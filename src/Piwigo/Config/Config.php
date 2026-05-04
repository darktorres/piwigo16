<?php

declare(strict_types=1);

namespace Piwigo\Config;

/**
 * Typed facade over Piwigo's runtime configuration.
 *
 * The body of this class is split into three regions:
 *   1. Preamble — properties, SCHEMA constant, raw/has helpers.
 *   2. Generated accessors — between the <<<CONFIG-ACCESSORS-BEGIN>>> and
 *      <<<CONFIG-ACCESSORS-END>>> sentinels. Produced by
 *      tools/build-config-accessors.php from SCHEMA. DO NOT EDIT BY HAND.
 *   3. Custom accessors + bulk helpers — hand-written below the END sentinel
 *      for keys whose access logic doesn't fit the simple getString/getInt/
 *      getBool pattern.
 *
 * self::$data is the single source of truth. ConfigLoader populates it at
 * boot, callers mutate it via Config::override / Config::persist. The legacy
 * $GLOBALS['conf'] reference bridge (attachGlobals) was retired once all
 * core and bundled-plugin readers/writers were migrated to this facade.
 */
final class Config
{
    /** @var array<string,mixed> */
    private static array $data = [];
    private static ?self $singleton = null;
    /**
     * Source-of-truth registry of every Piwigo config key. The typed accessors
     * below are GENERATED from this constant by tools/build-config-accessors.php
     * — do not hand-edit anything between the BEGIN/END GENERATED markers.
     *
     * Schema entries:
     *   type      one of 'string'|'int'|'bool'|'float'|'array'
     *   default   literal default value (mixed)
     *   method    accessor method name (cluster/cluster-irregular cases captured here)
     *   nullable  optional, accessor return type is `?T` (default null is implicit)
     *   custom    optional, accessor body is hand-written below the generated block
     *
     * Adding a new key: append the entry here, then run
     *   php tools/build-config-accessors.php
     * and commit the regenerated section. CI's SchemaIntegrityTest will fail
     * the build if the generated accessors drift from SCHEMA.
     *
     * @var array<string, array{type: string, default: mixed, method: string, nullable?: bool, custom?: bool}>
     */
    public const array SCHEMA = ['activate_comments' => ['type' => 'bool', 'default' => true, 'method' => 'activateComments'], 'activity_display_connections' => ['type' => 'string', 'default' => 'all', 'method' => 'activityDisplayConnections'], 'add_cache_to_storage_chart' => ['type' => 'bool', 'default' => true, 'method' => 'addCacheToStorageChart'], 'admin_theme' => ['type' => 'string', 'default' => 'roma', 'method' => 'adminTheme'], 'album_description_on_all_pages' => ['type' => 'bool', 'default' => false, 'method' => 'albumDescriptionOnAllPages'], 'album_move_delay_before_auto_opening' => ['type' => 'int', 'default' => 3000, 'method' => 'albumMoveDelayBeforeAutoOpening'], 'allow_html_descriptions' => ['type' => 'bool', 'default' => true, 'method' => 'allowHtmlDescriptions'], 'allow_html_in_metadata' => ['type' => 'bool', 'default' => false, 'method' => 'allowHtmlInMetadata'], 'allow_random_representative' => ['type' => 'bool', 'default' => false, 'method' => 'allowRandomRepresentative'], 'allow_user_customization' => ['type' => 'bool', 'default' => true, 'method' => 'allowUserCustomization'], 'allow_user_registration' => ['type' => 'bool', 'default' => true, 'method' => 'allowUserRegistration'], 'allow_web_services' => ['type' => 'bool', 'default' => true, 'method' => 'allowWebServices'], 'alternative_pem_url' => ['type' => 'string', 'default' => '', 'method' => 'alternativePemUrl'], 'animated_webp_compression_quality' => ['type' => 'int', 'default' => 70, 'method' => 'animatedWebpCompressionQuality'], 'anti-flood_time' => ['type' => 'int', 'default' => 60, 'method' => 'antiFloodTime'], 'apache_authentication' => ['type' => 'bool', 'default' => false, 'method' => 'apacheAuthentication'], 'api_key_duration' => ['type' => 'array', 'default' => null, 'method' => 'apiKeyDuration', 'custom' => true], 'api_key_forbidden_methods' => ['type' => 'array', 'default' => null, 'method' => 'apiKeyForbiddenMethods', 'custom' => true], 'auth_key_duration' => ['type' => 'int', 'default' => 3 * 24 * 60 * 60, 'method' => 'authKeyDuration'], 'authorize_remembering' => ['type' => 'bool', 'default' => true, 'method' => 'authorizeRemembering'], 'auto_migrate' => ['type' => 'bool', 'default' => true, 'method' => 'autoMigrate'], 'available_permission_levels' => ['type' => 'array', 'default' => null, 'method' => 'availablePermissionLevels', 'custom' => true], 'batch_manager_images_per_page_global' => ['type' => 'int', 'default' => 20, 'method' => 'batchManagerImagesPerPageGlobal'], 'batch_manager_images_per_page_unit' => ['type' => 'int', 'default' => 5, 'method' => 'batchManagerImagesPerPageUnit'], 'browser_language' => ['type' => 'bool', 'default' => true, 'method' => 'browserLanguage'], 'c13y_ignore' => ['type' => 'string', 'default' => null, 'method' => 'c13yIgnore', 'nullable' => true], 'cache_sizes' => ['type' => 'string', 'default' => null, 'method' => 'cacheSizes', 'nullable' => true], 'calendar_datefield' => ['type' => 'string', 'default' => 'date_creation', 'method' => 'calendarDatefield'], 'calendar_show_any' => ['type' => 'bool', 'default' => true, 'method' => 'calendarShowAny'], 'calendar_show_empty' => ['type' => 'bool', 'default' => true, 'method' => 'calendarShowEmpty'], 'category_url_style' => ['type' => 'string', 'default' => 'id', 'method' => 'categoryUrlStyle'], 'check_upgrade_feed' => ['type' => 'bool', 'default' => false, 'method' => 'checkUpgradeFeed'], 'checksum_compute_blocksize' => ['type' => 'int', 'default' => 50, 'method' => 'checksumComputeBlocksize'], 'chmod_value' => ['type' => 'int', 'default' => 0755, 'method' => 'chmodValue'], 'comment_spam_max_links' => ['type' => 'int', 'default' => 3, 'method' => 'commentSpamMaxLinks'], 'comment_spam_reject' => ['type' => 'bool', 'default' => true, 'method' => 'commentSpamReject'], 'comments_author_mandatory' => ['type' => 'bool', 'default' => false, 'method' => 'commentsAuthorMandatory'], 'comments_email_mandatory' => ['type' => 'bool', 'default' => false, 'method' => 'commentsEmailMandatory'], 'comments_enable_website' => ['type' => 'bool', 'default' => true, 'method' => 'commentsEnableWebsite'], 'comments_forall' => ['type' => 'bool', 'default' => false, 'method' => 'commentsForall'], 'comments_order' => ['type' => 'string', 'default' => 'ASC', 'method' => 'commentsOrder'], 'comments_page_nb_comments' => ['type' => 'int', 'default' => 10, 'method' => 'commentsPageNbComments'], 'comments_validation' => ['type' => 'bool', 'default' => false, 'method' => 'commentsValidation'], 'compiled_template_cache_language' => ['type' => 'bool', 'default' => false, 'method' => 'compiledTemplateCacheLanguage'], 'content_tag_cloud_items_number' => ['type' => 'int', 'default' => 12, 'method' => 'contentTagCloudItemsNumber'], 'dashboard_activity_nb_weeks' => ['type' => 'int', 'default' => 4, 'method' => 'dashboardActivityNbWeeks'], 'dashboard_check_for_updates' => ['type' => 'bool', 'default' => true, 'method' => 'dashboardCheckForUpdates'], 'data_location' => ['type' => 'string', 'default' => '_data/', 'method' => 'dataLocation'], 'db_base' => ['type' => 'string', 'default' => '', 'method' => 'dbName'], 'db_host' => ['type' => 'string', 'default' => 'localhost', 'method' => 'dbHost'], 'db_password' => ['type' => 'string', 'default' => '', 'method' => 'dbPassword'], 'db_prefix' => ['type' => 'string', 'default' => 'piwigo_', 'method' => 'dbPrefix'], 'db_user' => ['type' => 'string', 'default' => '', 'method' => 'dbUser'], 'debug_l10n' => ['type' => 'bool', 'default' => false, 'method' => 'debugL10n'], 'debug_mail' => ['type' => 'bool', 'default' => false, 'method' => 'debugMail'], 'debug_template' => ['type' => 'bool', 'default' => false, 'method' => 'debugTemplate'], 'default_filters_views' => ['type' => 'array', 'default' => null, 'method' => 'defaultFiltersViews', 'custom' => true], 'default_redirect_method' => ['type' => 'string', 'default' => 'http', 'method' => 'defaultRedirectMethod'], 'default_user_id' => ['type' => 'int', 'default' => 2, 'method' => 'defaultUserId'], 'derivative_default_size' => ['type' => 'string', 'default' => 'medium', 'method' => 'derivativeDefaultSize'], 'derivative_url_style' => ['type' => 'int', 'default' => 0, 'method' => 'derivativeUrlStyle'], 'derivatives' => ['type' => 'string', 'default' => null, 'method' => 'derivatives', 'nullable' => true], 'derivatives_strip_metadata_threshold' => ['type' => 'int', 'default' => 256000, 'method' => 'derivativesStripMetadataThreshold'], 'die_on_sql_error' => ['type' => 'bool', 'default' => false, 'method' => 'dieOnSqlError'], 'disabled_derivatives' => ['type' => 'string', 'default' => null, 'method' => 'disabledDerivatives', 'nullable' => true], 'display_fromto' => ['type' => 'bool', 'default' => false, 'method' => 'displayFromto'], 'double_password_type_in_admin' => ['type' => 'bool', 'default' => false, 'method' => 'doublePasswordTypeInAdmin'], 'email_admin_on_comment' => ['type' => 'string', 'default' => 'none', 'method' => 'emailAdminOnComment'], 'email_admin_on_comment_deletion' => ['type' => 'string', 'default' => 'none', 'method' => 'emailAdminOnCommentDeletion'], 'email_admin_on_comment_edition' => ['type' => 'string', 'default' => 'none', 'method' => 'emailAdminOnCommentEdition'], 'email_admin_on_comment_validation' => ['type' => 'string', 'default' => 'none', 'method' => 'emailAdminOnCommentValidation'], 'email_admin_on_new_user' => ['type' => 'string', 'default' => 'none', 'method' => 'emailAdminOnNewUser'], 'empty_lounge_running' => ['type' => 'string', 'default' => null, 'method' => 'emptyLoungeRunning', 'nullable' => true], 'enable_core_update' => ['type' => 'bool', 'default' => true, 'method' => 'enableCoreUpdate'], 'enable_extensions_install' => ['type' => 'bool', 'default' => true, 'method' => 'enableExtensionsInstall'], 'enable_formats' => ['type' => 'bool', 'default' => false, 'method' => 'isFormatsEnabled'], 'enable_plugins' => ['type' => 'bool', 'default' => true, 'method' => 'enablePlugins'], 'enable_synchronization' => ['type' => 'bool', 'default' => true, 'method' => 'enableSynchronization'], 'ext_imagick_dir' => ['type' => 'string', 'default' => '', 'method' => 'extImagickDir'], 'extents_for_templates' => ['type' => 'string', 'default' => null, 'method' => 'extentsForTemplates', 'nullable' => true, 'custom' => true], 'external_authentification' => ['type' => 'bool', 'default' => false, 'method' => 'externalAuthentification'], 'ffmpeg_dir' => ['type' => 'string', 'default' => '', 'method' => 'ffmpegDir'], 'file_ext' => ['type' => 'array', 'default' => null, 'method' => 'fileExtensions', 'custom' => true], 'filter_pages' => ['type' => 'array', 'default' => null, 'method' => 'filterPages', 'custom' => true], 'filters_views' => ['type' => 'string', 'default' => null, 'method' => 'filtersViews', 'nullable' => true], 'format_ext' => ['type' => 'array', 'default' => null, 'method' => 'formatExtensions', 'custom' => true], 'fs_quick_check_last_check' => ['type' => 'string', 'default' => null, 'method' => 'fsQuickCheckLastCheck', 'nullable' => true], 'fs_quick_check_period' => ['type' => 'int', 'default' => 86400, 'method' => 'fsQuickCheckPeriod'], 'full_tag_cloud_items_number' => ['type' => 'int', 'default' => 200, 'method' => 'fullTagCloudItemsNumber'], 'gallery_locked' => ['type' => 'bool', 'default' => false, 'method' => 'galleryLocked'], 'gallery_title' => ['type' => 'string', 'default' => 'Piwigo', 'method' => 'galleryTitle'], 'gallery_url' => ['type' => 'string', 'default' => null, 'method' => 'galleryUrl', 'nullable' => true], 'graphics_library' => ['type' => 'string', 'default' => 'auto', 'method' => 'graphicsLibrary'], 'guest_access' => ['type' => 'bool', 'default' => true, 'method' => 'guestAccess'], 'guest_id' => ['type' => 'int', 'default' => 2, 'method' => 'guestId'], 'header_notes' => ['type' => 'array', 'default' => null, 'method' => 'headerNotes', 'custom' => true], 'history_admin' => ['type' => 'bool', 'default' => false, 'method' => 'historyAdmin'], 'history_autopurge_blocksize' => ['type' => 'int', 'default' => 50000, 'method' => 'historyAutopurgeBlocksize'], 'history_autopurge_every' => ['type' => 'int', 'default' => 1021, 'method' => 'historyAutopurgeEvery'], 'history_autopurge_keep_lines' => ['type' => 'int', 'default' => 1000000, 'method' => 'historyAutopurgeKeepLines'], 'history_guest' => ['type' => 'bool', 'default' => false, 'method' => 'historyGuest'], 'history_sections_cache' => ['type' => 'string', 'default' => null, 'method' => 'historySectionsCache', 'nullable' => true], 'history_summarized_dropped' => ['type' => 'bool', 'default' => false, 'method' => 'historySummarizedDropped'], 'home_page' => ['type' => 'string', 'default' => 'recent_pics', 'method' => 'homePage'], 'index_caddie_icon' => ['type' => 'bool', 'default' => true, 'method' => 'indexCaddieIcon'], 'index_created_date_icon' => ['type' => 'bool', 'default' => true, 'method' => 'indexCreatedDateIcon'], 'index_edit_icon' => ['type' => 'bool', 'default' => true, 'method' => 'indexEditIcon'], 'index_flat_icon' => ['type' => 'bool', 'default' => true, 'method' => 'indexFlatIcon'], 'index_new_icon' => ['type' => 'bool', 'default' => true, 'method' => 'indexNewIcon'], 'index_posted_date_icon' => ['type' => 'bool', 'default' => true, 'method' => 'indexPostedDateIcon'], 'index_search_in_set_action' => ['type' => 'string', 'default' => 'results', 'method' => 'indexSearchInSetAction'], 'index_search_in_set_button' => ['type' => 'bool', 'default' => false, 'method' => 'indexSearchInSetButton'], 'index_sizes_icon' => ['type' => 'bool', 'default' => true, 'method' => 'indexSizesIcon'], 'index_slideshow_icon' => ['type' => 'bool', 'default' => true, 'method' => 'indexSlideShowIcon'], 'index_sort_order_input' => ['type' => 'string', 'default' => '', 'method' => 'indexSortOrderInput'], 'inheritance_by_default' => ['type' => 'bool', 'default' => false, 'method' => 'inheritanceByDefault'], 'insensitive_case_logon' => ['type' => 'bool', 'default' => false, 'method' => 'insensitiveCaseLogon'], 'last_major_update' => ['type' => 'string', 'default' => null, 'method' => 'lastMajorUpdate', 'nullable' => true], 'level_separator' => ['type' => 'string', 'default' => ' / ', 'method' => 'levelSeparator'], 'light_album_manager_threshold' => ['type' => 'int', 'default' => 10000, 'method' => 'lightAlbumManagerThreshold'], 'light_slideshow' => ['type' => 'bool', 'default' => true, 'method' => 'lightSlideshow'], 'linked_album_search_limit' => ['type' => 'int', 'default' => 100, 'method' => 'linkedAlbumSearchLimit'], 'links' => ['type' => 'array', 'default' => null, 'method' => 'links', 'custom' => true], 'log' => ['type' => 'bool', 'default' => false, 'method' => 'logConf'], 'log_archive_days' => ['type' => 'int', 'default' => 30, 'method' => 'logArchiveDays'], 'log_dir' => ['type' => 'string', 'default' => '/logs', 'method' => 'logDir'], 'log_level' => ['type' => 'string', 'default' => 'DEBUG', 'method' => 'logLevel'], 'lounge_activate_threshold' => ['type' => 'int', 'default' => 1, 'method' => 'loungeActivateThreshold'], 'lounge_active' => ['type' => 'bool', 'default' => false, 'method' => 'loungeActive'], 'lounge_max_duration' => ['type' => 'int', 'default' => 300, 'method' => 'loungeMaxDuration'], 'mail_allow_html' => ['type' => 'bool', 'default' => true, 'method' => 'mailAllowHtml'], 'mail_sender_email' => ['type' => 'string', 'default' => '', 'method' => 'mailSenderEmail'], 'mail_sender_name' => ['type' => 'string', 'default' => '', 'method' => 'mailSenderName'], 'mail_theme' => ['type' => 'string', 'default' => 'clear', 'method' => 'mailTheme'], 'max_requests' => ['type' => 'int', 'default' => 3, 'method' => 'maxRequests'], 'menubar_filter_icon' => ['type' => 'bool', 'default' => true, 'method' => 'menubarFilterIcon'], 'menubar_tag_cloud_content' => ['type' => 'string', 'default' => 'all_or_current', 'method' => 'menubarTagCloudContent'], 'menubar_tag_cloud_items_number' => ['type' => 'int', 'default' => 20, 'method' => 'menubarTagCloudItemsNumber'], 'meta_ref' => ['type' => 'bool', 'default' => true, 'method' => 'metaRef'], 'metadata_keyword_separator_regex' => ['type' => 'string', 'default' => '/[.,;]/', 'method' => 'metadataKeywordSeparatorRegex'], 'mobile_theme' => ['type' => 'string', 'default' => '', 'method' => 'mobilTheme'], 'nb_categories_page' => ['type' => 'int', 'default' => 9999, 'method' => 'nbCategoriesPage'], 'nb_comment_page' => ['type' => 'int', 'default' => 10, 'method' => 'nbCommentPage'], 'nb_logs_page' => ['type' => 'int', 'default' => 300, 'method' => 'nbLogsPage'], 'nbm_complementary_mail_content' => ['type' => 'string', 'default' => '', 'method' => 'nbmComplementaryMailContent'], 'nbm_default_value_user_enabled' => ['type' => 'bool', 'default' => false, 'method' => 'nbmDefaultValueUserEnabled'], 'nbm_list_all_enabled_users_to_send' => ['type' => 'bool', 'default' => false, 'method' => 'nbmListAllEnabledUsersToSend'], 'nbm_max_treatment_timeout_percent' => ['type' => 'float', 'default' => 0.8, 'method' => 'nbmMaxTreatmentTimeoutPercent', 'custom' => true], 'nbm_send_detailed_content' => ['type' => 'bool', 'default' => true, 'method' => 'nbmSendDetailedContent'], 'nbm_send_html_mail' => ['type' => 'bool', 'default' => true, 'method' => 'nbmSendHtmlMail'], 'nbm_send_mail_as' => ['type' => 'string', 'default' => '', 'method' => 'nbmSendMailAs'], 'nbm_send_recent_post_dates' => ['type' => 'bool', 'default' => true, 'method' => 'nbmSendRecentPostDates'], 'nbm_treatment_timeout_default' => ['type' => 'int', 'default' => 20, 'method' => 'nbmTreatmentTimeoutDefault'], 'never_delete_originals' => ['type' => 'bool', 'default' => false, 'method' => 'neverDeleteOriginals'], 'newcat_default_commentable' => ['type' => 'bool', 'default' => true, 'method' => 'newcatDefaultCommentable'], 'newcat_default_position' => ['type' => 'string', 'default' => 'first', 'method' => 'newcatDefaultPosition'], 'newcat_default_status' => ['type' => 'string', 'default' => 'public', 'method' => 'newcatDefaultStatus'], 'newcat_default_visible' => ['type' => 'bool', 'default' => true, 'method' => 'newcatDefaultVisible'], 'no_photo_yet_url' => ['type' => 'string', 'default' => 'admin.php?page=photos_add', 'method' => 'noPhotoYetUrl'], 'obligatory_user_mail_address' => ['type' => 'bool', 'default' => false, 'method' => 'obligatoryUserMailAddress'], 'order_by' => ['type' => 'string', 'default' => '', 'method' => 'orderBy'], 'order_by_custom' => ['type' => 'string', 'default' => null, 'method' => 'orderByCustom', 'nullable' => true], 'order_by_inside_category' => ['type' => 'string', 'default' => '', 'method' => 'orderByInsideCategory'], 'order_by_inside_category_custom' => ['type' => 'string', 'default' => null, 'method' => 'orderByInsideCategoryCustom', 'nullable' => true], 'original_resize' => ['type' => 'bool', 'default' => false, 'method' => 'originalResize'], 'original_resize_maxheight' => ['type' => 'int', 'default' => 2000, 'method' => 'originalResizeMaxheight'], 'original_resize_maxwidth' => ['type' => 'int', 'default' => 2000, 'method' => 'originalResizeMaxwidth'], 'original_resize_quality' => ['type' => 'int', 'default' => 95, 'method' => 'originalResizeQuality'], 'original_url_protection' => ['type' => 'string', 'default' => '', 'method' => 'originalUrlProtection'], 'page_banner' => ['type' => 'string', 'default' => '', 'method' => 'pageBanner'], 'paginate_pages_around' => ['type' => 'int', 'default' => 2, 'method' => 'paginatePagesAround'], 'password_activation_duration' => ['type' => 'int', 'default' => 259200, 'method' => 'passwordActivationDuration'], 'password_reset_code_duration' => ['type' => 'int', 'default' => 300, 'method' => 'passwordResetCodeDuration'], 'password_reset_duration' => ['type' => 'int', 'default' => 3600, 'method' => 'passwordResetDuration'], 'pdf_viewer_filesize_threshold' => ['type' => 'int', 'default' => 5, 'method' => 'pdfViewerFilesizeThreshold'], 'pem_languages_category' => ['type' => 'int', 'default' => 8, 'method' => 'pemLanguagesCategory'], 'pem_plugins_category' => ['type' => 'int', 'default' => 12, 'method' => 'pemPluginsCategory'], 'pem_themes_category' => ['type' => 'int', 'default' => 10, 'method' => 'pemThemesCategory'], 'php_extension_in_urls' => ['type' => 'bool', 'default' => true, 'method' => 'phpExtensionInUrls'], 'picture_caddie_icon' => ['type' => 'bool', 'default' => true, 'method' => 'pictureCaddieIcon'], 'picture_download_icon' => ['type' => 'bool', 'default' => true, 'method' => 'pictureDownloadIcon'], 'picture_edit_icon' => ['type' => 'bool', 'default' => true, 'method' => 'pictureEditIcon'], 'picture_ext' => ['type' => 'array', 'default' => null, 'method' => 'pictureExtensions', 'custom' => true], 'picture_favorite_icon' => ['type' => 'bool', 'default' => true, 'method' => 'pictureFavoriteIcon'], 'picture_informations' => ['type' => 'string', 'default' => null, 'method' => 'pictureInformations', 'nullable' => true], 'picture_menu' => ['type' => 'bool', 'default' => true, 'method' => 'pictureMenu'], 'picture_metadata_icon' => ['type' => 'bool', 'default' => true, 'method' => 'pictureMetadataIcon'], 'picture_navigation_icons' => ['type' => 'bool', 'default' => true, 'method' => 'pictureNavigationIcons'], 'picture_navigation_thumb' => ['type' => 'bool', 'default' => true, 'method' => 'pictureNavigationThumb'], 'picture_representative_icon' => ['type' => 'bool', 'default' => true, 'method' => 'pictureRepresentativeIcon'], 'picture_sizes_icon' => ['type' => 'bool', 'default' => true, 'method' => 'pictureSizesIcon'], 'picture_slideshow_icon' => ['type' => 'bool', 'default' => true, 'method' => 'pictureSlideShowIcon'], 'picture_url_style' => ['type' => 'string', 'default' => 'id', 'method' => 'pictureUrlStyle'], 'piwigo_db_version' => ['type' => 'string', 'default' => null, 'method' => 'piwigoDbVersion', 'nullable' => true], 'piwigo_installed_version' => ['type' => 'string', 'default' => null, 'method' => 'piwigoInstalledVersion', 'nullable' => true], 'proxy_auth' => ['type' => 'string', 'default' => '', 'method' => 'proxyAuth'], 'proxy_server' => ['type' => 'string', 'default' => '', 'method' => 'proxyServer'], 'question_mark_in_urls' => ['type' => 'bool', 'default' => true, 'method' => 'questionMarkInUrls'], 'quick_search_include_sub_albums' => ['type' => 'bool', 'default' => false, 'method' => 'quickSearchIncludeSubAlbums'], 'random_index_redirect' => ['type' => 'array', 'default' => null, 'method' => 'randomIndexRedirect', 'custom' => true], 'rate' => ['type' => 'bool', 'default' => true, 'method' => 'rateEnabled'], 'rate_anonymous' => ['type' => 'bool', 'default' => true, 'method' => 'rateAnonymous'], 'rate_items' => ['type' => 'array', 'default' => null, 'method' => 'rateItems', 'custom' => true], 'recent_post_dates' => ['type' => 'array', 'default' => null, 'method' => 'recentPostDates', 'custom' => true], 'related_albums_display_limit' => ['type' => 'int', 'default' => 20, 'method' => 'relatedAlbumsDisplayLimit'], 'related_albums_maximum_items_to_compute' => ['type' => 'int', 'default' => 1000, 'method' => 'relatedAlbumsMaximumItemsToCompute'], 'remember_me_length' => ['type' => 'int', 'default' => 5184000, 'method' => 'rememberMeLength'], 'remember_me_name' => ['type' => 'string', 'default' => 'pwg_remember', 'method' => 'rememberMeName'], 'representative_cache_on_level' => ['type' => 'bool', 'default' => true, 'method' => 'representativeCacheOnLevel'], 'representative_cache_on_subcats' => ['type' => 'bool', 'default' => true, 'method' => 'representativeCacheOnSubcats'], 'rss_feed_author' => ['type' => 'string', 'default' => 'Piwigo notifier', 'method' => 'rssReedAuthor'], 'secret_key' => ['type' => 'string', 'default' => '', 'method' => 'secretKey'], 'send_bcc_mail_webmaster' => ['type' => 'bool', 'default' => false, 'method' => 'sendBccMailWebmaster'], 'send_piwigo_infos' => ['type' => 'bool', 'default' => true, 'method' => 'sendPiwigoInfos'], 'send_piwigo_infos_last_notice' => ['type' => 'string', 'default' => null, 'method' => 'sendPiwigoInfosLastNotice', 'nullable' => true], 'send_piwigo_infos_origin_hash' => ['type' => 'string', 'default' => null, 'method' => 'sendPiwigoInfosOriginHash', 'nullable' => true], 'session_gc_probability' => ['type' => 'int', 'default' => 1, 'method' => 'sessionGcProbability'], 'session_length' => ['type' => 'int', 'default' => 3600, 'method' => 'sessionLength'], 'session_name' => ['type' => 'string', 'default' => 'pwg_id', 'method' => 'sessionName'], 'session_save_handler' => ['type' => 'string', 'default' => 'db', 'method' => 'sessionSaveHandler'], 'session_use_cookies' => ['type' => 'bool', 'default' => true, 'method' => 'sessionUseCookies'], 'session_use_ip_address' => ['type' => 'bool', 'default' => true, 'method' => 'sessionUseIpAddress'], 'session_use_only_cookies' => ['type' => 'bool', 'default' => true, 'method' => 'sessionUseOnlyCookies'], 'session_use_trans_sid' => ['type' => 'bool', 'default' => false, 'method' => 'sessionUseTransSid'], 'show_exif' => ['type' => 'bool', 'default' => true, 'method' => 'showExif'], 'show_exif_fields' => ['type' => 'array', 'default' => null, 'method' => 'showExifFields', 'custom' => true], 'show_gt' => ['type' => 'bool', 'default' => false, 'method' => 'showGt'], 'show_iptc' => ['type' => 'bool', 'default' => false, 'method' => 'showIptc'], 'show_iptc_mapping' => ['type' => 'array', 'default' => null, 'method' => 'showIptcMapping', 'custom' => true], 'show_newsletter_subscription' => ['type' => 'bool', 'default' => true, 'method' => 'showNewsletterSubscription'], 'show_php_errors' => ['type' => 'int', 'default' => E_ALL, 'method' => 'showPhpErrors'], 'show_php_errors_on_frontend' => ['type' => 'bool', 'default' => true, 'method' => 'showPhpErrorsOnFrontend'], 'show_piwigo_latest_news' => ['type' => 'bool', 'default' => true, 'method' => 'showPiwigoLatestNews'], 'show_queries' => ['type' => 'bool', 'default' => false, 'method' => 'showQueries'], 'show_template_in_side_menu' => ['type' => 'bool', 'default' => false, 'method' => 'showTemplateInSideMenu'], 'show_thumbnail_caption' => ['type' => 'bool', 'default' => true, 'method' => 'showThumbnailCaption'], 'show_version' => ['type' => 'bool', 'default' => false, 'method' => 'showVersion'], 'slideshow_period' => ['type' => 'int', 'default' => 4, 'method' => 'slideshowPeriod'], 'slideshow_period_max' => ['type' => 'int', 'default' => 10, 'method' => 'slideshowPeriodMax'], 'slideshow_period_min' => ['type' => 'int', 'default' => 1, 'method' => 'slideshowPeriodMin'], 'slideshow_period_step' => ['type' => 'int', 'default' => 1, 'method' => 'slideshowPeriodStep'], 'slideshow_repeat' => ['type' => 'bool', 'default' => true, 'method' => 'slideshowRepeat'], 'smtp_host' => ['type' => 'string', 'default' => '', 'method' => 'smtpHost'], 'smtp_password' => ['type' => 'string', 'default' => '', 'method' => 'smtpPassword'], 'smtp_secure' => ['type' => 'string', 'default' => null, 'method' => 'smtpSecure', 'nullable' => true], 'smtp_user' => ['type' => 'string', 'default' => '', 'method' => 'smtpUser'], 'stat_compare_year_displayed' => ['type' => 'int', 'default' => 5, 'method' => 'statCompareYearDisplayed'], 'sync_chars_regex' => ['type' => 'string', 'default' => '/^[a-zA-Z0-9-_.]+$/', 'method' => 'syncCharsRegex'], 'sync_exclude_folders' => ['type' => 'array', 'default' => null, 'method' => 'syncExcludeFolders', 'custom' => true], 'tag_letters_column_number' => ['type' => 'int', 'default' => 4, 'method' => 'tagLettersColumnNumber'], 'tag_url_style' => ['type' => 'string', 'default' => 'id-tag', 'method' => 'tagUrlStyle'], 'tags_default_display_mode' => ['type' => 'string', 'default' => 'cloud', 'method' => 'tagsDefaultDisplayMode'], 'tags_levels' => ['type' => 'int', 'default' => 5, 'method' => 'tagsLevels'], 'template_combine_files' => ['type' => 'bool', 'default' => true, 'method' => 'templateCombineFiles'], 'template_compile_check' => ['type' => 'bool', 'default' => true, 'method' => 'templateCompileCheck'], 'template_force_compile' => ['type' => 'bool', 'default' => false, 'method' => 'templateForceCompile'], 'themes_dir' => ['type' => 'string', 'default' => './themes', 'method' => 'themesDir'], 'tiff_representative_ext' => ['type' => 'string', 'default' => 'png', 'method' => 'tiffRepresentativeExt'], 'top_number' => ['type' => 'int', 'default' => 15, 'method' => 'topNumber'], 'uniqueness_mode' => ['type' => 'string', 'default' => 'md5sum', 'method' => 'uniquenessMode'], 'update_notify_check_period' => ['type' => 'int', 'default' => 86400, 'method' => 'updateNotifyCheckPeriod'], 'update_notify_last_check' => ['type' => 'string', 'default' => null, 'method' => 'updateNotifyLastCheck', 'nullable' => true], 'update_notify_last_notification' => ['type' => 'string', 'default' => null, 'method' => 'updateNotifyLastNotification', 'nullable' => true], 'update_notify_reminder_period' => ['type' => 'int', 'default' => 604800, 'method' => 'updateNotifyReminderPeriod'], 'updates_ignored' => ['type' => 'array', 'default' => null, 'method' => 'updatesIgnored', 'custom' => true], 'upload_detect_duplicate' => ['type' => 'bool', 'default' => true, 'method' => 'uploadDetectDuplicate'], 'upload_dir' => ['type' => 'string', 'default' => './upload', 'method' => 'uploadDir'], 'upload_form_all_types' => ['type' => 'bool', 'default' => false, 'method' => 'uploadFormAllTypes'], 'upload_form_automatic_rotation' => ['type' => 'bool', 'default' => true, 'method' => 'uploadFormAutomaticRotation'], 'upload_form_chunk_size' => ['type' => 'int', 'default' => 500, 'method' => 'uploadFormChunkSize'], 'upload_form_max_file_size' => ['type' => 'int', 'default' => 1000, 'method' => 'uploadFormMaxFileSize'], 'url_port' => ['type' => 'string', 'default' => 'none', 'method' => 'urlPort'], 'use_exif' => ['type' => 'bool', 'default' => true, 'method' => 'useExif'], 'use_exif_mapping' => ['type' => 'array', 'default' => null, 'method' => 'useExifMapping', 'custom' => true], 'use_iptc' => ['type' => 'bool', 'default' => false, 'method' => 'useIptc'], 'use_iptc_mapping' => ['type' => 'array', 'default' => null, 'method' => 'useIptcMapping', 'custom' => true], 'use_proxy' => ['type' => 'bool', 'default' => false, 'method' => 'useProxy'], 'user_can_delete_comment' => ['type' => 'bool', 'default' => false, 'method' => 'userCanDeleteComment'], 'user_can_edit_comment' => ['type' => 'bool', 'default' => false, 'method' => 'userCanEditComment'], 'user_fields' => ['type' => 'array', 'default' => null, 'method' => 'userFields', 'custom' => true], 'users_table' => ['type' => 'string', 'default' => null, 'method' => 'usersTable', 'nullable' => true], 'webmaster_id' => ['type' => 'int', 'default' => 1, 'method' => 'webmasterId'], 'week_starts_on' => ['type' => 'string', 'default' => 'monday', 'method' => 'weekStartsOn'], 'ws_max_images_per_page' => ['type' => 'int', 'default' => 500, 'method' => 'wsMaxImagesPerPage'], 'ws_max_users_per_page' => ['type' => 'int', 'default' => 1000, 'method' => 'wsMaxUsersPerPage']];
    private function __construct()
    {
    }
    /** Singleton handle — used by ServiceLocator; all data methods are static. */
    public static function instance(): self
    {
        return self::$singleton ??= new self();
    }
    /** @return array<string,mixed> */
    private static function src(): array
    {
        return self::$data;
    }
    /**
     * Public escape hatch for parametric / dynamic keys (per-block menu config,
     * *_running semaphores, flip_picture_ext caches, DB row write loops, etc.).
     * Bypasses SCHEMA validation by design.
     *
     * Static keys MUST go through a typed accessor — `raw()` is for cases where
     * the key is computed at runtime and cannot be expressed via SCHEMA.
     */
    public static function raw(string $key, mixed $default = null): mixed
    {
        return self::src()[$key] ?? $default;
    }

    private static function getString(string $key, string $default = ''): string
    {
        if (!array_key_exists($key, self::SCHEMA)) {
            throw new UnknownConfigKeyException($key, 'getString');
        }
        $v = self::src()[$key] ?? $default;
        if (is_string($v)) {
            return $v;
        }
        if (is_scalar($v)) {
            return (string) $v;
        }
        return $default;
    }

    private static function getInt(string $key, int $default = 0): int
    {
        if (!array_key_exists($key, self::SCHEMA)) {
            throw new UnknownConfigKeyException($key, 'getInt');
        }
        $v = self::src()[$key] ?? $default;
        if (is_int($v)) {
            return $v;
        }
        if (is_scalar($v)) {
            return (int) $v;
        }
        return $default;
    }

    private static function getBool(string $key, bool $default = false): bool
    {
        if (!array_key_exists($key, self::SCHEMA)) {
            throw new UnknownConfigKeyException($key, 'getBool');
        }
        $v = self::src()[$key] ?? $default;
        return (bool) $v;
    }

    // <<<CONFIG-ACCESSORS-BEGIN>>>
    public static function activateComments(): bool
    {
        return self::getBool('activate_comments', true);
    }
    public static function activityDisplayConnections(): string
    {
        return self::getString('activity_display_connections', 'all');
    }
    public static function addCacheToStorageChart(): bool
    {
        return self::getBool('add_cache_to_storage_chart', true);
    }
    public static function adminTheme(): string
    {
        return self::getString('admin_theme', 'roma');
    }
    public static function albumDescriptionOnAllPages(): bool
    {
        return self::getBool('album_description_on_all_pages', false);
    }
    public static function albumMoveDelayBeforeAutoOpening(): int
    {
        return self::getInt('album_move_delay_before_auto_opening', 3000);
    }
    public static function allowHtmlDescriptions(): bool
    {
        return self::getBool('allow_html_descriptions', true);
    }
    public static function allowHtmlInMetadata(): bool
    {
        return self::getBool('allow_html_in_metadata', false);
    }
    public static function allowRandomRepresentative(): bool
    {
        return self::getBool('allow_random_representative', false);
    }
    public static function allowUserCustomization(): bool
    {
        return self::getBool('allow_user_customization', true);
    }
    public static function allowUserRegistration(): bool
    {
        return self::getBool('allow_user_registration', true);
    }
    public static function allowWebServices(): bool
    {
        return self::getBool('allow_web_services', true);
    }
    public static function alternativePemUrl(): string
    {
        return self::getString('alternative_pem_url', '');
    }
    public static function animatedWebpCompressionQuality(): int
    {
        return self::getInt('animated_webp_compression_quality', 70);
    }
    public static function antiFloodTime(): int
    {
        return self::getInt('anti-flood_time', 60);
    }
    public static function apacheAuthentication(): bool
    {
        return self::getBool('apache_authentication', false);
    }
    public static function authKeyDuration(): int
    {
        return self::getInt('auth_key_duration', 259200);
    }
    public static function authorizeRemembering(): bool
    {
        return self::getBool('authorize_remembering', true);
    }
    public static function autoMigrate(): bool
    {
        return self::getBool('auto_migrate', true);
    }
    public static function batchManagerImagesPerPageGlobal(): int
    {
        return self::getInt('batch_manager_images_per_page_global', 20);
    }
    public static function batchManagerImagesPerPageUnit(): int
    {
        return self::getInt('batch_manager_images_per_page_unit', 5);
    }
    public static function browserLanguage(): bool
    {
        return self::getBool('browser_language', true);
    }
    public static function c13yIgnore(): ?string
    {
        $v = self::src()['c13y_ignore'] ?? null;
        return $v !== null ? (is_scalar($v) ? (string) $v : null) : null;
    }
    public static function cacheSizes(): ?string
    {
        $v = self::src()['cache_sizes'] ?? null;
        return $v !== null ? (is_scalar($v) ? (string) $v : null) : null;
    }
    public static function calendarDatefield(): string
    {
        return self::getString('calendar_datefield', 'date_creation');
    }
    public static function calendarShowAny(): bool
    {
        return self::getBool('calendar_show_any', true);
    }
    public static function calendarShowEmpty(): bool
    {
        return self::getBool('calendar_show_empty', true);
    }
    public static function categoryUrlStyle(): string
    {
        return self::getString('category_url_style', 'id');
    }
    public static function checkUpgradeFeed(): bool
    {
        return self::getBool('check_upgrade_feed', false);
    }
    public static function checksumComputeBlocksize(): int
    {
        return self::getInt('checksum_compute_blocksize', 50);
    }
    public static function chmodValue(): int
    {
        return self::getInt('chmod_value', 493);
    }
    public static function commentSpamMaxLinks(): int
    {
        return self::getInt('comment_spam_max_links', 3);
    }
    public static function commentSpamReject(): bool
    {
        return self::getBool('comment_spam_reject', true);
    }
    public static function commentsAuthorMandatory(): bool
    {
        return self::getBool('comments_author_mandatory', false);
    }
    public static function commentsEmailMandatory(): bool
    {
        return self::getBool('comments_email_mandatory', false);
    }
    public static function commentsEnableWebsite(): bool
    {
        return self::getBool('comments_enable_website', true);
    }
    public static function commentsForall(): bool
    {
        return self::getBool('comments_forall', false);
    }
    public static function commentsOrder(): string
    {
        return self::getString('comments_order', 'ASC');
    }
    public static function commentsPageNbComments(): int
    {
        return self::getInt('comments_page_nb_comments', 10);
    }
    public static function commentsValidation(): bool
    {
        return self::getBool('comments_validation', false);
    }
    public static function compiledTemplateCacheLanguage(): bool
    {
        return self::getBool('compiled_template_cache_language', false);
    }
    public static function contentTagCloudItemsNumber(): int
    {
        return self::getInt('content_tag_cloud_items_number', 12);
    }
    public static function dashboardActivityNbWeeks(): int
    {
        return self::getInt('dashboard_activity_nb_weeks', 4);
    }
    public static function dashboardCheckForUpdates(): bool
    {
        return self::getBool('dashboard_check_for_updates', true);
    }
    public static function dataLocation(): string
    {
        return self::getString('data_location', '_data/');
    }
    public static function dbName(): string
    {
        return self::getString('db_base', '');
    }
    public static function dbHost(): string
    {
        return self::getString('db_host', 'localhost');
    }
    public static function dbPassword(): string
    {
        return self::getString('db_password', '');
    }
    public static function dbPrefix(): string
    {
        return self::getString('db_prefix', 'piwigo_');
    }
    public static function dbUser(): string
    {
        return self::getString('db_user', '');
    }
    public static function debugL10n(): bool
    {
        return self::getBool('debug_l10n', false);
    }
    public static function debugMail(): bool
    {
        return self::getBool('debug_mail', false);
    }
    public static function debugTemplate(): bool
    {
        return self::getBool('debug_template', false);
    }
    public static function defaultRedirectMethod(): string
    {
        return self::getString('default_redirect_method', 'http');
    }
    public static function defaultUserId(): int
    {
        return self::getInt('default_user_id', 2);
    }
    public static function derivativeDefaultSize(): string
    {
        return self::getString('derivative_default_size', 'medium');
    }
    public static function derivativeUrlStyle(): int
    {
        return self::getInt('derivative_url_style', 0);
    }
    public static function derivatives(): ?string
    {
        $v = self::src()['derivatives'] ?? null;
        return $v !== null ? (is_scalar($v) ? (string) $v : null) : null;
    }
    public static function derivativesStripMetadataThreshold(): int
    {
        return self::getInt('derivatives_strip_metadata_threshold', 256000);
    }
    public static function dieOnSqlError(): bool
    {
        return self::getBool('die_on_sql_error', false);
    }
    public static function disabledDerivatives(): ?string
    {
        $v = self::src()['disabled_derivatives'] ?? null;
        return $v !== null ? (is_scalar($v) ? (string) $v : null) : null;
    }
    public static function displayFromto(): bool
    {
        return self::getBool('display_fromto', false);
    }
    public static function doublePasswordTypeInAdmin(): bool
    {
        return self::getBool('double_password_type_in_admin', false);
    }
    public static function emailAdminOnComment(): string
    {
        return self::getString('email_admin_on_comment', 'none');
    }
    public static function emailAdminOnCommentDeletion(): string
    {
        return self::getString('email_admin_on_comment_deletion', 'none');
    }
    public static function emailAdminOnCommentEdition(): string
    {
        return self::getString('email_admin_on_comment_edition', 'none');
    }
    public static function emailAdminOnCommentValidation(): string
    {
        return self::getString('email_admin_on_comment_validation', 'none');
    }
    public static function emailAdminOnNewUser(): string
    {
        return self::getString('email_admin_on_new_user', 'none');
    }
    public static function emptyLoungeRunning(): ?string
    {
        $v = self::src()['empty_lounge_running'] ?? null;
        return $v !== null ? (is_scalar($v) ? (string) $v : null) : null;
    }
    public static function enableCoreUpdate(): bool
    {
        return self::getBool('enable_core_update', true);
    }
    public static function enableExtensionsInstall(): bool
    {
        return self::getBool('enable_extensions_install', true);
    }
    public static function isFormatsEnabled(): bool
    {
        return self::getBool('enable_formats', false);
    }
    public static function enablePlugins(): bool
    {
        return self::getBool('enable_plugins', true);
    }
    public static function enableSynchronization(): bool
    {
        return self::getBool('enable_synchronization', true);
    }
    public static function extImagickDir(): string
    {
        return self::getString('ext_imagick_dir', '');
    }
    public static function externalAuthentification(): bool
    {
        return self::getBool('external_authentification', false);
    }
    public static function ffmpegDir(): string
    {
        return self::getString('ffmpeg_dir', '');
    }
    public static function filtersViews(): ?string
    {
        $v = self::src()['filters_views'] ?? null;
        return $v !== null ? (is_scalar($v) ? (string) $v : null) : null;
    }
    public static function fsQuickCheckLastCheck(): ?string
    {
        $v = self::src()['fs_quick_check_last_check'] ?? null;
        return $v !== null ? (is_scalar($v) ? (string) $v : null) : null;
    }
    public static function fsQuickCheckPeriod(): int
    {
        return self::getInt('fs_quick_check_period', 86400);
    }
    public static function fullTagCloudItemsNumber(): int
    {
        return self::getInt('full_tag_cloud_items_number', 200);
    }
    public static function galleryLocked(): bool
    {
        return self::getBool('gallery_locked', false);
    }
    public static function galleryTitle(): string
    {
        return self::getString('gallery_title', 'Piwigo');
    }
    public static function galleryUrl(): ?string
    {
        $v = self::src()['gallery_url'] ?? null;
        return $v !== null ? (is_scalar($v) ? (string) $v : null) : null;
    }
    public static function graphicsLibrary(): string
    {
        return self::getString('graphics_library', 'auto');
    }
    public static function guestAccess(): bool
    {
        return self::getBool('guest_access', true);
    }
    public static function guestId(): int
    {
        return self::getInt('guest_id', 2);
    }
    public static function historyAdmin(): bool
    {
        return self::getBool('history_admin', false);
    }
    public static function historyAutopurgeBlocksize(): int
    {
        return self::getInt('history_autopurge_blocksize', 50000);
    }
    public static function historyAutopurgeEvery(): int
    {
        return self::getInt('history_autopurge_every', 1021);
    }
    public static function historyAutopurgeKeepLines(): int
    {
        return self::getInt('history_autopurge_keep_lines', 1000000);
    }
    public static function historyGuest(): bool
    {
        return self::getBool('history_guest', false);
    }
    public static function historySectionsCache(): ?string
    {
        $v = self::src()['history_sections_cache'] ?? null;
        return $v !== null ? (is_scalar($v) ? (string) $v : null) : null;
    }
    public static function historySummarizedDropped(): bool
    {
        return self::getBool('history_summarized_dropped', false);
    }
    public static function homePage(): string
    {
        return self::getString('home_page', 'recent_pics');
    }
    public static function indexCaddieIcon(): bool
    {
        return self::getBool('index_caddie_icon', true);
    }
    public static function indexCreatedDateIcon(): bool
    {
        return self::getBool('index_created_date_icon', true);
    }
    public static function indexEditIcon(): bool
    {
        return self::getBool('index_edit_icon', true);
    }
    public static function indexFlatIcon(): bool
    {
        return self::getBool('index_flat_icon', true);
    }
    public static function indexNewIcon(): bool
    {
        return self::getBool('index_new_icon', true);
    }
    public static function indexPostedDateIcon(): bool
    {
        return self::getBool('index_posted_date_icon', true);
    }
    public static function indexSearchInSetAction(): string
    {
        return self::getString('index_search_in_set_action', 'results');
    }
    public static function indexSearchInSetButton(): bool
    {
        return self::getBool('index_search_in_set_button', false);
    }
    public static function indexSizesIcon(): bool
    {
        return self::getBool('index_sizes_icon', true);
    }
    public static function indexSlideShowIcon(): bool
    {
        return self::getBool('index_slideshow_icon', true);
    }
    public static function indexSortOrderInput(): string
    {
        return self::getString('index_sort_order_input', '');
    }
    public static function inheritanceByDefault(): bool
    {
        return self::getBool('inheritance_by_default', false);
    }
    public static function insensitiveCaseLogon(): bool
    {
        return self::getBool('insensitive_case_logon', false);
    }
    public static function lastMajorUpdate(): ?string
    {
        $v = self::src()['last_major_update'] ?? null;
        return $v !== null ? (is_scalar($v) ? (string) $v : null) : null;
    }
    public static function levelSeparator(): string
    {
        return self::getString('level_separator', ' / ');
    }
    public static function lightAlbumManagerThreshold(): int
    {
        return self::getInt('light_album_manager_threshold', 10000);
    }
    public static function lightSlideshow(): bool
    {
        return self::getBool('light_slideshow', true);
    }
    public static function linkedAlbumSearchLimit(): int
    {
        return self::getInt('linked_album_search_limit', 100);
    }
    public static function logConf(): bool
    {
        return self::getBool('log', false);
    }
    public static function logArchiveDays(): int
    {
        return self::getInt('log_archive_days', 30);
    }
    public static function logDir(): string
    {
        return self::getString('log_dir', '/logs');
    }
    public static function logLevel(): string
    {
        return self::getString('log_level', 'DEBUG');
    }
    public static function loungeActivateThreshold(): int
    {
        return self::getInt('lounge_activate_threshold', 1);
    }
    public static function loungeActive(): bool
    {
        return self::getBool('lounge_active', false);
    }
    public static function loungeMaxDuration(): int
    {
        return self::getInt('lounge_max_duration', 300);
    }
    public static function mailAllowHtml(): bool
    {
        return self::getBool('mail_allow_html', true);
    }
    public static function mailSenderEmail(): string
    {
        return self::getString('mail_sender_email', '');
    }
    public static function mailSenderName(): string
    {
        return self::getString('mail_sender_name', '');
    }
    public static function mailTheme(): string
    {
        return self::getString('mail_theme', 'clear');
    }
    public static function maxRequests(): int
    {
        return self::getInt('max_requests', 3);
    }
    public static function menubarFilterIcon(): bool
    {
        return self::getBool('menubar_filter_icon', true);
    }
    public static function menubarTagCloudContent(): string
    {
        return self::getString('menubar_tag_cloud_content', 'all_or_current');
    }
    public static function menubarTagCloudItemsNumber(): int
    {
        return self::getInt('menubar_tag_cloud_items_number', 20);
    }
    public static function metaRef(): bool
    {
        return self::getBool('meta_ref', true);
    }
    public static function metadataKeywordSeparatorRegex(): string
    {
        return self::getString('metadata_keyword_separator_regex', '/[.,;]/');
    }
    public static function mobilTheme(): string
    {
        return self::getString('mobile_theme', '');
    }
    public static function nbCategoriesPage(): int
    {
        return self::getInt('nb_categories_page', 9999);
    }
    public static function nbCommentPage(): int
    {
        return self::getInt('nb_comment_page', 10);
    }
    public static function nbLogsPage(): int
    {
        return self::getInt('nb_logs_page', 300);
    }
    public static function nbmComplementaryMailContent(): string
    {
        return self::getString('nbm_complementary_mail_content', '');
    }
    public static function nbmDefaultValueUserEnabled(): bool
    {
        return self::getBool('nbm_default_value_user_enabled', false);
    }
    public static function nbmListAllEnabledUsersToSend(): bool
    {
        return self::getBool('nbm_list_all_enabled_users_to_send', false);
    }
    public static function nbmSendDetailedContent(): bool
    {
        return self::getBool('nbm_send_detailed_content', true);
    }
    public static function nbmSendHtmlMail(): bool
    {
        return self::getBool('nbm_send_html_mail', true);
    }
    public static function nbmSendMailAs(): string
    {
        return self::getString('nbm_send_mail_as', '');
    }
    public static function nbmSendRecentPostDates(): bool
    {
        return self::getBool('nbm_send_recent_post_dates', true);
    }
    public static function nbmTreatmentTimeoutDefault(): int
    {
        return self::getInt('nbm_treatment_timeout_default', 20);
    }
    public static function neverDeleteOriginals(): bool
    {
        return self::getBool('never_delete_originals', false);
    }
    public static function newcatDefaultCommentable(): bool
    {
        return self::getBool('newcat_default_commentable', true);
    }
    public static function newcatDefaultPosition(): string
    {
        return self::getString('newcat_default_position', 'first');
    }
    public static function newcatDefaultStatus(): string
    {
        return self::getString('newcat_default_status', 'public');
    }
    public static function newcatDefaultVisible(): bool
    {
        return self::getBool('newcat_default_visible', true);
    }
    public static function noPhotoYetUrl(): string
    {
        return self::getString('no_photo_yet_url', 'admin.php?page=photos_add');
    }
    public static function obligatoryUserMailAddress(): bool
    {
        return self::getBool('obligatory_user_mail_address', false);
    }
    public static function orderBy(): string
    {
        return self::getString('order_by', '');
    }
    public static function orderByCustom(): ?string
    {
        $v = self::src()['order_by_custom'] ?? null;
        return $v !== null ? (is_scalar($v) ? (string) $v : null) : null;
    }
    public static function orderByInsideCategory(): string
    {
        return self::getString('order_by_inside_category', '');
    }
    public static function orderByInsideCategoryCustom(): ?string
    {
        $v = self::src()['order_by_inside_category_custom'] ?? null;
        return $v !== null ? (is_scalar($v) ? (string) $v : null) : null;
    }
    public static function originalResize(): bool
    {
        return self::getBool('original_resize', false);
    }
    public static function originalResizeMaxheight(): int
    {
        return self::getInt('original_resize_maxheight', 2000);
    }
    public static function originalResizeMaxwidth(): int
    {
        return self::getInt('original_resize_maxwidth', 2000);
    }
    public static function originalResizeQuality(): int
    {
        return self::getInt('original_resize_quality', 95);
    }
    public static function originalUrlProtection(): string
    {
        return self::getString('original_url_protection', '');
    }
    public static function pageBanner(): string
    {
        return self::getString('page_banner', '');
    }
    public static function paginatePagesAround(): int
    {
        return self::getInt('paginate_pages_around', 2);
    }
    public static function passwordActivationDuration(): int
    {
        return self::getInt('password_activation_duration', 259200);
    }
    public static function passwordResetCodeDuration(): int
    {
        return self::getInt('password_reset_code_duration', 300);
    }
    public static function passwordResetDuration(): int
    {
        return self::getInt('password_reset_duration', 3600);
    }
    public static function pdfViewerFilesizeThreshold(): int
    {
        return self::getInt('pdf_viewer_filesize_threshold', 5);
    }
    public static function pemLanguagesCategory(): int
    {
        return self::getInt('pem_languages_category', 8);
    }
    public static function pemPluginsCategory(): int
    {
        return self::getInt('pem_plugins_category', 12);
    }
    public static function pemThemesCategory(): int
    {
        return self::getInt('pem_themes_category', 10);
    }
    public static function phpExtensionInUrls(): bool
    {
        return self::getBool('php_extension_in_urls', true);
    }
    public static function pictureCaddieIcon(): bool
    {
        return self::getBool('picture_caddie_icon', true);
    }
    public static function pictureDownloadIcon(): bool
    {
        return self::getBool('picture_download_icon', true);
    }
    public static function pictureEditIcon(): bool
    {
        return self::getBool('picture_edit_icon', true);
    }
    public static function pictureFavoriteIcon(): bool
    {
        return self::getBool('picture_favorite_icon', true);
    }
    public static function pictureInformations(): ?string
    {
        $v = self::src()['picture_informations'] ?? null;
        return $v !== null ? (is_scalar($v) ? (string) $v : null) : null;
    }
    public static function pictureMenu(): bool
    {
        return self::getBool('picture_menu', true);
    }
    public static function pictureMetadataIcon(): bool
    {
        return self::getBool('picture_metadata_icon', true);
    }
    public static function pictureNavigationIcons(): bool
    {
        return self::getBool('picture_navigation_icons', true);
    }
    public static function pictureNavigationThumb(): bool
    {
        return self::getBool('picture_navigation_thumb', true);
    }
    public static function pictureRepresentativeIcon(): bool
    {
        return self::getBool('picture_representative_icon', true);
    }
    public static function pictureSizesIcon(): bool
    {
        return self::getBool('picture_sizes_icon', true);
    }
    public static function pictureSlideShowIcon(): bool
    {
        return self::getBool('picture_slideshow_icon', true);
    }
    public static function pictureUrlStyle(): string
    {
        return self::getString('picture_url_style', 'id');
    }
    public static function piwigoDbVersion(): ?string
    {
        $v = self::src()['piwigo_db_version'] ?? null;
        return $v !== null ? (is_scalar($v) ? (string) $v : null) : null;
    }
    public static function piwigoInstalledVersion(): ?string
    {
        $v = self::src()['piwigo_installed_version'] ?? null;
        return $v !== null ? (is_scalar($v) ? (string) $v : null) : null;
    }
    public static function proxyAuth(): string
    {
        return self::getString('proxy_auth', '');
    }
    public static function proxyServer(): string
    {
        return self::getString('proxy_server', '');
    }
    public static function questionMarkInUrls(): bool
    {
        return self::getBool('question_mark_in_urls', true);
    }
    public static function quickSearchIncludeSubAlbums(): bool
    {
        return self::getBool('quick_search_include_sub_albums', false);
    }
    public static function rateEnabled(): bool
    {
        return self::getBool('rate', true);
    }
    public static function rateAnonymous(): bool
    {
        return self::getBool('rate_anonymous', true);
    }
    public static function relatedAlbumsDisplayLimit(): int
    {
        return self::getInt('related_albums_display_limit', 20);
    }
    public static function relatedAlbumsMaximumItemsToCompute(): int
    {
        return self::getInt('related_albums_maximum_items_to_compute', 1000);
    }
    public static function rememberMeLength(): int
    {
        return self::getInt('remember_me_length', 5184000);
    }
    public static function rememberMeName(): string
    {
        return self::getString('remember_me_name', 'pwg_remember');
    }
    public static function representativeCacheOnLevel(): bool
    {
        return self::getBool('representative_cache_on_level', true);
    }
    public static function representativeCacheOnSubcats(): bool
    {
        return self::getBool('representative_cache_on_subcats', true);
    }
    public static function rssReedAuthor(): string
    {
        return self::getString('rss_feed_author', 'Piwigo notifier');
    }
    public static function secretKey(): string
    {
        return self::getString('secret_key', '');
    }
    public static function sendBccMailWebmaster(): bool
    {
        return self::getBool('send_bcc_mail_webmaster', false);
    }
    public static function sendPiwigoInfos(): bool
    {
        return self::getBool('send_piwigo_infos', true);
    }
    public static function sendPiwigoInfosLastNotice(): ?string
    {
        $v = self::src()['send_piwigo_infos_last_notice'] ?? null;
        return $v !== null ? (is_scalar($v) ? (string) $v : null) : null;
    }
    public static function sendPiwigoInfosOriginHash(): ?string
    {
        $v = self::src()['send_piwigo_infos_origin_hash'] ?? null;
        return $v !== null ? (is_scalar($v) ? (string) $v : null) : null;
    }
    public static function sessionGcProbability(): int
    {
        return self::getInt('session_gc_probability', 1);
    }
    public static function sessionLength(): int
    {
        return self::getInt('session_length', 3600);
    }
    public static function sessionName(): string
    {
        return self::getString('session_name', 'pwg_id');
    }
    public static function sessionSaveHandler(): string
    {
        return self::getString('session_save_handler', 'db');
    }
    public static function sessionUseCookies(): bool
    {
        return self::getBool('session_use_cookies', true);
    }
    public static function sessionUseIpAddress(): bool
    {
        return self::getBool('session_use_ip_address', true);
    }
    public static function sessionUseOnlyCookies(): bool
    {
        return self::getBool('session_use_only_cookies', true);
    }
    public static function sessionUseTransSid(): bool
    {
        return self::getBool('session_use_trans_sid', false);
    }
    public static function showExif(): bool
    {
        return self::getBool('show_exif', true);
    }
    public static function showGt(): bool
    {
        return self::getBool('show_gt', false);
    }
    public static function showIptc(): bool
    {
        return self::getBool('show_iptc', false);
    }
    public static function showNewsletterSubscription(): bool
    {
        return self::getBool('show_newsletter_subscription', true);
    }
    public static function showPhpErrors(): int
    {
        return self::getInt('show_php_errors', 30719);
    }
    public static function showPhpErrorsOnFrontend(): bool
    {
        return self::getBool('show_php_errors_on_frontend', true);
    }
    public static function showPiwigoLatestNews(): bool
    {
        return self::getBool('show_piwigo_latest_news', true);
    }
    public static function showQueries(): bool
    {
        return self::getBool('show_queries', false);
    }
    public static function showTemplateInSideMenu(): bool
    {
        return self::getBool('show_template_in_side_menu', false);
    }
    public static function showThumbnailCaption(): bool
    {
        return self::getBool('show_thumbnail_caption', true);
    }
    public static function showVersion(): bool
    {
        return self::getBool('show_version', false);
    }
    public static function slideshowPeriod(): int
    {
        return self::getInt('slideshow_period', 4);
    }
    public static function slideshowPeriodMax(): int
    {
        return self::getInt('slideshow_period_max', 10);
    }
    public static function slideshowPeriodMin(): int
    {
        return self::getInt('slideshow_period_min', 1);
    }
    public static function slideshowPeriodStep(): int
    {
        return self::getInt('slideshow_period_step', 1);
    }
    public static function slideshowRepeat(): bool
    {
        return self::getBool('slideshow_repeat', true);
    }
    public static function smtpHost(): string
    {
        return self::getString('smtp_host', '');
    }
    public static function smtpPassword(): string
    {
        return self::getString('smtp_password', '');
    }
    public static function smtpSecure(): ?string
    {
        $v = self::src()['smtp_secure'] ?? null;
        return $v !== null ? (is_scalar($v) ? (string) $v : null) : null;
    }
    public static function smtpUser(): string
    {
        return self::getString('smtp_user', '');
    }
    public static function statCompareYearDisplayed(): int
    {
        return self::getInt('stat_compare_year_displayed', 5);
    }
    public static function syncCharsRegex(): string
    {
        return self::getString('sync_chars_regex', '/^[a-zA-Z0-9-_.]+$/');
    }
    public static function tagLettersColumnNumber(): int
    {
        return self::getInt('tag_letters_column_number', 4);
    }
    public static function tagUrlStyle(): string
    {
        return self::getString('tag_url_style', 'id-tag');
    }
    public static function tagsDefaultDisplayMode(): string
    {
        return self::getString('tags_default_display_mode', 'cloud');
    }
    public static function tagsLevels(): int
    {
        return self::getInt('tags_levels', 5);
    }
    public static function templateCombineFiles(): bool
    {
        return self::getBool('template_combine_files', true);
    }
    public static function templateCompileCheck(): bool
    {
        return self::getBool('template_compile_check', true);
    }
    public static function templateForceCompile(): bool
    {
        return self::getBool('template_force_compile', false);
    }
    public static function themesDir(): string
    {
        return self::getString('themes_dir', './themes');
    }
    public static function tiffRepresentativeExt(): string
    {
        return self::getString('tiff_representative_ext', 'png');
    }
    public static function topNumber(): int
    {
        return self::getInt('top_number', 15);
    }
    public static function uniquenessMode(): string
    {
        return self::getString('uniqueness_mode', 'md5sum');
    }
    public static function updateNotifyCheckPeriod(): int
    {
        return self::getInt('update_notify_check_period', 86400);
    }
    public static function updateNotifyLastCheck(): ?string
    {
        $v = self::src()['update_notify_last_check'] ?? null;
        return $v !== null ? (is_scalar($v) ? (string) $v : null) : null;
    }
    public static function updateNotifyLastNotification(): ?string
    {
        $v = self::src()['update_notify_last_notification'] ?? null;
        return $v !== null ? (is_scalar($v) ? (string) $v : null) : null;
    }
    public static function updateNotifyReminderPeriod(): int
    {
        return self::getInt('update_notify_reminder_period', 604800);
    }
    public static function uploadDetectDuplicate(): bool
    {
        return self::getBool('upload_detect_duplicate', true);
    }
    public static function uploadDir(): string
    {
        return self::getString('upload_dir', './upload');
    }
    public static function uploadFormAllTypes(): bool
    {
        return self::getBool('upload_form_all_types', false);
    }
    public static function uploadFormAutomaticRotation(): bool
    {
        return self::getBool('upload_form_automatic_rotation', true);
    }
    public static function uploadFormChunkSize(): int
    {
        return self::getInt('upload_form_chunk_size', 500);
    }
    public static function uploadFormMaxFileSize(): int
    {
        return self::getInt('upload_form_max_file_size', 1000);
    }
    public static function urlPort(): string
    {
        return self::getString('url_port', 'none');
    }
    public static function useExif(): bool
    {
        return self::getBool('use_exif', true);
    }
    public static function useIptc(): bool
    {
        return self::getBool('use_iptc', false);
    }
    public static function useProxy(): bool
    {
        return self::getBool('use_proxy', false);
    }
    public static function userCanDeleteComment(): bool
    {
        return self::getBool('user_can_delete_comment', false);
    }
    public static function userCanEditComment(): bool
    {
        return self::getBool('user_can_edit_comment', false);
    }
    public static function usersTable(): ?string
    {
        $v = self::src()['users_table'] ?? null;
        return $v !== null ? (is_scalar($v) ? (string) $v : null) : null;
    }
    public static function webmasterId(): int
    {
        return self::getInt('webmaster_id', 1);
    }
    public static function weekStartsOn(): string
    {
        return self::getString('week_starts_on', 'monday');
    }
    public static function wsMaxImagesPerPage(): int
    {
        return self::getInt('ws_max_images_per_page', 500);
    }
    public static function wsMaxUsersPerPage(): int
    {
        return self::getInt('ws_max_users_per_page', 1000);
    }
    // <<<CONFIG-ACCESSORS-END>>>

    // ---- Custom accessors (hand-written) --------------------------------

    /** @return list<string> */
    public static function pictureExtensions(): array
    {
        $v = self::src()['picture_ext'] ?? ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        if (!is_array($v)) {
            return ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        }
        return array_values(array_map(static fn (mixed $x): string => is_scalar($x) || $x === null ? (string) $x : '', $v));
    }
    /** @return list<string> */
    public static function fileExtensions(): array
    {
        $default = array_merge(self::pictureExtensions(), ['tiff', 'tif', 'mpg', 'zip', 'avi', 'mp3', 'ogg', 'pdf', 'svg', 'heic']);
        $v = self::src()['file_ext'] ?? $default;
        if (!is_array($v)) {
            return $default;
        }
        return array_values(array_map(static fn (mixed $x): string => is_scalar($x) || $x === null ? (string) $x : '', $v));
    }
    /** @return list<string> */
    public static function formatExtensions(): array
    {
        $v = self::src()['format_ext'] ?? ['cr2', 'tif', 'tiff', 'nef', 'dng', 'ai', 'psd'];
        if (!is_array($v)) {
            return ['cr2', 'tif', 'tiff', 'nef', 'dng', 'ai', 'psd'];
        }
        return array_values(array_map(static fn (mixed $x): string => is_scalar($x) || $x === null ? (string) $x : '', $v));
    }
    /**
     * @return array{RSS: array{max_dates: int, max_elements: int, max_cats: int}, NBM: array{max_dates: int, max_elements: int, max_cats: int}}
     */
    public static function recentPostDates(): array
    {
        $default = ['RSS' => ['max_dates' => 5, 'max_elements' => 6, 'max_cats' => 6], 'NBM' => ['max_dates' => 7, 'max_elements' => 3, 'max_cats' => 9]];
        $v = self::src()['recent_post_dates'] ?? $default;
        if (!is_array($v)) {
            return $default;
        }
        foreach (['RSS', 'NBM'] as $key) {
            if (!isset($v[$key]) || !is_array($v[$key])) {
                $v[$key] = $default[$key];
            } else {
                $v[$key] = ['max_dates' => isset($v[$key]['max_dates']) && is_int($v[$key]['max_dates']) ? $v[$key]['max_dates'] : $default[$key]['max_dates'], 'max_elements' => isset($v[$key]['max_elements']) && is_int($v[$key]['max_elements']) ? $v[$key]['max_elements'] : $default[$key]['max_elements'], 'max_cats' => isset($v[$key]['max_cats']) && is_int($v[$key]['max_cats']) ? $v[$key]['max_cats'] : $default[$key]['max_cats']];
            }
        }
        /** @var array{RSS: array{max_dates: int, max_elements: int, max_cats: int}, NBM: array{max_dates: int, max_elements: int, max_cats: int}} $v */
        return $v;
    }
    public static function nbmMaxTreatmentTimeoutPercent(): float
    {
        $v = self::src()['nbm_max_treatment_timeout_percent'] ?? 0.8;
        return is_scalar($v) ? (float) $v : 0.8;
    }
    /** @return array<string,mixed> */
    public static function links(): array
    {
        $v = self::src()['links'] ?? [];
        return is_array($v) ? $v : [];
    }
    /** @return array<string,string> */
    public static function randomIndexRedirect(): array
    {
        $v = self::src()['random_index_redirect'] ?? [];
        if (!is_array($v)) {
            return [];
        }
        /** @var array<string,string> */
        return array_map(strval(...), array_filter($v, is_scalar(...)));
    }
    /** @return list<string> */
    public static function headerNotes(): array
    {
        $v = self::src()['header_notes'] ?? [];
        if (!is_array($v)) {
            return [];
        }
        return array_values(array_map(static fn (mixed $x): string => is_scalar($x) || $x === null ? (string) $x : '', $v));
    }
    // ---- Permission / access cluster ------------------------------------
    /** @return non-empty-list<int> */
    public static function availablePermissionLevels(): array
    {
        $v = self::src()['available_permission_levels'] ?? [0, 1, 2, 4, 8];
        return is_array($v) && count($v) > 0 ? array_values(array_map(static fn (mixed $x): int => is_scalar($x) ? (int) $x : 0, $v)) : [0, 1, 2, 4, 8];
    }
    /** @return array<string,string> */
    public static function userFields(): array
    {
        $default = ['id' => 'id', 'username' => 'username', 'password' => 'password', 'email' => 'mail_address'];
        $v = self::src()['user_fields'] ?? $default;
        if (!is_array($v)) {
            return $default;
        }
        $result = [];
        foreach ($v as $k => $val) {
            $result[(string) $k] = is_scalar($val) ? (string) $val : '';
        }
        return $result;
    }
    /** @return array<string,mixed> */
    public static function filterPages(): array
    {
        $default = [
            'default'        => ['used' => true, 'cancel' => false, 'add_notes' => false],
            'index'          => ['add_notes' => true],
            'tags'           => ['add_notes' => true],
            'search'         => ['add_notes' => true],
            'comments'       => ['add_notes' => true],
            'admin'          => ['used' => false],
            'feed'           => ['used' => false],
            'notification'   => ['used' => false],
            'nbm'            => ['used' => false],
            'popuphelp'      => ['used' => false],
            'profile'        => ['used' => false],
            'ws'             => ['used' => false],
            'identification' => ['cancel' => true],
            'install'        => ['cancel' => true],
            'password'       => ['cancel' => true],
            'register'       => ['cancel' => true],
        ];
        $v = self::src()['filter_pages'] ?? $default;
        return is_array($v) ? $v : $default;
    }
    /** @return array<string,string> */
    public static function showIptcMapping(): array
    {
        $default = ['iptc_keywords' => '2#025', 'iptc_caption_writer' => '2#122', 'iptc_byline_title' => '2#085', 'iptc_caption' => '2#120'];
        $v = self::src()['show_iptc_mapping'] ?? $default;
        if (!is_array($v)) {
            return $default;
        }
        $result = [];
        foreach ($v as $k => $val) {
            $result[(string) $k] = is_scalar($val) ? (string) $val : '';
        }
        return $result;
    }
    /** @return array<string,string> */
    public static function useIptcMapping(): array
    {
        $default = ['keywords' => '2#025', 'date_creation' => '2#055', 'author' => '2#122', 'name' => '2#005', 'comment' => '2#120'];
        $v = self::src()['use_iptc_mapping'] ?? $default;
        if (!is_array($v)) {
            return $default;
        }
        $result = [];
        foreach ($v as $k => $val) {
            $result[(string) $k] = is_scalar($val) ? (string) $val : '';
        }
        return $result;
    }
    /** @return list<string> */
    public static function showExifFields(): array
    {
        $v = self::src()['show_exif_fields'] ?? ['Make', 'Model', 'DateTimeOriginal', 'COMPUTED;ApertureFNumber'];
        if (!is_array($v)) {
            return ['Make', 'Model', 'DateTimeOriginal', 'COMPUTED;ApertureFNumber'];
        }
        return array_values(array_map(static fn (mixed $x): string => is_scalar($x) || $x === null ? (string) $x : '', $v));
    }
    /** @return array<string,string> */
    public static function useExifMapping(): array
    {
        $default = ['date_creation' => 'DateTimeOriginal'];
        $v = self::src()['use_exif_mapping'] ?? $default;
        if (!is_array($v)) {
            return $default;
        }
        $result = [];
        foreach ($v as $k => $val) {
            $result[(string) $k] = is_scalar($val) ? (string) $val : '';
        }
        return $result;
    }
    /** @return list<string> */
    public static function syncExcludeFolders(): array
    {
        $v = self::src()['sync_exclude_folders'] ?? [];
        if (!is_array($v)) {
            return [];
        }
        return array_values(array_map(static fn (mixed $x): string => is_scalar($x) || $x === null ? (string) $x : '', $v));
    }
    public static function extentsForTemplates(): ?string
    {
        $v = self::src()['extents_for_templates'] ?? null;
        if ($v === null) {
            return null;
        }
        return is_string($v) ? $v : serialize($v);
    }
    /** @return list<int> */
    public static function rateItems(): array
    {
        $v = self::src()['rate_items'] ?? [0, 1, 2, 3, 4, 5];
        return is_array($v) ? array_values(array_map(static fn (mixed $x): int => is_scalar($x) ? (int) $x : 0, $v)) : [0, 1, 2, 3, 4, 5];
    }
    /** @return array<mixed> */
    public static function apiKeyDuration(): array
    {
        $v = self::src()['api_key_duration'] ?? ['30', '90', '180', '365', 'custom'];
        return is_array($v) ? $v : ['30', '90', '180', '365', 'custom'];
    }
    /** @return list<string> */
    public static function apiKeyForbiddenMethods(): array
    {
        $default = ['pwg.users.generatePasswordLink', 'pwg.users.getAuthKey', 'pwg.users.setMainUser', 'pwg.users.setInfo', 'pwg.plugins.performAction', 'pwg.themes.performAction', 'pwg.extensions.ignoreUpdate', 'pwg.extensions.update'];
        $v = self::src()['api_key_forbidden_methods'] ?? $default;
        if (!is_array($v)) {
            return $default;
        }
        return array_values(array_map(static fn (mixed $x): string => is_scalar($x) || $x === null ? (string) $x : '', $v));
    }
    /** @return array<string,mixed> */
    public static function defaultFiltersViews(): array
    {
        $default = [
            'words'             => ['access' => 'everybody', 'default' => true],
            'tags'              => ['access' => 'everybody', 'default' => false],
            'post_date'         => ['access' => 'everybody', 'default' => false],
            'creation_date'     => ['access' => 'everybody', 'default' => true],
            'album'             => ['access' => 'everybody', 'default' => true],
            'author'            => ['access' => 'everybody', 'default' => false],
            'added_by'          => ['access' => 'everybody', 'default' => false],
            'file_type'         => ['access' => 'everybody', 'default' => false],
            'ratio'             => ['access' => 'everybody', 'default' => false],
            'rating'            => ['access' => 'everybody', 'default' => false],
            'file_size'         => ['access' => 'everybody', 'default' => false],
            'height'            => ['access' => 'everybody', 'default' => false],
            'width'             => ['access' => 'everybody', 'default' => false],
            'expert'            => ['access' => 'everybody', 'default' => false],
            'last_filters_conf' => true,
        ];
        $v = self::src()['default_filters_views'] ?? $default;
        return is_array($v) ? $v : $default;
    }
    /** @return array<string, int> */
    public static function flipPictureExt(): array
    {
        return array_flip(self::pictureExtensions());
    }
    /** @return array<string, int> */
    public static function flipFileExt(): array
    {
        return array_flip(self::fileExtensions());
    }
    // ---- Updates cluster (additions) ------------------------------------
    /** @return list<string> */
    public static function updatesIgnored(): array
    {
        $v = self::src()['updates_ignored'] ?? [];
        if (!is_array($v)) {
            return [];
        }
        return array_values(array_map(static fn (mixed $x): string => is_scalar($x) || $x === null ? (string) $x : '', $v));
    }

    // ---- Bulk / test helpers -------------------------------------------

    // ---- Bulk access / Wave C support -----------------------------------
    /** @return array<string,mixed> */
    public static function all(): array
    {
        return self::src();
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
    }
    public static function reset(): void
    {
        self::$data = [];
        self::$singleton = null;
    }
}
