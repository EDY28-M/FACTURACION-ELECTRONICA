<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class TenantPerformanceIndexesAndViews extends Migration
{
    public function up()
    {
        $conn = DB::connection('tenant');
        $dbName = $conn->getDatabaseName();

        $ensureIndex = function (string $table, string $indexName, string $columnsSql) use ($conn, $dbName) {
            if (!Schema::connection('tenant')->hasTable($table)) {
                return;
            }
            $exists = $conn->selectOne(
                'SELECT 1 FROM information_schema.statistics WHERE table_schema = ? AND table_name = ? AND index_name = ? LIMIT 1',
                [$dbName, $table, $indexName]
            );
            if (!$exists) {
                $conn->statement("ALTER TABLE `{$table}` ADD INDEX `{$indexName}` ({$columnsSql})");
            }
        };

        // Composite indexes for high-volume dashboards, lists, POS, and header badges
        $ensureIndex('documents', 'idx_documents_est_date_state_type', '`establishment_id`, `date_of_issue`, `state_type_id`, `document_type_id`');
        $ensureIndex('documents', 'idx_documents_soap_state_date', '`soap_type_id`, `state_type_id`, `date_of_issue`');
        $ensureIndex('documents', 'idx_documents_state_reg', '`state_type_id`, `regularize_shipping`');

        $ensureIndex('sale_notes', 'idx_sale_notes_est_changed_state_date', '`establishment_id`, `changed`, `state_type_id`, `date_of_issue`');
        $ensureIndex('sale_notes', 'idx_sale_notes_soap_state_date', '`soap_type_id`, `state_type_id`, `date_of_issue`');
        $ensureIndex('sale_notes', 'idx_sale_notes_date_of_issue', '`date_of_issue`');

        $ensureIndex('purchases', 'idx_purchases_est_state_date', '`establishment_id`, `state_type_id`, `date_of_issue`');
        $ensureIndex('expenses', 'idx_expenses_est_state_date', '`establishment_id`, `state_type_id`, `date_of_issue`');

        $ensureIndex('items', 'idx_items_pos_active', '`active`, `series_enabled`, `unit_type_id`, `description`(100)');
        $ensureIndex('items', 'idx_items_barcode', '`barcode`(64)');

        $ensureIndex('item_warehouse', 'idx_item_warehouse_wh_stock', '`warehouse_id`, `stock`, `item_id`');
        $ensureIndex('item_warehouse', 'idx_item_warehouse_item_wh', '`item_id`, `warehouse_id`');

        $ensureIndex('persons', 'idx_persons_type_enabled_name', '`type`, `enabled`, `name`(100)');
        $ensureIndex('global_payments', 'idx_global_payments_dest_type', '`destination_type`(120), `destination_id`, `payment_type`(120)');
        $ensureIndex('orders', 'idx_orders_status_order_id', '`status_order_id`');
        $ensureIndex('quotations', 'idx_quotations_est_state_date', '`establishment_id`, `state_type_id`, `date_of_issue`');

        // Payment sum helper views (one row per parent record -> zero double-counting)
        $conn->statement('
            CREATE OR REPLACE VIEW v_document_payments_sum AS
            SELECT document_id, COALESCE(SUM(payment), 0) AS total_paid
            FROM document_payments
            GROUP BY document_id
        ');

        $conn->statement('
            CREATE OR REPLACE VIEW v_sale_note_payments_sum AS
            SELECT sale_note_id, COALESCE(SUM(payment), 0) AS total_paid
            FROM sale_note_payments
            GROUP BY sale_note_id
        ');

        $conn->statement('
            CREATE OR REPLACE VIEW v_purchase_payments_sum AS
            SELECT purchase_id, COALESCE(SUM(payment), 0) AS total_paid
            FROM purchase_payments
            GROUP BY purchase_id
        ');

        $conn->statement('
            CREATE OR REPLACE VIEW v_expense_payments_sum AS
            SELECT expense_id, COALESCE(SUM(payment), 0) AS total_paid
            FROM expense_payments
            GROUP BY expense_id
        ');

        // Daily dashboard summary views (PEN-normalized directly in the DB engine)
        $conn->statement("
            CREATE OR REPLACE VIEW v_dashboard_documents_daily AS
            SELECT
                d.establishment_id,
                d.soap_type_id,
                d.date_of_issue,
                COUNT(*) AS documents_count,
                COALESCE(SUM(CASE
                    WHEN d.document_type_id IN ('01','03','08') THEN
                        CASE WHEN d.currency_type_id = 'PEN' THEN d.total ELSE d.total * d.exchange_rate_sale END
                    ELSE 0
                END), 0) AS gross_sales_pen,
                COALESCE(SUM(CASE
                    WHEN d.document_type_id = '07' THEN
                        CASE WHEN d.currency_type_id = 'PEN' THEN d.total ELSE d.total * d.exchange_rate_sale END
                    ELSE 0
                END), 0) AS credit_notes_pen,
                COALESCE(SUM(COALESCE(dp.total_paid, 0) * CASE
                    WHEN d.currency_type_id = 'PEN' THEN 1 ELSE d.exchange_rate_sale
                END), 0) AS total_payment_pen
            FROM documents d
            LEFT JOIN v_document_payments_sum dp ON dp.document_id = d.id
            WHERE d.state_type_id IN ('01','03','05','07','13')
            GROUP BY d.establishment_id, d.soap_type_id, d.date_of_issue
        ");

        $conn->statement("
            CREATE OR REPLACE VIEW v_dashboard_sale_notes_daily AS
            SELECT
                s.establishment_id,
                s.soap_type_id,
                s.date_of_issue,
                COUNT(*) AS sale_notes_count,
                COALESCE(SUM(CASE
                    WHEN s.currency_type_id = 'PEN' THEN s.total ELSE s.total * s.exchange_rate_sale
                END), 0) AS total_pen,
                COALESCE(SUM(COALESCE(sp.total_paid, 0) * CASE
                    WHEN s.currency_type_id = 'PEN' THEN 1 ELSE s.exchange_rate_sale
                END), 0) AS total_payment_pen
            FROM sale_notes s
            LEFT JOIN v_sale_note_payments_sum sp ON sp.sale_note_id = s.id
            WHERE s.changed = 0
              AND s.state_type_id IN ('01','03','05','07','13')
            GROUP BY s.establishment_id, s.soap_type_id, s.date_of_issue
        ");

        $conn->statement("
            CREATE OR REPLACE VIEW v_dashboard_purchases_daily AS
            SELECT
                p.establishment_id,
                p.soap_type_id,
                p.date_of_issue,
                COUNT(*) AS purchases_count,
                COALESCE(SUM(CASE
                    WHEN p.currency_type_id = 'PEN' THEN (p.total + COALESCE(p.total_perception, 0))
                    ELSE (p.total * p.exchange_rate_sale + COALESCE(p.total_perception, 0))
                END), 0) AS total_pen,
                COALESCE(SUM(COALESCE(pp.total_paid, 0) * CASE
                    WHEN p.currency_type_id = 'PEN' THEN 1 ELSE p.exchange_rate_sale
                END), 0) AS total_payment_pen
            FROM purchases p
            LEFT JOIN v_purchase_payments_sum pp ON pp.purchase_id = p.id
            WHERE p.deleted_at IS NULL
              AND p.state_type_id IN ('01','03','05','07','13')
            GROUP BY p.establishment_id, p.soap_type_id, p.date_of_issue
        ");

        $conn->statement("
            CREATE OR REPLACE VIEW v_dashboard_expenses_daily AS
            SELECT
                e.establishment_id,
                e.soap_type_id,
                e.date_of_issue,
                COUNT(*) AS expenses_count,
                COALESCE(SUM(CASE
                    WHEN e.currency_type_id = 'PEN' THEN e.total ELSE e.total * e.exchange_rate_sale
                END), 0) AS total_pen,
                COALESCE(SUM(COALESCE(ep.total_paid, 0)), 0) AS total_payment_pen
            FROM expenses e
            LEFT JOIN v_expense_payments_sum ep ON ep.expense_id = e.id
            WHERE e.state_type_id = '05'
            GROUP BY e.establishment_id, e.soap_type_id, e.date_of_issue
        ");

        // Low-stock products view (eliminates 47-query N+1 on dashboard boot)
        $conn->statement("
            CREATE OR REPLACE VIEW v_low_stock_products AS
            SELECT
                iw.id,
                iw.item_id,
                iw.warehouse_id,
                w.establishment_id,
                i.description AS product,
                iw.stock,
                w.description AS warehouse
            FROM item_warehouse iw
            INNER JOIN items i ON i.id = iw.item_id
            INNER JOIN warehouses w ON w.id = iw.warehouse_id
            WHERE i.is_set = 0
              AND i.status = 1
              AND i.unit_type_id != 'ZZ'
              AND iw.stock <= 20
        ");

        // Active POS customers lightweight view (avoids hydrating ubigeo/districts)
        $conn->statement("
            CREATE OR REPLACE VIEW v_pos_customers_active AS
            SELECT
                p.id,
                p.number,
                p.name,
                p.identity_document_type_id,
                idt.description AS identity_document_type_description
            FROM persons p
            LEFT JOIN cat_identity_document_types idt ON idt.id = p.identity_document_type_id
            WHERE p.type = 'customers'
              AND p.enabled = 1
        ");
    }

    public function down()
    {
        $conn = DB::connection('tenant');
        $dbName = $conn->getDatabaseName();

        $views = [
            'v_pos_customers_active',
            'v_low_stock_products',
            'v_dashboard_expenses_daily',
            'v_dashboard_purchases_daily',
            'v_dashboard_sale_notes_daily',
            'v_dashboard_documents_daily',
            'v_expense_payments_sum',
            'v_purchase_payments_sum',
            'v_sale_note_payments_sum',
            'v_document_payments_sum',
        ];

        foreach ($views as $view) {
            $conn->statement("DROP VIEW IF EXISTS `{$view}`");
        }

        $indexes = [
            ['documents', 'idx_documents_est_date_state_type'],
            ['documents', 'idx_documents_soap_state_date'],
            ['documents', 'idx_documents_state_reg'],
            ['sale_notes', 'idx_sale_notes_est_changed_state_date'],
            ['sale_notes', 'idx_sale_notes_soap_state_date'],
            ['sale_notes', 'idx_sale_notes_date_of_issue'],
            ['purchases', 'idx_purchases_est_state_date'],
            ['expenses', 'idx_expenses_est_state_date'],
            ['items', 'idx_items_pos_active'],
            ['items', 'idx_items_barcode'],
            ['item_warehouse', 'idx_item_warehouse_wh_stock'],
            ['item_warehouse', 'idx_item_warehouse_item_wh'],
            ['persons', 'idx_persons_type_enabled_name'],
            ['global_payments', 'idx_global_payments_dest_type'],
            ['orders', 'idx_orders_status_order_id'],
            ['quotations', 'idx_quotations_est_state_date'],
        ];

        foreach ($indexes as [$table, $indexName]) {
            if (!Schema::connection('tenant')->hasTable($table)) {
                continue;
            }
            $exists = $conn->selectOne(
                'SELECT 1 FROM information_schema.statistics WHERE table_schema = ? AND table_name = ? AND index_name = ? LIMIT 1',
                [$dbName, $table, $indexName]
            );
            if ($exists) {
                $conn->statement("ALTER TABLE `{$table}` DROP INDEX `{$indexName}`");
            }
        }
    }
}
