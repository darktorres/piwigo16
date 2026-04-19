# JS → TypeScript Migration Roadmap

## Context

The codebase has ~74 application JS files. All are already ES-module-native (`import`/`export`). The goal is full TypeScript coverage with `strict: true`.

There are two distinct sub-populations:

- **Admin (52 files)**: Vite-bundled, clean `init(cfg)` pattern, JSON data islands, no Smarty globals. Lives in `admin/themes/default/js/`.
- **Gallery/plugins (~22 files)**: Loaded via direct `<script type="module">` or `{combine_script}`. Depend on Smarty-injected globals (`global_params`, `search_id`, etc.). NOT in the Vite build.

There is currently zero TypeScript in app code. `tsconfig.json` exists only for Playwright tests; `typescript` lives only in `tests/package.json`.

---

## Guiding Principles

- Never rename a file and change its logic in the same commit — rename first, type second.
- Keep `strict: true` from the start; don't use `any` as a crutch.
- Each phase ends with a passing build + typecheck — no phase leaves the repo broken.
- External libs without `@types` get minimal `declare module` stubs, not `any`-casted imports.

---

## Phase 1 — Tooling & Build Setup

**Goal:** Enable TypeScript compilation without breaking anything. All existing `.js` files continue to work unchanged.

1. `bun add -d typescript @types/node` (root `package.json`)
2. `bun add -d @typescript-eslint/eslint-plugin @typescript-eslint/parser` (root `package.json`)
3. Create `tsconfig.app.json` at repo root:
   ```json
   {
     "compilerOptions": {
       "target": "ES2020",
       "lib": ["ES2020", "DOM"],
       "module": "ESNext",
       "moduleResolution": "bundler",
       "strict": true,
       "noEmit": true,
       "skipLibCheck": true
     },
     "include": [
       "admin/themes/default/js/**/*",
       "themes/*/js/**/*",
       "plugins/*/js/**/*"
     ],
     "exclude": ["**/dist/**", "node_modules/**", "tests/**"]
   }
   ```
4. Update `vite.config.js`: allow `.ts` entry points alongside `.js` (resolve order, or update input map to check both extensions as files are renamed).
5. Update `eslint.config.js`: add a TS-specific block using `@typescript-eslint/parser` for `**/*.ts` files; keep existing JS rules for `.js` files during the transition.
6. Add script to `package.json`: `"typecheck": "tsc -p tsconfig.app.json --noEmit"`

**Verification:** `bun run build` passes; `bun run typecheck` runs with zero errors (no `.ts` files yet = nothing to check).

---

## Phase 2 — Ambient Type Declarations

**Goal:** Declare all external-facing types so that converted files can reference globals and third-party libs without errors.

Create `admin/themes/default/js/types/`:

1. **`globals.d.ts`** — Smarty-injected window globals used by gallery/plugin JS:
   ```ts
   declare const global_params: { fields: Record<string, unknown>; [k: string]: unknown };
   declare const search_id: string;
   declare const fullname_of_cat: string;
   declare const prefix_icon: string;
   declare const str_word_widget_label: string;
   declare const str_tags_widget_label: string;
   declare const str_album_widget_label: string;
   declare const str_author_widget_label: string;
   declare const str_added_by_widget_label: string;
   declare const str_filetypes_widget_label: string;
   declare const str_empty_search_top_alt: string;
   declare const str_empty_search_bot_alt: string;
   ```

2. **`vendor.d.ts`** — ambient stubs for libs without `@types`:
   - Install `@types/*` packages where they exist (check tippy.js, tom-select, nouislider, sortablejs, flatpickr, dropzone, cropperjs).
   - Write `declare module 'glightbox'`, `declare module 'piecon'`, `declare module 'jgrowl'`, `declare module 'underscore'` stubs for anything that has no `@types` package.

3. **`piwigo.d.ts`** — shared Piwigo domain types, grown incrementally as modules are converted:
   - Start with `PwigoConfig` (base `init(cfg)` shape), `ImageData`, `AlbumData`.

**Verification:** `bun run typecheck` passes with no errors.

---

## Phase 3 — Convert Shared Utilities (Highest Leverage)

**Goal:** Convert the files imported by the most other modules first so their type signatures propagate downstream.

Files (rename `.js` → `.ts`, then add types):

| File | Key exports |
|------|-------------|
| `admin/themes/default/js/common.js` | `TemporaryState`, `sprintf`, misc utils |
| `admin/themes/default/js/moduleInit.js` | JSON island reader, `initModule` bootstrap |
| `admin/themes/default/js/LocalStorageCache.js` | `CategoriesCache`, `TagsCache` |
| `admin/themes/default/js/pwgConfirm.js` | confirmation dialog |
| `admin/themes/default/js/pwgws.js` | `PwgWS` class |
| `admin/themes/default/js/PwgTree.js` | tree widget |
| `themes/default/js/scripts.js` | `PwgWS`, `pwgAddEventListener` (gallery copy) |

For each file: rename, add parameter/return types, fix `strict` errors. Update `vite.config.js` entry points to reference `.ts` filenames.

**Verification:** `bun run build` + `bun run typecheck` pass.

---

## Phase 4 — Convert Admin Vite-Bundled Modules

**Goal:** Convert all 52 admin modules. These are the safest to convert — clean module boundaries, no globals, all in Vite.

Suggested order (ascending complexity):

**Batch A — Simple:**
`intro`, `maintenance`, `maintenance_env`, `cat_search`, `cat_perm`, `picture_formats`, `updates_ext`, `geoip`, `stats`, `permalinks`, `helpPopin`, `site_manager`, `site_update`, `languages_installed`, `themes_installed`, `themes_new`

**Batch B — Medium:**
`addAlbum`, `cat_modify`, `history`, `plugins_new`, `plugins_installated`, `photos_add_applications`, `photos_add_direct`, `comments`, `admin`, `configuration`, `configuration_comments`, `configuration_sizes`, `configuration_watermark`, `configuration_main`, `menubar`, `generate_video_thumbnails`, `element_set_ranks`, `rating`, `rating_user`, `picture_coi`, `picture_modify`, `user_activity`, `batch_manager_unit`

**Batch C — Complex:**
`album_selector`, `album_notification`, `datepicker`, `doubleSlider`, `cat_list`, `albums`, `tags`, `group_list`, `batchManagerGlobal`, `user_list`

For each file: rename `.js` → `.ts`, type the `init(cfg)` parameter (extend `PwigoConfig` from Phase 2), resolve all `strict` errors. Update `vite.config.js` entry points.

**Verification after each batch:** `bun run build` + `bun run typecheck` pass.

---

## Phase 5 — Bring Gallery & Plugin JS Into Vite Build

**Goal:** Gallery theme files and plugin files are currently loaded directly (not bundled). Add them to the Vite build so they can be TypeScript-compiled.

1. Add gallery theme entries to `vite.config.js` input map:
   - `themes/default/js/mcs.js`, `rating.js`, `scripts.js`, `switchbox.js`
   - `themes/smartpocket/js/smartpocket.js`, `config.js`, `thumb.arrange.js`
   - `themes/bootstrap_darkroom/js/*.js`
2. Add plugin entries:
   - `plugins/AdminTools/js/*.js`
   - `plugins/TakeATour/js/Tour.js`
   - `plugins/rv_tscroller/rv_tscroller.js`
   - `plugins/GDThumb/js/*.js`
3. Update output path strategy in `vite.config.js` to preserve theme/plugin sub-paths.
4. Update Smarty templates to load the Vite-built output (via manifest lookup) instead of raw source paths.
5. Remove `{combine_script}` calls for app JS (keep for external libs).

**Verification:** All pages function; no browser console errors.

---

## Phase 6 — Convert Gallery & Plugin JS

**Goal:** Rename and type the ~22 gallery/plugin files now that they're in the Vite pipeline.

Files:
- `themes/default/js/` → `mcs.ts`, `rating.ts`, `scripts.ts`, `switchbox.ts`
- `themes/smartpocket/js/` → `smartpocket.ts`, `config.ts`, `thumb.arrange.ts`
- `themes/bootstrap_darkroom/js/` → all `.ts`
- `plugins/AdminTools/js/` → all `.ts`
- `plugins/TakeATour/js/Tour.ts`
- `plugins/rv_tscroller/rv_tscroller.ts`
- `plugins/GDThumb/js/` → all `.ts`

Smarty-injected globals (`global_params`, `search_id`, etc.) are covered by Phase 2 `globals.d.ts`.

**Verification:** `bun run build` + `bun run typecheck` pass; all gallery/admin pages function.

---

## Phase 7 — Final Cleanup

**Goal:** Remove all remaining legacy patterns and enable strict TypeScript ESLint rules.

1. **Audit remaining `{combine_script}` for node_modules**: verify each referenced lib is already `import`ed at the module level; remove redundant template tags. ✅ *Partially done — see "Remaining combine_script calls" below.*
2. **Convert 2 remaining inline Smarty→JS variable assignments** to JSON island pattern: ✅ *Done — both were dead code (no TS file referenced `cat_nav`), so removed outright.*
   - `admin/themes/default/template/cat_perm.tpl` — removed
   - `admin/themes/default/template/element_set_ranks.tpl` — removed
3. **Enable type-aware ESLint rules** in `eslint.config.js`:
   - Switch to `@typescript-eslint/recommended-type-checked` for `.ts` files
   - Point TS parser to `tsconfig.app.json`
4. Remove `globals.d.ts` entries that were ESLint workarounds (now enforced by TS).
5. Update `tsconfig.app.json` include paths to cover final file locations.

**Verification:** `bun run lint` + `bun run typecheck` + `bun run build` all pass clean. Zero `.js` files remain in `admin/themes/default/js/` (source), `themes/*/js/`, `plugins/*/js/`.

---

## Remaining `{combine_script}` Calls — Status & Reasons

After Phase 7 step 1, these node_modules `{combine_script}` calls are intentionally kept. Each has a specific reason it cannot simply be removed.

### Removed (redundant)

| Template | Library | Why removed |
|----------|---------|-------------|
| `admin/themes/default/template/inc/add_album.inc.tpl` | `tom-select` | `LocalStorageCache.ts` imports and instantiates TomSelect; bundled in every Vite module that uses the add-album dialog. No inline script uses the global. |
| `themes/default/template/inc/search_filters.inc.tpl` | `tom-select` | Removed in Phase 6 when `mcs.ts` began importing TomSelect via npm. |
| `themes/default/template/search.tpl` | `tom-select` | Converted to `gallery_search.ts` Vite entry; inline `<script>` block moved to the module. |
| `themes/bootstrap_darkroom/template/search.tpl` | `tom-select` | Same — reuses `gallery_search.ts`. |
| `themes/smartpocket/template/search.tpl` | `tom-select` | Same — reuses `gallery_search.ts`. |

### Kept — reason: no Vite entry covers that page

| Template | Library | Reason |
|----------|---------|--------|
| `admin/themes/default/template/inc/colorbox.inc.tpl` | `glightbox` | Included by `themes/modus/admin/modus_admin.tpl`, which has **no Vite entry** (see below). Cannot remove without breaking modus admin. Admin Vite module pages also include this file and double-load GLightbox, but that is harmless. |
| `themes/bootstrap_darkroom/template/add_photos.tpl` | `glightbox` | Public BD gallery page; the existing BD Vite entries (`bd_header`, `bd_theme`, `bd_rating`) do not import GLightbox. An inline `GLightbox({...})` call in the template requires the global. |
| `themes/bootstrap_darkroom/template/add_photos.tpl` | `dropzone` | Same page; Dropzone is used as a global. `photos_add_direct.ts` bundles Dropzone but that is the **admin** upload page, not this public BD page. |
| `themes/bootstrap_darkroom/template/add_photos.tpl` | `piecon` | Same page; no Vite module imports piecon. |
| `themes/bootstrap_darkroom/template/header.tpl` | `bootstrap` (BD local) | Bootstrap JS is required on every BD page for component initialization. No Vite entry imports it. |
| `themes/bootstrap_darkroom/template/tags.tpl` | `wordcloud2` (BD local) | Conditional tag-cloud feature; no Vite entry imports wordcloud2. |
| `themes/bootstrap_darkroom/template/_slick_js.tpl` | `swiper` (BD local) | Picture slideshow; no Vite entry imports swiper. |
| `themes/modus/admin/modus_admin.tpl` | `nouislider` | Modus theme admin has no Vite entries at all (see below). |
| `plugins/AdminTools/template/public_controller.tpl` | `tom-select` | `public_controller.js` uses `TomSelect` as a global (see below). |
| `plugins/AdminTools/template/public_controller.tpl` | `mousetrap` | Same file uses `Mousetrap` as a global (see below). |
| `plugins/LocalFilesEditor/template/admin.tpl` | `codemirror` (×7) | Plugin has no TS files and no Vite entry; CodeMirror is used directly from inline template scripts. |
| `plugins/LocalFilesEditor/template/show_default.tpl` | `codemirror` (×7) | Same plugin. |

---

## Why These Pages Have No Vite Entry

### AdminTools `public_controller.js`

`plugins/AdminTools/template/public_controller.tpl` loads its JS via a **direct filesystem import**:

```smarty
import { AdminTools } from './{$ADMINTOOLS_PATH}template/public_controller.js';
```

This bypasses the Vite build entirely — the file is served as raw JS from the template directory, not from the dist manifest. Because it is not bundled, it cannot resolve npm imports; it declares `/* global TomSelect, Mousetrap */` and relies on the globals provided by `{combine_script}`.

**To fix:** move `plugins/AdminTools/template/public_controller.js` to `plugins/AdminTools/js/public_controller.ts`, add a `['at_public_controller', 'plugins/AdminTools/js/public_controller']` Vite entry, use `import TomSelect from 'tom-select'` and `import Mousetrap from 'mousetrap'` inside the module, then update the template to load via the manifest.

### Modus Theme Admin

The modus theme has no `themes/modus/js/` directory and no Vite entries. Its admin page (`themes/modus/admin/modus_admin.tpl`) uses GLightbox and nouislider via `{combine_script}` the same way the whole codebase did before the migration. The modus theme was out of scope for Phases 5–6.

**To fix:** create `themes/modus/js/` with TypeScript source files, add Vite entries for the modus admin page, import GLightbox and nouislider there, and remove the `{combine_script}` calls.

### Bootstrap Darkroom — Uncovered Libraries

The BD Vite entries (`bd_header.ts`, `bd_theme.ts`, `bd_rating.ts`) were created for the TypeScript migration of specific BD functionality. Several BD libraries were not included:

- **bootstrap.bundle.js**, **swiper**, **wordcloud2** — live in `themes/bootstrap_darkroom/node_modules/` (a separate local node_modules, not the root one) and are not imported by any migrated TS file.
- **dropzone**, **piecon**, **glightbox** on `add_photos.tpl` — this is a public gallery upload page. The admin-side `photos_add_direct.ts` bundles Dropzone for the *admin* upload page; the BD public page is a separate template with separate JS needs that was not given its own Vite entry.

**To fix:** create a `bd_add_photos.ts` entry that imports dropzone, piecon, and glightbox for the BD public upload page; create a `bd_picture.ts` entry for the swiper slideshow; add wordcloud2 to a `bd_tags.ts` entry; add bootstrap to `bd_header.ts` or a new base entry.

### LocalFilesEditor Plugin

This third-party plugin has no JS/TS source files in its directory — all JavaScript is written inline in Smarty templates using CodeMirror as a global. There is no TS file to add an `import CodeMirror from 'codemirror'` statement to.

**To fix:** extract the inline JavaScript into a `plugins/LocalFilesEditor/js/editor.ts`, add a Vite entry, import CodeMirror as an ES module, and remove the `{combine_script}` calls.

---

## File Summary

| File | Action | Phase |
|------|--------|-------|
| `package.json` | Add `typescript`, `@types/node`, `@typescript-eslint/*` | 1 |
| `tsconfig.app.json` | Create | 1 |
| `vite.config.js` | Support `.ts` entries; updated each phase | 1, 3, 4, 5 |
| `eslint.config.js` | Add TS parser block; tighten in Phase 7 | 1, 7 |
| `admin/themes/default/js/types/globals.d.ts` | Create | 2 |
| `admin/themes/default/js/types/vendor.d.ts` | Create | 2 |
| `admin/themes/default/js/types/piwigo.d.ts` | Create, grow | 2–4 |
| 7 shared utility files | Rename + type | 3 |
| 52 admin modules | Rename + type | 4 |
| `vite.config.js` + templates | Add gallery/plugin entries | 5 |
| 22 gallery/plugin files | Rename + type | 6 |
| Templates + ESLint | Final legacy removal | 7 |
