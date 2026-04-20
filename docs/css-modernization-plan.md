# CSS Modernization Master Plan

## Context

The JS/TS stack has been fully modernized (TypeScript, Vite, stylelint, ESLint), but CSS has had no equivalent attention. The result: 572 `!important` declarations across project CSS, zero CSS custom properties anywhere, hardcoded colors/values throughout, and inconsistent media query breakpoints. This plan covers the entire project CSS — all themes, all plugins — not just modus/GDThumb.

## Full File Inventory (31 files, all in scope)

**Themes — front-end:**
- `themes/modus/css/hf_base.css` + `plugin_compatibility.css` + `tags.css`
- `themes/modus/skins/*.css` (11 files: avocado, blueberry, cafe_latte, glacier, neon_orange, neon_pink, newspaper, quartz, splash, strawberry_jam, swimming_pool)
- `themes/default/theme.css` + `css/clear-search.css` + `css/dark-search.css` + `css/search.css`
- `themes/elegant/theme.css`
- `themes/smartpocket/theme.css`
- `themes/bootstrap_darkroom/theme.css`

**Themes — admin:**
- `admin/themes/clear/theme.css`
- `admin/themes/default/theme.css`
- `admin/themes/roma/theme.css` + `css/components/general.css`
- `admin/themes/default/css/components/general.css`

**Plugins:**
- `plugins/GDThumb/css/gdthumb.css` + `admin.css`
- `plugins/AdminTools/template/admin_style.css` + `public_style.css`
- `plugins/language_switch/language_switch.css` + `style.css`

**Out of scope (vendor/third-party, already excluded or to be excluded from lint):**
- `themes/bootstrap_darkroom/css/**` (Bootstrap library ~30 files)
- `themes/modus/css/fontello/**`, `themes/default/fontello/**`
- `themes/modus/css/open-sans/**`
- `themes/default/js/plugins/selectize.*.css`
- `themes/elegant/admin/jquery.ui.button.css`

---

## Step 1 — Fix Stylelint to Cover Full Codebase

**File:** `.stylelintrc.json`

Update `ignoreFiles` to exclude only vendor libraries (not entire theme directories), so the full 31-file inventory is linted:

```json
"ignoreFiles": [
  "node_modules/**",
  "dist/**",
  "tests/**",
  "themes/bootstrap_darkroom/css/**",
  "themes/modus/css/open-sans/**",
  "themes/modus/css/fontello/**",
  "themes/default/fontello/**",
  "themes/default/js/plugins/**",
  "themes/elegant/admin/**"
]
```

New rules (already added):
```json
"declaration-no-important": [true, { "severity": "warning" }],
"custom-property-pattern": "^[a-z][a-z0-9-]*$",
"color-no-invalid-hex": true,
"shorthand-property-no-redundant-values": true
```

**Baseline after Step 1:** 3557 errors, 572 warnings across 31 files.

---

## Step 2 — Auto-fix Mechanical Issues

Run stylelint `--fix` to eliminate all auto-fixable formatting errors (empty lines, whitespace, shorthand redundancy, pseudo-element notation). This drops the error count dramatically without touching logic.

```bash
bunx stylelint --fix <all 31 files>
```

After auto-fix: rerun lint, record new baseline. Remaining errors require manual review.

---

## Step 3 — GDThumb CSS: Remove `!important` (Quick Win)

**Files:** `plugins/GDThumb/css/gdthumb.css`, `plugins/GDThumb/css/admin.css`

| Location | Fix |
|----------|-----|
| `gdthumb.css:8` `background: inherit !important` | Remove — `ul.thumbnails .gdthumb` specificity is sufficient |
| `admin.css:41,42,46` `width/margin !important` | Raise specificity: `.GDThumb_config input[type="text"]` |
| `admin.css:59` `line-height !important` | Same — more specific selector |

Same treatment for `plugins/AdminTools/` and `plugins/language_switch/` `!important` instances.

---

## Step 4 — CSS Custom Properties: Design Token Layer (BIGGEST WIN)

This is the architectural keystone for the modus theme (the most complex). The same variable-first principle applies to other themes at smaller scale.

### 4A — Non-color tokens in `hf_base.css`

Add at top of `themes/modus/css/hf_base.css`:

```css
:root {
  --space-xs: 5px;   --space-sm: 10px;  --space-md: 15px;
  --space-lg: 20px;  --space-xl: 30px;
  --font-size-sm: 13px;  --font-size-base: 15px;  --font-size-lg: 20px;
  --line-height-base: 1.5;
  --radius-sm: 5px;   --radius-md: 10px;
  --z-dropdown: 100;  --z-overlay: 500;  --z-modal: 1000;
}
```

Replace all hardcoded instances throughout the file.

### 4B — Color tokens from PHP skin system

**File:** `themes/modus/css/base.css.tpl`

Emit ALL `$skin` colors as CSS variables once at the top, instead of injecting them inline throughout:

```css
:root {
  --color-bg:            {$skin.BODY.backgroundColor};
  --color-text:          {$skin.BODY.color};
  --color-link:          {$skin.A.color};
  --color-link-hover:    {$skin['A:hover'].color};
  --color-control-bg:    {$skin.controls.backgroundColor|default:''};
  --color-menubar-bg:    {$skin.menubar.backgroundColor|default:''};
  --color-menubar-text:  {$skin.menubar.color|default:''};
  --color-dropdown-bg:   {$skin.dropdowns.backgroundColor|default:''};
  /* ... all $skin keys */
}
```

Then replace inline `{$skin.*}` injections in the rest of `base.css.tpl` with `var(--color-*)`.

### 4C — Replace hardcoded hex in `hf_base.css`

Pass through `hf_base.css` replacing hex values with `var(--color-*)`. Colors not from `$skin` become static variables in the `:root` block (e.g., `--color-border: #ddd`).

---

## Step 5 — Refactor Modus Skin CSS Files (Eliminate 176 `!important`)

**Files:** `themes/modus/skins/*.css` (11 files)

With the token system in place, each skin's only job is to override variable values. Transform each from hundreds of element-level overrides to a single `:root {}` block:

```css
/* avocado.css — before: 957 lines, 27× !important */
/* avocado.css — after: ~40 lines, 0× !important   */
:root {
  --color-accent:      #74bf04;
  --color-accent-dark: #65a603;
  --color-bg-light:    #e7ffe7;
  /* skin-specific extras beyond $skin PHP keys */
}
```

The `!important` in skin files exists because they fight specificity with `hf_base.css`. Once both use the same variables, there's nothing to fight.

---

## Step 6 — Clean Up Admin Theme CSS

**Files:** `admin/themes/clear/theme.css` (46), `admin/themes/default/theme.css` (125), `admin/themes/roma/theme.css` (139)

These are heavier `!important` users than the front-end themes. Approach:
1. Introduce `:root` variable blocks for repeated values (colors, spacing)
2. Fix specificity instead of `!important` — admin themes tend to fight each other's selectors
3. Where a rule only exists to override another rule in the same file, consolidate

---

## Step 7 — Clean Up Front-End Theme CSS

**Files:** `themes/default/theme.css`, `themes/elegant/theme.css`, `themes/smartpocket/theme.css`, `themes/bootstrap_darkroom/theme.css`, `themes/default/css/*.css`

Lighter lift than admin themes. Same pattern:
1. Introduce `:root` variables for any repeated colors/values
2. Fix specificity instead of `!important`
3. Auto-fixable issues already handled by Step 2

---

## Step 8 — Remaining `!important` Audit

After Steps 5–7, do a final pass across all 31 files:
- `display: none !important` on JS-toggled elements — **keep**, add `/* js-toggled */` comment
- Everything else — fix with specificity

Goal: zero `!important` outside JS-toggled visibility.

---

## Step 9 — Standardize Breakpoints (All Files)

Canonical breakpoints to adopt project-wide:

| Name | Value | Replaces |
|------|-------|---------|
| sm | 576px | 640px in hf_base.css |
| md | 800px | — |
| lg | 1100px | — |

Add a `/* Breakpoints: sm=576px md=800px lg=1100px */` comment header in each file that uses media queries.

---

## Step 10 — Split `hf_base.css`

**File:** `themes/modus/css/hf_base.css` (917 lines)

Split into four files, loaded via `{combine_css}` in `themes/modus/template/header.tpl`:

| New file | Content |
|----------|---------|
| `hf_layout.css` | Structural rules, containers |
| `hf_components.css` | Nav, menus, thumbnails, forms |
| `hf_typography.css` | Font sizes, headings, links |
| `hf_responsive.css` | All `@media` blocks |

---

## Verification (After Each Step)

1. `bun run lint:css` — error/warning count decreasing
2. Visual: load category page at desktop + 390px mobile width
3. Switch 3+ modus skins — verify colors correct
4. Admin panel: verify admin themes render correctly
5. After Step 5 (skin refactor): screenshot each skin before/after
