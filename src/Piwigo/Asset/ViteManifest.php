<?php

declare(strict_types=1);

namespace Piwigo\Asset;

use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;

/**
 * Reads the Piwigo-format Vite manifest (dist/manifest.json) and resolves
 * entry names to their hashed output paths. The manifest is keyed by entry
 * name (e.g. "albums") and each value carries `file` (the JS path) and
 * optionally `css` (an array of CSS paths).
 */
final class ViteManifest
{
    /** @var array<string, mixed>|false|null */
    private static array|false|null $cache = null;

    /**
     * @return array<string, mixed>|null
     */
    public static function read(): ?array
    {
        if (self::$cache !== null) {
            return self::$cache !== false ? self::$cache : null;
        }
        $f = Kernel::service(Paths::class)->root . 'dist/manifest.json';
        if (!is_file($f)) {
            self::$cache = false;
            return null;
        }
        $decoded = json_decode((string) file_get_contents($f), true);
        self::$cache = is_array($decoded) ? $decoded : false;
        return self::$cache !== false ? self::$cache : null;
    }

    /**
     * @return array{file: string, css: list<string>}|null
     */
    public static function entry(string $id): ?array
    {
        $manifest = self::read();
        if ($manifest === null) {
            return null;
        }
        $entry = $manifest[$id] ?? null;
        if (!is_array($entry) || !is_string($entry['file'] ?? null)) {
            return null;
        }
        $css = [];
        foreach ($entry['css'] ?? [] as $cssPath) {
            if (is_string($cssPath) && $cssPath !== '') {
                $css[] = $cssPath;
            }
        }
        return ['file' => $entry['file'], 'css' => $css];
    }

    public static function resetCache(): void
    {
        self::$cache = null;
    }
}
