<?php

namespace Tests\Unit;

use App\Exceptions\Handler;
use App\Http\Kernel;
use App\Http\Middleware\LockedTenant;
use App\Models\Tenant\User as TenantUser;
use Hyn\Tenancy\Contracts\CurrentHostname;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\AuthManager;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Application;
use Illuminate\Hashing\HashManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Routing\Redirector;
use Illuminate\Routing\ResponseFactory;
use Illuminate\Routing\Router;
use Illuminate\Routing\UrlGenerator;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Engines\EngineResolver;
use Illuminate\View\Factory as ViewFactory;
use Illuminate\View\FileViewFinder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Pins the authentication boundary of the tenant API (routes/api.php).
 *
 * The real route file is loaded under a fake tenant hostname and every action is
 * replaced by an inert recorder, so the real middleware pipeline runs but no
 * controller, database table of the application or outbound HTTP is touched.
 */
class ApiRouteAuthTest extends TestCase
{
    private const TOKEN = 'fixture-tenant-token';
    private const HOST = 'tenant.test';

    private $previousContainer;
    private $previousFacadeApplication;
    private $previousResolver;
    private $previousDispatcher;
    private $previousRequestMacros;
    private $app;
    private $router;
    private $auth;
    private $tenant;
    private $actionCalls = 0;
    private $routes = [];
    private $originalActionNames = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousContainer = Container::getInstance();
        $this->previousFacadeApplication = Facade::getFacadeApplication();
        $this->previousResolver = Model::getConnectionResolver();
        $this->previousDispatcher = Model::getEventDispatcher();
        $this->previousRequestMacros = (new \ReflectionProperty(Request::class, 'macros'))->getValue();
        $this->actionCalls = 0;

        $this->app = new Application(dirname(__DIR__, 2));
        $this->app->instance('env', 'local');
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->app);
        $this->app->instance('config', new Repository([
            'auth' => require dirname(__DIR__, 2) . '/config/auth.php',
            'hashing' => ['driver' => 'bcrypt', 'bcrypt' => ['rounds' => 4]],
        ]));
        $this->app->instance('events', new Dispatcher($this->app));
        Model::setEventDispatcher($this->app['events']);
        $this->app->instance('hash', new HashManager($this->app));
        $this->app->instance(RateLimiter::class, new RateLimiter(new CacheRepository(new ArrayStore())));
        $this->auth = new AuthManager($this->app);
        $this->app->instance('auth', $this->auth);
        $this->app->instance(\Illuminate\Contracts\Auth\Factory::class, $this->auth);
        $this->app->instance('request', Request::create('https://' . self::HOST . '/api/login'));
        $this->router = new Router($this->app['events'], $this->app);
        $this->app->instance('router', $this->router);
        $this->app->instance(Router::class, $this->router);
        new Kernel($this->app, $this->router);
        // Authenticate::redirectTo() resolves route('login') eagerly, even for JSON requests.
        $this->router->get('/login', fn () => new JsonResponse('login'))->name('login');
        $this->app->instance('url', new UrlGenerator($this->router->getRoutes(), $this->app['request']));
        // Needed only so the application's exception handler can render the JSON 401.
        $view = new ViewFactory(new EngineResolver(), new FileViewFinder(new Filesystem(), []), $this->app['events']);
        $this->app->instance(\Illuminate\Contracts\Routing\ResponseFactory::class, new ResponseFactory($view, new Redirector($this->app['url'])));
        // routes/api.php only registers the tenant block when a hostname is resolved.
        $this->app->singleton(CurrentHostname::class, fn () => (object) ['fqdn' => self::HOST]);
        if (!class_exists('Route')) { class_alias(Route::class, 'Route'); }
        Route::prefix('api')->middleware('api')->namespace('App\\Http\\Controllers')->group(dirname(__DIR__, 2) . '/routes/api.php');
        $this->router->getRoutes()->refreshNameLookups();

        foreach ($this->router->getRoutes() as $route) {
            foreach ($route->methods() as $method) {
                if ($method === 'HEAD') { continue; }
                $this->routes[$method . ' ' . $route->uri()] = $route;
            }
            $this->originalActionNames[$route->uri()] = $route->getActionName();
            $action = $route->getAction();
            unset($action['controller']);
            $action['uses'] = function () { $this->actionCalls++; return new JsonResponse(['success' => true, 'inert' => true]); };
            $route->setAction($action);
        }

        $this->tenant = new SQLiteConnection(new \PDO('sqlite::memory:'), '', '', ['name' => 'tenant']);
        $this->tenant->statement('create table users (id integer primary key autoincrement, name text, email text, password text, api_token text, type text, remember_token text, created_at text, updated_at text)');
        $this->tenant->statement('create table configurations (id integer primary key, locked_tenant integer)');
        $this->tenant->statement('insert into configurations values (1, 0)');
        // Tenant plan tables read by the plan.module middleware (App\Support\Access\TenantPlan).
        $this->tenant->statement('create table modules (id integer primary key, value text)');
        $this->tenant->statement("insert into modules values (1, 'documents'), (2, 'hotels')");
        $this->tenant->statement('create table module_levels (id integer primary key, value text, module_id integer)');
        $this->tenant->statement('create table module_user (user_id integer, module_id integer)');
        $this->tenant->statement('create table module_level_user (user_id integer, module_level_id integer)');
        $resolver = new ConnectionResolver(['tenant' => $this->tenant]);
        Model::setConnectionResolver($resolver);
        $this->app->instance('db', $resolver);
        \App\Support\Access\TenantPlan::flush();
    }

    protected function tearDown(): void
    {
        \App\Support\Access\TenantPlan::flush();
        if ($this->previousResolver) { Model::setConnectionResolver($this->previousResolver); }
        else { Model::unsetConnectionResolver(); }
        if ($this->previousDispatcher) { Model::setEventDispatcher($this->previousDispatcher); }
        else { Model::unsetEventDispatcher(); }
        (new \ReflectionProperty(Request::class, 'macros'))->setValue(null, $this->previousRequestMacros);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previousFacadeApplication);
        Container::setInstance($this->previousContainer);
        parent::tearDown();
    }

    private function user(string $type = 'admin'): TenantUser
    {
        $user = new TenantUser();
        $user->forceFill([
            'name' => 'Fixture', 'email' => 'fixture@example.test', 'password' => bcrypt('fixture-password'),
            'type' => $type, 'api_token' => self::TOKEN,
        ])->save();
        return $user;
    }

    private function dispatch(string $method, string $path, ?string $bearer = null, array $data = []): \Symfony\Component\HttpFoundation\Response
    {
        $request = Request::create('https://' . self::HOST . $path, $method, $data);
        $request->headers->set('Accept', 'application/json');
        if ($bearer !== null) { $request->headers->set('Authorization', 'Bearer ' . $bearer); }
        $this->auth->shouldUse('api');
        $this->auth->forgetGuards();
        $request->setUserResolver(fn ($guard = null) => $this->auth->guard($guard)->user());
        $this->app->instance('request', $request);
        return $this->router->dispatch($request);
    }

    private function middlewareOf(string $key): array
    {
        $this->assertArrayHasKey($key, $this->routes, "Route {$key} is not registered");
        return $this->router->gatherRouteMiddleware($this->routes[$key]);
    }

    private function assertDenied(callable $operation, string $exceptionClass): \Throwable
    {
        $caught = null;
        try { $operation(); } catch (\Throwable $exception) { $caught = $exception; }
        $this->assertInstanceOf($exceptionClass, $caught, $caught ? get_class($caught) . ': ' . $caught->getMessage() : 'Expected request denial');
        $this->assertSame(0, $this->actionCalls, 'The action must not run when the request is denied');
        return $caught;
    }

    public static function protectedRoutes(): array
    {
        return [
            'documents/status (previously public)' => ['POST', 'api/documents/status'],
            'sendserver (previously public)' => ['GET', 'api/sendserver/{document_id}/{query?}'],
            'documents/search/customers' => ['GET', 'api/documents/search/customers'],
            'configurations/generateDispatch' => ['POST', 'api/configurations/generateDispatch'],
            'documents' => ['POST', 'api/documents'],
            'documents_server' => ['POST', 'api/documents_server'],
        ];
    }

    /** @dataProvider protectedRoutes */
    public function test_route_carries_api_token_and_tenant_lock_middleware(string $method, string $uri): void
    {
        $stack = $this->middlewareOf($method . ' ' . $uri);
        $this->assertContains('App\\Http\\Middleware\\Authenticate:api', $stack);
        $this->assertContains(LockedTenant::class, $stack);
    }

    public function test_moved_routes_keep_their_uri_method_and_controller_action(): void
    {
        $this->assertSame('App\\Http\\Controllers\\Tenant\\Api\\ServiceController@documentStatus', $this->originalActionNames['api/documents/status']);
        $this->assertSame('App\\Http\\Controllers\\Tenant\\DocumentController@sendServer', $this->originalActionNames['api/sendserver/{document_id}/{query?}']);
        $this->assertSame(['POST'], $this->routes['POST api/documents/status']->methods());
        $this->assertSame(['GET', 'HEAD'], $this->routes['GET api/sendserver/{document_id}/{query?}']->methods());
    }

    public function test_only_login_remains_without_api_auth(): void
    {
        $public = [];
        foreach ($this->routes as $key => $route) {
            if (strpos($route->uri(), 'api/') !== 0) { continue; }
            if (!in_array('App\\Http\\Middleware\\Authenticate:api', $this->router->gatherRouteMiddleware($route), true)) {
                $public[] = $key;
            }
        }
        sort($public);
        // The dead validate_cpe / consult_status routes (controller methods never existed) were
        // removed; any new public API route must be a conscious decision.
        $this->assertSame(['POST api/login'], $public);
    }

    public function test_unauthenticated_requests_are_rejected_before_the_action_runs(): void
    {
        $this->user();
        $cases = [
            ['POST', '/api/documents/status', ['external_id' => 'x']],
            ['POST', '/api/documents/status', ['serie_number' => 'F001-1']],
            ['GET', '/api/sendserver/1', []],
            ['GET', '/api/sendserver/999999/true', []],
            ['GET', '/api/documents/search/customers', []],
            ['POST', '/api/configurations/generateDispatch', []],
            ['POST', '/api/documents', []],
        ];
        foreach ($cases as [$method, $path, $data]) {
            $exception = $this->assertDenied(fn () => $this->dispatch($method, $path, null, $data), AuthenticationException::class);
            $this->assertSame(['api'], $exception->guards());
            // The application's own handler turns it into a JSON 401.
            $response = (new Handler($this->app))->render($this->app['request'], $exception);
            $this->assertSame(401, $response->getStatusCode(), "{$method} {$path}");
        }
    }

    public function test_wrong_token_is_rejected_before_the_action_runs(): void
    {
        $this->user();
        $this->assertDenied(fn () => $this->dispatch('POST', '/api/documents/status', 'not-the-token', ['external_id' => 'x']), AuthenticationException::class);
        $this->assertDenied(fn () => $this->dispatch('GET', '/api/sendserver/1', 'not-the-token'), AuthenticationException::class);
    }

    public function test_valid_token_reaches_the_inert_action(): void
    {
        $this->user();
        $status = $this->dispatch('POST', '/api/documents/status', self::TOKEN, ['external_id' => 'x']);
        $this->assertSame(200, $status->getStatusCode());
        $send = $this->dispatch('GET', '/api/sendserver/1', self::TOKEN);
        $this->assertSame(200, $send->getStatusCode());
        $this->assertSame(2, $this->actionCalls);
    }

    public function test_module_outside_the_company_plan_is_rejected_with_403(): void
    {
        $user = $this->user();
        // The owner's grants define the plan: Hotels only, no Ventas/POS.
        $this->tenant->statement('insert into module_user values (' . (int) $user->id . ', 2)');
        $response = $this->dispatch('POST', '/api/documents/status', self::TOKEN, ['external_id' => 'x']);
        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(0, $this->actionCalls);
    }

    public function test_storefront_client_token_is_still_rejected_on_the_moved_routes(): void
    {
        $this->user('client');
        $this->assertDenied(fn () => $this->dispatch('POST', '/api/documents/status', self::TOKEN, ['external_id' => 'x']), HttpException::class);
        $this->assertDenied(fn () => $this->dispatch('GET', '/api/sendserver/1', self::TOKEN), HttpException::class);
    }

    public function test_locked_tenant_blocks_a_valid_token_on_the_moved_routes(): void
    {
        $this->user();
        $this->tenant->statement('update configurations set locked_tenant = 1');
        $exception = $this->assertDenied(fn () => $this->dispatch('POST', '/api/documents/status', self::TOKEN, ['external_id' => 'x']), HttpException::class);
        $this->assertSame(403, $exception->getStatusCode());
    }

    public function test_login_stays_public(): void
    {
        $response = $this->dispatch('POST', '/api/login', null, ['email' => 'a@b.test', 'password' => 'x']);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(1, $this->actionCalls);
    }
}
