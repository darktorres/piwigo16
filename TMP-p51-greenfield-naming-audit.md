# P51 (TS) greenfield naming/structure audit — temp working notes

Analysis-only workflow run (no files modified). 7 parallel survey
dimensions specifically hunting for jQuery-era naming/structure residue,
judged against "would a senior engineer with zero jQuery history have
named/organized this the same way today?" 78 raw findings (heavy overlap
across dimensions — e.g. colorbox alone had 4+ near-duplicate write-ups),
merged by the synthesis stage into 17 real, non-duplicate punch-list
items.

Overall auditor summary: *"Genuine jQuery-era naming/structure residue is
real but narrow and fully mapped: it clusters in exactly two places — the
`themes/default/js/vendor/{widgets,utils}` directory taxonomy plus ~10
individual widget files still named after the specific jQuery plugin they
replaced (jconfirm, jcrop, jgrowl, jqtree, cluetip, tiptip, piecon,
colorbox, selectize, dataTable) — and a second, unrelated cluster of
leftover `pwg_` global-namespace-avoidance prefixes on now-private or
plain-ES-exported functions, with `pageData.ts`'s
`pwg_getPageData`/`pwg_getPageString` being the widest single case. None
of it is functional jQuery; every file is already fully-ported,
first-party TypeScript, so every fix here is a pure rename/move with zero
behavior change and no external-compat cost (v17 already broke
third-party PEM compatibility on purpose). Disruption is real but
bounded... all fully enumerable and independently verifiable via
tsc/vite build with no design ambiguity."*

## Prioritized punch list (17 items)

### 1. [move, medium-high risk] Drop the misleading "vendor/" wrapper in themes/default/js/ (widgets/, utils/, tests/Unit/Vendor/)

**Files:** `themes/default/js/vendor/widgets/*.ts` (16 files), `vendor/utils/*.ts` (8 files), `tests/Unit/Vendor/*.ts` (10 files), `eslint.config.ts:271`

**The single most-repeated finding in the audit** — merges 7 near-duplicate
write-ups. Every file under `vendor/widgets` and `vendor/utils` is
confirmed 100% first-party, hand-written TS — every header says "Native
port of.../Port of...", none is actual vendored third-party code. The
name misleads readers (and collides with the real, genuinely-vendored
root Composer `vendor/` and `themes/default/vendor/fontello/`).
`tests/Unit/Vendor/` mirrors the same stale taxonomy but only ever
imports `vendor/utils/*` (ajax, dom) — never `vendor/widgets/*`.

**Suggested action:** `git mv themes/default/js/vendor/{widgets,utils}`
to `themes/default/js/{widgets,utils}` (drop the `vendor/` segment);
`git mv tests/Unit/Vendor/` to `tests/Unit/Utils/`. Real reference count:
~99 distinct `.ts` files / ~178+ import-statement occurrences (widgets:
45 files/90 occurrences; utils: 84 files/126 occurrences, overlapping
sets), plus `eslint.config.ts:271`'s hardcoded lint-message string and ~8
CSS header-comment mentions. **Execute in the SAME `git mv` pass as items
2-9 below** (e.g. `git mv .../jconfirm.ts .../confirmDialog.ts`
directly) rather than moving the directory first and renaming files
second, so each consumer's import line is edited exactly once.

### 2. [rename, medium risk] jconfirm.ts / jconfirmPresets.ts / pwg_jconfirm_follow_href / jconfirm.css still branded after jquery-confirm

**Files:** `vendor/widgets/jconfirm.ts`, `admin/default/js/jconfirmPresets.ts`, `css/components/jconfirm.css`, `theme-base.css`, `roma/theme.css`, 3 Browser tests

Merges 6 duplicate findings. `jconfirm.ts` already exports plain
`confirm()`/`alert()` but keeps `JConfirmInstance`/`JConfirmOptions` type
names and the file/CSS/preset filenames from the ported jquery-confirm
plugin.

**Suggested action:** Rename `vendor/widgets/jconfirm.ts` →
`confirmDialog.ts` (`JConfirmInstance`→`ConfirmDialogInstance`,
`JConfirmOptions`→`ConfirmDialogOptions`); update its 14 real `.ts`
importers. Rename `jconfirmPresets.ts` → `confirmDialogPresets.ts` and
its `pwg_jconfirm_follow_href` → `confirmFollowHref`, updating its 20
real importers (14 above + 6 more that only use `follow_href`). Rename
`jconfirm.css` → `confirm-dialog.css` and its ~70 `.jconfirm*`/`.jc-bs3-*`
selectors, plus ~24 occurrences in `theme-base.css`/`roma/theme.css`.
**Update the 3 real Browser tests asserting `.jconfirm` selectors
(CatModifyInteractionTest.php, CommentsInteractionTest.php,
PluginsIncompatiblePanelTest.php) in the same commit** — the CSS rename
otherwise silently breaks them.

### 3. [rename, medium risk] colorbox.ts / colorbox.css / ColorboxView.php branded after the Colorbox lightbox plugin

**Files:** `vendor/widgets/colorbox.ts`, `css/components/colorbox.css`, `ColorboxView.php`, `images/colorbox/`

Merges 4 duplicate findings. **Widest PHP-side coupling of any widget
rename**: `colorbox()` renders a generic lightbox/photo/iframe overlay
but is named for the specific "jackmoore/colorbox" plugin.

**Suggested action:** Rename `colorbox.ts` → `lightbox.ts`
(`colorbox()`→`openLightbox()`, `closeColorbox()`→`closeLightbox()`);
update its 8 real `.ts` call sites. Rename `colorbox.css` → `lightbox.css`
and its ~65 `.cbox*`/`.colorbox` selector lines across 5 theme CSS files.
Rename `ColorboxView.php` → `LightboxView.php` and update its **16 real
PHP callers** (the widest cross-language footprint in this list). Move
`images/colorbox/` (controls.png, loading.gif) alongside. Do as one
coordinated commit given the CSS/PHP/asset coupling.

### 4. [rename, low risk] jcrop.ts / jcrop.css branded after the discontinued Jcrop plugin

**Files:** `vendor/widgets/jcrop.ts`, `css/components/jcrop.css`, `pictureCoi.ts`, `picture_coi.latte`

Merges 6 duplicate findings. Single real consumer (a center-of-interest
image cropper); one of the lowest-risk renames in the set.

**Suggested action:** Rename `jcrop.ts` → `imageCropper.ts`
(`jcrop()`→`cropImage()`, `JcropOptions`→`ImageCropperOptions`,
`JcropApi`→`ImageCropperApi`); update sole caller `pictureCoi.ts`. Rename
`jcrop.css` → `image-cropper.css` and its ~199 `.jcrop-*` selectors;
update `id="jcrop"` in `picture_coi.latte`; update
`PictureCoiView.php`'s asset registration; update
`stylelint-suppressions.json:43`'s path-keyed entry; regenerate the
golden-HTML fixture's `<link>` href; update 2 Browser tests
(`jcropPointerDragScript()`/`jcropCoordsFloat()` helper names,
`.jcrop-holder` selector); move `images/jcrop/` (Jcrop.gif); sweep
comment cross-refs in `sortable.ts`, `jqtree.css`, `jquery-ui.css`.

### 5. [rename, medium risk] jqtree.ts / jqtree.css branded after the jqTree plugin, plus its emitted jqtree- class family

**Files:** `vendor/widgets/jqtree.ts`, `css/components/jqtree.css`, `albums.ts`, `theme-base.css`, `roma/theme.css`, `pages/albums.css`, `AlbumTreeTest.php`

Merges 6 duplicate findings. `tree()`/`getTreeInstance()` are already
function-named; only the 4 exported types
(`JqTreeNode`/`JqTreeMoveInfo`/`JqTreeOptions`/`JqTreeInstance`) and the
~11 hardcoded `jqtree-*` DOM class strings the widget itself emits still
carry the brand.

**Suggested action:** Rename `jqtree.ts` → `tree.ts`; rename exported
types `JqTreeNode`/`JqTreeMoveInfo`/`JqTreeOptions`/`JqTreeInstance` →
`TreeNode`/`TreeMoveInfo`/`TreeOptions`/`TreeInstance` — **first rename
the file's existing internal, non-exported `class TreeNode` (line 171) to
`TreeNodeImpl`** to avoid a name collision with the newly-renamed public
`TreeNode` type. Update sole real consumer `albums.ts` (~20
type-reference sites). Rename the ~11 emitted `jqtree-*` class strings to
a `tree-*` prefix and update their ~55 matching CSS occurrences; **update
`tests/Browser/AlbumTreeTest.php`'s live selector assertions in the same
commit**. Sweep comment cross-refs in `jcrop.ts`, `dom.ts`.

### 6. [rename, medium risk] jgrowl.ts / jgrowl.css branded after the jGrowl plugin

**Files:** `vendor/widgets/jgrowl.ts`, `css/components/jgrowl.css`, `updates/ext.ts`, `theme-base.css`, `UpdatesExtInteractionTest.php`, `RomaVisualRegressionTest.php`

Merges 7 duplicate findings. Single real consumer (a queued toast
notification), but its `#jGrowl`/`.jGrowl-*` DOM/CSS class family is
asserted on directly by 18 real Browser-test selectors.

**Suggested action:** Rename `jgrowl.ts` → `toast.ts`
(`jGrowl()`→`showToast()`, `JGrowlOptions`→`ToastOptions`); update sole
real consumer `updates/ext.ts` (import + 2 calls + 5 hardcoded selector
refs). Rename `jgrowl.css` → `toast.css` and update its selectors plus
the ~60-line `.jGrowl-*` override block in `theme-base.css`. Update
`UpdatesExtView.php`'s asset path. **Update the 18 real selector
assertions in `UpdatesExtInteractionTest.php` in the SAME commit** —
these are live Playwright DOM assertions, not comments. Update
`RomaVisualRegressionTest.php`'s snapshot filename (cosmetic). **Do NOT
fold in the separate toaster.ts merge question here** — see item 17.

### 7. [rename, medium risk] cluetip.ts and tiptip.ts distinguish two real tooltip variants only by which jQuery plugin each replaced

**Files:** `vendor/widgets/cluetip.ts`, `tiptip.ts`, `tooltip.ts` (leave alone), `install.ts`, `languages/new.ts`, `install.latte`, `languages_new.latte`

Merges 6 duplicate findings (5 about the pair + all "tooltip trio"
findings). `tooltip.ts` (1 consumer, `ratings/user.ts`) is already
correctly, genuinely named and **stays untouched** — it's a separate,
real jQuery-UI-tooltip port, not a duplicate of the other two.

**Suggested action:** Rename `cluetip.ts` → `splitTooltip.ts`
(`cluetip()`→`showSplitTooltip()`); update its exactly 2 real call sites
and the `.cluetip`/`.cluetip-outer`/etc. selectors in 2 templates + 3
theme CSS files. Rename `tiptip.ts` → `pointerTooltip.ts`
(`tipTip()`→`showPointerTooltip()`); update its exactly 10 real call
sites including 5 `.tiptip` querySelectorAll sites, `intro.ts`'s
hardcoded template-literal string, and the `.tiptip`/`.tiptip-with-img`
markup across ~20 admin `.latte` templates (leave
`search_filters.inc.latte`'s `.tiptip` markup as dead-code cleanup —
already confirmed unwired). Do both together in one commit; leave
`tooltip.ts` alone.

### 8. [rename, low risk] piecon.ts branded after the discontinued Piecon plugin

**Files:** `vendor/widgets/piecon.ts`, `photosAddDirect.ts`, `build/ambient-globals.d.ts`

Merges 6 duplicate findings. Single real consumer; already-generic
exports (`setProgress`/`reset`). Includes a bonus dead-code removal the
findings independently surfaced.

**Suggested action:** Rename `piecon.ts` → `faviconProgress.ts`; update
sole real consumer `photosAddDirect.ts` (import alias, 2 call sites, 2
comment mentions). **Delete the dead `declare module "piecon" {...}`
ambient-type block in `build/ambient-globals.d.ts:57-70` in the same
pass** — confirmed orphaned, nothing imports the bare `"piecon"`
specifier. Sweep 3 comment-only mentions in
`PhotosAddDirectInteractionTest.php`.

### 9. [rename, low risk] datepicker.ts's exported pwgDatepicker/PwgDatepickerOptions carry the dead pwg_ prefix (file itself is already correctly named)

**Files:** `vendor/widgets/datepicker.ts`, `pictureModify.ts`, `history.ts`, `batch_manager/global.ts`, `batch_manager/unit.ts`

The sole `vendor/widgets` file with this specific residue — every sibling
widget already uses the unprefixed convention, making `datepicker.ts` a
genuine outlier worth fixing alongside the other widget renames even
though it needs no file move.

**Suggested action:** Rename exported `pwgDatepicker`→`datepicker` and
`PwgDatepickerOptions`→`DatepickerOptions`; update the 4 real call-site
files, each with exactly 1 import + 1 call; sweep 4 comment-only
mentions.

### 10. [rename, low risk] Seven module-private pwg_-prefixed functions have zero remaining namespace-collision purpose

**Files:** `scripts.ts`, `standard_pages.ts`, `menubarLinks.ts`, `menubarQuicksearch.ts`, `thumbnailsLoader.ts`

Merges 8 duplicate findings covering the same 7 functions across 5 files.
Each is confirmed non-exported, never window-attached, and referenced
only within its own defining file — the `pwg_` prefix is pure
pre-ES-module residue. **Explicitly excludes `pwg_tryFocus`
(scripts.ts)**, a genuine, documented exception still needed for 3 real
inline `<script>` PHP call sites (RegisterView.php, IdentificationView.php,
PasswordView.php) with no import mechanism available.

**Suggested action:** Drop the `pwg_` prefix on all 7, each a
single-file, zero-cross-file-reference rename safe to batch in one
commit: `pwg_checkPasswordMatch`→`checkPasswordMatch`,
`pwg_checkEmailFormat`→`checkEmailFormat`,
`pwg_checkPasswordMatchStdPages`→`checkPasswordMatchStdPages`,
`pwg_checkEmailFormatStdPages`→`checkEmailFormatStdPages`,
`pwg_initMenubarLinks`→`initMenubarLinks`,
`pwg_initQuickSearch`→`initQuickSearch`,
`pwg_ajax_thumbnails_loader`→`ajaxThumbnailsLoader`.

### 11. [rename, medium risk] pwg_getPageData/pwg_getPageString (plus internal pageData.ts helpers) retain the dead pwg_ prefix on the codebase's most-used utility pair

**Files:** `pageData.ts`, `admin.ts`, `albums.ts`, `profile.ts`, `standard_pages.ts`, `scripts.ts`, `build/ambient-globals.d.ts`

Merges 6+ duplicate findings — **the widest single-symbol-family rename
in the audit**. Confirmed pure ES-module exports (no window-global, no
`.latte` coupling); the one documented objection to renaming (a prior
decision to mirror the PHP backend's own `pwg_*` convention) is now moot
since grep confirms zero `function pwg_*` remain anywhere in the current
PHP backend (`Env.php` already migrated `pwg_now()`/
`pwg_test_mode_header()` to `Env::now()`/`Env::testModeHeader()`).

**Suggested action:** Rename `pwg_getPageData`→`getPageData` and
`pwg_getPageString`→`getPageString` in `pageData.ts`, plus its 4
internal-only identifiers. Update all real importer `.ts` files — ~51-55
files across all 3 chains; every reference is a plain import + call,
verifiable by `tsc`/`vite build` with no template/CSS/test impact.
**Land this AFTER item 1** (the vendor/ directory move) since many of the
same files' import blocks are touched by both — sequencing it second
avoids re-touching the same import lines in two unrelated diffs.

### 12. [remove, low risk] Dead, literal jQuery plugin file still checked into themes/default/js/plugins/

**Files:** `themes/default/js/plugins/jquery.progressbar.min.js`, `eslint.config.ts`, 2 test files, `Template.php`, `BatchManagerGlobalView.php`

Merges 3 duplicate findings. Real, unmodified 2009 jQuery plugin source,
confirmed dead — no `AssetContribution` loads it (the real upload-progress
UI is plain-CSS-driven). Its sole occupancy of a directory named
"plugins/" also creates a false-friend collision with the real, active
`admin/default/js/plugins/` (PEM-plugin-management pages).

**Suggested action:** Delete the file and the now-empty
`themes/default/js/plugins/` directory. Swap the 2 real remaining test
references for a synthetic fixture path (`TemplateInstanceTest.php`
already demonstrates this pattern with a fabricated `jquery.legacy.js`).
Update `eslint.config.ts`'s ignore-comment and doc-comment mentions.

### 13. [rename, medium risk] jquery-ui.css umbrella name no longer matches its narrowed, first-party, 3-widget scope

**Files:** `css/components/jquery-ui.css`, 7 PHP View classes

The stylesheet's own header already documents it as a heavily pruned,
first-party rewrite serving only `datepicker.ts`, `slider.ts`, and
`cluetip.ts`/`splitTooltip.ts` (item 7) — keeping the literal upstream
"jquery-ui.css" name misleads readers into thinking it's vendored CSS.

**Suggested action:** Rename to a name describing its real narrow scope
(e.g. `legacy-widget-theme.css` or `datepicker-slider-tooltip.css`);
update the 7 PHP View classes' `AssetContribution::css(...)`
registrations. File-rename only — leave the internal `.ui-*` DOM class
family as a separate, larger follow-on (touches live VR/snapshot tests).
Sequence AFTER item 7.

### 14. [rename, low risk] Widespread str-prefixed Hungarian-notation variable naming is stale now that TS carries real types

**Files:** 24 files across all 3 chains

192 confirmed `const/let str*` declarations across 24 files, 186 of them
file-private and 6 cross-file exports needing coordinated updates. Bonus:
2 of the 192 (`introTooltips.ts`'s `strChartPos`/`strChartHeight`) hold
**numeric** values, not strings — the prefix is actively misleading
there, not just redundant.

**Suggested action:** Drop the `str` prefix from all 192 declarations.
Coordinate the 6 cross-file exports specifically:
`strAlbumsFound`/`strAlbumFound`/`strResultLimit` (album_selector.ts → 5
use sites), `strGb`/`strMb` (intro.ts → 4 use sites), `strRestoreDef`
(plugins/installedConfig.ts → 2 use sites). Rename `introTooltips.ts`'s
numeric `strChartPos`/`strChartHeight` to
`chartTopOffset`/`chartHeightPx` instead of a string-flavored name.

### 15. [rename, high risk] selectize.ts branded after the Selectize.js plugin, with the largest CSS/test surface of any widget

**Files:** `vendor/widgets/selectize.ts`, 3 selectize CSS files, 3+ templates, ~13 Browser tests

Confirmed real, but "selectize" has some generic-term currency, and its
footprint (~368 raw occurrences across 25 CSS/latte files, ~13 Browser
tests, several golden-HTML fixtures) is **by far the largest** of the
widget group — do this after the unambiguous, cheaper renames above.

**Suggested action — two phases:** Phase 1 (do first): rename
`selectize.ts` → `tagSelect.ts` (`selectize()`→`createTagSelect()`,
`getSelectizeInstance()`→`getTagSelectInstance()`); update the 14 real
`.ts` consumers (9 direct + 5 indirect via `LocalStorageCache.ts`'s
`AbstractSelectizer` wrapper). Phase 2 (separate follow-on commit):
rename the `data-selectize` attribute and `.selectize-*` CSS class family
across 3 CSS files (461 combined lines) and 7 `.latte` templates,
updating ~13 Browser tests with hardcoded selectors and golden-HTML
fixtures in lockstep.

### 16. [rename, low-consumer/medium-effort] dataTable.ts is a weaker, borderline rename candidate — "DataTable" is also a generic term

**Files:** `vendor/widgets/dataTable.ts`, `ratings/user.ts`, `rating_user.css`, `images/datatables/`

**Lowest-confidence rename in the set** — "DataTable" names both the
DataTables.net plugin and a generic UI abstraction. The widget's real
branding residue lives in the DOM classes it emits at runtime
(`dataTables_wrapper`, `sorting_asc`, etc.), not just the filename, so a
file-only rename is incomplete on its own.

**Suggested action:** Treat as optional/lowest priority; skip if
time-constrained. If pursued: rename `dataTable.ts` → `table.ts` and its
hardcoded emitted class strings; update sole consumer; update matching
selectors in 4 CSS files and rename the `images/datatables/` asset
directory. Only worth doing as part of a broader CSS cleanup pass, not
standalone.

### 17. [other — flag only, do not execute] jgrowl/toast.ts (item 6) and standard_pages/toaster.ts are two independently-built notification widgets that may or may not warrant a future merge

**Files:** `vendor/widgets/jgrowl.ts`, `standard_pages/js/toaster.ts`, `profile.ts`

This is a genuine **open architecture question, not a naming residue
fix**: `jgrowl.ts`/`toast.ts` has a real 250ms-tick pending-queue and
shared hover-pause mechanism; `toaster.ts` (`pwgToaster`) is a simpler
template-clone+fadeOut with no queue/pause. `toaster.ts`'s 7 real call
sites are all single discrete-action fires, so whether the queue/pause
behavior is even relevant to standard_pages is unsettled.

**Suggested action:** Do not fold into item 6's rename. After item 6
lands, separately evaluate (as a design discussion, not a mechanical
rename) whether `toaster.ts` should be replaced by `toast.ts` or kept as
a deliberately simpler, distinct mechanism. **Risk if forced without
resolving the behavior-parity question first: profile.ts's 7 call sites
could gain unwanted queuing/pausing behavior they don't have today.**

---

*Generated from workflow run `wf_8d21bdca-fe9` (task w3us5i1a6). Untracked
scratch file — not committed, safe to delete once reviewed/acted on.*
