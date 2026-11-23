<?php

namespace Tests\Unit\Treasury;

use App\Models\Tenant\BankAccount;
use App\Models\Tenant\Cash;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\SQLiteGrammar;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use Illuminate\Validation\ValidationException;
use Modules\Treasury\Models\PaymentEvidence;
use Modules\Treasury\Models\TreasuryMovement;
use Modules\Treasury\Services\BancarizationChecker;
use Modules\Treasury\Services\ItfCalculator;
use Modules\Treasury\Services\MovementService;
use Modules\Treasury\Services\PaymentEvidenceService;
use Modules\Treasury\Services\TaxRateRepository;
use PHPUnit\Framework\TestCase;

class MovementServiceTest extends TestCase
{
    private $previousContainer;
    private $previousFacade;
    private $previousResolver;
    private $previousDispatcher;
    private $previousScopes;
    private $previousBooted;
    private $app;
    private $db;
    private $grammar;
    private $config;

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

        $this->config = new Repository(['treasury' => ['allow_future_dates' => false]]);
        $this->app->instance('config', $this->config);
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

        Model::setConnectionResolver($database);
        Model::setEventDispatcher(new Dispatcher($this->app));

        $this->db = $database->connection('tenant');
        $this->grammar = new class extends SQLiteGrammar {
            public $locks = [];

            protected function compileLock(Builder $query, $value)
            {
                $this->locks[] = [
                    'table' => $query->from,
                    'lock' => $value,
                    'transaction' => $query->getConnection()->transactionLevel(),
                ];

                return '';
            }
        };
        $this->db->setQueryGrammar($this->grammar);

        foreach ([
            'bank_accounts (id integer primary key autoincrement, bank_id integer, description text, number text, currency_type_id text, cci text null, status integer, initial_balance numeric, show_in_documents integer, account_type text, pcge_account text null, itf_exempt integer, establishment_id integer null)',
            'cash (id integer primary key autoincrement, user_id integer, date_opening text, time_opening text, date_closed text null, time_closed text null, beginning_balance numeric, final_balance numeric, income numeric, state integer, reference_number text null, created_at text null, updated_at text null)',
            'treasury_concepts (id integer primary key autoincrement, type text, description text, pcge_account text null, active integer, created_at text null, updated_at text null)',
            'payment_method_types (id text primary key, description text, has_card integer, charge numeric null, number_days integer null, sunat_code text null, is_cash integer)',
            'exchange_rates (id integer primary key autoincrement, date text, purchase numeric, sale numeric, date_original text null, purchase_original numeric null, sale_original numeric null, created_at text null, updated_at text null)',
            'persons (id integer primary key autoincrement, number text, name text)',
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
        $this->db->table('bank_accounts')->insert([
            ['id' => 1, 'bank_id' => 1, 'description' => 'BCP Soles', 'number' => '191-111', 'currency_type_id' => 'PEN', 'status' => 1, 'initial_balance' => 1000, 'show_in_documents' => 1, 'account_type' => 'checking', 'itf_exempt' => 0, 'establishment_id' => 1],
            ['id' => 2, 'bank_id' => 1, 'description' => 'BCP Dolares', 'number' => '191-222', 'currency_type_id' => 'USD', 'status' => 1, 'initial_balance' => 500, 'show_in_documents' => 1, 'account_type' => 'checking', 'itf_exempt' => 0, 'establishment_id' => 1],
            ['id' => 3, 'bank_id' => 2, 'description' => 'BN Detracciones', 'number' => '00-001', 'currency_type_id' => 'PEN', 'status' => 1, 'initial_balance' => 200, 'show_in_documents' => 0, 'account_type' => 'detraction_bn', 'itf_exempt' => 1, 'establishment_id' => 1],
            ['id' => 4, 'bank_id' => 1, 'description' => 'Cuenta inactiva', 'number' => '191-999', 'currency_type_id' => 'PEN', 'status' => 0, 'initial_balance' => 0, 'show_in_documents' => 0, 'account_type' => 'checking', 'itf_exempt' => 0, 'establishment_id' => 1],
        ]);

        $openingDate = date('Y-m-d', strtotime('-3 days'));
        $closedDate = date('Y-m-d', strtotime('-4 days'));

        $this->db->table('cash')->insert([
            ['id' => 1, 'user_id' => 1, 'date_opening' => $openingDate, 'time_opening' => '08:00:00', 'date_closed' => null, 'time_closed' => null, 'beginning_balance' => 100, 'final_balance' => 0, 'income' => 0, 'state' => 1, 'reference_number' => 'CAJA-1'],
            ['id' => 2, 'user_id' => 2, 'date_opening' => $closedDate, 'time_opening' => '08:00:00', 'date_closed' => $closedDate, 'time_closed' => '18:00:00', 'beginning_balance' => 100, 'final_balance' => 100, 'income' => 0, 'state' => 0, 'reference_number' => 'CAJA-2'],
        ]);

        $this->db->table('treasury_concepts')->insert([
            ['id' => 1, 'type' => 'income', 'description' => 'Prestamo recibido', 'pcge_account' => '451', 'active' => 1],
            ['id' => 2, 'type' => 'expense', 'description' => 'Pago de servicios', 'pcge_account' => '636', 'active' => 1],
            ['id' => 3, 'type' => 'expense', 'description' => 'Concepto inactivo', 'pcge_account' => '659', 'active' => 0],
        ]);

        $this->db->table('payment_method_types')->insert([
            ['id' => '01', 'description' => 'Efectivo', 'has_card' => 0, 'sunat_code' => '008', 'is_cash' => 1],
            ['id' => '04', 'description' => 'Transferencia', 'has_card' => 0, 'sunat_code' => '003', 'is_cash' => 0],
        ]);

        $this->db->table('exchange_rates')->insert([
            ['date' => $openingDate, 'purchase' => 3.7400, 'sale' => 3.7600],
        ]);

        $this->db->table('treasury_tax_rates')->insert([
            ['tax' => 'itf', 'code' => null, 'rate' => 0.00005, 'amount' => null, 'min_operation' => null, 'valid_from' => '2011-04-01', 'valid_to' => null, 'legal_reference' => 'Ley 29667'],
            ['tax' => 'bancarization_threshold_pen', 'code' => null, 'rate' => null, 'amount' => 2000.00, 'min_operation' => null, 'valid_from' => '2022-04-01', 'valid_to' => null, 'legal_reference' => 'DL 1529'],
            ['tax' => 'bancarization_threshold_usd', 'code' => null, 'rate' => null, 'amount' => 500.00, 'min_operation' => null, 'valid_from' => '2022-04-01', 'valid_to' => null, 'legal_reference' => 'DL 1529'],
        ]);
    }

    private function makeService(): MovementService
    {
        $repo = new TaxRateRepository();
        $itf = new ItfCalculator($repo);
        $bancarization = new BancarizationChecker($repo);
        $evidence = new PaymentEvidenceService($bancarization);

        return new MovementService($repo, $itf, $bancarization, $evidence);
    }

    public function test_creates_usd_bank_expense_with_fx_linked_itf_bank_fee_and_evidence(): void
    {
        $service = $this->makeService();

        $result = $service->createManual([
            'establishment_id' => 1,
            'date' => '2026-11-22',
            'kind' => 'expense',
            'destination_type' => 'bank_account',
            'destination_id' => 2,
            'currency_type_id' => 'USD',
            'amount' => 1000.005, // rounds to 1000.01
            'payment_method_type_id' => '04',
            'operation_number' => 'OP-88412',
            'counterparty_document' => '20100070970',
            'counterparty_name' => 'Proveedor SAC',
            'treasury_concept_id' => 2,
            'description' => 'Pago de servicio cloud USD',
            'include_itf' => true,
            'bank_fee_amount' => 15.00,
        ], 7);

        /** @var TreasuryMovement $movement */
        $movement = $result['movement'];
        $this->assertSame('expense', $movement->kind);
        $this->assertSame(-1, $movement->direction);
        $this->assertSame(BankAccount::class, $movement->destination_type);
        $this->assertSame(2, (int) $movement->destination_id);
        $this->assertSame('USD', $movement->currency_type_id);
        $this->assertEqualsWithDelta(1000.01, $movement->amount, 0.0001);
        // Outflow uses SBS sale rate from latest prior date (2026-11-20 -> 3.7600)
        $this->assertEqualsWithDelta(3.76, $movement->exchange_rate_used, 0.0001);
        $this->assertEqualsWithDelta(3760.04, $movement->amount_pen, 0.0001);
        $this->assertSame(7, (int) $movement->user_id);

        // Two linked child movements: ITF (1000.01 * 0.00005 = 0.05) and bank_fee (15.00)
        $this->assertCount(2, $result['related_movements']);
        $itfRow = collect($result['related_movements'])->firstWhere('kind', 'itf');
        $feeRow = collect($result['related_movements'])->firstWhere('kind', 'bank_fee');
        $this->assertNotNull($itfRow);
        $this->assertEqualsWithDelta(0.05, $itfRow->amount, 0.0001);
        $this->assertSame($movement->id, (int) $itfRow->related_movement_id);
        $this->assertNotNull($feeRow);
        $this->assertEqualsWithDelta(15.00, $feeRow->amount, 0.0001);
        $this->assertSame($movement->id, (int) $feeRow->related_movement_id);

        // Evidence created automatically when sunat_payment_code + operation_number present
        $this->assertInstanceOf(PaymentEvidence::class, $result['evidence']);
        $this->assertSame('003', $result['evidence']->sunat_payment_code);
        $this->assertSame('OP-88412', $result['evidence']->operation_number);

        // Now void parent movement -> voids parent + both child rows atomically
        $voidResult = $service->void($movement->id, 'Duplicado por error de digitacion', 9);
        $this->assertSame(3, $voidResult['count']);
        $this->assertSame(0, TreasuryMovement::active()->count());
        $this->assertSame(3, TreasuryMovement::where('state', 'voided')->where('voided_by', 9)->count());

        // Double void rejected
        $this->expectException(ValidationException::class);
        $service->void($movement->id, 'Segundo intento', 9);
    }

    public function test_enforces_bancarization_exception_for_cash_above_threshold(): void
    {
        $service = $this->makeService();

        // Missing art. 6 exception for S/ 2,500 cash payment -> ValidationException
        try {
            $service->createManual([
                'establishment_id' => 1,
                'date' => '2026-11-22',
                'kind' => 'expense',
                'destination_type' => 'cash',
                'destination_id' => 1,
                'currency_type_id' => 'PEN',
                'amount' => 2500.00,
                'payment_method_type_id' => '01',
                'treasury_concept_id' => 2,
                'description' => 'Pago en efectivo mayor al umbral',
            ], 1);
            $this->fail('Expected ValidationException when bancarization exception is omitted.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('bancarization_exception', $e->errors());
        }

        // Supplying valid art. 6 exception succeeds and persists payment_evidence with code 009
        $created = $service->createManual([
            'establishment_id' => 1,
            'date' => '2026-11-22',
            'kind' => 'expense',
            'destination_type' => 'cash',
            'destination_id' => 1,
            'currency_type_id' => 'PEN',
            'amount' => 2500.00,
            'payment_method_type_id' => '01',
            'bancarization_exception' => 'no_bank_district',
            'treasury_concept_id' => 2,
            'description' => 'Pago en distrito sin agencia bancaria',
        ], 1);

        $this->assertSame('active', $created['movement']->state);
        $this->assertNotNull($created['evidence']);
        $this->assertSame('no_bank_district', $created['evidence']->bancarization_exception);
    }

    public function test_rejects_future_date_currency_mismatch_inactive_bank_and_closed_cash(): void
    {
        $service = $this->makeService();
        $futureDate = date('Y-m-d', strtotime('+5 days'));

        // Future date blocked by default
        try {
            $service->createManual([
                'establishment_id' => 1,
                'date' => $futureDate,
                'kind' => 'income',
                'destination_type' => 'bank_account',
                'destination_id' => 1,
                'currency_type_id' => 'PEN',
                'amount' => 100,
                'treasury_concept_id' => 1,
                'description' => 'Futuro',
            ], 1);
            $this->fail('Expected ValidationException for future date.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('date', $e->errors());
        }

        // Allowing future dates via config unlocks it
        $this->config->set('treasury.allow_future_dates', true);
        $futureOk = $service->createManual([
            'establishment_id' => 1,
            'date' => $futureDate,
            'kind' => 'income',
            'destination_type' => 'bank_account',
            'destination_id' => 1,
            'currency_type_id' => 'PEN',
            'amount' => 100,
            'treasury_concept_id' => 1,
            'description' => 'Futuro permitido',
        ], 1);
        $this->assertSame($futureDate, $futureOk['movement']->date->format('Y-m-d'));
        $this->config->set('treasury.allow_future_dates', false);

        // Currency mismatch (USD on PEN account)
        try {
            $service->createManual([
                'establishment_id' => 1,
                'date' => '2026-11-22',
                'kind' => 'income',
                'destination_type' => 'bank_account',
                'destination_id' => 1,
                'currency_type_id' => 'USD',
                'amount' => 100,
                'treasury_concept_id' => 1,
                'description' => 'Moneda distinta',
            ], 1);
            $this->fail('Expected ValidationException for currency mismatch.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('currency_type_id', $e->errors());
        }

        // Inactive bank account
        try {
            $service->createManual([
                'establishment_id' => 1,
                'date' => '2026-11-22',
                'kind' => 'income',
                'destination_type' => 'bank_account',
                'destination_id' => 4,
                'currency_type_id' => 'PEN',
                'amount' => 100,
                'treasury_concept_id' => 1,
                'description' => 'Cuenta inactiva',
            ], 1);
            $this->fail('Expected ValidationException for inactive bank account.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('destination_id', $e->errors());
        }

        // Closed POS cash
        try {
            $service->createManual([
                'establishment_id' => 1,
                'date' => '2026-11-22',
                'kind' => 'income',
                'destination_type' => 'cash',
                'destination_id' => 2,
                'currency_type_id' => 'PEN',
                'amount' => 100,
                'treasury_concept_id' => 1,
                'description' => 'Caja cerrada',
            ], 1);
            $this->fail('Expected ValidationException for closed cash.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('destination_id', $e->errors());
        }
    }

    public function test_adjustment_requires_explicit_direction_and_bn_account_skips_itf(): void
    {
        $service = $this->makeService();

        // Missing direction on adjustment fails
        try {
            $service->createManual([
                'establishment_id' => 1,
                'date' => '2026-11-22',
                'kind' => 'adjustment',
                'destination_type' => 'bank_account',
                'destination_id' => 3,
                'currency_type_id' => 'PEN',
                'amount' => 50,
                'treasury_concept_id' => 1,
                'description' => 'Ajuste sin direccion',
            ], 1);
            $this->fail('Expected ValidationException when adjustment direction is missing.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('direction', $e->errors());
        }

        // Explicit direction +1 on ITF-exempt BN account skips automatic ITF
        $created = $service->createManual([
            'establishment_id' => 1,
            'date' => '2026-11-22',
            'kind' => 'adjustment',
            'direction' => 1,
            'destination_type' => 'bank_account',
            'destination_id' => 3,
            'currency_type_id' => 'PEN',
            'amount' => 10000.00,
            'treasury_concept_id' => 1,
            'description' => 'Ajuste BN exento',
            'include_itf' => true,
        ], 1);

        $this->assertSame(1, $created['movement']->direction);
        $this->assertCount(0, $created['related_movements']);
    }

    public function test_blocks_voiding_closed_cash_movement_backdated_cash_establishment_mismatch_and_saves_nested_evidence(): void
    {
        $service = $this->makeService();
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        $beforeOpening = date('Y-m-d', strtotime('-5 days'));

        // 1. Reject movement dated before POS cash opening
        try {
            $service->createManual([
                'establishment_id' => 1,
                'date' => $beforeOpening,
                'kind' => 'income',
                'destination_type' => 'cash',
                'destination_id' => 1,
                'currency_type_id' => 'PEN',
                'amount' => 100.00,
                'payment_method_type_id' => '01',
                'treasury_concept_id' => 1,
                'description' => 'Anterior a apertura',
            ], 1);
            $this->fail('Expected ValidationException for cash movement before date_opening.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('date', $e->errors());
        }

        // 2. Reject mismatched establishment_id for scoped BankAccount
        try {
            $service->createManual([
                'establishment_id' => 2,
                'date' => $yesterday,
                'kind' => 'income',
                'destination_type' => 'bank_account',
                'destination_id' => 1,
                'currency_type_id' => 'PEN',
                'amount' => 100.00,
                'treasury_concept_id' => 1,
                'description' => 'Sede distinta a la cuenta',
            ], 1);
            $this->fail('Expected ValidationException for bank account establishment mismatch.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('establishment_id', $e->errors());
        }

        // 3. Explicit evidence.sunat_payment_code works even when top-level sunat_payment_code is omitted,
        //    and omitting establishment_id derives it from BankAccount.establishment_id
        $nestedEvidenceResult = $service->createManual([
            'date' => $yesterday,
            'kind' => 'income',
            'destination_type' => 'bank_account',
            'destination_id' => 1,
            'currency_type_id' => 'PEN',
            'amount' => 300.00,
            'treasury_concept_id' => 1,
            'description' => 'Con evidencia anidada',
            'evidence' => [
                'sunat_payment_code' => '001',
                'operation_number' => 'DEP-777',
            ],
        ], 1);
        $this->assertSame(1, (int) $nestedEvidenceResult['movement']->establishment_id);
        $this->assertInstanceOf(PaymentEvidence::class, $nestedEvidenceResult['evidence']);
        $this->assertSame('001', $nestedEvidenceResult['evidence']->sunat_payment_code);
        $this->assertSame('DEP-777', $nestedEvidenceResult['evidence']->operation_number);

        // 4. Reject explicit itf_amount above Money::MAX_AMOUNT
        try {
            $service->createManual([
                'establishment_id' => 1,
                'date' => $yesterday,
                'kind' => 'expense',
                'destination_type' => 'bank_account',
                'destination_id' => 1,
                'currency_type_id' => 'PEN',
                'amount' => 100.00,
                'treasury_concept_id' => 2,
                'description' => 'ITF fuera de rango',
                'include_itf' => true,
                'itf_amount' => 99999999999.00,
            ], 1);
            $this->fail('Expected ValidationException for out-of-range itf_amount.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('itf_amount', $e->errors());
        }

        // 5. Create movement on open cash #1, then close cash #1, then attempt void -> MUST reject!
        $cashMov = $service->createManual([
            'establishment_id' => 1,
            'date' => $yesterday,
            'kind' => 'income',
            'destination_type' => 'cash',
            'destination_id' => 1,
            'currency_type_id' => 'PEN',
            'amount' => 80.00,
            'payment_method_type_id' => '01',
            'treasury_concept_id' => 1,
            'description' => 'Ingreso en caja antes de cierre',
        ], 1);

        $this->db->table('cash')->where('id', 1)->update(['state' => 0, 'date_closed' => $yesterday]);

        try {
            $service->void($cashMov['movement']->id, 'Intento de anular tras cierre', 1);
            $this->fail('Expected ValidationException when voiding movement on closed cash.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('movement', $e->errors());
            $this->assertSame('active', TreasuryMovement::find($cashMov['movement']->id)->state);
        }
    }
}
