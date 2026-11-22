<?php

namespace App\Services\Payments;

require_once __DIR__ . '/CulqiErrorPolyfills.php';

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
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
    public function __construct(callable $factory = null, ?ClientInterface $httpClient = null)
    {
        $this->factory = $factory ?: function ($secret) use ($httpClient) {
            return new class($secret, $httpClient) {
                public $Charges;

                public function __construct(string $secret, ?ClientInterface $httpClient)
                {
                    $this->Charges = new class($secret, $httpClient) {
                        private $secret;
                        private $httpClient;

                        public function __construct(string $secret, ?ClientInterface $httpClient)
                        {
                            $this->secret = $secret;
                            $this->httpClient = $httpClient;
                        }

                        public function create(array $payload)
                        {
                            $client = $this->httpClient ?: new Client([
                                'base_uri' => 'https://api.culqi.com',
                                'timeout' => 15,
                                'connect_timeout' => 10,
                                'http_errors' => false,
                                'verify' => true,
                            ]);

                            try {
                                $response = $client->request('POST', '/v2/charges', [
                                    'headers' => [
                                        'Authorization' => 'Bearer ' . $this->secret,
                                        'Content-Type' => 'application/json',
                                        'Accept' => 'application/json',
                                    ],
                                    'json' => $payload,
                                ]);
                            } catch (GuzzleException $e) {
                                throw new \Culqi\Error\UnableToConnect($e->getMessage(), 0, $e);
                            }

                            $status = (int) $response->getStatusCode();
                            $body = (string) $response->getBody();

                            if ($status === 200 || $status === 201) {
                                $decoded = json_decode($body);
                                if (json_last_error() !== JSON_ERROR_NONE) {
                                    throw new \Culqi\Error\UnhandledError($body, $status);
                                }

                                return $decoded;
                            }

                            switch ($status) {
                                case 400:
                                    throw new \Culqi\Error\UnhandledError($body, 400);
                                case 402:
                                    throw new \Culqi\Error\UnhandledError($body, 402);
                                case 401:
                                    throw new \Culqi\Error\AuthenticationError($body, 401);
                                case 403:
                                    throw new \Culqi\Error\InvalidApiKey($body, 403);
                                case 404:
                                    throw new \Culqi\Error\NotFound($body, 404);
                                case 405:
                                    throw new \Culqi\Error\MethodNotAllowed($body, 405);
                                case 422:
                                    throw new \Culqi\Error\InputValidationError($body, 422);
                                default:
                                    throw new \Culqi\Error\UnhandledError($body, $status);
                            }
                        }
                    };
                }
            };
        };
    }

    const UNKNOWN_MESSAGE = 'No pudimos confirmar el pago. Si se realizó el cargo lo verificaremos; no vuelva a intentar el pago.';
    const DECLINED_MESSAGE = 'El pago fue rechazado. Verifique los datos de su tarjeta o utilice otro medio de pago.';
    const NEEDS_3DS_MESSAGE = 'Su banco requiere autenticación adicional.';

    const ANTIFRAUD_KEYS = ['device_finger_print_id', 'first_name', 'last_name', 'phone_number'];
    const AUTH_3DS_KEYS = ['eci', 'xid', 'cavv', 'protocolVersion', 'directoryServerTransactionId'];

    /**
     * Result shapes:
     * - paid:      paid=true, id set (verified venta_exitosa, same amount and currency).
     * - declined / rejected before processing: all flags false (no charge exists).
     * - needs_3ds: Culqi answered action_code REVIEW; no charge exists yet, the same token may be
     *              submitted again with authentication_3DS.
     * - unknown:   the outcome could not be determined (timeouts, 429, 5xx, transport errors):
     *              the card may have been charged, so callers must keep the record pending and never retry.
     *
     * @param array $options ['antifraud' => array, 'authentication_3DS' => array] (whitelisted here)
     * @return array{paid:bool,unknown:bool,needs_3ds:bool,id:?string,message:?string}
     */
    public function charge(string $secret, int $amountCents, string $email, string $description, string $sourceId, $installments = null, array $logContext = [], array $options = []): array
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

            $antifraud = $this->whitelist($options['antifraud'] ?? null, self::ANTIFRAUD_KEYS, 100);
            if ($antifraud) {
                $payload['antifraud_details'] = $antifraud;
            }
            $auth3ds = $this->whitelist($options['authentication_3DS'] ?? null, self::AUTH_3DS_KEYS, 255);
            if ($auth3ds) {
                $payload['authentication_3DS'] = $auth3ds;
            }

            $charge = ($this->factory)($secret)->Charges->create($payload);
        } catch (\Throwable $e) {
            if ($e instanceof \Error) {
                throw $e;
            }

            $logContext['error'] = get_class($e);
            $logContext['http'] = $e->getCode();
            $http = (int) $e->getCode();

            // Card declined, no charge was made. The live API answers HTTP 400 with
            // type "operacion_denegada" (verified in sandbox); the docs describe HTTP 402
            // "card_error". Both are handled, and a 400 is a decline only when its body says so.
            $error = $e instanceof \Culqi\Error\UnhandledError ? json_decode((string) $e->getMessage(), true) : null;
            $declinedBody = is_array($error) && (
                in_array($error['type'] ?? null, ['operacion_denegada', 'card_error'], true)
                || (is_string($error['decline_code'] ?? null) && $error['decline_code'] !== '')
            );
            if ($e instanceof \Culqi\Error\UnhandledError && ($http === 402 || ($http === 400 && $declinedBody))) {
                $logContext['decline_code'] = is_array($error) ? ($error['decline_code'] ?? null) : null;
                $logContext['charge_id'] = is_array($error) ? ($error['charge_id'] ?? null) : null;
                $this->log('warning', 'Culqi charge declined', $logContext);

                $userMessage = is_array($error) ? $this->cleanMessage($error['user_message'] ?? null) : null;

                return $this->fail($userMessage ?: self::DECLINED_MESSAGE);
            }

            // Rejected before any charge could exist (bad request, bad key, not found, validation).
            if (($e instanceof \Culqi\Error\UnhandledError && $http === 400)
                || $e instanceof \Culqi\Error\AuthenticationError || $e instanceof \Culqi\Error\InvalidApiKey
                || $e instanceof \Culqi\Error\NotFound || $e instanceof \Culqi\Error\MethodNotAllowed
                || $e instanceof \Culqi\Error\InputValidationError) {
                $this->log('error', 'Culqi charge rejected before processing', $logContext);

                return $this->fail('No se pudo procesar el pago. Intente nuevamente más tarde.');
            }

            $this->log('error', 'Culqi charge outcome unknown', $logContext);

            return ['paid' => false, 'unknown' => true, 'needs_3ds' => false, 'id' => null, 'message' => self::UNKNOWN_MESSAGE];
        }

        // 3DS required (documented as 200, detected by body): no charge exists yet.
        if ($this->read($charge, 'action_code') === 'REVIEW') {
            $this->log('info', 'Culqi charge requires 3DS authentication', $logContext);

            return [
                'paid' => false, 'unknown' => false, 'needs_3ds' => true, 'id' => null,
                'message' => $this->cleanMessage($this->read($charge, 'user_message')) ?: self::NEEDS_3DS_MESSAGE,
            ];
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

        return ['paid' => true, 'unknown' => false, 'needs_3ds' => false, 'id' => $id, 'message' => null];
    }

    /** Keeps only whitelisted, non-empty scalar-string keys, length-capped. */
    private function whitelist($data, array $keys, int $max): array
    {
        if (! is_array($data)) {
            return [];
        }

        $out = [];
        foreach ($keys as $key) {
            $value = $data[$key] ?? null;
            if (is_int($value) || is_float($value)) {
                $value = (string) $value;
            }
            if (! is_string($value)) {
                continue;
            }
            $value = trim(strip_tags($value));
            if ($value === '') {
                continue;
            }
            $out[$key] = mb_substr($value, 0, $max);
        }

        return $out;
    }

    private function cleanMessage($message): ?string
    {
        if (! is_string($message)) {
            return null;
        }
        $message = trim(strip_tags($message));

        return $message === '' ? null : mb_substr($message, 0, 255);
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
        return ['paid' => false, 'unknown' => false, 'needs_3ds' => false, 'id' => null, 'message' => $message];
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
