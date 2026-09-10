# P52 (CSS) visual accessibility audit — temp working notes

Analysis-only workflow run (no files modified). 7 parallel survey
dimensions over color contrast (computed, not eyeballed), touch-target
sizing, zoom/reflow (WCAG 1.4.10), focus-ring visual sufficiency, forced-
colors/high-contrast-mode support, truncated-text accessibility, and
skip-links/landmarks. 49 agents, merged into 23 punch-list items —
several severe, sitewide, and independent of everything found so far.

**Notable: this audit found real gaps in this session's own earlier
accessibility work.** The reduced-motion reset and the mechanical
`:focus-visible` sibling-selector rollout (both verified/committed
earlier this session) are real and correct as far as they go, but this
pass found: (a) one selectize input suite that explicitly suppresses
`:focus-visible` with `outline:none`, entirely unaffected by that
rollout since it had no matching `:hover` rule for the mechanical pass to
touch; (b) several sites where the mechanical pass *mirrored an
already-too-subtle `:hover` color change* into `:focus-visible`, so the
new focus ring is technically present but fails the 3:1 non-text-contrast
bar — visually indistinguishable from the unfocused state. Neither is a
mistake in the mechanical transform itself (it did exactly what it was
built to do — copy `:hover` styling to `:focus-visible`) — it's that
"present" and "sufficient" are different bars, and only this pass checked
the second one.

Overall auditor summary: *"Beyond the completed reduced-motion reset and
the mechanical :focus-visible rollout, the P52-touched CSS still has
substantial, verifiable WCAG gaps. The two completed fixes are necessary
but not sufficient... Reflow at 400% zoom / on real mobile devices is
broadly broken — a sitewide `min-width:60em` on both the admin and
public-gallery `body`, a total absence of a `<meta viewport>` tag on the
two highest-traffic layouts, and several hard-coded-pixel dialogs/panels
each independently block WCAG 1.4.10. Color contrast failures are
pervasive and sometimes severe..., meaning the underlying visual design —
not just the accessibility layer applied on top — needs real color
changes in a follow-up pass. Structural navigation (no `<main>`/`<nav>`
landmarks or skip link on any of the three template chains) and several
undersized touch targets... round out a picture of a codebase whose CSS
mechanics (logical properties, focus-visible plumbing, reduced-motion)
are now solid, but whose color choices, fixed-width layouts, and semantic
structure have not yet been audited or corrected for AA conformance."*

## Prioritized punch list (23 items)

### Reflow / zoom — sitewide structural failures

#### 1. [reflow-zoom, high risk] Sitewide body min-width:60em blocks WCAG 1.4.10 Reflow on both major themes

**Files:** `themes/default/theme.css`, `admin/default/theme-base.css`

Both the public gallery theme and the admin theme set `body {
min-width: 60em }` (~960px), resolving to a hard floor with **zero media
query anywhere that relaxes it**. This unconditionally blocks reflow at
400% zoom or on any viewport under ~768-960px, across essentially every
page in the application — thumbnails, picture view, categories, tags,
search, and the entire admin panel.

**Suggested action:** Remove `min-width: 60em` from `body` in both files
and replace the menubar/sidebar layout with a real responsive pattern
(collapse to off-canvas below ~768-960px); re-verify at 400% zoom on
representative pages.

#### 2. [reflow-zoom, high risk] No `<meta viewport>` tag anywhere in the two highest-traffic layouts

**Files:** `themes/default/template/layout.latte`, `admin/default/template/layout.latte`

`themes/standard_pages/template/layout.latte` **already has** a viewport
meta tag — the public gallery layout and the admin layout do not.
Without it, mobile browsers fall back to a virtual ~980px viewport and
scale the whole page down, so **none of the site's real `@media`
breakpoints can ever engage on an actual phone**, regardless of any other
fix.

**Suggested action:** Add `<meta name="viewport" content="width=device-width,
initial-scale=1">` to both layout files, mirroring the existing
standard_pages tag.

#### 6. [structure-navigation, high risk] No `<main>`/`<nav>` landmarks and no skip-link on any of the site's 3 template chains

**Files:** 11 templates across all 3 chains

A repo-wide grep for `<main>`/`role=main`/`<nav>`/`role=navigation`
returns **zero hits anywhere** in `themes/`. On every page load in all
three chains, a keyboard or screen-reader user must tab through the full
header/sidebar before reaching content, with no skip-link anywhere — a
WCAG 2.4.1 (Level A) failure hitting **every single visitor**, not an
edge case.

**Suggested action:** Wrap each chain's real content region in `<main>`,
wrap menubars/sidebars in `<nav aria-label="...">`, and add one "Skip to
content" link as the first focusable element in each of the three
`layout.latte` files.

#### 16-19. [reflow-zoom, medium-high risk] Several hard-coded-pixel dialogs/panels independently block reflow

**Files:** `user_list.css` (3 popin dialogs at 745/635/350px), `install.css` (800px install wizard, no media query at all), `search.css` (400px filter-panel `min-width` overriding the mobile breakpoint; a 500×400px quick-search dialog with its browser-default max-size safety net explicitly disabled via `max-width:none; max-height:none`)

Four independent fixed-size UI surfaces (some of the most commonly used
admin dialogs — edit user, add user; the entire first-run install flow;
front-end search filter panels) force horizontal scroll/clipping under
the WCAG 320px reflow test width, unrelated to the `body min-width` issue
above.

**Suggested action:** Add responsive step-downs per file (see the report
for exact values); replace `search.css`'s mobile-breakpoint
`overflow-x:hidden` (which clips rather than fixes) with a real width fix.

### Focus indicators — including gaps in this session's own earlier work

#### 3. [focus-indicator, high risk] Selectize tag/author/added-by filter inputs explicitly suppress :focus-visible with outline:none

**Files:** `themes/default/css/search.css`

A dedicated `:focus-visible { border:none; outline:none; }` rule exists
for 3 real, live filter inputs, on top of an already `border:none` base
rule, with **no substitute indicator anywhere in the ancestor/descendant
chain**. Tabbing or clicking into any of these produces zero visible
focus indicator — a clean WCAG 2.4.7 failure, not merely an
inherited-but-weak one, and untouched by this session's 378-site
mechanical pass (these inputs have no `:hover` counterpart for that pass
to have mirrored).

**Suggested action:** Delete the suppression rule, or replace it with a
real visible ring.

#### 4. [focus-indicator, high risk] Several mechanically-mirrored :focus-visible rings fail the 3:1 non-text-contrast bar, undermining this session's own focus-visible rollout

**Files:** `selectize.css`, 5 admin page CSS files, `theme-base.css`

Because the earlier mechanical pass mirrored each existing `:hover` rule
verbatim into `:focus-visible`, several already-subtle mouse-only
affordances (a remove-chip color delta at ~1.37:1, chip-remove overlays
at ~1.10:1, flyout/dropdown-menu overlays at ~1.15-1.16:1) now carry a
keyboard focus indicator that is **technically present but visually
indistinguishable from the unfocused state** — a real SC 1.4.11 failure
hiding behind "focus-visible was added."

**Suggested action:** Treat as its own follow-up pass: for each listed
selector, replace the mirrored `:hover` color delta with an independent
outline/box-shadow ring reaching ≥3:1 against both states, rather than
relying on the existing hover color.

#### 5. [focus-indicator, high risk] standard_pages login/register/password/profile pages use a color-only hover/focus indicator that fails 3:1 on most skins

**Files:** `standard_pages/theme.css` + 11 skin files

`.dark`/`.light a:hover/:focus-visible` change only text color, with
underline present unconditionally before and after (carrying no state
signal). **All 11 dark-mode variants fail 3:1** (one skin is a
byte-identical zero-delta swap), and 5 of 10 light-mode variants fail
too — on the account-management pages every user must pass through.

**Suggested action:** Add a non-color state change (outline, background
tint, or underline-thickness) to both rules so at least one property
clears 3:1 independent of the per-skin color swap; verify per-skin.

#### 13. [focus-indicator, medium risk] admin/clear & admin/roma: gray-on-gray border-only hover/focus indicators fall below the 3:1 contrast-change requirement

**Files:** `admin/clear/theme.css`, `admin/roma/theme.css`

3 real toggle controls change only border color on hover/focus-visible
with no compensating change — 1.70:1 and 1.60:1 contrast-of-change,
both failing WCAG 2.4.11's 3:1 requirement.

**Suggested action:** Replace the border-color-only swap with a pair
reaching 3:1, or add a background-color change alongside it.

### Color contrast (computed) — pervasive, on real visible text

#### 8. [contrast, high risk] Navigation-bar/calendar muted text (#b0b0b0) is 2.17:1 against the page background

Pagination/breadcrumb text and calendar day numbers, real content shown
on nearly every paginated public listing — 2.17:1 vs. the 4.5:1 floor.

**Suggested action:** Darken to at least `#757575` (≈4.5:1 on white).

#### 9. [contrast, high risk] standard_pages fixed header-options link fails 4.5:1 against its own gradient on nearly every skin

Recomputed against each skin's actual gradient: **only "purple" (4.91:1)
clearly passes** — lime (1.60:1), cadmium (1.91:1), green (1.71:1),
default (2.27:1), fuchsia (2.69:1), silver (2.81:1), red (2.29:1), cobalt
(3.32:1), sienna (3.99:1), teal (4.32:1) all fail — a near-full
skin-catalog failure, not an isolated case.

**Suggested action:** Re-pick each skin's header-link token (or add a
translucent scrim behind the header), using "purple" as the only current
passing reference.

#### 11-12. [contrast, high/medium risk] Selected-tag pill (2.26:1) and the related-tags/search-in-set widget cluster (3 separate failing colors)

Selected-tag pill text is 2.26:1. The related-tags widget has 3
independently-failing colors in the same CSS block: the hover/focus pair
drops from a barely-passing 4.64:1 resting state to a failing 2.18:1 (the
same mechanical-mirroring issue as item 4, on the default-chain side);
`span.related-tags a` is 4.05:1; `.tag-counter` is 2.08:1; `.mcs-side-badge`
is 4.29:1.

**Suggested action:** Darken each color per the specific values in the
report; for the hover/focus pair, don't darken both sides equally.

#### 14. [contrast, medium risk] Upgrade/maintenance header banner text is 3.67:1

Bold text at body's inherited ~12.8px doesn't qualify for the
bold-large-text exception; live on the **public** gallery too (a
maintenance-lock message), not admin-only.

**Suggested action:** Darken or lighten the pair to reach 4.5:1.

### Touch targets — real, computed rendered sizes

#### 7. [touch-target, high risk] Public filter-chip remove icon renders at only ~15×15 CSS px — smallest confirmed target on the site

Inherits a 15px font-size with no explicit width/height — about 39% of
the WCAG 2.5.8 AA 24×24 minimum area.

**Suggested action:** Give the control an explicit `min-width`/
`min-height: 24px`, decoupling the hit area from the inherited font-size.

#### 20-21, 23. [touch-target, low-medium risk] Selectize/admin chip remove buttons (17px), colorbox prev/next/close (20×20px), and rating stars (16px, JS-zeroed spacing)

Three more real, computed undersized targets, one compounded by JS that
actively strips inter-button spacing (`rating.ts` sets inline
`marginLeft/marginRight: 0` and strips whitespace text nodes between
adjacent star inputs).

**Suggested action:** Widen each to reach 24px per the report's specific
values; for rating stars, also remove the JS margin-zeroing.

### Text truncation and forced-colors

#### 10. [text-truncation, high risk] Album selector's search results destructively slice the album path in JS — the full text never exists in the DOM

**Files:** `themes/default/js/album_selector.ts`

`#fillResults()` calls `#getEllipsisName()`, which slices the name down
to its trailing 50 characters **before ever inserting it into the DOM**
— this is not a CSS clip, so the full path is unrecoverable by a screen
reader or copy-paste, and no `title` fallback exists either. Affects the
shared move/permissions/notifications/search-album-filter dialog used
across both admin and public flows.

**Suggested action:** Stop calling `#getEllipsisName()` before insertion
— insert the full text and apply CSS `text-overflow: ellipsis` (as the
sibling `#prefillResults()` path already correctly does) plus a `title`.

#### 22. [text-truncation, medium risk] Truncated text with no title fallback recurs across 5 separate components

CSS-only ellipsis (full text recoverable in principle, but with no way
to actually recover it in the current UI) across admin album grids, the
shared breadcrumb renderer, upload-queue filenames, group names, and
front-end search-filter tags/albums.

**Suggested action:** Add a `title` at each site; fixing the shared PHP
breadcrumb renderer covers 2 sites in one change.

#### 15. [forced-colors, high risk] Zero forced-colors (Windows High Contrast Mode) support sitewide

A repo-wide search for `forced-colors`/`forced-color-adjust` returns
**zero hits** across the entire codebase. Several real toggle-chip
components signal checked/selected state purely via `background-color`
(or a non-inset box-shadow, also suppressed by forced-colors) — under
forced-colors, the selected filter/view becomes indistinguishable from
unselected ones.

**Suggested action:** Add a baseline `@media (forced-colors: active)`
block restoring `border`/`outline` on custom controls, plus a system-color
fallback for the checked-state rules named in the report.

---

*Generated from workflow run `wf_147eab94-2b5` (task wkwqr92sm). Untracked
scratch file — not committed, safe to delete once reviewed/acted on.*
