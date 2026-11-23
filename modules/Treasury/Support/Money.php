<?php

namespace Modules\Treasury\Support;

/**
 * Single place for monetary rounding and formatting across Treasury services (§6).
 */
final class Money
{
    public const MIN_AMOUNT = 0.01;
    public const MAX_AMOUNT = 9999999999.99;

    public static function round(float $amount): float
    {
        return round($amount, 2);
    }

    public static function format(float $amount): string
    {
        return number_format(self::round($amount), 2, '.', '');
    }
}
