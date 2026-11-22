<?php

namespace Modules\Finance\Helpers;

use Hyn\Tenancy\Contracts\CurrentHostname;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;

class PaymentFileHelper
{
    private const PURPOSE = 'payment-attachment';
    private const LIFETIME_SECONDS = 7200;
    private const MIME_EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/svg+xml' => 'svg',
        'application/pdf' => 'pdf',
    ];

    public static function storeTemp(UploadedFile $file, $index = null): array
    {
        $owner = self::owner();
        $source = $file->getRealPath();
        $extension = $source ? self::extension($source) : null;
        if (!$extension) {
            abort(422, 'Tipo de archivo no permitido.');
        }

        $temp = tempnam(sys_get_temp_dir(), 'payment_');
        if ($temp === false) {
            throw new \RuntimeException('Unable to create temporary payment attachment.');
        }
        $temp = realpath($temp);
        try {
            if (!copy($source, $temp)) {
                throw new \RuntimeException('Unable to copy temporary payment attachment.');
            }
            $registration = ['owner' => $owner, 'purpose' => self::PURPOSE, 'extension' => $extension];
            if (!Cache::store()->put(self::cacheKey($temp), $registration, self::LIFETIME_SECONDS)) {
                throw new \RuntimeException('Unable to register temporary payment attachment.');
            }
        } catch (\Throwable $exception) {
            @unlink($temp);
            throw $exception;
        }

        return ['success' => true, 'data' => [
            'filename' => $file->getClientOriginalName(),
            'temp_path' => $temp,
            'index' => (int) $index,
        ]];
    }

    public static function resolveValidated($tempPath): array
    {
        $owner = self::owner();
        $tempDir = realpath(sys_get_temp_dir());
        $path = is_string($tempPath) && $tempPath !== '' ? realpath($tempPath) : false;
        if (!$tempDir || !$path || !is_file($path) || strpos($path, $tempDir . DIRECTORY_SEPARATOR) !== 0) {
            abort(422, 'Archivo temporal de pago inválido.');
        }

        $cache = Cache::store();
        $registration = $cache->get(self::cacheKey($path));
        if (
            !is_array($registration)
            || ($registration['owner'] ?? null) !== $owner
            || ($registration['purpose'] ?? null) !== self::PURPOSE
            || !in_array($registration['extension'] ?? null, ['jpg', 'png', 'gif', 'svg', 'pdf', 'xlsx'], true)
        ) {
            abort(422, 'Archivo temporal de pago inválido o expirado.');
        }

        return ['path' => $path, 'extension' => $registration['extension'], 'registration' => $registration, 'cache' => $cache];
    }

    public static function cleanup(array $resolved): \Closure
    {
        // Capture the validated owner and concrete cache store before deferred commit callbacks.
        $path = $resolved['path'];
        $registration = $resolved['registration'];
        $cache = $resolved['cache'];
        $key = self::cacheKey($path);
        return static function () use ($path, $registration, $cache, $key): void {
            try {
                if ($cache->get($key) !== $registration) {
                    return;
                }
                if (realpath($path) === $path && is_file($path) && !@unlink($path)) {
                    return;
                }
                $cache->forget($key);
            } catch (\Throwable $exception) {
                // Cleanup cannot turn a committed payment into a reported failure.
                try { report($exception); } catch (\Throwable $ignored) {}
            }
        };
    }

    private static function cacheKey(string $path): string
    {
        return 'temp-payment-file:' . hash('sha256', $path);
    }

    private static function owner(): array
    {
        $request = request();
        $user = $request->user();
        $hostname = app(CurrentHostname::class);
        if (!$user || $user->getAuthIdentifier() === null || !$hostname || !isset($hostname->website_id)
            || !$request->hasSession() || !$request->session()->isStarted()) {
            abort(422, 'El archivo temporal requiere una sesión autenticada del establecimiento.');
        }
        return ['tenant' => (string) $hostname->website_id, 'user' => (string) $user->getAuthIdentifier(), 'session' => $request->session()->getId()];
    }

    private static function extension(string $path): ?string
    {
        $mime = mime_content_type($path);
        if (isset(self::MIME_EXTENSIONS[$mime])) {
            return self::MIME_EXTENSIONS[$mime];
        }
        if (in_array($mime, ['application/zip', 'application/x-zip-compressed', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'], true)) {
            $reader = new Xlsx();
            try {
                return $reader->canRead($path) && $reader->listWorksheetNames($path) !== [] ? 'xlsx' : null;
            } catch (\Throwable $exception) {
                return null;
            }
        }
        return null;
    }
}
