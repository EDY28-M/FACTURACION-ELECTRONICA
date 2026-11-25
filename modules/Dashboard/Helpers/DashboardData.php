<?php

namespace Modules\Dashboard\Helpers;

use App\Models\Tenant\Configuration;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Dashboard\Traits\TotalsTrait;

class DashboardData
{
    use TotalsTrait;

    public function data($request)
    {
        $establishment_id = $request['establishment_id'];
        $period = $request['period'] ?? null;
        $date_start = $request['date_start'] ?? null;
        $date_end = $request['date_end'] ?? null;
        $month_start = $request['month_start'] ?? null;
        $month_end = $request['month_end'] ?? null;

        [$d_start, $d_end] = $this->resolvePeriodDates($period, $date_start, $date_end, $month_start, $month_end);

        // Compute once per period and reuse across cards, graph, and balance summary
        $docSummary = $this->get_document_totals($establishment_id, $d_start, $d_end)['totals'];
        $snSummary = $this->get_sale_note_totals($establishment_id, $d_start, $d_end)['totals'];

        return [
            'document' => $this->formatDocumentTotals($docSummary),
            'sale_note' => $this->formatSaleNoteTotals($snSummary),
            'general' => $this->totals($establishment_id, $d_start, $d_end, $period, $month_start, $month_end, $docSummary, $snSummary),
            'balance' => $this->balance($establishment_id, $d_start, $d_end, $docSummary, $snSummary),
            'items' => $this->getItems(),
        ];
    }

    public function globalData()
    {
        return [
            'total_cpe' => optional(Configuration::select('quantity_documents')->first())->quantity_documents,
            'document_total_global' => $this->document_totals_globals(),
            'sale_note_total_global' => $this->sale_note_totals_global(),
        ];
    }

    private function resolvePeriodDates($period, $date_start, $date_end, $month_start, $month_end): array
    {
        switch ($period) {
            case 'month':
                return [
                    Carbon::parse($month_start.'-01')->format('Y-m-d'),
                    Carbon::parse($month_start.'-01')->endOfMonth()->format('Y-m-d'),
                ];
            case 'between_months':
                return [
                    Carbon::parse($month_start.'-01')->format('Y-m-d'),
                    Carbon::parse($month_end.'-01')->endOfMonth()->format('Y-m-d'),
                ];
            case 'date':
                return [$date_start, $date_start];
            case 'between_dates':
            case 'last_week':
                return [$date_start, $date_end];
            default:
                return [null, null];
        }
    }

    private function formatSaleNoteTotals(array $summary): array
    {
        $sale_note_total = (float) $summary['total'];
        $sale_note_total_payment = (float) $summary['total_payment'];
        $sale_note_total_to_pay = $sale_note_total - $sale_note_total_payment;

        return [
            'totals' => [
                'total_payment' => number_format($sale_note_total_payment, 2, '.', ''),
                'total_to_pay' => number_format($sale_note_total_to_pay, 2, '.', ''),
                'total' => number_format($sale_note_total, 2, '.', ''),
            ],
            'graph' => [
                'labels' => ['Total pagado', 'Total por pagar'],
                'datasets' => [
                    [
                        'label' => 'Notas de venta',
                        'data' => [round($sale_note_total_payment, 2), round($sale_note_total_to_pay, 2)],
                        'backgroundColor' => [
                            'rgb(54, 162, 235)',
                            'rgb(255, 99, 132)',
                        ],
                    ],
                ],
            ],
        ];
    }

    private function formatDocumentTotals(array $summary): array
    {
        $document_total = (float) $summary['total'];
        $document_total_payment = (float) $summary['total_payment'];
        $document_total_to_pay = $document_total - $document_total_payment;

        return [
            'totals' => [
                'total_payment' => number_format($document_total_payment, 2, '.', ''),
                'total_to_pay' => number_format($document_total_to_pay, 2, '.', ''),
                'total' => number_format($document_total, 2, '.', ''),
            ],
            'graph' => [
                'labels' => ['Total pagado', 'Total por pagar'],
                'datasets' => [
                    [
                        'label' => 'Comprobantes',
                        'data' => [round($document_total_payment, 2), round($document_total_to_pay, 2)],
                        'backgroundColor' => [
                            'rgb(54, 162, 235)',
                            'rgb(255, 99, 132)',
                        ],
                    ],
                ],
            ],
        ];
    }

    private function sale_note_totals_global()
    {
        $total = (float) (DB::connection('tenant')
            ->table('v_dashboard_sale_notes_daily')
            ->selectRaw('COALESCE(SUM(total_pen), 0) AS total')
            ->value('total') ?? 0);

        return number_format($total, 2, '.', '');
    }

    private function document_totals_globals()
    {
        $row = DB::connection('tenant')
            ->table('v_dashboard_documents_daily')
            ->selectRaw('COALESCE(SUM(gross_sales_pen), 0) AS gross_sales, COALESCE(SUM(credit_notes_pen), 0) AS credit_notes')
            ->first();

        $document_total = round((float) ($row->gross_sales ?? 0) - (float) ($row->credit_notes ?? 0), 2);

        return number_format($document_total, 2, '.', '');
    }

    private function totals($establishment_id, $date_start, $date_end, $period, $month_start, $month_end, ?array $docSummary = null, ?array $snSummary = null)
    {
        $docSummary = $docSummary ?? $this->get_document_totals($establishment_id, $date_start, $date_end)['totals'];
        $snSummary = $snSummary ?? $this->get_sale_note_totals($establishment_id, $date_start, $date_end)['totals'];

        $documents_total = (float) $docSummary['total'];
        $sale_notes_total = (float) $snSummary['total'];
        $total = $sale_notes_total + $documents_total;

        if ($period == 'month') {
            $data_array = $this->getTotalsByDays($establishment_id, $date_start, $date_end);
        } elseif ($period == 'between_months' && $month_start === $month_end) {
            $data_array = $this->getTotalsByDays($establishment_id, $date_start, $date_end);
        } elseif ($period == 'between_months') {
            $data_array = $this->getTotalsByMonths($establishment_id, $date_start, $date_end, $month_start, $month_end);
        } else {
            if ($date_start && $date_start === $date_end) {
                $data_array = $this->getTotalsByHours($establishment_id, $date_start);
            } else {
                $data_array = $this->getTotalsByDays($establishment_id, $date_start, $date_end);
            }
        }

        return [
            'totals' => [
                'total_documents' => number_format($documents_total, 2, '.', ''),
                'total_sale_notes' => number_format($sale_notes_total, 2, '.', ''),
                'total' => number_format($total, 2, '.', ''),
            ],
            'graph' => [
                'labels' => array_keys($data_array['total_array']),
                'datasets' => [
                    [
                        'label' => 'Total notas de venta',
                        'data' => array_values($data_array['sale_notes_array']),
                        'backgroundColor' => 'rgb(255, 99, 132)',
                        'borderColor' => 'rgb(255, 99, 132)',
                        'borderWidth' => 1,
                        'fill' => false,
                        'lineTension' => 0,
                    ],
                    [
                        'label' => 'Total comprobantes',
                        'data' => array_values($data_array['documents_array']),
                        'backgroundColor' => 'rgb(54, 162, 235)',
                        'borderColor' => 'rgb(54, 162, 235)',
                        'borderWidth' => 1,
                        'fill' => false,
                        'lineTension' => 0,
                    ],
                    [
                        'label' => 'Total',
                        'data' => array_values($data_array['total_array']),
                        'backgroundColor' => 'rgb(201, 203, 207)',
                        'borderColor' => 'rgb(201, 203, 207)',
                        'borderWidth' => 1,
                        'fill' => false,
                        'lineTension' => 0,
                    ],
                ],
            ],
        ];
    }

    private function getTotalsByHours($establishment_id, $date): array
    {
        $conn = DB::connection('tenant');

        $snRows = $conn->table('sale_notes')
            ->where('establishment_id', $establishment_id)
            ->where('changed', false)
            ->whereIn('state_type_id', ['01', '03', '05', '07', '13'])
            ->where('date_of_issue', $date)
            ->selectRaw("SUBSTR(time_of_issue, 1, 2) AS hour_bucket, COALESCE(SUM(CASE WHEN currency_type_id = 'PEN' THEN total ELSE total * exchange_rate_sale END), 0) AS total_pen")
            ->groupBy('hour_bucket')
            ->pluck('total_pen', 'hour_bucket');

        $docRows = $conn->table('documents')
            ->where('establishment_id', $establishment_id)
            ->whereIn('state_type_id', ['01', '03', '05', '07', '13'])
            ->where('date_of_issue', $date)
            ->selectRaw("
                SUBSTR(time_of_issue, 1, 2) AS hour_bucket,
                COALESCE(SUM(CASE
                    WHEN document_type_id IN ('01','03','08') THEN CASE WHEN currency_type_id = 'PEN' THEN total ELSE total * exchange_rate_sale END
                    WHEN document_type_id = '07' THEN -1 * (CASE WHEN currency_type_id = 'PEN' THEN total ELSE total * exchange_rate_sale END)
                    ELSE 0
                END), 0) AS net_pen
            ")
            ->groupBy('hour_bucket')
            ->pluck('net_pen', 'hour_bucket');

        $sale_notes_array = [];
        $documents_array = [];
        $total_array = [];

        for ($h = 0; $h <= 23; $h++) {
            $h_format = str_pad($h, 2, '0', STR_PAD_LEFT);
            $sn = round((float) ($snRows[$h_format] ?? 0), 2);
            $doc = round((float) ($docRows[$h_format] ?? 0), 2);

            $sale_notes_array[$h_format.'h'] = $sn;
            $documents_array[$h_format.'h'] = $doc;
            $total_array[$h_format.'h'] = round($sn + $doc, 2);
        }

        return compact('sale_notes_array', 'documents_array', 'total_array');
    }

    private function getTotalsByDays($establishment_id, $date_start, $date_end): array
    {
        $conn = DB::connection('tenant');
        $sale_notes_array = [];
        $documents_array = [];
        $total_array = [];

        if (!$date_start || !$date_end) {
            return compact('sale_notes_array', 'documents_array', 'total_array');
        }

        $snByDate = $conn->table('v_dashboard_sale_notes_daily')
            ->where('establishment_id', $establishment_id)
            ->whereBetween('date_of_issue', [$date_start, $date_end])
            ->selectRaw('date_of_issue, COALESCE(SUM(total_pen), 0) AS total_pen')
            ->groupBy('date_of_issue')
            ->pluck('total_pen', 'date_of_issue');

        $docByDate = $conn->table('v_dashboard_documents_daily')
            ->where('establishment_id', $establishment_id)
            ->whereBetween('date_of_issue', [$date_start, $date_end])
            ->selectRaw('date_of_issue, COALESCE(SUM(gross_sales_pen - credit_notes_pen), 0) AS net_pen')
            ->groupBy('date_of_issue')
            ->pluck('net_pen', 'date_of_issue');

        $d_start = Carbon::parse($date_start);
        $d_end = Carbon::parse($date_end);

        while ($d_start <= $d_end) {
            $keyDate = $d_start->format('Y-m-d');
            $label = $d_start->format('d').'d';

            $sn = round((float) ($snByDate[$keyDate] ?? 0), 2);
            $doc = round((float) ($docByDate[$keyDate] ?? 0), 2);

            $sale_notes_array[$label] = $sn;
            $documents_array[$label] = $doc;
            $total_array[$label] = round($sn + $doc, 2);

            $d_start->addDay();
        }

        return compact('sale_notes_array', 'documents_array', 'total_array');
    }

    private function getTotalsByMonths($establishment_id, $date_start, $date_end, $month_start, $month_end): array
    {
        $conn = DB::connection('tenant');

        $snByMonth = $conn->table('v_dashboard_sale_notes_daily')
            ->where('establishment_id', $establishment_id)
            ->whereBetween('date_of_issue', [$date_start, $date_end])
            ->selectRaw('SUBSTR(date_of_issue, 6, 2) AS month_bucket, COALESCE(SUM(total_pen), 0) AS total_pen')
            ->groupBy('month_bucket')
            ->pluck('total_pen', 'month_bucket');

        $docByMonth = $conn->table('v_dashboard_documents_daily')
            ->where('establishment_id', $establishment_id)
            ->whereBetween('date_of_issue', [$date_start, $date_end])
            ->selectRaw('SUBSTR(date_of_issue, 6, 2) AS month_bucket, COALESCE(SUM(gross_sales_pen - credit_notes_pen), 0) AS net_pen')
            ->groupBy('month_bucket')
            ->pluck('net_pen', 'month_bucket');

        $sale_notes_array = [];
        $documents_array = [];
        $total_array = [];

        $m_start = (int) substr($month_start, 5, 2);
        $m_end = (int) substr($month_end, 5, 2);

        for ($m = $m_start; $m <= $m_end; $m++) {
            $m_format = str_pad($m, 2, '0', STR_PAD_LEFT);
            $sn = round((float) ($snByMonth[$m_format] ?? 0), 2);
            $doc = round((float) ($docByMonth[$m_format] ?? 0), 2);

            $sale_notes_array[$m_format.'m'] = $sn;
            $documents_array[$m_format.'m'] = $doc;
            $total_array[$m_format.'m'] = round($sn + $doc, 2);
        }

        return compact('sale_notes_array', 'documents_array', 'total_array');
    }

    private function balance($establishment_id, $date_start, $date_end, ?array $docSummary = null, ?array $snSummary = null)
    {
        $response_totals_document = $docSummary ?? $this->get_document_totals($establishment_id, $date_start, $date_end)['totals'];
        $response_totals_sale_note = $snSummary ?? $this->get_sale_note_totals($establishment_id, $date_start, $date_end)['totals'];
        $response_totals_purchase = $this->get_purchase_totals($establishment_id, $date_start, $date_end)['totals'];
        $response_totals_expense = $this->get_expense_totals($establishment_id, $date_start, $date_end)['totals'];

        $total_document = (float) $response_totals_document['total'];
        $total_payment_document = (float) $response_totals_document['total_payment'];

        $total_sale_note = (float) $response_totals_sale_note['total'];
        $total_payment_sale_note = (float) $response_totals_sale_note['total_payment'];

        $total_purchase = (float) $response_totals_purchase['total'];
        $total_payment_purchase = (float) $response_totals_purchase['total_payment'];

        $total_expense = (float) $response_totals_expense['total'];
        $total_payment_expense = (float) $response_totals_expense['total_payment'];

        $all_totals = $total_document + $total_sale_note - $total_expense - $total_purchase;
        $all_totals_payment = $total_payment_document + $total_payment_sale_note - $total_payment_purchase - $total_payment_expense;

        return [
            'totals' => [
                'total_document' => number_format($total_document, 2),
                'total_payment_document' => number_format($total_payment_document, 2),
                'total_sale_note' => number_format($total_sale_note, 2),
                'total_payment_sale_note' => number_format($total_payment_sale_note, 2),
                'total_purchase' => number_format($total_purchase, 2),
                'total_payment_purchase' => number_format($total_payment_purchase, 2),
                'total_expense' => number_format($total_expense, 2),
                'total_payment_expense' => number_format($total_payment_expense, 2),
                'all_totals' => number_format($all_totals, 2),
                'all_totals_payment' => number_format($all_totals_payment, 2),
            ],
            'graph' => [
                'labels' => ['Totales', 'Total pagos'],
                'datasets' => [
                    [
                        'label' => 'Grafico',
                        'data' => [round($all_totals, 2), round($all_totals_payment, 2)],
                        'backgroundColor' => [
                            'rgb(54, 162, 235)',
                            'rgb(255, 99, 132)',
                        ],
                    ],
                ],
            ],
        ];
    }

    public function getItems()
    {
        return DB::connection('tenant')
            ->table('items')
            ->select('id', 'internal_id', 'description')
            ->orderBy('description')
            ->limit(20)
            ->get()
            ->map(function ($row) {
                return [
                    'id' => $row->id,
                    'description' => ($row->internal_id) ? "{$row->internal_id} - {$row->description}" : $row->description,
                ];
            });
    }

    public function data_mobile($request)
    {
        $establishment_id = $request['establishment_id'];
        $period = $request['period'] ?? null;
        $date_start = $request['date_start'] ?? null;
        $date_end = $request['date_end'] ?? null;
        $month_start = $request['month_start'] ?? null;
        $month_end = $request['month_end'] ?? null;

        [$d_start, $d_end] = $this->resolvePeriodDates($period, $date_start, $date_end, $month_start, $month_end);

        return [
            'general' => $this->totals($establishment_id, $d_start, $d_end, $period, $month_start, $month_end),
        ];
    }
}
