# P52 (CSS) greenfield naming/structure audit — temp working notes

Analysis-only workflow run (no files modified). 7 parallel survey
dimensions specifically hunting for jQuery-era naming/structure residue in
CSS, judged against "would a senior engineer with zero jQuery history have
named/organized this the same way today?" Findings merged by the
synthesis stage into 17 real, non-duplicate punch-list items.

**Correction (2026-09-10, see the "CORRECTED" section before item 15
below):** the original synthesis produced 3 "keep as-is, not worth
renaming" decisions, 2 of which were reasoned around churn/effort ("does
it clear the cost/benefit bar") rather than any real external contract or
technical constraint — that contradicts this project's own standing
policy that blast radius, including real test-fixture updates, is never
itself a reason to skip a properness fix. Items 15/16 below are rewritten
as real, executable rename items; only item 17 (standard_pages `skins/`
vs admin `roma`/`clear` `theme.json`, genuinely different mechanisms, not
naming drift) remains a legitimate "no action" finding.

Overall auditor summary (superseded in part by the correction above):
*"Genuine jQuery-era naming residue in the CSS is real but narrow and
mostly cheap to clear: roughly 9 vendor-branded CSS/TS file pairs
(colorbox, jconfirm, jcrop, jgrowl, jqtree, plupload, jquery-ui,
selectize×3) plus a dozen internal 'jquery.*' PHP asset-id strings,
totaling well under 200 concrete reference points... The larger and
riskier residue is structural rather than vendor-brand: a live PascalCase
'*PopIn*' dialog family (item 11) and, above all, ~83 Latte template
files carrying ~360-440 camelCase/PascalCase class tokens across both
admin and public themes (item 14) — this is a genuine phased-campaign-
scale cleanup, not a mechanical rename, and is compounded by a
currently-disabled stylelint naming-pattern rule that lets new violations
in unchecked."* (The original summary's closing line about "judgment
calls about churn vs. payoff" applied its own flawed framing to items
15/16 and should be disregarded — see the correction.)

## Prioritized punch list (17 items)

### Quick, low-risk vendor-brand file renames (do these first)

#### 1. [rename, low risk] jcrop.css/jcrop.ts → coi-cropper.css/coiCropper.ts — smallest, cleanest rename in the set

**Files:** `jcrop.css`, `jcrop.ts`, `pictureCoi.ts`, `picture_coi.latte`, `PictureCoiView.php`, `stylelint-suppressions.json`, 1 golden-HTML fixture

`coi-cropper.css` matches the already-established "Coi" (center-of-interest)
vocabulary in `PictureCoiView.php`/`pictureCoi.ts`/`picture_coi.latte`.
Full corrected blast radius is only 6 files, including a
`stylelint-suppressions.json` key (a 21-count suppression that silently
orphans on a bare rename if missed) and 2 `#jcrop` querySelector calls in
`pictureCoi.ts` the original finding's file list omitted.

**Suggested action:** Rename both files in one commit; update
`PictureCoiView.php:53`, `stylelint-suppressions.json:43`,
`pictureCoi.ts:3/27/52`, `picture_coi.latte:50`'s `id="jcrop"`, and the
golden-HTML fixture href. Leave `.jcrop-*` DOM classes untouched
(vendor-mirrored).

#### 2. [rename, low risk] plupload.css → upload-queue.css — the .ts half was already renamed; CSS is the sole holdout

**Files:** `plupload.css`, `PhotosAddDirectView.php`, 1 golden-HTML fixture, `docs/PLAN.md`

`uploadQueue.ts` already carries a functional name — no `.ts` rename
needed here, unlike every other item in this bucket. Blast radius: 1 PHP
call site plus a golden-HTML fixture and a PLAN.md prose mention.

**Suggested action:** Rename `plupload.css` → `upload-queue.css`; update
`PhotosAddDirectView.php:95`, the fixture href, and the PLAN.md mention.
Optionally rename the `images/plupload/` sprite directory (not required).
Leave the `.plupload_*` selector overrides as a separate, larger,
not-yet-scoped follow-on.

#### 3. [rename, low risk] jqtree.css/jqtree.ts → album-tree.css/albumTree.ts

**Files:** `jqtree.css`, `jqtree.ts`, `albums.ts`, `AlbumsView.php`, 1 golden-HTML fixture

The real load-bearing reference is `albums.ts:23`'s import (the file's
only real consumer), not just the asset registration earlier passes
focused on.

**Suggested action:** Rename both files together; update
`AlbumsView.php:46`, `albums.ts:23`, and the golden-HTML fixture. Sweep
~8 comment-only mentions for accuracy. Leave `.jqtree-*` classes
untouched (live-asserted by `AlbumTreeTest.php`).

#### 4. [rename, medium risk] jgrowl.css/jgrowl.ts → toast.css/toast.ts, plus unrelated cosmetic "jGrowl" test-title residue

**Files:** `jgrowl.css`, `jgrowl.ts`, `UpdatesExtView.php`, `RomaVisualRegressionTest.php`, its Pest snapshot, `CommentsInteractionTest.php`

Bundles two mechanically-independent fixes into one pass. The file rename
has exactly 1 real call site; `.jGrowl*` DOM classes stay untouched
(asserted 15x in `UpdatesExtInteractionTest.php`). Separately, two test
titles mention "jGrowl" purely descriptively (confirmed: the real
assertion targets are unrelated selectors) — zero-risk rewording.

**Suggested action:** Rename `jgrowl.css`/`jgrowl.ts` → `toast.css`/
`toast.ts` (1 call site: `UpdatesExtView.php:47`). In the same commit,
reword the two test titles to drop "jGrowl", letting the Pest snapshot
filename regenerate.

#### 5. [rename, medium risk] colorbox.css/colorbox.ts/ColorboxView.php → lightbox.css/lightbox.ts/LightboxView.php

**Files:** `colorbox.css`, `colorbox.ts`, `ColorboxView.php`, `colorbox.inc.latte`, `stylelint-suppressions.json`, `scan.py`, ~27 golden-HTML fixtures, 14 View.php callers

Corrects an earlier "17 references" claim to the verified **23** (14
`new ColorboxView()` call sites, 3 `use` import statements, 4 comment
mentions, 2 self-references inside the file itself). Confirmed unaffected:
4 Browser test files touch only internal `#cbox*`/`.cboxElement` DOM ids,
which stay as-is.

**Suggested action:** Rename all 3 (CSS/TS/PHP class+file) in one commit;
update all 23 real `ColorboxView` references across 14 View.php files,
`colorbox.inc.latte:1`, `stylelint-suppressions.json:38`, `scan.py:260`,
and regenerate 27 golden-HTML fixtures. Leave `.cbox*`/`#cbox*` DOM ids
untouched.

#### 6. [rename, low risk] Retire the "jquery.*" internal AssetContribution dedup-key namespace (32 call sites, 15 files)

**Files:** `AssetContribution.php`, `PageAssets.php`, 14 View.php files

This exact finding was independently re-submitted 5 times with
self-correcting tallies; final verified count is **~32 real `id:
'jquery.*'` call sites across 15 PHP files**. Confirmed the id is a pure
`PageAssets::$css[$id]` dedup key — never rendered to HTML, never read by
any `.ts`/`.latte`/test file. The jQuery-naming-convention resolver that
once gave these strings special meaning was removed in P49-C. **Zero
functional/test risk.**

**Suggested action:** Replace `id: 'jquery.ui'` (7 sites) → `'date-slider'`,
`id: 'jquery.selectize'` (12 sites) → `'tag-select'`, `id:
'jquery.selectize.scheme'` (12 sites) → `'tag-select.scheme'`, and `id:
'jquery.colorbox'` (1 site) → `'lightbox'` — mirroring items 5/7/9's CSS
renames. Pure PHP string find/replace.

#### 7. [rename, medium risk] jquery-ui.css → date-slider.css (file rename only; keep .ui-* selectors)

**Files:** `jquery-ui.css`, `datepicker.ts`, `slider.ts`, 7 View.php files, `scan.py`, `known-findings.json`, 9 golden-HTML fixtures

Real content is 2 widgets sharing a jQuery-UI "core chrome" block:
datepicker+timepicker-addon and range slider (cluetip.ts's classes in
this file are confirmed dead — comment-only). `scan.py:257` and
`known-findings.json` hardcode the literal path and desync silently if
missed.

**Suggested action:** Rename to `date-slider.css`; update 7 call sites,
`scan.py:257`, `known-findings.json`'s suppression keys, and 9 golden-HTML
fixtures. **Do NOT rename the 115 internal `.ui-*` selectors** (see
decision item 15). Optional separate follow-on: split into
`datepicker.css` + `slider.css` since the front-end search page currently
loads the whole bundle (~120 unused datepicker/timepicker lines) just for
the slider.

#### 8. [rename, medium risk] jconfirm.css/jconfirm.ts → confirm-dialog.css/confirmDialog.ts — widest single-file registration blast radius

**Files:** `jconfirm.css`, `jconfirm.ts`, `jconfirmPresets.ts`, 20 View.php files, ~26 golden-HTML fixtures

Registered from exactly **20 View.php files**, each a single
`AssetContribution::css()` call with no custom id — the largest count of
registration call sites of any item in the set, but entirely mechanical
(no selector coupling in the rename itself).

**Suggested action:** Rename all 3 files; update all 20 call sites and
regenerate ~26 golden-HTML fixtures plus their bundler-hash hrefs once
`jconfirmPresets.ts` is renamed too. Leave the 23 `.jconfirm-*` DOM
classes and 8 Browser test files asserting them untouched.

#### 9. [rename, high risk] selectize.css/-clear/-dark/selectize.ts → tag-select.css/-clear/-dark/tagSelect.ts — largest total surface, do last as its own PR

**Files:** 3 selectize CSS files, `selectize.ts`, 12 View.php files (24 registrations), 7 templates, 7 `.ts` page controllers, `cat_perm.css`, 5 Browser tests

**Largest surface in the audit**: 12 Views × 2 registrations = 24
`AssetContribution::css()` sites, the `data-selectize="categories"/
"tags"/"groups"/"users"` bootstrap attribute (14 live occurrences across
7 `.latte` files, read by 7 separate `.ts` page controllers — not by
`selectize.ts` itself, a correction from an earlier pass), plus a live
attribute selector in `cat_perm.css`. 5 Browser tests assert
`.selectize-input`/`.selectize-control`/`.selectize-dropdown` directly.

**Suggested action:** Rename the 3 CSS files and `selectize.ts`; update 24
registration call sites (pair with item 6), the `data-selectize`
attribute across 7 `.latte` + 7 `.ts` files, and `cat_perm.css`'s
attribute selector. Leave `.selectize-*` classes untouched. **Execute as
its own dedicated PR, last among the vendor-file renames.**

### Structural moves and larger campaigns

#### 10. [move, medium risk] Split admin/default/css/components/ into components/ (first-party) vs components/vendor/ (ported-widget)

**Files:** `general.css`, `add_album.css`, `album_selector.css`, `batch_manager_filter.css`, `scan.py`

The directory currently mixes 10 vendor-ported widget stylesheets (items
1-9) with first-party shared components with no structural signal
distinguishing them. Corrected blast radius: **30 PHP files** reference
this directory path, **~59 golden-HTML fixtures** embed hrefs into it,
and 4 vendor files contain relative `url("../../images/...")` references
needing a directory-depth adjustment on the move.

**Suggested action:** **After items 1-9 land** (so paths are touched only
once), move the 10 renamed vendor CSS files into a new
`components/vendor/` subdirectory. Update ~30 PHP call sites, ~59
golden-HTML hrefs, and adjust the 4 files' relative image `url()` paths.
Batch as one move-only commit.

#### 11. [rename, medium risk] PascalCase "*PopIn*" dialog family (19 tokens) → kebab-case, plus consolidate 3 incompatible close-button names into 1

**Files:** 8 templates, 6 `.ts` files, 4 CSS files, 3 search CSS files, 2 Browser tests, 5 golden-HTML fixtures

Merges 3 overlapping findings. All 19 distinct tokens
(`AddAlbumPopIn`/`-Container`, `DeleteAlbumPopIn`, `UserListPopIn`,
`GuestUserPopIn`, `EditUserPopIn`, `linkedAlbumPopIn`, `ClosePopIn`, etc.)
are confirmed live via the P52-J native-`<dialog>` campaign's own commit
history — that campaign converted the markup but never the PascalCase
naming. The same admin chain also spells its own "close this dialog"
affordance **3 incompatible ways** (`close-popin`, `ClosePopIn`,
`close-modal`) for the identical role.

**Suggested action:** Convert all 19 tokens to kebab-case
(`AddAlbumPopIn` → `add-album-popin`, etc.). Consolidate the 3
close-button spellings onto one term (recommend `close-popin`, the
dominant term). Regenerate the 5 named golden-HTML fixtures and update 2
Browser tests' hardcoded selectors. Verify the shared `.ClosePopIn` usage
in the unrelated front-end search dialog is swept in the same commit.

#### 12. [rename, low risk] Quick-win scoped camelCase/mixed-case fixes: "action*" family (20 tokens), mixed camelCase+kebab (9 tokens), 2 small first-party classes

**Files:** ~29 files across templates/TS/CSS

Merges 3 findings covering small, self-contained, low-risk token groups
distinct from the giant 83-template campaign below: 9 mixed-case tokens
(`addFilter-button`, `selectedAlbum-first`, `batchManager-pagination`,
etc.), the 20-token "action*" family (`actionButtons`, `actionEdit`,
`actionDelete`, etc. — notably `user_list.latte`'s `userActionDelete`/
`userActionLevel`, the **last 2 camelCase holdouts** in an otherwise
already-kebab-cased file), and 2 more isolated tokens. None are
vendor-coupled.

**Suggested action:** Batch into one cleanup PR: rename all 9 mixed-case
tokens to pure kebab-case; rename all 20 "action*" tokens (including 9
golden-HTML fixtures that embed them); rename
`categoryNameErrorMessage`/`errorFilter-message` to kebab-case.

#### 13. [other, low risk] Reword 5 bare "/* jQuery ... */" comments that mislabel live vanilla-TS sections; delete 1 dead block; defer 1

**Files:** `roma/theme.css`, `clear/theme.css`, `theme-base.css`, `themes/default/theme.css`, `docs/PLAN.md`

Of 7 bare `/* jQuery ... */` section-header comments, 5 are confirmed
live and genuinely mislabeled (sitting over `.ui-tooltip`/`.cluetip-*`/
`#tiptip_holder` rules actually driven by `tooltip.ts`/`cluetip.ts`/
`tiptip.ts` today). One (`/* jQuery datepicker */`, `theme.css:730`) is
**not** a mislabel case — it sits over a block already documented as
100% dead in `docs/PLAN.md:8238` and should be deleted, not relabeled.
Two more (`/* jQuery ui resizable */`) have zero live references but
aren't formally tracked as dead yet.

**Suggested action:** Reword the 5 confirmed-live comments to name the
real widget instead of "jQuery" (text-only). Delete the dead block at
`theme.css:730-731` per its existing PLAN.md disposition. Leave the 2
"jQuery ui resizable" comments for a separate dead-code-verification pass.

#### 14. [rename, high risk] Scope a dedicated phased campaign for ~83-template camelCase/PascalCase residue (~360-440 tokens) + close the stylelint enforcement gap

**Files:** 83 `.latte` files across admin AND public themes, 8 CSS files, `.stylelintrc.json`

Merges 4 restatements. Corrected scope: **83 `.latte` files — NOT
admin-only** as first reported (51 admin, 32 public/standard_pages, ~39%
of the footprint), **~360-440 distinct tokens**
(`buttonLike`/`bulkAction`/`categoryActions`/`contentWithMenu`/
`switchBox`/`togglePassword`/`titrePage`, the `month_calendar` "cal*"
family, `imageInfo`/`dError`, etc.). `.stylelintrc.json` currently
disables `selector-class-pattern`/`selector-id-pattern`/
`keyframes-name-pattern` (all `null`) with **no enforcement mechanism**
guarding against new violations. Too large/interdependent for a
mechanical punch-list entry — several tokens overlap with already-
flagged-dead selectors from the prior consolidation audit and need
re-verification before renaming.

**Suggested action:** Open a dedicated, phased kebab-case-conversion plan
(its own P-numbered campaign) covering the full footprint across both
themes. Re-verify each token against the prior consolidation audit's
dead-selector list before renaming to avoid wasted churn. On completion,
flip `.stylelintrc.json`'s 3 disabled naming-pattern rules to enforce
kebab-case going forward.

### CORRECTED: items 15/16 (originally "keep as-is, not worth renaming")

**Correction (2026-09-10):** the audit's original items 15/16 recommended
*not* renaming the `.ui-*`/`.jconfirm-*`/`.jcrop-*`/`.jGrowl-*`/
`.jqtree-*`/`.selectize-*`/`.cbox*` DOM class vocabularies, reasoned
entirely as "internal churn vs. cosmetic payoff — doesn't clear the bar."
That's wrong per this project's own standing policy: blast radius
(including real test-fixture updates) is never itself a reason to skip a
real properness fix — only a genuine external contract or technical
constraint would be (and none exists here; PEM compat is already broken
project-wide, and every one of these is a first-party, fully-owned DOM
vocabulary). The mistake was mine: the workflow's own instructions told
subagents to weigh a cost/benefit "bar" for these specifically, which is
what produced this conclusion. Corrected below.

#### 15. [rename, medium risk] Rename the jQuery-UI ".ui-*" selector convention (52 unique classes, 115 selector lines)

**Files:** `date-slider.css` (renamed in item 7), `datepicker.ts`, `slider.ts`, `DatepickerInteractionTest.php`, `RomaVisualRegressionTest.php`, `BatchManagerFilterInteractionTest.php`

The 52 unique `.ui-*` classes are hardcoded as literal className strings
in `datepicker.ts` (~29 sites) and `slider.ts` (~8 sites), and directly
asserted by 3 real test files. All of that is fully enumerable and
mechanical — a real, if larger, rename like every other item in this
report, not a reason to stop.

**Suggested action:** Pick a real functional prefix (e.g. `date-slider-*`
or split per-widget: `datepicker-*`/`slider-*`) and rename all 52 classes
across `datepicker.ts`, `slider.ts`, and `date-slider.css` together;
update the 3 test files' hardcoded selectors and any VR snapshot in the
same commit. Sequence after item 7 (the file rename) so the class rename
lands against the file's final name.

#### 16. [rename, high risk] Rename the other 5 vendor-mirrored DOM class vocabularies (.jconfirm-*, .jcrop-*, .jGrowl-*, .jqtree-*, .selectize-*, .cbox*)

**Files:** the 5 renamed widgets' `.ts`/`.css` pairs, 8 Browser test files (jconfirm), `UpdatesExtInteractionTest.php` (jgrowl), 5 Browser test files (selectize), plus golden-HTML fixtures

Same real, mechanical rename class as item 15, just larger: each `.ts`
port hardcodes its own DOM class literals matching the original plugin's
convention, and several are directly asserted by Browser tests (jconfirm:
8 test files; jgrowl: 15 occurrences + a classList assertion; selectize:
5 test files) and golden-HTML fixtures. This is real churn, and it's
exactly the kind this project's standing policy says to do, not skip —
touching Browser test selectors and fixtures alongside the source rename
is the normal cost of a real rename here, not a reason to avoid it.

**Suggested action:** Treat as its own dedicated follow-on campaign after
items 1-9 (the file/PHP-class renames) land, given the combined size
(test files + fixtures across 6 widget families). For each widget, pick a
real functional class prefix, rename every DOM class literal in the
`.ts` file and its CSS, and update every asserting test/fixture in the
same commit per widget (don't batch all 6 into one commit — the
per-widget blast radius is already large enough to review independently).
Do not leave any of these "as-is" — only a genuine external contract
would justify that, and none exists here.

#### 17. [DECISION] standard_pages/skins/ vs admin roma/clear theme.json are genuinely different mechanisms — no merge or rename

**Files:** `standard_pages/skins/*.css`, `admin/{roma,clear}/theme.json`

`standard_pages/skins/*.css` is 11 accent-color variants (corrected from
an earlier miscount of 17, which had folded in unrelated preview-thumbnail
images) selected via string interpolation — a same-theme accent-variant
picker. Admin roma/clear's `theme.json` `parent`/`loadParentCss` fields
drive `ThemeChain.php`'s general-purpose theme-inheritance mechanism, a
structurally different facility. **Not accidental drift — no action.**

---

*Generated from workflow run `wf_58cd3e09-86e` (task w982z9ijl). Untracked
scratch file — not committed, safe to delete once reviewed/acted on.*
