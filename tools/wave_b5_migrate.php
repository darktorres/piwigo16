<?php

/**
 * Wave B.5 migration: replace bare $conf[key] reads/writes with Config:: calls.
 *
 * Usage:
 *   php tools/wave_b5_migrate.php [--dry-run] [file|dir ...]
 *
 * Without arguments, processes admin/, include/, src/.
 * With --dry-run, prints diffs without writing.
 */

declare(strict_types=1);

// ---- typed getter map: config key => method name (no parens) -------------
// Only keys that have a typed getter in Config.php. All others use Config::get().
const TYPED_GETTERS = [
    'data_location'                    => 'dataLocation',
    'upload_dir'                       => 'uploadDir',
    'themes_dir'                       => 'themesDir',
    'log_dir'                          => 'logDir',
    'ffmpeg_dir'                       => 'ffmpegDir',
    'ext_imagick_dir'                  => 'extImagickDir',
    'no_photo_yet_url'                 => 'noPhotoYetUrl',
    'gallery_url'                      => 'galleryUrl',
    'alternative_pem_url'              => 'alternativePemUrl',
    'secret_key'                       => 'secretKey',
    'auth_key_duration'                => 'authKeyDuration',
    'apache_authentication'            => 'apacheAuthentication',
    'gallery_title'                    => 'galleryTitle',
    'guest_id'                         => 'guestId',
    'default_user_id'                  => 'defaultUserId',
    'webmaster_id'                     => 'webmasterId',
    'allow_html_descriptions'          => 'allowHtmlDescriptions',
    'activate_comments'                => 'activateComments',
    'enable_formats'                   => 'isFormatsEnabled',
    'picture_ext'                      => 'pictureExtensions',
    'file_ext'                         => 'fileExtensions',
    'format_ext'                       => 'formatExtensions',
    'dblayer'                          => 'dbLayer',
    'log_level'                        => 'logLevel',
    'log_archive_days'                 => 'logArchiveDays',
    'password_hash'                    => 'passwordHash',
    'password_verify'                  => 'passwordVerify',
    'smtp_host'                        => 'smtpHost',
    'smtp_user'                        => 'smtpUser',
    'smtp_password'                    => 'smtpPassword',
    'smtp_secure'                      => 'smtpSecure',
    'mail_sender_name'                 => 'mailSenderName',
    'mail_sender_email'                => 'mailSenderEmail',
    'mail_allow_html'                  => 'mailAllowHtml',
    'send_bcc_mail_webmaster'          => 'sendBccMailWebmaster',
    'show_php_errors'                  => 'showPhpErrors',
    'show_php_errors_on_frontend'      => 'showPhpErrorsOnFrontend',
    'show_queries'                     => 'showQueries',
    'debug_l10n'                       => 'debugL10n',
    'debug_template'                   => 'debugTemplate',
    'debug_mail'                       => 'debugMail',
    'die_on_sql_error'                 => 'dieOnSqlError',
    'template_force_compile'           => 'templateForceCompile',
    'session_use_cookies'              => 'sessionUseCookies',
    'session_use_only_cookies'         => 'sessionUseOnlyCookies',
    'session_use_trans_sid'            => 'sessionUseTransSid',
    'session_name'                     => 'sessionName',
    'session_save_handler'             => 'sessionSaveHandler',
    'session_length'                   => 'sessionLength',
    'authorize_remembering'            => 'authorizeRemembering',
    'remember_me_name'                 => 'rememberMeName',
    'remember_me_length'               => 'rememberMeLength',
    'session_use_ip_address'           => 'sessionUseIpAddress',
    'session_gc_probability'           => 'sessionGcProbability',
    'derivative_default_size'          => 'derivativeDefaultSize',
    'derivatives_strip_metadata_threshold' => 'derivativesStripMetadataThreshold',
    'chmod_value'                      => 'chmodValue',
    'tiff_representative_ext'          => 'tiffRepresentativeExt',
    'full_tag_cloud_items_number'      => 'fullTagCloudItemsNumber',
    'menubar_tag_cloud_items_number'   => 'menubarTagCloudItemsNumber',
    'menubar_tag_cloud_content'        => 'menubarTagCloudContent',
    'content_tag_cloud_items_number'   => 'contentTagCloudItemsNumber',
    'tags_levels'                      => 'tagsLevels',
    'tags_default_display_mode'        => 'tagsDefaultDisplayMode',
    'tag_letters_column_number'        => 'tagLettersColumnNumber',
    'upload_form_automatic_rotation'   => 'uploadFormAutomaticRotation',
    'upload_form_all_types'            => 'uploadFormAllTypes',
    'upload_form_chunk_size'           => 'uploadFormChunkSize',
    'upload_form_max_file_size'        => 'uploadFormMaxFileSize',
    'enable_synchronization'           => 'enableSynchronization',
    'nbm_default_value_user_enabled'   => 'nbmDefaultValueUserEnabled',
    'nbm_list_all_enabled_users_to_send' => 'nbmListAllEnabledUsersToSend',
    'nbm_max_treatment_timeout_percent' => 'nbmMaxTreatmentTimeoutPercent',
    'nbm_treatment_timeout_default'    => 'nbmTreatmentTimeoutDefault',
    'top_number'                       => 'topNumber',
    'anti-flood_time'                  => 'antiFloodTime',
    'comment_spam_reject'              => 'commentSpamReject',
    'comment_spam_max_links'           => 'commentSpamMaxLinks',
    'comments_page_nb_comments'        => 'commentsPageNbComments',
    'show_thumbnail_caption'           => 'showThumbnailCaption',
    'allow_random_representative'      => 'allowRandomRepresentative',
    'representative_cache_on_level'    => 'representativeCacheOnLevel',
    'representative_cache_on_subcats'  => 'representativeCacheOnSubcats',
    'show_version'                     => 'showVersion',
    'meta_ref'                         => 'metaRef',
    'level_separator'                  => 'levelSeparator',
    'paginate_pages_around'            => 'paginatePagesAround',
    'page_banner'                      => 'pageBanner',
    'light_album_manager_threshold'    => 'lightAlbumManagerThreshold',
    'light_slideshow'                  => 'lightSlideshow',
    'index_new_icon'                   => 'indexNewIcon',
    'menubar_filter_icon'              => 'menubarFilterIcon',
    'show_gt'                          => 'showGt',
    'links'                            => 'links',
    'random_index_redirect'            => 'randomIndexRedirect',
    'header_notes'                     => 'headerNotes',
    'available_permission_levels'      => 'availablePermissionLevels',
    'guest_access'                     => 'guestAccess',
    'allow_user_registration'          => 'allowUserRegistration',
    'allow_user_customization'         => 'allowUserCustomization',
    'obligatory_user_mail_address'     => 'obligatoryUserMailAddress',
    'insensitive_case_logon'           => 'insensitiveCaseLogon',
    'double_password_type_in_admin'    => 'doublePasswordTypeInAdmin',
    'browser_language'                 => 'browserLanguage',
    'external_authentification'        => 'externalAuthentification',
    'password_reset_duration'          => 'passwordResetDuration',
    'password_activation_duration'     => 'passwordActivationDuration',
    'password_reset_code_duration'     => 'passwordResetCodeDuration',
    'user_fields'                      => 'userFields',
    'users_table'                      => 'usersTable',
    'calendar_datefield'               => 'calendarDatefield',
    'calendar_show_any'                => 'calendarShowAny',
    'calendar_show_empty'              => 'calendarShowEmpty',
    'newcat_default_commentable'       => 'newcatDefaultCommentable',
    'newcat_default_visible'           => 'newcatDefaultVisible',
    'newcat_default_status'            => 'newcatDefaultStatus',
    'newcat_default_position'          => 'newcatDefaultPosition',
    'inheritance_by_default'           => 'inheritanceByDefault',
    'album_description_on_all_pages'   => 'albumDescriptionOnAllPages',
    'album_move_delay_before_auto_opening' => 'albumMoveDelayBeforeAutoOpening',
    'linked_album_search_limit'        => 'linkedAlbumSearchLimit',
    'related_albums_display_limit'     => 'relatedAlbumsDisplayLimit',
    'related_albums_maximum_items_to_compute' => 'relatedAlbumsMaximumItemsToCompute',
    'question_mark_in_urls'            => 'questionMarkInUrls',
    'php_extension_in_urls'            => 'phpExtensionInUrls',
    'category_url_style'               => 'categoryUrlStyle',
    'picture_url_style'                => 'pictureUrlStyle',
    'tag_url_style'                    => 'tagUrlStyle',
    'url_port'                         => 'urlPort',
    'original_url_protection'          => 'originalUrlProtection',
    'use_proxy'                        => 'useProxy',
    'proxy_server'                     => 'proxyServer',
    'proxy_auth'                       => 'proxyAuth',
    'admin_theme'                      => 'adminTheme',
    'enable_plugins'                   => 'enablePlugins',
    'allow_web_services'               => 'allowWebServices',
    'ws_max_images_per_page'           => 'wsMaxImagesPerPage',
    'ws_max_users_per_page'            => 'wsMaxUsersPerPage',
    'show_newsletter_subscription'     => 'showNewsletterSubscription',
    'show_piwigo_latest_news'          => 'showPiwigoLatestNews',
    'dashboard_check_for_updates'      => 'dashboardCheckForUpdates',
    'dashboard_activity_nb_weeks'      => 'dashboardActivityNbWeeks',
    'activity_display_connections'     => 'activityDisplayConnections',
    'show_template_in_side_menu'       => 'showTemplateInSideMenu',
    'add_cache_to_storage_chart'       => 'addCacheToStorageChart',
    'enable_core_update'               => 'enableCoreUpdate',
    'enable_extensions_install'        => 'enableExtensionsInstall',
    'check_upgrade_feed'               => 'checkUpgradeFeed',
    'stat_compare_year_displayed'      => 'statCompareYearDisplayed',
    'filter_pages'                     => 'filterPages',
    'update_notify_check_period'       => 'updateNotifyCheckPeriod',
    'update_notify_reminder_period'    => 'updateNotifyReminderPeriod',
    'send_piwigo_infos'                => 'sendPiwigoInfos',
    'pem_plugins_category'             => 'pemPluginsCategory',
    'pem_themes_category'              => 'pemThemesCategory',
    'pem_languages_category'           => 'pemLanguagesCategory',
    'nb_logs_page'                     => 'nbLogsPage',
    'history_autopurge_every'          => 'historyAutopurgeEvery',
    'history_autopurge_keep_lines'     => 'historyAutopurgeKeepLines',
    'history_autopurge_blocksize'      => 'historyAutopurgeBlocksize',
    'fs_quick_check_period'            => 'fsQuickCheckPeriod',
    'show_iptc'                        => 'showIptc',
    'show_iptc_mapping'                => 'showIptcMapping',
    'use_iptc'                         => 'useIptc',
    'use_iptc_mapping'                 => 'useIptcMapping',
    'show_exif'                        => 'showExif',
    'show_exif_fields'                 => 'showExifFields',
    'use_exif'                         => 'useExif',
    'use_exif_mapping'                 => 'useExifMapping',
    'allow_html_in_metadata'           => 'allowHtmlInMetadata',
    'metadata_keyword_separator_regex' => 'metadataKeywordSeparatorRegex',
    'pdf_viewer_filesize_threshold'    => 'pdfViewerFilesizeThreshold',
    'sync_chars_regex'                 => 'syncCharsRegex',
    'sync_exclude_folders'             => 'syncExcludeFolders',
    'checksum_compute_blocksize'       => 'checksumComputeBlocksize',
    'upload_detect_duplicate'          => 'uploadDetectDuplicate',
    'never_delete_originals'           => 'neverDeleteOriginals',
    'graphics_library'                 => 'graphicsLibrary',
    'original_resize'                  => 'originalResize',
    'original_resize_maxwidth'         => 'originalResizeMaxwidth',
    'original_resize_maxheight'        => 'originalResizeMaxheight',
    'original_resize_quality'          => 'originalResizeQuality',
    'animated_webp_compression_quality' => 'animatedWebpCompressionQuality',
    'max_requests'                     => 'maxRequests',
    'lounge_active'                    => 'loungeActive',
    'lounge_activate_threshold'        => 'loungeActivateThreshold',
    'lounge_max_duration'              => 'loungeMaxDuration',
    'nbm_send_html_mail'               => 'nbmSendHtmlMail',
    'nbm_send_mail_as'                 => 'nbmSendMailAs',
    'nbm_send_detailed_content'        => 'nbmSendDetailedContent',
    'nbm_complementary_mail_content'   => 'nbmComplementaryMailContent',
    'nbm_send_recent_post_dates'       => 'nbmSendRecentPostDates',
    'template_compile_check'           => 'templateCompileCheck',
    'template_combine_files'           => 'templateCombineFiles',
    'compiled_template_cache_language' => 'compiledTemplateCacheLanguage',
    'extents_for_templates'            => 'extentsForTemplates',
    'slideshow_period'                 => 'slideshowPeriod',
    'slideshow_period_min'             => 'slideshowPeriodMin',
    'slideshow_period_max'             => 'slideshowPeriodMax',
    'slideshow_period_step'            => 'slideshowPeriodStep',
    'slideshow_repeat'                 => 'slideshowRepeat',
    'order_by'                         => 'orderBy',
    'order_by_custom'                  => 'orderByCustom',
    'order_by_inside_category'         => 'orderByInsideCategory',
    'order_by_inside_category_custom'  => 'orderByInsideCategoryCustom',
    'mobile_theme'                     => 'mobilTheme',
    'batch_manager_images_per_page_global' => 'batchManagerImagesPerPageGlobal',
    'batch_manager_images_per_page_unit'   => 'batchManagerImagesPerPageUnit',
    'rate_items'                       => 'rateItems',
    'default_redirect_method'          => 'defaultRedirectMethod',
    'uniqueness_mode'                  => 'uniquenessMode',
    'quick_search_include_sub_albums'  => 'quickSearchIncludeSubAlbums',
    'rss_feed_author'                  => 'rssReedAuthor',
    'api_key_duration'                 => 'apiKeyDuration',
    'api_key_forbidden_methods'        => 'apiKeyForbiddenMethods',
    'default_filters_views'            => 'defaultFiltersViews',
];

// ---- exempt path fragments -----------------------------------------------
const EXEMPT = [
    DIRECTORY_SEPARATOR . 'install' . DIRECTORY_SEPARATOR . 'db' . DIRECTORY_SEPARATOR,
    '/install/db/',
    '\\install\\db\\',
    DIRECTORY_SEPARATOR . 'local' . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR,
    '/local/config/',
    '\\local\\config\\',
    DIRECTORY_SEPARATOR . 'language' . DIRECTORY_SEPARATOR,
    '/language/',
    '\\language\\',
    'config_default.inc.php',
    'Config.php',
    'wave_b5_migrate.php',
    'vendor' . DIRECTORY_SEPARATOR,
    '/vendor/',
];

// ---- helpers -------------------------------------------------------------

function is_exempt(string $path): bool
{
    foreach (EXEMPT as $frag) {
        if (str_contains($path, $frag)) {
            return true;
        }
    }
    return false;
}

function conf_call(string $key): string
{
    $getter = TYPED_GETTERS[$key] ?? null;
    if ($getter !== null) {
        return "\\Piwigo\\Core\\Config::{$getter}()";
    }
    return "\\Piwigo\\Core\\Config::get('{$key}')";
}

/**
 * Migrate one line. Returns [new_line, changed_bool].
 * Does NOT handle multi-line write patterns.
 */
function migrate_line(string $line): array
{
    $original = $line;

    // ---- (A) isset($conf['key']) → null !== \Piwigo\Config\Config::get / has()
    $line = preg_replace_callback(
        "/isset\(\s*\\\$conf\['([^']+)'\]\s*\)/",
        fn ($m) => "\\Piwigo\\Core\\Config::has('" . $m[1] . "')",
        $line
    );

    // ---- (B) array_key_exists('key', $conf) → Config::has('key')
    $line = preg_replace_callback(
        "/array_key_exists\(\s*'([^']+)'\s*,\s*\\\$conf\s*\)/",
        fn ($m) => "\\Piwigo\\Core\\Config::has('" . $m[1] . "')",
        $line
    );

    // ---- (C) single-line write: $conf['key'] = expr;
    // Must NOT match ==, !=, ===, !==, <=, >=, =~
    // Only standalone assignment: $conf['key'] =
    $line = preg_replace_callback(
        "/\\\$conf\['([^']+)'\]\s*=(?![=>])\s*(.+?);(\s*(?:\/\/[^\n]*)?)$/",
        function ($m) {
            $key = $m[1];
            $expr = rtrim($m[2]);
            $tail = $m[3];
            return "\\Piwigo\\Core\\Config::override('{$key}', {$expr});{$tail}";
        },
        $line
    );

    // ---- (D) dynamic read: $conf[$var] (not followed by '[' which would be 2D)
    // Guard: ensure $var is a simple variable, not an array read
    $line = preg_replace_callback(
        "/\\\$conf\[(\\\$[a-zA-Z_][a-zA-Z0-9_]*)\](?!\[)/",
        fn ($m) => "\\Piwigo\\Core\\Config::get({$m[1]})",
        $line
    );

    // ---- (E) static read: $conf['key'] (handles 2D via chaining automatically)
    $line = preg_replace_callback(
        "/\\\$conf\['([^']+)'\]/",
        fn ($m) => conf_call($m[1]),
        $line
    );

    return [$line, $line !== $original];
}

/**
 * Returns true if the file still has bare $conf[ references after migration.
 */
function has_remaining_conf(string $content): bool
{
    return (bool) preg_match('/\$conf\[/', $content);
}

/**
 * Remove 'global $conf[,;]' or 'global $conf, $x;' fragments from a line.
 * Only removes $conf from global declarations when conf is fully gone.
 */
function remove_global_conf(string $content): string
{
    // Remove entire line if "global $conf;" (sole variable)
    $content = preg_replace('/^[ \t]*global\s+\$conf\s*;\s*\n/m', '', $content);
    // Remove $conf from multi-variable global: "global $conf, $page;" → "global $page;"
    $content = preg_replace('/\bglobal\s+\$conf\s*,\s*/', 'global ', $content);
    // Remove $conf as second/later variable: "global $page, $conf;" → "global $page;"
    $content = preg_replace('/,\s*\$conf\b/', '', $content);
    return $content;
}

function migrate_file(string $path, bool $dry_run): bool
{
    if (!is_file($path) || pathinfo($path, PATHINFO_EXTENSION) !== 'php') {
        return false;
    }
    if (is_exempt($path)) {
        return false;
    }

    $original = file_get_contents($path);
    $lines = explode("\n", $original);
    $changed_any = false;
    $new_lines = [];

    foreach ($lines as $line) {
        // skip lines that are already fully Config:: (avoid double-processing)
        if (!str_contains($line, '$conf[')) {
            $new_lines[] = $line;
            continue;
        }
        [$new_line, $changed] = migrate_line($line);
        $new_lines[] = $new_line;
        if ($changed) {
            $changed_any = true;
        }
    }

    if (!$changed_any) {
        return false;
    }

    $new_content = implode("\n", $new_lines);

    // Remove 'global $conf;' only if no bare $conf[ remains
    if (!has_remaining_conf($new_content)) {
        $before_remove = $new_content;
        $new_content = remove_global_conf($new_content);
        if ($new_content !== $before_remove) {
            $changed_any = true;
        }
    }

    if ($dry_run) {
        echo "--- {$path}\n";
        $old_lines = explode("\n", $original);
        $new_lines_arr = explode("\n", $new_content);
        for ($i = 0; $i < max(count($old_lines), count($new_lines_arr)); $i++) {
            $o = $old_lines[$i] ?? '';
            $n = $new_lines_arr[$i] ?? '';
            if ($o !== $n) {
                echo "  -{$o}\n";
                echo "  +{$n}\n";
            }
        }
    } else {
        file_put_contents($path, $new_content);
    }

    return true;
}

function collect_php_files(string $path): array
{
    if (is_file($path)) {
        return [$path];
    }
    $files = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }
    sort($files);
    return $files;
}

// ---- main ----------------------------------------------------------------

$dry_run = false;
$targets = [];

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--dry-run') {
        $dry_run = true;
        continue;
    }
    $targets[] = $arg;
}

if (empty($targets)) {
    $base = dirname(__DIR__);
    $targets = [
        $base . '/admin',
        $base . '/include',
        $base . '/src',
    ];
}

$changed = 0;
$skipped = 0;

foreach ($targets as $target) {
    foreach (collect_php_files($target) as $file) {
        if (is_exempt($file)) {
            $skipped++;
            continue;
        }
        if (migrate_file($file, $dry_run)) {
            echo ($dry_run ? '[dry] ' : '[ok]  ') . $file . "\n";
            $changed++;
        }
    }
}

echo "\nDone. Changed: {$changed}, skipped (exempt): {$skipped}\n";
if ($dry_run) {
    echo "(dry-run: no files written)\n";
}
