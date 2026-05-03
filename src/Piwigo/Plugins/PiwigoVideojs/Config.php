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
 * vjs_conf and vjs_sync are stored serialized in the conf table; the plugin
 * boot path unserializes them into native arrays. This facade exposes the
 * top-level sections as typed array accessors plus the two scalar paths
 * and the customcss string.
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
        $conf = self::confArray();
        $raw  = $conf['vjs_conf'] ?? [];
        return is_array($raw) ? $raw : [];
    }

    /** @return array<string, mixed> */
    public static function syncProbes(): array
    {
        $conf = self::confArray();
        $raw  = $conf['vjs_sync'] ?? [];
        return is_array($raw) ? $raw : [];
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
        $g = $GLOBALS['conf'] ?? [];
        return is_array($g) ? $g : [];
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function persistPlayerConfig(array $config): void
    {
        ConfigStorage::persist('vjs_conf', serialize($config));
        PiwigoConfig::override('vjs_conf', $config);
    }

    public static function persistCustomCss(string $css): void
    {
        ConfigStorage::persist('vjs_customcss', $css);
        PiwigoConfig::override('vjs_customcss', $css);
    }
}
