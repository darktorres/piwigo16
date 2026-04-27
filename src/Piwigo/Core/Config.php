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

    private static bool $attached = false;

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
        self::$attached = true;
    }

    /** Returns the array backing this facade (for pre-boot reads). */
    private static function src(): array
    {
        return self::$attached ? self::$data : ($GLOBALS['conf'] ?? []);
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return self::src()[$key] ?? $default;
    }

    public static function getString(string $key, string $default = ''): string
    {
        $v = self::src()[$key] ?? $default;
        return is_string($v) ? $v : (string) $v;
    }

    public static function getInt(string $key, int $default = 0): int
    {
        $v = self::src()[$key] ?? $default;
        return is_int($v) ? $v : (int) $v;
    }

    public static function getBool(string $key, bool $default = false): bool
    {
        $v = self::src()[$key] ?? $default;
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

    // ---- Content / display cluster --------------------------------------

    public static function topNumber(): int { return self::getInt('top_number', 15); }
    public static function antiFloodTime(): int { return self::getInt('anti-flood_time', 60); }
    public static function commentSpamReject(): bool { return self::getBool('comment_spam_reject', true); }
    public static function commentSpamMaxLinks(): int { return self::getInt('comment_spam_max_links', 3); }
    public static function commentsPageNbComments(): int { return self::getInt('comments_page_nb_comments', 10); }
    public static function showThumbnailCaption(): bool { return self::getBool('show_thumbnail_caption', true); }
    public static function allowRandomRepresentative(): bool { return self::getBool('allow_random_representative', false); }
    public static function representativeCacheOnLevel(): bool { return self::getBool('representative_cache_on_level', true); }
    public static function representativeCacheOnSubcats(): bool { return self::getBool('representative_cache_on_subcats', true); }
    public static function showVersion(): bool { return self::getBool('show_version', false); }
    public static function metaRef(): bool { return self::getBool('meta_ref', true); }
    public static function levelSeparator(): string { return self::getString('level_separator', ' / '); }
    public static function paginatePagesAround(): int { return self::getInt('paginate_pages_around', 2); }
    public static function pageBanner(): string { return self::getString('page_banner', ''); }
    public static function lightAlbumManagerThreshold(): int { return self::getInt('light_album_manager_threshold', 10000); }
    public static function lightSlideshow(): bool { return self::getBool('light_slideshow', true); }
    public static function indexNewIcon(): bool { return self::getBool('index_new_icon', true); }
    public static function menubarFilterIcon(): bool { return self::getBool('menubar_filter_icon', true); }
    public static function showGt(): bool { return self::getBool('show_gt', false); }

    /** @return array<string,mixed> */
    public static function links(): array
    {
        $v = self::$data['links'] ?? [];
        return is_array($v) ? $v : [];
    }

    /** @return array<string,string> */
    public static function randomIndexRedirect(): array
    {
        $v = self::$data['random_index_redirect'] ?? [];
        return is_array($v) ? $v : [];
    }

    /** @return list<string> */
    public static function headerNotes(): array
    {
        $v = self::$data['header_notes'] ?? [];
        return is_array($v) ? array_values($v) : [];
    }

    // ---- Permission / access cluster ------------------------------------

    /** @return list<int> */
    public static function availablePermissionLevels(): array
    {
        $v = self::$data['available_permission_levels'] ?? [0, 1, 2, 4, 8];
        return is_array($v) ? array_values($v) : [0, 1, 2, 4, 8];
    }

    public static function guestAccess(): bool { return self::getBool('guest_access', true); }
    public static function allowUserRegistration(): bool { return self::getBool('allow_user_registration', true); }
    public static function allowUserCustomization(): bool { return self::getBool('allow_user_customization', true); }
    public static function obligatoryUserMailAddress(): bool { return self::getBool('obligatory_user_mail_address', false); }
    public static function insensitiveCaseLogon(): bool { return self::getBool('insensitive_case_logon', false); }
    public static function doublePasswordTypeInAdmin(): bool { return self::getBool('double_password_type_in_admin', false); }
    public static function browserLanguage(): bool { return self::getBool('browser_language', true); }
    public static function externalAuthentification(): bool { return self::getBool('external_authentification', false); }
    public static function passwordResetDuration(): int { return self::getInt('password_reset_duration', 3600); }
    public static function passwordActivationDuration(): int { return self::getInt('password_activation_duration', 259200); }
    public static function passwordResetCodeDuration(): int { return self::getInt('password_reset_code_duration', 300); }
    public static function passConvert(): bool { return self::getBool('pass_convert', false); }

    /** @return array<string,string> */
    public static function userFields(): array
    {
        $v = self::$data['user_fields'] ?? ['id' => 'id', 'username' => 'username', 'password' => 'password', 'email' => 'mail_address'];
        return is_array($v) ? $v : ['id' => 'id', 'username' => 'username', 'password' => 'password', 'email' => 'mail_address'];
    }

    public static function usersTable(): ?string
    {
        return isset(self::$data['users_table']) ? (string) self::$data['users_table'] : null;
    }

    // ---- Calendar cluster -----------------------------------------------

    public static function calendarDatefield(): string { return self::getString('calendar_datefield', 'date_creation'); }
    public static function calendarShowAny(): bool { return self::getBool('calendar_show_any', true); }
    public static function calendarShowEmpty(): bool { return self::getBool('calendar_show_empty', true); }

    // ---- Album defaults cluster -----------------------------------------

    public static function newcatDefaultCommentable(): bool { return self::getBool('newcat_default_commentable', true); }
    public static function newcatDefaultVisible(): bool { return self::getBool('newcat_default_visible', true); }
    public static function newcatDefaultStatus(): string { return self::getString('newcat_default_status', 'public'); }
    public static function newcatDefaultPosition(): string { return self::getString('newcat_default_position', 'first'); }
    public static function inheritanceByDefault(): bool { return self::getBool('inheritance_by_default', false); }
    public static function albumDescriptionOnAllPages(): bool { return self::getBool('album_description_on_all_pages', false); }
    public static function albumMoveDelayBeforeAutoOpening(): int { return self::getInt('album_move_delay_before_auto_opening', 3000); }
    public static function linkedAlbumSearchLimit(): int { return self::getInt('linked_album_search_limit', 100); }
    public static function relatedAlbumsDisplayLimit(): int { return self::getInt('related_albums_display_limit', 20); }
    public static function relatedAlbumsMaximumItemsToCompute(): int { return self::getInt('related_albums_maximum_items_to_compute', 1000); }

    // ---- URL cluster ----------------------------------------------------

    public static function questionMarkInUrls(): bool { return self::getBool('question_mark_in_urls', true); }
    public static function phpExtensionInUrls(): bool { return self::getBool('php_extension_in_urls', true); }
    public static function categoryUrlStyle(): string { return self::getString('category_url_style', 'id'); }
    public static function pictureUrlStyle(): string { return self::getString('picture_url_style', 'id'); }
    public static function tagUrlStyle(): string { return self::getString('tag_url_style', 'id-tag'); }
    public static function urlPort(): string { return self::getString('url_port', 'none'); }
    public static function originalUrlProtection(): string { return self::getString('original_url_protection', ''); }

    // ---- Proxy cluster --------------------------------------------------

    public static function useProxy(): bool { return self::getBool('use_proxy', false); }
    public static function proxyServer(): string { return self::getString('proxy_server', ''); }
    public static function proxyAuth(): string { return self::getString('proxy_auth', ''); }

    // ---- Admin / dashboard cluster --------------------------------------

    public static function adminTheme(): string { return self::getString('admin_theme', 'clear'); }
    public static function enablePlugins(): bool { return self::getBool('enable_plugins', true); }
    public static function allowWebServices(): bool { return self::getBool('allow_web_services', true); }
    public static function wsMaxImagesPerPage(): int { return self::getInt('ws_max_images_per_page', 500); }
    public static function wsMaxUsersPerPage(): int { return self::getInt('ws_max_users_per_page', 1000); }
    public static function showNewsletterSubscription(): bool { return self::getBool('show_newsletter_subscription', true); }
    public static function showPiwigoLatestNews(): bool { return self::getBool('show_piwigo_latest_news', true); }
    public static function dashboardCheckForUpdates(): bool { return self::getBool('dashboard_check_for_updates', true); }
    public static function dashboardActivityNbWeeks(): int { return self::getInt('dashboard_activity_nb_weeks', 4); }
    public static function activityDisplayConnections(): string { return self::getString('activity_display_connections', 'all'); }
    public static function showTemplateInSideMenu(): bool { return self::getBool('show_template_in_side_menu', false); }
    public static function addCacheToStorageChart(): bool { return self::getBool('add_cache_to_storage_chart', true); }
    public static function enableCoreUpdate(): bool { return self::getBool('enable_core_update', true); }
    public static function enableExtensionsInstall(): bool { return self::getBool('enable_extensions_install', true); }
    public static function checkUpgradeFeed(): bool { return self::getBool('check_upgrade_feed', false); }
    public static function statCompareYearDisplayed(): int { return self::getInt('stat_compare_year_displayed', 5); }

    /** @return array<string,mixed> */
    public static function filterPages(): array
    {
        $v = self::$data['filter_pages'] ?? [];
        return is_array($v) ? $v : [];
    }

    // ---- Update / PEM cluster -------------------------------------------

    public static function updateNotifyCheckPeriod(): int { return self::getInt('update_notify_check_period', 86400); }
    public static function updateNotifyReminderPeriod(): int { return self::getInt('update_notify_reminder_period', 604800); }
    public static function sendPiwigoInfos(): bool { return self::getBool('send_piwigo_infos', true); }
    public static function pemPluginsCategory(): int { return self::getInt('pem_plugins_category', 12); }
    public static function pemThemesCategory(): int { return self::getInt('pem_themes_category', 10); }
    public static function pemLanguagesCategory(): int { return self::getInt('pem_languages_category', 8); }
    public static function alternativePemUrl(): string { return self::getString('alternative_pem_url', ''); }

    // ---- History cluster ------------------------------------------------

    public static function nbLogsPage(): int { return self::getInt('nb_logs_page', 300); }
    public static function historyAutopurgeEvery(): int { return self::getInt('history_autopurge_every', 1021); }
    public static function historyAutopurgeKeepLines(): int { return self::getInt('history_autopurge_keep_lines', 1000000); }
    public static function historyAutopurgeBlocksize(): int { return self::getInt('history_autopurge_blocksize', 50000); }
    public static function fsQuickCheckPeriod(): int { return self::getInt('fs_quick_check_period', 86400); }

    // ---- Metadata cluster -----------------------------------------------

    public static function showIptc(): bool { return self::getBool('show_iptc', false); }
    /** @return array<string,string> */
    public static function showIptcMapping(): array
    {
        $v = self::$data['show_iptc_mapping'] ?? ['iptc_keywords' => '2#025', 'iptc_caption_writer' => '2#122', 'iptc_byline_title' => '2#085', 'iptc_caption' => '2#120'];
        return is_array($v) ? $v : [];
    }
    public static function useIptc(): bool { return self::getBool('use_iptc', false); }
    /** @return array<string,string> */
    public static function useIptcMapping(): array
    {
        $v = self::$data['use_iptc_mapping'] ?? ['keywords' => '2#025', 'date_creation' => '2#055', 'author' => '2#122', 'name' => '2#005', 'comment' => '2#120'];
        return is_array($v) ? $v : [];
    }
    public static function showExif(): bool { return self::getBool('show_exif', true); }
    /** @return list<string> */
    public static function showExifFields(): array
    {
        $v = self::$data['show_exif_fields'] ?? ['Make', 'Model', 'DateTimeOriginal', 'COMPUTED;ApertureFNumber'];
        return is_array($v) ? array_values($v) : [];
    }
    public static function useExif(): bool { return self::getBool('use_exif', true); }
    /** @return array<string,string> */
    public static function useExifMapping(): array
    {
        $v = self::$data['use_exif_mapping'] ?? ['date_creation' => 'DateTimeOriginal'];
        return is_array($v) ? $v : [];
    }
    public static function allowHtmlInMetadata(): bool { return self::getBool('allow_html_in_metadata', false); }
    public static function metadataKeywordSeparatorRegex(): string { return self::getString('metadata_keyword_separator_regex', '/[.,;]/'); }
    public static function pdfViewerFilesizeThreshold(): int { return self::getInt('pdf_viewer_filesize_threshold', 5); }

    // ---- Sync cluster ---------------------------------------------------

    public static function syncCharsRegex(): string { return self::getString('sync_chars_regex', '/^[a-zA-Z0-9-_.]+$/'); }
    /** @return list<string> */
    public static function syncExcludeFolders(): array
    {
        $v = self::$data['sync_exclude_folders'] ?? [];
        return is_array($v) ? array_values($v) : [];
    }
    public static function checksumComputeBlocksize(): int { return self::getInt('checksum_compute_blocksize', 50); }
    public static function uploadDetectDuplicate(): bool { return self::getBool('upload_detect_duplicate', true); }
    public static function neverDeleteOriginals(): bool { return self::getBool('never_delete_originals', false); }

    // ---- Image processing cluster ---------------------------------------

    public static function graphicsLibrary(): string { return self::getString('graphics_library', 'auto'); }
    public static function originalResize(): bool { return self::getBool('original_resize', false); }
    public static function originalResizeMaxwidth(): int { return self::getInt('original_resize_maxwidth', 2000); }
    public static function originalResizeMaxheight(): int { return self::getInt('original_resize_maxheight', 2000); }
    public static function originalResizeQuality(): int { return self::getInt('original_resize_quality', 95); }
    public static function animatedWebpCompressionQuality(): int { return self::getInt('animated_webp_compression_quality', 70); }
    public static function maxRequests(): int { return self::getInt('max_requests', 3); }

    // ---- Lounge cluster -------------------------------------------------

    public static function loungeActive(): bool { return self::getBool('lounge_active', false); }
    public static function loungeActivateThreshold(): int { return self::getInt('lounge_activate_threshold', 1); }
    public static function loungeMaxDuration(): int { return self::getInt('lounge_max_duration', 300); }

    // ---- NBM extended cluster -------------------------------------------

    public static function nbmSendHtmlMail(): bool { return self::getBool('nbm_send_html_mail', true); }
    public static function nbmSendMailAs(): string { return self::getString('nbm_send_mail_as', ''); }
    public static function nbmSendDetailedContent(): bool { return self::getBool('nbm_send_detailed_content', true); }
    public static function nbmComplementaryMailContent(): string { return self::getString('nbm_complementary_mail_content', ''); }
    public static function nbmSendRecentPostDates(): bool { return self::getBool('nbm_send_recent_post_dates', true); }

    // ---- Template cluster -----------------------------------------------

    public static function templateCompileCheck(): bool { return self::getBool('template_compile_check', true); }
    public static function templateCombineFiles(): bool { return self::getBool('template_combine_files', true); }
    public static function compiledTemplateCacheLanguage(): bool { return self::getBool('compiled_template_cache_language', false); }

    public static function extentsForTemplates(): ?string
    {
        $v = self::src()['extents_for_templates'] ?? null;
        if ($v === null) return null;
        return is_string($v) ? $v : serialize($v);
    }

    // ---- Slideshow cluster ----------------------------------------------

    public static function slideshowPeriod(): int { return self::getInt('slideshow_period', 4); }
    public static function slideshowPeriodMin(): int { return self::getInt('slideshow_period_min', 1); }
    public static function slideshowPeriodMax(): int { return self::getInt('slideshow_period_max', 10); }
    public static function slideshowPeriodStep(): int { return self::getInt('slideshow_period_step', 1); }
    public static function slideshowRepeat(): bool { return self::getBool('slideshow_repeat', true); }

    // ---- Misc DB-only keys (no default in config_default.inc.php) -------

    public static function orderBy(): string { return self::getString('order_by', ''); }
    public static function orderByCustom(): ?string
    {
        return isset(self::$data['order_by_custom']) ? (string) self::$data['order_by_custom'] : null;
    }
    public static function orderByInsideCategory(): string { return self::getString('order_by_inside_category', ''); }
    public static function orderByInsideCategoryCustom(): ?string
    {
        return isset(self::$data['order_by_inside_category_custom']) ? (string) self::$data['order_by_inside_category_custom'] : null;
    }

    public static function mobilTheme(): string { return self::getString('mobile_theme', ''); }
    public static function batchManagerImagesPerPageGlobal(): int { return self::getInt('batch_manager_images_per_page_global', 20); }
    public static function batchManagerImagesPerPageUnit(): int { return self::getInt('batch_manager_images_per_page_unit', 5); }

    /** @return list<int> */
    public static function rateItems(): array
    {
        $v = self::$data['rate_items'] ?? [0, 1, 2, 3, 4, 5];
        return is_array($v) ? array_values($v) : [0, 1, 2, 3, 4, 5];
    }
    public static function defaultRedirectMethod(): string { return self::getString('default_redirect_method', 'http'); }
    public static function uniquenessMode(): string { return self::getString('uniqueness_mode', 'md5sum'); }
    public static function quickSearchIncludeSubAlbums(): bool { return self::getBool('quick_search_include_sub_albums', false); }
    public static function rssReedAuthor(): string { return self::getString('rss_feed_author', 'Piwigo notifier'); }
    public static function apiKeyDuration(): array
    {
        $v = self::$data['api_key_duration'] ?? ['30', '90', '180', '365', 'custom'];
        return is_array($v) ? $v : ['30', '90', '180', '365', 'custom'];
    }
    /** @return list<string> */
    public static function apiKeyForbiddenMethods(): array
    {
        $v = self::$data['api_key_forbidden_methods'] ?? [];
        return is_array($v) ? array_values($v) : [];
    }

    /** @return array<string,mixed> */
    public static function defaultFiltersViews(): array
    {
        $v = self::$data['default_filters_views'] ?? [];
        return is_array($v) ? $v : [];
    }

    // ---- Bulk access / Wave C support -----------------------------------

    /** @return array<string,mixed> */
    public static function all(): array
    {
        return self::$data;
    }

    // ---- Existence check ------------------------------------------------

    public static function has(string $key): bool
    {
        return array_key_exists($key, self::src());
    }

    public static function delete(string $key): void
    {
        unset(self::$data[$key]);
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
        self::$attached = true;
    }

    public static function reset(): void
    {
        self::$data = [];
        self::$attached = false;
        self::$singleton = null;
    }
}
