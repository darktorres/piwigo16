# Piwigo 16.x — Modernization Roadmap (CSS)

CSS / theme modernization work. See [MODERNIZATION.md](MODERNIZATION.md) for architecture context and completed phase summaries; see [ROADMAP-PHP.md](ROADMAP-PHP.md) and [ROADMAP-TS.md](ROADMAP-TS.md) for the other tracks.

---

## #1 — CSS design tokens + Stylelint

**Status:** Done &nbsp;|&nbsp; **Size:** M

### Goal

- Stylelint passing for all first-party CSS with zero errors and no `!important` outside JS-toggled visibility rules.
- CSS custom properties for all repeated colors, spacing values, and breakpoints.
- Monster files split into per-concern files: `themes/admin/_base/theme.css` (9,635 lines) and `themes/_base/theme.css` (1,305 lines) become thin `@import` lists.
- Admin child themes (`light`, `dark`) reduced to `:root {}` variable override blocks.
- `themes/standard_pages/skins/*.css` refactored from hundreds of element overrides to single `:root {}` blocks.

### Final state

- `themes/admin/_base/theme.css`: **12 lines** — thin `@import` list of 51 split files.
- `themes/admin/dark/theme.css`: **1,127 lines** (down from 2,837 — −60%).
- `themes/admin/light/theme.css`: **374 lines** (down from 1,234 — −70%).
- `themes/_base/theme.css`: **11 lines** — thin `@import` list.
- Orphan files deleted: `fix-khtml.css`, `fix-ie5-ie6.css`, `fix-ie7.css`.
- `clear-search.css` + `dark-search.css` collapsed into `search.css`.
- `themes/standard_pages/skins/*.css`: 11 skin files, each ~11 lines, 0 `!important`.
- **51 `!important` declarations** remain in theme CSS (+ 3 in `tools/ws/`), all justified and documented with `/* keep: reason */`.
- Stylelint: **0 errors, 53 warnings** (`declaration-no-important` reinstated as warning).
- **~2,040 `var(--*)` references** across 93 CSS files.
- **93 admin tokens** (`--admin-*`) + **42 frontend tokens** (colors, spacing, font-size, radius, z-index).
- Breakpoints remain as component-level literals (CSS `@media` can't use `var()`).

### File inventory (in scope)

**Frontend themes:**

- `themes/_base/theme.css` (11-line `@import` list), `css/search.css`, plus per-page CSS (`thumbnails.css`, `month_calendar.css`, `mainpage_categories.css`, `comment_list.css`, `redirect.css`, `no-photo-yet.css`)
- `themes/standard_pages/theme.css` + 11 skin files in `skins/`

**Admin themes:**

- `themes/admin/_base/theme.css` (12-line `@import` list), `css/base/*.css`, `css/components/*.css`, `css/pages/*.css`, `css/features/*.css` (51 files total)
- `themes/admin/light/theme.css`
- `themes/admin/dark/theme.css`, `css/components/general.css`

**Out of scope (vendor / ignored by Stylelint):** `node_modules/**`, `dist/**`, `_data/**`, `vendor/**`, `plugins/**`, `tests/**`, fontello files, open-sans files, `themes/_base/js/plugins/**`, `themes/_base/vendor/fontello/**`, `themes/admin/_base/{fontello,fonts}/**`, `**/*.min.css`.

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

**Step 3 — Delete orphans.** ✅ Done.
`fix-khtml.css`, `fix-ie5-ie6.css`, `fix-ie7.css` removed along with
their IE conditional-comment references in templates.

**Step 4 — Split `themes/_base/theme.css`.** ✅ Done.
`themes/_base/theme.css` is now 11 lines — a thin `@import` list of
per-concern files (`tokens.css`, `utilities.css`, `menubar.css`,
`content.css`, `calendar.css`, `gallery.css`, `picture.css`,
`layout.css`, `forms.css`, `colors.css`).

**Step 5 — Collapse search CSS variants.** ✅ Done (`5727a1d86`).
Merged `clear-search.css` (98 lines) and `dark-search.css` (73 lines)
into `search.css`. No theme set `colorscheme: 'clear'`, so dark tokens
became the only set. The `{=cssLink(...colorscheme-search.css)}` load
in `search_filters.inc.latte` was removed. Eliminated 4 `!important`
declarations that existed solely to override the clear variant.
Date-created alternating-row/checked/selected rules (previously
clear-only) were merged into the shared selector lists so they now
work in dark mode too.

**Step 6 — Non-color design tokens at theme root.** ✅ Done
(`3f0ae055d`, `9e9f01310`, `d01a55d98`, `5eb73cd4b`, `351f5449d`,
`5480ad8b2`).

Shipped token set (lives in both `themes/_base/css/tokens.css` and
`themes/admin/_base/css/base/tokens.css`):

  - Spacing scale: `--space-xs` (5) / sm (10) / md (15) / lg (20) / xl (30)
  - Font sizes: `--font-size-xxs` (11) / xs (12) / sm (13) / md (14) / base (15) / lg (16) / xl (20) / xxl (22)
  - Line height: `--line-height-base` (1.5)
  - Border radius: `--radius-sm` (5) / md (10) / lg (15) / xl (20)
  - Z-index: `--z-dropdown` (100) / overlay (500) / modal (1000)

Migrations applied: border-radius (33 files, 138 declarations),
font-size (38 files, ~218 declarations), padding/margin/gap with all
12 longhand variants (57 files, 623 declarations), z-index: 100 (11
files, 18 declarations).

Deviations from the original spec:
  - Added `--font-size-md` (14px) and `--font-size-lg` (16px) — actual
    usage frequency demanded them (each ~50 occurrences).
  - Added `--font-size-xxs` (11px) and `--font-size-xxl` (22px) in
    Phase 8 — 14 and 5 call-sites respectively.
  - Added `--radius-lg` (15px) and `--radius-xl` (20px) — same reason.
  - `--bp-*` breakpoint tokens NOT introduced. CSS `@media`
    expressions can't substitute `var()` values, so the tokens would
    be defined-only. 18 unique breakpoints are all component-specific.
  - `--line-height-base` defined but no current `line-height: 1.5`
    callsites to migrate; available for future use.

**Step 7 — Color tokens for `themes/standard_pages/`.** ✅ Done
(`38beb8ced`).

Extended the standard_pages `:root` block from 6 to 28 tokens.
Migrated 57 of 59 hardcoded color literals in
`themes/standard_pages/theme.css` to `var(--color-*)` references.

Token groups (mirrored from the commit message):
  - Surfaces & text (light + dark mode)
  - Status (success/info/error in light + dark variants)
  - Borders, dividers, accent variants
  - Disabled / secondary button states

The original 6 per-skin tokens (`--color-accent`,
`--color-btn-primary-fg`, `--color-light-gradient`, `--color-dark-link`,
`--color-dark-link-hover`, `--color-dark-header-link`) keep their
per-skin override behavior — the 11 skin files continue to override
only these. The 22 new tokens are constants across skins today; they
sit in `:root` as named centralization points so future overrides are
cheap. Two one-off literals stay hardcoded (a single 1-use muted
color in the api section and one `color: #fff` on a light-mode close
button).

**Step 8 — Refactor `themes/standard_pages/skins/*.css`.** ✅ Done.
Each of the 11 skin files is now ~11 lines — a `:root {}` block
overriding accent/button/link tokens. 0 `!important` across all skins
(down from ~220). Total skin CSS: 122 lines.

**Step 9 — (deleted; was modus split — modus is no longer in the codebase).**

**Step 10 — Admin CSS design tokens.** ✅ Done (static approach).
Superseded the original Smarty-templated plan. Tokens live in
`themes/admin/_base/css/base/tokens.css` (93 `--admin-*` definitions)
with dark `:root {}` overrides in `themes/admin/dark/theme.css` (67
overrides). No Smarty `.css.tpl` needed — static CSS custom properties
handle theming directly.

**Step 11 — Split `themes/admin/_base/theme.css`.** ✅ Done.
`themes/admin/_base/theme.css` is now 12 lines — a thin `@import` list.
The 9,635-line monolith was split into 51 files across `base/`,
`components/`, `pages/`, and `features/` subdirectories.

**Step 12 — Slim admin child themes.** ✅ Done.

Final state:

| file | start | final | Δ |
|---|---:|---:|---:|
| `themes/admin/dark/theme.css` | 2,837 | 1,127 | -1,710 (-60%) |
| `themes/admin/light/theme.css` | 1,234 | 374 | -860 (-70%) |
| `themes/admin/_base/css/base/tokens.css` | 26 | 93 tokens | +67 |

Both child files carry a `:root {}` token override block plus
selector-level overrides that fall into three documented categories:
(1) genuine one-off colors, (2) third-party widget overrides,
(3) asymmetric prop sets (Bucket X). Every remaining selector has a
`/* keep: reason */` comment. The 93-token admin palette covers
surfaces, foreground, borders, accents, links, inputs, row stripes,
popin chrome, danger, disabled, chart colors, brand, and close-modal.

`themes/admin/dark/css/components/general.css` was reformed during
the Step 11 split — admin/_base now owns the general.css structure
with `--admin-filter-*` / `--admin-info-*` tokens.

**Step 13 — (deleted; plugin CSS quick-wins; the named plugins — GDThumb, AdminTools, language_switch — are no longer in the tree).**

**Step 14 — (deleted; TakeATour relocation; plugin no longer in the tree).**

**Step 15 — `!important` final elimination pass.** ✅ Done.
`declaration-no-important` reinstated as a Stylelint warning. 51
`!important` declarations remain across theme CSS (+ 3 in
`tools/ws/ws.css` for TipTip overrides = 53 total warnings, 0 errors).
Every instance has a `/* keep: reason */` comment. Breakdown:
  - JS-toggled visibility (`display: none/block/flex/grid`): 13
  - UA/autofill overrides: 5
  - Child-theme/variant specificity: 6
  - Internal cascade overrides (tooltips, inherited styles): ~15
  - Font-size language scale: 5
  - Button state overrides: 5
  - TipTip widget override (tools/ws): 3

**Step 16 — Extract static inline `<style>` blocks.** ✅ Done.
Every static template's CSS moved to `css/pages/<name>.css`, replaced with `{combine_css path="themes/admin/_base/css/pages/<name>.css"}`. `install.tpl` and `upgrade.tpl` share `install-upgrade.css`. Plus the dynamic blocks (`thumbnails`, `mainpage_categories`, `comment_list`, `month_calendar`, `batch_manager_global` first block) migrated via CSS custom properties on the wrapping element. See `PLAN-inline-assets-extraction.md`.

### Verification (final state)

```bash
bun run lint:css                                            # 0 errors, 53 warnings ✓
git ls-files | grep -E "fix-(khtml|ie5-ie6|ie7)"            # empty ✓
git ls-files | grep -E "clear-search|dark-search"           # empty ✓
wc -l themes/admin/_base/theme.css                          # 12 ✓
wc -l themes/_base/theme.css                                # 11 ✓
grep -rn "!important" themes/standard_pages/skins/          # empty ✓
```

---

## #2 — A11y audit (axe-core in Playwright)

**Status:** Not started &nbsp;|&nbsp; **Size:** M

### Goal

Integrate `@axe-core/playwright` into the existing E2E suite. WCAG 2.1 AA violations of severity _moderate_ and above fail CI. Existing violations are triaged: fixable ones get fixed, justified exemptions go into a documented allowlist.

### Current state

- **Zero accessibility testing.** 16 Playwright E2E specs in `tests/E2e/` cover functional flows but never invoke axe-core.
- No `aria-*` audit, no focus-management tests, no contrast checks.
- Color contrast issues are likely once #1 design tokens land — values currently inline are easier to evaluate against tokenized variables.
- `package.json` has Playwright; no axe-core dependency yet.

### Steps

1. **Install dependencies.**

   ```bash
   npm i -D @axe-core/playwright axe-core
   ```

2. **Helper at `tests/E2e/utils/a11y.ts`.**

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
npx playwright test tests/E2e/                              # all pages green, a11y included
npx playwright test --grep '@a11y'                          # focused a11y-only run

# Regression check: introduce a button without label, expect failure
echo '<button>X</button>' > /tmp/probe && \
  ! npx playwright test tests/E2e/probe.spec.ts             # exits non-zero
```
