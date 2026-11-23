<?php

namespace Tests\Unit\Treasury;

use App\Models\Tenant\Cash;
use App\Models\Tenant\DocumentPayment;
use App\Models\Tenant\Purchase;
use App\Models\Tenant\PurchasePayment;
use App\Models\Tenant\SaleNotePayment;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use Modules\Expense\Models\ExpensePayment;
use Modules\Finance\Models\GlobalPayment;
use Modules\Finance\Models\IncomePayment;
use Modules\Finance\Models\PaymentFile;
use Modules\Finance\Providers\GlobalPaymentServiceProvider;
use Modules\Pos\Models\CashTransaction;
use Modules\Sale\Models\ContractPayment;
use Modules\Sale\Models\QuotationPayment;
use Modules\Sale\Models\TechnicalServicePayment;
use PHPUnit\Framework\TestCase;

/**
 * Characterization tests for Modules\Finance\Providers\GlobalPaymentServiceProvider.
 *
 * Freezes Eloquent deleting cascades to global_payments / payment_files,
 * the query-builder bulk-delete bypass limitation, and PurchasePayment
 * created/deleted recalculation of purchases.total_canceled.
 */
class GlobalPaymentCascadeCharacterizationTest extends TestCase
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

        $manager = new Manager($this->app);
        $manager->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''], 'tenant');
        $database = $manager->getDatabaseManager();
        $database->setDefaultConnection('tenant');
        $this->app->instance('db', $database);

        Model::setConnectionResolver($database);
        Model::setEventDispatcher(new Dispatcher($this->app));

        $this->db = $database->connection('tenant');

        foreach ([
            DocumentPayment::class,
            SaleNotePayment::class,
            PurchasePayment::class,
            QuotationPayment::class,
            ContractPayment::class,
            IncomePayment::class,
            TechnicalServicePayment::class,
            Purchase::class,
        ] as $model) {
            $model::addGlobalScope('global-payment-cascade-fixture', function ($query) {
                $query->setEagerLoads([]);
            });
        }

        foreach ([
            'global_payments (id integer primary key autoincrement, soap_type_id text null, destination_id integer, destination_type text, payment_id integer, payment_type text, user_id integer null, created_at text null, updated_at text null)',
            'payment_files (id integer primary key autoincrement, filename text, payment_id integer, payment_type text)',
            'document_payments (id integer primary key autoincrement, document_id integer, date_of_payment text, payment_method_type_id text, has_card integer, card_brand_id text null, reference text null, change numeric null, payment numeric)',
            'sale_note_payments (id integer primary key autoincrement, sale_note_id integer, date_of_payment text, payment_method_type_id text, has_card integer, card_brand_id text null, reference text null, change numeric null, payment numeric)',
            'purchases (id integer primary key autoincrement, total numeric, total_canceled integer, created_at text null, updated_at text null)',
            'purchase_payments (id integer primary key autoincrement, purchase_id integer, date_of_payment text, payment_method_type_id text, has_card integer, card_brand_id text null, reference text null, payment numeric)',
            'quotation_payments (id integer primary key autoincrement, quotation_id integer, date_of_payment text, payment_method_type_id text, has_card integer, card_brand_id text null, reference text null, change numeric null, payment numeric)',
            'contract_payments (id integer primary key autoincrement, contract_id integer, date_of_payment text, payment_method_type_id text, has_card integer, card_brand_id text null, reference text null, change numeric null, payment numeric)',
            'expense_payments (id integer primary key autoincrement, expense_id integer, date_of_payment text, expense_method_type_id text, has_card integer, card_brand_id text null, reference text null, payment numeric)',
            'income_payments (id integer primary key autoincrement, income_id integer, date_of_payment text, payment_method_type_id text, has_card integer, card_brand_id text null, reference text null, change numeric null, payment numeric)',
            'cash_transactions (id integer primary key autoincrement, cash_id integer, payment_method_type_id text, date text, description text, payment numeric)',
            'technical_service_payments (id integer primary key autoincrement, technical_service_id integer, date_of_payment text, payment_method_type_id text, reference text null, change numeric null, payment numeric)',
        ] as $table) {
            $this->db->statement('create table ' . $table);
        }

        (new GlobalPaymentServiceProvider($this->app))->boot();
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

    private function attachGlobalAndFile(string $paymentType, int $paymentId): void
    {
        $this->db->table('global_payments')->insert([
            'soap_type_id' => '02',
            'destination_id' => 1,
            'destination_type' => Cash::class,
            'payment_id' => $paymentId,
            'payment_type' => $paymentType,
            'user_id' => 1,
        ]);

        $this->db->table('payment_files')->insert([
            'filename' => 'receipt-' . $paymentId . '.pdf',
            'payment_id' => $paymentId,
            'payment_type' => $paymentType,
        ]);
    }

    public function test_eloquent_delete_cascades_global_payments_and_supported_payment_files_only(): void
    {
        // Seed an unrelated sentinel row to guarantee cascades never affect other records.
        $this->attachGlobalAndFile(DocumentPayment::class, 999);

        // Seed parent purchase for PurchasePayment::deleted listener.
        $this->db->table('purchases')->insert(['id' => 1, 'total' => 200.00, 'total_canceled' => 0]);

        $cases = [
            [
                'model' => DocumentPayment::class,
                'table' => 'document_payments',
                'row' => ['id' => 1, 'document_id' => 10, 'date_of_payment' => '2026-11-20', 'payment_method_type_id' => '01', 'has_card' => 0, 'payment' => 100.00],
                'hasPaymentFileRelation' => true,
            ],
            [
                'model' => SaleNotePayment::class,
                'table' => 'sale_note_payments',
                'row' => ['id' => 2, 'sale_note_id' => 10, 'date_of_payment' => '2026-11-20', 'payment_method_type_id' => '01', 'has_card' => 0, 'payment' => 80.00],
                'hasPaymentFileRelation' => true,
            ],
            [
                'model' => PurchasePayment::class,
                'table' => 'purchase_payments',
                'row' => ['id' => 3, 'purchase_id' => 1, 'date_of_payment' => '2026-11-20', 'payment_method_type_id' => '01', 'has_card' => 0, 'payment' => 50.00],
                'hasPaymentFileRelation' => true,
            ],
            [
                'model' => ExpensePayment::class,
                'table' => 'expense_payments',
                'row' => ['id' => 4, 'expense_id' => 10, 'date_of_payment' => '2026-11-20', 'expense_method_type_id' => '1', 'has_card' => 0, 'payment' => 40.00],
                'hasPaymentFileRelation' => true,
            ],
            [
                'model' => QuotationPayment::class,
                'table' => 'quotation_payments',
                'row' => ['id' => 7, 'quotation_id' => 10, 'date_of_payment' => '2026-11-20', 'payment_method_type_id' => '01', 'has_card' => 0, 'payment' => 70.00],
                'hasPaymentFileRelation' => true,
            ],
            // IncomePayment, CashTransaction, ContractPayment, and TechnicalServicePayment do not define
            // a payment_file() relation: deleting cascades global_payments only and leaves payment_files untouched.
            [
                'model' => IncomePayment::class,
                'table' => 'income_payments',
                'row' => ['id' => 5, 'income_id' => 10, 'date_of_payment' => '2026-11-20', 'payment_method_type_id' => '01', 'has_card' => 0, 'payment' => 60.00],
                'hasPaymentFileRelation' => false,
            ],
            [
                'model' => CashTransaction::class,
                'table' => 'cash_transactions',
                'row' => ['id' => 6, 'cash_id' => 1, 'payment_method_type_id' => '01', 'date' => '2026-11-20', 'description' => 'Saldo inicial', 'payment' => 150.00],
                'hasPaymentFileRelation' => false,
            ],
            [
                'model' => ContractPayment::class,
                'table' => 'contract_payments',
                'row' => ['id' => 8, 'contract_id' => 10, 'date_of_payment' => '2026-11-20', 'payment_method_type_id' => '01', 'has_card' => 0, 'payment' => 90.00],
                'hasPaymentFileRelation' => false,
            ],
            [
                'model' => TechnicalServicePayment::class,
                'table' => 'technical_service_payments',
                'row' => ['id' => 9, 'technical_service_id' => 10, 'date_of_payment' => '2026-11-20', 'payment_method_type_id' => '01', 'payment' => 55.00],
                'hasPaymentFileRelation' => false,
            ],
        ];

        foreach ($cases as $case) {
            $modelClass = $case['model'];
            $id = $case['row']['id'];

            $this->db->table($case['table'])->insert($case['row']);
            $this->attachGlobalAndFile($modelClass, $id);

            $record = $modelClass::findOrFail($id);
            $record->delete();

            $this->assertSame(
                0,
                GlobalPayment::where('payment_type', $modelClass)->where('payment_id', $id)->count(),
                "Expected global_payments cascade for {$modelClass}"
            );

            $expectedFileCount = $case['hasPaymentFileRelation'] ? 0 : 1;
            $this->assertSame(
                $expectedFileCount,
                PaymentFile::where('payment_type', $modelClass)->where('payment_id', $id)->count(),
                "Unexpected payment_files count after deleting {$modelClass}"
            );
        }

        // Unrelated sentinel rows remain intact.
        $this->assertSame(1, GlobalPayment::where('payment_type', DocumentPayment::class)->where('payment_id', 999)->count());
        $this->assertSame(1, PaymentFile::where('payment_type', DocumentPayment::class)->where('payment_id', 999)->count());
    }

    public function test_query_builder_delete_bypasses_eloquent_cascade_and_leaves_orphans(): void
    {
        $this->db->table('document_payments')->insert([
            'id' => 10,
            'document_id' => 1,
            'date_of_payment' => '2026-11-20',
            'payment_method_type_id' => '01',
            'has_card' => 0,
            'payment' => 125.00,
        ]);
        $this->attachGlobalAndFile(DocumentPayment::class, 10);

        // Query-builder delete bypasses Eloquent model events, leaving orphan rows behind.
        DocumentPayment::where('id', 10)->delete();

        $this->assertSame(0, DocumentPayment::where('id', 10)->count());
        $this->assertSame(1, GlobalPayment::where('payment_type', DocumentPayment::class)->where('payment_id', 10)->count());
        $this->assertSame(1, PaymentFile::where('payment_type', DocumentPayment::class)->where('payment_id', 10)->count());
    }

    public function test_purchase_payment_created_and_deleted_recalculate_purchase_total_canceled(): void
    {
        $this->db->table('purchases')->insert([
            'id' => 1,
            'total' => 100.00,
            'total_canceled' => 0,
        ]);

        // Partial payment (40 < 100): total_canceled remains false (0).
        PurchasePayment::create([
            'purchase_id' => 1,
            'date_of_payment' => '2026-11-20',
            'payment_method_type_id' => '01',
            'has_card' => 0,
            'payment' => 40.00,
        ]);
        $this->assertSame(0, (int) $this->db->table('purchases')->where('id', 1)->value('total_canceled'));

        // Completing payment (40 + 60 >= 100): total_canceled becomes true (1).
        $second = PurchasePayment::create([
            'purchase_id' => 1,
            'date_of_payment' => '2026-11-20',
            'payment_method_type_id' => '01',
            'has_card' => 0,
            'payment' => 60.00,
        ]);
        $this->assertSame(1, (int) $this->db->table('purchases')->where('id', 1)->value('total_canceled'));

        // Overpayment keeps total_canceled true (1).
        $third = PurchasePayment::create([
            'purchase_id' => 1,
            'date_of_payment' => '2026-11-20',
            'payment_method_type_id' => '01',
            'has_card' => 0,
            'payment' => 10.00,
        ]);
        $this->assertSame(1, (int) $this->db->table('purchases')->where('id', 1)->value('total_canceled'));

        // Deleting a freshly loaded payment recalculates payments from DB:
        // deleting $third leaves sum = 100 >= 100 -> total_canceled stays true (1).
        PurchasePayment::findOrFail($third->id)->delete();
        $this->assertSame(1, (int) $this->db->table('purchases')->where('id', 1)->value('total_canceled'));

        // Quirk characterization: deleting the SAME in-memory instance returned by ::create()
        // reuses its cached $purchase->payments relation (populated during the created event),
        // leaving total_canceled stale at 1 even though the row is deleted in DB.
        $second->delete();
        $this->assertSame(0, PurchasePayment::where('id', $second->id)->count());
        $this->assertSame(1, (int) $this->db->table('purchases')->where('id', 1)->value('total_canceled'));

        // Re-completing via create, then deleting a FRESHLY queried instance reverts total_canceled to 0.
        $replacement = PurchasePayment::create([
            'purchase_id' => 1,
            'date_of_payment' => '2026-11-20',
            'payment_method_type_id' => '01',
            'has_card' => 0,
            'payment' => 60.00,
        ]);
        $this->assertSame(1, (int) $this->db->table('purchases')->where('id', 1)->value('total_canceled'));

        PurchasePayment::findOrFail($replacement->id)->delete();
        $this->assertSame(0, (int) $this->db->table('purchases')->where('id', 1)->value('total_canceled'));

        // Re-completing via create, then deleting via query builder bypasses the deleted listener
        // and leaves total_canceled stale at 1.
        $fourth = PurchasePayment::create([
            'purchase_id' => 1,
            'date_of_payment' => '2026-11-20',
            'payment_method_type_id' => '01',
            'has_card' => 0,
            'payment' => 60.00,
        ]);
        $this->assertSame(1, (int) $this->db->table('purchases')->where('id', 1)->value('total_canceled'));

        PurchasePayment::where('id', $fourth->id)->delete();
        $this->assertSame(1, (int) $this->db->table('purchases')->where('id', 1)->value('total_canceled'));
    }
}
