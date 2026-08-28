<?php

declare(strict_types=1);

namespace Piwigo\Config;

use Piwigo\Common\Enum\SortOrder;
use Piwigo\Common\ValueObject\PhotoSortOrder;
use Piwigo\Core\AppInfo;
use Piwigo\Core\Env;
use ReflectionClass;

/**
 * Typed facade over Piwigo's runtime configuration. Every real config key is
 * a real typed property (PHP 8.4 property hooks + asymmetric visibility) --
 * there is no generic string-keyed surface (no override()/has()/delete()/
 * loadArray()), and no separate getter/setter method pair to hand-maintain
 * alongside it. A property is `public` when something outside this class
 * legitimately writes it at runtime (an in-memory-only override, not a DB
 * write -- see ConfigService for that); `public private(set)` when nothing
 * outside this class does; and carries a `set`/`get` hook only when reading
 * or writing it involves real logic (sanitizing untrusted input, a
 * SAPI-dependent default, a lazily-built value object). DB-backed
 * persistence is a two-part split: this class is the typed read/in-memory-
 * write layer; Piwigo\Config\ConfigService is the DI/Doctrine-backed
 * persistence layer, constructor-injected where possible and reached via
 * Piwigo\Config\CurrentConfigService::get() everywhere else.
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
 * Adding a new key: add a property by hand (matching the shape of any
 * neighboring one) -- there is no schema/generator to run. loadConfFromDb()
 * discovers every property reflectively, so a new property is picked up
 * automatically as long as its name matches a real `config` table row.
 */
final class CurrentConfig
{
    // === activate_comments ===
    /**
     * Enable or disable user comments on photos gallery-wide.
     */
    public bool $activateComments = true;

    // === activity_display_connections ===
    /**
     * Which connection events to show in the activity log: all, admin, or none.
     */
    public private(set) string $activityDisplayConnections = 'all';

    // === add_cache_to_storage_chart ===
    /**
     * Include cache files in the storage usage chart on the dashboard.
     */
    public private(set) bool $addCacheToStorageChart = true;

    // === admin_theme ===
    /**
     * Site-wide fallback admin theme (clear, default, or roma) used when a user
     * has no admin_theme preference of their own yet.
     */
    public private(set) string $adminTheme = 'clear';

    // === album_description_on_all_pages ===
    /**
     * Show the album description on every paginated page, not just the first.
     */
    public private(set) bool $albumDescriptionOnAllPages = false;

    // === album_move_delay_before_auto_opening ===
    /**
     * Milliseconds to wait before auto-expanding an album drop-target during
     * drag-and-drop.
     */
    public private(set) int $albumMoveDelayBeforeAutoOpening = 3000;

    // === allow_html_descriptions ===
    /**
     * Allow HTML markup in photo and album descriptions.
     */
    public private(set) bool $allowHtmlDescriptions = true;

    // === allow_html_in_metadata ===
    /**
     * Allow HTML in metadata values extracted from photo files.
     */
    public bool $allowHtmlInMetadata = false;

    // === allow_random_representative ===
    /**
     * Allow a random photo to represent an album that has no explicit
     * representative set.
     */
    public bool $allowRandomRepresentative = false;

    // === allow_user_customization ===
    /**
     * Let registered users change their own display preferences.
     */
    public private(set) bool $allowUserCustomization = true;

    // === allow_user_registration ===
    /**
     * Allow new users to self-register from the public gallery.
     */
    public private(set) bool $allowUserRegistration = true;

    // === allow_web_services ===
    /**
     * Enable the Piwigo web-service (API) endpoint.
     */
    public private(set) bool $allowWebServices = true;

    // === alternative_pem_url ===
    /**
     * Override URL for the Piwigo Extensions Manager repository.
     */
    public string $alternativePemUrl = '';

    // === animated_webp_compression_quality ===
    /**
     * Quality level (1-100) for animated WebP derivative encoding.
     */
    public int $animatedWebpCompressionQuality = 70;

    // === anti-flood_time ===
    /**
     * Minimum seconds between comment posts from the same user to prevent spam.
     */
    public int $antiFloodTime = 60;

    // === auth_key_duration ===
    /**
     * Lifetime in seconds for single-use authentication keys sent in emails.
     */
    public int $authKeyDuration = 259200;

    // === authorize_remembering ===
    /**
     * Allow users to use the remember-me persistent login cookie.
     */
    public private(set) bool $authorizeRemembering = true;

    // === batch_manager_images_per_page_global ===
    /**
     * Number of photos shown per page in the batch-manager global view.
     */
    public private(set) int $batchManagerImagesPerPageGlobal = 20;

    // === batch_manager_images_per_page_unit ===
    /**
     * Number of photos shown per page in the batch-manager unit view.
     */
    public private(set) int $batchManagerImagesPerPageUnit = 5;

    // === browser_language ===
    /**
     * Automatically detect and use the visitor browser language preference.
     */
    public bool $browserLanguage = true;

    // === cache.backend ===
    /**
     * Cache driver to use: file or redis.
     */
    public private(set) string $cacheBackend = 'file';

    // === cache.default_ttl ===
    /**
     * Default cache entry time-to-live in seconds.
     */
    public private(set) int $cacheDefaultTtl = 86400;

    // === cache.namespace ===
    /**
     * Namespace prefix for all cache keys, useful when sharing a Redis instance.
     */
    public private(set) string $cacheNamespace = '';

    // === cache.redis_url ===
    /**
     * Redis connection DSN used when cache.backend is redis.
     */
    public private(set) string $cacheRedisUrl = 'redis://localhost:6379';

    // === calendar_datefield ===
    /**
     * Date field used for the calendar view: date_creation or date_available.
     */
    public string $calendarDatefield = 'date_creation';

    // === calendar_show_any ===
    /**
     * Show an Any link in the calendar so visitors can view photos without a date
     * filter.
     */
    public private(set) bool $calendarShowAny = true;

    // === calendar_show_empty ===
    /**
     * Show months and years with no photos in the calendar navigation.
     */
    public private(set) bool $calendarShowEmpty = true;

    // === category_url_style ===
    /**
     * URL format for album links: id or id-name.
     */
    public string $categoryUrlStyle = 'id';

    // === checksum_compute_blocksize ===
    /**
     * Number of photos per block when computing file checksums in batch.
     */
    public private(set) int $checksumComputeBlocksize = 50;

    // === comment_spam_max_links ===
    /**
     * Maximum number of links allowed in a single comment before it is rejected as
     * spam.
     */
    public int $commentSpamMaxLinks = 3;

    // === comment_spam_reject ===
    /**
     * Silently reject comments that exceed the spam link threshold.
     */
    public bool $commentSpamReject = true;

    // === comments_author_mandatory ===
    /**
     * Require commenters to supply an author name.
     */
    public bool $commentsAuthorMandatory = false;

    // === comments_email_mandatory ===
    /**
     * Require commenters to supply an email address.
     */
    public bool $commentsEmailMandatory = false;

    // === comments_enable_website ===
    /**
     * Show a website field in the comment form.
     */
    public bool $commentsEnableWebsite = true;

    // === comments_forall ===
    /**
     * Allow unauthenticated (guest) visitors to post comments.
     */
    public bool $commentsForall = false;

    // === comments_order ===
    /**
     * Sort order for comment display: ASC (oldest first) or DESC (newest first).
     */
    public private(set) string $commentsOrder = SortOrder::Asc->value;

    // === comments_page_nb_comments ===
    /**
     * Number of comments shown per page on the admin comments page.
     */
    public private(set) int $commentsPageNbComments = 10;

    // === comments_validation ===
    /**
     * Require admin approval before newly posted comments appear publicly.
     */
    public bool $commentsValidation = false;

    // === compiled_template_cache_language ===
    /**
     * Include the active language in the compiled-template cache key.
     */
    public bool $compiledTemplateCacheLanguage = false;

    // === content_tag_cloud_items_number ===
    /**
     * Maximum number of tags shown in the content-area tag cloud.
     */
    public private(set) int $contentTagCloudItemsNumber = 12;

    // === count_orphans ===
    /**
     * Cached count of images belonging to no album; null means "not computed
     * yet" (recomputed lazily by ImageService::countOrphans(), invalidated by
     * PermissionCacheInvalidator).
     */
    public ?int $countOrphans = null;

    // === dashboard_activity_nb_weeks ===
    /**
     * Number of weeks of activity data shown on the admin dashboard.
     */
    public private(set) int $dashboardActivityNbWeeks = 4;

    // === dashboard_check_for_updates ===
    /**
     * Check for Piwigo core updates on the admin dashboard.
     */
    public private(set) bool $dashboardCheckForUpdates = true;

    // === data_dir_checked ===
    /**
     * Presence-only marker: once set (to '1'), Template's data-directory
     * writability check is permanently skipped. Genuine absence until the check
     * first passes, matching the gallery_url/last_major_update convention.
     */
    public ?string $dataDirChecked = null;

    // === data_location ===
    /**
     * Relative path from the Piwigo root to the writable data directory.
     */
    public string $dataLocation = '_data/';

    // === debug_l10n ===
    /**
     * Highlight untranslated strings in the UI for l10n debugging.
     */
    public bool $debugL10n = false;

    // === debug_mail ===
    /**
     * Log all outgoing mail to a file instead of sending.
     */
    public bool $debugMail = false;

    // === debug_template ===
    /**
     * Add template debugging information to rendered pages.
     */
    public bool $debugTemplate = false;

    // === default_redirect_method ===
    /**
     * HTTP redirect method Piwigo uses internally: http or html.
     */
    public string $defaultRedirectMethod = 'http';

    // === default_user_id ===
    /**
     * User ID whose settings serve as defaults for new accounts.
     */
    public int $defaultUserId = 2;

    // === derivative_default_size ===
    /**
     * Default derivative size name served when no size is specified.
     */
    public private(set) string $derivativeDefaultSize = 'medium';

    // === derivative_url_style ===
    /**
     * Derivative URL format: 0 = auto (static link if already cached, else routed
     * through i.php), 1 = always a static link, 2 = always routed through i.php.
     */
    public int $derivativeUrlStyle = 2;

    // === derivatives_strip_metadata_threshold ===
    /**
     * File size in bytes above which EXIF/IPTC metadata is stripped from
     * derivatives.
     */
    public private(set) int $derivativesStripMetadataThreshold = 256000;

    // === die_on_sql_error ===
    /**
     * Halt execution immediately when a database query fails.
     */
    public private(set) bool $dieOnSqlError = false;

    // === display_fromto ===
    /**
     * Show the date range of photos in album and search results headers.
     */
    public bool $displayFromto = false;

    // === double_password_type_in_admin ===
    /**
     * Require admins to enter a new password twice when setting it.
     */
    public private(set) bool $doublePasswordTypeInAdmin = false;

    // === email_admin_on_comment ===
    /**
     * Send an email to the administrators when a valid comment is entered.
     */
    public bool $emailAdminOnComment = false;

    // === email_admin_on_comment_deletion ===
    /**
     * Send an email to the administrators when a comment is deleted.
     */
    public bool $emailAdminOnCommentDeletion = false;

    // === email_admin_on_comment_edition ===
    /**
     * Send an email to the administrators when a comment is modified.
     */
    public bool $emailAdminOnCommentEdition = false;

    // === email_admin_on_comment_validation ===
    /**
     * Send an email to the administrators when a comment requires validation.
     */
    public bool $emailAdminOnCommentValidation = true;

    // === email_admin_on_new_user ===
    /**
     * When to email the webmaster when a new user registers: none, all, or new.
     */
    public string $emailAdminOnNewUser = 'none';

    // === enable_core_update ===
    /**
     * Allow Piwigo core to be updated from the administration panel.
     */
    public bool $enableCoreUpdate = true;

    // === enable_extensions_install ===
    /**
     * Allow plugins and themes to be installed from the administration panel.
     */
    public bool $enableExtensionsInstall = true;

    // === enable_formats ===
    /**
     * Enable the multi-format photo feature (original plus additional formats).
     */
    public bool $isFormatsEnabled = false;

    // === enable_plugins ===
    /**
     * Load and activate installed plugins.
     */
    public bool $enablePlugins = true;

    // === enable_synchronization ===
    /**
     * Allow directory-to-database synchronization from the admin panel.
     */
    public bool $enableSynchronization = true;

    // === ext_imagick_dir ===
    /**
     * Filesystem path to the ImageMagick binary directory (leave empty to
     * auto-detect).
     */
    public string $extImagickDir = '';

    // === ffmpeg_dir ===
    /**
     * Filesystem path to the FFmpeg binary directory (leave empty to auto-detect).
     */
    public private(set) string $ffmpegDir = '';

    // === fs_quick_check_last_check ===
    /**
     * Timestamp of the last filesystem quick-check run.
     */
    public private(set) ?string $fsQuickCheckLastCheck = null;

    // === fs_quick_check_period ===
    /**
     * Interval in seconds between automatic filesystem quick-checks.
     */
    public int $fsQuickCheckPeriod = 86400;

    // === full_tag_cloud_items_number ===
    /**
     * Maximum number of tags shown on the full tag-cloud page.
     */
    public private(set) int $fullTagCloudItemsNumber = 200;

    // === gallery_locked ===
    /**
     * Lock the gallery for maintenance, blocking non-admin access.
     */
    public bool $galleryLocked = false;

    // === gallery_title ===
    /**
     * Title of the gallery shown in the browser tab and page header.
     */
    public string $galleryTitle = 'Piwigo';

    // === gallery_url ===
    /**
     * Public base URL of the gallery (overrides auto-detection when set).
     */
    public ?string $galleryUrl = null;

    // === graphics_library ===
    /**
     * Image processing backend: auto, gd, imagick, or ext_imagick.
     */
    public string $graphicsLibrary = 'auto';

    // === guest_access ===
    /**
     * Allow unauthenticated (guest) visitors to browse public photos.
     */
    public bool $guestAccess = true;

    // === guest_id ===
    /**
     * User ID of the built-in guest account used for unauthenticated sessions.
     */
    public int $guestId = 2;

    // === history_admin ===
    /**
     * Log page visits by admin users in the history table.
     */
    public private(set) bool $historyAdmin = false;

    // === history_autopurge_blocksize ===
    /**
     * Number of rows deleted per autopurge cycle from the history table.
     */
    public int $historyAutopurgeBlocksize = 50000;

    // === history_autopurge_every ===
    /**
     * Autopurge frequency: delete old history every N page loads (approximately).
     */
    public int $historyAutopurgeEvery = 1021;

    // === history_autopurge_keep_lines ===
    /**
     * Maximum number of history rows to retain after an autopurge.
     */
    public int $historyAutopurgeKeepLines = 1000000;

    // === history_guest ===
    /**
     * Log page visits by guest (unauthenticated) users in the history table.
     */
    public private(set) bool $historyGuest = false;

    // === index_caddie_icon ===
    /**
     * Show the add-to-caddie icon on album index pages.
     */
    public private(set) bool $indexCaddieIcon = true;

    // === index_created_date_icon ===
    /**
     * Show the creation-date icon on album index pages.
     */
    public private(set) bool $indexCreatedDateIcon = true;

    // === index_edit_icon ===
    /**
     * Show the quick-edit icon on album index pages (admins only).
     */
    public private(set) bool $indexEditIcon = true;

    // === index_flat_icon ===
    /**
     * Show the flat-view icon on album index pages.
     */
    public private(set) bool $indexFlatIcon = true;

    // === index_new_icon ===
    /**
     * Show the new badge icon on recently added photos in album index pages.
     */
    public private(set) bool $indexNewIcon = true;

    // === index_posted_date_icon ===
    /**
     * Show the posted-date icon on album index pages.
     */
    public private(set) bool $indexPostedDateIcon = true;

    // === index_search_in_set_action ===
    /**
     * Show the "search in this set" icon on album index pages.
     *
     * A bool, despite the name and despite the `'results'|'filter'`
     * behaviour its docblock used to claim: nothing anywhere compares it
     * to either of those words, the admin form lists it among
     * `$display_checkboxes`, and `index.latte` gates a single icon on it.
     * It was declared `string` and stored as the JSON string `"true"`,
     * which is truthy either way -- so the icon showed even when the
     * setting was off, and the admin checkbox always rendered ticked.
     */
    public private(set) bool $indexSearchInSetAction = true;

    // === index_search_in_set_button ===
    /**
     * Show the search-within-set button on album index pages.
     */
    public private(set) bool $indexSearchInSetButton = false;

    // === index_sizes_icon ===
    /**
     * Show the available-sizes icon on album index pages.
     */
    public private(set) bool $indexSizesIcon = true;

    // === index_slideshow_icon ===
    /**
     * Show the slideshow icon on album index pages.
     */
    public private(set) bool $indexSlideshowIcon = true;

    // === index_sort_order_input ===
    /**
     * Display the image order selection list on album index pages.
     */
    public private(set) bool $indexSortOrderInput = true;

    // === inheritance_by_default ===
    /**
     * Apply parent album permissions to newly created sub-albums by default.
     */
    public private(set) bool $inheritanceByDefault = false;

    // === insensitive_case_logon ===
    /**
     * Allow login with any letter-case variation of the username.
     */
    public bool $insensitiveCaseLogon = false;

    // === last_major_update ===
    /**
     * Timestamp of the last major Piwigo upgrade, used for change detection.
     */
    public private(set) ?string $lastMajorUpdate = null;

    // === level_separator ===
    /**
     * String used to separate album hierarchy levels in breadcrumb trails.
     */
    public private(set) string $levelSeparator = ' / ';

    // === light_album_manager_threshold ===
    /**
     * Album count above which the lightweight album manager UI is used.
     */
    public private(set) int $lightAlbumManagerThreshold = 10000;

    // === light_slideshow ===
    /**
     * Use the lightweight built-in slideshow instead of a plugin-based one.
     */
    public private(set) bool $lightSlideshow = true;

    // === linked_album_search_limit ===
    /**
     * Maximum albums returned when searching for albums to link a photo to.
     */
    public private(set) int $linkedAlbumSearchLimit = 100;

    // === log ===
    /**
     * Enable the application log.
     */
    public bool $logConf = false;

    // === log_archive_days ===
    /**
     * Number of days to keep archived log files before deletion.
     */
    public private(set) int $logArchiveDays = 30;

    // === log_dir ===
    /**
     * Directory (relative to the data location) where log files are written.
     */
    public private(set) string $logDir = '/logs';

    // === log_level ===
    /**
     * Minimum log severity to record: DEBUG, INFO, WARNING, or ERROR.
     */
    public private(set) string $logLevel = 'DEBUG';

    // === login_lockout_duration_minutes ===
    /**
     * Minutes a username/IP stays locked out after too many failed logins.
     */
    public private(set) int $loginLockoutDurationMinutes = 15;

    // === login_lockout_max_attempts ===
    /**
     * Failed logins allowed (per username, and separately per IP) within
     * the lockout window before AuthService::pwgLogin() starts rejecting
     * outright.
     */
    public int $loginLockoutMaxAttempts = 5;

    // === login_lockout_window_minutes ===
    /**
     * Rolling window, in minutes, over which failed logins are counted
     * towards the lockout threshold.
     */
    public private(set) int $loginLockoutWindowMinutes = 15;

    // === lounge_activate_threshold ===
    /**
     * Number of photos in the lounge that triggers automatic album creation.
     */
    public int $loungeActivateThreshold = 1;

    // === lounge_active ===
    /**
     * Enable the lounge feature (a staging area for uploaded photos).
     */
    public bool $loungeActive = false;

    // === lounge_max_duration ===
    /**
     * Maximum seconds a photo can stay in the lounge before auto-processing.
     */
    public int $loungeMaxDuration = 300;

    // === mail_allow_html ===
    /**
     * Send emails in HTML format in addition to plain text.
     */
    public bool $mailAllowHtml = true;

    // === mail_sender_email ===
    /**
     * From email address used for all outgoing Piwigo emails.
     */
    public string $mailSenderEmail = '';

    // === mail_sender_name ===
    /**
     * Display name shown as the email sender in outgoing Piwigo emails.
     */
    public string $mailSenderName = '';

    // === mail_theme ===
    /**
     * Visual theme for HTML notification emails: light or dark.
     */
    public string $mailTheme = 'light';

    // === max_requests ===
    /**
     * Maximum concurrent HTTP requests Piwigo will make to external services.
     */
    public private(set) int $maxRequests = 3;

    // === menubar_filter_icon ===
    /**
     * Show the filter icon in the sidebar menu.
     */
    public bool $menubarFilterIcon = true;

    // === menubar_tag_cloud_content ===
    /**
     * Which tags to show in the sidebar tag cloud: all_or_current or current.
     */
    public private(set) string $menubarTagCloudContent = 'all_or_current';

    // === menubar_tag_cloud_items_number ===
    /**
     * Maximum number of tags shown in the sidebar tag cloud.
     */
    public private(set) int $menubarTagCloudItemsNumber = 20;

    // === meta_ref ===
    /**
     * Emit a referrer meta tag allowing search engines to attribute traffic.
     */
    public bool $metaRef = true;

    // === mobile_theme ===
    /**
     * Theme name applied automatically when a mobile browser is detected.
     */
    public string $mobileTheme = '';

    // === nb_categories_page ===
    /**
     * Maximum albums shown per page in admin album listings.
     */
    public private(set) int $nbCategoriesPage = 9999;

    // === nb_comment_page ===
    /**
     * Number of comments per page on the public photo detail page.
     */
    public int $nbCommentPage = 10;

    // === nb_logs_page ===
    /**
     * Number of history entries shown per page in the admin history view.
     */
    public private(set) int $nbLogsPage = 300;

    // === nbm_complementary_mail_content ===
    /**
     * Extra HTML appended to notification-by-mail digest emails.
     */
    public string $nbmComplementaryMailContent = '';

    // === nbm_default_value_user_enabled ===
    /**
     * Subscribe new users to notification-by-mail digests by default.
     */
    public private(set) bool $nbmDefaultValueUserEnabled = false;

    // === nbm_list_all_enabled_users_to_send ===
    /**
     * Show all subscribed users in the NBM send UI, not just those with pending
     * notifications.
     */
    public bool $nbmListAllEnabledUsersToSend = false;

    // === nbm_send_detailed_content ===
    /**
     * Include photo thumbnails and descriptions in NBM digest emails.
     */
    public bool $nbmSendDetailedContent = true;

    // === nbm_send_html_mail ===
    /**
     * Send NBM digest emails in HTML format.
     */
    public private(set) bool $nbmSendHtmlMail = true;

    // === nbm_send_mail_as ===
    /**
     * Override the From display name used specifically for NBM emails.
     */
    public string $nbmSendMailAs = '';

    // === nbm_send_recent_post_dates ===
    /**
     * Include recent-post date ranges in NBM digest emails.
     */
    public private(set) bool $nbmSendRecentPostDates = true;

    // === nbm_treatment_timeout_default ===
    /**
     * Default timeout in seconds for a single NBM send-batch execution.
     */
    public int $nbmTreatmentTimeoutDefault = 20;

    // === never_delete_originals ===
    /**
     * Prevent deletion of original image files when a photo is removed.
     */
    public bool $neverDeleteOriginals = false;

    // === newcat_default_commentable ===
    /**
     * Make newly created albums commentable by default.
     */
    public private(set) bool $newcatDefaultCommentable = true;

    // === newcat_default_position ===
    /**
     * Insert position for new sub-albums: first or last.
     */
    public string $newcatDefaultPosition = 'first';

    // === newcat_default_status ===
    /**
     * Default visibility for new albums: public or private.
     */
    public private(set) string $newcatDefaultStatus = 'public';

    // === newcat_default_visible ===
    /**
     * Make newly created albums visible by default.
     */
    public private(set) bool $newcatDefaultVisible = true;

    // === no_photo_yet ===
    /**
     * Presence-only marker: once set (to 'false'), NoPhotoYetRenderer's first-run
     * banner is permanently suppressed. Genuine absence on a fresh install/reset
     * -- callers check noPhotoYet() === null to detect first-run state, matching
     * the gallery_url/last_major_update convention.
     */
    public private(set) ?string $noPhotoYet = null;

    // === no_photo_yet_url ===
    /**
     * Admin URL linked from the no-photos-yet placeholder shown to admins.
     */
    public private(set) string $noPhotoYetUrl = 'admin.php?page=photos_add';

    // === obligatory_user_mail_address ===
    /**
     * Require an email address for all user registrations.
     */
    public bool $obligatoryUserMailAddress = false;

    // === original_resize ===
    /**
     * Resize uploaded originals that exceed the configured maximum dimensions.
     */
    public bool $originalResize = false;

    // === original_resize_maxheight ===
    /**
     * Maximum pixel height for uploaded originals when resize is enabled.
     */
    public int $originalResizeMaxheight = 2000;

    // === original_resize_maxwidth ===
    /**
     * Maximum pixel width for uploaded originals when resize is enabled.
     */
    public int $originalResizeMaxwidth = 2000;

    // === original_resize_quality ===
    /**
     * JPEG quality (1-100) used when resizing uploaded originals.
     */
    public int $originalResizeQuality = 95;

    // === original_url_protection ===
    /**
     * Original-file URL protection mode: empty (none), images, or all.
     */
    public string $originalUrlProtection = '';

    // === page_banner ===
    /**
     * HTML banner content displayed at the top of public gallery pages.
     */
    public private(set) string $pageBanner = '';

    // === paginate_pages_around ===
    /**
     * Number of page-number links shown on each side of the current page in
     * pagination.
     */
    public int $paginatePagesAround = 2;

    // === password_activation_duration ===
    /**
     * Seconds a password-activation link emailed to new users remains valid.
     */
    public private(set) int $passwordActivationDuration = 259200;

    // === password_reset_code_duration ===
    /**
     * Seconds a password-reset verification code is valid.
     */
    public private(set) int $passwordResetCodeDuration = 300;

    // === password_reset_duration ===
    /**
     * Seconds a password-reset link emailed to a user remains valid.
     */
    public private(set) int $passwordResetDuration = 3600;

    // === password_reset_request_ip_max_attempts ===
    /**
     * [P44-L] The IP-scoped sibling to passwordResetRequestMaxAttempts --
     * deliberately much looser than the per-account ceiling, same
     * reasoning as most IP-based abuse limits: a shared office/household
     * IP can legitimately request resets for several different accounts
     * within one window, so this is a backstop against one source
     * flooding many different targets, not the primary defense (that's
     * the tighter per-account ceiling, which catches "one victim's inbox
     * is being flooded" directly).
     */
    public int $passwordResetRequestIpMaxAttempts = 20;

    // === password_reset_request_max_attempts ===
    /**
     * [P44-L] Verification-code *requests* allowed per account within the
     * rolling window before PasswordController::processVerificationCode()
     * starts rejecting outright -- the request-scoped sibling to
     * loginLockoutMaxAttempts, closing the gap where nothing previously
     * limited how often a fresh code could be requested (only the
     * per-code 3-attempt guess lockout was limited). See
     * passwordResetRequestIpMaxAttempts for the separate, looser
     * IP-scoped ceiling.
     */
    public int $passwordResetRequestMaxAttempts = 3;

    // === password_reset_request_window_minutes ===
    /**
     * Rolling window, in minutes, over which reset-code requests are
     * counted towards passwordResetRequestMaxAttempts.
     */
    public private(set) int $passwordResetRequestWindowMinutes = 60;

    // === pdf_jpg_quality ===
    /**
     * JPEG quality used when Imagick renders a PDF's representative image.
     */
    public int $pdfJpgQuality = 90;

    // === pdf_representative_ext ===
    /**
     * File extension used for a PDF's rendered representative image.
     */
    public private(set) string $pdfRepresentativeExt = 'jpg';

    // === pdf_viewer_filesize_threshold ===
    /**
     * Maximum PDF file size in MB to display inline; larger files show a download
     * link.
     */
    public private(set) int $pdfViewerFilesizeThreshold = 5;

    // === pem_languages_category ===
    /**
     * PEM (Piwigo Extensions Manager) category ID for language packs.
     */
    public private(set) int $pemLanguagesCategory = 8;

    // === pem_plugins_category ===
    /**
     * PEM category ID for plugins.
     */
    public private(set) int $pemPluginsCategory = 12;

    // === pem_themes_category ===
    /**
     * PEM category ID for themes.
     */
    public private(set) int $pemThemesCategory = 10;

    // === php_extension_in_urls ===
    /**
     * Include the .php extension in generated picture/category URLs. Works only
     * with Options +MultiViews or URL rewriting active.
     */
    public bool $phpExtensionInUrls = true;

    // === picture_caddie_icon ===
    /**
     * Show the add-to-caddie icon on the photo detail page.
     */
    public private(set) bool $pictureCaddieIcon = true;

    // === picture_download_icon ===
    /**
     * Show the download icon on the photo detail page.
     */
    public private(set) bool $pictureDownloadIcon = true;

    // === picture_edit_icon ===
    /**
     * Show the quick-edit icon on the photo detail page (admins only).
     */
    public private(set) bool $pictureEditIcon = true;

    // === picture_favorite_icon ===
    /**
     * Show the add-to-favorites icon on the photo detail page.
     */
    public private(set) bool $pictureFavoriteIcon = true;

    // === picture_menu ===
    /**
     * Show the navigation menu on the photo detail page.
     */
    public private(set) bool $pictureMenu = true;

    // === picture_metadata_icon ===
    /**
     * Show the metadata icon on the photo detail page.
     */
    public private(set) bool $pictureMetadataIcon = true;

    // === picture_navigation_icons ===
    /**
     * Show previous/next navigation arrows on the photo detail page.
     */
    public private(set) bool $pictureNavigationIcons = true;

    // === picture_navigation_thumb ===
    /**
     * Show previous/next thumbnail previews on the photo detail page.
     */
    public private(set) bool $pictureNavigationThumb = true;

    // === picture_representative_icon ===
    /**
     * Show the set-as-album-representative icon on the photo detail page.
     */
    public private(set) bool $pictureRepresentativeIcon = true;

    // === picture_sizes_icon ===
    /**
     * Show the available-sizes icon on the photo detail page.
     */
    public private(set) bool $pictureSizesIcon = true;

    // === picture_slideshow_icon ===
    /**
     * Show the slideshow icon on the photo detail page.
     */
    public private(set) bool $pictureSlideshowIcon = true;

    // === picture_url_style ===
    /**
     * URL format for photo links: id or id-file.
     */
    public string $pictureUrlStyle = 'id';

    // === piwigo_installed_version ===
    /**
     * Full Piwigo version string recorded at the time of the last upgrade.
     */
    public private(set) ?string $piwigoInstalledVersion = null;

    // === proxy_auth ===
    /**
     * Credentials (user:password) for HTTP proxy authentication.
     */
    public string $proxyAuth = '';

    // === proxy_server ===
    /**
     * HTTP proxy server URL used for outgoing connections from Piwigo.
     */
    public string $proxyServer = '';

    // === question_mark_in_urls ===
    /**
     * Include a ? in generated URLs. Can only be set false when the server
     * translates PATH_INFO (AcceptPathInfo).
     */
    public bool $questionMarkInUrls = true;

    // === quick_search_include_sub_albums ===
    /**
     * Include photos from sub-albums in quick-search results.
     */
    public bool $quickSearchIncludeSubAlbums = false;

    // === rate ===
    /**
     * Enable the photo rating feature.
     */
    public bool $rateEnabled = true;

    // === rate_anonymous ===
    /**
     * Allow guest (unauthenticated) visitors to rate photos.
     */
    public bool $rateAnonymous = true;

    // === related_albums_display_limit ===
    /**
     * Maximum number of related albums shown on the photo detail page.
     */
    public private(set) int $relatedAlbumsDisplayLimit = 20;

    // === related_albums_maximum_items_to_compute ===
    /**
     * Maximum photos considered when computing related albums.
     */
    public private(set) int $relatedAlbumsMaximumItemsToCompute = 1000;

    // === remember_me_length ===
    /**
     * Lifetime in seconds of the remember-me persistent login cookie.
     */
    public private(set) int $rememberMeLength = 5184000;

    // === remember_me_name ===
    /**
     * Cookie name used for the remember-me persistent login token.
     */
    public private(set) string $rememberMeName = 'pwg_remember';

    // === representative_cache_on_level ===
    /**
     * Cache the album representative photo when permission level changes.
     */
    public private(set) bool $representativeCacheOnLevel = true;

    // === representative_cache_on_subcats ===
    /**
     * Rebuild album representative thumbnails when sub-album content changes.
     */
    public private(set) bool $representativeCacheOnSubcats = true;

    // === rss_feed_author ===
    /**
     * Author name shown in the gallery RSS feed.
     */
    public private(set) string $rssFeedAuthor = 'Piwigo notifier';

    // === secret_key ===
    /**
     * Random string used to sign CSRF tokens and internal hashes.
     */
    #[Required]
    #[Sensitive]
    public string $secretKey = '';

    // === send_bcc_mail_webmaster ===
    /**
     * BCC the webmaster address on every outgoing notification email.
     */
    public bool $sendBccMailWebmaster = false;

    // === send_piwigo_infos ===
    /**
     * Allow Piwigo to send anonymous usage statistics to the Piwigo project.
     */
    public bool $sendPiwigoInfos = true;

    // === send_piwigo_infos_last_notice ===
    /**
     * Date the admin was last shown the usage-statistics opt-in notice.
     */
    public private(set) ?string $sendPiwigoInfosLastNotice = null;

    // === send_piwigo_infos_origin_hash ===
    /**
     * Anonymous installation hash included in usage statistics.
     */
    public private(set) ?string $sendPiwigoInfosOriginHash = null;

    // === send_piwigo_infos_period ===
    /**
     * Minimum seconds between two "send Piwigo infos" telemetry pings.
     */
    public private(set) int $sendPiwigoInfosPeriod = 7 * 24 * 60 * 60;

    // === send_piwigo_infos_update_url ===
    /**
     * Base URL the "send Piwigo infos" telemetry ping is posted to.
     */
    public private(set) string $sendPiwigoInfosUpdateUrl = AppInfo::URL;

    // === session_gc_probability ===
    /**
     * Probability weight (out of 100) that a PHP session GC run is triggered per
     * request.
     */
    public private(set) int $sessionGcProbability = 1;

    // === session_length ===
    /**
     * PHP session lifetime in seconds (sets cookie_lifetime and gc_maxlifetime).
     */
    public int $sessionLength = 3600;

    // === session_name ===
    /**
     * PHP session cookie name used by Piwigo.
     */
    public private(set) string $sessionName = 'pwg_id';

    // === session_save_handler ===
    /**
     * Session storage backend: db (database) or files.
     */
    public private(set) string $sessionSaveHandler = 'db';

    // === session_use_cookies ===
    /**
     * Store the session ID in a cookie (PHP session.use_cookies).
     */
    public private(set) bool $sessionUseCookies = true;

    // === session_use_ip_address ===
    /**
     * Bind sessions to the client IP address to reduce session-hijacking risk.
     */
    public bool $sessionUseIpAddress = true;

    // === session_use_only_cookies ===
    /**
     * Reject session IDs passed in the URL; require cookie only (PHP
     * session.use_only_cookies).
     */
    public private(set) bool $sessionUseOnlyCookies = true;

    // === session_use_trans_sid ===
    /**
     * Allow the session ID to be transmitted in the URL query string (PHP
     * session.use_trans_sid).
     */
    public private(set) bool $sessionUseTransSid = false;

    // === show_exif ===
    /**
     * Display EXIF metadata on the photo detail page.
     */
    public bool $showExif = true;

    // === show_gt ===
    /**
     * Show the Go-to navigation widget on photo detail pages.
     */
    public bool $showGt = false;

    // === show_iptc ===
    /**
     * Display IPTC metadata on the photo detail page.
     */
    public bool $showIptc = false;

    // === show_mobile_app_banner_in_admin ===
    /**
     * Show the "get the mobile app" banner while browsing the admin.
     */
    public private(set) bool $showMobileAppBannerInAdmin = true;

    // === show_mobile_app_banner_in_gallery ===
    /**
     * Show the "get the mobile app" banner while browsing the gallery.
     */
    public private(set) bool $showMobileAppBannerInGallery = false;

    // === show_newsletter_subscription ===
    /**
     * Show the newsletter subscription link in the gallery menu.
     */
    public private(set) bool $showNewsletterSubscription = true;

    // === show_piwigo_latest_news ===
    /**
     * Show the latest Piwigo project news on the admin dashboard.
     */
    public private(set) bool $showPiwigoLatestNews = true;

    // === show_queries ===
    /**
     * Append executed SQL queries to the page HTML for debugging.
     */
    public bool $showQueries = false;

    // === show_thumbnail_caption ===
    /**
     * Show the photo title below thumbnails in album index pages.
     */
    public private(set) bool $showThumbnailCaption = true;

    // === show_version ===
    /**
     * Display the Piwigo version string in the page footer and emails.
     */
    public bool $showVersion = false;

    // === slideshow_period ===
    /**
     * Default interval in seconds between photos in the slideshow.
     */
    public int $slideshowPeriod = 4;

    // === slideshow_period_max ===
    /**
     * Maximum selectable interval in seconds for the slideshow.
     */
    public int $slideshowPeriodMax = 10;

    // === slideshow_period_min ===
    /**
     * Minimum selectable interval in seconds for the slideshow.
     */
    public int $slideshowPeriodMin = 1;

    // === slideshow_period_step ===
    /**
     * Step size in seconds for the slideshow interval selector.
     */
    public private(set) int $slideshowPeriodStep = 1;

    // === slideshow_repeat ===
    /**
     * Loop the slideshow back to the first photo after the last.
     */
    public bool $slideshowRepeat = true;

    // === smtp_host ===
    /**
     * SMTP server hostname (and optional port) for outgoing email.
     */
    public string $smtpHost = '';

    // === smtp_password ===
    /**
     * SMTP authentication password.
     */
    #[Sensitive]
    public string $smtpPassword = '';

    // === smtp_secure ===
    /**
     * SMTP connection security: null (none), ssl, or tls.
     */
    public ?string $smtpSecure = null;

    // === smtp_user ===
    /**
     * SMTP authentication username.
     */
    public string $smtpUser = '';

    // === standard_pages_selected_logo ===
    /**
     * Which logo the "standard pages" theme fallback displays: 'piwigo_logo',
     * 'custom_logo', 'gallery_title', or 'none'.
     */
    public private(set) string $standardPagesSelectedLogo = 'piwigo_logo';

    // === standard_pages_selected_logo_path ===
    /**
     * Disk-relative path (under the 'local' disk) of the uploaded custom logo
     * used by the "standard pages" theme fallback; null until one is uploaded.
     */
    public private(set) ?string $standardPagesSelectedLogoPath = null;

    // === standard_pages_selected_skin ===
    /**
     * Skin used by the "standard pages" theme fallback (login/register/...).
     */
    public private(set) string $standardPagesSelectedSkin = 'default';

    // === stat_compare_year_displayed ===
    /**
     * Number of years of photo statistics shown in the comparison chart.
     */
    public private(set) int $statCompareYearDisplayed = 5;

    // === tag_letters_column_number ===
    /**
     * Number of columns in the alphabetical tag index layout.
     */
    public private(set) int $tagLettersColumnNumber = 4;

    // === tag_url_style ===
    /**
     * URL format for tag links: id, tag, or id-tag.
     */
    public string $tagUrlStyle = 'id-tag';

    // === tags_default_display_mode ===
    /**
     * Default tag-listing display mode: cloud or letters.
     */
    public private(set) string $tagsDefaultDisplayMode = 'cloud';

    // === tags_levels ===
    /**
     * Number of font-size levels used in the tag cloud.
     */
    public int $tagsLevels = 5;

    // === template_combine_files ===
    /**
     * Merge JavaScript/CSS files together at render time to reduce the number of
     * HTTP requests.
     */
    public bool $templateCombineFiles = true;

    // === template_compile_check ===
    /**
     * Recompile Latte templates when source files change (disable in production).
     */
    public bool $templateCompileCheck = true;

    // === template_force_compile ===
    /**
     * Always recompile Latte templates on every request.
     */
    public private(set) bool $templateForceCompile = false;

    // === themes_dir ===
    /**
     * Root-relative path to the directory containing installed themes (compose
     * with a real, constructor-injected Paths::$root for an absolute
     * filesystem path).
     */
    public string $themesDir = 'themes/';

    // === tiff_representative_ext ===
    /**
     * Image extension used when generating a representative for TIFF originals.
     */
    public string $tiffRepresentativeExt = 'png';

    // === top_number ===
    /**
     * Number of items shown in top ranking lists (most visited, best rated, etc.).
     */
    public private(set) int $topNumber = 15;

    // === trusted_proxies ===
    /**
     * Comma-separated CIDR list of reverse proxies whose forwarded headers are
     * trusted.
     */
    public private(set) string $trustedProxies = '';

    // === uniqueness_mode ===
    /**
     * Algorithm used to detect duplicate uploads: md5sum or filename.
     */
    public private(set) string $uniquenessMode = 'md5sum';

    // === update_notify_check_period ===
    /**
     * Interval in seconds between automatic checks for Piwigo updates.
     */
    public int $updateNotifyCheckPeriod = 86400;

    // === update_notify_last_check ===
    /**
     * Timestamp of the last update-availability check.
     */
    public ?string $updateNotifyLastCheck = null;

    // === update_notify_reminder_period ===
    /**
     * Interval in seconds between repeated update reminder notifications.
     */
    public private(set) int $updateNotifyReminderPeriod = 604800;

    // === upload_detect_duplicate ===
    /**
     * Check for duplicate photos by checksum when uploading.
     */
    public bool $uploadDetectDuplicate = true;

    // === upload_dir ===
    /**
     * Root-relative path to the directory where uploaded files are stored (compose
     * with a real, constructor-injected Paths::$root for an absolute
     * filesystem path).
     */
    public string $uploadDir = 'upload/';

    // === upload_form_all_types ===
    /**
     * Allow uploading any file type, not just images and videos.
     */
    public bool $uploadFormAllTypes = false;

    // === upload_form_automatic_rotation ===
    /**
     * Automatically rotate uploaded photos based on their EXIF orientation tag.
     */
    public private(set) bool $uploadFormAutomaticRotation = true;

    // === upload_form_chunk_size ===
    /**
     * Chunk size in KB for multi-part file uploads via the upload form.
     */
    public private(set) int $uploadFormChunkSize = 500;

    // === upload_form_max_file_size ===
    /**
     * Maximum file size in MB accepted by the upload form.
     */
    public private(set) int $uploadFormMaxFileSize = 1000;

    // === url_port ===
    /**
     * Port included in generated URLs: none, or a port number string.
     */
    public string $urlPort = 'none';

    // === use_exif ===
    /**
     * Read EXIF metadata from uploaded photos and store it in the database.
     */
    public bool $useExif = true;

    // === use_iptc ===
    /**
     * Read IPTC metadata from uploaded photos and store it in the database.
     */
    public bool $useIptc = false;

    // === use_proxy ===
    /**
     * Send outgoing HTTP requests from Piwigo through a proxy server.
     */
    public bool $useProxy = false;

    // === use_standard_pages ===
    /**
     * Whether the current theme falls back to Piwigo's own "standard pages"
     * (login/register/forgot-password/...) instead of its own.
     */
    public bool $useStandardPages = true;

    // === user_can_delete_comment ===
    /**
     * Allow a registered user to delete their own comments.
     */
    public bool $userCanDeleteComment = false;

    // === user_can_edit_comment ===
    /**
     * Allow a registered user to edit their own comments.
     */
    public bool $userCanEditComment = false;

    // === webmaster_id ===
    /**
     * User ID of the designated webmaster account.
     */
    public int $webmasterId = 1;

    // === week_starts_on ===
    /**
     * First day of the week in calendar views: monday or sunday.
     */
    public string $weekStartsOn = 'monday';

    // === api_key_duration ===
    /**
     * Selectable API-key expiration presets, in days (plus the literal 'custom' entry).
     * @var list<string>
     */
    public array $apiKeyDuration = ['30', '90', '180', '365', 'custom'] {
        /**
         * @param array<mixed> $value
         */
        set(array $value) {
            $this->apiKeyDuration = self::sanitizeApiKeyDuration($value);
        }
    }

    /**
     * @param array<mixed> $value
     * @return list<string>
     */
    private static function sanitizeApiKeyDuration(array $value): array
    {
        return array_values(array_map(static fn (mixed $x): string => is_scalar($x) || $x === null ? (string) $x : '', $value));
    }

    // === api_key_forbidden_methods ===
    /**
     * Web-service method names that API-key callers are not allowed to invoke.
     * @var list<string>
     */
    public array $apiKeyForbiddenMethods = ['pwg.users.generatePasswordLink', 'pwg.users.getAuthKey', 'pwg.users.setMainUser', 'pwg.users.setInfo', 'pwg.plugins.performAction', 'pwg.themes.performAction', 'pwg.extensions.ignoreUpdate', 'pwg.extensions.update'] {
        /**
         * @param array<mixed> $value
         */
        set(array $value) {
            $this->apiKeyForbiddenMethods = self::sanitizeApiKeyForbiddenMethods($value);
        }
    }

    /**
     * @param array<mixed> $value
     * @return list<string>
     */
    private static function sanitizeApiKeyForbiddenMethods(array $value): array
    {
        return array_values(array_map(static fn (mixed $x): string => is_scalar($x) || $x === null ? (string) $x : '', $value));
    }

    // === available_permission_levels ===
    /**
     * Ordered list of numeric permission levels visible in the UI.
     * @var list<int>
     */
    public array $availablePermissionLevels = [0, 1, 2, 4, 8] {
        /**
         * @param array<mixed> $value
         */
        set(array $value) {
            $this->availablePermissionLevels = self::sanitizeAvailablePermissionLevels($value);
        }
    }

    /**
     * @param array<mixed> $value
     * @return list<int>
     */
    private static function sanitizeAvailablePermissionLevels(array $value): array
    {
        return count($value) > 0
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
     * @var array<string, int>|null
     */
    public ?array $blkMenubar = null {
        /**
         * @param array<mixed>|null $value
         */
        set(?array $value) {
            $this->blkMenubar = $value === null ? null : self::sanitizeBlkMenubar($value);
        }
    }

    /**
     * Non-numeric entries are dropped, not coerced to 0 -- Menu\BlockManager's
     * own missing-key fallback (`$idx * 50`) then applies the same way it
     * already does for an absent key, instead of a coerced 0 being
     * misread as "explicitly hidden" (`$pos > 0` gates visibility).
     *
     * @param array<mixed> $value
     * @return array<string, int>
     */
    private static function sanitizeBlkMenubar(array $value): array
    {
        $result = [];
        foreach ($value as $key => $val) {
            if (is_string($key) && is_numeric($val)) {
                $result[$key] = (int) $val;
            }
        }
        return $result;
    }

    // === cache_sizes ===
    /**
     * Cache-directory sizes computed by the maintenance page, cached to
     * avoid recomputing on every dashboard/maintenance load. No `set` hook
     * needed -- ConfigService::hydrate()'s special case builds the VO via
     * CacheSizesSnapshot::fromArray() before assignment, same as
     * recentPostDates.
     */
    public private(set) ?CacheSizesSnapshot $cacheSizes = null;

    // === chmod_value ===
    /**
     * Filesystem permission bits applied to newly created directories -- 0777
     * under Apache, 0755 otherwise, unless explicitly overridden. Null means
     * "not explicitly overridden": the SAPI-dependent default below applies.
     */
    private ?int $chmodValueStorage = null;

    public int $chmodValue {
        get {
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
                return $this->chmodValueStorage ?? 0777;
            }

            return $this->chmodValueStorage ?? (substr_compare(\PHP_SAPI, 'apa', 0, 3) === 0 ? 0777 : 0755);
        }
        set(?int $value) {
            $this->chmodValueStorage = $value;
        }
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
     * drives the search filters admin page. A property default can't call
     * `new` directly, so the real default is built lazily on first read via
     * the private backing field below, same pattern as chmodValue/
     * recentPostDates.
     * @var array<string, FilterViewDefinition>|null
     */
    private ?array $defaultFiltersViewsStorage = null;

    /**
     * @var array<string, FilterViewDefinition>
     */
    public array $defaultFiltersViews {
        get => $this->defaultFiltersViewsStorage ??= self::buildDefaultFiltersViews();
        /**
         * @param array<mixed>|null $value
         */
        set(?array $value) {
            $this->defaultFiltersViewsStorage = self::sanitizeDefaultFiltersViews($value);
        }
    }

    /**
     * @return array<string, FilterViewDefinition>
     */
    private static function buildDefaultFiltersViews(): array
    {
        $result = [];
        foreach (self::DEFAULT_FILTERS_VIEWS as $key => $entry) {
            $result[$key] = new FilterViewDefinition(access: $entry['access'], default: $entry['default']);
        }
        return $result;
    }

    /**
     * @param array<mixed>|null $value
     * @return array<string, FilterViewDefinition>
     */
    private static function sanitizeDefaultFiltersViews(?array $value): array
    {
        $defaults = self::buildDefaultFiltersViews();
        if ($value === null) {
            return $defaults;
        }
        $result = [];
        foreach ($defaults as $key => $defaultEntry) {
            $entry = $value[$key] ?? null;
            $result[$key] = (is_array($entry) ? FilterViewDefinition::tryFromArray($entry) : null) ?? $defaultEntry;
        }
        return $result;
    }

    // === empty_lounge_running ===
    /**
     * Transient "<execId>-<startTime>" marker set while
     * ImageService::emptyLounge() is running, used to detect a concurrent/
     * stalled run. Absent when no run is in progress.
     */
    public ?string $emptyLoungeRunning = null;

    // === file_ext ===
    /**
     * Full list of file extensions Piwigo will manage (pictures plus extras).
     * @var list<string>
     */
    public array $fileExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'tiff', 'tif', 'mpg', 'zip', 'avi', 'mp3', 'ogg', 'pdf', 'svg', 'heic'] {
        /**
         * @param array<mixed> $value
         */
        set(array $value) {
            $this->fileExtensions = self::sanitizeFileExtensions($value);
        }
    }

    /**
     * @param array<mixed> $value
     * @return list<string>
     */
    private static function sanitizeFileExtensions(array $value): array
    {
        return array_values(array_map(static fn (mixed $x): string => is_scalar($x) || $x === null ? (string) $x : '', $value));
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
     * Pages on which the tag/date filter UI is displayed. A property
     * default can't call `new` directly, so the real default is built
     * lazily on first read via the private backing field below, same
     * pattern as chmodValue/recentPostDates.
     * @var array<string, PageFilterFlags>|null
     */
    private ?array $filterPagesStorage = null;

    /**
     * @var array<string, PageFilterFlags>
     */
    public array $filterPages {
        get => $this->filterPagesStorage ??= self::buildDefaultFilterPages();
        /**
         * @param array<mixed>|null $value
         */
        set(?array $value) {
            $this->filterPagesStorage = self::sanitizeFilterPages($value);
        }
    }

    /**
     * @return array<string, PageFilterFlags>
     */
    private static function buildDefaultFilterPages(): array
    {
        $result = [];
        foreach (self::DEFAULT_FILTER_PAGES as $key => $entry) {
            $result[$key] = PageFilterFlags::fromArray($entry);
        }
        return $result;
    }

    /**
     * @param array<mixed>|null $value
     * @return array<string, PageFilterFlags>
     */
    private static function sanitizeFilterPages(?array $value): array
    {
        if ($value === null) {
            return self::buildDefaultFilterPages();
        }
        $result = [];
        foreach ($value as $key => $entry) {
            if (is_string($key) && is_array($entry)) {
                $result[$key] = PageFilterFlags::fromArray($entry);
            }
        }
        return $result;
    }

    // === filters_views ===
    /**
     * Admin-customized search-filter definitions, lazily seeded from
     * 'default_filters_views' the first time the search filters admin page is
     * saved. Absent (falls back to defaultFiltersViews) until then. No `set`
     * hook needed -- ConfigService::hydrate()'s special case builds the VO
     * via FilterViewsSelection::fromArray() before assignment, same as
     * recentPostDates.
     */
    public ?FilterViewsSelection $filtersViews = null;

    // === format_ext ===
    /**
     * File extensions recognised as additional formats for multi-format
     * photos.
     * @var list<string>
     */
    public array $formatExtensions = ['cr2', 'tif', 'tiff', 'nef', 'dng', 'ai', 'psd'] {
        /**
         * @param array<mixed> $value
         */
        set(array $value) {
            $this->formatExtensions = self::sanitizeFormatExtensions($value);
        }
    }

    /**
     * @param array<mixed> $value
     * @return list<string>
     */
    private static function sanitizeFormatExtensions(array $value): array
    {
        return array_values(array_map(static fn (mixed $x): string => is_scalar($x) || $x === null ? (string) $x : '', $value));
    }

    // === header_notes ===
    /**
     * Additional HTML messages shown in the gallery header for all users.
     * @var list<string>
     */
    public array $headerNotes = [] {
        /**
         * @param array<mixed> $value
         */
        set(array $value) {
            $this->headerNotes = self::sanitizeHeaderNotes($value);
        }
    }

    /**
     * @param array<mixed> $value
     * @return list<string>
     */
    private static function sanitizeHeaderNotes(array $value): array
    {
        return array_values(array_map(static fn (mixed $x): string => is_scalar($x) || $x === null ? (string) $x : '', $value));
    }

    // === history_sections_cache ===
    /**
     * Cached list of the history.section enum column values, refreshed when a
     * plugin adds a new section.
     * @var list<string>|null
     */
    public ?array $historySectionsCache = null {
        /**
         * @param array<mixed>|null $value
         */
        set(?array $value) {
            $this->historySectionsCache = $value === null ? null : array_values(array_filter($value, is_string(...)));
        }
    }

    // === links ===
    /**
     * Additional navigation links shown in the gallery menu, keyed by URL.
     * @var array<string, MenuLink>
     */
    public array $links = [] {
        /**
         * @param array<mixed> $value
         */
        set(array $value) {
            $this->links = self::sanitizeLinks($value);
        }
    }

    /**
     * @param array<mixed> $value
     * @return array<string, MenuLink>
     */
    private static function sanitizeLinks(array $value): array
    {
        $result = [];
        foreach ($value as $key => $entry) {
            if (is_string($key) && (is_array($entry) || is_string($entry))) {
                $result[$key] = MenuLink::fromArray($entry);
            }
        }
        return $result;
    }

    // === metadata_keyword_separator_regex ===
    /**
     * PCRE regex used to split keyword strings extracted from EXIF/IPTC
     * metadata.
     */
    public string $metadataKeywordSeparatorRegex = '/[.,;]/' {
        set(string $value) {
            $this->metadataKeywordSeparatorRegex = $value !== '' ? $value : '/[.,;]/';
        }
    }

    // === nbm_max_treatment_timeout_percent ===
    /**
     * Fraction of the PHP max_execution_time budget NBM may consume per batch.
     */
    public float $nbmMaxTreatmentTimeoutPercent = 0.8;

    // === order_by ===
    /**
     * The active photo sort order, as an {@see PhotoSortOrder} value rather than
     * the raw "ORDER BY ..." text the DB stores -- consumers ask it for SQL
     * or DQL instead of parsing it back themselves.
     *
     * Hooked with a nullable backing field because PHP has no way to give a
     * property an object default; the default materializes on first read,
     * same shape as recentPostDates below. That also makes it one of the
     * "fully hooked" properties ConfigService's own reset path lists, since
     * Reflection can report no usable default for it.
     */
    private ?PhotoSortOrder $orderByStorage = null;

    public PhotoSortOrder $orderBy {
        get => $this->orderByStorage ??= PhotoSortOrder::default();
        set(PhotoSortOrder $value) {
            $this->orderByStorage = $value;
        }
    }

    // === order_by_inside_category ===
    /**
     * Sort order applied within album listings (see order_by above). Unlike
     * order_by this one may legitimately sort on `rank`, which lives on the
     * image_category join row rather than on images.
     */
    private ?PhotoSortOrder $orderByInsideCategoryStorage = null;

    public PhotoSortOrder $orderByInsideCategory {
        get => $this->orderByInsideCategoryStorage ??= PhotoSortOrder::default();
        set(PhotoSortOrder $value) {
            $this->orderByInsideCategoryStorage = $value;
        }
    }

    // === picture_ext ===
    /**
     * File extensions recognised as displayable photo types.
     * @var list<string>
     */
    public array $pictureExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'] {
        /**
         * @param array<mixed> $value
         */
        set(array $value) {
            $this->pictureExtensions = self::sanitizePictureExtensions($value);
        }
    }

    /**
     * @param array<mixed> $value
     * @return list<string>
     */
    private static function sanitizePictureExtensions(array $value): array
    {
        return array_values(array_map(static fn (mixed $x): string => is_scalar($x) || $x === null ? (string) $x : '', $value));
    }

    // === picture_informations ===
    /**
     * Map of metadata field names to visibility booleans on the photo detail
     * page.
     * @var array<string, bool>
     */
    public array $pictureInformations = [] {
        /**
         * @param array<mixed> $value
         */
        set(array $value) {
            $this->pictureInformations = self::sanitizePictureInformations($value);
        }
    }

    /**
     * @param array<mixed> $value
     * @return array<string, bool>
     */
    private static function sanitizePictureInformations(array $value): array
    {
        $out = [];
        foreach ($value as $key => $val) {
            if (is_string($key) && is_bool($val)) {
                $out[$key] = $val;
            }
        }
        return $out;
    }

    // === random_index_redirect ===
    /**
     * URL mapping for random-index redirects used by shuffle features.
     * @var array<string,string>
     */
    public array $randomIndexRedirect = [] {
        /**
         * @param array<mixed> $value
         */
        set(array $value) {
            $this->randomIndexRedirect = self::sanitizeRandomIndexRedirect($value);
        }
    }

    /**
     * @param array<mixed> $value
     * @return array<string,string>
     */
    private static function sanitizeRandomIndexRedirect(array $value): array
    {
        $result = [];
        foreach ($value as $key => $val) {
            if (is_scalar($val)) {
                $result[(string) $key] = (string) $val;
            }
        }
        return $result;
    }

    // === rate_items ===
    /**
     * Available rating values displayed in the rating widget.
     * @var list<int>
     */
    public array $rateItems = [0, 1, 2, 3, 4, 5] {
        /**
         * @param array<mixed> $value
         */
        set(array $value) {
            $this->rateItems = self::sanitizeRateItems($value);
        }
    }

    /**
     * @param array<mixed> $value
     * @return list<int>
     */
    private static function sanitizeRateItems(array $value): array
    {
        return array_values(array_map(static fn (mixed $x): int => is_scalar($x) ? (int) $x : 0, $value));
    }

    // === recent_post_dates ===
    /**
     * Threshold dates used to determine which photos count as recent. Null
     * means "not explicitly set": the get hook lazily builds the default VO
     * below (a hooked property's own default can't call `new` directly).
     */
    private ?NotificationConfig $recentPostDatesStorage = null;

    public NotificationConfig $recentPostDates {
        get => $this->recentPostDatesStorage ??= NotificationConfig::default();
        set(NotificationConfig $value) {
            $this->recentPostDatesStorage = $value;
        }
    }

    // === show_exif_fields ===
    /**
     * List of EXIF field names to display on the photo detail page.
     * @var list<string>
     */
    public array $showExifFields = ['Make', 'Model', 'DateTimeOriginal', 'COMPUTED;ApertureFNumber'] {
        /**
         * @param array<mixed> $value
         */
        set(array $value) {
            $this->showExifFields = self::sanitizeShowExifFields($value);
        }
    }

    /**
     * @param array<mixed> $value
     * @return list<string>
     */
    private static function sanitizeShowExifFields(array $value): array
    {
        return array_values(array_map(static fn (mixed $x): string => is_scalar($x) || $x === null ? (string) $x : '', $value));
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
    public array $showIptcMapping = self::DEFAULT_SHOW_IPTC_MAPPING {
        /**
         * @param array<mixed> $value
         */
        set(array $value) {
            $this->showIptcMapping = self::sanitizeShowIptcMapping($value);
        }
    }

    /**
     * @param array<mixed> $value
     * @return array<string,string>
     */
    private static function sanitizeShowIptcMapping(array $value): array
    {
        $result = [];
        foreach ($value as $k => $val) {
            $result[(string) $k] = is_scalar($val) ? (string) $val : '';
        }
        return $result;
    }

    // === sync_chars_regex ===
    /**
     * Regex that matches valid filename characters during filesystem
     * synchronisation.
     */
    public string $syncCharsRegex = '/^[a-zA-Z0-9-_.]+$/' {
        set(string $value) {
            $this->syncCharsRegex = $value !== '' ? $value : '/^[a-zA-Z0-9-_.]+$/';
        }
    }

    // === sync_exclude_folders ===
    /**
     * Folder names excluded from filesystem synchronisation.
     * @var list<string>
     */
    public array $syncExcludeFolders = [] {
        /**
         * @param array<mixed> $value
         */
        set(array $value) {
            $this->syncExcludeFolders = self::sanitizeSyncExcludeFolders($value);
        }
    }

    /**
     * @param array<mixed> $value
     * @return list<string>
     */
    private static function sanitizeSyncExcludeFolders(array $value): array
    {
        return array_values(array_map(static fn (mixed $x): string => is_scalar($x) || $x === null ? (string) $x : '', $value));
    }

    // === update_notify_last_notification ===
    /**
     * The last update-availability notification shown to the admin.
     * Genuine absence before the first check. No `set` hook needed --
     * ConfigService::hydrate()'s special case builds the VO via
     * UpdateNotification::fromArray() before assignment, same as
     * recentPostDates.
     */
    public ?UpdateNotification $updateNotifyLastNotification = null;

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
    public array $useExifMapping = self::DEFAULT_USE_EXIF_MAPPING {
        /**
         * @param array<mixed> $value
         */
        set(array $value) {
            $this->useExifMapping = self::sanitizeUseExifMapping($value);
        }
    }

    /**
     * @param array<mixed> $value
     * @return array<string,string>
     */
    private static function sanitizeUseExifMapping(array $value): array
    {
        $result = [];
        foreach ($value as $k => $val) {
            $result[(string) $k] = is_scalar($val) ? (string) $val : '';
        }
        return $result;
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
    public array $useIptcMapping = self::DEFAULT_USE_IPTC_MAPPING {
        /**
         * @param array<mixed> $value
         */
        set(array $value) {
            $this->useIptcMapping = self::sanitizeUseIptcMapping($value);
        }
    }

    /**
     * @param array<mixed> $value
     * @return array<string,string>
     */
    private static function sanitizeUseIptcMapping(array $value): array
    {
        $result = [];
        foreach ($value as $k => $val) {
            $result[(string) $k] = is_scalar($val) ? (string) $val : '';
        }
        return $result;
    }

    // ---- Composed accessors (no config key of their own) ---------------

    /**
     * Composed accessors replacing 3 of the 52 retired `define()` constants
     * (PHPWG_THEMES_PATH/PWG_COMBINED_DIR/PWG_DERIVATIVE_DIR) -- not real
     * config keys, just the same composition `include/constants.php` used
     * to do at boot time (`$themesDir . '/'`, `$dataLocation . 'combined/'`,
     * `$dataLocation . 'i/'`). Get-only virtual properties, not methods --
     * pure derived values with no side effects, same "a property for an
     * attribute" rule applied to every other config-backed property above.
     */
    public string $themesPath {
        get => $this->themesDir . '/';
    }

    public string $combinedDir {
        get => $this->dataLocation . 'combined/';
    }

    public string $derivativeDir {
        get => $this->dataLocation . 'i/';
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
        foreach ($reflection->getProperties() as $property) {
            // isStatic() filters the class's own static properties (none
            // today, kept defensively). chmodValueStorage/
            // recentPostDatesStorage/defaultFiltersViewsStorage/
            // filterPagesStorage are private implementation detail behind
            // their own public virtual property (chmodValue/
            // recentPostDates/defaultFiltersViews/filterPages) -- reported
            // under that name via the loop below instead, not duplicated
            // here.
            if ($property->isStatic() || in_array($property->getName(), ['chmodValueStorage', 'recentPostDatesStorage', 'defaultFiltersViewsStorage', 'filterPagesStorage'], true)) {
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
     * Test-only -- restricted to tests/ by an arch test, mirroring the
     * equivalent guard on Kernel's and ShutdownHandler's own
     * test-isolation reset methods. Restores every property to its own
     * declared default, reflectively. chmodValueStorage/
     * recentPostDatesStorage/defaultFiltersViewsStorage/filterPagesStorage
     * have no declared default of their own (their public virtual property
     * is fully hooked, so Reflection reports no usable default for it) --
     * nulling the backing field directly restores the same "not explicitly
     * set" state their own get hook already treats as the default.
     */
    public function reset(): void
    {
        $reflection = new ReflectionClass($this);
        foreach ($reflection->getProperties() as $property) {
            if ($property->isStatic()) {
                continue;
            }
            $name = $property->getName();
            if (in_array($name, ['chmodValueStorage', 'recentPostDatesStorage', 'defaultFiltersViewsStorage', 'filterPagesStorage', 'orderByStorage', 'orderByInsideCategoryStorage'], true)) {
                $property->setValue($this, null);
                continue;
            }
            // chmodValue/recentPostDates/defaultFiltersViews/filterPages/
            // orderBy/orderByInsideCategory themselves: already reset via
            // their own backing field above -- their `set` hook only accepts
            // a real int/NotificationConfig/array/PhotoSortOrder, not the null
            // getDefaultValue() would otherwise try to pass it (a fully
            // get+set hooked property reports no usable default).
            // themesPath/combinedDir/derivativeDir have no backing state of
            // their own at all -- get-only, computed from themesDir/
            // dataLocation, which reset normally on their own turn.
            if (in_array($name, ['chmodValue', 'recentPostDates', 'defaultFiltersViews', 'filterPages', 'orderBy', 'orderByInsideCategory', 'themesPath', 'combinedDir', 'derivativeDir'], true)) {
                continue;
            }
            $property->setValue($this, $property->getDefaultValue());
        }
    }
}
