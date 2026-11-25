<?php

namespace Tests\Unit;

use App\Http\Controllers\Tenant\PosController;
use App\Models\Tenant\BankAccount;
use App\Models\Tenant\CardBrand;
use App\Models\Tenant\Cash;
use App\Models\Tenant\Company;
use App\Models\Tenant\PaymentMethodType;
use App\Models\Tenant\Series;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;

/**
 * Verifies real PosController::payment_tables() behavior across all three SUNAT
 * environments: Demo (01), Producción (02 complete & incomplete), and Interno (03).
 */
class PosEnvironmentTablesTest extends TestCase
{
    private $previousContainer;
    private $previousFacadeApplication;
    private $previousResolver;
    private $previousDispatcher;
    private $previousScopes;
    private $app;
    private $db;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousContainer = Container::getInstance();
        $this->previousFacadeApplication = Facade::getFacadeApplication();
        $this->previousResolver = Model::getConnectionResolver();
        $this->previousDispatcher = Model::getEventDispatcher();
        $this->previousScopes = (new \ReflectionProperty(Model::class, 'globalScopes'))->getValue();

        $this->app = new Application(realpath(__DIR__.'/../..'));
        $this->app->instance('env', 'testing');
        $this->app->instance('auth', new class {
            public function user()
            {
                return (object) ['id' => 1, 'establishment_id' => 1, 'type' => 'admin'];
            }

            public function id()
            {
                return 1;
            }

            public function check()
            {
                return true;
            }
        });

        Container::setInstance($this->app);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->app);

        $capsule = new Manager($this->app);
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''], 'tenant');
        $database = $capsule->getDatabaseManager();
        $database->setDefaultConnection('tenant');
        $this->app->instance('db', $database);
        Model::setConnectionResolver($database);
        Model::setEventDispatcher(new Dispatcher($this->app));
        $this->db = $database->connection('tenant');

        Company::addGlobalScope('pos-tables-fixture', fn ($query) => $query->setEagerLoads([]));
        BankAccount::addGlobalScope('pos-tables-fixture', fn ($query) => $query->setEagerLoads([]));

        $this->db->statement('create table companies (id integer primary key, number text, soap_send_id text, soap_type_id text, soap_username text, soap_password text, soap_url text, certificate text, created_at text, updated_at text)');
        $this->db->statement('create table series (id integer primary key, establishment_id integer, document_type_id text, number text, contingency integer, created_at text, updated_at text)');
        $this->db->statement('create table payment_method_types (id text primary key, description text, has_card integer)');
        $this->db->statement('create table card_brands (id text primary key, description text)');
        $this->db->statement('create table cash (id integer primary key, user_id integer, state integer, reference_number text)');
        $this->db->statement('create table bank_accounts (id integer primary key, bank_id integer, description text, number text, currency_type_id text, status integer)');

        $this->db->table('series')->insert([
            ['id' => 1, 'establishment_id' => 1, 'document_type_id' => '01', 'number' => 'F001', 'contingency' => 0],
            ['id' => 2, 'establishment_id' => 1, 'document_type_id' => '03', 'number' => 'B001', 'contingency' => 0],
            ['id' => 3, 'establishment_id' => 1, 'document_type_id' => '80', 'number' => 'NV01', 'contingency' => 0],
            ['id' => 4, 'establishment_id' => 1, 'document_type_id' => '01', 'number' => '0001', 'contingency' => 1],
            ['id' => 5, 'establishment_id' => 2, 'document_type_id' => '80', 'number' => 'NV02', 'contingency' => 0],
        ]);
        $this->db->table('payment_method_types')->insert(['id' => '01', 'description' => 'Efectivo', 'has_card' => 0]);
        $this->db->table('card_brands')->insert(['id' => '01', 'description' => 'Visa']);
        $this->db->table('cash')->insert(['id' => 1, 'user_id' => 1, 'state' => 1, 'reference_number' => 'CAJA-1']);
    }

    protected function tearDown(): void
    {
        Model::setConnectionResolver($this->previousResolver);
        Model::setEventDispatcher($this->previousDispatcher);
        (new \ReflectionProperty(Model::class, 'globalScopes'))->setValue(null, $this->previousScopes);
        Container::setInstance($this->previousContainer);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previousFacadeApplication);
        parent::tearDown();
    }

    public function test_internal_mode_returns_only_sale_note_series(): void
    {
        $this->db->table('companies')->insert([
            'id' => 1,
            'number' => '20600000001',
            'soap_send_id' => '01',
            'soap_type_id' => '03',
        ]);

        $result = (new PosController())->payment_tables();

        $this->assertSame('03', $result['soap_type_id']);
        $this->assertSame([], $result['missing_for_production']);
        $this->assertSame(['NV01'], $result['series']->pluck('number')->all());
    }

    public function test_demo_mode_returns_factura_boleta_and_sale_note_series(): void
    {
        $this->db->table('companies')->insert([
            'id' => 1,
            'number' => '20600000001',
            'soap_send_id' => '01',
            'soap_type_id' => '01',
        ]);

        $result = (new PosController())->payment_tables();

        $this->assertSame('01', $result['soap_type_id']);
        $this->assertSame([], $result['missing_for_production']);
        $this->assertSame(['F001', 'B001', 'NV01'], $result['series']->pluck('number')->all());
    }

    public function test_incomplete_production_exposes_missing_fields_and_series(): void
    {
        $this->db->table('companies')->insert([
            'id' => 1,
            'number' => '20600000001',
            'soap_send_id' => '01',
            'soap_type_id' => '02',
            'soap_username' => null,
            'soap_password' => null,
            'certificate' => null,
        ]);

        $result = (new PosController())->payment_tables();

        $this->assertSame('02', $result['soap_type_id']);
        $this->assertSame(
            ['usuario SOL', 'clave SOL', 'certificado digital'],
            $result['missing_for_production']
        );
        $this->assertSame(['F001', 'B001', 'NV01'], $result['series']->pluck('number')->all());
    }
}
