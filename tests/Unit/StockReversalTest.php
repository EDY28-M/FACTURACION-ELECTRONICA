<?php

namespace Tests\Unit;

use App\Http\Controllers\Tenant\SaleNoteController;
use App\Models\Tenant\Document;
use App\Models\Tenant\DocumentItem;
use App\Models\Tenant\Establishment;
use App\Models\Tenant\Item;
use App\Models\Tenant\SaleNote;
use App\Models\Tenant\SaleNoteItem;
use App\Providers\AnulationServiceProvider;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\SQLiteGrammar;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use Modules\Inventory\Providers\InventoryVoidedServiceProvider;
use Modules\Order\Http\Controllers\OrderNoteController;
use Modules\Order\Models\OrderNote;
use Modules\Order\Models\OrderNoteItem;
use PHPUnit\Framework\TestCase;

class StockReversalTest extends TestCase
{
    private $previousContainer;
    private $previousFacade;
    private $previousResolver;
    private $previousDispatcher;
    private $previousScopes;
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
        $scopes->setAccessible(true);
        $this->previousScopes = $scopes->getValue();
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
                $this->locks[] = ['table' => $query->from, 'lock' => $value, 'transaction' => $query->getConnection()->transactionLevel()];
                return ''; // SQLite cannot enforce row locks; record the requested production intent only.
            }
        };
        $this->db->setQueryGrammar($this->grammar);
        // Keep actual stock relations and events; unrelated catalog eager loads are not needed here.
        foreach ([Document::class, DocumentItem::class, Item::class, Establishment::class, SaleNote::class, SaleNoteItem::class, OrderNote::class, OrderNoteItem::class] as $model) {
            $model::addGlobalScope('stock-fixture', function ($query) { $query->setEagerLoads([]); });
        }
        foreach ([
            'documents (id integer primary key, document_type_id text, state_type_id text, establishment_id integer, sale_note_id integer null, order_note_id integer null, notes text, prepayments text, series text, number text, was_deducted_prepayment integer, pending_amount_prepayment numeric)',
            'document_items (id integer primary key, document_id integer, item_id integer, quantity numeric, item text)',
            'order_notes (id integer primary key, state_type_id text, establishment_id integer, notes text)',
            'order_note_items (id integer primary key, order_note_id integer, item_id integer, quantity numeric, item text)',
            'sale_notes (id integer primary key, state_type_id text, establishment_id integer, order_note_id integer null, notes text)',
            'sale_note_items (id integer primary key, sale_note_id integer, item_id integer, quantity numeric, item text, warehouse_id integer, inventory_kardex_id integer)',
            'items (id integer primary key, stock numeric, is_set integer, unit_type_id text)',
            'item_sets (id integer primary key, item_id integer, individual_item_id integer, quantity numeric)',
            'item_warehouse (id integer primary key, item_id integer, warehouse_id integer, stock numeric)',
            'establishments (id integer primary key, description text)',
            'warehouses (id integer primary key, establishment_id integer)',
            'inventory_configurations (id integer primary key, stock_control integer)',
            'kardex (id integer primary key, type text, date_of_issue text, item_id integer, document_id integer null, purchase_id integer null, sale_note_id integer null, quantity numeric)',
            'inventory_kardex (id integer primary key, date_of_issue text, item_id integer, warehouse_id integer, inventory_kardexable_id integer, inventory_kardexable_type text, quantity numeric)',
            'item_lots_group (id integer primary key, quantity numeric)',
            'item_lots (id integer primary key, has_sale integer)',
        ] as $table) {
            $this->db->statement('create table ' . substr($table, 0, -1) . ', created_at text null, updated_at text null)');
        }
        $this->db->table('establishments')->insert(['id' => 1, 'description' => 'Fixture']);
        $this->db->table('warehouses')->insert([['id' => 1, 'establishment_id' => 1], ['id' => 2, 'establishment_id' => 2]]);
        $this->db->table('inventory_configurations')->insert(['id' => 1, 'stock_control' => 1]);
        $this->db->table('items')->insert([['id' => 1, 'stock' => 10, 'is_set' => 0, 'unit_type_id' => 'NIU'], ['id' => 2, 'stock' => 10, 'is_set' => 1, 'unit_type_id' => 'NIU']]);
        $this->db->table('item_warehouse')->insert([['id' => 1, 'item_id' => 1, 'warehouse_id' => 1, 'stock' => 20], ['id' => 2, 'item_id' => 1, 'warehouse_id' => 2, 'stock' => 100]]);
        $this->db->table('item_sets')->insert(['id' => 1, 'item_id' => 2, 'individual_item_id' => 1, 'quantity' => 4]);
        $this->db->table('item_lots_group')->insert(['id' => 1, 'quantity' => 7]);
        $this->db->table('item_lots')->insert(['id' => 1, 'has_sale' => 1]);
        $this->app->instance(\Illuminate\Contracts\Auth\Factory::class, new class {
            public function user() { return (object) ['establishment' => (object) ['id' => 1, 'description' => 'Fixture']]; }
        });
        (new AnulationServiceProvider($this->app))->boot();
        (new InventoryVoidedServiceProvider($this->app))->boot();
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

    private function metadata(bool $set = false): string
    {
        return json_encode(['is_set' => $set, 'presentation' => ['quantity_unit' => 3], 'IdLoteSelected' => 1, 'lots_group' => [['id' => 1, 'checked' => true]], 'lots' => [['id' => 1, 'has_sale' => true]]]);
    }

    private function document(int $id = 1, string $type = '01', array $values = []): Document
    {
        $this->db->table('documents')->insert(array_merge(['id' => $id, 'document_type_id' => $type, 'state_type_id' => '05', 'establishment_id' => 1], $values));
        $this->db->table('document_items')->insert(['id' => $id, 'document_id' => $id, 'item_id' => 1, 'quantity' => 2, 'item' => $this->metadata()]);
        return Document::findOrFail($id);
    }

    private function state(Model $record, string $state): void
    {
        $record->state_type_id = $state;
        $record->save();
    }

    private function effects(): array
    {
        return [
            'global' => (float) $this->db->table('items')->where('id', 1)->value('stock'),
            'warehouse' => (float) $this->db->table('item_warehouse')->where('id', 1)->value('stock'),
            'other_warehouse' => (float) $this->db->table('item_warehouse')->where('id', 2)->value('stock'),
            'legacy_rows' => $this->db->table('kardex')->count(),
            'warehouse_rows' => $this->db->table('inventory_kardex')->count(),
            'lot_quantity' => (float) $this->db->table('item_lots_group')->value('quantity'),
            'lot_sold' => (int) $this->db->table('item_lots')->value('has_sale'),
        ];
    }

    public function test_document_first_rejection_restores_warehouse_lots_and_prepayment_only_once(): void
    {
        $this->db->table('documents')->insert(['id' => 99, 'document_type_id' => '01', 'state_type_id' => '05', 'series' => 'F001', 'number' => '99', 'was_deducted_prepayment' => 1, 'pending_amount_prepayment' => 4]);
        $doc = $this->document(1, '01', ['prepayments' => '[{"number":"F001-99","total":10}]']);
        $this->state($doc, '09');
        $expected = ['global' => 10.0, 'warehouse' => 26.0, 'other_warehouse' => 100.0, 'legacy_rows' => 0, 'warehouse_rows' => 1, 'lot_quantity' => 9.0, 'lot_sold' => 0];
        $this->assertSame($expected, $this->effects());
        $this->assertSame(14.0, (float) $this->db->table('documents')->where('id', 99)->value('pending_amount_prepayment'));
        $doc->notes = 'Fixture update';
        $doc->save();
        $this->assertSame($expected, $this->effects());
        $this->state($doc, '11');
        $expected['global'] = 12.0;
        $expected['legacy_rows'] = 1;
        $this->assertSame($expected, $this->effects());
        $this->assertSame(14.0, (float) $this->db->table('documents')->where('id', 99)->value('pending_amount_prepayment'));
        $this->assertSame(-2.0, (float) $this->db->table('kardex')->value('quantity'));
    }

    public function test_direct_void_restores_distinct_global_and_warehouse_ledgers_once_for_invoices_and_receipts(): void
    {
        foreach (['01', '03'] as $index => $type) {
            $doc = $this->document($index + 1, $type);
            $before = $this->effects();
            $this->state($doc, '11');
            $after = $this->effects();
            $this->assertSame($before['global'] + 2, $after['global']);
            $this->assertSame($before['warehouse'] + 6, $after['warehouse']);
            $this->assertSame($before['legacy_rows'] + 1, $after['legacy_rows']);
            $this->assertSame($before['warehouse_rows'] + 1, $after['warehouse_rows']);
            $doc->notes = 'Non-state change';
            $doc->save();
            $this->assertSame($after, $this->effects());
            $this->state($doc, '11');
            $this->assertSame($after, $this->effects());
        }
    }

    public function test_credit_debit_notes_and_non_terminal_state_changes_remain_excluded(): void
    {
        $before = $this->effects();
        foreach (['07', '08'] as $index => $type) {
            $this->state($this->document($index + 1, $type), '11');
            $this->assertSame($before, $this->effects());
        }
        $doc = $this->document(3);
        $this->state($doc, '03');
        $this->assertSame($before, $this->effects());
    }

    public function test_documents_linked_to_sale_or_order_keep_warehouse_exclusion_and_ledger_behavior(): void
    {
        foreach (['sale_note_id', 'order_note_id'] as $index => $link) {
            $doc = $this->document($index + 1, '01', [$link => 99]);
            $before = $this->effects();
            $this->state($doc, '11');
            $after = $this->effects();
            $this->assertSame($before['global'] + 2, $after['global']);
            $this->assertSame($before['warehouse'], $after['warehouse']);
            $this->assertSame($before['warehouse_rows'] + 1, $after['warehouse_rows']);
        }
    }

    private function order(string $state = '05'): OrderNote
    {
        $this->db->table('order_notes')->insert(['id' => 1, 'state_type_id' => $state, 'establishment_id' => 1]);
        $this->db->table('order_note_items')->insert(['id' => 1, 'order_note_id' => 1, 'item_id' => 1, 'quantity' => 2, 'item' => $this->metadata()]);
        return OrderNote::findOrFail(1);
    }

    public function test_order_note_rejection_then_void_and_non_state_updates_do_not_restore_twice(): void
    {
        $order = $this->order();
        $this->state($order, '09');
        $after = $this->effects();
        $this->assertSame(26.0, $after['warehouse']);
        $this->assertSame(10.0, $after['global']);
        $this->assertSame(1, $after['warehouse_rows']);
        $order->notes = 'Non-state change';
        $order->save();
        $this->assertSame($after, $this->effects());
        $this->state($order, '11');
        $this->assertSame($after, $this->effects());
    }

    private function assertLockedInsideTransaction(string $table): void
    {
        $locks = array_values(array_filter($this->grammar->locks, fn ($lock) => $lock['table'] === $table));
        $this->assertNotEmpty($locks);
        foreach ($locks as $lock) {
            $this->assertTrue($lock['lock']);
            $this->assertSame(1, $lock['transaction']);
        }
        $this->assertSame(0, $this->db->transactionLevel());
    }

    public function test_order_void_endpoint_preserves_response_and_locks_repeated_transitions(): void
    {
        $this->order();
        $controller = new OrderNoteController();
        $first = $controller->voided(1);
        $after = $this->effects();
        $this->assertSame(['success' => true, 'message' => 'Pedido anulado con éxito'], $first);
        $this->assertSame(26.0, $after['warehouse']);
        $this->assertSame($first, $controller->voided(1));
        $this->assertSame($after, $this->effects());
        $this->assertLockedInsideTransaction('order_notes');
    }

    private function sale(bool $set = false, ?int $orderId = null): void
    {
        $this->db->table('sale_notes')->insert(['id' => 1, 'state_type_id' => '05', 'establishment_id' => 1, 'order_note_id' => $orderId]);
        $this->db->table('sale_note_items')->insert(['id' => 1, 'sale_note_id' => 1, 'item_id' => $set ? 2 : 1, 'quantity' => 2, 'warehouse_id' => 2, 'item' => $this->metadata($set)]);
    }

    public function test_sale_note_repeated_annulment_preserves_manual_warehouse_lot_and_response_semantics(): void
    {
        $this->sale();
        $controller = new SaleNoteController();
        $first = $controller->anulate(1);
        $after = $this->effects();
        $this->assertSame(['success' => true, 'message' => 'N. Venta anulada con éxito'], $first);
        $this->assertSame(10.0, $after['global']);
        $this->assertSame(20.0, $after['warehouse']);
        $this->assertSame(106.0, $after['other_warehouse']);
        $this->assertSame(9.0, $after['lot_quantity']);
        $this->assertSame(0, $after['lot_sold']);
        $this->assertSame($first, $controller->anulate(1));
        $this->assertSame($after, $this->effects());
        $this->assertLockedInsideTransaction('sale_notes');
    }

    public function test_linked_sale_note_sets_keep_existing_order_stock_exclusion(): void
    {
        $this->sale(true, 99);
        $controller = new SaleNoteController();
        $controller->anulate(1);
        $after = $this->effects();
        $this->assertSame(20.0, $after['warehouse']);
        $this->assertSame(100.0, $after['other_warehouse']);
        $this->assertSame(1, $after['warehouse_rows']);
        $this->assertSame(8.0, (float) $this->db->table('inventory_kardex')->value('quantity'));
        $controller->anulate(1);
        $this->assertSame($after, $this->effects());
    }
}
