<?php

namespace Modules\Treasury\Support;

use Illuminate\Validation\ValidationException;

/**
 * Reads bank statement files (CSV / Excel) and turns rows into normalized, signed statement lines using a
 * configurable column mapping (Phase 5). Pure: no database access, no auth().
 *
 * Mapping config (columns are 1-based numbers, as shown in the spreadsheet):
 *   header_row         int   1-based row of the header; rows up to and including it are skipped (0 = no header)
 *   date_column        int   required
 *   description_column int   required
 *   reference_column   int   optional
 *   amount_mode        string  'signed' (one column, + inflow / - outflow) | 'debit_credit' (two columns)
 *   amount_column      int   required when amount_mode = signed
 *   debit_column       int   required when amount_mode = debit_credit (outflow)
 *   credit_column      int   required when amount_mode = debit_credit (inflow)
 *   date_format        string  one of DATE_FORMATS
 *   decimal_separator  string  '.' or ','
 *   invert_sign        bool  signed mode only: the bank reports withdrawals as positive
 *   delimiter          string  CSV only, optional (auto-detected when omitted)
 */
class StatementParser
{
    public const DATE_FORMATS = [
        'Y-m-d' => 'AAAA-MM-DD',
        'd/m/Y' => 'DD/MM/AAAA',
        'd-m-Y' => 'DD-MM-AAAA',
        'd.m.Y' => 'DD.MM.AAAA',
        'd/m/y' => 'DD/MM/AA',
        'm/d/Y' => 'MM/DD/AAAA',
        'Y/m/d' => 'AAAA/MM/DD',
        'excel' => 'Número de serie de Excel',
    ];

    public const AMOUNT_MODES = ['signed', 'debit_credit'];
    public const DELIMITERS = [',', ';', "\t", '|'];
    public const MAX_REPORTED_ERRORS = 20;

    /** Rows allowed above the data (titles, header) on top of the data-row limit. */
    private const HEADER_MARGIN = 600;

    private $maxRows;
    private $maxColumns;

    public function __construct(?int $maxRows = null, ?int $maxColumns = null)
    {
        $this->maxRows = $maxRows ?? (int) $this->configValue('treasury.reconciliation.max_rows', 5000);
        $this->maxColumns = $maxColumns ?? (int) $this->configValue('treasury.reconciliation.max_columns', 30);
    }

    /**
     * Validates and normalizes a mapping config. Throws a ValidationException keyed by `mapping.*`.
     */
    public function normalizeConfig(array $config): array
    {
        $errors = [];

        $column = function (string $key, bool $required) use ($config, &$errors): ?int {
            $value = $config[$key] ?? null;
            if ($value === null || $value === '') {
                if ($required) {
                    $errors['mapping.' . $key] = ['Indique el número de columna.'];
                }

                return null;
            }
            if (!is_numeric($value) || (int) $value != $value || (int) $value < 1 || (int) $value > 200) {
                $errors['mapping.' . $key] = ['El número de columna debe ser un entero entre 1 y 200.'];

                return null;
            }

            return (int) $value;
        };

        $mode = (string) ($config['amount_mode'] ?? 'signed');
        if (!in_array($mode, self::AMOUNT_MODES, true)) {
            $errors['mapping.amount_mode'] = ['El formato de importes no es válido.'];
            $mode = 'signed';
        }

        $headerRow = $config['header_row'] ?? 1;
        if (!is_numeric($headerRow) || (int) $headerRow < 0 || (int) $headerRow > 500) {
            $errors['mapping.header_row'] = ['La fila de encabezado debe estar entre 0 y 500.'];
            $headerRow = 1;
        }

        $dateFormat = (string) ($config['date_format'] ?? 'd/m/Y');
        if (!array_key_exists($dateFormat, self::DATE_FORMATS)) {
            $errors['mapping.date_format'] = ['El formato de fecha no es válido.'];
        }

        $decimal = (string) ($config['decimal_separator'] ?? '.');
        if (!in_array($decimal, ['.', ','], true)) {
            $errors['mapping.decimal_separator'] = ['El separador decimal debe ser "." o ",".'];
            $decimal = '.';
        }

        $delimiter = $config['delimiter'] ?? null;
        if ($delimiter === 'tab') {
            $delimiter = "\t";
        }
        if ($delimiter !== null && $delimiter !== '' && !in_array($delimiter, self::DELIMITERS, true)) {
            $errors['mapping.delimiter'] = ['El delimitador del CSV no es válido.'];
            $delimiter = null;
        }

        $normalized = [
            'header_row' => (int) $headerRow,
            'date_column' => $column('date_column', true),
            'description_column' => $column('description_column', true),
            'reference_column' => $column('reference_column', false),
            'amount_mode' => $mode,
            'amount_column' => $column('amount_column', $mode === 'signed'),
            'debit_column' => $column('debit_column', $mode === 'debit_credit'),
            'credit_column' => $column('credit_column', $mode === 'debit_credit'),
            'date_format' => $dateFormat,
            'decimal_separator' => $decimal,
            'invert_sign' => !empty($config['invert_sign']),
            'delimiter' => ($delimiter === null || $delimiter === '') ? null : $delimiter,
        ];

        if ($mode === 'debit_credit' && $normalized['debit_column'] !== null
            && $normalized['debit_column'] === $normalized['credit_column']) {
            $errors['mapping.credit_column'] = ['Las columnas de cargo y abono deben ser distintas.'];
        }

        if (!empty($errors)) {
            throw ValidationException::withMessages($errors);
        }

        return $normalized;
    }

    /**
     * Reads every row of the file as an array of cell values (strings for CSV, raw values for Excel).
     *
     * @return array<int, array<int, mixed>> 0-based list of rows, each a 0-based list of cells
     */
    public function readRows(string $path, string $extension, ?string $delimiter = null): array
    {
        $extension = strtolower($extension);

        if (!is_file($path)) {
            throw ValidationException::withMessages(['file' => ['No se pudo leer el archivo cargado.']]);
        }

        if (in_array($extension, ['xlsx', 'xls'], true)) {
            return $this->readSpreadsheet($path);
        }

        if (in_array($extension, ['csv', 'txt'], true)) {
            return $this->readCsv($path, $delimiter);
        }

        throw ValidationException::withMessages(['file' => ['Solo se admiten archivos CSV, TXT, XLS o XLSX.']]);
    }

    /**
     * @param array<int, array<int, mixed>> $rows
     *
     * @return array{lines: array<int, array>, errors: array<int, string>, error_count: int, warnings: array<int, string>, totals: array}
     */
    public function parse(array $rows, array $config): array
    {
        $config = $this->normalizeConfig($config);

        $lines = [];
        $errors = [];
        $errorCount = 0;
        $warnings = [];
        $skippedZero = 0;
        $dataRows = 0;

        $recordError = function (string $message) use (&$errors, &$errorCount): void {
            $errorCount++;
            if (count($errors) < self::MAX_REPORTED_ERRORS) {
                $errors[] = $message;
            }
        };

        foreach ($rows as $index => $row) {
            $rowNumber = $index + 1;
            if ($rowNumber <= $config['header_row']) {
                continue;
            }

            $dateRaw = $this->cell($row, $config['date_column']);
            $descriptionRaw = $this->cell($row, $config['description_column']);
            $referenceRaw = $config['reference_column'] !== null ? $this->cell($row, $config['reference_column']) : null;

            if ($config['amount_mode'] === 'signed') {
                $amountCells = [$this->cell($row, $config['amount_column'])];
            } else {
                $amountCells = [$this->cell($row, $config['debit_column']), $this->cell($row, $config['credit_column'])];
            }

            $dateEmpty = $this->isBlank($dateRaw);
            $amountsEmpty = true;
            foreach ($amountCells as $cell) {
                if (!$this->isBlank($cell)) {
                    $amountsEmpty = false;
                }
            }

            // Blank rows, titles and footers without a date and without an amount are not data.
            if ($dateEmpty && $amountsEmpty) {
                continue;
            }

            $dataRows++;
            if ($dataRows > $this->maxRows) {
                throw ValidationException::withMessages([
                    'file' => ['El archivo supera el máximo de ' . $this->maxRows . ' movimientos por extracto.'],
                ]);
            }

            $date = $dateEmpty ? null : $this->parseDate($dateRaw, $config['date_format']);
            if ($date === null) {
                $recordError("Fila {$rowNumber}: la fecha \"" . $this->display($dateRaw) . '" no coincide con el formato ' . self::DATE_FORMATS[$config['date_format']] . '.');
                continue;
            }

            $amount = $this->resolveAmount($amountCells, $config, $rowNumber, $recordError);
            if ($amount === null) {
                continue;
            }

            if (abs($amount) < 0.005) {
                $skippedZero++;
                continue;
            }

            $description = $this->cleanText($descriptionRaw, 500);
            $lines[] = [
                'line_no' => count($lines) + 1,
                'source_row' => $rowNumber,
                'date' => $date,
                'description' => $description !== '' ? $description : 'Sin descripción',
                'reference' => $referenceRaw !== null && !$this->isBlank($referenceRaw)
                    ? $this->cleanText($referenceRaw, 100)
                    : null,
                'amount' => Money::round($amount),
            ];
        }

        if ($skippedZero > 0) {
            $warnings[] = "Se omitieron {$skippedZero} fila(s) con importe cero.";
        }
        if ($errorCount > count($errors)) {
            $warnings[] = 'Hay ' . ($errorCount - count($errors)) . ' error(es) adicional(es) que no se listan.';
        }

        return [
            'lines' => $lines,
            'errors' => $errors,
            'error_count' => $errorCount,
            'warnings' => $warnings,
            'totals' => $this->totals($lines),
        ];
    }

    /**
     * Parses a monetary text. Handles thousands separators, currency symbols, parentheses and trailing minus as
     * negatives. Returns null when the text is not a valid number.
     */
    public function parseAmount($raw, string $decimalSeparator = '.'): ?float
    {
        if ($raw === null) {
            return null;
        }
        if (is_int($raw) || is_float($raw)) {
            return (float) $raw;
        }

        $text = trim((string) $raw);
        $text = str_replace(["\u{00A0}", ' '], '', $text);
        if ($text === '') {
            return null;
        }

        $negative = false;
        if (preg_match('/^\((.*)\)$/', $text, $m)) {
            $negative = true;
            $text = $m[1];
        }
        $text = preg_replace('/^(S\/\.?|S\/|US\$|\$|PEN|USD)/i', '', $text);
        $text = preg_replace('/(S\/\.?|S\/|US\$|\$|PEN|USD)$/i', '', $text);

        if (substr($text, -1) === '-') {
            $negative = !$negative;
            $text = substr($text, 0, -1);
        }
        if ($text !== '' && ($text[0] === '-' || $text[0] === '+')) {
            if ($text[0] === '-') {
                $negative = !$negative;
            }
            $text = substr($text, 1);
        }
        if ($text === '') {
            return null;
        }

        $thousands = $decimalSeparator === ',' ? '.' : ',';

        // A thousands separator is only valid as a group of exactly 3 digits ("1.234", "1.234.567,89"). Anything else
        // ("1.5" with decimal comma) is ambiguous and is rejected instead of silently read as 15.
        if (strpos($text, $thousands) !== false) {
            $grouped = '/^\d{1,3}(?:' . preg_quote($thousands, '/') . '\d{3})+(?:' . preg_quote($decimalSeparator, '/') . '\d+)?$/';
            if (!preg_match($grouped, $text)) {
                return null;
            }
        }

        $text = str_replace($thousands, '', $text);
        if ($decimalSeparator === ',') {
            $text = str_replace(',', '.', $text);
        }

        if (!preg_match('/^\d+(\.\d+)?$|^\.\d+$/', $text)) {
            return null;
        }

        $value = (float) $text;

        return $negative ? -$value : $value;
    }

    /**
     * Parses a date cell into Y-m-d. Returns null when invalid.
     *
     * @param mixed $raw
     */
    public function parseDate($raw, string $format): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        // Spreadsheet readers return dates as serial numbers (float/int).
        if (is_int($raw) || is_float($raw) || ($format === 'excel' && is_numeric($raw))) {
            $serial = (float) $raw;
            if ($serial < 1 || $serial > 80000) {
                return null;
            }
            try {
                return \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($serial)->format('Y-m-d');
            } catch (\Throwable $e) {
                return null;
            }
        }

        if ($format === 'excel') {
            return null;
        }

        $text = trim((string) $raw);
        // Drop a time part ("2026-10-05 00:00:00", "05/10/2026 14:33").
        if (strpos($format, ' ') === false && preg_match('/^(\S+)[ T]\d{1,2}:\d{2}/', $text, $m)) {
            $text = $m[1];
        }

        $date = \DateTimeImmutable::createFromFormat('!' . $format, $text);
        $errors = \DateTimeImmutable::getLastErrors();
        if ($date === false || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            return null;
        }

        $year = (int) $date->format('Y');
        if ($year < 1990 || $year > 2100) {
            return null;
        }

        return $date->format('Y-m-d');
    }

    /**
     * @param array<int, array> $lines
     */
    public function totals(array $lines): array
    {
        $inflow = 0.0;
        $outflow = 0.0;
        $min = null;
        $max = null;

        foreach ($lines as $line) {
            if ($line['amount'] > 0) {
                $inflow += $line['amount'];
            } else {
                $outflow += $line['amount'];
            }
            $min = $min === null || $line['date'] < $min ? $line['date'] : $min;
            $max = $max === null || $line['date'] > $max ? $line['date'] : $max;
        }

        return [
            'count' => count($lines),
            'inflow' => Money::round($inflow),
            'outflow' => Money::round($outflow),
            'net' => Money::round($inflow + $outflow),
            'date_from' => $min,
            'date_to' => $max,
        ];
    }

    private function resolveAmount(array $cells, array $config, int $rowNumber, callable $recordError): ?float
    {
        $decimal = $config['decimal_separator'];

        if ($config['amount_mode'] === 'signed') {
            if ($this->isBlank($cells[0])) {
                $recordError("Fila {$rowNumber}: el importe está vacío.");

                return null;
            }
            $value = $this->parseAmount($cells[0], $decimal);
            if ($value === null) {
                $recordError("Fila {$rowNumber}: el importe \"" . $this->display($cells[0]) . '" no es un número válido.');

                return null;
            }

            return $config['invert_sign'] ? -$value : $value;
        }

        [$debitRaw, $creditRaw] = $cells;
        $debit = $this->isBlank($debitRaw) ? 0.0 : $this->parseAmount($debitRaw, $decimal);
        $credit = $this->isBlank($creditRaw) ? 0.0 : $this->parseAmount($creditRaw, $decimal);

        if ($debit === null || $credit === null) {
            $recordError("Fila {$rowNumber}: el cargo o el abono no es un número válido.");

            return null;
        }

        // Banks show debits either as positive numbers or already negative: the column defines the direction.
        $debit = abs($debit);
        $credit = abs($credit);

        if ($debit > 0 && $credit > 0) {
            $recordError("Fila {$rowNumber}: tiene cargo y abono a la vez.");

            return null;
        }

        return $credit - $debit;
    }

    private function cell(array $row, ?int $column)
    {
        if ($column === null) {
            return null;
        }

        return $row[$column - 1] ?? null;
    }

    private function isBlank($value): bool
    {
        if ($value === null) {
            return true;
        }
        if (is_string($value)) {
            return trim($value) === '';
        }

        return false;
    }

    private function display($value): string
    {
        $text = is_scalar($value) ? (string) $value : '';

        return mb_substr(trim($text), 0, 40);
    }

    private function cleanText($value, int $max): string
    {
        $text = is_scalar($value) ? (string) $value : '';
        $text = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $text);
        $text = trim(preg_replace('/\s+/u', ' ', (string) $text));

        return mb_substr($text, 0, $max);
    }

    private function readCsv(string $path, ?string $delimiter): array
    {
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw ValidationException::withMessages(['file' => ['No se pudo leer el archivo cargado.']]);
        }

        if (strncmp($contents, "\xEF\xBB\xBF", 3) === 0) {
            $contents = substr($contents, 3);
        }
        if (!mb_check_encoding($contents, 'UTF-8')) {
            // Spanish bank exports are frequently Windows-1252 / ISO-8859-1.
            $contents = mb_convert_encoding($contents, 'UTF-8', 'Windows-1252');
        }

        $delimiter = $delimiter !== null && $delimiter !== '' ? $delimiter : $this->detectDelimiter($contents);

        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $contents);
        rewind($stream);

        $rows = [];
        // Keep a hard ceiling on raw rows (data rows are limited again in parse()).
        $ceiling = $this->maxRows + 600;
        while (($cells = fgetcsv($stream, 0, $delimiter, '"', '\\')) !== false) {
            if ($cells === [null]) {
                $rows[] = [];
                continue;
            }
            $rows[] = array_map(function ($cell) {
                return $cell === null ? null : trim((string) $cell);
            }, $cells);

            if (count($rows) > $ceiling) {
                fclose($stream);
                throw ValidationException::withMessages([
                    'file' => ['El archivo supera el máximo de ' . $this->maxRows . ' movimientos por extracto.'],
                ]);
            }
        }
        fclose($stream);

        return $rows;
    }

    private function detectDelimiter(string $contents): string
    {
        $sample = '';
        foreach (preg_split('/\r\n|\r|\n/', $contents) as $line) {
            if (trim($line) !== '') {
                $sample .= $line . "\n";
                if (strlen($sample) > 4000) {
                    break;
                }
            }
        }

        $best = ',';
        $bestCount = 0;
        foreach (self::DELIMITERS as $candidate) {
            $count = substr_count($sample, $candidate);
            if ($count > $bestCount) {
                $best = $candidate;
                $bestCount = $count;
            }
        }

        return $best;
    }

    private function readSpreadsheet(string $path): array
    {
        $maxRow = $this->maxRows + self::HEADER_MARGIN;
        $maxColumns = max(1, $this->maxColumns);

        // Only cells inside the allowed window are loaded, so a stray far cell (e.g. XFD100000) can never make the
        // reader allocate a huge range. The filter remembers whether anything was offered outside the window.
        $filter = new class($maxRow + 1, $maxColumns + 1) implements \PhpOffice\PhpSpreadsheet\Reader\IReadFilter {
            public $lastRow;
            public $lastColumn;
            public $exceeded = false;

            public function __construct(int $lastRow, int $lastColumn)
            {
                $this->lastRow = $lastRow;
                $this->lastColumn = $lastColumn;
            }

            public function readCell($columnAddress, $row, $worksheetName = ''): bool
            {
                if ($row > $this->lastRow || \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($columnAddress) > $this->lastColumn) {
                    $this->exceeded = true;

                    return false;
                }

                return true;
            }
        };

        try {
            $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($path);
            $reader->setReadDataOnly(true);
            $reader->setReadFilter($filter);

            $names = method_exists($reader, 'listWorksheetNames') ? $reader->listWorksheetNames($path) : [];
            if (!empty($names)) {
                $reader->setLoadSheetsOnly([reset($names)]);
            }

            $spreadsheet = $reader->load($path);
            $sheet = $spreadsheet->getSheet(0);
            $highestRow = (int) $sheet->getHighestDataRow();
            $highestColumn = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($sheet->getHighestDataColumn());

            if ($filter->exceeded || $highestRow > $maxRow || $highestColumn > $maxColumns) {
                $spreadsheet->disconnectWorksheets();
                $this->failSpreadsheetTooLarge($maxColumns);
            }

            // Formulas are NEVER evaluated (user-controlled input): a formula cell yields its cached result, or an
            // error marker when the file has none, so the row is reported instead of silently misread.
            $rows = [];
            foreach ($sheet->getCoordinates(false) as $coordinate) {
                $cell = $sheet->getCell($coordinate);
                [$columnLetters, $rowNumber] = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::coordinateFromString($coordinate);
                $columnIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($columnLetters);

                if ($cell->isFormula()) {
                    $value = $cell->getOldCalculatedValue();
                    if ($value === null || $value === '') {
                        $value = '#N/A';
                    }
                } else {
                    $value = $cell->getValue();
                }
                if (is_object($value)) {
                    $value = method_exists($value, '__toString') ? (string) $value : null;
                }

                $rows[(int) $rowNumber - 1][$columnIndex - 1] = $value;
            }
            $spreadsheet->disconnectWorksheets();
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw ValidationException::withMessages(['file' => ['No se pudo leer el archivo de Excel.']]);
        }

        // Dense, 0-based rows/columns.
        $dense = [];
        for ($r = 0; $r < $highestRow; $r++) {
            $line = [];
            for ($c = 0; $c < $highestColumn; $c++) {
                $cellValue = $rows[$r][$c] ?? null;
                $line[] = is_string($cellValue) ? trim($cellValue) : $cellValue;
            }
            $dense[] = $line;
        }

        return $dense;
    }

    private function failSpreadsheetTooLarge(int $maxColumns): void
    {
        throw ValidationException::withMessages([
            'file' => [
                'El archivo de Excel es demasiado grande: supera el máximo de ' . $this->maxRows . ' movimientos o de '
                . $maxColumns . ' columnas. Elimine las celdas sobrantes (filas o columnas vacías con formato) y vuelva a cargarlo.',
            ],
        ]);
    }

    private function configValue(string $key, $default)
    {
        try {
            return function_exists('config') && \Illuminate\Container\Container::getInstance()->bound('config')
                ? config($key, $default)
                : $default;
        } catch (\Throwable $e) {
            return $default;
        }
    }
}
