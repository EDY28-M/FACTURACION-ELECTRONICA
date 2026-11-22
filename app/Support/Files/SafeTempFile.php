<?php

namespace App\Support\Files;

class SafeTempFile
{
    /**
     * Resolve a client-supplied temporary path only when it is a real file
     * inside the system temp directory.
     */
    public static function realPath($path): ?string
    {
        if (!is_string($path) || $path === '') {
            return null;
        }

        $real = realpath($path);
        $tempDir = realpath(sys_get_temp_dir());

        if (!$real || !$tempDir || !is_file($real)) {
            return null;
        }

        return strpos($real, $tempDir.DIRECTORY_SEPARATOR) === 0 ? $real : null;
    }

    public static function filename($name): string
    {
        return preg_replace('/[^A-Za-z0-9._-]/', '_', basename((string) $name));
    }

    public static function image($path): ?array
    {
        $real = self::realPath($path);
        if (!$real) {
            return null;
        }

        $info = @getimagesize($real);
        $extensions = [
            IMAGETYPE_JPEG => 'jpg',
            IMAGETYPE_PNG => 'png',
            IMAGETYPE_GIF => 'gif',
            IMAGETYPE_WEBP => 'webp',
        ];

        if (!$info || !isset($extensions[$info[2]])) {
            return null;
        }

        return ['path' => $real, 'extension' => $extensions[$info[2]]];
    }
}
