# Modernization plan — pending work

Phases 0–5 and Phase 8 are complete. Phase 4 Wave C shipped. Phase 6 is mostly done; Phase 7 is the active work item.

---

## Phase 6 — unconfirmed cleanup steps

No explicit close-out was written for Phase 6. Steps below have no completion evidence:

- **Step 6 — Delete `GlobalsBridge.php` / `ConfProxy`**: do after deprecation logs confirm quiet. Wave C is live so every plugin `$conf` access still pays a `debug_backtrace` cost. When logs are clean, delete `src/Piwigo/Core/GlobalsBridge.php` and the `installAsConfProxy()` call in `Kernel::boot()`.
- **Step 7 — Static dblayer include**: `Config::dbLayer()` always returns `'mysqli'` but `common.inc.php` still uses a dynamic string-interpolation include, making the ~70 `pwg_*` functions invisible to PHPStan. Replace with a static `include`.
- **Step 9 — Delete `tests/e2e/global-setup.js`**: stale CJS leftover alongside the canonical `global-setup.ts`.
- **Step 10 — Add `npm run clean` script**: `"clean": "node -e \"require('fs').rmSync('dist',{recursive:true,force:true}); require('fs').rmSync('_data/combined',{recursive:true,force:true});\""` — prevents stale concat artifacts.
- **Step 11 — E2E TypeScript typecheck in CI**: `tests/e2e/` is excluded from `tsconfig.json`; create `tests/e2e/tsconfig.json` and add `tsc --noEmit -p tests/e2e/tsconfig.json` to the `typecheck` script.
- **Step 12 — Wire `check-baseline.sh` and `check-conf-shape.php` into CI**: both tools exist in `tools/` but are not in `.github/workflows/ci.yml`. Add as steps in the `lint` job after PHPStan.

---

## Phase 7 — PHPStan baseline elimination (active, WIP)

**Current state:** level 8, baseline **1,412 errors**.  
**Target:** level 9, `treatPhpDocTypesAsCertain: true`, no baseline file.  
**Full plan:** `docs/modernization/MODERNIZATION_PLAN_2.md`.
