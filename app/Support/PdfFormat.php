<?php

namespace App\Support;

/**
 * Formatos comunes para las representaciones impresas (PDF) de los comprobantes.
 *
 * Todo es tolerante a datos incompletos: si falta un dato se omite, nunca se rompe el PDF.
 */
class PdfFormat
{
    /** Dirección legible: sin guiones vacíos, sin comas sobrantes y sin repetir "LIMA, LIMA, LIMA". */
    public static function address($entity): string
    {
        if (! $entity) {
            return '';
        }

        $parts = [data_get($entity, 'address')];
        foreach (['district', 'province', 'department'] as $relation) {
            $id = data_get($entity, $relation.'_id');
            if ($id && $id !== '-') {
                try {
                    $parts[] = data_get($entity, $relation.'.description');
                } catch (\Throwable $e) {
                    // Relación inexistente: se omite.
                }
            }
        }

        $clean = [];
        foreach ($parts as $part) {
            $part = trim((string) $part, " \t\n\r\0\x0B,-");
            if ($part === '') {
                continue;
            }
            $last = end($clean);
            if ($last !== false && mb_strtolower($last) === mb_strtolower($part)) {
                continue;
            }
            $clean[] = $part;
        }

        return implode(', ', $clean);
    }

    /** "-" o vacío cuentan como dato ausente. */
    public static function filled($value): bool
    {
        $value = trim((string) $value);

        return $value !== '' && $value !== '-';
    }

    /** Cliente genérico ("clientes varios", documento 99999999 / 00000000): no se muestran sus datos de identidad. */
    public static function isAnonymous($customer): bool
    {
        $number = trim((string) data_get($customer, 'number'));

        return $number === '' || $number === '-' || (bool) preg_match('/^(0+|9+)$/', $number);
    }

    /** Etiqueta corta del documento de identidad. */
    public static function identityLabel($customer): string
    {
        $map = ['1' => 'DNI', '4' => 'C.E.', '6' => 'RUC', '7' => 'Pasaporte', 'A' => 'C.D.I.', '0' => 'Doc.'];
        $id = (string) data_get($customer, 'identity_document_type_id');
        if (isset($map[$id])) {
            return $map[$id];
        }
        try {
            return (string) (data_get($customer, 'identity_document_type.description') ?: 'Doc.');
        } catch (\Throwable $e) {
            return 'Doc.';
        }
    }

    /** Unidad legible para el cliente (NIU -> UND, ZZ -> SERV); el resto se deja con su código SUNAT. */
    public static function unit($code): string
    {
        $map = ['NIU' => 'UND', 'ZZ' => 'SERV'];
        $code = (string) $code;

        return $map[$code] ?? $code;
    }

    /** Cantidad sin decimales innecesarios: 1, 2.5, 0.125. */
    public static function quantity($quantity): string
    {
        $q = (float) $quantity;
        if ((int) $q == $q) {
            return number_format($q, 0, '.', ',');
        }

        return rtrim(rtrim(number_format($q, 4, '.', ','), '0'), '.');
    }

    public static function money($amount): string
    {
        return number_format((float) $amount, 2, '.', ',');
    }

    public static function date($date): string
    {
        if (! $date) {
            return '';
        }
        try {
            return ($date instanceof \DateTimeInterface ? $date : new \DateTime((string) $date))->format('d/m/Y');
        } catch (\Throwable $e) {
            return (string) $date;
        }
    }

    /** Hora sin segundos. */
    public static function time($time): string
    {
        return $time ? substr((string) $time, 0, 5) : '';
    }

    /** Forma de pago para la representación impresa (obligatoria en facturas desde 2021). */
    public static function paymentCondition($document): ?string
    {
        $id = data_get($document, 'payment_condition_id');
        if (! $id) {
            return null;
        }

        return $id === '01' ? 'Contado' : 'Crédito';
    }

    /** "Son: ..." sin repetir la moneda cuando la leyenda ya la incluye. */
    public static function amountInWords($value, $currency): string
    {
        $value = trim((string) $value);
        $currency = trim((string) $currency);
        if ($currency === '' || mb_stripos($value, $currency) !== false) {
            return $value;
        }

        return $value.' '.$currency;
    }

    /**
     * Filas de productos normalizadas para las plantillas comerciales (nota de venta, cotización, pedido, compras...).
     * La descripción conserva el HTML del sistema (igual que antes); los textos adicionales se escapan.
     */
    public static function itemRows($items): array
    {
        $lotService = class_exists(\App\Services\ItemLotsGroupService::class) ? app(\App\Services\ItemLotsGroupService::class) : null;
        $setService = class_exists(\App\Services\ItemSetService::class) ? app(\App\Services\ItemSetService::class) : null;
        $rows = [];
        foreach (($items ?? []) as $row) {
            $item = data_get($row, 'item');
            $description = data_get($row, 'name_product_pdf') ?: data_get($item, 'description');
            $presentation = data_get($item, 'presentation.description');
            if ($presentation) {
                $description .= ' '.$presentation;
            }

            $extra = [];
            foreach ((array) (data_get($row, 'additional_information') ?? []) as $info) {
                if ($info) { $extra[] = e($info); }
            }
            foreach ((data_get($row, 'attributes') ?? []) as $attr) {
                $extra[] = e(strip_tags((string) data_get($attr, 'description')).': '.data_get($attr, 'value'));
            }
            $discount = 0;
            foreach ((data_get($row, 'discounts') ?? []) as $d) {
                $extra[] = e((data_get($d, 'factor') * 100).'% '.data_get($d, 'description'));
                $discount += (float) data_get($d, 'amount');
            }
            if ($setService && (int) data_get($item, 'is_set') === 1) {
                try {
                    foreach ($setService->getItemsSet(data_get($row, 'item_id')) as $setItem) { $extra[] = e($setItem); }
                } catch (\Throwable $e) {
                }
            }

            $lot = '';
            if ($lotService) {
                try {
                    $checked = collect(data_get($item, 'lots_group') ?? [])->first(function ($g) { return data_get($g, 'checked') == true; });
                    $lotId = $checked ? data_get($checked, 'id') : data_get($item, 'IdLoteSelected');
                    $lot = trim((string) $lotService->getLote($lotId));
                } catch (\Throwable $e) {
                    $lot = '';
                }
            }
            $series = [];
            foreach ((data_get($item, 'lots') ?? []) as $l) {
                if (data_get($l, 'has_sale')) { $series[] = (string) data_get($l, 'series'); }
            }

            $rows[] = [
                'qty' => self::quantity(data_get($row, 'quantity')),
                'unit' => self::unit(data_get($item, 'unit_type_id')),
                'description' => (string) $description,
                'extra' => $extra,
                'unit_price' => data_get($row, 'unit_price'),
                'discount' => $discount,
                'total' => data_get($row, 'total'),
                'model' => trim((string) data_get($item, 'model')),
                'lot' => $lot,
                'series' => $series,
            ];
        }

        return $rows;
    }

    /** Subtotales habituales. $withTax = false omite Op. gravadas e IGV (la nota de venta no desglosa impuestos). */
    public static function totals($document, bool $withTax = true): array
    {
        $t = [];
        $add = function ($label, $field) use (&$t, $document) {
            $v = (float) data_get($document, $field);
            if ($v > 0) { $t[$label] = $v; }
        };
        $add('Op. exportación', 'total_exportation');
        $add('Op. gratuitas', 'total_free');
        $add('Op. inafectas', 'total_unaffected');
        $add('Op. exoneradas', 'total_exonerated');
        if ($withTax) { $add('Op. gravadas', 'total_taxed'); }
        $add(((float) data_get($document, 'total_prepayment') > 0) ? 'Anticipo' : 'Descuento total', 'total_discount');
        if ($withTax && data_get($document, 'total_igv') !== null) { $t['IGV'] = (float) data_get($document, 'total_igv'); }

        return $t;
    }

    /** "SERIE-NUMERO" sin relleno; null si no hay datos. */
    public static function number($left, $right): ?string
    {
        $n = trim(trim((string) $left).'-'.trim((string) $right), '-');

        return $n === '' ? null : $n;
    }
}
