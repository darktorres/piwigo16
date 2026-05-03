<?php

declare(strict_types=1);

namespace Piwigo\Plugins\PiwigoOpenstreetmap;

use Piwigo\Config\Config as PiwigoConfig;
use Piwigo\Config\ConfigStorage;

/**
 * Typed Config facade for the bundled piwigo-openstreetmap plugin.
 *
 * The plugin stores a deeply nested associative array under the single
 * `osm_conf` key, persisted as a serialized string in the conf table and
 * unserialized into a live array at boot. The structure is:
 *
 *   osm_conf:
 *     left_menu:        { enabled, layout, link, zoom, center, popup, ... }
 *     main_menu:        { enabled, height, ... }
 *     right_panel:      { enabled, height, zoom, link, ... }
 *     category_description: { enabled, height, width, index, display_gpx }
 *     map:              { baselayer, custombaselayer, custombaselayerurl, ... }
 *     pin:              { pin, pinpath, pinsize, ... }
 *     gpx:              { height, width }
 *     batch:            { global_height, unit_height }
 *     community_bm:     { enabled }
 *
 * Exposing each nested leaf as its own typed accessor would balloon the
 * surface area (60+ leaves). Instead this facade exposes the top-level
 * sections as typed array accessors; plugin code navigates the nested keys
 * itself using array access.
 */
final class Config
{
    /**
     * @var array<string, array{type: string, default: mixed, method: string}>
     */
    public const SCHEMA = [
        'osm_conf' => [
            'type'    => 'array',
            'default' => [],
            'method'  => 'all',
        ],
    ];

    /** @return array<string, mixed> Full osm_conf array (post-unserialize). */
    public static function all(): array
    {
        $conf = self::confArray();
        $raw  = $conf['osm_conf'] ?? [];
        return is_array($raw) ? $raw : [];
    }

    /** @return array<string, mixed> */
    private static function confArray(): array
    {
        $g = $GLOBALS['conf'] ?? [];
        return is_array($g) ? $g : [];
    }

    /** @return array<string, mixed> */
    public static function leftMenu(): array
    {
        $section = self::all()['left_menu'] ?? [];
        return is_array($section) ? $section : [];
    }

    /** @return array<string, mixed> */
    public static function mainMenu(): array
    {
        $section = self::all()['main_menu'] ?? [];
        return is_array($section) ? $section : [];
    }

    /** @return array<string, mixed> */
    public static function rightPanel(): array
    {
        $section = self::all()['right_panel'] ?? [];
        return is_array($section) ? $section : [];
    }

    /** @return array<string, mixed> */
    public static function categoryDescription(): array
    {
        $section = self::all()['category_description'] ?? [];
        return is_array($section) ? $section : [];
    }

    /** @return array<string, mixed> */
    public static function map(): array
    {
        $section = self::all()['map'] ?? [];
        return is_array($section) ? $section : [];
    }

    /**
     * Persists the full osm_conf array. Plugin admin pages mutate the nested
     * structure in-memory then call this once to commit the snapshot.
     *
     * @param array<string, mixed> $conf
     */
    public static function persistAll(array $conf): void
    {
        ConfigStorage::persist('osm_conf', serialize($conf));
        PiwigoConfig::override('osm_conf', $conf);
    }
}
