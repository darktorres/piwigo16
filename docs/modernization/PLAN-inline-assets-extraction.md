# Extract all inline CSS / JS from Smarty templates

## Context

Today, 133 project Smarty templates (in `themes/default/`, `themes/standard_pages/`, `admin/themes/default/`) carry **~6,990 lines** of inline assets and **240 inline `style="…"` attributes**:

| Kind                          | Blocks | Lines |
|-------------------------------|--------|-------|
| `<style>`                     | 21     | 4,219 |
| `{footer_script}`             | 52     | 1,835 |
| `{literal}`                   | 21     | 708   |
| `{html_head}`                 | 3      | 106   |
| Bare `<script>` (no `src`)    | 40     | 125   |
| `style="…"` attributes        | 240    | —     |

This work already has homes in the roadmap:

- **`docs/modernization/ROADMAP-CSS.md` #1 Step 16** — extract static `<style>` blocks to `css/pages/<name>.css` via `{combine_css}`.
- **`docs/modernization/ROADMAP-TS.md` #3** — replace `window.*` data-bridges in `{footer_script}` with `<script type="application/json">` + `getPageData<T>()`.

Two gaps the roadmap doesn't yet cover, found during this survey:

1. **20 `{footer_script}` blocks (~815 lines)** are pure JS with no Smarty interpolation and no `window.*` globals — they can move straight to `.ts` files via `{combine_script}` without any data-bridge work.
2. **`{literal}` blocks (708 lines)** and **240 inline `style=""` attributes** aren't itemized anywhere.

This plan executes the two existing roadmap items and closes those gaps in one ordered sweep. The end-state goal is a CSP that drops `'unsafe-inline'` for `style-src` (called out in `docs/modernization/ROADMAP-PHP.md:1332`).

## Scope

**In scope:** `themes/default/`, `themes/standard_pages/`, `admin/themes/default/`.

**Out of scope (intentionally inline forever):**

- Email templates: `themes/default/template/mail/text/html/*.tpl` — HTML email clients require inline styles.
- `vendor/`, `plugins/` — explicitly excluded; reassessed only if the core pipeline changes the contract.

**Previously declared "must stay inline" — reassessed and migrated:**

The five dynamic blocks (`batch_manager_global.tpl` first block, `thumbnails.tpl`, `month_calendar.tpl`, `mainpage_categories.tpl`, `comment_list.tpl`) initially seemed to require inline `{html_style}` because their CSS values are PHP-computed from `$derivative_params`. They were migrated anyway via **CSS custom properties on a wrapper element** + static `.css` rules consuming `var(--…)`. Inline `style=""` HTML attributes are governed by `style-src-attr` (a separate CSP directive from `style-src`), so this approach removes them from `style-src 'unsafe-inline'` requirements while preserving the dynamic value.

## Pipeline references (already in place — reuse, don't reinvent)

- `{combine_css path=… [id=] [version=] [order=] [template=]}` — registered at `src/Piwigo/Template/Template.php:125`, implementation at lines 967–989. Emitted via `{get_combined_css}`.
- `{combine_script id=… path=… [load=header|footer|async] [require=] [version=] [template=]}` — `src/Piwigo/Template/Template.php:123`, lines 798–853. Emitted via `{get_combined_scripts load=…}`.
- `{footer_script [require=…]}…{/footer_script}` — `src/Piwigo/Template/Template.php:128`, lines 941–953. Use only for the small bootstrap that injects Smarty values; the body should be ≤ ~10 lines and call into a static module.
- Page-data pattern (the canonical example): `batch_manager_unit.php` populates `page_data_json`, the template renders `<script type="application/json" id="pwg-…-data">`, and the `.ts` file reads it via `getPageData<T>()` (see `admin/themes/default/js/batch_manager_unit.ts`).
- Manifest-aware path resolution: `dist/manifest.json` is checked by `combine_script` automatically (Template.php:838-852), so `path='admin/themes/default/js/foo.js'` resolves to the hashed bundle in production.

## Phases

The phases are ordered for risk: cheapest, highest-volume wins first; data-bridge refactors last.

### Phase 1 — Static `<style>` extraction (executes ROADMAP-CSS Step 16)

Pure CSS, zero Smarty interpolation. Each file: cut the `<style>…</style>` block into the target `.css`, replace with one `{combine_css path=…}` call. Target paths follow the existing `admin/themes/default/css/pages/<name>.css` convention from ROADMAP-CSS.md:121-153.

Highest-impact files (line counts confirmed by inspection):

| Template | Block lines | Target path |
|----------|-------------|-------------|
| `admin/themes/default/template/user_list.tpl` (lines 1069–3171) | 2,103 | `admin/themes/default/css/pages/user-list.css` |
| `admin/themes/default/template/history.tpl` (241–687) | 447 | `admin/themes/default/css/pages/history.css` |
| `admin/themes/default/template/albums.tpl` (240–603) | 364 | `admin/themes/default/css/pages/albums.css` |
| `admin/themes/default/template/user_activity.tpl` (190–469) | 280 | `admin/themes/default/css/pages/user-activity.css` |
| `admin/themes/default/template/maintenance_sys.tpl` | 145 | `admin/themes/default/css/pages/maintenance-sys.css` |
| `admin/themes/default/template/cat_list.tpl` | 158 | `admin/themes/default/css/pages/cat-list.css` |
| `admin/themes/default/template/install.tpl` + `upgrade.tpl` | 127 + 106 | `admin/themes/default/css/pages/install-upgrade.css` (shared, near-identical) |
| `admin/themes/default/template/cat_modify.tpl` | 77 | `admin/themes/default/css/pages/cat-modify.css` |
| `admin/themes/default/template/maintenance_actions.tpl` (231–307) | 77 | `admin/themes/default/css/pages/maintenance-actions.css` |
| `admin/themes/default/template/photos_add_applications.tpl` | 59 | `admin/themes/default/css/pages/photos-add-applications.css` |
| `themes/default/template/no_photo_yet.tpl` (8–116) | 109 | `themes/default/css/no-photo-yet.css` |
| Remaining ~12 small static blocks (≤40 lines each) per ROADMAP-CSS.md:62 | ~250 | `…/css/pages/<name>.css` |

Phase total: **~4,200 lines of CSS moved to disk, 21 `<style>` tags removed**.

### Phase 2 — Pure `{footer_script}` and `{literal}` extraction to `.ts`

20 admin `{footer_script}` blocks (815 lines) and most `{literal}` blocks (708 lines) are pure JS — no Smarty, no `window.*` globals. They move directly to `.ts` files in the existing `admin/themes/default/js/` and `themes/default/js/` trees and are loaded with `{combine_script id=… path=… load='footer'}`.

Highest-impact files (from the classification pass):

| Template | Lines | Target |
|----------|-------|--------|
| `admin/themes/default/template/configuration_main.tpl` | 115 | `admin/themes/default/js/configuration_main.ts` |
| `admin/themes/default/template/menubar.tpl` | 77 | `admin/themes/default/js/admin_menubar.ts` |
| `admin/themes/default/template/element_set_ranks.tpl` | 74 | `admin/themes/default/js/element_set_ranks.ts` |
| `admin/themes/default/template/themes_standard_pages.tpl` | 68 | `admin/themes/default/js/themes_standard_pages.ts` |
| `admin/themes/default/template/configuration_search.tpl` | 60 | `admin/themes/default/js/configuration_search.ts` |
| `admin/themes/default/template/admin.tpl` | 57 | merge into existing `admin/themes/default/js/admin.ts` |
| `admin/themes/default/template/configuration_watermark.tpl` | 47 | `admin/themes/default/js/configuration_watermark.ts` |
| `admin/themes/default/template/history.tpl` (footer_script ×2) | 50 | `admin/themes/default/js/history.ts` |
| Remaining 12 pure-JS blocks (≤45 lines each) | ~270 | one `.ts` per template |
| 21 `{literal}` blocks (708 lines, including `no_photo_yet.tpl:7-117`) | 708 | per-template `.ts`, or merge into existing |

Replacement pattern in the template:

```smarty
{combine_script id='configuration_main' load='footer' path='admin/themes/default/js/configuration_main.js'}
```

Phase total: **~1,500 lines of JS moved to typed modules, 41 inline blocks removed**.

### Phase 3 — `window.*` data-bridge migration (executes ROADMAP-TS #3)

15 admin `{footer_script}` blocks (849 lines) carry `window.foo = …` data-bridges. This is the existing TS#3 work, with the inventory already in `docs/modernization/ROADMAP-TS.md:153-163`. Per-cluster pattern (from `batch_manager_unit.php`):

1. PHP controller pushes structured values into `page_data_json[$key]`.
2. Template emits one `<script type="application/json" id="pwg-<page>-data">` block.
3. `.ts` module reads via `getPageData<PageData>('pwg-<page>-data')`.
4. Drop the `{footer_script}` block; if a single value is consumed by one element, prefer `data-*` on that element instead of a JSON island.

Files: `batch_manager_global.tpl`, `picture_modify.tpl`, `admin.tpl`, `user_list.tpl`, `updates_ext.tpl`, `maintenance_actions.tpl`, `configuration_sizes.tpl`, `rating_user.tpl`, `themes_installed.tpl`, plus the smaller ones in TS#3's table.

Phase total: **~850 lines of JS moved, 20 `window.*` assignments deleted, `'unsafe-inline'` no longer needed for these.**

### Phase 4 — Smarty-l10n footer scripts (the residual 2 blocks)

2 admin `{footer_script}` blocks (~90 lines) contain Smarty interpolation that is exclusively translation strings (`{'…'|translate|escape:'javascript'}`) and no `window.*`. Treat each one of two ways:

- **Preferred:** add the translated strings to `page_data_json` (PHP side: `l10n('…')`), move the JS body to `.ts`, read via `getPageData()`.
- **Acceptable for tiny cases:** keep a 5–10 line `{footer_script}` that builds a JSON config, plus a `{combine_script}` to a static `.ts` that reads it from `data-pwg-strings` on a hook element.

Examples: `intro.tpl` (72 lines), the small remainder.

### Phase 5 — Inline `style=""` attributes (240 instances)

For each attribute, three actions in priority order:

1. **Move to a class** — when the rule is shared or matches an obvious page/component scope, add a class to the existing `css/pages/<name>.css` from Phase 1 and replace `style="…"` with `class="…"`.
2. **Drop entirely** — when the attribute is a no-op vs. the existing stylesheet (e.g. `display: block` on a `<div>` already block, default margins, etc.).
3. **Keep with `data-*` driver** — only when the value is genuinely PHP-computed at render time (e.g. progress bar widths). Tag with a comment.

Heaviest concentrations (most files have ≤5 attrs):

- `admin/themes/default/template/user_list.tpl` — 37 attrs (most consolidate into the new `user-list.css`)
- `admin/themes/default/template/batch_manager_unit.tpl` — 26
- `admin/themes/default/template/include/batch_manager_filter.inc.tpl` — 21
- `admin/themes/default/template/photos_add_direct.tpl` — 17
- `admin/themes/default/template/batch_manager_global.tpl` — 16

Phase total: **240 attrs → ~0**, modulo a handful of dynamic survivors.

### Phase 6 (terminal) — Tighten CSP

Once Phases 1–5 land, edit `docs/modernization/ROADMAP-PHP.md`'s CSP item to drop `'unsafe-inline'` from `style-src` and remove the residual `<style>` allowlist nonce path. Verify in browser dev console that no inline-style violations are reported on any admin or public page.

## Conventions

- **CSS file naming:** `<theme>/css/pages/<kebab-case-template-name>.css` (mirrors ROADMAP-CSS.md target tree at line 121).
- **JS file naming:** `<theme>/js/<template_name>.ts` (mirrors existing `batch_manager_unit.ts`, `picture_modify.ts`).
- **Script load order:** default `load='footer'` for page logic; only use `load='header'` when execution must precede DOM (rare, document why).
- **Always specify `id=`** on `{combine_script}` so `require=` chains stay readable.
- **One commit per template** within Phase 1 and Phase 2 — keeps diffs reviewable and bisectable.

## Critical files to modify or reference

- `src/Piwigo/Template/Template.php` (read-only) — `{combine_*}`, `{footer_script}`, `{html_head}` definitions; do not change.
- `src/Piwigo/Template/ScriptLoader.php` (read-only) — dependency resolver, topological sort.
- `dist/manifest.json` (read-only, build-output) — verify per-page bundles after each phase.
- `themes/default/template/header.tpl:54-58` and `themes/default/template/footer.tpl:35-37` — emission sites; should not need changes.
- `admin/themes/default/template/header.tpl:33-37` and `admin/themes/default/template/footer.tpl:111-113` — same.
- `admin/themes/default/js/batch_manager_unit.ts` — reference for `getPageData<T>()` consumer side.
- `admin/themes/default/include/batch_manager_unit.php` (or wherever `page_data_json` lives) — reference for emitter side.

## Verification

After each phase, in this order:

1. **Build:** `bun run build` (or `npm run build`) — Vite manifest regenerates cleanly.
2. **Type-check:** `bun run typecheck` — zero errors.
3. **PHPStan:** `vendor/bin/phpstan analyse` — still level 9, zero errors.
4. **Stylelint** (after Phase 1 & 5): `bunx stylelint "**/*.css" --ignore-path .stylelintignore` — no new errors.
5. **Combined-output diff:** before each phase, snapshot `_data/combined/t*.css` and `_data/combined/t*.js`; after, diff for byte-equivalence modulo selector ordering.
6. **Smoke-test in browser**: for each touched template, load the page in light + dark, click the dominant interaction, watch the console for `Refused to apply inline style` (CSP) or undefined-symbol errors.
7. **E2E:** if a Playwright spec covers the touched page, run that spec; otherwise note the gap.
8. **Phase-end byte counts** to confirm decreasing inline volume.

After **Phase 5**, confirm:

```bash
grep -rn 'style="' themes/default/template/ admin/themes/default/template/ themes/standard_pages/template/ \
  | grep -v 'mail/text/html' \
  | wc -l
# target: ≤ 10 (only genuine dynamic-width / dynamic-color survivors)
```

After **Phase 3**:

```bash
grep -rn '^window\.' admin/themes/default/template/ --include='*.tpl' \
  | grep -v 'window\.location\|window\.open\|window\.confirm'
# must return empty (mirrors ROADMAP-TS.md:178-181)
```

After **Phase 6**, the CSP header in browser dev tools should show no `'unsafe-inline'` for `style-src`, and no console violations on a 5-page tour (dashboard, user_list, batch_manager_global, picture_modify, public index).
