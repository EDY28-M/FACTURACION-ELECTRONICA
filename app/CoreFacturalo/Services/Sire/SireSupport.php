<?php

namespace App\CoreFacturalo\Services\Sire;

use Carbon\Carbon;
use InvalidArgumentException;
use RuntimeException;
use ZipArchive;

/**
 * Helpers shared by the SIRE flat-file builders (RVIE sales, RCE purchases).
 * All methods are static and database-free.
 */
class SireSupport
{
    /** Max entries kept per warnings/errors list (a final "y N mas" line is appended). */
    public const MAX_MESSAGES = 50;

    /** @return int[] [year, month] */
    public static function validatePeriod(string $period, ?Carbon $now = null): array
    {
        if (!preg_match('/^(\d{4})(0[1-9]|1[0-2])$/', $period, $m)) {
            throw new InvalidArgumentException('El periodo debe tener el formato AAAAMM.');
        }

        $year = (int) $m[1];
        $month = (int) $m[2];
        $now = $now ?: Carbon::now('America/Lima');

        if ($year < 2000 || ($year * 100 + $month) > ((int) $now->format('Y') * 100 + (int) $now->format('n'))) {
            throw new InvalidArgumentException('El periodo no puede ser futuro.');
        }

        return [$year, $month];
    }

    public static function amount(float $value): string
    {
        $value = round($value, 2);
        if ($value == 0.0) {
            $value = 0.0; // avoid "-0.00"
        }

        return number_format($value, 2, '.', '');
    }

    public static function date(?string $date): string
    {
        return $date ? Carbon::parse($date)->format('d/m/Y') : '';
    }

    /** Removes the field separator and line breaks, collapsing spaces; optionally truncates. */
    public static function text(string $value, ?int $max = null): string
    {
        $value = trim(preg_replace('/\s+/', ' ', str_replace('|', ' ', $value)));

        return $max !== null ? mb_substr($value, 0, $max) : $value;
    }

    public static function preview(array $items, int $max = 8, ?int $total = null): string
    {
        $total = $total ?? count($items);
        $shown = array_slice($items, 0, $max);

        return implode(', ', $shown).($total > count($shown) ? sprintf(' y %d mas', $total - count($shown)) : '');
    }

    /** Counts a message and keeps it only while the list is below MAX_MESSAGES. */
    public static function addMessage(array &$state, string $list, string $message): void
    {
        $state[$list === 'errors' ? 'err_count' : 'warn_count']++;
        if (count($state[$list]) < self::MAX_MESSAGES) {
            $state[$list][] = $message;
        }
    }

    /** Appends the "y N mas" line when more messages were counted than kept. */
    public static function capList(array $list, int $count): array
    {
        if ($count > count($list)) {
            $list[] = sprintf('y %d mas', $count - count($list));
        }

        return $list;
    }

    /**
     * Converts every monetary component and the total to PEN (rounding each to 2 decimals) and absorbs a
     * residual of up to S/ 0.05 so the converted components add up exactly to the converted total. The
     * residual goes to $preferred when it is non-zero, otherwise to the largest component. Larger gaps are
     * genuine inconsistencies and are left for the reconciliation warning.
     *
     * @param string[] $components keys of $doc to convert
     * @return array converted components plus 'total'
     */
    public static function convertToPen(array $doc, array $components, float $rate, string $preferred): array
    {
        $conv = [];
        foreach ($components as $key) {
            $conv[$key] = round((float) ($doc[$key] ?? 0) * $rate, 2);
        }
        $total = round((float) ($doc['total'] ?? 0) * $rate, 2);

        $residual = round($total - array_sum($conv), 2);
        if ($residual != 0.0 && abs($residual) <= 0.05) {
            $target = ($conv[$preferred] ?? 0.0) != 0.0 ? $preferred : null;
            if ($target === null) {
                $max = 0.0;
                foreach ($conv as $key => $value) {
                    if (abs($value) > $max) {
                        $max = abs($value);
                        $target = $key;
                    }
                }
            }
            if ($target !== null) {
                $conv[$target] = round($conv[$target] + $residual, 2);
            }
        }
        $conv['total'] = $total;

        return $conv;
    }

    /**
     * Creates the ZIP in a temporary file from the TXT file and returns its path
     * (caller deletes both after sending). The TXT is read when the archive closes.
     */
    public static function zipFile(string $filename, string $txtPath, string $prefix = 'sire'): string
    {
        $path = tempnam(sys_get_temp_dir(), $prefix);
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('No se pudo crear el archivo ZIP.');
        }
        if (filesize($txtPath) === 0) {
            $zip->addFromString($filename, '');
        } else {
            $zip->addFile($txtPath, $filename);
        }
        $zip->close();

        return $path;
    }
}
