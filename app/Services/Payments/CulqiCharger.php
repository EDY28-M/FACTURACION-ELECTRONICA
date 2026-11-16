<?php

namespace App\Services\Payments;

use Culqi\Culqi;
use Illuminate\Support\Facades\Log;

/**
 * Creates a Culqi charge and verifies its outcome. Only an approved sale
 * ("venta_exitosa") for the exact amount and currency counts as paid; anything
 * else (declined, 3DS challenge, REVIEW, transport errors) is NOT paid.
 */
class CulqiCharger
{
    private $factory;

    /**
     * @param callable|null $factory fn(string $secretKey): object exposing Charges->create(array)
     */
    public function __construct(callable $factory = null)
    {
        $this->factory = $factory ?: function ($secret) {
            return new Culqi(['api_key' => $secret]);
        };
    }

    const UNKNOWN_MESSAGE = 'No pudimos confirmar el pago. Si se realizó el cargo lo verificaremos; no vuelva a intentar el pago.';

    /**
     * "unknown" = the outcome could not be determined (timeouts, 429, 5xx, transport errors):
     * the card may have been charged, so callers must keep the record pending and never retry.
     *
     * @return array{paid:bool,unknown:bool,id:?string,message:?string}
     */
    public function charge(string $secret, int $amountCents, string $email, string $description, string $sourceId, $installments = null, array $logContext = []): array
    {
        $logContext += ['amount_cents' => $amountCents, 'email' => $email, 'source_tail' => substr($sourceId, -4)];

        try {
            $payload = [
                'amount' => $amountCents,
                'currency_code' => 'PEN',
                'email' => $email,
                'description' => $description,
                'source_id' => $sourceId,
            ];
            if ($installments) {
                $payload['installments'] = (int) $installments;
            }

            $charge = ($this->factory)($secret)->Charges->create($payload);
        } catch (\Throwable $e) {
            $logContext['error'] = get_class($e);
            $logContext['http'] = $e->getCode();

            // Only a clear HTTP 400 is a card decline.
            if ($e instanceof \Culqi\Error\UnhandledError && (int) $e->getCode() === 400) {
                $this->log('warning', 'Culqi charge declined', $logContext);

                return $this->fail('El pago fue rechazado. Verifique los datos de su tarjeta o utilice otro medio de pago.');
            }

            // Rejected before any charge could exist (bad key, bad request, not found).
            if ($e instanceof \Culqi\Error\AuthenticationError || $e instanceof \Culqi\Error\InvalidApiKey
                || $e instanceof \Culqi\Error\NotFound || $e instanceof \Culqi\Error\MethodNotAllowed
                || $e instanceof \Culqi\Error\InputValidationError) {
                $this->log('error', 'Culqi charge rejected before processing', $logContext);

                return $this->fail('No se pudo procesar el pago. Intente nuevamente más tarde.');
            }

            $this->log('error', 'Culqi charge outcome unknown', $logContext);

            return ['paid' => false, 'unknown' => true, 'id' => null, 'message' => self::UNKNOWN_MESSAGE];
        }

        $id = $this->read($charge, 'id');
        $type = $this->read($this->read($charge, 'outcome'), 'type');
        $approved = $type === 'venta_exitosa'
            && is_string($id) && $id !== ''
            && (int) $this->read($charge, 'amount') === $amountCents
            && $this->read($charge, 'currency_code') === 'PEN';

        if (! $approved) {
            $this->log('warning', 'Culqi charge not approved', $logContext + ['charge_id' => $id, 'outcome' => $type]);

            return $this->fail('El pago no fue aprobado. Verifique los datos de su tarjeta o utilice otro medio de pago.');
        }

        $this->log('info', 'Culqi charge approved', $logContext + ['charge_id' => $id]);

        return ['paid' => true, 'unknown' => false, 'id' => $id, 'message' => null];
    }

    private function read($data, string $key)
    {
        if (is_object($data)) {
            return $data->{$key} ?? null;
        }

        return is_array($data) ? ($data[$key] ?? null) : null;
    }

    private function fail(string $message): array
    {
        return ['paid' => false, 'unknown' => false, 'id' => null, 'message' => $message];
    }

    private function log(string $level, string $message, array $context): void
    {
        try {
            Log::{$level}($message, $context);
        } catch (\Throwable $e) {
            // Logging must never break a payment flow.
        }
    }
}
