<?php

declare(strict_types=1);

namespace Piwigo\Config;

use Piwigo\Core\AppInfo;
use Piwigo\Core\Env;
use ReflectionClass;
use ReflectionProperty;

/**
 * Typed facade over Piwigo's runtime configuration. Every real config key is
 * a private typed property with a named getter/setter pair; there is no
 * generic string-keyed surface (no override()/has()/delete()/loadArray()).
 * DB-backed persistence is a two-part split: this class is the typed
 * read/in-memory-write layer; Piwigo\Config\ConfigService is the DI/
 * Doctrine-backed persistence layer, constructor-injected where possible
 * and reached via Piwigo\Config\CurrentConfigService::get() everywhere else.
 *
 * This is a container-shared instance. Callers take it via constructor
 * injection, or as an explicit param at call sites with no constructor of
 * their own (NOCTOR).
 *
 * DB credentials (db_host/db_port/db_driver/db_base/db_user/db_password/
 * db_prefix) live in Piwigo\Db\DbCredentials (env-only). The handful of
 * sysadmin-lockable settings (show_php_errors/show_php_errors_on_frontend/
 * apache_authentication/external_authentification/allowed_hosts) live in
 * Piwigo\Config\DeploymentPolicy (file-only). Neither set of keys is a
 * property on this class, so there is no ambiguity over which source wins
 * for them.
 *
 * Adding a new key: add a property + getter + setter by hand (matching
 * the shape of any neighboring one) -- there is no schema/generator to
 * run. loadConfFromDb() discovers every property reflectively, so a new
 * property is picked up automatically as long as its name matches a real
 * `config` table row.
 */
final class CurrentConfig
{
    // === activate_comments ===
    /**
     * Enable or disable user comments on photos gallery-wide.
     */
    private bool $activateComments = true;

    public function activateComments(): bool
    {
        return $this->activateComments;
    }

    public function setActivateComments(bool $value): void
    {
        $this->activateComments = $value;
    }

    // === activity_display_connections ===
    /**
     * Which connection events to show in the activity log: all, admin, or none.
     */
    private string $activityDisplayConnections = 'all';

    public function activityDisplayConnections(): string
    {
        return $this->activityDisplayConnections;
    }

    public function setActivityDisplayConnections(string $value): void
    {
        $this->activityDisplayConnections = $value;
    }

    // === add_cache_to_storage_chart ===
    /**
     * Include cache files in the storage usage chart on the dashboard.
     */
    private bool $addCacheToStorageChart = true;

    public function addCacheToStorageChart(): bool
    {
        return $this->addCacheToStorageChart;
    }

    public function setAddCacheToStorageChart(bool $value): void
    {
        $this->addCacheToStorageChart = $value;
    }

    // === admin_theme ===
    /**
     * Site-wide fallback admin theme (clear, default, or roma) used when a user
     * has no admin_theme preference of their own yet.
     */
    private string $adminTheme = 'clear';

    public function adminTheme(): string
    {
        return $this->adminTheme;
    }

    public function setAdminTheme(string $value): void
    {
        $this->adminTheme = $value;
    }

    // === album_description_on_all_pages ===
    /**
     * Show the album description on every paginated page, not just the first.
     */
    private bool $albumDescriptionOnAllPages = false;

    public function albumDescriptionOnAllPages(): bool
    {
        return $this->albumDescriptionOnAllPages;
    }

    public function setAlbumDescriptionOnAllPages(bool $value): void
    {
        $this->albumDescriptionOnAllPages = $value;
    }

    // === album_move_delay_before_auto_opening ===
    /**
     * Milliseconds to wait before auto-expanding an album drop-target during
     * drag-and-drop.
     */
    private int $albumMoveDelayBeforeAutoOpening = 3000;

    public function albumMoveDelayBeforeAutoOpening(): int
    {
        return $this->albumMoveDelayBeforeAutoOpening;
    }

    public function setAlbumMoveDelayBeforeAutoOpening(int $value): void
    {
        $this->albumMoveDelayBeforeAutoOpening = $value;
    }

    // === allow_html_descriptions ===
    /**
     * Allow HTML markup in photo and album descriptions.
     */
    private bool $allowHtmlDescriptions = true;

    public function allowHtmlDescriptions(): bool
    {
        return $this->allowHtmlDescriptions;
    }

    public function setAllowHtmlDescriptions(bool $value): void
    {
        $this->allowHtmlDescriptions = $value;
    }

    // === allow_html_in_metadata ===
    /**
     * Allow HTML in metadata values extracted from photo files.
     */
    private bool $allowHtmlInMetadata = false;

    public function allowHtmlInMetadata(): bool
    {
        return $this->allowHtmlInMetadata;
    }

    public function setAllowHtmlInMetadata(bool $value): void
    {
        $this->allowHtmlInMetadata = $value;
    }

    // === allow_random_representative ===
    /**
     * Allow a random photo to represent an album that has no explicit
     * representative set.
     */
    private bool $allowRandomRepresentative = false;

    public function allowRandomRepresentative(): bool
    {
        return $this->allowRandomRepresentative;
    }

    public function setAllowRandomRepresentative(bool $value): void
    {
        $this->allowRandomRepresentative = $value;
    }

    // === allow_user_customization ===
    /**
     * Let registered users change their own display preferences.
     */
    private bool $allowUserCustomization = true;

    public function allowUserCustomization(): bool
    {
        return $this->allowUserCustomization;
    }

    public function setAllowUserCustomization(bool $value): void
    {
        $this->allowUserCustomization = $value;
    }

    // === allow_user_registration ===
    /**
     * Allow new users to self-register from the public gallery.
     */
    private bool $allowUserRegistration = true;

    public function allowUserRegistration(): bool
    {
        return $this->allowUserRegistration;
    }

    public function setAllowUserRegistration(bool $value): void
    {
        $this->allowUserRegistration = $value;
    }

    // === allow_web_services ===
    /**
     * Enable the Piwigo web-service (API) endpoint.
     */
    private bool $allowWebServices = true;

    public function allowWebServices(): bool
    {
        return $this->allowWebServices;
    }

    public function setAllowWebServices(bool $value): void
    {
        $this->allowWebServices = $value;
    }

    // === alternative_pem_url ===
    /**
     * Override URL for the Piwigo Extensions Manager repository.
     */
    private string $alternativePemUrl = '';

    public function alternativePemUrl(): string
    {
        return $this->alternativePemUrl;
    }

    public function setAlternativePemUrl(string $value): void
    {
        $this->alternativePemUrl = $value;
    }

    // === animated_webp_compression_quality ===
    /**
     * Quality level (1-100) for animated WebP derivative encoding.
     */
    private int $animatedWebpCompressionQuality = 70;

    public function animatedWebpCompressionQuality(): int
    {
        return $this->animatedWebpCompressionQuality;
    }

    public function setAnimatedWebpCompressionQuality(int $value): void
    {
        $this->animatedWebpCompressionQuality = $value;
    }

    // === anti-flood_time ===
    /**
     * Minimum seconds between comment posts from the same user to prevent spam.
     */
    private int $antiFloodTime = 60;

    public function antiFloodTime(): int
    {
        return $this->antiFloodTime;
    }

    public function setAntiFloodTime(int $value): void
    {
        $this->antiFloodTime = $value;
    }

    // === auth_key_duration ===
    /**
     * Lifetime in seconds for single-use authentication keys sent in emails.
     */
    private int $authKeyDuration = 259200;

    public function authKeyDuration(): int
    {
        return $this->authKeyDuration;
    }

    public function setAuthKeyDuration(int $value): void
    {
        $this->authKeyDuration = $value;
    }

    // === authorize_remembering ===
    /**
     * Allow users to use the remember-me persistent login cookie.
     */
    private bool $authorizeRemembering = true;

    public function authorizeRemembering(): bool
    {
        return $this->authorizeRemembering;
    }

    public function setAuthorizeRemembering(bool $value): void
    {
        $this->authorizeRemembering = $value;
    }

    // === batch_manager_images_per_page_global ===
    /**
     * Number of photos shown per page in the batch-manager global view.
     */
    private int $batchManagerImagesPerPageGlobal = 20;

    public function batchManagerImagesPerPageGlobal(): int
    {
        return $this->batchManagerImagesPerPageGlobal;
    }

    public function setBatchManagerImagesPerPageGlobal(int $value): void
    {
        $this->batchManagerImagesPerPageGlobal = $value;
    }

    // === batch_manager_images_per_page_unit ===
    /**
     * Number of photos shown per page in the batch-manager unit view.
     */
    private int $batchManagerImagesPerPageUnit = 5;

    public function batchManagerImagesPerPageUnit(): int
    {
        return $this->batchManagerImagesPerPageUnit;
    }

    public function setBatchManagerImagesPerPageUnit(int $value): void
    {
        $this->batchManagerImagesPerPageUnit = $value;
    }

    // === browser_language ===
    /**
     * Automatically detect and use the visitor browser language preference.
     */
    private bool $browserLanguage = true;

    public function browserLanguage(): bool
    {
        return $this->browserLanguage;
    }

    public function setBrowserLanguage(bool $value): void
    {
        $this->browserLanguage = $value;
    }

    // === cache.backend ===
    /**
     * Cache driver to use: file or redis.
     */
    private string $cacheBackend = 'file';

    public function cacheBackend(): string
    {
        return $this->cacheBackend;
    }

    public function setCacheBackend(string $value): void
    {
        $this->cacheBackend = $value;
    }

    // === cache.default_ttl ===
    /**
     * Default cache entry time-to-live in seconds.
     */
    private int $cacheDefaultTtl = 86400;

    public function cacheDefaultTtl(): int
    {
        return $this->cacheDefaultTtl;
    }

    public function setCacheDefaultTtl(int $value): void
    {
        $this->cacheDefaultTtl = $value;
    }

    // === cache.namespace ===
    /**
     * Namespace prefix for all cache keys, useful when sharing a Redis instance.
     */
    private string $cacheNamespace = '';

    public function cacheNamespace(): string
    {
        return $this->cacheNamespace;
    }

    public function setCacheNamespace(string $value): void
    {
        $this->cacheNamespace = $value;
    }

    // === cache.redis_url ===
    /**
     * Redis connection DSN used when cache.backend is redis.
     */
    private string $cacheRedisUrl = 'redis://localhost:6379';

    public function cacheRedisUrl(): string
    {
        return $this->cacheRedisUrl;
    }

    public function setCacheRedisUrl(string $value): void
    {
        $this->cacheRedisUrl = $value;
    }

    // === calendar_datefield ===
    /**
     * Date field used for the calendar view: date_creation or date_available.
     */
    private string $calendarDatefield = 'date_creation';

    public function calendarDatefield(): string
    {
        return $this->calendarDatefield;
    }

    public function setCalendarDatefield(string $value): void
    {
        $this->calendarDatefield = $value;
    }

    // === calendar_show_any ===
    /**
     * Show an Any link in the calendar so visitors can view photos without a date
     * filter.
     */
    private bool $calendarShowAny = true;

    public function calendarShowAny(): bool
    {
        return $this->calendarShowAny;
    }

    public function setCalendarShowAny(bool $value): void
    {
        $this->calendarShowAny = $value;
    }

    // === calendar_show_empty ===
    /**
     * Show months and years with no photos in the calendar navigation.
     */
    private bool $calendarShowEmpty = true;

    public function calendarShowEmpty(): bool
    {
        return $this->calendarShowEmpty;
    }

    public function setCalendarShowEmpty(bool $value): void
    {
        $this->calendarShowEmpty = $value;
    }

    // === category_url_style ===
    /**
     * URL format for album links: id or id-name.
     */
    private string $categoryUrlStyle = 'id';

    public function categoryUrlStyle(): string
    {
        return $this->categoryUrlStyle;
    }

    public function setCategoryUrlStyle(string $value): void
    {
        $this->categoryUrlStyle = $value;
    }

    // === checksum_compute_blocksize ===
    /**
     * Number of photos per block when computing file checksums in batch.
     */
    private int $checksumComputeBlocksize = 50;

    public function checksumComputeBlocksize(): int
    {
        return $this->checksumComputeBlocksize;
    }

    public function setChecksumComputeBlocksize(int $value): void
    {
        $this->checksumComputeBlocksize = $value;
    }

    // === comment_spam_max_links ===
    /**
     * Maximum number of links allowed in a single comment before it is rejected as
     * spam.
     */
    private int $commentSpamMaxLinks = 3;

    public function commentSpamMaxLinks(): int
    {
        return $this->commentSpamMaxLinks;
    }

    public function setCommentSpamMaxLinks(int $value): void
    {
        $this->commentSpamMaxLinks = $value;
    }

    // === comment_spam_reject ===
    /**
     * Silently reject comments that exceed the spam link threshold.
     */
    private bool $commentSpamReject = true;

    public function commentSpamReject(): bool
    {
        return $this->commentSpamReject;
    }

    public function setCommentSpamReject(bool $value): void
    {
        $this->commentSpamReject = $value;
    }

    // === comments_author_mandatory ===
    /**
     * Require commenters to supply an author name.
     */
    private bool $commentsAuthorMandatory = false;

    public function commentsAuthorMandatory(): bool
    {
        return $this->commentsAuthorMandatory;
    }

    public function setCommentsAuthorMandatory(bool $value): void
    {
        $this->commentsAuthorMandatory = $value;
    }

    // === comments_email_mandatory ===
    /**
     * Require commenters to supply an email address.
     */
    private bool $commentsEmailMandatory = false;

    public function commentsEmailMandatory(): bool
    {
        return $this->commentsEmailMandatory;
    }

    public function setCommentsEmailMandatory(bool $value): void
    {
        $this->commentsEmailMandatory = $value;
    }

    // === comments_enable_website ===
    /**
     * Show a website field in the comment form.
     */
    private bool $commentsEnableWebsite = true;

    public function commentsEnableWebsite(): bool
    {
        return $this->commentsEnableWebsite;
    }

    public function setCommentsEnableWebsite(bool $value): void
    {
        $this->commentsEnableWebsite = $value;
    }

    // === comments_forall ===
    /**
     * Allow unauthenticated (guest) visitors to post comments.
     */
    private bool $commentsForall = false;

    public function commentsForall(): bool
    {
        return $this->commentsForall;
    }

    public function setCommentsForall(bool $value): void
    {
        $this->commentsForall = $value;
    }

    // === comments_order ===
    /**
     * Sort order for comment display: ASC (oldest first) or DESC (newest first).
     */
    private string $commentsOrder = 'ASC';

    public function commentsOrder(): string
    {
        return $this->commentsOrder;
    }

    public function setCommentsOrder(string $value): void
    {
        $this->commentsOrder = $value;
    }

    // === comments_page_nb_comments ===
    /**
     * Number of comments shown per page on the admin comments page.
     */
    private int $commentsPageNbComments = 10;

    public function commentsPageNbComments(): int
    {
        return $this->commentsPageNbComments;
    }

    public function setCommentsPageNbComments(int $value): void
    {
        $this->commentsPageNbComments = $value;
    }

    // === comments_validation ===
    /**
     * Require admin approval before newly posted comments appear publicly.
     */
    private bool $commentsValidation = false;

    public function commentsValidation(): bool
    {
        return $this->commentsValidation;
    }

    public function setCommentsValidation(bool $value): void
    {
        $this->commentsValidation = $value;
    }

    // === compiled_template_cache_language ===
    /**
     * Include the active language in the compiled-template cache key.
     */
    private bool $compiledTemplateCacheLanguage = false;

    public function compiledTemplateCacheLanguage(): bool
    {
        return $this->compiledTemplateCacheLanguage;
    }

    public function setCompiledTemplateCacheLanguage(bool $value): void
    {
        $this->compiledTemplateCacheLanguage = $value;
    }

    // === content_tag_cloud_items_number ===
    /**
     * Maximum number of tags shown in the content-area tag cloud.
     */
    private int $contentTagCloudItemsNumber = 12;

    public function contentTagCloudItemsNumber(): int
    {
        return $this->contentTagCloudItemsNumber;
    }

    public function setContentTagCloudItemsNumber(int $value): void
    {
        $this->contentTagCloudItemsNumber = $value;
    }

    // === count_orphans ===
    /**
     * Cached count of images belonging to no album; null means "not computed
     * yet" (recomputed lazily by ImageService::countOrphans(), invalidated by
     * PermissionCacheInvalidator).
     */
    private ?int $countOrphans = null;

    public function countOrphans(): ?int
    {
        return $this->countOrphans;
    }

    public function setCountOrphans(?int $value): void
    {
        $this->countOrphans = $value;
    }

    // === dashboard_activity_nb_weeks ===
    /**
     * Number of weeks of activity data shown on the admin dashboard.
     */
    private int $dashboardActivityNbWeeks = 4;

    public function dashboardActivityNbWeeks(): int
    {
        return $this->dashboardActivityNbWeeks;
    }

    public function setDashboardActivityNbWeeks(int $value): void
    {
        $this->dashboardActivityNbWeeks = $value;
    }

    // === dashboard_check_for_updates ===
    /**
     * Check for Piwigo core updates on the admin dashboard.
     */
    private bool $dashboardCheckForUpdates = true;

    public function dashboardCheckForUpdates(): bool
    {
        return $this->dashboardCheckForUpdates;
    }

    public function setDashboardCheckForUpdates(bool $value): void
    {
        $this->dashboardCheckForUpdates = $value;
    }

    // === data_dir_checked ===
    /**
     * Presence-only marker: once set (to '1'), Template's data-directory
     * writability check is permanently skipped. Genuine absence until the check
     * first passes, matching the gallery_url/last_major_update convention.
     */
    private ?string $dataDirChecked = null;

    public function dataDirChecked(): ?string
    {
        return $this->dataDirChecked;
    }

    public function setDataDirChecked(?string $value): void
    {
        $this->dataDirChecked = $value;
    }

    // === data_location ===
    /**
     * Relative path from the Piwigo root to the writable data directory.
     */
    private string $dataLocation = '_data/';

    public function dataLocation(): string
    {
        return $this->dataLocation;
    }

    public function setDataLocation(string $value): void
    {
        $this->dataLocation = $value;
    }

    // === debug_l10n ===
    /**
     * Highlight untranslated strings in the UI for l10n debugging.
     */
    private bool $debugL10n = false;

    public function debugL10n(): bool
    {
        return $this->debugL10n;
    }

    public function setDebugL10n(bool $value): void
    {
        $this->debugL10n = $value;
    }

    // === debug_mail ===
    /**
     * Log all outgoing mail to a file instead of sending.
     */
    private bool $debugMail = false;

    public function debugMail(): bool
    {
        return $this->debugMail;
    }

    public function setDebugMail(bool $value): void
    {
        $this->debugMail = $value;
    }

    // === debug_template ===
    /**
     * Add template debugging information to rendered pages.
     */
    private bool $debugTemplate = false;

    public function debugTemplate(): bool
    {
        return $this->debugTemplate;
    }

    public function setDebugTemplate(bool $value): void
    {
        $this->debugTemplate = $value;
    }

    // === default_redirect_method ===
    /**
     * HTTP redirect method Piwigo uses internally: http or html.
     */
    private string $defaultRedirectMethod = 'http';

    public function defaultRedirectMethod(): string
    {
        return $this->defaultRedirectMethod;
    }

    public function setDefaultRedirectMethod(string $value): void
    {
        $this->defaultRedirectMethod = $value;
    }

    // === default_user_id ===
    /**
     * User ID whose settings serve as defaults for new accounts.
     */
    private int $defaultUserId = 2;

    public function defaultUserId(): int
    {
        return $this->defaultUserId;
    }

    public function setDefaultUserId(int $value): void
    {
        $this->defaultUserId = $value;
    }

    // === derivative_default_size ===
    /**
     * Default derivative size name served when no size is specified.
     */
    private string $derivativeDefaultSize = 'medium';

    public function derivativeDefaultSize(): string
    {
        return $this->derivativeDefaultSize;
    }

    public function setDerivativeDefaultSize(string $value): void
    {
        $this->derivativeDefaultSize = $value;
    }

    // === derivative_url_style ===
    /**
     * Derivative URL format: 0 = auto (static link if already cached, else routed
     * through i.php), 1 = always a static link, 2 = always routed through i.php.
     */
    private int $derivativeUrlStyle = 2;

    public function derivativeUrlStyle(): int
    {
        return $this->derivativeUrlStyle;
    }

    public function setDerivativeUrlStyle(int $value): void
    {
        $this->derivativeUrlStyle = $value;
    }

    // === derivatives_strip_metadata_threshold ===
    /**
     * File size in bytes above which EXIF/IPTC metadata is stripped from
     * derivatives.
     */
    private int $derivativesStripMetadataThreshold = 256000;

    public function derivativesStripMetadataThreshold(): int
    {
        return $this->derivativesStripMetadataThreshold;
    }

    public function setDerivativesStripMetadataThreshold(int $value): void
    {
        $this->derivativesStripMetadataThreshold = $value;
    }

    // === die_on_sql_error ===
    /**
     * Halt execution immediately when a database query fails.
     */
    private bool $dieOnSqlError = false;

    public function dieOnSqlError(): bool
    {
        return $this->dieOnSqlError;
    }

    public function setDieOnSqlError(bool $value): void
    {
        $this->dieOnSqlError = $value;
    }

    // === display_fromto ===
    /**
     * Show the date range of photos in album and search results headers.
     */
    private bool $displayFromto = false;

    public function displayFromto(): bool
    {
        return $this->displayFromto;
    }

    public function setDisplayFromto(bool $value): void
    {
        $this->displayFromto = $value;
    }

    // === double_password_type_in_admin ===
    /**
     * Require admins to enter a new password twice when setting it.
     */
    private bool $doublePasswordTypeInAdmin = false;

    public function doublePasswordTypeInAdmin(): bool
    {
        return $this->doublePasswordTypeInAdmin;
    }

    public function setDoublePasswordTypeInAdmin(bool $value): void
    {
        $this->doublePasswordTypeInAdmin = $value;
    }

    // === email_admin_on_comment ===
    /**
     * Send an email to the administrators when a valid comment is entered.
     */
    private bool $emailAdminOnComment = false;

    public function emailAdminOnComment(): bool
    {
        return $this->emailAdminOnComment;
    }

    public function setEmailAdminOnComment(bool $value): void
    {
        $this->emailAdminOnComment = $value;
    }

    // === email_admin_on_comment_deletion ===
    /**
     * Send an email to the administrators when a comment is deleted.
     */
    private bool $emailAdminOnCommentDeletion = false;

    public function emailAdminOnCommentDeletion(): bool
    {
        return $this->emailAdminOnCommentDeletion;
    }

    public function setEmailAdminOnCommentDeletion(bool $value): void
    {
        $this->emailAdminOnCommentDeletion = $value;
    }

    // === email_admin_on_comment_edition ===
    /**
     * Send an email to the administrators when a comment is modified.
     */
    private bool $emailAdminOnCommentEdition = false;

    public function emailAdminOnCommentEdition(): bool
    {
        return $this->emailAdminOnCommentEdition;
    }

    public function setEmailAdminOnCommentEdition(bool $value): void
    {
        $this->emailAdminOnCommentEdition = $value;
    }

    // === email_admin_on_comment_validation ===
    /**
     * Send an email to the administrators when a comment requires validation.
     */
    private bool $emailAdminOnCommentValidation = true;

    public function emailAdminOnCommentValidation(): bool
    {
        return $this->emailAdminOnCommentValidation;
    }

    public function setEmailAdminOnCommentValidation(bool $value): void
    {
        $this->emailAdminOnCommentValidation = $value;
    }

    // === email_admin_on_new_user ===
    /**
     * When to email the webmaster when a new user registers: none, all, or new.
     */
    private string $emailAdminOnNewUser = 'none';

    public function emailAdminOnNewUser(): string
    {
        return $this->emailAdminOnNewUser;
    }

    public function setEmailAdminOnNewUser(string $value): void
    {
        $this->emailAdminOnNewUser = $value;
    }

    // === enable_core_update ===
    /**
     * Allow Piwigo core to be updated from the administration panel.
     */
    private bool $enableCoreUpdate = true;

    public function enableCoreUpdate(): bool
    {
        return $this->enableCoreUpdate;
    }

    public function setEnableCoreUpdate(bool $value): void
    {
        $this->enableCoreUpdate = $value;
    }

    // === enable_extensions_install ===
    /**
     * Allow plugins and themes to be installed from the administration panel.
     */
    private bool $enableExtensionsInstall = true;

    public function enableExtensionsInstall(): bool
    {
        return $this->enableExtensionsInstall;
    }

    public function setEnableExtensionsInstall(bool $value): void
    {
        $this->enableExtensionsInstall = $value;
    }

    // === enable_formats ===
    /**
     * Enable the multi-format photo feature (original plus additional formats).
     */
    private bool $isFormatsEnabled = false;

    public function isFormatsEnabled(): bool
    {
        return $this->isFormatsEnabled;
    }

    public function setIsFormatsEnabled(bool $value): void
    {
        $this->isFormatsEnabled = $value;
    }

    // === enable_plugins ===
    /**
     * Load and activate installed plugins.
     */
    private bool $enablePlugins = true;

    public function enablePlugins(): bool
    {
        return $this->enablePlugins;
    }

    public function setEnablePlugins(bool $value): void
    {
        $this->enablePlugins = $value;
    }

    // === enable_synchronization ===
    /**
     * Allow directory-to-database synchronization from the admin panel.
     */
    private bool $enableSynchronization = true;

    public function enableSynchronization(): bool
    {
        return $this->enableSynchronization;
    }

    public function setEnableSynchronization(bool $value): void
    {
        $this->enableSynchronization = $value;
    }

    // === ext_imagick_dir ===
    /**
     * Filesystem path to the ImageMagick binary directory (leave empty to
     * auto-detect).
     */
    private string $extImagickDir = '';

    public function extImagickDir(): string
    {
        return $this->extImagickDir;
    }

    public function setExtImagickDir(string $value): void
    {
        $this->extImagickDir = $value;
    }

    // === ffmpeg_dir ===
    /**
     * Filesystem path to the FFmpeg binary directory (leave empty to auto-detect).
     */
    private string $ffmpegDir = '';

    public function ffmpegDir(): string
    {
        return $this->ffmpegDir;
    }

    public function setFfmpegDir(string $value): void
    {
        $this->ffmpegDir = $value;
    }

    // === fs_quick_check_last_check ===
    /**
     * Timestamp of the last filesystem quick-check run.
     */
    private ?string $fsQuickCheckLastCheck = null;

    public function fsQuickCheckLastCheck(): ?string
    {
        return $this->fsQuickCheckLastCheck;
    }

    public function setFsQuickCheckLastCheck(?string $value): void
    {
        $this->fsQuickCheckLastCheck = $value;
    }

    // === fs_quick_check_period ===
    /**
     * Interval in seconds between automatic filesystem quick-checks.
     */
    private int $fsQuickCheckPeriod = 86400;

    public function fsQuickCheckPeriod(): int
    {
        return $this->fsQuickCheckPeriod;
    }

    public function setFsQuickCheckPeriod(int $value): void
    {
        $this->fsQuickCheckPeriod = $value;
    }

    // === full_tag_cloud_items_number ===
    /**
     * Maximum number of tags shown on the full tag-cloud page.
     */
    private int $fullTagCloudItemsNumber = 200;

    public function fullTagCloudItemsNumber(): int
    {
        return $this->fullTagCloudItemsNumber;
    }

    public function setFullTagCloudItemsNumber(int $value): void
    {
        $this->fullTagCloudItemsNumber = $value;
    }

    // === gallery_locked ===
    /**
     * Lock the gallery for maintenance, blocking non-admin access.
     */
    private bool $galleryLocked = false;

    public function galleryLocked(): bool
    {
        return $this->galleryLocked;
    }

    public function setGalleryLocked(bool $value): void
    {
        $this->galleryLocked = $value;
    }

    // === gallery_title ===
    /**
     * Title of the gallery shown in the browser tab and page header.
     */
    private string $galleryTitle = 'Piwigo';

    public function galleryTitle(): string
    {
        return $this->galleryTitle;
    }

    public function setGalleryTitle(string $value): void
    {
        $this->galleryTitle = $value;
    }

    // === gallery_url ===
    /**
     * Public base URL of the gallery (overrides auto-detection when set).
     */
    private ?string $galleryUrl = null;

    public function galleryUrl(): ?string
    {
        return $this->galleryUrl;
    }

    public function setGalleryUrl(?string $value): void
    {
        $this->galleryUrl = $value;
    }

    // === graphics_library ===
    /**
     * Image processing backend: auto, gd, imagick, or ext_imagick.
     */
    private string $graphicsLibrary = 'auto';

    public function graphicsLibrary(): string
    {
        return $this->graphicsLibrary;
    }

    public function setGraphicsLibrary(string $value): void
    {
        $this->graphicsLibrary = $value;
    }

    // === guest_access ===
    /**
     * Allow unauthenticated (guest) visitors to browse public photos.
     */
    private bool $guestAccess = true;

    public function guestAccess(): bool
    {
        return $this->guestAccess;
    }

    public function setGuestAccess(bool $value): void
    {
        $this->guestAccess = $value;
    }

    // === guest_id ===
    /**
     * User ID of the built-in guest account used for unauthenticated sessions.
     */
    private int $guestId = 2;

    public function guestId(): int
    {
        return $this->guestId;
    }

    public function setGuestId(int $value): void
    {
        $this->guestId = $value;
    }

    // === history_admin ===
    /**
     * Log page visits by admin users in the history table.
     */
    private bool $historyAdmin = false;

    public function historyAdmin(): bool
    {
        return $this->historyAdmin;
    }

    public function setHistoryAdmin(bool $value): void
    {
        $this->historyAdmin = $value;
    }

    // === history_autopurge_blocksize ===
    /**
     * Number of rows deleted per autopurge cycle from the history table.
     */
    private int $historyAutopurgeBlocksize = 50000;

    public function historyAutopurgeBlocksize(): int
    {
        return $this->historyAutopurgeBlocksize;
    }

    public function setHistoryAutopurgeBlocksize(int $value): void
    {
        $this->historyAutopurgeBlocksize = $value;
    }

    // === history_autopurge_every ===
    /**
     * Autopurge frequency: delete old history every N page loads (approximately).
     */
    private int $historyAutopurgeEvery = 1021;

    public function historyAutopurgeEvery(): int
    {
        return $this->historyAutopurgeEvery;
    }

    public function setHistoryAutopurgeEvery(int $value): void
    {
        $this->historyAutopurgeEvery = $value;
    }

    // === history_autopurge_keep_lines ===
    /**
     * Maximum number of history rows to retain after an autopurge.
     */
    private int $historyAutopurgeKeepLines = 1000000;

    public function historyAutopurgeKeepLines(): int
    {
        return $this->historyAutopurgeKeepLines;
    }

    public function setHistoryAutopurgeKeepLines(int $value): void
    {
        $this->historyAutopurgeKeepLines = $value;
    }

    // === history_guest ===
    /**
     * Log page visits by guest (unauthenticated) users in the history table.
     */
    private bool $historyGuest = false;

    public function historyGuest(): bool
    {
        return $this->historyGuest;
    }

    public function setHistoryGuest(bool $value): void
    {
        $this->historyGuest = $value;
    }

    // === index_caddie_icon ===
    /**
     * Show the add-to-caddie icon on album index pages.
     */
    private bool $indexCaddieIcon = true;

    public function indexCaddieIcon(): bool
    {
        return $this->indexCaddieIcon;
    }

    public function setIndexCaddieIcon(bool $value): void
    {
        $this->indexCaddieIcon = $value;
    }

    // === index_created_date_icon ===
    /**
     * Show the creation-date icon on album index pages.
     */
    private bool $indexCreatedDateIcon = true;

    public function indexCreatedDateIcon(): bool
    {
        return $this->indexCreatedDateIcon;
    }

    public function setIndexCreatedDateIcon(bool $value): void
    {
        $this->indexCreatedDateIcon = $value;
    }

    // === index_edit_icon ===
    /**
     * Show the quick-edit icon on album index pages (admins only).
     */
    private bool $indexEditIcon = true;

    public function indexEditIcon(): bool
    {
        return $this->indexEditIcon;
    }

    public function setIndexEditIcon(bool $value): void
    {
        $this->indexEditIcon = $value;
    }

    // === index_flat_icon ===
    /**
     * Show the flat-view icon on album index pages.
     */
    private bool $indexFlatIcon = true;

    public function indexFlatIcon(): bool
    {
        return $this->indexFlatIcon;
    }

    public function setIndexFlatIcon(bool $value): void
    {
        $this->indexFlatIcon = $value;
    }

    // === index_new_icon ===
    /**
     * Show the new badge icon on recently added photos in album index pages.
     */
    private bool $indexNewIcon = true;

    public function indexNewIcon(): bool
    {
        return $this->indexNewIcon;
    }

    public function setIndexNewIcon(bool $value): void
    {
        $this->indexNewIcon = $value;
    }

    // === index_posted_date_icon ===
    /**
     * Show the posted-date icon on album index pages.
     */
    private bool $indexPostedDateIcon = true;

    public function indexPostedDateIcon(): bool
    {
        return $this->indexPostedDateIcon;
    }

    public function setIndexPostedDateIcon(bool $value): void
    {
        $this->indexPostedDateIcon = $value;
    }

    // === index_search_in_set_action ===
    /**
     * Behaviour when searching within the current set: results or filter.
     */
    private string $indexSearchInSetAction = 'results';

    public function indexSearchInSetAction(): string
    {
        return $this->indexSearchInSetAction;
    }

    public function setIndexSearchInSetAction(string $value): void
    {
        $this->indexSearchInSetAction = $value;
    }

    // === index_search_in_set_button ===
    /**
     * Show the search-within-set button on album index pages.
     */
    private bool $indexSearchInSetButton = false;

    public function indexSearchInSetButton(): bool
    {
        return $this->indexSearchInSetButton;
    }

    public function setIndexSearchInSetButton(bool $value): void
    {
        $this->indexSearchInSetButton = $value;
    }

    // === index_sizes_icon ===
    /**
     * Show the available-sizes icon on album index pages.
     */
    private bool $indexSizesIcon = true;

    public function indexSizesIcon(): bool
    {
        return $this->indexSizesIcon;
    }

    public function setIndexSizesIcon(bool $value): void
    {
        $this->indexSizesIcon = $value;
    }

    // === index_slideshow_icon ===
    /**
     * Show the slideshow icon on album index pages.
     */
    private bool $indexSlideShowIcon = true;

    public function indexSlideShowIcon(): bool
    {
        return $this->indexSlideShowIcon;
    }

    public function setIndexSlideShowIcon(bool $value): void
    {
        $this->indexSlideShowIcon = $value;
    }

    // === index_sort_order_input ===
    /**
     * Display the image order selection list on album index pages.
     */
    private bool $indexSortOrderInput = true;

    public function indexSortOrderInput(): bool
    {
        return $this->indexSortOrderInput;
    }

    public function setIndexSortOrderInput(bool $value): void
    {
        $this->indexSortOrderInput = $value;
    }

    // === inheritance_by_default ===
    /**
     * Apply parent album permissions to newly created sub-albums by default.
     */
    private bool $inheritanceByDefault = false;

    public function inheritanceByDefault(): bool
    {
        return $this->inheritanceByDefault;
    }

    public function setInheritanceByDefault(bool $value): void
    {
        $this->inheritanceByDefault = $value;
    }

    // === insensitive_case_logon ===
    /**
     * Allow login with any letter-case variation of the username.
     */
    private bool $insensitiveCaseLogon = false;

    public function insensitiveCaseLogon(): bool
    {
        return $this->insensitiveCaseLogon;
    }

    public function setInsensitiveCaseLogon(bool $value): void
    {
        $this->insensitiveCaseLogon = $value;
    }

    // === last_major_update ===
    /**
     * Timestamp of the last major Piwigo upgrade, used for change detection.
     */
    private ?string $lastMajorUpdate = null;

    public function lastMajorUpdate(): ?string
    {
        return $this->lastMajorUpdate;
    }

    public function setLastMajorUpdate(?string $value): void
    {
        $this->lastMajorUpdate = $value;
    }

    // === level_separator ===
    /**
     * String used to separate album hierarchy levels in breadcrumb trails.
     */
    private string $levelSeparator = ' / ';

    public function levelSeparator(): string
    {
        return $this->levelSeparator;
    }

    public function setLevelSeparator(string $value): void
    {
        $this->levelSeparator = $value;
    }

    // === light_album_manager_threshold ===
    /**
     * Album count above which the lightweight album manager UI is used.
     */
    private int $lightAlbumManagerThreshold = 10000;

    public function lightAlbumManagerThreshold(): int
    {
        return $this->lightAlbumManagerThreshold;
    }

    public function setLightAlbumManagerThreshold(int $value): void
    {
        $this->lightAlbumManagerThreshold = $value;
    }

    // === light_slideshow ===
    /**
     * Use the lightweight built-in slideshow instead of a plugin-based one.
     */
    private bool $lightSlideshow = true;

    public function lightSlideshow(): bool
    {
        return $this->lightSlideshow;
    }

    public function setLightSlideshow(bool $value): void
    {
        $this->lightSlideshow = $value;
    }

    // === linked_album_search_limit ===
    /**
     * Maximum albums returned when searching for albums to link a photo to.
     */
    private int $linkedAlbumSearchLimit = 100;

    public function linkedAlbumSearchLimit(): int
    {
        return $this->linkedAlbumSearchLimit;
    }

    public function setLinkedAlbumSearchLimit(int $value): void
    {
        $this->linkedAlbumSearchLimit = $value;
    }

    // === log ===
    /**
     * Enable the application log.
     */
    private bool $logConf = false;

    public function logConf(): bool
    {
        return $this->logConf;
    }

    public function setLogConf(bool $value): void
    {
        $this->logConf = $value;
    }

    // === log_archive_days ===
    /**
     * Number of days to keep archived log files before deletion.
     */
    private int $logArchiveDays = 30;

    public function logArchiveDays(): int
    {
        return $this->logArchiveDays;
    }

    public function setLogArchiveDays(int $value): void
    {
        $this->logArchiveDays = $value;
    }

    // === log_dir ===
    /**
     * Directory (relative to the data location) where log files are written.
     */
    private string $logDir = '/logs';

    public function logDir(): string
    {
        return $this->logDir;
    }

    public function setLogDir(string $value): void
    {
        $this->logDir = $value;
    }

    // === log_level ===
    /**
     * Minimum log severity to record: DEBUG, INFO, WARNING, or ERROR.
     */
    private string $logLevel = 'DEBUG';

    public function logLevel(): string
    {
        return $this->logLevel;
    }

    public function setLogLevel(string $value): void
    {
        $this->logLevel = $value;
    }

    // === login_lockout_duration_minutes ===
    /**
     * Minutes a username/IP stays locked out after too many failed logins.
     */
    private int $loginLockoutDurationMinutes = 15;

    public function loginLockoutDurationMinutes(): int
    {
        return $this->loginLockoutDurationMinutes;
    }

    public function setLoginLockoutDurationMinutes(int $value): void
    {
        $this->loginLockoutDurationMinutes = $value;
    }

    // === login_lockout_max_attempts ===
    /**
     * Failed logins allowed (per username, and separately per IP) within
     * the lockout window before AuthService::pwgLogin() starts rejecting
     * outright.
     */
    private int $loginLockoutMaxAttempts = 5;

    public function loginLockoutMaxAttempts(): int
    {
        return $this->loginLockoutMaxAttempts;
    }

    public function setLoginLockoutMaxAttempts(int $value): void
    {
        $this->loginLockoutMaxAttempts = $value;
    }

    // === login_lockout_window_minutes ===
    /**
     * Rolling window, in minutes, over which failed logins are counted
     * towards the lockout threshold.
     */
    private int $loginLockoutWindowMinutes = 15;

    public function loginLockoutWindowMinutes(): int
    {
        return $this->loginLockoutWindowMinutes;
    }

    public function setLoginLockoutWindowMinutes(int $value): void
    {
        $this->loginLockoutWindowMinutes = $value;
    }

    // === lounge_activate_threshold ===
    /**
     * Number of photos in the lounge that triggers automatic album creation.
     */
    private int $loungeActivateThreshold = 1;

    public function loungeActivateThreshold(): int
    {
        return $this->loungeActivateThreshold;
    }

    public function setLoungeActivateThreshold(int $value): void
    {
        $this->loungeActivateThreshold = $value;
    }

    // === lounge_active ===
    /**
     * Enable the lounge feature (a staging area for uploaded photos).
     */
    private bool $loungeActive = false;

    public function loungeActive(): bool
    {
        return $this->loungeActive;
    }

    public function setLoungeActive(bool $value): void
    {
        $this->loungeActive = $value;
    }

    // === lounge_max_duration ===
    /**
     * Maximum seconds a photo can stay in the lounge before auto-processing.
     */
    private int $loungeMaxDuration = 300;

    public function loungeMaxDuration(): int
    {
        return $this->loungeMaxDuration;
    }

    public function setLoungeMaxDuration(int $value): void
    {
        $this->loungeMaxDuration = $value;
    }

    // === mail_allow_html ===
    /**
     * Send emails in HTML format in addition to plain text.
     */
    private bool $mailAllowHtml = true;

    public function mailAllowHtml(): bool
    {
        return $this->mailAllowHtml;
    }

    public function setMailAllowHtml(bool $value): void
    {
        $this->mailAllowHtml = $value;
    }

    // === mail_sender_email ===
    /**
     * From email address used for all outgoing Piwigo emails.
     */
    private string $mailSenderEmail = '';

    public function mailSenderEmail(): string
    {
        return $this->mailSenderEmail;
    }

    public function setMailSenderEmail(string $value): void
    {
        $this->mailSenderEmail = $value;
    }

    // === mail_sender_name ===
    /**
     * Display name shown as the email sender in outgoing Piwigo emails.
     */
    private string $mailSenderName = '';

    public function mailSenderName(): string
    {
        return $this->mailSenderName;
    }

    public function setMailSenderName(string $value): void
    {
        $this->mailSenderName = $value;
    }

    // === mail_theme ===
    /**
     * Visual theme for HTML notification emails: light or dark.
     */
    private string $mailTheme = 'light';

    public function mailTheme(): string
    {
        return $this->mailTheme;
    }

    public function setMailTheme(string $value): void
    {
        $this->mailTheme = $value;
    }

    // === max_requests ===
    /**
     * Maximum concurrent HTTP requests Piwigo will make to external services.
     */
    private int $maxRequests = 3;

    public function maxRequests(): int
    {
        return $this->maxRequests;
    }

    public function setMaxRequests(int $value): void
    {
        $this->maxRequests = $value;
    }

    // === menubar_filter_icon ===
    /**
     * Show the filter icon in the sidebar menu.
     */
    private bool $menubarFilterIcon = true;

    public function menubarFilterIcon(): bool
    {
        return $this->menubarFilterIcon;
    }

    public function setMenubarFilterIcon(bool $value): void
    {
        $this->menubarFilterIcon = $value;
    }

    // === menubar_tag_cloud_content ===
    /**
     * Which tags to show in the sidebar tag cloud: all_or_current or current.
     */
    private string $menubarTagCloudContent = 'all_or_current';

    public function menubarTagCloudContent(): string
    {
        return $this->menubarTagCloudContent;
    }

    public function setMenubarTagCloudContent(string $value): void
    {
        $this->menubarTagCloudContent = $value;
    }

    // === menubar_tag_cloud_items_number ===
    /**
     * Maximum number of tags shown in the sidebar tag cloud.
     */
    private int $menubarTagCloudItemsNumber = 20;

    public function menubarTagCloudItemsNumber(): int
    {
        return $this->menubarTagCloudItemsNumber;
    }

    public function setMenubarTagCloudItemsNumber(int $value): void
    {
        $this->menubarTagCloudItemsNumber = $value;
    }

    // === meta_ref ===
    /**
     * Emit a referrer meta tag allowing search engines to attribute traffic.
     */
    private bool $metaRef = true;

    public function metaRef(): bool
    {
        return $this->metaRef;
    }

    public function setMetaRef(bool $value): void
    {
        $this->metaRef = $value;
    }

    // === mobile_theme ===
    /**
     * Theme name applied automatically when a mobile browser is detected.
     */
    private string $mobilTheme = '';

    public function mobilTheme(): string
    {
        return $this->mobilTheme;
    }

    public function setMobilTheme(string $value): void
    {
        $this->mobilTheme = $value;
    }

    // === nb_categories_page ===
    /**
     * Maximum albums shown per page in admin album listings.
     */
    private int $nbCategoriesPage = 9999;

    public function nbCategoriesPage(): int
    {
        return $this->nbCategoriesPage;
    }

    public function setNbCategoriesPage(int $value): void
    {
        $this->nbCategoriesPage = $value;
    }

    // === nb_comment_page ===
    /**
     * Number of comments per page on the public photo detail page.
     */
    private int $nbCommentPage = 10;

    public function nbCommentPage(): int
    {
        return $this->nbCommentPage;
    }

    public function setNbCommentPage(int $value): void
    {
        $this->nbCommentPage = $value;
    }

    // === nb_logs_page ===
    /**
     * Number of history entries shown per page in the admin history view.
     */
    private int $nbLogsPage = 300;

    public function nbLogsPage(): int
    {
        return $this->nbLogsPage;
    }

    public function setNbLogsPage(int $value): void
    {
        $this->nbLogsPage = $value;
    }

    // === nbm_complementary_mail_content ===
    /**
     * Extra HTML appended to notification-by-mail digest emails.
     */
    private string $nbmComplementaryMailContent = '';

    public function nbmComplementaryMailContent(): string
    {
        return $this->nbmComplementaryMailContent;
    }

    public function setNbmComplementaryMailContent(string $value): void
    {
        $this->nbmComplementaryMailContent = $value;
    }

    // === nbm_default_value_user_enabled ===
    /**
     * Subscribe new users to notification-by-mail digests by default.
     */
    private bool $nbmDefaultValueUserEnabled = false;

    public function nbmDefaultValueUserEnabled(): bool
    {
        return $this->nbmDefaultValueUserEnabled;
    }

    public function setNbmDefaultValueUserEnabled(bool $value): void
    {
        $this->nbmDefaultValueUserEnabled = $value;
    }

    // === nbm_list_all_enabled_users_to_send ===
    /**
     * Show all subscribed users in the NBM send UI, not just those with pending
     * notifications.
     */
    private bool $nbmListAllEnabledUsersToSend = false;

    public function nbmListAllEnabledUsersToSend(): bool
    {
        return $this->nbmListAllEnabledUsersToSend;
    }

    public function setNbmListAllEnabledUsersToSend(bool $value): void
    {
        $this->nbmListAllEnabledUsersToSend = $value;
    }

    // === nbm_send_detailed_content ===
    /**
     * Include photo thumbnails and descriptions in NBM digest emails.
     */
    private bool $nbmSendDetailedContent = true;

    public function nbmSendDetailedContent(): bool
    {
        return $this->nbmSendDetailedContent;
    }

    public function setNbmSendDetailedContent(bool $value): void
    {
        $this->nbmSendDetailedContent = $value;
    }

    // === nbm_send_html_mail ===
    /**
     * Send NBM digest emails in HTML format.
     */
    private bool $nbmSendHtmlMail = true;

    public function nbmSendHtmlMail(): bool
    {
        return $this->nbmSendHtmlMail;
    }

    public function setNbmSendHtmlMail(bool $value): void
    {
        $this->nbmSendHtmlMail = $value;
    }

    // === nbm_send_mail_as ===
    /**
     * Override the From display name used specifically for NBM emails.
     */
    private string $nbmSendMailAs = '';

    public function nbmSendMailAs(): string
    {
        return $this->nbmSendMailAs;
    }

    public function setNbmSendMailAs(string $value): void
    {
        $this->nbmSendMailAs = $value;
    }

    // === nbm_send_recent_post_dates ===
    /**
     * Include recent-post date ranges in NBM digest emails.
     */
    private bool $nbmSendRecentPostDates = true;

    public function nbmSendRecentPostDates(): bool
    {
        return $this->nbmSendRecentPostDates;
    }

    public function setNbmSendRecentPostDates(bool $value): void
    {
        $this->nbmSendRecentPostDates = $value;
    }

    // === nbm_treatment_timeout_default ===
    /**
     * Default timeout in seconds for a single NBM send-batch execution.
     */
    private int $nbmTreatmentTimeoutDefault = 20;

    public function nbmTreatmentTimeoutDefault(): int
    {
        return $this->nbmTreatmentTimeoutDefault;
    }

    public function setNbmTreatmentTimeoutDefault(int $value): void
    {
        $this->nbmTreatmentTimeoutDefault = $value;
    }

    // === never_delete_originals ===
    /**
     * Prevent deletion of original image files when a photo is removed.
     */
    private bool $neverDeleteOriginals = false;

    public function neverDeleteOriginals(): bool
    {
        return $this->neverDeleteOriginals;
    }

    public function setNeverDeleteOriginals(bool $value): void
    {
        $this->neverDeleteOriginals = $value;
    }

    // === newcat_default_commentable ===
    /**
     * Make newly created albums commentable by default.
     */
    private bool $newcatDefaultCommentable = true;

    public function newcatDefaultCommentable(): bool
    {
        return $this->newcatDefaultCommentable;
    }

    public function setNewcatDefaultCommentable(bool $value): void
    {
        $this->newcatDefaultCommentable = $value;
    }

    // === newcat_default_position ===
    /**
     * Insert position for new sub-albums: first or last.
     */
    private string $newcatDefaultPosition = 'first';

    public function newcatDefaultPosition(): string
    {
        return $this->newcatDefaultPosition;
    }

    public function setNewcatDefaultPosition(string $value): void
    {
        $this->newcatDefaultPosition = $value;
    }

    // === newcat_default_status ===
    /**
     * Default visibility for new albums: public or private.
     */
    private string $newcatDefaultStatus = 'public';

    public function newcatDefaultStatus(): string
    {
        return $this->newcatDefaultStatus;
    }

    public function setNewcatDefaultStatus(string $value): void
    {
        $this->newcatDefaultStatus = $value;
    }

    // === newcat_default_visible ===
    /**
     * Make newly created albums visible by default.
     */
    private bool $newcatDefaultVisible = true;

    public function newcatDefaultVisible(): bool
    {
        return $this->newcatDefaultVisible;
    }

    public function setNewcatDefaultVisible(bool $value): void
    {
        $this->newcatDefaultVisible = $value;
    }

    // === no_photo_yet ===
    /**
     * Presence-only marker: once set (to 'false'), NoPhotoYetRenderer's first-run
     * banner is permanently suppressed. Genuine absence on a fresh install/reset
     * -- callers check noPhotoYet() === null to detect first-run state, matching
     * the gallery_url/last_major_update convention.
     */
    private ?string $noPhotoYet = null;

    public function noPhotoYet(): ?string
    {
        return $this->noPhotoYet;
    }

    public function setNoPhotoYet(?string $value): void
    {
        $this->noPhotoYet = $value;
    }

    // === no_photo_yet_url ===
    /**
     * Admin URL linked from the no-photos-yet placeholder shown to admins.
     */
    private string $noPhotoYetUrl = 'admin.php?page=photos_add';

    public function noPhotoYetUrl(): string
    {
        return $this->noPhotoYetUrl;
    }

    public function setNoPhotoYetUrl(string $value): void
    {
        $this->noPhotoYetUrl = $value;
    }

    // === obligatory_user_mail_address ===
    /**
     * Require an email address for all user registrations.
     */
    private bool $obligatoryUserMailAddress = false;

    public function obligatoryUserMailAddress(): bool
    {
        return $this->obligatoryUserMailAddress;
    }

    public function setObligatoryUserMailAddress(bool $value): void
    {
        $this->obligatoryUserMailAddress = $value;
    }

    // === original_resize ===
    /**
     * Resize uploaded originals that exceed the configured maximum dimensions.
     */
    private bool $originalResize = false;

    public function originalResize(): bool
    {
        return $this->originalResize;
    }

    public function setOriginalResize(bool $value): void
    {
        $this->originalResize = $value;
    }

    // === original_resize_maxheight ===
    /**
     * Maximum pixel height for uploaded originals when resize is enabled.
     */
    private int $originalResizeMaxheight = 2000;

    public function originalResizeMaxheight(): int
    {
        return $this->originalResizeMaxheight;
    }

    public function setOriginalResizeMaxheight(int $value): void
    {
        $this->originalResizeMaxheight = $value;
    }

    // === original_resize_maxwidth ===
    /**
     * Maximum pixel width for uploaded originals when resize is enabled.
     */
    private int $originalResizeMaxwidth = 2000;

    public function originalResizeMaxwidth(): int
    {
        return $this->originalResizeMaxwidth;
    }

    public function setOriginalResizeMaxwidth(int $value): void
    {
        $this->originalResizeMaxwidth = $value;
    }

    // === original_resize_quality ===
    /**
     * JPEG quality (1-100) used when resizing uploaded originals.
     */
    private int $originalResizeQuality = 95;

    public function originalResizeQuality(): int
    {
        return $this->originalResizeQuality;
    }

    public function setOriginalResizeQuality(int $value): void
    {
        $this->originalResizeQuality = $value;
    }

    // === original_url_protection ===
    /**
     * Original-file URL protection mode: empty (none), images, or all.
     */
    private string $originalUrlProtection = '';

    public function originalUrlProtection(): string
    {
        return $this->originalUrlProtection;
    }

    public function setOriginalUrlProtection(string $value): void
    {
        $this->originalUrlProtection = $value;
    }

    // === page_banner ===
    /**
     * HTML banner content displayed at the top of public gallery pages.
     */
    private string $pageBanner = '';

    public function pageBanner(): string
    {
        return $this->pageBanner;
    }

    public function setPageBanner(string $value): void
    {
        $this->pageBanner = $value;
    }

    // === paginate_pages_around ===
    /**
     * Number of page-number links shown on each side of the current page in
     * pagination.
     */
    private int $paginatePagesAround = 2;

    public function paginatePagesAround(): int
    {
        return $this->paginatePagesAround;
    }

    public function setPaginatePagesAround(int $value): void
    {
        $this->paginatePagesAround = $value;
    }

    // === password_activation_duration ===
    /**
     * Seconds a password-activation link emailed to new users remains valid.
     */
    private int $passwordActivationDuration = 259200;

    public function passwordActivationDuration(): int
    {
        return $this->passwordActivationDuration;
    }

    public function setPasswordActivationDuration(int $value): void
    {
        $this->passwordActivationDuration = $value;
    }

    // === password_reset_code_duration ===
    /**
     * Seconds a password-reset verification code is valid.
     */
    private int $passwordResetCodeDuration = 300;

    public function passwordResetCodeDuration(): int
    {
        return $this->passwordResetCodeDuration;
    }

    public function setPasswordResetCodeDuration(int $value): void
    {
        $this->passwordResetCodeDuration = $value;
    }

    // === password_reset_duration ===
    /**
     * Seconds a password-reset link emailed to a user remains valid.
     */
    private int $passwordResetDuration = 3600;

    public function passwordResetDuration(): int
    {
        return $this->passwordResetDuration;
    }

    public function setPasswordResetDuration(int $value): void
    {
        $this->passwordResetDuration = $value;
    }

    // === pdf_jpg_quality ===
    /**
     * JPEG quality used when Imagick renders a PDF's representative image.
     */
    private int $pdfJpgQuality = 90;

    public function pdfJpgQuality(): int
    {
        return $this->pdfJpgQuality;
    }

    public function setPdfJpgQuality(int $value): void
    {
        $this->pdfJpgQuality = $value;
    }

    // === pdf_representative_ext ===
    /**
     * File extension used for a PDF's rendered representative image.
     */
    private string $pdfRepresentativeExt = 'jpg';

    public function pdfRepresentativeExt(): string
    {
        return $this->pdfRepresentativeExt;
    }

    public function setPdfRepresentativeExt(string $value): void
    {
        $this->pdfRepresentativeExt = $value;
    }

    // === pdf_viewer_filesize_threshold ===
    /**
     * Maximum PDF file size in MB to display inline; larger files show a download
     * link.
     */
    private int $pdfViewerFilesizeThreshold = 5;

    public function pdfViewerFilesizeThreshold(): int
    {
        return $this->pdfViewerFilesizeThreshold;
    }

    public function setPdfViewerFilesizeThreshold(int $value): void
    {
        $this->pdfViewerFilesizeThreshold = $value;
    }

    // === pem_languages_category ===
    /**
     * PEM (Piwigo Extensions Manager) category ID for language packs.
     */
    private int $pemLanguagesCategory = 8;

    public function pemLanguagesCategory(): int
    {
        return $this->pemLanguagesCategory;
    }

    public function setPemLanguagesCategory(int $value): void
    {
        $this->pemLanguagesCategory = $value;
    }

    // === pem_plugins_category ===
    /**
     * PEM category ID for plugins.
     */
    private int $pemPluginsCategory = 12;

    public function pemPluginsCategory(): int
    {
        return $this->pemPluginsCategory;
    }

    public function setPemPluginsCategory(int $value): void
    {
        $this->pemPluginsCategory = $value;
    }

    // === pem_themes_category ===
    /**
     * PEM category ID for themes.
     */
    private int $pemThemesCategory = 10;

    public function pemThemesCategory(): int
    {
        return $this->pemThemesCategory;
    }

    public function setPemThemesCategory(int $value): void
    {
        $this->pemThemesCategory = $value;
    }

    // === php_extension_in_urls ===
    /**
     * Include the .php extension in generated picture/category URLs. Works only
     * with Options +MultiViews or URL rewriting active.
     */
    private bool $phpExtensionInUrls = true;

    public function phpExtensionInUrls(): bool
    {
        return $this->phpExtensionInUrls;
    }

    public function setPhpExtensionInUrls(bool $value): void
    {
        $this->phpExtensionInUrls = $value;
    }

    // === picture_caddie_icon ===
    /**
     * Show the add-to-caddie icon on the photo detail page.
     */
    private bool $pictureCaddieIcon = true;

    public function pictureCaddieIcon(): bool
    {
        return $this->pictureCaddieIcon;
    }

    public function setPictureCaddieIcon(bool $value): void
    {
        $this->pictureCaddieIcon = $value;
    }

    // === picture_download_icon ===
    /**
     * Show the download icon on the photo detail page.
     */
    private bool $pictureDownloadIcon = true;

    public function pictureDownloadIcon(): bool
    {
        return $this->pictureDownloadIcon;
    }

    public function setPictureDownloadIcon(bool $value): void
    {
        $this->pictureDownloadIcon = $value;
    }

    // === picture_edit_icon ===
    /**
     * Show the quick-edit icon on the photo detail page (admins only).
     */
    private bool $pictureEditIcon = true;

    public function pictureEditIcon(): bool
    {
        return $this->pictureEditIcon;
    }

    public function setPictureEditIcon(bool $value): void
    {
        $this->pictureEditIcon = $value;
    }

    // === picture_favorite_icon ===
    /**
     * Show the add-to-favorites icon on the photo detail page.
     */
    private bool $pictureFavoriteIcon = true;

    public function pictureFavoriteIcon(): bool
    {
        return $this->pictureFavoriteIcon;
    }

    public function setPictureFavoriteIcon(bool $value): void
    {
        $this->pictureFavoriteIcon = $value;
    }

    // === picture_menu ===
    /**
     * Show the navigation menu on the photo detail page.
     */
    private bool $pictureMenu = true;

    public function pictureMenu(): bool
    {
        return $this->pictureMenu;
    }

    public function setPictureMenu(bool $value): void
    {
        $this->pictureMenu = $value;
    }

    // === picture_metadata_icon ===
    /**
     * Show the metadata icon on the photo detail page.
     */
    private bool $pictureMetadataIcon = true;

    public function pictureMetadataIcon(): bool
    {
        return $this->pictureMetadataIcon;
    }

    public function setPictureMetadataIcon(bool $value): void
    {
        $this->pictureMetadataIcon = $value;
    }

    // === picture_navigation_icons ===
    /**
     * Show previous/next navigation arrows on the photo detail page.
     */
    private bool $pictureNavigationIcons = true;

    public function pictureNavigationIcons(): bool
    {
        return $this->pictureNavigationIcons;
    }

    public function setPictureNavigationIcons(bool $value): void
    {
        $this->pictureNavigationIcons = $value;
    }

    // === picture_navigation_thumb ===
    /**
     * Show previous/next thumbnail previews on the photo detail page.
     */
    private bool $pictureNavigationThumb = true;

    public function pictureNavigationThumb(): bool
    {
        return $this->pictureNavigationThumb;
    }

    public function setPictureNavigationThumb(bool $value): void
    {
        $this->pictureNavigationThumb = $value;
    }

    // === picture_representative_icon ===
    /**
     * Show the set-as-album-representative icon on the photo detail page.
     */
    private bool $pictureRepresentativeIcon = true;

    public function pictureRepresentativeIcon(): bool
    {
        return $this->pictureRepresentativeIcon;
    }

    public function setPictureRepresentativeIcon(bool $value): void
    {
        $this->pictureRepresentativeIcon = $value;
    }

    // === picture_sizes_icon ===
    /**
     * Show the available-sizes icon on the photo detail page.
     */
    private bool $pictureSizesIcon = true;

    public function pictureSizesIcon(): bool
    {
        return $this->pictureSizesIcon;
    }

    public function setPictureSizesIcon(bool $value): void
    {
        $this->pictureSizesIcon = $value;
    }

    // === picture_slideshow_icon ===
    /**
     * Show the slideshow icon on the photo detail page.
     */
    private bool $pictureSlideShowIcon = true;

    public function pictureSlideShowIcon(): bool
    {
        return $this->pictureSlideShowIcon;
    }

    public function setPictureSlideShowIcon(bool $value): void
    {
        $this->pictureSlideShowIcon = $value;
    }

    // === picture_url_style ===
    /**
     * URL format for photo links: id or id-file.
     */
    private string $pictureUrlStyle = 'id';

    public function pictureUrlStyle(): string
    {
        return $this->pictureUrlStyle;
    }

    public function setPictureUrlStyle(string $value): void
    {
        $this->pictureUrlStyle = $value;
    }

    // === piwigo_installed_version ===
    /**
     * Full Piwigo version string recorded at the time of the last upgrade.
     */
    private ?string $piwigoInstalledVersion = null;

    public function piwigoInstalledVersion(): ?string
    {
        return $this->piwigoInstalledVersion;
    }

    public function setPiwigoInstalledVersion(?string $value): void
    {
        $this->piwigoInstalledVersion = $value;
    }

    // === proxy_auth ===
    /**
     * Credentials (user:password) for HTTP proxy authentication.
     */
    private string $proxyAuth = '';

    public function proxyAuth(): string
    {
        return $this->proxyAuth;
    }

    public function setProxyAuth(string $value): void
    {
        $this->proxyAuth = $value;
    }

    // === proxy_server ===
    /**
     * HTTP proxy server URL used for outgoing connections from Piwigo.
     */
    private string $proxyServer = '';

    public function proxyServer(): string
    {
        return $this->proxyServer;
    }

    public function setProxyServer(string $value): void
    {
        $this->proxyServer = $value;
    }

    // === question_mark_in_urls ===
    /**
     * Include a ? in generated URLs. Can only be set false when the server
     * translates PATH_INFO (AcceptPathInfo).
     */
    private bool $questionMarkInUrls = true;

    public function questionMarkInUrls(): bool
    {
        return $this->questionMarkInUrls;
    }

    public function setQuestionMarkInUrls(bool $value): void
    {
        $this->questionMarkInUrls = $value;
    }

    // === quick_search_include_sub_albums ===
    /**
     * Include photos from sub-albums in quick-search results.
     */
    private bool $quickSearchIncludeSubAlbums = false;

    public function quickSearchIncludeSubAlbums(): bool
    {
        return $this->quickSearchIncludeSubAlbums;
    }

    public function setQuickSearchIncludeSubAlbums(bool $value): void
    {
        $this->quickSearchIncludeSubAlbums = $value;
    }

    // === rate ===
    /**
     * Enable the photo rating feature.
     */
    private bool $rateEnabled = true;

    public function rateEnabled(): bool
    {
        return $this->rateEnabled;
    }

    public function setRateEnabled(bool $value): void
    {
        $this->rateEnabled = $value;
    }

    // === rate_anonymous ===
    /**
     * Allow guest (unauthenticated) visitors to rate photos.
     */
    private bool $rateAnonymous = true;

    public function rateAnonymous(): bool
    {
        return $this->rateAnonymous;
    }

    public function setRateAnonymous(bool $value): void
    {
        $this->rateAnonymous = $value;
    }

    // === related_albums_display_limit ===
    /**
     * Maximum number of related albums shown on the photo detail page.
     */
    private int $relatedAlbumsDisplayLimit = 20;

    public function relatedAlbumsDisplayLimit(): int
    {
        return $this->relatedAlbumsDisplayLimit;
    }

    public function setRelatedAlbumsDisplayLimit(int $value): void
    {
        $this->relatedAlbumsDisplayLimit = $value;
    }

    // === related_albums_maximum_items_to_compute ===
    /**
     * Maximum photos considered when computing related albums.
     */
    private int $relatedAlbumsMaximumItemsToCompute = 1000;

    public function relatedAlbumsMaximumItemsToCompute(): int
    {
        return $this->relatedAlbumsMaximumItemsToCompute;
    }

    public function setRelatedAlbumsMaximumItemsToCompute(int $value): void
    {
        $this->relatedAlbumsMaximumItemsToCompute = $value;
    }

    // === remember_me_length ===
    /**
     * Lifetime in seconds of the remember-me persistent login cookie.
     */
    private int $rememberMeLength = 5184000;

    public function rememberMeLength(): int
    {
        return $this->rememberMeLength;
    }

    public function setRememberMeLength(int $value): void
    {
        $this->rememberMeLength = $value;
    }

    // === remember_me_name ===
    /**
     * Cookie name used for the remember-me persistent login token.
     */
    private string $rememberMeName = 'pwg_remember';

    public function rememberMeName(): string
    {
        return $this->rememberMeName;
    }

    public function setRememberMeName(string $value): void
    {
        $this->rememberMeName = $value;
    }

    // === representative_cache_on_level ===
    /**
     * Cache the album representative photo when permission level changes.
     */
    private bool $representativeCacheOnLevel = true;

    public function representativeCacheOnLevel(): bool
    {
        return $this->representativeCacheOnLevel;
    }

    public function setRepresentativeCacheOnLevel(bool $value): void
    {
        $this->representativeCacheOnLevel = $value;
    }

    // === representative_cache_on_subcats ===
    /**
     * Rebuild album representative thumbnails when sub-album content changes.
     */
    private bool $representativeCacheOnSubcats = true;

    public function representativeCacheOnSubcats(): bool
    {
        return $this->representativeCacheOnSubcats;
    }

    public function setRepresentativeCacheOnSubcats(bool $value): void
    {
        $this->representativeCacheOnSubcats = $value;
    }

    // === rss_feed_author ===
    /**
     * Author name shown in the gallery RSS feed.
     */
    private string $rssReedAuthor = 'Piwigo notifier';

    public function rssReedAuthor(): string
    {
        return $this->rssReedAuthor;
    }

    public function setRssReedAuthor(string $value): void
    {
        $this->rssReedAuthor = $value;
    }

    // === secret_key ===
    /**
     * Random string used to sign CSRF tokens and internal hashes.
     */
    #[Required]
    private string $secretKey = '';

    public function secretKey(): string
    {
        return $this->secretKey;
    }

    public function setSecretKey(string $value): void
    {
        $this->secretKey = $value;
    }

    // === send_bcc_mail_webmaster ===
    /**
     * BCC the webmaster address on every outgoing notification email.
     */
    private bool $sendBccMailWebmaster = false;

    public function sendBccMailWebmaster(): bool
    {
        return $this->sendBccMailWebmaster;
    }

    public function setSendBccMailWebmaster(bool $value): void
    {
        $this->sendBccMailWebmaster = $value;
    }

    // === send_piwigo_infos ===
    /**
     * Allow Piwigo to send anonymous usage statistics to the Piwigo project.
     */
    private bool $sendPiwigoInfos = true;

    public function sendPiwigoInfos(): bool
    {
        return $this->sendPiwigoInfos;
    }

    public function setSendPiwigoInfos(bool $value): void
    {
        $this->sendPiwigoInfos = $value;
    }

    // === send_piwigo_infos_last_notice ===
    /**
     * Date the admin was last shown the usage-statistics opt-in notice.
     */
    private ?string $sendPiwigoInfosLastNotice = null;

    public function sendPiwigoInfosLastNotice(): ?string
    {
        return $this->sendPiwigoInfosLastNotice;
    }

    public function setSendPiwigoInfosLastNotice(?string $value): void
    {
        $this->sendPiwigoInfosLastNotice = $value;
    }

    // === send_piwigo_infos_origin_hash ===
    /**
     * Anonymous installation hash included in usage statistics.
     */
    private ?string $sendPiwigoInfosOriginHash = null;

    public function sendPiwigoInfosOriginHash(): ?string
    {
        return $this->sendPiwigoInfosOriginHash;
    }

    public function setSendPiwigoInfosOriginHash(?string $value): void
    {
        $this->sendPiwigoInfosOriginHash = $value;
    }

    // === send_piwigo_infos_period ===
    /**
     * Minimum seconds between two "send Piwigo infos" telemetry pings.
     */
    private int $sendPiwigoInfosPeriod = 7 * 24 * 60 * 60;

    public function sendPiwigoInfosPeriod(): int
    {
        return $this->sendPiwigoInfosPeriod;
    }

    public function setSendPiwigoInfosPeriod(int $value): void
    {
        $this->sendPiwigoInfosPeriod = $value;
    }

    // === send_piwigo_infos_update_url ===
    /**
     * Base URL the "send Piwigo infos" telemetry ping is posted to.
     */
    private string $sendPiwigoInfosUpdateUrl = AppInfo::URL;

    public function sendPiwigoInfosUpdateUrl(): string
    {
        return $this->sendPiwigoInfosUpdateUrl;
    }

    public function setSendPiwigoInfosUpdateUrl(string $value): void
    {
        $this->sendPiwigoInfosUpdateUrl = $value;
    }

    // === session_gc_probability ===
    /**
     * Probability weight (out of 100) that a PHP session GC run is triggered per
     * request.
     */
    private int $sessionGcProbability = 1;

    public function sessionGcProbability(): int
    {
        return $this->sessionGcProbability;
    }

    public function setSessionGcProbability(int $value): void
    {
        $this->sessionGcProbability = $value;
    }

    // === session_length ===
    /**
     * PHP session lifetime in seconds (sets cookie_lifetime and gc_maxlifetime).
     */
    private int $sessionLength = 3600;

    public function sessionLength(): int
    {
        return $this->sessionLength;
    }

    public function setSessionLength(int $value): void
    {
        $this->sessionLength = $value;
    }

    // === session_name ===
    /**
     * PHP session cookie name used by Piwigo.
     */
    private string $sessionName = 'pwg_id';

    public function sessionName(): string
    {
        return $this->sessionName;
    }

    public function setSessionName(string $value): void
    {
        $this->sessionName = $value;
    }

    // === session_save_handler ===
    /**
     * Session storage backend: db (database) or files.
     */
    private string $sessionSaveHandler = 'db';

    public function sessionSaveHandler(): string
    {
        return $this->sessionSaveHandler;
    }

    public function setSessionSaveHandler(string $value): void
    {
        $this->sessionSaveHandler = $value;
    }

    // === session_use_cookies ===
    /**
     * Store the session ID in a cookie (PHP session.use_cookies).
     */
    private bool $sessionUseCookies = true;

    public function sessionUseCookies(): bool
    {
        return $this->sessionUseCookies;
    }

    public function setSessionUseCookies(bool $value): void
    {
        $this->sessionUseCookies = $value;
    }

    // === session_use_ip_address ===
    /**
     * Bind sessions to the client IP address to reduce session-hijacking risk.
     */
    private bool $sessionUseIpAddress = true;

    public function sessionUseIpAddress(): bool
    {
        return $this->sessionUseIpAddress;
    }

    public function setSessionUseIpAddress(bool $value): void
    {
        $this->sessionUseIpAddress = $value;
    }

    // === session_use_only_cookies ===
    /**
     * Reject session IDs passed in the URL; require cookie only (PHP
     * session.use_only_cookies).
     */
    private bool $sessionUseOnlyCookies = true;

    public function sessionUseOnlyCookies(): bool
    {
        return $this->sessionUseOnlyCookies;
    }

    public function setSessionUseOnlyCookies(bool $value): void
    {
        $this->sessionUseOnlyCookies = $value;
    }

    // === session_use_trans_sid ===
    /**
     * Allow the session ID to be transmitted in the URL query string (PHP
     * session.use_trans_sid).
     */
    private bool $sessionUseTransSid = false;

    public function sessionUseTransSid(): bool
    {
        return $this->sessionUseTransSid;
    }

    public function setSessionUseTransSid(bool $value): void
    {
        $this->sessionUseTransSid = $value;
    }

    // === show_exif ===
    /**
     * Display EXIF metadata on the photo detail page.
     */
    private bool $showExif = true;

    public function showExif(): bool
    {
        return $this->showExif;
    }

    public function setShowExif(bool $value): void
    {
        $this->showExif = $value;
    }

    // === show_gt ===
    /**
     * Show the Go-to navigation widget on photo detail pages.
     */
    private bool $showGt = false;

    public function showGt(): bool
    {
        return $this->showGt;
    }

    public function setShowGt(bool $value): void
    {
        $this->showGt = $value;
    }

    // === show_iptc ===
    /**
     * Display IPTC metadata on the photo detail page.
     */
    private bool $showIptc = false;

    public function showIptc(): bool
    {
        return $this->showIptc;
    }

    public function setShowIptc(bool $value): void
    {
        $this->showIptc = $value;
    }

    // === show_mobile_app_banner_in_admin ===
    /**
     * Show the "get the mobile app" banner while browsing the admin.
     */
    private bool $showMobileAppBannerInAdmin = true;

    public function showMobileAppBannerInAdmin(): bool
    {
        return $this->showMobileAppBannerInAdmin;
    }

    public function setShowMobileAppBannerInAdmin(bool $value): void
    {
        $this->showMobileAppBannerInAdmin = $value;
    }

    // === show_mobile_app_banner_in_gallery ===
    /**
     * Show the "get the mobile app" banner while browsing the gallery.
     */
    private bool $showMobileAppBannerInGallery = false;

    public function showMobileAppBannerInGallery(): bool
    {
        return $this->showMobileAppBannerInGallery;
    }

    public function setShowMobileAppBannerInGallery(bool $value): void
    {
        $this->showMobileAppBannerInGallery = $value;
    }

    // === show_newsletter_subscription ===
    /**
     * Show the newsletter subscription link in the gallery menu.
     */
    private bool $showNewsletterSubscription = true;

    public function showNewsletterSubscription(): bool
    {
        return $this->showNewsletterSubscription;
    }

    public function setShowNewsletterSubscription(bool $value): void
    {
        $this->showNewsletterSubscription = $value;
    }

    // === show_piwigo_latest_news ===
    /**
     * Show the latest Piwigo project news on the admin dashboard.
     */
    private bool $showPiwigoLatestNews = true;

    public function showPiwigoLatestNews(): bool
    {
        return $this->showPiwigoLatestNews;
    }

    public function setShowPiwigoLatestNews(bool $value): void
    {
        $this->showPiwigoLatestNews = $value;
    }

    // === show_queries ===
    /**
     * Append executed SQL queries to the page HTML for debugging.
     */
    private bool $showQueries = false;

    public function showQueries(): bool
    {
        return $this->showQueries;
    }

    public function setShowQueries(bool $value): void
    {
        $this->showQueries = $value;
    }

    // === show_template_in_side_menu ===
    /**
     * Show the active theme name in the gallery sidebar.
     */
    private bool $showTemplateInSideMenu = false;

    public function showTemplateInSideMenu(): bool
    {
        return $this->showTemplateInSideMenu;
    }

    public function setShowTemplateInSideMenu(bool $value): void
    {
        $this->showTemplateInSideMenu = $value;
    }

    // === show_thumbnail_caption ===
    /**
     * Show the photo title below thumbnails in album index pages.
     */
    private bool $showThumbnailCaption = true;

    public function showThumbnailCaption(): bool
    {
        return $this->showThumbnailCaption;
    }

    public function setShowThumbnailCaption(bool $value): void
    {
        $this->showThumbnailCaption = $value;
    }

    // === show_version ===
    /**
     * Display the Piwigo version string in the page footer and emails.
     */
    private bool $showVersion = false;

    public function showVersion(): bool
    {
        return $this->showVersion;
    }

    public function setShowVersion(bool $value): void
    {
        $this->showVersion = $value;
    }

    // === slideshow_period ===
    /**
     * Default interval in seconds between photos in the slideshow.
     */
    private int $slideshowPeriod = 4;

    public function slideshowPeriod(): int
    {
        return $this->slideshowPeriod;
    }

    public function setSlideshowPeriod(int $value): void
    {
        $this->slideshowPeriod = $value;
    }

    // === slideshow_period_max ===
    /**
     * Maximum selectable interval in seconds for the slideshow.
     */
    private int $slideshowPeriodMax = 10;

    public function slideshowPeriodMax(): int
    {
        return $this->slideshowPeriodMax;
    }

    public function setSlideshowPeriodMax(int $value): void
    {
        $this->slideshowPeriodMax = $value;
    }

    // === slideshow_period_min ===
    /**
     * Minimum selectable interval in seconds for the slideshow.
     */
    private int $slideshowPeriodMin = 1;

    public function slideshowPeriodMin(): int
    {
        return $this->slideshowPeriodMin;
    }

    public function setSlideshowPeriodMin(int $value): void
    {
        $this->slideshowPeriodMin = $value;
    }

    // === slideshow_period_step ===
    /**
     * Step size in seconds for the slideshow interval selector.
     */
    private int $slideshowPeriodStep = 1;

    public function slideshowPeriodStep(): int
    {
        return $this->slideshowPeriodStep;
    }

    public function setSlideshowPeriodStep(int $value): void
    {
        $this->slideshowPeriodStep = $value;
    }

    // === slideshow_repeat ===
    /**
     * Loop the slideshow back to the first photo after the last.
     */
    private bool $slideshowRepeat = true;

    public function slideshowRepeat(): bool
    {
        return $this->slideshowRepeat;
    }

    public function setSlideshowRepeat(bool $value): void
    {
        $this->slideshowRepeat = $value;
    }

    // === smtp_host ===
    /**
     * SMTP server hostname (and optional port) for outgoing email.
     */
    private string $smtpHost = '';

    public function smtpHost(): string
    {
        return $this->smtpHost;
    }

    public function setSmtpHost(string $value): void
    {
        $this->smtpHost = $value;
    }

    // === smtp_password ===
    /**
     * SMTP authentication password.
     */
    #[Sensitive]
    private string $smtpPassword = '';

    public function smtpPassword(): string
    {
        return $this->smtpPassword;
    }

    public function setSmtpPassword(string $value): void
    {
        $this->smtpPassword = $value;
    }

    // === smtp_secure ===
    /**
     * SMTP connection security: null (none), ssl, or tls.
     */
    private ?string $smtpSecure = null;

    public function smtpSecure(): ?string
    {
        return $this->smtpSecure;
    }

    public function setSmtpSecure(?string $value): void
    {
        $this->smtpSecure = $value;
    }

    // === smtp_user ===
    /**
     * SMTP authentication username.
     */
    private string $smtpUser = '';

    public function smtpUser(): string
    {
        return $this->smtpUser;
    }

    public function setSmtpUser(string $value): void
    {
        $this->smtpUser = $value;
    }

    // === standard_pages_selected_logo ===
    /**
     * Which logo the "standard pages" theme fallback displays: 'piwigo_logo',
     * 'custom_logo', 'gallery_title', or 'none'.
     */
    private string $standardPagesSelectedLogo = 'piwigo_logo';

    public function standardPagesSelectedLogo(): string
    {
        return $this->standardPagesSelectedLogo;
    }

    public function setStandardPagesSelectedLogo(string $value): void
    {
        $this->standardPagesSelectedLogo = $value;
    }

    // === standard_pages_selected_logo_path ===
    /**
     * Disk-relative path (under the 'local' disk) of the uploaded custom logo
     * used by the "standard pages" theme fallback; null until one is uploaded.
     */
    private ?string $standardPagesSelectedLogoPath = null;

    public function standardPagesSelectedLogoPath(): ?string
    {
        return $this->standardPagesSelectedLogoPath;
    }

    public function setStandardPagesSelectedLogoPath(?string $value): void
    {
        $this->standardPagesSelectedLogoPath = $value;
    }

    // === standard_pages_selected_skin ===
    /**
     * Skin used by the "standard pages" theme fallback (login/register/...).
     */
    private string $standardPagesSelectedSkin = 'default';

    public function standardPagesSelectedSkin(): string
    {
        return $this->standardPagesSelectedSkin;
    }

    public function setStandardPagesSelectedSkin(string $value): void
    {
        $this->standardPagesSelectedSkin = $value;
    }

    // === stat_compare_year_displayed ===
    /**
     * Number of years of photo statistics shown in the comparison chart.
     */
    private int $statCompareYearDisplayed = 5;

    public function statCompareYearDisplayed(): int
    {
        return $this->statCompareYearDisplayed;
    }

    public function setStatCompareYearDisplayed(int $value): void
    {
        $this->statCompareYearDisplayed = $value;
    }

    // === tag_letters_column_number ===
    /**
     * Number of columns in the alphabetical tag index layout.
     */
    private int $tagLettersColumnNumber = 4;

    public function tagLettersColumnNumber(): int
    {
        return $this->tagLettersColumnNumber;
    }

    public function setTagLettersColumnNumber(int $value): void
    {
        $this->tagLettersColumnNumber = $value;
    }

    // === tag_url_style ===
    /**
     * URL format for tag links: id, tag, or id-tag.
     */
    private string $tagUrlStyle = 'id-tag';

    public function tagUrlStyle(): string
    {
        return $this->tagUrlStyle;
    }

    public function setTagUrlStyle(string $value): void
    {
        $this->tagUrlStyle = $value;
    }

    // === tags_default_display_mode ===
    /**
     * Default tag-listing display mode: cloud or letters.
     */
    private string $tagsDefaultDisplayMode = 'cloud';

    public function tagsDefaultDisplayMode(): string
    {
        return $this->tagsDefaultDisplayMode;
    }

    public function setTagsDefaultDisplayMode(string $value): void
    {
        $this->tagsDefaultDisplayMode = $value;
    }

    // === tags_levels ===
    /**
     * Number of font-size levels used in the tag cloud.
     */
    private int $tagsLevels = 5;

    public function tagsLevels(): int
    {
        return $this->tagsLevels;
    }

    public function setTagsLevels(int $value): void
    {
        $this->tagsLevels = $value;
    }

    // === template_combine_files ===
    /**
     * Merge JavaScript/CSS files together at render time to reduce the number of
     * HTTP requests.
     */
    private bool $templateCombineFiles = true;

    public function templateCombineFiles(): bool
    {
        return $this->templateCombineFiles;
    }

    public function setTemplateCombineFiles(bool $value): void
    {
        $this->templateCombineFiles = $value;
    }

    // === template_compile_check ===
    /**
     * Recompile Latte templates when source files change (disable in production).
     */
    private bool $templateCompileCheck = true;

    public function templateCompileCheck(): bool
    {
        return $this->templateCompileCheck;
    }

    public function setTemplateCompileCheck(bool $value): void
    {
        $this->templateCompileCheck = $value;
    }

    // === template_force_compile ===
    /**
     * Always recompile Latte templates on every request.
     */
    private bool $templateForceCompile = false;

    public function templateForceCompile(): bool
    {
        return $this->templateForceCompile;
    }

    public function setTemplateForceCompile(bool $value): void
    {
        $this->templateForceCompile = $value;
    }

    // === themes_dir ===
    /**
     * Root-relative path to the directory containing installed themes (compose
     * with a real, constructor-injected Paths::$root for an absolute
     * filesystem path).
     */
    private string $themesDir = 'themes/';

    public function themesDir(): string
    {
        return $this->themesDir;
    }

    public function setThemesDir(string $value): void
    {
        $this->themesDir = $value;
    }

    // === tiff_representative_ext ===
    /**
     * Image extension used when generating a representative for TIFF originals.
     */
    private string $tiffRepresentativeExt = 'png';

    public function tiffRepresentativeExt(): string
    {
        return $this->tiffRepresentativeExt;
    }

    public function setTiffRepresentativeExt(string $value): void
    {
        $this->tiffRepresentativeExt = $value;
    }

    // === top_number ===
    /**
     * Number of items shown in top ranking lists (most visited, best rated, etc.).
     */
    private int $topNumber = 15;

    public function topNumber(): int
    {
        return $this->topNumber;
    }

    public function setTopNumber(int $value): void
    {
        $this->topNumber = $value;
    }

    // === trusted_proxies ===
    /**
     * Comma-separated CIDR list of reverse proxies whose forwarded headers are
     * trusted.
     */
    private string $trustedProxies = '';

    public function trustedProxies(): string
    {
        return $this->trustedProxies;
    }

    public function setTrustedProxies(string $value): void
    {
        $this->trustedProxies = $value;
    }

    // === uniqueness_mode ===
    /**
     * Algorithm used to detect duplicate uploads: md5sum or filename.
     */
    private string $uniquenessMode = 'md5sum';

    public function uniquenessMode(): string
    {
        return $this->uniquenessMode;
    }

    public function setUniquenessMode(string $value): void
    {
        $this->uniquenessMode = $value;
    }

    // === update_notify_check_period ===
    /**
     * Interval in seconds between automatic checks for Piwigo updates.
     */
    private int $updateNotifyCheckPeriod = 86400;

    public function updateNotifyCheckPeriod(): int
    {
        return $this->updateNotifyCheckPeriod;
    }

    public function setUpdateNotifyCheckPeriod(int $value): void
    {
        $this->updateNotifyCheckPeriod = $value;
    }

    // === update_notify_last_check ===
    /**
     * Timestamp of the last update-availability check.
     */
    private ?string $updateNotifyLastCheck = null;

    public function updateNotifyLastCheck(): ?string
    {
        return $this->updateNotifyLastCheck;
    }

    public function setUpdateNotifyLastCheck(?string $value): void
    {
        $this->updateNotifyLastCheck = $value;
    }

    // === update_notify_reminder_period ===
    /**
     * Interval in seconds between repeated update reminder notifications.
     */
    private int $updateNotifyReminderPeriod = 604800;

    public function updateNotifyReminderPeriod(): int
    {
        return $this->updateNotifyReminderPeriod;
    }

    public function setUpdateNotifyReminderPeriod(int $value): void
    {
        $this->updateNotifyReminderPeriod = $value;
    }

    // === upload_detect_duplicate ===
    /**
     * Check for duplicate photos by checksum when uploading.
     */
    private bool $uploadDetectDuplicate = true;

    public function uploadDetectDuplicate(): bool
    {
        return $this->uploadDetectDuplicate;
    }

    public function setUploadDetectDuplicate(bool $value): void
    {
        $this->uploadDetectDuplicate = $value;
    }

    // === upload_dir ===
    /**
     * Root-relative path to the directory where uploaded files are stored (compose
     * with a real, constructor-injected Paths::$root for an absolute
     * filesystem path).
     */
    private string $uploadDir = 'upload/';

    public function uploadDir(): string
    {
        return $this->uploadDir;
    }

    public function setUploadDir(string $value): void
    {
        $this->uploadDir = $value;
    }

    // === upload_form_all_types ===
    /**
     * Allow uploading any file type, not just images and videos.
     */
    private bool $uploadFormAllTypes = false;

    public function uploadFormAllTypes(): bool
    {
        return $this->uploadFormAllTypes;
    }

    public function setUploadFormAllTypes(bool $value): void
    {
        $this->uploadFormAllTypes = $value;
    }

    // === upload_form_automatic_rotation ===
    /**
     * Automatically rotate uploaded photos based on their EXIF orientation tag.
     */
    private bool $uploadFormAutomaticRotation = true;

    public function uploadFormAutomaticRotation(): bool
    {
        return $this->uploadFormAutomaticRotation;
    }

    public function setUploadFormAutomaticRotation(bool $value): void
    {
        $this->uploadFormAutomaticRotation = $value;
    }

    // === upload_form_chunk_size ===
    /**
     * Chunk size in KB for multi-part file uploads via the upload form.
     */
    private int $uploadFormChunkSize = 500;

    public function uploadFormChunkSize(): int
    {
        return $this->uploadFormChunkSize;
    }

    public function setUploadFormChunkSize(int $value): void
    {
        $this->uploadFormChunkSize = $value;
    }

    // === upload_form_max_file_size ===
    /**
     * Maximum file size in MB accepted by the upload form.
     */
    private int $uploadFormMaxFileSize = 1000;

    public function uploadFormMaxFileSize(): int
    {
        return $this->uploadFormMaxFileSize;
    }

    public function setUploadFormMaxFileSize(int $value): void
    {
        $this->uploadFormMaxFileSize = $value;
    }

    // === url_port ===
    /**
     * Port included in generated URLs: none, or a port number string.
     */
    private string $urlPort = 'none';

    public function urlPort(): string
    {
        return $this->urlPort;
    }

    public function setUrlPort(string $value): void
    {
        $this->urlPort = $value;
    }

    // === use_exif ===
    /**
     * Read EXIF metadata from uploaded photos and store it in the database.
     */
    private bool $useExif = true;

    public function useExif(): bool
    {
        return $this->useExif;
    }

    public function setUseExif(bool $value): void
    {
        $this->useExif = $value;
    }

    // === use_iptc ===
    /**
     * Read IPTC metadata from uploaded photos and store it in the database.
     */
    private bool $useIptc = false;

    public function useIptc(): bool
    {
        return $this->useIptc;
    }

    public function setUseIptc(bool $value): void
    {
        $this->useIptc = $value;
    }

    // === use_proxy ===
    /**
     * Send outgoing HTTP requests from Piwigo through a proxy server.
     */
    private bool $useProxy = false;

    public function useProxy(): bool
    {
        return $this->useProxy;
    }

    public function setUseProxy(bool $value): void
    {
        $this->useProxy = $value;
    }

    // === use_standard_pages ===
    /**
     * Whether the current theme falls back to Piwigo's own "standard pages"
     * (login/register/forgot-password/...) instead of its own.
     */
    private bool $useStandardPages = true;

    public function useStandardPages(): bool
    {
        return $this->useStandardPages;
    }

    public function setUseStandardPages(bool $value): void
    {
        $this->useStandardPages = $value;
    }

    // === user_can_delete_comment ===
    /**
     * Allow a registered user to delete their own comments.
     */
    private bool $userCanDeleteComment = false;

    public function userCanDeleteComment(): bool
    {
        return $this->userCanDeleteComment;
    }

    public function setUserCanDeleteComment(bool $value): void
    {
        $this->userCanDeleteComment = $value;
    }

    // === user_can_edit_comment ===
    /**
     * Allow a registered user to edit their own comments.
     */
    private bool $userCanEditComment = false;

    public function userCanEditComment(): bool
    {
        return $this->userCanEditComment;
    }

    public function setUserCanEditComment(bool $value): void
    {
        $this->userCanEditComment = $value;
    }

    // === webmaster_id ===
    /**
     * User ID of the designated webmaster account.
     */
    private int $webmasterId = 1;

    public function webmasterId(): int
    {
        return $this->webmasterId;
    }

    public function setWebmasterId(int $value): void
    {
        $this->webmasterId = $value;
    }

    // === week_starts_on ===
    /**
     * First day of the week in calendar views: monday or sunday.
     */
    private string $weekStartsOn = 'monday';

    public function weekStartsOn(): string
    {
        return $this->weekStartsOn;
    }

    public function setWeekStartsOn(string $value): void
    {
        $this->weekStartsOn = $value;
    }

    // === ws_max_images_per_page ===
    /**
     * Maximum number of photos returned per page by the web-service API.
     */
    private int $wsMaxImagesPerPage = 500;

    public function wsMaxImagesPerPage(): int
    {
        return $this->wsMaxImagesPerPage;
    }

    public function setWsMaxImagesPerPage(int $value): void
    {
        $this->wsMaxImagesPerPage = $value;
    }

    // === ws_max_users_per_page ===
    /**
     * Maximum number of users returned per page by the web-service API.
     */
    private int $wsMaxUsersPerPage = 1000;

    public function wsMaxUsersPerPage(): int
    {
        return $this->wsMaxUsersPerPage;
    }

    public function setWsMaxUsersPerPage(int $value): void
    {
        $this->wsMaxUsersPerPage = $value;
    }

    // ---- Custom-shaped properties (non-trivial coercion) ---------------

    // === api_key_duration ===
    /**
     * Selectable API-key expiration presets, in days (plus the literal 'custom' entry).
     * @var list<string>
     */
    private array $apiKeyDuration = ['30', '90', '180', '365', 'custom'];

    /**
     * @return list<string>
     */
    public function apiKeyDuration(): array
    {
        return $this->apiKeyDuration;
    }

    /**
     * @param array<mixed> $value
     */
    public function setApiKeyDuration(array $value): void
    {
        $this->apiKeyDuration = array_values(array_map(static fn (mixed $x): string => is_scalar($x) || $x === null ? (string) $x : '', $value));
    }

    // === api_key_forbidden_methods ===
    /**
     * Web-service method names that API-key callers are not allowed to invoke.
     * @var list<string>
     */
    private array $apiKeyForbiddenMethods = ['pwg.users.generatePasswordLink', 'pwg.users.getAuthKey', 'pwg.users.setMainUser', 'pwg.users.setInfo', 'pwg.plugins.performAction', 'pwg.themes.performAction', 'pwg.extensions.ignoreUpdate', 'pwg.extensions.update'];

    /**
     * @return list<string>
     */
    public function apiKeyForbiddenMethods(): array
    {
        return $this->apiKeyForbiddenMethods;
    }

    /**
     * @param array<mixed> $value
     */
    public function setApiKeyForbiddenMethods(array $value): void
    {
        $this->apiKeyForbiddenMethods = array_values(array_map(static fn (mixed $x): string => is_scalar($x) || $x === null ? (string) $x : '', $value));
    }

    // === available_permission_levels ===
    /**
     * Ordered list of numeric permission levels visible in the UI.
     * @var list<int>
     */
    private array $availablePermissionLevels = [0, 1, 2, 4, 8];

    /**
     * @return list<int>
     */
    public function availablePermissionLevels(): array
    {
        return $this->availablePermissionLevels;
    }

    /**
     * @param array<mixed> $value
     */
    public function setAvailablePermissionLevels(array $value): void
    {
        $this->availablePermissionLevels = count($value) > 0
            ? array_values(array_map(static fn (mixed $x): int => is_scalar($x) ? (int) $x : 0, $value))
            : [0, 1, 2, 4, 8];
    }

    // === blk_menubar ===
    /**
     * Serialized per-block position overrides for the sidebar menubar. This
     * is the only real Piwigo\Menu\BlockManager id anywhere in this
     * codebase, so a single property is enough -- the id is never actually
     * variable in practice.
     *
     * Exposed as `?array`, not the raw encoded blob, matching every other
     * array-shaped property. `Admin\MenubarPageRenderer` writes it directly
     * via `Config\ConfigRepository::upsert()`, `json_encode()`-d the same
     * way `ConfigService::encode()` encodes it; the read side is a plain
     * `hydrate()`-driven `json_decode()`, with no manual unserialize()
     * anywhere.
     * @var array<mixed>|null
     */
    private ?array $blkMenubar = null;

    /**
     * @return array<mixed>|null
     */
    public function blkMenubar(): ?array
    {
        return $this->blkMenubar;
    }

    /**
     * @param array<mixed>|null $value
     */
    public function setBlkMenubar(?array $value): void
    {
        $this->blkMenubar = $value;
    }

    // === cache_sizes ===
    /**
     * Serialized [name, value] rows of cache-directory sizes computed by the
     * maintenance page, cached to avoid recomputing on every dashboard/
     * maintenance load.
     * @var array<mixed>|null
     */
    private ?array $cacheSizes = null;

    /**
     * @return array<mixed>|null
     */
    public function cacheSizes(): ?array
    {
        return $this->cacheSizes;
    }

    /**
     * @param array<mixed>|null $value
     */
    public function setCacheSizes(?array $value): void
    {
        $this->cacheSizes = $value;
    }

    // === chmod_value ===
    /**
     * Filesystem permission bits applied to newly created directories -- 0777
     * under Apache, 0755 otherwise, unless explicitly overridden. Null means
     * "not explicitly overridden": the SAPI-dependent default below applies.
     */
    private ?int $chmodValue = null;

    public function chmodValue(): int
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
        if (Env::testModeIsActive()) {
            return $this->chmodValue ?? 0777;
        }

        return $this->chmodValue ?? (substr_compare(\PHP_SAPI, 'apa', 0, 3) === 0 ? 0777 : 0755);
    }

    public function setChmodValue(?int $value): void
    {
        $this->chmodValue = $value;
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
    private array $defaultFiltersViews = self::DEFAULT_FILTERS_VIEWS;

    /**
     * @return array<string, array{access: string, default: bool}>
     */
    public function defaultFiltersViews(): array
    {
        return $this->defaultFiltersViews;
    }

    /**
     * @param array<mixed>|null $value
     */
    public function setDefaultFiltersViews(?array $value): void
    {
        if ($value === null) {
            $this->defaultFiltersViews = self::DEFAULT_FILTERS_VIEWS;
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
        $this->defaultFiltersViews = $result;
    }

    // === empty_lounge_running ===
    /**
     * Transient "<execId>-<startTime>" marker set while
     * ImageService::emptyLounge() is running, used to detect a concurrent/
     * stalled run. Absent when no run is in progress.
     */
    private ?string $emptyLoungeRunning = null;

    public function emptyLoungeRunning(): ?string
    {
        return $this->emptyLoungeRunning;
    }

    public function setEmptyLoungeRunning(?string $value): void
    {
        $this->emptyLoungeRunning = $value;
    }

    // === extents_for_templates ===
    /**
     * Comma-separated list of template file extensions recognised by the
     * theme engine.
     * @var array<mixed>
     */
    private array $extentsForTemplates = [];

    /**
     * @return array<mixed>
     */
    public function extentsForTemplates(): array
    {
        return $this->extentsForTemplates;
    }

    /**
     * @param array<mixed> $value
     */
    public function setExtentsForTemplates(array $value): void
    {
        $this->extentsForTemplates = $value;
    }

    // === file_ext ===
    /**
     * Full list of file extensions Piwigo will manage (pictures plus extras).
     * @var list<string>
     */
    private array $fileExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'tiff', 'tif', 'mpg', 'zip', 'avi', 'mp3', 'ogg', 'pdf', 'svg', 'heic'];

    /**
     * @return list<string>
     */
    public function fileExtensions(): array
    {
        return $this->fileExtensions;
    }

    /**
     * @param array<mixed> $value
     */
    public function setFileExtensions(array $value): void
    {
        $this->fileExtensions = array_values(array_map(static fn (mixed $x): string => is_scalar($x) || $x === null ? (string) $x : '', $value));
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
    private array $filterPages = self::DEFAULT_FILTER_PAGES;

    /**
     * @return array<string, array<string, bool>>
     */
    public function filterPages(): array
    {
        return $this->filterPages;
    }

    /**
     * @param array<string, array<string, bool>> $value
     */
    public function setFilterPages(array $value): void
    {
        $this->filterPages = $value;
    }

    // === filters_views ===
    /**
     * Admin-customized search-filter definitions, lazily seeded from
     * 'default_filters_views' the first time the search filters admin page is
     * saved. Absent (falls back to defaultFiltersViews()) until then.
     * @var array<mixed>|null
     */
    private ?array $filtersViews = null;

    /**
     * @return array<mixed>|null
     */
    public function filtersViews(): ?array
    {
        return $this->filtersViews;
    }

    /**
     * @param array<mixed>|null $value
     */
    public function setFiltersViews(?array $value): void
    {
        $this->filtersViews = $value;
    }

    // === format_ext ===
    /**
     * File extensions recognised as additional formats for multi-format
     * photos.
     * @var list<string>
     */
    private array $formatExtensions = ['cr2', 'tif', 'tiff', 'nef', 'dng', 'ai', 'psd'];

    /**
     * @return list<string>
     */
    public function formatExtensions(): array
    {
        return $this->formatExtensions;
    }

    /**
     * @param array<mixed> $value
     */
    public function setFormatExtensions(array $value): void
    {
        $this->formatExtensions = array_values(array_map(static fn (mixed $x): string => is_scalar($x) || $x === null ? (string) $x : '', $value));
    }

    // === header_notes ===
    /**
     * Additional HTML messages shown in the gallery header for all users.
     * @var list<string>
     */
    private array $headerNotes = [];

    /**
     * @return list<string>
     */
    public function headerNotes(): array
    {
        return $this->headerNotes;
    }

    /**
     * @param array<mixed> $value
     */
    public function setHeaderNotes(array $value): void
    {
        $this->headerNotes = array_values(array_map(static fn (mixed $x): string => is_scalar($x) || $x === null ? (string) $x : '', $value));
    }

    // === history_sections_cache ===
    /**
     * Cached list of the history.section enum column values, refreshed when a
     * plugin adds a new section.
     * @var list<string>|null
     */
    private ?array $historySectionsCache = null;

    /**
     * @return list<string>|null
     */
    public function historySectionsCache(): ?array
    {
        return $this->historySectionsCache;
    }

    /**
     * @param array<mixed>|null $value
     */
    public function setHistorySectionsCache(?array $value): void
    {
        $this->historySectionsCache = $value === null ? null : array_values(array_filter($value, is_string(...)));
    }

    // === links ===
    /**
     * Additional navigation links shown in the gallery menu.
     * @var array<mixed>
     */
    private array $links = [];

    /**
     * @return array<mixed>
     */
    public function links(): array
    {
        return $this->links;
    }

    /**
     * @param array<mixed> $value
     */
    public function setLinks(array $value): void
    {
        $this->links = $value;
    }

    // === metadata_keyword_separator_regex ===
    /**
     * PCRE regex used to split keyword strings extracted from EXIF/IPTC
     * metadata.
     */
    private string $metadataKeywordSeparatorRegex = '/[.,;]/';

    public function metadataKeywordSeparatorRegex(): string
    {
        return $this->metadataKeywordSeparatorRegex;
    }

    public function setMetadataKeywordSeparatorRegex(string $value): void
    {
        $this->metadataKeywordSeparatorRegex = $value !== '' ? $value : '/[.,;]/';
    }

    // === nbm_max_treatment_timeout_percent ===
    /**
     * Fraction of the PHP max_execution_time budget NBM may consume per batch.
     */
    private float $nbmMaxTreatmentTimeoutPercent = 0.8;

    public function nbmMaxTreatmentTimeoutPercent(): float
    {
        return $this->nbmMaxTreatmentTimeoutPercent;
    }

    public function setNbmMaxTreatmentTimeoutPercent(float $value): void
    {
        $this->nbmMaxTreatmentTimeoutPercent = $value;
    }

    // === order_by ===
    // A raw SQL "ORDER BY ..." fragment string, not a structured
    // {field,dir}[] shape -- every real reader across BatchManager*/
    // SearchService/CategoryService/CalendarRenderer/TagService/
    // SectionPopulator/Ws/PwgCategories/GalleryController treats it as one.
    // Default matches install/config.sql's seed row.
    private string $orderBy = 'ORDER BY date_available DESC, file ASC, id ASC';

    public function orderBy(): string
    {
        return $this->orderBy;
    }

    public function setOrderBy(string $value): void
    {
        $this->orderBy = $value;
    }

    // === order_by_custom ===
    /**
     * Admin-defined custom sort order that overrides order_by when set --
     * a raw "ORDER BY ..." SQL fragment string, same real shape as order_by
     * itself (see its own docblock).
     */
    private ?string $orderByCustom = null;

    public function orderByCustom(): ?string
    {
        return $this->orderByCustom;
    }

    public function setOrderByCustom(?string $value): void
    {
        $this->orderByCustom = $value;
    }

    // === order_by_inside_category ===
    /**
     * Active sort order applied within album listings -- a raw
     * "ORDER BY ..." SQL fragment string (see order_by's own docblock).
     */
    private string $orderByInsideCategory = 'ORDER BY date_available DESC, file ASC, id ASC';

    public function orderByInsideCategory(): string
    {
        return $this->orderByInsideCategory;
    }

    public function setOrderByInsideCategory(string $value): void
    {
        $this->orderByInsideCategory = $value;
    }

    // === order_by_inside_category_custom ===
    /**
     * Admin-defined custom sort order that overrides order_by_inside_category
     * when set (see order_by's own docblock).
     */
    private ?string $orderByInsideCategoryCustom = null;

    public function orderByInsideCategoryCustom(): ?string
    {
        return $this->orderByInsideCategoryCustom;
    }

    public function setOrderByInsideCategoryCustom(?string $value): void
    {
        $this->orderByInsideCategoryCustom = $value;
    }

    // === picture_ext ===
    /**
     * File extensions recognised as displayable photo types.
     * @var list<string>
     */
    private array $pictureExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    /**
     * @return list<string>
     */
    public function pictureExtensions(): array
    {
        return $this->pictureExtensions;
    }

    /**
     * @param array<mixed> $value
     */
    public function setPictureExtensions(array $value): void
    {
        $this->pictureExtensions = array_values(array_map(static fn (mixed $x): string => is_scalar($x) || $x === null ? (string) $x : '', $value));
    }

    // === picture_informations ===
    /**
     * Map of metadata field names to visibility booleans on the photo detail
     * page.
     * @var array<string, bool>
     */
    private array $pictureInformations = [];

    /**
     * @return array<string, bool>
     */
    public function pictureInformations(): array
    {
        return $this->pictureInformations;
    }

    /**
     * @param array<mixed> $value
     */
    public function setPictureInformations(array $value): void
    {
        $out = [];
        foreach ($value as $key => $val) {
            if (is_string($key) && is_bool($val)) {
                $out[$key] = $val;
            }
        }
        $this->pictureInformations = $out;
    }

    // === random_index_redirect ===
    /**
     * URL mapping for random-index redirects used by shuffle features.
     * @var array<string,string>
     */
    private array $randomIndexRedirect = [];

    /**
     * @return array<string,string>
     */
    public function randomIndexRedirect(): array
    {
        return $this->randomIndexRedirect;
    }

    /**
     * @param array<mixed> $value
     */
    public function setRandomIndexRedirect(array $value): void
    {
        $result = [];
        foreach ($value as $key => $val) {
            if (is_scalar($val)) {
                $result[(string) $key] = (string) $val;
            }
        }
        $this->randomIndexRedirect = $result;
    }

    // === rate_items ===
    /**
     * Available rating values displayed in the rating widget.
     * @var list<int>
     */
    private array $rateItems = [0, 1, 2, 3, 4, 5];

    /**
     * @return list<int>
     */
    public function rateItems(): array
    {
        return $this->rateItems;
    }

    /**
     * @param array<mixed> $value
     */
    public function setRateItems(array $value): void
    {
        $this->rateItems = array_values(array_map(static fn (mixed $x): int => is_scalar($x) ? (int) $x : 0, $value));
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
    private ?NotificationConfig $recentPostDates = null;

    public function recentPostDates(): NotificationConfig
    {
        return $this->recentPostDates ??= new NotificationConfig(
            rss: new NotificationChannelConfig(maxDates: 5, maxElements: 6, maxCats: 6),
            nbm: new NotificationChannelConfig(maxDates: 7, maxElements: 3, maxCats: 9),
        );
    }

    /**
     * @param array<mixed> $value
     */
    public function setRecentPostDates(array $value): void
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
        $this->recentPostDates = new NotificationConfig(rss: $build('RSS'), nbm: $build('NBM'));
    }

    // === show_exif_fields ===
    /**
     * List of EXIF field names to display on the photo detail page.
     * @var list<string>
     */
    private array $showExifFields = ['Make', 'Model', 'DateTimeOriginal', 'COMPUTED;ApertureFNumber'];

    /**
     * @return list<string>
     */
    public function showExifFields(): array
    {
        return $this->showExifFields;
    }

    /**
     * @param array<mixed> $value
     */
    public function setShowExifFields(array $value): void
    {
        $this->showExifFields = array_values(array_map(static fn (mixed $x): string => is_scalar($x) || $x === null ? (string) $x : '', $value));
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
    private array $showIptcMapping = self::DEFAULT_SHOW_IPTC_MAPPING;

    /**
     * @return array<string,string>
     */
    public function showIptcMapping(): array
    {
        return $this->showIptcMapping;
    }

    /**
     * @param array<mixed> $value
     */
    public function setShowIptcMapping(array $value): void
    {
        $result = [];
        foreach ($value as $k => $val) {
            $result[(string) $k] = is_scalar($val) ? (string) $val : '';
        }
        $this->showIptcMapping = $result;
    }

    // === sync_chars_regex ===
    /**
     * Regex that matches valid filename characters during filesystem
     * synchronisation.
     */
    private string $syncCharsRegex = '/^[a-zA-Z0-9-_.]+$/';

    public function syncCharsRegex(): string
    {
        return $this->syncCharsRegex;
    }

    public function setSyncCharsRegex(string $value): void
    {
        $this->syncCharsRegex = $value !== '' ? $value : '/^[a-zA-Z0-9-_.]+$/';
    }

    // === sync_exclude_folders ===
    /**
     * Folder names excluded from filesystem synchronisation.
     * @var list<string>
     */
    private array $syncExcludeFolders = [];

    /**
     * @return list<string>
     */
    public function syncExcludeFolders(): array
    {
        return $this->syncExcludeFolders;
    }

    /**
     * @param array<mixed> $value
     */
    public function setSyncExcludeFolders(array $value): void
    {
        $this->syncExcludeFolders = array_values(array_map(static fn (mixed $x): string => is_scalar($x) || $x === null ? (string) $x : '', $value));
    }

    // === update_notify_last_notification ===
    /**
     * Serialized {version, notified_on} of the last update-availability
     * notification shown to the admin. Genuine absence before the first
     * check.
     * @var array{version?: mixed, notified_on?: mixed}|null
     */
    private ?array $updateNotifyLastNotification = null;

    /**
     * @return array{version?: mixed, notified_on?: mixed}|null
     */
    public function updateNotifyLastNotification(): ?array
    {
        return $this->updateNotifyLastNotification;
    }

    /**
     * @param array<mixed>|null $value
     */
    public function setUpdateNotifyLastNotification(?array $value): void
    {
        if ($value === null) {
            $this->updateNotifyLastNotification = null;
            return;
        }
        $result = [];
        if (array_key_exists('version', $value)) {
            $result['version'] = $value['version'];
        }
        if (array_key_exists('notified_on', $value)) {
            $result['notified_on'] = $value['notified_on'];
        }
        $this->updateNotifyLastNotification = $result;
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
    private array $useExifMapping = self::DEFAULT_USE_EXIF_MAPPING;

    /**
     * @return array<string,string>
     */
    public function useExifMapping(): array
    {
        return $this->useExifMapping;
    }

    /**
     * @param array<mixed> $value
     */
    public function setUseExifMapping(array $value): void
    {
        $result = [];
        foreach ($value as $k => $val) {
            $result[(string) $k] = is_scalar($val) ? (string) $val : '';
        }
        $this->useExifMapping = $result;
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
    private array $useIptcMapping = self::DEFAULT_USE_IPTC_MAPPING;

    /**
     * @return array<string,string>
     */
    public function useIptcMapping(): array
    {
        return $this->useIptcMapping;
    }

    /**
     * @param array<mixed> $value
     */
    public function setUseIptcMapping(array $value): void
    {
        $result = [];
        foreach ($value as $k => $val) {
            $result[(string) $k] = is_scalar($val) ? (string) $val : '';
        }
        $this->useIptcMapping = $result;
    }

    // ---- Composed accessors (no config key of their own) ---------------

    /**
     * Composed accessors replacing 3 of the 52 retired `define()` constants
     * (PHPWG_THEMES_PATH/PWG_COMBINED_DIR/PWG_DERIVATIVE_DIR) -- not real
     * config keys, just the same composition `include/constants.php` used
     * to do at boot time (`$themesDir . '/'`, `$dataLocation . 'combined/'`,
     * `$dataLocation . 'i/'`).
     */
    public function themesPath(): string
    {
        return $this->themesDir() . '/';
    }

    public function combinedDir(): string
    {
        return $this->dataLocation() . 'combined/';
    }

    public function derivativeDir(): string
    {
        return $this->dataLocation() . 'i/';
    }

    // ---- Bulk / test / legacy-bridge helpers -----------------------------

    /**
     * Every property, keyed by property name, gathered via reflection.
     * Sensitive-flagged properties are redacted. Private: the only real
     * caller is dumpForLog() itself.
     *
     * @return array<string, mixed>
     */
    private function all(): array
    {
        $out = [];
        $reflection = new ReflectionClass($this);
        foreach ($reflection->getProperties(ReflectionProperty::IS_PRIVATE) as $property) {
            // IS_PRIVATE alone also matches static properties; isStatic()
            // is the only way to filter those out.
            if ($property->isStatic()) {
                continue;
            }
            $value = $property->getValue($this);
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
    public function dumpForLog(): array
    {
        return $this->all();
    }

    /**
     * A small, explicit snapshot of the config defaults needed before a
     * real `config` table exists to load from --
     * Controller\Admin\ConfigurationSubController::orderByIsLocal() builds
     * a bare `$conf` array from this, then overlays a site's
     * local/config/config.inc.php on top. NOT a general mechanism: these
     * are exactly the keys that real caller reads.
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
     * declared default, reflectively.
     */
    public function reset(): void
    {
        $reflection = new ReflectionClass($this);
        foreach ($reflection->getProperties(ReflectionProperty::IS_PRIVATE) as $property) {
            // Same isStatic() guard as all() above.
            if ($property->isStatic()) {
                continue;
            }
            $property->setValue($this, $property->getDefaultValue());
        }
    }
}
