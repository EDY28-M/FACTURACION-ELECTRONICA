<?php

namespace App\CoreFacturalo\Helpers\Pos;

use Illuminate\Database\Eloquent\Builder;

/**
 * Multi-token POS product search across description, secondary name, model,
 * internal code, barcode, category, and brand.
 *
 * Every token must match (AND) at least one searchable field (OR inside grouped
 * parentheses), so "adaptador tp-link", "tp-link usb" or "AU-008" all work in
 * any word order without ever breaking outer WHERE constraints (active, warehouse,
 * category, series_enabled).
 */
class ItemSearch
{
    private const MAX_TOKENS = 8;

    /**
     * Splits raw user input into distinct non-empty search tokens.
     *
     * @return string[]
     */
    public static function tokenize(?string $input): array
    {
        $trimmed = trim((string) $input);
        if ($trimmed === '') {
            return [];
        }

        $parts = preg_split('/\s+/u', $trimmed, -1, PREG_SPLIT_NO_EMPTY);
        if (!$parts) {
            return [];
        }

        $unique = [];
        foreach ($parts as $part) {
            $key = mb_strtolower($part, 'UTF-8');
            if (!isset($unique[$key])) {
                $unique[$key] = $part;
            }
            if (count($unique) >= self::MAX_TOKENS) {
                break;
            }
        }

        return array_values($unique);
    }

    /**
     * Escapes SQL LIKE wildcards so literal %, _, and \ match verbatim.
     */
    public static function escapeLike(string $token): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $token);
    }

    /**
     * Applies grouped search constraints onto an Item query builder.
     */
    public static function apply(Builder $query, ?string $input, bool $barcodeMode = false): Builder
    {
        $raw = trim((string) $input);
        if ($raw === '') {
            return $query;
        }

        $tokens = self::tokenize($raw);
        if (empty($tokens)) {
            return $query;
        }

        return $query->where(function (Builder $outer) use ($tokens, $raw, $barcodeMode) {
            if ($barcodeMode) {
                $outer->where('barcode', $raw)
                    ->orWhere('internal_id', $raw)
                    ->orWhere(function (Builder $multi) use ($tokens) {
                        self::applyTokens($multi, $tokens);
                    });
                return;
            }

            self::applyTokens($outer, $tokens);
        });
    }

    private static function applyTokens(Builder $query, array $tokens): void
    {
        foreach ($tokens as $token) {
            $like = '%'.self::escapeLike($token).'%';

            $query->where(function (Builder $tokenGroup) use ($like) {
                $tokenGroup->where('description', 'like', $like)
                    ->orWhere('second_name', 'like', $like)
                    ->orWhere('name', 'like', $like)
                    ->orWhere('model', 'like', $like)
                    ->orWhere('internal_id', 'like', $like)
                    ->orWhere('barcode', 'like', $like)
                    ->orWhereHas('category', function (Builder $q) use ($like) {
                        $q->where('name', 'like', $like);
                    })
                    ->orWhereHas('brand', function (Builder $q) use ($like) {
                        $q->where('name', 'like', $like);
                    });
            });
        }
    }
}
