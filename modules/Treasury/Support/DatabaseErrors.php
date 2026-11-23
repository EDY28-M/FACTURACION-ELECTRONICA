<?php

namespace Modules\Treasury\Support;

use Illuminate\Database\QueryException;

/**
 * Driver-aware helpers to classify database errors without swallowing unrelated failures.
 */
class DatabaseErrors
{
    /**
     * True only for unique/duplicate-key violations (MySQL 1062, PostgreSQL 23505, SQLite UNIQUE constraint).
     * Other integrity errors (NOT NULL, foreign keys) and generic failures are NOT duplicates.
     */
    public static function isDuplicateKey(QueryException $e): bool
    {
        $info = is_array($e->errorInfo) ? $e->errorInfo : [];
        $sqlState = (string) ($info[0] ?? $e->getCode());
        $driverCode = $info[1] ?? null;

        if ($sqlState === '23505') {
            return true;
        }

        if ($driverCode !== null && (int) $driverCode === 1062) {
            return true;
        }

        $message = $e->getMessage();

        return stripos($message, 'UNIQUE constraint failed') !== false
            || stripos($message, 'Duplicate entry') !== false;
    }
}
