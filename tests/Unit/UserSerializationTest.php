<?php

namespace Tests\Unit;

use App\Http\Controllers\Tenant\UserController;
use App\Http\Middleware\Authenticate;
use App\Http\Middleware\EnforceModuleAccess;
use App\Models\Tenant\User;
use App\Support\Access\AccessMap;
use Illuminate\Auth\AuthManager;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Cookie\CookieJar;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Facade;
use Illuminate\View\Compilers\BladeCompiler;
use Modules\Finance\Http\Controllers\ToPayController;
use Modules\Finance\Http\Controllers\UnpaidController;
use Modules\Sale\Http\Controllers\ContractController;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\HttpException;

class UserSerializationTest extends TestCase
{
    private $previousContainer;
    private $previousFacade;
    private $previousResolver;
    private $previousDispatcher;
    private $app;
    private $db;
    private $auth;

    protected function setUp(): void
    {
        parent::setUp();
        \App\Support\Access\TenantPlan::flush();
        $this->previousContainer = Container::getInstance();
        $this->previousFacade = Facade::getFacadeApplication();
        $this->previousResolver = Model::getConnectionResolver();
        $this->previousDispatcher = Model::getEventDispatcher();
        $this->app = new Application(dirname(__DIR__, 2));
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->app);
        $this->app->instance('config', new Repository([
            'auth' => require dirname(__DIR__, 2) . '/config/auth.php',
            'hashing' => ['driver' => 'bcrypt', 'bcrypt' => ['rounds' => 4]],
            'permissions' => require dirname(__DIR__, 2) . '/config/permissions.php',
        ]));
        $this->app->instance('events', new Dispatcher($this->app));
        $this->app->instance('cookie', new CookieJar());
        $this->app->instance('hash', new \Illuminate\Hashing\HashManager($this->app));
        $session = new Store('user-serialization', new ArraySessionHandler(120));
        $session->start();
        $this->app->instance('session.store', $session);
        $capsule = new Manager($this->app);
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''], 'tenant');
        $manager = $capsule->getDatabaseManager();
        $manager->setDefaultConnection('tenant');
        $this->app->instance('db', $manager);
        Model::setConnectionResolver($manager);
        Model::setEventDispatcher(new Dispatcher($this->app));
        $this->db = $manager->connection('tenant');
        $this->auth = new AuthManager($this->app);
        $this->app->instance('auth', $this->auth);
        $this->app->instance(\Illuminate\Contracts\Auth\Factory::class, $this->auth);
        $this->app->instance(\Illuminate\Contracts\Routing\ResponseFactory::class, new class {
            public function json($data, $status = 200) { return new JsonResponse($data, $status); }
        });
        foreach ([
            'users (id integer primary key, name text, email text, password text, api_token text, remember_token text, type text, establishment_id integer, locked integer)',
            'establishments (id integer primary key, description text)',
            'persons (id integer primary key, type text, enabled integer, name text)',
            'payment_method_types (id text primary key)',
            'cat_currency_types (id text primary key, active integer)',
            'cat_charge_discount_types (id text primary key, type text, level text)',
            'companies (id integer primary key)',
            'configurations (id integer primary key, destination_sale integer)',
            'series (id integer primary key, establishment_id integer, document_type_id text, number text)',
            'bank_accounts (id integer primary key)',
            'cash (id integer primary key, user_id integer, state integer)',
            'modules (id integer primary key, value text, description text, order_menu integer)',
            'module_levels (id integer primary key, module_id integer, value text, description text)',
            'module_user (module_id integer, user_id integer)',
            'module_level_user (module_level_id integer, user_id integer)',
        ] as $table) { $this->db->statement('create table ' . $table); }
        $this->db->table('establishments')->insert(['id' => 1, 'description' => 'Fixture']);
        foreach ([1 => 'admin', 2 => 'seller', 3 => 'admin', 4 => 'integrator', 5 => 'client'] as $id => $type) {
            $this->db->table('users')->insert(['id' => $id, 'name' => 'Fixture ' . $id, 'email' => 'fixture' . $id . '@example.test', 'password' => 'fixture-hash-' . $id, 'api_token' => $type === 'client' ? null : 'fixture-token-' . $id, 'remember_token' => 'fixture-remember-' . $id, 'type' => $type, 'establishment_id' => 1, 'locked' => 0]);
        }
        $this->requestAs(1);
    }

    protected function tearDown(): void
    {
        if ($this->previousResolver) { Model::setConnectionResolver($this->previousResolver); }
        else { Model::unsetConnectionResolver(); }
        if ($this->previousDispatcher) { Model::setEventDispatcher($this->previousDispatcher); }
        else { Model::unsetEventDispatcher(); }
        \App\Support\Access\TenantPlan::flush();
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previousFacade);
        Container::setInstance($this->previousContainer);
        parent::tearDown();
    }

    private function requestAs(int $id, string $path = '/users/records'): Request
    {
        $request = Request::create($path, 'GET');
        $this->app->instance('request', $request);
        $this->auth->guard('web')->setUser(User::findOrFail($id));
        $request->headers->set('Accept', 'application/json');
        $request->setUserResolver(fn ($guard = null) => $this->auth->guard($guard)->user());
        $this->app->instance('request', $request);
        return $request;
    }

    private function assertNoCredentials(array $user): void
    {
        foreach (['password', 'remember_token', 'api_token'] as $key) { $this->assertArrayNotHasKey($key, $user); }
    }

    public function test_default_and_nested_user_serialization_hide_credentials_without_mutating_model(): void
    {
        $user = User::findOrFail(2);
        $this->assertNoCredentials($user->toArray());
        $this->assertNoCredentials(json_decode($user->toJson(), true));
        $parent = new \App\Models\Tenant\ModelTenant();
        $parent->setRelation('user', $user);
        $this->assertNoCredentials($parent->toArray()['user']);
        $this->assertNoCredentials(json_decode(collect([$parent])->toJson(), true)[0]['user']);
        $this->assertSame('fixture-hash-2', $user->password);
        $this->assertSame('fixture-token-2', $user->api_token);
        $this->assertSame('seller', $user->toArray()['type']);
        $this->assertSame('Fixture', $user->toArray()['establishment']['description']);
    }

    private function selectorRows($rows): array
    {
        return json_decode(json_encode($rows), true);
    }

    public function test_financial_and_contract_selectors_return_only_id_and_name_with_existing_membership(): void
    {
        $toPay = (new ToPayController())->filter();
        $unpaid = (new UnpaidController())->filter();
        $contract = (new ContractController())->tables();
        foreach ([$toPay['users'], $unpaid['users'], $contract['sellers']] as $rows) {
            foreach ($this->selectorRows($rows) as $row) {
                $this->assertSame(['id', 'name'], array_keys($row));
                $this->assertNoCredentials($row);
            }
        }
        $this->assertSame([1, 2, 3, 4, 5], array_column($this->selectorRows($toPay['users']), 'id'));
        $this->assertSame([2, 3], array_column($this->selectorRows($unpaid['users']), 'id'));
        $this->assertSame([1, 2], array_column($this->selectorRows($contract['sellers']), 'id'));
        $this->requestAs(2);
        $this->assertSame([], (new UnpaidController())->filter()['users']);
    }

    public function test_explicit_management_dtos_keep_tokens_but_never_passwords(): void
    {
        $controller = new UserController();
        $records = $controller->records()->resolve($this->app['request']);
        // The list never carries the full credential, only a masked hint.
        $this->assertArrayNotHasKey('api_token', $records[1]);
        $this->assertSame('••••••••en-2', $records[1]['api_token_hint']);
        $this->assertArrayNotHasKey('password', $records[1]);
        $this->assertArrayNotHasKey('remember_token', $records[1]);
        $record = $controller->record(2)->resolve($this->app['request']);
        $this->assertSame('fixture-token-2', $record['api_token']);
        $this->assertSame(2, $record['id']);
        $this->assertArrayNotHasKey('password', $record);
        $this->assertArrayNotHasKey('remember_token', $record);
        $this->assertNull($controller->record(5)->resolve($this->app['request'])['api_token']);

        // A non-owner administrator cannot read the owner's token.
        $this->requestAs(3);
        $this->assertNull($controller->record(1)->resolve($this->app['request'])['api_token']);
        $this->assertSame('fixture-token-2', $controller->record(2)->resolve($this->app['request'])['api_token']);

        // A seller can only open its own account.
        $this->requestAs(2);
        $this->assertSame('fixture-token-2', $controller->record(2)->resolve($this->app['request'])['api_token']);
        try {
            $controller->record(1);
            $this->fail('A seller must not open other users.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    public function test_user_management_permissions_are_enforced_for_json_and_client_boundary_still_denies(): void
    {
        $this->db->table('modules')->insert(['id' => 1, 'value' => 'establishments', 'description' => 'Fixture', 'order_menu' => 1]);
        $this->db->table('module_user')->insert([['module_id' => 1, 'user_id' => 2], ['module_id' => 1, 'user_id' => 5]]);
        \App\Support\Access\TenantPlan::flush();
        $map = new AccessMap(require dirname(__DIR__, 2) . '/config/permissions.php');
        foreach (['users/records', 'users/record/2'] as $path) {
            $this->assertSame('establishments', $map->resolve($path)['module']);
            $this->assertTrue($map->resolve($path)['ajax']);
            foreach ([1 => true, 2 => true, 3 => false] as $id => $allowed) {
                $request = $this->requestAs($id, '/' . $path);
                $result = (new Authenticate($this->auth))->handle($request, fn ($request) => (new EnforceModuleAccess())->handle($request, fn () => 'authorized'));
                $this->assertSame($allowed ? 'authorized' : 403, $allowed ? $result : $result->getStatusCode());
            }
            $request = $this->requestAs(5, '/' . $path);
            try {
                (new Authenticate($this->auth))->handle($request, fn () => 'unexpected');
                $this->fail('Storefront clients must not reach credential DTOs.');
            } catch (HttpException $exception) { $this->assertSame(403, $exception->getStatusCode()); }
        }
        $this->assertNull(User::findOrFail(5)->api_token);
        $routes = file_get_contents(dirname(__DIR__, 2) . '/routes/web.php');
        $this->assertStringContainsString("Route::middleware(['auth', 'redirect.module', 'locked.tenant'])->group", $routes);
        $this->assertStringContainsString("Route::get('users/record/{user}', 'Tenant\\UserController@record')", $routes);
        $this->assertStringContainsString("Route::get('users/records', 'Tenant\\UserController@records')", $routes);
    }

    public function test_orders_blade_keeps_only_current_users_explicit_token_without_exposing_password(): void
    {
        $this->requestAs(2, '/orders');
        $user = $this->auth->user();
        $source = file_get_contents(dirname(__DIR__, 2) . '/resources/views/tenant/orders/index.blade.php');
        // Compile the actual content section without booting the unrelated parent layout.
        $source = preg_replace('/@extends\([^\n]+\)|@section\([^\n]+\)|@endsection/', '', $source);
        $compiled = (new BladeCompiler(new Filesystem(), sys_get_temp_dir()))->compileString($source);
        ob_start();
        try { eval('?>' . $compiled); $html = ob_get_contents(); }
        finally { ob_end_clean(); }
        preg_match('/:user="([^"]*)"/', $html, $match);
        $payload = json_decode(html_entity_decode($match[1], ENT_QUOTES, 'UTF-8'), true);
        $this->assertSame('fixture-token-2', $payload['api_token']);
        $this->assertSame(2, $payload['id']);
        $this->assertSame($user->name, $payload['name']);
        $this->assertSame($user->email, $payload['email']);
        $this->assertArrayNotHasKey('password', $payload);
        $this->assertArrayNotHasKey('remember_token', $payload);
        $this->assertStringNotContainsString('fixture-token-1', $html);
        $this->assertStringNotContainsString('fixture-hash-', $html);
        $this->assertNoCredentials($user->toArray());
    }
}
