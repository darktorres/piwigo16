# Legacy File Includes in `src/`

All occurrences of `PHPWG_ROOT_PATH` and `.php` file-path string literals inside `src/Piwigo/`.
Snapshot: 2026-05-06. Excludes `vendor/`, `tests/`, `tools/`.

---

## Summary by category

| Category | Occurrences | Deletion gate |
|---|---|---|
| `include/page_header.php` + `include/page_tail.php` | ~30 pairs | #23 Latte (page lifecycle) |
| `admin/include/functions.php` loader | ~25 | Admin function call-site sweep |
| `admin/include/functions_*.inc.php` loaders | ~10 | Same |
| `include/functions_search.inc.php` | 4 | Search call-site sweep |
| `include/functions_*.php` misc | ~8 | Per-file call-site sweep |
| `include/page_header.php` in `Util.php` (fatal_error path) | 2 | #23 / pre-boot refactor |
| Plugin/theme dynamic includes | ~15 | 🔒 Permanent (dynamic, runtime-only) |
| Filesystem path construction (not includes) | ~80 | Not an include — fine as-is |

---

## `include/page_header.php` and `include/page_tail.php`

Gated on **#23 Latte** (page lifecycle migration).

| File | Lines |
|---|---|
| `src/Piwigo/Controller/AboutController.php` | 48, 52 |
| `src/Piwigo/Controller/CommentsController.php` | 370, 377 |
| `src/Piwigo/Controller/GalleryController.php` | 351, 358 |
| `src/Piwigo/Controller/IdentificationController.php` | 134, 138 |
| `src/Piwigo/Controller/NotificationController.php` | 62, 66 |
| `src/Piwigo/Controller/NbmController.php` | 55, 58 |
| `src/Piwigo/Controller/PasswordController.php` | 165, 169 |
| `src/Piwigo/Controller/PictureController.php` | 590, 602 |
| `src/Piwigo/Controller/PopuphelpController.php` | 47, 49 |
| `src/Piwigo/Controller/ProfileController.php` | 115, 147 |
| `src/Piwigo/Controller/RegisterController.php` | 129, 133 |
| `src/Piwigo/Controller/TagsController.php` | 114, 118 |
| `src/Piwigo/Controller/Admin/AdminController.php` | 373, 381 |
| `src/Piwigo/Controller/Admin/MiscController.php` | 433, 456 |
| `src/Piwigo/Core/Util.php` | 128, 134 |

---

## `admin/include/functions.php` (admin helper loader)

Loaded at the top of almost every admin sub-controller method. Deletion gate: admin function call-site sweep (part of FREE-FUNCTIONS.md Phase B).

| File | Lines |
|---|---|
| `src/Piwigo/Admin/Plugins.php` | 226 |
| `src/Piwigo/themes/admin.php` | 176 |
| `src/Piwigo/Controller/Admin/AdminController.php` | 51 |
| `src/Piwigo/Controller/Admin/AlbumController.php` | 115, 278, 421, 578, 762, 843, 990 |
| `src/Piwigo/Controller/Admin/BatchManagerController.php` | 58, 582, 924 |
| `src/Piwigo/Controller/Admin/ConfigurationController.php` | 40 |
| `src/Piwigo/Controller/Admin/ExtensionsController.php` | 409, 733, 1135 |
| `src/Piwigo/Controller/Admin/GroupsController.php` | 40, 130 |
| `src/Piwigo/Controller/Admin/MaintenanceController.php` | 80, 404, 810, 920, 980, 1093 |
| `src/Piwigo/Controller/Admin/MiscController.php` | 80, 238 (see also functions_permalinks), 310, 390, 471, 800, 832 |
| `src/Piwigo/Controller/Admin/PhotoController.php` | 117, 520, 564 |
| `src/Piwigo/Controller/Admin/UsersController.php` | 51, 251, 331 |
| `src/Piwigo/Controller/InstallController.php` | 58 |
| `src/Piwigo/Controller/NbmController.php` | 21 |
| `src/Piwigo/Controller/PictureController.php` | 182 |
| `src/Piwigo/Controller/UpgradeController.php` | 41 |
| `src/Piwigo/Core/Util.php` | 645, 734 |
| `src/Piwigo/Users/UserService.php` | 494 |
| `src/Piwigo/Ws/Method/CategoriesEndpoints.php` | 23 |
| `src/Piwigo/Ws/Method/GeneralEndpoints.php` | 28, 464 |
| `src/Piwigo/Ws/Method/GroupsEndpoints.php` | 15 |
| `src/Piwigo/Ws/Method/ImagesEndpoints.php` | 25 |
| `src/Piwigo/Ws/Method/PermissionsEndpoints.php` | 13 |
| `src/Piwigo/Ws/Method/TagsEndpoints.php` | 16 |
| `src/Piwigo/Ws/Method/UsersEndpoints.php` | 24 |

---

## Other `admin/include/` loaders

| File | Line | Loads |
|---|---|---|
| `src/Piwigo/Controller/Admin/AdminController.php` | 52 | `admin/include/functions_plugins.inc.php` |
| `src/Piwigo/Controller/Admin/AdminController.php` | 53 | `admin/include/add_core_tabs.inc.php` |
| `src/Piwigo/Controller/Admin/BatchManagerController.php` | 432 | `include/functions_search.inc.php` |
| `src/Piwigo/Controller/Admin/ConfigurationController.php` | 41 | `admin/include/functions_upload.inc.php` |
| `src/Piwigo/Controller/Admin/MaintenanceController.php` | 811, 921 | `admin/include/functions_history.inc.php` |
| `src/Piwigo/Controller/Admin/MiscController.php` | 81 | `admin/include/functions_notification_by_mail.inc.php` |
| `src/Piwigo/Controller/Admin/MiscController.php` | 238 | `admin/include/functions_permalinks.php` |
| `src/Piwigo/Controller/Admin/MiscController.php` | 1061 | `include/profile_functions.php` |
| `src/Piwigo/Controller/Admin/PhotoController.php` | 521, 565 | `admin/include/functions_upload.inc.php` |
| `src/Piwigo/Controller/NbmController.php` | 22 | `admin/include/functions_notification_by_mail.inc.php` |
| `src/Piwigo/Core/Util.php` | 536, 540 | `admin/include/functions_history.inc.php` |
| `src/Piwigo/Ws/Method/GeneralEndpoints.php` | 465 | `admin/include/functions_history.inc.php` |
| `src/Piwigo/Ws/Method/ImagesEndpoints.php` | 26 | `admin/include/functions_upload.inc.php` |
| `src/Piwigo/Ws/Method/ImagesEndpoints.php` | 27 | `admin/include/functions_metadata.php` |
| `src/Piwigo/Controller/ExtensionsController.php` | 1051 | `include/functions.inc.php` |

---

## `include/functions_search.inc.php`

| File | Line |
|---|---|
| `src/Piwigo/Controller/Admin/BatchManagerController.php` | 432 |
| `src/Piwigo/Controller/SearchController.php` | 20 |
| `src/Piwigo/Search/SearchFilterRenderer.php` | 52 |
| `src/Piwigo/Section/SectionInitializer.php` | 291 |
| `src/Piwigo/Ws/Method/ImagesEndpoints.php` | 28 |

---

## Other `include/` file loaders

| File | Line | Loads |
|---|---|---|
| `src/Piwigo/Controller/FeedController.php` | 22 | `include/feed_functions.php` |
| `src/Piwigo/Controller/ImageDerivativeController.php` | 34 | `include/image_derivative_functions.php` |
| `src/Piwigo/Controller/ImageDerivativeController.php` | 35 | `include/derivative_params.inc.php` |
| `src/Piwigo/Controller/ImageDerivativeController.php` | 36 | `include/derivative_std_params.inc.php` |
| `src/Piwigo/Controller/InstallController.php` | 57 | `include/constants.php` |
| `src/Piwigo/Controller/InstallController.php` | 123 | `include/dblayer/functions_mysqli.inc.php` *(install only)* |
| `src/Piwigo/Controller/InstallController.php` | 124 | `admin/include/functions_install.inc.php` *(install only)* |
| `src/Piwigo/Controller/InstallController.php` | 125 | `admin/include/functions_upgrade.php` *(install only)* |
| `src/Piwigo/Controller/PasswordController.php` | 25 | `include/password_functions.php` |
| `src/Piwigo/Controller/PictureController.php` | 36 | `include/picture_functions.php` |
| `src/Piwigo/Controller/ProfileController.php` | 27 | `include/profile_functions.php` |
| `src/Piwigo/Controller/UpgradeController.php` | 36 | `include/constants.php` *(upgrade only)* |
| `src/Piwigo/Controller/UpgradeController.php` | 40 | `include/functions.inc.php` *(upgrade only)* |
| `src/Piwigo/Controller/UpgradeController.php` | 97 | `admin/include/functions_upgrade.php` *(upgrade only)* |
| `src/Piwigo/Controller/UpgradeController.php` | 98 | `include/dblayer/functions_mysqli.inc.php` *(upgrade only)* |
| `src/Piwigo/Controller/WsController.php` | 41 | `include/ws_default_methods.php` |
| `src/Piwigo/Http/Middleware/FilterMiddleware.php` | 153 | `include/functions_filter.inc.php` |
| `src/Piwigo/Section/SectionInitializer.php` | 413 | `include/functions_calendar.inc.php` |
| `src/Piwigo/Search/SearchService.php` | 897–899 | `include/inflectors/*.php` |
| `src/Piwigo/Users/UserBootstrap.php` | 112 | `include/ws_functions/pwg.php` |

---

## 🔒 Plugin / theme dynamic includes — permanent

These load runtime-determined files and cannot be statically resolved. Not candidates for removal.

| File | Line | Pattern |
|---|---|---|
| `src/Piwigo/Admin/Plugins.php` | 267, 277 | `$path . '/main.inc.php'` |
| `src/Piwigo/themes/admin.php` | 45 | `PHPWG_THEMES_PATH . $themeId . '/admin/maintain.inc.php'` |
| `src/Piwigo/themes/admin.php` | 268, 279 | `$path . '/themeconf.inc.php'` |
| `src/Piwigo/themes/admin.php` | 333 | `$path . '/admin/admin.inc.php'` |
| `src/Piwigo/Controller/Admin/ExtensionsController.php` | 745 | `PHPWG_THEMES_PATH . $theme . '/admin/admin.inc.php'` |
| `src/Piwigo/Core/Util.php` | 174 | `Config::themesDir() . '/' . $themeId . '/themeconf.inc.php'` |
| `src/Piwigo/Plugin/PluginService.php` | 29, 41 | `PHPWG_PLUGINS_PATH . $pluginId . '/main.inc.php'` |
| `src/Piwigo/Plugin/PluginService.php` | 70 | `PHPWG_PLUGINS_PATH . $pluginId . '/maintain.class.php'` |
| `src/Piwigo/Template/Template.php` | 1191 | `$dir . '/themeconf.inc.php'` |

---

## Filesystem path construction — not includes

These use `PHPWG_ROOT_PATH` to build filesystem paths (not `require`/`include`). Fine as-is — no migration needed.

| File | Lines | Purpose |
|---|---|---|
| `src/Piwigo/Admin/AdminService.php` | 404 | Cache file path |
| `src/Piwigo/Admin/Config/WatermarkProcessor.php` | 33, 39, 42, 56 | Watermark directory paths |
| `src/Piwigo/Admin/Image/ImageAdminService.php` | 213, 242, 245, 246, 362 | Derivative cache paths, md5 |
| `src/Piwigo/Admin/Languages.php` | 89, 112, 118, 250, 282, 335 | Language directory operations |
| `src/Piwigo/Admin/Metadata/MetadataAdminService.php` | 114, 171 | Image file paths |
| `src/Piwigo/Admin/Updates.php` | 463–610 | Update extraction, trash, upgrade redirect |
| `src/Piwigo/Admin/Upload/UploadService.php` | 114, 159, 194, 269, 270, 562 | Upload directory paths |
| `src/Piwigo/Auth/CookieService.php` | 44, 45 | Cookie path prefix |
| `src/Piwigo/Bootstrap/Container.php` | 16 | DI container config path |
| `src/Piwigo/Cache/CacheFactory.php` | 45, 46 | Cache directory |
| `src/Piwigo/Cache/PersistentFileCache.php` | 28 | Cache directory |
| `src/Piwigo/Controller/Admin/ConfigurationController.php` | 414–419, 484, 485 | Watermark glob, config file paths |
| `src/Piwigo/Controller/Admin/ExtensionsController.php` | 597, 682, 881, 1147 | Themes/language directory paths |
| `src/Piwigo/Controller/Admin/MaintenanceController.php` | 1325 | Derivative directory |
| `src/Piwigo/Controller/Admin/UsersController.php` | 506, 507 | Config file path check |
| `src/Piwigo/Controller/Admin/AdminController.php` | 42 | Admin Template constructor |
| `src/Piwigo/Controller/FeedController.php` | 117 | Temp file path |
| `src/Piwigo/Controller/ImageDerivativeController.php` | 195 | Watermark image path |
| `src/Piwigo/Controller/InstallController.php` | 116, 163, 181, 182, 199 | Template, SQL files, galleries path |
| `src/Piwigo/Controller/NbmController.php` | 26 | Local language load |
| `src/Piwigo/Controller/UpgradeController.php` | 38, 104 | UPGRADES_PATH define, Template |
| `src/Piwigo/Core/InstallSentinel.php` | 65 | Stamp file path |
| `src/Piwigo/Core/StringUtil.php` | 367 | Relative-to-absolute path |
| `src/Piwigo/Core/Util.php` | 109, 110, 113 | Local lang + Template in fatal_error |
| `src/Piwigo/Html/HtmlService.php` | 50, 116 | URL construction (root-relative) |
| `src/Piwigo/Image/DerivativeImage.php` | 195, 220 | Derivative file paths |
| `src/Piwigo/Image/DerivativeService.php` | 41, 94 | Source/watermark image paths |
| `src/Piwigo/Image/SrcImage.php` | 55, 61, 107 | Image file paths |
| `src/Piwigo/Job/MessengerFactory.php` | 47 | Messenger config path |
| `src/Piwigo/Lang/LangService.php` | 69, 91 | Language file paths |
| `src/Piwigo/Mail/MailService.php` | 147, 185, 694 | Template, lang, tmp paths |
| `src/Piwigo/Migrations/MigrationRunner.php` | 18, 35 | Migrations config path |
| `src/Piwigo/Page/NoPhotoYetRenderer.php` | 35 | Template constructor |
| `src/Piwigo/Section/SectionInitializer.php` | 53, 56 | `$page['root_path']` construction |
| `src/Piwigo/Template/FileCombiner.php` | 34–279 | CSS/JS combined file paths |
| `src/Piwigo/Template/ScriptLoader.php` | 308 | Vite manifest path |
| `src/Piwigo/Template/Template.php` | 79, 97, 1157, 1163 | Data dir, compile dir, theme file checks |
| `src/Piwigo/Url/UrlService.php` | 20 | Root URL from path |
| `src/Piwigo/Ws/Method/ImagesEndpoints.php` | 875, 876 | Upload chunk paths |
