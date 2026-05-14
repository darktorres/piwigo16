# Compatibility Inventory

Shims, bridges, and backward-compatibility mechanisms in the 16.x rewrite.
Last deep-verified: 2026-05-14.

**Policy (2026-05-14):** All plugins will be rewritten as part of the platform migration.
External plugin compatibility is NOT a blocker. Only in-tree `src/` callers block removal.

---

## 1. Wave A Reference Bridges

These classes maintain a bidirectional PHP-reference link between a typed singleton and a `$GLOBALS` key so that legacy procedural code (plugins, un-migrated `include/` files) continues to work without modification. Each bridge is wired in `Kernel::boot()` via `attachGlobals()`.

### 1.1 `PageState` — `$GLOBALS['page']`

**File:** `src/Piwigo/Core/PageState.php`

`PageState::attachGlobals()` previously read pre-boot values from `$GLOBALS['page']` and wired 13 PHP reference bridges (errors, warnings, messages, infos, body_classes, body_data, …) so that legacy `$page['errors'][] = '…'` writes propagated automatically to the typed singleton and vice versa.

**Removal condition:** All reads/writes of `$GLOBALS['page']` in `src/` migrated to typed equivalents (`PageState::current()->…` for page state, or a typed request-context object for section/items/category/etc.).

**Status: ❌ NOT MET.** The reference bridge was removed (2026-05-14) and all 13 bridged-key callers were migrated. But `$GLOBALS['page']` is still accessed in **55 files** for ad-hoc request-context keys (`section`, `items`, `category`, `image_id`, `start`, `nb_image_page`, `title`, `root_path`, etc.). These never had a typed home and need a `PageContext` / `RequestContext` object to complete the migration. Bridge removal progress:

| Bridged key | Callers migrated |
|---|---|
| `errors` | IdentificationController, RegisterController, GeneralEndpoints, SizesProcessor, ConfigurationController, ProfileController, MaintenanceController, MiscController (profile), Updates, ExtensionsController |
| `body_classes` / `body_data` | SectionInitializer (write), GalleryController (read) |
| `keyed_errors` | IdentificationController, RegisterController |
| `auth_key_invalid` | AuthService (write) → `PageState::current()->authKeyInvalid`; CommonBootstrap already read from PageState |
| `execution_uuid` | CommonBootstrap (write before boot) → `PageState::current()->executionUuid`; Logger.php reads from PageState |
| `notify_api_key_expiration` | AuthService (write) + CommonBootstrap (read + reset) → both through `PageState::current()->notifyApiKeyExpiration` |

Pre-boot `$GLOBALS['page']` initialisation in CommonBootstrap cleaned up (was setting bridged keys that `attachGlobals()` immediately wiped).

Additional cleanup from second-pass verification:
- `AuthService`: dead `$page = &$GLOBALS['page']` alias removed; `auth_key_id` ad-hoc key write fixed to use `$GLOBALS['page']` directly.
- `PageState::$upgradeStart`: dead property removed (never written post-include/ removal, never read).
- `PageState::$countQueries` / `$queriesTime`: retained — still read by `PageTailRenderer` debug overlay and `Util.php`, but never written (always 0 — pre-existing gap since the `include/` files that incremented them were removed).
- `KernelBootTest`: three tests that validated the bridge round-trip and pre-boot preservation were removed; replaced with tests for actual current behaviour.

**Remaining work:** 55 files still read/write `$GLOBALS['page']` for ad-hoc request-context keys. These need a typed `PageContext` / `RequestContext` object — at which point `$GLOBALS['page']` can be removed entirely and `PageState::attachGlobals()` can be deleted.

---

### 1.2 `Lang` / `Translator` — `$GLOBALS['lang']`

**Files:** `src/Piwigo/Core/Lang.php`, `src/Piwigo/Lang/Translator.php`

`Lang::attachGlobals()` wires `$GLOBALS['lang']` as a PHP reference to `Lang::$data`.
`Translator::mirrorToGlobal()` (called on every `load()`) additionally copies every PO translation into `$GLOBALS['lang']` so legacy `$lang['key']` reads stay current.
`Translator` also rebuilds `$lang['day']` and `$lang['month']` sub-arrays for callers like `Lang::day()`, `admin/stats.php`.

**Removal condition:** All direct `$GLOBALS['lang']` reads in `src/` migrated to `Lang::t()` / `Lang::day()` / `Lang::month()`.

**Status: ❌ NOT MET.** Verified 2026-05-14. Active in-tree callers:

```
src/Piwigo/Calendar/CalendarWeekly.php:27
src/Piwigo/Calendar/CalendarMonthly.php:36, 264, 337, 378
src/Piwigo/Controller/Admin/PhotoController.php:542, 550–551
src/Piwigo/Controller/Admin/MaintenanceController.php:998–999
src/Piwigo/Controller/Admin/ConfigurationController.php:310–312
src/Piwigo/Controller/Admin/MiscController.php:725–726
src/Piwigo/Notification/NotificationService.php:394–395
src/Piwigo/Controller/PictureController.php:101, 475–476
```

Note: `CommonBootstrap.php:91` initialises `$GLOBALS['lang'] = []` before boot — this is the bootstrap side, not a legacy read.

---

### 1.3 `TemplateRegistry` — `$GLOBALS['template']`

**File:** `src/Piwigo/Template/TemplateRegistry.php`

`TemplateRegistry::set(Template $t)` previously set the typed singleton AND wrote `$GLOBALS['template'] = $t` so that legacy `$GLOBALS['template']` reads would see the same instance.

**Removal condition:** All direct `$GLOBALS['template']` reads/writes in `src/` migrated to `TemplateRegistry::current()` / `TemplateRegistry::set()`.

**Status: ✅ REMOVED (2026-05-14).** The `$GLOBALS['template']` write was removed from `TemplateRegistry::set()`. Follow-up fixes in the same session:
- `AdminController.php`: redundant `$GLOBALS['template'] = $adminTpl` after `TemplateRegistry::set()` — removed.
- `CommonBootstrap.php`: same redundant write — removed.
- `Util::getThemeconf()`: read `$GLOBALS['template']` directly — migrated to `TemplateRegistry::current()`.

No remaining `$GLOBALS['template']` reads or writes in `src/` (verified 2026-05-14).

---

### 1.4 `LoadedPluginRegistry` — `$GLOBALS['pwg_loaded_plugins']`

**File:** `src/Piwigo/Plugins/LoadedPluginRegistry.php`

`LoadedPluginRegistry::init()` wires `$GLOBALS['pwg_loaded_plugins']` as a reference to `self::$plugins`. Legacy plugin code that reads the global directly sees live data.

**Removal condition:** All in-tree reads of `$GLOBALS['pwg_loaded_plugins']` migrated to `LoadedPluginRegistry::all/get()`.

**Status: ❌ NOT MET — but simplified (2026-05-14).** All plugins removed from `plugins/` (only a redirect stub remains). `PluginService::loadPlugin()` finds no `main.inc.php` files; `LoadedPluginRegistry::all()` always returns `[]`. Three controllers still read the global directly:

```
src/Piwigo/Controller/Admin/MiscController.php:539–540     (count — always 0)
src/Piwigo/Controller/Admin/ExtensionsController.php:442–444  (active check — always false)
src/Piwigo/Controller/Admin/BatchManagerController.php:976–977  (list — always [])
```

With no plugins ever loading, the entire plugin stack (`PluginService::loadPlugins()`, `LoadedPluginRegistry::init()`, the bridge, and these three reads) could be removed together rather than migrated piecemeal.

---

### 1.5 `EventDispatcher` — `$GLOBALS['pwg_event_handlers']`

**File:** `src/Piwigo/Plugins/EventDispatcher.php`

`EventDispatcher::init()` previously wired `$GLOBALS['pwg_event_handlers']` as a reference to `self::$handlers`.

**Removal condition:** No in-tree `src/` code writes `$pwg_event_handlers` directly outside `EventDispatcher.php`.

**Status: ✅ REMOVED.** First pass removed the bridge from `EventDispatcher::init()`. Third-pass census found it was also present in `EventDispatcher::reset()` — removed there too. No remaining `$pwg_event_handlers` references in production `src/` (tools/phpstan-bootstrap.php and NoGlobalInSrcRule.php are non-production).

---

### 1.6 `CurrentUser` — `$GLOBALS['user']`

**File:** `src/Piwigo/Users/CurrentUser.php`

Not a PHP-reference bridge (the global is a plain array and can't be cleanly reference-bridged to a typed object), but maintains bidirectional sync via explicit methods:

- `attachGlobals()` — builds the typed `User` from `$GLOBALS['user']` at boot.
- `setLanguage()` — updates both `User::$language` and `$GLOBALS['user']['language']`.
- `setRawAttributes()` — replaces both the typed entity and `$GLOBALS['user']` wholesale (used by NBM when sending as a different recipient).

**Removal condition:** All direct `$GLOBALS['user']` reads in `src/` migrated to `CurrentUser::get()->…`.

**Status: ❌ NOT MET.** The most widely un-migrated global. Verified 2026-05-14. Active callers (43 lines across 30+ files):

```
src/Piwigo/Bootstrap/CommonBootstrap.php:90, 233           (init + guest username)
src/Piwigo/Http/Middleware/FilterMiddleware.php:51          (filter state)
src/Piwigo/Users/AuthService.php:289                        (login flow)
src/Piwigo/Users/PreferencesService.php:86, 94, 120, 148   (pref read/write)
src/Piwigo/Users/ProfileService.php:184                     (profile load)
src/Piwigo/Lang/LangService.php:90                          (user language)
src/Piwigo/Comment/CommentService.php:248, 285, 397–398     (comment ops)
src/Piwigo/Tag/TagService.php:30                            (tag cloud)
src/Piwigo/Page/NoPhotoYetRenderer.php:35                   (guest check)
src/Piwigo/Core/Util.php:502                                (log)
src/Piwigo/Admin/Users/UserAdminService.php:183–184, 191   (cache invalidate)
src/Piwigo/Admin/Notification/NotificationAdminService.php:78  (NBM context)
src/Piwigo/Ws/Method/ImagesEndpoints.php:1011              (enable_high write)
src/Piwigo/Ws/WsMethodRegistrar.php:39                      (ws user context)
src/Piwigo/Section/SectionInitializer.php:67               (nb_image_page)
src/Piwigo/Controller/Admin/AdminController.php:85          (admin layout)
src/Piwigo/Controller/Admin/AlbumController.php:158         (user id)
src/Piwigo/Controller/Admin/BatchManagerController.php:101, 629, 975
src/Piwigo/Controller/Admin/MaintenanceController.php:971, 1136
src/Piwigo/Controller/Admin/MiscController.php:463, 538
src/Piwigo/Controller/Admin/PhotoController.php:168, 619
src/Piwigo/Controller/Admin/UsersController.php:80
src/Piwigo/Controller/AboutController.php:43
src/Piwigo/Controller/ActionController.php:82–83, 102
src/Piwigo/Controller/FeedController.php:54
src/Piwigo/Controller/IdentificationController.php:102
src/Piwigo/Controller/InstallController.php:292             (post-install user init)
src/Piwigo/Controller/NotificationController.php:48
src/Piwigo/Controller/PasswordController.php:62
src/Piwigo/Controller/PictureController.php:99
src/Piwigo/Controller/ProfileController.php:62
src/Piwigo/Controller/RegisterController.php:61
src/Piwigo/Controller/SearchController.php:53
src/Piwigo/Auth/PasswordService.php:136, 160
```

---

## 2. Session Handler Bridge

**File:** `src/Piwigo/Session/PwgSession.php`

`PwgSession implements \SessionHandlerInterface` is registered with `session_set_save_handler()`. Every method immediately delegates to `Kernel::service(SessionService::class)`:

```php
public function open(string $path, string $name): bool {
    return Kernel::service(SessionService::class)->sessionOpen($path, $name);
}
// … close, read, write, destroy, gc
```

This adapter exists because PHP requires a `SessionHandlerInterface` object, but `SessionService` holds the real logic and is a DI-managed service. The bridge cannot be eliminated without making `SessionService` itself implement `SessionHandlerInterface` and registering it directly.

**Note:** The `#[Override]` attribute on each method and the `// see https://php.watch/versions/8.4/…` comment confirm the current code uses the valid object-form signature (PHP 8.4's deprecation only affects the old function-argument form).

**Status: ❌ NOT MET.** Verified 2026-05-14. `SessionService` is `final readonly class` — it cannot implement `SessionHandlerInterface` (interface methods mutate state, incompatible with `readonly`). `PwgSession` is the only bridge available. **Removal path:** make `SessionService` non-readonly and have it implement `SessionHandlerInterface`, then register it directly via `session_set_save_handler(new SessionService(…))` from the DI container.

---

## 3. Legacy Cache API (`PersistentCache` / `PersistentFileCache`)

**Files:** `src/Piwigo/Cache/PersistentCache.php`, `src/Piwigo/Cache/PersistentFileCache.php`, `src/Piwigo/Cache/PersistentCacheRegistry.php`

`PersistentCache` is an abstract legacy API (`get`, `set`, `purge`, `makeKey`) that predates PSR-6. `PersistentFileCache` is its sole concrete subclass; it wraps a `CacheItemPoolInterface` (defaults to `FilesystemAdapter`) and maps the old surface to PSR-6 calls.

`PersistentCacheRegistry::current()` returns the active instance, set by `CommonBootstrap` on every request.

**Removal path:** Replace all `PersistentCache::get/set/purge/makeKey` call-sites with direct PSR-6 / `CacheFactory` usage, then delete `PersistentCache`, `PersistentFileCache`, and `PersistentCacheRegistry`.

**Status: ❌ NOT MET.** Verified 2026-05-14. 10 files with active call-sites:

```
src/Piwigo/Bootstrap/CommonBootstrap.php        (constructs + registers — 2 lines)
src/Piwigo/Tag/TagService.php                   (tag list cache — 4 lines)
src/Piwigo/Section/SectionInitializer.php       (section items cache — 3 lines)
src/Piwigo/Search/SearchService.php             (search cache)
src/Piwigo/Search/SearchFilterRenderer.php      (filter cache)
src/Piwigo/Notification/NotificationService.php (notification cache)
src/Piwigo/Ws/Method/GeneralEndpoints.php       (WS tag cache)
src/Piwigo/Calendar/CalendarService.php         (calendar cache)
src/Piwigo/Admin/Users/UserAdminService.php     (user cache purge)
src/Piwigo/Controller/Admin/MaintenanceController.php  (purge — 2 lines)
```

---

## 4. Web Service Backward-Compat Parameters

**Status: ✅ REMOVED (2026-05-14).** No client compatibility maintained. All four methods cleaned:

- `pwg.images.addChunk`: `type` param removed; chunk filename hardcoded to `file`.
- `pwg.images.addFile`: `type` param removed; `thumb` early-return and `high` merge path deleted; size-check always runs.
- `pwg.images.add`: `thumbnail_sum`/`high_sum` params removed; always merges `file` chunks.
- `pwg.images.checkFiles`: `thumbnail_sum`/`high_sum` params removed; only `file_sum` comparison remains.

---

## 5. One-Time DB Migration Guard

**Files:** `src/Piwigo/History/HistoryRepository.php`, `src/Piwigo/Admin/History/HistoryAdminService.php`

Piwigo 2.x stored a `summarized` column in the `history` table. The column is unused in 16.x. `HistoryAdminService::historyRemoveSummarizedColumn()` drops it lazily on the first autopurge run where the table is small enough, then sets `Config('history_summarized_dropped') = true` so the check is skipped on all subsequent runs.

```
HistoryRepository::summarizedColumnExists()           — schema introspection
HistoryRepository::dropSummarizedColumn()             — ALTER TABLE … DROP COLUMN
HistoryAdminService::historyRemoveSummarizedColumn()  — guard + orchestrator (called at :447, :499)
Config::historySummarizedDropped()                    — the skip flag
```

**Removal condition:** All production installs confirmed to have the column gone. Remove `summarizedColumnExists`, `dropSummarizedColumn`, `historyRemoveSummarizedColumn`, the `history_summarized_dropped` Config entry, and `Config::historySummarizedDropped()`.

**Status: ❌ NOT MET / UNDETERMINABLE FROM CODE.** Verified 2026-05-14. Guard still active; called from `HistoryAdminService.php:447` and `:499`. No Doctrine migration for the column drop. Whether any given production install still has the column can only be known at runtime. **Alternative path:** write a Doctrine migration that drops the column unconditionally (with IF EXISTS guard), which gives a versioned, auditable removal date.

---

## 6. Plugin Config Legacy Storage Format

**Status: ✅ MOOT (2026-05-14).** All plugins removed — `plugins/` contains only a redirect stub. The four bundled plugin typed-config facades (`NbcThemeChanger`, `LocalFilesEditor`, `PiwigoOpenstreetmap`, `PiwigoVideojs`) in `src/Piwigo/Plugins/` have been deleted along with their test (`LocalFilesEditorConfigTest`). The `nbc_ThemeChanger` config row may still exist in existing databases but the code that reads or writes it is gone — no migration needed.

---

## 7. `trigger_error` Runtime Signals

Not deprecation shims — these are programmer-error and runtime-validation guards that use `E_USER_WARNING` / `E_USER_ERROR` rather than exceptions. Collected here for completeness. Verified 2026-05-14.

| File | Line | Condition signalled | Severity |
|------|------|---------------------|----------|
| `Category/CategoryService.php` | 239 | `get_subcat_ids` called with non-numeric ID | `E_USER_WARNING` |
| `Html/HtmlService.php` | 43 | `get_cat_display_name` called with wrong category type | `E_USER_WARNING` |
| `Admin/Users/UserAdminService.php` | 126 | Group delete called when group does not exist | `E_USER_WARNING` |
| `Admin/Image/ImageAdminService.php` | 89 | File cannot be removed from disk | `E_USER_WARNING` |
| `Ws/Method/ImagesEndpoints.php` | 1156 | File cannot be removed from disk | `E_USER_WARNING` |
| `Admin/Image/ImageExtImagick.php` | 211 | ImageMagick stderr line forwarded as warning | `E_USER_WARNING` |
| `Ws/Protocol/PwgRestEncoder.php` | 146 | Encoder receives unexpected PHP type | `E_USER_WARNING` |
| `Url/UrlService.php` | 263, 268 | Category array missing `name` or `permalink` key | `E_USER_WARNING` |
| `Mail/MailService.php` | 679 | PHPMailer send failure | `E_USER_WARNING` |
| `Admin/Category/CategoryAdminService.php` | 227, 250 | `set_cat_visible` / `set_cat_status` invalid param | `E_USER_WARNING` |
| `Picture/PictureCommentRenderer.php` | 96 | Unknown comment action | `E_USER_WARNING` |
| `Controller/CommentsController.php` | 229 | Unknown comment action | `E_USER_WARNING` |
| `Controller/PictureController.php` | 276 | Unknown comment action | `E_USER_WARNING` |
| `Controller/Admin/AlbumController.php` | 621, 1035 | Missing `cat_id` param — programming error | `E_USER_ERROR` |
| `Template/ScriptLoader.php` | 58, 84, 86, 128, 202 | Script/footer ordering violation — programming error | `E_USER_WARNING` |

The `AlbumController` (`E_USER_ERROR`) and `ScriptLoader` cases are programming errors, not runtime conditions; they should be converted to thrown exceptions. The remainder are reasonable runtime warnings.

---

## 8. Ad-hoc `$GLOBALS` Communication Channels

These globals are used as request-scoped data channels between unrelated classes but are NOT reference bridges — nothing syncs them to a typed singleton. They are catalogued here for completeness. Verified 2026-05-14.

| Global | Writer(s) | Reader(s) | Notes |
|--------|-----------|-----------|-------|
| `$GLOBALS['filter']` | `CommonBootstrap`, `FilterMiddleware` | `CategoryService`, `FilterService`, `SectionInitializer`, `MenubarRenderer`, `PermissionService`, `CalendarService` | Request-scoped filter state (recent-photos mode). No bridge. |
| `$GLOBALS['lang_info']` | `LanguageStack` | `AdminService`, `Template` | Language metadata (code, direction, name). Written and read only within the language subsystem. No bridge. |
| `$GLOBALS['picture']` | `PictureController` (via include chain) | `PictureMetadataRenderer`, `PictureCommentRenderer`, `PictureRateRenderer` | Current picture data array. No bridge. |
| `$GLOBALS['my_base_url']` | `AlbumsTabRenderer`, `UserTabRenderer`, `MaintenanceController`, `MiscController` | `CoreTabsRegistrar` and tabsheet code | Admin page base URL, set before tabsheet render. No bridge. |
| `$GLOBALS['debug']` | `Util::pwgLog()` | `PageTailRenderer` | Accumulated debug HTML. No bridge. |
| `$GLOBALS['t2']` | `CommonBootstrap` | `PageTailRenderer`, `Util::pwgLog()` | Request start microtime. No bridge. |
| `$GLOBALS['header_notes']` | `CommonBootstrap`, `CheckIntegrity` | `CommonBootstrap` (template assign) | Admin header notification strings. No bridge. |
| `$GLOBALS['header_msgs']` | `CommonBootstrap` | `CommonBootstrap` (template assign) | Guest/lock status warnings. Set and consumed within the same bootstrap method. No bridge. |
| `$GLOBALS['errors']` | `LocalSiteReader` | `LocalSiteReader` | Sync error list (not UI page errors). No bridge. |
| `$GLOBALS['url_self']` | **Nothing in src/** | `PictureCommentRenderer`, `PictureRateRenderer` | Was set by `include/picture.php` (removed). `PictureController` never writes it. Renderers always get `''`. Pre-existing rewrite gap — not a bridge issue. |
| `$GLOBALS['related_categories']` | **Nothing in src/** | `PictureCommentRenderer` | Same as above — `PictureController` builds a local `$related_categories` but never writes to the global. Renderer always gets `[]`. |
| `$GLOBALS['picture']` | **Nothing in src/** | `PictureCommentRenderer`, `PictureRateRenderer`, `PictureMetadataRenderer` | Same as above — `PictureController` builds a local `$picture` array but never writes to the global. All three renderers read `[]`. Pre-existing rewrite gap. |
| `$GLOBALS['cache']` | `UserService::getDefaultUserInfo()` | `UserService::getDefaultUserInfo()` | Self-contained request memoization: the method both writes and reads this cache. Never initialised externally. |
| `$GLOBALS['themeconfs']` | `Template::loadThemeconf()` | `Template::loadThemeconf()` | Self-contained per-request cache for parsed `themeconf.inc.php` files. Method both writes and reads it. |
| `$GLOBALS['prefixeTable']` | `CommonBootstrap`, `UpgradeController`, `InstallController` | `UpgradeService`, `MaintenanceService` | DB table prefix, set at boot via `Config::dbPrefix()`. Pre-boot config value, not a bridge. |
| `$GLOBALS['admin_album_base_url']` | `AlbumController` | `CoreTabsRegistrar` | Admin album URL prefix, set before tab rendering. Ad-hoc, not a bridge. |
| `$GLOBALS['maint_actions']` | `MaintenanceController` | `MaintenanceController` | Self-contained: set and consumed within the same controller. |
| `$GLOBALS['countQueries']` / `$GLOBALS['queriesTime']` | *Nothing in src/* | `PageTailRenderer` (via `PageState::current()->countQueries`) | Were incremented by the old `include/` DB layer. Now always 0. |
