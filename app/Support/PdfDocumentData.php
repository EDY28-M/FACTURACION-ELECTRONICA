<?php

namespace App\Support;

use App\Support\PdfFormat as F;

/**
 * Prepara los datos de cada documento comercial para las plantillas comunes del formato "moderno"
 * (partials/commercial_a4 y partials/commercial_ticket). Un mismo arreglo sirve para A4, A5 y ticket.
 */
class PdfDocumentData
{
    /** Leyenda "Son: ..." (código 1000) si el documento la trae. */
    private static function words($document): ?string
    {
        foreach ((array) (data_get($document, 'legends') ?? []) as $legend) {
            if (data_get($legend, 'code') == '1000') {
                return F::amountInWords(data_get($legend, 'value'), data_get($document, 'currency_type.description'));
            }
        }

        return null;
    }

    private static function guides($document): array
    {
        $refs = [];
        foreach ((data_get($document, 'guides') ?? []) as $guide) {
            $refs[] = [data_get($guide, 'document_type_description') ?: data_get($guide, 'document_type_id'), data_get($guide, 'number')];
        }

        return $refs;
    }

    private static function base($document, string $title, string $number): array
    {
        return [
            'establishment' => data_get($document, 'establishment'),
            'title' => $title,
            'number' => $number,
            'anulado' => data_get($document, 'state_type_id') === '11',
            'symbol' => data_get($document, 'currency_type.symbol') ?: 'S/',
            'items' => F::itemRows(data_get($document, 'items')),
            'words' => self::words($document),
        ];
    }

    private static function padded($left, $number): string
    {
        return trim((string) $left).'-'.str_pad((string) $number, 8, '0', STR_PAD_LEFT);
    }

    private static function seller($document): ?string
    {
        return data_get($document, 'seller.name') ?: data_get($document, 'user.name');
    }

    public static function saleNote($document): array
    {
        $payments = [];
        $paid = 0;
        foreach ((data_get($document, 'payments') ?? []) as $p) {
            $label = trim(F::date(data_get($p, 'date_of_payment')).' · '.data_get($p, 'payment_method_type.description')
                .(data_get($p, 'reference') ? ' · '.data_get($p, 'reference') : ''), ' ·');
            $payments[] = [$label, data_get($p, 'payment')];
            $paid += (float) data_get($p, 'payment');
        }
        if (! count($payments) && data_get($document, 'payment_method_type_id')) {
            $payments[] = [data_get($document, 'payment_method_type.description'), data_get($document, 'total')];
        }
        $balance = count($payments) && $paid > 0 ? round((float) data_get($document, 'total') - $paid, 2) : null;

        return self::base($document, 'NOTA DE VENTA', self::padded(data_get($document, 'series') ?: data_get($document, 'prefix'), data_get($document, 'number'))) + [
            'party_label' => 'Cliente',
            'party' => data_get($document, 'customer'),
            'party_extra' => [['Teléfono', data_get($document, 'customer.telephone')]],
            'meta' => [
                ['Fecha', trim(F::date(data_get($document, 'date_of_issue')).' '.F::time(data_get($document, 'time_of_issue')))],
                ['Moneda', data_get($document, 'currency_type.description')],
                ['Estado', data_get($document, 'total_canceled') ? 'Pagado' : 'Pendiente de pago', true],
                ['N° placa', data_get($document, 'plate_number')],
            ],
            'refs' => array_merge([
                ['Referencia', data_get($document, 'reference_data')],
                ['Orden de compra', data_get($document, 'purchase_order')],
            ], self::guides($document)),
            'notes' => [['Observación', data_get($document, 'observation')]],
            'totals' => F::totals($document, false),
            'grand' => data_get($document, 'total'),
            'payments' => $payments,
            'balance' => ($balance !== null && $balance > 0.004) ? $balance : null,
            'accounts' => true,
            'seller' => self::seller($document),
            'legend' => 'Este documento no es un comprobante de pago.',
        ];
    }

    public static function quotation($document): array
    {
        $payments = [];
        foreach ((data_get($document, 'payments') ?? []) as $p) {
            $payments[] = [trim(data_get($p, 'payment_method_type.description').(data_get($p, 'reference') ? ' · '.data_get($p, 'reference') : '')), data_get($p, 'payment')];
        }

        return self::base($document, 'COTIZACIÓN', self::padded(data_get($document, 'prefix'), data_get($document, 'id'))) + [
            'party_label' => 'Cliente',
            'party' => data_get($document, 'customer'),
            'party_extra' => [
                ['Contacto', data_get($document, 'contact')],
                ['Teléfono', data_get($document, 'phone')],
                ['Dirección de envío', data_get($document, 'shipping_address')],
            ],
            'meta' => [
                ['Fecha', F::date(data_get($document, 'date_of_issue'))],
                ['Válida hasta', F::date(data_get($document, 'date_of_due'))],
                ['Entrega', F::date(data_get($document, 'delivery_date'))],
                ['Moneda', data_get($document, 'currency_type.description')],
                ['Condición de pago', data_get($document, 'payment_method_type.description')],
            ],
            'refs' => array_merge([
                ['Oportunidad de venta', data_get($document, 'sale_opportunity.number_full')],
                ['N° de cuenta', data_get($document, 'account_number')],
            ], self::guides($document)),
            'notes' => [
                ['Descripción', data_get($document, 'description')],
                ['Información adicional', is_array(data_get($document, 'additional_information')) ? implode("\n", array_filter(data_get($document, 'additional_information'))) : data_get($document, 'additional_information')],
            ],
            'totals' => F::totals($document),
            'grand' => data_get($document, 'total'),
            'payments' => $payments,
            'accounts' => true,
            'seller' => self::seller($document),
            'terms' => data_get($document, 'terms_condition'),
        ];
    }

    public static function orderNote($document): array
    {
        return self::base($document, 'PEDIDO', self::padded(data_get($document, 'prefix'), data_get($document, 'id'))) + [
            'party_label' => 'Cliente',
            'party' => data_get($document, 'customer'),
            'party_extra' => [['Dirección de envío', data_get($document, 'shipping_address')]],
            'meta' => [
                ['Fecha', F::date(data_get($document, 'date_of_issue'))],
                ['Vence', F::date(data_get($document, 'date_of_due'))],
                ['Entrega', F::date(data_get($document, 'delivery_date'))],
                ['Moneda', data_get($document, 'currency_type.description')],
                ['Condición de pago', data_get($document, 'payment_method_type.description')],
            ],
            'refs' => self::guides($document),
            'notes' => [['Observación', data_get($document, 'observation')]],
            'totals' => F::totals($document),
            'grand' => data_get($document, 'total'),
            'accounts' => true,
            'seller' => self::seller($document),
        ];
    }

    public static function purchase($document): array
    {
        // El recuadro lleva nuestro RUC: el comprobante del proveedor va como dato, no como título.
        return self::base($document, 'COMPRA', F::number(data_get($document, 'series'), data_get($document, 'number')) ?? '') + [
            'party_label' => 'Proveedor',
            'party' => data_get($document, 'supplier'),
            'meta' => [
                ['Comprobante', data_get($document, 'document_type.description')],
                ['Fecha', F::date(data_get($document, 'date_of_issue'))],
                ['Vence', F::date(data_get($document, 'date_of_due'))],
                ['Moneda', data_get($document, 'currency_type.description')],
            ],
            'refs' => [['Orden de compra', data_get($document, 'purchase_order.number_full')]],
            'totals' => F::totals($document),
            'grand' => data_get($document, 'total'),
            'seller' => data_get($document, 'user.name'),
            'seller_label' => 'Registrado por',
        ];
    }

    public static function purchaseOrder($document): array
    {
        return self::base($document, 'ORDEN DE COMPRA', self::padded(data_get($document, 'prefix'), data_get($document, 'id'))) + [
            'party_label' => 'Proveedor',
            'party' => data_get($document, 'supplier'),
            'party_extra' => [['Dirección de entrega', data_get($document, 'shipping_address')]],
            'meta' => [
                ['Fecha', F::date(data_get($document, 'date_of_issue'))],
                ['Entrega', F::date(data_get($document, 'date_of_due'))],
                ['Moneda', data_get($document, 'currency_type.description')],
                ['Condición de pago', data_get($document, 'payment_method_type.description')],
            ],
            'refs' => [
                ['Cotización de compra', data_get($document, 'purchase_quotation.identifier')],
                ['Oportunidad de venta', data_get($document, 'sale_opportunity.number_full')],
            ],
            'notes' => [['Descripción', data_get($document, 'description')]],
            'totals' => F::totals($document),
            'grand' => data_get($document, 'total'),
            'seller' => data_get($document, 'user.name'),
            'seller_label' => 'Emitido por',
        ];
    }

    public static function purchaseQuotation($document): array
    {
        return self::base($document, 'SOLICITUD DE COTIZACIÓN', self::padded(data_get($document, 'prefix'), data_get($document, 'id'))) + [
            'meta' => [['Fecha', F::date(data_get($document, 'date_of_issue'))]],
            'show_prices' => false,
            'seller' => data_get($document, 'user.name'),
            'seller_label' => 'Solicitado por',
            'legend' => 'Le agradeceremos enviar su cotización por los productos indicados.',
        ];
    }

    public static function contract($document): array
    {
        return self::base($document, 'CONTRATO', self::padded(data_get($document, 'prefix'), data_get($document, 'id'))) + [
            'party_label' => 'Cliente',
            'party' => data_get($document, 'customer'),
            'party_extra' => [['Dirección de envío', data_get($document, 'shipping_address')]],
            'meta' => [
                ['Fecha', F::date(data_get($document, 'date_of_issue'))],
                ['Vence', F::date(data_get($document, 'date_of_due'))],
                ['Entrega', F::date(data_get($document, 'delivery_date'))],
                ['Moneda', data_get($document, 'currency_type.description')],
                ['Condición de pago', data_get($document, 'payment_method_type.description')],
            ],
            'refs' => [
                ['Cotización', data_get($document, 'quotation.number_full')],
                ['N° de cuenta', data_get($document, 'account_number')],
            ],
            'notes' => [['Descripción', data_get($document, 'description')]],
            'totals' => F::totals($document),
            'grand' => data_get($document, 'total'),
            'accounts' => true,
            'seller' => self::seller($document),
            'terms' => data_get($document, 'terms_condition'),
        ];
    }

    public static function saleOpportunity($document): array
    {
        return self::base($document, 'OPORTUNIDAD DE VENTA', self::padded(data_get($document, 'prefix'), data_get($document, 'id'))) + [
            'party_label' => 'Cliente',
            'party' => data_get($document, 'customer'),
            'party_extra' => [['Contacto', data_get($document, 'person.contact')]],
            'meta' => [
                ['Fecha', F::date(data_get($document, 'date_of_issue'))],
                ['Vence', F::date(data_get($document, 'date_of_due'))],
                ['Moneda', data_get($document, 'currency_type.description')],
            ],
            'refs' => [
                ['Cotización', data_get($document, 'quotation.number_full')],
                ['Orden de compra', data_get($document, 'purchase_order.number_full')],
            ],
            'notes' => [
                ['Detalle', data_get($document, 'detail')],
                ['Observación', data_get($document, 'observation')],
            ],
            'totals' => F::totals($document),
            'grand' => data_get($document, 'total'),
            'seller' => self::seller($document),
        ];
    }
}
