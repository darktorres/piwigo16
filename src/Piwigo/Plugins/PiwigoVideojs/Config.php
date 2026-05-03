<?php

declare(strict_types=1);

namespace Piwigo\Plugins\PiwigoVideojs;

use Piwigo\Config\Config as PiwigoConfig;
use Piwigo\Config\ConfigStorage;

/**
 * Typed Config facade for the bundled piwigo-videojs plugin.
 *
 * The plugin owns five conf keys:
 *
 *   vjs_conf         array  player configuration (max_height, skin, autoplay, etc.)
 *   vjs_sync         array  sync probe paths (mediainfo, ffprobe, exiftool, ffmpeg)
 *   vjs_customcss    string user-supplied CSS injected into the player skin
 *   vjs_mediainfo_dir string optional path prefix for the mediainfo binary
 *   vjs_exiftool_dir  string optional path prefix for the exiftool binary
 *
 * vjs_conf and vjs_sync are stored serialized in the conf table.
 * playerConfig() and syncProbes() lazy-deserialize the raw DB value on
 * first read, so callers always get a native array.
 */
final class Config
{
    /**
     * @var array<string, array{type: string, default: mixed, method: string}>
     */
    public const SCHEMA = [
        'vjs_conf'          => ['type' => 'array',  'default' => [], 'method' => 'playerConfig'],
        'vjs_sync'          => ['type' => 'array',  'default' => [], 'method' => 'syncProbes'],
        'vjs_customcss'     => ['type' => 'string', 'default' => '', 'method' => 'customCss'],
        'vjs_mediainfo_dir' => ['type' => 'string', 'default' => '', 'method' => 'mediainfoDir'],
        'vjs_exiftool_dir'  => ['type' => 'string', 'default' => '', 'method' => 'exiftoolDir'],
    ];

    /** @return array<string, mixed> */
    public static function playerConfig(): array
    {
        return self::lazyArray('vjs_conf');
    }

    /** @return array<string, mixed> */
    public static function syncProbes(): array
    {
        return self::lazyArray('vjs_sync');
    }

    /**
     * Returns an array-typed conf value, lazy-deserializing if the stored
     * form is still the raw serialized string from load_conf_from_db.
     *
     * @return array<string, mixed>
     */
    private static function lazyArray(string $key): array
    {
        $raw = self::confArray()[$key] ?? [];
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

    public static function customCss(): string
    {
        $conf = self::confArray();
        $raw  = $conf['vjs_customcss'] ?? '';
        return is_string($raw) ? $raw : '';
    }

    public static function mediainfoDir(): string
    {
        $conf = self::confArray();
        $raw  = $conf['vjs_mediainfo_dir'] ?? '';
        return is_string($raw) ? $raw : '';
    }

    public static function exiftoolDir(): string
    {
        $conf = self::confArray();
        $raw  = $conf['vjs_exiftool_dir'] ?? '';
        return is_string($raw) ? $raw : '';
    }

    /** @return array<string, mixed> */
    private static function confArray(): array
    {
        return PiwigoConfig::all();
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function persistPlayerConfig(array $config): void
    {
        ConfigStorage::persist('vjs_conf', serialize($config));
        PiwigoConfig::override('vjs_conf', $config);
    }

    /**
     * @param array<string, mixed> $sync
     */
    public static function persistSyncProbes(array $sync): void
    {
        ConfigStorage::persist('vjs_sync', serialize($sync));
        PiwigoConfig::override('vjs_sync', $sync);
    }

    public static function persistCustomCss(string $css): void
    {
        ConfigStorage::persist('vjs_customcss', $css);
        PiwigoConfig::override('vjs_customcss', $css);
    }
}
