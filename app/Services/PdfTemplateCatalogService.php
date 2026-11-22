<?php

namespace App\Services;

use App\Models\Tenant\Configuration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Keeps the tenant template catalogs (format_templates / preprinted_format_templates)
 * in sync with the template folders found on disk and decorates them with preview info.
 */
class PdfTemplateCatalogService
{
    const TYPE_PDF = 'pdf';
    const TYPE_PREPRINTED = 'preprinted_pdf';

    private const TABLES = [
        self::TYPE_PDF => 'format_templates',
        self::TYPE_PREPRINTED => 'preprinted_format_templates',
    ];

    /** Folder that custom templates are cloned from when they do not exist yet. */
    private const CUSTOM_SOURCES = [
        self::TYPE_PDF => 'default',
        self::TYPE_PREPRINTED => 'static',
    ];

    /**
     * Rebuild the catalog table from the filesystem.
     */
    public function sync(string $type): void
    {
        $this->ensureCustomTemplate($type);

        $names = $this->discover($type);

        DB::connection('tenant')->transaction(function () use ($type, $names) {
            $table = DB::connection('tenant')->table(self::TABLES[$type]);
            $table->delete();
            foreach ($names as $name) {
                DB::connection('tenant')->table(self::TABLES[$type])->insert(['formats' => $name]);
            }
        });
    }

    /**
     * Returns the catalog, resyncing it first when it is empty or out of date.
     */
    public function all(string $type): array
    {
        $table = self::TABLES[$type];
        $discovered = $this->discover($type);
        $stored = DB::connection('tenant')->table($table)->orderBy('id')->get();

        $storedNames = $stored->pluck('formats')->all();
        if (array_diff($discovered, $storedNames) || array_diff($storedNames, $discovered)) {
            $this->sync($type);
            $stored = DB::connection('tenant')->table($table)->orderBy('id')->get();
        }

        $active = $type === self::TYPE_PDF ? optional(Configuration::first())->formats : null;
        $rows = [];
        $names = [];
        foreach ($stored as $row) {
            $names[] = $row->formats;
            $rows[] = $this->describe($type, (int) $row->id, $row->formats, false);
        }

        // The active template must always be visible, even if its folder is gone.
        if ($active && !in_array($active, $names, true)) {
            $rows[] = $this->describe($type, 0, $active, true);
        }

        return $rows;
    }

    private function describe(string $type, int $id, string $name, bool $missing): array
    {
        $dir = $this->coreDir($type, $name);
        $preview = $this->publicImage($type, $name, 'image.png');
        $guidePreview = $this->publicImage($type, $name, 'image_guide.png') ?: $preview;

        return [
            'id' => $id,
            'formats' => $name,
            'preview' => $preview,
            'guide_preview' => $guidePreview,
            'supports_invoice' => is_file($dir . '/invoice_a4.blade.php'),
            'supports_dispatch' => is_file($dir . '/dispatch_a4.blade.php'),
            'missing' => $missing,
        ];
    }

    private function publicImage(string $type, string $name, string $file): ?string
    {
        if (!is_file(public_path("templates/{$type}/{$name}/{$file}"))) {
            return null;
        }

        return "/templates/{$type}/" . rawurlencode($name) . "/{$file}";
    }

    private function coreDir(string $type, string $name): string
    {
        return app_path('CoreFacturalo/Templates/' . $type . '/' . basename($name));
    }

    /**
     * Folder names that contain at least one root-level blade template.
     */
    private function discover(string $type): array
    {
        $root = app_path('CoreFacturalo/Templates/' . $type);
        if (!is_dir($root)) {
            return [];
        }

        $names = [];
        foreach (File::directories($root) as $dir) {
            if (count(File::glob($dir . '/*.blade.php')) > 0) {
                $names[] = basename($dir);
            }
        }
        sort($names, SORT_NATURAL | SORT_FLAG_CASE);

        return $names;
    }

    /**
     * Makes sure the editable "custom" template exists, cloning it from an existing folder when possible.
     */
    private function ensureCustomTemplate(string $type): void
    {
        $custom = $this->coreDir($type, 'custom');
        if (is_file($custom . '/style.css')) {
            return;
        }

        $source = $this->coreDir($type, self::CUSTOM_SOURCES[$type]);
        $files = ['style.css', 'invoice_a4.blade.php', 'partials/footer.blade.php'];
        foreach ($files as $file) {
            if (!is_file($source . '/' . $file)) {
                continue;
            }
            File::ensureDirectoryExists(dirname($custom . '/' . $file));
            File::copy($source . '/' . $file, $custom . '/' . $file);
        }
    }
}
