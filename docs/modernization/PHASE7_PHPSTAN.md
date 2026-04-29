# Phase 7 — PHPStan level 9 / baseline elimination (L)

## Goal

Delete `phpstan-baseline.neon` and raise PHPStan from level 8 (with a baseline
suppressing ~1 412 errors as of the Phase 8 close) to level 9 with
`treatPhpDocTypesAsCertain: true` and zero suppressed errors. This makes static
analysis genuinely effective: every new file is fully checked, every type error
is a CI failure, and the `phpstan-strict-rules` and `phpstan-deprecation-rules`
packages already installed in `phpstan.neon` become load-bearing rather than
silenced.

**Starting state (current):** level 8, baseline 1 412 errors, PHPStan exits 0
because every remaining error is suppressed. The baseline was reduced from the
original ~22 279 lines (Phase 6 exit) to 1 412 during Phases 7–8 work.

---

## Step-by-step sequence

### Preparation (before touching phpstan.neon)

1. **Freeze the baseline.** The CI `check-baseline.sh` guard is already wired.
   Confirm it passes on the current branch. Any new error added during Phase 7
   work is a regression, not a baseline entry — fix it before proceeding.

2. **Slice the baseline by namespace.** Run:
   ```bash
   grep "path:" phpstan-baseline.neon | sed 's/.*path: //' | sort | uniq -c | sort -rn | head -30
   ```
   This shows which files contribute the most suppressions. Work from
   highest-count files downward within each namespace slice.

### Namespace slices (recommended order)

3. **`src/Piwigo/Core/`** — typed services already have unit tests. Errors here
   are typically `mixed` return types on `Config::get()` propagating into typed
   contexts. Fix by narrowing call sites or adding `@phpstan-var` inline casts
   only where PHPStan cannot infer.

4. **`src/Piwigo/Template/`** — Smarty adapter code. Errors are typically
   `mixed` from Smarty's loosely-typed plugin API. Narrow `Template::assign()`
   callers or add method-level `@param` phpdoc where the shape is knowable.

5. **`src/Piwigo/Ws/`** — web service handlers. Errors are typically `mixed`
   flowing from `$_GET`/`$_POST` through the WS parameter extraction. The
   `input_*` helpers from Phase 2 already coerce many of these; wire remaining
   call sites.

6. **`include/functions.inc.php` and friends** — the largest single-file error
   contributor. Work one function at a time. Common patterns:
   - `pwg_db_fetch_assoc()` returns `array<string,mixed>|false` — add
     null-checks at call sites.
   - `array_map()` / `array_filter()` return types losing specificity — use
     `@return` phpdoc or the `list<>` / `array<int,T>` narrowing syntax.
   - Implicit `mixed` from `unserialize()` — add a type-guard
     (`is_array($v) ? $v : []`).

7. **`admin/`** — largest directory by file count. Batch-process: run PHPStan
   on one admin file at a time (`--paths admin/admin.php`) and fix errors before
   moving on.

### Level bump

8. **Bump to level 9 and enable `treatPhpDocTypesAsCertain: true`.** Only after
   the baseline entry count reaches zero at level 8. Do this as a single commit:
   ```diff
   --- a/phpstan.neon
   +++ b/phpstan.neon
   @@ -1,3 +1,4 @@
    parameters:
   -    level: 8
   +    level: 9
   +    treatPhpDocTypesAsCertain: true
   ```
   Let PHPStan emit the new level-9 errors. Expect ~200–500 new errors, mostly:
   - `never` return type inference on unreachable branches.
   - Phpdoc `@return` types claimed as certain that PHPStan can now disprove.
   - Narrower `mixed` handling inside conditionals.
   Fix each in its own commit. Do not add baseline entries.

9. **Delete the baseline.** Once level 9 exits 0:
   ```bash
   rm phpstan-baseline.neon
   # remove the includes: block from phpstan.neon
   vendor/bin/phpstan analyse --no-progress  # must exit 0
   ```
   Update `tools/check-baseline.sh` to be a no-op when the file is absent (it
   already handles this — the first `if [ ! -f "$BASELINE" ]` guard exits 0).

---

## Effort breakdown

| Sub-task | Tag |
|---|---|
| Freeze + slice baseline by namespace | S |
| Fix `src/Piwigo/` (Core, Template, Ws, etc.) | M |
| Fix `include/functions.inc.php` and friends | L |
| Fix `admin/` files | L |
| Bump to level 9 + `treatPhpDocTypesAsCertain` | M |
| Fix level-9 new errors | M |
| Delete baseline file + update phpstan.neon | S |

**Phase total: L.**

---

## Risks

- **`treatPhpDocTypesAsCertain: true` can surface false positives.** When
  phpdoc says `@return string` but the function can actually return `null`,
  PHPStan level 9 trusts the phpdoc and will flag callers that guard against
  null. Fix: update the phpdoc, not the null-guard. Never widen a parameter or
  return type just to silence the error — that hides real bugs.
- **Level-9 fixes in `include/` may silently change behaviour** if a
  type-narrowing assumption was wrong. Mitigate: every fix in
  `include/functions.inc.php` or `include/functions_user.inc.php` should be
  accompanied by a PHPUnit assertion or a Playwright spec that exercises the
  fixed path.
- **The baseline shrinks slowly at first.** Each namespace slice takes a day
  or two. Don't try to fix everything in one commit — it makes bisecting
  regressions impossible.
- **New errors may appear during slice work.** Fixing a type in one file can
  expose previously suppressed errors in callers. Treat them as bonus fixes,
  not scope creep.

---

## Verification (exit signal)

1. `vendor/bin/phpstan analyse --no-progress` exits 0 with `level: 9`,
   `treatPhpDocTypesAsCertain: true`, and no `includes: [phpstan-baseline.neon]`
   in `phpstan.neon`.
2. `ls phpstan-baseline.neon` returns "No such file".
3. `bash tools/check-baseline.sh` exits 0 ("No baseline file — nothing to check.").
4. CI lint job green end-to-end (pint + phpstan + baseline guard + conf shape +
   typecheck + build).
5. Unit suite: 84+ tests, 0 failures.
6. Playwright E2E: all specs green against the Docker stack.

---

## Close-out (fill in when shipped)

- Final baseline line count at close: _
- Level reached and `treatPhpDocTypesAsCertain` status: _
- Real bugs found and fixed during baseline elimination: _
- CI run ID confirming green: _
