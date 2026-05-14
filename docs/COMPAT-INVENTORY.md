# Compatibility Inventory

Shims, bridges, and backward-compatibility mechanisms in the 16.x rewrite.
Last deep-verified: 2026-05-14. Last updated: 2026-05-14 (Wave A bridges + §3 PersistentCache removed).

**Policy (2026-05-14):** All plugins will be rewritten as part of the platform migration.
External plugin compatibility is NOT a blocker. Only in-tree `src/` callers block removal.

---

## 1. Wave A Reference Bridges

These classes previously maintained a bidirectional PHP-reference link between a typed singleton and a `$GLOBALS` key. **All six Wave A bridges are now fully resolved** — bridges removed, callers migrated to typed APIs, no `$GLOBALS` reads/writes remain in production `src/` for any of these keys.

| § | Global | Status |
|---|---|---|
| 1.1 | `$GLOBALS['page']` | ✅ Complete — SectionContext + PictureContext VOs, PageState typed props |
| 1.2 | `$GLOBALS['lang']` | ✅ Complete — Lang static properties, bridge removed |
| 1.3 | `$GLOBALS['template']` | ✅ Removed |
| 1.4 | `$GLOBALS['pwg_loaded_plugins']` | ✅ Removed — 3 callers migrated to LoadedPluginRegistry |
| 1.5 | `$GLOBALS['pwg_event_handlers']` | ✅ Removed |
| 1.6 | `$GLOBALS['user']` | ✅ Complete — CurrentUser typed entity, bridge removed |

### 1.1 `PageState` — `$GLOBALS['page']`

**File:** `src/Piwigo/Core/PageState.php`

`PageState::attachGlobals()` previously read pre-boot values from `$GLOBALS['page']` and wired 13 PHP reference bridges (errors, warnings, messages, infos, body_classes, body_data, …). The bridge was removed in May 2026. The subsequent migration replaced all ad-hoc `$GLOBALS['page']` reads with typed value objects.

**Removal condition (original):** All reads/writes of `$GLOBALS['page']` in `src/` migrated to typed equivalents.

**Status: ✅ COMPLETE.** All six migration groups delivered:

#### Group 1 — per-request service caches → service instance state
Five keys moved out of `$page` into owning class fields:
- `ext_imagick_command` / `is_ext_imagick` → `PwgImage::$extImagickCommandCache` / `$extImagickCache`
- `fs_quick_check_already_called` → `ImageAdminService::$fsQuickCheckCalled`
- `tag_id_from_tag_name_cache` → `TagAdminService::$tagCache`
- `user_use_cache` → local var in `UserBootstrap::bootstrap()`
- `sizes_loaded_in_tpl` → `SizesProcessor::$sizesLoadedInTpl`

#### Group 4 — display/template metadata → `PageState` typed properties
Four new typed properties added to `PageState`: `$bodyId`, `$pageBanner`, `$metaRobots`, `$galleryTitle`.
All controller writes (`$page['body_id']` etc.) and `PageHeaderRenderer`/`MailService` reads migrated.

#### Group 5 — admin page state → local variables
~50 keys (`tab`, `page`, `action`, `mode`, `section`, `cat_elements_id`, `user_filter`, etc.) across
17 admin controllers/services inlined as plain local variables or typed instance fields.
Tab renderers (`AlbumsTabRenderer`, `UserTabRenderer`) and `HistoryAdminService::historyTabsheet()`
received explicit string parameters instead of reading from `$GLOBALS['page']`.

#### Group 2 — gallery section/navigation context → `SectionContext` VO
**New VO:** `src/Piwigo/Section/SectionContext.php` (31 readonly properties: section, items, category,
tags, chronologyField, etc.) + `SectionContextRegistry`.

`SectionInitializer::initialize()` builds and registers a `SectionContext` at the end of every
request. All ~30 external readers migrated:
`GalleryController`, `PictureController`, `CalendarService`, `CalendarBase`, `CalendarMonthly`,
`CalendarWeekly`, `CategoryCatsRenderer`, `CategoryDefaultRenderer`, `CategoryService`,
`MenubarRenderer`, `SearchFilterRenderer`, `SearchService`, `HtmlService`, `Util::pwgLog`,
`UserService`, `PictureCommentRenderer`, `PictureRateRenderer`, `SelectedTagsRenderer`,
`UrlService::paramsForDuplication` + `getRootUrl`, `GeneralEndpoints::historyLog`.

`SectionContext.toUrlParams()` converts the VO back to the snake_case array that `UrlService` URL
builders expect. `UrlService::getRootUrl()` checks `$GLOBALS['page']['root_path']` first so that
the `setMakeFullUrl()`/`unsetMakeFullUrl()` push/pop mechanism (for absolute email URLs) still works.

`$GLOBALS['page'] = []` initialization removed from `PageState::attachGlobals()`.

#### Group 3 — picture page navigation → `PictureContext` VO
**New VO:** `src/Piwigo/Picture/PictureContext.php` (9 readonly properties: currentItem,
nextItem, previousItem, firstItem, lastItem, currentRank, lastRank, rankOf, slideshow) +
`PictureContextRegistry`.

`PictureController::__invoke()` builds and registers a `PictureContext` after resolving the
navigation state. `PictureCommentRenderer` migrated: uses `PictureContextRegistry::current()->currentItem`
(resolved ID, correct even for filename-based URLs) and a local `$showComments` variable.
All `$GLOBALS['page']` reads/writes removed from both files.

#### SectionInitializer internal cleanup
`$page = &$GLOBALS['page']` replaced with `$page = []; $GLOBALS['page'] = &$page` — local array
aliased to the global so that CalendarService and SearchService sub-calls (which do
`&$GLOBALS['page']`) automatically write into the same local array. Zero signature changes.

#### PageState::attachGlobals() deleted
`Kernel::boot()` now calls `PageState::current()` directly. The method has been removed.

---

**Remaining `$GLOBALS['page']` accesses (all legitimate, not external readers):**

| Location | Keys | Nature |
|---|---|---|
| `SectionInitializer::initialize()` | all section keys | Local array aliased to global; sub-calls write through the alias; NOT an external reader |
| `CalendarService::initializeCalendar()` | `items`, `comment`, `chronology_*` | Within SectionInitializer call stack; writes feed the SectionContext snapshot |
| `SearchService` methods | `search_details`, `use_regexp_ICU`, `search_id` | Within SectionInitializer call stack; mutable search cache |
| `SearchFilterRenderer::render()` | `search_details` (ref) | Reference write-back to mutable search state |
| `CalendarBase`/`CalendarMonthly` render methods | `chronology_date` (write) | Rendering-time dead writes; calendar URL builders always override explicitly |
| `UrlService::setMakeFullUrl` / `unsetMakeFullUrl` | `root_path`, `save_root_path` | Preserved push/pop mechanism for absolute URLs in emails; `getRootUrl()` checks this first |
| `MaintenanceController::history()` | `search`, `search_id`, `nb_lines`, `start` | WS search state set by `GeneralEndpoints::historySearch()` for the admin history page; catalogued in §8 |
| `GeneralEndpoints::historySearch()` | `search`, `nb_lines`, `start` | Writer for the above channel |

---

### 1.2 `Lang` / `Translator` — `$GLOBALS['lang']`

**Files:** `src/Piwigo/Core/Lang.php`, `src/Piwigo/Lang/Translator.php`

`Lang::attachGlobals()` wires `$GLOBALS['lang']` as a PHP reference to `Lang::$data`.
`Translator::mirrorToGlobal()` (called on every `load()`) additionally copies every PO translation into `$GLOBALS['lang']` so legacy `$lang['key']` reads stay current.
`Translator` also rebuilds `$lang['day']` and `$lang['month']` sub-arrays for callers like `Lang::day()`, `admin/stats.php`.

**Removal condition:** All direct `$GLOBALS['lang']` reads in `src/` migrated to `Lang::t()` / `Lang::day()` / `Lang::month()`.

**Status: ✅ CALLER READS MET (2026-05-14).** All 8 external caller files migrated:
- `CalendarMonthly` (4×) + `CalendarWeekly` → `Lang::months()` / `Lang::days()` (new array accessors)
- `PhotoController` + `PictureController` → `Lang::has()` + `Lang::t()` for dynamic format keys
- `NotificationService` → `Lang::month(int)`
- `MaintenanceController` → `Lang::months()`
- `ConfigurationController` → `Lang::day(0/1)`
- `MiscController` → `Lang::days()`

New methods added: `Lang::months(): array<array-key,string>` and `Lang::days(): array<array-key,string>`.

No `$GLOBALS['lang']` reads remain outside the bridge infrastructure itself (`Lang.php`, `LanguageStack.php`, `Translator.php`, `CommonBootstrap.php`).

**Bridge fully removed (follow-up 2026-05-14):**
- `Lang::$days` / `Lang::$months` added as typed static properties.
- `Translator::mirrorToGlobal()` now calls `Lang::setString()` / `Lang::setDays()` / `Lang::setMonths()` — no `$GLOBALS['lang']` writes.
- `Translator::translate()` fallback uses `Lang::getRaw()` instead of `$GLOBALS['lang']`.
- `LanguageStack` uses `Lang::all()` / `Lang::bulkSet()` / `Lang::setString()` — no `$GLOBALS['lang']` reads.
- `Lang::attachGlobals()` now snapshots any pre-boot `$GLOBALS['lang']` into static properties, then calls `unset($GLOBALS['lang'])` — no reference bridge created.

`Lang::attachGlobals()` is still called by `Kernel::boot()` for the pre-boot snapshot, but it could be inlined into Kernel if desired. No `$GLOBALS['lang']` remains in production code after boot.

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

**Status: ✅ REMOVED (2026-05-14).** Bridge removed; three controller reads migrated:
- `LoadedPluginRegistry`: `$GLOBALS['pwg_loaded_plugins'] = &self::$plugins` removed from `init()` and `reset()`; `$plugins` made private.
- `MiscController`: `count($GLOBALS['pwg_loaded_plugins'])` → `count(LoadedPluginRegistry::all())`
- `BatchManagerController`: `array_keys(...)` → `array_keys(LoadedPluginRegistry::all())`
- `ExtensionsController`: `isset($pwg_loaded_plugins[$id])` → `LoadedPluginRegistry::isLoaded($id)`

`PluginService::loadPlugins()` is still called from `CommonBootstrap` but is a no-op (plugins/ has no `main.inc.php` files). No `$GLOBALS['pwg_loaded_plugins']` remains in `src/`.

---

### 1.5 `EventDispatcher` — `$GLOBALS['pwg_event_handlers']`

**File:** `src/Piwigo/Plugins/EventDispatcher.php`

`EventDispatcher::init()` previously wired `$GLOBALS['pwg_event_handlers']` as a reference to `self::$handlers`.

**Removal condition:** No in-tree `src/` code writes `$pwg_event_handlers` directly outside `EventDispatcher.php`.

**Status: ✅ REMOVED.** First pass removed the bridge from `EventDispatcher::init()`. Third-pass census found it was also present in `EventDispatcher::reset()` — removed there too. No remaining `$pwg_event_handlers` references in production `src/` (tools/phpstan-bootstrap.php and NoGlobalInSrcRule.php are non-production).

---

### 1.6 `CurrentUser` — `$GLOBALS['user']`

**File:** `src/Piwigo/Users/CurrentUser.php`

Not a PHP-reference bridge (the global is a plain array), but maintains bidirectional sync via explicit methods:

- `attachGlobals()` — builds the typed `User` from `$GLOBALS['user']` at boot.
- `setLanguage()` — updates both `User::$language` and `$GLOBALS['user']['language']`.
- `setRawAttributes()` — replaces both the typed entity and `$GLOBALS['user']` wholesale (NBM).

**Removal condition:** All direct `$GLOBALS['user']` reads in `src/` migrated to `CurrentUser::get()->…`.

**Status: ✅ CALLER READS MET (2026-05-14).** All 30+ external caller files migrated:

- **Pure-read controllers/services** (`$user = CurrentUser::get()->rawAttributes`): AboutController,
  FeedController, IdentificationController, NotificationController, PasswordController,
  ProfileController, RegisterController, SearchController, PictureController, AdminController,
  AlbumController, BatchManagerController (3×), MaintenanceController (2×), MiscController (2×),
  PhotoController (2×), UsersController, FilterMiddleware, SectionInitializer, TagService,
  NoPhotoYetRenderer, Util::pwgLog, WsMethodRegistrar, NotificationAdminService, ProfileService
- **Write/mutate cases**: ActionController (enabledHigh), UserAdminService (nb_available_tags unset),
  CommentService (nb_available_comments unset), PreferencesService (rawAttributes['preferences']),
  CommonBootstrap (guest username on typed User + rawAttributes), InstallController
  (→ CurrentUser::setRawAttributes()), PasswordService/NotificationAdminService (save/restore)
- **LangService**: simplified guard (CurrentUser::isInitialized() fallback)

**Bridge fully removed (follow-up 2026-05-14):**
- `UserBootstrap::bootstrap()` now accumulates a local `$userId` int; calls `buildUser($userId)` then `CurrentUser::set(User::fromUserArray($builtUser))` — no `$GLOBALS['user']` writes.
- `AuthService::authKeyLogin()` now reads from `CurrentUser::get()->rawAttributes` instead of `&$GLOBALS['user']`; still calls `CurrentUser::setRawAttributes()` after key resolution.
- `CurrentUser::attachGlobals()` no longer reads `$GLOBALS['user']` — creates default guest User (`??=` so idempotent). Called by `Kernel::boot()`.
- `CurrentUser::setLanguage()` and `setRawAttributes()`: `$GLOBALS['user']` writes removed (dead code).
- `CommonBootstrap`: `$GLOBALS['user'] = []` init removed.

No `$GLOBALS['user']` remains anywhere in production `src/`.

---

## 2. Session Handler Bridge

~~**File:** `src/Piwigo/Session/PwgSession.php`~~

**Status: ✅ REMOVED (2026-05-14).** `PwgSession.php` deleted.

`SessionService` now implements `\SessionHandlerInterface` directly (`final readonly` → `final` with explicit `readonly` per constructor param). Handler methods renamed to match the interface (`sessionOpen→open`, `sessionClose→close`, `sessionRead→read`, `sessionWrite→write`, `sessionDestroy→destroy`, `sessionGc→gc`).

`SessionBootstrap` passes `Kernel::service(SessionService::class)` directly to `session_set_save_handler()` — Kernel is already booted at that point (line 119 vs 158 in CommonBootstrap). Direct `sessionGc()` callers in `AuthService` and `MaintenanceController` updated to `gc(0)` (the `$max_lifetime` argument is ignored; the method always uses `Config::sessionLength()`).

---

## 3. Legacy Cache API (`PersistentCache` / `PersistentFileCache`)

**Files deleted (2026-05-14):** `src/Piwigo/Cache/PersistentCache.php`, `src/Piwigo/Cache/PersistentFileCache.php`, `src/Piwigo/Cache/PersistentCacheRegistry.php`

`PersistentCache` was an abstract legacy API (`get`, `set`, `purge`, `makeKey`) that predated PSR-6. `PersistentFileCache` was its sole concrete subclass, wrapping a `CacheItemPoolInterface`. `PersistentCacheRegistry::current()` provided the singleton.

**Status: ✅ REMOVED (2026-05-14).** All 10 call-sites migrated to direct PSR-6 `CacheItemPoolInterface` injection:

- `CacheItemPoolInterface` registered in `config/container.php` via `CacheFactory::create()`.
- `CommonBootstrap`: removed `PersistentFileCache` construction and `PersistentCacheRegistry::set()`.
- All 8 services (`TagService`, `SectionInitializer`, `SearchService`, `SearchFilterRenderer`, `NotificationService`, `CalendarService`, `GeneralEndpoints`, `UserAdminService`) now receive `CacheItemPoolInterface $pool` via constructor injection and call `getItem()` / `save()` / `clear()` directly.
- `MaintenanceController`: receives `CacheItemPoolInterface $pool`; two `purge(true)` calls replaced by `$this->pool->clear()`.
- `makeKey(string|array $key)` → `md5($key . AppInfo::VERSION)` inlined at each call-site to preserve per-version cache invalidation.
- `PersistentFileCacheTest` deleted (tested a deleted class).

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
| `Category/CategoryService.php` | 231 | `get_subcat_ids` called with non-numeric ID | `E_USER_WARNING` |
| `Html/HtmlService.php` | 44 | `get_cat_display_name` called with wrong category type | `E_USER_WARNING` |
| `Admin/Users/UserAdminService.php` | 127 | Group delete called when group does not exist | `E_USER_WARNING` |
| `Admin/Image/ImageAdminService.php` | 91 | File cannot be removed from disk | `E_USER_WARNING` |
| `Ws/Method/ImagesEndpoints.php` | 1155 | File cannot be removed from disk | `E_USER_WARNING` |
| `Admin/Image/ImageExtImagick.php` | 211 | ImageMagick stderr line forwarded as warning | `E_USER_WARNING` |
| `Ws/Protocol/PwgRestEncoder.php` | 146 | Encoder receives unexpected PHP type | `E_USER_WARNING` |
| `Url/UrlService.php` | 268, 273 | Category array missing `name` or `permalink` key | `E_USER_WARNING` |
| `Mail/MailService.php` | 679 | PHPMailer send failure | `E_USER_WARNING` |
| `Admin/Category/CategoryAdminService.php` | 227, 250 | `set_cat_visible` / `set_cat_status` invalid param | `E_USER_WARNING` |
| `Picture/PictureCommentRenderer.php` | 94 | Unknown comment action | `E_USER_WARNING` |
| `Controller/CommentsController.php` | 226 | Unknown comment action | `E_USER_WARNING` |
| `Controller/PictureController.php` | 259 | Unknown comment action | `E_USER_WARNING` |
| `Controller/Admin/AlbumController.php` | 607, 1017 | Missing `cat_id` param — programming error | `E_USER_ERROR` |
| `Template/ScriptLoader.php` | 58, 84, 86, 128, 202 | Script/footer ordering violation — programming error | `E_USER_WARNING` |

The `AlbumController` (`E_USER_ERROR`) and `ScriptLoader` cases are programming errors, not runtime conditions; they should be converted to thrown exceptions. The remainder are reasonable runtime warnings.

---

## 8. Ad-hoc `$GLOBALS` Communication Channels

These globals are used as request-scoped data channels between unrelated classes but are NOT reference bridges — nothing syncs them to a typed singleton. They are catalogued here for completeness. Last updated: 2026-05-14.

| Global | Writer(s) | Reader(s) | Notes |
|--------|-----------|-----------|-------|
| `$GLOBALS['filter']` | `CommonBootstrap`, `FilterMiddleware` | `CategoryService`, `FilterService`, `SectionInitializer`, `MenubarRenderer`, `PermissionService`, `CalendarService` | Request-scoped filter state (recent-photos mode). No bridge. |
| `$GLOBALS['lang_info']` | `LanguageStack` | `AdminService`, `Template` | Language metadata (code, direction, name). Written and read only within the language subsystem. No bridge. |
| `$GLOBALS['my_base_url']` | `AlbumsTabRenderer`, `UserTabRenderer`, `MaintenanceController`, `MiscController` | `CoreTabsRegistrar` and tabsheet code | Admin page base URL, set before tabsheet render. No bridge. |
| `$GLOBALS['debug']` | `Util::pwgLog()` | `PageTailRenderer` | Accumulated debug HTML. No bridge. |
| `$GLOBALS['t2']` | `CommonBootstrap` | `PageTailRenderer`, `Util::pwgLog()` | Request start microtime. No bridge. |
| `$GLOBALS['header_notes']` | `CommonBootstrap`, `CheckIntegrity` | `CommonBootstrap` (template assign) | Admin header notification strings. No bridge. |
| `$GLOBALS['header_msgs']` | `CommonBootstrap` | `CommonBootstrap` (template assign) | Guest/lock status warnings. Set and consumed within the same bootstrap method. No bridge. |
| `$GLOBALS['errors']` | `LocalSiteReader` | `LocalSiteReader` | Sync error list (not UI page errors). No bridge. |
| `$GLOBALS['url_self']` | **Nothing in src/** | `PictureCommentRenderer`, `PictureRateRenderer` | Was set by `include/picture.php` (removed). Renderers always get `''`. Pre-existing gap — renderers should be updated to use `UrlService::duplicatePictureUrl()`. |
| `$GLOBALS['related_categories']` | **Nothing in src/** | `PictureCommentRenderer` | `PictureController` builds a local but never writes the global. Renderer always gets `[]`. Pre-existing gap. |
| `$GLOBALS['picture']` | **Nothing in src/** | `PictureCommentRenderer`, `PictureRateRenderer`, `PictureMetadataRenderer` | `PictureController` builds a local `$picture` array but never writes the global. All three renderers get `[]`. Pre-existing gap — renderers should use `PictureContextRegistry` once picture data is added there. |
| `$GLOBALS['cache']` | `UserService::getDefaultUserInfo()` | `UserService::getDefaultUserInfo()` | Self-contained request memoization. |
| `$GLOBALS['themeconfs']` | `Template::loadThemeconf()` | `Template::loadThemeconf()` | Self-contained per-request cache for `themeconf.inc.php` files. |
| `$GLOBALS['prefixeTable']` | `CommonBootstrap`, `UpgradeController`, `InstallController` | `UpgradeService`, `MaintenanceService` | DB table prefix. Pre-boot config value. |
| `$GLOBALS['admin_album_base_url']` | `AlbumController` | `CoreTabsRegistrar` | Admin album URL prefix, set before tab rendering. |
| `$GLOBALS['maint_actions']` | `MaintenanceController` | `MaintenanceController` | Self-contained: set and consumed within the same controller. |
| `$GLOBALS['countQueries']` / `$GLOBALS['queriesTime']` | *Nothing in src/* | `PageTailRenderer` (via `PageState::current()->countQueries`) | Were incremented by old `include/` DB layer. Now always 0. |
| `$GLOBALS['page']['search']` + `['nb_lines']` + `['start']` | `GeneralEndpoints::historySearch()` | `MaintenanceController::history()` | WS history search state: AJAX call sets search rules + result count, page re-render reads them to build the nav bar and prefill the form. Survives the §1.1 migration because both sides still use `$GLOBALS['page']` explicitly. |
| `$GLOBALS['page']['auth_key_id']` | `AuthService::authKeyLogin()` | `Util::pwgLog()` | API-key request ID passed to the history log. Pre-existing gap — not part of the Wave A §1.1 migration groups. |
| `$GLOBALS['page']['username']` | `PasswordService` | `PasswordService` | Temporary username stored mid-password-reset flow. Self-contained within that service. |
