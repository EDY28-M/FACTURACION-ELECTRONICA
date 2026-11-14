<?php

namespace App\CoreFacturalo\Helpers\Sunat;

use Carbon\Carbon;
use DateTimeInterface;

/**
 * Maximum send deadline to SUNAT/OSE for the electronic invoice (factura) and the credit/debit
 * notes linked to it.
 *
 * Source: SUNAT RS 000003-2023/SUNAT (art. 3 and 4, modifying RS 097-2012, RS 182-2016 and RS 117-2017):
 * the document must be sent on its issue date or up to three calendar days counted from the day
 * after the issue date. Once the deadline passes, what was not sent no longer has the status of
 * electronic invoice / electronic note, even if it was delivered to the customer.
 *
 * Not covered by this rule: boletas (daily summary rules) and contingency series.
 */
class SendDeadline
{
    public const DAYS_AFTER_ISSUE = 3;
    public const TIMEZONE = 'America/Lima';

    /**
     * Whether the deadline applies to a document.
     *
     * Factura (01) or note (07/08) of an electronic "F" series. A note linked to a boleta is
     * excluded when the affected document type is known. Contingency series (numeric) and
     * boletas ("B" series) are excluded.
     */
    public static function appliesTo($documentTypeId, $series, $affectedDocumentTypeId = null): bool
    {
        if (!in_array((string) $documentTypeId, ['01', '07', '08'], true)) {
            return false;
        }

        if (strtoupper(substr((string) $series, 0, 1)) !== 'F') {
            return false;
        }

        if (in_array((string) $documentTypeId, ['07', '08'], true)
            && $affectedDocumentTypeId !== null
            && (string) $affectedDocumentTypeId !== '01') {
            return false;
        }

        return true;
    }

    /**
     * Same as appliesTo() using a Document model (group_id '01' means invoice family).
     */
    public static function appliesToDocument($document): bool
    {
        if (!$document) {
            return false;
        }

        $affected = null;
        if (in_array((string) $document->document_type_id, ['07', '08'], true)) {
            $affected = ((string) $document->group_id === '01') ? '01' : '03';
        }

        return self::appliesTo($document->document_type_id, $document->series, $affected);
    }

    /**
     * Last calendar day (Lima) on which the document can still be sent, or null when the
     * issue date cannot be parsed.
     */
    public static function lastDay($dateOfIssue): ?Carbon
    {
        $day = self::issueDay($dateOfIssue);

        return $day ? $day->addDays(self::DAYS_AFTER_ISSUE) : null;
    }

    /**
     * An unparseable issue date is never considered expired (normal validation handles it).
     */
    public static function isExpired($dateOfIssue, $today = null): bool
    {
        $last = self::lastDay($dateOfIssue);

        return $last !== null && self::today($today)->greaterThan($last);
    }

    /**
     * Calendar days left including the last day: 0 = last day is today, negative = expired.
     * Returns null when the issue date cannot be parsed.
     */
    public static function daysLeft($dateOfIssue, $today = null): ?int
    {
        $last = self::lastDay($dateOfIssue);

        return $last === null ? null : (int) self::today($today)->diffInDays($last, false);
    }

    public static function message($dateOfIssue): string
    {
        $last = self::lastDay($dateOfIssue);
        $last = $last ? $last->format('d/m/Y') : '';

        return "El plazo de envío a SUNAT venció el {$last} (RS 003-2023: hasta 3 días calendario después de la emisión). "
            . 'Este comprobante ya no puede enviarse; emite uno nuevo.';
    }

    public static function creationMessage($dateOfIssue): string
    {
        $last = self::lastDay($dateOfIssue);
        $last = $last ? $last->format('d/m/Y') : '';

        return "No puedes emitir este comprobante con esa fecha: el plazo de envío a SUNAT venció el {$last} "
            . '(RS 003-2023: hasta 3 días calendario después de la emisión). Emite uno nuevo con una fecha vigente.';
    }

    /**
     * Accepts DateTimeInterface, 'Y-m-d', 'Y-m-d H:i:s' (or ISO 'T') and 'd/m/Y'. Null if unparseable.
     */
    private static function issueDay($dateOfIssue): ?Carbon
    {
        if ($dateOfIssue instanceof DateTimeInterface) {
            $date = $dateOfIssue->format('Y-m-d');
        } else {
            $raw = trim((string) $dateOfIssue);
            if (preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[ T].*)?$/', $raw, $m)) {
                $date = "{$m[1]}-{$m[2]}-{$m[3]}";
            } elseif (preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', $raw, $m)) {
                $date = "{$m[3]}-{$m[2]}-{$m[1]}";
            } else {
                return null;
            }
        }

        try {
            $day = Carbon::createFromFormat('Y-m-d', $date, self::TIMEZONE);
        } catch (\Throwable $e) {
            return null;
        }

        // Reject overflowed dates such as 2026-02-31.
        if (!$day || $day->format('Y-m-d') !== $date) {
            return null;
        }

        return $day->startOf('day');
    }

    private static function today($today = null): Carbon
    {
        $now = $today ? Carbon::parse($today) : Carbon::now();

        return $now->copy()->setTimezone(self::TIMEZONE)->startOf('day');
    }
}
