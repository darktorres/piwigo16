<?php

declare(strict_types=1);

namespace Piwigo\Config;

/**
 * Typed facade over Piwigo's runtime configuration (P13, retyped in full
 * during Config generic-accessor removal). Every real config key is a
 * private static typed property -- named getter/setter pair, no generic
 * string-keyed surface (no override()/has()/delete()/loadArray(), all
 * deleted; see design doc for the full rationale). DB-backed persistence
 * stays a two-part split (Legacy Coupling Retirement Phase 5, narrowed in
 * Phase 8, 8d, unchanged by this rename): this class is the static typed
 * read/in-memory-write layer; Piwigo\Config\ConfigService is the DI/
 * Doctrine-backed persistence layer, constructor-injected where possible
 * and reached via Piwigo\Config\CurrentConfigService::get() everywhere
 * else. Renamed from Config to CurrentConfig to match this codebase's own
 * established Current* convention (CurrentUser, CurrentLogger,
 * CurrentPaths, CurrentTemplate, CurrentPersistentCache,
 * CurrentConfigService) -- this was the one class of that same shape
 * breaking the pattern.
 *
 * DB credentials (db_host/db_port/db_driver/db_base/db_user/db_password/
 * db_prefix) and the handful of sysadmin-lockable settings
 * (show_php_errors/show_php_errors_on_frontend/apache_authentication/
 * external_authentification/allowed_hosts) are NOT here -- they moved to
 * Piwigo\Db\DbCredentials (env-only) and Piwigo\Config\DeploymentPolicy
 * (file-only) respectively, closing the "which source wins" overlap those
 * keys used to have with this DB-backed class.
 *
 * Adding a new key: add a property + getter + setter by hand (matching
 * the shape of any neighboring one) -- there is no more schema/generator
 * to run. loadConfFromDb() discovers every property reflectively, so a
 * new property is picked up automatically as long as its name matches a
 * real `config` table row.
 */
final class CurrentConfig
{
    // === activate_comments ===
    /**
     * Enable or disable user comments on photos gallery-wide.
     */
    private static bool $activateComments = true;

    public static function activateComments(): bool
    {
        return self::$activateComments;
    }

    public static function setActivateComments(bool $value): void
    {
        self::$activateComments = $value;
    }

    // === activity_display_connections ===
    /**
     * Which connection events to show in the activity log: all, admin, or none.
     */
    private static string $activityDisplayConnections = 'all';

    public static function activityDisplayConnections(): string
    {
        return self::$activityDisplayConnections;
    }

    public static function setActivityDisplayConnections(string $value): void
    {
        self::$activityDisplayConnections = $value;
    }

    // === add_cache_to_storage_chart ===
    /**
     * Include cache files in the storage usage chart on the dashboard.
     */
    private static bool $addCacheToStorageChart = true;

    public static function addCacheToStorageChart(): bool
    {
        return self::$addCacheToStorageChart;
    }

    public static function setAddCacheToStorageChart(bool $value): void
    {
        self::$addCacheToStorageChart = $value;
    }

    // === admin_theme ===
    /**
     * Site-wide fallback admin theme (clear, default, or roma) used when a user
     * has no admin_theme preference of their own yet.
     */
    private static string $adminTheme = 'clear';

    public static function adminTheme(): string
    {
        return self::$adminTheme;
    }

    public static function setAdminTheme(string $value): void
    {
        self::$adminTheme = $value;
    }

    // === album_description_on_all_pages ===
    /**
     * Show the album description on every paginated page, not just the first.
     */
    private static bool $albumDescriptionOnAllPages = false;

    public static function albumDescriptionOnAllPages(): bool
    {
        return self::$albumDescriptionOnAllPages;
    }

    public static function setAlbumDescriptionOnAllPages(bool $value): void
    {
        self::$albumDescriptionOnAllPages = $value;
    }

    // === album_move_delay_before_auto_opening ===
    /**
     * Milliseconds to wait before auto-expanding an album drop-target during
     * drag-and-drop.
     */
    private static int $albumMoveDelayBeforeAutoOpening = 3000;

    public static function albumMoveDelayBeforeAutoOpening(): int
    {
        return self::$albumMoveDelayBeforeAutoOpening;
    }

    public static function setAlbumMoveDelayBeforeAutoOpening(int $value): void
    {
        self::$albumMoveDelayBeforeAutoOpening = $value;
    }

    // === allow_html_descriptions ===
    /**
     * Allow HTML markup in photo and album descriptions.
     */
    private static bool $allowHtmlDescriptions = true;

    public static function allowHtmlDescriptions(): bool
    {
        return self::$allowHtmlDescriptions;
    }

    public static function setAllowHtmlDescriptions(bool $value): void
    {
        self::$allowHtmlDescriptions = $value;
    }

    // === allow_html_in_metadata ===
    /**
     * Allow HTML in metadata values extracted from photo files.
     */
    private static bool $allowHtmlInMetadata = false;

    public static function allowHtmlInMetadata(): bool
    {
        return self::$allowHtmlInMetadata;
    }

    public static function setAllowHtmlInMetadata(bool $value): void
    {
        self::$allowHtmlInMetadata = $value;
    }

    // === allow_random_representative ===
    /**
     * Allow a random photo to represent an album that has no explicit
     * representative set.
     */
    private static bool $allowRandomRepresentative = false;

    public static function allowRandomRepresentative(): bool
    {
        return self::$allowRandomRepresentative;
    }

    public static function setAllowRandomRepresentative(bool $value): void
    {
        self::$allowRandomRepresentative = $value;
    }

    // === allow_user_customization ===
    /**
     * Let registered users change their own display preferences.
     */
    private static bool $allowUserCustomization = true;

    public static function allowUserCustomization(): bool
    {
        return self::$allowUserCustomization;
    }

    public static function setAllowUserCustomization(bool $value): void
    {
        self::$allowUserCustomization = $value;
    }

    // === allow_user_registration ===
    /**
     * Allow new users to self-register from the public gallery.
     */
    private static bool $allowUserRegistration = true;

    public static function allowUserRegistration(): bool
    {
        return self::$allowUserRegistration;
    }

    public static function setAllowUserRegistration(bool $value): void
    {
        self::$allowUserRegistration = $value;
    }

    // === allow_web_services ===
    /**
     * Enable the Piwigo web-service (API) endpoint.
     */
    private static bool $allowWebServices = true;

    public static function allowWebServices(): bool
    {
        return self::$allowWebServices;
    }

    public static function setAllowWebServices(bool $value): void
    {
        self::$allowWebServices = $value;
    }

    // === alternative_pem_url ===
    /**
     * Override URL for the Piwigo Extensions Manager repository.
     */
    private static string $alternativePemUrl = '';

    public static function alternativePemUrl(): string
    {
        return self::$alternativePemUrl;
    }

    public static function setAlternativePemUrl(string $value): void
    {
        self::$alternativePemUrl = $value;
    }

    // === animated_webp_compression_quality ===
    /**
     * Quality level (1-100) for animated WebP derivative encoding.
     */
    private static int $animatedWebpCompressionQuality = 70;

    public static function animatedWebpCompressionQuality(): int
    {
        return self::$animatedWebpCompressionQuality;
    }

    public static function setAnimatedWebpCompressionQuality(int $value): void
    {
        self::$animatedWebpCompressionQuality = $value;
    }

    // === anti-flood_time ===
    /**
     * Minimum seconds between comment posts from the same user to prevent spam.
     */
    private static int $antiFloodTime = 60;

    public static function antiFloodTime(): int
    {
        return self::$antiFloodTime;
    }

    public static function setAntiFloodTime(int $value): void
    {
        self::$antiFloodTime = $value;
    }

    // === auth_key_duration ===
    /**
     * Lifetime in seconds for single-use authentication keys sent in emails.
     */
    private static int $authKeyDuration = 259200;

    public static function authKeyDuration(): int
    {
        return self::$authKeyDuration;
    }

    public static function setAuthKeyDuration(int $value): void
    {
        self::$authKeyDuration = $value;
    }

    // === authorize_remembering ===
    /**
     * Allow users to use the remember-me persistent login cookie.
     */
    private static bool $authorizeRemembering = true;

    public static function authorizeRemembering(): bool
    {
        return self::$authorizeRemembering;
    }

    public static function setAuthorizeRemembering(bool $value): void
    {
        self::$authorizeRemembering = $value;
    }

    // === batch_manager_images_per_page_global ===
    /**
     * Number of photos shown per page in the batch-manager global view.
     */
    private static int $batchManagerImagesPerPageGlobal = 20;

    public static function batchManagerImagesPerPageGlobal(): int
    {
        return self::$batchManagerImagesPerPageGlobal;
    }

    public static function setBatchManagerImagesPerPageGlobal(int $value): void
    {
        self::$batchManagerImagesPerPageGlobal = $value;
    }

    // === batch_manager_images_per_page_unit ===
    /**
     * Number of photos shown per page in the batch-manager unit view.
     */
    private static int $batchManagerImagesPerPageUnit = 5;

    public static function batchManagerImagesPerPageUnit(): int
    {
        return self::$batchManagerImagesPerPageUnit;
    }

    public static function setBatchManagerImagesPerPageUnit(int $value): void
    {
        self::$batchManagerImagesPerPageUnit = $value;
    }

    // === browser_language ===
    /**
     * Automatically detect and use the visitor browser language preference.
     */
    private static bool $browserLanguage = true;

    public static function browserLanguage(): bool
    {
        return self::$browserLanguage;
    }

    public static function setBrowserLanguage(bool $value): void
    {
        self::$browserLanguage = $value;
    }

    // === cache.backend ===
    /**
     * Cache driver to use: file or redis.
     */
    private static string $cacheBackend = 'file';

    public static function cacheBackend(): string
    {
        return self::$cacheBackend;
    }

    public static function setCacheBackend(string $value): void
    {
        self::$cacheBackend = $value;
    }

    // === cache.default_ttl ===
    /**
     * Default cache entry time-to-live in seconds.
     */
    private static int $cacheDefaultTtl = 86400;

    public static function cacheDefaultTtl(): int
    {
        return self::$cacheDefaultTtl;
    }

    public static function setCacheDefaultTtl(int $value): void
    {
        self::$cacheDefaultTtl = $value;
    }

    // === cache.namespace ===
    /**
     * Namespace prefix for all cache keys, useful when sharing a Redis instance.
     */
    private static string $cacheNamespace = '';

    public static function cacheNamespace(): string
    {
        return self::$cacheNamespace;
    }

    public static function setCacheNamespace(string $value): void
    {
        self::$cacheNamespace = $value;
    }

    // === cache.redis_url ===
    /**
     * Redis connection DSN used when cache.backend is redis.
     */
    private static string $cacheRedisUrl = 'redis://localhost:6379';

    public static function cacheRedisUrl(): string
    {
        return self::$cacheRedisUrl;
    }

    public static function setCacheRedisUrl(string $value): void
    {
        self::$cacheRedisUrl = $value;
    }

    // === calendar_datefield ===
    /**
     * Date field used for the calendar view: date_creation or date_available.
     */
    private static string $calendarDatefield = 'date_creation';

    public static function calendarDatefield(): string
    {
        return self::$calendarDatefield;
    }

    public static function setCalendarDatefield(string $value): void
    {
        self::$calendarDatefield = $value;
    }

    // === calendar_show_any ===
    /**
     * Show an Any link in the calendar so visitors can view photos without a date
     * filter.
     */
    private static bool $calendarShowAny = true;

    public static function calendarShowAny(): bool
    {
        return self::$calendarShowAny;
    }

    public static function setCalendarShowAny(bool $value): void
    {
        self::$calendarShowAny = $value;
    }

    // === calendar_show_empty ===
    /**
     * Show months and years with no photos in the calendar navigation.
     */
    private static bool $calendarShowEmpty = true;

    public static function calendarShowEmpty(): bool
    {
        return self::$calendarShowEmpty;
    }

    public static function setCalendarShowEmpty(bool $value): void
    {
        self::$calendarShowEmpty = $value;
    }

    // === category_url_style ===
    /**
     * URL format for album links: id or id-name.
     */
    private static string $categoryUrlStyle = 'id';

    public static function categoryUrlStyle(): string
    {
        return self::$categoryUrlStyle;
    }

    public static function setCategoryUrlStyle(string $value): void
    {
        self::$categoryUrlStyle = $value;
    }

    // === checksum_compute_blocksize ===
    /**
     * Number of photos per block when computing file checksums in batch.
     */
    private static int $checksumComputeBlocksize = 50;

    public static function checksumComputeBlocksize(): int
    {
        return self::$checksumComputeBlocksize;
    }

    public static function setChecksumComputeBlocksize(int $value): void
    {
        self::$checksumComputeBlocksize = $value;
    }

    // === comment_spam_max_links ===
    /**
     * Maximum number of links allowed in a single comment before it is rejected as
     * spam.
     */
    private static int $commentSpamMaxLinks = 3;

    public static function commentSpamMaxLinks(): int
    {
        return self::$commentSpamMaxLinks;
    }

    public static function setCommentSpamMaxLinks(int $value): void
    {
        self::$commentSpamMaxLinks = $value;
    }

    // === comment_spam_reject ===
    /**
     * Silently reject comments that exceed the spam link threshold.
     */
    private static bool $commentSpamReject = true;

    public static function commentSpamReject(): bool
    {
        return self::$commentSpamReject;
    }

    public static function setCommentSpamReject(bool $value): void
    {
        self::$commentSpamReject = $value;
    }

    // === comments_author_mandatory ===
    /**
     * Require commenters to supply an author name.
     */
    private static bool $commentsAuthorMandatory = false;

    public static function commentsAuthorMandatory(): bool
    {
        return self::$commentsAuthorMandatory;
    }

    public static function setCommentsAuthorMandatory(bool $value): void
    {
        self::$commentsAuthorMandatory = $value;
    }

    // === comments_email_mandatory ===
    /**
     * Require commenters to supply an email address.
     */
    private static bool $commentsEmailMandatory = false;

    public static function commentsEmailMandatory(): bool
    {
        return self::$commentsEmailMandatory;
    }

    public static function setCommentsEmailMandatory(bool $value): void
    {
        self::$commentsEmailMandatory = $value;
    }

    // === comments_enable_website ===
    /**
     * Show a website field in the comment form.
     */
    private static bool $commentsEnableWebsite = true;

    public static function commentsEnableWebsite(): bool
    {
        return self::$commentsEnableWebsite;
    }

    public static function setCommentsEnableWebsite(bool $value): void
    {
        self::$commentsEnableWebsite = $value;
    }

    // === comments_forall ===
    /**
     * Allow unauthenticated (guest) visitors to post comments.
     */
    private static bool $commentsForall = false;

    public static function commentsForall(): bool
    {
        return self::$commentsForall;
    }

    public static function setCommentsForall(bool $value): void
    {
        self::$commentsForall = $value;
    }

    // === comments_order ===
    /**
     * Sort order for comment display: ASC (oldest first) or DESC (newest first).
     */
    private static string $commentsOrder = 'ASC';

    public static function commentsOrder(): string
    {
        return self::$commentsOrder;
    }

    public static function setCommentsOrder(string $value): void
    {
        self::$commentsOrder = $value;
    }

    // === comments_page_nb_comments ===
    /**
     * Number of comments shown per page on the admin comments page.
     */
    private static int $commentsPageNbComments = 10;

    public static function commentsPageNbComments(): int
    {
        return self::$commentsPageNbComments;
    }

    public static function setCommentsPageNbComments(int $value): void
    {
        self::$commentsPageNbComments = $value;
    }

    // === comments_validation ===
    /**
     * Require admin approval before newly posted comments appear publicly.
     */
    private static bool $commentsValidation = false;

    public static function commentsValidation(): bool
    {
        return self::$commentsValidation;
    }

    public static function setCommentsValidation(bool $value): void
    {
        self::$commentsValidation = $value;
    }

    // === compiled_template_cache_language ===
    /**
     * Include the active language in the compiled-template cache key.
     */
    private static bool $compiledTemplateCacheLanguage = false;

    public static function compiledTemplateCacheLanguage(): bool
    {
        return self::$compiledTemplateCacheLanguage;
    }

    public static function setCompiledTemplateCacheLanguage(bool $value): void
    {
        self::$compiledTemplateCacheLanguage = $value;
    }

    // === content_tag_cloud_items_number ===
    /**
     * Maximum number of tags shown in the content-area tag cloud.
     */
    private static int $contentTagCloudItemsNumber = 12;

    public static function contentTagCloudItemsNumber(): int
    {
        return self::$contentTagCloudItemsNumber;
    }

    public static function setContentTagCloudItemsNumber(int $value): void
    {
        self::$contentTagCloudItemsNumber = $value;
    }

    // === count_orphans ===
    /**
     * Cached count of images belonging to no album; null means "not computed
     * yet" (recomputed lazily by ImageService::countOrphans(), invalidated by
     * PermissionCacheInvalidator).
     */
    private static ?int $countOrphans = null;

    public static function countOrphans(): ?int
    {
        return self::$countOrphans;
    }

    public static function setCountOrphans(?int $value): void
    {
        self::$countOrphans = $value;
    }

    // === dashboard_activity_nb_weeks ===
    /**
     * Number of weeks of activity data shown on the admin dashboard.
     */
    private static int $dashboardActivityNbWeeks = 4;

    public static function dashboardActivityNbWeeks(): int
    {
        return self::$dashboardActivityNbWeeks;
    }

    public static function setDashboardActivityNbWeeks(int $value): void
    {
        self::$dashboardActivityNbWeeks = $value;
    }

    // === dashboard_check_for_updates ===
    /**
     * Check for Piwigo core updates on the admin dashboard.
     */
    private static bool $dashboardCheckForUpdates = true;

    public static function dashboardCheckForUpdates(): bool
    {
        return self::$dashboardCheckForUpdates;
    }

    public static function setDashboardCheckForUpdates(bool $value): void
    {
        self::$dashboardCheckForUpdates = $value;
    }

    // === data_dir_checked ===
    /**
     * Presence-only marker: once set (to '1'), Template's data-directory
     * writability check is permanently skipped. Genuine absence until the check
     * first passes, matching the gallery_url/last_major_update convention.
     */
    private static ?string $dataDirChecked = null;

    public static function dataDirChecked(): ?string
    {
        return self::$dataDirChecked;
    }

    public static function setDataDirChecked(?string $value): void
    {
        self::$dataDirChecked = $value;
    }

    // === data_location ===
    /**
     * Relative path from the Piwigo root to the writable data directory.
     */
    private static string $dataLocation = '_data/';

    public static function dataLocation(): string
    {
        return self::$dataLocation;
    }

    public static function setDataLocation(string $value): void
    {
        self::$dataLocation = $value;
    }

    // === debug_l10n ===
    /**
     * Highlight untranslated strings in the UI for l10n debugging.
     */
    private static bool $debugL10n = false;

    public static function debugL10n(): bool
    {
        return self::$debugL10n;
    }

    public static function setDebugL10n(bool $value): void
    {
        self::$debugL10n = $value;
    }

    // === debug_mail ===
    /**
     * Log all outgoing mail to a file instead of sending.
     */
    private static bool $debugMail = false;

    public static function debugMail(): bool
    {
        return self::$debugMail;
    }

    public static function setDebugMail(bool $value): void
    {
        self::$debugMail = $value;
    }

    // === debug_template ===
    /**
     * Add template debugging information to rendered pages.
     */
    private static bool $debugTemplate = false;

    public static function debugTemplate(): bool
    {
        return self::$debugTemplate;
    }

    public static function setDebugTemplate(bool $value): void
    {
        self::$debugTemplate = $value;
    }

    // === default_redirect_method ===
    /**
     * HTTP redirect method Piwigo uses internally: http or html.
     */
    private static string $defaultRedirectMethod = 'http';

    public static function defaultRedirectMethod(): string
    {
        return self::$defaultRedirectMethod;
    }

    public static function setDefaultRedirectMethod(string $value): void
    {
        self::$defaultRedirectMethod = $value;
    }

    // === default_user_id ===
    /**
     * User ID whose settings serve as defaults for new accounts.
     */
    private static int $defaultUserId = 2;

    public static function defaultUserId(): int
    {
        return self::$defaultUserId;
    }

    public static function setDefaultUserId(int $value): void
    {
        self::$defaultUserId = $value;
    }

    // === derivative_default_size ===
    /**
     * Default derivative size name served when no size is specified.
     */
    private static string $derivativeDefaultSize = 'medium';

    public static function derivativeDefaultSize(): string
    {
        return self::$derivativeDefaultSize;
    }

    public static function setDerivativeDefaultSize(string $value): void
    {
        self::$derivativeDefaultSize = $value;
    }

    // === derivative_url_style ===
    /**
     * Derivative URL format: 0 = auto (static link if already cached, else routed
     * through i.php), 1 = always a static link, 2 = always routed through i.php.
     */
    private static int $derivativeUrlStyle = 2;

    public static function derivativeUrlStyle(): int
    {
        return self::$derivativeUrlStyle;
    }

    public static function setDerivativeUrlStyle(int $value): void
    {
        self::$derivativeUrlStyle = $value;
    }

    // === derivatives_strip_metadata_threshold ===
    /**
     * File size in bytes above which EXIF/IPTC metadata is stripped from
     * derivatives.
     */
    private static int $derivativesStripMetadataThreshold = 256000;

    public static function derivativesStripMetadataThreshold(): int
    {
        return self::$derivativesStripMetadataThreshold;
    }

    public static function setDerivativesStripMetadataThreshold(int $value): void
    {
        self::$derivativesStripMetadataThreshold = $value;
    }

    // === die_on_sql_error ===
    /**
     * Halt execution immediately when a database query fails.
     */
    private static bool $dieOnSqlError = false;

    public static function dieOnSqlError(): bool
    {
        return self::$dieOnSqlError;
    }

    public static function setDieOnSqlError(bool $value): void
    {
        self::$dieOnSqlError = $value;
    }

    // === display_fromto ===
    /**
     * Show the date range of photos in album and search results headers.
     */
    private static bool $displayFromto = false;

    public static function displayFromto(): bool
    {
        return self::$displayFromto;
    }

    public static function setDisplayFromto(bool $value): void
    {
        self::$displayFromto = $value;
    }

    // === double_password_type_in_admin ===
    /**
     * Require admins to enter a new password twice when setting it.
     */
    private static bool $doublePasswordTypeInAdmin = false;

    public static function doublePasswordTypeInAdmin(): bool
    {
        return self::$doublePasswordTypeInAdmin;
    }

    public static function setDoublePasswordTypeInAdmin(bool $value): void
    {
        self::$doublePasswordTypeInAdmin = $value;
    }

    // === email_admin_on_comment ===
    /**
     * Send an email to the administrators when a valid comment is entered.
     */
    private static bool $emailAdminOnComment = false;

    public static function emailAdminOnComment(): bool
    {
        return self::$emailAdminOnComment;
    }

    public static function setEmailAdminOnComment(bool $value): void
    {
        self::$emailAdminOnComment = $value;
    }

    // === email_admin_on_comment_deletion ===
    /**
     * Send an email to the administrators when a comment is deleted.
     */
    private static bool $emailAdminOnCommentDeletion = false;

    public static function emailAdminOnCommentDeletion(): bool
    {
        return self::$emailAdminOnCommentDeletion;
    }

    public static function setEmailAdminOnCommentDeletion(bool $value): void
    {
        self::$emailAdminOnCommentDeletion = $value;
    }

    // === email_admin_on_comment_edition ===
    /**
     * Send an email to the administrators when a comment is modified.
     */
    private static bool $emailAdminOnCommentEdition = false;

    public static function emailAdminOnCommentEdition(): bool
    {
        return self::$emailAdminOnCommentEdition;
    }

    public static function setEmailAdminOnCommentEdition(bool $value): void
    {
        self::$emailAdminOnCommentEdition = $value;
    }

    // === email_admin_on_comment_validation ===
    /**
     * Send an email to the administrators when a comment requires validation.
     */
    private static bool $emailAdminOnCommentValidation = true;

    public static function emailAdminOnCommentValidation(): bool
    {
        return self::$emailAdminOnCommentValidation;
    }

    public static function setEmailAdminOnCommentValidation(bool $value): void
    {
        self::$emailAdminOnCommentValidation = $value;
    }

    // === email_admin_on_new_user ===
    /**
     * When to email the webmaster when a new user registers: none, all, or new.
     */
    private static string $emailAdminOnNewUser = 'none';

    public static function emailAdminOnNewUser(): string
    {
        return self::$emailAdminOnNewUser;
    }

    public static function setEmailAdminOnNewUser(string $value): void
    {
        self::$emailAdminOnNewUser = $value;
    }

    // === enable_core_update ===
    /**
     * Allow Piwigo core to be updated from the administration panel.
     */
    private static bool $enableCoreUpdate = true;

    public static function enableCoreUpdate(): bool
    {
        return self::$enableCoreUpdate;
    }

    public static function setEnableCoreUpdate(bool $value): void
    {
        self::$enableCoreUpdate = $value;
    }

    // === enable_extensions_install ===
    /**
     * Allow plugins and themes to be installed from the administration panel.
     */
    private static bool $enableExtensionsInstall = true;

    public static function enableExtensionsInstall(): bool
    {
        return self::$enableExtensionsInstall;
    }

    public static function setEnableExtensionsInstall(bool $value): void
    {
        self::$enableExtensionsInstall = $value;
    }

    // === enable_formats ===
    /**
     * Enable the multi-format photo feature (original plus additional formats).
     */
    private static bool $isFormatsEnabled = false;

    public static function isFormatsEnabled(): bool
    {
        return self::$isFormatsEnabled;
    }

    public static function setIsFormatsEnabled(bool $value): void
    {
        self::$isFormatsEnabled = $value;
    }

    // === enable_plugins ===
    /**
     * Load and activate installed plugins.
     */
    private static bool $enablePlugins = true;

    public static function enablePlugins(): bool
    {
        return self::$enablePlugins;
    }

    public static function setEnablePlugins(bool $value): void
    {
        self::$enablePlugins = $value;
    }

    // === enable_synchronization ===
    /**
     * Allow directory-to-database synchronization from the admin panel.
     */
    private static bool $enableSynchronization = true;

    public static function enableSynchronization(): bool
    {
        return self::$enableSynchronization;
    }

    public static function setEnableSynchronization(bool $value): void
    {
        self::$enableSynchronization = $value;
    }

    // === ext_imagick_dir ===
    /**
     * Filesystem path to the ImageMagick binary directory (leave empty to
     * auto-detect).
     */
    private static string $extImagickDir = '';

    public static function extImagickDir(): string
    {
        return self::$extImagickDir;
    }

    public static function setExtImagickDir(string $value): void
    {
        self::$extImagickDir = $value;
    }

    // === ffmpeg_dir ===
    /**
     * Filesystem path to the FFmpeg binary directory (leave empty to auto-detect).
     */
    private static string $ffmpegDir = '';

    public static function ffmpegDir(): string
    {
        return self::$ffmpegDir;
    }

    public static function setFfmpegDir(string $value): void
    {
        self::$ffmpegDir = $value;
    }

    // === fs_quick_check_last_check ===
    /**
     * Timestamp of the last filesystem quick-check run.
     */
    private static ?string $fsQuickCheckLastCheck = null;

    public static function fsQuickCheckLastCheck(): ?string
    {
        return self::$fsQuickCheckLastCheck;
    }

    public static function setFsQuickCheckLastCheck(?string $value): void
    {
        self::$fsQuickCheckLastCheck = $value;
    }

    // === fs_quick_check_period ===
    /**
     * Interval in seconds between automatic filesystem quick-checks.
     */
    private static int $fsQuickCheckPeriod = 86400;

    public static function fsQuickCheckPeriod(): int
    {
        return self::$fsQuickCheckPeriod;
    }

    public static function setFsQuickCheckPeriod(int $value): void
    {
        self::$fsQuickCheckPeriod = $value;
    }

    // === full_tag_cloud_items_number ===
    /**
     * Maximum number of tags shown on the full tag-cloud page.
     */
    private static int $fullTagCloudItemsNumber = 200;

    public static function fullTagCloudItemsNumber(): int
    {
        return self::$fullTagCloudItemsNumber;
    }

    public static function setFullTagCloudItemsNumber(int $value): void
    {
        self::$fullTagCloudItemsNumber = $value;
    }

    // === gallery_locked ===
    /**
     * Lock the gallery for maintenance, blocking non-admin access.
     */
    private static bool $galleryLocked = false;

    public static function galleryLocked(): bool
    {
        return self::$galleryLocked;
    }

    public static function setGalleryLocked(bool $value): void
    {
        self::$galleryLocked = $value;
    }

    // === gallery_title ===
    /**
     * Title of the gallery shown in the browser tab and page header.
     */
    private static string $galleryTitle = 'Piwigo';

    public static function galleryTitle(): string
    {
        return self::$galleryTitle;
    }

    public static function setGalleryTitle(string $value): void
    {
        self::$galleryTitle = $value;
    }

    // === gallery_url ===
    /**
     * Public base URL of the gallery (overrides auto-detection when set).
     */
    private static ?string $galleryUrl = null;

    public static function galleryUrl(): ?string
    {
        return self::$galleryUrl;
    }

    public static function setGalleryUrl(?string $value): void
    {
        self::$galleryUrl = $value;
    }

    // === graphics_library ===
    /**
     * Image processing backend: auto, gd, imagick, or ext_imagick.
     */
    private static string $graphicsLibrary = 'auto';

    public static function graphicsLibrary(): string
    {
        return self::$graphicsLibrary;
    }

    public static function setGraphicsLibrary(string $value): void
    {
        self::$graphicsLibrary = $value;
    }

    // === guest_access ===
    /**
     * Allow unauthenticated (guest) visitors to browse public photos.
     */
    private static bool $guestAccess = true;

    public static function guestAccess(): bool
    {
        return self::$guestAccess;
    }

    public static function setGuestAccess(bool $value): void
    {
        self::$guestAccess = $value;
    }

    // === guest_id ===
    /**
     * User ID of the built-in guest account used for unauthenticated sessions.
     */
    private static int $guestId = 2;

    public static function guestId(): int
    {
        return self::$guestId;
    }

    public static function setGuestId(int $value): void
    {
        self::$guestId = $value;
    }

    // === history_admin ===
    /**
     * Log page visits by admin users in the history table.
     */
    private static bool $historyAdmin = false;

    public static function historyAdmin(): bool
    {
        return self::$historyAdmin;
    }

    public static function setHistoryAdmin(bool $value): void
    {
        self::$historyAdmin = $value;
    }

    // === history_autopurge_blocksize ===
    /**
     * Number of rows deleted per autopurge cycle from the history table.
     */
    private static int $historyAutopurgeBlocksize = 50000;

    public static function historyAutopurgeBlocksize(): int
    {
        return self::$historyAutopurgeBlocksize;
    }

    public static function setHistoryAutopurgeBlocksize(int $value): void
    {
        self::$historyAutopurgeBlocksize = $value;
    }

    // === history_autopurge_every ===
    /**
     * Autopurge frequency: delete old history every N page loads (approximately).
     */
    private static int $historyAutopurgeEvery = 1021;

    public static function historyAutopurgeEvery(): int
    {
        return self::$historyAutopurgeEvery;
    }

    public static function setHistoryAutopurgeEvery(int $value): void
    {
        self::$historyAutopurgeEvery = $value;
    }

    // === history_autopurge_keep_lines ===
    /**
     * Maximum number of history rows to retain after an autopurge.
     */
    private static int $historyAutopurgeKeepLines = 1000000;

    public static function historyAutopurgeKeepLines(): int
    {
        return self::$historyAutopurgeKeepLines;
    }

    public static function setHistoryAutopurgeKeepLines(int $value): void
    {
        self::$historyAutopurgeKeepLines = $value;
    }

    // === history_guest ===
    /**
     * Log page visits by guest (unauthenticated) users in the history table.
     */
    private static bool $historyGuest = false;

    public static function historyGuest(): bool
    {
        return self::$historyGuest;
    }

    public static function setHistoryGuest(bool $value): void
    {
        self::$historyGuest = $value;
    }

    // === index_caddie_icon ===
    /**
     * Show the add-to-caddie icon on album index pages.
     */
    private static bool $indexCaddieIcon = true;

    public static function indexCaddieIcon(): bool
    {
        return self::$indexCaddieIcon;
    }

    public static function setIndexCaddieIcon(bool $value): void
    {
        self::$indexCaddieIcon = $value;
    }

    // === index_created_date_icon ===
    /**
     * Show the creation-date icon on album index pages.
     */
    private static bool $indexCreatedDateIcon = true;

    public static function indexCreatedDateIcon(): bool
    {
        return self::$indexCreatedDateIcon;
    }

    public static function setIndexCreatedDateIcon(bool $value): void
    {
        self::$indexCreatedDateIcon = $value;
    }

    // === index_edit_icon ===
    /**
     * Show the quick-edit icon on album index pages (admins only).
     */
    private static bool $indexEditIcon = true;

    public static function indexEditIcon(): bool
    {
        return self::$indexEditIcon;
    }

    public static function setIndexEditIcon(bool $value): void
    {
        self::$indexEditIcon = $value;
    }

    // === index_flat_icon ===
    /**
     * Show the flat-view icon on album index pages.
     */
    private static bool $indexFlatIcon = true;

    public static function indexFlatIcon(): bool
    {
        return self::$indexFlatIcon;
    }

    public static function setIndexFlatIcon(bool $value): void
    {
        self::$indexFlatIcon = $value;
    }

    // === index_new_icon ===
    /**
     * Show the new badge icon on recently added photos in album index pages.
     */
    private static bool $indexNewIcon = true;

    public static function indexNewIcon(): bool
    {
        return self::$indexNewIcon;
    }

    public static function setIndexNewIcon(bool $value): void
    {
        self::$indexNewIcon = $value;
    }

    // === index_posted_date_icon ===
    /**
     * Show the posted-date icon on album index pages.
     */
    private static bool $indexPostedDateIcon = true;

    public static function indexPostedDateIcon(): bool
    {
        return self::$indexPostedDateIcon;
    }

    public static function setIndexPostedDateIcon(bool $value): void
    {
        self::$indexPostedDateIcon = $value;
    }

    // === index_search_in_set_action ===
    /**
     * Behaviour when searching within the current set: results or filter.
     */
    private static string $indexSearchInSetAction = 'results';

    public static function indexSearchInSetAction(): string
    {
        return self::$indexSearchInSetAction;
    }

    public static function setIndexSearchInSetAction(string $value): void
    {
        self::$indexSearchInSetAction = $value;
    }

    // === index_search_in_set_button ===
    /**
     * Show the search-within-set button on album index pages.
     */
    private static bool $indexSearchInSetButton = false;

    public static function indexSearchInSetButton(): bool
    {
        return self::$indexSearchInSetButton;
    }

    public static function setIndexSearchInSetButton(bool $value): void
    {
        self::$indexSearchInSetButton = $value;
    }

    // === index_sizes_icon ===
    /**
     * Show the available-sizes icon on album index pages.
     */
    private static bool $indexSizesIcon = true;

    public static function indexSizesIcon(): bool
    {
        return self::$indexSizesIcon;
    }

    public static function setIndexSizesIcon(bool $value): void
    {
        self::$indexSizesIcon = $value;
    }

    // === index_slideshow_icon ===
    /**
     * Show the slideshow icon on album index pages.
     */
    private static bool $indexSlideShowIcon = true;

    public static function indexSlideShowIcon(): bool
    {
        return self::$indexSlideShowIcon;
    }

    public static function setIndexSlideShowIcon(bool $value): void
    {
        self::$indexSlideShowIcon = $value;
    }

    // === index_sort_order_input ===
    /**
     * Display the image order selection list on album index pages.
     */
    private static bool $indexSortOrderInput = true;

    public static function indexSortOrderInput(): bool
    {
        return self::$indexSortOrderInput;
    }

    public static function setIndexSortOrderInput(bool $value): void
    {
        self::$indexSortOrderInput = $value;
    }

    // === inheritance_by_default ===
    /**
     * Apply parent album permissions to newly created sub-albums by default.
     */
    private static bool $inheritanceByDefault = false;

    public static function inheritanceByDefault(): bool
    {
        return self::$inheritanceByDefault;
    }

    public static function setInheritanceByDefault(bool $value): void
    {
        self::$inheritanceByDefault = $value;
    }

    // === insensitive_case_logon ===
    /**
     * Allow login with any letter-case variation of the username.
     */
    private static bool $insensitiveCaseLogon = false;

    public static function insensitiveCaseLogon(): bool
    {
        return self::$insensitiveCaseLogon;
    }

    public static function setInsensitiveCaseLogon(bool $value): void
    {
        self::$insensitiveCaseLogon = $value;
    }

    // === last_major_update ===
    /**
     * Timestamp of the last major Piwigo upgrade, used for change detection.
     */
    private static ?string $lastMajorUpdate = null;

    public static function lastMajorUpdate(): ?string
    {
        return self::$lastMajorUpdate;
    }

    public static function setLastMajorUpdate(?string $value): void
    {
        self::$lastMajorUpdate = $value;
    }

    // === level_separator ===
    /**
     * String used to separate album hierarchy levels in breadcrumb trails.
     */
    private static string $levelSeparator = ' / ';

    public static function levelSeparator(): string
    {
        return self::$levelSeparator;
    }

    public static function setLevelSeparator(string $value): void
    {
        self::$levelSeparator = $value;
    }

    // === light_album_manager_threshold ===
    /**
     * Album count above which the lightweight album manager UI is used.
     */
    private static int $lightAlbumManagerThreshold = 10000;

    public static function lightAlbumManagerThreshold(): int
    {
        return self::$lightAlbumManagerThreshold;
    }

    public static function setLightAlbumManagerThreshold(int $value): void
    {
        self::$lightAlbumManagerThreshold = $value;
    }

    // === light_slideshow ===
    /**
     * Use the lightweight built-in slideshow instead of a plugin-based one.
     */
    private static bool $lightSlideshow = true;

    public static function lightSlideshow(): bool
    {
        return self::$lightSlideshow;
    }

    public static function setLightSlideshow(bool $value): void
    {
        self::$lightSlideshow = $value;
    }

    // === linked_album_search_limit ===
    /**
     * Maximum albums returned when searching for albums to link a photo to.
     */
    private static int $linkedAlbumSearchLimit = 100;

    public static function linkedAlbumSearchLimit(): int
    {
        return self::$linkedAlbumSearchLimit;
    }

    public static function setLinkedAlbumSearchLimit(int $value): void
    {
        self::$linkedAlbumSearchLimit = $value;
    }

    // === log ===
    /**
     * Enable the application log.
     */
    private static bool $logConf = false;

    public static function logConf(): bool
    {
        return self::$logConf;
    }

    public static function setLogConf(bool $value): void
    {
        self::$logConf = $value;
    }

    // === log_archive_days ===
    /**
     * Number of days to keep archived log files before deletion.
     */
    private static int $logArchiveDays = 30;

    public static function logArchiveDays(): int
    {
        return self::$logArchiveDays;
    }

    public static function setLogArchiveDays(int $value): void
    {
        self::$logArchiveDays = $value;
    }

    // === log_dir ===
    /**
     * Directory (relative to the data location) where log files are written.
     */
    private static string $logDir = '/logs';

    public static function logDir(): string
    {
        return self::$logDir;
    }

    public static function setLogDir(string $value): void
    {
        self::$logDir = $value;
    }

    // === log_level ===
    /**
     * Minimum log severity to record: DEBUG, INFO, WARNING, or ERROR.
     */
    private static string $logLevel = 'DEBUG';

    public static function logLevel(): string
    {
        return self::$logLevel;
    }

    public static function setLogLevel(string $value): void
    {
        self::$logLevel = $value;
    }

    // === login_lockout_duration_minutes ===
    /**
     * Minutes a username/IP stays locked out after too many failed logins.
     */
    private static int $loginLockoutDurationMinutes = 15;

    public static function loginLockoutDurationMinutes(): int
    {
        return self::$loginLockoutDurationMinutes;
    }

    public static function setLoginLockoutDurationMinutes(int $value): void
    {
        self::$loginLockoutDurationMinutes = $value;
    }

    // === login_lockout_max_attempts ===
    /**
     * Failed logins allowed (per username, and separately per IP) within
     * the lockout window before AuthService::pwgLogin() starts rejecting
     * outright.
     */
    private static int $loginLockoutMaxAttempts = 5;

    public static function loginLockoutMaxAttempts(): int
    {
        return self::$loginLockoutMaxAttempts;
    }

    public static function setLoginLockoutMaxAttempts(int $value): void
    {
        self::$loginLockoutMaxAttempts = $value;
    }

    // === login_lockout_window_minutes ===
    /**
     * Rolling window, in minutes, over which failed logins are counted
     * towards the lockout threshold.
     */
    private static int $loginLockoutWindowMinutes = 15;

    public static function loginLockoutWindowMinutes(): int
    {
        return self::$loginLockoutWindowMinutes;
    }

    public static function setLoginLockoutWindowMinutes(int $value): void
    {
        self::$loginLockoutWindowMinutes = $value;
    }

    // === lounge_activate_threshold ===
    /**
     * Number of photos in the lounge that triggers automatic album creation.
     */
    private static int $loungeActivateThreshold = 1;

    public static function loungeActivateThreshold(): int
    {
        return self::$loungeActivateThreshold;
    }

    public static function setLoungeActivateThreshold(int $value): void
    {
        self::$loungeActivateThreshold = $value;
    }

    // === lounge_active ===
    /**
     * Enable the lounge feature (a staging area for uploaded photos).
     */
    private static bool $loungeActive = false;

    public static function loungeActive(): bool
    {
        return self::$loungeActive;
    }

    public static function setLoungeActive(bool $value): void
    {
        self::$loungeActive = $value;
    }

    // === lounge_max_duration ===
    /**
     * Maximum seconds a photo can stay in the lounge before auto-processing.
     */
    private static int $loungeMaxDuration = 300;

    public static function loungeMaxDuration(): int
    {
        return self::$loungeMaxDuration;
    }

    public static function setLoungeMaxDuration(int $value): void
    {
        self::$loungeMaxDuration = $value;
    }

    // === mail_allow_html ===
    /**
     * Send emails in HTML format in addition to plain text.
     */
    private static bool $mailAllowHtml = true;

    public static function mailAllowHtml(): bool
    {
        return self::$mailAllowHtml;
    }

    public static function setMailAllowHtml(bool $value): void
    {
        self::$mailAllowHtml = $value;
    }

    // === mail_sender_email ===
    /**
     * From email address used for all outgoing Piwigo emails.
     */
    private static string $mailSenderEmail = '';

    public static function mailSenderEmail(): string
    {
        return self::$mailSenderEmail;
    }

    public static function setMailSenderEmail(string $value): void
    {
        self::$mailSenderEmail = $value;
    }

    // === mail_sender_name ===
    /**
     * Display name shown as the email sender in outgoing Piwigo emails.
     */
    private static string $mailSenderName = '';

    public static function mailSenderName(): string
    {
        return self::$mailSenderName;
    }

    public static function setMailSenderName(string $value): void
    {
        self::$mailSenderName = $value;
    }

    // === mail_theme ===
    /**
     * Visual theme for HTML notification emails: light or dark.
     */
    private static string $mailTheme = 'light';

    public static function mailTheme(): string
    {
        return self::$mailTheme;
    }

    public static function setMailTheme(string $value): void
    {
        self::$mailTheme = $value;
    }

    // === max_requests ===
    /**
     * Maximum concurrent HTTP requests Piwigo will make to external services.
     */
    private static int $maxRequests = 3;

    public static function maxRequests(): int
    {
        return self::$maxRequests;
    }

    public static function setMaxRequests(int $value): void
    {
        self::$maxRequests = $value;
    }

    // === menubar_filter_icon ===
    /**
     * Show the filter icon in the sidebar menu.
     */
    private static bool $menubarFilterIcon = true;

    public static function menubarFilterIcon(): bool
    {
        return self::$menubarFilterIcon;
    }

    public static function setMenubarFilterIcon(bool $value): void
    {
        self::$menubarFilterIcon = $value;
    }

    // === menubar_tag_cloud_content ===
    /**
     * Which tags to show in the sidebar tag cloud: all_or_current or current.
     */
    private static string $menubarTagCloudContent = 'all_or_current';

    public static function menubarTagCloudContent(): string
    {
        return self::$menubarTagCloudContent;
    }

    public static function setMenubarTagCloudContent(string $value): void
    {
        self::$menubarTagCloudContent = $value;
    }

    // === menubar_tag_cloud_items_number ===
    /**
     * Maximum number of tags shown in the sidebar tag cloud.
     */
    private static int $menubarTagCloudItemsNumber = 20;

    public static function menubarTagCloudItemsNumber(): int
    {
        return self::$menubarTagCloudItemsNumber;
    }

    public static function setMenubarTagCloudItemsNumber(int $value): void
    {
        self::$menubarTagCloudItemsNumber = $value;
    }

    // === meta_ref ===
    /**
     * Emit a referrer meta tag allowing search engines to attribute traffic.
     */
    private static bool $metaRef = true;

    public static function metaRef(): bool
    {
        return self::$metaRef;
    }

    public static function setMetaRef(bool $value): void
    {
        self::$metaRef = $value;
    }

    // === mobile_theme ===
    /**
     * Theme name applied automatically when a mobile browser is detected.
     */
    private static string $mobilTheme = '';

    public static function mobilTheme(): string
    {
        return self::$mobilTheme;
    }

    public static function setMobilTheme(string $value): void
    {
        self::$mobilTheme = $value;
    }

    // === nb_categories_page ===
    /**
     * Maximum albums shown per page in admin album listings.
     */
    private static int $nbCategoriesPage = 9999;

    public static function nbCategoriesPage(): int
    {
        return self::$nbCategoriesPage;
    }

    public static function setNbCategoriesPage(int $value): void
    {
        self::$nbCategoriesPage = $value;
    }

    // === nb_comment_page ===
    /**
     * Number of comments per page on the public photo detail page.
     */
    private static int $nbCommentPage = 10;

    public static function nbCommentPage(): int
    {
        return self::$nbCommentPage;
    }

    public static function setNbCommentPage(int $value): void
    {
        self::$nbCommentPage = $value;
    }

    // === nb_logs_page ===
    /**
     * Number of history entries shown per page in the admin history view.
     */
    private static int $nbLogsPage = 300;

    public static function nbLogsPage(): int
    {
        return self::$nbLogsPage;
    }

    public static function setNbLogsPage(int $value): void
    {
        self::$nbLogsPage = $value;
    }

    // === nbm_complementary_mail_content ===
    /**
     * Extra HTML appended to notification-by-mail digest emails.
     */
    private static string $nbmComplementaryMailContent = '';

    public static function nbmComplementaryMailContent(): string
    {
        return self::$nbmComplementaryMailContent;
    }

    public static function setNbmComplementaryMailContent(string $value): void
    {
        self::$nbmComplementaryMailContent = $value;
    }

    // === nbm_default_value_user_enabled ===
    /**
     * Subscribe new users to notification-by-mail digests by default.
     */
    private static bool $nbmDefaultValueUserEnabled = false;

    public static function nbmDefaultValueUserEnabled(): bool
    {
        return self::$nbmDefaultValueUserEnabled;
    }

    public static function setNbmDefaultValueUserEnabled(bool $value): void
    {
        self::$nbmDefaultValueUserEnabled = $value;
    }

    // === nbm_list_all_enabled_users_to_send ===
    /**
     * Show all subscribed users in the NBM send UI, not just those with pending
     * notifications.
     */
    private static bool $nbmListAllEnabledUsersToSend = false;

    public static function nbmListAllEnabledUsersToSend(): bool
    {
        return self::$nbmListAllEnabledUsersToSend;
    }

    public static function setNbmListAllEnabledUsersToSend(bool $value): void
    {
        self::$nbmListAllEnabledUsersToSend = $value;
    }

    // === nbm_send_detailed_content ===
    /**
     * Include photo thumbnails and descriptions in NBM digest emails.
     */
    private static bool $nbmSendDetailedContent = true;

    public static function nbmSendDetailedContent(): bool
    {
        return self::$nbmSendDetailedContent;
    }

    public static function setNbmSendDetailedContent(bool $value): void
    {
        self::$nbmSendDetailedContent = $value;
    }

    // === nbm_send_html_mail ===
    /**
     * Send NBM digest emails in HTML format.
     */
    private static bool $nbmSendHtmlMail = true;

    public static function nbmSendHtmlMail(): bool
    {
        return self::$nbmSendHtmlMail;
    }

    public static function setNbmSendHtmlMail(bool $value): void
    {
        self::$nbmSendHtmlMail = $value;
    }

    // === nbm_send_mail_as ===
    /**
     * Override the From display name used specifically for NBM emails.
     */
    private static string $nbmSendMailAs = '';

    public static function nbmSendMailAs(): string
    {
        return self::$nbmSendMailAs;
    }

    public static function setNbmSendMailAs(string $value): void
    {
        self::$nbmSendMailAs = $value;
    }

    // === nbm_send_recent_post_dates ===
    /**
     * Include recent-post date ranges in NBM digest emails.
     */
    private static bool $nbmSendRecentPostDates = true;

    public static function nbmSendRecentPostDates(): bool
    {
        return self::$nbmSendRecentPostDates;
    }

    public static function setNbmSendRecentPostDates(bool $value): void
    {
        self::$nbmSendRecentPostDates = $value;
    }

    // === nbm_treatment_timeout_default ===
    /**
     * Default timeout in seconds for a single NBM send-batch execution.
     */
    private static int $nbmTreatmentTimeoutDefault = 20;

    public static function nbmTreatmentTimeoutDefault(): int
    {
        return self::$nbmTreatmentTimeoutDefault;
    }

    public static function setNbmTreatmentTimeoutDefault(int $value): void
    {
        self::$nbmTreatmentTimeoutDefault = $value;
    }

    // === never_delete_originals ===
    /**
     * Prevent deletion of original image files when a photo is removed.
     */
    private static bool $neverDeleteOriginals = false;

    public static function neverDeleteOriginals(): bool
    {
        return self::$neverDeleteOriginals;
    }

    public static function setNeverDeleteOriginals(bool $value): void
    {
        self::$neverDeleteOriginals = $value;
    }

    // === newcat_default_commentable ===
    /**
     * Make newly created albums commentable by default.
     */
    private static bool $newcatDefaultCommentable = true;

    public static function newcatDefaultCommentable(): bool
    {
        return self::$newcatDefaultCommentable;
    }

    public static function setNewcatDefaultCommentable(bool $value): void
    {
        self::$newcatDefaultCommentable = $value;
    }

    // === newcat_default_position ===
    /**
     * Insert position for new sub-albums: first or last.
     */
    private static string $newcatDefaultPosition = 'first';

    public static function newcatDefaultPosition(): string
    {
        return self::$newcatDefaultPosition;
    }

    public static function setNewcatDefaultPosition(string $value): void
    {
        self::$newcatDefaultPosition = $value;
    }

    // === newcat_default_status ===
    /**
     * Default visibility for new albums: public or private.
     */
    private static string $newcatDefaultStatus = 'public';

    public static function newcatDefaultStatus(): string
    {
        return self::$newcatDefaultStatus;
    }

    public static function setNewcatDefaultStatus(string $value): void
    {
        self::$newcatDefaultStatus = $value;
    }

    // === newcat_default_visible ===
    /**
     * Make newly created albums visible by default.
     */
    private static bool $newcatDefaultVisible = true;

    public static function newcatDefaultVisible(): bool
    {
        return self::$newcatDefaultVisible;
    }

    public static function setNewcatDefaultVisible(bool $value): void
    {
        self::$newcatDefaultVisible = $value;
    }

    // === no_photo_yet ===
    /**
     * Presence-only marker: once set (to 'false'), NoPhotoYetRenderer's first-run
     * banner is permanently suppressed. Genuine absence on a fresh install/reset
     * -- callers check noPhotoYet() === null to detect first-run state, matching
     * the gallery_url/last_major_update convention.
     */
    private static ?string $noPhotoYet = null;

    public static function noPhotoYet(): ?string
    {
        return self::$noPhotoYet;
    }

    public static function setNoPhotoYet(?string $value): void
    {
        self::$noPhotoYet = $value;
    }

    // === no_photo_yet_url ===
    /**
     * Admin URL linked from the no-photos-yet placeholder shown to admins.
     */
    private static string $noPhotoYetUrl = 'admin.php?page=photos_add';

    public static function noPhotoYetUrl(): string
    {
        return self::$noPhotoYetUrl;
    }

    public static function setNoPhotoYetUrl(string $value): void
    {
        self::$noPhotoYetUrl = $value;
    }

    // === obligatory_user_mail_address ===
    /**
     * Require an email address for all user registrations.
     */
    private static bool $obligatoryUserMailAddress = false;

    public static function obligatoryUserMailAddress(): bool
    {
        return self::$obligatoryUserMailAddress;
    }

    public static function setObligatoryUserMailAddress(bool $value): void
    {
        self::$obligatoryUserMailAddress = $value;
    }

    // === original_resize ===
    /**
     * Resize uploaded originals that exceed the configured maximum dimensions.
     */
    private static bool $originalResize = false;

    public static function originalResize(): bool
    {
        return self::$originalResize;
    }

    public static function setOriginalResize(bool $value): void
    {
        self::$originalResize = $value;
    }

    // === original_resize_maxheight ===
    /**
     * Maximum pixel height for uploaded originals when resize is enabled.
     */
    private static int $originalResizeMaxheight = 2000;

    public static function originalResizeMaxheight(): int
    {
        return self::$originalResizeMaxheight;
    }

    public static function setOriginalResizeMaxheight(int $value): void
    {
        self::$originalResizeMaxheight = $value;
    }

    // === original_resize_maxwidth ===
    /**
     * Maximum pixel width for uploaded originals when resize is enabled.
     */
    private static int $originalResizeMaxwidth = 2000;

    public static function originalResizeMaxwidth(): int
    {
        return self::$originalResizeMaxwidth;
    }

    public static function setOriginalResizeMaxwidth(int $value): void
    {
        self::$originalResizeMaxwidth = $value;
    }

    // === original_resize_quality ===
    /**
     * JPEG quality (1-100) used when resizing uploaded originals.
     */
    private static int $originalResizeQuality = 95;

    public static function originalResizeQuality(): int
    {
        return self::$originalResizeQuality;
    }

    public static function setOriginalResizeQuality(int $value): void
    {
        self::$originalResizeQuality = $value;
    }

    // === original_url_protection ===
    /**
     * Original-file URL protection mode: empty (none), images, or all.
     */
    private static string $originalUrlProtection = '';

    public static function originalUrlProtection(): string
    {
        return self::$originalUrlProtection;
    }

    public static function setOriginalUrlProtection(string $value): void
    {
        self::$originalUrlProtection = $value;
    }

    // === page_banner ===
    /**
     * HTML banner content displayed at the top of public gallery pages.
     */
    private static string $pageBanner = '';

    public static function pageBanner(): string
    {
        return self::$pageBanner;
    }

    public static function setPageBanner(string $value): void
    {
        self::$pageBanner = $value;
    }

    // === paginate_pages_around ===
    /**
     * Number of page-number links shown on each side of the current page in
     * pagination.
     */
    private static int $paginatePagesAround = 2;

    public static function paginatePagesAround(): int
    {
        return self::$paginatePagesAround;
    }

    public static function setPaginatePagesAround(int $value): void
    {
        self::$paginatePagesAround = $value;
    }

    // === password_activation_duration ===
    /**
     * Seconds a password-activation link emailed to new users remains valid.
     */
    private static int $passwordActivationDuration = 259200;

    public static function passwordActivationDuration(): int
    {
        return self::$passwordActivationDuration;
    }

    public static function setPasswordActivationDuration(int $value): void
    {
        self::$passwordActivationDuration = $value;
    }

    // === password_reset_code_duration ===
    /**
     * Seconds a password-reset verification code is valid.
     */
    private static int $passwordResetCodeDuration = 300;

    public static function passwordResetCodeDuration(): int
    {
        return self::$passwordResetCodeDuration;
    }

    public static function setPasswordResetCodeDuration(int $value): void
    {
        self::$passwordResetCodeDuration = $value;
    }

    // === password_reset_duration ===
    /**
     * Seconds a password-reset link emailed to a user remains valid.
     */
    private static int $passwordResetDuration = 3600;

    public static function passwordResetDuration(): int
    {
        return self::$passwordResetDuration;
    }

    public static function setPasswordResetDuration(int $value): void
    {
        self::$passwordResetDuration = $value;
    }

    // === pdf_jpg_quality ===
    /**
     * JPEG quality used when Imagick renders a PDF's representative image.
     */
    private static int $pdfJpgQuality = 90;

    public static function pdfJpgQuality(): int
    {
        return self::$pdfJpgQuality;
    }

    public static function setPdfJpgQuality(int $value): void
    {
        self::$pdfJpgQuality = $value;
    }

    // === pdf_representative_ext ===
    /**
     * File extension used for a PDF's rendered representative image.
     */
    private static string $pdfRepresentativeExt = 'jpg';

    public static function pdfRepresentativeExt(): string
    {
        return self::$pdfRepresentativeExt;
    }

    public static function setPdfRepresentativeExt(string $value): void
    {
        self::$pdfRepresentativeExt = $value;
    }

    // === pdf_viewer_filesize_threshold ===
    /**
     * Maximum PDF file size in MB to display inline; larger files show a download
     * link.
     */
    private static int $pdfViewerFilesizeThreshold = 5;

    public static function pdfViewerFilesizeThreshold(): int
    {
        return self::$pdfViewerFilesizeThreshold;
    }

    public static function setPdfViewerFilesizeThreshold(int $value): void
    {
        self::$pdfViewerFilesizeThreshold = $value;
    }

    // === pem_languages_category ===
    /**
     * PEM (Piwigo Extensions Manager) category ID for language packs.
     */
    private static int $pemLanguagesCategory = 8;

    public static function pemLanguagesCategory(): int
    {
        return self::$pemLanguagesCategory;
    }

    public static function setPemLanguagesCategory(int $value): void
    {
        self::$pemLanguagesCategory = $value;
    }

    // === pem_plugins_category ===
    /**
     * PEM category ID for plugins.
     */
    private static int $pemPluginsCategory = 12;

    public static function pemPluginsCategory(): int
    {
        return self::$pemPluginsCategory;
    }

    public static function setPemPluginsCategory(int $value): void
    {
        self::$pemPluginsCategory = $value;
    }

    // === pem_themes_category ===
    /**
     * PEM category ID for themes.
     */
    private static int $pemThemesCategory = 10;

    public static function pemThemesCategory(): int
    {
        return self::$pemThemesCategory;
    }

    public static function setPemThemesCategory(int $value): void
    {
        self::$pemThemesCategory = $value;
    }

    // === php_extension_in_urls ===
    /**
     * Include the .php extension in generated picture/category URLs. Works only
     * with Options +MultiViews or URL rewriting active.
     */
    private static bool $phpExtensionInUrls = true;

    public static function phpExtensionInUrls(): bool
    {
        return self::$phpExtensionInUrls;
    }

    public static function setPhpExtensionInUrls(bool $value): void
    {
        self::$phpExtensionInUrls = $value;
    }

    // === picture_caddie_icon ===
    /**
     * Show the add-to-caddie icon on the photo detail page.
     */
    private static bool $pictureCaddieIcon = true;

    public static function pictureCaddieIcon(): bool
    {
        return self::$pictureCaddieIcon;
    }

    public static function setPictureCaddieIcon(bool $value): void
    {
        self::$pictureCaddieIcon = $value;
    }

    // === picture_download_icon ===
    /**
     * Show the download icon on the photo detail page.
     */
    private static bool $pictureDownloadIcon = true;

    public static function pictureDownloadIcon(): bool
    {
        return self::$pictureDownloadIcon;
    }

    public static function setPictureDownloadIcon(bool $value): void
    {
        self::$pictureDownloadIcon = $value;
    }

    // === picture_edit_icon ===
    /**
     * Show the quick-edit icon on the photo detail page (admins only).
     */
    private static bool $pictureEditIcon = true;

    public static function pictureEditIcon(): bool
    {
        return self::$pictureEditIcon;
    }

    public static function setPictureEditIcon(bool $value): void
    {
        self::$pictureEditIcon = $value;
    }

    // === picture_favorite_icon ===
    /**
     * Show the add-to-favorites icon on the photo detail page.
     */
    private static bool $pictureFavoriteIcon = true;

    public static function pictureFavoriteIcon(): bool
    {
        return self::$pictureFavoriteIcon;
    }

    public static function setPictureFavoriteIcon(bool $value): void
    {
        self::$pictureFavoriteIcon = $value;
    }

    // === picture_menu ===
    /**
     * Show the navigation menu on the photo detail page.
     */
    private static bool $pictureMenu = true;

    public static function pictureMenu(): bool
    {
        return self::$pictureMenu;
    }

    public static function setPictureMenu(bool $value): void
    {
        self::$pictureMenu = $value;
    }

    // === picture_metadata_icon ===
    /**
     * Show the metadata icon on the photo detail page.
     */
    private static bool $pictureMetadataIcon = true;

    public static function pictureMetadataIcon(): bool
    {
        return self::$pictureMetadataIcon;
    }

    public static function setPictureMetadataIcon(bool $value): void
    {
        self::$pictureMetadataIcon = $value;
    }

    // === picture_navigation_icons ===
    /**
     * Show previous/next navigation arrows on the photo detail page.
     */
    private static bool $pictureNavigationIcons = true;

    public static function pictureNavigationIcons(): bool
    {
        return self::$pictureNavigationIcons;
    }

    public static function setPictureNavigationIcons(bool $value): void
    {
        self::$pictureNavigationIcons = $value;
    }

    // === picture_navigation_thumb ===
    /**
     * Show previous/next thumbnail previews on the photo detail page.
     */
    private static bool $pictureNavigationThumb = true;

    public static function pictureNavigationThumb(): bool
    {
        return self::$pictureNavigationThumb;
    }

    public static function setPictureNavigationThumb(bool $value): void
    {
        self::$pictureNavigationThumb = $value;
    }

    // === picture_representative_icon ===
    /**
     * Show the set-as-album-representative icon on the photo detail page.
     */
    private static bool $pictureRepresentativeIcon = true;

    public static function pictureRepresentativeIcon(): bool
    {
        return self::$pictureRepresentativeIcon;
    }

    public static function setPictureRepresentativeIcon(bool $value): void
    {
        self::$pictureRepresentativeIcon = $value;
    }

    // === picture_sizes_icon ===
    /**
     * Show the available-sizes icon on the photo detail page.
     */
    private static bool $pictureSizesIcon = true;

    public static function pictureSizesIcon(): bool
    {
        return self::$pictureSizesIcon;
    }

    public static function setPictureSizesIcon(bool $value): void
    {
        self::$pictureSizesIcon = $value;
    }

    // === picture_slideshow_icon ===
    /**
     * Show the slideshow icon on the photo detail page.
     */
    private static bool $pictureSlideShowIcon = true;

    public static function pictureSlideShowIcon(): bool
    {
        return self::$pictureSlideShowIcon;
    }

    public static function setPictureSlideShowIcon(bool $value): void
    {
        self::$pictureSlideShowIcon = $value;
    }

    // === picture_url_style ===
    /**
     * URL format for photo links: id or id-file.
     */
    private static string $pictureUrlStyle = 'id';

    public static function pictureUrlStyle(): string
    {
        return self::$pictureUrlStyle;
    }

    public static function setPictureUrlStyle(string $value): void
    {
        self::$pictureUrlStyle = $value;
    }

    // === piwigo_installed_version ===
    /**
     * Full Piwigo version string recorded at the time of the last upgrade.
     */
    private static ?string $piwigoInstalledVersion = null;

    public static function piwigoInstalledVersion(): ?string
    {
        return self::$piwigoInstalledVersion;
    }

    public static function setPiwigoInstalledVersion(?string $value): void
    {
        self::$piwigoInstalledVersion = $value;
    }

    // === proxy_auth ===
    /**
     * Credentials (user:password) for HTTP proxy authentication.
     */
    private static string $proxyAuth = '';

    public static function proxyAuth(): string
    {
        return self::$proxyAuth;
    }

    public static function setProxyAuth(string $value): void
    {
        self::$proxyAuth = $value;
    }

    // === proxy_server ===
    /**
     * HTTP proxy server URL used for outgoing connections from Piwigo.
     */
    private static string $proxyServer = '';

    public static function proxyServer(): string
    {
        return self::$proxyServer;
    }

    public static function setProxyServer(string $value): void
    {
        self::$proxyServer = $value;
    }

    // === question_mark_in_urls ===
    /**
     * Include a ? in generated URLs. Can only be set false when the server
     * translates PATH_INFO (AcceptPathInfo).
     */
    private static bool $questionMarkInUrls = true;

    public static function questionMarkInUrls(): bool
    {
        return self::$questionMarkInUrls;
    }

    public static function setQuestionMarkInUrls(bool $value): void
    {
        self::$questionMarkInUrls = $value;
    }

    // === quick_search_include_sub_albums ===
    /**
     * Include photos from sub-albums in quick-search results.
     */
    private static bool $quickSearchIncludeSubAlbums = false;

    public static function quickSearchIncludeSubAlbums(): bool
    {
        return self::$quickSearchIncludeSubAlbums;
    }

    public static function setQuickSearchIncludeSubAlbums(bool $value): void
    {
        self::$quickSearchIncludeSubAlbums = $value;
    }

    // === rate ===
    /**
     * Enable the photo rating feature.
     */
    private static bool $rateEnabled = true;

    public static function rateEnabled(): bool
    {
        return self::$rateEnabled;
    }

    public static function setRateEnabled(bool $value): void
    {
        self::$rateEnabled = $value;
    }

    // === rate_anonymous ===
    /**
     * Allow guest (unauthenticated) visitors to rate photos.
     */
    private static bool $rateAnonymous = true;

    public static function rateAnonymous(): bool
    {
        return self::$rateAnonymous;
    }

    public static function setRateAnonymous(bool $value): void
    {
        self::$rateAnonymous = $value;
    }

    // === related_albums_display_limit ===
    /**
     * Maximum number of related albums shown on the photo detail page.
     */
    private static int $relatedAlbumsDisplayLimit = 20;

    public static function relatedAlbumsDisplayLimit(): int
    {
        return self::$relatedAlbumsDisplayLimit;
    }

    public static function setRelatedAlbumsDisplayLimit(int $value): void
    {
        self::$relatedAlbumsDisplayLimit = $value;
    }

    // === related_albums_maximum_items_to_compute ===
    /**
     * Maximum photos considered when computing related albums.
     */
    private static int $relatedAlbumsMaximumItemsToCompute = 1000;

    public static function relatedAlbumsMaximumItemsToCompute(): int
    {
        return self::$relatedAlbumsMaximumItemsToCompute;
    }

    public static function setRelatedAlbumsMaximumItemsToCompute(int $value): void
    {
        self::$relatedAlbumsMaximumItemsToCompute = $value;
    }

    // === remember_me_length ===
    /**
     * Lifetime in seconds of the remember-me persistent login cookie.
     */
    private static int $rememberMeLength = 5184000;

    public static function rememberMeLength(): int
    {
        return self::$rememberMeLength;
    }

    public static function setRememberMeLength(int $value): void
    {
        self::$rememberMeLength = $value;
    }

    // === remember_me_name ===
    /**
     * Cookie name used for the remember-me persistent login token.
     */
    private static string $rememberMeName = 'pwg_remember';

    public static function rememberMeName(): string
    {
        return self::$rememberMeName;
    }

    public static function setRememberMeName(string $value): void
    {
        self::$rememberMeName = $value;
    }

    // === representative_cache_on_level ===
    /**
     * Cache the album representative photo when permission level changes.
     */
    private static bool $representativeCacheOnLevel = true;

    public static function representativeCacheOnLevel(): bool
    {
        return self::$representativeCacheOnLevel;
    }

    public static function setRepresentativeCacheOnLevel(bool $value): void
    {
        self::$representativeCacheOnLevel = $value;
    }

    // === representative_cache_on_subcats ===
    /**
     * Rebuild album representative thumbnails when sub-album content changes.
     */
    private static bool $representativeCacheOnSubcats = true;

    public static function representativeCacheOnSubcats(): bool
    {
        return self::$representativeCacheOnSubcats;
    }

    public static function setRepresentativeCacheOnSubcats(bool $value): void
    {
        self::$representativeCacheOnSubcats = $value;
    }

    // === rss_feed_author ===
    /**
     * Author name shown in the gallery RSS feed.
     */
    private static string $rssReedAuthor = 'Piwigo notifier';

    public static function rssReedAuthor(): string
    {
        return self::$rssReedAuthor;
    }

    public static function setRssReedAuthor(string $value): void
    {
        self::$rssReedAuthor = $value;
    }

    // === secret_key ===
    /**
     * Random string used to sign CSRF tokens and internal hashes.
     */
    #[Required]
    private static string $secretKey = '';

    public static function secretKey(): string
    {
        return self::$secretKey;
    }

    public static function setSecretKey(string $value): void
    {
        self::$secretKey = $value;
    }

    // === send_bcc_mail_webmaster ===
    /**
     * BCC the webmaster address on every outgoing notification email.
     */
    private static bool $sendBccMailWebmaster = false;

    public static function sendBccMailWebmaster(): bool
    {
        return self::$sendBccMailWebmaster;
    }

    public static function setSendBccMailWebmaster(bool $value): void
    {
        self::$sendBccMailWebmaster = $value;
    }

    // === send_piwigo_infos ===
    /**
     * Allow Piwigo to send anonymous usage statistics to the Piwigo project.
     */
    private static bool $sendPiwigoInfos = true;

    public static function sendPiwigoInfos(): bool
    {
        return self::$sendPiwigoInfos;
    }

    public static function setSendPiwigoInfos(bool $value): void
    {
        self::$sendPiwigoInfos = $value;
    }

    // === send_piwigo_infos_last_notice ===
    /**
     * Date the admin was last shown the usage-statistics opt-in notice.
     */
    private static ?string $sendPiwigoInfosLastNotice = null;

    public static function sendPiwigoInfosLastNotice(): ?string
    {
        return self::$sendPiwigoInfosLastNotice;
    }

    public static function setSendPiwigoInfosLastNotice(?string $value): void
    {
        self::$sendPiwigoInfosLastNotice = $value;
    }

    // === send_piwigo_infos_origin_hash ===
    /**
     * Anonymous installation hash included in usage statistics.
     */
    private static ?string $sendPiwigoInfosOriginHash = null;

    public static function sendPiwigoInfosOriginHash(): ?string
    {
        return self::$sendPiwigoInfosOriginHash;
    }

    public static function setSendPiwigoInfosOriginHash(?string $value): void
    {
        self::$sendPiwigoInfosOriginHash = $value;
    }

    // === send_piwigo_infos_period ===
    /**
     * Minimum seconds between two "send Piwigo infos" telemetry pings.
     */
    private static int $sendPiwigoInfosPeriod = 7 * 24 * 60 * 60;

    public static function sendPiwigoInfosPeriod(): int
    {
        return self::$sendPiwigoInfosPeriod;
    }

    public static function setSendPiwigoInfosPeriod(int $value): void
    {
        self::$sendPiwigoInfosPeriod = $value;
    }

    // === send_piwigo_infos_update_url ===
    /**
     * Base URL the "send Piwigo infos" telemetry ping is posted to.
     */
    private static string $sendPiwigoInfosUpdateUrl = \Piwigo\Core\AppInfo::URL;

    public static function sendPiwigoInfosUpdateUrl(): string
    {
        return self::$sendPiwigoInfosUpdateUrl;
    }

    public static function setSendPiwigoInfosUpdateUrl(string $value): void
    {
        self::$sendPiwigoInfosUpdateUrl = $value;
    }

    // === session_gc_probability ===
    /**
     * Probability weight (out of 100) that a PHP session GC run is triggered per
     * request.
     */
    private static int $sessionGcProbability = 1;

    public static function sessionGcProbability(): int
    {
        return self::$sessionGcProbability;
    }

    public static function setSessionGcProbability(int $value): void
    {
        self::$sessionGcProbability = $value;
    }

    // === session_length ===
    /**
     * PHP session lifetime in seconds (sets cookie_lifetime and gc_maxlifetime).
     */
    private static int $sessionLength = 3600;

    public static function sessionLength(): int
    {
        return self::$sessionLength;
    }

    public static function setSessionLength(int $value): void
    {
        self::$sessionLength = $value;
    }

    // === session_name ===
    /**
     * PHP session cookie name used by Piwigo.
     */
    private static string $sessionName = 'pwg_id';

    public static function sessionName(): string
    {
        return self::$sessionName;
    }

    public static function setSessionName(string $value): void
    {
        self::$sessionName = $value;
    }

    // === session_save_handler ===
    /**
     * Session storage backend: db (database) or files.
     */
    private static string $sessionSaveHandler = 'db';

    public static function sessionSaveHandler(): string
    {
        return self::$sessionSaveHandler;
    }

    public static function setSessionSaveHandler(string $value): void
    {
        self::$sessionSaveHandler = $value;
    }

    // === session_use_cookies ===
    /**
     * Store the session ID in a cookie (PHP session.use_cookies).
     */
    private static bool $sessionUseCookies = true;

    public static function sessionUseCookies(): bool
    {
        return self::$sessionUseCookies;
    }

    public static function setSessionUseCookies(bool $value): void
    {
        self::$sessionUseCookies = $value;
    }

    // === session_use_ip_address ===
    /**
     * Bind sessions to the client IP address to reduce session-hijacking risk.
     */
    private static bool $sessionUseIpAddress = true;

    public static function sessionUseIpAddress(): bool
    {
        return self::$sessionUseIpAddress;
    }

    public static function setSessionUseIpAddress(bool $value): void
    {
        self::$sessionUseIpAddress = $value;
    }

    // === session_use_only_cookies ===
    /**
     * Reject session IDs passed in the URL; require cookie only (PHP
     * session.use_only_cookies).
     */
    private static bool $sessionUseOnlyCookies = true;

    public static function sessionUseOnlyCookies(): bool
    {
        return self::$sessionUseOnlyCookies;
    }

    public static function setSessionUseOnlyCookies(bool $value): void
    {
        self::$sessionUseOnlyCookies = $value;
    }

    // === session_use_trans_sid ===
    /**
     * Allow the session ID to be transmitted in the URL query string (PHP
     * session.use_trans_sid).
     */
    private static bool $sessionUseTransSid = false;

    public static function sessionUseTransSid(): bool
    {
        return self::$sessionUseTransSid;
    }

    public static function setSessionUseTransSid(bool $value): void
    {
        self::$sessionUseTransSid = $value;
    }

    // === show_exif ===
    /**
     * Display EXIF metadata on the photo detail page.
     */
    private static bool $showExif = true;

    public static function showExif(): bool
    {
        return self::$showExif;
    }

    public static function setShowExif(bool $value): void
    {
        self::$showExif = $value;
    }

    // === show_gt ===
    /**
     * Show the Go-to navigation widget on photo detail pages.
     */
    private static bool $showGt = false;

    public static function showGt(): bool
    {
        return self::$showGt;
    }

    public static function setShowGt(bool $value): void
    {
        self::$showGt = $value;
    }

    // === show_iptc ===
    /**
     * Display IPTC metadata on the photo detail page.
     */
    private static bool $showIptc = false;

    public static function showIptc(): bool
    {
        return self::$showIptc;
    }

    public static function setShowIptc(bool $value): void
    {
        self::$showIptc = $value;
    }

    // === show_mobile_app_banner_in_admin ===
    /**
     * Show the "get the mobile app" banner while browsing the admin.
     */
    private static bool $showMobileAppBannerInAdmin = true;

    public static function showMobileAppBannerInAdmin(): bool
    {
        return self::$showMobileAppBannerInAdmin;
    }

    public static function setShowMobileAppBannerInAdmin(bool $value): void
    {
        self::$showMobileAppBannerInAdmin = $value;
    }

    // === show_mobile_app_banner_in_gallery ===
    /**
     * Show the "get the mobile app" banner while browsing the gallery.
     */
    private static bool $showMobileAppBannerInGallery = false;

    public static function showMobileAppBannerInGallery(): bool
    {
        return self::$showMobileAppBannerInGallery;
    }

    public static function setShowMobileAppBannerInGallery(bool $value): void
    {
        self::$showMobileAppBannerInGallery = $value;
    }

    // === show_newsletter_subscription ===
    /**
     * Show the newsletter subscription link in the gallery menu.
     */
    private static bool $showNewsletterSubscription = true;

    public static function showNewsletterSubscription(): bool
    {
        return self::$showNewsletterSubscription;
    }

    public static function setShowNewsletterSubscription(bool $value): void
    {
        self::$showNewsletterSubscription = $value;
    }

    // === show_piwigo_latest_news ===
    /**
     * Show the latest Piwigo project news on the admin dashboard.
     */
    private static bool $showPiwigoLatestNews = true;

    public static function showPiwigoLatestNews(): bool
    {
        return self::$showPiwigoLatestNews;
    }

    public static function setShowPiwigoLatestNews(bool $value): void
    {
        self::$showPiwigoLatestNews = $value;
    }

    // === show_queries ===
    /**
     * Append executed SQL queries to the page HTML for debugging.
     */
    private static bool $showQueries = false;

    public static function showQueries(): bool
    {
        return self::$showQueries;
    }

    public static function setShowQueries(bool $value): void
    {
        self::$showQueries = $value;
    }

    // === show_template_in_side_menu ===
    /**
     * Show the active theme name in the gallery sidebar.
     */
    private static bool $showTemplateInSideMenu = false;

    public static function showTemplateInSideMenu(): bool
    {
        return self::$showTemplateInSideMenu;
    }

    public static function setShowTemplateInSideMenu(bool $value): void
    {
        self::$showTemplateInSideMenu = $value;
    }

    // === show_thumbnail_caption ===
    /**
     * Show the photo title below thumbnails in album index pages.
     */
    private static bool $showThumbnailCaption = true;

    public static function showThumbnailCaption(): bool
    {
        return self::$showThumbnailCaption;
    }

    public static function setShowThumbnailCaption(bool $value): void
    {
        self::$showThumbnailCaption = $value;
    }

    // === show_version ===
    /**
     * Display the Piwigo version string in the page footer and emails.
     */
    private static bool $showVersion = false;

    public static function showVersion(): bool
    {
        return self::$showVersion;
    }

    public static function setShowVersion(bool $value): void
    {
        self::$showVersion = $value;
    }

    // === slideshow_period ===
    /**
     * Default interval in seconds between photos in the slideshow.
     */
    private static int $slideshowPeriod = 4;

    public static function slideshowPeriod(): int
    {
        return self::$slideshowPeriod;
    }

    public static function setSlideshowPeriod(int $value): void
    {
        self::$slideshowPeriod = $value;
    }

    // === slideshow_period_max ===
    /**
     * Maximum selectable interval in seconds for the slideshow.
     */
    private static int $slideshowPeriodMax = 10;

    public static function slideshowPeriodMax(): int
    {
        return self::$slideshowPeriodMax;
    }

    public static function setSlideshowPeriodMax(int $value): void
    {
        self::$slideshowPeriodMax = $value;
    }

    // === slideshow_period_min ===
    /**
     * Minimum selectable interval in seconds for the slideshow.
     */
    private static int $slideshowPeriodMin = 1;

    public static function slideshowPeriodMin(): int
    {
        return self::$slideshowPeriodMin;
    }

    public static function setSlideshowPeriodMin(int $value): void
    {
        self::$slideshowPeriodMin = $value;
    }

    // === slideshow_period_step ===
    /**
     * Step size in seconds for the slideshow interval selector.
     */
    private static int $slideshowPeriodStep = 1;

    public static function slideshowPeriodStep(): int
    {
        return self::$slideshowPeriodStep;
    }

    public static function setSlideshowPeriodStep(int $value): void
    {
        self::$slideshowPeriodStep = $value;
    }

    // === slideshow_repeat ===
    /**
     * Loop the slideshow back to the first photo after the last.
     */
    private static bool $slideshowRepeat = true;

    public static function slideshowRepeat(): bool
    {
        return self::$slideshowRepeat;
    }

    public static function setSlideshowRepeat(bool $value): void
    {
        self::$slideshowRepeat = $value;
    }

    // === smtp_host ===
    /**
     * SMTP server hostname (and optional port) for outgoing email.
     */
    private static string $smtpHost = '';

    public static function smtpHost(): string
    {
        return self::$smtpHost;
    }

    public static function setSmtpHost(string $value): void
    {
        self::$smtpHost = $value;
    }

    // === smtp_password ===
    /**
     * SMTP authentication password.
     */
    #[Sensitive]
    private static string $smtpPassword = '';

    public static function smtpPassword(): string
    {
        return self::$smtpPassword;
    }

    public static function setSmtpPassword(string $value): void
    {
        self::$smtpPassword = $value;
    }

    // === smtp_secure ===
    /**
     * SMTP connection security: null (none), ssl, or tls.
     */
    private static ?string $smtpSecure = null;

    public static function smtpSecure(): ?string
    {
        return self::$smtpSecure;
    }

    public static function setSmtpSecure(?string $value): void
    {
        self::$smtpSecure = $value;
    }

    // === smtp_user ===
    /**
     * SMTP authentication username.
     */
    private static string $smtpUser = '';

    public static function smtpUser(): string
    {
        return self::$smtpUser;
    }

    public static function setSmtpUser(string $value): void
    {
        self::$smtpUser = $value;
    }

    // === standard_pages_selected_logo ===
    /**
     * Which logo the "standard pages" theme fallback displays: 'piwigo_logo',
     * 'custom_logo', 'gallery_title', or 'none'.
     */
    private static string $standardPagesSelectedLogo = 'piwigo_logo';

    public static function standardPagesSelectedLogo(): string
    {
        return self::$standardPagesSelectedLogo;
    }

    public static function setStandardPagesSelectedLogo(string $value): void
    {
        self::$standardPagesSelectedLogo = $value;
    }

    // === standard_pages_selected_logo_path ===
    /**
     * Disk-relative path (under the 'local' disk) of the uploaded custom logo
     * used by the "standard pages" theme fallback; null until one is uploaded.
     */
    private static ?string $standardPagesSelectedLogoPath = null;

    public static function standardPagesSelectedLogoPath(): ?string
    {
        return self::$standardPagesSelectedLogoPath;
    }

    public static function setStandardPagesSelectedLogoPath(?string $value): void
    {
        self::$standardPagesSelectedLogoPath = $value;
    }

    // === standard_pages_selected_skin ===
    /**
     * Skin used by the "standard pages" theme fallback (login/register/...).
     */
    private static string $standardPagesSelectedSkin = 'default';

    public static function standardPagesSelectedSkin(): string
    {
        return self::$standardPagesSelectedSkin;
    }

    public static function setStandardPagesSelectedSkin(string $value): void
    {
        self::$standardPagesSelectedSkin = $value;
    }

    // === stat_compare_year_displayed ===
    /**
     * Number of years of photo statistics shown in the comparison chart.
     */
    private static int $statCompareYearDisplayed = 5;

    public static function statCompareYearDisplayed(): int
    {
        return self::$statCompareYearDisplayed;
    }

    public static function setStatCompareYearDisplayed(int $value): void
    {
        self::$statCompareYearDisplayed = $value;
    }

    // === tag_letters_column_number ===
    /**
     * Number of columns in the alphabetical tag index layout.
     */
    private static int $tagLettersColumnNumber = 4;

    public static function tagLettersColumnNumber(): int
    {
        return self::$tagLettersColumnNumber;
    }

    public static function setTagLettersColumnNumber(int $value): void
    {
        self::$tagLettersColumnNumber = $value;
    }

    // === tag_url_style ===
    /**
     * URL format for tag links: id, tag, or id-tag.
     */
    private static string $tagUrlStyle = 'id-tag';

    public static function tagUrlStyle(): string
    {
        return self::$tagUrlStyle;
    }

    public static function setTagUrlStyle(string $value): void
    {
        self::$tagUrlStyle = $value;
    }

    // === tags_default_display_mode ===
    /**
     * Default tag-listing display mode: cloud or letters.
     */
    private static string $tagsDefaultDisplayMode = 'cloud';

    public static function tagsDefaultDisplayMode(): string
    {
        return self::$tagsDefaultDisplayMode;
    }

    public static function setTagsDefaultDisplayMode(string $value): void
    {
        self::$tagsDefaultDisplayMode = $value;
    }

    // === tags_levels ===
    /**
     * Number of font-size levels used in the tag cloud.
     */
    private static int $tagsLevels = 5;

    public static function tagsLevels(): int
    {
        return self::$tagsLevels;
    }

    public static function setTagsLevels(int $value): void
    {
        self::$tagsLevels = $value;
    }

    // === template_combine_files ===
    /**
     * Merge JavaScript/CSS files together at render time to reduce the number of
     * HTTP requests.
     */
    private static bool $templateCombineFiles = true;

    public static function templateCombineFiles(): bool
    {
        return self::$templateCombineFiles;
    }

    public static function setTemplateCombineFiles(bool $value): void
    {
        self::$templateCombineFiles = $value;
    }

    // === template_compile_check ===
    /**
     * Recompile Latte templates when source files change (disable in production).
     */
    private static bool $templateCompileCheck = true;

    public static function templateCompileCheck(): bool
    {
        return self::$templateCompileCheck;
    }

    public static function setTemplateCompileCheck(bool $value): void
    {
        self::$templateCompileCheck = $value;
    }

    // === template_force_compile ===
    /**
     * Always recompile Latte templates on every request.
     */
    private static bool $templateForceCompile = false;

    public static function templateForceCompile(): bool
    {
        return self::$templateForceCompile;
    }

    public static function setTemplateForceCompile(bool $value): void
    {
        self::$templateForceCompile = $value;
    }

    // === themes_dir ===
    /**
     * Root-relative path to the directory containing installed themes (compose
     * with CurrentPaths::get()->root for an absolute filesystem path).
     */
    private static string $themesDir = 'themes/';

    public static function themesDir(): string
    {
        return self::$themesDir;
    }

    public static function setThemesDir(string $value): void
    {
        self::$themesDir = $value;
    }

    // === tiff_representative_ext ===
    /**
     * Image extension used when generating a representative for TIFF originals.
     */
    private static string $tiffRepresentativeExt = 'png';

    public static function tiffRepresentativeExt(): string
    {
        return self::$tiffRepresentativeExt;
    }

    public static function setTiffRepresentativeExt(string $value): void
    {
        self::$tiffRepresentativeExt = $value;
    }

    // === top_number ===
    /**
     * Number of items shown in top ranking lists (most visited, best rated, etc.).
     */
    private static int $topNumber = 15;

    public static function topNumber(): int
    {
        return self::$topNumber;
    }

    public static function setTopNumber(int $value): void
    {
        self::$topNumber = $value;
    }

    // === trusted_proxies ===
    /**
     * Comma-separated CIDR list of reverse proxies whose forwarded headers are
     * trusted.
     */
    private static string $trustedProxies = '';

    public static function trustedProxies(): string
    {
        return self::$trustedProxies;
    }

    public static function setTrustedProxies(string $value): void
    {
        self::$trustedProxies = $value;
    }

    // === uniqueness_mode ===
    /**
     * Algorithm used to detect duplicate uploads: md5sum or filename.
     */
    private static string $uniquenessMode = 'md5sum';

    public static function uniquenessMode(): string
    {
        return self::$uniquenessMode;
    }

    public static function setUniquenessMode(string $value): void
    {
        self::$uniquenessMode = $value;
    }

    // === update_notify_check_period ===
    /**
     * Interval in seconds between automatic checks for Piwigo updates.
     */
    private static int $updateNotifyCheckPeriod = 86400;

    public static function updateNotifyCheckPeriod(): int
    {
        return self::$updateNotifyCheckPeriod;
    }

    public static function setUpdateNotifyCheckPeriod(int $value): void
    {
        self::$updateNotifyCheckPeriod = $value;
    }

    // === update_notify_last_check ===
    /**
     * Timestamp of the last update-availability check.
     */
    private static ?string $updateNotifyLastCheck = null;

    public static function updateNotifyLastCheck(): ?string
    {
        return self::$updateNotifyLastCheck;
    }

    public static function setUpdateNotifyLastCheck(?string $value): void
    {
        self::$updateNotifyLastCheck = $value;
    }

    // === update_notify_reminder_period ===
    /**
     * Interval in seconds between repeated update reminder notifications.
     */
    private static int $updateNotifyReminderPeriod = 604800;

    public static function updateNotifyReminderPeriod(): int
    {
        return self::$updateNotifyReminderPeriod;
    }

    public static function setUpdateNotifyReminderPeriod(int $value): void
    {
        self::$updateNotifyReminderPeriod = $value;
    }

    // === upload_detect_duplicate ===
    /**
     * Check for duplicate photos by checksum when uploading.
     */
    private static bool $uploadDetectDuplicate = true;

    public static function uploadDetectDuplicate(): bool
    {
        return self::$uploadDetectDuplicate;
    }

    public static function setUploadDetectDuplicate(bool $value): void
    {
        self::$uploadDetectDuplicate = $value;
    }

    // === upload_dir ===
    /**
     * Root-relative path to the directory where uploaded files are stored (compose
     * with CurrentPaths::get()->root for an absolute filesystem path).
     */
    private static string $uploadDir = 'upload/';

    public static function uploadDir(): string
    {
        return self::$uploadDir;
    }

    public static function setUploadDir(string $value): void
    {
        self::$uploadDir = $value;
    }

    // === upload_form_all_types ===
    /**
     * Allow uploading any file type, not just images and videos.
     */
    private static bool $uploadFormAllTypes = false;

    public static function uploadFormAllTypes(): bool
    {
        return self::$uploadFormAllTypes;
    }

    public static function setUploadFormAllTypes(bool $value): void
    {
        self::$uploadFormAllTypes = $value;
    }

    // === upload_form_automatic_rotation ===
    /**
     * Automatically rotate uploaded photos based on their EXIF orientation tag.
     */
    private static bool $uploadFormAutomaticRotation = true;

    public static function uploadFormAutomaticRotation(): bool
    {
        return self::$uploadFormAutomaticRotation;
    }

    public static function setUploadFormAutomaticRotation(bool $value): void
    {
        self::$uploadFormAutomaticRotation = $value;
    }

    // === upload_form_chunk_size ===
    /**
     * Chunk size in KB for multi-part file uploads via the upload form.
     */
    private static int $uploadFormChunkSize = 500;

    public static function uploadFormChunkSize(): int
    {
        return self::$uploadFormChunkSize;
    }

    public static function setUploadFormChunkSize(int $value): void
    {
        self::$uploadFormChunkSize = $value;
    }

    // === upload_form_max_file_size ===
    /**
     * Maximum file size in MB accepted by the upload form.
     */
    private static int $uploadFormMaxFileSize = 1000;

    public static function uploadFormMaxFileSize(): int
    {
        return self::$uploadFormMaxFileSize;
    }

    public static function setUploadFormMaxFileSize(int $value): void
    {
        self::$uploadFormMaxFileSize = $value;
    }

    // === url_port ===
    /**
     * Port included in generated URLs: none, or a port number string.
     */
    private static string $urlPort = 'none';

    public static function urlPort(): string
    {
        return self::$urlPort;
    }

    public static function setUrlPort(string $value): void
    {
        self::$urlPort = $value;
    }

    // === use_exif ===
    /**
     * Read EXIF metadata from uploaded photos and store it in the database.
     */
    private static bool $useExif = true;

    public static function useExif(): bool
    {
        return self::$useExif;
    }

    public static function setUseExif(bool $value): void
    {
        self::$useExif = $value;
    }

    // === use_iptc ===
    /**
     * Read IPTC metadata from uploaded photos and store it in the database.
     */
    private static bool $useIptc = false;

    public static function useIptc(): bool
    {
        return self::$useIptc;
    }

    public static function setUseIptc(bool $value): void
    {
        self::$useIptc = $value;
    }

    // === use_proxy ===
    /**
     * Send outgoing HTTP requests from Piwigo through a proxy server.
     */
    private static bool $useProxy = false;

    public static function useProxy(): bool
    {
        return self::$useProxy;
    }

    public static function setUseProxy(bool $value): void
    {
        self::$useProxy = $value;
    }

    // === use_standard_pages ===
    /**
     * Whether the current theme falls back to Piwigo's own "standard pages"
     * (login/register/forgot-password/...) instead of its own.
     */
    private static bool $useStandardPages = true;

    public static function useStandardPages(): bool
    {
        return self::$useStandardPages;
    }

    public static function setUseStandardPages(bool $value): void
    {
        self::$useStandardPages = $value;
    }

    // === user_can_delete_comment ===
    /**
     * Allow a registered user to delete their own comments.
     */
    private static bool $userCanDeleteComment = false;

    public static function userCanDeleteComment(): bool
    {
        return self::$userCanDeleteComment;
    }

    public static function setUserCanDeleteComment(bool $value): void
    {
        self::$userCanDeleteComment = $value;
    }

    // === user_can_edit_comment ===
    /**
     * Allow a registered user to edit their own comments.
     */
    private static bool $userCanEditComment = false;

    public static function userCanEditComment(): bool
    {
        return self::$userCanEditComment;
    }

    public static function setUserCanEditComment(bool $value): void
    {
        self::$userCanEditComment = $value;
    }

    // === webmaster_id ===
    /**
     * User ID of the designated webmaster account.
     */
    private static int $webmasterId = 1;

    public static function webmasterId(): int
    {
        return self::$webmasterId;
    }

    public static function setWebmasterId(int $value): void
    {
        self::$webmasterId = $value;
    }

    // === week_starts_on ===
    /**
     * First day of the week in calendar views: monday or sunday.
     */
    private static string $weekStartsOn = 'monday';

    public static function weekStartsOn(): string
    {
        return self::$weekStartsOn;
    }

    public static function setWeekStartsOn(string $value): void
    {
        self::$weekStartsOn = $value;
    }

    // === ws_max_images_per_page ===
    /**
     * Maximum number of photos returned per page by the web-service API.
     */
    private static int $wsMaxImagesPerPage = 500;

    public static function wsMaxImagesPerPage(): int
    {
        return self::$wsMaxImagesPerPage;
    }

    public static function setWsMaxImagesPerPage(int $value): void
    {
        self::$wsMaxImagesPerPage = $value;
    }

    // === ws_max_users_per_page ===
    /**
     * Maximum number of users returned per page by the web-service API.
     */
    private static int $wsMaxUsersPerPage = 1000;

    public static function wsMaxUsersPerPage(): int
    {
        return self::$wsMaxUsersPerPage;
    }

    public static function setWsMaxUsersPerPage(int $value): void
    {
        self::$wsMaxUsersPerPage = $value;
    }

    // ---- Custom-shaped properties (non-trivial coercion) ---------------

    // === api_key_duration ===
    /**
     * Selectable API-key expiration presets, in days (plus the literal 'custom' entry).
     * @var list<string>
     */
    private static array $apiKeyDuration = ['30', '90', '180', '365', 'custom'];

    /**
     * @return list<string>
     */
    public static function apiKeyDuration(): array
    {
        return self::$apiKeyDuration;
    }

    /**
     * @param array<mixed> $value
     */
    public static function setApiKeyDuration(array $value): void
    {
        self::$apiKeyDuration = array_values(array_map(static fn (mixed $x): string => is_scalar($x) || $x === null ? (string) $x : '', $value));
    }

    // === api_key_forbidden_methods ===
    /**
     * Web-service method names that API-key callers are not allowed to invoke.
     * @var list<string>
     */
    private static array $apiKeyForbiddenMethods = ['pwg.users.generatePasswordLink', 'pwg.users.getAuthKey', 'pwg.users.setMainUser', 'pwg.users.setInfo', 'pwg.plugins.performAction', 'pwg.themes.performAction', 'pwg.extensions.ignoreUpdate', 'pwg.extensions.update'];

    /**
     * @return list<string>
     */
    public static function apiKeyForbiddenMethods(): array
    {
        return self::$apiKeyForbiddenMethods;
    }

    /**
     * @param array<mixed> $value
     */
    public static function setApiKeyForbiddenMethods(array $value): void
    {
        self::$apiKeyForbiddenMethods = array_values(array_map(static fn (mixed $x): string => is_scalar($x) || $x === null ? (string) $x : '', $value));
    }

    // === available_permission_levels ===
    /**
     * Ordered list of numeric permission levels visible in the UI.
     * @var list<int>
     */
    private static array $availablePermissionLevels = [0, 1, 2, 4, 8];

    /**
     * @return list<int>
     */
    public static function availablePermissionLevels(): array
    {
        return self::$availablePermissionLevels;
    }

    /**
     * @param array<mixed> $value
     */
    public static function setAvailablePermissionLevels(array $value): void
    {
        self::$availablePermissionLevels = count($value) > 0
            ? array_values(array_map(static fn (mixed $x): int => is_scalar($x) ? (int) $x : 0, $value))
            : [0, 1, 2, 4, 8];
    }

    // === blk_menubar ===
    /**
     * Serialized per-block position overrides for the sidebar menubar (the
     * only real Piwigo\Menu\BlockManager id anywhere in this codebase --
     * confirmed by grepping every `new BlockManager(...)` call site). Never
     * had a SCHEMA entry -- read via the dynamic `'blk_' . $id` bag key by
     * BlockManager/MenubarPageRenderer. Given a real property here instead of
     * staying dynamic, since the "id" was never actually variable in
     * practice.
     *
     * `?array`, not the raw encoded blob -- matches every other array-shaped
     * property (gap-closure Stage 1a-bis item 1). `Admin\MenubarPageRenderer`
     * writes this directly via `Config\ConfigRepository::upsert()` (P24
     * Part B: the row-shaped write this used to do through the now-deleted
     * `Menu\MenubarLayoutRepository` needed no dedicated repository once
     * `ConfigEntry` existed), `json_encode()`-d the same way `ConfigService::
     * encode()`'s own item 5 convention does -- so the read side stays a
     * plain `hydrate()`-driven `json_decode()`, no manual unserialize()
     * anywhere.
     * @var array<mixed>|null
     */
    private static ?array $blkMenubar = null;

    /**
     * @return array<mixed>|null
     */
    public static function blkMenubar(): ?array
    {
        return self::$blkMenubar;
    }

    /**
     * @param array<mixed>|null $value
     */
    public static function setBlkMenubar(?array $value): void
    {
        self::$blkMenubar = $value;
    }

    // === cache_sizes ===
    /**
     * Serialized [name, value] rows of cache-directory sizes computed by the
     * maintenance page, cached to avoid recomputing on every dashboard/
     * maintenance load.
     * @var array<mixed>|null
     */
    private static ?array $cacheSizes = null;

    /**
     * @return array<mixed>|null
     */
    public static function cacheSizes(): ?array
    {
        return self::$cacheSizes;
    }

    /**
     * @param array<mixed>|null $value
     */
    public static function setCacheSizes(?array $value): void
    {
        self::$cacheSizes = $value;
    }

    // === chmod_value ===
    /**
     * Filesystem permission bits applied to newly created directories -- 0777
     * under Apache, 0755 otherwise, unless explicitly overridden. Null means
     * "not explicitly overridden": the SAPI-dependent default below applies.
     */
    private static ?int $chmodValue = null;

    public static function chmodValue(): int
    {
        // Real bug, found live: this SAPI-only heuristic (byte-identical to
        // the pre-rewrite $conf['chmod_value'] default) assumes whichever
        // single process creates a directory is the only one that will
        // ever need to write to it -- true for a real standalone
        // deployment, false in this test environment, where CLI-run suites
        // (Unit/Integration, as `torres`) and real Apache-served suites
        // (Contract/Browser, as `www-data`) share the same _data/ tree
        // within one composer test:coverage:all run. Whichever side's
        // mkgetdir() call creates a shared directory first "wins" its mode
        // for the rest of the run -- a CLI test creating _data/templates_c
        // first left it torres-only (0755), and every subsequent
        // Apache-served request 500'd the moment it needed that same
        // directory (took down an entire Contract suite run this way).
        // Env::testModeIsActive() also covers a real Apache-served
        // Browser-test request (loopback + header), so this doesn't
        // narrow that side at all -- it only widens the CLI side to match.
        if (\Piwigo\Core\Env::testModeIsActive()) {
            return self::$chmodValue ?? 0777;
        }

        return self::$chmodValue ?? (substr_compare(\PHP_SAPI, 'apa', 0, 3) === 0 ? 0777 : 0755);
    }

    public static function setChmodValue(?int $value): void
    {
        self::$chmodValue = $value;
    }

    // === default_filters_views ===
    /**
     * @var array<string, array{access: string, default: bool}>
     */
    private const array DEFAULT_FILTERS_VIEWS = [
        'words' => [
            'access' => 'everybody',
            'default' => true,
        ],
        'tags' => [
            'access' => 'everybody',
            'default' => false,
        ],
        'post_date' => [
            'access' => 'everybody',
            'default' => false,
        ],
        'creation_date' => [
            'access' => 'everybody',
            'default' => true,
        ],
        'album' => [
            'access' => 'everybody',
            'default' => true,
        ],
        'author' => [
            'access' => 'everybody',
            'default' => false,
        ],
        'added_by' => [
            'access' => 'everybody',
            'default' => false,
        ],
        'file_type' => [
            'access' => 'everybody',
            'default' => false,
        ],
        'ratio' => [
            'access' => 'everybody',
            'default' => false,
        ],
        'rating' => [
            'access' => 'everybody',
            'default' => false,
        ],
        'file_size' => [
            'access' => 'everybody',
            'default' => false,
        ],
        'height' => [
            'access' => 'everybody',
            'default' => false,
        ],
        'width' => [
            'access' => 'everybody',
            'default' => false,
        ],
        'expert' => [
            'access' => 'everybody',
            'default' => false,
        ],
    ];

    /**
     * Factory-default search-filter definitions (access level + default-on
     * state per filter key); seeds the 'filters_views' DB row on first use and
     * drives the search filters admin page.
     * @var array<string, array{access: string, default: bool}>
     */
    private static array $defaultFiltersViews = self::DEFAULT_FILTERS_VIEWS;

    /**
     * @return array<string, array{access: string, default: bool}>
     */
    public static function defaultFiltersViews(): array
    {
        return self::$defaultFiltersViews;
    }

    /**
     * @param array<mixed>|null $value
     */
    public static function setDefaultFiltersViews(?array $value): void
    {
        if ($value === null) {
            self::$defaultFiltersViews = self::DEFAULT_FILTERS_VIEWS;
            return;
        }
        $result = [];
        foreach (self::DEFAULT_FILTERS_VIEWS as $key => $defaultEntry) {
            $entry = $value[$key] ?? null;
            $result[$key] = is_array($entry) && is_string($entry['access'] ?? null) && is_bool($entry['default'] ?? null)
                ? [
                    'access' => $entry['access'],
                    'default' => $entry['default'],
                ]
                : $defaultEntry;
        }
        self::$defaultFiltersViews = $result;
    }

    // === empty_lounge_running ===
    /**
     * Transient "<execId>-<startTime>" marker set while
     * ImageService::emptyLounge() is running, used to detect a concurrent/
     * stalled run. Absent when no run is in progress.
     */
    private static ?string $emptyLoungeRunning = null;

    public static function emptyLoungeRunning(): ?string
    {
        return self::$emptyLoungeRunning;
    }

    public static function setEmptyLoungeRunning(?string $value): void
    {
        self::$emptyLoungeRunning = $value;
    }

    // === extents_for_templates ===
    /**
     * Comma-separated list of template file extensions recognised by the
     * theme engine.
     * @var array<mixed>
     */
    private static array $extentsForTemplates = [];

    /**
     * @return array<mixed>
     */
    public static function extentsForTemplates(): array
    {
        return self::$extentsForTemplates;
    }

    /**
     * @param array<mixed> $value
     */
    public static function setExtentsForTemplates(array $value): void
    {
        self::$extentsForTemplates = $value;
    }

    // === file_ext ===
    /**
     * Full list of file extensions Piwigo will manage (pictures plus extras).
     * @var list<string>
     */
    private static array $fileExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'tiff', 'tif', 'mpg', 'zip', 'avi', 'mp3', 'ogg', 'pdf', 'svg', 'heic'];

    /**
     * @return list<string>
     */
    public static function fileExtensions(): array
    {
        return self::$fileExtensions;
    }

    /**
     * @param array<mixed> $value
     */
    public static function setFileExtensions(array $value): void
    {
        self::$fileExtensions = array_values(array_map(static fn (mixed $x): string => is_scalar($x) || $x === null ? (string) $x : '', $value));
    }

    // === filter_pages ===
    /**
     * @var array<mixed>
     */
    private const array DEFAULT_FILTER_PAGES = [
        'default' => [
            'used' => true,
            'cancel' => false,
            'add_notes' => false,
        ],
        'index' => [
            'add_notes' => true,
        ],
        'tags' => [
            'add_notes' => true,
        ],
        'search' => [
            'add_notes' => true,
        ],
        'comments' => [
            'add_notes' => true,
        ],
        'admin' => [
            'used' => false,
        ],
        'feed' => [
            'used' => false,
        ],
        'notification' => [
            'used' => false,
        ],
        'nbm' => [
            'used' => false,
        ],
        'popuphelp' => [
            'used' => false,
        ],
        'profile' => [
            'used' => false,
        ],
        'ws' => [
            'used' => false,
        ],
        'identification' => [
            'cancel' => true,
        ],
        'install' => [
            'cancel' => true,
        ],
        'password' => [
            'cancel' => true,
        ],
        'register' => [
            'cancel' => true,
        ],
    ];

    /**
     * Pages on which the tag/date filter UI is displayed.
     * @var array<string, array<string, bool>>
     */
    private static array $filterPages = self::DEFAULT_FILTER_PAGES;

    /**
     * @return array<string, array<string, bool>>
     */
    public static function filterPages(): array
    {
        return self::$filterPages;
    }

    /**
     * @param array<string, array<string, bool>> $value
     */
    public static function setFilterPages(array $value): void
    {
        self::$filterPages = $value;
    }

    // === filters_views ===
    /**
     * Admin-customized search-filter definitions, lazily seeded from
     * 'default_filters_views' the first time the search filters admin page is
     * saved. Absent (falls back to defaultFiltersViews()) until then.
     * @var array<mixed>|null
     */
    private static ?array $filtersViews = null;

    /**
     * @return array<mixed>|null
     */
    public static function filtersViews(): ?array
    {
        return self::$filtersViews;
    }

    /**
     * @param array<mixed>|null $value
     */
    public static function setFiltersViews(?array $value): void
    {
        self::$filtersViews = $value;
    }

    // === format_ext ===
    /**
     * File extensions recognised as additional formats for multi-format
     * photos.
     * @var list<string>
     */
    private static array $formatExtensions = ['cr2', 'tif', 'tiff', 'nef', 'dng', 'ai', 'psd'];

    /**
     * @return list<string>
     */
    public static function formatExtensions(): array
    {
        return self::$formatExtensions;
    }

    /**
     * @param array<mixed> $value
     */
    public static function setFormatExtensions(array $value): void
    {
        self::$formatExtensions = array_values(array_map(static fn (mixed $x): string => is_scalar($x) || $x === null ? (string) $x : '', $value));
    }

    // === header_notes ===
    /**
     * Additional HTML messages shown in the gallery header for all users.
     * @var list<string>
     */
    private static array $headerNotes = [];

    /**
     * @return list<string>
     */
    public static function headerNotes(): array
    {
        return self::$headerNotes;
    }

    /**
     * @param array<mixed> $value
     */
    public static function setHeaderNotes(array $value): void
    {
        self::$headerNotes = array_values(array_map(static fn (mixed $x): string => is_scalar($x) || $x === null ? (string) $x : '', $value));
    }

    // === history_sections_cache ===
    /**
     * Cached list of the history.section enum column values, refreshed when a
     * plugin adds a new section.
     * @var list<string>|null
     */
    private static ?array $historySectionsCache = null;

    /**
     * @return list<string>|null
     */
    public static function historySectionsCache(): ?array
    {
        return self::$historySectionsCache;
    }

    /**
     * @param array<mixed>|null $value
     */
    public static function setHistorySectionsCache(?array $value): void
    {
        self::$historySectionsCache = $value === null ? null : array_values(array_filter($value, is_string(...)));
    }

    // === links ===
    /**
     * Additional navigation links shown in the gallery menu.
     * @var array<mixed>
     */
    private static array $links = [];

    /**
     * @return array<mixed>
     */
    public static function links(): array
    {
        return self::$links;
    }

    /**
     * @param array<mixed> $value
     */
    public static function setLinks(array $value): void
    {
        self::$links = $value;
    }

    // === metadata_keyword_separator_regex ===
    /**
     * PCRE regex used to split keyword strings extracted from EXIF/IPTC
     * metadata.
     */
    private static string $metadataKeywordSeparatorRegex = '/[.,;]/';

    public static function metadataKeywordSeparatorRegex(): string
    {
        return self::$metadataKeywordSeparatorRegex;
    }

    public static function setMetadataKeywordSeparatorRegex(string $value): void
    {
        self::$metadataKeywordSeparatorRegex = $value !== '' ? $value : '/[.,;]/';
    }

    // === nbm_max_treatment_timeout_percent ===
    /**
     * Fraction of the PHP max_execution_time budget NBM may consume per batch.
     */
    private static float $nbmMaxTreatmentTimeoutPercent = 0.8;

    public static function nbmMaxTreatmentTimeoutPercent(): float
    {
        return self::$nbmMaxTreatmentTimeoutPercent;
    }

    public static function setNbmMaxTreatmentTimeoutPercent(float $value): void
    {
        self::$nbmMaxTreatmentTimeoutPercent = $value;
    }

    // === order_by ===
    // "nothing is frozen" gap-closure -- CurrentConfig::orderBy()'s structured
    // {field,dir}[] shape modeled NOTHING any real code ever wrote: every
    // real writer (ConfigurationSubController's save handler) always stores
    // a raw "ORDER BY ..." SQL fragment string, and every real reader (15+
    // call sites across BatchManager*/SearchService/CategoryService/
    // CalendarRenderer/TagService/SectionPopulator/Ws/PwgCategories/
    // GalleryController) already bypassed the typed accessor entirely via
    // CurrentConfig::all()['order_by'], is_string()-guarding it themselves. Fixed
    // here to match reality: a plain string, default matching
    // install/config.sql's own seed row. filterOrderEntries() (the shared
    // {field,dir}[] validator) is deleted -- nothing needs it once neither
    // order_by nor order_by_inside_category models that shape.
    private static string $orderBy = 'ORDER BY date_available DESC, file ASC, id ASC';

    public static function orderBy(): string
    {
        return self::$orderBy;
    }

    public static function setOrderBy(string $value): void
    {
        self::$orderBy = $value;
    }

    // === order_by_custom ===
    /**
     * Admin-defined custom sort order that overrides order_by when set --
     * a raw "ORDER BY ..." SQL fragment string, same real shape as order_by
     * itself (see its own docblock).
     */
    private static ?string $orderByCustom = null;

    public static function orderByCustom(): ?string
    {
        return self::$orderByCustom;
    }

    public static function setOrderByCustom(?string $value): void
    {
        self::$orderByCustom = $value;
    }

    // === order_by_inside_category ===
    /**
     * Active sort order applied within album listings -- a raw
     * "ORDER BY ..." SQL fragment string (see order_by's own docblock).
     */
    private static string $orderByInsideCategory = 'ORDER BY date_available DESC, file ASC, id ASC';

    public static function orderByInsideCategory(): string
    {
        return self::$orderByInsideCategory;
    }

    public static function setOrderByInsideCategory(string $value): void
    {
        self::$orderByInsideCategory = $value;
    }

    // === order_by_inside_category_custom ===
    /**
     * Admin-defined custom sort order that overrides order_by_inside_category
     * when set (see order_by's own docblock).
     */
    private static ?string $orderByInsideCategoryCustom = null;

    public static function orderByInsideCategoryCustom(): ?string
    {
        return self::$orderByInsideCategoryCustom;
    }

    public static function setOrderByInsideCategoryCustom(?string $value): void
    {
        self::$orderByInsideCategoryCustom = $value;
    }

    // === picture_ext ===
    /**
     * File extensions recognised as displayable photo types.
     * @var list<string>
     */
    private static array $pictureExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    /**
     * @return list<string>
     */
    public static function pictureExtensions(): array
    {
        return self::$pictureExtensions;
    }

    /**
     * @param array<mixed> $value
     */
    public static function setPictureExtensions(array $value): void
    {
        self::$pictureExtensions = array_values(array_map(static fn (mixed $x): string => is_scalar($x) || $x === null ? (string) $x : '', $value));
    }

    // === picture_informations ===
    /**
     * Map of metadata field names to visibility booleans on the photo detail
     * page.
     * @var array<string, bool>
     */
    private static array $pictureInformations = [];

    /**
     * @return array<string, bool>
     */
    public static function pictureInformations(): array
    {
        return self::$pictureInformations;
    }

    /**
     * @param array<mixed> $value
     */
    public static function setPictureInformations(array $value): void
    {
        $out = [];
        foreach ($value as $key => $val) {
            if (is_string($key) && is_bool($val)) {
                $out[$key] = $val;
            }
        }
        self::$pictureInformations = $out;
    }

    // === random_index_redirect ===
    /**
     * URL mapping for random-index redirects used by shuffle features.
     * @var array<string,string>
     */
    private static array $randomIndexRedirect = [];

    /**
     * @return array<string,string>
     */
    public static function randomIndexRedirect(): array
    {
        return self::$randomIndexRedirect;
    }

    /**
     * @param array<mixed> $value
     */
    public static function setRandomIndexRedirect(array $value): void
    {
        $result = [];
        foreach ($value as $key => $val) {
            if (is_scalar($val)) {
                $result[(string) $key] = (string) $val;
            }
        }
        self::$randomIndexRedirect = $result;
    }

    // === rate_items ===
    /**
     * Available rating values displayed in the rating widget.
     * @var list<int>
     */
    private static array $rateItems = [0, 1, 2, 3, 4, 5];

    /**
     * @return list<int>
     */
    public static function rateItems(): array
    {
        return self::$rateItems;
    }

    /**
     * @param array<mixed> $value
     */
    public static function setRateItems(array $value): void
    {
        self::$rateItems = array_values(array_map(static fn (mixed $x): int => is_scalar($x) ? (int) $x : 0, $value));
    }

    // === recent_post_dates ===
    /**
     * @var array{RSS: array{max_dates: int, max_elements: int, max_cats: int}, NBM: array{max_dates: int, max_elements: int, max_cats: int}}
     */
    private const array DEFAULT_RECENT_POST_DATES = [
        'RSS' => [
            'max_dates' => 5,
            'max_elements' => 6,
            'max_cats' => 6,
        ],
        'NBM' => [
            'max_dates' => 7,
            'max_elements' => 3,
            'max_cats' => 9,
        ],
    ];

    /**
     * Threshold dates used to determine which photos count as recent. Null
     * means "not explicitly set": the getter lazily builds the default VO
     * below (a property default can't call `new` directly).
     */
    private static ?NotificationConfig $recentPostDates = null;

    public static function recentPostDates(): NotificationConfig
    {
        return self::$recentPostDates ??= new NotificationConfig(
            rss: new NotificationChannelConfig(maxDates: 5, maxElements: 6, maxCats: 6),
            nbm: new NotificationChannelConfig(maxDates: 7, maxElements: 3, maxCats: 9),
        );
    }

    /**
     * @param array<mixed> $value
     */
    public static function setRecentPostDates(array $value): void
    {
        $build = static function (string $key) use ($value): NotificationChannelConfig {
            $default = self::DEFAULT_RECENT_POST_DATES[$key];
            $src = (isset($value[$key]) && is_array($value[$key])) ? $value[$key] : $default;
            return new NotificationChannelConfig(
                maxDates: isset($src['max_dates']) && is_int($src['max_dates']) ? $src['max_dates'] : $default['max_dates'],
                maxElements: isset($src['max_elements']) && is_int($src['max_elements']) ? $src['max_elements'] : $default['max_elements'],
                maxCats: isset($src['max_cats']) && is_int($src['max_cats']) ? $src['max_cats'] : $default['max_cats'],
            );
        };
        self::$recentPostDates = new NotificationConfig(rss: $build('RSS'), nbm: $build('NBM'));
    }

    // === show_exif_fields ===
    /**
     * List of EXIF field names to display on the photo detail page.
     * @var list<string>
     */
    private static array $showExifFields = ['Make', 'Model', 'DateTimeOriginal', 'COMPUTED;ApertureFNumber'];

    /**
     * @return list<string>
     */
    public static function showExifFields(): array
    {
        return self::$showExifFields;
    }

    /**
     * @param array<mixed> $value
     */
    public static function setShowExifFields(array $value): void
    {
        self::$showExifFields = array_values(array_map(static fn (mixed $x): string => is_scalar($x) || $x === null ? (string) $x : '', $value));
    }

    // === show_iptc_mapping ===
    /**
     * @var array<string,string>
     */
    private const array DEFAULT_SHOW_IPTC_MAPPING = [
        'iptc_keywords' => '2#025',
        'iptc_caption_writer' => '2#122',
        'iptc_byline_title' => '2#085',
        'iptc_caption' => '2#120',
    ];

    /**
     * Mapping of IPTC field codes to human-readable labels for display.
     * @var array<string,string>
     */
    private static array $showIptcMapping = self::DEFAULT_SHOW_IPTC_MAPPING;

    /**
     * @return array<string,string>
     */
    public static function showIptcMapping(): array
    {
        return self::$showIptcMapping;
    }

    /**
     * @param array<mixed> $value
     */
    public static function setShowIptcMapping(array $value): void
    {
        $result = [];
        foreach ($value as $k => $val) {
            $result[(string) $k] = is_scalar($val) ? (string) $val : '';
        }
        self::$showIptcMapping = $result;
    }

    // === sync_chars_regex ===
    /**
     * Regex that matches valid filename characters during filesystem
     * synchronisation.
     */
    private static string $syncCharsRegex = '/^[a-zA-Z0-9-_.]+$/';

    public static function syncCharsRegex(): string
    {
        return self::$syncCharsRegex;
    }

    public static function setSyncCharsRegex(string $value): void
    {
        self::$syncCharsRegex = $value !== '' ? $value : '/^[a-zA-Z0-9-_.]+$/';
    }

    // === sync_exclude_folders ===
    /**
     * Folder names excluded from filesystem synchronisation.
     * @var list<string>
     */
    private static array $syncExcludeFolders = [];

    /**
     * @return list<string>
     */
    public static function syncExcludeFolders(): array
    {
        return self::$syncExcludeFolders;
    }

    /**
     * @param array<mixed> $value
     */
    public static function setSyncExcludeFolders(array $value): void
    {
        self::$syncExcludeFolders = array_values(array_map(static fn (mixed $x): string => is_scalar($x) || $x === null ? (string) $x : '', $value));
    }

    // === update_notify_last_notification ===
    /**
     * Serialized {version, notified_on} of the last update-availability
     * notification shown to the admin. Genuine absence before the first
     * check.
     * @var array{version?: mixed, notified_on?: mixed}|null
     */
    private static ?array $updateNotifyLastNotification = null;

    /**
     * @return array{version?: mixed, notified_on?: mixed}|null
     */
    public static function updateNotifyLastNotification(): ?array
    {
        return self::$updateNotifyLastNotification;
    }

    /**
     * @param array<mixed>|null $value
     */
    public static function setUpdateNotifyLastNotification(?array $value): void
    {
        if ($value === null) {
            self::$updateNotifyLastNotification = null;
            return;
        }
        $result = [];
        if (array_key_exists('version', $value)) {
            $result['version'] = $value['version'];
        }
        if (array_key_exists('notified_on', $value)) {
            $result['notified_on'] = $value['notified_on'];
        }
        self::$updateNotifyLastNotification = $result;
    }

    // === use_exif_mapping ===
    /**
     * @var array<string,string>
     */
    private const array DEFAULT_USE_EXIF_MAPPING = [
        'date_creation' => 'DateTimeOriginal',
    ];

    /**
     * Mapping of EXIF field names to Piwigo photo attribute names for import.
     * @var array<string,string>
     */
    private static array $useExifMapping = self::DEFAULT_USE_EXIF_MAPPING;

    /**
     * @return array<string,string>
     */
    public static function useExifMapping(): array
    {
        return self::$useExifMapping;
    }

    /**
     * @param array<mixed> $value
     */
    public static function setUseExifMapping(array $value): void
    {
        $result = [];
        foreach ($value as $k => $val) {
            $result[(string) $k] = is_scalar($val) ? (string) $val : '';
        }
        self::$useExifMapping = $result;
    }

    // === use_iptc_mapping ===
    /**
     * @var array<string,string>
     */
    private const array DEFAULT_USE_IPTC_MAPPING = [
        'keywords' => '2#025',
        'date_creation' => '2#055',
        'author' => '2#122',
        'name' => '2#005',
        'comment' => '2#120',
    ];

    /**
     * Mapping of IPTC field codes to Piwigo photo attribute names for import.
     * @var array<string,string>
     */
    private static array $useIptcMapping = self::DEFAULT_USE_IPTC_MAPPING;

    /**
     * @return array<string,string>
     */
    public static function useIptcMapping(): array
    {
        return self::$useIptcMapping;
    }

    /**
     * @param array<mixed> $value
     */
    public static function setUseIptcMapping(array $value): void
    {
        $result = [];
        foreach ($value as $k => $val) {
            $result[(string) $k] = is_scalar($val) ? (string) $val : '';
        }
        self::$useIptcMapping = $result;
    }

    // === user_fields ===
    /**
     * Simplified from the reference's typed UserFieldsMap return -- no such
     * VO exists in this codebase's Piwigo\Users namespace; returns the same
     * column-name mapping as a plain array instead.
     * @var array{id: string, username: string, password: string, email: string}
     */
    private static array $userFields = [
        'id' => 'id',
        'username' => 'username',
        'password' => 'password',
        'email' => 'mail_address',
    ];

    /**
     * @return array{id: string, username: string, password: string, email: string}
     */
    public static function userFields(): array
    {
        return self::$userFields;
    }

    /**
     * @param array<mixed> $value
     */
    public static function setUserFields(array $value): void
    {
        self::$userFields = [
            'id' => isset($value['id']) && is_scalar($value['id']) ? (string) $value['id'] : 'id',
            'username' => isset($value['username']) && is_scalar($value['username']) ? (string) $value['username'] : 'username',
            'password' => isset($value['password']) && is_scalar($value['password']) ? (string) $value['password'] : 'password',
            'email' => isset($value['email']) && is_scalar($value['email']) ? (string) $value['email'] : 'mail_address',
        ];
    }

    // ---- Composed accessors (no config key of their own) ---------------

    /**
     * Composed accessors replacing 3 of the 52 retired `define()` constants
     * (PHPWG_THEMES_PATH/PWG_COMBINED_DIR/PWG_DERIVATIVE_DIR) -- not real
     * config keys, just the same composition `include/constants.php` used
     * to do at boot time (`$themesDir . '/'`, `$dataLocation . 'combined/'`,
     * `$dataLocation . 'i/'`).
     */
    public static function themesPath(): string
    {
        return self::themesDir() . '/';
    }

    public static function combinedDir(): string
    {
        return self::dataLocation() . 'combined/';
    }

    public static function derivativeDir(): string
    {
        return self::dataLocation() . 'i/';
    }

    // ---- Bulk / test / legacy-bridge helpers -----------------------------

    /**
     * Every property, keyed by property name -- reflection-based, replacing
     * the former SCHEMA-driven all(). Sensitive-flagged properties are
     * redacted. Private: the only remaining real caller is dumpForLog()
     * itself; every other former all()['key'] read site had a real typed
     * getter or moved to Piwigo\Db\DbCredentials/Piwigo\Config\
     * DeploymentPolicy (Config generic-accessor removal).
     *
     * @return array<string, mixed>
     */
    private static function all(): array
    {
        $out = [];
        $reflection = new \ReflectionClass(self::class);
        foreach ($reflection->getProperties(\ReflectionProperty::IS_STATIC | \ReflectionProperty::IS_PRIVATE) as $property) {
            $value = $property->getValue();
            $out[$property->getName()] = $property->getAttributes(Sensitive::class) !== [] ? str_repeat('*', 8) : $value;
        }

        return $out;
    }

    /**
     * Return every property with sensitive ones redacted. Intended for
     * safe use in error-handler logs and diagnostic output.
     *
     * @return array<string,mixed>
     */
    public static function dumpForLog(): array
    {
        return self::all();
    }

    /**
     * A small, explicit snapshot of the handful of legacy config defaults
     * still needed before a real `config` table exists to load from --
     * Controller\Admin\ConfigurationSubController::orderByIsLocal() builds
     * a bare `$conf` array from this, then overlays a site's
     * local/config/config.inc.php on top. NOT a general mechanism: these
     * are exactly the keys that real caller reads (confirmed by grep, not
     * assumed), matching the former defaultsArray()'s own real output
     * exactly (which only ever covered non-'custom', non-null-default
     * SCHEMA entries -- every 'custom' key's real caller already has its
     * own inline fallback regardless, unaffected either way).
     *
     * @return array<string, mixed>
     */
    public static function defaultsArray(): array
    {
        return [
            'data_location' => '_data/',
            'default_user_id' => 2,
            'guest_id' => 2,
            'rate' => true,
            'rate_anonymous' => true,
            'webmaster_id' => 1,
        ];
    }

    /**
     * Test-only -- restricted to tests/ by an arch test, mirroring the
     * equivalent guard on Kernel's and ShutdownHandler's own
     * test-isolation reset methods. Restores every property to its own
     * declared default, reflectively -- replaces the former
     * `self::$data = [];` (trivial when everything read through one
     * untyped bag; every property now needs its own reset).
     */
    public static function reset(): void
    {
        $reflection = new \ReflectionClass(self::class);
        foreach ($reflection->getProperties(\ReflectionProperty::IS_STATIC | \ReflectionProperty::IS_PRIVATE) as $property) {
            $property->setValue(null, $property->getDefaultValue());
        }
    }
}
