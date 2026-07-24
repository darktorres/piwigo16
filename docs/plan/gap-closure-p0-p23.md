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
| 5 text→JSON | **NOT DONE, 0/5** — `config.value`, `search.rules`, `user_cache.forbidden_categories`, `user_cache.image_access_list`, `user_infos.preferences` are all still plain `text`/`mediumtext`. |
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
