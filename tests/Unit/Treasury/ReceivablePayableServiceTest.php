<?php

namespace Tests\Unit\Treasury;

use App\Models\Tenant\DocumentPayment;
use App\Models\Tenant\PurchasePayment;
use App\Models\Tenant\SaleNotePayment;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use Illuminate\Validation\ValidationException;
use Modules\Finance\Traits\UnpaidTrait;
use Modules\Treasury\Models\PaymentEvidence;
use Modules\Treasury\Models\PurchaseFee;
use Modules\Treasury\Services\BancarizationChecker;
use Modules\Treasury\Services\PurchaseFeeService;
use Modules\Treasury\Services\ReceivablePayableService;
use Modules\Treasury\Services\TaxRateRepository;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Phase 3 Receivables & Payables:
 * - Pure FIFO installment allocation & status/bucket transitions
 * - 1:1 balance parity with UnpaidController + RS 193-2020 detraction/retention/net credit
 * - Payables schedule (purchases with/without purchase_fees + expenses)
 * - Installment-level multi-currency aging & consolidated PEN totals
 * - Overdue, due-soon (0..7d), and versioned bancarization evidence alerts
 * - Non-admin establishment/user isolation
 * - PurchaseFeeService atomic schedule replacement/deletion & migration idempotency
 */
class ReceivablePayableServiceTest extends TestCase
{
    private $previousContainer;
    private $previousFacade;
    private $previousResolver;
    private $previousDispatcher;
    private $previousScopes;
    private $previousBooted;
    private $app;
    private $db;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousContainer = Container::getInstance();
        $this->previousFacade = Facade::getFacadeApplication();
        $this->previousResolver = Model::getConnectionResolver();
        $this->previousDispatcher = Model::getEventDispatcher();

        $scopes = new \ReflectionProperty(Model::class, 'globalScopes');
        $this->previousScopes = $scopes->getValue();

        $booted = new \ReflectionProperty(Model::class, 'booted');
        $this->previousBooted = $booted->getValue();

        $this->app = new Container();
        Container::setInstance($this->app);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->app);

        $this->app->instance('config', new Repository());
        $this->app->instance(
            'validator',
            new \Illuminate\Validation\Factory(
                new \Illuminate\Translation\Translator(new \Illuminate\Translation\ArrayLoader(), 'es'),
                $this->app
            )
        );

        $manager = new Manager($this->app);
        $manager->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''], 'tenant');
        $database = $manager->getDatabaseManager();
        $database->setDefaultConnection('tenant');
        $this->app->instance('db', $database);
        $this->app->instance('db.schema', $database->connection('tenant')->getSchemaBuilder());

        Model::setConnectionResolver($database);
        Model::setEventDispatcher(new Dispatcher($this->app));

        $this->db = $database->connection('tenant');

        $this->createSchema();
        $this->seedTaxRatesAndCatalogs();
    }

    protected function tearDown(): void
    {
        if ($this->db !== null) {
            while ($this->db->transactionLevel() > 0) {
                $this->db->rollBack();
            }
        }

        $scopes = new \ReflectionProperty(Model::class, 'globalScopes');
        $scopes->setValue(null, $this->previousScopes);

        $booted = new \ReflectionProperty(Model::class, 'booted');
        $booted->setValue(null, $this->previousBooted);

        if ($this->previousResolver) {
            Model::setConnectionResolver($this->previousResolver);
        } else {
            Model::unsetConnectionResolver();
        }

        if ($this->previousDispatcher) {
            Model::setEventDispatcher($this->previousDispatcher);
        } else {
            Model::unsetEventDispatcher();
        }

        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previousFacade);
        Container::setInstance($this->previousContainer);

        parent::tearDown();
    }

    private function createSchema(): void
    {
        foreach ([
            'persons (id integer primary key autoincrement, type text null, name text, number text null)',
            'exchange_rates (id integer primary key autoincrement, date text, purchase numeric, sale numeric)',
            'documents (id integer primary key autoincrement, user_id integer, customer_id integer, establishment_id integer, state_type_id text, document_type_id text, series text, number text, date_of_issue text, total numeric, currency_type_id text, exchange_rate_sale numeric, detraction text null, discounts text null, total_canceled integer default 0)',
            'document_payments (id integer primary key autoincrement, document_id integer, payment numeric, date_of_payment text)',
            'document_fee (id integer primary key autoincrement, document_id integer, date text, currency_type_id text, amount numeric)',
            'invoices (id integer primary key autoincrement, document_id integer, date_of_due text null)',
            'dispatches (id integer primary key autoincrement, reference_document_id integer, external_id text null, series text null, number text null, date_of_issue text null, date_of_shipping text null)',
            'sale_notes (id integer primary key autoincrement, user_id integer, customer_id integer, establishment_id integer, state_type_id text, series text null, number text null, prefix text null, filename text null, date_of_issue text, total numeric, currency_type_id text, exchange_rate_sale numeric, changed integer default 0, total_canceled integer default 0)',
            'sale_note_payments (id integer primary key autoincrement, sale_note_id integer, payment numeric, date_of_payment text)',
            'purchases (id integer primary key autoincrement, user_id integer, supplier_id integer, establishment_id integer, state_type_id text, document_type_id text, series text, number text, date_of_issue text, date_of_due text null, total numeric, currency_type_id text, exchange_rate_sale numeric, total_canceled integer default 0)',
            'purchase_payments (id integer primary key autoincrement, purchase_id integer, payment numeric, date_of_payment text)',
            'expenses (id integer primary key autoincrement, user_id integer, supplier_id integer, establishment_id integer, state_type_id text, number text, date_of_issue text, total numeric, currency_type_id text, exchange_rate_sale numeric)',
            'expense_payments (id integer primary key autoincrement, expense_id integer, payment numeric, date_of_payment text)',
            'treasury_tax_rates (id integer primary key autoincrement, tax text, code text null, rate numeric null, amount numeric null, min_operation numeric null, valid_from text, valid_to text null, legal_reference text, created_at text null, updated_at text null)',
            'payment_evidences (id integer primary key autoincrement, payable_type text, payable_id integer, sunat_payment_code text, operation_number text null, bank_id integer null, bank_account_id integer null, operation_date text, payer_or_beneficiary_document text null, payer_or_beneficiary_name text null, third_party_payment integer default 0, third_party_reported_to_sunat integer default 0, bancarization_exception text null, created_at text null, updated_at text null)',
        ] as $table) {
            $this->db->statement('create table ' . $table);
        }

        // Create purchase_fees through the real Phase 3 migration.
        require_once __DIR__ . '/../../../database/migrations/tenant/2026_11_23_000004_tenant_create_purchase_fees_table.php';
        (new \TenantCreatePurchaseFeesTable())->up();
    }

    private function seedTaxRatesAndCatalogs(): void
    {
        $this->db->table('treasury_tax_rates')->insert([
            [
                'tax' => 'bancarization_threshold_pen',
                'code' => null,
                'rate' => null,
                'amount' => 2000.00,
                'min_operation' => null,
                'valid_from' => '2022-04-01',
                'valid_to' => null,
                'legal_reference' => 'DL 1529',
            ],
            [
                'tax' => 'bancarization_threshold_usd',
                'code' => null,
                'rate' => null,
                'amount' => 500.00,
                'min_operation' => null,
                'valid_from' => '2022-04-01',
                'valid_to' => null,
                'legal_reference' => 'DL 1529',
            ],
        ]);

        $this->db->table('exchange_rates')->insert([
            ['date' => '2026-11-20', 'purchase' => 3.74, 'sale' => 3.76],
        ]);

        $this->db->table('persons')->insert([
            ['id' => 1, 'type' => 'customers', 'name' => 'Cliente Alfa SAC', 'number' => '20100000001'],
            ['id' => 2, 'type' => 'customers', 'name' => 'Cliente Beta EIRL', 'number' => '20100000002'],
            ['id' => 10, 'type' => 'suppliers', 'name' => 'Proveedor Uno SA', 'number' => '20200000010'],
            ['id' => 11, 'type' => 'suppliers', 'name' => 'Proveedor Dos SRL', 'number' => '20200000011'],
        ]);
    }

    private function makeService(): ReceivablePayableService
    {
        $repo = new TaxRateRepository();

        return new ReceivablePayableService($repo, new BancarizationChecker($repo));
    }

    public function test_fifo_installment_allocation_assigns_payments_statuses_and_buckets_deterministically(): void
    {
        $service = $this->makeService();

        // Intentionally unsorted input to verify date ASC, id ASC sorting.
        $installments = [
            ['id' => 3, 'date' => '2026-11-25', 'amount' => 300.00, 'source' => 'document_fee'], // +5 days -> due_soon
            ['id' => 1, 'date' => '2026-10-01', 'amount' => 200.00, 'source' => 'document_fee'], // fully paid by 350
            ['id' => 4, 'date' => '2026-12-20', 'amount' => 400.00, 'source' => 'document_fee'], // +30 days -> pending
            ['id' => 2, 'date' => '2026-10-25', 'amount' => 250.00, 'source' => 'document_fee'], // partially paid (150), 26d overdue
        ];

        $allocated = $service->allocateInstallmentsFifo($installments, 350.00, '2026-11-20');

        $this->assertCount(4, $allocated);

        // #1: 2026-10-01 -> paid
        $this->assertSame(1, $allocated[0]['id']);
        $this->assertSame(200.0, $allocated[0]['paid_amount']);
        $this->assertSame(0.0, $allocated[0]['pending_amount']);
        $this->assertSame(0, $allocated[0]['days_overdue']);
        $this->assertSame('paid', $allocated[0]['status']);
        $this->assertFalse($allocated[0]['is_partial']);
        $this->assertSame('current', $allocated[0]['bucket']);

        // #2: 2026-10-25 -> partial AND overdue (26 days -> days_1_30)
        $this->assertSame(2, $allocated[1]['id']);
        $this->assertSame(150.0, $allocated[1]['paid_amount']);
        $this->assertSame(100.0, $allocated[1]['pending_amount']);
        $this->assertSame(26, $allocated[1]['days_overdue']);
        $this->assertSame('overdue', $allocated[1]['status']);
        $this->assertTrue($allocated[1]['is_partial']);
        $this->assertSame('days_1_30', $allocated[1]['bucket']);

        // #3: 2026-11-25 -> due in 5 days -> due_soon, bucket current
        $this->assertSame(3, $allocated[2]['id']);
        $this->assertSame(0.0, $allocated[2]['paid_amount']);
        $this->assertSame(300.0, $allocated[2]['pending_amount']);
        $this->assertSame(-5, $allocated[2]['days_overdue']);
        $this->assertSame(5, $allocated[2]['days_until_due']);
        $this->assertSame('due_soon', $allocated[2]['status']);
        $this->assertSame('current', $allocated[2]['bucket']);

        // #4: 2026-12-20 -> future beyond 7 days -> pending
        $this->assertSame(4, $allocated[3]['id']);
        $this->assertSame(400.0, $allocated[3]['pending_amount']);
        $this->assertSame('pending', $allocated[3]['status']);
        $this->assertSame('current', $allocated[3]['bucket']);

        // Partial payment on future installment beyond 7 days -> status 'partial', is_partial = true
        $futurePartial = $service->allocateInstallmentsFifo([
            ['id' => 10, 'date' => '2026-12-15', 'amount' => 500.335, 'source' => 'purchase_fee'],
        ], 100.114, '2026-11-20');
        $this->assertSame(500.34, $futurePartial[0]['amount']);
        $this->assertSame(100.11, $futurePartial[0]['paid_amount']);
        $this->assertSame(400.23, $futurePartial[0]['pending_amount']);
        $this->assertSame('partial', $futurePartial[0]['status']);
        $this->assertTrue($futurePartial[0]['is_partial']);
    }

    public function test_receivables_match_unpaid_controller_parity_and_expose_rs_193_2020_net_credit(): void
    {
        // Doc 1: PEN credit invoice with 12% detraction (120 PEN) and 2 document_fee rows summing to 880 PEN
        $this->db->table('documents')->insert([
            'id' => 1,
            'user_id' => 1,
            'customer_id' => 1,
            'establishment_id' => 1,
            'state_type_id' => '05',
            'document_type_id' => '01',
            'series' => 'F001',
            'number' => '100',
            'date_of_issue' => '2026-10-01',
            'total' => 1000.00,
            'currency_type_id' => 'PEN',
            'exchange_rate_sale' => 3.75,
            'detraction' => json_encode(['detraction_type_id' => '022', 'percentage' => 12, 'amount' => 120.00]),
            'discounts' => null,
            'total_canceled' => 0,
        ]);
        $this->db->table('document_fee')->insert([
            ['id' => 1, 'document_id' => 1, 'date' => '2026-10-31', 'currency_type_id' => 'PEN', 'amount' => 440.00],
            ['id' => 2, 'document_id' => 1, 'date' => '2026-11-30', 'currency_type_id' => 'PEN', 'amount' => 440.00],
        ]);
        $this->db->table('document_payments')->insert([
            ['id' => 1, 'document_id' => 1, 'payment' => 440.00, 'date_of_payment' => '2026-10-30'],
        ]);

        // Doc 2: USD invoice with detraction stored in PEN (380 PEN / 3.80 = 100 USD) + retention discount type 62 (30 USD), no document_fee
        $this->db->table('documents')->insert([
            'id' => 2,
            'user_id' => 1,
            'customer_id' => 2,
            'establishment_id' => 1,
            'state_type_id' => '05',
            'document_type_id' => '01',
            'series' => 'F001',
            'number' => '101',
            'date_of_issue' => '2026-11-01',
            'total' => 1000.00,
            'currency_type_id' => 'USD',
            'exchange_rate_sale' => 3.80,
            'detraction' => json_encode(['detraction_type_id' => '022', 'percentage' => 10, 'amount' => 380.00]),
            'discounts' => json_encode([
                ['discount_type_id' => '62', 'amount' => 30.00],
                ['discount_type_id' => '02', 'amount' => 15.00],
            ]),
            'total_canceled' => 0,
        ]);
        $this->db->table('invoices')->insert([
            ['id' => 1, 'document_id' => 2, 'date_of_due' => '2026-11-25'],
        ]);
        $this->db->table('document_payments')->insert([
            ['id' => 2, 'document_id' => 2, 'payment' => 250.00, 'date_of_payment' => '2026-11-10'],
        ]);

        // Doc 3: Voided (state_type_id = 11) -> MUST be excluded
        $this->db->table('documents')->insert([
            'id' => 3,
            'user_id' => 1,
            'customer_id' => 1,
            'establishment_id' => 1,
            'state_type_id' => '11',
            'document_type_id' => '01',
            'series' => 'F001',
            'number' => '102',
            'date_of_issue' => '2026-11-01',
            'total' => 500.00,
            'currency_type_id' => 'PEN',
            'exchange_rate_sale' => 3.75,
            'total_canceled' => 0,
        ]);

        // SaleNote 1: Unpaid accepted sale note
        $this->db->table('sale_notes')->insert([
            'id' => 20,
            'user_id' => 1,
            'customer_id' => 2,
            'establishment_id' => 1,
            'state_type_id' => '01',
            'series' => 'NV01',
            'number' => '20',
            'filename' => 'NV01-20',
            'date_of_issue' => '2026-11-05',
            'total' => 300.00,
            'currency_type_id' => 'PEN',
            'exchange_rate_sale' => 3.75,
            'changed' => 0,
            'total_canceled' => 0,
        ]);
        $this->db->table('sale_note_payments')->insert([
            ['id' => 1, 'sale_note_id' => 20, 'payment' => 100.00, 'date_of_payment' => '2026-11-06'],
        ]);

        $service = $this->makeService();
        $result = $service->getReceivables(['establishment_id' => 1], '2026-11-20');

        // 3 unpaid obligations: Doc 1, Doc 2, SaleNote 20 (Doc 3 voided is excluded)
        $this->assertCount(3, $result['rows']);

        // Compare 1:1 with UnpaidTrait::transformRecords balance formula (total - total_payment)
        $unpaidHarness = new class {
            use UnpaidTrait;
        };
        $unpaidRows = $unpaidHarness->transformRecords(collect([
            (object) ['id' => 1, 'date_of_issue' => '2026/10/01', 'customer_name' => 'Cliente Alfa SAC', 'customer_id' => 1, 'document_type_id' => '01', 'number_full' => 'F001-100', 'total' => 1000.0, 'total_payment' => 440.0, 'total_subtraction' => 560.0, 'type' => 'document', 'currency_type_id' => 'PEN', 'exchange_rate_sale' => 3.75, 'user_id' => 1, 'username' => 'Admin'],
            (object) ['id' => 2, 'date_of_issue' => '2026/11/01', 'customer_name' => 'Cliente Beta EIRL', 'customer_id' => 2, 'document_type_id' => '01', 'number_full' => 'F001-101', 'total' => 1000.0, 'total_payment' => 250.0, 'total_subtraction' => 750.0, 'type' => 'document', 'currency_type_id' => 'USD', 'exchange_rate_sale' => 3.80, 'user_id' => 1, 'username' => 'Admin'],
            (object) ['id' => 20, 'date_of_issue' => '2026/11/05', 'customer_name' => 'Cliente Beta EIRL', 'customer_id' => 2, 'document_type_id' => null, 'number_full' => 'NV01-20', 'total' => 300.0, 'total_payment' => 100.0, 'total_subtraction' => 200.0, 'type' => 'sale_note', 'currency_type_id' => 'PEN', 'exchange_rate_sale' => 3.75, 'user_id' => 1, 'username' => 'Admin'],
        ]))->keyBy(fn ($r) => $r['type'] . ':' . $r['id']);

        $byKey = [];
        foreach ($result['rows'] as $row) {
            $byKey[$row['type'] . ':' . $row['id']] = $row;
        }

        foreach ($unpaidRows as $key => $legacyRow) {
            $this->assertArrayHasKey($key, $byKey);
            $this->assertSame((float) $legacyRow['total_to_pay'], $byKey[$key]['balance'], "Parity mismatch for {$key}");
        }

        // RS 193-2020 fields on Doc 1 (with document_fee)
        $doc1 = $byKey['document:1'];
        $this->assertSame(120.0, $doc1['detraction_amount']);
        $this->assertSame(120.0, $doc1['detraction_amount_pen']);
        $this->assertSame(880.0, $doc1['net_credit_amount']);
        $this->assertTrue($doc1['has_installments']);
        $this->assertSame('paid', $doc1['installments'][0]['status']);
        $this->assertSame('pending', $doc1['installments'][1]['status']);
        $this->assertSame(440.0, $doc1['installments'][1]['pending_amount']);

        // RS 193-2020 fields on Doc 2 (USD without document_fee)
        $doc2 = $byKey['document:2'];
        $this->assertSame(100.0, $doc2['detraction_amount']);
        $this->assertSame(380.0, $doc2['detraction_amount_pen']);
        $this->assertSame(30.0, $doc2['retention_amount']);
        $this->assertSame(870.0, $doc2['net_credit_amount']);
        $this->assertSame('due_soon', $doc2['status']);

        // Totals by currency + consolidated PEN: PEN (560 + 200 = 760) + USD (750 * 3.80 = 2850) = 3610 PEN
        $this->assertSame(760.0, $result['totals']['by_currency']['PEN']['balance']);
        $this->assertSame(750.0, $result['totals']['by_currency']['USD']['balance']);
        $this->assertSame(3610.0, $result['totals']['consolidated_pen']);
    }

    public function test_payables_schedule_includes_purchases_with_fees_and_expenses_and_excludes_voided_or_paid(): void
    {
        // Purchase 1: with 2 manual purchase_fees, 1 partial payment
        $this->db->table('purchases')->insert([
            'id' => 1,
            'user_id' => 1,
            'supplier_id' => 10,
            'establishment_id' => 1,
            'state_type_id' => '01',
            'document_type_id' => '01',
            'series' => 'F002',
            'number' => '50',
            'date_of_issue' => '2026-10-01',
            'date_of_due' => '2026-11-01',
            'total' => 1000.00,
            'currency_type_id' => 'PEN',
            'exchange_rate_sale' => 3.75,
            'total_canceled' => 0,
        ]);
        $this->db->table('purchase_fees')->insert([
            ['id' => 1, 'purchase_id' => 1, 'date' => '2026-10-20', 'currency_type_id' => 'PEN', 'amount' => 400.00],
            ['id' => 2, 'purchase_id' => 1, 'date' => '2026-11-24', 'currency_type_id' => 'PEN', 'amount' => 600.00],
        ]);
        $this->db->table('purchase_payments')->insert([
            ['id' => 1, 'purchase_id' => 1, 'payment' => 450.00, 'date_of_payment' => '2026-10-21'],
        ]);

        // Purchase 2: fully paid -> excluded
        $this->db->table('purchases')->insert([
            'id' => 2,
            'user_id' => 1,
            'supplier_id' => 10,
            'establishment_id' => 1,
            'state_type_id' => '01',
            'document_type_id' => '01',
            'series' => 'F002',
            'number' => '51',
            'date_of_issue' => '2026-10-05',
            'date_of_due' => '2026-11-05',
            'total' => 200.00,
            'currency_type_id' => 'PEN',
            'exchange_rate_sale' => 3.75,
            'total_canceled' => 1,
        ]);
        $this->db->table('purchase_payments')->insert([
            ['id' => 2, 'purchase_id' => 2, 'payment' => 200.00, 'date_of_payment' => '2026-10-06'],
        ]);

        // Purchase 3: voided -> excluded
        $this->db->table('purchases')->insert([
            'id' => 3,
            'user_id' => 1,
            'supplier_id' => 10,
            'establishment_id' => 1,
            'state_type_id' => '11',
            'document_type_id' => '01',
            'series' => 'F002',
            'number' => '52',
            'date_of_issue' => '2026-10-05',
            'date_of_due' => '2026-11-05',
            'total' => 500.00,
            'currency_type_id' => 'PEN',
            'exchange_rate_sale' => 3.75,
            'total_canceled' => 0,
        ]);

        // Expense 1: unpaid expense
        $this->db->table('expenses')->insert([
            'id' => 5,
            'user_id' => 1,
            'supplier_id' => 11,
            'establishment_id' => 1,
            'state_type_id' => '05',
            'number' => 'G-005',
            'date_of_issue' => '2026-10-10',
            'total' => 300.00,
            'currency_type_id' => 'PEN',
            'exchange_rate_sale' => 3.75,
        ]);
        $this->db->table('expense_payments')->insert([
            ['id' => 1, 'expense_id' => 5, 'payment' => 100.00, 'date_of_payment' => '2026-10-12'],
        ]);

        $service = $this->makeService();
        $result = $service->getPayables([], '2026-11-20');

        $this->assertCount(2, $result['rows']);
        $byKey = [];
        foreach ($result['rows'] as $row) {
            $byKey[$row['type'] . ':' . $row['id']] = $row;
        }

        // Purchase 1: installment 1 (400) paid, installment 2 (600) has 50 paid -> 550 pending, due in 4d (due_soon)
        $p1 = $byKey['purchase:1'];
        $this->assertSame(550.0, $p1['balance']);
        $this->assertTrue($p1['has_installments']);
        $this->assertSame('paid', $p1['installments'][0]['status']);
        $this->assertSame(550.0, $p1['installments'][1]['pending_amount']);
        $this->assertSame('due_soon', $p1['installments'][1]['status']);

        // Expense 1: 200 pending, 41 days overdue
        $e5 = $byKey['expense:5'];
        $this->assertSame(200.0, $e5['balance']);
        $this->assertSame('overdue', $e5['status']);
        $this->assertSame(41, $e5['max_days_overdue']);
    }

    public function test_aging_splits_installments_across_all_five_buckets_by_person_and_currency(): void
    {
        // One document with 5 installments landing in all 5 aging buckets as of 2026-11-20
        $this->db->table('documents')->insert([
            'id' => 10,
            'user_id' => 1,
            'customer_id' => 1,
            'establishment_id' => 1,
            'state_type_id' => '05',
            'document_type_id' => '01',
            'series' => 'F001',
            'number' => '500',
            'date_of_issue' => '2026-07-01',
            'total' => 1500.00,
            'currency_type_id' => 'PEN',
            'exchange_rate_sale' => 3.75,
        ]);
        $this->db->table('document_fee')->insert([
            ['id' => 1, 'document_id' => 10, 'date' => '2026-08-01', 'currency_type_id' => 'PEN', 'amount' => 100.00], // 111d -> >90
            ['id' => 2, 'document_id' => 10, 'date' => '2026-09-10', 'currency_type_id' => 'PEN', 'amount' => 200.00], // 71d -> 61-90
            ['id' => 3, 'document_id' => 10, 'date' => '2026-10-05', 'currency_type_id' => 'PEN', 'amount' => 300.00], // 46d -> 31-60
            ['id' => 4, 'document_id' => 10, 'date' => '2026-11-05', 'currency_type_id' => 'PEN', 'amount' => 400.00], // 15d -> 1-30
            ['id' => 5, 'document_id' => 10, 'date' => '2026-12-01', 'currency_type_id' => 'PEN', 'amount' => 500.00], // -11d -> current
        ]);

        // Second document for SAME customer in USD (Establishment 2) to verify multi-currency separation & establishment filter
        $this->db->table('documents')->insert([
            'id' => 11,
            'user_id' => 1,
            'customer_id' => 1,
            'establishment_id' => 2,
            'state_type_id' => '05',
            'document_type_id' => '01',
            'series' => 'F002',
            'number' => '600',
            'date_of_issue' => '2026-10-01',
            'total' => 100.00,
            'currency_type_id' => 'USD',
            'exchange_rate_sale' => 3.80,
        ]);
        $this->db->table('invoices')->insert([
            ['id' => 11, 'document_id' => 11, 'date_of_due' => '2026-11-10'], // 10d -> 1-30
        ]);

        $service = $this->makeService();
        $agingAll = $service->getAging('receivables', [], '2026-11-20');

        // Same customer has TWO separate rows: one PEN and one USD (never mixed!)
        $this->assertCount(2, $agingAll['rows']);

        $penRow = null;
        $usdRow = null;
        foreach ($agingAll['rows'] as $row) {
            if ($row['currency_type_id'] === 'PEN') {
                $penRow = $row;
            } elseif ($row['currency_type_id'] === 'USD') {
                $usdRow = $row;
            }
        }

        $this->assertNotNull($penRow);
        $this->assertSame(500.0, $penRow['current']);
        $this->assertSame(400.0, $penRow['days_1_30']);
        $this->assertSame(300.0, $penRow['days_31_60']);
        $this->assertSame(200.0, $penRow['days_61_90']);
        $this->assertSame(100.0, $penRow['days_over_90']);
        $this->assertSame(1500.0, $penRow['total']);
        $this->assertSame(1500.0, $penRow['total_pen']);

        $this->assertNotNull($usdRow);
        $this->assertSame(100.0, $usdRow['days_1_30']);
        $this->assertSame(380.0, $usdRow['total_pen']);

        // Consolidated PEN total: 1500 + 380 = 1880
        $this->assertSame(1880.0, $agingAll['totals_pen']['total']);
        $this->assertSame(780.0, $agingAll['totals_pen']['days_1_30']);

        // Establishment filter + currency filter
        $agingEst2Usd = $service->getAging('receivables', ['establishment_id' => 2, 'currency_type_id' => 'USD'], '2026-11-20');
        $this->assertCount(1, $agingEst2Usd['rows']);
        $this->assertSame('USD', $agingEst2Usd['rows'][0]['currency_type_id']);
    }

    public function test_alerts_detect_overdue_due_soon_and_bancarization_and_clear_when_valid_evidence_attached(): void
    {
        // Doc 1: PEN 2500 (>= 2000 threshold), 1 payment without evidence, overdue
        $this->db->table('documents')->insert([
            'id' => 1,
            'user_id' => 1,
            'customer_id' => 1,
            'establishment_id' => 1,
            'state_type_id' => '05',
            'document_type_id' => '01',
            'series' => 'F001',
            'number' => '1',
            'date_of_issue' => '2026-10-01',
            'total' => 2500.00,
            'currency_type_id' => 'PEN',
            'exchange_rate_sale' => 3.75,
        ]);
        $this->db->table('invoices')->insert([
            ['id' => 1, 'document_id' => 1, 'date_of_due' => '2026-11-10'], // 10d overdue
        ]);
        $this->db->table('document_payments')->insert([
            ['id' => 101, 'document_id' => 1, 'payment' => 1000.00, 'date_of_payment' => '2026-11-05'],
        ]);

        // Purchase 1: USD 600 (>= 500 USD threshold), due in 3 days, 1 payment without evidence
        $this->db->table('purchases')->insert([
            'id' => 1,
            'user_id' => 1,
            'supplier_id' => 10,
            'establishment_id' => 1,
            'state_type_id' => '01',
            'document_type_id' => '01',
            'series' => 'F009',
            'number' => '9',
            'date_of_issue' => '2026-11-01',
            'date_of_due' => '2026-11-23', // +3 days -> due_soon
            'total' => 600.00,
            'currency_type_id' => 'USD',
            'exchange_rate_sale' => 3.75,
        ]);
        $this->db->table('purchase_payments')->insert([
            ['id' => 201, 'purchase_id' => 1, 'payment' => 200.00, 'date_of_payment' => '2026-11-10'],
        ]);

        $service = $this->makeService();
        $alertsBefore = $service->getAlerts([], '2026-11-20');

        $this->assertSame(1, $alertsBefore['summary']['overdue_count']);
        $this->assertSame(1, $alertsBefore['summary']['due_soon_count']);
        $this->assertSame(2, $alertsBefore['summary']['unbancarized_count']);

        // Attaching cash evidence WITHOUT art. 6 exception must NOT clear the bancarization alert
        PaymentEvidence::create([
            'payable_type' => DocumentPayment::class,
            'payable_id' => 101,
            'sunat_payment_code' => '009',
            'operation_date' => '2026-11-05',
            'bancarization_exception' => null,
        ]);
        $alertsCashNoException = $service->getAlerts([], '2026-11-20');
        $this->assertSame(2, $alertsCashNoException['summary']['unbancarized_count']);

        // Updating to valid non-cash evidence (003 Transferencia) clears Doc 1; attaching 001 clears Purchase 1
        PaymentEvidence::where('payable_type', DocumentPayment::class)
            ->where('payable_id', 101)
            ->update(['sunat_payment_code' => '003', 'operation_number' => 'OP-101']);

        PaymentEvidence::create([
            'payable_type' => PurchasePayment::class,
            'payable_id' => 201,
            'sunat_payment_code' => '001',
            'operation_number' => 'OP-201',
            'operation_date' => '2026-11-10',
        ]);

        $alertsAfter = $service->getAlerts([], '2026-11-20');
        $this->assertSame(0, $alertsAfter['summary']['unbancarized_count']);
        $this->assertSame(1, $alertsAfter['summary']['overdue_count']);
        $this->assertSame(1, $alertsAfter['summary']['due_soon_count']);
    }

    public function test_non_admin_scoping_isolates_receivables_and_payables_by_user_and_establishment(): void
    {
        $this->db->table('documents')->insert([
            ['id' => 1, 'user_id' => 2, 'customer_id' => 1, 'establishment_id' => 1, 'state_type_id' => '05', 'document_type_id' => '01', 'series' => 'F001', 'number' => '1', 'date_of_issue' => '2026-11-01', 'total' => 500.00, 'currency_type_id' => 'PEN', 'exchange_rate_sale' => 3.75],
            ['id' => 2, 'user_id' => 3, 'customer_id' => 1, 'establishment_id' => 1, 'state_type_id' => '05', 'document_type_id' => '01', 'series' => 'F001', 'number' => '2', 'date_of_issue' => '2026-11-01', 'total' => 600.00, 'currency_type_id' => 'PEN', 'exchange_rate_sale' => 3.75],
            ['id' => 3, 'user_id' => 2, 'customer_id' => 1, 'establishment_id' => 2, 'state_type_id' => '05', 'document_type_id' => '01', 'series' => 'F002', 'number' => '3', 'date_of_issue' => '2026-11-01', 'total' => 700.00, 'currency_type_id' => 'PEN', 'exchange_rate_sale' => 3.75],
        ]);

        $this->db->table('purchases')->insert([
            ['id' => 1, 'user_id' => 2, 'supplier_id' => 10, 'establishment_id' => 1, 'state_type_id' => '01', 'document_type_id' => '01', 'series' => 'F001', 'number' => '10', 'date_of_issue' => '2026-11-01', 'date_of_due' => '2026-11-30', 'total' => 400.00, 'currency_type_id' => 'PEN', 'exchange_rate_sale' => 3.75],
            ['id' => 2, 'user_id' => 3, 'supplier_id' => 10, 'establishment_id' => 2, 'state_type_id' => '01', 'document_type_id' => '01', 'series' => 'F002', 'number' => '20', 'date_of_issue' => '2026-11-01', 'date_of_due' => '2026-11-30', 'total' => 800.00, 'currency_type_id' => 'PEN', 'exchange_rate_sale' => 3.75],
        ]);

        $service = $this->makeService();

        // Non-admin user 2 in establishment 1 sees ONLY Doc 1 and Purchase 1
        $sellerAr = $service->getReceivables(['is_admin' => false, 'user_id' => 2, 'establishment_id' => 1], '2026-11-20');
        $this->assertSame([1], array_column($sellerAr['rows'], 'id'));

        $sellerAp = $service->getPayables(['is_admin' => false, 'user_id' => 2, 'establishment_id' => 1], '2026-11-20');
        $this->assertSame([1], array_column($sellerAp['rows'], 'id'));

        // Admin sees all 3 documents and both purchases
        $adminAr = $service->getReceivables(['is_admin' => true], '2026-11-20');
        $this->assertCount(3, $adminAr['rows']);

        $adminAp = $service->getPayables(['is_admin' => true], '2026-11-20');
        $this->assertCount(2, $adminAp['rows']);
    }

    public function test_purchase_fee_service_and_migration_idempotency(): void
    {
        // Migration up() -> down() -> up() idempotency
        $migration = new \TenantCreatePurchaseFeesTable();
        $migration->up(); // already exists: must be no-op
        $this->assertTrue($this->db->getSchemaBuilder()->hasTable('purchase_fees'));
        $migration->down();
        $this->assertFalse($this->db->getSchemaBuilder()->hasTable('purchase_fees'));
        $migration->up();
        $this->assertTrue($this->db->getSchemaBuilder()->hasTable('purchase_fees'));

        $this->db->table('purchases')->insert([
            ['id' => 1, 'user_id' => 1, 'supplier_id' => 10, 'establishment_id' => 1, 'state_type_id' => '01', 'document_type_id' => '01', 'series' => 'F001', 'number' => '1', 'date_of_issue' => '2026-11-01', 'date_of_due' => '2026-12-01', 'total' => 1000.00, 'currency_type_id' => 'USD', 'exchange_rate_sale' => 3.75],
            ['id' => 2, 'user_id' => 1, 'supplier_id' => 10, 'establishment_id' => 1, 'state_type_id' => '11', 'document_type_id' => '01', 'series' => 'F001', 'number' => '2', 'date_of_issue' => '2026-11-01', 'date_of_due' => '2026-12-01', 'total' => 1000.00, 'currency_type_id' => 'PEN', 'exchange_rate_sale' => 3.75],
        ]);

        $feeService = new PurchaseFeeService();

        // Save 2 installments (returned sorted by date ASC, inheriting purchase currency USD)
        $saved = $feeService->replaceFees(1, [
            ['date' => '2026-12-15', 'amount' => 600.00],
            ['date' => '2026-11-25', 'amount' => 400.00],
        ]);
        $this->assertCount(2, $saved);
        $this->assertSame('2026-11-25', $saved[0]['date']);
        $this->assertSame('USD', $saved[0]['currency_type_id']);
        $this->assertSame(400.0, $saved[0]['amount']);

        // Replacing overwrites previous rows cleanly
        $replaced = $feeService->replaceFees(1, [
            ['date' => '2026-11-30', 'amount' => 880.00],
        ]);
        $this->assertCount(1, $replaced);
        $this->assertSame(1, PurchaseFee::where('purchase_id', 1)->count());

        // Rejects sum > purchase.total with 422 ValidationException
        try {
            $feeService->replaceFees(1, [
                ['date' => '2026-11-30', 'amount' => 600.00],
                ['date' => '2026-12-30', 'amount' => 500.00],
            ]);
            $this->fail('Expected ValidationException when installment sum exceeds purchase total');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('fees', $e->errors());
        }

        // Rejects voided purchase
        try {
            $feeService->replaceFees(2, [
                ['date' => '2026-11-30', 'amount' => 500.00],
            ]);
            $this->fail('Expected ValidationException for voided purchase');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('purchase_id', $e->errors());
        }

        // Delete manual fees
        $feeService->deleteFees(1);
        $this->assertSame(0, PurchaseFee::where('purchase_id', 1)->count());

        // deleteFees() rejects voided purchase
        try {
            $feeService->deleteFees(2);
            $this->fail('Expected ValidationException when deleting fees on voided purchase');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('purchase_id', $e->errors());
        }

        // Missing purchase_fees table guard on both replaceFees() and deleteFees()
        $migration->down();
        try {
            $feeService->replaceFees(1, [['date' => '2026-11-30', 'amount' => 100.00]]);
            $this->fail('Expected ValidationException when purchase_fees table is missing');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('fees', $e->errors());
        }
        try {
            $feeService->deleteFees(1);
            $this->fail('Expected ValidationException when purchase_fees table is missing');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('fees', $e->errors());
        }
        $migration->up();
    }

    public function test_aging_reconciles_with_balance_on_detraction_invoices_overpayment_and_missing_exchange_rates(): void
    {
        // 1. Detraction invoice: total = 1000 PEN, detraction = 120, fees = [440 @ Oct 31, 440 @ Nov 30]
        $this->db->table('documents')->insert([
            'id' => 30,
            'user_id' => 1,
            'customer_id' => 1,
            'establishment_id' => 1,
            'state_type_id' => '05',
            'document_type_id' => '01',
            'series' => 'F001',
            'number' => '300',
            'date_of_issue' => '2026-10-01',
            'total' => 1000.00,
            'currency_type_id' => 'PEN',
            'exchange_rate_sale' => 3.75,
            'detraction' => json_encode(['detraction_type_id' => '022', 'percentage' => 12, 'amount' => 120.00]),
        ]);
        $this->db->table('invoices')->insert([
            ['id' => 30, 'document_id' => 30, 'date_of_due' => '2026-11-30'],
        ]);
        $this->db->table('document_fee')->insert([
            ['id' => 30, 'document_id' => 30, 'date' => '2026-10-31', 'currency_type_id' => 'PEN', 'amount' => 440.00],
            ['id' => 31, 'document_id' => 30, 'date' => '2026-11-30', 'currency_type_id' => 'PEN', 'amount' => 440.00],
        ]);

        $service = $this->makeService();

        // Unpaid: synthetic detraction_retention residual (120) makes installment pending sum === balance === aging total (1000)
        $arUnpaid = $service->getReceivables([], '2026-11-20');
        $rowUnpaid = $arUnpaid['rows'][0];
        $this->assertSame(1000.0, $rowUnpaid['balance']);
        $this->assertCount(3, $rowUnpaid['installments']);
        $this->assertSame('detraction_retention', $rowUnpaid['installments'][2]['source']);
        $this->assertSame(120.0, $rowUnpaid['installments'][2]['pending_amount']);
        $this->assertSame(
            $rowUnpaid['balance'],
            round(array_sum(array_column($rowUnpaid['installments'], 'pending_amount')), 2)
        );
        $agingUnpaid = $service->getAging('receivables', [], '2026-11-20');
        $this->assertSame(1000.0, $agingUnpaid['rows'][0]['total']);
        $this->assertSame(440.0, $agingUnpaid['rows'][0]['days_1_30']);
        $this->assertSame(560.0, $agingUnpaid['rows'][0]['current']);

        // Partially paid === sum(document_fee) = 880: both credit fees paid, 120 detraction residual remains in aging!
        $this->db->table('document_payments')->insert([
            ['id' => 30, 'document_id' => 30, 'payment' => 880.00, 'date_of_payment' => '2026-11-15'],
        ]);
        $arPartial = $service->getReceivables([], '2026-11-20');
        $rowPartial = $arPartial['rows'][0];
        $this->assertSame(120.0, $rowPartial['balance']);
        $this->assertSame('paid', $rowPartial['installments'][0]['status']);
        $this->assertSame('paid', $rowPartial['installments'][1]['status']);
        $this->assertSame(120.0, $rowPartial['installments'][2]['pending_amount']);
        $agingPartial = $service->getAging('receivables', [], '2026-11-20');
        $this->assertSame(120.0, $agingPartial['rows'][0]['total']);
        $this->assertSame(120.0, $agingPartial['rows'][0]['current']);

        // 2. Pure FIFO overpayment clamps paid_amount to installment amount with zero negative pending
        $overpaid = $service->allocateInstallmentsFifo([
            ['id' => 1, 'date' => '2026-11-01', 'amount' => 100.00, 'source' => 'document_fee'],
            ['id' => 2, 'date' => '2026-12-01', 'amount' => 100.00, 'source' => 'document_fee'],
        ], 350.00, '2026-11-20');
        $this->assertSame(100.0, $overpaid[0]['paid_amount']);
        $this->assertSame(0.0, $overpaid[0]['pending_amount']);
        $this->assertSame('paid', $overpaid[0]['status']);
        $this->assertSame(100.0, $overpaid[1]['paid_amount']);
        $this->assertSame(0.0, $overpaid[1]['pending_amount']);
        $this->assertSame('paid', $overpaid[1]['status']);

        // 3. Missing exchange rate: USD with 0 rate and empty exchange_rates + EUR must NOT inherit USD fallback
        $this->db->table('documents')->insert([
            [
                'id' => 31,
                'user_id' => 1,
                'customer_id' => 2,
                'establishment_id' => 1,
                'state_type_id' => '05',
                'document_type_id' => '01',
                'series' => 'F001',
                'number' => '301',
                'date_of_issue' => '2026-11-18',
                'total' => 200.00,
                'currency_type_id' => 'EUR',
                'exchange_rate_sale' => 0,
                'detraction' => null,
            ],
        ]);
        // Even with USD exchange_rates row present, EUR with 0 doc rate must stay missing_exchange_rate = true
        $arEur = $service->getReceivables(['currency_type_id' => 'EUR'], '2026-11-20');
        $this->assertNull($arEur['rows'][0]['balance_pen']);
        $this->assertTrue($arEur['totals']['missing_exchange_rate']);

        // Now clear exchange_rates and insert a USD doc with exchange_rate_sale = 0 -> missing_exchange_rate = true
        $this->db->table('exchange_rates')->delete();
        $this->db->table('documents')->insert([
            [
                'id' => 32,
                'user_id' => 1,
                'customer_id' => 2,
                'establishment_id' => 1,
                'state_type_id' => '05',
                'document_type_id' => '01',
                'series' => 'F001',
                'number' => '302',
                'date_of_issue' => '2026-11-18',
                'total' => 100.00,
                'currency_type_id' => 'USD',
                'exchange_rate_sale' => 0,
                'detraction' => null,
            ],
        ]);
        $this->db->table('invoices')->insert([
            ['id' => 32, 'document_id' => 32, 'date_of_due' => '2026-11-24'], // +4 days -> min_days_until_due = 4
        ]);
        $arUsdNoRate = $service->getReceivables(['currency_type_id' => 'USD'], '2026-11-20');
        $this->assertNull($arUsdNoRate['rows'][0]['balance_pen']);
        $this->assertTrue($arUsdNoRate['totals']['missing_exchange_rate']);
        $this->assertSame(4, $arUsdNoRate['rows'][0]['min_days_until_due']);

        $agingUsdNoRate = $service->getAging('receivables', ['currency_type_id' => 'USD'], '2026-11-20');
        $this->assertTrue($agingUsdNoRate['missing_exchange_rate']);
        $this->assertNull($agingUsdNoRate['rows'][0]['total_pen']);
    }
}
