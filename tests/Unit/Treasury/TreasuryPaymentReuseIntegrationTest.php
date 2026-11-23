<?php

namespace Tests\Unit\Treasury;

use App\Http\Controllers\Tenant\DocumentPaymentController;
use App\Http\Requests\Tenant\DocumentPaymentRequest;
use App\Models\Tenant\BankAccount;
use App\Models\Tenant\Document;
use App\Models\Tenant\DocumentPayment;
use App\Models\Tenant\Purchase;
use App\Models\Tenant\PurchasePayment;
use App\Providers\DocumentPaymentProvider;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use Modules\Finance\Models\GlobalPayment;
use Modules\Finance\Providers\GlobalPaymentServiceProvider;
use Modules\Purchase\Http\Controllers\PurchasePaymentController;
use Modules\Purchase\Http\Requests\PurchasePaymentRequest;
use Modules\Treasury\Services\BancarizationChecker;
use Modules\Treasury\Services\ReceivablePayableService;
use Modules\Treasury\Services\TaxRateRepository;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end SQLite integration tests proving Phase 3 Acceptance Criteria #2 & #3 (Decision D4):
 * - Recording a payment via existing DocumentPaymentController::store updates documents.total_canceled,
 *   creates exactly 1 GlobalPayment (zero duplication), and immediately updates ReceivablePayableService
 *   balances and FIFO installment states.
 * - Recording a payment via existing PurchasePaymentController::store updates purchases.total_canceled,
 *   creates exactly 1 GlobalPayment (zero duplication), and updates payable schedules and aging.
 */
class TreasuryPaymentReuseIntegrationTest extends TestCase
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

        $this->auth = new class {
            public $id = 1;
            public $type = 'admin';

            public function user()
            {
                return (object) ['id' => $this->id, 'type' => $this->type, 'establishment_id' => 1];
            }

            public function id()
            {
                return $this->id;
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

        // Clear model booted cache so providers register fresh listeners on this dispatcher.
        $booted->setValue(null, []);

        foreach ([Document::class, DocumentPayment::class, Purchase::class, PurchasePayment::class, GlobalPayment::class] as $model) {
            $model::addGlobalScope('payment-reuse-fixture', function ($query) {
                $query->setEagerLoads([]);
            });
        }

        // Boot the real providers that maintain total_canceled and global_payments cascade.
        (new DocumentPaymentProvider($this->app))->boot();
        (new GlobalPaymentServiceProvider($this->app))->boot();

        foreach ([
            'companies (id integer primary key autoincrement, soap_type_id text)',
            'persons (id integer primary key autoincrement, name text, number text null)',
            'bank_accounts (id integer primary key autoincrement, bank_id integer, description text, number text, currency_type_id text, status integer, initial_balance numeric)',
            'documents (id integer primary key autoincrement, user_id integer, customer_id integer, establishment_id integer, state_type_id text, document_type_id text, series text, number text, date_of_issue text, total numeric, currency_type_id text, exchange_rate_sale numeric, total_canceled integer default 0, created_at text null, updated_at text null)',
            'document_payments (id integer primary key autoincrement, document_id integer, date_of_payment text, payment_method_type_id text, has_card integer default 0, card_brand_id text null, reference text null, change numeric null, payment numeric)',
            'document_fee (id integer primary key autoincrement, document_id integer, date text, currency_type_id text, amount numeric)',
            'purchases (id integer primary key autoincrement, user_id integer, supplier_id integer, establishment_id integer, state_type_id text, document_type_id text, series text, number text, date_of_issue text, date_of_due text null, total numeric, currency_type_id text, exchange_rate_sale numeric, total_canceled integer default 0, created_at text null, updated_at text null)',
            'purchase_payments (id integer primary key autoincrement, purchase_id integer, date_of_payment text, payment_method_type_id text, has_card integer default 0, card_brand_id text null, reference text null, payment numeric)',
            'purchase_fees (id integer primary key autoincrement, purchase_id integer, date text, currency_type_id text, amount numeric, created_at text null, updated_at text null)',
            'global_payments (id integer primary key autoincrement, soap_type_id text null, destination_id integer, destination_type text, payment_id integer, payment_type text, user_id integer null, created_at text null, updated_at text null)',
            'payment_files (id integer primary key autoincrement, filename text, payment_id integer, payment_type text)',
            'payment_evidences (id integer primary key autoincrement, payable_type text, payable_id integer, sunat_payment_code text, operation_number text null, bank_id integer null, bank_account_id integer null, operation_date text, payer_or_beneficiary_document text null, payer_or_beneficiary_name text null, third_party_payment integer default 0, third_party_reported_to_sunat integer default 0, bancarization_exception text null, created_at text null, updated_at text null)',
            'treasury_tax_rates (id integer primary key autoincrement, tax text, code text null, rate numeric null, amount numeric null, min_operation numeric null, valid_from text, valid_to text null, legal_reference text)',
        ] as $table) {
            $this->db->statement('create table ' . $table);
        }

        $this->db->table('companies')->insert(['id' => 1, 'soap_type_id' => '02']);
        $this->db->table('persons')->insert([
            ['id' => 1, 'name' => 'Cliente Test SAC', 'number' => '20111111111'],
            ['id' => 2, 'name' => 'Proveedor Test SA', 'number' => '20222222222'],
        ]);
        $this->db->table('bank_accounts')->insert([
            ['id' => 5, 'bank_id' => 1, 'description' => 'BCP PEN', 'number' => '191-001', 'currency_type_id' => 'PEN', 'status' => 1, 'initial_balance' => 1000],
        ]);
        $this->db->table('treasury_tax_rates')->insert([
            ['tax' => 'bancarization_threshold_pen', 'code' => null, 'rate' => null, 'amount' => 2000.00, 'min_operation' => null, 'valid_from' => '2022-04-01', 'valid_to' => null, 'legal_reference' => 'DL 1529'],
        ]);
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

    private function makeService(): ReceivablePayableService
    {
        $repo = new TaxRateRepository();

        return new ReceivablePayableService($repo, new BancarizationChecker($repo));
    }

    public function test_document_payment_controller_updates_total_canceled_creates_single_global_and_updates_fifo(): void
    {
        $this->db->table('documents')->insert([
            'id' => 10,
            'user_id' => 1,
            'customer_id' => 1,
            'establishment_id' => 1,
            'state_type_id' => '05',
            'document_type_id' => '01',
            'series' => 'F001',
            'number' => '10',
            'date_of_issue' => '2026-10-01',
            'total' => 600.00,
            'currency_type_id' => 'PEN',
            'exchange_rate_sale' => 3.75,
            'total_canceled' => 0,
        ]);
        $this->db->table('document_fee')->insert([
            ['id' => 1, 'document_id' => 10, 'date' => '2026-11-01', 'currency_type_id' => 'PEN', 'amount' => 300.00],
            ['id' => 2, 'document_id' => 10, 'date' => '2026-12-01', 'currency_type_id' => 'PEN', 'amount' => 300.00],
        ]);

        $controller = new DocumentPaymentController();
        $service = $this->makeService();

        // 1st payment: 300 PEN covers installment 1 completely; total_canceled stays false
        $req1 = DocumentPaymentRequest::create('/document_payments', 'POST', [
            'document_id' => 10,
            'date_of_payment' => '2026-11-01',
            'payment_method_type_id' => '04',
            'payment_destination_id' => 5,
            'reference' => 'OP-1',
            'payment' => 300.00,
        ]);
        $resp1 = $controller->store($req1);
        $this->assertTrue($resp1['success']);

        $this->assertSame(0, (int) $this->db->table('documents')->where('id', 10)->value('total_canceled'));
        $this->assertSame(1, GlobalPayment::where('payment_type', DocumentPayment::class)->count());
        $global1 = GlobalPayment::where('payment_type', DocumentPayment::class)->first();
        $this->assertSame(BankAccount::class, $global1->destination_type);
        $this->assertSame(5, (int) $global1->destination_id);

        // ReceivablePayableService immediately reflects reduced balance (300) and installment 1 = paid
        $arAfter1 = $service->getReceivables([], '2026-11-20');
        $this->assertCount(1, $arAfter1['rows']);
        $this->assertSame(300.0, $arAfter1['rows'][0]['balance']);
        $this->assertSame('paid', $arAfter1['rows'][0]['installments'][0]['status']);
        $this->assertSame('pending', $arAfter1['rows'][0]['installments'][1]['status']);

        // 2nd payment: remaining 300 PEN settles the document -> total_canceled becomes true, 2 globals total (1 per payment)
        $req2 = DocumentPaymentRequest::create('/document_payments', 'POST', [
            'document_id' => 10,
            'date_of_payment' => '2026-11-20',
            'payment_method_type_id' => '04',
            'payment_destination_id' => 5,
            'reference' => 'OP-2',
            'payment' => 300.00,
        ]);
        $resp2 = $controller->store($req2);
        $this->assertTrue($resp2['success']);

        $this->assertSame(1, (int) $this->db->table('documents')->where('id', 10)->value('total_canceled'));
        $this->assertSame(2, DocumentPayment::where('document_id', 10)->count());
        $this->assertSame(2, GlobalPayment::where('payment_type', DocumentPayment::class)->count());

        // Fully paid document no longer appears in pending receivables
        $arAfter2 = $service->getReceivables([], '2026-11-20');
        $this->assertCount(0, $arAfter2['rows']);
    }

    public function test_purchase_payment_controller_updates_total_canceled_creates_single_global_and_updates_aging(): void
    {
        $this->db->table('purchases')->insert([
            'id' => 20,
            'user_id' => 1,
            'supplier_id' => 2,
            'establishment_id' => 1,
            'state_type_id' => '01',
            'document_type_id' => '01',
            'series' => 'F005',
            'number' => '20',
            'date_of_issue' => '2026-10-01',
            'date_of_due' => '2026-11-01',
            'total' => 500.00,
            'currency_type_id' => 'PEN',
            'exchange_rate_sale' => 3.75,
            'total_canceled' => 0,
        ]);
        $this->db->table('purchase_fees')->insert([
            ['id' => 1, 'purchase_id' => 20, 'date' => '2026-11-01', 'currency_type_id' => 'PEN', 'amount' => 200.00], // overdue 19d
            ['id' => 2, 'purchase_id' => 20, 'date' => '2026-12-05', 'currency_type_id' => 'PEN', 'amount' => 300.00], // current
        ]);

        $controller = new PurchasePaymentController();
        $service = $this->makeService();

        // Before payment: aging has 200 in days_1_30 and 300 in current
        $agingBefore = $service->getAging('payables', [], '2026-11-20');
        $this->assertSame(200.0, $agingBefore['rows'][0]['days_1_30']);
        $this->assertSame(300.0, $agingBefore['rows'][0]['current']);

        // Pay 200 PEN via PurchasePaymentController::store -> clears overdue installment 1
        $req1 = PurchasePaymentRequest::create('/purchase-payments', 'POST', [
            'purchase_id' => 20,
            'date_of_payment' => '2026-11-20',
            'payment_method_type_id' => '04',
            'payment_destination_id' => 5,
            'reference' => 'TR-1',
            'payment' => 200.00,
        ]);
        $resp1 = $controller->store($req1);
        $this->assertTrue($resp1['success']);

        $this->assertSame(0, (int) $this->db->table('purchases')->where('id', 20)->value('total_canceled'));
        $this->assertSame(1, GlobalPayment::where('payment_type', PurchasePayment::class)->count());

        $agingAfter1 = $service->getAging('payables', [], '2026-11-20');
        $this->assertSame(0.0, $agingAfter1['rows'][0]['days_1_30']);
        $this->assertSame(300.0, $agingAfter1['rows'][0]['current']);

        // Pay remaining 300 PEN -> purchase total_canceled becomes true, zero pending payables
        $req2 = PurchasePaymentRequest::create('/purchase-payments', 'POST', [
            'purchase_id' => 20,
            'date_of_payment' => '2026-11-20',
            'payment_method_type_id' => '04',
            'payment_destination_id' => 5,
            'reference' => 'TR-2',
            'payment' => 300.00,
        ]);
        $resp2 = $controller->store($req2);
        $this->assertTrue($resp2['success']);

        $this->assertSame(1, (int) $this->db->table('purchases')->where('id', 20)->value('total_canceled'));
        $this->assertSame(2, GlobalPayment::where('payment_type', PurchasePayment::class)->count());
        $this->assertCount(0, $service->getPayables([], '2026-11-20')['rows']);
    }
}
