<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

/**
 * Encrypts a secret at rest. Legacy plain-text values are tolerated on read and are
 * encrypted the next time the attribute is assigned and saved.
 */
class EncryptedSecret implements CastsAttributes
{
    public function get($model, string $key, $value, array $attributes)
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Crypt::decryptString($value);
        } catch (DecryptException $e) {
            if (self::looksEncrypted($value)) {
                // Undecryptable payload (e.g. APP_KEY changed): never hand the ciphertext out as a key.
                try {
                    Log::warning('Encrypted secret could not be decrypted', ['attribute' => $key]);
                } catch (\Throwable $ignored) {
                }

                return null;
            }

            return $value;
        }
    }

    public static function looksEncrypted(string $value): bool
    {
        $payload = json_decode((string) base64_decode($value, true), true);

        return is_array($payload) && isset($payload['iv'], $payload['value'], $payload['mac']);
    }

    public function set($model, string $key, $value, array $attributes)
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Crypt::encryptString((string) $value);
    }
}
