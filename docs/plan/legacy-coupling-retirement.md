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

## Progress log (updated 2026-07-19)

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
  - **Step 2 — Search/Tags/Comments/Feed cluster. DONE, commit `f544ec5f3`.**
    `SearchController.php`, `TagsController.php`,
    `CommentsController.php` (669 lines, 8 MysqliDb call sites —
    3 `getRecentPeriodExpression()` retargeted onto their established
    `SqlDialect::` sibling from Phase 1a), and `FeedController.php`
    migrated. `QSearchController.php` audited and confirmed already
    fully clean (pure redirect) — zero changes needed. Same
    connection-consolidation/private-helper discipline as step 1;
    `FeedController.php`'s one `die()` converted to its already-in-scope
    `$htmlRenderer->fatalError()` (unlike `ImageDerivativeController`'s 2
    legitimate `die()` calls, this one runs after the renderer already
    exists) — `global $user` in the same file left completely untouched,
    confirmed via Track A3's own memory record as a still-live
    cross-file bridge outside this file's reach alone. **Real bug found
    and fixed via VR, not assumed**: `CommentsController.php`'s
    comment-listing query (`GROUP BY comment_id`, unchanged query text)
    selected `ic.category_id` from the joined table — not functionally
    dependent on the group column — which `Piwigo\Db\DbConnection`
    doesn't tolerate the way the legacy mysqli connection did (the same
    `ONLY_FULL_GROUP_BY` class of issue as `Ws\PwgComments`'s own
    `ANY_VALUE(author)` fix); fixed identically with
    `ANY_VALUE(ic.category_id)`. Full verification gate green after the
    fix (deptrac 0, ECS clean, PHPStan baseline regen — ratio drift
    only, Unit/Arch 604, Contract 93, Integration 620, Browser 64+1
    skipped, Visual 32/32).
  - **Steps 3–10 (all remaining sub-batches). DONE, commit `4fb6c2ff1`.**
    Migrated together and verified once at the end (user direction,
    departing from steps 1–2's per-sub-batch verification rhythm).
    Step 3 (Auth/Identity/Action): `IdentificationController.php`,
    `PasswordController.php`, `RegisterController.php`,
    `ActionController.php`. Step 4 (Profile): `ProfileController.php`,
    `ProfileFormHandler.php` (4 shared helpers collapsing ~10 repeated
    chains, 2 `die()` → `fatalError()`). Step 5 (Notification/Ws/Misc
    top-level): only a dead-`$title`-global cleanup in
    `NotificationController.php`/`NbmController.php`;
    `WsController.php`/`VitalsController.php`/`LegacyRenderCapture.php`
    confirmed already clean. Step 6 (Admin Site/Config):
    `SiteUpdateSubController.php` (1215 lines, the single densest file
    in this domain — 20+ MysqliDb call sites, 4 shared helpers
    including a ported `nextval()`), `SiteManagerSubController.php`,
    `ConfigurationSubController.php` (1300 lines),
    `PermalinksSubController.php`; `MaintenanceSubController.php`
    confirmed already clean. Step 7 (Admin Users/Groups/Rating): all 7
    files confirmed already clean pure delegates — zero changes, data
    access lives in their `*PageRenderer` classes (`Piwigo\Admin\`, out
    of this domain's scope). Step 8 (Admin Photos/Albums/Batch):
    `BatchManagerSubController.php` (3 shared helpers),
    `AlbumSubController.php`; 7 of 9 files confirmed already clean.
    Step 9 (Admin Plugins/Themes/Languages/Updates):
    `PluginSubController.php` (5 `die()` → `fatalError()`, a
    path-traversal-adjacent validation chain), `ThemeSubController.php`
    (3), `UpdatesSubController.php` (1); 4 of 7 files confirmed already
    clean. Step 10 (Admin Misc): `NotificationByMailSubController.php`
    (`BatchWriter`/`SqlDialect` retargets), `AdminPopuphelpController.php`
    (dead-global cleanup only — both `die()`/`exit()` calls confirmed
    deliberate per its own docblock), `IntroSubController.php` (8
    MysqliDb call sites); 6 of 10 files confirmed already clean pure
    delegates. Every `$GLOBALS` bridge found across all 8 steps was
    individually verified via grep against every real reader before
    being left alone or removed — none assumed dead or assumed live.
    **2 real bugs found and fixed via VR, not assumed** (same DBAL
    native-int-casting class already seen repeatedly this plan):
    `Piwigo\Admin\CatModifyPageRenderer.php` (outside this domain, but
    a direct, confirmed consumer of `AlbumSubController.php`'s
    `$GLOBALS['category']` bridge) had `is_string()`-only guards on
    `representative_picture_id`/`site_id` that silently broke once
    `AlbumSubController`'s own query retarget started returning native
    ints instead of always-string mysqli rows — widened to accept both;
    same class in `PermalinksSubController.php`'s own `cat_id` history-table
    lookup. Both confirmed via decode+diff against the VR baseline PNG.
    Full verification gate green (deptrac 0, ECS clean, PHPStan
    baseline regenerated — 3186 errors, down from 3191, net removal
    from now-obsolete suppressions, zero new ones — Unit/Arch 604,
    Contract 93, Integration 620, Browser 64+1 skipped, Visual 32/32).

  **Phase 1g (Controller) is now fully complete — all 60 files across
  10 sub-batches DI+DBAL migrated.**

- **Phase 1h (Admin's real domain). DONE, commit `163ca21a4`.** 81 files
  (237 total under `src/Piwigo/Admin/` minus the 156-file frozen
  `Install/` subtree, out of scope). Reused P23 batch 6's own proven
  sub-groupings, split into 18 sub-batches: small admin pages,
  stats/history, user/group management, photo/picture management,
  photos-add, album/category management, batch manager, maintenance,
  languages/themes/plugins/updates, the `Extensions/`/`Image/`/
  `Integrity/`/`Upload/` subdirectories, and misc top-level utility files
  (`AdminShell`/`AdminUiHelper`/`CoreTabs`/etc). Migrated everything
  first, verified once at the end (user direction, same mode as 1g steps
  3-10). Same connection-consolidation/private-static-helper discipline
  as every prior sub-phase; `MysqliDb::realEscapeString()` verified
  individually per call rather than batch-converted — two confirmed
  still load-bearing (`Extensions/ExtensionUpdateChecker.php`,
  `updates.php`, both feeding `ConfigDb::confUpdateParam()`, which is
  itself still raw-SQL-splicing internally and out of this phase's
  scope) and left untouched. `die()`/`exit()` converted to
  `HtmlService::fatalError()` in 10+ files; deliberately left untouched
  in `Upload/UploadService.php` and `Image/*.php` — confirmed real
  callers include `Ws/PwgImages.php` (needs a JSON error response) and
  `Job/BatchUploadJob.php` (background job, no HTTP response at all),
  matching the established `ImageDerivativeController` precedent.
  One comprehensive gate found ~120 real PHPStan errors (DBAL
  mixed/native-int row values leaking into string/array-key contexts,
  fixed via `is_scalar()`/`is_int()`/`is_string()` guards, never
  suppressed) plus **2 real runtime bugs found via VR/Browser, not
  assumed**: `AlbumsPageRenderer::assocToOrderedTree()` emitted a raw
  native-int category id into the client-side tree JSON — `albums.js`'s
  `open_nodes.includes(node_id)` does strict JS equality against a
  `data-id` DOM attribute (always a string), so the toggler-collapse
  interaction silently broke; fixed by reusing the already
  string-normalized `$cat_id` local instead of the raw row value.
  `UserListPageRenderer.php`'s registration-dates query
  (`SELECT DISTINCT month(...)/year(...) ... ORDER BY registration_date`)
  ordered by a column outside its own SELECT list — silently tolerated
  by legacy mysqli, a hard error under DBAL's connection (real HTTP 500,
  caught by a blank-screenshot Visual Regression failure, decoded and
  pixel-compared rather than dismissed as a flake); fixed by ordering on
  the SELECTed aliases instead. Full verification gate green after both
  fixes (deptrac 0, ECS clean, PHPStan baseline regenerated — clean
  diff, ratio drift only — Unit/Arch 604, Contract 93, Integration 620,
  Browser 64+1 skipped, Visual 32/32).

  **Phase 1h (Admin) is now fully complete — all 81 files across 18
  sub-batches DI+DBAL migrated.**

- **Phase 1i (Install/Upgrade orchestration). DONE, commit `076e411c5`.**
  `InstallService`/`InstallWizard`/`UpgradeRunner`/`UpgradeService`/
  `UpgradeFeedRunner` (5 files) plus their `upgrade.php`/`install.php`
  entry-shell call sites. Found before touching anything: the plan's own
  "trivial 1:1 swap" framing for `MysqliDb::connect()` was wrong —
  `UpgradeRunner::performUpgrade()`/`UpgradeFeedRunner::run()` dispatch
  into the 151 frozen `DbPatch`/`VersionUpgrade` classes (Phase 1j, not
  yet migrated), which still depend on the shared `$mysqli` global
  `connect()` establishes. Kept `MysqliDb::connect()`/`checkVersion()`/
  `checkCharset()` exactly as-is; built a real DBAL `Connection` right
  after each succeeds and threaded it through for every other `MysqliDb::`
  call site instead — safe because `InstallWizard::boot()` already seeds
  `Config`'s `db_*` overrides with the same submitted credentials before
  either connection is built (reused an existing earlier-phase fix, not
  duplicated). Collapsed a redundant double-query execution in
  `UpgradeService::checkUpgradeAccessRights()` as a natural side effect of
  the DBAL retarget (`MysqliDb::query($query)` run once and discarded,
  then re-run inside `fetchAssoc()` — now one `fetchAssociative()` call).
  **3 real bugs found via a real fixture-regen run
  (`composer test:visual` + the opt-in `RegenerateFixtureTest`, not
  assumed):**
  1. `CurrentUser`/`CurrentLogger` were never guest/logger-initialized on
     these 3 no-`Kernel::boot()` entry paths — a theme's missing-
     screenshot fallback, then `UserService::buildUser()`'s activity log,
     each threw an uncaught `LogicException` the instant a retargeted
     consumer touched either singleton. Fixed with `CurrentUser::
     attachGlobals()` (a safe idempotent guest default, designed for
     exactly this) plus the same `Logger` construction recipe
     `RequestBootstrap::connect()` already uses.
  2. `UserService::createUserInfos()` (outside this phase's own file
     scope, but blocking its verification) gated `Config::webmasterId()`/
     `guestId()`/`defaultUserId()` behind `Config::has()`, even though all
     three already have safe hardcoded fallback defaults matching
     `config_default.inc.php`. On a no-boot path those keys are never
     explicitly loaded, so `has()` was false and every created user —
     including the webmaster and guest accounts `install.php` itself just
     created — silently fell through to `'normal'` status. Fixed by
     dropping the redundant `has()` gate.
  3. The VR baseline for `admin-album` needed updating after the fixture
     regen — decoded and pixel-compared both PNGs before touching
     anything: a real "N weeks ago" label shift from the regen running on
     a later real calendar date than the previous baseline capture, not a
     regression.
  Full verification gate green (deptrac 0, ECS clean, PHPStan baseline
  regenerated — 3179 errors, ratio drift only — Unit/Arch 604, Contract
  93, Integration 620, Browser 64+1 skipped, Visual 32/32, plus the
  fixture-regen test itself: 134 assertions).

- **Phase 1j (the 156 frozen DbPatch/VersionUpgrade files). DONE, commit
  `b8c064fbf`.** Real count 151 files (125 DbPatch + 26 VersionUpgrade);
  148 needed `apply(): void` → `apply(Connection $conn): void`
  (`DbPatchInterface`/`VersionUpgradeInterface`, `AbstractRangeVersion
  Upgrade`'s shared range-family base, every concrete `PatchNNN`/
  `UpgradeFrom_X_Y_Z` class — the 3 pure factory/collector classes,
  `DbPatchRegistry`/`VersionUpgradeRegistry`/`DatabaseConfigChanges`,
  have no `apply()` of their own and were untouched). Of 104 files with
  real `MysqliDb::` calls: 83 were pure `query()`-only, handled via a
  reviewed script (every occurrence confirmed byte-identical by grep
  before running it — same "sed/perl only for byte-identical patterns,
  reviewed after" standing exception this project has used before); 21
  with `fetchAssoc`/`fetchRow`/`massInserts`/`massUpdates`/
  `singleUpdate`/`realEscapeString`/`getDbVersion`/`numRows`/
  `booleanToString`/`query2Array` hand-migrated individually. 23 internal
  chain-call sites (`DbPatch->apply()`, `VersionUpgrade->apply()`
  stepping to the next release) updated to pass `$conn` through, plus
  the 2 external callers already `$conn`-aware from Phase 1i.
  `die()`/`exit()` and `ConfigDb::` calls deliberately untouched, exactly
  as this item's own original scope note specified.
  **No existing test exercises `apply()` itself** — the Unit registry
  test (`UpgradeRegistriesTest.php`) only checks `id()`/`description()`/
  `versionFrom()` metadata, and a fresh install marks every patch
  pre-applied without running it (`InstallWizard::performInstall()`'s own
  ledger `INSERT`, seeding `UpgradeService::getAvailableUpgradeIds()`'s
  full id list as already-done). Verified the connection-threading
  mechanism for real regardless: a throwaway smoke script (discarded
  after use, per this project's standing throwaway-script convention)
  called `DbPatchRegistry::make('110')->apply($conn)` against the live
  test DB — the real `$conn->fetchNumeric()`/`executeStatement()` calls
  inside `Patch110` executed successfully end-to-end. (`Patch165`'s own
  smoke-test run threw, but only inside `ConfigDb::confUpdateParam()`'s
  own still-`MysqliDb::`-dependent internals — the same pre-existing,
  out-of-scope `ConfigDb.php` gap flagged since Phase 1f step 1 — not a
  regression from this phase's own changes.) Full verification gate
  green (deptrac 0, ECS clean, PHPStan baseline regenerated — 3096
  errors, down from 3179, ratio drift only — Unit/Arch 604, Contract 93,
  Integration 620, Browser 64+1 skipped, Visual 32/32).

- **Phase 1i/1j gap-closure: `ConfigDb.php`/`UserService.php` migration +
  `MysqliDb::connect()` retirement. DONE, commits `94d5e6408` (ConfigDb/
  UserService) + follow-up (connect retirement).** Triggered by
  re-investigating Phase 1i's own "deferred" note now that 1j is done:
  found two real, previously-unassigned blockers standing between the
  install/upgrade dual-connection design and actually retiring
  `MysqliDb::connect()`.
  1. **`ConfigDb.php`** — a cross-cutting ~63-caller-file dependency, not
     scoped to any Phase 1 sub-phase. Migrated `loadConfFromDb`/
     `confUpdateParam`/`confDeleteParam`/`pwgIsDbconfWriteable` onto DBAL
     with a lazy `?Connection $conn = null` default (matches the
     `MailService`/`HtmlService` high-caller-count precedent). **Found and
     fixed a real SQL-injection vulnerability while migrating**:
     `confUpdateParam()`/`confDeleteParam()` did raw unescaped
     string-splicing (`VALUES('$param','$dbValue')`, `WHERE param
     IN(...)`) — parameterized both via DBAL bound params /
     `ArrayParameterType::STRING`. This made several `MysqliDb::
     realEscapeString()` calls feeding `confUpdateParam()` genuinely dead
     (`Patch125.php`, `updates.php`, `ExtensionUpdateChecker.php`,
     `PwgExtensions.php` x2) — removed.
  2. **`UserService.php`** — 29 un-migrated `MysqliDb::` calls, a gap
     never assigned to any Phase 1 sub-phase despite the class being
     touched by several. Constructor now takes `Connection $conn`
     directly (39 call sites across 32 files threaded via a reviewed
     bracket-matching script; 2 files needed manual fixup where the
     script's insertion produced a double-comma syntax error, caught via
     a full `php -l` sweep, not just diff review).
  With both resolved, re-audited the full reachable graph from
  `InstallService`/`UpgradeService`/`UpgradeFeedRunner`/`RequestBootstrap`
  and found two more, smaller live dependents outside Phase 1's own
  domain list: `Filter/FilterService.php` (2 calls, lazy-optional
  `?Connection` default) and `Url/UrlService.php` (1 call; un-readonly'd
  just the new property since the class-level `readonly` modifier
  otherwise blocks a lazy default and the real caller count — 17 in
  `Url/functions.php` + ~26 Unit tests — ruled out a required param).
  Both exercised on the **normal gallery request path**, not just
  install/upgrade. With those migrated, retired `MysqliDb::connect()`/
  `checkVersion()`/`checkCharset()`/`myError()` entirely from
  `InstallService::installDbConnect()`, `UpgradeService::
  upgradeDbConnect()`, `UpgradeFeedRunner::run()`, `InstallWizard.php`,
  and `RequestBootstrap::connect()` (the normal per-request path) —
  replaced with `DbConnection::build()` + `Connection::
  getNativeConnection()` (the public entry point; `connect()` itself is
  `protected`) for eager-connect-with-friendly-error, plus `DbInfo::
  version()`/`SqlDialect::REQUIRED_MYSQL_VERSION` for the version check,
  already proven in Phase 1j. **A real regression caught by the Visual
  suite, not assumed away**: `it random matches its visual baseline`
  failed with a solid-blank screenshot (67KB baseline vs 7KB actual) —
  decoded and compared both PNGs, then read the Apache error log for the
  actual fatal (`Call to a member function query() on null` inside
  `MysqliDb::query()`, from `random.php:61`). Root cause: `random.php`
  and `upgrade.php`, both **root-level entry scripts outside
  `src/Piwigo/`**, still called `MysqliDb::query2Array()`/
  `DB_RANDOM_FUNCTION`/`checkCharset()` directly — a real blind spot,
  since every `MysqliDb::` sweep this whole gap-closure pass had run was
  scoped to `src/`+`tests/` and never caught them. Fixed both (`random.php`
  onto `SqlDialect::DB_RANDOM_FUNCTION` + `$conn->fetchFirstColumn()`;
  `upgrade.php`'s dead `checkCharset()` call removed) after which a
  repo-wide (not `src/`-scoped) grep confirmed the fix. Verified `upgrade.php`
  couldn't be curl-smoke-tested directly (this sandbox has no legacy-format
  `local/config/database.inc.php`), so `UpgradeService::upgradeDbConnect()`
  was instead exercised directly against the live test DB via a throwaway
  script (discarded after use) — connected, read the real MySQL version,
  and called `checkUpgradeFeed()` successfully.
  **Net effect: a repo-wide grep (`src/`, `tests/`, and every root-level
  `*.php` entry script) now finds zero live callers of any `MysqliDb::`
  method anywhere** — only a dormant, unregistered Rector rule
  (`tools/rector-rules/QueryHashWrapperRector.php`) still names it in a
  code-sample string. `MysqliDb.php` itself was deliberately left
  untouched (deleting/reducing it is Phase 1k's own scope, not folded
  into this gap-closure pass) — flagged as a near-trivial follow-up now
  that every real dependent is gone. Full verification gate green
  (deptrac 0, ECS clean, PHPStan baseline regenerated twice — 3067
  errors both times, ratio drift only — Unit/Arch 604, Contract 93,
  Integration 620, Browser 64+1 skipped, Visual 32/32 including the
  fixed `random` page, plus the fixture-regen test itself: 134
  assertions).

- **Phase 1k (Close-out), part 1: `MysqliDb.php` deletion. DONE.** With
  the prior gap-closure pass leaving zero live callers repo-wide, verified
  exhaustively before deleting: no dynamic/reflection calls
  (`MysqliDb::class`, string class names), no DI container registration,
  no deptrac/PHPStan/ECS/Rector config references, no dedicated Unit test
  file, no Arch test enumerating `Piwigo\Db\` classes, and the class
  itself has no interface/inheritance coupling (`final class MysqliDb`,
  standalone). Deleted the 973-line file, ran `composer dump-autoload`,
  regenerated the PHPStan baseline (3067 → 3049, the drop being the
  file's own now-gone internal suppressions). Full verification gate
  green (deptrac 0, ECS clean — caught 2 files whose ECS fixes from the
  prior commit's pre-commit hook never got restaged, a real lefthook
  gotcha: `ecs --fix` in a pre-commit hook rewrites the working tree but
  `git commit` already snapshotted the index, so the fix silently lands
  as an uncommitted diff after the commit completes unless something
  re-adds it — Unit/Arch 604, Contract 93, Integration 620, Browser
  64+1 skipped, Visual 32/32). Still open: the Arch tests locking in the
  new baseline (manual-DI-chain + `die()`/`exit()` allowlists) — scoped
  but not yet built, see the checklist item above for the raw counts
  found (49 `die()`/`exit()` sites, 768 manual `new *Repository(`/
  `new *Service(` construction sites in `src/Piwigo/`, the overwhelming
  majority almost certainly legitimate) and why encoding either as an
  automated Arch test needs its own dedicated scoping pass rather than a
  quick mechanical add-on.

- **Phase 1k (Close-out), part 2: the two Arch tests. DONE.** User said
  "both now" on the die()/exit() allowlist vs. the DI-chain audit choice.
  1. **`die()`/`exit()` allowlist** — all 49 originally-found sites plus
     12 more found only once token-level scanning was used (`die`/`exit`
     both tokenize as `T_EXIT` regardless of parens/args, so `exit;` with
     no parens — 12 real sites across 8 files — was invisible to the
     original grep), 60 total in the final count. Every site read in
     full; one genuine fix (`Url\UrlService::parseSectionUrl()`'s
     `die('infinite loop?')` → `$this->htmlRenderer->fatalError(...)`,
     since the class already had the dependency injected and this is an
     internal-invariant assertion, not a user-facing reject). Everything
     else allowlisted by rationale category (Ws/ raw-response mechanism,
     the HtmlService/redirect_http() sanctioned exit points, AJAX/JSON
     action endpoints, 503 Retry-After raw responses, image-library
     internals, i.php's pre-bootstrap fast path, frozen historical
     VersionUpgrade classes). Resolved a real previously-deferred
     question along the way: `Picture\PictureCommentRenderer::render()`'s
     2 `die()` calls run inside `LegacyRenderCapture::capture()`'s own
     closure, whose docblock claimed nothing inside it calls
     exit()/die() -- false, and already contradicted by
     `PopuphelpController`/`AdminPopuphelpController`'s own docblocks
     (written in an earlier phase, never cross-referenced). Not a bug:
     PHP flushes the still-open output buffer on exit()/die() by default,
     reproducing the original bare-include-then-die() behavior verbatim.
     Corrected `LegacyRenderCapture`'s docblock and closed the loop on
     `PictureCommentRenderer`'s own. New Arch test:
     `countExitCallsPerFile()` (token-level, `T_EXIT`) + a per-file count
     allowlist in `tests/Arch/StructuralTest.php`.
  2. **DI-chain audit** — 768 raw `new *Repository(`/`new *Service(`
     sites was the wrong denominator; most are ordinary `new
     XRepository($conn)` construction or single-dependency services
     (`ActivityService`, `CsrfService`, `HtmlService`) built fresh where
     needed, an already-established pattern throughout Phase 1
     (`RequestBootstrap::activityService()`, `MailService::authService()`
     precedent). Narrowed to the real anti-pattern signature -- the exact
     same multi-argument, nested `new XService(new YRepository(...), ...)`
     chain repeated verbatim 2+ times in one file (the
     `NotificationByMailSender.php`-class gap from the 1a/1b/1c
     gap-closure) -- 14 exact-duplicate chains found. 8 were genuine gaps
     in DI-eligible classes (a real `__construct()`, not static-only, not
     a free-function file): fixed via a private DRY-extraction helper
     method (`UserService::permissionService()`/`passwordService()`,
     `MailService::userService()` -- UserService genuinely can't be a
     constructor dependency there, same documented cycle as
     `authService()`; `ExtensionLifecycle::activityService()`,
     `BatchManagerUnitPageRenderer`/`PictureModifyPageRenderer`/
     `MaintenanceActionDispatcher::permissionService()`/`categoryService()`,
     `PiwigoInfosSender::userService()`, `AdminShell::imageService()`) or
     a single reused local variable where both call sites were in the
     same method (`UserPermPageRenderer`, `MenubarRenderer`) -- never a
     constructor param: the classes involved either already carry 5-6
     constructor params or the dependency is cheaply reachable from
     already-injected state. The other 6 are structurally exempt, not
     overlooked: `Category/functions.php`/`Http/functions.php` are free
     functions (no `$this`), `Ws/PwgImages.php`/`Ws/PwgCategories.php`'s
     methods are all `public static` (no `$this`), and
     `InstallWizard.php` runs before any DI container exists (a
     constructor param there would only move the manual construction to
     `install.php`, not remove it). New Arch test:
     `findDuplicateServiceConstructionChains()` (balanced-paren chain
     extraction + verbatim-text dedup) + an explicit 6-entry exemption
     list in `tests/Arch/StructuralTest.php`.
  Full verification gate green (deptrac 0, ECS clean, PHPStan baseline
  regenerated — 3051 errors, ratio drift only — Unit/Arch 606, Contract
  93, Integration 620).

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
   single densest caller layer). DONE, 3 commits** (`58b8604af` step 1,
   `f544ec5f3` step 2, `4fb6c2ff1` steps 3-10) — see Progress log above.
   10 sub-batches by controller/feature area, same rhythm as 1f for
   steps 1-2; steps 3-10 migrated together and verified once at the end
   per user direction. Roughly half the 60 files turned out to already
   be clean pure delegates (data access already lives in `Piwigo\Admin\`
   `*PageRenderer` classes, out of this domain's own scope) — confirmed
   file-by-file, not assumed from the pattern. 3 real bugs found and
   fixed via VR across the whole phase (1 `ONLY_FULL_GROUP_BY` case
   matching `Ws\PwgComments`'s own precedent, 2 DBAL native-int-casting
   cases where a `Piwigo\Admin\` renderer's `is_string()`-only guard
   broke once its `$GLOBALS` bridge started receiving native ints from
   a migrated query).
8. **1h — Admin's real domain (81 files: 237 total minus the 156-file
   frozen Install set). DONE, 1 commit** (`163ca21a4`) — see Progress log
   above. Reused P23 batch 6's own proven sub-groupings, split into 18
   sub-batches: small admin pages, stats/history, user/group management,
   photo/picture management, photos-add, album/category management,
   batch manager, maintenance, languages/themes/plugins/updates, the
   `Extensions/`/`Image/`/`Integrity/`/`Upload/` subdirectories, and misc
   top-level utility files (AdminShell/AdminUiHelper/CoreTabs/etc). (The
   P23 batch 6 groupings for site management/permalinks/dashboard/
   configuration/notification-by-mail/site-update live under
   `Controller\Admin\`, not `Admin\` — already done in Phase 1g, out of
   this phase's own scope.) Migrated everything first, verified once at
   the end (user direction, same mode as 1g steps 3-10). One comprehensive
   gate found ~120 real PHPStan type errors (DBAL mixed/native-int row
   values leaking into string/array-key contexts) plus 2 real runtime
   bugs: `AlbumsPageRenderer`'s tree JSON leaking a native int category id
   broke `albums.js`'s strict-equality `open_nodes.includes()` toggle
   state (caught by a real browser interaction test, not just a
   screenshot diff); `UserListPageRenderer`'s `SELECT DISTINCT ... ORDER
   BY registration_date` (ordering by a column outside its own SELECT
   list) is silently accepted by mysqli but rejected outright by DBAL's
   connection — a real HTTP 500 caught by a blank-page Visual Regression
   failure, fixed by ordering on the SELECTed aliases instead. `die()`
   calls in `Upload/UploadService.php` and `Image/*.php` deliberately left
   untouched — confirmed real WS-API and background-job (`Job\
   BatchUploadJob`) callers with no HTML response pipeline to route a
   `HtmlService::fatalError()` through, matching `ImageDerivativeController`'s
   established exception.
9. **1i — Install/Upgrade orchestration (`InstallService`, `InstallWizard`,
   `UpgradeRunner`, `UpgradeService`, `UpgradeFeedRunner` — the
   orchestration classes, not the frozen scripts). DONE, commit
   `076e411c5`.** The "trivial 1:1 swap" framing above was wrong on one
   point, found by reading the full call graph before touching anything:
   `MysqliDb::connect()`/`checkVersion()`/`checkCharset()` could **not**
   simply become `DbConnection::build()`, because `UpgradeRunner::
   performUpgrade()`/`UpgradeFeedRunner::run()` dispatch into the 151
   frozen `DbPatch`/`VersionUpgrade` classes (Phase 1j, not yet migrated),
   which still depend on the shared `$mysqli` global those calls
   establish. Kept the legacy connect calls exactly as-is; a real DBAL
   `Connection` is additionally built right after each one succeeds and
   threaded through (constructor param for `UpgradeRunner`, per-method
   param for `UpgradeService`'s static methods) for every *other*
   `MysqliDb::` call site in these 5 files — safe because `InstallWizard::
   boot()` already seeds `Config`'s `db_*` overrides with the same
   submitted credentials before either connection is built (a pre-existing
   fix from an earlier phase, reused here rather than duplicated).
   `upgrade.php`'s own direct `MysqliDb::` call sites were retargeted the
   same way; its `UpgradeRunner`/`UpgradeService::` call sites updated for
   the new signatures. **3 real bugs found via a real fixture-regen run
   (`composer test:visual` + the opt-in `RegenerateFixtureTest`, not
   assumed):** `CurrentUser`/`CurrentLogger` were never guest/logger-
   initialized on these 3 no-`Kernel::boot()` entry paths (a themes
   screenshot fallback, then `UserService::buildUser()`'s activity log,
   each threw an uncaught `LogicException` the instant a retargeted
   consumer touched either singleton) — fixed with `CurrentUser::
   attachGlobals()` plus the same `Logger` construction recipe
   `RequestBootstrap::connect()` already uses; and `UserService::
   createUserInfos()` (outside this phase's own file scope, but blocking
   its verification) gated `Config::webmasterId()`/`guestId()`/
   `defaultUserId()` behind `Config::has()` even though all three already
   have safe hardcoded fallback defaults — on a no-boot path those keys
   are never explicitly loaded, so `has()` was false and every created
   user, including the webmaster and guest accounts `install.php` itself
   just created, silently fell through to `'normal'` status. VR baseline
   for `admin-album` updated after decoding and comparing both PNGs — a
   real "N weeks ago" label shift from the regen running on a later
   real calendar date, not a regression. Full gate green (deptrac 0, ECS
   clean, PHPStan baseline regenerated — 3179 errors, ratio drift only —
   Unit/Arch 604, Contract 93, Integration 620, Browser 64+1 skipped,
   Visual 32/32, plus the fixture-regen test itself: 134 assertions).
10. **1j — The 156 frozen DbPatch/VersionUpgrade files: `MysqliDb::` swap
    only. DONE, commit `b8c064fbf`.** Real count was 151 files (125
    DbPatch + 26 VersionUpgrade); 148 needed the `apply(): void` →
    `apply(Connection $conn): void` signature change (`DbPatchInterface`/
    `VersionUpgradeInterface` themselves, `AbstractRangeVersionUpgrade`'s
    shared range-family base, and every concrete `PatchNNN`/
    `UpgradeFrom_X_Y_Z` class); `DbPatchRegistry`/`VersionUpgradeRegistry`/
    `DatabaseConfigChanges` are pure factories/collectors with no `apply()`
    of their own, untouched. 104 files had real `MysqliDb::` calls to
    retarget (83 pure `query()`-only files handled via a reviewed script —
    confirmed byte-identical across every occurrence by grep before
    running it — 21 with `fetchAssoc`/`fetchRow`/`massInserts`/
    `massUpdates`/`singleUpdate`/`realEscapeString`/`getDbVersion`/
    `numRows`/`booleanToString`/`query2Array` hand-migrated individually,
    same discipline as every prior phase). Every chain call
    (`DbPatch->apply()`, `VersionUpgrade->apply()` stepping to the next
    release) updated to pass `$conn` through — 23 such sites, plus the 2
    external callers (`UpgradeFeedRunner`/`UpgradeRunner`, already
    `$conn`-aware from Phase 1i). `die()`/`exit()` and `ConfigDb::` calls
    deliberately untouched, exactly as this note originally scoped.
    **No existing test exercises `apply()` itself** — the Unit registry
    test only checks `id()`/`description()`/`versionFrom()` metadata, and
    a fresh install marks every patch pre-applied without ever running it
    (`performInstall()`'s own ledger `INSERT`) — verified the
    connection-threading mechanism for real via a throwaway smoke script
    calling `Patch110::apply($conn)` against the live test DB (real DBAL
    `fetchNumeric()`/`executeStatement()` calls succeeded end-to-end),
    then discarded it, per this project's standing throwaway-script
    convention. Full verification gate green (deptrac 0, ECS clean,
    PHPStan baseline regenerated — 3096 errors, down from 3179, ratio
    drift only — Unit/Arch 604, Contract 93, Integration 620, Browser
    64+1 skipped, Visual 32/32).
11. **1k — Close-out. DONE, both parts (see Progress log below).**
    `MysqliDb.php` deletion — turned out fully irreducible-to-zero, not
    merely reducible; every real dependent across the gap-closure pass
    was gone. The two Arch tests locking in the new baseline — no bare
    `die()`/`exit()` outside a documented allowlist, no un-injected
    manual `new *Repository(`/`new *Service(` chain where a constructor
    parameter (or a private DRY-extraction helper, for the classes that
    genuinely can't take one) would do — both landed in
    `tests/Arch/StructuralTest.php`, matching the existing "no `define()`
    calls" precedent.

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

## Phase 2 — Global-residual sweep. DONE.

The "near-zero" hope in this phase's own original framing (below) did not
pan out — direct investigation (comment-aware token scan, no subagents)
found real residual close to the original pre-Phase-1 estimate: `$filter`
8 sites/7 files, `$pwg_loaded_plugins` 5/4, `$template` 9/7 (8 `global` +
1 `$GLOBALS[...]`), `$page` 7/7. Confirmed via a repo-wide (not just
`src/`-scoped) scan that nothing outside `src/Piwigo/`+`tests/` touches
any of these four.

Each cluster needed a genuinely different fix, not one mechanical
pattern:

- **`$template`** — pure retarget onto the already-existing
  `Template\CurrentTemplate` singleton. Most sites were already calling
  `CurrentTemplate::set()` right after the `global` write, making the
  `global` declaration itself vestigial. `Bootstrap\RequestBootstrap.php`
  was the last of the 3 documented dual-write sites (kept live "until
  every consumer is retargeted" during its own earlier migration) —
  retired only after a fresh repo-wide grep confirmed zero remaining
  consumers. `Http\functions.php`'s `redirect_html()` needed care to
  preserve a real early-crash-before-bootstrap fallback path
  (`isset($template)` → `CurrentTemplate::isInitialized()`), not a blind
  swap. Retargeting `Html\HtmlService.php`/`Http\functions.php` gave
  PHPStan enough type information to *prove* two old defensive
  re-checks — kept in the original code specifically because they
  "weren't provable statically" under an untyped raw global — are now
  genuinely dead code; removed both (retire-the-defensive-check-with-the-
  retype, not just retarget).
- **`$page`** — mostly dead or purely local, one real judgment call. 5 of
  7 files had exactly one method each, so every `$page[...]` access was
  provably local to that one method's execution — same fix already
  proven in `Section\SectionPopulator.php` (Track A5.2e): drop `global`,
  keep a plain local. `Admin\HistoryPageRenderer.php`'s `$page['search']`
  turned out to be a **confirmed-dead read**: its own docblock's
  justification named a file (`include/ws_functions/pwg.php`) that no
  longer exists (migrated to `Ws\PwgCore::historySearch()`, whose own
  `$page['search']` write has no `global` declaration at all — an
  unrelated same-named local) — removed the dead branch entirely, same
  precedent this file's own docblock already set for its dead siblings.
  `Admin\Maintenance\FilesystemIntegrityChecker::fsQuickCheck()`'s
  run-once guard, by contrast, was **confirmed genuinely load-bearing**:
  traced the real call chain and found `admin.php`'s own top-level
  dispatcher (`AdminShell::run()`) calls it directly, then dispatches to
  a sub-controller (`Controller\Admin\IntroSubController`) that calls it
  again in that same request — replaced the global-keyed guard with a
  private static bool + test-only `reset()`.
- **`$filter`** — needed a genuinely new singleton
  (`Piwigo\Core\FilterState`), and its layer placement was the single
  most consequential decision in this phase. `Piwigo\Filter` sits at
  `L2bExtendedDomain` in `deptrac.yaml`; 2 of the 5 real readers
  (`Permission\PermissionService`, `Category\CategoryService`) sit at
  `L2aCoreDomain`, which may not depend sideways on L2b. A `CurrentFilter`
  class living in `Piwigo\Filter` (the first draft's plan) would have
  failed `deptrac` immediately. This is very likely *why* `$filter`
  survived every one of Phase 1's 11 sub-phases as a raw global:
  `deptrac.yaml`'s own comment on Filter's layer placement already names
  the `$filter` global as the reason that domain needed no
  domain-namespace dependency of its own — i.e. probably a deliberate
  original workaround for this exact conflict, not an oversight.
  `FilterState` lives directly in `Piwigo\Core` instead, matching
  `Core\PageState`'s own precedent (full singleton, not a split
  interface+impl) — L1Infrastructure sits below every layer, so every
  reader works regardless of its own layer, with zero constructor
  ripple into `PermissionService`'s 30+ real construction call sites
  (confirmed via a real grep, not assumed — adding a required
  constructor param there would have meant an audit at the same scale as
  this session's own `UserService.php` constructor change, 39 sites).
  Preserved a real behavioral subtlety while retargeting
  `Controller\PictureController.php`: the raw global's occasional `-1`
  int sentinel (meaning "the filter computed an empty visible-images
  list") was implicitly excluded by an `is_string()` type check;
  `FilterState::visibleImages()` always returns a real string, so the
  sentinel is now excluded by an explicit `!== '-1'` check instead —
  same behavior, no longer accidental.
- **`$pwg_loaded_plugins`** — the simplest cluster: writer and all 3
  readers are all `L4Integration` (`Piwigo\Admin`/`Piwigo\Controller\Admin`),
  so no cross-layer decision was needed. New `Admin\LoadedPlugins`
  singleton, same `get()`/`set()`/`isInitialized()`/`reset()` shape as
  `CurrentTemplate`.

8 test files wrote `$GLOBALS['filter']`/`$GLOBALS['page']` directly;
6 Integration tests needed real updates (mostly deleting now-redundant
`$GLOBALS['filter'] = [];` boilerplate once `IntegrationTestCase::setUp()`
was given its own `FilterState::set(false)` baseline — the same
per-request-singleton pattern already established there for
`CurrentUser`/`CurrentLogger`), plus the one Unit test
(`Filter\FilterServiceTest.php`) exercising `updateCatsWithFilteredData()`
directly. One test scenario ("filter categories is not an array") became
unreachable through the new type-safe `FilterState::set()` API and was
deleted, not preserved artificially — the equivalent defensive guard now
lives once, at the `FilterState::set()` write site in
`FilterService::initializeFromRequest()`, not on every read.

Closed out with a new Arch test (`tests/Arch/StructuralTest.php`)
asserting zero remaining `global $filter`/`$pwg_loaded_plugins`/
`$template`/`$page` in `src/Piwigo/` — genuinely zero-tolerance, no
allowlist needed, since every real site turned out to be fixable rather
than a permanent bridge worth documenting as an exception.

Full verification gate green (deptrac 0, ECS clean, PHPStan baseline
regenerated — 3039 errors, down from 3051, ratio drift plus real
dead-code removal — Unit/Arch 606, Contract 93, Integration 620, Browser
64+1 skipped, Visual 32/32).

The phase's own original framing, kept for context: whatever
`$filter`/`$pwg_loaded_plugins`/`$template`/`$page` residuals Phase 1's
domain batches didn't already resolve as a side effect (expected to be
near-zero given the domain overlap already confirmed above) gets a
dedicated small sweep here, same investigate → design → implement →
verify → commit rhythm as the closed-out A-gap G1-G5 batches.

## Phase 3 — Event dispatch retarget sweep (Track B). DONE.

The phase's own pre-Phase-1/2 call-site estimate (301, 39+158+104 across
~88 files) needed re-verifying, not trusting — same lesson as Phase 2's
`$filter`/`$page` residuals turning out non-trivial. A fresh comment-aware
token scan found 241 real call sites (28 `add_event_handler(` + 119
`trigger_change(` + 94 `trigger_notify(`) across 84 files — same order of
magnitude, corrected number.

Adversarial validation (re-checking the plan's "safe to mechanically
retarget" claim against `deptrac.yaml`, same discipline as Phase 2's
`$filter` catch) found a real design flaw before any code shipped: a free
function call creates no deptrac dependency edge, but a direct class
reference does, so the callers' current zero-violation state didn't
predict the post-retarget state. `Piwigo\PluginConfig` (housing
`EventDispatcher`) sat at `L2bExtendedDomain`; 3 real callers live in
`L1Infrastructure` (`Config\ConfigDb`, `Core\ThemeCatalog`,
`Cache\UserCacheInvalidator`) and 11 in `L2aCoreDomain` (`Auth\AuthService`,
`Users\UserRepository`/`UserService`, `Category\CategoryDefaultRenderer`/
`CategoryService`/`CategoryCatsRenderer`, `Image\SrcImage`/`ImageService`/
`DerivativeImage`, `Group\GroupService`, `Tag\TagService`) — under this
repo's strict layering (no `skip_violations`), retargeting these 14 files
onto `EventDispatcher::get()` directly would have been a real L1→L2b/
L2a→L2b violation for every one of them, invisible today only because the
untracked free-function form hid it. Fixed by splitting
`Piwigo\PluginConfig` across two layers in `deptrac.yaml`:
`EventDispatcher` moved to `L1Infrastructure` (confirmed the lowest layer
that covers every real caller, and the architecturally correct fit —
`EventDispatcher.php` itself injects nothing and is a generic pub/sub bus
reachable from every layer, same shape as `Cache`/`Session`/`Storage`/
`Audit`'s own L1 placement), `PluginRepository` stayed `L2bExtendedDomain`
(its only 2 real callers, `Admin\PluginLoader`/`Admin\plugins.php`, are
both `L4Integration`, already legally above L2b).

Retarget itself was a pure, safe mechanical text substitution — confirmed,
not assumed: `trigger_change()`'s `func_get_args()`-based forwarding to
`EventDispatcher::triggerChange(string $event, mixed $data = null, mixed
...$extra)` means PHP's own variadic parameter-binding captures every
caller's extra positional arguments automatically, so a literal
`funcname(` → `EventDispatcher::get()->methodName(` substring swap
(arguments untouched) is correct even for the 99 multi-arg
`trigger_change()` call sites. Zero dynamic/variable-name calls, zero
string-callable references, zero Smarty `.tpl`/`registerPlugin` hits, zero
test-side stub redeclarations, no by-reference params.

Used a token-aware PHP script (`token_get_all()`-based, not regex — skips
comments/strings automatically, and skips `->`/`::`/`function`-preceded
occurrences defensively) to rewrite all 241 sites in one reviewed pass,
dry-run and diff-reviewed against representative samples first (a
single-call file, `Bootstrap\RequestBootstrap.php` at 20 calls, the
closure-callable pattern in `Admin\Integrity\c13y_internal.php`, and the
multi-arg variadic pattern in `Auth\AuthService.php`). Fully-qualifies
inline (`\Piwigo\PluginConfig\EventDispatcher::get()->...`) rather than
adding a `use` import, matching this codebase's own established
convention for singleton retargets (`CurrentTemplate::get()` is
fully-qualified inline across all 91 existing call sites, never a `use`
import, regardless of per-file call count).

Deleted `src/Piwigo/PluginConfig/functions.php` and its `composer.json`
`autoload.files` entry once a fresh re-scan confirmed zero remaining real
callers; `composer dump-autoload` regenerated. Swept ~9 stale "no local
stub needed, always available via composer autoload.files" test-side
comments (and 2 real-code docblocks, `Controller\ImageDerivativeController.php`
and `Controller\PictureController.php`, which made present-tense claims
about the now-deleted mechanism) to describe the real
`EventDispatcher::get()` singleton instead.

Closed out with a new Arch test (`tests/Arch/StructuralTest.php`)
asserting zero remaining bare `add_event_handler(`/`trigger_change(`/
`trigger_notify(` calls in `src/Piwigo/` — genuinely zero-tolerance, no
allowlist needed.

Full verification gate green: deptrac 0 (both right after the layer split
alone and again after the full retarget), PHPStan baseline regenerated
(3034, down from 3039 — the deleted file's own ignored-error entries),
ECS clean, Unit/Arch 607, Contract 93, Integration 620, Browser 64+1
skipped, Visual 32/32.

The phase's own original framing, kept for context: `add_event_handler()`/
`trigger_change()`/`trigger_notify()` (`PluginConfig/functions.php`) are
confirmed pure 1-line delegates to the already-real `EventDispatcher::get()`
(read the free-function bodies directly) — 301 call sites (39+158+104)
across ~88 files. Spot-checked a sample of real `trigger_change()` call
sites: plain positional arguments, no by-reference or `func_get_args()`
edge cases — safe to mechanically retarget onto
`EventDispatcher::get()->addEventHandler()`/`triggerChange()`/
`triggerNotify()` directly, then delete the free functions and their
`function_exists()` guard. Checked for the same Smarty-bare-string-reference
risk that hit Phase 4's `l10n()` (every `Template.php` `registerPlugin`
call and every `.tpl` file) — clean, no hits, safe to delete outright once
call sites are retargeted. Batch by domain; expect most files already
touched by Phase 1 (56 of 88 already overlap with `MysqliDb::` callers) to
need only this one additional edit.

## Phase 4 — l10n/URL/redirect/category free-function retarget sweep (Track C). DONE.

Retired all four remaining `composer.json` `autoload.files` free-function
bridges, in the dependency order the design required (Category 4a → Http
4b → Url 4c, since `UrlService` needed `RedirectServiceInterface` before
its own retarget could land → Lang 4d, independent of the other three).
Commits `da88ead93` (4a), `29ac1a204` (4b), `9705b7566` (4c), `bfefe6f19`
(4d) — a full completion write-up for each lives in project memory
(`project_p24_phase4{a,b,c,d}_complete.md`); summary here:

- **4a (Category, 7 functions)** — `get_uppercat_ids()`'s body moved onto
  a new `CategoryRepository::findUppercatIds()` to resolve a real
  `PermissionService` ↔ `CategoryService` circularity;
  `CategoryAdminServiceTest.php` moved from Unit to Integration (final
  classes block stubbing once real methods replace free functions). 2
  real bugs found: an all-unknown-category-list SQL bug the old stubbed
  test had masked, and a `random.php` construction site with only 2 of 3
  now-required args (blank-page fatal, caught by Visual regression).
- **4b (Http, 3 functions)** — new `Piwigo\Core\RedirectServiceInterface`
  (L1) + `Piwigo\Bootstrap\RedirectService` (L4, body moved verbatim).
  `HtmlRenderingInterface`'s 4 terminal methods
  (`accessDenied`/`badRequest`/`pageNotFound`/`pageForbidden`) gained a
  required `RedirectServiceInterface` *method* parameter instead of a
  constructor dependency (`HtmlService` has hundreds of construction
  sites; the concrete class is L4, `HtmlService` is L3, deptrac forbids
  the direct dependency). 95 files touched (vs. the original plan's "34"
  estimate) once that ripple was accounted for.
- **4c (Url, 17 functions after `parse_section_url` was retired in an
  earlier session)** — new `Piwigo\Core\UrlServiceInterface` (L1) +
  existing `Piwigo\Url\UrlService` (L2b). Resolved a real
  `UrlService → HtmlRenderingInterface → HtmlService →
  UrlServiceInterface → UrlService` cycle: classes inside
  `RedirectService`'s own construction chain (`HtmlService`,
  `MailService`, `UserService`, `Template`, `PageHeaderRenderer`)
  construct a throwaway `new UrlService(new HtmlService())` per call site
  instead of constructor-injecting the interface; `SrcImage`/
  `DerivativeImage`/`Template\ScriptLoader` (a `uasort()`-bound
  first-class-callable with an externally-fixed signature) use the same
  static-setter pattern already established for other L2a
  cross-cutting statics. 166 files touched. A live production fatal
  (`Template.php`'s `'url_is_remote'`/`'get_gallery_home_url'` Smarty
  modifier registrations were still bare-string references to the
  deleted free functions) was caught by the Contract suite against the
  running webserver, not static analysis — fixed with first-class-
  callable registrations. 3 more real bugs (missed construction sites,
  a wrong-scope variable reference) caught by 2 successive full-project
  PHPStan sweeps.
- **4d (Lang, 2 functions, 850 call sites — the largest single sweep)**
  — `l10n()` → `Lang::t()` is a true 1:1 rename (`Translator::translate()`
  already does the same scalar coercion), applied via a reviewed,
  token-aware batch script across all 817 sites. `l10n_dec()` →
  `Translator::get()->plural()` needed a strict-`int` third argument,
  so all 33 sites were reviewed individually rather than scripted; one
  new `Lang::plural()` thin wrapper (sibling to `Lang::t()`) added
  specifically for `Template::modcompiler_translate_dec()`'s generated-
  code fallback, the one call site whose runtime type can't be proven
  statically. `debug_l10n`'s missing-key diagnostic moved from `l10n()`'s
  body into `Translator::translate()` itself (more accurate resolution-
  path check, and now applies to every `Lang::t()` caller, not just former
  `l10n()` ones).
- **4e (close-out)** — a new zero-tolerance Arch test
  (`tests/Arch/StructuralTest.php`, `findBareCallSites()`) asserts no bare
  call to any of the 29 retired function names remains anywhere under
  `src/Piwigo/` — token-aware rather than the simpler
  `findCallSitesOutsideComments()` substring check the Phase 2/3 tests use,
  since several retired names (`redirect` most visibly) collide as a bare
  substring with a real, still-legitimate method call of the identical
  short name (`$this->redirectService->redirect(...)`). `composer.json`'s
  now-empty `autoload.files` key removed entirely (Track C was the last of
  its four entries). Two mid-sweep, whole-repo re-verification passes
  (prompted by an explicit reminder not to scope searches to curated
  subfolders) confirmed both Phase 4c's and 4d's own closing sweeps had
  found every real call site — zero stragglers in previously-unscanned
  directories (`include/`, `language/`, `galleries/`, `doc/`, `.github/`).
  One of those passes found 2 more `.tpl` bare-call sites from Phase 4c
  (`url_is_remote` in `picture_modify.tpl`/`batch_manager_unit.tpl`) that
  turned out, on empirical verification, to already compile safely (Smarty
  routes a bare `identifier(args)` call through the same
  `getModifierCallback()` path as `|modifier` pipe syntax whenever
  `identifier` matches an already-registered modifier plugin name) —
  rewritten to pipe syntax anyway as a clarity improvement, not reported
  as a fixed regression.

Full verification gate green at every sub-phase close: `php -l` sweep,
`deptrac analyse` (0 violations throughout), PHPStan (0 errors against a
baseline regenerated once per sub-phase, for real ratio-drift/deleted-file
entries only), ECS, Unit/Arch/Contract, Integration, Browser, and Visual
(0 pixel diffs) — Smarty's compiled-template cache
(`_data/templates_c/*.tpl.php`) cleared before every Browser/Visual run
touching a `Template.php` `registerPlugin()` change, per this phase's own
cross-cutting finding on exactly that failure mode.

The phase's own original framing, kept for context:

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

## Phase 5 — ConfigDb direct-call retarget sweep. DONE.

Retargeted every real application caller of `Piwigo\Config\ConfigDb::
confGetParam()`/`confUpdateParam()`/`confDeleteParam()`/`loadConfFromDb()`/
`pwgIsDbconfWriteable()` onto typed, DI-friendly alternatives: reads onto
`Piwigo\Config\Config::`'s static SCHEMA-driven accessors (or its untyped
`Config::all()` bag for keys with no SCHEMA entry); writes onto
`Piwigo\Config\ConfigService` (constructor injection for classes with no
pre-container reachability — Tier 1) or a new
`Piwigo\Config\CurrentConfigService` static registry (for static
utilities/throwaway-constructed classes whose write only ever fires
post-container — Tier 2). A genuinely pre-container remainder (the 20
already-frozen `DbPatch`/`VersionUpgrade` files from Phase 1j, plus ~13
individually-traced classes/scripts — Tier 3) stays on `ConfigDb`
permanently, each documented on its own class docblock and enforced by a
two-part Arch test (zero `confGetParam(` anywhere; the four write methods
restricted to exactly the Tier 3 set). Commits `ac88917a8` (5a), `b91efc8e7`
(5b), `1ca6d140e` (5c), `12c09e0f5` (5d), `2c118a8b5` (5e), plus 5f
close-out — a full completion write-up for each lives in project memory
(`project_p24_phase5{a,b,c,d,e,f}_complete.md`); summary here:

- **5a (Step 0 prerequisites)** — retired the last 2 real internal `$conf`
  reads (`random.php`, `themes/standard_pages/themeconf.inc.php`); fixed
  `ConfigService::loadConfFromDb()`'s missing `load_conf` plugin-hook
  dispatch (present in `ConfigDb::loadConfFromDb()`, silently dropped
  since P13); corrected three stale "deferred to P14" docblocks across
  `Config.php`/`ConfigService.php`/`ConfigLoader.php` that no longer
  matched reality.
- **5b (Reads, 11 files)** — every real `confGetParam()` call site onto
  `Config::`, no DI changes (both `Config::` and `ConfigDb::` are static).
  PHPStan-driven cleanup once call sites landed on properly typed
  accessors: retired now-always-true `is_string()`/`is_array()` guards and
  a now-redundant `ArrayHelper::safeUnserialize()` call.
- **5c (`CurrentConfigService` infrastructure)** — new static registry
  class mirroring `CurrentLogger`'s exact shape, wired from both
  `CommonBootstrap::run()` and `CliBootstrap::buildApplication()` (the
  latter deliberately skips the accompanying `loadConfFromDb()` call —
  HTTP-only, since CLI commands can run pre-migration). New opt-in
  `IntegrationTestCase::buildConfigRepository()` helper for tests that
  need a real, DB-backed `ConfigService`.
- **5d (Tier 1 writes, 10 classes + 2 raw-SQL fixes)** — constructor-
  injected `ConfigService` into `ThemesStandardPagesPageRenderer`,
  `ExtensionLifecycle`, `MaintenanceActionDispatcher`, `CoreUpdateService`,
  `ConfigurationSubController`, `AdminShell`, `ExtensionUpdateChecker`,
  `HistoryService`, `NotificationByMailSubController`, `GroupService`.
  Fixed two of the three raw-SQL config-write vulnerabilities discovered
  while investigating `Config::`'s custom accessors (see below):
  `ConfigurationSubController`'s generic config-row save loop and the new
  Tier 1 addition `ExtendForTemplatesPageRenderer`'s `extents_for_templates`
  write. Two real bugs caught by full-project PHPStan/Arch sweeps: two
  `static function () use (...)` closures (`GalleryController`,
  `PictureController`) referencing `$this->configService` — static
  closures don't capture `$this`, fixed by threading a local copy through
  `use()` — and a repeated-multi-dependency-chain Arch test violation from
  `HistoryService`'s now-2-arg constructor, fixed via
  `self::historyService()`/`$this->historyService()` DRY-extraction
  helpers in `Ws\PwgCore`/`ActionController`. A third real bug, found only
  by running the full Browser suite: `ConfigurationSubController`'s
  `picture_informations`/`filters_views` pre-processing wrapped its
  `serialize()`d value in `addslashes()` — a workaround that only worked
  because the old raw-SQL write's quote-doubling transform happened to
  strip it back out during the MySQL round trip; the new parameterized
  `ConfigService` write stores the value verbatim, so the wrapper
  corrupted every subsequent `unserialize()`. Fixed at the source by
  removing the now-obsolete `addslashes()` call.
- **5e (Tier 2 writes, 8 classes + 1 raw-SQL fix)** — retargeted
  `PiwigoInfosSender`, `Admin\Upload\UploadService`,
  `Admin\Maintenance\FilesystemIntegrityChecker`, `Ws\PwgCore`'s own
  `cache_sizes` write, `Ws\PwgUsers`, `Ws\PwgExtensions` onto
  `CurrentConfigService::get()`. Fixed the third raw-SQL fix:
  `Admin\Integrity\check_integrity::update_conf()` (zero escaping
  attempted, the worst of the three) onto a parameterized
  `confUpdateParam()` call. Found and reverted a real reachability gap in
  this phase's own plan: `Core\UniqueExecLock::ends()` looked
  post-container-only but is also reachable pre-container via
  `Bootstrap\RequestBootstrap::connect()` →
  `Bootstrap\UserBootstrap::initialize()` →
  `Users\UserService::getUserData()` → `begins()`'s own timeout branch
  calling `ends()` — a path that runs on every single request, before
  `Kernel::boot()`. Caught via a live HTTP 500
  (`CurrentConfigService not initialised`) surfacing through nearly every
  Contract test; `UniqueExecLock::ends()` stays on `ConfigDb` (Tier 3).
- **5f (Close-out)** — Tier 3 docblock notes on every stay-on-`ConfigDb`
  class; the two-part zero-tolerance Arch test (comment-aware, matching
  this file's existing `findCallSitesOutsideComments()` convention, since
  several of this phase's own docblocks name the retired accessor in
  prose); this doc + memory updated.

**Three real, if low-severity/webmaster-gated, security fixes** were
folded into this phase's scope on direct instruction, discovered while
investigating `Config::`'s custom accessors (`Config::pictureInformations()`
and `Config::extentsForTemplates()` both named their real writer files in
their own docblocks, leading to a broader sweep of every real
`Tables::config()` reference): `ConfigurationSubController`'s generic
config-row save loop, `ExtendForTemplatesPageRenderer`'s
`extents_for_templates` write, and `check_integrity::update_conf()` were
all writing the `config` table via string-concatenated SQL with manual
(or, in `check_integrity`'s case, zero) escaping — the same vulnerability
class this project already fixed once via `MysqliDb`'s deletion (Phase
1k). All three now go through parameterized `ConfigService` writes.

**Known limitation, not fixed by this phase**: `ConfigRepository::upsert()`
(`ConfigService`'s write mechanism) does `find()` then branches
insert-vs-update, then `flush()` — not an atomic upsert, unlike
`ConfigDb::confUpdateParam()`'s raw `INSERT ... ON DUPLICATE KEY UPDATE`.
Two concurrent requests both writing the same brand-new key for the first
time could race. Not worth blocking this phase over — every real write
key retargeted here is a long-lived, already-existing config row, not a
first-ever-creation key, and the failure mode if it ever hit would be a
thrown Doctrine/DBAL exception, not silent data loss. A real atomic-upsert
fix is a reasonable future improvement to `ConfigService` itself.

## Phase 6 — TODO/FIXME/XXX triage. DONE.

Triaged all 28 real TODO/FIXME/XXX markers (27 originally scoped across
`src/Piwigo/` + 1 found outside that scoping, in `include/`, during
planning — the same "search the whole repo, not just curated subfolders"
gap Phase 4's own retrospective already flagged). Every marker got a
concrete verdict reached through individual investigation (tracing real
call sites, cross-referencing the `16.x-rewrite` reference branch,
`tools/triggers_list.php`, and `composer.json`'s required extensions),
not a pattern-matched guess — an adversarial validation pass after the
initial triage caught and corrected several conclusions before any code
changed (see below). Commits `5d59879c2` (6a, real fixes) and `69d5b966f`
(6b, cleanup) — full write-up in project memory
(`project_p24_phase6_complete.md`); summary here:

- **6a (4 real bugs fixed, 5 files)** — `Bootstrap/RequestBootstrap.php`:
  removed a one-time 2022 `order_by` data migration whose own comment
  asked for removal once 2025 arrived (self-healed on every request
  since). `Ws/PwgCore.php`: `pwg.getInfos`'s `cache_size` field returned
  a hardcoded `4242` — replaced with `null` (matching the real
  `pwg.getCacheSize` method's own "couldn't determine" sentinel), not the
  real computation, which shells out via `exec('du -sk ...')` and would
  have been a real performance/security-surface regression on a
  general-purpose endpoint. `Admin/updates.php` +
  `Admin/Extensions/CoreUpdateService.php` (the latter had no TODO
  marker, found while investigating the former): both redirected to a
  never-real `?page=plugin-<dirname>` URL, a leftover from when the
  update system was itself shipped as a plugin — live-verified the old
  target actually threw a real HTTP 500; both now redirect to the real
  Updates page, confirmed reachable via `UpdatesPwgPageRenderer`'s real
  form submission. `Admin/Upload/UploadService.php`: `needResize()`'s
  max-dimension check didn't account for EXIF orientation, so a portrait
  photo stored with landscape raw pixels could be compared on the wrong
  axis — and this check gates a permanent resize of the stored original,
  not just a derivative. Fixed by reusing the existing
  `pwg_image::get_rotation_angle()` detection already used elsewhere in
  the same file.
- **6b (19 locations cleaned up, 16 files)** — 5 stale TODOs deleted
  (confirmed already-resolved: a trigger dispatch that already exists one
  layer up, a language string the code already matches, references to
  functions that never existed in this codebase, a privacy concern its
  own next sentence already answered, a hardcoded value with no pending
  alternate branch). 13 items (14 locations) converted into accurate,
  individually-verified explanatory comments — including three
  deliberate "real gap, not implemented" scope calls matching this
  phase's own discipline (a per-user album-position preference, a
  config-file-only setting missing its admin UI, a missing
  descendant-representative fallback) and a legacy `array_push()`
  pattern's real (traced, not assumed) behavior.

**Adversarial validation caught real errors before they shipped**: a
reclassification (`Search/SearchFilterRenderer.php`'s admin-page link
"bug" turned out to be functionally inert — a `strip_tags()` call one
line later in its own caller strips whatever URL was passed, a fact the
initial read never traced far enough to notice), a wrong proposed fix
(the obvious "just call the real cache-size method" fix for
`Ws/PwgCore.php` would have introduced a real `exec()`-shell-out
performance regression), and an overstated safety claim (a legacy
`array_push()` pattern first declared "harmless no-op" was, on closer
inspection with concrete example values, only harmless in every
realistic case — not provably safe in a pathological one). Same
discipline this project's Phase 5 adversarial-validation rounds already
established: verify every claim against the actual code, not against how
plausible it sounds.

## Gap-closure: DbPatch/VersionUpgrade `global` retirement ("fix all"). DONE.

The 151 frozen `DbPatch`/`VersionUpgrade` migration files (Phase 1j) and
`VersionUpgradeInterface`'s own docblock had framed keeping `global
$conf, $prefixeTable, $page, $template, $persistent_cache, $last_time;`
as a deliberate "verbatim port" trade-off, never in scope for any later
phase. Direct re-investigation (triggered by a user challenge to the
"100+ globals" count) found this framing was only half right: an
anchored, comment-aware count showed exactly 36 real remaining `global`
statements (not the 144-256 an unanchored grep suggests) — 34 of them
`$conf`/`$prefixeTable` in the migration files, plus `InstallWizard.php`'s
constructor and `RequestBootstrap::configure()`'s `$t2`. Of those 34, one
(`UpgradeFrom_1_4_0.php`'s dead `$last_time`) was a genuine, previously
undelivered fix from 8d's own original design that never got executed
when 8d's scope narrowed to `ConfigDb::` retirement only — not a
deliberate keep. Instructed to fix all 36 regardless of which category
each fell into.

Two small shared helpers, both `Piwigo\Admin\Install\DbPatch\` (matching
the already-established `UpgradeCharset`/`DatabaseConfigChanges`
precedent of small state/utility classes for this exact file family, and
already imported cross-namespace by `VersionUpgrade` classes the same way
`DbPatchRegistry`/`DatabaseConfigChanges` already were):

- **`LegacyFileConf::read()`** — the raw, file-sourced `$conf` for keys
  Config:: can't be trusted for mid-migration: `Config::$data` during a
  real `DbPatch`/`VersionUpgrade` `apply()` run holds only SCHEMA
  defaults + env overrides (`UpgradeRunner`'s own entry-shell seeding),
  never a site's `local/config/config.inc.php` customization nor the
  DB-persisted config (that loads later, in `finish()`). Same 3-step
  layering as the already-reviewed live precedent,
  `UserListPageRenderer::webmasterIdIsLocal()`/
  `ConfigurationSubController::orderByIsLocal()`: `config_default.inc.php`,
  then `local/config/config.inc.php`, then — only if that revealed a
  `local_dir_site` override — the site's real dir-site config file too.
  Caught and corrected a real mistake mid-pass: an initial swap of
  `$conf['guest_id']`/`['webmaster_id']` for bare `Config::guestId()`/
  `webmasterId()` calls would have silently used the schema default
  instead of a site's real file override — `Patch171.php`'s own comment
  ("If the webmaster_id has been modified, it must be present in
  local/config/config.inc.php") proved these keys are genuinely
  site-customizable, not just schema defaults.
- **`LegacyDbLayer::value()`** — the raw `dblayer` string (`'mysql'`/
  `'pgsql'`/`'sqlite'`/`'pdo-sqlite'`), read the same way `upgrade.php`/
  `upgrade_feed.php`'s own entry-shell `$dblayer = $conf['dblayer'];`
  read does. No `Config::` equivalent exists at all: `dblayer` isn't in
  `config_default.inc.php` (confirmed via grep, only ever set in
  `database.inc.php`), and the nearest modern accessor,
  `Config::dbDriver()`, uses an incompatible value space (`'mysqli'`/
  `'pgsql'`) that would silently break the `== 'mysql'`-style checks 9
  frozen patches still make.

Every `$prefixeTable` site got `Tables::tableName()` (when a live
accessor exists and there's no "might be an external table" risk — the
`Patch132.php`/`Patch143.php` exception for `users` is preserved verbatim,
per their own original comments) or `Config::dbPrefix() . 'literal'`
(defunct tables, bulk `str_replace()`, dynamic table-name variables).
`InstallWizard.php`'s constructor and `RequestBootstrap::configure()`'s
`$t2` turned out fixable too, once looked at directly instead of
re-accepting the earlier Phase 8 "genuinely load-bearing" framing:
`InstallWizard` now calls `LegacyFileConf::read()` the same as the
migration files (same `data_location` concern, same fix), and
`$t2` — captured at `include/common.inc.php`'s true top-level scope,
before `RequestBootstrap` is even autoloadable — is now an explicit
second parameter to `configure()` instead of a `global` bridge, since
the one real call site already has it in scope.

Surfaced one real, independent code-quality fix along the way: making
`dblayer` a genuine typed `string` (instead of `mixed` off a raw global)
let PHPStan see 9 `== 'mysql'` comparisons as real string-vs-string loose
comparisons for the first time — converted to `===`, per this project's
own standing strict-comparison policy.

Closed out with a new Arch test (`tests/Arch/StructuralTest.php`)
asserting zero remaining `global $conf`/`$prefixeTable`/`$last_time`/`$t2`
in `src/Piwigo/` — zero-tolerance, no allowlist, matching Phase 2's own
`$filter`/`$pwg_loaded_plugins`/`$template`/`$page` test.

Full verification gate green: ECS clean, PHPStan baseline regenerated
(1031 → 1011 entries — real reduction from the fixes, plus 5 new entries
for `LegacyFileConf.php`/`LegacyDbLayer.php`'s own genuinely-unresolvable
`include.fileNotFound` cascade, same accepted class of finding
`UserListPageRenderer.php`/`ConfigurationSubController.php` already
carry), deptrac 0 violations, Unit/Arch 602, Contract 93, Integration
632, Browser 66+1 skipped (including `UpgradePathTest`'s real
`upgrade.php`/`upgrade_feed.php` connection smoke test).

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
