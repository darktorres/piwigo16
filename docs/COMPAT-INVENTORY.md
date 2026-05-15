# Compatibility Inventory & Cleanup Roadmap

This document lists every remaining compatibility surface in `src/` and orders
the work in execution sequence. Each open task appears exactly once, with its
gates called out. Closed migrations are recorded for context in
[Appendix A](#appendix-a--closed-migrations).

**Policy:** v17.0 intentionally breaks all PEM extensions
(`AppInfo::VERSION = '17.0.0'` — already in effect). External plugin
compatibility is **not** a blocker; only in-tree `src/` callers block removal.
Existing extensions get migrated separately — out of scope for this
inventory.

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
| **1** | Doc-drift cleanups | ✓ Closed 2026-05-15 | — | — |
| **2** | Legacy `define()` migrations | ✓ Closed 2026-05-15 (2a–2g done; 2h deferred — audit found scope ≠ inventory framing, see Phase 2h section) | — | — |
| **3** | `$GLOBALS` channel migrations | ✓ Closed 2026-05-15 | — | — |
| **4a** | `$filter` → `FilterContext` VO | ✓ Closed 2026-05-15 | — | — |
| **4b** | `header_msgs` / `header_notes` → `PageState` | ✓ Closed 2026-05-15 | — | — |
| **4c** | `IN_ADMIN` / `IN_WS` / `PHPWG_IN_UPGRADE` → typed `RequestContext` | ✓ Closed 2026-05-15 | — | — |
| **4d** | `$lang_info` → `Lang` static state | ✓ Closed 2026-05-15 | — | — |
| **5** | `Util.php` split | ✓ Closed 2026-05-15 | — | — |
| **6** | Extension-API compat removal (post-v17 cleanup) | Open — most sub-tasks deferred pending §1.3 plugin architecture | none | 6.1–6.5 framed as independent before the 2026-05-15 audit; the audit reframed 6.3 (mostly complete already) and tied 6.1 / 6.2 / 6.4 to the plugin architecture replacement |

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
- §Z14 — Phase 1 doc-drift sweep (2026-05-15)
- §Z15 — Phase 2a WS_* constant migration (2026-05-15)
- §Z16 — Phase 2b `*_TABLE` constant migration (2026-05-15)
- §Z17 — Phase 2c PclZip → ZipArchive migration (2026-05-15)
- §Z18 — Phase 2d `xmlrpc_encode()` removal (2026-05-15)
- §Z19 — Phase 2e MobileEsp → mobiledetect/mobiledetectlib swap (2026-05-15)
- §Z20 — Phase 2f `CURRENT_DATE` global retired (2026-05-15)
- §Z21 — Phase 2g UniversalFeedCreator → in-tree DOMDocument RSS generator (2026-05-15)
- §Z22 — Phase 4c `IN_ADMIN` / `IN_WS` / `PHPWG_IN_UPGRADE` → typed `RequestContext` (2026-05-15)
- §Z23 — Phase 5 `Util.php` split (2026-05-15)

---

# Phase 1 — Doc-Drift Cleanups ✓

Closed 2026-05-15. Five sub-tasks landed in one sweep:

- **1.1** [D1.1] — Seven stale `$GLOBALS['user']` / `$GLOBALS['conf']` /
  `$GLOBALS['lang']` / `$GLOBALS['language_files']` claims in docstrings
  rewritten; `CurrentUser::attachGlobals()` got a clarifying note that the
  historical name no longer matches the body. (One file the inventory
  listed — `FilterMiddleware:27` — was already clean.)
- **1.2** [D1.2] — 17 docstrings carrying stale `include/` / `admin/`
  file references rewritten or simplified. The `SelectedTagsRenderer:43`
  caveat is preserved (template path, not a real `include/` reference).
- **1.3** [D2.1] — `psalm.xml` suppressions reframed: `MissingFile` now
  explains it's for dynamic plugin / theme / language file includes;
  `UnsupportedPropertyReferenceUsage` now names the actual registry-pattern
  call sites (EventDispatcher handler buckets, LoadedPluginRegistry
  plugin_data) instead of "legacy compatibility bridges".
- **1.4** [D3.2, D3.3, D3.1A] — Dead `PwgGetSessionVarDynamicReturnType`
  PHPStan extension deleted and unregistered. Misnamed
  `TriggerChangeDynamicReturnType` renamed to
  `EventDispatcherDispatchDynamicReturnType` (file + class + phpstan.neon
  registration + one referencing comment in `PiwigoExtension.php` + STRUCTURE
  / ROADMAP-PHP doc references). Eight stale `@var` placeholders dropped
  from `tools/phpstan-bootstrap.php` (`$user`, `$lang`, `$template`,
  `$logger`, `$pwg_event_handlers`, `$pwg_loaded_plugins`, `$service`,
  `$persistent_cache`). NoGlobalInSrcRule cleanup [D3.4] was found already
  done — docblock and REPLACEMENTS were clean before Phase 1 ran.
- **1.5** [D3.5 — Phase 1 portion] — `tools/triggers_list.php` type strings
  renamed `'trigger_change'` → `'dispatch'` and `'trigger_notify'` →
  `'notify'` (159 entries via single-file Edit replace_all); HTML filter
  dropdown updated to match. Eleven `include/` path references repointed to
  current src/ files (`page_header.php` → `PageHeaderRenderer.php`,
  `page_tail.php` → `PageTailRenderer.php`, `common.inc.php` →
  `CommonBootstrap.php`, `functions_plugins.inc.php` → `EventDispatcher.php`,
  redundant `functions.inc.php` entries dropped); one orphan event
  (`functions_mail_included`) that no longer dispatches deleted from the
  list. Event names themselves untouched — they're the API surface
  ([Phase 6.2](#62-plugin-event-api-p2) territory).

Verified: `vendor/bin/phpstan analyse` → 0 errors at level 10;
`vendor/bin/phpunit` (Unit + Integration) → 486 tests, 2390 assertions, OK.

Detail → [Appendix A §Z14](#z14-phase-1-doc-drift-sweep).

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

## Phase 2a — `WS_*` constant migration  [A3.2] ✓

Closed 2026-05-15. All 13 WS_* constants formerly emitted by `PwgServer::boot()`
have been replaced with typed enum / class-constant references. New enum
`Piwigo\Ws\WsError` added (`InvalidMethod=501`, `MissingParam=1002`,
`InvalidParam=1003`) to round out the trio with the existing `WsType` /
`WsParam`; `WS_XML_ATTRIBUTES` became a public class constant
`PwgResponseEncoder::ATTRIBUTES_KEY`. Define block in `PwgServer::boot()`,
duplicate defines in `tests/bootstrap.php`, and stubs in
`tools/phpstan-bootstrap.php` / `tools/psalm-stubs.phpstub` all deleted.

Detail → [Appendix A §Z15](#z15-phase-2a-ws_-constant-migration).

## Phase 2b — `*_TABLE` constant migration  [A3.3] ✓

Closed 2026-05-15. 30 table-name `define()` calls in
`Admin/UpgradeService::prepareConfUpgrade()` were dead code — `Piwigo\Db\Tables::*`
already covers every live caller, and the only legacy consumer
(`install/db/*-database.php` upgrade scripts) ships empty in v17. Method
deleted, sole call from `UpgradeFeedController` removed, 30 stubs purged from
`tools/psalm-stubs.phpstub`. `CADDIE_TABLE` went with the batch — caddie itself
is a live admin feature; only the legacy define is retired (see
[§Z23 fillCaddie carve-out](#z23-phase-5-utilphp-split)).

Detail → [Appendix A §Z16](#z16-phase-2b-_table-constant-migration).

## Phase 2c — `PclZip` → `ZipArchive`  [A4.1] ✓

Closed 2026-05-15. All four PclZip call sites (`Admin/Updates.php`,
`Admin/Plugins.php`, `Admin/Languages.php`, `Admin/Themes.php`) migrated to
the built-in `ZipArchive` via a new `Piwigo\Core\ZipExtractor` helper.
`pclzip/pclzip` removed from `composer.json`; `ext-zip` added to `require`;
21 `PCLZIP_OPT_*` stubs deleted from `tools/psalm-stubs.phpstub`. New
helper class covered by 9 unit tests (path traversal, prefix stripping,
selective extraction, chmod).

Detail → [Appendix A §Z17](#z17-phase-2c-pclzip-ziparchive-migration).

## Phase 2d — `xmlrpc_encode()` removal  [A3.5] ✓

Closed 2026-05-15. The PHP `xmlrpc` extension was deprecated in 8.0 and
removed from core in 8.1+; `pwg.xmlrpc` requests would fatal-error on any
modern PHP build without an explicit PECL install. `PwgXmlRpcEncoder.php`
deleted; `xmlrpc` case removed from encoder selection in `PwgServer::boot()`;
`xmlrpc_encode` function stub purged from `tools/psalm-stubs.phpstub`;
the `XML RPC` option removed from `tools/ws.htm` (developer API tester).

Detail → [Appendix A §Z18](#z18-phase-2d-xmlrpc_encode-removal).

## Phase 2e — MobileEsp dep replacement  [A4.3] ✓

Closed 2026-05-15. `ahand/mobileesp` (`\uagent_info`, ~2010-era regex-based
UA-detection library) replaced with `mobiledetect/mobiledetectlib: ^4.8`
(actively maintained, namespaced `Detection\MobileDetect`). The dep
swapped; every consumer of the UA classification (mobile-theme switcher,
iOS banner guards) is preserved unchanged.

Detail → [Appendix A §Z19](#z19-phase-2e-mobileesp-mobiledetect-swap).

## Phase 2f — `CURRENT_DATE` global retired  [A3.4] ✓

Closed 2026-05-15. The `CURRENT_DATE` PHP constant — defined in 4 places
with 2 different formats and consumed at 4 sites — replaced with inline
`new \DateTimeImmutable()->format(...)` per call site, each picking the
format its DB column needs. Latent format-collision bug (whichever path
defined first won) fixed along the way. Coincidence-of-naming with SQL
`CURRENT_DATE` keyword in `Db/SqlExpr.php` unaffected — that's a string
literal in the SQL namespace.

Detail → [Appendix A §Z20](#z20-phase-2f-current_date-global-retired).

## Phase 2g — UniversalFeedCreator removal  [A4.2] ✓

Closed 2026-05-15. `openpsa/universalfeedcreator` (2004-era untyped lib,
~50 file vendor package supporting RSS/Atom/RDF/OPML/JSON/GPX — we only
used RSS 2.0) replaced with an in-tree DOMDocument-based RSS 2.0 generator
(`Piwigo\Feed\PiwigoFeedCreator` + `Piwigo\Feed\FeedItem`). One controller
consumer (`FeedController`) migrated; file-caching side effect dropped (it
was overwritten on every request, not actually used as a cache); 8 unit
tests cover the new generator.

Detail → [Appendix A §Z21](#z21-phase-2g-universalfeedcreator-in-tree-dom-rss-generator).

## Phase 2h — Other one-off `define()` polish  [A3.6] — deferred 2026-05-15

Decision: **deferred indefinitely.** The audit (2026-05-15) found each
piece's cost-benefit different from the inventory framing, and the
inventory's own "defer unless touching the surrounding code anyway"
guidance applies to all of them. Captured here as a record of what was
looked at and why each was passed over, not as a TODO.

### Audit findings per piece

| Constant | Define sites (actual) | In-tree consumers | Why deferred |
|---|---|---|---|
| **`PHPWG_DOMAIN`** | 2 — `Bootstrap/CommonBootstrap.php:164-172` and `Controller/UpgradeController.php:79-99` (inventory listed only the first) | **0 in `src/`** | Pure dead code. Both define blocks duplicate the same locale→domain mapping; nothing reads the result. `CommonBootstrap` has an explicit comment "left intact above to ease upstream merges" — the dead define is preserved deliberately to keep diffs against upstream Piwigo smaller. **Delete blocked by the upstream-merge ergonomics, not by code dependency.** If/when the fork stops tracking upstream merges, both blocks can be deleted as ~30 lines of pure cruft. |
| **`PHPWG_URL`** | 1 — `Bootstrap/CommonBootstrap.php:177` (hardcoded `''` by fork policy: "blanked so this install never sends telemetry to upstream piwigo.org") | 28 sites — `AdminService.php` admin help-link templates (`PHPWG_URL . '/wiki'`, `'/forum'`, `'/bugs'`, etc.), `Updates.php` (telemetry endpoint), `MailService.php`, `PageTailRenderer.php` (template var), `Util.php` (telemetry endpoint) | **Migration is blocked by a product decision, not a refactor question.** With `PHPWG_URL=''`, the 28 consumers produce broken `/wiki`, `/forum`, `/bugs`-on-current-host paths in the admin UI. Cleaning that up means deciding where the fork's help links *should* point (fork docs URL? remove the help-link feature entirely? keep them broken?). That's a UX/product call, not a Phase 2h refactor. |
| **`PEM_URL`** | 1 — `Bootstrap/CommonBootstrap.php:180-186` (fork-local extensions catalog URL, derived from `$_SERVER['HTTP_HOST']` or `Config::alternativePemUrl()`) | ~10 sites — `Plugins.php`, `Themes.php`, `Languages.php`, `Updates.php`, `Util.php` (all in plugin/theme/language install + version-check flows) | Live runtime constant with real consumers. Migration to `Config::pemUrl()` is mechanical (~10 touches) but doesn't fix a bug, remove a dep, or unlock another phase. The inventory's "defer unless touching the surrounding code anyway" applies. Reasonable bundle-of-opportunity move if a future phase touches the extension-install flow. |
| **`PWG_LOCAL_DIR`** | 1 — `Bootstrap/CommonBootstrap.php:71` (literal `'local/'`) | 14 sites — various `PHPWG_ROOT_PATH . PWG_LOCAL_DIR . '...'` constructions | String literal, never varies. Could become a class constant (`AppInfo::LOCAL_DIR`?) for stricter typing but nothing currently breaks. 14 mechanical touches for low value. |
| **`MKGETDIR_*`** | ~~5 in `Core/Util.php:42-46`~~ retired 2026-05-15 in Phase 5 Batch 2 — promoted to `Piwigo\Core\Filesystem::FLAG_*` class constants (`FLAG_NONE`, `FLAG_RECURSIVE`, `FLAG_DIE_ON_ERROR`, `FLAG_PROTECT_INDEX`, `FLAG_DEFAULT`). See [§Z23](#z23-phase-5-utilphp-split). |

### Bottom line

Nothing in Phase 2h is bug-driving, dep-removing, or unlocking another
phase. The remaining pieces fall into three buckets:

- **`PHPWG_DOMAIN`**: dead code preserved for upstream-merge ergonomics.
  Delete becomes free if/when the fork drops upstream-merge tracking.
- **`PHPWG_URL`**: surfaces a *product* question (where should the fork's
  help links point?), not a refactor. Belongs in a UX discussion.
- **`PEM_URL` / `PWG_LOCAL_DIR`**: conventional runtime config, not shims.
  Pickable as bundle-of-opportunity moves when a future phase touches the
  surrounding code. **`MKGETDIR_*`** retired 2026-05-15 in Phase 5 Batch 2
  (now `Piwigo\Core\Filesystem::FLAG_*` class constants).

This phase intentionally does not move code. If any of the above
conditions changes (fork drops upstream merges, help-link UX decision
is made), reopen the relevant piece then.

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

**Status:** ✓ all 4 sub-phases closed 2026-05-15. Each sub-phase shipped
independently of the others.

## Phase 4a — `$filter` → `FilterContext` VO  [A2] ✓

Closed 2026-05-15. New immutable VO `Piwigo\Filter\FilterContext` + registry
mirroring `SectionContext` / `SectionContextRegistry`. Six readers migrated;
`FilterMiddleware` builds one immutable `FilterContext` per request. Detail →
[Appendix A §Z11](#z11-phase-4a-filtercontext-vo).

**Enabled:** Phase 5 — the Util methods that read `$GLOBALS['filter']` became
`FilterService::getFilterPageValue()` during Phase 5 Batch 2. See
[§Z23](#z23-phase-5-utilphp-split).

## Phase 4b — `header_msgs` / `header_notes` → `PageState`  [A2] ✓

Closed 2026-05-15. Both channels promoted to typed `PageState` arrays.
Audit-discovered bug fix: `header_notes` template variable was never assigned,
so integrity / filter notes were silently swallowed; `PageHeaderRenderer::render()`
now wires both `header_msgs` and `header_notes` to the template. Detail →
[Appendix A §Z12](#z12-phase-4b-header_-pagestate-properties).

## Phase 4c — `IN_ADMIN` / `IN_WS` / `PHPWG_IN_UPGRADE` → typed `RequestContext`  [A3.1] ✓

Closed 2026-05-15. New typed enum `Piwigo\Http\RequestContext` (cases
`Admin`, `Ws`, `Upgrade`, `Gallery`, `Derivative`) distributed via
`Piwigo\Http\RequestContextRegistry::set` / `current`, mirroring the
established `FilterContextRegistry` / `SectionContextRegistry` pattern.
Four entry-point controllers (`AdminController`, `WsController`,
`UpgradeController`, `ImageDerivativeController`) set the context at the
top of `__invoke`; gallery-side controllers leave the default. 13 read
sites migrated; 4 `define('IN_ADMIN', …)` and 1 `define('IN_WS', true)`
lines deleted; stubs purged from `tools/phpstan-bootstrap.php` and
`tools/psalm-stubs.phpstub`. `PHPWG_IN_UPGRADE` collapsed to a
private static `UpgradeService::$upgradeAuthorized` property; the
`checkUpgrade()` accessor reads it directly. PSR-7 request attributes
remain a possible future re-homing but are not needed for this phase.

Detail → [Appendix A §Z22](#z22-phase-4c-requestcontext-enum--registry).

## Phase 4d — `$lang_info` → `Lang` static state  [A2] ✓

Closed 2026-05-15. Locale metadata folded into the existing `Lang` static
state alongside `$data` / `$days` / `$months` (`langInfo()` / `setLangInfo()` /
`mergeLangInfo()` accessors). `LanguageStack::info/setInfo/mergeInfo` delegate
to `Lang`. Detail → [Appendix A §Z13](#z13-phase-4d-lang_info-lang-static-state).

---

# Phase 5 — `Util.php` Split  [A5.1] ✓

Closed 2026-05-15 in three sequential batches. The 1058-line
`src/Piwigo/Core/Util.php` god-class is gone — file deleted, no compat shim.
Twenty methods carved into eight new typed services, three method moves to
existing services (`HtmlService`, `FilterService`, `MailService`), one move
to an existing storage helper (`Piwigo\Core\Filesystem::mkgetdir`), and one
to a repository (`ImageRepository::addToUserCaddie`). The `pwgActivity`
union-typed signature became the typed `ActivityEvent` DTO + `ActivityObject`
enum.

Detail → [Appendix A §Z23](#z23-phase-5-utilphp-split).

---

# Phase 6 — Extension-API Compat Removal

**Status:** open, but most sub-tasks deferred 2026-05-15 pending the §1.3
plugin architecture rewrite. The v17.0 version bump has shipped
(`AppInfo::VERSION = '17.0.0'`) and intentionally breaks all PEM extensions
by policy, so the *external* BC layer is no longer load-bearing — but the
*in-tree* surfaces (procedural plugin loader, event dispatcher, frontend BC
queues, Latte template API) double as the staging ground for the
replacement plugin contract. Removing them before the §1.3 replacement
exists would leave the codebase in a half-built state. Re-investigate when
the plugin architecture lands.

**2026-05-15 audit findings (single source of truth for current state):**

1. **The 133 `.latte` templates are already Latte-native** (zero remaining
   Smarty surface syntax — verified via grep, see [6.3](#63-piwigoextensionphp--piwigo-latte-template-api-p3) below).
   The "rewrite 133 templates" framing in the original 6.3 description
   was wrong: the Smarty → Latte template conversion already happened in an
   earlier phase. What looked like "Smarty pipes" is Latte syntax using
   Piwigo-registered filters that share Smarty's pipe-first arg convention
   to ease the historical conversion.
2. **`PiwigoExtension.php` is no longer a Smarty-compat shim** — it is the
   Piwigo Latte template API. Every filter / function it registers is also
   listed in `src/Piwigo/Template/Latte/PiwigoPolicy.php` as the staged
   plugin template API for §1.3.
3. **All 17 internal `EventDispatcher::addListener` call sites** register
   hardcoded framework callbacks (no `include_path` use). The event
   dispatcher is not currently a plugin extension surface; it's an internal
   message bus dressed up as one. Replacement design belongs to the §1.3
   plugin architecture.
4. **`tools/triggers_list.php`** has zero runtime references (purely an
   author-facing reference doc), but listing what events the not-yet-built
   plugin system *will* expose is part of the §1.3 design — defer.
5. **`globals.d.ts` plugin-facing declarations** (`SwitchBox`,
   `_pwgRatingAutoQueue`) are the TypeScript half of the frontend BC
   queues in 6.4 — they defer with that sub-task.

**Sub-task statuses after the 2026-05-15 audit:**
- 6.1 (P1) — **deferred** with §1.3 (procedural loader is the staged
  plugin contract)
- 6.2 (P2) — **deferred** with §1.3 (event dispatcher is the staged
  plugin extension surface)
- 6.3 (P3) — **largely complete**; remaining sub-pieces re-scoped (see
  below)
- 6.4 (P4) — **deferred** with §1.3 (frontend BC queues are the staged
  plugin frontend entry points)
- 6.5 (D4) — **partially available** (header comment + a handful of dead
  declarations); plugin-facing subset defers with 6.4

**Hard dependencies:** none on Phases 1–5; 6.1 / 6.2 / 6.4 / 6.5-plugin-subset
gated on §1.3 plugin architecture.

## 6.1 Plugin/theme procedural contract  [P1]

**Deferred 2026-05-15** with §1.3. The procedural runtime described below
is the staging ground for the new plugin contract. Removing it before §1.3
ships would mean no plugin contract at all. When §1.3 lands, the procedural
loader gets removed *alongside* the typed replacement, not before. See
[[no-shim-removal-before-replacement]].

Repo state at audit time: zero in-tree plugins (`plugins/` contains only a
security `index.php`); zero in-tree themes ship `admin/maintain.inc.php`;
`PluginMaintain` / `ThemeMaintain` are empty marker classes. So deletion
would not break any *currently running* code — but it would erase the
contract for the future.

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

**Deferred 2026-05-15** with §1.3. The event dispatcher is the staged
plugin extension surface; deleting it before §1.3 produces a typed
replacement would erase Piwigo's extensibility contract entirely.

Audit findings to carry into §1.3 planning:

- 17 internal `EventDispatcher::addListener` calls catalogued; all register
  hardcoded framework callbacks, none use the `include_path` mechanism. Of
  the 17, only 6 listeners do non-trivial work (URL token protection,
  comment HTML sanitization, `pwg_nl2br`, `strip_tags`, `str2url`,
  `ws_invoke_allowed` gating). The other 11 are trivial inlinable
  one-liners.
- `EventDispatcher::dispatch()` get-hook return pattern is uniform: returns
  `$args[0]` after passing through listeners. The mutate-in-place idiom is
  consistent across all 98 `dispatch()` call sites.
- `notify()` (119 call sites) is fire-and-forget; semantically distinct
  from `dispatch()` and used consistently.
- The `'trigger'` meta-event (EventDispatcher.php:75–77, 96–98, 105–107)
  has zero internal listeners. Pure plugin-API surface — exists so plugins
  can introspect all event flow.
- `composer.json` does NOT declare `psr/event-dispatcher`. The current
  dispatcher is homegrown; a future PSR-14 alignment is open design space
  for §1.3.

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

Phase 1 already cleaned the `'type'` field strings; Phase 6.2 either
rewrites this file to match the new typed-event shape or deletes it.

### Rename to PSR-14 typed events  [P2.3]

Rename the 153 legacy event names to PSR-14 typed events. The version bump
already broke the legacy names by policy, so this is a mechanical rewrite of
every `EventDispatcher::dispatch()` / `notify()` call site.

## 6.3 `PiwigoExtension.php` — Piwigo Latte template API  [P3]

**Reframed 2026-05-15 after a full audit.** The original framing — "rewrite
133 templates to native Latte, then delete the Smarty-port filters" — was
wrong. Audit findings:

1. **Smarty surface syntax is already gone from every template.** Grep
   across `themes/**/*.latte` shows **zero** occurrences of
   `{html_options}`, `{html_radios}`, `{html_checkboxes}`, `{math …}`,
   `{section}`, `{foreach from=…}`, `{include file=…}`, `{assign var=…}`,
   `{capture name=…}`, `{literal}`, `{php}`, `{strip}`, `{ldelim}`,
   `{rdelim}`. The 133-template Smarty → Latte migration is complete; this
   was confused with "remaining Smarty-syntax compat" in the original P3
   description.
2. **`PiwigoExtension.php` (808 LOC) is the Piwigo Latte template API**,
   not a Smarty-compat shim. Every filter and function it registers is
   also listed in `src/Piwigo/Template/Latte/PiwigoPolicy.php` as the
   staged plugin template API for §1.3:
   - `getFilters()` registers 35 entries — translation pair (`translate`,
     `translate_dec`), 19 PHP passthroughs (`sprintf`, `urlencode`, …),
     7 Smarty-pipe-convention wrappers (`number_format`, `cat`,
     `strip_tags`, `default`, `date_format`, `explode`, `l10n` alias),
     and 8 small custom helpers.
   - `getFunctions()` registers 14 entries — 9 asset-pipeline / image
     helpers (`combineScript`, `getCombinedScripts`, `combineCss`,
     `getCombinedCss`, `defineDerivative`, `derivative`, `htmlHead`,
     `htmlStyle`, `footerScript`), 3 form/eval helpers (`htmlOptions`,
     `htmlRadios`, `math`), and 2 duplicates of filter-form helpers
     (`url_is_remote`, `l10n`).
3. **The historical "Smarty-port" framing in `PiwigoExtension.php`'s
   docstring** (lines 27–60, 72–87) describes how the filter set was
   originally chosen during the Smarty → Latte migration: pipe-first
   convention preserved so converted templates kept working. That choice
   is now load-bearing for templates, not transitional.

**Actual filter / function usage across 133 templates** (audit 2026-05-15):

| Filter / Function | Pipe / Call count | Files | Status |
|---|---|---|---|
| `\|translate` | 1815 | 110 | Heavy use — keep |
| `\|translate_dec` | 25 | 13 | Used — keep |
| `combineScript()` | 119 | 74 | Heavy use — keep |
| `combineCss()` | 77 | 52 | Heavy use — keep |
| `htmlOptions()` | 50 | 24 | Used — keep |
| `\|json_encode` | 13 | 9 | Used — keep |
| `\|default:` | 11 | 3 | Used — keep |
| `getCombinedScripts()` | 10 | 8 | Used — keep |
| `\|sprintf` `\|urlencode` `\|htmlspecialchars` | 6 each | 5–6 | Used — keep |
| `\|nl2br` `\|strip_tags` | 5 each | 4 | Used — keep |
| `getCombinedCss()` `htmlRadios()` `\|cat:` `\|str_repeat` | 4–6 each | 2–5 | Used — keep |
| `derivative()` `\|number_format` `\|intval` `\|count` | 3–4 each | 1–3 | Used — keep |
| `\|date_format` `url_is_remote()` `l10n()` `math()` | 1–2 each | 1–2 | Used — keep |
| `\|stripslashes` `\|in_array` `\|ucfirst` `htmlHead()` | 1 each | 1 | Used — keep |
| `\|file_exists` `\|constant` `\|json_decode` `\|trim` `\|md5` `\|strtolower` `\|is_null` `\|is_file` `\|strpos` `\|sizeOf` | 0 | 0 | **Registered but never called** |
| `defineDerivative()` `htmlStyle()` `footerScript()` | 0 | 0 | **Registered but never called** |

**Remaining real work for P3 (post-audit):**

1. **[P3.1] Update `PiwigoExtension.php` docstring** (lines 27–60, 72–87).
   Replace the Smarty-conversion narrative with the current reality: this
   is the Piwigo Latte template API, allowlisted via `PiwigoPolicy`. Pure
   docs change.
2. **[P3.2] Audit deletable filters/functions** (the 10 filters + 3
   functions with zero template callers above). Each is also listed in
   `PiwigoPolicy::PLUGIN_FILTERS` or `PLUGIN_FUNCTIONS` /
   `CORE_FILTERS`. Deletion is a *paired* edit (drop from
   `PiwigoExtension::getFilters()` / `getFunctions()` AND from the
   matching `PiwigoPolicy` allowlist). Because `PiwigoPolicy` is the
   staged plugin template API for §1.3, this audit should run alongside
   the §1.3 plugin architecture rewrite — not before. **Deferred** with
   §1.3.
3. **[P3.3] Decide on `htmlOptions` / `htmlRadios` / `math` long-term.**
   Currently used by 24 / 2 / 1 templates respectively. Two paths to weigh
   when §1.3 lands:
   - Keep them as the Piwigo Latte form-helper API (status quo)
   - Rewrite the 24 / 2 / 1 templates to inline `<select>` HTML / radio
     groups, then delete the helpers from both `PiwigoExtension` and
     `PiwigoPolicy`
   This is a design call coupled to the plugin-template-API surface
   choice, so **deferred** with §1.3.
4. **[P3.4 — completed already]** 133-template Smarty → Latte syntactic
   conversion. Historical, not "still ahead". No current open work.

**Net status:** P3.1 (docstring update) is the only currently-actionable
piece. Everything else defers with §1.3.

## 6.4 Frontend plugin BC queues  [P4]

**Deferred 2026-05-15** with §1.3. These three pre-load auto-queue patterns
exist so third-party plugins can inject frontend behaviour before the
relevant bundle has loaded. They are the staged frontend extension entry
points for the not-yet-built plugin system; deleting them before §1.3 ships
a typed alternative would leave the frontend with no plugin contract at all.

The patterns:

- **`_pwgRatingAutoQueue`** — drained by `themes/_base/js/rating.ts:150`
- **`SwitchBox`** — drained by `themes/_base/js/switchbox.ts:35`
- **`_cont`** window-global alias at `themes/admin/_base/js/albums.ts:522`
  (`_cont = contEl;`)

Removable as one tight diff (three line-level edits and a search-and-verify)
once §1.3 has produced the replacement frontend plugin contract.

## 6.5 Frontend `globals.d.ts` cleanup  [D4]

**Partially available 2026-05-15.** Header comment and confirmed-dead
declarations can be edited now; plugin-facing declarations defer with 6.4.

`src/types/globals.d.ts` is the TypeScript ambient-globals declaration file
(111 lines). Header comment says "Smarty templates" — stale (templates are
Latte, see [6.3](#63-piwigoextensionphp--piwigo-latte-template-api-p3)).

| Category | Status | Phase 6 disposition |
|---|---|---|
| Template-emitted constants (`pwg_token`, `pwg_root_url`, `cookie_*`) | Populated via `<script type="application/json" id="pwg-page-data">` JSON islands, not inline `var x = …` blocks. | Verify still in islands; declarations stay until JSON-island schema reduces them. |
| Cross-bundle functions (`pwgBind`, `pwgAddEventListener`, `pwgToaster`, `phpWGOpenWindow`, `popuphelp`) | Active — defined in `themes/_base/js/scripts.ts` and hoisted to `window`. | Keep. |
| PHP-style JS helpers (`array_delete`, `str_repeat`, `getRandomInt`, `sprintf`) | Implemented in `themes/admin/_base/js/common.ts`; the naming dates from Smarty-era ports but the helpers are live. | Keep; rename out of Phase 6 scope. |
| Profile-specific i18n vars (`selected_date`, `no_time_elapsed`, `str_*`) | Set via inline `<script>` from `profile.latte`. | Keep until JSON-island migration covers profile page. |
| `Window.PwgWS`, `Window.LocalStorageCache` + subclasses | Active — intentionally hoisted for cross-bundle reuse. | Keep. |
| `SwitchBox` (line 50), `_pwgRatingAutoQueue` (lines 53–55) | Plugin-facing BC queue declarations matching [6.4](#64-frontend-plugin-bc-queues-p4). | **Defer with 6.4.** |
| Likely-stale (need re-grep): `var user`, `var preferencesDefaultValues`, `var standardSaveSelector` | Earlier audit (pre-2026-05-15) reported no grep hits in `themes/`; needs re-verification. | Drop if confirmed dead. |

**Currently actionable:**
- Update the "Ambient globals injected by Smarty templates" header (lines
  1–2) to reflect the JSON-island model: "Ambient globals injected by
  Latte templates via JSON islands (`<script id='pwg-page-data'
  type='application/json'>`) or exposed by TypeScript bundles."
- Drop confirmed-dead declarations after re-grep (the three above, if
  still dead).

**Deferred:** the plugin-facing pair (`SwitchBox`, `_pwgRatingAutoQueue`) —
ships alongside the 6.4 BC-queue removal once §1.3 produces the
replacement frontend plugin contract.

## 6.6 Phase 6 task summary

Revised 2026-05-15 after the full audit. The deferred rows below revisit
together once §1.3 has produced a typed plugin contract — at that point
the legacy bridges come out *alongside* the replacement, not before.

| Task | Disposition |
|---|---|
| Delete plugin-loader paths (P1.1, P1.4) | **Deferred** with §1.3 |
| Delete `Admin/Plugins.php` pre-2.7 BC branching (P1.2) | **Deferred** with §1.3 |
| Delete theme contract (P1.3) | **Deferred** with §1.3 |
| Remove `plugin_*` / `theme_*` callback stubs from `tools/phpstan-bootstrap.php` (P1.5) | **Deferred** with §1.3 (lives with P1) |
| Rewrite legacy event names as PSR-14 typed events (P2) | **Deferred** with §1.3 |
| Rewrite or delete `tools/triggers_list.php` (P2.2, D3.5 part 2) | **Deferred** with §1.3 |
| Rewrite all 133 `.latte` templates from Smarty to native Latte (P3 original framing) | **Completed already** (pre-Phase-6 work) — Smarty surface syntax is gone from every template; what remains is Latte syntax using the Piwigo template API |
| Update `PiwigoExtension.php` docstring to drop Smarty-conversion narrative (P3.1) | **Actionable now** — pure docs change |
| Drop unused PiwigoExtension filters/functions in lockstep with PiwigoPolicy (P3.2) | **Deferred** with §1.3 — `PiwigoPolicy` is the staged plugin template API |
| Decide `htmlOptions` / `htmlRadios` / `math` long-term (P3.3) | **Deferred** with §1.3 |
| Delete frontend BC queues (P4) | **Deferred** with §1.3 |
| Update `globals.d.ts` header comment + drop confirmed-dead non-plugin declarations (D4 partial) | **Actionable now** |
| Remove `SwitchBox` / `_pwgRatingAutoQueue` declarations (D4 plugin-coupled subset) | **Deferred** with P4 / §1.3 |

---

# Hard Dependencies — Summary Graph

```
   Phase 1 (parallel)        ──→  (no downstream gate)
   all standalone

   Phase 2a (WS_*)         ──┐
   Phase 2b (*_TABLE)      ──┤
   Phase 2c (PclZip)       ──┤
   Phase 2d (xmlrpc)       ──┤  done 2026-05-15
   Phase 2e (MobileEsp)    ──┤  (see Appendix A §Z15–§Z21)
   Phase 2f (CURRENT_DATE) ──┤
   Phase 2g (FeedCreator)  ──┘
   Phase 2h (other defines)   ── deferred 2026-05-15 (see Phase 2h section)

   Phase 3a (4 channels)  ──┐
   Phase 3b (3 channels)  ──┤
   Phase 4a ($filter)     ──┤
   Phase 4b (header_*)    ──┼──  done 2026-05-15
   Phase 4c (RequestCtx)  ──┤  (see Appendix A §Z9–§Z13, §Z22)
   Phase 4d (lang_info)   ──┤
   Phase 5 (Util split)   ──┘  (see Appendix A §Z23)

   Phase 6 (extension-API)   ──  independent of Phases 1-5; most sub-tasks
                                gated on §1.3 plugin architecture (see Phase 6)
   §A1 ($page alias)         ──  done 2026-05-15 (shipped early, §Z1.1)
```

Notes:

- Phase 2 tasks are internally sequenced (code → runtime → stubs) but
  externally parallel.
- Phases 3 / 4 / 5 are all done. Phase 5 did not need Phase 2e — the
  mobile-theme switcher stays as a feature, so `mobileTheme` / `getDevice`
  carved into the new `DeviceDetectionService` (Batch 2) rather than being
  removed.
- Phase 6 has no hard dependency on any other phase, but the 2026-05-15
  audit reframed most of it: 6.3's "rewrite 133 templates" was already
  complete; 6.1 / 6.2 / 6.4 / 6.5-plugin-subset / 6.3's residual deletions
  defer with §1.3 plugin architecture. Only the cosmetic docstring update
  in 6.3 (P3.1) and the non-plugin globals.d.ts trim in 6.5 (D4 partial)
  remain actionable now.

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
| `$GLOBALS['prefixeTable']` | Both readers (`UpgradeService::prepareConfUpgrade()` [later deleted in Phase 2b], `MaintenanceService::repairAndOptimize()`) call `Config::dbPrefix()` directly. All 5 writes deleted (`CommonBootstrap:83`, `UpgradeController:49`, `InstallController:56`, `index.php:38`, `index.php:66`). `phpstan-bootstrap.php` `$prefixeTable` stub dropped; `ContainerSmokeTest` test plumbing line removed. |
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

## Z14. Phase 1 Doc-Drift Sweep

Closed 2026-05-15. One-pass mechanical cleanup of comments, tooling stubs,
and the plugin-author event-reference doc. No runtime behaviour changes.

### Code comments / docstrings rewritten

- **Stale `$GLOBALS` bridge claims** [D1.1] — 7 docstrings rewritten:
  `UserBootstrap.php:18-25`, `AuthMiddleware.php:14-21`,
  `Config.php:21-25`, `ConfigStorage.php:26-29` (full docblock rewrite),
  `Translator.php:97-100`, `LanguageStack.php:33-39`,
  `CurrentUser.php:17-20` (added clarifying note that the
  `attachGlobals()` method name is historical — body no longer touches
  any global; rename would touch callers, deferred).
- **Stale `include/` / `admin/` doc references** [D1.2] — 17 single-line
  or short-block edits across `src/` and `config/storage.php`. Two
  docblocks rewritten in full:
  `ConfigStorage.php` ("Thin OO facade…" → "Storage backend for the conf
  table…") and `MailNotificationContext.php` (now names the actual
  current callers — `NotificationAdminService` and `MailService`).
  Pointer updates: `WsParam.php` / `WsType.php` now say
  "Values mirror the WS_PARAM_*/WS_TYPE_* runtime constants emitted by
  `PwgServer::boot()`"; `ImageDerivativeController.php` points helpers at
  `DerivativePipeline`; `config/storage.php` points `PWG_LOCAL_DIR` at
  `CommonBootstrap`; `Config.php`'s "(formerly PHP define()s in
  include/constants.php)" historical aside dropped; `ConfigLoader.php`'s
  "Replaces the legacy include/config_default.inc.php" historical aside
  dropped. `Repository::*` doc lines of the form "Used by admin/X.php"
  removed entirely (the action verb in the method name documents itself).
  The `SelectedTagsRenderer.php:43` template-path note from D1.3 was
  preserved as a deliberate non-fix.

### Tooling

- **psalm.xml** [D2.1] — both suppression comments rewritten. `MissingFile`
  comment now describes the dynamic plugin / theme / language file include
  pattern it's actually catching. `UnsupportedPropertyReferenceUsage`
  comment names the two real call sites
  (`EventDispatcher` handler buckets, `LoadedPluginRegistry::plugin_data`)
  rather than the stale "legacy compatibility bridge" framing.
- **PHPStan dead extension deleted** [D3.2] —
  `tools/phpstan/PwgGetSessionVarDynamicReturnType.php` removed (zero
  callers; `SessionService::getSessionVar()` replaced the targeted free
  function). The `services:` block in `phpstan.neon` lost the
  `dynamicFunctionReturnTypeExtension` registration.
- **PHPStan extension renamed** [D3.3] —
  `tools/phpstan/TriggerChangeDynamicReturnType.php` → 
  `tools/phpstan/EventDispatcherDispatchDynamicReturnType.php` (`git mv`),
  class name updated, `phpstan.neon` registration updated, one referencing
  comment in `src/Piwigo/Template/Latte/PiwigoExtension.php:415` updated,
  doc tables in `docs/STRUCTURE.md:293` and `docs/ROADMAP-PHP.md:2296`
  updated.
- **phpstan-bootstrap.php stale `@var` placeholders dropped** [D3.1A] — 8
  blocks deleted (`$user`, `$lang`, `$template`, `$logger`,
  `$pwg_event_handlers`, `$pwg_loaded_plugins`, `$service`,
  `$persistent_cache`). File dropped from 93 lines to 75. The remaining
  runtime-constant duplicates (Group B) and procedural callback stubs
  (Group C) are out of Phase 1 scope; Group B tracks
  [Phase 2a](#phase-2a--ws_-constant-migration-a32) and
  [Phase 4c](#phase-4c--in_admin--in_ws--phpwg_in_upgrade--typed-requestcontext-a31);
  Group C is required by [Phase 6](#phase-6--extension-api-compat-removal).
- **NoGlobalInSrcRule cleanup** [D3.4] — found already done. The docblock
  no longer mentioned `include/` / `admin/`, and `persistent_cache` was
  already gone from `REPLACEMENTS`. No-op for Phase 1.

### `tools/triggers_list.php`

[D3.5 — Phase 1 portion]:

- `'type' => 'trigger_change'` → `'type' => 'dispatch'` and
  `'type' => 'trigger_notify'` → `'type' => 'notify'` across 159 entries
  via single-file `Edit replace_all`. HTML filter dropdown in the rendered
  page updated to match (`<option value="dispatch">` / `notify`).
- 11 `include/` path references repointed:
  `include/page_header.php` (×4) → `src/Piwigo/Page/PageHeaderRenderer.php`;
  `include/page_tail.php` (×2) → `src/Piwigo/Page/PageTailRenderer.php`;
  `include/common.inc.php` (×2) → `src/Piwigo/Bootstrap/CommonBootstrap.php`;
  `include/functions.inc.php` (×2) — both were the redundant first entry
  in a multi-file list (alongside `ConfigService.php` for `load_conf` and
  alongside `CommonBootstrap` / `Util` / `MailService` for `loading_lang`)
  — dropped entirely;
  `include/functions_plugins.inc.php (trigger_change, trigger_notify)` → 
  `src/Piwigo/Plugins/EventDispatcher.php (dispatch, notify)`.
- The orphan event `functions_mail_included` was dispatched by the
  deleted `include/functions_mail.inc.php` and is never fired anywhere
  in `src/`; its entry was deleted from the reference doc rather than
  pointed at an unrelated file. (Event-API rewrite, including any further
  pruning of dead events, is
  [Phase 6.2](#62-plugin-event-api-p2).)

### Verification

- `vendor/bin/phpstan analyse --no-progress` → 0 errors at level 10.
- `vendor/bin/phpunit` (Unit + Integration) → 486 tests, 2390 assertions, OK.
- `composer dump-autoload --classmap-authoritative` run after the
  PHPStan-extension rename.

## Z15. Phase 2a WS_* Constant Migration

Closed 2026-05-15. All 13 `WS_*` runtime constants formerly emitted by
`PwgServer::boot()` have been replaced with typed enum / class-constant
references across `src/` and the test suite.

### New types

- **`Piwigo\Ws\WsError`** (new enum, parallels `WsType` / `WsParam`):
  `InvalidMethod=501`, `MissingParam=1002`, `InvalidParam=1003`. These three
  codes were the only ones with constant names; the rest of the Piwigo
  error-code space (101/102/103, 400/401/403/415, etc.) is still passed to
  `PwgError` as bare ints and is left as-is.
- **`PwgResponseEncoder::ATTRIBUTES_KEY`** (new public `const string` =
  `'attributes_xml_'`): the marker key used inside response structs to attach
  XML-attribute metadata. Replaces the `WS_XML_ATTRIBUTES` magic string at
  three reader sites (`PwgResponseEncoder::flatten`, `PwgRestEncoder`,
  `ImagesEndpoints`).

### Migration mapping

| Old token | New |
|---|---|
| `WS_TYPE_BOOL` | `WsType::Bool->value` |
| `WS_TYPE_INT` | `WsType::Int->value` |
| `WS_TYPE_FLOAT` | `WsType::Float->value` |
| `WS_TYPE_POSITIVE` | `WsType::Positive->value` |
| `WS_TYPE_NOTNULL` | `WsType::NotNull->value` |
| `WS_TYPE_ID` | `WsType::Id->value` |
| `WS_PARAM_ACCEPT_ARRAY` | `WsParam::AcceptArray->value` |
| `WS_PARAM_FORCE_ARRAY` | `WsParam::ForceArray->value` |
| `WS_PARAM_OPTIONAL` | `WsParam::Optional->value` |
| `WS_ERR_INVALID_METHOD` | `WsError::InvalidMethod->value` |
| `WS_ERR_MISSING_PARAM` | `WsError::MissingParam->value` |
| `WS_ERR_INVALID_PARAM` | `WsError::InvalidParam->value` |
| `WS_XML_ATTRIBUTES` | `PwgResponseEncoder::ATTRIBUTES_KEY` |

Composite `WS_TYPE_*` OR-patterns at call sites (e.g.
`WS_TYPE_INT | WS_TYPE_POSITIVE`) translate term-for-term
(`WsType::Int->value | WsType::Positive->value`); the `WS_TYPE_ID` composite
case on the enum keeps it short where it stood alone.

### Files touched

- **Source migrations** — 15 files with replacements (largest:
  `WsMethodRegistrar.php` 186 hits; `PwgServer.php` 47 hits;
  `ImagesEndpoints.php` 21 hits). `Piwigo\Ws\Method\*Endpoints`,
  `Piwigo\Ws\Protocol\PwgRestRequestHandler`, `Piwigo\Users\UserService`,
  `Piwigo\Ws\Protocol\PwgRestEncoder` gained
  `use Piwigo\Ws\WsError;` and/or `use Piwigo\Ws\Encoder\PwgResponseEncoder;`
  imports. Files already in `Piwigo\Ws` namespace (`PwgServer`, `WsHelper`,
  `WsMethodRegistrar`) need no imports.
- **`UserService.php`** — also routes its array-shape error returns
  (`['error' => ['code' => 1003, ...]]`) through `WsError::InvalidParam->value`
  for consistency with the PwgError call sites.
- **Tests** — `tests/Unit/Ws/PwgServerTest.php` (7 hits) and
  `tests/Unit/Ws/SpecBuilderTest.php` (8 hits) migrated. `PwgErrorTest.php`
  left untouched — its bare `1003` is testing that `PwgError` accepts an
  arbitrary `int`, not coupling to `WsError`.
- **Encoder** — `Piwigo\Ws\Encoder\PwgResponseEncoder` gained the new
  `ATTRIBUTES_KEY` class constant; `WS_XML_ATTRIBUTES` consumers migrated.
- **Define / stub deletions:**
  - `PwgServer::boot()` — entire 13-`define()` block deleted.
  - `tests/bootstrap.php` — WS_* and WS_XML_ATTRIBUTES `define()` blocks
    deleted (the unrelated `QST_*` search-constant block kept).
  - `tools/phpstan-bootstrap.php` — WS_* `define()` block deleted.
  - `tools/psalm-stubs.phpstub` — 13 `const WS_*` declarations deleted.
- **Docstrings** — `WsType` and `WsParam` had docstrings claiming
  "Values mirror the WS_*… constants emitted by PwgServer::boot()" — the
  reference is now incoherent (the constants are gone), so the line was
  dropped. `WsError`'s new docstring notes the same history with "retired
  in Phase 2a".

### Migration philosophy

The inventory's original plan said "WS_ERR_INVALID_PARAM →
`WsError::InvalidParam->value` / WS_TYPE_INT → `WsType::Int->value` / etc."
First-pass attempted to use bare int literals for the three WS_ERR_* codes
(reasoning: the codebase already uses 400/401/403 as bare ints in PwgError
calls, so adding more bare ints "stays consistent"). On feedback that bare
ints lose semantic information, the work backed out to create the
`WsError` enum and route the three codes through it as the inventory had
originally specified. The pre-existing bare 400/401/403/etc. PwgError codes
are left as-is — they were never WS_ERR_* constants in the first place and
are out of Phase 2a's scope.

### Verification

- `vendor/bin/phpstan analyse --no-progress` → 0 errors at level 10.
- `vendor/bin/phpunit` (Unit + Integration) → 486 tests, 2390 assertions, OK.
- `composer dump-autoload --classmap-authoritative` run after `WsError.php`
  was added (the project pins `classmap-authoritative: true`).

## Z16. Phase 2b `*_TABLE` Constant Migration

Closed 2026-05-15. 30 table-name `define()` calls in
`Admin/UpgradeService::prepareConfUpgrade()` retired as dead code.

### What was actually true at start

The inventory listed three tasks (rewrite legacy upgrade SQL → delete defines
→ delete stubs). On audit, task #1 was unnecessary: a cross-tree grep showed
the 30 constants (`CATEGORIES_TABLE`, `IMAGES_TABLE`, `USER_INFOS_TABLE`, …,
including `CADDIE_TABLE`) had **zero** consumers anywhere — `src/`, `tests/`,
`install/`. `Piwigo\Db\Tables::*()` already covered every live caller
(`UserAdminService.php:70-73` is the canonical example). The only legacy
consumer pathway — `install/db/<id>-database.php` upgrade scripts dynamically
required by `UpgradeFeedController` — ships empty in v17 (`install/db/`
contains only an `index.php` placeholder), so there was nothing reading the
constants there either.

### What landed

- **`Admin/UpgradeService::prepareConfUpgrade()` deleted** (entire method,
  lines 29-63). It only existed to populate the 30 defines.
- **`Controller/UpgradeFeedController:36`** — the sole call to
  `UpgradeService::prepareConfUpgrade()` removed. The controller's own
  `define('PREFIX_TABLE', …)` and `define('UPGRADES_PATH', …)` calls remain;
  those constants are still consumed at lines 41/57/54 of the same controller
  and stay alive.
- **`tools/psalm-stubs.phpstub`** — the 30 `const *_TABLE = '';` stubs deleted
  (lines 28-57 in the pre-change file). `PREFIX_TABLE` and
  `DEFAULT_PREFIX_TABLE` kept — those are table-*prefix* strings, separate
  identifier space, still defined and consumed.
- **`tools/phpstan-bootstrap.php`** — no change needed (only `PREFIX_TABLE`
  was stubbed here, not the 30 table-name constants).
- **`src/Piwigo/Users/UserRepository.php:107`** — `@param` docstring example
  rewritten: "`(e.g. USER_INFOS_TABLE)`" → "`(e.g. `Tables::userInfos()`)`".
  The actual `$tableName` parameter is already passed as a `Tables::*` string
  by the only caller (`UserAdminService.php:84`); only the prose was stale.

### What about CADDIE_TABLE?

`CADDIE_TABLE` came along with this batch as part of the same dead-define
block. **The caddie feature is unaffected** — it's a live admin feature (a
per-user shopping cart for the printing/order-form mechanism), and its
runtime table access goes through the (yet-to-be-written) Tables accessor
just like the others. Only the legacy procedural define was retired here.
`fillCaddie` moved to `ImageRepository::addToUserCaddie()` during Phase 5
Batch 1 — see [§Z23](#z23-phase-5-utilphp-split).

### Files touched

- `src/Piwigo/Admin/UpgradeService.php` — `prepareConfUpgrade()` removed.
- `src/Piwigo/Controller/UpgradeFeedController.php` — call site removed.
- `src/Piwigo/Users/UserRepository.php` — docstring example refreshed.
- `tools/psalm-stubs.phpstub` — 30 stubs purged; comment reframed.

### Why this was cheaper than the original three-step plan

The original plan assumed legacy upgrade SQL inside `UpgradeService.php`
still referenced the constants. It did not — the SQL had already been
migrated to `Tables::*()` accessors in earlier waves. So the migration
collapsed to deleting dead code rather than rewriting it.

### Verification

- `vendor/bin/phpstan analyse --no-progress` → 0 errors at level 10.
- `vendor/bin/phpunit` (Unit + Integration) → 486 tests, 2390 assertions, OK.
- `composer dump-autoload --classmap-authoritative` run after the edits
  (no new files, but the project pins `classmap-authoritative: true`).
- Post-edit cross-tree grep: `grep -rEn "\b[A-Z][A-Z_]+_TABLE\b" --include="*.php"
  --include="*.sql"` returns nothing outside `PREFIX_TABLE` /
  `DEFAULT_PREFIX_TABLE` (the surviving prefix constants).

## Z17. Phase 2c PclZip → ZipArchive Migration

Closed 2026-05-15. All four `\PclZip` call sites migrated to the built-in
`ZipArchive` via a new `Piwigo\Core\ZipExtractor` helper; the
`pclzip/pclzip` Composer dependency is gone.

### New type

- **`Piwigo\Core\ZipExtractor`** — minimal extraction helper. Two public
  static methods:
  - `listNames(string $archivePath): list<string>` — returns archive entry
    stored-names in order; `[]` on open failure. Replaces PclZip's
    `listContent()` for the sentinel-scan use case.
  - `extract(string $archivePath, string $extractPath, string $stripPrefix
    = '', ?list<string> $onlyNames = null, ?int $chmod = null): list<array{
    filename: string, stored_filename: string, status: string}>` — extracts
    entries (optionally restricted to a name list), stripping `$stripPrefix`
    from each stored name before writing. Returns a per-entry status row
    array whose shape mirrors PclZip's so call-site inspection logic ports
    unchanged: `stored_filename` is the archive's entry name; `filename` is
    the on-disk path *relative to `$extractPath`*. Path-traversal is
    blocked by lexically resolving each computed target and rejecting any
    that escapes `$extractPath` (`STATUS_PATH_ERROR`).
- **Status string constants** mirror PclZip's: `ok`, `already_a_directory`,
  `filtered`, plus three failure cases not enumerated by PclZip
  (`write_error`, `open_error`, `path_error`).

### Behavioral mapping

| PclZip surface | ZipExtractor equivalent |
|---|---|
| `new \PclZip($p); $zip->listContent()` | `ZipExtractor::listNames($p)` |
| `PCLZIP_OPT_PATH` | `$extractPath` argument |
| `PCLZIP_OPT_REMOVE_PATH` | `$stripPrefix` argument |
| `PCLZIP_OPT_SET_CHMOD` | `$chmod` argument |
| `PCLZIP_OPT_BY_NAME` | `$onlyNames` array |
| `PCLZIP_OPT_REPLACE_NEWER` | (dropped — ZipArchive always overwrites; in install/upgrade flow the archived file IS the new version, so the "only if newer" guard was vacuous) |
| return `[i => ['filename'=>…, 'stored_filename'=>…, 'status'=>…]]` | return `list<array{filename, stored_filename, status}>` — same keys |

### Files touched

- **`src/Piwigo/Core/ZipExtractor.php`** — new helper class (~170 lines).
- **`src/Piwigo/Admin/Plugins.php`** — `extractPluginFiles()` migrated. The
  pre-scan loop now reads `listNames()` directly (returns flat names instead
  of PclZip's per-entry assoc arrays). `\PclZip` import and noisy
  `is_array($listRaw) && $listRaw` / type-juggling on result rows removed
  thanks to the new return type's typed array shape.
- **`src/Piwigo/Admin/Languages.php`** — `extractLanguageFiles()` migrated;
  same shape as Plugins.
- **`src/Piwigo/Admin/Themes.php`** — `extractThemeFiles()` migrated; same
  shape as Plugins.
- **`src/Piwigo/Admin/Updates.php`** — `submitMaintenance()` core-update
  extraction migrated. The retry-on-error loop (chmod + re-extract single
  failed entry) now uses `$onlyNames = [$entryStoredName]` instead of
  `PCLZIP_OPT_BY_NAME`. Loop walks the typed result array directly; the
  PHPStan `@phpstan-ignore-next-line offsetAccess.nonOffsetAccessible` line
  that was masking PclZip's untyped return is gone.
- **`composer.json`** — `pclzip/pclzip: ^2.8` removed from `require`;
  `ext-zip: *` added (already implicit on most builds, now explicit).
- **`composer.lock`** — regenerated; `vendor/pclzip/` directory removed.
- **`tools/psalm-stubs.phpstub`** — 21 `PCLZIP_OPT_*` constant stubs (the
  whole "PclZip library constants" block) deleted.

### Tests added

- **`tests/Unit/Core/ZipExtractorTest.php`** — 9 tests, 28 assertions.
  Covers: list-names ordering, list on missing archive, prefix-stripped
  extraction, plain extraction without prefix, `$onlyNames` filtering,
  path-traversal blocking (`../escape.txt` style entries), chmod
  application, empty-result on open failure, missing-parent-directory
  auto-creation.

### Semantic note: `PCLZIP_OPT_REPLACE_NEWER` dropped

PclZip's default was "never overwrite an existing file"; `REPLACE_NEWER`
relaxed that to "overwrite when the archived entry's mtime is newer". All
four call sites passed `REPLACE_NEWER`. `ZipArchive` always overwrites
unconditionally. In the install/upgrade context — where the archive *is*
the new version we want on disk — the difference is moot: there is no case
where the archived file is older than what's on disk. Documented here so a
future reader doesn't grep for it and assume the guard was lost.

### Verification

- `vendor/bin/phpstan analyse --no-progress` → 0 errors at level 10.
- `vendor/bin/phpunit` (Unit + Integration) → 495 tests, 2418 assertions, OK
  (was 486 / 2390 before; the 9 new tests + 28 assertions are
  `ZipExtractorTest`).
- `composer dump-autoload --classmap-authoritative` run after `ZipExtractor`
  was added.
- Post-edit cross-tree grep: `grep -rEn "PclZip|PCLZIP_|pclzip"
  src/ tools/ tests/ composer.json composer.lock` returns nothing outside
  docstrings in `ZipExtractor.php` (intentional historical reference).
- `vendor/pclzip/` directory removed by `composer update`.

## Z18. Phase 2d `xmlrpc_encode()` Removal

Closed 2026-05-15. The PHP `xmlrpc` extension was deprecated in 8.0 and
removed from core in 8.1+. Piwigo's `pwg.xmlrpc` response format had been
shipping broken on every PHP install without an explicit PECL build for two
major versions; REST/JSON cover all in-tree callers and all external API
consumers we've observed in extension code.

### XML-RPC was only an output format, never an input handler

Worth being explicit because Phase 2d looks one-sided otherwise: XML-RPC in
Piwigo was always *response*-side only. Looking at `PwgServer::boot()`:

- `$requestFormat` is hardcoded to `'rest'`; the only concrete handler
  subclass of `PwgRequestHandler` is `PwgRestRequestHandler`. Requests are
  always REST-parsed.
- Only `$responseFormat` (read from `$_GET['format']`) ever varied; the
  encoder switch picked one of `rest` / `php` / `json` / `xmlrpc`.
- There is no `pwg.xmlrpc` method, no XML-RPC RPC dispatcher, no separate
  request-format `case 'xmlrpc':` to remove. Phase 2d is purely an
  encoder-side removal.

A request that arrives with `?format=xmlrpc` after this phase falls through
the encoder switch with `$encoder = null` and gets the existing
"Unknown response format" 400 (handled in `PwgServer::run()`) — the same
response any other unknown format produces.

### What was deleted

- **`src/Piwigo/Ws/Protocol/PwgXmlRpcEncoder.php`** — entire file (60 lines).
  Only producer of `text/xml`-shaped XML-RPC responses; only consumer of
  `xmlrpc_encode()`.
- **`src/Piwigo/Ws/PwgServer.php`** — `use Piwigo\Ws\Protocol\PwgXmlRpcEncoder;`
  import dropped; the `case 'xmlrpc': $encoder = new PwgXmlRpcEncoder();`
  arm of the encoder-selection switch in `boot()` deleted. The switch now
  has three live cases (`rest`, `php`, `json`).
- **`tools/psalm-stubs.phpstub`** — `function xmlrpc_encode(mixed $value): string {}`
  stub (and its 3-line docblock explaining why it existed) deleted from
  the global namespace block.
- **`tools/ws.htm:145`** — the `<option value="xmlrpc">XML RPC</option>`
  entry removed from the developer API tester's response-format dropdown
  (the surrounding `select` still has JSON/REST/PHP-serial).

### Docs touched

- **`docs/STRUCTURE.md:153`** — `PwgXmlRpcEncoder` removed from the
  `Ws/Protocol` namespace listing.
- **`docs/ROADMAP-PHP.md:1377`** — the migration row for
  `include/ws_protocols/xmlrpc_encoder.php` was historically marked
  "✅ deleted — migrated to PwgXmlRpcEncoder". Updated to note the second
  hop: PwgXmlRpcEncoder was itself retired in Phase 2d.

### No callers blocked the removal

- `src/`: grep for `xmlrpc` returns nothing post-edit; the encoder was only
  reachable via `?format=xmlrpc` from the HTTP layer.
- `tests/`: no tests target `xmlrpc` (grep clean before edit).
- `composer.json`: no PECL `ext-xmlrpc` requirement was declared (the
  extension's removal from core meant the dependency would have been
  impossible to satisfy anyway).
- External plugins that issued `?format=xmlrpc` API calls have been broken
  since PHP 8.1; per the project rule, external compat is not a blocker.

### Verification

- `vendor/bin/phpstan analyse --no-progress` → 0 errors at level 10.
- `vendor/bin/phpunit` (Unit + Integration) → 495 tests, 2418 assertions, OK
  (no test count change — no test was targeting xmlrpc and no new test was
  added; the deletion is pure removal of dead code).
- `composer dump-autoload --classmap-authoritative` run after the file
  deletion (classmap count went 7469 → 7468).
- Post-edit cross-tree grep: `grep -rEn "xmlrpc|XmlRpc|PwgXmlRpc"
  src/ tests/ tools/ composer.json` returns nothing.

## Z19. Phase 2e MobileEsp → mobiledetect Swap

Closed 2026-05-15. `ahand/mobileesp` (`\uagent_info`, ~2010-era regex-based
UA-detection library) replaced with `mobiledetect/mobiledetectlib: ^4.8`.
Pure dep swap — the mobile-theme switching feature is staying, every
consumer is preserved.

### Why a dep at all (vs. rolling our own)

UA-string parsing is fiddly enough that a maintained library doing it for
us is cheaper than catching corners ourselves. iPadOS 13+ identifies as
macOS Safari unless you check for `(iPad)`-shaped artifacts or touch
support; Android tablet detection is a "not Mobile" heuristic that breaks
against custom skins; new Apple/Samsung devices keep adding string quirks.
`mobiledetect/mobiledetectlib` v4 is the actively maintained successor to
MobileEsp (same lineage, completely rewritten for v4 in 2023) and ships
pattern updates as new devices appear.

`Sec-CH-UA-Mobile` / `Sec-CH-UA-Platform` were surveyed as alternatives:
they're cleaner protocol-wise, but Safari doesn't send them, and Safari is
the dominant browser on iOS. Useful only as a *prefer-when-present* signal
layered on top of the library, not a replacement. Not added in this phase
to keep the diff narrow; trivial follow-up if a perf gain is wanted on
Chromium-family browsers.

`matomo/device-detector` was the third candidate — excellent but ~10× the
footprint of mobiledetect, built for analytics dashboards needing model
names. Overkill for three boolean classifications.

### API mapping

| MobileEsp call | mobiledetect equivalent |
|---|---|
| `new \uagent_info()` | `new \Detection\MobileDetect()` |
| `$obj->DetectSmartphone()` | `$detect->isMobile()` **AND** `!$detect->isTablet()` (mobiledetect's `isMobile()` returns true for tablets too) |
| `$obj->DetectTierTablet()` | `$detect->isTablet()` |
| `$obj->DetectIos()` | `$detect->is('iOS')` |

For `getDevice()`'s 3-way mobile/tablet/desktop classification, the
order-of-checks matters: check `isTablet()` first, then `isMobile()`,
otherwise tablets get classified as `'mobile'`.

### Files touched

- **`composer.json`** — `mobiledetect/mobiledetectlib: ^4.8` added to
  `require`; `ahand/mobileesp: dev-master` removed.
- **`composer.lock`** — regenerated; `vendor/ahand/` gone,
  `vendor/mobiledetect/mobiledetectlib/` (v4.10.0) installed.
- **`src/Piwigo/Core/Util.php`** — `getDevice()` body migrated:
  `new \uagent_info()` → `new MobileDetect()`; tablet/mobile/desktop
  classification reordered to check `isTablet()` before `isMobile()`;
  `use Detection\MobileDetect;` import added.
- **`src/Piwigo/Controller/Admin/PhotoController.php`** — banner UA guard
  migrated: `$uagent_obj->DetectIos()` → `$detect->is('iOS')`;
  `use Detection\MobileDetect;` import added.
- **`src/Piwigo/Controller/Admin/MiscController.php`** — same migration
  as PhotoController.

No psalm/phpstan stubs were needed (the library is properly typed).

### Behavioural deltas

None visible to users or admins. Both libraries are regex-based UA
classifiers; for the three classification queries Piwigo asks
(`isSmartphone` / `isTablet` / `isIos`), the result spaces are
functionally identical for current devices. mobiledetect is newer, so
it'll correctly classify devices MobileEsp doesn't know about (e.g.,
post-2010 Android tablets, iPad Air, etc.) — a strict improvement.

### What was kept

Every consumer of the UA classification stays. The mobile-theme switching
feature is a real Piwigo feature (some themes are mobile-specific via
`'mobile' => true` in themeconf), and remains untouched:

- `Util::mobileTheme()`, `Util::getDevice()`
- `Config::mobilTheme()` accessor + `mobile_theme` SCHEMA entry
- `Admin/Themes.php` mobile-flag activation guard, config writes, themeconf parse
- `Bootstrap/CommonBootstrap.php` theme switch
- `Page/PageTailRenderer.php` toggle link
- `Template/Latte/PiwigoExtension.php` `get_device` filter (zero in-template
  consumers across in-tree themes today — eligible for separate cleanup,
  not Phase 2e's job)
- `Util::getPwgThemes(bool $showMobile)` parameter

### Verification

- `vendor/bin/phpstan analyse --no-progress` → 0 errors at level 10.
- `vendor/bin/phpunit` (Unit + Integration) → 495 tests, 2418 assertions, OK
  (no test count change — no test was targeting MobileEsp and no new test
  was added; the swap is API-equivalent).
- `composer dump-autoload --classmap-authoritative` run after the dep swap
  (classmap 7468 → 7474, reflecting mobiledetect's namespace-loaded
  classes).
- Post-edit cross-tree grep: `grep -rEn "uagent_info|MobileEsp|mobileesp"
  src/ tests/ tools/ composer.json` returns nothing.
- `vendor/ahand/` directory removed by `composer remove`;
  `vendor/mobiledetect/mobiledetectlib/` installed.

## Z20. Phase 2f `CURRENT_DATE` Global Retired

Closed 2026-05-15. The `CURRENT_DATE` PHP constant was a global
shared-mutable state footgun and an inventory inaccuracy: stated as
"3 places, 2 formats", actually **4 places, 2 formats**, with a latent
column/format mismatch on one consumer that this phase fixed.

### What was actually true (audit before edits)

**4 sites defined `CURRENT_DATE`** (not 3):

| File:Line | Format | Notes |
|---|---|---|
| `Admin/Metadata/MetadataAdminService.php:213-214` | `Y-m-d` | Inside `syncMetadata()`; only reachable from one method |
| `Controller/UpgradeController.php:127` | `Y-m-d H:i:s` | Top of upgrade flow — **never read** within `src/` in v17; was leaking into the global namespace for legacy upgrade scripts in `install/db/*.php` that ship empty in v17 (see also §Z16). Pure dead define. |
| `Controller/InstallController.php:244` | `Y-m-d H:i:s` | Inside installer; reads on next line |
| `Controller/Admin/MaintenanceController.php:1112` | `Y-m-d H:i:s` | Inside one method (`siteUpdate()`); two consumers downstream |

**4 sites consumed `CURRENT_DATE`** + a column-format table:

| File:Line | Column | DB type | Format wanted | What was happening |
|---|---|---|---|---|
| `MetadataAdminService.php:238` | `images.date_metadata_update` | `date` | `Y-m-d` | Worked: own define is `Y-m-d` |
| `InstallController.php:247` | `upgrade.applied` | `datetime` | `Y-m-d H:i:s` | Worked: own define is `Y-m-d H:i:s` |
| `MaintenanceController.php:1374` | `images.date_available` | `datetime` | `Y-m-d H:i:s` | Worked: own define is `Y-m-d H:i:s` |
| `MaintenanceController.php:1535` | `images.date_metadata_update` | `date` | `Y-m-d` | **Bug**: was using `Y-m-d H:i:s` from the controller's own define. MySQL silently truncates the time portion when writing to a `date` column, so the data on disk was still correct, but the PHP-side value differed from MetadataAdminService's writes to the same column. Flat-out inconsistency. |

**Latent shared-state footgun**: the `defined() or define()` guard at
`MaintenanceController:1112` and the `if (!defined())` guard at
`MetadataAdminService:213` meant whichever path ran first won. If
`syncMetadata()` ran first in a request (`Y-m-d` format), then
`siteUpdate()` ran later, `siteUpdate()`'s `date_available` write at line
1374 would have used `Y-m-d` — landing `Y-m-d 00:00:00` in MySQL instead
of the current request time. Reverse direction: `MetadataAdminService`'s
`date_metadata_update` write at line 238 would have used `Y-m-d H:i:s` —
truncated by MySQL but inconsistent on the PHP side.

### The fix

Per-call-site inline `DateTimeImmutable`, each picks its own format. No
shared state, no constant, no possibility of cross-request bleed:

- **`MetadataAdminService::syncMetadata()`** — `$today = new \DateTimeImmutable()->format('Y-m-d');` once near method top; consumer at line 238 reads `$today`.
- **`InstallController` (install path)** — `$now = new \DateTimeImmutable()->format('Y-m-d H:i:s');` immediately above the foreach loop; consumer reads `$now`.
- **`MaintenanceController::siteUpdate()`** — `$now = new \DateTimeImmutable();` once near method top, then derives `$nowDateTime = $now->format('Y-m-d H:i:s')` and `$today = $now->format('Y-m-d')` from the **same instant**; consumers at 1374 use `$nowDateTime`, at 1535 use `$today`. This fixes the latent format bug at 1535 and guarantees both consumers see the same request-instant clock.
- **`UpgradeController`** — `define('CURRENT_DATE', …)` line deleted with no replacement. It was a leak with no in-tree v17 consumer.

`Db/SqlExpr.php:70-74` is unaffected: it uses `'CURRENT_DATE'` as a SQL
keyword string literal, in the `recentPeriodExpr()` argument namespace.
The existing docstring `$date may be a column name or 'CURRENT_DATE'`
already clarifies the SQL context.

`tools/psalm-stubs.phpstub:27` — `const CURRENT_DATE = '';` stub
deleted (no remaining PHP-constant consumers).

### Files touched

- `src/Piwigo/Admin/Metadata/MetadataAdminService.php` — define block replaced with `$today` local; line 238 reads `$today`.
- `src/Piwigo/Controller/InstallController.php` — define replaced with `$now` local; line 247 reads `$now`.
- `src/Piwigo/Controller/UpgradeController.php` — define line deleted outright.
- `src/Piwigo/Controller/Admin/MaintenanceController.php` — define replaced with one `DateTimeImmutable` instance + two `format()`-derived strings; lines 1374 / 1535 read the correct format for their respective columns.
- `tools/psalm-stubs.phpstub` — `const CURRENT_DATE = '';` stub deleted.

### Behavioural deltas

- **Cross-method format bleed eliminated.** Each call site's value is
  derived from `DateTimeImmutable` at its own moment, with its own
  format string. Order-of-method-call no longer affects timestamp shape.
- **`MaintenanceController::siteUpdate()` `date_metadata_update` writes**
  now use `Y-m-d` (matching the `date` column type) instead of
  `Y-m-d H:i:s`. MySQL was already truncating the latter, so the on-disk
  values are unchanged; the PHP-side value matches reality now.
- **`MaintenanceController::siteUpdate()` `date_available` writes**
  unchanged in format (`Y-m-d H:i:s`) and timestamp accuracy.
- **Multi-method-call requests no longer drift between formats.** Even if
  some long-running request hits both `syncMetadata` and `siteUpdate`,
  each grabs its own clock instant.

### Why no `Piwigo\Core\RequestClock` service

The inventory's "recommended fix" proposed introducing a
`Piwigo\Core\RequestClock` DI service holding a single
`DateTimeImmutable` per request. Skipped in favour of inline reads at the
4 sites — each call site needs `now()` exactly once for its own purpose
with its own format, and there's no shared "the request's start time"
concept anywhere else in `src/` that would benefit from a service
abstraction. Following the project rule
(`Don't add abstractions beyond what the task requires`), inline beats a
new service here. If a future phase finds a real need (e.g., testability
hooks for clock-mocked tests, or a request-scoped "started_at"
field consumed across many controllers), a service can be introduced then.

### Verification

- `vendor/bin/phpstan analyse --no-progress` → 0 errors at level 10.
- `vendor/bin/phpunit` (Unit + Integration) → 495 tests, 2418 assertions, OK
  (no test count change — no test was targeting `CURRENT_DATE`).
- `composer dump-autoload --classmap-authoritative` refreshed (classmap
  stable at 7474; no new files).
- Post-edit cross-tree grep: `grep -rEn "CURRENT_DATE" src/ tests/ tools/`
  returns only intentional residuals — `ProfileService.php:220`
  (`'API_CURRENT_DATE'` template-var name, unrelated), `Db/SqlExpr.php:70,72,74`
  (SQL keyword string literal, unrelated).

## Z21. Phase 2g UniversalFeedCreator → In-Tree DOM RSS Generator

Closed 2026-05-15. `openpsa/universalfeedcreator` (2004-era untyped lib,
~50 files supporting RSS/Atom/RDF/OPML/JSON/GPX) retired in favour of an
in-tree DOMDocument-based RSS 2.0 generator.

### Audit findings

- Single consumer: `Controller/FeedController.php` (RSS 2.0 only — the
  only `saveFeed(…)` call in `src/` passes `'RSS2.0'`).
- `Piwigo\Feed\PiwigoFeedCreator` was a 12-line subclass of
  `\UniversalFeedCreator` that only overrode `$encoding = 'UTF-8'`.
- The controller's call to `saveFeed('RSS2.0', $fileName, true)` wrote to
  `_data/tmp/feed.xml` AND returned the string. The file was overwritten
  on every request and never read back as a cache — pure side effect.
- Item-level fields actually used: `title`, `link`, `description`,
  `descriptionHtmlSyndicated`, `date` (ISO 8601 string), `author`, `guid`.
- Channel-level fields actually used: `title`, `link`, `encoding`. The lib
  emitted `<lastBuildDate>` and `<generator>` automatically; channel
  `<description>` was unset (lib emitted an empty element).
- No tests targeted the feed output.

### Why DOMDocument over alternatives

- **DOMDocument (chosen):** zero deps, ships with PHP, native `<![CDATA[…]]>`
  support via `createCDATASection()`, automatic XML escaping for everything
  else. ~80 LOC generator + ~30 LOC FeedItem.
- **SimpleXMLElement:** lighter API, but no native CDATA — workaround
  requires dropping to DOM for the CDATA nodes anyway, so you end up with
  a mixed model.
- **laminas/laminas-feed:** modern and well-maintained, but pulls in
  laminas-stdlib + laminas-escaper as transitive deps. Overkill when the
  in-tree shape is ~110 LOC and tightly scoped.
- **Roll our own string concat:** what UniversalFeedCreator did. Fragile
  against escaping bugs; DOMDocument is strictly safer for nearly the same
  LOC.

### What was built

- **`src/Piwigo/Feed/PiwigoFeedCreator.php`** — DOMDocument-based RSS 2.0
  generator. Property-bag API matching the legacy call-site shape:
  `$encoding`, `$title`, `$link`, `addItem(FeedItem)`. New `toRss20Xml():
  string` method returns the XML directly (replaces the legacy
  `saveFeed(…)` file-write + return-string side-effect; file caching
  dropped — it wasn't being used as a cache). Channel `<description>`
  falls back to `<title>` to stay valid per RSS 2.0 spec (the legacy lib
  emitted an empty element, which was technically spec-non-compliant).
  Item title gets `strip_tags()` + 100-char truncation with `...` suffix;
  description gets CDATA wrap when `descriptionHtmlSyndicated`, plain
  text-escape otherwise; dates emitted as RFC 822 (`DATE_RSS`).
- **`src/Piwigo/Feed/FeedItem.php`** — new namespaced class replacing the
  global `\FeedItem` from the retired lib. Property-bag with typed
  fields; `$date` is `?\DateTimeImmutable` (was an ISO 8601 string passed
  through `FeedHelper::tsToIso8601(…)` then converted to RFC 822 inside
  the lib — now it's a single typed value across the boundary).

### Files touched

- **`composer.json`** — `openpsa/universalfeedcreator: ^1.9` removed.
- **`composer.lock`** — regenerated; `vendor/openpsa/` directory removed.
- **`src/Piwigo/Feed/PiwigoFeedCreator.php`** — full rewrite (was a 12-line
  shell extending `\UniversalFeedCreator`; now a ~95-line standalone
  generator).
- **`src/Piwigo/Feed/FeedItem.php`** — new file (~25 lines) replacing the
  global `\FeedItem`.
- **`src/Piwigo/Controller/FeedController.php`** — `use Piwigo\Feed\FeedItem;`
  import added; two `new \FeedItem()` → `new FeedItem()`; two
  `FeedHelper::tsToIso8601(FeedHelper::datetimeToTs($x))` →
  `new \DateTimeImmutable($x)`; the title-string assignment refactored to
  one statement (was two — `$rss->title = …; $rss->title .= ' (as …)';`);
  `$rss->saveFeed('RSS2.0', $fileName, true)` + `Util::mkgetdir(…)` +
  `_data/tmp/feed.xml` file-write block all replaced with a single
  `echo $rss->toRss20Xml();`.

### Tests added

`tests/Unit/Feed/PiwigoFeedCreatorTest.php` — 8 tests, 24 assertions:

1. Root element is well-formed RSS 2.0 with `<title>`, `<link>`,
   `<description>` (fallback to title).
2. Item descriptions wrapped in CDATA when `descriptionHtmlSyndicated = true`.
3. Item descriptions XML-escaped (no CDATA) when flag is false.
4. Item dates emitted in RFC 822 format.
5. Item titles strip HTML and truncate at 100 chars with `...` suffix.
6. Items without a date omit the `<pubDate>` element entirely.
7. Multiple items emitted in insertion order.
8. Generated XML is parseable by `simplexml_load_string()` with mixed
   ampersand/`<unsafe>` content escaped correctly across both channel and
   item levels.

### Behavioural deltas

- **Channel `<description>` is no longer empty.** Falls back to title to
  stay RSS-2.0 compliant. Legacy output had an empty `<description></description>`
  element, which is technically spec-non-compliant — readers tolerated it
  but the new output is stricter-valid.
- **`<generator>` value changed** from `FeedCreator 1.7.4` (the lib's
  version string) to `Piwigo`. No reader behaviour depends on this.
- **File write at `_data/tmp/feed.xml` removed.** Was overwritten on every
  request, never read back; pure side effect.
- **Item title truncation behaviour matched** (100 chars, with `...`
  suffix when truncated). HTML-strip matched.
- **CDATA semantics matched** (wrap when `descriptionHtmlSyndicated`,
  XML-escape otherwise).
- **Item dates: same RFC 822 wire format**, internal representation
  changed from ISO 8601 string → `DateTimeImmutable`.

### Verification

- `vendor/bin/phpstan analyse --no-progress` → 0 errors at level 10.
- `vendor/bin/phpunit` (Unit + Integration) → 503 tests, 2442 assertions,
  OK (495 → 503 = +8 from `PiwigoFeedCreatorTest`).
- `composer dump-autoload --classmap-authoritative` refreshed (classmap
  7474 → 7455 — net negative because the retired lib was a vendor package
  with many classes, replaced by 2 in-tree classes).
- Post-edit cross-tree grep: `grep -rEn "UniversalFeedCreator|\\FeedItem|universalfeedcreator"
  src/ tests/ tools/ composer.json` returns only intentional residuals
  (PiwigoFeedCreator import in FeedController, docstrings in the new
  classes referencing the retired lib by name).
- `vendor/openpsa/` directory removed by `composer remove`.

## Z22. Phase 4c `RequestContext` enum + registry

Closed 2026-05-15. The three legacy "we're inside this kind of request" flags —
`defined('IN_ADMIN')`, `defined('IN_WS')`, `defined('PHPWG_IN_UPGRADE')` —
replaced with a typed enum distributed via a thin static registry, matching
the established Phase 4a/4b/4d pattern. PSR-7 request attributes were
considered (the inventory's pre-audit framing) and deferred as a future
re-homing — every read site would have needed `ServerRequestInterface`
threaded through its constructor; the registry shape is already idiomatic
in this codebase (`FilterContextRegistry`, `SectionContextRegistry`,
`TemplateRegistry`, `CurrentUser`).

### What landed

- **`Piwigo\Http\RequestContext`** — `enum` with cases `Admin`, `Ws`,
  `Upgrade`, `Gallery`, `Derivative`.
- **`Piwigo\Http\RequestContextRegistry`** — `set()` / `current()` /
  `reset()`. `current()` falls back to `RequestContext::Gallery` when no
  controller has set the context yet, matching the legacy "no flag
  defined" semantics. (Gallery-side controllers therefore leave the
  default; only the four non-default contexts get an explicit `set()`.)

### Contexts set at controller entry

| Controller | Context | Old code |
|---|---|---|
| `Controller/Admin/AdminController.php:76` | `RequestContext::Admin` | `defined('IN_ADMIN') or define('IN_ADMIN', true);` |
| `Controller/WsController.php:42` | `RequestContext::Ws` | `if (!defined('IN_WS')) { define('IN_WS', true); }` |
| `Controller/UpgradeController.php:48` | `RequestContext::Upgrade` | (no equivalent — Upgrade context is new) |
| `Controller/ImageDerivativeController.php:43` | `RequestContext::Derivative` | (no equivalent — Derivative context is new) |

The Upgrade/Derivative contexts have no reader yet (existing flow was
purely structural: bypass `CommonBootstrap` via `index.php` URL prefix
dispatch). They're set so the enum covers every entry point and so future
code that needs the distinction has a typed answer.

### 13 read sites migrated

- **`IN_ADMIN` (10 → `RequestContextRegistry::current() === RequestContext::Admin`):**
  `Page/NoPhotoYetRenderer.php:41` (also dropped the `@psalm-suppress
  RedundantCondition` block at `:39` — Psalm narrows the enum compare
  without help), `Page/PageHeaderRenderer.php:30`, `Core/Util.php:138`,
  `Users/ProfileService.php` (5 sites: 55, 59, 91, 149, 216 — consolidated
  to a single `$inAdmin` local inside `saveProfileFromPost` since the four
  reads happen in one method),
  `Users/UserBootstrap.php:145`, `Bootstrap/CommonBootstrap.php:190, 234`.
- **`IN_WS` (3 → `=== RequestContext::Ws`):**
  `Admin/Upload/UploadService.php:167`, `Users/UserBootstrap.php:90, 120`
  (consolidated to one `$inWs` local since both reads are in the same
  function).

### 5 define() lines deleted

- `Controller/Admin/AdminController.php:76` (`IN_ADMIN`) — replaced by `RequestContextRegistry::set(RequestContext::Admin)`.
- `Ws/Method/ExtensionsEndpoints.php:63, 87, 165` (3× `IN_ADMIN`) — belt-and-suspenders re-defines inside WS extension methods. Now that `WsController::__invoke` sets `RequestContext::Ws` at request entry, the `IN_ADMIN` re-defines are pure dead state — the `IN_ADMIN`-only readers (`PageHeaderRenderer`, `NoPhotoYetRenderer`, `ProfileService`, `Util::redirectHtml`, `CommonBootstrap`) don't run inside WS dispatch (which echoes JSON/XML, never renders HTML pages or re-runs bootstrap).
- `Controller/WsController.php:42` (`IN_WS`) — replaced by `RequestContextRegistry::set(RequestContext::Ws)`. The `if (!defined('IN_WS'))` guard at `:41` went too — the registry is set-once-overwrites and doesn't need the guard.

### `PHPWG_IN_UPGRADE` collapse

Self-contained inside `Admin/UpgradeService.php`: 2 defines at `:109, :144`
and 1 read at `:23-24`. Collapsed to:

- **`private static bool $upgradeAuthorized = false;`** — class field, same
  single-write/many-read shape as the legacy define.
- **`self::$upgradeAuthorized = true;`** at the two grant sites
  (webmaster cookie path + username/password POST path).
- **`return self::$upgradeAuthorized;`** in `checkUpgrade()` — the
  accessor's public signature is unchanged; callers (`UpgradeService` itself)
  read it the same way.

The inventory entry previously named `:145, :180` and `Controller/UpgradeController.php`
as define sites; the audit found `:109, :144` in `UpgradeService` and zero
defines in `UpgradeController`. Corrected before code work began
(commit `49eab8dfe`).

### Stubs purged

- **`tools/phpstan-bootstrap.php:8`** — `define('IN_ADMIN', false);` deleted. PHPStan no longer needs the stub because the legacy reads (`defined('IN_ADMIN')`) are gone and the new code uses enum comparison.
- **`tools/psalm-stubs.phpstub:24-26`** — `IN_ADMIN`, `IN_WS`, `PHPWG_IN_UPGRADE` constant stubs deleted.

### What about `$tpl->assign('IN_ADMIN', …)`?

`ProfileService.php:220` still assigns a template variable named `IN_ADMIN`
because the Latte templates downstream read `{$IN_ADMIN}` by that name.
The variable's *value* is now derived from the enum
(`RequestContextRegistry::current() === RequestContext::Admin`); the
*name* is a template-side identifier that a future Latte cleanup phase can
rename to `IS_ADMIN_REQUEST` if desired. Out of scope for Phase 4c — no
PHP-side `defined()` call remains.

### Verification

- `vendor/bin/phpstan analyse --no-progress` → 0 errors at level 10.
- `vendor/bin/psalm --no-progress` → 0 errors.
- `vendor/bin/phpunit` (Unit + Integration) → 503 tests, 2442 assertions, OK.
- `composer dump-autoload --classmap-authoritative` re-run after the new
  `Piwigo\Http\RequestContext` / `RequestContextRegistry` files were
  added — required because the project pins `classmap-authoritative: true`,
  so Apache PHP-FPM cannot autoload classes not in the classmap. A first
  pass without this step produced 14 WS integration failures (Apache
  returning 500 because the autoloader could not resolve the new classes);
  the unit suite missed it because it doesn't exercise Apache.
- Cross-tree grep: `grep -RnE '\bIN_ADMIN\b|\bIN_WS\b|\bPHPWG_IN_UPGRADE\b' src/ tests/ tools/` returns only intentional residuals: the `'IN_ADMIN'` template-variable string in `ProfileService:220` (see above), and docstring references to the retired flag names in the new `RequestContext.php` / `UpgradeService.php` migration notes.
- Whole-tree `defined()` / `define()` sweep across `*.php` / `*.phpstub`
  for the three flag names returns zero matches outside docstrings.

### Doc-drift fixed alongside

- `docs/STRUCTURE.md` `tools/phpstan-bootstrap.php` row no longer mentions
  `IN_ADMIN=false` (that define was removed).
- `ROADMAP.md` Forbidden-Patterns table line for `IN_ADMIN` updated to
  cover all three retired flags and point at `RequestContextRegistry`.
- `docs/DEFAULT-PLUGINS.md:69` (`If IN_ADMIN, includes admin/admin_boot.php`)
  is **not** touched — that line describes the PiwigoOpenstreetmap
  plugin's own source code, which is external and out of scope (per the
  "plugins will be rewritten; external compat is not a blocker" policy).

---

## Z23. Phase 5 `Util.php` split

Closed 2026-05-15. The 1058-line `src/Piwigo/Core/Util.php` service-locator
god-class — 27 methods across many concerns, 11 carrying the legacy `pwg*`
prefix from `include/functions.inc.php` heritage — was split into eight new
typed services and three existing-service extensions, then deleted entirely.
No compat shim. Method renames deliberately drop the `pwg*` prefix.

The split happened in three sequential batches over a single day, each with
PHPStan 0 / Psalm 0 / PHPUnit 503/503 / Pint clean before commit.

### What landed

#### New services

| Class | Methods carved from `Util` | Notes |
|---|---|---|
| `Piwigo\Activity\ActivityLogger` | `log(ActivityEvent)`, `pageView()`, `isLoggingEnabled()` | replaces `pwgActivity` / `pwgLog` / `doLog`. Method renames intentional — `pageView` is what `pwgLog` actually did. |
| `Piwigo\Activity\ActivityEvent` (DTO) | — | typed payload replacing `pwgActivity`'s `(string $object, array\|int\|string $objectId, string $action, array $details)` union signature |
| `Piwigo\Activity\ActivityObject` (enum) | — | `Album` / `Group` / `Photo` / `System` / `Tag` / `User`. String values match the persisted `activity.object` MySQL ENUM column — renaming a case is a schema migration |
| `Piwigo\Auth\EphemeralKeyService` | `generate()`, `verify()` | replaces `getEphemeralKey` / `verifyEphemeralKey` |
| `Piwigo\Core\DebugCollector` | `collect()` | replaces `pwgDebug`; thin facade over `PageState->debugLines` (set in [§Z10](#z10-phase-3b-mechanical-channels)) |
| `Piwigo\Core\ExecutionMutex` | `acquire()`, `isHeld()`, `release()` | replaces `pwgUniqueExecBegins` / `IsRunning` / `Ends` |
| `Piwigo\Csrf\CsrfService` | `getToken()`, `check()` | replaces `getPwgToken` / `checkPwgToken` |
| `Piwigo\Http\DeviceDetectionService` | `getDevice()`, `isMobileTheme()` | replaces `getDevice` / `mobileTheme`. Mobile-theme switcher stays as a feature (see Phase 2e "Out of scope" — these survived Phase 2e specifically because Phase 5 had a home for them) |
| `Piwigo\Http\RedirectResponder` | `redirect()`, `redirectHttp()`, `redirectHtml()` | same signatures; PSR-7 deferred (see "Deferred" below) |
| `Piwigo\Language\LanguageService` | `getActiveLanguages()` | replaces `getLanguages` |
| `Piwigo\Page\PaginationService` | `createNavigationBar()` | — |
| `Piwigo\Telemetry\TelemetryService` | `sendInfos()`, `retryLater()` | replaces `sendPiwigoInfos` / `sendPiwigoInfosRetryLater` |
| `Piwigo\Theme\ThemeService` | `getActiveThemes()`, `isInstalled()`, `getThemeconf()` | replaces `getPwgThemes` / `checkThemeInstalled` / `getThemeconf` |
| `Piwigo\Validation\InputValidator` | `check()` | replaces `checkInputParameter` |

#### Method moves to existing services

| Target | Method | Old name on `Util` |
|---|---|---|
| `Piwigo\Comment\CommentService` | `getNbAvailable()` | `getNbAvailableComments` |
| `Piwigo\Admin\Category\CategoryAdminService` | `checkLounge()` | `checkLounge` |
| `Piwigo\Image\ImageRepository` | `addToUserCaddie(int $userId, int[] $elementIds)` | `fillCaddie` — explicit userId parameter replaces the implicit `CurrentUser::get()->id` read |
| `Piwigo\Html\HtmlService` | `getPrivacyLevelOptions()`, `getIcon()` | same names |
| `Piwigo\Filter\FilterService` | `getFilterPageValue()` | same name |
| `Piwigo\Mail\MailService` | `getWebmasterMailAddress()` | same name; MailService dropped its `Util` dep entirely |
| `Piwigo\Core\Filesystem` (existing) | `mkgetdir()` static + `FLAG_*` class constants | `Util::mkgetdir()` static + global `MKGETDIR_*` ints. Joined the existing `try*` filesystem helpers rather than creating a separate `Piwigo\Filesystem\Filesystem` |

#### Caddie

`fillCaddie` was the only orphan caddie operation still on `Util` — the other
DB operations (`deleteUserCaddie`, `deleteUserCaddieByImageIds`,
`deleteCaddieByImageIds`, `countCaddieByUserId`) already lived on
`ImageRepository`. Caddie itself is a **live admin feature** (per-admin
selection basket surfaced in the top admin menu with photo-count badge,
gallery/picture "Add to caddie" buttons, Batch Manager `caddie` prefilter
plus `add_to_caddie` / `remove_from_caddie` / `empty_caddie` actions, plus
auto-population on photo upload). Earlier inventory framing of caddie as a
"v1.x precursor to batch_manager" / removal target was wrong — caddie and
batch_manager are complementary features (caddie = selection basket,
batch_manager = the tool that operates on selections). The `CADDIE_TABLE`
define was already retired with Phase 2b; `fillCaddie` was the last
Util-side trace.

### Batch sequencing

Six commits in `16.x-rewrite`:

- **Batch 1** (`ae8aadb8d`) — six mechanical low-coupling carve-outs:
  `DebugCollector`, `ExecutionMutex`, `TelemetryService`,
  `CommentService::getNbAvailable`, `CategoryAdminService::checkLounge`,
  `ImageRepository::addToUserCaddie`. ~25 callers updated.
- Post-Batch-1 follow-ups (`6ec4b79ef` + `3b355628b`) — orphan-import sweep
  and Pint style-fix. (See [§Z23 caveats](#z23-phase-5-utilphp-split) below
  on why these landed separately.)
- **Batch 2** (`8e313c650`) — nine more carve-outs / method moves:
  `ThemeService`, `LanguageService`, `EphemeralKeyService`,
  `DeviceDetectionService`, `PaginationService`, `Filesystem::mkgetdir`,
  plus `HtmlService::getPrivacyLevelOptions/getIcon`,
  `FilterService::getFilterPageValue`, `MailService::getWebmasterMailAddress`.
  ~85 callers updated. Util.php shrank 1058 → 343 lines.
- Inventory tweak (`8568cae3f`) — recorded RedirectResponder PSR-7 deferral
  in Phase 5's open description (see "Deferred" below).
- **Batch 3** (`4727b3837`) — four big carve-outs +
  `ActivityEvent` signature change: `CsrfService` (140 sites),
  `InputValidator` (85 sites), `RedirectResponder` (57 sites),
  `ActivityLogger` (71 sites). ~360 caller sites total. `Util.php` deleted
  from disk; `Util::class` factory + import removed from `config/container.php`.

### Naming policy — what we deliberately didn't do

> Don't name the carved-out classes `PwgLogger`, `PwgCsrf`, etc. Those names
> preserve the legacy `pwg*` prefix the inventory just spent paragraphs
> explaining is the symptom of `include/functions.inc.php` heritage. The
> point of the split is to leave that behind.

All renames are deliberate: `pwgActivity` → `ActivityLogger::log`,
`pwgLog` → `ActivityLogger::pageView`, `doLog` → `ActivityLogger::isLoggingEnabled`,
`getPwgToken` → `CsrfService::getToken`, `checkPwgToken` → `CsrfService::check`,
`getEphemeralKey` → `EphemeralKeyService::generate`, `verifyEphemeralKey` →
`::verify`, `getPwgThemes` → `ThemeService::getActiveThemes`,
`checkThemeInstalled` → `ThemeService::isInstalled`,
`getLanguages` → `LanguageService::getActiveLanguages`,
`mobileTheme` → `DeviceDetectionService::isMobileTheme`,
`checkInputParameter` → `InputValidator::check`.

`MKGETDIR_*` global ints became `Filesystem::FLAG_*` class constants — same
bitmask values, same combine-with-`|` ergonomics, scoped to the class
instead of the global namespace.

### Deferred

- **PSR-7 conversion for `RedirectResponder`.** The inventory originally
  specified that `RedirectResponder` should return PSR-7 `ResponseInterface`
  and collapse the three methods to one. Converting deep services
  (`AuthService`, `UserService`, `PasswordService`, …) to bubble Response
  up through the call chain instead of `exit()`-ing is invasive enough that
  Phase 4c followed the same "defer PSR-7, do the carve-out now" path —
  Batch 3 did the same. The single-method collapse is also deferred.
  Callers use `redirect()` / `redirectHttp()` / `redirectHtml()` exactly as
  they used them on `Util`. Recorded in inventory tweak `8568cae3f` ahead
  of Batch 3.
- **`ActivityEvent::$objectId` widening to `int|int[]|string`.** Kept at
  `int|int[]` rather than reintroducing the legacy `array|int|string`
  union. Three call sites where the source was loosely typed needed an
  inline `is_numeric(...) ? (int) ... : 0` cast at the call site
  (`PasswordService` reset-failure `$state['user_id']` access; two
  `GroupsEndpoints` user-id reads from WS params; `TagsEndpoints` tag-id
  read). This surfaces a deeper WS-param-typing smell — WS endpoint IDs
  travel as strings and some callers cast at the boundary, some don't — but
  that's out of scope for Phase 5.
- **§A5.2 / "caddie retirement" phase.** Earlier inventory drafts described
  caddie as a v1.x precursor to batch_manager and listed it as a removal
  target. That was wrong (see the Caddie section above). No retirement
  phase was scheduled or executed; the only caddie work in Phase 5 was
  moving `fillCaddie` to `ImageRepository`.

### §A1 — `$page` reference bridge

Originally scheduled for Phase 5 (service-owned state was invasive enough to
land alongside the Util split). Shipped early on 2026-05-15. Closure record:
[Appendix A §Z1.1](#z1-wave-a-reference-bridges) and the supplementary
detail block in §A1 closure. Not part of the Phase 5 batches themselves.

### Container.php impact

The `Util::class` factory and `use Piwigo\Core\Util` import were removed.
Roughly twenty-five consumer factories had their `Util $util` argument
replaced with the appropriate combination of `ActivityLogger`, `CsrfService`,
`InputValidator`, `RedirectResponder`, plus the Batch 1/2 services. Four new
service wirings landed in Batch 3 (`ActivityLogger`, `CsrfService`,
`InputValidator`, `RedirectResponder`); five more in Batch 2
(`ThemeService`, `LanguageService`, `EphemeralKeyService`,
`DeviceDetectionService`, `PaginationService`); three in Batch 1
(`DebugCollector`, `ExecutionMutex`, `TelemetryService`).

The three lazy-proxy factories (`CategoryAdminService`, `ImageAdminService`,
`UserAdminService`) now resolve `ActivityLogger::class` instead of
`Util::class`.

### Verification

Each batch ran the full gate set before commit (PHPStan 0 / Psalm 0 /
PHPUnit 503/503, 2442 assertions / Pint clean) and an audit-pass-style
re-check after commit:

- Batch 1 needed two follow-up commits (orphan imports + Pint). The
  orphan-import miss is what produced the `feedback_run_pint_before_commit`
  memory: PHPStan and Psalm don't flag unused `use` statements under this
  project's config — that's specifically Pint's job, and Pint hadn't been
  run locally before the original Batch 1 commit. Batch 2 and Batch 3
  followed the corrected workflow (`composer lint:php` before commit) and
  needed no follow-up.
- `composer dump-autoload --classmap-authoritative` re-ran after every
  batch because the project pins `classmap-authoritative: true`. Phase 4c
  was where this trap was discovered (14 WS integration failures from
  Apache being unable to autoload new classes the unit suite didn't
  exercise); the `feedback_composer_dump_after_new_class` memory was
  written then and Phase 5 followed it.
- Final post-Batch-3 audit verified: zero `Piwigo\Core\Util` references
  anywhere, zero `MKGETDIR_*` global-constant references, zero orphan
  imports across the 62 changed files, three lazy proxies correctly
  re-wired to `ActivityLogger`, all 67 `new ActivityEvent(...)` call sites
  type-check clean under PHPStan level 10 + Psalm.

### Doc drift fixed alongside

- Phase 2h MKGETDIR_* row marked retired (was: "deferred to Phase 5
  territory").
- Phase 2h bottom-line list trimmed (`MKGETDIR_*` removed from the
  bundle-of-opportunity bucket).
- Phase 4a "Enabled by" forward-reference rewritten to past tense pointing
  at `FilterService::getFilterPageValue`.
- Phase 2b CADDIE_TABLE note pointing at "Phase 5's `fillCaddie` carve-out"
  rewritten to `ImageRepository::addToUserCaddie()` with §Z23 anchor.
- Dependency graph: Phase 5 row added to the done block.
- Phase Ladder: Phase 5 status changed from Open to ✓ Closed.
- Phase Ladder "Already shipped" list: §Z23 added.

---

## Caveats

- The plugin/theme procedural contract (Phase 6.1) and the 153-name event
  API (Phase 6.2) were orthogonal to Phase 5 (now closed): splitting
  `Util.php` didn't reach those, and Phase 6 doesn't reach the carved-out
  Phase 5 services either.
- The Smarty compat layer (Phase 6.3) is the largest sub-task in Phase 6 in
  terms of touched files (133 templates). Estimate this independently of the
  rest of Phase 6.
- `tools/triggers_list.php` shows up in two phases: the `'type'`-string
  rename and `include/` path fixes are Phase 1.5; the underlying event-name
  rewrite is Phase 6.2.
