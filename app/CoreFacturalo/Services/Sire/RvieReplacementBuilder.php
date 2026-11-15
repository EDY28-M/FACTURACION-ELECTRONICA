<?php

namespace App\CoreFacturalo\Services\Sire;

use App\Models\Tenant\Company;
use App\Models\Tenant\Document;
use Carbon\Carbon;
use InvalidArgumentException;
use RuntimeException;
use ZipArchive;

/**
 * Builds the SIRE "Reemplazo de la propuesta del RVIE" flat file (Anexo 3, RS 112-2021/SUNAT)
 * from the tenant's own sales documents. Read-only: nothing is written to the database.
 *
 * Verified against SUNAT sources (structure sheet, RS 112-2021 and the SIRE Ventas API manual v32):
 *  - 33 pipe-separated fields, field 4 (CAR SUNAT) empty for a replacement.
 *  - Electronic documents with a communicated void ("baja") are recorded with ZERO amounts (Anexo 3, note 3).
 *  - The register is kept in national currency (RS 112-2021, art. 6 e) -> amounts are converted to PEN.
 *  - File name: LE{RUC}{AAAAMM}00 + 140400 + 02 + {O}{I}{M} + 2 (manual v32, field nomArchivoImportacion), zipped.
 *  - Boletas may be reported one by one (field 10 empty); on boletas below S/ 700 the customer
 *    identification is optional and the value "0" is NOT accepted (fields 11-13 are left empty).
 *
 * Everything else is marked "[unverified]" and is a best-effort PLE-style convention.
 */
class RvieReplacementBuilder
{
    /** Number of fields emitted per line (fields 34-50 "CLU" are omitted). */
    public const FIELDS = 33;

    /** Document types reported by this slice. */
    public const DOCUMENT_TYPES = ['01', '03', '07', '08'];

    /** States reported with real amounts: 05 accepted, 07 observed. */
    public const REPORTED_STATES = ['05', '07'];

    /** State reported with zero amounts: 11 voided. */
    public const VOIDED_STATE = '11';

    /** States that are not reported but worth warning about: 01 registered, 03 sent, 13 pending void. */
    public const PENDING_STATES = ['01', '03', '13'];

    /** Threshold from which a boleta must identify the buyer. */
    public const BOLETA_ID_THRESHOLD = 700.00;

    /** Max entries kept per warnings/errors list (a final "y N mas" line is appended). */
    public const MAX_MESSAGES = 50;

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

    /**
     * LE{RUC}{AAAA}{MM}00 140400 02 {O}{I}{M} 2  (+ extension).
     * O = operations indicator, I = content indicator, M = currency (1 PEN, 2 USD).
     * [unverified] O/I/M meanings come from the PLE convention; the rest of the layout is in the SUNAT manual.
     */
    public static function filename(string $ruc, int $year, int $month, bool $hasOperations, bool $hasContent, int $currency = 1, string $extension = 'txt'): string
    {
        return sprintf(
            'LE%s%04d%02d00140400%02d%d%d%d2.%s',
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
     * Maps one normalized document to the 33 RVIE fields.
     *
     * Expected keys of $doc: document_type_id, series, number, date_of_issue (Y-m-d), due_date (Y-m-d|null),
     * state_type_id, customer (identity_document_type_id, number, name), currency_type_id, exchange_rate_sale,
     * total_exportation, total_taxed, total_igv, total_exonerated, total_unaffected, total_isc,
     * total_plastic_bag_taxes, total_other_taxes, total, affected (list of date/type/series/number) for notes.
     *
     * @return string[] exactly self::FIELDS values
     */
    public static function mapRow(array $doc, string $ruc, string $companyName, string $period): array
    {
        $type = (string) $doc['document_type_id'];
        $voided = ($doc['state_type_id'] ?? '') === self::VOIDED_STATE;
        $currency = (string) ($doc['currency_type_id'] ?? 'PEN');
        $rate = $currency === 'PEN' ? 1.0 : max((float) ($doc['exchange_rate_sale'] ?? 0), 0.0);

        // Field 14 = exportation, 15 = taxed base, 17 = IGV, 19 = exonerated, 20 = unaffected, 21 = ISC,
        // 24 = ICBPER, 25 = other taxes, 26 = total.
        // 15 is total_taxed as stored: global discounts that affect the base already reduce it, so field 16/18
        // (discounts) stay 0.00 to avoid counting them twice. total_free (free transfers) is NOT part of
        // the totals and is never reported. [unverified] treatment of global charges: only total_other_taxes goes to 25.
        $components = ['total_exportation', 'total_taxed', 'total_igv', 'total_exonerated', 'total_unaffected', 'total_isc', 'total_plastic_bag_taxes', 'total_other_taxes'];
        $conv = [];
        foreach ($components as $key) {
            $conv[$key] = round((float) ($doc[$key] ?? 0) * $rate, 2);
        }
        $total = round((float) ($doc['total'] ?? 0) * $rate, 2);

        // Converting each component to PEN can drift by a cent from the converted total. Absorb a small
        // residual in the taxed base (or the largest non-zero component) so fields 14+15+17+19+20+21+24+25 == 26.
        // Larger gaps are genuine inconsistencies (e.g. global charges) and are left for the reconciliation warning.
        $residual = round($total - array_sum($conv), 2);
        if ($residual != 0.0 && abs($residual) <= 0.05) {
            $target = $conv['total_taxed'] != 0.0 ? 'total_taxed' : null;
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

        $amounts = [];
        foreach ($conv as $key => $value) {
            // Credit notes carry negative amounts. [unverified - PLE convention]
            if ($type === '07') {
                $value = -abs($value);
            }
            if ($voided) {
                $value = 0.0;
            }
            $amounts[$key] = self::amount($value);
        }

        [$idType, $idNumber, $name] = self::customerFields($doc);

        $isNote = in_array($type, ['07', '08'], true);
        $affected = $isNote ? ($doc['affected'] ?? []) : [];

        return [
            $ruc,                                                        // 1
            self::text($companyName, 1500),                              // 2
            $period,                                                     // 3
            '',                                                          // 4 CAR SUNAT: empty for a replacement
            self::date($doc['date_of_issue']),                           // 5
            self::date($doc['due_date'] ?? null),                        // 6
            $type,                                                       // 7
            self::text((string) $doc['series'], 20),                    // 8
            self::text((string) $doc['number'], 20),                    // 9 [unverified] no zero padding
            '',                                                          // 10 each boleta is reported individually
            $idType,                                                     // 11
            $idNumber,                                                   // 12
            $name,                                                       // 13
            $amounts['total_exportation'],                               // 14
            $amounts['total_taxed'],                                     // 15
            '0.00',                                                      // 16
            $amounts['total_igv'],                                       // 17 IGV/IPM combined (also 10.5% reduced regime)
            '0.00',                                                      // 18
            $amounts['total_exonerated'],                                // 19
            $amounts['total_unaffected'],                                // 20
            $amounts['total_isc'],                                       // 21
            '0.00',                                                      // 22 IVAP not supported
            '0.00',                                                      // 23
            $amounts['total_plastic_bag_taxes'],                         // 24
            $amounts['total_other_taxes'],                               // 25
            $amounts['total'],                                           // 26
            $currency,                                                   // 27
            number_format($currency === 'PEN' ? 1 : $rate, 3, '.', ''),  // 28
            self::join(array_column($affected, 'date'), true),           // 29
            self::join(array_column($affected, 'type')),                 // 30
            self::join(array_column($affected, 'series')),               // 31
            self::join(array_column($affected, 'number')),               // 32
            '',                                                          // 33 operator attribution project id
        ];
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

        $base = Document::query()
            ->whereBetween('date_of_issue', [$start->toDateString(), $end->toDateString()])
            ->whereIn('document_type_id', self::DOCUMENT_TYPES)
            ->where('soap_type_id', $company->soap_type_id);

        $handle = $txtPath !== null ? fopen($txtPath, 'wb') : null;
        $state = self::newState();

        (clone $base)
            ->whereIn('state_type_id', array_merge(self::REPORTED_STATES, [self::VOIDED_STATE]))
            ->with(['note', 'fee'])
            ->orderBy('date_of_issue')->orderBy('document_type_id')->orderBy('series')->orderBy('number')
            ->chunk(500, function ($chunk) use (&$state, $company, $period, $handle) {
                foreach ($chunk as $document) {
                    self::consume($state, self::normalize($document), $company->number, $company->name, $period, $handle);
                }
            });

        if ($handle) {
            fclose($handle);
        }

        $result = self::finish($state, $company->number, $year, $month);

        $pending = (clone $base)->whereIn('state_type_id', self::PENDING_STATES)
            ->orderBy('date_of_issue')->get(['series', 'number'])
            ->map(function ($d) {
                return $d->series.'-'.$d->number;
            })->all();
        if ($pending) {
            $result['warnings'][] = sprintf(
                '%d comprobante(s) aun no estan aceptados por SUNAT (registrados, enviados o por anular) y no se incluyen: %s',
                count($pending),
                self::preview($pending)
            );
        }

        $rejected = (clone $base)->where('state_type_id', '09')->count();
        if ($rejected) {
            $result['warnings'][] = sprintf('%d comprobante(s) rechazado(s) por SUNAT no se incluyen.', $rejected);
        }

        $result['demo'] = (string) $company->soap_type_id !== '02';
        if ($result['demo']) {
            $result['warnings'][] = 'La empresa no esta en ambiente de produccion; los comprobantes de prueba no son validos para SIRE.';
        }
        $result['txt_path'] = $txtPath;

        return $result;
    }

    /**
     * Assembly from normalized documents (unit-testable, no database). Returns the TXT in 'txt'.
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
     * Blocking problems that make a document impossible to report correctly. Voided documents are
     * reported with zero amounts, so they are never blocked.
     *
     * @return string[]
     */
    public static function blockingErrors(array $doc): array
    {
        if (($doc['state_type_id'] ?? '') === self::VOIDED_STATE) {
            return [];
        }

        $label = ($doc['series'] ?? '').'-'.($doc['number'] ?? '');
        $type = (string) $doc['document_type_id'];
        $errors = [];

        $currency = (string) ($doc['currency_type_id'] ?? 'PEN');
        $rate = (float) ($doc['exchange_rate_sale'] ?? 0);
        $pen = (float) ($doc['total'] ?? 0) * ($currency === 'PEN' ? 1 : $rate);

        if ($currency !== 'PEN' && $rate <= 0) {
            $errors[] = $label.': moneda extranjera sin tipo de cambio valido.';
        }

        $affected = $doc['affected'][0] ?? null;
        $isNote = in_array($type, ['07', '08'], true);
        if ($isNote && (!$affected || ($affected['type'] ?? '') === '' || ($affected['series'] ?? '') === '' || ($affected['number'] ?? '') === '' || empty($affected['date']))) {
            $errors[] = $label.': la nota no tiene documento afectado (campos 29-32 obligatorios).';
        }

        $customer = $doc['customer'] ?? [];
        $idType = (string) ($customer['identity_document_type_id'] ?? '');
        $hasId = $idType !== '' && $idType !== '0' && trim((string) ($customer['number'] ?? '')) !== '';

        // Identification is mandatory on facturas and on notes modifying a factura; on boletas
        // (and notes on boletas) it may be blank only below S/ 700.
        $modifiesFactura = $isNote && $affected && ($affected['type'] ?? '') === '01';
        if (($type === '01' || $modifiesFactura) && !$hasId) {
            $errors[] = $label.': falta la identificacion del cliente (obligatoria en facturas).';
        }
        if ($type === '03' && !$hasId && abs($pen) >= self::BOLETA_ID_THRESHOLD) {
            $errors[] = $label.': boleta de S/ 700 o mas sin identificacion del comprador.';
        }

        return $errors;
    }

    private static function newState(): array
    {
        return [
            'rows' => 0, 'by_type' => [], 'taxed' => 0.0, 'igv' => 0.0, 'total' => 0.0,
            'warnings' => [], 'warn_count' => 0, 'errors' => [], 'err_count' => 0,
            'voided' => 0, 'voided_ids' => [], 'converted' => 0,
        ];
    }

    private static function addMessage(array &$state, string $list, string $message): void
    {
        $state[$list === 'errors' ? 'err_count' : 'warn_count']++;
        if (count($state[$list]) < self::MAX_MESSAGES) {
            $state[$list][] = $message;
        }
    }

    private static function consume(array &$state, array $doc, string $ruc, string $companyName, string $period, $handle): void
    {
        $errors = self::blockingErrors($doc);
        foreach ($errors as $error) {
            self::addMessage($state, 'errors', $error);
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
        $bucket = $bucket ?? ['type' => $type, 'count' => 0, 'voided' => 0, 'taxed' => 0.0, 'igv' => 0.0, 'total' => 0.0];
        $bucket['count']++;

        if (($doc['state_type_id'] ?? '') === self::VOIDED_STATE) {
            $bucket['voided']++;
            $state['voided']++;
            if (count($state['voided_ids']) < 8) {
                $state['voided_ids'][] = $doc['series'].'-'.$doc['number'];
            }
        } else {
            $bucket['taxed'] += (float) $fields[14];
            $bucket['igv'] += (float) $fields[16];
            $bucket['total'] += (float) $fields[25];
            $state['taxed'] += (float) $fields[14];
            $state['igv'] += (float) $fields[16];
            $state['total'] += (float) $fields[25];

            $sum = (float) $fields[13] + (float) $fields[14] + (float) $fields[16] + (float) $fields[18]
                + (float) $fields[19] + (float) $fields[20] + (float) $fields[23] + (float) $fields[24];
            if (abs($sum - (float) $fields[25]) > 0.005) {
                self::addMessage($state, 'warnings', sprintf('%s-%s: los importes no cuadran con el total (diferencia %s); revisar cargos o descuentos globales.', $doc['series'], $doc['number'], number_format($sum - (float) $fields[25], 2, '.', '')));
            }
            if ($fields[26] !== 'PEN') {
                $state['converted']++;
            }
        }
        unset($bucket);
    }

    private static function finish(array $state, string $ruc, int $year, int $month): array
    {
        foreach ($state['by_type'] as &$bucket) {
            foreach (['taxed', 'igv', 'total'] as $k) {
                $bucket[$k] = round($bucket[$k], 2);
            }
        }
        unset($bucket);
        ksort($state['by_type']);

        if ($state['voided']) {
            self::addMessage($state, 'warnings', sprintf('%d comprobante(s) anulado(s) se informan con importes en cero (comprobantes con baja comunicada): %s', $state['voided'], self::preview($state['voided_ids'], 8, $state['voided'])));
        }
        if ($state['converted']) {
            self::addMessage($state, 'warnings', sprintf('%d comprobante(s) en moneda extranjera se informan convertidos a soles con su tipo de cambio.', $state['converted']));
        }

        $warnings = $state['warnings'];
        if ($state['warn_count'] > count($warnings)) {
            $warnings[] = sprintf('y %d mas', $state['warn_count'] - count($warnings));
        }
        $errors = $state['errors'];
        if ($state['err_count'] > count($errors)) {
            $errors[] = sprintf('y %d mas', $state['err_count'] - count($errors));
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
                'total' => round($state['total'], 2),
            ],
            'warnings' => $warnings,
            'warnings_count' => $state['warn_count'],
            'errors' => $errors,
            'errors_count' => $state['err_count'],
        ];
    }

    /**
     * Creates the ZIP in a temporary file from the TXT file and returns its path
     * (caller deletes both after sending). The TXT is read when the archive closes.
     */
    public static function zipFile(string $filename, string $txtPath): string
    {
        $path = tempnam(sys_get_temp_dir(), 'rvie');
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

    /**
     * Converts an Eloquent document (with note.affected_document and fee loaded) to the array consumed by mapRow().
     */
    public static function normalize(Document $document): array
    {
        $customer = $document->customer;
        $affected = [];

        if (in_array($document->document_type_id, ['07', '08'], true) && $document->note) {
            $ref = $document->note->affected_document;
            $data = $document->note->data_affected_document;
            if ($ref) {
                $affected[] = [
                    'date' => $ref->date_of_issue->format('Y-m-d'),
                    'type' => $ref->document_type_id,
                    'series' => $ref->series,
                    'number' => $ref->number,
                ];
            } elseif ($data) {
                $affected[] = [
                    'date' => $data->date_of_issue ?? null,
                    'type' => $data->document_type_id ?? '',
                    'series' => $data->series ?? '',
                    'number' => $data->number ?? '',
                ];
            }
        }

        // Credit invoices: due date = last installment date. Otherwise empty.
        $due = $document->fee && $document->fee->count() ? $document->fee->max('date') : null;

        return [
            'document_type_id' => $document->document_type_id,
            'series' => $document->series,
            'number' => $document->number,
            'date_of_issue' => $document->date_of_issue->format('Y-m-d'),
            'due_date' => $due ? Carbon::parse($due)->format('Y-m-d') : null,
            'state_type_id' => $document->state_type_id,
            'customer' => [
                'identity_document_type_id' => $customer->identity_document_type_id ?? '',
                'number' => $customer->number ?? '',
                'name' => $customer->name ?? '',
            ],
            'currency_type_id' => $document->currency_type_id,
            'exchange_rate_sale' => $document->exchange_rate_sale,
            'total_exportation' => $document->total_exportation,
            'total_taxed' => $document->total_taxed,
            'total_igv' => $document->total_igv,
            'total_exonerated' => $document->total_exonerated,
            'total_unaffected' => $document->total_unaffected,
            'total_isc' => $document->total_isc,
            'total_plastic_bag_taxes' => $document->total_plastic_bag_taxes,
            'total_other_taxes' => $document->total_other_taxes,
            'total' => $document->total,
            'affected' => $affected,
        ];
    }

    private static function customerFields(array $doc): array
    {
        $customer = $doc['customer'] ?? [];
        $idType = (string) ($customer['identity_document_type_id'] ?? '');
        $number = trim((string) ($customer['number'] ?? ''));
        $name = trim((string) ($customer['name'] ?? ''));

        // Anexo 3: identification is optional for boletas below S/ 700 and the value "0" is not accepted;
        // when absent from the voucher the fields must be empty.
        if ($idType === '0' || $idType === '' || $number === '') {
            return ['', '', ''];
        }

        return [$idType, self::text($number, 15), self::text($name, 1500)];
    }

    private static function amount(float $value): string
    {
        $value = round($value, 2);
        if ($value == 0.0) {
            $value = 0.0; // avoid "-0.00"
        }

        return number_format($value, 2, '.', '');
    }

    private static function date(?string $date): string
    {
        return $date ? Carbon::parse($date)->format('d/m/Y') : '';
    }

    private static function join(array $values, bool $dates = false): string
    {
        $values = array_map(function ($v) use ($dates) {
            return $dates ? self::date($v) : self::text((string) $v);
        }, $values);

        return implode(',', array_filter($values, 'strlen'));
    }

    /** Removes the field separator and line breaks, collapsing spaces; optionally truncates. */
    private static function text(string $value, ?int $max = null): string
    {
        $value = trim(preg_replace('/\s+/', ' ', str_replace('|', ' ', $value)));

        return $max !== null ? mb_substr($value, 0, $max) : $value;
    }

    private static function preview(array $items, int $max = 8, ?int $total = null): string
    {
        $total = $total ?? count($items);
        $shown = array_slice($items, 0, $max);

        return implode(', ', $shown).($total > count($shown) ? sprintf(' y %d mas', $total - count($shown)) : '');
    }
}
