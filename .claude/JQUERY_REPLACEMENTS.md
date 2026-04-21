# jQuery Plugin Replacement Tasks

## Tier 1 — Trivial (drop-in, ≤ half day each)

- [x] **autogrow** — replace with vanilla `input` listener in both autosize tpls
  - Files: `admin/themes/default/template/inc/autosize.inc.tpl`, `themes/default/template/inc/autosize.inc.tpl`
  - Remove `{combine_script id='jquery.autogrow' ...}` tag
  - Add: `el.addEventListener('input', () => { el.style.height='auto'; el.style.height=el.scrollHeight+'px'; })`

- [x] **lightAccordion** — rewrite inline plugin as vanilla in `admin.tpl`
  - File: `admin/themes/default/template/admin.tpl`
  - Remove `jQuery.fn.lightAccordion` definition + `$('#menubar').lightAccordion()` call
  - Replace with ~15-line `querySelectorAll`+`addEventListener` block

- [x] **cluetip** — replace with custom vanilla tooltip (~30 lines per file)
  - Files: `admin/themes/default/template/languages_new.tpl`, `install.tpl`, `intro.tpl`
  - Remove `{combine_script id='jquery.cluetip' ...}` tags
  - Parse `el.title.split('|')`, create/destroy floating `<div>` on `mouseover`/`mouseleave`

- [x] **fadeOut / fadeIn / fadeTo** — replace with Web Animations API / CSS transitions
  - `admin/themes/default/js/plugins_installated.js:712,714`
  - `admin/themes/default/template/rating_user.tpl:176,186`
  - `group_list.tpl` and `rating.tpl` already guarded (no change needed)
  - Skip `plugins/AdminTools/` (third-party plugin)

- [x] **checkboxradio** (SmartPocket) — replace with CSS-only or native checkbox refresh
  - File: `themes/smartpocket/js/mcs_sp.js`

---

## Tier 2 — Standard effort (1–2 days each)

- [x] **tipTip** — replace with Tippy.js
  - Install: `npm install @popperjs/core tippy.js`
  - Remove all CDN `{combine_script id='jquery.tipTip' ...}` tags (6 tpl files)
  - Add one `{combine_script id='tippy'}` in `footer.tpl`
  - Replace every `.tipTip({ maxWidth, content })` call with `tippy(el, { content })` in:
    - `albums.js`, `history.js`, `cat_modify.js`, `plugins_installated.js`, `plugins_new.js`
    - `batchManagerGlobal.js`, `user_list.js`, `footer.tpl`, `element_set_ranks.tpl`
    - `configuration_main.tpl`, `user_list.tpl`, `search_filters.inc.tpl`

- [x] **jQuery UI sortable** — replace with SortableJS
  - Install: `npm install sortablejs`
  - File `admin/themes/default/template/menubar.tpl`:
    - `jQuery('.menuUl').sortable({axis:'y', opacity:0.8})` → `new Sortable(el, {animation:150})`
    - `jQuery('.menuUl').sortable('toArray')` → `sortable.toArray()`
  - File `admin/themes/default/template/element_set_ranks.tpl`:
    - `jQuery('ul.thumbnails').sortable({...})` → `new Sortable(el, {...})`

- [x] **jquery-confirm** — replace with native `<dialog>` + `pwgConfirm()` utility
  - Create: `admin/themes/default/js/pwgConfirm.js` (~40 lines, `<dialog>`-based)
  - Update `admin/themes/default/js/common.js`: rewrite `jQuery.fn.pwg_jconfirm_follow_href` to call `pwgConfirm()` instead of `$.confirm()`
  - Replace every `$.confirm({...})` / `jQuery.confirm({...})` call in:
    - `tags.js`, `user_list.js`, `plugins_installated.js`, `albums.js`, `group_list.js`
    - `picture_formats.js`, `cat_modify.js`, `rating_user.tpl`, `updates_ext.tpl`
    - `batch_manager_global.tpl`, `maintenance_actions.tpl`, `site_manager.tpl`
    - `languages_installed.tpl`, `picture_modify.tpl`, `themes_installed.tpl`
  - Remove all `{combine_script id='jquery.confirm' ...}` tags (~14 tpl files)

- [x] **DataTables** — upgrade to DataTables 2.x standalone
  - Install: `npm install datatables.net-dt`
  - File: `admin/themes/default/template/rating_user.tpl`
  - Swap script path; remove `require='jquery'` from `{combine_script}`
  - API stays identical (`dataTable({...})`, `.DataTable()`, `.row().remove().draw()`)

---

## Tier 3 — High effort (multiple days each)

- [x] **selectize → Tom Select**
  - Install: `npm install tom-select`
  - Refactor `admin/themes/default/js/LocalStorageCache.js`:
    - Change `selectize($target, opts)` signature to accept native element instead of `jQuery($target)`
    - Replace 4 internal `$target.selectize({...})` calls with `new TomSelect(el, {...})`
    - Return the TomSelect instance instead of the selectize instance
  - Update all callsites that pass `jQuery('[data-selectize=X]')` to pass `document.querySelector(...)`:
    - `batch_manager_unit.tpl`, `batch_manager_global.tpl`, `album_notification.tpl`
    - `cat_perm.tpl`, `group_list.tpl` (tpl + js), `user_list.js`, `plugins_new.js`
    - `addAlbum.js`, `photos_add_direct.tpl`, `rating.tpl`, `picture_modify.tpl`
    - `user_activity.tpl`, `themes/default/js/mcs.js`, `search.tpl` (×3 themes)
  - Replace all `{combine_script id='jquery.selectize' path='node_modules/selectize/...'}` tags

- [x] **colorbox → GLightbox (images) + native `<dialog>` (inline/help modals)**
  - Install: `npm install glightbox`
  - Replace image-preview uses (`.colorbox({photo:true})`, `.colorbox({rel:'group1'})`):
    - `batchManagerGlobal.js:142`, `batch_manager_global.tpl:91`, `batch_manager_unit.tpl:37`
    - `themes_installed.tpl`, `admin.tpl`, `configuration_main.tpl`, `photos_add_applications.tpl`
    - `bootstrap_darkroom/add_photos.tpl`
  - Replace inline/help-popin uses (`.colorbox({inline:true, width:'500px'})`):
    - `picture_modify.tpl`, `batch_manager_global.tpl` help link → native `<dialog>`
  - Rewrite `admin/themes/default/js/addAlbum.js:110` (`.colorbox({inline:true})`) → `<dialog>` show/hide
  - Update / remove `admin/themes/default/template/inc/colorbox.inc.tpl`
  - Remove all `{combine_script id='jquery.colorbox' ...}` tags

- [x] **pwgDatepicker → Flatpickr**
  - Install: `npm install flatpickr`
  - Rewrite `admin/themes/default/js/datepicker.js` (238 lines → ~60-line Flatpickr wrapper)
    - Keep `[data-datepicker]` attribute API so callsites stay the same
    - Implement linked start/end min-max with Flatpickr's `onChange` hook
  - No changes needed in `batchManagerGlobal.js`, `batch_manager_unit.tpl`, `history.tpl`,
    `picture_modify.tpl` (all use `[data-datepicker]` attribute, handled by the wrapper)
  - Remove `{combine_script id='jquery.ui.datepicker-...'}` and Timepicker-Addon script tags

- [x] **pwgDoubleSlider + jQuery UI slider → noUiSlider**
  - Install: `npm install nouislider`
  - Rewrite `admin/themes/default/js/doubleSlider.js` (~60 lines wrapping noUiSlider)
    - Keep `[data-slider=widths]` attribute API
    - `slider.noUiSlider.get()` replaces `.slider("option","values")`
  - Update `admin/themes/default/js/user_list.js` slider init (7 callsites) + value reads (3 callsites)
  - Update `admin/themes/default/js/plugins_new.js` slider init (3 callsites) + value reads (3 callsites)
  - Update `themes/modus/admin/modus_admin.tpl` single slider

- [x] **Jcrop → Cropper.js**
  - Install: `npm install cropperjs`
  - File: `admin/themes/default/template/picture_coi.tpl`
  - Replace `{combine_script id='jquery.jcrop' require='jquery' ...}` with Cropper.js script tag
  - Replace `jQuery("#jcrop").Jcrop({...})` with `new Cropper(img, { cropBoxMovable, crop: fn })`
  - Adapt `jOnChange` coordinate math (Cropper uses absolute px, same as Jcrop)
  - Remove `<link rel="stylesheet" href="node_modules/jquery.Jcrop.js/css/jquery.Jcrop.css">`

- [x] **plupload → Dropzone.js**
  - Install: `npm install dropzone`
  - Files: `admin/themes/default/template/photos_add_direct.tpl`, `themes/bootstrap_darkroom/template/add_photos.tpl`
  - Rewrite upload queue UI using Dropzone (chunked upload config, progress callbacks)
  - PHP upload endpoint unchanged
  - Remove `{combine_script id='jquery.plupload' ...}` and `jquery.plupload.queue` script tags
  - Highest effort — treat as a standalone ticket

---

## Final cleanup (after all above complete)

- [ ] Remove `{combine_script id='jquery'}` from every tpl
  — Blocked: `albums.js` still uses jqTree (jQuery plugin); jqTree replacement needed first
- [ ] Remove `{combine_script id='jquery.ui'}` from every tpl
- [ ] Remove `jquery`, `jquery-migrate` from `package.json` dependencies
  — Blocked: same as above; also `jquery-colorbox` kept for AdminTools plugin
- [x] Delete obsolete packages from `node_modules`:
  `cluetip`, `datatables` (old), `jquery-confirm`, `selectize`,
  `jQuery-Timepicker-Addon`, `jquery.Jcrop.js`, `plupload`, `jquery.cookie`
  — Remaining: `jquery`, `jquery-colorbox` (AdminTools), `jquery-migrate` (BD theme)
- [x] Remove `if (window.jQuery)` guards from converted files:
  `plugins_installated.js`, `batchManagerGlobal.js`, `tags.js`, `common.js`, `plugins_new.js`
  — Remaining guards: `albums.js` (jqTree), `jquery.geoip.js` (deprecated JSONP), `thumb.pop.js` (modus plugin)
- [x] Remove stale `require='jquery'` from `{combine_script}` tags for converted scripts
- [ ] Replace jqTree in `albums.js` with a vanilla tree widget → then remove remaining guards
- [ ] Run full e2e tests across admin and public gallery
