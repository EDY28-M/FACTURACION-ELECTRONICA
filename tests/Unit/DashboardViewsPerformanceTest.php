<?php

namespace Tests\Unit;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use Modules\Dashboard\Helpers\DashboardData;
use Modules\Dashboard\Helpers\DashboardStock;
use PHPUnit\Framework\TestCase;

/**
 * Verifies exact mathematical parity and bounded SQL query counts for the
 * SQL-view backed dashboard aggregations (Documents, Sale Notes, Purchases,
 * Expenses, Low Stock) across Demo (01), Producción (02), and Interno (03).
 */
class DashboardViewsPerformanceTest extends TestCase
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
        $this->app->instance('config', new \Illuminate\Config\Repository([
            'tenant' => ['items_per_page_simple_d_table' => 5],
        ]));
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

        $this->createSchemaAndViews();
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

    private function createSchemaAndViews(): void
    {
        $this->db->statement('create table configurations (id integer primary key, quantity_documents integer)');
        $this->db->statement('create table items (id integer primary key, internal_id text, description text, is_set integer, status integer, unit_type_id text)');
        $this->db->statement('create table warehouses (id integer primary key, establishment_id integer, description text)');
        $this->db->statement('create table item_warehouse (id integer primary key, item_id integer, warehouse_id integer, stock real)');
        $this->db->statement('create table documents (id integer primary key, establishment_id integer, soap_type_id text, state_type_id text, document_type_id text, date_of_issue text, time_of_issue text, currency_type_id text, exchange_rate_sale real, total real)');
        $this->db->statement('create table document_payments (id integer primary key, document_id integer, payment real)');
        $this->db->statement('create table sale_notes (id integer primary key, establishment_id integer, soap_type_id text, state_type_id text, changed integer, date_of_issue text, time_of_issue text, currency_type_id text, exchange_rate_sale real, total real)');
        $this->db->statement('create table sale_note_payments (id integer primary key, sale_note_id integer, payment real)');
        $this->db->statement('create table purchases (id integer primary key, establishment_id integer, soap_type_id text, state_type_id text, date_of_issue text, currency_type_id text, exchange_rate_sale real, total real, total_perception real, deleted_at text)');
        $this->db->statement('create table purchase_payments (id integer primary key, purchase_id integer, payment real)');
        $this->db->statement('create table expenses (id integer primary key, establishment_id integer, soap_type_id text, state_type_id text, date_of_issue text, currency_type_id text, exchange_rate_sale real, total real)');
        $this->db->statement('create table expense_payments (id integer primary key, expense_id integer, payment real)');

        $this->db->statement('create view v_document_payments_sum as select document_id, coalesce(sum(payment), 0) as total_paid from document_payments group by document_id');
        $this->db->statement('create view v_sale_note_payments_sum as select sale_note_id, coalesce(sum(payment), 0) as total_paid from sale_note_payments group by sale_note_id');
        $this->db->statement('create view v_purchase_payments_sum as select purchase_id, coalesce(sum(payment), 0) as total_paid from purchase_payments group by purchase_id');
        $this->db->statement('create view v_expense_payments_sum as select expense_id, coalesce(sum(payment), 0) as total_paid from expense_payments group by expense_id');

        $this->db->statement("
            create view v_dashboard_documents_daily as
            select d.establishment_id, d.soap_type_id, d.date_of_issue, count(*) as documents_count,
                coalesce(sum(case when d.document_type_id in ('01','03','08') then case when d.currency_type_id = 'PEN' then d.total else d.total * d.exchange_rate_sale end else 0 end), 0) as gross_sales_pen,
                coalesce(sum(case when d.document_type_id = '07' then case when d.currency_type_id = 'PEN' then d.total else d.total * d.exchange_rate_sale end else 0 end), 0) as credit_notes_pen,
                coalesce(sum(coalesce(dp.total_paid, 0) * case when d.currency_type_id = 'PEN' then 1 else d.exchange_rate_sale end), 0) as total_payment_pen
            from documents d left join v_document_payments_sum dp on dp.document_id = d.id
            where d.state_type_id in ('01','03','05','07','13')
            group by d.establishment_id, d.soap_type_id, d.date_of_issue
        ");

        $this->db->statement("
            create view v_dashboard_sale_notes_daily as
            select s.establishment_id, s.soap_type_id, s.date_of_issue, count(*) as sale_notes_count,
                coalesce(sum(case when s.currency_type_id = 'PEN' then s.total else s.total * s.exchange_rate_sale end), 0) as total_pen,
                coalesce(sum(coalesce(sp.total_paid, 0) * case when s.currency_type_id = 'PEN' then 1 else s.exchange_rate_sale end), 0) as total_payment_pen
            from sale_notes s left join v_sale_note_payments_sum sp on sp.sale_note_id = s.id
            where s.changed = 0 and s.state_type_id in ('01','03','05','07','13')
            group by s.establishment_id, s.soap_type_id, s.date_of_issue
        ");

        $this->db->statement("
            create view v_dashboard_purchases_daily as
            select p.establishment_id, p.soap_type_id, p.date_of_issue, count(*) as purchases_count,
                coalesce(sum(case when p.currency_type_id = 'PEN' then (p.total + coalesce(p.total_perception, 0)) else (p.total * p.exchange_rate_sale + coalesce(p.total_perception, 0)) end), 0) as total_pen,
                coalesce(sum(coalesce(pp.total_paid, 0) * case when p.currency_type_id = 'PEN' then 1 else p.exchange_rate_sale end), 0) as total_payment_pen
            from purchases p left join v_purchase_payments_sum pp on pp.purchase_id = p.id
            where p.deleted_at is null and p.state_type_id in ('01','03','05','07','13')
            group by p.establishment_id, p.soap_type_id, p.date_of_issue
        ");

        $this->db->statement("
            create view v_dashboard_expenses_daily as
            select e.establishment_id, e.soap_type_id, e.date_of_issue, count(*) as expenses_count,
                coalesce(sum(case when e.currency_type_id = 'PEN' then e.total else e.total * e.exchange_rate_sale end), 0) as total_pen,
                coalesce(sum(coalesce(ep.total_paid, 0)), 0) as total_payment_pen
            from expenses e left join v_expense_payments_sum ep on ep.expense_id = e.id
            where e.state_type_id = '05'
            group by e.establishment_id, e.soap_type_id, e.date_of_issue
        ");

        $this->db->statement("
            create view v_low_stock_products as
            select iw.id, iw.item_id, iw.warehouse_id, w.establishment_id, i.description as product, iw.stock, w.description as warehouse
            from item_warehouse iw
            inner join items i on i.id = iw.item_id
            inner join warehouses w on w.id = iw.warehouse_id
            where i.is_set = 0 and i.status = 1 and i.unit_type_id != 'ZZ' and iw.stock <= 20
        ");
    }

    public function test_dashboard_views_compute_exact_pen_usd_credit_notes_and_stay_under_10_queries(): void
    {
        $this->db->table('configurations')->insert(['id' => 1, 'quantity_documents' => 42]);

        // Documents: PEN invoice (200), USD invoice (50 * 3.8 = 190), PEN credit note (-40), rejected/voided ignored
        $this->db->table('documents')->insert([
            ['id' => 1, 'establishment_id' => 1, 'soap_type_id' => '02', 'state_type_id' => '05', 'document_type_id' => '01', 'date_of_issue' => '2026-11-24', 'time_of_issue' => '10:15:00', 'currency_type_id' => 'PEN', 'exchange_rate_sale' => 1.0, 'total' => 200.00],
            ['id' => 2, 'establishment_id' => 1, 'soap_type_id' => '01', 'state_type_id' => '05', 'document_type_id' => '03', 'date_of_issue' => '2026-11-25', 'time_of_issue' => '11:00:00', 'currency_type_id' => 'USD', 'exchange_rate_sale' => 3.8, 'total' => 50.00],
            ['id' => 3, 'establishment_id' => 1, 'soap_type_id' => '02', 'state_type_id' => '05', 'document_type_id' => '07', 'date_of_issue' => '2026-11-25', 'time_of_issue' => '12:00:00', 'currency_type_id' => 'PEN', 'exchange_rate_sale' => 1.0, 'total' => 40.00],
            ['id' => 4, 'establishment_id' => 1, 'soap_type_id' => '02', 'state_type_id' => '11', 'document_type_id' => '01', 'date_of_issue' => '2026-11-25', 'time_of_issue' => '13:00:00', 'currency_type_id' => 'PEN', 'exchange_rate_sale' => 1.0, 'total' => 999.00],
        ]);
        // Two payments on doc #1 (120 + 80 = 200) -> must NOT double-count doc #1 total!
        $this->db->table('document_payments')->insert([
            ['id' => 1, 'document_id' => 1, 'payment' => 120.00],
            ['id' => 2, 'document_id' => 1, 'payment' => 80.00],
            ['id' => 3, 'document_id' => 2, 'payment' => 25.00], // 25 USD * 3.8 = 95 PEN
        ]);

        // Sale notes (Interno 03): PEN 150 + changed=1 (ignored) + voided (ignored)
        $this->db->table('sale_notes')->insert([
            ['id' => 1, 'establishment_id' => 1, 'soap_type_id' => '03', 'state_type_id' => '01', 'changed' => 0, 'date_of_issue' => '2026-11-25', 'time_of_issue' => '14:00:00', 'currency_type_id' => 'PEN', 'exchange_rate_sale' => 1.0, 'total' => 150.00],
            ['id' => 2, 'establishment_id' => 1, 'soap_type_id' => '03', 'state_type_id' => '01', 'changed' => 1, 'date_of_issue' => '2026-11-25', 'time_of_issue' => '14:30:00', 'currency_type_id' => 'PEN', 'exchange_rate_sale' => 1.0, 'total' => 500.00],
            ['id' => 3, 'establishment_id' => 1, 'soap_type_id' => '03', 'state_type_id' => '11', 'changed' => 0, 'date_of_issue' => '2026-11-25', 'time_of_issue' => '15:00:00', 'currency_type_id' => 'PEN', 'exchange_rate_sale' => 1.0, 'total' => 800.00],
        ]);
        $this->db->table('sale_note_payments')->insert([
            ['id' => 1, 'sale_note_id' => 1, 'payment' => 100.00],
        ]);

        // Purchases & Expenses (deleted purchase ignored)
        $this->db->table('purchases')->insert([
            ['id' => 1, 'establishment_id' => 1, 'soap_type_id' => '03', 'state_type_id' => '01', 'date_of_issue' => '2026-11-25', 'currency_type_id' => 'PEN', 'exchange_rate_sale' => 1.0, 'total' => 80.00, 'total_perception' => 5.00, 'deleted_at' => null],
            ['id' => 2, 'establishment_id' => 1, 'soap_type_id' => '03', 'state_type_id' => '01', 'date_of_issue' => '2026-11-25', 'currency_type_id' => 'PEN', 'exchange_rate_sale' => 1.0, 'total' => 700.00, 'total_perception' => 0.00, 'deleted_at' => '2026-11-25 10:00:00'],
        ]);
        $this->db->table('purchase_payments')->insert([['id' => 1, 'purchase_id' => 1, 'payment' => 50.00]]);

        $this->db->table('expenses')->insert([
            ['id' => 1, 'establishment_id' => 1, 'soap_type_id' => '03', 'state_type_id' => '05', 'date_of_issue' => '2026-11-25', 'currency_type_id' => 'PEN', 'exchange_rate_sale' => 1.0, 'total' => 25.00],
        ]);
        $this->db->table('expense_payments')->insert([['id' => 1, 'expense_id' => 1, 'payment' => 25.00]]);

        $this->db->table('items')->insert([
            ['id' => 1, 'internal_id' => 'P01', 'description' => 'Laptop', 'is_set' => 0, 'status' => 1, 'unit_type_id' => 'NIU'],
            ['id' => 2, 'internal_id' => 'S01', 'description' => 'Servicio', 'is_set' => 0, 'status' => 1, 'unit_type_id' => 'ZZ'],
        ]);
        $this->db->table('warehouses')->insert(['id' => 1, 'establishment_id' => 1, 'description' => 'Almacén Principal']);
        $this->db->table('item_warehouse')->insert([
            ['id' => 1, 'item_id' => 1, 'warehouse_id' => 1, 'stock' => 4],
            ['id' => 2, 'item_id' => 2, 'warehouse_id' => 1, 'stock' => 0], // service ZZ excluded
        ]);

        // 1. Global data: docs = 200 + 190 - 40 = 350.00; sale notes = 150.00
        $this->db->flushQueryLog();
        $this->db->enableQueryLog();
        $global = (new DashboardData())->globalData();
        $this->assertSame(42, $global['total_cpe']);
        $this->assertSame('350.00', $global['document_total_global']);
        $this->assertSame('150.00', $global['sale_note_total_global']);
        $this->assertLessThanOrEqual(3, count($this->db->getQueryLog()));

        // 2. Period data (between_dates: 2026-11-24 to 2026-11-25)
        $this->db->flushQueryLog();
        $data = (new DashboardData())->data([
            'establishment_id' => 1,
            'period' => 'between_dates',
            'date_start' => '2026-11-24',
            'date_end' => '2026-11-25',
            'month_start' => '2026-11',
            'month_end' => '2026-11',
        ]);
        $queriesUsed = count($this->db->getQueryLog());

        $this->assertSame('350.00', $data['document']['totals']['total']);
        $this->assertSame('295.00', $data['document']['totals']['total_payment']);
        $this->assertSame('55.00', $data['document']['totals']['total_to_pay']);
        $this->assertSame('150.00', $data['sale_note']['totals']['total']);
        $this->assertSame('100.00', $data['sale_note']['totals']['total_payment']);
        $this->assertSame('500.00', $data['general']['totals']['total']);
        // Balance: 350 + 150 - 25 - 85 = 390.00
        $this->assertSame('390.00', $data['balance']['totals']['all_totals']);
        $this->assertLessThanOrEqual(8, $queriesUsed, "DashboardData::data used {$queriesUsed} queries (expected <= 8)");

        // 3. Low-stock view query count (2 queries: count + paginated slice)
        $this->db->flushQueryLog();
        $stockRes = (new DashboardStock())->data(new Request(['establishment_id' => 1]))->toArray(new Request());
        $this->assertCount(1, $stockRes);
        $this->assertSame('Laptop', $stockRes[0]['product']);
        $this->assertSame('4.00', $stockRes[0]['stock']);
        $this->assertLessThanOrEqual(2, count($this->db->getQueryLog()));
    }
}
