<?php

namespace App\CoreFacturalo\Helpers\Sunat;

use Exception;

/**
 * Single source of truth for the structural validation of a Peruvian RUC (catalog 06 code '6').
 *
 * Rules: 11 digits, valid prefix (10, 15, 16, 17, 20) and Modulo 11 check digit
 * (weights 5,4,3,2,7,6,5,4,3,2 over the first ten digits; check = (11 - sum % 11) mod 10).
 * This does NOT prove the RUC exists or is active; only that it is well formed.
 *
 * Keep in sync with resources/js/helpers/ruc-validator.js
 */
class RucValidator
{
    private const WEIGHTS = [5, 4, 3, 2, 7, 6, 5, 4, 3, 2];

    private const PREFIXES = ['10', '15', '16', '17', '20'];

    /** Catalog 06 code for RUC. */
    public const IDENTITY_DOCUMENT_TYPE_ID = '6';

    public static function isValid($ruc): bool
    {
        $ruc = trim((string) $ruc);

        if (!preg_match('/^\d{11}$/', $ruc) || !in_array(substr($ruc, 0, 2), self::PREFIXES, true)) {
            return false;
        }

        $sum = 0;
        foreach (self::WEIGHTS as $i => $weight) {
            $sum += ((int) $ruc[$i]) * $weight;
        }

        // 11 - remainder is 10 or 11 only for remainders 1 and 0: they map to 0 and 1.
        return (11 - $sum % 11) % 10 === (int) $ruc[10];
    }

    public static function message($ruc): string
    {
        return 'El RUC ' . trim((string) $ruc) . ' no es válido (dígito verificador incorrecto).';
    }

    /**
     * Throws when the document is a RUC (type '6') that fails the structural validation.
     * Other document types are never validated here.
     */
    public static function assert($identityDocumentTypeId, $number): void
    {
        if ((string) $identityDocumentTypeId !== self::IDENTITY_DOCUMENT_TYPE_ID) {
            return;
        }

        if (!self::isValid($number)) {
            throw new Exception(self::message($number));
        }
    }
}
