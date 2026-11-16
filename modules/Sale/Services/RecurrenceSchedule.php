<?php

namespace Modules\Sale\Services;

use Carbon\Carbon;
use InvalidArgumentException;

/**
 * Pure date math for recurrent billing (no database access).
 *
 * Occurrences are always computed from the start date (never chained from the previous one), so a
 * contract starting on 31 Jan bills 31 Jan, 28/29 Feb, 31 Mar, ... without drifting to the 28th.
 */
final class RecurrenceSchedule
{
    const PERIOD_MONTH = 'month';
    const PERIOD_YEAR = 'year';

    /** Safety cap on the search loop (a century of monthly occurrences). */
    const MAX_OCCURRENCES = 1300;

    /**
     * k-th occurrence (k = 0 is the first slot of the series; it can precede $start when a custom day is used).
     */
    public static function occurrence($start, string $period, int $interval, int $k, ?int $day = null): Carbon
    {
        self::assertPeriod($period, $interval);

        $start = Carbon::parse($start)->startOfDay();
        $anchorDay = $day ?: $start->day;
        $months = ($period === self::PERIOD_YEAR ? 12 : 1) * $interval * $k;

        $base = $start->copy()->startOfMonth()->addMonthsNoOverflow($months);

        return $base->copy()->day(min($anchorDay, $base->daysInMonth));
    }

    /**
     * First billing date: the first occurrence that is on or after the start date.
     */
    public static function first($start, string $period, int $interval, ?int $day = null): Carbon
    {
        return self::next($start, $period, $interval, Carbon::parse($start)->startOfDay()->subDay(), $day);
    }

    /**
     * First occurrence strictly after $after.
     */
    public static function next($start, string $period, int $interval, $after, ?int $day = null): Carbon
    {
        $after = Carbon::parse($after)->startOfDay();

        for ($k = 0; $k < self::MAX_OCCURRENCES; $k++) {
            $candidate = self::occurrence($start, $period, $interval, $k, $day);
            if ($candidate->gt($after) && $candidate->gte(Carbon::parse($start)->startOfDay())) {
                return $candidate;
            }
        }

        throw new InvalidArgumentException('Recurrence search exceeded the supported horizon.');
    }

    /**
     * Next billing date after $current, or null when it would fall past the end date.
     */
    public static function advance($start, ?string $end, string $period, int $interval, $current, ?int $day = null): ?Carbon
    {
        $next = self::next($start, $period, $interval, $current, $day);

        if ($end && $next->gt(Carbon::parse($end)->startOfDay())) {
            return null;
        }

        return $next;
    }

    /**
     * Billing dates (inclusive) that fall inside [$from, $to], capped by the contract end date.
     *
     * @return Carbon[]
     */
    public static function between($start, ?string $end, string $period, int $interval, $from, $to, ?int $day = null): array
    {
        $from = Carbon::parse($from)->startOfDay();
        $to = Carbon::parse($to)->startOfDay();
        $startDay = Carbon::parse($start)->startOfDay();
        $result = [];

        for ($k = 0; $k < self::MAX_OCCURRENCES; $k++) {
            $candidate = self::occurrence($start, $period, $interval, $k, $day);
            if ($candidate->gt($to) || ($end && $candidate->gt(Carbon::parse($end)->startOfDay()))) {
                break;
            }
            if ($candidate->gte($from) && $candidate->gte($startDay)) {
                $result[] = $candidate;
            }
        }

        return $result;
    }

    /**
     * Label of the billed period, e.g. "11/2026".
     */
    public static function periodLabel($billingDate): string
    {
        return Carbon::parse($billingDate)->format('m/Y');
    }

    private static function assertPeriod(string $period, int $interval): void
    {
        if (!in_array($period, [self::PERIOD_MONTH, self::PERIOD_YEAR], true)) {
            throw new InvalidArgumentException("Invalid recurrence period: {$period}");
        }
        if ($interval < 1) {
            throw new InvalidArgumentException('Recurrence interval must be at least 1.');
        }
    }
}
