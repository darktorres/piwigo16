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

Traced from actual `use` statements in the final codebase. 14 phases (P0-P13),
down from 22 after merging phases that share the same concern and touch the
same files.

```
P0 Tooling ──→ P1 Composer + Rector + PHPStan L5
                │
                └─→ P2 PSR-4 (62 classes → src/Piwigo/)
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

### P0 — Tooling foundation (on bare `origin/16.x`)

**Creates:** `composer.json`, `package.json`, Pest, Pest Browser, CI, Pint, PHPStan,
Rector, Vitest, `.editorconfig`, `.gitignore` extensions.

**The rule from here forward:** no code change lands without an accompanying test
or being gated by a static analysis tool.

#### Commits:

1. **Composer init + Pest + Pest plugins + coverage**
   - `composer.json`: PHP 8.5, `pestphp/pest` ^4, `pest-plugin-arch` ^4,
     `pest-plugin-mutate` ^4, `pest-plugin-type-coverage` ^4,
     `pest-plugin-browser` ^4
   - `phpunit.xml.dist` (Pest uses it) with `<coverage>` filter on `include/`,
     `admin/`, and later `src/Piwigo/`
   - `tests/Pest.php` (Pest config)
   - `tests/bootstrap.php`
   - `tests/Unit/SmokeTest.php`
   - `tests/Arch/StructuralTest.php` — first arch rules (will grow per phase)
   - `.gitignore` for `vendor/`, `.phpunit.cache/`, `coverage/`
   - Configure `pcov` as the coverage driver (faster than Xdebug for CI):
     `composer require --dev pcov/clobber` or rely on php.ini `extension=pcov`
   - `composer.json` scripts: `"test:coverage": "pest --coverage-html=coverage/"`
   - Establish baseline coverage % against `origin/16.x` codebase (will be near 0%)
   - Gate: `vendor/bin/pest --testsuite Unit` green
   - Gate: `vendor/bin/pest --coverage --min=0` runs without error (proves
     coverage tooling works; threshold raised per phase)

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
   - `.github/workflows/ci.yml`: pest-unit (with `--coverage-clover`),
     pest-browser, pint, phpstan, rector, vitest, audit
   - Coverage artifact upload (HTML report) on every CI run
   - `.editorconfig`
   - Gate: CI green

6. **Dev fixtures + coverage ratchet**
   - `dev/fixtures/piwigo-16.x.sql` — baseline DB snapshot
   - `dev/fixtures/README.md`
   - `.coverage-baseline` — committed file recording current minimum coverage %.
     CI fails if coverage drops below. Starting at 0%, bumped after each phase.

**Test count after P0:** ~5 PHP unit, 3 browser, 1 TS unit, arch rules.
**Coverage:** tooling operational, baseline at 0%, ratchet enforced from P1 onward.
All gates green.

---

### P1 — Composer + Rector + PHPStan (PHP modernization)

**Merges old P1 + P2.** Both are "modernize the raw legacy PHP before restructuring."
Same files, same concern, one pass.

**What happens:**
- Remove vendored `include/smarty/`, `include/phpmailer/`, `include/emogrifier.class.php`,
  `include/jshrink.class.php`, `include/minify/`, `include/passwordhash.class.php`,
  `include/feedcreator.class.php`, `include/phpqrcode.php`, `include/mdetect.php`,
  `include/base32.class.php`
- `composer require` their packagist equivalents
- Add `require 'vendor/autoload.php'` in `include/common.inc.php`
- Remove `include/php_compat/` shims, `include/dblayer/functions_mysql.inc.php`
- Migrate password hashing to `password_hash()` / bcrypt
- Apply Rector PHP 8.0-8.5 sets
- Pint format pass, add `declare(strict_types=1)` to all first-party files
- Push PHPStan from L0 → L5 incrementally

**Tests:** Browser E2E after each vendor swap + Rector pass. Password hashing unit test.
Arch test: no vendored lib dirs exist.

**Gate:** PHPStan L5 clean. Rector dry-run clean. Pint clean. Browser green.

---

### P2 — PSR-4 namespace migration

**What happens:**
- Create `src/Piwigo/` directory tree
- PSR-4 autoload config in `composer.json`
- Move all 62 first-party classes from `include/*.class.php` → namespaced classes
- Update all `require`/`include` references

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
- `Paths.php` value object (replaces `PHPWG_ROOT_PATH`, 195 reads in 72 files)
- Replace all remaining `define()` constants with typed alternatives
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

#### P4.5a — Vite + TypeScript + linting
- `vite.config.ts`, `tsconfig.json`, `eslint.config.ts`, `.prettierrc.json`,
  `.stylelintrc.json`
- Convert all 38 authored JS files to `.ts`, fix all lint errors
- CI jobs for lint/format/typecheck
- **Tests:** `npm run build`, `npm run typecheck`, lint gates

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

**After each domain:** that domain's `include/` file is DELETED.

#### P5b — Admin migration

Upload → Albums → Users → Config → Extensions → BatchManager → History →
Maintenance → Misc. Each deletes its `admin/*.php` source.

#### P5c — Cleanup

- Delete `ServiceLocator.php`, retire `$GLOBALS` bridges
- Delete remaining root entry points (`about.php`, `comments.php`, etc.)
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
- `WsMethodRegistrar.php` — register all 95 endpoints
- 9 `*Endpoints.php` classes → individual `*Handler.php` classes
- `#[ApiMethod]` attribute on all 95 handlers
- `SpecBuilder` → OpenAPI 3.1 spec generation reading attributes

**Tests:** `WsApiTest.php` — integration test for all 95 endpoints. OpenAPI spec
validation (cebe/redocly). Parameter validation tests.

**Gate:** All 95 endpoints respond. OpenAPI validates. Integration tests green.

---

### P7 — CSS modernization + Tailwind

**Merges old P9 + P19.** P9 builds the token foundation that P19 consumes. Running
them as separate phases means touching every admin CSS file twice. One pass: build
the token system, then replace hand-written rules with Tailwind utilities.

1. Delete orphan CSS
2. Split theme monoliths (9635 → 51 files admin, 1305 → 10 files frontend)
3. Design tokens (93 admin, 42 frontend)
4. Skin/child theme refactor, `!important` elimination (689 → 51)
5. Inline `<style>` extraction, search CSS collapse
6. Install `@tailwindcss/vite`, create `tailwind.css` with `@theme inline`
   referencing `--admin-*` tokens
7. Migrate admin CSS from hand-written rules to Tailwind utilities
8. `@source` for `.latte` scanning, Stylelint config for Tailwind v4

**Tests:** Stylelint clean. Visual regression screenshots. Browser E2E.
`wc -l themes/admin/_base/theme.css` → 12.

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
4. Convert admin templates (80 files)
5. Convert frontend + standard pages templates (53 files)
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
3. ValueObjects (~25) + Enums (~10) — all of them, not split across two phases
4. Entity + Projection patterns (7 Entity, 73 projections)
5. Session VO + `SessionStore` rename
6. WsResult DTOs (88 endpoints)
7. Web/admin Request DTOs (30-50)
8. Container Factory classes (118 closures → typed factories)
9. Bare-array repo returns (249 methods → typed)
10. SearchRules deep adoption (SearchService + SearchFilterRenderer)
11. `is_array(.* ?? null)` elimination (154 → 0)
12. Typed error responses (PwgError → enum + i18n key)
13. Psalm as secondary CI gate

**Tests:** Each VO/Entity/DTO/Result gets construction + validation tests.
Integration tests for every search filter combination.

**Gate:** PHPStan L10. `psalm --show-info` < 50.
`grep -rn 'is_array(.* ?? null)' src/` → 0.

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
| P2 PSR-4 | 2-3 days | 62 class moves + test writing |
| P3 Kernel/DI/boot | 4-5 days | Core architecture |
| P4 Config/DB/facades/constants | 5-7 days | Config SCHEMA + Paths + DI wiring |
| P4.5 Frontend tooling | 3-5 days | Parallel: Vite + TS + lint + any→0 + webfonts |
| P5 Service migration | 10-14 days | 17 domains + admin + ServiceLocator kill |
| P6 WS endpoints + OpenAPI | 4-6 days | 95 endpoints + #[ApiMethod] + SpecBuilder |
| P7 CSS + Tailwind | 3-5 weeks | Tokens + splitting + Tailwind rewrite (admin) |
| P8 Templates + assets | 5-7 days | 133 Latte conversions + ViteManifest |
| P9 Plugins + extensions | 2-3 weeks | Events + registries + 3 god-classes + 7 extensions |
| P10 Security | 2-3 days | Cookies + rate limit + headers |
| P11 Type correctness | 4-7 weeks | Full §1.6 + §1.7 in one pass |
| P12 Quality gates | 2-3 days | Mutation, a11y, bundle budgets |
| P13 Repo restructure | 2-3 weeks | 14 steps + web-root isolation |

**Total: ~20-28 weeks** of focused work.

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
