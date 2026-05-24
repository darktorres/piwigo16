# Tailwind CSS v4 Integration

## Context

After splitting the monolithic `theme.css` into per-page CSS files for the Vite migration, shared CSS rules ended up in wrong files — causing styles to be missing on pages that don't load a specific bundle. Rather than patching individual misplacements, we adopt Tailwind CSS v4 as the CSS architecture. This is a greenfield rewrite — we design from scratch using Tailwind's design system.

The existing CSS custom property system (170 tokens in `tokens.css`, dark/light overrides) is preserved as the theming foundation. Tailwind references them via `@theme inline`.

**Scope:** Admin panel only. The gallery frontend has a separate CSS architecture (17 CSS files, no `@layer`, loaded via `cssLink()`) and is not part of this migration.

## Verified Technical Facts

- **`@theme inline` + `var()`**: Confirmed. `@theme inline { --color-surface: var(--admin-bg-page); }` generates `bg-surface { background-color: var(--admin-bg-page) }`.
- **Layer source order**: Later `@layer base` rules win over earlier ones. Unlayered styles always beat layered.
- **Preflight override**: `theme.css` loaded via `cssLink()` after Vite bundle → same `@layer base`, later source → existing rules win.
- **`.latte` scanning**: Not in Tailwind's default template list, but auto-discovered if files exist in project tree. Need `@source` as safety net.
- **JS import pattern**: `import '../css/tailwind.css'` from TS entry works with `@tailwindcss/vite`.
- **Stylelint**: Use `@dreamsicle.io/stylelint-config-tailwindcss` for v4 directives.
- **Dynamic class names**: Complete class names inside `{if}` work. Dynamic construction `text-{$color}-500` does NOT.
- **Token availability**: `var(--admin-*)` resolves at render time, not build time. No timing issue.
- **Manifest plugin**: Collects CSS from transitive imports, doesn't modify CSS. No conflict with Tailwind.

### Tailwind v4 Namespace Mapping (Critical)

Tailwind v4 uses specific variable name prefixes that map to utility classes:

| Tailwind namespace | Utility generated | Our token name | Collision? |
|---|---|---|---|
| `--color-*` | `bg-*`, `text-*`, `border-*` | `--color-surface`, `--color-fg`, etc. | No — new names |
| `--text-*` | `text-*` (font size) | **NOT** `--font-size-*` | Must use `--text-*` to map to `text-base`, `text-sm` etc. |
| `--radius-*` | `rounded-*` | `--radius-sm` etc. | **Overrides** Tailwind defaults (desired) |
| `--spacing` | `p-*`, `m-*`, `gap-*`, `w-*` | Single base unit, not per-size | Define `--spacing: 1px` for pixel-based scale |
| `--z-*` | `z-*` | No defaults in Tailwind | Clean addition |
| `--font-*` | `font-*` (family) | No conflict | N/A |
| `--shadow-*` | `shadow-*` | No conflict | N/A |

**Key decisions:**
- Font sizes: Use `--text-*` namespace (not `--font-size-*`) so `text-base`, `text-sm` etc. map correctly
- Spacing: Set `--spacing: 1px` so `p-5` = `5px`, `p-10` = `10px` etc. (matches existing pixel-based token scale). Custom named sizes via `--spacing-xs: 5px` etc. are pure additions (no Tailwind defaults to collide with)
- Radii: Our `--radius-sm: 5px` overrides Tailwind's default `--radius-sm: 0.25rem`. Use `--radius-*: initial` first to clear defaults.

## Phase 1: Install & Configure

### 1.1 Install packages

```
npm install -D tailwindcss@4.3.0 @tailwindcss/vite@4.3.0 @dreamsicle.io/stylelint-config-tailwindcss
```

### 1.2 Vite config

**`vite.config.ts`** — add Tailwind plugin before manifest plugin:
```ts
import tailwindcss from '@tailwindcss/vite';
// line 101:
plugins: [tailwindcss(), piwigoManifestPlugin()]
```

### 1.3 Create Tailwind entry

**`themes/admin/_base/css/tailwind.css`**:

```css
@import "tailwindcss";
@source "../../../../themes";
@source "../../../../src";

@theme inline {
  /* ── Clear Tailwind defaults we're replacing ── */
  --radius-*: initial;
  --text-*: initial;

  /* ── Surfaces ── */
  --color-surface: var(--admin-bg-page);
  --color-surface-alt: var(--admin-bg-panel);
  --color-surface-raised: var(--admin-bg-elevated);
  --color-surface-sunken: var(--admin-bg-deepest);

  /* ── Text ── */
  --color-fg: var(--admin-text);
  --color-fg-strong: var(--admin-text-strong);
  --color-fg-muted: var(--admin-text-muted);
  --color-fg-on-accent: var(--admin-text-on-accent);

  /* ── Borders ── */
  --color-border: var(--admin-border);
  --color-border-strong: var(--admin-border-strong);

  /* ── Accent ── */
  --color-accent: var(--admin-accent);
  --color-accent-soft: var(--admin-accent-soft);
  --color-accent-pale: var(--admin-accent-pale);

  /* ── Semantic ── */
  --color-danger: var(--admin-danger);
  --color-danger-hover: var(--admin-danger-hover);
  --color-success-bg: var(--admin-info-success-bg);
  --color-success-fg: var(--admin-info-success-fg);
  --color-warning-bg: var(--admin-info-warning-bg);
  --color-warning-fg: var(--admin-info-warning-fg);
  --color-error-bg: var(--admin-info-error-bg);
  --color-error-fg: var(--admin-info-error-fg);
  --color-info-bg: var(--admin-info-message-bg);
  --color-info-fg: var(--admin-info-message-fg);

  /* ── Forms ── */
  --color-input-bg: var(--admin-input-bg);
  --color-input-border: var(--admin-input-border);
  --color-form-bg: var(--admin-form-bg);
  --color-form-fg: var(--admin-form-fg);
  --color-form-border: var(--admin-form-border);

  /* ── Modals ── */
  --color-modal-bg: var(--admin-modal-bg);
  --color-popin-bg: var(--admin-popin-bg);
  --color-close-modal: var(--admin-close-modal);

  /* ── Link ── */
  --color-link: var(--admin-link);

  /* ── Disabled ── */
  --color-disabled-bg: var(--admin-disabled-bg);
  --color-disabled-fg: var(--admin-disabled-fg);

  /* ── Pagination ── */
  --color-pagination-bg: var(--admin-pagination-bg);
  --color-pagination-hover-bg: var(--admin-pagination-link-hover-bg);
  --color-pagination-hover-fg: var(--admin-pagination-link-hover-fg);

  /* ── Charts ── */
  --color-chart-blue: var(--admin-chart-blue);
  --color-chart-purple: var(--admin-chart-purple);
  --color-chart-green: var(--admin-chart-green);
  --color-chart-orange: var(--admin-chart-orange);
  --color-chart-red: var(--admin-chart-red);

  /* ── Stripes ── */
  --color-stripe-odd: var(--admin-stripe-odd);
  --color-stripe-even: var(--admin-stripe-even);

  /* ── Filter ── */
  --color-filter-bg: var(--admin-filter-bg);
  --color-filter-text: var(--admin-filter-text);
  --color-filter-input-bg: var(--admin-filter-input-bg);
  --color-filter-input-border: var(--admin-filter-input-border);

  /* ── Interactive ── */
  --color-text-info: var(--admin-text-info);
  --color-text-info-hover: var(--admin-text-info-hover);
  --color-piwigo-brand: var(--admin-piwigo-brand);

  /* ── Spacing (pixel-based scale, --spacing: 1px makes p-5 = 5px) ── */
  --spacing: 1px;
  --spacing-xs: var(--space-xs);
  --spacing-sm: var(--space-sm);
  --spacing-md: var(--space-md);
  --spacing-lg: var(--space-lg);
  --spacing-xl: var(--space-xl);

  /* ── Font sizes (--text-* namespace → text-base, text-sm utilities) ── */
  --text-2xs: var(--font-size-xxs);
  --text-xs: var(--font-size-xs);
  --text-sm: var(--font-size-sm);
  --text-md: var(--font-size-md);
  --text-base: var(--font-size-base);
  --text-lg: var(--font-size-lg);
  --text-xl: var(--font-size-xl);
  --text-2xl: var(--font-size-xxl);

  /* ── Radii (cleared defaults above, using our scale) ── */
  --radius-sm: var(--radius-sm);
  --radius-md: var(--radius-md);
  --radius-lg: var(--radius-lg);
  --radius-xl: var(--radius-xl);

  /* ── Z-index (no Tailwind defaults — clean addition) ── */
  --z-dropdown: var(--z-dropdown);
  --z-overlay: var(--z-overlay);
  --z-modal: var(--z-modal);
}
```

**Note on `--radius-*` self-reference:** `--radius-sm: var(--radius-sm)` would be circular. The `@theme inline` `--radius-sm` is in Tailwind's theme namespace, but `var(--radius-sm)` references the CSS custom property defined in `tokens.css`. Since `inline` doesn't emit a `:root` variable, there's no conflict. Verify during implementation — if circular, rename to `--radius-sm: var(--piwigo-radius-sm)` and update `tokens.css` accordingly.

### 1.4 Import from admin.ts

```ts
import '../css/tailwind.css';
```

### 1.5 Update Stylelint

**`.stylelintrc.json`** — add Tailwind v4 config to extends:
```json
{
  "extends": [
    "stylelint-config-standard",
    "@dreamsicle.io/stylelint-config-tailwindcss"
  ]
}
```

### 1.6 Verification

- `npx vite build` succeeds
- Add `class="bg-accent text-fg-on-accent font-bold p-10 rounded-md"` to one template element
- Verify it renders with orange background, white text, bold, 10px padding, 10px border-radius
- Verify existing pages look identical (Preflight overridden by existing base CSS)

## Phase 2: Component Layer

**`themes/admin/_base/css/tailwind-components.css`** — shared UI patterns using direct CSS variables (per Tailwind v4 team recommendation over `@apply`):

```css
@layer components {
  /* ── Buttons ── */
  .btn {
    display: inline-flex;
    align-items: center;
    padding: var(--spacing-sm) var(--spacing-md);
    font-size: var(--text-xs);
    font-weight: 700;
    border: none;
    cursor: pointer;
    transition: all 125ms ease-out;
  }
  .btn-primary { background-color: var(--color-accent-soft); color: var(--color-fg-on-accent); }
  .btn-primary:hover { background-color: var(--color-accent); }
  .btn-secondary { background-color: var(--color-surface-sunken); color: var(--color-fg); }
  .btn-secondary:hover { background-color: var(--color-border-strong); }
  .btn-danger { background-color: var(--color-danger); color: var(--color-fg-on-accent); }
  .btn-danger:hover { background-color: var(--color-danger-hover); }
  .btn:disabled { background-color: var(--color-disabled-bg); color: var(--color-disabled-fg); cursor: not-allowed; }

  /* ── Save Bar ── */
  .save-bar {
    position: fixed; bottom: 0; right: 0; width: 100%;
    z-index: var(--z-dropdown);
    display: flex; justify-content: space-between; align-items: center;
    border-top: 1px solid var(--color-border);
    background-color: var(--color-surface);
    padding: var(--spacing-sm);
  }
  .save-bar-start { margin-left: 230px; }
  .save-bar-end { display: flex; align-items: center; gap: var(--spacing-sm); }

  /* ── Modal ── */
  .modal-overlay {
    display: none; position: fixed; inset: 0; z-index: var(--z-modal);
    width: 100%; height: 100%; overflow: auto;
    background-color: rgb(0 0 0 / 0.7);
  }
  .modal-close {
    position: absolute; right: -40px; top: -40px;
    font-size: 30px; color: var(--color-close-modal); cursor: pointer;
  }
  .modal-panel {
    position: absolute; left: 50%; top: 50%; transform: translate(-50%, -50%);
    background-color: var(--color-popin-bg); border-radius: var(--radius-md);
    padding: var(--spacing-xl); max-height: 90%; text-align: left;
  }

  /* ── Alerts ── */
  .alert { border-radius: var(--radius-sm); padding: var(--spacing-sm); font-size: var(--text-sm); font-weight: 700; }
  .alert-success { background-color: var(--color-success-bg); color: var(--color-success-fg); }
  .alert-warning { background-color: var(--color-warning-bg); color: var(--color-warning-fg); }
  .alert-error { background-color: var(--color-error-bg); color: var(--color-error-fg); }
  .alert-info { background-color: var(--color-info-bg); color: var(--color-info-fg); }

  /* ── Badge ── */
  .badge {
    display: inline-block; border-radius: var(--radius-lg);
    padding: var(--spacing-xs) var(--spacing-sm);
    font-size: var(--text-xs); font-weight: 700;
  }

  /* ── Toggle Switch ── */
  .toggle { position: relative; display: inline-block; width: 60px; height: 24px; vertical-align: middle; margin-right: var(--spacing-sm); }
  .toggle input { opacity: 0; width: 0; height: 0; }
  .toggle-track {
    position: absolute; cursor: pointer; inset: 0;
    background-color: var(--color-surface-sunken); border-radius: 34px; transition: 0.4s;
  }
  .toggle-track::before {
    position: absolute; content: ""; height: 16px; width: 16px;
    left: 6px; bottom: 4px; border-radius: 50%; transition: 0.4s;
    background-color: var(--color-fg-muted);
  }
  .toggle input:checked + .toggle-track { background-color: var(--color-accent-soft); }
  .toggle input:checked + .toggle-track::before { transform: translateX(33px); background-color: var(--color-fg-on-accent); }

  /* ── Advanced Filter ── */
  .filter-btn {
    width: 70px; z-index: 2; position: relative; cursor: pointer;
    padding: var(--spacing-sm); text-align: center; font-weight: 700;
    background-color: var(--color-filter-bg);
  }
  .filter-panel {
    display: none; padding: var(--spacing-md); flex-direction: column;
    background-color: var(--color-filter-bg);
  }
  .filter-panel.open { display: flex; }
  .filter-header { display: flex; justify-content: space-between; margin-bottom: var(--spacing-sm); }
  .filter-title { font-weight: 700; color: var(--color-filter-text); }

  /* ── Dropdown ── */
  .dropdown-menu {
    display: none; position: absolute; z-index: var(--z-dropdown);
    border-radius: var(--radius-md);
    background-color: var(--color-popin-bg);
    box-shadow: 0 3px 3px 1px rgb(0 0 0 / 0.2);
    padding: var(--spacing-sm) var(--spacing-lg);
  }
  .dropdown-item {
    display: block; white-space: nowrap;
    padding: var(--spacing-xs) var(--spacing-sm);
    font-size: var(--text-sm); font-weight: 700; cursor: pointer;
  }

  /* ── Card ── */
  .card {
    background-color: var(--color-surface-alt); border-radius: var(--radius-sm);
    box-shadow: 0 1px 3px rgb(0 0 0 / 0.1); padding: var(--spacing-sm);
  }
}
```

Import from `admin.ts` after `tailwind.css`.

## Phase 3: Preflight Coexistence

Preflight enabled from day one via `@import "tailwindcss"`. Existing base CSS overrides it during migration.

**Loading order:**
1. Vite bundle → Tailwind Preflight in `@layer base` + utilities in `@layer utilities`
2. `header.latte` → `cssLink("themes/admin/{id}/theme.css")` → imports base CSS into `@layer base`
3. Same layer, later source order → existing rules win

**Third-party CSS + Preflight (verified safe):**
- Third-party CSS (tom-select, flatpickr, uppy, glightbox, datatables) is imported via TS entries → bundled by Vite as unlayered CSS
- Preflight is in `@layer base`. Unlayered CSS always beats layered CSS (CSS spec). Third-party styles win.
- Additionally, library selectors use classes (`.flatpickr-input`, `.ts-control`) which have higher specificity than Preflight's element selectors (`input`, `button`)
- Double protection — no risk to third-party component styling

**Base files to migrate away from (in dependency order):**

| File | Lines | When to remove |
|------|-------|----------------|
| `utilities.css` | 350 | After all `.u-*` replaced with Tailwind utilities |
| `icons.css` | 119 | After all buttons use `.btn-*` |
| `typography.css` | 267 | After heading styles moved to templates |
| `notifications.css` | 171 | After all alerts use `.alert-*` |
| `navigation.css` | 235 | After nav components use Tailwind |
| `tabsheets.css` | 95 | After tab navigation converted |
| `gallery-view.css` | 64 | After picture pages converted |
| `menubar.css` | 126 | With admin shell migration |
| `layout.css` | 389 | Last — admin shell structure |
| `tokens.css` | 173 | **Never** — stays as dark/light theme source |

## Phase 4: TypeScript Updates

~200+ programmatic class references across 30 TS files. Categories:

**A. State toggles (keep as-is):** `selected`, `active`, `hidden`, `open`, `disabled`, `unavailable`, `thumbSelected`, `container-selected`, `input-mode`, `drag-over`, `pwgtree-open/closed`, `animate-spin`. These are behavioral markers, not styling. ~120 references.

**B. Component class updates (per template migration):**
- `buttonLike` → not in classList (CSS only)
- `bg-modal` → `modal-overlay` (in `comments.ts`, `cat_modify.ts`, `batchManagerFilter.ts`)
- `savebar-footer` → `save-bar` (not in classList — CSS only)
- `info-message/warning/error` → `alert-success/warning/error` (in `cat_modify.ts`)
- `slider`, `slider round` → `toggle-track` (in `plugins_installated.ts`)

**C. Icon classes (keep as-is):** `icon-floppy`, `icon-spin6`, `icon-check`, `icon-cancel`, `icon-star` etc. Fontello icons — structural, not styling. ~80 references.

**D. `style.display` → class toggles (~130 occurrences):**
Convert `el.style.display = 'none'` → `el.classList.add('hidden')` and `el.style.display = ''` → `el.classList.remove('hidden')`. Biggest files: `mcs.ts` (~80), `album_selector.ts` (~25), `cat_modify.ts` (~15), `batchManagerGlobal.ts` (~15).

**E. DOM query selectors (keep, migrate to `data-*` over time):**
`querySelector('.some-class')` for element access. Old class names coexist with Tailwind classes — not blocking.

## Phase 5: Template Migration (Incremental)

Templates converted page-by-page. For each:
1. Replace old classes with component-layer or utility classes
2. Update corresponding TS file's classList refs
3. Convert `style.display` to class toggles
4. Remove page's CSS import from TS entry when empty
5. Delete empty page CSS file
6. Remove unused rules from base CSS files

**Priority order:**
1. Dashboard (`intro.latte`) — most visible, simple layout
2. Configuration pages (6 templates) — share savebar
3. User/group management (4 templates) — share filter + selection mode
4. Album pages (4 templates) — share tree + savebar
5. Photo pages (4 templates) — share batch manager patterns
6. Plugin/theme/extension pages (6 templates) — share plugin card
7. Remaining pages
8. Admin shell (`header.latte`, `admin.latte`, `footer.latte`) — last, structural

## Phase 6: Dark/Light Theme

**No changes during migration.** Dark/light works unchanged:
- `tokens.css` defines light defaults → consumed by `@theme inline`
- `dark/theme.css` overrides `:root` values → `var(--admin-*)` resolves to dark values
- `dark/css/components/general.css` overrides component tokens (16 variables)

**After migration:**
- `:root` token overrides in `dark/theme.css` (lines 1-82) stay
- Component CSS overrides in `dark/theme.css` (lines 83-1128) get deleted as components are converted
- `light/theme.css` explicit rules get deleted as templates use utilities
- `tokens.css` stays permanently

## Phase 7: Print CSS

Both `themes/admin/_base/print.css` and `themes/_base/print.css` exist. Loaded conditionally via `cssLink()`. Tailwind has no impact — these hide elements via `display: none` and set `color: #000; background: #fff`. Keep as-is; eventually convert to Tailwind `print:` variant utilities in templates.

## Files Modified

| File | Change |
|------|--------|
| `package.json` | Add `tailwindcss@4.3.0`, `@tailwindcss/vite@4.3.0`, `@dreamsicle.io/stylelint-config-tailwindcss` |
| `vite.config.ts` | Add `tailwindcss()` plugin |
| `.stylelintrc.json` | Extend with `@dreamsicle.io/stylelint-config-tailwindcss` |
| `themes/admin/_base/css/tailwind.css` | **New** — entry with `@theme inline` mapping |
| `themes/admin/_base/css/tailwind-components.css` | **New** — `@layer components` |
| `themes/admin/_base/js/admin.ts` | Import `tailwind.css` and `tailwind-components.css` |
| `themes/admin/_base/template/*.latte` | Incremental per Phase 5 |
| `themes/admin/_base/js/*.ts` | Update classList refs per template |
| `themes/admin/_base/css/pages/*.css` | Delete as pages migrate |
| `themes/admin/_base/css/base/*.css` | Remove rules incrementally, delete when empty |
| `themes/admin/_base/css/components/*.css` | Delete as Tailwind components replace them |
| `themes/admin/_base/theme.css` | Remove `@import` lines as files deleted |
| `themes/admin/dark/theme.css` | Keep `:root` overrides; delete component rules |
| `themes/admin/light/theme.css` | Delete rules as templates use utilities |

## Verification

1. **Phase 1:** `npx vite build` succeeds. Test utility renders. Existing pages identical. Third-party components (flatpickr, tom-select, uppy) still functional.
2. **Per template:** Visual comparison before/after in dark + light. Test interactive elements.
3. **TS changes:** Click-test classList-dependent interactions.
4. **Continuous:** phpunit, Pint, PHPStan, ESLint, Stylelint pass.
5. **Final:** All base CSS deleted. Preflight is sole reset. Full visual regression across all admin pages.

## Verified Details (No Open Questions)

1. **`--radius-sm` self-reference is safe.** `@theme inline` does NOT emit a `:root` variable, so `var(--radius-sm)` resolves to the one from `tokens.css`. No circular reference. This is the intended use case for `inline`.
2. **`--spacing: 1px` works as expected.** `p-5` → `padding: calc(1px * 5)` = `5px`. Named tokens (`--spacing-sm`) coexist — `p-sm` → `padding: var(--spacing-sm)`. Both scales work simultaneously.
3. **Third-party CSS is safe from Preflight.** Library CSS is unlayered → always beats Preflight's `@layer base`. Library class selectors also have higher specificity than Preflight's element selectors. Double protection.
