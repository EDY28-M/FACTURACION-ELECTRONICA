<?php

namespace App\CoreFacturalo\Helpers\Sunat;

/**
 * Pure helpers to turn the user's search text into safe query parts (no DB access).
 */
class ProductCodeSearch
{
    const MIN_LENGTH = 3;

    /**
     * @return array|null ['type' => 'code', 'code' => '10101501'] | ['type' => 'text', 'terms' => [...]] | null
     */
    public static function parse($q)
    {
        $q = trim((string) $q);

        if (preg_match('/^\d{8}$/', $q)) {
            return ['type' => 'code', 'code' => $q];
        }
        if (preg_match('/^\d{3,7}$/', $q)) {
            return ['type' => 'prefix', 'prefix' => $q];
        }
        if (mb_strlen($q) < self::MIN_LENGTH) {
            return null;
        }

        $terms = array_values(array_filter(preg_split('/\s+/u', $q), function ($t) {
            return $t !== '';
        }));

        return ['type' => 'text', 'terms' => array_slice($terms, 0, 6)];
    }

    /**
     * Escape LIKE wildcards so user input is matched literally.
     */
    public static function escapeLike($value)
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    /**
     * Run the search against the model (system connection, bound parameters only).
     */
    public static function run($q, $limit = 30)
    {
        $parsed = self::parse($q);
        if ($parsed === null) {
            return [];
        }

        $query = \App\Models\System\SunatProductCode::query();

        if ($parsed['type'] === 'code') {
            $query->where('code', $parsed['code']);
        } elseif ($parsed['type'] === 'prefix') {
            $query->where('code', 'like', self::escapeLike($parsed['prefix']).'%');
        } else {
            foreach ($parsed['terms'] as $term) {
                $query->where('description', 'like', '%'.self::escapeLike($term).'%');
            }
            // Class rows first, then product rows, alphabetical.
            $query->orderByRaw("level = 'class' desc")->orderBy('description');
        }

        $rows = $query->orderBy('code')->limit($limit)->get(['code', 'description', 'level']);

        $classCodes = $rows->map(function ($r) {
            return substr($r->code, 0, 6).'00';
        })->unique()->values()->all();
        $classNames = $classCodes
            ? \App\Models\System\SunatProductCode::whereIn('code', $classCodes)->pluck('description', 'code')
            : collect();

        return $rows->map(function ($r) use ($classNames) {
            $classCode = substr($r->code, 0, 6).'00';
            return [
                'code' => $r->code,
                'description' => $r->description,
                'level' => $r->level,
                'class_name' => $r->level === 'product' ? ($classNames[$classCode] ?? null) : null,
            ];
        })->all();
    }
}
