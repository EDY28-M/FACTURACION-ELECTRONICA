<?php

namespace App\Services\Payments;

use App\CoreFacturalo\Helpers\Igv\IgvRate;
use App\Http\Controllers\Tenant\Api\ServiceController;
use App\Models\Tenant\Item;
use InvalidArgumentException;

/**
 * Recomputes a storefront cart total from database prices. Client-sent prices are ignored.
 */
class CartPricing
{
    /**
     * @param array      $lines     [['id' => .., 'cantidad' => ..], ...] as sent by the client
     * @param mixed      $items     records keyed by id (id, has_igv, igv_general_rate, currency_type_id, sale_unit_price)
     *                              already filtered to active store items
     * @param callable   $igvRate   fn(bool $generalRate): float percentage
     * @return array{total:float,lines:array,exchange_rate_sale:float}
     * @throws InvalidArgumentException
     */
    public static function total(array $lines, $items, float $exchangeRate, callable $igvRate): array
    {
        if ($exchangeRate <= 0) {
            throw new InvalidArgumentException('El tipo de cambio no es válido.');
        }

        $quantities = [];
        foreach ($lines as $line) {
            $line = (array) $line;
            $id = $line['id'] ?? null;
            $qty = $line['cantidad'] ?? null;
            if (! is_numeric($id) || ! is_numeric($qty) || (float) $qty < 0.01 || (float) $qty > 100000) {
                throw new InvalidArgumentException('El carrito contiene productos o cantidades no válidos.');
            }
            $quantities[(int) $id] = ($quantities[(int) $id] ?? 0) + (float) $qty;
        }

        if (! $quantities) {
            throw new InvalidArgumentException('El carrito está vacío.');
        }

        $total = 0.0;
        $detail = [];
        foreach ($quantities as $id => $qty) {
            $item = $items[$id] ?? null;
            if (! $item) {
                throw new InvalidArgumentException('El carrito contiene productos no disponibles.');
            }
            // Countable units (unit / service) cannot be fractional; other units need at least 0.01.
            if (in_array($item->unit_type_id ?? 'NIU', ['NIU', 'ZZ'], true) && floor($qty) != $qty) {
                throw new InvalidArgumentException('La cantidad de un producto no es válida.');
            }

            $unit = (float) $item->sale_unit_price;
            if (! $item->has_igv) {
                $unit *= 1 + $igvRate((bool) $item->igv_general_rate) / 100;
            }
            $shown = round($unit, 2);
            if ($item->currency_type_id !== 'PEN') {
                $unit *= $exchangeRate;
            }

            $subtotal = round(round($unit, 2) * $qty, 2);
            $total += $subtotal;
            $detail[] = [
                'id' => $id,
                'internal_id' => $item->internal_id ?? null,
                'name' => $item->name ?? ($item->description ?? ''),
                'description' => $item->description ?? '',
                'unit_type_id' => $item->unit_type_id ?? 'NIU',
                'currency_type_id' => $item->currency_type_id,
                'sale_unit_price' => $shown,
                'unit_pen' => round($unit, 2),
                'exchange_rate_sale' => $item->currency_type_id === 'PEN' ? '' : $exchangeRate,
                'cantidad' => $qty,
                'sub_total' => $subtotal,
            ];
        }

        if (round($total, 2) <= 0) {
            throw new InvalidArgumentException('El monto de la compra no es válido.');
        }

        return ['total' => round($total, 2), 'lines' => $detail, 'exchange_rate_sale' => $exchangeRate];
    }

    /**
     * Loads active store items from the tenant database and prices the cart.
     */
    public static function forStore(array $lines): array
    {
        $ids = array_filter(array_map(function ($line) {
            return is_numeric(((array) $line)['id'] ?? null) ? (int) ((array) $line)['id'] : null;
        }, $lines));

        $items = Item::whereIn('id', $ids)->where('apply_store', 1)->where('active', 1)->get()->keyBy('id');

        $exchangeRate = 1.0;
        if ($items->contains(function ($item) {
            return $item->currency_type_id !== 'PEN';
        })) {
            $rate = app(ServiceController::class)->exchangeRateTest(date('Y-m-d'));
            $exchangeRate = (float) ($rate['sale'] ?? 0);

            if ($exchangeRate <= 0) {
                throw new InvalidArgumentException('No se pudo obtener el tipo de cambio.');
            }
        }

        return self::total($lines, $items, $exchangeRate, function ($general) {
            return IgvRate::forTenant(null, $general);
        });
    }
}
