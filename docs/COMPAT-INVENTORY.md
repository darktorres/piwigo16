# Compatibility Inventory

Shims, bridges, and backward-compatibility mechanisms in the 16.x rewrite.
Updated: 2026-05-14. Removal-condition status verified against current codebase.

**Policy (2026-05-14):** All plugins will be rewritten as part of the platform migration.
External plugin compatibility is NOT a blocker. Only in-tree `src/` callers block removal.

---

## 1. Wave A Reference Bridges

These classes maintain a bidirectional PHP-reference link between a typed singleton and a `$GLOBALS` key so that legacy procedural code (plugins, un-migrated `include/` files) continues to work without modification. Each bridge is wired in `Kernel::boot()` via `attachGlobals()`.

### 1.1 `PageState` — `$GLOBALS['page']`

**File:** `src/Piwigo/Core/PageState.php`

`PageState::attachGlobals()` reads any values already in `$GLOBALS['page']` (set by `common.inc.php` before boot), copies them into the singleton, then re-assigns every well-known key as a PHP reference:

```
$GLOBALS['page']['errors']    = &$inst->errors;
$GLOBALS['page']['warnings']  = &$inst->warnings;
$GLOBALS['page']['messages']  = &$inst->messages;
$GLOBALS['page']['infos']     = &$inst->infos;
$GLOBALS['page']['body_classes'] = &$inst->bodyClasses;
… (13 keys total)
```

**Removal condition:** All reads/writes of `$page['…']` in `src/` migrated to `PageState::current()->…`.

**Status: ❌ NOT MET.** Many `src/` files still read `$GLOBALS['page']` directly instead of `PageState::current()`. Confirmed callers (grep, 2026-05-14):

```
src/Piwigo/Core/Util.php:503
src/Piwigo/Admin/History/HistoryAdminService.php:31
src/Piwigo/Section/SectionInitializer.php:64
src/Piwigo/Category/CategoryDefaultRenderer.php:40
src/Piwigo/Admin/Integrity/C13yInternal.php:154
src/Piwigo/Ws/Method/GeneralEndpoints.php:505, 533
src/Piwigo/Users/UserService.php:801
src/Piwigo/Calendar/CalendarService.php:39
src/Piwigo/Controller/PopuphelpController.php:39
src/Piwigo/Controller/IdentificationController.php:67
src/Piwigo/Controller/Admin/MiscController.php:143, 311, 496, 536, 899, 1118
src/Piwigo/Picture/PictureCommentRenderer.php:42
src/Piwigo/Picture/PictureRateRenderer.php:31
src/Piwigo/Admin/Updates.php:548
src/Piwigo/Admin/AdminService.php:200
src/Piwigo/Admin/Album/AlbumsTabRenderer.php:22
src/Piwigo/Admin/Tag/TagAdminService.php:109
src/Piwigo/Admin/Image/PwgImage.php:369, 394
src/Piwigo/Admin/Image/ImageAdminService.php:373
src/Piwigo/Admin/Users/UserTabRenderer.php:20
src/Piwigo/Admin/BatchManager/FilterResolver.php:37
src/Piwigo/Controller/Admin/BatchManagerController.php:101
```

---

### 1.2 `Lang` / `Translator` — `$GLOBALS['lang']`

**Files:** `src/Piwigo/Core/Lang.php`, `src/Piwigo/Lang/Translator.php`

`Lang::attachGlobals()` wires `$GLOBALS['lang']` as a PHP reference to `Lang::$data`.
`Translator::mirrorToGlobal()` (called on every `load()`) additionally copies every PO translation into `$GLOBALS['lang']` so legacy `$lang['key']` reads stay current.
`Translator` also rebuilds `$lang['day']` and `$lang['month']` sub-arrays for callers like `Lang::day()`, `admin/stats.php`.

**Removal condition:** All direct `$lang[…]` reads in `src/` migrated to `Lang::t()` / `Lang::day()` / `Lang::month()`.

**Status: ❌ NOT MET.** Many `src/` files still read `$GLOBALS['lang']` directly. Confirmed callers (grep, 2026-05-14):

```
src/Piwigo/Calendar/CalendarWeekly.php:27
src/Piwigo/Calendar/CalendarMonthly.php:36, 264, 337, 378
src/Piwigo/Controller/Admin/PhotoController.php:542, 550–551
src/Piwigo/Controller/Admin/MaintenanceController.php:998–999
src/Piwigo/Controller/Admin/ConfigurationController.php:311–312
src/Piwigo/Controller/Admin/MiscController.php:725–726
src/Piwigo/Notification/NotificationService.php:394–395
src/Piwigo/Controller/PictureController.php:101, 475–476
```

---

### 1.3 `TemplateRegistry` — `$GLOBALS['template']`

**File:** `src/Piwigo/Template/TemplateRegistry.php`

`TemplateRegistry::set(Template $t)` simultaneously sets the typed singleton and `$GLOBALS['template'] = $t`. Any global-scope `$template = new Template(…)` site must call `TemplateRegistry::set()` right after construction.

Local-scope Template instances (e.g. MailService mail templates) are intentionally NOT registered.

**Removal condition:** All global `$template` reads and constructions in `src/` migrated to `TemplateRegistry::current()`.

**Status: ✅ REMOVED (2026-05-14).** `$GLOBALS['template'] = $template` deleted from `TemplateRegistry::set()`. Docblock updated.

---

### 1.4 `LoadedPluginRegistry` — `$GLOBALS['pwg_loaded_plugins']`

**File:** `src/Piwigo/Plugins/LoadedPluginRegistry.php`

`LoadedPluginRegistry::init()` wires `$GLOBALS['pwg_loaded_plugins']` as a reference to `self::$plugins`. Legacy plugin code that reads the global directly sees live data.

**Removal condition:** All in-tree reads of `$pwg_loaded_plugins` migrated to `LoadedPluginRegistry::all/get()`.

**Status: ❌ NOT MET.** Three `src/` controllers still read `$GLOBALS['pwg_loaded_plugins']` directly. Confirmed callers (grep, 2026-05-14):

```
src/Piwigo/Controller/Admin/MiscController.php:540
src/Piwigo/Controller/Admin/ExtensionsController.php:443
src/Piwigo/Controller/Admin/BatchManagerController.php:977
```

Migrate each to `LoadedPluginRegistry::all()` and the bridge can be removed.

---

### 1.5 `EventDispatcher` — `$GLOBALS['pwg_event_handlers']`

**File:** `src/Piwigo/Plugins/EventDispatcher.php`

`EventDispatcher::init()` wires `$GLOBALS['pwg_event_handlers']` as a reference to `self::$handlers`. Legacy plugins that write `$pwg_event_handlers[…]` directly still register their listeners.

**Removal condition:** No in-tree `src/` code writes `$pwg_event_handlers` directly outside `EventDispatcher.php`.

**Status: ✅ REMOVED (2026-05-14).** `$GLOBALS['pwg_event_handlers'] = &self::$handlers` deleted from `EventDispatcher::init()`. `$handlers` visibility comment and class docblock updated. `init()` now just resets `self::$handlers = []`.

---

### 1.6 `CurrentUser` — `$GLOBALS['user']`

**File:** `src/Piwigo/Users/CurrentUser.php`

Not a PHP-reference bridge (the global is a plain array and can't be cleanly reference-bridged to a typed object), but maintains bidirectional sync via explicit methods:

- `attachGlobals()` — builds the typed `User` from `$GLOBALS['user']` at boot.
- `setLanguage()` — updates both `$this->user->language` and `$GLOBALS['user']['language']`.
- `setRawAttributes()` — replaces both the typed entity and `$GLOBALS['user']` wholesale (used by NBM when sending as a different recipient).

**Removal condition:** All direct `$GLOBALS['user']` reads in `src/` migrated to `CurrentUser::get()->…`.

**Status: ❌ NOT MET.** The most widely un-migrated bridge. Confirmed callers of `$GLOBALS['user']` in `src/` (grep, 2026-05-14):

```
src/Piwigo/Page/NoPhotoYetRenderer.php:35
src/Piwigo/Core/Util.php:502
src/Piwigo/Admin/Users/UserAdminService.php:183–184, 191
src/Piwigo/Section/SectionInitializer.php:66
src/Piwigo/Users/AuthService.php:289
src/Piwigo/Ws/Method/ImagesEndpoints.php:1027
src/Piwigo/Controller/FeedController.php:54
src/Piwigo/Controller/Admin/PhotoController.php:168, 619
src/Piwigo/Ws/WsMethodRegistrar.php:39
src/Piwigo/Controller/Admin/BatchManagerController.php:101, 629, 975
src/Piwigo/Bootstrap/CommonBootstrap.php:90, 233
src/Piwigo/Auth/PasswordService.php:136, 160
src/Piwigo/Lang/LangService.php:90
src/Piwigo/Admin/Notification/NotificationAdminService.php:78
src/Piwigo/Controller/Admin/AlbumController.php:158
src/Piwigo/Controller/InstallController.php:292
src/Piwigo/Controller/Admin/AdminController.php:85
src/Piwigo/Controller/AboutController.php:43
src/Piwigo/Controller/RegisterController.php:62
src/Piwigo/Controller/ActionController.php:82–83, 102
src/Piwigo/Controller/PictureController.php:99
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

**Note:** The `#[Override]` attribute on each method and the comment `// see https://php.watch/versions/8.4/…` flag a PHP 8.4 deprecation of the older `session_set_save_handler` signature — the current code uses the object form that remains valid.

**Status: ❌ NOT MET.** `SessionService` is declared `final readonly class SessionService` and does not implement `SessionHandlerInterface`. Making it do so would require removing `readonly` (the interface methods must mutate state) and wiring it as the handler object directly. `PwgSession` is the only way to bridge the gap today.

---

## 3. Legacy Cache API (`PersistentCache` / `PersistentFileCache`)

**Files:** `src/Piwigo/Cache/PersistentCache.php`, `src/Piwigo/Cache/PersistentFileCache.php`, `src/Piwigo/Cache/PersistentCacheRegistry.php`

`PersistentCache` is an abstract legacy API (`get`, `set`, `purge`, `makeKey`) that predates PSR-6. `PersistentFileCache` is its sole concrete subclass; it wraps a `CacheItemPoolInterface` (defaults to `FilesystemAdapter`) and maps the old surface to PSR-6 calls.

`PersistentCacheRegistry::current()` returns the active instance, set by `CommonBootstrap` on every request.

**Removal path:** Replace `PersistentCache::get/set/purge` call-sites with direct PSR-6 / `CacheFactory` usage, then delete `PersistentCache`, `PersistentFileCache`, and `PersistentCacheRegistry`.

**Status: ❌ NOT MET.** 10 active call-sites remain (grep, 2026-05-14):

```
src/Piwigo/Bootstrap/CommonBootstrap.php:123–124          (constructs + registers)
src/Piwigo/Tag/TagService.php:65, 68, 86, 96             (tag list cache)
src/Piwigo/Admin/Users/UserAdminService.php:165           (user cache purge)
src/Piwigo/Search/SearchFilterRenderer.php:48             (filter cache)
src/Piwigo/Section/SectionInitializer.php:265, 276, 289  (section items cache)
src/Piwigo/Ws/Method/GeneralEndpoints.php:99              (WS tag cache)
src/Piwigo/Calendar/CalendarService.php:35                (calendar cache)
src/Piwigo/Search/SearchService.php:859                   (search cache)
src/Piwigo/Notification/NotificationService.php:269       (notification cache)
src/Piwigo/Controller/Admin/MaintenanceController.php:291, 522  (purge)
```

---

## 4. Web Service Backward-Compat Parameters

**File:** `src/Piwigo/Ws/WsMethodRegistrar.php`

Three API methods accept legacy parameters that should no longer be used:

| Method | Parameter | Notes |
|--------|-----------|-------|
| `pwg.images.addChunk` (line 360) | `type` | Accepts `"high"` and `"thumb"` for back-compat; only `"file"` is valid |
| `pwg.images.addFile` (line 375) | `type` | Same as above |
| `pwg.images.add` (lines 387–389) | `thumbnail_sum`, `high_sum` | Accepted but explicitly documented as "don't use" |
| `pwg.images.checkFiles` (lines 732–737) | `thumbnail_sum`, `high_sum` | Same |

**Removal condition:** Confirm no active third-party client (Piwigo.app, DigiKam, etc.) sends these values, then drop the optional params and any handling branches in `ImagesEndpoints.php`.

**Status: ✅ REMOVED (2026-05-14).** No client compat maintained. All four methods cleaned:

- `addChunk`: `type` param removed; chunk filename hardcoded to `file`.
- `addFile`: `type` param removed; `thumb` early-return and `high` merge path deleted; size-check always runs.
- `add`: `thumbnail_sum`/`high_sum` params removed; always merges `file` chunks.
- `checkFiles`: `thumbnail_sum`/`high_sum` params removed; only `file_sum` comparison remains.

---

## 5. One-Time DB Migration Guard

**Files:** `src/Piwigo/History/HistoryRepository.php`, `src/Piwigo/Admin/History/HistoryAdminService.php`

Piwigo 2.x stored a `summarized` column in the `history` table. The column is unused in 16.x. `HistoryAdminService::historyRemoveSummarizedColumn()` drops it lazily on the first autopurge run where the table is small enough, then sets `Config('history_summarized_dropped') = true` so the check is skipped on all subsequent runs.

```
HistoryRepository::summarizedColumnExists()  — schema introspection
HistoryRepository::dropSummarizedColumn()    — ALTER TABLE … DROP COLUMN
HistoryAdminService::historyRemoveSummarizedColumn() — guard + orchestrator
Config::historySummarizedDropped()           — the skip flag
```

**Removal condition:** All production installs are confirmed to have the column gone (i.e. every install that could have had the column has run autopurge at least once post-16.x). At that point: remove `summarizedColumnExists`, `dropSummarizedColumn`, `historyRemoveSummarizedColumn`, the `history_summarized_dropped` Config entry, and `Config::historySummarizedDropped()`.

**Status: ❌ NOT MET / UNDETERMINABLE FROM CODE.** The guard is still active and called from two places (`HistoryAdminService.php:447` and `:499`). There is no Doctrine migration for the column drop — the guard is the only mechanism. Whether a given production install still has the column can only be known at runtime. There is no in-tree signal that all installs have passed through the guard.

---

## 6. Plugin Config Legacy Storage Format

**File:** `src/Piwigo/Plugins/NbcThemeChanger/Config.php`

The bundled `nbc_ThemeChanger` plugin stores its selected-themes list as a semicolon-separated string in `piwigo_config` (`nbc_ThemeChanger` key) because that was the pre-16.x format. The typed `Config::themes()` accessor decodes it on read; `Config::setThemes()` encodes it back on write.

The "backward compatibility" here is with existing database rows written by older Piwigo installs that used the same plugin. Changing to a serialized array would require a data migration.

**Removal condition:** A migration script converts the stored value to the new format (e.g. JSON) and updates `Config::themesRaw/themes/setThemes` accordingly.

**Status: ❌ NOT MET.** No migration script exists. `src/Piwigo/Migrations/` contains only `MigrationRunner.php` — no migration files at all. The semicolon format is still the live storage format.

---

## 7. `trigger_error` Runtime Signals

Not deprecation shims — these are programmer-error and runtime-validation guards that use `E_USER_WARNING` / `E_USER_ERROR` rather than exceptions. Collected here for completeness.

| File | Line | Condition signalled |
|------|------|---------------------|
| `Category/CategoryService.php` | 239 | `get_subcat_ids` called with non-numeric ID |
| `Html/HtmlService.php` | 43 | `get_cat_display_name` called with wrong category type |
| `Admin/Users/UserAdminService.php` | 126 | Group delete called when group does not exist |
| `Ws/Method/ImagesEndpoints.php` | 1172 | File cannot be removed from disk |
| `Admin/Image/ImageExtImagick.php` | 211 | ImageMagick stderr line forwarded as warning |
| `Ws/Protocol/PwgRestEncoder.php` | 146 | Encoder receives unexpected PHP type |
| `Url/UrlService.php` | 263, 268 | Category array missing `name` or `permalink` key |
| `Mail/MailService.php` | 679 | PHPMailer send failure |
| `Admin/Category/CategoryAdminService.php` | 227, 250 | `set_cat_visible` / `set_cat_status` invalid param |
| `Picture/PictureCommentRenderer.php` | 96 | Unknown comment action |
| `Controller/Admin/AlbumController.php` | 621, 1035 | Missing `cat_id` param (programming error) |
| `Template/ScriptLoader.php` | 58, 84, 86, 128, 202 | Script/footer ordering violations (programming error) |
| `Controller/CommentsController.php` | 229 | Unknown comment action |
| `Controller/PictureController.php` | 276 | Unknown comment action |

The `AlbumController` and `ScriptLoader` cases (programming errors, not user input) could be converted to thrown exceptions; the rest are reasonable runtime warnings.
