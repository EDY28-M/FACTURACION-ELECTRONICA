<?php

namespace Tests\Unit\Treasury;

use App\Models\Tenant\BankAccount;
use App\Models\Tenant\Cash;
use App\Models\Tenant\Document;
use App\Models\Tenant\DocumentPayment;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Facade;
use Modules\Pos\Models\CashTransaction;
use Modules\Treasury\Http\Controllers\MovementController;
use Modules\Treasury\Http\Controllers\PaymentEvidenceController;
use Modules\Treasury\Http\Controllers\TransferController;
use Modules\Treasury\Http\Requests\StoreMovementRequest;
use Modules\Treasury\Http\Requests\StorePaymentEvidenceRequest;
use Modules\Treasury\Http\Requests\StoreTransferRequest;
use Modules\Treasury\Http\Requests\VoidMovementRequest;
use Modules\Treasury\Models\TreasuryMovement;
use Modules\Treasury\Services\BancarizationChecker;
use Modules\Treasury\Services\ItfCalculator;
use Modules\Treasury\Services\MovementService;
use Modules\Treasury\Services\PaymentEvidenceService;
use Modules\Treasury\Services\TaxRateRepository;
use Modules\Treasury\Services\TransferService;
use PHPUnit\Framework\TestCase;

/**
 * Verifies record-level and establishment/user authorization boundaries on Phase 2 Treasury controllers.
 */
class TreasuryControllerSecurityTest extends TestCase
{
    private $previousContainer;
    private $previousFacade;
    private $previousResolver;
    private $previousDispatcher;
    private $previousScopes;
    private $previousBooted;
    private $app;
    private $db;
    private $auth;

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

        $this->app->instance('config', new Repository([
            'treasury' => ['allow_future_dates' => false],
            'permissions' => ['denied_message' => 'No tiene permiso para este módulo.'],
        ]));
        $this->app->instance(
            'validator',
            new \Illuminate\Validation\Factory(
                new \Illuminate\Translation\Translator(new \Illuminate\Translation\ArrayLoader(), 'es'),
                $this->app
            )
        );
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

        $manager = new Manager($this->app);
        $manager->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''], 'tenant');
        $database = $manager->getDatabaseManager();
        $database->setDefaultConnection('tenant');
        $this->app->instance('db', $database);

        Model::setConnectionResolver($database);
        Model::setEventDispatcher(new Dispatcher($this->app));

        $this->db = $database->connection('tenant');

        foreach ([Document::class, DocumentPayment::class] as $model) {
            $model::addGlobalScope('controller-security-fixture', function ($query) {
                $query->setEagerLoads([]);
            });
        }

        foreach ([
            'banks (id integer primary key autoincrement, description text)',
            'establishments (id integer primary key autoincrement, description text)',
            'cat_currency_types (id text primary key, description text, symbol text, active integer)',
            'users (id integer primary key autoincrement, name text, type text, establishment_id integer null)',
            'bank_accounts (id integer primary key autoincrement, bank_id integer, description text, number text, currency_type_id text, cci text null, status integer, initial_balance numeric, show_in_documents integer, account_type text, pcge_account text null, itf_exempt integer, establishment_id integer null)',
            'cash (id integer primary key autoincrement, user_id integer, date_opening text, time_opening text, date_closed text null, time_closed text null, beginning_balance numeric, final_balance numeric, income numeric, state integer, reference_number text null, created_at text null, updated_at text null)',
            'treasury_concepts (id integer primary key autoincrement, type text, description text, pcge_account text null, active integer, created_at text null, updated_at text null)',
            'payment_method_types (id text primary key, description text, has_card integer, charge numeric null, number_days integer null, sunat_code text null, is_cash integer)',
            'treasury_tax_rates (id integer primary key autoincrement, tax text, code text null, rate numeric null, amount numeric null, min_operation numeric null, valid_from text, valid_to text null, legal_reference text, created_at text null, updated_at text null)',
            'treasury_movements (id integer primary key autoincrement, establishment_id integer, date text, kind text, direction integer, destination_type text, destination_id integer, currency_type_id text, amount numeric, exchange_rate_purchase numeric null, exchange_rate_sale numeric null, exchange_rate_used numeric null, amount_pen numeric, payment_method_type_id text null, sunat_payment_code text null, operation_number text null, counterparty_person_id integer null, counterparty_document text null, counterparty_name text null, treasury_concept_id integer null, description text, transfer_group text null, related_movement_id integer null, source_type text null, source_id integer null, reconciled_at text null, bank_statement_line_id integer null, state text, voided_at text null, voided_by integer null, void_reason text null, user_id integer, created_at text null, updated_at text null)',
            'payment_evidences (id integer primary key autoincrement, payable_type text, payable_id integer, sunat_payment_code text, operation_number text null, bank_id integer null, bank_account_id integer null, operation_date text, payer_or_beneficiary_document text null, payer_or_beneficiary_name text null, third_party_payment integer, third_party_reported_to_sunat integer, bancarization_exception text null, created_at text null, updated_at text null)',
            'documents (id integer primary key autoincrement, user_id integer, state_type_id text, currency_type_id text, exchange_rate_sale numeric, total numeric, customer text null, created_at text null, updated_at text null)',
            'document_payments (id integer primary key autoincrement, document_id integer, date_of_payment text, payment_method_type_id text, has_card integer, card_brand_id text null, reference text null, change numeric null, payment numeric)',
            'cash_transactions (id integer primary key autoincrement, cash_id integer, payment_method_type_id text null, date text null, description text null, payment numeric)',
            'global_payments (id integer primary key autoincrement, soap_type_id text null, destination_id integer, destination_type text, payment_id integer, payment_type text, user_id integer null, created_at text null, updated_at text null)',
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

    private function makeUser(int $id, string $type, int $establishmentId, array $modules): object
    {
        return new class($id, $type, $establishmentId, $modules) {
            public $id;
            public $type;
            public $establishment_id;
            private $modules;

            public function __construct(int $id, string $type, int $establishmentId, array $modules)
            {
                $this->id = $id;
                $this->type = $type;
                $this->establishment_id = $establishmentId;
                $this->modules = $modules;
            }

            public function moduleValues(): array
            {
                return $this->modules;
            }
        };
    }

    private function seedFixtures(): void
    {
        $openingDate = date('Y-m-d', strtotime('-2 days'));
        $yesterday = date('Y-m-d', strtotime('-1 day'));

        $this->db->table('banks')->insert(['id' => 1, 'description' => 'BCP']);
        $this->db->table('establishments')->insert([
            ['id' => 1, 'description' => 'Sede 1'],
            ['id' => 2, 'description' => 'Sede 2'],
        ]);
        $this->db->table('cat_currency_types')->insert([
            ['id' => 'PEN', 'description' => 'Soles', 'symbol' => 'S/', 'active' => 1],
        ]);
        $this->db->table('users')->insert([
            ['id' => 1, 'name' => 'Admin', 'type' => 'admin', 'establishment_id' => 1],
            ['id' => 2, 'name' => 'Seller Est1', 'type' => 'seller', 'establishment_id' => 1],
            ['id' => 3, 'name' => 'Seller Est2', 'type' => 'seller', 'establishment_id' => 2],
        ]);
        $this->db->table('bank_accounts')->insert([
            ['id' => 1, 'bank_id' => 1, 'description' => 'Cuenta Est1', 'number' => '191-1', 'currency_type_id' => 'PEN', 'status' => 1, 'initial_balance' => 1000, 'show_in_documents' => 1, 'account_type' => 'checking', 'itf_exempt' => 0, 'establishment_id' => 1],
            ['id' => 2, 'bank_id' => 1, 'description' => 'Cuenta Est2', 'number' => '191-2', 'currency_type_id' => 'PEN', 'status' => 1, 'initial_balance' => 1000, 'show_in_documents' => 1, 'account_type' => 'checking', 'itf_exempt' => 0, 'establishment_id' => 2],
            ['id' => 3, 'bank_id' => 1, 'description' => 'Cuenta Global', 'number' => '191-3', 'currency_type_id' => 'PEN', 'status' => 1, 'initial_balance' => 1000, 'show_in_documents' => 1, 'account_type' => 'checking', 'itf_exempt' => 0, 'establishment_id' => null],
        ]);
        $this->db->table('cash')->insert([
            ['id' => 1, 'user_id' => 2, 'date_opening' => $openingDate, 'time_opening' => '08:00:00', 'date_closed' => null, 'time_closed' => null, 'beginning_balance' => 100, 'final_balance' => 0, 'income' => 0, 'state' => 1, 'reference_number' => 'C-U2'],
            ['id' => 2, 'user_id' => 3, 'date_opening' => $openingDate, 'time_opening' => '08:00:00', 'date_closed' => null, 'time_closed' => null, 'beginning_balance' => 100, 'final_balance' => 0, 'income' => 0, 'state' => 1, 'reference_number' => 'C-U3'],
        ]);
        $this->db->table('treasury_concepts')->insert([
            ['id' => 1, 'type' => 'income', 'description' => 'Ingreso', 'active' => 1],
            ['id' => 2, 'type' => 'expense', 'description' => 'Egreso', 'active' => 1],
        ]);
        $this->db->table('payment_method_types')->insert([
            ['id' => '01', 'description' => 'Efectivo', 'has_card' => 0, 'sunat_code' => '008', 'is_cash' => 1],
            ['id' => '04', 'description' => 'Transferencia', 'has_card' => 0, 'sunat_code' => '003', 'is_cash' => 0],
        ]);
        $this->db->table('treasury_tax_rates')->insert([
            ['tax' => 'bancarization_threshold_pen', 'code' => null, 'rate' => null, 'amount' => 2000.00, 'min_operation' => null, 'valid_from' => '2022-04-01', 'valid_to' => null, 'legal_reference' => 'DL 1529'],
        ]);
        $this->db->table('documents')->insert([
            ['id' => 10, 'user_id' => 3, 'state_type_id' => '05', 'currency_type_id' => 'PEN', 'exchange_rate_sale' => 3.75, 'total' => 500.00],
            ['id' => 11, 'user_id' => 2, 'state_type_id' => '05', 'currency_type_id' => 'PEN', 'exchange_rate_sale' => 3.75, 'total' => 500.00],
        ]);
        $this->db->table('document_payments')->insert([
            ['id' => 10, 'document_id' => 10, 'date_of_payment' => $yesterday, 'payment_method_type_id' => '04', 'has_card' => 0, 'reference' => 'R10', 'payment' => 500.00],
            ['id' => 11, 'document_id' => 11, 'date_of_payment' => $yesterday, 'payment_method_type_id' => '04', 'has_card' => 0, 'reference' => 'R11', 'payment' => 500.00],
        ]);
        $this->db->table('cash_transactions')->insert([
            ['id' => 20, 'cash_id' => 2, 'payment_method_type_id' => '01', 'date' => $yesterday, 'description' => 'Saldo U3', 'payment' => 100.00],
        ]);
    }

    private function makeServices(): array
    {
        $repo = new TaxRateRepository();
        $itf = new ItfCalculator($repo);
        $bancarization = new BancarizationChecker($repo);
        $evidence = new PaymentEvidenceService($bancarization);
        $movement = new MovementService($repo, $itf, $bancarization, $evidence);
        $transfer = new TransferService($itf, $evidence);

        return [$evidence, $movement, $transfer];
    }

    public function test_payment_evidence_controller_blocks_non_admin_idor_and_module_mismatch(): void
    {
        [$evidenceService] = $this->makeServices();
        $controller = new PaymentEvidenceController($evidenceService);
        $yesterday = date('Y-m-d', strtotime('-1 day'));

        // Seller #2 with 'documents' only:
        // 1. Denied 403 on DocumentPayment #10 owned by Seller #3 (IDOR blocked)
        $this->auth->currentUser = $this->makeUser(2, 'seller', 1, ['documents']);
        $idorShow = $controller->show('document_payment', 10);
        $this->assertSame(403, $idorShow->getStatusCode());

        $storeReq = new class(['sunat_payment_code' => '003', 'operation_date' => $yesterday]) extends StorePaymentEvidenceRequest {
            private $payload;
            public function __construct(array $payload) { $this->payload = $payload; }
            public function validated($key = null, $default = null) { return $this->payload; }
        };
        $idorStore = $controller->store($storeReq, 'document_payment', 10);
        $this->assertSame(403, $idorStore->getStatusCode());

        // 2. Denied 403 on CashTransaction #20 owned by Seller #3 even with 'pos' module
        $this->auth->currentUser = $this->makeUser(2, 'seller', 1, ['pos', 'documents']);
        $this->assertSame(403, $controller->show('cash_transaction', 20)->getStatusCode());

        // 3. Denied 403 on purchase_payment when user only has 'documents' (per-alias module restriction)
        $this->auth->currentUser = $this->makeUser(2, 'seller', 1, ['documents']);
        $this->assertSame(403, $controller->show('purchase_payment', 1)->getStatusCode());

        // 4. Allowed 200 on Seller #2's own DocumentPayment #11
        $ownShow = $controller->show('document_payment', 11);
        $this->assertSame(200, $ownShow->getStatusCode());

        // 5. Admin allowed 200 on Seller #3's DocumentPayment #10
        $this->auth->currentUser = $this->makeUser(1, 'admin', 1, ['treasury']);
        $adminShow = $controller->show('document_payment', 10);
        $this->assertSame(200, $adminShow->getStatusCode());
    }

    public function test_movement_and_transfer_controllers_enforce_non_admin_isolation_and_transfer_void_guard(): void
    {
        [, $movementService, $transferService] = $this->makeServices();
        $movController = new MovementController($movementService);
        $trfController = new TransferController($transferService, $movementService);
        $yesterday = date('Y-m-d', strtotime('-1 day'));

        // Seller #2 (est 1) calling tables() only sees est 1, est1/global bank accounts, and own cash #1
        $this->auth->currentUser = $this->makeUser(2, 'seller', 1, ['treasury']);
        $movTables = $movController->tables()->getData(true);
        $this->assertSame([1], array_column($movTables['establishments'], 'id'));
        $this->assertSame([1, 3], array_column($movTables['bank_accounts'], 'id'));
        $this->assertSame([1], array_column($movTables['open_cashes'], 'id'));

        $trfTables = $trfController->tables()->getData(true);
        $this->assertSame([1], array_column($trfTables['establishments'], 'id'));
        $this->assertSame([1, 3], array_column($trfTables['bank_accounts'], 'id'));
        $this->assertSame([1], array_column($trfTables['open_cashes'], 'id'));

        // Seller #2 cannot create a movement in Seller #3's cash #2 (403) or Est2's bank account #2 (403)
        $badCashReq = new class([
            'establishment_id' => 1,
            'date' => $yesterday,
            'kind' => 'income',
            'destination_type' => 'cash',
            'destination_id' => 2,
            'currency_type_id' => 'PEN',
            'amount' => 50,
            'treasury_concept_id' => 1,
            'description' => 'Intento caja ajena',
        ]) extends StoreMovementRequest {
            private $payload;
            public function __construct(array $payload) { $this->payload = $payload; }
            public function validated($key = null, $default = null) { return $this->payload; }
        };
        $this->assertSame(403, $movController->store($badCashReq)->getStatusCode());

        $badBankReq = new class([
            'establishment_id' => 1,
            'date' => $yesterday,
            'kind' => 'income',
            'destination_type' => 'bank_account',
            'destination_id' => 2,
            'currency_type_id' => 'PEN',
            'amount' => 50,
            'treasury_concept_id' => 1,
            'description' => 'Intento banco otra sede',
        ]) extends StoreMovementRequest {
            private $payload;
            public function __construct(array $payload) { $this->payload = $payload; }
            public function validated($key = null, $default = null) { return $this->payload; }
        };
        $this->assertSame(403, $movController->store($badBankReq)->getStatusCode());

        // Seller #2 cannot transfer from/to another user's cash (403)
        $badTrfReq = new class([
            'establishment_id' => 1,
            'date' => $yesterday,
            'origin_type' => 'cash',
            'origin_id' => 2,
            'target_type' => 'bank_account',
            'target_id' => 1,
            'amount' => 50,
            'description' => 'Transferencia caja ajena',
        ]) extends StoreTransferRequest {
            private $payload;
            public function __construct(array $payload) { $this->payload = $payload; }
            public function validated($key = null, $default = null) { return $this->payload; }
        };
        $this->assertSame(403, $trfController->store($badTrfReq)->getStatusCode());

        // Admin creates a manual movement (user 1, est 1) and a transfer (user 1, est 1)
        $manual = $movementService->createManual([
            'establishment_id' => 1,
            'date' => $yesterday,
            'kind' => 'income',
            'destination_type' => 'bank_account',
            'destination_id' => 1,
            'currency_type_id' => 'PEN',
            'amount' => 100,
            'treasury_concept_id' => 1,
            'description' => 'Manual de admin',
        ], 1);

        $transfer = $transferService->create([
            'establishment_id' => 1,
            'date' => $yesterday,
            'origin_type' => 'bank_account',
            'origin_id' => 1,
            'target_type' => 'bank_account',
            'target_id' => 3,
            'amount' => 100,
            'description' => 'Transferencia de admin',
        ], 1);

        $voidReq = new class(['void_reason' => 'Motivo valido']) extends VoidMovementRequest {
            private $payload;
            public function __construct(array $payload) { $this->payload = $payload; }
            public function input($key = null, $default = null) { return $key === null ? $this->payload : ($this->payload[$key] ?? $default); }
        };

        // Seller #2 denied 403 trying to void Admin's manual movement or transfer
        $this->assertSame(403, $movController->void($voidReq, $manual['movement']->id)->getStatusCode());
        $this->assertSame(403, $trfController->void($voidReq, $transfer['out']->id)->getStatusCode());

        // TransferController::void() rejects a non-transfer manual movement with 422 (even as admin)
        $this->auth->currentUser = $this->makeUser(1, 'admin', 1, ['treasury']);
        $nonTransferResp = $trfController->void($voidReq, $manual['movement']->id);
        $this->assertSame(422, $nonTransferResp->getStatusCode());
        $this->assertSame('active', TreasuryMovement::find($manual['movement']->id)->state);
    }
}
