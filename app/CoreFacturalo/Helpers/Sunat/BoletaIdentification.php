<?php

namespace App\CoreFacturalo\Helpers\Sunat;

/**
 * Single source of truth for the SUNAT rule on buyer identification in boletas.
 *
 * Anexo II of RS 114-2019/SUNAT: the buyer's document type and number are
 * required when the total amount of the boleta exceeds S/ 700.00
 * (strictly greater: exactly 700.00 does not require identification).
 * SUNAT rejects it with error 2396.
 *
 * Keep in sync with resources/js/helpers/boleta-identification.js
 */
class BoletaIdentification
{
    public const THRESHOLD = 700;

    public const MESSAGE = 'Las boletas por más de S/ 700.00 deben identificar al cliente con su tipo y número de documento (Reglamento de Comprobantes de Pago, art. 8).';

    /** Catalog 06 codes that mean "no identification". */
    private const NOT_IDENTIFIED_TYPES = ['', '0', '-'];

    /**
     * Whether the boleta total (converted to PEN) exceeds the threshold.
     */
    public static function requiresIdentification($total, $currencyTypeId = 'PEN', $exchangeRate = 1): bool
    {
        $amount = (float) $total;
        if (strtoupper((string) $currencyTypeId) !== 'PEN') {
            $rate = (float) $exchangeRate;
            $amount *= $rate > 0 ? $rate : 1;
        }

        // Compare in cents to avoid float noise (e.g. 700.0000001).
        return round($amount, 2) > self::THRESHOLD;
    }

    /**
     * Whether the customer has a real identification document.
     */
    public static function isIdentified($identityDocumentTypeId, $number): bool
    {
        $type = trim((string) $identityDocumentTypeId);
        $number = trim((string) $number);

        if (in_array($type, self::NOT_IDENTIFIED_TYPES, true) || $number === '') {
            return false;
        }

        // Generic placeholders such as 00000000 / 99999999.
        if (preg_match('/^(0+|9+)$/', $number)) {
            return false;
        }

        return true;
    }

    /**
     * Throws when a boleta above the threshold lacks buyer identification.
     */
    public static function assert($total, $currencyTypeId, $exchangeRate, $identityDocumentTypeId, $number): void
    {
        if (self::requiresIdentification($total, $currencyTypeId, $exchangeRate)
            && !self::isIdentified($identityDocumentTypeId, $number)) {
            throw new \Exception(self::MESSAGE);
        }
    }
}
