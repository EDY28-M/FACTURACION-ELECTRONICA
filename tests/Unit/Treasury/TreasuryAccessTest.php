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
use PHPUnit\Framework\TestCase;

/**
 * Verifies Phase 1 access control requirements for the Treasury module:
 * - Every route declared in modules/Treasury/Routes/web.php is wrapped in auth/redirect.module/locked.tenant
 *   and resolves to the `treasury` module with AJAX enforcement in config/permissions.php
 * - Users without the treasury module are denied on every route (page and AJAX)
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

    public function test_every_declared_treasury_route_has_a_permission_rule_with_ajax_enforcement(): void
    {
        $map = $this->map();
        $routes = $this->declaredTreasuryRoutes();

        foreach ($routes as [$path, $method]) {
            $rule = $map->resolve($path, $method);
            $this->assertNotNull($rule, "Missing permission rule for {$method} {$path}");
            $this->assertSame('treasury', $rule['module'], "Wrong module for {$method} {$path}");
            $this->assertTrue($rule['ajax'], "AJAX must be enforced for {$method} {$path}");
        }
    }

    public function test_user_without_treasury_module_is_denied_on_every_declared_route(): void
    {
        $map = $this->map();
        $routes = $this->declaredTreasuryRoutes();

        // Even a user with finance and configuration modules cannot access treasury when disabled.
        $otherModules = ['dashboard', 'finance', 'configuration'];

        foreach ($routes as [$path, $method]) {
            foreach ([false, true] as $isAjax) {
                $decision = $map->decide($path, false, $otherModules, [], $isAjax, $method);
                $this->assertFalse($decision['allowed'], "Expected denial for {$method} {$path} (ajax=" . ($isAjax ? '1' : '0') . ')');
                $this->assertSame('module', $decision['reason'], "Expected module denial for {$method} {$path}");
            }
        }
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
            $decision = $map->decide('treasury', false, $plan->moduleValues(), $plan->levelValues(), false, 'GET');
            $this->assertFalse($decision['allowed']);
            $this->assertSame('module', $decision['reason']);

            // Enabling treasury on the tenant plan unlocks access immediately after flush.
            $db->table('module_user')->insert(['module_id' => 19, 'user_id' => 1]);
            TenantPlan::flush();
            $enabledPlan = TenantPlan::current();
            $this->assertContains('treasury', $enabledPlan->moduleValues());
            $this->assertTrue($map->decide('treasury', false, $enabledPlan->moduleValues(), $enabledPlan->levelValues(), true, 'GET')['allowed']);
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
