<?php

namespace App\Services\Payments;

use App\CoreFacturalo\Helpers\Igv\IgvRate;
use App\Http\Controllers\Tenant\Api\ServiceController;
use App\Models\Tenant\Item;
use InvalidArgumentException;
use Modules\Inventory\Models\InventoryConfiguration;

/**
 * Recomputes a storefront cart total from database prices. Client-sent prices are ignored.
 */
class CartPricing
{
    /**
     * IGV applies only to taxed affectation types (catalog 07: 10-17). Exonerated (20-21),
     * unaffected (30-37) and export (40) items are never charged IGV. A missing value is treated
     * as taxed so legacy rows keep their previous behaviour.
     */
    public static function isTaxed($affectationType): bool
    {
        if ($affectationType === null || $affectationType === '') {
            return true;
        }

        $code = (int) $affectationType;

        return $code >= 10 && $code <= 17;
    }

    /**
     * Single source of truth for the price a buyer sees and pays for one unit.
     *
     * @param object   $item         sale_unit_price, has_igv, igv_general_rate, currency_type_id, sale_affectation_igv_type_id
     * @param callable $igvRate      fn(bool $generalRate): float percentage
     * @return array{shown:float,pen:float,igv_percentage:float}
     *         shown: unit price in the item currency (IGV included when taxed);
     *         pen: the same price in soles (the amount charged)
     */
    public static function unitPrices($item, float $exchangeRate, callable $igvRate): array
    {
        $unit = (float) $item->sale_unit_price;
        $percentage = 0.0;

        if (self::isTaxed($item->sale_affectation_igv_type_id ?? null)) {
            $percentage = (float) $igvRate((bool) ($item->igv_general_rate ?? false));
            if (! $item->has_igv) {
                $unit *= 1 + $percentage / 100;
            }
        }

        $shown = round($unit, 2);
        $pen = $item->currency_type_id === 'PEN' ? $shown : round($unit * $exchangeRate, 2);

        return ['shown' => $shown, 'pen' => $pen, 'igv_percentage' => $percentage];
    }

    public static function defaultIgvRate(): callable
    {
        return function ($general) {
            return IgvRate::forTenant(null, (bool) $general);
        };
    }

    /**
     * @param array      $lines     [['id' => .., 'cantidad' => ..], ...] as sent by the client
     * @param mixed      $items     records keyed by id (id, has_igv, igv_general_rate, currency_type_id, sale_unit_price)
     *                              already filtered to active store items
     * @param callable   $igvRate   fn(bool $generalRate): float percentage
     * @param callable|null $availableStock fn($item): ?float units available, null when stock is not controlled
     * @return array{total:float,lines:array,exchange_rate_sale:float}
     * @throws InvalidArgumentException
     */
    public static function total(array $lines, $items, float $exchangeRate, callable $igvRate, ?callable $availableStock = null): array
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

            if ($availableStock) {
                $available = $availableStock($item);
                if ($available !== null && $qty > $available) {
                    throw new InvalidArgumentException(sprintf(
                        'No hay stock suficiente de "%s". Disponible: %s.',
                        $item->name ?? ($item->description ?? ''),
                        rtrim(rtrim(number_format(max(0, $available), 2, '.', ''), '0'), '.') ?: '0'
                    ));
                }
            }

            $prices = self::unitPrices($item, $exchangeRate, $igvRate);
            $shown = $prices['shown'];
            $unit = $prices['pen'];

            $subtotal = round($unit * $qty, 2);
            $total += $subtotal;
            $detail[] = [
                'id' => $id,
                'internal_id' => $item->internal_id ?? null,
                'name' => $item->name ?? ($item->description ?? ''),
                'description' => $item->description ?? '',
                'unit_type_id' => $item->unit_type_id ?? 'NIU',
                'currency_type_id' => $item->currency_type_id,
                'sale_unit_price' => $shown,
                'unit_pen' => $unit,
                'exchange_rate_sale' => $item->currency_type_id === 'PEN' ? '' : $exchangeRate,
                'cantidad' => $qty,
                'sub_total' => $subtotal,
                'sale_affectation_igv_type_id' => (string) ($item->sale_affectation_igv_type_id ?? '10'),
                'igv_general_rate' => (bool) ($item->igv_general_rate ?? false),
                'igv_percentage' => $prices['igv_percentage'],
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

        $control = (bool) optional(InventoryConfiguration::first())->stock_control;
        $stock = function ($item) use ($control) {
            if (! $control || ($item->unit_type_id ?? 'NIU') === 'ZZ') {
                return null;
            }

            return (float) $item->warehouses->sum('stock');
        };

        return self::total($lines, $items, $exchangeRate, self::defaultIgvRate(), $stock);
    }
}
