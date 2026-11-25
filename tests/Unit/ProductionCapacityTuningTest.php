<?php

namespace Tests\Unit;

use App\Http\Controllers\Tenant\PersonController;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class ProductionCapacityTuningTest extends TestCase
{
    private function projectPath(string $relative): string
    {
        return dirname(__DIR__, 2).'/'.$relative;
    }

    public function test_php_fpm_pool_config_supports_high_concurrency(): void
    {
        $poolPath = $this->projectPath('docker/php/zz-pool.conf');
        $this->assertFileExists($poolPath);

        $conf = file_get_contents($poolPath);
        $this->assertStringContainsString('pm = dynamic', $conf);
        $this->assertStringContainsString('pm.max_children = 50', $conf);
        $this->assertStringContainsString('pm.start_servers = 10', $conf);
        $this->assertStringContainsString('pm.max_requests = 500', $conf);

        $compose = file_get_contents($this->projectPath('docker-compose.yml'));
        $this->assertStringContainsString('./docker/php/zz-pool.conf:/usr/local/etc/php-fpm.d/zz-pool.conf:ro', $compose);
    }

    public function test_mariadb_config_enables_multi_tenant_buffer_and_table_caches(): void
    {
        $cnfPath = $this->projectPath('docker/mariadb/my.cnf');
        $this->assertFileExists($cnfPath);

        $cnf = file_get_contents($cnfPath);
        $this->assertStringContainsString('innodb_buffer_pool_size = 3G', $cnf);
        $this->assertStringContainsString('innodb_flush_log_at_trx_commit = 1', $cnf);
        $this->assertStringContainsString('max_connections = 300', $cnf);
        $this->assertStringContainsString('table_open_cache = 8000', $cnf);
        $this->assertStringContainsString('table_definition_cache = 4000', $cnf);
        $this->assertStringContainsString('skip-name-resolve', $cnf);

        $compose = file_get_contents($this->projectPath('docker-compose.yml'));
        $this->assertStringContainsString('./docker/mariadb/my.cnf:/etc/mysql/conf.d/99-performance.cnf:ro', $compose);
    }

    public function test_nginx_rate_limits_accommodate_shared_office_nat(): void
    {
        $nginx = file_get_contents($this->projectPath('docker/nginx/default.conf'));
        $this->assertStringContainsString('zone=app_limit:20m rate=60r/s', $nginx);
        $this->assertStringContainsString('limit_req zone=app_limit burst=120 nodelay', $nginx);
        $this->assertStringContainsString('limit_conn conn_limit 100', $nginx);
    }

    public function test_person_controller_builds_location_cascade_from_flat_collections(): void
    {
        $controller = new PersonController();
        $method = new ReflectionMethod(PersonController::class, 'buildLocationCascade');
        $method->setAccessible(true);

        $departments = collect([
            (object) ['id' => '15', 'description' => 'LIMA'],
            (object) ['id' => '04', 'description' => 'AREQUIPA'],
        ]);
        $provinces = collect([
            (object) ['id' => '1501', 'department_id' => '15', 'description' => 'LIMA'],
            (object) ['id' => '0401', 'department_id' => '04', 'description' => 'AREQUIPA'],
        ]);
        $districts = collect([
            (object) ['id' => '150101', 'province_id' => '1501', 'description' => 'LIMA'],
            (object) ['id' => '150122', 'province_id' => '1501', 'description' => 'MIRAFLORES'],
            (object) ['id' => '040101', 'province_id' => '0401', 'description' => 'AREQUIPA'],
        ]);

        $cascade = $method->invoke($controller, $departments, $provinces, $districts);

        $this->assertCount(2, $cascade);
        $this->assertSame('15', $cascade[0]['value']);
        $this->assertSame('LIMA', $cascade[0]['label']);
        $this->assertCount(1, $cascade[0]['children']);
        $this->assertSame('1501', $cascade[0]['children'][0]['value']);
        $this->assertCount(2, $cascade[0]['children'][0]['children']);
        $this->assertSame(['value' => '150122', 'label' => 'MIRAFLORES'], $cascade[0]['children'][0]['children'][1]);
        $this->assertSame('040101', $cascade[1]['children'][0]['children'][0]['value']);
    }

    public function test_controllers_strip_heavy_default_eager_loads(): void
    {
        $quotation = file_get_contents($this->projectPath('app/Http/Controllers/Tenant/QuotationController.php'));
        $this->assertStringContainsString("Quotation::without(['user', 'soap_type', 'currency_type', 'items', 'payments'])", $quotation);
        $this->assertStringContainsString('ColumnWhitelist::resolve(', $quotation);

        $establishment = file_get_contents($this->projectPath('app/Http/Controllers/Tenant/EstablishmentController.php'));
        $this->assertStringContainsString("Department::without(['provinces'])", $establishment);
        $this->assertStringContainsString("Province::without(['districts'])", $establishment);

        $person = file_get_contents($this->projectPath('app/Http/Controllers/Tenant/PersonController.php'));
        $this->assertStringContainsString("Department::without(['provinces'])", $person);
        $this->assertStringContainsString("Province::without(['districts'])", $person);

        $document = file_get_contents($this->projectPath('app/Http/Controllers/Tenant/DocumentController.php'));
        $this->assertStringContainsString("User::without(['establishment'])", $document);
        $this->assertStringContainsString("Item::without(['item_type', 'unit_type', 'tags'])", $document);
        $this->assertStringContainsString("with(['brand', 'category', 'warehouses.warehouse', 'lots_group'])", $document);
    }
}
