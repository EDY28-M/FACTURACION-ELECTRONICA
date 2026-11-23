<?php

namespace Tests\Unit\Treasury;

use App\Support\Access\AccessMap;
use App\Support\Access\TenantPlan;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use Modules\Treasury\Services\PaymentEvidenceService;
use PHPUnit\Framework\TestCase;

/**
 * Verifies Phase 1 + Phase 2 access control requirements for the Treasury module:
 * - Every route declared in modules/Treasury/Routes/web.php is wrapped in auth/redirect.module/locked.tenant
 *   and has an AJAX-enforced rule in config/permissions.php
 * - Core `treasury/*` routes require strictly the `treasury` module
 * - Shared `treasury/payment-evidences/*` routes allow payment-owning modules at the middleware layer
 *   while enforcing per-alias module restrictions in PaymentEvidenceService::REQUIRED_MODULES_BY_ALIAS
 * - Tenants whose plan omits `treasury` cannot grant `treasury` to any user (including the owner)
 */
class TreasuryAccessTest extends TestCase
{
    private function map(): AccessMap
    {
        return new AccessMap(require __DIR__ . '/../../../config/permissions.php');
    }

    /**
     * Parses every Route::get/post/put/patch/delete declaration in modules/Treasury/Routes/web.php.
     *
     * @return array<int, array{0: string, 1: string}>
     */
    private function declaredTreasuryRoutes(): array
    {
        $source = file_get_contents(__DIR__ . '/../../../modules/Treasury/Routes/web.php');
        $this->assertNotFalse($source);
        $this->assertStringContainsString("['auth', 'redirect.module', 'locked.tenant']", $source);
        $this->assertStringContainsString("Route::prefix('treasury')", $source);

        preg_match_all("/Route::(get|post|put|patch|delete)\(\s*'([^']*)'/", $source, $matches, PREG_SET_ORDER);
        $this->assertNotEmpty($matches, 'Expected at least one route declared in modules/Treasury/Routes/web.php');

        $routes = [];
        foreach ($matches as $match) {
            $method = strtoupper($match[1]);
            $subpath = trim($match[2], '/');
            $sampleSubpath = preg_replace('/\{[^}]+\}/', '1', $subpath);
            $fullPath = $sampleSubpath === '' ? 'treasury' : ('treasury/' . $sampleSubpath);
            $routes[] = [$fullPath, $method];
        }

        return $routes;
    }

    private function isPaymentEvidenceRoute(string $path): bool
    {
        return strpos($path, 'treasury/payment-evidences') === 0;
    }

    public function test_every_declared_treasury_route_has_a_permission_rule_with_ajax_enforcement(): void
    {
        $map = $this->map();
        $routes = $this->declaredTreasuryRoutes();

        foreach ($routes as [$path, $method]) {
            $rule = $map->resolve($path, $method);
            $this->assertNotNull($rule, "Missing permission rule for {$method} {$path}");
            $this->assertTrue($rule['ajax'], "AJAX must be enforced for {$method} {$path}");

            if ($this->isPaymentEvidenceRoute($path)) {
                $this->assertContains('treasury', $rule['modules'], "Evidence route must include treasury: {$method} {$path}");
                $this->assertContains('documents', $rule['modules'], "Evidence route must include documents: {$method} {$path}");
                $this->assertContains('purchases', $rule['modules'], "Evidence route must include purchases: {$method} {$path}");
            } else {
                $this->assertSame('treasury', $rule['module'], "Wrong module for {$method} {$path}");
            }
        }
    }

    public function test_user_without_treasury_module_is_denied_on_every_core_route_and_unrelated_users_denied_on_evidence(): void
    {
        $map = $this->map();
        $routes = $this->declaredTreasuryRoutes();

        // Even a user with finance and configuration modules cannot access core treasury routes when disabled.
        $otherModules = ['dashboard', 'finance', 'configuration'];
        $nonPaymentModules = ['dashboard', 'configuration', 'items'];

        foreach ($routes as [$path, $method]) {
            if ($this->isPaymentEvidenceRoute($path)) {
                foreach ([false, true] as $isAjax) {
                    $decision = $map->decide($path, false, $nonPaymentModules, [], $isAjax, $method);
                    $this->assertFalse($decision['allowed'], "Expected denial for {$method} {$path} without payment modules");
                    $this->assertSame('module', $decision['reason']);
                }
                continue;
            }

            foreach ([false, true] as $isAjax) {
                $decision = $map->decide($path, false, $otherModules, [], $isAjax, $method);
                $this->assertFalse($decision['allowed'], "Expected denial for {$method} {$path} (ajax=" . ($isAjax ? '1' : '0') . ')');
                $this->assertSame('module', $decision['reason'], "Expected module denial for {$method} {$path}");
            }
        }
    }

    public function test_payment_evidence_per_alias_module_map_covers_every_supported_payable_type(): void
    {
        $expected = array_keys(PaymentEvidenceService::PAYABLE_TYPES);
        $actual = array_keys(PaymentEvidenceService::REQUIRED_MODULES_BY_ALIAS);
        sort($expected);
        sort($actual);

        $this->assertSame($expected, $actual);
        // treasury_movement evidence remains strictly restricted to the treasury module
        $this->assertSame(['treasury'], PaymentEvidenceService::REQUIRED_MODULES_BY_ALIAS['treasury_movement']);
    }

    public function test_user_with_treasury_module_is_allowed_and_menu_entry_exists(): void
    {
        $config = require __DIR__ . '/../../../config/permissions.php';
        $map = new AccessMap($config);

        $this->assertContains('treasury', $config['menu']);
        $this->assertSame('treasury', $map->firstAllowedPath(['treasury'], []));

        foreach ($this->declaredTreasuryRoutes() as [$path, $method]) {
            $decision = $map->decide($path, false, ['treasury'], [], true, $method);
            $this->assertTrue($decision['allowed'], "Expected access for {$method} {$path}");
        }
    }

    public function test_treasury_module_can_reuse_existing_payment_endpoints_while_unrelated_modules_remain_denied(): void
    {
        $map = $this->map();
        $paymentPaths = [
            'document_payments/tables',
            'document_payments/records/1',
            'sale_note_payments/tables',
            'purchase-payments/tables',
            'expense-payments/tables',
        ];

        foreach ($paymentPaths as $path) {
            $rule = $map->resolve($path, 'GET');
            $this->assertNotNull($rule, "Missing rule for {$path}");
            $this->assertTrue($rule['ajax'], "AJAX must be enforced for {$path}");
            $this->assertContains('treasury', $rule['modules'], "Treasury must be allowed on {$path}");

            $this->assertTrue($map->decide($path, false, ['treasury'], [], true, 'GET')['allowed'], "Treasury user denied on {$path}");
            $this->assertFalse($map->decide($path, false, ['inventory'], [], true, 'GET')['allowed'], "Unrelated module allowed on {$path}");
        }

        // Treasury-only users MUST be denied on admin/export document_payments sub-routes
        foreach (['document_payments/initialize_balance', 'document_payments/report/2026-01-01/2026-01-31'] as $restricted) {
            $rule = $map->resolve($restricted, 'GET');
            $this->assertNotNull($rule, "Missing rule for {$restricted}");
            $this->assertTrue($rule['ajax']);
            $this->assertFalse($map->decide($restricted, false, ['treasury'], [], true, 'GET')['allowed'], "Treasury must be denied on {$restricted}");
            $this->assertTrue($map->decide($restricted, false, ['documents'], [], true, 'GET')['allowed'], "Documents must remain allowed on {$restricted}");
            $this->assertTrue($map->decide($restricted, false, ['pos'], [], true, 'GET')['allowed'], "POS must remain allowed on {$restricted}");
        }

        // Verify Phase 3 receivables-payables routes are strictly gated under treasury
        foreach ([
            ['treasury/receivables-payables', 'GET'],
            ['treasury/receivables-payables/tables', 'GET'],
            ['treasury/receivables-payables/records', 'GET'],
            ['treasury/receivables-payables/purchase-fees/1', 'POST'],
            ['treasury/receivables-payables/purchase-fees/1', 'DELETE'],
        ] as [$path, $method]) {
            $rule = $map->resolve($path, $method);
            $this->assertNotNull($rule, "Missing rule for {$method} {$path}");
            $this->assertSame('treasury', $rule['module']);
            $this->assertTrue($rule['ajax']);
            $this->assertFalse($map->decide($path, false, ['documents', 'purchases', 'finance'], [], true, $method)['allowed']);
            $this->assertTrue($map->decide($path, false, ['treasury'], [], true, $method)['allowed']);
        }
    }

    public function test_tenant_plan_omitting_treasury_blocks_treasury_even_for_owner(): void
    {
        $prevContainer = Container::getInstance();
        $prevFacade = Facade::getFacadeApplication();
        $prevResolver = Model::getConnectionResolver();
        $prevDispatcher = Model::getEventDispatcher();

        $app = new Container();
        Container::setInstance($app);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);
        $app->instance('config', new Repository());

        $manager = new Manager($app);
        $manager->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''], 'tenant');
        $database = $manager->getDatabaseManager();
        $database->setDefaultConnection('tenant');
        $app->instance('db', $database);
        Model::setConnectionResolver($database);
        Model::setEventDispatcher(new Dispatcher($app));
        TenantPlan::flush();

        try {
            $db = $database->connection('tenant');
            $db->statement('create table users (id integer primary key, type text)');
            $db->statement('create table modules (id integer primary key, value text, description text)');
            $db->statement('create table module_levels (id integer primary key, value text, module_id integer)');
            $db->statement('create table module_user (module_id integer, user_id integer)');
            $db->statement('create table module_level_user (module_level_id integer, user_id integer)');

            $db->table('users')->insert(['id' => 1, 'type' => 'admin']);
            $db->table('modules')->insert([
                ['id' => 5, 'value' => 'configuration', 'description' => 'Configuración'],
                ['id' => 12, 'value' => 'finance', 'description' => 'Finanzas'],
                ['id' => 19, 'value' => 'treasury', 'description' => 'Tesorería'],
            ]);
            // Tenant plan grants configuration + finance, NOT treasury.
            $db->table('module_user')->insert([
                ['module_id' => 5, 'user_id' => 1],
                ['module_id' => 12, 'user_id' => 1],
            ]);

            $plan = TenantPlan::current();
            $this->assertNotContains('treasury', $plan->moduleValues());

            $map = $this->map();
            foreach (['treasury', 'treasury/movements', 'treasury/transfers'] as $path) {
                $decision = $map->decide($path, false, $plan->moduleValues(), $plan->levelValues(), false, 'GET');
                $this->assertFalse($decision['allowed']);
                $this->assertSame('module', $decision['reason']);
            }

            // Enabling treasury on the tenant plan unlocks access immediately after flush.
            $db->table('module_user')->insert(['module_id' => 19, 'user_id' => 1]);
            TenantPlan::flush();
            $enabledPlan = TenantPlan::current();
            $this->assertContains('treasury', $enabledPlan->moduleValues());
            foreach (['treasury', 'treasury/movements', 'treasury/transfers'] as $path) {
                $this->assertTrue($map->decide($path, false, $enabledPlan->moduleValues(), $enabledPlan->levelValues(), true, 'GET')['allowed']);
            }
        } finally {
            TenantPlan::flush();
            if ($prevResolver) {
                Model::setConnectionResolver($prevResolver);
            } else {
                Model::unsetConnectionResolver();
            }
            if ($prevDispatcher) {
                Model::setEventDispatcher($prevDispatcher);
            } else {
                Model::unsetEventDispatcher();
            }
            Facade::clearResolvedInstances();
            Facade::setFacadeApplication($prevFacade);
            Container::setInstance($prevContainer);
        }
    }
}
