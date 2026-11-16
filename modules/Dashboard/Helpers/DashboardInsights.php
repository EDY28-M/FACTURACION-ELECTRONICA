<?php

namespace Modules\Dashboard\Helpers;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Aggregated figures for the tenant dashboard (comparison series, type split, weekday profile,
 * today summary and receivables schedule).
 *
 * Everything is computed with GROUP BY queries: a 12-month range returns a few hundred rows
 * instead of hydrating every document as a model.
 */
class DashboardInsights
{
    // Same states the rest of the dashboard counts as "issued" (registered, sent, accepted, observed, to void).
    const STATES = ['01', '03', '05', '07', '13'];
    const PENDING_STATES = ['01', '03'];
    const ACCEPTED_STATE = '05';

    const MAX_DAY_BUCKETS = 400;
    const MAX_WEEK_BUCKETS = 80;
    const MAX_MONTH_BUCKETS = 60;
    const MAX_RECEIVABLES = 8;

    private $establishmentId;
    private $db;

    private static $weekdaysShort = ['Dom', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb'];
    private static $weekdaysLong = ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];
    private static $monthsShort = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'set', 'oct', 'nov', 'dic'];
    private static $monthsLong = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'setiembre', 'octubre', 'noviembre', 'diciembre'];

    public function data($request)
    {
        $this->db = DB::connection('tenant');
        $this->establishmentId = $request['establishment_id'] ?? null;

        $period = $request['period'] ?? 'last_week';
        [$start, $end] = $this->resolveRange($request, $period);

        if (!$start || !$end) {
            return $this->emptyPayload();
        }

        [$prevStart, $prevEnd] = $this->previousRange($start, $end, $period);

        $from = $prevStart ? $prevStart : $start;
        $days = $this->loadDays($from, $end);
        $singleDay = $start->equalTo($end);

        $current = $this->sumRange($days, $start, $end);
        $previous = $prevStart ? $this->sumRange($days, $prevStart, $prevEnd) : null;

        $series = [];
        $unitBuckets = [];
        foreach (['day', 'week', 'month'] as $unit) {
            $cur = $this->buckets($start, $end, $unit);
            $unitBuckets[$unit] = count($cur);
            if (!$this->unitAllowed($unit, count($cur), $singleDay)) {
                continue;
            }
            $prev = $prevStart ? $this->buckets($prevStart, $prevEnd, $unit) : [];
            $series[$unit] = $this->buildSeries($days, $cur, $prev, $unit, $start, $end);
        }
        if ($singleDay) {
            $series['hour'] = $this->hourSeries($start, $prevStart);
        }

        $labels = $this->periodLabels($period, $singleDay);

        return [
            'meta' => [
                'date_start' => $start->format('Y-m-d'),
                'date_end' => $end->format('Y-m-d'),
                'previous_start' => $prevStart ? $prevStart->format('Y-m-d') : null,
                'previous_end' => $prevEnd ? $prevEnd->format('Y-m-d') : null,
                'has_previous' => (bool) $prevStart,
                'days' => $start->diffInDays($end) + 1,
                'units' => array_keys($series),
                'default_unit' => $this->defaultUnit($series, $start->diffInDays($end) + 1, $singleDay),
                'labels' => $labels,
            ],
            'today' => $this->today(),
            'totals' => ['current' => $current, 'previous' => $previous],
            'series' => $series,
            'counts' => $this->typeCounts($start, $end),
            'split' => $this->typeSplit($start, $end),
            'weekdays' => $this->weekdays($days, $start, $end, $prevStart, $prevEnd),
            'receivables' => $this->receivables(),
            'purchases_year' => $this->purchasesYear($end->year),
        ];
    }

    // ------------------------------------------------------------------------------------
    // Ranges
    // ------------------------------------------------------------------------------------

    private function resolveRange($request, $period)
    {
        $dateStart = $request['date_start'] ?? null;
        $dateEnd = $request['date_end'] ?? null;
        $monthStart = $request['month_start'] ?? null;
        $monthEnd = $request['month_end'] ?? null;

        try {
            switch ($period) {
                case 'month':
                    $s = Carbon::parse($monthStart . '-01')->startOfDay();
                    return [$s, $s->copy()->endOfMonth()->startOfDay()];
                case 'between_months':
                    return [
                        Carbon::parse($monthStart . '-01')->startOfDay(),
                        Carbon::parse($monthEnd . '-01')->endOfMonth()->startOfDay(),
                    ];
                case 'date':
                    $s = Carbon::parse($dateStart)->startOfDay();
                    return [$s, $s->copy()];
                case 'between_dates':
                case 'last_week':
                    return [Carbon::parse($dateStart)->startOfDay(), Carbon::parse($dateEnd)->startOfDay()];
                case 'all':
                default:
                    return $this->fullRange();
            }
        } catch (\Throwable $e) {
            return [null, null];
        }
    }

    private function fullRange()
    {
        $doc = $this->scope($this->db->table('documents'), 'documents')->whereIn('state_type_id', self::STATES)
            ->selectRaw('MIN(date_of_issue) AS d_min, MAX(date_of_issue) AS d_max')->first();
        $note = $this->scope($this->db->table('sale_notes'), 'sale_notes')->where('changed', false)->whereIn('state_type_id', self::STATES)
            ->selectRaw('MIN(date_of_issue) AS d_min, MAX(date_of_issue) AS d_max')->first();

        $mins = array_filter([$doc->d_min ?? null, $note->d_min ?? null]);
        $maxs = array_filter([$doc->d_max ?? null, $note->d_max ?? null]);
        if (!$mins || !$maxs) {
            return [null, null];
        }

        return [Carbon::parse(min($mins))->startOfDay(), Carbon::parse(max($maxs))->startOfDay()];
    }

    private function previousRange(Carbon $start, Carbon $end, $period)
    {
        if ($period === 'all') {
            return [null, null];
        }

        if (in_array($period, ['month', 'between_months'], true)) {
            $months = ($end->year - $start->year) * 12 + ($end->month - $start->month) + 1;
            $pStart = $start->copy()->subMonthsNoOverflow($months)->startOfMonth();
            $pEnd = $pStart->copy()->addMonthsNoOverflow($months - 1)->endOfMonth()->startOfDay();
            return [$pStart, $pEnd];
        }

        $length = $start->diffInDays($end) + 1;
        return [$start->copy()->subDays($length), $start->copy()->subDay()];
    }

    private function periodLabels($period, $singleDay)
    {
        if ($period === 'last_week') {
            return ['current' => 'Última semana', 'previous' => 'Semana anterior', 'compare' => 'frente a la semana anterior', 'unit_name' => 'semana'];
        }
        if ($period === 'month') {
            return ['current' => 'Este mes', 'previous' => 'Mes anterior', 'compare' => 'frente al mes anterior', 'unit_name' => 'mes'];
        }
        if ($singleDay) {
            return ['current' => 'Este día', 'previous' => 'Día anterior', 'compare' => 'frente al día anterior', 'unit_name' => 'día'];
        }
        return ['current' => 'Periodo actual', 'previous' => 'Periodo anterior', 'compare' => 'frente al periodo anterior', 'unit_name' => 'periodo'];
    }

    // ------------------------------------------------------------------------------------
    // Queries
    // ------------------------------------------------------------------------------------

    private function scope($query, $table)
    {
        if ($this->establishmentId) {
            $query->where($table . '.establishment_id', $this->establishmentId);
        }
        return $query;
    }

    private function amountExpr($alias)
    {
        return "{$alias}.total * IF({$alias}.currency_type_id = 'USD', {$alias}.exchange_rate_sale, 1)";
    }

    /**
     * Documents grouped by day / type. Credit and debit notes are attributed to the type of the document they modify,
     * so the invoice + receipt split adds up to the same figure as the dashboard totals (notes included).
     */
    private function documentRows($from, $to, $hourly = false)
    {
        $hour = $hourly ? ', SUBSTR(d.time_of_issue, 1, 2) AS hr' : '';
        $groupHour = $hourly ? ', hr' : '';

        $query = $this->db->table('documents AS d')
            ->leftJoin('notes AS n', function ($join) {
                $join->on('n.document_id', '=', 'd.id')->whereIn('d.document_type_id', ['07', '08']);
            })
            ->leftJoin('documents AS a', 'a.id', '=', 'n.affected_document_id')
            ->whereIn('d.state_type_id', self::STATES)
            ->whereIn('d.document_type_id', ['01', '03', '07', '08'])
            ->whereBetween('d.date_of_issue', [$from->format('Y-m-d'), $to->format('Y-m-d')])
            ->selectRaw(
                "d.date_of_issue AS day{$hour}, d.document_type_id AS dtype, "
                . "CASE WHEN d.document_type_id IN ('07','08') THEN COALESCE(a.document_type_id, '01') ELSE d.document_type_id END AS bucket, "
                . 'COUNT(*) AS cnt, SUM(' . $this->amountExpr('d') . ') AS amt'
            )
            ->groupBy('day', 'dtype', 'bucket');
        if ($hourly) {
            $query->groupBy('hr');
        }
        $this->scope($query, 'd');

        return $query->get();
    }

    private function noteRows($from, $to, $hourly = false)
    {
        $hour = $hourly ? ', SUBSTR(s.time_of_issue, 1, 2) AS hr' : '';

        $query = $this->db->table('sale_notes AS s')
            ->where('s.changed', false)
            ->whereIn('s.state_type_id', self::STATES)
            ->whereBetween('s.date_of_issue', [$from->format('Y-m-d'), $to->format('Y-m-d')])
            ->selectRaw("s.date_of_issue AS day{$hour}, COUNT(*) AS cnt, SUM(" . $this->amountExpr('s') . ') AS amt')
            ->groupBy('day');
        if ($hourly) {
            $query->groupBy('hr');
        }
        $this->scope($query, 's');

        return $query->get();
    }

    /**
     * Per-day figures: docs (net of credit notes), notes (sale notes), count (electronic documents),
     * plus the per-type buckets used by the donut and the type counters.
     */
    private function loadDays(Carbon $from, Carbon $to)
    {
        $days = [];
        foreach ($this->documentRows($from, $to) as $row) {
            $key = $row->day;
            $days[$key] = $days[$key] ?? $this->blankDay();
            $sign = $row->dtype === '07' ? -1 : 1;
            $days[$key]['docs'] += $sign * (float) $row->amt;
            $days[$key]['count'] += (int) $row->cnt;
            $days[$key]['types'][$row->dtype] = ($days[$key]['types'][$row->dtype] ?? 0) + (int) $row->cnt;
            $days[$key]['buckets'][$row->bucket] = ($days[$key]['buckets'][$row->bucket] ?? 0) + $sign * (float) $row->amt;
        }
        foreach ($this->noteRows($from, $to) as $row) {
            $key = $row->day;
            $days[$key] = $days[$key] ?? $this->blankDay();
            $days[$key]['notes'] += (float) $row->amt;
            $days[$key]['note_count'] += (int) $row->cnt;
        }

        return $days;
    }

    private function blankDay()
    {
        return ['docs' => 0.0, 'notes' => 0.0, 'count' => 0, 'note_count' => 0, 'types' => [], 'buckets' => []];
    }

    // ------------------------------------------------------------------------------------
    // Series
    // ------------------------------------------------------------------------------------

    private function dayRange(Carbon $start, Carbon $end)
    {
        $list = [];
        for ($d = $start->copy(); $d->lte($end); $d->addDay()) {
            $list[] = $d->copy();
        }
        return $list;
    }

    /** Buckets as [from, to] pairs (inclusive). Weeks are 7-day chunks from the start, months are calendar months. */
    private function buckets(Carbon $start, Carbon $end, $unit)
    {
        $list = [];
        if ($unit === 'day') {
            foreach ($this->dayRange($start, $end) as $d) {
                $list[] = [$d, $d->copy()];
            }
        } elseif ($unit === 'week') {
            for ($d = $start->copy(); $d->lte($end); $d->addDays(7)) {
                $to = $d->copy()->addDays(6);
                $list[] = [$d->copy(), $to->gt($end) ? $end->copy() : $to];
            }
        } else {
            for ($d = $start->copy(); $d->lte($end); $d = $d->copy()->startOfMonth()->addMonthNoOverflow()) {
                $to = $d->copy()->endOfMonth()->startOfDay();
                $list[] = [$d->copy(), $to->gt($end) ? $end->copy() : $to];
            }
        }
        return $list;
    }

    private function unitAllowed($unit, $count, $singleDay)
    {
        if ($singleDay || $count < 2) {
            return false;
        }
        $max = ['day' => self::MAX_DAY_BUCKETS, 'week' => self::MAX_WEEK_BUCKETS, 'month' => self::MAX_MONTH_BUCKETS][$unit];
        return $count <= $max;
    }

    private function defaultUnit($series, $days, $singleDay)
    {
        if ($singleDay && isset($series['hour'])) {
            return 'hour';
        }
        $preferred = $days <= 45 ? 'day' : ($days <= 182 ? 'week' : 'month');
        foreach ([$preferred, 'week', 'month', 'day'] as $unit) {
            if (isset($series[$unit])) {
                return $unit;
            }
        }
        return null;
    }

    private function sumRange($days, Carbon $from, Carbon $to)
    {
        $docs = 0.0;
        $notes = 0.0;
        $count = 0;
        foreach ($this->dayRange($from, $to) as $d) {
            $row = $days[$d->format('Y-m-d')] ?? null;
            if ($row) {
                $docs += $row['docs'];
                $notes += $row['notes'];
                $count += $row['count'];
            }
        }
        return ['docs' => round($docs, 2), 'notes' => round($notes, 2), 'total' => round($docs + $notes, 2), 'count' => $count];
    }

    private function bucketLabel(Carbon $from, Carbon $to, $unit, $spansYears)
    {
        if ($unit === 'day') {
            return [self::$weekdaysShort[$from->dayOfWeek] . ' ' . $from->day, self::$weekdaysLong[$from->dayOfWeek] . ' ' . $from->day . ' de ' . self::$monthsLong[$from->month - 1]];
        }
        if ($unit === 'week') {
            $label = $from->day . ' ' . self::$monthsShort[$from->month - 1];
            $full = 'Semana del ' . $from->day . ' ' . self::$monthsShort[$from->month - 1] . ' al ' . $to->day . ' ' . self::$monthsShort[$to->month - 1];
            return [$label, $full];
        }
        $label = self::$monthsShort[$from->month - 1] . ($spansYears ? ' ' . substr((string) $from->year, 2) : '');
        return [$label, ucfirst(self::$monthsLong[$from->month - 1]) . ' ' . $from->year];
    }

    private function buildSeries($days, array $cur, array $prev, $unit, Carbon $start, Carbon $end)
    {
        $spansYears = $start->year !== $end->year;
        $labels = [];
        $full = [];
        $out = ['docs' => [], 'notes' => [], 'total' => [], 'count' => []];
        foreach ($cur as $range) {
            [$label, $long] = $this->bucketLabel($range[0], $range[1], $unit, $spansYears);
            $labels[] = $label;
            $full[] = $long;
            $sum = $this->sumRange($days, $range[0], $range[1]);
            foreach (['docs', 'notes', 'total', 'count'] as $k) {
                $out[$k][] = $sum[$k];
            }
        }

        $previous = null;
        if ($prev) {
            $previous = ['docs' => [], 'notes' => [], 'total' => [], 'count' => [], 'full' => []];
            foreach ($cur as $i => $_) {
                $range = $prev[$i] ?? null;
                if (!$range) {
                    foreach (['docs', 'notes', 'total', 'count'] as $k) {
                        $previous[$k][] = null;
                    }
                    $previous['full'][] = null;
                    continue;
                }
                $sum = $this->sumRange($days, $range[0], $range[1]);
                foreach (['docs', 'notes', 'total', 'count'] as $k) {
                    $previous[$k][] = $sum[$k];
                }
                [, $long] = $this->bucketLabel($range[0], $range[1], $unit, $range[0]->year !== $range[1]->year || $spansYears);
                $previous['full'][] = $long;
            }
        }

        return ['labels' => $labels, 'full' => $full, 'current' => $out, 'previous' => $previous];
    }

    private function hourSeries(Carbon $day, $prevDay)
    {
        $fill = function (Carbon $d) {
            $out = ['docs' => array_fill(0, 24, 0.0), 'notes' => array_fill(0, 24, 0.0), 'total' => array_fill(0, 24, 0.0), 'count' => array_fill(0, 24, 0)];
            foreach ($this->documentRows($d, $d, true) as $row) {
                $h = (int) $row->hr;
                if ($h < 0 || $h > 23) {
                    continue;
                }
                $out['docs'][$h] += ($row->dtype === '07' ? -1 : 1) * (float) $row->amt;
                $out['count'][$h] += (int) $row->cnt;
            }
            foreach ($this->noteRows($d, $d, true) as $row) {
                $h = (int) $row->hr;
                if ($h >= 0 && $h <= 23) {
                    $out['notes'][$h] += (float) $row->amt;
                }
            }
            for ($h = 0; $h < 24; $h++) {
                $out['docs'][$h] = round($out['docs'][$h], 2);
                $out['notes'][$h] = round($out['notes'][$h], 2);
                $out['total'][$h] = round($out['docs'][$h] + $out['notes'][$h], 2);
            }
            return $out;
        };

        $labels = [];
        $full = [];
        for ($h = 0; $h < 24; $h++) {
            $labels[] = str_pad($h, 2, '0', STR_PAD_LEFT) . 'h';
            $full[] = str_pad($h, 2, '0', STR_PAD_LEFT) . ':00 a ' . str_pad($h, 2, '0', STR_PAD_LEFT) . ':59';
        }
        $previous = null;
        if ($prevDay) {
            $previous = $fill($prevDay);
            $previous['full'] = $full;
        }

        return ['labels' => $labels, 'full' => $full, 'current' => $fill($day), 'previous' => $previous];
    }

    // ------------------------------------------------------------------------------------
    // Type counters, donut, weekdays
    // ------------------------------------------------------------------------------------

    private function typeCounts(Carbon $start, Carbon $end)
    {
        $counts = ['invoices' => 0, 'receipts' => 0, 'sale_notes' => 0, 'credit_notes' => 0];
        foreach ($this->documentCountRows($start, $end) as $row) {
            if ($row->document_type_id === '01') {
                $counts['invoices'] = (int) $row->cnt;
            } elseif ($row->document_type_id === '03') {
                $counts['receipts'] = (int) $row->cnt;
            } elseif ($row->document_type_id === '07') {
                $counts['credit_notes'] = (int) $row->cnt;
            }
        }
        $counts['sale_notes'] = (int) $this->noteCount($start, $end);

        return $counts;
    }

    private function documentCountRows(Carbon $start, Carbon $end)
    {
        $query = $this->db->table('documents AS d')
            ->whereIn('d.state_type_id', self::STATES)
            ->whereIn('d.document_type_id', ['01', '03', '07'])
            ->whereBetween('d.date_of_issue', [$start->format('Y-m-d'), $end->format('Y-m-d')])
            ->selectRaw('d.document_type_id, COUNT(*) AS cnt')
            ->groupBy('d.document_type_id');
        return $this->scope($query, 'd')->get();
    }

    private function noteCount(Carbon $start, Carbon $end)
    {
        $query = $this->db->table('sale_notes AS s')
            ->where('s.changed', false)
            ->whereIn('s.state_type_id', self::STATES)
            ->whereBetween('s.date_of_issue', [$start->format('Y-m-d'), $end->format('Y-m-d')]);
        return $this->scope($query, 's')->count();
    }

    private function typeSplit(Carbon $start, Carbon $end)
    {
        $invoices = 0.0;
        $receipts = 0.0;
        foreach ($this->documentRows($start, $end) as $row) {
            $value = ($row->dtype === '07' ? -1 : 1) * (float) $row->amt;
            if ($row->bucket === '03') {
                $receipts += $value;
            } else {
                $invoices += $value;
            }
        }
        $notes = 0.0;
        foreach ($this->noteRows($start, $end) as $row) {
            $notes += (float) $row->amt;
        }

        return ['invoices' => round($invoices, 2), 'receipts' => round($receipts, 2), 'sale_notes' => round($notes, 2)];
    }

    /**
     * Monday-first weekday profile.
     * Periods shorter than four weeks hold one sample per weekday, which draws a single spike;
     * those use the average of the last 8 weeks against the 8 weeks before, and say so in `names`.
     */
    private function weekdays($days, Carbon $start, Carbon $end, $prevStart, $prevEnd)
    {
        $labels = ['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'];
        $profile = function ($source, Carbon $from, Carbon $to, $average) {
            $out = array_fill(0, 7, 0.0);
            $hits = array_fill(0, 7, 0);
            foreach ($this->dayRange($from, $to) as $d) {
                $i = $d->dayOfWeekIso - 1;
                $hits[$i]++;
                $row = $source[$d->format('Y-m-d')] ?? null;
                if ($row) {
                    $out[$i] += $row['docs'] + $row['notes'];
                }
            }
            foreach ($out as $i => $v) {
                $out[$i] = round($average && $hits[$i] > 0 ? $v / $hits[$i] : $v, 2);
            }
            return $out;
        };

        if ($start->diffInDays($end) + 1 >= 28) {
            return [
                'labels' => $labels,
                'mode' => 'total',
                'names' => null,
                'current' => $profile($days, $start, $end, false),
                'previous' => $prevStart ? $profile($days, $prevStart, $prevEnd, false) : null,
            ];
        }

        $windowEnd = $end->copy();
        $windowStart = $end->copy()->subDays(55);
        $priorEnd = $windowStart->copy()->subDay();
        $priorStart = $priorEnd->copy()->subDays(55);
        $window = $this->loadDays($priorStart, $windowEnd);

        return [
            'labels' => $labels,
            'mode' => 'average',
            'names' => ['Promedio últimas 8 semanas', '8 semanas anteriores'],
            'current' => $profile($window, $windowStart, $windowEnd, true),
            'previous' => $profile($window, $priorStart, $priorEnd, true),
        ];
    }

    // ------------------------------------------------------------------------------------
    // Today
    // ------------------------------------------------------------------------------------

    private function today()
    {
        $today = Carbon::today();
        $query = $this->db->table('documents AS d')
            ->whereIn('d.state_type_id', self::STATES)
            ->whereIn('d.document_type_id', ['01', '03', '07', '08'])
            ->where('d.date_of_issue', $today->format('Y-m-d'))
            ->selectRaw('d.state_type_id AS state, d.document_type_id AS dtype, COUNT(*) AS cnt, SUM(' . $this->amountExpr('d') . ') AS amt')
            ->groupBy('state', 'dtype');
        $rows = $this->scope($query, 'd')->get();

        $count = 0;
        $total = 0.0;
        $accepted = 0;
        $pending = 0;
        foreach ($rows as $row) {
            $count += (int) $row->cnt;
            $total += ($row->dtype === '07' ? -1 : 1) * (float) $row->amt;
            if ($row->state === self::ACCEPTED_STATE) {
                $accepted += (int) $row->cnt;
            } elseif (in_array($row->state, self::PENDING_STATES, true)) {
                $pending += (int) $row->cnt;
            }
        }

        return ['date' => $today->format('Y-m-d'), 'count' => $count, 'total' => round($total, 2), 'accepted' => $accepted, 'pending' => $pending];
    }

    // ------------------------------------------------------------------------------------
    // Receivables
    // ------------------------------------------------------------------------------------

    /** Pending credit documents (invoices on credit with an unpaid balance), largest balance first. */
    private function receivables()
    {
        $fees = $this->db->table('document_fee')
            ->selectRaw('document_id, MAX(date) AS due')
            ->groupBy('document_id');
        $paid = $this->db->table('document_payments')
            ->selectRaw('document_id, SUM(payment) AS paid')
            ->groupBy('document_id');

        $rate = "IF(d.currency_type_id = 'USD', d.exchange_rate_sale, 1)";
        $query = $this->db->table('documents AS d')
            ->joinSub($fees, 'f', 'f.document_id', '=', 'd.id')
            ->leftJoinSub($paid, 'p', 'p.document_id', '=', 'd.id')
            ->leftJoin('persons AS c', 'c.id', '=', 'd.customer_id')
            ->where('d.payment_condition_id', '02')
            ->whereIn('d.state_type_id', self::STATES)
            ->whereIn('d.document_type_id', ['01', '03', '08'])
            ->whereRaw('d.total - COALESCE(p.paid, 0) > 0.005')
            ->selectRaw(
                "d.id, d.series, d.number, d.document_type_id, d.date_of_issue, f.due, c.name AS customer, "
                . "(d.total - COALESCE(p.paid, 0)) * {$rate} AS pending"
            )
            ->orderByDesc('pending')
            ->limit(self::MAX_RECEIVABLES);
        $this->scope($query, 'd');

        $today = Carbon::today();
        $list = [];
        foreach ($query->get() as $row) {
            $due = Carbon::parse($row->due)->startOfDay();
            $issue = Carbon::parse($row->date_of_issue)->startOfDay();
            $daysToDue = (int) $today->diffInDays($due, false);
            $list[] = [
                'id' => (int) $row->id,
                'customer' => $row->customer ?: 'Cliente sin nombre',
                'document' => $row->series . '-' . $row->number,
                'issue' => $issue->format('Y-m-d'),
                'due' => $due->format('Y-m-d'),
                'amount' => round((float) $row->pending, 2),
                'days_to_due' => $daysToDue,
                'status' => $daysToDue < 0 ? 'overdue' : ($daysToDue <= 7 ? 'soon' : 'ok'),
            ];
        }

        return $list;
    }

    private function emptyPayload()
    {
        return [
            'meta' => ['has_previous' => false, 'units' => [], 'default_unit' => null, 'labels' => $this->periodLabels('all', false)],
            'today' => $this->today(),
            'totals' => ['current' => ['docs' => 0, 'notes' => 0, 'total' => 0, 'count' => 0], 'previous' => null],
            'series' => new \stdClass(),
            'counts' => ['invoices' => 0, 'receipts' => 0, 'sale_notes' => 0, 'credit_notes' => 0],
            'split' => ['invoices' => 0, 'receipts' => 0, 'sale_notes' => 0],
            'weekdays' => ['labels' => ['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'], 'current' => array_fill(0, 7, 0), 'previous' => null],
            'receivables' => $this->receivables(),
            'purchases_year' => $this->purchasesYear(Carbon::today()->year),
        ];
    }

    // ------------------------------------------------------------------------------------
    // Purchases of the calendar year
    // ------------------------------------------------------------------------------------

    /** Purchases and perceptions per month of one calendar year (months that have not happened yet stay null). */
    private function purchasesYear($year)
    {
        $query = $this->db->table('purchases AS p')
            ->whereNull('p.deleted_at')
            ->whereIn('p.state_type_id', self::STATES)
            ->whereYear('p.date_of_issue', $year)
            ->selectRaw('MONTH(p.date_of_issue) AS m, SUM(' . $this->amountExpr('p') . ') AS amt, SUM(p.total_perception) AS per')
            ->groupBy('m');
        $this->scope($query, 'p');

        $buys = array_fill(0, 12, 0.0);
        $per = array_fill(0, 12, 0.0);
        foreach ($query->get() as $row) {
            $buys[$row->m - 1] = round((float) $row->amt, 2);
            $per[$row->m - 1] = round((float) $row->per, 2);
        }

        $today = Carbon::today();
        $currentMonth = $today->year === (int) $year ? $today->month : ($today->year > (int) $year ? 12 : 0);
        for ($i = $currentMonth; $i < 12; $i++) {
            $buys[$i] = null;
            $per[$i] = null;
        }

        return [
            'year' => (int) $year,
            'months' => array_map('ucfirst', self::$monthsLong),
            'buys' => $buys,
            'perceptions' => $per,
            'current_month' => $today->year === (int) $year ? $today->month - 1 : null,
            'total' => round(array_sum($buys) + array_sum($per), 2),
        ];
    }
}
