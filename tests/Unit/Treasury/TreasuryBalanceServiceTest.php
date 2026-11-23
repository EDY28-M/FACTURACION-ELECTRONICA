<?php

namespace Tests\Unit\Treasury;

use App\Http\Controllers\Tenant\BankAccountController;
use App\Http\Requests\Tenant\BankAccountRequest;
use App\Models\Tenant\BankAccount;
use App\Models\Tenant\Cash;
use App\Models\Tenant\Document;
use App\Models\Tenant\DocumentPayment;
use App\Models\Tenant\Establishment;
use App\Models\Tenant\Purchase;
use App\Models\Tenant\PurchasePayment;
use App\Models\Tenant\SaleNote;
use App\Models\Tenant\SaleNotePayment;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\DatabasePresenceVerifier;
use Illuminate\Validation\Factory as ValidationFactory;
use Modules\Finance\Models\GlobalPayment;
use Modules\Finance\Traits\FinanceTrait;
use Modules\Pos\Models\CashTransaction;
use Modules\Treasury\Models\TreasuryConcept;
use Modules\Treasury\Models\TreasuryMovement;
use Modules\Treasury\Models\TreasuryTaxRate;
use Modules\Treasury\Services\TreasuryBalanceService;
use PHPUnit\Framework\TestCase;

/**
 * Phase 1 unit tests for TreasuryBalanceService, FinanceTrait parity,
 * idempotent migrations (up & down), regulatory tax rate seeds, filters,
 * missing exchange rate handling, and BankAccountController field preservation.
 */
class TreasuryBalanceServiceTest extends TestCase
{
    private $previousContainer;
    private $previousFacade;
    private $previousResolver;
    private $previousDispatcher;
    private $previousScopes;
    private $previousBooted;
    private $app;
    private $databaseManager;
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

        $manager = new Manager($this->app);
        $manager->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''], 'tenant');
        $this->databaseManager = $manager->getDatabaseManager();
        $this->databaseManager->setDefaultConnection('tenant');
        $this->app->instance('db', $this->databaseManager);
        $this->app->bind('db.schema', fn () => $this->databaseManager->connection('tenant')->getSchemaBuilder());

        Model::setConnectionResolver($this->databaseManager);
        Model::setEventDispatcher(new Dispatcher($this->app));

        $this->db = $this->databaseManager->connection('tenant');

        foreach ([
            Document::class,
            DocumentPayment::class,
            SaleNote::class,
            SaleNotePayment::class,
            Purchase::class,
            PurchasePayment::class,
            Establishment::class,
        ] as $model) {
            $model::addGlobalScope('treasury-balance-fixture', function ($query) {
                $query->setEagerLoads([]);
            });
        }

        // Base existing tenant tables required by migrations and balance queries.
        foreach ([
            'modules (id integer primary key, value text, description text, order_menu integer null, sort integer null)',
            'establishments (id integer primary key, description text)',
            'users (id integer primary key, name text, type text, establishment_id integer null)',
            'banks (id integer primary key, description text)',
            'bank_accounts (id integer primary key autoincrement, bank_id integer, description text, number text, currency_type_id text, cci text null, status integer default 1, initial_balance numeric default 0, show_in_documents integer default 0)',
            'payment_method_types (id text primary key, description text, has_card integer default 0, charge numeric null, number_days integer null)',
            'companies (id integer primary key, name text)',
            'exchange_rates (id integer primary key, date text, purchase numeric, sale numeric)',
            'cash (id integer primary key, user_id integer, date_opening text, time_opening text, date_closed text null, time_closed text null, beginning_balance numeric, final_balance numeric, income numeric, state integer, reference_number text null, created_at text null, updated_at text null)',
            'documents (id integer primary key, state_type_id text, currency_type_id text, exchange_rate_sale numeric, total numeric, created_at text null, updated_at text null)',
            'notes (id integer primary key, document_id integer, affected_document_id integer, note_type text)',
            'document_payments (id integer primary key, document_id integer, date_of_payment text, payment_method_type_id text, has_card integer default 0, payment numeric)',
            'sale_notes (id integer primary key, state_type_id text, currency_type_id text, exchange_rate_sale numeric, total numeric, changed integer default 0, created_at text null, updated_at text null)',
            'sale_note_payments (id integer primary key, sale_note_id integer, date_of_payment text, payment_method_type_id text, has_card integer default 0, payment numeric)',
            'purchases (id integer primary key, state_type_id text, currency_type_id text, exchange_rate_sale numeric, total numeric, created_at text null, updated_at text null)',
            'purchase_payments (id integer primary key, purchase_id integer, date_of_payment text, payment_method_type_id text, has_card integer default 0, payment numeric)',
            'cash_transactions (id integer primary key, cash_id integer, payment_method_type_id text, date text, description text, payment numeric)',
            'global_payments (id integer primary key autoincrement, soap_type_id text null, destination_id integer, destination_type text, payment_id integer, payment_type text, user_id integer null, created_at text null, updated_at text null)',
        ] as $table) {
            $this->db->statement('create table ' . $table);
        }

        $this->db->table('payment_method_types')->insert([
            ['id' => '01', 'description' => 'Efectivo'],
            ['id' => '03', 'description' => 'Tarjeta de débito'],
            ['id' => '04', 'description' => 'Transferencia'],
        ]);

        $this->runPhase1Migrations();
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

    private function runPhase1Migrations(): void
    {
        require_once __DIR__ . '/../../../database/migrations/2026_11_23_000001_add_data_module_for_treasury.php';
        require_once __DIR__ . '/../../../database/migrations/tenant/2026_11_23_000001_tenant_add_data_module_for_treasury.php';
        require_once __DIR__ . '/../../../database/migrations/tenant/2026_11_23_000002_tenant_add_treasury_columns_to_existing_tables.php';
        require_once __DIR__ . '/../../../database/migrations/tenant/2026_11_23_000003_tenant_create_treasury_core_tables.php';

        (new \TenantAddDataModuleForTreasury())->up();
        (new \TenantAddTreasuryColumnsToExistingTables())->up();
        (new \TenantCreateTreasuryCoreTables())->up();
    }

    public function test_phase1_migrations_are_idempotent_seed_rates_and_reverse_cleanly(): void
    {
        // Running up() a second time must not fail or duplicate rows.
        $this->runPhase1Migrations();

        $this->assertSame(1, $this->db->table('modules')->where('id', 19)->where('value', 'treasury')->count());
        $this->assertSame(16, (int) $this->db->table('modules')->where('id', 19)->value('order_menu'));
        $this->assertSame('008', $this->db->table('payment_method_types')->where('id', '01')->value('sunat_code'));
        $this->assertSame(1, (int) $this->db->table('payment_method_types')->where('id', '01')->value('is_cash'));
        $this->assertSame('003', $this->db->table('payment_method_types')->where('id', '04')->value('sunat_code'));

        $this->assertSame(9, TreasuryConcept::count());

        $retention = TreasuryTaxRate::where('tax', 'retention_igv')->effectiveOn('2026-11-23')->first();
        $this->assertNotNull($retention);
        $this->assertEqualsWithDelta(0.03, $retention->rate, 0.00001);
        $this->assertEqualsWithDelta(700.0, $retention->min_operation, 0.01);

        $itf = TreasuryTaxRate::where('tax', 'itf')->effectiveOn('2026-11-23')->first();
        $this->assertEqualsWithDelta(0.00005, $itf->rate, 0.000001);

        $penThreshold = TreasuryTaxRate::where('tax', 'bancarization_threshold_pen')->effectiveOn('2026-11-23')->first();
        $usdThreshold = TreasuryTaxRate::where('tax', 'bancarization_threshold_usd')->effectiveOn('2026-11-23')->first();
        $this->assertEqualsWithDelta(2000.0, $penThreshold->amount, 0.01);
        $this->assertEqualsWithDelta(500.0, $usdThreshold->amount, 0.01);

        $detractionServices = TreasuryTaxRate::where('tax', 'detraction')->where('code', '037')->effectiveOn('2026-11-23')->first();
        $this->assertEqualsWithDelta(0.12, $detractionServices->rate, 0.00001);
        $this->assertStringContainsString('REVISAR', $detractionServices->legal_reference);

        // Verify payment_evidences.operation_number is nullable (required for Ley 28194 art. 6 cash exceptions).
        $this->db->table('payment_evidences')->insert([
            'payable_type' => DocumentPayment::class,
            'payable_id' => 1,
            'sunat_payment_code' => '009',
            'operation_number' => null,
            'operation_date' => '2026-11-23',
            'bancarization_exception' => 'no_bank_district',
        ]);
        $this->assertSame(1, $this->db->table('payment_evidences')->count());

        // Verify down() cleanly drops Phase 1 tables, additive columns, and module row.
        (new \TenantCreateTreasuryCoreTables())->down();
        $this->assertFalse($this->db->getSchemaBuilder()->hasTable('treasury_movements'));
        $this->assertFalse($this->db->getSchemaBuilder()->hasTable('payment_evidences'));
        $this->assertFalse($this->db->getSchemaBuilder()->hasTable('treasury_tax_rates'));
        $this->assertFalse($this->db->getSchemaBuilder()->hasTable('treasury_concepts'));

        (new \TenantAddTreasuryColumnsToExistingTables())->down();
        $this->assertFalse($this->db->getSchemaBuilder()->hasColumn('bank_accounts', 'account_type'));
        $this->assertFalse($this->db->getSchemaBuilder()->hasColumn('payment_method_types', 'sunat_code'));
        $this->assertFalse($this->db->getSchemaBuilder()->hasColumn('companies', 'is_retention_agent'));

        (new \TenantAddDataModuleForTreasury())->down();
        $this->assertSame(0, $this->db->table('modules')->where('value', 'treasury')->count());

        // Test central AddDataModuleForTreasury up() & down() as well.
        (new \AddDataModuleForTreasury())->up();
        $this->assertSame(1, $this->db->table('modules')->where('id', 19)->where('value', 'treasury')->count());
        $this->assertSame(16, (int) $this->db->table('modules')->where('id', 19)->value('sort'));
        (new \AddDataModuleForTreasury())->down();
        $this->assertSame(0, $this->db->table('modules')->where('value', 'treasury')->count());
    }

    private function seedBalanceFixtures(): void
    {
        $this->db->table('establishments')->insert([
            ['id' => 1, 'description' => 'Oficina Principal'],
            ['id' => 2, 'description' => 'Sucursal Norte'],
        ]);
        $this->db->table('users')->insert([
            ['id' => 1, 'name' => 'Admin Lima', 'type' => 'admin', 'establishment_id' => 1],
            ['id' => 2, 'name' => 'Cajero Norte', 'type' => 'seller', 'establishment_id' => 2],
        ]);
        $this->db->table('banks')->insert([
            ['id' => 1, 'description' => 'BCP'],
            ['id' => 2, 'description' => 'Banco de la Nación'],
        ]);
        $this->db->table('exchange_rates')->insert([
            ['id' => 1, 'date' => '2026-11-23', 'purchase' => 3.70, 'sale' => 3.75],
        ]);
        $this->db->table('bank_accounts')->insert([
            [
                'id' => 1,
                'bank_id' => 1,
                'description' => 'Cuenta Corriente Soles',
                'number' => '191-0001',
                'currency_type_id' => 'PEN',
                'initial_balance' => 1000.00,
                'account_type' => 'checking',
                'pcge_account' => '1041',
                'itf_exempt' => 0,
                'establishment_id' => 1,
            ],
            [
                'id' => 2,
                'bank_id' => 1,
                'description' => 'Cuenta Ahorros Dolares',
                'number' => '191-0002',
                'currency_type_id' => 'USD',
                'initial_balance' => 500.00,
                'account_type' => 'savings',
                'pcge_account' => '1042',
                'itf_exempt' => 0,
                'establishment_id' => 1,
            ],
            [
                'id' => 3,
                'bank_id' => 2,
                'description' => 'Detracciones BN',
                'number' => '00-000-123456',
                'currency_type_id' => 'PEN',
                'initial_balance' => 250.00,
                'account_type' => 'detraction_bn',
                'pcge_account' => '1071',
                'itf_exempt' => 1,
                'establishment_id' => 1,
            ],
            [
                'id' => 4,
                'bank_id' => 1,
                'description' => 'Cuenta Sobregirada',
                'number' => '191-0004',
                'currency_type_id' => 'PEN',
                'initial_balance' => 10.00,
                'account_type' => 'checking',
                'pcge_account' => '1041',
                'itf_exempt' => 0,
                'establishment_id' => 2,
            ],
        ]);

        // Cash #1 open (est 1), Cash #2 closed (est 1), Cash #3 open (est 2)
        $this->db->table('cash')->insert([
            [
                'id' => 1,
                'user_id' => 1,
                'date_opening' => '2026-11-23',
                'time_opening' => '08:00:00',
                'beginning_balance' => 100.00,
                'final_balance' => 0,
                'income' => 0,
                'state' => 1,
                'reference_number' => 'CAJA-01',
            ],
            [
                'id' => 2,
                'user_id' => 1,
                'date_opening' => '2026-11-20',
                'time_opening' => '08:00:00',
                'beginning_balance' => 50.00,
                'final_balance' => 50.00,
                'income' => 0,
                'state' => 0,
                'reference_number' => 'CAJA-OLD',
            ],
            [
                'id' => 3,
                'user_id' => 2,
                'date_opening' => '2026-11-23',
                'time_opening' => '09:00:00',
                'beginning_balance' => 60.00,
                'final_balance' => 0,
                'income' => 0,
                'state' => 1,
                'reference_number' => 'CAJA-NORTE',
            ],
        ]);

        // Documents, credit notes, and payments
        $this->db->table('documents')->insert([
            ['id' => 1, 'state_type_id' => '05', 'currency_type_id' => 'PEN', 'exchange_rate_sale' => 3.75, 'total' => 400.00],
            ['id' => 2, 'state_type_id' => '05', 'currency_type_id' => 'USD', 'exchange_rate_sale' => 3.75, 'total' => 100.00],
            ['id' => 3, 'state_type_id' => '11', 'currency_type_id' => 'PEN', 'exchange_rate_sale' => 3.75, 'total' => 999.00], // voided -> ignored
            ['id' => 4, 'state_type_id' => '05', 'currency_type_id' => 'PEN', 'exchange_rate_sale' => 3.75, 'total' => 50.00],  // credit note affecting doc #1
        ]);
        $this->db->table('notes')->insert([
            ['id' => 1, 'document_id' => 4, 'affected_document_id' => 1, 'note_type' => 'credit'],
        ]);
        // Two payments against doc #1 (250 + 150 = 400) -> credit note (50) MUST be deducted only ONCE -> net 350!
        $this->db->table('document_payments')->insert([
            ['id' => 1, 'document_id' => 1, 'date_of_payment' => '2026-11-23', 'payment_method_type_id' => '04', 'payment' => 250.00],
            ['id' => 2, 'document_id' => 1, 'date_of_payment' => '2026-11-23', 'payment_method_type_id' => '04', 'payment' => 150.00],
            ['id' => 3, 'document_id' => 2, 'date_of_payment' => '2026-11-23', 'payment_method_type_id' => '04', 'payment' => 100.00],
            ['id' => 4, 'document_id' => 3, 'date_of_payment' => '2026-11-23', 'payment_method_type_id' => '04', 'payment' => 999.00],
        ]);

        // Sale notes: #1 active (+30), #2 changed=1 (ignored!)
        $this->db->table('sale_notes')->insert([
            ['id' => 1, 'state_type_id' => '05', 'currency_type_id' => 'PEN', 'exchange_rate_sale' => 1.00, 'total' => 30.00, 'changed' => 0],
            ['id' => 2, 'state_type_id' => '05', 'currency_type_id' => 'PEN', 'exchange_rate_sale' => 1.00, 'total' => 200.00, 'changed' => 1],
        ]);
        $this->db->table('sale_note_payments')->insert([
            ['id' => 1, 'sale_note_id' => 1, 'date_of_payment' => '2026-11-23', 'payment_method_type_id' => '01', 'payment' => 30.00],
            ['id' => 2, 'sale_note_id' => 2, 'date_of_payment' => '2026-11-23', 'payment_method_type_id' => '01', 'payment' => 200.00],
        ]);

        // Purchases & payments
        $this->db->table('purchases')->insert([
            ['id' => 1, 'state_type_id' => '05', 'currency_type_id' => 'PEN', 'exchange_rate_sale' => 3.75, 'total' => 150.00],
            ['id' => 2, 'state_type_id' => '05', 'currency_type_id' => 'PEN', 'exchange_rate_sale' => 3.75, 'total' => 80.00],
        ]);
        $this->db->table('purchase_payments')->insert([
            ['id' => 1, 'purchase_id' => 1, 'date_of_payment' => '2026-11-23', 'payment_method_type_id' => '04', 'payment' => 150.00],
            ['id' => 2, 'purchase_id' => 2, 'date_of_payment' => '2026-11-23', 'payment_method_type_id' => '04', 'payment' => 80.00],
        ]);

        // Cash opening transactions
        $this->db->table('cash_transactions')->insert([
            ['id' => 1, 'cash_id' => 1, 'payment_method_type_id' => '01', 'date' => '2026-11-23', 'description' => 'Saldo inicial', 'payment' => 100.00],
            ['id' => 2, 'cash_id' => 2, 'payment_method_type_id' => '01', 'date' => '2026-11-20', 'description' => 'Caja cerrada', 'payment' => 50.00],
            ['id' => 3, 'cash_id' => 3, 'payment_method_type_id' => '01', 'date' => '2026-11-23', 'description' => 'Saldo Norte', 'payment' => 60.00],
        ]);

        // Global payments linking payments to destinations
        $this->db->table('global_payments')->insert([
            ['destination_id' => 1, 'destination_type' => BankAccount::class, 'payment_id' => 1, 'payment_type' => DocumentPayment::class, 'user_id' => 1],
            ['destination_id' => 1, 'destination_type' => BankAccount::class, 'payment_id' => 2, 'payment_type' => DocumentPayment::class, 'user_id' => 1],
            ['destination_id' => 1, 'destination_type' => BankAccount::class, 'payment_id' => 4, 'payment_type' => DocumentPayment::class, 'user_id' => 1], // voided doc
            ['destination_id' => 1, 'destination_type' => BankAccount::class, 'payment_id' => 1, 'payment_type' => PurchasePayment::class, 'user_id' => 1],
            ['destination_id' => 2, 'destination_type' => BankAccount::class, 'payment_id' => 3, 'payment_type' => DocumentPayment::class, 'user_id' => 1],
            ['destination_id' => 4, 'destination_type' => BankAccount::class, 'payment_id' => 2, 'payment_type' => PurchasePayment::class, 'user_id' => 2], // 10 - 80 = -70
            ['destination_id' => 1, 'destination_type' => Cash::class, 'payment_id' => 1, 'payment_type' => CashTransaction::class, 'user_id' => 1],
            ['destination_id' => 1, 'destination_type' => Cash::class, 'payment_id' => 1, 'payment_type' => SaleNotePayment::class, 'user_id' => 1],
            ['destination_id' => 1, 'destination_type' => Cash::class, 'payment_id' => 2, 'payment_type' => SaleNotePayment::class, 'user_id' => 1], // changed=1 -> ignored
            ['destination_id' => 2, 'destination_type' => Cash::class, 'payment_id' => 2, 'payment_type' => CashTransaction::class, 'user_id' => 1],
            ['destination_id' => 3, 'destination_type' => Cash::class, 'payment_id' => 3, 'payment_type' => CashTransaction::class, 'user_id' => 2],
        ]);
    }

    public function test_summary_balances_match_finance_trait_with_credit_notes_and_period_filtered_movements(): void
    {
        $this->seedBalanceFixtures();

        $service = new TreasuryBalanceService();
        $finance = new class {
            use FinanceTrait;
        };

        $summary = $service->getSummary();
        $accountsById = collect($summary['bank_accounts'])->keyBy('id');

        // Account #1 (PEN): 1000 + (250 + 150 - 50 credit note once) - 150 purchase = 1200.00
        $this->assertSame('1200.00', $accountsById[1]['balance_currency']);
        $this->assertSame('1200.00', $accountsById[1]['balance_pen']);
        $this->assertSame('1200.00', $accountsById[1]['finance_balance']);

        // Account #2 (USD): 500 + 100 = 600.00 USD; PEN at compra 3.70 = 2220.00; legacy finance = 500 + 375 = 875.00
        $this->assertSame('600.00', $accountsById[2]['balance_currency']);
        $this->assertSame('2220.00', $accountsById[2]['balance_pen']);
        $this->assertSame('875.00', $accountsById[2]['finance_balance']);
        $this->assertFalse($summary['totals']['missing_exchange_rate']);

        // Account #3 (Detraction BN): 250.00
        $this->assertSame('250.00', $summary['totals']['detraction_bn_balance']);
        $this->assertSame(1, $summary['totals']['detraction_bn_count']);

        // Account #4 (Overdrawn PEN): -70.00 -> alert
        $this->assertSame('-70.00', $accountsById[4]['balance_currency']);
        $this->assertTrue($accountsById[4]['is_negative']);
        $this->assertCount(1, $summary['alerts']['negative_accounts']);

        // Cash general: Cash #1 (100 + 30 sale_note, changed=1 ignored) + Cash #2 (50) + Cash #3 (60) = 240.00
        // Open cashes: Cash #1 (130.00) and Cash #3 (60.00); closed Cash #2 not in open_cashes
        $this->assertSame('240.00', $summary['cash_general']['balance']);
        $this->assertSame(2, $summary['totals']['open_cash_count']);

        // Consolidated PEN = 1200 + 2220 + 250 - 70 + 240 = 3840.00
        $this->assertSame('3840.00', $summary['totals']['consolidated_pen']);

        // Add period-distinct treasury_movements (Oct vs Nov, PEN + USD + Cash + voided)
        TreasuryMovement::create([
            'establishment_id' => 1,
            'date' => '2026-10-15', // outside Nov window
            'kind' => 'income',
            'direction' => TreasuryMovement::DIRECTION_INFLOW,
            'destination_type' => BankAccount::class,
            'destination_id' => 1,
            'currency_type_id' => 'PEN',
            'amount' => 100.00,
            'amount_pen' => 100.00,
            'description' => 'October deposit',
            'state' => TreasuryMovement::STATE_ACTIVE,
            'user_id' => 1,
        ]);
        TreasuryMovement::create([
            'establishment_id' => 1,
            'date' => '2026-11-23',
            'kind' => 'income',
            'direction' => TreasuryMovement::DIRECTION_INFLOW,
            'destination_type' => BankAccount::class,
            'destination_id' => 1,
            'currency_type_id' => 'PEN',
            'amount' => 300.00,
            'amount_pen' => 300.00,
            'description' => 'November deposit',
            'state' => TreasuryMovement::STATE_ACTIVE,
            'user_id' => 1,
        ]);
        TreasuryMovement::create([
            'establishment_id' => 1,
            'date' => '2026-11-23',
            'kind' => 'expense',
            'direction' => TreasuryMovement::DIRECTION_OUTFLOW,
            'destination_type' => BankAccount::class,
            'destination_id' => 2,
            'currency_type_id' => 'USD',
            'amount' => 50.00,
            'exchange_rate_purchase' => 3.70,
            'exchange_rate_sale' => 3.75,
            'exchange_rate_used' => 3.70,
            'amount_pen' => 185.00,
            'description' => 'USD wire fee',
            'state' => TreasuryMovement::STATE_ACTIVE,
            'user_id' => 1,
        ]);
        TreasuryMovement::create([
            'establishment_id' => 1,
            'date' => '2026-11-23',
            'kind' => 'expense',
            'direction' => TreasuryMovement::DIRECTION_OUTFLOW,
            'destination_type' => Cash::class,
            'destination_id' => 1,
            'currency_type_id' => 'PEN',
            'amount' => 20.00,
            'amount_pen' => 20.00,
            'description' => 'Cash withdrawal',
            'state' => TreasuryMovement::STATE_ACTIVE,
            'user_id' => 1,
        ]);
        TreasuryMovement::create([
            'establishment_id' => 1,
            'date' => '2026-11-23',
            'kind' => 'expense',
            'direction' => TreasuryMovement::DIRECTION_OUTFLOW,
            'destination_type' => BankAccount::class,
            'destination_id' => 1,
            'currency_type_id' => 'PEN',
            'amount' => 999.00,
            'amount_pen' => 999.00,
            'description' => 'Voided movement',
            'state' => TreasuryMovement::STATE_VOIDED,
            'user_id' => 1,
        ]);

        // Period-filtered parity check (Nov 2026 excludes Oct movement of 100.00 in BOTH sources!)
        $novSummary = $service->getSummary(['date_start' => '2026-11-01', 'date_end' => '2026-11-30']);
        $novAccounts = collect($novSummary['bank_accounts'])->keyBy('id');

        // Account #1 in Nov: 1200 + 300 = 1500.00 (Oct +100 and voided -999 excluded)
        $this->assertSame('1500.00', $novAccounts[1]['balance_pen']);
        // Account #2 USD in Nov: 600 - 50 = 550.00 USD -> 550 * 3.70 = 2035.00 PEN
        $this->assertSame('550.00', $novAccounts[2]['balance_currency']);
        $this->assertSame('2035.00', $novAccounts[2]['balance_pen']);
        // Cash in Nov: 240 - 20 = 220.00
        $this->assertSame('220.00', $novSummary['cash_general']['balance']);

        // Compare directly against FinanceTrait with the same Nov period bounds
        $bank1Model = BankAccount::with('bank')->findOrFail(1);
        $bank1Globals = GlobalPayment::with('payment')
            ->where('destination_type', BankAccount::class)
            ->where('destination_id', 1)
            ->where('payment_id', '!=', 4)
            ->get();
        $bank1Proxy = (object) [
            'id' => 1,
            'currency_type_id' => 'PEN',
            'description' => $bank1Model->description,
            'initial_balance' => (float) $bank1Model->initial_balance,
            'bank' => $bank1Model->bank,
            'global_destination' => $service->wrapGlobalPayments($bank1Globals),
        ];
        $financeBank1Nov = $finance->getBalanceByBankAcounts(collect([$bank1Proxy]), '2026-11-01', '2026-11-30')->first();
        $this->assertSame($financeBank1Nov['balance'], $novAccounts[1]['balance_pen']);

        // Establishment filter scopes BOTH bank accounts AND cash boxes by establishment_id
        $est2Summary = $service->getSummary(['establishment_id' => 2]);
        $this->assertSame([4], collect($est2Summary['bank_accounts'])->pluck('id')->all());
        $this->assertSame([3], collect($est2Summary['open_cashes'])->pluck('id')->all());
        $this->assertSame('60.00', $est2Summary['cash_general']['balance']);

        // Currency filter = USD omits PEN accounts and Cash
        $usdSummary = $service->getSummary(['currency_type_id' => 'USD']);
        $this->assertSame([2], collect($usdSummary['bank_accounts'])->pluck('id')->all());
        $this->assertNull($usdSummary['cash_general']);
        $this->assertSame([], $usdSummary['open_cashes']);
    }

    public function test_missing_exchange_rate_does_not_silently_convert_usd_one_to_one(): void
    {
        $this->seedBalanceFixtures();
        $this->db->table('exchange_rates')->delete();

        $summary = (new TreasuryBalanceService())->getSummary(['currency_type_id' => 'USD']);
        $usdAccount = $summary['bank_accounts'][0];

        $this->assertSame('600.00', $usdAccount['balance_currency']);
        $this->assertNull($usdAccount['balance_pen']);
        $this->assertTrue($summary['totals']['missing_exchange_rate']);
        $this->assertTrue($summary['alerts']['missing_exchange_rate']);
        // Unconverted USD is NOT added 1:1 into consolidated_pen!
        $this->assertSame('0.00', $summary['totals']['consolidated_pen']);
        $this->assertSame('0.00', $summary['totals']['banks_usd_in_pen']);
    }

    public function test_bank_account_request_and_store_preserve_existing_treasury_fields_and_validate_establishment(): void
    {
        $this->db->table('establishments')->insert(['id' => 1, 'description' => 'Principal']);
        $this->db->table('banks')->insert(['id' => 1, 'description' => 'Banco de la Nación']);

        $validatorFactory = new ValidationFactory(new Translator(new ArrayLoader(), 'en'), $this->app);
        $validatorFactory->setPresenceVerifier(new DatabasePresenceVerifier($this->databaseManager));

        $requestModel = new BankAccountRequest();
        $rules = $requestModel->rules();

        // Rejects non-existent establishment_id
        $invalid = $validatorFactory->make([
            'bank_id' => 1,
            'description' => 'Detracciones',
            'number' => '00-000-111',
            'currency_type_id' => 'PEN',
            'initial_balance' => 100,
            'show_in_documents' => false,
            'establishment_id' => 999,
        ], $rules);
        $this->assertTrue($invalid->fails());
        $this->assertTrue($invalid->errors()->has('establishment_id'));

        // Creating a detraction_bn account defaults itf_exempt = true
        $createPayload = [
            'bank_id' => 1,
            'description' => 'Detracciones BN',
            'number' => '00-000-111',
            'currency_type_id' => 'PEN',
            'initial_balance' => 100,
            'show_in_documents' => false,
            'account_type' => 'detraction_bn',
            'pcge_account' => '1071',
            'establishment_id' => 1,
        ];
        $createReq = BankAccountRequest::create('/treasury/accounts', 'POST', $createPayload);
        $createReq->setValidator($validatorFactory->make($createPayload, $rules));

        $controller = new BankAccountController();
        $res = $controller->store($createReq);
        $this->assertTrue($res['success']);

        $created = BankAccount::firstOrFail();
        $this->assertSame('detraction_bn', $created->account_type);
        $this->assertTrue($created->itf_exempt);
        $this->assertSame('1071', $created->pcge_account);

        // Partial update omitting account_type/itf_exempt MUST NOT clobber them, and cannot mass-assign status=0
        $updatePayload = [
            'id' => $created->id,
            'bank_id' => 1,
            'description' => 'Detracciones BN Actualizada',
            'number' => '00-000-111',
            'currency_type_id' => 'PEN',
            'initial_balance' => 150,
            'show_in_documents' => true,
            'status' => 0,
        ];
        $updateReq = BankAccountRequest::create('/treasury/accounts', 'POST', $updatePayload);
        $updateReq->setValidator($validatorFactory->make($updatePayload, $rules));
        $controller->store($updateReq);

        $updated = BankAccount::findOrFail($created->id);
        $this->assertSame('Detracciones BN Actualizada', $updated->description);
        $this->assertSame('detraction_bn', $updated->account_type);
        $this->assertTrue($updated->itf_exempt);
        $this->assertSame(1, (int) $updated->status);
    }
}
