<?php

namespace Modules\Treasury\Support;

/**
 * Pure formatting helpers for the PLE TXT files (RS 286-2009/SUNAT Anexo 2, general rules sheet):
 * - text fields cannot contain the characters | / \
 * - alphanumeric fields only accept letters, digits and ( ) , . -
 * - amounts use a dot, at most 2 decimals and no thousands separator
 * - dates are DD/MM/AAAA
 */
class PleFormatter
{
    private const TRANSLITERATION = [
        'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a', 'ã' => 'a', 'å' => 'a',
        'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e',
        'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i',
        'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o', 'õ' => 'o',
        'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u',
        'ñ' => 'n', 'ç' => 'c',
        'Á' => 'A', 'À' => 'A', 'Ä' => 'A', 'Â' => 'A', 'Ã' => 'A', 'Å' => 'A',
        'É' => 'E', 'È' => 'E', 'Ë' => 'E', 'Ê' => 'E',
        'Í' => 'I', 'Ì' => 'I', 'Ï' => 'I', 'Î' => 'I',
        'Ó' => 'O', 'Ò' => 'O', 'Ö' => 'O', 'Ô' => 'O', 'Õ' => 'O',
        'Ú' => 'U', 'Ù' => 'U', 'Ü' => 'U', 'Û' => 'U',
        'Ñ' => 'N', 'Ç' => 'C',
        '“' => '"', '”' => '"', '‘' => "'", '’' => "'", '–' => '-', '—' => '-', '°' => 'o', 'º' => 'o', 'ª' => 'a',
    ];

    /**
     * Free text: no | / \ , no control characters, collapsed whitespace, truncated to $max characters.
     */
    public static function text(?string $value, int $max, bool $asciiOnly = true): string
    {
        $value = self::normalize((string) $value, $asciiOnly);
        $value = str_replace(['|', '/', '\\'], ' ', $value);
        $value = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? '';
        $value = trim((string) preg_replace('/\s+/u', ' ', $value));

        return self::truncate($value, $max);
    }

    /**
     * Alphanumeric field: letters, digits and ( ) , . - only (everything else is dropped).
     */
    public static function alnum(?string $value, int $max): string
    {
        $value = self::normalize((string) $value, true);
        $value = (string) preg_replace('/[^A-Za-z0-9(),.\-]/', '', $value);

        return self::truncate($value, $max);
    }

    public static function amount(float $value): string
    {
        $value = round($value, 2);
        if ($value == 0.0) {
            $value = 0.0; // never "-0.00"
        }

        return number_format($value, 2, '.', '');
    }

    public static function date(string $ymd): string
    {
        return substr($ymd, 8, 2) . '/' . substr($ymd, 5, 2) . '/' . substr($ymd, 0, 4);
    }

    /**
     * Splits a "SERIES-NUMBER" label at its last dash. Without a dash everything is the number.
     *
     * @return array{0: string, 1: string}
     */
    public static function splitDocumentLabel(string $label): array
    {
        $label = trim($label);
        $pos = strrpos($label, '-');
        if ($pos === false) {
            return ['', $label];
        }

        return [substr($label, 0, $pos), substr($label, $pos + 1)];
    }

    /**
     * Peruvian RUC check digit (modulo 11).
     */
    public static function isValidRuc(string $ruc): bool
    {
        if (!preg_match('/^\d{11}$/', $ruc)) {
            return false;
        }

        $weights = [5, 4, 3, 2, 7, 6, 5, 4, 3, 2];
        $sum = 0;
        foreach ($weights as $index => $weight) {
            $sum += ((int) $ruc[$index]) * $weight;
        }

        return (int) $ruc[10] === (11 - ($sum % 11)) % 10;
    }

    /**
     * Applies the "tipo y número de documento" rules of the general rules sheet. Anything that does not satisfy the
     * rule of its type becomes the allowed "no existe" value ('-', '-') instead of an invalid identity.
     *
     * @return array{0: string, 1: string}
     */
    public static function identityDocument(?string $type, ?string $number): array
    {
        $type = strtoupper(trim((string) $type));
        $number = trim((string) $number);
        if ($type === '' || $number === '' || $number === '-') {
            return ['-', '-'];
        }

        switch ($type) {
            case '1': // DNI: 8 digits
                $valid = (bool) preg_match('/^\d{8}$/', $number);
                break;
            case '6': // RUC: 11 digits + modulo 11
                $valid = self::isValidRuc($number);
                break;
            case 'A': // Cédula diplomática: 15 digits
                $valid = (bool) preg_match('/^\d{15}$/', $number);
                break;
            case '4': // Carné de extranjería: up to 12 alphanumeric
            case '7': // Pasaporte: up to 12 alphanumeric
                $valid = (bool) preg_match('/^[A-Za-z0-9]{1,12}$/', $number);
                break;
            case '0': // Otros: up to 15 alphanumeric
                $valid = (bool) preg_match('/^[A-Za-z0-9]{1,15}$/', $number);
                break;
            default:
                $valid = false;
        }

        return $valid ? [$type, $number] : ['-', '-'];
    }

    private static function normalize(string $value, bool $asciiOnly): string
    {
        $value = (string) mb_scrub($value, 'UTF-8');
        // Control characters (line breaks, tabs) become spaces before anything else is dropped.
        $value = (string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $value);
        if (!$asciiOnly) {
            return $value;
        }

        $value = strtr($value, self::TRANSLITERATION);

        return (string) preg_replace('/[^\x20-\x7E]/', '', $value);
    }

    private static function truncate(string $value, int $max): string
    {
        if (mb_strlen($value, 'UTF-8') <= $max) {
            return $value;
        }

        return rtrim(mb_substr($value, 0, $max, 'UTF-8'));
    }
}
