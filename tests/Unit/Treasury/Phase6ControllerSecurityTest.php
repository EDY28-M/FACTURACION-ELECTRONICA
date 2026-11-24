<?php

namespace Tests\Unit\Treasury;

use App\Support\Access\AccessMap;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Treasury\Exports\CashBookFormat11Export;
use Modules\Treasury\Exports\CashBookFormat12Export;
use Modules\Treasury\Exports\CashFlowExport;
use Modules\Treasury\Http\Controllers\CashCountController;
use Modules\Treasury\Http\Controllers\ReportController;
use Modules\Treasury\Http\Requests\CashBookReportRequest;
use Modules\Treasury\Http\Requests\CashFlowReportRequest;
use Modules\Treasury\Http\Requests\StoreCashCountRequest;
use Modules\Treasury\Services\CashBookReport;
use Modules\Treasury\Services\CashCountService;
use Modules\Treasury\Services\CashFlowService;
use Modules\Treasury\Services\PleCashBookExporter;
use Modules\Treasury\Http\Controllers\HolidayController;
use Modules\Treasury\Http\Requests\PleExportRequest;
use Modules\Treasury\Support\TreasuryExcelValueBinder;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PHPUnit\Framework\TestCase;

/**
 * Phase 6 security & export verification:
 * - Every Phase 6 route has an explicit AJAX-enforced `treasury` rule in config/permissions.php.
 * - ReportController and CashCountController strictly scope by user/establishment and fail closed when unassigned.
 * - Maatwebsite Excel Export classes declare explicit typed properties (PHP 8.3 clean) and Blade templates escape HTML.
 */
class Phase6ControllerSecurityTest extends TestCase
{
    use Phase4Harness;

    private $auth;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootHarness();

        $this->db->statement('create table cash_transactions (id integer primary key autoincrement, cash_id integer, payment_method_type_id text null, date text, description text null, payment numeric)');
        require_once __DIR__ . '/../../../database/migrations/tenant/2026_11_23_000012_tenant_create_treasury_cash_counts_table.php';
        (new \TenantCreateTreasuryCashCountsTable())->up();

        $this->app->instance(ResponseFactory::class, new class {
            public function json($data = [], $status = 200, array $headers = [], $options = 0): JsonResponse
            {
                return new JsonResponse($data, $status, $headers, $options);
            }
        });
        $this->auth = new class {
            public $currentUser;

            public function user()
            {
                return $this->currentUser;
            }

            public function id()
            {
                return $this->currentUser ? $this->currentUser->id : null;
            }
        };
        $this->app->instance(AuthFactory::class, $this->auth);
    }

    protected function tearDown(): void
    {
        $this->tearDownHarness();
        parent::tearDown();
    }

    private function reportController(): ReportController
    {
        $balance = $this->makeBalanceService();

        return new ReportController(
            new CashFlowService($balance, $this->makeReceivablePayableService()),
            new CashBookReport($balance),
            new PleCashBookExporter(new CashBookReport($balance))
        );
    }

    private function cashCountController(): CashCountController
    {
        return new CashCountController(new CashCountService($this->makeBalanceService()));
    }

    private function user(int $id, string $type, ?int $establishmentId): object
    {
        $user = new \stdClass();
        $user->id = $id;
        $user->type = $type;
        $user->establishment_id = $establishmentId;

        return $user;
    }

    private function validatedRequest(array $params): Request
    {
        return new class($params) extends Request {
            public function __construct(array $params)
            {
                parent::__construct($params);
            }

            public function validate(array $rules, ...$args)
            {
                return app('validator')->make($this->all(), $rules)->validate();
            }
        };
    }

    private function makeFormRequest(string $class, array $data)
    {
        $req = new class($data, $class) {
            private $data;
            private $targetClass;

            public function __construct(array $data, string $targetClass)
            {
                $this->data = $data;
                $this->targetClass = $targetClass;
            }

            public function build()
            {
            $instance = $this->targetClass::create('/', 'GET', $this->data);
            $validator = app('validator')->make($this->data, $instance->rules(), $instance->messages());
            if (method_exists($instance, 'withValidator')) {
                $instance->withValidator($validator);
            }
            $instance->setValidator($validator);
            $validator->validate();

            return $instance;
            }
        };

        return $req->build();
    }

    public function test_every_phase_6_route_has_explicit_treasury_permission_rule_and_denies_other_modules(): void
    {
        $map = new AccessMap(require __DIR__ . '/../../../config/permissions.php');

        $expected = [
            ['GET', 'treasury/cash-flow'],
            ['GET', 'treasury/cash-flow/tables'],
            ['GET', 'treasury/cash-flow/records'],
            ['GET', 'treasury/cash-flow/excel'],
            ['GET', 'treasury/cash-flow/pdf'],
            ['GET', 'treasury/cash-book'],
            ['GET', 'treasury/cash-book/tables'],
            ['GET', 'treasury/cash-book/records'],
            ['GET', 'treasury/cash-book/excel'],
            ['GET', 'treasury/cash-book/pdf'],
            ['GET', 'treasury/cash-book/ple/preview'],
            ['GET', 'treasury/cash-book/ple/file'],
            ['GET', 'treasury/cash-book/ple/zip'],
            ['GET', 'treasury/cash-counts'],
            ['GET', 'treasury/cash-counts/tables'],
            ['GET', 'treasury/cash-counts/records'],
            ['GET', 'treasury/cash-counts/system-balance/1'],
            ['POST', 'treasury/cash-counts'],
            ['GET', 'treasury/cash-counts/1/pdf'],
        ];

        $source = file_get_contents(__DIR__ . '/../../../modules/Treasury/Routes/web.php');
        preg_match_all("/Route::(get|post|put|patch|delete)\(\s*'((?:cash-flow|cash-book|cash-counts)[^']*)'/", $source, $matches, PREG_SET_ORDER);
        $declared = [];
        foreach ($matches as $m) {
            $declared[] = strtoupper($m[1]) . ' treasury/' . preg_replace('/\{[^}]+\}/', '1', $m[2]);
        }

        foreach ($expected as [$method, $path]) {
            $this->assertContains($method . ' ' . $path, $declared, "Route not declared: {$method} {$path}");
            $rule = $map->resolve($path, $method);
            $this->assertNotNull($rule, "Missing permission rule for {$method} {$path}");
            $this->assertSame('treasury', $rule['module']);
            $this->assertTrue($rule['ajax']);

            foreach ([false, true] as $isAjax) {
                $denied = $map->decide($path, false, ['dashboard', 'finance', 'reports', 'configuration'], [], $isAjax, $method);
                $this->assertFalse($denied['allowed'], "Other modules must be denied on {$method} {$path}");
                $this->assertSame('module', $denied['reason']);
            }
            $this->assertTrue($map->decide($path, false, ['treasury'], [], true, $method)['allowed']);
        }

        $this->assertCount(count($expected), $declared, 'A Phase 6 route is not covered by this test.');
    }

    public function test_report_controller_scopes_tables_and_fails_closed_for_unassigned_non_admin(): void
    {
        $this->db->table('bank_accounts')->insert([
            'id' => 6, 'bank_id' => 1, 'description' => 'Sucursal 2', 'number' => '191-006',
            'currency_type_id' => 'PEN', 'status' => 1, 'initial_balance' => 0,
            'show_in_documents' => 1, 'account_type' => 'checking', 'itf_exempt' => 0, 'establishment_id' => 2,
        ]);

        $controller = $this->reportController();

        // Non-admin without establishment sees empty tables and fails closed
        $this->auth->currentUser = $this->user(3, 'seller', null);
        $cfTables = $controller->cashFlowTables()->getData(true);
        $cbTables = $controller->cashBookTables()->getData(true);
        $this->assertSame([], $cfTables['establishments']);
        $this->assertSame([], $cbTables['bank_accounts']);
        $this->assertSame([], $cbTables['cashes']);

        $cfReq = $this->makeFormRequest(CashFlowReportRequest::class, ['mode' => 'real', 'date_start' => '2026-11-01', 'date_end' => '2026-11-30']);
        $cfRes = $controller->cashFlowRecords($cfReq)->getData(true);
        $this->assertSame(0.0, (float) $cfRes['data']['opening_balance']);

        // Non-admin in est 1 cannot access est 2 bank account in Format 1.2, nor another cashier's cash in tables
        $cashAdmin = (int) $this->db->table('cash')->insertGetId([
            'user_id' => 1, 'date_opening' => '2026-11-20', 'time_opening' => '08:00:00',
            'beginning_balance' => 0, 'final_balance' => 0, 'income' => 0, 'state' => 1, 'reference_number' => 'ADM-CB',
        ]);
        $cashSeller = (int) $this->db->table('cash')->insertGetId([
            'user_id' => 2, 'date_opening' => '2026-11-20', 'time_opening' => '08:00:00',
            'beginning_balance' => 0, 'final_balance' => 0, 'income' => 0, 'state' => 1, 'reference_number' => 'SEL-CB',
        ]);

        $this->auth->currentUser = $this->user(2, 'seller', 1);
        $cbTablesEst1 = $controller->cashBookTables()->getData(true);
        $this->assertNotContains(6, array_column($cbTablesEst1['bank_accounts'], 'id'));
        $this->assertSame([$cashSeller], array_column($cbTablesEst1['cashes'], 'id'));
        $this->assertNotContains($cashAdmin, array_column($cbTablesEst1['cashes'], 'id'));

        $cbReq = $this->makeFormRequest(CashBookReportRequest::class, [
            'format' => '1.2',
            'date_start' => '2026-11-01',
            'date_end' => '2026-11-30',
            'bank_account_id' => 6,
        ]);

        $this->expectException(AuthorizationException::class);
        $controller->cashBookRecords($cbReq);
    }

    public function test_cash_count_controller_enforces_ownership_and_unassigned_non_admin_denial(): void
    {
        $cashAdmin = (int) $this->db->table('cash')->insertGetId([
            'user_id' => 1, 'date_opening' => '2026-11-20', 'time_opening' => '08:00:00',
            'beginning_balance' => 0, 'final_balance' => 0, 'income' => 0, 'state' => 1, 'reference_number' => 'ADM',
        ]);
        $cashSeller = (int) $this->db->table('cash')->insertGetId([
            'user_id' => 2, 'date_opening' => '2026-11-20', 'time_opening' => '08:00:00',
            'beginning_balance' => 0, 'final_balance' => 0, 'income' => 0, 'state' => 1, 'reference_number' => 'SEL',
        ]);

        $controller = $this->cashCountController();

        // Unassigned non-admin fails closed
        $this->auth->currentUser = $this->user(2, 'seller', null);
        $this->assertSame([], $controller->tables()->getData(true)['cashes']);
        $this->assertSame([], $controller->records($this->validatedRequest([]))->getData(true)['data']['rows']);

        // Assigned seller sees only own cash and can store count for it
        $this->auth->currentUser = $this->user(2, 'seller', 1);
        $sellerTables = $controller->tables()->getData(true);
        $this->assertSame([$cashSeller], array_column($sellerTables['cashes'], 'id'));

        $storeReq = $this->makeFormRequest(StoreCashCountRequest::class, [
            'cash_id' => $cashSeller,
            'denominations' => [['value' => 50, 'qty' => 2]],
            'notes' => 'Arqueo vendedor',
        ]);
        $stored = $controller->store($storeReq);
        $this->assertSame(201, $stored->getStatusCode());
        $this->assertSame(100.0, (float) $stored->getData(true)['data']['counted_balance']);

        // Seller trying to query systemBalance of admin's cash receives AuthorizationException
        $this->expectException(AuthorizationException::class);
        $controller->systemBalance($cashAdmin);
    }

    public function test_excel_export_classes_declare_explicit_properties_and_views_escape_html(): void
    {
        // PHP 8.3 dynamic property compliance: instantiation sets only declared properties
        $cfExport = new CashFlowExport(['mode' => 'real'], ['ruc' => '201', 'name' => 'Demo']);
        $f11Export = new CashBookFormat11Export(['meta' => ['format' => '1.1']]);
        $f12Export = new CashBookFormat12Export(['meta' => ['format' => '1.2']]);

        $this->assertInstanceOf(CashFlowExport::class, $cfExport);
        $this->assertInstanceOf(CashBookFormat11Export::class, $f11Export);
        $this->assertInstanceOf(CashBookFormat12Export::class, $f12Export);

        // Verify none of the Phase 6 Blade templates use unescaped {!! !!} output
        $views = [
            'modules/Treasury/Resources/views/reports/cash_flow_excel.blade.php',
            'modules/Treasury/Resources/views/reports/cash_flow_pdf.blade.php',
            'modules/Treasury/Resources/views/reports/cash_book_11_excel.blade.php',
            'modules/Treasury/Resources/views/reports/cash_book_11_pdf.blade.php',
            'modules/Treasury/Resources/views/reports/cash_book_12_excel.blade.php',
            'modules/Treasury/Resources/views/reports/cash_book_12_pdf.blade.php',
            'modules/Treasury/Resources/views/cash_counts/report_pdf.blade.php',
        ];
        foreach ($views as $relPath) {
            $contents = file_get_contents(__DIR__ . '/../../../' . $relPath);
            $this->assertNotFalse($contents, "Missing view {$relPath}");
            $this->assertStringNotContainsString('{!!', $contents, "Unescaped Blade output found in {$relPath}");
        }
    }

    public function test_excel_value_binder_neutralizes_formula_injection_and_preserves_leading_zeros_and_numeric_cells(): void
    {
        $sheet = (new Spreadsheet())->getActiveSheet();
        $binder = new TreasuryExcelValueBinder();

        // Formula injection payloads are neutralized with a leading apostrophe and forced to TYPE_STRING
        foreach (['=1+1', '+CMD|calc', '-SUM(A1:A2)', '@HYPERLINK("http://evil")', "\t=2+2"] as $idx => $payload) {
            $cell = $sheet->getCell('A' . ($idx + 1));
            $binder->bindValue($cell, $payload);
            $this->assertSame(DataType::TYPE_STRING, $cell->getDataType(), "Formula payload must be TYPE_STRING: {$payload}");
            $this->assertSame("'" . $payload, $cell->getValue());
        }

        // SUNAT codes and correlatives preserve leading zeros as TYPE_STRING
        foreach (['000001', '001', '003', '008'] as $idx => $code) {
            $cell = $sheet->getCell('B' . ($idx + 1));
            $binder->bindValue($cell, $code);
            $this->assertSame(DataType::TYPE_STRING, $cell->getDataType());
            $this->assertSame($code, $cell->getValue());
        }

        // Plain signed numbers remain TYPE_NUMERIC
        foreach (['1250.50', '-100.00', '0.00'] as $idx => $num) {
            $cell = $sheet->getCell('C' . ($idx + 1));
            $binder->bindValue($cell, $num);
            $this->assertSame(DataType::TYPE_NUMERIC, $cell->getDataType());
        }
    }

    public function test_export_endpoints_enforce_authorization_on_foreign_bank_account_and_other_users_cash_count(): void
    {
        $this->db->table('bank_accounts')->insert([
            'id' => 6, 'bank_id' => 1, 'description' => 'Sucursal 2', 'number' => '191-006',
            'currency_type_id' => 'PEN', 'status' => 1, 'initial_balance' => 0,
            'show_in_documents' => 1, 'account_type' => 'checking', 'itf_exempt' => 0, 'establishment_id' => 2,
        ]);

        $reportCtrl = $this->reportController();
        $this->auth->currentUser = $this->user(2, 'seller', 1);

        $cbReq = $this->makeFormRequest(CashBookReportRequest::class, [
            'format' => '1.2', 'date_start' => '2026-11-01', 'date_end' => '2026-11-30', 'bank_account_id' => 6,
        ]);

        try {
            $reportCtrl->cashBookExportExcel($cbReq);
            $this->fail('cashBookExportExcel must deny foreign bank account.');
        } catch (AuthorizationException $e) {
            $this->assertStringContainsString('otra sede', $e->getMessage());
        }

        try {
            $reportCtrl->cashBookExportPdf($cbReq);
            $this->fail('cashBookExportPdf must deny foreign bank account.');
        } catch (AuthorizationException $e) {
            $this->assertStringContainsString('otra sede', $e->getMessage());
        }

        // CashCountController::pdf denies seller on admin's cash count
        $cashAdmin = (int) $this->db->table('cash')->insertGetId([
            'user_id' => 1, 'date_opening' => '2026-11-20', 'time_opening' => '08:00:00',
            'beginning_balance' => 0, 'final_balance' => 0, 'income' => 0, 'state' => 1, 'reference_number' => 'ADM-PDF',
        ]);
        $countId = (int) (new CashCountService($this->makeBalanceService()))->create([
            'cash_id' => $cashAdmin, 'denominations' => [['value' => 100, 'qty' => 1]],
        ], 1, ['is_admin' => true, 'user_id' => 1, 'establishment_id' => 1])->id;

        $this->expectException(AuthorizationException::class);
        $this->cashCountController()->pdf($countId);
    }

    public function test_form_requests_allow_date_end_without_date_start_and_enforce_range_limits(): void
    {
        // Passing date_end without date_start must NOT fail after_or_equal:date_start
        $cfOnlyEnd = $this->makeFormRequest(CashFlowReportRequest::class, ['mode' => 'real', 'date_end' => '2026-11-30']);
        $this->assertSame('2026-11-30', $cfOnlyEnd->validated()['date_end']);

        $cbOnlyEnd = $this->makeFormRequest(CashBookReportRequest::class, ['format' => '1.1', 'date_end' => '2026-11-30']);
        $this->assertSame('2026-11-30', $cbOnlyEnd->validated()['date_end']);

        // Weekly range > 731 days fails in CashFlowReportRequest
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $this->makeFormRequest(CashFlowReportRequest::class, [
            'mode' => 'real',
            'granularity' => 'week',
            'date_start' => '2020-01-01',
            'date_end' => '2026-11-30',
        ]);
    }

    public function test_cash_book_request_rejects_ranges_wider_than_the_configured_maximum(): void
    {
        $this->assertSame(366, \Modules\Treasury\Services\CashBookReport::maxRangeDays());

        $ok = $this->makeFormRequest(CashBookReportRequest::class, ['format' => '1.1', 'date_start' => '2026-01-01', 'date_end' => '2026-12-31']);
        $this->assertSame('2026-12-31', $ok->validated()['date_end']);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $this->makeFormRequest(CashBookReportRequest::class, ['format' => '1.1', 'date_start' => '2024-01-01', 'date_end' => '2026-11-30']);
    }

    public function test_cash_selectors_return_open_shifts_plus_the_most_recent_closed_ones_and_accept_a_search_filter(): void
    {
        $this->app['config']->set('treasury.reports.cash_selector_limit', 3);

        $openId = (int) $this->db->table('cash')->insertGetId([
            'user_id' => 2, 'date_opening' => '2020-01-01', 'time_opening' => '08:00:00',
            'beginning_balance' => 0, 'final_balance' => 0, 'income' => 0, 'state' => 1, 'reference_number' => 'OLD-OPEN',
        ]);
        $closedIds = [];
        for ($i = 1; $i <= 10; $i++) {
            $closedIds[$i] = (int) $this->db->table('cash')->insertGetId([
                'user_id' => 2, 'date_opening' => sprintf('2026-10-%02d', $i), 'time_opening' => '08:00:00',
                'beginning_balance' => 0, 'final_balance' => 0, 'income' => 0, 'state' => 0, 'reference_number' => 'CLOSED-' . $i,
            ]);
        }

        $this->auth->currentUser = $this->user(2, 'seller', 1);
        $tables = $this->cashCountController()->tables()->getData(true);
        $ids = array_column($tables['cashes'], 'id');
        $this->assertSame([$openId, $closedIds[10], $closedIds[9], $closedIds[8]], $ids);

        $reportTables = $this->reportController()->cashBookTables()->getData(true);
        $this->assertSame($ids, array_column($reportTables['cashes'], 'id'));

        // search + date filters narrow the list
        $request = new Request(['search' => 'CLOSED-2', 'date_start' => '2026-10-01', 'date_end' => '2026-10-05']);
        $filtered = $this->cashCountController()->tables($request)->getData(true);
        $this->assertSame([$closedIds[2]], array_column($filtered['cashes'], 'id'));

        // Another cashier's shifts never leak through the selector
        $this->auth->currentUser = $this->user(1, 'seller', 1);
        $this->assertSame([], $this->cashCountController()->tables()->getData(true)['cashes']);
    }

    public function test_cash_count_cash_foreign_key_is_restrict_and_the_repair_migration_is_a_safe_noop_when_already_restrict(): void
    {
        $fks = $this->db->select("pragma foreign_key_list('treasury_cash_counts')");
        $cashFk = null;
        foreach ($fks as $fk) {
            if ($fk->from === 'cash_id') {
                $cashFk = $fk;
            }
        }
        $this->assertNotNull($cashFk);
        $this->assertSame('RESTRICT', strtoupper((string) $cashFk->on_delete));

        require_once __DIR__ . '/../../../database/migrations/tenant/2026_11_23_000013_tenant_restrict_treasury_cash_counts_cash_fk.php';
        $migration = new \TenantRestrictTreasuryCashCountsCashFk();
        $migration->up();
        $migration->up();
        $this->assertTrue($this->db->getSchemaBuilder()->hasTable('treasury_cash_counts'));
    }

    public function test_holiday_routes_have_a_treasury_rule_and_only_admins_can_write(): void
    {
        $map = new AccessMap(require __DIR__ . '/../../../config/permissions.php');
        foreach ([['GET', 'treasury/holidays'], ['GET', 'treasury/holidays/records'], ['POST', 'treasury/holidays'], ['DELETE', 'treasury/holidays/1']] as [$method, $path]) {
            $rule = $map->resolve($path, $method);
            $this->assertSame('treasury', $rule['module']);
            $this->assertTrue($rule['ajax']);
            $this->assertFalse($map->decide($path, false, ['dashboard', 'finance'], [], true, $method)['allowed']);
        }

        $this->app['validator']->setPresenceVerifier(new \Illuminate\Validation\DatabasePresenceVerifier($this->app['db']));
        $controller = new HolidayController();
        $payload = ['date' => '2028-01-01', 'name' => 'Año Nuevo', 'legal_reference' => 'DL 713 art. 6', 'source_url' => 'https://www.gob.pe/feriados'];

        // Any treasury user can read; a seller cannot write.
        $this->auth->currentUser = $this->user(2, 'seller', 1);
        $records = $controller->records($this->validatedRequest([]))->getData(true);
        $this->assertFalse($records['is_admin']);
        $this->assertContains(2026, $records['years']);
        $denied = $controller->store($this->validatedRequest($payload));
        $this->assertSame(403, $denied->getStatusCode());
        $this->assertSame(403, $controller->destroy(1)->getStatusCode());
        $this->assertSame(0, $this->db->table('treasury_holidays')->where('date', '2028-01-01')->count());

        // The admin adds 2028; the year is then covered by the calculator and the entry can be deleted.
        $this->auth->currentUser = $this->user(1, 'admin', 1);
        $created = $controller->store($this->validatedRequest($payload))->getData(true);
        $this->assertTrue($created['success']);
        $this->assertSame(1, $this->db->table('treasury_holidays')->where('date', '2028-01-01')->count());
        $this->assertTrue((new \Modules\Treasury\Support\BusinessDayCalculator(new \Modules\Treasury\Support\HolidayProvider()))->hasHolidaysForYear(2028));

        $this->assertTrue($controller->destroy($created['id'])->getData(true)['success']);
        $this->assertSame(0, $this->db->table('treasury_holidays')->where('date', '2028-01-01')->count());
    }

    public function test_adding_or_removing_a_holiday_refreshes_the_protest_deadline_of_open_bills(): void
    {
        config(['treasury.allow_future_dates' => true]);
        $this->app['validator']->setPresenceVerifier(new \Illuminate\Validation\DatabasePresenceVerifier($this->app['db']));
        $this->app->bind(\Modules\Treasury\Services\InstrumentService::class, function () {
            return new \Modules\Treasury\Services\InstrumentService(
                $this->makeMovementService(),
                new \Modules\Treasury\Support\InstrumentDeadlines(
                    new \Modules\Treasury\Services\TaxRateRepository(),
                    new \Modules\Treasury\Support\BusinessDayCalculator(new \Modules\Treasury\Support\HolidayProvider())
                )
            );
        });

        $this->auth->currentUser = $this->user(1, 'admin', 1);
        $bill = $this->app->make(\Modules\Treasury\Services\InstrumentService::class)->create([
            'type' => 'bill_receivable', 'number' => 'HOL-1', 'amount' => 500, 'currency_type_id' => 'PEN',
            'issue_date' => '2027-12-01', 'due_date' => '2028-01-03', 'bank_id' => 1, 'person_id' => 1,
        ], 1, ['is_admin' => true, 'user_id' => 1, 'establishment_id' => 1]);
        // No holiday loaded for 2028 yet: the stored deadline is an estimate.
        $this->assertTrue((bool) $bill->deadline_is_estimate);
        $estimated = $bill->protest_deadline->format('Y-m-d');

        $controller = new HolidayController();
        $created = $controller->store($this->validatedRequest(['date' => '2028-01-06', 'name' => 'Feriado de prueba']))->getData(true);
        $this->assertTrue($created['success']);

        $afterAdd = $bill->fresh();
        $this->assertFalse((bool) $afterAdd->deadline_is_estimate);
        $this->assertGreaterThan($estimated, $afterAdd->protest_deadline->format('Y-m-d'));

        // Removing it brings the previous count back (still flagged by the table, not by the estimate flag).
        $controller->destroy($created['id']);
        $this->assertSame($estimated, $bill->fresh()->protest_deadline->format('Y-m-d'));
        $this->assertTrue((bool) $bill->fresh()->deadline_is_estimate);
    }

    public function test_ple_endpoints_preview_refuse_and_download_with_the_cash_book_scoping(): void
    {
        $this->db->statement('alter table companies add column number text null');
        $this->db->statement('alter table companies add column name text null');
        $this->db->table('companies')->where('id', 1)->update(['number' => '20555555551', 'name' => 'Empresa Demo SAC']);
        $controller = $this->reportController();
        $this->auth->currentUser = $this->user(1, 'admin', 1);

        $request = function (array $params) {
            return $this->makeFormRequest(PleExportRequest::class, $params);
        };

        // Empty month: preview is exportable and both files exist; the ZIP contains them.
        $preview = $controller->pleCashBookPreview($request(['period' => '2026-12']))->getData(true)['data'];
        $this->assertTrue($preview['can_export']);
        $this->assertCount(2, $preview['files']);
        $this->assertArrayNotHasKey('content', $preview['files'][0]);

        $file = $controller->pleCashBookFile($request(['period' => '2026-12', 'format' => '1.1']));
        $this->assertSame('attachment; filename="LE2055555555120261200010100001011.TXT"', $file->headers->get('Content-Disposition'));
        $this->assertSame('text/plain; charset=UTF-8', $file->headers->get('Content-Type'));
        $this->assertSame('', $file->getContent());

        $zipResponse = $controller->pleCashBookZip($request(['period' => '2026-12']));
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($zipResponse->getFile()->getPathname()) === true);
        $this->assertSame(2, $zip->numFiles);
        $this->assertNotFalse($zip->locateName('LE2055555555120261200010200001011.TXT'));
        $zip->close();

        // A bank movement on an account without Tabla 3 code: preview lists the blocker, downloads answer 422.
        \Modules\Treasury\Models\TreasuryMovement::create([
            'establishment_id' => 1, 'date' => '2026-11-10', 'kind' => 'bank_fee', 'direction' => -1,
            'destination_type' => \App\Models\Tenant\BankAccount::class, 'destination_id' => 1, 'currency_type_id' => 'PEN',
            'amount' => 15.00, 'amount_pen' => 15.00, 'sunat_payment_code' => '003', 'operation_number' => 'F1',
            'treasury_concept_id' => 2, 'description' => 'Comision', 'state' => 'active', 'user_id' => 1,
        ]);
        $blocked = $controller->pleCashBookPreview($request(['period' => '2026-11']))->getData(true)['data'];
        $this->assertFalse($blocked['can_export']);
        $this->assertSame('missing_financial_entity_code', $blocked['blockers'][0]['code']);

        $refused = $controller->pleCashBookZip($request(['period' => '2026-11']));
        $this->assertSame(422, $refused->getStatusCode());
        $this->assertStringContainsString('Tabla 3', $refused->getData(true)['message']);
        $this->assertSame(422, $controller->pleCashBookFile($request(['period' => '2026-11', 'format' => '1.2']))->getStatusCode());

        // B-S7: the Tabla 3 blocker belongs to the 1.2 format only; the 1.1 (cash) file still downloads.
        $cashFile = $controller->pleCashBookFile($request(['period' => '2026-11', 'format' => '1.1']));
        $this->assertSame(200, $cashFile->getStatusCode());
        $this->assertStringContainsString('010100', $cashFile->headers->get('Content-Disposition'));
    }

    public function test_ple_endpoints_are_admin_only_for_preview_file_and_zip(): void
    {
        // B-W3: the PLE is the company's whole book; a scoped non-admin must not obtain a partial one.
        $controller = $this->reportController();
        $request = function (array $params) {
            return $this->makeFormRequest(PleExportRequest::class, $params);
        };

        foreach ([$this->user(3, 'seller', 1), $this->user(4, 'seller', null)] as $seller) {
            $this->auth->currentUser = $seller;
            foreach ([
                $controller->pleCashBookPreview($request(['period' => '2026-11'])),
                $controller->pleCashBookFile($request(['period' => '2026-11', 'format' => '1.1'])),
                $controller->pleCashBookZip($request(['period' => '2026-11'])),
            ] as $response) {
                $this->assertSame(403, $response->getStatusCode());
                $this->assertStringContainsString('administrador', $response->getData(true)['message']);
            }
        }
    }

    public function test_ple_request_validates_the_period(): void
    {
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $this->makeFormRequest(PleExportRequest::class, ['period' => '2026-13']);
    }
}
