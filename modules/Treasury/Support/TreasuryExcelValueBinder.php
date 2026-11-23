<?php

namespace Modules\Treasury\Support;

use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;
use PhpOffice\PhpSpreadsheet\Shared\StringHelper;

/**
 * Custom PhpSpreadsheet value binder for Treasury Excel exports:
 * - Neutralizes Excel formula injection (strings starting with =, +, -, @, tab, CR) unless they are pure signed numbers.
 * - Preserves leading zeros on SUNAT code / correlative strings (e.g. 000001, 001, 003, 008).
 * - Preserves real numeric monetary cells as DataType::TYPE_NUMERIC.
 */
class TreasuryExcelValueBinder extends DefaultValueBinder
{
    public function bindValue(Cell $cell, $value)
    {
        if (is_string($value)) {
            $sanitized = StringHelper::sanitizeUTF8($value);
            $trimmed = trim($sanitized);

            // Preserve leading zeros on SUNAT codes, correlatives, and zero-prefixed identifiers.
            if ($trimmed !== '' && preg_match('/^0\d+$/', $trimmed) === 1) {
                $cell->setValueExplicit($trimmed, DataType::TYPE_STRING);

                return true;
            }

            // Neutralize potential spreadsheet formula injection while allowing plain signed numbers (e.g. -123.45).
            if (self::isDangerousFormula($sanitized)) {
                $cell->setValueExplicit("'" . $sanitized, DataType::TYPE_STRING);

                return true;
            }
        }

        return parent::bindValue($cell, $value);
    }

    public static function isDangerousFormula(string $value): bool
    {
        if ($value === '') {
            return false;
        }

        $trimmed = trim($value);
        if (preg_match('/^[+-]?\d+(\.\d+)?$/', $trimmed) === 1) {
            return false;
        }

        return preg_match('/^[\t\r\n ]*[=+\-@]/', $value) === 1
            || $value[0] === "\t"
            || $value[0] === "\r";
    }
}
