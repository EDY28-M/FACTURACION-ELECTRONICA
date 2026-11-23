<?php

namespace Tests\Unit\Treasury;

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use Modules\Treasury\Services\BancarizationChecker;
use Modules\Treasury\Services\DetractionService;
use Modules\Treasury\Services\ItfCalculator;
use Modules\Treasury\Services\MovementService;
use Modules\Treasury\Services\PaymentEvidenceService;
use Modules\Treasury\Services\ReceivablePayableService;
use Modules\Treasury\Services\RetentionPerceptionService;
use Modules\Treasury\Services\TaxRateRepository;
use Modules\Treasury\Services\TransferService;
use Modules\Treasury\Services\TreasuryBalanceService;
use Modules\Treasury\Support\BusinessDayCalculator;

/**
 * Shared SQLite + container harness for the Phase 4 (detractions, retentions, perceptions) tests.
 *
 * Detraction/withholding tables are created through the REAL migrations so the schema cannot drift.
 */
trait Phase4Harness
{
    private $previousContainer;
    private $previousFacade;
    private $previousResolver;
    private $previousDispatcher;
    private $previousScopes;
    private $previousBooted;
    private $app;
    private $db;

    protected function bootHarness(array $holidays = []): void
    {
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

        // Real module config (tolerance, certificate types, grace days...) with the test holidays on top.
        $moduleConfig = require __DIR__ . '/../../../modules/Treasury/Config/config.php';
        $this->app->instance('config', new Repository([
            'treasury' => array_merge($moduleConfig, ['allow_future_dates' => false, 'holidays' => $holidays]),
        ]));
        $this->app->instance('cache', new \Illuminate\Cache\Repository(new \Illuminate\Cache\ArrayStore()));
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

        $this->createHarnessSchema();
        $this->seedHarness();
    }

    protected function tearDownHarness(): void
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
    }

    private function createHarnessSchema(): void
    {
        foreach ([
            'banks (id integer primary key autoincrement, description text)',
            'establishments (id integer primary key autoincrement, description text)',
            'users (id integer primary key autoincrement, name text, establishment_id integer null)',
            'persons (id integer primary key autoincrement, type text null, name text, number text null)',
            'companies (id integer primary key autoincrement, is_retention_agent integer default 0, is_perception_agent integer default 0, is_good_taxpayer integer default 0)',
            'bank_accounts (id integer primary key autoincrement, bank_id integer, description text, number text, currency_type_id text, cci text null, status integer, initial_balance numeric, show_in_documents integer, account_type text, pcge_account text null, itf_exempt integer, establishment_id integer null)',
            'cash (id integer primary key autoincrement, user_id integer, date_opening text, time_opening text, date_closed text null, time_closed text null, beginning_balance numeric, final_balance numeric, income numeric, state integer, reference_number text null, created_at text null, updated_at text null)',
            'exchange_rates (id integer primary key autoincrement, date text, purchase numeric, sale numeric, date_original text null, purchase_original numeric null, sale_original numeric null, created_at text null, updated_at text null)',
            'treasury_tax_rates (id integer primary key autoincrement, tax text, code text null, rate numeric null, amount numeric null, min_operation numeric null, valid_from text, valid_to text null, legal_reference text, created_at text null, updated_at text null)',
            'treasury_concepts (id integer primary key autoincrement, type text, description text, pcge_account text null, active integer default 1, created_at text null, updated_at text null)',
            'treasury_movements (id integer primary key autoincrement, establishment_id integer, date text, kind text, direction integer, destination_type text, destination_id integer, currency_type_id text, amount numeric, exchange_rate_purchase numeric null, exchange_rate_sale numeric null, exchange_rate_used numeric null, amount_pen numeric, payment_method_type_id text null, sunat_payment_code text null, operation_number text null, counterparty_person_id integer null, counterparty_document text null, counterparty_name text null, treasury_concept_id integer null, description text, transfer_group text null, related_movement_id integer null, source_type text null, source_id integer null, reconciled_at text null, bank_statement_line_id integer null, state text, voided_at text null, voided_by integer null, void_reason text null, user_id integer, created_at text null, updated_at text null)',
            'payment_evidences (id integer primary key autoincrement, payable_type text, payable_id integer, sunat_payment_code text, operation_number text null, bank_id integer null, bank_account_id integer null, operation_date text, payer_or_beneficiary_document text null, payer_or_beneficiary_name text null, third_party_payment integer default 0, third_party_reported_to_sunat integer default 0, bancarization_exception text null, created_at text null, updated_at text null)',
            'documents (id integer primary key autoincrement, user_id integer, customer_id integer, establishment_id integer, state_type_id text, document_type_id text, series text, number text, date_of_issue text, total numeric, currency_type_id text, exchange_rate_sale numeric, detraction text null, discounts text null, total_canceled integer default 0)',
            'document_payments (id integer primary key autoincrement, document_id integer, payment numeric, date_of_payment text)',
            'document_fee (id integer primary key autoincrement, document_id integer, date text, currency_type_id text, amount numeric)',
            'invoices (id integer primary key autoincrement, document_id integer, date_of_due text null)',
            'dispatches (id integer primary key autoincrement, reference_document_id integer, external_id text null, series text null, number text null, date_of_issue text null, date_of_shipping text null)',
            'sale_notes (id integer primary key autoincrement, user_id integer, customer_id integer, establishment_id integer, state_type_id text, series text null, number text null, prefix text null, filename text null, date_of_issue text, total numeric, currency_type_id text, exchange_rate_sale numeric, changed integer default 0, total_canceled integer default 0)',
            'sale_note_payments (id integer primary key autoincrement, sale_note_id integer, payment numeric, date_of_payment text)',
            'purchases (id integer primary key autoincrement, user_id integer, supplier_id integer, establishment_id integer, state_type_id text, document_type_id text, series text, number text, date_of_issue text, date_of_due text null, total numeric, currency_type_id text, exchange_rate_sale numeric, total_canceled integer default 0, detraction text null)',
            'purchase_payments (id integer primary key autoincrement, purchase_id integer, payment numeric, date_of_payment text)',
            'expenses (id integer primary key autoincrement, user_id integer, supplier_id integer, establishment_id integer, state_type_id text, number text, date_of_issue text, total numeric, currency_type_id text, exchange_rate_sale numeric)',
            'expense_payments (id integer primary key autoincrement, expense_id integer, payment numeric, date_of_payment text)',
            'retentions (id integer primary key autoincrement, series text, number text, state_type_id text, date_of_issue text, total_retention numeric, supplier text null)',
            'perceptions (id integer primary key autoincrement, series text, number text, state_type_id text, date_of_issue text, total_perception numeric, customer text null)',
        ] as $table) {
            $this->db->statement('create table ' . $table);
        }

        foreach ([
            ['2026_11_23_000004_tenant_create_purchase_fees_table.php', '\\TenantCreatePurchaseFeesTable'],
            ['2026_11_23_000005_tenant_create_detraction_records_table.php', '\\TenantCreateDetractionRecordsTable'],
            ['2026_11_23_000006_tenant_create_treasury_withholdings_table.php', '\\TenantCreateTreasuryWithholdingsTable'],
            ['2026_11_23_000007_tenant_add_active_link_key_to_treasury_withholdings.php', '\\TenantAddActiveLinkKeyToTreasuryWithholdings'],
            ['2026_11_23_000008_tenant_seed_detraction_deposit_business_day_rate.php', '\\TenantSeedDetractionDepositBusinessDayRate'],
        ] as [$file, $class]) {
            require_once __DIR__ . '/../../../database/migrations/tenant/' . $file;
            (new $class())->up();
        }
    }

    private function seedHarness(): void
    {
        $this->db->table('banks')->insert([['id' => 1, 'description' => 'BCP'], ['id' => 2, 'description' => 'Banco de la Nación']]);
        $this->db->table('establishments')->insert([['id' => 1, 'description' => 'Principal'], ['id' => 2, 'description' => 'Sucursal']]);
        $this->db->table('users')->insert([
            ['id' => 1, 'name' => 'Admin', 'establishment_id' => 1],
            ['id' => 2, 'name' => 'Vendedor', 'establishment_id' => 1],
        ]);
        $this->db->table('persons')->insert([
            ['id' => 1, 'type' => 'customers', 'name' => 'Cliente Alfa SAC', 'number' => '20100000001'],
            ['id' => 10, 'type' => 'suppliers', 'name' => 'Proveedor Uno SA', 'number' => '20200000010'],
        ]);
        $this->db->table('companies')->insert([['id' => 1, 'is_retention_agent' => 0, 'is_perception_agent' => 0, 'is_good_taxpayer' => 0]]);

        $this->db->table('bank_accounts')->insert([
            ['id' => 1, 'bank_id' => 1, 'description' => 'BCP PEN', 'number' => '191-001', 'currency_type_id' => 'PEN', 'status' => 1, 'initial_balance' => 5000.00, 'show_in_documents' => 1, 'account_type' => 'checking', 'itf_exempt' => 0, 'establishment_id' => 1],
            ['id' => 2, 'bank_id' => 1, 'description' => 'BCP PEN 2', 'number' => '191-002', 'currency_type_id' => 'PEN', 'status' => 1, 'initial_balance' => 100.00, 'show_in_documents' => 1, 'account_type' => 'checking', 'itf_exempt' => 0, 'establishment_id' => 1],
            ['id' => 4, 'bank_id' => 2, 'description' => 'Detracciones BN', 'number' => '00-123-456789', 'currency_type_id' => 'PEN', 'status' => 1, 'initial_balance' => 0.00, 'show_in_documents' => 0, 'account_type' => 'detraction_bn', 'itf_exempt' => 1, 'establishment_id' => 1],
        ]);

        $this->db->table('treasury_concepts')->insert([
            ['id' => 1, 'type' => 'expense', 'description' => 'Pago de tributos', 'active' => 1],
        ]);

        $this->db->table('exchange_rates')->insert([
            ['date' => '2026-11-20', 'purchase' => 3.74, 'sale' => 3.75],
        ]);

        // Fixture rates: the second retention version is test data only, it proves date-based versioning.
        $this->db->table('treasury_tax_rates')->insert([
            ['tax' => 'itf', 'code' => null, 'rate' => 0.00005, 'amount' => null, 'min_operation' => null, 'valid_from' => '2011-04-01', 'valid_to' => null, 'legal_reference' => 'Ley 29667'],
            ['tax' => 'bancarization_threshold_pen', 'code' => null, 'rate' => null, 'amount' => 2000.00, 'min_operation' => null, 'valid_from' => '2022-04-01', 'valid_to' => null, 'legal_reference' => 'DL 1529'],
            ['tax' => 'bancarization_threshold_usd', 'code' => null, 'rate' => null, 'amount' => 500.00, 'min_operation' => null, 'valid_from' => '2022-04-01', 'valid_to' => null, 'legal_reference' => 'DL 1529'],
            ['tax' => 'retention_igv', 'code' => null, 'rate' => 0.03, 'amount' => null, 'min_operation' => 700.00, 'valid_from' => '2014-03-01', 'valid_to' => '2026-12-31', 'legal_reference' => 'fixture v1'],
            ['tax' => 'retention_igv', 'code' => null, 'rate' => 0.04, 'amount' => null, 'min_operation' => 1000.00, 'valid_from' => '2027-01-01', 'valid_to' => null, 'legal_reference' => 'fixture v2'],
            ['tax' => 'perception_igv', 'code' => 'general', 'rate' => 0.02, 'amount' => null, 'min_operation' => null, 'valid_from' => '2013-07-01', 'valid_to' => null, 'legal_reference' => 'fixture'],
            ['tax' => 'perception_igv', 'code' => 'fuel', 'rate' => 0.01, 'amount' => null, 'min_operation' => null, 'valid_from' => '2013-07-01', 'valid_to' => null, 'legal_reference' => 'fixture'],
            ['tax' => 'perception_igv', 'code' => 'agent', 'rate' => 0.005, 'amount' => null, 'min_operation' => null, 'valid_from' => '2013-07-01', 'valid_to' => null, 'legal_reference' => 'fixture'],
            ['tax' => 'detraction', 'code' => '037', 'rate' => 0.12, 'amount' => null, 'min_operation' => 700.00, 'valid_from' => '2018-04-01', 'valid_to' => null, 'legal_reference' => 'fixture'],
        ]);
    }

    protected function detractionJson(float $amountPen, string $code = '037', float $percentage = 12.0): string
    {
        return json_encode([
            'detraction_type_id' => $code,
            'percentage' => $percentage,
            'amount' => $amountPen,
            'payment_method_id' => '001',
            'bank_account' => '00-123-456789',
        ]);
    }

    protected function insertDocument(array $overrides = []): int
    {
        return (int) $this->db->table('documents')->insertGetId(array_merge([
            'user_id' => 1,
            'customer_id' => 1,
            'establishment_id' => 1,
            'state_type_id' => '05',
            'document_type_id' => '01',
            'series' => 'F001',
            'number' => (string) random_int(1, 99999),
            'date_of_issue' => '2026-10-15',
            'total' => 1000.00,
            'currency_type_id' => 'PEN',
            'exchange_rate_sale' => 1,
            'detraction' => null,
            'discounts' => null,
        ], $overrides));
    }

    protected function insertPurchase(array $overrides = []): int
    {
        return (int) $this->db->table('purchases')->insertGetId(array_merge([
            'user_id' => 1,
            'supplier_id' => 10,
            'establishment_id' => 1,
            'state_type_id' => '01',
            'document_type_id' => '01',
            'series' => 'F100',
            'number' => (string) random_int(1, 99999),
            'date_of_issue' => '2026-10-20',
            'date_of_due' => null,
            'total' => 2000.00,
            'currency_type_id' => 'PEN',
            'exchange_rate_sale' => 1,
            'total_canceled' => 0,
            'detraction' => null,
        ], $overrides));
    }

    protected function makeMovementService(): MovementService
    {
        $repo = new TaxRateRepository();
        $bancarization = new BancarizationChecker($repo);

        return new MovementService($repo, new ItfCalculator($repo), $bancarization, new PaymentEvidenceService($bancarization));
    }

    protected function makeTransferService(): TransferService
    {
        $repo = new TaxRateRepository();
        $bancarization = new BancarizationChecker($repo);

        return new TransferService(new ItfCalculator($repo), new PaymentEvidenceService($bancarization));
    }

    protected function makeDetractionService(array $holidays = []): DetractionService
    {
        return new DetractionService(
            $this->makeMovementService(),
            new BusinessDayCalculator($holidays),
            new TaxRateRepository()
        );
    }

    protected function makeRetentionService(): RetentionPerceptionService
    {
        return new RetentionPerceptionService(new TaxRateRepository());
    }

    protected function makeReceivablePayableService(): ReceivablePayableService
    {
        $repo = new TaxRateRepository();

        return new ReceivablePayableService($repo, new BancarizationChecker($repo));
    }

    protected function makeBalanceService(): TreasuryBalanceService
    {
        return new TreasuryBalanceService();
    }
}
