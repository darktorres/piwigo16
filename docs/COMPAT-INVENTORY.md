# Compatibility Inventory & Cleanup Roadmap

The 16.x rewrite carries three categories of compatibility surface, listed
here in priority order:

1. **Plugin-facing API** — kept until the v17.0 cutover, then removed wholesale.
2. **Open removal targets** — internal shims that should be cleaned up before v17.
3. **Tooling & documentation drift** — non-functional, fix opportunistically.

A fourth category, **closed migrations**, is recorded for historical context.

**Policy:** v17.0 intentionally breaks all PEM extensions. External plugin
compatibility is NOT a blocker; only in-tree `src/` callers block removal.

Last audited end-to-end: 2026-05-15.

---

## Status at a Glance

| Area | State | Section |
|---|---|---|
| Wave A reference bridges (`$page`, `$lang`, `$template`, `$user`, …) | All 6 removed | [§Z1](#z1-wave-a-reference-bridges) |
| Session handler bridge | Removed | [§Z2](#z2-session-handler-bridge) |
| Legacy cache API (`PersistentCache`) | Removed | [§Z3](#z3-legacy-cache-api) |
| WS backward-compat parameters | Removed | [§Z4](#z4-ws-backward-compat-parameters) |
| `summarized` column lazy guard | Replaced by Doctrine migration | [§Z5](#z5-one-time-db-migration-guard) |
| Plugin config legacy storage format | Moot (no callers) | [§Z6](#z6-plugin-config-legacy-storage) |
| `trigger_error` runtime signals | All converted to typed exceptions / PSR-3 | [§Z7](#z7-trigger_error-runtime-signals) |
| Ad-hoc `$GLOBALS` cross-class channels | Mixed — admin URL channels removed, runtime state channels still active | [§A2](#a2-ad-hoc-globals-cross-class-channels), [§Z8](#z8-globals-channels-closed) |
| Plugin/theme procedural contract (`main.inc.php`, `maintain.class.php`, hook events) | Active until v17.0 | [§P1](#p1-plugintheme-procedural-contract) |
| Plugin event API — 153 hook names | Active until v17.0 | [§P2](#p2-plugin-event-api) |
| Smarty-syntax compatibility layer in Latte | Active (transitional API) | [§P3](#p3-smarty-syntax-compatibility-in-latte) |
| Frontend plugin BC queues | Active until v17.0 | [§P4](#p4-frontend-plugin-bc-queues) |
| Reference bridge alias `SectionInitializer:68` | Removed | [§A1](#a1-page-reference-bridge) |
| Remaining `$GLOBALS` runtime state channels | Removal targets | [§A2](#a2-ad-hoc-globals-cross-class-channels) |
| Legacy `define()` shims (`IN_ADMIN`, `WS_*`, `*_TABLE`, `CURRENT_DATE`, `xmlrpc_encode`) | Removal targets | [§A3](#a3-legacy-define-shims) |
| Legacy vendor dependencies (PclZip, UniversalFeedCreator, MobileEsp) | Removal targets | [§A4](#a4-legacy-vendor-dependencies) |
| `Util.php` kitchen sink + caddie | Removal target | [§A5](#a5-utilphp-kitchen-sink--caddie) |
| Stale comments referencing removed bridges | Doc drift | [§D1](#d1-stale-comments) |
| Psalm config + stubs | Doc drift | [§D2](#d2-psalm-config--stubs) |
| PHPStan bootstrap + dead extensions | Doc drift | [§D3](#d3-phpstan-bootstrap--dead-extensions) |
| Frontend `globals.d.ts` | Doc drift | [§D4](#d4-frontend-globalsdts) |

---

# Part A — Open Removal Targets

These are internal shims that exist for code-organisation reasons (not plugin
compatibility) and could be removed in the 16.x line.

## A1. `$page` Reference Bridge

**Removed** (2026-05-15). Each subsystem now owns its own per-request state:

- `SearchService::$searchDetails`, `$searchId`, `$useRegexpICU` (instance properties); `SearchFilterRenderer` calls `setForbidden()` instead of mutating a shared array; `SectionInitializer` reads `getSearchId()` directly.
- `CalendarService::$chronologyDate`, `$chronologyStyle`, `$chronologyView`, `$items`, `$comment` (instance properties). `CalendarBase` / `CalendarMonthly` operate on `public array $chronologyDate` populated by `CalendarService`. `SectionInitializer::initialize()` passes chronology data in as named parameters and reads results back through `getChronologyDate()` / `getItems()` / etc.
- `UrlService::setMakeFullUrl()` / `unsetMakeFullUrl()` are backed by a private static `$rootPathOverride` + ref count; `getRootUrl()` reads override → `SectionContext::rootPath` → `PHPWG_ROOT_PATH`.
- `AuthService::authKeyLogin()` writes `PageState::current()->authKeyId`; `Util::pwgLog()` reads from the same typed property.
- `PasswordService` no longer writes `username` (dead write — no reader).
- `MaintenanceController::history()` / `GeneralEndpoints::historySearch()` / `UpgradeController` reads of `$page['search']`, `$page['errors']`, `$page['nb_lines']` were dead (different request paths) or replaced by `PageState::current()->errors`.

`SectionInitializer.php:68` (`$GLOBALS['page'] = &$page;`) is gone. The local `$page` array still exists inside `SectionInitializer::initialize()` as scratch space for building the `SectionContext` value object — it is no longer aliased to any global. `tools/phpstan-bootstrap.php` `$page` stub removed; `NoGlobalInSrcRule` GUARDED entry for `page` removed.

## A2. Ad-hoc `$GLOBALS` Cross-Class Channels

Channels still actively written and read across class boundaries. None has a
typed bridge — readers do `is_array($GLOBALS['x'] ?? null) ? … : []` inline.

| Global | Writer(s) | Reader(s) | Removal sketch |
|---|---|---|---|
| `$GLOBALS['filter']` | `CommonBootstrap` (init), `FilterMiddleware` (`&$GLOBALS['filter']`), `SectionInitializer` (reference mutation) | `CategoryService`, `FilterService`, `MenubarRenderer`, `PermissionService`, `CalendarService`, `PictureController` | Promote to typed `FilterContext` VO with registry singleton |
| `$GLOBALS['lang_info']` | `LanguageStack` (set/merge/restore) | `Template:83`, `AdminService:406` | Cross-subsystem read — fold into `Lang` static state |
| `$GLOBALS['header_msgs']` + `$GLOBALS['header_notes']` | `CommonBootstrap`, `CheckIntegrity` | `CommonBootstrap` (template assign), `FilterMiddleware:54` (reference) | Promote to `PageState` typed arrays |
| `$GLOBALS['debug']` + `$GLOBALS['t2']` | `Util::pwgLog`, `CommonBootstrap` | `PageTailRenderer`, `Util` | `t2` → use `$_SERVER['REQUEST_TIME_FLOAT']` (built-in); `debug` → fold into PSR-3 logger via `DebugAccumulatorHandler` |
| `$GLOBALS['prefixeTable']` | `CommonBootstrap:83`, `UpgradeController`, `InstallController`, `index.php:38` (image-derivative fast path), `index.php:66` (upgrade_feed fast path) | `UpgradeService:31`, `MaintenanceService:16` | Use `Config::dbPrefix()` everywhere |
| `$GLOBALS['errors']` | `LocalSiteReader` | `LocalSiteReader` | Self-contained — make instance state |
| `$GLOBALS['themeconfs']` | `Template::loadThemeconf` | `Template::loadThemeconf` | Self-contained — make instance property |
| `$GLOBALS['cache']` | `UserService::getDefaultUserInfo` | `UserService::getDefaultUserInfo` | Self-contained — make instance memo |
| `$GLOBALS['maint_actions']` | `MaintenanceController` | `MaintenanceController` | Self-contained — make instance property |

## A3. Legacy `define()` Shims

105 `define()` calls in `src/` form a parallel shim mechanism the inventory
treats separately from `$GLOBALS`. Five distinct patterns:

### A3.1 Runtime Context Flags

`defined()`-based request context detection — should be PSR-7 request
attributes from a typed `RequestContext` set by the corresponding middleware.

| Flag | Defined in | Read in |
|---|---|---|
| `IN_ADMIN` | `Ws/Method/ExtensionsEndpoints.php:63, 87, 165` | `Page/PageHeaderRenderer.php:30`, `Page/NoPhotoYetRenderer.php:39, 41`, `Users/ProfileService.php:56, 60, 92, 150, 217`, `Core/Util.php:142` |
| `IN_WS` | `Controller/WsController.php:42` | `Users/UserBootstrap.php:89, 119`, `Admin/Upload/UploadService.php:167` |
| `PHPWG_IN_UPGRADE` | `Admin/UpgradeService.php:145, 180`, `Controller/UpgradeController.php` | `Admin/UpgradeService.php:23` (self-contained) |

`NoPhotoYetRenderer:39` already has a `/** @psalm-suppress RedundantCondition
— IN_ADMIN is runtime-set; stub value misleads Psalm */` acknowledging the
smell.

### A3.2 WS Constant Bridges

`PwgServer::boot()` (lines 471-485) defines 13 constants
(`WS_PARAM_*`, `WS_TYPE_*`, `WS_ERR_*`, `WS_XML_ATTRIBUTES`) as a bridge to
the typed `WsParam` and `WsType` enums. Consumed by ~50 call sites in
`Ws/Method/*Endpoints.php`. `WsParam.php:10` docstring still references the
deleted `include/ws_core.inc.php`.

Replace each `WS_ERR_INVALID_PARAM` with `WsError::InvalidParam->value`,
`WS_TYPE_INT` with `WsType::Int->value`, etc., then delete the `define()`
block.

### A3.3 Table-Name Constants

`Admin/UpgradeService.php:33-58` defines 30+ table-name constants
(`CATEGORIES_TABLE`, `IMAGES_TABLE`, …) by string-concatenating the prefix.
Only `UpgradeService.php` reads them inside `src/` — everywhere else uses
`Piwigo\Db\Tables::*()`. Delete once upgrade SQL is rewritten to the typed
API.

### A3.4 `CURRENT_DATE` — Inconsistent Definitions

Defined in **three** places with **two formats**:

- `Admin/Metadata/MetadataAdminService.php:214` → `date('Y-m-d')` (date only)
- `Controller/UpgradeController.php:127` → `date('Y-m-d H:i:s')`
- `Controller/InstallController.php:245` → `date('Y-m-d H:i:s')`

The `defined() or define()` guard means whichever path runs first wins —
**latent bug**. Also conflicts with the SQL keyword `CURRENT_DATE` used as a
string literal in `Db/SqlExpr.php:70, 72, 74`. Pass a `DateTimeImmutable`
through the call chain instead.

### A3.5 `xmlrpc_encode()` — Removed PHP Extension

`Ws/Protocol/PwgXmlRpcEncoder.php:40` calls `xmlrpc_encode($response)`. The
PHP `xmlrpc` extension was deprecated in 8.0 and removed from core in 8.1+.
`pwg.xmlrpc` requests will fatal-error on any modern PHP build without an
explicit PECL install. Either swap for `phpxmlrpc/phpxmlrpc` or drop the
xmlrpc protocol entirely (REST/JSON cover all in-tree callers).

### A3.6 Other (One-Off) `define()` Calls

- `CommonBootstrap.php:174-186`: `PHPWG_DOMAIN`, `PHPWG_URL`, `PEM_URL`
- `CommonBootstrap.php:78`: `PWG_LOCAL_DIR`
- `Core/Util.php:41-45`: `MKGETDIR_*` flag constants

Conventional runtime config, not shims, but contribute to the 105-define
count. Promote `MKGETDIR_*` to a typed enum; replace path/URL defines with
`Config` reads.

## A4. Legacy Vendor Dependencies

### A4.1 PclZip — PHP-4-era Zip Library

`pclzip/pclzip` (in `composer.json`) used in 4 production files instead of
the built-in `ZipArchive`:

| File:Line | Purpose |
|---|---|
| `Admin/Updates.php:600` | Extract Piwigo core update archives |
| `Admin/Plugins.php:549` | Extract plugin archives from PEM |
| `Admin/Languages.php:273` | Extract language archives |
| `Admin/Themes.php:511` | Extract theme archives |

Mechanical translation; `tools/psalm-stubs.phpstub` declares the 20+
`PCLZIP_OPT_*` constants because PclZip defines them lazily.

### A4.2 UniversalFeedCreator — Legacy Feed Library

`openpsa/universalfeedcreator` is in `composer.json`.
`Feed/PiwigoFeedCreator.php` extends `\UniversalFeedCreator` (untyped
2004-era class). Used by `Controller/FeedController.php:78`. Swap for
`SimpleXMLElement` or `laminas/laminas-feed`.

### A4.3 MobileEsp — Mobile UA Detection

`ahand/mobileesp` (`\uagent_info`) is in `composer.json`. Used in three
places for regex-based mobile UA detection:

- `Core/Util.php:452` (`mobile_detect` helper)
- `Controller/Admin/PhotoController.php:614`
- `Controller/Admin/MiscController.php:574`

~2010-era library predating UA Client Hints. Modern admin UI is responsive;
the detection paths are largely obsolete. Likely safe to delete the three
call sites and the dependency outright.

## A5. `Util.php` Kitchen Sink + Caddie

### A5.1 1058-Line "Util" Class

`src/Piwigo/Core/Util.php` is a service-locator anti-pattern: 33 methods
spanning many concerns, 11 prefixed `pwg*` because they were once free
functions of the same name in `include/functions.inc.php`.

| Concern | Methods |
|---|---|
| Logging / debug | `pwgLog`, `pwgDebug`, `doLog`, `pwgActivity` |
| CSRF tokens | `getPwgToken`, `checkPwgToken` |
| Execution mutex | `pwgUniqueExecBegins`, `pwgUniqueExecIsRunning`, `pwgUniqueExecEnds` |
| HTTP redirects | `redirect`, `redirectHttp`, `redirectHtml` (three overlap) |
| Telemetry | `sendPiwigoInfos`, `sendPiwigoInfosRetryLater` |
| Extension enumeration | `getLanguages`, `getPwgThemes`, `checkThemeInstalled`, `getThemeconf` |
| Filesystem | `mkgetdir` |
| Mobile detection | `mobileTheme`, `getDevice` (uses MobileEsp — see [§A4.3](#a43-mobileesp--mobile-ua-detection)) |
| Input validation | `checkInputParameter` |
| Misc UI | `getPrivacyLevelOptions`, `getIcon`, `createNavigationBar` |
| Ephemeral keys | `getEphemeralKey`, `verifyEphemeralKey` |
| Comment counts | `getNbAvailableComments` |
| Filter state | `getFilterPageValue` |
| Email | `getWebmasterMailAddress` |
| Lounge (timed-publish staging) | `checkLounge` |
| Caddie (legacy) | `fillCaddie` |

Modernisation: split into purpose-specific services (`PwgLogger`,
`CsrfService`, `MobileUaService`, `RedirectResponder`, …). Three redirect
helpers should collapse to a single PSR-7 `RedirectResponder`.
`Util::pwgActivity()`'s free-form `(string, array|int|string, string, array)`
signature should become a typed `ActivityEvent` enum + DTO.

### A5.2 Caddie — Legacy v1.x Cart

The "caddie" was the v1.x precursor to `batch_manager`. Replaced years ago
in the UI but the machinery is fully preserved:

| Surface | Location |
|---|---|
| DB table | `piwigo_caddie` (`element_id`, `user_id`) |
| Typed accessor | `Db\Tables::caddie()` |
| Upgrade constant | `define('CADDIE_TABLE', ...)` in `UpgradeService.php:53` |
| WS API | `pwg.caddie.add` (registered in `WsMethodRegistrar.php:105`) |
| Internal helper | `Util::fillCaddie()` |
| Callers | `Ws\Method\GeneralEndpoints:262-269`, `Controller\Admin\PhotoController:606` |

Frontend admin UI no longer surfaces a caddie tab. Removable as a single PR
once we confirm no in-tree caller uses the WS method (third-party callers
break by policy).

---

# Part P — Sustained Plugin-Facing Surface (Until v17.0)

These are deliberate compatibility layers preserved for plugin authors. Per
policy, they break at the v17.0 cutover, not before.

## P1. Plugin/Theme Procedural Contract

The whole legacy Piwigo plugin/theme runtime contract is wired and
load-bearing.

### P1.1 Plugin Loading

`Plugin/PluginService.php:32-41`:

```php
$fileName = Config::pluginsPath() . $pluginId . '/main.inc.php';
if (file_exists($fileName)) {
    $this->autoupdatePlugin($plugin);
    LoadedPluginRegistry::register($pluginId, $plugin);
    require_once($fileName);
}
```

Plugins ship as procedural PHP files. Metadata (`Version: x.y.z`) is parsed
from file-header comments via regex on the first 10 lines
(`PluginService.php:44-63`).

### P1.2 Pre-2.7 vs 2.7+ Branching

`Admin/Plugins.php:60-84` has explicit dual-path BC:

```php
// 2.7 pattern (OO only)
if (file_exists($file_to_include.'.class.php')) { … }
// before 2.7 pattern (OO only)
if (file_exists($file_to_include.'.inc.php')) { … }
```

Eleven years of plugin BC kept alive in the loader. Plugins implement a
`{plugin_id}_maintain` class (dashes-to-underscores) extending
`PluginMaintain`.

### P1.3 Theme Contract

`Admin/Themes.php` mirrors the plugin contract:

- `themeconf.inc.php` — theme metadata, PHP array literals (`Themes.php:287, 298`)
- `admin/maintain.inc.php` — required `ThemeMaintain` class (`Themes.php:63-77`)
- `admin/admin.inc.php` — optional admin bootstrap (`Themes.php:353`)
- Theme archives identified at install time by presence of `themeconf.inc.php` (`Themes.php:522-523`)

### P1.4 Lazy-Include Event Handlers

`EventDispatcher::addListener($event, $func, $priority, ?$include_path)`
(`Plugins/EventDispatcher.php:28`) accepts an optional include path that's
`include_once`'d **right before** dispatching (lines 86-88, 115-117). Standard
PSR-14 dispatchers don't have this; it's Piwigo-specific shim for lazy
plugin loading.

### P1.5 Procedural Callback Contract

Plugins/themes are expected to define free functions: `plugin_install`,
`plugin_activate`, `plugin_deactivate`, `plugin_uninstall`, `theme_activate`,
`theme_deactivate`, `theme_delete`. `tools/phpstan-bootstrap.php` stubs them
so PHPStan resolves the `is_callable()` call sites in `Admin/Plugins.php` /
`Admin/Themes.php`.

## P2. Plugin Event API

`EventDispatcher::dispatch()` / `notify()` is called with **153 unique event
names** across `src/`. Every name is a stable hook PEM plugins subscribe on.

### P2.1 Naming Conventions

| Pattern | Count | Purpose | Examples |
|---|---|---|---|
| `loc_begin_X` / `loc_end_X` | ~30 | Page-lifecycle markers | `loc_begin_index`, `loc_end_page_header` |
| `get_X` | ~50 | Getter hooks, plugins mutate return value | `get_admin_plugin_menu_links`, `get_derivative_url` |
| `render_element_X` | several | Photo-page rendering | `render_element_content`, `render_element_name`, `render_element_description` |
| `batch_manager_X` | several | Batch manager hooks | `batch_manager_perform_filters`, `batch_manager_register_filters` |
| `format_X`, `clean_X`, `combined_X`, `before_X`, `finalize_X` | several each | Domain-specific | `format_exif_data`, `clean_iptc_value`, `combined_script`, `before_send_mail`, `finalize_login` |
| Special | — | Auth, WS gating, derivatives | `user_init`, `ws_invoke_allowed`, `derivative_params_get` |

### P2.2 Reference Documentation

`tools/triggers_list.php` is the 1136-line canonical plugin-author reference
for these events. Each entry is shaped:

```php
array(
  'name'  => 'event_name',
  'type'  => 'trigger_change' | 'trigger_notify',  // → dispatch | notify
  'vars'  => array('php_type', 'var_name', ...),
  'files' => array('src/Piwigo/Controller/Foo.php', ...),
  'infos' => '(optional) plugin-author note',
)
```

The `'type'` values are legacy free-function names — see [§D3](#d3-phpstan-bootstrap--dead-extensions)
for the rename.

### P2.3 Removal at v17.0

Migration path: rename the events to PSR-14 typed events at the v17 cutover,
breaking the legacy names cleanly. Until then, all 153 are part of the
supported API.

## P3. Smarty-Syntax Compatibility in Latte

`src/Piwigo/Template/Latte/PiwigoExtension.php` is a ~700-line
compatibility layer that ports Smarty filter/block/function semantics to
Latte so the 133 converted `.latte` templates can keep using Smarty surface
syntax.

Ported features:

- Filters: `default`, `strip_tags`, `date_format`, `cat:`, `number_format`
- Blocks: `{html_options …}`, `{html_radios …}`, `{math …}`
- Admin asset accumulation: `{combineScript}`-style helpers
- Accessor: Smarty's `$pwg->derivative(...)` over `SrcImage`

**Not strictly removable** — migrating away requires rewriting all 133
templates to native Latte syntax. Tracked as a stable transitional API, not
a removal target.

## P4. Frontend Plugin BC Queues

Two pre-load auto-queue patterns where third-party plugins can inject
behaviour before the relevant bundle has loaded by pushing onto a global
array; the bundle then drains the queue and replaces it with a real object
exposing `push()`.

- **`_pwgRatingAutoQueue`** — drained by `themes/_base/js/rating.ts:150`
  ("Process any legacy `_pwgRatingAutoQueue` queue (plugins may still push to it)")
- **`SwitchBox`** — drained by `themes/_base/js/switchbox.ts:35`

Plus a smaller window-global alias:

- `themes/admin/_base/js/albums.ts:522`: `_cont = contEl; // keep global for compatibility`

Removable as one batch at v17.0.

---

# Part D — Documentation & Tooling Drift

Non-functional cleanup. No runtime impact; do opportunistically.

## D1. Stale Comments

Files with docstrings describing dead bridges or referencing the deleted
`include/` and `admin/` directories.

### D1.1 Comments Asserting Removed Bridges

| File:Line | Stale claim | Reality |
|---|---|---|
| `Users/UserBootstrap.php:23` | "the PSR-15 pipeline has a fully-built `$GLOBALS['user']`" | No `$GLOBALS['user']` write happens |
| `Http/Middleware/AuthMiddleware.php:18, 20` | "Calls `UserBootstrap::bootstrap()` which populates `$GLOBALS['user']`" | Same |
| `Http/Middleware/FilterMiddleware.php:27` | "Runs after AuthMiddleware so that `$GLOBALS['user']`" | Reads `CurrentUser::get()->rawAttributes` now |
| `Config/Config.php:23` | "`$GLOBALS['conf']` reference bridge (attachGlobals) was retired" | True — flagged because it's the last in-tree mention |
| `Config/ConfigStorage.php:27` | "Bulk read from the conf table into `$GLOBALS['conf']`" | Bulk read populates `Config::$data` only |
| `Lang/Translator.php:99` | "restores from the stack top (so `$GLOBALS['lang']` takes over)" | `$GLOBALS['lang']` unset at boot |
| `Core/LanguageStack.php:34` | mentions `$GLOBALS['language_files']` | Only mention in codebase |
| `Users/CurrentUser.php:21` | Method named `attachGlobals` | Body no longer touches `$GLOBALS` |

### D1.2 References to Deleted Directories

Eighteen `src/` files have docstrings of the form "Replaces the former
`include/X.inc.php`" or "Used by `admin/Y.php`". Affected:

`Kernel.php` (4×), `InstallSentinel.php` (2×), `InstallController.php` (2×),
`FilterMiddleware.php` (2×), `Config.php`, `WsType.php`, `WsParam.php`,
`DerivativeSize.php`, `LanguageStack.php`, `SectionInitializer.php`,
`UserBootstrap.php`, `ImageDerivativeController.php`, `HistoryRepository.php`
(2×), `CategoryRepository.php`, `UserRepository.php`, `RateRepository.php`
(2×), `PermalinkRepository.php`, `Db/SqlExpr.php`, `Tag/TagRepository.php:95`,
`Image/ImageDerivativeContext.php`, `config/routes.php:64, 79`.

### D1.3 Caveat: `include/` as a Template Subdirectory

`SelectedTagsRenderer.php:43` assigns the template path
`include/selected_tags.inc.latte`. This is **inside the template tree**
(`themes/_base/template/include/...`), not a reference to the deleted root
`include/`. Easy to misread; flagged so it doesn't trip a future audit.

## D2. Psalm Config & Stubs

### D2.1 `psalm.xml`

Two stale comments around suppression rules (lines 30, 33-34):

- "Legacy globals used in bootstrapped files — not actionable yet"
- "Reference assignments to `$GLOBALS` / static properties are intentional legacy-compatibility bridges; Psalm cannot analyze them but they are correct."

Suppression itself is still needed (one reference assignment remains, in
[§A1](#a1-page-reference-bridge)), but the framing is outdated.

### D2.2 `tools/psalm-stubs.phpstub`

223-line stub file declaring runtime constants and extension classes
(Imagick, Redis, Relay, Predis, IntlDateFormatter) for Psalm. Lines 6-9
contain a stub for `xmlrpc_encode()` with the comment *"The xmlrpc extension
is deprecated/removed in modern PHP builds but still used in
`PwgXmlRpcEncoder`"* — the only in-tree acknowledgement of the §A3.5 shim.

## D3. PHPStan Bootstrap & Dead Extensions

### D3.1 `tools/phpstan-bootstrap.php` — Stale Placeholders

`bootstrapFiles` in `phpstan.neon`. Three sections of stale content:

**A. Legacy global `@var` placeholders** (lines 14-35) — 11 globals declared,
8 already removed:

| Variable | Code status |
|---|---|
| `$prefixeTable` | Active (§A2) |
| `$user` | Removed (§Z1) |
| `$page` | Removed (§Z1.1) |
| `$lang` | Removed (§Z1) |
| `$template` | Removed (§Z1) |
| `$logger` | Removed — replaced by `LoggerRegistry` |
| `$filter` | Active (§A2) |
| `$pwg_event_handlers` | Removed (§Z1) |
| `$pwg_loaded_plugins` | Removed (§Z1) |
| `$service` | Now `PwgServerRegistry::current()` |
| `$persistent_cache` | Class deleted (§Z3) |

**B. Runtime constant duplicates** (lines 37-65) — re-declares 13 `WS_*`
constants plus `IN_ADMIN`, `PHPWG_DOMAIN`, `PHPWG_URL`, `PEM_URL`,
`PHOTOS_ADD_BASE_URL`. Parallel maintenance burden — any rename in §A3 has
to be mirrored here.

**C. Procedural plugin/theme callback stubs** (lines 75-103) —
`plugin_install`, `plugin_activate`, `plugin_deactivate`, `plugin_uninstall`,
`theme_activate`, `theme_deactivate`, `theme_delete`. Required by §P1.5.

### D3.2 `tools/phpstan/PwgGetSessionVarDynamicReturnType.php` — Dead

The extension targets a free function `pwg_get_session_var()` that no longer
exists. Whole-repo grep finds zero call sites — `SessionService::getSessionVar()`
replaced it. **Safe to delete the extension and unregister from
`phpstan.neon`.**

### D3.3 `tools/phpstan/TriggerChangeDynamicReturnType.php` — Misnamed

Class name fossilizes the legacy `trigger_change()` free function; the
implementation correctly targets `\Piwigo\Plugins\EventDispatcher::dispatch()`.
Functionally correct, but the name lies. **Rename to
`EventDispatcherDispatchDynamicReturnType` (mechanical).**

### D3.4 `tools/phpstan/NoGlobalInSrcRule.php` — Stale Replacement Advice

Class docblock (line 18) claims "Legacy code in `include/` and `admin/` is
allowed to keep using globals" — neither directory exists. `REPLACEMENTS`
map (lines 31-48) has stale entries:

- `'persistent_cache' => 'PersistentCacheRegistry::current()'` — class deleted in §Z3
- `'header_notes' => '$GLOBALS[\'header_notes\'] reference-bridge'` — wrong message
- `'themeconfs'`, `'filter'` — descriptions are self-referential

### D3.5 `tools/triggers_list.php` — Stale Terminology

1136-line plugin-author reference. Uses legacy `'type' => 'trigger_change'` /
`'type' => 'trigger_notify'` strings that mirror the legacy free-function
names (the modern equivalents are `EventDispatcher::dispatch()` and
`EventDispatcher::notify()`). Also has 4 references to
`'files' => array('include/functions.inc.php', ...)` pointing at a directory
that no longer exists.

Rename the type strings to match `EventDispatcher` method names. The event
names themselves are the API surface ([§P2](#p2-plugin-event-api)) and can't
be touched without breaking plugins.

## D4. Frontend `globals.d.ts`

`src/types/globals.d.ts` is the TypeScript ambient-globals declaration file
(109 lines). Header comment says "Smarty templates" (stale — we're on Latte).

Categories:

| Category | Status |
|---|---|
| Template-emitted constants (`pwg_token`, `pwg_root_url`, `cookie_*`) | Now populated via `<script type="application/json" id="pwg-page-data">` JSON islands, not inline `var x = …` blocks. Declarations may be stale. |
| Cross-bundle functions (`pwgBind`, `pwgAddEventListener`, `pwgToaster`, `phpWGOpenWindow`, `popuphelp`) | Active — defined in `themes/_base/js/scripts.ts` and hoisted to `window` |
| PHP-style JS helpers (`array_delete`, `str_repeat`, `getRandomInt`, `sprintf`) | Implemented in `themes/admin/_base/js/common.ts`; cosmetic Smarty-era naming |
| Profile-specific i18n vars (`selected_date`, `no_time_elapsed`, `str_*`) | Set via inline `<script>` from `profile.latte` |
| `Window.PwgWS`, `Window.LocalStorageCache` and subclasses | Active — intentionally hoisted for cross-bundle reuse |
| Likely-stale (no grep hits in `themes/`) | `var user`, `var preferencesDefaultValues`, `var standardSaveSelector` |

**Already cleaned (FYI):** `tools/ws/ws.js` and `tools/ws/json-viewer.js` are
vanilla ES module rewrites of jQuery-based originals. No runtime jQuery
anywhere (`package.json` confirms). `tools/triggers_list.php` outputs a
reference page using jQuery-on-CDN for DataTables glue — only jQuery
reference in the project, not runtime.

---

# Part Z — Closed Migrations (Historical)

Items completed earlier in the 16.x branch, kept for context.

## Z1. Wave A Reference Bridges

| § | Global | Notes |
|---|---|---|
| §Z1.1 | `$GLOBALS['page']` | Service-owned state across `SearchService`, `CalendarService`, `CalendarBase`, `CalendarMonthly`, `UrlService`, `AuthService` (via `PageState::current()->authKeyId`); dead writes removed in `PasswordService`, `MaintenanceController`, `GeneralEndpoints`, `UpgradeController`. Alias at `SectionInitializer:68` deleted. |
| §Z1.2 | `$GLOBALS['lang']` | `Lang::attachGlobals()` snapshots once at boot then `unset()`s the global. Stale comment at `Translator.php:99` — see [§D1.1](#d11-comments-asserting-removed-bridges). |
| §Z1.3 | `$GLOBALS['template']` | `TemplateRegistry::set()` write removed; readers migrated to `TemplateRegistry::current()`. |
| §Z1.4 | `$GLOBALS['pwg_loaded_plugins']` | Bridge removed from `LoadedPluginRegistry::init/reset`. 3 callers migrated (MiscController, BatchManagerController, ExtensionsController). |
| §Z1.5 | `$GLOBALS['pwg_event_handlers']` | Bridge removed from `EventDispatcher::init/reset`. |
| §Z1.6 | `$GLOBALS['user']` | All 30+ caller files migrated to `CurrentUser::get()->rawAttributes`. `UserBootstrap`, `AuthService`, `CurrentUser::setLanguage()`/`setRawAttributes()` no longer write `$GLOBALS['user']`. `CurrentUser::attachGlobals()` retained as misnomer (creates guest singleton) — see [§D1.1](#d11-comments-asserting-removed-bridges). |

## Z2. Session Handler Bridge

`Session/PwgSession.php` deleted. `SessionService` implements
`\SessionHandlerInterface` directly (`open`/`close`/`read`/`write`/`destroy`/`gc`).
`SessionBootstrap` passes `Kernel::service(SessionService::class)` to
`session_set_save_handler()`.

## Z3. Legacy Cache API

`PersistentCache`, `PersistentFileCache`, `PersistentCacheRegistry` deleted.
All 10 call-sites migrated to direct PSR-6 `CacheItemPoolInterface`
injection. `CacheFactory::create()` provides the pool via DI;
`makeKey(string|array $key)` inlined as `md5($key . AppInfo::VERSION)` for
per-version invalidation. `PersistentFileCacheTest` deleted.

## Z4. WS Backward-Compat Parameters

No client compatibility maintained:

- `pwg.images.addChunk`: `type` param removed; chunk filename hardcoded
- `pwg.images.addFile`: `type` param removed; size-check always runs
- `pwg.images.add`: `thumbnail_sum`/`high_sum` params removed
- `pwg.images.checkFiles`: only `file_sum` comparison remains

## Z5. One-Time DB Migration Guard

Lazy `history_summarized_dropped` runtime guard replaced by Doctrine
migration `Version20260514000001` that drops the column via schema
introspection (handles fresh 16.x installs and upgraded installs uniformly).
`HistoryAdminService::historyRemoveSummarizedColumn()` and its two
call-sites deleted; `summarizedColumnExists()`/`dropSummarizedColumn()` in
`HistoryRepository` deleted; `Config::historySummarizedDropped()` and the
SCHEMA entry deleted.

## Z6. Plugin Config Legacy Storage

All plugins removed — `plugins/` is now a redirect stub. Four bundled
plugin typed-config facades (`NbcThemeChanger`, `LocalFilesEditor`,
`PiwigoOpenstreetmap`, `PiwigoVideojs`) in `src/Piwigo/Plugins/` deleted along
with `LocalFilesEditorConfigTest`. `nbc_ThemeChanger` config rows may exist
in existing databases but the code that reads/writes them is gone — no
migration needed.

## Z7. `trigger_error` Runtime Signals

All eliminated. Conversions:

| File | Replacement type |
|---|---|
| `Controller/Admin/AlbumController.php`, `Category/CategoryService.php`, `Html/HtmlService.php`, `Admin/Users/UserAdminService.php`, `Admin/Category/CategoryAdminService.php`, `Url/UrlService.php` | `InvalidArgumentException` |
| `Template/ScriptLoader.php`, `Picture/PictureCommentRenderer.php`, `Controller/CommentsController.php`, `Controller/PictureController.php`, `Ws/Protocol/PwgRestEncoder.php` | `LogicException` |
| `Admin/Image/ImageAdminService.php`, `Ws/Method/ImagesEndpoints.php` | `RuntimeException` |
| `Mail/MailService.php` | `LoggerRegistry::current()->warning()` (PHPMailer failure is runtime infrastructure) |
| `Admin/Image/ImageExtImagick.php` | Removed entirely (stderr already captured by `$logger->error()`) |

## Z8. `$GLOBALS` Channels Closed

Cross-class admin URL channels eliminated as of 2026-05-14;
`CoreTabsRegistrar` takes no `$GLOBALS` reads.

| Channel | Closure |
|---|---|
| `$GLOBALS['url_self']` | Renderers call `$this->urlService->duplicatePictureUrl()` |
| `$GLOBALS['related_categories']` | Added `relatedCategories` to `PictureContext` |
| `$GLOBALS['picture']` | Added `ratingScore`, `srcImage` to `PictureContext` |
| `$GLOBALS['link_start']` + `$GLOBALS['conf_link']` | `CoreTabsRegistrar` calls `$ug->admin('page')` directly |
| `$GLOBALS['manager_link']` | `$ug->admin('batch_manager').'&mode='` |
| `$GLOBALS['base_url']` | `$ug->admin('notification_by_mail')` |
| `$GLOBALS['admin_photo_base_url']` | Read from `$_GET['image_id']` directly |
| `$GLOBALS['admin_album_base_url']` | Read from `$_GET['cat_id']` directly |
| `$GLOBALS['my_base_url']` | `$ug->admin('pagename')` per case; writes removed from 6 controllers; `UrlGenerator` dependency dropped from `UserTabRenderer` and `AlbumsTabRenderer` |
| `$GLOBALS['logger']` | `LoggerRegistry::set()` no longer mirrors |
| `$GLOBALS['help_link']`, `$GLOBALS['current_release']` | Dead reads removed |
| `$GLOBALS['category']`, `$GLOBALS['upload_form_config']` | Dead writes removed |

---

# Sequencing Plan

Each task below is annotated with its **prerequisites** (must finish first)
and **enables** (work that becomes easier or unblocked afterward). Most
tasks are genuinely independent; the dependencies that exist are concentrated
around tooling-stub updates (which must follow the runtime change they
describe) and composer dependency removal (which requires all call sites
migrated first).

## Pattern: Three-Phase Cleanup

Most §A removal targets follow the same three-phase shape:

1. **Migrate call sites** in `src/` to the typed replacement.
2. **Remove the runtime shim** (the `define()` block, the GLOBALS write, or
   the composer dependency).
3. **Clean up the static-analysis stubs** that described it
   (`tools/phpstan-bootstrap.php`, `tools/psalm-stubs.phpstub`, the
   `NoGlobalInSrcRule` GUARDED/REPLACEMENTS maps).

Phase 3 always follows phase 2 (PHPStan and Psalm read the stubs at analyze
time; removing a stub before the runtime is gone breaks the analyzer).

## Phase 1 — Standalone Doc & Tooling Cleanup

No code dependencies. All independent of each other and of every §A item.

| Task | Prereq | Effort |
|---|---|---|
| Delete `PwgGetSessionVarDynamicReturnType` extension (§D3.2) | None | trivial |
| Rename `TriggerChangeDynamicReturnType` → `EventDispatcherDispatchDynamicReturnType` (§D3.3) | None — implementation already targets the new method | trivial |
| Fix `NoGlobalInSrcRule.php` REPLACEMENTS for *dead* entries: `persistent_cache` only (§D3.4) | None — class is already deleted | trivial |
| Fix stale comments in `UserBootstrap`, `AuthMiddleware`, `FilterMiddleware`, `Config`, `ConfigStorage`, `Translator`, `LanguageStack`, `CurrentUser` (§D1.1) | None — these assert about already-completed migrations | trivial |
| Fix stale `include/`/`admin/` directory references in 22 files (§D1.2) | None — docstrings only | small |
| Fix `psalm.xml` "legacy-compatibility bridges" comment (§D2.1) | None | trivial |
| Rename `'trigger_change'`/`'trigger_notify'` `type` strings in `tools/triggers_list.php` to match modern `dispatch`/`notify` (§D3.5); fix 4 `include/functions.inc.php` path references | None — reference doc, the event names themselves are untouched | small |
| Remove resolved-bridge `@var` placeholders from `tools/phpstan-bootstrap.php`: `$user`, `$lang`, `$template`, `$logger`, `$pwg_event_handlers`, `$pwg_loaded_plugins`, `$service`, `$persistent_cache`, `$page` (§D3.1A subset — 9 of 11; `$page` removed 2026-05-15) | None — corresponding code is already gone (§Z1, §Z1.1, §Z3, plus `$logger`→`LoggerRegistry`, `$service`→`PwgServerRegistry`) | trivial |

> Do NOT touch `NoGlobalInSrcRule` entries for `cache`, `themeconfs`, `filter`, `page`, `header_notes` yet — those globals are still active (see Phase 3/5). Only `persistent_cache` is dead-code at this point.

## Phase 2 — Mechanical Translations (Parallelisable)

Each task here is internally a three-phase sequence (call sites → runtime
shim → stubs). The four tasks are independent of each other and can be done
in any order.

### Phase 2a — `WS_*` constant migration (§A3.2)

1. Migrate ~50 call sites in `Ws/Method/*Endpoints.php`,
   `Ws/Protocol/PwgRestRequestHandler.php`, `Ws/WsHelper.php` from
   `WS_ERR_INVALID_PARAM` etc. to `WsError::InvalidParam->value` /
   `WsType::Int->value` / `WsParam::Optional->value`.
2. Delete the `define()` block in `PwgServer::boot()` lines 471-485.
3. Delete WS_* stubs from `tools/phpstan-bootstrap.php` and
   `tools/psalm-stubs.phpstub`.
4. Update `WsParam.php:10` docstring (currently references the deleted
   `include/ws_core.inc.php`).

**Enables:** Cleaner WS layer; nothing else hard-depends on this.

### Phase 2b — `*_TABLE` constant migration (§A3.3)

1. Rewrite legacy upgrade SQL in `Admin/UpgradeService.php` to use
   `Piwigo\Db\Tables::*()` accessors.
2. Delete the 30+ `define()` calls in `UpgradeService.php:33-58`.
3. Delete `*_TABLE` stubs from `tools/phpstan-bootstrap.php` and
   `tools/psalm-stubs.phpstub`.

**Enables:** Simpler caddie removal (§A5.2) because `CADDIE_TABLE` goes with
this batch.

### Phase 2c — `PclZip` → `ZipArchive` (§A4.1)

1. Rewrite 4 call sites: `Admin/Updates.php:600`, `Admin/Plugins.php:549`,
   `Admin/Languages.php:273`, `Admin/Themes.php:511`.
2. Remove `pclzip/pclzip` from `composer.json` (hard dep: all 4 must be
   migrated first).
3. Delete `PCLZIP_OPT_*` stubs (~20 constants) from
   `tools/psalm-stubs.phpstub`.

### Phase 2d — `xmlrpc_encode()` removal (§A3.5)

1. Delete `Ws/Protocol/PwgXmlRpcEncoder.php`.
2. Remove the `xmlrpc` case from encoder selection in `PwgServer.php:522`.
3. Delete the `xmlrpc_encode` stub from `tools/psalm-stubs.phpstub` lines 6-9.

### Phase 2e — MobileEsp removal (§A4.3)

**Precondition** (not yet verified): the 3 call sites assume mobile UA
detection is needed to switch theme/UI. The frontend is meant to be
responsive, but this hasn't been audited against the actual call sites.

1. **Audit first**: confirm the modern admin UI works on mobile viewports
   without the `Util::mobileTheme()` / `getDevice()` branches.
   `tests/e2e/14-admin-extended-smoke.spec.ts` and friends can be run with
   a mobile viewport.
2. **If the UI is fully responsive** (likely): delete the 3 call sites,
   delete `Util::mobileTheme()` and `Util::getDevice()` (no other callers),
   remove `ahand/mobileesp` from `composer.json`.
3. **If mobile branches still serve a purpose**: migrate to the
   `Sec-CH-UA-Mobile` request header (modern UA Client Hints) read from
   the PSR-7 request — keep the behaviour, drop the regex library.

**Enables:** Util.php split (§A5.1) loses two methods, becoming smaller.

### Phase 2f — `CURRENT_DATE` inconsistency (§A3.4)

**Proper fix** — eliminate the global constant entirely:

1. Define a `Piwigo\Core\RequestClock` service that holds one
   `DateTimeImmutable` for the request and exposes `->now()` and
   `->format(string $fmt)`. Inject via DI.
2. Rewrite `Admin/Metadata/MetadataAdminService.php:214`,
   `Controller/UpgradeController.php:127`, and
   `Controller/InstallController.php:245` to read from `RequestClock`
   instead of `define('CURRENT_DATE', …)`. Each call site picks its own
   format — no shared format means no inconsistency.
3. Delete all three `define('CURRENT_DATE', …)` calls.
4. Disambiguate from the SQL string literal `'CURRENT_DATE'` in
   `Db/SqlExpr.php:70, 72, 74` — that's the SQL keyword, not the PHP
   constant. An inline `// SQL keyword, not the PHP constant` comment is
   enough.

> **Workaround alternative (do not use unless RequestClock is too
> invasive):** pick one canonical format and standardise all three
> `define()` calls. This keeps the global state but at least makes the
> three writers consistent. Smaller diff but doesn't actually solve the
> shared-state problem.

Latent bug, not a shim. Worth doing because the inconsistent formats
silently no-op via the `defined() or define()` guard.

### Phase 2g — UniversalFeedCreator removal (§A4.2)

1. Rewrite `Feed/PiwigoFeedCreator.php` to emit RSS/Atom directly with
   `SimpleXMLElement` (PHP built-in), or replace with `laminas/laminas-feed`.
2. Update `Controller/FeedController.php:78` if the constructor signature
   changes.
3. Remove `openpsa/universalfeedcreator` from `composer.json`.

Mechanical translation, no other dependencies.

### Phase 2h — Other one-off `define()` polish (§A3.6) — *optional*

`PHPWG_DOMAIN`, `PHPWG_URL`, `PEM_URL` (`CommonBootstrap.php:174-186`) are
locale-derived strings; could become `Config` reads. `PWG_LOCAL_DIR`
(`CommonBootstrap.php:78`) is a constant path. `MKGETDIR_*`
(`Core/Util.php:41-45`) flag constants could be promoted to a typed enum
that the new `Filesystem` service (Phase 5 Util split) consumes.

Conventional runtime config, not shims. Defer unless touching the
surrounding code anyway.

## Phase 3 — Direct `$GLOBALS` Migrations (No New VO)

Channels where the fix is a straight read-through to a typed accessor or an
instance property — no new value object needed. All independent of each
other.

### Phase 3a — Self-contained (writer == reader class)

| Task | Sub-steps |
|---|---|
| `$GLOBALS['errors']` → `LocalSiteReader::$errors` (§A2) | Migrate `LocalSiteReader.php:35-38`; drop `errors` from `NoGlobalInSrcRule` GUARDED |
| `$GLOBALS['themeconfs']` → `Template::$themeconfs` (§A2) | Migrate `Template::loadThemeconf()` (line 485); drop `themeconfs` from GUARDED |
| `$GLOBALS['cache']` → `UserService::$defaultUserCache` (§A2) | Migrate `UserService::getDefaultUserInfo()` (line 407); drop `cache` from GUARDED |
| `$GLOBALS['maint_actions']` → `MaintenanceController::$maintActions` (§A2) | Already an instance prop; remove the GLOBALS mirror (line 158) |

### Phase 3b — Cross-class, but existing typed accessor available

| Task | Sub-steps |
|---|---|
| `$GLOBALS['prefixeTable']` → `Config::dbPrefix()` (§A2) | Migrate the 2 readers (`UpgradeService:31`, `MaintenanceService:16`); delete the 5 writes (`CommonBootstrap:83`, `UpgradeController`, `InstallController`, `index.php:38`, `index.php:66`); drop `prefixeTable` from `phpstan-bootstrap.php` |
| `$GLOBALS['t2']` → `$_SERVER['REQUEST_TIME_FLOAT']` (§A2) | PHP populates `$_SERVER['REQUEST_TIME_FLOAT']` natively. Replace the `CommonBootstrap:57` write and `Util:107, 502` + `PageTailRenderer:61` reads with that. Delete the global entirely. **Proper fix — eliminates the custom timing mechanism, no relocation.** |
| `$GLOBALS['debug']` → PSR-3 logger (§A2) | The codebase already has `LoggerRegistry::current()` with Monolog. `Util::pwgLog()` writes an HTML string into `$GLOBALS['debug']`; `PageTailRenderer:55` reads it back to render a debug panel. Replace the string accumulator with `$logger->debug(...)` calls and either (a) delete the debug-panel render if it duplicates Monolog's output, or (b) add a `DebugAccumulatorHandler` that captures log records and exposes them to `PageTailRenderer`. **Proper fix — folds custom mechanism into the existing typed logger.** Coordinated with Phase 5 Util split. |

Phase 3b sits between Phase 3a (trivial) and Phase 4 (new VO required) —
each task is a 10-30-line change across 2-5 files.

## Phase 4 — Cross-Class Typed Contexts

Each task touches multiple files and introduces a new typed VO/middleware.
Independent of each other (and of Phases 1-3).

### Phase 4a — `$GLOBALS['filter']` → `FilterContext` VO (§A2)

1. Define `FilterContext` VO + registry (mirror `SectionContext` pattern).
2. Migrate `CommonBootstrap` (init), `FilterMiddleware` (write),
   `SectionInitializer:72` (reference mutation).
3. Migrate readers: `CategoryService`, `FilterService`, `MenubarRenderer`,
   `PermissionService`, `CalendarService`, `PictureController`.
4. Remove `$filter` from `tools/phpstan-bootstrap.php`; drop `'filter'`
   from `NoGlobalInSrcRule` GUARDED.

**Enables:** Util.php split (§A5.1) — several `Util::*` methods read
`$GLOBALS['filter']`; with a typed `FilterContext`, those become DI
injections.

### Phase 4b — `$GLOBALS['header_*']` → `PageState` properties (§A2)

1. Add `PageState::$headerMessages` and `$headerNotes` as typed arrays.
2. Migrate `CommonBootstrap` writes (lines 72-73, 264, 268, 284, 296, 299).
3. Migrate `CheckIntegrity:72` write.
4. Migrate `FilterMiddleware:54` (reference) and `CommonBootstrap:291-292`
   (template assign).
5. Drop `header_notes` from `NoGlobalInSrcRule` GUARDED.

### Phase 4c — `IN_ADMIN`/`IN_WS`/`PHPWG_IN_UPGRADE` → typed `RequestContext` (§A3.1)

1. Define `RequestContext` enum (`Admin`, `Ws`, `Upgrade`, `Gallery`,
   `Derivative`) + PSR-7 request attribute.
2. Middleware sets the attribute per route.
3. Migrate read sites: `Page/PageHeaderRenderer.php:30`,
   `Page/NoPhotoYetRenderer.php:39, 41`,
   `Users/ProfileService.php:56, 60, 92, 150, 217`, `Core/Util.php:142`,
   `Users/UserBootstrap.php:89, 119`,
   `Admin/Upload/UploadService.php:167`.
4. Delete `define('IN_ADMIN', true)` at `ExtensionsEndpoints.php:63, 87, 165`
   and `define('IN_WS', true)` at `WsController.php:42`.
5. Remove `IN_ADMIN`/`IN_WS` stubs from `tools/phpstan-bootstrap.php`.
6. `PHPWG_IN_UPGRADE` is self-contained in `UpgradeService` — collapse to a
   private static property.

### Phase 4d — `$GLOBALS['lang_info']` → `Lang` static state (§A2)

1. Add `Lang::$langInfo` typed static property + accessor methods
   (`Lang::langInfo(): array<string,string>`,
   `Lang::setLangInfo(array)`, `Lang::mergeLangInfo(array)`).
2. Migrate writers: `LanguageStack:115, 122, 195` (set, merge, restore from
   stack top) to call the new `Lang` methods.
3. Migrate readers: `Template:83`, `AdminService:406`.
4. Drop `lang_info` from `NoGlobalInSrcRule` GUARDED (if listed).

Cross-subsystem read (template + admin both depend on the language-stack
writer), so it's a true Phase-4 task. Independent of 4a/4b/4c.

## Phase 5 — Large Refactors (Ordered)

These have soft dependencies on earlier phases. The dependencies are not
hard correctness requirements but make each step smaller / less risky.

```
Phase 2e (MobileEsp)        ──┐
Phase 4a ($filter context)  ──┼──→  §A5.1 Util.php split
                              ┘
Phase 2b (*_TABLE migration) ──→  §A5.2 caddie retirement
                                  └──→ also retires CADDIE_TABLE define
§A1 service-owned state — done 2026-05-15, out of recommended order
                          (Phase 4a would have shrunk the surface but
                           wasn't strictly required; see §Z1.1)
```

### §A5.1 — Split `Util.php` (recommended order after Phase 2e + 4a)

Carve out (names deliberately drop the `pwg` prefix — those are the legacy
free-function names §16 calls out as the smell):

- `ActivityLogger` (`pwgLog`, `pwgActivity`, `doLog` — these all write to the activity log table; merge with each other). The PSR-3 `LoggerRegistry`/`Logger` covers application-level logging; `ActivityLogger` is specifically the user-visible activity feed.
- `DebugCollector` (`pwgDebug`) — coordinated with the Phase 3b `$GLOBALS['debug']` fold into PSR-3. If Phase 3b adds a `DebugAccumulatorHandler` to Monolog, this class becomes a thin facade or goes away.
- `CsrfService` (`getPwgToken`, `checkPwgToken`)
- `ExecutionMutex` (`pwgUniqueExecBegins` / `IsRunning` / `Ends`) — rename methods to `acquire` / `isHeld` / `release`.
- `RedirectResponder` returning PSR-7 `ResponseInterface` (collapses `redirect` / `redirectHttp` / `redirectHtml` to one method that picks header vs HTML body based on whether headers can still be sent).
- `TelemetryService` (`sendPiwigoInfos`, `sendPiwigoInfosRetryLater`).
- Extension enumeration helpers move to `ThemeService` and `LanguageService` (mirroring `PluginService`).
- `mkgetdir` → `Symfony\Component\Filesystem` — we already require `league/flysystem`, so the `Filesystem` component or a thin Flysystem wrapper subsumes it. Promote `MKGETDIR_*` flags (§A3.6) to a typed enum if any callers still need flag combinations; otherwise inline the defaults.

Update `Util::pwgActivity` signature to typed `ActivityEvent` enum + DTO at
the same time. The current `(string $object, array|int|string $objectId,
string $action, array $details = [])` union-type signature is itself a smell.

> **Don't do**: name the carved-out class `PwgLogger`, `PwgCsrf`, etc. Those names preserve the legacy `pwg*` prefix the inventory just spent paragraphs explaining are the symptom of `include/functions.inc.php` heritage. The point of the split is to leave that behind.

### §A5.2 — Retire `caddie` (after Phase 2b)

1. Confirm no in-tree caller uses `pwg.caddie.add` (frontend admin UI
   doesn't surface a caddie tab; third-party usage breaks by policy).
2. Delete WS method registration in `WsMethodRegistrar.php:105`.
3. Delete `Util::fillCaddie()` and call sites in
   `Ws\Method\GeneralEndpoints:262-269` and
   `Controller\Admin\PhotoController:606`.
4. Doctrine migration: `DROP TABLE piwigo_caddie`.
5. Delete `Db\Tables::caddie()` accessor.
6. Phase 2b already deleted the `CADDIE_TABLE` constant.

### §A1 — Eliminate the `&$GLOBALS['page']` Alias

**Done** (2026-05-15) — service-owned state shipped. See [§Z1.1](#z1-wave-a-reference-bridges) for the closure record. Key class changes:

- `SearchService` (no longer `readonly`): `$searchDetails`, `$searchId`, `$useRegexpICU` instance state; `setSearchDetails`/`setForbidden`/`getSearchDetails`/`getSearchId` accessors. `SearchFilterRenderer` calls `setForbidden()` instead of mutating a shared array via reference.
- `CalendarService` (no longer `readonly`): `$chronologyDate`, `$chronologyStyle`, `$chronologyView`, `$items`, `$comment` instance state; `initializeCalendar()` takes named parameters and SectionInitializer reads results back through getters.
- `CalendarBase` / `CalendarMonthly`: `public array $chronologyDate`, `public string $chronologyField`, `public string $chronologyView` populated by `CalendarService` before `initialize()`.
- `UrlService`: refcounted `private static ?string $rootPathOverride` for the `setMakeFullUrl()` / `unsetMakeFullUrl()` pair. `getRootUrl()` reads override → `SectionContext::rootPath` → `PHPWG_ROOT_PATH`. Class-level `readonly` dropped (PHP rejects static properties inside `readonly` classes) and DI props made individually `readonly`.
- `AuthService::authKeyLogin()` writes `PageState::current()->authKeyId` (new typed `?int` property); `Util::pwgLog()` reads it.
- `PasswordService`, `MaintenanceController::history`, `GeneralEndpoints::historySearch`, `UpgradeController` — dead `$page` reads/writes (different request paths or never-set keys) deleted; `UpgradeController` errors path reads from `PageState::current()->errors`.

`SectionInitializer.php:68` alias deleted. `tools/phpstan-bootstrap.php` `$page` stub removed; `NoGlobalInSrcRule` GUARDED entry for `page` removed.

Verification: full repo grep for `$GLOBALS['page']` (excluding vendor) returns zero matches; PHPStan green; 486 tests pass.

## Phase 6 — v17.0 Cutover (Single PR Cluster)

Coordinated breakage of all plugin-facing surface (§P1–§P4). Independent of
every Phase 1-5 task except that §A5.2 (caddie) is **already done** by the
time this phase runs.

| Task | Notes |
|---|---|
| Delete `Plugin/PluginService.php`, `Plugins/EventDispatcher.php` plugin-loader paths (§P1.1, §P1.4) | Drops `main.inc.php` `require_once` and the include_path-on-listener mechanism |
| Delete `Admin/Plugins.php` pre-2.7 BC branching (§P1.2) | Single dead-code removal |
| Delete `Admin/Themes.php` theme contract (§P1.3) | `themeconf.inc.php`, `admin/maintain.inc.php`, `admin.inc.php` |
| Remove `plugin_*` / `theme_*` callback stubs from `tools/phpstan-bootstrap.php` (§P1.5) | Phase 1 didn't touch these; they live as long as §P1 does |
| Rewrite the 153 plugin event names as PSR-14 typed events (§P2) | Touches every `EventDispatcher::dispatch/notify` call site |
| Rewrite the 1136-line `tools/triggers_list.php` to match new event types or delete it (§P2.2, §D3.5) | Phase 1 already cleaned the `'type'` field strings; this phase deletes or reshapes the file |
| Rewrite all 133 `.latte` templates to native Latte syntax (§P3) | Largest single piece of v17 work |
| Delete `Template/Latte/PiwigoExtension.php` Smarty ports (§P3) | Becomes empty / mostly empty once templates are rewritten |
| Delete frontend BC queues: `_pwgRatingAutoQueue`, `SwitchBox` drainers, `_cont` alias (§P4) | `rating.ts:150`, `switchbox.ts:35`, `albums.ts:522` |
| Clean `src/types/globals.d.ts`: remove unused declarations, update "Smarty templates" header to "Latte" (§D4) | After templates rewritten, several declared globals can go |

## Hard Dependencies — Summary Graph

```
                        ┌─────────────────────┐
                        │ Phase 1 (parallel)  │  ──┐
                        │ all standalone      │    │
                        └─────────────────────┘    │
                                                   │
   Phase 2a (WS_*)        ──→  stubs (D3.1B, D2.2)│
   Phase 2b (*_TABLE)     ──→  stubs (D3.1B, D2.2)┼──→ §A5.2 (caddie)
   Phase 2c (PclZip)      ──→  composer.json,stubs│    after 2b
   Phase 2d (xmlrpc)      ──→  stubs (D2.2)       │
   Phase 2e (MobileEsp)   ──→  composer.json     ─┼──→ §A5.1 split
   Phase 2f (CURRENT_DATE)──→  (standalone)       │    after 2e + 4a
   Phase 2g (FeedCreator) ──→  composer.json      │
   Phase 2h (other defines, optional)             │
                                                   │
   Phase 3a (self-contained ×4)                  ─┤
   Phase 3b (prefixeTable, debug+t2)             ─┤
                                                   │
   Phase 4a ($filter)     ──→ phpstan stubs, GUARDED
   Phase 4b (header_*)    ──→ GUARDED             │
   Phase 4c (RequestCtx)  ──→ phpstan stubs       │    (§A1 already done
   Phase 4d (lang_info)   ──→ Lang static state   │     2026-05-15 — §Z1.1)
                                                   │
                                              ┌────┴────┐
                                              │ Phase 6 │
                                              │ v17 cut │
                                              └─────────┘
```

Notes:
- Phase 2 tasks are internally sequenced (code → runtime → stubs) but
  externally parallel.
- Phase 3 tasks are mutually independent and don't gate anything.
- Phase 4 tasks gate Phase 5 softly: Util.php split (§A5.1) benefits from
  Phase 2e + 4a. §A1 was originally listed as benefiting from Phase 4a for
  the same reason (CalendarService sub-calls share the alias with the
  filter path) but shipped first in practice — service-owned state was
  invasive enough that filter-context dependency didn't materially change
  the diff size. §Z1.1 is the closure record.
- Phase 6 only hard-depends on §A5.2 having retired the caddie — every
  other §P task is independent of Phases 1-5.

## Coverage Matrix

Every open-action item in Parts A and D maps to exactly one phase (or one
phase plus a v17 follow-up). Closed items in Part Z and sustained items in
Part P are listed for completeness.

| Item | Section | Phase | Status |
|---|---|---|---|
| `$page` reference bridge alias | A1 | 5 | **Closed** (2026-05-15 — see [§Z1.1](#z1-wave-a-reference-bridges)) |
| `$filter` channel | A2 | 4a | Open |
| `lang_info` channel | A2 | 4d | Open |
| `header_msgs` + `header_notes` | A2 | 4b | Open |
| `debug` + `t2` | A2 | 3b | Open |
| `prefixeTable` | A2 | 3b | Open |
| `errors` | A2 | 3a | Open |
| `themeconfs` | A2 | 3a | Open |
| `cache` | A2 | 3a | Open |
| `maint_actions` | A2 | 3a | Open |
| `IN_ADMIN` / `IN_WS` / `PHPWG_IN_UPGRADE` | A3.1 | 4c | Open |
| `WS_*` constants | A3.2 | 2a | Open |
| `*_TABLE` constants | A3.3 | 2b | Open |
| `CURRENT_DATE` inconsistency | A3.4 | 2f | Open |
| `xmlrpc_encode` | A3.5 | 2d | Open |
| `PHPWG_DOMAIN` / `MKGETDIR_*` etc. | A3.6 | 2h (optional) | Open |
| PclZip | A4.1 | 2c | Open |
| UniversalFeedCreator | A4.2 | 2g | Open |
| MobileEsp | A4.3 | 2e | Open |
| `Util.php` split | A5.1 | 5 | Open |
| Caddie | A5.2 | 5 | Open |
| Plugin/theme procedural contract | P1 | 6 | Sustained until v17 |
| Plugin event API (153 names) | P2 | 6 | Sustained until v17 |
| Smarty syntax compat in Latte | P3 | 6 | Sustained until v17 |
| Frontend plugin BC queues | P4 | 6 | Sustained until v17 |
| Stale comments (D1.1, D1.2) | D1 | 1 | Open |
| `include/` template subdir caveat | D1.3 | — | Awareness only, no task |
| `psalm.xml` comments | D2.1 | 1 | Open |
| `psalm-stubs.phpstub` cleanup | D2.2 | 2a/2b/2c/2d (per stub group) | Open |
| `phpstan-bootstrap.php` closed-bridge stubs (9 vars: `$user`, `$lang`, `$template`, `$logger`, `$pwg_event_handlers`, `$pwg_loaded_plugins`, `$service`, `$persistent_cache`, `$page`) | D3.1A | 1 | 1 of 9 closed (`$page` removed 2026-05-15); 8 still open |
| `phpstan-bootstrap.php` `$filter` / `$prefixeTable` stubs (2 vars, still active) | D3.1A | 4a / 3b | Open |
| `phpstan-bootstrap.php` WS const stubs | D3.1B | 2a | Open |
| `phpstan-bootstrap.php` plugin/theme callback stubs | D3.1C | 6 | Sustained until v17 |
| Dead `PwgGetSessionVarDynamicReturnType` | D3.2 | 1 | Open |
| Misnamed `TriggerChangeDynamicReturnType` | D3.3 | 1 | Open |
| `NoGlobalInSrcRule` `persistent_cache` entry | D3.4 | 1 | Open |
| `NoGlobalInSrcRule` other GUARDED entries | D3.4 | 3a / 3b / 4a / 4b / 4d | Open (per channel) |
| `triggers_list.php` `'type'` strings + `include/` paths | D3.5 | 1 | Open |
| `triggers_list.php` event-name rewrite | D3.5 (cross-ref P2) | 6 | Sustained until v17 |
| `globals.d.ts` ambient TS globals | D4 | 6 | Sustained until v17 |

## Caveats

- The plugin/theme procedural contract (§P1) and the 153-name event API
  (§P2) sit behind every Phase 5 refactor. Splitting `Util.php` doesn't
  reach those, but anything that touches event names or plugin-loader paths
  is v17 territory.
- The Smarty compat layer (§P3) is the largest piece of v17 work in terms
  of touched files (133 templates). Estimate this independently of the
  rest of §P.
- `tools/triggers_list.php` (§D3.5 + §P2.2) shows up in two phases: the
  `'type'`-string rename is Phase 1; the underlying event-name rewrite is
  Phase 6.
