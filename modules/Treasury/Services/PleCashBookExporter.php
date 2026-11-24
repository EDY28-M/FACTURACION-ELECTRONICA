<?php

namespace Modules\Treasury\Services;

use App\Models\Tenant\BankAccount;
use App\Models\Tenant\DocumentPayment;
use App\Models\Tenant\PurchasePayment;
use App\Models\Tenant\SaleNotePayment;
use Carbon\Carbon;
use Modules\Expense\Models\ExpensePayment;
use Modules\Finance\Models\IncomePayment;
use Modules\Pos\Models\CashTransaction;
use Modules\Sale\Models\ContractPayment;
use Modules\Sale\Models\QuotationPayment;
use Modules\Sale\Models\TechnicalServicePayment;
use Modules\Treasury\Models\TreasuryMovement;
use Modules\Treasury\Support\PleExportException;
use Modules\Treasury\Support\PleFormatter;

/**
 * PLE TXT files of the Libro Caja y Bancos for one month (RS 286-2009/SUNAT Anexo 2):
 * - 010100 (Formato 1.1, 19 fields): movements of the cash in PEN, one file with M = 1.
 * - 010200 (Formato 1.2, 15 fields): movements of the bank accounts, one file per currency (M = 1 PEN, M = 2 USD).
 *
 * Design decisions (see docs/plans/tesoreria-investigacion-sunat.md "PLE layout confirmado"):
 * - Data comes from CashBookReport, so the scoping rules are exactly the ones of the on-screen Libro Caja y Bancos.
 * - CUO is stable per source row: a 2-letter source code + the source id padded to 10 digits (e.g. DP0000000123).
 *   It never depends on the report order, so re-running the export after new movements keeps every CUO.
 * - Correlativo is "M" + a sequence per CUO. Every source row is its own asiento, hence "M1". No opening ("A") or
 *   closing ("C") asientos are generated: the files only carry the period movements.
 * - Nothing is guessed: a missing Tabla 3 code (bank account) or Tabla 1 code (payment method) blocks the export.
 * - The 1.1 file always exists (I = 0 and empty when there is nothing); the 1.2 PEN file too. A USD 1.2 file is
 *   produced only when USD accounts have movements.
 * Never calls auth(); callers pass an explicit $scope.
 */
class PleCashBookExporter
{
    public const SOURCE_CODES = [
        DocumentPayment::class => 'DP',
        SaleNotePayment::class => 'SN',
        QuotationPayment::class => 'QT',
        ContractPayment::class => 'CO',
        IncomePayment::class => 'IN',
        TechnicalServicePayment::class => 'TS',
        PurchasePayment::class => 'PC',
        ExpensePayment::class => 'EX',
        CashTransaction::class => 'CT',
        TreasuryMovement::class => 'TM',
    ];

    /** Tabla 10 types whose serie/number follow the strict electronic rules; any other type is reported as "00 Otros". */
    private const STRICT_DOCUMENT_TYPES = ['01', '03', '07', '08'];

    private const MAX_LISTED_ITEMS = 25;

    private $report;

    public function __construct(CashBookReport $report)
    {
        $this->report = $report;
    }

    /**
     * @param string               $period  YYYY-MM
     * @param array<string, mixed> $scope   is_admin, user_id, establishment_id (same shape as CashBookReport)
     * @param array<string, mixed> $filters optional establishment_id (administrators only, applies to the cash file)
     *
     * @return array{
     *     period: string,
     *     ruc: string,
     *     files: array<int, array{key: string, format: string, currency: string, filename: string, has_info: bool, line_count: int, content: string}>,
     *     blockers: array<int, array{code: string, message: string, items: array<int, string>}>,
     *     warnings: array<int, string>
     * }
     */
    public function build(string $period, array $scope, array $filters = []): array
    {
        if (!preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $period, $m)) {
            throw new \InvalidArgumentException('El periodo debe tener el formato AAAA-MM.');
        }

        $start = Carbon::createFromFormat('Y-m-d', $period . '-01')->startOfDay();
        $dateStart = $start->format('Y-m-d');
        $dateEnd = $start->copy()->endOfMonth()->format('Y-m-d');
        $periodField = $m[1] . $m[2] . '00';

        $blockers = [];
        $warnings = [];

        $cashFilters = ['date_start' => $dateStart, 'date_end' => $dateEnd];
        if (!empty($scope['is_admin']) && !empty($filters['establishment_id'])) {
            $cashFilters['establishment_id'] = (int) $filters['establishment_id'];
        }
        $cashReport = $this->report->buildFormat11($cashFilters, $scope);

        $ruc = (string) ($cashReport['meta']['company_ruc'] ?? '');
        if (!preg_match('/^\d{11}$/', $ruc)) {
            $blockers[] = $this->blocker('invalid_ruc', 'La empresa no tiene un RUC válido de 11 dígitos; configúrelo antes de generar el PLE.', []);
        }

        $cashLines = $this->buildFormat11Lines($cashReport['rows'], $periodField);

        $bankByCurrency = ['PEN' => [], 'USD' => []];
        $missingEntity = [];
        $missingPayment = [];
        $unsupportedCurrency = [];
        $skippedZero = 0;

        foreach ($this->scopedBankAccounts($scope) as $account) {
            $report = $this->report->buildFormat12([
                'date_start' => $dateStart,
                'date_end' => $dateEnd,
                'bank_account_id' => (int) $account->id,
            ], $scope);
            if (empty($report['rows'])) {
                continue;
            }

            $label = $this->accountLabel($account);
            $currency = strtoupper((string) ($account->currency_type_id ?: 'PEN'));
            if (!isset($bankByCurrency[$currency])) {
                $unsupportedCurrency[] = $label . ' (' . $currency . ')';
                continue;
            }

            $entityCode = trim((string) ($account->sunat_financial_entity_code ?? ''));
            $accountNumber = PleFormatter::alnum((string) $account->number, 30);
            $accountReady = preg_match('/^\d{2}$/', $entityCode) && $accountNumber !== '';
            if (!$accountReady) {
                $missingEntity[] = $label;
            }

            foreach ($report['rows'] as $row) {
                if (!empty($row['missing_payment_code'])) {
                    $missingPayment[] = $row['date'] . ' ' . $this->trimLabel((string) $row['description']) . ' - ' . $label;
                    continue;
                }
                if ($this->isZeroAfterNetting((float) $row['debit'], (float) $row['credit'])) {
                    $skippedZero++;
                    continue;
                }
                if ($accountReady) {
                    $bankByCurrency[$currency][] = ['row' => $row, 'entity' => $entityCode, 'account' => $accountNumber];
                }
            }
        }

        if (!empty($missingEntity)) {
            $blockers[] = $this->blocker(
                'missing_financial_entity_code',
                'Cuentas bancarias con movimientos sin código de entidad financiera (Tabla 3 del PLE). Edite la cuenta e indique el código.',
                $missingEntity,
                ['1.2']
            );
        }
        if (!empty($missingPayment)) {
            $blockers[] = $this->blocker(
                'missing_payment_code',
                'Operaciones bancarias sin código de medio de pago (Tabla 1 del PLE). Complete la evidencia de pago de cada una.',
                $missingPayment,
                ['1.2']
            );
        }
        if (!empty($unsupportedCurrency)) {
            $blockers[] = $this->blocker(
                'unsupported_currency',
                'El PLE solo admite cuentas en soles (PEN) o dólares (USD).',
                $unsupportedCurrency,
                ['1.2']
            );
        }
        if ($skippedZero > 0) {
            $warnings[] = $skippedZero . ' operación(es) bancaria(s) con importe neto 0.00 (sin importe, o con deudor y acreedor iguales) se omitieron porque el Formato 1.2 no admite deudor y acreedor en cero.';
        }
        $warnings[] = 'Los códigos de las Tablas 1 y 3 deben validarse con el validador oficial del PLE antes de presentar el libro.';

        $files = [$this->makeFile('1.1', '010100', 'PEN', $ruc, $period, $cashLines)];
        $files[] = $this->makeFile('1.2', '010200', 'PEN', $ruc, $period, $this->buildFormat12Lines($bankByCurrency['PEN'], $periodField));
        $usdLines = $this->buildFormat12Lines($bankByCurrency['USD'], $periodField);
        if (!empty($usdLines)) {
            $files[] = $this->makeFile('1.2', '010200', 'USD', $ruc, $period, $usdLines);
        }

        return [
            'period' => $period,
            'ruc' => $ruc,
            'files' => $files,
            'blockers' => $blockers,
            'warnings' => $warnings,
        ];
    }

    /**
     * Same as build() but refuses (PleExportException) when anything would have to be guessed.
     */
    public function buildOrFail(string $period, array $scope, array $filters = [], ?string $format = null): array
    {
        $result = $this->build($period, $scope, $filters);

        $blockers = array_values(array_filter($result['blockers'], function ($blocker) use ($format) {
            return $format === null || in_array($format, $blocker['formats'] ?? ['1.1', '1.2'], true);
        }));
        if (!empty($blockers)) {
            throw new PleExportException($blockers);
        }

        return $result;
    }

    /** True when debit and credit are both empty or cancel each other once netted (Formato 1.2 needs a non-zero side). */
    private function isZeroAfterNetting(float $debit, float $credit): bool
    {
        return round(max(0.0, $debit) - max(0.0, $credit), 2) == 0.0;
    }

    public static function fileName(string $ruc, string $period, string $bookCode, bool $hasInfo, string $currency): string
    {
        return sprintf(
            'LE%s%s%s00%s00%d%d%d1.TXT',
            $ruc,
            substr($period, 0, 4),
            substr($period, 5, 2),
            $bookCode,
            1,
            $hasInfo ? 1 : 0,
            $currency === 'USD' ? 2 : 1
        );
    }

    public static function cuo(?string $sourceType, $sourceId): string
    {
        $code = self::SOURCE_CODES[(string) $sourceType] ?? 'OT';

        return $code . str_pad((string) (int) $sourceId, 10, '0', STR_PAD_LEFT);
    }

    // ---------------------------------------------------------------------------------------------------------
    // Line builders
    // ---------------------------------------------------------------------------------------------------------

    /**
     * @param array<int, array<string, mixed>> $rows rows of CashBookReport::buildFormat11
     *
     * @return array<int, string>
     */
    public function buildFormat11Lines(array $rows, string $periodField): array
    {
        $sequence = [];
        $lines = [];
        foreach ($rows as $row) {
            $cuo = self::cuo($row['source_type'] ?? null, $row['source_id'] ?? null);
            $sequence[$cuo] = ($sequence[$cuo] ?? 0) + 1;
            $lines[] = $this->formatLine11($row, $periodField, $cuo, 'M' . $sequence[$cuo]);
        }

        return $lines;
    }

    /**
     * @param array<int, array{row: array<string, mixed>, entity: string, account: string}> $entries
     *
     * @return array<int, string>
     */
    public function buildFormat12Lines(array $entries, string $periodField): array
    {
        $sequence = [];
        $lines = [];
        foreach ($entries as $entry) {
            // Never emit a line whose debe and haber net to zero (callers count them as skipped).
            if ($this->isZeroAfterNetting((float) $entry['row']['debit'], (float) $entry['row']['credit'])) {
                continue;
            }
            $cuo = self::cuo($entry['row']['source_type'] ?? null, $entry['row']['source_id'] ?? null);
            $sequence[$cuo] = ($sequence[$cuo] ?? 0) + 1;
            $lines[] = $this->formatLine12($entry['row'], $entry['entity'], $entry['account'], $periodField, $cuo, 'M' . $sequence[$cuo]);
        }

        return $lines;
    }

    /**
     * Formato 1.1, 19 fields. Fields 5, 6, 12, 15 and 18 are optional and left empty; field 11 repeats the operation
     * date; field 19 is always 1 (operation of the reported period).
     *
     * @param array<string, mixed> $row
     */
    public function formatLine11(array $row, string $periodField, string $cuo, string $correlative): string
    {
        $ascii = $this->asciiOnly();
        [$type, $series, $number] = $this->resolveDocument($row, $cuo);
        $date = PleFormatter::date((string) $row['date']);
        $debit = (float) $row['debit'];
        $credit = (float) $row['credit'];
        $gloss = PleFormatter::text((string) ($row['description'] ?? ''), 200, $ascii);

        return $this->join([
            $periodField,
            $cuo,
            $correlative,
            PleFormatter::alnum((string) config('treasury.ple.default_cash_account', '101'), 24) ?: '101',
            '',
            '',
            'PEN',
            $type,
            $series,
            $number,
            $date,
            '',
            $date,
            $gloss !== '' ? $gloss : 'Movimiento de efectivo',
            '',
            PleFormatter::amount($debit),
            PleFormatter::amount($credit),
            '',
            '1',
        ]);
    }

    /**
     * Formato 1.2, 15 fields. Debe and haber are mutually exclusive (a row never carries both).
     *
     * @param array<string, mixed> $row
     */
    public function formatLine12(array $row, string $entityCode, string $accountNumber, string $periodField, string $cuo, string $correlative): string
    {
        $ascii = $this->asciiOnly();
        [$docType, $docNumber] = PleFormatter::identityDocument($row['counterparty_doc_type'] ?? null, $row['counterparty_doc_number'] ?? null);
        $name = PleFormatter::text((string) ($row['counterparty'] ?? ''), 200, $ascii);
        if ($name === '' || $name === '-') {
            $name = PleFormatter::text((string) config('treasury.ple.unknown_counterparty_name', 'varios'), 200, $ascii);
        }
        $transaction = PleFormatter::alnum((string) ($row['transaction_number'] ?? ''), 20);
        $description = PleFormatter::text((string) ($row['description'] ?? ''), 200, $ascii);
        $debit = (float) $row['debit'];
        $credit = (float) $row['credit'];
        // Exclusive fields: the report already yields one side per row; keep the larger one if both were ever set.
        if ($debit > 0 && $credit > 0) {
            if ($debit >= $credit) {
                $debit = round($debit - $credit, 2);
                $credit = 0.0;
            } else {
                $credit = round($credit - $debit, 2);
                $debit = 0.0;
            }
        }

        return $this->join([
            $periodField,
            $cuo,
            $correlative,
            $entityCode,
            $accountNumber,
            PleFormatter::date((string) $row['date']),
            (string) $row['sunat_payment_code'],
            $description !== '' ? $description : 'Operacion bancaria',
            $docType,
            $docNumber,
            $name,
            $transaction !== '' ? $transaction : '-',
            PleFormatter::amount($debit),
            PleFormatter::amount($credit),
            '1',
        ]);
    }

    // ---------------------------------------------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------------------------------------------

    /**
     * Tabla 10 type, serie and number of the supporting document of a cash row (fields 8 to 10 of Formato 1.1).
     * Factura/boleta/notas keep their electronic serie/number only when they satisfy the official rules; everything
     * else is "00 Otros" (serie optional, number mandatory: falls back to the CUO so it is never empty).
     *
     * @param array<string, mixed> $row
     *
     * @return array{0: string, 1: string, 2: string}
     */
    private function resolveDocument(array $row, string $cuo): array
    {
        $type = (string) ($row['doc_type_code'] ?? '');
        $series = PleFormatter::alnum((string) ($row['doc_series'] ?? ''), 20);
        $number = PleFormatter::alnum((string) ($row['doc_number'] ?? ''), 20);

        if (in_array($type, self::STRICT_DOCUMENT_TYPES, true)
            && preg_match('/^[A-Za-z0-9]{4}$/', $series)
            && preg_match('/^\d{1,8}$/', $number)
            && (int) $number > 0
        ) {
            return [$type, $series, $number];
        }

        if ($series === '' && $number === '' && !empty($row['document_number'])) {
            [$labelSeries, $labelNumber] = PleFormatter::splitDocumentLabel((string) $row['document_number']);
            $series = PleFormatter::alnum($labelSeries, 20);
            $number = PleFormatter::alnum($labelNumber, 20);
        }

        return ['00', $series, $number !== '' ? $number : $cuo];
    }

    /**
     * @param array<int, string> $fields
     */
    private function join(array $fields): string
    {
        return implode('|', $fields) . (config('treasury.ple.trailing_pipe', false) ? '|' : '');
    }

    private function asciiOnly(): bool
    {
        return (bool) config('treasury.ple.ascii_only', true);
    }

    /**
     * @param array<int, string> $lines
     */
    private function makeFile(string $format, string $bookCode, string $currency, string $ruc, string $period, array $lines): array
    {
        $hasInfo = !empty($lines);
        $ending = (string) config('treasury.ple.line_ending', "\r\n");

        return [
            'key' => $format . '-' . $currency,
            'format' => $format,
            'currency' => $currency,
            'filename' => self::fileName($ruc, $period, $bookCode, $hasInfo, $currency),
            'has_info' => $hasInfo,
            'line_count' => count($lines),
            // Every record, the last one included, ends with the line terminator. Empty period = empty file.
            'content' => $hasInfo ? implode($ending, $lines) . $ending : '',
        ];
    }

    /**
     * Same bank accounts the on-screen cash book offers: administrators see all, others their establishment plus the
     * shared ones (row-level scoping of shared accounts is applied by CashBookReport).
     *
     * @return \Illuminate\Support\Collection<int, BankAccount>
     */
    private function scopedBankAccounts(array $scope)
    {
        $query = BankAccount::query()->orderBy('id');
        if (empty($scope['is_admin'])) {
            $estId = (int) ($scope['establishment_id'] ?? 0);
            $query->where(function ($q) use ($estId) {
                $q->whereNull('establishment_id')->orWhere('establishment_id', $estId);
            });
        }

        return $query->get();
    }

    private function accountLabel(BankAccount $account): string
    {
        return trim(($account->description ?: 'Cuenta') . ' (' . ($account->number ?: 'sin número') . ')');
    }

    private function trimLabel(string $text): string
    {
        return mb_strlen($text) > 60 ? mb_substr($text, 0, 57) . '...' : $text;
    }

    /**
     * @param array<int, string> $items
     */
    private function blocker(string $code, string $message, array $items, array $formats = ['1.1', '1.2']): array
    {
        if (count($items) > self::MAX_LISTED_ITEMS) {
            $extra = count($items) - self::MAX_LISTED_ITEMS;
            $items = array_slice($items, 0, self::MAX_LISTED_ITEMS);
            $items[] = 'y ' . $extra . ' más';
        }

        return ['code' => $code, 'message' => $message, 'items' => array_values($items), 'formats' => $formats];
    }
}
