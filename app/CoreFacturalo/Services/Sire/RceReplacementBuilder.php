<?php

namespace App\CoreFacturalo\Services\Sire;

use App\CoreFacturalo\Helpers\Sunat\RucValidator;
use App\Models\Tenant\Company;
use App\Models\Tenant\Purchase;
use Carbon\Carbon;
use RuntimeException;

/**
 * Builds the SIRE "Reemplazo de la propuesta del RCE" flat file (Anexo 11, RS 040-2022/SUNAT)
 * from the tenant's own purchases. Read-only: nothing is written to the database.
 *
 * Verified against SUNAT sources (structure sheet "Estructura del Reemplazo RCE", SIRE Compras API manual v28/29):
 *  - 41 pipe-separated fields emitted (fields 42+ "CLU" are optional and omitted).
 *  - Fields 4 (CAR SUNAT) and 37 (CAR Orig) must be empty for a replacement; fields 38-41 must be shown empty.
 *  - Book code of the RCE is 080400 (manual: report file names LE{RUC}{AAAAMM}00080400...).
 *  - File name pattern LE{RUC}{AAAA}{MM}00 080400 02 {O}{I}{M} 2, zipped: the fixed parts mirror the RVIE
 *    manual and the "...080400" + "02" + 3 letters + "2" shape of the RCE report names.
 *
 * Marked "[unverified]" (best-effort PLE-style convention, not found in the sources):
 *  - Meaning of the O/I/M indicators (same as RVIE).
 *  - Credit notes are reported with negative amounts.
 *  - The register is kept in national currency (same rule as RS 112-2021 art. 6 e for the RVIE) -> converted to PEN.
 *  - Voided purchases (state 11) are excluded and warned about (the structure sheet has no zero-amount rule).
 */
class RceReplacementBuilder
{
    /** Number of fields emitted per line (fields 42+ "CLU" are omitted). */
    public const FIELDS = 41;

    /** Document types (catalog 10) reported by this slice. */
    public const DOCUMENT_TYPES = ['01', '02', '03', '07', '08', '12', '13', '14'];

    /** State of a voided purchase: excluded with a warning. */
    public const VOIDED_STATE = '11';

    /** Destination of the acquisition: DG = taxed, DGNG = taxed and non-taxed, DNG = non-taxed. */
    public const DESTINATIONS = ['DG', 'DGNG', 'DNG'];

    public const MAX_MESSAGES = SireSupport::MAX_MESSAGES;

    public static function validatePeriod(string $period, ?Carbon $now = null): array
    {
        return SireSupport::validatePeriod($period, $now);
    }

    /**
     * LE{RUC}{AAAA}{MM}00 080400 02 {O}{I}{M} 2  (+ extension).
     * O = operations indicator, I = content indicator, M = currency (1 PEN, 2 USD). [unverified] O/I/M meaning.
     */
    public static function filename(string $ruc, int $year, int $month, bool $hasOperations, bool $hasContent, int $currency = 1, string $extension = 'txt'): string
    {
        return sprintf(
            'LE%s%04d%02d00080400%02d%d%d%d2.%s',
            $ruc,
            $year,
            $month,
            2,
            $hasOperations ? 1 : 0,
            $hasContent ? 1 : 0,
            $currency,
            $extension
        );
    }

    /**
     * Maps one normalized purchase to the 41 RCE fields.
     *
     * Expected keys of $doc: document_type_id, series, number, date_of_issue (Y-m-d), due_date (Y-m-d|null),
     * state_type_id, supplier (identity_document_type_id, number, name), currency_type_id, exchange_rate_sale,
     * sire_destination (DG|DGNG|DNG), total_taxed, total_igv, total_exonerated, total_unaffected, total_exportation,
     * total_isc, total_other_taxes, total, affected (date/type/series/number) for notes.
     *
     * @return string[] exactly self::FIELDS values
     */
    public static function mapRow(array $doc, string $ruc, string $companyName, string $period): array
    {
        $type = (string) $doc['document_type_id'];
        $currency = (string) ($doc['currency_type_id'] ?? 'PEN');
        $rate = $currency === 'PEN' ? 1.0 : max((float) ($doc['exchange_rate_sale'] ?? 0), 0.0);

        // Non-taxed acquisition value (field 21) = exonerated + unaffected (+ exportation, which purchases do not
        // use). total_free (free transfers) is not part of the totals and is never reported.
        $doc['total_ng'] = (float) ($doc['total_exonerated'] ?? 0) + (float) ($doc['total_unaffected'] ?? 0) + (float) ($doc['total_exportation'] ?? 0);

        $conv = SireSupport::convertToPen($doc, ['total_taxed', 'total_igv', 'total_ng', 'total_isc', 'total_other_taxes'], $rate, 'total_taxed');

        $amounts = [];
        foreach ($conv as $key => $value) {
            // Credit notes carry negative amounts. [unverified - PLE convention]
            if ($type === '07') {
                $value = -abs($value);
            }
            $amounts[$key] = SireSupport::amount($value);
        }

        // Fields 15/16 = DG, 17/18 = DGNG, 19/20 = DNG (base, IGV).
        $destination = (string) ($doc['sire_destination'] ?? 'DG');
        $zero = '0.00';
        $bases = [$zero, $zero, $zero, $zero, $zero, $zero];
        $offset = ['DG' => 0, 'DGNG' => 2, 'DNG' => 4][$destination] ?? 0;
        $bases[$offset] = $amounts['total_taxed'];
        $bases[$offset + 1] = $amounts['total_igv'];

        [$idType, $idNumber, $name] = self::supplierFields($doc);

        $isNote = in_array($type, ['07', '08'], true);
        $affected = $isNote ? ($doc['affected'][0] ?? []) : [];

        return array_merge([
            $ruc,                                                        // 1
            SireSupport::text($companyName, 1500),                       // 2
            $period,                                                     // 3
            '',                                                          // 4 CAR SUNAT: empty for a replacement
            SireSupport::date($doc['date_of_issue']),                    // 5
            SireSupport::date($doc['due_date'] ?? null),                 // 6
            $type,                                                       // 7
            SireSupport::text((string) $doc['series'], 20),              // 8
            '',                                                          // 9 year: only for DAM/DSI (not supported)
            SireSupport::text((string) $doc['number'], 20),              // 10 [unverified] no zero padding
            '',                                                          // 11 range end: each document is reported individually
            $idType,                                                     // 12
            $idNumber,                                                   // 13
            $name,                                                       // 14
        ], $bases, [
            $amounts['total_ng'],                                        // 21
            $amounts['total_isc'],                                       // 22
            '0.00',                                                      // 23 ICBPER (purchases do not store it)
            $amounts['total_other_taxes'],                               // 24
            $amounts['total'],                                           // 25
            $currency,                                                   // 26
            number_format($currency === 'PEN' ? 1 : $rate, 3, '.', ''),  // 27
            SireSupport::date($affected['date'] ?? null),                // 28
            SireSupport::text((string) ($affected['type'] ?? '')),       // 29
            SireSupport::text((string) ($affected['series'] ?? ''), 20), // 30
            '',                                                          // 31 DAM/DSI code (not supported)
            SireSupport::text((string) ($affected['number'] ?? ''), 20), // 32
            '',                                                          // 33 goods/services classification (not stored)
            '',                                                          // 34 operators/participants project id
            '',                                                          // 35 participation percentage
            '',                                                          // 36 IMB (not stored)
            '',                                                          // 37 CAR Orig: empty for a replacement
            '', '', '', '',                                              // 38-41 loaded by SUNAT from the proposal: empty
        ]);
    }

    /**
     * Builds the file for a period. When $txtPath is given the lines are streamed to that file (download);
     * otherwise only counters/totals are kept (preview). Never holds all lines in memory.
     */
    public function build(int $year, int $month, ?string $txtPath = null): array
    {
        $company = Company::first();
        if (!$company || !preg_match('/^\d{11}$/', (string) $company->number)) {
            throw new RuntimeException('La empresa no tiene un RUC valido configurado.');
        }

        $start = Carbon::create($year, $month, 1)->startOfMonth();
        $end = $start->copy()->endOfMonth();
        $period = sprintf('%04d%02d', $year, $month);

        $handle = $txtPath !== null ? fopen($txtPath, 'wb') : null;
        $state = self::newState();

        // setEagerLoads([]) avoids the heavy default $with of the model (items, payments, ...).
        Purchase::query()->setEagerLoads([])
            ->whereBetween('date_of_issue', [$start->toDateString(), $end->toDateString()])
            ->where('soap_type_id', $company->soap_type_id)
            ->orderBy('date_of_issue')->orderBy('document_type_id')->orderBy('series')->orderBy('number')->orderBy('id')
            ->chunk(500, function ($chunk) use (&$state, $company, $period, $handle) {
                foreach ($chunk as $purchase) {
                    self::consume($state, self::normalize($purchase), $company->number, $company->name, $period, $handle);
                }
            });

        if ($handle) {
            fclose($handle);
        }

        $result = self::finish($state, $company->number, $year, $month);
        $result['demo'] = (string) $company->soap_type_id !== '02';
        if ($result['demo']) {
            $result['warnings'][] = 'La empresa no esta en ambiente de produccion; los comprobantes de prueba no son validos para SIRE.';
        }
        $result['txt_path'] = $txtPath;

        return $result;
    }

    /**
     * Assembly from normalized purchases (unit-testable, no database). Returns the TXT in 'txt'.
     */
    public static function buildFromDocuments(array $docs, string $ruc, string $companyName, int $year, int $month): array
    {
        $period = sprintf('%04d%02d', $year, $month);
        $state = self::newState();
        $handle = fopen('php://memory', 'w+b');

        foreach ($docs as $doc) {
            self::consume($state, $doc, $ruc, $companyName, $period, $handle);
        }

        rewind($handle);
        $txt = (string) stream_get_contents($handle);
        fclose($handle);

        $result = self::finish($state, $ruc, $year, $month);
        $result['txt'] = $txt;

        return $result;
    }

    /**
     * Blocking problems that make a purchase impossible to report correctly.
     *
     * @return string[]
     */
    public static function blockingErrors(array $doc): array
    {
        $label = ($doc['series'] ?? '').'-'.($doc['number'] ?? '');
        $type = (string) $doc['document_type_id'];
        $errors = [];

        $currency = (string) ($doc['currency_type_id'] ?? 'PEN');
        if ($currency !== 'PEN' && (float) ($doc['exchange_rate_sale'] ?? 0) <= 0) {
            $errors[] = $label.': moneda extranjera sin tipo de cambio valido.';
        }

        if (!in_array((string) ($doc['sire_destination'] ?? 'DG'), self::DESTINATIONS, true)) {
            $errors[] = $label.': destino de la adquisicion invalido (usa DG, DGNG o DNG).';
        }

        if (in_array($type, ['07', '08'], true)) {
            $affected = $doc['affected'][0] ?? null;
            if (!$affected || ($affected['type'] ?? '') === '' || ($affected['series'] ?? '') === '' || ($affected['number'] ?? '') === '' || empty($affected['date'])) {
                $errors[] = $label.': la nota no tiene documento modificado (campos 28-30 y 32 obligatorios).';
            }
        }

        if ($type === '01') {
            $supplier = $doc['supplier'] ?? [];
            if ((string) ($supplier['identity_document_type_id'] ?? '') !== RucValidator::IDENTITY_DOCUMENT_TYPE_ID
                || !RucValidator::isValid($supplier['number'] ?? '')) {
                $errors[] = $label.': la factura requiere un proveedor con RUC valido.';
            }
        }

        return $errors;
    }

    private static function newState(): array
    {
        return [
            'rows' => 0, 'by_type' => [], 'taxed' => 0.0, 'igv' => 0.0, 'ng' => 0.0, 'total' => 0.0,
            'warnings' => [], 'warn_count' => 0, 'errors' => [], 'err_count' => 0,
            'voided' => 0, 'voided_ids' => [], 'converted' => 0, 'unsupported' => [], 'unsupported_count' => 0,
        ];
    }

    private static function consume(array &$state, array $doc, string $ruc, string $companyName, string $period, $handle): void
    {
        $label = ($doc['series'] ?? '').'-'.($doc['number'] ?? '');

        if (($doc['state_type_id'] ?? '') === self::VOIDED_STATE) {
            $state['voided']++;
            if (count($state['voided_ids']) < 8) {
                $state['voided_ids'][] = $label;
            }

            return;
        }

        if (!in_array((string) $doc['document_type_id'], self::DOCUMENT_TYPES, true)) {
            $state['unsupported_count']++;
            if (count($state['unsupported']) < 8) {
                $state['unsupported'][] = $label.' ('.$doc['document_type_id'].')';
            }

            return;
        }

        $errors = self::blockingErrors($doc);
        foreach ($errors as $error) {
            SireSupport::addMessage($state, 'errors', $error);
        }
        if ($errors) {
            return;
        }

        $fields = self::mapRow($doc, $ruc, $companyName, $period);
        if ($handle) {
            fwrite($handle, ($state['rows'] > 0 ? "\n" : '').implode('|', $fields));
        }
        $state['rows']++;

        $type = $fields[6];
        $bucket = &$state['by_type'][$type];
        $bucket = $bucket ?? ['type' => $type, 'count' => 0, 'taxed' => 0.0, 'igv' => 0.0, 'ng' => 0.0, 'total' => 0.0];
        $bucket['count']++;

        $taxed = (float) $fields[14] + (float) $fields[16] + (float) $fields[18];
        $igv = (float) $fields[15] + (float) $fields[17] + (float) $fields[19];
        $ng = (float) $fields[20];
        $bucket['taxed'] += $taxed;
        $bucket['igv'] += $igv;
        $bucket['ng'] += $ng;
        $bucket['total'] += (float) $fields[24];
        $state['taxed'] += $taxed;
        $state['igv'] += $igv;
        $state['ng'] += $ng;
        $state['total'] += (float) $fields[24];
        unset($bucket);

        // Fields 15..24 must add up to field 25.
        $sum = $taxed + $igv + $ng + (float) $fields[21] + (float) $fields[22] + (float) $fields[23];
        if (abs($sum - (float) $fields[24]) > 0.005) {
            SireSupport::addMessage($state, 'warnings', sprintf('%s: los importes no cuadran con el total (diferencia %s); revisar cargos, descuentos o percepciones.', $label, number_format($sum - (float) $fields[24], 2, '.', '')));
        }
        if ($fields[25] !== 'PEN') {
            $state['converted']++;
        }
    }

    private static function finish(array $state, string $ruc, int $year, int $month): array
    {
        foreach ($state['by_type'] as &$bucket) {
            foreach (['taxed', 'igv', 'ng', 'total'] as $k) {
                $bucket[$k] = round($bucket[$k], 2);
            }
        }
        unset($bucket);
        ksort($state['by_type']);

        if ($state['voided']) {
            SireSupport::addMessage($state, 'warnings', sprintf('%d compra(s) anulada(s) no se incluyen: %s', $state['voided'], SireSupport::preview($state['voided_ids'], 8, $state['voided'])));
        }
        if ($state['unsupported_count']) {
            SireSupport::addMessage($state, 'warnings', sprintf('%d compra(s) con tipo de comprobante no soportado (DAM/DSI u otros) no se incluyen: %s', $state['unsupported_count'], SireSupport::preview($state['unsupported'], 8, $state['unsupported_count'])));
        }
        if ($state['converted']) {
            SireSupport::addMessage($state, 'warnings', sprintf('%d compra(s) en moneda extranjera se informan convertidas a soles con su tipo de cambio.', $state['converted']));
        }

        $hasRows = $state['rows'] > 0;
        $filename = self::filename($ruc, $year, $month, $hasRows, $hasRows);

        return [
            'filename' => $filename,
            'zip_filename' => preg_replace('/\.txt$/', '.zip', $filename),
            'rows_count' => $state['rows'],
            'totals' => [
                'by_type' => $state['by_type'],
                'taxed' => round($state['taxed'], 2),
                'igv' => round($state['igv'], 2),
                'ng' => round($state['ng'], 2),
                'total' => round($state['total'], 2),
            ],
            'warnings' => SireSupport::capList($state['warnings'], $state['warn_count']),
            'warnings_count' => $state['warn_count'],
            'errors' => SireSupport::capList($state['errors'], $state['err_count']),
            'errors_count' => $state['err_count'],
        ];
    }

    /** Creates the ZIP in a temporary file from the TXT file and returns its path (caller deletes both). */
    public static function zipFile(string $filename, string $txtPath): string
    {
        return SireSupport::zipFile($filename, $txtPath, 'rce');
    }

    /** Converts an Eloquent purchase to the array consumed by mapRow(). */
    public static function normalize(Purchase $purchase): array
    {
        $supplier = $purchase->supplier;
        $affected = [];

        if (in_array($purchase->document_type_id, ['07', '08'], true) && ($purchase->affected_series || $purchase->affected_number || $purchase->affected_document_type_id)) {
            $affected[] = [
                'date' => $purchase->affected_date_of_issue ? Carbon::parse($purchase->affected_date_of_issue)->format('Y-m-d') : null,
                'type' => (string) $purchase->affected_document_type_id,
                'series' => (string) $purchase->affected_series,
                'number' => (string) $purchase->affected_number,
            ];
        }

        return [
            'document_type_id' => $purchase->document_type_id,
            'series' => $purchase->series,
            'number' => $purchase->number,
            'date_of_issue' => $purchase->date_of_issue->format('Y-m-d'),
            'due_date' => $purchase->date_of_due ? $purchase->date_of_due->format('Y-m-d') : null,
            'state_type_id' => $purchase->state_type_id,
            'supplier' => [
                'identity_document_type_id' => $supplier->identity_document_type_id ?? '',
                'number' => $supplier->number ?? '',
                'name' => $supplier->name ?? '',
            ],
            'currency_type_id' => $purchase->currency_type_id,
            'exchange_rate_sale' => $purchase->exchange_rate_sale,
            'sire_destination' => $purchase->sire_destination ?: 'DG',
            'total_taxed' => $purchase->total_taxed,
            'total_igv' => $purchase->total_igv,
            'total_exonerated' => $purchase->total_exonerated,
            'total_unaffected' => $purchase->total_unaffected,
            'total_exportation' => $purchase->total_exportation,
            'total_isc' => $purchase->total_isc,
            'total_other_taxes' => $purchase->total_other_taxes,
            'total' => $purchase->total,
            'affected' => $affected,
        ];
    }

    private static function supplierFields(array $doc): array
    {
        $supplier = $doc['supplier'] ?? [];
        $idType = (string) ($supplier['identity_document_type_id'] ?? '');
        $number = trim((string) ($supplier['number'] ?? ''));
        $name = SireSupport::text((string) ($supplier['name'] ?? ''), 1500);

        if ($idType === '' || $idType === '0' || $number === '') {
            return ['', '', $name];
        }

        return [SireSupport::text($idType, 1), SireSupport::text($number, 15), $name];
    }
}
