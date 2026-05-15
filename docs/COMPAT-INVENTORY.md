# Compatibility Inventory & Cleanup Roadmap

This document lists every remaining compatibility surface in `src/` and orders
the work in execution sequence. Each open task appears exactly once, with its
gates called out. Closed migrations are recorded for context in
[Appendix A](#appendix-a--closed-migrations).

**Policy:** v17.0 intentionally breaks all PEM extensions. External plugin
compatibility is **not** a blocker; only in-tree `src/` callers block removal.

**How to read this doc:** start at the [Phase Ladder](#phase-ladder) below.
Each row points at its full narrative further down. Closed phases collapse to
a one-paragraph stub that links into Appendix A. Legacy `§A` / `§D` tags
(referenced by prior commits and other docs) survive as `[tag]` annotations
inside their owning phase, and `§Z` anchors are preserved verbatim in
Appendix A.

Last audited end-to-end: 2026-05-15.

---

## Phase Ladder

The single canonical execution order. All other ordering schemes in this doc
defer to this table.

| Phase | Title | Status | Gates | Parallelism |
|---|---|---|---|---|
| **1** | Doc-drift cleanups | Open | none | all tasks independent |
| **2** | Legacy `define()` migrations | Open | none | 2a–2h independent |
| **3** | `$GLOBALS` channel migrations | ✓ Closed 2026-05-15 | — | — |
| **4a** | `$filter` → `FilterContext` VO | ✓ Closed 2026-05-15 | — | — |
| **4b** | `header_msgs` / `header_notes` → `PageState` | ✓ Closed 2026-05-15 | — | — |
| **4c** | `IN_ADMIN` / `IN_WS` / `PHPWG_IN_UPGRADE` → typed `RequestContext` | Open | none | — |
| **4d** | `$lang_info` → `Lang` static state | ✓ Closed 2026-05-15 | — | — |
| **5a** | `Util.php` split | Open | soft: Phase 2e + 4a (4a ✓) | — |
| **5b** | Retire caddie | Open | soft: Phase 2b | — |
| **6** | v17.0 plugin-API cutover | Sustained until v17 | soft: Phase 5b done first | single coordinated PR cluster |

**Already shipped before this re-org** (Appendix A entries):

- §Z1 Wave A reference bridges — `$page`, `$lang`, `$template`, `$user`, `$logger`, `$pwg_event_handlers`, `$pwg_loaded_plugins`
- §Z2 Session-handler bridge
- §Z3 Legacy cache API (`PersistentCache`)
- §Z4 WS backward-compat parameters
- §Z5 `summarized` column lazy guard (→ Doctrine migration)
- §Z6 Plugin config legacy storage
- §Z7 `trigger_error` runtime signals
- §Z8 12 admin-URL `$GLOBALS` channels
- §Z9 / §Z10 / §Z11 / §Z12 / §Z13 — channels closed 2026-05-15 (Phases 3a, 3b, 4a, 4b, 4d)

---

# Phase 1 — Doc-Drift Cleanups

**Status:** open, all tasks independent. No code dependencies; do
opportunistically.

**Why:** the runtime cleanups in Phases 3 / 4 left behind stale comments,
unused PHPStan extensions, and dead stub entries. None block other work, but
they mislead readers and bulk up tooling configuration.

## 1.1 Stale comments asserting removed bridges  [D1.1]

Eight files have docstrings that describe `$GLOBALS` bridges that no longer
exist:

| File:Line | Stale claim | Reality |
|---|---|---|
| `Users/UserBootstrap.php:23` | "the PSR-15 pipeline has a fully-built `$GLOBALS['user']`" | No `$GLOBALS['user']` write happens |
| `Http/Middleware/AuthMiddleware.php:18, 20` | "Calls `UserBootstrap::bootstrap()` which populates `$GLOBALS['user']`" | Same |
| `Http/Middleware/FilterMiddleware.php:27` | "Runs after AuthMiddleware so that `$GLOBALS['user']`" | Reads `CurrentUser::get()->rawAttributes` now |
| `Config/Config.php:23` | "`$GLOBALS['conf']` reference bridge (attachGlobals) was retired" | Last in-tree mention; flagged for awareness |
| `Config/ConfigStorage.php:27` | "Bulk read from the conf table into `$GLOBALS['conf']`" | Bulk read populates `Config::$data` only |
| `Lang/Translator.php:99` | "restores from the stack top (so `$GLOBALS['lang']` takes over)" | `$GLOBALS['lang']` unset at boot |
| `Core/LanguageStack.php:34` | mentions `$GLOBALS['language_files']` | Only mention in codebase |
| `Users/CurrentUser.php:21` | method named `attachGlobals` | Body no longer touches `$GLOBALS` |

## 1.2 References to deleted directories  [D1.2]

Eighteen `src/` files have docstrings of the form "Replaces the former
`include/X.inc.php`" or "Used by `admin/Y.php`". Affected:

`Kernel.php` (4×), `InstallSentinel.php` (2×), `InstallController.php` (2×),
`FilterMiddleware.php` (2×), `Config.php`, `WsType.php`, `WsParam.php`,
`DerivativeSize.php`, `LanguageStack.php`, `SectionInitializer.php`,
`UserBootstrap.php`, `ImageDerivativeController.php`, `HistoryRepository.php`
(2×), `CategoryRepository.php`, `UserRepository.php`, `RateRepository.php`
(2×), `PermalinkRepository.php`, `Db/SqlExpr.php`, `Tag/TagRepository.php:95`,
`Image/ImageDerivativeContext.php`, `config/routes.php:64, 79`.

> **Caveat — `include/` as a template subdirectory  [D1.3]:**
> `SelectedTagsRenderer.php:43` assigns the template path
> `include/selected_tags.inc.latte`. That path is **inside the template tree**
> (`themes/_base/template/include/...`), not the deleted root `include/`. Easy
> to misread; do not "fix" this one.

## 1.3 `psalm.xml` stale comments  [D2.1]

Two comments around suppression rules (lines 30, 33-34) frame the suppressions
as "Legacy globals used in bootstrapped files — not actionable yet" and
"reference assignments to `$GLOBALS` / static properties are intentional
legacy-compatibility bridges". The suppressions themselves are still needed
(one reference assignment remains, in `UrlService`), but the framing is
outdated.

## 1.4 PHPStan tooling cleanups

### Delete `PwgGetSessionVarDynamicReturnType`  [D3.2]

The extension targets a free function `pwg_get_session_var()` that no longer
exists. Whole-repo grep finds zero call sites — `SessionService::getSessionVar()`
replaced it. Safe to delete the extension and unregister from `phpstan.neon`.

### Rename `TriggerChangeDynamicReturnType`  [D3.3]

Class name fossilizes the legacy `trigger_change()` free function; the
implementation correctly targets
`\Piwigo\Plugins\EventDispatcher::dispatch()`. Functionally correct, but the
name lies. Rename to `EventDispatcherDispatchDynamicReturnType` (mechanical).

### Clean `NoGlobalInSrcRule`  [D3.4]

Class docblock (line 18) claims "Legacy code in `include/` and `admin/` is
allowed to keep using globals" — neither directory exists. The `REPLACEMENTS`
map's single remaining stale entry is
`'persistent_cache' => 'PersistentCacheRegistry::current()'` — the class
was deleted in §Z3. The previously stale `'header_notes'`, `'filter'`,
`'themeconfs'` entries were dropped alongside their channel closures (§Z9 /
§Z11 / §Z12).

### Drop closed-bridge `@var` placeholders from `tools/phpstan-bootstrap.php`  [D3.1A]

Eight of the nine "closed-bridge" placeholders still declared in lines 14-35
correspond to globals that no longer exist anywhere in `src/`:

| Variable | Why it's dead |
|---|---|
| `$user` | Removed (§Z1) — readers use `CurrentUser::get()->rawAttributes` |
| `$lang` | Removed (§Z1) — readers use `Lang::all()` |
| `$template` | Removed (§Z1) — readers use `TemplateRegistry::current()` |
| `$logger` | Replaced by `LoggerRegistry` |
| `$pwg_event_handlers` | Removed (§Z1) |
| `$pwg_loaded_plugins` | Removed (§Z1) |
| `$service` | Now `PwgServerRegistry::current()` |
| `$persistent_cache` | Class deleted (§Z3) |

(`$page` was the ninth; already dropped 2026-05-15 alongside §A1.) Each can
be deleted with the corresponding `@var` block. The `$prefixeTable` and
`$filter` stubs were already dropped in §Z10 and §Z11.

> The remaining placeholders in the same file fall into two groups:
> **(B) runtime constant duplicates** (13 `WS_*`, `IN_ADMIN`, URLs) tracked
> by [Phase 2a](#phase-2a--ws_-constant-migration-a32) and
> [Phase 4c](#phase-4c--in_admin--in_ws--phpwg_in_upgrade--typed-requestcontext-a31);
> and **(C) procedural plugin/theme callback stubs** (`plugin_install`, …)
> required by [Phase 6](#phase-6--v170-plugin-api-cutover).

## 1.5 `tools/triggers_list.php` terminology — Phase 1 portion  [D3.5]

The 1136-line plugin-author reference uses legacy `'type' => 'trigger_change'`
and `'type' => 'trigger_notify'` strings, plus four
`'files' => array('include/functions.inc.php', ...)` references pointing at a
deleted directory.

**Phase 1 task:** rename the type strings to match the modern method names
(`dispatch` / `notify`) and replace the four `include/` paths with their
modern equivalents. The event names themselves are the API surface and don't
change here — see [Phase 6](#phase-6--v170-plugin-api-cutover) for that.

---

# Phase 2 — Legacy `define()` Migrations

**Status:** open. Eight independent sub-phases, do in any order.

**Pattern (applies to every sub-phase):**

1. Migrate call sites in `src/` to the typed replacement.
2. Remove the runtime shim (the `define()` block or composer dependency).
3. Clean up the static-analysis stubs that described it
   (`tools/phpstan-bootstrap.php`, `tools/psalm-stubs.phpstub`, the
   `NoGlobalInSrcRule` GUARDED / REPLACEMENTS maps).

Step 3 always follows step 2 — PHPStan and Psalm read the stubs at analyze
time, so removing a stub before the runtime is gone breaks the analyzer.

## Phase 2a — `WS_*` constant migration  [A3.2]

`PwgServer::boot()` (lines 471-485) defines 13 constants (`WS_PARAM_*`,
`WS_TYPE_*`, `WS_ERR_*`, `WS_XML_ATTRIBUTES`) as a bridge to the typed
`WsParam` and `WsType` enums. ~50 call sites in `Ws/Method/*Endpoints.php`,
`Ws/Protocol/PwgRestRequestHandler.php`, `Ws/WsHelper.php`.

1. Migrate call sites from `WS_ERR_INVALID_PARAM` to `WsError::InvalidParam->value`
   / `WS_TYPE_INT` to `WsType::Int->value` / etc.
2. Delete the `define()` block in `PwgServer::boot()` lines 471-485.
3. Delete `WS_*` stubs from `tools/phpstan-bootstrap.php` and
   `tools/psalm-stubs.phpstub`.
4. Update `WsParam.php:10` docstring (currently references deleted
   `include/ws_core.inc.php`).

**Enables:** cleaner WS layer; nothing else hard-depends on this.

## Phase 2b — `*_TABLE` constant migration  [A3.3]

`Admin/UpgradeService.php:33-58` defines 30+ table-name constants
(`CATEGORIES_TABLE`, `IMAGES_TABLE`, …) by string-concatenating the prefix.
Only `UpgradeService.php` reads them inside `src/` — everywhere else uses
`Piwigo\Db\Tables::*()`.

1. Rewrite legacy upgrade SQL in `Admin/UpgradeService.php` to use
   `Piwigo\Db\Tables::*()` accessors.
2. Delete the 30+ `define()` calls in `UpgradeService.php:33-58`.
3. Delete `*_TABLE` stubs from `tools/phpstan-bootstrap.php` and
   `tools/psalm-stubs.phpstub`.

**Enables:** simpler caddie removal in [Phase 5b](#phase-5b--retire-caddie-a52)
because `CADDIE_TABLE` goes with this batch.

## Phase 2c — `PclZip` → `ZipArchive`  [A4.1]

`pclzip/pclzip` is in `composer.json` and used in 4 production files instead
of the built-in `ZipArchive`:

| File:Line | Purpose |
|---|---|
| `Admin/Updates.php:600` | Extract Piwigo core update archives |
| `Admin/Plugins.php:549` | Extract plugin archives from PEM |
| `Admin/Languages.php:273` | Extract language archives |
| `Admin/Themes.php:511` | Extract theme archives |

1. Rewrite all four call sites to `ZipArchive`.
2. Remove `pclzip/pclzip` from `composer.json` (hard dep: all four must be
   migrated first).
3. Delete `PCLZIP_OPT_*` stubs (~20 constants) from
   `tools/psalm-stubs.phpstub`.

## Phase 2d — `xmlrpc_encode()` removal  [A3.5]

`Ws/Protocol/PwgXmlRpcEncoder.php:40` calls `xmlrpc_encode($response)`. The
PHP `xmlrpc` extension was deprecated in 8.0 and removed from core in 8.1+;
`pwg.xmlrpc` requests will fatal-error on any modern PHP build without an
explicit PECL install. REST/JSON cover all in-tree callers.

1. Delete `Ws/Protocol/PwgXmlRpcEncoder.php`.
2. Remove the `xmlrpc` case from encoder selection in `PwgServer.php:522`.
3. Delete the `xmlrpc_encode` stub from `tools/psalm-stubs.phpstub` lines 6-9.

## Phase 2e — MobileEsp removal  [A4.3]

`ahand/mobileesp` (`\uagent_info`) is in `composer.json`. Used in three
places for regex-based mobile UA detection:

- `Core/Util.php:452` (`mobile_detect` helper)
- `Controller/Admin/PhotoController.php:614`
- `Controller/Admin/MiscController.php:574`

~2010-era library, predates UA Client Hints. Modern admin UI is responsive;
the detection paths are largely obsolete.

**Audit first** — confirm the modern admin UI works on mobile viewports
without the `Util::mobileTheme()` / `getDevice()` branches.
`tests/e2e/14-admin-extended-smoke.spec.ts` can be run with a mobile viewport.

- **If the UI is fully responsive** (likely): delete the 3 call sites, delete
  `Util::mobileTheme()` and `Util::getDevice()`, remove `ahand/mobileesp`
  from `composer.json`.
- **If mobile branches still serve a purpose**: migrate to the
  `Sec-CH-UA-Mobile` request header read from the PSR-7 request — keep the
  behaviour, drop the regex library.

**Enables:** [Phase 5a](#phase-5a--split-utilphp-a51) — `Util.php` loses two
methods, the split gets smaller.

## Phase 2f — `CURRENT_DATE` inconsistency  [A3.4]

Defined in **three** places with **two formats**:

- `Admin/Metadata/MetadataAdminService.php:214` → `date('Y-m-d')`
- `Controller/UpgradeController.php:127` → `date('Y-m-d H:i:s')`
- `Controller/InstallController.php:245` → `date('Y-m-d H:i:s')`

The `defined() or define()` guard means whichever path runs first wins —
**latent bug**. Also conflicts with the SQL keyword `CURRENT_DATE` used as a
string literal in `Db/SqlExpr.php:70, 72, 74`.

**Recommended fix** — eliminate the global constant entirely:

1. Define a `Piwigo\Core\RequestClock` service holding one
   `DateTimeImmutable` per request; expose `->now()` and
   `->format(string $fmt)`. Inject via DI.
2. Rewrite the three call sites to read from `RequestClock`. Each picks its
   own format — no shared format, no inconsistency.
3. Delete all three `define('CURRENT_DATE', …)` calls.
4. Add an inline `// SQL keyword, not the PHP constant` comment at
   `Db/SqlExpr.php:70, 72, 74`.

> **Workaround alternative** (smaller diff, doesn't actually fix shared
> state): pick one canonical format and standardise all three `define()`
> calls. Not recommended unless `RequestClock` is too invasive.

## Phase 2g — UniversalFeedCreator removal  [A4.2]

`openpsa/universalfeedcreator` is in `composer.json`.
`Feed/PiwigoFeedCreator.php` extends `\UniversalFeedCreator` (untyped
2004-era class). Used by `Controller/FeedController.php:78`.

1. Rewrite `Feed/PiwigoFeedCreator.php` to emit RSS/Atom directly with
   `SimpleXMLElement` (PHP built-in) or `laminas/laminas-feed`.
2. Update `Controller/FeedController.php:78` if the constructor signature
   changes.
3. Remove `openpsa/universalfeedcreator` from `composer.json`.

## Phase 2h — Other one-off `define()` polish  [A3.6] — *optional*

`PHPWG_DOMAIN`, `PHPWG_URL`, `PEM_URL` (`CommonBootstrap.php:174-186`) are
locale-derived strings — could become `Config` reads. `PWG_LOCAL_DIR`
(`CommonBootstrap.php:78`) is a constant path. `MKGETDIR_*`
(`Core/Util.php:41-45`) flag constants — promote to a typed enum that the
new `Filesystem` service (Phase 5a) consumes.

Conventional runtime config, not shims. Defer unless touching the surrounding
code anyway.

---

# Phase 3 — `$GLOBALS` Channel Migrations ✓

**Status:** Closed 2026-05-15.

Seven `$GLOBALS` channels eliminated across two sub-phases:

- **Phase 3a (self-contained, writer == reader class):** `$GLOBALS['errors']`,
  `$GLOBALS['themeconfs']`, `$GLOBALS['cache']`, `$GLOBALS['maint_actions']`.
  Detail → [Appendix A §Z9](#z9-phase-3a-self-contained-channels).
- **Phase 3b (cross-class with existing typed accessor):**
  `$GLOBALS['prefixeTable']`, `$GLOBALS['t2']`, `$GLOBALS['debug']`.
  Detail → [Appendix A §Z10](#z10-phase-3b-mechanical-channels).

---

# Phase 4 — Cross-Class Typed Contexts

**Status:** 3 of 4 sub-phases closed. Phase 4c is the only open task. Each
sub-phase is independent of the others.

## Phase 4a — `$filter` → `FilterContext` VO  [A2] ✓

Closed 2026-05-15. New immutable VO `Piwigo\Filter\FilterContext` + registry
mirroring `SectionContext` / `SectionContextRegistry`. Six readers migrated;
`FilterMiddleware` builds one immutable `FilterContext` per request. Detail →
[Appendix A §Z11](#z11-phase-4a-filtercontext-vo).

**Enabled:** [Phase 5a](#phase-5a--split-utilphp-a51) — several `Util::*`
methods that read `$GLOBALS['filter']` can now become DI injections.

## Phase 4b — `header_msgs` / `header_notes` → `PageState`  [A2] ✓

Closed 2026-05-15. Both channels promoted to typed `PageState` arrays.
Audit-discovered bug fix: `header_notes` template variable was never assigned,
so integrity / filter notes were silently swallowed; `PageHeaderRenderer::render()`
now wires both `header_msgs` and `header_notes` to the template. Detail →
[Appendix A §Z12](#z12-phase-4b-header_-pagestate-properties).

## Phase 4c — `IN_ADMIN` / `IN_WS` / `PHPWG_IN_UPGRADE` → typed `RequestContext`  [A3.1]

**Status:** open. The only Phase 4 task still pending.

`defined()`-based request-context detection should be PSR-7 request attributes
set by the corresponding middleware.

| Flag | Defined in | Read in |
|---|---|---|
| `IN_ADMIN` | `Ws/Method/ExtensionsEndpoints.php:63, 87, 165` | `Page/PageHeaderRenderer.php:30`, `Page/NoPhotoYetRenderer.php:39, 41`, `Users/ProfileService.php:56, 60, 92, 150, 217`, `Core/Util.php:142` |
| `IN_WS` | `Controller/WsController.php:42` | `Users/UserBootstrap.php:89, 119`, `Admin/Upload/UploadService.php:167` |
| `PHPWG_IN_UPGRADE` | `Admin/UpgradeService.php:145, 180`, `Controller/UpgradeController.php` | `Admin/UpgradeService.php:23` (self-contained) |

`NoPhotoYetRenderer:39` already has a
`/** @psalm-suppress RedundantCondition — IN_ADMIN is runtime-set; stub value
misleads Psalm */` acknowledging the smell.

1. Define `RequestContext` enum (`Admin`, `Ws`, `Upgrade`, `Gallery`,
   `Derivative`) + a PSR-7 request attribute key.
2. Middleware sets the attribute per route.
3. Migrate read sites listed above to read from the typed attribute.
4. Delete `define('IN_ADMIN', true)` at `ExtensionsEndpoints.php:63, 87, 165`
   and `define('IN_WS', true)` at `WsController.php:42`.
5. Remove `IN_ADMIN` / `IN_WS` stubs from `tools/phpstan-bootstrap.php`.
6. `PHPWG_IN_UPGRADE` is self-contained in `UpgradeService` — collapse to a
   private static property.

## Phase 4d — `$lang_info` → `Lang` static state  [A2] ✓

Closed 2026-05-15. Locale metadata folded into the existing `Lang` static
state alongside `$data` / `$days` / `$months` (`langInfo()` / `setLangInfo()` /
`mergeLangInfo()` accessors). `LanguageStack::info/setInfo/mergeInfo` delegate
to `Lang`. Detail → [Appendix A §Z13](#z13-phase-4d-lang_info-lang-static-state).

---

# Phase 5 — `Util.php` Split + Caddie Retirement

**Status:** open. Both tasks have soft dependencies on earlier phases.

## Phase 5a — Split `Util.php`  [A5.1]

**Gates (soft):** [Phase 2e](#phase-2e--mobileesp-removal-a43) (drops
`mobileTheme` / `getDevice`); [Phase 4a](#phase-4a--filter--filtercontext-vo-a2)
done ✓ (already shipped).

`src/Piwigo/Core/Util.php` is a 1058-line service-locator anti-pattern: 33
methods across many concerns, 11 prefixed `pwg*` because they were once free
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
| Mobile detection | `mobileTheme`, `getDevice` (goes away with Phase 2e) |
| Input validation | `checkInputParameter` |
| Misc UI | `getPrivacyLevelOptions`, `getIcon`, `createNavigationBar` |
| Ephemeral keys | `getEphemeralKey`, `verifyEphemeralKey` |
| Comment counts | `getNbAvailableComments` |
| Filter state | `getFilterPageValue` |
| Email | `getWebmasterMailAddress` |
| Lounge (timed-publish staging) | `checkLounge` |
| Caddie (legacy) | `fillCaddie` — see Phase 5b |

Carve-out (names deliberately drop the `pwg` prefix — those are the legacy
free-function names §A5.1 calls out as the smell):

- **`ActivityLogger`** — `pwgLog`, `pwgActivity`, `doLog`. These all write to
  the activity log table; merge them. PSR-3 `LoggerRegistry` / `Logger`
  already covers application logging; `ActivityLogger` is specifically the
  user-visible activity feed.
- **`DebugCollector`** — `pwgDebug`. Coordinates with `PageState->debugLines`
  (set in [§Z10](#z10-phase-3b-mechanical-channels)). Becomes a thin facade
  over the `PageState` property.
- **`CsrfService`** — `getPwgToken`, `checkPwgToken`.
- **`ExecutionMutex`** — `pwgUniqueExecBegins` / `IsRunning` / `Ends` →
  rename methods to `acquire` / `isHeld` / `release`.
- **`RedirectResponder`** returning PSR-7 `ResponseInterface` — collapses
  `redirect` / `redirectHttp` / `redirectHtml` to one method that picks
  header vs HTML body based on whether headers can still be sent.
- **`TelemetryService`** — `sendPiwigoInfos`, `sendPiwigoInfosRetryLater`.
- Extension enumeration helpers move to `ThemeService` and `LanguageService`
  (mirroring `PluginService`).
- `mkgetdir` → `Symfony\Component\Filesystem` (we already require
  `league/flysystem`, so the `Filesystem` component or a thin Flysystem
  wrapper subsumes it). Promote `MKGETDIR_*` flags (Phase 2h) to a typed enum
  if callers still need flag combinations; otherwise inline the defaults.

Update `Util::pwgActivity`'s signature to a typed `ActivityEvent` enum + DTO
at the same time — the current
`(string $object, array|int|string $objectId, string $action, array $details = [])`
union signature is itself a smell.

> **Don't do:** name the carved-out classes `PwgLogger`, `PwgCsrf`, etc.
> Those names preserve the legacy `pwg*` prefix the inventory just spent
> paragraphs explaining is the symptom of `include/functions.inc.php`
> heritage. The point of the split is to leave that behind.

## Phase 5b — Retire caddie  [A5.2]

**Gates (soft):** [Phase 2b](#phase-2b--_table-constant-migration-a33) (drops
`CADDIE_TABLE` constant in the same batch).

The "caddie" was the v1.x precursor to `batch_manager`. Replaced years ago in
the UI but the machinery is fully preserved:

| Surface | Location |
|---|---|
| DB table | `piwigo_caddie` (`element_id`, `user_id`) |
| Typed accessor | `Db\Tables::caddie()` |
| Upgrade constant | `define('CADDIE_TABLE', ...)` in `UpgradeService.php:53` |
| WS API | `pwg.caddie.add` (registered in `WsMethodRegistrar.php:105`) |
| Internal helper | `Util::fillCaddie()` |
| Callers | `Ws\Method\GeneralEndpoints:262-269`, `Controller\Admin\PhotoController:606` |

1. Confirm no in-tree caller uses `pwg.caddie.add` (frontend admin UI doesn't
   surface a caddie tab; third-party usage breaks by policy).
2. Delete WS method registration in `WsMethodRegistrar.php:105`.
3. Delete `Util::fillCaddie()` and the two call sites.
4. Doctrine migration: `DROP TABLE piwigo_caddie`.
5. Delete `Db\Tables::caddie()` accessor.
6. Phase 2b already deleted the `CADDIE_TABLE` constant.

> **§A1 — `$page` reference bridge** was originally scheduled for Phase 5
> (service-owned state was invasive enough to land alongside the Util split).
> Shipped early on 2026-05-15. Closure record:
> [Appendix A §Z1.1](#z1-wave-a-reference-bridges) and the supplementary
> detail block in [§A1 closure](#a1-page-reference-bridge-closure-record).

---

# Phase 6 — v17.0 Plugin-API Cutover

**Status:** sustained until v17.0. A single coordinated PR cluster that
breaks all plugin-facing surface at once.

**Why one cluster:** every item in this phase is a deliberate compatibility
layer for plugin authors. They are wired and load-bearing for plugins running
today. Per policy (top of doc), they break at the v17.0 cutover, not before.

**Hard dependency:** [Phase 5b](#phase-5b--retire-caddie-a52) must finish
first (the caddie WS method is part of the legacy plugin API surface and
goes away in 5b rather than here).

## 6.1 Plugin/theme procedural contract  [P1]

The legacy Piwigo plugin/theme runtime contract is wired and load-bearing.

### Plugin loading  [P1.1]

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

### Pre-2.7 vs 2.7+ branching  [P1.2]

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

### Theme contract  [P1.3]

`Admin/Themes.php` mirrors the plugin contract:

- `themeconf.inc.php` — theme metadata, PHP array literals (`Themes.php:287, 298`)
- `admin/maintain.inc.php` — required `ThemeMaintain` class (`Themes.php:63-77`)
- `admin/admin.inc.php` — optional admin bootstrap (`Themes.php:353`)
- Theme archives identified at install time by presence of `themeconf.inc.php`
  (`Themes.php:522-523`)

### Lazy-include event handlers  [P1.4]

`EventDispatcher::addListener($event, $func, $priority, ?$include_path)`
(`Plugins/EventDispatcher.php:28`) accepts an optional include path that's
`include_once`'d **right before** dispatching (lines 86-88, 115-117).
Standard PSR-14 dispatchers don't have this; Piwigo-specific shim for lazy
plugin loading.

### Procedural callback contract  [P1.5]

Plugins/themes are expected to define free functions: `plugin_install`,
`plugin_activate`, `plugin_deactivate`, `plugin_uninstall`, `theme_activate`,
`theme_deactivate`, `theme_delete`. `tools/phpstan-bootstrap.php` stubs them
(group C in §1.4) so PHPStan resolves the `is_callable()` call sites in
`Admin/Plugins.php` / `Admin/Themes.php`.

## 6.2 Plugin event API  [P2]

`EventDispatcher::dispatch()` / `notify()` is called with **153 unique event
names** across `src/`. Every name is a stable hook PEM plugins subscribe on.

### Naming conventions  [P2.1]

| Pattern | Count | Purpose | Examples |
|---|---|---|---|
| `loc_begin_X` / `loc_end_X` | ~30 | Page-lifecycle markers | `loc_begin_index`, `loc_end_page_header` |
| `get_X` | ~50 | Getter hooks, plugins mutate return value | `get_admin_plugin_menu_links`, `get_derivative_url` |
| `render_element_X` | several | Photo-page rendering | `render_element_content`, `render_element_name`, `render_element_description` |
| `batch_manager_X` | several | Batch manager hooks | `batch_manager_perform_filters`, `batch_manager_register_filters` |
| `format_X`, `clean_X`, `combined_X`, `before_X`, `finalize_X` | several each | Domain-specific | `format_exif_data`, `clean_iptc_value`, `combined_script`, `before_send_mail`, `finalize_login` |
| Special | — | Auth, WS gating, derivatives | `user_init`, `ws_invoke_allowed`, `derivative_params_get` |

### Reference documentation  [P2.2]

`tools/triggers_list.php` is the 1136-line canonical plugin-author reference
for these events. Each entry is shaped:

```php
array(
  'name'  => 'event_name',
  'type'  => 'trigger_change' | 'trigger_notify',
  'vars'  => array('php_type', 'var_name', ...),
  'files' => array('src/Piwigo/Controller/Foo.php', ...),
  'infos' => '(optional) plugin-author note',
)
```

Phase 1 already cleaned the `'type'` field strings; v17 either rewrites this
file to match the new typed-event shape or deletes it.

### Removal at v17.0  [P2.3]

Migration path: rename the events to PSR-14 typed events at the v17 cutover,
breaking the legacy names cleanly. Until then, all 153 are part of the
supported API.

## 6.3 Smarty-syntax compatibility in Latte  [P3]

`src/Piwigo/Template/Latte/PiwigoExtension.php` is a ~700-line compatibility
layer that ports Smarty filter/block/function semantics to Latte so the 133
converted `.latte` templates can keep using Smarty surface syntax.

Ported features:

- Filters: `default`, `strip_tags`, `date_format`, `cat:`, `number_format`
- Blocks: `{html_options …}`, `{html_radios …}`, `{math …}`
- Admin asset accumulation: `{combineScript}`-style helpers
- Accessor: Smarty's `$pwg->derivative(...)` over `SrcImage`

Migrating away requires rewriting all 133 templates to native Latte syntax.
**Largest single piece of v17 work** in terms of touched files — estimate
independently of the rest of Phase 6.

## 6.4 Frontend plugin BC queues  [P4]

Two pre-load auto-queue patterns where third-party plugins can inject
behaviour before the relevant bundle has loaded by pushing onto a global
array; the bundle then drains the queue and replaces it with a real object
exposing `push()`:

- **`_pwgRatingAutoQueue`** — drained by `themes/_base/js/rating.ts:150`
- **`SwitchBox`** — drained by `themes/_base/js/switchbox.ts:35`

Plus a smaller window-global alias:

- `themes/admin/_base/js/albums.ts:522`: `_cont = contEl;` — kept for plugin
  compat.

Removable as one batch at v17.0.

## 6.5 Frontend `globals.d.ts` cleanup  [D4]

`src/types/globals.d.ts` is the TypeScript ambient-globals declaration file
(109 lines). Header comment says "Smarty templates" — stale.

| Category | Status |
|---|---|
| Template-emitted constants (`pwg_token`, `pwg_root_url`, `cookie_*`) | Now populated via `<script type="application/json" id="pwg-page-data">` JSON islands, not inline `var x = …` blocks. Declarations may be stale. |
| Cross-bundle functions (`pwgBind`, `pwgAddEventListener`, `pwgToaster`, `phpWGOpenWindow`, `popuphelp`) | Active — defined in `themes/_base/js/scripts.ts` and hoisted to `window` |
| PHP-style JS helpers (`array_delete`, `str_repeat`, `getRandomInt`, `sprintf`) | Implemented in `themes/admin/_base/js/common.ts`; cosmetic Smarty-era naming |
| Profile-specific i18n vars (`selected_date`, `no_time_elapsed`, `str_*`) | Set via inline `<script>` from `profile.latte` |
| `Window.PwgWS`, `Window.LocalStorageCache` and subclasses | Active — intentionally hoisted for cross-bundle reuse |
| Likely-stale (no grep hits in `themes/`) | `var user`, `var preferencesDefaultValues`, `var standardSaveSelector` |

After the templates are rewritten (6.3), several declared globals can go.
Update the "Smarty templates" header to "Latte" at the same time.

## 6.6 Phase 6 task summary

| Task | Detail |
|---|---|
| Delete plugin-loader paths (P1.1, P1.4) | Drops `main.inc.php` `require_once` and the include-path-on-listener mechanism |
| Delete `Admin/Plugins.php` pre-2.7 BC branching (P1.2) | Single dead-code removal |
| Delete theme contract (P1.3) | `themeconf.inc.php`, `admin/maintain.inc.php`, `admin.inc.php` |
| Remove `plugin_*` / `theme_*` callback stubs from `tools/phpstan-bootstrap.php` (P1.5) | These live as long as P1 does |
| Rewrite 153 event names as PSR-14 typed events (P2) | Touches every `EventDispatcher::dispatch/notify` call site |
| Rewrite or delete `tools/triggers_list.php` (P2.2, D3.5 part 2) | Phase 1 already cleaned the `'type'` field strings |
| Rewrite all 133 `.latte` templates to native Latte (P3) | Largest single piece |
| Delete `Template/Latte/PiwigoExtension.php` Smarty ports (P3) | Mostly empty after templates rewritten |
| Delete frontend BC queues (P4) | `rating.ts:150`, `switchbox.ts:35`, `albums.ts:522` |
| Clean `src/types/globals.d.ts` (D4) | After templates rewritten |

---

# Hard Dependencies — Summary Graph

```
   Phase 1 (parallel)        ──→  (no downstream gate)
   all standalone

   Phase 2a (WS_*)           ──→  phpstan + psalm stubs
   Phase 2b (*_TABLE)        ──→  phpstan + psalm stubs ──→  Phase 5b (caddie)
   Phase 2c (PclZip)         ──→  composer.json, stubs
   Phase 2d (xmlrpc)         ──→  psalm stub
   Phase 2e (MobileEsp)      ──→  composer.json     ──┐
   Phase 2f (CURRENT_DATE)   ──→  (standalone)        │
   Phase 2g (FeedCreator)    ──→  composer.json      │
   Phase 2h (other defines, optional)                │
                                                      │
   Phase 3a (4 channels)  ──┐                         │
   Phase 3b (3 channels)  ──┼──  done 2026-05-15     │
   Phase 4a ($filter)     ──┤  (see Appendix A)     ──┼──→  Phase 5a (Util split)
   Phase 4b (header_*)    ──┤                         │
   Phase 4d (lang_info)   ──┘                         │
                                                      │
   Phase 4c (RequestCtx)     ──→  phpstan stubs       │
                                                      │
   Phase 5b (caddie)         ──────────────────────────┴──→ Phase 6
   Phase 5a (Util split)
   §A1 ($page alias)         ──  done 2026-05-15 (shipped early, §Z1.1)
```

Notes:

- Phase 2 tasks are internally sequenced (code → runtime → stubs) but
  externally parallel.
- Phase 3 / 4a / 4b / 4d are done; they unblock Phase 5a but Phase 5a also
  needs Phase 2e (MobileEsp).
- Phase 6 only hard-depends on Phase 5b having retired the caddie. Every
  other Phase 1-5 task is independent of Phase 6.

---

# Appendix A — Closed Migrations

Items completed earlier in the 16.x branch, kept here for context. Each entry
preserves its original `§Z` anchor so prior commits and docs still resolve.

## Z1. Wave A Reference Bridges

| § | Global | Notes |
|---|---|---|
| §Z1.1 | `$GLOBALS['page']` | Service-owned state across `SearchService`, `CalendarService`, `CalendarBase`, `CalendarMonthly`, `UrlService`, `AuthService` (via `PageState::current()->authKeyId`); dead writes removed in `PasswordService`, `MaintenanceController`, `GeneralEndpoints`, `UpgradeController`. Alias at `SectionInitializer:68` deleted. See [§A1 closure detail](#a1-page-reference-bridge-closure-record) below. |
| §Z1.2 | `$GLOBALS['lang']` | `Lang::attachGlobals()` snapshots once at boot then `unset()`s the global. (Stale comment at `Translator.php:99` → Phase 1.1.) |
| §Z1.3 | `$GLOBALS['template']` | `TemplateRegistry::set()` write removed; readers migrated to `TemplateRegistry::current()`. |
| §Z1.4 | `$GLOBALS['pwg_loaded_plugins']` | Bridge removed from `LoadedPluginRegistry::init/reset`. 3 callers migrated (MiscController, BatchManagerController, ExtensionsController). |
| §Z1.5 | `$GLOBALS['pwg_event_handlers']` | Bridge removed from `EventDispatcher::init/reset`. |
| §Z1.6 | `$GLOBALS['user']` | All 30+ caller files migrated to `CurrentUser::get()->rawAttributes`. `UserBootstrap`, `AuthService`, `CurrentUser::setLanguage()`/`setRawAttributes()` no longer write `$GLOBALS['user']`. `CurrentUser::attachGlobals()` retained as misnomer (creates guest singleton) — Phase 1.1. |

### §A1 — `$page` reference bridge closure record

Closed 2026-05-15. Subsystem-by-subsystem detail of how `$GLOBALS['page']`
was eliminated:

- **`SearchService`** (no longer `readonly`): `$searchDetails`, `$searchId`,
  `$useRegexpICU` instance state; `setSearchDetails` / `setForbidden` /
  `getSearchDetails` / `getSearchId` accessors. `SearchFilterRenderer` calls
  `setForbidden()` instead of mutating a shared array via reference.
- **`CalendarService`** (no longer `readonly`): `$chronologyDate`,
  `$chronologyStyle`, `$chronologyView`, `$items`, `$comment` instance state;
  `initializeCalendar()` takes named parameters and `SectionInitializer`
  reads results back through getters.
- **`CalendarBase` / `CalendarMonthly`**: `public array $chronologyDate`,
  `public string $chronologyField`, `public string $chronologyView` populated
  by `CalendarService` before `initialize()`.
- **`UrlService`**: refcounted `private static ?string $rootPathOverride` for
  the `setMakeFullUrl()` / `unsetMakeFullUrl()` pair. `getRootUrl()` reads
  override → `SectionContext::rootPath` → `PHPWG_ROOT_PATH`. Class-level
  `readonly` dropped (PHP rejects static properties inside `readonly`
  classes); DI props made individually `readonly`.
- **`AuthService::authKeyLogin()`** writes `PageState::current()->authKeyId`
  (new typed `?int` property); `Util::pwgLog()` reads it.
- **Dead-read cleanups** in `PasswordService`, `MaintenanceController::history`,
  `GeneralEndpoints::historySearch`, `UpgradeController` — different request
  paths or never-set keys; `UpgradeController`'s errors path reads from
  `PageState::current()->errors`.

`SectionInitializer.php:68` (`$GLOBALS['page'] = &$page;`) deleted. The local
`$page` array still exists inside `SectionInitializer::initialize()` as
scratch space for building the `SectionContext` value object — no longer
aliased to any global. `tools/phpstan-bootstrap.php` `$page` stub removed;
`NoGlobalInSrcRule` GUARDED entry for `page` removed.

Verification: full repo grep for `$GLOBALS['page']` (excluding vendor)
returns zero matches; PHPStan green; 486 tests pass.

## Z2. Session Handler Bridge

`Session/PwgSession.php` deleted. `SessionService` implements
`\SessionHandlerInterface` directly (`open` / `close` / `read` / `write` /
`destroy` / `gc`). `SessionBootstrap` passes
`Kernel::service(SessionService::class)` to `session_set_save_handler()`.

## Z3. Legacy Cache API

`PersistentCache`, `PersistentFileCache`, `PersistentCacheRegistry` deleted.
All 10 call sites migrated to direct PSR-6 `CacheItemPoolInterface` injection.
`CacheFactory::create()` provides the pool via DI;
`makeKey(string|array $key)` inlined as `md5($key . AppInfo::VERSION)` for
per-version invalidation. `PersistentFileCacheTest` deleted.

## Z4. WS Backward-Compat Parameters

No client compatibility maintained:

- `pwg.images.addChunk`: `type` param removed; chunk filename hardcoded
- `pwg.images.addFile`: `type` param removed; size-check always runs
- `pwg.images.add`: `thumbnail_sum` / `high_sum` params removed
- `pwg.images.checkFiles`: only `file_sum` comparison remains

## Z5. One-Time DB Migration Guard

Lazy `history_summarized_dropped` runtime guard replaced by Doctrine
migration `Version20260514000001` that drops the column via schema
introspection (handles fresh 16.x installs and upgraded installs uniformly).
`HistoryAdminService::historyRemoveSummarizedColumn()` and its two call sites
deleted; `summarizedColumnExists()` / `dropSummarizedColumn()` in
`HistoryRepository` deleted; `Config::historySummarizedDropped()` and the
SCHEMA entry deleted.

## Z6. Plugin Config Legacy Storage

All plugins removed — `plugins/` is now a redirect stub. Four bundled-plugin
typed-config facades (`NbcThemeChanger`, `LocalFilesEditor`,
`PiwigoOpenstreetmap`, `PiwigoVideojs`) in `src/Piwigo/Plugins/` deleted along
with `LocalFilesEditorConfigTest`. `nbc_ThemeChanger` config rows may exist
in existing databases but the code that reads / writes them is gone — no
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

Cross-class admin-URL channels eliminated as of 2026-05-14;
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

## Z9. Phase 3a Self-Contained Channels

Closed 2026-05-15. Each channel's writer and reader lived in a single class;
no cross-class contract existed.

| Channel | Closure |
|---|---|
| `$GLOBALS['errors']` | `LocalSiteReader::open()` write was dead (no reader repo-wide). Deleted the write; `open()` now collapses to `return is_dir($this->site_url);`. |
| `$GLOBALS['themeconfs']` | `Template::loadThemeconf()` now uses `private array $themeconfs = []` instance cache. |
| `$GLOBALS['cache']` | `UserService::getDefaultUserInfo()` now uses `private array\|false\|null $defaultUserCache = null` instance memo. Class-level `readonly` dropped; DI props made individually `private readonly`. |
| `$GLOBALS['maint_actions']` | `MaintenanceController` already stored the data as `$this->maintActions`. The mirror write (line 158) and the two defensive `if (empty($this->maintActions))` blocks at lines 188/636 (dead — both methods are private callees of `maintenance()`) deleted. |

`NoGlobalInSrcRule` GUARDED list drops `errors`, `themeconfs`, `cache`,
`maint_actions`; `REPLACEMENTS` map drops the same four entries.

## Z10. Phase 3b Mechanical Channels

Closed 2026-05-15. Cross-class channels with an existing typed accessor or
PHP built-in waiting in the wings.

| Channel | Closure |
|---|---|
| `$GLOBALS['prefixeTable']` | Both readers (`UpgradeService::prepareConfUpgrade()`, `MaintenanceService::repairAndOptimize()`) call `Config::dbPrefix()` directly. All 5 writes deleted (`CommonBootstrap:83`, `UpgradeController:49`, `InstallController:56`, `index.php:38`, `index.php:66`). `phpstan-bootstrap.php` `$prefixeTable` stub dropped; `ContainerSmokeTest` test plumbing line removed. |
| `$GLOBALS['t2']` | The `CommonBootstrap:57` write deleted. `Util::pwgDebug()` and `PageTailRenderer` read `$_SERVER['REQUEST_TIME_FLOAT']` (populated natively by PHP at request start — strictly more accurate than `microtime(true)` at bootstrap). |
| `$GLOBALS['debug']` | The HTML-string accumulator is now `PageState::current()->debugLines` — a `list<string>` of pre-formatted `<p>` lines populated by `Util::pwgDebug()` and surfaced by `PageTailRenderer` when `Config::showQueries()` is on. No `DebugAccumulatorHandler` introduced; the panel content was always semantically distinct from PSR-3 logging (timing breadcrumbs, not application logs) and folding it into PageState keeps it request-scoped and typed without adding a new Monolog handler. |

## Z11. Phase 4a FilterContext VO

Closed 2026-05-15. New typed value object + registry mirroring
`SectionContext` / `SectionContextRegistry`.

- **VO** — `Piwigo\Filter\FilterContext` (immutable `readonly` props):
  `enabled (bool)`, `recentPeriod (int)`,
  `categories (array<int|string, array<string,mixed>>)`,
  `visibleCategories (string)`, `visibleImages (string)`. The legacy `int -1`
  sentinel for "no rows match" is preserved as the string `"-1"` so SQL
  `IN ($visible)` matches nothing.
- **Registry** — `Piwigo\Filter\FilterContextRegistry::current()` /
  `::set(FilterContext)` / `::reset()`.
- **Writer** — `FilterMiddleware::bootstrap()` builds local scalars / arrays
  and commits one immutable `FilterContext` to the registry. The previous
  `&$GLOBALS['filter']` reference-mutation pattern is gone; each early-return
  branch (filter disabled / cancelled / page not used) sets a
  `FilterContext(enabled: false)`.
- **Readers** — all 6 sites (`FilterService::updateCategoriesWithFilteredData`,
  `PermissionService::getSqlConditionFandF`, `CategoryService::getCategoriesMenu`,
  `MenubarRenderer::render`, `PictureController::__invoke`,
  `SectionInitializer::initialize`) replaced
  `is_array($GLOBALS['filter'] ?? null)` narrowing with
  `FilterContextRegistry::current()->{$prop}`.
- **Init writes removed** — `CommonBootstrap:71` (`$GLOBALS['filter'] = []`)
  and the late `enabled = false` write deleted. Default `FilterContext()`
  provided by the registry is functionally equivalent.
- **Tooling / stubs** — `$filter` stub dropped from
  `tools/phpstan-bootstrap.php`; `'filter'` dropped from `NoGlobalInSrcRule`
  GUARDED + REPLACEMENTS; `ContainerSmokeTest` test plumbing line removed.

Note: this is the first §A2 task to add new files (`FilterContext.php`,
`FilterContextRegistry.php`), so
`composer dump-autoload --classmap-authoritative` is required after the
diff is checked out (the project pins `classmap-authoritative: true`).

## Z12. Phase 4b `header_*` PageState Properties

Closed 2026-05-15. Two channels promoted to typed `PageState` arrays. Audit
also fixed a pre-existing bug: `$GLOBALS['header_notes']` had three writers
(CommonBootstrap, CheckIntegrity, FilterMiddleware) but no template-assign
anywhere — the integrity warnings, filter-period note, and
`Config::headerNotes()` were silently swallowed because the
`{if !empty($header_notes)}` block in `header.latte` always saw an undefined
variable.

- **PageState additions** — `PageState::$headerMessages` and `$headerNotes`
  (both `list<string|Latte\Runtime\Html>`).
- **header_msgs writers migrated** — `CommonBootstrap` (guest-status,
  gallery-locked, upgrade-pending) and `ImageAdminService` (missing photos /
  duplicate paths) now push to `PageState::current()->headerMessages`.
  `CommonBootstrap`'s init at line 69 and the flush-to-template block at
  lines 285-288 deleted (PageHeaderRenderer reads PageState directly now).
- **header_notes writers migrated** — `CommonBootstrap` (Config::headerNotes()
  merge), `CheckIntegrity` (anomaly count notice), and `FilterMiddleware`
  (filter-period note) push to `PageState::current()->headerNotes`.
  `CommonBootstrap`'s init at line 70 deleted; `FilterMiddleware`'s
  `&$GLOBALS['header_notes']` reference variable deleted.
- **Display wiring** — `PageHeaderRenderer::render()` now assigns
  `header_msgs` and `header_notes` to the template along with the other
  `$pageState->…` properties it already surfaces (bodyId, pageBanner,
  metaRobots, etc.) before calling `$template->parse('header.latte')`.
- **No clear-after-assign** — the old flush logic in CommonBootstrap reset
  `$GLOBALS['header_msgs'] = []` after assigning to the template. The new
  pattern does not clear; each request gets a fresh `PageState` singleton,
  and intra-request re-renders are uncommon (PageHeaderRenderer is typically
  called once per page). If re-display becomes a concern later, PageState
  can grow `consumeHeaderMessages(): array` accessors.
- **Tooling** — `'header_notes'` dropped from `NoGlobalInSrcRule` GUARDED +
  REPLACEMENTS. (`header_msgs` was never in GUARDED because it was always
  accessed via `$GLOBALS[…]` rather than `global $header_msgs`.)

## Z13. Phase 4d `lang_info` Lang Static State

Closed 2026-05-15. Locale metadata folded into the existing `Lang` static
state alongside `$data` / `$days` / `$months`.

- **Lang additions** — `private static array $langInfo = []` (typed
  `array<string,mixed>`) with `langInfo()` / `setLangInfo()` /
  `mergeLangInfo()` accessors. `Lang::reset()` clears it.
- **LanguageStack delegates** — `info()` / `setInfo()` / `mergeInfo()` /
  `initialized()` now route through `Lang::langInfo / setLangInfo / mergeLangInfo`.
  `restoreState()` calls `Lang::setLangInfo()` instead of writing
  `$GLOBALS['lang_info']` directly.
- **External readers migrated** — `Template::__construct():86` and
  `AdminService::getPiwigoNews():406` call `Lang::langInfo()` instead of
  narrowing `$GLOBALS['lang_info']`.
- **No new tooling** — `NoGlobalInSrcRule` never guarded `lang_info` (the
  legacy code accessed it as `$GLOBALS['lang_info']` rather than
  `global $lang_info`, so the rule didn't flag it).
- **No autoload regen** — only existing classes modified; no new files.

`LangService::getParentLanguage()` and `loadLanguage()` (and the
`mergeFromFile()` include helper in `LanguageStack`) still use local
`$lang_info` variables, but those are scoped to the include `.lang.php`
files — not the global.

---

## Caveats

- The plugin/theme procedural contract (Phase 6.1) and the 153-name event API
  (Phase 6.2) sit behind every Phase 5 refactor. Splitting `Util.php` doesn't
  reach those, but anything that touches event names or plugin-loader paths
  is v17 territory.
- The Smarty compat layer (Phase 6.3) is the largest piece of v17 work in
  terms of touched files (133 templates). Estimate this independently of the
  rest of Phase 6.
- `tools/triggers_list.php` shows up in two phases: the `'type'`-string
  rename and `include/` path fixes are Phase 1.5; the underlying event-name
  rewrite is Phase 6.2.
