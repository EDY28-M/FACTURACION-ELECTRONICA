<?php

namespace App\Support;

use App\Models\Tenant\Company;
use App\Models\Tenant\Establishment;

/**
 * Datos comunes de los correos salientes: remitente, "responder a" y formatos.
 *
 * Todo es tolerante a fallos: un dato que falte nunca debe impedir que salga un correo.
 */
class MailBrand
{
    /** Dirección verificada en el proveedor SMTP (no el usuario de login del SMTP). */
    public static function address(): string
    {
        return (string) config('mail.from.address');
    }

    /** Nombre visible del remitente: el de la empresa; si no existe, el $fallback. */
    public static function name(string $fallback = ''): string
    {
        try {
            $company = Company::first();
            $name = $company ? ($company->trade_name ?: $company->name) : null;
        } catch (\Throwable $e) {
            $name = null;
        }

        return (string) ($name ?: ($fallback ?: config('mail.from.name')));
    }

    /** Correo del establecimiento para que las respuestas del cliente lleguen a un buzón real. */
    public static function replyTo(): ?string
    {
        try {
            $email = optional(Establishment::first())->email;
        } catch (\Throwable $e) {
            return null;
        }

        return ($email && filter_var($email, FILTER_VALIDATE_EMAIL)) ? $email : null;
    }

    public static function money($amount, $currency = 'PEN'): ?string
    {
        if ($amount === null || $amount === '' || ! is_numeric($amount)) {
            return null;
        }

        return ($currency === 'USD' ? 'US$ ' : 'S/ ').number_format((float) $amount, 2, '.', ',');
    }

    public static function date($date): ?string
    {
        if (! $date) {
            return null;
        }
        try {
            return ($date instanceof \DateTimeInterface ? $date : new \DateTime((string) $date))->format('d/m/Y');
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** "SERIE-NUMERO", sin guiones sobrantes si falta una parte. */
    public static function number($left, $right): ?string
    {
        $n = trim(trim((string) $left).'-'.trim((string) $right), '-');

        return $n === '' ? null : $n;
    }
}
