<?php

namespace App\Services\Ecommerce;

use Illuminate\Support\Facades\Mail;

/**
 * Sends a notification mail without ever failing the business flow that triggered it.
 *
 * Orders, payments and comprobantes are already saved when their mail is sent: a broken SMTP
 * configuration must be reported (log) but never turn a completed operation into an error.
 */
class SafeMail
{
    /**
     * @param  string|null    $recipient
     * @param  object         $mailable
     * @param  callable|null  $sender    function ($recipient, $mailable): void (defaults to the Mail facade)
     * @return bool true when the mail was handed to the mailer
     */
    public static function send($recipient, $mailable, ?callable $sender = null): bool
    {
        $recipient = trim((string) $recipient);
        if ($recipient === '' || ! filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        try {
            if ($sender) {
                $sender($recipient, $mailable);
            } else {
                Mail::to($recipient)->send($mailable);
            }

            return true;
        } catch (\Throwable $e) {
            try {
                report($e);
            } catch (\Throwable $ignored) {
                // Reporting must never break the flow either.
            }

            return false;
        }
    }
}
