# Plan: Replay `16.x-rewrite` as a clean, tested branch

## Context

`origin/16.x` is legacy Piwigo: no Composer, no tests, no TypeScript, no PSR-4,
vendored Smarty/PHPMailer in `include/`, 947 PHP files, 333 JS files, 140 `.tpl`
templates. Zero CI, zero static analysis, zero test coverage.

`16.x-rewrite` is 2023 commits ahead: 894 PHP source files under `src/Piwigo/`
(53 namespaces), Latte templates, TypeScript + Vite, PHPStan L10 clean, 1122 unit
tests. But the commit history has an average of 27 era-switches per 100 commits —
CSS interleaved with Latte interleaved with WS refactoring interleaved with PHPStan
fixes. Core files like `config/container.php` (194 touches) and
`include/functions.inc.php` (97 touches) were modified continuously across the
entire timeline.

**Goal:** New branch `16.x-v2` from `origin/16.x`. Replay in clean phases.
Tests accompany each phase. Every commit green.

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

Traced from actual `use` statements in the final codebase. Arrows mean "requires"
(X → Y means X depends on Y being in place). Phase numbers in parentheses.

### Layer 0 — Tooling (P0)

No code dependencies. Establishes the test harness and CI gates that
everything else runs against.

```
P0 Pest + Vitest + Pint + PHPStan L0 + Rector + CI
 │
 └──→ all subsequent phases depend on P0 gates being green
```

### Layer 1 — PHP foundation (P1 → P2 → P3 → P4 → P5, strictly sequential)

Each layer depends on the previous. Cannot be parallelized.

```
P1 Composer + vendor migration
 │  WHY first: autoloader required for PSR-4; Smarty/PHPMailer must come
 │  from Composer before we can delete include/smarty/, include/phpmailer/
 │
 └─→ P2 Rector + PHPStan L0→L5
      │  WHY after P1: Rector needs Composer autoload to resolve classes.
      │  PHPStan needs typed signatures Rector introduces.
      │
      └─→ P3 PSR-4 namespace migration (62 classes → src/Piwigo/)
           │  WHY after P2: classes must already have strict_types + type
           │  declarations so PHPStan continues to pass after the move.
           │  PSR-4 autoload needs composer.json from P1.
           │
           └─→ P4 Kernel + DI container + middleware pipeline
                │  WHY after P3: Kernel.php imports Container.php (P4b),
                │  Container uses Paths (in src/ from P3). Middleware
                │  pipeline references Session, Auth, Routing — all need
                │  PSR-4 namespacing.
                │
                │  Internal order (cannot be reordered):
                │  P4a Kernel + boot skeleton
                │   └─→ P4b DI container (Container imports Paths)
                │        └─→ P4c Middleware pipeline (imports container services)
                │             └─→ P4d Entry point consolidation (routes through middleware)
                │
                └─→ P5 Config + DB layer + typed facades
                     │  WHY after P4: Config depends on Kernel (for service
                     │  resolution), Paths (for file locations). ConfigService
                     │  depends on DbConnection (DBAL). Typed facades
                     │  (PageState, CurrentUser, Lang) are wired via container
                     │  from P4b.
                     │
                     │  Internal order:
                     │  P5a Config (Config → Kernel, Paths, Enums)
                     │   ├─→ P5b DB layer (ConfigService → DbConnection;
                     │   │    repositories need Config for table prefix)
                     │   └─→ P5c Typed facades (PageState, CurrentUser, Lang
                     │        all need Config for defaults; Lang needs
                     │        LangService which needs Paths for .po files)
                     │
                     └──→ [P6, P7, P8 branch here — see Layer 2]
```

### Layer 2 — Service migration (P6 + P7, sequential; P5.5 parallel)

```
P5 ──→ P6 Service layer migration (include/ → src/)
 │      │  WHY after P5: every migrated service needs constructor-injected
 │      │  Config, Connection, repositories from P5. container.php grows
 │      │  with each domain (194 touches in the original). Functions like
 │      │  url_is_remote() become UrlService methods that need UrlGenerator
 │      │  which needs Paths + Config.
 │      │
 │      │  Internal dependency order (by service graph):
 │      │
 │      │  P6a — include/ domains (each deletes its source file):
 │      │   Tier 1 (no service deps, only Config/DB):
 │      │    ├ URL (UrlService → Config, Paths)
 │      │    ├ Cookie (CookieService → Config)
 │      │    ├ Session (SessionService → Config, SessionRepository)
 │      │    └ HTML (HtmlService → Config, Lang)
 │      │   Tier 2 (depend on Tier 1):
 │      │    ├ Filter (FilterMiddleware → CategoryService, Config, Session)
 │      │    ├ User (UserService → AuthService, PermissionService → Config, SessionService, DB)
 │      │    ├ Tag (TagService → TagRepository → DB)
 │      │    ├ Comment (CommentService → Config, UserService)
 │      │    └ Rate (RateService → Config, DB)
 │      │   Tier 3 (depend on Tier 1+2):
 │      │    ├ Mail (MailService → Config, UserService, Lang, HtmlService)
 │      │    ├ Category (CategoryService → CategoryRepository, Config, PermissionService)
 │      │    ├ Search (SearchService → CategoryService, TagService, Config, DB)
 │      │    ├ Image (ImageRepository, DerivativePipeline → Config, Paths, StorageRegistry)
 │      │    ├ Calendar (CalendarService → CategoryService, ImageRepository)
 │      │    ├ Notification (NotificationService → MailService, UserService)
 │      │    └ Metadata (MetadataService → Config, ImageRepository)
 │      │   Tier 4 (depend on Tier 1-3):
 │      │    ├ Page (PageHeaderRenderer → Config, Lang, Template, UrlGenerator,
 │      │    │       PermissionService, DeviceDetectionService)
 │      │    ├ Page (PageTailRenderer → Config, Template, DebugCollector)
 │      │    ├ Menu (MenubarRenderer → Config, CategoryService, TagService,
 │      │    │       UrlGenerator, PermissionService, SearchFilterRenderer)
 │      │    └ Plugin (PluginService → Config, Paths, DB)
 │      │
 │      │  P6b — admin/ migration (each deletes its source file):
 │      │   Depends on P6a Tier 1-4 because admin services import from
 │      │   every domain (BatchManagerController alone imports from
 │      │   Activity, Admin, Cache, Category, Comment, Config, Core,
 │      │   Event, Image, Tag, Users — 15+ namespaces).
 │      │   Internal order: Upload → Albums → Users → Config → Extensions →
 │      │   BatchManager → History → Maintenance → Misc
 │      │
 │      │  P6c — ServiceLocator kill + $GLOBALS cleanup
 │      │   WHY last in P6: can only delete ServiceLocator after ALL
 │      │   services use constructor injection. Can only remove $GLOBALS
 │      │   bridges after all callers use typed facades.
 │      │
 │      │  P6d — Delete include/ and admin/ directories
 │      │   WHY last: proves migration is complete
 │      │
 │      └─→ P7 Constants retirement
 │           WHY after P6: Paths value object replaces PHPWG_ROOT_PATH
 │           across 195 reads in 72 files — those files must already be
 │           in src/ (from P6). PemUrlResolver replaces PEM_URL define —
 │           needs DI container. Tables class replaces PREFIX_TABLE —
 │           needs Config.
 │
 ├──→ P5.5 Frontend tooling (PARALLEL to P6/P7)
 │     │  WHY parallel: touches themes/*/js/ files, NOT src/Piwigo/.
 │     │  No PHP service dependencies. Only needs P3 (PSR-4) done
 │     │  because vite.config.ts references the project structure.
 │     │
 │     │  Internal order (strictly sequential):
 │     │  P5.5a Vite + TypeScript setup
 │     │   │  WHY first: TS compiler needed before any .ts file exists
 │     │   └─→ P5.5b ESLint + Prettier + Stylelint
 │     │        │  WHY after 5.5a: ESLint config uses typescript-eslint
 │     │        │  which needs tsconfig.json from 5.5a
 │     │        └─→ P5.5c Inline JS extraction (footer_script → modules)
 │     │             │  WHY after 5.5b: extracted .ts modules must pass
 │     │             │  ESLint. Also needs Vite entries from 5.5a.
 │     │             └─→ P5.5d any reduction (478 → 0)
 │     │                  WHY after 5.5c: window.* bridge elimination in
 │     │                  5.5c creates the getPageData<T> typed helper
 │     │                  that replaces (window as any).* casts
 │     │
 │     │  SYNC POINT with PHP track:
 │     │  P5.5c needs template files to still have {footer_script} blocks
 │     │  to extract from. If Latte migration (P10) runs first, those
 │     │  blocks are already gone. So P5.5c MUST complete before P10.
 │     │
 │     └──→ [feeds into P9, P10, P11]
```

### Layer 3 — Templates + Assets + CSS (P8-P11, mostly sequential)

```
P6 + P7 ──→ P8 WS typed endpoints
 │            │  WHY after P6: WS endpoints (95 handlers) import from
 │            │  nearly every domain service. PwgServer depends on Config,
 │            │  Session, PermissionService, UrlService, HtmlService,
 │            │  EventDispatcher. WsMethodRegistrar depends on Config,
 │            │  ImageStdParams, CurrentUser, PermissionService.
 │            │  Also: legacy include/ws_functions/*.php must be deleted
 │            │  in P6 before typed handlers can take over.
 │            │
 │            └──→ [feeds into P12]
 │
P5.5 + P6 ──→ P9 CSS modernization
 │              │  WHY after P5.5: Stylelint config from P5.5b needed.
 │              │  Vite entries from P5.5a needed for CSS imports.
 │              │  WHY after P6: some CSS files reference template structure
 │              │  (class names from renderers). Service layer must be stable
 │              │  so page structure doesn't shift under the CSS.
 │              │
 │              │  Internal order:
 │              │  1. Delete orphans (no deps)
 │              │  2. Split monoliths (no deps, just file reorganization)
 │              │  3─4. Design tokens (depends on split files existing)
 │              │  5─7. Skin/child theme refactor (depends on tokens existing)
 │              │  8. !important elimination (depends on tokens + specificity
 │              │     from steps 3-7 resolving cascade battles)
 │              │  9. Inline <style> extraction (depends on split target files
 │              │     existing from step 2)
 │              │  10. Search CSS collapse (independent, can go anywhere)
 │              │
 │              └──→ [feeds into P10]
 │
P5.5c + P6 + P9 ──→ P10 Template migration (Smarty → Latte)
 │                    │  WHY after P5.5c: Latte templates call {viteEntry()}
 │                    │  and {cssLink()} — Vite entries must exist.
 │                    │  {footer_script} blocks must already be extracted to
 │                    │  .ts modules (P5.5c) before converting .tpl → .latte,
 │                    │  otherwise the conversion loses the JS.
 │                    │  WHY after P6: Latte PiwigoExtension.php depends on
 │                    │  ViteManifest, UrlGenerator, PermissionService, Lang,
 │                    │  Translator, DeviceDetectionService — all from P5/P6.
 │                    │  Template.php depends on Config, Kernel, Paths, Lang,
 │                    │  HtmlService, UrlGenerator.
 │                    │  WHY after P9: Latte {cssLink("path")} calls reference
 │                    │  the split CSS file paths (e.g. css/pages/albums.css)
 │                    │  that only exist after P9 step 2.
 │                    │
 │                    │  Internal order:
 │                    │  1. Latte engine + PiwigoExtension wiring
 │                    │  2. Smarty → Latte converter tool
 │                    │  3. Admin templates (80 files, largest batch)
 │                    │  4. Frontend templates (35 files)
 │                    │  5. Standard pages (18 files)
 │                    │  6. Precompile pipeline + CI gate
 │                    │  7. Delete Smarty dependency
 │                    │
 │                    └──→ [feeds into P11, P12]
 │
P10 ──→ P11 Asset pipeline (ViteManifest)
         │  WHY after P10: ViteManifest.php is called from PiwigoExtension
         │  which is wired during Latte migration. The {=viteEntry('id')}
         │  calls in .latte files (P10) are what trigger ViteManifest
         │  lookups. Legacy {combine_script}/{combine_css} must be gone
         │  (converted in P10) before the old asset pipeline can be deleted.
         │
         └──→ [P11 completion means asset pipeline is fully modern]
```

### Layer 4 — Contracts + security + types (P12-P15, mostly sequential)

```
P8 + P10 + P11 ──→ P12 Plugin / Theme / WS contracts (§1.4)
 │                   │  WHY after P8: typed WS endpoints must exist for
 │                   │  MethodDefinition registration. OpenAPI SpecBuilder
 │                   │  reflects on typed endpoint classes.
 │                   │  WHY after P10: PluginRegistry.php depends on
 │                   │  LangService (for plugin language loading).
 │                   │  ThemeRegistry depends on event system for
 │                   │  ThemeChanged event. Template engine must be Latte
 │                   │  (plugins register Latte template dirs, not Smarty).
 │                   │  WHY after P11: plugins need ViteManifest for their
 │                   │  own asset loading.
 │                   │
 │                   │  Internal order:
 │                   │  1. PSR-14 event classes (160, standalone data classes)
 │                   │  2. EventDispatcher integration (needs events from step 1)
 │                   │  3. PluginInterface + PluginRegistry (needs events + LangService)
 │                   │  4. ThemeInterface + ThemeRegistry (needs events)
 │                   │  5. Bundled plugin migration (needs PluginInterface from step 3)
 │                   │  6. OpenAPI SpecBuilder (needs MethodDefinition from P8)
 │                   │  7. Delete legacy event functions (all callers migrated)
 │                   │
 │                   └──→ P13, P14, P22
 │
P4c + P6c ──→ P13 Security hardening (§1.5)
 │              │  WHY after P4c: SecurityHeadersMiddleware is part of the
 │              │  PSR-15 pipeline (P4c). Middleware ordering matters:
 │              │  security headers wrap the full response.
 │              │  WHY after P6c: LoginThrottle depends on
 │              │  UserFailedLoginRepository (P6a), AuthService (P6a),
 │              │  symfony/rate-limiter. CsrfMiddleware depends on
 │              │  CsrfService which needs Session (P6a).
 │              │  NOTE: P13 CAN run before P10/P11/P12 if needed — it
 │              │  only depends on the middleware pipeline and auth services,
 │              │  not templates or plugins. Placed here for logical grouping.
 │              │
 │              └──→ [independent, no downstream deps]
 │
P6 + P8 ──→ P14 Type correctness (§1.6 + done portion of §1.7)
 │            │  WHY after P6: Config schema metadata (sensitive, required,
 │            │  description) needs the Config service stable. RequestCache
 │            │  generics need the cache system. VOs and Enums slot into
 │            │  existing service signatures.
 │            │  WHY after P8: WS Params DTOs (83 of them) already exist
 │            │  from P8. Entity/Projection patterns reference repository
 │            │  return types from P6.
 │            │
 │            └──→ P16 (deep mixed elimination builds on P14 foundation)
 │
P14 ──→ P15 Coverage + mutation + quality gates
          WHY after P14: coverage and mutation testing are meaningful
          only after the full codebase exists. Measuring coverage on a
          half-migrated codebase produces misleading baselines.
          Bundle size budgets need the final Vite build (P11).
          A11y testing needs the final Latte templates (P10) + CSS (P9).
```

### Layer 5 — New work (P16-P22)

```
P14 + P15 ──→ P16 Deep mixed elimination (§1.7 remaining)
 │              │  WHY after P14: builds on the VO/Entity/Enum foundation.
 │              │  WHY after P15: coverage gates catch regressions in the
 │              │  large-scale type changes (88 Result DTOs, 249 repo
 │              │  returns, 758 $_POST/$_GET reads).
 │              │
 │              │  Internal dependency order:
 │              │  1. SessionStore rename (trivial, unblocks F5-c gate)
 │              │  2. Remaining VOs + Enums (4+6, unblocks downstream typing)
 │              │  3. WsResult DTOs (88 endpoints, needs VO types from step 2)
 │              │  4. Web/admin Request DTOs (30-50, same pattern as WsParams)
 │              │  5. Container Factory classes (118 closures → typed factories)
 │              │  6. Bare-array repo returns (249 methods, needs Entity/Projection
 │              │     types from P14 step 4)
 │              │  7. SearchRules deep adoption (SearchService + SearchFilterRenderer,
 │              │     needs repo types from step 6)
 │              │  8. is_array(.* ?? null) elimination (154 instances, mechanical
 │              │     once types from steps 3-7 propagate)
 │              │  9. Typed error responses (PwgError → enum + i18n key)
 │              │
 │              └──→ P17, P18 can start once steps 1-3 are done
 │
P12 ──→ P17 Plugins/extensions god-class decomposition
 │        │  WHY after P12: needs PluginRegistry + ThemeRegistry stable.
 │        │  The decomposition splits Plugins.php/Themes.php/Languages.php
 │        │  which are tightly coupled to the extension lifecycle from P12.
 │        │  The 10 consumers (Updates, InstallService, TelemetryService,
 │        │  ExtensionsController, etc.) reach into public mutable arrays
 │        │  on these classes — must be encapsulated.
 │        │
 │        └──→ [independent, no downstream deps]
 │
P16 (steps 1-3) ──→ P18 OpenAPI #[ApiMethod] decoration
 │                    │  WHY after P16 steps 1-3: SpecBuilder reflection
 │                    │  needs WsResult DTOs (from P16 step 3) to populate
 │                    │  responseClass in the attribute. Without Result types,
 │                    │  the attribute has nothing to point at.
 │                    │
 │                    └──→ [independent, no downstream deps]
 │
P9 + P10 + P11 ──→ P19 Tailwind CSS v4 (admin panel)
 │                   │  WHY after P9: existing --admin-* tokens (93 of them
 │                   │  from P9 step 4) are referenced via @theme inline.
 │                   │  Split CSS files from P9 step 2 are what gets replaced.
 │                   │  WHY after P10: Tailwind scans .latte files for class
 │                   │  names — templates must be in final Latte form.
 │                   │  WHY after P11: Tailwind CSS is imported from TS entries
 │                   │  via Vite — asset pipeline must be final.
 │                   │  NOTE: This is a REWRITE of admin CSS, not a migration.
 │                   │  P9's hand-written CSS gets replaced by Tailwind utilities.
 │                   │  P9 is still required because its token system becomes the
 │                   │  Tailwind @theme foundation.
 │                   │
 │                   └──→ [independent, no downstream deps]
 │
P11 ──→ P20 Vendored frontend libs
 │        WHY after P11: @fontsource packages are loaded via Vite imports.
 │        Asset pipeline must be in place.
 │        NOTE: scope is just webfonts now. Low effort, low risk.
 │
P12 + P13 + all tests green ──→ P21 Repository restructure (STRUCTURE-PLAN)
 │                                │  WHY LAST among structural changes:
 │                                │  - Moves EVERY directory (src/, themes/,
 │                                │    language/, install/, tests/, tools/)
 │                                │  - Updates EVERY config file (composer.json,
 │                                │    phpstan.neon, vite.config.ts, phpunit.xml.dist,
 │                                │    rector.php, pint.json, eslint.config.ts,
 │                                │    playwright.config.ts)
 │                                │  - Needs ALL tests passing to catch breakage
 │                                │  - Needs plugin system (P12) stable because
 │                                │    theme discovery paths change
 │                                │  - Needs security (P13) because web-root
 │                                │    isolation is a security feature
 │                                │
 │                                │  Internal order (each step leaves tree green):
 │                                │  Steps 1-4: var/ migration (runtime data)
 │                                │  Step 5-7: dev files + docs consolidation
 │                                │  Step 8: namespace rename (PluginConfig)
 │                                │  Step 9: themes/ → resources/ (BIGGEST step,
 │                                │    touches vite.config.ts, Template.php,
 │                                │    ViteManifest.php, all themeconf.inc.php)
 │                                │  Steps 10-12: moves (types, SQL, language)
 │                                │  Step 13: public shim + setup script
 │                                │  Step 14: meta files
 │                                │
 │                                └──→ P22
 │
P12 + P21 ──→ P22 Bundled extensions migration
               WHY after P12: needs PluginInterface contracts.
               WHY after P21: if repo restructure moves plugins/ into a
               new location, migration must target the final paths.
               NOTE: can run before P21 if we accept re-pathing later.
```

### Summary: critical path (longest sequential chain)

```
P0 → P1 → P2 → P3 → P4 → P5 → P6 → P7 → P8 → P10 → P11 → P12 → P21
                                  ↑
                            P5.5 (parallel)──→ P9 (parallel to P8)
```

Minimum sequential depth: **13 phases** on the critical path.
Parallelizable: P5.5/P9 alongside P6-P8. P13 alongside P10-P12.
P17/P18/P19/P20 are independent leaves once their prerequisites are met.

---

## Phase breakdown

### P0 — Tooling foundation (on bare `origin/16.x`)

**Creates:** `composer.json`, `package.json`, Pest, Pest Browser, CI, Pint, PHPStan,
Rector, Vitest, `.editorconfig`, `.gitignore` extensions.

**The rule from here forward:** no code change lands without an accompanying test
or being gated by a static analysis tool.

#### Commits:

1. **Composer init + Pest + Pest plugins**
   - `composer.json`: PHP 8.5, `pestphp/pest` ^4, `pest-plugin-arch` ^4,
     `pest-plugin-mutate` ^4, `pest-plugin-type-coverage` ^4,
     `pest-plugin-browser` ^4
   - `phpunit.xml.dist` (Pest uses it)
   - `tests/Pest.php` (Pest config)
   - `tests/bootstrap.php`
   - `tests/Unit/SmokeTest.php`
   - `tests/Arch/StructuralTest.php` — first arch rules (will grow per phase)
   - `.gitignore` for `vendor/`, `.phpunit.cache/`
   - Gate: `vendor/bin/pest --testsuite Unit` green

2. **Node + Vitest + Pest Browser deps**
   - `package.json`: `vitest`, `@vitest/coverage-v8`, `happy-dom`
   - `vitest.config.ts`
   - `tests/js/smoke.test.ts`
   - Gate: `npm run test:unit` green

3. **Pest Browser E2E setup**
   - `tests/Browser/InstallTest.php` — fresh install flow
   - `tests/Browser/SmokeGalleryTest.php` — gallery home loads
   - `tests/Browser/SmokeAdminTest.php` — admin dashboard loads
   - `.env.test` template
   - Gate: `vendor/bin/pest --testsuite Browser` green (against PHP built-in server)

4. **Pint + Rector + PHPStan (level 0)**
   - `pint.json`, `rector.php`, `phpstan.neon`
   - `tools/phpstan-bootstrap.php`
   - Gate: `vendor/bin/pint --test`, `vendor/bin/phpstan analyse`, `vendor/bin/rector --dry-run`

5. **GitHub Actions CI**
   - `.github/workflows/ci.yml`: pest-unit, pest-browser, pint, phpstan, rector, vitest,
     audit
   - `.editorconfig`
   - Gate: CI green

6. **Dev fixtures**
   - `dev/fixtures/piwigo-16.x.sql` — baseline DB snapshot
   - `dev/fixtures/README.md`

**Test count after P0:** ~5 PHP unit, 3 browser, 1 TS unit, arch rules. All gates green.

---

### P1 — Composer + vendored lib migration

**Replays:** Commits that moved vendored PHP libs to Composer packages.

**What happens:**
- Remove vendored `include/smarty/`, `include/phpmailer/`, `include/emogrifier.class.php`,
  `include/jshrink.class.php`, `include/minify/`, `include/passwordhash.class.php`,
  `include/feedcreator.class.php`, `include/phpqrcode.php`, `include/mdetect.php`,
  `include/base32.class.php`
- `composer require` their packagist equivalents
- Add `require 'vendor/autoload.php'` in `include/common.inc.php`
- Remove `include/php_compat/` shims
- Remove `include/dblayer/functions_mysql.inc.php` (MySQL4 layer)
- Migrate password hashing to `password_hash()` / bcrypt

**Tests alongside:**
- Browser E2E: gallery + admin still load after each swap
- Unit: password hashing round-trip test

**Arch test added:** no `include/smarty`, `include/phpmailer` etc. directories exist

**Gate:** Pest unit + browser green. Pint clean. PHPStan L0 clean.

---

### P2 — Rector + PHPStan level push

**Replays:** Phase 1 steps 3-6 (Rector PHP 8.0-8.5), Phase 7 (PHPStan L0 → L5).

**What happens:**
- Apply Rector PHP 8.0 set (match expressions, named args, property promotion, etc.)
- Apply Rector PHP 8.1-8.5 sets (enums, readonly, fibers typing)
- Pint format pass
- Add `declare(strict_types=1)` to all first-party files
- Push PHPStan from L0 → L5 incrementally (fix errors at each level)

**Tests alongside:**
- Browser E2E: still loads after each Rector pass
- Each PHPStan level is itself a test — errors must hit zero before proceeding

**Gate:** PHPStan L5 clean. Rector dry-run clean. Browser green.

---

### P3 — PSR-4 namespace migration

**Replays:** Phase 3 (62 class moves to `src/Piwigo/`).

**What happens:**
- Create `src/Piwigo/` directory tree
- PSR-4 autoload config in `composer.json`
- Move all 62 first-party classes from `include/*.class.php` → namespaced classes
- Update all `require`/`include` references
- Procedural files (`include/functions_*.inc.php`) stay for now — they can't be
  autoloaded until they're converted to classes

**Tests alongside:**
- Unit test for each moved class that has testable logic
- Browser E2E: gallery + admin load
- Arch test: all classes in `src/Piwigo/` have `declare(strict_types=1)` and a namespace

**Gate:** `composer dump-autoload --strict-psr`. PHPStan clean. Browser green.

---

### P4 — Core kernel + DI + boot sequence

**Replays:** Phase 4 (Kernel, Container, CommonBootstrap, entry point consolidation).

**What happens, in sub-phases:**

#### P4a — Kernel + boot skeleton
- `src/Piwigo/Core/Kernel.php`
- `src/Piwigo/Bootstrap/CommonBootstrap.php` (wraps `common.inc.php` logic)
- `src/Piwigo/Http/RequestFactory.php`, `ResponseEmitter.php`
- `index.php` becomes single entry point
- **Tests:** `KernelBootTest.php`, `ContainerSmokeTest.php`

#### P4b — DI container
- `src/Piwigo/Bootstrap/Container.php` (PHP-DI wiring)
- `config/container.php` — initial service definitions
- **Tests:** `ContainerDefinitionsTest.php` — every service resolves

#### P4c — PSR-15 middleware pipeline
- `src/Piwigo/Http/Middleware/` — all 8 middleware classes
- `src/Piwigo/Http/MiddlewarePipeline.php`
- `config/routes.php`
- **Tests:** Middleware unit tests. `FastPathHeadersTest.php`.

#### P4d — Entry point consolidation
- Legacy entry points (`i.php`, `action.php`, `ws.php`, etc.) → routed through
  `index.php`
- **Tests:** Browser E2E: every legacy URL still works. Integration: fast-path
  headers correct.

**Gate:** PHPStan raise to L6-7. All services resolve. Browser green.

---

### P5 — Config + DB + typed facades

**Replays:** Phase 4 Wave B/C (Config), Phase 5 steps 5-14 (Config schema), DB tasks.

**What happens, in sub-phases:**

#### P5a — Config service
- `src/Piwigo/Config/Config.php` — typed facade with SCHEMA
- `src/Piwigo/Config/ConfigService.php` — DB-backed CRUD
- `src/Piwigo/Config/ConfigLoader.php` — `.env` loading
- `tools/build-config-accessors.php` — code generator
- Replace `$conf['key']` reads with typed accessors (the largest single sweep)
- **Tests:** `ConfigTest.php` (every SCHEMA entry), `ConfigRepositoryTest.php`,
  accessor sync check

#### P5b — Database layer
- Doctrine DBAL `Connection` factory
- `src/Piwigo/Db/Dml.php` — query helpers
- First repositories: `ConfigRepository`, `SessionRepository`
- **Tests:** Repository integration tests (need MySQL service)

#### P5c — Typed facades
- `src/Piwigo/Core/PageState.php`
- `src/Piwigo/Users/CurrentUser.php`
- `src/Piwigo/Lang/Lang.php`, `LangService.php`, `Translator.php`
- Reference bridges to `$GLOBALS` (these are TEMPORARY — tracked for later removal)
- **Tests:** `PageStateTest.php`, `LangTest.php`, `InstallSentinelTest.php`

**Gate:** PHPStan raise to L8+. Config accessor sync CI. All tests green.

---

### P5.5 — Frontend tooling (parallel track begins)

**Replays:** Phase 5 (JS → TS), ESLint/Prettier/Stylelint setup.

**Can run in parallel with P6-P7 because it touches different files** (themes/js vs
src/Piwigo).

#### P5.5a — Vite + TypeScript
- `vite.config.ts` with entry points
- `tsconfig.json`
- Convert all 38 authored JS files to `.ts`
- **Tests:** `npm run build`, `npm run typecheck`

#### P5.5b — ESLint + Prettier + Stylelint
- `eslint.config.ts`, `.prettierrc.json`, `.stylelintrc.json`
- Fix all lint errors
- CI jobs: `npm run lint`, `npm run format:check`, `npm run lint:css`
- **Tests:** CI gates (lint IS the test)

#### P5.5c — Inline JS extraction
- `{footer_script}` blocks → `.ts` modules
- `data-*` attribute bridges
- `getPageData<T>()` helper
- **Tests:** Vitest: `getPageData.test.ts`. Browser E2E: all admin pages load
  without JS errors.

#### P5.5d — `any` reduction (478 → 0)
- Window globals declaration files
- Per-file `any` elimination
- **Tests:** ESLint `no-explicit-any: error` enforced. `npm run typecheck` clean.

**Gate:** TS clean, ESLint clean, Stylelint clean, Vite builds, E2E green.

---

### P6 — Service layer migration

**Replays:** The massive incremental migration of procedural code to typed services.
This is where `include/functions.inc.php` got its 97 modifications and
`config/container.php` got its 194.

**Strategy:** Instead of 97 incremental touches, group by domain:

#### P6a — Include → src migration (by domain)

Each domain is one commit group. The reference implementation on `16.x-rewrite` shows
the final state of each file.

| Domain | include/ files consumed | src/Piwigo/ classes created | Tests |
|--------|------------------------|-----------------------------|-------|
| URL | `functions_url.inc.php` | `Url/UrlService.php`, `Url/UrlGenerator.php` | URL generation unit tests |
| User | `functions_user.inc.php`, `user.inc.php` | `Users/UserService.php`, `Users/UserRepository.php` | User CRUD integration tests |
| Category | `functions_category.inc.php`, `category_*.inc.php` | `Category/CategoryRepository.php`, `Category/*Renderer.php` | Category tree unit tests |
| Image | `functions_picture.inc.php`, `derivative*.inc.php` | `Image/ImageRepository.php`, `Image/DerivativePipeline.php` | Image metadata unit tests |
| Search | `functions_search.inc.php`, `search_filters.inc.php` | `Search/SearchService.php`, `Search/SearchFilterRenderer.php` | Search query unit tests |
| Tag | `functions_tag.inc.php`, `selected_tags.inc.php` | `Tag/TagRepository.php`, `Tag/SelectedTagsRenderer.php` | Tag CRUD integration tests |
| Comment | `functions_comment.inc.php`, `picture_comment.inc.php` | `Comment/CommentRepository.php`, `Comment/*Renderer.php` | Comment unit tests |
| Mail | `functions_mail.inc.php` | `Mail/MailService.php` | Mail construction unit test |
| Session | `functions_session.inc.php`, `pwgsession*.class.php` | `Session/PwgSession.php`, `Session/SessionRepository.php` | Session integration tests |
| Filter | `filter.inc.php`, `functions_filter.inc.php` | `Filter/FilterMiddleware.php` | Filter unit tests |
| HTML | `functions_html.inc.php` | `Html/HtmlService.php` | HTML helper unit tests |
| Rate | `functions_rate.inc.php`, `picture_rate.inc.php` | `Rate/RateService.php`, `Rate/*Renderer.php` | Rate unit tests |
| Notification | `functions_notification.inc.php` | `Notification/*Service.php` | Notification unit tests |
| Calendar | `functions_calendar.inc.php`, `calendar_*.class.php` | `Calendar/*Calendar.php` | Calendar rendering unit tests |
| Cookie | `functions_cookie.inc.php` | `Session/CookieService.php` | Cookie unit tests |
| Metadata | `functions_metadata.inc.php` | `Metadata/MetadataService.php` | Metadata unit tests |
| Page | `page_header.php`, `page_tail.php`, `no_photo_yet.inc.php`, `menubar.inc.php` | `Page/*Renderer.php`, `Menu/MenubarRenderer.php` | Renderer unit tests |
| Plugin | `functions_plugins.inc.php` | `Plugin/PluginService.php` | Plugin loading tests |

**After each domain migration:** that domain's `include/` files are DELETED. No zombie
delegation files. The arch test enforces: `include/<file>.php` does not exist.

#### P6b — Admin migration

| Admin domain | admin/ files consumed | src/Piwigo/Admin/ classes created | Tests |
|-------------|----------------------|----------------------------------|-------|
| Upload | `photos_add_direct*.php` | `Upload/UploadService.php`, `Upload/DirectPreparer.php` | Upload integration test |
| BatchManager | `batch_manager*.php` | `Controller/Admin/BatchManagerController.php` | Batch operation tests |
| Albums | `albums.php`, `cat_*.php` | `Controller/Admin/AlbumController.php`, `Album/*Service.php` | Album CRUD integration |
| Users | `user_list.php`, `user_perm.php` | `Controller/Admin/UserController.php` | User management tests |
| Configuration | `configuration.php` | `Controller/Admin/ConfigurationController.php`, `Config/*Processor.php` | Config round-trip tests |
| Extensions | `plugins*.php`, `themes*.php`, `languages*.php` | `Controller/Admin/ExtensionsController.php` | Extension listing tests |
| Maintenance | `maintenance*.php` | `Controller/Admin/MaintenanceController.php` | Maintenance action tests |
| History | `history.php` | `Controller/Admin/HistoryController.php` | History query tests |
| Misc | remaining admin pages | respective controllers | Smoke tests |

#### P6c — ServiceLocator elimination + `$GLOBALS` cleanup

- Delete `ServiceLocator.php` — all callers already use constructor injection from P6a/b
- Retire `$GLOBALS['conf']`, `$GLOBALS['user']`, `$GLOBALS['page']`, `$GLOBALS['lang']`
  reference bridges
- **Tests:** Arch tests: no `ServiceLocator` in src/. No `$GLOBALS` in src/ (except
  explicitly allowed files, which should be zero by this point).

#### P6d — Root entry point deletion

- Delete `about.php`, `comments.php`, `search.php`, `picture.php`, `tags.php`,
  `identification.php`, `register.php`, `password.php`, etc.
- All routed through `index.php` → controllers
- Delete `include/` directory entirely
- Delete `admin/` directory entirely
- **Tests:** Browser E2E for every major route. Integration tests for every controller.

**Gate:** PHPStan L10 clean (raise during this phase). Zero `include/` files. Zero
`admin/` files. Browser E2E green for all routes. Every namespace has tests.

---

### P7 — Constants retirement

**Replays:** §1.10 (`PHPWG_ROOT_PATH` → `Paths`) and §1.11 (all `define()` calls).

- `src/Piwigo/Core/Paths.php` value object
- Thread through DI
- Replace 195 reads across 72 files
- Replace all remaining `define()` constants with typed alternatives
- **Tests:** `PathsTest.php`, `PemUrlResolverTest.php`.
  Arch test: no `define()` in src/, no `PHPWG_ROOT_PATH` in src/.

**Gate:** PHPStan L10. Arch tests green.

---

### P8 — WS typed endpoints

**Replays:** WS refactoring commits (concentrated around commits 1580-1680).

- `src/Piwigo/Ws/PwgServer.php` — typed server
- `MethodDefinition`, `ParamDefinition` value objects
- `WsMethodRegistrar.php` — register all 95 endpoints
- `src/Piwigo/Ws/Method/*Endpoints.php` — 9 endpoint classes
- **Tests:** `WsApiTest.php` — integration test hitting all 95 endpoints with at
  least one smoke call each. Parameter validation tests for typed params.

**Gate:** All 95 endpoints respond. Integration tests green.

---

### P9 — CSS modernization

**Replays:** The concentrated CSS work (especially commits 1800-1980).

**Executed as one coherent pass** (it was mostly concentrated anyway):

1. Delete orphan CSS (`fix-khtml.css`, `fix-ie5-ie6.css`, `fix-ie7.css`)
2. Split `themes/admin/_base/theme.css` (9635 lines → 51 files)
3. Split `themes/_base/theme.css` (1305 lines → 10 files)
4. Design tokens — CSS custom properties for colors, spacing, font-size, radius, z-index
5. Admin token system (93 `--admin-*` tokens)
6. Frontend tokens (42 tokens)
7. Standard pages skin refactor (skins → `:root {}` overrides)
8. Admin child theme slim-down (dark −60%, light −70%)
9. `!important` elimination (689 → 51)
10. Inline `<style>` extraction → `css/pages/*.css`
11. Search CSS collapse

**Tests per sub-step:**
- Stylelint clean after each commit
- Browser E2E: screenshot all major routes before and after (visual regression)
- Arch assertion: `!important` count matches expectation
- `wc -l themes/admin/_base/theme.css` → 12

**Gate:** Stylelint 0 errors. Theme files at target sizes. E2E green.

---

### P10 — Template migration (Smarty → Latte)

**Replays:** §1.2 (waves 1+2+3, 133 template conversions).

**Depends on:** P6 (services must be stable — templates call service methods), P5.5
(Vite entries must exist for `{viteEntry()}` calls), P9 (CSS files must be in final
locations for `{cssLink()}`).

1. Add Latte engine to Composer
2. Wire `LatteEngine` factory in `Template.php`
3. Build Smarty → Latte converter tool
4. Convert admin templates (largest set, ~80 files)
5. Convert frontend templates (~35 files)
6. Convert standard pages templates (~18 files)
7. Precompile pipeline: `composer precompile:templates` + CI gate
8. Delete Smarty dependency

**Tests per wave:**
- `composer lint:latte` — syntax validation
- `composer precompile:templates` — compile-time errors
- Browser E2E: every route renders correctly
- Visual regression screenshots before/after each wave

**Gate:** Zero `.tpl` files. Latte lint + precompile clean. All E2E green.

---

### P11 — Asset pipeline (ViteManifest)

**Replays:** ViteManifest service + template helper migration.

1. `src/Piwigo/Asset/ViteManifest.php`
2. `viteEntry()` and `cssLink()` Latte helpers
3. Migrate all templates to use `{viteEntry()}` / `{cssLink()}`
4. Delete legacy asset pipeline (`CombineService`, etc.)

**Tests:** `ViteManifestTest.php`, `AssetServiceTest.php`. Browser E2E: all pages
load hashed `dist/` URLs.

**Gate:** All assets served from `dist/assets/` with content hashes.

---

### P12 — Plugin / Theme / WS contracts (§1.4)

**Replays:** The §1.4 work (concentrated around commits 1320-1400).

**Depends on:** P6 (service layer), P8 (WS endpoints), P10 (templates).

1. PSR-14 event system — ~160 typed event classes under `src/Piwigo/Event/`
2. `PluginInterface` + `PluginRegistry` + `PluginMigrationRunner`
3. Bundled plugin migration (5 plugins)
4. `ThemeInterface` + `ThemeRegistry`
5. OpenAPI 3.1 spec generation (`SpecBuilder`)
6. Delete legacy `add_event_handler()` / `trigger_notify()` / `trigger_change()`

**Tests:**
- `PluginRegistryTest.php`, `PluginSchemaTest.php`, `PluginRegistryMigrationsTest.php`,
  `PluginRegistryLanguagesTest.php`
- `EventSymmetryTest.php` — every event has a matching dispatch site
- Arch test: all event classes are readonly
- OpenAPI spec validation (cebe/redocly)
- Browser E2E: plugin load/unload works

**Gate:** All 160 events dispatch. Plugin lifecycle works. OpenAPI validates.

---

### P13 — Security hardening (§1.5)

**Replays:** §1.5 waves A-D.

1. Session cookie hardening (`SameSite=Lax`, `HttpOnly`, `Secure`)
2. Rate limiting + login throttle (`LoginThrottle`, `symfony/rate-limiter`)
3. Security headers middleware (CSP, XFO, XCTO, Referrer-Policy, Permissions-Policy, HSTS)
4. `docs/SECURITY.md`

**Tests:**
- Integration: cookie attribute assertions, rate limit engagement/reset,
  header presence on responses
- Browser E2E: login/logout cookie behavior (existing spec 08 equivalent)
- `composer lint:no-inline-scripts` CI guard

**Gate:** All security headers present. Rate limit tests pass. E2E green.

---

### P14 — Type correctness (§1.6 + §1.7)

**Replays:** Config schema enhancements, RequestCache generics, mixed elimination.

1. Config: `sensitive` + `dumpForLog`, `required` + `MissingRequiredConfigException`,
   `description` on all 277 SCHEMA entries
2. `RequestCache` with `@template T`
3. ValueObjects + Enums (~25 VOs, ~10 Enums)
4. Entity + Projection patterns (7 Entity, 73 projection shapes)
5. Session VO
6. Request DTOs (typed wrappers for `$_POST`/`$_GET`)
7. Psalm as secondary gate

**Tests:** Each VO/Entity/DTO gets a construction + validation unit test.

**Gate:** PHPStan L10. Psalm level 2 as CI gate.

---

### P15 — Coverage + mutation + bundle budgets

**After all features are in:** establish quality baselines.

1. Coverage measurement: `--coverage-clover` in CI, filter on `src/Piwigo/`
2. Coverage ratchet: committed baseline, CI fails on regression
3. Mutation testing: `vendor/bin/pest --mutate` targeting high-value services
4. Type coverage: `pest --type-coverage --min=95`
5. Bundle size budgets: `size-limit` per Vite entry point
6. A11y: Pest Browser `assertNoAccessibilityIssues()` or equivalent axe-core
   integration, WCAG 2.1 AA gate on all page-level tests

**Gate:** All quality gates green. Baselines committed.

---

### P16 — §1.7 remaining: deep mixed elimination

**NEW work not yet done on `16.x-rewrite`.** Currently 1796 psalm --show-info issues,
target <50.

1. **WsResult DTOs** — 88 of 95 endpoints still return `array<string, mixed>`.
   Tighten `WsAction` to `__invoke(...): WsResult|PwgError`. Add `*Result.php` per
   endpoint.
   - **Tests:** Each Result DTO gets a `toArray()` round-trip test.

2. **Web/admin Request DTOs** — 758 raw `$_POST`/`$_GET` reads across 54 files.
   30-50 `final readonly class XxxRequest` DTOs with `fromArray()` factories
   (same pattern as WS `*Params`).
   - **Tests:** Each Request DTO gets construction + validation tests.

3. **SearchRules deep adoption** — `SearchService` (47 psalm issues) and
   `SearchFilterRenderer` (67 psalm issues, #1 hotspot). Rewrite both to consume
   `SearchRules` end-to-end.
   - **Tests:** Integration tests for every filter combination (allwords, tags,
     dates, ratios, ratings, dimensions, expert, added_by, filetypes).

4. **Container Factory classes** — Extract 118 inline `factory(static fn ...)` closures
   from `config/container.php` into `src/Piwigo/Core/Container/<Name>Factory.php`.
   - **Tests:** Container smoke test already covers resolution; add factory-specific
     unit tests for complex wiring.

5. **Bare-array repo returns** — 249 of 646 public repository methods return untyped
   arrays. Tracked in `docs/SQL-DTO-AUDIT.md` + `ARRAY-REFACTOR-AUDIT-{1..4}.md`.
   Return typed Projection/Entity classes instead.
   - **Tests:** Each converted method gets a return-type assertion.

6. **Remaining VOs + Enums** — ~4 ValueObjects + ~6 Enums to close F5-a.

7. **SessionStore rename** — `SessionService.php` → `SessionStore.php` (6 call sites).

8. **`is_array(.* ?? null)` elimination** — 154 instances → 0.

9. **Typed error responses** — `PwgError` message strings → typed error-code enum +
   i18n translation key.

**Gate:** `psalm --show-info` < 50. `grep -rn 'is_array(.* ?? null)' src/` → 0.

---

### P17 — Plugins/extensions module modernization

**NEW work not yet done on `16.x-rewrite`.** Decompose the three god-classes.

- `Plugins.php` (726 lines) → `PluginScanner`, `PluginLifecycle`, `PemCatalog`
- `Themes.php` (692 lines) → same pattern
- `Languages.php` (385 lines) → same pattern
- Eliminate public mutable arrays (`$plugins->fs_plugins`, `$plugins->db_plugins_by_id`)
- Fix WS handler Demeter violations (10 consumers reaching into public arrays)
- `Plugins::performAction(): mixed` → typed return

**Tests:** Unit tests for each extracted service. Integration tests for plugin
install/activate/deactivate/delete lifecycle.

---

### P18 — OpenAPI `#[ApiMethod]` decoration

**NEW work not yet done on `16.x-rewrite`.** The attribute class exists at
`src/Piwigo/Ws/OpenApi/ApiMethod.php` and `SpecBuilder` reflection is wired, but
no endpoint method carries the attribute yet.

- Decorate all 95 endpoint handler methods with `#[ApiMethod]`
- `SpecBuilder` reads `summary`, `responseClass`, `tags` from attribute
- Per-domain decomposition of endpoint classes

**Tests:** OpenAPI spec structural test. `SpecValidityTest` already exists.

---

### P19 — Tailwind CSS v4 (admin panel)

**NEW work not yet done on `16.x-rewrite`.** Plan exists at `docs/PLAN-TAILWIND.md`.

- Install `@tailwindcss/vite`
- Create `tailwind.css` entry with `@theme inline` referencing existing `--admin-*` tokens
- `@source` directive for `.latte` template scanning
- Migrate admin CSS from hand-written rules to utility classes
- Stylelint config update for Tailwind v4 directives
- Scope: admin panel only (gallery frontend has separate CSS architecture)

**Tests:** Stylelint clean. Visual regression screenshots before/after. Browser E2E
for all admin pages.

---

### P20 — Vendored frontend library migration

**Partially from existing §2.4 + §2.6.**

- Open Sans webfonts → `@fontsource/open-sans` / `@fontsource-variable/open-sans`
- (Scope shrunk after bundled-plugin removal — video.js, Leaflet, CodeMirror no
  longer in tree)

**Tests:** `npm run build` succeeds. Font rendering in browser E2E.

---

### P21 — Repository restructure (STRUCTURE-PLAN)

**Not yet done on `16.x-rewrite`.** Full plan at `docs/STRUCTURE-PLAN.md`.

14-step filesystem reorganization:
1. `_data/` → `var/`
2. `galleries/` + `upload/` → `var/`
3. Permission-checked original-file controller
4. Rewrite originals URLs to `?/p/<id>`
5. `dev/fixtures/` → `tests/Fixtures/`
6. `build/` cleanup
7. Docs consolidation
8. `src/Piwigo/Plugins/` → `src/Piwigo/PluginConfig/` rename
9. `themes/` dissolution → `resources/` (biggest single change)
10. TS types out of `src/`
11. Install SQL move
12. `language/` → `resources/lang/`
13. Public shim + setup script
14. Meta files + STRUCTURE.md rewrite

**Web-root isolation:** Only `public/` (generated by setup) is HTTP-reachable.
`vendor/`, `src/`, `config/`, `var/` all outside the web root. Private albums
become actually private (originals served via PHP permission check, not Apache
direct).

**Tests per step:** Full test suite after every step. Any red = stop and fix.
Browser E2E including install + upgrade flows. Visual regression.

**Gate:** `setup.sh` produces working installation. Direct HTTP access to
`vendor/`, `src/`, `var/` returns 404.

---

### P22 — Bundled extensions migration

**Not yet done on `16.x-rewrite`.** Plan at `docs/BUNDLED-EXTENSIONS-MIGRATION-PLAN.md`.

Migrate the bundled extensions (AdminTools, LocalFilesEditor, etc.) to the new
`PluginInterface` contracts from P12.

**Tests:** Plugin activation/deactivation E2E. Feature-specific tests per plugin.

---

### Deferred / on-demand (not phased)

These trigger only when a deployment or audit demands — no scheduled effort:

- **Monolog:** Swap logger backend when structured logging / external aggregation needed
- **S3/SFTP adapters:** Wire in `config/storage.php` when disk pressure / multi-server
- **Supervisor/systemd:** Package worker daemon config when async queue goes live
- **Renovate:** Port dev-dep auto-merge if dependency churn warrants

---

## What changes from the original branch

| Aspect | `16.x-rewrite` (original) | `16.x-v2` (replay) |
|--------|--------------------------|---------------------|
| Test framework | PHPUnit + Playwright (separate) | Pest 4 (unified: unit + browser + arch + mutate) |
| TS unit tests | None | Vitest |
| Commit style | 27 era-switches per 100 commits | One concern per phase |
| Coverage | Unmeasured (15 namespaces at zero) | Measured, ratcheted, every namespace tested |
| Mutation testing | None | pest-plugin-mutate |
| `include/` deletion | Incremental (97 modifications over months) | By domain (URL, User, Category, etc.), each deleted same commit as migration |
| PHPStan | Pushed L0→L10 in one burst (commits 300-340) | Raised gradually per phase as code quality supports it |
| CSS | Interleaved throughout | One concentrated phase (P9) |
| Latte | Two separated waves (commits 380-440, 980-1080) | One concentrated phase (P10) after all deps stable |
| Plugin contracts | Mixed with WS refactoring | Separate phase (P12) after WS endpoints (P8) |

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

### Replay of existing work (P0-P15)

| Phase | Effort | Notes |
|-------|--------|-------|
| P0 Tooling | 2-3 days | Framework setup + initial E2E |
| P1 Composer | 1-2 days | Mechanical vendor swaps |
| P2 Rector/PHPStan | 2-3 days | Automated transforms + manual L5 fixes |
| P3 PSR-4 | 2-3 days | 62 class moves + test writing |
| P4 Kernel/DI/boot | 4-5 days | Core architecture, careful |
| P5 Config/DB/facades | 5-7 days | Config SCHEMA alone is large |
| P5.5 Frontend tooling | 3-4 days | Parallel track |
| P6 Service migration | 10-14 days | Largest phase — 17 domains + admin |
| P7 Constants | 1-2 days | Mechanical |
| P8 WS endpoints | 3-4 days | 95 endpoints + tests |
| P9 CSS | 5-7 days | 11 sub-steps |
| P10 Templates | 5-7 days | 133 conversions |
| P11 Asset pipeline | 2-3 days | |
| P12 Contracts | 5-7 days | 160 events + plugin system |
| P13 Security | 2-3 days | |
| P14 Type correctness | 5-7 days | VOs, DTOs, entities (done portion of §1.6/§1.7) |
| P15 Quality gates | 2-3 days | Baselines + CI |

**Subtotal replay: ~10-14 weeks**

### New/pending work (P16-P22)

| Phase | Effort | Notes |
|-------|--------|-------|
| P16 Deep mixed elimination | 3-5 weeks | 88 Result DTOs, 30-50 Request DTOs, SearchRules, 249 repo returns |
| P17 Extensions decomposition | 1-2 weeks | 3 god-classes → ~9 focused services |
| P18 OpenAPI #[ApiMethod] | 3-5 days | 95 endpoint decorations |
| P19 Tailwind CSS v4 | 2-3 weeks | Admin panel CSS rewrite |
| P20 Vendored frontend libs | 1-2 days | Scope shrunk to webfonts |
| P21 Repo restructure | 2-3 weeks | 14 steps, most complex is themes → resources |
| P22 Bundled extensions | 1 week | Plugin contract migration |

**Subtotal new work: ~10-14 weeks**

**Grand total: ~20-28 weeks** of focused work (replay + new).

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
