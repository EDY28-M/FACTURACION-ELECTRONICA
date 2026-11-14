<?php

namespace App\CoreFacturalo\Helpers\Igv;

use App\Models\Tenant\Configuration;
use Carbon\Carbon;

/**
 * Single source of truth (PHP side) for the IGV percentage.
 *
 * The reduced regime (IGV + IPM reported as one percentage under tax scheme 1000)
 * only applies to qualifying activities (restaurants / hotels / lodging):
 *   2022-09-01 .. 2025-12-31 => 10.00
 *   2026-01-01 .. 2026-12-31 => 10.50
 *   2027-01-01 .. 2027-12-31 => 15.00
 *   2028-01-01 onwards       => 18.00
 *
 * Keep in sync with resources/js/helpers/igv.js
 */
class IgvRate
{
    const GENERAL = 18.00;

    /** @var array [from, to (inclusive), rate] */
    const REDUCED_SCHEDULE = [
        ['2022-09-01', '2025-12-31', 10.00],
        ['2026-01-01', '2026-12-31', 10.50],
        ['2027-01-01', '2027-12-31', 15.00],
    ];

    public static function forDate(?string $date, bool $reducedRegime, bool $itemGeneralRate = false): float
    {
        if (!$reducedRegime || $itemGeneralRate) {
            return self::GENERAL;
        }

        $day = $date ? Carbon::parse($date)->format('Y-m-d') : Carbon::now()->format('Y-m-d');

        foreach (self::REDUCED_SCHEDULE as [$from, $to, $rate]) {
            if ($day >= $from && $day <= $to) {
                return $rate;
            }
        }

        return self::GENERAL;
    }

    /**
     * Reads the tenant configuration (cached per request).
     */
    public static function reducedRegimeEnabled(): bool
    {
        // Cached per tenant (website id) so long-lived processes never leak a flag between tenants.
        static $cache = [];

        try {
            $website = app(\Hyn\Tenancy\Environment::class)->tenant();
            $key = $website ? (string) $website->id : 'none';
        } catch (\Throwable $e) {
            $key = 'none';
        }

        if (!array_key_exists($key, $cache)) {
            try {
                $cache[$key] = (bool) optional(Configuration::first())->igv_reduced_regime;
            } catch (\Throwable $e) {
                return false;
            }
        }

        return $cache[$key];
    }

    /**
     * Convenience: rate for a date using the tenant configuration.
     */
    public static function forTenant(?string $date = null, bool $itemGeneralRate = false): float
    {
        return self::forDate($date, self::reducedRegimeEnabled(), $itemGeneralRate);
    }

    /**
     * Allowed percentages (general + reduced schedule), used to snap derived values.
     */
    const ALLOWED = [18.00, 15.00, 10.50, 10.00];

    /**
     * Percentage to report for an API row: the provided value when numeric; otherwise derived
     * from the row's own amounts (total_igv / total_base_igv) snapped to the nearest allowed
     * value; null when it cannot be derived (the input layer then defaults it by date/tenant).
     */
    public static function fromAmounts($percentage, $totalIgv, $totalBase)
    {
        if ($percentage !== null && $percentage !== '' && is_numeric($percentage)) {
            return $percentage;
        }

        $igv = (float) $totalIgv;
        $base = (float) $totalBase;
        if ($igv <= 0 || $base <= 0) {
            return null;
        }

        $derived = round($igv / $base * 100, 2);
        $nearest = self::ALLOWED[0];
        foreach (self::ALLOWED as $allowed) {
            if (abs($allowed - $derived) < abs($nearest - $derived)) {
                $nearest = $allowed;
            }
        }

        return $nearest;
    }

    /**
     * Keeps a client-provided percentage; when missing, defaults by issue date and tenant config.
     */
    public static function valueOrDefault($value, ?string $date = null, bool $itemGeneralRate = false): float
    {
        if ($value !== null && $value !== '' && is_numeric($value)) {
            return (float) $value;
        }

        return self::forTenant($date, $itemGeneralRate);
    }

    /**
     * Rate for an item identified by its internal id (imports); honours the item flag.
     */
    public static function forInternalId(?string $internalId, ?string $date = null): float
    {
        $general = false;
        if ($internalId !== null && $internalId !== '') {
            try {
                $general = (bool) \App\Models\Tenant\Item::where('internal_id', $internalId)->value('igv_general_rate');
            } catch (\Throwable $e) {
                $general = false;
            }
        }

        return self::forTenant($date, $general);
    }

    /**
     * Highest percentage_igv among the taxed (affectation 10) rows of a document; falls back to
     * $default (general rate when null). Accepts a collection or an array of items.
     */
    public static function fromItems($items, ?float $default = null): float
    {
        $max = 0.0;
        foreach ($items ?? [] as $row) {
            $row = (object) (is_array($row) ? $row : (is_object($row) && method_exists($row, 'toArray') ? $row->toArray() : (array) $row));
            $affectation = (string) ($row->affectation_igv_type_id ?? '');
            $rate = (float) ($row->percentage_igv ?? 0);
            if ($affectation === '10' && $rate > $max) {
                $max = $rate;
            }
        }

        return $max > 0 ? $max : ($default ?? self::GENERAL);
    }

    /**
     * Rate as a printable string, e.g. "18", "10.5", "15".
     */
    public static function format($rate): string
    {
        return rtrim(rtrim(number_format((float) $rate, 2, '.', ''), '0'), '.');
    }
}
