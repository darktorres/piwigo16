# Compatibility Inventory

Shims, bridges, and backward-compatibility mechanisms in the 16.x rewrite.
Last deep-verified: 2026-05-14. Last updated: 2026-05-14.

**Headline status:**
- §§2–7 fully resolved (session bridge deleted, PersistentCache removed, WS BC params dropped, summarized-column guard migrated, plugin config moot, all `trigger_error` eliminated).
- §1 Wave A: §§1.2–1.6 caller migrations are complete and no `$GLOBALS` reads/writes remain for those keys; §1.1 `$GLOBALS['page']` still has 30 active references in 13 files — the reference bridge at `SectionInitializer:68` (`$GLOBALS['page'] = &$page`) is preserved on purpose so sub-calls (Search/Calendar/UrlService push-pop) mutate the same shared array.
- §8 ad-hoc channels: all cross-class admin URL channels eliminated as of 2026-05-14 (CoreTabsRegistrar takes no `$GLOBALS` reads). Remaining §8 channels are either bootstrap init (`prefixeTable`, `t2`, `header_*`, `debug`, `filter`), language-subsystem (`lang_info`), or self-contained (`themeconfs`, `cache`, `errors`, `maint_actions`).
- §9 stale comments: several files reference long-removed `$GLOBALS` bridges in docstrings only — see §9.
- §10 tooling drift outside `src/`: the `NoGlobalInSrcRule` PHPStan rule has stale replacement advice (points at deleted `PersistentCacheRegistry`); `index.php` writes `$GLOBALS['prefixeTable']` directly in two fast-path branches; `psalm.xml` suppresses a check with a "legacy-compatibility bridges" comment. Tests legitimately seed `$GLOBALS` for fixtures. See §10.
- §11 legacy `define()` constants — a parallel shim mechanism the inventory had not covered at all: 105 `define()` calls in `src/` form three legacy patterns — runtime context flags (`IN_ADMIN`, `IN_WS`, `PHPWG_IN_UPGRADE`), web-service constant bridges (13 `WS_*` constants mirroring `WsParam`/`WsType` enums), SQL table-name constants (30+ defined in `UpgradeService` for legacy upgrade queries), plus a brittle `CURRENT_DATE` constant with inconsistent definitions across three controllers, and a `xmlrpc_encode()` call in `PwgXmlRpcEncoder` that depends on a PHP extension removed in 8.1. See §11.
- §12 vendor & template-engine shims — `pclzip/pclzip` (PHP 4-era zip library) still used in 4 admin files instead of native `ZipArchive`; `openpsa/universalfeedcreator` extended by `PiwigoFeedCreator`; `ahand/mobileesp` (`uagent_info` class) used in 3 places for mobile UA detection. The `Template/Latte/PiwigoExtension.php` is a 700+-line Smarty-compatibility layer that ports `default`, `strip_tags`, `date_format`, `cat:`, `html_options`, `html_radios`, `math` and other Smarty modifiers/blocks to Latte — needed because the .latte templates use Smarty-style surface syntax. `PwgImage::__call()` magic dispatch over `ImageImagick`/`ImageExtImagick`. See §12.
- §13 PHPStan tooling stubs & extensions — `tools/phpstan-bootstrap.php` declares 11 legacy `global $foo` placeholders (8 already removed), duplicates 13 `WS_*` runtime defines, and provides 7 stub `plugin_*`/`theme_*` procedural callbacks. Two phpstan extensions are dead: `PwgGetSessionVarDynamicReturnType` types a function that no longer exists, and `TriggerChangeDynamicReturnType` is misnamed (actually targets `EventDispatcher::dispatch`). `tools/triggers_list.php` is a 1136-line plugin-author doc using legacy `trigger_change`/`trigger_notify` terminology. See §13.
- §14 frontend shims — `src/types/globals.d.ts` documents 30+ ambient TS globals; its header comment says "Smarty templates" (stale; we're on Latte). Several declared globals (`var user`, `SwitchBox`, `_pwgRatingAutoQueue`, `preferencesDefaultValues`, …) are pre-load auto-queue patterns explicitly drained as "legacy queue" by `rating.ts:150` and `switchbox.ts:35` for plugin BC. `albums.ts:522` retains a window-global with a `// keep global for compatibility` comment. See §14.
- §15 plugin/theme procedural contract — the **whole legacy Piwigo plugin/theme runtime contract is still wired**: `PluginService::loadPlugin()` does `require_once($pluginsPath/$id/main.inc.php)`; plugin metadata is parsed from file header comments (`Version: x.y.z`); `Admin/Plugins.php` has explicit pre-2.7 vs 2.7+ branching (`maintain.class.php` vs `maintain.inc.php`); `Admin/Themes.php` requires `themeconf.inc.php` and `admin/maintain.inc.php` per theme; `EventDispatcher` supports lazy-include plugins via `include_path` on listeners. 18+ docstrings throughout `src/` still describe code as "Used by admin/X.php" or "Replaces the former include/Y.inc.php" for directories that don't exist. See §15.
- §16 `Util.php` kitchen-sink class & legacy v1.x features — `src/Piwigo/Core/Util.php` is 1058 lines / 33 methods, most named `pwg*` (`pwgLog`, `pwgDebug`, `pwgActivity`, `getPwgToken`, `pwgUniqueExecBegins`, etc.) — the modern wrapper around legacy `include/functions.inc.php`. The legacy v1.x **caddie** feature (predecessor to batch_manager) is still alive as a WS API (`pwg.caddie.add`), a DB table (`Tables::caddie()`), a `CADDIE_TABLE` constant for upgrade SQL, and `Util::fillCaddie()` populating it from `GeneralEndpoints` and `PhotoController`. Three redirect helpers (`redirect`, `redirectHttp`, `redirectHtml`) overlap. See §16.

**Policy (2026-05-14):** All plugins will be rewritten as part of the platform migration.
External plugin compatibility is NOT a blocker. Only in-tree `src/` callers block removal.

---

## 1. Wave A Reference Bridges

These classes previously maintained a bidirectional PHP-reference link between a typed singleton and a `$GLOBALS` key. Five of the six bridges are fully removed; the sixth (`page`) keeps a deliberate alias for internal sub-call mutation.

| § | Global | Status |
|---|---|---|
| 1.1 | `$GLOBALS['page']` | ⚠️ Bridge alias still active — typed VOs (SectionContext, PictureContext) and PageState props cover external readers, but `SectionInitializer:68` still does `$GLOBALS['page'] = &$page` so Search/Calendar/UrlService sub-calls mutate the shared local array. 30 `$GLOBALS['page']` references remain across 13 files. |
| 1.2 | `$GLOBALS['lang']` | ✅ Removed — `Lang::attachGlobals()` snapshots once at boot and `unset()`s the global; one stale comment in `Translator.php:99` |
| 1.3 | `$GLOBALS['template']` | ✅ Removed |
| 1.4 | `$GLOBALS['pwg_loaded_plugins']` | ✅ Removed — 3 callers migrated to LoadedPluginRegistry |
| 1.5 | `$GLOBALS['pwg_event_handlers']` | ✅ Removed |
| 1.6 | `$GLOBALS['user']` | ✅ Removed — no code reads/writes remain; 4 stale comments reference it (see §9) |

### 1.1 `PageState` — `$GLOBALS['page']`

**File:** `src/Piwigo/Core/PageState.php`

`PageState::attachGlobals()` previously read pre-boot values from `$GLOBALS['page']` and wired 13 PHP reference bridges (errors, warnings, messages, infos, body_classes, body_data, …). The bridge was removed in May 2026. The subsequent migration replaced all ad-hoc `$GLOBALS['page']` reads with typed value objects.

**Removal condition (original):** All reads/writes of `$GLOBALS['page']` in `src/` migrated to typed equivalents.

**Status: ⚠️ MIGRATION GROUPS DELIVERED, BRIDGE ALIAS STILL ACTIVE.** External readers all moved to typed VOs (SectionContext, PictureContext) or PageState properties — but `SectionInitializer:68` keeps `$GLOBALS['page'] = &$page` so Search/Calendar/UrlService sub-calls that do `&$GLOBALS['page']` still mutate the shared local array (see the "Remaining accesses" table below). 30 `$GLOBALS['page']` references remain in production `src/` (verified 2026-05-14).

All six migration groups delivered:

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
**New VO:** `src/Piwigo/Picture/PictureContext.php` (12 readonly properties: currentItem,
nextItem, previousItem, firstItem, lastItem, currentRank, lastRank, rankOf, slideshow,
ratingScore, srcImage, relatedCategories) + `PictureContextRegistry`.

`PictureController::__invoke()` builds and registers a `PictureContext` after resolving the
navigation state. All `$GLOBALS['page']` reads/writes removed from both files.
`ratingScore`, `srcImage`, and `relatedCategories` were added in the §8 gap-closure pass
(2026-05-14) to give picture-page renderers typed access to data that was previously
piped through broken `$GLOBALS['picture']` / `$GLOBALS['related_categories']` channels.

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

`Lang::attachGlobals()` previously wired `$GLOBALS['lang']` as a PHP reference to `Lang::$data`.
`Translator::mirrorToGlobal()` additionally copied every PO translation into `$GLOBALS['lang']` for legacy callers.
Both behaviours have been removed — see the "Bridge fully removed" block below.

**Removal condition:** All direct `$GLOBALS['lang']` reads in `src/` migrated to `Lang::t()` / `Lang::day()` / `Lang::month()`.

**Status: ✅ CALLER READS MET (2026-05-14).** All 8 external caller files migrated:
- `CalendarMonthly` (4×) + `CalendarWeekly` → `Lang::months()` / `Lang::days()` (new array accessors)
- `PhotoController` + `PictureController` → `Lang::has()` + `Lang::t()` for dynamic format keys
- `NotificationService` → `Lang::month(int)`
- `MaintenanceController` → `Lang::months()`
- `ConfigurationController` → `Lang::day(0/1)`
- `MiscController` → `Lang::days()`

New methods added: `Lang::months(): array<array-key,string>` and `Lang::days(): array<array-key,string>`.

No `$GLOBALS['lang']` reads remain in production `src/` outside `Lang::attachGlobals()` in `Lang.php`, which snapshots any pre-boot data once at Kernel boot then unsets the global.

**Bridge fully removed (follow-up 2026-05-14):**
- `Lang::$days` / `Lang::$months` added as typed static properties.
- `Translator::mirrorToGlobal()` now calls `Lang::setString()` / `Lang::setDays()` / `Lang::setMonths()` — no `$GLOBALS['lang']` writes.
- `Translator::translate()` fallback uses `Lang::getRaw()` instead of `$GLOBALS['lang']`.
- `LanguageStack` uses `Lang::all()` / `Lang::bulkSet()` / `Lang::setString()` — no `$GLOBALS['lang']` reads.
- `Lang::attachGlobals()` now snapshots any pre-boot `$GLOBALS['lang']` into static properties, then calls `unset($GLOBALS['lang'])` — no reference bridge created.

`Lang::attachGlobals()` is still called by `Kernel::boot()` for the pre-boot snapshot, but it could be inlined into Kernel if desired. No `$GLOBALS['lang']` remains in production code after boot.

**Stale comment:** `Translator.php:99` still says "restores from the stack top (so `$GLOBALS['lang']` takes over)" — this is wrong; the bridge is gone. See §9.

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

No `$GLOBALS['user']` reads or writes remain anywhere in production `src/`.

**Stale (2026-05-14):**
- `CurrentUser::attachGlobals()` is misnamed — its current body just initialises a guest `User` singleton (`self::$instance ??= new User(...)`) and does not touch any `$GLOBALS` key. Kept under the historical name because `Kernel::boot()` calls it. Renaming to `initGuest()` (or merging into Kernel) is a cosmetic follow-up.
- Four docstrings still describe `$GLOBALS['user']` as if the bridge were live — `UserBootstrap.php:23`, `AuthMiddleware.php:18`, `AuthMiddleware.php:20`, `FilterMiddleware.php:27`. See §9.

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

**Status: ✅ REMOVED (2026-05-14).** Replaced the runtime lazy-guard with a proper Doctrine migration.

- `Version20260514000001` drops `summarized` from the history table via schema introspection (skips silently if column is absent — handles fresh 16.x installs).
- `HistoryAdminService::historyRemoveSummarizedColumn()` and its two call-sites deleted.
- `HistoryRepository::summarizedColumnExists()` and `dropSummarizedColumn()` deleted.
- `Config::historySummarizedDropped()` and the `history_summarized_dropped` SCHEMA entry deleted.
- `HistoryAdminService::$configService` constructor param removed (was only used by the guard).

---

## 6. Plugin Config Legacy Storage Format

**Status: ✅ MOOT (2026-05-14).** All plugins removed — `plugins/` contains only a redirect stub. The four bundled plugin typed-config facades (`NbcThemeChanger`, `LocalFilesEditor`, `PiwigoOpenstreetmap`, `PiwigoVideojs`) in `src/Piwigo/Plugins/` have been deleted along with their test (`LocalFilesEditorConfigTest`). The `nbc_ThemeChanger` config row may still exist in existing databases but the code that reads or writes it is gone — no migration needed.

---

## 7. `trigger_error` Runtime Signals

**Status: ✅ FULLY ELIMINATED (2026-05-14).** All `trigger_error` calls in production `src/` have been replaced. No `trigger_error` remains.

| File | Line(s) | Replacement |
|------|---------|-------------|
| `Controller/Admin/AlbumController.php` | 607, 1017 | `throw new \InvalidArgumentException` — missing `cat_id` param |
| `Template/ScriptLoader.php` | 59, 86, 88, 130, 204 | `throw new \LogicException` — script/footer ordering violation |
| `Category/CategoryService.php` | 232 | `throw new \InvalidArgumentException` — non-numeric ID passed to `get_subcat_ids` |
| `Html/HtmlService.php` | 44 | `throw new \InvalidArgumentException` — wrong type in category array |
| `Admin/Users/UserAdminService.php` | 128 | `throw new \InvalidArgumentException` — empty group list; `deleteGroups()` return type narrowed from `false\|array` to `array` |
| `Admin/Image/ImageAdminService.php` | 89 | `throw new \RuntimeException` — `unlink()` failure; `$ok` flag removed |
| `Ws/Method/ImagesEndpoints.php` | 1154 | `throw new \RuntimeException` — `unlink()` failure |
| `Admin/Image/ImageExtImagick.php` | — | Removed — stderr already captured by `$logger->error()` on the line above |
| `Ws/Protocol/PwgRestEncoder.php` | 146 | `throw new \LogicException` — unexpected PHP type in encoder |
| `Url/UrlService.php` | 269, 272 | `throw new \InvalidArgumentException` — category array missing `name` / `permalink` |
| `Mail/MailService.php` | 678 | `LoggerRegistry::current()->warning()` — PHPMailer failure is a runtime infrastructure event; `$permissionService` param removed (was only used for the dropped display_errors guard) |
| `Admin/Category/CategoryAdminService.php` | 227, 249 | `throw new \InvalidArgumentException` — invalid `set_cat_visible` / `set_cat_status` param |
| `Picture/PictureCommentRenderer.php` | 94 | `throw new \LogicException` — unknown comment action |
| `Controller/CommentsController.php` | 226 | `throw new \LogicException` — unknown comment action |
| `Controller/PictureController.php` | 259 | `throw new \LogicException` — unknown comment action |

---

## 8. Ad-hoc `$GLOBALS` Communication Channels

These globals are used as request-scoped data channels between unrelated classes but are NOT reference bridges — nothing syncs them to a typed singleton. They are catalogued here for completeness. Last updated: 2026-05-14.

| Global | Writer(s) | Reader(s) | Notes |
|--------|-----------|-----------|-------|
| `$GLOBALS['filter']` | `CommonBootstrap` (init), `FilterMiddleware` (writes via `&$GLOBALS['filter']`), `SectionInitializer` (mutates via reference) | `CategoryService`, `FilterService`, `MenubarRenderer`, `PermissionService`, `CalendarService`, `PictureController` | Request-scoped filter state (recent-photos mode). No typed bridge — readers do `is_array($GLOBALS['filter'] ?? null) ? ... : []` inline. |
| `$GLOBALS['lang_info']` | `LanguageStack` (sets at language switch, merges, restores from stack) | `Template:83`, `AdminService:406` | Language metadata (code, direction, name). Cross-subsystem read — template + admin layers depend on the language-stack writer. Still an active runtime channel. |
| `$GLOBALS['my_base_url']` | ~~`AlbumsTabRenderer`, `UserTabRenderer`, `GroupsController`, `MaintenanceController`, `MiscController`, `ExtensionsController`~~ | ~~`CoreTabsRegistrar`~~ | **Fixed 2026-05-14**: `CoreTabsRegistrar` now calls `$ug->admin('pagename')` (with `&tab=...`/`&mode=...` suffixes where needed) directly per case. All writes removed; `UrlGenerator` dependency dropped from `UserTabRenderer` and `AlbumsTabRenderer`. `MiscController` internal `$my_base_url.'comments'` use replaced with `$urlGenerator->admin('comments')`. |
| `$GLOBALS['debug']` | `Util::pwgLog()` | `PageTailRenderer` | Accumulated debug HTML. No bridge. |
| `$GLOBALS['t2']` | `CommonBootstrap` | `PageTailRenderer`, `Util::pwgLog()` | Request start microtime. No bridge. |
| `$GLOBALS['header_notes']` | `CommonBootstrap`, `CheckIntegrity` | `CommonBootstrap` (template assign) | Admin header notification strings. No bridge. |
| `$GLOBALS['header_msgs']` | `CommonBootstrap` | `CommonBootstrap` (template assign) | Guest/lock status warnings. Set and consumed within the same bootstrap method. No bridge. |
| `$GLOBALS['errors']` | `LocalSiteReader` | `LocalSiteReader` | Sync error list (not UI page errors). No bridge. |
| `$GLOBALS['url_self']` | ~~Nothing in src/~~ | ~~`PictureCommentRenderer`, `PictureRateRenderer`~~ | **Fixed 2026-05-14**: renderers now call `$this->urlService->duplicatePictureUrl()` directly. |
| `$GLOBALS['related_categories']` | ~~Nothing in src/~~ | ~~`PictureCommentRenderer`~~ | **Fixed 2026-05-14**: added `relatedCategories: list<array<string,mixed>>` to `PictureContext`; `PictureController` populates it; renderer reads from `PictureContextRegistry::current()`. |
| `$GLOBALS['picture']` | ~~Nothing in src/~~ | ~~`PictureCommentRenderer`, `PictureRateRenderer`, `PictureMetadataRenderer`~~ | **Fixed 2026-05-14**: added `ratingScore: ?float` and `srcImage: ?SrcImage` to `PictureContext`; `PictureController` populates both; renderers read from `PictureContextRegistry::current()`. Dead `$picture` read in `PictureCommentRenderer` removed. |
| `$GLOBALS['cache']` | `UserService::getDefaultUserInfo()` | `UserService::getDefaultUserInfo()` | Self-contained request memoization. |
| `$GLOBALS['themeconfs']` | `Template::loadThemeconf()` | `Template::loadThemeconf()` | Self-contained per-request cache for `themeconf.inc.php` files. |
| `$GLOBALS['prefixeTable']` | `CommonBootstrap:83`, `UpgradeController`, `InstallController`, **`index.php:38` (image-derivative fast path)**, **`index.php:66` (upgrade_feed fast path)** | `UpgradeService:31`, `MaintenanceService:16` | DB table prefix. Pre-boot config value. The root `index.php` short-circuit branches (`/i/...` derivative and `/upgrade_feed`) skip `CommonBootstrap::run()` and therefore set this directly before calling Config queries. |
| `$GLOBALS['admin_album_base_url']` | ~~`AlbumController`~~ | ~~`CoreTabsRegistrar`~~ | **Fixed 2026-05-14**: `CoreTabsRegistrar` reads `$_GET['cat_id']` directly and computes `$ug->admin('album-{cat_id}')`. All three GLOBALS writes removed from `AlbumController`; local var kept in `albumNotification()` and `catPerm()` where still used internally. Dead local-var assignments removed from `catModify()`. |
| `$GLOBALS['link_start']`, `$GLOBALS['conf_link']` | ~~`AdminController`~~ | ~~`CoreTabsRegistrar`~~ | **Fixed 2026-05-14**: `CoreTabsRegistrar` now calls `$ug->admin('pagename')` directly; writes removed from `AdminController` (local vars kept — still used within that controller). |
| `$GLOBALS['manager_link']` | ~~`BatchManagerController`~~ | ~~`CoreTabsRegistrar`~~ | **Fixed 2026-05-14**: `CoreTabsRegistrar` uses `$ug->admin('batch_manager').'&mode='` directly; write + unused local var removed from `BatchManagerController`. |
| `$GLOBALS['base_url']` | ~~`MiscController`~~ | ~~`CoreTabsRegistrar`~~ | **Fixed 2026-05-14**: `CoreTabsRegistrar` uses `$ug->admin('notification_by_mail')` directly; write removed from `MiscController` (local var kept — still used within that controller). |
| `$GLOBALS['admin_photo_base_url']` | ~~`PhotoController`~~ | ~~`CoreTabsRegistrar`~~ | **Fixed 2026-05-14**: write already removed (dead-code pass); `CoreTabsRegistrar` now reads `$_GET['image_id']` directly and computes the URL via `$ug->admin()`. |
| `$GLOBALS['maint_actions']` | `MaintenanceController` | `MaintenanceController` | Self-contained: set and consumed within the same controller. |
| `$GLOBALS['countQueries']` / `$GLOBALS['queriesTime']` | *Nothing in src/* | `PageTailRenderer` (via `PageState::current()->countQueries`) | Were incremented by old `include/` DB layer. Now always 0. |
| `$GLOBALS['page']['search']` + `['nb_lines']` + `['start']` | `GeneralEndpoints::historySearch()` | `MaintenanceController::history()` | WS history search state: AJAX call sets search rules + result count, page re-render reads them to build the nav bar and prefill the form. Survives the §1.1 migration because both sides still use `$GLOBALS['page']` explicitly. |
| `$GLOBALS['page']['auth_key_id']` | `AuthService::authKeyLogin()` | `Util::pwgLog()` | API-key request ID passed to the history log. Pre-existing gap — not part of the Wave A §1.1 migration groups. |
| `$GLOBALS['page']['username']` | `PasswordService` | `PasswordService` | Temporary username stored mid-password-reset flow. Self-contained within that service. |
| `$GLOBALS['logger']` | ~~`CommonBootstrap`, `LoggerRegistry::set()`~~ | *Nothing in src/* | **Fixed 2026-05-14**: dead write removed from both sites; `LoggerRegistry::set()` no longer mirrors to GLOBALS; `CommonBootstrap` constructs and registers the logger in one step. |
| `$GLOBALS['help_link']` | *Nothing in src/* | ~~`CoreTabsRegistrar`~~ | **Fixed 2026-05-14**: dead read replaced with `''`; help tab URLs were always broken (empty prefix). |
| `$GLOBALS['current_release']` | *Nothing in src/* | ~~`UpgradeService`~~ | **Fixed 2026-05-14**: dead read replaced with `Config::piwigoInstalledVersion() ?? ''`, which is the typed equivalent (the upgrade version stored in conf table). |
| `$GLOBALS['category']` | ~~`PhotoController`~~ | *Nothing in src/* | **Fixed 2026-05-14**: dead write removed; the DB query that populated it was also eliminated. |
| `$GLOBALS['upload_form_config']` | ~~`PhotoController`~~ | *Nothing in src/* | **Fixed 2026-05-14**: dead write removed; `$upload_form_config` is still used locally in the same method. |

---

## 9. Stale Comments Referring to Removed Bridges

Code-level cleanup is done for these channels, but docstrings/inline comments in `src/` still describe the old behaviour and would mislead a reader auditing the codebase. Identified 2026-05-14 by a `grep -rn "GLOBALS\\['…'\\]"` sweep over `src/`.

| File:Line | Stale text (paraphrased) | Reality |
|---|---|---|
| `Users/UserBootstrap.php:23` | "the PSR-15 pipeline has a fully-built `$GLOBALS['user']` before the…" | `UserBootstrap` no longer writes `$GLOBALS['user']`; it only sets the typed `CurrentUser` singleton. |
| `Http/Middleware/AuthMiddleware.php:18` | "Calls `UserBootstrap::bootstrap()` which populates `$GLOBALS['user']`…" | Same — no `$GLOBALS['user']` write happens. |
| `Http/Middleware/AuthMiddleware.php:20` | "read `$GLOBALS['user']` as before; typed code reads the request attribute." | The "as before" half is dead. |
| `Http/Middleware/FilterMiddleware.php:27` | "Runs after AuthMiddleware so that `$GLOBALS['user']` (recent_period, id, …)" | `$GLOBALS['user']` doesn't exist at this point in 16.x; the middleware reads `CurrentUser::get()->rawAttributes`. |
| `Config/Config.php:23` | "`$GLOBALS['conf']` reference bridge (attachGlobals) was retired once all…" | True statement, but flagged here because Config no longer has `attachGlobals` and the comment is the last in-tree mention of the channel. |
| `Config/ConfigStorage.php:27` | "Bulk read from the conf table into `$GLOBALS['conf']` (and through the…)" | The bulk read populates `Config::$data` only; no `$GLOBALS['conf']` write. |
| `Lang/Translator.php:99` | "restores from the stack top (so `$GLOBALS['lang']` takes over)" | `$GLOBALS['lang']` is unset at boot by `Lang::attachGlobals()` and never repopulated — the Translator restore is via static state, not a global. |
| `Core/LanguageStack.php:34` | "we don't need `$GLOBALS['language_files']` for the switch_lang_to reload." | True — but it's the only mention of `$GLOBALS['language_files']` in the codebase. Could be deleted as a non-existent reference. |
| `Users/CurrentUser.php:21` | Method named `attachGlobals` with comment "initialise the singleton with an empty guest user" | The method no longer attaches anything to `$GLOBALS`. Misnamed; mechanical rename to `initGuest()` (or fold into `Kernel::boot()`) would be safe. |

**Impact:** None on runtime behaviour — these are documentation drift. Cleaning them is a search-and-replace pass, but worth doing in a single dedicated commit so future audits don't get false signals.

---

## 10. Tooling Drift Outside `src/`

A 2026-05-14 audit confirmed there is no shim/bridge code outside `src/` itself, but the *tooling* that enforces the cleanup has its own drift.

### 10.1 `tools/phpstan/NoGlobalInSrcRule.php`

The PHPStan rule that flags `global $foo` in `src/` has stale content:

| Issue | Detail |
|---|---|
| Class docblock (line 18) | Says "Legacy code in `include/` and `admin/` is allowed to keep using globals" — neither directory exists in 16.x. The rule applies to all of `src/`; nothing is exempted. |
| `REPLACEMENTS['persistent_cache']` (line 38) | Points at `PersistentCacheRegistry::current()` — that class was deleted in §3. Should point at `CacheItemPoolInterface` injection. |
| `REPLACEMENTS['header_notes']` (line 44) | Says the replacement is "$GLOBALS['header_notes'] reference-bridge" — that's a description, not a typed accessor. Either remove `header_notes` from GUARDED or point at a real typed wrapper. |
| `REPLACEMENTS['themeconfs']` (line 45) | "instance property or $GLOBALS['themeconfs'] reference-bridge" — contradictory; the global IS the cache (`Template::loadThemeconf()`). |
| `REPLACEMENTS['filter']` (line 47) | "$GLOBALS['filter'] read with is_array narrowing" — describes the existing pattern. Either remove from GUARDED (since the global is the storage and is allowed) or add a typed `FilterContext`. |

**Impact:** The rule still fires on `global $foo` declarations, so the enforcement isn't broken — but the error messages it would produce for some keys point users at deleted/non-existent typed APIs. Anyone who triggers it for `persistent_cache` gets advice to use a class that doesn't exist.

### 10.2 Tests legitimately seed `$GLOBALS`

Several test files set `$GLOBALS[...]` in setup/teardown to simulate the pre-boot environment Kernel expects:

- `tests/Unit/Core/KernelBootTest.php` — seeds `page`, `lang`, `user` then asserts boot behaviour (including that `attachGlobals` unsets `lang`).
- `tests/Unit/Url/UrlGeneratorTest.php` — sets `$GLOBALS['page']['root_path']` so URL builders have a deterministic root.
- `tests/Integration/ContainerSmokeTest.php` — seeds `page`, `user`, `lang`, `filter`, `prefixeTable` for the container smoke test.
- `tests/Unit/Users/CurrentUserTest.php` / `LangTest.php` — unset `$GLOBALS[...]` in setUp to isolate.

These are **not** shims or bridges — they are test fixtures that exist precisely because the code paths still touch `$GLOBALS` (or expect them to be initialised). They become removable in lockstep with the corresponding production-code cleanup.

### 10.3 `install/obsolete.list`

Plain-text file listing legacy filenames the upgrade flow deletes from old installs. Not code; lists historical Piwigo files that were removed (e.g., `include_phpwebgallery`, `admin/admin.php`). Kept for upgrade compatibility only — no action needed.

### 10.4 `psalm.xml`

Two stale comments around suppression rules:
- Line 30: `<!-- Legacy globals used in bootstrapped files — not actionable yet -->` — suppresses `MissingFile`. "Not actionable yet" is wrong; the policy is plugins are rewriting and BC is not maintained.
- Lines 33-34: `<!-- Reference assignments to $GLOBALS / static properties are intentional legacy-compatibility bridges; Psalm cannot analyze them but they are correct. -->` — suppresses `UnsupportedPropertyReferenceUsage`. The framing "legacy-compatibility bridges" is misleading; the only remaining reference assignment (`SectionInitializer:68`) is for internal sub-call coherence, not legacy callers. Suppression is still needed, but the reason should be updated.

### 10.5 `tools/psalm-stubs.phpstub`

223-line stub file declaring runtime constants and extension classes (Imagick, Redis, Relay, Predis, IntlDateFormatter) for Psalm. Lines 6-9 contain a stub for `xmlrpc_encode()` with the comment: "The xmlrpc extension is deprecated/removed in modern PHP builds but still used in `PwgXmlRpcEncoder`." This is the only stub that documents a real shim concern (see §11.5).

---

## 11. Legacy `define()` Constants — Parallel Shim Mechanism

The 16.x inventory has so far focused exclusively on `$GLOBALS[...]` channels, but the codebase also relies heavily on PHP runtime constants defined via `define()` to carry cross-cutting state. A whole-codebase sweep on 2026-05-14 found **105 `define()` calls in `src/`**, falling into five legacy categories.

### 11.1 Runtime Context Flags (`IN_ADMIN`, `IN_WS`, `PHPWG_IN_UPGRADE`)

Classic Piwigo "request context" detection — code reaches for `defined('IN_ADMIN')` instead of receiving a typed flag.

| Flag | Defined in | Read in |
|---|---|---|
| `IN_ADMIN` | `Ws/Method/ExtensionsEndpoints.php:63, 87, 165` (three plugin/theme install endpoints) | `Page/PageHeaderRenderer.php:30`, `Page/NoPhotoYetRenderer.php:39, 41`, `Users/ProfileService.php:56, 60, 92, 150, 217`, `Core/Util.php:142` |
| `IN_WS` | `Controller/WsController.php:42` | `Users/UserBootstrap.php:89, 119`, `Admin/Upload/UploadService.php:167` |
| `PHPWG_IN_UPGRADE` | `Admin/UpgradeService.php:145, 180`, `Controller/UpgradeController.php` | `Admin/UpgradeService.php:23` (self-contained) |

**Impact:** These are global state — any code path can `define()` them and any other can read them, with no type or scope enforcement. `IN_ADMIN` in particular escapes from WS extension endpoints into general page-render code (`PageHeaderRenderer`, `ProfileService`), which couples the WS layer to admin-page rendering.

**Migration:** Replace with a typed `RequestContext` object (admin/ws/upgrade/derivative) populated by the corresponding middleware and read from PSR-7 request attributes. The `NoPhotoYetRenderer:39` comment already acknowledges the smell: `/** @psalm-suppress RedundantCondition — IN_ADMIN is runtime-set; stub value misleads Psalm */`.

### 11.2 Web Service Constant Bridges (`WS_*`)

`PwgServer::boot()` lines 471-485 defines 13 constants as a bridge to the typed `WsParam` and `WsType` enums:

```
WS_PARAM_ACCEPT_ARRAY, WS_PARAM_FORCE_ARRAY, WS_PARAM_OPTIONAL,
WS_TYPE_BOOL, WS_TYPE_INT, WS_TYPE_FLOAT, WS_TYPE_POSITIVE, WS_TYPE_NOTNULL, WS_TYPE_ID,
WS_ERR_INVALID_METHOD, WS_ERR_MISSING_PARAM, WS_ERR_INVALID_PARAM, WS_XML_ATTRIBUTES
```

Consumed throughout `Ws/Method/*Endpoints.php` (Permissions, Groups, Users, Images, etc.) and in `Ws/Protocol/PwgRestRequestHandler.php`, `Ws/WsHelper.php`.

`WsParam.php:10` docstring even says: *"Values match the WS_PARAM_* defines in include/ws_core.inc.php."* — referencing an `include/` directory that no longer exists.

**Migration:** Replace `WS_ERR_INVALID_PARAM` with `WsError::InvalidParam->value`, `WS_TYPE_INT` with `WsType::Int->value`, etc. Then delete the `define()` block in `PwgServer::boot()`. Touches ~50 call sites across `Ws/`.

### 11.3 Legacy Table-Name Constants (`*_TABLE`)

`Admin/UpgradeService.php:33-58` defines 30+ table-name constants (`CATEGORIES_TABLE`, `IMAGES_TABLE`, `USERS_TABLE`, etc.) by string-concatenating the prefix. These exist only for **legacy upgrade SQL** that does inline string interpolation. Only `UpgradeService.php` reads them inside `src/` (verified). The typed equivalent (`Piwigo\Db\Tables::categories()` etc.) is used everywhere else.

**Migration:** Delete the defines once all upgrade SQL is rewritten to use the typed `Tables::*()` API. Not blocking — they're contained to the upgrade flow.

### 11.4 `CURRENT_DATE` — Inconsistent Definitions

Defined in **three** places with **two different formats**:

- `Admin/Metadata/MetadataAdminService.php:214` → `date('Y-m-d')` (date only)
- `Controller/UpgradeController.php:127` → `date('Y-m-d H:i:s')` (date + time)
- `Controller/InstallController.php:245` → `date('Y-m-d H:i:s')` (date + time)

Whichever path defines it first wins for the rest of the request; subsequent `define()` calls silently no-op (the `defined() or define()` guard pattern is used). **This is a latent bug**: if a request runs metadata + upgrade work in either order, the second consumer gets the format the first writer chose.

Also conflicts with the SQL keyword `CURRENT_DATE` (string literal) used in `Db/SqlExpr.php:70, 72, 74`.

**Migration:** Pass a `DateTimeImmutable` through the call chain, or delete one of the writers if it's dead.

### 11.5 `xmlrpc_encode()` — Removed PHP Extension

`Ws/Protocol/PwgXmlRpcEncoder.php:40` calls `xmlrpc_encode($response)`. The PHP `xmlrpc` extension was:

- Deprecated in PHP 8.0
- Moved to PECL in PHP 8.0
- Removed from the main PHP distribution by 8.1

This means `pwg.xmlrpc` requests will fatally error on any modern PHP build without an explicit PECL install. The class is fully wired through `PwgServer.php:522` (encoder selection by `format=xmlrpc` query param).

**Migration:** Either replace with a vendor library (e.g., `phpxmlrpc/phpxmlrpc`) or drop the xmlrpc protocol entirely — REST/JSON cover all callers we control. Since v17.0 breaks all PEM extensions anyway (per project policy), dropping is viable.

### 11.6 Other (one-off) `define()` Calls

- `Bootstrap/CommonBootstrap.php:174-186` — `PHPWG_DOMAIN`, `PHPWG_URL` (locale-derived strings for PEM URLs).
- `Bootstrap/CommonBootstrap.php:78` — `PWG_LOCAL_DIR`.
- `Core/Util.php:41-45` — `MKGETDIR_*` flag constants for the `mkgetdir` helper.

These are conventional runtime config / flag definitions and not shims, but they do contribute to the 105-define count and follow the same legacy `defined() or define()` pattern. A future cleanup pass could promote `MKGETDIR_*` to a typed enum and replace `PHPWG_DOMAIN`/`PHPWG_URL` with `Config` reads.

---

## 12. Vendor Library & Template-Engine Shims

A fourth (and finally exhaustive) sweep on 2026-05-14 caught two more shim categories that earlier passes missed: vendored legacy PHP libraries the codebase still depends on, and a Smarty-syntax compatibility layer inside the Latte engine.

### 12.1 PclZip — Legacy Zip Library

`pclzip/pclzip` (PHP 4-era zip library, originally from PHPConcept) is in `composer.json` `require` and used in four production files:

| File:Line | Call | Purpose |
|---|---|---|
| `Admin/Updates.php:600` | `new \PclZip($filename)` | Extract Piwigo core update archives |
| `Admin/Plugins.php:549` | `new \PclZip($archive)` | Extract plugin archives from PEM |
| `Admin/Languages.php:273` | `new \PclZip($archive)` | Extract language archives |
| `Admin/Themes.php:511` | `new \PclZip($archive)` | Extract theme archives |

PHP 8.x has built-in `ZipArchive` (ext-zip). No code uses `ZipArchive`. The `tools/psalm-stubs.phpstub` even declares all 20 `PCLZIP_OPT_*` constants because the library's own constants are defined lazily at first instantiation.

**Migration:** Replace four call sites with `ZipArchive`, then drop the Composer dependency. Mechanical translation; the PclZip API is wider than needed (we only use `extract()` essentially).

### 12.2 UniversalFeedCreator — Legacy Feed Library

`openpsa/universalfeedcreator` is in `composer.json` `require`. `Feed/PiwigoFeedCreator.php` extends `\UniversalFeedCreator` (untyped legacy class). Used by `Controller/FeedController.php:78` to emit RSS/Atom feeds.

Modern alternatives: PHP's built-in `SimpleXMLElement`, or `laminas/laminas-feed`. The current dependency is maintained by a third party but the upstream library is essentially a port of a 2004-era PHP class. The class hierarchy is untyped (mixed defaults, no docblocks).

**Migration:** Either swap the library or rewrite `PiwigoFeedCreator` to emit RSS/Atom XML directly with `SimpleXMLElement`. Not urgent; the feed format itself is stable.

### 12.3 MobileEsp — Mobile User-Agent Detection

`ahand/mobileesp` (`\uagent_info` class) is in `composer.json` `require` and used in three places:

- `Core/Util.php:452` — `new \uagent_info()` for `mobile_detect()` helper
- `Controller/Admin/PhotoController.php:614` — admin upload UI mobile path
- `Controller/Admin/MiscController.php:574` — admin home page mobile shortcut

MobileEsp is a ~2010 regex-based UA detection library; it predates modern UA Client Hints and has no PHP type hints. Native PHP 8.x alternatives: `WhichBrowser/Parser`, `matomo/device-detector`, or just `Sec-CH-UA-Mobile` request header.

**Migration:** Mobile-only admin UI shortcuts are largely obsolete (modern admin pages are responsive). Could simply delete the three call sites and the dependency.

### 12.4 `Template/Latte/PiwigoExtension.php` — Smarty Compatibility Layer

The Latte extension contains 28 "Smarty" mentions across ~700 lines. It's a deliberate compatibility layer that ports Smarty filter/block/function semantics to Latte so the converted `.latte` templates can still write `{$x|default:'fallback'}`, `{$str|strip_tags}`, `{$d|date_format:"%Y"}`, `{html_options ...}`, `{math eq=...}`, etc.

This was an intentional design choice in the Smarty → Latte migration: rather than rewriting all 133 templates to native Latte syntax, the converter kept the Smarty surface and PiwigoExtension implements the missing pieces. Filters/tags ported include:

- `default`, `strip_tags`, `date_format`, `cat:`, `number_format`
- `html_options`, `html_radios`, `math` blocks
- `{combineScript}`-style admin asset accumulation
- Smarty's `$pwg->derivative(...)` accessor

**Not strictly removable.** Migrating away means rewriting all 133 .latte templates to native syntax. This is a real shim layer but tracked as a "stable transitional API" — not a removal target.

### 12.5 `PwgImage::__call()` — Magic Strategy Dispatch

`Admin/Image/PwgImage.php:63` exposes a `__call($method, $arguments)` magic method that forwards to whichever backend (`ImageImagick` or `ImageExtImagick`) was selected at construction. This isn't a backwards-compatibility shim — it's a strategy pattern — but the use of `__call` makes the public API opaque to static analysis.

**Not blocking.** Could be replaced with an explicit interface + matching method declarations on `PwgImage` that delegate, but the gain is mostly tooling-visibility.

### 12.6 `xmlrpc_encode()` — Cross-reference

Already covered in §11.5. The PHP `xmlrpc` extension is itself a deprecated vendor shim that the codebase depends on. Worth re-flagging here for completeness: `composer.json` does not declare `ext-xmlrpc` (because it can no longer be statically declared on PHP 8.1+), but `Ws/Protocol/PwgXmlRpcEncoder.php:40` calls `xmlrpc_encode()` directly. Any deployment without the PECL `xmlrpc` package installed will fatal-error on `pwg.xmlrpc` requests.

---

## 13. PHPStan Tooling Stubs & Stale Extensions

A fifth-pass audit on 2026-05-14 read `phpstan.neon`, its bootstrap files, and every rule under `tools/phpstan/`. The static-analysis tooling carries more drift than the production code does:

### 13.1 `tools/phpstan-bootstrap.php` — Stale Global Placeholders

This bootstrap file is referenced from `phpstan.neon` (`bootstrapFiles: - tools/phpstan-bootstrap.php`). It runs only at PHPStan analysis time. Three sections of stale content:

**A. Legacy global placeholders** (lines 14-35) — declares `@var` types for 11 globals so PHPStan can resolve `global $foo` references. 8 of these were already migrated:

| Variable | Status in production code |
|---|---|
| `$prefixeTable` | Active (`§8`) |
| `$user` | **Removed** — typed via `CurrentUser` (§1.6) |
| `$page` | Bridge alias still active (§1.1) |
| `$lang` | **Removed** — `Lang::attachGlobals` unsets it (§1.2) |
| `$template` | **Removed** (§1.3) |
| `$logger` | **Removed** — replaced by `LoggerRegistry` |
| `$filter` | Active (§8) |
| `$pwg_event_handlers` | **Removed** — `EventDispatcher` (§1.5) |
| `$pwg_loaded_plugins` | **Removed** — `LoadedPluginRegistry` (§1.4) |
| `$service` | Now `PwgServerRegistry::current()` |
| `$persistent_cache` | **Deleted entirely** — class deleted in §3 |

**B. Runtime constant duplicates** (lines 37-65) — re-declares 13 `WS_*` constants, plus `IN_ADMIN`, `PHPWG_DOMAIN`, `PHPWG_URL`, `PEM_URL`, `PHOTOS_ADD_BASE_URL`. These are stubs of the runtime defines from §11; PHPStan needs them for static-time const resolution. Not strictly stale, but a parallel maintenance burden — any rename in §11 has to be mirrored here.

**C. Procedural plugin/theme callbacks** (lines 75-103) — stubs `plugin_install`, `plugin_activate`, `plugin_deactivate`, `plugin_uninstall`, `theme_activate`, `theme_deactivate`, `theme_delete`. These are the legacy free-function plugin contract; since the policy is plugins are being rewritten ("v17.0 intentionally breaks all PEM extensions"), the callback contract itself is dead. Stubs can be removed when the plugin-loader path that calls them is removed.

### 13.2 `tools/phpstan/PwgGetSessionVarDynamicReturnType.php` — Dead Extension

The extension teaches PHPStan that `pwg_get_session_var($key, $default)` returns the same type as `$default`. The class docstring (line 17) refers to "`Kernel::service(SessionService::class)->getSessionVar()`" but the extension checks for the **free function** `pwg_get_session_var`:

```
return $functionReflection->getName() === 'pwg_get_session_var';
```

A whole-repo grep finds **zero** call sites of `pwg_get_session_var` outside this file. The function was migrated to `SessionService::getSessionVar()`, but the extension still targets the deleted free function. **Safe to delete the extension and unregister from `phpstan.neon`.**

### 13.3 `tools/phpstan/TriggerChangeDynamicReturnType.php` — Misnamed

Class is called `TriggerChangeDynamicReturnType` — name comes from the legacy `trigger_change()` free function (the plugin-event dispatcher's old name). The implementation actually targets `\Piwigo\Plugins\EventDispatcher::dispatch()`:

```
public function getClass(): string { return \Piwigo\Plugins\EventDispatcher::class; }
public function isStaticMethodSupported(...) { return $methodReflection->getName() === 'dispatch'; }
```

The class is **functionally correct** but the name is a fossil. Rename to `EventDispatcherDispatchDynamicReturnType` (or shorter) to match what it actually does.

### 13.4 `tools/triggers_list.php` — Stale Terminology

1136-line documentation file listing every event the codebase dispatches, intended for plugin authors. Uses legacy `'type' => 'trigger_change'` / `'type' => 'trigger_notify'` terminology that mirrors the legacy free-function names (the modern equivalents are `EventDispatcher::dispatch()` and `EventDispatcher::notify()`).

Also contains 4 references to `'files' => array('include/functions.inc.php', ...)` — pointing at a directory that no longer exists in 16.x.

**Not a runtime shim** — it's a reference doc — but the terminology and dead path references would mislead any plugin author trying to use it. Either delete (plugins are being rewritten) or rename the type strings to match `EventDispatcher` method names.

### 13.5 `set_error_handler(static fn (): bool => true)` — Typed `@` Replacement

10 call sites in `Core/StringUtil.php` (×3), `Admin/AdminService.php` (×3), `Core/Filesystem.php` (×4) use this idiom:

```
set_error_handler(static fn (): bool => true);
try { /* operation that might emit warnings */ }
finally { restore_error_handler(); }
```

This is the **typed replacement** for the `@` error-suppression operator (which is forbidden by `NoErrorSuppressionRule`). It's the recommended pattern, not a shim — flagged here because it's the only error-handling primitive used outside the framework's own pipeline. Adding it to the inventory so future audits don't mistake it for boot-shim code.

---

## 14. Frontend Shims (TypeScript / Latte Templates)

The 16.x rewrite isn't only PHP — the frontend (TypeScript bundles compiled by Vite, ~40 entry points, served via `themes/`) has its own shim layer. Audited 2026-05-14 by reading `src/types/globals.d.ts`, all `themes/**/js/*.ts` files, and Latte template inline-script patterns.

### 14.1 `src/types/globals.d.ts` — Ambient Globals Declaration

109-line file declaring TypeScript ambient globals so the bundles can reference cross-bundle vars without TS errors. The header comment is stale:

> "Ambient globals injected by **Smarty templates** via inline `<script>` tags or exposed to window by other bundles loaded earlier on the same page."

Templates are Latte now, not Smarty — but the inline-script pattern continues. Categories of declared globals:

| Category | Examples | Status |
|---|---|---|
| Template-emitted constants | `pwg_token`, `pwg_root_url`, `cookie_path`, `cookie_domain` | Now populated via `<script type="application/json" id="pwg-page-data">` JSON islands (modern), **not** legacy `<script>var x=...</script>` blocks. Declarations in `globals.d.ts` may be stale. |
| Cross-bundle functions | `pwgBind`, `pwgAddEventListener`, `phpWGOpenWindow`, `pwgToaster`, `popuphelp`, `array_delete`, `str_repeat`, `getRandomInt`, `sprintf` | Active — defined in `themes/_base/js/scripts.ts` and assigned to `window` for cross-bundle access. Names mirror PHP (`array_delete`, `str_repeat`, `sprintf`) because these JS helpers were carried over from the Smarty era. |
| Profile-specific i18n vars | `selected_date`, `no_time_elapsed`, `str_handle_error`, `str_copy_key_secret`, etc. | Set via inline `<script>` from `profile.latte` for `profile.ts` consumption. |
| Globally-exposed classes | `Window.PwgWS`, `Window.LocalStorageCache`, `Window.CategoriesCache`, `Window.TagsCache`, `Window.GroupsCache`, `Window.UsersCache` | Active — these are intentionally hoisted to `window` for cross-bundle reuse. |

**Likely-stale entries** that would need verifying: `var user`, `var preferencesDefaultValues`, `var standardSaveSelector` — `grep` found no consumers in `themes/`.

### 14.2 Pre-Load Auto-Queue Shims (Plugin BC)

A legacy pattern where third-party plugins could inject behaviour *before* the relevant bundle had loaded by pushing onto a global array; the bundle, once loaded, replaces the array with a real object exposing the same `push()` interface and drains the queue. Two such queues remain:

- **`_pwgRatingAutoQueue`** — `themes/_base/js/rating.ts:150` has a comment block: *"Process any legacy `_pwgRatingAutoQueue` queue (plugins may still push to it)."* Drains then redefines `push()` to be a direct rate-call.
- **`SwitchBox`** — `themes/_base/js/switchbox.ts:35` *"Process the legacy queue any caller may have populated before this module loaded."* Same pattern.

These are explicit plugin-backwards-compat shims. Given the v17.0 policy (all plugins are being rewritten, BC is not maintained), both queues could be deleted in a single commit per file once any in-tree caller is migrated.

### 14.3 Window-Global Compatibility Aliases

- `themes/admin/_base/js/albums.ts:522`: `_cont = contEl; // keep global for compatibility` — explicit BC marker for a `window._cont` reference. Likely used by plugin/theme scripts; safe to remove with v17.0.
- `themes/admin/_base/js/batchManagerGlobal.ts:834` references "the legacy `typeof elements` guard" — guards against an older bundle-load order.

### 14.4 PHP-Style Names in JS

`array_delete`, `str_repeat`, `getRandomInt`, `sprintf` (declared in `globals.d.ts`, implemented in `themes/admin/_base/js/common.ts`) are JavaScript reimplementations of PHP functions with PHP names — a holdover from the Smarty era when template variables crossed the PHP/JS boundary with the same names. Native JS has `Array.prototype.splice`, `String.repeat`, `Math.random`, template literals, and (since 2018) most string-format needs are covered by template strings. Cosmetic cleanup target; no functional shim.

### 14.5 `tools/ws/ws.js` & `tools/ws/json-viewer.js` — Already Migrated

The web-services explorer was migrated from jQuery to vanilla ES modules; comments at the top of both files document this:

- `tools/ws/ws.js:2` — *"Vanilla ES module replacement for the original jQuery-based ws.js."*
- `tools/ws/json-viewer.js:1` — *"Vanilla replacement for jquery.json-viewer."*
- `tools/ws/ws.js:406` — *"tipTip — dropped along with jQuery."*

No jQuery remains in the codebase (`package.json` confirms). These are historical doc comments only.

### 14.6 `tools/triggers_list.php` Output Uses jQuery

The static HTML reference page emitted by `tools/triggers_list.php` includes jQuery-based DataTables filter glue at lines 1116-1122. This is the **only** jQuery reference in the project. Since the file is a reference doc (not runtime), and the `<script>` references a CDN-hosted jQuery, this isn't a runtime dependency. But future cleanup could rewrite it as vanilla JS too — or delete the file entirely (the inventory in §13.4 covers the doc-staleness side).

---

## 15. Plugin / Theme Procedural Contract

This is the largest shim system in the codebase and earlier inventory passes did not catalogue it as a shim — it was treated as "plugin loader" rather than backwards-compat. But the contract is purely a legacy Piwigo design: plugins ship as **procedural PHP files** that register handlers into the event dispatcher, and the loader reads file-header comments for metadata. The v17.0 policy ("v17 intentionally breaks all PEM extensions") means this entire contract is removable, but as long as the 16.x line ships, it's load-bearing.

### 15.1 Plugin Loading (`Plugin/PluginService.php`)

`loadPlugin(array $plugin)` (line 32):
- Computes `$fileName = Config::pluginsPath() . $pluginId . '/main.inc.php'`
- `require_once($fileName)` — loads the plugin's procedural entry point
- Plugins are expected to call `EventDispatcher::addListener(...)` during this `require`

`autoupdatePlugin()` (line 44) parses the file header for legacy Piwigo plugin metadata:
- Reads first 10 lines of `main.inc.php`
- Regex-matches `Version:\s*([\w.-]+)`
- This is the **Piwigo plugin header format** — comment-block metadata at the top of `main.inc.php`

If a version bump is detected, `autoupdatePlugin()` loads `maintain.class.php` and instantiates `{plugin_id}_maintain` (dashes replaced with underscores) implementing `PluginMaintain`, then calls `->update($oldVersion, $fsVersion, $errors)`.

### 15.2 Pre-2.7 vs Post-2.7 Branching (`Admin/Plugins.php`)

`buildMaintainClass()` (lines 60-84) has explicit dual-path BC:

```php
// 2.7 pattern (OO only)
if (file_exists($file_to_include.'.class.php')) {
    require_once($file_to_include.'.class.php');
    ...
}
// before 2.7 pattern (OO only)
if (file_exists($file_to_include.'.inc.php')) {
    require_once($file_to_include.'.inc.php');
    ...
}
```

The "pre-2.7" branch is a documented BC path for plugins from before Piwigo 2.7 (released circa 2015). Eleven years of compatibility surface, dead-coded behind v17.0 policy.

### 15.3 Theme Contract (`Admin/Themes.php`)

Themes have a similar contract:
- `buildMaintainClass()` (line 63) requires `<theme>/admin/maintain.inc.php` and instantiates `{theme_id}_maintain` implementing `ThemeMaintain`
- Theme metadata is parsed from `themeconf.inc.php` (line 287, 298) — file-format-encoded as PHP array literals
- Theme archives extracted during install are searched for `themeconf.inc.php` (line 522-523) as the canonical "this is a Piwigo theme" marker
- `admin.inc.php` (line 353) is the optional admin-side theme bootstrap

### 15.4 Lazy-Include Event Handlers (`Plugins/EventDispatcher.php`)

`addListener($event, $func, $priority, ?$include_path)` accepts an optional include path that's `include_once`'d **right before** dispatching the event (lines 86-88, 115-117). This is how plugins can register lightweight stubs at boot and defer loading the heavy implementation file until the event actually fires.

```php
if (isset($handler['include_path']) && $handler['include_path'] !== '') {
    include_once($handler['include_path']);
}
```

Standard event-dispatcher pattern, but PSR-14 dispatchers don't have this — it's a Piwigo-specific shim for the procedural plugin contract.

### 15.5 Procedural Plugin/Theme Callback Stubs

`tools/phpstan-bootstrap.php` (already §13.1 C) stubs the procedural callbacks plugins/themes are expected to define: `plugin_install`, `plugin_activate`, `plugin_deactivate`, `plugin_uninstall`, `theme_activate`, `theme_deactivate`, `theme_delete`. These names come from the legacy contract; the stubs make PHPStan happy on call sites in `Admin/Plugins.php` and `Admin/Themes.php` that look for these functions via `is_callable()`.

### 15.6 Docstring Drift Across `src/`

A `grep -rn 'include/\|admin/'` of `src/` PHP files turns up **18+ docstrings** that describe code as "Replaces the former `include/X.inc.php`" or "Used by `admin/Y.php`". Neither directory exists in 16.x. Affected files include:

`Kernel.php` (4×), `InstallSentinel.php` (2×), `InstallController.php` (2×), `FilterMiddleware.php` (2×), `Config.php`, `WsType.php`, `WsParam.php`, `DerivativeSize.php`, `LanguageStack.php`, `SectionInitializer.php`, `UserBootstrap.php`, `ImageDerivativeController.php`, `HistoryRepository.php` (2×), `CategoryRepository.php`, `UserRepository.php`, `RateRepository.php` (2×), `PermalinkRepository.php`, `Db/SqlExpr.php` ("instead of the legacy"), `Tag/TagRepository.php:95` ("Replaces the legacy `find_tags()`"), `Image/ImageDerivativeContext.php` (former `$page` keys), and `config/routes.php:64, 79` ("former tags.php", "former search.php").

No functional impact — they're docstrings. But misleading to anyone tracing the codebase ("where's `include/section_init.inc.php`?" → "doesn't exist; this is the replacement").

### 15.7 Template Inheritance via `themes/_base`

Not a shim per se, but worth noting: the Latte template system reproduces Piwigo's classic theme-inheritance model (`themes/elegant` extends `themes/_base`; `themes/admin/dark` extends `themes/admin/_base`). The `include/selected_tags.inc.latte` reference at `SelectedTagsRenderer.php:43` is a path **inside the template directory tree** (`themes/_base/template/include/selected_tags.inc.latte`), not a reference to the deleted `include/` root directory. Easy to misread as legacy drift; flagged here so it doesn't trip a future audit.

---

## 16. `Util.php` Kitchen-Sink Class & Legacy v1.x Features

A ninth-pass audit (2026-05-14) opened `src/Piwigo/Core/Util.php` end-to-end and traced its 33 methods to their call sites. The file is the modern wrapper around the legacy `include/functions.inc.php` procedural module — same responsibilities, same naming conventions, same overlapping helpers, just in a class.

### 16.1 `Util.php` — 1058 lines, 33 methods

Method naming is heavily legacy: 11 of 33 methods are prefixed `pwg*` because they were once free functions of the same name. The class spans concerns that would normally be split across half a dozen services:

| Concern | Methods |
|---|---|
| Logging / debug | `pwgLog`, `pwgDebug`, `doLog`, `pwgActivity` |
| CSRF tokens | `getPwgToken`, `checkPwgToken` |
| Execution mutex (lock files) | `pwgUniqueExecBegins`, `pwgUniqueExecIsRunning`, `pwgUniqueExecEnds` |
| HTTP redirects | `redirect`, `redirectHttp`, `redirectHtml` (three variants overlap) |
| Telemetry | `sendPiwigoInfos`, `sendPiwigoInfosRetryLater` |
| Extension enumeration | `getLanguages`, `getPwgThemes`, `checkThemeInstalled`, `getThemeconf` |
| Filesystem | `mkgetdir` (static, with `MKGETDIR_*` flag constants from §11.6) |
| Mobile detection | `mobileTheme`, `getDevice` (uses MobileEsp from §12.3) |
| Input validation | `checkInputParameter` |
| Misc UI | `getPrivacyLevelOptions`, `getIcon`, `createNavigationBar` |
| Ephemeral keys | `getEphemeralKey`, `verifyEphemeralKey` |
| Comment counts | `getNbAvailableComments` |
| Filter state | `getFilterPageValue` |
| Email | `getWebmasterMailAddress` |
| Lounge (timed-publish staging) | `checkLounge` |
| Caddie (legacy) | `fillCaddie` |

**Not strictly removable** — every method has live call sites — but the right modernisation would split `Util` into purpose-specific services (`PwgLogger`, `CsrfService`, `MobileUaService`, `RedirectResponder`, …) and let DI inject only the surface each consumer needs. The current shape is a service-locator anti-pattern: any class that needs `pwgLog` ends up with the entire 1058-line `Util` as a dependency.

### 16.2 The Caddie Feature — Legacy v1.x Cart

The "caddie" was the v1.x precursor to `batch_manager`: users would click photos to add them to a session-scoped collection, then run a bulk operation on the collection. `batch_manager` replaced this UX years ago, but the underlying machinery is fully preserved:

| Surface | Location |
|---|---|
| DB table | `piwigo_caddie` (`element_id`, `user_id`) |
| Typed accessor | `Db\Tables::caddie()` |
| Upgrade constant | `define('CADDIE_TABLE', $prefix.'caddie')` in `UpgradeService.php:53` |
| Web Service API | `pwg.caddie.add` (registered in `WsMethodRegistrar.php:105`) |
| Internal helper | `Util::fillCaddie(array $elementsId)` |
| Callers | `Ws\Method\GeneralEndpoints::262-269` (the `pwg.caddie.add` impl), `Controller\Admin\PhotoController.php:606` (admin "send to caddie" action) |

**Status:** still load-bearing as long as the `pwg.caddie.add` WS API is published. Frontend (modern admin UI) doesn't use the caddie tab anywhere, but third-party scripts and plugins likely still call the WS method. Same v17.0 dead-coding window as the rest of the plugin contract.

### 16.3 Three Overlapping `redirect()` Variants

`Util::redirect()`, `Util::redirectHttp()`, `Util::redirectHtml()` are three sibling redirect helpers with subtly different signatures:

- `redirectHttp(string $url)` — `Location:` header, then `exit()`.
- `redirectHtml(string $url, string $msg = '', int $refreshTime = 0)` — emits a `<meta http-equiv="refresh">` HTML page with optional message and delay.
- `redirect(string $url, string $msg = '', int $refreshTime = 0)` — dispatches between the two based on whether headers can still be sent.

Modernisation target: collapse to a `RedirectResponder` returning PSR-7 responses; `redirectHtml` only exists because some legacy code paths emitted output before deciding to redirect.

### 16.4 Activity Logging via `pwgActivity()`

`Util::pwgActivity(string $object, array|int|string $objectId, string $action, array $details = [])` writes to the `activity` log table. Most callers are admin controllers. The signature (`$object` as free-form string, `$objectId` as union of `array|int|string`) is a legacy holdover from when the activity log accepted whatever the caller felt like writing. Modern code would use a typed `ActivityEvent` enum + DTO.

---

**End of inventory.** Audited 2026-05-14 across nine progressive passes covering: `src/` (every namespace), `tools/`, `tests/`, `install/`, root entry points (`index.php`, `ecs.php`, `migrations.php`, `rector.php`, `bin/piwigo`), `config/` (`container.php`, `routes.php`), Composer + npm dependencies, static-analysis tooling (PHPStan rules/extensions/stubs + Psalm config/stubs), build config (Vite + ESLint + Playwright), frontend TypeScript bundles, all 133 Latte templates (spot-checked), CI workflows (`.github/`), the plugin/theme procedural contract, the `Util.php` kitchen-sink + legacy v1.x features, and stale docstrings throughout.
