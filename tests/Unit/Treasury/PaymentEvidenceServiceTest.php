<?php

namespace Tests\Unit\Treasury;

use App\Models\Tenant\BankAccount;
use App\Models\Tenant\Document;
use App\Models\Tenant\DocumentPayment;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use Illuminate\Validation\ValidationException;
use Modules\Finance\Providers\GlobalPaymentServiceProvider;
use Modules\Treasury\Models\PaymentEvidence;
use Modules\Treasury\Services\BancarizationChecker;
use Modules\Treasury\Services\PaymentEvidenceService;
use Modules\Treasury\Services\TaxRateRepository;
use PHPUnit\Framework\TestCase;

class PaymentEvidenceServiceTest extends TestCase
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

        foreach ([Document::class, DocumentPayment::class] as $model) {
            $model::addGlobalScope('evidence-test-fixture', function ($query) {
                $query->setEagerLoads([]);
            });
        }

        foreach ([
            'bank_accounts (id integer primary key autoincrement, bank_id integer, description text, number text, currency_type_id text, cci text null, status integer, initial_balance numeric, show_in_documents integer, account_type text, pcge_account text null, itf_exempt integer, establishment_id integer null)',
            'payment_method_types (id text primary key, description text, has_card integer, charge numeric null, number_days integer null, sunat_code text null, is_cash integer)',
            'documents (id integer primary key autoincrement, state_type_id text, currency_type_id text, exchange_rate_sale numeric, total numeric, customer text null, created_at text null, updated_at text null)',
            'document_payments (id integer primary key autoincrement, document_id integer, date_of_payment text, payment_method_type_id text, has_card integer, card_brand_id text null, reference text null, change numeric null, payment numeric)',
            'global_payments (id integer primary key autoincrement, soap_type_id text null, destination_id integer, destination_type text, payment_id integer, payment_type text, user_id integer null, created_at text null, updated_at text null)',
            'payment_files (id integer primary key autoincrement, filename text, payment_id integer, payment_type text)',
            'treasury_tax_rates (id integer primary key autoincrement, tax text, code text null, rate numeric null, amount numeric null, min_operation numeric null, valid_from text, valid_to text null, legal_reference text, created_at text null, updated_at text null)',
            'payment_evidences (id integer primary key autoincrement, payable_type text, payable_id integer, sunat_payment_code text, operation_number text null, bank_id integer null, bank_account_id integer null, operation_date text, payer_or_beneficiary_document text null, payer_or_beneficiary_name text null, third_party_payment integer, third_party_reported_to_sunat integer, bancarization_exception text null, created_at text null, updated_at text null)',
        ] as $table) {
            $this->db->statement('create table ' . $table);
        }

        $this->db->table('bank_accounts')->insert([
            'id' => 10,
            'bank_id' => 2,
            'description' => 'BCP Corriente',
            'number' => '191-123456',
            'currency_type_id' => 'PEN',
            'status' => 1,
            'initial_balance' => 0,
            'show_in_documents' => 1,
            'account_type' => 'checking',
            'itf_exempt' => 0,
        ]);

        $this->db->table('payment_method_types')->insert([
            ['id' => '01', 'description' => 'Efectivo', 'has_card' => 0, 'sunat_code' => '008', 'is_cash' => 1],
            ['id' => '04', 'description' => 'Transferencia', 'has_card' => 0, 'sunat_code' => '003', 'is_cash' => 0],
        ]);

        $this->db->table('treasury_tax_rates')->insert([
            ['tax' => 'bancarization_threshold_pen', 'code' => null, 'rate' => null, 'amount' => 2000.00, 'min_operation' => null, 'valid_from' => '2022-04-01', 'valid_to' => null, 'legal_reference' => 'DL 1529'],
            ['tax' => 'bancarization_threshold_usd', 'code' => null, 'rate' => null, 'amount' => 500.00, 'min_operation' => null, 'valid_from' => '2022-04-01', 'valid_to' => null, 'legal_reference' => 'DL 1529'],
        ]);

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

    private function makeService(): PaymentEvidenceService
    {
        return new PaymentEvidenceService(new BancarizationChecker(new TaxRateRepository()));
    }

    public function test_hydrates_defaults_upserts_idempotently_and_rejects_unknown_alias(): void
    {
        $service = $this->makeService();

        $this->db->table('documents')->insert([
            'id' => 1,
            'state_type_id' => '05',
            'currency_type_id' => 'PEN',
            'exchange_rate_sale' => 3.75,
            'total' => 3000.00,
            'customer' => json_encode(['number' => '20555666777', 'name' => 'Cliente Ejemplo SAC']),
        ]);
        $this->db->table('document_payments')->insert([
            'id' => 1,
            'document_id' => 1,
            'date_of_payment' => '2026-11-21',
            'payment_method_type_id' => '04',
            'has_card' => 0,
            'reference' => 'OP-9988',
            'payment' => 3000.00,
        ]);
        $this->db->table('global_payments')->insert([
            'soap_type_id' => '02',
            'destination_id' => 10,
            'destination_type' => BankAccount::class,
            'payment_id' => 1,
            'payment_type' => DocumentPayment::class,
            'user_id' => 1,
        ]);

        $defaultsPayload = $service->getWithDefaults('document_payment', 1);
        $this->assertNull($defaultsPayload['evidence']);
        $this->assertSame('2026-11-21', $defaultsPayload['defaults']['operation_date']);
        $this->assertSame('OP-9988', $defaultsPayload['defaults']['operation_number']);
        $this->assertSame('003', $defaultsPayload['defaults']['sunat_payment_code']);
        $this->assertSame(10, $defaultsPayload['defaults']['bank_account_id']);
        $this->assertSame(2, $defaultsPayload['defaults']['bank_id']);
        $this->assertSame('20555666777', $defaultsPayload['defaults']['payer_or_beneficiary_document']);
        $this->assertSame('Cliente Ejemplo SAC', $defaultsPayload['defaults']['payer_or_beneficiary_name']);
        $this->assertTrue($defaultsPayload['context']['bancarization']['exceeds_threshold']);

        // Upsert create then update keeps exactly 1 row
        $created = $service->upsert('document_payment', 1, [
            'sunat_payment_code' => '003',
            'operation_number' => 'OP-9988',
            'bank_account_id' => 10,
            'operation_date' => '2026-11-21',
            'payer_or_beneficiary_document' => '20555666777',
            'payer_or_beneficiary_name' => 'Cliente Ejemplo SAC',
            'third_party_payment' => true,
            'third_party_reported_to_sunat' => true,
        ]);
        $this->assertSame(2, (int) $created->bank_id);
        $this->assertTrue($created->third_party_payment);
        $this->assertTrue($created->third_party_reported_to_sunat);

        $updated = $service->upsert('document_payment', 1, [
            'sunat_payment_code' => '001',
            'operation_number' => 'OP-9988-REV',
            'operation_date' => '2026-11-22',
        ]);
        $this->assertSame($created->id, $updated->id);
        $this->assertSame(1, PaymentEvidence::count());
        $this->assertSame('OP-9988-REV', $updated->operation_number);

        // Cash code above threshold without art. 6 exception rejected
        try {
            $service->upsert('document_payment', 1, [
                'sunat_payment_code' => '009',
                'operation_date' => '2026-11-22',
            ]);
            $this->fail('Expected ValidationException for cash code above threshold without exception.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('bancarization_exception', $e->errors());
        }

        // Unknown alias rejected
        $this->expectException(ValidationException::class);
        $service->resolvePayable('non_existent_model', 1);
    }

    public function test_deleting_legacy_payment_cascades_to_payment_evidences_without_touching_others(): void
    {
        $service = $this->makeService();

        $this->db->table('documents')->insert([
            'id' => 1,
            'state_type_id' => '05',
            'currency_type_id' => 'PEN',
            'exchange_rate_sale' => 3.75,
            'total' => 500.00,
        ]);
        $this->db->table('document_payments')->insert([
            ['id' => 1, 'document_id' => 1, 'date_of_payment' => '2026-11-21', 'payment_method_type_id' => '04', 'has_card' => 0, 'reference' => 'A1', 'payment' => 200.00],
            ['id' => 2, 'document_id' => 1, 'date_of_payment' => '2026-11-21', 'payment_method_type_id' => '04', 'has_card' => 0, 'reference' => 'A2', 'payment' => 300.00],
        ]);

        $service->upsert('document_payment', 1, ['sunat_payment_code' => '003', 'operation_date' => '2026-11-21']);
        $service->upsert('document_payment', 2, ['sunat_payment_code' => '003', 'operation_date' => '2026-11-21']);
        $this->assertSame(2, PaymentEvidence::count());

        DocumentPayment::findOrFail(1)->delete();

        $this->assertSame(0, PaymentEvidence::where('payable_type', DocumentPayment::class)->where('payable_id', 1)->count());
        $this->assertSame(1, PaymentEvidence::where('payable_type', DocumentPayment::class)->where('payable_id', 2)->count());
    }
}
