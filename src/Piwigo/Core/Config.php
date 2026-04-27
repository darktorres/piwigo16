<?php

declare(strict_types=1);

namespace Piwigo\Core;

/**
 * Typed facade over the $conf global array.
 *
 * Wave A: self::$data IS the same array as $GLOBALS['conf'] (via reference after
 * attachGlobals()). Old code that writes $conf['x'] = $v mutates self::$data and
 * vice versa — no data divergence between the global and the typed reader.
 */
final class Config
{
    /** @var array<string,mixed> */
    private static array $data = [];

    /**
     * Called by Kernel::boot() after common.inc.php has fully populated $GLOBALS['conf'].
     * Copies conf data into self::$data, then rebinds $GLOBALS['conf'] to reference it,
     * so any subsequent $conf write also updates Config::get().
     */
    public static function attachGlobals(): void
    {
        self::$data = $GLOBALS['conf'];
        $GLOBALS['conf'] = &self::$data;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return self::$data[$key] ?? $default;
    }

    public static function getString(string $key, string $default = ''): string
    {
        $v = self::$data[$key] ?? $default;
        return is_string($v) ? $v : (string) $v;
    }

    public static function getInt(string $key, int $default = 0): int
    {
        $v = self::$data[$key] ?? $default;
        return is_int($v) ? $v : (int) $v;
    }

    public static function getBool(string $key, bool $default = false): bool
    {
        $v = self::$data[$key] ?? $default;
        return (bool) $v;
    }

    // ---- Typed cluster accessors -----------------------------------------

    // Paths
    public static function dataLocation(): string
    {
        return self::getString('data_location', '_data/');
    }
    public static function uploadDir(): string
    {
        return self::getString('upload_dir', './upload');
    }
    public static function themesDir(): string
    {
        return self::getString('themes_dir', '');
    }
    public static function logDir(): string
    {
        return self::getString('log_dir', '/logs');
    }
    public static function galleryUrl(): ?string
    {
        return isset(self::$data['gallery_url']) ? (string) self::$data['gallery_url'] : null;
    }
    public static function alternativePemUrl(): string
    {
        return self::getString('alternative_pem_url', '');
    }
    public static function noPhotoYetUrl(): string
    {
        return self::getString('no_photo_yet_url', 'admin.php?page=photos_add');
    }
    public static function ffmpegDir(): string
    {
        return self::getString('ffmpeg_dir', '');
    }
    public static function extImagickDir(): string
    {
        return self::getString('ext_imagick_dir', '');
    }

    // Security / auth
    public static function secretKey(): string
    {
        return self::getString('secret_key', '');
    }
    public static function authKeyDuration(): int
    {
        return self::getInt('auth_key_duration', 3 * 24 * 60 * 60);
    }
    public static function apacheAuthentication(): bool
    {
        return self::getBool('apache_authentication', false);
    }

    // Identity
    public static function galleryTitle(): string
    {
        return self::getString('gallery_title', 'Piwigo');
    }
    public static function guestId(): int
    {
        return self::getInt('guest_id', 2);
    }
    public static function defaultUserId(): int
    {
        return self::getInt('default_user_id', 2);
    }
    public static function webmasterId(): int
    {
        return self::getInt('webmaster_id', 1);
    }

    // Content
    public static function allowHtmlDescriptions(): bool
    {
        return self::getBool('allow_html_descriptions', true);
    }
    public static function activateComments(): bool
    {
        return self::getBool('activate_comments', true);
    }
    public static function isFormatsEnabled(): bool
    {
        return self::getBool('enable_formats', false);
    }

    /** @return list<string> */
    public static function pictureExtensions(): array
    {
        $v = self::$data['picture_ext'] ?? ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        return is_array($v) ? array_values($v) : [];
    }

    /** @return list<string> */
    public static function fileExtensions(): array
    {
        $v = self::$data['file_ext'] ?? [];
        return is_array($v) ? array_values($v) : [];
    }

    // DB / infra
    public static function dbLayer(): string
    {
        return self::getString('dblayer', 'mysqli');
    }

    // Logging
    public static function logLevel(): string
    {
        return self::getString('log_level', 'DEBUG');
    }
    public static function logArchiveDays(): int
    {
        return self::getInt('log_archive_days', 30);
    }

    // ---- Writers ---------------------------------------------------------

    /** Transient runtime override (per-album, etc). Does not persist to DB. */
    public static function override(string $key, mixed $value): void
    {
        self::$data[$key] = $value;
    }

    /** Persists via existing conf_update_param() free function — DB write. */
    public static function persist(string $key, mixed $value): void
    {
        \conf_update_param($key, $value);
        self::$data[$key] = $value;
    }

    // ---- Test helpers ----------------------------------------------------

    /** @param array<string,mixed> $data */
    public static function loadArray(array $data): void
    {
        self::$data = $data;
    }

    public static function reset(): void
    {
        self::$data = [];
    }
}
