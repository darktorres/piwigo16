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
| 5 text→JSON | **IN PROGRESS — full plan below.** `user_cache.forbidden_categories`/`image_access_list` turned out not to be serialize-leak candidates at all (comma-separated ID lists spliced into raw SQL, not `serialize()` blobs) — carved out as a separate future finding. `config.value`, `search.rules`, `user_infos.preferences` (plus `activity.details`, a newly-found 4th column not in the original 43-item list) get a real fix: see "1a-bis" below. |
| 1 new column (`history_summary.summary_id` AUTO_INCREMENT PK) | **NOT DONE** — table still has no primary key at all, only the `UNIQUE KEY (year,month,day,hour)`. |
| 3 unsigned fixes | **NOT DONE** — `sites.id` is still `tinyint(4) NOT NULL auto_increment` without `unsigned`, the one confirmed candidate. |
| Serialized-blob normalization (separate from the 43) | **NOT DONE** — `extents_for_templates` (`ExtendForTemplatesPageRenderer.php`) and `updates_ignored` (`ExtensionUpdateChecker.php:129`) are both still `serialize()`d config values; the `extension_ignored_updates` table exists but nothing writes to it yet. |

**34 of the ~40 concretely-named items are done.** Remaining: the 5 text→JSON columns +
their consumers, 1 new PK column, `sites.id unsigned`, and the 2 serialized-blob cutovers.

**Stage 1b — Typed DTO/Projection pattern: 10 of 32 repositories done.** (33 total minus
`ConfigRepository`, already excluded per the plan — confirmed still the sole
Doctrine-ORM-backed repository.) Verified by checking which repositories actually reference
their own `Projection\*` class and call `::fromRow(`, not just which domain directory
happens to contain a `Projection/` folder (e.g. `Auth/Projection/ApiKey.php` exists, but
`AuthRepository.php`/`PasswordRepository.php` in the same domain don't use it yet).

- **Done:** `ApiKeyRepository`, `CategoryRepository`, `ImageRepository`,
  `PermalinkRepository`, `PluginRepository`, `RateRepository`, `SearchRepository`,
  `SiteRepository`, `TagRepository`, `UserRepository`.
- **Not done (22):** `ActivityRepository`, `Admin/Extensions/ExtensionRepository`,
  `Admin/Maintenance/DbMaintenanceRepository`, `AuditRepository`, `AuthRepository`,
  `PasswordRepository`, `Cache/UserCacheRepository`, `CaddieRepository`,
  `CalendarRepository`, `CommentRepository`, `FeedRepository`, `GroupRepository`,
  `HistoryRepository`, `LangRepository`, `MailRecipientRepository`,
  `MenubarLayoutRepository`, `MetadataRepository`, `NotificationByMailRepository`,
  `NotificationRepository`, `PermissionRepository`, `SectionRepository`,
  `SessionRepository`.
- Execution departed from the plan's suggested order (Image → Category → User → Tag →
  Comment → Activity → Rate → remaining 26) — Rate/Search/Site/PluginConfig/Permalink/Auth
  landed before Comment/Activity. Fine per the plan's own "detail worked out at
  implementation time" allowance; **Comment and Activity are next in the original
  sequence if resuming in-order.**
- `BatchManagerUnitPageRenderer.php`'s stale-`$row` bug (lines ~364–374, called out as
  "fix along the way") is **still open** — its own inline comment still says "pre-existing
  bug, not fixed here."

**Stage 1c — Per-namespace Unit test coverage: NOT STARTED, 0/11.** `Audit`, `Caddie`,
`Calendar`, `Comment`, `Group`, `History`, `Metadata`, `Permission`, `Picture`, `Site`,
`Tag` all still have zero files under `tests/Unit/`. Notably, `Site` and `Tag` already
had their Stage 1b pass land (Projection classes exist) without the companion Unit tests
the plan says should ship in the same pass — a real, verified deviation from the stated
intent, not yet caught up. `Picture` (the flagged priority — `PictureCommentRenderer`'s
documented prior bug) still has zero tests in any suite; only `tests/Arch/StructuralTest.php`
references its renderer class names, and only for a structural check, not behavior.

**Stage 1d — CachePools wiring: 1 of 3 done.** `CachePools::config()` is now real (wired
into `ConfigService::loadConfFromDb()` this session, `feede75c9`). `CachePools::permissions()`
and `CachePools::tagCloud()` still have zero callers anywhere in `src/Piwigo`.

**Stage 1e — die() elimination: NOT STARTED, 0/20.** Exact same counts as the plan's own
re-derived figures: `UploadService.php` (9), `Admin/Image/ImageGd.php` (5),
`Admin/Image/PwgImage.php` (2), `Admin/Image/ImageExtImagick.php` (1),
`UpgradeFrom_1_3_1.php` (3). No `ImageProcessingException` class exists yet.

**Stage 1f — reset() arch-test coverage: NOT STARTED, still 6/~29.** `StructuralTest.php`
has exactly the same 6 `"X::reset() is only called from tests/"` tests the plan's baseline
names (`Kernel`, `ShutdownHandler`, `CurrentConfig`, `SessionService`, `StorageRegistry`,
`CurrentConfigService`). None of the other ~23 classes have gained one.

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

**3. `user_infos.preferences`**

- Schema: `piwigo_user_infos.preferences` TEXT → JSON.
- `UserInfo` Projection: `preferences` becomes `?array`, decoded via `json_decode(...,
  true)`; remove the "deliberately deferred" docblock note.
- `UserRepository::savePreferences()`: retype to accept `array`, encode via
  `json_encode()` internally.
- `PreferencesService::save()`: drop the manual `serialize()` call.
- `UserService::getUserData()` (~line 686): drop the manual `unserialize()` call and its
  "mixed-type-widening" workaround comment; retarget onto the Projection's decoded value.

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
  claims), `(p8)` for Stage 2, and whatever scope tag fits the legacy-import adoption item in
  Stage 3. Update `docs/plan/manifest.yaml` and `docs/PLAN-REPLAY-AUDIT.md` as each stage's
  gaps actually close, not preemptively.
