# Plan: Replay `16.x-rewrite` as a clean, tested branch

## Context

`origin/16.x` is legacy Piwigo: no Composer, no tests, no TypeScript, no PSR-4,
vendored Smarty/PHPMailer in `include/`, 947 PHP files, 333 JS files, 140 `.tpl`
templates. Zero CI, zero static analysis, zero test coverage.

`16.x-rewrite` is 2023 commits ahead: 894 PHP source files under `src/Piwigo/`
(53 namespaces), 133 Latte templates, 82 authored TS files, PHPStan L10 clean,
1281 tests (899 methods × data providers). But the commit history has an average of 27 era-switches per 100 commits —
CSS interleaved with Latte interleaved with WS refactoring interleaved with PHPStan
fixes. Core files like `config/container.php` (194 touches) and
`include/functions.inc.php` (97 touches) were modified continuously across the
entire timeline.

**Goal:** New branch `16.x-v2` from `origin/16.x`. Replay in clean phases.
Tests accompany each phase. Every commit green.

---

## Pre-implementation audit (2026-05-24)

Deep analysis of every claim in this plan against the actual codebase state.
All numbers verified by grep/find on both `origin/16.x` and `16.x-rewrite` (HEAD).

### Factual corrections needed

| # | Plan claim | Actual value | Severity | Fix |
|---|-----------|-------------|----------|-----|
| 1 | "1122 unit tests" | 1281 tests (899 methods × data providers) | Moderate | Update to 1281 |
| 2 | "62 classes → src/Piwigo/" (P3) | 75 class declarations in origin/16.x; ~51 first-party after removing vendored. The "62" was actually the admin page count. | **Major** | Rewrite P3 scope: it's not "move 62 classes" — it's extracting classes from procedural files, splitting multi-class files, AND creating new classes for procedural logic |
| 3 | "195 reads in 72 files" for PHPWG_ROOT_PATH (P7) | **885 usages across 282 files** in origin/16.x | **Major** | P7 effort is 4.5× larger. Update from "1-2 days" to "4-5 days" minimum |
| 4 | "~160 events" (P12) | 157 event classes | Trivial | Update to 157 |
| 5 | "277 SCHEMA entries" (P5a) | 271 Config SCHEMA entries | Minor | Update to 271 |
| 6 | "80 admin + 35 frontend + 18 standard" templates (P10) | **70 admin + 56 frontend + 7 standard** = 133 | **Major** | Rewrite P10 wave sizes. Frontend is 1.6× bigger than planned, standard pages is 2.5× smaller |
| 7 | "69 Vite entry points" | 68 entries in vite.config.ts | Trivial | Update |
| 8 | "admin theme.css 9635 lines" (P9) | 8603 lines (admin/themes/default/theme.css in origin/16.x) | Minor | Update |
| 9 | "!important 689 → 51" (P9) | origin/16.x has **720**; current rewrite has **4** (not 51) | Moderate | Update baseline to 720, target to 4 |
| 10 | "118 factory closures" in container (P16) | 127 closures in config/container.php | Minor | Update to 127 |
| 11 | "15 namespaces with zero coverage" | **24 namespaces** with no tests (out of 53) | Moderate | Update — testing gap is 60% larger |
| 12 | "~25 VOs" + "~10 Enums" (P14) | 21 VOs + 31 Enums **already built** on rewrite | Moderate | P14 scope for VOs/Enums is replay, not creation. Plan implies new work |
| 13 | "7 Entity, 73 projection shapes" (P14) | 0 Entity + 0 Projection types exist | OK — this IS new work |
| 14 | WS "9 endpoint classes" (P8) | **94 individual Handler files** (1 per method) + 83 Params + 7 Results across 12 domains | **Major** | Wrong architecture described. Rewrite P8 to match actual Handler-per-method pattern |
| 15 | 95 WS endpoints | 95 registrations, 94 handler files (1 mismatch) | Minor | Investigate the 1 registration without a handler |

### Missing from the plan

| # | Missing item | Impact | Where it belongs |
|---|-------------|--------|-----------------|
| 1 | **18 namespaces omitted from P6a domain table**: Activity, Auth, Caddie, Common, Core, Csrf, Exception, Feed, Group, History, Job, Listener, Permalink, Routing, Section, Site, Storage, Telemetry, Validation | **Major** | P6a — these all get migrated too |
| 2 | **Admin theme consolidation**: origin/16.x has 3 admin themes (clear/default/roma) under `admin/themes/`, rewrite consolidated to `themes/admin/{_base,dark,light}` | Moderate | P9 or P10 — structural change not mentioned |
| 3 | **Symfony Messenger / Job system**: 14 files under `src/Piwigo/Job/` (BatchUpload, GenerateDerivative, RegenerateAll, ReindexImages, SendNotificationEmail) + `config/messenger.php` | Moderate | P5 or P6 — async job infrastructure |
| 4 | **Flysystem StorageRegistry** + `config/storage.php`: Disk abstraction layer | Minor | P5 — infrastructure |
| 5 | **16 Listener/Subscriber classes** under `src/Piwigo/Listener/` | Minor | P12 — event system |
| 6 | **21 frontend controllers** (P6b only lists 9 admin controllers): About, Action, Comments, Feed, Gallery, Identification, ImageDerivative, Install, Nbm, Notification, Password, Picture, Popuphelp, Profile, QSearch, Register, Search, Tags, Upgrade, UpgradeFeed, Ws | Moderate | P6a/P6b — need an explicit step |
| 7 | **Upgrade script removal**: 23 `install/upgrade_*.php` scripts deleted. How does upgrade work now? Doctrine Migrations dep exists but no migration files. | **Major** | P4 or P5 — upgrade path undefined |
| 8 | **template-extension/ directory**: Sample template overrides in origin/16.x | Minor | Needs explicit deletion or migration |
| 9 | **doc/ directory**: README translations (ca, en, fr) | Trivial | P21 or delete |
| 10 | **local/ directory**: Config override structure (`local/config/`, `local/css/`, `local/language/`) | Minor | Must survive across phases |
| 11 | **Doctrine DBAL migrations**: `doctrine/migrations` ^3.9 is in require but no migration files exist — SQL lives in `install/piwigo_structure-mysql.sql` + `install/config.sql` | Moderate | Clarify: does the replay use Doctrine migrations or keep SQL files? |
| 12 | **tools/ directory**: 20+ development tools (latte-lint, smarty-to-latte, css-audit, measure-nesting, openapi-dump, plugin-lint, phpstan extensions, etc.) | Minor | P0-P2 — some tools must be built before the code they lint |
| 13 | **Custom PHPStan rules**: 6 custom rules in `tools/phpstan/` + Latte engine resolver | Minor | P0 or P2 — needed for PHPStan to work |

### Structural risks

1. **P3 is not a "move" — it's a rewrite.** Origin/16.x has 30+ procedural `include/functions_*.inc.php` files. These cannot be autoloaded or namespaced. They must be *converted* to classes first. The plan describes P3 as a namespace move, but the real work is converting procedural code to OOP.

2. **PHPStan level progression may not work incrementally.** The current rewrite is at L10. The plan proposes going L0→L5→L6→L7→L8→L10 across phases. But the code patterns (VOs, readonly classes, strict typing) were designed FOR L10. Applying a half-migrated codebase at intermediate levels may surface different errors than the final code — making intermediate green states harder than expected.

3. **`config/container.php` cannot be cherry-picked even by domain.** Each P6a domain adds service definitions that reference services from OTHER domains (circular cross-references). The container must grow in a specific order that respects resolution order, not just "URL first, User second."

4. **CSS split is already done on rewrite branch.** Current `themes/admin/_base/theme.css` is 15 lines (import hub). 54 CSS files exist. The plan describes the split as future work (P9) but it was already implemented. Replay must reproduce this structure, not rediscover it.

5. **Pest Browser is v4.3.1 and pre-v1 quality.** The plan replaces Playwright with Pest Browser, but the current E2E suite (27 test files, 15 specs) was written for Playwright. Pest Browser's gaps (no multi-tab, no network interception, no dialog handling) may prevent porting some tests. Consider keeping Playwright alongside Pest for the E2E suite.

6. **The plan has no Vitest** in P0's commit list despite listing it in the "Test framework" section. Vitest + happy-dom must be in P0's npm setup.

7. **Current CI is already 11 jobs.** P0 describes creating CI from scratch but the reference implementation (`.github/workflows/ci.yml`) already has: style, phpstan, latte, precompile, unit, audit, build, rector, integration, e2e, ts. P0 must reproduce all of these, not the simplified 6-job description in the plan.

### Deep structural analysis (origin/16.x baseline)

#### Database schema

- **MyISAM → InnoDB**: ALL 34 tables in origin/16.x use `ENGINE=MyISAM` with no
  explicit charset. Rewrite uses `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci`. No FULLTEXT indexes exist so migration is safe,
  but this is a schema-level change that must happen early (P1 or P4).
- **7 new tables** added by rewrite: `derivative_settings`, `derivative_size`,
  `extension_ignored_updates`, `integrity_ignored_anomalies`, `plugin_migrations`,
  `search_filter_view`, `user_failed_logins`
- **enum('true','false') → tinyint(1)**: origin has 17 enum columns, rewrite has 7.
  10 boolean-string enums were converted to tinyint(1). 9 `binary` attributes removed.
- **Column type changes** throughout (mediumint → int, varchar sizes, etc.)
- Upgrade from origin schema to rewrite schema must be handled explicitly.

#### Procedural function inventory

**664 total procedural functions** across include/ and admin/include/ — this is the
real scope of P5 (service migration):

| File | Functions | Target namespace |
|------|-----------|-----------------|
| `admin/include/functions.php` | 79 | Admin/* services |
| `include/functions_user.inc.php` | 62 | Users/* |
| `include/dblayer/functions_mysqli.inc.php` | 45 | Db/* |
| `include/functions.inc.php` | 78 | Core/*, Url/*, Html/*, etc. |
| `include/functions_html.inc.php` | 23 | Html/* |
| `include/functions_mail.inc.php` | 22 | Mail/* |
| `include/functions_url.inc.php` | 21 | Url/* |
| `admin/include/functions_upload.inc.php` | 21 | Upload/* |
| `include/functions_notification.inc.php` | 18 | Notification/* |
| `include/functions_search.inc.php` | 17 | Search/* |
| `include/functions_category.inc.php` | 17 | Category/* |
| `admin/include/functions_notification_by_mail.inc.php` | 15 | Notification/* |
| `include/functions_session.inc.php` | 12 | Session/* |
| `include/functions_plugins.inc.php` | 10 | Plugin/* |
| `include/functions_tag.inc.php` | 9 | Tag/* |
| `admin/include/functions_upgrade.php` | 9 | Admin/* |
| `include/functions_comment.inc.php` | 8 | Comment/* |
| `admin/include/functions_metadata.php` | 7 | Metadata/* |
| `admin/include/functions_history.inc.php` | 6 | History/* |
| `include/functions_picture.inc.php` | 6 | Image/* |
| `include/functions_metadata.inc.php` | 5 | Metadata/* |
| Others (12 files) | ≤4 each | Various |

#### Config key evolution

- **189 keys** in origin/16.x `config_default.inc.php`
- **271 keys** in rewrite's Config::SCHEMA
- **96 new keys** added (many were previously DB-only in `config.sql`)
- **14 keys removed**: `anti-flood_time`, `debug_l10n`, `default_filters_views`,
  `derivatives_strip_metadata_threshold`, `external_authentification`,
  `metadata_keyword_separator_regex`, `password_hash`, `password_verify`,
  `php_extension_in_urls`, `question_mark_in_urls`, `tag_url_style`,
  `template_combine_files`, `checksum_compute_blocksize`, `comments_page_nb_comments`

#### Boot sequence (origin/16.x → rewrite)

Origin boot (`common.inc.php`):
1. `config_default.inc.php` → set `$conf` defaults
2. `local/config/config.inc.php` → overrides
3. `local/config/database.inc.php` → DB credentials
4. `dblayer/functions_mysqli.inc.php` → DB functions (45 procedural)
5. `constants.php` → 52 defines
6. `functions.inc.php` → 78 utility functions
7. `template.class.php` → Smarty wrapper
8. `cache.class.php`, `Logger.class.php`
9. `user.inc.php` → session + permissions
10. `filter.inc.php` → content filtering

Rewrite boot (`index.php`):
1. `vendor/autoload.php` → Composer PSR-4
2. `Paths::fromIndex()` → root path resolution
3. Fast-paths for `?i/` (derivatives), `?install`, `?upgrade_feed`, `?upgrade`
4. `CommonBootstrap::run()` → ConfigLoader + Kernel::boot + DI + middleware

**Global variables** beyond `$conf/$user/$page/$lang`: `$filter`, `$template`,
`$logger`, `$persistent_cache`, `$theme`, `$header_msgs`, `$header_notes`, `$dbnow`

#### jQuery elimination

- **2752 jQuery references** in origin/16.x → **0** on rewrite
- **jQuery UI** (35 files + 107 i18n) → removed entirely
- **20+ jQuery plugins** → replaced by 15 npm packages:
  - colorbox → glightbox
  - plupload → @uppy/core + dashboard + xhr-upload
  - jQuery UI datepicker → flatpickr
  - selectize → tom-select
  - datatables (kept, non-jQuery version: datatables.net ^2.3.8)
  - chart.js (kept, standalone)
  - tipTip → tippy.js
  - jgrowl → native toaster
  - tokeninput → tom-select
  - Jcrop → native
  - cluetip → tippy.js
  - progressbar → native CSS
  - ajaxmanager → removed
  - sort → native
  - piecon → kept (themes/_base/js/plugins/piecon.ts)
  - underscore → removed (native Array methods)
  - moment → dayjs
  - nouislider (new, replaces jQuery UI slider)
  - js-cookie (new, replaces manual cookie handling)
  - @popperjs/core (new, positioning library)

#### Smarty template syntax scope

| Pattern | Count | Latte equivalent |
|---------|-------|-----------------|
| `{if }` | 1117 | `{if }` (same) |
| `{foreach}` | 194 | `{foreach $x as $y}` (syntax change) |
| `{\|translate}` | 883 | `{\|translate}` (same via filter) |
| `{\|escape}` | 214 | Auto-escaped by Latte (remove) |
| `{combine_script}` | 179 | `{=viteEntry('name')}` |
| `{combine_css}` | 88 | `{=cssLink('path')}` |
| `{footer_script}` | 80 | Extract to `.ts` module |
| `{include}` | 71 | `{include 'file.latte'}` |
| `{assign}` | 43 | `{var $x = y}` |
| `{html_style}` | 15 | Extract to `.css` file |
| Other modifiers | ~50 | Mapped in PiwigoExtension (27 filters, 9 functions) |

A `tools/smarty-to-latte/Converter.php` exists on the rewrite branch with 30+
regex-based rewrite passes. It handles the mechanical conversions; complex patterns
need hand-fix.

#### Upgrade mechanism

- Origin: `upgrade.php` discovers `install/upgrade_X.Y.Z.php` scripts by version.
  23 scripts exist (1.3.0 → 15.0.0).
- Rewrite: All upgrade scripts deleted. `UpgradeController` exists but handles
  DB version checks + plugin deactivation only. `doctrine/migrations` is a dep
  but used ONLY for plugin migrations (`PluginMigrationRunner`), NOT core schema.
- **Core schema upgrades are undefined** in the rewrite. Fresh installs use
  `InstallController` → `piwigo_structure-mysql.sql` + `config.sql`. Upgrades from
  existing installations are not handled — this must be addressed in the plan.

#### Language format migration (.lang.php → .po)

**NOT mentioned in the plan.** The entire language system was converted from PHP
arrays (`$lang['key'] = 'value'`) to GNU gettext PO format:

- **324 .lang.php files** across 72 languages → **322 .po files**
- `gettext/gettext` ^5.7 + `gettext/translator` ^1.2 added as Composer deps
- All `$lang['key']` refs → `Lang::t('key')` (1053 calls in src/)
- LangService reads `.po` via `Gettext\Loader\PoLoader`
- LangService retains `.lang.php` fallback for parent-language detection only
- Converter tools exist: `tools/i18n/php-to-po.php`, `convert-all.php`
- 699 `|translate` calls in templates need the filter registered in Latte
- This migration belongs in P5 (Config/DB phase) alongside LangService, or as
  a standalone phase between P4 and P5

#### Serialized PHP data → normalized tables

Three config values store `serialize()`d PHP in origin's `piwigo_config` table:
- `extents_for_templates` → `a:0:{}` → removed entirely
- `show_nb_*` → `a:11:{...}` → JSON or individual columns
- `updates_ignored` → `a:3:{...}` → normalized to `piwigo_extension_ignored_updates`
- Derivative params blob → normalized to `piwigo_derivative_settings` + `piwigo_derivative_size`

#### Event system mapping

- 6 legacy events intentionally removed (asset pipeline hooks: `combined_css`,
  `combined_css_postfilter`, `combined_script`, `functions_history_included`,
  `functions_mail_included`, `get_history`)
- 15 new PSR-14 events added with no legacy ancestor
- All other ~120 events have 1:1 mapping (trigger_notify/trigger_change → class)

#### Include file cross-dependencies

The procedural files have **minimal cross-dependencies** — only 2:
- `functions_comment.inc.php` → `functions_mail.inc.php`
- `functions_user.inc.php` → `functions_mail.inc.php`

This means **Mail must migrate before Comment and User** in P5 tiers, but
otherwise the migration order is unconstrained.

#### Container coupling analysis

129 service definitions, all using `factory()` closures (only 1 `autowire`).
Highest DI fanout (non-controller files):
- `UserService` — imports from 21 namespaces
- `SectionInitializer` — 17 namespaces
- `SearchService` — 15 namespaces
- `HtmlService` — 15 namespaces
- `CommonBootstrap` — 15 namespaces

These are the files that cannot be migrated early — they depend on almost
everything else being in place first.

#### Runtime environment requirements

- **PHP 8.5** (composer.json `^8.5`, current: 8.5.4)
- **Node.js 24** (current: 24.15.0, no `.nvmrc` / `engines` field)
- **TypeScript 6.0.3**
- **MySQL 8.4** (CI uses `mysql:8.4` image)
- **pcov NOT installed** — must install for code coverage (P0 blocker)
- **ext-intl NOT installed** — some Symfony components may need it
- **ext-sockets installed** — Pest Browser compatible
- **Pest 4 requires PHP ^8.4** — compatible
- **Pest 4 requires PHPUnit ^13** — compatible with existing 13.1.11
- **amphp/amp already installed** — no conflict with Pest Browser deps

#### Branch diff dimensions

- **1549 files deleted** (origin files that get replaced)
- **2271 files added** (new files on rewrite)
- **104 files modified** (exist on both branches, changed in place — mostly language
  help HTML, install SQL, theme skins, tools)
- **208 files renamed** (with content changes)
- Total: **4132 files changed, 527K insertions, 434K deletions**
- 21 root config files must be created from scratch in P0 (composer.json,
  package.json, tsconfig.json, vite.config.ts, eslint.config.ts, phpstan.neon,
  pint.json, rector.php, psalm.xml, phpunit.xml.dist, .editorconfig, etc.)

#### Current gate status (reference branch)

| Gate | Status | Notes |
|------|--------|-------|
| PHPUnit (unit) | **PASS** | 1122 tests, 3937 assertions, 1.2s |
| PHPStan L10 | **PASS** | 0 errors |
| Pint (PSR-12) | **PASS** | |
| TypeScript | **PASS** | tsc --noEmit clean |
| Vite build | **PASS** | 68 entries, 541ms |
| Stylelint | **PASS** | 7 warnings, 0 errors |
| Rector | **DRIFT** | 94 files would change (2 cosmetic rules: arrow fn return types) |
| ESLint | **FAIL** | 33 errors in `tests/E2e/visual-regression.ts` (26 prettier, 7 strict) |

The ESLint failures are all in one recent test utility file. Rector drift is
cosmetic type-hint additions. Both must be fixed before claiming "all gates green."

#### PHP 8.5 compatibility of origin/16.x

Origin code parses cleanly on PHP 8.5 (`php -l` passes). Runtime issues:
- **Dynamic properties** (deprecated 8.2, still warning in 8.5): found in 14 classes
  (template 63 assigns, image 27, check_integrity 15, tabsheet 15, Logger 13).
  Vendored libs (feedcreator 93, jshrink 38) also affected.
- **utf8_encode/decode** (removed 8.2): 4 references — will fatal
- **addslashes on all input**: 7 refs in common.inc.php — works but unnecessary
- **`functions_mysql.inc.php`**: 25 mysql_ calls — dead code (only mysqli used),
  but the file is included by name via `$conf['dblayer']` config

Origin/16.x will **not run cleanly** on PHP 8.5 without patching. P0 must either:
1. Fix the 4 `utf8_encode/decode` calls and dynamic property warnings first, OR
2. Start P1 (Composer + vendor migration) immediately to replace the vendored libs
   that cause most warnings

#### DB query migration scope

- **1168 total DB calls** (pwg_query + pwg_db_*) across all origin files
- **266 query2array()** calls (fetch-all pattern)
- **139 mass_inserts/mass_updates/single_insert/single_update** calls
- **45 DB wrapper functions** in functions_mysqli.inc.php → Doctrine DBAL
- Key mappings: pwg_query→executeQuery, query2array→fetchAllAssociative,
  pwg_db_real_escape→prepared statements, mass_inserts→batch INSERT

#### Error handling migration scope

- **206 die() calls** across origin → must become exceptions or HTTP responses
- **34 trigger_error** calls → must become exceptions
- **78 fatal_error/bad_request/access_denied/page_not_found** calls → HTTP status codes

#### Theme system migration

- `themeconf.inc.php` (PHP array) → `theme.json` (JSON, schema-validated)
- Admin themes: `admin/themes/{clear,default,roma}/themeconf.inc.php` →
  `themes/admin/{_base,dark,light}/theme.json`
- Gallery themes: `themes/default/themeconf.inc.php` →
  `themes/_base/theme.json`
- ThemeRegistry reads `theme.json`, validates against JSON schema, resolves
  parent chains

#### Admin page dispatch

Origin: `admin.php` reads `$_GET['page']` and does
`include(PHPWG_ROOT_PATH.'admin/'.$page['page'].'.php')` — dynamic file inclusion.
Rewrite: AdminController resolves `$page` to an `AdminSubControllerInterface` service
from the DI container. 62 admin PHP files → 9 admin controllers.

### Verified claims (correct)

- origin/16.x: 947 PHP, 333 JS, 140 TPL, 83 CSS ✓
- 53 namespaces under src/Piwigo/ ✓
- 894 PHP files in src/ ✓
- 133 non-test Latte templates (136 total, 3 in test fixtures) ✓
- 82 authored TS files in themes/ (115 total including tests/config) ✓
- 8 middleware classes ✓
- No include/ or admin/ directories on rewrite ✓
- ServiceLocator: 0 usages in src/ ✓
- $GLOBALS: 6 remaining (all in Lang.php bridge + comments) ✓
- PHPWG_ROOT_PATH: effectively eliminated (5 remaining = comments only) ✓
- PHPStan level 10 on rewrite ✓
- 37 repositories, 47 services ✓
- 12 WS domains ✓
- 83 WS Params files ✓
- No plugins bundled in origin/16.x (only `plugins/index.php`) ✓
- Smarty vendored: 173 files ✓
- define() in constants.php: 52 ✓
- Admin PHP pages in origin/16.x: 62 ✓
- install/ upgrade scripts: 23 ✓

---

## Pest as the unified test framework

Pest 4 (latest stable: v4.7.0) replaces both PHPUnit and Playwright as a single
test harness:

- **Unit/Integration:** Pest 4 runs PHP tests natively (it IS PHPUnit under the hood)
- **Browser E2E:** `pestphp/pest-plugin-browser` v4.3.1 (requires Pest ^4.4.5)
  wraps Playwright's server via WebSocket — same Chromium engine, PHP test syntax.
  Covers: navigation, form fill, click, screenshots, console error checks, content
  assertions, file upload. Gaps: no multi-tab, no network interception, no dialog
  handling — acceptable for our E2E scope.
- **Architecture:** `pestphp/pest-plugin-arch` v4 enforces structural rules (no
  globals, strict types, namespace boundaries)
- **Mutation:** `pestphp/pest-plugin-mutate` v4 replaces Infection — built-in, same
  MSI metric
- **Type coverage:** `pestphp/pest-plugin-type-coverage` v4 tracks PHP
  type-declaration coverage
- **One runner, one report, one CI job family** instead of PHPUnit + Playwright +
  Vitest + Infection as separate tools

**Exception: Vitest stays for TypeScript unit tests.** Pest can't test TS logic — only
browser-level assertions. Pure TS utility functions (formatters, reducers, URL builders)
need Vitest. But E2E browser tests move from Playwright → Pest Browser.

---

## Dependency graph

Traced from actual `use` statements in the final codebase. 14 phases (P0-P13),
down from 22 after merging phases that share the same concern and touch the
same files.

```
P0 Tooling ──→ P1 Composer + Rector + PHPStan L5
                │
                └─→ P2 PSR-4 (~51 first-party classes → src/Piwigo/)
                     │
                     └─→ P3 Kernel + DI + middleware + routing
                          │
                          └─→ P4 Config + DB + facades + constants
                               │
                     ┌─────────┴──────────────────────────┐
                     │                                     │
                     ↓                                     ↓
                P4.5 Frontend tooling (parallel)      P5 Service migration
                 │   Vite + TS + ESLint + any→0        │  + legacy deletion
                 │   + webfont swap                    │  include/ → src/
                 │                                     │  admin/ → src/
                 │   SYNC: P4.5b must finish           │  ServiceLocator kill
                 │   before P8 (footer_script           │  $GLOBALS cleanup
                 │   extraction before Latte)           │
                 │                                     │
                 ├──────────┐                          │
                 ↓          ↓                          ↓
            P7 CSS +    P6 WS endpoints ←─────────────┘
            Tailwind    + OpenAPI
                 │          │
                 └────┬─────┘
                      ↓
                 P8 Templates + assets
                 │   (Smarty → Latte + ViteManifest)
                 │
                 └─→ P9 Plugin/Theme contracts
                 │   + god-class decomposition
                 │   + 7 bundled extensions
                 │
                 ├─→ P10 Security hardening
                 │
                 ├─→ P11 Type correctness + mixed elimination
                 │   (§1.6 + full §1.7: VOs, Entities, DTOs,
                 │    WsResult, Request DTOs, SearchRules,
                 │    repo returns, factory classes)
                 │
                 └─→ P12 Quality gates
                      (mutation, a11y, bundle budgets, coverage ≥40%)
                      │
                      └─→ P13 Repository restructure
                           (web-root isolation, themes/ → resources/)
```

### Detailed dependency rationale

**P1 → P2 → P3 → P4 (strictly sequential):**
Composer autoload needed for PSR-4. PSR-4 needed for Kernel/Container.
Kernel/Container needed for Config/DB/facades. Constants retirement
folded into P4 because `Paths` depends on Config + DI (same files).

**P4 branches into P4.5 (parallel) and P5 (sequential):**
P4.5 touches `themes/*/js/`, P5 touches `src/Piwigo/`. No file overlap.
P4.5b (inline JS extraction) must complete before P8 (Latte conversion
needs `{footer_script}` blocks to still exist for extraction).

**P5 → P6 + P7 (service layer feeds WS + CSS):**
WS endpoints import from every domain service (P5). CSS references
template structure from renderers (P5). Both need the service layer stable.

**P7 + P8 need each other's outputs:**
Latte `{cssLink("path")}` references split CSS file paths from P7.
Tailwind scans `.latte` files from P8. Solution: P7 steps 1-5 (tokens)
before P8, P7 steps 6-8 (Tailwind) after P8.

**P8 → P9 (templates before plugin contracts):**
Plugins register Latte template dirs, not Smarty. ThemeRegistry fires
`ThemeChanged` event. Template engine must be Latte.

**P9 → P10, P11, P12 (independent leaves off plugin contracts):**
Security (P10) only needs middleware + auth (from P5), placed here for
logical grouping. Type correctness (P11) needs WS endpoints (P6) + repos (P5).
Quality gates (P12) need everything substantially complete.

**P13 last:** Moves every directory, updates every config file. Needs all
tests passing to catch breakage.

---

## Phase breakdown

### P0 — All dev tooling + CI (on bare `origin/16.x`)

**Install every tool up front.** Most will report hundreds or thousands of issues
against the legacy codebase — that's expected. Each tool records its baseline issue
count. CI enforces ratchets: issue counts can only go down, never up. No phase is
blocked waiting for a tool to become "ready."

**The rule from here forward:** no code change lands without all CI gates green
(i.e. no regressions from the committed baselines).

#### PHP tooling

- `composer.json`: PHP 8.5
- **Pest 4** + plugins: `pest` ^4, `pest-plugin-arch` ^4, `pest-plugin-mutate` ^4,
  `pest-plugin-type-coverage` ^4, `pest-plugin-browser` ^4
- **pcov** for code coverage (`composer require --dev pcov/clobber`)
- **Pint** (`pint.json`) — run `vendor/bin/pint`, commit the formatted result,
  baseline is now 0 errors
- **PHPStan** (`phpstan.neon`, `tools/phpstan-bootstrap.php`) — start at level 0,
  record baseline error count. CI: `vendor/bin/phpstan analyse` must not exceed
  baseline. Ratchet down as phases fix errors.
- **Rector** (`rector.php`) — `vendor/bin/rector --dry-run` records pending
  transformations as baseline. CI fails if new transformations appear.
- **Psalm** (`psalm.xml`) — install, run `psalm --show-info`, record baseline
  count (will be high — thousands). CI: count must not increase.
- `phpunit.xml.dist` with `<coverage>` filter on `include/`, `admin/`, `src/Piwigo/`
- `tests/Pest.php`, `tests/bootstrap.php`
- `tests/Unit/SmokeTest.php` — trivial passing test
- `tests/Arch/StructuralTest.php` — first arch rules (grow per phase)

#### Frontend tooling

- `package.json`: all dev deps installed now, baselined against legacy JS
- **Vite** + `vite.config.ts` — configure but don't convert files yet. `npm run build`
  may fail on legacy JS; record as known state.
- **TypeScript** + `tsconfig.json` — `allowJs: true`, `checkJs: false` initially.
  `npm run typecheck` baseline (will be 0 errors if only checking `.ts` files,
  which don't exist yet — that's fine).
- **ESLint** + `eslint.config.ts` — run against legacy JS, record baseline error
  count. CI: must not increase.
- **Prettier** + `.prettierrc.json` — format, commit result, baseline is 0.
- **Stylelint** + `.stylelintrc.json` — run against legacy CSS, record baseline.
  CI: must not increase.
- **Vitest** + `vitest.config.ts` + `@vitest/coverage-v8` + `happy-dom`
- `tests/js/smoke.test.ts`

#### Browser E2E

- `tests/Browser/InstallTest.php` — fresh install flow
- `tests/Browser/SmokeGalleryTest.php` — gallery home loads
- `tests/Browser/SmokeAdminTest.php` — admin dashboard loads
- `.env.test` template

#### CI + baselines

- `.github/workflows/ci.yml` — all jobs from day one:
  pest-unit, pest-browser, pint, phpstan, psalm (baseline), rector,
  eslint (baseline), stylelint (baseline), vitest, coverage, audit
- `.editorconfig`
- `.coverage-baseline` — committed coverage floor (starts at 0%)
- `.phpstan-baseline.neon` — committed PHPStan baseline (known errors)
- `psalm-baseline.xml` — committed Psalm baseline
- `.eslint-baseline.json` or equivalent — committed ESLint error count
- `.stylelint-baseline` — committed Stylelint error count
- Convention: each phase's final commit tightens one or more baselines

#### Dev fixtures

- `dev/fixtures/piwigo-16.x.sql` — baseline DB snapshot
- `dev/fixtures/README.md`

**Test count after P0:** ~5 PHP unit, 3 browser, 1 TS unit, arch rules.
**Baselines recorded:** PHPStan (level 0 errors), Psalm (info count), ESLint
(error count), Stylelint (error count), coverage (0%), Rector (pending transforms).
All CI gates green against their baselines.

---

### P1 — Composer + Rector + PHPStan (PHP modernization)

**All tools already installed and baselined from P0.** This phase uses them.

**What happens:**
- Remove vendored `include/smarty/`, `include/phpmailer/`, `include/emogrifier.class.php`,
  `include/jshrink.class.php`, `include/minify/`, `include/passwordhash.class.php`,
  `include/feedcreator.class.php`, `include/phpqrcode.php`, `include/mdetect.php`,
  `include/base32.class.php`
- `composer require` their packagist equivalents
- Add `require 'vendor/autoload.php'` in `include/common.inc.php`
- Remove `include/php_compat/` shims, `include/dblayer/functions_mysql.inc.php`
- Migrate password hashing to `password_hash()` / bcrypt
- Apply Rector PHP 8.0-8.5 sets (tightens Rector baseline to 0 pending)
- Pint format pass, add `declare(strict_types=1)` to all first-party files
- Push PHPStan from L0 → L5, tightening `.phpstan-baseline.neon` at each level

**Tests:** Browser E2E after each vendor swap + Rector pass. Password hashing unit test.
Arch test: no vendored lib dirs exist.

**Baselines tightened:** PHPStan (L5, fewer known errors), Rector (0 pending),
Psalm (count drops from strict_types + type declarations).

**Gate:** PHPStan L5 clean. Rector dry-run clean. Pint clean. Browser green.
All other baselines unchanged or improved.

---

### P2 — PSR-4 namespace migration

**What happens:**
- Create `src/Piwigo/` directory tree
- PSR-4 autoload config in `composer.json`
- Extract classes from ~51 first-party class declarations across include/ and
  admin/include/ (16 `.class.php` files in include/, 8 in admin/include/, plus
  inline classes in `functions_search.inc.php` (7 classes), `ws_core.inc.php` (4),
  `ws_protocols/*.php` (5), `block.class.php` (3), `template.class.php` (5),
  `functions_plugins.inc.php` (2))
- Split multi-class files into one-class-per-file
- Namespace all extracted classes under `Piwigo\`
- Update all `require`/`include` references
- Procedural files (`include/functions_*.inc.php`) stay for now — they can't be
  autoloaded until they're converted to classes in P5

**Tests:** Unit test for each moved class with testable logic. Browser E2E.
Arch test: all classes in `src/Piwigo/` have `declare(strict_types=1)`.

**Gate:** `composer dump-autoload --strict-psr`. PHPStan clean. Browser green.

---

### P3 — Core kernel + DI + boot sequence

#### P3a — Kernel + boot skeleton
- `Kernel.php`, `CommonBootstrap.php`, `RequestFactory.php`, `ResponseEmitter.php`
- `index.php` becomes single entry point
- **Tests:** `KernelBootTest.php`, `ContainerSmokeTest.php`

#### P3b — DI container
- `Container.php`, `config/container.php`
- **Tests:** `ContainerDefinitionsTest.php`

#### P3c — PSR-15 middleware + routing
- All 8 middleware classes, `MiddlewarePipeline.php`, `config/routes.php`
- Legacy entry points (`i.php`, `action.php`, `ws.php`, etc.) routed through `index.php`
- **Tests:** Middleware unit tests, `FastPathHeadersTest.php`, browser E2E for legacy URLs

**Gate:** PHPStan raise to L6-7. All services resolve. Browser green.

---

### P4 — Config + DB + typed facades

#### P4a — Config service
- `Config.php` with SCHEMA, `ConfigService.php`, `ConfigLoader.php`,
  `tools/build-config-accessors.php`
- Replace `$conf['key']` reads with typed accessors
- **Tests:** `ConfigTest.php`, `ConfigRepositoryTest.php`, accessor sync CI

#### P4b — Database layer
- Doctrine DBAL Connection, `Dml.php`, first repositories
- **Tests:** Repository integration tests

#### P4c — Typed facades + constants retirement
- `PageState.php`, `CurrentUser.php`, `Lang.php` + `LangService.php` + `Translator.php`
- `Paths.php` value object (replaces `PHPWG_ROOT_PATH`, **885 usages across 282 files**)
- Replace all 52 `define()` constants from `include/constants.php` with typed
  alternatives (`Tables` class, `AppInfo`, `Paths`, `Config` accessors)
- **Tests:** `PageStateTest.php`, `LangTest.php`, `PathsTest.php`, `PemUrlResolverTest.php`
- Arch test: no `define()` in src/, no `PHPWG_ROOT_PATH` in src/

**Why constants are here, not separate:** `Paths` depends on `Config` and DI container
(both from P4a/P4b). The files being patched for `PHPWG_ROOT_PATH` are the same
service files being created in P4a-b. One pass, not two.

**Gate:** PHPStan raise to L8+. Config accessor sync CI. All tests green.

---

### P4.5 — Frontend tooling (parallel track)

**Can run in parallel with P5 because it touches different files** (themes/js vs
src/Piwigo).

#### P4.5a — Vite + TypeScript conversion
**Vite, TS, ESLint, Prettier, Stylelint already installed and baselined in P0.**
- Configure Vite entry points for the project structure
- Convert all 38 authored JS files to `.ts`
- Fix lint errors (tightens ESLint + Stylelint baselines toward 0)
- **Tests:** `npm run build`, `npm run typecheck`, lint baselines tightened

#### P4.5b — Inline JS extraction + `any` reduction
- `{footer_script}` blocks → `.ts` modules, `data-*` bridges, `getPageData<T>()`
- Window globals declaration files, per-file `any` elimination (478 → 0)
- Open Sans webfonts → `@fontsource` (tiny, same Vite pipeline)
- **Tests:** Vitest for `getPageData`. ESLint `no-explicit-any: error`. Browser E2E.

**Why merged:** P5.5a-d were 4 sub-phases of one concern (frontend tooling).
ESLint setup without TS files to lint is pointless. `any` reduction can't happen
until TS files exist. Inline JS extraction needs Vite entries. One pipeline.

**Gate:** TS clean, ESLint clean, Stylelint clean, Vite builds, zero `any`, E2E green.

---

### P5 — Service layer migration + legacy deletion

**The largest phase.** Merges old P6+P7 — same concern (procedural → typed services),
same files (`include/functions.inc.php`, `config/container.php`), and constants
retirement was already folded into P4.

**Strategy:** Group by domain, each domain deletes its source file in the same commit.

#### P5a — Include → src (by domain, 4 tiers)

Tier 1 (no service deps): URL, Cookie, Session, HTML
Tier 2 (depend on Tier 1): Filter, User, Tag, Comment, Rate
Tier 3 (depend on 1+2): Mail, Category, Search, Image, Calendar, Notification, Metadata
Tier 4 (depend on 1-3): Page renderers, Menu, Plugin

**⚠ AUDIT NOTE:** The tiers above list 17+1 (Plugin) include/ domains but the final
rewrite has **35+ namespaces** under src/Piwigo/. Missing from these tiers:
Activity (ActivityRepository, ActivityLogger), Auth (CookieService, PasswordService,
EphemeralKeyService, AuthKeyRepository), Caddie (CaddieRepository), Common (21 VOs +
4 Enums + DTOs), Core (Kernel, Paths, Lang, PageState, AppInfo, etc.), Csrf
(CsrfService), Exception, Feed (FeedHelper, FeedRepository, PiwigoFeedCreator),
Group (GroupRepository), History (HistoryRepository), Job (5 jobs + 5 handlers +
MessengerFactory), Listener (16 subscribers), Permalink (PermalinkService,
PermalinkRepository), Routing (Router, RouteResult), Section, Site (SiteRepository),
Storage (StorageRegistry), Telemetry (TelemetryService + 5 DTOs), Validation.
These must either be folded into existing Tier 1-4 groups or added as explicit rows.

**After each domain:** that domain's `include/` file is DELETED.

#### P5b — Admin migration

Upload → Albums → Users → Config → Extensions → BatchManager → History →
Maintenance → Misc. Each deletes its `admin/*.php` source.

**⚠ AUDIT NOTE:** P5b only lists 9 admin controller groups, but the final rewrite has
**21 non-admin controllers** that are not accounted for: About, Action, Comments, Feed,
Gallery, Identification, ImageDerivative, Install, Nbm, Notification, Password,
Picture, Popuphelp, Profile, QSearch, Register, Search, Tags, Upgrade, UpgradeFeed,
Ws. These must be part of P5a (they consume include/ procedural code) or a new step
between P5a and P5c.

#### P5c — Cleanup

- Delete `ServiceLocator.php`, retire `$GLOBALS` bridges
- Delete all 23 root entry points (`about.php`, `comments.php`, `search.php`,
  `picture.php`, `tags.php`, `identification.php`, `register.php`, `password.php`,
  `feed.php`, `i.php`, `action.php`, `ws.php`, `qsearch.php`, `random.php`,
  `profile.php`, `popuphelp.php`, `notification.php`, `nbm.php`, `admin.php`,
  `install.php`, `upgrade.php`, `upgrade_feed.php`)
- Delete `include/` and `admin/` directories entirely
- Arch tests: no `ServiceLocator`, no `$GLOBALS`, no `include/`, no `admin/`

**Tests per domain:** Unit tests for every service created. Integration tests for
repositories. Browser E2E for every route after each domain batch.

**Gate:** PHPStan L10 clean. Zero `include/` files. Zero `admin/` files.
Browser E2E green. Every namespace has tests.

---

### P6 — WS typed endpoints + OpenAPI

**Merges old P8 + P18.** Both are "type the WS layer." `#[ApiMethod]` decoration
is just finishing what `MethodDefinition` starts — same files, same concern.

- `PwgServer.php`, `MethodDefinition`, `ParamDefinition` value objects
- `WsMethodRegistrar.php` — register all 95 methods (event-driven via
  `WsMethodsRegistering`)
- `src/Piwigo/Ws/Action/Pwg/{Domain}/{Name}Handler.php` — **94 individual handler
  files** (one per WS method), organized across 12 domains (Activity, Categories,
  Comments, Extensions, Groups, History, Images, Permissions, Rates, Session,
  Tags, Users) + 5 top-level handlers
- 83 `*Params.php` typed parameter DTOs alongside the handlers
- 7 `*Result.php` typed result DTOs (remaining 87 endpoints return untyped arrays
  — addressed in P11)
- Supporting infrastructure: `WsAction`, `WsHelper`, `WsParams`, `WsResult`,
  `WsError`, `WsType`, `PwgError`, `PwgNamedArray`, `PwgNamedStruct`, encoders
  (`PwgJsonEncoder`, `PwgRestEncoder`, `PwgXmlWriter`), `PwgRestRequestHandler`
- `#[ApiMethod]` attribute on all 94 handlers
- `SpecBuilder` → OpenAPI 3.1 spec generation reading attributes

**Tests:** `WsApiTest.php` — integration test for all 95 endpoints. OpenAPI spec
validation (cebe/redocly). Parameter validation tests.

**Gate:** All 95 endpoints respond. OpenAPI validates. Integration tests green.

---

### P7 — CSS modernization + Tailwind

**Merges old P9 + P19.** P9 builds the token foundation that P19 consumes. Running
them as separate phases means touching every admin CSS file twice. One pass: build
the token system, then replace hand-written rules with Tailwind utilities.

**⚠ AUDIT NOTE:** origin/16.x has 3 admin themes: `admin/themes/{clear,default,roma}`
(316 files total). The rewrite consolidated them into `themes/admin/{_base,dark,light}`.
This structural change must happen here or as a prerequisite step.

1. Delete orphan CSS
2. Split theme monoliths (8603 → 54 files admin, 1004 → 22 files frontend)
3. Design tokens (93 admin, 42 frontend)
4. Skin/child theme refactor, `!important` elimination (720 → 4)
5. Inline `<style>` extraction, search CSS collapse
6. Install `@tailwindcss/vite`, create `tailwind.css` with `@theme inline`
   referencing `--admin-*` tokens
7. Migrate admin CSS from hand-written rules to Tailwind utilities
8. `@source` for `.latte` scanning, Stylelint config for Tailwind v4

**Tests:** Stylelint clean. Visual regression screenshots. Browser E2E.
`wc -l themes/admin/_base/theme.css` → 15 (import hub).

**Gate:** Stylelint 0 errors. Theme files at target sizes. E2E green.

**NOTE:** Steps 6-8 (Tailwind) depend on Latte templates (P8) existing for class
scanning. If P8 isn't done yet, do steps 1-5 now and steps 6-8 after P8.

---

### P8 — Template migration + asset pipeline (Smarty → Latte → ViteManifest)

**Merges old P10 + P11.** ViteManifest is wired INTO the Latte engine via
`PiwigoExtension`. The `{=viteEntry()}` and `{=cssLink()}` calls are Latte
functions. Converting templates and wiring their asset helpers is one concern.

**Depends on:** P5 (services stable), P4.5 (Vite entries exist), P7 steps 1-5
(CSS files in final locations).

1. Add Latte engine, wire `PiwigoExtension` (which includes ViteManifest)
2. `ViteManifest.php` — reads `dist/manifest.json`
3. Build Smarty → Latte converter tool
4. Convert admin templates (70 files)
5. Convert frontend templates (56 files — includes mail templates)
6. Convert standard pages templates (7 files)
6. Precompile pipeline + CI gate
7. Delete Smarty dependency + legacy asset pipeline (`CombineService`, etc.)

**Tests:** `ViteManifestTest.php`, `AssetServiceTest.php`, `composer lint:latte`,
`composer precompile:templates`, visual regression, browser E2E every route.

**Gate:** Zero `.tpl` files. All assets from `dist/assets/`. Latte lint + precompile
clean. E2E green.

---

### P9 — Plugin / Theme contracts + bundled extensions + decomposition

**Merges old P12 + P17 + P22.** All three are "the plugin/theme system":
- P12: define the contracts (`PluginInterface`, events, registry)
- P17: decompose the admin god-classes that manage plugins
- P22: migrate the 7 bundled extensions as proof the contracts work

Shipping PluginInterface without decomposing the god-classes that manage plugins
leaves the admin layer broken. Shipping contracts without migrating at least the
bundled extensions means no proof they work. One phase.

**Depends on:** P5 (service layer), P6 (WS endpoints), P8 (Latte templates).

1. PSR-14 event system — ~160 typed event classes
2. EventDispatcher integration
3. `PluginInterface` + `PluginRegistry` + `PluginMigrationRunner`
4. `ThemeInterface` + `ThemeRegistry`
5. Decompose `Plugins.php` (726 lines) → `PluginScanner`, `PluginLifecycle`, `PemCatalog`
6. Decompose `Themes.php` (692 lines) + `Languages.php` (385 lines) — same pattern
7. Fix WS handler Demeter violations (10 consumers)
8. Migrate 7 bundled extensions (1 commit each):
   AdminTools, LocalFilesEditor, TakeATour, language_switch,
   elegant, modus, smartpocket
9. OpenAPI SpecBuilder
10. Delete legacy event functions

**Tests:**
- `PluginRegistryTest.php`, `PluginSchemaTest.php`, `EventSymmetryTest.php`
- Unit tests for each decomposed service
- Plugin install/activate/deactivate/delete lifecycle integration tests
- Browser E2E: each bundled extension's main feature works
- Arch test: all event classes are readonly
- OpenAPI spec validation

**Gate:** All 160 events dispatch. Plugin lifecycle works. All 7 extensions functional.

---

### P10 — Security hardening

1. Session cookie hardening (`SameSite=Lax`, `HttpOnly`, `Secure`)
2. Rate limiting + login throttle
3. Security headers middleware (CSP, XFO, XCTO, etc.)
4. `docs/SECURITY.md`

**Tests:** Integration: cookie attributes, rate limit, header presence.
Browser E2E: login/logout. `composer lint:no-inline-scripts`.

**Gate:** All security headers present. Rate limit tests pass. E2E green.

---

### P11 — Type correctness + mixed elimination (§1.6 + §1.7 complete)

**Merges old P14 + P16.** Both are "eliminate mixed from the domain." P14 was the
"already done" portion and P16 the "remaining." Artificial split — same concern,
same patterns, same files. One phase, ordered by dependency depth.

1. Config schema metadata (`sensitive`, `required`, `description`)
2. `RequestCache` with `@template T`
3. ValueObjects (replay 21 VOs from `Common/ValueObject/`) + Enums (replay
   31 Enums already built on rewrite — these are replay, not new creation)
4. Entity + Projection patterns (new work: 7 Entity, 73 projection shapes —
   none exist on the rewrite branch yet)
5. Session VO + `SessionStore` rename
6. WsResult DTOs (87 of 94 handlers still return untyped arrays)
7. Web/admin Request DTOs (796 raw `$_POST`/`$_GET`/`$_REQUEST`/`$_FILES` reads)
8. Container Factory classes (127 closures in 392 LOC → typed factories)
9. Bare-array repo returns (249 methods → typed)
10. SearchRules deep adoption (SearchService + SearchFilterRenderer)
11. `is_array(.* ?? null)` elimination (152 → 0)
12. Typed error responses (PwgError → enum + i18n key)
13. Psalm as secondary CI gate

**Tests:** Each VO/Entity/DTO/Result gets construction + validation tests.
Integration tests for every search filter combination.

**Gate:** PHPStan L10. `psalm --show-info` < 50.
`grep -rn 'is_array(.* ?? null)' src/` → 0.
`grep -rn '\$_POST\|\$_GET\|\$_REQUEST\|\$_FILES' src/` → 0.

---

### P12 — Quality gates

**Coverage measurement is active since P0.** P12 raises thresholds and adds
the remaining quality tools now that the codebase is substantially complete.

1. Coverage ratchet ≥40% on `src/Piwigo/`. Verify every namespace has ≥1 test.
2. Mutation testing: `pest --mutate`, MSI ≥60%, covered-MSI ≥75%
3. Type coverage: `pest --type-coverage --min=95`
4. Bundle size budgets: `size-limit` per Vite entry point
5. A11y: axe-core in browser tests, WCAG 2.1 AA gate

**Gate:** All quality gates green.

---

### P13 — Repository restructure (STRUCTURE-PLAN)

**Last phase.** Full plan at `docs/STRUCTURE-PLAN.md`. Needs everything stable
because it moves every directory and updates every config file.

14-step filesystem reorganization:
1. `_data/` → `var/`
2. `galleries/` + `upload/` → `var/`
3. Permission-checked original-file controller
4. Rewrite originals URLs to `?/p/<id>`
5. `dev/fixtures/` → `tests/Fixtures/`
6. `build/` cleanup, docs consolidation
7. `src/Piwigo/Plugins/` → `src/Piwigo/PluginConfig/` rename
8. `themes/` dissolution → `resources/` (biggest step)
9. TS types out of `src/`, install SQL move, `language/` → `resources/lang/`
10. Public shim + setup script
11. Meta files + STRUCTURE.md rewrite

**Web-root isolation:** Only `public/` is HTTP-reachable.

**Tests per step:** Full test suite. Any red = stop. Browser E2E + visual regression.

**Gate:** `setup.sh` produces working installation. `vendor/`, `src/`, `var/` not
HTTP-accessible.

---

### Deferred / on-demand (not phased)

- **Monolog:** Swap logger backend when structured logging needed
- **S3/SFTP adapters:** Wire in `config/storage.php` when disk pressure
- **Supervisor/systemd:** Package worker daemon config
- **Renovate:** Port dev-dep auto-merge if churn warrants

---

## What changes from the original branch

| Aspect | `16.x-rewrite` (original) | `16.x-v2` (replay) |
|--------|--------------------------|---------------------|
| Phases | 22 artificially separated phases | 14 phases (merged by concern) |
| Test framework | PHPUnit + Playwright (separate) | Pest 4 (unified: unit + browser + arch + mutate) |
| TS unit tests | None | Vitest |
| Commit style | 27 era-switches per 100 commits | One concern per phase |
| Coverage | Unmeasured (15 namespaces at zero) | Measured from P0, ratcheted, every namespace tested |
| Mutation testing | None | pest-plugin-mutate |
| `include/` deletion | Incremental (97 modifications over months) | By domain, each deleted same commit as migration (P5) |
| PHPStan | Pushed L0→L10 in one burst (commits 300-340) | L5 in P1, raised through P4-P5, L10 by end of P5 |
| CSS + Tailwind | CSS interleaved throughout, Tailwind not started | One phase: tokens → Tailwind (P7) |
| Latte + ViteManifest | Two Latte waves + separate ViteManifest | One phase: Latte + asset pipeline together (P8) |
| Plugin contracts | Mixed with WS refactoring | Contracts + god-class decomposition + bundled extensions together (P9) |
| Type correctness | §1.6 done, §1.7 partially done, split across phases | One phase: all VOs/Entities/DTOs/Results/repos (P11) |
| OpenAPI | SpecBuilder separate from endpoints | WS endpoints + `#[ApiMethod]` + OpenAPI together (P6) |

---

## Execution approach

For each phase:

1. **Write tests first** (or at least alongside — test file committed in same commit group)
2. Use `git show 16.x-rewrite:<path>` to see the target state of every file
3. Pull implementation via `git checkout 16.x-rewrite -- <files>` where the file's
   final state is self-contained (CSS files, templates, standalone classes)
4. Manual re-implementation where the original was built incrementally
   (`config/container.php`, `functions.inc.php` — these need to be built to match
   the current phase's scope, not the final state)
5. After each commit group: run full gate suite. Fix any failures before proceeding.

**Key constraint:** `config/container.php` and `CommonBootstrap.php` grow WITH each
phase. They can't be pulled from the reference branch in bulk — they must reflect
only the services that exist at each point.

---

## Estimated effort

| Phase | Effort | Notes |
|-------|--------|-------|
| P0 Tooling | 2-3 days | Pest + Vitest + CI + coverage |
| P1 Composer + Rector + PHPStan | 3-4 days | Vendor swaps + Rector + L0→L5 |
| P2 PSR-4 | 3-5 days | ~51 class extractions (multi-class splits, procedural→OOP) + test writing |
| P3 Kernel/DI/boot | 4-5 days | Core architecture |
| P4 Config/DB/facades/constants | 7-10 days | Config SCHEMA + Paths (885 usages in 282 files!) + DI wiring |
| P4.5 Frontend tooling | 3-5 days | Parallel: Vite + TS + lint + any→0 + webfonts |
| P5 Service migration | 12-18 days | 35+ namespaces (not 17) + admin + 21 frontend controllers + ServiceLocator kill |
| P6 WS endpoints + OpenAPI | 5-8 days | 94 handlers + 83 Params + encoders + #[ApiMethod] + SpecBuilder |
| P7 CSS + Tailwind | 3-5 weeks | Tokens + splitting + Tailwind rewrite (admin) |
| P8 Templates + assets | 5-7 days | 133 Latte conversions + ViteManifest |
| P9 Plugins + extensions | 2-3 weeks | Events + registries + 3 god-classes + 7 extensions |
| P10 Security | 2-3 days | Cookies + rate limit + headers |
| P11 Type correctness | 4-7 weeks | Full §1.6 + §1.7 in one pass |
| P12 Quality gates | 2-3 days | Mutation, a11y, bundle budgets |
| P13 Repo restructure | 2-3 weeks | 14 steps + web-root isolation |

**Total: ~22-32 weeks** of focused work (adjusted after pre-implementation audit).

---

## Verification (final state)

```bash
vendor/bin/pest                                   # All suites: unit, integration, browser, arch
vendor/bin/pest --mutate --min=60                  # Mutation score
vendor/bin/pest --type-coverage --min=95           # Type coverage
vendor/bin/pint --test                             # PSR-12
vendor/bin/phpstan analyse                         # Level 10, 0 errors
vendor/bin/rector --dry-run                        # Clean
composer lint:latte && composer precompile:templates
npm run typecheck && npm run lint && npm run lint:css
npm run build
npm run test:unit -- --coverage                   # Vitest TS coverage
npx size-limit                                    # Bundle budgets
```
