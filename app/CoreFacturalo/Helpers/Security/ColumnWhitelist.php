<?php

namespace App\CoreFacturalo\Helpers\Security;

/**
 * Validates dynamic filter column names against an explicit whitelist so
 * untrusted request parameters can never inject arbitrary columns or JSON
 * path expressions (e.g. `password->x`) into SQL WHERE clauses.
 */
class ColumnWhitelist
{
    public const IDENTIFIER_REGEX = '/^[a-zA-Z_][a-zA-Z0-9_]{0,63}$/';

    public static function isValidIdentifier($column): bool
    {
        if (!is_string($column) || $column === '') {
            return false;
        }

        return preg_match(self::IDENTIFIER_REGEX, $column) === 1;
    }

    /**
     * Returns $requested when it is present in $allowed, otherwise $default.
     *
     * @param  mixed     $requested
     * @param  string[]  $allowed
     */
    public static function resolve($requested, array $allowed, string $default): string
    {
        $candidate = is_string($requested) ? trim($requested) : '';

        if ($candidate !== '' && self::isValidIdentifier($candidate) && in_array($candidate, $allowed, true)) {
            return $candidate;
        }

        return $default;
    }
}
