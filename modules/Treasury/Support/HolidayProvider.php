<?php

namespace Modules\Treasury\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Supplies the non-working holidays (Y-m-d list) consumed by BusinessDayCalculator.
 *
 * Source order: the tenant table `treasury_holidays` first (rows flagged `is_business_day_override` are días
 * no laborables that SUNAT treats as business days and are never returned), then the `treasury.holidays`
 * config list, which is merged in so a manual config entry still counts and which is the only source when the
 * table does not exist yet (migration not run). Invokable so it can be injected as a callable.
 */
class HolidayProvider
{
    /**
     * @return array<int, string>
     */
    public function __invoke(): array
    {
        return $this->all();
    }

    /**
     * @return array<int, string>
     */
    public function all(): array
    {
        $dates = $this->fromTable();
        foreach ($this->fromConfig() as $date) {
            $dates[$date] = $date;
        }

        ksort($dates);

        return array_values($dates);
    }

    /**
     * @return array<string, string>
     */
    private function fromTable(): array
    {
        $dates = [];
        try {
            if (!Model::getConnectionResolver()) {
                return [];
            }
            $connection = DB::connection('tenant');
            if (!$connection->getSchemaBuilder()->hasTable('treasury_holidays')) {
                return [];
            }

            $rows = $connection->table('treasury_holidays')
                ->where('is_business_day_override', false)
                ->pluck('date');
            foreach ($rows as $date) {
                $date = substr((string) $date, 0, 10);
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                    $dates[$date] = $date;
                }
            }
        } catch (\Throwable $e) {
            return [];
        }

        return $dates;
    }

    /**
     * @return array<int, string>
     */
    private function fromConfig(): array
    {
        try {
            $list = (array) config('treasury.holidays', []);
        } catch (\Throwable $e) {
            return [];
        }

        $dates = [];
        foreach ($list as $date) {
            $date = trim((string) $date);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                $dates[] = $date;
            }
        }

        return $dates;
    }
}
