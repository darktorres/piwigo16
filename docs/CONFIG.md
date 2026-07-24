# Configuration reference

`Piwigo\Config\CurrentConfig` is the single typed source of truth for every runtime
configuration property -- one private static typed property per key, with a named getter and
setter (no generic string-keyed accessor). `Piwigo\Config\ConfigService` (reached via
`CurrentConfigService::get()`) is the DB-backed persistence layer that reads/writes the `config`
table on top of it — see `CurrentConfig.php`'s own class docblock for the full read/write split.
DB connection credentials and the handful of sysadmin-lockable settings live on separate classes
entirely (`Piwigo\Db\DbCredentials`, env-only; `Piwigo\Config\DeploymentPolicy`, file-only) — not
listed below.

The table below is generated directly from `CurrentConfig`'s own properties (reflectively) — do
not hand-edit it. Regenerate after adding, removing, or editing any property:

```
php tools/build-config-docs.php
```

**Flags column:** `required` — `ConfigLoader::validateRequired()` rejects a missing/empty value
at boot; marked via the `#[Required]` attribute. `sensitive` — redacted by
`CurrentConfig::dumpForLog()`; marked via the `#[Sensitive]` attribute.

<!-- <<<CONFIG-TABLE-BEGIN>>> -->

| Property | Type | Default | Flags | Description |
| --- | --- | --- | --- | --- |
| `activateComments` | bool | true |  | Enable or disable user comments on photos gallery-wide. |
| `activityDisplayConnections` | string | `all` |  | Which connection events to show in the activity log: all, admin, or none. |
| `addCacheToStorageChart` | bool | true |  | Include cache files in the storage usage chart on the dashboard. |
| `adminTheme` | string | `clear` |  | Site-wide fallback admin theme (clear, default, or roma) used when a user has no admin_theme preference of their own yet. |
| `albumDescriptionOnAllPages` | bool | false |  | Show the album description on every paginated page, not just the first. |
| `albumMoveDelayBeforeAutoOpening` | int | 3000 |  | Milliseconds to wait before auto-expanding an album drop-target during drag-and-drop. |
| `allowHtmlDescriptions` | bool | true |  | Allow HTML markup in photo and album descriptions. |
| `allowHtmlInMetadata` | bool | false |  | Allow HTML in metadata values extracted from photo files. |
| `allowRandomRepresentative` | bool | false |  | Allow a random photo to represent an album that has no explicit representative set. |
| `allowUserCustomization` | bool | true |  | Let registered users change their own display preferences. |
| `allowUserRegistration` | bool | true |  | Allow new users to self-register from the public gallery. |
| `allowWebServices` | bool | true |  | Enable the Piwigo web-service (API) endpoint. |
| `alternativePemUrl` | string | _(empty string)_ |  | Override URL for the Piwigo Extensions Manager repository. |
| `animatedWebpCompressionQuality` | int | 70 |  | Quality level (1-100) for animated WebP derivative encoding. |
| `antiFloodTime` | int | 60 |  | Minimum seconds between comment posts from the same user to prevent spam. |
| `authKeyDuration` | int | 259200 |  | Lifetime in seconds for single-use authentication keys sent in emails. |
| `authorizeRemembering` | bool | true |  | Allow users to use the remember-me persistent login cookie. |
| `batchManagerImagesPerPageGlobal` | int | 20 |  | Number of photos shown per page in the batch-manager global view. |
| `batchManagerImagesPerPageUnit` | int | 5 |  | Number of photos shown per page in the batch-manager unit view. |
| `browserLanguage` | bool | true |  | Automatically detect and use the visitor browser language preference. |
| `cacheBackend` | string | `file` |  | Cache driver to use: file or redis. |
| `cacheDefaultTtl` | int | 86400 |  | Default cache entry time-to-live in seconds. |
| `cacheNamespace` | string | _(empty string)_ |  | Namespace prefix for all cache keys, useful when sharing a Redis instance. |
| `cacheRedisUrl` | string | `redis://localhost:6379` |  | Redis connection DSN used when cache.backend is redis. |
| `calendarDatefield` | string | `date_creation` |  | Date field used for the calendar view: date_creation or date_available. |
| `calendarShowAny` | bool | true |  | Show an Any link in the calendar so visitors can view photos without a date filter. |
| `calendarShowEmpty` | bool | true |  | Show months and years with no photos in the calendar navigation. |
| `categoryUrlStyle` | string | `id` |  | URL format for album links: id or id-name. |
| `checkUpgradeFeed` | bool | false |  | Check for pending database upgrades on every page load. |
| `checksumComputeBlocksize` | int | 50 |  | Number of photos per block when computing file checksums in batch. |
| `commentSpamMaxLinks` | int | 3 |  | Maximum number of links allowed in a single comment before it is rejected as spam. |
| `commentSpamReject` | bool | true |  | Silently reject comments that exceed the spam link threshold. |
| `commentsAuthorMandatory` | bool | false |  | Require commenters to supply an author name. |
| `commentsEmailMandatory` | bool | false |  | Require commenters to supply an email address. |
| `commentsEnableWebsite` | bool | true |  | Show a website field in the comment form. |
| `commentsForall` | bool | false |  | Allow unauthenticated (guest) visitors to post comments. |
| `commentsOrder` | string | `ASC` |  | Sort order for comment display: ASC (oldest first) or DESC (newest first). |
| `commentsPageNbComments` | int | 10 |  | Number of comments shown per page on the admin comments page. |
| `commentsValidation` | bool | false |  | Require admin approval before newly posted comments appear publicly. |
| `compiledTemplateCacheLanguage` | bool | false |  | Include the active language in the compiled-template cache key. |
| `contentTagCloudItemsNumber` | int | 12 |  | Maximum number of tags shown in the content-area tag cloud. |
| `dashboardActivityNbWeeks` | int | 4 |  | Number of weeks of activity data shown on the admin dashboard. |
| `dashboardCheckForUpdates` | bool | true |  | Check for Piwigo core updates on the admin dashboard. |
| `dataDirChecked` | ?string | null |  | Presence-only marker: once set (to '1'), Template's data-directory writability check is permanently skipped. Genuine absence until the check first passes, matching the gallery_url/last_major_update convention. |
| `dataLocation` | string | `_data/` |  | Relative path from the Piwigo root to the writable data directory. |
| `debugL10n` | bool | false |  | Highlight untranslated strings in the UI for l10n debugging. |
| `debugMail` | bool | false |  | Log all outgoing mail to a file instead of sending. |
| `debugTemplate` | bool | false |  | Add template debugging information to rendered pages. |
| `defaultRedirectMethod` | string | `http` |  | HTTP redirect method Piwigo uses internally: http or html. |
| `defaultUserId` | int | 2 |  | User ID whose settings serve as defaults for new accounts. |
| `derivativeDefaultSize` | string | `medium` |  | Default derivative size name served when no size is specified. |
| `derivativeUrlStyle` | int | 2 |  | Derivative URL format: 0 = auto (static link if already cached, else routed through i.php), 1 = always a static link, 2 = always routed through i.php. |
| `derivativesStripMetadataThreshold` | int | 256000 |  | File size in bytes above which EXIF/IPTC metadata is stripped from derivatives. |
| `dieOnSqlError` | bool | false |  | Halt execution immediately when a database query fails. |
| `displayFromto` | bool | false |  | Show the date range of photos in album and search results headers. |
| `doublePasswordTypeInAdmin` | bool | false |  | Require admins to enter a new password twice when setting it. |
| `emailAdminOnComment` | bool | false |  | Send an email to the administrators when a valid comment is entered. |
| `emailAdminOnCommentDeletion` | bool | false |  | Send an email to the administrators when a comment is deleted. |
| `emailAdminOnCommentEdition` | bool | false |  | Send an email to the administrators when a comment is modified. |
| `emailAdminOnCommentValidation` | bool | true |  | Send an email to the administrators when a comment requires validation. |
| `emailAdminOnNewUser` | string | `none` |  | When to email the webmaster when a new user registers: none, all, or new. |
| `enableCoreUpdate` | bool | true |  | Allow Piwigo core to be updated from the administration panel. |
| `enableExtensionsInstall` | bool | true |  | Allow plugins and themes to be installed from the administration panel. |
| `isFormatsEnabled` | bool | false |  | Enable the multi-format photo feature (original plus additional formats). |
| `enablePlugins` | bool | true |  | Load and activate installed plugins. |
| `enableSynchronization` | bool | true |  | Allow directory-to-database synchronization from the admin panel. |
| `extImagickDir` | string | _(empty string)_ |  | Filesystem path to the ImageMagick binary directory (leave empty to auto-detect). |
| `ffmpegDir` | string | _(empty string)_ |  | Filesystem path to the FFmpeg binary directory (leave empty to auto-detect). |
| `fsQuickCheckLastCheck` | ?string | null |  | Timestamp of the last filesystem quick-check run. |
| `fsQuickCheckPeriod` | int | 86400 |  | Interval in seconds between automatic filesystem quick-checks. |
| `fullTagCloudItemsNumber` | int | 200 |  | Maximum number of tags shown on the full tag-cloud page. |
| `galleryLocked` | bool | false |  | Lock the gallery for maintenance, blocking non-admin access. |
| `galleryTitle` | string | `Piwigo` |  | Title of the gallery shown in the browser tab and page header. |
| `galleryUrl` | ?string | null |  | Public base URL of the gallery (overrides auto-detection when set). |
| `graphicsLibrary` | string | `auto` |  | Image processing backend: auto, gd, imagick, or ext_imagick. |
| `guestAccess` | bool | true |  | Allow unauthenticated (guest) visitors to browse public photos. |
| `guestId` | int | 2 |  | User ID of the built-in guest account used for unauthenticated sessions. |
| `historyAdmin` | bool | false |  | Log page visits by admin users in the history table. |
| `historyAutopurgeBlocksize` | int | 50000 |  | Number of rows deleted per autopurge cycle from the history table. |
| `historyAutopurgeEvery` | int | 1021 |  | Autopurge frequency: delete old history every N page loads (approximately). |
| `historyAutopurgeKeepLines` | int | 1000000 |  | Maximum number of history rows to retain after an autopurge. |
| `historyGuest` | bool | false |  | Log page visits by guest (unauthenticated) users in the history table. |
| `indexCaddieIcon` | bool | true |  | Show the add-to-caddie icon on album index pages. |
| `indexCreatedDateIcon` | bool | true |  | Show the creation-date icon on album index pages. |
| `indexEditIcon` | bool | true |  | Show the quick-edit icon on album index pages (admins only). |
| `indexFlatIcon` | bool | true |  | Show the flat-view icon on album index pages. |
| `indexNewIcon` | bool | true |  | Show the new badge icon on recently added photos in album index pages. |
| `indexPostedDateIcon` | bool | true |  | Show the posted-date icon on album index pages. |
| `indexSearchInSetAction` | string | `results` |  | Behaviour when searching within the current set: results or filter. |
| `indexSearchInSetButton` | bool | false |  | Show the search-within-set button on album index pages. |
| `indexSizesIcon` | bool | true |  | Show the available-sizes icon on album index pages. |
| `indexSlideShowIcon` | bool | true |  | Show the slideshow icon on album index pages. |
| `indexSortOrderInput` | bool | true |  | Display the image order selection list on album index pages. |
| `inheritanceByDefault` | bool | false |  | Apply parent album permissions to newly created sub-albums by default. |
| `insensitiveCaseLogon` | bool | false |  | Allow login with any letter-case variation of the username. |
| `lastMajorUpdate` | ?string | null |  | Timestamp of the last major Piwigo upgrade, used for change detection. |
| `levelSeparator` | string | ` / ` |  | String used to separate album hierarchy levels in breadcrumb trails. |
| `lightAlbumManagerThreshold` | int | 10000 |  | Album count above which the lightweight album manager UI is used. |
| `lightSlideshow` | bool | true |  | Use the lightweight built-in slideshow instead of a plugin-based one. |
| `linkedAlbumSearchLimit` | int | 100 |  | Maximum albums returned when searching for albums to link a photo to. |
| `logConf` | bool | false |  | Enable the application log. |
| `logArchiveDays` | int | 30 |  | Number of days to keep archived log files before deletion. |
| `logDir` | string | `/logs` |  | Directory (relative to the data location) where log files are written. |
| `logLevel` | string | `DEBUG` |  | Minimum log severity to record: DEBUG, INFO, WARNING, or ERROR. |
| `loungeActivateThreshold` | int | 1 |  | Number of photos in the lounge that triggers automatic album creation. |
| `loungeActive` | bool | false |  | Enable the lounge feature (a staging area for uploaded photos). |
| `loungeMaxDuration` | int | 300 |  | Maximum seconds a photo can stay in the lounge before auto-processing. |
| `mailAllowHtml` | bool | true |  | Send emails in HTML format in addition to plain text. |
| `mailSenderEmail` | string | _(empty string)_ |  | From email address used for all outgoing Piwigo emails. |
| `mailSenderName` | string | _(empty string)_ |  | Display name shown as the email sender in outgoing Piwigo emails. |
| `mailTheme` | string | `light` |  | Visual theme for HTML notification emails: light or dark. |
| `maxRequests` | int | 3 |  | Maximum concurrent HTTP requests Piwigo will make to external services. |
| `menubarFilterIcon` | bool | true |  | Show the filter icon in the sidebar menu. |
| `menubarTagCloudContent` | string | `all_or_current` |  | Which tags to show in the sidebar tag cloud: all_or_current or current. |
| `menubarTagCloudItemsNumber` | int | 20 |  | Maximum number of tags shown in the sidebar tag cloud. |
| `metaRef` | bool | true |  | Emit a referrer meta tag allowing search engines to attribute traffic. |
| `mobilTheme` | string | _(empty string)_ |  | Theme name applied automatically when a mobile browser is detected. |
| `nbCategoriesPage` | int | 9999 |  | Maximum albums shown per page in admin album listings. |
| `nbCommentPage` | int | 10 |  | Number of comments per page on the public photo detail page. |
| `nbLogsPage` | int | 300 |  | Number of history entries shown per page in the admin history view. |
| `nbmComplementaryMailContent` | string | _(empty string)_ |  | Extra HTML appended to notification-by-mail digest emails. |
| `nbmDefaultValueUserEnabled` | bool | false |  | Subscribe new users to notification-by-mail digests by default. |
| `nbmListAllEnabledUsersToSend` | bool | false |  | Show all subscribed users in the NBM send UI, not just those with pending notifications. |
| `nbmSendDetailedContent` | bool | true |  | Include photo thumbnails and descriptions in NBM digest emails. |
| `nbmSendHtmlMail` | bool | true |  | Send NBM digest emails in HTML format. |
| `nbmSendMailAs` | string | _(empty string)_ |  | Override the From display name used specifically for NBM emails. |
| `nbmSendRecentPostDates` | bool | true |  | Include recent-post date ranges in NBM digest emails. |
| `nbmTreatmentTimeoutDefault` | int | 20 |  | Default timeout in seconds for a single NBM send-batch execution. |
| `neverDeleteOriginals` | bool | false |  | Prevent deletion of original image files when a photo is removed. |
| `newcatDefaultCommentable` | bool | true |  | Make newly created albums commentable by default. |
| `newcatDefaultPosition` | string | `first` |  | Insert position for new sub-albums: first or last. |
| `newcatDefaultStatus` | string | `public` |  | Default visibility for new albums: public or private. |
| `newcatDefaultVisible` | bool | true |  | Make newly created albums visible by default. |
| `noPhotoYet` | ?string | null |  | Presence-only marker: once set (to 'false'), NoPhotoYetRenderer's first-run banner is permanently suppressed. Genuine absence on a fresh install/reset -- callers use CurrentConfig::has() to detect first-run state, matching the gallery_url/last_major_update convention. |
| `noPhotoYetUrl` | string | `admin.php?page=photos_add` |  | Admin URL linked from the no-photos-yet placeholder shown to admins. |
| `obligatoryUserMailAddress` | bool | false |  | Require an email address for all user registrations. |
| `originalResize` | bool | false |  | Resize uploaded originals that exceed the configured maximum dimensions. |
| `originalResizeMaxheight` | int | 2000 |  | Maximum pixel height for uploaded originals when resize is enabled. |
| `originalResizeMaxwidth` | int | 2000 |  | Maximum pixel width for uploaded originals when resize is enabled. |
| `originalResizeQuality` | int | 95 |  | JPEG quality (1-100) used when resizing uploaded originals. |
| `originalUrlProtection` | string | _(empty string)_ |  | Original-file URL protection mode: empty (none), images, or all. |
| `pageBanner` | string | _(empty string)_ |  | HTML banner content displayed at the top of public gallery pages. |
| `paginatePagesAround` | int | 2 |  | Number of page-number links shown on each side of the current page in pagination. |
| `passwordActivationDuration` | int | 259200 |  | Seconds a password-activation link emailed to new users remains valid. |
| `passwordResetCodeDuration` | int | 300 |  | Seconds a password-reset verification code is valid. |
| `passwordResetDuration` | int | 3600 |  | Seconds a password-reset link emailed to a user remains valid. |
| `pdfViewerFilesizeThreshold` | int | 5 |  | Maximum PDF file size in MB to display inline; larger files show a download link. |
| `pemLanguagesCategory` | int | 8 |  | PEM (Piwigo Extensions Manager) category ID for language packs. |
| `pemPluginsCategory` | int | 12 |  | PEM category ID for plugins. |
| `pemThemesCategory` | int | 10 |  | PEM category ID for themes. |
| `phpExtensionInUrls` | bool | true |  | Include the .php extension in generated picture/category URLs. Works only with Options +MultiViews or URL rewriting active. |
| `pictureCaddieIcon` | bool | true |  | Show the add-to-caddie icon on the photo detail page. |
| `pictureDownloadIcon` | bool | true |  | Show the download icon on the photo detail page. |
| `pictureEditIcon` | bool | true |  | Show the quick-edit icon on the photo detail page (admins only). |
| `pictureFavoriteIcon` | bool | true |  | Show the add-to-favorites icon on the photo detail page. |
| `pictureMenu` | bool | true |  | Show the navigation menu on the photo detail page. |
| `pictureMetadataIcon` | bool | true |  | Show the metadata icon on the photo detail page. |
| `pictureNavigationIcons` | bool | true |  | Show previous/next navigation arrows on the photo detail page. |
| `pictureNavigationThumb` | bool | true |  | Show previous/next thumbnail previews on the photo detail page. |
| `pictureRepresentativeIcon` | bool | true |  | Show the set-as-album-representative icon on the photo detail page. |
| `pictureSizesIcon` | bool | true |  | Show the available-sizes icon on the photo detail page. |
| `pictureSlideShowIcon` | bool | true |  | Show the slideshow icon on the photo detail page. |
| `pictureUrlStyle` | string | `id` |  | URL format for photo links: id or id-file. |
| `piwigoDbVersion` | ?string | null |  | Branch identifier of the last applied database migration (e.g. 16). |
| `piwigoInstalledVersion` | ?string | null |  | Full Piwigo version string recorded at the time of the last upgrade. |
| `proxyAuth` | string | _(empty string)_ |  | Credentials (user:password) for HTTP proxy authentication. |
| `proxyServer` | string | _(empty string)_ |  | HTTP proxy server URL used for outgoing connections from Piwigo. |
| `questionMarkInUrls` | bool | true |  | Include a ? in generated URLs. Can only be set false when the server translates PATH_INFO (AcceptPathInfo). |
| `quickSearchIncludeSubAlbums` | bool | false |  | Include photos from sub-albums in quick-search results. |
| `rateEnabled` | bool | true |  | Enable the photo rating feature. |
| `rateAnonymous` | bool | true |  | Allow guest (unauthenticated) visitors to rate photos. |
| `relatedAlbumsDisplayLimit` | int | 20 |  | Maximum number of related albums shown on the photo detail page. |
| `relatedAlbumsMaximumItemsToCompute` | int | 1000 |  | Maximum photos considered when computing related albums. |
| `rememberMeLength` | int | 5184000 |  | Lifetime in seconds of the remember-me persistent login cookie. |
| `rememberMeName` | string | `pwg_remember` |  | Cookie name used for the remember-me persistent login token. |
| `representativeCacheOnLevel` | bool | true |  | Cache the album representative photo when permission level changes. |
| `representativeCacheOnSubcats` | bool | true |  | Rebuild album representative thumbnails when sub-album content changes. |
| `rssReedAuthor` | string | `Piwigo notifier` |  | Author name shown in the gallery RSS feed. |
| `secretKey` | string | _(empty string)_ | required | Random string used to sign CSRF tokens and internal hashes. |
| `sendBccMailWebmaster` | bool | false |  | BCC the webmaster address on every outgoing notification email. |
| `sendPiwigoInfos` | bool | true |  | Allow Piwigo to send anonymous usage statistics to the Piwigo project. |
| `sendPiwigoInfosLastNotice` | ?string | null |  | Date the admin was last shown the usage-statistics opt-in notice. |
| `sendPiwigoInfosOriginHash` | ?string | null |  | Anonymous installation hash included in usage statistics. |
| `sessionGcProbability` | int | 1 |  | Probability weight (out of 100) that a PHP session GC run is triggered per request. |
| `sessionLength` | int | 3600 |  | PHP session lifetime in seconds (sets cookie_lifetime and gc_maxlifetime). |
| `sessionName` | string | `pwg_id` |  | PHP session cookie name used by Piwigo. |
| `sessionSaveHandler` | string | `db` |  | Session storage backend: db (database) or files. |
| `sessionUseCookies` | bool | true |  | Store the session ID in a cookie (PHP session.use_cookies). |
| `sessionUseIpAddress` | bool | true |  | Bind sessions to the client IP address to reduce session-hijacking risk. |
| `sessionUseOnlyCookies` | bool | true |  | Reject session IDs passed in the URL; require cookie only (PHP session.use_only_cookies). |
| `sessionUseTransSid` | bool | false |  | Allow the session ID to be transmitted in the URL query string (PHP session.use_trans_sid). |
| `showExif` | bool | true |  | Display EXIF metadata on the photo detail page. |
| `showGt` | bool | false |  | Show the Go-to navigation widget on photo detail pages. |
| `showIptc` | bool | false |  | Display IPTC metadata on the photo detail page. |
| `showNewsletterSubscription` | bool | true |  | Show the newsletter subscription link in the gallery menu. |
| `showPiwigoLatestNews` | bool | true |  | Show the latest Piwigo project news on the admin dashboard. |
| `showQueries` | bool | false |  | Append executed SQL queries to the page HTML for debugging. |
| `showTemplateInSideMenu` | bool | false |  | Show the active theme name in the gallery sidebar. |
| `showThumbnailCaption` | bool | true |  | Show the photo title below thumbnails in album index pages. |
| `showVersion` | bool | false |  | Display the Piwigo version string in the page footer and emails. |
| `slideshowPeriod` | int | 4 |  | Default interval in seconds between photos in the slideshow. |
| `slideshowPeriodMax` | int | 10 |  | Maximum selectable interval in seconds for the slideshow. |
| `slideshowPeriodMin` | int | 1 |  | Minimum selectable interval in seconds for the slideshow. |
| `slideshowPeriodStep` | int | 1 |  | Step size in seconds for the slideshow interval selector. |
| `slideshowRepeat` | bool | true |  | Loop the slideshow back to the first photo after the last. |
| `smtpHost` | string | _(empty string)_ |  | SMTP server hostname (and optional port) for outgoing email. |
| `smtpPassword` | string | _(empty string)_ | sensitive | SMTP authentication password. |
| `smtpSecure` | ?string | null |  | SMTP connection security: null (none), ssl, or tls. |
| `smtpUser` | string | _(empty string)_ |  | SMTP authentication username. |
| `statCompareYearDisplayed` | int | 5 |  | Number of years of photo statistics shown in the comparison chart. |
| `tagLettersColumnNumber` | int | 4 |  | Number of columns in the alphabetical tag index layout. |
| `tagUrlStyle` | string | `id-tag` |  | URL format for tag links: id, tag, or id-tag. |
| `tagsDefaultDisplayMode` | string | `cloud` |  | Default tag-listing display mode: cloud or letters. |
| `tagsLevels` | int | 5 |  | Number of font-size levels used in the tag cloud. |
| `templateCombineFiles` | bool | true |  | Merge JavaScript/CSS files together at render time to reduce the number of HTTP requests. |
| `templateCompileCheck` | bool | true |  | Recompile Latte templates when source files change (disable in production). |
| `templateForceCompile` | bool | false |  | Always recompile Latte templates on every request. |
| `themesDir` | string | `themes/` |  | Root-relative path to the directory containing installed themes (compose with CurrentPaths::get()->root for an absolute filesystem path). |
| `tiffRepresentativeExt` | string | `png` |  | Image extension used when generating a representative for TIFF originals. |
| `topNumber` | int | 15 |  | Number of items shown in top ranking lists (most visited, best rated, etc.). |
| `trustedProxies` | string | _(empty string)_ |  | Comma-separated CIDR list of reverse proxies whose forwarded headers are trusted. |
| `uniquenessMode` | string | `md5sum` |  | Algorithm used to detect duplicate uploads: md5sum or filename. |
| `updateNotifyCheckPeriod` | int | 86400 |  | Interval in seconds between automatic checks for Piwigo updates. |
| `updateNotifyLastCheck` | ?string | null |  | Timestamp of the last update-availability check. |
| `updateNotifyReminderPeriod` | int | 604800 |  | Interval in seconds between repeated update reminder notifications. |
| `uploadDetectDuplicate` | bool | true |  | Check for duplicate photos by checksum when uploading. |
| `uploadDir` | string | `upload/` |  | Root-relative path to the directory where uploaded files are stored (compose with CurrentPaths::get()->root for an absolute filesystem path). |
| `uploadFormAllTypes` | bool | false |  | Allow uploading any file type, not just images and videos. |
| `uploadFormAutomaticRotation` | bool | true |  | Automatically rotate uploaded photos based on their EXIF orientation tag. |
| `uploadFormChunkSize` | int | 500 |  | Chunk size in KB for multi-part file uploads via the upload form. |
| `uploadFormMaxFileSize` | int | 1000 |  | Maximum file size in MB accepted by the upload form. |
| `urlPort` | string | `none` |  | Port included in generated URLs: none, or a port number string. |
| `useExif` | bool | true |  | Read EXIF metadata from uploaded photos and store it in the database. |
| `useIptc` | bool | false |  | Read IPTC metadata from uploaded photos and store it in the database. |
| `useProxy` | bool | false |  | Send outgoing HTTP requests from Piwigo through a proxy server. |
| `userCanDeleteComment` | bool | false |  | Allow a registered user to delete their own comments. |
| `userCanEditComment` | bool | false |  | Allow a registered user to edit their own comments. |
| `webmasterId` | int | 1 |  | User ID of the designated webmaster account. |
| `weekStartsOn` | string | `monday` |  | First day of the week in calendar views: monday or sunday. |
| `wsMaxImagesPerPage` | int | 500 |  | Maximum number of photos returned per page by the web-service API. |
| `wsMaxUsersPerPage` | int | 1000 |  | Maximum number of users returned per page by the web-service API. |
| `apiKeyDuration` | array | `["30","90","180","365","custom"]` |  | Lifetime configuration for API keys (array with count and unit). |
| `apiKeyForbiddenMethods` | array | `["pwg.users.generatePasswordLink","pwg.users.getAuthKey","pwg.users.setMainUser","pwg.users.setInfo","pwg.plugins.performAction","pwg.themes.performAction","pwg.extensions.ignoreUpdate","pwg.extensions.update"]` |  | Web-service method names that API-key callers are not allowed to invoke. |
| `availablePermissionLevels` | array | `[0,1,2,4,8]` |  | Ordered list of numeric permission levels visible in the UI. |
| `c13yIgnore` | ?string | null |  | Serialized {version, list} of integrity-check anomalies the admin has acknowledged/ignored (Admin/Integrity CheckIntegrity.php). |
| `cacheSizes` | ?array | null |  | Serialized [name, value] rows of cache-directory sizes computed by the maintenance page, cached to avoid recomputing on every dashboard/ maintenance load. |
| `chmodValue` | ?int | null |  | Filesystem permission bits applied to newly created directories -- 0777 under Apache, 0755 otherwise, unless explicitly overridden. Null means "not explicitly overridden": the SAPI-dependent default below applies. |
| `defaultFiltersViews` | array | `{"words":{"access":"everybody","default":true},"tags":{"access":"everybody","default":false},"post_date":{"access":"everybody","default":false},"creation_date":{"access":"everybody","default":true},"album":{"access":"everybody","default":true},"author":{"access":"everybody","default":false},"added_by":{"access":"everybody","default":false},"file_type":{"access":"everybody","default":false},"ratio":{"access":"everybody","default":false},"rating":{"access":"everybody","default":false},"file_size":{"access":"everybody","default":false},"height":{"access":"everybody","default":false},"width":{"access":"everybody","default":false},"expert":{"access":"everybody","default":false}}` |  | Factory-default search-filter definitions (access level + default-on state per filter key); seeds the 'filters_views' DB row on first use and drives the search filters admin page. |
| `derivatives` | ?string | null |  | Serialized ImageStdParams derivative-size definitions saved by the photo sizes admin page. Absent on a fresh install until the admin saves the sizes form once. |
| `disabledDerivatives` | array|string | `[]` |  | Serialized list of derivative type keys the admin has disabled from generation via the photo sizes admin page. |
| `emptyLoungeRunning` | ?string | null |  | Transient "<execId>-<startTime>" marker set while ImageService::emptyLounge() is running, used to detect a concurrent/ stalled run. Absent when no run is in progress. |
| `extentsForTemplates` | array | `[]` |  | Comma-separated list of template file extensions recognised by the theme engine. |
| `fileExtensions` | array | `["jpg","jpeg","png","gif","webp","tiff","tif","mpg","zip","avi","mp3","ogg","pdf","svg","heic"]` |  | Full list of file extensions Piwigo will manage (pictures plus extras). |
| `filterPages` | array | `{"default":{"used":true,"cancel":false,"add_notes":false},"index":{"add_notes":true},"tags":{"add_notes":true},"search":{"add_notes":true},"comments":{"add_notes":true},"admin":{"used":false},"feed":{"used":false},"notification":{"used":false},"nbm":{"used":false},"popuphelp":{"used":false},"profile":{"used":false},"ws":{"used":false},"identification":{"cancel":true},"install":{"cancel":true},"password":{"cancel":true},"register":{"cancel":true}}` |  | Pages on which the tag/date filter UI is displayed. |
| `filtersViews` | ?array | null |  | Admin-customized search-filter definitions, lazily seeded from 'default_filters_views' the first time the search filters admin page is saved. Absent (falls back to defaultFiltersViews()) until then. |
| `formatExtensions` | array | `["cr2","tif","tiff","nef","dng","ai","psd"]` |  | File extensions recognised as additional formats for multi-format photos. |
| `headerNotes` | array | `[]` |  | Additional HTML messages shown in the gallery header for all users. |
| `historySectionsCache` | ?array | null |  | Cached list of the history.section enum column values, refreshed when a plugin adds a new section. |
| `links` | array | `[]` |  | Additional navigation links shown in the gallery menu. |
| `metadataKeywordSeparatorRegex` | string | `/[.,;]/` |  | PCRE regex used to split keyword strings extracted from EXIF/IPTC metadata. |
| `nbmMaxTreatmentTimeoutPercent` | float | 0.8 |  | Fraction of the PHP max_execution_time budget NBM may consume per batch. |
| `orderBy` | string | `ORDER BY date_available DESC, file ASC, id ASC` |  |  |
| `orderByCustom` | ?string | null |  | Admin-defined custom sort order that overrides order_by when set -- a raw "ORDER BY ..." SQL fragment string, same real shape as order_by itself (see its own docblock). |
| `orderByInsideCategory` | string | `ORDER BY date_available DESC, file ASC, id ASC` |  | Active sort order applied within album listings -- a raw "ORDER BY ..." SQL fragment string (see order_by's own docblock). |
| `orderByInsideCategoryCustom` | ?string | null |  | Admin-defined custom sort order that overrides order_by_inside_category when set (see order_by's own docblock). |
| `pictureExtensions` | array | `["jpg","jpeg","png","gif","webp"]` |  | File extensions recognised as displayable photo types. |
| `pictureInformations` | array | `[]` |  | Map of metadata field names to visibility booleans on the photo detail page. |
| `randomIndexRedirect` | array | `[]` |  | URL mapping for random-index redirects used by shuffle features. |
| `rateItems` | array | `[0,1,2,3,4,5]` |  | Available rating values displayed in the rating widget. |
| `recentPostDates` | ?Piwigo\Config\NotificationConfig | null |  | Threshold dates used to determine which photos count as recent. Null means "not explicitly set": the getter lazily builds the default VO below (a property default can't call `new` directly). |
| `showExifFields` | array | `["Make","Model","DateTimeOriginal","COMPUTED;ApertureFNumber"]` |  | List of EXIF field names to display on the photo detail page. |
| `showIptcMapping` | array | `{"iptc_keywords":"2#025","iptc_caption_writer":"2#122","iptc_byline_title":"2#085","iptc_caption":"2#120"}` |  | Mapping of IPTC field codes to human-readable labels for display. |
| `syncCharsRegex` | string | `/^[a-zA-Z0-9-_.]+$/` |  | Regex that matches valid filename characters during filesystem synchronisation. |
| `syncExcludeFolders` | array | `[]` |  | Folder names excluded from filesystem synchronisation. |
| `updateNotifyLastNotification` | ?array | null |  | Serialized {version, notified_on} of the last update-availability notification shown to the admin. Genuine absence before the first check. |
| `updatesIgnored` | array | `{"plugins":[],"themes":[],"languages":[]}` |  | Serialized {plugins, themes, languages} lists of extension IDs the admin has dismissed from update notifications. |
| `useExifMapping` | array | `{"date_creation":"DateTimeOriginal"}` |  | Mapping of EXIF field names to Piwigo photo attribute names for import. |
| `useIptcMapping` | array | `{"keywords":"2#025","date_creation":"2#055","author":"2#122","name":"2#005","comment":"2#120"}` |  | Mapping of IPTC field codes to Piwigo photo attribute names for import. |
| `userFields` | array | `{"id":"id","username":"username","password":"password","email":"mail_address"}` |  | Simplified from the reference's typed UserFieldsMap return: that VO lives under the not-yet-existing Piwigo\Users namespace (P16) -- returns the same column-name mapping as a plain array instead. |

<!-- <<<CONFIG-TABLE-END>>> -->
