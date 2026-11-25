<?php

namespace Modules\Dashboard\Traits;

use Illuminate\Support\Facades\DB;

/**
 * High-performance SQL-view backed dashboard total aggregations.
 *
 * Uses tenant SQL views created in 2026_11_25_000001_tenant_performance_indexes_and_views:
 *  - v_dashboard_documents_daily
 *  - v_dashboard_sale_notes_daily
 *  - v_dashboard_purchases_daily
 *  - v_dashboard_expenses_daily
 *
 * Computes PEN-normalized totals and payments directly in the database engine
 * (O(log N) with composite indexes) without hydrating Eloquent models or $with relations.
 */
trait TotalsTrait
{
    public function get_purchase_totals($establishment_id, $date_start, $date_end)
    {
        $query = DB::connection('tenant')
            ->table('v_dashboard_purchases_daily')
            ->where('establishment_id', $establishment_id);

        if ($date_start && $date_end) {
            $query->whereBetween('date_of_issue', [$date_start, $date_end]);
        }

        $row = $query->selectRaw('COALESCE(SUM(total_pen), 0) AS total, COALESCE(SUM(total_payment_pen), 0) AS total_payment')->first();

        return [
            'totals' => [
                'total_payment' => round((float) ($row->total_payment ?? 0), 2),
                'total' => round((float) ($row->total ?? 0), 2),
            ],
        ];
    }

    public function get_expense_totals($establishment_id, $date_start, $date_end)
    {
        $query = DB::connection('tenant')
            ->table('v_dashboard_expenses_daily')
            ->where('establishment_id', $establishment_id);

        if ($date_start && $date_end) {
            $query->whereBetween('date_of_issue', [$date_start, $date_end]);
        }

        $row = $query->selectRaw('COALESCE(SUM(total_pen), 0) AS total, COALESCE(SUM(total_payment_pen), 0) AS total_payment')->first();

        return [
            'totals' => [
                'total_payment' => round((float) ($row->total_payment ?? 0), 2),
                'total' => round((float) ($row->total ?? 0), 2),
            ],
        ];
    }

    public function get_sale_note_totals($establishment_id, $date_start, $date_end)
    {
        $query = DB::connection('tenant')
            ->table('v_dashboard_sale_notes_daily')
            ->where('establishment_id', $establishment_id);

        if ($date_start && $date_end) {
            $query->whereBetween('date_of_issue', [$date_start, $date_end]);
        }

        $row = $query->selectRaw('COALESCE(SUM(total_pen), 0) AS total, COALESCE(SUM(total_payment_pen), 0) AS total_payment')->first();

        return [
            'totals' => [
                'total_payment' => round((float) ($row->total_payment ?? 0), 2),
                'total' => round((float) ($row->total ?? 0), 2),
            ],
        ];
    }

    public function get_document_totals($establishment_id, $date_start, $date_end)
    {
        $query = DB::connection('tenant')
            ->table('v_dashboard_documents_daily')
            ->where('establishment_id', $establishment_id);

        if ($date_start && $date_end) {
            $query->whereBetween('date_of_issue', [$date_start, $date_end]);
        }

        $row = $query->selectRaw(
            'COALESCE(SUM(gross_sales_pen), 0) AS gross_sales, COALESCE(SUM(credit_notes_pen), 0) AS credit_notes, COALESCE(SUM(total_payment_pen), 0) AS total_payment'
        )->first();

        $document_total = round((float) ($row->gross_sales ?? 0) - (float) ($row->credit_notes ?? 0), 2);

        return [
            'totals' => [
                'total_payment' => round((float) ($row->total_payment ?? 0), 2),
                'total' => $document_total,
            ],
        ];
    }
}
