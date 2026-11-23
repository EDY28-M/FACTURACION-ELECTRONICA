<?php

namespace Tests\Unit\Treasury;

use App\Http\Controllers\Tenant\CashController;
use App\Models\Tenant\Cash;
use App\Models\Tenant\Document;
use App\Models\Tenant\SaleNote;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\SQLiteGrammar;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Facade;
use Modules\Pos\Models\CashTransaction;
use PHPUnit\Framework\TestCase;

/**
 * Characterization tests for App\Http\Controllers\Tenant\CashController::close().
 *
 * Freezes the current cash closing balance computation, state transitions,
 * expense state_type_id === '05' filtering quirk, ignoring of global_payments
 * and cash_transactions, 422 response on re-close, and user ownership enforcement.
 */
class CashCloseCharacterizationTest extends TestCase
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

        $this->app->instance('config', new Repository());

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

                return ''; // SQLite does not support FOR UPDATE; record locking intent only.
            }
        };
        $this->db->setQueryGrammar($this->grammar);

        $this->auth = new class {
            public $id = 1;
            public $type = 'seller';

            public function user()
            {
                return (object) ['id' => $this->id, 'type' => $this->type];
            }

            public function id()
            {
                return $this->id;
            }
        };
        $this->app->instance(AuthFactory::class, $this->auth);

        $this->app->instance(ResponseFactory::class, new class {
            public function json($data = [], $status = 200, array $headers = [], $options = 0): JsonResponse
            {
                return new JsonResponse($data, $status, $headers, $options);
            }
        });

        // Strip unrelated catalog $with eager loads on Document and SaleNote only.
        foreach ([Document::class, SaleNote::class] as $model) {
            $model::addGlobalScope('cash-close-fixture', function ($query) {
                $query->setEagerLoads([]);
            });
        }

        foreach ([
            'cash (id integer primary key autoincrement, user_id integer, date_opening text, time_opening text, date_closed text null, time_closed text null, beginning_balance numeric, final_balance numeric, income numeric, state integer, reference_number text null, created_at text null, updated_at text null)',
            'cash_documents (id integer primary key autoincrement, cash_id integer, document_id integer null, sale_note_id integer null, expense_payment_id integer null)',
            'sale_notes (id integer primary key autoincrement, state_type_id text, currency_type_id text, exchange_rate_sale numeric, total numeric, created_at text null, updated_at text null)',
            'documents (id integer primary key autoincrement, state_type_id text, currency_type_id text, exchange_rate_sale numeric, total numeric, created_at text null, updated_at text null)',
            'expenses (id integer primary key autoincrement, state_type_id text, currency_type_id text, exchange_rate_sale numeric, total numeric, created_at text null, updated_at text null)',
            'expense_payments (id integer primary key autoincrement, expense_id integer, payment numeric, date_of_payment text null)',
            'cash_transactions (id integer primary key autoincrement, cash_id integer, payment_method_type_id text null, date text null, description text null, payment numeric)',
            'global_payments (id integer primary key autoincrement, soap_type_id text null, destination_id integer, destination_type text, payment_id integer, payment_type text, user_id integer null, created_at text null, updated_at text null)',
        ] as $table) {
            $this->db->statement('create table ' . $table);
        }
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

    private function seedOpenCash(int $id = 1, int $userId = 1, float $beginningBalance = 200.00): void
    {
        $this->db->table('cash')->insert([
            'id' => $id,
            'user_id' => $userId,
            'date_opening' => '2026-11-20',
            'time_opening' => '08:00:00',
            'date_closed' => null,
            'time_closed' => null,
            'beginning_balance' => $beginningBalance,
            'final_balance' => 0,
            'income' => 0,
            'state' => 1,
            'reference_number' => 'CAJA-01',
        ]);
    }

    public function test_close_computes_net_income_and_final_balance_from_cash_documents_only(): void
    {
        $this->seedOpenCash(1, 1, 200.00);

        // Sale notes: accepted PEN (+120.50), accepted USD (+50 * 3.80 = +190.00), voided '11' (ignored)
        $this->db->table('sale_notes')->insert([
            ['id' => 1, 'state_type_id' => '05', 'currency_type_id' => 'PEN', 'exchange_rate_sale' => 3.80, 'total' => 120.50],
            ['id' => 2, 'state_type_id' => '01', 'currency_type_id' => 'USD', 'exchange_rate_sale' => 3.80, 'total' => 50.00],
            ['id' => 3, 'state_type_id' => '11', 'currency_type_id' => 'PEN', 'exchange_rate_sale' => 1.00, 'total' => 999.00],
        ]);

        // Documents: accepted PEN (+300.25), accepted USD (+20 * 3.75 = +75.00), rejected '09' (ignored)
        $this->db->table('documents')->insert([
            ['id' => 1, 'state_type_id' => '05', 'currency_type_id' => 'PEN', 'exchange_rate_sale' => 3.75, 'total' => 300.25],
            ['id' => 2, 'state_type_id' => '03', 'currency_type_id' => 'USD', 'exchange_rate_sale' => 3.75, 'total' => 20.00],
            ['id' => 3, 'state_type_id' => '09', 'currency_type_id' => 'PEN', 'exchange_rate_sale' => 1.00, 'total' => 888.00],
        ]);

        // Expenses:
        // - state '05' PEN (total 100.00, partial payment 45.10) -> deducts expense_payment->payment (-45.10), NOT expense->total
        // - state '05' USD (total 25.00, partial payment 10.00 * 3.75 = -37.50) -> deducts payment * exchange_rate_sale
        // - state '01' PEN (60.00) -> NOT subtracted (characterizes quirk that only '05' is deducted)
        $this->db->table('expenses')->insert([
            ['id' => 1, 'state_type_id' => '05', 'currency_type_id' => 'PEN', 'exchange_rate_sale' => 3.75, 'total' => 100.00],
            ['id' => 2, 'state_type_id' => '05', 'currency_type_id' => 'USD', 'exchange_rate_sale' => 3.75, 'total' => 25.00],
            ['id' => 3, 'state_type_id' => '01', 'currency_type_id' => 'PEN', 'exchange_rate_sale' => 1.00, 'total' => 60.00],
        ]);
        $this->db->table('expense_payments')->insert([
            ['id' => 1, 'expense_id' => 1, 'payment' => 45.10, 'date_of_payment' => '2026-11-20'],
            ['id' => 2, 'expense_id' => 2, 'payment' => 10.00, 'date_of_payment' => '2026-11-20'],
            ['id' => 3, 'expense_id' => 3, 'payment' => 60.00, 'date_of_payment' => '2026-11-20'],
        ]);

        $this->db->table('cash_documents')->insert([
            ['cash_id' => 1, 'sale_note_id' => 1, 'document_id' => null, 'expense_payment_id' => null],
            ['cash_id' => 1, 'sale_note_id' => 2, 'document_id' => null, 'expense_payment_id' => null],
            ['cash_id' => 1, 'sale_note_id' => 3, 'document_id' => null, 'expense_payment_id' => null],
            ['cash_id' => 1, 'sale_note_id' => null, 'document_id' => 1, 'expense_payment_id' => null],
            ['cash_id' => 1, 'sale_note_id' => null, 'document_id' => 2, 'expense_payment_id' => null],
            ['cash_id' => 1, 'sale_note_id' => null, 'document_id' => 3, 'expense_payment_id' => null],
            ['cash_id' => 1, 'sale_note_id' => null, 'document_id' => null, 'expense_payment_id' => 1],
            ['cash_id' => 1, 'sale_note_id' => null, 'document_id' => null, 'expense_payment_id' => 2],
            ['cash_id' => 1, 'sale_note_id' => null, 'document_id' => null, 'expense_payment_id' => 3],
        ]);

        // Unrelated cash_transactions and global_payments pointing to this cash MUST be ignored by close().
        $this->db->table('cash_transactions')->insert([
            'id' => 1,
            'cash_id' => 1,
            'payment_method_type_id' => '01',
            'date' => '2026-11-20',
            'description' => 'Manual deposit',
            'payment' => 500.00,
        ]);
        $this->db->table('global_payments')->insert([
            'soap_type_id' => '02',
            'destination_id' => 1,
            'destination_type' => Cash::class,
            'payment_id' => 1,
            'payment_type' => CashTransaction::class,
            'user_id' => 1,
        ]);

        $response = (new CashController())->close(1);

        $this->assertSame([
            'success' => true,
            'message' => 'Caja cerrada con éxito',
        ], $response);

        $closed = $this->db->table('cash')->where('id', 1)->first();

        // Net = 120.50 + 190.00 + 300.25 + 75.00 - 45.10 - 37.50 = 603.15
        // income stores net (603.15); final_balance = net + beginning_balance (803.15)
        $this->assertEqualsWithDelta(603.15, (float) $closed->income, 0.0001);
        $this->assertEqualsWithDelta(803.15, (float) $closed->final_balance, 0.0001);
        $this->assertSame(0, (int) $closed->state);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $closed->date_closed);
        $this->assertMatchesRegularExpression('/^\d{2}:\d{2}:\d{2}$/', $closed->time_closed);

        // Verify lockForUpdate() was requested on the cash table inside an active transaction.
        $cashLocks = array_values(array_filter($this->grammar->locks, fn ($lock) => $lock['table'] === 'cash'));
        $this->assertNotEmpty($cashLocks);
        $this->assertTrue($cashLocks[0]['lock']);
        $this->assertSame(1, $cashLocks[0]['transaction']);
        $this->assertSame(0, $this->db->transactionLevel());
    }

    public function test_closing_already_closed_cash_returns_422_and_preserves_existing_values(): void
    {
        $this->db->table('cash')->insert([
            'id' => 1,
            'user_id' => 1,
            'date_opening' => '2026-11-19',
            'time_opening' => '08:00:00',
            'date_closed' => '2026-11-19',
            'time_closed' => '18:30:00',
            'beginning_balance' => 100.00,
            'final_balance' => 250.00,
            'income' => 150.00,
            'state' => 0,
        ]);

        $response = (new CashController())->close(1);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame([
            'success' => false,
            'message' => 'La caja ya se encuentra cerrada.',
        ], $response->getData(true));

        $row = $this->db->table('cash')->where('id', 1)->first();
        $this->assertEqualsWithDelta(250.00, (float) $row->final_balance, 0.0001);
        $this->assertEqualsWithDelta(150.00, (float) $row->income, 0.0001);
        $this->assertSame('2026-11-19', $row->date_closed);
        $this->assertSame('18:30:00', $row->time_closed);
    }

    public function test_non_admin_cannot_close_another_users_cash_while_admin_can(): void
    {
        $this->seedOpenCash(1, 2, 75.00);

        $this->auth->id = 1;
        $this->auth->type = 'seller';

        $caught = null;
        try {
            (new CashController())->close(1);
        } catch (ModelNotFoundException $exception) {
            $caught = $exception;
        }

        $this->assertInstanceOf(ModelNotFoundException::class, $caught);
        $this->assertSame(1, (int) $this->db->table('cash')->where('id', 1)->value('state'));

        $this->auth->id = 99;
        $this->auth->type = 'admin';

        $response = (new CashController())->close(1);
        $this->assertSame([
            'success' => true,
            'message' => 'Caja cerrada con éxito',
        ], $response);
        $this->assertSame(0, (int) $this->db->table('cash')->where('id', 1)->value('state'));
        $this->assertEqualsWithDelta(75.00, (float) $this->db->table('cash')->where('id', 1)->value('final_balance'), 0.0001);
    }
}
