# P52 (CSS architecture) consolidation audit — temp working notes

Analysis-only workflow run (no files modified). 7 parallel survey dimensions
over the P52-touched CSS/tooling surface, 49 raw findings, 48 survived
independent skeptical verification against real source (real `.latte`/`.ts`
usage, `stylelint-suppressions.json`, git history, `docs/PLAN.md`
cross-references).

Overall auditor summary: *"The P52-touched CSS is largely well-modernized
(logical properties, layered cascade, tokens.css) but carries a long tail
of unconsolidated cruft from incremental campaigns: dozens of pre-Latte/
pre-rename dead selectors sit alongside several genuinely duplicated
components (dialog resets, selectize chips, ellipsis flyouts, table
headers) that were fixed independently in each copy rather than shared.
More consequential are a handful of real, currently-live regressions the
campaign's own audits didn't catch — an accessibility focus-indicator gap,
an RTL timepicker misalignment, a dark-mode contrast failure, a dark-mode
filter panel with zero styling, and a broken selector silently dropping
monospace styling — which should be fixed before the lower-stakes cleanup.
Two admin areas (user_list.css, and the still-embedded cat-modify/profile
components) have outgrown the project's own established page-split pattern
and are due for the same extraction treatment already applied elsewhere. A
smaller tail of items are meta/tooling gaps (stale audit-trail comments, a
scanner blind spot for @import and for string-concatenated ids) or
forward-looking lint-tooling suggestions — useful, but lower urgency than
the functional fixes above."*

## Prioritized punch list (35 items)

### Real, currently-live regressions — fix these first

#### 1. [inconsistency, medium risk] Real focus-indicator (a11y) regressions from outline:none with no compensating :focus rule

**Files:** `themes/admin/clear/theme.css`, `themes/admin/roma/theme.css`, `themes/admin/default/theme-base.css`

3 confirmed live sites strip `outline:none` via a selector that never
contains `:focus`, so stylelint's `no-outline-none` rule never inspects
them, and no compensating focus style exists at a winning layer/
specificity: `clear/theme.css:794-799` (#filterList/#permitAction/
.sort-by selects), `roma/theme.css:614-624` (AddAlbum/AddUser/
DeleteAlbum/RenameAlbum popin inputs), `theme-base.css:3903-3928`
(.calendar-box/.half-line-info-box/.description-box inputs). Keyboard-only
admins lose all visible focus indication on these real, in-use form
controls. (The standard_pages `theme.css` instance originally flagged
alongside these is a false positive — a `:focus-within` compensator
already covers it — and should not be touched.)

**Suggested action:** Add explicit `:focus`/`:focus-visible` replacement
styling (e.g. a visible border-color or outline restore) at each of the 3
confirmed sites, verified in each theme chain with a real keyboard
tab-through. Do not touch `standard_pages theme.css:153-160` (already
correctly compensated via `:focus-within` at lines 1228-1230).

#### 2. [inconsistency, medium risk] RTL date/time picker: dd label misaligns because only the dt sibling rule was converted to logical properties

**Files:** `themes/admin/default/css/components/jquery-ui.css`, `themes/default/js/vendor/widgets/datepicker.ts`

`jquery-ui.css`'s header wrongly claims `isRTL` is never true;
`datepickerLocales.ts` has real `isRTL:true` locales (e.g. `ar`) reachable
via 3 real call sites (`pictureModify.ts`, `batch_manager/global.ts`,
`batch_manager/unit.ts`) that pass both the admin's live `jquery_code` and
`showTimepicker:true`. Most RTL positioning already auto-flips via logical
properties, but `.ui-timepicker-div dl dd` was left as a hardcoded
physical `margin:0 10px 10px 40%` while its `dl dt` sibling was correctly
converted — for a real RTL-language admin, the time label and its value
overlap/misalign.

**Suggested action:** Correct the false "isRTL never true" claim in
`jquery-ui.css`'s header comment, then add an RTL-aware override for
`.ui-timepicker-div dl dd` (mirroring the vendor's original
`.ui-timepicker-rtl dl dd { margin: 0 40% 10px 10px; }`) so it flips
together with `dl dt` when `dir=rtl`.

#### 3. [inconsistency, medium risk] Dark-mode "Search tips" label loses contrast on hover/focus (#3C3C3C on #333 background)

**Files:** `themes/default/css/search.css`, `themes/default/css/dark-search.css`

`search.css:1081-1086` sets `.help-popin-search:hover span`/
`:focus-visible span` to `#3C3C3C` at specificity `(0,2,1)`;
`dark-search.css:120-123` sets the intended `#fff` at only `(0,2,0)` in
the same `@layer pages`, so it always loses. Real, currently-live contrast
regression for dark-mode users hovering/focusing the label. The
`.icon-help-circled::before` half of the same selector group is dead
(stale pre-P52-G icon class, real icon is `gallery-icon-help-circled`) and
can be dropped in the same pass.

**Suggested action:** Add a same-or-higher-specificity override in
`dark-search.css`, e.g. `.help-popin-search:hover span,
.help-popin-search:focus-visible span { color: #fff; }`, and drop the dead
`.icon-help-circled::before` branches from `search.css`'s selector group.

#### 4. [other, medium risk] Missing comma glues two selectors together, silently dropping monospace styling on the API key display and ID input

**Files:** `themes/standard_pages/theme.css`

Lines ~695-699 lack a comma between two selectors, forming one dead
compound selector instead of two live ones, so the intended monospace
styling for the API key display and ID input never applies. Confirmed
live since the original 2025 import and untouched even by a later commit
that edited an adjacent line of the same rule block.

**Suggested action:** Insert the missing comma to split the compound
selector back into two separate rules; verify visually that both the API
key display and the ID input regain their intended monospace font.

#### 5. [inconsistency, low risk] Global prefers-reduced-motion reset zeroes duration but not delay, so staggered animations still visibly stall

**Files:** `themes/default/css/reset.css`

`reset.css`'s global `@media (prefers-reduced-motion: reduce)` rule sets
`animation`/`transition-duration` to `0.01ms` but never touches
`animation-delay`/`transition-delay`. Real two-stage interactions
(`theme-base.css:296-361` add-album reveal with `transition-delay:0.4s`,
`standard_pages theme.css:839`'s `animation-delay:100ms`) still make a
reduced-motion user wait through the full delay before the now-instant
visual change fires.

**Suggested action:** Add `transition-delay: 0.01ms; animation-delay:
0.01ms;` to the existing global reduced-motion rule in `reset.css`,
matching the same one-place philosophy already used for duration.

#### 6. [inconsistency, low risk] roma jqtree drag-state icon-blue copy-pastes icon-green's color instead of its own

**Files:** `themes/admin/roma/theme.css`

Lines 2501-2505 (`.jqtree-moving ... icon-blue`) are byte-identical to the
icon-green block immediately above (`#50a48f`/`#a9f6e3`) instead of
icon-blue's own base values (`#4f71a4`/`#9fbef1`, lines 1643-1646).
Purple/red/yellow/green all correctly echo their own base color before
being dimmed by a shared `opacity:0.6` rule; blue is the sole outlier, so
drag-moving a category shows the wrong blue shade in roma.

**Suggested action:** Change the `.jqtree-moving ... i.icon-blue`/
`.node-icon.icon-blue` background/color values at `roma/theme.css:2501-2505`
from `#50a48f`/`#a9f6e3` to icon-blue's own `#4f71a4`/`#9fbef1`.

#### 7. [inconsistency, medium risk] "Date created" search filter has zero dark-mode colors (fully unstyled in dark mode)

**Files:** `themes/default/css/dark-search.css`, `clear-search.css`, `search.css`

`clear-search.css` defines a full 13-rule dark/light-independent color set
for the `date_created` filter variant (zebra striping, checked-icon color,
badge background, option label background/border, selected highlight);
`dark-search.css` only ever refactored the pre-existing `date_posted`
rules and never added `date_created` equivalents. The entire "Date
created" filter panel renders unstyled in dark mode. Predates P52 and was
never caught since it's a missing-selector gap, not a lint violation.

**Suggested action:** Add the 13 missing `date_created`-scoped dark-mode
rules to `dark-search.css`, using `clear-search.css`'s existing
`date_created` rules as the structural template with dark-appropriate
values (mirroring the existing `date_posted` dark rules already in the
file).

#### 8. [inconsistency, low risk] Mobile .filter-form width diverges between color schemes (101vw vs 100vw)

**Files:** `themes/default/css/clear-search.css`, `dark-search.css`, `search.css`

Same `@media (max-width: 600px) { .filter-form { ... } }` override:
`clear-search.css:347` uses `width:101vw` while `dark-search.css:339` and
`search.css:787` use `100vw`. Traced to the same original 2023 commit as a
day-one copy/paste slip between colorscheme files, not a later
regression — no documented rationale for the extra 1vw exists.

**Suggested action:** Change `clear-search.css:347`'s `width: 101vw` to
`100vw` to match `dark-search.css` and `search.css`.

### Real, unaddressed gaps

#### 9. [other, low risk] album_selector.css search-icon/cancel positioning uses hardcoded physical pixels, never adjusted for RTL

**Files:** `themes/admin/default/css/components/album_selector.css`, `themes/default/js/album_selector.ts`

`.search-icon` and `.search-cancel-linked-album` position via fixed
`transform: translate(Npx, 8px)` physical offsets with no RTL adjustment
anywhere in `album_selector.ts`. Unlike jcrop/colorbox/slider's documented
pixel-math exception, this is an ordinary text-input decoration with no
photo-pixel justification — a real, unaddressed gap for RTL-language
admins.

**Suggested action:** Replace the physical `transform: translate(Npx,
8px)` offsets on `.search-icon` and `.search-cancel-linked-album` with
logical `inset-inline-start`/`end` positioning so both swap sides
correctly under `dir=rtl`.

#### 10. [inconsistency, low risk] standard_pages --color-danger / --color-danger-text tokens defined but never consumed

**Files:** `themes/standard_pages/css/tokens.css`, `css/pages/toaster.css`

`tokens.css:144-147` defines these two tokens as an exact OKLCH conversion
of `toaster.css`'s own error-toast colors (`#be4949`/`#ffc8c8`), with a
comment naming `toaster.css` as the source — yet `toaster.css` still
hardcodes the literals and repo-wide grep finds zero `var(--color-danger`/
`var(--color-danger-text` consumers anywhere. Unlike admin's analogous,
genuinely-wired danger tokens, this pair is a pure oversight, not a
deliberate deferral.

**Suggested action:** Replace `toaster.css`'s hardcoded `#be4949`/
`#ffc8c8` literals in `.light/.dark .toast.error` (lines ~39-52) with
`var(--color-danger)`/`var(--color-danger-text)`.

#### 11. [inconsistency, low risk] jQuery-UI range-slider colors are hardcoded literals that never vary by colorscheme

**Files:** `themes/default/css/search.css`, `clear-search.css`, `dark-search.css`

The real, actively-rendered range-slider rules (`search.css:905-998` —
`.ui-widget-content`, `.ui-slider-range`, `.ui-state-*`) are all hardcoded
hex with zero occurrences in `clear-search.css`/`dark-search.css`, even
though `slider.ts` is a real, live-driven widget behind the
filesize/height/width/ratio filters. A sibling branch (16.x-rewrite commit
`496a476302`) already fixed this exact gap by tokenizing to
`var(--search-*)`; it was never ported to 17.x-rewrite.

**Suggested action:** Port 16.x-rewrite commit `496a476302`'s approach
forward: replace `search.css`'s hardcoded slider colors with
`var(--search-*)` tokens and add colorscheme-specific values to
`clear-search.css`/`dark-search.css`.

### Consolidation (merge duplicated components)

#### 12. [merge, low risk] 5 scattered &lt;dialog&gt; reset rules duplicate the same 6 properties (4 share an identical ::backdrop)

**Files:** `themes/admin/default/theme-base.css`

`.RenameTagPopIn`, `.cat-move-order-popin`, `.UserListPopIn`, `.bg-modal`,
and `.desc-modal` (spread ~4000 lines apart) each independently declare
the identical P52-J dialog-reset shorthand (padding/border/background/
max-width/max-height/overflow), and 4 of the 5 also declare an identical
`::backdrop` rule. The codebase already demonstrates the better pattern
one file over (`albums.css` groups sibling popins into one
comma-separated selector list).

**Suggested action:** Consolidate the 5 dialog-reset declarations into one
comma-separated selector list in `theme-base.css`, and group the 4-way
identical `::backdrop { background-color: rgb(0,0,0,0.7); }` similarly,
mirroring the existing AddAlbumPopIn/DeleteAlbumPopIn/RenameAlbumPopIn
pattern in `albums.css`.

#### 13. [merge, low risk] Selectize "orange chip" item/remove styling duplicated verbatim across 4 page files

**Files:** `batch_manager_global.css`, `batch_manager_unit.css`, `picture_modify.css`, `user_list.css`

All 4 files declare the identical `.item`/`.item.active`/`.item .remove`
chip styling (background `#ffa646`, border-radius:20px, hover/focus
`#f70`), differing only in selector prefix. An RTL border-radius bug in
this exact block was already fixed independently in each of the 4 copies
rather than consolidated — direct evidence the duplication actively costs
maintenance effort. `tokens.css` already defines `--color-accent-light`
for this exact value.

**Suggested action:** Extract the shared `.item`/`.item.active`/
`.item .remove` chip styling into one shared `components/` rule
(comma-grouped across the 4 page-specific selector prefixes), switching
the literal `#ffa646` to `var(--color-accent-light)`; also fix
`user_list.css`'s stray missing space in `background-image:none`.

#### 14. [merge, low risk] View-selector icon sizing duplicated in 2 page files and already redundant with theme-base.css

**Files:** `cat_list.css`, `user_list.css`, `theme-base.css`

`cat_list.css:33-38` and `user_list.css:1626-1631` redeclare a block
already present with identical values in `theme-base.css:3344-3348`'s
`theme-chain` layer, which the `pages` layer (holding both page files)
always cascades after — so the two page-level copies are inert.
`.selectedAlbum-first { margin-inline-start: 0; }` is a separate, genuine
byte-identical duplicate between the two page files with no shared-layer
equivalent.

**Suggested action:** Delete the redundant view-selector icon-sizing block
from both `cat_list.css` (33-38) and `user_list.css` (1626-1631), relying
on the existing `theme-base.css` rule; separately, move
`.selectedAlbum-first` into a shared location consumed by both files
instead of duplicating it.

#### 15. [merge, medium risk] Ellipsis dropdown/flyout-menu component duplicated between cat_modify.css and history.css

**Files:** `cat_modify.css`, `history.css`

`cat_modify.css`'s `.toggle-comment-option`/`.comment-option` and
`history.css`'s `.toggle-img-option`/`.img-option` are the same
component: identical gradient background, container border-radius,
hover/focus corner rules, triangle-tail, and icon-scale transform. Real
variance is flyout direction (triangle rotate 0deg vs 270deg) and anchor
strategy (relative vs absolute+margin) plus element tag (span vs a) — a
shared extraction must parameterize these, not just an offset.

**Suggested action:** Extract the shared ellipsis-flyout styling into a
`components/` file parameterized by flyout-direction, anchor-position, and
element tag, and have both `cat_modify.css` and `history.css` consume it
instead of their independent copies.

#### 16. [merge, low risk] Activity/log table header boilerplate byte-identical between history.css and user_activity.css

**Files:** `history.css`, `user_activity.css`

4 blocks (`.container`/`.tab` flex layout, `.tab-title` flex row, `.hide`,
`.tab-title div` text styling) are byte-for-byte identical including
blank-line placement, both confirmed live via matching real markup in
`history.latte` and `user_activity.latte`. No documented rationale for the
duplication exists in either file.

**Suggested action:** Move the 4 identical blocks into a shared
`components/` file or `theme-base.css` rule consumed by both `history.css`
and `user_activity.css`, removing the duplicate declarations from one of
them.

### Dead-code removal

#### 17. [remove, low risk] Dead pre-rename/legacy selector families in theme-base.css (7 verified-dead groups)

**Files:** `theme-base.css`, `roma/theme.css`, `clear/theme.css`, `themes/default/theme.css`

7 independently-verified dead groups, each confirmed via repo-wide grep
across all `.latte`/`.ts`/`.js` files: (1) both byte-identical
`@keyframes animatedBackground` blocks (4334-4342, unused by anything);
(2) the `.userSeparator`/`.userProperties*`/`.userPrefs`/`.userProperty`/
`.userActions` family (5847, 7027-7057) plus color overrides in
`roma/theme.css` (1047, 1084-1085) and `clear/theme.css` (658, 688-689,
714); (3) `.AddUserLabel`/`.AddAlbumLabel`/`.DeleteAlbumLabel`/
`.AddUserInput`/`.AddAlbumInput`/`.DeleteAlbumInput`/`.RenameAlbumInput`
(8349-8361); (4) `.dataTables_filter`/`.dataTables_length` (never created
by the ported `dataTable.ts` widget); (5) `.optgroup-header` (selectize
port has no optgroup support — same dead selector also in
`themes/default/theme.css:908`); (6) `.sort .icon-sort-number-up`
(151-153, no template ever emits this class); (7) the dead half of
`.user-property-column-title, .edit-username-title` (8159, plus the same
dead half in `roma/theme.css:2023-2024` and `user_list.css:411-419`) —
keep `.edit-username-title` only.

**Suggested action:** In one pass, delete all 7 verified-dead groups
listed in the rationale from `theme-base.css` (and their sibling dead
declarations in `roma/theme.css`, `clear/theme.css`, and
`themes/default/theme.css:908`), keeping only the confirmed-live selectors
each was grouped with.

#### 18. [remove, medium risk] Further batch of 23 selectors in theme-base.css with zero real usage — needs per-selector verification before removal

**Files:** `theme-base.css`

A repo-wide grep found 23 additional selectors (`.addGroupFormTitle`,
`.advanced-filter-dates-max/-min`, `.albumBlock`, `.albumLineBlock`,
`.badge-count`, `.bc-albums`, `.close-apps`, `.filter_search_input`,
`.formButtons`, `.full-line-info-box`, `.input-active`,
`.main-info-icon`, `.menuSubmit`, `.nextStepLink`, `.pictureLevels`,
`.pluginEmptyInput`, `.popinWait`, `.qsearch_help_table`, `.start-date`,
`.syncBtn`, `.tag-rename`, `.unavailablePlugin`, `.warningDeletion`) with
no matches anywhere outside this file, but unlike item 17's groups, no
specific root cause has been traced for each individually yet.

**Suggested action:** Run a targeted per-selector verification pass (grep
+ template/JS trace) on each of the 23 listed selectors, confirming a root
cause the way item 17's groups were confirmed, before deleting any of
them.

#### 19. [remove, low risk] Dead-code sweep across themes/default frontend CSS (5 verified-dead groups)

**Files:** `themes/default/theme.css`, `search.css`, `dark-search.css`, `clear-search.css`

5 independently-verified dead sites in the default (frontend) chain: (1)
`.related-tag-condition` (theme.css:1059-1066, orphaned after its
template was replaced); (2) `.optgroup-header` half of the
selectize-dropdown rule (theme.css:908-910, no optgroup support in
selectize.ts); (3) 3 stale pre-rename icon selectors —
`.mcs-icon.pwg-icon-cog` (search.css:181), `.pwg-icon-cancel::before`
(dark-search.css:321), and the dead `.icon-help-circled::before`
hover/focus branches (search.css:1082/1084) — all superseded by the
gallery-icon-* rename; (4) `.disableSlider`/`.ui-state-hover`/
`.ui-state-focus` (search.css ~968-990), never toggled by slider.ts, which
only ever applies `ui-state-active`; (5) the duplicated non-color
`.search-input { padding; margin-bottom; }` block in
clear-search.css:322-325, redundant with search.css's own copy.

**Suggested action:** Delete all 5 verified-dead groups from their
respective files; for group 3, apply after item 3's contrast fix so the
live `span` selectors in that group are preserved.

#### 21. [remove, low risk] Dead .rotate-anim + private @keyframes spin duplicate the standard icon-spin6/animate-spin idiom

**Files:** `maintenance_actions.css`

Lines 78-86 define `.rotate-anim` and a private `@keyframes spin`,
applied nowhere in `maintenance_actions.latte` or `maintenance.ts`. The
established, documented, actively-used spin mechanism is
`icon-spin6`/`animate-spin` backed by the shared `@keyframes spin` at
`theme-base.css:6166` (used by 15+ admin templates/TS files).

**Suggested action:** Delete `.rotate-anim` and its private `@keyframes
spin` (lines 78-86) from `maintenance_actions.css`.

#### 22. [remove, low risk] Dead filter-chip selectors in user_activity.css left over from the original P39-B port

**Files:** `user_activity.css`

`.actions-filters`, `.activity-period-info`, `.cancel-icon`, and
`.activity-filter-container .icon-cancel` have zero matches anywhere
outside this file. The real template uses `.additional-filters`/
`.additional-filters-info` instead, meaning these describe a filter-chip
remove affordance that either never shipped or was renamed away without
cleanup.

**Suggested action:** Remove `.actions-filters`, `.activity-period-info`,
`.cancel-icon`, and `.activity-filter-container .icon-cancel` from
`user_activity.css`.

#### 23. [remove, low risk] [data-tooltip] tooltip CSS in standard_pages theme.css is entirely dead

**Files:** `themes/standard_pages/theme.css`

The `[data-tooltip]:hover`/`::after` rule plus its `.light`/`.dark`
background-color variants (lines 834-846, 987-988, 1183-1184) render
`attr(data-tooltip)` content, but no template anywhere in the repo sets a
`data-tooltip` attribute (static or dynamic).

**Suggested action:** Remove the `[data-tooltip]:hover::after`/
`:focus-visible::after` rule and its `.light`/`.dark` background-color
variants at the 3 line ranges listed.

#### 24. [remove, low risk] 4 selectors in standard_pages theme.css's top hide-list target elements that don't exist in its own templates

**Files:** `themes/standard_pages/theme.css`

The `display:none` comma-group at lines 21-30 mixes real selectors
(`#api_custom_date` etc.) with dead ones: `#theHeader` and `#copyright`
only exist in default's `layout.latte` (standard_pages never shares that
layout), `.template-section` appears in no `.latte` file anywhere, and
`.api_name_edit` doesn't match any markup (the real class is
`.api_name`). `.api_name_edit` also has its own separately-dead styled
rule at lines 667-672.

**Suggested action:** Remove `#theHeader`, `#copyright`,
`.template-section`, and `.api_name_edit` from the `display:none` group at
lines 21-30, and delete the separately dead `.api_name_edit` rule at
lines 667-672.

#### 25. [remove, low risk] 5 of 6 z-index scale tokens (18 @property blocks total) are dead code repo-wide

**Files:** all 3 `tokens.css` files

All 3 `tokens.css` files register the identical 6 z-index tokens via
`@property`, but a repo-wide grep for `var(--z-` finds exactly one real
consumer (`jconfirm.css:55`, `--z-modal`). `--z-dropdown`/`--z-sticky`/
`--z-overlay`/`--z-toast`/`--z-tooltip` have zero consumers despite
obvious real candidates left unmapped (`selectize.css:86`,
`toaster.css:15`, `jgrowl.css:35`, `jqtree.css:126`).

**Suggested action:** Either wire the 5 unused tokens to their obvious
candidates (`selectize.css:86` → `--z-dropdown`, `toaster.css:15`/
`jgrowl.css:35` → `--z-toast`, `jqtree.css:126` → `--z-dropdown`) or, if no
near-term plan exists, delete the 5 unused `@property`/value declarations
from all 3 `tokens.css` files, keeping only `--z-modal`.

### Split (files that outgrew their bounds)

#### 20. [split, medium risk] The single-page .cat-modify-* component (~200 lines) sits in the monolithic theme-base.css instead of the existing cat_modify.css

**Files:** `theme-base.css`, `cat_modify.css`

`theme-base.css:419-808` defines the full `.cat-modify` component
(`.cat-modify`, `.cat-modify-header`/`-ariane`, `.cat-parent-nav`,
`.cat-modify-actions`, `.cat-modify-infos`, `.cat-modify-representative`,
`.cat-modify-form`/`-input-container`, `.cat-delete-modes`), confirmed
used exclusively by `cat_modify.latte`/`modify.ts`. `cat_modify.css`
already exists and already extends this same component, confirming the
split target and precedent. The `.savebar-*` selectors comma-grouped into
`.cat-modify-footer` must stay in `theme-base.css` since 14 other
templates use them. `cat_modify.css` also already contains a dead
`.cat-modify-footer .spinner` rule (no matching markup) that should be
removed in the same pass.

**Suggested action:** Move the `.cat-modify-*` component
(`theme-base.css:419-808`, excluding the shared `.savebar-*` selectors)
into `cat_modify.css` under its existing `@layer pages`/`pages-base`
structure; delete the dead `.cat-modify-footer .spinner` rule already in
`cat_modify.css` while there.

#### 28. [split, low risk] Profile page's substantial page-specific CSS lives in shared theme.css instead of the existing profile.css

**Files:** `themes/standard_pages/theme.css`, `css/pages/profile.css`

`.profile-section`/`.api-*` rules (~lines 453-704 plus `.light`/`.dark`
variants) and the API-key modal system (`.bg-modal`/`.body-modal`/
`.close-modal`/`.head-modal`/`.title-modal`/`.subtitle-modal`/
`.input-modal-*`, lines 707-778) are confirmed used only by
`profile.latte`, none of the other 3 standard_pages templates.
`css/pages/profile.css` already exists as the designated home per the
established pattern but holds only 3 lines. Since `pages` loads after
`theme-chain` in the layer order, moving these rules preserves the
current cascade outcome exactly.

**Suggested action:** Move the `.profile-section`/`.api-*` block and the
API-key modal system out of `theme.css`'s `theme-chain` content into
`css/pages/profile.css`'s `pages`-layer content, verifying no cascade
outcome changes.

#### 29. [split, medium risk] user_list.css has grown ~4.5x larger than any other page file, mixing 3 popins and 3 table-row views

**Files:** `user_list.css`

At 2155 lines / 38.7KB it is by far the largest file in `css/pages/`
(next largest, `history.css`, is 477 lines). It bundles genuinely
separable sub-features: selection mode, the header/filters bar, 3 table
row-view variants (Tile/Compact/Line), and 3 distinct `<dialog>`-backed
popins (EditUserPopIn, GuestUserPopIn, AddUserPopIn) each with their own
summary/properties/preferences/plugins sub-UI, plus cross-cutting
selectize/slider rules shared by all three popins.

**Suggested action:** Split `user_list.css` along its existing internal
section boundaries, e.g. into `user_list_table.css` (Tile/Compact/Line
views + view selector) and `user_list_popins.css` (the 3 popins + their
shared selectize/slider rules), following the `theme.css`/`theme-base.css`
split as precedent.

### Meta / tooling / documentation

#### 26. [inconsistency, low risk] .clear-both utility bypasses the utilities layer / u- naming convention

**Files:** `pages/picture.css`, `utilities.css`

`utilities.css` establishes a dedicated `utilities` layer with
`u-`-prefixed helper classes, but `picture.css` independently defines a
same-shaped generic clearfix `.clear-both` inside the `pages` layer
instead, used once (`picture.latte:532`). Admin's own `utilities.css`
already has a `.u-clear-both` precedent.

**Suggested action:** Move `.clear-both { clear: both; }` out of
`pages/picture.css` into `utilities.css`'s `utilities` layer, renamed
`u-clear-both`, and update its single consumer in `picture.latte:532`.

#### 27. [inconsistency, low risk] colorbox.css's "never creates #cboxMiddleLeft/#cboxBottomLeft" comment is factually wrong (grep methodology blind spot)

**Files:** `colorbox.css`, `colorbox.ts`

`colorbox.ts`'s own `tag(id)` helper builds these ids via string
concatenation (`tag('MiddleLeft')` → `id='cboxMiddleLeft'`), which a
literal grep for the id string would never find. The rule removal is
still functionally safe, but for a different, uncredited reason:
`clear:left` is separately applied via inline JS style on the row wrapper
divs. The same "confirmed dead via grep" methodology is provably
insufficient for any id/class built via string concatenation (also used
by `jcrop.ts`'s `ord-${ord}` and `jgrowl.ts`'s `${p.theme}`), and other
"confirmed dead via grep" claims in this campaign warrant a second pass
with that blind spot in mind.

**Suggested action:** Correct `colorbox.css`'s header comment to state the
real reason the rule removal is safe, and re-check other "confirmed dead
via grep" claims in this campaign for string-concatenated id/class blind
spots.

#### 30. [inconsistency, medium risk] tools/css-layers/scan.py has no @import handling and misses themes/default/iconset.css's real theme-chain selectors

**Files:** `scan.py`, `themes/default/theme.css`, `iconset.css`

`theme.css:12` does `@import "iconset.css" layer(theme-chain);`, a real,
live load putting ~33 selectors (`.pwg-icon`, `.pwg-icon-home`, etc.)
into the `theme-chain` layer on every page. `iconset.css` isn't
registered via `AssetContribution::css()` and `scan.py`'s
`DEFAULT_UNIVERSAL` file pool doesn't include it, nor does the tool's
parser handle `@import` at all — so none of these live declarations are
visible to the `--inversions`/`--important` cascade-collision analysis for
the default chain.

**Suggested action:** Add `@import`-statement parsing to `scan.py` that
resolves `@import "file" layer(name);` relative to the importing file and
merges its selectors into that layer's analysis, then re-run
`--inversions`/`--important` for the default chain to surface any
newly-visible collisions.

#### 31. [inconsistency, low risk] No shared naming convention across the 3 tokens.css files for "status-color variant" vs. "text/foreground pairing" tokens

**Files:** admin `tokens.css`, standard_pages `tokens.css`

`-flat` (admin) means "a materially different shade of the same status
role"; `-text` (standard_pages) and `-contrast` (standard_pages) both
mean "the foreground color to pair with this status/accent's background"
— a structurally different concept, introduced in separate, unreconciled
campaigns. admin/default has no equivalent "foreground pairing" token
despite a real, confirmed need (`theme-base.css:7432-7433`'s
`.albumCreationIndicator` hardcodes `color:#3c3c3c` paired with
`background-color:#ffa744`).

**Suggested action:** Adopt one consistent suffix convention repo-wide
(keep `-flat` for materially-different-shade variants, standardize
`-text` for foreground-pairing tokens across all 3 chains) and add the
missing foreground-pairing token to admin/default's `tokens.css` to cover
the confirmed real `.albumCreationIndicator` need.

#### 32. [inconsistency, low risk] 3 stale self-referential comments/docstrings now contradict reality

**Files:** `reset.css`, `scan.py`, `.browserslistrc`

(1) `reset.css:69-70` claims the `utilities` layer was "never yet
populated" — false, 3 per-chain `utilities.css` files already populate
it, one (standard_pages) with a documented layer-order bug fix. (2)
`scan.py:109`'s docstring says "118" triaged admin-chain inversion
candidates; the real current admin-chain-scoped baseline in
`known-findings.json` is 104 (the 116 total figure double-counts 12
unrelated default-chain entries). (3) `.browserslistrc`'s "no real
tooling consumer today" comment is now false since
`stylelint-no-unsupported-browser-features` consumes it — the committing
commit's own message acknowledges closing this exact gap but never
updated the comment.

**Suggested action:** Update `reset.css`'s utilities-layer comment to
acknowledge the 3 populating files; update `scan.py:109`'s docstring from
"118" to "104"; update `.browserslistrc`'s stale comment now that
`stylelint-no-unsupported-browser-features` consumes it.

#### 33. [other, low risk] roma/clear literal hex for the shared brand accent color bypasses --color-accent tokens (known, already-scoped-out gap)

**Files:** `roma/theme.css`, `clear/theme.css`

~61 literal `#ffa646`/`#FFA646`/`#ffa744` occurrences across both files
could reference `tokens.css`'s `--color-accent`/`--color-accent-light`.
`docs/PLAN.md`'s P52-H closeout already deferred a repo-wide
raw-hex-outside-tokens.css ban as its own future campaign (2492 such
declarations exist today) — this is informational, not a missed step.

**Suggested action:** When the already-planned repo-wide raw-hex campaign
is picked up, start with these accent-orange instances since `tokens.css`
already names this exact value as shared; no standalone action needed
before then.

#### 34. [other, low risk] Add stylelint-declaration-strict-value to enforce the token system going forward

**Files:** `.stylelintrc.json`, `package.json`

The project's `tokens.css` design-token system shows tokens are meant to
be the single source of truth for color/z-index properties, but nothing
currently enforces that a future edit can't reintroduce a raw hex color or
magic z-index outside that system —
`csstools/value-no-unknown-custom-properties` only checks that referenced
custom properties exist, not that literals are avoided.

**Suggested action:** Install `stylelint-declaration-strict-value` and
configure `scale-unlimited/declaration-strict-value` for
color/border-color/background-color/z-index to require `var(--token)` or
keyword values, preventing future raw-literal regressions.

#### 35. [other, low risk] Evaluate stylelint-plugin-use-baseline as a complementary browser-support check

**Files:** `.stylelintrc.json`, `.browserslistrc`, `package.json`

`.browserslistrc`'s P52 floor bump was justified by specific named
features (`light-dark()`, `@property`, relative color syntax, Popover
API), which a Baseline-dataset-based plugin may track more precisely than
the currently-installed caniuse/browserslist-based
`stylelint-no-unsupported-browser-features`. This has not been considered
or rejected anywhere in the project's history.

**Suggested action:** Evaluate adding `stylelint-plugin-use-baseline`
alongside (or in place of) `stylelint-no-unsupported-browser-features` to
check the specific modern features that motivated the browserslist floor
bump against Baseline data.

---

## Notable self-correcting findings (methodology lessons, not just bugs)

Two findings are worth flagging on their own because they caught the
**audit's own prior session's mistakes**, not just codebase cruft:

- **jquery-ui.css's "isRTL never true" claim is false** (punch-list #2) —
  this session's own earlier RTL-correction work asserted this and was
  wrong; the real gap (an unconverted `dl dd` margin) produces a genuine
  RTL layout bug for real reachable call sites.
- **colorbox.css's "never creates #cboxMiddleLeft/#cboxBottomLeft" claim
  is false** (punch-list #27) — also this session's own conclusion,
  wrong because `colorbox.ts` builds the id via string concatenation
  (`tag('MiddleLeft')`), which a literal grep can't find. The rule removal
  is still safe, just not for the stated reason. **This is a generalizable
  blind spot**: any "confirmed dead via grep" claim in this campaign
  should be re-checked wherever an id/class is built via string
  concatenation or a template literal rather than a literal in source
  (also flagged in `jcrop.ts`'s `ord-${ord}` and `jgrowl.ts`'s
  `${p.theme}`).

---

*Generated from workflow run `wf_0e6f7133-52e` (task w3kf0ncn4). Untracked
scratch file — not committed, safe to delete once reviewed/acted on.*
