<?php

namespace Modules\Treasury\Support;

use DateTimeImmutable;

/**
 * Business-day arithmetic for SPOT (detraction) deadlines.
 *
 * Saturdays and Sundays are always non-working. Holidays are data-driven: they come from an injected
 * provider (a callable returning a list of Y-m-d strings) so the list can come from config or a table
 * without touching this class. When no provider is given, `treasury.holidays` is read lazily from config.
 */
class BusinessDayCalculator
{
    /** @var callable|null */
    private $holidayProvider;

    /** @var array<string, bool>|null */
    private $holidayCache = null;

    /**
     * @param callable|array<int, string>|null $holidays
     */
    public function __construct($holidays = null)
    {
        if (is_array($holidays)) {
            $list = $holidays;
            $holidays = function () use ($list) {
                return $list;
            };
        }

        $this->holidayProvider = $holidays;
    }

    public function isBusinessDay(string $date): bool
    {
        $day = new DateTimeImmutable($date);
        if ((int) $day->format('N') >= 6) {
            return false;
        }

        return !isset($this->holidays()[$day->format('Y-m-d')]);
    }

    /**
     * Years that have at least one configured holiday. Business-day results for any other year ignore holidays
     * and must be treated as estimates.
     *
     * @return array<int, int>
     */
    public function holidayYears(): array
    {
        $years = [];
        foreach (array_keys($this->holidays()) as $holiday) {
            $year = (int) substr($holiday, 0, 4);
            $years[$year] = $year;
        }

        return array_values($years);
    }

    public function hasHolidaysForYear(int $year): bool
    {
        return in_array($year, $this->holidayYears(), true);
    }

    /**
     * Returns the Nth business day (1-based) of the month that follows the month of $date.
     * Rolls over the year automatically (December -> January of the next year).
     */
    public function nthBusinessDayOfNextMonth(string $date, int $n): string
    {
        $n = max(1, $n);
        $cursor = (new DateTimeImmutable($date))->modify('first day of next month');

        $count = 0;
        // A month has at most 31 days; the loop also stops safely if holidays swallow the whole month.
        for ($i = 0; $i < 62; $i++) {
            if ($this->isBusinessDay($cursor->format('Y-m-d'))) {
                $count++;
                if ($count === $n) {
                    return $cursor->format('Y-m-d');
                }
            }
            $cursor = $cursor->modify('+1 day');
        }

        return $cursor->format('Y-m-d');
    }

    private function holidays(): array
    {
        if ($this->holidayCache !== null) {
            return $this->holidayCache;
        }

        $list = [];
        if ($this->holidayProvider !== null) {
            $list = (array) call_user_func($this->holidayProvider);
        } elseif (function_exists('config')) {
            try {
                $list = (array) config('treasury.holidays', []);
            } catch (\Throwable $e) {
                $list = [];
            }
        }

        $this->holidayCache = [];
        foreach ($list as $holiday) {
            $holiday = trim((string) $holiday);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $holiday)) {
                $this->holidayCache[$holiday] = true;
            }
        }

        return $this->holidayCache;
    }
}
