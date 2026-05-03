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

    /**
     * Returns the full osm_conf array, lazy-deserializing from the raw
     * DB string on first read if needed.
     *
     * @return array<string, mixed>
     */
    public static function all(): array
    {
        $raw = PiwigoConfig::all()['osm_conf'] ?? [];
        if (is_string($raw) && $raw !== '') {
            $raw = @unserialize($raw);
        }
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $k => $v) {
            $out[(string) $k] = $v;
        }
        return $out;
    }

    /** @return array<string, mixed> */
    public static function leftMenu(): array
    {
        return self::section('left_menu');
    }

    /** @return array<string, mixed> */
    public static function mainMenu(): array
    {
        return self::section('main_menu');
    }

    /** @return array<string, mixed> */
    public static function rightPanel(): array
    {
        return self::section('right_panel');
    }

    /** @return array<string, mixed> */
    public static function categoryDescription(): array
    {
        return self::section('category_description');
    }

    /** @return array<string, mixed> */
    public static function map(): array
    {
        return self::section('map');
    }

    /** @return array<string, mixed> */
    public static function pin(): array
    {
        return self::section('pin');
    }

    /** @return array<string, mixed> */
    public static function gpx(): array
    {
        return self::section('gpx');
    }

    /** @return array<string, mixed> */
    public static function batch(): array
    {
        return self::section('batch');
    }

    /** @return array<string, mixed> */
    public static function communityBm(): array
    {
        return self::section('community_bm');
    }

    /** @return array<string, mixed> */
    private static function section(string $name): array
    {
        $section = self::all()[$name] ?? [];
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
