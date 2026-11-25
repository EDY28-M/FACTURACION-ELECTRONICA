<?php

namespace Tests\Unit;

use App\CoreFacturalo\Helpers\Security\ColumnWhitelist;
use App\Http\Middleware\SanitizeFilterColumn;
use App\Http\Middleware\VerifyCsrfToken;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;

/**
 * Verifies defense-in-depth security controls across Nginx, PHP-FPM, CSRF,
 * and dynamic filter column sanitization.
 */
class SecurityHardeningTest extends TestCase
{
    private $previousContainer;
    private $previousFacadeApplication;
    private $app;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousContainer = Container::getInstance();
        $this->previousFacadeApplication = Facade::getFacadeApplication();

        $this->app = new Application(realpath(__DIR__.'/../..'));
        $this->app->instance(
            \Illuminate\Contracts\Routing\ResponseFactory::class,
            new \Illuminate\Routing\ResponseFactory(
                $this->createStub(\Illuminate\Contracts\View\Factory::class),
                $this->createStub(\Illuminate\Routing\Redirector::class)
            )
        );
        Container::setInstance($this->app);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->app);
    }

    protected function tearDown(): void
    {
        Container::setInstance($this->previousContainer);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previousFacadeApplication);
        parent::tearDown();
    }

    public function test_column_whitelist_accepts_valid_and_rejects_malicious_inputs(): void
    {
        $allowed = ['name', 'number', 'date_of_issue'];

        $this->assertSame('name', ColumnWhitelist::resolve('name', $allowed, 'date_of_issue'));
        $this->assertSame('number', ColumnWhitelist::resolve(' number ', $allowed, 'date_of_issue'));

        $badInputs = [
            null,
            '',
            'password',
            'api_token',
            'name->secret',
            '1=1 OR name',
            'name; DROP TABLE users--',
            'users.password',
            "name'",
            str_repeat('a', 65),
        ];

        foreach ($badInputs as $bad) {
            $this->assertSame('date_of_issue', ColumnWhitelist::resolve($bad, $allowed, 'date_of_issue'));
        }
    }

    public function test_sanitize_filter_column_middleware_blocks_sql_and_json_path_injections(): void
    {
        $middleware = new SanitizeFilterColumn();
        $okNext = fn () => new Response('ok', 200);

        // Valid identifiers and absent/empty column pass through
        foreach ([null, '', 'name', 'date_of_issue', 'customer', 'internal_id'] as $valid) {
            $req = Request::create('/sale-notes/records', 'GET', $valid === null ? [] : ['column' => $valid]);
            $res = $middleware->handle($req, $okNext);
            $this->assertSame(200, $res->getStatusCode());
        }

        // Malformed / injection attempts return HTTP 422 before hitting any controller
        $attacks = [
            'password->x',
            'id) OR 1=1--',
            'users.password',
            "name' OR '1'='1",
            'name;SELECT 1',
            '123numericstart',
            'col with space',
        ];

        foreach ($attacks as $attack) {
            $req = Request::create('/sale-notes/records', 'GET', ['column' => $attack, 'value' => 'test']);
            $res = $middleware->handle($req, $okNext);
            $this->assertSame(422, $res->getStatusCode(), "Expected 422 for column={$attack}");
            $data = $res->getData(true);
            $this->assertFalse($data['success']);
        }
    }

    public function test_nginx_and_php_production_hardening_directives_are_present(): void
    {
        $root = dirname(__DIR__, 2);
        $nginx = file_get_contents($root.'/docker/nginx/default.conf');
        $phpIni = file_get_contents($root.'/docker/php/php.ini');

        $this->assertStringContainsString('server_tokens off;', $nginx);
        $this->assertStringContainsString('limit_req_zone', $nginx);
        $this->assertStringContainsString('limit_conn_zone', $nginx);
        $this->assertStringContainsString('real_ip_header X-Forwarded-For;', $nginx);
        $this->assertStringContainsString('Strict-Transport-Security', $nginx);
        $this->assertStringContainsString('X-Frame-Options "SAMEORIGIN"', $nginx);
        $this->assertStringContainsString('X-Content-Type-Options "nosniff"', $nginx);
        $this->assertStringContainsString('Referrer-Policy', $nginx);
        $this->assertStringContainsString('Permissions-Policy', $nginx);
        $this->assertStringContainsString('fastcgi_hide_header X-Powered-By;', $nginx);
        $this->assertStringContainsString('deny all;', $nginx);

        $this->assertStringContainsString('expose_php = Off', $phpIni);
    }

    public function test_csrf_has_no_unprotected_route_exclusions(): void
    {
        $ref = new \ReflectionProperty(VerifyCsrfToken::class, 'except');
        $ref->setAccessible(true);
        $instance = $this->createStub(VerifyCsrfToken::class);
        $this->assertSame([], $ref->getValue($instance));
    }
}
