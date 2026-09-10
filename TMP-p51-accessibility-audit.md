# P51 (TS/DOM) accessibility audit — temp working notes

Analysis-only workflow run (no files modified). 7 parallel survey
dimensions over ARIA coverage, keyboard navigation, focus management,
alt text, live-region announcements, semantic HTML vs `role="button"`
workarounds, and form labeling. 52 agents, merged into 29 punch-list
items — several of them genuine, severe, task-blocking bugs.

Overall auditor summary: *"The P51-touched JS/DOM surface has real, live
keyboard-accessibility gaps at the core of several everyday admin
workflows: the album-management tree (expand/collapse, rename, delete,
reorder) is almost entirely mouse-only, the profile page's accordion
sections and API-key management are keyboard-unreachable, the admin
menu-reorder screen actively hides a working native input in favor of
drag-only reordering, and the batch photo editor's own Save button can't
be reached by keyboard — these outrank everything else because they
block task completion outright, not just degrade the experience...
Overall: keyboard operability was clearly a stated goal of the P52-J
campaign and is well-executed where applied, but that convention was
inconsistently carried into P51's JS-generated and dynamically-rendered
controls, leaving several whole admin subsystems unusable without a
mouse; ARIA state-exposure and image alt-text are secondary, more
mechanical gaps layered on top of a genuinely inconsistent (not absent)
accessibility baseline."*

## Prioritized punch list (29 items)

### Severe — block task completion outright for keyboard-only users

#### 1. [keyboard-gap, high risk] Admin album-management tree is almost entirely unusable without a mouse

**Files:** `albums.ts`, `vendor/widgets/jqtree.ts`, `albums.latte`

Every real control in the album tree fails keyboard access, and they
compound into a total workflow block: the expand/collapse toggle is a
bare `<div>` bound only via delegated click, no role/tabindex/keydown
anywhere; the row title (rename trigger), `.move-cat-order`, and
`.move-cat-delete` are click-only, and the latter two are `<a>` tags with
**no `href` at all** — not even in the tab order. `jqtree.ts`'s port
deliberately drops `keyboardSupport`/`tabIndex` entirely and never sets
`aria-expanded` despite tracking `node.is_open`. **A keyboard-only admin
cannot expand, rename, delete, or reorder any album** on a page where
the whole point is a tree of albums.

**Suggested action:** Replace the div/hrefless-anchor controls with real
`<button type="button">` elements for toggler/title/order/delete; add
`aria-expanded` to each toggler and give rows a real `role="treeitem"`.
Add a keyboard-accessible alternative to drag-reorder.

#### 2. [keyboard-gap, high risk] menubar.ts hides the native, keyboard-operable "Position" input and forces mouse-only drag reorder

**Files:** `menubar.ts`, `menubar.latte`

A real, fully keyboard-operable `<input type="text" name="pos_...">`
exists per menu block — `menubar.ts` runs **unconditionally on every page
load**, no feature-detect, and hides it while showing a drag button. The
hidden input is still the real data channel read back on submit — **a
working native mechanism actively suppressed** and replaced with a
mouse-only substitute, not a case of no mechanism existing.

**Suggested action:** Stop unconditionally hiding `.menuPos`; keep the
native position input visible (or reveal on focus / via a toggle)
alongside the drag UI.

#### 3. [keyboard-gap, high risk] Batch photo editor's primary Save controls (and per-picture action icons) are keyboard-unreachable

**Files:** `batch_manager_unit.latte`, `batch_manager/unit.ts`

The page's actual Save buttons are `<div class="buttonLike...">` with no
role/tabindex, click-only. `.action-sync-metadata`/`.action-delete-picture`
are hrefless `<a>` tags, also click-only. Blocks the primary
form-submission mechanism of a heavily used bulk photo-metadata editor.

**Suggested action:** Convert all 4 to real `<button type="button">`
elements — no navigation involved, so no `href` needed; native buttons
get Enter/Space for free.

#### 4. [keyboard-gap, high risk] Profile page accordion sections and API-key row controls are entirely keyboard-unreachable

**Files:** `standard_pages/js/profile.ts`, `profile.latte`

Every collapsible section header (Account/Preferences/Password/API-keys)
is a plain `<div>` with no role/tabindex, opened only via click. Within
API-keys, the view/edit/delete row controls have the same gap. **A direct
regression against this same file's own correct convention** a few lines
away (the `.close-modal` dialog buttons correctly use
role=button+tabindex+keydown). A keyboard-only user cannot open any
profile section, nor view, copy, rename, or revoke an API key.

**Suggested action:** Replace the section headers and row-control icons
with real `<button type="button">` elements; add `aria-expanded` to each
header.

#### 5. [keyboard-gap, high risk] Album-edit page's representative-photo controls and Save action are keyboard-unreachable

**Files:** `cat_modify.latte`, `categories/modify.ts`

`#refreshRepresentative`/`#deleteRepresentative` are `<a>` tags with **no
`href`** — not focusable at all. `#cat-properties-save` is a bare
`<span>`. Inconsistent with the same file's own correct convention
applied to its zoom triggers a few lines away.

**Suggested action:** Convert all 3 to real `<button type="button">`
elements, keeping existing click bindings.

#### 6. [bug, high risk] Multiple real forms have broken `<label for>` targets — screen readers cannot associate visible labels with their fields

**Files:** `register.latte`, `profile_content.latte`, `comments.latte`, `cat_list.latte`, `search_filters.inc.latte`

Four independent, confirmed id/for mismatches on live forms: (1)
`register.latte` — Username label targets nothing (real id is `login`);
Confirm-Password label wrongly repeats the Password field's `for`,
leaving confirm unlabeled. (2) `profile_content.latte` — Theme/Language
selects have **no `id` at all**; Recent-period label targets a string
containing a space (can never match). (3) **`comments.latte`'s entire
public filter form (7 fields) has no `id`/`for` pairing anywhere** — every
field on this public-facing form is unlabeled, with no JS to patch it.
(4) `cat_list.latte`/`search_filters.inc.latte` have two more
id/for mismatches (missing id; hyphen-vs-underscore typo).

**Suggested action:** Fix each id/for pair directly per the file-by-file
list in the rationale.

### Widespread click-only controls in real, everyday workflows

#### 7. [missing-aria, high risk] ~20 icon-only role="button" close/dismiss controls have no accessible name

**Files:** 11 templates across admin/default/standard_pages

Roughly 20 sites correctly carry `role="button" tabindex="0"` and a
working keydown handler (the P52-J keyboard-operability convention IS
genuinely applied), but are empty icon-font elements with no text
content, no `aria-label`, and no `title`. A screen-reader user tabbing to
any of these real, functional close controls hears only "button" with no
indication of what it does. **Sibling controls in the same files** that
do carry visible text or a `title` prove this is an inconsistently-applied
gap, not a blanket miss.

**Suggested action:** Add `aria-label="Close"` (or more specific per
context) to each cited element — single-attribute, zero-behavior-change
fix, doable as one sweep.

#### 8. [missing-aria, high risk] Custom date/rating/size slider widget has full keyboard support but zero ARIA slider semantics

**Files:** `vendor/widgets/slider.ts`, `plugins_new.latte`, `search_filters.inc.latte`

The handle is a real, focusable element with genuine Home/End/PageUp/
PageDown/Arrow (RTL-aware) keyboard support — but `role="slider"`,
`aria-valuenow`/`valuemin`/`valuemax`, and an accessible name are **never
set anywhere**. Operability exists but is completely invisible to screen
readers, on 2 real, reachable filter UIs.

**Suggested action:** Add `role="slider"` plus the `aria-value*` triad
(updated on every value change) and an `aria-label` in `slider.ts`'s
`createHandle()`/`refreshValue()`; propagate to `doubleSlider.ts`'s two
handles.

#### 9-13. [keyboard-gap, medium risk] More click-only controls across related-categories widgets, filter-panel toggles, comment-permission dropdown, cache-purge checkboxes, and the shared search-cancel icon

**Files:** `pictureModify.ts`/`batch_manager/unit.ts` (related-categories chips, duplicated across 2 pages); `comments.ts`/`users/list.ts`/`plugins/new.ts` (3 "Filters" panel toggles); `categories/modify.ts` (comment-permission dropdown); `maintenance/actions.ts` (cache-purge size checkboxes); `common.ts` + 6 templates (shared `.search-cancel`, propagates to ~9 admin pages)

All follow the identical pattern: a real, live, reachable control bound
click-only with no keydown counterpart, in files that correctly apply the
role=button convention *elsewhere*. The `.search-cancel` one is
highest-leverage — fixing the shared `common.ts` binding once propagates
to ~9 pages.

**Suggested action:** Convert each to a real `<button type="button">`
(or checkbox, for the cache-purge item) keeping existing click handlers.

#### 14. [semantic-html, medium risk] Several role="button" workarounds (label, div, a) should be real `<button>` elements — no structural reason not to

**Files:** `user_list.latte`, `album_selector.inc.latte`, `albums.latte`, `tags.latte`, `photos_add_direct.latte`, `users/list.ts`

Two patterns: (1) three `<label role="button">` elements wrap **no form
control** and have no `for` — a label with nothing to label. (2)
Standalone dialog-footer action pairs across 5 files are all
`<div>`/`<a>`/`<p> role="button">`, each needing its own bespoke ~3-line
Enter/Space keydown handler (9+ near-identical copies just in
`albums.ts`) — while `comments.latte` **already proves** a real
`<button type="button">` works correctly in this exact structural slot.

**Suggested action:** Convert to `<button type="button">` matching
`comments.latte`'s own precedent; delete the now-redundant per-selector
keydown boilerplate across 5 controller files.

### ARIA gaps on real interactive widgets

#### 15. [missing-aria, medium risk] selectize.ts's tag-select combobox carries zero ARIA

**Files:** `vendor/widgets/selectize.ts`

No `role="combobox"`/`aria-expanded` on the input, no `role="listbox"` on
the dropdown, no `role="option"`/`aria-selected` on rows, no
`aria-activedescendant` reflecting the cursor. Full keyboard support is
real (Escape/Arrow/Enter/Backspace all wired) — operability exists but is
invisible to screen readers. Live in 7 real admin templates. The per-item
remove control's accessible name is also just "×".

**Suggested action:** Add the standard combobox/listbox/option ARIA
triad plus `aria-activedescendant`; add `aria-label="Remove"` to the
remove anchor.

#### 16. [keyboard-gap, medium risk] tiptip.ts/cluetip.ts tooltips are hover-only, and tiptip.ts actively strips the native title fallback

**Files:** `vendor/widgets/tiptip.ts`, `cluetip.ts`, `languages_new.latte`

Both bind exclusively via hover — focus/click activation was **deliberately
dropped** per the port's own header comment. Worse, `tiptip.ts` calls
`removeAttr(el, "title")` once bound, **deleting even the native
browser/AT fallback** a bare `title` would otherwise provide on focus. A
real call site applies this to natively-focusable links whose `title`
carries substantive description text shown nowhere else — a keyboard-only
user tabbing onto that link can never see it (WCAG 1.4.13).

**Suggested action:** Add focus/blur handlers alongside hover (position
via the target's bounding rect when triggered by focus); stop removing
the `title` attribute — keep it as a fallback.

#### 17. [missing-aria, medium risk] Toast/notification containers have no role or aria-live — save/error feedback is silent to screen readers

**Files:** `vendor/widgets/jgrowl.ts`, `updates/ext.ts`, `standard_pages/toaster.ts`, `toaster.latte`

Both toast mechanisms create their container with no `role`/`aria-live`.
`profile.ts` calls the toaster at 8 real, non-decorative sites
(settings-saved, API-key add/edit/revoke, clipboard success/failure)
where the toast text is the **only feedback given**.

**Suggested action:** Add `role="status" aria-live="polite"` to both
containers — additive, zero-behavior-change.

#### 18. [missing-aria, medium risk] Inline form-validation error text has no role=alert/aria-live, and the native validation bubble was deliberately suppressed with nothing replacing it

**Files:** `standard_pages.ts`, `register.latte`, `password.latte`, `profile.latte`

Live validators show/hide static `<p class="error-message">` elements via
a pure display toggle with no ARIA. In parallel, `input.setCustomValidity("")`
is called specifically to blank the browser's own accessible
validation-tooltip UI — the one already-accessible mechanism was
overridden and never replaced.

**Suggested action:** Add a static `role="alert"` to every
`.error-message` `<p>` — zero JS change required, since new content
becoming visible inside an alert region is announced automatically.

#### 19. [missing-aria, medium risk] Upload/regenerate-derivatives progress bars are visual-only, with no role=progressbar/aria-valuenow

**Files:** `photosAddDirect.ts`, `batch_manager/global.ts`, `photos_add_direct.latte`, `batch_manager_global.latte`

Both progress bars are static markup with no ARIA; the handlers only ever
set CSS width. Both are live, multi-minute-capable batch operations, not
decorative.

**Suggested action:** Add `role="progressbar"` + the `aria-value*` triad,
updated alongside the existing width-setting calls; wrap the status
counter in `aria-live="polite"`.

#### 29. [missing-aria, low risk] batch_manager_global's "X of Y photos selected" status text has no aria-live

**Files:** `batch_manager/global.ts`, `batch_manager_global.latte`

Updated via `textContent` on every selection-changing interaction with no
announcement mechanism.

**Suggested action:** Add `aria-live="polite"` to the static span — no JS
change required.

### Alt-text gaps (inconsistent, not absent)

#### 20. [bug, medium risk] Comment thumbnail/modal images never get `alt`, even though `comment.author` is available at the same call site

**Files:** `comments.latte`, `comments.ts`

**Suggested action:** Add `alt` using `comment.author` (already in scope)
at both real call sites.

#### 21. [semantic-html, medium risk] batch_manager_unit.latte per-photo edit form uses `<strong>` instead of real `<label>` for Title/Author/Level/Tags

**Files:** `batch_manager_unit.latte`

A localized regression — the same file's Creation-date field and the
sibling `batch_manager_global.latte` both correctly use real `<label>`.

**Suggested action:** Replace each `<strong>` with a proper `<label for>`.

#### 22-23. [semantic-html, medium risk] Admin help screenshots and dynamic theme/format thumbnails ship with zero `alt`, several with the real source string already in scope

**Files:** `photos_add_applications.latte`, `configuration_main.latte`, `photos_add_direct.latte`, `themes_new.latte`, `themes_standard_pages.latte`

9 static screenshots need real descriptive alt text; 3 more dynamic
thumbnails have the exact needed field (`$theme['name']`, etc.) already
rendered as sibling text but never passed to `alt`.

**Suggested action:** Add specific alt text per image; wire the
already-in-scope name/label fields into `alt` for the dynamic ones.

#### 24. [bug, medium risk] Two icon images already have `alt`, but the value is a meaningless placeholder while the real text sits unused nearby

**Files:** `menubar_categories.latte`, `thumbnails.latte`, `batch_manager_unit.latte`, `batch_manager_global.latte`

`alt="(!)"` on a "recently updated" icon while the real translated text
sits in `title=`; a hardcoded literal `alt="imagename"` while the sibling
file correctly interpolates the real filename — proving the fix pattern
is already known elsewhere in the same codebase.

**Suggested action:** Fix both to use the real, already-available text.

#### 25. [missing-aria, medium risk] Standard_pages site logo images have zero `alt` on every login/register/password/profile page

**Files:** 4 standard_pages templates

The sibling branch of the same conditional renders `<h1>{$GALLERY_TITLE}</h1>`
for the alternate logo-mode, proving this slot is meaningful site-identity
content, not decoration.

**Suggested action:** Add `alt="{$GALLERY_TITLE}"` to both logo `<img>`
tags in all 4 templates.

#### 26. [semantic-html, low risk] Decorative ajax-loader/spinner GIFs (and one promo banner) have no `alt=""`

**Files:** 8 templates, 10 confirmed occurrences

**Suggested action:** Add `alt=""` to all 10 — one-line change per site.

#### 27. [semantic-html, low risk] Watermark preview image has no `alt`

**Files:** `configuration_watermark.latte`, `configuration/watermark.ts`

**Suggested action:** Add a single static translated `alt` — no JS change
needed.

#### 28. [semantic-html, low risk] Email notification photo has no `alt`, and the source data is dropped before it reaches the template

**Files:** `mail/text/html/cat_group_info.latte`, `AlbumNotificationPageRenderer.php`

The PHP renderer builds the image array from a real row with `name`/
`comment` fields available, but drops them before they reach the
template.

**Suggested action:** Thread `name`/`comment` through as `alt` in the PHP
renderer and the view's array shape, then consume it in the template.

---

*Generated from workflow run `wf_69f37860-7f1` (task w160hzweu). Untracked
scratch file — not committed, safe to delete once reviewed/acted on.*
