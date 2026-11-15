<?php

namespace App\CoreFacturalo\Services\Sire;

/**
 * Pure (database-free) comparison between SUNAT's RVIE propuesta and the tenant's own sales.
 *
 * Matching key: tipo CP + serie + numero (series upper-cased, number without zero padding).
 * Amount: field 26 "Total CP" in PEN with the sign convention of the replacement file
 * (credit notes negative, everything else positive, voided documents zero) applied to BOTH sides, so a
 * credit note informed with a positive sign by one side still matches [unverified sign convention of the
 * SUNAT export]. Amounts are compared with a tolerance (default 0.01).
 *
 * Boletas consolidated by SUNAT in a range (field 9 "Nro inicial" + field 10 "Nro final"): the range is NOT
 * expanded into one row per number. System documents that find no individual SUNAT line are assigned to the
 * range with the same type/series that contains their number; then the SUM of those system totals is compared
 * with the range total. A range with no system document at all is reported as "solo en SUNAT"; a range whose
 * sum differs is reported under "diferencias_rango". The document count of a range is (final - initial + 1).
 */
class SireReconciler
{
    public const TOLERANCE = 0.01;

    /** Max items kept per list (counts always reflect the full figures). */
    public const MAX_ITEMS = 200;

    /** Ranges wider than this are not trusted (garbage input). */
    private const MAX_RANGE_SPAN = 1000000;

    /**
     * @param array $sunatRows  rows of SirePropuestaParser (type, series, number, number_to, date, total)
     * @param array $systemRows rows with type, series, number, date, total (signed as in the replacement file), state
     * @param array $excluded   key (see key()) => state_type_id of system documents NOT reported (pending/rejected),
     *                          only used to annotate "solo en SUNAT" items
     */
    public static function reconcile(array $sunatRows, array $systemRows, array $excluded = [], float $tolerance = self::TOLERANCE, int $maxItems = self::MAX_ITEMS): array
    {
        $sunat = ['count' => 0, 'rows' => count($sunatRows), 'total' => 0.0, 'by_type' => []];
        $system = ['count' => 0, 'rows' => count($systemRows), 'total' => 0.0, 'by_type' => []];

        // Index the system side (same key twice would be a data problem: amounts are added, count increases).
        $sysIndex = [];
        foreach ($systemRows as $row) {
            $key = self::key($row['type'], $row['series'], $row['number']);
            $total = self::signed($row['type'], (float) $row['total']);
            self::tally($system, $row['type'], 1, $total);
            if (isset($sysIndex[$key])) {
                $sysIndex[$key]['total'] += $total;
                continue;
            }
            $sysIndex[$key] = [
                'type' => self::type($row['type']), 'series' => self::series($row['series']),
                'number' => self::number($row['number']), 'date' => $row['date'] ?? null,
                'total' => $total, 'state' => $row['state'] ?? null, 'used' => false,
            ];
        }

        $onlySunat = [];
        $amountDiffs = [];
        $ranges = [];
        $matched = 0;
        $seen = [];

        foreach ($sunatRows as $row) {
            $type = self::type($row['type']);
            $series = self::series($row['series']);
            $from = self::number($row['number']);
            $to = ($row['number_to'] ?? null) !== null && $row['number_to'] !== '' ? self::number($row['number_to']) : null;
            $total = self::signed($type, (float) $row['total']);

            $isRange = $to !== null && $to !== $from && ctype_digit($from) && ctype_digit($to)
                && (int) $to > (int) $from && ((int) $to - (int) $from) < self::MAX_RANGE_SPAN;

            if ($isRange) {
                self::tally($sunat, $type, (int) $to - (int) $from + 1, $total);
                $ranges[$type.'|'.$series][] = [
                    'type' => $type, 'series' => $series, 'from' => (int) $from, 'to' => (int) $to,
                    'date' => $row['date'] ?? null, 'total' => $total, 'sys_total' => 0.0, 'sys_count' => 0,
                ];
                continue;
            }

            self::tally($sunat, $type, 1, $total);
            $key = self::key($type, $series, $from);

            if (isset($seen[$key])) {
                // Same document listed twice by SUNAT: fold the amount into the first occurrence.
                $seen[$key]['total'] += $total;
                continue;
            }
            $seen[$key] = ['type' => $type, 'series' => $series, 'number' => $from, 'date' => $row['date'] ?? null, 'total' => $total];
        }

        foreach ($seen as $key => $item) {
            if (!isset($sysIndex[$key])) {
                $onlySunat[] = [
                    'type' => $item['type'], 'series' => $item['series'], 'number' => $item['number'],
                    'date' => $item['date'], 'sunat_total' => round($item['total'], 2), 'system_total' => null,
                    'note' => isset($excluded[$key]) ? 'En el sistema no está informable (estado '.$excluded[$key].').' : null,
                ];
                continue;
            }

            $sys = &$sysIndex[$key];
            $sys['used'] = true;
            $matched++;
            $diff = round($item['total'] - $sys['total'], 2);
            if (abs($diff) > $tolerance + 1e-9) {
                $amountDiffs[] = [
                    'type' => $item['type'], 'series' => $item['series'], 'number' => $item['number'],
                    'date' => $item['date'] ?? $sys['date'],
                    'sunat_total' => round($item['total'], 2), 'system_total' => round($sys['total'], 2),
                    'difference' => $diff,
                    'note' => self::diffNote($item['total'], $sys),
                ];
            }
            unset($sys);
        }

        // System documents without an individual SUNAT line: inside a SUNAT range, or only in the system.
        $onlySystem = [];
        foreach ($sysIndex as $key => $sys) {
            if ($sys['used']) {
                continue;
            }
            $number = ctype_digit($sys['number']) ? (int) $sys['number'] : null;
            $assigned = false;
            if ($number !== null) {
                foreach ($ranges[$sys['type'].'|'.$sys['series']] ?? [] as $i => $range) {
                    if ($number >= $range['from'] && $number <= $range['to']) {
                        $ranges[$sys['type'].'|'.$sys['series']][$i]['sys_total'] += $sys['total'];
                        $ranges[$sys['type'].'|'.$sys['series']][$i]['sys_count']++;
                        $assigned = true;
                        break;
                    }
                }
            }
            if (!$assigned) {
                $onlySystem[] = [
                    'type' => $sys['type'], 'series' => $sys['series'], 'number' => $sys['number'],
                    'date' => $sys['date'], 'sunat_total' => null, 'system_total' => round($sys['total'], 2),
                    'note' => $sys['state'] === '11' ? 'Anulado en el sistema.' : null,
                ];
            }
        }

        $rangeDiffs = [];
        foreach ($ranges as $group) {
            foreach ($group as $range) {
                $label = $range['from'].'-'.$range['to'];
                $diff = round($range['total'] - $range['sys_total'], 2);
                $item = [
                    'type' => $range['type'], 'series' => $range['series'], 'number' => $label, 'date' => $range['date'],
                    'sunat_total' => round($range['total'], 2), 'system_total' => round($range['sys_total'], 2),
                    'difference' => $diff,
                    'expected_count' => $range['to'] - $range['from'] + 1, 'system_count' => $range['sys_count'],
                ];
                if ($range['sys_count'] === 0 && abs($range['total']) > $tolerance) {
                    $item['system_total'] = null;
                    $item['note'] = 'Rango de boletas consolidado por SUNAT sin comprobantes en el sistema.';
                    $onlySunat[] = $item;
                } elseif (abs($diff) > $tolerance + 1e-9) {
                    $item['note'] = 'La suma de las boletas del sistema en el rango no coincide con el total de SUNAT.';
                    $rangeDiffs[] = $item;
                } else {
                    $matched++;
                }
            }
        }

        foreach (['by_type'] as $k) {
            ksort($sunat[$k]);
            ksort($system[$k]);
        }
        $sunat['total'] = round($sunat['total'], 2);
        $system['total'] = round($system['total'], 2);

        $lists = ['solo_en_sunat' => $onlySunat, 'solo_en_sistema' => $onlySystem, 'diferencias_monto' => $amountDiffs, 'diferencias_rango' => $rangeDiffs];
        $result = [
            'tolerance' => $tolerance,
            'sunat' => $sunat,
            'system' => $system,
            'matched' => $matched,
            'difference_total' => round($sunat['total'] - $system['total'], 2),
            'in_sync' => !$onlySunat && !$onlySystem && !$amountDiffs && !$rangeDiffs,
        ];
        foreach ($lists as $name => $items) {
            usort($items, function ($a, $b) {
                return [$a['date'] ?? '', $a['type'], $a['series'], (string) $a['number']] <=> [$b['date'] ?? '', $b['type'], $b['series'], (string) $b['number']];
            });
            $result[$name.'_count'] = count($items);
            $result[$name] = array_slice($items, 0, $maxItems);
        }

        return $result;
    }

    public static function key($type, $series, $number): string
    {
        return self::type($type).'|'.self::series($series).'|'.self::number($number);
    }

    public static function type($type): string
    {
        $type = trim((string) $type);

        return ctype_digit($type) ? str_pad($type, 2, '0', STR_PAD_LEFT) : strtoupper($type);
    }

    public static function series($series): string
    {
        return strtoupper(trim((string) $series));
    }

    /** Number without zero padding ("00000025" -> "25"); non-numeric numbers are only upper-cased. */
    public static function number($number): string
    {
        $number = strtoupper(trim((string) $number));
        if ($number !== '' && ctype_digit($number)) {
            return ltrim($number, '0') === '' ? '0' : ltrim($number, '0');
        }

        return $number;
    }

    private static function signed($type, float $total): float
    {
        $total = round($total, 2);

        return self::type($type) === '07' ? -abs($total) : abs($total);
    }

    private static function tally(array &$side, $type, int $count, float $total): void
    {
        $type = self::type($type);
        $side['count'] += $count;
        $side['total'] += $total;
        $bucket = &$side['by_type'][$type];
        $bucket = $bucket ?? ['type' => $type, 'count' => 0, 'total' => 0.0];
        $bucket['count'] += $count;
        $bucket['total'] = round($bucket['total'] + $total, 2);
        unset($bucket);
    }

    private static function diffNote(float $sunatTotal, array $sys): ?string
    {
        if (($sys['state'] ?? null) === '11' && abs($sunatTotal) > self::TOLERANCE) {
            return 'Anulado en el sistema, pero SUNAT lo informa con importe.';
        }
        if (abs($sunatTotal) <= self::TOLERANCE && abs($sys['total']) > self::TOLERANCE) {
            return 'SUNAT lo informa en cero (posible baja o anulación).';
        }

        return null;
    }
}
