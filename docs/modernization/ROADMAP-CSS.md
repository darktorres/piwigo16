# Piwigo 16.x — Modernization Roadmap (CSS)

CSS / theme modernization work. See [MODERNIZATION.md](MODERNIZATION.md) for architecture context and completed phase summaries; see [ROADMAP-PHP.md](ROADMAP-PHP.md) and [ROADMAP-TS.md](ROADMAP-TS.md) for the other tracks.

---

## #1 — CSS design tokens + Stylelint

**Status:** Not started &nbsp;|&nbsp; **Size:** M

### Goal

- Stylelint passing for all 31 authored CSS files with zero errors and no `!important` outside JS-toggled visibility rules.
- CSS custom properties for all repeated colors, spacing values, and breakpoints.
- Monster files split into per-concern files: `admin/themes/default/theme.css` (8,375 lines) and `themes/default/theme.css` (1,122 lines) become thin `@import` lists.
- Admin child themes (`clear`, `roma`) reduced to `:root {}` variable override blocks.
- `themes/modus/` skins refactored from hundreds of element overrides to single `:root {}` blocks.

### Current state

- `admin/themes/default/theme.css`: **8,375 lines**, monolithic (60+ `/* name.css */` section markers baked in).
- `admin/themes/roma/theme.css`: **2,716 lines** — duplicates parent section headers and carries far more than color overrides.
- `admin/themes/clear/theme.css`: **1,392 lines** — same problem.
- `themes/default/theme.css`: **1,122 lines**, unsplit.
- `themes/default/fix-khtml.css`: **16 lines**, orphan — zero references anywhere in the repo.
- `themes/default/css/clear-search.css` + `dark-search.css`: **295 + 298 lines** — color-only variants duplicating `search.css` structure.
- **572 `!important` declarations** across 31 project CSS files. Grouped:
  - ~230 justified (child-theme load-order, plugin overrides, JS-toggled visibility) — keep permanently.
  - 22 tom-select overrides — fix with higher specificity.
  - ~176 in `themes/modus/skins/*.css` — disappear when token system lands.
  - ~95 internal specificity battles across ~20 files — fix file-by-file.
- Stylelint configured but `ignoreFiles` excludes whole theme directories instead of just vendor libraries.
- Zero CSS custom properties anywhere in project CSS.
- No canonical breakpoints — `576px`, `640px`, `800px`, `1100px` used inconsistently.

### File inventory (31 authored files in scope)

**Frontend themes:**

- `themes/modus/css/hf_base.css` (917 lines), `plugin_compatibility.css`, `tags.css`
- `themes/modus/skins/*.css` — 11 skin files (avocado, blueberry, cafe_latte, glacier, neon_orange, neon_pink, newspaper, quartz, splash, strawberry_jam, swimming_pool)
- `themes/default/theme.css`, `css/search.css`, `css/clear-search.css`, `css/dark-search.css`
- `themes/elegant/theme.css`, `themes/smartpocket/theme.css`, `themes/bootstrap_darkroom/theme.css`

**Admin themes:**

- `admin/themes/default/theme.css`, `css/components/general.css`
- `admin/themes/clear/theme.css`, `css/components/general.css`
- `admin/themes/roma/theme.css`, `css/components/general.css`

**Plugins:**

- `plugins/GDThumb/css/gdthumb.css`, `admin.css`
- `plugins/AdminTools/template/admin_style.css`, `public_style.css`
- `plugins/language_switch/language_switch.css`, `style.css`

**Out of scope (vendor):** `themes/bootstrap_darkroom/css/**`, fontello files, open-sans files, `themes/default/js/plugins/selectize.*.css`, `themes/elegant/admin/jquery.ui.button.css`.

### Inline `<style>` block inventory ✅ Extracted

All static `<style>` and `{html_style}` blocks were extracted to `css/pages/<name>.css` files via `{combine_css}`. The dynamic blocks initially flagged "must stay inline" — `batch_manager_global.tpl` (first block), `thumbnails.tpl`, `month_calendar.tpl`, `mainpage_categories.tpl`, `comment_list.tpl` — were also migrated using the **CSS custom property pattern**: the wrapper element carries `style="--var: value"` (governed by CSP `style-src-attr`, separate from `style-src`) while the consuming rules live in static CSS. See `PLAN-inline-assets-extraction.md` for the full record.

Result: **0 `<style>` tags and 0 `{html_style}` blocks remain** in `themes/default/`, `themes/standard_pages/`, `admin/themes/default/` (excluding `mail/text/html/` which intentionally keeps inline styles for email clients, and the `themes/modus/`/`themes/smartpocket/`/plugin templates which are out of the 16.x core scope).

### `!important` tier breakdown

**Tier 1 — Keep permanently (~230 instances).** Add `/* reason */` comment where missing.

| Reason | Files | Count |
|--------|-------|-------|
| Child-theme load-order (child CSS loads before parent; overrides need `!important` until CSS variable migration is complete) | `admin/themes/roma/theme.css`, `admin/themes/clear/theme.css` | ~97 |
| Third-party plugin CSS override (mcs-search, masonry inject their own CSS) | `plugin_compatibility.css`, `search.css`, `dark-search.css`, `clear-search.css` | ~33 |
| Modus skin overrides of mcs-search (already commented) | All 9 skin files | ~83 |
| Masonry/JS inline position overrides | `hf_layout.css`, `hf_responsive.css`, `thumbnails.css` | ~7 |
| JS-toggled visibility (`display: none/flex/block`) | `user_activity.css`, `upload.css`, `user_list.css`, `icons.css`, `smartpocket/theme.css`, `search-in-set.css` | ~7 |
| Tag cloud JS inline-style override | `themes/modus/css/tags.css` | ~5 |

**Tier 2 — Fix with higher specificity: tom-select overrides (22 instances).**
`batch_manager_unit.css` (11) and `picture_modify.css` (11) contain an identical block styling `.ts-control .item` at specificity (0,2,0). Tom-select ships `.ts-control > .item` at (0,1,1). Our (0,2,0) already wins — `!important` is redundant. Fix: verify against `node_modules/tom-select/dist/css/tom-select.css`, then drop `!important`. Extract the shared block into `admin/themes/default/css/components/tomselect-item.css`.

**Tier 3 — Fix internal specificity battles (~95 instances, ~20 files).**
These exist because the original monolithic `theme.css` relied on cascade order within one file; when split, overrides lost their position advantage.

| File | Count | Notes |
|------|-------|-------|
| `css/pages/album-manager.css` | 16 | Mixed: tom-select items (Tier 2 pass) + layout overrides |
| `css/pages/dashboard.css` | 11 | Pure specificity: cursor, display, margin, padding, text-decoration |
| `css/pages/user-manager.css` | 8 | `background: var(--admin-*)` fighting higher-specificity parent rules |
| `css/pages/plugins.css` | 7 | `display: grid/inline/flex`, `margin-right/left: 0` |
| `css/pages/user_list.css` | 6 | Excluding 2 JS-toggled display:none (keep) |
| `css/pages/albums.css` | 6 | Tom-select `.item` (Tier 2 fix) + margin |
| `css/pages/cat-list.css` | 5 | `var(--admin-*)` + transform — fighting higher specificity |
| `css/components/icons.css` | 3 | Excluding 1 JS-toggled |
| `css/pages/watermark.css` | 3 | |
| `css/pages/history.css` | 3 | |
| Remaining single-instance files | ~10 | tabsheets, content, batch-manager, picture-edit, themes/elegant, colors.css, picture.css |

Approach per instance: (1) note the property + selector, (2) grep for conflicting rule, (3) fix by raising specificity, lowering source rule specificity, or reordering within the file.

### Target directory layout

**`admin/themes/default/css/`** (post-split):

```text
base/
  reset-defaults.css       ← "General defaults", forms, "Tables & forms"
  typography.css
components/
  general.css              ← (existing file, unchanged)
  pagination.css
  waiting.css
  tipTip.css
  menubar.css
  tabsheets.css
  dropdown.css
  search-bar.css
  datepicker.css
  webkit-hacks.css
  tomselect-item.css       ← shared Tier 2 fix (extracted from batch_manager_unit + picture_modify)
pages/
  dashboard.css
  history.css
  batch-manager.css
  tag-manager.css
  picture-edit.css         ← "Picture Edit" + "Format tab"
  album-manager.css        ← "Album Manager" + "album search" + "Move Album"
  user-manager.css         ← "UserList Pop in" + "Edit user popin" + "Activity Tab"
  comments.css             ← "Pending Comments"
  watermark.css
  upload.css               ← "Add photos, direct mode" + "Upload Form"
  plugins.css
  install-upgrade.css
  intro.css
  rating.css
  rating-user.css
  cat-modify.css
  cat-list.css
  cat-search.css
  cat-perm.css
  user-activity.css
  user-list.css
  albums.css
  batch_manager_unit.css   ← (existing file, keep name)
  batch_manager_global.css
  picture_modify.css       ← (existing file, keep name)
features/
  selection-mode.css
  merge-options.css
  group-editor.css
  jqtree-overrides.css
  icons.css
theme.css                  ← thin entry: @import the above in order
```

**`themes/default/css/`** (post-split):

```text
menubar.css
content.css
picture.css
layout.css
colors.css
forms.css
calendar.css
thumbnails.css
comments.css
popup.css
search.css                 ← variable-driven (replaces search.css + clear-search.css + dark-search.css)
iconset.css                ← (unchanged)
print.css
theme.css                  ← thin entry: @import the above
```

### Steps

Execute in this order (risk-free first):

**Step 1 — Extend Stylelint coverage.**
Update `.stylelintrc.json` `ignoreFiles` to exclude only vendor libraries:

```json
"ignoreFiles": [
  "node_modules/**", "dist/**", "tests/**",
  "themes/bootstrap_darkroom/css/**",
  "themes/modus/css/open-sans/**",
  "themes/modus/css/fontello/**",
  "themes/default/fontello/**",
  "themes/default/js/plugins/**",
  "themes/elegant/admin/**"
]
```

Add new rules: `"declaration-no-important": [true, {"severity": "warning"}]`, `"custom-property-pattern": "^[a-z][a-z0-9-]*$"`, `"color-no-invalid-hex": true`, `"shorthand-property-no-redundant-values": true`. Record new baseline (expected: ~3,557 errors, 572 warnings across 31 files).

**Step 2 — Mechanical auto-fix.**

```bash
bunx stylelint --fix $(git ls-files '*.css' | grep -v node_modules | grep -v dist)
```

Eliminates all auto-fixable formatting errors (empty lines, whitespace, shorthand redundancy, pseudo-element `::` notation) without touching logic. Rerun lint, record new baseline.

**Step 3 — Delete the orphan.**

```bash
git rm themes/default/fix-khtml.css
```

**Step 4 — Split `themes/default/theme.css`** along its `/** Menubar / Content / Picture / Default Layout / Default colors / Tables & forms */` section markers into the per-concern files listed in the target layout above. `themes/default/theme.css` becomes an `@import` list. `themes/default/template/header.tpl` is unchanged — it still loads `theme.css`.

**Step 5 — Collapse search CSS variants.**
Replace `search.css` + `clear-search.css` + `dark-search.css` with a single `search.css` using `--search-*` CSS variables:

```css
/* search.css — variable-driven */
.filter .filter-icon        { color: var(--search-icon); }
.filter-manager-popin       { background-color: var(--search-popin-bg); }
```

Each skin supplies its `--search-*` variable set (either in its `theme.css` `:root` block or a dedicated skin file). Drop the `{$themeconf.colorscheme}-search.css` load in `themes/default/template/inc/search_filters.inc.tpl:4`. Net savings: ~500 lines.

**Step 6 — Non-color design tokens in `themes/modus/css/hf_base.css`.**
Add at top:

```css
:root {
  --space-xs: 5px;   --space-sm: 10px;  --space-md: 15px;
  --space-lg: 20px;  --space-xl: 30px;
  --font-size-sm: 13px; --font-size-base: 15px; --font-size-lg: 20px;
  --line-height-base: 1.5;
  --radius-sm: 5px;  --radius-md: 10px;
  --z-dropdown: 100; --z-overlay: 500;  --z-modal: 1000;
  --bp-sm: 576px;    --bp-md: 800px;    --bp-lg: 1100px;
}
```

Replace all hardcoded values throughout the file. Canonical breakpoints: `sm=576px md=800px lg=1100px` — adopt project-wide, add `/* Breakpoints: sm=576px md=800px lg=1100px */` header in every file that uses media queries.

**Step 7 — Color tokens from PHP skin system in `themes/modus/css/base.css.tpl`.**
Emit ALL `$skin` colors as CSS variables once at top:

```css
:root {
  --color-bg:           {$skin.BODY.backgroundColor};
  --color-text:         {$skin.BODY.color};
  --color-link:         {$skin.A.color};
  --color-link-hover:   {$skin['A:hover'].color};
  --color-menubar-bg:   {$skin.menubar.backgroundColor|default:''};
  --color-menubar-text: {$skin.menubar.color|default:''};
  --color-dropdown-bg:  {$skin.dropdowns.backgroundColor|default:''};
  /* … all $skin keys */
}
```

Replace all inline `{$skin.*}` injections in the rest of `base.css.tpl` with `var(--color-*)`.

**Step 8 — Refactor modus skin files (eliminates 176 `!important`).**
With the token system in place each skin's only job is to override variable values. Transform each from hundreds of element-level overrides to a single `:root {}` block:

```css
/* avocado.css — before: 957 lines, 27× !important */
/* avocado.css — after: ~40 lines, 0× !important */
:root {
  --color-accent:      #74bf04;
  --color-accent-dark: #65a603;
}
```

The `!important` in skin files exists solely because they fight specificity with `hf_base.css`. Once both use the same variable names, there is nothing to fight.

**Step 9 — Split `themes/modus/css/hf_base.css`** (917 lines) into four files loaded via `{combine_css}` in `themes/modus/template/header.tpl`:

| New file | Content |
|----------|---------|
| `hf_layout.css` | Structural rules, containers |
| `hf_components.css` | Nav, menus, thumbnails, forms |
| `hf_typography.css` | Font sizes, headings, links |
| `hf_responsive.css` | All `@media` blocks |

**Step 10 — Introduce CSS design tokens in admin parent.**
Create `admin/themes/default/css/base.css.tpl` (Smarty-templated, following the modus pattern):

```smarty
:root {
  --admin-bg:      {$admin_skin.page.backgroundColor};
  --admin-fg:      {$admin_skin.page.color};
  --admin-accent:  {$admin_skin.accent};
  --admin-border:  {$admin_skin.border};
}
```

Each `themeconf.php` for `clear` and `roma` defines its `$admin_skin` array. `admin/themes/default/template/header.tpl` loads `base.css.tpl` with `template=true` before the split CSS.

**Step 11 — Split `admin/themes/default/theme.css`** along its 60+ `/* name.css */` section markers into the target layout in the directory tree above. `admin/themes/default/theme.css` becomes an `@import` list.

**Step 12 — Slim admin child themes.**
With `var(--admin-*)` in place, `admin/themes/clear/theme.css` (1,392 lines) and `admin/themes/roma/theme.css` (2,716 lines) reduce to `:root {}` variable override blocks. Structural rules currently duplicated in both (borders, padding, grid, `@keyframes`) move up into the parent's split CSS.

**Step 13 — Plugin CSS: remove `!important` (quick wins).**

- `plugins/GDThumb/css/gdthumb.css:8` `background: inherit !important` — specificity of `ul.thumbnails .gdthumb` is sufficient, remove.
- `plugins/GDThumb/css/admin.css:41,42,46` — raise selector to `.GDThumb_config input[type="text"]`.
- Same treatment for `plugins/AdminTools/` and `plugins/language_switch/` instances.

**Step 14 — Relocate TakeATour skin files.**
Move the bodies of `plugins/TakeATour/css/clear.css` and `plugins/TakeATour/css/roma.css` into the corresponding admin skin CSS (or `admin/themes/{clear,roma}/css/takeatour.css`). Simplify `plugins/TakeATour/tpl/js_css.tpl:3-4`.

**Step 15 — `!important` final elimination pass.**
Work through Tier 2 (tom-select: `batch_manager_unit.css`, `picture_modify.css`, `albums.css`) then Tier 3 file-by-file from largest to smallest. After each file: `bunx stylelint --fix <file>` + browser smoke-test of that admin page. Keep all Tier 1 instances; add `/* reason */` comment to any that are missing one.

**Step 16 — Extract static inline `<style>` blocks.** ✅ Done.
Every static template's CSS moved to `css/pages/<name>.css`, replaced with `{combine_css path="admin/themes/default/css/pages/<name>.css"}`. `install.tpl` and `upgrade.tpl` share `install-upgrade.css`. Plus the dynamic blocks (`thumbnails`, `mainpage_categories`, `comment_list`, `month_calendar`, `batch_manager_global` first block) migrated via CSS custom properties on the wrapping element. See `PLAN-inline-assets-extraction.md`.

### Verification

After each step:

1. `bunx stylelint "**/*.css" --ignore-path .stylelintignore` — error/warning count decreasing.
2. Visual smoke-test on the admin pages touched: dashboard, each sidebar section, toggle clear/roma via the head button, gallery index/category/picture, search popin (light + dark), 3+ modus skins.
3. Network tab: confirm same or fewer CSS requests (the `{combine_css}` combiner bundles source files; more source files must not multiply requests).
4. Diff `_data/combined/t*.css` before and after — concatenated output should be byte-for-byte similar modulo ordering.

```bash
bunx stylelint "**/*.css" --ignore-path .stylelintignore   # zero errors, ≤100 warnings (Tier 1 keeps)
grep -rn "fix-khtml" .                                      # empty
grep -rn "clear-search\|dark-search" themes/               # empty
wc -l admin/themes/default/theme.css                        # ≤ 30 (just @imports)
wc -l themes/default/theme.css                             # ≤ 15 (just @imports)
grep -rn "!important" themes/modus/css/skins/              # empty (after Step 8)
```

---

## #2 — A11y audit (axe-core in Playwright)

**Status:** Not started &nbsp;|&nbsp; **Size:** M

### Goal

Integrate `@axe-core/playwright` into the existing E2E suite. WCAG 2.1 AA violations of severity *moderate* and above fail CI. Existing violations are triaged: fixable ones get fixed, justified exemptions go into a documented allowlist.

### Current state

- **Zero accessibility testing.** 15 Playwright E2E specs cover functional flows but never invoke axe-core.
- No `aria-*` audit, no focus-management tests, no contrast checks.
- Color contrast issues are likely once #1 design tokens land — values currently inline are easier to evaluate against tokenized variables.
- `package.json` already has Playwright 1.48; no axe-core dependency yet.

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
       const blocking = results.violations.filter(v =>
           ['critical', 'serious', 'moderate'].includes(v.impact ?? ''));
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
