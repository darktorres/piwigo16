# CSS Restructuring Plan — Piwigo Fork

## Status: All 7 steps complete

All execution steps have been implemented. This document reflects the actual state of the codebase after implementation, noting where the approach deviated from the original plan.

---

## Context

The CSS in this fork had drifted into several pain shapes:

1. **One monster file.** `admin/themes/default/theme.css` was **8,798 lines** with 60+ `/* name.css */` section markers baked in.
2. **Color-overrides that were also huge.** `admin/themes/roma/theme.css` (2,716) and `admin/themes/clear/theme.css` (1,392) repeated parent-theme section headers with no CSS-variable contract between parent and children.
3. **Color-variant triplets.** `themes/default/css/{search,clear-search,dark-search}.css` (830 + 295 + 298) duplicated structure between dark/clear variants for colors only.
4. **Inline `<style>` in templates.** 54 `.tpl` files contained `<style>` blocks. About half were **static** CSS (extractable); half were **dynamic** (use `{$var}` / `{if}` / `{cssResolution}`) and had to stay inline.
5. **Orphans and dupes.** `themes/default/fix-khtml.css` was not referenced anywhere.

---

## Inventory (current state)

| Area | File | Lines (before → after) | Status |
|---|---|---|---|
| admin default | `admin/themes/default/theme.css` | 8798 → 35 | **Done** — thin `@import` manifest |
| admin default | `admin/themes/default/css/base/variables.css` | new (40) | **Done** — 28 `--admin-*` custom properties |
| admin default | `admin/themes/default/css/base/global.css` | new (90) | **Done** — page-level rules consuming variables |
| admin default | `admin/themes/default/css/base/content.css` | new (93) | **Done** |
| admin default | `admin/themes/default/css/base/layout.css` | new (99) | **Done** |
| admin default | `admin/themes/default/css/base/forms.css` | new (62) | **Done** |
| admin default | `admin/themes/default/css/base/maintenance.css` | new (58) | **Done** |
| admin default | `admin/themes/default/css/components/` (13 files) | new | **Done** — see layout below |
| admin default | `admin/themes/default/css/pages/` (13 theme + 26 per-page) | new | **Done** — see layout below |
| admin default | `admin/themes/default/css/features/` (3 files) | new | **Done** |
| admin default | `admin/themes/default/css/components/general.css` | 361 (360) | Kept; loaded via `{* Temporary solution *}` in header.tpl |
| admin default | `admin/themes/default/print.css` | 14 | Not yet wired into `header.tpl` |
| admin clear | `admin/themes/clear/theme.css` | 1392 → 1299 | **Partial** — `:root {}` variable block added at top; structural rules remain |
| admin roma | `admin/themes/roma/theme.css` | 2716 → 2776 | **Partial** — `:root {}` variable block added at top; grew slightly (TakeATour append) |
| gallery default | `themes/default/theme.css` | 1087 → 6 | **Done** — thin `@import` manifest |
| gallery default | `themes/default/css/menubar.css` | new (90) | **Done** |
| gallery default | `themes/default/css/content.css` | new (281) | **Done** — includes Content, Thumbnails, Comments, Calendar, Popup sections |
| gallery default | `themes/default/css/picture.css` | new (140) | **Done** |
| gallery default | `themes/default/css/layout.css` | new (240) | **Done** — includes Layout and Filter forms sections |
| gallery default | `themes/default/css/colors.css` | new (330) | **Done** — includes Default colors, Tables & forms, and other global color rules |
| gallery default | `themes/default/css/no_photo_yet.css` | new (170) | **Done** — extracted from `no_photo_yet.tpl` (step 2) |
| gallery default | `themes/default/iconset.css` | 196 | Kept (already clean) |
| gallery default | `themes/default/fix-khtml.css` | 16 → deleted | **Done** |
| gallery default | `themes/default/print.css` | 22 | Kept |
| gallery default | `themes/default/css/search.css` | 830 → 948 | **Done** — rewritten to use `var(--search-*)` |
| gallery default | `themes/default/css/clear-search.css` | 295 → 59 | **Done** — slim `:root {}` with 44 `--search-*` light values |
| gallery default | `themes/default/css/dark-search.css` | 298 → 78 | **Done** — slim `:root {}` with 44 `--search-*` dark values + dark-only structural rules |
| plugin TakeATour | `plugins/TakeATour/css/clear.css` | 4 → deleted | **Done** — moved into `admin/themes/clear/theme.css` |
| plugin TakeATour | `plugins/TakeATour/css/roma.css` | 128 → deleted | **Done** — moved into `admin/themes/roma/theme.css` |
| plugin TakeATour | `plugins/TakeATour/css/admin.css` | kept | Kept |
| plugin TakeATour | `plugins/TakeATour/css/admin_page.css` | new | **Done** — extracted from `tpl/admin.tpl` (step 2) |
| plugin TakeATour | `plugins/TakeATour/css/tour_privacy.css` | new | **Done** — extracted from `tours/privacy/tour.tpl` (step 2) |
| plugin TakeATour | `plugins/TakeATour/tpl/js_css.tpl` | simplified | **Done** — skin conditionals removed |

---

## Inline `<style>` extraction (Step 2)

All static `<style>` blocks were extracted. Each tpl now has a `{combine_css path=…}` line instead.

**Admin tpls extracted** — CSS files in `admin/themes/default/css/pages/` (underscore-named, loaded per-page):

`album_notification.css`, `albums.css`, `batch_manager_global.css`, `batch_manager_unit.css`, `cat_list.css`, `cat_modify.css`, `cat_search.css`, `configuration_display.css`, `configuration_sizes.css`, `generate_thumbnails.css`, `generate_video_thumbnails.css`, `help.css`, `history.css`, `install-upgrade.css` (merged install.tpl + upgrade.tpl), `intro.css`, `maintenance.css`, `menubar.css`, `permalinks.css`, `photos_add_applications.css`, `photos_add_direct.css`, `picture_modify.css`, `rating_user.css`, `site_update.css`, `updates_pwg.css`, `user_activity.css`, `user_list.css`

**Other tpls extracted** — CSS next to each theme/plugin:

| Template | CSS file created |
|---|---|
| `themes/default/template/no_photo_yet.tpl` | `themes/default/css/no_photo_yet.css` |
| `themes/modus/admin/modus_admin.tpl` | `themes/modus/css/modus_admin.css` |
| `themes/bootstrap_darkroom/template/add_photos.tpl` | `themes/bootstrap_darkroom/css/add_photos.css` |
| `themes/bootstrap_darkroom/template/header.tpl` | `themes/bootstrap_darkroom/css/header.css` |
| `themes/bootstrap_darkroom/template/stuffs_lastcoms.tpl` | `themes/bootstrap_darkroom/css/stuffs_lastcoms.css` |
| `themes/smartpocket/admin/admin.tpl` | `themes/smartpocket/css/admin.css` |
| `plugins/AdminTools/template/admin.tpl` | `plugins/AdminTools/template/admin_style.css` |
| `plugins/AdminTools/template/public_controller.tpl` | `plugins/AdminTools/template/public_controller.css` |
| `plugins/GDThumb/template/admin.tpl` | `plugins/GDThumb/css/admin_page.css` |
| `plugins/LocalFilesEditor/template/show_default.tpl` | `plugins/LocalFilesEditor/template/show_default.css` |
| `plugins/TakeATour/tpl/admin.tpl` | `plugins/TakeATour/css/admin_page.css` |
| `plugins/TakeATour/tours/privacy/tour.tpl` | `plugins/TakeATour/css/tour_privacy.css` |

**Left inline (dynamic — Smarty interpolation):**
- `admin/themes/default/template/batch_manager_global.tpl` (first `{html_style}` block; thumb size)
- `themes/{default,modus,bootstrap_darkroom}/template/thumbnails.tpl`
- `themes/{default,modus,bootstrap_darkroom}/template/month_calendar.tpl`
- `themes/{default,modus}/template/mainpage_categories.tpl`
- `themes/{default,modus,bootstrap_darkroom}/template/comment_list.tpl`
- `themes/smartpocket/template/thumbnails.tpl`
- `themes/default/template/mail/text/html/header.tpl` (email — must stay inline)
- `themes/smartpocket/template/search.tpl`

---

## Actual layout (as-built)

### admin/themes/default/css/

```
admin/themes/default/css/
├── base/
│   ├── variables.css     (40)   — 28 --admin-* custom properties, dark/roma defaults
│   ├── global.css        (90)   — page-level selectors consuming var()
│   ├── content.css       (93)
│   ├── layout.css        (99)
│   ├── forms.css         (62)
│   └── maintenance.css   (58)
├── components/
│   ├── general.css       (360)  — unchanged; loaded via {* Temporary solution *} in header.tpl
│   ├── thumbnails.css    (81)
│   ├── search-bar.css    (63)
│   ├── dropdown.css      (81)
│   ├── pagination.css    (88)
│   ├── waiting.css       (21)
│   ├── datepicker.css    (37)
│   ├── tipTip.css        (103)
│   ├── webkit-hacks.css  (16)
│   ├── menubar.css       (133)
│   ├── tabsheets.css     (439)
│   ├── selectize.css     (6)
│   ├── jqtree-overrides.css (104)
│   └── icons.css         (275)
├── pages/
│   │   — Loaded via @import in theme.css (always present):
│   ├── cat-list.css      (236)
│   ├── cat-modify.css    (455)
│   ├── dashboard.css     (802)
│   ├── add-photos.css    (112)
│   ├── plugins.css       (1043)
│   ├── batch-manager.css (249)
│   ├── upload.css        (143)
│   ├── tag-manager.css   (530)
│   ├── picture-edit.css  (207)
│   ├── album-manager.css (1157)
│   ├── comments.css      (77)
│   ├── watermark.css     (259)
│   ├── user-manager.css  (1150)
│   │
│   │   — Loaded per-page via {combine_css} in each tpl (from step 2 inline extraction):
│   ├── album_notification.css (10)
│   ├── albums.css        (406)
│   ├── batch_manager_global.css (30)
│   ├── batch_manager_unit.css   (29)
│   ├── cat_list.css      (157)
│   ├── cat_modify.css    (90)
│   ├── cat_search.css    (5)
│   ├── configuration_display.css (14)
│   ├── configuration_sizes.css   (33)
│   ├── generate_thumbnails.css   (33)
│   ├── generate_video_thumbnails.css (36)
│   ├── help.css          (3)
│   ├── history.css       (458)
│   ├── install-upgrade.css (216)  — shared by install.tpl + upgrade.tpl
│   ├── intro.css         (7)
│   ├── maintenance.css   (170)
│   ├── menubar.css       (3)
│   ├── permalinks.css    (31)
│   ├── photos_add_applications.css (56)
│   ├── photos_add_direct.css    (5)
│   ├── picture_modify.css (25)
│   ├── rating_user.css   (26)
│   ├── site_update.css   (102)
│   ├── updates_pwg.css   (44)
│   ├── user_activity.css (205)
│   └── user_list.css     (1466)
└── features/
    ├── selection-mode.css (220)
    ├── merge-options.css  (81)
    └── group-editor.css   (310)
```

### themes/default/css/ (gallery)

```
themes/default/css/
├── menubar.css       (90)
├── content.css       (281)  — Content + Thumbnails + Comments + Calendar + Popup sections
├── picture.css       (140)
├── layout.css        (240)  — Default Layout + Filter forms sections
├── colors.css        (330)  — Default colors + Tables & forms + global color rules
├── no_photo_yet.css  (170)  — per-page, loaded via {combine_css} in no_photo_yet.tpl
├── search.css        (948)  — variable-driven (always loaded)
├── clear-search.css  (59)   — :root {} with --search-* light values
└── dark-search.css   (78)   — :root {} with --search-* dark values + dark-only structural rules
```

---

## Deviations from original plan

### Step 3 (gallery theme.css split) — coarser than planned

The plan proposed 14 separate files; the implementation split into 5 (plus `no_photo_yet.css`). Several planned files were folded into larger ones:

| Planned file | Where it ended up |
|---|---|
| `forms.css` | folded into `colors.css` |
| `calendar.css` | folded into `content.css` |
| `thumbnails.css` | folded into `content.css` |
| `comments.css` | folded into `content.css` |
| `popup.css` | folded into `content.css` or `layout.css` |
| `search-skin.css` | not created (see step 4 deviation) |

### Step 4 (search variant collapse) — kept two files

The plan proposed replacing `clear-search.css` + `dark-search.css` with a single `search-skin.css`. Instead:
- Both files remain but are now pure `:root {}` variable declarations (59 and 78 lines)
- `search_filters.inc.tpl` still loads `{$themeconf.colorscheme}-search.css` — unchanged because the per-colorscheme files still exist

### Step 5 (admin split) — base/ naming differs from plan

The plan proposed `reset-defaults.css` + `typography.css`. The actual names are `content.css`, `forms.css`, `layout.css`, `maintenance.css` (more descriptive of content). `variables.css` and `global.css` were added in step 6.

The plan put `selectize.css` and `jqtree-overrides.css` in `features/`; they went into `components/` instead.

The plan included `history.css` in the always-loaded `@import` set; it was extracted from the inline style (step 2) and loads per-page only via `{combine_css}` in `history.tpl`.

### Step 6 (CSS variable contract) — plain CSS, not Smarty template

The plan called for `base.css.tpl` (Smarty-driven, `$admin_skin` PHP arrays, `template=true` in header.tpl). Instead:
- Plain `variables.css` defines the dark defaults; `global.css` consumes them
- `admin/themes/clear/theme.css` starts with a `:root {}` override block (1299 lines total)
- `admin/themes/roma/theme.css` starts with a `:root {}` block matching dark defaults (2776 lines)
- Neither clear nor roma is near-empty — structural duplication between parent and children was not eliminated in this pass
- `header.tpl` unchanged (no `template=true` load needed)

### Step 7 (TakeATour relocation) — appended directly to theme.css

The plan suggested creating dedicated `admin/themes/{clear,roma}/css/takeatour.css` files. Instead the CSS was appended directly to each skin's `theme.css`.

---

## Remaining work (not yet done)

- **admin/themes/{clear,roma}/ structural deduplication** — both skins still carry large amounts of structural CSS duplicated from the parent. The variable contract is in place but the cleanup of duplicated rules was not done.
- **gallery split granularity** — `content.css` (281 lines) folds together Content, Thumbnails, Comments, Calendar, and Popup sections. Could be split further if needed.
- **`admin/themes/default/print.css`** (14 lines) — exists but is not wired into `header.tpl`.
- **`admin/themes/default/css/components/general.css`** — still loaded via the `{* Temporary solution *}` note in `header.tpl:24`. Could be merged into the main split or the comment removed once the intent is satisfied.

---

## Reusing existing patterns

- **`{combine_css path=…}`** — standard CSS loader; `inc/FileCombiner.php` inlines all `@import` chains, so splitting source files does not increase HTTP requests.
- **FileCombiner `@import` security check** — `FileCombiner.php:310` rejects imports containing `..`; this means split files that moved 2 levels deeper must use `url("../../icon/...")` instead of `@import "../..."`.
- **CSS custom properties** — `variables.css` declares `--admin-*` defaults; child themes override with a `:root {}` block at the top of their `theme.css`. Same pattern as `themes/modus/css/base.css.tpl` but without Smarty.
- **Per-page CSS load** — `{combine_css path='...' order=-10}` at top of tpl. Used by all step-2 extractions.
- **`local/css/rules.css` user overrides** — untouched; `Template.php::prefilter_local_css` at `inc/Template.php:1184-1209` picks these up automatically.

---

## Verification

After each step:
1. `bun run lint`
2. Visual smoke-test:
   - `http://localhost/piwigo-fork/admin.php` → dashboard, each sidebar section.
   - Toggle between `clear` and `roma` via the head button.
   - `http://localhost/piwigo-fork/` → gallery index, a category, a picture page, search popin (both light and dark skins).
3. Network tab: confirm same or fewer CSS requests (`{combine_css}` bundles into `_data/combined/*.css`).
4. Grep for removed files to confirm no template or PHP still references them.

No automated tests currently cover CSS.
