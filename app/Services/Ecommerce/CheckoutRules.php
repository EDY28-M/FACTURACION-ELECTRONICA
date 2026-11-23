<?php

namespace App\Services\Ecommerce;

use Illuminate\Support\Facades\Validator;

/**
 * Server-side rules for the storefront checkout customer (cash and card paths share them), so
 * every stored order carries the data the back office needs to list it and issue its comprobante.
 */
class CheckoutRules
{
    const IDENTITY_FIELDS = [
        'codigo_tipo_documento_identidad',
        'numero_documento',
        'apellidos_y_nombres_o_razon_social',
        'codigo_pais',
        'ubigeo',
        'direccion',
        'correo_electronico',
        'telefono',
        'identity_document_type_id',
    ];

    /** Boletas above this amount must identify the buyer (no "DOC" type 0). */
    const ANONYMOUS_BOLETA_LIMIT = 700;

    /**
     * Keeps only the known customer fields (the client may send anything).
     */
    public static function sanitize(array $customer, ?string $sessionEmail = null): array
    {
        $clean = [];
        foreach (self::IDENTITY_FIELDS as $field) {
            if (array_key_exists($field, $customer) && is_scalar($customer[$field])) {
                $clean[$field] = trim((string) $customer[$field]);
            }
        }

        if ($sessionEmail !== null && $sessionEmail !== '') {
            $clean['correo_electronico'] = $sessionEmail;
        }
        $clean['codigo_pais'] = 'PE';
        $clean['ubigeo'] = '150101';

        return $clean;
    }

    public static function validate(array $customer): \Illuminate\Contracts\Validation\Validator
    {
        $validator = Validator::make($customer, [
            'apellidos_y_nombres_o_razon_social' => 'required|string|max:255',
            'correo_electronico' => 'required|email|max:255',
            'telefono' => 'required|numeric|digits_between:6,15',
            'direccion' => 'required|string|max:255',
            'codigo_tipo_documento_identidad' => 'required|in:0,1,4,6',
            'numero_documento' => 'required|numeric|digits_between:1,15',
            'identity_document_type_id' => 'required|in:1,6',
        ], [
            'required' => 'El campo :attribute es obligatorio.',
            'string' => 'El campo :attribute no es válido.',
            'max' => 'El campo :attribute no debe superar :max caracteres.',
            'email' => 'El correo electrónico no es válido.',
            'numeric' => 'El campo :attribute debe ser numérico.',
            'digits_between' => 'El campo :attribute debe tener entre :min y :max dígitos.',
            'in' => 'El campo :attribute no es válido.',
        ], [
            'apellidos_y_nombres_o_razon_social' => 'nombre o razón social',
            'correo_electronico' => 'correo electrónico',
            'telefono' => 'teléfono',
            'direccion' => 'dirección',
            'codigo_tipo_documento_identidad' => 'tipo de documento',
            'numero_documento' => 'número de documento',
            'identity_document_type_id' => 'comprobante',
        ]);

        $validator->after(function ($validator) use ($customer) {
            $type = (string) ($customer['codigo_tipo_documento_identidad'] ?? '');
            $number = (string) ($customer['numero_documento'] ?? '');
            $voucher = (string) ($customer['identity_document_type_id'] ?? '');

            if ($voucher === '6' && $type !== '6') {
                $validator->errors()->add('codigo_tipo_documento_identidad', 'Una factura requiere RUC.');
            } elseif ($voucher === '1' && $type === '6') {
                $validator->errors()->add('codigo_tipo_documento_identidad', 'Una boleta no puede usar RUC.');
            }
            if ($type === '1' && ! preg_match('/^\d{8}$/', $number)) {
                $validator->errors()->add('numero_documento', 'El DNI debe tener 8 dígitos.');
            }
            if ($type === '6' && ! preg_match('/^\d{11}$/', $number)) {
                $validator->errors()->add('numero_documento', 'El RUC debe tener 11 dígitos.');
            }
        });

        return $validator;
    }

    /**
     * Validation error for a boleta that is too large to stay anonymous, or null.
     */
    public static function identityErrorForTotal(array $customer, float $total): ?array
    {
        if (($customer['identity_document_type_id'] ?? null) === '1'
            && ($customer['codigo_tipo_documento_identidad'] ?? null) === '0'
            && $total > self::ANONYMOUS_BOLETA_LIMIT) {
            return ['codigo_tipo_documento_identidad' => ['Las boletas mayores a S/ 700.00 deben identificar al comprador.']];
        }

        return null;
    }
}
