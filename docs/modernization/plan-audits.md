# Modernization plan status audits

Living record of per-plan completion checks. Each entry is a snapshot of how much of a planned migration actually landed.

Audit date: 2026-05-01. Branch: `16.x-rewrite`.

---

## `move-php-libs-to-cached-iverson.md` — Eliminate `window.*` globals via ES module static imports

**Status: 100% complete.** All three waves landed; every `window.*` global the plan targets is gone from live code. Remaining mentions in `MODERNIZATION_PLAN.md` are retrospective only. `globals-removal.md` was verified complete and deleted.

### What's done

**Wave 1 — TS→TS globals (`AlbumSelector` + `pwgDoubleSlider`)** ✓

- `window.AlbumSelector` removed from `album_selector.ts`; all 6 consumers (`batchManagerFilter`, `batchManagerGlobal`, `batchManagerUnit`, `cat_modify`, `photos_add_direct`, `picture_modify`) use ES imports
- `mcs.ts` AlbumSelector guard block removed
- `doubleSlider.ts` moved from `admin/themes/default/js/` → `themes/default/js/`, window assignment dropped, both consumers (`batchManagerFilter`, `mcs`) import statically
- `combine_script id='album_selector'` and `combine_script id='doubleSlider'` purged from templates
- `album_selector` Vite entry removed from `vite.config.ts`

**Wave 2 — Template-instantiated `LocalStorageCache` classes** ✓ (mostly)

- All 5 `window.{LocalStorageCache,CategoriesCache,TagsCache,GroupsCache,UsersCache} = …` assignments deleted from `LocalStorageCache.ts`
- All 7 template+TS pairs migrated (`batch_manager_global`, `batch_manager_unit`, `group_list`, `picture_modify`, `user_activity`, `cat_perm`, `rating`)
- New entries created: `cat_perm.ts` (vite.config.ts:44) and `rating_admin` (vite.config.ts:63 — wired to `admin/themes/default/js/rating.ts`)

**Wave 3 — Piecon (legacy JS library)** ✓

- `themes/default/js/plugins/piecon.js` converted to ES module (`const Piecon = (() => {...})(); export default Piecon;`)
- `photos_add_direct.ts:8` does `import Piecon from '../../../../themes/default/js/plugins/piecon';` — no `declare var`, no `typeof` guard
- `combine_script id='piecon'` removed from `photos_add_direct.tpl`

### What's still pending

Nothing. All three loose ends resolved:

1. Stale `combine_script id='LocalStorageCache'` in `photos_add_direct.tpl` — removed.
2. `'LocalStorageCache'` standalone Vite entry — removed; now auto-shared chunk.
3. `'doubleSlider'` standalone Vite entry — removed; now auto-shared chunk.

---

## `i-added-a-few-zesty-adleman.md` — Test coverage expansion

**Status: ~100% complete.** Every planned file exists, the unit suite runs green at 218 tests, and only minor distribution differences from the plan text.

### What's done

| Phase | Status |
|---|---|
| 1 — Bootstrap stubs (`char_to_fraction`, `get_pwg_charset`, `trigger_notify`, `trigger_change`) | ✓ Present in `tests/bootstrap.php` lines 91, 99, 107, 113 |
| 2 — Auth (`PwgBase32Test`, `PwgTOTPTest`) | ✓ Both files in `tests/Unit/Auth/` |
| 3 — Search (5 files) | ✓ `QSingleTokenTest`, `QSearchScopeTest`, `QNumericRangeScopeTest`, `QDateRangeScopeTest`, `QExpressionTest` all in `tests/Unit/Search/` |
| 4 — Image geometry (3 files) | ✓ `ImageRectTest`, `SizingParamsTest`, `DerivativeParamsTest` in `tests/Unit/Image/` |
| 5 — Users / Menu / WS encoders (10 files) | ✓ All planned files plus 2 bonus (`PwgErrorTest`, `PwgServerTest`) under `tests/Unit/Ws/` |
| 6 — Integration `WsApiTest` | ✓ 9 tests (plan said ~8) covering version, session login + status, categories list, tags list, image search, users list with/without auth, full categories add/delete lifecycle. `IntegrationTestCase.php` base class also created. |
| 7 — Photo lifecycle E2E | ✓ `09-photo-upload.spec.ts` (3 tests), `10-photo-album-lifecycle.spec.ts` (1 bundled CRUD test, intentional per plan) |
| 8 — Content management E2E | ✓ `11-search.spec.ts` (3 tests), `12-tags.spec.ts` (2 tests, bundled CRUD + admin-page smoke), `13-user-management.spec.ts` (3 tests) |
| 9 — Extended admin smoke | ✓ `14-admin-extended-smoke.spec.ts` (6 tests) |
| Helpers | ✓ `tests/e2e/helpers/upload-photo.ts` exists |

### Test counts vs estimates

- Plan estimated **~140 new unit tests** on top of an 84-test baseline → 224 expected
- Actual: **218 unit tests, all passing** (`vendor/bin/phpunit --testsuite Unit`)

### Minor distribution variations from plan text

Two cosmetic differences, neither a gap:

1. **Page-smoke distribution between specs 12/13/14.** Plan put `user_list`, `group_list`, `tags` admin-page smoke under spec 14. Actual: those moved into the matching feature specs (12-tags, 13-user-management) where they're more cohesive. Spec 14 picked up `photo`, `comments`, `batch_manager`, `stats`, `rating`, `permalinks` instead. Same total page coverage; more readable groupings.
2. **CRUD bundling in specs 10 and 12.** Plan was clear about bundling for spec 10 ("Full CRUD in one spec to keep ordering safe"). Spec 12 followed the same pattern (bundling `tags.add` + `images.setInfo` + `tags.delete` into one `'create tag, assign to photo, delete tag'` test). Plan said ~4 tests; actual is 2 tests covering the same operations.

### What I couldn't verify here

- **Integration suite** — needs `docker compose up -d --wait db web`; Docker is not currently up in this environment. Tests exist and parse; have not confirmed they pass.
- **E2E suite** — needs Playwright + DB reset; same constraint.

---

## `i-want-to-modernize-vectorized-matsumoto.md` — Modernize Piwigo 16.x → PHP 8.5 + full typing + TypeScript (umbrella plan)

**Status: substantial progress on every phase; the active cut-point is Phase 4.** Phases 0, 1, 5 are effectively done. Phases 2 and 3 are mostly done with long-tail remainders. Phase 4 is the largest remaining phase — Wave A complete and Wave B underway, Wave C not started.

### Phase 0 — Foundation & safety net ✓ (~100%, exceeds plan in places)

- `composer.json` matches plan (PHP `^8.5`, phpstan/rector/pint/phpunit/symfony-process pinned); also pulls runtime deps the plan didn't enumerate (smarty, phpmailer, pclzip, emogrifier, jshrink, minify, mobileesp).
- `phpstan.neon` at **level 8** (plan started at 0); `phpstan-baseline.neon` (109.6 KB) committed plus a `phpstan-nobaseline.neon` variant. Bootstrap files `tools/phpstan-bootstrap.php` + `tools/phpstan-types.php`. Custom rules `Piwigo\Tools\PhpStan\NoDynamicNewRule` and `NoGlobalInSrcRule` registered.
- `rector.php` is well past Phase 0 dry-run: `withPhpSets(php85: true)`, `SetList::TYPE_DECLARATION`, `DeclareStrictTypesRector`, ~75 `RenameClassRector` mappings driving the Phase 3 namespacing.
- `pint.json` matches spec exactly (PSR-12, `declare_strict_types: false`, single-quote, ordered imports).
- `phpunit.xml.dist` declares Unit + Integration suites; bootstrap is `tests/bootstrap.php`.
- `docker-compose.yml` matches plan (mariadb:10.11 healthcheck-gated, php:8.5-apache via `docker/Dockerfile`, playwright service). Ports 3307/8090.
- `.github/workflows/ci.yml` runs lint (pint + phpstan + baseline-grow guard + conf-shape drift + typecheck + vite build + tarball-excludes-dev-deps + strict_types coverage gate), unit, e2e (docker compose + playwright + integration).
- 16.x fixture: `dev/fixtures/piwigo-16.x.sql` 241 KB (above plan's ≥200 KB target).
- Playwright suite: 15 specs in `tests/e2e/` covering install, smoke gallery/admin, create-album, upload-photo, change-setting plus 9 additional specs (identify-remember-me, photo lifecycle, search, tags, user-mgmt, album-tree, etc.) — well beyond plan's 6.

### Phase 1 — Make it run on PHP 8.5 ✓ (100%)

- `mysql_*` (legacy non-`i`) function calls in `*.php`: **0** matches across repo.
- `create_function(`: **0** live calls (only 2 commented references in `src/Piwigo/Template/Template.php:1262,1285`).
- `utf8_encode(` / `utf8_decode(`: **0** matches.

### Phase 2 — phpdoc → native types ~ (~85-90%)

- `declare(strict_types=1)` enforced repo-wide by CI gate (`ci.yml:36-51` fails on any missing file in `include/ admin/ install/ src/`, with allowlist for vendored libs like `feedcreator`, `phpqrcode`, `pclzip`, `emogrifier`).
- Sampled `include/functions.inc.php`: 81 top-level functions, 66 carry native return types; spot-check shows full param typing on newer functions (`input_int`, `input_string`, `input_bool`), older ones still use untyped params (`get_extension`, `mkgetdir`).
- Sampled `include/functions_picture.inc.php`, `include/ws_functions/pwg.images.php`, `include/functions_user.inc.php`, `admin/include/functions.php`, `admin/include/functions_install.inc.php`: all carry `declare(strict_types=1)` and consistent native return types.
- Phpdoc `@param`/`@return` counts: `include/` 1,162 across 37 files, `admin/` 282 across 22 files. Most are now type-supplementary (generic shapes for arrays) rather than primary type source.
- **What's left**: long tail of legacy untyped params on older free functions in `include/functions.inc.php`, `functions_url.inc.php`, etc. Rector's TYPE_DECLARATION set is wired so this will continue to bleed off.

### Phase 3 — PSR-4 + namespacing ~ (~50-60%)

- `composer.json` autoload: `"Piwigo\\": "src/Piwigo/"` PSR-4 mapping live (with `classmap-authoritative: true`).
- `src/Piwigo/` holds 69 PHP class files across 12 namespace clusters (`Admin`, `Auth`, `Cache`, `Calendar`, `Core`, `Image`, `Menu`, `Search`, `Session`, `Template`, `Users`, `Ws`).
- Legacy `*.class.php` still in `include/`: 12 (`Logger`, `block`, `cache`, `calendar_base`, `calendar_monthly`, `calendar_weekly`, `feedcreator`, `passwordhash`, `pwgsession`, `pwgsession_php7`, `template`, `totp`). All correspond to classes already migrated to `src/Piwigo/` per `rector.php` `RenameClassRector` map — these legacy shims appear to remain on disk as transitional aliases (worth confirming they're empty/redirects).
- Legacy `*.class.php` still in `admin/include/`: 8 (`c13y_internal`, `check_integrity`, `image`, `languages`, `plugins`, `tabsheet`, `themes`, `updates`) — all also mapped in `RenameClassRector`.
- `include_once`/`require_once` in `src/Piwigo/`: **0** matches — clean PSR-4 inside the new tree.
- Plan target was 358 classes. Repo has ~89 total class definitions across legacy locations + 69 in `src/`. Either the 358 figure included many enums/interfaces/traits the plan over-counted, or large swaths of code remain free-function-based. Either way, the bulk of identified classes have been moved.
- **What's left**: confirm the 12+8 legacy `*.class.php` files are stub shims (or remove them); finish migrating any plugin/theme classes still under `include/`.

### Phase 4 — Globals → typed services / DTOs ~ (~35-50%, **sampled, not exhaustive**)

The plan flagged this as the largest phase and most likely cut point. Scaffolds are in place; adoption is in progress.

- All five plan-spec services exist: `src/Piwigo/Core/Config.php` (1,204 lines), `Kernel.php`, `Lang.php`, `PageState.php`, `ServiceLocator.php`, `src/Piwigo/Users/User.php`, `CurrentUser.php`.
- `Config.php` implements `attachGlobals()` reference-bridge as planned (`$GLOBALS['conf'] = &self::$data`), plus `get/getString/getInt/getBool` and lazy `src()` for pre-boot reads.
- `Kernel::boot()` is wired into **20** root entry points (`about`, `admin`, `comments`, `action`, `check_admin`, `identification`, `feed`, `index`, `nbm`, `notification`, `picture`, `password`, `ws`, `random`, `profile`, `qsearch`, `popuphelp`, `register`, `search`, `tags`). `common.inc.php:48` honours the `Kernel::isBooted()` re-entrance guard.
- Adoption metrics:
  - `Config::(get|getString|getInt|getBool|getArray)` callsites: **182+** across 50+ files (good distribution: includes, admin, root entry points, `src/`).
  - `PageState::(current|init|addError|...)` callsites: **171** across 48 files — substantial Wave B writer migration done (esp. `admin/maintenance_actions.php` 19 hits, `admin/include/functions_notification_by_mail.inc.php` 11 hits, `admin/themes_new.php`/`languages_new.php`/`plugins_new.php` 8-9 each — matches plan's hot spots).
  - `CurrentUser::` callsites: **30** across 11 files (lighter adoption — `User`/`CurrentUser` mostly read by `src/`, legacy `$user` still dominant elsewhere).
- Remaining raw `$conf[...]` access: `include/` 1,162 occurrences (1 file, `config_default.inc.php`, alone has 209 — that's the writer file and should stay), `admin/` 282 across 22 files. Hot spots: `admin/include/functions.php` 149, `include/functions.inc.php` 139, `include/functions_user.inc.php` 121, `include/ws_functions/pwg.images.php` 95.
- Wave B migrate tooling exists: `tools/wave_b_migrate.php`, `tools/wave_b5_migrate.php`, `tools/remove_global_conf.php`, `tools/check-conf-shape.php` (last is a CI gate).
- **Wave C** (ArrayObject deprecation proxy): not landed (no `GlobalsBridge` class).
- **Caveat**: this phase is too sprawling to fully audit in one pass — sampled the four scaffolds (Config/PageState/CurrentUser/Kernel), boot wiring, and adoption greps. Did not inspect each push-site.

### Phase 5 — JS → TS conversion ✓ (~100%)

Confirmed only at file-presence level (substantively audited via the jquery-removal and globals-removal plan audits, both of which were verified complete and deleted).

- `package.json` declares Vite 5, TypeScript 5.6.3, Playwright; full runtime deps modernised (Uppy, Tom Select, dayjs, Chart.js, GLightbox, noUiSlider, Tippy, Flatpickr).
- `vite.config.ts` and `tsconfig.json` present at root. `tsconfig.json` has `strict: true`, `noImplicitAny: true`, `strictNullChecks: true` all enabled.
- `admin/themes/default/js/`: **40 `.ts` files, 0 `.js` files** (full conversion).
- `themes/default/js/`: **8 `.ts` files, 1 `.js` file** (`plugins/piecon.js` — vendored ES module, expected per plan).
- `dev/vite-entries.json` artifact present per plan step 2.
- Phase 5 console-clean spec exists at `tests/e2e/07-phase5-console-clean.spec.ts`.
- **Module-graph gaps closed** (2026-05-01): all `{footer_script}` function definitions and `onclick=` calls to TS-defined functions eliminated. Specifically: `changeImgSrc` + `addToCadie` moved to `scripts.ts`; rating `_pwgRatingAutoQueue` callback replaced with plain data fields; `group_list` event listeners + cancel-button handlers moved to `group_list.ts`; `batch_manager_unit` globals (`activePlugins`, `all_related_categories_ids`, `pluginValues`, GLightbox, datepicker init) migrated to typed JSON + TS DOMContentLoaded.

### Phase 6 — Cleanup

Out of scope for this audit (depends on prior phases finishing).

### Headline numbers

- **Phase 0**: scaffolding 100% landed; exceeds plan in places (PHPStan level 8 not 0; 15 e2e specs not 6).
- **Phase 1**: zero offending PHP function calls remain.
- **Phase 2**: strict_types enforced repo-wide via CI; native return types on new code; long tail of param types remains.
- **Phase 3**: 69 PSR-4 classes migrated; 20 legacy `*.class.php` shims remain in `include/` + `admin/include/` (worth confirming these are aliases not duplicates).
- **Phase 4**: services scaffolded, Wave A reference-bridge live, Wave B in active progress (171 PageState + 182 Config callsites), Wave C not started — this is the active cut-point area.
- **Phase 5**: TS migration complete (only 1 vendored JS remaining, `piecon.js`); module-graph gaps closed.
