# Justified `!important` Declarations

All 235 remaining `!important` instances fall into one of the categories below. None should be removed without first resolving the underlying constraint they document.

---

## 1. Child admin themes — loading-order override (97)

**Files:** `admin/themes/roma/theme.css` (86), `admin/themes/clear/theme.css` (11)

Both child themes are loaded by the `{combine_css}` bundler **after** the parent `admin/themes/default/theme.css`, which means they win by cascade order at equal specificity — except where the parent itself uses `!important`. Until the admin CSS is migrated to a CSS-variable contract (`:root { --admin-* }` in parent, overridden in child), these `!important` declarations are the only reliable way for the child to win those properties.

**Resolution path:** Complete the CSS-variable migration planned in the restructuring plan (Step 6). Once the parent uses `var(--admin-*)` everywhere, children only need a `:root {}` override block and these `!important` rules disappear.

---

## 2. Third-party plugin CSS overrides (32)

### 2a. mcs-search plugin compatibility (15)

**File:** `themes/modus/css/plugin_compatibility.css`

mcs-search injects its own `<link>` stylesheet at runtime. Our rules must beat it regardless of injection order. All 15 instances target mcs-search selectors.

### 2b. Gallery search feature — selectize and filter overrides (13)

**File:** `themes/default/css/search.css`

Selectize.js ships its own CSS. Our theme rules override colors, backgrounds, and dimensions. Key instances:

| Line | Property | Reason |
|------|----------|--------|
| 273–275 | `background`, `border`, `color` on filled filter | Beats selectize own filled-state styles |
| 279 | `color` on filled filter item | Same |
| 474 | `color` on hover button | Higher-specificity rule in parent chain |
| 513 | `margin-left: auto` | Overrides flex margin from parent container |
| 736 | `color` on selectize input | Overrides selectize own input color rule |
| 823 | `min-width: 150px` | Overrides `style="min-width:…"` set by selectize inline |
| 827 | `z-index: 10` | Overrides Bootstrap `sticky-top` z-index |
| 851 | `box-shadow: none` | Overrides desktop box-shadow in mobile media query |
| 873 | `display: block` | Overrides `flex` set by a higher-specificity rule |
| 919 | `white-space: unset` | Overrides higher-specificity `nowrap` rule |
| 936 | `font-size: 16px` | Prevents iOS auto-zoom on input focus |

### 2c. dark-search color variant (4)

**File:** `themes/default/css/dark-search.css`

Overrides the base `search.css` rules for the dark colorscheme. Same third-party conflict as 2b.

---

## 3. Modus skin overrides of mcs-search (74)

**Files:** `themes/modus/skins/` — blueberry (11), cafe_latte (11), swimming_pool (11), quartz (10), glacier (9), avocado (8), splash (6), neon_pink (4), strawberry_jam (4)

Each skin file customises the mcs-search override colors for that skin. All instances are already annotated with `/* mcs-search override */` comments in the source. Same constraint as §2a.

---

## 4. Masonry / JS inline-position overrides (7)

**Files:** `themes/modus/css/hf_layout.css` (2), `themes/modus/css/hf_responsive.css` (4), `themes/modus/css/hf_components.css` (1)

Masonry.js sets `position`, `left`, and `top` as inline styles on grid items. Our CSS must override those with `!important` to enforce layout constraints that Masonry would otherwise ignore.

---

## 5. Tag cloud JS inline-style override (5)

**File:** `themes/modus/css/tags.css`

The tag cloud JS sets `font-size` as an inline style on each tag element to reflect tag weight. The theme overrides specific size ranges. Inline styles have specificity (1,0,0,0) — unreachable without `!important`.

---

## 6. JS-toggled visibility — `display: none` (8)

JavaScript adds/removes CSS classes to show or hide elements. The `display: none !important` on the hidden state must beat any `display: flex/block/inline` set by layout rules, regardless of their specificity.

| File | Selector | Class toggled by JS |
|------|----------|---------------------|
| `css/pages/user_list.css` (×2) | `.hide`, `.compactView .user-container .user-container-registration` | `.hide`, `.compactView` |
| `css/pages/icons.css` | `#permissions` | shown/hidden by permission toggle |
| `css/pages/history.css` | `.hide` | JS hide class |
| `css/pages/upload.css` | JS-controlled upload progress element | upload state machine |
| `css/pages/user_activity.css` | `.hide` | JS hide class |
| `themes/default/css/search-in-set.css` | search-in-set toggle button | search toggle |
| `themes/smartpocket/theme.css` | mobile nav toggle | JS nav state |

---

## 7. Colorbox and JS inline-style overrides (6)

**File:** `admin/themes/default/css/pages/album-manager.css` (4), `admin/themes/default/css/components/jqtree-overrides.css` (2)

| Selector | Property | Source of inline style |
|----------|----------|------------------------|
| `#cboxLoadedContent` | `height: auto`, `padding: 20px` | Colorbox sets `height` as inline style |
| `#batchManagerGlobal ul.thumbnails .wrap2` | `width`, `height` (110px) | Thumbnail-size JS sets inline dimensions |
| `.afterUploadActions` | `margin-bottom: 30px`, `margin-top: 45px` | Upload JS sets `style="margin:10px"` on the element |

---

## 8. CSS load-order fix (2)

**File:** `admin/themes/default/css/pages/install-upgrade.css`

```css
background-color: transparent !important; /* overrides global.css #content bg — loads before theme.css in combined */
```

`install-upgrade.css` is registered at `order=-10` in `install.tpl`, which places it **before** `global.css` in the combined bundle. `global.css` sets `#content { background-color: var(--admin-bg-content) }` at specificity (1,0,1). Without `!important` the install page inherits the admin content background instead of being transparent.

---

## 9. Third-party selectize override — gallery colors (1)

**File:** `themes/default/css/colors.css`

```css
.selectize-dropdown [data-selectable],
.selectize-dropdown .optgroup-header {
    padding: 0 5px !important; /* overrides selectize.js own padding rule at higher specificity */
}
```

Selectize's own stylesheet uses a more-specific selector for dropdown item padding. Our rule at (0,1,1) loses without `!important`.

---

## 10. Legacy browser compatibility (1)

**File:** `themes/default/css/picture.css`

```css
background-color: transparent !important; /* Konqueror doesn't accept transparent here */
```

Ancient Konqueror-specific workaround. Low priority to remove; harmless to keep.

---

## 11. JS-set layout margin (1)

**File:** `themes/default/css/thumbnails.css`

```css
margin-left: 0 !important; /* overrides JS-set margin from menubar layout */
```

The menubar layout script sets `margin-left` as an inline style on the thumbnail container. `!important` is required to override it.

---

## 12. Plugin output margin (1)

**File:** `themes/elegant/theme.css`

```css
#the_page .content .stuffs {
    margin: 0 !important; /* overrides margin set by pwgStuffs plugin */
}
```

The pwgStuffs plugin injects margin on its output container. Overriding it without `!important` would require knowing the plugin's selector specificity, which is not stable across plugin versions.
