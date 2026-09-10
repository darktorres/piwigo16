# P51 (TS) type-safety & code-quality audit — temp working notes

Analysis-only workflow run (no files modified). 7 parallel survey
dimensions over unsafe casts, `eslint-disable` staleness, non-null
assertions, error handling, weak typing, ambient-type accuracy, and
test-file standards. 23 agents, merged into 9 punch-list items.

**Note on the audit's own starting numbers:** the calibration text handed
to this workflow (based on a quick grep before launch) said "~48 remaining
non-null-assertion usages" and "1 `as any`". **Both were wrong** — the
audit itself caught this (item 8): the real count is **352
non-null-assertion disable sites across 54 files** (virtually all still
correctly justified), and the "1 `as any`" was a false-positive grep hit
inside comment prose — the real count of actual `as any` casts in
production `themes/` code is **0**. Worth remembering next time a quick
grep is used to seed a workflow's calibration text.

Overall auditor summary: *"The P51-touched codebase is in solid shape at
this point in the campaign: production `as any` usage is confirmed at
zero..., the vast majority of the 352 non-null-assertion disables and the
29 as-unknown-as casts still carry accurate, specific justifying comments,
and the test suite is held to the identical strict standard as production
with no carve-out. What remains is a tail of smaller, non-systemic gaps
rather than backsliding: one genuine runtime-bug-shaped issue
(localStorage-sourced JSON parsed with no error handling, one path
reachable uncaught from a UI event handler), a cluster of stale
comments/justifications..., a non-generic internal helper (dom.ts's
find()) that forces repeated boilerplate at 10 call sites, and a handful
of any-typed values in history.ts/mcs.ts that a from-scratch codebase
would have narrowed. None of these represent regression from
P51-O/P/Q/U's closed work — they're file- or pattern-specific gaps those
targeted passes didn't reach — and each has a small, mechanical, low-risk
fix."*

## Prioritized punch list (9 items)

### 1. [unsafe-pattern, medium risk] localStorage-sourced JSON is parsed with no error handling at two sites, one reachable uncaught from a UI event handler

**Files:** `LocalStorageCache.ts`, `users/groupUserManager.ts`

`LocalStorageCache.get()` (line ~125) does `JSON.parse(stored)` on real
browser localStorage content guarded only by a not-null check, with **no
try/catch** — unlike its own sibling `set()` (lines 159-177), which
already wraps storage writes in try/catch. This method backs all 4 real
cache subclasses used by every selectize-backed admin page. Separately,
`groupUserManager.ts`'s `updateUserSearch()` bypasses `get()` entirely and
hand-rolls the same unguarded parse; one of its 3 call sites is a plain
`focus` DOM handler sitting outside every try block in the file, so a
corrupted/incompatible localStorage entry throws a `SyntaxError` straight
out of a focus handler, silently breaking the user-search dropdown.

**Suggested action:** Wrap `LocalStorageCache.get()`'s `JSON.parse`
call in try/catch mirroring `set()`'s existing pattern, returning the
same not-found/invalid fallback instead of throwing. Refactor
`groupUserManager.ts`'s `updateUserSearch()` to call `usersCache.get()`
instead of hand-rolling the parse — this also removes its duplicate cast
and disable-comment pair and closes the uncaught path from the focus
handler.

### 2. [stale-suppression, low risk] build/ambient-globals.d.ts's AlbumSelector-related ambient types are stale, duplicated, and unnecessarily ambient

**Files:** `build/ambient-globals.d.ts`, `eslint.config.ts`, `album_selector.ts`, `mcs.ts`, `intro.ts`, `introTooltips.ts`

Three co-located inaccuracies: (a) `AlbumSelectorCallbackArgs.addSelectedAlbum`
is typed `(...args: any[]) => void` though the real method and all 4 real
call sites take zero arguments — this stale variadic is
`eslint.config.ts`'s sole remaining stated justification for keeping this
file in the `no-explicit-any` override list; (b) `AlbumSelectorInstance`
hand-duplicates the real, already-imported `AlbumSelector` class's public
surface, and its justifying comment (claiming the real class "stays
loosely typed") is **factually false** — its ~17 private fields are all
concretely typed, a comment now describing code that has changed out
from under it; (c) `AlbumSelectorOptions`/`StorageDetails` sit in the
ambient file despite having exactly one real consumer each, unlike the
genuinely-ambient `Window.popuphelp`/`pwg_tryFocus` globals with real
non-module `<script>` call sites.

**Suggested action:** Retype `addSelectedAlbum` to `() => void` and drop
`build/ambient-globals.d.ts` from `eslint.config.ts`'s `no-explicit-any`
override list. Delete `AlbumSelectorInstance`, retype `let ab` in
`mcs.ts:144` as `AlbumSelector` (already imported). Move
`AlbumSelectorOptions` into `album_selector.ts` and `StorageDetails` into
`intro.ts`, exporting each from its real owning module.

### 3. [weak-typing, low risk] dom.ts's find() helper isn't generic, forcing a repeated unsafe-cast-plus-disable pattern at 10 call sites

**Files:** `vendor/utils/dom.ts`, `users/groupList.ts`, `users/list.ts`, `mcs.ts`, `batch_manager/global.ts`

`find()` always returns `Element[]` even though the codebase already
types `querySelectorAll` generically at ~20 other call sites. Because
`find()` alone doesn't follow that convention, 10 real call sites across
4 files each need their own manual cast plus an eslint-disable comment —
each individually accurate, but exactly the repeated boilerplate a
from-scratch typed codebase would eliminate once, at the helper.

**Suggested action:** Change `find()`'s signature to `export function
find<E extends Element = Element>(target: Element | ArrayLike<Element>,
selector: string): E[]`, mirroring `Element.querySelectorAll<E>()`.
Update the 10 call sites to pass the type parameter directly, removing
each site's cast and disable comment.

### 4. [stale-suppression, low risk] groupUserManager.ts comment quotes an outdated selectize.ts cast form that no longer exists

**Files:** `users/groupUserManager.ts`, `vendor/widgets/selectize.ts`

The comment explaining a `String(id) !== ""` guard quotes selectize.ts's
cast as `items[0] ?? ("" as unknown as T)`, but that cast was narrowed to
`items[0] ?? ("" as T)` **over 12 hours before the quoting comment was
even added** — confirmed via `git log`/`git show` on both commits. The
comment has been stale from the moment it was written. The underlying
guard logic is correct and not in question.

**Suggested action:** Update the comment to quote selectize.ts's current
form. No logic change needed.

### 5. [weak-typing, low risk] SelectizeInstance.on() declares one imprecise shared handler signature, forcing an avoidable cast at its only call site

**Files:** `vendor/widgets/selectize.ts`, `LocalStorageCache.ts`

`on()`'s public signature is a single union type covering multiple
distinct events, even though the internal `listeners` map already splits
handlers correctly per event and `dropdown_close` is invoked with zero
args. This self-authored interface imprecision forces a cast plus
disable comment at its only call site — **the audit explicitly flagged
that its own "a justified comment is not a finding" rule shouldn't apply
here**, since the root cause is entirely within this codebase's control
(a fixable interface design choice), not an external/DOM constraint.

**Suggested action:** Overload `on()` into per-event signatures matching
the internal listeners split, then remove the cast and disable comment at
the call site.

### 6. [weak-typing, low risk] history.ts has three any-typed values with knowable concrete types

**Files:** `history.ts`, `openapi/client/schema.d.ts`

Three related gaps in the same file: (1) `activeMore: any[]` at 5 sites
holds only strings in every real push/read — mechanical `any[]`→`string[]`
narrowing; (2) `SEARCH_DETAIL_ICONS: Record<string, any>` holds only
string literals while its structurally-identical sibling one line away
is already correctly `Record<string, string>`; (3)
`activeSearchDetails: Record<string, any>` discards the already-generated,
more precise `HistorySearchDetails` OpenAPI schema type one destructure
away — **the `eslint.config.ts` comment justifying this as "genuinely
heterogeneous" was factually inaccurate even at the time it was written**
(the real controller builds every field as a flat string/string[]/null,
matching the schema exactly).

**Suggested action:** Narrow `activeMore` to `string[]`; retype
`SEARCH_DETAIL_ICONS` to `Record<string, string>` (drop the now-redundant
`String()` wrap); replace `activeSearchDetails: Record<string, any>` with
`Partial<HistorySearchDetails>`; correct the `eslint.config.ts` comment's
"genuinely heterogeneous" claim.

### 7. [weak-typing, low risk] mcs.ts's emptyFiltersList is typed any[] when unknown[] covers every real use

**Files:** `mcs.ts`

Typed `any[]` at 16 sites, but every real operation on it type-checks
identically against `unknown[]`. The sibling `filtersToRemove` in the
same function scope already carries a comment noting it was deliberately
narrowed rather than left `any[]` — the file's own authors just didn't
extend that discipline here.

**Suggested action:** Retype `emptyFiltersList` from `any[]` to
`unknown[]` at all 16 sites. Zero behavior change; leave the legitimate,
documented `psParams: Record<string, any>` carve-out untouched.

### 8. [other, low risk] P51 campaign's own tracked baseline figures are stale (bookkeeping only, no code change)

The working baseline of "~48 remaining non-null-assertion usages" is
badly stale — real count is **352** disable sites across 54 files,
virtually all still carrying specific, accurate justifying comments (no
bulk cleanup needed). Separately, "1 `as any`" is a false positive (a
comment-text substring match) — the true count of real `as any` casts in
production `themes/` code is **0**.

**Suggested action:** Update whatever campaign-tracking document quotes
these figures. No source code change required.

### 9. [other, low risk] Test files (tests/Unit/**) confirmed held to the same strict standard as production — verified sound, no action needed

**Files:** `eslint.config.ts`, `tsconfig.json`

Direct reads confirm **no test-path carve-out exists**: the hardened rule
block (`no-non-null-assertion: error`, `no-unsafe-type-assertion: error`)
and `strictTypeChecked` apply glob-wide; the only `any`-relaxation
override targets 5 named production files, none under `tests/`. All 20
real eslint-disable comments under `tests/Unit/**` were read individually
and are specific and still accurate; the test suite's
`dom-test-helpers.ts` even adopts a real production pattern rather than
falling back to non-null assertions.

**Suggested action:** No fix required. Record as a closed/verified item so
a future audit doesn't need to re-check it.

---

*Generated from workflow run `wf_e4bbe518-10a` (task w2pfun4tv). Untracked
scratch file — not committed, safe to delete once reviewed/acted on.*
