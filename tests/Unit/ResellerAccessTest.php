<?php

namespace Tests\Unit;

use App\Http\Controllers\System\Api\ResellerController;
use App\Http\Kernel;
use App\Http\Middleware\Authenticate;
use App\Http\Middleware\LockedAdmin;
use App\Http\Middleware\RedirectIfAuthenticated;
use App\Http\Middleware\VerifyCsrfToken;
use App\Models\System\User as SystemUser;
use App\Models\Tenant\User as TenantUser;
use Hyn\Tenancy\Contracts\CurrentHostname;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\AuthManager;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Cookie\CookieJar;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Encryption\Encrypter;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Application;
use Illuminate\Hashing\HashManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Router;
use Illuminate\Routing\UrlGenerator;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Session\SessionManager;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Route;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use Illuminate\Validation\ValidationException;
use Illuminate\View\Engines\EngineResolver;
use Illuminate\View\Factory as ViewFactory;
use Illuminate\View\FileViewFinder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ResellerAccessTest extends TestCase
{
    private $previousContainer;
    private $previousFacadeApplication;
    private $previousResolver;
    private $previousDispatcher;
    private $previousRequestMacros;
    private $app;
    private $auth;
    private $session;
    private $router;
    private $encrypter;
    private $system;
    private $privilegedCalls = 0;
    private $resellerRoutes = [];
    private $originalActions = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousContainer = Container::getInstance();
        $this->previousFacadeApplication = Facade::getFacadeApplication();
        $this->previousResolver = Model::getConnectionResolver();
        $this->previousDispatcher = Model::getEventDispatcher();
        $this->previousRequestMacros = (new \ReflectionProperty(Request::class, 'macros'))->getValue();
        $this->app = new Application(dirname(__DIR__, 2));
        // Exercise real CSRF enforcement, not Laravel's automatic testing bypass.
        $this->app->instance('env', 'local');
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->app);
        $this->app->instance('config', new Repository([
            'auth' => require dirname(__DIR__, 2) . '/config/auth.php',
            'hashing' => ['driver' => 'bcrypt', 'bcrypt' => ['rounds' => 4]],
            'session' => ['driver' => 'array', 'lifetime' => 120, 'expire_on_close' => false, 'encrypt' => false, 'cookie' => 'reseller_test_session', 'path' => '/', 'domain' => null, 'secure' => false, 'http_only' => true, 'same_site' => 'lax', 'lottery' => [0, 100]],
        ]));
        $this->app->instance('events', new Dispatcher($this->app));
        Model::setEventDispatcher($this->app['events']);
        $this->app->instance('cookie', new CookieJar());
        $this->app->instance(\Illuminate\Contracts\Cookie\QueueingFactory::class, $this->app['cookie']);
        $this->app->instance('hash', new HashManager($this->app));
        $this->encrypter = new Encrypter(str_repeat('x', 32), 'AES-256-CBC');
        $this->app->instance(\Illuminate\Contracts\Encryption\Encrypter::class, $this->encrypter);
        $manager = new SessionManager($this->app);
        $this->app->instance('session', $manager);
        $this->app->instance(SessionManager::class, $manager);
        $this->session = $manager->driver();
        $this->session->start();
        $this->app->instance('session.store', $this->session);
        $view = new ViewFactory(new EngineResolver(), new FileViewFinder(new \Illuminate\Filesystem\Filesystem(), []), $this->app['events']);
        $this->app->instance(\Illuminate\Contracts\View\Factory::class, $view);
        $this->app->instance(RateLimiter::class, new RateLimiter(new CacheRepository(new ArrayStore())));
        $translator = new Translator(new ArrayLoader(), 'en');
        $this->app->instance('translator', $translator);
        $validator = new Factory($translator, $this->app);
        $this->app->instance('validator', $validator);
        $this->app->instance(\Illuminate\Contracts\Validation\Factory::class, $validator);
        $this->auth = new AuthManager($this->app);
        $this->app->instance('auth', $this->auth);
        $this->app->instance(\Illuminate\Contracts\Auth\Factory::class, $this->auth);
        $request = Request::create('https://platform.test/login');
        $request->setLaravelSession($this->session);
        $this->app->instance('request', $request);
        $this->router = new Router($this->app['events'], $this->app);
        $this->app->instance('router', $this->router);
        $this->app->instance(Router::class, $this->router);
        new Kernel($this->app, $this->router);
        $this->router->get('/login', fn () => new Response('login'))->name('login');
        $this->router->get('/ordinary-locked-web', fn () => new Response('ordinary'))->middleware('web');
        $this->app->instance('url', new UrlGenerator($this->router->getRoutes(), $request));
        $this->app->singleton(CurrentHostname::class, fn () => null);
        if (!class_exists('Route')) { class_alias(Route::class, 'Route'); }
        Route::prefix('api')->middleware('api')->namespace('App\\Http\\Controllers')->group(dirname(__DIR__, 2) . '/routes/api.php');
        $this->router->getRoutes()->refreshNameLookups();
        foreach ($this->router->getRoutes() as $route) {
            if (strpos($route->uri(), 'api/reseller/') === 0) {
                $this->resellerRoutes[$route->uri()] = $route;
                $this->originalActions[$route->uri()] = $route->getActionName();
                $action = $route->getAction();
                unset($action['controller']);
                $action['uses'] = function () { $this->privilegedCalls++; return new JsonResponse(['success' => true, 'message' => 'Inert privileged action']); };
                $route->setAction($action);
            }
        }
        $tenant = new SQLiteConnection(new \PDO('sqlite::memory:'), '', '', ['name' => 'tenant']);
        $tenant->statement('create table users (id integer primary key autoincrement, name text, email text, password text, api_token text, type text, remember_token text, created_at text, updated_at text)');
        $this->system = new SQLiteConnection(new \PDO('sqlite::memory:'), '', '', ['name' => 'system']);
        $this->system->statement('create table users (id integer primary key autoincrement, name text, email text, password text, remember_token text, created_at text, updated_at text)');
        $this->system->statement('create table configurations (id integer primary key, locked_admin integer)');
        $this->system->statement('insert into configurations values (1, 1)');
        Model::setConnectionResolver(new ConnectionResolver(['tenant' => $tenant, 'system' => $this->system]));
    }

    protected function tearDown(): void
    {
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

    private function login(string $guard, ?string $type = null): void
    {
        $user = $guard === 'admin' ? new SystemUser() : new TenantUser();
        $attributes = ['name' => 'Fixture', 'email' => 'fixture@example.test', 'password' => bcrypt('fixture-password')];
        if ($guard !== 'admin') { $attributes += ['type' => $type ?? 'admin', 'api_token' => 'fixture-tenant-token']; }
        $user->forceFill($attributes)->save();
        $this->auth->guard($guard)->login($user);
    }

    private function dispatch(string $path, ?string $csrf = 'valid', ?string $bearer = null, array $data = [], ?string $method = null): \Symfony\Component\HttpFoundation\Response
    {
        $this->session->save();
        $name = $this->session->getName();
        $cookie = $this->encrypter->encrypt(CookieValuePrefix::create($name, $this->encrypter->getKey()) . $this->session->getId(), false);
        $domain = isset($this->resellerRoutes[ltrim($path, '/')]) ? $this->resellerRoutes[ltrim($path, '/')]->getDomain() : null;
        $request = Request::create('https://' . ($domain ?: 'platform.test') . $path, $method ?? ($path === '/ordinary-locked-web' ? 'GET' : 'POST'), $data + ['locked_admin' => false], [$name => $cookie]);
        $request->headers->set('Accept', 'application/json');
        if ($csrf !== null) { $request->headers->set('X-CSRF-TOKEN', $csrf === 'valid' ? $this->session->token() : $csrf); }
        if ($bearer !== null) { $request->headers->set('Authorization', 'Bearer ' . $bearer); }
        $this->auth->shouldUse('web');
        $this->auth->forgetGuards();
        $request->setUserResolver(fn ($guard = null) => $this->auth->guard($guard)->user());
        $this->app->instance('request', $request);
        return $this->router->dispatch($request);
    }

    private function assertDenied(callable $operation, string $exceptionClass): void
    {
        $caught = null;
        try { $operation(); } catch (\Throwable $exception) { $caught = $exception; }
        $this->assertInstanceOf($exceptionClass, $caught, $caught ? get_class($caught) . ': ' . $caught->getMessage() : 'Expected request denial');
        $this->assertSame(0, $this->privilegedCalls);
    }

    public function test_actual_routes_keep_paths_and_actions_with_session_csrf_and_selected_admin_guard(): void
    {
        $this->assertSame([
            'api/reseller/detail' => 'App\\Http\\Controllers\\System\\Api\\ResellerController@resellerDetail',
            'api/reseller/lockedAdmin' => 'App\\Http\\Controllers\\System\\Api\\ResellerController@lockedAdmin',
        ], $this->originalActions);
        foreach ($this->resellerRoutes as $route) {
            $stack = $this->router->gatherRouteMiddleware($route);
            $this->assertContains(StartSession::class, $stack);
            $this->assertContains(VerifyCsrfToken::class, $stack);
            $this->assertContains(Authenticate::class . ':admin', $stack);
            $this->assertNotContains(LockedAdmin::class, $stack);
            $this->assertLessThan(array_search(Authenticate::class . ':admin', $stack), array_search(StartSession::class, $stack));
        }
    }

    public function test_guest_with_valid_csrf_cannot_reach_either_privileged_action(): void
    {
        foreach (array_keys($this->resellerRoutes) as $path) {
            $this->assertDenied(fn () => $this->dispatch('/' . $path), AuthenticationException::class);
        }
    }

    public function test_tenant_api_token_cannot_substitute_for_system_admin_session(): void
    {
        $user = new TenantUser();
        $user->forceFill(['name' => 'Integrator', 'email' => 'integrator@example.test', 'password' => bcrypt('fixture-password'), 'type' => 'integrator', 'api_token' => 'fixture-tenant-token'])->save();
        $tokenRequest = Request::create('https://platform.test/api/reseller/detail');
        $tokenRequest->headers->set('Authorization', 'Bearer fixture-tenant-token');
        $this->app->instance('request', $tokenRequest);
        $this->assertTrue($this->auth->guard('api')->check());
        $this->assertDenied(fn () => $this->dispatch('/api/reseller/detail', 'valid', 'fixture-tenant-token'), AuthenticationException::class);
    }

    public function test_tenant_staff_or_customer_web_session_cannot_substitute_for_system_admin_session(): void
    {
        foreach (['admin', 'seller', 'integrator', 'client'] as $type) {
            $this->login('web', $type);
            $this->assertDenied(fn () => $this->dispatch('/api/reseller/lockedAdmin'), AuthenticationException::class);
        }
    }

    public function test_system_admin_with_valid_csrf_can_reach_both_actions_even_while_platform_is_locked(): void
    {
        $this->login('admin');
        $this->assertDenied(fn () => $this->dispatch('/ordinary-locked-web'), HttpException::class);
        foreach (array_keys($this->resellerRoutes) as $path) {
            $response = $this->dispatch('/' . $path);
            $this->assertSame(200, $response->getStatusCode());
            $this->assertSame(['success' => true, 'message' => 'Inert privileged action'], $response->getData(true));
        }
        $this->assertSame(2, $this->privilegedCalls);
    }

    public function test_missing_or_incorrect_csrf_denies_even_an_existing_system_admin(): void
    {
        $this->login('admin');
        foreach ([null, 'incorrect'] as $csrf) {
            $this->assertDenied(fn () => $this->dispatch('/api/reseller/lockedAdmin', $csrf), TokenMismatchException::class);
        }
    }

    public function test_locked_admin_requires_boolean_before_any_system_query_or_write(): void
    {
        $this->system->beforeExecuting(function () { throw new \LogicException('Validation must precede system queries'); });
        foreach ([[], ['locked_admin' => 'yes'], ['locked_admin' => []], ['locked_admin' => 2]] as $data) {
            $caught = null;
            try { (new ResellerController())->lockedAdmin(Request::create('/api/reseller/lockedAdmin', 'POST', $data)); }
            catch (\Throwable $exception) { $caught = $exception; }
            $this->assertInstanceOf(ValidationException::class, $caught);
            $this->assertSame(422, $caught->status);
            $this->assertArrayHasKey('locked_admin', $caught->errors());
        }
    }

    public function test_fresh_system_admin_can_login_while_locked_and_then_reach_protected_unlock(): void
    {
        Route::middleware('web')->namespace('App\\Http\\Controllers')->group(dirname(__DIR__, 2) . '/routes/web.php');
        $this->router->getRoutes()->refreshNameLookups();
        (new \Illuminate\Foundation\Providers\FoundationServiceProvider($this->app))->registerRequestValidation();
        $this->app->instance(\Illuminate\Contracts\View\Factory::class, new class(new EngineResolver(), new FileViewFinder(new \Illuminate\Filesystem\Filesystem(), []), $this->app['events']) extends ViewFactory {
            public function make($view, $data = [], $mergeData = [])
            {
                return $view === 'system.auth.login' ? new Response('Fixture system login form') : parent::make($view, $data, $mergeData);
            }
        });
        $user = new SystemUser();
        $user->forceFill(['name' => 'Fresh administrator', 'email' => 'fresh@example.test', 'password' => bcrypt('fixture-password')])->save();
        $this->assertFalse($this->auth->guard('admin')->check());
        $this->assertSame(1, (int) $this->system->table('configurations')->value('locked_admin'));

        foreach ($this->router->getRoutes() as $route) {
            if ($route->uri() === 'login') {
                $stack = $this->router->gatherRouteMiddleware($route);
                $this->assertContains(VerifyCsrfToken::class, $stack);
                $this->assertContains(RedirectIfAuthenticated::class . ':admin', $stack);
                $this->assertNotContains(LockedAdmin::class, $stack);
            }
        }
        $form = $this->dispatch('/login', 'valid', null, [], 'GET');
        $this->assertSame(200, $form->getStatusCode());
        $this->assertSame('Fixture system login form', $form->getContent());
        $credentials = ['email' => $user->email, 'password' => 'fixture-password'];
        $this->assertDenied(fn () => $this->dispatch('/login', null, null, $credentials, 'POST'), TokenMismatchException::class);
        $this->assertDenied(fn () => $this->dispatch('/login', 'valid', null, ['email' => $user->email, 'password' => 'wrong-password'], 'POST'), ValidationException::class);
        $this->assertFalse($this->auth->guard('admin')->check());
        $oldToken = $this->session->token();
        $login = $this->dispatch('/login', 'valid', null, $credentials, 'POST');
        $this->assertSame(204, $login->getStatusCode());
        $this->assertSame($user->id, $this->auth->guard('admin')->id());
        $this->assertNotSame($oldToken, $this->session->token());
        $unlock = $this->dispatch('/api/reseller/lockedAdmin');
        $this->assertSame(200, $unlock->getStatusCode());
        $this->assertSame(1, $this->privilegedCalls);
        $this->assertSame(1, (int) $this->system->table('configurations')->value('locked_admin'));

        $dashboard = $this->router->getRoutes()->getByName('system.dashboard');
        $action = $dashboard->getAction();
        unset($action['controller']);
        $action['uses'] = fn () => new Response('Must remain locked');
        $dashboard->setAction($action);
        $this->assertContains(LockedAdmin::class, $this->router->gatherRouteMiddleware($dashboard));
        $caught = null;
        try { $this->dispatch('/dashboard', 'valid', null, [], 'GET'); } catch (HttpException $exception) { $caught = $exception; }
        $this->assertInstanceOf(HttpException::class, $caught);
        $this->assertSame(403, $caught->getStatusCode());
    }
}
