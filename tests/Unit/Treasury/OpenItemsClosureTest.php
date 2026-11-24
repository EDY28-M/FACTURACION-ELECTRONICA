<?php

namespace Tests\Unit\Treasury;

use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Http\JsonResponse;
use Modules\Treasury\Http\Controllers\ReportController;
use Modules\Treasury\Providers\TreasuryServiceProvider;
use Modules\Treasury\Services\BancarizationChecker;
use Modules\Treasury\Services\CashBookReport;
use Modules\Treasury\Services\DetractionService;
use Modules\Treasury\Services\InstrumentService;
use Modules\Treasury\Services\PleCashBookExporter;
use Modules\Treasury\Services\RetentionPerceptionService;
use Modules\Treasury\Support\BusinessDayCalculator;
use Modules\Treasury\Support\HolidayProvider;
use Modules\Treasury\Support\InstrumentDeadlines;
use Modules\Treasury\Support\TenantCacheKey;
use PHPUnit\Framework\TestCase;

/**
 * Review closure of the open items: per-resolution holiday services, per-tenant cache keys, ZIP failure handling
 * and PLE netting.
 */
class OpenItemsClosureTest extends TestCase
{
    use Phase4Harness;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootHarness();
    }

    protected function tearDown(): void
    {
        $this->tearDownHarness();
        parent::tearDown();
    }

    public function test_holiday_services_are_bound_per_resolution_not_as_singletons(): void
    {
        $fake = new class {
            public $singletons = [];
            public $binds = [];

            public function singleton($abstract, $concrete = null)
            {
                $this->singletons[] = $abstract;
            }

            public function bind($abstract, $concrete = null)
            {
                $this->binds[] = $abstract;
            }

            public function register($provider)
            {
            }
        };

        (new TreasuryServiceProvider($fake))->register();

        // They cache the holiday list for their whole lifetime: a stale list must never outlive the request.
        foreach ([HolidayProvider::class, BusinessDayCalculator::class, InstrumentDeadlines::class, InstrumentService::class, DetractionService::class] as $class) {
            $this->assertContains($class, $fake->binds, $class . ' must be bound per resolution');
            $this->assertNotContains($class, $fake->singletons, $class . ' must not be a singleton');
        }
    }

    public function test_the_business_day_calculator_sees_holidays_added_after_a_new_resolution(): void
    {
        $this->db->table('treasury_holidays')->where('date', '2031-05-01')->delete();
        $first = new BusinessDayCalculator(new HolidayProvider());
        $this->assertTrue($first->isBusinessDay('2031-05-01'));

        $this->db->table('treasury_holidays')->insert([
            'date' => '2031-05-01', 'name' => 'Dia del Trabajo', 'scope' => 'national', 'is_business_day_override' => 0,
        ]);

        // The cached instance keeps its answer (proof that sharing it would be stale) ...
        $this->assertTrue($first->isBusinessDay('2031-05-01'));
        // ... a fresh resolution sees the change.
        $this->assertFalse((new BusinessDayCalculator(new HolidayProvider()))->isBusinessDay('2031-05-01'));
    }

    public function test_schema_probes_are_cached_per_tenant_database(): void
    {
        $this->assertSame(':memory:|', TenantCacheKey::prefix());

        foreach ([
            $this->makeDetractionService(),
            $this->makeRetentionService(),
            $this->makeReceivablePayableService(),
        ] as $service) {
            $probe = new \ReflectionMethod($service, 'hasTable');
            $probe->setAccessible(true);
            $cache = new \ReflectionProperty($service, 'schemaCache');
            $cache->setAccessible(true);

            $this->assertTrue($probe->invoke($service, 'documents'));
            $this->db->setDatabaseName('tenant_two');
            // Another tenant never reads the first tenant's answer: it is probed (and cached) on its own key.
            $this->assertTrue($probe->invoke($service, 'documents'));
            $keys = array_keys($cache->getValue($service));
            $this->db->setDatabaseName(':memory:');

            $this->assertNotEmpty(array_filter($keys, function ($key) {
                return strpos($key, ':memory:|') === 0;
            }), get_class($service));
            $this->assertNotEmpty(array_filter($keys, function ($key) {
                return strpos($key, 'tenant_two|') === 0;
            }), get_class($service));
        }
    }

    public function test_a_failed_zip_returns_500_and_removes_the_temporary_file(): void
    {
        $this->app->instance(ResponseFactory::class, new class {
            public function json($data = [], $status = 200, array $headers = [], $options = 0): JsonResponse
            {
                return new JsonResponse($data, $status, $headers, $options);
            }
        });

        $path = tempnam(sys_get_temp_dir(), 'ple');
        $this->assertFileExists($path);

        $controller = (new \ReflectionClass(ReportController::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod($controller, 'zipFailure');
        $method->setAccessible(true);

        $response = $method->invoke($controller, $path);
        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame('No se pudo crear el archivo ZIP.', $response->getData(true)['message']);
        $this->assertFileDoesNotExist($path);

        // No temp file at all (tempnam failed): still a clean 500.
        $this->assertSame(500, $method->invoke($controller, null)->getStatusCode());
    }

    public function test_ple_rows_whose_debe_and_haber_cancel_each_other_are_dropped(): void
    {
        $exporter = new PleCashBookExporter(new CashBookReport($this->makeBalanceService()));
        $row = function (float $debit, float $credit) {
            return [
                'source_type' => null, 'source_id' => 1, 'date' => '2026-11-10', 'description' => 'Operacion',
                'debit' => $debit, 'credit' => $credit, 'counterparty' => 'Cliente', 'transaction_number' => '1',
                'counterparty_doc_type' => null, 'counterparty_doc_number' => null, 'sunat_payment_code' => '003',
            ];
        };
        $entry = function (array $r) {
            return ['row' => $r, 'entity' => '02', 'account' => '191001'];
        };

        $lines = $exporter->buildFormat12Lines([
            $entry($row(100.00, 100.00)),   // nets to zero: dropped
            $entry($row(0.00, 0.00)),       // empty: dropped
            $entry($row(100.00, 40.00)),    // nets to 60.00 debe: kept
        ], '20261100');

        $this->assertCount(1, $lines);
        $this->assertStringContainsString('60.00', $lines[0]);
    }
}
