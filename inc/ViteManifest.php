<?php
namespace Piwigo\Vite;

/**
 * Helper class to read Vite manifest.json and get hashed filenames
 */
class ViteManifest {
    private static $manifest = null;

    /**
     * Load and parse manifest.json
     */
    private static function loadManifest() {
        if (self::$manifest !== null) {
            return self::$manifest;
        }

        $manifestPath = dirname(__DIR__) . '/admin/themes/default/js/dist/manifest.json';

        if (!file_exists($manifestPath)) {
            return null;
        }

        $json = file_get_contents($manifestPath);
        self::$manifest = json_decode($json, true) ?? [];
        return self::$manifest;
    }

    /**
     * Get the hashed filename for a module
     *
     * @param string $moduleName The module name (e.g., 'common', 'tags')
     * @return string|null The hashed filename or null if not found
     */
    public static function getFile($moduleName) {
        $manifest = self::loadManifest();

        if (!$manifest) {
            return null;
        }

        $key = 'admin/themes/default/js/' . $moduleName . '.js';
        if (!isset($manifest[$key])) {
            return null;
        }

        return $manifest[$key]['file'] ?? null;
    }
}
?>
