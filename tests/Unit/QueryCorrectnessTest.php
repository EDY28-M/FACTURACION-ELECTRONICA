<?php

namespace Tests\Unit;

use App\Models\Tenant\Item;
use App\Models\Tenant\Person;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Grammars\MySqlGrammar;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\DatabasePresenceVerifier;
use Illuminate\Validation\Factory;
use Modules\Document\Traits\SearchTrait;
use Modules\Finance\Helpers\ToPay;
use Modules\Hotel\Http\Controllers\HotelReceptionController;
use Modules\Hotel\Http\Requests\HotelAddRateToRoomRequest;
use Modules\Hotel\Models\HotelRent;
use PHPUnit\Framework\TestCase;

class QueryCorrectnessTest extends TestCase
{
    private $previousContainer;
    private $previousFacade;
    private $previousResolver;
    private $previousDispatcher;
    private $previousScopes;
    private $app;
    private $db;
    private $connection;
    private $auth;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousContainer = Container::getInstance();
        $this->previousFacade = Facade::getFacadeApplication();
        $this->previousResolver = Model::getConnectionResolver();
        $this->previousDispatcher = Model::getEventDispatcher();
        $scopes = new \ReflectionProperty(Model::class, 'globalScopes');
        $scopes->setAccessible(true);
        $this->previousScopes = $scopes->getValue();
        $this->app = new Container();
        Container::setInstance($this->app);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->app);
        $this->app->instance('config', new Repository());
        $capsule = new Manager($this->app);
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''], 'tenant');
        $this->db = $capsule->getDatabaseManager();
        $this->db->setDefaultConnection('tenant');
        $this->app->instance('db', $this->db);
        Model::setConnectionResolver($this->db);
        Model::setEventDispatcher(new Dispatcher($this->app));
        $this->connection = $this->db->connection('tenant');
        $this->auth = new class {
            public $type = 'seller';
            public function user() { return (object) ['type' => $this->type, 'establishment_id' => 1]; }
        };
        $this->app->instance(\Illuminate\Contracts\Auth\Factory::class, $this->auth);
        $this->app->instance('request', Request::create('/'));
        // These tests execute real selection/filtering queries, not unrelated catalog eager loads.
        foreach ([Item::class, Person::class] as $model) {
            $model::addGlobalScope('query-fixture', function ($query) { $query->setEagerLoads([]); });
        }
        foreach ([
            'items (id integer primary key, description text, internal_id text, barcode text, unit_type_id text, active integer, is_set integer, category_id integer, brand_id integer, attributes text)',
            'warehouses (id integer primary key, establishment_id integer)',
            'item_warehouse (id integer primary key, item_id integer, warehouse_id integer, stock numeric)',
            'categories (id integer primary key, name text)',
            'brands (id integer primary key, name text)',
            'persons (id integer primary key, type text, number text, name text, identity_document_type_id text, address text, email text)',
        ] as $table) { $this->connection->statement('create table ' . $table); }
        $this->connection->table('warehouses')->insert([['id' => 1, 'establishment_id' => 1], ['id' => 2, 'establishment_id' => 2]]);
    }

    protected function tearDown(): void
    {
        $scopes = new \ReflectionProperty(Model::class, 'globalScopes');
        $scopes->setAccessible(true);
        $scopes->setValue(null, $this->previousScopes);
        if ($this->previousResolver) { Model::setConnectionResolver($this->previousResolver); }
        else { Model::unsetConnectionResolver(); }
        if ($this->previousDispatcher) { Model::setEventDispatcher($this->previousDispatcher); }
        else { Model::unsetEventDispatcher(); }
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previousFacade);
        Container::setInstance($this->previousContainer);
        parent::tearDown();
    }

    private function item(int $id, array $overrides = [], ?int $warehouse = 1): void
    {
        $this->connection->table('items')->insert(array_merge([
            'id' => $id, 'description' => 'Fixture ' . $id, 'internal_id' => 'code-' . $id,
            'barcode' => 'barcode-' . $id, 'unit_type_id' => 'NIU', 'active' => 1, 'is_set' => 0,
            'category_id' => null, 'brand_id' => null, 'attributes' => null,
        ], $overrides));
        if ($warehouse !== null) {
            $this->connection->table('item_warehouse')->insert(['item_id' => $id, 'warehouse_id' => $warehouse, 'stock' => 1]);
        }
    }

    public function test_warehouse_service_alternative_cannot_bypass_existing_or_following_filters(): void
    {
        $this->item(1);
        $this->item(2, ['unit_type_id' => 'ZZ'], null);
        $this->item(3, ['unit_type_id' => 'ZZ', 'active' => 0], null);
        $this->item(4, ['unit_type_id' => 'ZZ', 'is_set' => 1], null);
        $this->item(5, [], 2);
        $this->assertSame([1, 2], Item::whereIsActive()->whereNotIsSet()->whereWarehouse()->orderBy('id')->pluck('id')->all());
        $this->assertSame([1, 2], Item::whereWarehouse()->whereIsActive()->whereNotIsSet()->orderBy('id')->pluck('id')->all());
        $this->assertSame([1], Item::where('id', 1)->whereWarehouse()->pluck('id')->all());
        $this->assertSame([1], Item::whereWarehouse()->where('unit_type_id', '!=', 'ZZ')->pluck('id')->all());
    }

    private function search()
    {
        // Use the production MySQL JSON grammar on SQLite with an equivalent fixture function.
        $this->connection->setQueryGrammar(new MySqlGrammar());
        $this->connection->getPdo()->sqliteCreateFunction('json_contains', function ($json, $needle) {
            $needle = json_decode($needle, true);
            foreach (json_decode($json ?? '[]', true) ?? [] as $value) {
                if (is_array($value) && array_intersect_assoc($value, $needle) === $needle) { return 1; }
            }
            return 0;
        }, 2);
        return new class { use SearchTrait; };
    }

    public function test_service_search_groups_every_alternative_under_type_active_and_set_restrictions(): void
    {
        $this->connection->table('categories')->insert(['id' => 1, 'name' => 'needle']);
        $this->connection->table('brands')->insert(['id' => 1, 'name' => 'needle']);
        foreach ([['description' => 'needle'], ['internal_id' => 'needle'], ['category_id' => 1], ['brand_id' => 1], ['attributes' => '[{"value":"needle"}]']] as $index => $match) {
            $this->item($index + 1, array_merge(['unit_type_id' => 'ZZ'], $match), null);
            $this->item($index + 11, array_merge(['unit_type_id' => 'ZZ', 'active' => 0], $match), null);
            $this->item($index + 21, array_merge(['unit_type_id' => 'ZZ', 'is_set' => 1], $match), null);
            $this->item($index + 31, $match);
        }
        $request = new Request(['input' => 'needle']);
        $this->assertSame([1, 2, 3, 4, 5], $this->search()->getItemsServices($request)->pluck('id')->sort()->values()->all());
    }

    public function test_product_search_respects_warehouse_and_active_for_every_alternative_and_keeps_services(): void
    {
        $this->connection->table('categories')->insert(['id' => 1, 'name' => 'needle']);
        $this->connection->table('brands')->insert(['id' => 1, 'name' => 'needle']);
        foreach ([['description' => 'needle'], ['internal_id' => 'needle'], ['category_id' => 1], ['brand_id' => 1], ['attributes' => '[{"value":"needle"}]']] as $index => $match) {
            $this->item($index + 1, $match);
            $this->item($index + 11, array_merge(['active' => 0], $match));
            $this->item($index + 21, $match, 2);
        }
        $this->item(40, ['unit_type_id' => 'ZZ', 'description' => 'needle'], null);
        $this->item(41, ['unit_type_id' => 'ZZ', 'description' => 'not a match'], null);
        $this->assertSame([1, 2, 3, 4, 5, 40], $this->search()->getItemsNotServices(new Request(['input' => 'needle']))->pluck('id')->sort()->values()->all());
    }

    public function test_id_and_barcode_searches_do_not_leak_unrequested_services(): void
    {
        $this->item(1);
        $this->item(2, ['unit_type_id' => 'ZZ'], null);
        $this->item(3, ['unit_type_id' => 'ZZ', 'active' => 0], null);
        $search = $this->search();
        foreach (['getItemsServices', 'getItemsNotServices'] as $method) {
            $this->assertSame([1], $search->$method(new Request(['items_id' => [1]]))->pluck('id')->all());
        }
        $this->assertSame([1], $search->getItemsNotServices(new Request(['search_by_barcode' => 1, 'input' => 'barcode-1']))->pluck('id')->all());
        $this->assertSame([2], $search->getItemsServices(new Request(['search_by_barcode' => 1, 'input' => 'barcode-2']))->pluck('id')->all());
        $this->auth->type = 'admin';
        $this->assertSame([1], $search->getItemsNotServicesById(1)->pluck('id')->all());
    }

    public function test_customer_and_supplier_number_search_cannot_bypass_person_type_or_identity_type(): void
    {
        $this->connection->table('persons')->insert([
            ['id' => 1, 'type' => 'customers', 'number' => 'needle', 'name' => 'Customer', 'identity_document_type_id' => '6'],
            ['id' => 2, 'type' => 'suppliers', 'number' => 'needle', 'name' => 'Supplier', 'identity_document_type_id' => '6'],
            ['id' => 3, 'type' => 'suppliers', 'number' => 'needle', 'name' => 'Wrong ID', 'identity_document_type_id' => '1'],
            ['id' => 4, 'type' => 'customers', 'number' => 'other', 'name' => 'needle', 'identity_document_type_id' => '6'],
            ['id' => 5, 'type' => 'suppliers', 'number' => 'other', 'name' => 'needle', 'identity_document_type_id' => '6'],
        ]);
        $request = new Request(['input' => 'needle', 'document_type_id' => '01']);
        $customers = (new \Modules\Document\Http\Controllers\DocumentController())->dataTableCustomers($request);
        $this->assertSame([1, 4], $customers['customers']->pluck('id')->sort()->values()->all());
        $suppliers = (new \Modules\Purchase\Http\Controllers\Api\PurchaseController())->searchSuppliers($request);
        $this->assertSame([2, 5], $suppliers->pluck('id')->sort()->values()->all());
    }

    private function payableFixtures(): void
    {
        $this->connection->getPdo()->sqliteCreateFunction('DATE_FORMAT', fn ($date, $format) => $date === null ? null : date('Y/m/d', strtotime($date)), 2);
        $this->connection->getPdo()->sqliteCreateFunction('CONCAT', fn (...$parts) => implode('', $parts));
        foreach ([
            'purchases (id integer primary key, user_id integer, supplier_id integer, state_type_id text, document_type_id text, date_of_issue text, date_of_due text, series text, number text, total numeric, establishment_id integer, currency_type_id text, exchange_rate_sale numeric)',
            'expenses (id integer primary key, user_id integer, supplier_id integer, state_type_id text, date_of_issue text, number text, total numeric, establishment_id integer, currency_type_id text, exchange_rate_sale numeric)',
            'purchase_payments (id integer primary key, purchase_id integer, payment numeric, date_of_payment text)',
            'expense_payments (id integer primary key, expense_id integer, payment numeric, date_of_payment text)',
        ] as $table) { $this->connection->statement('create table ' . $table); }
        $this->connection->table('persons')->insert(['id' => 1, 'name' => 'Supplier']);
        foreach ([1 => 1, 2 => 2, 3 => null] as $id => $user) {
            $values = ['id' => $id, 'user_id' => $user, 'supplier_id' => 1, 'state_type_id' => '01', 'date_of_issue' => '2026-11-15', 'number' => (string) $id, 'total' => 100, 'establishment_id' => 1, 'currency_type_id' => 'PEN', 'exchange_rate_sale' => 1];
            $this->connection->table('expenses')->insert($values);
            $this->connection->table('purchases')->insert($values + ['document_type_id' => '01', 'series' => 'F001', 'date_of_due' => null]);
        }
    }

    public function test_payable_optional_user_filter_and_expense_labels_in_both_date_branches(): void
    {
        $this->payableFixtures();
        foreach (['all', 'between_dates'] as $period) {
            $request = ['establishment_id' => 1, 'period' => $period, 'date_start' => '2026-11-01', 'date_end' => '2026-11-30', 'month_start' => null, 'month_end' => null, 'supplier_id' => null, 'user' => null];
            foreach ([null, '', 1, 2] as $user) {
                $request['user'] = $user;
                $records = ToPay::getToPay($request);
                $this->assertCount($user === null || $user === '' ? 6 : 2, $records);
                $this->assertCount($records->count() / 2, $records->where('type', 'expense'));
                if ($user !== null && $user !== '') { $this->assertSame([$user], $records->pluck('id')->unique()->values()->all()); }
            }
            $request['user'] = null;
            $request['supplier_id'] = 999;
            $this->assertCount(0, ToPay::getToPay($request));
        }
    }

    public function test_unfiltered_payable_export_labels_expenses_as_expenses(): void
    {
        $this->payableFixtures();
        $records = ToPay::getToPayNoFilter();
        $this->assertCount(6, $records);
        $this->assertCount(3, $records->where('type', 'expense'));
        $this->assertCount(0, $records->where('type', 'sale_note'));
    }

    public function test_reception_only_hydrates_latest_rent_for_each_visible_occupied_room(): void
    {
        foreach ([
            'hotel_rooms (id integer primary key, name text, status text, hotel_floor_id integer, hotel_category_id integer)',
            'hotel_floors (id integer primary key, description text)',
            'hotel_categories (id integer primary key, description text)',
            'hotel_room_rates (id integer primary key, hotel_room_id integer, hotel_rate_id integer, price numeric)',
            'hotel_rents (id integer primary key, hotel_room_id integer, notes text)',
        ] as $table) { $this->connection->statement('create table ' . $table); }
        foreach ([1 => 'OCUPADO', 2 => 'OCUPADO', 3 => 'DISPONIBLE', 4 => 'OCUPADO'] as $id => $status) {
            $this->connection->table('hotel_rooms')->insert(['id' => $id, 'name' => 'Room ' . $id, 'status' => $status, 'hotel_floor_id' => $id === 4 ? 2 : 1]);
        }
        for ($id = 1; $id <= 100; $id++) {
            $this->connection->table('hotel_rents')->insert(['id' => $id, 'hotel_room_id' => $id % 4 + 1, 'notes' => 'History']);
        }
        $hydrated = [];
        Model::getEventDispatcher()->listen('eloquent.retrieved: ' . HotelRent::class, function ($rent) use (&$hydrated) { $hydrated[] = $rent->id; });
        $this->app->instance('request', Request::create('/hotels/reception', 'GET', ['hotel_floor_id' => 1]));
        $method = new \ReflectionMethod(HotelReceptionController::class, 'getRooms');
        $method->setAccessible(true);
        $rooms = $method->invoke(new HotelReceptionController())->keyBy('id');
        $this->assertSame(100, $rooms[1]->rent->id);
        $this->assertSame(97, $rooms[2]->rent->id);
        $this->assertSame([], $rooms[3]->rent);
        $this->assertCount(3, $rooms);
        sort($hydrated);
        $this->assertSame([97, 100], $hydrated);
    }

    public function test_hotel_rate_unique_rule_uses_route_room_instead_of_body_id(): void
    {
        $this->connection->statement('create table hotel_room_rates (id integer primary key, hotel_room_id integer, hotel_rate_id integer, price numeric)');
        $this->connection->table('hotel_room_rates')->insert(['hotel_room_id' => 1, 'hotel_rate_id' => 5, 'price' => 10]);
        $factory = new Factory(new Translator(new ArrayLoader(), 'en'), $this->app);
        $factory->setPresenceVerifier(new DatabasePresenceVerifier($this->db));
        foreach ([1 => false, 2 => true] as $roomId => $passes) {
            $request = HotelAddRateToRoomRequest::create('/hotels/rooms/' . $roomId . '/rates/store', 'POST', ['id' => $roomId === 1 ? 2 : 1, 'hotel_room_id' => 999, 'hotel_rate_id' => 5, 'price' => 10]);
            $route = new Route('POST', 'hotels/rooms/{id}/rates/store', fn () => null);
            $route->bind($request);
            $request->setRouteResolver(fn () => $route);
            $this->assertSame($passes, $factory->make($request->all(), $request->rules())->passes());
        }
    }
}
