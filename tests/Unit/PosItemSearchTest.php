<?php

namespace Tests\Unit;

use App\CoreFacturalo\Helpers\Pos\ItemSearch;
use App\Models\Tenant\Item;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;

/**
 * Verifies multi-token POS search (ItemSearch) and grouped warehouse/active
 * SQL precedence so unrelated items, inactive items, services (ZZ) or other
 * warehouses never leak into POS search results.
 */
class PosItemSearchTest extends TestCase
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
            public function check() { return true; }
            public function id() { return 1; }
            public function user() { return (object) ['id' => 1, 'establishment_id' => 1, 'type' => 'admin']; }
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

        Item::addGlobalScope('pos-search-fixture', fn ($q) => $q->setEagerLoads([]));

        $this->db->statement('create table warehouses (id integer primary key, establishment_id integer, description text)');
        $this->db->statement('create table categories (id integer primary key, name text)');
        $this->db->statement('create table brands (id integer primary key, name text)');
        $this->db->statement('create table items (id integer primary key, description text, second_name text, name text, model text, internal_id text, barcode text, unit_type_id text, category_id integer, brand_id integer, active integer, series_enabled integer)');
        $this->db->statement('create table item_warehouse (id integer primary key, item_id integer, warehouse_id integer, stock real)');

        $this->db->table('warehouses')->insert([
            ['id' => 1, 'establishment_id' => 1, 'description' => 'Principal'],
            ['id' => 2, 'establishment_id' => 2, 'description' => 'Sucursal 2'],
        ]);
        $this->db->table('categories')->insert([
            ['id' => 1, 'name' => 'Redes y Conectividad'],
            ['id' => 2, 'name' => 'Audio'],
        ]);
        $this->db->table('brands')->insert([
            ['id' => 1, 'name' => 'TP-Link'],
            ['id' => 2, 'name' => 'Anker'],
        ]);

        $this->db->table('items')->insert([
            ['id' => 1, 'description' => 'Access Point Empotrable PoE', 'second_name' => null, 'name' => null, 'model' => 'EAP225', 'internal_id' => 'RD-006', 'barcode' => '775000111222', 'unit_type_id' => 'NIU', 'category_id' => 1, 'brand_id' => 1, 'active' => 1, 'series_enabled' => 0],
            ['id' => 2, 'description' => 'Adaptador de Audio Bluetooth 5.0 USB', 'second_name' => null, 'name' => null, 'model' => 'UB500', 'internal_id' => 'AU-008', 'barcode' => '775000333444', 'unit_type_id' => 'NIU', 'category_id' => 2, 'brand_id' => 1, 'active' => 1, 'series_enabled' => 0],
            ['id' => 3, 'description' => 'Adaptador USB-C a HDMI 4K', 'second_name' => null, 'name' => null, 'model' => 'A8312', 'internal_id' => 'CB-005', 'barcode' => '775000555666', 'unit_type_id' => 'NIU', 'category_id' => 1, 'brand_id' => 2, 'active' => 1, 'series_enabled' => 0],
            // Inactive item (must NEVER appear)
            ['id' => 4, 'description' => 'Adaptador TP-Link Descontinuado', 'second_name' => null, 'name' => null, 'model' => null, 'internal_id' => 'RD-999', 'barcode' => null, 'unit_type_id' => 'NIU', 'category_id' => 1, 'brand_id' => 1, 'active' => 0, 'series_enabled' => 0],
            // Item in another warehouse only (must NEVER appear)
            ['id' => 5, 'description' => 'Adaptador TP-Link Otra Sucursal', 'second_name' => null, 'name' => null, 'model' => null, 'internal_id' => 'RD-888', 'barcode' => null, 'unit_type_id' => 'NIU', 'category_id' => 1, 'brand_id' => 1, 'active' => 1, 'series_enabled' => 0],
            // Service ZZ (must NEVER leak via ungrouped OR in whereWarehouse!)
            ['id' => 6, 'description' => 'Instalación de Redes', 'second_name' => null, 'name' => null, 'model' => null, 'internal_id' => 'SV-001', 'barcode' => null, 'unit_type_id' => 'ZZ', 'category_id' => 1, 'brand_id' => null, 'active' => 1, 'series_enabled' => 0],
            // Series-enabled item (must NEVER appear in POS)
            ['id' => 7, 'description' => 'Adaptador TP-Link con Serie', 'second_name' => null, 'name' => null, 'model' => null, 'internal_id' => 'RD-777', 'barcode' => null, 'unit_type_id' => 'NIU', 'category_id' => 1, 'brand_id' => 1, 'active' => 1, 'series_enabled' => 1],
        ]);

        $this->db->table('item_warehouse')->insert([
            ['id' => 1, 'item_id' => 1, 'warehouse_id' => 1, 'stock' => 5],
            ['id' => 2, 'item_id' => 2, 'warehouse_id' => 1, 'stock' => 10],
            ['id' => 3, 'item_id' => 3, 'warehouse_id' => 1, 'stock' => 8],
            ['id' => 4, 'item_id' => 4, 'warehouse_id' => 1, 'stock' => 3],
            ['id' => 5, 'item_id' => 5, 'warehouse_id' => 2, 'stock' => 9],
            ['id' => 6, 'item_id' => 7, 'warehouse_id' => 1, 'stock' => 2],
        ]);
    }

    protected function tearDown(): void
    {
        if ($this->previousResolver !== null) {
            Model::setConnectionResolver($this->previousResolver);
        } else {
            Model::unsetConnectionResolver();
        }
        if ($this->previousDispatcher !== null) {
            Model::setEventDispatcher($this->previousDispatcher);
        } else {
            Model::unsetEventDispatcher();
        }
        (new \ReflectionProperty(Model::class, 'globalScopes'))->setValue(null, $this->previousScopes);
        Container::setInstance($this->previousContainer);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previousFacadeApplication);
        parent::tearDown();
    }

    private function search(?string $input, ?int $cat = null, bool $barcodeMode = false): array
    {
        $query = Item::query()
            ->whereWarehouse()
            ->whereIsActive()
            ->where('series_enabled', 0)
            ->where('unit_type_id', '!=', 'ZZ');

        if ($cat !== null) {
            $query->where('category_id', $cat);
        }

        ItemSearch::apply($query, $input, $barcodeMode);

        return $query->orderBy('id')->pluck('id')->all();
    }

    public function test_tokenize_and_escape_like(): void
    {
        $this->assertSame(['adaptador', 'tp-link'], ItemSearch::tokenize('  adaptador   tp-link  ADAPTADOR '));
        $this->assertSame([], ItemSearch::tokenize('   '));
        $this->assertSame('50\\%\\_off', ItemSearch::escapeLike('50%_off'));
    }

    public function test_searches_across_name_code_barcode_model_brand_and_category(): void
    {
        $this->assertSame([1], $this->search('RD-006'));
        $this->assertSame([2], $this->search('775000333444'));
        $this->assertSame([2], $this->search('UB500'));
        $this->assertSame([1, 2], $this->search('TP-Link'));
        $this->assertSame([3], $this->search('Anker'));
        $this->assertSame([2], $this->search('Audio'));
        $this->assertSame([1, 3], $this->search('Redes'));
    }

    public function test_multi_word_search_works_in_any_order_across_mixed_fields(): void
    {
        // Word from description + word from brand, in reverse order
        $this->assertSame([2], $this->search('tp-link bluetooth'));
        $this->assertSame([2], $this->search('usb 5.0 tp-link'));
        $this->assertSame([3], $this->search('hdmi anker'));
        $this->assertSame([1], $this->search('poe redes'));
        // Non-matching term returns empty (never leaks unrelated or ZZ rows!)
        $this->assertSame([], $this->search('producto_inexistente_xyz'));
    }

    public function test_strictly_respects_warehouse_active_series_zz_and_category_filters(): void
    {
        // "Adaptador" exists on items 2, 3 (valid), 4 (inactive), 5 (warehouse 2), 7 (series_enabled)
        $this->assertSame([2, 3], $this->search('Adaptador'));

        // Category filter narrows results accurately
        $this->assertSame([3], $this->search('Adaptador', 1));
        $this->assertSame([2], $this->search('Adaptador', 2));

        // Barcode mode exact match + strict active/warehouse filters
        $this->assertSame([2], $this->search('775000333444', null, true));
        $this->assertSame([1, 2], $this->search('TP-Link', null, true));
    }
}
