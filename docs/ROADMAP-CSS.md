# Piwigo 16.x — Modernization Roadmap (CSS)

CSS / theme modernization work. See [MODERNIZATION.md](MODERNIZATION.md) for architecture context and completed phase summaries; see [ROADMAP-PHP.md](ROADMAP-PHP.md) and [ROADMAP-TS.md](ROADMAP-TS.md) for the other tracks.

---

## #1 — CSS design tokens + Stylelint

**Status:** In progress &nbsp;|&nbsp; **Size:** M

### Goal

- Stylelint passing for all first-party CSS with zero errors and no `!important` outside JS-toggled visibility rules.
- CSS custom properties for all repeated colors, spacing values, and breakpoints.
- Monster files split into per-concern files: `themes/admin/_base/theme.css` (9,635 lines) and `themes/_base/theme.css` (1,305 lines) become thin `@import` lists.
- Admin child themes (`light`, `dark`) reduced to `:root {}` variable override blocks.
- `themes/standard_pages/skins/*.css` refactored from hundreds of element overrides to single `:root {}` blocks.

### Current state

- `themes/admin/_base/theme.css`: **9,561 lines**, still monolithic (60+ `/* name.css */` section markers baked in). Grew from 8,375 because inline-style extraction (Step 16) added utility classes here.
- `themes/admin/dark/theme.css`: **2,805 lines** — duplicates parent section headers and carries far more than color overrides.
- `themes/admin/light/theme.css`: **1,226 lines** — same problem.
- `themes/_base/theme.css`: **1,241 lines**, unsplit (currently just `@import "iconset.css"` + bulk content).
- `themes/_base/fix-khtml.css`: **15 lines**, orphan — zero references anywhere in the repo.
- `themes/_base/fix-ie5-ie6.css`, `fix-ie7.css`: referenced only from `<!--[if lt IE 7]>` / `<!--[if IE 7]>` conditional comments in `themes/_base/local_head.tpl`. IE conditionals are dead in modern browsers — both files are de facto orphans.
- `themes/admin/_base/template/install.tpl:17` and `upgrade.tpl:18` reference a non-existent `themes/admin/_base/fix-ie7.css` (broken link, also IE-only).
- `themes/_base/css/clear-search.css` + `dark-search.css`: **344 + 333 lines** — color-only variants duplicating `search.css` structure.
- `themes/standard_pages/skins/*.css`: **11 skin files** (cadmium, cobalt, default, fuchsia, green, lime, purple, red, sienna, silver, teal), each ~337 lines, each with **20 `!important` instances** — fight specificity with the parent theme; same anti-pattern modus had.
- **~689 `!important` declarations** across first-party CSS:
  - `themes/admin/dark/theme.css`: 162
  - `themes/admin/_base/theme.css`: 150
  - `themes/admin/light/theme.css`: 45
  - `themes/admin/_base/css/**`: ~71 (pages/components combined)
  - `themes/standard_pages/`: ~235 (theme + 11 skins)
  - `themes/_base/theme.css`: 10, `print.css`: 1
  - Roughly half justified (child-theme load order, JS-toggled visibility); the rest disappear when the token system lands.
- Stylelint coverage and rules are correctly configured (commit `ba17576e8`). Current state: **0 errors, 0 warnings** — the 328-error backlog from the tightened config (`color-named`, `no-duplicate-selectors`, unit/zero-length, single-line declarations) was cleared in `81ddf3d63`.
- `declaration-no-important` was tried as a warning then dropped (commit `9a482db86`) — too noisy until the token system reduces the genuine count.
- Zero CSS custom properties anywhere in project CSS (one-off `var(--col-w)` style hooks for inline-extracted dynamic blocks aside).
- No canonical breakpoints — `576px`, `640px`, `800px`, `1100px` used inconsistently.

### File inventory (in scope)

**Frontend themes:**

- `themes/_base/theme.css`, `css/search.css`, `css/clear-search.css`, `css/dark-search.css`, plus small per-page CSS (`thumbnails.css`, `month_calendar.css`, `mainpage_categories.css`, `comment_list.css`, `redirect.css`, `no-photo-yet.css`)
- `themes/standard_pages/theme.css` + 11 skin files in `skins/`

**Admin themes:**

- `themes/admin/_base/theme.css`, `css/components/{general,album_selector,batch_manager,flatpickr}.css`, `css/pages/*.css` (28 files)
- `themes/admin/light/theme.css`
- `themes/admin/dark/theme.css`, `css/components/general.css`

**Out of scope (vendor / ignored by Stylelint):** `node_modules/**`, `dist/**`, `_data/**`, `vendor/**`, `plugins/**`, `tests/**`, fontello files, open-sans files, `themes/_base/js/plugins/**`, `themes/_base/vendor/fontello/**`, `themes/elegant/admin/**` (path retained in ignoreFiles for safety even though theme is gone), `themes/admin/_base/{fontello,fonts}/**`, `**/*.min.css`.

### Inline `<style>` block inventory ✅ Extracted

All static `<style>` and `{html_style}` blocks were extracted to `css/pages/<name>.css` files via `{combine_css}`. The dynamic blocks initially flagged "must stay inline" — `batch_manager_global.tpl` (first block), `thumbnails.tpl`, `month_calendar.tpl`, `mainpage_categories.tpl`, `comment_list.tpl` — were also migrated using the **CSS custom property pattern**: the wrapper element carries `style="--var: value"` (governed by CSP `style-src-attr`, separate from `style-src`) while the consuming rules live in static CSS. See `PLAN-inline-assets-extraction.md` for the full record.

Result: **0 `<style>` tags and 0 `{html_style}` blocks remain** in `themes/_base/`, `themes/standard_pages/`, `themes/admin/_base/` (excluding `mail/text/html/` which intentionally keeps inline styles for email clients, and `plugins/` which is out of the 16.x core scope).

### `!important` tier breakdown

Counts re-measured against the current tree; the modus-skin tier from the original plan is gone (theme deleted) and is replaced by the equivalent tier for `themes/standard_pages/skins/`.

**Tier 1 — Keep permanently.** Add `/* reason */` comment where missing.

| Reason                                                                                                                       | Files                                                                   | Count |
| ---------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------- | ----: |
| Child-theme load-order (child CSS loads before parent; overrides need `!important` until CSS variable migration is complete) | `themes/admin/dark/theme.css`, `themes/admin/light/theme.css`           |  ~150 |
| Third-party CSS override (search popin / mcs-search injects its own CSS)                                                     | `themes/_base/css/search.css`, `clear-search.css`, `dark-search.css`    |   ~30 |
| `[hidden]` HTML5 attribute beating `display: flex/block` class rules                                                         | `themes/admin/_base/theme.css`, `themes/_base/theme.css`                |     2 |
| JS-toggled visibility (`display: none/flex/block`)                                                                           | `themes/admin/_base/css/pages/user-list.css`, `user-activity.css`, etc. |   ~10 |

**Tier 2 — Fix with higher specificity: tom-select overrides.**
`batch_manager_unit.css` and `picture_modify.css` carry tom-select `.ts-control .item` overrides at specificity (0,2,0); tom-select ships `.ts-control > .item` at (0,1,1) — our specificity already wins, `!important` is redundant. Verify against `node_modules/tom-select/dist/css/tom-select.css`, drop `!important`, and extract the shared block into `themes/admin/_base/css/components/tomselect-item.css`.

**Tier 3 — Fix internal specificity battles.**
These exist because rules that used to live in cascade order inside the monolithic `theme.css` were extracted to per-page files (Step 16) and lost their position advantage. Concrete hot spots in the current tree:

| File                                                                   |  Count | Notes                                                      |
| ---------------------------------------------------------------------- | -----: | ---------------------------------------------------------- |
| `themes/admin/_base/css/pages/user-list.css`                           |     20 | Mixed: tom-select items + layout + a few JS-toggled (keep) |
| `themes/admin/_base/css/pages/picture_modify.css`                      |     11 | Tom-select `.item` (Tier 2)                                |
| `themes/admin/_base/css/components/general.css`                        |      9 | Buttons, head-buttons fighting parent specificity          |
| `themes/admin/_base/css/pages/albums.css`                              |      6 | Tom-select `.item` + margin                                |
| `themes/admin/_base/css/pages/maintenance-sys.css`                     |      4 |                                                            |
| `themes/admin/_base/css/pages/user-activity.css`                       |      3 | Excluding JS-toggled                                       |
| `themes/admin/_base/css/pages/history.css`                             |      3 |                                                            |
| `themes/admin/_base/css/pages/{batch_manager_unit,cat-list,intro}.css` | 2 each |                                                            |
| `themes/admin/_base/css/components/{album_selector}.css`               |      2 |                                                            |

Approach per instance: (1) note the property + selector, (2) grep for conflicting rule, (3) fix by raising specificity, lowering source rule specificity, or reordering within the file.

**Tier 4 — `themes/standard_pages/skins/*.css`.**
11 skin files × 20 `!important` ≈ 220 instances. They exist solely because skins fight specificity with `themes/standard_pages/theme.css`. Disappear once the parent uses CSS variables and the skins reduce to `:root {}` overrides (covered by the new Step 8 below).

### Target directory layout

Many `pages/` files already exist (extracted by Step 16) and several `components/` exist too — the split here is the rest of the monolith plus a few new components. Existing files marked `(existing)`.

**`themes/admin/_base/css/`** (post-split):

```text
base/
  reset-defaults.css       ← "General defaults", forms, "Tables & forms"
  typography.css
  utilities.css            ← .u-* atomic classes currently at the top of theme.css
components/
  general.css              ← (existing) — folded into base.css.tpl token system in Step 10
  album_selector.css       ← (existing)
  batch_manager.css        ← (existing)
  flatpickr.css            ← (existing)
  pagination.css
  waiting.css
  tipTip.css
  menubar.css
  tabsheets.css
  dropdown.css
  search-bar.css
  datepicker.css
  tomselect-item.css       ← shared Tier 2 fix (extracted from batch_manager_unit + picture_modify + user-list + albums)
pages/
  dashboard.css
  history.css                ← (existing)
  batch_manager_unit.css     ← (existing)
  batch_manager_global.css   ← (existing)
  picture_modify.css         ← (existing)
  picture-edit.css           ← "Picture Edit" + "Format tab"
  album-manager.css          ← "Album Manager" + "album search" + "Move Album"
  user-manager.css           ← "UserList Pop in" + "Edit user popin"
  user-activity.css          ← (existing)
  user-list.css              ← (existing)
  comments.css               ← (existing) — "Pending Comments" moves here
  watermark.css
  upload.css                 ← "Add photos, direct mode" + "Upload Form"
  photos_add_direct.css      ← (existing)
  photos-add-applications.css ← (existing)
  plugins.css
  install-upgrade.css        ← (existing)
  intro.css                  ← (existing)
  rating.css                 ← (existing)
  rating_user.css            ← (existing)
  cat-modify.css             ← (existing)
  cat-list.css               ← (existing)
  cat-search.css
  cat-perm.css
  albums.css                 ← (existing)
  album_notification.css     ← (existing)
  permalinks.css             ← (existing)
  site_manager.css           ← (existing)
  configuration_display.css  ← (existing)
  configuration_sizes.css    ← (existing)
  maintenance-actions.css    ← (existing)
  maintenance-env.css        ← (existing)
  maintenance-sys.css        ← (existing)
  updates-pwg.css            ← (existing)
  menubar.css                ← (existing)
  help.css                   ← (existing)
features/
  selection-mode.css
  merge-options.css
  group-editor.css
  jqtree-overrides.css
  icons.css
base.css.tpl                ← Smarty token emitter (Step 10)
theme.css                   ← thin entry: @import the above in order
```

**`themes/_base/css/`** (post-split):

```text
tokens.css                 ← :root {} block (Step 6)
menubar.css
content.css
picture.css
layout.css
colors.css
forms.css
calendar.css
thumbnails.css             ← (existing)
comment_list.css           ← (existing)
comments.css
mainpage_categories.css    ← (existing)
month_calendar.css         ← (existing)
no-photo-yet.css           ← (existing)
redirect.css               ← (existing)
popup.css
search.css                 ← variable-driven (replaces search.css + clear-search.css + dark-search.css after Step 5)
print.css                  ← (currently at themes/_base/print.css; move under css/)
themes/_base/iconset.css ← (existing, unchanged)
themes/_base/theme.css   ← thin entry: @import the above
```

### Steps

Execute in this order (risk-free first):

**Step 1 — Extend Stylelint coverage.** ✅ Done (`72264635d`, `ac3fddf57`, `e7967a8e6`, `c9ac86326`, `ba17576e8`).
`.stylelintrc.json` now scopes `ignoreFiles` to vendor/font directories and `plugins/**`; the rule set has `custom-property-pattern`, `color-no-invalid-hex`, `color-hex-length: short`, `color-named: never`, `shorthand-property-no-redundant-values`, `declaration-block-no-redundant-longhand-properties`, `length-zero-no-unit`, `unit-allowed-list`, `selector-no-vendor-prefix` (warning), `no-duplicate-selectors`, `alpha-value-notation: number`. `declaration-no-important` was tried as a warning then dropped (`9a482db86`) — too noisy until token migration cuts the genuine count. Current baseline: 0 errors, 0 warnings.

**Step 2 — Mechanical auto-fix.** ✅ Done (`2914b89cf`, `afbe58226`, `148afd4fc`, `fc8b343be`, `81ddf3d63`).
Multiple `stylelint --fix` passes eliminated auto-fixable formatting errors. The 328-error residual from the tightened config (`color-named`, `no-duplicate-selectors`, disallowed units, zero-length suffixes, single-line declarations) was cleared by hand in `81ddf3d63`: named colors → hex equivalents, `ch`/`pt`/`svh`/`turn` → allowed units, duplicate selectors merged into their latest occurrence (preserving cascade winner semantics).

**Step 3 — Delete orphans.**

```bash
git rm themes/_base/fix-khtml.css
git rm themes/_base/fix-ie5-ie6.css themes/_base/fix-ie7.css
# Drop the IE conditional-comment block in themes/_base/local_head.tpl
# Drop the broken themes/admin/_base/fix-ie7.css <link> in install.tpl + upgrade.tpl
```

IE conditional comments are inert in modern browsers; the install/upgrade `<link>` already points to a non-existent file. All four references are dead.

**Step 4 — Split `themes/_base/theme.css`** (1,305 lines) along its section markers into the per-concern files listed in the target layout above. `themes/_base/theme.css` becomes an `@import` list. `themes/_base/template/header.tpl` is unchanged — it still loads `theme.css`.

**Step 5 — Collapse search CSS variants.**
Replace `themes/_base/css/{search,clear-search,dark-search}.css` with a single `search.css` using `--search-*` CSS variables:

```css
/* search.css — variable-driven */
.filter .filter-icon {
  color: var(--search-icon);
}
.filter-manager-popin {
  background-color: var(--search-popin-bg);
}
```

Each colorscheme supplies its `--search-*` variable set in its theme `:root` block. Drop the `{combine_css path="themes/_base/css/{$themeconf.colorscheme}-search.css"}` load in `themes/_base/template/include/search_filters.inc.tpl:3`. Net savings: ~500 lines.

**Step 6 — Non-color design tokens at theme root.**
Add a single `:root {}` token block at the top of `themes/_base/theme.css` (or its post-split `colors.css` / new `tokens.css`) and `themes/admin/_base/theme.css`:

```css
:root {
  --space-xs: 5px;
  --space-sm: 10px;
  --space-md: 15px;
  --space-lg: 20px;
  --space-xl: 30px;
  --font-size-sm: 13px;
  --font-size-base: 15px;
  --font-size-lg: 20px;
  --line-height-base: 1.5;
  --radius-sm: 5px;
  --radius-md: 10px;
  --z-dropdown: 100;
  --z-overlay: 500;
  --z-modal: 1000;
  --bp-sm: 576px;
  --bp-md: 800px;
  --bp-lg: 1100px;
}
```

Replace hardcoded values throughout. Canonical breakpoints: `sm=576px md=800px lg=1100px` — adopt project-wide, add `/* Breakpoints: sm=576px md=800px lg=1100px */` header in every file that uses media queries.

**Step 7 — Color tokens for `themes/standard_pages/`.**
Emit a `:root {}` color block at the top of `themes/standard_pages/theme.css` covering all hardcoded colors currently in the parent (accent, border, button bg/fg, focus ring, modal divider, etc.). Replace direct color literals in the parent with `var(--color-*)`.

**Step 8 — Refactor `themes/standard_pages/skins/*.css` (eliminates ~220 `!important`).**
With the token system in place each skin's only job is to override variable values. Transform each from ~337 lines of element-level overrides to a single `:root {}` block:

```css
/* default.css — before: 333 lines, 20× !important */
/* default.css — after:  ~30 lines, 0× !important */
:root {
  --color-accent: #f70;
  --color-button-secondary-bg: #ececec;
  --color-button-secondary-fg: #3c3c3c;
  --color-divider: #d8d8d8;
  /* … */
}
```

The `!important` in skin files exists solely because they fight specificity with `themes/standard_pages/theme.css`. Once both use the same variable names, there is nothing to fight.

**Step 9 — (deleted; was modus split — modus is no longer in the codebase).**

**Step 10 — Introduce CSS design tokens in admin parent.**
Create `themes/admin/_base/css/base.css.tpl` (Smarty-templated):

```smarty
:root {
  --admin-bg:      {$admin_skin.page.backgroundColor};
  --admin-fg:      {$admin_skin.page.color};
  --admin-accent:  {$admin_skin.accent};
  --admin-border:  {$admin_skin.border};
}
```

Each `themeconf.inc.php` for `light` and `dark` defines its `$admin_skin` array. `themes/admin/_base/template/header.tpl` loads `base.css.tpl` with `template=true` before the split CSS. Removes the current `{combine_css path="themes/admin/`$theme.id`/css/components/general.css" order=-9} {* Temporary solution *}` workaround in `header.tpl:24`.

**Step 11 — Split `themes/admin/_base/theme.css`** (9,635 lines) along its 60+ `/* name.css */` section markers into the target layout in the directory tree above. `themes/admin/_base/theme.css` becomes an `@import` list. Note: file grew from 8,375 to 9,635 lines because Step 16 (inline-style extraction) added utility classes (`.u-*`) here; those should land in a `base/utilities.css` during the split.

**Step 12 — Slim admin child themes.**
With `var(--admin-*)` in place, `themes/admin/light/theme.css` (1,234 lines) and `themes/admin/dark/theme.css` (2,837 lines) reduce to `:root {}` variable override blocks. Structural rules currently duplicated in both (borders, padding, grid, `@keyframes`) move up into the parent's split CSS. Same treatment for `themes/admin/dark/css/components/general.css` — content moves into the parent. (`themes/admin/light/` has no `css/` subdirectory; only its `theme.css` needs slimming.)

**Step 13 — (deleted; plugin CSS quick-wins; the named plugins — GDThumb, AdminTools, language_switch — are no longer in the tree).**

**Step 14 — (deleted; TakeATour relocation; plugin no longer in the tree).**

**Step 15 — `!important` final elimination pass.**
Work through Tier 2 (tom-select: `batch_manager_unit.css`, `picture_modify.css`, `albums.css`, `user-list.css`) then Tier 3 file-by-file from largest to smallest. After each file: `bunx stylelint --fix <file>` + browser smoke-test of that admin page. Keep all Tier 1 instances; add `/* reason */` comment to any that are missing one. Once the count is low enough, reinstate `declaration-no-important` as a Stylelint warning (it was dropped in `9a482db86`).

**Step 16 — Extract static inline `<style>` blocks.** ✅ Done.
Every static template's CSS moved to `css/pages/<name>.css`, replaced with `{combine_css path="themes/admin/_base/css/pages/<name>.css"}`. `install.tpl` and `upgrade.tpl` share `install-upgrade.css`. Plus the dynamic blocks (`thumbnails`, `mainpage_categories`, `comment_list`, `month_calendar`, `batch_manager_global` first block) migrated via CSS custom properties on the wrapping element. See `PLAN-inline-assets-extraction.md`.

### Verification

After each step:

1. `bun run lint:css` (alias for `bunx stylelint "**/*.css"`) — error/warning count decreasing.
2. Visual smoke-test on the admin pages touched: dashboard, each sidebar section, toggle clear/roma via the head button, gallery index/category/picture, search popin (light + dark), at least 3 `standard_pages` skins.
3. Network tab: confirm same or fewer CSS requests (the `{combine_css}` combiner bundles source files; more source files must not multiply requests).
4. Diff `_data/combined/t*.css` before and after — concatenated output should be byte-for-byte similar modulo ordering.

```bash
bun run lint:css                                            # zero errors
git ls-files | grep -E "fix-(khtml|ie5-ie6|ie7)"            # empty (after Step 3)
git ls-files | grep -E "clear-search|dark-search"           # empty (after Step 5)
wc -l themes/admin/_base/theme.css                        # ≤ 30 (just @imports, after Step 11)
wc -l themes/_base/theme.css                              # ≤ 15 (just @imports, after Step 4)
grep -rn "!important" themes/standard_pages/skins/          # empty (after Step 8)
```

---

## #2 — A11y audit (axe-core in Playwright)

**Status:** Not started &nbsp;|&nbsp; **Size:** M

### Goal

Integrate `@axe-core/playwright` into the existing E2E suite. WCAG 2.1 AA violations of severity _moderate_ and above fail CI. Existing violations are triaged: fixable ones get fixed, justified exemptions go into a documented allowlist.

### Current state

- **Zero accessibility testing.** 16 Playwright E2E specs in `tests/e2e/` cover functional flows but never invoke axe-core.
- No `aria-*` audit, no focus-management tests, no contrast checks.
- Color contrast issues are likely once #1 design tokens land — values currently inline are easier to evaluate against tokenized variables.
- `package.json` has Playwright; no axe-core dependency yet.

### Steps

1. **Install dependencies.**

   ```bash
   npm i -D @axe-core/playwright axe-core
   ```

2. **Helper at `tests/e2e/utils/a11y.ts`.**

   ```typescript
   import AxeBuilder from '@axe-core/playwright';
   import { expect, Page } from '@playwright/test';

   export async function runA11y(page: Page, opts: { disable?: string[] } = {}) {
     const results = await new AxeBuilder({ page })
       .withTags(['wcag21aa', 'wcag2aa'])
       .disableRules(opts.disable ?? [])
       .analyze();
     const blocking = results.violations.filter((v) =>
       ['critical', 'serious', 'moderate'].includes(v.impact ?? '')
     );
     expect(blocking, JSON.stringify(blocking, null, 2)).toEqual([]);
   }
   ```

3. **Wrap critical pages.** Add `runA11y(page)` calls in existing E2E specs covering: gallery index, picture page, search results, login, register, admin dashboard, batch manager, picture edit, user management.

4. **Initial sweep.** Run the augmented suite once. Snapshot every violation. Triage:
   - **Fixable:** open one task per violation; fix in template / CSS / TypeScript.
   - **Accepted with rationale:** add to a per-page `disable: [rule-id]` list with an inline comment explaining why (e.g., "color-contrast: this is a brand-color admonition; contrast verified manually at 4.6:1 by the design team").
   - **Out of scope:** vendor 3rd-party libraries (Tom Select, jqTree) — listed in a global allowlist with version-pinned rationale.

5. **Pair with the design tokens work (#1).** Most color-contrast violations dissolve once colors come from `--color-*` variables — central change fixes all callers.

6. **CI gate.** The Playwright job (already in CI) now also enforces a11y. Any new violation fails the build.

7. **Document.** Add an "Accessibility" section to `CONTRIBUTING.md` with the rationale-on-disable rule and the workflow for adding new pages to the audit.

### Verification

```bash
npx playwright test tests/e2e/                              # all pages green, a11y included
npx playwright test --grep '@a11y'                          # focused a11y-only run

# Regression check: introduce a button without label, expect failure
echo '<button>X</button>' > /tmp/probe && \
  ! npx playwright test tests/e2e/probe.spec.ts             # exits non-zero
```
