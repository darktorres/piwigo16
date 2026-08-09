<?php

declare(strict_types=1);

namespace Piwigo\Config;

use Piwigo\Cache\CachePools;
use Piwigo\Config\Projection\ConfigParamValue;
use Piwigo\Event\Lifecycle\LoadConf;
use Piwigo\PluginConfig\EventDispatcher;
use ReflectionNamedType;
use ReflectionProperty;
use RuntimeException;

/**
 * The DI/Doctrine-backed config persistence layer -- the real writer
 * behind Piwigo\Config\CurrentConfig. CurrentConfig is the typed read layer
 * (one real property per key -- plain, `private(set)`, or hooked -- no
 * separate getter/setter methods) and in-memory-only write via that same
 * property; Piwigo\Config\ConfigService (this class) is the DI/Doctrine-
 * backed persistence layer for every real writer -- constructor-injected
 * where a class can be container-built, or reached via
 * Piwigo\Config\CurrentConfigService::get() otherwise (static utilities,
 * throwaway-constructed classes, and every pre-container bootstrap/install
 * call site).
 *
 * `value` is a real JSON column -- encode()/hydrate() below
 * json_encode()/json_decode() every value uniformly. No addslashes() --
 * Doctrine parameterizes values safely.
 */
final readonly class ConfigService
{
    /**
     * Config-table param name -> CurrentConfig property name. The one
     * place this class needs to know property names don't always match
     * their DB key mechanically (log -> logConf, rate -> rateEnabled,
     * enable_formats -> isFormatsEnabled, anti-flood_time -> antiFloodTime,
     * cache.backend -> cacheBackend, ...) -- loadConfFromDb() looks up each
     * real config row's param here to find its property. Adding a new
     * CurrentConfig property that should load from the DB needs one line
     * here too, same as adding its property by hand. A param with no entry
     * here is a genuinely dynamic/unschematized key (e.g.
     * upload_user_access) -- confGetParam() reads those straight from the
     * repository instead.
     *
     * @var array<string, string>
     */
    private const array KEY_TO_PROPERTY = [
        'activate_comments' => 'activateComments',
        'activity_display_connections' => 'activityDisplayConnections',
        'add_cache_to_storage_chart' => 'addCacheToStorageChart',
        'admin_theme' => 'adminTheme',
        'album_description_on_all_pages' => 'albumDescriptionOnAllPages',
        'album_move_delay_before_auto_opening' => 'albumMoveDelayBeforeAutoOpening',
        'allow_html_descriptions' => 'allowHtmlDescriptions',
        'allow_html_in_metadata' => 'allowHtmlInMetadata',
        'allow_random_representative' => 'allowRandomRepresentative',
        'allow_user_customization' => 'allowUserCustomization',
        'allow_user_registration' => 'allowUserRegistration',
        'allow_web_services' => 'allowWebServices',
        'alternative_pem_url' => 'alternativePemUrl',
        'animated_webp_compression_quality' => 'animatedWebpCompressionQuality',
        'anti-flood_time' => 'antiFloodTime',
        'api_key_duration' => 'apiKeyDuration',
        'api_key_forbidden_methods' => 'apiKeyForbiddenMethods',
        'auth_key_duration' => 'authKeyDuration',
        'authorize_remembering' => 'authorizeRemembering',
        'available_permission_levels' => 'availablePermissionLevels',
        'batch_manager_images_per_page_global' => 'batchManagerImagesPerPageGlobal',
        'batch_manager_images_per_page_unit' => 'batchManagerImagesPerPageUnit',
        'blk_menubar' => 'blkMenubar',
        'browser_language' => 'browserLanguage',
        'cache.backend' => 'cacheBackend',
        'cache.default_ttl' => 'cacheDefaultTtl',
        'cache.namespace' => 'cacheNamespace',
        'cache.redis_url' => 'cacheRedisUrl',
        'cache_sizes' => 'cacheSizes',
        'calendar_datefield' => 'calendarDatefield',
        'calendar_show_any' => 'calendarShowAny',
        'calendar_show_empty' => 'calendarShowEmpty',
        'category_url_style' => 'categoryUrlStyle',
        'checksum_compute_blocksize' => 'checksumComputeBlocksize',
        'chmod_value' => 'chmodValue',
        'comment_spam_max_links' => 'commentSpamMaxLinks',
        'comment_spam_reject' => 'commentSpamReject',
        'comments_author_mandatory' => 'commentsAuthorMandatory',
        'comments_email_mandatory' => 'commentsEmailMandatory',
        'comments_enable_website' => 'commentsEnableWebsite',
        'comments_forall' => 'commentsForall',
        'comments_order' => 'commentsOrder',
        'comments_page_nb_comments' => 'commentsPageNbComments',
        'comments_validation' => 'commentsValidation',
        'compiled_template_cache_language' => 'compiledTemplateCacheLanguage',
        'content_tag_cloud_items_number' => 'contentTagCloudItemsNumber',
        'count_orphans' => 'countOrphans',
        'dashboard_activity_nb_weeks' => 'dashboardActivityNbWeeks',
        'dashboard_check_for_updates' => 'dashboardCheckForUpdates',
        'data_dir_checked' => 'dataDirChecked',
        'data_location' => 'dataLocation',
        'debug_l10n' => 'debugL10n',
        'debug_mail' => 'debugMail',
        'debug_template' => 'debugTemplate',
        'default_filters_views' => 'defaultFiltersViews',
        'default_redirect_method' => 'defaultRedirectMethod',
        'default_user_id' => 'defaultUserId',
        'derivative_default_size' => 'derivativeDefaultSize',
        'derivative_url_style' => 'derivativeUrlStyle',
        'derivatives_strip_metadata_threshold' => 'derivativesStripMetadataThreshold',
        'die_on_sql_error' => 'dieOnSqlError',
        'display_fromto' => 'displayFromto',
        'double_password_type_in_admin' => 'doublePasswordTypeInAdmin',
        'email_admin_on_comment' => 'emailAdminOnComment',
        'email_admin_on_comment_deletion' => 'emailAdminOnCommentDeletion',
        'email_admin_on_comment_edition' => 'emailAdminOnCommentEdition',
        'email_admin_on_comment_validation' => 'emailAdminOnCommentValidation',
        'email_admin_on_new_user' => 'emailAdminOnNewUser',
        'empty_lounge_running' => 'emptyLoungeRunning',
        'enable_core_update' => 'enableCoreUpdate',
        'enable_extensions_install' => 'enableExtensionsInstall',
        'enable_formats' => 'isFormatsEnabled',
        'enable_plugins' => 'enablePlugins',
        'enable_synchronization' => 'enableSynchronization',
        'ext_imagick_dir' => 'extImagickDir',
        'extents_for_templates' => 'extentsForTemplates',
        'ffmpeg_dir' => 'ffmpegDir',
        'file_ext' => 'fileExtensions',
        'filter_pages' => 'filterPages',
        'filters_views' => 'filtersViews',
        'format_ext' => 'formatExtensions',
        'fs_quick_check_last_check' => 'fsQuickCheckLastCheck',
        'fs_quick_check_period' => 'fsQuickCheckPeriod',
        'full_tag_cloud_items_number' => 'fullTagCloudItemsNumber',
        'gallery_locked' => 'galleryLocked',
        'gallery_title' => 'galleryTitle',
        'gallery_url' => 'galleryUrl',
        'graphics_library' => 'graphicsLibrary',
        'guest_access' => 'guestAccess',
        'guest_id' => 'guestId',
        'header_notes' => 'headerNotes',
        'history_admin' => 'historyAdmin',
        'history_autopurge_blocksize' => 'historyAutopurgeBlocksize',
        'history_autopurge_every' => 'historyAutopurgeEvery',
        'history_autopurge_keep_lines' => 'historyAutopurgeKeepLines',
        'history_guest' => 'historyGuest',
        'history_sections_cache' => 'historySectionsCache',
        'index_caddie_icon' => 'indexCaddieIcon',
        'index_created_date_icon' => 'indexCreatedDateIcon',
        'index_edit_icon' => 'indexEditIcon',
        'index_flat_icon' => 'indexFlatIcon',
        'index_new_icon' => 'indexNewIcon',
        'index_posted_date_icon' => 'indexPostedDateIcon',
        'index_search_in_set_action' => 'indexSearchInSetAction',
        'index_search_in_set_button' => 'indexSearchInSetButton',
        'index_sizes_icon' => 'indexSizesIcon',
        'index_slideshow_icon' => 'indexSlideshowIcon',
        'index_sort_order_input' => 'indexSortOrderInput',
        'inheritance_by_default' => 'inheritanceByDefault',
        'insensitive_case_logon' => 'insensitiveCaseLogon',
        'last_major_update' => 'lastMajorUpdate',
        'level_separator' => 'levelSeparator',
        'light_album_manager_threshold' => 'lightAlbumManagerThreshold',
        'light_slideshow' => 'lightSlideshow',
        'linked_album_search_limit' => 'linkedAlbumSearchLimit',
        'links' => 'links',
        'log' => 'logConf',
        'log_archive_days' => 'logArchiveDays',
        'log_dir' => 'logDir',
        'log_level' => 'logLevel',
        'login_lockout_duration_minutes' => 'loginLockoutDurationMinutes',
        'login_lockout_max_attempts' => 'loginLockoutMaxAttempts',
        'login_lockout_window_minutes' => 'loginLockoutWindowMinutes',
        'lounge_activate_threshold' => 'loungeActivateThreshold',
        'lounge_active' => 'loungeActive',
        'lounge_max_duration' => 'loungeMaxDuration',
        'mail_allow_html' => 'mailAllowHtml',
        'mail_sender_email' => 'mailSenderEmail',
        'mail_sender_name' => 'mailSenderName',
        'mail_theme' => 'mailTheme',
        'max_requests' => 'maxRequests',
        'menubar_filter_icon' => 'menubarFilterIcon',
        'menubar_tag_cloud_content' => 'menubarTagCloudContent',
        'menubar_tag_cloud_items_number' => 'menubarTagCloudItemsNumber',
        'meta_ref' => 'metaRef',
        'metadata_keyword_separator_regex' => 'metadataKeywordSeparatorRegex',
        'mobile_theme' => 'mobileTheme',
        'nb_categories_page' => 'nbCategoriesPage',
        'nb_comment_page' => 'nbCommentPage',
        'nb_logs_page' => 'nbLogsPage',
        'nbm_complementary_mail_content' => 'nbmComplementaryMailContent',
        'nbm_default_value_user_enabled' => 'nbmDefaultValueUserEnabled',
        'nbm_list_all_enabled_users_to_send' => 'nbmListAllEnabledUsersToSend',
        'nbm_max_treatment_timeout_percent' => 'nbmMaxTreatmentTimeoutPercent',
        'nbm_send_detailed_content' => 'nbmSendDetailedContent',
        'nbm_send_html_mail' => 'nbmSendHtmlMail',
        'nbm_send_mail_as' => 'nbmSendMailAs',
        'nbm_send_recent_post_dates' => 'nbmSendRecentPostDates',
        'nbm_treatment_timeout_default' => 'nbmTreatmentTimeoutDefault',
        'never_delete_originals' => 'neverDeleteOriginals',
        'newcat_default_commentable' => 'newcatDefaultCommentable',
        'newcat_default_position' => 'newcatDefaultPosition',
        'newcat_default_status' => 'newcatDefaultStatus',
        'newcat_default_visible' => 'newcatDefaultVisible',
        'no_photo_yet' => 'noPhotoYet',
        'no_photo_yet_url' => 'noPhotoYetUrl',
        'obligatory_user_mail_address' => 'obligatoryUserMailAddress',
        'order_by' => 'orderBy',
        'order_by_custom' => 'orderByCustom',
        'order_by_inside_category' => 'orderByInsideCategory',
        'order_by_inside_category_custom' => 'orderByInsideCategoryCustom',
        'original_resize' => 'originalResize',
        'original_resize_maxheight' => 'originalResizeMaxheight',
        'original_resize_maxwidth' => 'originalResizeMaxwidth',
        'original_resize_quality' => 'originalResizeQuality',
        'original_url_protection' => 'originalUrlProtection',
        'page_banner' => 'pageBanner',
        'paginate_pages_around' => 'paginatePagesAround',
        'password_activation_duration' => 'passwordActivationDuration',
        'password_reset_code_duration' => 'passwordResetCodeDuration',
        'password_reset_duration' => 'passwordResetDuration',
        'pdf_jpg_quality' => 'pdfJpgQuality',
        'pdf_representative_ext' => 'pdfRepresentativeExt',
        'pdf_viewer_filesize_threshold' => 'pdfViewerFilesizeThreshold',
        'pem_languages_category' => 'pemLanguagesCategory',
        'pem_plugins_category' => 'pemPluginsCategory',
        'pem_themes_category' => 'pemThemesCategory',
        'php_extension_in_urls' => 'phpExtensionInUrls',
        'picture_caddie_icon' => 'pictureCaddieIcon',
        'picture_download_icon' => 'pictureDownloadIcon',
        'picture_edit_icon' => 'pictureEditIcon',
        'picture_ext' => 'pictureExtensions',
        'picture_favorite_icon' => 'pictureFavoriteIcon',
        'picture_informations' => 'pictureInformations',
        'picture_menu' => 'pictureMenu',
        'picture_metadata_icon' => 'pictureMetadataIcon',
        'picture_navigation_icons' => 'pictureNavigationIcons',
        'picture_navigation_thumb' => 'pictureNavigationThumb',
        'picture_representative_icon' => 'pictureRepresentativeIcon',
        'picture_sizes_icon' => 'pictureSizesIcon',
        'picture_slideshow_icon' => 'pictureSlideshowIcon',
        'picture_url_style' => 'pictureUrlStyle',
        'piwigo_installed_version' => 'piwigoInstalledVersion',
        'proxy_auth' => 'proxyAuth',
        'proxy_server' => 'proxyServer',
        'question_mark_in_urls' => 'questionMarkInUrls',
        'quick_search_include_sub_albums' => 'quickSearchIncludeSubAlbums',
        'random_index_redirect' => 'randomIndexRedirect',
        'rate' => 'rateEnabled',
        'rate_anonymous' => 'rateAnonymous',
        'rate_items' => 'rateItems',
        'recent_post_dates' => 'recentPostDates',
        'related_albums_display_limit' => 'relatedAlbumsDisplayLimit',
        'related_albums_maximum_items_to_compute' => 'relatedAlbumsMaximumItemsToCompute',
        'remember_me_length' => 'rememberMeLength',
        'remember_me_name' => 'rememberMeName',
        'representative_cache_on_level' => 'representativeCacheOnLevel',
        'representative_cache_on_subcats' => 'representativeCacheOnSubcats',
        'rss_feed_author' => 'rssFeedAuthor',
        'secret_key' => 'secretKey',
        'send_bcc_mail_webmaster' => 'sendBccMailWebmaster',
        'send_piwigo_infos' => 'sendPiwigoInfos',
        'send_piwigo_infos_last_notice' => 'sendPiwigoInfosLastNotice',
        'send_piwigo_infos_origin_hash' => 'sendPiwigoInfosOriginHash',
        'send_piwigo_infos_period' => 'sendPiwigoInfosPeriod',
        'send_piwigo_infos_update_url' => 'sendPiwigoInfosUpdateUrl',
        'session_gc_probability' => 'sessionGcProbability',
        'session_length' => 'sessionLength',
        'session_name' => 'sessionName',
        'session_save_handler' => 'sessionSaveHandler',
        'session_use_cookies' => 'sessionUseCookies',
        'session_use_ip_address' => 'sessionUseIpAddress',
        'session_use_only_cookies' => 'sessionUseOnlyCookies',
        'session_use_trans_sid' => 'sessionUseTransSid',
        'show_exif' => 'showExif',
        'show_exif_fields' => 'showExifFields',
        'show_gt' => 'showGt',
        'show_iptc' => 'showIptc',
        'show_iptc_mapping' => 'showIptcMapping',
        'show_mobile_app_banner_in_admin' => 'showMobileAppBannerInAdmin',
        'show_mobile_app_banner_in_gallery' => 'showMobileAppBannerInGallery',
        'show_newsletter_subscription' => 'showNewsletterSubscription',
        'show_piwigo_latest_news' => 'showPiwigoLatestNews',
        'show_queries' => 'showQueries',
        'show_template_in_side_menu' => 'showTemplateInSideMenu',
        'show_thumbnail_caption' => 'showThumbnailCaption',
        'show_version' => 'showVersion',
        'slideshow_period' => 'slideshowPeriod',
        'slideshow_period_max' => 'slideshowPeriodMax',
        'slideshow_period_min' => 'slideshowPeriodMin',
        'slideshow_period_step' => 'slideshowPeriodStep',
        'slideshow_repeat' => 'slideshowRepeat',
        'smtp_host' => 'smtpHost',
        'smtp_password' => 'smtpPassword',
        'smtp_secure' => 'smtpSecure',
        'smtp_user' => 'smtpUser',
        'standard_pages_selected_logo' => 'standardPagesSelectedLogo',
        'standard_pages_selected_logo_path' => 'standardPagesSelectedLogoPath',
        'standard_pages_selected_skin' => 'standardPagesSelectedSkin',
        'stat_compare_year_displayed' => 'statCompareYearDisplayed',
        'sync_chars_regex' => 'syncCharsRegex',
        'sync_exclude_folders' => 'syncExcludeFolders',
        'tag_letters_column_number' => 'tagLettersColumnNumber',
        'tag_url_style' => 'tagUrlStyle',
        'tags_default_display_mode' => 'tagsDefaultDisplayMode',
        'tags_levels' => 'tagsLevels',
        'template_combine_files' => 'templateCombineFiles',
        'template_compile_check' => 'templateCompileCheck',
        'template_force_compile' => 'templateForceCompile',
        'themes_dir' => 'themesDir',
        'tiff_representative_ext' => 'tiffRepresentativeExt',
        'top_number' => 'topNumber',
        'trusted_proxies' => 'trustedProxies',
        'uniqueness_mode' => 'uniquenessMode',
        'update_notify_check_period' => 'updateNotifyCheckPeriod',
        'update_notify_last_check' => 'updateNotifyLastCheck',
        'update_notify_last_notification' => 'updateNotifyLastNotification',
        'update_notify_reminder_period' => 'updateNotifyReminderPeriod',
        'upload_detect_duplicate' => 'uploadDetectDuplicate',
        'upload_dir' => 'uploadDir',
        'upload_form_all_types' => 'uploadFormAllTypes',
        'upload_form_automatic_rotation' => 'uploadFormAutomaticRotation',
        'upload_form_chunk_size' => 'uploadFormChunkSize',
        'upload_form_max_file_size' => 'uploadFormMaxFileSize',
        'url_port' => 'urlPort',
        'use_exif' => 'useExif',
        'use_exif_mapping' => 'useExifMapping',
        'use_iptc' => 'useIptc',
        'use_iptc_mapping' => 'useIptcMapping',
        'use_proxy' => 'useProxy',
        'use_standard_pages' => 'useStandardPages',
        'user_can_delete_comment' => 'userCanDeleteComment',
        'user_can_edit_comment' => 'userCanEditComment',
        'webmaster_id' => 'webmasterId',
        'week_starts_on' => 'weekStartsOn',
        'ws_max_images_per_page' => 'wsMaxImagesPerPage',
        'ws_max_users_per_page' => 'wsMaxUsersPerPage',
    ];

    /**
     * The single cache item allRowsFromCacheOrDb() stores the whole
     * param => value map under -- CachePools::config() is a dedicated
     * namespace with no other consumer, so one well-known key is enough.
     */
    private const string CACHE_ITEM_KEY = 'all_rows';

    public function __construct(
        private ConfigRepository $repo,
        private EventDispatcher $eventDispatcher,
        private CurrentConfig $currentConfig,
    ) {}

    /**
     * Generic dynamic-key reader for conf rows that legitimately lack a
     * CurrentConfig property (not in KEY_TO_PROPERTY -- e.g.
     * upload_user_access). For property-backed keys, prefer the typed
     * CurrentConfig::xxx() accessors; calling this with one still works
     * (reads the live property), just isn't the preferred surface.
     *
     * @param array<mixed>|string|int|float|bool|null $defaultValue
     * @return array<mixed>|string|int|float|bool|NotificationConfig|null
     */
    public function confGetParam(string $param, array|string|int|float|bool|null $defaultValue = null): array|string|int|float|bool|NotificationConfig|null
    {
        $propertyName = self::KEY_TO_PROPERTY[$param] ?? null;
        if ($propertyName !== null) {
            $value = new ReflectionProperty(CurrentConfig::class, $propertyName)->getValue($this->currentConfig);
            /** @var array<mixed>|string|int|float|bool|NotificationConfig|null $value every mapped property's declared type */

            return $value ?? $defaultValue;
        }

        $entry = $this->repo->find($param);
        if ($entry === null || $entry->value === null) {
            return $defaultValue;
        }

        $decoded = json_decode($entry->value, true);
        /** @var array<mixed>|string|int|float|bool|null $decoded json_decode(..., true) of a value only ever json_encode()'d from that same union by confUpdateParam() */

        return $decoded ?? $defaultValue;
    }

    /**
     * Loads config table rows into CurrentConfig's own properties.
     * $param === null loads every row (the common case -- every real call
     * site except one); a non-null $param loads just that one row by
     * primary key. Narrower than the legacy load_conf_from_db()'s
     * arbitrary raw-SQL $condition string -- grepping every real call
     * site found exactly one non-trivial usage (a single-param equality
     * filter), so a parameterized find() covers the real need without a
     * string-interpolation surface.
     *
     * A row whose param isn't in KEY_TO_PROPERTY is a genuinely dynamic/
     * unschematized key (confGetParam()'s own real use case) -- silently
     * skipped here, exactly like the SCHEMA-driven design this replaces
     * skipped keys with no matching accessor.
     *
     * Fires the same 'load_conf' plugin hook the reference's
     * ConfigDb::loadConfFromDb() does, payload $param in place of that
     * method's raw SQL $condition string -- a documented trigger
     * (tools/triggers_list.php), so a plugin listening for it must keep
     * being notified regardless of which persistence layer a given
     * caller routes through.
     */
    public function loadConfFromDb(?string $param = null, bool $dieIfNotFound = true): void
    {
        if ($param === null) {
            foreach ($this->allRowsFromCacheOrDb() as $rowParam => $value) {
                $this->hydrate($rowParam, $value);
            }
        } else {
            $entry = $this->repo->find($param);
            if ($entry === null) {
                if ($dieIfNotFound) {
                    throw new RuntimeException("No configuration data for param '{$param}'.");
                }
                return;
            }
            $this->hydrate($entry->param, $entry->value);
        }

        $this->eventDispatcher->dispatchNotify(new LoadConf($param));
    }

    /**
     * The $param === null branch above is the expensive one -- a full
     * Doctrine ORM hydration of ~280 ConfigEntry entities on every request
     * that runs it (every real HTTP request, via RequestBootstrap::connect()).
     * CachePools::config() caches the plain param => value map instead of
     * the ConfigEntry objects themselves -- hydrate() only ever needs the
     * raw string value, and a plain array sidesteps any Doctrine-entity-
     * serialization question entirely. No TTL: config data doesn't go
     * stale on a timer, only on a write, so confUpdateParam()/
     * confDeleteParam() below explicitly clear this pool after every real
     * write instead of relying on expiry.
     *
     * @return array<string, ?string>
     */
    private function allRowsFromCacheOrDb(): array
    {
        $pool = CachePools::config();
        $item = $pool->getItem(self::CACHE_ITEM_KEY);
        if ($item->isHit()) {
            /** @var array<string, ?string> $cached */
            $cached = $item->get();

            return $cached;
        }

        $rows = [];
        foreach ($this->repo->findAll() as $entry) {
            $rows[$entry->param] = $entry->value;
        }

        $item->set($rows);
        $pool->save($item);

        return $rows;
    }

    /**
     * Persist a single key/value to the conf table.
     *
     * @param array<mixed>|string|int|float|bool|null $value
     */
    public function confUpdateParam(string $param, array|string|int|float|bool|null $value, bool $updateGlobal = false): void
    {
        $this->repo->upsert($param, self::encode($param, $value));
        CachePools::config()->clear();

        if ($updateGlobal) {
            $propertyName = self::KEY_TO_PROPERTY[$param] ?? null;
            if ($propertyName !== null) {
                $property = new ReflectionProperty(CurrentConfig::class, $propertyName);
                // recentPostDates's own type is NotificationConfig, not the
                // raw array a caller passes here -- same coercion hydrate()
                // needs below.
                $property->setValue(
                    $this->currentConfig,
                    $propertyName === 'recentPostDates' ? NotificationConfig::fromArray(is_array($value) ? $value : []) : $value,
                );
            }
        }
    }

    /**
     * @param string|list<string> $params
     */
    public function confDeleteParam(string|array $params): void
    {
        $params = is_array($params) ? $params : [$params];
        foreach ($params as $param) {
            $this->repo->deleteByParam($param);
            CachePools::config()->clear();

            $propertyName = self::KEY_TO_PROPERTY[$param] ?? null;
            if ($propertyName === null) {
                continue;
            }

            if (in_array($propertyName, ['chmodValue', 'recentPostDates'], true)) {
                // Both are fully-hooked properties (get + set) -- Reflection
                // reports no usable default for those (see CurrentConfig's
                // own reset()), so "deleted" means nulling the private
                // backing field directly, which their own get hook already
                // treats as "not explicitly set."
                new ReflectionProperty(CurrentConfig::class, $propertyName . 'Storage')
                    ->setValue($this->currentConfig, null);
                continue;
            }

            // "Deleted" means back to the property's own declared default
            // (usually null for the nullable-string presence-marker
            // cluster, but a real non-null default for e.g.
            // disabledDerivatives's [] -- resetting those to null would
            // violate their own non-nullable type).
            $property = new ReflectionProperty(CurrentConfig::class, $propertyName);
            $property->setValue($this->currentConfig, $property->getDefaultValue());
        }
    }

    /**
     * Writes a random probe value, reads it back, deletes it.
     */
    public function pwgIsDbconfWriteable(): bool
    {
        $param = 'pwg_is_dbconf_writeable_' . bin2hex(random_bytes(6));
        $value = date('c') . ' ' . bin2hex(random_bytes(10));

        $this->confUpdateParam($param, $value);
        $entry = $this->repo->find($param);
        $writeable = $entry !== null && $entry->value !== null && json_decode($entry->value, true) === $value;

        $this->confDeleteParam($param);

        return $writeable;
    }

    /**
     * Bridges one real config-table row into its matching CurrentConfig
     * property. This is the one place that decodes a raw DB JSON value into
     * a property's exact native PHP type; every hooked property's own `set`
     * hook can otherwise assume it's always called with an
     * already-correctly-shaped value.
     */
    private function hydrate(string $param, ?string $raw): void
    {
        $propertyName = self::KEY_TO_PROPERTY[$param] ?? null;
        if ($propertyName === null) {
            return;
        }

        $property = new ReflectionProperty(CurrentConfig::class, $propertyName);
        $paramType = $property->getType();
        $paramTypeName = $paramType instanceof ReflectionNamedType ? $paramType->getName() : null;

        if ($raw === null) {
            if ($paramType?->allowsNull() === true) {
                $property->setValue($this->currentConfig, null);
            }

            return;
        }

        $decoded = json_decode($raw, true);

        if ($propertyName === 'recentPostDates') {
            // recentPostDates's own declared type is NotificationConfig, not
            // array -- getName() above already returned that (a single
            // ReflectionNamedType, not a union), so it never hits the
            // generic match() below. Its own `set` hook only accepts a real
            // NotificationConfig, so the raw decoded array is coerced here
            // instead of inside CurrentConfig.
            $property->setValue($this->currentConfig, NotificationConfig::fromArray(is_array($decoded) ? $decoded : []));

            return;
        }

        $value = match ($paramTypeName) {
            'bool' => is_bool($decoded) ? $decoded : false,
            'int' => is_int($decoded) ? $decoded : 0,
            'float' => is_float($decoded) || is_int($decoded) ? (float) $decoded : 0.0,
            'string' => is_string($decoded) ? $decoded : '',
            'array' => is_array($decoded) ? $decoded : [],
            default => $decoded,
        };

        $property->setValue($this->currentConfig, $value);
    }

    /**
     * @return list<string>
     */
    public function getAllParamNames(): array
    {
        return $this->repo->findAllParamNames();
    }

    /**
     * @return list<ConfigParamValue>
     */
    public function getParamsAndValuesLike(string $likePattern): array
    {
        return $this->repo->findParamsAndValuesLike($likePattern);
    }

    public function findRawValue(string $param): string|false
    {
        return $this->repo->findRawValue($param);
    }

    /**
     * @param array<mixed>|string|int|float|bool|null $value
     */
    private static function encode(string $param, array|string|int|float|bool|null $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $encoded = json_encode($value);
        assert($encoded !== false);

        return $encoded;
    }
}
