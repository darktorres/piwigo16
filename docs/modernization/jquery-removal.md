# jQuery Removal Plan

## Context

The project has a modern TypeScript + Vite stack but all 30 admin TS files and the frontend TS files still depend on jQuery 1.11.3 (vendored at `themes/default/js/jquery.min.js`). jQuery is not bundled by Vite — it's served via a hardcoded `$known_paths` fallback in `ScriptLoader.php`. The goal is to remove jQuery entirely and replace its usage with native DOM APIs and modern npm packages.

## npm replacements

| Replacing | With | npm package |
|---|---|---|
| Selectize | Tom Select (same author, modern successor) | `tom-select` |
| jQuery UI Datepicker + timepicker-addon | Flatpickr | `flatpickr` |
| jQuery UI Slider / doubleSlider | noUiSlider | `nouislider` |
| jQuery UI Sortable | Sortable.js | `sortablejs` |
| jQuery UI Effects | CSS transitions (no package) | — |
| Colorbox lightbox | GLightbox | `glightbox` |
| jquery-confirm dialogs | Native `<dialog>` element | — |
| Plupload file upload | Uppy | `@uppy/core` + `@uppy/dashboard` |
| Jcrop image crop | Cropper.js | `cropperjs` |
| TipTip / Cluetip tooltips | Tippy.js | `tippy.js` |
| jGrowl notifications | Notyf | `notyf` |
| jQuery.cookie | js-cookie | `js-cookie` |
| jQuery AjaxManager | Native fetch queue (inline) | — |
| jQuery DataTables | DataTables 2.x (no jQuery mode) | `datatables.net` |
| jQuery.sort plugin | `Array.sort()` + `el.append()` | — |
| jQuery Autogrow textarea | `field-sizing: content` CSS | — |
| jQuery Progressbar | Native `<progress>` element | — |
| Underscore.js | Native Array/Object methods | — |
| Moment.js | Day.js | `dayjs` |
| Chart.js 2.x | Chart.js 4.x | `chart.js` |

Stays vendored (no npm equivalent, usage isolated): `jquery.cluetip.js` (only in install/intro templates).

## Migration waves

Earlier waves unblock later ones.

### Wave 1 — Pure DOM files (no plugin dependencies)

Files using only `$()`, `.on()`, `.find()`, `.attr()`, `.val()`, `.text()`, `.addClass/removeClass/hasClass/toggleClass`, `.closest()`, `.append()`. Map to `querySelector`, `addEventListener`, `getAttribute`, `.value`, `.textContent`, `.classList`, `.closest()`, `.append()`. No new packages needed.

Files: `addAlbum.ts`, `album_selector.ts`, `albums.ts`, `cat_search.ts`, `comments.ts`, `history.ts`, `maintenance.ts`, `maintenance_env.ts`, `maintenance_sys.ts`, `picture_formats.ts`

### Wave 2 — Simple plugin replacements

- `cat_list.ts`, `tags.ts`, `group_list.ts`, `user_list.ts` — replace `jQuery.cookie` with `js-cookie`
- `cat_modify.ts`, `tags.ts`, `common.ts` (footer tooltips) — replace `tipTip()` with `Tippy.js`
- `common.ts` — replace `$.confirm()` (jquery-confirm) with native `<dialog>`; remove `$.fn` plugin definition
- `plugins_installed.ts` — replace `manageAjax` queue with `Promise`-based fetch chain
- `thumbnails.loader.ts` — replace `manageAjax` + jQuery DOM with vanilla fetch + `querySelectorAll`
- `switchbox.ts` — replace `.position()` / `.outerWidth()` / `.toggle()` / `.hide()` with `getBoundingClientRect()` + `classList`

### Wave 3 — jQuery UI replacements

- `batchManagerFilter.ts`, `doubleSlider.ts`, `user_list.ts` — replace `slider()` with `<input type="range">` or noUiSlider
- `batchManagerUnit.ts` — replace `sortable()` with Sortable.js; replace `effect('blind')` with CSS transition
- `datepicker.ts` — replace `datepicker()` + `timepicker-addon` with Flatpickr (handles both date + time)
- `intro_tooltips.ts` — replace `cluetip()` with Tippy.js

### Wave 4 — Heavy plugins

- `photos_add_direct.ts` — replace Plupload with Uppy
- `picture_modify.ts` — replace `Jcrop()` with Cropper.js; replace `selectize()` with Tom Select
- `batchManagerGlobal.ts` — replace `$.ajax()` with `fetch()` + replace colorbox with GLightbox
- `stats.ts` — upgrade Chart.js 2→4 + Moment.js→Day.js (already no jQuery in this file)
- `plugins_new.ts` — replace `$.sort` with `Array.sort` + native DOM; replace `effect()` with CSS

### Wave 5 — Frontend templates + remaining

- Replace Colorbox in `include/colorbox.inc.tpl` templates with GLightbox
- Replace jQuery DataTables in `rating_user.tpl` with DataTables 2.x vanilla mode
- Replace Selectize in remaining templates with Tom Select
- Remove jQuery Autogrow — replace with `field-sizing: content` CSS (supported in modern browsers)

## Status (as of 2026-04-29)

**Waves 1–6 complete. jQuery fully removed.**

All 42 TypeScript files are jQuery-free. All Smarty templates have been converted from inline jQuery to vanilla JS. jQuery and all vendored jQuery plugins have been deleted from the codebase.

### Wave 6 — Remove jQuery itself ✓

- `ScriptLoader.php`: removed `'jquery'`, `'jquery.ui'`, `'jquery.ui.effect'` from `$known_paths`; removed `$ui_core_dependencies`; removed jQuery-specific auto-dependency logic from `fill_well_known`
- All header templates: removed `{combine_script id="jquery"}` 
- All plugin `{combine_script}` calls replaced with Vite-bundled npm equivalents
- New Vite entries: `glightbox-admin`, `lightbox`, `mcs`, `rating_user`, `picture_coi`
- Deleted vendored files: `themes/default/js/jquery.js`, `jquery.min.js`, `jquery.cookie.js`, `ui/`, `plugins/colorbox/`, `plugins/datatables/`, `plugins/plupload/`, and all jQuery plugins
- `package.json`: removed `@types/jquery`, `@types/jqueryui` from devDependencies; added `datatables.net`
- `tsconfig.json`: removed jquery/jqueryui type references
- `mcs.js` → `mcs.ts` (2,334 lines, Tom Select replaces Selectize, fetch replaces jQuery.ajax)
- `picture_coi.tpl`: Jcrop replaced with native drag-select crop overlay (picture_coi.ts)
- `rating_user.tpl`: jQuery DataTables 1.x replaced with DataTables 2.x vanilla mode

## Critical files

- `src/Piwigo/Template/ScriptLoader.php` — `$known_paths`, manifest resolution
- `vite.config.ts` — add new npm entry points for each replacement package
- `themes/default/template/header.tpl`, `admin/themes/default/template/header.tpl`
- All 30 `admin/themes/default/js/*.ts` files
- `themes/default/js/scripts.ts`, `switchbox.ts`, `thumbnails.loader.ts`

## Verification per wave

- `npm run typecheck` — TypeScript strict check (remove `@types/jquery` early to force type errors on remaining usages)
- `npx playwright test` — E2E smoke suite covers gallery, admin, upload
- Manual browser: admin panel interactions (batch manager, photo upload, date pickers, confirmations)
