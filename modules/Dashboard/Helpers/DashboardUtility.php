<?php

namespace Modules\Dashboard\Helpers;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Income / egress / utility totals for the dashboard.
 *
 * Every figure is computed with aggregate SQL (SUM ... GROUP BY) instead of hydrating documents and
 * items as models, so memory stays flat regardless of the selected range.
 */
class DashboardUtility
{
    const STATES = ['01', '03', '05', '07', '13'];
    const SALE_DOCUMENT_TYPES = ['01', '03', '08'];

    // use TotalsTrait;

    public function data($request)
    {

        $establishment_id = $request['establishment_id'] ?? null;
        $period = $request['period'] ?? null;
        $date_start = $request['date_start'] ?? null;
        $date_end = $request['date_end'] ?? null;
        $month_start = $request['month_start'] ?? null;
        $month_end = $request['month_end'] ?? null;
        $enabled_expense = $request['enabled_expense'] ?? null;
        $item_id = $request['item_id'] ?? null;

        $d_start = null;
        $d_end = null;

        switch ($period) {
            case 'month':
                $d_start = Carbon::parse($month_start.'-01')->format('Y-m-d');
                $d_end = Carbon::parse($month_start.'-01')->endOfMonth()->format('Y-m-d');
                break;
            case 'between_months':
                $d_start = Carbon::parse($month_start.'-01')->format('Y-m-d');
                $d_end = Carbon::parse($month_end.'-01')->endOfMonth()->format('Y-m-d');
                break;
            case 'date':
                $d_start = $date_start;
                $d_end = $date_start;
                break;
            case 'between_dates':
                $d_start = $date_start;
                $d_end = $date_end;
                break;
            case 'last_week':
                $d_start = $date_start;
                $d_end = $date_end;
                break;
        }

        return [
            'utilities' => $this->utilities_totals($establishment_id, $d_start, $d_end, $enabled_expense, $item_id),
        ];

    }



    private function utilities_totals($establishment_id, $d_start, $d_end, $enabled_expense, $item_id){

        $db = DB::connection('tenant');
        $hasRange = $d_start && $d_end;

        $documents = $this->documentTotals($db, $establishment_id, $hasRange, $d_start, $d_end, $item_id);
        $saleNotes = $this->saleNoteTotals($db, $establishment_id, $hasRange, $d_start, $d_end, $item_id);
        $expenses = $enabled_expense ? $this->expenseTotal($db, $establishment_id, $hasRange, $d_start, $d_end) : 0;

        $total_income = $documents['sale'] + $saleNotes['sale'];
        $total_purchase = $documents['purchase'] + $saleNotes['purchase'];
        $total_egress = $total_purchase + $expenses;
        $utility = $total_income - $total_egress;

        return [
            'totals' => [
                'total_income' => number_format($total_income,2, ".", ""),
                'total_egress' => number_format($total_egress,2, ".", ""),
                'utility' => number_format($utility,2, ".", ""),
                // Components of total_egress, so the dashboard can show purchases and expenses apart.
                'total_purchase' => number_format($total_purchase,2, ".", ""),
                'total_expense' => number_format($expenses,2, ".", ""),
            ],
            'graph' => [
                'labels' => ['Ingreso', 'Egreso'],
                'datasets' => [
                    [
                        'label' => 'Utilidades',
                        'data' => [round($total_income,2), round($total_egress,2)],
                        'backgroundColor' => [
                            'rgb(54, 162, 235)',
                            'rgb(255, 99, 132)',
                        ]
                    ]
                ],
            ]
        ];

    }

    /**
     * Cost of one item row: 0 for services (unit type ZZ); otherwise the item's purchase price when set,
     * else the unit price of its latest purchase line, else the price it was sold at.
     * $row is the table alias of the line (document_items / sale_note_items), joined with `it` (items) and `lp` (latest purchase line).
     */
    private function costSql($row)
    {
        return "(CASE WHEN COALESCE(JSON_UNQUOTE(JSON_EXTRACT({$row}.item, '$.unit_type_id')), '') <> 'ZZ' THEN
                    (CASE WHEN COALESCE(it.purchase_unit_price, 0) > 0 THEN it.purchase_unit_price
                          WHEN lp.id IS NOT NULL THEN lp.unit_price
                          ELSE {$row}.unit_price END)
                 ELSE 0 END) * {$row}.quantity";
    }

    /** Latest purchase line (highest id) of every item, as a derived table with columns id, item_id, unit_price. */
    private function latestPurchaseLineSql()
    {
        return DB::raw('(SELECT pi.id, pi.item_id, pi.unit_price FROM purchase_items pi
                         INNER JOIN (SELECT MAX(id) AS max_id FROM purchase_items GROUP BY item_id) m ON m.max_id = pi.id) lp');
    }

    private function documentTotals($db, $establishment_id, $hasRange, $d_start, $d_end, $item_id)
    {
        $scope = function ($query) use ($establishment_id, $hasRange, $d_start, $d_end) {
            $query->where('d.establishment_id', $establishment_id)
                ->whereIn('d.state_type_id', self::STATES);
            if ($hasRange) {
                // Only a bounded range is restricted to sales documents; the open (all time) range also
                // includes the other types (credit notes), which subtract.
                $query->whereIn('d.document_type_id', self::SALE_DOCUMENT_TYPES)
                    ->whereBetween('d.date_of_issue', [$d_start, $d_end]);
            }
        };
        $sign = "(CASE WHEN d.document_type_id IN ('" . implode("','", self::SALE_DOCUMENT_TYPES) . "') THEN 1 ELSE -1 END)";

        // Sales: the whole document total, once per document that has at least one matching item line.
        // The USD branch multiplies by a rate that the former implementation never loaded (null), so USD documents add 0.
        $sale = $db->table('documents as d');
        $scope($sale);
        $sale->whereExists(function ($query) use ($item_id) {
            $query->select(DB::raw(1))->from('document_items as di')->whereColumn('di.document_id', 'd.id');
            if ($item_id) {
                $query->where('di.item_id', $item_id);
            }
        });
        $saleTotal = (float) $sale->where('d.currency_type_id', 'PEN')->selectRaw("COALESCE(SUM({$sign} * d.total), 0) AS total")->value('total');

        // Cost: per item line.
        $cost = $db->table('document_items as di')
            ->join('documents as d', 'd.id', '=', 'di.document_id')
            ->leftJoin('items as it', 'it.id', '=', 'di.item_id')
            ->leftJoin($this->latestPurchaseLineSql(), 'lp.item_id', '=', 'di.item_id');
        $scope($cost);
        if ($item_id) {
            $cost->where('di.item_id', $item_id);
        }
        $purchaseTotal = (float) $cost->selectRaw('COALESCE(SUM(' . $sign . ' * ' . $this->costSql('di') . '), 0) AS cost')->value('cost');

        return ['sale' => $saleTotal, 'purchase' => $purchaseTotal];
    }

    private function saleNoteTotals($db, $establishment_id, $hasRange, $d_start, $d_end, $item_id)
    {
        $query = $db->table('sale_note_items as sni')
            ->join('sale_notes as sn', 'sn.id', '=', 'sni.sale_note_id')
            ->leftJoin('items as it', 'it.id', '=', 'sni.item_id')
            ->leftJoin($this->latestPurchaseLineSql(), 'lp.item_id', '=', 'sni.item_id')
            ->where('sn.establishment_id', $establishment_id)
            ->where('sn.changed', false)
            ->whereIn('sn.state_type_id', self::STATES);

        if ($hasRange) {
            $query->whereBetween('sn.date_of_issue', [$d_start, $d_end]);
        }
        if ($item_id) {
            $query->where('sni.item_id', $item_id);
        }

        $row = $query->selectRaw(
            "COALESCE(SUM(CASE WHEN sn.currency_type_id = 'PEN' THEN sni.total ELSE sni.total * sn.exchange_rate_sale END), 0) AS sale,
             COALESCE(SUM(" . $this->costSql('sni') . "), 0) AS cost"
        )->first();

        return ['sale' => (float) $row->sale, 'purchase' => (float) $row->cost];
    }

    private function expenseTotal($db, $establishment_id, $hasRange, $d_start, $d_end)
    {
        $query = $db->table('expenses')->where('establishment_id', $establishment_id);

        if ($hasRange) {
            $query->whereBetween('date_of_issue', [$d_start, $d_end]);
        }

        $total = $query->selectRaw("COALESCE(SUM(CASE WHEN currency_type_id = 'USD' THEN total * exchange_rate_sale ELSE total END), 0) AS total")->value('total');

        // Same rounding the former implementation applied before adding it to the egress.
        return (float) number_format((float) $total, 2, ".", "");
    }

}
