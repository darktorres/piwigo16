# CSS Modernization Master Plan

## Context

The JS/TS stack has been fully modernized (TypeScript, Vite, stylelint, ESLint), but CSS has had no equivalent attention. The result: 572 `!important` declarations across project CSS, zero CSS custom properties anywhere, hardcoded colors/values throughout, and inconsistent media query breakpoints. This plan covers the entire project CSS — all themes, all plugins — not just modus/GDThumb.

**Current state (after completed steps):** 0 errors, 451 warnings (down from 3557 errors / 572 warnings).

## Full File Inventory (31 files, all in scope)

**Themes — front-end:**
- `themes/modus/css/hf_layout.css` + `hf_components.css` + `hf_typography.css` + `hf_responsive.css` *(was `hf_base.css`, split in Step 10)*
- `themes/modus/css/plugin_compatibility.css` + `tags.css`
- `themes/modus/css/base.css.tpl` *(Smarty template, excluded from lint)*
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

**Out of scope (vendor/third-party):**
- `themes/bootstrap_darkroom/css/**` (Bootstrap library ~30 files)
- `themes/modus/css/fontello/**`, `themes/default/fontello/**`
- `themes/modus/css/open-sans/**`
- `themes/default/js/plugins/selectize.*.css` (removed; replaced by TomSelect)
- `themes/elegant/admin/jquery.ui.button.css`

---

## Step 1 — Fix Stylelint to Cover Full Codebase ✅ DONE

**File:** `.stylelintrc.json`

Updated `ignoreFiles` to exclude only vendor libraries. New rules added:
```json
"declaration-no-important": [true, { "severity": "warning" }],
"custom-property-pattern": "^[a-z][a-z0-9-]*$",
"color-no-invalid-hex": true,
"shorthand-property-no-redundant-values": true
```

**Baseline after Step 1:** 3557 errors, 572 warnings across 31 files.

---

## Step 2 — Auto-fix Mechanical Issues ✅ DONE

Ran `bunx stylelint --fix` across all in-scope files. Eliminated all auto-fixable formatting errors (empty lines, whitespace, shorthand redundancy, pseudo-element notation).

**After Step 2:** 0 errors, 572 warnings.

---

## Step 3 — Plugin CSS: Remove `!important` ✅ DONE

Removed or justified all `!important` instances in:
- `plugins/GDThumb/css/gdthumb.css` + `admin.css`
- `plugins/AdminTools/template/admin_style.css` + `public_style.css`
- `plugins/language_switch/language_switch.css` + `style.css`

---

## Step 4 — CSS Custom Properties: Design Token Layer ✅ DONE (narrower than planned)

### 4A — Tokens in `hf_base.css` (now `hf_layout.css`) ✅ DONE

Added `:root` block with the tokens that had immediate usage:

```css
:root {
  --color-error-bg: #ffc4bf;
  --color-error-border: #d31100;
  --color-thumb-overlay: rgb(0 0 0 / 20%);
  --color-scrollbar-thumb: rgb(0 0 0 / 40%);
  --z-page-title: 1;
  --z-footer: 500;
  /* Form control shape tokens — overridden per skin */
  --radius-control: 0;
  --radius-comment-element: 0;
  --radius-thumb-hover: 0;
  --radius-image-info: 0;
  --radius-sortbox: 0;
  --radius-menubar-dd: 0;
}
```

**Not done:** The originally planned spacing tokens (`--space-xs` etc.), font-size tokens (`--font-size-sm` etc.), and additional z-index tokens. These remain as hardcoded values throughout.

### 4B — Color tokens from PHP skin system ✅ DONE

`themes/modus/css/base.css.tpl` rewritten: all `{$skin.*}` values now emitted as CSS variables in a single `:root {}` block at the top. Element rules throughout the file use `var(--color-*)`.

### 4C — Replace hardcoded hex in `hf_base.css` ✅ DONE

Static hex values replaced with `var(--color-*)` references where applicable.

---

## Step 5 — Refactor Modus Skin CSS Files ⚠️ PARTIALLY DONE

**Files:** `themes/modus/skins/*.css` (11 files)

**Done:**
- Added `:root { --radius-control: Xpx; }` blocks to skins with non-zero radius values, replacing `border-radius: Xpx !important` instances throughout each skin
- Removed redundant `!important` on `border-radius: 0` (equal to variable default, no override needed)
- Removed `!important` from `background: transparent`, `box-shadow: none`, `text-decoration` rules across all skins
- Removed redundant `!important` from: mobile action button selectors (high specificity wins), `#quickconnect input` / `#t4u-update-tags` (ID specificity wins), `.selectize-input` bg/color/border, `input[type="text"]` bg, `#menubar input` bg, `.switchBox` bg, `input[type="submit"]` padding/color/bg, `.tagLevel5` color, `.head-button-2:hover` color, `.token-input-input-token` bg, `#derivativeSwitchBox` bg, `div.thumbHover` height, `#menubar li:hover a` color
- Added `/* Search colors — !important below overrides mcs-search plugin CSS */` annotation to the search section of each skin

**Result:** ~74 `!important` warnings remaining in skin files (down from 176).

**Not done:** The original goal was to reduce each skin to a single `:root {}` block of variable overrides. Most skins still contain hundreds of element-level rules that duplicate `hf_components.css` with color differences. The remaining ~74 warnings are all in `/* Search colors */` sections — they override the mcs-search plugin's CSS with `!important` and cannot be removed without changing both sides.

---

## Step 6 — Clean Up Admin Theme CSS ⚠️ PARTIALLY DONE

**Files:** `admin/themes/clear/theme.css`, `admin/themes/default/theme.css`, `admin/themes/roma/theme.css`, `admin/themes/default/css/components/general.css`, `admin/themes/roma/css/components/general.css`

**Done:**
- `clear/theme.css`: Removed 18 redundant `!important` — class/pseudo-class selectors with sufficient specificity (`.menuLi_hidden`, `a.Piwigo`, `.pluginBox.incompatible`, `:disabled` buttons, `.GroupBackgroundSelected`, `.OrangeIcon`, `.OrangeFont`, `.ValidationUser*`, `#addAlbumForm`, `:hover` rules, `.dimension-cancel`, `.pageNumberSelected`, `.breadcrumb-item.add-item.highlight`)
- `default/theme.css`: Removed 13 redundant `!important` (`.albumIconLineHover`, `.albumActions a:hover span`, `.pluginUnavailableAction:hover`, `.unavailablePlugin:hover`, 8× `.pluginMiniBox.*`); annotated 3 `display: none !important` with `/* js-toggled */`
- `default/css/components/general.css`: Removed 3 (`.head-button-1/2:hover` text-decoration/color, `.tree .badge-container i::before` margin)
- `roma/theme.css`: Removed 4 (`.menuLi_hidden`, `a.Piwigo`, `.selected-pagination` bg/color)

**Not done:** ~278 `!important` remain across admin files — the bulk are in `roma/theme.css` (135) and `default/theme.css` (107) fighting third-party library CSS (jQuery UI datepicker, TomSelect, plupload, DataTables, jconfirm). These require knowing the competing library CSS to safely remove.

**Approach when resuming:**
1. Introduce `:root` variable blocks for repeated colors/spacing values
2. Fix specificity instead of `!important` — admin themes fight each other's selectors
3. Where a rule only exists to override another rule in the same file, consolidate

---

## Step 7 — Clean Up Front-End Theme CSS ⚠️ PARTIALLY DONE

**Files:** `themes/default/theme.css`, `themes/elegant/theme.css`, `themes/smartpocket/theme.css`, `themes/bootstrap_darkroom/theme.css`, `themes/default/css/*.css`

**Done:**
- Removed `!important` from `.tagLevel1`–`.tagLevel5` font-size rules in `default/theme.css`
- Removed unjustified `!important` from `search.css` (margins, outline)
- Removed `background: transparent !important` from `clear-search.css` and `dark-search.css`
- Added `/* js-toggled */` annotation to colorbox `display: none !important` in `smartpocket/theme.css`
- Added `/* js-toggled */` and `/* overrides JS-set margin */` annotations in `default/theme.css`

**Not done:**
- No `:root` variables introduced for repeated colors in these themes
- ~25 `!important` instances remain in `search.css`, `clear-search.css`, `dark-search.css` that fight higher-specificity or Bootstrap rules — these are justified but not annotated
- `themes/bootstrap_darkroom/theme.css` not touched
- `themes/elegant/theme.css` plugin override `!important` left as-is

---

## Step 8 — Remaining `!important` Audit ⚠️ PARTIALLY DONE

Annotated justified `!important` throughout the codebase with comments:
- `/* js-toggled */` — on `display: none !important` controlled by JavaScript
- `/* overrides inline position set by masonry */` — on SVG layout overrides
- `/* overrides JS-set margin */` — on thumbnail scroll layout

**Current state:** 517 warnings. Goal of zero `!important` outside JS-toggled visibility was not achieved. The remaining warnings break down roughly as:
- ~140 in modus skin files (need more CSS variables — see Step 5)
- ~323 in admin theme files (deferred — see Step 6)
- ~54 in front-end themes/search (mix of justified and deferred)

---

## Step 9 — Standardize Breakpoints ⚠️ PARTIALLY DONE

**Done:** Added `/* Breakpoints: sm=Xpx ... */` comment headers to all files containing `@media` blocks:
- `themes/modus/css/hf_responsive.css` — `sm=640px md=800px lg=1100px`
- `themes/default/css/search.css`, `clear-search.css`, `dark-search.css` — `sm=600px`
- `themes/smartpocket/theme.css` — `sm=600px`

**Not done:** The actual pixel value standardization (640px → 576px in modus files) was left as-is. Changing these values shifts when mobile layout activates for 576–640px viewports, which is a visual behavior change requiring browser testing before committing.

---

## Step 10 — Split `hf_base.css` ✅ DONE

`hf_base.css` (955 lines) split into four focused files, all registered in `themes/modus/template/header.tpl` via `{combine_css}` at `order=-10`:

| New file | Lines | Content |
|----------|-------|---------|
| `hf_layout.css` | 255 | `:root` tokens, body/flex skeleton, page containers, thumbnail scroll layout |
| `hf_components.css` | 496 | Menubar, forms, inputs, calendar, switchBox, titrePage, thumbHover |
| `hf_typography.css` | 73 | h2, tagLevel sizes, imageInfo dt, legend fonts |
| `hf_responsive.css` | 136 | All `@media` blocks |

---

## Remaining Work Summary

| Step | Status | Remaining effort |
|------|--------|-----------------|
| Step 5 (skin files) | ⚠️ Partial | Introduce per-component color variables to eliminate ~140 skin `!important` |
| Step 6 (admin themes) | ⚠️ Partial | ~278 `!important` remain, mostly third-party lib overrides (jQuery UI, TomSelect, plupload, DataTables) |
| Step 7 (front-end themes) | ⚠️ Partial | Annotate ~25 justified search CSS `!important`; `:root` variables not added |
| Step 9 (breakpoints) | ⚠️ Partial | Decide on + apply actual 640px → 576px value change across modus files |

---

## Verification

1. `bun run lint:css` — 0 errors, 410 warnings currently
2. Visual: load category page at desktop + 390px mobile width
3. Switch 3+ modus skins — verify colors correct
4. Admin panel: verify admin themes render correctly
