<?php

namespace App\CoreFacturalo\Helpers\Sunat;

/**
 * Decides whether a credit note (document type 07) must give stock back to the warehouse.
 *
 * SUNAT Catalog 09 (credit note types). Stock goes back only when the operation is voided or the
 * goods physically return to the seller:
 *  - 01 Anulacion de la operacion
 *  - 02 Anulacion por error en el RUC (the sale is re-issued to the right RUC, which discounts stock again)
 *  - 06 Devolucion total
 *  - 07 Devolucion por item (only the quantities carried by the note items)
 *
 * Every other type (03 description fix, 04/05 discounts, 08 bonus, 09 value decrease, 10 other,
 * 11 export adjustments, 12 IVAP adjustments, 13 payment amounts/dates adjustments) is financial
 * only and must not move stock. A missing or unknown type never restores stock.
 */
class CreditNoteStock
{
    public const RESTORING_TYPES = ['01', '02', '06', '07'];

    public static function restoresStock($noteCreditTypeId): bool
    {
        if ($noteCreditTypeId === null || $noteCreditTypeId === '') {
            return false;
        }

        return in_array(str_pad((string) $noteCreditTypeId, 2, '0', STR_PAD_LEFT), self::RESTORING_TYPES, true);
    }

    /**
     * Whether a document line must move stock/kardex/lots when it is created.
     *
     * Debit notes (08) only increase the value of the operation and never move stock.
     * Credit notes (07) follow {@see restoresStock()}. Other documents keep their behavior.
     */
    public static function documentMovesStock($document): bool
    {
        return self::movesStockFor((string) $document->document_type_id, function () use ($document) {
            $note = $document->note;

            return $note ? $note->note_credit_type_id : null;
        });
    }

    /**
     * @param string        $documentTypeId
     * @param callable|null $creditTypeResolver returns the note_credit_type_id (only called for 07)
     */
    public static function movesStockFor(string $documentTypeId, ?callable $creditTypeResolver = null): bool
    {
        if ($documentTypeId === '08') {
            return false;
        }

        if ($documentTypeId !== '07') {
            return true;
        }

        return self::restoresStock($creditTypeResolver ? $creditTypeResolver() : null);
    }
}
