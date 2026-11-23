<?php

namespace App\Services\Ecommerce;

use App\Http\Controllers\Tenant\Api\ServiceController;
use App\Services\Payments\CartPricing;

/**
 * Public (storefront) representation of an item.
 *
 * Only whitelisted, buyer-facing fields are exposed: never purchase price, profit, stock figures,
 * warehouses, barcodes or internal costs. The displayed price comes from CartPricing, the same
 * code the cart quote uses, so list, detail, quick view, search bar and cart always agree.
 */
class StoreItemPresenter
{
    /** @var float|null|false false = not resolved yet */
    private static $rate = false;

    /** @var callable|null */
    private static $rateResolver;

    /** @var callable|null */
    private static $igvResolver;

    public static function flush(): void
    {
        self::$rate = false;
        self::$rateResolver = null;
        self::$igvResolver = null;
    }

    /** Test seam. */
    public static function resolveWith(?callable $rate, ?callable $igv = null): void
    {
        self::$rate = false;
        self::$rateResolver = $rate;
        self::$igvResolver = $igv;
    }

    /**
     * Sale exchange rate for USD items, or null when it cannot be obtained (display falls back
     * to the item currency; the cart quote refuses USD items without a rate anyway).
     */
    public static function exchangeRate(): ?float
    {
        if (self::$rate === false) {
            try {
                $resolver = self::$rateResolver ?: function () {
                    $rate = app(ServiceController::class)->exchangeRateTest(date('Y-m-d'));

                    return $rate['sale'] ?? null;
                };
                $value = (float) $resolver();
                self::$rate = $value > 0 ? $value : null;
            } catch (\Throwable $e) {
                self::$rate = null;
            }
        }

        return self::$rate;
    }

    /**
     * Price shown to the buyer: soles when it can be converted, otherwise the item currency.
     *
     * @return array{amount:float,currency_type_id:string,currency_type_symbol:string}
     */
    public static function price($item): array
    {
        $igv = self::$igvResolver ?: CartPricing::defaultIgvRate();
        $needsRate = $item->currency_type_id !== 'PEN';
        $rate = $needsRate ? self::exchangeRate() : 1.0;

        if ($needsRate && ! $rate) {
            $prices = CartPricing::unitPrices($item, 1.0, $igv);

            return [
                'amount' => $prices['shown'],
                'currency_type_id' => (string) $item->currency_type_id,
                'currency_type_symbol' => (string) optional($item->currency_type)->symbol,
            ];
        }

        $prices = CartPricing::unitPrices($item, (float) $rate, $igv);

        return ['amount' => $prices['pen'], 'currency_type_id' => 'PEN', 'currency_type_symbol' => 'S/'];
    }

    /**
     * Buyer-facing fields; also what the cart stores in the browser.
     */
    public static function present($item): array
    {
        $price = self::price($item);

        return [
            'id' => (int) $item->id,
            'internal_id' => $item->internal_id,
            'unit_type_id' => $item->unit_type_id,
            'description' => $item->description,
            'name' => $item->name,
            'image' => $item->image,
            'image_medium' => $item->image_medium,
            'image_small' => $item->image_small,
            'sale_affectation_igv_type_id' => (string) ($item->sale_affectation_igv_type_id ?? '10'),
            'igv_general_rate' => (bool) $item->igv_general_rate,
            'currency_type_id' => $price['currency_type_id'],
            'currency_type_symbol' => $price['currency_type_symbol'],
            'sale_unit_price' => $price['amount'],
        ];
    }

    /**
     * Payload for the autocomplete bar.
     */
    public static function presentForSearch($item): array
    {
        $price = self::price($item);
        $image = function ($file) {
            return ($file && $file !== 'imagen-no-disponible.jpg')
                ? asset('storage/uploads/items/'.$file)
                : asset('/logo/imagen-no-disponible.jpg');
        };

        return [
            'id' => (int) $item->id,
            'internal_id' => $item->internal_id,
            'description' => $item->description,
            'name' => $item->name,
            'image_url_small' => $image($item->image_small),
            'sale_unit_price' => $price['currency_type_symbol'].' '.number_format($price['amount'], 2, '.', ''),
        ];
    }
}
