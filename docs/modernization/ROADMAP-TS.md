# Piwigo 16.x — Modernization Roadmap (TypeScript)

TypeScript / frontend-glue modernization work. See [MODERNIZATION.md](MODERNIZATION.md) for architecture context and completed phase summaries; see [ROADMAP-PHP.md](ROADMAP-PHP.md) and [ROADMAP-CSS.md](ROADMAP-CSS.md) for the other tracks.

---

## #1 — TypeScript `any` reduction

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
const pluginSave = (window as Record<string, unknown>)[pluginId + '_save'] as PluginSaveCallback | undefined;
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

## #2 — Eliminate remaining `window.*` data-bridge globals in `{footer_script}` blocks

**Status:** Not started &nbsp;|&nbsp; **Size:** M

### Goal

Remove all `window.foo = value` data-bridge assignments in Smarty `{footer_script}` blocks. Each surviving assignment is a gap in the TypeScript module graph — the data is invisible to `tsc` and PHPStan. Replace each with either a `<script type="application/json">` page-data block (for structured data) or a `data-*` attribute (for single values).

### Current state

**20 remaining assignments** in `admin/themes/default/template/` (0 in `themes/default/template/` — frontend is already clean).

Key clusters:

| Template | Globals | Pattern |
|----------|---------|---------|
| `batch_manager_global.tpl` | `window.lang`, `window.all_elements`, `window.str_*`, `nb_thumbs_page`, `nb_thumbs_set` | page-data JSON block |
| `picture_modify.tpl` | `window.related_categories_ids`, `window.str_are_you_sure`, `window.url_delete`, `window.str_*` | mix of page-data + data-attrs |
| `admin.tpl` | `window.str_root`, `window.pwg_token` | page-data JSON block |
| `user_list.tpl` | `window.str_*` (user confirmation strings) | page-data JSON block |

### Steps

For each cluster:

1. **Add a `<script type="application/json" id="pwg-<page>-data">` block** to the PHP controller's `page_data_json` array (pattern established in `batch_manager_unit.php`).

2. **Read from `getPageData<T>('pwg-<page>-data')`** in the corresponding TS file.

3. **Remove the `window.*` assignments** from the `{footer_script}` block. If the block becomes empty, remove the entire `{footer_script}` / `{/footer_script}` pair.

4. For single-element targets (e.g., `window.url_delete` used as an `href`), prefer `data-url-delete="…"` on the triggering element and read it from `dataset` in the TS handler.

### Verification

```bash
grep -rn "^window\." admin/themes/default/template/ --include="*.tpl" \
  | grep -v "window\.location\|window\.open\|window\.confirm"
# must return empty
npm run typecheck    # still zero errors
npm run build        # clean
```
