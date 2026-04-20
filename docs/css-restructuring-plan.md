# CSS Restructuring Plan — Piwigo Fork

## Decisions locked in

- **Scope:** Everything — admin + gallery, plus inline-style extraction, file deletions, TakeATour relocation.
- **clear/roma admin themes:** Migrate to the **modus pattern** — parent admin emits `:root { --var }` via a `base.css.tpl` driven by Smarty-provided skin values; children become tiny `:root {}` overrides.
- **Post-split loader:** Keep `theme.css` as the single entry point; its body becomes `@import "components/...";` statements. Zero change to `header.tpl`.
- **Housekeeping:** Delete/move every orphan and duplicate listed below.

## Context

The CSS in this fork has drifted into several pain shapes, all visible in one scan:

1. **One monster file.** `admin/themes/default/theme.css` is **8,798 lines** with 60+ `/* name.css */` section markers baked in — evidence it was merged from many files that should still be separate.
2. **Color-overrides that are also huge.** `admin/themes/roma/theme.css` (2,716) and `admin/themes/clear/theme.css` (1,392) repeat parent-theme section headers and carry far more than color overrides; there is no CSS-variable contract between parent and children.
3. **Color-variant triplets.** `themes/default/css/{search,clear-search,dark-search}.css` (830 + 295 + 298) duplicate structure between dark/clear variants for colors only.
4. **Inline `<style>` in templates.** 54 `.tpl` files contain `<style>` blocks. About half are **static** CSS (trivially extractable); half are **dynamic** (use `{$var}` / `{if}` / `{cssResolution}`) and must stay inline.
5. **An already-good exemplar.** `themes/modus/` is split by concern (`hf_layout/hf_components/hf_typography/hf_responsive`, `tags`, `plugin_compatibility`, `print`) and uses Smarty-templated `*.css.tpl` files that emit `:root { --color-* }` custom properties plus per-skin CSS files. This is the shape to copy.
6. **Orphans and dupes.** `themes/default/fix-khtml.css` is not referenced anywhere. `plugins/language_switch/style.css` (32 lines) is a near-empty counterpart to `language_switch.css` (1,021).
7. **Auto-generated & vendor.** `_data/combined/*.css` (output of `{combine_css}`) and `themes/bootstrap_darkroom/css/**` (60+ bootstrap/bootswatch/material variants) are vendor/derivative — out of scope.

Goal: bring every non-vendor CSS file into a predictable per-theme layout, kill duplication, and extract the inline CSS that has no reason to be in a template.

---

## Inventory (non-vendor, non-generated)

| Area | File | Lines | Verdict |
|---|---|---|---|
| admin default | `admin/themes/default/theme.css` | 8798 | **Split** into ~15 files by the section markers already in the file |
| admin default | `admin/themes/default/css/components/general.css` | 361 | Keep; promote pattern (`{* Temporary solution *}` header in `header.tpl` says this was an experiment) |
| admin default | `admin/themes/default/print.css` | 14 | Keep, wire via `header.tpl` (currently not loaded) |
| admin default | `admin/themes/default/fonts/open-sans/*.css` | (3 files) | Leave (vendor-ish font kit) |
| admin clear | `admin/themes/clear/theme.css` | 1392 | **Shrink** to colour-variables file once parent uses `var(--*)` |
| admin clear | `admin/themes/clear/css/components/general.css` | tiny | Keep/merge into clear skin |
| admin roma | `admin/themes/roma/theme.css` | 2716 | **Shrink** same way as clear |
| admin roma | `admin/themes/roma/css/components/general.css` | tiny | Keep/merge into roma skin |
| gallery default | `themes/default/theme.css` | 1087 | **Split** along existing `/** Menubar / Content / Picture / Default Layout / Default colors / Tables & forms */` section markers |
| gallery default | `themes/default/iconset.css` | 196 | Keep (imported via `@import` at top of `theme.css` — already clean) |
| gallery default | `themes/default/fix-khtml.css` | 16 | **Delete** (not referenced in any tpl/php) |
| gallery default | `themes/default/print.css` | 22 | Keep |
| gallery default | `themes/default/css/search.css` | 830 | **Keep structure; rewrite to use CSS variables** |
| gallery default | `themes/default/css/{clear,dark}-search.css` | 295+298 | **Collapse** into a `search-theme.css.tpl` per skin or a single file with `[data-colorscheme="dark"]` selectors |
| elegant | `themes/elegant/theme.css` | 680 | Keep as skin overlay on default; review for moves of structural bits into parent |
| smartpocket | `themes/smartpocket/theme.css` | 455 | Keep; already well-structured |
| modus | `themes/modus/css/*` | (10 files) | **Reference implementation** — no change |
| bootstrap_darkroom | `themes/bootstrap_darkroom/theme.css` | 1113 | Keep; optionally split by `/* Calendar / Cards / Plugin compat / BatchDownloader / ... */` markers |
| bootstrap_darkroom | `themes/bootstrap_darkroom/admin/css/admin.css` | 75 | Keep |
| plugin AdminTools | `plugins/AdminTools/template/{admin,public}_style.css` | — | Keep |
| plugin GDThumb | `plugins/GDThumb/css/{admin,gdthumb}.css` | — | Keep |
| plugin language_switch | `plugins/language_switch/language_switch.css` | 1021 | Keep |
| plugin language_switch | `plugins/language_switch/style.css` | 32 | Inspect: in `language_switch_flags.tpl` it is loaded before the main file — it is a pre-load style for flash-of-unstyled-content. Leave, or inline into header |
| plugin LocalFilesEditor | `plugins/LocalFilesEditor/template/locfiledit.css` | — | Keep |
| plugin TakeATour | `plugins/TakeATour/css/{admin,clear,roma}.css` | 16+4+128 | Move `clear.css`/`roma.css` into the corresponding admin skin files (TakeATour is already using `{if $ADMIN_THEME==…}` to pick one) |

---

## Inline `<style>` blocks in templates

**Static (17 files, safe to extract)** — no `{$var}` / `{if}` / `{cssResolution}` inside:
- `admin/themes/default/template/`: `album_notification.tpl`, `albums.tpl`, `batch_manager_unit.tpl`, `cat_list.tpl`, `cat_modify.tpl`, `cat_search.tpl`, `configuration_display.tpl`, `configuration_sizes.tpl`, `generate_thumbnails.tpl`, `generate_video_thumbnails.tpl`, `help.tpl`, `history.tpl`, `install.tpl` (+ `upgrade.tpl` — nearly identical to install), `intro.tpl`, `maintenance_actions.tpl`, `maintenance_env.tpl`, `menubar.tpl`, `permalinks.tpl`, `photos_add_applications.tpl`, `photos_add_direct.tpl`, `picture_modify.tpl`, `rating_user.tpl`, `site_update.tpl`, `updates_pwg.tpl`, `user_activity.tpl`, `user_list.tpl`, `batch_manager_global.tpl` (second `<style>` block only)
- `plugins/AdminTools/template/admin.tpl`, `plugins/AdminTools/template/public_controller.tpl`, `plugins/GDThumb/template/admin.tpl`, `plugins/LocalFilesEditor/template/show_default.tpl`, `plugins/TakeATour/tpl/admin.tpl`, `plugins/TakeATour/tours/privacy/tour.tpl`
- `themes/bootstrap_darkroom/template/{add_photos,header,stuffs_lastcoms}.tpl`
- `themes/default/template/no_photo_yet.tpl`
- `themes/default/template/mail/text/html/header.tpl` (leave — email HTML **must** inline styles)
- `themes/modus/admin/modus_admin.tpl` (75 lines of static!)
- `themes/smartpocket/admin/admin.tpl`, `themes/smartpocket/template/search.tpl`

**Dynamic (must stay inline)** — contain Smarty interpolation:
- `admin/themes/default/template/batch_manager_global.tpl` (first `{html_style}` block; thumb size)
- `themes/{default,modus,bootstrap_darkroom}/template/thumbnails.tpl` (derivative max_width/height)
- `themes/{default,modus,bootstrap_darkroom}/template/month_calendar.tpl` (cell width/height)
- `themes/{default,modus}/template/mainpage_categories.tpl`
- `themes/{default,modus,bootstrap_darkroom}/template/comment_list.tpl`
- `themes/smartpocket/template/thumbnails.tpl`

Extraction destination for each static block: a per-page CSS file loaded with `{combine_css path=…}` at the top of that tpl, matching the existing pattern (`batch_manager_global.tpl` already does `{combine_css path='node_modules/tom-select/…'}`, so we are just adding one more).

---

## Recommended target layout

### A. admin/themes/default/ — split the monster

Use the `/* name.css */` comments already present inside `theme.css` as natural seams. Proposed output:

```
admin/themes/default/css/
├── base/
│   ├── reset-defaults.css        // "General defaults", "forms", "Tables & forms"
│   └── typography.css
├── components/
│   ├── general.css               // (unchanged — existing file)
│   ├── pagination.css
│   ├── waiting.css
│   ├── tipTip.css                // TipTip 1.2 section
│   ├── menubar.css
│   ├── tabsheets.css
│   ├── dropdown.css
│   ├── search-bar.css
│   ├── datepicker.css            // jQuery datepicker + jQuery tooltips
│   └── webkit-hacks.css
├── pages/
│   ├── dashboard.css
│   ├── history.css
│   ├── batch-manager.css
│   ├── tag-manager.css
│   ├── picture-edit.css          // "Picture Edit" + "Format tab"
│   ├── album-manager.css         // "Album Manager" + "album search" + "Move Album"
│   ├── user-manager.css          // "UserList Pop in" + "Edit user popin" + "Activity Tab"
│   ├── comments.css              // "Pending Comments"
│   ├── watermark.css
│   ├── upload.css                // "Add photos, direct mode" + "Upload Form"
│   └── plugins.css               // "Plugin" + "Plugin page multiple views"
├── features/
│   ├── selection-mode.css
│   ├── merge-options.css
│   ├── group-editor.css          // Edit group name, Add group, Group checkbox, Group manager buttons
│   ├── selectize.css
│   ├── jqtree-overrides.css
│   └── icons.css
└── theme.css                     // thin entry: @import the above in order
```

`theme.css` becomes a single file whose body is nothing but `@import "components/...";` statements, in load order. `header.tpl` continues to load `theme.css` exactly as it does today (`admin/themes/default/template/header.tpl:23`); the combiner concats and caches the bundle. Behaviour and final payload are identical; the source is navigable.

### B. admin/themes/{clear,roma}/ — become colour themes (modus pattern)

Following `themes/modus/css/base.css.tpl`:

1. Create `admin/themes/default/css/base.css.tpl` — a Smarty-templated file that emits the `:root {}` declaration block from skin variables, e.g.

   ```smarty
   :root {
     --admin-bg:       {$admin_skin.page.backgroundColor};
     --admin-surface:  {$admin_skin.page.surface};
     --admin-fg:       {$admin_skin.page.color};
     --admin-accent:   {$admin_skin.accent};
     --admin-border:   {$admin_skin.border};
     /* … */
   }
   ```

2. Rewrite the parent admin `theme.css` (post-split) to consume `var(--admin-*)` everywhere a color/bg/border/shadow differs between light and dark.

3. Each `themeconf.php` for `clear` and `roma` defines its `$admin_skin` array (mirroring the modus `skins/*.css` approach). `header.tpl` loads `base.css.tpl` with `template=true` before the parent's split CSS.

4. `admin/themes/{clear,roma}/theme.css` shrinks to whatever truly cannot be expressed as a variable — ideally near-empty, just `:root {}` fallback overrides if any.

Structural rules currently duplicated in clear/roma (borders, paddings, grid, `@keyframes`, etc.) move up into `admin/themes/default/theme.css`.

### C. themes/default/ — split along existing seams

```
themes/default/css/
├── menubar.css
├── content.css
├── picture.css
├── layout.css                // "Default Layout"
├── colors.css                // "Default colors"
├── forms.css                 // "Tables & forms"
├── calendar.css              // chronology + month calendar
├── thumbnails.css            // "Thumbnails" + "Thumbnail elastic layout" + thumbnail-only scroll layout
├── comments.css              // "User comments" + "image comments rules"
├── popup.css                 // "Popup help page"
├── search.css                // (unchanged filename, rewritten to use variables)
├── search-skin.css           // (replaces clear-search.css + dark-search.css)
├── iconset.css               // (unchanged)
└── print.css
theme.css                     // imports all of the above
```

### D. themes/default/css/search* — collapse variants

Replace `search.css` + `clear-search.css` + `dark-search.css` with `search.css` that uses variables, driven by a `.css.tpl` in each skin:

```css
/* search.css */
.filter .filter-icon { color: var(--search-icon); }
.filter-manager-popin { background-color: var(--search-popin-bg); }
```

Each theme (default's clear/dark, elegant, bootstrap_darkroom) supplies the `--search-*` variable set, either in an inline `<style>` or in `theme.css`. Net savings: ~500 lines.

### E. Static inline `<style>` extraction

For every static-only `<style>` block listed above:
1. Cut the CSS into a new file under the theme's CSS dir — either a new file per page (`admin/themes/default/css/pages/<name>.css`) or appended to the matching `pages/*.css` file created in step A.
2. Replace the template's `<style>` block with a single line at the top of the tpl:

```smarty
{combine_css path="admin/themes/default/css/pages/install.css" order=-10}
```

3. Keep `install.tpl` + `upgrade.tpl` using the **same** file (they are duplicates today).

### F. Housekeeping

- Delete `themes/default/fix-khtml.css` — zero references in the repo.
- Keep `admin/themes/default/template/header.tpl`'s `{* Temporary solution *}` comment intact until the split in step A lands (then remove the note — the comment flags an intent that will be satisfied).
- Leave `_data/combined/*.css` untouched (regenerated on page load).
- Leave `themes/bootstrap_darkroom/css/bootstrap*/` & `bootswatch-*/` & `material-*/` — all vendor builds.

---

## Execution order (independent, low-risk first)

1. **Delete the orphan** `themes/default/fix-khtml.css`.
2. **Extract static inline `<style>`** from admin & gallery TPLs into new per-page CSS files; replace each block with a single `{combine_css path=…}` line. Merge duplicate pairs (`install.tpl`/`upgrade.tpl`) into one file.
3. **Split `themes/default/theme.css`** along its `/** Menubar / Content / Picture / Default Layout / Default colors / Tables & forms */` seams; `theme.css` becomes an `@import` list.
4. **Collapse search variants** (`search.css` + `clear-search.css` + `dark-search.css`) into `search.css` (variable-driven) + per-skin `--search-*` declarations. Drop the `{$themeconf.colorscheme}-search.css` load in `themes/default/template/inc/search_filters.inc.tpl:4`.
5. **Split `admin/themes/default/theme.css`** along its 60+ section comments into the tree in §A. `theme.css` becomes an `@import` list.
6. **Introduce `base.css.tpl` + `$admin_skin` variable contract** in the admin parent. Rewrite parent CSS to consume `var(--admin-*)`. Slim `admin/themes/clear/theme.css` and `admin/themes/roma/theme.css` to `:root {}` blocks (or delete in favour of `themeconf.php`-provided skins, matching modus). Update `admin/themes/default/template/header.tpl` to load `base.css.tpl` with `template=true`.
7. **Relocate TakeATour skin files.** Move the bodies of `plugins/TakeATour/css/clear.css` and `plugins/TakeATour/css/roma.css` into the corresponding admin skin CSS (or a dedicated `admin/themes/{clear,roma}/css/takeatour.css`). Simplify `plugins/TakeATour/tpl/js_css.tpl:3-4`.

Steps 1–4 are risk-free. Steps 5–6 are the biggest wins and the biggest change surface.

---

## Critical files to modify

- `admin/themes/default/theme.css` — split source
- `admin/themes/default/template/header.tpl` — replace single `{combine_css theme.css}` with the list (or keep one file that `@import`s the split)
- `themes/default/theme.css` — split source
- `themes/default/template/header.tpl` — same treatment
- `themes/default/css/search.css` + removal of `clear-search.css` / `dark-search.css`
- `themes/default/template/inc/search_filters.inc.tpl` — drop the `{$themeconf.colorscheme}-search.css` line
- `admin/themes/{clear,roma}/theme.css` — rewrite as CSS-variable payloads
- Every static-inline tpl listed above — one-line edit per file
- `themes/default/fix-khtml.css` — delete

## Reusing existing patterns

- **`{combine_css path=…}`** — already the standard CSS loader; see `admin/themes/default/template/header.tpl:17-24` and every `combine_css` call across the codebase.
- **CSS custom properties with Smarty skin vars** — pattern shown in `themes/modus/css/base.css.tpl` (`:root { --color-bg: {$skin.BODY.backgroundColor}; … }`). Apply the same idea for the admin parent/child relationship.
- **Per-page CSS load** — already done in `admin/themes/default/template/batch_manager_global.tpl:5-6` (`{combine_css path='node_modules/…'}`). Extending to project CSS is trivial.
- **`local/css/rules.css` user overrides** — untouched; `Template.php::prefilter_local_css` at `inc/Template.php:1184-1209` picks these up automatically.

---

## Verification

After each step:
1. `bun run lint` (picks up Biome/ECS pre-commit issues).
2. Visual smoke-test the admin pages touched by the step:
   - `http://localhost/piwigo-fork/admin.php` → dashboard, each sidebar section in turn.
   - Toggle between `clear` and `roma` via the head button (`header.tpl` Dark/Light link).
   - `http://localhost/piwigo-fork/` → gallery index, a category, a picture page, search popin (both light and dark skins).
3. Network tab in dev tools: confirm **same** or **fewer** CSS requests (the `{combine_css}` combiner bundles everything into `_data/combined/*.css`; adding more source files should not multiply network requests).
4. Diff `_data/combined/t*.css` output between before and after on a representative page — the concatenated, minified output should be byte-for-byte similar (modulo ordering).
5. Grep for the removed files/selectors to confirm no template or PHP still references them:
   - `rg fix-khtml`
   - `rg clear-search\|dark-search`

No automated tests currently cover CSS.

