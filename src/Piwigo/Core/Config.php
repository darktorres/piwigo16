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

    private static ?self $singleton = null;

    private function __construct()
    {
    }

    /** Singleton handle — used by ServiceLocator; all data methods are static. */
    public static function instance(): self
    {
        return self::$singleton ??= new self();
    }

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

    // Security / passwords
    public static function passwordHash(): string
    {
        return self::getString('password_hash', 'pwg_password_hash');
    }
    public static function passwordVerify(): string
    {
        return self::getString('password_verify', 'pwg_password_verify');
    }

    // Mail cluster
    public static function smtpHost(): string
    {
        return self::getString('smtp_host', '');
    }
    public static function smtpUser(): string
    {
        return self::getString('smtp_user', '');
    }
    public static function smtpPassword(): string
    {
        return self::getString('smtp_password', '');
    }
    public static function smtpSecure(): ?string
    {
        $v = self::$data['smtp_secure'] ?? null;
        return $v !== null ? (string) $v : null;
    }
    public static function mailSenderName(): string
    {
        return self::getString('mail_sender_name', '');
    }
    public static function mailSenderEmail(): string
    {
        return self::getString('mail_sender_email', '');
    }
    public static function mailAllowHtml(): bool
    {
        return self::getBool('mail_allow_html', true);
    }
    public static function sendBccMailWebmaster(): bool
    {
        return self::getBool('send_bcc_mail_webmaster', false);
    }

    // Debug cluster
    public static function showPhpErrors(): int
    {
        return self::getInt('show_php_errors', E_ALL);
    }
    public static function showPhpErrorsOnFrontend(): bool
    {
        return self::getBool('show_php_errors_on_frontend', true);
    }
    public static function showQueries(): bool
    {
        return self::getBool('show_queries', false);
    }
    public static function debugL10n(): bool
    {
        return self::getBool('debug_l10n', false);
    }
    public static function debugTemplate(): bool
    {
        return self::getBool('debug_template', false);
    }
    public static function debugMail(): bool
    {
        return self::getBool('debug_mail', false);
    }
    public static function dieOnSqlError(): bool
    {
        return self::getBool('die_on_sql_error', false);
    }
    public static function templateForceCompile(): bool
    {
        return self::getBool('template_force_compile', false);
    }

    // Session cluster
    public static function sessionUseCookies(): bool
    {
        return self::getBool('session_use_cookies', true);
    }
    public static function sessionUseOnlyCookies(): bool
    {
        return self::getBool('session_use_only_cookies', true);
    }
    public static function sessionUseTransSid(): bool
    {
        return self::getBool('session_use_trans_sid', false);
    }
    public static function sessionName(): string
    {
        return self::getString('session_name', 'pwg_id');
    }
    public static function sessionSaveHandler(): string
    {
        return self::getString('session_save_handler', 'db');
    }
    public static function sessionLength(): int
    {
        return self::getInt('session_length', 3600);
    }
    public static function authorizeRemembering(): bool
    {
        return self::getBool('authorize_remembering', true);
    }
    public static function rememberMeName(): string
    {
        return self::getString('remember_me_name', 'pwg_remember');
    }
    public static function rememberMeLength(): int
    {
        return self::getInt('remember_me_length', 5184000);
    }
    public static function sessionUseIpAddress(): bool
    {
        return self::getBool('session_use_ip_address', true);
    }
    public static function sessionGcProbability(): int
    {
        return self::getInt('session_gc_probability', 1);
    }

    // Derivatives cluster
    public static function derivativeDefaultSize(): string
    {
        return self::getString('derivative_default_size', 'medium');
    }
    public static function derivativesStripMetadataThreshold(): int
    {
        return self::getInt('derivatives_strip_metadata_threshold', 256000);
    }
    public static function chmodValue(): int
    {
        return self::getInt('chmod_value', 0755);
    }
    public static function tiffRepresentativeExt(): string
    {
        return self::getString('tiff_representative_ext', 'png');
    }
    /** @return list<string> */
    public static function formatExtensions(): array
    {
        $v = self::$data['format_ext'] ?? ['cr2', 'tif', 'tiff', 'nef', 'dng', 'ai', 'psd'];
        return is_array($v) ? array_values($v) : [];
    }

    // Tags cluster
    public static function fullTagCloudItemsNumber(): int
    {
        return self::getInt('full_tag_cloud_items_number', 200);
    }
    public static function menubarTagCloudItemsNumber(): int
    {
        return self::getInt('menubar_tag_cloud_items_number', 20);
    }
    public static function menubarTagCloudContent(): string
    {
        return self::getString('menubar_tag_cloud_content', 'all_or_current');
    }
    public static function contentTagCloudItemsNumber(): int
    {
        return self::getInt('content_tag_cloud_items_number', 12);
    }
    public static function tagsLevels(): int
    {
        return self::getInt('tags_levels', 5);
    }
    public static function tagsDefaultDisplayMode(): string
    {
        return self::getString('tags_default_display_mode', 'cloud');
    }
    public static function tagLettersColumnNumber(): int
    {
        return self::getInt('tag_letters_column_number', 4);
    }

    // Uploads cluster
    public static function uploadFormAutomaticRotation(): bool
    {
        return self::getBool('upload_form_automatic_rotation', true);
    }
    public static function uploadFormAllTypes(): bool
    {
        return self::getBool('upload_form_all_types', false);
    }
    public static function uploadFormChunkSize(): int
    {
        return self::getInt('upload_form_chunk_size', 500);
    }
    public static function uploadFormMaxFileSize(): int
    {
        return self::getInt('upload_form_max_file_size', 1000);
    }
    public static function enableSynchronization(): bool
    {
        return self::getBool('enable_synchronization', true);
    }

    // Notification cluster (nbm)
    public static function nbmDefaultValueUserEnabled(): bool
    {
        return self::getBool('nbm_default_value_user_enabled', false);
    }
    public static function nbmListAllEnabledUsersToSend(): bool
    {
        return self::getBool('nbm_list_all_enabled_users_to_send', false);
    }
    public static function nbmMaxTreatmentTimeoutPercent(): float
    {
        $v = self::$data['nbm_max_treatment_timeout_percent'] ?? 0.8;
        return (float) $v;
    }
    public static function nbmTreatmentTimeoutDefault(): int
    {
        return self::getInt('nbm_treatment_timeout_default', 20);
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
        self::$singleton = null;
    }
}
