<?php

namespace App\CoreFacturalo\Helpers\Sunat;

use App\CoreFacturalo\Helpers\Number\NumberLetter;
use App\Models\Tenant\Document;
use Illuminate\Support\Facades\DB;

/**
 * Credit note type 13 (Catalog 09): "Correccion o modificacion del monto neto pendiente de pago
 * y/o de la(s) fecha(s) de vencimiento del pago unico o de las cuotas".
 *
 * Source: RS 193-2020/SUNAT, Anexo 4 (CreditNote UBL 2.1): the note carries
 * /CreditNote/cac:PaymentTerms with ID "FormaPago", PaymentMeansID "Credito" + net pending amount,
 * and one PaymentTerms per cuota ("Cuota001"...) with Amount and PaymentDueDate.
 * The note is financial only: all monetary totals and line amounts are 0.00 (not stated in the
 * resolution; unverified against official text: standard OSE practice).
 */
class CreditNotePaymentAdjustment
{
    public const TYPE = '13';

    public static function isType($noteCreditTypeId): bool
    {
        return (string) $noteCreditTypeId === self::TYPE;
    }

    /**
     * Validates the new cuotas and returns them normalized.
     *
     * @throws \Exception
     */
    public static function validatedFees($affectedDocumentId, $dateOfIssue, $currencyTypeId, $fees): array
    {
        if (!$affectedDocumentId) {
            throw new \Exception('El ajuste de cuotas (tipo 13) requiere que la factura afectada exista en el sistema.');
        }

        $affected = Document::find($affectedDocumentId);

        if (!$affected || $affected->document_type_id !== '01') {
            throw new \Exception('La nota de crédito tipo 13 solo se puede emitir sobre una factura.');
        }

        if ($affected->payment_condition_id !== '02') {
            throw new \Exception('La nota de crédito tipo 13 solo aplica a facturas con forma de pago crédito.');
        }

        if ($affected->currency_type_id !== $currencyTypeId) {
            throw new \Exception('La moneda de la nota debe ser la misma de la factura afectada.');
        }

        if (!is_array($fees) || count($fees) === 0) {
            throw new \Exception('Debe registrar al menos una cuota para la nota de crédito tipo 13.');
        }

        $issue = substr((string) $dateOfIssue, 0, 10);
        $normalized = [];
        $sum = 0;

        foreach (array_values($fees) as $i => $fee) {
            $amount = round((float) ($fee['amount'] ?? 0), 2);
            $date = substr((string) ($fee['date'] ?? ''), 0, 10);
            $n = $i + 1;

            if ($amount <= 0) {
                throw new \Exception("El monto de la cuota {$n} debe ser mayor a cero.");
            }
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $date <= $issue) {
                throw new \Exception("La fecha de vencimiento de la cuota {$n} debe ser posterior a la fecha de emisión.");
            }

            $sum += $amount;
            $normalized[] = [
                'date' => $date,
                'currency_type_id' => $currencyTypeId,
                'amount' => $amount,
            ];
        }

        if (round($sum, 2) > round((float) $affected->total, 2)) {
            throw new \Exception('La suma de las cuotas no puede superar el total de la factura afectada.');
        }

        return $normalized;
    }

    /** Zeroes monetary totals of the document header and lines (financial-only note). */
    public static function zeroAmounts(array $data): array
    {
        foreach ([
            'total_prepayment', 'total_discount', 'total_charge', 'total_exportation', 'total_free',
            'total_taxed', 'total_unaffected', 'total_exonerated', 'total_igv', 'total_base_isc',
            'total_isc', 'total_base_other_taxes', 'total_other_taxes', 'total_plastic_bag_taxes',
            'total_taxes', 'total_value', 'total',
        ] as $key) {
            $data[$key] = 0;
        }

        foreach ($data['items'] ?? [] as $i => $row) {
            foreach ([
                'unit_value', 'unit_price', 'total_base_igv', 'total_igv', 'total_base_isc', 'total_isc',
                'total_base_other_taxes', 'total_other_taxes', 'total_plastic_bag_taxes', 'total_taxes',
                'total_value', 'total_charge', 'total_discount', 'total',
            ] as $key) {
                $row[$key] = 0;
            }
            $data['items'][$i] = $row;
        }

        // Legend 1000 (amount in words) must describe the zero total, not the original one.
        $legends = [];
        foreach ($data['legends'] ?? [] as $legend) {
            if ((string) $legend['code'] === '1000') {
                $legend['value'] = NumberLetter::convertToLetter(0);
            }
            $legends[] = $legend;
        }
        $data['legends'] = $legends;

        $data['discounts'] = null;
        $data['charges'] = null;

        return $data;
    }

    /**
     * Applies an ACCEPTED type 13 note to the affected factura: its cuotas are replaced by the
     * note's cuotas. This runs only after SUNAT accepts the note (state 05), because until then
     * the original payment terms of the invoice are still the valid ones. Idempotent: the rows
     * are replaced, never appended.
     */
    public static function applyAccepted(Document $noteDocument): void
    {
        $note = $noteDocument->note;

        if ($noteDocument->document_type_id !== '07' || !$note || !self::isType($note->note_credit_type_id) || !$note->affected_document_id) {
            return;
        }

        $affected = Document::find($note->affected_document_id);
        $fees = $noteDocument->fee()->get();

        if (!$affected || $fees->isEmpty()) {
            return;
        }

        DB::connection('tenant')->transaction(function () use ($affected, $fees) {
            $affected->fee()->delete();
            foreach ($fees as $fee) {
                $affected->fee()->create([
                    'date' => $fee->date->format('Y-m-d'),
                    'currency_type_id' => $fee->currency_type_id,
                    'amount' => $fee->amount,
                ]);
            }
        });
    }
}
