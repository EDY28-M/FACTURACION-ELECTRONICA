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
use Modules\Treasury\Models\TreasuryMovement;
use Modules\Treasury\Services\BancarizationChecker;
use Modules\Treasury\Services\ItfCalculator;
use Modules\Treasury\Services\MovementService;
use Modules\Treasury\Services\PaymentEvidenceService;
use Modules\Treasury\Services\TaxRateRepository;
use Modules\Treasury\Services\TransferService;
use Modules\Treasury\Services\TreasuryBalanceService;
use PHPUnit\Framework\TestCase;

class TransferServiceTest extends TestCase
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
                    'bindings' => $query->getBindings(),
                    'lock' => $value,
                    'transaction' => $query->getConnection()->transactionLevel(),
                ];

                return '';
            }
        };
        $this->db->setQueryGrammar($this->grammar);

        foreach ([
            'banks (id integer primary key autoincrement, description text)',
            'establishments (id integer primary key autoincrement, description text)',
            'users (id integer primary key autoincrement, name text, establishment_id integer null)',
            'bank_accounts (id integer primary key autoincrement, bank_id integer, description text, number text, currency_type_id text, cci text null, status integer, initial_balance numeric, show_in_documents integer, account_type text, pcge_account text null, itf_exempt integer, establishment_id integer null)',
            'cash (id integer primary key autoincrement, user_id integer, date_opening text, time_opening text, date_closed text null, time_closed text null, beginning_balance numeric, final_balance numeric, income numeric, state integer, reference_number text null, created_at text null, updated_at text null)',
            'exchange_rates (id integer primary key autoincrement, date text, purchase numeric, sale numeric, date_original text null, purchase_original numeric null, sale_original numeric null, created_at text null, updated_at text null)',
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
        $this->db->table('banks')->insert([
            ['id' => 1, 'description' => 'BCP'],
            ['id' => 2, 'description' => 'BBVA'],
        ]);
        $this->db->table('establishments')->insert([
            ['id' => 1, 'description' => 'Oficina Principal'],
        ]);
        $this->db->table('users')->insert([
            ['id' => 1, 'name' => 'Cajero 1', 'establishment_id' => 1],
        ]);

        $this->db->table('bank_accounts')->insert([
            ['id' => 1, 'bank_id' => 1, 'description' => 'BCP PEN', 'number' => '191-001', 'currency_type_id' => 'PEN', 'status' => 1, 'initial_balance' => 1500.00, 'show_in_documents' => 1, 'account_type' => 'checking', 'itf_exempt' => 0, 'establishment_id' => 1],
            ['id' => 2, 'bank_id' => 2, 'description' => 'BBVA PEN', 'number' => '011-002', 'currency_type_id' => 'PEN', 'status' => 1, 'initial_balance' => 500.00, 'show_in_documents' => 1, 'account_type' => 'checking', 'itf_exempt' => 0, 'establishment_id' => 1],
            ['id' => 3, 'bank_id' => 1, 'description' => 'BCP USD', 'number' => '191-003', 'currency_type_id' => 'USD', 'status' => 1, 'initial_balance' => 200.00, 'show_in_documents' => 1, 'account_type' => 'checking', 'itf_exempt' => 0, 'establishment_id' => 1],
        ]);

        $openingDate = date('Y-m-d', strtotime('-3 days'));
        $closedDate = date('Y-m-d', strtotime('-4 days'));
        $yesterday = date('Y-m-d', strtotime('-1 day'));

        $this->db->table('cash')->insert([
            ['id' => 1, 'user_id' => 1, 'date_opening' => $openingDate, 'time_opening' => '08:00:00', 'date_closed' => null, 'time_closed' => null, 'beginning_balance' => 300.00, 'final_balance' => 0, 'income' => 0, 'state' => 1, 'reference_number' => 'POS-1'],
            ['id' => 2, 'user_id' => 1, 'date_opening' => $closedDate, 'time_opening' => '08:00:00', 'date_closed' => $closedDate, 'time_closed' => '18:00:00', 'beginning_balance' => 100.00, 'final_balance' => 100.00, 'income' => 0, 'state' => 0, 'reference_number' => 'POS-CLOSED'],
        ]);

        $this->db->table('exchange_rates')->insert([
            ['date' => $yesterday, 'purchase' => 3.7000, 'sale' => 3.7500],
        ]);

        $this->db->table('treasury_tax_rates')->insert([
            ['tax' => 'itf', 'code' => null, 'rate' => 0.00005, 'amount' => null, 'min_operation' => null, 'valid_from' => '2011-04-01', 'valid_to' => null, 'legal_reference' => 'Ley 29667'],
            ['tax' => 'bancarization_threshold_pen', 'code' => null, 'rate' => null, 'amount' => 2000.00, 'min_operation' => null, 'valid_from' => '2022-04-01', 'valid_to' => null, 'legal_reference' => 'DL 1529'],
            ['tax' => 'bancarization_threshold_usd', 'code' => null, 'rate' => null, 'amount' => 500.00, 'min_operation' => null, 'valid_from' => '2022-04-01', 'valid_to' => null, 'legal_reference' => 'DL 1529'],
        ]);
    }

    private function makeServices(): array
    {
        $repo = new TaxRateRepository();
        $itf = new ItfCalculator($repo);
        $bancarization = new BancarizationChecker($repo);
        $evidence = new PaymentEvidenceService($bancarization);

        return [
            new TransferService($itf, $evidence),
            new MovementService($repo, $itf, $bancarization, $evidence),
            new TreasuryBalanceService(),
        ];
    }

    public function test_same_currency_bank_transfer_locks_deterministically_keeps_consolidated_total_and_void_restores_balances(): void
    {
        [$transferService, $movementService, $balanceService] = $this->makeServices();

        $before = $balanceService->getSummary();
        // 1500 + 500 + (200 * 3.70 = 740) = 2740.00
        $this->assertSame('2740.00', $before['totals']['consolidated_pen']);

        // Transfer from account 2 -> account 1 (reverse ID order) to verify deterministic ascending lock order!
        $this->grammar->locks = [];
        $result = $transferService->create([
            'establishment_id' => 1,
            'date' => '2026-11-22',
            'origin_type' => 'bank_account',
            'origin_id' => 2,
            'target_type' => 'bank_account',
            'target_id' => 1,
            'amount' => 250.00,
            'description' => 'Traslado BBVA a BCP',
            'same_holder' => true,
            'include_itf' => true, // ignored because same_holder = true
        ], 1);

        $this->assertNotEmpty($result['transfer_group']);
        $this->assertSame($result['transfer_group'], $result['out']->transfer_group);
        $this->assertSame($result['transfer_group'], $result['in']->transfer_group);
        $this->assertCount(0, $result['related_movements']);

        // Verify deterministic lockForUpdate order (account 1 locked BEFORE account 2 inside transaction level 1)
        $bankLocks = array_values(array_filter($this->grammar->locks, fn ($l) => $l['table'] === 'bank_accounts'));
        $this->assertCount(2, $bankLocks);
        $this->assertTrue($bankLocks[0]['lock']);
        $this->assertSame(1, $bankLocks[0]['transaction']);
        $this->assertSame([1], $bankLocks[0]['bindings']);
        $this->assertSame([2], $bankLocks[1]['bindings']);

        $after = $balanceService->getSummary();
        // Consolidated PEN total is unchanged!
        $this->assertSame($before['totals']['consolidated_pen'], $after['totals']['consolidated_pen']);

        $byId = collect($after['bank_accounts'])->keyBy('id');
        $this->assertSame('1750.00', $byId[1]['balance_currency']);
        $this->assertSame('250.00', $byId[2]['balance_currency']);

        // Void transfer from the `in` leg -> voids BOTH legs and restores exact initial balances
        $voidRes = $movementService->void($result['in']->id, 'Error en cuenta destino', 1);
        $this->assertSame(2, $voidRes['count']);

        $restored = $balanceService->getSummary();
        $restoredById = collect($restored['bank_accounts'])->keyBy('id');
        $this->assertSame('1500.00', $restoredById[1]['balance_currency']);
        $this->assertSame('500.00', $restoredById[2]['balance_currency']);
        $this->assertSame($before['totals']['consolidated_pen'], $restored['totals']['consolidated_pen']);
    }

    public function test_cross_currency_transfer_records_exchange_rate_and_linked_charges(): void
    {
        [$transferService, $movementService] = $this->makeServices();

        // Transfer USD 100.00 (Bank 3) -> PEN (Bank 1) at explicit rate 3.72 -> PEN 372.00 + non-same-holder ITF + fee
        $result = $transferService->create([
            'establishment_id' => 1,
            'date' => '2026-11-22',
            'origin_type' => 'bank_account',
            'origin_id' => 3,
            'target_type' => 'bank_account',
            'target_id' => 1,
            'amount' => 100.00,
            'exchange_rate_used' => 3.72,
            'description' => 'Cambio de dolares a soles',
            'same_holder' => false,
            'include_itf' => true,
            'bank_fee_amount' => 2.50,
        ], 1);

        $this->assertSame('USD', $result['out']->currency_type_id);
        $this->assertEqualsWithDelta(100.00, $result['out']->amount, 0.0001);
        $this->assertEqualsWithDelta(372.00, $result['out']->amount_pen, 0.0001);

        $this->assertSame('PEN', $result['in']->currency_type_id);
        $this->assertEqualsWithDelta(372.00, $result['in']->amount, 0.0001);
        $this->assertEqualsWithDelta(372.00, $result['in']->amount_pen, 0.0001);
        $this->assertEqualsWithDelta(3.72, $result['in']->exchange_rate_used, 0.0001);

        // ITF on 100.00 * 0.00005 = 0.005 -> rounds to 0.01 USD; bank_fee = 2.50 USD
        $this->assertCount(2, $result['related_movements']);

        // Voiding transfer voids both transfer legs + both related charges (4 movements total)
        $voidRes = $movementService->void($result['out']->id, 'Operacion revertida por banco', 1);
        $this->assertSame(4, $voidRes['count']);
        $this->assertSame(0, TreasuryMovement::active()->count());
    }

    public function test_cash_to_bank_and_bank_to_cash_require_open_cash_and_reject_same_account(): void
    {
        [$transferService] = $this->makeServices();

        // Cash -> Bank with open cash succeeds
        $deposit = $transferService->create([
            'establishment_id' => 1,
            'date' => '2026-11-22',
            'origin_type' => 'cash',
            'origin_id' => 1,
            'target_type' => 'bank_account',
            'target_id' => 1,
            'amount' => 120.00,
            'description' => 'Deposito de caja a BCP',
        ], 1);

        $this->assertSame(Cash::class, $deposit['out']->destination_type);
        $this->assertSame(BankAccount::class, $deposit['in']->destination_type);

        // Closed cash rejected
        try {
            $transferService->create([
                'establishment_id' => 1,
                'date' => '2026-11-22',
                'origin_type' => 'bank_account',
                'origin_id' => 1,
                'target_type' => 'cash',
                'target_id' => 2,
                'amount' => 50.00,
                'description' => 'Retiro a caja cerrada',
            ], 1);
            $this->fail('Expected ValidationException for closed POS cash.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('target_id', $e->errors());
        }

        // Same origin & target rejected
        try {
            $transferService->create([
                'establishment_id' => 1,
                'date' => date('Y-m-d', strtotime('-1 day')),
                'origin_type' => 'bank_account',
                'origin_id' => 1,
                'target_type' => 'bank_account',
                'target_id' => 1,
                'amount' => 50.00,
                'description' => 'Misma cuenta',
            ], 1);
            $this->fail('Expected ValidationException for identical origin and target.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('target_id', $e->errors());
        }

        // Backdated transfer prior to POS cash opening rejected
        try {
            $transferService->create([
                'establishment_id' => 1,
                'date' => date('Y-m-d', strtotime('-5 days')),
                'origin_type' => 'cash',
                'origin_id' => 1,
                'target_type' => 'bank_account',
                'target_id' => 1,
                'amount' => 50.00,
                'description' => 'Fecha anterior a apertura de caja',
            ], 1);
            $this->fail('Expected ValidationException for transfer dated before cash opening.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('date', $e->errors());
        }

        // Closing cash #1 blocks voiding the earlier cash->bank transfer (even via the bank leg!)
        [, $movementService] = $this->makeServices();
        $this->db->table('cash')->where('id', 1)->update(['state' => 0, 'date_closed' => date('Y-m-d')]);

        try {
            $movementService->void($deposit['in']->id, 'Intento de anular tras cierre', 1);
            $this->fail('Expected ValidationException when voiding transfer linked to closed cash.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('movement', $e->errors());
            $this->assertSame(2, TreasuryMovement::where('transfer_group', $deposit['transfer_group'])->active()->count());
        }
    }
}
