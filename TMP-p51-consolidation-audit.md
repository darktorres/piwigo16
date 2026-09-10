# P51 (TS modernization) consolidation audit — temp working notes

Analysis-only workflow run (no files modified). 7 parallel survey dimensions
over the P51-touched TS/JS/PHP surface, 18 raw findings, 15 survived
independent skeptical verification against real source (call sites,
git history, docs/PLAN.md cross-references).

Overall auditor summary: *"The P51-touched JS/TS surface has real, verified
duplication concentrated in a handful of patterns rather than being broadly
messy: a couple of small hand-rolled helpers (element normalization, HTML
escaping) diverged from already-established shared utilities in dom.ts, one
of which produced an actual rendering bug (autogrow's escape-order defect)
alongside a one-line user-facing toast bug in profile.ts. The two largest
opportunities — mcs.ts's date/range filter setups and the cross-file admin
pagination widget — are self-contained, well-understood near-duplicates
whose extraction would remove roughly 700-900 lines combined, at medium
(not high) risk since both are additive refactors with clear existing call
sites. Several smaller items ... are low-risk, single-file or two-file
fixes ready to execute immediately, and one delete-confirm dedup is already
fully scoped in docs/PLAN.md as P53-M and just needs to be carried out. One
backend item (MetadataRepository's CategoryId VO gap) is unrelated to the
JS work but is the same class of "campaign left one site unconverted"
issue. Overall this reads as a mature, well-audited codebase with real but
bounded and mostly low-risk cleanup remaining, not a project in disarray."*

## Prioritized punch list

### 1. [bugfix, low risk] autogrow.ts hand-rolled HTML escape has a real double-escaping bug and duplicates dom.ts's escapeHtml()

**Files:** `themes/default/js/vendor/widgets/autogrow.ts`, `themes/default/js/vendor/utils/dom.ts`

autogrow.ts:40-44 escapes `<` and `>` before `&`, so a literal `<` becomes
`&lt;` and the later `&`→`&amp;` pass mangles it into `&amp;lt;`, which
renders as literal text and corrupts the shadow-div wrap/height
measurement for any textarea content containing `<` or `>`. dom.ts's
existing `escapeHtml()` (added by the P51-Z absorption commit, which
missed this file) escapes `&` first and avoids the bug; jqtree.ts and
selectize.ts already use it correctly.

**Suggested action:** Import `escapeHtml` from `../utils/dom` into
autogrow.ts and replace the three manual `.replace()` calls (~lines 40-44)
with a single `escapeHtml(value)` call, keeping the existing `\n`→`<br/>`
substitution after it. Manually verify with a textarea containing `<`/`>`
characters via autosize.ts's real call site
(`document.querySelectorAll("textarea")`).

### 2. [bugfix, low risk] getAllApiKeys() error toast concatenates a raw function name with no separator, producing garbled user-facing text

**Files:** `themes/standard_pages/js/profile.ts`

profile.ts:632 does `?? strHandleError + "getAllApiKeys"`, producing a
visible toast reading "An error has occuredgetAllApiKeys" whenever the
API-key GET fails without a detail field. The sibling catch block in
setInfos() (line 602, same file) uses bare `?? strHandleError`. This is a
lone leftover from the pre-rewrite jQuery source (`str_handle_error +
"getAllApiKeys"`), carried through the TS conversion unfixed; grep
confirms no other file does this.

**Suggested action:** In profile.ts's getAllApiKeys() catch block (~line
632), delete the `+ "getAllApiKeys"` suffix so it matches setInfos()'s
pattern: `?? strHandleError`. One-line fix.

### 3. [merge, low risk] toArray()/toElements() Element|ArrayLike normalizer duplicated 6 times instead of exported from dom.ts

**Files:** `themes/default/js/vendor/utils/dom.ts`, `themes/default/js/vendor/widgets/cluetip.ts`, `colorbox.ts`, `tiptip.ts`, `slider.ts`, `sortable.ts`, `datepicker.ts`

dom.ts already has a private `toElements(target: Element |
ArrayLike<Element>): Element[]` used by ~30 of its own helpers. Four
widgets (cluetip.ts:92-94, slider.ts:76-78, colorbox.ts:103-105,
tiptip.ts:48-50) hand-roll a byte-identical copy as `toArray`, and two more
(sortable.ts:281-282, datepicker.ts:1003-1005) inline the same ternary.
Pure, verified duplication with no per-widget variation, mirroring the
precedent P51-Z already set for escapeHtml/escapeRegExp.

**Suggested action:** Export the existing `toElements` from dom.ts. Delete
the four local `toArray` functions in cluetip.ts, slider.ts, colorbox.ts
and tiptip.ts, and the two inline ternaries in sortable.ts and
datepicker.ts; import the shared `toElements` at all six call sites.

### 4. [inconsistency, low risk] comments.ts reads data-status/data-page via raw getAttribute() instead of its own already-adopted data<T>() accessor

**Files:** `themes/admin/default/js/comments.ts`, `themes/default/js/vendor/utils/dom.ts`, `themes/default/js/vendor/widgets/selectize.ts`

comments.ts:200 uses `this.getAttribute("data-status")!` (under a
no-non-null-assertion disable) and comments.ts:521 uses
`Number(this.getAttribute("data-page")) - 1`, even though the same file
already imports and calls `dataId(this, "idx")` three times (lines 389,
400, 410) — this is an internal inconsistency within one file, not just a
repo convention it never adopted. selectize.ts has 6 structurally
identical unconverted `getAttribute("data-value")` sites, confirming this
is a genuine incomplete-adoption gap, not a P51-Q site deliberately left
alone.

**Suggested action:** Replace comments.ts:200 with `data<string>(this,
"status")` (dropping the `!` and the eslint-disable) and comments.ts:521
with `data<number>(this, "page") - 1`. While in the area, apply the same
conversion to selectize.ts's 6 `getAttribute("data-value")` sites.

### 5. [merge, low risk] Delete-confirm wiring duplicated across 3 call sites — already scoped as P53-M, just not yet executed

**Files:** `themes/admin/default/js/themesInstalled.ts`, `languages/installed.ts`, `plugins/new.ts`, `jconfirmPresets.ts`

themesInstalled.ts:15-31, languages/installed.ts:6-26, and
plugins/new.ts:174-182 all duplicate the same closest()+name-extraction+
%s-template+pwg_jconfirm_follow_href() shape. docs/PLAN.md already scopes
this correctly as **P53-M** with all 3 sites named and a proposed
`confirmDeleteByName(...)` signature, bundled with sibling dedups
P53-K/P53-L. Its prerequisite (P51-I's common.ts split, commit
cc8ed9c7a5) has already landed, so this is ready to execute, not a new
discovery.

**Suggested action:** Implement the existing P53-M plan item as written:
add `confirmDeleteByName(buttonSelector, boxSelector, nameExtractor,
titleTemplate)` to the P51-I module, migrate all 3 call sites onto it, and
bundle in the same commit with P53-K/P53-L's other
`pwg_jconfirm_follow_href` boilerplate sites per docs/PLAN.md's existing
scoping.

### 6. [remove, low risk] rating.ts's summary/update-text branches are dead code with a bespoke sprintf reimplementation

**Files:** `themes/default/js/rating.ts`, `ratingAutoQueue.ts`, `picture.ts`

pushRatingAutoQueue() has exactly one real call site (picture.ts), which
never sets updateRateElement/updateRateText/ratingSummaryElement/
ratingSummaryText. The `if (gRatingOptions.updateRateElement)`/`if
(gRatingOptions.ratingSummaryElement)` branches in updateRating() are
therefore unreachable, and they hide a private ad-hoc sprintf-substitute
that duplicates sprintf.ts's real implementation.

**Suggested action:** Delete both dead `if` branches in rating.ts's
updateRating() (~lines 129-142) including their private regex/replace
sprintf-substitute, and remove the four now-unused PwgRatingOptions
fields from the type and any object literals. Keep `onSuccess`. Grep
first to confirm no other caller sets those 4 fields.

### 7. [merge, low risk] profile.ts duplicates standard_pages.ts's password-match-check function instead of exporting and reusing it

**Files:** `themes/standard_pages/js/profile.ts`, `standard_pages.ts`, `template/profile.latte`

standard_pages.ts:226-259 defines unexported
`pwg_checkPasswordMatchStdPages`. profile.ts:313-342 (an IIFE) duplicates
its exact logic byte-for-byte (same error markup, same
`.closest(".column-flex")` convention, same blur/keyup binding).
profile.latte's confirmed DOM structure (`#password-section` >
`.column-flex` > `#password_new`/`#password_conf`) matches the shared
function's expected shape exactly, so it is a drop-in replacement once
exported. Distinct from the already-tracked P53-D (profile.ts's API-key
modals), which does not cover this.

**Suggested action:** Export `pwg_checkPasswordMatchStdPages(rootId,
pass1Id, pass2Id)` from standard_pages.ts. Delete profile.ts:313-342's
IIFE and replace it with `pwg_checkPasswordMatchStdPages("password-section",
"password_new", "password_conf")` inside the `if (canUpdatePassword)`
block.

### 8. [inconsistency, low risk] standard_pages.ts mixes raw addEventListener with its own imported on()/delegate() helpers, including two redundant listeners on the same elements

**Files:** `themes/standard_pages/js/standard_pages.ts`, `profile.ts`

standard_pages.ts imports on()/delegate() from dom.ts and uses them at
lines 204-211 and 257-258, yet has 9 other raw `.addEventListener` sites
(lines 28, 36, 77, 90, 144, 173, 190, 196, 288) despite on() already
accepting `ArrayLike<Element>` internally. Two of those sites (77 and 90)
are also a separate real bug: both attach their own 'input' listener to
the identical `.column-flex input` NodeList in the same ready() callback,
with line 90's body a strict subset of line 77's — every keystroke runs a
redundant querySelectorAll+hide() pass. profile.ts, same directory, has
zero raw addEventListener sites, confirming the established convention
this file should follow.

**Suggested action:** Convert all 9 raw `.addEventListener` sites in
standard_pages.ts to `on()`/`delegate()`, passing NodeLists directly (no
forEach wrapper needed). While converting, merge the two redundant
'input' listeners (lines 77 and 90) into one `on()` call whose body is the
superset (hide the error-message elements AND call
`input.setCustomValidity('')`).

### 9. [merge, medium risk] mcs.ts hand-rolls two families of near-identical filter-setup functions: date filters and range-slider filters

**Files:** `themes/default/js/mcs.ts`

setupDatePostedFilter (283-492) and setupDateCreatedFilter (494-~700) are
~210-line near-duplicates differing only by field-name string
substitution. Separately, setupFilesizeFilter (1075-1187),
setupHeightFilter (1189-1259) and setupWidthFilter (1261-1331) are
near-identical range-slider setups, differing mainly by field-name/
sliders-key strings and one real behavioral drift (filesize alone passes
a `stop` callback and checks only the max bound, vs both bounds for
height/width). Both are traced, verified duplication (identical
selectors, event wiring, and psParams/emptyFiltersList shape), not
superficial resemblance, and together represent the largest single-file
line-count reduction opportunity in this batch (~550 lines).

**Suggested action:** Extract `setupDateFilter(kind: 'date_posted' |
'date_created', emptyFiltersList)` to replace the two date functions, and
`setupRangeFilter(kind, sliderKey, widgetLabel, extraSliderOptions?)` to
replace the three range-slider functions. Resolve the filesize-only
`stop: onFilesizeSlideStop` callback and the max-only-vs-both-bounds
difference as explicit parameters during the merge rather than silently
dropping either behavior. Do the two extractions as separate commits
given the size.

### 10. [merge, medium risk] Client-side pagination widget hand-rolled near-identically across 4 admin JS files plus a shared template typo

**Files:** `themes/admin/default/js/users/list.ts`, `users/activity.ts`, `tags.ts`, `history.ts`, `comments.ts`, `template/navigation_bar.latte`

users/list.ts:1387-1505, tags.ts:1380-1490 (bounded-maxPage variant),
users/activity.ts:795-885 (cursor/endPage variant), history.ts:1091-1129
(simpler no-ellipsis variant), and comments.ts's commentsDiplayPagination
(~443-510) all reimplement the same updateArrows/appendPaginationItem/
updatePaginationMenu shape with the same selectors and toggle idiom,
including the identical `rigth` typo replicated in querySelectorAll calls
across 4 files and baked into the shared server template
navigation_bar.latte:33. Not addressed by P51's own pagination-adjacent
item, which only ruled on internal file-splitting, not this cross-file
widget duplication.

**Suggested action:** Extract a shared pagination-bar widget (e.g.
`themes/default/js/vendor/widgets/paginationBar.ts`) covering the
bounded-maxPage variant and the cursor/endPage variant, and migrate
history.ts and comments.ts onto it as far as their simpler rendering
allows. Fix the `rigth` typo as one coordinated rename across all JS
selectors, the CSS, and navigation_bar.latte in the same commit — never
partially.

### 11. [inconsistency, low risk] picture.ts hand-writes document.cookie because cookie.ts's setCookie() has no path parameter

**Files:** `themes/default/js/picture.ts`, `themes/default/js/vendor/utils/cookie.ts`

picture.ts:39-44 is the sole remaining `document.cookie =` write outside
cookie.ts itself. It needs an explicit path sourced from PictureView.php's
`cookie_path` (CookieService::cookiePath(), a mod_rewrite-aware mount
path), which cookie.ts's setCookie(name, value, days?) has no parameter
for. This differs from the P51-H precedent where standard_pages.ts safely
dropped `path=/` — picture.php sits behind the same mount-depth machinery
CookieService exists to handle, so that precedent does not extend here.

**Suggested action:** Add an optional `path` parameter to `setCookie(name,
value, days?, path?)` in cookie.ts, then replace picture.ts's hand-written
cookie write with `setCookie(...)` passing the existing `cookie_path`
value from PictureView.php. Do not drop the path the way P51-H did
elsewhere.

### 12. [inconsistency, low risk] MetadataRepository::findCategoryIds() still hand-rolls int|string category-id parsing instead of the CategoryId VO

**Files:** `src/Piwigo/Metadata/MetadataRepository.php`, `MetadataService.php`, `src/Piwigo/Controller/Admin/SiteUpdateSubController.php`

findCategoryIds() does its own is_numeric()/(int) cast at two bind sites,
unlike every other repository already audited in this campaign
(Permalink, Caddie, Mail, Auth\UserFailedLogin, Activity, History,
Search, Section), all of which uniformly wrap via
CategoryId::from()/tryFrom(). Traced call chain confirms a single parse
point (SiteUpdateSubController's sync/sync_meta passes raw
`$_POST['cat']` through), and current non-numeric handling (WHERE clause
skipped) is already safe — this is a consistency gap left from the
VO-retyping campaign, not a correctness defect.

**Suggested action:** Retype `findCategoryIds()`'s `$categoryId` parameter
to use `CategoryId::from()`/`tryFrom()`, using `tryFrom()`'s null path to
preserve the current "skip WHERE clause on non-numeric input" behavior so
MetadataService::getFilelist() and its SiteUpdateSubController caller are
unaffected.

## Findings that survived verification but were folded into the punch list above (not separately numbered)

- **jqtree.ts split candidate** — surveyed but the verifier returned
  `holds: false` for the specific split shape proposed; jqtree.ts's size
  (1563 lines) is real but the proposed split points didn't hold up to
  scrutiny. Not included above; worth a fresh look if jqtree.ts churn is
  ever on the table for another reason.
- **profile.ts (1050 lines) mixes 3 concerns, API-key subsystem (~700
  lines) is a clean split candidate** — raised by the standard-pages-js
  survey agent; not independently re-verified in the excerpt captured
  here (see the workflow's own journal.jsonl for the full verify record
  if this is worth pursuing before #8/#9 above).

## Raw survivors (verifier's revised rationale, pre-synthesis)

15 findings total survived out of 18 raw findings across 7 survey
dimensions (vendor-widgets, vendor-utils, admin-js, default-js,
standard-pages-js, backend-id-typing-gaps, tooling-config — the
tooling-config dimension returned zero findings, i.e. P51's own build/
lint/tsconfig setup is clean with nothing to flag).

See the punch list above for the full text of each survivor's rationale
— this section intentionally omits repeating them a second time.

---

*Generated from workflow run `wf_a6bdcde1-fd5` (task ws2z0b27h). Untracked
scratch file — not committed, safe to delete once reviewed/acted on.*
