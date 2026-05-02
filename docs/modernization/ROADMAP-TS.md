# Piwigo 16.x — Modernization Roadmap (TypeScript)

TypeScript / frontend-glue modernization work. See [MODERNIZATION.md](MODERNIZATION.md) for architecture context and completed phase summaries; see [ROADMAP-PHP.md](ROADMAP-PHP.md) and [ROADMAP-CSS.md](ROADMAP-CSS.md) for the other tracks.

---

## #1 — ESLint + Prettier

**Status:** Not started &nbsp;|&nbsp; **Size:** S

### Goal

Enforce TypeScript code style and catch common mistakes via ESLint + Prettier as a CI gate. Foundation item — every subsequent TS change lands on a consistent baseline.

### Current state

- `package.json` has Vite 5.4, TypeScript 5.6, Playwright 1.48 — no ESLint, no Prettier, no shared config.
- Code style across `themes/default/js/` and `admin/themes/default/js/` is hand-formatted; drift is unbounded.
- `tsc` catches type errors but not stylistic issues (unused vars, `console.log`, missing return types, etc.).

### Steps

1. **Install.**

   ```bash
   npm i -D eslint @typescript-eslint/parser @typescript-eslint/eslint-plugin \
              prettier eslint-config-prettier eslint-plugin-prettier
   ```

2. **`eslint.config.js`** (flat config, ESLint 9+):

   ```js
   import tseslint from '@typescript-eslint/eslint-plugin';
   import tsparser from '@typescript-eslint/parser';
   import prettier from 'eslint-plugin-prettier';
   import prettierConfig from 'eslint-config-prettier';

   export default [
     { ignores: ['dist/**', 'node_modules/**', '**/plugins/selectize.*'] },
     {
       files: ['**/*.ts'],
       languageOptions: { parser: tsparser, parserOptions: { project: './tsconfig.json' } },
       plugins: { '@typescript-eslint': tseslint, prettier },
       rules: {
         ...tseslint.configs.recommended.rules,
         ...prettierConfig.rules,
         'prettier/prettier': 'error',
         '@typescript-eslint/no-explicit-any': 'warn',
         '@typescript-eslint/explicit-function-return-type': 'off',
         'no-console': ['warn', { allow: ['warn', 'error'] }],
       },
     },
   ];
   ```

3. **`.prettierrc`** matching Pint's PHP style choices: single quotes, 4-space indent for TS, trailing commas (`es5`), 100-char line width.

4. **npm scripts.**

   ```json
   "scripts": {
       "lint": "eslint themes/default/js admin/themes/default/js tests/e2e --ext .ts",
       "lint:fix": "npm run lint -- --fix",
       "format": "prettier --write \"**/*.{ts,json,md,css}\"",
       "format:check": "prettier --check \"**/*.{ts,json,md,css}\""
   }
   ```

5. **Baseline format pass.** Run `prettier --write` once. Commit alone (mechanical, easy to review).

6. **CI gate.** Add `lint` job to `.github/workflows/ci.yml` running `npm ci && npm run lint && npm run format:check`.

7. **Document** in `CONTRIBUTING.md` — point at `npm run lint:fix` and the `format:check` gate.

### Verification

```bash
npm run lint           # exits 0 on a clean tree
npm run format:check   # exits 0
# CI fails any PR with style violations
```

---

## #2 — TypeScript `any` reduction

**Status:** Not started &nbsp;|&nbsp; **Size:** M

### Goal

Reduce `any` escapes in authored TypeScript from the current **468** to **≤250**, focusing on `(window as any)` calls and untyped function parameters. Do not touch vendored `node_modules/` or generated `dist/`.

### Current state

- 468 total `any` patterns across `admin/themes/default/js/` and `themes/default/js/`.
- Largest concentrations: `common.ts` (window globals for plugin interop), `batchManagerGlobal.ts` (legacy data shapes), `user_list.ts` (plugin tab API).

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
# target: ≤ 250
npm run typecheck   # still zero errors
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
