# Plan and build history

Phase-by-phase record of `17.x-rewrite`'s development: what was planned,
what actually shipped, and where the two diverge. Consolidated 2026-07-26/27
from `PLAN.md`, `PLAN.md`, `docs/plan/gap-closure-p0-p23.md`,
`docs/plan/legacy-coupling-retirement.md`, `docs/plan/code-quality-review-2026-07-25.md`,
and `docs/plan/dbal-orm-remaining-audit-2026-07-26.md` — one current status
per phase, not several documents to cross-reference by hand. `docs/plan/manifest.yaml`
(the old machine-readable status tracker) is deleted along with this merge;
this file's status table replaces its role, independently re-derived from
`git log`/live code rather than copied from the manifest, which had drifted
badly (P24 marked `planned` despite 271 landed commits).

`17.x-rewrite` replays `16.x-rewrite`'s modernization in 33 strictly-sequential
backbone phases (P0–P32, grouped into 9 epochs A–I), rebuilt from
`origin/16.x` rather than upgraded in place. Dual-purpose: a *replay* of
work with a reference implementation on `16.x-rewrite`, plus *greenfield*
net-new capabilities with no counterpart there. Full conventions (REPLAY
vs. GREENFIELD tagging, T1/T2/T3 tiers, the "every landed commit green,
baselines ratchet" working rule) carry forward unchanged from the original
plan — see "Conventions" below.

## Real status vs. commit-tag labels — read this before the table

Commit-message phase tags (`feat(p24): ...`) and the *original* phase
definitions below diverged starting around P24. This matters for reading
the table correctly:

- **P24 as originally defined** ("Vite + TypeScript conversion") — not
  started. What actually landed under 271 `(p24)`-tagged commits is a much
  larger, separately-motivated effort: retiring the `$GLOBALS`/static-bridge
  coupling, migrating ~27 domain repositories from DBAL to real Doctrine
  ORM, retargeting the event-dispatch and `l10n()`/URL free-function
  bridges onto real classes, and closing gaps a 2026-07-13 audit found in
  P0–P23's own claims. This was tracked in its own planning docs
  (`legacy-coupling-retirement.md`, `gap-closure-p0-p23.md`) under the
  `p24` tag as a matter of sequencing convenience ("whatever comes after
  P23"), not because it's the P24 this plan originally scoped. See the P24
  section below for the real work; see "Not started" in the status table
  for the Vite/TypeScript conversion itself.
- **P25 as originally defined** ("Inline JS extraction + `any` reduction")
  — not started. The 52 `(p25)`-tagged commits are PHP `mixed`-type
  elimination (Phase 1–2, by domain module), continued directly into P27's
  tag. Unrelated to the original P25 scope.
- **P27 as originally defined** ("Type correctness + mixed elimination") —
  this one actually matches: the 89 `(p27)`-tagged commits continue the
  same mixed-elimination effort (Phase 4: replacing ambient `$_POST`/`$_GET`
  superglobal reads with typed Request DTOs) plus real security fixes
  found along the way (SQL injection, request-array scope). Counts as
  real, substantial progress against its own original definition.
- **P32** — 1 commit landed (`chore(p32): delete doc/`), not the phase's
  full "layer decoupling + repository restructure" scope. A narrow,
  unrelated cleanup borrowed the tag.

Where the table below says "diverged," this is what it means: re-verify
against the phase's own section, don't assume the commit count maps
cleanly onto the original scope.

## Status (re-derived from git log + live code, 2026-07-27)

| Phase | Original scope | Status | Real commits |
| --- | --- | --- | --- |
| P0 | PHP tooling + baselines | Done | 8 |
| P1 | Frontend tooling + baselines | Done | 6 |
| P2 | Test harness | Done | 7 |
| P3 | CI pipeline | Done | 16 |
| P4 | Containerization + runtime image | Done | 4 |
| P5 | Composer + Rector + PHPStan | Done | 653 |
| P6 | PSR-4 namespace migration | Done | 34 |
| P7 | Kernel + boot skeleton | Done | 4 |
| P8 | DI container | Done | 4 |
| P9 | PSR-15 middleware + routing | Done | 6 |
| P10 | Observability | Done | 4 |
| P11 | Cache + session + messenger + opcache.preload | Done | 4 |
| P12 | CLI tool + backup/restore | Done | 7 |
| P13 | Config service | Done | 4 |
| P14 | DB layer + Doctrine ORM | Done, mechanism changed (Doctrine Migrations reversed; static SQL schema instead) | 4 |
| P15 | Schema migration + multi-provider | Done | 6 |
| P16 | Typed facades + constants + language | Done | 7 |
| P17 | Domain tier 1 | Done | 14 |
| P18 | Domain tier 2 | Done | 4 |
| P19 | Domain tier 3 | Done, 1 gap found this round (`Common` namespace never built, see below) | 12 |
| P20 | Domain tier 4 | Done | 10 |
| P21 | Admin controller migration | Done | 4 |
| P22 | Frontend controller migration | Done | 7 |
| P23 | Legacy deletion & cleanup | Done, 2 gaps found in later audit (see below) | 123 |
| — | Remediation (informally tagged `p24`): globals/event/l10n coupling retirement, DBAL→ORM | Done, 2 gaps found (see below) | 271 |
| P24 | *Originally:* Vite + TypeScript conversion | **Not started** | 0 |
| P25 | *Originally:* Inline JS extraction + `any` reduction | **Not started** (mixed-elimination work landed instead, see above) | 0 |
| P26 | REST resource layer + OpenAPI (WS API removed) | Not started | 0 |
| P27 | Type correctness + mixed elimination | Real progress (Request DTO migration, Phase 4) | 89 |
| P28 | Security hardening | Not started | 0 |
| P29 | Template migration (Smarty → Latte) + asset pipeline | Not started | 0 |
| P30 | CSS modernization + Tailwind | Not started | 0 |
| P31 | Plugin / Theme contracts + bundled extensions | Not started | 0 |
| P32 | Layer decoupling + repository restructure | Not started (1 unrelated commit borrowed the tag — `doc/` cleanup) | 1 |

Two adjacent, non-phase-numbered tracks, both confirmed **not started**
directly against the codebase:

- **FrankenPHP worker mode** (SEC-60, a P7 gap found in the 2026-07-13
  audit) — `docker/Caddyfile` still plain `php_server`, no `worker` block.
- **Legacy import tool** (`bin/piwigo import:legacy`, `ADR-0002`) — no
  `import:legacy`/`ImportLegacy` reference anywhere. Cited elsewhere as
  "ADR-0025," which — like ADR-0007/0008 for the image-derivative fast
  path — was never actually written; see `docs/REFERENCE.md`'s ADR
  section for the full list of phantom ADR citations found during this
  consolidation.

## Conventions (carried forward unchanged)

- **Kind**: REPLAY (a reference implementation exists on `16.x-rewrite`,
  reproduce it) vs. GREENFIELD (net-new, needs its own design step first).
- **Tier**: T1 Core-parity (required to match `16.x-rewrite` behavior), T2
  Modernization (clear-ROI infra/quality), T3 Stretch (cuttable without
  blocking a release).
- **Working rule**: no change lands unless all CI gates pass on a clean
  checkout — CI, not a local worktree, is the source of truth for "green."
  Tool baselines ratchet (issue counts only go down). A later "resolve N
  failures" commit is a smell, not a milestone.
- **Additive-only foundation**: P0–P1 install tooling and record baselines
  without modifying first-party code; the first code-modifying pass is
  gated on the P2 regression harness being green against pristine
  `origin/16.x`.
- **Reference branch**: `16.x-rewrite` (`../piwigo16-rewrite`) is a
  read-only design target — reproduce behavior, never `git checkout`/
  cherry-pick from it.

## Phase detail

Detail for each phase/epoch follows below, each section re-verified
against live code during this consolidation (not copied from the source
docs' own prose) — see each section's own notes for what changed. Kept
condensed: current tool/system state lives in `docs/REFERENCE.md`, not
duplicated here — this section records what each phase delivered and any
real corrections found along the way, not a re-derivation of present-day
config.

### Epoch A — Foundation (P0–P4)

**P0 — PHP tooling + baselines.** Installed Pest 4 + plugins, pcov, ECS,
PHPStan, Psalm, Rector, Deptrac (config deferred to P6), ComposerRequireChecker/
Unused, PHPBench, roave/security-advisories — additive only, no first-party
code modified. Baselines recorded, not yet gated (ECS/Rector became
code-modifying passes later; Psalm gating was later paused entirely — see
P5).

**P1 — Frontend tooling + baselines.** bun/Vite/TS/ESLint/Stylelint/Vitest,
knip, size-limit, commitlint, Lighthouse CI, `web-vitals` installed.
**Real gap found and fixed** (2026-07-13 audit): `web-vitals` was installed
but never wired to a real endpoint — no beacon, no `/analytics/vitals`
route. Remediated same audit cycle: `build/vitals.ts` + `VitalsController`
+ route, log-only (no dashboard yet) — see `docs/REFERENCE.md`'s
Development section for current state.

**P2 — Test harness.** Env split (`.env.test`, `X-Piwigo-Env: test`
header), fixture DB (`tests/Fixtures/piwigo-17.0.sql`), Pest Browser E2E +
WS Contract suites established. Foundation for every later phase's own
test coverage.

**P3 — CI pipeline.** `ci.yml` job layout, matrix, caching; actionlint,
commitlint, SBOM/OSV jobs, OpenSSF Scorecard.

**P4 — Containerization + runtime image.** Multi-stage Dockerfile
(FrankenPHP + Apache-fallback targets), Compose, Helm chart, `/health`+
`/ready`, restore drills, SEC-01 web-root deny rules across all 3 server
targets. Fully current — see `docs/REFERENCE.md`'s Deployment section for
the live details (image signing, hardening, web root isolation).

### Epoch B — Composer/Rector/PHPStan + PSR-4 (P5–P6)

**P5 — Composer + Rector + PHPStan (PHP modernization).** By far the
largest single phase by commit count (653) — whole-codebase ECS `--fix`,
PHPStan bleeding-edge rules applied file-by-file across the legacy tree,
vendored third-party library replacement (`ADR-0021`: PHPMailer → Symfony
Mailer, Emogrifier → `pelago/emogrifier`, phpqrcode → `endroid/qr-code`,
vendored Smarty → `smarty/smarty`, phpass → native `password_hash()`,
`mdetect.php` dropped with no replacement). **Real correction landed
here, not a doc fix**: Psalm's global-function-resolution scanner broke
down against the still-non-namespaced legacy codebase at this scale —
investigated properly (ruled out cache staleness, parallel-worker races),
concluded it's a real tool limitation at this codebase's shape, not a bug
in the code. Psalm gating paused (`ADR-0026`) — PHPStan remains the sole
blocking static-analysis gate. *(See `docs/REFERENCE.md`'s CI section:
`ADR-0026`'s own resume condition — P6 + P17–P23 done — has since been
met, but gating hasn't been reconsidered.)*

**P6 — PSR-4 namespace migration.** Extracted every first-party `class`/
`interface` declaration living inside `include/`/`admin/include/`
procedural files into `src/Piwigo/`, `Piwigo\` namespace prefix — 66
classes/interfaces across 33 origin files. Extraction and namespacing
only, not a rewrite: no renaming to modern casing, no DI, no behavior
changes beyond what the move itself forced. Established the 6-layer
Deptrac model (L0Data → L4Integration, L2a/L2b domain split) — enumerated
per-namespace, not a catch-all regex, so a later phase adding a namespace
has to deliberately choose its layer. **Real bug found via the deptrac
layer-name check**: Deptrac 4.6.2 silently breaks ruleset resolution when
a layer name contains a hyphen — the original `L0-Data`/`L1-Infrastructure`-style
names made every legal cross-layer dependency misreport as a violation.
Fixed by dropping hyphens from every layer name; confirmed 0 real
violations, there was never an actual architecture problem to baseline.
See `docs/REFERENCE.md`'s Architecture section for the full, current
50-namespace layer table (P6 seeded ~20 of them; every phase since P7 has
added more).

### Epoch C — Kernel & HTTP foundation (P7–P12)

**P7 — Kernel + boot skeleton.** `Kernel`, `CommonBootstrap`,
`public/index.php`, fast-paths. **Real gap found** (2026-07-13 audit,
SEC-60): the FrankenPHP worker loop was never implemented — classic
per-request execution on the FrankenPHP binary, not true worker mode; only
5 of 13 request-scoped static/singleton classes had a `reset()`-is-test-only
arch test. **Still open** — see the status table above and
`docs/REFERENCE.md`'s "What's genuinely not built yet." Deliberately
deferred past P23 (the bootstrap-chain replacement work changes what state
needs resetting, so doing the audit twice would be wasted effort) — but
P23 is long done and this hasn't been picked back up.

**P8 — DI container.** `Container`, `config/container.php`, PHP-DI
autowire-by-default. No gaps found.

**P9 — PSR-15 middleware + routing.** 7-stage middleware pipeline (re-verified
this round directly against `RequestPipeline.php`'s own registration list —
an earlier pass of this doc said 8). Traced where "8" came from:
`PLAN-REPLAY.md`'s own "Codebase baselines" table records `16.x-rewrite`
(the reference branch) at 8 middleware stages — a real replay target, not
a typo, unlike the "62 admin pages" figure corrected above (which turned
out to be a legacy origin/16.x file count, not a target at all). No
record explains which stage `17.x-rewrite` is missing relative to the
reference's 8, or whether it was a deliberate simplification — flagging
the gap precisely rather than guessing. Routes, extensible
`SecurityHeadersMiddleware`, cross-server SEC-01 deny rules.
**Real gap found** (2026-07-13 audit, SEC-11/SEC-12): `CsrfService` still
used `hash_hmac('md5', ...)` + `===` despite the identical weak-hash
pattern being correctly fixed in the sibling `AuthService`/
`EphemeralKeyService` during P18 — a later P17/P18 fix (`a64fccbb6`) had
touched the same file for an unrelated bug (DB-persisted secret key) and
never caught it. **Fixed** in the pre-P23 remediation pass — `CsrfService`
now uses `hash_hmac('sha256', ...)` + `hash_equals()`, confirmed live in
`src/Piwigo/Csrf/CsrfService.php`.

**P10 — Observability.** Monolog channels, Server-Timing,
OpenTelemetry-first (OTLP → Sentry/Tempo/Jaeger). Greenfield, no reference
implementation. No gaps found.

**P11 — Cache + session + messenger + `opcache.preload`.** `symfony/cache`
pools, session handler, Messenger, preload list. **Real gap found**
(2026-07-13 audit): the "named cache pools" design (`config`/`permissions`/
`category_tree`/`tag_cloud`/`rate_limiter`/`general`, each its own
TTL) was never built — `CacheFactory` produced one generic pool, zero real
consumers besides `CacheClearCommand`. Load-bearing for P23's own
cache-table-rationalization gate. **Fixed** in the pre-P23 remediation
pass — `CachePools` built on top of `CacheFactory`; `rate_limiter`
specifically stays unbuilt (genuinely P28 scope, no consumer exists yet).
Messenger itself is now real and wired (`config/messenger.php`, 5
`Piwigo\Job\*` classes + handlers) — see `docs/REFERENCE.md`.

**P12 — CLI tool + backup/restore + graceful shutdown.** `bin/piwigo`,
`BackupService`, `ShutdownHandler`/SIGTERM cleanup, PHPBench. **Real gap
found** (2026-07-13 audit): all 4 `maintenance:*` commands
(`orphan-tags`/`purge-history`/`purge-sessions`/`repair-db`) were planned
but never built; `config/commands.php`'s own comment pointed at a
"P12 scope-decision section" that didn't exist. **Partially fixed**: 3 of
4 landed in the pre-P23 remediation pass
(`MaintenanceOrphanTagsCommand`/`MaintenancePurgeHistoryCommand`/
`MaintenancePurgeSessionsCommand`, all real, tested). `maintenance:repair-db`
**still doesn't exist** — deferred at the time because its backing logic
lived in a legacy file P23's own absorption work still had to touch;
that file is long gone and the logic now lives in a real typed method
(`DbMaintenanceRepository::repairOptimizeAllTables()`, called only from
the admin web UI), but nobody circled back to build the CLI wrapper once
the blocker cleared. Small, well-understood fix — copy
`MaintenancePurgeHistoryCommand`'s exact shape. Still open.

### Epoch D — Config/DB/language (P13–P16)

**P13 — Config service.** 277-entry `SCHEMA`, `ConfigLoader`, typed
accessors. **Real gap found** (2026-07-13 audit): the `$conf` → `Config`
migration had stalled — 72 `src/Piwigo/` files still read `global $conf`
directly, not from incomplete migration but because `Config::` accessors
were provably unsynced with DB-persisted values (`ConfigService::
loadConfFromDb()` wrote into the legacy `$conf` global but never into
`Config::$data`) — the confirmed root cause of a real shipped bug
(`CsrfService` reading an empty `secret_key`). **Fixed** in the pre-P23
remediation pass (at the time, `ConfigDb`'s write paths were made to call
`Config::override()`/`Config::delete()` alongside the legacy `$conf`
write) and finished by Track A of the legacy-coupling-retirement work
below — confirmed zero `global $conf` reads anywhere in `src/Piwigo/`
today. **Class/method names since renamed, re-verified current**:
`ConfigDb` was later merged into `Piwigo\Config\ConfigService` directly
(no standalone `ConfigDb` class exists today), and `Config::override()`/
`::delete()` no longer exist at all — the typed-`CurrentConfig` refactor
(see below) replaced them with reflection-based named-setter calls
(`ConfigService::confUpdateParam()` invokes `CurrentConfig::set{Property}()`
via `ReflectionMethod`). The underlying fix (DB writes reaching the live
config object, not just the legacy global) still holds under the new
names — confirmed by reading `ConfigService::confUpdateParam()` directly
— this is a naming update, not a functional regression like the
`local/config/config.inc.php` one below.

**P14 — DB layer + Doctrine ORM.** **Two real corrections**, both since
resolved:
- (2026-07-13) The "repositories as real Doctrine ORM `EntityRepository`
  subclasses from day one" design was followed only for `ConfigRepository`
  itself — all ~27 domain repositories built in P17–21 used
  `AbstractRepository`+`Tables::` (DBAL) instead, which had become the
  real, working, tested pattern for query-heavy repositories, not a
  legacy-only shim. **User decision**: migrate all ~27 for real rather
  than correct the doc — tracked as its own remediation initiative,
  sequenced after P23. **Done, re-verified this round by reading every
  repository's own `extends` clause directly — corrected twice over now,
  the denominator was wrong before too**: `find src/Piwigo -iname
  '*Repository.php'` gives 32 files, one of which
  (`Db/AbstractRepository.php`) is the shared base class itself — **31
  real domain repositories**, not the 25 an earlier pass of this doc
  counted (a narrower `grep` pattern silently missed several — the same
  class of undercount this project's own memory already warns about for
  `new ClassName(` scans). Of the 31: **16** extend
  `Doctrine\ORM\EntityRepository` (not Symfony's `ServiceEntityRepository`
  — that class isn't used anywhere in this codebase, which doesn't run on
  the Symfony framework/DoctrineBundle; a still-earlier pass of this doc
  misnamed it) — `Activity`, `Audit`, `Category`, `Comment`, `Config`,
  `Feed`, `Group`, `History`, `Image`, `Lang`, `PluginConfig\Plugin`,
  `Rate`, `Session`, `Site`, `Tag`, `User`. **8** deliberately stay on
  `AbstractRepository`/DBAL, each with its own documented reason in its
  class docblock: `Password`, `Calendar`, `Search`, `Section`, `Caddie`,
  `Notification`, `NotificationByMail`, and `MailRecipient` all stay on
  generic parameterized DBAL executors because their query shapes are
  assembled dynamically per-caller rather than fixed enough for entity
  mapping to help (see e.g. `Search\SearchRepository`'s own docblock:
  "deliberately NOT QueryBuilder-per-query"). **7** — not "the single
  outlier" as an earlier pass of this doc said — extend neither base
  class at all, holding `EntityManagerInterface` directly via constructor
  injection instead: `Permission\PermissionRepository` (`user_access` is a
  shared join table with no single owning repository),
  `Auth\AuthRepository`, `Auth\ApiKeyRepository`,
  `Metadata\MetadataRepository`, `Permalink\PermalinkRepository`,
  `Admin\Maintenance\DbMaintenanceRepository`, and
  `Admin\Extensions\ExtensionRepository` — each touches tables *other*
  repositories own (`Users\UserInfoEntity`/`Auth\UserAuthKeyEntity`/images/
  categories), reaching them via DQL for simple writes or plain DBAL for
  reads/dynamic fragments, never claiming ownership of a table itself.
- (2026-07-24) The Doctrine Migrations decision itself was reversed before
  any real install existed — real installs create the schema from a
  static, hand-maintained `install/piwigo_structure-mysql.sql` instead;
  `doctrine/migrations` is no longer a dependency. `InstallWizard`'s own
  flow was already hardcoded to MySQL only, so the multi-provider
  migration path this enabled never backed a real installable option.

**P15 — Schema migration + multi-provider.** InnoDB+utf8mb4 uniformly, 7
new tables, FK constraints, `audit_log` (SEC-57). Cache tables
(`user_cache`, `user_cache_categories`, `history_summary`) originally got
engine/charset only, type-norm skipped — `user_cache`/`user_cache_categories`
were later dropped entirely (P23 gap-closure Stage 4, once every real
consumer moved onto TTL cache pools instead); `history_summary` was kept
and got its own type fix (`summary_id` AUTO_INCREMENT PK) separately.

**P16 — Typed facades + constants retirement + language.** `Paths`/
`CurrentUser`/`PageState` facades, 52 `define()` constants retired, `.po`
migration, ICU MessageFormat pluralization. **Real gap found**
(2026-07-13 audit): `src/Piwigo/Template/` (8 classes with real logic —
`Template`, `ScriptLoader`, `CssLoader`, `FileCombiner`, `Combinable`,
`Css`, `Script`, `PwgTemplateAdapter`) had zero dedicated Unit test
coverage, only indirect exercise via the Browser suite. **Fixed** in the
pre-P23 remediation pass — all 8 classes have real `tests/Unit/Template/`
coverage now.

### Epoch E — Service layer (P17–P23)

**P17–P20 — Domain tiers 1–4.** The ~35 domain namespaces, migrated in
dependency order (each tier only depends on the ones before it): **Tier 1**
(no service deps) URL, Cookie (built as `Piwigo\Auth\CookieService`, not a
standalone `Cookie` namespace — confirmed this round; a real placement
choice, not a gap like `Common` below, since the functionality itself
exists), Session, HTML, Storage, Csrf, Permalink, Site, Feed. **Tier 2**
Mail, Filter, User (the real namespace is `Users`, plural), Auth, Tag, Comment, Rate, Group,
Caddie, History, Activity. **Tier 3** Category, Search, Image, Calendar,
Notification, Metadata, Telemetry, Validation, Common. **Tier 4** Page
renderers, Menu, PluginConfig, Section, Job. Each domain's legacy
`include/` file was deleted immediately after its migration, not batched
to the end.

**Real gap found this round, in P19's own scope, not previously
documented**: `Common` (the last item in Tier 3's own list above) was
never built on this branch, at all, under any name — `src/Piwigo/Common/`
doesn't exist, confirmed directly. This isn't the same as `docs/REFERENCE.md`'s
Architecture section's "reserved, no classes yet" note treats it (an inert
placeholder) — the original plan's `Common` scope was a real typed-primitives
layer: path/id/email value objects (`AbsPath`, `RelPath`, `CategoryId`,
`CommentId`, `Email`, `Md5Sum`), domain enums (`Privacy`, `Section`,
`SortOrder`, `UserStatus`), and generic DTOs (`PaginatedResult<T>`,
`UserGroupPair`) — confirmed real and substantial by checking `16.x-rewrite`
(the reference branch, present in this same repo's git history), which did
build all of it. None of it exists on `17.x-rewrite`, under `Common` or any
other namespace: only 3 scattered, domain-specific enums exist anywhere
(`Admin\Extensions\ExtensionType`, `Routing\RouteMatchStatus`,
`Users\UserStatus` — the last one is a real, if partial, analog for one
`Common\Enum` case, placed in its own domain rather than centralized), no
path/id/email value-object layer at all, and no generic paginated-result
DTO (repositories return plain arrays/counts for pagination instead — see
the "Typed DTO/Projection pattern" note below, which undersold how much of
this is actually missing since it only checked for at-least-one typed
accessor per repository, not a systematic VO layer). **Open, not closed**:
whether this was a deliberate scope cut (the doc found no decision record
for it) or simply never circled back to, same shape as the
`LocalConfigOverrides` finding below — flagging precisely rather than
guessing. No gaps otherwise found in P17–P19; P20 had one (below).

**P21 — Admin controller migration.** **Correction to an earlier pass of
this doc**: "62 admin pages" was never a target count of
`AdminSubControllerInterface` services to build — re-reading
`PLAN-REPLAY.md`'s own "Codebase baselines" table found it's the
`origin/16.x` (upstream Piwigo) raw `admin/*.php` file count being
replaced (that same table shows `16.x-rewrite`, the reference
implementation, consolidating those 62 files down to a much smaller
number itself). An earlier pass of this doc misread it as an overstated
target and wrongly flagged a "62 vs 37" shortfall. The real, current,
still-worth-recording fact: `config/admin_pages.php` maps exactly **37**
page slugs to `AdminSubControllerInterface` services today, matching the
37 classes that actually implement the interface — a reasonable,
consolidated count replacing 62 legacy files, not a gap against an unmet
target. Dispatch itself is
`Bootstrap\AdminDispatcher::dispatch()` (not `AdminController`, which
doesn't exist under that name) — built decomposed from the start, not
migrated-then-decomposed: the reference implementation's god-classes
(`MaintenanceController`, `MiscController`, `BatchManagerController`) were
never reproduced as monoliths here. Same rule applied to admin PEM services
(`PluginScanner`/`PluginLifecycle`/`PemCatalog` and theme/language
equivalents authored directly, not as `Admin/Plugins.php`-style 700-line
god-classes).

**P22 — Frontend controller migration.** Originally scoped as 21 frontend
controllers; **real finding this round, re-verified directly against
`src/Piwigo/Controller/`**: only 19 of those 21 names were ever built on
this branch — confirmed via a branch-scoped `git log` (not `--all`, which
mixes in `16.x-rewrite`'s own unrelated history and would wrongly suggest
these once existed here). `Install` was never meant to become a
`Controller\InstallController` in the first place — `public/install.php`
stays a special, unrouted entry point (it must work before any DB/config
exists, so it can't go through the normal DI-resolved Router/Controller
path), backed by `Bootstrap\InstallBootstrap` + `Admin\Install\InstallWizard`
instead — a legitimate different architecture, not a miss. `Upgrade`/
`UpgradeFeed` genuinely were never built, on any branch history for this
fork: no route, no controller, confirmed absent from `config/routes.php`.
Consistent with `ADR-0002`'s "no in-place upgrade" stance and the later
deletion of the entire `DbPatch`/`VersionUpgrade` chain (see P23's
gap-closure list below) — there's no upgrade mechanism left to drive an
`Upgrade`/`UpgradeFeed` controller, so their absence is a real, consistent
consequence of that design decision, not an oversight. Render via Smarty,
collecting an engine-agnostic `$vars` array (P29's future Latte swap is
meant to be a one-line render-call change per controller, not a rewrite —
P29 hasn't started). **Real gap found** (2026-07-13 audit): `GalleryController` only
relocated `include/section_init.inc.php`'s `include()` call site into the
controller — the ~450 lines of raw SQL logic P20's own docblock said
belonged here (`$page['items']`, favorites, next/prev navigation) was
never actually absorbed. **Fixed** — folded into P23's Gallery/Picture
absorption batch.

**P23 — Legacy deletion & cleanup.** The largest single phase in the
original plan and, per this fork's own execution, larger still — see
"P23 in detail" below. Headline outcome: `include/`/`admin/` (as
directories) are fully deleted, all `$GLOBALS`/static-bridge globals are
retired, the legacy `Tables`/`AbstractRepository` DBAL layer is gone
(migrated to real Doctrine ORM), and the event-dispatch/`l10n()`/URL
free-function bridges are retargeted onto real classes — confirmed
directly: zero `global $x` statements, zero live `$GLOBALS` reads, zero
bare legacy free-function calls anywhere in `src/Piwigo/` today, each
guarded by a zero-tolerance Arch test.

**This fork deliberately diverged from the original P23 plan in real,
documented ways**, not silently:
- `include/` was **not** deleted entirely at the time — it kept a 4-file
  bootstrap seam (`common.inc.php`, `config_default.inc.php`,
  `env.inc.php`, an anti-listing stub) through P23's own batches, since
  SEC-60 needs `define()`s to stay out of `src/Piwigo/`. **This has since
  been fully closed anyway** — `include/` doesn't exist at all today,
  confirmed directly; the bootstrap seam collapsed into
  `Piwigo\Bootstrap\RequestBootstrap` during the later `$GLOBALS`
  retirement work (Track A7, see below).
- Root entry points (`admin.php`, `picture.php`, etc.) were kept as thin
  shells rather than collapsed into one front controller — this fork
  keeps Piwigo's original URL surface. Since relocated into `public/` as
  part of web-root isolation (originally P32 scope, pulled forward).
- The `$GLOBALS`/static-bridge retirement bullets in the original P23
  plan were audited and **deliberately not executed in P23 itself** — the
  plan's premise ("zero callers remain after `include/` deletion") didn't
  hold in this fork, since ~230 `src/` files had real, live `global $x`
  contracts preserved verbatim by the migration discipline. Tracked
  instead as its own post-P23 initiative (below) — and that initiative is
  now done.
- `Tables.php`/`AbstractRepository` were kept, not deleted, pending the
  post-P23 ORM remediation (P14's own audit note) — now done, see Epoch D
  above.

#### Gaps found in a later audit, since closed

A 2026-07-13 full P0–P22 audit and further re-investigation found P23's
own manifest entry claimed more than had actually landed (`docs/plan/manifest.yaml`'s
own historical note: `status: done` meant "the phase sequence correctly
advanced," not "every claim in P23's prose is true"). All findings below
are now resolved — re-verified directly against live code during this
consolidation, not copied from the gap-closure doc's own claims:

- **43 column-type migrations** — mostly done; mechanism changed (the
  Doctrine Migrations plan was reversed, see P14 above — every "done" item
  was verified by reading the static schema SQL directly). 4 real
  columns' serialize()/unserialize() leaks fixed end-to-end via typed
  `CurrentConfig` accessors; a 4th column (`activity.details`, not in the
  original 43) found and fixed the same way.
- **Typed DTO/Projection pattern** — every one of the 31 real domain
  repositories (32 files match `*Repository.php`, but one of those,
  `Db/AbstractRepository.php`, is the shared base class itself, not a
  domain repository) now has at least one typed accessor returning a real
  object instead of a raw array (e.g. `SearchRepository::findOneByClause():
  ?Search`) — the "finding #1" gap `PLAN.md` called "the biggest unmet
  claim" is closed in that sense. **Re-verified this round, one caveat**:
  this doesn't mean every method on every repository returns a typed
  object — the 8 repositories intentionally kept on `AbstractRepository`
  (see P14 above) still have generic, raw-`array`-returning query
  executors (`SearchRepository::findRowsByClause()`,
  `CalendarRepository::findRows()`, and similar) alongside their typed
  accessor, by the same deliberate design documented in each one's own
  docblock, not a residual gap.
- **Per-namespace Unit test coverage** — 11 of 11 caught up.
- **`CachePools` full wiring** — done (see P11 above).
- **`die()`/`exit()` elimination (image-processing failure paths)** — done,
  re-verified this round against the real current scope, not the original
  estimate: the original finding's own 3 named files
  (`ImageGd.php`/`PwgImage.php`/`Admin\Upload\UploadService.php`, 17 of the
  original 34 call sites) now have 0 real `die()`/`exit()` calls, replaced
  by `ImageProcessingException` throws per that class's own docblock. **Not
  the same claim as "0 `die()`/`exit()` calls anywhere"** — re-verified this
  round, 9 real call sites remain project-wide, across 9 other files; see
  the C3 workstream note below for why (this is the still-open
  request-lifecycle architecture gap, not a missed cleanup pass) — at least
  one of the 9 (`ShutdownHandler::install()`'s `exit(143)`) is a correct,
  deliberate SIGTERM exit-code convention, not a gap at all.
- **`reset()` arch-test coverage** — done; 31 classes now have a tested
  `reset()` (up from the 13 the 2026-07-13 audit found), all but 2
  legitimate exceptions verified individually.
- **FrankenPHP worker mode** (finding #15, first half) — **still not
  started**. See the status table above.
- **Legacy import tool** (`bin/piwigo import:legacy`) — **still not
  started**.
- **A repo-wide legacy sweep round 2** (`global` cleanup outside
  `src/Piwigo/`, `die()`/`exit()` request-lifecycle architecture,
  `LegacyRenderCapture` void-renderer → return-string conversion,
  DbPatch/VersionUpgrade raw-SQL-to-DBAL bound parameters) — all done
  except one workstream: the `header()`+`echo`+`exit()`/`: never`-return
  request-lifecycle architecture (`RedirectServiceInterface`,
  bootstrap-phase short-circuits) is investigated and designed in outline
  only, **not started** — confirmed architecturally deeper than a cleanup
  item (would mean changing `RedirectServiceInterface`'s contract from
  `: never` to `: ResponseInterface`), deliberately deferred to its own
  planning pass. Blocks 3 controllers
  (`PopuphelpController`/`AdminPopuphelpController`/`PictureController`)
  from finishing their `LegacyRenderCapture` → return-string conversion;
  10 of 13 controllers converted, these 3 wait on this.
- **`maintenance:repair-db`** — still not built (see P12 above; found via
  a separate, later audit of the pre-P23 remediation plan itself, not the
  2026-07-13 P0–P22 audit).
- **Install/upgrade legacy constants + a real `PWG_CHARSET` bug** — found
  and fixed.
- **`local/config/config.inc.php` bridge — fixed, then superseded, not
  currently live as originally fixed.** A real, previously-undetected bug
  was found and fixed here (2026-07-21, `338217f48`): nothing in
  `src/Piwigo/` ever actually read a site's local config override file on
  a real request, silently ignoring any non-DB-credential key
  (`order_by_custom`, `data_location`, `guest_id`, etc.) a site
  customized there. Fixed at the time via `LocalConfigOverrides::read()` +
  `ConfigLoader::applyLocalFileOverrides()`. **Confirmed via `git log
  -S` and direct code inspection**: `LocalConfigOverrides.php` was
  deleted outright 3 days later (2026-07-24, `feede75c9`, "typed
  CurrentConfig properties, DbCredentials/DeploymentPolicy split") as
  part of a deliberate, much larger redesign — `ConfigLoader::applyDefaults()`/
  `applyEnvOverrides()` are genuine no-op method bodies today (confirmed
  by reading the class directly), and the only surviving local-file
  mechanism is `Piwigo\Config\DeploymentPolicy`, sourced from a
  differently-formatted `local/config/config.php` (not `.inc.php`) and
  explicitly scoped to a narrow, deliberately separate list of
  security-boundary settings (PHP error display, auth-bypass flags,
  allowed hosts) — its own docblock states it "never overlaps with
  CurrentConfig (DB)." **Open question, not resolved by this
  consolidation**: whether arbitrary site-local overrides of ordinary
  settings (the original bug's own examples) are meant to be reachable
  any other way now (the DB-backed admin UI, presumably) or whether this
  is a genuine unintentional regression — the architectural intent
  wasn't documented anywhere this consolidation found. Flagging
  precisely rather than guessing which it is.

### The `p24`-tagged remediation era (271 commits — not the original P24)

As flagged above: none of this is the original plan's P24 ("Vite +
TypeScript conversion," still not started). What actually happened under
271 `feat(p24)`/`fix(p24)`-tagged commits is the post-P23 remediation work
several phase sections above already promised — the ORM migration (P14),
the `$GLOBALS`/static-bridge retirement P23 explicitly deferred, and the
event-dispatch/`l10n()`/URL free-function bridges P23 batch 8c/8d also
deferred as "too many call sites." Tracked in its own planning docs
(`legacy-coupling-retirement.md`, `gap-closure-p0-p23.md`) rather than
this one at the time; folded in here now so there's one current record.

**Part B — DBAL → ORM migration.** 16 of 31 domain repositories converted
from `AbstractRepository`+`Tables::` (hand-written DBAL) to real Doctrine
`EntityRepository` + attribute-mapped entities — the P14 remediation
promised above (see that section for the exact current breakdown, including
the corrected 31-repository census — a previous pass of this doc both
undercounted the total at 25 and named the wrong class,
`ServiceEntityRepository`, which this codebase doesn't use at all). 8
repositories stay on `AbstractRepository`/DBAL by design (dynamic query
shapes) and 7 more — not "just `Permission`" as an earlier pass of this doc
said — hold `EntityManagerInterface` directly instead of extending either
base class, since they touch tables owned by a different repository; see
P14 above for the full breakdown of all three groups. Real findings along
the way: the shared Doctrine identity map serves stale data after
bulk/raw writes outside the ORM (needs `HINT_REFRESH` for reads or
`clear()` after bulk ops) — first hit twice while converting the
repositories themselves (`TagRepository`'s `image_tag` bulk insert, one
other raw-write site), then found to be a much larger, repo-wide problem:
a dedicated follow-up audit (`cb956266b`) found **33 real call sites
across 13 files** where Controller/`Ws`/Admin classes bypass their domain
repository entirely and write via `BatchWriter`/raw `executeStatement()`
against a table an entity now maps, each needing the same
identity-map-clearing fix. Confirmed still current: `33` call sites,
`13` files. This needed a real new accessor, not just a fix at each call
site — `Piwigo\Db\EntityManagerFactory::build()` turned out to not be
memoized (always constructs a fresh `EntityManager`, so clearing a
locally-built instance protects nothing); the only genuinely shared
identity map lives on the DI container's `EntityManagerInterface`
singleton, reachable only through `Kernel::container()`, itself
Arch-test-restricted to `Bootstrap/`. `Bootstrap\InfrastructureAccessor::entityManager()`
(confirmed still real) was added specifically so L4Integration classes
could legally reach and clear that shared instance. Two call sites were
investigated and deliberately left unfixed, not missed: `InstallWizard.php`'s
pre-seed writes (the container's `EntityManagerInterface` would wrap a
connection built from stale pre-seed credentials — clearing it would be
both risky and pointless) and `UserService::checkAndSaveUserInfos()`'s
writes (its only real caller manually builds a fully isolated
`EntityManagerFactory`/repository chain with no container involvement at
all, so there's no shared identity map in that path to protect).

**Track A — `$GLOBALS`/static-bridge retirement.** Batches A1–A8, smallest/
lowest-risk first: `$template` → `Piwigo\Template\CurrentTemplate`, `$lang`
→ `Lang::t()`/new bulk accessors, `$user` → `CurrentUser::get()` (found
**not actually functional for real users before this batch** — only ever
seeded a guest placeholder, a real production bug fixed here, not just a
retarget), `$conf` → `Config::` (found a **root architecture bug**:
`Config::$data` was never synced with DB-persisted overrides during the
early bootstrap window — fixed by making `ConfigDb`'s write paths call
`Config::override()`/`Config::delete()` alongside the legacy write, both
since renamed/replaced — see Epoch D's P13 entry above for the current
names), `$page`
→ `PageState` (9 sub-batches, the one global without a complete existing
target — needed real design work, not just mechanical retarget), the
remaining ~25 smaller globals (`$my_base_url`, `$logger`, `$mysqli`,
`$prefixeTable`, `$filter`, `$pwg_loaded_plugins`, and more), then
collapsing `include/common.inc.php`'s raw seeding into real object
construction (A7) and deleting the `attachGlobals()` bridge shape (A8,
partially — the method *names* were kept as the per-request seeding entry
point on `CurrentUser`/`PageState`/`Lang`, since nothing needs a `$GLOBALS`
bridge anymore but a seeding call still runs once per request; a
documented judgment call, not a miss). **Confirmed live**: zero `global $x`
anywhere in `src/Piwigo/`, zero `$GLOBALS` reads, the door-lock Arch tests
pass.

**Track B — event dispatch retarget.** **Done and verified live
(2026-08-02).** The free-function elimination landed first —
`add_event_handler()`/`trigger_change()`/`trigger_notify()`
(`PluginConfig/functions.php`) deleted, all 241 real call sites (later
re-counted at 240) retargeted onto `EventDispatcher::get()->addEventHandler()`/
`triggerChange()`/`triggerNotify()` directly. Track B's actual point —
typed event objects (`SomeEvent` classes, `dispatchNotify()`/
`dispatchChange()`) replacing the bare-string-keyed dispatch — then
shipped across 12 domain batches (all 155 real events, including the
7-event WS-protocol-lifecycle group originally deferred behind P26).
`EventDispatcher.php` now exposes `addTypedHandler()`/`dispatchChange()`/
`dispatchNotify()` alongside the original string-keyed methods (kept only
for `'trigger'`, its own permanent internal meta-notification channel). A
token-aware door-lock Arch test enforces zero remaining string-keyed call
sites outside that one name. Full history and design notes:
`~/.claude/plans/track-b-typed-events-gap.md`.

**Track C — `l10n()`/`get_root_url()` retarget.** Done and verified —
`Lang/functions.php`/`Url/functions.php`/`Category/functions.php`/
`Http/functions.php` (the last of the free-function bridges from P23
batch 8) all deleted, `composer.json` has zero `autoload.files` entries
left at all. Real finding along the way: `Piwigo\PluginConfig` had to
split across two Deptrac layers mid-migration (see Epoch A–B above for
the same class of finding at P6) — a free-function call creates no
Deptrac dependency edge, but the direct class reference this migration
introduces does, so 14 real L1/L2a callers of `EventDispatcher` were
invisible to Deptrac until this migration made them real edges.

**Global-residual, ConfigDb, and TODO/FIXME/XXX sweeps.** Beyond the 3
named tracks: `$filter`/`$pwg_loaded_plugins` fully retired (not just
reduced); direct `ConfigDb` callers retargeted; 28 TODO markers triaged
(each resolved, fixed, or explicitly re-flagged with a reason, not just
deleted).

**Repo-wide legacy sweep, round 2** (2026-07-18/19, prompted by "check the
whole repo, not just `src/`, don't stop at the first pass"): 6 real
`global` sites outside `src/Piwigo/` fixed, `Ws/PwgImages.php`'s 5 raw
`die()` JSON calls retargeted onto its own typed `PwgError` path (fixed a
real latent bug — the old `die()` always emitted JSON even when the
client requested `format=rest`), `LegacyRenderCapture`'s void-renderer
pattern converted to return-string for 10 of 13 controllers (3 remain,
blocked on the request-lifecycle redesign noted above), 44 of ~144
DbPatch/VersionUpgrade files given real bound-parameter DML at the time (2
real double-escaping bugs found and fixed along the way) — **superseded
since, not just historical**: the entire `DbPatch`/`VersionUpgrade`
subsystem (153 files) was deleted outright the following day (`8224f23a3`,
"delete the legacy in-place upgrade chain (Stage 0)"), confirmed gone from
the working tree — it contradicted the project's own "clean fork, no
in-place upgrade" design (`ADR-0002`) and had been carried over mechanically
during porting before anyone caught the conflict. The bound-parameter fix
above is moot, not a currently-live improvement to point to. One workstream
(C3, the `die()`/`exit()`/`: never`-return request-lifecycle architecture)
correctly still open, covered under P23's own gap list above rather than
repeated here.

**Test coverage.** Most recently (`9f5198bfe`, 2026-07-26): closed a
70-class zero-coverage gap found via a combined Unit+Arch+Integration+
Contract+Browser coverage measurement (539 classes total, 220 of which
were only invisible because Contract/Browser coverage wasn't measurable
before that session's own pcov work) — combined line coverage raised from
10.6%/15.6% (siloed, pre-visibility) to 65.07%.

**A 2026-07-25 full-sweep code-quality review** (all 470 `src/Piwigo/`
files read in full, not sampled) found several items — most already
resolved by later work above (Finding 1's Unit-test gap on
Controller/`Ws/`, Finding 2's stale `ARCHITECTURE.md`, Finding 3's
meaningless `test:coverage --min=5` gate, Finding 9's stale
`DbPatch`/`VersionUpgrade` doc references — all superseded by this
consolidation or the later coverage/deletion work above), but 3 items
re-checked this round and confirmed **still genuinely open**, not carried
forward anywhere until now:

- **Two real, still-live bugs, both cheap to fix, neither fixed since
  being found**: `Template::p()` (`src/Piwigo/Template/Template.php`)
  calls `\Smarty_Internal_Debug::display_debug($this->smarty)` when
  `CurrentConfig::debugTemplate()` is enabled — that class doesn't exist
  in the installed Smarty 5.x package (confirmed: the adjacent comment in
  the code already diagnoses the fix, `Smarty\Debug`, an instantiable
  class with the same `display_debug()` method, but was never applied).
  Would fatal the instant anyone enables `debugTemplate`. Separately,
  `MailService::generateResetPasswordMail()` (confirmed still present at
  its current line ~1069) uses `$message = Lang::t(...) . '</p>';` instead
  of `.=`, silently discarding the opening `<p>` tag set the line above —
  the sibling method `generateSetPasswordMail()` does this correctly.
  Cosmetic (malformed HTML in one transactional email), not
  security-relevant, but a genuine, still-unfixed bug in a real user flow.
- **`psalm.xml` (1143 lines, confirmed still that size) is still a live
  dependency** (`vimeo/psalm` still in `composer.json`) despite Psalm
  gating being paused since P5/`ADR-0026` — the review's proposal to
  delete both outright (not "shrink," since nothing runs Psalm to verify
  which suppressions are still needed) hasn't been acted on either way.
- **TODO/FIXME/HACK/XXX markers in `src/Piwigo/`**: 50 today (was 46 at
  review time), still uninventoried as a set — 2 concrete ones the review
  triaged (`DerivativeParams::is_identity()`'s docblock,
  `HtmlService`'s 4 `@todo nice display if $template loaded` markers)
  remain unresolved alongside the other ~46-50, none picked up since.

### Epoch F — Frontend (P24–P25) — not started as originally scoped

**P24 — Vite + TypeScript conversion** (real Vite entries beyond the
`noop`/`vitals` placeholders, JS → TS, jQuery removed entirely, a Lit
component catalog). **Not started.** `vite.config.ts` still has only 2
entries.

**P25 — Inline JS extraction + `any` reduction** (`{footer_script}` inline
blocks → real `.ts` modules, `getPageData<T>()`, TypeScript `any` driven to
zero, real bundle budgets). **Not started** as originally scoped — the 52
commits tagged `p25` are the PHP-side mixed-elimination work covered under
"The `p24`-tagged remediation era" above, unrelated to this phase's actual
frontend scope.

### Epoch G — REST/OpenAPI + types (P26–P27)

**P26 — REST resource layer + OpenAPI, legacy WS API removed** (`/api/v1`
as the sole API, ETag/304, `Link` pagination, a generated typed TS client;
removes the 94-method legacy RPC WS API this whole codebase's Contract
test suite currently locks in). **Not started.** The 21 `Ws*Test` Contract
tests, the whole `Ws/` namespace, and every `l10n()`/URL-retarget note
above that explicitly deferred a WS-specific event/function pending this
phase are all still waiting on it.

**P27 — Type correctness + mixed elimination.** **Real, substantial
progress** — this is the one post-P23 phase where the commit-tag label
matches its original definition. 89 commits: continuing the mixed-elimination
sweep from the `p25`-tagged work (Phase 4), replacing ambient `$_POST`/
`$_GET` superglobal reads across dozens of controllers/sub-controllers with
typed Request DTOs (one per action/param cluster — `PluginSectionRequest`
and many more), plus real bugs found and fixed along the way (a SQL
injection via a raw `cat_id` superglobal read in the cat-modify renderer;
a stale `$_POST` dead write in `AlbumSubController`; comment
rejection-reason tracking moved off `$_POST` onto `PageState`; a new
SEC-40 arch-test gate locking in "no raw superglobal reads outside a
Request DTO" going forward). Not complete — no claim of "0 remaining" has
been verified — but real, ongoing, and aligned with its own stated goal.

### Epoch H — Security + templates/CSS (P28–P30) — not started

**P28 — Security hardening** (WebAuthn/passkeys, OIDC SSO, nonce-based
CSP, COOP/COEP, CSP reporting). Depends on P27. Not started — `rate_limiter`
(the one P11 cache pool deliberately left unbuilt, "genuinely P28 scope")
is the clearest concrete marker that this phase hasn't begun.

**P29 — Template migration + asset pipeline** (Smarty → Latte →
`ViteManifest`, `<picture>` AVIF/WebP, ThumbHash placeholders). Not
started — every controller still renders through Smarty; the one-line
Latte render-call swap P22 set up for is still just potential energy.

**P30 — CSS modernization + Tailwind** (dark mode, `@container` queries,
`@layer` cascade). Not started — depends on P29's Latte templates existing
for Tailwind's `@source` scanning.

### Epoch I — Plugins/layering/repo-restructure (P31–P32) — not started

**P31 — Plugin / Theme contracts + bundled extensions + decomposition**
(`PluginInterface`/`ThemeInterface`, JSON-schema manifests, 16
Listener/Subscriber classes, migrating 7 bundled extensions, OpenAPI spec
generation, outbound webhooks). **Not started** — but its typed-event-object
piece shipped early, independent of the rest: Track B (above) already
built all 155 typed event classes and the `dispatchChange()`/
`dispatchNotify()`/`addTypedHandler()` dispatch mechanism, ahead of and
outside P31. What's left here is wiring those classes to a real plugin
registration surface — P31's own documented design ties them to
`PluginInterface::subscribedEvents()`, which doesn't exist yet.

**P32 — Layer decoupling + repository restructure** (drive the Deptrac
ratchet to zero cross-cutting residue, then the repository restructure —
web-root isolation, `public/` entry point, formerly its own phase). The
web-root-isolation half was pulled forward and is done (see
`docs/REFERENCE.md`'s Deployment section — `public/` is the real document
root today). The 1 commit actually tagged `p32` (`chore(p32): delete doc/`)
is an unrelated, narrow cleanup that borrowed the tag, not phase work.
Layer decoupling itself: not started (though Deptrac already reports 0
violations today — whether that's "P32's ratchet reaching zero" or just
"no violations have accumulated yet" hasn't been separately verified).

## Greenfield tracks (T3, cuttable — outside the P0–P32 backbone)

T3·WEB (PWA, View Transitions, Speculation Rules, JSON-LD, SRI, resource
hints — depends on P24/P29/P30), T3·AI (depends on P19/P26), and T3·RIDERS
(CQRS, libvips/HEIC, vector/CLIP search, tus uploads, webhooks, Fibers,
Mercure, passkeys, OIDC, soft delete — each hosted on its own backbone
phase) are all entirely cuttable, never gate a backbone commit, and are
dropped first on overrun. None have started — each depends on backbone
phases that haven't landed yet. The **legacy import tool** (`bin/piwigo
import:legacy`) is the one non-cuttable exception in this group — T2
adoption tooling, not a rider — see the status table above.

## What changes from the original branch — not reproduced here

The original plan carried a ~150-row table comparing `16.x-rewrite`
(the reference implementation) against this fork's intended end state,
feature by feature. Dropped from this consolidation as redundant with the
phase-by-phase status above — nearly every row maps to a specific P24+
phase already marked "not started" in the status table, and the handful
describing already-landed work (coverage measurement, static-singleton
retirement, service-locator avoidance) are already covered in their own
phase sections. If a specific comparison from that table is needed, it's
recoverable from git history (`docs/PLAN-REPLAY.md` as it existed before
this consolidation).

## Execution approach for remaining phases (P24–P32)

Still the governing process for whoever picks up P24+:

1. **Write tests first** (or in the same commit group).
2. Read the target state of the equivalent code on the `16.x-rewrite`
   reference branch (`../piwigo16-rewrite`) — for reference only.
3. **Re-implement manually.** Nothing is git-pulled from either branch —
   self-contained files are re-created by hand, greenfield items (no
   reference counterpart) are authored new.
4. `config/container.php`/`config/routes.php` grow incrementally with each
   phase — never reproduced from the reference in bulk.
5. Full gate suite after each commit group; fix before proceeding.
6. **Documentation: extend `docs/REFERENCE.md`/`docs/PLAN.md`'s existing
   sections, don't create a new per-phase doc file.** The original plan
   (recovered from git history) had each remaining phase spinning up its
   own doc (`docs/FRONTEND.md` at P24, `docs/API.md` at P26,
   `docs/SECURITY.md` at P28, `docs/PLUGINS.md`/`docs/EVENTS.md`/JSON
   schemas at P31, `docs/STRUCTURE.md` at P32, `docs/AI.md` for T3·AI) —
   that plan predates this consolidation and is superseded by its whole
   premise (18 drifting files reduced to 2). A future P24+ contributor
   should add a Development/Deployment subsection or a new phase entry,
   not reintroduce the fragmentation this consolidation just closed.

**Risk register** (highest blast-radius remaining phases): P29 (Smarty →
Latte across ~140 templates) risks visual regressions — mitigated by the
committed VR baselines + a11y gate + per-template review, same mechanism
already in daily use. P31 (plugin/theme contracts, god-class decomposition)
breaks external extensions by design — an accepted product decision, not
an oversight; in-tree callers migrate in the same phase. Cross-cutting:
MySQL 9.x is a non-LTS line — pin the exact server version, hedge via the
MariaDB/PostgreSQL provider matrix.

**Rollback rules** (unchanged from the original plan, still the working
discipline): every commit must be green — fix before the next commit, never
accumulate broken state. Stuck mid-phase → revert to the last green commit,
re-approach, don't push through. A phase materially exceeding its estimate
→ drop its T3 (cuttable) items first, split the phase only if T1/T2 alone
is still oversized.

## MySQL infrastructure notes (one correction this round, rest still accurate)

**Real, currently-implemented collation corrected this round — the plan's
own stated choice was never actually applied.** `utf8mb4_0900_ai_ci` was
the *originally planned* MySQL collation (more accurate multilingual sort
than `utf8mb4_unicode_ci`) — but it doesn't appear anywhere in the live
repo: all 39 `CREATE TABLE` statements in
`install/piwigo_structure-mysql.sql` explicitly declare
`COLLATE=utf8mb4_unicode_ci` instead, confirmed by direct count (0 matches
for `utf8mb4_0900_ai_ci`, 39 matches for `utf8mb4_unicode_ci`, 39
`CREATE TABLE` statements total — uniform, not a partial rollout). No
decision record explains the reversal; relevant for any phase still
touching schema, since a new table following the *original* plan's
`utf8mb4_0900_ai_ci` instruction would be inconsistent with all 39
existing ones. Whether this was a deliberate, undocumented simplification
(fewer moving parts across the MariaDB/PostgreSQL provider matrix, since
MariaDB has no `_0900_` collation either way) or an oversight isn't
established — flagging precisely rather than guessing, same as the
`LocalConfigOverrides` finding above. MariaDB's `utf8mb4_uca1400_ai_ci`
equivalent was, similarly, never actually adopted for the same reason.
MySQL 8.0+ has no `.frm`/query-cache — the
`symfony/cache` layer is the intentional replacement, not a gap. `SET
PERSIST` is available for the future admin maintenance page's MySQL tuning.
Replication terminology is `SOURCE`/`REPLICA`, not `MASTER`/`SLAVE`, in any
future documentation or admin page that touches it.

## Migration path

Clean fork, no in-place upgrade from an existing Piwigo install (`ADR-0002`).
`InstallWizard` creates the schema directly from a single, hand-maintained,
already-final-shape `install/piwigo_structure-mysql.sql` — no Doctrine
Migrations, no per-version migration files (the original design, reversed
2026-07-24 before any real install existed). There is currently no
version-to-version upgrade mechanism for a *shipped* install, since
nothing has shipped yet — open design work for whenever it's actually
needed. Adopting from an existing Piwigo install is meant to go through
`bin/piwigo import:legacy` — not built yet (status table above).

## Verification (final end-state gate, not current status)

The full gate list once every phase above is actually done — most of
these already run in CI today per-commit (see `docs/REFERENCE.md`'s CI
section for current status of each); a few are aspirational until later
phases land:

```bash
vendor/bin/pest                             # unit, integration, browser, arch
vendor/bin/pest --mutate --min=60           # mutation score — not run in CI yet
vendor/bin/pest --type-coverage --min=95    # type coverage
vendor/bin/ecs --no-progress-bar            # style — still non-blocking, see REFERENCE.md
vendor/bin/phpstan analyse                  # level 10, 0 errors — blocking today
vendor/bin/rector --dry-run                 # still non-blocking, see REFERENCE.md
vendor/bin/deptrac --no-progress            # 0 violations — blocking today
vendor/bin/composer-require-checker check
vendor/bin/composer-unused
vendor/bin/phpbench run --report=aggregate
just typecheck && just lint-js && just lint-css
just build
bun run test:unit -- --coverage
bunx size-limit
bunx knip
bunx lhci autorun
actionlint .github/workflows/*.yml
bunx commitlint --from origin/16.x --to HEAD
k6 run tests/Load/*.js                      # non-blocking, tests/Load/ doesn't exist yet
```

**Not in this list anymore**: `vendor/bin/psalm` (gating paused, `ADR-0026`
— its resume condition has been met but gating hasn't been reconsidered,
see `docs/REFERENCE.md`), `composer lint:latte`/`precompile:templates`
(P29 hasn't started, Smarty is still the template engine), `tools/plan-lint`
(deleted along with `docs/plan/manifest.yaml` in this consolidation).

**Real consequence of that last deletion, not just a doc note**: the
original plan's SEC-NN traceability design (every `SEC-NN` reachable from
threat model → phase checklist → manifest → `verified_by` test, enforced
automatically by `plan-lint`) has lost its automated cross-check. The
threat model and phase mapping are reproduced below (carried forward from
the original `PLAN-REPLAY.md`'s own security master checklist section,
which this consolidation had dropped entirely on the first pass — a real
omission, not a stylistic trim, caught and fixed after re-checking), but
nothing currently re-verifies automatically that every `SEC-NN` still
appears in all the places it should. Flagging this honestly rather than
silently — if SEC traceability enforcement matters going forward, it
needs a new mechanism that doesn't depend on the deleted YAML file.

## Security master checklist

65 items, `SEC-01`–`SEC-65`, each globally unique. **Status column is
derived from this file's own phase-status table above, not independently
re-verified item by item** — treat "phase done ⇒ item done" as a
reasonable default, not a guarantee, except where marked `(confirmed)`,
which means this consolidation directly verified it in code.

| ID | Phase | Item | Status |
| --- | --- | --- | --- |
| SEC-01 | P4 | `.htaccess`/Caddy deny rules for sensitive directories | Done (confirmed) |
| SEC-02 | P0 | CLI guards on all `tools/*.php` scripts | Partial (checked this round): most real entry-point scripts have a `PHP_SAPI !== 'cli'` guard, but `tools/i18n/verify-parity.php` and `tools/i18n/convert-all.php` — both real, directly-invokable CLI tools per their own "Usage:" docblocks — have none. Not currently reachable (`tools/` isn't among `public/`'s 3 real symlinks), but a literal, live gap against this item's stated scope regardless of reachability |
| SEC-03 | P2 | No fixture SQL with secrets in web root | Done |
| SEC-04 | P4 | Ship `robots.txt` | Done |
| SEC-05 | P4 | Brotli compression | Done |
| SEC-06 | P4 | `Cache-Control: immutable` for hashed assets | Done |
| SEC-07 | P5 | Replace `mt_rand()` with `random_int()` | Done for security-sensitive uses (confirmed this round) — 7 `mt_rand()` calls remain project-wide, but each is non-security-sensitive (temp-filename uniqueness, cache-busting query params, probabilistic log-sampling gates, or picking a *length* parameter for a value that itself comes from `random_bytes()`/`generateKey()`, e.g. `Ws\PwgUsers.php`'s auto-generated password). None are the actual entropy source for a security-relevant token |
| SEC-08 | P5/P17–P23 | Replace loose `==` with `===` (manual, per-domain) | Done |
| SEC-09 | P5 | `#[\SensitiveParameter]` on secret-carrying params | Partial, not "Done" (checked this round): only `Users\UserService.php` and `Auth\PasswordService.php` use the attribute anywhere in `src/Piwigo/`. Real gaps found: `Auth\AuthService::tryLogUser()`/`::pwgLogin()` — the actual login entry points — take `?string $password` unguarded; `Db\DbCredentials`'s constructor holds the real DB password unguarded; 4 Request DTOs (`IdentificationSubmitRequest`/`RegisterSubmitRequest`/`PasswordRequest`/`UserBootstrapRequest`) hold raw passwords in unguarded constructor-promoted properties. Any exception thrown during login or DB-connection setup would currently leak the plaintext password into that exception's stack-trace args (visible to logs/Sentry) |
| SEC-10 | P9→P17–P23 | Remove `addslashes()` superglobal sanitization | Done |
| SEC-11 | P9 | CSRF token md5→sha256 HMAC | Done (confirmed — see Epoch C) |
| SEC-12 | P9 | CSRF verification via `hash_equals()` | Done (confirmed — see Epoch C) |
| SEC-13 | P9 | `CookieService` HttpOnly + Secure flags | Done |
| SEC-14 | P9 | Cookie deletion calls include all flags | Done |
| SEC-15 | P20 | Eliminate 2 of 3 `eval()` calls (3rd = SEC-49) | Done |
| SEC-16 | P19 | Wrap `exec()` calls with `escapeshellarg()` | Done (confirmed — 40 real call sites) |
| SEC-17 | P17 | URL validation in redirect responder | Done |
| SEC-18 | P19 | Replace `addslashes()` in `SearchService` with prepared statements | Done |
| SEC-19 | P21–P22 | Controllers use PSR-7 request, not superglobals | Done |
| SEC-20 | P19 | XXE protection on SVG/XML parsing | Done (confirmed — `Piwigo\Metadata\MetadataService`) |
| SEC-21 | P19 | SVG stored XSS sanitization on upload | Done |
| SEC-22 | P21 | Replace `phpinfo()` with curated server info | Done |
| SEC-23 | P17 | SSRF hardening for the HTTP client | Done |
| SEC-24 | P17 | Remove local-file read fallback in the HTTP client | Done |
| SEC-25 | P18 | Session fixation: regenerate on privilege escalation | Done (P28 was meant to verify further; P28 not started, so only the P18 half is confirmed) |
| SEC-26 | P16 | Validate locale before `include` in `LangService` | Done (confirmed — see Epoch D) |
| SEC-27 | P18 | Auto-login key HMAC sha1→sha256 + `hash_equals()` | Done |
| SEC-28 | P18 | `EphemeralKeyService` HMAC md5→sha256 + `hash_equals()` | Done |
| SEC-29 | P17 | Host header poisoning defense | Done |
| SEC-30 | P17–P22 | Exception messages don't expose internals | Done |
| SEC-31 | P18 | Account enumeration via registration | Done |
| SEC-32 | P20 | ZIP bomb protection | Done (confirmed — `Piwigo\Admin\Extensions\ZipExtractor`) |
| SEC-33 | P19 | Derivative serving leaks file existence | Partial — see `docs/REFERENCE.md`'s "not built yet": the permission-check half is real, but it runs through the full request pipeline, not the originally-designed fast path, so this item's *scope* shifted under it |
| SEC-34 | P22 | Install sentinel DB-flag secondary check | Done |
| SEC-35 | P19 | Remove non-standard headers from derivative pipeline | Done |
| SEC-36 | P26 | REST error responses never leak internals | **Not started** (P26) |
| SEC-37 | P26 | No object dumps in the REST error path | **Not started** (P26) |
| SEC-38 | P26 | REST route authorization middleware | **Not started** (P26) |
| SEC-39 | P26 | Validate `Content-Type: application/json` on REST bodies | **Not started** (P26) |
| SEC-40 | P27 | Request DTOs as a hard input-validation gate | Real progress — see P27 above (not "0 remaining" verified) |
| SEC-41 | P28 | Password hashing → Argon2id | **Not started** (P28) |
| SEC-42 | P28 | CSRF middleware: remove `/admin*` exemption | **Not started** (P28) |
| SEC-43 | P28 | No `Access-Control-Allow-Origin: *` on the OpenAPI spec endpoint | **Not started** (P28, depends on P26) |
| SEC-44 | P28 | API rate limiting + rate-limit headers | **Not started** (P28; the `rate_limiter` cache pool is deliberately unbuilt pending this) |
| SEC-45 | P28 | CSP violation reporting | **Not started** (P28) |
| SEC-46 | P28 | Cross-Origin Isolation (COOP/COEP) | **Not started** (P28) |
| SEC-47 | P28 | `Vary: Cookie` on permission-dependent responses | **Not started** (P28) |
| SEC-48 | P29 | Default `allow_html_descriptions` to `false` | **Not started** (P29) |
| SEC-49 | P31 | Remove `eval_visible` (plugin-facing half of SEC-15) | **Not started** (P31) |
| SEC-50 | P3 | CycloneDX SBOM generated as a CI artifact | Done (confirmed — `sbom` job in current CI list) |
| SEC-51 | P3 | Pin GitHub Actions to commit SHAs | Done |
| SEC-52 | P3 | OSV-Scanner over lockfiles in CI | Done |
| SEC-53 | P3 | SLSA build provenance + attestations | Done |
| SEC-54 | P4 | Sign container images + release artifacts (cosign/sigstore) | Done (confirmed — see `docs/REFERENCE.md`'s Deployment section) |
| SEC-55 | P28 | OIDC SSO: PKCE + state/nonce + ID-token validation | **Not started** (P28) |
| SEC-56 | P18 | GDPR data-subject endpoints behind re-auth + rate limit | **Not started** — `PrivacyService` doesn't exist (its REST exposure is P26/P29 scope, but the backend itself was P18 scope and isn't built either) |
| SEC-57 | P15 | Append-only / tamper-evident audit log | Done — `Piwigo\Audit\*` is real (see Epoch D) |
| SEC-58 | P11 | Feature-flag changes authz-gated + audited | Partial — `FeatureFlag` is read-only by design, no mutation path exists yet to protect (a deliberate, documented non-gap, not an oversight) |
| SEC-59 | T3·AI | MCP server: scoped read-only tokens | **Not started** (T3·AI, cuttable) |
| SEC-60 | P7 | Worker-mode request isolation | **Not started** — see `docs/REFERENCE.md`'s "not built yet"; this is the FrankenPHP worker mode gap |
| SEC-61 | P11 | Mercure topic authorization | **Not started** (T3 rider, hosted on P11) |
| SEC-62 | P28 | Trusted Types | **Not started** (P28) |
| SEC-63 | P28 | Fetch Metadata isolation | **Not started** (P28) |
| SEC-64 | P3 | OpenSSF Scorecard | Done |
| SEC-65 | P26 | API `Idempotency-Key` replay store | **Not started** (P26) |

**Threat model** (attacker goal → mitigating `SEC-NN` items) is a
different cross-section of the same 65 items — kept brief since the table
above already carries per-item status: every threat maps to at least one
`SEC-NN` above, so its own status is derivable from theirs. Two items
(SEC-05 Brotli, SEC-06 `Cache-Control: immutable`) are performance items,
not mitigations, and intentionally don't appear in any threat row.
Mitigations that aren't numbered items at all (nonce-based CSP, the PSR-18
SSRF guard, DB-level account locking, dual passwords) belong to
not-yet-started phases (P28) the same as their numbered siblings.

**Secrets & key management** (still accurate, not phase-dependent): DB
credentials and the application `secret_key` live in `.env`, never
web-served. A single `secret_key` derives the HMACs for CSRF tokens
(SEC-11/12), the auto-login cookie (SEC-27), and ephemeral keys (SEC-28) —
**rotating it invalidates all three at once**, forcing re-login
repo-wide; see `docs/REFERENCE.md`'s Secret rotation section. DB password
rotation via MySQL dual passwords (`ALTER USER ... RETAIN CURRENT
PASSWORD`) is P28 scope, not yet built — today's rotation path is the
simpler "update env, roll deployment" sequence `docs/REFERENCE.md`
documents.
