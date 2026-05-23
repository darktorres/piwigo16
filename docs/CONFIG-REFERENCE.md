# Piwigo configuration reference

> **Generated** by `tools/build-config-reference.php` from `Piwigo\Config\Config::SCHEMA`.
> Do not edit by hand — re-run the generator after editing SCHEMA.

Total keys: **277**.

Access pattern:

```php
use Piwigo\Config\Config;

$dir = Config::uploadDir();           // typed accessor — preferred
$mb  = Config::raw('blk_' . $id);   // dynamic key escape hatch
```

Static keys MUST go through a typed accessor. The private `getString` /
`getInt` / `getBool` helpers throw `UnknownConfigKeyException` if called
with a key not in the table below.

## Schema

| Key                                       | Type     | Default                     | Accessor                                        | Notes                     |
| ----------------------------------------- | -------- | --------------------------- | ----------------------------------------------- | ------------------------- |
| `activate_comments`                       | `bool`   | `true`                      | `Config::activateComments()`                    | —                         |
| `activity_display_connections`            | `string` | `all`                       | `Config::activityDisplayConnections()`          | —                         |
| `add_cache_to_storage_chart`              | `bool`   | `true`                      | `Config::addCacheToStorageChart()`              | —                         |
| `admin_theme`                             | `string` | `dark`                      | `Config::adminTheme()`                          | —                         |
| `album_description_on_all_pages`          | `bool`   | `false`                     | `Config::albumDescriptionOnAllPages()`          | —                         |
| `album_move_delay_before_auto_opening`    | `int`    | `3000`                      | `Config::albumMoveDelayBeforeAutoOpening()`     | —                         |
| `allow_html_descriptions`                 | `bool`   | `true`                      | `Config::allowHtmlDescriptions()`               | —                         |
| `allow_html_in_metadata`                  | `bool`   | `false`                     | `Config::allowHtmlInMetadata()`                 | —                         |
| `allow_random_representative`             | `bool`   | `false`                     | `Config::allowRandomRepresentative()`           | —                         |
| `allow_user_customization`                | `bool`   | `true`                      | `Config::allowUserCustomization()`              | —                         |
| `allow_user_registration`                 | `bool`   | `true`                      | `Config::allowUserRegistration()`               | —                         |
| `allow_web_services`                      | `bool`   | `true`                      | `Config::allowWebServices()`                    | —                         |
| `alternative_pem_url`                     | `string` | `(empty)`                   | `Config::alternativePemUrl()`                   | —                         |
| `animated_webp_compression_quality`       | `int`    | `70`                        | `Config::animatedWebpCompressionQuality()`      | —                         |
| `anti-flood_time`                         | `int`    | `60`                        | `Config::antiFloodTime()`                       | —                         |
| `apache_authentication`                   | `bool`   | `false`                     | `Config::apacheAuthentication()`                | —                         |
| `api_key_duration`                        | `array`  | `null`                      | `Config::apiKeyDuration()`                      | custom accessor           |
| `api_key_forbidden_methods`               | `array`  | `null`                      | `Config::apiKeyForbiddenMethods()`              | custom accessor           |
| `auth_key_duration`                       | `int`    | `259200`                    | `Config::authKeyDuration()`                     | —                         |
| `authorize_remembering`                   | `bool`   | `true`                      | `Config::authorizeRemembering()`                | —                         |
| `auto_migrate`                            | `bool`   | `true`                      | `Config::autoMigrate()`                         | —                         |
| `available_permission_levels`             | `array`  | `null`                      | `Config::availablePermissionLevels()`           | custom accessor           |
| `batch_manager_images_per_page_global`    | `int`    | `20`                        | `Config::batchManagerImagesPerPageGlobal()`     | —                         |
| `batch_manager_images_per_page_unit`      | `int`    | `5`                         | `Config::batchManagerImagesPerPageUnit()`       | —                         |
| `browser_language`                        | `bool`   | `true`                      | `Config::browserLanguage()`                     | —                         |
| `cache.backend`                           | `string` | `file`                      | `Config::cacheBackend()`                        | —                         |
| `cache.default_ttl`                       | `int`    | `86400`                     | `Config::cacheDefaultTtl()`                     | —                         |
| `cache.namespace`                         | `string` | `(empty)`                   | `Config::cacheNamespace()`                      | —                         |
| `cache.redis_url`                         | `string` | `redis://localhost:6379`    | `Config::cacheRedisUrl()`                       | —                         |
| `calendar_datefield`                      | `string` | `date_creation`             | `Config::calendarDatefield()`                   | —                         |
| `calendar_show_any`                       | `bool`   | `true`                      | `Config::calendarShowAny()`                     | —                         |
| `calendar_show_empty`                     | `bool`   | `true`                      | `Config::calendarShowEmpty()`                   | —                         |
| `category_url_style`                      | `string` | `id`                        | `Config::categoryUrlStyle()`                    | —                         |
| `check_upgrade_feed`                      | `bool`   | `false`                     | `Config::checkUpgradeFeed()`                    | —                         |
| `checksum_compute_blocksize`              | `int`    | `50`                        | `Config::checksumComputeBlocksize()`            | —                         |
| `chmod_value`                             | `int`    | `493`                       | `Config::chmodValue()`                          | —                         |
| `comment_spam_max_links`                  | `int`    | `3`                         | `Config::commentSpamMaxLinks()`                 | —                         |
| `comment_spam_reject`                     | `bool`   | `true`                      | `Config::commentSpamReject()`                   | —                         |
| `comments_author_mandatory`               | `bool`   | `false`                     | `Config::commentsAuthorMandatory()`             | —                         |
| `comments_email_mandatory`                | `bool`   | `false`                     | `Config::commentsEmailMandatory()`              | —                         |
| `comments_enable_website`                 | `bool`   | `true`                      | `Config::commentsEnableWebsite()`               | —                         |
| `comments_forall`                         | `bool`   | `false`                     | `Config::commentsForall()`                      | —                         |
| `comments_order`                          | `string` | `ASC`                       | `Config::commentsOrder()`                       | —                         |
| `comments_page_nb_comments`               | `int`    | `10`                        | `Config::commentsPageNbComments()`              | —                         |
| `comments_validation`                     | `bool`   | `false`                     | `Config::commentsValidation()`                  | —                         |
| `compiled_template_cache_language`        | `bool`   | `false`                     | `Config::compiledTemplateCacheLanguage()`       | —                         |
| `content_tag_cloud_items_number`          | `int`    | `12`                        | `Config::contentTagCloudItemsNumber()`          | —                         |
| `dashboard_activity_nb_weeks`             | `int`    | `4`                         | `Config::dashboardActivityNbWeeks()`            | —                         |
| `dashboard_check_for_updates`             | `bool`   | `true`                      | `Config::dashboardCheckForUpdates()`            | —                         |
| `data_location`                           | `string` | `_data/`                    | `Config::dataLocation()`                        | —                         |
| `db_base`                                 | `string` | `(empty)`                   | `Config::dbName()`                              | —                         |
| `db_host`                                 | `string` | `localhost`                 | `Config::dbHost()`                              | —                         |
| `db_password`                             | `string` | `(empty)`                   | `Config::dbPassword()`                          | —                         |
| `db_prefix`                               | `string` | `piwigo_`                   | `Config::dbPrefix()`                            | —                         |
| `db_user`                                 | `string` | `(empty)`                   | `Config::dbUser()`                              | —                         |
| `debug_l10n`                              | `bool`   | `false`                     | `Config::debugL10n()`                           | —                         |
| `debug_mail`                              | `bool`   | `false`                     | `Config::debugMail()`                           | —                         |
| `debug_template`                          | `bool`   | `false`                     | `Config::debugTemplate()`                       | —                         |
| `default_redirect_method`                 | `string` | `http`                      | `Config::defaultRedirectMethod()`               | —                         |
| `default_user_id`                         | `int`    | `2`                         | `Config::defaultUserId()`                       | —                         |
| `derivative_default_size`                 | `string` | `medium`                    | `Config::derivativeDefaultSize()`               | —                         |
| `derivative_url_style`                    | `int`    | `0`                         | `Config::derivativeUrlStyle()`                  | —                         |
| `derivatives_strip_metadata_threshold`    | `int`    | `256000`                    | `Config::derivativesStripMetadataThreshold()`   | —                         |
| `die_on_sql_error`                        | `bool`   | `false`                     | `Config::dieOnSqlError()`                       | —                         |
| `display_fromto`                          | `bool`   | `false`                     | `Config::displayFromto()`                       | —                         |
| `double_password_type_in_admin`           | `bool`   | `false`                     | `Config::doublePasswordTypeInAdmin()`           | —                         |
| `email_admin_on_comment`                  | `string` | `none`                      | `Config::emailAdminOnComment()`                 | —                         |
| `email_admin_on_comment_deletion`         | `string` | `none`                      | `Config::emailAdminOnCommentDeletion()`         | —                         |
| `email_admin_on_comment_edition`          | `string` | `none`                      | `Config::emailAdminOnCommentEdition()`          | —                         |
| `email_admin_on_comment_validation`       | `string` | `none`                      | `Config::emailAdminOnCommentValidation()`       | —                         |
| `email_admin_on_new_user`                 | `string` | `none`                      | `Config::emailAdminOnNewUser()`                 | —                         |
| `empty_lounge_running`                    | `string` | `null`                      | `Config::emptyLoungeRunning()`                  | nullable                  |
| `enable_core_update`                      | `bool`   | `true`                      | `Config::enableCoreUpdate()`                    | —                         |
| `enable_extensions_install`               | `bool`   | `true`                      | `Config::enableExtensionsInstall()`             | —                         |
| `enable_formats`                          | `bool`   | `false`                     | `Config::isFormatsEnabled()`                    | —                         |
| `enable_plugins`                          | `bool`   | `true`                      | `Config::enablePlugins()`                       | —                         |
| `enable_synchronization`                  | `bool`   | `true`                      | `Config::enableSynchronization()`               | —                         |
| `ext_imagick_dir`                         | `string` | `(empty)`                   | `Config::extImagickDir()`                       | —                         |
| `extents_for_templates`                   | `string` | `null`                      | `Config::extentsForTemplates()`                 | nullable, custom accessor |
| `external_authentification`               | `bool`   | `false`                     | `Config::externalAuthentification()`            | —                         |
| `ffmpeg_dir`                              | `string` | `(empty)`                   | `Config::ffmpegDir()`                           | —                         |
| `file_ext`                                | `array`  | `null`                      | `Config::fileExtensions()`                      | custom accessor           |
| `filter_pages`                            | `array`  | `null`                      | `Config::filterPages()`                         | custom accessor           |
| `format_ext`                              | `array`  | `null`                      | `Config::formatExtensions()`                    | custom accessor           |
| `fs_quick_check_last_check`               | `string` | `null`                      | `Config::fsQuickCheckLastCheck()`               | nullable                  |
| `fs_quick_check_period`                   | `int`    | `86400`                     | `Config::fsQuickCheckPeriod()`                  | —                         |
| `full_tag_cloud_items_number`             | `int`    | `200`                       | `Config::fullTagCloudItemsNumber()`             | —                         |
| `gallery_locked`                          | `bool`   | `false`                     | `Config::galleryLocked()`                       | —                         |
| `gallery_title`                           | `string` | `Piwigo`                    | `Config::galleryTitle()`                        | —                         |
| `gallery_url`                             | `string` | `null`                      | `Config::galleryUrl()`                          | nullable                  |
| `graphics_library`                        | `string` | `auto`                      | `Config::graphicsLibrary()`                     | —                         |
| `guest_access`                            | `bool`   | `true`                      | `Config::guestAccess()`                         | —                         |
| `guest_id`                                | `int`    | `2`                         | `Config::guestId()`                             | —                         |
| `header_notes`                            | `array`  | `null`                      | `Config::headerNotes()`                         | custom accessor           |
| `history_admin`                           | `bool`   | `false`                     | `Config::historyAdmin()`                        | —                         |
| `history_autopurge_blocksize`             | `int`    | `50000`                     | `Config::historyAutopurgeBlocksize()`           | —                         |
| `history_autopurge_every`                 | `int`    | `1021`                      | `Config::historyAutopurgeEvery()`               | —                         |
| `history_autopurge_keep_lines`            | `int`    | `1000000`                   | `Config::historyAutopurgeKeepLines()`           | —                         |
| `history_guest`                           | `bool`   | `false`                     | `Config::historyGuest()`                        | —                         |
| `history_summarized_dropped`              | `bool`   | `false`                     | `Config::historySummarizedDropped()`            | —                         |
| `home_page`                               | `string` | `recent_pics`               | `Config::homePage()`                            | —                         |
| `index_caddie_icon`                       | `bool`   | `true`                      | `Config::indexCaddieIcon()`                     | —                         |
| `index_created_date_icon`                 | `bool`   | `true`                      | `Config::indexCreatedDateIcon()`                | —                         |
| `index_edit_icon`                         | `bool`   | `true`                      | `Config::indexEditIcon()`                       | —                         |
| `index_flat_icon`                         | `bool`   | `true`                      | `Config::indexFlatIcon()`                       | —                         |
| `index_new_icon`                          | `bool`   | `true`                      | `Config::indexNewIcon()`                        | —                         |
| `index_posted_date_icon`                  | `bool`   | `true`                      | `Config::indexPostedDateIcon()`                 | —                         |
| `index_search_in_set_action`              | `string` | `results`                   | `Config::indexSearchInSetAction()`              | —                         |
| `index_search_in_set_button`              | `bool`   | `false`                     | `Config::indexSearchInSetButton()`              | —                         |
| `index_sizes_icon`                        | `bool`   | `true`                      | `Config::indexSizesIcon()`                      | —                         |
| `index_slideshow_icon`                    | `bool`   | `true`                      | `Config::indexSlideShowIcon()`                  | —                         |
| `index_sort_order_input`                  | `string` | `(empty)`                   | `Config::indexSortOrderInput()`                 | —                         |
| `inheritance_by_default`                  | `bool`   | `false`                     | `Config::inheritanceByDefault()`                | —                         |
| `insensitive_case_logon`                  | `bool`   | `false`                     | `Config::insensitiveCaseLogon()`                | —                         |
| `last_major_update`                       | `string` | `null`                      | `Config::lastMajorUpdate()`                     | nullable                  |
| `level_separator`                         | `string` | `/`                         | `Config::levelSeparator()`                      | —                         |
| `light_album_manager_threshold`           | `int`    | `10000`                     | `Config::lightAlbumManagerThreshold()`          | —                         |
| `light_slideshow`                         | `bool`   | `true`                      | `Config::lightSlideshow()`                      | —                         |
| `linked_album_search_limit`               | `int`    | `100`                       | `Config::linkedAlbumSearchLimit()`              | —                         |
| `links`                                   | `array`  | `null`                      | `Config::links()`                               | custom accessor           |
| `log`                                     | `bool`   | `false`                     | `Config::logConf()`                             | —                         |
| `log_archive_days`                        | `int`    | `30`                        | `Config::logArchiveDays()`                      | —                         |
| `log_dir`                                 | `string` | `/logs`                     | `Config::logDir()`                              | —                         |
| `log_level`                               | `string` | `DEBUG`                     | `Config::logLevel()`                            | —                         |
| `lounge_activate_threshold`               | `int`    | `1`                         | `Config::loungeActivateThreshold()`             | —                         |
| `lounge_active`                           | `bool`   | `false`                     | `Config::loungeActive()`                        | —                         |
| `lounge_max_duration`                     | `int`    | `300`                       | `Config::loungeMaxDuration()`                   | —                         |
| `mail_allow_html`                         | `bool`   | `true`                      | `Config::mailAllowHtml()`                       | —                         |
| `mail_sender_email`                       | `string` | `(empty)`                   | `Config::mailSenderEmail()`                     | —                         |
| `mail_sender_name`                        | `string` | `(empty)`                   | `Config::mailSenderName()`                      | —                         |
| `mail_theme`                              | `string` | `light`                     | `Config::mailTheme()`                           | —                         |
| `max_requests`                            | `int`    | `3`                         | `Config::maxRequests()`                         | —                         |
| `menubar_filter_icon`                     | `bool`   | `true`                      | `Config::menubarFilterIcon()`                   | —                         |
| `menubar_tag_cloud_content`               | `string` | `all_or_current`            | `Config::menubarTagCloudContent()`              | —                         |
| `menubar_tag_cloud_items_number`          | `int`    | `20`                        | `Config::menubarTagCloudItemsNumber()`          | —                         |
| `meta_ref`                                | `bool`   | `true`                      | `Config::metaRef()`                             | —                         |
| `metadata_keyword_separator_regex`        | `string` | `/[.,;]/`                   | `Config::metadataKeywordSeparatorRegex()`       | —                         |
| `mobile_theme`                            | `string` | `(empty)`                   | `Config::mobilTheme()`                          | —                         |
| `nb_categories_page`                      | `int`    | `9999`                      | `Config::nbCategoriesPage()`                    | —                         |
| `nb_comment_page`                         | `int`    | `10`                        | `Config::nbCommentPage()`                       | —                         |
| `nb_logs_page`                            | `int`    | `300`                       | `Config::nbLogsPage()`                          | —                         |
| `nbm_complementary_mail_content`          | `string` | `(empty)`                   | `Config::nbmComplementaryMailContent()`         | —                         |
| `nbm_default_value_user_enabled`          | `bool`   | `false`                     | `Config::nbmDefaultValueUserEnabled()`          | —                         |
| `nbm_list_all_enabled_users_to_send`      | `bool`   | `false`                     | `Config::nbmListAllEnabledUsersToSend()`        | —                         |
| `nbm_max_treatment_timeout_percent`       | `float`  | `0.8`                       | `Config::nbmMaxTreatmentTimeoutPercent()`       | custom accessor           |
| `nbm_send_detailed_content`               | `bool`   | `true`                      | `Config::nbmSendDetailedContent()`              | —                         |
| `nbm_send_html_mail`                      | `bool`   | `true`                      | `Config::nbmSendHtmlMail()`                     | —                         |
| `nbm_send_mail_as`                        | `string` | `(empty)`                   | `Config::nbmSendMailAs()`                       | —                         |
| `nbm_send_recent_post_dates`              | `bool`   | `true`                      | `Config::nbmSendRecentPostDates()`              | —                         |
| `nbm_treatment_timeout_default`           | `int`    | `20`                        | `Config::nbmTreatmentTimeoutDefault()`          | —                         |
| `never_delete_originals`                  | `bool`   | `false`                     | `Config::neverDeleteOriginals()`                | —                         |
| `newcat_default_commentable`              | `bool`   | `true`                      | `Config::newcatDefaultCommentable()`            | —                         |
| `newcat_default_position`                 | `string` | `first`                     | `Config::newcatDefaultPosition()`               | —                         |
| `newcat_default_status`                   | `string` | `public`                    | `Config::newcatDefaultStatus()`                 | —                         |
| `newcat_default_visible`                  | `bool`   | `true`                      | `Config::newcatDefaultVisible()`                | —                         |
| `no_photo_yet_url`                        | `string` | `admin.php?page=photos_add` | `Config::noPhotoYetUrl()`                       | —                         |
| `obligatory_user_mail_address`            | `bool`   | `false`                     | `Config::obligatoryUserMailAddress()`           | —                         |
| `order_by`                                | `string` | `(empty)`                   | `Config::orderBy()`                             | —                         |
| `order_by_custom`                         | `string` | `null`                      | `Config::orderByCustom()`                       | nullable                  |
| `order_by_inside_category`                | `string` | `(empty)`                   | `Config::orderByInsideCategory()`               | —                         |
| `order_by_inside_category_custom`         | `string` | `null`                      | `Config::orderByInsideCategoryCustom()`         | nullable                  |
| `original_resize`                         | `bool`   | `false`                     | `Config::originalResize()`                      | —                         |
| `original_resize_maxheight`               | `int`    | `2000`                      | `Config::originalResizeMaxheight()`             | —                         |
| `original_resize_maxwidth`                | `int`    | `2000`                      | `Config::originalResizeMaxwidth()`              | —                         |
| `original_resize_quality`                 | `int`    | `95`                        | `Config::originalResizeQuality()`               | —                         |
| `original_url_protection`                 | `string` | `(empty)`                   | `Config::originalUrlProtection()`               | —                         |
| `page_banner`                             | `string` | `(empty)`                   | `Config::pageBanner()`                          | —                         |
| `paginate_pages_around`                   | `int`    | `2`                         | `Config::paginatePagesAround()`                 | —                         |
| `password_activation_duration`            | `int`    | `259200`                    | `Config::passwordActivationDuration()`          | —                         |
| `password_reset_code_duration`            | `int`    | `300`                       | `Config::passwordResetCodeDuration()`           | —                         |
| `password_reset_duration`                 | `int`    | `3600`                      | `Config::passwordResetDuration()`               | —                         |
| `pdf_viewer_filesize_threshold`           | `int`    | `5`                         | `Config::pdfViewerFilesizeThreshold()`          | —                         |
| `pem_languages_category`                  | `int`    | `8`                         | `Config::pemLanguagesCategory()`                | —                         |
| `pem_plugins_category`                    | `int`    | `12`                        | `Config::pemPluginsCategory()`                  | —                         |
| `pem_themes_category`                     | `int`    | `10`                        | `Config::pemThemesCategory()`                   | —                         |
| `picture_caddie_icon`                     | `bool`   | `true`                      | `Config::pictureCaddieIcon()`                   | —                         |
| `picture_download_icon`                   | `bool`   | `true`                      | `Config::pictureDownloadIcon()`                 | —                         |
| `picture_edit_icon`                       | `bool`   | `true`                      | `Config::pictureEditIcon()`                     | —                         |
| `picture_ext`                             | `array`  | `null`                      | `Config::pictureExtensions()`                   | custom accessor           |
| `picture_favorite_icon`                   | `bool`   | `true`                      | `Config::pictureFavoriteIcon()`                 | —                         |
| `picture_informations`                    | `string` | `null`                      | `Config::pictureInformations()`                 | nullable                  |
| `picture_menu`                            | `bool`   | `true`                      | `Config::pictureMenu()`                         | —                         |
| `picture_metadata_icon`                   | `bool`   | `true`                      | `Config::pictureMetadataIcon()`                 | —                         |
| `picture_navigation_icons`                | `bool`   | `true`                      | `Config::pictureNavigationIcons()`              | —                         |
| `picture_navigation_thumb`                | `bool`   | `true`                      | `Config::pictureNavigationThumb()`              | —                         |
| `picture_representative_icon`             | `bool`   | `true`                      | `Config::pictureRepresentativeIcon()`           | —                         |
| `picture_sizes_icon`                      | `bool`   | `true`                      | `Config::pictureSizesIcon()`                    | —                         |
| `picture_slideshow_icon`                  | `bool`   | `true`                      | `Config::pictureSlideShowIcon()`                | —                         |
| `picture_url_style`                       | `string` | `id`                        | `Config::pictureUrlStyle()`                     | —                         |
| `piwigo_db_version`                       | `string` | `null`                      | `Config::piwigoDbVersion()`                     | nullable                  |
| `piwigo_installed_version`                | `string` | `null`                      | `Config::piwigoInstalledVersion()`              | nullable                  |
| `proxy_auth`                              | `string` | `(empty)`                   | `Config::proxyAuth()`                           | —                         |
| `proxy_server`                            | `string` | `(empty)`                   | `Config::proxyServer()`                         | —                         |
| `quick_search_include_sub_albums`         | `bool`   | `false`                     | `Config::quickSearchIncludeSubAlbums()`         | —                         |
| `random_index_redirect`                   | `array`  | `null`                      | `Config::randomIndexRedirect()`                 | custom accessor           |
| `rate`                                    | `bool`   | `true`                      | `Config::rateEnabled()`                         | —                         |
| `rate_anonymous`                          | `bool`   | `true`                      | `Config::rateAnonymous()`                       | —                         |
| `rate_items`                              | `array`  | `null`                      | `Config::rateItems()`                           | custom accessor           |
| `recent_post_dates`                       | `array`  | `null`                      | `Config::recentPostDates()`                     | custom accessor           |
| `related_albums_display_limit`            | `int`    | `20`                        | `Config::relatedAlbumsDisplayLimit()`           | —                         |
| `related_albums_maximum_items_to_compute` | `int`    | `1000`                      | `Config::relatedAlbumsMaximumItemsToCompute()`  | —                         |
| `remember_me_length`                      | `int`    | `5184000`                   | `Config::rememberMeLength()`                    | —                         |
| `remember_me_name`                        | `string` | `pwg_remember`              | `Config::rememberMeName()`                      | —                         |
| `representative_cache_on_level`           | `bool`   | `true`                      | `Config::representativeCacheOnLevel()`          | —                         |
| `representative_cache_on_subcats`         | `bool`   | `true`                      | `Config::representativeCacheOnSubcats()`        | —                         |
| `rss_feed_author`                         | `string` | `Piwigo notifier`           | `Config::rssReedAuthor()`                       | —                         |
| `secret_key`                              | `string` | `(empty)`                   | `Config::secretKey()`                           | —                         |
| `send_bcc_mail_webmaster`                 | `bool`   | `false`                     | `Config::sendBccMailWebmaster()`                | —                         |
| `send_piwigo_infos`                       | `bool`   | `true`                      | `Config::sendPiwigoInfos()`                     | —                         |
| `send_piwigo_infos_last_notice`           | `string` | `null`                      | `Config::sendPiwigoInfosLastNotice()`           | nullable                  |
| `send_piwigo_infos_origin_hash`           | `string` | `null`                      | `Config::sendPiwigoInfosOriginHash()`           | nullable                  |
| `session_gc_probability`                  | `int`    | `1`                         | `Config::sessionGcProbability()`                | —                         |
| `session_length`                          | `int`    | `3600`                      | `Config::sessionLength()`                       | —                         |
| `session_name`                            | `string` | `pwg_id`                    | `Config::sessionName()`                         | —                         |
| `session_save_handler`                    | `string` | `db`                        | `Config::sessionSaveHandler()`                  | —                         |
| `session_use_cookies`                     | `bool`   | `true`                      | `Config::sessionUseCookies()`                   | —                         |
| `session_use_ip_address`                  | `bool`   | `true`                      | `Config::sessionUseIpAddress()`                 | —                         |
| `session_use_only_cookies`                | `bool`   | `true`                      | `Config::sessionUseOnlyCookies()`               | —                         |
| `session_use_trans_sid`                   | `bool`   | `false`                     | `Config::sessionUseTransSid()`                  | —                         |
| `show_exif`                               | `bool`   | `true`                      | `Config::showExif()`                            | —                         |
| `show_exif_fields`                        | `array`  | `null`                      | `Config::showExifFields()`                      | custom accessor           |
| `show_gt`                                 | `bool`   | `false`                     | `Config::showGt()`                              | —                         |
| `show_iptc`                               | `bool`   | `false`                     | `Config::showIptc()`                            | —                         |
| `show_iptc_mapping`                       | `array`  | `null`                      | `Config::showIptcMapping()`                     | custom accessor           |
| `show_newsletter_subscription`            | `bool`   | `true`                      | `Config::showNewsletterSubscription()`          | —                         |
| `show_php_errors`                         | `int`    | `30719`                     | `Config::showPhpErrors()`                       | —                         |
| `show_php_errors_on_frontend`             | `bool`   | `true`                      | `Config::showPhpErrorsOnFrontend()`             | —                         |
| `show_piwigo_latest_news`                 | `bool`   | `true`                      | `Config::showPiwigoLatestNews()`                | —                         |
| `show_queries`                            | `bool`   | `false`                     | `Config::showQueries()`                         | —                         |
| `show_template_in_side_menu`              | `bool`   | `false`                     | `Config::showTemplateInSideMenu()`              | —                         |
| `show_thumbnail_caption`                  | `bool`   | `true`                      | `Config::showThumbnailCaption()`                | —                         |
| `show_version`                            | `bool`   | `false`                     | `Config::showVersion()`                         | —                         |
| `slideshow_period`                        | `int`    | `4`                         | `Config::slideshowPeriod()`                     | —                         |
| `slideshow_period_max`                    | `int`    | `10`                        | `Config::slideshowPeriodMax()`                  | —                         |
| `slideshow_period_min`                    | `int`    | `1`                         | `Config::slideshowPeriodMin()`                  | —                         |
| `slideshow_period_step`                   | `int`    | `1`                         | `Config::slideshowPeriodStep()`                 | —                         |
| `slideshow_repeat`                        | `bool`   | `true`                      | `Config::slideshowRepeat()`                     | —                         |
| `smtp_host`                               | `string` | `(empty)`                   | `Config::smtpHost()`                            | —                         |
| `smtp_password`                           | `string` | `(empty)`                   | `Config::smtpPassword()`                        | —                         |
| `smtp_secure`                             | `string` | `null`                      | `Config::smtpSecure()`                          | nullable                  |
| `smtp_user`                               | `string` | `(empty)`                   | `Config::smtpUser()`                            | —                         |
| `stat_compare_year_displayed`             | `int`    | `5`                         | `Config::statCompareYearDisplayed()`            | —                         |
| `sync_chars_regex`                        | `string` | `/^[a-zA-Z0-9-_.]+$/`       | `Config::syncCharsRegex()`                      | —                         |
| `sync_exclude_folders`                    | `array`  | `null`                      | `Config::syncExcludeFolders()`                  | custom accessor           |
| `tag_letters_column_number`               | `int`    | `4`                         | `Config::tagLettersColumnNumber()`              | —                         |
| `tag_url_style`                           | `string` | `id-tag`                    | `Config::tagUrlStyle()`                         | —                         |
| `tags_default_display_mode`               | `string` | `cloud`                     | `Config::tagsDefaultDisplayMode()`              | —                         |
| `tags_levels`                             | `int`    | `5`                         | `Config::tagsLevels()`                          | —                         |
| `template_combine_files`                  | `bool`   | `true`                      | `Config::templateCombineFiles()`                | —                         |
| `template_compile_check`                  | `bool`   | `true`                      | `Config::templateCompileCheck()`                | —                         |
| `template_force_compile`                  | `bool`   | `false`                     | `Config::templateForceCompile()`                | —                         |
| `themes_dir`                              | `string` | `./themes`                  | `Config::themesDir()`                           | —                         |
| `tiff_representative_ext`                 | `string` | `png`                       | `Config::tiffRepresentativeExt()`               | —                         |
| `top_number`                              | `int`    | `15`                        | `Config::topNumber()`                           | —                         |
| `uniqueness_mode`                         | `string` | `md5sum`                    | `Config::uniquenessMode()`                      | —                         |
| `update_notify_check_period`              | `int`    | `86400`                     | `Config::updateNotifyCheckPeriod()`             | —                         |
| `update_notify_last_check`                | `string` | `null`                      | `Config::updateNotifyLastCheck()`               | nullable                  |
| `update_notify_last_notification_at`      | `string` | `null`                      | `Config::updateNotifyLastNotificationAt()`      | nullable                  |
| `update_notify_last_notification_version` | `string` | `null`                      | `Config::updateNotifyLastNotificationVersion()` | nullable                  |
| `update_notify_reminder_period`           | `int`    | `604800`                    | `Config::updateNotifyReminderPeriod()`          | —                         |
| `upload_detect_duplicate`                 | `bool`   | `true`                      | `Config::uploadDetectDuplicate()`               | —                         |
| `upload_dir`                              | `string` | `./upload`                  | `Config::uploadDir()`                           | —                         |
| `upload_form_all_types`                   | `bool`   | `false`                     | `Config::uploadFormAllTypes()`                  | —                         |
| `upload_form_automatic_rotation`          | `bool`   | `true`                      | `Config::uploadFormAutomaticRotation()`         | —                         |
| `upload_form_chunk_size`                  | `int`    | `500`                       | `Config::uploadFormChunkSize()`                 | —                         |
| `upload_form_max_file_size`               | `int`    | `1000`                      | `Config::uploadFormMaxFileSize()`               | —                         |
| `url_port`                                | `string` | `none`                      | `Config::urlPort()`                             | —                         |
| `use_exif`                                | `bool`   | `true`                      | `Config::useExif()`                             | —                         |
| `use_exif_mapping`                        | `array`  | `null`                      | `Config::useExifMapping()`                      | custom accessor           |
| `use_iptc`                                | `bool`   | `false`                     | `Config::useIptc()`                             | —                         |
| `use_iptc_mapping`                        | `array`  | `null`                      | `Config::useIptcMapping()`                      | custom accessor           |
| `use_proxy`                               | `bool`   | `false`                     | `Config::useProxy()`                            | —                         |
| `user_can_delete_comment`                 | `bool`   | `false`                     | `Config::userCanDeleteComment()`                | —                         |
| `user_can_edit_comment`                   | `bool`   | `false`                     | `Config::userCanEditComment()`                  | —                         |
| `user_fields`                             | `array`  | `null`                      | `Config::userFields()`                          | custom accessor           |
| `users_table`                             | `string` | `null`                      | `Config::usersTable()`                          | nullable                  |
| `webmaster_id`                            | `int`    | `1`                         | `Config::webmasterId()`                         | —                         |
| `week_starts_on`                          | `string` | `monday`                    | `Config::weekStartsOn()`                        | —                         |
| `ws_max_images_per_page`                  | `int`    | `500`                       | `Config::wsMaxImagesPerPage()`                  | —                         |
| `ws_max_users_per_page`                   | `int`    | `1000`                      | `Config::wsMaxUsersPerPage()`                   | —                         |

## Environment variable overrides

A small curated subset of keys can be overridden at runtime via env vars,
loaded by `Piwigo\Config\ConfigLoader::loadEnv()` from `.env` (or `.env.test`
when `TestMode` is active). Real environment variables (set by Apache
`SetEnv`, systemd `EnvironmentFile=`, Docker `-e`, or a parent shell)
win over the file values — standard 12-factor precedence.

| Env var              | Schema key    |
| -------------------- | ------------- |
| `PIWIGO_DB_HOST`     | `db_host`     |
| `PIWIGO_DB_USER`     | `db_user`     |
| `PIWIGO_DB_PASSWORD` | `db_password` |
| `PIWIGO_DB_BASE`     | `db_base`     |
| `PIWIGO_DB_PREFIX`   | `db_prefix`   |

Fresh installs write `PIWIGO_DB_*` to a `.env` file at the repo root
(`local/config/database.inc.php` and `include/config_default.inc.php` were
retired in the 16.x rewrite — there is no PHP-include fallback path).
