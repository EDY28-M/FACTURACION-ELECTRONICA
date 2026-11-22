<?php

namespace Tests\Unit;

use App\Http\Controllers\Tenant\Api\MobileController;
use App\Http\Middleware\Authenticate;
use App\Models\System\User as SystemUser;
use App\Models\Tenant\User;
use Illuminate\Auth\AuthManager;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Cookie\CookieJar;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Application;
use Illuminate\Hashing\HashManager;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Router;
use Illuminate\Routing\UrlGenerator;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Route;
use Modules\Ecommerce\Http\Controllers\EcommerceController;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\HttpException;

class CustomerBoundaryPassThrough
{
    public function handle($request, $next, ...$parameters) { return $next($request); }
}

class CustomerAccessBoundaryTest extends TestCase
{
    private $previousContainer;
    private $previousFacadeApplication;
    private $previousResolver;
    private $app;
    private $auth;
    private $session;
    private $router;
    private $connection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousContainer = Container::getInstance();
        $this->previousFacadeApplication = Facade::getFacadeApplication();
        $this->previousResolver = Model::getConnectionResolver();
        $this->app = new Application(dirname(__DIR__, 2));
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->app);
        $this->app->instance('config', new Repository([
            'auth' => require dirname(__DIR__, 2) . '/config/auth.php',
            'hashing' => ['driver' => 'bcrypt', 'bcrypt' => ['rounds' => 4]],
        ]));
        $this->app->instance('events', new Dispatcher($this->app));
        $this->app->instance('cookie', new CookieJar());
        $this->app->instance('hash', new HashManager($this->app));
        $this->session = new Store('customer-boundary', new ArraySessionHandler(120));
        $this->session->start();
        $this->session->put('cart', [['item_id' => 123, 'quantity' => 2]]);
        $this->app->instance('session.store', $this->session);
        $this->auth = new AuthManager($this->app);
        $this->app->instance('auth', $this->auth);
        $this->app->instance(\Illuminate\Contracts\Auth\Factory::class, $this->auth);
        $request = $this->request('/login');
        $this->router = new Router($this->app['events'], $this->app);
        $this->router->aliasMiddleware('auth', Authenticate::class);
        foreach (['locked.tenant', 'redirect.module', 'throttle'] as $middleware) {
            $this->router->aliasMiddleware($middleware, CustomerBoundaryPassThrough::class);
        }
        $this->app->instance('router', $this->router);
        $this->router->get('/login', fn () => new Response('login'))->name('login');
        $this->app->instance('url', new UrlGenerator($this->router->getRoutes(), $request));
        if (!class_exists('Route')) { class_alias(Route::class, 'Route'); }
        $this->connection = new SQLiteConnection(new \PDO('sqlite::memory:'), '', '', ['name' => 'tenant']);
        $this->connection->statement('create table users (id integer primary key autoincrement, name text, email text unique, password text, type text, establishment_id integer, api_token text unique null, remember_token text null, created_at text, updated_at text)');
        $this->connection->statement('create table establishments (id integer primary key, description text)');
        $this->connection->statement("insert into establishments values (1, 'Fixture establishment')");
        $this->connection->statement('create table modules (id integer primary key)');
        $this->connection->statement('insert into modules values (10)');
        $this->connection->statement('create table module_user (module_id integer, user_id integer)');
        $this->connection->statement('create table companies (id integer primary key, number text, logo text, identity_document_type_id text null)');
        $this->connection->statement("insert into companies values (1, '20123456789', 'fixture.png', null)");
        Model::setConnectionResolver(new ConnectionResolver(['tenant' => $this->connection]));
    }

    protected function tearDown(): void
    {
        if ($this->previousResolver) { Model::setConnectionResolver($this->previousResolver); }
        else { Model::unsetConnectionResolver(); }
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previousFacadeApplication);
        Container::setInstance($this->previousContainer);
        parent::tearDown();
    }

    private function request(string $path, array $data = [], ?string $token = null): Request
    {
        $request = Request::create('https://tenant.test' . $path, 'POST', $data);
        $request->setLaravelSession($this->session);
        $request->setUserResolver(fn ($guard = null) => $this->auth->guard($guard)->user());
        if ($token !== null) { $request->headers->set('Authorization', 'Bearer ' . $token); }
        $this->app->instance('request', $request);
        return $request;
    }

    private function user(string $type): User
    {
        $user = new User();
        $user->forceFill([
            'name' => ucfirst($type), 'email' => $type . '@example.test',
            'password' => bcrypt('fixture-password'), 'type' => $type,
            'api_token' => 'fixture-' . $type . '-legacy-token', 'establishment_id' => 1,
        ])->save();
        return $user;
    }

    private function forbidden(callable $operation): void
    {
        $caught = null;
        try { $operation(); } catch (HttpException $exception) { $caught = $exception; }
        $this->assertInstanceOf(HttpException::class, $caught);
        $this->assertSame(403, $caught->getStatusCode());
    }

    private function ecommerce(): EcommerceController
    {
        // Auth actions have no constructor state; omit unrelated catalog/view queries.
        return (new \ReflectionClass(EcommerceController::class))->newInstanceWithoutConstructor();
    }

    public function test_existing_customer_token_is_authenticated_but_forbidden_from_backoffice_api(): void
    {
        $user = $this->user('client');
        $request = $this->request('/api/documents', [], $user->api_token);
        $this->assertTrue($this->auth->guard('api')->check());
        $this->forbidden(fn () => (new Authenticate($this->auth))->handle($request, fn () => 'should not reach', 'api'));
        $this->assertSame($user->api_token, User::findOrFail($user->id)->api_token);
    }

    public function test_existing_customer_web_session_is_forbidden_without_destroying_storefront_login_or_cart(): void
    {
        $user = $this->user('client');
        $request = $this->request('/items');
        $this->auth->guard('web')->login($user);
        $this->forbidden(fn () => (new Authenticate($this->auth))->handle($request, fn () => 'should not reach'));
        $this->assertSame($user->id, $this->auth->guard('web')->id());
        $this->assertSame([['item_id' => 123, 'quantity' => 2]], $this->session->get('cart'));
    }

    public function test_staff_and_integrator_guards_retain_backoffice_access(): void
    {
        foreach (['admin', 'seller', 'integrator'] as $type) {
            $user = $this->user($type);
            foreach (['web', 'api'] as $guard) {
                $this->auth->shouldUse('web');
                $this->auth->forgetGuards();
                $request = $this->request('/protected', [], $guard === 'api' ? $user->api_token : null);
                if ($guard === 'web') { $this->auth->guard('web')->login($user); }
                $this->assertSame('allowed', (new Authenticate($this->auth))->handle($request, fn () => 'allowed', $guard));
                $this->assertSame($user->id, $request->user()->id);
            }
        }
    }

    public function test_system_admin_guard_is_not_treated_as_a_tenant_customer(): void
    {
        $systemUser = new SystemUser();
        $systemUser->forceFill(['id' => 99, 'name' => 'System administrator', 'type' => 'client']);
        $this->auth->guard('admin')->setUser($systemUser);
        $request = $this->request('/system/settings');
        $this->assertSame('allowed', (new Authenticate($this->auth))->handle($request, fn () => 'allowed', 'admin'));
        $this->assertSame($systemUser, $request->user());
    }

    public function test_mobile_login_denies_storefront_credentials_without_revealing_a_token(): void
    {
        $user = $this->user('client');
        $request = $this->request('/api/login', ['email' => $user->email, 'password' => 'fixture-password']);
        $this->assertSame(['success' => false, 'message' => 'No Autorizado'], (new MobileController())->login($request));
        $this->assertSame($user->api_token, User::findOrFail($user->id)->api_token);
    }

    public function test_mobile_staff_login_payload_and_tokens_are_unchanged(): void
    {
        foreach (['admin', 'seller', 'integrator'] as $type) {
            $user = $this->user($type);
            $request = $this->request('/api/login', ['email' => $user->email, 'password' => 'fixture-password']);
            $this->assertSame([
                'success' => true, 'name' => $user->name, 'email' => $user->email,
                'token' => $user->api_token, 'ruc' => '20123456789', 'logo' => 'fixture.png',
            ], (new MobileController())->login($request));
        }
    }

    public function test_storefront_login_preserves_payload_and_cart_while_regenerating_session_token(): void
    {
        $user = $this->user('client');
        $request = $this->request('/ecommerce/login', ['email' => $user->email, 'password' => 'fixture-password']);
        $oldId = $this->session->getId();
        $oldToken = $this->session->token();
        $this->assertSame(['success' => true, 'message' => 'Login Success'], $this->ecommerce()->login($request));
        $this->assertNotSame($oldId, $this->session->getId());
        $this->assertNotSame($oldToken, $this->session->token());
        $this->assertSame($user->id, $this->auth->guard('web')->id());
        $this->assertSame([['item_id' => 123, 'quantity' => 2]], $this->session->get('cart'));
    }

    public function test_new_customer_registration_keeps_payload_session_and_modules_without_api_credentials(): void
    {
        foreach (['first', 'second'] as $name) {
            $request = $this->request('/ecommerce/storeUser', ['name' => $name, 'email' => $name . '@example.test', 'pswd' => 'fixture-password']);
            $oldToken = $this->session->token();
            $this->assertSame(['success' => true, 'message' => 'Usuario registrado'], $this->ecommerce()->storeUser($request));
            $user = User::where('email', $name . '@example.test')->firstOrFail();
            $this->assertSame('client', $user->type);
            $this->assertNull($user->api_token);
            $this->assertSame([10], $user->modules()->pluck('modules.id')->all());
            $this->assertSame($user->id, $this->auth->guard('web')->id());
            $this->assertNotSame($oldToken, $this->session->token());
            $this->assertSame([['item_id' => 123, 'quantity' => 2]], $this->session->get('cart'));
        }
    }

    public function test_real_storefront_routes_remain_public_to_customer_sessions_but_configuration_requires_boundary(): void
    {
        Route::namespace('Modules\\Ecommerce\\Http\\Controllers')->group(dirname(__DIR__, 2) . '/modules/Ecommerce/Routes/web.php');
        $routes = $this->router->getRoutes();
        $routes->refreshNameLookups();
        $this->assertNotContains('auth', $routes->getByName('tenant_ecommerce_login_post')->middleware());
        $this->assertNotContains('auth', $routes->getByName('tenant_ecommerce_store_user')->middleware());
        foreach (['tenant_detail_cart', 'tenant_ecommerce_cart_quote', 'tenant_ecommerce_payment_cash', 'tenant_ecommerce_culqui', 'tenant_ecommerce_transaction_finally'] as $name) {
            $this->assertNotContains('auth', $routes->getByName($name)->middleware());
        }
        $this->assertContains('auth', $routes->getByName('tenant_ecommerce_configuration')->middleware());
        foreach ($routes as $route) {
            $action = $route->getAction();
            unset($action['controller']);
            $action['uses'] = fn () => new Response('route reached');
            $route->setAction($action);
        }
        $user = $this->user('client');
        $this->auth->guard('web')->login($user);
        $cartRequest = $this->request('/ecommerce/detail_cart');
        $cartRequest->setMethod('GET');
        $this->assertSame('route reached', $this->router->dispatch($cartRequest)->getContent());
        $configRequest = $this->request('/ecommerce/configuration');
        $configRequest->setMethod('GET');
        $this->forbidden(fn () => $this->router->dispatch($configRequest));
        $this->assertSame($user->id, $this->auth->guard('web')->id());
    }
}
