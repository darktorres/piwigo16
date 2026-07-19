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
  revisiting once 1d is underway. Commit TBD.
- **Next up: Phase 1d** (Bootstrap, 9 files/51 call sites — the
  widest-blast-radius single sub-phase, see its own note above) — not yet
  started.

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
4. **1d — Bootstrap (9 files, 51 call sites — confirmed genuine manual
   construction by reading `RequestBootstrap.php`/`UserBootstrap.php`
   directly, not bootstrap-time code that's fine as-is).** This is the
   request-construction root that runs on every single request — do this
   only after 1a-1c prove the pattern on lower-traffic-risk domains
   first, since a mistake here has the widest blast radius of any single
   file in the plan (not because other domains structurally depend on
   Bootstrap's own construction — they don't; `CategoryRepository` etc.
   are independently constructed and don't reach into Bootstrap).
5. **1e — Core (46 files, mostly infrastructure).** Low construction
   density (`newchains=1`) but still has 6 `MysqliDb::` call sites.
   **Correction from an earlier draft**: the 15 static-singleton classes
   are NOT concentrated here — only 4 of them (`PageState`,
   `CurrentLogger`, `Lang`, `FilesystemHelper`) live in `Core/`; the rest
   are scattered across 10 other domains (Users, Template, PluginConfig,
   Session, Db, Auth, Storage, Section, Url, Cache). Decide the
   static-singleton conversion-vs-exception **criteria** during 1a's
   pilot (using `Core/PageState` or `Lang` as the first worked example
   since they're touched early anyway), then apply that same criteria
   consistently in whichever sub-phase later encounters each of the
   other 11 classes — don't defer all 15 to this one sub-phase.
6. **1f — Ws (24 files, 308 call sites, 9 MysqliDb files).** Each WS
   method independently assembles its own service graph — expect heavy
   de-duplication once real constructors land. Needs its own multi-batch
   breakdown once started (by WS resource area: images/categories/users/
   tags/comments/groups, matching the already-successful P23 batch 8e
   grouping).
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
