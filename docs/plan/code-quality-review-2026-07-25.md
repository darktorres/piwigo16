# Deep code-quality & architecture review — findings and proposals

*Full-sweep review conducted 2026-07-25 against `17.x-rewrite`. Persisted here
(not `~/.claude/plans/`) so it survives beyond the originating Claude Code
session, matching `legacy-coupling-retirement.md`'s own precedent.*

## Context

The user asked for a deep review of code quality and architecture on
`17.x-rewrite`, on the premise that a lot of the PHP side has already been
modernized but more could be improved. This lands at a specific moment:
the DI migration (P24 batches 1–3, commit `9d26bc1f6`) just finished, and
a DBAL→ORM migration ("Part B", tasks #94–#103) was approved earlier today
— so the codebase is mid-flight on a large, already-planned initiative.

This review's job is **not** to re-derive or duplicate that work. It's a
direct-investigation pass (no subagents, per standing instruction) looking
for things that sit *outside* what's already tracked in
`docs/plan/legacy-coupling-retirement.md`, `docs/plan/manifest.yaml`, and
recent memory — real gaps, dead weight, or drift that the DI/DBAL-focused
effort wouldn't surface because it isn't what that effort is looking for.

Overall assessment up front: this is an unusually disciplined codebase for
a project at this stage — PHPStan level 10 with **zero** ignored errors,
deptrac enforcing a 6-layer dependency direction with **zero** violations,
ECS/Rector/composer-unused/composer-require-checker all wired into CI, a
mutation-testing plugin installed, and a 2100-line plan document that
re-verifies its own "done" claims rather than taking them on faith. Most
of what a "quality and architecture" audit would normally flag has
already been found and fixed by the team's own process. The findings below
are the real remainder — the gaps that survived that process, not
retreads of it.

This document is a **written report** — findings and proposals, not
implementations in progress. Each finding lists what to do about it so a
future planning session can pick one up directly.

This pass covers every PHP file in `src/Piwigo/` (470 files, all 49
domain directories), read in full rather than sampled — see "Sweep
coverage note" at the end of this document for how that was done and what
it did/didn't add beyond the initial sampling pass.

---

## Findings

### 1. Controller/Ws layers are nearly untested at the Unit level (highest value)

- **Evidence**: `tests/Unit/Controller/` has 3 files for **60** controller
  classes (`src/Piwigo/Controller/` + `Controller/Admin/`); `tests/Unit/`
  has **0** files under a `Ws/` directory for **24** classes in
  `src/Piwigo/Ws/` (`PwgImages.php` alone is 3061 lines; `PwgCategories.php`
  1718; `WsDefaultMethods.php` 2359). Coverage is carried almost entirely
  by Integration (73 files)/Contract (22 files)/Browser (20 files) tests
  instead — real end-to-end coverage, but slow, and it doesn't pin down
  unit-level behavior the way a fast, isolated test would.
- **This is already self-identified, not a new discovery**: the plan
  document's own "Phase 7 — Unit test coverage expansion" section names
  this exact gap and exact numbers, and says it becomes tractable "once
  Phase 1 makes the ~40-45 domain classes and controller layer genuinely
  mockable" — which Phase 1 (a–k) has now fully completed. Phase 7 itself
  was never started.
- **Why it matters**: this is the layer most likely to regress silently
  during Part B (DBAL→ORM), since Controllers/Ws are the top-level
  consumers of every repository being converted. Right now regressions
  here are caught only by the slower suites (Integration/Contract/Browser),
  well after the fact.
- **Proposal**: do this *before* Part B (DBAL→ORM) starts, not interleaved
  with it. The finding's own rationale is that Controllers/Ws are the
  top-level consumers of every repository Part B touches, and regressions
  there currently surface only in the slow suites, well after the fact —
  interleaving still leaves every repository converted before its turn
  arrives with no fast regression net during its own conversion, which is
  exactly the exposure window this finding is about. Start with the
  highest-traffic/highest-complexity files first (`PictureController`,
  `PwgImages`, `PwgCategories`, `CommentsController`), matching the plan's
  own prioritization, and treat reaching adequate Unit coverage on those
  as a real prerequisite gate before Part B begins converting the
  repositories they depend on.

### 2. `docs/ARCHITECTURE.md` is stale relative to current reality

- **Evidence**: the doc's HTTP pipeline section says `RequestPipeline`/
  `ControllerInvokerMiddleware` are "**Not yet reachable from real
  traffic** — built and tested since P9, but nothing routes an actual
  request through it yet; that's a later-phase cutover (P22+)." That's no
  longer true: `public/index.php` calls
  `RequestPipeline::handle(RequestFactory::fromGlobals())` directly, and
  `ControllerInvokerMiddleware` resolves real routed controllers
  (`$this->container->get($result->handler)`) for real requests today.
  The doc is explicitly scoped as covering "P6... Extended in P7–P23,
  P27, P32" but its content stops narrating anything past P12.
- **Why it matters**: this is the one file a new contributor (or a future
  session with less context) would read first to understand how requests
  actually flow. As written, it undersells how far the migration has
  actually gotten and could send someone down the wrong path (e.g.
  assuming controllers are still unreachable, or that root-level entry
  scripts like `about.php`/`picture.php` still exist — they don't;
  everything now lives under `public/`).
- **Proposal**: a documentation-only pass bringing the "Kernel boot
  sequence"/"HTTP middleware pipeline" sections up to date with P17–P23
  reality (real routed controllers, `public/` web-root isolation, the
  `Bootstrap\*Accessor` DI pattern). Low risk, high value for anyone
  onboarding into this codebase later — including a future Claude Code
  session with a fresh context window.

### 3. `test:coverage`'s `--min=5` threshold is not a meaningful gate

- **Evidence**: `composer.json`: `"test:coverage": "@php vendor/bin/pest
  --testsuite Unit,Arch --coverage --min=5"`. A 5% minimum only fails if
  coverage falls to near-zero — it can't catch a real regression in
  coverage of any normal size, and doesn't reflect the actual (much
  higher, given 118 Unit test files) coverage this codebase already has.
- **Why it matters**: it's not actively harmful, but it's a gate that
  looks like it's protecting something and isn't. Anyone reading
  `composer.json` cold would reasonably assume 5% is close to the real
  number, which isn't the case.
- **Proposal**: measure real current coverage
  (`vendor/bin/pest --testsuite Unit,Arch --coverage`, no `--min`) and
  raise `--min` to match it with reasonable headroom. This is the fix,
  not a relabel — renaming/commenting the script as a "smoke test"
  without raising the threshold leaves the exact same problem in place
  under a more honest name: it still can't catch a real coverage
  regression. Cheap once the real number is measured.

### 4. A handful of files are large enough to be genuine navigation/merge-conflict hotspots

- **Evidence** (largest first): `Config/CurrentConfig.php` 5302 lines,
  `Ws/PwgImages.php` 3061, `Ws/WsDefaultMethods.php` 2359,
  `Category/CategoryService.php` 1814, `Ws/PwgCategories.php` 1718,
  `Template/Template.php` 1533, `Ws/PwgCore.php` 1460,
  `Controller/Admin/ConfigurationSubController.php` 1443,
  `Users/UserService.php` 1438, `Admin/Upload/UploadService.php` 1408.
- **Not all of these are equally a problem** — investigated the largest
  one directly rather than assuming size alone means poor design:
  `CurrentConfig.php` is a deliberate, well-documented design (P13): one
  typed private-static property + getter/setter pair per real config key,
  chosen specifically to remove a generic string-keyed
  `override()`/`has()`/`delete()` surface in favor of full type safety.
  Its size is mechanical repetition (~290 keys × ~18 lines), not
  entangled logic — low actual risk despite the line count, though still
  a big single-file surface for merge conflicts across unrelated config
  changes.
  `WsDefaultMethods.php` (2359 lines) is similarly "confirmed clean" in
  the plan doc — pure method-registration table.
  The real complexity hotspots are `PwgImages.php`/`PwgCategories.php`/
  `PwgCore.php`/`CategoryService.php`/`UserService.php` — these mix many
  distinct operations (upload, permissions, search, tagging, listing) in
  one class each, and are exactly where Finding #1's missing Unit tests
  would matter most.
- **Proposal**: no mechanical size-based splitting for `CurrentConfig.php`/
  `WsDefaultMethods.php` — they're fine as-is; splitting a deliberately
  mechanical design for line-count alone would be churn without a
  correctness payoff. For the true hotspots, split along the cohesive
  sub-concerns each file's own method list already reveals — verified by
  reading each file's method groupings directly, not inferred from size
  alone — sequenced *after* Finding #1 gives them real Unit coverage
  (splitting untested code is genuinely higher-risk, not an excuse to
  defer indefinitely):
  - `PwgImages.php` (3061 lines): the upload pipeline (`addChunk`/
    `addFile`/`add`/`addSimple`/`upload`/`uploadAsync`/`checkUpload`/
    `uploadCompleted`/`setMd5sum`, plus the private `mergeChunks`/
    `removeChunks`/`addImageCategoryRelations` helpers only those methods
    use) is the dominant, clearly separable concern — over half the
    file — and should move to its own class first. `search`/
    `filteredSearchCreate`, `setPrivacyLevel`/`setCategory`/`setRank`,
    and `formatsSearchImage`/`formatsDelete` are 3 further natural splits.
  - `PwgCategories.php` (1718 lines): 3 natural groups —
    representative-image management (`setRepresentative`/
    `deleteRepresentative`/`refreshRepresentative`/the private
    `get`/`setCachedRepresentative` helpers), listing (`getImages`/
    `getList`/`getAdminList`), and category CRUD (`add`/`setRank`/
    `setInfo`/`delete`/`move`/`calculateOrphans`).
  - `PwgCore.php` (1460 lines): this one is structurally different — a
    grab-bag of WS methods for domains that never got their own `PwgXxx`
    class (session: `sessionLogin`/`sessionLogout`/`sessionGetStatus`;
    history: `historyLog`/`historyGet`/`historySearch`; plus one-off
    `caddieAdd`/`ratesDelete`), each already calling out to a real
    domain service via its own private `xxxService()` factory helper.
    The proper fix here isn't "split a god class" so much as "finish
    giving session/history their own `PwgSession`/`PwgHistory` classes,
    matching every sibling domain that already has one" — a completion
    of the existing pattern, not a new one.
  - `CategoryService.php` (1814 lines, 44 public methods) and
    `UserService.php` (1438 lines, 24 public methods): both genuinely mix
    several real concerns — for `CategoryService`, menu/UI rendering
    (`getCategoriesMenu`/`getRelatedCategoriesMenu`/`displaySelectCategories`
    family) is presentation-adjacent logic living inside a domain service,
    separable from category CRUD/lifecycle, from access-control
    (`getAccessGroupIds`/`denyGroupAccess`/`grantGroupAccess`/`catAdminAccess`),
    and from representative-image management; for `UserService`,
    lookup/validation (`validateMailAddress`/`getUserId`/`getUsername`)
    is separable from lifecycle (`registerUser`/`deleteUser`/`syncUsers`),
    from preference-defaults (`getDefaultTheme`/`getDefaultLanguage`/
    `getDefaultUserValue`), and from data-aggregation (`buildUser`/
    `getUserData`). Exact class boundaries need a closer read at pickup
    time (not done to full precision here, unlike the 3 `Ws/` files
    above), but the menu-rendering-out-of-CategoryService split in
    particular is a clear, low-ambiguity first cut.

### 5. `psalm.xml` (1143 lines, 182 files' worth of suppressions) is dead weight

- **Evidence**: ADR-0026 paused Psalm gating at P5 for a well-documented,
  investigated reason (Psalm's global-function index breaking on a large
  procedural codebase) and removed it from CI/`pre-push`. `psalm.xml`
  itself, though, is still a 73KB/1143-line file full of per-file
  `<code>`-tag suppressions that nothing reads anymore.
- **Why it matters**: minor, but it's maintenance surface with no
  payoff — every suppression in there is silently unverifiable (nothing
  runs Psalm to check whether it's still needed), and a future
  contributor could reasonably mistake its presence for "Psalm is still
  part of the toolchain."
- **Proposal**: this is a settled decision already (per memory: "Stop
  gating on Psalm — PHPStan/ECS/deptrac/Pest are the real gates"), so
  this isn't reopening that call — just cleanup. Delete `psalm.xml` and
  the `vimeo/psalm` dev dependency outright. A "shrunk" suppression file
  is not a middle option worth keeping: nothing runs Psalm against it, so
  a trimmed file is exactly as unverifiable as the current 1143-line one
  — there's no way to tell a suppression that's still needed from one
  that's been stale for months either way, so partial retention buys
  nothing. Whether to properly revisit Psalm later is a real, separate
  decision (the root cause — unnamespaced procedural functions — has
  substantially shrunk since P5, per ADR-0026's own framing, so it may
  genuinely be worth another look) — but that decision should produce a
  fresh, actually-verified config when someone re-runs the tool, not
  justify keeping today's dead one on life support in the meantime.

### 6. 46 `TODO`/`FIXME`/`@todo`-class markers in `src/`, uninventoried

- **Evidence**: `grep -rniE "TODO|FIXME|HACK|XXX" src --include=*.php`
  returns 46 hits. Most are false positives on this pass (`'xxx@yyy.eee'`
  email format strings, "Hacking attempt" log messages, an `MKGETDIR_xxx`
  docblock placeholder) — but a handful are real: e.g.
  `Html/HtmlService.php` has 4 `@todo nice display if $template loaded`
  markers, and `Image/DerivativeParams.php` has an incomplete docblock
  (`@todo : description of DerivativeParams::is_identity`).
- **Why it matters**: low individual severity, but nobody has triaged
  this list as a set — it's plausible some of these are stale (already
  resolved elsewhere) and some are real, silently-deferred gaps worth a
  tracked decision either way.
- **Proposal**: per-marker disposition is genuinely correct here (not a
  hedge) — these 46 hits are heterogeneous, and a single blanket action
  across all of them would be wrong for most of them. This pass didn't
  triage all 46, but for the 2 concrete real ones it did surface, the
  disposition is unambiguous, not "figure it out later":
  - `Image/DerivativeParams.php`'s `is_identity()` docblock: resolve it
    now, trivially — the method's own body already answers the
    "description" the `@todo` was waiting on (`in_size` fits within
    `ideal_size` in both dimensions, i.e. no resize is needed). Correct
    replacement text: "Whether `$in_size` already fits within
    `ideal_size` in both dimensions, meaning this derivative is a no-op
    resize (an identity transform) rather than a real downscale."
  - `Html/HtmlService.php`'s 4 `@todo nice display if $template loaded`
    markers (`pageForbidden`/`badRequest`/`pageNotFound`/`fatalError`):
    these are one coherent deferred feature (render via the loaded Smarty
    template when one's available, instead of the fixed inline HTML these
    4 methods currently emit), not 4 independent gaps — convert to a
    *single* tracked backlog item referencing all 4 call sites, rather
    than leaving 4 duplicate inline markers or resolving them piecemeal.
  - The remaining 42 hits still need the same look this pass gave these
    2 — that's real, undone triage work, not resolved by this review.

### 7. `CurrentConfig`'s static state is a deliberate but load-bearing exception to the DI direction (informational — no action recommended now)

- **Evidence**: `CurrentConfig` is `final class` with exclusively
  `private static` typed properties — real global mutable state, by
  design, coexisting with the DI-first direction the rest of the
  codebase (67 bucket-A classes now container-resolved, `Kernel::
  container()` access restricted to `Bootstrap/`) is moving toward. Its
  own docblock frames this as intentional: `ConfigService` is the real
  DI/Doctrine-backed persistence layer; `CurrentConfig` is "the static
  typed read/in-memory-write layer."
- **Why this is listed but not proposed as work**: investigated whether
  this contradicts the DI push (it's the kind of thing this review should
  catch) and concluded it doesn't — it's a conscious, documented split
  between two different concerns (fast synchronous global read access
  config is read from *everywhere*, vs. DI-friendly persistence), not an
  oversight. Flagging it here only so it's visible as a known, examined
  tradeoff rather than something a future pass rediscovers and "fixes"
  incorrectly.

### 8. Deptrac reports 564 "Uncovered" dependencies (low severity, real fix)

- **Evidence**: `vendor/bin/deptrac analyse` reports **0 violations** but
  **564 Uncovered** — dependencies from first-party classes to
  third-party libraries (Doctrine DBAL, Symfony Routing/Mime/Mailer,
  Smarty, PSR interfaces) that aren't modeled in any layer collector, so
  deptrac can't classify them as allowed or forbidden.
- **Why it matters**: not a real problem — this is normal for a ruleset
  that only models first-party namespaces — but 564 is a lot of noise in
  the report, which makes it harder to eyeball future `deptrac analyse`
  output for anything *newly* uncovered that might actually be worth a
  second look (e.g. a first-party class the ruleset doesn't know about
  yet, as opposed to routine vendor library usage).
- **Proposal**: add a `Vendor`/`External` layer collector (regex over
  `Doctrine\\`, `Symfony\\`, `Smarty\\`, `Psr\\`, etc.) so real
  uncovered-dependency reports shrink toward first-party-only cases and
  stay meaningful over time. Low severity doesn't make this optional —
  it's a small, mechanical, zero-risk config addition with a real
  (if modest) ongoing payoff every time someone reads deptrac output
  afterward; there's no version of "properly done" here that leaves it
  unaddressed, only a question of when it gets picked up.

### 9. `docs/plan/legacy-coupling-retirement.md` still documents a subsystem deleted the day before this review

- **Evidence**: the doc's "Phase 1i"/"Phase 1j" sections (and a Phase 5
  cross-reference around line 1594) describe `src/Piwigo/Admin/Install/DbPatch/`
  (127 files) and `VersionUpgrade/` (26 files) as "DONE" work items and,
  in Phase 5, as a still-live "genuinely pre-container remainder... stays
  on `ConfigDb` permanently." Both directories are now fully deleted —
  confirmed via `git show --stat 8224f23a3` ("fix(p23): delete the legacy
  in-place upgrade chain (Stage 0)", 2026-07-24, one day before this
  review) and directly via `find`/`ls` against the working tree (0 files
  in both paths). The commit message explains the whole 153-file chain
  contradicted the project's own documented "clean fork, no in-place
  upgrade" design and was carried over mechanically during porting before
  anyone caught the conflict.
- **Why it matters**: this is exactly the kind of drift a large, otherwise
  well-maintained plan document accumulates when a fast-moving deletion
  lands without a matching doc pass — a future reader of Phase 1i/1j/5
  would reasonably believe this subsystem still exists and is intentionally
  frozen on `ConfigDb`, when it's actually gone entirely.
- **Proposal**: a short doc-only pass — mark Phase 1i/1j as superseded by
  the Stage 0 deletion (with a pointer to commit `8224f23a3`), and correct
  Phase 5's Tier 3 accounting now that the "20 already-frozen DbPatch/
  VersionUpgrade files" it references no longer exist. Cheap, no code risk.

### 10. Two genuine, previously-untracked bugs found during the exhaustive read (both minor, both cheap to fix)

- **`Template.php`'s debug-mode display always fatals.** `Template::p()`
  (line 785) calls `\Smarty_Internal_Debug::display_debug($this->smarty)`
  when `CurrentConfig::debugTemplate()` is enabled; that class doesn't
  exist anywhere in the installed Smarty 5.x package. The code's own
  adjacent comment already correctly diagnoses the fix and deliberately
  didn't apply it ("Not in scope for a pure extraction; preserved
  verbatim"). Verified directly against the installed package
  (`vendor/smarty/smarty/src/Debug.php`): a real, working equivalent
  exists as `Smarty\Debug`, a normal instantiable class (not static) with
  a public `display_debug($obj, bool $full = false)` method — the exact
  same call the dead code is already trying to make, just via the wrong
  (removed, Smarty 4-era) static class name and calling convention. This
  isn't a coin flip between "fix it" and "delete the feature": a real,
  drop-in replacement already exists, so deletion would be discarding a
  working capability that costs one line to restore.
  **Proposal**: replace line 785 with `(new \Smarty\Debug())->display_debug($this->smarty)`
  (or a proper `use Smarty\Debug;` import + `new Debug()`), and drop the
  now-inapplicable `@phpstan-ignore class.notFound` + explanatory
  comment. A one-line fix, not five minutes of investigation — the
  investigation is already done above.
- **`MailService::generateResetPasswordMail()` drops its opening `<p>`
  tag.** Lines 1035–1036:
  ```php
  $message = '<p style="margin: 20px 0">';
  $message = Lang::t('Someone requested that the password be reset for the following user account:') . ' ' . $username . '</p>';
  ```
  Line 1036 uses `=` instead of `.=`, silently discarding line 1035's
  opening tag — the near-identical sibling method
  `generateSetPasswordMail()` (line 1069) does this correctly with `.=`.
  Result: the password-reset transactional email renders with an orphaned
  closing `</p>` and no matching opener. Minor (cosmetic HTML malformation
  in one email), not security-relevant, but a genuine, previously-unflagged
  bug in a real user-facing flow.
  **Proposal**: one-character fix (`=` → `.=`), trivial to verify against
  the sibling method's own correct pattern.

---

## Not investigated / explicitly out of scope for this pass

- The DBAL→ORM migration itself ("Part B") — already fully planned and
  approved separately; this review doesn't second-guess it.
- Frontend/TypeScript/Vite side — user's framing was specifically "the
  PHP side"; the JS toolchain (knip, eslint, stylelint, vitest) looked
  reasonably modern on a light pass and wasn't dug into further.
- Security posture beyond what's already tracked — `docs/plan/manifest.yaml`
  already carries a detailed `SEC-01`..`SEC-65` threat register with real
  status tracking; re-auditing security from scratch would duplicate that
  rather than add to it.
- Class-level circular-dependency analysis within a single deptrac layer
  (deptrac only enforces layer-to-layer direction, not intra-layer
  cycles) — not checked; would need a separate tool/pass if wanted later.

## How to re-verify these findings

- Finding 1: `find tests/Unit/Controller -name '*.php' | wc -l` vs.
  `find src/Piwigo/Controller -name '*.php' | wc -l`; same for `Ws/`.
- Finding 2: read `public/index.php` and
  `src/Piwigo/Http/Middleware/ControllerInvokerMiddleware.php` against
  `docs/ARCHITECTURE.md`'s HTTP pipeline section.
- Finding 3: `grep test:coverage composer.json`.
- Finding 4: `find src -name '*.php' -exec wc -l {} + | sort -rn | head`.
- Finding 5: `wc -l psalm.xml`; `docs/adr/0026-pause-psalm-gating.md`.
- Finding 6: `grep -rniE "TODO|FIXME|HACK|XXX" src --include=*.php`.
- Finding 8: `vendor/bin/deptrac analyse --cache-file=.deptrac.cache
  --report-uncovered` (tail of output has the summary counts).
- Finding 9: `find src/Piwigo/Admin/Install/DbPatch src/Piwigo/Admin/Install/VersionUpgrade`
  (both empty/absent); `git show --stat 8224f23a3`; read Phase 1i/1j and
  the Phase 5 Tier-3 paragraph in `docs/plan/legacy-coupling-retirement.md`.
- Finding 10: read `src/Piwigo/Template/Template.php` around its `p()`
  method (the `\Smarty_Internal_Debug::display_debug()` call) and
  `src/Piwigo/Mail/MailService.php` lines 1035–1036 vs. 1068–1069.

All counts above were captured directly against the working tree on
2026-07-25 and will drift as work continues — re-run before acting on any
of them.

---

## Sweep coverage note

This review's first pass sampled a subset of `src/Piwigo/`. The user asked
whether the *entire* PHP codebase had actually been reviewed — it hadn't —
and confirmed continuing with a full exhaustive read. Every one of the 470
PHP files under `src/Piwigo/` (all 49 top-level domain directories) was
then read in full, domain-by-domain, in this session. That full sweep
surfaced exactly the 3 additional items folded in above (Finding 9, and
the 2 bugs in Finding 10) — everything else read confirmed the same
established, disciplined patterns already described in the Context section
(repository/service split, typed projection row-shapes, DI-first
construction, documented security fixes referenced by SEC-NN id, deptrac-
clean layering) with no further undiscovered architectural issues. This
is a real ceiling on this pass's confidence, not a caveat to wave away:
direct human/LLM reading, even exhaustive, can still miss something a
tool-assisted pass (mutation testing, a fresh static-analysis rule, a
targeted grep sweep for a specific anti-pattern) would catch — but every
file's control flow, dependency direction, and inline documentation was
read and considered at least once.
