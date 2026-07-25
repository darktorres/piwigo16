# Fill the gaps in the already-done phases (P0–P23)

*Recovered 2026-07-24 — the original plan file was lost from `~/.claude/plans/` at some
point after approval; reconstructed from a job-recovery artifact and reconciled against
actual repo state below. Persisted here (not `~/.claude/plans/`) so it survives.*

## Progress log (updated 2026-07-24)

Verified directly against the live repo (schema file, source tree, git log), not assumed
from the original plan text — several things have drifted since this plan was written.

**Stage 0 — DONE, all 4 findings.**
- #7 (error-drain safety net): `ErrorCollector::drain()`, `/__test/errors` route
  (`TestErrorsController`), `IntegrationTestCase::assertNoPhpErrors()` all real.
- #16 (SEC-02 CLI guard): moot for `tools/build-config-accessors.php` specifically — that
  file was deleted outright in the Config generic-accessor removal (`feede75c9`).
  `manifest.yaml`'s `SEC-02` row now has the correct non-copy-pasted `verified_by` text.
- #9/#10 (doc hygiene): `docs/CONFIG.md` written (35KB); `manifest.yaml`'s doc-deliverable
  table already shows `created` for `DEVELOPMENT.md`/`DEPLOYMENT.md`/`RUNBOOK.md`/
  `plan/manifest.yaml`/`plan-lint`, not `planned`. `docs/PRIVACY.md` correctly still
  excluded (no `PrivacyService` exists).
- #11 (phase-tag hygiene): the note exists verbatim in `PLAN-REPLAY.md`'s Working-rule
  section (~line 173), explicitly citing finding #11.

**Stage 1a — 43 column migrations: mostly done, mechanism changed.** The plan assumes
these land as Doctrine Migration files; `src/Piwigo/Migrations/` no longer exists at all —
a later, independent decision (`212628d46`) replaced the whole Doctrine Migrations
mechanism with one static `install/piwigo_structure-mysql.sql`. Every "done" item below was
verified by reading that file directly, not by finding a migration.

| Category | Status |
| --- | --- |
| 11 enum→tinyint booleans | **DONE, 11/11** |
| 9 binary→utf8mb4_bin | **DONE, 9/9** |
| 8 `1970-01-01`→`NULL` defaults | **DONE, 8/8** |
| 6 TIMESTAMP NOT NULL additions | **DONE, 6/6** |
| 5 text→JSON | **DONE, 4/4 real columns.** `user_cache.forbidden_categories`/`image_access_list` turned out not to be serialize-leak candidates at all (comma-separated ID lists spliced into raw SQL, not `serialize()` blobs) — carved out as a separate future finding (see below). `config.value`, `search.rules`, `user_infos.preferences`, plus `activity.details` (a newly-found 4th column not in the original 43-item list) all fixed end-to-end — see "1a-bis" below. |
| 1 new column (`history_summary.summary_id` AUTO_INCREMENT PK) | **DONE** (`e05c1c92a`) — `summary_id int(10) unsigned NOT NULL auto_increment` is now the real PRIMARY KEY; `UNIQUE KEY history_summary_ymdh` kept alongside it. |
| 3 unsigned fixes | **DONE, the 1 confirmed candidate** (`e05c1c92a`) — `sites.id` is now `tinyint(4) unsigned NOT NULL auto_increment`. |
| Serialized-blob normalization (separate from the 43) | **DONE** (1a-bis item 1) — `extents_for_templates`/`updates_ignored` (plus `c13yIgnore`, found to have the same leak) now use their typed `CurrentConfig` accessors end-to-end, no manual `serialize()`/`unserialize()` left at any call site. |

**All ~40 concretely-named items are done.** Stage 1a is fully closed out.

**Stage 1b — Typed DTO/Projection pattern: 32 of 32 repositories done, 2026-07-25.**
(33 total minus `ConfigRepository`, already excluded per the plan — confirmed still the
sole Doctrine-ORM-backed repository.) Verified by checking which repositories actually
reference their own `Projection\*` class and call `::fromRow(`, not just which domain
directory happens to contain a `Projection/` folder (e.g. `Auth/Projection/ApiKey.php`
exists, but `AuthRepository.php`/`PasswordRepository.php` in the same domain didn't use
it until this pass).

- **Done via a real Projection (20):** `ActivityRepository`, `ApiKeyRepository`,
  `AuditRepository`, `AuthRepository`, `CategoryRepository`, `CommentRepository`,
  `GroupRepository`, `HistoryRepository`, `ImageRepository`, `MailRecipientRepository`,
  `MetadataRepository`, `NotificationByMailRepository`, `PermalinkRepository`,
  `PluginRepository`, `RateRepository`, `SearchRepository`, `SiteRepository`,
  `TagRepository`, `UserRepository`.
- **Done via a documented exception, no new Projection needed (12)** — re-framed the
  Stage 1b metric from "every repo has a Projection" to its own underlying goal, "no
  untyped `array<string, mixed>` row ever reaches a consumer that then re-implements the
  same defensive narrowing" — a repo can satisfy that without a class of its own:
  - **Write-only or scalar-only, no row shape exists to type:** `PasswordRepository`,
    `CaddieRepository`, `Cache/UserCacheRepository`, `SessionRepository`,
    `MenubarLayoutRepository` (already write-only since Stage 1a-bis),
    `Admin/Maintenance/DbMaintenanceRepository`.
  - **Genuinely dynamic caller-built SQL, shape varies per call, same precedent as
    `SearchRepository`'s own fragment-passing methods:** `SectionRepository`,
    `CalendarRepository`.
  - **Genuinely polymorphic across 3 heterogeneous tables (plugins/themes/languages,
    different real columns each), documented in the class's own docblock:**
    `Admin/Extensions/ExtensionRepository`.
  - **Every real return is a scalar list or a grouped scalar map, no row shape at all:**
    `PermissionRepository`.
  - **Trivial (≤2-field) shape, already zero-`mixed`-residue, exactly one real consumer —
    not worth a ceremonial single-purpose class:** `LangRepository::findAll()`,
    `FeedRepository::findById()`, `AuthRepository::findUsernameAndPassword()`.
  - **Genuine `GROUP BY` aggregate over a table, not a real row of it (same precedent as
    `Tag::countImagesPerTag()`):** `NotificationRepository::findRecentPostDates()`/
    `::findRecentCategoriesForDate()`.
  - **Deliberately-deferred full-row passthrough, no per-field access at the one real
    boundary that reads it:** `GroupRepository::findWithMemberCounts()` (feeds
    `Ws\PwgGroups::getList()`'s JSON response directly, same shape as
    `Tag::findCommonTags()`'s own deferral); `HistoryRepository::search()` (merges into a
    heterogeneous `$data` array for sort/CSV passthrough);
    `NotificationRepository::findRecentElementsForDate()` (returns a full `images` row
    that gets cached wholesale and consumed several methods later via an `is_array($element)`
    guard — converting it to a real object would have silently broken that guard, a real
    regression caught before landing, not after).
- **2026-07-25, batch 1 (Comment + Activity).** `CommentRepository` had exactly one
  row-returning method (`findForImage()`, a `LEFT JOIN` onto `piwigo_users` for the
  comment author's email) — every other method is a scalar/aggregate read, so a single
  `Comment\Projection\Comment` DTO (with the joined `userEmail` baked in as a real
  property, since that query has exactly one real caller and this is precisely its
  shape) closes the repository out. `PictureCommentRenderer::render()`'s consumption
  loop dropped its own `is_string()`/`is_numeric()` narrowing accordingly. Added
  `tests/Unit/Comment/Projection/CommentTest.php` (fromRow/toArray round-trip, matching
  `tests/Unit/Image/Projection/ImageTest.php`'s own precedent) — Comment domain's own
  Stage 1c zero-Unit-coverage gap closes in the same pass, per the plan's own
  instruction not to defer it.
  `ActivityRepository` had two row-returning methods, each a differently-shaped join
  already doing its own inline `array_map()` narrowing (not a plain `SELECT *`) --
  `findSystemObjectLogWithUsernames()` (`LEFT JOIN`, `details` decoded to `?array`, feeds
  `ActivityLogEntryFormatter::format()`) and `findUserObjectLogWithUsernames()` (`INNER
  JOIN`, `details` stays raw JSON text, feeds the CSV export) — given two distinct real
  shapes, not one. Became `Activity\Projection\SystemActivityLogEntry`/
  `UserActivityLogEntry`. `countByUser()`/`findMinOccuredOn()`/`findMaxOccuredOn()`/
  `findActionCounts()` stay raw (genuine aggregates, not table rows, same precedent as
  `Tag::countImagesPerTag()`). Activity domain already had real Unit coverage
  (`ActivityLogEntryFormatterTest.php`, pre-existing) — updated its `makeActivityRow()`
  test helper to build the new typed object directly instead of a raw array.
- Execution departed from the plan's suggested order (Image → Category → User → Tag →
  Comment → Activity → Rate → remaining 26) — Rate/Search/Site/PluginConfig/Permalink/Auth
  landed before Comment/Activity, which landed before the plan's own "remaining 26"
  step. Fine per the plan's own "detail worked out at implementation time" allowance.
- `BatchManagerUnitPageRenderer.php`'s stale-`$row` bug (lines ~364–374, called out as
  "fix along the way") was **misattributed in this plan's own text** — on inspection it's
  entirely `Tables::images()`/`ImageRepository` code (the file's own docblock documented
  it as a known, deliberately-not-fixed-here carry-forward from the original P23 port),
  not Comment or Activity; `ImageRepository`'s own Stage 1b pass (see "Done" above) had
  already happened and missed it. **Fixed 2026-07-25, as its own follow-up** (not bundled
  into Comment/Activity on the false premise): `$storage_category_id` is now computed
  fresh inside the per-image `foreach ($images as $row)` loop from that image's own row,
  instead of once beforehand from whatever `$row` an unrelated earlier loop happened to
  leave behind. A second, independent bug in the same 2 lines was found and fixed in the
  same pass: the comparison itself used `is_string($row['storage_category_id'] ?? null)`
  and a bare `===` against `$item_category_id` (`int|string` depending on the driver) —
  under Doctrine DBAL's native `int` return for this column, that `===` would have failed
  even with the correct row. Both sides now normalize to a real `int` before comparing.
- **2026-07-25, batch 2 (the remaining 20, closing Stage 1b out): 8 new Projections,
  12 documented exceptions** — user-directed "work through all of them, leave tests to
  the end." ("the end" turned out to mean *running* the suite, not *writing* the tests —
  clarified after the fact; 9 fromRow/toArray round-trip Unit tests, one per new
  Projection class, added in a follow-up pass and folded in below. 4 of the 8 domains
  (`Audit`, `Group`, `History`, `Metadata`) were on Stage 1c's own zero-coverage list —
  Stage 1c moves from 1/11 to 5/11 as a direct side effect.)
  - `Audit\Projection\AuditLogEntry` — `AuditRepository::findAllInOrder()`'s own inline
    `array_map()` narrowing (11 fields), single consumer (`AuditService::verifyChain()`).
  - `Mail\Projection\MailRecipient` — shared by `MailRecipientRepository`'s
    `findAdminsAndWebmasters()`/`findByGroupAndLanguage()` (same core `user_id`/`name`/
    `email` triple, `status` nullable since only one method selects it). `mailAdmins()`
    converts back to array via `->toArray()` immediately before `mail()`'s own `$to`
    param, which is a deliberately dynamic many-shapes contract shared with every other
    caller — not widened to also understand a real object.
  - `Metadata\Projection\MetadataImage` — shared by `MetadataRepository`'s
    `findImagesByIds()`/`findImagesByStorageCategoryIds()` (identical 3-field shape).
    Both real consumers (`MetadataService::syncMetadata()`,
    `SiteUpdateSubController`'s 2 callers via `getFilelist()`) treat the row as a
    growable data bag merging in exif/iptc fields before a batch write — `getFilelist()`
    keeps its own `array<int, array<string, mixed>>` contract, converting back via
    `->toArray()` at that boundary. Retyping surfaced 2 now-provably-redundant
    `assert(is_array(...))` + `@var` guards in `SiteUpdateSubController.php`, removed.
  - `Notification\Projection\UserMailNotification` —
    `NotificationByMailRepository::findUserNotifications()`, replacing ~20
    `$nbmUser['x']` accesses spread across `NotificationByMailSender`'s own several
    methods (some via a since-removed by-ref param, `setUserOnEnv(array &$nbmUser, ...)`
    → `setUserOnEnv(UserMailNotification $nbmUser, ...)`, since nothing ever mutated it)
    plus one more consumer block in `NotificationByMailSubController.php`. `userId`
    upgraded from the legacy "everything is `string|null`" convention (the repository's
    own docblock had documented this as deliberate, matching legacy
    `MysqliDb::fetchAssoc()`) to a real `int`, same direction every other domain's own
    Projection had already gone.
  - `Auth\Projection\AuthUser`/`AuthKeyDetails` — `AuthRepository::findByUsernameOrEmail()`/
    `::findAuthKeyDetails()`, both feeding **security-critical** paths
    (`AuthService::pwgLogin()`'s timing-attack-mitigated login,
    `AuthService::authKeyLogin()`'s auth_key/api_key login) — ported with extra care,
    field-by-field, preserving exact original semantics (the constant-time fake-user
    fallback via `??`, the `?->` nullsafe PHPStan itself then flagged as unnecessary
    since PHP's own `??` already suppresses "read property on null" for its left operand
    the same way it does `isset()` for array access — confirmed empirically before
    trusting it, not just following the suggestion blind). Zero existing test coverage
    for either method to update.
  - `History\Projection\HistorySummaryCursor`/`HistorySummaryCount` — 2 distinct classes
    (matching the `Activity` domain's own 2-distinct-shapes precedent), not one shared
    class with 2 always-one-null fields: `findLastSummaryWithHistoryIdTo()`'s and
    `findSummaryRowsForHierarchy()`'s own SELECT lists never overlap beyond
    `year`/`month`/`day`/`hour`. `findGroupedCountsSince()`/`search()` stay raw (a real
    aggregate and a genuine passthrough, respectively — see exceptions above).
  - `Group\Projection\Group` — `GroupRepository::findAllBasic()` (3 fields), consumed by
    `GroupListPageRenderer.php`. `findWithMemberCounts()` stays raw (see exceptions
    above).
  - **Follow-up: 9 fromRow/toArray round-trip Unit tests** (one per new Projection class,
    `HistorySummaryCursor`/`HistorySummaryCount` each getting their own — `Audit`, `Mail`,
    `Metadata`, `Notification`, `Auth` ×2, `History` ×2, `Group`), matching
    `tests/Unit/Image/Projection/ImageTest.php`'s established precedent. `AuthUser`'s own
    test also covers its one real behavioral deviation from a plain narrowing default:
    `status` falls back to `'normal'`, not `''`, matching the original's own
    `$user['status'] ??= 'normal';`.
  - Full verification: PHPStan/ECS/deptrac clean repo-wide; Unit+Arch 648/648 (619 + 29
    new test assertions' worth of test cases), Integration 668/668, Contract 94/94,
    Browser 68/68, Visual 34/34 (every suite but Unit+Arch unchanged, confirming no
    regressions from the new tests themselves).

**Stage 1c — Per-namespace Unit test coverage: 11 of 11 caught up, 2026-07-25.**
`Comment`, `Audit`, `Group`, `History`, and `Metadata` closed out in the earlier Stage 1b
passes (`tests/Unit/<Domain>/Projection/*Test.php`, fromRow/toArray round-trip). The
remaining 6 (`Site`, `Tag`, `Caddie`, `Calendar`, `Permission`, `Picture`) closed out in
this pass:

- **`Site`/`Tag`** — real `Projection` classes already existed from Stage 1b's own "Done
  via a real Projection" list, just missing their own round-trip test (an earlier pass,
  scoped to Comment + Activity only, never caught them up). Added
  `tests/Unit/Site/Projection/SiteTest.php`/`Tag/Projection/TagTest.php`, matching the
  established `ImageTest.php` 3-test pattern (`Tag` gets a 4th, for `fromRow()`'s own
  "tolerates an extra `counter` key" contract documented in its class docblock).
- **`Caddie`** — genuinely nothing pure to extract: `CaddieRepository::addElements()` is a
  DB-loop with no branching logic of its own, `CaddieService::fillCurrentUserCaddie()` is
  a 2-line resolve-and-delegate wrapper; both are already thoroughly covered at Integration
  level (`tests/Integration/CaddieRepositoryTest.php`, 5 tests). Documented as this stage's
  own version of Stage 1b's "write-only/scalar-only, no row shape" exception — forcing a
  DBAL-mock Unit test here would assert against the mock, not real behavior.
- **`Calendar`** — `CalendarService::buildInnerSql()` (the FROM/JOIN/WHERE fragment
  builder) has 2 branches that are pure string builders (no-category-context "browse
  everything visible," and the non-`categories`-section `WHERE id IN (...)` builder); the
  3rd (`hasCategoryContext=true` with a real category id) calls
  `CategoryService::getSubcatIds()`, a real DB read, and stays at Integration level
  (`CalendarServiceTest.php` there). Added
  `tests/Unit/Calendar/CalendarServiceTest.php` (5 tests), constructing real
  `PermissionService`/`CategoryService`/repositories (DBAL's `DriverManager::getConnection()`
  connects lazily, so building one without ever querying is safe — same shape as
  `tests/Unit/Image/ImageServiceTest.php`'s own precedent).
- **`Permission`** — `PermissionService::getSqlConditionFandF()` is the actual
  "permission-check logic" both Calendar and Picture's own gates depend on: a pure string
  builder reading `CurrentUser`/`FilterState`, zero DB access (per its own docblock).
  Added `tests/Unit/Permission/PermissionServiceTest.php` (11 tests), including its
  documented `visible_images`→`forbidden_images` switch fallthrough ("visible include
  forbidden") and `getPrivacyLevelOptions()`'s label-accumulation order.
- **`Picture`** — the flagged priority (`PictureCommentRenderer`'s documented prior
  `$edit_comment` scope-sharing bug). Two layers:
  - `Piwigo\Auth\AccessControl` (zero coverage anywhere despite backing every
    `U_EDIT`/`U_DELETE`/`U_VALIDATE` gate in the renderer) got its own thorough
    `tests/Unit/Auth/AccessControlTest.php` (13 tests) — the real "permission-check
    logic" the plan text calls out, pure reads of `CurrentUser`/`CurrentConfig`.
  - `tests/Unit/Picture/PictureCommentRendererTest.php` (4 tests) exercises `render()`
    itself (not an extracted helper) for its 3 DB-free branches: the no-commentable-
    category early return, and the "ugly spammer"/"Session expired" reject throws — real
    "comment add" rejection behavior, not a pixel diff. `PictureRateRendererTest.php`/
    `PictureMetadataRendererTest.php` (1 test each) cover those renderers' own
    config-disabled guard paths the same way. Every other branch needs a real
    `CommentRepository` row (`findForImage()`), so
    `tests/Integration/PictureCommentRendererTest.php` (3 tests, new — Picture had zero
    tests in *any* suite before this pass) directly re-verifies the historical bug fix:
    with 2 real comments on the same image, only the one matching the given
    `$editCommentId` ever gets `IN_EDIT`, plus the owner/non-owner/admin `U_EDIT`/
    `U_DELETE` wiring end-to-end against real rows.

Full verification: PHPStan/ECS/deptrac clean repo-wide; Unit+Arch 690/690 (648 + 42 new),
Integration 671/671 (668 + 3 new), Contract 94/94, Browser 68/68, Visual 34/34 (every
suite but Unit+Arch/Integration unchanged, confirming no regressions). The first
Integration run hit a MySQL deadlock (52 failures) from a concurrency mistake made while
verifying this stage (briefly running two Integration suites against the same DB at
once, not a real regression) — confirmed transient on an immediate clean re-run.

**Stage 1d — CachePools wiring: DONE, 2026-07-25.** The plan's own "3 items" framing was
stale — direct re-verification found 5 real pools, not 3: `categoryTree()` and `general()`
were both added later (Legacy Coupling Retirement work), neither mentioned in this plan's
original text.

- `config()` — already done (wired into `ConfigService::loadConfFromDb()`, `feede75c9`).
- `categoryTree()` — already done, discovered via this re-verification: wired into
  `CategoryService::getCategoriesMenu()` via `CategoryTreeCache` (a dedicated wrapper
  class, since `CategoryService` is constructed manually — no DI container — at ~10 call
  sites, so a pool dependency can't go on its own constructor).
- `permissions()` — wired this pass. Same "manually constructed at ~40 call sites"
  constraint as `CategoryService` applies even harder to `PermissionService`, so a new
  `Permission\ForbiddenCategoriesCache` wrapper (same shape as `CategoryTreeCache`) takes
  over `getForbiddenCategories()`'s per-user 30s-TTL caching. Only 3 of its 4 real call
  sites were retargeted onto it — `PictureModifyPageRenderer.php`,
  `BatchManagerUnitPageRenderer.php`, `Ws/PwgCategories.php` (all read-only
  authorization/display lookups, where the plan's own 30s staleness tradeoff is
  appropriate). The 4th, `UserService::generateUserCache()`, is deliberately **not**
  retargeted: it's the authoritative writer of `user_cache.forbidden_categories` (the very
  value this cache exists to avoid recomputing elsewhere) and must always read a fresh
  value, never a stale one from the pool it would otherwise feed.
- `tagCloud()` — wired this pass, replacing `TagService::getAvailableTags()`'s older
  `CurrentPersistentCache` mechanism directly (no wrapper class needed here —
  `TagService`'s caching branch is entirely self-contained inside one method, not
  something external callers wrap, unlike `permissions()`/`categoryTree()`). Same accepted
  tradeoff `CategoryTreeCache`'s own docblock already documents: a fixed 300s TTL replaces
  the old `cacheUpdateTime`-keyed immediate invalidation. `CurrentPersistentCache` remains
  very much alive elsewhere (`UserCacheInvalidator`, `RequestBootstrap`,
  `SearchFilterRenderer`, `NbmController`/`FeedController`, `SectionPopulator`,
  `NotificationByMailSubController`, `CalendarRenderer`, `MaintenanceActionDispatcher`) —
  this change is scoped to `TagService`'s one specific use, not a broader retirement.
- `general()` — genuinely a no-specific-target catch-all (its own docblock says so,
  unlike the other 4, none of which named a future consumer the way `config()`/`tagCloud()`
  originally did) — left unwired, not a pending item.

Verified: PHPStan/ECS/deptrac clean repo-wide; Unit+Arch 690/690 (unchanged), Integration
675/675 (671 + 4 new: `ForbiddenCategoriesCacheTest.php` and a new `TagServiceTest.php`
caching test), Contract 94/94 (`PwgCategories.php`'s own retarget exercised directly via
`pwg.categories.getList`), Browser 68/68, Visual 34/34.

**Stage 1e — die() elimination: DONE, 2026-07-25.** Re-verified the real scope directly
(token-level `T_EXIT` count, immune to the comment-vs-code confusion a naive `grep` hits)
before touching anything: still exactly 17 real calls, not 20 — the `UpgradeFrom_1_3_1.php`
"3 real calls, 1 file" item is moot (that file was deleted outright in Stage 0's legacy-
upgrade-chain removal, well before this stage started).

Added `Piwigo\Admin\Image\ImageProcessingException` (`extends \RuntimeException`, not
`Piwigo\Http\ResponseReadyException` — that class is deliberately for *expected* control
flow that must never reach Sentry, per its own docblock; these are genuine unexpected
failures that should). Converted all 17: `Admin/Image/ImageGd.php` (5),
`Admin/Image/PwgImage.php` (2), `Admin/Image/ImageExtImagick.php` (1),
`Admin/Upload/UploadService.php` (9). `Ws/PwgServer.php:91`'s `die(0)` stays excluded, still
tied to P26's future removal of the whole legacy WS server.

`Http\Middleware\ExceptionHandlerMiddleware` (catches/logs/Sentry-reports any `\Throwable`
reaching it for a real HTTP request) and Symfony Messenger's own consumer loop (the
`Job\BatchUploadJob`/`BatchUploadHandler` background-job caller) both already handle a
plain thrown exception correctly with zero new wiring needed — a strict improvement over
`die()`, which produced neither logging nor Sentry visibility and skipped every pending
`finally` block. Traced `ImageDerivativeController.php`'s own `catch (ResponseReadyException)
{ throw $e; } catch (\Exception) { ... }` block to confirm it doesn't swallow these: it's
scoped narrowly to the rotation-detection code above the real `new PwgImage(...)`/
`pwg_resize()` calls, which sit outside any local try/catch and propagate normally.

`tests/Arch/StructuralTest.php`'s die()/exit() allowlist updated: `ImageGd.php`/
`ImageExtImagick.php`/`PwgImage.php` entries removed entirely (0 real calls left);
`UploadService.php`'s count dropped from 10 to 1 (only its own unrelated, legitimate
IN_WS-branch `exit()` remains). The old "a hard die() is correct in both real callers"
comment justifying these entries was itself the audit's own "materially wrong" finding —
corrected in place, not just deleted.

New tests: `tests/Unit/Admin/Image/{ImageGdTest,PwgImageTest,ImageExtImagickTest}.php` (4
tests total; ImageExtImagickTest.php uses the real external `magick`/`convert` CLI already
available in this environment, matching `PwgImage::is_ext_imagick()`'s own detection —
skips cleanly if unavailable). `imagecreatetruecolor()`'s 3 failure branches and
`PwgImage`'s "no library available" branch stay untested: genuinely unreachable in this
environment without mocking a global function these classes have no seam to inject (GD is
real and always resolves). `UploadService.php`'s own 9 converted call sites
(`addUploadedFile()`/`addFormat()`/`prepareDirectoryStatic()`) stay untested directly too
-- reaching them needs a real DB row + filesystem permission tricks, disproportionate to
this stage's own scope; the existing Browser suite's real upload flow
(`PhotoUploadApiTest.php`, exercising `addUploadedFile()`'s success path end-to-end) gives
confidence the surrounding code wasn't disturbed.

Verified: PHPStan/ECS/deptrac clean repo-wide; Unit+Arch 694/694 (690 + 4 new), Integration
675/675 (unchanged), Contract 94/94 (unchanged), Browser 68/68 (unchanged, including
`PhotoUploadApiTest.php`'s real `addUploadedFile()` success-path exercise), Visual 34/34
(unchanged).

**Stage 1f — reset() arch-test coverage: DONE, 2026-07-25.** Re-verified the real scope
directly (not trusting the plan's stale "~29 classes, 23 untested, no exceptions" claim,
already 2 classes out of date): 31 classes now declare `public static function reset():
void`, 7 already arch-tested (the plan's baseline 6, plus `DeploymentPolicy` — added since,
under its own "set()/reset() are only called from tests/" title, missed by a naive count).

24 remained. Checking each for a real external caller (not just trusting "no exceptions")
found 2 genuine special cases the plan's own list couldn't have known about, since both
classes postdate it:

- `Db/DbCredentials.php` — a real production caller,
  `Admin\Install\InstallWizard::performInstall()`, reloading credentials right after
  writing a fresh `.env`/`.env.test` so the same request's later connection attempts see
  the new values instead of a stale cached one. Its own docblock claimed "test-only,"
  which was simply wrong — corrected in place rather than arch-tested; deliberately
  excluded from this stage's coverage.
- `Core/CurrentPaths.php` — `Core/Kernel.php`'s own `reset()` cascades into it (and
  `Kernel::reset()` is already verified test-only), so this is a legitimate non-tests/
  call, not a violation. Given its own filtered arch test instead of a plain one:
  `Kernel.php`'s one known cascade call is excluded by path, so any *other* new direct
  caller still fails it.
- `Users/CurrentUser.php` — a genuinely comment-only false-positive risk, not a real
  caller: `Bootstrap/RequestBootstrap.php` had a comment literally containing the
  substring `CurrentUser::reset()` (explaining why a narrower reset is used instead) --
  `findCallSites()`'s plain substring scan is comment-blind by design (unlike
  `countExitCallsPerFile()`'s real tokenization), so this would have been a false arch-test
  failure the moment the test existed. Reworded the comment to avoid the literal substring
  rather than weakening the scanner.

The other 22 classes needed no special handling: `Admin/LoadedPlugins`,
`Admin/Maintenance/FilesystemIntegrityChecker`, `Cache/CurrentPersistentCache`,
`Core/AdminContext`, `Core/ApiKeyRequestFlag`, `Core/CurrentLogger`, `Core/ErrorCollector`,
`Core/FilterState`, `Core/InstallationFlag`, `Core/Lang`, `Core/PageState`,
`Core/ProcessCache`, `Core/RequestMountDepth`, `Core/ServerTiming`, `Core/WsContext`,
`Lang/Translator`, `Mail/MailService`, `PluginConfig/EventDispatcher`,
`Section/SectionContextRegistry`, `Template/CurrentTemplate`, `Url/RootPathOverride`,
`Users/CurrentUser` all got the standard `"X::reset() is only called from tests/"` test,
verified individually (not just as a batch) to rule out any other false positive before
trusting a clean run.

Verified: PHPStan/ECS/deptrac clean repo-wide; Unit+Arch 717/717 (694 + 23 new arch tests),
Integration 675/675 (unchanged), Contract 94/94 (unchanged), Browser 68/68 (unchanged),
Visual 34/34 (unchanged).

**Stage 2 — FrankenPHP worker mode: NOT STARTED.** `docker/Caddyfile` still has no `worker`
directive; `public/index.php` has no `bootMinimal`/`frankenphp_handle_request` references.
Correctly sequenced after Stage 1f per the plan (worker-mode correctness depends on every
request-scoped static having a tested `reset()`).

**Stage 3 — Legacy import tool: NOT STARTED.** Still no `import:legacy`/`ImportLegacy`
reference anywhere. **Drift from the plan's own inventory:** it describes "8 existing
Symfony Console commands" including `SchemaDump` — that command no longer exists (removed
along with the whole Doctrine Migrations mechanism, same `212628d46` change that affects
Stage 1a). Only 7 commands exist today: `BackupCreate`, `BackupRestore`, `CacheClear`,
`MaintenanceOrphanTags`, `MaintenancePurgeHistory`, `MaintenancePurgeSessions`, `UserList`.

---

## Original plan text (as recovered, findings/design unchanged below)

## Context

`docs/PLAN-REPLAY-AUDIT.md` (17 findings) shows P0–P23 — every phase `docs/plan/manifest.yaml`
marks `status: done` — has real, unflagged gate failures underneath that `done`. The user wants
all of it fixed to the **most proper** standard (the real architecture `docs/PLAN-REPLAY.md`
describes for those phases, not a shortcut) and churn is not a concern. **P24 and everything
after is explicitly out of scope** — this plan only closes gaps in phases already claimed done,
it does not start new phase work.

That excludes several audit findings entirely, because they're genuinely un-started future-phase
scope, not gaps in a done phase:

- **#4** (P26/P29/P30 zero foundation), **#5** (P24/P25 Vite/TS), **#14** (P28 security features) —
  all P24+, correctly not built yet.
- **#6** (P31 plugin/theme contracts) and the **Listener/Subscriber half of #3** (16 classes,
  157 typed events) — read `PLAN-REPLAY.md`'s own P31 section: step 1 there is literally "157
  typed event classes + 16 Listener/Subscriber classes." P23's text claims this as done, which is
  false, but the *fix* is correcting that false claim, not building P31's own deliverable out of
  sequence (it would mean building typed events against a REST API and Latte templates that don't
  exist yet).
- **#12** (Deptrac uncovered classes / SCC arch test) — confirmed as P32's own step 2 in its
  own section, never claimed done elsewhere. Not this plan's problem.
- **#13** — a positive finding (doc understated progress), nothing to fix.
- Documentation table entries for `docs/FRONTEND.md`(P24)/`API.md`(P26)/`SECURITY.md`(P28)/
  `PLUGINS.md`+`EVENTS.md`(P31)/`STRUCTURE.md`(P32) correctly stay `planned`.

What's left is real backfill for phases already marked done: P0 (#7, #16), P8 (#15 worker mode),
P11/P23 (#8), P13/P17–23 (#10's two doc gaps), and P17–P23's own text (#1, #2, #3a, #3c, #9, #17).

## Stage 0 — Safety net + doc/config quick fixes

Small, independent, low-risk — do first so later stages get the benefit.

1. **Finding #7 — error-drain test safety net (P0 scope).** `ErrorCollector`
   (`src/Piwigo/Core/ErrorCollector.php`) has `collected()`/`reset()` but no `drain()`. Add
   `public static function drain(): array` (returns `collected()`, then clears the buffer). Add
   a `TestMode`-gated `GET /__test/errors` route calling `drain()`, returned as JSON. Add
   `IntegrationTestCase::assertNoPhpErrors()` per `PLAN-REPLAY.md`'s own sample (~line 307):
   fetch `/__test/errors`, assert empty. Wire `_data/logs/test_errors.log`: `ErrorCollector`
   writes to it additionally while active; `IntegrationTestCase` truncates it in `setUp()`.
   Build this before touching anything else so Stage 1's rewrite gets its benefit.
2. **Finding #16 — SEC-02 CLI guard (P0 scope).** Add the standard `PHP_SAPI !== 'cli'` guard
   (matching every other `tools/*.php` script) to `tools/build-config-accessors.php`. Also fix
   `docs/plan/manifest.yaml`'s `SEC-02` row — its `threat`/`verified_by` text is a stale
   copy-paste of SEC-01's ("Sensitive files served over HTTP"); the real definition (confirmed at
   `PLAN-REPLAY.md:470`) is "CLI guards on all `tools/*.php` scripts," and `status: done` is false
   until the code fix lands.
3. **Findings #9/#10 — doc hygiene (P13/P17–23 scope only).** Give `P23` a finer-grained status
   in `docs/plan/manifest.yaml` than the flat `done` (revisit to a clean `done` once Stage 1
   actually closes finding #3's gaps). Fix the stale "Documentation deliverables" table in
   `PLAN-REPLAY.md` (~line 120) for the entries that are wrong *today*: `docs/DEVELOPMENT.md`/
   `DEPLOYMENT.md`/`RUNBOOK.md`/`plan/manifest.yaml`/`tools/plan-lint` are marked `planned`
   despite existing — flip to `created`. Leave every P24+ doc row (`FRONTEND.md`, `API.md`,
   `SECURITY.md`, `PLUGINS.md`/`EVENTS.md`, `STRUCTURE.md`) alone — they're correctly `planned`.
   Write `docs/CONFIG.md` (P13) — confirmed genuinely ready to write: `Config::SCHEMA`
   (`src/Piwigo/Config/Config.php:80`) is real and populated, document its ~277 keys with
   types/defaults/sensitivity directly from it. **`docs/PRIVACY.md` is NOT the same kind of gap
   — investigated and reclassified, don't just write it:** the doc's own text (line ~3775) says
   this file documents `PrivacyService` (SEC-56: data export, right-to-erasure, consent
   records, retention-as-code) — and `PrivacyService` doesn't exist anywhere in the codebase
   (`find`/`grep` both confirm zero matches). Finding #10's "code landed, docs didn't" framing
   is wrong for this specific file: no code landed. Worse, the doc says this service is
   "exposed as REST endpoints (`/api/v1`, P26) + a user/admin UI (P29)" — both out of scope
   here. Writing `docs/PRIVACY.md` now would mean either documenting a nonexistent feature or
   quietly building an entire GDPR subsystem that was never confirmed to be already-done-phase
   scope. **Excluded from this stage** — leave `docs/plan/manifest.yaml`'s row as `planned`
   (correct as-is), and don't write the file.
4. **Finding #11 — phase-tag hygiene.** No code fix possible (history is history). Add a short
   note near the commit-tag convention in `PLAN-REPLAY.md` clarifying that `(p23)`/`(p24)` git
   tags don't reliably map to this doc's phase content — read against evidence, not the tag
   number.

**Verify:** `composer lint:php`, `vendor/bin/phpstan analyse`, `tools/pest-cleanup.sh` (new
`ErrorCollector`/`assertNoPhpErrors` coverage).

## Stage 1 — Finish what P17–P23 already claimed (findings #1, #2, #3a, #3c, #8, #15b, #17)

The largest stage. Everything here is backfill for phases the manifest already marks `done`.

### 1a. 43 column-type migrations (finding #3, third bullet)

`src/Piwigo/Migrations/` has the bootstrap migration (`Version20260711150857.php`) but the
promised follow-on type-alignment migrations were never written. **Use `PLAN-REPLAY.md`'s own
inventory directly (lines 2531–2554) — it names all 43 exactly; don't reconstruct it from the
audit's shorter spot-check or from re-deriving it via `install/schema/mysql.sql`, both of which
I did first while validating this plan and both undercounted:**

> **2026-07-24 note: this entire migration-file mechanism is obsolete.** `212628d46` deleted
> `src/Piwigo/Migrations/` entirely in favor of a static `install/piwigo_structure-mysql.sql`.
> The 43 items below are still the real target list — just edit the schema file (and its
> consumers) directly per item, one commit per logical group, instead of writing a migration.

- **11 enum→tinyint** (boolean columns): `categories.commentable`, `categories.visible`,
  `comments.validated`, `groups.is_default`, `user_cache.need_update`, `user_infos.enabled_high`,
  `user_infos.expand`, `user_infos.last_visit_from_history`, `user_infos.show_nb_comments`,
  `user_infos.show_nb_hits`, `user_mail_notification.enabled`.
- **5 text→JSON**: `config.value`, `search.rules`, `user_cache.forbidden_categories`,
  `user_cache.image_access_list`, `user_infos.preferences` — **all 5 are real, live work, not
  "3 real + 2 moot."** The audit's own claim that the 2 `user_cache` columns are moot because
  "`user_cache` itself was deleted in P23's cache-table rationalization" is **confirmed false**
  by direct read: `user_cache` the table is very much alive —
  `ImageVisibilityChecker.php:43` selects `forbidden_categories` directly from it, and
  `UserService.php` (~lines 746–865) both computes and writes `forbidden_categories` +
  `image_access_list` + `nb_total_images` into it via a raw string-built `INSERT`. Migration
  `Version20260711150901.php` also still adds this table's FK constraints. Do all 5, not 3.
- **9 binary→utf8mb4_bin**: `categories.permalink`, `images.file`, `old_permalinks.permalink`,
  `plugins.id`, `sessions.id`, `tags.url_name`, `user_feed.id`,
  `user_mail_notification.check_key`, `users.username`.
- **8 default changes** (1970-01-01 → NULL): `comments.date`, `history.date`,
  `images.date_available`, `old_permalinks.date_deleted`, `rate.date`, `sessions.expiration`,
  `upgrade.applied`, `user_infos.registration_date`.
- **6 TIMESTAMP NOT NULL additions — identified precisely** by reading `install/schema/mysql.sql`
  directly (it's a live `bin/piwigo schema:dump` snapshot of the current, not-yet-fixed schema,
  confirmed by its modern `int unsigned`-style DDL vs. the origin migration's `int(11)`-style):
  exactly 6 columns are declared `timestamp NULL DEFAULT CURRENT_TIMESTAMP` and should become
  `NOT NULL` — `piwigo_activity.occured_on` plus 5 `lastmodified` columns (categories, groups,
  images, tags, users — confirm the exact 5 tables by re-reading `install/schema/mysql.sql`
  lines 80/186/300/441/590 at execution time, since line numbers will drift once 1a's earlier
  migrations land).
- **1 new column** (`history_summary.summary_id` AUTO_INCREMENT) — confirmed:
  `install/schema/mysql.sql`'s `piwigo_history_summary` table has no primary key at all today
  (only a `UNIQUE KEY` on `year,month,day,hour`), matching the bootstrap migration's own comment
  that it "gains `summary_id` AUTO_INCREMENT PK in P17–P23."
- **3 unsigned fixes — only 1 confirmed with real confidence, 2 remain a judgment call.**
  `sites.id` (`tinyint NOT NULL AUTO_INCREMENT`, no `unsigned`) is the strong, confirmed
  candidate — every other AUTO_INCREMENT `id` column in the schema is declared `unsigned`
  (e.g. `activity.activity_id`, `audit_log.id`), making this a real, isolated inconsistency, not
  a deliberate choice. A schema-wide scan for non-unsigned numeric columns also surfaced
  `history_summary.nb_pages`, `derivative_settings.default_quality`, `derivative_size.*`, and
  `user_cache.nb_available_tags`/`nb_available_comments` as possible candidates, but none of
  these has the same "every sibling column is unsigned except this one" tell `sites.id` has —
  don't force a specific 2 more; apply the same "should never be negative + every structurally
  similar column elsewhere is unsigned" test at execution time and accept that the doc doesn't
  name them precisely.
- Separately (same doc section, **not** part of the 43 — verify fresh, don't assume either way):
  serialized-PHP-blob normalization. Investigated already: `extents_for_templates` is still a
  live serialized-array config value (`ExtendForTemplatesPageRenderer.php`,
  `Template.php:255`), `updates_ignored` is still live (`ExtensionUpdateChecker.php`,
  `UpdatesExtPageRenderer.php`) though `extension_ignored_updates` table already exists per
  migration `Version20260711150903.php` — meaning the table exists but the code hasn't been
  cut over to it yet. `derivative_settings`/`derivative_size` tables also already exist (same
  migration). This normalization work looks genuinely started-but-incomplete — worth its own
  short scoping pass at Stage 1a's start, not blind inclusion in the per-domain sequencing below.

One migration per logical group (not per column), following the existing
`Version20260711150857.php`/`...858.php` MySQL-raw-SQL + PostgreSQL-portable-API split. Every
consumer reading/writing the old representation (`'true'`/`'false'` strings, raw text blobs,
`BINARY` comparison semantics) gets updated in the same commit as its column — the same set of
call sites 1b's DTO work also touches, so sequence each domain's migration immediately before
that domain's DTO/repository pass, not as one separate 43-column mega-migration.

### 1a-bis. Delete the legacy upgrade chain; fix the config/search/user/activity serialize leak (2026-07-24)

**Stage 0 (delete the legacy upgrade chain) — DONE, commit `8224f23a3`.** Deleted
`DbPatch/` (127 files) and `VersionUpgrade/` (26 files) in full, plus `UpgradeRunner.php`/
`UpgradeService.php`/`UpgradeFeedRunner.php`/`public/upgrade.php`/`public/upgrade_feed.php`.
Found and fixed several real dead-code consequences along the way: `RequestBootstrap.php`
had an every-request check redirecting to the now-deleted `upgrade.php` on any
`piwigo_db_version` mismatch (removed); the `piwigo_upgrade` ledger table had zero readers
left, only `InstallWizard`'s write (table + write + `Tables::upgrade()` all dropped);
`checkUpgradeFeed`/`piwigo_db_version` `CurrentConfig` properties, `UpgradeFlow.php`,
`UpgradeRunDate.php`, and `ActivityRepository::findIdsByObjectAndAction()`/
`PageState::resetQueryCounters()` were all fully dead once their sole callers were gone.
`LegacyFileConf.php` was almost lost in the bulk deletion — rescued and relocated to
`Piwigo\Admin\Install\` since `InstallWizard`'s constructor genuinely still needs it.
Verified: full-repo PHPStan/ECS/deptrac clean; Unit 585/585, Arch 30/30, Integration
657/657, Contract 94/94, Browser 68/68, Visual 34/34. Stage 1 (the serialize leak fixes
below) starts next.

While closing out 1a's "5 text→JSON" item, investigation of every `serialize(`/
`unserialize(` call site in `src/Piwigo` found the real shape of the problem is bigger
than 5 columns, and includes one large, unrelated discovery. Two separable pieces of work:

**Finding 1 — the legacy in-place upgrade chain contradicts this codebase's own
documented architecture and should be deleted.** `docs/PLAN-REPLAY.md`'s "Migration path"
section states outright: *"This is a clean fork. No in-place upgrade from upstream Piwigo
is provided... there is currently no version-to-version upgrade mechanism for a shipped
install, since nothing has shipped yet."* The intended path for adopting an existing
Piwigo install was always a one-way `bin/piwigo import:legacy` data-import tool (unbuilt,
this doc's own Stage 3) — not a schema-patching upgrade chain. Yet
`src/Piwigo/Admin/Install/DbPatch/` (127 files) and `VersionUpgrade/` (26 files),
orchestrated by `UpgradeRunner`/`UpgradeService`/`UpgradeFeedRunner` and exposed via
`public/upgrade.php`/`upgrade_feed.php`, implement exactly that contradicted mechanism —
apparently carried over mechanically during the P17–P23 porting phases without anyone
catching the conflict. User direction: delete the whole chain.

**Finding 2 — several places store structured (array-shaped) data as a serialized blob
but leave encode/decode to client code instead of the owning class/repository.**
- `config.value` (`piwigo_config`, a classic EAV key-value table: `param` PK, `value
  TEXT`, ~289 independently-typed keys) has no schema-level typing at all — only
  `ConfigService`'s own hand-rolled `hydrate()`/`encode()` convention does.
  `updatesIgnored`/`extentsForTemplates`/`c13yIgnore` already have working typed
  `CurrentConfig` properties with automatic `serialize()`/`unserialize()` via that
  convention — the leak is that ~6 call sites still bypass them with manual
  `confUpdateParam($key, serialize($x))`. `blkMenubar` has a property, but it's typed
  `string` (the raw blob) instead of `?array`, unlike every other array-shaped property.
- `search.rules` and `user_infos.preferences` are genuinely, deliberately deferred — each
  Projection's own docblock says so explicitly.
- `activity.details` (`varchar(255)`, not in the original 43-item list) has the identical
  leak, found via this broader sweep rather than the audit's TEXT-only inventory.
- Two repository-bypass sites: `Ws/PwgCore.php` has its own raw SQL against
  `piwigo_search` (bypassing `SearchRepository`) and a raw `unserialize()` read against
  `activity.details` (bypassing `ActivityRepository`).

User direction: fix all of it in one pass, including `config.value`'s underlying EAV
design — not deferred. This is safe to do in one pass specifically *because* of finding 1:
deleting the legacy upgrade chain removes the ~30 frozen `DbPatch` scripts that write raw,
non-JSON strings to `config.value`, which was otherwise the real risk in converting that
column to JSON.

**Carved out as separate future findings, not part of this work:**
- `user_cache.forbidden_categories`/`image_access_list` are not this pattern at all —
  comma-separated ID-list strings spliced directly into raw SQL `IN (...)` clauses across
  15+ files (`CategoryService`, `PermissionService`, `Ws/PwgCategories`, `Ws/PwgImages`,
  `SearchService`, `TagService`, `CalendarService`, `CommentsController`,
  `PictureController`, `ActionController`, `NotificationService`, and more). Fixing this
  means redesigning SQL-building across all of them — a separate, much larger effort.
- The DBAL→ORM migration. `docs/PLAN-REPLAY.md`'s P14 audit note describes a "User
  decision" to migrate all ~27 domain repositories to real Doctrine ORM
  (`ServiceEntityRepository`), "tracked as a new `remediation:` initiative in
  `docs/plan/manifest.yaml`" — no such entry exists there. Stage 1b's own Projection work
  (10 domains done so far) doesn't address this either: every repository, done or not,
  still `extends AbstractRepository` and uses raw DBAL `QueryBuilder`. Real, unaddressed,
  separately-scoped gap.
- Prepared statements are not a concern either way — DBAL's `QueryBuilder::setParameter()`
  and Doctrine ORM's DQL layer both compile to real parameterized queries at the driver
  level already.

#### Design: delete the legacy upgrade chain

**Delete outright:**
- `src/Piwigo/Admin/Install/DbPatch/` — entire directory (127 files, including
  `DbPatchInterface.php`, `DbPatchRegistry.php`, `DatabaseConfigChanges.php`,
  `LegacyDbLayer.php`, `LegacyFileConf.php`, every `Patch*.php`).
- `src/Piwigo/Admin/Install/VersionUpgrade/` — entire directory (26 files, including
  `VersionUpgradeInterface.php`, `VersionUpgradeRegistry.php`, every `UpgradeFrom_*.php`).
- `src/Piwigo/Admin/Install/UpgradeRunner.php`, `UpgradeService.php`,
  `UpgradeFeedRunner.php` — verified each exists solely to orchestrate the deleted chain
  (`UpgradeService`'s 9 methods are all upgrade-only; none shared with fresh-install).
- `public/upgrade.php`, `public/upgrade_feed.php` — each is 100% orchestration of the
  deleted classes.
- `src/Piwigo/Db/DbCredentials.php`: delete `migrateFromLegacyFile()` and its private
  `extractLegacyValues()` helper only. **Keep `seed()`** — real, independent caller
  (`InstallWizard.php:192`, the fresh-install form-submission path).
- `tests/Browser/UpgradePathTest.php` — entire file, exists solely for the deleted flow.
- `tests/Unit/Db/DbCredentialsTest.php` — remove only the `migrateFromLegacyFile()` tests;
  keep `fromEnv()`/`current()`/`seed()` coverage.

**Update, don't delete:**
- `tests/Arch/StructuralTest.php` — remove the 2 die()/exit()-count allowlist entries for
  `VersionUpgrade/UpgradeFrom_1_3_1.php` (`=> 3`) and `UpgradeRunner.php` (`=> 2`) — this
  also simplifies Stage 1e below (its "3 real calls, 1 file" bullet for
  `UpgradeFrom_1_3_1.php` becomes moot once that file is gone; 17 image-processing calls
  remain in scope there, not 20).
- Docblock-only mentions of `UpgradeRunner` (contextual rationale, no real call — verified
  by direct read) in `Cache/UserCacheInvalidator.php`, `Cache/CurrentPersistentCache.php`,
  `Core/PageState.php`, `Image/ImageStdParams.php`, `Template/Template.php` — clean up so
  none assert a now-nonexistent class.

**Verify:** repo-wide grep for `DbPatch`, `VersionUpgrade`, `UpgradeRunner`,
`UpgradeService`, `UpgradeFeedRunner`, `migrateFromLegacyFile` (src/tests/public/docs/
composer.json/psalm.xml/phpstan.neon) confirms zero remaining references.
`vendor/bin/deptrac --no-progress`, then full Unit/Arch/Integration/Contract/Browser/
Visual — a deletion this size needs the full suite, not a scoped check.

#### Design: fix the serialize()/unserialize() leak

**1. `blkMenubar`/`updatesIgnored`/`extentsForTemplates`/`c13yIgnore`**

- `blkMenubar`: retype `private static string $blkMenubar = '';` → `?array` (default
  `null`) in `CurrentConfig.php`, matching every other array-shaped property.
  `BlockManager.php:88`'s `@unserialize(CurrentConfig::blkMenubar())` becomes a plain
  `CurrentConfig::blkMenubar()` call. `MenubarLayoutRepository::saveLayout()` keeps its
  deliberate raw-SQL write (documented "write half only, no DI dependency" scope) but
  switches its `serialize()` call to `json_encode()`, matching item 5's new `config.value`
  convention.
- `updatesIgnored`/`extentsForTemplates`/`c13yIgnore`: no property changes — retarget the
  leaking call sites onto the already-working typed accessor, dropping the manual
  serialize/unserialize: `ExtendForTemplatesPageRenderer.php:159` (write),
  `Admin/Extensions/ExtensionUpdateChecker.php:129` (write), `Ws/PwgExtensions.php:352,367`
  (write), `Admin/UpdatesExtPageRenderer.php` (read — verify current state at execution
  time), `Admin/Integrity/CheckIntegrity.php:51,276` (`c13y_ignore` read/write).

**Item 1 — DONE.** Execution found the plan text wrong about `c13yIgnore`: it was typed
`?string` (the raw serialized blob), the identical leak `blkMenubar` had — not "no property
changes needed" as assumed during planning. Retyped it to `?array` the same way, and fixed
both its read (`CheckIntegrity.php:50`) and write (`CheckIntegrity.php:275`) sites. Also
found a second `blkMenubar` leak site the plan missed — `MenubarPageRenderer.php` had its
own separate `unserialize(CurrentConfig::blkMenubar())` (the plan's file list only named
`BlockManager.php`) — fixed the same way. `updatesIgnored`/`extentsForTemplates` writes
retargeted onto `confUpdateParam($key, $arrayValue)` directly (dropping the manual
`serialize()` — `confUpdateParam()`'s own `encode()` already serializes array values).
Deliberately did **not** touch `MenubarLayoutRepository::saveLayout()`'s `serialize()` call
despite this item's own text above saying to switch it to `json_encode()` — doing so now,
before item 5's `ConfigService::encode()`/`hydrate()` rewrite lands, would desync that
write from the still-`serialize()`-based read/decode path and break every `blk_menubar`
read. Deferred to item 5, to land in the same commit. Verified: scoped PHPStan/ECS/deptrac
clean; full Unit 615/615, Arch clean, Integration 657/657, Contract 94/94, Browser 68/68,
Visual 34/34 (one Integration run hit a transient `piwigo_images` table-missing failure
from an unrelated concurrent-heavy-tools mistake mid-session, not a real regression —
confirmed clean on immediate re-run alone). Item 2 (`search.rules`) starts next.

**2. `search.rules`**

- Schema: `piwigo_search.rules` TEXT → JSON (`install/piwigo_structure-mysql.sql` +
  regenerate `tests/Fixtures/piwigo-17.0.sql`).
- `Search` Projection: `rules` becomes `?array`, decoded via `json_decode($row['rules'],
  true)` in `fromRow()`; remove the "deliberately deferred" docblock note.
- `SearchRepository`: retype `insertSearch()`/its read path to accept/return the real
  array, encoding via `json_encode()` internally.
- `SearchService`: `getSearchArray()`/`getValidatedSearchArray()`/`saveSearch()` drop their
  own `unserialize()`/`serialize()` calls.
- `Ws/PwgCore.php` (~lines 972–1002): replace the raw `INSERT`/`SELECT` against
  `piwigo_search` with a real `SearchRepository` call.

**Item 2 — DONE.** `Search::$rules` is `?array<string, mixed>` (string keys, not the
generic `array<int|string, mixed>` `ArrayHelper::safeJsonDecode()` returns -- filtered down
in `fromRow()`'s own `decodeRules()`, since every real writer, `SearchService::saveSearch()`,
only ever stores a string-keyed JSON object). `SearchRepository::insertSearch()` now takes
`array $rules` (`json_encode()`s internally) with `$createdOn`/`$createdBy`/`$searchUuid`/
`$forkedFrom` all made nullable (default `null`) so `Ws/PwgCore.php`'s ephemeral,
metadata-less insert doesn't have to fabricate values the original raw SQL never set.
Both raw-SQL bypass sites in `Ws/PwgCore.php::historySearch()` fixed: the single-row
INSERT/SELECT now goes through `SearchRepository::insertSearch()`/`findOneByClause()`; the
bulk multi-id lookup (previously string-concatenated `WHERE id IN (...)`, no parameter
binding at all) now goes through a new `SearchRepository::findRulesByIds()` using a real
`ArrayParameterType::INTEGER` bound array -- fixes a real SQL-string-splicing gap the plan
didn't call out, found while touching this exact clause for the leak fix. Verified: scoped
PHPStan/ECS/deptrac clean; full Unit 615/615, Arch clean, Integration 660/660 (3 new
`findRulesByIds()` tests), Contract 94/94 (`WsHistoryTest`'s `pwg.history.search` coverage
exercises the rewritten bypass sites directly), Browser 68/68, Visual 34/34. Also removed
a `SearchServiceTest.php` `safe_unserialize()` stub found dead during this item's work (zero
real callers anywhere in src/tests, pre-existing debt from an earlier P23 batch, not
something this item introduced). Item 3 (`user_infos.preferences`) starts next.

**3. `user_infos.preferences`**

- Schema: `piwigo_user_infos.preferences` TEXT → JSON.
- `UserInfo` Projection: `preferences` becomes `?array`, decoded via `json_decode(...,
  true)`; remove the "deliberately deferred" docblock note.
- `UserRepository::savePreferences()`: retype to accept `array`, encode via
  `json_encode()` internally.
- `PreferencesService::save()`: drop the manual `serialize()` call.
- `UserService::getUserData()` (~line 686): drop the manual `unserialize()` call and its
  "mixed-type-widening" workaround comment; retarget onto the Projection's decoded value.

**Item 3 — DONE.** `getUserData()`'s own 3-way raw JOIN never goes through the `UserInfo`
Projection (per that Projection's own docblock -- it's scoped to `findDefaultUserInfoRow()`
only), so its `unserialize()` call was swapped for `ArrayHelper::safeJsonDecode()` directly,
not "retargeted onto the Projection's decoded value" as this item's text assumed; the
Projection's own `preferences` retype is a separate, parallel fix for its one real caller.
Found and fixed a real, non-hypothetical data risk in the fixture: unlike `piwigo_search`
(started empty), `tests/Fixtures/piwigo-17.0.sql`'s `piwigo_user_infos` already had a live
`serialize()` blob for `webmaster` (`show_whats_new_16`'s acknowledgement flag) --
json_decode()-ing that under the new code would've silently produced `[]`, flipping the
admin dashboard's what's-new banner back on. Re-encoded that one row's data to its JSON
equivalent by hand (kept the column's own `CREATE TABLE` type as `text` for now, deferred
to the final regen) rather than deferring and finding out via a Visual Regression failure --
confirmed `admin-dashboard` baseline still holds. Verified: scoped PHPStan/ECS/deptrac
clean; full Unit 615/615, Arch clean, Integration 660/660, Contract 94/94, Browser 68/68,
Visual 34/34 (including the `admin-dashboard` baseline the fixture fix protected). Item 4
(`activity.details`) starts next.

**4. `activity.details`** (found via this sweep, not in the original 43-item list)

- Schema: `piwigo_activity.details` `varchar(255)` → `JSON` (verify no stored payload
  depends on the 255-char bound; JSON has no inherent cap).
- `ActivityRepository` has no Projection yet (full Stage-1b pass stays separately tracked
  in 1b below) — add narrowly-scoped encode/decode just for the `details` column's own
  read/write methods.
- `ActivityService.php:109`: drop the manual `serialize()` call.
- `Admin/Maintenance/ActivityLogEntryFormatter.php:37`: drop the manual `unserialize()`
  call.
- `Ws/PwgCore.php` (~line 679): replace the raw `@unserialize($row_details)` read (and its
  `str_replace()` pre-cleanup, if still needed under real JSON) with a repository-level
  read.

**Item 4 — DONE.** `findUserObjectLogWithUsernames()` (feeds the CSV export, dumps `details`
as one opaque column) deliberately kept `details: ?string` -- only `findSystemObjectLogWithUsernames()`
(feeds `ActivityLogEntryFormatter`, does structured `$details['key']` access) decodes to
`?array` now; `insertMany()` takes a real array and `json_encode()`s internally.
`Ws/PwgCore.php`'s bypass (`getActivityList()`/`pwg.activity.list`, a dynamic multi-filter
paginated query with no fixed WHERE clause matching either named repository method) kept
its own raw SQL -- narrowly scoped to swapping just the decode call
(`ArrayHelper::safeJsonDecode()` for `@unserialize()`), dropping the now-vestigial
`str_replace('`groups`'/'`rank`', ...)` pre-cleanup (a legacy-data workaround for
backtick-corrupted old serialize() blobs that JSON encoding can't produce). Found via this
sweep, not called out in the plan: this same `getActivityList()` query reads details across
*every* object type (no `object=` filter by default), not just `system` rows as first
assumed -- the fixture actually had 18 pre-existing `serialize()`-blob `piwigo_activity`
rows (not just the 1 `object='system'` row), all of which would have silently decoded to
`[]` under naive `json_decode()`. Re-encoded all 18 to their JSON equivalent via a throwaway
script (parses the fixture's mysqldump-style `INSERT ... VALUES (...),(...)` tuples
respecting quote-escaping, `unserialize()`s + `json_encode()`s each `details` field,
verified byte-identical elsewhere via diff and a scratch-DB reimport). Verified: scoped
PHPStan/ECS/deptrac clean; full Unit 615/615, Arch clean, Integration 660/660, Contract
94/94, Browser 68/68, Visual 34/34 (`admin-history`/`admin-maintenance` baselines, which
render `ActivityLogEntryFormatter` output, held). Item 5 (`config.value`) starts next.

**5. `config.value`'s EAV storage — real fix, not deferred**

Replace `ConfigService`'s per-PHP-type encoding convention (`'true'`/`'false'` strings,
numeric strings, `serialize()` blobs) with uniform `json_encode()`/`json_decode()`, then
retype `piwigo_config.value` from `TEXT` to `JSON`.

Real scope, precisely counted (not estimated): `install/config.sql` seeds only 52 of the
~289 config keys — the rest rely on their PHP default until first changed. Cross-checking
every seeded key's type against `CurrentConfig`:

| Type | Seeded rows | Rewrite needed? |
| --- | --- | --- |
| bool (`'true'`/`'false'`) | 38 | No — already valid bare JSON literals as stored. |
| int (`'10'`, `'12'`) | 2 | No — already valid bare JSON numbers as stored. |
| string | 10 | Yes — needs JSON quote-wrapping. |
| array (`serialize()` blobs) | 2 | Yes — needs re-encoding to real JSON. |

Only 12 rows need editing. The 10 string rows (verify unchanged at execution time):
`comments_order` (`ASC`), `gallery_title`, `page_banner`, `nbm_send_mail_as`,
`nbm_complementary_mail_content` (empty), `email_admin_on_new_user` (`none`),
`blk_menubar` (empty), `week_starts_on` (`monday`), `order_by`/`order_by_inside_category`
(`ORDER BY date_available DESC, file ASC, id ASC`). The 2 array rows: `extents_for_templates`
(`a:0:{}` → `[]`), `updates_ignored` (`a:3:{...}` → `{"plugins":[],"themes":[],"languages":[]}`).
No leading zeros, non-standard floats, or unicode-escaping edge cases in the real data.

Decide at execution time whether `blk_menubar`'s seed row should become JSON `null`
(matching its new `?array` default) or be dropped entirely (matching how most properties
have no seed row at all, relying on the PHP default).

This is safe as a single pass, not deferred, specifically because the legacy-chain
deletion above already removed every frozen script that wrote raw non-JSON strings to
this column — the only remaining raw writer is `MenubarLayoutRepository::saveLayout()`,
already covered in item 1.

Design:
- `ConfigService::encode()`: single `json_encode($value)` call for every non-null value,
  replacing the `is_array`/`is_bool`/else-`(string)` branches.
- `ConfigService::hydrate()`: since `json_decode($raw, true)` already returns a properly
  typed PHP value, most of the `match ($paramTypeName)` branches collapse to "decode once,
  coerce only where genuinely needed" — verify at execution time whether any coercion
  branch is still reachable once every write goes through consistent JSON encoding.
- Schema + seed-data edits as scoped above.

**Item 5 — DONE, with one real deviation from this plan text.**

`piwigo_config.value` stays `text`, not `JSON` -- confirmed live (`ERROR 3140 Invalid JSON
text`) that MySQL's JSON column type rejects non-JSON content outright, and 2 keys
genuinely can't be JSON: `derivatives`/`disabled_derivatives` hold real `DerivativeParams`/
`WatermarkParams` objects (`DerivativeParams` even declares its own `__serialize()` hook)
reconstructed via `unserialize()`'s object-identity-preserving behavior, which
`json_encode()`/`json_decode()` would silently destroy (every element becomes a plain
array/stdClass). `ConfigService::OBJECT_SERIALIZED_PARAMS` (`['derivatives',
'disabled_derivatives']`) carves these two out by param name in both `encode()`/`hydrate()`
-- everything else goes through uniform `json_encode()`/`json_decode()` as planned, and
`decodeScalar()`/`unserializeArray()` are gone entirely (folded into `hydrate()`'s
`match()`, now doing real type-guards instead of coercion since every value already
decodes to its own native type).

Real scope was bigger than the plan's 12-row estimate -- an automated verification script
(reflects on every `install/config.sql` row's real `CurrentConfig` setter type, checks
`json_decode()` of the stored value against it) caught the plan's row-count 2 rows short
and found 2 more the manual audit missed entirely: **`picture_informations`** (a `serialize()`
array the plan's own file list never counted) and **`mail_theme`**/**`index_search_in_set_action`**
(bare unquoted strings -- the second is a real oddity: a `?string`-typed property that
deliberately stores the *literal text* `"true"`/`"false"`, not a real bool, so its seed row
needed quoting even though it "looks like" one of the 38 already-fine bool rows). Total: 14
`install/config.sql` edits (11 planned + `picture_informations` + `mail_theme` +
`index_search_in_set_action`), `blk_menubar`'s seed row dropped entirely (relies on its
`?array` PHP default, matching how most properties have no seed row).

Found and fixed 2 real raw-SQL bypass sites that construct config rows outside
`ConfigService::encode()` and would have silently broken under `json_decode()`:
`InstallWizard.php`'s `secret_key` raw INSERT (now `json_encode()`s the hex string before
interpolating it) and `ImageRepository::tryAcquireLoungeLock()`/`findLoungeLockValue()`'s
`empty_lounge_running` lock (also switched the raw double-quote-delimited SQL literal to a
real parameterized statement, since a `json_encode()`d string's own quotes would otherwise
break that delimiter). Fixed `ConfigurationSubController.php`'s `picture_informations`/
`filters_views` write path, which manually pre-`serialize()`d the POST data into a string
specifically so a later "every `$_POST[param]` that's a string gets `confUpdateParam()`d"
generic save loop would accept it -- relaxed that loop to accept `array|string` and pass
the real array through, letting `encode()` do the encoding, matching the `MenubarLayoutRepository`
precedent from item 1.

`tests/Fixtures/piwigo-17.0.sql`'s own `piwigo_config` data needed the identical treatment
(a real runtime snapshot, not just install seeds) -- a second throwaway script decoded each
row per its old per-type convention and re-`json_encode()`d it (skipping the 2
`OBJECT_SERIALIZED_PARAMS` keys entirely, byte-identical), fixing 19 rows. Also found and
removed one fully orphaned row, `piwigo_db_version` (zero readers anywhere in `src/Piwigo`
-- Stage 0 deleted its `CurrentConfig` property and its `InstallWizard` writer; the fixture
row was just a stale pre-Stage-0 snapshot). Critically, **`tests/Browser/RegenerateFixtureTest.php`**
-- the actual source that regenerates this fixture file, not just a consumer of it -- had
the exact same stale-encoding bug in its own manual `$configEntries` overrides (a wrong
comment claiming "no JSON encoding needed"); fixed it to `json_encode()` each value too, or
every future `composer test:fixture-regen` run would have silently undone every hand-patch
above. `tests/Browser/Helpers/BrowserTestHelpers.php::setCustomLogo()` had the same bug
(a raw 3-key config write for Browser tests) -- found via a real `CustomLogoTest.php`
failure, not by inspection.

Verified: full-repo `vendor/bin/phpstan analyse` (802/802 files) clean, not just the scoped
touched-files pass, given the blast radius; ECS/deptrac clean. Full suite: Unit 615/615,
Arch clean, Integration 666/666, Contract 94/94, Browser 68/68, Visual 34/34 (including
`admin-config`/`admin-dashboard`/`admin-history`/`admin-maintenance` baselines). One
`RequestBootstrapBootConfigOnlyTest`/`NoPhotoYetRendererTest` failure round confirmed
transient (stale shared `piwigo_test` DB from before the fixture fix landed) plus one real
test-assertion fix (`no_photo_yet` is `?string`-typed, not bool -- its stored value is now
correctly JSON-quoted, `NoPhotoYetRendererTest.php`'s raw-byte assertion updated to match).
Stage 1 (items 1-5) is now fully complete; task #52 (fixture-regen + full suite) starts
next.

**Files:** `Config/CurrentConfig.php`, `Config/ConfigService.php`, `Menu/BlockManager.php`,
`Menu/MenubarLayoutRepository.php`, `Admin/ExtendForTemplatesPageRenderer.php`,
`Admin/Extensions/ExtensionUpdateChecker.php`, `Ws/PwgExtensions.php`,
`Admin/UpdatesExtPageRenderer.php`, `Admin/Integrity/CheckIntegrity.php`,
`install/piwigo_structure-mysql.sql`, `install/config.sql`,
`tests/Fixtures/piwigo-17.0.sql`, `Search/Projection/Search.php`,
`Search/SearchRepository.php`, `Search/SearchService.php`, `Ws/PwgCore.php`,
`Users/Projection/UserInfo.php`, `Users/UserRepository.php`,
`Users/PreferencesService.php`, `Users/UserService.php`, `Activity/ActivityRepository.php`,
`Activity/ActivityService.php`, `Admin/Maintenance/ActivityLogEntryFormatter.php`, plus the
Stage-0-deletion files listed above.

**Verify:** per sub-item, `php -l` + scoped `vendor/bin/phpstan analyse` + `vendor/bin/ecs
check --fix` + `vendor/bin/deptrac --no-progress`. One `composer test:fixture-regen` at the
end covering all four schema changes together. A dedicated pass confirming every
`CurrentConfig` property round-trips through the new JSON encoding — extend
`tests/Unit/Config/SchemaIntegrityTest.php`'s existing reflection-based sweep rather than
hand-writing per-property assertions. Once every sub-item lands: `composer test`
(Unit+Arch), `tools/pest-cleanup.sh --testsuite Integration` and `--testsuite Contract`,
`composer test:browser`, `composer test:visual` — one full pass, not per sub-item. One
commit per logical sub-item (chain deletion; Config-keys retarget; search.rules;
user_infos.preferences; activity.details; config.value redesign), `(p23)` scope tag.

**Full verification pass — DONE.** `tests/Unit/Config/SchemaIntegrityTest.php` gained a
5th reflection-based sweep: for every scalar/array-typed `CurrentConfig` property (skipping
`OBJECT_SERIALIZED_PARAMS` and union/object-typed setters), it calls
`ConfigService::encode()`/`hydrate()` directly via `ReflectionMethod` (both are pure
functions, no DB needed) on the property's own compiled-in default and asserts the
round-tripped value is unchanged, restoring every touched property afterward. Adversarially
verified the sweep itself: deliberately broke `hydrate()`'s `'int'` branch to always return
`0`, confirmed the test fails with a real per-property diff, reverted, confirmed it passes
again (1417 assertions).

Running the real `composer test:fixture-regen` (not just re-encoding the existing fixture
by hand, as the earlier items' throwaway scripts did) surfaced 3 more real bugs the
per-column work above didn't catch, because they're in **runtime write call sites**, not
seed data or fixture snapshots:
- **`MaintenanceActionDispatcher.php`**'s `lock_gallery`/`unlock_gallery` actions passed the
  *strings* `'true'`/`'false'` to `gallery_locked`, a `bool`-typed property. Harmless under
  the old convention (both `decodeScalar()` and the final string-cast collapsed to the same
  bytes either way) but a real, severe regression under JSON typing: `json_encode('true')`
  produces the JSON *string* `"true"`, which `hydrate()`'s `'bool'` branch's `is_bool()`
  check rejects, silently hydrating to `false` regardless of which action ran --
  `RequestBootstrap.php` gates the entire non-admin gallery lock on this value, so the
  actual "Lock gallery" admin feature would have silently stopped working. Fixed to pass
  real `true`/`false`.
- **`Template.php`**'s first-request directory-permission check wrote the real int `1` to
  `data_dir_checked`, a `?string`-typed presence marker -- same class of bug (a bare JSON
  number decodes to an int, not the string `hydrate()`'s `'string'` branch requires), lower
  severity here since the only consumer only ever checks `=== null`, which happens to
  behave identically whether the hydrated value is `''` or `'1'`. Fixed for correctness
  anyway.
- **`MenubarLayoutRepository::saveLayout()`**'s raw SQL was a plain `UPDATE ... WHERE param
  = ?`, not an upsert -- it only ever worked because `blk_menubar` always had a seed row
  (even if just an empty string) to match against. Item 1 above dropped that seed row
  (relying on the property's own `?array` default instead, matching how most properties
  have no seed row at all) without checking this dependency, so a fresh install's very
  first "save menubar layout" silently affected zero rows. Found via the regenerated
  fixture's own `MenubarLayoutRepositoryTest` failing (`SELECT` after `saveLayout()` found
  no row at all, not a decode problem). Fixed to a real `INSERT ... ON DUPLICATE KEY
  UPDATE` upsert -- the correct fix, not restoring the seed row, since a plain `UPDATE`
  depending on unrelated seed data existing was always fragile.

Also found and fixed one pre-existing, unrelated-to-config-value fragile test
(`DbMaintenanceRepositoryTest::test_purge_sessions_for_deleted_users_keeps_sessions_for_real_users()`)
that asserted the *entire* `piwigo_sessions` table's exact row count instead of the
specific invariant `purgeSessionsForDeletedUsers()` actually guarantees -- it happened to
pass against every previous fixture snapshot (which coincidentally had exactly 1 session
row) and broke the moment a regen produced more incidental anonymous sessions. Rewritten to
assert the real invariant (no session is purged; the real user's session specifically
survives) regardless of how many incidental sessions exist.

Verified: full-repo PHPStan clean, ECS/deptrac clean, and — after all the fixes above —
one final clean pass of the complete suite: Unit 616/616 (2586 assertions, +1 test file
+1 test), Arch clean, Integration 668/668, Contract 94/94, Browser 68/68, Visual 34/34.
Stage 1a-bis (Stage 0 + Stage 1 items 1-5 + this full verification pass) is now
**completely done**.

### 1b. Typed DTO/Projection pattern (finding #1 — the biggest unmet claim)

33 of the 34 repositories (`find src/Piwigo -iname '*Repository.php'`) extend
`AbstractRepository` (`src/Piwigo/Db/AbstractRepository.php`) and return raw
`array<string,mixed>` from `fetchAssociative()`/`fetchAllAssociative()`. **The 34th,
`Config/ConfigRepository.php`, is already correctly typed** — it extends Doctrine's
`EntityRepository<ConfigEntry>` and returns real `ConfigEntry` entities via `find()`/`findAll()`,
confirmed by direct read; exclude it from this work, it's the one repository already done (the
audit's own finding #1 flags it as the sole `ServiceEntityRepository` exception). Double-check
the other 33 for any other repository that might already be ORM-backed the same way before
assuming all 33 need the treatment. 46 files manually
re-implement the same defensive narrowing (`is_string($row['x']) ? ... : default`) that a
`fromRow()` factory should centralize — see `ImageRepository::findLoungeLockValue()`,
`findMaxRanksByCategory()`, etc. for the pattern being worked around.

**Design** (per `PLAN-REPLAY.md`'s own P17–P23 reference: 7 Entity types, 73 projection shapes):
for each repository, define a `readonly` `Entity`/`Projection` class in a `Projection/`
sub-namespace next to it, with `public static function fromRow(array $row): self` doing the
narrowing exactly once. Repository query methods return the typed object/`list<T>` instead of
`array<string,mixed>`. Real typed accessor objects — not PHPDoc-only array shapes.

**Sequencing, domain by domain, so 1a's schema fix lands before its own DTO is typed against
it:** Image → Category → User → Tag → Comment → Activity → Rate → remaining 26 repositories
(grouped by the existing L2a→L2b deptrac layering; `ConfigRepository` excluded per above). Per
repository: migrate its schema slice
(1a), add its `Projection` (check it lands inside the existing deptrac layer regex for that
namespace — sub-namespaces match by prefix, but verify), retype the repository's methods,
retype every consumer (search `grep -rln 'is_string($row\[' src` per domain — sandboxed grep
silently drops `$`-containing patterns, cross-check with Python per the audit's own methodology
note), delete the now-redundant defensive narrowing.

**Fix the real bug found along the way:** `BatchManagerUnitPageRenderer.php` lines 364–374 —
`$storage_category_id` is computed from a stale `$row` left over from an earlier, unrelated
loop. Fix as part of migrating that file's repository, not as a separate patch.

### 1c. Per-namespace Unit test coverage (finding #2)

11 namespaces have zero `tests/Unit/` coverage (`Audit`, `Caddie`, `Calendar`, `Comment`,
`Group`, `History`, `Metadata`, `Permission`, `Picture`, `Site`, `Tag`); `Picture` has zero
tests in *any* suite. Write Unit tests **as part of each domain's 1b pass**, not as a separate
sweep — the DTO/repository retyping is exactly when a domain's behavior is being touched and
verified anyway. `Picture` is the priority: `PictureCommentRenderer.php` (documented prior real
bug — a scope-sharing `$edit_comment` issue), `PictureMetadataRenderer.php`,
`PictureRateRenderer.php` need real behavioral Unit tests for comment add/edit/delete and
permission-check logic, not just the existing `VisualRegressionTest.php` pixel diff.

### 1d. CachePools full wiring (finding #8)

`CachePools::permissions()`/`tagCloud()`/`config()` (`src/Piwigo/Cache/CachePools.php`) have
zero callers. Wire `permissions()` into `PermissionService::getForbiddenCategories()` (its own
docblock already names this as the intended consumer — also called from
`ImageVisibilityChecker`, `PictureModifyPageRenderer`, `BatchManagerUnitPageRenderer`,
`Ws/PwgCategories.php`, `UserService`). Wire `tagCloud()` into `TagService::getAvailableTags()`
(currently on the older `CurrentPersistentCache` mechanism — replace it). `config()` has no
identified real consumer; leave unwired but document why, unless 1b's work surfaces one.

> **2026-07-24 note:** `config()` now has a real consumer — wired into
> `ConfigService::loadConfFromDb()` as part of the unrelated Config generic-accessor removal
> work (`feede75c9`), not as part of this plan. `permissions()`/`tagCloud()` are still unwired.

### 1e. die() elimination (finding #17 — audit's count fully re-derived, materially wrong)

The audit's "34 real `die()` calls across 13 files ... all inside genuine mid-request
image-processing failure paths" **does not survive direct verification.** I reproduced its exact
34/13 figures with a naive `\bdie\s*(` scan over `src/Piwigo` — but that scan counts *every*
textual match, including docblock/comment prose that merely *mentions* `die()` (e.g.
`ResponseReadyException.php`'s own docblock explaining why raw `die()` is a problem,
`PictureCommentRenderer.php`'s comment about a past refactor). Filtering those out (a match is
only real if the line isn't a comment) drops it to **21 real calls across 6 files** — and one of
those 6 isn't image-processing at all:

- **17 real calls, 4 files, genuinely image-processing (fix these — Stage 1e's actual scope):**
  `Admin/Upload/UploadService.php` (9), `Admin/Image/ImageGd.php` (5),
  `Admin/Image/PwgImage.php` (2), `Admin/Image/ImageExtImagick.php` (1).
- **3 real calls, 1 file, real debt but a different category — include in this stage anyway,
  it's cheap and the same underlying problem:** `Admin/Install/VersionUpgrade/
  UpgradeFrom_1_3_1.php` (~lines 595/599/602) — a one-time legacy-version upgrade step patching
  `config/database.inc.php`, not image processing. Confirmed by direct read.
- **1 real call, explicitly EXCLUDE — do not touch:** `Ws/PwgServer.php:91`, `die(0)` inside the
  legacy WS server's malformed-response-format error path. This sits one line after a
  `var_export($this)` call — the exact SEC-37 issue `PLAN-REPLAY.md`'s P26 section already
  documents as resolved by *deleting* `PwgServer` entirely (P26 removes the whole legacy WS
  API). P26 is out of scope here, and rewriting this call now would be pointless churn on code
  a future, out-of-scope phase deletes wholesale — leave it and note why, don't convert it.
- The other 7 files the naive scan matched (`VersionUpgradeInterface.php`,
  `PictureCommentRenderer.php`, `Controller/PictureController.php`,
  `Controller/PopuphelpController.php`, `Controller/Admin/AdminPopuphelpController.php`,
  `Http/ResponseReadyException.php`, `Search/SearchService.php`) have **zero** real `die()`
  calls — every match in them is a comment. Confirmed by reading every line the scan flagged.
  My own first-pass file list before this check (derived from a plain `grep -c "die("`) was
  *also* wrong in a different way — it silently included `Tables::caddie()`/`CaddieService`/
  `CaddieRepository` matches, because the substring `"caddie("` contains `"die("`. Neither a
  naive `grep` count nor the audit's own count can be trusted here without per-line
  classification; the 21/6 (17+3+1) split above is the fully verified real scope.

Replace the 20 in-scope calls (17 image-processing + 3 upgrade-patch) with the existing
exception-based flow (`Piwigo\Http\ResponseReadyException`, whose docblock explains why raw
`die()`/`exit()` skips pending `finally` blocks) — a dedicated `ImageProcessingException` for
the 17, and whatever fits the `UpgradeFrom_1_3_1.php` install-flow's existing error-handling
convention for the 3. Do this after Stage 0's `assertNoPhpErrors()` safety net exists. A broader
`grep -rn 'die(\|exit('` also matches legitimate CLI exit codes and `RequestBootstrap`'s own
catch-and-emit `exit;` — neither is this finding.

### 1f. State-isolation arch-test coverage (finding #15, testable half)

29 classes now have a `reset()` method; only 6 are arch-tested via
`tests/Arch/StructuralTest.php`'s "`X::reset()` is only called from tests/" pattern (down from
13/5 at a 2026-07-13 baseline — 38%→21% as the codebase grew). **Confirmed clean by direct
read: all 23 untested classes declare the identical `public static function reset(): void`
signature** (`Core/ServerTiming`, `Core/AdminContext`, `Core/WsContext`, `Core/CurrentPaths`,
`Core/FilterState`, `Core/ApiKeyRequestFlag`, `Core/RequestMountDepth`, `Core/PageState`,
`Cache/CurrentPersistentCache`, `Core/ProcessCache`, `Core/CurrentLogger`, `Core/Lang`,
`Core/InstallationFlag`, `Admin/LoadedPlugins`, `Section/SectionContextRegistry`,
`Admin/Maintenance/FilesystemIntegrityChecker`, `Users/CurrentUser`, `Url/RootPathOverride`,
`Mail/MailService`, `Template/CurrentTemplate`, `PluginConfig/EventDispatcher`,
`Lang/Translator`, plus `ErrorCollector` which Stage 0 already touches) — no exceptions, no
different reset semantics to special-case. Add the same arch-test pattern for all 23 now. (The
other half of finding #15 — FrankenPHP worker mode — is handled in Stage 2 below, deliberately
after this closes.)

**Verify Stage 1:** `composer lint:php` + `vendor/bin/phpstan analyse` + `tools/pest-cleanup.sh`
(Unit/Arch) per domain as each lands (one verify pass per domain, not per file);
`composer test:integration` once all domains are retyped (catches cross-domain consumers a
per-domain pass might miss); `composer test:browser`/`test:visual` at the end of the stage.
Update `docs/plan/manifest.yaml`'s `P23` status back to a clean `done`, and correct finding #3's
Listener/Subscriber bullet in `docs/PLAN-REPLAY-AUDIT.md` to point at P31 explicitly (not "an
open P23 gap"), only once 1a–1d actually close the rest.

## Stage 2 — FrankenPHP worker mode (finding #15, first half — P8 backfill)

Confirmed starting point by direct read: `Dockerfile:32` already bases the production image on
`dunglas/frankenphp:1-php8.5`, and `docker/Caddyfile` is real, SEC-01-hardened, current
infrastructure — but contains no `worker` directive anywhere in it, and `public/index.php` has
zero references to `bootMinimal` or `frankenphp_handle_request()` (both greps confirmed empty).
So this is genuinely still classic per-request execution end to end, exactly as finding #15
says — not partially started anywhere. Sequenced after Stage 1f deliberately: worker mode's
correctness depends on every request-scoped static having a real, tested `reset()`, which
Stage 1f closes for all 29 current classes. Needs a short focused design check at the start of
this stage (request-loop shape; what `finalize()`/`reset()` ordering a persistent worker process
needs that today's per-request boot in `RequestBootstrap`/`Kernel` doesn't) before
implementation — that design question is genuinely open (it depends on exactly how
`RequestBootstrap::bootEntryPoint()` and `Kernel::boot()`/`reset()` are structured once Stage 1
lands, not on anything uninvestigated today).

## Stage 3 — Legacy import tool (`bin/piwigo import:legacy`)

The manifest's one `adoption` entry: `depends_on: [P15, P23]`, both already-done phases —
genuinely unblocked, entirely unbuilt. Confirmed a completely blank slate: no
`import:legacy`/`ImportLegacy`/`LegacyImport` reference anywhere (`grep` across `src`/`bin`
returns nothing), and `src/Piwigo/Command/` has exactly 8 existing Symfony Console commands
(`BackupCreate`, `BackupRestore`, `CacheClear`, `MaintenanceOrphanTags`,
`MaintenancePurgeHistory`, `MaintenancePurgeSessions`, `SchemaDump`, `UserList`), none related —
so there's no partial implementation to reconcile with. Design it against Stage 1's now-typed
repository/DTO layer (building it against the old raw-array API first would mean an immediate
rewrite). The genuinely open design questions (source format — a `16.x-rewrite`/legacy Piwigo
DB dump vs. a different export shape; conflict/resume semantics for a partial re-run) aren't
resolvable from investigation, since no source-format decision has been made anywhere in the
docs — that's a real design pass to have at the start of this stage, not a gap in my research.

> **2026-07-24 note:** `SchemaDump` no longer exists (removed alongside the Doctrine
> Migrations mechanism, `212628d46`) — 7 commands remain today, not 8.

## Stage 4 — Delete `user_cache` + `user_cache_categories` (2026-07-25, findings #3c/#8 continuation)

Not a new finding — a genuine P23 shortfall, same species as Stage 1's own backfill work.
`docs/PLAN-REPLAY.md`'s P23 "cache table rationalization" section promises `user_cache`/
`user_cache_categories`/`history_summary` deleted entirely. A "Batch 3" progress note shows
partial historical execution — 3a (`user_cache_categories` existence-filter reads) and 3b
(`CategoryRepository::findMenuCategories()` → `CategoryTreeCache`) done; 3c
(`history_summary`) honestly flagged `deferred`. `user_cache` itself was never attempted —
not flagged deferred, just silently missing. Direct-code investigation found the true scope
is a live 10-column table, a still-alive sibling table, 6 cache-invalidation call sites
beyond the table itself, and one still-active distributed-lock/wait/503 mechanism, not just
"a few raw SQL splices." Real `piwigo_user_cache` columns (`install/piwigo_structure-mysql.sql`):
`user_id`, `need_update`, `cache_update_time`, `forbidden_categories`, `nb_total_images`,
`last_photo_date`, `nb_available_tags`, `nb_available_comments`, `image_access_type`,
`image_access_list`. All 10 traced to their real current readers/writers below.

### 4a. The `cacheUpdateTime`-keyed invalidation pattern → pure TTL pools

`User::$cacheUpdateTime` (sourced from `user_cache.cache_update_time`) is used as a
cache-busting key *component*, still on the older `CurrentPersistentCache`/
`PersistentFileCache` mechanism (`make_key()` + `get()`/`set()`) — the exact shape
`TagService::getAvailableTags()` was on before Stage 1d converted it to
`CachePools::tagCloud()` (that commit's own comment, "of the previous
cacheUpdateTime-keyed immediate invalidation," is the direct precedent here, not a new
pattern). Re-grepped precisely rather than trusting the original 5-site estimate — the
real count is higher in one file:

- `Section/SectionPopulator.php:337-338` — one `all_iids` key (per-user visible image-id
  list for a section). `$persistent_cache` (`CurrentPersistentCache::get()`) has no other
  use anywhere else in this file.
- `Search/SearchFilterRenderer.php` — **8 distinct cache-key sites**, not 1: `filter_author_rows`
  (263), `filter_added_by_rows` (383), `file_exts` (521), `filter_ratings` (586),
  `filter_ratios` (705), `filter_height_rows` (786), `filter_width_rows` (850), plus
  `renderDateFilter()`'s shared key (1080, used by the date_posted/date_created call sites
  at 320/344) — all built off the single `$userCacheUpdateTime` captured once at line 116,
  all through the same `CurrentPersistentCache::get()` instance with no other use in the
  file.
- `Search/SearchService.php:1049` (`getQuickSearchResults()`) — uses a constructor-injected
  `PersistentFileCache $cache` (not the static locator), with its own explicit 300s
  `set()` TTL. `$this->cache` has no other use in this class.
- `Calendar/CalendarRenderer.php:290` — one calendar navigation-bar key. Same
  no-other-use shape as `SectionPopulator`.
- `Notification/NotificationService.php:149` (`getRecentPostDates()`) — constructor-injected
  `PersistentCache $cache`, no other use in the class.

> **Execution-time correction:** a post-implementation full-repo grep for `cacheUpdateTime`
> (done to confirm zero remaining readers before this stage's own doc claimed it) found a
> 6th, differently-shaped site the investigation above missed: `Filter/FilterService.php:120`
> (`initializeFromRequest()`) uses `$filter_key['time'] <= $currentUser->cacheUpdateTime` as
> one of several OR'd staleness checks gating a *session*-cached (not CachePools-backed)
> "recent period" filter computation — an unrelated caching mechanism (`SessionService`), not
> one of the 5 `CurrentPersistentCache`/constructor-injected-cache files above. Fixed the
> same session: replaced the cacheUpdateTime comparison with `time() - $filter_key_time >=
> 30`, the same 30s staleness budget as every CachePools-backed check here, without
> introducing a CachePools dependency (this mechanism never used one).
>
> Also found, same grep pass: `Controller/FeedController.php:67` still built a
> `NotificationService` with the old 4-arg shape — an *execution* slip, not a plan gap (this
> section's own file list above already named `FeedController` correctly); the
> implementation step re-grepped 3 known files by name instead of cross-checking against
> that list, missing it until a repo-wide `CurrentPersistentCache` sweep surfaced it. Fixed
> in the same commit; repo-wide `grep -rn "new NotificationService("` / `"new
> SearchService("` re-run afterward to confirm the real total (4 and 6 respectively) before
> considering this sub-stage closed.

All 6 files are permission-filtered content, same reasoning `CachePools::permissions()`'s
own docblock already gives (30s TTL: "a permission change... becomes visible well within
one user session"). Add 4 new `CachePools::` methods — `sectionImageIds()`,
`searchResults()` (covers both `SearchFilterRenderer`'s 8 keys and
`SearchService::getQuickSearchResults()` — one "search" concept, several key prefixes,
same one-pool-many-prefixes shape `permissions()` already uses per-user), `calendarNav()`,
`notifications()` — each its own pool at 30s TTL. **Deliberate accepted behavior change**:
`SearchService::getQuickSearchResults()`'s existing 300s `set()` argument drops to the
uniform 30s — the 300s was never a considered "search content changes slowly" choice on
its own, it was bundled with the `cacheUpdateTime` permission-invalidation concern; 30s
favors faster permission-change visibility (a forbidden category's images disappearing
from search promptly) over fewer cache hits on unrelated content churn, matching every
other pool's own reasoning.

Each of the 5 files fetches its own new `CachePools::` pool inline at point of use — same
as `TagService`'s precedent (`TagService` is manually constructed at ~18 call sites, no DI
container, so the pool is fetched inline rather than constructor-injected). Since
`$this->cache` (`SearchService`/`NotificationService`) and `$persistent_cache`
(`SectionPopulator`/`CalendarRenderer`, including each file's own
`! $persistent_cache instanceof PersistentCache` → `fatalError()` guard) have **no other
use** in any of the 4 classes once this lands, the whole mechanism is removed, not left as
dead weight: delete `SearchService`'s `PersistentFileCache $cache` constructor param and
`NotificationService`'s `PersistentCache $cache` one (retarget all real construction sites —
`SearchController`, `FeedController`, `GalleryController` ×2, `BatchManagerSubController`,
`PwgImages`, `PictureController`, `NotificationByMailSubController`, `NbmController`, plus
`tests/Integration/{SearchServiceTest,NotificationServiceTest}.php`), and delete
`SectionPopulator`/`CalendarRenderer`'s `CurrentPersistentCache::get()` call + guard
entirely (no constructor param to remove there — it was never injected).

Once all 5 are converted, `cacheUpdateTime` has zero remaining real readers — delete
`User::$cacheUpdateTime`, `user_cache.cache_update_time`, and `getUserData()`'s own write of
it (folded into 4g below, same commit).

> **2026-07-25 note: 4a done.** Full verification: PHPStan/ECS/deptrac clean; Unit/Arch
> 717/717, Integration 675/675 (including the 2 rewritten mutation-based cache-hit tests —
> `NotificationServiceTest`/`SearchServiceTest` no longer assert against
> `PersistentFileCache`'s on-disk `*.cache` files, which this sub-stage's conversion made
> moot), Browser 68/68, Visual 34/34 — all unchanged from baseline. `CalendarRenderer.php`'s
> own defensive `is_array($items) ? array_filter(...) : []` re-narrowing (pre-dating this
> stage) turned out to be genuinely dead code once the by-ref `PersistentCache::get()` call
> masking it was removed — PHPStan proved every reachable path already produces `list<int>`;
> deleted rather than suppressed.

### 4b. `forbidden_categories`: two distinct concepts, don't conflate them

**Critical finding, would cause a real regression if missed**: the value stored in
`user_cache.forbidden_categories` (read via `CurrentUser::forbiddenCategories`, 9 real
consumer files including `PermissionService::getSqlConditionFandF()` itself) is **not**
the same as `PermissionService::getForbiddenCategories()`'s own return value.
`UserService::getUserData()`'s cache-generation block (verified directly,
`UserService.php:761-823`) computes the base structural value via `getForbiddenCategories()`,
then **adds** every category with zero visible images for non-admins (feature 1053,
`CategoryService::getComputedCategories()`) before storing it. Stage 1d's own
`Permission\ForbiddenCategoriesCache` wraps only the narrower, structural value — verified
safe because its own 3 call sites (`PictureModifyPageRenderer`, `BatchManagerUnitPageRenderer`,
`Ws/PwgCategories`) already called `getForbiddenCategories()` directly pre-Stage-1d, never
the broader stored one. Naively reusing `ForbiddenCategoriesCache` here would silently drop
the empty-category exclusion.

New class `Permission\EffectiveForbiddenCategoriesCache` (distinct name, distinct
computation, not an extension of `ForbiddenCategoriesCache`): wraps
`PermissionService::getForbiddenCategories()` + the same empty-category-exclusion query
`getUserData()` runs today, cached per-user in a new `CachePools::effectivePermissions()`
pool, 30s TTL. Wherever `CurrentUser::set()`/`UserBootstrap` populates `forbiddenCategories`
today (from the `user_cache`-joined row) switches to this new class instead.

### 4c. `image_access_type`/`image_access_list`: paired cache helper

Real computation (`getUserData()` lines 768-786, verified): images above the user's own
permission `level`, within categories not in the (effective) forbidden set — genuinely
distinct from "forbidden categories" itself. Consumed via
`CurrentUser::rawAttributes['image_access_type'/'image_access_list']`, same
`PermissionService::getSqlConditionFandF()` call site plus a final grep pass for the full
read set at execution time (the computation and cache design are already fully specified
here). New class `Permission\ImageAccessListCache`, same `CachePools::effectivePermissions()`
pool as 4b (one user, one permission snapshot, one cache entry holding both is more correct
than two entries that could theoretically desync under a race).

### 4d. `nb_total_images`: folded into 4b/4c's pool

A `COUNT(DISTINCT image_id)` over `image_category` filtered by the same effective
forbidden-categories + image-access-list computation (`getUserData()` lines 788-797). Only
real consumer: `Menu/MenubarRenderer.php:151`. Computed together with 4b/4c in the same
`EffectiveForbiddenCategoriesCache`/pool entry, since they already share the same underlying
query dependency chain in `getUserData()` today.

> **2026-07-25 note: 4b/4c/4d done, implemented and verified together** (one class,
> `Permission\EffectiveForbiddenCategoriesCache`, one pool,
> `CachePools::effectivePermissions()`) — confirmed via direct read that `Users\User::
> fromUserArray()` puts the *entire* raw `$userdata` array into `rawAttributes`, so
> `image_access_type`/`image_access_list`/`nb_total_images` (read via
> `CurrentUser::rawAttributes[...]`, e.g. `MenubarRenderer.php:151`) are already populated
> by `getUserData()`'s new unconditional overwrite with zero further consumer-side changes
> needed; `forbidden_categories` is separately promoted to `User::$forbiddenCategories`, also
> already correct. `getUserData()` now calls the new class *unconditionally*, after the
> still-present (until 4g) `$useCache`-gated legacy block, overwriting whatever that block
> (or the stale `uc.*` JOIN) produced — the real behavioral cutover for these 4 columns,
> ahead of 4g's own removal of the mechanism that used to write them.
>
> **Real regression caught by the Browser suite, not by PHPStan/Unit/Arch/Integration**:
> `EffectiveForbiddenCategoriesCache::getForUser()`'s first version typed `$level` as a bare
> `string`, matching the old code's own `assert(is_string($level))` — but `assert()` is a
> total no-op in this environment (zend.assertions=-1) and PHPStan only checked the class in
> isolation, never against `getUserData()`'s real call with a real DBAL row, where
> `user_infos.level` (a tinyint column) comes back as a native `int`. Every real request hit
> an uncaught `TypeError` (identification.php, qsearch.php, ...), only surfaced when the
> Browser suite's real dev-server process failed 61 of 68 tests at the login step. Fixed by
> widening the parameter to `int|string`. **The deeper gap this exposed and also fixed**:
> `UserServiceTest`/`UserRepositoryTest` had zero direct coverage of `getUserData()`/
> `buildUser()` at all — the single most-called per-request method in the app — so
> Integration testing never exercised this real DBAL-typed row shape either. Added 2 new
> `UserServiceTest` cases (`buildUser(1, false)`/`buildUser(1, true)`, the latter also
> exercising the still-present legacy regeneration block against the same real row) to close
> that gap, not just to regression-guard this one bug.

### 4e. `last_photo_date`: consolidate two independent implementations into one

Two things compute this today, independently: `getUserData()`'s own eager computation (via
`CategoryService::getComputedCategories()`), and `Filter/FilterService.php:143-148`'s own
separate, conditional computation (only inside its own "recent period filter" feature,
caching onto `CurrentUser::rawAttributes['last_photo_date']` when it runs). Real consumers:
`Category/CategoryCatsRenderer.php:94`, `Users/UserService.php:1070-1084`. Since
`FilterService`'s own branch doesn't run on every request, a consumer hit outside that
branch depends on `getUserData()`'s own eager write having already happened — the two
implementations aren't redundant, they're both real and currently uncoordinated.

Proper fix (matching the already-established `nb_available_tags`/`nb_available_comments`
pattern, see 4f): one new lazy method, `Category\LastPhotoDateCache::getForUser(User
$user): ?string` (compute via `CategoryService::getComputedCategories()` if
`CurrentUser::rawAttributes['last_photo_date']` isn't already set, cache onto
`rawAttributes` for the rest of the request — no cross-request pool needed, this is cheap
to recompute per-request). `FilterService`'s own inline computation and `getUserData()`'s
eager one both retarget onto this single method instead of each doing their own thing.

### 4f. Delete dead `nb_available_tags`/`nb_available_comments` DB write-back

Already fully modernized, confirmed dead weight, not part of the real migration work:
`Tag/TagService::getNbAvailableTags()` / `Comment/CommentService`'s equivalent already
compute fresh (via the cache-pool-backed methods Stage 1c/1d already built) and cache onto
`CurrentUser::rawAttributes` per request — the *read* side never touches the DB column. The
only remaining DB touch is a write nothing ever reads back: `TagRepository::
saveNbAvailableTags()`, `Comment/CommentRepository`'s equivalent,
`UserCacheRepository::clearNbAvailableTags()`, `Comment/CommentRepository`'s own
`nb_available_comments = NULL` invalidation write. Delete all of them outright — pure
removal, no replacement needed.

### 4g. Delete `UserService::getUserData()`'s lock/wait/503 regeneration mechanism

The whole `$useCache` branch (lines 694-887) — `UniqueExecLock::begins()`/`isRunning()`/
`ends()`, the 20×`sleep(1)` polling wait loop, the 503 "Rebuilding user cache takes long"
fallback with a raw `exit()` — exists solely to coordinate exclusive regeneration of the
`user_cache` row across concurrent requests for the same user. Once 4a-4e replace every
real column with an independent cache-pool-backed computation (each already safe for
uncoordinated concurrent access — a PSR-6 cache-pool miss just triggers an independent
recompute per request, no shared mutable row to corrupt), this coordination has nothing
left to protect. **Accepted, deliberate tradeoff, not an oversight**: a burst of concurrent
requests for the same just-invalidated user can now each independently recompute the
(cheap, single-query) permission snapshot rather than one computing while others wait —
matches P23's own original design text exactly ("recursive CTE cached in the APCu/Redis
permissions pool"), which never mentioned a lock for the replacement. `UniqueExecLock` the
*class* is not touched — `PiwigoInfosSender`/`Bootstrap/PageTail.php` are real, unrelated
callers.

`getUserData()` itself drops the `user_cache` `LEFT JOIN` and the `uc.*` columns entirely,
and populates `forbiddenCategories`/`rawAttributes['image_access_type'/'image_access_list'/
'nb_total_images']` from 4b/4c/4d's new cache classes unconditionally.

**Also verified**: with the whole `$useCache`-gated block gone, `getUserData()`'s
`$useCache` parameter has no remaining effect on the method body at all — it existed solely
to gate this one block. Per this codebase's own "no vestigial parameters" standard, the
parameter itself is removed, not left as a no-op flag: `getUserData(int $userId): array`,
`buildUser(int $userId): array`, and `Bootstrap/UserBootstrap::shouldUseUserCache()` (plus
its one call site building `$user_use_cache`) are deleted, and all ~14 call sites passing a
literal `true`/`false` second argument (`InstallWizard`, `NotificationByMailSender`,
`RedirectService`, `RegisterController`, `Controller/Admin/ConfigurationSubController` ×2,
`FeedController` ×2, `PasswordController` ×4, `UserBootstrap`) drop that argument.

### 4h. `user_cache_categories`: finish the batch-3 migration, not just verify it

Batch 3a/3b's own "done" claim covered only the read surfaces each sub-batch specifically
targeted. Direct grep found 3 real, unconverted readers/writers still remaining:

- `Ws/PwgCategories.php` — 4 real sites (2 JOINs building the WS category list, 1 UPDATE,
  1 reference inside an insert helper).
- `Search/SearchFilterRenderer.php:479` — 1 real `INNER JOIN`.
- `Category/CategoryRepository::findRandomRepresentativeIdAmongSubcategories()` — 1 real
  `INNER JOIN` (finds a random representative image among visible subcategories).

Convert each onto `Category\CategoryTreeCache` (already the real batch-3b replacement
mechanism — same category-visibility-rollup concept, not a new computation to design) or an
equivalent permission-filtered subquery where a full rollup would be wasteful (e.g. the
representative-image lookup only needs "is this category visible," not the full
per-category count rollup — a mechanical per-site fit, not open design).

`UserService.php:1567`/`UserRepository.php:314`/`CategoryService.php:1037`'s own
`Tables::userCacheCategories()` references are generic "delete/verify orphaned rows in
every user-related table" maintenance bookkeeping — remove the table from those lists,
nothing to migrate.

### 4i. Drop both tables

Once 4a-4h land and zero real readers remain: drop `piwigo_user_cache` and
`piwigo_user_cache_categories` from `install/piwigo_structure-mysql.sql` + the test
fixture; delete `Cache/UserCacheRepository.php`, `Cache/UserCacheInvalidator.php`, and every
one of the ~30 `UserCacheInvalidator::invalidate()`/`invalidateNbTags()` call sites across
`Admin/*`, `Ws/PwgImages.php`, `Ws/PwgCategories.php`, `Ws/PwgGroups.php`,
`Group/GroupService.php`, `Tag/TagService.php`, `Image/ImageService.php`,
`Controller/PictureController.php`, `Users/UserService.php`,
`Admin/Extensions/CoreUpdateService.php`, `Admin/Upload/UploadService.php` — every one
becomes a no-op deletion (the TTL-based replacements invalidate themselves; nothing needs
an explicit "mark dirty" signal any more), **except** `invalidate()`'s other 2 real effects
(`$persistent_cache->purge(true)`, `confDeleteParam('count_orphans')`) — verify at each call
site whether *those* still have independent value beyond the `user_cache` concern before
deleting the whole call, not just assumed dead by association.

### 4j. `history_summary` (flagged, not designed this pass — genuinely different kind of open question)

3c's own deferral reason ("two real, substantial `admin/*.php` files... unlike 3a/3b")
appears resolved as a side effect of unrelated work: `admin/stats.php`/
`get_pwg_general_statitics()` are now `Admin/StatsPageRenderer.php`/
`Admin/InstallationStats.php` inside `src/Piwigo/`, confirmed by direct grep — so 3c's
specific blocker (reading a table directly from code batch 6 hadn't absorbed yet) no longer
applies as stated. Stage 1b's own `History\Projection\HistorySummaryCursor`/
`HistorySummaryCount` typed projections would anchor a real migration. **Not designed
here**: P23's own text leaves the actual replacement mechanism as an open choice (`WITH
ROLLUP` live queries vs. a materialized summary refreshed by a maintenance job) that
depends on real data-volume/query-frequency characteristics no amount of code-reading
resolves — a materialized summary refreshed via the already-existing
`DbMaintenanceRepository`/`HistoryService::summarize()`/`autopurge()` maintenance-job
pattern is the recommended direction (reuses an established mechanism rather than
introducing a new live-query dependency), but this is its own follow-on investigation, not
bundled into 4a-4i's already-fully-resolved scope.

**Verify Stage 4:** standard per-sub-stage cadence (4a-4i, each its own commit,
`(p23)` scope tag) — `composer lint:php` + `vendor/bin/phpstan analyse` +
`tools/pest-cleanup.sh` (Unit/Arch) as each sub-stage lands; `composer test:integration`
once 4a-4i all land (catches cross-domain consumers a per-sub-stage pass might miss);
`composer test:browser`/`test:visual` at the end; `composer test:fixture-regen` once, for
the 2 dropped tables. Audit existing `UserServiceTest`/`UserRepositoryTest` coverage against
the redesigned `getUserData()` before trusting it still passes for the right reason, not
just still-green.

## Remediation — DBAL→ORM migration

Tracked separately, `docs/plan/manifest.yaml`'s `remediation:` section + a dedicated
`docs/PLAN-REPLAY.md` section (after P23) — genuinely new forward work (P14's own audit
note: "tracked as a new `remediation:` initiative... sequenced after P23," never actually
added until now), not a P23 backfill, so it doesn't get its own Stage number here. Sequenced
after Stage 4: Part B's own repository classification excludes `UserCacheRepository`
(deleted by Stage 4i before this would ever reach it) — a real dependency, not just a
scheduling preference.

## Verification (applies throughout)

- Per-domain/per-batch: `composer lint:php`, `vendor/bin/phpstan analyse`,
  `tools/pest-cleanup.sh` for Unit/Arch — one verify pass per logical batch, not per file.
- Per-stage close-out: `composer test:integration`, `composer test:browser` (full suite),
  `composer test:visual`.
- `vendor/bin/deptrac --no-progress` after any namespace/dependency change in Stage 1 (new
  `Projection/` sub-namespaces, new exception classes) — confirm they land in the existing
  layer regex rather than surfacing as newly Uncovered.
- Cross-check any suspicious zero-match `grep` on a `$`-containing pattern with Python before
  trusting it (this audit was bitten by this twice already).
- One commit per logical unit, `(p23)` scope tag for Stage 0/1 work (finishing P23's own
  claims), `(p8)` for Stage 2, whatever scope tag fits the legacy-import adoption item in
  Stage 3, and `(p23)` again for Stage 4 (same "finishing P23's own claims" reasoning — the
  DBAL→ORM remediation that follows Stage 4 gets its own scope tag when that work starts,
  not `(p23)`, since it's new forward work rather than a backfill). Update
  `docs/plan/manifest.yaml` and `docs/PLAN-REPLAY-AUDIT.md` as each stage's gaps actually
  close, not preemptively.
