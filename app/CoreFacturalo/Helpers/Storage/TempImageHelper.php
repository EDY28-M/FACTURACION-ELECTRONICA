<?php

namespace App\CoreFacturalo\Helpers\Storage;

use Hyn\Tenancy\Contracts\CurrentHostname;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;

class TempImageHelper
{
    private const LIFETIME_SECONDS = 7200;

    private const MIME_EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/gif'  => 'gif',
        'image/webp' => 'webp',
    ];

    /**
     * Store an uploaded image in the system temporary directory and return its upload payload.
     */
    public static function storeTemp(UploadedFile $file, ?string $prefix = null): array
    {
        $owner = self::owner();
        $safePrefix = preg_replace('/[^A-Za-z0-9_-]/', '', (string) $prefix) ?: 'item_';
        $temp = tempnam(sys_get_temp_dir(), $safePrefix);
        if ($temp === false) {
            abort(500, 'No se pudo crear el archivo temporal.');
        }

        $temp = realpath($temp);
        $sourcePath = $file->getRealPath() ?: (string) $file;
        $data = file_get_contents($sourcePath);
        if ($data === false || file_put_contents($temp, $data) === false) {
            @unlink($temp);
            abort(500, 'No se pudo guardar el archivo temporal.');
        }

        $mime = mime_content_type($temp) ?: 'application/octet-stream';
        // One key per upload also preserves simultaneous multi-image uploads.
        if (!Cache::put(self::cacheKey($temp), $owner, self::LIFETIME_SECONDS)) {
            @unlink($temp);
            abort(500, 'No se pudo registrar el archivo temporal.');
        }

        return [
            'success' => true,
            'data' => [
                'filename' => $file->getClientOriginalName(),
                'temp_path' => $temp,
                'temp_image' => 'data:' . $mime . ';base64,' . base64_encode($data),
            ],
        ];
    }

    /**
     * Validate ownership and a real image strictly inside sys_get_temp_dir().
     *
     * @param  mixed  $tempPath
     * @return array{path: string, extension: string}
     */
    public static function resolveValidated($tempPath): array
    {
        $owner = self::owner();
        $tempDir = realpath(sys_get_temp_dir());
        $realPath = is_string($tempPath) && $tempPath !== '' ? realpath($tempPath) : false;

        if (
            $tempDir === false
            || $realPath === false
            || !is_file($realPath)
            || strpos($realPath, $tempDir . DIRECTORY_SEPARATOR) !== 0
            || Cache::get(self::cacheKey($realPath)) !== $owner
        ) {
            abort(422, 'Ruta temporal de imagen inválida.');
        }

        $imageInfo = @getimagesize($realPath);
        $mime = is_array($imageInfo) && !empty($imageInfo['mime'])
            ? $imageInfo['mime']
            : (function_exists('mime_content_type') ? mime_content_type($realPath) : null);

        if ($imageInfo === false || !$mime || !isset(self::MIME_EXTENSIONS[$mime])) {
            abort(422, 'El archivo temporal no es una imagen válida.');
        }

        return [
            'path' => $realPath,
            'extension' => self::MIME_EXTENSIONS[$mime],
        ];
    }

    /**
     * Consume an owned upload only after the caller's save has succeeded.
     */
    public static function deleteOwned(string $tempPath): void
    {
        $key = self::cacheKey($tempPath);
        if (Cache::get($key) !== self::owner()) {
            return;
        }

        if (realpath($tempPath) === $tempPath && is_file($tempPath)) {
            @unlink($tempPath);
        }
        Cache::forget($key);
    }

    private static function cacheKey(string $path): string
    {
        return 'temp-image:' . hash('sha256', $path);
    }

    private static function owner(): array
    {
        $request = request();
        $user = $request->user();
        $hostname = app(CurrentHostname::class);

        if (
            !$user
            || $user->getAuthIdentifier() === null
            || !$hostname
            || !isset($hostname->website_id)
            || !$request->hasSession()
            || !$request->session()->isStarted()
        ) {
            abort(422, 'La imagen temporal requiere una sesión autenticada del establecimiento.');
        }

        return [
            'tenant' => (string) $hostname->website_id,
            'user' => (string) $user->getAuthIdentifier(),
            'session' => $request->session()->getId(),
        ];
    }
}
