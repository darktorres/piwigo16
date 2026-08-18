# Plan and build history

Phase-by-phase record of `17.x-rewrite`: what was planned, what shipped,
and where the two diverge. This file and `docs/REFERENCE.md` are the only
two planning documents, deliberately — an earlier structure of 18
per-phase files drifted against each other and was consolidated into
these two.

`17.x-rewrite` replays `16.x-rewrite`'s modernization as 55 sequential
backbone phases (P0–P54, in 10 epochs A–J), rebuilt from `origin/16.x`
rather than upgraded in place. Every backend phase is sequenced before
every frontend phase. The work is dual-purpose: a *replay* of work that
has a reference implementation on `16.x-rewrite`, plus *greenfield*
capabilities with no counterpart there.

## How to read this file

- **Present tense is a claim about live code**, checked when the line was
  last edited. Where a claim was cheap to make machine-checkable, it
  carries a `doc-drift-check` marker (invisible when rendered) that
  `composer check:doc-drift` re-runs on every CI build.
- **Commit counts are indicative**, carried forward from when each phase
  landed. They are not re-derived and not worth re-deriving.
- **"Open question"** marks something genuinely unresolved — the intent
  was never recorded, or two sources of truth disagree. It is not a
  to-do; it is a flag that guessing here would be wrong.
- **Detail lives closest to the code.** Where a campaign had its own plan
  file, per-file specs stay there and only the outcome is recorded here.
- **The ~150-row feature comparison against `16.x-rewrite` is gone.**
  Nearly every row mapped to a phase already marked "not started" below,
  and the handful describing landed work is covered in its own phase
  section. It is recoverable from git history as `docs/PLAN-REPLAY.md`,
  as that file existed before the consolidation.

### Known coverage gap

Roughly 550 commits landed between 2026-08-11 and 2026-08-15, across
several concurrent worktrees. The two largest — the WS layer
decomposition and a repo-wide naming campaign — are summarized in Epoch
G below from git history and live code, not from a full audit. Smaller
work in that window (CSRF constructor injection across 36 files, admin
theme/icon fixes, a Rector/ECS modernization pass) is in `git log` only.

## Numbering and commit tags

**Commit-message phase tags do not track this file's numbers.** A tag
records which phase the author was working under at the time; three
successive renumberings have moved the boundaries since. Never infer a
phase from a tag — use the table below.

| Tag or label you'll encounter | What it actually covers | Phase today |
| --- | --- | --- |
| `(p24)`, `(p27)`, `(sql)`, `(di)`, `(lang)` | Post-P23 remediation & hardening | P24 |
| `(p25)` | PHP `mixed`-type elimination, phases 1–2 | P24 |
| `(p31.x)`, `P31.n` | Smarty → Latte conversion | P31 |
| `(p32)` in `style(templates)` | Latte reformat across the tree | P32 |
| `(p33a)`–`(p33h)` | Latte idiomatic sub-items A–H | P33A–H |
| `chore(p32): delete doc/` | A one-off cleanup that borrowed the tag | — |
| `(P25/G19)`, `(P19.n)` | WS layer decomposition into typed handlers | Epoch G / P25 |
| Original plan's "P24 Vite + TypeScript" | Frontend track | P36 / P45 |
| Original plan's "P27 Type correctness" | Merged into remediation | P24 |
| Original plan's "P32 CSS modernization" | CSS architecture | P51 |

Three structural changes produced that drift:

- **The original P27 ("Type correctness + mixed elimination") merged into
  P24.** It is the same class of work as P24's own remediation sub-tracks
  — superglobal and raw-array-offset access, type correctness beyond what
  P0–P23 shipped — not a separable phase. The freed number cascaded a
  one-step shift through everything after it.
- **Epoch J was renumbered on 2026-08-15 so phase-number order is
  execution order.** The previous numbering appended the
  templating/extension rewrite at the tail even though its real position
  is immediately after asset-pipeline and inline-JS/CSS extraction, and
  the three completed Latte phases carried higher numbers than
  not-started work that must precede them.
- **P25 split into three phases on 2026-08-15/16, cascading +2 through
  everything after it.** A review found the old P25 ("REST resource
  layer + OpenAPI") bundled two different jobs — modernizing the legacy
  WS API's internals and replacing it with REST — which is why it had sat
  at "not started" as a monolith. Split into **P25** (WS layer
  modernization — typed internals + PSR-7 lifecycle, no wire-format
  change), **P26** (admin fragment surface — the UI-facing WS methods
  move off the JSON/XML envelope), and **P27** (public API v1 — REST +
  OpenAPI 3.1 + tus, WS deleted here). Old P26–P52 shift to new P28–P54
  unchanged in scope and order — only the numbers move.

## Status

| Phase | Scope | Status | Commits |
| --- | --- | --- | --- |
| P0 | PHP tooling + baselines | Done | 8 |
| P1 | Frontend tooling + baselines | Done | 6 |
| P2 | Test harness | Done | 7 |
| P3 | CI pipeline | Done | 16 |
| P4 | Containerization + runtime image | Done | 4 |
| P5 | Composer + Rector + PHPStan | Done | 653 |
| P6 | PSR-4 namespace migration | Done | 34 |
| P7 | Kernel + boot skeleton | Done — worker mode (SEC-60) never built | 4 |
| P8 | DI container | Done | 4 |
| P9 | PSR-15 middleware + routing | Done | 6 |
| P10 | Observability | Done | 4 |
| P11 | Cache + session + messenger + `opcache.preload` | Done — no failed-job visibility | 4 |
| P12 | CLI tool + backup/restore | Done | 7 |
| P13 | Config service | Done | 4 |
| P14 | DB layer + Doctrine ORM | Done | 4 |
| P15 | Schema migration + multi-provider | Done | 6 |
| P16 | Typed facades + constants + language | Done | 7 |
| P17 | Domain tier 1 | Done | 14 |
| P18 | Domain tier 2 | Done | 4 |
| P19 | Domain tier 3 | Done — 2 `Common` gaps remain | 12 |
| P20 | Domain tier 4 | Done | 10 |
| P21 | Admin controller migration | Done | 4 |
| P22 | Frontend controller migration | Done | 7 |
| P23 | Legacy deletion & cleanup | Done — later-audit gaps all closed | 123 |
| P24 | Post-P23 remediation & hardening | In progress — see Epoch F | 646 |
| P25 | WS layer modernization — typed internals + PSR-7 lifecycle | Mostly done — Stage 1/2 landed, Stage 3 tests/docs partial; see Epoch G | ~50 |
| P26 | Admin fragment surface — UI-facing WS methods off the envelope | Done — the WS layer no longer exists at all; every admin UI surface already renders via Latte pages/fragments, not a JSON/XML envelope | ~15 |
| P27 | Public API v1 (REST + OpenAPI 3.2 + tus) — WS deleted here | Done — 134 `Controller\Api\*` files, 88 registered `/api/v1` routes, full tus 1.0.0 chunked-upload protocol (6 dedicated controllers), RFC 9457 problem+json errors, hand-authored OpenAPI 3.2 spec (88 operations/11 domains) with a `redocly lint` CI gate + Gesso runtime contract enforcement, a generated TypeScript client, REST-body `Content-Type` validation (SEC-39), and an opt-in `Idempotency-Key` replay store (SEC-65); see Epoch G | ~151 |
| P28 | Security hardening | Not started | 0 |
| P29 | Plugin / Theme contracts + bundled extensions | In progress — P29.6 unstarted | 22 |
| P30 | Layer decoupling + repository restructure | Not started — web-root half done | 1 |
| P31 | Smarty → Latte template migration | Done | 80 |
| P32 | Latte lint/format tooling | Done — enforcement is P44 | 11 |
| P33 | Latte idiomatic modernization | Done — all 8 sub-items | 8 |
| P34 | Event system rewrite | Not started | 0 |
| P35 | Browserslist decision + IE back-compat removal | Not started | 0 |
| P36 | Asset-pipeline foundation (ViteManifest) | Not started | 0 |
| P37 | Typed page-data exposure (PHP half) | Not started | 0 |
| P38 | Inline JS extraction | Not started | 0 |
| P39 | Inline CSS extraction | Not started | 0 |
| P40 | Typed view objects + `Template` split | Not started | 0 |
| P41 | Shell-last rendering + `PageState` split | Not started | 0 |
| P42 | Typed contributions + plugin-owned routes | Not started | 0 |
| P43 | Escaping campaign | Not started | 0 |
| P44 | Latte lint/format enforcement | Not started | 0 |
| P45 | JS → TS mechanical conversion | Not started | 0 |
| P46 | `getPageData<T>()` typing + `any` reduction | Not started | 0 |
| P47 | Refactor TS into modules | Not started | 0 |
| P48 | Remove jQuery | Not started | 0 |
| P49 | Lit component catalog (conditional on P48) | Not started | 0 |
| P50 | TS modernization | Not started | 0 |
| P51 | CSS architecture modernization | Not started — Tailwind call due before P40 | 0 |
| P52 | Picture pipeline (new feature) | Not started | 0 |
| P53 | Dark mode (new feature) | Not started | 0 |
| P54 | Real quality gates | Not started | 0 |

Two adjacent, non-phase-numbered tracks, both not started:

- **FrankenPHP worker mode** (SEC-60, a P7 gap) — `docker/Caddyfile` is
  still plain `php_server`, with no `worker` block.
- **Legacy import tool** (`bin/piwigo import:legacy`) — no
  `import:legacy` or `ImportLegacy` reference exists anywhere. This is
  T2 adoption tooling, not a cuttable rider.

## Conventions

- **Kind**: REPLAY (a reference implementation exists on `16.x-rewrite`,
  reproduce it) vs. GREENFIELD (net-new, needs its own design step
  first).
- **Tier**: T1 Core-parity (required to match `16.x-rewrite` behavior),
  T2 Modernization (clear-ROI infra/quality), T3 Stretch (cuttable
  without blocking a release).
- **Working rule**: no change lands unless all CI gates pass on a clean
  checkout — CI, not a local worktree, is the source of truth for
  "green." Tool baselines ratchet; issue counts only go down. A later
  "resolve N failures" commit is a smell, not a milestone.
- **Additive-only foundation**: P0–P1 install tooling and record
  baselines without modifying first-party code; the first code-modifying
  pass is gated on the P2 regression harness being green against
  pristine `origin/16.x`.
- **Reference branch**: `16.x-rewrite` (`../piwigo16-rewrite`) is a
  read-only design target. Reproduce behavior; never `git checkout` or
  cherry-pick from it.

## Phase detail

Current tool and system state lives in `docs/REFERENCE.md` and is not
duplicated here. This section records what each phase delivered and what
is still open.

### Epoch A — Foundation (P0–P4)

**P0 — PHP tooling + baselines.** Pest and plugins, pcov, ECS, PHPStan,
Psalm, Rector, Deptrac (config deferred to P6), ComposerRequireChecker
and ComposerUnused, PHPBench, roave/security-advisories — additive only.
Baselines recorded, not yet gated. ECS and Rector became code-modifying
passes later; Psalm's history is in P5.

**P1 — Frontend tooling + baselines.** bun, Vite, TypeScript, ESLint,
Stylelint, Vitest, knip, size-limit, commitlint, Lighthouse CI and
`web-vitals`. `web-vitals` was installed but never wired to an endpoint;
closed with `build/vitals.ts` + `VitalsController` + route, log-only.

**P2 — Test harness.** Env split (`.env.test`, `X-Piwigo-Env: test`),
fixture DB (`tests/Fixtures/piwigo-17.0.sql`), Pest Browser E2E and WS
Contract suites.

**P3 — CI pipeline.** `ci.yml` job layout, matrix, caching; actionlint,
commitlint, SBOM and OSV jobs, OpenSSF Scorecard. 32 jobs today.

**P4 — Containerization + runtime image.** Multi-stage Dockerfile
(FrankenPHP plus Apache-fallback targets), Compose, Helm chart,
`/health` and `/ready`, restore drills, SEC-01 web-root deny rules
across all three server targets.

### Epoch B — Composer/Rector/PHPStan + PSR-4 (P5–P6)

**P5 — Composer + Rector + PHPStan.** The largest phase by commit count.
Whole-codebase ECS `--fix`; PHPStan bleeding-edge rules applied
file-by-file across the legacy tree; vendored third-party library
replacement per the native-platform-first policy (PHPMailer → Symfony
Mailer, Emogrifier → `pelago/emogrifier`, phpqrcode → `endroid/qr-code`,
vendored Smarty → `smarty/smarty`, phpass → native `password_hash()`,
`mdetect.php` dropped with no replacement).

*Rector* is fully configured today: `withPhpSets(php85: true)` and
`withPreparedSets(typeDeclarations: true, instanceOf: true)` are both
active, plus `withImportNames()` and `withParallel()`. Both rule sets
were applied tree-wide (`c49a00014d`, `0bfc324f59`). Still narrower than
the reference implementation's set (no `withComposerBased`, no explicit
`SetList::TYPE_DECLARATION`, no strict-types or dead-tag rules), and the
`rector` CI job stays `continue-on-error: true`.

*Psalm* had a long history: gating paused here when its global-function
resolution failed against the still-non-namespaced legacy tree
(investigated properly — cache staleness and parallel-worker races ruled
out; concluded a real tool limitation at this codebase's shape). Dropped
as a dependency 2026-08-07 over a Pest 5 conflict, then reinstated
2026-08-11 (`4118adbb85`, pinned to `7.x-dev` because the latest tagged
release caps `sebastian/diff` below Pest 5's floor) after fixing
`psalm.xml`'s drifted paths and patching a real `7.x-dev` crash via
`composer-patches` (`StatementsAnalyzer` reads two properties it never
declares). Live dependency today, still non-gating — no CI job, no
composer script.

#### PHP language features not yet adopted

Every 7.0–8.3 feature is either heavily used or correctly inapplicable.
Real remaining candidates:

- **Multi-catch (7.1)** — `Http\HttpClientService.php:245-247`: two
  adjacent catches both `return null;`. The only real candidate; every
  other adjacent-catch site has genuinely different per-type handling or
  a deliberate rethrow-vs-swallow split that must stay separate
  (`Controller\ImageDerivativeController`'s `ResponseReadyException`
  rethrow past a broader `Exception` catch is security-critical — a
  private album's derivative was once served to an anonymous request
  when that ordering broke).
- **`json_validate()` (8.3)** — unaudited. Any `json_decode($x) !== null`
  used only for validity is a direct replacement.
- **`array_find`/`array_any`/`array_all`/`array_find_key` (8.4)** —
  unaudited. `foreach`+`break` and `array_filter()`+count-check patterns
  across the domain services are the target.
- **Native `#[\Deprecated]` (8.4)** — not currently needed (zero shims
  remain), but the right default if a transitional shim is ever needed
  again.
- **`array_first()`/`array_last()` (8.5)** — unaudited.
  `reset()`/`end()`/`$arr[0]`/`$arr[count($arr) - 1]` are the target.
- **`#[\NoDiscard]` (8.5)** — unaudited. Methods returning a validation
  result or a success flag a caller could silently ignore.
- **Pipe operator (8.5)** — 34 call sites with 3+ levels of nested calls
  found as a candidate pool, not individually read.

Property hooks and asymmetric visibility (8.4) are **done**, not a
candidate: `Config\CurrentConfig` declares every key as
`public private(set) TYPE $name` (5225 lines down to 2626, real
boilerplate removed), call sites were converted project-wide
(`e6bdedf369`), and `ConfigService::confUpdateParam()`'s external write
path uses `ReflectionProperty::setValue()` against the
asymmetric-visibility property rather than a setter.

**Open question — device detection.** `Core\DeviceHelper::getDevice()`
has a single writer, unconditionally sets `'desktop'` on every new
session, and no User-Agent parsing exists anywhere; the only path to the
mobile theme is an explicit `?mobile=1`. Its own comment says this is
deliberate ("the v17 responsive CSS removes the need for a separate
mobile theme via device detection"). The reference implementation kept
`mobiledetect/mobiledetectlib` and built a real
`Http\DeviceDetectionService`. Nothing records whether that approach was
deliberately rejected in favor of responsive CSS, or whether the comment
is unvalidated rationale nobody reversed.

**P6 — PSR-4 namespace migration.** Extracted every first-party class and
interface declaration out of `include/` and `admin/include/` procedural
files into `src/Piwigo/` under the `Piwigo\` prefix — 66 classes across
33 origin files. Extraction and namespacing only: no renaming to modern
casing, no DI, no behavior changes beyond what the move forced.
Established the 6-layer Deptrac model (L0Data → L4Integration, with an
L2a/L2b domain split), enumerated per-namespace rather than by
catch-all regex so a later phase adding a namespace must deliberately
choose its layer.

Deptrac 4.6.2 silently breaks ruleset resolution when a layer name
contains a hyphen — the original `L0-Data`-style names made every legal
cross-layer dependency misreport as a violation. Fixed by dropping
hyphens from every layer name.

### Epoch C — Kernel & HTTP foundation (P7–P12)

**P7 — Kernel + boot skeleton.** `Kernel`, `CommonBootstrap`,
`public/index.php`, fast paths.

**Open — SEC-60, worker mode.** The FrankenPHP worker loop was never
implemented: classic per-request execution on the FrankenPHP binary, not
true worker mode. Originally deferred past P23 on the reasoning that
bootstrap-chain replacement changes what state needs resetting; P23 is
long done and this has not been picked back up. The related `reset()`
arch-test coverage *was* closed (31 classes today, up from 13).

**P8 — DI container.** `Container`, `config/container.php`, PHP-DI
autowire-by-default.

**P9 — PSR-15 middleware + routing.** Originally a 7-stage pipeline
(`ExceptionHandler`, `SecurityHeaders`, `Session`, `ServerTiming`,
`Sentry`, `Routing`, `ControllerInvoker`); grew to 13 stages under
workstream C3 Phase 1 (see below) — `ConfigBootstrap`,
`PluginBootstrap`, `Admin\LoadedPlugins`, `UserResolution`, `Language`
and `FinalizeBridge` now sit between `Sentry` and `Routing`, per
`RequestPipeline::DEFAULT_MIDDLEWARE`'s own current list. Routes, an
extensible `SecurityHeadersMiddleware`, cross-server SEC-01 deny rules.
SEC-11/SEC-12 were closed here: `CsrfService` used `hash_hmac('md5', …)`
plus `===` long after the identical pattern was fixed in the sibling
`AuthService`/`EphemeralKeyService`; it now uses `sha256` and
`hash_equals()`. (SEC-12's own claim of "closed here" held for
`CsrfService` itself but not for the WS layer's independent copy of the
same check — see the SEC-12 checklist row below.)

**Open question — pipeline composition.** The reference implementation's
own pipeline (in its `Core\Kernel.php`) has eight stages:
`SecurityHeaders`, `ExceptionHandler`, `Session`, `Auth`, `Filter`,
`Csrf`, `Routing`, `ControllerInvoker`. This is not "one stage missing"
but a different composition: the reference has `Auth`/`Filter`/`Csrf` as
real pipeline stages that this fork has no equivalent class for at all,
while this fork adds `ServerTiming`/`Sentry` that the reference lacks.
Whether auth, CSRF and filter checks moved into services and controllers
deliberately or by omission is not established anywhere. SEC-42 ("CSRF
middleware: remove the `/admin*` exemption") implies no such middleware
yet exists to have an exemption from.

**P10 — Observability.** Monolog channels, Server-Timing,
OpenTelemetry-first (OTLP → Sentry/Tempo/Jaeger). Greenfield.

**P11 — Cache + session + messenger + `opcache.preload`.**
`symfony/cache` pools, session handler, Messenger, preload list. The
named-pool design (`config`, `permissions`, `category_tree`, `tag_cloud`,
`rate_limiter`, `general`, each with its own TTL) was initially never
built — `CacheFactory` produced one generic pool with no real consumers.
Closed with `CachePools`; `rate_limiter` stays unbuilt as genuine P28
scope. Messenger itself is real and wired (`config/messenger.php`, five
`Piwigo\Job\*` classes plus handlers).

**Open — a failed job is invisible and unmanageable.** Nothing anywhere
queries the `messenger_messages` transport table. If a
`SendNotificationEmailJob`, `GenerateDerivativeJob`, `BatchUploadJob`,
`ReindexImagesJob` or `RegenerateAllDerivativesJob` fails, there is no
way to see it, retry it, or purge it. The reference implementation has
`Job\MessengerRepository`/`Job\FailedJob` backing an admin batch-manager
queue dashboard; building the equivalent repository plus a small admin
view is the fix.

**P12 — CLI tool + backup/restore + graceful shutdown.** `bin/piwigo`,
`BackupService`, `ShutdownHandler`/SIGTERM cleanup, PHPBench. All four
`maintenance:*` commands (`orphan-tags`, `purge-history`,
`purge-sessions`, `repair-db`) were planned but initially unbuilt; all
four are real now, `repair-db` last because its backing logic lived in a
legacy file P23 still had to absorb.

### Epoch D — Config/DB/language (P13–P16)

**P13 — Config service.** 277-entry `SCHEMA`, `ConfigLoader`, typed
accessors. The `$conf` → `Config` migration had stalled at 72 files
still reading `global $conf`, not from an incomplete migration but
because `Config::` accessors were provably unsynced with DB-persisted
values: `ConfigService::loadConfFromDb()` wrote into the legacy `$conf`
global and never into `Config::$data`. That was the root cause of a real
shipped bug (`CsrfService` reading an empty `secret_key`). Fixed by
making the DB write paths update the live config object too, and
finished by Track A below — zero `global $conf` reads today.

The class names in that story have all changed since: `ConfigDb` was
merged into `Piwigo\Config\ConfigService`, and `Config::override()` and
`::delete()` no longer exist — the typed-`CurrentConfig` refactor
replaced them with reflection-based property writes. The underlying fix
holds under the new names.

**Open — `#[Required]`/`#[Sensitive]` are read but never called.** Both
are still empty attribute classes, but each now has a genuine
reflection-based reader: `ConfigLoader::validateRequired()` throws
`MissingRequiredConfigException` for a missing `#[Required]` property,
and `CurrentConfig::dumpForLog()` returns every property with
`#[Sensitive]` ones replaced by `str_repeat('*', 8)`. Neither is called
from anywhere. The remaining task is wiring those two calls into boot and
the error handler, not building them. Auditing which other properties
should carry `#[Sensitive]` (mail and API credentials) is also open;
today only `secretKey` and `smtpPassword` do.

**P14 — DB layer + Doctrine ORM.** The "repositories as real
`EntityRepository` subclasses from day one" design was initially followed
only for `ConfigRepository`; every domain repository built in P17–P21
used `AbstractRepository` + `Tables::` (DBAL) instead. That was migrated
under P24 and is finished: `Db/AbstractRepository.php` no longer exists
and nothing extends it.

38 domain repositories today, split two ways:

<!-- markdownlint-disable-next-line MD013 -->
<!-- doc-drift-check: cmd='find src/Piwigo -iname "*Repository.php" | wc -l' expect="38" -->

- **23 extend `Doctrine\ORM\EntityRepository`** — not Symfony's
  `ServiceEntityRepository`, which stays unused because this codebase
  does not run on the Symfony framework or DoctrineBundle.
- **15 extend nothing**, holding `EntityManagerInterface` by constructor
  injection: `Permission`, `Auth`, `Auth\ApiKey`, `Auth\Password`,
  `Metadata`, `Permalink`, `Admin\Maintenance\Db`,
  `Admin\Extensions\Extension`, `Calendar`, `Search`, `Section`,
  `Notification`, `Mail\Recipient`, `Category`, `Users\User`. Each
  touches tables *other* repositories own, reaching them via DQL for
  simple writes or plain DBAL for reads and dynamic fragments, never
  claiming ownership of a table itself. `SearchRepository`'s docblock
  states the rationale: query, column and operator combinations that vary
  per caller "has no DQL representation."

*Doctrine Migrations history*, which explains a confusing artifact: the
decision was reversed on 2026-07-24, before any real install existed, in
favor of a static hand-maintained `install/piwigo_structure-mysql.sql`;
`doctrine/migrations` was briefly not a dependency at all. Migrations
were reinstated for real during the pgsql-support pass. See "Migration
path" below for the mechanism today.

**P15 — Schema migration + multi-provider.** InnoDB and utf8mb4
uniformly, 7 new tables, FK constraints, `audit_log` (SEC-57). The cache
tables originally got engine and charset only, with type normalization
skipped; `user_cache` and `user_cache_categories` were later dropped
entirely once every consumer moved onto TTL cache pools, and
`history_summary` got its own type fix (`summary_id` AUTO_INCREMENT PK).

**P16 — Typed facades + constants retirement + language.** `Paths`,
`CurrentUser` and `PageState` facades, 52 `define()` constants retired,
`.po` migration, ICU MessageFormat pluralization. `src/Piwigo/Template/`
had zero dedicated Unit coverage (only indirect Browser exercise); all
eight classes with real logic have real `tests/Unit/Template/` coverage
now.

### Epoch E — Service layer (P17–P23)

**P17–P20 — Domain tiers 1–4.** ~35 domain namespaces migrated in
dependency order, each tier depending only on the ones before it. **Tier
1** URL, Cookie, Session, HTML, Storage, Csrf, Permalink, Site, Feed.
**Tier 2** Mail, Filter, Users, Auth, Tag, Comment, Rate, Group, Caddie,
History, Activity. **Tier 3** Category, Search, Image, Calendar,
Notification, Metadata, Telemetry, Validation, Common. **Tier 4** Page
renderers, Menu, PluginConfig, Section, Job. Each domain's legacy
`include/` file was deleted immediately after its migration, not batched
to the end.

Two naming notes that look like gaps and are not: Cookie was built as
`Piwigo\Auth\CookieService` rather than a standalone namespace, and the
User namespace is `Users`, plural.

**Open — two `Common` gaps.** `src/Piwigo/Common/` is real: 19
`ValueObject/` classes, 3 `Enum/`, 2 `Dto/`, built from `063fd2ae30`
onward. Two originally-named items were never built: no `AbsPath`/
`RelPath` path-value-object layer exists anywhere, and no centralized
`Privacy` enum exists (only `Users\UserStatus`, which stays
domain-local by design).

**P21 — Admin controller migration.** "62 admin pages" was never a target
count of services to build — it is the `origin/16.x` raw `admin/*.php`
file count being replaced. `config/admin_pages.php` maps 37 page slugs to
`AdminSubControllerInterface` services, matching the 37 classes that
implement the interface.

<!-- markdownlint-disable-next-line MD013 -->
<!-- doc-drift-check: cmd='grep -rl "implements AdminSubControllerInterface" src/Piwigo --include="*.php" | wc -l' expect="37" -->

Dispatch is `Bootstrap\AdminDispatcher::dispatch()`, built decomposed
from the start: the reference implementation's god-classes
(`MaintenanceController`, `MiscController`, `BatchManagerController`)
were never reproduced as monoliths here, and the same rule was applied to
admin PEM services.

**P22 — Frontend controller migration.** Scoped as 21 controllers; 19
were built. The two absences are both deliberate consequences of design
decisions, verified against branch-scoped history (not `git log --all`,
which mixes in `16.x-rewrite`'s unrelated history):

- `Install` was never meant to be a controller. `public/install.php`
  stays a special unrouted entry point — it must work before any DB or
  config exists, so it cannot go through the DI-resolved router — backed
  by `Bootstrap\InstallBootstrap` + `Admin\Install\InstallWizard`.
- `Upgrade`/`UpgradeFeed` were never built on any branch of this fork.
  Consistent with the clean-fork stance and the later deletion of the
  entire `DbPatch`/`VersionUpgrade` chain: there is no upgrade mechanism
  left for them to drive.

`GalleryController` initially only relocated
`include/section_init.inc.php`'s `include()` call into the controller —
the ~450 lines of SQL logic that belonged there (`$page['items']`,
favorites, next/prev navigation) was never absorbed. Folded into P23's
Gallery/Picture absorption batch.

**P23 — Legacy deletion & cleanup.** `include/` and `admin/` are fully
deleted as directories, all `$GLOBALS` and static-bridge globals are
retired, the legacy `Tables`/`AbstractRepository` DBAL layer is gone, and
the event-dispatch, `l10n()` and URL free-function bridges are retargeted
onto real classes. Zero `global $x` statements, zero live `$GLOBALS`
reads, zero bare legacy free-function calls in `src/Piwigo/` — each
guarded by a zero-tolerance Arch test.

<!-- markdownlint-disable-next-line MD013 -->
<!-- doc-drift-check: cmd='grep -rE "^[[:space:]]*global[[:space:]]" src --include="*.php" | wc -l' expect="0" -->

Four documented divergences from the original P23 plan:

- `include/` kept a 4-file bootstrap seam through P23's own batches,
  since SEC-60 needs `define()`s to stay out of `src/Piwigo/`. Closed
  later anyway — the seam collapsed into
  `Piwigo\Bootstrap\RequestBootstrap` during Track A7.
- Root entry points (`admin.php`, `picture.php`) were kept as thin shells
  rather than collapsed into one front controller; this fork keeps
  Piwigo's original URL surface. They have since moved into `public/` as
  part of web-root isolation.
- The `$GLOBALS` retirement bullets were audited and deliberately not
  executed in P23: the plan's premise (zero callers remain after
  `include/` deletion) did not hold, since ~230 `src/` files had real
  live `global $x` contracts preserved verbatim by the migration
  discipline. Tracked as Track A under P24 instead.
- `Tables.php` and `AbstractRepository` were kept pending the post-P23
  ORM remediation, now done.

Gaps that a 2026-07-13 full P0–P22 audit found in P23's own manifest are
all closed: 43 column-type migrations (mechanism changed with the
Migrations reversal; four columns' `serialize()` leaks fixed end-to-end,
plus a fifth, `activity.details`, found the same way); the typed
DTO/Projection pattern; per-namespace Unit coverage, 11 of 11; `CachePools`
wiring; `die()`/`exit()` elimination in the image-processing paths;
`reset()` arch-test coverage; `maintenance:repair-db`; install/upgrade
legacy constants plus a real `PWG_CHARSET` bug; and a repo-wide legacy
sweep round 2.

**Partially closed — the request-lifecycle architecture (workstream
C3).** 11 real `die()`/`exit()` call sites remain project-wide, across 9
files (`tests/Arch/StructuralTest.php`'s own count-based allowlist is the
live, machine-checked source of truth for this number). One is correct
by design (`Core\ShutdownHandler`'s `exit(143)`, the conventional
128+SIGTERM code). The `header()`+`echo`+`exit()` / `: never`-return
contract question this workstream exists to answer is no longer
"designed in outline only" — Phases 0 and 1 landed real code:

- **Phase 0 (done).** `Http\MiddlewarePipeline::handle()` now catches
  `Http\ResponseReadyException` at every nesting level, not just the
  innermost middleware — a real, previously-untested gap where a
  short-circuit thrown by an outer-ish middleware would have been logged
  as an unhandled error, Sentry-reported, and answered with a generic
  500 instead of the real response, silently losing security headers
  and the `Server-Timing` header along the way.
- **Phase 1 (done).** `Bootstrap\RequestBootstrap::connect()` (~180
  lines) is deleted outright and the first half of `finalize()` (~220
  lines) decomposed into 6 real PSR-15 middleware
  (`Http\Middleware\ConfigBootstrapMiddleware`/`SessionMiddleware`/
  `PluginBootstrapMiddleware`, `Admin\LoadedPluginsMiddleware`,
  `Bootstrap\UserResolutionMiddleware`, `Http\Middleware\
  LanguageMiddleware`) plus a `Bootstrap\FinalizeBridgeMiddleware`
  bridging into `finalize()`'s still-Template-dependent remainder,
  wired into `RequestPipeline::DEFAULT_MIDDLEWARE` (P9, above).
  `bootEntryPoint()` shrinks to just `configure()` +
  `InstallationFlag::mark()`. Caught and fixed along the way: `public/
  admin.php` never called `RequestPipeline::handle()` at all (a
  pre-existing bypass — see item 1 below, now closed) and would have
  silently lost the entire admin panel's DB/config/session/plugin/user/
  language bootstrap once `bootEntryPoint()` stopped doing that work
  directly; fixed with a new `RequestPipeline::runBootstrapPhase()`
  entry point `admin.php` calls explicitly.
- **Phase 2 (not started, gated).** The still-legacy theme/`Template`
  construction remainder of `finalize()` needs P40/P41's own `Renderer`/
  typed-view-object shape to land first — building middleware around the
  current `Template` class would mean redoing the work once that class
  is deleted.
- **Phase 3 (not started, investigation only).** Whether `Admin\
  AdminShell`/`admin.php` become real `ControllerInterface`s routed
  through the unified pipeline, or stay a deliberately separate
  dispatcher, is not yet decided.

**Open question — site-local config overrides.** A real bug was found and
fixed on 2026-07-21 (`338217f48`): nothing in `src/Piwigo/` ever read a
site's local config override file on a real request, silently ignoring
any non-DB-credential key (`order_by_custom`, `data_location`,
`guest_id`) a site had customized. The fix
(`LocalConfigOverrides::read()`) was deleted three days later
(`feede75c9`) as part of a much larger deliberate redesign.
`ConfigLoader::applyDefaults()` and `applyEnvOverrides()` are genuine
no-op bodies today, and the only surviving local-file mechanism is
`Piwigo\Config\DeploymentPolicy`, sourced from a differently-formatted
`local/config/config.php` and explicitly scoped to security-boundary
settings that its own docblock says "never overlaps with CurrentConfig
(DB)." Whether arbitrary site-local overrides of ordinary settings are
meant to be reachable some other way now, or whether this is an
unintentional regression, is not recorded anywhere.

### Epoch F — Post-P23 remediation & hardening (P24)

**In progress.** This formalizes the `(p24)` commit-tag convention as
this file's real P24, rather than leaving a status-table row diverging
from the tags. What landed under `(p24)`, plus the `(sql)`, `(di)`,
`(lang)` and `(p27)` work that is the same effort in substance, is the
post-P23 remediation several phases above promised. These tracks were
tracked in their own planning documents at the time
(`legacy-coupling-retirement.md`, `gap-closure-p0-p23.md`); folded in
here so there is one record.

#### DBAL → ORM migration

The P14 remediation. Every domain repository moved off
`AbstractRepository` + `Tables::` onto real Doctrine `EntityRepository`
plus attribute-mapped entities, or onto a directly injected
`EntityManagerInterface`. `SectionRepository` was last (`c4125eeb43`), at
which point `Db/AbstractRepository.php` was deleted outright.

The real finding was the shared Doctrine identity map serving stale data
after bulk or raw writes outside the ORM, needing `HINT_REFRESH` for
reads or `clear()` after bulk operations. Hit twice while converting
repositories, then found to be repo-wide: a dedicated audit
(`cb956266b`) found **33 call sites across 13 files** where
Controller/`Ws`/Admin classes bypass their domain repository and write
via `BatchWriter` or raw `executeStatement()` against a table an entity
now maps.

This needed a new accessor, not a per-call-site fix.
`Piwigo\Db\EntityManagerFactory::build()` is not memoized — it always
constructs a fresh `EntityManager`, so clearing a locally built instance
protects nothing. The only genuinely shared identity map lives on the DI
container's `EntityManagerInterface` singleton, reachable only through
`Kernel::container()`, itself arch-test-restricted to `Bootstrap/`.
`Bootstrap\InfrastructureAccessor::entityManager()` was added so
L4Integration classes can legally reach and clear that shared instance.

Two sites were deliberately left unfixed, not missed:
`InstallWizard.php`'s pre-seed writes (the container's entity manager
would wrap a connection built from stale pre-seed credentials — clearing
it would be risky and pointless) and
`UserService::checkAndSaveUserInfos()` (its only real caller builds a
fully isolated factory/repository chain with no container involvement, so
there is no shared identity map in that path).

#### Track A — `$GLOBALS`/static-bridge retirement

Batches A1–A8, smallest and lowest-risk first. `$template` →
`Piwigo\Template\CurrentTemplate`; `$lang` → `Lang::t()` plus new bulk
accessors; `$user` → `CurrentUser::get()`; `$conf` → the config service;
`$page` → `PageState` (nine sub-batches, the one global without a
complete existing target — real design work, not a mechanical retarget);
then ~25 smaller globals (`$my_base_url`, `$logger`, `$mysqli`,
`$prefixeTable`, `$filter`, `$pwg_loaded_plugins` and more); then
collapsing `include/common.inc.php`'s raw seeding into real object
construction (A7) and deleting the `attachGlobals()` bridge shape (A8).

Two real production bugs surfaced here rather than being mere retargets:
`CurrentUser::get()` had never actually worked for real users — it only
ever seeded a guest placeholder — and the config sync bug described under
P13. A8 is partial by documented judgment: the method *names* were kept
as the per-request seeding entry point on `CurrentUser`, `PageState` and
`Lang`, since nothing needs a `$GLOBALS` bridge anymore but a seeding
call still runs once per request.

#### Track B — event dispatch retarget

Free-function elimination landed first: `add_event_handler()`,
`trigger_change()` and `trigger_notify()` deleted, all 240 call sites
retargeted onto the dispatcher directly. Then the actual point — typed
event objects replacing bare-string-keyed dispatch — across 12 domain
batches. 157 event classes today.

<!-- markdownlint-disable-next-line MD013 -->
<!-- doc-drift-check: cmd='find src -path "*/Event/*.php" | wc -l' expect="157" -->

`triggerChange()`/`triggerNotify()` were originally kept as "permanent"
for `'trigger'`, their own internal meta-notification channel, then
deleted outright once it turned out nothing had ever registered a handler
against `'trigger'` in the first place. A token-aware arch test now
enforces zero string-keyed dispatch call sites at all, with no
exception.

**Superseded by P34.** P29.11 recommended keeping the hand-rolled
`EventDispatcher` and closing its gaps in place rather than swapping to
Symfony's, on the reasoning that `addEventHandler()`'s string-keyed
legacy handlers, `includePath`-based lazy inclusion and
`callablesEqual()`'s closure-identity dedup are Piwigo-specific mechanics
Symfony does not provide, so adopting it would mean rebuilding all of
that on top of rather than instead of Symfony's class. **P34 reverses
that**, because it deletes those three mechanics rather than preserving
them — none has a production caller. The gaps P29.11 did close stand:
PSR-14 conformance, descending priority order matching Symfony's
convention, and `StoppableEventInterface` support.

One P29.11 finding is still open and unrelated to the swap: three of 23
registrations in `RequestBootstrap.php` eagerly construct a one-off
service regardless of whether the event ever fires. That is a call-site
problem, not a dispatcher-class limitation.

#### Track C — `l10n()`/`get_root_url()` retarget

`Lang/functions.php`, `Url/functions.php`, `Category/functions.php` and
`Http/functions.php` all deleted; `composer.json` has zero
`autoload.files` entries left. Real finding: `Piwigo\PluginConfig` had to
split across two Deptrac layers mid-migration, because a free-function
call creates no Deptrac dependency edge but the direct class reference
this migration introduces does — 14 real L1/L2a callers of
`EventDispatcher` were invisible to Deptrac until then. Same class of
finding as P6's hyphen bug: suspect the tool's model of the code, not
just the code.

#### Sweeps and stabilization

- **Repo-wide legacy sweep round 2** (2026-07-18/19). Six `global` sites
  outside `src/Piwigo/` fixed; `Ws/PwgImages.php`'s five raw `die()` JSON
  calls retargeted onto its typed error path, fixing a real latent bug
  (the old `die()` always emitted JSON even when the client asked for
  `format=rest`); `LegacyRenderCapture`'s void-renderer pattern converted
  to return-string. The DbPatch/VersionUpgrade bound-parameter work in
  this sweep is moot: the entire subsystem (153 files) was deleted the
  next day (`8224f23a3`) as contradicting the project's own clean-fork
  design — it had been ported over mechanically before anyone caught the
  conflict.
- **Test coverage** (`9f5198bfe`, 2026-07-26). Closed a 70-class
  zero-coverage gap found via combined Unit+Arch+Integration+Contract+
  Browser measurement. 220 of 539 classes were only invisible because
  Contract and Browser coverage was not measurable before that session's
  pcov work. Combined line coverage went from 10.6%/15.6% siloed to
  65.07%.
- **Coverage-gap Wave 1** (2026-07-27/28). The tail after the above.
  Real bugs found: a metadata-sync bug, four in the `Ws` domain, and
  `PasswordController` silently discarding lockout and expiry errors.
- **Full-suite stabilization** (2026-07-28 to 07-31). Browser, Contract
  and combined Unit+Integration suites made green by root-causing every
  failure from a full re-run rather than re-running until it passed. Real
  bugs: a picture-derivative cookie test assuming an IPv4-only client, a
  watermark write-access test leaking permanent debris, added_by and
  multi-filter search tests sensitive to ambient config drift.
- **Mutation-testing sweep** (2026-08-01, batches 20–31+). Real
  previously-undiagnosed bugs that PHPStan and ECS did not catch:
  `SessionRepository::gc()` and `LoungeMaintenance::needsEmptying()` read
  the real wall clock instead of `Env::now()`;
  `UrlService::getAbsoluteRootUrl()` appended a stray trailing colon;
  `Inflector_fr.php` had corrupted `é` regex literals; `Translator`'s
  day/month reassembly had an untested gap;
  `SentryBootstrap::resolveOptions()` needed extracting to fix a
  risky-test SDK leak; a deprecated `trigger_error(E_USER_ERROR)` became
  `ErrorCollector::recordFatal()`; `UploadService` leaked its process
  umask. Confirmed-equivalent mutants were documented as such rather than
  suppressed.
- **SQL bound-parameter sweep** (2026-08-01, 16 commits). Remaining
  raw-string SQL splices converted to bound parameters across the Image,
  Category, Tag, Search, Comment, History, Notification, Group, Activity,
  Config and Calendar domains and the `Db/` layer itself (a new
  `SqlCondition` carrier introduced for it). Found and fixed several
  real live SQL injections, not just style: three in `ImageRepository`,
  one in Comment, one across History/Notification, a plugin-hook
  injection in `TagRepository::findIdByWhereFragment()` (SEC-19), and
  `CategoryRepository::countByVisible()` in a same-day re-audit.

#### Singleton/DI elimination campaign

2026-08-02 to 08-06, 74 commits, **complete**. A 10-phase campaign that
grew a close-out Phase 12 with six lettered sub-phases once a handful of
"permanent exception" shims turned out to be closeable after all.
Converted every static-singleton and service-locator anti-pattern — ~55
classes across three shapes, plus the entire `Piwigo\Ws\*` static-dispatch
layer as its own phase — to constructor-injected DI.

The motivation was not style: SEC-60 worker-mode request isolation needs
no process-persistent static state, and FrankenPHP worker mode is a
committed future direction incompatible with it as-is.

Mechanism: a transitional `@deprecated`-tagged static shim per class for
callers not yet converted, tracked via a shrinking arch-test allow-list,
with a hard "zero shims remain" gate. That gate is met for real — a
strict `^\s*\*\s*@deprecated\b` search returns zero hits. Phases 0–11
converted every class with production callers; Phase 12 closed the last
dozen shims that had zero production callers left but real test debt,
from 4 test sites (`CurrentLogger::getStatic()`) up to 1,382
(`CurrentConfig::current()`, the campaign's final shim). `Kernel` never
carried such a tag and was never a shim — it is the one principled DI
root every system needs.

#### Typed template contexts

2026-08-08, 87 `feat(template)` commits, **complete**. Every file calling
`Template::assign()` with a real key converted to a
`final readonly class FooPageContext implements TemplatePageContext` plus
a single `assignContext()` call. Zero `Template::assign()` calls with a
string or array key remain in `src/Piwigo`; 130 context classes ship
today.

<!-- markdownlint-disable-next-line MD013 -->
<!-- doc-drift-check: cmd='grep -rl "implements TemplatePageContext" src/Piwigo --include="*.php" | wc -l' expect="130" -->

Four sites are correctly excluded and carry an explicit comment saying so
— the assign *key* itself is caller-chosen or per-instance-mutable, not a
fixed page var (`Calendar/CalendarBase.php`, `Category/CategoryService.php`,
`Html/HtmlService.php`, `Admin/Tabsheet.php`).

**Open — 18 correlated-nullable violations.** The campaign's own
convention (one flat `readonly` class, every var an independently
nullable property) is wrong whenever fields are actually correlated:
always null together, always set together, or representing a real
alternative. A flat bag then lets a caller construct combinations that
can never happen. A systematic audit of all 32 shipped classes with 2+
nullable constructor properties — the necessary precondition; the rest
are excluded by construction — found 18 real violations, none fixed.
One, `UpdatesPwgPageContext`, has a docblock actively claiming "every
optional field here is genuinely optional," which is false. Per-file fix
specs live in that campaign's own plan file.

#### Type correctness + mixed elimination

The original plan's P27, merged here. 89 `(p27)` commits continuing the
mixed-elimination sweep from the `(p25)` work: replacing ambient `$_POST`
and `$_GET` reads across dozens of controllers with typed Request DTOs,
one per action or param cluster. Real bugs fixed along the way: a SQL
injection via a raw `cat_id` superglobal read in the cat-modify renderer,
a stale `$_POST` dead write in `AlbumSubController`, comment
rejection-reason tracking moved off `$_POST` onto `PageState`. A SEC-40
arch test locking in "no raw superglobal reads outside a Request DTO" is
live and passing in `tests/Arch/StructuralTest.php` — the literal string
"SEC-40" no longer appears in the test's own text, persisting only in
~78 Request DTO docblocks and this file. Naming drift, not a functional
gap.

Not complete: no "0 remaining" claim has been verified.

**On measuring `mixed`.** The raw token count keeps climbing with new
code and is the wrong metric on its own, because a large legitimate
by-design residual will always exist: DBAL scalar-narrowing closures,
`ValueObject::tryFrom()`, `Db/Type::convert*()` vendor-dictated
signatures, the WS RPC layer's protocol params, PSR-3 logger context. The
useful question is the per-module Projection-wiring gap — a repository or
service method still declaring `array<string, mixed>` where a sibling
typed Projection already exists.

"Projection" is a real repo-wide directory convention
(`{Domain}/Projection/`), present in 40+ namespaces. The gap is uneven
and, where it was previously described as "not started," largely wrong:
`Image/Projection/` has 17 classes all referenced from
`ImageRepository.php`; `Users/Projection/` has 10, seven referenced from
`UserRepository.php`; `Category` is substantially fixed already.
`Comment`, `Tag` and `Group` are near-resolved at three occurrences each.
`Admin`, `Core` and `Controller` have real `Projection/` directories
(51/2/15 files) but have not been audited for this specific gap. `Ws` is
the one domain with no `Projection/` directory at all.

**Open — `SearchRepository`'s count is unchanged at 17** and genuinely
unexplained. Worth a single-file look.

**Open — superglobal access beyond the request superglobals.** Three
pockets remain, same "typed accessor over raw offset access" discipline:

1. **Prerequisite: wire `admin.php` through the shared PSR-7 pipeline —
   bootstrap half done (C3 Phase 1), routing half still open (C3 Phase
   3).** `public/index.php` calls `RequestPipeline::handle()` in full;
   `public/admin.php` calls `RequestBootstrap::bootEntryPoint()`, then
   `RequestPipeline::runBootstrapPhase()` (new in C3 Phase 1 — runs the
   same DB/config/session/plugin/user/language bootstrap middleware
   `index.php` gets, without which admin.php lost that work entirely once
   `bootEntryPoint()` stopped doing it directly), then still instantiates
   `AdminShell` directly rather than reaching `RoutingMiddleware`/
   `ControllerInvokerMiddleware` — `AdminShell::run()`'s own docblock still
   says so. Independently corroborated by SEC-42, from the CSRF angle.
   Everything the full wiring needs already exists (`Http\
   ControllerInterface`, the `ResponseReadyException` pattern, the
   string-returning `Template::parse()`/`PageTail::renderToString()`
   siblings), so this is scoped and tractable — C3 Phase 3's own job, not
   a rediscovery of its scope.
2. **`$_SESSION`/`$_SERVER`/`$_COOKIE`** — 168/68/18 direct-access sites
   across 40/30/8 files outside any designated typed home. The
   `$_SESSION` count has grown, not shrunk, since first scoped.
   `Session\Session` exists as a designated growth point and is threaded
   through `SessionMiddleware`, but is still genuinely empty (37 lines);
   `Auth\CookieService` is already the right home for `$_COOKIE`;
   `Core\CurrentServerRequest` would be a new sibling in the `Current*`
   family. **Open question**: `SessionService` already has 15 named
   accessors for a different, non-overlapping slice of `$_SESSION` (the
   `filter_*`/`device`/`mobile_theme` family), so whether new keys become
   more named accessors or populate the empty `Session` VO is unresolved.
   One slice has a ready design: `page_infos`/`page_errors`/`message_tags`
   — the cross-request flash-message pair `HtmlService` still
   reads and writes as raw `$_SESSION` keys — map directly onto the
   reference implementation's `Session\FlashBag`
   (`add()`/`consume()`/`peek()`). Worth porting rather than
   redesigning.
3. **Raw DBAL row arrays** consumed by string-keyed offset. Real, but its
   prior sizing document no longer exists, so it needs a fresh count
   before scoping. Roughly two-thirds of the files doing this are page
   renderers, controllers and `Ws/*` classes running raw SQL inline, not
   repositories at all.

The WS `$params` pocket that used to be item 4 here is **closed** — see
Epoch G.

#### Table-prefix + `Tables::` removal

2026-08-09/10, 62 commits, **complete**. `PIWIGO_DB_PREFIX` (upstream's
`$prefixeTable`, defaulting to `piwigo_`) was removed entirely, not just
made non-configurable: tables get their bare names unconditionally. The
prefix existed to let multiple installs share one database — a real
constraint on 2000s shared hosting, no longer the mainstream shape, and
this project's own Compose and Helm configs already assume one dedicated
database per install.

This supersedes P14's original claim that `AbstractRepository` + `Tables::`
"had become the real, working, tested pattern." `Tables::`'s 39 static
methods were deleted outright rather than simplified: their opacity to
static analysis (`'SELECT * FROM ' . Tables::images()` is not a literal
string) defeated `staabm/phpstan-dba` and IDE SQL tooling, both of which
only recognize a literal SQL string. Every call site — 129 in
`src/Piwigo`, ~1,540 in `tests/` — was inlined from a verified mapping,
then the class was deleted along with `TablePrefixListener` and every
`PIWIGO_DB_PREFIX` reference across the install flow, backup manifests,
deploy config, migrations and CLI tooling.

<!-- markdownlint-disable-next-line MD013 -->
<!-- doc-drift-check: cmd='grep -rn "Tables::" src --include="*.php" | wc -l' expect="0" -->

Real bugs found along the way, not just renames: `UniqueExecLock`,
`PwgImages` and `UploadService` used `db_prefix` as hash *entropy* for
`GET_LOCK()` lock names — server-wide MySQL lock names need a per-install
disambiguator — switched to the database name, still unique per install
and more reliably so; a leftover `bmDbPrefix()` call site that survived
an earlier cleanup; a column-name-case mismatch (`'IP'` vs. the Postgres
migration's lowercase `ip`); an int/bool mismatch (`'validated' => 1` vs.
the real boolean column).

Once every table name became a literal, phpstan-dba could resolve exact
live-schema column types for the first time, surfacing ~178 new real
findings — all fixed, mostly now-provably-dead `is_numeric()`/`(int)`
wrappers around schema-known-int reads — plus four confirmed tool
limitations, each root-caused against the live schema and now narrowly
suppressed in `phpstan.neon`, replacing a blanket `dba.keyValue`
suppression that existed only because `Tables::` made every table name
non-literal: synthetic jsonb-placeholder sample values, MySQL-dialect SQL
validated against the one Postgres connection the tool has, Postgres
`::text` casts misparsed as named bind placeholders, and
dynamically-shaped `tearDown()` snapshot-restore arrays.

A full non-parallel `composer test:integration` run — apparently the
first in a while — also surfaced two pre-existing Kernel-boot isolation
bugs and five stale hardcoded fixture-photo hashes, all fixed, all
unrelated to the prefix removal but found only because this pass finally
exercised the suite end to end.

Also closed here: `ecs.php` had excluded `tests/` since the project's
first commit, originally with a "deferred to P5" comment that a later
sweep stripped while leaving the exclusion in place, silently making it
permanent. `e44aeb8f2a` removed the exclusion and ran the full fixer set
across all 882 test files, with no new fixer exclusions added.

#### Still open from a 2026-07-25 full-sweep review

All 470 `src/Piwigo/` files were read in full, not sampled. Most findings
are resolved by later work above. Genuinely still open:

- **Six `@todo` markers.** Five were triaged by that review:
  `DerivativeParams::isIdentity()`'s docblock and `HtmlService`'s four
  "nice display if $template loaded" markers. The sixth,
  `DerivativeImage::build():242`, was never named by it. The
  TODO/FIXME/HACK/XXX literal-marker convention the review separately
  counted at 50 is fully gone, as a side-effect of some later cleanup
  pass not tracked anywhere.

<!-- markdownlint-disable-next-line MD013 -->
<!-- doc-drift-check: cmd='grep -rn "@todo" src --include="*.php" | wc -l' expect="6" -->

### Epoch G — WS layer & REST (P25–P27)

**P25 — WS layer modernization (typed internals + PSR-7 lifecycle).**
Mostly done. A review found the WS layer serves two audiences through one
surface — 71 of 94 methods are `requiresAuth: true` admin plumbing
exposed as a public contract, and 61 of 93 are reachable from the
first-party UI while 32 are the real machine surface (auth, browse, the
9-method upload protocol, image metadata, favorites) — which is why the
old plan's single P25 ("REST resource layer + OpenAPI, WS removed") had
sat at "not started" as a monolith. Split into three phases, execution
order: **P25** modernizes the legacy layer's internals without changing
one wire byte (verified by the Contract suite passing *unmodified*
throughout); **P26** (not started) moves the ~15 UI-facing methods off
the JSON/XML envelope onto server-rendered fragments; **P27** (not
started) ships `/api/v1` — REST + OpenAPI 3.1 + a generated typed TS
client, tus replacing the 9-method chunk-upload protocol — and deletes
the entire `Ws/` layer (`Server`, all 94 handlers, the encoders,
`NamedArray`/`NamedStruct`, `public/ws.php`) in the same phase. The 39
`Ws*Test` Contract tests get rewritten against the new surface there,
not before.

**Ship-first: seven security findings, all fixed 2026-08-15**, found
during the P25 review and landed ahead of the modernization work itself
— four contradicted this file's own SEC checklist (see SEC-10/SEC-12/
SEC-16 below, corrected):

1. Global `addslashes()` on every superglobal, every request — data
   corruption repo-wide, contradicted the SEC-10 "Done" claim. Fixed.
2. API-key session laundered into an unrestricted session via
   `pwg.images.uploadAsync` — `UserBootstrap.php` unconditionally
   overwrote a correctly-marked `ws_session_login_api_key` connection
   type with `'pwg.images.uploadAsync'`, making all 8
   `apiKeyForbiddenMethods` callable. Fixed.
3. `pwg.images.addChunk` wrote a file from unvalidated `original_sum`/
   `type` params — an authenticated arbitrary-directory write. Fixed.
4. `pwg.themes.performAction` bypassed the UI's own `isWebmaster()` gate
   its `plugins` sibling already had. Fixed.
5. CSRF was optional on three mutating methods (the token doubled as an
   unrelated "allow HTML" flag), one of them GET-reachable. Fixed by
   separating the two concerns.
6. WS compared CSRF tokens with `!==`, not `hash_equals()` — contradicted
   the SEC-12 "Done (confirmed)" claim. Fixed.
7. Four `exec()` sites escaped nothing — contradicted the SEC-16
   "Done (confirmed — 46 call sites)" claim. Fixed.

**Stage 1 — typed internals.** The registration god-method
(`WsDefaultMethods::register()`, 1,322 lines) split into 13 per-domain
registrars. The recursive `$server->invoke()` dispatch pattern deleted
(12 call sites now call the sibling handler directly), removing all 9
copies of a duplicated `narrowGetListResult()` helper. `WsParamType`/
`WsParamFlag` moved `Piwigo\Core` → `Piwigo\Ws`; `WsError` became a
backed enum (89 call sites updated, wire-visible codes unchanged).
`WsHelper` (an event listener + CSRF guard + SQL builder + URL builder +
tree builder in one class) split into 7 single-purpose classes.
`Server`'s reference-parameter setters and per-request `uksort()` on its
method list removed. **Two items were investigated and deliberately
descoped, not attempted**: making `WsAction`'s `array $params` typed end
to end, and building `Ws\Request\*` DTOs from the PSR-7 request — both
would touch only code P27 deletes outright, so building either now is
pure throwaway work against P27's own timeline. **Three items are real
but incomplete**: dropping the `Server $server` parameter from every
handler signature (blocked — `Images/UploadAsyncHandler` still threads
it into `UploadService`, resolved by a Stage 2 item below, not yet
revisited); a typed sort-spec replacement for `WsHelper`'s raw-SQL
`stdImageSqlOrder()`; retyping `Server::$methods` (blocked on a decision
for its 2 remaining legacy-callback registrations).

**Stage 2 — lifecycle (deleted the `exit()`s).** `Server::run()` returns
a real `ResponseInterface`; `WsController` returns it directly, deleting
its own `exit()`. `WsErrorResponse` is now a pure value object — the
status-code decision moved into `Server::sendResponse()`, the only place
building a real response. `UploadService` throws a new
`UnsupportedMediaTypeException` instead of reaching into a `Server` to
`sendResponse()`+`exit`. `UserBootstrap`'s 2 `exit()` sites (invalid
api_key, failed `uploadAsync` login) now throw
`Http\ResponseReadyException` — closing a real, previously-documented
test gap (both branches were "genuinely unsafe to invoke" per
`UserBootstrapTest.php`'s own prior docblock) and setting up workstream
C3 Phase 1's `Bootstrap\UserResolutionMiddleware` to wrap this logic as
real middleware. `connected_with` (5 string literals + one
variable-valued write) became a typed `Core\ConnectedWith` enum.
`WsInitializer`'s memoized `Server` had a real worker-mode-readiness
leak — `responseFormat`/`responseEncoder` were computed once per
`Server` lifetime instead of once per request — fixed. `pwg.extensions.
checkUpdates`'s session-keyed cache moved onto a real PSR-6 cache pool.
Two wire-compatible bugs fixed: `json_encode()` could silently emit an
empty body on failure (now `JSON_THROW_ON_ERROR`), and
`categoriesFlatlistToTree()` could hit an undefined array key for a
category whose parent was filtered out of scope.

**Stage 3 — tests and docs.** The Contract suite (604 tests, all 94
methods covered) stayed unmodified throughout, the real gate for a
phase that changes no wire byte. Still open: 39 of 94 handlers lack a
dedicated Unit test (`Ws/Users/` has none at all); 18 dangling
`{@see \Piwigo\Ws\...}`-style docblock references to deleted
god-classes.

**Landed 2026-08-13/14, before the Stage 1–3 work above** (under
`feat(P25/G19)` and `feat(P19.n)` tags — kept here for the historical
record, since these were the foundation Stage 1/2 built on):

- **94 `WsAction` handlers** replace the `*Endpoints` god-classes. Each
  is a constructor-injected class with an `__invoke()`, not a
  string-callback entry in a registration array.
- **78 `WsParams` DTOs** replace `array $params` indexed by string key —
  the "zero typed accessors" gap this file used to list under P24's
  superglobal pockets.
- **94 `MethodDefinition` registrations** with typed `ParamDefinition`
  entries replace the legacy callback-array shape.
- `Ws/Images.php`, the last god-class, was deleted in `6573f728c2`. The
  namespace is now 204 files across per-domain subdirectories.

**Superseded**: the entire `Piwigo\Ws\*` namespace and `tests/Contract/`
described throughout this P25 history section were deleted outright by
P27 (the WS layer deletion, its own section below) — nothing here is
verifiable against the current tree anymore; kept as historical record
only, no doc-drift-check marker.

One registration deliberately stayed on the legacy `addMethod()` path:
`pwg.activity.downloadLog` pointed at an undefined function and fataled
if invoked. It was permanently dead and covered by its own regression
test in `tests/Contract/WsHistoryTest.php`; both are gone now along with
the rest of `Ws/*` and `tests/Contract/`.

Follow-up fixes in the same window: `#[\Override]` added to all 94
handlers, `Server` no longer resolving handlers via `Kernel::container()`,
CSRF checks consolidated into `WsHelper::checkSecurityToken()`, and
`WsHelper::stdImageSqlFilterCriteria()` returning an error response
instead of `exit()`ing.

Older names in this area are stale everywhere they appear outside this
file: the `Pwg` prefix was dropped repo-wide on 2026-08-11
(`PwgTags.php` → `Tags.php`, `PwgError` → `WsErrorResponse`, `PwgServer`
→ `Server`).

**P26/P27 — real status, audited 2026-08-18** (the table above previously
read "Not started | 0" for both, contradicted by the P25 "Superseded"
note two paragraphs up and by this whole codebase's own Browser suite,
which drives `/api/v1` throughout). Verified directly against the tree,
not inferred:

- **P26 is done.** Its stated goal — moving the ~15 UI-facing WS methods
  off the JSON/XML envelope onto server-rendered fragments — is
  satisfied by construction: the WS layer is gone entirely, and every
  admin UI surface already renders through a `*PageRenderer`/
  `*SubController` onto a Latte template, never a WS envelope.
- **P27 is mostly done.** `find src/Piwigo/Controller/Api -name "*.php"`
  → 134 files; `RouteDefinitions.php` registers 88 real `/api/v1`
  routes, covering categories, comments, extensions, groups, history,
  images (including a filtered-search endpoint), session/preferences/
  API keys/favorites/caddie, tags, uploads, and users. Uploads get a
  full tus 1.0.0 chunked-upload protocol (`Uploads/TusUpload*`, 6
  dedicated controllers), replacing the old 9-method WS chunk-upload
  protocol as originally planned. Every response is RFC 9457
  `application/problem+json` on error (`Http\Middleware\
  ApiErrorMiddleware` for routing-level 404/405,
  `Http\Middleware\ExceptionHandlerMiddleware` app-wide for uncaught
  exceptions — confirmed SEC-36/SEC-37, see below). `Http\AdminGuard`
  (401 vs 403) is explicitly injected into 69 of the 134 controllers
  (SEC-38, confirmed).
- **The OpenAPI 3.2 spec now exists** (`openapi/openapi.yaml` +
  `openapi/paths/*.yaml`, 88 operations across 11 domains, hand-authored
  from real controller/DTO/service source, not generated from runtime
  behavior) — closes the gap this section used to note. `bun run
  lint:openapi` (Redocly) gates spec validity in CI; a structural test
  (`tests/Unit/OpenApi/SpecStructureTest.php`) asserts path/operationId/
  security/schema presence via `openapiphp/openapi`'s `Reader` (never
  its own `->validate()`, which hard-rejects `3.2.0`); `studio-design/
  gesso` enforces the spec against real PSR-7 request/response pairs at
  runtime (`tests/Browser/Api/*`) and tracks per-operation coverage via
  `OpenApiCoverageExtension`. The 3 controllers that previously lacked a
  typed `*Input` DTO (`ImageSetMd5sumController`,
  `ImageMissingDerivativesController`,
  `ImageFilteredSearchCreateController`) now all have one.
- **P27's remaining 3 gaps are now closed.** A generated TypeScript
  client exists (`openapi/client/schema.d.ts` via `openapi-typescript`,
  `openapi/client/index.ts`'s thin `openapi-fetch` wrapper), with a CI
  step regenerating and diffing the committed schema to catch drift.
  `Http\JsonBody::decode()` now validates the `Content-Type` media type
  is `application/json` for any non-empty body, returning the same 415
  shape `TusUploadPatchController`'s own tus-protocol check already used
  (SEC-39, done). `Http\Middleware\ApiIdempotencyMiddleware` adds an
  opt-in `Idempotency-Key` replay store (SEC-65, done) — scoped to
  `/api/v1` mutating methods, excluding the tus controllers, backed by a
  new `IdempotencyCachePool`; a repeated key with the same body replays
  the stored response, a different body gets 400. Concurrent-duplicate-
  request locking is a deliberate, documented non-goal (would need real
  cross-process locking, out of scope for a replay cache).

### Epoch H — Security (P28)

**P28 — Security hardening.** Not started: WebAuthn/passkeys, OIDC SSO,
nonce-based CSP, COOP/COEP, CSP reporting. Depends on P24. The clearest
concrete marker that it has not begun is `rate_limiter`, the one P11
cache pool deliberately left unbuilt as P28 scope.

One pattern to borrow when CSP work is scoped: the reference
implementation has `composer lint:no-inline-scripts` →
`tools/check-no-executable-inline-scripts.php`, scanning `.latte` and
`.php` for `<script>` tags missing `type=` or carrying one outside a
CSP3-safe allow-list. It exists there *because of* its own
`script-src 'self'` hardening. It was deliberately not pulled into P32
just because it is Latte-shaped — reference repos are a pattern source,
not a scope target.

### Epoch I — Plugins/Layering/Repo-restructure (P29–P30)

**P29 — Plugin / Theme contracts + bundled extensions.** In progress.
P29.0–P29.5 and P29.7–P29.15 are done and landed on this branch. **P29.6,
porting the 7 bundled extensions onto the new contract, has not started
here**: `plugins/` holds nothing but `index.php` and `trash`, and
`themes/` holds no bundled third-party theme. Ownership of P29.6 moved to
this session on 2026-08-14. Do not treat P29 as done until it lands.

Sub-item tags: P29.0 EventDispatcher PSR-14 conformance +
`Piwigo\Listener\*`; P29.1/P29.2 `ExtensionInterface` + manifests + JSON
schemas + the `ExtensionContext` SDK; P29.3 `PluginRegistry`/
`ThemeRegistry`; P29.4 request-time boot retarget; P29.5 admin lifecycle
retarget + page-renderer listing merge; P29.7 SEC-49, `eval_visible`
replaced by a typed `CheckMenuLinkVisibility` event; P29.8 dead
`PluginMaintain`/`ThemeMaintain`/`insertPlugin()` removal; P29.9
`AppInfo::VERSION` bump to `17.0.0` plus a local PEM mirror; P29.10 full
legacy plugin/theme file-support retirement (unplanned, prompted by a
live `elegant` theme rendering bug); P29.11 stoppable events + priority
direction; P29.12 `deleteSetting()`; P29.13 `mail()`; P29.14
`users()`/`themes()` facades; P29.15 the settings-page rendering
mechanism.

*Survey grounding.* Every real plugin in `../piwigo16-plugins` (~400
extensions) and every real theme in `../piwigo16-themes` (113 files) was
read, to ground the design in actual usage rather than guessing. 162
distinct legacy plugin events exist in the wild; 11 of the top 12 by
frequency already have a shipped 1:1 typed event class — the sole
exception, `ws_add_methods` (#7 by frequency), briefly became a dead
end: at the time this was written it was believed to be just another
typed event (`Ws/Event/WsAddMethods.php`), but the entire legacy
`Piwigo\Ws\*` namespace that class lived in was later deleted outright by
P27, replaced with typed `/api/v1` REST routes with nothing replacing
the plugin-extensibility half. Closed by P29.6:
`PluginConfig\ApiRouteProviderInterface`, a manifest-declared
(`hasApiRoutes: true`) capability mirroring `SettingsPageInterface`'s
own shape, lets an active plugin register real routes under a reserved
`/api/v1/plugin-routes/{id}/...` prefix from `Http\Middleware\
RoutingMiddleware::process()` — see that interface's own docblock.
Every other mapped event did not need inventing — only a real
registration surface wired onto dispatch machinery that already
existed.

*Reference-implementation verdict.* `../piwigo16-rewrite` actually built
`PluginInterface`/`ThemeInterface`/`PluginRegistry`/`ThemeRegistry` —
read in full and traced call-site by call-site rather than taken at face
value. Real, reusable prior art for the JSON manifest shape and the
Doctrine-migrations-per-plugin design, but the interface design does not
hold up:

- Both interfaces were written in one commit and never touched again, and
  their own test helper's docblock admits "unused inside this repository
  — there are no in-tree plugins yet."
- `getId()`, `getVersion()`, `getName()`, `getParentId()`,
  `loadParentCss()`, `getAssetDir()` and `getLocalHeadTemplate()` have
  zero call sites across the whole codebase; every real consumer reads
  the same facts from the manifest DTO. Once those are dropped, the two
  interfaces reduce to an identical shape, overturning that reference's
  own "keep them separate" design.
- `boot(ContainerInterface $container)` hands a plugin the entire
  unrestricted app container, with no scoped binding, contradicting this
  fork's own precedent (`Admin/PluginMaintain.php` takes two narrow named
  collaborators).
- PSR-4 autoloading is parsed into the manifest but never wired.

*Design adopted instead.* One shared
`PluginConfig\ExtensionInterface` for plugins and themes; a narrow
`ExtensionContext` SDK object passed to `boot()` instead of the raw
container, its accessor list sized from a frequency survey of what real
plugins actually call; **no raw DB access at all** — every real
`pwg_query()` use case sampled maps onto existing typed repositories,
`ConfigService`, or ordinary Doctrine entities per plugin, and one real
plugin's own comment admits using raw SQL specifically "to bypass
permission checks," which is a concrete argument against ever exposing
it; core-data reads routed through new purpose-built read-only facades
rather than the existing 105-method `CategoryService`/`ImageService`
(most of whose methods take internal collaborators as parameters and many
of which are unrestricted mutations); and a separate shared "extensions"
`EntityManager` — not the core one, and not one per plugin — so a
metadata error in one plugin's entity cannot take down every other active
plugin's data access.

The JSON manifest format is kept from the reference design.
`opis/json-schema` and `composer/semver` are already resolved in
`composer.lock` as transitive dependencies, so nothing new has to be
introduced to validate manifests or compare versions.

**P30 — Layer decoupling + repository restructure.** The web-root
isolation half is done — `public/` is the real document root today. Layer
decoupling itself is not started. Deptrac reports 0 violations today, and
that number is now known to be a live ratchet, not just "no violations
having accumulated": a 2026-08-15 P25 review found it had actually
regressed to 16 real violations (`Config\ConfigService`/`CurrentConfig`
in L1Infrastructure depending on `Image\OrderBy` in L2aCoreDomain,
introduced by `7c281ee97c`, which left `OrderBy` unplaced in
`deptrac.yaml`) — fixed the same day, confirmed back to 0 by a live
re-run, not by trusting the prior claim.

The one commit tagged `chore(p32): delete doc/` is an unrelated narrow
cleanup that borrowed a pre-consolidation number for this same phase.

### Epoch J — Presentation, templating & extension surface (P31–P54)

Sequenced after every backend phase. Order within the epoch: the
completed Latte foundation, then refactor and modernization (same
behavior, different implementation), then new features, then a closing
gate.

The tree is 135 templates and 119,752 lines, of which **93,420 lines
(78%) are auto-generated `{varType}` boilerplate** — every template
carries the same 692-line block while referencing 11.5 distinct
variables on average. That is forced by `Template::$vars` being one
request-global bag, and P40 is what removes it.

<!-- markdownlint-disable-next-line MD013 -->
<!-- doc-drift-check: cmd='find themes template-extension -name "*.latte" | wc -l' expect="135" -->
<!-- markdownlint-disable-next-line MD013 -->
<!-- doc-drift-check: cmd='grep -rho "{varType" themes template-extension --include="*.latte" | wc -l' expect="93420" -->

#### Completed Latte foundation

**P31 — Smarty → Latte template migration.** Done, 80 commits. All 139
real templates at the time converted (P31.1–P31.6), then the Smarty
engine fully retired (P31.7): `Template.php` has no Smarty dependency at
all, the `smarty/smarty` package and its three patches are gone, and the
Smarty-reach-around arch test was retired because PHP's own private-method
visibility on `Template::assign()`/`append()` now enforces the same
invariant.

Scope was narrowed from the original plan: the "+ asset pipeline" clause
is split out to P36 and P52. Every `p31.x` commit is a `.tpl` → `.latte`
conversion or Smarty cleanup, nothing manifest-, combiner- or
image-format-related.

**P32 — Latte lint/format tooling.** Done. Both halves exist; **only
enforcement is missing, and that is P44's job**, not a gap here.

*Lint half*: `tools/latte-lint.php` + `composer lint:latte`, a thin
wrapper around Latte's own bundled `Latte\Tools\Linter` registering
`PiwigoExtension` so Piwigo filters do not false-positive as unknown. It
runs as a subprocess specifically because `Linter` is `final` and writes
warnings only to stderr through a private error handler. Plus
`bin/piwigo precompile:templates`, which calls `warmupCache()` on every
template so a syntax error is a build failure while warming the
production cache.

*Static analysis inside templates*: the reference implementation used
`efabrica/phpstan-latte` plus a locally patched copy. **Not ported.** A
deep upstream review found dead Nette-only machinery,
maintainer-confirmed performance overhead, and three real crashes in its
hand-rolled analysis loop. A Piwigo-native replacement was built instead:
`tools/phpstan/Latte/` + `bin/piwigo phpstan-latte:compile`, chained
inside `composer analyse:phpstan`. It compiles all 135 templates with
typed `@var` injection and shim-rewritten filter calls into
`_analysis/phpstan-latte/`, analysed by plain `phpstan analyse` with
errors mapped back to real `.latte` lines via an `errorFormatter.table!`
override. Two follow-up campaigns shrink its remaining scoped ignores:
context-docblock enrichment (~1,400 mixed-flow findings across the
context classes) and template-source modernization (~450 loose-`==` and
`empty()` findings).

*Format half*: no prior art existed even in the reference, so it is
genuinely new work. `tools/latte-prettier/` is a real Prettier plugin —
a hand-written recursive-descent parser producing a typed AST, printed
through Prettier's own `Doc` builders, the same architecture
`prettier-plugin-laravel-blade` uses. All 135 files parse, format, are
idempotent, and are AST-semantically equivalent to their source.
`.prettierignore`'s blanket directory excludes were replaced with precise
per-extension ones, because gitignore semantics cannot re-include a path
whose parent directory is already fully excluded.

The reformat was then reviewed manually, every file, line by line — the
automated checks compare old-parse against new-parse with the *same*
parser, so a systematic parser bug is invisible to them by construction.
That review found three more real bugs (a mismatched-closing-tag
cascade that flattened element nesting, pure-CSS templates having their
hand-formatting collapsed onto one line, and `{contentType text}` mail
templates losing meaningful leading whitespace), all fixed; full detail
is in `tools/latte-prettier/README.md`. The reformat itself landed in
`4862a0f579`.

Cross-file composition was verified concretely rather than by inspection:
the mail templates, which are joined by raw PHP string concatenation in
`MailService.php`, were rendered through the real Latte engine for all
three content-template combinations and the concatenated output validated
with a real HTML parser.

**P33 — Latte idiomatic modernization.** Done, all eight sub-items. A
content pass over the templates for idiomatic Latte constructs, cleaning
up Smarty-era patterns that survived P31's mechanical conversion, with
the same rendered output.

- **P33A** — `Feature::Dedent` and `Feature::ScopedLoopVariables` enabled
  on the reformatted tree.
- **P33B** — `{varType}` blocks generated from the live `VariableMap` via
  `composer lint:vartype:fix`, plus a drift check. Not a hand pass.
- **P33C** — n:if/n:foreach sweep, 451 conversions across 91 templates,
  AST-based. Four templates skipped for a real structural edge case.
- **P33D** — verification only: `{spaceless}`'s runtime whitespace
  collapse confirmed unaffected by the Dedent and n:attribute work,
  inspected directly against the golden-html baselines.
- **P33E** — `|noescape` classified across all 1009 sites by an AST walk
  cross-checked against a raw-text count. 11 provably-redundant sites
  removed (bare var, plain-HTML-text position, PHPStan type exactly
  `\Latte\Runtime\Html`, confirmed against
  `Template::assignVarFromTemplate()`'s hard contract, not just static
  inference) and 379 `{='key'|translate…|noescape}` sites collapsed to
  the new `{_…}` tag. Explicitly deferred, not dropped: ~234 sites where
  removal would be a real behavior change (pre-built HTML strings from
  PHP helpers, not `Html`-typed); 14 sites inside a literal
  `<script>`/`<style>` body, which is a different JS-string escape path
  and invisible to the parser's AST walk by design; and the broader
  ~2,380-site `|translate` rollout beyond the noescape overlap.
- **P33F** — native `{_ …}`/`{translate …}` tags added to
  `PiwigoExtension::getTags()`, wired to the existing mechanism.
- **P33G** — `Engine::setLocale()` wired from `Lang::currentUserLanguage()`,
  and all four `|number_format[:N]` sites converted to `|number[:N]`.
  Original research found only one; three more in `rating_user.latte`
  used a `:N`-argument form a substring grep missed. Verified by a unit
  test proving `fr_FR`'s ICU output genuinely diverges from
  `number_format()` (narrow no-break-space separator, round-half-to-even),
  not just "renders without crashing."
- **P33H** — dev-only Tracy debug bar. `tracy/tracy` is a real
  `require-dev` dependency now, previously an unresolved transitive
  reference. `Piwigo\Bootstrap\TracyBootstrap` mirrors `SentryBootstrap`'s
  no-op-unless-opted-in shape behind `PIWIGO_TRACY_ENABLED`.
  `Env::isTracyEnabled()` lives in `Piwigo\Core`, not on `TracyBootstrap`,
  because deptrac forbids `LatteEngine` (L3Presentation) from depending
  upward on `Bootstrap` (L4Integration). `LatteEngine` registers
  `TracyExtension` conditionally, since its constructor unconditionally
  touches `Debugger::getBar()`.

#### Refactor/modernization track — lands first

**P34 — Event system rewrite.** Independent of the rest of Epoch J: it
touches no template, asset or JS file, and can run immediately.

Today there are 157 event classes but only 21 with a production listener,
and the three features the bespoke dispatcher cites to justify its
existence have zero production callers. The rewrite:

1. **Mutable payloads.** Of the 138 `final readonly` event classes, drop
   `readonly` on the *filterable* fields only; context fields keep it.
   The 149 `dispatchChange` call sites read the value back off the event
   instead of relying on the returned instance. Behavior is identical
   under the existing dispatcher, so this verifies standalone.
2. **One verb.** `dispatchChange()`/`dispatchNotify()` collapse into
   PSR-14 `dispatch(object $event): object` across all 250 sites, and the
   runtime "handler must return an instance" guard goes.
3. **Symfony.** `symfony/event-dispatcher` replaces the 282-line bespoke
   dispatcher; `addTypedHandler()` maps onto
   `addListener($event::class, $callable, $priority)`. Keep the
   closure-based `subscribedEvents()` shape — Symfony's
   `EventSubscriberInterface` uses static method-name strings, which
   collide with this repo's PHPStan ban on variable method calls.
4. **Delete the dead API.** `EventHandler`, `addEventHandler()`,
   `removeEventHandler()`, `includePath`, `callablesEqual()`, and the
   ~700-line test file that is largely testing deleted features.
5. **Catalogue.** Rename `Loc*` to tense-consistent module names
   (`LocEndIndex` → `Gallery\Event\IndexRendered`), co-locate every event
   with its module, prune to evidence, add the **6 real core hooks that
   have no class** (`invalidate_user_cache` 7 wild uses,
   `get_categories_menu_sql_where` 6, `user_list_columns` 3,
   `after_render_user_list` 2, `get_high_url` 2, `add_elements` 2), and
   ship `docs/events-legacy-map.md` so the plugin-porting campaign stays
   greppable.

**The prune is per-event judgement, not a script**, and its derived list
goes up for review before anything is deleted: 27% of `loc_*` handlers do
non-contribution work, so a payload-less marker can still be
load-bearing.

**P35 — Browserslist decision + IE back-compat removal.** One phase, not
two — the removal is the decision's mechanical consequence. Commit a
`browserslist` config (none exists today, in neither `.browserslistrc`
nor `package.json`), setting Vite's build target and confirming
`tsconfig.json`'s `ES2022` lib target against it. Then remove what that
obsoletes: `themes/default/js/pngfix.js` (an IE6 PNG-alpha shim) and its
`<script>` reference, the IE conditional comments in `header.latte` and
`local_head.latte`, the four IE7-specific fontello stylesheets, and the
`-ms-filter`/`zoom:1`/`\9` rules across 11 files. Proven via
`composer test:visual`.

**P36 — Asset-pipeline foundation.** Retires `ScriptLoader`, `CssLoader`,
`FileCombiner`, `Combinable`, `Script` and `Css` (~1,038 lines — the
legacy PHP combiner that `vite.config.ts`'s own comment flags as still
driving everything except the `vitals` entry) for real `ViteManifest`
resolution against `dist/manifest.json`. No template content moves; this
only builds the delivery mechanism P38 and P39 need.

**P36 owns one decision that determines P41's shape.** Do templates keep
declaring their own assets mid-body (`{do combineScript(…)}` — 178 sites,
plus 87 `combineCss` and 80 `footerScript`, across 76 of 135 templates),
or do assets become view-declared per-page Vite entries?

- *Template-declared (status quo).* Runtime collection is unavoidable,
  because a `<head>` rendered before the body cannot see what the body
  registered. P41 must then render **shell-last** (content → layout).
- *View-declared.* No runtime collection at all, so `<head>` can render
  first and Latte's own `{layout}`/`{block}` inheritance becomes usable
  immediately.

Verified against Latte itself rather than assumed:
`Latte\Runtime\Template::render()` delegates to
`createTemplate($this->parentName, …)->render()` when `{layout}` is
present, so the layout's `<head>` genuinely precedes the child block.
Only 10 of 158 registered scripts are `load: 'header'` (119 footer, 29
async), which bounds the problem. Counter-consideration: 76 templates
register assets today and themes must keep that ability, so moving all of
it into PHP removes a real capability from theme authors.

P40 and P41 must therefore treat any `Assets` abstraction as a **thin
seam P36 deletes**, not a design worth polishing.

**P37 — Typed page-data exposure (PHP half).** One typed payload per
page, emitted as a JSON island, replacing the ad-hoc PHP → JS smuggling:
68 in-template `json_encode` uses, `PageState::$bodyData`/`BODY_DATA`,
and the string-into-JS-literal pattern the 210 `escapeJavascript` sites
represent. This has to exist *before* P38, or P38 must invent an interim
mechanism that P46 then replaces. It is also the PHP counterpart to P40's
typed view objects — the same typed source feeds the template and the
JSON island, so design the two together even though P40 lands later.

**P38 — Inline JS extraction.** Every `<script>` block in a template
moves to a plain `.js` file loaded through P36's manifest. Same behavior,
proven via `composer test:visual`. No TypeScript, no modularization, no
jQuery changes.

Inline JS is not only literal `<script>` blocks: 16 templates carry one,
but **80 `footerScript(` captures across 61 templates** carry the rest.
Critically, **all 210 `escapeJavascript` call sites are inside that
scope** — verified, none outside a `{capture}` or `<script>` region. Any
escaping or filter cleanup done before P38 is therefore discarded work,
which is why P38 and P39 must run ahead of P40–P43 and ahead of any
further template-content pass.

**P39 — Inline CSS extraction.** Every `<style>` block and `style="…"`
attribute moves to a real `.css` file: 20 templates with `<style>`, 243
`style="` attributes. Independent of P38 — different files, different
linter — so parallelizable with it. P39 also settles whether
`Template::htmlStyle()` (15 call sites, accumulating runtime inline CSS)
survives at all, or is superseded by real stylesheets plus the existing
`local/css/*-rules.css` mechanism. P41 should not carry it forward by
default.

**P40 — Typed view objects + `Template` split.** The largest single diff
in the epoch. Mitigate by converting one page-family at a time, after
proving the pattern end to end on a thin slice (`index.latte` + a new
`@layout.latte` + `GalleryController`) gated on golden-HTML and VR.

Per template: one `final readonly class XxxView` carrying
`#[Template('index.latte')]`, so the template header collapses to
`{templateType Piwigo\…\IndexView}`. This deletes all 93,420 `{varType}`
lines, `toArray()` from all 130 context classes, `Core\TemplatePageContext`,
and the `ALL_CAPS`/`U_`/`F_` naming — **474 of 781 mapped keys are
literally `'CAPS' => $this->camelCase`**. The 29 contexts with derived
values need a decision each, since those derivations are themselves
Smarty coercions (`? 1 : 0`, `->value`, `[$x]`). The 21 push-only classes
must **return** typed fragments for their caller to compose, and the 18
`getTemplateVars()` read-backs become real accessors.

Then split `Template` (1,370 lines, 36 public methods) into `Renderer`
(one method, `render(View): Html`), `TemplateLocator`, `ThemeChain` (a
typed `ThemeConf` replacing the Smarty `append(…, merge: true)`
parent-theme emulation), a thin `Assets` seam that P36 owns, a
contribution registry, and a trimmed `PiwigoExtension`. Delete
`TemplateAdapter` (`$pwg` — 0 template uses), the `defineDerivative`
Latte registration (0 template uses), and `Core\TemplateInterface`.
Remove the five `Kernel::container()` reach-arounds and
`CssLoader::getCss()`'s six parameters.

Delete the **template-extension feature** outright. It ships only samples
— four `.latte` files under `distributed/samples/`, an empty
`yoga/local/` — and has 6 real uses in the wild. Going with it:
`setExtent`/`setExtents`/`getExtent`, `TemplateExtentsRequest`,
`ExtendForTemplatesPageRenderer` (214 lines) with its context, admin page
and tab, the `extents_for_templates` config and sanitiser, 15
`getExtent()` template calls, and its unit, Browser and VR tests.

Delete the tooling this obsoletes: `tools/phpstan/Latte/VarTypeSyncer.php`,
`VarTypeSyncResult.php`, `Command/PhpStanLatteSyncVarTypeCommand.php`,
their two test files, and the `lint:vartype`/`lint:vartype:fix` scripts.
Collapse `VariableMapBuilder`, `TemplateCallSiteScanner`,
`TemplateCallSiteVisitor` and `ContextVariableExtractor` to "read the
declared `{templateType}`". Keep `LatteTemplateCompiler`. **Add a
round-trip check**: every template's `{templateType}` class must declare
that template back via `#[Template]`.

Depends on P36, P37, P38 and P39.

**P41 — Shell-last rendering + `PageState` split.** `header.latte` (834
lines) and `footer.latte` (744 lines) merge into `@layout.latte`; admin's
61 `assignVarFromTemplate('ADMIN_CONTENT', …)` calls become the same
composition. Deletes `Template::$output`, both `COMBINED_*` placeholders,
the `preg_match('#\n[ \t]*</head>#')` injection, and the
`pparse`/`flush`/`finalizeOutput`/`fetchOutput` quartet. `AdminShell` and
`InstallWizard` stop echoing and return strings; `flushPageMessages()`
moves ahead of the body render.

Splits `PageState` (25+ public mutable properties, 225 mutation sites) by
concern: a `PageMessages` collector, page chrome into `LayoutView`, debug
counters into `RequestMetrics`, loose domain facts back to their owners.

**Shape depends on P36's fork** — if assets become view-declared,
`{layout}`/`{block}` inheritance replaces shell-last composition
entirely.

**P42 — Typed contributions + plugin-owned routes.**

*The problem.* Core ships **two** mechanisms for one need, on the same
page: `Template::addIndexButton()`/`parseIndexButtons()` (a ranked
collector flushed into `PLUGIN_INDEX_BUTTONS` by an explicit controller
call right before render) and `Template::concat()` writing
`PLUGIN_INDEX_ACTIONS`. Two names, two shapes, one need — what happens
when each need is solved locally. The `addX()`/`parseX()` split is itself
a Smarty vestige: it exists because Smarty could only read what was
assigned before render, which Latte does not require.

*Why the obvious fix is not enough.* A string-keyed slot registry
unifies those two, but a field survey of the sibling repos shows the real
demand is an order of magnitude larger: **122 of 433 plugins (28%) use
`set_prefilter`, across 211 distinct callbacks** — and that demand
resolves into a *finite* list of kinds: admin form field ~32, picture
info row ~21, profile/register field ~15, auth buttons ~13, thumbnail
overlay ~9, picture action ~8, menu item ~6, and a short tail.

*The design.* Because the kinds are finite, contributions become **typed
value objects**, not string-keyed slots carrying raw HTML, so **the type
determines the destination** and there is no point name to pass. That
structurally removes the one risk a string-keyed registry carries — a
mistyped point name silently creating a disconnected point that never
renders. A wrong kind is a type error; a wrong target is an invalid enum
case. Multi-destination kinds take a typed enum target
(`AdminForm::PictureModify`); per-row cases take a typed field
(`themeId`), never a composed key. Ordering is a `Priority` enum.
Core and themes render every contribution, so themes can restyle them.
This absorbs `addIndexButton`, `addPictureButton`, `parseIndexButtons`,
`parsePictureButtons` and `concat('PLUGIN_INDEX_ACTIONS')`, and adds
`FieldOverride` and `FormProvider` kinds.

*Also in scope.* Prune the Latte API: 18 zero-use registrations, and
`math()` with its `eval()` — exactly 1 call site, becoming `{=abs(...)}`,
removing ~75 lines and the last `eval()` in the codebase. Migrate Smarty
duplicates onto Latte built-ins (`count` → `|length`, `date_format` →
`|date`, `nl2br` → `|breakLines`, `strip_tags` → `|striptags`, `join` →
`|implode`, `cat` → `~`), checking semantics per swap: Smarty's
`strip_tags` replaces a tag with a *space* and Latte's `|striptags` does
not. Rewrite the 48 `htmlOptions` and 6 `htmlRadios` call sites as
`{foreach}` loops. Emit **stable DOM hooks** (`data-image-id`,
`data-category-id`, stable form-control ids) — this alone retires ~12% of
historical `set_prefilter` demand, which exists only because core emits
nothing stable to hook onto.

*The rendering API.* `render(View $view): Html` becomes the single
rendering API application-wide, with `ExtensionContext::render(View): Html`
for plugins (the `myplugin:` prefix stays an internal loader detail), and
`SettingsPageInterface::handleSettingsRequest()` returning a `View`
instead of `void` plus `ADMIN_CONTENT`.

*Deliberately no escape hatch*: no loader-chain template override, no
block override, no rendered-output filter. Consistency and predictability
are worth more than flexibility here, and needing to extend core later is
acceptable. Plugin-owned routes are consequently **required, not
optional** — making `Bootstrap\RouteDefinitions` extensible is the only
remaining answer for page ownership (`tag_groups`,
`piwigo_masonry_grid`, `PWG_Stuffs`).

**P43 — Escaping campaign.** The residue after P38 removes the JS-context
cases and P40 turns rendered-sub-template vars into `Html`-typed
properties: the pre-escaped-URL population (`{$U_HOME|noescape}`,
`{$F_ACTION|noescape}`, `{$ROOT_URL|noescape}`), not the full 988. **Size
it after P40, not before.** Kept as its own phase so an escaping
regression stays bisectable from a structural one; gated by golden-HTML
and VR.

**P44 — Latte lint/format enforcement.** P32 built the tooling and gated
almost none of it: `composer lint:latte`, `composer precompile:templates`
and the `tools/latte-prettier/` formatter are invoked by neither
`.github/workflows/ci.yml` nor `lefthook.yml` — only
`composer analyse:phpstan` runs today, via the CI `phpstan` job and a
`lefthook` pre-push hook. Wire the survivors into CI and pre-commit.

Deliberately last in the refactor track: P42 changes `PiwigoExtension`'s
filter set and `lint:latte` registers that extension, so gating earlier
only churns the config. `lint:vartype` is **never** wired — P40 deletes
it along with the `{varType}` blocks it generates.

**P45 — JS → TS mechanical conversion.** `.js` → `.ts` renames, minimal
types to satisfy the existing strict `tsconfig.json`, real Vite entries
replacing the `noop` placeholder (the 68 entries `vite.config.ts` already
earmarks). Same code, same behavior. Vendored third-party files
(`jquery.js`/`.min.js`/`.cookie.js`, `themes/default/js/ui/**`,
`themes/default/js/plugins/**`, `jquery.geoip.js`) stay out of scope —
already ESLint-ignore-listed, decided in P48. Depends on P38.

**P46 — `getPageData<T>()` typing + `any` reduction (TS half).**
`getPageData<T>()` consumes P37's island; TypeScript `any` driven to zero
across P45's output. Real type-design work, not a mechanical rename.

**P47 — Refactor TS into modules.** Breaks up monolithic per-page scripts
into proper ES modules (shared utils, per-feature entry points), one Vite
entry per real page bundle.

**P48 — Remove jQuery.** An explicit per-surface decision, not a blanket
removal: first-party call sites (native DOM/fetch), the vendored bundle
itself (delete once nothing references it), `themes/default/js/ui/**` and
`themes/default/js/plugins/**` (selectize, jqtree — replace or keep
vendored per widget), `jquery.geoip.js`, and the installer's own separate
`jquery.packed.js` load, which is a third easy-to-miss surface with
thinner coverage (`composer test:install` only). `pngfix.js` is not in
scope — it is an IE shim, not a jQuery plugin, already removed in P35.

**P49 — Lit component catalog.** Conditional on P48's findings, and still
parity-only. Just for widgets P48 finds no reasonable vanilla replacement
for — tag autocomplete and tree picker are the likely candidates. Skipped
entirely if P48 turns up nothing that needs it.

**P50 — TS modernization.** An idiomatic pass over the now-modular,
jQuery-free, fully-typed codebase from P45–P49. Same behavior.

**P51 — CSS architecture modernization.** `@container` queries, `@layer`
cascade, Tailwind evaluated (`@source` scanning needs Latte templates,
already satisfied). Same visual output, proven via VR baselines. Depends
on P39, not on the JS track, so parallelizable with all of P45–P50.
Includes confirming that nothing in the vendored plugin RTL rules
(`selectize.dark.css`, `jqtree.css` — the only RTL handling anywhere in
this repo) regresses if P48 touched those files.

**The Tailwind *decision* is pulled forward, though the work stays
here.** If Tailwind is adopted it rewrites `class=` across all 135
templates, and P40/P41 restructure those same templates. Deciding late
means touching every template a third time, so the adopt/don't-adopt call
must be made **before P40 starts**.

#### New-feature track — lands last

**P52 — Picture pipeline.** `<picture>` AVIF/WebP variants plus ThumbHash
blur-up placeholders: new image formats and a new loading-placeholder UX.
Independent of the refactor track; kept last per the modernize-first
ordering rather than for a technical dependency. Soft-depends on P36 if
generated variants should be served through the Vite manifest.

**P53 — Dark mode.** A new user-facing capability (theme toggle,
`prefers-color-scheme`). Depends on P51 — it needs the modernized cascade
layers and custom properties to add a theme dimension onto cleanly.

#### Closing gate

**P54 — Real quality gates.** `lighthouserc.json` has no `assert` block
today and is collect-only; `.size-limit.json` has one 1 KB placeholder
budget, whose own name still cites a pre-renumbering phase. Wires real
Lighthouse perf, a11y and best-practices thresholds and real per-entry
`size-limit` budgets, and decides whether the risk register's claimed
"a11y gate" becomes a real automated check. Needs P35–P53's real bundles,
templates and features to measure against.

## Greenfield tracks (T3, cuttable — outside the P0–P54 backbone)

All entirely cuttable, never gating a backbone commit, dropped first on
overrun. None have started; each depends on backbone phases that have not
landed.

- **T3·WEB** — PWA, View Transitions, Speculation Rules, JSON-LD, SRI,
  resource hints. Depends on P36 (asset pipeline), P31/P33 (Latte
  templates) and P51 (CSS architecture).
- **T3·AI** — depends on P19 and P27.
- **T3·RIDERS** — CQRS, libvips/HEIC, vector/CLIP search, tus uploads,
  webhooks, Fibers, Mercure, passkeys, OIDC, soft delete. Each is hosted
  on its own backbone phase.

The **legacy import tool** (`bin/piwigo import:legacy`) is the one
non-cuttable exception in this group — T2 adoption tooling, not a rider.

## Execution approach for remaining phases

1. **Write tests first**, or in the same commit group.
2. Read the target state of the equivalent code on `16.x-rewrite`
   (`../piwigo16-rewrite`) — for reference only.
3. **Re-implement manually.** Nothing is git-pulled or cherry-picked from
   either branch. Self-contained files are re-created by hand; greenfield
   items are authored new.
4. `config/container.php` and `config/routes.php` grow incrementally with
   each phase, never reproduced from the reference in bulk.
5. Full gate suite after each commit group; fix before proceeding.
6. **Extend this file and `docs/REFERENCE.md`; do not create a new
   per-phase doc.** The original plan had each remaining phase spinning
   up its own file (`docs/FRONTEND.md`, `docs/API.md`, `docs/SECURITY.md`,
   `docs/PLUGINS.md`, `docs/EVENTS.md`, `docs/STRUCTURE.md`,
   `docs/AI.md`). That is superseded by this consolidation's whole
   premise: 18 drifting files reduced to 2. Add a section, not a file.

**Rollback rules.** Every commit must be green — fix before the next
commit, never accumulate broken state. Stuck mid-phase: revert to the
last green commit and re-approach, do not push through. A phase
materially exceeding its estimate: drop its T3 items first, and split the
phase only if T1/T2 alone is still oversized.

## Risk register

- **P40 is the largest single diff remaining.** Mitigated by the thin
  slice and by converting one page-family at a time; two rendering models
  coexist during the transition.
- **P42's no-escape-hatch decision means core must be extended for novel
  needs.** Accepted explicitly; the consequence already absorbed is
  plugin-owned routes.
- **P42's built-in filter swaps have real semantic differences.** Check
  each; golden-HTML catches the rest.
- **P36's fork can flip P41 entirely.** If assets become view-declared,
  shell-last composition may be unnecessary.
- **P34's catalogue prune is judgement, not a script.** Review the derived
  list before deleting anything.
- **P51's Tailwind decision is due before P40 starts.**
- **P29 breaks external extensions by design** — an accepted product
  decision, not an oversight. In-tree callers migrate in the same phase.
- **Skipping workstream C3 Phase 0 breaks Phase 1 silently, not
  loudly.** A bootstrap-phase middleware that short-circuits without
  Phase 0's `ResponseReadyException`-at-every-nesting-level fix would
  still "work" (the request completes) while quietly losing security
  headers and Server-Timing and spamming Sentry with false errors —
  exactly the kind of regression a happy-path test suite would not
  catch. Both landed together for this reason.
- **MySQL 9.x is a non-LTS line.** Pin the exact server version; hedge via
  the MariaDB/PostgreSQL provider matrix.

**On the "a11y gate."** There isn't one. No automated accessibility
tooling — `axe-core`, `pa11y`, or a Lighthouse `assert` block scoped to
the a11y category — exists anywhere in this repo. What actually ran
during P31's 139-template conversion was the VR baseline plus manual
per-template review, and both stay in place for whatever templates P38
and later still touch. Making it real is P54's call.

## MySQL infrastructure notes

**Open question — collation.** `utf8mb4_0900_ai_ci` was the originally
planned MySQL collation, for more accurate multilingual sort than
`utf8mb4_unicode_ci`. It appears nowhere in the live repo: all 39
`CREATE TABLE` statements in `install/piwigo_structure-mysql.sql`
explicitly declare `utf8mb4_unicode_ci`. No decision record explains the
reversal, and MariaDB's `utf8mb4_uca1400_ai_ci` equivalent was similarly
never adopted. Whether this was a deliberate undocumented simplification
— fewer moving parts across the provider matrix, since MariaDB has no
`_0900_` collation either way — or an oversight is not established. It
matters for any phase still touching schema: a new table following the
*original* instruction would be inconsistent with all 39 existing ones.

Other notes: MySQL 8.0+ has no `.frm` or query cache, and the
`symfony/cache` layer is the intentional replacement, not a gap.
`SET PERSIST` is available for a future admin maintenance page's tuning.
Replication terminology is `SOURCE`/`REPLICA` in any future documentation
or admin page that touches it.

## Migration path

Clean fork, no in-place upgrade from an existing Piwigo install. Doctrine
Migrations (`bin/piwigo migrations:migrate`) are the real, live mechanism
today, for both a fresh install and a version-to-version upgrade of an
existing v17 install.

`install/piwigo_structure-{mysql,mariadb,pgsql}.sql` are **generated,
human-reviewable snapshots** regenerated *from* migrations by
`bin/piwigo schema:dump` — not the install-time source of truth. They
look like the hand-maintained static schema that briefly replaced
migrations between 2026-07-24 and the pgsql-support pass, which is the
one thing to know before assuming they are authoritative.

Adopting from an existing pre-v17 install is meant to go through
`bin/piwigo import:legacy`, which is not built.

## Verification

The full gate list once every phase is done. Most already run in CI
per-commit; a few are aspirational until later phases land. See
`docs/REFERENCE.md`'s CI section for the current status of each.

```bash
vendor/bin/pest                             # unit, integration, browser, arch
vendor/bin/pest --mutate --min=60           # mutation score — not run in CI yet
vendor/bin/pest --type-coverage --min=95    # type coverage
vendor/bin/ecs --no-progress-bar            # style — 0 violations, blocking
composer analyse:phpstan                    # level 10, 0 errors — blocking
vendor/bin/rector --dry-run                 # non-blocking
vendor/bin/deptrac --no-progress            # 0 violations — blocking
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

`composer analyse:phpstan` chains `bin/piwigo phpstan-latte:compile`
ahead of PHPStan; a bare `vendor/bin/phpstan analyse` skips template
checking entirely.

**Deliberately not in this list**: `vendor/bin/psalm`, a real working
dependency again since 2026-08-11 but with no CI job and no composer
script; and `composer lint:latte`/`precompile:templates`, which exist and
work but are gated nowhere until P44.

**SEC traceability has no automated cross-check.** The original design
had every `SEC-NN` reachable from threat model → phase checklist →
manifest → `verified_by` test, enforced by a `tools/plan-lint` script
against `docs/plan/manifest.yaml`. Both were deleted in this
consolidation. The threat model and phase mapping survive below, but
nothing re-verifies that a `SEC-NN` still appears everywhere it should.
If that enforcement matters going forward it needs a new mechanism that
does not depend on the deleted YAML.

## Security master checklist

65 items, `SEC-01`–`SEC-65`, each globally unique. Status is derived from
the phase table above unless marked `(confirmed)`, which means directly
verified in code. Treat "phase done ⇒ item done" as a reasonable default,
not a guarantee.

| ID | Phase | Item | Status |
| --- | --- | --- | --- |
| SEC-01 | P4 | `.htaccess`/Caddy deny rules for sensitive directories | Done (confirmed) |
| SEC-02 | P0 | CLI guards on all `tools/*.php` scripts | Partial — see below |
| SEC-03 | P2 | No fixture SQL with secrets in web root | Done |
| SEC-04 | P4 | Ship `robots.txt` | Done |
| SEC-05 | P4 | Brotli compression | Done |
| SEC-06 | P4 | `Cache-Control: immutable` for hashed assets | Done |
| SEC-07 | P5 | Replace `mt_rand()` with `random_int()` | Done for security-sensitive uses — see below |
| SEC-08 | P5/P17–P23 | Replace loose `==` with `===` | Done |
| SEC-09 | P5 | `#[\SensitiveParameter]` on secret-carrying params | Done (confirmed) — see below |
| SEC-10 | P9→P17–P23 | Remove `addslashes()` superglobal sanitization | Done (confirmed) — this row was wrong: global `addslashes()` on every superglobal was still live in `RequestBootstrap::bootEntryPoint()` as of 2026-08-15, corrupting data (`O'Brien` stored as `O\'Brien`), with 71 compensating `stripslashes()` calls masking it; fixed `aba74c9129` |
| SEC-11 | P9 | CSRF token md5→sha256 HMAC | Done (confirmed) |
| SEC-12 | P9 | CSRF verification via `hash_equals()` | Done (confirmed) — this row was wrong for the WS layer: `Ws\WsHelper`/`WsCsrfGuard::checkSecurityToken()` used `!==`, not `hash_equals()`, across all 41 real call sites; fixed `b38c5f0877` |
| SEC-13 | P9 | `CookieService` HttpOnly + Secure flags | Done |
| SEC-14 | P9 | Cookie deletion calls include all flags | Done |
| SEC-15 | P20 | Eliminate 2 of 3 `eval()` calls (3rd = SEC-49) | Done |
| SEC-16 | P19 | Wrap `exec()` calls with `escapeshellarg()` | Done (confirmed) — this row was wrong: 4 of 16 real `exec()` sites escaped nothing (`Admin/Image/ImageBackend.php`, `Admin/MaintenanceActionsPageRenderer.php`, `Ws/Core/GetCacheSizeHandler.php` ×2), admin-to-shell via DB-settable config; fixed `c6a63c8143` |
| SEC-17 | P17 | URL validation in redirect responder | Done |
| SEC-18 | P19 | Replace `addslashes()` in `SearchService` with prepared statements | Done |
| SEC-19 | P21–P22 | Controllers use PSR-7 request, not superglobals | Done |
| SEC-20 | P19 | XXE protection on SVG/XML parsing | Done (confirmed) |
| SEC-21 | P19 | SVG stored XSS sanitization on upload | Done |
| SEC-22 | P21 | Replace `phpinfo()` with curated server info | Done |
| SEC-23 | P17 | SSRF hardening for the HTTP client | Done |
| SEC-24 | P17 | Remove local-file read fallback in the HTTP client | Done |
| SEC-25 | P18 | Session fixation: regenerate on privilege escalation | Done — P28 was meant to verify further |
| SEC-26 | P16 | Validate locale before `include` in `LangService` | Done (confirmed) |
| SEC-27 | P18 | Auto-login key HMAC sha1→sha256 + `hash_equals()` | Done |
| SEC-28 | P18 | `EphemeralKeyService` HMAC md5→sha256 + `hash_equals()` | Done |
| SEC-29 | P17 | Host header poisoning defense | Done |
| SEC-30 | P17–P22 | Exception messages don't expose internals | Done |
| SEC-31 | P18 | Account enumeration via registration | Done |
| SEC-32 | P20 | ZIP bomb protection | Done (confirmed) |
| SEC-33 | P19 | Derivative serving leaks file existence | Partial — permission check is real, but runs through the full pipeline, not the designed fast path, so the item's scope shifted |
| SEC-34 | P22 | Install sentinel DB-flag secondary check | Done |
| SEC-35 | P19 | Remove non-standard headers from derivative pipeline | Done |
| SEC-36 | P27 | REST error responses never leak internals | Done (confirmed) — `Http\Middleware\ExceptionHandlerMiddleware` catches every uncaught `Throwable` app-wide (including `/api/v1`), logs it + reports to Sentry, and returns a bare `Internal Server Error` 500 with no message/trace |
| SEC-37 | P27 | No object dumps in the REST error path | Done (confirmed) — same middleware; nothing beyond the class name and message is logged, never returned to the client |
| SEC-38 | P27 | REST route authorization middleware | Done (confirmed) — `Http\AdminGuard` (401 vs 403, RFC 9457 problem+json), explicitly injected into 69 of the 134 `Controller\Api\*` classes |
| SEC-39 | P27 | Validate `Content-Type: application/json` on REST bodies | Done — `Http\JsonBody::decode()` (the single choke point every JSON-body-consuming controller already goes through) rejects a non-empty body whose media type isn't `application/json` with a 415, mirroring `TusUploadPatchController`'s own tus-protocol check |
| SEC-40 | P24 | Request DTOs as a hard input-validation gate | Real progress — arch test live; no "0 remaining" verified |
| SEC-41 | P28 | Password hashing → Argon2id | Not started |
| SEC-42 | P28 | CSRF middleware: remove `/admin*` exemption | Not started |
| SEC-43 | P28 | No `Access-Control-Allow-Origin: *` on the OpenAPI spec endpoint | Not started — still moot: the OpenAPI 3.2 spec now exists (`openapi/openapi.yaml`, P27) but only as a lint-gated repo artifact, never served over HTTP — no route exposes it, so there's no endpoint for a CORS header to apply to |
| SEC-44 | P28 | API rate limiting + rate-limit headers | Not started — `rate_limiter` pool deliberately unbuilt pending this |
| SEC-45 | P28 | CSP violation reporting | Not started |
| SEC-46 | P28 | Cross-Origin Isolation (COOP/COEP) | Not started |
| SEC-47 | P28 | `Vary: Cookie` on permission-dependent responses | Not started |
| SEC-48 | P28 | Default `allow_html_descriptions` to `false` | Not started — still `true` (confirmed); remapped from a pre-renumbering phase |
| SEC-49 | P29 | Remove `eval_visible` (plugin-facing half of SEC-15) | Done |
| SEC-50 | P3 | CycloneDX SBOM generated as a CI artifact | Done (confirmed) |
| SEC-51 | P3 | Pin GitHub Actions to commit SHAs | Done |
| SEC-52 | P3 | OSV-Scanner over lockfiles in CI | Done |
| SEC-53 | P3 | SLSA build provenance + attestations | Done |
| SEC-54 | P4 | Sign container images + release artifacts | Done (confirmed) |
| SEC-55 | P28 | OIDC SSO: PKCE + state/nonce + ID-token validation | Not started |
| SEC-56 | P18 | GDPR data-subject endpoints behind re-auth + rate limit | Not started — `PrivacyService` doesn't exist; the backend was P18 scope, its REST exposure P27 |
| SEC-57 | P15 | Append-only / tamper-evident audit log | Done — `Piwigo\Audit\*` is real |
| SEC-58 | P11 | Feature-flag changes authz-gated + audited | Partial — `FeatureFlag` is read-only by design, no mutation path exists yet to protect |
| SEC-59 | T3·AI | MCP server: scoped read-only tokens | Not started (cuttable) |
| SEC-60 | P7 | Worker-mode request isolation | Not started — the FrankenPHP worker-mode gap; workstream C3 Phases 0–1 (unified PSR-15 bootstrap pipeline) are a real prerequisite now landed, Phase 2/3 still open |
| SEC-61 | P11 | Mercure topic authorization | Not started (T3 rider) |
| SEC-62 | P28 | Trusted Types | Not started |
| SEC-63 | P28 | Fetch Metadata isolation | Not started |
| SEC-64 | P3 | OpenSSF Scorecard | Done |
| SEC-65 | P27 | API `Idempotency-Key` replay store | Done — `Http\Middleware\ApiIdempotencyMiddleware`, opt-in via the `Idempotency-Key` header, scoped to `/api/v1` mutating methods excluding tus; true concurrent-duplicate-request locking is a deliberate non-goal (a replay cache, not cross-process locking) |

### Notes on the partial items

**SEC-02.** Most real entry-point scripts have a `PHP_SAPI !== 'cli'`
guard, but `tools/i18n/verify-parity.php` and `tools/i18n/convert-all.php`
— both directly invokable per their own "Usage:" docblocks — have none.
Not currently reachable (`tools/` is not among `public/`'s symlinks), but
a literal gap against this item's stated scope regardless.

**SEC-07.** Seven `mt_rand()` calls remain, each non-security-sensitive:
temp-filename uniqueness, cache-busting query params, probabilistic
log-sampling gates, or picking a *length* parameter for a value that
itself comes from `random_bytes()`/`generateKey()` — e.g.
`Ws/Users/AddHandler.php`'s auto-generated password uses
`generateKey(mt_rand(15, 20))`, where the entropy is `generateKey`'s, not
`mt_rand`'s. None is the entropy source for a security-relevant token.

<!-- markdownlint-disable-next-line MD013 -->
<!-- doc-drift-check: cmd='grep -rn "mt_rand(" src --include="*.php" | wc -l' expect="7" -->

**SEC-09 — closed since last audit.** Every gap previously listed here is
now covered: `AuthService::tryLogUser()` marks its `?string $password`,
`Db\DbCredentials` marks the DB password, and all four Request DTOs
(`IdentificationSubmitRequest`, `RegisterSubmitRequest`, `PasswordRequest`,
`UserBootstrapRequest`) mark their promoted password properties.
`pwgLogin()` no longer takes a raw password at all — it takes a
`TryLogUser` event, whose own docblock records the residual limitation:
`#[\SensitiveParameter]` redacts scalar and array *parameters*, never
object properties, so an event carrying a password is not redacted by the
attribute and must not be dumped.

<!-- markdownlint-disable-next-line MD013 -->
<!-- doc-drift-check: cmd='grep -rln "SensitiveParameter" src --include="*.php" | wc -l' expect="9" -->

### Threat model

A different cross-section of the same 65 items. Every attacker goal maps
to at least one `SEC-NN` above, so its status is derivable from theirs;
it is not reproduced row by row here. Two items (SEC-05 Brotli, SEC-06
`Cache-Control: immutable`) are performance items, not mitigations, and
intentionally appear in no threat row. Mitigations that are not numbered
items at all — nonce-based CSP, the PSR-18 SSRF guard, DB-level account
locking, dual passwords — belong to P28 the same as their numbered
siblings.

### Secrets & key management

DB credentials and the application `secret_key` live in `.env`, never
web-served. A single `secret_key` derives the HMACs for CSRF tokens
(SEC-11/12), the auto-login cookie (SEC-27) and ephemeral keys (SEC-28) —
rotating it invalidates all three at once, forcing re-login repo-wide.
See `docs/REFERENCE.md`'s Secret rotation section.

DB password rotation via MySQL dual passwords
(`ALTER USER … RETAIN CURRENT PASSWORD`) is P28 scope, not built. Today's
path is the simpler "update env, roll deployment" sequence
`docs/REFERENCE.md` documents.
