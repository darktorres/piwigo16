# Plan: §1.7 master execution campaign — eliminate `mixed` from the domain

## Context

§1.7 of `ROADMAP.md` (2026-05-23 rewrite) is the umbrella for the 5-boundary
mixed-elimination effort. Its acceptance gate is `psalm --show-info <50`
(currently **1796**, was 2,041 at plan inception, **−245 net**). 6 of 8
acceptance gates already met; the two open ones (psalm-info <50 + zero
`is_array(.* ?? null)`) are load-bearing on the 12 sequenced phases below.

This plan turns §1.7 into an **executable campaign**: per-phase scope,
commit boundaries, verification, rollback. One phase per session unless a
phase decomposes into independently mergeable sub-commits (most do).
**Execution starts next session.** This session produces the plan only —
no code changes per plan mode + user directive.

User-confirmed campaign shape (2026-05-23):
- **Master plan first, then execute** (no code changes this session).
- **Full verification per ROADMAP §1.7 per phase**: `composer analyse`
  + `composer test` + `composer lint` green, per-boundary smoke,
  psalm-info delta in commit message, removed `is_*` guard count in PR
  description.

---

## Pre-flight (run at the start of every phase session)

Reconcile counts before planning the phase's commits — drift is real and
the ROADMAP snapshot is dated 2026-05-23. Pin a fresh snapshot in the
phase's first commit message.

```bash
# Acceptance-gate signals
vendor/bin/psalm --show-info=true 2>&1 | tail -5     # psalm-info total
grep -rnE 'is_array\(.*\?\? null\)' src/ | wc -l       # gate target: 0
grep -rln '\$_SESSION\[' src/Piwigo | grep -vE 'Session\.php|FlashBag\.php' | wc -l  # gate target: 0 (currently met)

# Phase-specific signals (one of these per phase, see each phase section)
find src/Piwigo/Ws/Action -name '*Handler.php' | wc -l          # phase 2
find src/Piwigo/Ws/Action -name '*Params.php'  | wc -l
find src/Piwigo/Ws/Action -name '*Result.php'  | wc -l
grep -c 'factory(static fn' config/container.php                # phase 8
grep -rln 'fn (mixed' src/Piwigo | wc -l                        # phase 7
grep -rln '\$_POST\|\$_GET' src/Piwigo | wc -l                  # phase 3
```

Baseline (2026-05-23, verified at plan time):

| Signal                                          | Value       | Target          |
| ----------------------------------------------- | ----------- | --------------- |
| `psalm --show-info` total                       | 1796        | <50             |
| `is_array(.* ?? null)`                          | 153         | 0               |
| `$_SESSION` outside Session.php / FlashBag.php  | 0           | 0 ✅            |
| `*Handler.php`                                  | 94          | 95 (1 drift)    |
| `*Params.php`                                   | 83          | 95              |
| `*Result.php`                                   | 7           | 95              |
| `factory(static fn` in container.php            | 118         | 0               |
| `fn (mixed` lambda files                        | 95          | 0               |
| Raw `$_POST`/`$_GET` files                      | 54          | 0 in admin/web  |
| Raw `$_POST`/`$_GET` references (any form)      | 758         | minimal         |
| 249 bare-array repo methods (from SQL-DTO)      | 249         | <50             |
| SearchFilterRenderer lines / psalm-info issues  | 920 / 67    | reduced         |
| SearchService lines / psalm-info issues         | 1138 / 47   | reduced         |

⚠ The ROADMAP §1.7 status snapshot was correct at write time but already
shows 1-handler drift. Re-verify at phase-start, update ROADMAP §1.7 in
the phase's final commit if drift exceeds ±5%.

---

## Per-phase template

Each phase below follows this shape:

- **Goal** — one-sentence outcome.
- **Scope** — concrete files / patterns / counts.
- **Sub-commits** — independently mergeable units (≤500 LoC each).
- **Verification (full per §1.7)** — every phase must pass:
  - `composer analyse` (= phpstan level-10 + psalm errorLevel-2) clean
  - `composer test` green (1282 baseline)
  - `composer lint` (Pint) clean — **per memory `feedback_run_pint_before_commit`**
  - `composer dump-autoload` after any new class — **per memory
    `feedback_composer_dump_after_new_class`** (composer.json pins
    classmap-authoritative)
  - Per-boundary smoke (see each phase)
  - psalm-info delta recorded in commit message
  - Removed `is_*` guard count in commit message body
- **Expected psalm-info delta** — best-effort estimate.
- **Effort** — S / M / L / XL.
- **Blockers** — prerequisites within this campaign.
- **Rollback** — what reverts cleanly vs what bakes in.

Cross-cutting rules (from memory, enforced every phase):
- No scripted mass rewrites — multi-file refactors are per-occurrence
  Edit calls ([[feedback_no_mass_rewrites]]).
- Don't suppress as a shortcut — fix contracts, add `#[\Override]`,
  retype source ([[feedback_no_suppression_shortcut]]).
- Document every PHPStan/Psalm suppression with rationale comment
  ([[feedback_document_every_suppression]]).
- Retire defensive casts in the same commit that retypes the source
  ([[feedback_cleanup_with_retype]]).
- Refactor multi-use callers before API migration
  ([[feedback_refactor_multi_use_callers_first]]).
- Long-running commands (phpstan/psalm/build) — save output to file
  ([[feedback_long_commands_to_file]]).

---

## Phase 1 — F5-i deep adoption (Boundary 2)

**Goal:** rewrite `SearchService::getRegularSearchResults()` +
`SearchFilterRenderer` to consume typed `SearchRules` + 14 filter VOs
end-to-end; eliminate the ~200 mixed accesses across both files; add the
2 missing helpers (`DateCustom`, `MysqlDateRange`) named by the F5 plan.

**Scope:**
- `src/Piwigo/Search/SearchService.php` (1138 lines, #7 hotspot, 47 issues)
- `src/Piwigo/Search/SearchFilterRenderer.php` (920 lines, **#1 hotspot, 67 issues**)
- `src/Piwigo/Search/Rules/*.php` (foundation exists: SearchRules + 14
  filters + 3 helpers shipped — extend, don't replace)
- New: `src/Piwigo/Search/Rules/DateCustom.php`
- New: `src/Piwigo/Search/Rules/MysqlDateRange.php`
- Templates that read filter shapes: `template/picture.latte`,
  `template/search.latte`, `template/admin/batch_manager_global.latte`
  (verify with `grep -rn 'allwords\|tagsFilter' template/`)

**Sub-commits:**
1. `feat(search): add DateCustom + MysqlDateRange helpers` — 2 new VOs +
   unit tests. ~150 LoC.
2. `refactor(search): SearchFilterRenderer consumes SearchRules typed` —
   replace all `$rules['allwords']`, `$rules['cat']` etc. mixed accesses
   with typed property access. Split into 2-3 commits by filter group
   (text/identity filters, date/range filters, dimension/rating filters)
   if commit exceeds 500 LoC.
3. `refactor(search): SearchService::getRegularSearchResults typed` —
   same pattern on the service side; query-building methods receive
   typed VOs not raw arrays.
4. `refactor(search): drop is_* guards at search call sites` — sweep
   `SearchAdminController`, `SearchController`, search-related WS
   handlers. ([[feedback_cleanup_with_retype]] — must ride along with
   the retype commits or land same-day.)

**Verification (full per §1.7):**
- Boundary 2 smoke per F5 plan: end-to-end search exercising every
  filter combo (allwords, tags, dates, ratios, ratings, dimensions,
  expert, added_by, filetypes) before / after. Browser test — F5 plan
  flags "high-risk".
- `tests/Integration/Repository/SearchRepositoryTest.php` green.
- psalm-info delta: target **−100 minimum** (114 of 1796 currently
  sit in the two hotspots).
- `composer test:parallel` since boundary touches request paths.

**Expected psalm-info delta:** −100 to −150.
**Effort:** L. **Blockers:** none.
**Rollback:** clean revert per commit; foundation VOs (commit 1) stay
even if rewrite reverts.

---

## Phase 2 — F5-h Result side (Boundary 1)

**Goal:** tighten `WsAction` interface from
`__invoke(array $params, PwgServer $server): mixed` to
`__invoke(array $params, PwgServer $server): WsResult|PwgError`; add
`*Result.php` for the 88 endpoints not yet on the typed-Result path.

**Scope:**
- Interface: `src/Piwigo/Ws/WsAction.php` — return type tightening.
- Caller: `src/Piwigo/Ws/PwgServer::invoke()` — enforce the contract.
- 88 new `*Result.php` files across `src/Piwigo/Ws/Action/Pwg/<Domain>/`.
- 94 `*Handler.php` `__invoke()` return-type + body update.

**Sub-commits** (one Result class per endpoint = too many; bundle by
priority order from F5-PENDING):
1. `feat(ws): 11 zero-params endpoints get *Result DTOs` —
   `CheckUpdates`, `PluginsGetList`, `GetCacheSize`, `GetInfos`,
   `GetVersion`, `History/Search`, `CheckUpload`, `EmptyLounge`,
   `Logout`, `GetAdminList`. Each: new `*Result.php` + handler return
   migration. Keep interface loose for now. ~600 LoC across 11 file
   pairs — may need 2 commits if Pint disagrees.
2. `feat(ws): 12 explicit-union handlers get *Result DTOs` — the
   handlers whose return-type already reads
   `array<string, mixed>|PwgError`. Find via
   `grep -rln 'array<string, mixed>|PwgError' src/Piwigo/Ws/Action`.
3. `feat(ws): remainder *Result DTOs` — bundle by domain (Images,
   Categories, Tags, Users, etc.), 1 commit per domain. **Per
   [[feedback_refactor_multi_use_callers_first]]:** start with domains
   whose Results are reused by other handlers (introspection paths).
4. `refactor(ws): tighten WsAction return type to WsResult|PwgError` —
   single small commit that flips the interface + adds `#[\Override]`.
   Lands only when all 94 handlers ship a typed Result. PHPStan
   enforces.
5. `refactor(ws): PwgServer::invoke enforces typed result envelope` —
   shape the response builder around `WsResult::toArray()` instead of
   raw array.

**Verification:**
- `tests/Integration/WsApiTest.php` (292 lines) — must stay green at
  every sub-commit.
- For each new `*Result`: a smoke unit test (5-10 lines, asserts
  `toArray()` shape matches the WS response builder's expectations).
- OpenAPI: `tools/openapi-dump.php` is **broken on `16.x-rewrite` head**
  (Piwigo\Users\User::__construct() TypeError at line 56 — see ROADMAP
  §1.7 verification block). Fix as a pre-phase tiny commit so the
  WsResult shape can be diffed against the generated spec; otherwise
  spec drift goes unverified.
- psalm-info delta: target −30 to −50 (each typed Result removes
  scattered mixed accesses in the corresponding handler body).

**Expected psalm-info delta:** −30 to −50. **Effort:** L (88 files).
**Blockers:** `tools/openapi-dump.php` fix (small, can be commit 0).
**Rollback:** interface tightening (sub-commit 4) is the breaking
change; per-Result additions are additive and revert cleanly.

---

## Phase 3 — Web/admin Request DTOs (Boundary 1)

**Goal:** parse `$_POST`/`$_GET` once at the boundary into typed
`*Request` DTOs following the `WsParams::fromArray()` shape (decision
locked in ROADMAP §1.7 — no `symfony/serializer`/`validator` deps).

**Scope:** 758 raw references / 54 files in `src/Piwigo`. Target ~30-50
multi-field web DTOs; long tail stays on `StringUtil::input{Int,String,Bool}`.

Priority order (highest field count first):
- `src/Piwigo/Controller/Admin/BatchManagerController.php` (largest
  multi-field admin surface)
- `src/Piwigo/Controller/Admin/MaintenanceController.php`
- `src/Piwigo/Controller/Admin/PhotoController.php`
- `src/Piwigo/Picture/PictureCommentRenderer.php` (sibling of the WS
  `AddCommentParams`; first DTO since cross-reference exists)
- `src/Piwigo/Controller/PictureController.php`
- Upload/maintenance/admin form controllers under
  `src/Piwigo/Controller/Admin/`

Out of scope here:
- WS protocol layer (`PwgServer.php`, `PwgRestRequestHandler.php`) —
  3 reads, intentional. Leave.
- Stray `$_POST['apply_on_sub'] = true` mutation in
  `Action/Pwg/Permissions/AddHandler.php` — flagged as code smell;
  separate small commit, sequenced first in phase 3.

**Sub-commits:**
0. `fix(ws): drop $_POST mutation in Permissions/AddHandler` — replace
   with explicit param-set on the action object.
1. `feat(picture): introduce CommentSubmitRequest DTO` —
   `PictureCommentRenderer` first because `AddCommentParams` is its WS
   sibling for cross-reference. ~150 LoC.
2-N. `feat(admin): introduce <Controller>Request DTO(s)` — one commit
   per controller. Each: `final readonly class XxxRequest` with
   `fromArray()` factory, controller flips to use it, smoke unit test.

**Verification:**
- For each migrated controller: browser smoke of the affected admin
  form — fill out the form, submit, verify behavior unchanged. (No
  unit test alone is sufficient; admin forms have many edge cases.)
- New DTOs each get a unit test on `fromArray()` covering
  missing-field, wrong-type, valid-input.
- psalm-info delta: target −80 to −120 (each controller migration
  drops 5-15 mixed accesses).
- Removed `is_*` guard count in PR descriptions — significant on
  admin controllers.

**Expected psalm-info delta:** −80 to −120. **Effort:** XL (54 files,
30-50 DTOs). **Blockers:** none. **Rollback:** per-controller commits
revert cleanly; DTO classes are additive.

---

## Phase 4 — Typed error responses (Boundary 1)

**Goal:** replace free-form `PwgError` message strings with a typed
error-code enum + i18n translation key so the wire format carries a
stable identifier.

**Scope:**
- `src/Piwigo/Ws/PwgError.php` — already a `final readonly` VO; extend
  with optional `errorKey()` returning an enum, keep `message()` for
  back-compat during migration.
- New: `src/Piwigo/Ws/PwgErrorCode.php` (enum).
- Migration targets: 2 throw sites in
  `src/Piwigo/Ws/Action/Pwg/Session/LoginHandler.php:47,59`
  (rate-limit + account-locked). Find all other throw sites via
  `grep -rn 'new PwgError(' src/` (likely ~20-30) and migrate in
  bundles by domain.
- Translation keys added to `language/en_UK/common.lang.php` and
  marked for i18n sync.

**Sub-commits:**
1. `feat(ws): add PwgErrorCode enum + extend PwgError with errorKey()`
   — additive, no migration yet.
2. `feat(ws/session): migrate LoginHandler throws to typed
   PwgErrorCode` — covers the 2 cited sites + their translation keys.
   Cross-refs `docs/SECURITY.md:182,235,238`.
3. `feat(ws): migrate remaining PwgError throws to PwgErrorCode` —
   bundle by domain, 1 commit per. Aim to remove the back-compat
   `message()` path in a final commit once all sites migrated.

**Verification:**
- `tests/Integration/WsApiTest.php` — assertions on error envelope
  shape may need updating; bake the new `code:` key into the asserts.
- For LoginHandler specifically: integration test that hits the
  rate-limit + account-lock paths and verifies the new envelope.
- psalm-info delta: small (−5 to −10) — this is a contract change,
  not a mixed-elimination win directly.
- i18n: confirm new translation keys present in `language/en_UK/` at
  minimum; flag other locales for downstream sync.

**Expected psalm-info delta:** −5 to −10. **Effort:** M.
**Blockers:** phase 2 (typed `*Result` envelope) helpful but not
strictly required — `PwgError` is separate from the success-path
`WsResult` union. **Rollback:** clean per commit.

---

## Phase 5 — Back-channel cleanup + AuthService result-DTO (Boundary 1)

**Goal:** retire the `PageState::loginFailureReason` session-flag
back-channel; throw `AuthException::accountLocked()` instead.
Restructure `AuthService::login()` to return an explicit
`AuthResult { ?UserId $userId, ?AuthFailureReason $failureReason }`
DTO instead of surfacing failure mode through
`AuthService::getLastFailureReason()`.

**Scope:**
- `src/Piwigo/Auth/AuthService.php` — restructure `login()` return.
- `src/Piwigo/Auth/AuthException.php` — `accountLocked()` defined but
  never thrown; wire up.
- New: `src/Piwigo/Auth/AuthResult.php` + `AuthFailureReason.php` (enum).
- Callers: `src/Piwigo/Ws/Action/Pwg/Session/LoginHandler.php`,
  `src/Piwigo/Controller/IdentificationController.php`,
  `src/Piwigo/Page/PageState.php` (drop `loginFailureReason` slot
  fully; sweep any persisters).

**Sub-commits:**
1. `feat(auth): add AuthResult + AuthFailureReason DTOs (unused)` —
   additive, infrastructure first.
2. `refactor(auth): AuthService::login returns AuthResult` — flips
   the API + updates all 2 callers; `getLastFailureReason()` deletion
   ([[feedback_cleanup_with_retype]] — drop in same commit).
3. `refactor(controller): throw AuthException::accountLocked from
   login paths` — controllers catch and respond; drop
   `PageState::loginFailureReason` slot.

**Verification:**
- `tests/Unit/Auth/AuthServiceTest.php` — update for new return shape.
- Browser smoke: bad-credentials login, account-lockout login, both
  WS (`pwg.session.login`) and form (`identification.php`) paths.
- psalm-info delta: small (−5 to −15) but resolves Review finding F5.

**Expected psalm-info delta:** −5 to −15. **Effort:** M.
**Blockers:** phase 4 helpful (typed PwgError envelope cleaner with
typed account-locked code) but not required. **Rollback:** clean per
commit; AuthResult DTO is additive.

---

## Phase 6 — Plugins admin modernization (cross-plan)

**Goal:** execute `.claude/plans/there-is-a-plan-prancy-castle.md` —
collapse Plugins/Themes/Languages admin god-classes (726/14, 692/16,
385/6 LoC/methods) into typed services with
`PluginRegistry`/`ThemeRegistry` as read source-of-truth. Unblocks
the final 6 `pwg.extensions.*` WS handlers.

**Scope:** entire prancy-castle plan. Out of detailed planning here —
the linked plan is the spec. This campaign plan only sequences it.

**Sub-commits:** per the prancy-castle plan.

**Verification per §1.7:**
- Full plan's verification + the 6 blocked WS handlers'
  `tests/Integration/WsApiTest.php` cases unblocked and passing.
- psalm-info delta: medium (−20 to −40) — drains 3 large god-class
  files.

**Expected psalm-info delta:** −20 to −40. **Effort:** XL (separate
plan). **Blockers:** none for prancy-castle itself; phase 7 may
overlap with audit-driven sweep — coordinate at phase-start.
**Rollback:** per the prancy-castle plan.

---

## Phase 7 — Audit-driven Projection sweep (Boundary 3)

**Goal:** drain 249 of 646 public repository methods still returning
bare `array`. Execute `docs/SQL-DTO-AUDIT.md` open IDs +
`docs/ARRAY-REFACTOR-AUDIT-4.md` remainder. Run a round-5 audit to
enumerate what remains.

**Scope:** bucket the 249 per ROADMAP §1.7 Boundary 3:
- **(a) already-tight via docblock** — caller `is_*` guard cleanup
  only; no repo-side change.
- **(b) tuple-shape** — promote `@return list<array{...}>` to a named
  Projection class.
- **(c) genuinely untyped** — 31 docblocks across 18 repositories
  (UserRepository alone has 5). Each needs a new Projection.
- **(d) orphaned dead docblocks** — confirmed at
  `Tag/TagRepository.php:192`, `Image/ImageRepository.php:316`;
  deletion only.

**Sub-commits:**
0. `audit(sql-dto): round 5 enumeration` — produces
   `docs/SQL-DTO-AUDIT-5.md` with bucket-by-bucket IDs for the 249.
   Read-only sweep; no code changes.
1-N. `feat(<repo>): <id range> — typed projections` — bundle by
   repository (UserRepository, ImageRepository, CategoryRepository,
   etc.), 1 commit per repo file. Each commit:
   - new `*Row.php` / `Projection/*.php` for bucket (c)
   - promoted tuples for bucket (b)
   - retyped methods
   - **paired** ([[feedback_cleanup_with_retype]]) caller `is_*`
     guard drops
   - **paired** ([[feedback_extract_state_object_not_phpstan_impure]])
     where the new shape replaces mutable handler slots.
N+1. `chore(repos): drop orphaned dead docblocks` — bucket (d)
     deletions. Single commit.
N+2. `refactor(callsites): drop mixed-lambda call sites` — sweep the
     291 `fn (mixed $v)` lambdas (95 files at plan time) that loose
     repo returns forced; many drop naturally with the repo retypes
     above, but a residue cleanup commit catches the rest.

**Verification:**
- For each repo migration: existing
  `tests/Integration/Repository/<Repo>Test.php` green; if missing,
  add a smoke test that exercises the new Projection from a real
  fixture row.
- `tests/Unit/{Image,Category,Tag,Comment}/Entity/*Test.php` (5 files,
  26 tests, 142 assertions) must stay green per ROADMAP §1.7
  verification block.
- psalm-info delta: target −400 to −600 — largest single contributor
  to the F5-k <50 gate.
- Removed `is_*` guard count: target several hundred.

**Expected psalm-info delta:** −400 to −600. **Effort:** XL (multiple
sessions; one repo per sub-session). **Blockers:** none.
**Rollback:** per-repo commits revert cleanly.

---

## Phase 8 — F5-b factory class extraction (Boundary 5)

**Goal:** extract 118 inline `factory(static fn …)` closures from
`config/container.php` into typed `*Factory.php` classes under
`src/Piwigo/Core/Container/` (new directory).

**Scope:**
- `config/container.php` — 118 factory closures.
- New: `src/Piwigo/Core/Container/` directory (does not exist yet).
- ~10 Factory classes if bundled by domain; could be ~20-30 if 1
  Factory per service for cleanliness — pick per F5 plan template.

**Sub-commits:**
1. `feat(container): introduce Core/Container/ + first Factory` —
   pick a small slow-moving service (e.g.
   `CategoryAdminServiceFactory`). Proves the pattern + autowiring.
   **`composer dump-autoload` after the new class**
   ([[feedback_composer_dump_after_new_class]]).
2-N. `refactor(container): <domain> factories extracted` — bundle by
   domain (Auth, Image, Category, Search, Tag, User, Plugins, Themes,
   Ws). 1 commit per domain.
N+1. `chore(container): drop residual inline closures` — final
     sweep, container.php should contain only DI metadata
     (parameters, autowire rules), no factory closures.

**Verification:**
- `tests/Integration/ContainerSmokeTest.php` — must stay green after
  each Factory extraction.
- Browser smoke per domain — instantiation paths exercised.
- psalm-info delta: small (−10 to −30) — factories themselves don't
  reduce mixed counts much, but tighten DI metadata typing.

**Expected psalm-info delta:** −10 to −30. **Effort:** L.
**Blockers:** none. **Rollback:** per-domain commits revert
cleanly; new directory is additive.

---

## Phase 9 — F5-c SessionStore rename (Boundary 4)

**Goal:** rename `SessionService` → `SessionStore` per F5-c plan;
cosmetic but in scope. 6 call sites to sweep.

**Scope:**
- `src/Piwigo/Session/SessionService.php` (87 lines) → `SessionStore.php`.
- Call sites: `UserAdminService`, `AuthService`,
  `MaintenanceController`, `UserService`, `SessionBootstrap`, the
  file itself.

**Sub-commits:** 1 commit. Rename + 6 import updates +
`composer dump-autoload`.

**Verification:**
- Session-handling integration tests
  (`tests/Integration/FastPathHeadersTest.php` etc.) green.
- Browser smoke: login → session persistence → logout cycle.
- psalm-info delta: 0 (cosmetic).

**Expected psalm-info delta:** 0. **Effort:** S.
**Blockers:** none. **Rollback:** trivial.

---

## Phase 10 — VO + Enum completion (cross-cutting, F5-a)

**Goal:** ship the ~4 remaining ValueObjects + ~6 remaining Enums to
close F5-a. Plan-named missing enums include `ImageLevel`,
`DerivativeType`, `Locale`, `Section`.

**Scope:**
- New VOs under `src/Piwigo/Common/ValueObject/` (~4): admin-side IDs
  + string-shape wrappers. Identify at phase start by re-grepping for
  remaining `int $userId` / `string $email` patterns not yet wrapped.
- New Enums under `src/Piwigo/Common/Enum/` (~6): `ImageLevel`,
  `DerivativeType`, `Locale`, `Section`, plus 2 to identify at phase
  start.

**Sub-commits:** 1 commit per VO/Enum. Each: class + unit test +
call-site sweep ([[feedback_cleanup_with_retype]]).

**Verification:**
- Unit tests per new VO/Enum (5-10 lines each).
- psalm-info delta: medium (−20 to −40) — each enum/VO collapses
  scattered string/int typed mixed accesses.

**Expected psalm-info delta:** −20 to −40. **Effort:** M.
**Blockers:** none — can interleave with other phases.
**Rollback:** per VO/Enum commits revert cleanly.

---

## Phase 11 — F5-k residue sweep

**Goal:** long-tail psalm-info burndown across controllers, admin
classes, residual repo internals after the named hotspots are drained.
Goal: cross the `<50` gate.

**Scope:** whatever's left after phases 1-10. Likely targets per
ROADMAP §1.7 acceptance-gates section:
- `CategoryRepository` (57 issues at baseline)
- `SectionInitializer` (56)
- `TelemetryService` body (55)
- `MiscController` (52)
- `MaintenanceController` (50)

These overlap with phase 3 (web DTOs) and phase 7 (Projection sweep)
— much will drain naturally. This phase catches the residue.

**Sub-commits:** audit-first commit produces a fresh hotspot list;
then 1 commit per remaining hotspot file.

**Verification:**
- Final commit must show
  `vendor/bin/psalm --show-info=true | grep 'Found.*errors'` < 50.
- All earlier gates still pass.
- `is_array(.* ?? null)` should be at or near 0 by this point.

**Expected psalm-info delta:** **drives the count under 50**
(whatever residue remains, ~50-300 issues depending on prior phases).
**Effort:** M-L (sized by residue). **Blockers:** phases 1, 3, 7.
**Rollback:** per-hotspot commits revert cleanly.

---

## Phase 12 — HttpKernel adoption (Boundary 1, longer horizon)

**Goal:** Symfony HttpKernel + HttpFoundation + standard
ArgumentResolver pipeline. Adds `symfony/http-kernel` dep; new
request-lifecycle events on the existing EventDispatcher (no second
bus). Request DTOs from phase 3 carry over unchanged.

**Scope:** architectural — separate plan file will be needed
(`.claude/plans/http-kernel-adoption.md`). Not detailed here.

Sequenced last because:
- It's a future architectural step, not a current prerequisite for
  §1.7's gates.
- The mixed-elimination work doesn't depend on it.
- Doing it before phases 1-11 would force every retype to land twice.

**Effort:** XL (multi-session, own plan). **Blockers:** ideally all
prior phases for clean migration. **Rollback:** plan-specific.

---

## Phase 13 — Symfony Validator adoption for web-side DTOs

**Goal:** layer `symfony/validator` + `symfony/serializer` onto the
web-side `*Request` DTOs from phase 3, replacing silent coercion in
`fromArray()` with declarative attribute-based constraints and structured
violation reporting.

**Why sequenced after phase 3, not instead of it:** phase 3 ships the
DTOs with `fromArray()` — matching the WS layer, zero new deps, fast to
land. Phase 13 upgrades those DTOs to Validator attributes once the
typed boundary already exists. This avoids blocking the mixed-elimination
gate on a dependency decision and gives us real DTOs to evaluate the
Validator against.

**Scope:**
- New deps: `composer require symfony/validator symfony/serializer`.
- ~30-50 `*Request` DTOs from phase 3 get `#[Assert\*]` attributes.
- New: `src/Piwigo/Http/Validation/` — violation-to-response mapper
  (structured JSON error envelope for admin forms, flash-message
  adapter for page controllers).
- `fromArray()` factories on web-side DTOs are replaced by Serializer
  deserialization + Validator validation. WS-side `*Params::fromArray()`
  stays unchanged (WS layer doesn't need Validator — its coercion
  semantics are intentional and match the legacy WS protocol).

**Sub-commits:**
1. `feat(deps): add symfony/validator + symfony/serializer` — deps only,
   wire into container. Smoke: `ContainerSmokeTest` green.
2. `feat(http): add validation violation → response mapper` — the
   infrastructure: takes `ConstraintViolationListInterface`, produces
   either a JSON error envelope or a flash-message redirect depending
   on request context. Unit-tested standalone.
3. `refactor(admin): migrate BatchManagerRequest to Validator
   attributes` — proof of pattern on the largest admin form. Replace
   `fromArray()` with Serializer + Validator. Browser smoke.
4-N. `refactor(admin): migrate <Controller>Request to Validator` — one
   commit per controller, same pattern. Each: add `#[Assert\*]`
   attributes, swap `fromArray()` call in controller for
   deserialize + validate, update unit test.
N+1. `chore: drop fromArray() from web-side DTOs` — final sweep once
     all DTOs migrated. WS-side `fromArray()` stays.

**Verification:**
- Browser smoke per admin form — Validator must reject bad input with
  structured feedback (not silent coercion). Test: submit empty
  required fields, submit wrong types, submit valid data.
- `composer analyse` green — Validator attributes are PHPStan-visible
  via `phpstan/phpstan-symfony`.
- `composer test` green.
- psalm-info delta: small (−5 to −15) — Validator tightens inference
  on validated DTOs but the major mixed-elimination win was phase 3.

**Expected psalm-info delta:** −5 to −15 (psalm-info gate should
already be met by phase 11). **Effort:** L. **Blockers:** phase 3
(DTOs must exist first). **Rollback:** per-controller commits revert
cleanly; deps revert with `composer remove`.

---

## Phase 14 — Repository query-objects

**Goal:** collapse N similar repository methods into composable
query-object DTOs that type the *input* side of repository queries
(complementing phases 7/10 which typed the *output* side).

**Why sequenced last:** query-objects don't reduce `mixed` or move the
psalm-info gate — they're a structural improvement that composes
filter parameters instead of multiplying method signatures. Doing them
after phases 7+10 means the output side is already typed, so each
query-object migration touches only the input shape, not both axes
simultaneously.

**Scope:**
- Repositories with the most method-signature duplication (same SELECT,
  different WHERE combos):
  - `ImageRepository` (~25 public methods — many differ only by filter)
  - `CategoryRepository` (~27 methods)
  - `UserRepository` (~20 methods)
  - `TagRepository` (~10 methods)
  - `CommentRepository` (~5 methods — may not benefit)
  - `SearchRepository` — already partially composed via `SearchRules`
    after phase 1; evaluate whether a query-object adds value on top.
- New: one `*Query.php` per repository that benefits. Shape:

```php
final readonly class ImageQuery
{
    public function __construct(
        public ?int $categoryId = null,
        public ?UserId $addedBy = null,
        public ?DateRange $datePosted = null,
        public ?DateRange $dateCreated = null,
        public SortOrder $sort = SortOrder::DateDesc,
        public int $limit = 100,
        public int $offset = 0,
    ) {}
}

// ImageRepository collapses:
//   findByCategory(), findByCategoryAndDate(), findByCategoryAndUser(),
//   findRecent(), findByDateRange(), ...
// into:
public function find(ImageQuery $query): PaginatedResult<ImageSummaryRow> { ... }
```

- Repos with genuinely distinct queries (different SELECT columns,
  JOINs, GROUP BYs) keep dedicated methods — the query-object only
  replaces the filter-combination explosion, not structurally different
  queries.

**Sub-commits:**
0. `audit(repos): identify query-object candidates` — read-only.
   For each repo, list which public methods share the same SELECT +
   JOIN shape and differ only by WHERE. Methods with unique SELECT
   shapes stay as-is. Produces a candidates list.
1. `feat(image): introduce ImageQuery + collapse filter methods` —
   proof of pattern on ImageRepository (largest). New `ImageQuery.php`,
   new `find(ImageQuery)` method, old filter-specific methods become
   thin wrappers (or delete if no external callers). Unit test.
2-N. `feat(<domain>): introduce <Domain>Query` — one commit per repo.
   Same pattern.
N+1. `chore(repos): drop thin wrapper methods` — once all callers
     migrated to query-objects, delete the wrappers that were left for
     back-compat.

**Verification:**
- All existing repo integration tests green after each migration.
- Per-repo: verify no caller was missed (search for old method name).
- `composer analyse` green (PHPStan validates query-object property
  types flow through to QueryBuilder parameters).
- psalm-info delta: minimal (0 to −10) — query-objects don't eliminate
  `mixed`, they consolidate method signatures.
- Browser smoke for repos that back admin pages (Category, Image).

**Expected psalm-info delta:** 0 to −10. **Effort:** L-XL (depends on
audit results — some repos may not benefit). **Blockers:** phase 7
(output typing should be done first). **Rollback:** per-repo commits
revert cleanly; query-object classes are additive.

---

## Cross-phase coordination

**Phases that can run in parallel** (with care):
- 1 + 4 + 5 (different files, different concerns)
- 7 + 10 (repo sweep + VO completion — VO additions feed Projection
  cleanups, so phase 10 should lead slightly)
- 8 + 9 (container work + cosmetic rename — fully orthogonal)

**Phases that block others:**
- 6 (plugins admin) blocks the final 6 WS handler cleanups from phase 2.
- 2 (typed Result envelope) is helpful before phase 11 to avoid
  double-touching handler files.
- 3 (web DTOs) blocks phase 13 (Validator adoption — DTOs must exist).
- 7 (projection sweep) blocks phase 14 (query-objects — output typing
  should be done before input typing).
- 11 must run before 12-14 (residue sweep needs core phases drained).

**Suggested ordering for execution:**

| Session | Phase / Sub-commit                                       |
| ------- | -------------------------------------------------------- |
| 1       | Phase 1 (F5-i deep adoption) — highest psalm-info payoff |
| 2       | Phase 2 sub-commits 1-2 (zero-params + explicit-union)   |
| 3       | Phase 2 sub-commits 3-5 (remainder + interface tighten)  |
| 4–N     | Phase 3 (1 controller per session)                       |
| N+1     | Phase 4                                                  |
| N+2     | Phase 5                                                  |
| N+3..M  | Phase 7 (1 repo per session) interleaved with Phase 10   |
| M+1     | Phase 6 (prancy-castle)                                  |
| M+2     | Phase 8                                                  |
| M+3     | Phase 9                                                  |
| M+4     | Phase 11                                                 |
| M+5+    | Phase 12 (separate plan)                                 |
| M+6+    | Phase 13 (Validator on web DTOs — requires phase 3 done) |
| M+7+    | Phase 14 (query-objects — requires phase 7 done)         |

**Documentation cadence:**
- After each phase: update ROADMAP §1.7 status snapshot table with
  fresh counts.
- After each phase: refresh `docs/F5-PENDING.md` to reflect newly
  shipped sub-codes / drained surfaces.
- After phase 1, 3, 7, 11: cross-check `psalm --show-info` total
  against the gate and update the ROADMAP gate-table row.

---

## Files this campaign will modify

Code:
- `src/Piwigo/Search/{SearchService,SearchFilterRenderer}.php` + new
  `Rules/{DateCustom,MysqlDateRange}.php` (phase 1)
- `src/Piwigo/Ws/{WsAction,PwgServer,PwgError}.php` + 88 new
  `*Result.php` (phases 2, 4)
- `src/Piwigo/Controller/Admin/*.php` + `Picture/*.php` + ~30-50 new
  `*Request.php` DTOs (phase 3)
- `src/Piwigo/Auth/{AuthService,AuthException,AuthResult}.php` +
  `Page/PageState.php` + controllers (phase 5)
- Plugins/Themes/Languages admin + registries (phase 6, per
  prancy-castle plan)
- 18+ repository files + ~31 new Projection classes (phase 7)
- `config/container.php` + ~10-30 new
  `src/Piwigo/Core/Container/*Factory.php` (phase 8)
- `src/Piwigo/Session/SessionService.php` → `SessionStore.php` +
  6 import updates (phase 9)
- ~4 new VOs + ~6 new Enums under `src/Piwigo/Common/` (phase 10)
- F5-k residue files (phase 11, sized at phase start)

Docs:
- `ROADMAP.md` §1.7 — status snapshot after each phase.
- `docs/F5-PENDING.md` — sub-code closures after each phase.
- `docs/SQL-DTO-AUDIT-5.md` — new, phase 7 commit 0.
- `.claude/plans/http-kernel-adoption.md` — new, before phase 12.

Tests:
- Unit tests per new DTO / VO / Enum / Projection / Factory class.
- Integration smoke per phase verification block.

---

## Verification — campaign-level

Final acceptance (closes §1.7 entirely):

```bash
# Acceptance gates
vendor/bin/psalm --show-info=true 2>&1 | tail -3    # target: <50
grep -rnE 'is_array\(.*\?\? null\)' src/ | wc -l    # target: 0
grep -rln '\$_SESSION\[' src/Piwigo | grep -vE 'Session\.php|FlashBag\.php' | wc -l  # already 0
grep -c 'factory(static fn' config/container.php    # target: 0
find src/Piwigo/Ws/Action -name '*Result.php' | wc -l  # target: ≥83

# Test gates
composer analyse
composer test
composer test:parallel
composer lint
```

When all 8 acceptance gates from ROADMAP §1.7 read ✅ Met, §1.7 closes.
Update the ROADMAP §1.7 status line from
`🟢 Active ▸ 5 boundaries opened, 3 substantially shipped` to
`✅ Closed YYYY-MM-DD ▸ mixed eliminated, psalm-info <50`.

---

## Non-decisions

None. Everything is in scope — phases 13 and 14 cover Symfony
Validator adoption and repository query-objects respectively.

## Scope boundary

Everything in the repo is in scope — `src/Piwigo/`, `config/`,
`tools/`, `themes/`, `template/`, `language/`, `docs/`. There is no
legacy `admin/` or `include/` directory. Each phase's scope section
is the boundary, not a directory whitelist. `themes/` is base Piwigo
(14 PHP files, currently zero mixed surface — but if a phase touches
theme services, those files are fair game).
