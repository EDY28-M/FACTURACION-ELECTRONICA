<?php

namespace App\CoreFacturalo\Services\Sire;

use RuntimeException;
use ZipArchive;

/**
 * Parses the RVIE "propuesta" TXT that SUNAT generates through the SIRE API (service 5.18 + 5.17 of the
 * "Manual de servicios web API - SIRE Ventas" v32) into plain rows used by SireReconciler.
 *
 * [unverified] The manual does NOT document the column layout of the exported propuesta file; the
 * RS 112-2021/SUNAT only says it holds the "Anexo 2" information (not available in our sources). What is
 * assumed, from Anexo 3 (same RVIE field order, fields 1-33) and from real exports:
 *  - one header row with the Anexo 3 column names (the parser is HEADER-DRIVEN and accent/case tolerant);
 *  - delimiter detected among "|", ",", ";" and tab;
 *  - when no header is recognised, the Anexo 3 positional order is used (see POSITIONAL).
 * Anything not found stays null and the reconciliation degrades gracefully (e.g. no state comparison).
 */
class SirePropuestaParser
{
    /** Hard cap of an uncompressed entry, to avoid zip bombs. */
    public const MAX_ENTRY_BYTES = 50 * 1024 * 1024;

    /** Minimum share of non-empty rows that must look like a document (type, series, number, numeric total). */
    public const MIN_VALID_RATIO = 0.8;

    /** Anexo 3 positions (0-based) used when the file has no header. */
    public const POSITIONAL = [
        'car' => 3, 'date' => 4, 'type' => 6, 'series' => 7, 'number' => 8, 'number_to' => 9,
        'igv' => 16, 'total' => 25, 'currency' => 26, 'rate' => 27,
    ];

    /**
     * Extracts every .txt/.csv entry of the ZIP (SUNAT may split big files) and parses them.
     *
     * @return array{rows: array, skipped: int, header: bool, delimiter: string}
     */
    public static function parseZip(string $zipBinary): array
    {
        $path = tempnam(sys_get_temp_dir(), 'sirezip');
        try {
            file_put_contents($path, $zipBinary);
            $zip = new ZipArchive();
            if ($zip->open($path) !== true) {
                throw new RuntimeException('El archivo descargado de SUNAT no es un ZIP válido.');
            }

            $result = ['rows' => [], 'skipped' => 0, 'header' => false, 'delimiter' => ''];
            $found = false;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $name = (string) ($stat['name'] ?? '');
                if (substr($name, -1) === '/' || !preg_match('/\.(txt|csv)$/i', $name)) {
                    continue;
                }
                if (($stat['size'] ?? 0) > self::MAX_ENTRY_BYTES) {
                    throw new RuntimeException('El archivo de propuesta de SUNAT es demasiado grande.');
                }
                $found = true;
                // Read at most MAX+1 bytes: the declared size can lie, the actual content is checked too.
                $content = $zip->getFromIndex($i, self::MAX_ENTRY_BYTES + 1);
                if ($content === false) {
                    throw new RuntimeException('No se pudo leer el archivo de la propuesta de SUNAT.');
                }
                if (strlen($content) > self::MAX_ENTRY_BYTES) {
                    throw new RuntimeException('El archivo de propuesta de SUNAT es demasiado grande.');
                }
                $part = self::parseText($content);
                $result['rows'] = array_merge($result['rows'], $part['rows']);
                $result['skipped'] += $part['skipped'];
                $result['header'] = $result['header'] || $part['header'];
                $result['delimiter'] = $result['delimiter'] ?: $part['delimiter'];
            }
            $zip->close();

            if (!$found) {
                throw new RuntimeException('El ZIP de SUNAT no contiene un archivo TXT de propuesta.');
            }

            return $result;
        } finally {
            @unlink($path);
        }
    }

    /**
     * @return array{rows: array, skipped: int, header: bool, delimiter: string}
     *               rows: type, series, number, number_to (null for single documents), date (Y-m-d|null),
     *               total (float, as informed), igv, currency, car, state
     */
    public static function parseText(string $txt): array
    {
        $txt = preg_replace('/^\xEF\xBB\xBF/', '', $txt);
        $lines = preg_split('/\r\n|\n|\r/', $txt);
        $lines = array_values(array_filter($lines, function ($l) {
            return trim($l) !== '';
        }));

        if (!$lines) {
            return ['rows' => [], 'skipped' => 0, 'header' => false, 'delimiter' => ''];
        }

        $delimiter = self::detectDelimiter($lines[0]);
        $first = self::split($lines[0], $delimiter);
        $map = self::headerMap($first);
        $hasHeader = $map !== null;
        if ($hasHeader) {
            array_shift($lines);
            $nonEmpty = count($lines);
        } else {
            $map = self::POSITIONAL;
            if (self::looksLikeHeader($first)) {
                // Header row present but with unknown/incomplete columns: it is not data. Fall back to Anexo 3 positions.
                array_shift($lines);
            }
            $nonEmpty = count($lines);
        }

        $rows = [];
        $skipped = 0;
        $valid = 0;
        foreach ($lines as $line) {
            $cells = self::split($line, $delimiter);
            $get = function ($key) use ($map, $cells) {
                return isset($map[$key], $cells[$map[$key]]) ? trim((string) $cells[$map[$key]]) : '';
            };

            $type = $get('type');
            $series = $get('series');
            $from = $get('number');
            $rawTotal = $get('total');

            if ($type !== '' && preg_match('/^\d{1,2}$/', $type) && $series !== '' && $from !== '' && $rawTotal !== '' && self::isNumeric($rawTotal)) {
                $valid++;
            }

            if ($type === '' && $series === '') {
                $skipped++;
                continue;
            }

            $to = $get('number_to');

            $rows[] = [
                'type' => ctype_digit($type) ? str_pad($type, 2, '0', STR_PAD_LEFT) : strtoupper($type),
                'series' => strtoupper($series),
                'number' => $from,
                'number_to' => $to !== '' ? $to : null,
                'date' => self::date($get('date')),
                'total' => self::number($rawTotal),
                'igv' => self::number($get('igv')),
                'currency' => $get('currency'),
                'car' => $get('car'),
                'state' => $get('state'),
            ];
        }

        // Sanity gate: an unknown layout must not be reconciled (it would flood the report with false differences).
        if ($nonEmpty > 0 && ($valid / $nonEmpty) < self::MIN_VALID_RATIO) {
            throw new RuntimeException('No se reconoce el formato de la propuesta de SUNAT; no se compararon los datos.');
        }

        return ['rows' => $rows, 'skipped' => $skipped, 'header' => $hasHeader, 'delimiter' => $delimiter];
    }

    /** Lower-case, accent-free, alphanumeric-only normalization of a header cell. */
    public static function normalizeHeader(string $value): string
    {
        $value = strtr(mb_strtolower($value, 'UTF-8'), [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
            "\xC3" => '', // stray byte of a mis-decoded accent
        ]);

        return trim(preg_replace('/[^a-z0-9]+/', ' ', $value));
    }

    /** @return array<string,int>|null column map, or null when the line does not look like a header */
    private static function headerMap(array $cells)
    {
        $names = array_map([self::class, 'normalizeHeader'], $cells);
        $joined = implode('|', $names);
        if (strpos($joined, 'serie') === false || strpos($joined, 'total') === false) {
            return null;
        }

        $rules = [
            'car' => function ($n) { return strpos($n, 'car sunat') === 0 || $n === 'car'; },
            'date' => function ($n) { return strpos($n, 'fecha de emision') === 0; },
            'type' => function ($n) { return strpos($n, 'tipo cp') === 0 && strpos($n, 'modificado') === false; },
            'series' => function ($n) { return strpos($n, 'serie del cdp') === 0 || $n === 'serie'; },
            'number' => function ($n) { return strpos($n, 'nro cp o doc') === 0; },
            'number_to' => function ($n) { return strpos($n, 'nro final') === 0; },
            'igv' => function ($n) { return strpos($n, 'igv') === 0 && strpos($n, 'dscto') === false; },
            'total' => function ($n) { return $n === 'total cp' || $n === 'total'; },
            'currency' => function ($n) { return $n === 'moneda'; },
            'rate' => function ($n) { return $n === 'tipo de cambio'; },
            'state' => function ($n) { return strpos($n, 'est comp') === 0 || $n === 'estado'; },
        ];

        $map = [];
        foreach ($names as $index => $name) {
            foreach ($rules as $key => $rule) {
                if (!isset($map[$key]) && $rule($name)) {
                    $map[$key] = $index;
                }
            }
        }

        foreach (['type', 'series', 'number', 'total'] as $required) {
            if (!isset($map[$required])) {
                return null;
            }
        }

        return $map;
    }

    private static function looksLikeHeader(array $cells): bool
    {
        $joined = implode('|', array_map([self::class, 'normalizeHeader'], $cells));

        return strpos($joined, 'serie') !== false && strpos($joined, 'total') !== false;
    }

    private static function isNumeric(string $value): bool
    {
        $v = str_replace([' ', "\xC2\xA0"], '', $value);

        return $v !== '' && preg_match('/^-?[\d.,]+$/', $v) === 1 && preg_match('/\d/', $v) === 1;
    }

    private static function detectDelimiter(string $line): string
    {
        $best = '|';
        $max = -1;
        foreach (['|', ',', ';', "\t"] as $candidate) {
            $count = substr_count($line, $candidate);
            if ($count > $max) {
                $max = $count;
                $best = $candidate;
            }
        }

        return $best;
    }

    private static function split(string $line, string $delimiter): array
    {
        return $delimiter === '|' ? explode('|', $line) : str_getcsv($line, $delimiter);
    }

    private static function date(string $value)
    {
        if (preg_match('#^(\d{2})/(\d{2})/(\d{4})#', $value, $m)) {
            return "{$m[3]}-{$m[2]}-{$m[1]}";
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $value, $m)) {
            return "{$m[1]}-{$m[2]}-{$m[3]}";
        }

        return null;
    }

    /**
     * Parses "1234.50", "1,234.56", "1.234,56" and "1234,56". The last separator is the decimal one when
     * both appear; a lone separator followed by exactly 3 digits groups (repeated) is a thousands separator.
     */
    private static function number(string $value): float
    {
        $value = str_replace([' ', "\xC2\xA0"], '', $value);
        if ($value === '' || !preg_match('/^-?[\d.,]+$/', $value)) {
            return 0.0;
        }

        $comma = strrpos($value, ',');
        $dot = strrpos($value, '.');
        if ($comma !== false && $dot !== false) {
            $decimal = $comma > $dot ? ',' : '.';
            $thousands = $decimal === ',' ? '.' : ',';
            $value = str_replace($thousands, '', $value);
            $value = str_replace($decimal, '.', $value);
        } elseif ($comma !== false || $dot !== false) {
            $sep = $comma !== false ? ',' : '.';
            $grouped = substr_count($value, $sep) > 1 || ($sep === ',' && preg_match('/^-?\d{1,3},\d{3}$/', $value) === 1);
            if ($grouped) {
                $value = str_replace($sep, '', $value); // thousands grouping
            } elseif ($sep === ',') {
                $value = str_replace(',', '.', $value);
            }
        }

        return is_numeric($value) ? (float) $value : 0.0;
    }
}
