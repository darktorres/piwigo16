# Legacy Coupling Retirement: DI + DBAL/ORM + globals + everything else

> **Resuming this from a new session/machine?** This file is a snapshot of
> an in-progress working plan, written out so it survives outside any one
> Claude Code session. It is NOT the same thing as `docs/PLAN-REPLAY.md` /
> `docs/plan/manifest.yaml` (the P0-P32 backbone) — see the naming note
> below. Two things referenced throughout this file will NOT be present in
> a fresh session and need to be reconstructed from what's here plus
> `git log`, not assumed to exist: the Claude Code task-list IDs (#569 etc.
> — see the Progress log) and the auto-memory files it cites (e.g.
> `project_p24_phase1b_complete.md`) — those live in a previous session's
> local memory store, not in this repo. Start a fresh session by re-reading
> this file, then `git log --oneline` on `17.x-rewrite` to confirm which
> commits already landed before doing anything else.

## Context

Following the A-gap globals effort, the user asked for a complete list of
remaining legacy code, then directed: modernize all of it — DI migration,
ORM/DBAL migration, global-variable retirement, free-function retargeting,
and everything else needed for a genuinely modern, testable baseline
before functional work resumes. Churn is explicitly acceptable; "most
proper" beats "smallest diff."

This plan replaces the completed A-gap plan in full. It was built through
direct investigation only (no subagents, per standing instruction) —
every number below comes from a real grep/read against the current
`17.x-rewrite` tree, not an estimate.

**Naming note**: commits for this plan use the message prefix
`feat(p24): ...`. That label is internal to this plan only — it is NOT
the same `P24` as `docs/PLAN-REPLAY.md`/`docs/plan/manifest.yaml`'s
backbone `P24` (Vite + TypeScript/frontend conversion, still
`status: planned` there). The two plans are unrelated; don't conflate
them when reading commit history or the replay manifest.

## Progress log (updated 2026-07-18)

- **Phase 1a (Category pilot) — DONE.** Commit `1e23646c6`. Resolved the
  SQL-dialect-fragment design question: `Piwigo\Db\SqlDialect`, a
  stateless static helper class, now hosts `getYear`/`getMonth`/`getWeek`/
  `getDayOfMonth`/`getDayOfWeek`/`getWeekday`/`getDateYYYYMM`/
  `getDateMMDD`/`getHour`/`castToText`/`concat`/`concatWs`/`getBoolean`/
  `booleanToString`/`protectColumnName`/`getRecentPeriodExpression`/
  `getFloodPeriodExpression`/`dateToTs`/`DB_RANDOM_FUNCTION` — reused by
  every later sub-phase. `Piwigo\Db\BatchWriter` (parameterized
  singleInsert/massInsert/singleUpdate/massUpdate) and
  `AbstractRepository::batchWriter()` also landed here as reusable
  infrastructure.
- **Phase 1b (small self-contained domains) — DONE, all 7 steps.**
  Auth+Session `21fa7afb9`, Mail+Notification `4b945a77c`,
  Comment+Group+History+Permalink `457e460e2`, Tag+Metadata+Site
  `f62f729b9`, Image+Cache `8dde1df4a`, Picture+Html `699a5fc64`,
  Permission+Lang `bf7904954`. 6 reusable DI-ripple-avoidance patterns
  established: reuse `batchWriter()` internally instead of a new
  constructor param; lazy-default optional constructor param for
  50+-caller classes; static setter for a rarely-needed dependency on a
  frequently-constructed value object (`SrcImage`); static method with no
  instance state constructs its own dependency inline; check for an
  existing higher-level helper before writing a new repository method.
- **Side-work, user-directed mid-plan (not in the original phase list)
  — DONE.** The user asked to retire the "i.php fast-bootstrap path
  avoids DI-container construction cost" rationale project-wide, since
  the planned FrankenPHP/workers conversion keeps the container warm
  across requests and removes that cost argument. Migrated
  `ImageVisibilityChecker` + `ImageDerivativeController` (i.php) off
  `MysqliDb`/manual DB handling onto one shared DBAL `Connection`
  (commit `cf6f48225`), then — per an explicit follow-up instruction —
  the *unrelated* Calendar exception (`CalendarBase`/`CalendarMonthly`/
  `CalendarWeekly`, left on `MysqliDb` for lack-of-migration reasons, not
  performance) (commit `943c1b9ac`). Re-grepped
  `fast.bootstrap|fast path|i\.php's own|i\.php fast` across
  `src/Piwigo/` afterward and confirmed every remaining hit
  (`SessionUserResolver`, `FilesystemHelper`, `SessionService`,
  `ImageDerivativeController`'s own docblock) is the *legitimate,
  unretired* "this endpoint doesn't need plugin/session/template
  bootstrap" request-shape rationale, not the retired DB-layer one — no
  further files need this class of migration.
- **Phase 1c (Search + Section) — DONE.** New `SearchRepository` generic
  raw-SQL executors (`queryRows`/`queryKeyedColumn`/`queryColumn`,
  string|null-cast to exactly mirror `MysqliDb::fetchAssoc()`'s own typing
  so no downstream call site needed a type-narrowing fix) plus
  `getDbVersion()` via `Connection::getServerVersion()`. New
  `Section\SectionRepository` (same generic-executor shape, plus
  `escapeToken()` — `Connection::quote()` with the wrapping quotes
  stripped — for `SectionInitializer`'s non-SQL `$_GET`-key character
  escape, ported from `MysqliDb::realEscapeString()`). `SearchService`
  gained 3 optional-lazy-default constructor params (`?TagService`/
  `?UserService`/`?PreferencesService`, MailService-precedent) so its 4
  out-of-phase callers (SearchController, Ws\PwgImages x2,
  BatchManagerSubController — Controller/Ws/Admin, later phases) needed no
  changes. `SearchFilterRenderer`/`SectionPopulator`/`SectionInitializer`
  all got real constructors; their 3 external call sites
  (GalleryController x2, PictureController x1) were fixed manually inline
  matching each file's own not-yet-migrated construction idiom (same
  precedent as 1a's GalleryController touch), not converted to DI.
  Real regression caught by `composer test:visual`, not by PHPStan/Unit:
  5 `SELECT DISTINCT(id/image_id) ... ORDER BY <col not in select>`
  queries ported into `SectionPopulator` (categories/recent_pics/
  most_visited/best_rated/list sections) hit the same
  `ONLY_FULL_GROUP_BY` gotcha already documented and fixed in
  `CalendarRepository`/`SearchService` — `Piwigo\Db\DbConnection`
  deliberately doesn't strip that sql_mode the way the legacy mysqli
  connection did, so a query that was silently valid under `MysqliDb::`
  became a real `Doctrine\DBAL\Exception\DriverException` (HTTP 500)
  under DBAL; fixed with the same established `GROUP BY id` swap. Found
  by decoding and pixel-diffing the failing screenshots (not
  pattern-matched as "flaky") — all 4 showed an identical "Internal
  Server Error" page. Commit `1d1fd7663`.
- **1a/1b/1c gap-closure audit — DONE.** User-directed: "check if 1a/1b/1c
  are truly complete... don't assume." Direct re-investigation (grep/read
  only) found `MysqliDb::` retargeting 100% complete everywhere, but the
  "combined fix" mandate's other two parts (raw globals, `die()`/`exit()`)
  were essentially untouched in every sub-phase — confirmed by
  cross-referencing the pre-Phase-1 domain table's own Globals/DieExit
  counts against current grep results, which matched almost exactly
  (nothing had been resolved). Most of that gap turned out to be
  correctly-unresolvable-yet (a global like `$filter`/`$picture` is
  shared live state also written by an untouched domain — e.g. Picture's
  globals are written by `Controller/PictureController.php`, Phase 1g,
  which itself still has a real `MysqliDb::query2Array()` call) or
  already a documented, deliberate deferral (`PictureCommentRenderer`'s 2
  `die()` calls, explicitly flagged in commit `699a5fc64`'s own message
  pending an `ExceptionHandlerMiddleware`-propagation investigation;
  `Lang/functions.php`'s `$lang` bridge, explicitly Phase 4's own scope).
  5 genuine, self-contained gaps got fixed: dead `global $errors;` write
  in `Site\LocalSiteReader::open()`; 3x-repeated `new UserService(...)` +
  1x `new AuthService(...)` chains in `Mail\NotificationByMailSender`
  (neither circularly blocked, unlike `MailService`'s own 2 `UserService`
  chains, correctly left alone — `UserService` constructor-depends on
  `MailerInterface` == `MailService`, a real cycle); the matching 1x
  `AuthService` chain in `MailService` itself, given an optional-lazy-
  default param matching the `$webmasterMailProvider` precedent since
  `AuthService` doesn't share that cycle; `Search\SearchService`'s 1
  `die()` call (my own miss from the 1c work above), swapped for the
  already-injected `$this->htmlRenderer->fatalError()`. **Caught and
  reverted a near-miss during this pass**: `Auth\AuthService`'s 2
  `global $user;` declarations looked identically dead at first grep
  (matching the exact pattern that made `Site`'s `$errors` a clean fix),
  but a closer read found `logUser()` genuinely reads/writes `$user['id']`
  for `trigger_notify()`/`activityLogger->record()`, and `authKeyLogin()`'s
  write is reached from `Bootstrap/UserBootstrap.php`'s own login flow —
  verifying it's truly dead needs tracing Bootstrap's execution order,
  Phase 1d's own territory, not a quick grep. Left untouched; worth
  revisiting once 1d is underway. Commit `412ff004e`.
- **Phase 1d (Bootstrap) — DONE.** All 9 files re-investigated directly
  (`RequestPipeline`/`CommonBootstrap`/`CliBootstrap`/`AdminDispatcher`/
  `PageTail`/`SentryBootstrap`/`SessionBootstrap` needed zero changes —
  already container-resolved or zero-arg-only). `UserBootstrap`/
  `RequestBootstrap` had the real debt: 2 genuine `MysqliDb::` calls
  retargeted (a bare `SELECT NOW()` onto `Connection::fetchOne()`; a
  `singleUpdate()` onto a new `ApiKeyRepository::updateLastNotifiedOn()`
  method) — the other `MysqliDb::` hits in `RequestBootstrap::connect()`
  (`connect`/`checkCharset`/`myError`/`setHtmlRenderer`) are the literal
  mysqli connection bootstrap itself, structurally necessary until
  `MysqliDb.php` is retired at 1k, correctly left alone. Found and fixed
  a real, non-cosmetic issue: `DbConnection::build()` returns a **fresh**
  `Connection` on every call (no internal caching) — `RequestBootstrap::
  finalize()` alone had 11 separate calls, meaning up to 11 needless
  physical DB connections on every single request; consolidated to one
  `$conn` per method, reused throughout (same pattern as `configure()`'s
  connect()/checkCharset() already used, now applied consistently). Also
  eliminated real repeated-construction waste in `UserBootstrap::
  initialize()` (the exact same `AuthService`/`UserService` chain
  constructed fresh 3x/4x within one method) by hoisting each to a single
  local variable built once. Two additional small, carefully-verified
  fixes: `UserBootstrap`'s `global $service;` was redundant (confirmed
  `Ws\WsInitializer::init()` already writes `$GLOBALS['service']` as its
  own side effect, independent of this method's own local reassignment)
  demoted to a plain local; `MysqliDb::realEscapeString()` on the
  `HTTP_X_PIWIGO_API` header was dead pre-escaping (confirmed
  `AuthRepository::findAuthKeyDetails()`, what it ultimately feeds,
  already uses a bound DBAL parameter, and the value is regex-validated
  to `[a-z0-9]`/`pkid-...` shapes that can't contain anything needing SQL
  escaping in the first place) — same "defensive cast tied to a legacy
  nullable return type" pattern as `SectionInitializer.php` in 1c.
  `$conf`/`$user`/`$t2`/`$template`/`$filter` globals all confirmed
  genuinely necessary (these files ARE the bootstrap-owned seeding/
  dual-write-bridge points, not leftover debt) — left untouched, matching
  the same reasoning already applied to Search/Section's `$page`/
  `$template` in 1c. The 4 remaining `die()`/`exit()` calls
  (WS-response-then-terminate in `UserBootstrap`, install-redirect +
  gallery-locked-maintenance-page in `RequestBootstrap`) were each
  individually investigated and confirmed structurally necessary
  (matching the plan's own "not bootstrap-time code that's fine as-is"
  carve-out for 1d) — not silently skipped. Commit `bfb705813`.
- **Phase 1e (Core) — DONE.** 5 files had real `MysqliDb::` debt (of 47
  total): `DeviceHelper.php` was a trivial rename onto the already-built
  `SqlDialect::getBoolean()` (pure logic, zero DB access, Phase 1a).
  `RecentIconResolver.php`/`LoungeMaintenance.php`/`ThemeCatalog.php`/
  `UniqueExecLock.php` had real query execution, retargeted onto
  `DbConnection` constructed inline (all are static-only classes/methods,
  matching the established "static method, no instance state" precedent)
  -- `Piwigo\Db\*` and `Piwigo\Core\*` share the same L1Infrastructure
  deptrac layer, so this is always a legal same-layer dependency.
  `UniqueExecLock`'s `INSERT IGNORE`-based distributed-lock race kept its
  exact original SQL text (pure execution-API swap, not rewritten through
  QueryBuilder or newly parameterized) since that's the real mechanism
  the class exists for. Caught and fixed a real PHPStan `cast.string`
  error in `LoungeMaintenance.php` (casting DBAL's `mixed` column value
  straight to `string` needs an `is_scalar()` guard first — a mysqli
  raw-row assumption that doesn't hold against DBAL's wider return type).
  `Core/ShutdownHandler.php`'s `exit(143)` (a real `pcntl_signal(SIGTERM,
  ...)` handler — throwing an exception from inside a signal-handler
  callback is unsound) and `Core/Lang.php`'s `global $lang;` (the
  documented, Phase-4-scoped `l10n()`/PO-translation bridge) were both
  re-confirmed as legitimate, already-documented exceptions, not gaps.
  Zero manual `new *Repository(`/`new *Service(` chains found anywhere in
  the domain (matches the original table's very low "New-chains: 1").
  Commit `456241849`.
- **Phase 1f (Ws) — DONE, all 7 sub-batches complete.**
  24 files/12396 lines total, by far the largest domain in
  this plan — confirmed needs the multi-batch breakdown the plan already
  anticipated. Sub-batch 1 investigated the small/shared files first:
  `WsInitializer`/`PwgError`/`PwgServer` needed no real changes (`PwgServer`'s
  `die(0)` is its own protocol-level "malformed response format, dump
  diagnostics, halt" primitive, structurally analogous to `HtmlService::
  fatalError()`'s own internal `die(0)` — not a drop-in replacement,
  different content-type/status/message shape, correctly left alone).
  `WsHelper.php`'s 3 `MysqliDb::DB_RANDOM_FUNCTION` hits were a constant
  reference, not a query — trivial rename onto the already-migrated
  `SqlDialect::DB_RANDOM_FUNCTION` (Phase 1a); its `global $service;`/
  `exit;` WS-error-termination pattern re-confirmed legitimate (same
  shape as `UserBootstrap`'s own, Phase 1d).
  **Real, previously-unlisted gap found:** `PwgExtensions.php`'s 2
  `MysqliDb::realEscapeString()` calls are genuinely still required —
  traced the value into `ConfigDb::confUpdateParam()`, which still builds
  raw string-interpolated `INSERT ... VALUES('...','...')` SQL with zero
  escaping of its own (`MysqliDb::query()`, not yet retargeted). Removing
  the caller's pre-escaping would be a real SQL-injection regression.
  `ConfigDb.php`'s own internal `MysqliDb::` usage isn't assigned to any
  numbered Phase 1 sub-phase in this plan (Config domain has no 1a-1j
  slot) — flagging as a real, confirmed gap in the plan itself, not
  fixing it here (would ripple into ~50 other `ConfigDb::` callers this
  phase doesn't own). `PwgComments.php` fully migrated: 5 `MysqliDb::`
  calls onto `DbConnection` (search term escaping via `Connection::
  quote()`, SEC-18-style, matching `SearchRepository::quote()`'s
  precedent), 2 repeated `CommentService` chains collapsed into a private
  static helper (`RequestBootstrap::activityService()`'s own precedent,
  adapted). Caught 3 real PHPStan errors from DBAL's wider `mixed` row
  type (stripslashes()/string-concat/loose-`==` on unnarrowed row
  values) — fixed with `is_string()`/`is_scalar()` guards, matching the
  Phase 1e finding. **Caught a real HTTP 500 via Contract tests** (not
  PHPStan/Unit): a `GROUP BY author_id` query selecting the
  non-functionally-dependent `author` column hit
  `sql_mode=only_full_group_by` under DBAL (same root cause as the
  Phase 1c/1e `DISTINCT`+`ORDER BY` bugs, different shape) — fixed with
  `ANY_VALUE(author)`, preserving the exact original arbitrary-value-per-
  group semantics rather than changing grouping granularity by adding
  `author` to `GROUP BY`. Commit `b4120fb90`.
  Sub-batch 2 (Permissions+Groups): `PwgPermissions.php` fully migrated
  — 3 `SELECT`+loop queries onto `$conn->fetchAllAssociative()`,
  `MysqliDb::query2Array($query, null, 'id')` onto the equivalent
  `array_column($conn->fetchAllAssociative($query), 'id')` (the
  `key_name === null` branch of `query2Array()` is exactly
  `array_column`), `MysqliDb::massInserts()` onto
  `BatchWriter::massInsert()` (same class Phase 1a/1b already
  established for ~20 other call sites), 2 raw `DELETE` queries onto
  `$conn->executeStatement()`. Fixed 3 PHPStan errors:
  `intval(mixed)` isn't accepted by PHPStan's stub for DBAL's wider row
  type, guarded with `is_scalar(...) ? intval(...) : 0`.
  `PwgGroups.php` had zero `MysqliDb::` calls but the exact "same recipe
  repeated" anti-pattern this whole plan targets: `new GroupService(new
  GroupRepository(...), new ActivityService(...), new AuditService(...))`
  repeated 7 times across `add`/`delete`/`setInfo`/`addUser`/`merge`/
  `duplicate`/`deleteUser` — collapsed into a private static
  `groupService()` helper (same shape as
  `RequestBootstrap::activityService()`/`PwgComments::commentService()`,
  now established 3 times). Left the single-occurrence
  `new GroupRepository(...)` in `getList()` and the single-occurrence
  `new AuditService(...)` audit-record call in `add()` alone, matching
  the "don't touch non-repeated chains" precedent from
  `NotificationByMailSender.php` (Phase 1a/1b/1c gap-closure). Commit
  `2f4ff6e3e`.
  Sub-batch 3 (Tags): `PwgTags.php` fully migrated — all 20
  `MysqliDb::` calls retargeted, the widest variety of DBAL swaps seen
  in this phase so far: `query()`+while-loop onto
  `fetchAllAssociative()`+`foreach`, `query2Array()` (both the
  no-key/no-value full-row form and the `key_name===null` single-column
  form) onto `fetchAllAssociative()`/`array_column()`,
  `fetchRow(query())` `COUNT(*)` checks onto `fetchOne()`,
  `singleInsert()`/`singleUpdate()`/`massInserts()` onto `BatchWriter`,
  `insertId()` onto `Connection::lastInsertId()`. Dropped one dead
  `realEscapeString()` pre-escaping call feeding straight into
  `BatchWriter::singleUpdate()`'s own parameterization (same "dead
  pre-escaping" pattern as `UserBootstrap`'s HTTP_X_PIWIGO_API fix,
  Phase 1d). The repeated `new ActivityService(new
  ActivityRepository(DbConnection::build()))` chain (6 call sites,
  2 inside per-image `foreach` loops in `duplicate()`/`merge()`)
  collapsed into a private static `activityService(Connection $conn)`
  helper that takes the caller's own connection — avoiding N fresh
  connections per loop iteration, not just N constructions; same
  "connection passed in, not rebuilt" precedent as
  `RequestBootstrap::activityService()`. Fixed 11 real PHPStan errors:
  `is_numeric()` guards before `(int)` casts on now-`mixed` row values;
  `is_numeric()`+`intval()` narrowing before both
  `ActivityService::record()`'s `int|string` param and `array_diff()`
  (which needs string-castable list values — `array_column()` alone
  only yields `list<mixed>`); strict `!==`/`===` in place of the
  original loose `!=`/`==` once `COUNT(*)` results are cast to `int`
  (PHPStan disallows loose comparison between `mixed`/`string|null` and
  `int`). Commit `e5a270c64`.
  Sub-batch 4 (Users): `PwgUsers.php` (1168 lines, 16 `MysqliDb::`
  calls) fully migrated — the free-text `username`/`filter` search
  terms in `getList()`'s hand-built WHERE clause escaped via
  `Connection::quote()` (SEC-18 pattern, same rationale as Phase 1c/1f
  step 1's own occurrences). `getList()`'s `SELECT SQL_CALC_FOUND_ROWS
  ...` + `SELECT FOUND_ROWS();` pair kept on one shared `$conn` for the
  whole method — `FOUND_ROWS()` reflects the immediately-preceding
  query on the *same connection/session*, so unlike every other query
  in this file, this pair could not be allowed to open independent
  connections per call. Everything else followed established patterns:
  `query()`+loop onto `fetchAllAssociative()`+`foreach`,
  `query2Array()`/`fetchRow(query())` onto `array_column()`/
  `fetchOne()`, `singleInsert()`/raw `DELETE` onto `BatchWriter`/
  `executeStatement()`. Dropped 2 more dead `realEscapeString()`
  pre-escaping calls once `ApiKeyRepository::insert()`/`updateName()`
  were confirmed to already parameterize the value themselves (same
  "dead pre-escaping" pattern, 3rd occurrence this phase).
  **New shared helper:** `MysqliDb::getEnums()` (DESC-table +
  string-parse, no cross-driver-portable DBAL equivalent for reading a
  live ENUM definition) is called from 4 files across 2 domains (Ws:
  this file + the not-yet-migrated `PwgCore.php`; Admin:
  `HistoryPageRenderer.php`/`UserListPageRenderer.php`, both out of
  Phase 1's scope) — added `Db\DbInfo::getEnums(string $table, string
  $field): array` as a shared home once this file's own migration
  confirmed a second real Ws-domain caller exists, rather than inlining
  the DESC-parse logic again (`HistoryRepository::
  getSectionEnumOptions()`, Phase 1b, already has its own
  single-table-hardcoded copy — not retrofitted onto the new shared
  method, out of scope for this pass). Two more repeated construction
  chains collapsed: `new AuthService(...)` (4 sites, 1 inside
  `getList()`'s per-user loop) into `authService(Connection $conn)`
  (connection-as-param variant, `PwgTags::activityService()`'s
  precedent); `new ApiKeyService(...)` (8 sites, none in a loop) into
  `apiKeyService()` (build-own-connection variant,
  `PwgComments::commentService()`'s precedent) — confirms this file
  needed both private-static-helper sub-variants side by side. Fixed 2
  real PHPStan errors, both already-known regression classes:
  `is_scalar()`+string-cast narrowing before `implode()` (needs
  `array<string>`), `is_numeric()` guards before `(int)` casts on
  now-`mixed` row values (repeated at every row-value site touched in
  this file). Commit `a514db5cc`.
  Sub-batch 5 (Core): `PwgCore.php` (1428 lines, ~30 `MysqliDb::` calls
  across 8 methods, Ws's own file — distinct from the already-complete
  `Core/` domain) fully migrated. `getInfos()`'s 11 sequential
  `COUNT(*)`/`MIN()` single-value queries all collapsed onto
  `fetchOne()`; `historySearch()` (the single largest WS method touched
  in this phase, ~650 lines) needed the widest range of swaps in one
  method yet: `query2Array()`'s no-key/no-value full-row form, its
  `key_name`+`value_name` form, AND (new this file) its `key_name`-only
  "keep the whole row, index by this column" form (`array_column($rows,
  null, 'id')` — confirmed `array_column()`'s `$column_key === null`
  branch does exactly this), plus a raw `INSERT ... VALUES('...')`
  onto `$conn->quote(serialize(...))` + `executeStatement()` +
  `Connection::lastInsertId()`. `historyLog()`/`historySearch()`'s
  `MysqliDb::getEnums()` calls retargeted onto the shared
  `DbInfo::getEnums()` added in step 4 — its second real caller,
  confirming that addition was correctly justified rather than
  speculative.
  **Real bug found and fixed, not just a retarget:** `ratesDelete()`
  built a `DELETE` query string and then called `MysqliDb::changes()`
  (which reads the connection's own last-statement affected-row count)
  *without ever executing the query it built* — traced this back to the
  original pre-rewrite legacy code
  (`include/ws_functions/pwg.php::ws_rates_delete()`) and confirmed the
  bug predates this entire rewrite; `pwg.rates.delete` has never
  actually deleted a rate row. Fixed by executing the query via
  `executeStatement()`, which both runs it for real and returns its
  actual affected-row count — zero test coverage existed for this
  method, so this is a case where migration-driven code reading (not
  testing) surfaced the bug.
  Dropped 3 more dead `realEscapeString()` pre-escaping calls:
  `sessionLogin()`'s auth-key secret (the combined string must match
  `authKeyLogin()`'s own strict `[a-z0-9]`-only regex to be valid,
  so escaping could only break it) and `historySearch()`'s
  `filename`/`ip` search fields (both already flow into
  `HistoryRepository`'s parameterized `:pattern`/`:ip` queries,
  confirmed by reading `HistoryRepository::search()`/
  `findImageIdsByFilename()`, both from Phase 1b).
  Removed a dead `global $name_of_tag;` declaration in
  `historySearch()` — written, read (including via a closure capture),
  and `unset()` entirely within that one function; confirmed via a
  full-codebase grep that nothing else ever touches it. The 3 repeated
  `AuthService` chains (`sessionLogin` x2/`sessionLogout`) collapsed
  into a private static `authService()` (build-own-connection variant,
  since none of the 3 call sites are in a loop).
  Full-`mixed`-row narrowing needed across `getActivityList()`/
  `historySearch()` — both large, row-value-heavy methods — was the
  bulk of the PHPStan follow-up work: scalar narrowing before string
  concatenation/casts, `===` in place of a loose `==` PHPStan now flags,
  and explicit `(string)` casts before using row values as array keys.
  Commit `e1ef1a062`.
  Sub-batch 6 (Categories): `PwgCategories.php` (1508 lines, 62
  `MysqliDb::` calls — the widest-scale single-file migration in this
  phase by call count) fully migrated. Every pattern established in
  prior Phase 1f sub-batches got reused: `query()`+loop onto
  `fetchAllAssociative()`+`foreach`, all 3 `query2Array()` forms onto
  `array_column()`, `fetchRow(query())` `COUNT(*)`/single-value checks
  onto `fetchOne()`, `realEscapeString()` onto `Connection::quote()`,
  `singleUpdate()`/`massUpdates()` onto `BatchWriter`, `numRows()>0`/
  `==0` checks onto `fetchOne() !== false`/`=== false`,
  `DB_REGEX_OPERATOR`/`DB_RANDOM_FUNCTION` onto `SqlDialect`. Three
  `SQL_CALC_FOUND_ROWS`/`FOUND_ROWS()` pairs (in `getImages()`,
  `getList()`, `getAdminList()`) each kept on their own single shared
  connection, matching the Phase 1f step 4 precedent. Two more repeated
  construction chains collapsed: `PermissionService` (5 call sites, one
  inside `getList()`'s per-category loop) into
  `permissionService(Connection $conn)`; `ActivityService` (4 call
  sites, none looped) into `activityService()`.
  The bulk of the follow-up work was systematic `mixed`-row narrowing
  across this file's many per-row loops — `getList()`'s ~200-line
  category-processing loop and `calculateOrphans()`'s orphan-computation
  logic needed the most of it. Two variants of the by-now-familiar
  "`(int)` cast on `mixed`" issue recurred: PHPStan rejects a bare
  `(int)`/`(string)` cast on a `mixed` value even when the cast is
  already present in the code (not just when adding a new one) — every
  single cast in this file needed an explicit `is_numeric()`/
  `is_scalar()` guard first, no exceptions. Also hit (for the first
  time this phase) `is_numeric()` alone being insufficient for an
  array-key guard, since it allows `float` (not a valid PHP array key
  type) — fixed with `is_int($x) || is_string($x)` instead. Renamed
  several loop variables that collided with earlier same-named
  variables still in scope (PHPStan's "foreach overwrites $row" check,
  now hit 4 times across this phase). Commit `115193ef6`.
  Sub-batch 7 (Images, final): `PwgImages.php` (3034 lines, 74
  `MysqliDb::` calls — the single largest file in this entire plan)
  fully migrated. Every pattern from steps 1-6 reused at the largest
  scale yet. Added 4 shared private static helpers for chains repeated
  across the whole file (12x `PermissionService`, 7x `ImageService`, 5x
  `TagService`, 2x standalone `ActivityService`) — all connection-as-
  param, and `tagService()`'s consolidation also fixed the original call
  sites' `ActivityService` param building off a fresh, unrelated
  `DbConnection::build()` instead of the shared `$conn`, removing a
  needless extra connection per call in the process.
  **Investigated the 5 `die()` calls** in `upload()` (the chunked-upload
  JSON-RPC handler): a full-codebase grep confirmed this exact raw
  `{"jsonrpc":"2.0",...}` error shape is produced nowhere else in the
  codebase, meaning a hardcoded JS chunked-upload client depends on it
  directly, bypassing the normal `PwgError`/response-format pipeline
  entirely — same structural precedent as `PwgServer.php`'s own
  `die(0)` (Phase 1f step 1). Left unchanged, not converted to
  `PwgError` returns. Also confirmed `uploadAsync()`'s `global $user;`
  is genuinely load-bearing (`$user['level']` read/written for a
  documented "trick to bypass get_sql_condition_FandF" dual-write,
  already paired with a `CurrentUser::set()` sync per Track A5) — left
  unchanged. Dropped one more dead `realEscapeString()` (`setInfo()`'s
  `tag_list` batch, confirmed via `TagService::getTagIds()`/
  `tagIdFromTagName()` already routing through parameterized
  `TagRepository` queries), and kept one genuinely load-bearing
  `realEscapeString()`-equivalent via `Connection::quote()`
  (`upload()`'s `update_mode` filename lookup, still spliced into raw
  SQL). Fixed the remaining real PHPStan errors — `is_numeric()`/
  `is_scalar()` row narrowing, array-key type guards, a
  `syncMetadata()` `list<int>` reindex via `array_values()`, param
  narrowing for `UploadService::addUploadedFile()`/`addFormat()` — no
  new regression classes, all instances of patterns already established
  earlier in this phase. Commit `00a503a59`.

  **Phase 1f (Ws) is now fully complete — all 24 files across 7
  sub-batches DI+DBAL migrated.** `WsDefaultMethods.php` (2357 lines)
  confirmed clean (pure method-registration table, zero MysqliDb/
  globals/die-exit/manual-chains); `Protocol/*` encoders +
  `PwgNamedArray`/`PwgNamedStruct`/`PwgRequestHandler` absent from every
  grep across all 7 sub-batches, confirmed clean by omission. One
  cross-cutting gap remains explicitly tracked but deliberately
  unfixed: `ConfigDb.php`'s own internal `MysqliDb::` usage (found in
  step 1, `PwgExtensions.php`'s escaping still depends on it) has no
  assigned Phase 1 sub-phase in this plan — flagged for a future phase,
  not this one's scope.

- **Phase 1g (Controller) — started.** 60 files, batched by
  controller/feature area following the same rhythm as 1f, tracked as
  10 sub-batches.
  - **Step 1 — Gallery/Picture cluster. DONE, commit `58b8604af`.**
    `PictureController.php` (1357 lines, 11 MysqliDb call sites, ~20
    manual construction chains — the single largest per-file MysqliDb
    count outside Ws) and `GalleryController.php` (620 lines, ~15
    manual chains, zero MysqliDb) migrated. `ImageDerivativeController.php`
    audited and confirmed already fully migrated (part of the earlier
    i.php FrankenPHP-exception retirement work, see the P24 memory
    checkpoint) — zero changes needed, a genuine "already clean" file
    rather than an oversight. Both controllers collapse repeated
    PermissionService/CategoryService/TagService/ActivityService/
    UserService/CommentService chains into private static helpers
    (connection-as-param), and consolidate every `DbConnection::build()`
    call in the request down to one shared `Connection` threaded through
    the closure — restoring the legacy single-global-mysqli-connection
    model instead of the needless-reconnection pattern earlier
    chain-construction debt left behind (Phase 1d's own finding).
    Removed 3 dead globals in `PictureController.php`
    (`$title`/`$refresh`/`$url_link` — set and read entirely within the
    closure, confirmed via a full-`src` grep that no other file reads
    `$GLOBALS` on any of them) and 1 in `GalleryController.php`
    (`$title`, same reasoning). Kept `$url_self`/`$picture`/
    `$related_categories` (real bridges to `PictureCommentRenderer`/
    `PictureRateRenderer`/`PictureMetadataRenderer`, each with their own
    `global` read) and `$filter` (the documented cross-cutting cluster,
    Phase 2's job) unchanged — verified via grep, not assumed. Full
    verification gate green (deptrac 0, ECS clean, PHPStan baseline
    regen — ratio drift only, Unit/Arch 604, Contract 93, Integration
    620, Browser 64+1 skipped, Visual 32, both via the real `composer
    test:browser`/`composer test:visual` scripts).

## What direct investigation found (the basis for phase sequencing)

**DI infrastructure already exists and works — it's just unused.**
PHP-DI (`Core/Container.php`, `config/container.php`) has autowiring on
by default (no `useAutowiring(false)` anywhere), and
`ControllerInvokerMiddleware` already resolves controllers via
`$container->get($result->handler)`. `Kernel::container()`'s own docblock
documents a real Arch-test-enforced rule: "services must receive
dependencies via constructor injection, never look them up through this
locator." Spot-checked `UserService`'s constructor — every parameter is
already a resolvable interface/class type, all already registered
(`MailerInterface`, `ActivityLoggerInterface`, `HtmlRenderingInterface`,
plus `UserRepository`/`GroupRepository` via the already-working
`Connection` entry). `$container->get(UserService::class)` would work
**today**, zero extra wiring. The debt is entirely on the caller side.

**That caller-side debt is more mechanical than it looks.** 815
`new *Repository(`/`new *Service(` call sites across 110 files — but the
exact same long `UserService(new UserRepository(...), new
GroupRepository(...), new MailService(), ...)` chain repeats **verbatim
54 times**. Only ~40-45 distinct domain classes are ever being
constructed this way; the rest is copy-paste of the same recipe. Once
each of those ~40 classes is confirmed autowirable (most already are) and
its callers get real constructors instead of manual assembly, the
hundreds of call sites collapse for free.

**DI and DBAL/ORM migration hit the same files.** Every `new
XRepository(DbConnection::build())` site is simultaneously a DI problem
(manual construction) and a DBAL problem (if `X` still also calls
`MysqliDb::` internally) — fixing both in one pass avoids a second visit.
The four global-residual clusters found in the previous session's final
sweep (`$filter`: 7 files, `$pwg_loaded_plugins`: 4, residual `$template`:
5, residual `$page`: 8) land entirely inside domains this plan already
touches (Bootstrap, Category, Controller, Filter, Menu, Permission,
Section, Admin, Http, Page) — fold them into the same per-domain pass
rather than a separate later phase.

**The DBAL target-state decision (re-confirmed by reading real queries).**
`doctrine/orm` is installed and one domain (`Config`/`ConfigRepository`/
`ConfigEntry`) already uses full ORM (`EntityRepository`, `persist`/
`flush`). But Piwigo's real query shapes — read
`CategoryRepository::findComputedCategoriesRollup()` — are
permission-filtered joins, computed rollups, dynamic WHERE/ORDER-BY
fragments: a poor fit for ORM's object-graph hydration. Verified all 29
`*Repository` classes individually: 28 extend `AbstractRepository`
(constructor-injected `Doctrine\DBAL\Connection`, `QueryBuilder` for real
queries); the 29th, `DbMaintenanceRepository`, doesn't literally extend it
but independently duplicates the identical `Connection`-constructor
pattern and is already 100% `QueryBuilder`-based — zero fixing needed
there either way. This is the proven, working pattern. Target state:
finish it everywhere `MysqliDb::` is still used; keep `ConfigRepository`
as the one deliberate ORM exception.

**Three more real, previously-unlisted gaps, found this pass — one of
which changes Phase 4's scope:**
- **`l10n()` and `get_gallery_home_url()` (a `get_root_url()` sibling) are
  referenced by bare string name, not just called** —
  `Template.php:186`/`:194` register them as Smarty modifiers via
  `registerPlugin('modifier', 'l10n', 'l10n')` (the string `'l10n'` used
  as the actual PHP callable) instead of the first-class-callable syntax
  the same file already uses two lines later for `AccessControl::
  isAdmin(...)` — trivial fix, same file, proven pattern already sitting
  right next to it. But worse: **`themes/default/template/header.tpl`
  and `themes/standard_pages/template/header.tpl` each bare-call
  `l10n('Home')` directly inside `{if $PAGE_TITLE!=l10n('Home') ...}`** —
  Smarty compiles this straight into a literal PHP function call, so
  deleting the global `l10n()` function breaks both templates at render
  time. Phase 4 cannot "delete the free function" as flatly stated;
  needs one of: keep `l10n()` as a permanent thin wrapper (Smarty
  interop, matching the already-accepted `ConfigDb`-style "permanent by
  design" precedent), or edit both templates to stop bare-calling it
  (e.g. precompute an `IS_HOME_PAGE` boolean in `PageHeaderRenderer` and
  pass it in instead of comparing against `l10n('Home')` in-template).
  Decide during Phase 4, before touching any `l10n()` call site.
- **48 files call `die()`/`exit()` directly** (outside `MysqliDb.php`) —
  corrected from an initial 65 after re-checking line-by-line: 17 of the
  original hits were comment/docblock text mentioning "exit()" as a
  concept, not real calls (same class of false-positive this project has
  hit before with dead `global` declarations). Of the real 48, most are
  genuine `die('validation message')`-style fatal-error patterns that
  should become real exceptions — but not all uniformly: e.g.
  `Core/ShutdownHandler.php`'s `exit(143)` is a deliberate SIGTERM-handling
  exit code with its own inline rationale, likely a legitimate exception
  needing individual judgment, not blanket conversion.
- **15 static singleton accessor classes**, scattered across **11
  different domains** (re-checked directly — only 4 of the 15 are
  actually in `Core/`, not "most" as first estimated): `Config::`,
  `Lang::`/`Translator::` (Lang), `CurrentUser::` (Users),
  `CurrentTemplate::` (Template), `PageState::`/`CurrentLogger::`/
  `FilesystemHelper::` (Core), `EventDispatcher::` (PluginConfig),
  `SessionService::` (Session), `MysqliDb::` (Db), `AccessControl::`
  (Auth), `StorageRegistry::` (Storage), `SectionContextRegistry::`
  (Section), `RootPathOverride::` (Url), `CurrentPersistentCache::`/
  `ProcessCache::` (Cache, from the just-completed A-gap work) — a milder
  version of the same global-mutable-state problem raw globals were, each
  needing a case-by-case call (convert to a container-managed injected
  service, or document as a legitimate, bounded exception). Given the
  spread, this can't be concentrated into one domain sub-phase (see
  Phase 1's own note below) — apply the same criteria consistently
  whenever a batch encounters one, starting with a policy decided during
  the 1a pilot.
- Only **96 Unit test files exist for 588 classes** under `src/Piwigo/`
  (569 once the 19 interfaces/enums that don't need behavioral unit tests
  the same way are excluded) — a concrete number for the "testable
  baseline" the user asked for.

## Domain data (files / MysqliDb calls / manual-construction sites / die-exit / raw globals / existing Unit tests), gathered directly per top-level `src/Piwigo/` directory

```
Domain            Files MySQLi New-chains DieExit Globals UnitTests
Admin             237   148    281        23      98      12   (156 of these files are the frozen DbPatch/VersionUpgrade set)
Controller        60    17     326        28      39      1
Ws                24    9      308        2       3       0
Core              46    6      1          1       1       10   (mostly infra; low construction density)
Bootstrap         9     2      51         2       10      5    (the request-scoped wiring root)
Http              18    0      5          1       2       12
Search            13    3      21         1       0       1
Image             12    3      7          0       0       2
Job               11    0      1          0       0       2
Auth              10    3      14         0       2       2
Config            10    1      0          0       4       5
Template          9     0      17         0       1       7
Db                8     0      0          0       11      3
Migrations        8     0      0          0       0       0
Command           8     0      0          0       0       2
Category          6     4      48         0       1       2
Section           6     2      31         0       1       6
Users             6     1      15         1       0       3
Cache             6     1      0          0       0       2
Telemetry         6     0      0          0       0       1
Calendar          7     4      5          0       0       0
Menu              5     0      11         0       1       0
Session           5     1      3          0       0       2
Routing           3     0      0          0       0       3
Site              2     0      4          0       1       0
Tag               3     2      3          0       0       0
Url               3     1      47         1       0       1   (mostly free-function retarget, Phase 4)
Permission        3     2      0          0       1       0
Picture           3     1      9          1       5       0
PluginConfig      3     0      0          0       0       1   (event dispatch, Phase 3)
Lang              3     1      0          0       1       2
Mail              2     2      25         0       3       1
Comment           2     1      3          0       0       0
Group             2     1      2          0       0       0
History           2     1      0          0       0       0
Notification      4     2      0          0       0       0
Permalink         2     1      0          0       0       0
Metadata          2     1      6          1       0       0
Caddie            2     0      1          0       0       0
Activity          2     0      0          0       1       0
Audit             2     0      0          0       0       0
Feed              2     0      0          0       0       1
Backup            1     0      0          1       0       0
Html              1     1      0          1       0       1
Csrf              1     0      0          0       0       1
Filter            1     1      5          0       3       1  (the $filter cluster)
Storage           1     0      0          0       0       1
Rate              2     0      0          0       0       0
Validation        1     0      0          0       0       1
Page              3     0      1          1       1       1  ($page residual)
```

## Phase 1 — Foundation: combined DI + DBAL migration, per domain

Every sub-phase below applies the **same combined fix** to its domain's
files in one pass: retarget `MysqliDb::` calls onto
`Connection`/`QueryBuilder` (matching `AbstractRepository`'s established
shape), give callers real constructors instead of manual `new` chains
(relying on the already-working autowiring), fix any raw globals /
`die()`/`exit()` calls found in the same files, and re-evaluate any
static-singleton usage encountered. One file, one pass — not four passes
across four separate phases. **Exception: 1j (the 156 frozen files)
gets the `MysqliDb::` swap only** — see 1j's own note for why die()/exit()
conversion and DI/construction changes don't apply there the same way.

1. **1a — Pilot (Category, 6 files). DONE, commit `1e23646c6`** — see
   Progress log above.
2. **1b — Small self-contained domains. DONE, 7 commits** (Auth+Session,
   Mail+Notification, Comment+Group+History+Permalink, Tag+Metadata+Site,
   Image+Cache, Picture+Html, Permission+Lang) — see Progress log above.
   Calendar was deliberately deferred out of this sub-phase (its
   `MysqliDb::` usage was judged non-trivial complex dynamic SQL, not a
   quick batch fit) and was instead migrated later as user-directed
   side-work alongside the i.php FrankenPHP-exception retirement — also
   done, see Progress log above.
3. **1c — Search (13 files) + Section (6→7 files, new `SectionRepository`).
   DONE.** See Progress log above. `SectionContext`/`SectionContextRegistry`/
   `SectionUrlParse`/`RandomIndexRedirectResolver` needed no changes at all
   (clean already); `SectionPopulator`/`SectionInitializer` were the only
   Section files with real `MysqliDb::`/manual-chain debt.
4. **1d — Bootstrap (9 files. DONE.)** See Progress log above. 7 of the 9
   files needed zero changes (already container-resolved or zero-arg-only
   construction); `UserBootstrap.php`/`RequestBootstrap.php` had the real
   debt (2 genuine `MysqliDb::` calls, repeated-construction waste,
   redundant global, dead pre-escaping) — full verification gate
   including Contract/Integration/Browser/Visual all green, confirming
   the widest-blast-radius sub-phase landed safely.
5. **1e — Core (47 files, mostly infrastructure). DONE.** See Progress
   log above. **Correction from an earlier draft**: the 15 static-singleton classes
   are NOT concentrated here — only 4 of them (`PageState`,
   `CurrentLogger`, `Lang`, `FilesystemHelper`) live in `Core/`; the rest
   are scattered across 10 other domains (Users, Template, PluginConfig,
   Session, Db, Auth, Storage, Section, Url, Cache). Decide the
   static-singleton conversion-vs-exception **criteria** during 1a's
   pilot (using `Core/PageState` or `Lang` as the first worked example
   since they're touched early anyway), then apply that same criteria
   consistently in whichever sub-phase later encounters each of the
   other 11 classes — don't defer all 15 to this one sub-phase.
6. **1f — Ws (24 files, 308 call sites, 9 MysqliDb files). DONE, 7
   commits** (infrastructure+Comments, Permissions+Groups, Tags, Users,
   Core, Categories, Images) — see Progress log above. Each WS method
   independently assembled its own service graph, as anticipated —
   heavy de-duplication landed via connection-as-param private static
   helpers, reused/re-established per sub-batch (`activityService()`,
   `permissionService()`, `tagService()`, `imageService()`,
   `authService()`, `apiKeyService()`, `groupService()`, `categoryService()`
   depending on the file). One real pre-existing bug found and fixed
   (`PwgCore::ratesDelete()` never executed the DELETE it built — an
   upstream legacy bug, not introduced by this rewrite). One
   cross-cutting gap flagged but deliberately not fixed here (`ConfigDb.php`'s
   own `MysqliDb::` usage has no assigned Phase 1 sub-phase).
7. **1g — Controller (60 files, 326 call sites, 28 die/exit hits — the
   single densest caller layer).** Batch by controller/feature area,
   same rhythm as 1f.
8. **1h — Admin's real domain (81 files: 237 total minus the 156-file
   frozen Install set).** By far the largest single domain; reuse P23
   batch 6's own proven sub-groupings (user/group management, photo/
   picture management, batch manager, maintenance, languages/themes/
   plugins/updates, site management/permalinks, dashboard, configuration,
   notification-by-mail, site-update, plus the `Extensions/`/`Image/`/
   `Integrity/`/`Maintenance/`/`Upload/` subdirectories) rather than
   re-deriving a new breakdown from scratch.
9. **1i — Install/Upgrade orchestration** (`InstallService`,
   `InstallWizard`, `UpgradeRunner`, `UpgradeService`,
   `UpgradeFeedRunner` — the orchestration classes, not the frozen
   scripts). `InstallService::executeSqlfile()` already parses/executes
   SQL statement-by-statement (confirmed by reading it) — trivial 1:1 swap
   to `Connection::executeStatement()`, no multi-statement-blob problem.
   Needs care since this runs before `Config::` is fully populated from a
   real install; `DbConnection::build()` is a static factory usable
   without the container, so this is not actually blocked, just needs the
   right sequencing confirmed per call site.
10. **1j — The 156 frozen DbPatch/VersionUpgrade files: `MysqliDb::` swap
    only.** Last and most mechanical once the pattern is proven
    everywhere else; each still needs individual review since every one
    is a historical, must-stay-byte-correct upgrade step. Included per
    the user's explicit decision (previously deferred three separate
    times, overridden now) — but that decision was specifically about
    "no raw MysqliDb calls left anywhere," a pure execution-API swap
    (same SQL, same result). It does not extend to these files' other
    Phase-1-style fixes: 2 of them also have real `die()`/`exit()` calls
    and 20 also call `ConfigDb::confGetParam()`/`confUpdateParam()`
    directly (see Phase 5's own note on why that specific swap is a
    data-semantics change, not just an API swap, for historical code) —
    leave both untouched here, matching G3's original judgment call on
    these files' `$conf` usage.
11. **1k — Close-out.** Delete `MysqliDb.php` (or reduce to whatever
    proves genuinely irreducible during 1i), add Arch tests locking in
    the new baseline (no `MysqliDb::` references, no un-injected manual
    `new *Repository(`/`new *Service(` chains where a constructor
    parameter would do, no bare `die()`/`exit()` outside a documented
    allowlist — matching the existing "no `define()` calls" precedent in
    `tests/Arch/StructuralTest.php`), full final verification gate,
    commit, completion memory.

### Phase 1 verification (extra rigor)

Same base gate as every prior track (deptrac, PHPStan baseline
regen/diff, ECS, all 5 test suites) — but run the **full** suite after
**every** domain sub-batch (1b-1j), not just at the end. This class of
bug (mysqli returning strings where DBAL returns native types, silently
breaking `is_string()`/type-strict comparisons) has already caused one
real regression this project. Treat every migrated query's result-type
handling, and every controller's newly-injected-vs-previously-inline
dependency graph, as a first-class review item per sub-batch — not a
mechanical find-replace rubber-stamped at the end.

## Phase 2 — Global-residual sweep

Whatever `$filter`/`$pwg_loaded_plugins`/`$template`/`$page` residuals
Phase 1's domain batches didn't already resolve as a side effect (expected
to be near-zero given the domain overlap already confirmed above) gets a
dedicated small sweep here, same investigate → design → implement →
verify → commit rhythm as the closed-out A-gap G1-G5 batches.

## Phase 3 — Event dispatch retarget sweep (Track B)

`add_event_handler()`/`trigger_change()`/`trigger_notify()`
(`PluginConfig/functions.php`) are confirmed pure 1-line delegates to the
already-real `EventDispatcher::get()` (read the free-function bodies
directly) — 301 call sites (39+158+104) across ~88 files. Spot-checked a
sample of real `trigger_change()` call sites: plain positional arguments,
no by-reference or `func_get_args()` edge cases — safe to mechanically
retarget onto `EventDispatcher::get()->addEventHandler()`/
`triggerChange()`/`triggerNotify()` directly, then delete the free
functions and their `function_exists()` guard. Checked for the same
Smarty-bare-string-reference risk that hit Phase 4's `l10n()` (every
`Template.php` `registerPlugin` call and every `.tpl` file) — clean, no
hits, safe to delete outright once call sites are retargeted. Batch by
domain; expect most files already touched by Phase 1 (56 of 88 already
overlap with `MysqliDb::` callers) to need only this one additional
edit.

## Phase 4 — l10n/URL/redirect/category free-function retarget sweep (Track C)

- `Lang/functions.php`'s `l10n()`/`l10n_dec()` → `Piwigo\Core\Lang::t()` —
  841 call sites, the largest single retarget in this plan; spot-checked
  call sites are plain positional/sprintf-style calls, safe to retarget
  mechanically **for the PHP call sites**. `l10n()` cannot simply be
  deleted without also handling the Smarty-interop finding from the
  Context section: `Template.php:186` registers it as a modifier via the
  bare string `'l10n'` (fix: switch to first-class-callable syntax,
  matching `AccessControl::isAdmin(...)` two lines below it in the same
  file), and **two template files** (`themes/default/template/header.tpl`,
  `themes/standard_pages/template/header.tpl`) bare-call `l10n('Home')`
  directly inside `{if $PAGE_TITLE!=l10n('Home') ...}`, which Smarty
  compiles straight into a literal PHP function call. **Decision: no
  permanent wrapper — edit both templates instead.** Likely fix: replace
  the in-template `l10n('Home')` comparison with the `{$var|l10n}`
  modifier syntax against a literal string Smarty can pass through its
  registered modifier (`{if $PAGE_TITLE!='Home'|l10n ...}` or equivalent,
  confirm the exact Smarty syntax during Phase 4), so no bare function
  call remains in either `.tpl` file, then the free function can be
  deleted outright once the modifier registration also points at
  `Lang::t(...)`.
- `Url/functions.php`'s `get_root_url()` and its sibling
  `get_gallery_home_url()` → the real backing URL service — 155 call
  sites. `get_gallery_home_url()` has the identical
  registered-by-bare-string-name issue as `l10n()`
  (`Template.php:194`) — same fix (first-class-callable syntax) — but
  no direct `.tpl` bare-call sites were found for it (checked), so it's
  the simpler of the two.
- `Http/functions.php`'s `redirect_html()`/`redirect_http()` — 17 call
  sites.
- `Category/functions.php`'s `get_subcat_ids()` and siblings →
  `Piwigo\Category\CategoryService` directly.

~1000+ combined call sites — batch by file/directory group, same as
Phase 1 and 3. Checked `redirect_html()`/`redirect_http()`/
`get_subcat_ids()` for the same bare-string-reference failure mode
(`Template.php` `registerPlugin` calls and every `.tpl` file) — clean,
no hits; only `l10n()` and `get_gallery_home_url()` have this problem.

## Phase 5 — ConfigDb direct-call retarget sweep

`Config::`'s SCHEMA is already 100% complete relative to
`config_default.inc.php` (verified: zero missing keys) — mechanical
retarget for real application code. 50 files call `ConfigDb::
confGetParam()`/`confUpdateParam()` directly instead of the typed
`Config::` accessor — but **20 of those 50 are inside the 156-file frozen
DbPatch/VersionUpgrade set already scoped in Phase 1j, and this phase
explicitly excludes them** (checked all key arguments across the
remaining 30: every one is a literal string, no dynamic/variable key
names, so the retarget itself is safe for those). The frozen files are a
different risk profile: swapping their `MysqliDb::` calls for DBAL
equivalents (Phase 1j) is a pure execution-API substitution — same SQL,
same result. Swapping their `ConfigDb::confGetParam('key')` calls for
`Config::key()` is a data-semantics substitution — it assumes `Config::`'s
current typed coercion for that key behaves identically to a raw
DB-row read at whatever historical version that patch targets, which
isn't guaranteed and wasn't true reasoning G3 already applied when it
left these files' `$conf` usage alone. Leave their `ConfigDb::` calls
untouched, matching that same precedent; retarget only the 30 real
application files, mirroring A4 step 7's already-proven pattern.

## Phase 6 — TODO/FIXME/XXX triage

27 markers found across `src/Piwigo/`. Read each individually: fix
genuine leftover work, or convert to a proper explanatory comment (or
delete) if stale/already resolved.

## Phase 7 — Unit test coverage expansion

Once Phase 1 makes the ~40-45 domain classes and controller layer
genuinely mockable (real constructors, injected interfaces instead of
static/global state), add real Unit tests for the highest-value
previously-hard-to-test classes — prioritize `Controller/` (currently 1
Unit test file for 60 classes) and `Ws/` (0 for 24) first, since those are
both the densest and least-covered layers per the domain table above.

## Cross-cutting notes — confirmed out of scope

- **Root-level entry shells** (`admin.php`, `picture.php`, etc.) — read
  `picture.php` directly: already a uniform 30-line `CommonBootstrap::run()`
  + `RequestPipeline::handle()` dispatch through the real routing/
  middleware pipeline. Not legacy debt.
- **`LegacyRenderCapture` and the `void` return signature of its ~50
  renderer classes** — Smarty's inherently echo-based rendering, already
  covered by Browser/Visual-regression suites, not the DI/DBAL/globals
  debt this plan targets. Not a contradiction with Phase 1: many of these
  same classes (e.g. `Admin/HistoryPageRenderer.php`) live inside domains
  Phase 1 touches anyway and will get their construction/globals/
  `MysqliDb::` fixed there — only their `render(): void` signature itself
  stays untouched. The bundled `themes/` Smarty `.tpl` templates
  themselves are also out of scope, same reasoning. Revisit the
  `void`-signature question only if Phase 1-7 completion reveals this
  assumption was wrong.

## Verification (baseline, every phase)

`vendor/bin/deptrac analyse` (0 violations expected), `vendor/bin/phpstan
analyse --generate-baseline` + manual diff review (only typeCoverage
ratio-drift + real fixes, zero new unexplained suppressions),
`composer lint:php` (ECS), and all 5 test suites via
`tools/pest-cleanup.sh --testsuite=Unit,Arch` / `--testsuite=Contract` /
`--testsuite=Integration`, plus `composer test:browser` and
`composer test:visual` — Integration and any other heavy suite run
strictly sequentially, never concurrently (confirmed DB-race risk this
session). Phase 1 gets the additional per-sub-batch full-suite re-run
described in its own section. Each phase closes with the same discipline
used throughout Track A/A5/A-gap: full verification gate, commit(s), a
completion memory, and a task-list update — do not declare a phase done
without re-grepping to confirm (the entire A-gap effort existed only
because an earlier "done" claim wasn't re-verified).
