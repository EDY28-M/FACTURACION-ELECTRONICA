<?php

namespace App\CoreFacturalo\Helpers\Sunat;

use Carbon\Carbon;
use Exception;

/**
 * SUNAT "Codigo de producto SUNAT" (Catalogo 25 / UNSPSC v14_0801, 8 digits).
 *
 * Official SUNAT page (cpe.sunat.gob.pe/informacion_general/codigoproducto, 13/07/2026): not a minimum
 * requirement today, an invalid code is only observed (OBS-3496).
 * [unverified, vendor sources] From 2027-01-01 SUNAT validates it as mandatory (ERR-3496/3506, rejection)
 * for facturas, boletas and notes. Liquidacion de compra (04) is saved by the purchase flow and is not guarded here. Date is configurable: sunat.product_code_required_from.
 */
class ProductCode
{
    /** Document types where the code becomes mandatory: factura, boleta, NC, ND. */
    const REQUIRED_DOCUMENT_TYPES = ['01', '03', '07', '08'];

    /** @var callable|null fn(string[] $codes): string[] returning the codes that exist, replaceable in tests */
    private static $lookup = null;

    public static function setLookup(callable $lookup = null)
    {
        self::$lookup = $lookup;
    }

    public static function isValidFormat($code)
    {
        return is_string($code) && preg_match('/^\d{8}$/', $code) === 1;
    }

    /** Exists in Catalogo 25 as class (xxxxxx00) or product. */
    public static function exists($code)
    {
        if (!self::isValidFormat($code)) {
            return false;
        }

        return in_array($code, self::existing([$code]), true);
    }

    /** Batched existence check: returns the subset of $codes present in the catalog (one query). */
    public static function existing(array $codes)
    {
        $codes = array_values(array_unique(array_filter($codes, [self::class, 'isValidFormat'])));
        if (!$codes) {
            return [];
        }
        if (self::$lookup) {
            return array_values(call_user_func(self::$lookup, $codes));
        }

        return \App\Models\System\SunatProductCode::whereIn('code', $codes)->pluck('code')->all();
    }

    /** Only a new or changed code needs catalog validation; an unchanged legacy value is left alone. */
    public static function needsValidation($storedCode, $newCode)
    {
        $new = trim((string) $newCode);

        return $new !== '' && $new !== trim((string) $storedCode);
    }

    /** Validation rule for item_code on item save requests. $storedCode: current DB value (null for new items). */
    public static function itemCodeRule($storedCode)
    {
        return function ($attribute, $value, $fail) use ($storedCode) {
            if (self::needsValidation($storedCode, $value) && !self::exists(trim((string) $value))) {
                $fail('El código SUNAT no existe en el Catálogo 25 (8 dígitos).');
            }
        };
    }

    public static function storedItemCode($itemId)
    {
        return $itemId ? \App\Models\Tenant\Item::query()->whereKey($itemId)->value('item_code') : null;
    }

    public static function requiredFrom()
    {
        try {
            $value = config('sunat.product_code_required_from');
        } catch (\Throwable $e) {
            $value = null; // no container (plain unit tests)
        }

        return $value ?: '2027-01-01';
    }

    /** Is a valid code mandatory for a document of this type issued on this date? */
    public static function isRequired($documentTypeId, $dateOfIssue)
    {
        if (!in_array((string) $documentTypeId, self::REQUIRED_DOCUMENT_TYPES, true)) {
            return false;
        }

        return Carbon::parse($dateOfIssue)->format('Y-m-d') >= self::requiredFrom();
    }

    /**
     * Emission guard: before the required date it does nothing; from that date every item needs an existing code.
     *
     * @param array $inputs document inputs (document_type_id, date_of_issue, items[].item.item_code)
     * @throws Exception
     */
    public static function assertItems(array $inputs)
    {
        if (!self::isRequired($inputs['document_type_id'] ?? null, $inputs['date_of_issue'] ?? date('Y-m-d'))) {
            return;
        }

        $rows = $inputs['items'] ?? [];
        $present = self::existing(array_map(function ($row) {
            return (string) ($row['item']['item_code'] ?? '');
        }, $rows));

        foreach ($rows as $i => $row) {
            $code = (string) ($row['item']['item_code'] ?? '');
            if (!in_array($code, $present, true)) {
                $name = $row['item']['description'] ?? ('#'.($i + 1));
                throw new Exception(
                    "El producto \"{$name}\" no tiene un código de producto SUNAT válido (Catálogo 25). "
                    .'Desde '.Carbon::parse(self::requiredFrom())->format('d/m/Y').' SUNAT lo exige en este comprobante.'
                );
            }
        }
    }
}
