<?php

namespace Modules\Dashboard\Helpers;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Purchases summary, best-selling items and top customers for the dashboard.
 *
 * Everything is aggregated in SQL (GROUP BY) so memory does not grow with the number of documents or items.
 */
class DashboardSalePurchase
{
    const STATES = ['01', '03', '05', '07', '13'];
    const SALE_DOCUMENT_TYPES = ['01', '03', '08'];
    const TOP = 10;
    public function data($request)
    {
        $establishment_id = $request['establishment_id'];
        $period = $request['period'];
        $date_start = $request['date_start'];
        $date_end = $request['date_end'];
        $month_start = $request['month_start'];
        $month_end = $request['month_end'];
        $enabled_move_item = $request['enabled_move_item'];
        $enabled_transaction_customer = $request['enabled_transaction_customer'];

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
            'purchase' => $this->purchase_totals($establishment_id, $d_start, $d_end),
            'items_by_sales' => $this->items_by_sales($establishment_id, $d_start, $d_end, $enabled_move_item),
            'top_customers' => $this->top_customers($establishment_id, $d_start, $d_end, $enabled_transaction_customer),
        ];
    }

    private function db()
    {
        return DB::connection('tenant');
    }

    /** SQL expression: amount converted to PEN with the document's sale exchange rate. */
    private function penSql($total, $currency, $rate)
    {
        return "(CASE WHEN {$currency} = 'USD' THEN {$total} * {$rate} ELSE {$total} END)";
    }

    private function saleTypesSql($column)
    {
        return "{$column} IN ('" . implode("','", self::SALE_DOCUMENT_TYPES) . "')";
    }

    /** Documents (any type) of the establishment in the range, issued states only. */
    private function documentsQuery($establishment_id, $d_start, $d_end)
    {
        $query = $this->db()->table('documents as d')
            ->where('d.establishment_id', $establishment_id)
            ->whereIn('d.state_type_id', self::STATES);

        if ($d_start && $d_end) {
            $query->whereBetween('d.date_of_issue', [$d_start, $d_end]);
        }

        return $query;
    }

    /** Sale notes that were not converted into documents, in the range, issued states only. */
    private function saleNotesQuery($establishment_id, $d_start, $d_end)
    {
        $query = $this->db()->table('sale_notes as sn')
            ->where('sn.establishment_id', $establishment_id)
            ->where('sn.changed', false)
            ->whereIn('sn.state_type_id', self::STATES);

        if ($d_start && $d_end) {
            $query->whereBetween('sn.date_of_issue', [$d_start, $d_end]);
        }

        return $query;
    }

    /**
     * Sorts rows descending by $column keeping the original (first appearance) order on ties,
     * the way Collection::sortByDesc did, and returns them re-indexed.
     */
    private function sortDesc(array $rows, $column)
    {
        $values = [];
        foreach ($rows as $index => $row) {
            $values[$index] = $row[$column];
        }
        arsort($values);

        $sorted = [];
        foreach (array_keys($values) as $index) {
            $sorted[] = $rows[$index];
        }

        return $sorted;
    }

    private function top_customers($establishment_id, $d_start, $d_end, $enabled_transaction_customer){

        $docTotal = $this->penSql('d.total', 'd.currency_type_id', 'd.exchange_rate_sale');
        $isSale = $this->saleTypesSql('d.document_type_id');

        $documents = $this->documentsQuery($establishment_id, $d_start, $d_end)
            ->selectRaw("d.customer_id, MIN(d.id) AS first_id,
                SUM(CASE WHEN {$isSale} THEN 1 ELSE 0 END) AS qty_sale,
                SUM(CASE WHEN d.document_type_id = '07' THEN 1 ELSE 0 END) AS qty_credit,
                SUM(CASE WHEN {$isSale} THEN {$docTotal} ELSE 0 END) AS total_sale,
                SUM(CASE WHEN d.document_type_id = '07' THEN d.total ELSE 0 END) AS total_credit")
            ->groupBy('d.customer_id')
            ->orderBy('first_id')
            ->get();

        $snTotal = $this->penSql('sn.total', 'sn.currency_type_id', 'sn.exchange_rate_sale');

        $saleNotes = $this->saleNotesQuery($establishment_id, $d_start, $d_end)
            ->selectRaw("sn.customer_id, MIN(sn.id) AS first_id,
                SUM(CASE WHEN sn.prefix = 'NV' THEN 1 ELSE 0 END) AS qty_note,
                SUM(CASE WHEN sn.prefix = 'NV' THEN {$snTotal} ELSE 0 END) AS total_note")
            ->groupBy('sn.customer_id')
            ->orderBy('first_id')
            ->get();

        // Merge per customer. Documents come first, then sale notes, as in the former single collection.
        $customers = [];
        foreach ($documents as $row) {
            $customers[$row->customer_id] = [
                'customer_id' => $row->customer_id,
                'quantity' => (int) $row->qty_sale - (int) $row->qty_credit,
                'difference' => (float) $row->total_sale - (float) $row->total_credit,
            ];
        }
        foreach ($saleNotes as $row) {
            if (!isset($customers[$row->customer_id])) {
                $customers[$row->customer_id] = ['customer_id' => $row->customer_id, 'quantity' => 0, 'difference' => 0.0];
            }
            $customers[$row->customer_id]['quantity'] += (int) $row->qty_note;
            $customers[$row->customer_id]['difference'] += (float) $row->total_note;
        }

        $rows = [];
        foreach ($customers as $customer) {
            if ($customer['difference'] > 0) {
                $customer['total'] = number_format($customer['difference'], 2, ".", "");
                $customer['transaction_quantity'] = $customer['quantity'];
                $rows[] = $customer;
            }
        }

        $order_column = ($enabled_transaction_customer) ? 'transaction_quantity' : 'total';
        $sorted = $this->sortDesc($rows, $order_column);

        $top = [];
        foreach (array_chunk($sorted, self::TOP) as $batch) {
            $people = $this->db()->table('persons')
                ->where('type', 'customers')
                ->whereIn('id', array_column($batch, 'customer_id'))
                ->get(['id', 'name', 'number'])
                ->keyBy('id');

            foreach ($batch as $customer) {
                $person = $people->get($customer['customer_id']);
                if (!$person) {
                    continue;
                }
                $top[] = [
                    'total' => $customer['total'],
                    'name' => $person->name,
                    'number' => $person->number,
                    'transaction_quantity' => $customer['transaction_quantity'],
                ];
                if (count($top) >= self::TOP) {
                    break 2;
                }
            }
        }

        return collect($top);

    }



    private function purchase_totals($establishment_id, $d_start, $d_end)
    {
        // Purchases are not limited by the selected range: they feed a yearly (by month of the year) chart.
        $base = $this->db()->table('purchases')
            ->whereIn('state_type_id', self::STATES)
            ->where('establishment_id', $establishment_id);

        $summary = (clone $base)->selectRaw("
            COALESCE(SUM(CASE WHEN currency_type_id = 'PEN' THEN total WHEN currency_type_id = 'USD' THEN total * exchange_rate_sale ELSE 0 END), 0) AS purchases_total,
            COALESCE(SUM(total_perception), 0) AS perception_total")->first();

        $purchases_total = (float) $summary->purchases_total;
        $purchases_total_perception = round((float) $summary->perception_total, 2);

        $data_array = ['Ene', 'Feb','Mar', 'Abr','May', 'Jun','Jul', 'Ago','Sep', 'Oct', 'Nov', 'Dic'];

        $by_month = (clone $base)
            ->selectRaw('MONTH(date_of_issue) AS m, SUM(total) AS total, COALESCE(SUM(total_perception), 0) AS perception')
            ->groupByRaw('MONTH(date_of_issue)')
            ->get()
            ->keyBy('m');

        return [
            'totals' => [
                'purchases_total_perception' => number_format($purchases_total_perception,2),
                'purchases_total' => number_format( round($purchases_total, 2),2),
                'total' => number_format($purchases_total + $purchases_total_perception,2),
            ],
            'graph' => [
                'labels' => $data_array,
                'datasets' => [
                    [
                        'label' => 'Total percepciones',
                        'data' => $this->arrayPurchasesbyMonth($by_month, 'perception'),
                        'backgroundColor' => 'rgb(255, 99, 132)',
                        'borderColor' => 'rgb(255, 99, 132)',
                        'borderWidth' => 1,
                        'fill' => false,
                        'lineTension' => 0,
                    ],
                    [
                        'label' => 'Total compras',
                        'data' => $this->arrayPurchasesbyMonth($by_month, 'total'),
                        'backgroundColor' => 'rgb(54, 162, 235)',
                        'borderColor' => 'rgb(54, 162, 235)',
                        'borderWidth' => 1,
                        'fill' => false,
                        'lineTension' => 0,
                    ],
                    [
                        'label' => 'Total',
                        'data' => $data_array,
                        'backgroundColor' => 'rgb(201, 203, 207)',
                        'borderColor' => 'rgb(201, 203, 207)',
                        'borderWidth' => 1,
                        'fill' => false,
                        'lineTension' => 0,
                    ]

                ],
            ]
        ];
    }



    private function items_by_sales($establishment_id, $d_start, $d_end, $enabled_move_item){

        // Every item line carries the amount of its whole document (not of the line), as before.
        $docTotal = $this->penSql('d.total', 'd.currency_type_id', 'd.exchange_rate_sale');
        $isSale = $this->saleTypesSql('d.document_type_id');

        $documents = $this->documentsQuery($establishment_id, $d_start, $d_end)
            ->join('document_items as di', 'di.document_id', '=', 'd.id')
            ->selectRaw("di.item_id,
                MIN(CONCAT(LPAD(d.id, 12, '0'), LPAD(di.id, 12, '0'))) AS first_seen,
                SUM(CASE WHEN {$isSale} THEN {$docTotal} ELSE 0 END) AS total_sale,
                SUM(CASE WHEN {$isSale} THEN 0 ELSE {$docTotal} END) AS total_credit,
                SUM(CASE WHEN {$isSale} THEN di.quantity ELSE -di.quantity END) AS move_quantity")
            ->groupBy('di.item_id')
            ->orderBy('first_seen')
            ->get();

        $snTotal = $this->penSql('sn.total', 'sn.currency_type_id', 'sn.exchange_rate_sale');

        $saleNotes = $this->saleNotesQuery($establishment_id, $d_start, $d_end)
            ->join('sale_note_items as sni', 'sni.sale_note_id', '=', 'sn.id')
            ->selectRaw("sni.item_id,
                MIN(CONCAT(LPAD(sn.id, 12, '0'), LPAD(sni.id, 12, '0'))) AS first_seen,
                SUM({$snTotal}) AS total_sale,
                SUM(sni.quantity) AS move_quantity")
            ->groupBy('sni.item_id')
            ->orderBy('first_seen')
            ->get();

        // Merge per item. Document lines come first, then sale note lines, as in the former single collection.
        $items = [];
        foreach ($documents as $row) {
            $items[$row->item_id] = [
                'item_id' => $row->item_id,
                'sale' => (float) $row->total_sale,
                'credit' => (float) $row->total_credit,
                'move' => (float) $row->move_quantity,
            ];
        }
        foreach ($saleNotes as $row) {
            if (!isset($items[$row->item_id])) {
                $items[$row->item_id] = ['item_id' => $row->item_id, 'sale' => 0.0, 'credit' => 0.0, 'move' => 0.0];
            }
            $items[$row->item_id]['sale'] += (float) $row->total_sale;
            $items[$row->item_id]['move'] += (float) $row->move_quantity;
        }

        $rows = [];
        foreach ($items as $item) {
            $difference = $item['sale'] - $item['credit'];
            if ($difference > 0) {
                $item['total'] = number_format($difference, 2, ".", "");
                $item['move_quantity'] = number_format($item['move'], 2, ".", "");
                $rows[] = $item;
            }
        }

        $order_column = ($enabled_move_item) ? 'move_quantity' : 'total';
        $sorted = $this->sortDesc($rows, $order_column);

        $top = [];
        foreach (array_chunk($sorted, self::TOP) as $batch) {
            $catalog = $this->db()->table('items')
                ->where('status', true)
                ->whereIn('id', array_column($batch, 'item_id'))
                ->get(['id', 'description', 'internal_id'])
                ->keyBy('id');

            foreach ($batch as $item) {
                $record = $catalog->get($item['item_id']);
                if (!$record) {
                    continue;
                }
                $top[] = [
                    'total' => $item['total'],
                    'description' => $record->description,
                    'internal_id' => $record->internal_id,
                    'move_quantity' => $item['move_quantity'],
                ];
                if (count($top) >= self::TOP) {
                    break 2;
                }
            }
        }

        return collect($top);

    }

    /**
     * @param \Illuminate\Support\Collection $by_month rows keyed by month number (1-12)
     * @param string $column
     * @return array
     */
    private function arrayPurchasesbyMonth($by_month, $column){

        $result = [];
        for ($month = 1; $month <= 12; $month++) {
            $result[] = $by_month->has($month) ? round((float) $by_month->get($month)->{$column}, 2) : 0;
        }

        return $result;

    }

}
