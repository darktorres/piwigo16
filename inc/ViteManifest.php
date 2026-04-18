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
    /**
     * @param string $key Module name (e.g. 'tags') or full path (e.g. 'themes/default/js/mcs')
     */
    public static function getFile($key) {
        $manifest = self::loadManifest();

        if (!$manifest) {
            return null;
        }

        if (strpos($key, '/') !== false) {
            // Full source path provided
            $fullKey = $key;
            if (substr($fullKey, -3) !== '.js') {
                $fullKey .= '.js';
            }
        } else {
            // Legacy: module name only → admin path
            $fullKey = 'admin/themes/default/js/' . $key . '.js';
        }

        return $manifest[$fullKey]['file'] ?? null;
    }
}
?>
