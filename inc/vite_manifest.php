<?php

namespace Piwigo\Vite;

class ViteManifest
{
    private static $manifest = null;

    private static $manifestPath = null;

    public static function setManifestPath($path): void
    {
        self::$manifestPath = $path;
        self::$manifest = null; // Reset cache on path change
    }

    public static function getManifest()
    {
        if (self::$manifest === null) {
            $path = self::$manifestPath ?? dirname(__DIR__) . '/admin/themes/default/js/dist/.vite/manifest.json';

            if (! file_exists($path)) {
                throw new \Exception("Vite manifest not found at {$path}. Run 'bun run build' first.");
            }

            self::$manifest = json_decode(file_get_contents($path), true);
            if (self::$manifest === null) {
                throw new \Exception('Failed to parse Vite manifest: ' . json_last_error_msg());
            }
        }

        return self::$manifest;
    }

    /**
     * Get the hashed filename for a given module key
     * @param string $key Module key (e.g. 'tags', 'albums') or full path (e.g. 'admin/themes/default/js/tags.js')
     * @return string|null Hashed filename or null if not found
     */
    public static function getFile(string $key)
    {
        $manifest = self::getManifest();

        // Try full path first
        $fullPath = 'admin/themes/default/js/' . $key . '.js';
        if (isset($manifest[$fullPath])) {
            return $manifest[$fullPath]['file'];
        }

        // Fallback to just key.js
        if (isset($manifest[$key . '.js'])) {
            return $manifest[$key . '.js']['file'];
        }

        return null;
    }

    /**
     * Get all dependencies/imports for a module
     * @param string $key Module key
     * @return array Array of imported module files
     */
    public static function getDeps(string $key)
    {
        $manifest = self::getManifest();
        $entry = $manifest[$key . '.js'] ?? null;

        if (! $entry) {
            return [];
        }

        return $entry['imports'] ?? [];
    }

    /**
     * Emit a <script type="module"> tag for a given module
     * @param string $key Module key
     * @param string $rootPath Base path to dist/ (default: '/admin/themes/default/js/dist')
     */
    public static function emitModuleScript($key, $rootPath = '/admin/themes/default/js/dist'): void
    {
        $file = self::getFile($key);

        if (! $file) {
            throw new \Exception("Module '{$key}' not found in Vite manifest");
        }

        $src = rtrim($rootPath, '/') . '/' . $file;
        echo '<script type="module" src="' . htmlspecialchars($src, ENT_QUOTES, 'UTF-8') . '"></script>';
    }
}
