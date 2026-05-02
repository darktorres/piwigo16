# Piwigo 16.x — Modernization Roadmap (TypeScript)

TypeScript / frontend-glue modernization work. See [MODERNIZATION.md](MODERNIZATION.md) for architecture context and completed phase summaries; see [ROADMAP-PHP.md](ROADMAP-PHP.md) and [ROADMAP-CSS.md](ROADMAP-CSS.md) for the other tracks.

**Status snapshot (2026-05-02):** #1 ESLint + Prettier ✅ Done · #2 `any` reduction Not started (479 patterns) · #3 `window.*` data-bridge globals ✅ Done · #4 Vitest Not started · #5 Bundle size budgets Not started · #6 Vendored libs — Tier 2 ✅ Done, Tiers 1/3/4/5 Not started.

---

## #1 — ESLint + Prettier

**Status:** ✅ Done &nbsp;|&nbsp; **Size:** S

### Outcome

ESLint flat config (`eslint.config.ts`) and Prettier (`.prettierrc.json`) are in place; the TS rule set goes beyond the original plan to `tseslint.configs.recommendedTypeChecked` (type-checked linting) plus `strict-boolean-expressions`, `no-unnecessary-condition`, `prefer-nullish-coalescing`, `prefer-optional-chain`, `consistent-type-imports`. `no-explicit-any` is `error` (stricter than the originally proposed `warn`). Prettier rules: 4-space TS indent, single quotes, 100-char width, `es5` trailing commas — matching Pint's PHP style choices.

Recent commits `ba17576e8 build(lint): tighten ESLint and Stylelint rule sets` and `232066e55 fix(lint): scope tseslint type-checked preset to *.ts files` finalized the configuration.

### Verification

```bash
npm run lint           # ESLint flat config runs across .js/.mjs/.cjs and .ts
npm run lint:fix
npm run format         # Prettier write
npm run format:check   # Prettier check
```

### Caveats / follow-ups

- `no-explicit-any: error` is currently undermined by the existing **479** `any` patterns (see #2). `npm run lint` does not yet exit clean on the tree; the rule is enforced for new code via review, not gate.
- No `.github/workflows/` directory exists (this is a personal fork — no CI). Lint is a local pre-commit / manual step, not a merge gate. The original CONTRIBUTING.md doc step is moot for the same reason.

---

## #2 — TypeScript `any` reduction

**Status:** Not started &nbsp;|&nbsp; **Size:** M

### Goal

Reduce `any` escapes in authored TypeScript from the current **479** to **≤250**, focusing on `(window as any)` calls and untyped function parameters. Do not touch vendored `node_modules/` or generated `dist/`.

### Current state

- **479** `: any` / `as any` / `(window as any)` patterns: 440 in `admin/themes/default/js/` (24 files) + 39 in `themes/default/js/` (6 files). Slight increase from the original 468 baseline (drift since the roadmap was first written).
- ESLint `@typescript-eslint/no-explicit-any` is set to `error` in `eslint.config.ts`, so each occurrence is a lint error today. Only one file has an `eslint-disable` for this rule (`group_list.ts`); the rest cause `npm run lint` to fail. Closing this item is what unlocks a clean lint baseline.
- Largest concentrations: `tags.ts` (80), `user_list.ts` (58), `albums.ts` (52), `group_list.ts` (45), `album_selector.ts` (35), `batchManagerUnit.ts` (31), `batchManagerGlobal.ts` (27).
- No `themes/default/js/types/` or `admin/themes/default/js/types/` declaration directory exists yet — Tier 1 hasn't started.

### Approach

**Tier 1 — window globals for plugin interop (~130 instances).** Functions like `applyFontCheckbox`, `array_delete`, `sprintf` are assigned to `window` so Smarty-rendered inline scripts can call them. These should stay on `window` for the plugin contract but can be typed via a declaration file:

```typescript
// src/types/admin-globals.d.ts
interface Window {
  applyFontCheckbox(el: HTMLInputElement): void;
  array_delete<T>(arr: T[], value: T): T[];
  sprintf(format: string, ...args: unknown[]): string;
  TemporaryState: typeof TemporaryState;
  // …
}
```

With the interface in place, replace `(window as any).applyFontCheckbox` with `window.applyFontCheckbox`.

**Tier 2 — untyped plugin callbacks (~80 instances).** Plugin function maps in `batchManagerUnit.ts` and `batchManagerGlobal.ts` use `(window as any)[pluginId + '_save']`. Type as:

```typescript
type PluginSaveCallback = (pictureId: number) => Promise<void> | void;
const pluginSave = (window as Record<string, unknown>)[pluginId + '_save'] as
  | PluginSaveCallback
  | undefined;
```

**Tier 3 — data shape unknowns (~100 instances).** `fetch()` responses typed as `any`. Replace with explicit interfaces for each WS method response shape. Start with the most-used: `pwg.images.search`, `pwg.categories.getList`, `pwg.tags.getList`.

**Keep:** `(window as any).pluginValues`, `(window as any)[pluginId + '_batchManagerSave']` in plugin interop hot-paths — these are acceptable `any` uses where the call target is truly dynamic.

### Steps

1. Create `themes/default/js/types/admin-globals.d.ts` and `themes/default/js/types/ws-responses.d.ts`.
2. Fill in Tier 1 declarations — `npm run typecheck` confirms each file as it is typed.
3. Tier 2: replace cast per file, largest files first (`common.ts`, `batchManagerGlobal.ts`, `user_list.ts`).
4. Tier 3: add WS response interfaces, replace `any` in `fetch().then((data: any) =>` chains.

### Verification

```bash
grep -rn ": any\b\|as any\b\|(window as any)" admin/themes/default/js/ themes/default/js/ --include="*.ts" | wc -l
# current: 479 — target: ≤ 250
npm run typecheck   # still zero errors
npm run lint        # eventually exits 0 once `no-explicit-any` is satisfied
```

---

## #3 — Eliminate remaining `window.*` data-bridge globals in `{footer_script}` blocks

**Status:** ✅ Done &nbsp;|&nbsp; **Size:** M

### Outcome

All `window.foo = value` data-bridge assignments in Smarty `{footer_script}` blocks are gone. The four clusters listed below (and all smaller satellite cases) migrated to `<script type="application/json" id="pwg-<page>-data">` page-data blocks consumed by `getPageData<T>()`, or to `data-*` attributes on triggering elements consumed via `dataset` in the corresponding `.ts`.

The work expanded beyond the original 20 assignments: `{footer_script}` blocks themselves were eliminated codebase-wide as part of `PLAN-inline-assets-extraction.md` (Phases 2-4). Final count: **0 `{footer_script}` blocks** and **0 inline-JS event-handler attributes** across `admin/themes/default/`, `themes/default/`, `themes/standard_pages/`.

### Verification

```bash
grep -rn "^window\." admin/themes/default/template/ --include="*.tpl" \
  | grep -v "window\.location\|window\.open\|window\.confirm"
# returns empty ✓

grep -rn "{footer_script}" admin/themes/default/template/ themes/default/template/ themes/standard_pages/template/ --include="*.tpl"
# returns empty ✓
```

### Original plan (historical)

Initial inventory: **20 remaining assignments** in `admin/themes/default/template/` (0 in `themes/default/template/`). Key clusters:

| Template                   | Globals                                                                                         | Migration                     |
| -------------------------- | ----------------------------------------------------------------------------------------------- | ----------------------------- |
| `batch_manager_global.tpl` | `window.lang`, `window.all_elements`, `window.str_*`, `nb_thumbs_page`, `nb_thumbs_set`         | page-data JSON block          |
| `picture_modify.tpl`       | `window.related_categories_ids`, `window.str_are_you_sure`, `window.url_delete`, `window.str_*` | mix of page-data + data-attrs |
| `admin.tpl`                | `window.str_root`, `window.pwg_token`                                                           | page-data JSON block          |
| `user_list.tpl`            | `window.str_*` (user confirmation strings)                                                      | page-data JSON block          |

Pattern applied per cluster: PHP controller pushes structured values into `page_data_json[$key]`; template emits one `<script type="application/json" id="pwg-<page>-data">` block; `.ts` module reads via `getPageData<PageData>('pwg-<page>-data')`. For single-element targets (e.g., `url_delete` used as an `href`), `data-*` on the triggering element was preferred over a JSON island.

---

## #4 — Vitest unit tests

**Status:** Not started &nbsp;|&nbsp; **Size:** M

### Goal

Add a unit-test framework for non-DOM TypeScript logic. Today the only JS test infrastructure is Playwright E2E — useful for end-to-end flows but slow and high-friction for testing pure functions like validators, formatters, URL builders, and state reducers.

### Current state

- `package.json` contains Playwright 1.48 only — 15 E2E specs in `tests/e2e/`.
- No Vitest, Jest, or other unit-test runner.
- Pure-logic candidates with no test coverage today: number/date formatters in `common.ts`, URL builders, batch-manager state transitions in `batchManagerGlobal.ts`, validators in `user_list.ts`, the `getPageData` helper.

### Steps

1. **Install.**

   ```bash
   npm i -D vitest @vitest/coverage-v8 happy-dom
   ```

2. **`vitest.config.ts`.**

   ```ts
   import { defineConfig } from 'vitest/config';

   export default defineConfig({
     test: {
       environment: 'node',
       include: ['themes/default/js/**/*.test.ts', 'admin/themes/default/js/**/*.test.ts'],
       environmentMatchGlobs: [['**/*.dom.test.ts', 'happy-dom']],
       coverage: {
         provider: 'v8',
         reporter: ['text', 'html'],
         include: ['themes/default/js/**/*.ts', 'admin/themes/default/js/**/*.ts'],
         exclude: ['**/*.test.ts', '**/types/*.d.ts', '**/plugins/**'],
         thresholds: { lines: 50, functions: 50, branches: 40 },
       },
     },
   });
   ```

3. **First wave — pure utility tests.** Co-locate `<source>.test.ts` next to each module:
   - `common.test.ts` — format/parse helpers
   - `urls.test.ts` — URL builders
   - `getPageData.test.ts` — JSON page-data extraction (uses happy-dom)

4. **Second wave — state reducers.** `batchManagerGlobal.test.ts` covers the reducer-shaped functions that compute selection state, filter results, etc. These are pure given a snapshot.

5. **CI job.** Append to `.github/workflows/ci.yml`:

   ```yaml
   - run: npm run test:unit -- --coverage
   ```

6. **`npm scripts.**

   ```json
   "test:unit": "vitest run",
   "test:unit:watch": "vitest",
   "test:unit:ui": "vitest --ui"
   ```

7. **Threshold.** Start at 50% line/function coverage; raise to 70% after the first wave. Track in `package.json` so CI fails on regression.

### Verification

```bash
npm run test:unit              # all green
npm run test:unit -- --coverage   # ≥ 50% line coverage
```

---

## #5 — Bundle size budgets

**Status:** Not started &nbsp;|&nbsp; **Size:** S

### Goal

Per-entrypoint bundle size budgets gate every PR. Regressions block merge. Bundle composition is visualizable as a CI artifact for debugging.

### Current state

- `vite.config.ts` defines 39 TypeScript entrypoints across admin and frontend.
- No size tracking. No alerts on bloat. No way to detect a careless `import` of a 200 kB lib.
- Manifest output is in `dist/` after `npm run build` — already structured for size-checking.

### Steps

1. **Install.**

   ```bash
   npm i -D size-limit @size-limit/file vite-bundle-visualizer
   ```

2. **`.size-limit.json`.** Per-entrypoint budgets (sizes are illustrative — set after a baseline build):

   ```json
   [
     { "name": "admin/admin", "path": "dist/assets/admin-*.js", "limit": "85 kB" },
     { "name": "admin/batchManager*", "path": "dist/assets/batchManager*-*.js", "limit": "60 kB" },
     {
       "name": "admin/picture_modify",
       "path": "dist/assets/picture_modify-*.js",
       "limit": "55 kB"
     },
     { "name": "themes/default/script", "path": "dist/assets/script-*.js", "limit": "45 kB" }
   ]
   ```

   `size-limit` measures gzipped size by default — that's the relevant transfer cost.

3. **Baseline.** Run `npm run build && npx size-limit` once to record current sizes; set budgets ~5–10% above today's numbers to allow normal drift.

4. **CI job.** After build:

   ```yaml
   - run: npm run build
   - run: npx size-limit
   ```

   Failure = PR cannot merge until either the budget is justified-and-raised in `.size-limit.json` (with rationale in the PR description) or the change is reworked.

5. **Optional visualizer.** On the `main` push (not every PR), run `vite-bundle-visualizer` and upload the HTML as a workflow artifact. Use to debug regressions: which dep got pulled in, which module bloomed.

6. **Document** the budget-change policy in `CONTRIBUTING.md`: "Raising a budget requires a one-line rationale citing the trade-off."

### Verification

```bash
npm run build && npx size-limit    # all entrypoints within budget
```

A PR that adds `import _ from 'lodash'` (without tree-shaking) is rejected by the CI gate.

---

## #6 — Migrate vendored frontend libraries to npm

**Status:** Not started &nbsp;|&nbsp; **Size:** L

### Goal

Replace 3rd-party JS/CSS libraries currently checked into the repo under `plugins/`, `admin/themes/`, and `themes/standard_pages/fonts/` with versioned npm dependencies. Outcome: a single canonical version per library, no ~12 MB of stale `video-js-{4,5,6,7}` mirrors, and a clean Stylelint/ESLint scope (vendor stops appearing in lint output by virtue of being in `node_modules/`).

### Current state — vendored inventory

| Lib                          | Location                                                                                                                                               |                  Pinned version | Approx size | npm package                                                                                                                                               |
| ---------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------ | ------------------------------: | ----------: | --------------------------------------------------------------------------------------------------------------------------------------------------------- |
| video.js (×4 mirrors)        | `plugins/piwigo-videojs/video-js-{4,5,6,7}/`                                                                                                           | 4.12.15 / 5.x / 6.12.1 / 7.21.5 |      ~12 MB | `video.js` (8.x)                                                                                                                                          |
| Leaflet                      | `plugins/piwigo-openstreetmap/leaflet/leaflet.js`                                                                                                      |                **0.7.7** (2015) |     ~135 KB | `leaflet` (1.9+)                                                                                                                                          |
| Leaflet plugins (×8)         | `plugins/piwigo-openstreetmap/leaflet/*` (MarkerCluster, Search, Elevation, MiniMap, contextmenu, providers, EditInOSM, omnivore, jcarousel, qleaflet) |                     all 0.7-era |     ~500 KB | `leaflet.markercluster`, `leaflet-search`, `leaflet.contextmenu`, `leaflet-providers`, `leaflet.elevation`, `leaflet-control-minimap`, `leaflet-omnivore` |
| CodeMirror                   | `plugins/LocalFilesEditor/codemirror/`                                                                                                                 |                  ~v2 (1915 LOC) |      ~70 KB | `codemirror` (v6 — full rewrite; `codemirror@5` for low-risk path)                                                                                        |
| Open Sans webfont            | `admin/themes/default/fonts/open-sans/`                                                                                                                |        locally generated subset |     ~250 KB | `@fontsource/open-sans`                                                                                                                                   |
| Open Sans variable font      | `themes/standard_pages/fonts/OpenSans-VariableFont_wdth,wght.ttf`                                                                                      |               Google Fonts dump |     ~340 KB | `@fontsource-variable/open-sans`                                                                                                                          |
| jQuery tablesorter           | `plugins/nbc_ThemeChanger/include/jquery.tablesorter.js`                                                                                               |                         ancient |      ~15 KB | `tablesorter`                                                                                                                                             |
| jquery.addtags (token-input) | `plugins/user_tags/js/jquery.addtags.js`                                                                                                               |               packed/obfuscated |       ~3 KB | replace with `tom-select` (already a Piwigo dep) — drops the jQuery dependency                                                                            |

**Stays as static asset (cannot move to npm):**

- Fontello custom-glyph subsets in `admin/themes/default/fontello/`, `themes/default/vendor/fontello/`, `plugins/piwigo-openstreetmap/fontello/`. These are project-specific glyph builds from fontello.com, not packageable.
- Bundled themes (`themes/elegant`, `themes/modus`, `themes/smartpocket`, `themes/bootstrap_darkroom`) — themes, not libs; out of 16.x core scope per ROADMAP-CSS.
- `themes/default/js/plugins/piecon.ts` — already authored TS, ~100 LOC, no maintenance burden.

**Already migrated:** PHP libs (`smarty`, `phpmailer`, `minify`, `pclzip`, `feedcreator`, `jshrink`, `passwordhash`, `mdetect`, `emogrifier`, `phpqrcode`) all moved to Composer in 16.x. `pint.json`'s `exclude` still lists them — stale entries; harmless, worth a one-line cleanup.

### Tiers (recommended order)

**Tier 1 — Quick wins (S, ≤1 day each).**

1. `@fontsource/open-sans` replaces `admin/themes/default/fonts/open-sans/`. Vite serves the WOFF2/CSS via npm; delete the in-repo dir.
2. `@fontsource-variable/open-sans` replaces `themes/standard_pages/fonts/OpenSans-VariableFont_wdth,wght.ttf`.
3. `tablesorter` replaces `plugins/nbc_ThemeChanger/include/jquery.tablesorter.js`.
4. **`tom-select` swap for `user_tags/jquery.addtags.js`** — already a Piwigo dep; deletes the jQuery dependency for that plugin entirely. Convert `init.php`/templates to load the Piwigo-shared tom-select bundle.

**Tier 2 — Stylelint / ESLint scope cleanup (XS, parallel to Tier 1). ✅ Done.**

`.stylelintrc.json` already ignores `plugins/**`, `admin/themes/default/fonts/**`, `themes/default/vendor/fontello/**`, and `themes/default/js/plugins/**`, which subsumes the originally-listed vendor paths (videojs / leaflet / codemirror / open-sans). `eslint.config.ts` likewise ignores `plugins/**`, `themes/default/js/plugins/selectize.*`, the bundled themes, and the PHP vendor paths. No further config additions are needed for this tier; when Tier 3/4/5 delete the vendor dirs the ignores can stay (they still cover Piwigo plugin code) or be narrowed.

**Tier 3 — video.js consolidation (M).**

Drop `video-js-4` and `video-js-5` outright (vintage admins almost certainly absent in 16.x install base). Pin npm `video.js@7` (or `@8` if a smoke pass on the test gallery passes). Port `videojs.thumbnails` / `videojs.watermark` / `videojs.zoomrotate` / `videojs-resolution-switcher` to their npm equivalents. Net: ~12 MB removed from repo, single version to maintain.

**Tier 4 — Leaflet 0.7 → 1.9 (L).**

Highest blast-radius. Plan:

1. Audit which Leaflet plugins `osmmap.php`/`osmmap2.php`/`osmmap3.php`/`osmmap4.php` actually call (some bundled plugins may be dead weight).
2. Stand up `leaflet@1.9` + `leaflet.markercluster` + `leaflet-search` on a feature branch. The 0.7→1.x core-API delta is mostly tile-layer/marker construction; plugin APIs vary.
3. Replace `Leaflet.Elevation-0.0.2` → `@raruto/leaflet-elevation`, `Control.MiniMap.js` → `leaflet-control-minimap`, `leaflet-omnivore.min.js` → `leaflet-omnivore`. `qleaflet.jquery.js` is a thin jQuery wrapper — drop or rewrite without jQuery.
4. Smoke-test all four `osmmap*.php` entry pages and the gallery picture-page map widget across a few sample galleries with multi-marker clusters.

**Tier 5 — CodeMirror (M).**

Two paths:

- **Low risk:** `codemirror@5` (still maintained as a legacy line). Drop-in close to v2; `LocalFilesEditor`'s wiring needs minor edits.
- **Long term:** `codemirror@6` — rewrite. Different module shape, separate language packages (`@codemirror/lang-php`, etc.). Better future but full editor re-init.

Pick one based on appetite; v5 is the recommended default unless someone wants to invest.

### Verification

After each tier:

```bash
# Vendor disappears from the working tree
git ls-files plugins/piwigo-videojs/video-js-{4,5}/        # empty (after Tier 3)
git ls-files plugins/piwigo-openstreetmap/leaflet/         # contains only Piwigo-authored glue (after Tier 4)
git ls-files plugins/LocalFilesEditor/codemirror/          # empty (after Tier 5)
git ls-files admin/themes/default/fonts/open-sans/         # empty (after Tier 1.1)

# Dependencies show up where they belong
jq '.dependencies' package.json | grep -E "video.js|leaflet|codemirror|@fontsource"

# Lint output stops mentioning vendor paths
npm run lint:css 2>&1 | grep -E "(piwigo-videojs|piwigo-openstreetmap|codemirror|open-sans)" # empty

# Bundle still builds + smoke-tests pass
npm run build
npx playwright test
```

### Notes

- The Stylelint ignore additions in **Tier 2** can land immediately as a single config commit; they're a no-op on the underlying code and stop noise in the meantime.
- Tier 3+ should each produce two commits: one to add the npm dep + glue, one to delete the vendor dir. Two commits make the actual replacement reviewable; the deletion commit is otherwise a 12 MB diff that hides the real change.
