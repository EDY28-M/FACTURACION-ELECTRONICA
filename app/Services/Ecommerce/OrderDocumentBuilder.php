<?php

namespace App\Services\Ecommerce;

use InvalidArgumentException;

/**
 * Builds the /api/documents payload of a storefront order on the server.
 *
 * Nothing comes from the browser: prices, quantities and affectation come from the order lines
 * stored by CartPricing at checkout, the customer from the validated checkout customer. Only
 * onerous operations are supported: 10 (taxed), 20 (exonerated) and 30 (unaffected).
 */
class OrderDocumentBuilder
{
    /**
     * @param object $order   customer (object|array), items (array of lines), total
     * @param mixed  $items   Item records keyed by id, used to complete legacy lines
     * @param callable|null $igvRate fn(bool $generalRate): float, only for legacy lines without igv_percentage
     * @throws InvalidArgumentException with a message safe to show to the back office user
     */
    public static function build($order, $items, string $date, string $time, ?callable $igvRate = null): array
    {
        $customer = (array) $order->customer;
        $lines = array_map(function ($line) {
            return (array) $line;
        }, (array) $order->items);

        if (! $lines) {
            throw new InvalidArgumentException('El pedido no tiene productos.');
        }

        $voucher = self::voucherType($customer);
        $identityType = (string) ($customer['codigo_tipo_documento_identidad'] ?? '');
        $identityNumber = (string) ($customer['numero_documento'] ?? '');
        $name = trim((string) ($customer['apellidos_y_nombres_o_razon_social'] ?? ''));

        if ($voucher === '01' && $identityType !== '6') {
            throw new InvalidArgumentException('Una factura requiere el RUC del cliente.');
        }
        if ($name === '' || $identityNumber === '') {
            throw new InvalidArgumentException('El pedido no tiene los datos del cliente completos.');
        }

        $rows = [];
        $totals = ['taxed' => 0.0, 'exonerated' => 0.0, 'unaffected' => 0.0, 'igv' => 0.0, 'value' => 0.0, 'total' => 0.0];

        foreach ($lines as $line) {
            $item = $items[(int) ($line['id'] ?? 0)] ?? null;
            $quantity = (float) ($line['cantidad'] ?? 0);
            $price = round((float) ($line['unit_pen'] ?? 0), 2);
            $label = $line['name'] ?? ($line['description'] ?? 'producto');

            if ($quantity <= 0 || $price <= 0) {
                throw new InvalidArgumentException("El producto \"{$label}\" del pedido no tiene precio o cantidad válidos.");
            }

            $affectation = (string) ($line['sale_affectation_igv_type_id'] ?? ($item->sale_affectation_igv_type_id ?? '10'));
            if (! in_array($affectation, ['10', '20', '30'], true)) {
                throw new InvalidArgumentException("El producto \"{$label}\" tiene una afectación al IGV que no se puede facturar desde la tienda.");
            }

            $percentage = 0.0;
            if ($affectation === '10') {
                if (isset($line['igv_percentage'])) {
                    $percentage = (float) $line['igv_percentage'];
                } else {
                    $general = (bool) ($line['igv_general_rate'] ?? ($item->igv_general_rate ?? false));
                    $percentage = $igvRate ? (float) $igvRate($general) : 18.0;
                }
            }
            $total = round($price * $quantity, 2);
            $divisor = 1 + $percentage / 100;
            $value = $affectation === '10' ? round($total / $divisor, 2) : $total;
            $igv = round($total - $value, 2);
            $unitValue = $affectation === '10' ? round($price / $divisor, 6) : $price;

            $totals[['10' => 'taxed', '20' => 'exonerated', '30' => 'unaffected'][$affectation]] += $value;
            $totals['igv'] += $igv;
            $totals['value'] += $value;
            $totals['total'] += $total;

            $rows[] = [
                'codigo_interno' => (string) ($line['internal_id'] ?? ($item->internal_id ?? '')),
                'descripcion' => (string) ($line['description'] ?? ($item->description ?? '')),
                'codigo_producto_sunat' => '',
                'unidad_de_medida' => (string) ($line['unit_type_id'] ?? ($item->unit_type_id ?? 'NIU')),
                'cantidad' => $quantity,
                'valor_unitario' => $unitValue,
                'codigo_tipo_precio' => '01',
                'precio_unitario' => $price,
                'codigo_tipo_afectacion_igv' => $affectation,
                'total_base_igv' => $value,
                'porcentaje_igv' => $percentage,
                'total_igv' => $igv,
                'total_impuestos' => $igv,
                'total_valor_item' => $value,
                'total_item' => $total,
            ];
        }

        $totals = array_map(function ($value) {
            return round($value, 2);
        }, $totals);

        if (abs($totals['total'] - round((float) $order->total, 2)) > 0.01) {
            throw new InvalidArgumentException('El total de los productos no coincide con el total del pedido.');
        }

        return [
            'acciones' => ['enviar_email' => true, 'formato_pdf' => 'a4'],
            'serie_documento' => '',
            'numero_documento' => '#',
            'fecha_de_emision' => $date,
            'hora_de_emision' => $time,
            'codigo_tipo_operacion' => '0101',
            'codigo_tipo_documento' => $voucher,
            'codigo_tipo_moneda' => 'PEN',
            'fecha_de_vencimiento' => $date,
            'datos_del_cliente_o_receptor' => [
                'codigo_tipo_documento_identidad' => $identityType,
                'numero_documento' => $identityNumber,
                'apellidos_y_nombres_o_razon_social' => $name,
                'codigo_pais' => 'PE',
                'ubigeo' => '150101',
                'direccion' => (string) ($customer['direccion'] ?? ''),
                'correo_electronico' => (string) ($customer['correo_electronico'] ?? ''),
                'telefono' => (string) ($customer['telefono'] ?? ''),
            ],
            'totales' => [
                'total_exportacion' => 0.00,
                'total_operaciones_gravadas' => $totals['taxed'],
                'total_operaciones_inafectas' => $totals['unaffected'],
                'total_operaciones_exoneradas' => $totals['exonerated'],
                'total_operaciones_gratuitas' => 0.00,
                'total_igv' => $totals['igv'],
                'total_impuestos' => $totals['igv'],
                'total_valor' => $totals['value'],
                'total_venta' => $totals['total'],
            ],
            'items' => $rows,
        ];
    }

    /**
     * 01 factura / 03 boleta, from the voucher the buyer chose at checkout (legacy orders: from the identity type).
     */
    public static function voucherType(array $customer): string
    {
        $voucher = (string) ($customer['identity_document_type_id'] ?? '');
        if ($voucher === '') {
            $voucher = ((string) ($customer['codigo_tipo_documento_identidad'] ?? '')) === '6' ? '6' : '1';
        }

        return $voucher === '6' ? '01' : '03';
    }

    /**
     * True when every stored line carries the server-computed price (orders created after the
     * server-side pricing hardening); older lines must be repriced by the caller.
     */
    public static function hasServerPrices($order): bool
    {
        foreach ((array) $order->items as $line) {
            $line = (array) $line;
            if (! isset($line['unit_pen']) || ! is_numeric($line['unit_pen'])) {
                return false;
            }
        }

        return true;
    }
}
