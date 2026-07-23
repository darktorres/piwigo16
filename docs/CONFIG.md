# Configuration reference

`Piwigo\Config\Config` (P13) is the single typed source of truth for every runtime
configuration key. `Config::SCHEMA` declares each key's type, default value, and accessor
method; `ConfigLoader` populates `Config`'s internal store at boot from that schema plus any
`local/config/config.inc.php` overrides and environment variables. `Piwigo\Config\ConfigService`
(reached via `CurrentConfigService::get()`) is the DB-backed persistence layer that reads/writes
the `config` table on top of it — see `Config.php`'s own class docblock for the full read/write
split.

The table below is generated directly from `Config::SCHEMA` — do not hand-edit it. Regenerate
after adding, removing, or editing any SCHEMA entry:

```
php tools/build-config-docs.php
```

**Flags column:** `required` — `ConfigLoader::validateRequired()` rejects a missing/empty value
at boot. `sensitive` — redacted by `Config::dumpForLog()`. `custom accessor` — this key's
accessor is hand-written below `Config.php`'s `<<<CONFIG-ACCESSORS-END>>>` sentinel instead of
being mechanically generated (its access logic doesn't fit the simple get/set-scalar pattern).

<!-- <<<CONFIG-TABLE-BEGIN>>> -->

| Key | Type | Default | Flags | Description |
| --- | --- | --- | --- | --- |
| `activate_comments` | bool | true |  | Enable or disable user comments on photos gallery-wide. |
| `activity_display_connections` | string | `all` |  | Which connection events to show in the activity log: all, admin, or none. |
| `add_cache_to_storage_chart` | bool | true |  | Include cache files in the storage usage chart on the dashboard. |
| `admin_theme` | string | `clear` |  | Site-wide fallback admin theme (clear, default, or roma) used when a user has no admin_theme preference of their own yet. |
| `album_description_on_all_pages` | bool | false |  | Show the album description on every paginated page, not just the first. |
| `album_move_delay_before_auto_opening` | int | 3000 |  | Milliseconds to wait before auto-expanding an album drop-target during drag-and-drop. |
| `allow_html_descriptions` | bool | true |  | Allow HTML markup in photo and album descriptions. |
| `allow_html_in_metadata` | bool | false |  | Allow HTML in metadata values extracted from photo files. |
| `allow_random_representative` | bool | false |  | Allow a random photo to represent an album that has no explicit representative set. |
| `allow_user_customization` | bool | true |  | Let registered users change their own display preferences. |
| `allow_user_registration` | bool | true |  | Allow new users to self-register from the public gallery. |
| `allow_web_services` | bool | true |  | Enable the Piwigo web-service (API) endpoint. |
| `allowed_hosts` | array | null | custom accessor | Hostnames UrlService trusts when building absolute URLs from the Host / X-Forwarded-Host header. Empty means auto-detect (trust the header), matching prior releases. |
| `alternative_pem_url` | string | _(empty string)_ |  | Override URL for the Piwigo Extensions Manager repository. |
| `animated_webp_compression_quality` | int | 70 |  | Quality level (1-100) for animated WebP derivative encoding. |
| `anti-flood_time` | int | 60 |  | Minimum seconds between comment posts from the same user to prevent spam. |
| `apache_authentication` | bool | false |  | Trust HTTP Basic Auth credentials supplied by Apache (mod_auth). |
| `api_key_duration` | array | null | custom accessor | Lifetime configuration for API keys (array with count and unit). |
| `api_key_forbidden_methods` | array | null | custom accessor | Web-service method names that API-key callers are not allowed to invoke. |
| `auth_key_duration` | int | 259200 |  | Lifetime in seconds for single-use authentication keys sent in emails. |
| `authorize_remembering` | bool | true |  | Allow users to use the remember-me persistent login cookie. |
| `available_permission_levels` | array | null | custom accessor | Ordered list of numeric permission levels visible in the UI. |
| `batch_manager_images_per_page_global` | int | 20 |  | Number of photos shown per page in the batch-manager global view. |
| `batch_manager_images_per_page_unit` | int | 5 |  | Number of photos shown per page in the batch-manager unit view. |
| `browser_language` | bool | true |  | Automatically detect and use the visitor browser language preference. |
| `c13y_ignore` | string | null | custom accessor | Serialized {version, list} of integrity-check anomalies the admin has acknowledged/ignored (Admin/Integrity CheckIntegrity.php). |
| `cache.backend` | string | `file` |  | Cache driver to use: file or redis. |
| `cache.default_ttl` | int | 86400 |  | Default cache entry time-to-live in seconds. |
| `cache.namespace` | string | _(empty string)_ |  | Namespace prefix for all cache keys, useful when sharing a Redis instance. |
| `cache.redis_url` | string | `redis://localhost:6379` |  | Redis connection DSN used when cache.backend is redis. |
| `cache_sizes` | array | null | custom accessor | Serialized [name, value] rows of cache-directory sizes computed by the maintenance page, cached to avoid recomputing on every dashboard/maintenance load. |
| `calendar_datefield` | string | `date_creation` |  | Date field used for the calendar view: date_creation or date_available. |
| `calendar_show_any` | bool | true |  | Show an Any link in the calendar so visitors can view photos without a date filter. |
| `calendar_show_empty` | bool | true |  | Show months and years with no photos in the calendar navigation. |
| `category_url_style` | string | `id` |  | URL format for album links: id or id-name. |
| `check_upgrade_feed` | bool | false |  | Check for pending database upgrades on every page load. |
| `checksum_compute_blocksize` | int | 50 |  | Number of photos per block when computing file checksums in batch. |
| `chmod_value` | int | null | custom accessor | Filesystem permission bits applied to newly created directories -- 0777 under Apache, 0755 otherwise, unless explicitly overridden. |
| `comment_spam_max_links` | int | 3 |  | Maximum number of links allowed in a single comment before it is rejected as spam. |
| `comment_spam_reject` | bool | true |  | Silently reject comments that exceed the spam link threshold. |
| `comments_author_mandatory` | bool | false |  | Require commenters to supply an author name. |
| `comments_email_mandatory` | bool | false |  | Require commenters to supply an email address. |
| `comments_enable_website` | bool | true |  | Show a website field in the comment form. |
| `comments_forall` | bool | false |  | Allow unauthenticated (guest) visitors to post comments. |
| `comments_order` | string | `ASC` |  | Sort order for comment display: ASC (oldest first) or DESC (newest first). |
| `comments_page_nb_comments` | int | 10 |  | Number of comments shown per page on the admin comments page. |
| `comments_validation` | bool | false |  | Require admin approval before newly posted comments appear publicly. |
| `compiled_template_cache_language` | bool | false |  | Include the active language in the compiled-template cache key. |
| `content_tag_cloud_items_number` | int | 12 |  | Maximum number of tags shown in the content-area tag cloud. |
| `dashboard_activity_nb_weeks` | int | 4 |  | Number of weeks of activity data shown on the admin dashboard. |
| `dashboard_check_for_updates` | bool | true |  | Check for Piwigo core updates on the admin dashboard. |
| `data_dir_checked` | string? | null |  | Presence-only marker: once set (to '1'), Template's data-directory writability check is permanently skipped. Genuine absence until the check first passes, matching the gallery_url/last_major_update convention. |
| `data_location` | string | `_data/` |  | Relative path from the Piwigo root to the writable data directory. |
| `db_base` | string | _(empty string)_ | required | MySQL/MariaDB database name. |
| `db_driver` | string | `mysqli` |  | DBAL driver: 'mysqli' (MySQL/MariaDB) or 'pgsql' (PostgreSQL). Native drivers only, matching ADR-0021's native-platform-first policy -- not pdo_mysql/pdo_pgsql. |
| `db_host` | string | `localhost` | required | MySQL/MariaDB host (hostname or IP, optionally with port). |
| `db_password` | string | _(empty string)_ | sensitive | MySQL/MariaDB password for the database user. |
| `db_port` | int? | null | custom accessor | Database server port. Null uses the driver's own default (3306 for mysqli, 5432 for pgsql). Previously read from PIWIGO_DB_PORT but silently unused until P15's multi-provider work needed it for real. |
| `db_prefix` | string | `piwigo_` |  | Table name prefix for all Piwigo database tables. |
| `db_user` | string | _(empty string)_ | required | MySQL/MariaDB user account used by Piwigo. |
| `debug_l10n` | bool | false |  | Highlight untranslated strings in the UI for l10n debugging. |
| `debug_mail` | bool | false |  | Log all outgoing mail to a file instead of sending. |
| `debug_template` | bool | false |  | Add template debugging information to rendered pages. |
| `default_filters_views` | array | null | custom accessor | Factory-default search-filter definitions (access level + default-on state per filter key); seeds the 'filters_views' DB row on first use and drives the search filters admin page. |
| `default_redirect_method` | string | `http` |  | HTTP redirect method Piwigo uses internally: http or html. |
| `default_user_id` | int | 2 |  | User ID whose settings serve as defaults for new accounts. |
| `derivative_default_size` | string | `medium` |  | Default derivative size name served when no size is specified. |
| `derivative_url_style` | int | 2 |  | Derivative URL format: 0 = auto (static link if already cached, else routed through i.php), 1 = always a static link, 2 = always routed through i.php. |
| `derivatives` | string | null | custom accessor | Serialized ImageStdParams derivative-size definitions saved by the photo sizes admin page. Absent on a fresh install until the admin saves the sizes form once. |
| `derivatives_strip_metadata_threshold` | int | 256000 |  | File size in bytes above which EXIF/IPTC metadata is stripped from derivatives. |
| `die_on_sql_error` | bool | false |  | Halt execution immediately when a database query fails. |
| `disabled_derivatives` | string | null | custom accessor | Serialized list of derivative type keys the admin has disabled from generation via the photo sizes admin page. |
| `display_fromto` | bool | false |  | Show the date range of photos in album and search results headers. |
| `double_password_type_in_admin` | bool | false |  | Require admins to enter a new password twice when setting it. |
| `email_admin_on_comment` | bool | false |  | Send an email to the administrators when a valid comment is entered. |
| `email_admin_on_comment_deletion` | bool | false |  | Send an email to the administrators when a comment is deleted. |
| `email_admin_on_comment_edition` | bool | false |  | Send an email to the administrators when a comment is modified. |
| `email_admin_on_comment_validation` | bool | true |  | Send an email to the administrators when a comment requires validation. |
| `email_admin_on_new_user` | string | `none` |  | When to email the webmaster when a new user registers: none, all, or new. |
| `empty_lounge_running` | string | null | custom accessor | Transient "<execId>-<startTime>" marker set while ImageService::emptyLounge() is running, used to detect a concurrent/stalled run. Absent when no run is in progress. |
| `enable_core_update` | bool | true |  | Allow Piwigo core to be updated from the administration panel. |
| `enable_extensions_install` | bool | true |  | Allow plugins and themes to be installed from the administration panel. |
| `enable_formats` | bool | false |  | Enable the multi-format photo feature (original plus additional formats). |
| `enable_plugins` | bool | true |  | Load and activate installed plugins. |
| `enable_synchronization` | bool | true |  | Allow directory-to-database synchronization from the admin panel. |
| `ext_imagick_dir` | string | _(empty string)_ |  | Filesystem path to the ImageMagick binary directory (leave empty to auto-detect). |
| `extents_for_templates` | string? | null | custom accessor | Comma-separated list of template file extensions recognised by the theme engine. |
| `external_authentification` | bool | false |  | Enable authentication delegation to an external system. |
| `ffmpeg_dir` | string | _(empty string)_ |  | Filesystem path to the FFmpeg binary directory (leave empty to auto-detect). |
| `file_ext` | array | null | custom accessor | Full list of file extensions Piwigo will manage (pictures plus extras). |
| `filter_pages` | array | null | custom accessor | Pages on which the tag/date filter UI is displayed. |
| `filters_views` | array | null | custom accessor | Admin-customized search-filter definitions, lazily seeded from 'default_filters_views' the first time the search filters admin page is saved. Absent (falls back to defaultFiltersViews()) until then. |
| `format_ext` | array | null | custom accessor | File extensions recognised as additional formats for multi-format photos. |
| `fs_quick_check_last_check` | string? | null |  | Timestamp of the last filesystem quick-check run. |
| `fs_quick_check_period` | int | 86400 |  | Interval in seconds between automatic filesystem quick-checks. |
| `full_tag_cloud_items_number` | int | 200 |  | Maximum number of tags shown on the full tag-cloud page. |
| `gallery_locked` | bool | false |  | Lock the gallery for maintenance, blocking non-admin access. |
| `gallery_title` | string | `Piwigo` |  | Title of the gallery shown in the browser tab and page header. |
| `gallery_url` | string? | null |  | Public base URL of the gallery (overrides auto-detection when set). |
| `graphics_library` | string | `auto` |  | Image processing backend: auto, gd, imagick, or ext_imagick. |
| `guest_access` | bool | true |  | Allow unauthenticated (guest) visitors to browse public photos. |
| `guest_id` | int | 2 |  | User ID of the built-in guest account used for unauthenticated sessions. |
| `header_notes` | array | null | custom accessor | Additional HTML messages shown in the gallery header for all users. |
| `history_admin` | bool | false |  | Log page visits by admin users in the history table. |
| `history_autopurge_blocksize` | int | 50000 |  | Number of rows deleted per autopurge cycle from the history table. |
| `history_autopurge_every` | int | 1021 |  | Autopurge frequency: delete old history every N page loads (approximately). |
| `history_autopurge_keep_lines` | int | 1000000 |  | Maximum number of history rows to retain after an autopurge. |
| `history_guest` | bool | false |  | Log page visits by guest (unauthenticated) users in the history table. |
| `history_sections_cache` | array | null | custom accessor | Cached list of the history.section enum column values, refreshed when a plugin adds a new section. |
| `index_caddie_icon` | bool | true |  | Show the add-to-caddie icon on album index pages. |
| `index_created_date_icon` | bool | true |  | Show the creation-date icon on album index pages. |
| `index_edit_icon` | bool | true |  | Show the quick-edit icon on album index pages (admins only). |
| `index_flat_icon` | bool | true |  | Show the flat-view icon on album index pages. |
| `index_new_icon` | bool | true |  | Show the new badge icon on recently added photos in album index pages. |
| `index_posted_date_icon` | bool | true |  | Show the posted-date icon on album index pages. |
| `index_search_in_set_action` | string | `results` |  | Behaviour when searching within the current set: results or filter. |
| `index_search_in_set_button` | bool | false |  | Show the search-within-set button on album index pages. |
| `index_sizes_icon` | bool | true |  | Show the available-sizes icon on album index pages. |
| `index_slideshow_icon` | bool | true |  | Show the slideshow icon on album index pages. |
| `index_sort_order_input` | bool | true |  | Display the image order selection list on album index pages. |
| `inheritance_by_default` | bool | false |  | Apply parent album permissions to newly created sub-albums by default. |
| `insensitive_case_logon` | bool | false |  | Allow login with any letter-case variation of the username. |
| `last_major_update` | string? | null |  | Timestamp of the last major Piwigo upgrade, used for change detection. |
| `level_separator` | string | ` / ` |  | String used to separate album hierarchy levels in breadcrumb trails. |
| `light_album_manager_threshold` | int | 10000 |  | Album count above which the lightweight album manager UI is used. |
| `light_slideshow` | bool | true |  | Use the lightweight built-in slideshow instead of a plugin-based one. |
| `linked_album_search_limit` | int | 100 |  | Maximum albums returned when searching for albums to link a photo to. |
| `links` | array | null | custom accessor | Additional navigation links shown in the gallery menu. |
| `log` | bool | false |  | Enable the application log. |
| `log_archive_days` | int | 30 |  | Number of days to keep archived log files before deletion. |
| `log_dir` | string | `/logs` |  | Directory (relative to the data location) where log files are written. |
| `log_level` | string | `DEBUG` |  | Minimum log severity to record: DEBUG, INFO, WARNING, or ERROR. |
| `lounge_activate_threshold` | int | 1 |  | Number of photos in the lounge that triggers automatic album creation. |
| `lounge_active` | bool | false |  | Enable the lounge feature (a staging area for uploaded photos). |
| `lounge_max_duration` | int | 300 |  | Maximum seconds a photo can stay in the lounge before auto-processing. |
| `mail_allow_html` | bool | true |  | Send emails in HTML format in addition to plain text. |
| `mail_sender_email` | string | _(empty string)_ |  | From email address used for all outgoing Piwigo emails. |
| `mail_sender_name` | string | _(empty string)_ |  | Display name shown as the email sender in outgoing Piwigo emails. |
| `mail_theme` | string | `light` |  | Visual theme for HTML notification emails: light or dark. |
| `max_requests` | int | 3 |  | Maximum concurrent HTTP requests Piwigo will make to external services. |
| `menubar_filter_icon` | bool | true |  | Show the filter icon in the sidebar menu. |
| `menubar_tag_cloud_content` | string | `all_or_current` |  | Which tags to show in the sidebar tag cloud: all_or_current or current. |
| `menubar_tag_cloud_items_number` | int | 20 |  | Maximum number of tags shown in the sidebar tag cloud. |
| `meta_ref` | bool | true |  | Emit a referrer meta tag allowing search engines to attribute traffic. |
| `metadata_keyword_separator_regex` | string | `/[.,;]/` | custom accessor | PCRE regex used to split keyword strings extracted from EXIF/IPTC metadata. |
| `mobile_theme` | string | _(empty string)_ |  | Theme name applied automatically when a mobile browser is detected. |
| `nb_categories_page` | int | 9999 |  | Maximum albums shown per page in admin album listings. |
| `nb_comment_page` | int | 10 |  | Number of comments per page on the public photo detail page. |
| `nb_logs_page` | int | 300 |  | Number of history entries shown per page in the admin history view. |
| `nbm_complementary_mail_content` | string | _(empty string)_ |  | Extra HTML appended to notification-by-mail digest emails. |
| `nbm_default_value_user_enabled` | bool | false |  | Subscribe new users to notification-by-mail digests by default. |
| `nbm_list_all_enabled_users_to_send` | bool | false |  | Show all subscribed users in the NBM send UI, not just those with pending notifications. |
| `nbm_max_treatment_timeout_percent` | float | 0.8 | custom accessor | Fraction of the PHP max_execution_time budget NBM may consume per batch. |
| `nbm_send_detailed_content` | bool | true |  | Include photo thumbnails and descriptions in NBM digest emails. |
| `nbm_send_html_mail` | bool | true |  | Send NBM digest emails in HTML format. |
| `nbm_send_mail_as` | string | _(empty string)_ |  | Override the From display name used specifically for NBM emails. |
| `nbm_send_recent_post_dates` | bool | true |  | Include recent-post date ranges in NBM digest emails. |
| `nbm_treatment_timeout_default` | int | 20 |  | Default timeout in seconds for a single NBM send-batch execution. |
| `never_delete_originals` | bool | false |  | Prevent deletion of original image files when a photo is removed. |
| `newcat_default_commentable` | bool | true |  | Make newly created albums commentable by default. |
| `newcat_default_position` | string | `first` |  | Insert position for new sub-albums: first or last. |
| `newcat_default_status` | string | `public` |  | Default visibility for new albums: public or private. |
| `newcat_default_visible` | bool | true |  | Make newly created albums visible by default. |
| `no_photo_yet` | string? | null |  | Presence-only marker: once set (to 'false'), NoPhotoYetRenderer's first-run banner is permanently suppressed. Genuine absence on a fresh install/reset -- callers use Config::has() to detect first-run state, matching the gallery_url/last_major_update convention. |
| `no_photo_yet_url` | string | `admin.php?page=photos_add` |  | Admin URL linked from the no-photos-yet placeholder shown to admins. |
| `obligatory_user_mail_address` | bool | false |  | Require an email address for all user registrations. |
| `order_by` | array | null | custom accessor | Active sort order applied to photo listings (list of field+direction specs). |
| `order_by_custom` | array? | null | custom accessor | Admin-defined custom sort order that overrides order_by when set. |
| `order_by_inside_category` | array | null | custom accessor | Active sort order applied within album listings. |
| `order_by_inside_category_custom` | array? | null | custom accessor | Admin-defined custom sort order that overrides order_by_inside_category when set. |
| `original_resize` | bool | false |  | Resize uploaded originals that exceed the configured maximum dimensions. |
| `original_resize_maxheight` | int | 2000 |  | Maximum pixel height for uploaded originals when resize is enabled. |
| `original_resize_maxwidth` | int | 2000 |  | Maximum pixel width for uploaded originals when resize is enabled. |
| `original_resize_quality` | int | 95 |  | JPEG quality (1-100) used when resizing uploaded originals. |
| `original_url_protection` | string | _(empty string)_ |  | Original-file URL protection mode: empty (none), images, or all. |
| `page_banner` | string | _(empty string)_ |  | HTML banner content displayed at the top of public gallery pages. |
| `paginate_pages_around` | int | 2 |  | Number of page-number links shown on each side of the current page in pagination. |
| `password_activation_duration` | int | 259200 |  | Seconds a password-activation link emailed to new users remains valid. |
| `password_reset_code_duration` | int | 300 |  | Seconds a password-reset verification code is valid. |
| `password_reset_duration` | int | 3600 |  | Seconds a password-reset link emailed to a user remains valid. |
| `pdf_viewer_filesize_threshold` | int | 5 |  | Maximum PDF file size in MB to display inline; larger files show a download link. |
| `pem_languages_category` | int | 8 |  | PEM (Piwigo Extensions Manager) category ID for language packs. |
| `pem_plugins_category` | int | 12 |  | PEM category ID for plugins. |
| `pem_themes_category` | int | 10 |  | PEM category ID for themes. |
| `php_extension_in_urls` | bool | true |  | Include the .php extension in generated picture/category URLs. Works only with Options +MultiViews or URL rewriting active. |
| `picture_caddie_icon` | bool | true |  | Show the add-to-caddie icon on the photo detail page. |
| `picture_download_icon` | bool | true |  | Show the download icon on the photo detail page. |
| `picture_edit_icon` | bool | true |  | Show the quick-edit icon on the photo detail page (admins only). |
| `picture_ext` | array | null | custom accessor | File extensions recognised as displayable photo types. |
| `picture_favorite_icon` | bool | true |  | Show the add-to-favorites icon on the photo detail page. |
| `picture_informations` | array | null | custom accessor | Map of metadata field names to visibility booleans on the photo detail page. |
| `picture_menu` | bool | true |  | Show the navigation menu on the photo detail page. |
| `picture_metadata_icon` | bool | true |  | Show the metadata icon on the photo detail page. |
| `picture_navigation_icons` | bool | true |  | Show previous/next navigation arrows on the photo detail page. |
| `picture_navigation_thumb` | bool | true |  | Show previous/next thumbnail previews on the photo detail page. |
| `picture_representative_icon` | bool | true |  | Show the set-as-album-representative icon on the photo detail page. |
| `picture_sizes_icon` | bool | true |  | Show the available-sizes icon on the photo detail page. |
| `picture_slideshow_icon` | bool | true |  | Show the slideshow icon on the photo detail page. |
| `picture_url_style` | string | `id` |  | URL format for photo links: id or id-file. |
| `piwigo_db_version` | string? | null |  | Branch identifier of the last applied database migration (e.g. 16). |
| `piwigo_installed_version` | string? | null |  | Full Piwigo version string recorded at the time of the last upgrade. |
| `proxy_auth` | string | _(empty string)_ |  | Credentials (user:password) for HTTP proxy authentication. |
| `proxy_server` | string | _(empty string)_ |  | HTTP proxy server URL used for outgoing connections from Piwigo. |
| `question_mark_in_urls` | bool | true |  | Include a ? in generated URLs. Can only be set false when the server translates PATH_INFO (AcceptPathInfo). |
| `quick_search_include_sub_albums` | bool | false |  | Include photos from sub-albums in quick-search results. |
| `random_index_redirect` | array | null | custom accessor | URL mapping for random-index redirects used by shuffle features. |
| `rate` | bool | true |  | Enable the photo rating feature. |
| `rate_anonymous` | bool | true |  | Allow guest (unauthenticated) visitors to rate photos. |
| `rate_items` | array | null | custom accessor | Available rating values displayed in the rating widget. |
| `recent_post_dates` | array | null | custom accessor | Threshold dates used to determine which photos count as recent. |
| `related_albums_display_limit` | int | 20 |  | Maximum number of related albums shown on the photo detail page. |
| `related_albums_maximum_items_to_compute` | int | 1000 |  | Maximum photos considered when computing related albums. |
| `remember_me_length` | int | 5184000 |  | Lifetime in seconds of the remember-me persistent login cookie. |
| `remember_me_name` | string | `pwg_remember` |  | Cookie name used for the remember-me persistent login token. |
| `representative_cache_on_level` | bool | true |  | Cache the album representative photo when permission level changes. |
| `representative_cache_on_subcats` | bool | true |  | Rebuild album representative thumbnails when sub-album content changes. |
| `rss_feed_author` | string | `Piwigo notifier` |  | Author name shown in the gallery RSS feed. |
| `secret_key` | string | _(empty string)_ | required | Random string used to sign CSRF tokens and internal hashes. |
| `send_bcc_mail_webmaster` | bool | false |  | BCC the webmaster address on every outgoing notification email. |
| `send_piwigo_infos` | bool | true |  | Allow Piwigo to send anonymous usage statistics to the Piwigo project. |
| `send_piwigo_infos_last_notice` | string? | null |  | Date the admin was last shown the usage-statistics opt-in notice. |
| `send_piwigo_infos_origin_hash` | string? | null |  | Anonymous installation hash included in usage statistics. |
| `session_gc_probability` | int | 1 |  | Probability weight (out of 100) that a PHP session GC run is triggered per request. |
| `session_length` | int | 3600 |  | PHP session lifetime in seconds (sets cookie_lifetime and gc_maxlifetime). |
| `session_name` | string | `pwg_id` |  | PHP session cookie name used by Piwigo. |
| `session_save_handler` | string | `db` |  | Session storage backend: db (database) or files. |
| `session_use_cookies` | bool | true |  | Store the session ID in a cookie (PHP session.use_cookies). |
| `session_use_ip_address` | bool | true |  | Bind sessions to the client IP address to reduce session-hijacking risk. |
| `session_use_only_cookies` | bool | true |  | Reject session IDs passed in the URL; require cookie only (PHP session.use_only_cookies). |
| `session_use_trans_sid` | bool | false |  | Allow the session ID to be transmitted in the URL query string (PHP session.use_trans_sid). |
| `show_exif` | bool | true |  | Display EXIF metadata on the photo detail page. |
| `show_exif_fields` | array | null | custom accessor | List of EXIF field names to display on the photo detail page. |
| `show_gt` | bool | false |  | Show the Go-to navigation widget on photo detail pages. |
| `show_iptc` | bool | false |  | Display IPTC metadata on the photo detail page. |
| `show_iptc_mapping` | array | null | custom accessor | Mapping of IPTC field codes to human-readable labels for display. |
| `show_newsletter_subscription` | bool | true |  | Show the newsletter subscription link in the gallery menu. |
| `show_php_errors` | int | 30719 |  | PHP error_reporting bitmask for errors displayed during development. |
| `show_php_errors_on_frontend` | bool | true |  | Display PHP errors to visitors on the public gallery (disable in production). |
| `show_piwigo_latest_news` | bool | true |  | Show the latest Piwigo project news on the admin dashboard. |
| `show_queries` | bool | false |  | Append executed SQL queries to the page HTML for debugging. |
| `show_template_in_side_menu` | bool | false |  | Show the active theme name in the gallery sidebar. |
| `show_thumbnail_caption` | bool | true |  | Show the photo title below thumbnails in album index pages. |
| `show_version` | bool | false |  | Display the Piwigo version string in the page footer and emails. |
| `slideshow_period` | int | 4 |  | Default interval in seconds between photos in the slideshow. |
| `slideshow_period_max` | int | 10 |  | Maximum selectable interval in seconds for the slideshow. |
| `slideshow_period_min` | int | 1 |  | Minimum selectable interval in seconds for the slideshow. |
| `slideshow_period_step` | int | 1 |  | Step size in seconds for the slideshow interval selector. |
| `slideshow_repeat` | bool | true |  | Loop the slideshow back to the first photo after the last. |
| `smtp_host` | string | _(empty string)_ |  | SMTP server hostname (and optional port) for outgoing email. |
| `smtp_password` | string | _(empty string)_ | sensitive | SMTP authentication password. |
| `smtp_secure` | string? | null |  | SMTP connection security: null (none), ssl, or tls. |
| `smtp_user` | string | _(empty string)_ |  | SMTP authentication username. |
| `stat_compare_year_displayed` | int | 5 |  | Number of years of photo statistics shown in the comparison chart. |
| `sync_chars_regex` | string | `/^[a-zA-Z0-9-_.]+$/` | custom accessor | Regex that matches valid filename characters during filesystem synchronisation. |
| `sync_exclude_folders` | array | null | custom accessor | Folder names excluded from filesystem synchronisation. |
| `tag_letters_column_number` | int | 4 |  | Number of columns in the alphabetical tag index layout. |
| `tag_url_style` | string | `id-tag` |  | URL format for tag links: id, tag, or id-tag. |
| `tags_default_display_mode` | string | `cloud` |  | Default tag-listing display mode: cloud or letters. |
| `tags_levels` | int | 5 |  | Number of font-size levels used in the tag cloud. |
| `template_combine_files` | bool | true |  | Merge JavaScript/CSS files together at render time to reduce the number of HTTP requests. |
| `template_compile_check` | bool | true |  | Recompile Latte templates when source files change (disable in production). |
| `template_force_compile` | bool | false |  | Always recompile Latte templates on every request. |
| `themes_dir` | string | `themes/` |  | Root-relative path to the directory containing installed themes (compose with CurrentPaths::get()->root for an absolute filesystem path). |
| `tiff_representative_ext` | string | `png` |  | Image extension used when generating a representative for TIFF originals. |
| `top_number` | int | 15 |  | Number of items shown in top ranking lists (most visited, best rated, etc.). |
| `trusted_proxies` | string | _(empty string)_ |  | Comma-separated CIDR list of reverse proxies whose forwarded headers are trusted. |
| `uniqueness_mode` | string | `md5sum` |  | Algorithm used to detect duplicate uploads: md5sum or filename. |
| `update_notify_check_period` | int | 86400 |  | Interval in seconds between automatic checks for Piwigo updates. |
| `update_notify_last_check` | string? | null |  | Timestamp of the last update-availability check. |
| `update_notify_last_notification` | array | null | custom accessor | Serialized {version, notified_on} of the last update-availability notification shown to the admin. Genuine absence before the first check. |
| `update_notify_reminder_period` | int | 604800 |  | Interval in seconds between repeated update reminder notifications. |
| `updates_ignored` | array | null | custom accessor | Serialized {plugins, themes, languages} lists of extension IDs the admin has dismissed from update notifications. |
| `upload_detect_duplicate` | bool | true |  | Check for duplicate photos by checksum when uploading. |
| `upload_dir` | string | `upload/` |  | Root-relative path to the directory where uploaded files are stored (compose with CurrentPaths::get()->root for an absolute filesystem path). |
| `upload_form_all_types` | bool | false |  | Allow uploading any file type, not just images and videos. |
| `upload_form_automatic_rotation` | bool | true |  | Automatically rotate uploaded photos based on their EXIF orientation tag. |
| `upload_form_chunk_size` | int | 500 |  | Chunk size in KB for multi-part file uploads via the upload form. |
| `upload_form_max_file_size` | int | 1000 |  | Maximum file size in MB accepted by the upload form. |
| `url_port` | string | `none` |  | Port included in generated URLs: none, or a port number string. |
| `use_exif` | bool | true |  | Read EXIF metadata from uploaded photos and store it in the database. |
| `use_exif_mapping` | array | null | custom accessor | Mapping of EXIF field names to Piwigo photo attribute names for import. |
| `use_iptc` | bool | false |  | Read IPTC metadata from uploaded photos and store it in the database. |
| `use_iptc_mapping` | array | null | custom accessor | Mapping of IPTC field codes to Piwigo photo attribute names for import. |
| `use_proxy` | bool | false |  | Send outgoing HTTP requests from Piwigo through a proxy server. |
| `user_can_delete_comment` | bool | false |  | Allow a registered user to delete their own comments. |
| `user_can_edit_comment` | bool | false |  | Allow a registered user to edit their own comments. |
| `user_fields` | array | null | custom accessor | Database column mapping for user attributes (username, email, etc.). |
| `webmaster_id` | int | 1 |  | User ID of the designated webmaster account. |
| `week_starts_on` | string | `monday` |  | First day of the week in calendar views: monday or sunday. |
| `ws_max_images_per_page` | int | 500 |  | Maximum number of photos returned per page by the web-service API. |
| `ws_max_users_per_page` | int | 1000 |  | Maximum number of users returned per page by the web-service API. |

<!-- <<<CONFIG-TABLE-END>>> -->
