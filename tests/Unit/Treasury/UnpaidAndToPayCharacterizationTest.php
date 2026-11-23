<?php

namespace Tests\Unit\Treasury;

use App\Models\Tenant\Dispatch;
use App\Models\Tenant\DocumentPayment;
use App\Models\Tenant\Invoice;
use App\Models\Tenant\PurchasePayment;
use App\Models\Tenant\SaleNotePayment;
use Carbon\Carbon;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use Modules\Dashboard\Helpers\DashboardView;
use Modules\Expense\Models\ExpensePayment;
use Modules\Finance\Helpers\ToPay;
use Modules\Finance\Http\Resources\UnpaidCollection;
use Modules\Finance\Traits\UnpaidTrait;
use PHPUnit\Framework\TestCase;

/**
 * Characterization tests for accounts receivable (DashboardView::getUnpaidFilterUser,
 * UnpaidTrait::transformRecords, UnpaidCollection::toArray) and accounts payable
 * (ToPay::getToPay, ToPay::getToPayNoFilter).
 */
class UnpaidAndToPayCharacterizationTest extends TestCase
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

        $this->app->instance('config', new Repository());

        $manager = new Manager($this->app);
        $manager->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''], 'tenant');
        $database = $manager->getDatabaseManager();
        $database->setDefaultConnection('tenant');
        $this->app->instance('db', $database);

        Model::setConnectionResolver($database);
        Model::setEventDispatcher(new Dispatcher($this->app));

        $this->db = $database->connection('tenant');
        $pdo = $this->db->getPdo();
        $dateFormatFn = function ($date, $format) {
            if ($date === null) {
                return null;
            }
            $phpFormat = strtr($format, ['%Y' => 'Y', '%m' => 'm', '%d' => 'd']);

            return date($phpFormat, strtotime($date));
        };
        $concatFn = fn (...$parts) => implode('', $parts);

        if (method_exists($pdo, 'createFunction')) {
            $pdo->createFunction('DATE_FORMAT', $dateFormatFn, 2);
            $pdo->createFunction('CONCAT', $concatFn);
        } else {
            $pdo->sqliteCreateFunction('DATE_FORMAT', $dateFormatFn, 2);
            $pdo->sqliteCreateFunction('CONCAT', $concatFn);
        }

        $this->auth = new class {
            public $id = 1;
            public $type = 'admin';

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

        // Minimal URL generator so Dispatch::getDownloadExternal{Xml,Pdf}Attribute() route() calls resolve.
        $this->app->instance('url', new class {
            public function route(string $name, array $parameters = []): string
            {
                return 'https://tenant.test/' . $name . '?' . http_build_query($parameters);
            }
        });

        Carbon::setTestNow(Carbon::parse('2026-11-20 12:00:00'));

        foreach ([
            Invoice::class,
            Dispatch::class,
            DocumentPayment::class,
            SaleNotePayment::class,
            PurchasePayment::class,
            ExpensePayment::class,
        ] as $model) {
            $model::addGlobalScope('unpaid-topay-fixture', function ($query) {
                $query->setEagerLoads([]);
            });
        }

        foreach ([
            'persons (id integer primary key, name text)',
            'users (id integer primary key, name text, type text)',
            'documents (id integer primary key, user_id integer, customer_id integer, establishment_id integer, state_type_id text, document_type_id text, series text, number text, date_of_issue text, total numeric, currency_type_id text, exchange_rate_sale numeric, payment_method_type_id text null)',
            'document_payments (id integer primary key, document_id integer, payment numeric, date_of_payment text)',
            'sale_notes (id integer primary key, user_id integer, customer_id integer, establishment_id integer, state_type_id text, filename text, date_of_issue text, total numeric, currency_type_id text, exchange_rate_sale numeric, changed integer, total_canceled integer)',
            'sale_note_payments (id integer primary key, sale_note_id integer, payment numeric, date_of_payment text)',
            'invoices (id integer primary key, document_id integer, operation_type_id text null, date_of_due text)',
            'dispatches (id integer primary key, reference_document_id integer, external_id text, series text, number text, date_of_issue text, date_of_shipping text)',
            'purchases (id integer primary key, user_id integer, supplier_id integer, state_type_id text, document_type_id text, date_of_issue text, date_of_due text null, series text, number text, total numeric, establishment_id integer, currency_type_id text, exchange_rate_sale numeric)',
            'purchase_payments (id integer primary key, purchase_id integer, payment numeric, date_of_payment text)',
            'expenses (id integer primary key, user_id integer, supplier_id integer, state_type_id text, date_of_issue text, number text, total numeric, establishment_id integer, currency_type_id text, exchange_rate_sale numeric)',
            'expense_payments (id integer primary key, expense_id integer, payment numeric, date_of_payment text)',
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

        Carbon::setTestNow();

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

    public function test_unpaid_query_sql_structure_for_admin_seller_dates_payment_method_and_having_placement(): void
    {
        // 1. Admin with explicit user_id + date range + payment_method_type_id:
        //    includes BOTH session user_id and requested user_id via whereIn.
        $this->auth->id = 1;
        $this->auth->type = 'admin';

        $adminBuilder = DashboardView::getUnpaidFilterUser([
            'establishment_id' => 1,
            'period' => 'between_dates',
            'date_start' => '2026-11-01',
            'date_end' => '2026-11-30',
            'customer_id' => 10,
            'user_id' => 2,
            'payment_method_type_id' => '05',
        ]);

        $adminSql = $adminBuilder->toSql();
        $adminBindings = $adminBuilder->getBindings();

        // Freezes that havingRaw('total_subtraction > 0') is attached to the documents branch before union.
        $havingPos = stripos($adminSql, 'having total_subtraction > 0');
        $unionPos = stripos($adminSql, 'union');
        $this->assertNotFalse($havingPos);
        $this->assertNotFalse($unionPos);
        $this->assertLessThan($unionPos, $havingPos);

        // Core predicates on documents vs sale_notes branches.
        $documentsSql = substr($adminSql, 0, $unionPos);
        $saleNotesSql = substr($adminSql, $unionPos);
        $this->assertStringContainsString('"state_type_id" in (?, ?, ?, ?, ?)', $documentsSql);
        $this->assertStringContainsString('"document_type_id" in (?, ?, ?)', $documentsSql);
        $this->assertStringContainsString('"documents"."establishment_id" = ?', $documentsSql);
        $this->assertStringContainsString('"payment_method_type_id" = ?', $documentsSql);
        $this->assertStringContainsString('"user_id" in (?, ?)', $documentsSql);
        $this->assertStringContainsString('"documents"."date_of_issue" between ? and ?', $documentsSql);

        $this->assertStringContainsString('"state_type_id" in (?, ?, ?, ?, ?)', $saleNotesSql);
        $this->assertStringContainsString('"sale_notes"."establishment_id" = ?', $saleNotesSql);
        $this->assertStringContainsString('"sale_notes"."changed" = ?', $saleNotesSql);
        $this->assertStringContainsString('"sale_notes"."total_canceled" = ?', $saleNotesSql);
        $this->assertStringNotContainsString('payment_method_type_id', $saleNotesSql);
        $this->assertStringContainsString('"user_id" in (?, ?)', $saleNotesSql);
        $this->assertStringContainsString('"sale_notes"."date_of_issue" between ? and ?', $saleNotesSql);

        foreach (['01', '03', '05', '07', '13', '08', '2026-11-01', '2026-11-30', 10, false] as $expectedBinding) {
            $this->assertContains($expectedBinding, $adminBindings);
        }

        // Freezes SQLite incompatibility of ungrouped HAVING on the current query.
        $caught = null;
        try {
            $adminBuilder->get();
        } catch (QueryException $exception) {
            $caught = $exception;
        }
        $this->assertInstanceOf(QueryException::class, $caught);
        $this->assertStringContainsString('HAVING clause on a non-aggregate query', $caught->getMessage());

        // 2. Admin WITHOUT user_id and WITHOUT period (exercises the no-period else branch).
        $adminNoFilterBuilder = DashboardView::getUnpaidFilterUser([
            'establishment_id' => 1,
        ]);
        $noFilterSql = $adminNoFilterBuilder->toSql();
        $noFilterUnionPos = stripos($noFilterSql, 'union');
        $noFilterDocsSql = substr($noFilterSql, 0, $noFilterUnionPos);
        $noFilterNotesSql = substr($noFilterSql, $noFilterUnionPos);

        $this->assertStringNotContainsString('between ? and ?', $noFilterDocsSql);
        $this->assertStringNotContainsString('between ? and ?', $noFilterNotesSql);
        $this->assertStringNotContainsString('"user_id" =', $noFilterDocsSql);
        $this->assertStringNotContainsString('"user_id" in', $noFilterDocsSql);
        $this->assertStringNotContainsString('"user_id" =', $noFilterNotesSql);
        $this->assertStringNotContainsString('"user_id" in', $noFilterNotesSql);
        $this->assertStringContainsString('"sale_notes"."changed" = ?', $noFilterNotesSql);
        $this->assertStringContainsString('"sale_notes"."total_canceled" = ?', $noFilterNotesSql);

        // 3. Seller ignores requested user_id and scopes both branches to session user_id.
        $this->auth->id = 7;
        $this->auth->type = 'seller';

        $sellerBuilder = DashboardView::getUnpaidFilterUser([
            'establishment_id' => 1,
            'period' => 'month',
            'month_start' => '2026-11',
            'user_id' => 99,
        ]);

        $sellerSql = $sellerBuilder->toSql();
        $sellerBindings = $sellerBuilder->getBindings();
        $sellerUnionPos = stripos($sellerSql, 'union');
        $this->assertStringContainsString('"user_id" = ?', substr($sellerSql, 0, $sellerUnionPos));
        $this->assertStringContainsString('"user_id" = ?', substr($sellerSql, $sellerUnionPos));
        $this->assertContains(7, $sellerBindings);
        $this->assertNotContains(99, $sellerBindings);
        $this->assertContains('2026-11-01', $sellerBindings);
        $this->assertContains('2026-11-30', $sellerBindings);
    }

    private function seedUnpaidEnrichmentTables(): \Illuminate\Support\Collection
    {
        $this->db->table('invoices')->insert([
            ['id' => 1, 'document_id' => 10, 'operation_type_id' => '0101', 'date_of_due' => '2026-11-10'],
            ['id' => 2, 'document_id' => 11, 'operation_type_id' => '0101', 'date_of_due' => '2026-11-25'],
            ['id' => 3, 'document_id' => 12, 'operation_type_id' => '0101', 'date_of_due' => '2026-11-01'],
        ]);

        $this->db->table('dispatches')->insert([
            [
                'id' => 1,
                'reference_document_id' => 10,
                'external_id' => 'ext-t001-1',
                'series' => 'T001',
                'number' => '1',
                'date_of_issue' => '2026-11-01',
                'date_of_shipping' => '2026-11-02',
            ],
            [
                'id' => 2,
                'reference_document_id' => 10,
                'external_id' => 'ext-t001-2',
                'series' => 'T001',
                'number' => '2',
                'date_of_issue' => '2026-11-03',
                'date_of_shipping' => '2026-11-04',
            ],
        ]);

        $this->db->table('document_payments')->insert([
            ['id' => 1, 'document_id' => 10, 'payment' => 100.00, 'date_of_payment' => '2026-11-04'],
            ['id' => 2, 'document_id' => 10, 'payment' => 100.00, 'date_of_payment' => '2026-11-09'],
            ['id' => 3, 'document_id' => 12, 'payment' => 100.00, 'date_of_payment' => '2026-11-02'],
        ]);

        $this->db->table('sale_note_payments')->insert([
            ['id' => 1, 'sale_note_id' => 20, 'payment' => 40.00, 'date_of_payment' => '2026-11-05'],
            ['id' => 2, 'sale_note_id' => 20, 'payment' => 30.00, 'date_of_payment' => '2026-11-12'],
        ]);

        return collect([
            // Overdue unpaid document with guides and two payments
            (object) [
                'id' => 10,
                'date_of_issue' => '2026/11/01',
                'customer_name' => 'Acme Corp',
                'customer_id' => 1,
                'document_type_id' => '01',
                'number_full' => 'F001-10',
                'total' => 500.00,
                'total_payment' => 200.00,
                'total_subtraction' => 300.00,
                'type' => 'document',
                'currency_type_id' => 'PEN',
                'exchange_rate_sale' => 3.75,
                'user_id' => 1,
                'username' => 'Admin',
            ],
            // Future-due unpaid document with no payments
            (object) [
                'id' => 11,
                'date_of_issue' => '2026/11/15',
                'customer_name' => 'Acme Corp',
                'customer_id' => 1,
                'document_type_id' => '01',
                'number_full' => 'F001-11',
                'total' => 250.00,
                'total_payment' => 0.00,
                'total_subtraction' => 250.00,
                'type' => 'document',
                'currency_type_id' => 'USD',
                'exchange_rate_sale' => 3.80,
                'user_id' => 1,
                'username' => 'Admin',
            ],
            // Fully paid document: date_of_due and delay_payment stay null even with an Invoice row
            (object) [
                'id' => 12,
                'date_of_issue' => '2026/11/01',
                'customer_name' => 'Acme Corp',
                'customer_id' => 1,
                'document_type_id' => '01',
                'number_full' => 'F001-12',
                'total' => 100.00,
                'total_payment' => 100.00,
                'total_subtraction' => 0.00,
                'type' => 'document',
                'currency_type_id' => 'PEN',
                'exchange_rate_sale' => 1.00,
                'user_id' => 1,
                'username' => 'Admin',
            ],
            // Unpaid sale note: document_type_id is null -> no guides, no due date, reads sale_note_payments
            (object) [
                'id' => 20,
                'date_of_issue' => '2026/11/02',
                'customer_name' => 'Beta LLC',
                'customer_id' => 2,
                'document_type_id' => null,
                'number_full' => 'NV01-20',
                'total' => 150.00,
                'total_payment' => 70.00,
                'total_subtraction' => 80.00,
                'type' => 'sale_note',
                'currency_type_id' => 'PEN',
                'exchange_rate_sale' => 1.00,
                'user_id' => 2,
                'username' => 'Seller',
            ],
        ]);
    }

    private function assertUnpaidTransformedRows(array $transformed): void
    {
        $expectedKeys = [
            'id',
            'date_of_issue',
            'customer_name',
            'customer_id',
            'number_full',
            'total',
            'total_to_pay',
            'type',
            'guides',
            'date_payment_last',
            'delay_payment',
            'date_of_due',
            'currency_type_id',
            'exchange_rate_sale',
            'user_id',
            'username',
            'total_subtraction',
            'total_payment',
        ];
        $this->assertSame($expectedKeys, array_keys($transformed[0]));

        // Row 0: overdue document (now=2026-11-20 12:00, due=2026-11-10 00:00 -> diffInDays = 10)
        $this->assertSame('300.00', $transformed[0]['total_to_pay']);
        $this->assertSame('500.00', $transformed[0]['total']);
        $this->assertSame('2026/11/10', $transformed[0]['date_of_due']);
        $this->assertSame(10, $transformed[0]['delay_payment']);
        $this->assertSame('2026-11-09', $transformed[0]['date_payment_last']);
        $this->assertCount(2, $transformed[0]['guides']);
        $this->assertSame('T001-2', $transformed[0]['guides'][0]['number']);
        $this->assertSame('T001-1', $transformed[0]['guides'][1]['number']);
        $this->assertSame('2026-11-03', $transformed[0]['guides'][0]['date_of_issue']);
        $this->assertSame('2026-11-04', $transformed[0]['guides'][0]['date_of_shipping']);

        // Row 1: not yet due
        $this->assertSame('250.00', $transformed[1]['total_to_pay']);
        $this->assertSame('2026/11/25', $transformed[1]['date_of_due']);
        $this->assertNull($transformed[1]['delay_payment']);
        $this->assertNull($transformed[1]['date_payment_last']);
        $this->assertCount(0, $transformed[1]['guides']);

        // Row 2: fully paid -> date_of_due and delay_payment are null
        $this->assertSame('0.00', $transformed[2]['total_to_pay']);
        $this->assertNull($transformed[2]['date_of_due']);
        $this->assertNull($transformed[2]['delay_payment']);
        $this->assertSame('2026-11-02', $transformed[2]['date_payment_last']);

        // Row 3: sale note
        $this->assertSame('80.00', $transformed[3]['total_to_pay']);
        $this->assertSame('sale_note', $transformed[3]['type']);
        $this->assertNull($transformed[3]['guides']);
        $this->assertNull($transformed[3]['date_of_due']);
        $this->assertNull($transformed[3]['delay_payment']);
        $this->assertSame('2026-11-12', $transformed[3]['date_payment_last']);
    }

    public function test_unpaid_trait_transform_records_computes_due_delay_guides_and_last_payment(): void
    {
        $rawRows = $this->seedUnpaidEnrichmentTables();

        $transformer = new class {
            use UnpaidTrait;
        };

        $transformed = $transformer->transformRecords($rawRows)->values()->all();
        $this->assertUnpaidTransformedRows($transformed);
    }

    public function test_unpaid_collection_resource_freezes_records_endpoint_contract(): void
    {
        $rawRows = $this->seedUnpaidEnrichmentTables();

        $resourceOutput = (new UnpaidCollection($rawRows))->toArray(new Request())->values()->all();
        $this->assertUnpaidTransformedRows($resourceOutput);
    }

    private function seedToPayDataset(): void
    {
        $this->db->table('persons')->insert([
            ['id' => 1, 'name' => 'Supplier Alpha'],
            ['id' => 2, 'name' => 'Supplier Beta'],
        ]);

        $this->db->table('purchases')->insert([
            // #1: PEN partially paid, overdue by 10 days (now = 2026-11-20, due = 2026-11-10)
            [
                'id' => 1,
                'user_id' => 1,
                'supplier_id' => 1,
                'state_type_id' => '01',
                'document_type_id' => '01',
                'date_of_issue' => '2026-11-01',
                'date_of_due' => '2026-11-10',
                'series' => 'F001',
                'number' => '101',
                'total' => 500.00,
                'establishment_id' => 1,
                'currency_type_id' => 'PEN',
                'exchange_rate_sale' => 1.00,
            ],
            // #2: USD partially paid, future due date (due = 2026-11-25)
            [
                'id' => 2,
                'user_id' => 2,
                'supplier_id' => 2,
                'state_type_id' => '05',
                'document_type_id' => '03',
                'date_of_issue' => '2026-11-05',
                'date_of_due' => '2026-11-25',
                'series' => 'B001',
                'number' => '202',
                'total' => 200.00,
                'establishment_id' => 1,
                'currency_type_id' => 'USD',
                'exchange_rate_sale' => 3.80,
            ],
            // #3: PEN fully paid (total_to_pay = 0.00) -> STILL returned, with null date_of_due & delay_payment
            [
                'id' => 3,
                'user_id' => 1,
                'supplier_id' => 1,
                'state_type_id' => '05',
                'document_type_id' => '01',
                'date_of_issue' => '2026-11-02',
                'date_of_due' => '2026-11-05',
                'series' => 'F001',
                'number' => '103',
                'total' => 300.00,
                'establishment_id' => 1,
                'currency_type_id' => 'PEN',
                'exchange_rate_sale' => 1.00,
            ],
            // #4: Voided ('11') -> excluded
            [
                'id' => 4,
                'user_id' => 1,
                'supplier_id' => 1,
                'state_type_id' => '11',
                'document_type_id' => '01',
                'date_of_issue' => '2026-11-03',
                'date_of_due' => '2026-11-10',
                'series' => 'F001',
                'number' => '104',
                'total' => 400.00,
                'establishment_id' => 1,
                'currency_type_id' => 'PEN',
                'exchange_rate_sale' => 1.00,
            ],
            // #5: Disallowed document_type_id ('07') -> excluded
            [
                'id' => 5,
                'user_id' => 1,
                'supplier_id' => 1,
                'state_type_id' => '05',
                'document_type_id' => '07',
                'date_of_issue' => '2026-11-03',
                'date_of_due' => '2026-11-10',
                'series' => 'FC01',
                'number' => '105',
                'total' => 100.00,
                'establishment_id' => 1,
                'currency_type_id' => 'PEN',
                'exchange_rate_sale' => 1.00,
            ],
            // #6: Zero-payment purchase (verifies IFNULL(total_payment, 0) = 0 and date_payment_last = null)
            [
                'id' => 6,
                'user_id' => 2,
                'supplier_id' => 2,
                'state_type_id' => '05',
                'document_type_id' => 'GU75',
                'date_of_issue' => '2026-10-15',
                'date_of_due' => '2026-11-15',
                'series' => 'G001',
                'number' => '106',
                'total' => 120.00,
                'establishment_id' => 1,
                'currency_type_id' => 'PEN',
                'exchange_rate_sale' => 1.00,
            ],
        ]);

        $this->db->table('purchase_payments')->insert([
            ['id' => 1, 'purchase_id' => 1, 'payment' => 100.00, 'date_of_payment' => '2026-11-03'],
            ['id' => 2, 'purchase_id' => 1, 'payment' => 150.00, 'date_of_payment' => '2026-11-08'],
            ['id' => 3, 'purchase_id' => 2, 'payment' => 50.00, 'date_of_payment' => '2026-11-06'],
            ['id' => 4, 'purchase_id' => 3, 'payment' => 300.00, 'date_of_payment' => '2026-11-04'],
        ]);

        $this->db->table('expenses')->insert([
            // #1: PEN partially paid
            [
                'id' => 1,
                'user_id' => 1,
                'supplier_id' => 1,
                'state_type_id' => '05',
                'date_of_issue' => '2026-11-04',
                'number' => 'E-001',
                'total' => 180.00,
                'establishment_id' => 1,
                'currency_type_id' => 'PEN',
                'exchange_rate_sale' => 1.00,
            ],
            // #2: USD fully paid -> STILL returned
            [
                'id' => 2,
                'user_id' => 2,
                'supplier_id' => 2,
                'state_type_id' => '01',
                'date_of_issue' => '2026-11-07',
                'number' => 'E-002',
                'total' => 100.00,
                'establishment_id' => 1,
                'currency_type_id' => 'USD',
                'exchange_rate_sale' => 3.75,
            ],
            // #3: Voided ('11') -> excluded
            [
                'id' => 3,
                'user_id' => 1,
                'supplier_id' => 1,
                'state_type_id' => '11',
                'date_of_issue' => '2026-11-08',
                'number' => 'E-003',
                'total' => 50.00,
                'establishment_id' => 1,
                'currency_type_id' => 'PEN',
                'exchange_rate_sale' => 1.00,
            ],
            // #4: Zero-payment expense (verifies IFNULL(total_payment, 0) = 0 and date_payment_last = null)
            [
                'id' => 4,
                'user_id' => 2,
                'supplier_id' => 2,
                'state_type_id' => '05',
                'date_of_issue' => '2026-10-20',
                'number' => 'E-004',
                'total' => 90.00,
                'establishment_id' => 1,
                'currency_type_id' => 'PEN',
                'exchange_rate_sale' => 1.00,
            ],
        ]);

        $this->db->table('expense_payments')->insert([
            ['id' => 1, 'expense_id' => 1, 'payment' => 80.00, 'date_of_payment' => '2026-11-05'],
            ['id' => 2, 'expense_id' => 1, 'payment' => 20.00, 'date_of_payment' => '2026-11-09'],
            ['id' => 3, 'expense_id' => 2, 'payment' => 100.00, 'date_of_payment' => '2026-11-08'],
        ]);
    }

    public function test_to_pay_returns_fully_paid_rows_zero_payment_rows_and_respects_filters(): void
    {
        $this->seedToPayDataset();

        // Period-filtered query (November 2026 excludes October rows purchase:6 and expense:4).
        $records = ToPay::getToPay([
            'establishment_id' => 1,
            'period' => 'between_dates',
            'date_start' => '2026-11-01',
            'date_end' => '2026-11-30',
        ]);

        $this->assertCount(5, $records);
        $byKey = $records->keyBy(fn ($row) => $row['type'] . ':' . $row['id']);

        $this->assertSame([
            'id' => 1,
            'date_of_issue' => '2026/11/01',
            'supplier_name' => 'Supplier Alpha',
            'supplier_id' => 1,
            'number_full' => 'F001-101',
            'total' => '500.00',
            'total_to_pay' => '250.00',
            'type' => 'purchase',
            'date_payment_last' => '2026-11-08',
            'delay_payment' => 10,
            'date_of_due' => '2026/11/10',
            'currency_type_id' => 'PEN',
            'exchange_rate_sale' => 1.0,
        ], $byKey['purchase:1']);

        $this->assertSame('150.00', $byKey['purchase:2']['total_to_pay']);
        $this->assertSame('USD', $byKey['purchase:2']['currency_type_id']);
        $this->assertSame(3.8, $byKey['purchase:2']['exchange_rate_sale']);
        $this->assertSame('2026/11/25', $byKey['purchase:2']['date_of_due']);
        $this->assertNull($byKey['purchase:2']['delay_payment']);
        $this->assertSame('2026-11-06', $byKey['purchase:2']['date_payment_last']);

        // Fully paid purchase IS returned, with null date_of_due and null delay_payment.
        $this->assertSame('0.00', $byKey['purchase:3']['total_to_pay']);
        $this->assertNull($byKey['purchase:3']['date_of_due']);
        $this->assertNull($byKey['purchase:3']['delay_payment']);
        $this->assertSame('2026-11-04', $byKey['purchase:3']['date_payment_last']);

        // Expenses: partially paid and fully paid both returned, with null date_of_due.
        $this->assertSame('80.00', $byKey['expense:1']['total_to_pay']);
        $this->assertSame('2026-11-09', $byKey['expense:1']['date_payment_last']);
        $this->assertNull($byKey['expense:1']['date_of_due']);
        $this->assertNull($byKey['expense:1']['delay_payment']);

        $this->assertSame('0.00', $byKey['expense:2']['total_to_pay']);
        $this->assertSame('USD', $byKey['expense:2']['currency_type_id']);
        $this->assertSame('2026-11-08', $byKey['expense:2']['date_payment_last']);

        // No-period branch includes October zero-payment rows (purchase:6, expense:4).
        $allRecords = ToPay::getToPay([
            'establishment_id' => 1,
            'period' => null,
        ]);
        $this->assertCount(7, $allRecords);
        $allByKey = $allRecords->keyBy(fn ($row) => $row['type'] . ':' . $row['id']);

        $this->assertSame('120.00', $allByKey['purchase:6']['total_to_pay']);
        $this->assertNull($allByKey['purchase:6']['date_payment_last']);
        $this->assertSame('2026/11/15', $allByKey['purchase:6']['date_of_due']);
        $this->assertSame(5, $allByKey['purchase:6']['delay_payment']);

        $this->assertSame('90.00', $allByKey['expense:4']['total_to_pay']);
        $this->assertNull($allByKey['expense:4']['date_payment_last']);
        $this->assertNull($allByKey['expense:4']['date_of_due']);

        // Supplier + user filters scope both purchases and expenses.
        $filteredBySupplierAndUser = ToPay::getToPay([
            'establishment_id' => 1,
            'period' => null,
            'supplier_id' => 2,
            'user' => 2,
        ]);
        $filteredKeys = $filteredBySupplierAndUser->map(fn ($r) => $r['type'] . ':' . $r['id'])->sort()->values()->all();
        $this->assertSame(['expense:2', 'expense:4', 'purchase:2', 'purchase:6'], $filteredKeys);
    }

    public function test_to_pay_no_filter_formats_date_of_due_with_dashes_unlike_get_to_pay(): void
    {
        $this->seedToPayDataset();

        $records = ToPay::getToPayNoFilter();
        $this->assertCount(7, $records);

        $byKey = $records->keyBy(fn ($row) => $row['type'] . ':' . $row['id']);

        // Freezes format divergence: getToPay() outputs Y/m/d while getToPayNoFilter() outputs Y-m-d.
        $this->assertSame('2026-11-10', $byKey['purchase:1']['date_of_due']);
        $this->assertSame(10, $byKey['purchase:1']['delay_payment']);
        $this->assertSame('2026-11-25', $byKey['purchase:2']['date_of_due']);
        $this->assertNull($byKey['purchase:2']['delay_payment']);

        // Zero-payment rows keep IFNULL(..., 0) = 0 and null date_payment_last.
        $this->assertSame('120.00', $byKey['purchase:6']['total_to_pay']);
        $this->assertSame('2026-11-15', $byKey['purchase:6']['date_of_due']);
        $this->assertNull($byKey['purchase:6']['date_payment_last']);
        $this->assertSame('90.00', $byKey['expense:4']['total_to_pay']);
        $this->assertNull($byKey['expense:4']['date_payment_last']);

        // Fully paid rows are still returned with null due date and delay.
        $this->assertSame('0.00', $byKey['purchase:3']['total_to_pay']);
        $this->assertNull($byKey['purchase:3']['date_of_due']);
        $this->assertNull($byKey['purchase:3']['delay_payment']);
        $this->assertSame('0.00', $byKey['expense:2']['total_to_pay']);
    }
}
