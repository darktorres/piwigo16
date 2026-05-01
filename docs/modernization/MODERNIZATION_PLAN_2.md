# Modernization Plan — Phase 7 and beyond

This document covers all remaining modernization work after Phase 8 of
`MODERNIZATION_PLAN.md` completed. Each phase is self-contained and
ordered by impact vs. effort.

**Current state of the codebase (as of Phase 8 close):**

| Metric | Value |
|---|---|
| PHPStan level | 8 (baseline 1 412 errors) |
| PHP minimum | 8.5 |
| TypeScript | strict + noImplicitAny + noImplicitThis + strictNullChecks |
| JS→TS (frontend themes) | 5% (5 TS / ~13 first-party + 280 vendored JS files) |
| Unit test coverage | 13% (9 / 69 source classes) |
| CSS custom properties | 0 (189 hardcoded hex colors in first-party CSS) |
| Baseline error breakdown | ~850 vendor code, ~560 first-party |

---

## Phase 7 — PHPStan level 9 / baseline elimination (L) — WIP

**Note on baseline composition:** ~850 of the 1 412 baseline errors are in
vendored third-party files (`include/phpqrcode.php` 443,
`admin/include/pclzip.lib.php` 192, `include/mdetect.php` 168,
`include/phpmailer/` ~50). These cannot be fixed without forking upstream.
The actionable first-party error count is ~560.

### Step-by-step sequence

1. **Exclude vendored files from PHPStan analysis.** Add to `phpstan.neon`:
   ```yaml
   excludePaths:
     analyseAndScan:
       - include/phpqrcode.php
       - include/mdetect.php
       - admin/include/pclzip.lib.php
       - include/phpmailer/**
   ```
   Regenerate baseline — expected drop from 1 412 → ~560.

2. **Slice the remaining baseline by file:**
   ```bash
   grep "path:" phpstan-baseline.neon | sed 's/.*path: //' | sort | uniq -c | sort -rn | head -20
   ```
   Top first-party contributors: `feed.php` (21),
   `admin/include/functions_upload.inc.php` (12), `admin/rating_user.php` (6).

3. **Fix dominant error classes** (93.5% of errors are type-related):
   - `missingType.parameter` (343) — add native parameter types
   - `missingType.return` (318) — add return type declarations
   - `missingType.property` (163) — add property type declarations
   - `argument.type` (117) — fix type mismatches at call sites
   - `missingType.iterableValue` (68) — replace bare `array` with `array<K,V>`

4. **Work namespace slices in order:**
   - `src/Piwigo/Core/` — typed services, unit tests provide safety net
   - `src/Piwigo/Template/` — Smarty adapter `mixed` types
   - `src/Piwigo/Ws/` — WS parameter extraction `mixed` flow
   - `include/functions*.inc.php` — one function per commit
   - `admin/` — one file per commit (`--paths admin/file.php`)

5. **Bump to level 9 + `treatPhpDocTypesAsCertain: true`** after baseline
   reaches zero at level 8. Expect 200–500 new errors; fix without adding
   baseline entries.

6. **Delete the baseline** once level 9 exits 0:
   ```bash
   rm phpstan-baseline.neon
   # remove the includes: block from phpstan.neon
   vendor/bin/phpstan analyse --no-progress
   ```

### Exit signal

`phpstan.neon` at level 9 with `treatPhpDocTypesAsCertain: true`, no
`includes: [phpstan-baseline.neon]`, `vendor/bin/phpstan analyse` exits 0,
CI lint green.

### Close-out (fill in when shipped)

- Final baseline line count at close: _
- Level reached and `treatPhpDocTypesAsCertain` status: _
- Real bugs found and fixed during baseline elimination: _
- CI run ID confirming green: _

---

## Phase 9 — Fix global variable violations in `src/` (S)

**Problem:** 11 `global` declarations remain inside `src/Piwigo/` classes,
violating the `NoGlobalInSrcRule` PHPStan custom rule:

| File | Variable | Count |
|---|---|---|
| `src/Piwigo/Template/Template.php` | `$lang_info` | 3 |
| `src/Piwigo/Calendar/CalendarBase.php` | `$template` | 2 |
| `src/Piwigo/Calendar/CalendarMonthly.php` | `$template` | 1 |
| `src/Piwigo/Admin/updates.php` | `$template` | 1 |
| `src/Piwigo/Admin/tabsheet.php` | `$template` | 1 |
| `src/Piwigo/Menu/BlockManager.php` | `$template` | 1 |
| `src/Piwigo/Template/FileCombiner.php` | `$template` | 1 |
| `src/Piwigo/Admin/Integrity/c13y_internal.php` | `$template` | 1 |

**Fix:** Replace each `global $template` with
`ServiceLocator::get(Template::class)`. For `$lang_info`, expose via
`Lang::current()->info()` or pass as a parameter.

### Exit signal

`grep -rn "global \$template\|global \$lang_info" src/` returns zero
hits; PHPStan exits 0; unit suite green.

---

## Phase 10 — Remove `ws_core.inc.php` class duplication (M)

**Problem:** `include/ws_core.inc.php` still defines five global classes
(`PwgError`, `PwgNamedArray`, `PwgNamedStruct`, `PwgServer`,
`PwgRequestHandler`) that are now also in `src/Piwigo/Ws/`. Both
definitions coexist at runtime via PHP class-alias shims in Rector config.

### Step-by-step

1. **Audit remaining direct callers** outside `src/`:
   ```bash
   grep -rn "new PwgError\|new PwgNamedArray\|new PwgNamedStruct\|new PwgServer" include/ admin/ --include="*.php"
   ```
2. **Migrate remaining callers** to the namespaced FQCN via `use` imports.
3. **Replace class bodies** in `ws_core.inc.php` with `class_alias` calls:
   ```php
   class_alias(\Piwigo\Ws\PwgError::class, 'PwgError');
   ```
4. **Verify:** `grep -n "^class Pwg" include/ws_core.inc.php` returns zero;
   all tests green; E2E WS endpoints respond correctly.

### Exit signal

No class definitions in `ws_core.inc.php`; PHPStan exits 0; Playwright
WS specs green.

---

## Phase 11 — Unit test coverage expansion (L)

**Current:** 9 test files, 13% of 69 source classes.
**Target:** ≥40% (≥28 test files).

**Untested namespaces (prioritised):**

| Namespace | Classes | Priority | Notes |
|---|---|---|---|
| `Piwigo\Admin` | 9 | HIGH | Most PHPStan violations |
| `Piwigo\Search` | 7 | HIGH | Complex query logic, pure parsing — easy unit tests |
| `Piwigo\Template` | 5 | HIGH | ScriptLoader tested; FileCombiner, CssLoader not |
| `Piwigo\Image` | 5 | MEDIUM | Derivative/sizing math |
| `Piwigo\Calendar` | 3 | MEDIUM | Date/range logic |
| `Piwigo\Auth` | 2 | MEDIUM | Security-critical |
| `Piwigo\Menu` | 3 | LOW | UI rendering logic |

### Approach

- One commit per class. No DB, no HTTP — unit scope only.
- Search classes (`QMultiToken`, `QDateRangeScope`, `QNumericRangeScope`)
  have pure parsing logic — zero mocking needed.
- For DB-dependent Admin classes, use the stub pattern in `tests/bootstrap.php`.
- For `Template` classes, test file-path logic and loader registration only
  (no Smarty render).

### Exit signal

`vendor/bin/phpunit --testsuite Unit` reports ≥28 test files; zero
failures; no risky tests.

---

## Phase 12 — PHP code quality: readonly, enums, match (M)

**Goal:** Adopt PHP 8.1–8.5 features where semantically correct.
Currently: 3 `readonly` uses, 0 `enum`, 4 `match`, 14 legacy `switch`
in `src/`.

### 12a — `readonly` properties (S)

Scan for properties written only in the constructor:

Candidates: `Piwigo\Image` value objects (`ImageRect`, dimension fields of
`SizingParams`), `Piwigo\Ws` request/response structs, `Piwigo\Cache\PersistentFileCache` path.

### 12b — `enum` for status constants (M)

Replace string discriminated unions with backed enums:

| Current | Enum |
|---|---|
| `$user['status']` (`'guest'\|'normal'\|'admin'\|'webmaster'`) | `enum UserStatus: string` |
| `$conf['session_save_handler']` (`'db'\|'file'`) | `enum SessionHandler: string` |
| Derivative size names (`'square'\|'thumb'\|'small'\|...`) | `enum DerivativeSize: string` |
| `$conf['category_url_style']` (`'id'\|'id-name'`) | `enum CategoryUrlStyle: string` |

### 12c — `match` over `switch` (S)

Convert the 14 `switch` statements in `src/` that have no fall-through and
return a value. Mechanical Rector conversion:
```bash
grep -rn "switch (" src/ --include="*.php" -l
```

### 12d — `#[Override]` attribute (S)

Add `#[Override]` via Rector:
```php
->withRules([AddOverrideAttributeToOverriddenMethodsRector::class])
```

### Exit signal

`switch` count in `src/` reduced by ≥10; ≥3 new `enum` types; PHPStan
exits 0; unit suite green.

---

## Phase 13 — `functions_user.inc.php` refactoring (L)

**Problem:** 2 696-line monolith with 10 TODO/FIXME markers covering
auth, validation, data loading, permissions, and notification logic.

### Proposed split

| New class | Responsibility | Est. lines |
|---|---|---|
| `Users\UserRepository` | DB reads: `get_user()`, `get_user_infos()` | ~400 |
| `Users\AuthService` | Login, logout, remember-me, API key auth | ~500 |
| `Users\UserValidator` | Registration, password rules | ~300 |
| `Users\PermissionChecker` | `is_admin()`, level checks | ~200 |
| `Users\UserPreferences` | Theme, language, per-user settings | ~200 |

`functions_user.inc.php` becomes a shim of free-function wrappers
delegating to the new classes (backward compat for plugins).

### Approach

One class at a time. Each commit: extract class, add unit test, keep
free-function delegate, PHPStan green.

### Exit signal

`wc -l include/functions_user.inc.php` ≤ 500; `ls src/Piwigo/Users/`
shows ≥5 new classes; unit test coverage for `Users/` ≥80%.

---

## Phase 14 — TypeScript: reduce `any` / type-escape patterns (M)

**Current:** 883 type-escape patterns:

| Pattern | Count |
|---|---|
| `: any` annotations | 754 |
| `as any` / `as unknown` | 129 |
| `!` non-null assertions | 163 |

**Target:** Reduce to ≤450 by replacing mechanical `any`s with real types.

### Approach

1. **Define shared types in `src/types/piwigo.d.ts`:**
   ```typescript
   interface PiwigoCategory { id: number; name: string; permalink?: string; }
   interface PiwigoUser { id: number; username: string; status: string; }
   interface PiwigoTag { id: number; name: string; url_name: string; }
   interface WsResponse<T> { stat: 'ok' | 'fail'; result: T; }
   ```
2. **Replace `any[]` module-level arrays** with typed arrays:
   `current_users: PiwigoUser[]`, `groups_arr: [number, string][]`.
3. **Replace `(ajax_data: any)` parameters** with named interface types.
4. **Audit `!` non-null assertions** — replace unsafe ones with explicit
   null checks.
5. **Enable `noUncheckedIndexedAccess`** in tsconfig once `any[]` is
   mostly eliminated.

### Exit signal

Total type-escape patterns ≤450; `npm run typecheck` exits 0; no
`// @ts-ignore`.

---

## Phase 15 — Frontend JS → TypeScript migration ✓ COMPLETE

`themes/default/js/` has 8 `.ts` files and 1 `.js` file.
`admin/themes/default/js/` has 40 `.ts` files and 0 `.js` files.
The only remaining `.js` file is `themes/default/js/plugins/piecon.js`,
which is a vendored ES module intentionally kept as-is.

---

## Phase 16 — CSS design tokens and linting (M)

**Current:** 0 CSS custom properties; 189 hardcoded hex colors; no Stylelint.

**Note:** `feature/css-modernization` branch has 63 CSS variables and
refactored admin CSS — review and merge before doing new work here.

### Tasks

**16a — Merge `feature/css-modernization`** — brings CSS variable contract,
split component files, removed dead plugin CSS, fixed `!important` issues.

**16b — Frontend gallery theme tokens** — extract color palette from
`themes/default/css/default.css` into `:root` custom properties in a new
`tokens.css`. Replace hex literals with `var(--color-*)`.

**16c — Add Stylelint:**
```bash
npm install --save-dev stylelint stylelint-config-standard
```
```json
// stylelint.config.json
{
  "extends": ["stylelint-config-standard"],
  "ignoreFiles": ["**/js/plugins/**", "**/fonts/**"],
  "rules": { "color-named": "never", "color-no-invalid-hex": true }
}
```
Add `npx stylelint "**/*.css"` to CI lint job.

### Exit signal

`npx stylelint "themes/default/css/*.css" "admin/themes/default/css/*.css"`
exits 0; hardcoded hex count in first-party CSS ≤10; CI lint includes
stylelint.

---

## Phase 17 — jQuery migration (XL — incremental, long-term)

**Current:** jQuery 1.11.x (2014), 313+ references in TS files, jQuery UI
for sliders/datepicker/sortable. Cannot remove jQuery without breaking the
plugin contract.

### Migration ladder (do in order)

| Step | Action | Effort |
|---|---|---|
| 1 | Upgrade jQuery 1.11 → 3.7 in `themes/default/js/plugins/` | M |
| 2 | Replace jQueryUI `$.fn.datepicker` → `<input type="date">` | S |
| 3 | Replace jQueryUI `$.fn.slider` → `<input type="range">` | M |
| 4 | Replace Selectize → TomSelect (maintained fork) | M |
| 5 | Replace jQueryUI `$.fn.sortable` → native Drag and Drop API | L |
| 6 | Replace Colorbox → `<dialog>` + CSS | L |
| 7 | Replace `$.ajax` → `fetch()` in new code; keep old | incremental |

**Constraint:** jQuery must remain globally available for plugin authors
until the plugin API is officially versioned.

### Exit signal (step 1 only)

`grep -r "jquery-1\." themes/default/js/plugins/` returns zero;
Playwright E2E green (gallery interactions work).

---

## Phase 18 — Overdue TODO cleanup (S)

**46 TODO/FIXME/HACK markers** in first-party code. At least one is
explicitly overdue:

- `common.inc.php:172` — "TODO remove this data update as soon as 2025
  arrives" — **past due**. Investigate and remove or re-date.
- `include/functions_user.inc.php` — 10 markers (resolved by Phase 13).

### Approach

```bash
grep -rn "TODO\|FIXME\|HACK" src/ include/ admin/ --include="*.php"
```
Triage: fix now / reference phase / close as won't-fix. One commit per fix.

### Exit signal

`common.inc.php` overdue TODO resolved; total TODO count in `src/` ≤10.

---

## Effort summary and recommended sequence

| Phase | Description | Size | Status |
|---|---|---|---|
| 7 | PHPStan level 9 / baseline elimination | L | **WIP** |
| 9 | Fix global vars in `src/` | S | Not started |
| 10 | Remove `ws_core.inc.php` class duplication | M | Not started |
| 11 | Unit test coverage expansion | L | Not started |
| 12 | PHP: readonly, enum, match | M | Not started |
| 13 | `functions_user.inc.php` refactoring | L | Not started |
| 14 | TypeScript `any` reduction | M | Not started |
| 15 | Frontend JS → TypeScript | M | ✓ Complete |
| 16 | CSS design tokens + Stylelint | M | Not started |
| 17 | jQuery migration (incremental) | XL | Planning only |
| 18 | Overdue TODO cleanup | S | Not started |

**Recommended sequence:**
7 → 9 → 18 → 10 → 11 → 12 → 14 → 16 → 13 → 15 → 17

Phases 7, 9, 18, and 10 are quick wins that clean up technical debt from
the main modernization. Phases 13, 15, and 17 are multi-week efforts best
deferred until the codebase stabilises at level 9.
