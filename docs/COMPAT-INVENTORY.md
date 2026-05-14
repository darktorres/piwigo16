# Compatibility Inventory

Shims, bridges, and backward-compatibility mechanisms in the 16.x rewrite.
Updated: 2026-05-14.

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

**Removal condition:** All reads/writes of `$page['…']` in `include/`, plugins, and templates migrated to `PageState::current()->…`.

---

### 1.2 `Lang` / `Translator` — `$GLOBALS['lang']`

**Files:** `src/Piwigo/Core/Lang.php`, `src/Piwigo/Lang/Translator.php`

`Lang::attachGlobals()` wires `$GLOBALS['lang']` as a PHP reference to `Lang::$data`.
`Translator::mirrorToGlobal()` (called on every `load()`) additionally copies every PO translation into `$GLOBALS['lang']` so legacy `$lang['key']` reads stay current.
`Translator` also rebuilds `$lang['day']` and `$lang['month']` sub-arrays for callers like `Lang::day()`, `admin/stats.php`.

**Removal condition:** All direct `$lang[…]` reads migrated to `Lang::t()` / `Lang::day()` / `Lang::month()`.

---

### 1.3 `TemplateRegistry` — `$GLOBALS['template']`

**File:** `src/Piwigo/Template/TemplateRegistry.php`

`TemplateRegistry::set(Template $t)` simultaneously sets the typed singleton and `$GLOBALS['template'] = $t`. Any global-scope `$template = new Template(…)` site must call `TemplateRegistry::set()` right after construction.

Local-scope Template instances (e.g. MailService mail templates) are intentionally NOT registered.

**Removal condition:** All global `$template` reads and constructions migrated to `TemplateRegistry::current()`.

---

### 1.4 `LoadedPluginRegistry` — `$GLOBALS['pwg_loaded_plugins']`

**File:** `src/Piwigo/Plugins/LoadedPluginRegistry.php`

`LoadedPluginRegistry::init()` wires `$GLOBALS['pwg_loaded_plugins']` as a reference to `self::$plugins`. Legacy plugin code that reads the global directly sees live data.

**Removal condition:** No plugin or un-migrated include reads `$pwg_loaded_plugins` directly.

---

### 1.5 `EventDispatcher` — `$GLOBALS['pwg_event_handlers']`

**File:** `src/Piwigo/Plugins/EventDispatcher.php`

`EventDispatcher::init()` wires `$GLOBALS['pwg_event_handlers']` as a reference to `self::$handlers`. Legacy plugins that write `$pwg_event_handlers[…]` directly still register their listeners.

**Removal condition:** No plugin or un-migrated include writes `$pwg_event_handlers` directly.

---

### 1.6 `CurrentUser` — `$GLOBALS['user']`

**File:** `src/Piwigo/Users/CurrentUser.php`

Not a PHP-reference bridge (the global is a plain array and can't be cleanly reference-bridged to a typed object), but maintains bidirectional sync via explicit methods:

- `attachGlobals()` — builds the typed `User` from `$GLOBALS['user']` at boot.
- `setLanguage()` — updates both `$this->user->language` and `$GLOBALS['user']['language']`.
- `setRawAttributes()` — replaces both the typed entity and `$GLOBALS['user']` wholesale (used by NBM when sending as a different recipient).

**Removal condition:** All direct `$user[…]` reads migrated to `CurrentUser::get()->…`.

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

---

## 3. Legacy Cache API (`PersistentCache` / `PersistentFileCache`)

**Files:** `src/Piwigo/Cache/PersistentCache.php`, `src/Piwigo/Cache/PersistentFileCache.php`, `src/Piwigo/Cache/PersistentCacheRegistry.php`

`PersistentCache` is an abstract legacy API (`get`, `set`, `purge`, `makeKey`) that predates PSR-6. `PersistentFileCache` is its sole concrete subclass; it wraps a `CacheItemPoolInterface` (defaults to `FilesystemAdapter`) and maps the old surface to PSR-6 calls.

`PersistentCacheRegistry::current()` returns the active instance, set by `CommonBootstrap` on every request.

**Active callers:**
- `CommonBootstrap` — constructs and registers `PersistentFileCache`
- `TagService` — uses `PersistentCacheRegistry::current()` to cache the full tag list

**Removal path:** Replace `PersistentCache::get/set/purge` call-sites with direct PSR-6 / `CacheFactory` usage, then delete `PersistentCache`, `PersistentFileCache`, and `PersistentCacheRegistry`.

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

These are consumed by the corresponding endpoint handlers in `ImagesEndpoints.php`. The compat values are silently ignored or mapped to `"file"` internally.

**Removal condition:** Confirm no active third-party client (Piwigo.app, DigiKam, etc.) sends these values, then drop the optional params and any handling branches in `ImagesEndpoints.php`.

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

---

## 6. Plugin Config Legacy Storage Format

**File:** `src/Piwigo/Plugins/NbcThemeChanger/Config.php`

The bundled `nbc_ThemeChanger` plugin stores its selected-themes list as a semicolon-separated string in `piwigo_config` (`nbc_ThemeChanger` key) because that was the pre-16.x format. The typed `Config::themes()` accessor decodes it on read; `Config::setThemes()` encodes it back on write.

The "backward compatibility" here is with existing database rows written by older Piwigo installs that used the same plugin. Changing to a serialized array would require a data migration.

**Removal condition:** A migration script converts the stored value to the new format (e.g. JSON) and updates `Config::themesRaw/themes/setThemes` accordingly.

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
