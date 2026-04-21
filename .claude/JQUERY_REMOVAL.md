# jQuery → Vanilla JS: Full Repo Conversion Plan

## Status
- `themes/bootstrap_darkroom/` — **DONE** (~174 usages removed)
- Everything else — **IN PROGRESS**

---

## Scope

### Total jQuery usage found (outside bootstrap_darkroom)
~3,774 matching lines across 88 files. ~97% are directly convertible.

### What gets converted (direct jQuery API calls)

| jQuery | Vanilla JS |
|--------|-----------|
| `$(document).ready(fn)` | `document.addEventListener('DOMContentLoaded', fn)` |
| `$('.sel')` / `jQuery('.sel')` | `document.querySelector` / `querySelectorAll` |
| `.on('click', fn)` / `.click(fn)` | `addEventListener('click', fn)` |
| `.addClass()` / `.removeClass()` / `.toggleClass()` | `classList.add/remove/toggle()` |
| `.attr('x')` / `.attr('x', v)` | `getAttribute('x')` / `setAttribute('x', v)` |
| `.removeAttr('x')` | `removeAttribute('x')` |
| `.data('x')` | `dataset.x` |
| `.val()` | `.value` |
| `.html(x)` | `.innerHTML = x` |
| `.text(x)` | `.textContent = x` |
| `.css('prop', val)` | `style.prop = val` |
| `.show()` / `.hide()` | `style.display = ''` / `'none'` |
| `.append(el)` / `.prepend(el)` | `.append(el)` / `.prepend(el)` |
| `.remove()` | `.remove()` |
| `.find('.x')` | `el.querySelector('.x')` |
| `.each(fn)` | `.forEach(fn)` |
| `.closest('.x')` | `.closest('.x')` (native) |
| `.parent()` | `.parentElement` |
| `.children()` | `.children` |
| `$.ajax()` / `$.get()` / `$.post()` / `$.getJSON()` | `fetch()` |
| `$.extend(a, b)` | `Object.assign(a, b)` |
| `$.each(obj, fn)` | `Object.keys(obj).forEach(...)` |
| `$.isNumeric(n)` | `!isNaN(parseFloat(n)) && isFinite(n)` |
| `$.isEmptyObject(o)` | `Object.keys(o).length === 0` |
| `$(window).width()` / `.height()` | `window.innerWidth` / `innerHeight` |
| `.offset()` | `getBoundingClientRect()` |
| `.outerWidth()` / `.outerHeight()` | `getBoundingClientRect().width/height` |
| `.scrollTop()` | `scrollY` / `el.scrollTop` |
| `.is(':visible')` | `el.offsetParent !== null` |
| `.is(':checked')` | `el.checked` |

### What stays (jQuery plugin calls — can't remove without replacing the plugin)

- `.colorbox()` — jQuery Colorbox plugin
- `.selectize()` — Selectize plugin
- `.slider()` — jQuery UI slider
- `.buttonset()` — jQuery UI buttonset
- `.datetimepicker()` / `$.datepicker.*` — jQuery UI datepicker + timepicker
- `$.confirm()` / `.pwg_jconfirm_follow_href()` — jConfirm plugin
- `.autogrow()` — autogrow plugin
- `.fotorama` data API — Fotorama plugin
- `$(document).bind("mobileinit")` / `.checkboxradio()` — jQuery Mobile (SmartPocket only)
- `.photoSwipe()` — PhotoSwipe jQuery plugin (SmartPocket only)
- `jQuery.fn.*` plugin definitions in:
  - `admin/themes/default/js/common.js` (`fontCheckbox`, `pwg_jconfirm_follow_href`)
  - `admin/themes/default/js/datepicker.js` (entire file — wraps jQuery UI datepicker)
  - `admin/themes/default/js/doubleSlider.js` (entire file — wraps jQuery UI slider)
  - `admin/themes/default/js/addAlbum.js` (entire file — defines `pwgAddAlbum` plugin)
  - `themes/modus/js/thumb.pop.js` (hoverIntent plugin inline definition)

---

## Files by Area and Size

### Admin Core JS — `admin/themes/default/js/`
| File | ~Lines | Plugin Deps |
|------|--------|-------------|
| `user_list.js` | 556 | jQuery UI `.slider()` ×8 |
| `group_list.js` | 382 | `.confirm()`, `.selectize()` |
| `plugins_installated.js` | 281 | `.confirm()` ×4, `.pwg_jconfirm_follow_href()` |
| `history.js` | 252 | none |
| `tags.js` | 251 | `.confirm()` ×3 |
| `albums.js` | 243 | `.confirm()` ×1 |
| `cat_list.js` | 145 | none |
| `cat_modify.js` | 145 | `.confirm()` |
| `batchManagerGlobal.js` | 146 | `.colorbox()`, `.pwgDatepicker()`, `.pwgAddAlbum()`, `.pwgDoubleSlider()` ×3 |
| `plugins_new.js` | 72 | `.selectize()`, `.slider()`, `.pwg_jconfirm_follow_href()` |
| `picture_modify.js` | 57 | none |
| `stats.js` | 20 | none |
| `album_selector.js` | 14 | none |
| `maintenance.js` | 12 | none |
| `picture_formats.js` | 11 | `.confirm()` |
| `LocalStorageCache.js` | 11 | `.selectize()`, `$.getJSON()`, `$.extend()`, `$.each()`, `$.proxy()` |
| `maintenance_env.js` | 10 | none |
| `datepicker.js` | — | **whole file is a plugin wrapper — skip** |
| `doubleSlider.js` | — | **whole file is a plugin wrapper — skip** |
| `addAlbum.js` | — | **whole file is a plugin definition — skip** |
| `common.js` | 47 | defines `jQuery.fn.*` — convert non-plugin parts only |
| `jquery.geoip.js` | 2 | none |

### Admin Templates — `admin/themes/default/template/`
| File | ~Lines | Plugin Deps |
|------|--------|-------------|
| `user_activity.tpl` | 156 | `.selectize()` ×1 |
| `batch_manager_global.tpl` | 83 | `.selectize()` ×2, `.colorbox()`, `.pwgAddAlbum()` |
| `photos_add_direct.tpl` | 66 | `.selectize()`, `.pwgAddAlbum()` |
| `intro.tpl` | 59 | none |
| `site_update.tpl` | 58 | none |
| `maintenance_actions.tpl` | 35 | none |
| `cat_search.tpl` | 30 | none |
| `updates_ext.tpl` | 27 | none |
| `themes_installed.tpl` | 23 | none |
| `configuration_sizes.tpl` | 23 | none |
| `configuration_main.tpl` | 23 | `.colorbox()` |
| `user_list.tpl` | 26 | none |
| `rating_user.tpl` | 17 | `$.confirm()` |
| `comments.tpl` | 17 | none |
| `admin.tpl` | 15 | `.lightAccordion()` (inline plugin), `.colorbox()` |
| `history.tpl` | 14 | `.pwgDatepicker()` |
| `element_set_ranks.tpl` | 13 | none |
| `configuration_watermark.tpl` | 12 | none |
| `album_notification.tpl` | 12 | none |
| `plugins_installed.tpl` | 1 | none |
| `rating.tpl` | 11 | `.selectize()` |
| `menubar.tpl` | 10 | none |
| `cat_perm.tpl` | 9 | none |
| `permalinks.tpl` | 8 | none |
| `picture_coi.tpl` | 8 | none |
| `themes_new.tpl` | 8 | none |
| `install.tpl` | 7 | none |
| `group_list.tpl` | 6 | none |
| `inc/install.inc.tpl` | 6 | none |
| `picture_modify.tpl` | 6 | none |
| `site_manager.tpl` | 6 | none |
| `updates_pwg.tpl` | 6 | none |
| `notification_by_mail.tpl` | 5 | none |
| `check_integrity.tpl` | 5 | none |
| `configuration_comments.tpl` | 5 | none |
| `tags.tpl` | 2 | none |
| `albums.tpl` | 1 | none |
| `languages_installed.tpl` | 3 | none |
| `languages_new.tpl` | 2 | none |
| `cat_list.tpl` | 3 | none |
| `photos_add_applications.tpl` | 2 | none |
| `footer.tpl` | 3 | none |
| `inc/autosize.inc.tpl` | 3 | `.autogrow()` — plugin only |

### Default Theme — `themes/default/`
| File | ~Lines | Plugin Deps |
|------|--------|-------------|
| `js/mcs.js` | 318 | none |
| `js/switchbox.js` | 10 | none |
| `template/picture.tpl` | 7 | none |
| `template/search.tpl` | 4 | `.selectize()` |
| `template/popuphelp.tpl` | 2 | none |
| `template/inc/autosize.inc.tpl` | 3 | `.autogrow()` — plugin only |

### Elegant Theme — `themes/elegant/`
| File | ~Lines | Plugin Deps |
|------|--------|-------------|
| `scripts_pp.js` | 51 | none |
| `scripts.js` | 15 | none |
| `admin/admin.tpl` | 2 | `.buttonset()` — plugin only |

### Modus Theme — `themes/modus/`
| File | ~Lines | Plugin Deps |
|------|--------|-------------|
| `js/photo.autosize.js` | 37 | none |
| `js/modus.async.js` | 23 | none |
| `js/thumb.arrange.js` | 11 | none |
| `js/menuh.js` | 10 | none |
| `js/thumb.pop.js` | — | **whole file is hoverIntent plugin — skip** |
| `admin/modus_admin.tpl` | 12 | `.slider()`, `.colorbox()` |
| `template/menubar.tpl` | 5 | none |
| `template/fotorama.tpl` | 4 | `.fotorama` data API — plugin only |

### SmartPocket Theme — `themes/smartpocket/`
| File | ~Lines | Plugin Deps |
|------|--------|-------------|
| `js/thumb.arrange.js` | 14 | none |
| `js/smartpocket.js` | 8 | `.photoSwipe()`, `$.ajax()` |
| `js/mcs_sp.js` | 5 | `.checkboxradio()` (jQuery Mobile) |
| `js/config.js` | 1 | `$.mobile` init — jQuery Mobile only |
| `template/infos_errors.tpl` | 3 | none |
| `template/search.tpl` | 4 | `.selectize()` |
| `template/footer.tpl` | 1 | none |
| `admin/admin.tpl` | 3 | none |

---

## Execution Order

### Batch 1 — Small template files (all themes)
Simple inline `<script>` blocks, quick wins. Commit after.

### Batch 2 — Theme JS files (non-admin)
`switchbox.js`, `mcs.js`, elegant/modus/smartpocket JS files. Commit after.

### Batch 3 — Admin JS: small/medium files
`jquery.geoip.js` through `picture_modify.js` in size order. Commit after.

### Batch 4 — Admin JS: large files
`cat_list.js` through `user_list.js`. Commit after each file.

### Batch 5 — Admin templates
All 40+ `.tpl` files under `admin/themes/default/template/`. Commit after.

---

## Rules
1. Convert one file at a time.
2. Plugin calls stay — wrap in `if (window.jQuery && ...)` guards when necessary.
3. Remove `require='jquery'` from `{footer_script}` / `{combine_script}` tags only when the script no longer uses jQuery directly.
4. After each file: `grep -n "jQuery\|\$(" <file>` to verify only plugin lines remain.
5. Commit after each batch.
