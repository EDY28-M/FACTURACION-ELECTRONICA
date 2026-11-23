<?php

namespace Tests\Unit;

use App\Http\Resources\Tenant\OrderCollection;
use App\Models\Tenant\Item;
use App\Models\Tenant\ItemWarehouse;
use App\Models\Tenant\Order;
use App\Services\Ecommerce\CheckoutRules;
use App\Services\Ecommerce\OrderDocumentBuilder;
use App\Services\Ecommerce\OrderFulfillment;
use App\Services\Ecommerce\StoreItemPresenter;
use App\Services\Payments\CartPricing;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory as ValidatorFactory;
use Modules\Ecommerce\Http\Resources\ItemBarCollection;
use Modules\Ecommerce\Http\ViewComposers\InformationContactViewComposer;
use PHPUnit\Framework\TestCase;

/**
 * Storefront / orders fixes (QA-01 .. QA-12). In-memory sqlite, no HTTP.
 */
class EcommerceStorefrontFixesTest extends TestCase
{
    private $previousContainer;
    private $previousFacade;
    private $previousResolver;
    private $previousDispatcher;
    private $previousScopes;
    private $db;

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

        $app = new Container();
        Container::setInstance($app);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);
        $app->instance('config', new Repository());
        $app->instance('url', new \Illuminate\Routing\UrlGenerator(new \Illuminate\Routing\RouteCollection(), \Illuminate\Http\Request::create('http://tenant.test')));
        $app->instance('validator', new ValidatorFactory(new Translator(new ArrayLoader(), 'es')));

        $manager = new Manager($app);
        $manager->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''], 'tenant');
        $database = $manager->getDatabaseManager();
        $database->setDefaultConnection('tenant');
        $app->instance('db', $database);
        Model::setConnectionResolver($database);
        Model::setEventDispatcher(new Dispatcher($app));
        $this->db = $database->connection('tenant');

        foreach ([Item::class, Order::class] as $model) {
            $model::addGlobalScope('fixture', function ($query) { $query->setEagerLoads([]); });
        }

        foreach ([
            'orders (id integer primary key, external_id text, customer text, shipping_address text null, items text, total numeric, reference_payment text, document_external_id text null, number_document text null, status_order_id integer null, purchase text null, deleted_at text null)',
            'items (id integer primary key, description text, unit_type_id text, stock numeric default 0)',
            'item_warehouse (id integer primary key, item_id integer, warehouse_id integer, stock numeric)',
            'inventory_kardex (id integer primary key, date_of_issue text, item_id integer, warehouse_id integer, inventory_kardexable_id integer, inventory_kardexable_type text, quantity numeric)',
        ] as $table) {
            $this->db->statement('create table ' . substr($table, 0, -1) . ', created_at text null, updated_at text null)');
        }
        $this->db->table('items')->insert([
            ['id' => 1, 'description' => 'Mouse', 'unit_type_id' => 'NIU', 'stock' => 10],
            ['id' => 2, 'description' => 'Hosting', 'unit_type_id' => 'ZZ', 'stock' => 0],
        ]);
        $this->db->table('item_warehouse')->insert([
            ['id' => 1, 'item_id' => 1, 'warehouse_id' => 1, 'stock' => 10],
            ['id' => 2, 'item_id' => 2, 'warehouse_id' => 1, 'stock' => 0],
        ]);
        StoreItemPresenter::flush();
    }

    protected function tearDown(): void
    {
        StoreItemPresenter::flush();
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

    // ---------------------------------------------------------------- helpers

    private function item(array $attrs = []): object
    {
        return (object) array_merge([
            'id' => 1, 'internal_id' => 'A-1', 'name' => 'Mouse', 'description' => 'Mouse gamer', 'unit_type_id' => 'NIU',
            'sale_unit_price' => 100, 'has_igv' => 0, 'igv_general_rate' => 0, 'currency_type_id' => 'PEN',
            'sale_affectation_igv_type_id' => '10', 'image' => 'a.jpg', 'image_medium' => 'a.jpg', 'image_small' => 'a.jpg',
            'purchase_unit_price' => 23.93, 'percentage_of_profit' => 40, 'stock' => 7, 'barcode' => '775',
            'warehouses' => [['warehouse_description' => 'Principal', 'stock' => 7]],
            'currency_type' => (object) ['symbol' => 'S/'],
        ], $attrs);
    }

    private function igv(): callable
    {
        return function () { return 18.0; };
    }

    private function order(array $over = []): Order
    {
        $order = new Order();
        $order->forceFill(array_merge([
            'external_id' => 'ext-1',
            'customer' => ['apellidos_y_nombres_o_razon_social' => 'Ana', 'correo_electronico' => 'ana@example.test', 'telefono' => '999888777',
                'direccion' => 'Av 1', 'codigo_tipo_documento_identidad' => '1', 'numero_documento' => '76728374', 'identity_document_type_id' => '1'],
            'items' => [['id' => 1, 'internal_id' => 'A-1', 'name' => 'Mouse', 'description' => 'Mouse gamer', 'unit_type_id' => 'NIU',
                'unit_pen' => 118.0, 'cantidad' => 2, 'sub_total' => 236.0, 'sale_affectation_igv_type_id' => '10', 'igv_percentage' => 18.0]],
            'total' => 236.00,
            'reference_payment' => 'efectivo',
            'status_order_id' => 1,
        ], $over));
        $order->save();

        return $order;
    }

    // ------------------------------------------------- QA-05 pricing source

    public function test_igv_is_charged_only_on_taxed_affectation_types(): void
    {
        $taxed = CartPricing::unitPrices($this->item(), 1.0, $this->igv());
        $this->assertSame(118.0, $taxed['pen']);

        foreach (['20', '21', '30', '31', '40'] as $type) {
            $p = CartPricing::unitPrices($this->item(['sale_affectation_igv_type_id' => $type]), 1.0, $this->igv());
            $this->assertSame(100.0, $p['pen'], "affectation $type must not add IGV");
        }

        // has_igv already includes it
        $this->assertSame(100.0, CartPricing::unitPrices($this->item(['has_igv' => 1]), 1.0, $this->igv())['pen']);
        // legacy null affectation behaves as taxed
        $this->assertSame(118.0, CartPricing::unitPrices($this->item(['sale_affectation_igv_type_id' => null]), 1.0, $this->igv())['pen']);
    }

    public function test_cart_total_matches_the_presented_price_for_exonerated_and_usd_items(): void
    {
        $items = [
            1 => $this->item(['id' => 1, 'sale_affectation_igv_type_id' => '20', 'sale_unit_price' => 50]),
            2 => $this->item(['id' => 2, 'currency_type_id' => 'USD', 'sale_unit_price' => 10, 'currency_type' => (object) ['symbol' => '$']]),
        ];
        StoreItemPresenter::resolveWith(function () { return 3.5; }, $this->igv());

        $cart = CartPricing::total([['id' => 1, 'cantidad' => 2], ['id' => 2, 'cantidad' => 1]], $items, 3.5, $this->igv());

        $this->assertSame(50.0, $cart['lines'][0]['unit_pen']);
        $this->assertSame(41.3, $cart['lines'][1]['unit_pen']); // 10 * 1.18 * 3.5
        $this->assertSame(141.3, $cart['total']);
        // The catalogue shows exactly what the cart charges.
        $this->assertSame(50.0, StoreItemPresenter::present($items[1])['sale_unit_price']);
        $this->assertSame(41.3, StoreItemPresenter::present($items[2])['sale_unit_price']);
        $this->assertSame('PEN', StoreItemPresenter::present($items[2])['currency_type_id']);
    }

    public function test_usd_item_falls_back_to_its_own_currency_when_the_rate_is_unavailable(): void
    {
        StoreItemPresenter::resolveWith(function () { throw new \RuntimeException('down'); }, $this->igv());
        $p = StoreItemPresenter::present($this->item(['currency_type_id' => 'USD', 'sale_unit_price' => 10, 'currency_type' => (object) ['symbol' => '$']]));

        $this->assertSame('USD', $p['currency_type_id']);
        $this->assertSame('$', $p['currency_type_symbol']);
        $this->assertSame(11.8, $p['sale_unit_price']);
    }

    // ------------------------------------------------ QA-06 public payload

    public function test_public_payloads_never_expose_cost_stock_or_warehouses(): void
    {
        StoreItemPresenter::resolveWith(null, $this->igv());
        $forbidden = ['purchase_unit_price', 'percentage_of_profit', 'stock', 'stock_min', 'barcode', 'warehouses', 'warehouse_id', 'item_code', 'item_code_gs1', 'sale_unit'];

        foreach ([StoreItemPresenter::present($this->item()), StoreItemPresenter::presentForSearch($this->item())] as $payload) {
            foreach ($forbidden as $key) {
                $this->assertArrayNotHasKey($key, $payload);
            }
            $this->assertStringNotContainsString('23.93', json_encode($payload));
            $this->assertStringNotContainsString('775', json_encode($payload));
        }

        $present = StoreItemPresenter::present($this->item());
        $this->assertSame(['id', 'internal_id', 'unit_type_id', 'description', 'name', 'image', 'image_medium', 'image_small',
            'sale_affectation_igv_type_id', 'igv_general_rate', 'currency_type_id', 'currency_type_symbol', 'sale_unit_price'], array_keys($present));
    }

    public function test_items_bar_collection_serializes_only_the_search_fields(): void
    {
        StoreItemPresenter::resolveWith(null, $this->igv());
        $rows = (new ItemBarCollection(collect([$this->item()])))->toArray(null);

        $this->assertSame(['id', 'internal_id', 'description', 'name', 'image_url_small', 'sale_unit_price'], array_keys($rows->first()));
        $this->assertSame('S/ 118.00', $rows->first()['sale_unit_price']);
        $this->assertSame('http://tenant.test/storage/uploads/items/a.jpg', $rows->first()['image_url_small']);
    }

    // ------------------------------------------------------ QA-07 stock

    public function test_cart_refuses_quantities_above_available_stock_when_stock_is_controlled(): void
    {
        $items = [1 => $this->item()];
        $stock = function ($item) { return 3.0; };

        $this->assertSame(354.0, CartPricing::total([['id' => 1, 'cantidad' => 3]], $items, 1.0, $this->igv(), $stock)['total']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('No hay stock suficiente de "Mouse". Disponible: 3.');
        CartPricing::total([['id' => 1, 'cantidad' => 50]], $items, 1.0, $this->igv(), $stock);
    }

    public function test_stock_is_not_checked_when_the_callback_reports_no_control(): void
    {
        $items = [1 => $this->item()];
        $cart = CartPricing::total([['id' => 1, 'cantidad' => 50]], $items, 1.0, $this->igv(), function () { return null; });
        $this->assertSame(5900.0, $cart['total']);
    }

    // ------------------------------------------- QA-04 / QA-12 validation

    private function customer(array $over = []): array
    {
        return array_merge([
            'apellidos_y_nombres_o_razon_social' => 'Ana', 'correo_electronico' => 'ana@example.test', 'telefono' => '999888777',
            'direccion' => 'Av 1', 'codigo_tipo_documento_identidad' => '1', 'numero_documento' => '76728374', 'identity_document_type_id' => '1',
        ], $over);
    }

    public function test_checkout_customer_requires_name_and_valid_email(): void
    {
        $this->assertFalse(CheckoutRules::validate($this->customer())->fails());

        foreach ([
            ['apellidos_y_nombres_o_razon_social' => ''],
            ['correo_electronico' => ''],
            ['correo_electronico' => 'not-an-email'],
            ['apellidos_y_nombres_o_razon_social' => str_repeat('x', 300)],
            ['direccion' => str_repeat('x', 300)],
            ['numero_documento' => '123'],
            ['identity_document_type_id' => '6'],
            ['codigo_tipo_documento_identidad' => '6', 'numero_documento' => '123'],
        ] as $bad) {
            $this->assertTrue(CheckoutRules::validate($this->customer($bad))->fails(), json_encode($bad));
        }

        $v = CheckoutRules::validate($this->customer(['correo_electronico' => 'nope']));
        $this->assertSame('El correo electrónico no es válido.', $v->errors()->first('correo_electronico'));
        $this->assertFalse(CheckoutRules::validate($this->customer(['identity_document_type_id' => '6', 'codigo_tipo_documento_identidad' => '6', 'numero_documento' => '20123456789']))->fails());
    }

    public function test_checkout_customer_is_sanitized_and_the_session_email_wins(): void
    {
        $clean = CheckoutRules::sanitize($this->customer(['evil' => '<script>', 'correo_electronico' => 'spoof@x.test', 'telefono' => ['x']]), 'real@example.test');

        $this->assertSame('real@example.test', $clean['correo_electronico']);
        $this->assertArrayNotHasKey('evil', $clean);
        $this->assertArrayNotHasKey('telefono', $clean);
        $this->assertSame('PE', $clean['codigo_pais']);
    }

    public function test_large_anonymous_boleta_is_refused(): void
    {
        $doc = $this->customer(['codigo_tipo_documento_identidad' => '0', 'numero_documento' => '0']);
        $this->assertNotNull(CheckoutRules::identityErrorForTotal($doc, 700.01));
        $this->assertNull(CheckoutRules::identityErrorForTotal($doc, 700.0));
        $this->assertNull(CheckoutRules::identityErrorForTotal($this->customer(), 5000.0));
    }

    public function test_order_list_survives_orders_without_customer_name_or_email(): void
    {
        $order = $this->order(['customer' => ['telefono' => '1']]);
        $rows = (new OrderCollection(collect([Order::find($order->id)])))->toArray(null);

        $this->assertSame('', $rows->first()['customer']);
        $this->assertSame('', $rows->first()['customer_email']);
        $this->assertArrayNotHasKey('purchase', $rows->first());
    }

    // -------------------------------------------- QA-03 server document

    public function test_document_is_built_from_the_stored_order_not_from_client_data(): void
    {
        $order = $this->order(['purchase' => json_encode(['totales' => ['total_venta' => 0.01], 'items' => []])]);
        $doc = OrderDocumentBuilder::build(Order::find($order->id), [1 => $this->item()], '2026-11-22', '10:00:00', $this->igv());

        $this->assertSame('03', $doc['codigo_tipo_documento']);
        $this->assertSame(236.0, $doc['totales']['total_venta']);
        $this->assertSame(200.0, $doc['totales']['total_operaciones_gravadas']);
        $this->assertSame(36.0, $doc['totales']['total_igv']);
        $this->assertSame(118.0, $doc['items'][0]['precio_unitario']);
        $this->assertEqualsWithDelta(100.0, $doc['items'][0]['valor_unitario'], 0.000001);
        $this->assertSame('76728374', $doc['datos_del_cliente_o_receptor']['numero_documento']);
    }

    public function test_document_handles_exonerated_and_unaffected_lines_and_refuses_the_rest(): void
    {
        $lines = [
            ['id' => 1, 'name' => 'A', 'description' => 'A', 'unit_pen' => 50.0, 'cantidad' => 2, 'sale_affectation_igv_type_id' => '20'],
            ['id' => 2, 'name' => 'B', 'description' => 'B', 'unit_pen' => 10.0, 'cantidad' => 1, 'sale_affectation_igv_type_id' => '30'],
        ];
        $order = (object) ['customer' => $this->customer(), 'items' => $lines, 'total' => 110.0];
        $doc = OrderDocumentBuilder::build($order, [], '2026-11-22', '10:00:00', $this->igv());

        $this->assertSame(100.0, $doc['totales']['total_operaciones_exoneradas']);
        $this->assertSame(10.0, $doc['totales']['total_operaciones_inafectas']);
        $this->assertSame(0.0, $doc['totales']['total_igv']);
        $this->assertSame(110.0, $doc['totales']['total_venta']);

        $lines[0]['sale_affectation_igv_type_id'] = '11';
        $this->expectException(\InvalidArgumentException::class);
        OrderDocumentBuilder::build((object) ['customer' => $this->customer(), 'items' => $lines, 'total' => 110.0], [], 'd', 't', $this->igv());
    }

    public function test_document_refuses_total_mismatch_and_factura_without_ruc(): void
    {
        $line = [['id' => 1, 'name' => 'A', 'description' => 'A', 'unit_pen' => 118.0, 'cantidad' => 1, 'sale_affectation_igv_type_id' => '10', 'igv_percentage' => 18.0]];

        try {
            OrderDocumentBuilder::build((object) ['customer' => $this->customer(), 'items' => $line, 'total' => 5.0], [], 'd', 't');
            $this->fail('total mismatch accepted');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('no coincide', $e->getMessage());
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('RUC');
        OrderDocumentBuilder::build((object) ['customer' => $this->customer(['identity_document_type_id' => '6']), 'items' => $line, 'total' => 118.0], [], 'd', 't');
    }

    // ----------------------------------- QA-01 attach, QA-08 status/stock

    private function document(array $over = []): object
    {
        return (object) array_merge(['external_id' => 'doc-1', 'number_full' => 'B001-1', 'total' => 236.0, 'state_type_id' => '01'], $over);
    }

    public function test_attach_document_links_once_and_marks_payment_verified(): void
    {
        $order = $this->order();
        $lookup = function ($id) { return $id === 'doc-1' ? $this->document() : null; };

        $linked = OrderFulfillment::attachDocument($order->id, 'doc-1', $lookup);
        $this->assertSame('doc-1', $linked->document_external_id);
        $this->assertSame('B001-1', $linked->number_document);
        $this->assertEquals(2, $linked->status_order_id);

        try {
            OrderFulfillment::attachDocument($order->id, 'doc-1', $lookup);
            $this->fail('second link accepted');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('ya tiene un comprobante', $e->getMessage());
        }
    }

    public function test_attach_document_rejects_unknown_voided_mismatched_and_shared_documents(): void
    {
        $order = $this->order();
        $cases = [
            'doc-x' => function () { return null; },
            'doc-1' => function () { return $this->document(['state_type_id' => '11']); },
        ];
        foreach ($cases as $id => $lookup) {
            try {
                OrderFulfillment::attachDocument($order->id, $id, $lookup);
                $this->fail('accepted '.$id);
            } catch (\InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
        try {
            OrderFulfillment::attachDocument($order->id, 'doc-1', function () { return $this->document(['total' => 1.0]); });
            $this->fail('total mismatch accepted');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('no coincide', $e->getMessage());
        }

        $other = $this->order(['document_external_id' => 'doc-1', 'number_document' => 'B001-1']);
        $this->expectException(\InvalidArgumentException::class);
        OrderFulfillment::attachDocument($order->id, 'doc-1', function () { return $this->document(); });
    }

    public function test_attach_document_to_unknown_order_is_not_found(): void
    {
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        OrderFulfillment::attachDocument(999, 'doc-1', function () { return $this->document(); });
    }

    public function test_dispatch_is_a_pure_status_change_and_never_touches_stock(): void
    {
        $order = $this->order();

        $r = OrderFulfillment::changeStatus($order->id, 3);
        $this->assertEquals(3, $r['order']->status_order_id);
        $this->assertEquals(3, Order::find($order->id)->status_order_id);
        $this->assertSame(10.0, (float) $this->db->table('item_warehouse')->where('id', 1)->value('stock'));
        $this->assertSame(10.0, (float) $this->db->table('items')->where('id', 1)->value('stock'));
        $this->assertSame(0, $this->db->table('inventory_kardex')->count());

        // Repeating the transition is a no-op (and still moves nothing).
        OrderFulfillment::changeStatus($order->id, 3);
        $this->assertSame(10.0, (float) $this->db->table('item_warehouse')->where('id', 1)->value('stock'));
        $this->assertSame(0, $this->db->table('inventory_kardex')->count());
    }

    public function test_dispatched_or_confirmed_orders_cannot_go_back(): void
    {
        $order = $this->order();
        OrderFulfillment::changeStatus($order->id, 4);

        foreach ([1, 2, 3] as $status) {
            try {
                OrderFulfillment::changeStatus($order->id, $status);
                $this->fail('went back to '.$status);
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('estado anterior', $e->getMessage());
            }
        }
        $this->assertEquals(4, Order::find($order->id)->status_order_id);
    }

    public function test_status_change_and_unknown_order(): void
    {
        $order = $this->order();
        $r = OrderFulfillment::changeStatus($order->id, 4);
        $this->assertEquals(4, Order::find($order->id)->status_order_id);

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        OrderFulfillment::changeStatus(404, 2);
    }

    public function test_stock_moves_only_through_the_normal_sale_path_when_the_comprobante_is_issued(): void
    {
        // The order's comprobante is a regular document (no sale note / order note), so the
        // DocumentItem::created listener moves stock exactly once; the order flow never writes stock.
        $provider = file_get_contents(__DIR__.'/../../modules/Inventory/Providers/InventoryKardexServiceProvider.php');
        $this->assertStringContainsString('if(!$document->sale_note_id && !$document->order_note_id)', $provider);

        $builder = file_get_contents(__DIR__.'/../../app/Services/Ecommerce/OrderDocumentBuilder.php');
        $this->assertStringNotContainsString('sale_note_id', $builder);
        $this->assertStringNotContainsString('order_note_id', $builder);

        $fulfillment = file_get_contents(__DIR__.'/../../app/Services/Ecommerce/OrderFulfillment.php');
        $this->assertStringNotContainsString('ItemWarehouse', $fulfillment);
        $this->assertStringNotContainsString('inventory_kardex', $fulfillment);
    }

    public function test_safe_mail_never_throws_and_skips_invalid_recipients(): void
    {
        $sent = [];
        $ok = \App\Services\Ecommerce\SafeMail::send('buyer@example.com', new \stdClass, function ($to, $m) use (&$sent) { $sent[] = $to; });
        $this->assertTrue($ok);
        $this->assertSame(['buyer@example.com'], $sent);

        // SMTP failure (535/530 Authentication Required): swallowed, flow continues.
        $failed = \App\Services\Ecommerce\SafeMail::send('buyer@example.com', new \stdClass, function () {
            throw new \RuntimeException('Expected response code "250" but got code "530", with message "5.7.0 Authentication Required"');
        });
        $this->assertFalse($failed);

        foreach ([null, '', '   ', 'no-es-email'] as $bad) {
            $this->assertFalse(\App\Services\Ecommerce\SafeMail::send($bad, new \stdClass, function () { $this->fail('sent to invalid recipient'); }));
        }
    }

    // ------------------------------------------------------------ QA-16

    public function test_social_links_are_rendered_only_when_they_are_http_urls(): void
    {
        $safe = [InformationContactViewComposer::class, 'safeUrl'];

        $this->assertSame('https://facebook.com/tienda', $safe('https://facebook.com/tienda'));
        $this->assertSame('http://x.test/a?b=1', $safe(' http://x.test/a?b=1 '));
        foreach (['javascript:alert(1)', 'not a url', 'data:text/html,x', '//evil.test', 'ftp://x.test', '', null, 'https://x.test/"onclick="x'] as $bad) {
            $this->assertNull($safe($bad), (string) $bad);
        }
    }
}
