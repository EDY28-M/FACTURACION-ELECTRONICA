<?php

namespace Tests\Unit\Treasury;

use App\Http\Controllers\Tenant\CashController;
use App\Models\Tenant\BankAccount;
use App\Models\Tenant\Cash;
use App\Models\Tenant\Document;
use App\Models\Tenant\SaleNote;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Facade;
use Modules\Finance\Models\GlobalPayment;
use Modules\Treasury\Services\BancarizationChecker;
use Modules\Treasury\Services\CashTreasuryIntegrationService;
use Modules\Treasury\Services\ItfCalculator;
use Modules\Treasury\Services\MovementService;
use Modules\Treasury\Services\PaymentEvidenceService;
use Modules\Treasury\Services\TaxRateRepository;
use Modules\Treasury\Services\TransferService;
use Modules\Treasury\Services\TreasuryBalanceService;
use PHPUnit\Framework\TestCase;

/**
 * Verifies Phase 2 POS Cash closing integration and Finance <-> Treasury balance parity.
 */
class CashCloseTreasuryIntegrationTest extends TestCase
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

        $this->app->instance('config', new Repository(['treasury' => ['allow_future_dates' => false]]));

        $manager = new Manager($this->app);
        $manager->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''], 'tenant');
        $database = $manager->getDatabaseManager();
        $database->setDefaultConnection('tenant');
        $this->app->instance('db', $database);

        Model::setConnectionResolver($database);
        Model::setEventDispatcher(new Dispatcher($this->app));

        $this->db = $database->connection('tenant');

        $this->app->instance(AuthFactory::class, new class {
            public function user()
            {
                return (object) ['id' => 1, 'type' => 'admin'];
            }

            public function id()
            {
                return 1;
            }
        });

        $this->app->instance(ResponseFactory::class, new class {
            public function json($data = [], $status = 200, array $headers = [], $options = 0): JsonResponse
            {
                return new JsonResponse($data, $status, $headers, $options);
            }
        });

        foreach ([Document::class, SaleNote::class] as $model) {
            $model::addGlobalScope('cash-treasury-fixture', function ($query) {
                $query->setEagerLoads([]);
            });
        }

        foreach ([
            'banks (id integer primary key autoincrement, description text)',
            'establishments (id integer primary key autoincrement, description text)',
            'users (id integer primary key autoincrement, name text, establishment_id integer null)',
            'bank_accounts (id integer primary key autoincrement, bank_id integer, description text, number text, currency_type_id text, cci text null, status integer, initial_balance numeric, show_in_documents integer, account_type text, pcge_account text null, itf_exempt integer, establishment_id integer null)',
            'cash (id integer primary key autoincrement, user_id integer, date_opening text, time_opening text, date_closed text null, time_closed text null, beginning_balance numeric, final_balance numeric, income numeric, state integer, reference_number text null, created_at text null, updated_at text null)',
            'cash_documents (id integer primary key autoincrement, cash_id integer, document_id integer null, sale_note_id integer null, expense_payment_id integer null)',
            'sale_notes (id integer primary key autoincrement, state_type_id text, currency_type_id text, exchange_rate_sale numeric, total numeric, changed integer default 0, created_at text null, updated_at text null)',
            'documents (id integer primary key autoincrement, state_type_id text, currency_type_id text, exchange_rate_sale numeric, total numeric, created_at text null, updated_at text null)',
            'expenses (id integer primary key autoincrement, state_type_id text, currency_type_id text, exchange_rate_sale numeric, total numeric, created_at text null, updated_at text null)',
            'expense_payments (id integer primary key autoincrement, expense_id integer, payment numeric, date_of_payment text null)',
            'cash_transactions (id integer primary key autoincrement, cash_id integer, payment_method_type_id text null, date text null, description text null, payment numeric)',
            'global_payments (id integer primary key autoincrement, soap_type_id text null, destination_id integer, destination_type text, payment_id integer, payment_type text, user_id integer null, created_at text null, updated_at text null)',
            'treasury_concepts (id integer primary key autoincrement, type text, description text, pcge_account text null, active integer, created_at text null, updated_at text null)',
            'payment_method_types (id text primary key, description text, has_card integer, charge numeric null, number_days integer null, sunat_code text null, is_cash integer)',
            'treasury_tax_rates (id integer primary key autoincrement, tax text, code text null, rate numeric null, amount numeric null, min_operation numeric null, valid_from text, valid_to text null, legal_reference text, created_at text null, updated_at text null)',
            'treasury_movements (id integer primary key autoincrement, establishment_id integer, date text, kind text, direction integer, destination_type text, destination_id integer, currency_type_id text, amount numeric, exchange_rate_purchase numeric null, exchange_rate_sale numeric null, exchange_rate_used numeric null, amount_pen numeric, payment_method_type_id text null, sunat_payment_code text null, operation_number text null, counterparty_person_id integer null, counterparty_document text null, counterparty_name text null, treasury_concept_id integer null, description text, transfer_group text null, related_movement_id integer null, source_type text null, source_id integer null, reconciled_at text null, bank_statement_line_id integer null, state text, voided_at text null, voided_by integer null, void_reason text null, user_id integer, created_at text null, updated_at text null)',
            'payment_evidences (id integer primary key autoincrement, payable_type text, payable_id integer, sunat_payment_code text, operation_number text null, bank_id integer null, bank_account_id integer null, operation_date text, payer_or_beneficiary_document text null, payer_or_beneficiary_name text null, third_party_payment integer, third_party_reported_to_sunat integer, bancarization_exception text null, created_at text null, updated_at text null)',
        ] as $table) {
            $this->db->statement('create table ' . $table);
        }

        $this->seedFixtures();
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

    private function seedFixtures(): void
    {
        $this->db->table('banks')->insert(['id' => 1, 'description' => 'BCP']);
        $this->db->table('establishments')->insert(['id' => 1, 'description' => 'Principal']);
        $this->db->table('users')->insert(['id' => 1, 'name' => 'Cajero', 'establishment_id' => 1]);

        $this->db->table('bank_accounts')->insert([
            'id' => 1,
            'bank_id' => 1,
            'description' => 'BCP PEN',
            'number' => '191-001',
            'currency_type_id' => 'PEN',
            'status' => 1,
            'initial_balance' => 1000.00,
            'show_in_documents' => 1,
            'account_type' => 'checking',
            'itf_exempt' => 0,
            'establishment_id' => 1,
        ]);

        $openingDate = date('Y-m-d', strtotime('-3 days'));
        $this->db->table('cash')->insert([
            ['id' => 1, 'user_id' => 1, 'date_opening' => $openingDate, 'time_opening' => '08:00:00', 'date_closed' => null, 'time_closed' => null, 'beginning_balance' => 200.00, 'final_balance' => 0, 'income' => 0, 'state' => 1, 'reference_number' => 'CAJA-1'],
            ['id' => 2, 'user_id' => 1, 'date_opening' => $openingDate, 'time_opening' => '08:00:00', 'date_closed' => null, 'time_closed' => null, 'beginning_balance' => 50.00, 'final_balance' => 0, 'income' => 0, 'state' => 1, 'reference_number' => 'CAJA-2'],
        ]);

        $this->db->table('treasury_concepts')->insert([
            ['id' => 1, 'type' => 'income', 'description' => 'Reposicion', 'active' => 1],
            ['id' => 2, 'type' => 'expense', 'description' => 'Gasto menor', 'active' => 1],
        ]);
        $this->db->table('payment_method_types')->insert([
            ['id' => '01', 'description' => 'Efectivo', 'has_card' => 0, 'sunat_code' => '008', 'is_cash' => 1],
        ]);
        $this->db->table('treasury_tax_rates')->insert([
            ['tax' => 'bancarization_threshold_pen', 'code' => null, 'rate' => null, 'amount' => 2000.00, 'min_operation' => null, 'valid_from' => '2022-04-01', 'valid_to' => null, 'legal_reference' => 'DL 1529'],
        ]);
    }

    public function test_cash_close_and_summary_include_active_in_window_treasury_movements_only_and_preserve_finance_parity(): void
    {
        $repo = new TaxRateRepository();
        $itf = new ItfCalculator($repo);
        $bancarization = new BancarizationChecker($repo);
        $evidence = new PaymentEvidenceService($bancarization);
        $movementService = new MovementService($repo, $itf, $bancarization, $evidence);
        $transferService = new TransferService($itf, $evidence);
        $integration = new CashTreasuryIntegrationService();
        $balanceService = new TreasuryBalanceService();

        // 1. Sale note of S/ 150.00 in cash_documents
        $this->db->table('sale_notes')->insert([
            'id' => 1,
            'state_type_id' => '05',
            'currency_type_id' => 'PEN',
            'exchange_rate_sale' => 3.75,
            'total' => 150.00,
        ]);
        $this->db->table('cash_documents')->insert([
            'cash_id' => 1,
            'sale_note_id' => 1,
        ]);

        $inWindow1 = date('Y-m-d', strtotime('-2 days'));
        $inWindow2 = date('Y-m-d', strtotime('-1 day'));
        $beforeOpening = date('Y-m-d', strtotime('-5 days'));
        $today = date('Y-m-d');

        // 2. Bank -> Cash withdrawal (+300.00 to cash #1)
        $transferService->create([
            'establishment_id' => 1,
            'date' => $inWindow1,
            'origin_type' => 'bank_account',
            'origin_id' => 1,
            'target_type' => 'cash',
            'target_id' => 1,
            'amount' => 300.00,
            'description' => 'Retiro para caja chica',
        ], 1);

        // 3. Cash -> Bank deposit (-120.00 from cash #1)
        $transferService->create([
            'establishment_id' => 1,
            'date' => $inWindow2,
            'origin_type' => 'cash',
            'origin_id' => 1,
            'target_type' => 'bank_account',
            'target_id' => 1,
            'amount' => 120.00,
            'description' => 'Deposito parcial al banco',
        ], 1);

        // 4. Voided manual movement on cash #1 (+500.00) -> MUST be excluded!
        $voided = $movementService->createManual([
            'establishment_id' => 1,
            'date' => $inWindow1,
            'kind' => 'income',
            'destination_type' => 'cash',
            'destination_id' => 1,
            'currency_type_id' => 'PEN',
            'amount' => 500.00,
            'payment_method_type_id' => '01',
            'treasury_concept_id' => 1,
            'description' => 'Movimiento anulado',
        ], 1);
        $movementService->void($voided['movement']->id, 'Anulado de prueba', 1);

        // 5. Out-of-window movement (before opening) seeded directly to verify CashTreasuryIntegrationService window filter!
        $this->db->table('treasury_movements')->insert([
            'establishment_id' => 1,
            'date' => $beforeOpening,
            'kind' => 'income',
            'direction' => 1,
            'destination_type' => Cash::class,
            'destination_id' => 1,
            'currency_type_id' => 'PEN',
            'amount' => 90.00,
            'exchange_rate_used' => 1.0,
            'amount_pen' => 90.00,
            'payment_method_type_id' => '01',
            'treasury_concept_id' => 1,
            'description' => 'Antes de apertura',
            'state' => 'active',
            'user_id' => 1,
        ]);

        // 6. Movement on OTHER cash #2 (+40.00) -> MUST be excluded from cash #1!
        $movementService->createManual([
            'establishment_id' => 1,
            'date' => $inWindow1,
            'kind' => 'income',
            'destination_type' => 'cash',
            'destination_id' => 2,
            'currency_type_id' => 'PEN',
            'amount' => 40.00,
            'payment_method_type_id' => '01',
            'treasury_concept_id' => 1,
            'description' => 'Otra caja',
        ], 1);

        // Check summarizeForCash before closing
        $cashModel = Cash::findOrFail(1);
        $summaryBefore = $integration->summarizeForCash($cashModel, $today);
        $this->assertCount(2, $summaryBefore['movements']);
        $this->assertEqualsWithDelta(300.00, $summaryBefore['income'], 0.0001);
        $this->assertEqualsWithDelta(120.00, $summaryBefore['egress'], 0.0001);
        $this->assertEqualsWithDelta(180.00, $summaryBefore['net'], 0.0001);

        // Close cash #1: sale_note (+150) + treasury net (+180) = +330 income; final_balance = 200 + 330 = 530
        $closeResponse = (new CashController())->close(1);
        $this->assertTrue($closeResponse['success']);

        $closed = Cash::findOrFail(1);
        $this->assertEqualsWithDelta(330.00, (float) $closed->income, 0.0001);
        $this->assertEqualsWithDelta(530.00, (float) $closed->final_balance, 0.0001);

        // Verify Finance Balance (getBalanceByCash / getBalanceByBankAccounts) === Treasury Summary parity!
        $treasurySummary = $balanceService->getSummary();
        $financeCash = $balanceService->getBalanceByCash(
            $balanceService->wrapGlobalPayments(GlobalPayment::where('destination_type', Cash::class)->get())
        );
        $bankAccounts = BankAccount::with(['bank', 'global_destination'])->get()->each(function ($acc) use ($balanceService) {
            $acc->setRelation('global_destination', $balanceService->wrapGlobalPayments($acc->global_destination));
        });
        $financeBanks = $balanceService->getBalanceByBankAccounts($bankAccounts);

        $this->assertSame($financeCash['balance'], $treasurySummary['cash_general']['balance']);
        $this->assertSame($financeBanks->first()['balance'], $treasurySummary['bank_accounts'][0]['finance_balance']);
        // Bank #1 balance = 1000 - 300 + 120 = 820.00
        $this->assertSame('820.00', $treasurySummary['bank_accounts'][0]['balance_currency']);
        // Cash general treasury total = (+300 - 120) + 90 + 40 = 310.00
        $this->assertSame('310.00', $treasurySummary['cash_general']['balance']);
    }
}
