# Plan and build history

Phase-by-phase record of `17.x-rewrite`: what was planned, what shipped,
and where the two diverge. This file and `docs/REFERENCE.md` are the only
two planning documents, deliberately — an earlier structure of 18
per-phase files drifted against each other and was consolidated into
these two.

`17.x-rewrite` replays `16.x-rewrite`'s modernization as 55 sequential
backbone phases (P0–P55, in 10 epochs A–J), rebuilt from `origin/16.x`
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
| `feat(events): A1`–`A6`, "P32 Stage A" in commit bodies/`docs/events-legacy-map.md` | Event system rewrite | P34 |
| `(P25/G19)`, `(P19.n)` | WS layer decomposition into typed handlers | Epoch G / P25 |
| Original plan's "P24 Vite + TypeScript" | Frontend track | P36 / P46 |
| Original plan's "P27 Type correctness" | Merged into remediation | P24 |
| Original plan's "P32 CSS modernization" | CSS architecture | P52 |

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
  OpenAPI 3.1 + tus, WS deleted here). Old P26–P53 shift to new P28–P55
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
| P25 | WS layer modernization — typed internals + PSR-7 lifecycle | Done — Stage 3's remaining items targeted `Piwigo\Ws\*`/`tests/Contract/`, both deleted outright by P27; see Epoch G | ~50 |
| P26 | Admin fragment surface — UI-facing WS methods off the envelope | Done — the WS layer no longer exists at all; every admin UI surface already renders via Latte pages/fragments, not a JSON/XML envelope | ~15 |
| P27 | Public API v1 (REST + OpenAPI 3.2 + tus) — WS deleted here | Done — 134 `Controller\Api\*` files, 88 registered `/api/v1` routes, full tus 1.0.0 chunked-upload protocol (6 dedicated controllers), RFC 9457 problem+json errors, hand-authored OpenAPI 3.2 spec (88 operations/11 domains) with a `redocly lint` CI gate + Gesso runtime contract enforcement, a generated TypeScript client, REST-body `Content-Type` validation (SEC-39), and an opt-in `Idempotency-Key` replay store (SEC-65); see Epoch G | ~151 |
| P28 | Security hardening | Not started | 0 |
| P29 | Plugin / Theme contracts + bundled extensions | In progress — P29.6 unstarted | 22 |
| P30 | Layer decoupling + repository restructure | Done — deptrac's 6-layer model enforces 0 violations in CI (established P6); the pre-consolidation repository-restructure plan's load-bearing goals were already met by the simpler `public/`-as-sibling-directory approach that shipped | 1 |
| P31 | Smarty → Latte template migration | Done | 80 |
| P32 | Latte lint/format tooling | Done — enforcement is P45 | 11 |
| P33 | Latte idiomatic modernization | Done — all 8 sub-items | 8 |
| P34 | Event system rewrite | Done — all 5 items complete and verified, including all 6 named core hooks (see Epoch J) | 13 |
| P35 | Browserslist decision + IE back-compat removal | Done | 1 |
| P36 | Asset-pipeline foundation (ViteManifest) | Done | 1 |
| P37 | Typed page-data exposure (PHP half) | Done | 1 |
| P38 | Inline JS extraction | Done — all 7 batches (P38-A–G) | 7 |
| P39 | Inline CSS extraction | Done — all 5 batches (P39-A–E) | 5 |
| P40 | Typed view objects + `Template` split | Done — Batches 1–9 + the 3 include-only-partials + the Mail domain batch all landed and fully validated (see below); every remaining `TemplatePageContext` class confirmed either P41 shell scope or a permanent ambient wrapper, exhausting P40's own actual scope. The physical `Renderer`/`TemplateLocator`/`ThemeChain` class split was never P40's own work — this section's own "Scope correction" note reassigned it to P41's one-time cutover from the start | 2 |
| P41 | Shell-last rendering + `PageState` split | Part 1 done — Batches A–E landed (see above). Part 2 (P41-G/H, asset-pipeline swap) landed too — `CssLoader`/`ScriptLoader`/`FileCombiner` replaced by `PageAssets`/`AssetContribution`, file-combining intentionally dropped (Vite migration replaces it later), 6 dead `header.latte`/`footer.latte` files removed; P41-I (capture-based, more-idiomatic-Latte follow-up replacing the placeholder-tag mechanism) proposed, then superseded before landing by P42's own declarative redesign (see below) | 8 |
| P42 | Declarative page assets & exposed data (View-level, supersedes P41-I) | In progress — mechanism + P42-A (11-partial conversion + 4 theme-base pieces) fully landed; P42-B (945-call-site migration) not started (see below) | 6 |
| P43 | Typed contributions + plugin-owned routes | Not started | 0 |
| P44 | Escaping campaign | Not started | 0 |
| P45 | Latte lint/format enforcement | Not started | 0 |
| P46 | JS → TS mechanical conversion | Not started | 0 |
| P47 | `getPageData<T>()` typing + `any` reduction | Not started | 0 |
| P48 | Refactor TS into modules | Not started | 0 |
| P49 | Remove jQuery | Not started | 0 |
| P50 | Lit component catalog (conditional on P49) | Not started | 0 |
| P51 | TS modernization | Not started | 0 |
| P52 | CSS architecture modernization | Not started — Tailwind call resolved (not adopted), work itself unstarted | 0 |
| P53 | Picture pipeline (new feature) | Not started | 0 |
| P54 | Dark mode (new feature) | Not started | 0 |
| P55 | Real quality gates | Not started | 0 |

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
batches. 157 event classes at the time; P34 (below) later pruned dead
ones to 127, then added 2 more closing its own catalogue gap.

<!-- markdownlint-disable-next-line MD013 -->
<!-- doc-drift-check: cmd='find src -path "*/Event/*.php" | wc -l' expect="128" -->

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

**Ship-first: seven security findings, fixed 2026-08-15**, found during
the P25 review and landed ahead of the modernization work itself:

1. Global `addslashes()` on every superglobal, every request — data
   corruption repo-wide (SEC-10). Fixed.
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
6. WS compared CSRF tokens with `!==`, not `hash_equals()` (SEC-12).
   Fixed.
7. Four `exec()` sites escaped nothing (SEC-16). Fixed.

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

**P26 is done.** Its goal — moving the ~15 UI-facing WS methods off the
JSON/XML envelope onto server-rendered fragments — holds by
construction: the WS layer is gone entirely, and every admin UI surface
renders through a `*PageRenderer`/`*SubController` onto a Latte
template, never a WS envelope.

**P27 is done.** `Controller\Api` holds 134 files; `RouteDefinitions.php`
registers 88 real `/api/v1` routes across categories, comments,
extensions, groups, history, images (including filtered search),
session/preferences/API keys/favorites/caddie, tags, uploads, and users.
Uploads use a full tus 1.0.0 chunked-upload protocol (`Uploads/
TusUpload*`, 6 dedicated controllers) in place of the old 9-method WS
chunk-upload protocol. Every error response is RFC 9457
`application/problem+json` (`Http\Middleware\ApiErrorMiddleware` for
routing-level 404/405, `Http\Middleware\ExceptionHandlerMiddleware`
app-wide for uncaught exceptions — SEC-36/SEC-37). `Http\AdminGuard`
(401 vs 403) is injected into 69 of the 134 controllers (SEC-38).

An OpenAPI 3.2 spec (`openapi/openapi.yaml` + `openapi/paths/*.yaml`, 88
operations across 11 domains, hand-authored from real controller/DTO/
service source) is gated in CI by `bun run lint:openapi` (Redocly) and a
structural test (`tests/Unit/OpenApi/SpecStructureTest.php`, via
`openapiphp/openapi`'s `Reader` — never its own `->validate()`, which
hard-rejects `3.2.0`); `studio-design/gesso` enforces it against real
PSR-7 request/response pairs at runtime (`tests/Browser/Api/*`) and
tracks per-operation coverage via `OpenApiCoverageExtension`. Every
controller has a typed `*Input` DTO for its request body
(`ImageSetMd5sumController`/`ImageMissingDerivativesController`/
`ImageFilteredSearchCreateController` were the last 3). A generated
TypeScript client (`openapi/client/schema.d.ts` via `openapi-typescript`,
`openapi/client/index.ts`'s `openapi-fetch` wrapper) is regenerated and
diffed in CI to catch drift.

`Http\JsonBody::decode()` validates `Content-Type: application/json` on
any non-empty body, rejecting anything else with 415 (SEC-39).
`Http\Middleware\ApiIdempotencyMiddleware` provides an opt-in
`Idempotency-Key` replay store (SEC-65) scoped to `/api/v1` mutating
methods, excluding tus (its own resumability protocol already covers
retries): a repeated key with the same body replays the stored response
without re-invoking the controller; a different body gets 400.
Concurrent-duplicate-request locking is a deliberate non-goal — a
replay cache, not cross-process locking.

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

**P30 — Layer decoupling + repository restructure.** Both halves done.

Layer decoupling: `deptrac.yaml`'s 6-layer model (L0Data→L4Integration,
established at P6) enforces 0 violations with no `skip_violations`
escape hatch, gated in CI (`vendor/bin/deptrac analyse`, the job scaffolded
at P3, given real teeth once P6 defined the ruleset) -- meets the
original pre-consolidation plan's own target (`SCC ≤ 15,
layer violations = 0`) outright. The 0-violation count is a live
ratchet, not just "no violations having accumulated": a 2026-08-15 P25
review found it had regressed to 16 real violations
(`Config\ConfigService`/`CurrentConfig` in L1Infrastructure depending on
`Image\OrderBy` in L2aCoreDomain, introduced by `7c281ee97c`, which left
`OrderBy` unplaced in `deptrac.yaml`), fixed the same day; a
2026-08-18 `ApiIdempotencyMiddleware` regression (L3Presentation
depending on 5 concrete L4Integration controllers) was caught and fixed
the same way, via route-level metadata (`RouteResult::
$bypassIdempotency`) instead of a controller-class reference -- see
`Http\ControllerInterface`'s own docblock for the same L3/L4
dependency-inversion pattern.

Repository restructure: the pre-consolidation `docs/STRUCTURE-PLAN.md`
(a fully separate source folder outside the web root anywhere the user
wants, plus a `public-template/` shim generated by a setup script) was
never built as written -- what shipped instead is simpler:
`public/` as a plain sibling directory inside the same repo, holding
only the real entry-point scripts plus `themes`/`dist`/`_data/combined`
symlinks for the static assets that must stay web-reachable. That
covers every load-bearing goal the old plan had (`galleries/`,
`upload/`, `install/`, `vendor/`, `src/` are all outside `public/`, none
directly HTTP-reachable; `src/` holds only PHP, no TS mixing; no stray
root-level working-note files). Audited fresh against the current tree
2026-08-18: the old plan's remaining proposals (renaming `_data`/
`_analysis`, moving `template-extension/` into a `resources/` tree) are
cosmetic, and `template-extension/` itself is live code (`Template.
php`'s override-resolution chain, `AdminUiHelper`,
`PrecompileTemplatesCommand`), not dead weight to clean up. No further
restructure work is scoped.

The one commit tagged `chore(p32): delete doc/` is an unrelated narrow
cleanup that borrowed a pre-consolidation number for this same phase.

### Epoch J — Presentation, templating & extension surface (P31–P55)

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
is split out to P36 and P53. Every `p31.x` commit is a `.tpl` → `.latte`
conversion or Smarty cleanup, nothing manifest-, combiner- or
image-format-related.

**P32 — Latte lint/format tooling.** Done. Both halves exist; **only
enforcement is missing, and that is P45's job**, not a gap here.

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

**P34 — Event system rewrite. Done**, landed under the `feat(events):
A1`-`A6` tags (historically "P32 Stage A" — the numbering shift predates
the current scheme; see the commit-tags table above) plus one follow-up
`fix(events)` commit closing gaps a full Integration/Contract/Browser
run surfaced (7 test-fixture plugins with a heredoc-escaping byte
mismatch that made the co-location rename's `grep -F` search miss them,
2 further fixture handlers still using the pre-A2 `return $newValue`
idiom, a stale `{@see}` reference, a stale `deptrac.yaml` comment).
Independent of the rest of Epoch J: touches no template, asset or JS
file.

1. **Mutable payloads — done (A1).** Filterable fields on event classes
   dropped `readonly`; context fields kept it. Call sites read the value
   back off the event instead of relying on a returned instance.
2. **One verb — done (A2).** `dispatchChange()`/`dispatchNotify()`
   collapsed into PSR-14 `dispatch(object $event): object`
   (`PluginConfig\EventDispatcher::dispatch()`); the runtime "handler
   must return an instance" guard is gone. A handler that still
   `return`s a replacement value instead of mutating the event in place
   now fails silently — documented in `docs/events-legacy-map.md`.
3. **Symfony — done (A3).** `PluginConfig\EventDispatcher` wraps a real
   `Symfony\Component\EventDispatcher\EventDispatcher` directly;
   `addTypedHandler()` maps onto `addListener()`. Kept the closure-based
   `subscribedEvents()` shape rather than Symfony's own
   `EventSubscriberInterface`, whose static method-name strings collide
   with this repo's PHPStan ban on variable method calls.
4. **Delete the dead API — done (A4).** `EventHandler`,
   `addEventHandler()`, `removeEventHandler()`, `includePath`,
   `callablesEqual()`, and the legacy test file are all gone.
5. **Catalogue — done.** Every `Loc*` marker renamed to a
   tense-consistent, module-co-located name and event classes pruned to
   evidence (127 classes today, down from 157); `docs/
   events-legacy-map.md` shipped as the name-lookup reference. All 6
   named core hooks are covered: `invalidate_user_cache` →
   `Cache\Event\InvalidateUserCache` and `get_categories_menu_sql_where`
   → `Category\Event\GetCategoriesMenuRows` (both A5); `get_high_url` →
   the new `Image\Event\GetHighUrl` (dispatched from `ImageUrlBuilder::
   stdGetUrls()`'s `download_url` computation, distinct from
   `GetDerivativeUrl`, which fires for every resized derivative, not just
   the original-file download link); `user_list_columns` and
   `after_render_user_list` → one new `Users\Event\GetUserListRows`
   (dispatched once from `UserListController`, over the final row list --
   both legacy hooks wanted the same real capability, customize/augment
   each admin-user-list row, through a DataTables server-side-columns
   shape `GET /api/v1/users`'s plain JSON rows don't have, so they
   collapse into one filter here, the same move `GetCategoriesMenuRows`
   already made for its own now-nonexistent SQL-string mechanism);
   `add_elements` → already covered by the pre-existing `Admin\Upload\
   Event\UploadedFileAdded` (a different legacy hook,
   `loc_end_add_uploaded_file`, already dispatched at the real
   per-upload insert site for the same "react to a newly-added element"
   capability -- no new class needed).

**P35 — Browserslist decision + IE back-compat removal.** Done. Committed
`.browserslistrc` (Chrome/Edge ≥94, Firefox ≥93, Safari ≥15 — the
evergreen floor that actually supports `tsconfig.json`'s existing
`ES2022` target/lib, confirmed against it rather than picked
independently), and mirrored it into `vite.config.ts`'s
`build.target` as esbuild target strings (`browserslist` queries and
esbuild targets aren't interchangeable, so this is a second, matching
declaration, not a derived one). Removed everything that floor
obsoletes: `themes/default/js/pngfix.js` (IE6 PNG-alpha shim) and its
`<script>` reference in `header.latte`; the `fix-ie5-ie6.css`/
`fix-ie7.css` files and their `<!--[if IE]>` links in `local_head.latte`
(plus a dangling `admin/default/fix-ie7.css` link in `install.latte`
that pointed at a file that no longer existed even before this phase);
the four unreferenced IE7 fontello stylesheets
(`fontello-ie7[-codes].css` ×2 theme copies). Also swept every
`-ms-filter`, `zoom:1`/`*zoom:1`, `*`-hack property
(`*display`/`*cursor`/`*margin-top`), and legacy
`filter:progid(...)`/`filter:alpha(...)` declaration out of the 14
vendored plugin/theme CSS files that had them (`theme.css`,
`iconset.css`, `chosen.css`, `jquery.dataTables.css`,
`jquery.jgrowl.css`, `jquery.Jcrop.css`, `jquery.ui.progressbar.css`,
`selectize.clear/dark.css`, all 5 `colorbox/style*/colorbox.css`
variants) — broader than the shorthand "`-ms-filter`/`zoom:1`/`\9`"
description first used to scope this, once the real grep was run
end to end; the modern (non-IE) vendor-prefixed declarations
(`-webkit-`/`-moz-`/`-o-`) sitting alongside them were left alone as
out of scope. Verified: brace-balance check on every edited CSS file,
`bun run typecheck`/`lint:js` clean, then `composer test:visual` (66/66)
and `GoldenHtmlSnapshotTest` regenerated for the pages whose rendered
`<head>` lost the dead `<!--[if IE]>` markup.

Running the full (unfiltered) `composer test:visual` for this also
surfaced a genuine pre-existing latent bug unrelated to any of the
above, in `admin-user-activity`'s own VR/golden-HTML baselines: every
`needsAuth` route's `H::loginAsAdmin()` does a real, fresh POST login
each time (confirmed via a live debug trace on
`AuthService::logUser()`), and each one legitimately logs an `activity`
row. `admin-user-activity` renders that table as a full unpaginated list
(unlike `admin-dashboard`'s chart, which only needs deterministic weekly
bucketing), so its row count — and rendered height — depended on how
many other `needsAuth` routes happened to run before it, not on a fixed
baseline; the committed baseline itself already carried ~20 such
accumulated rows, meaning it was never actually deterministic, just
never previously exercised by a full unfiltered run. Fixed the same way
`admin-history` already handles the identical class of problem:
`H::truncateGuestActivity()` (new), called right before
`H::loginAsAdmin()` in both `VisualRegressionTest.php` and
`GoldenHtmlSnapshotTest.php`.

**P36 — Asset-pipeline foundation.** Done. The template-declared vs.
view-declared fork is **decided: view-declared**, resolved now rather
than left for P40/P41 to re-litigate.

Reasoning: only 10 of 158 registered scripts are `load: 'header'` (119
footer, 29 async) — template-declared's "must collect before `<head>`
renders" cost is almost entirely a CSS problem, not a JS one. CSS
layering already relies on fragile, tribal-knowledge magic numbers (a
real comment in `picture_formats.latte`: `{* order 10 is required, see
issue 1080 *}`); view-declared replaces that with one explicit ordered
list per page. Verified against Latte itself rather than assumed:
`Latte\Runtime\Template::render()` delegates to
`createTemplate($this->parentName, …)->render()` when `{layout}` is
present, so the layout's `<head>` genuinely precedes the child block —
unavailable under today's shell-last rendering. Natural fit with P40's
typed per-page view objects.

Adversarial pass against the real 76-template corpus found every real
`combineScript`/`combineCss` (file-based) call site fits one of three
sources, each with a different natural home once declaration is
per-page: (1) a theme's own unconditional base assets (`theme.css`,
`local/css/*-rules.css`, `print.css`) — resolved from theme config at
layout-render time, no event needed; (2) core's own per-page
conditional assets (e.g. `rating.js` only where ratings are enabled) —
conditional today on state the controller already decided before the
template ran, becomes a property on the page's typed view once P40
lands; (3) plugin-contributed assets — the one genuine extension
point, since plugins can't add properties to core's View classes, via
a new `Get*`-prefixed PSR-14 event (`GetPageAssets`, matching the
established filter-event convention). This preserves the *capability*
("a plugin/theme can still get an asset onto the page") without
preserving the old *mechanism* (arbitrary inline template calls) —
deliberately not a 1:1 port.

**Scope correction from the original plan text**: this phase does
**not** retire `ScriptLoader`/`CssLoader`/`FileCombiner`/`Combinable`/
`Script`/`Css` (~1,038 lines). Doing that now and bridging all 76
templates through an interim collector would be exactly the kind of
throwaway scaffolding P41 would immediately replace. Instead: the old
system stays completely untouched, serving all 76 templates exactly as
today, while this phase builds the new `Piwigo\Asset` infrastructure
(`ViteManifest`, `AssetContribution`, `PageAssets` collector,
`GetPageAssets` event) alongside it with **zero template edits**.
Migration onto the new mechanism happens once P40's page-family
campaign fully completes: `PageAssets`/`AssetContribution`/
`GetPageAssets` stay built but dormant through the whole of P40 (a
migrated page's controller calls `Template::combineCss()`/
`combineScript()` directly instead — see P40's own "Scope correction"
note), and become the real, sole asset-resolution path only as part of
P41's own single, one-time shell-last cutover, at the same point the
old `CssLoader`/`ScriptLoader` classes are finally deleted — not
per-page-family alongside each P40 batch.

Two real behaviors the new ordering pass must preserve, found via
direct template audit, not assumed: real multi-level `require:` chains
(e.g. `jquery.ui.timepicker-addon` → `jquery.ui.datepicker` → its own
transitive deps) need genuine topological resolution, not a
single-level check; and `rating_user.latte`'s `jquery.ui.tooltip`
registration (zero `path:`/`require:` params) plus
`jquery.ui.datepicker` (never explicitly registered anywhere) both
depend entirely on `ScriptLoader`'s naming-convention auto-resolution
— dropping that ~30-line resolver would silently break
`admin-rating-user` and every page including the datepicker.
`footerScript`'s 80 real call sites are inline JS with real
PHP-interpolated per-request data (not file references), so they stay
on the untouched old mechanism until P37 (typed JSON island) + P38
(extraction) can turn them into real static files.

**Shipped**: `Piwigo\Asset\{ViteManifest, ViteManifestEntry,
AssetContribution, AssetKind, LoadMode, PageAssets, ResolvedAsset,
Event\GetPageAssets}` — real, fully unit-tested infrastructure (24 new
tests, including the two jQuery-UI-resolver edge cases and the real
multi-level `require:` chain above), zero template edits, zero
behavior change to any of the 76 templates. `vitals.js`'s
`VITALS_SCRIPT_URL` (`PageTailRenderer`) now resolves through
`ViteManifest` instead of a hardcoded string, proving the
manifest-reading half end to end against the one real entry that
exists today — confirmed byte-identical output via the full VR suite
(66/66, zero baseline regeneration needed). Also fixed two stale
phase-number references in `vite.config.ts`'s own comments found while
touching this file ("P34's asset-manifest resolution" → P36, "68 real
entries land in P44" → P46, matching P46's own text below). Verified:
`composer analyse`/deptrac (0 violations, `Piwigo\Asset` lands in the
already-reserved L3Presentation slot)/ECS all clean; full
`composer test`/`test:integration`/`test:visual` green (one unrelated
pre-existing flaky failure in `ImageServiceTest.php`, a random-ID
collision under `--parallel`, confirmed passes in isolation).

**P37 — Typed page-data exposure (PHP half).** One typed payload per
page, emitted as a JSON island, replacing the ad-hoc PHP → JS smuggling:
68 in-template `json_encode` uses, `PageState::$bodyData`/`BODY_DATA`,
and the string-into-JS-literal pattern the 210 `escapeJavascript` sites
represent. This has to exist *before* P38, or P38 must invent an interim
mechanism that P47 then replaces. It is also the PHP counterpart to P40's
typed view objects — the same typed source feeds the template and the
JSON island, so design the two together even though P40 lands later.

**Shipped**: `Piwigo\Page\PageDataPayload` — a real, fully unit-tested
`{data, strings}` JSON-island builder (5 new tests: bodyData/exposedData
merge with collision precedence, `Lang::t()` string resolution including
a missing key, dedup on a repeated `exposeString()` call, a non-ASCII
round-trip, and a `</script>`/`<!--`/`&` neutralization check via the
real `JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR | JSON_HEX_TAG |
JSON_HEX_AMP` flags). `PageState::exposeData()`/`exposeString()` land as
the declaration surface (2 new tests: accumulate-and-dedup, and a full
`reset()` sweep across every property, closing a real pre-existing gap —
no prior test asserted `reset()` cleared every property exhaustively).
`Template::getPageDataScript()` backfills a `JSON_ISLAND_TAG` placeholder
in `finalizeOutput()`, the same pattern `COMBINED_SCRIPTS_TAG`/
`COMBINED_CSS_TAG` already use. The one real existing writer,
`PageState::$bodyData` (via `SectionPopulator`), is wired end to end:
`BODY_DATA`/`data-infos` is removed from `PageHeaderPageContext` and both
real front-end headers (`default`, `standard_pages` — `admin`'s header
never had it), replaced by a single `<script type="application/json"
id="page-data">` tag in all 3 real footers. `composer lint:vartype:fix`
resynced the global `{varType}` union across all 135 templates (the
mail/install templates' own `{varType string $BODY_DATA}` boilerplate
line dropped along with it — confirmed neither path ever read that var).
The Latte-analysis shim class (`tools/phpstan/Latte/Generated/
LatteAnalysisShims.php`) was regenerated via `bin/piwigo
phpstan-latte:generate-shims` to pick up the 3 new
`exposeData`/`exposeString`/`getPageDataScript` Latte functions.
Explicitly out of scope, same as P36's own template-corpus boundary:
converting the 204+42 real `escapeJavascript`/`json_encode(translate(...))`
template call sites to `exposeString()` — that is P38's job, one template
at a time. Verified: PHPStan level 10/ECS/deptrac (0 violations) all
clean on every changed file; full `composer test` (5739 passed) and
`composer test:integration` (2120 passed) green; `composer test:visual`
66/66 green with zero baseline regeneration (the removed attribute and
new JSON `<script>` tag are invisible on-screen); `composer
test:golden-html` needed baseline regeneration for 72 of 74 routes,
exactly as predicted (only the `data-infos` removal + `<script
id="page-data">` addition changed in every diff, confirmed by inspection).

**P38 — Inline JS extraction.** Every `<script>` block in a template
moves to a plain `.js` file loaded through P36's manifest. Same behavior,
proven via `composer test:visual`. No TypeScript, no modularization, no
jQuery changes.

Inline JS is not only literal `<script>` blocks: 16 templates carry one,
but **80 `footerScript(` captures across 61 templates** carry the rest.
Critically, **all 210 `escapeJavascript` call sites are inside that
scope** — verified, none outside a `{capture}` or `<script>` region. Any
escaping or filter cleanup done before P38 is therefore discarded work,
which is why P38 and P39 must run ahead of P40–P44 and ahead of any
further template-content pass.

**Shipped**: every real corpus site — 419 `translate()`/`{_'...'}` calls
inside a `{capture}`/`<script>`/`on*=` region, plus the 4 real dynamic
values (`$CATEGORIES_NAV`, `$CSRF_TOKEN`, `$ROOT_URL`, `$NB_ALBUMS`) —
converted to `exposeData()`/`exposeString()` + a companion `.js` file,
across 6 batches (P38-A mechanism through P38-G retirement). Two
incidental, real behavior fixes landed as a side effect of the
conversion rather than a deliberate goal: `themes/default/js/
thumbnails.loader.js`'s `max_requests`/`error_icon` were always read
before their `footerScript()` producers had rendered (a genuine ordering
bug independent of P38), now resolved correctly via `page-data`'s
`require:`; and `plugins_installed.latte`'s `const isWebmaster =
{$isWebmaster};` — a raw PHP bool interpolated through Latte's
`ENT_NOQUOTES` text escaper — produced `const isWebmaster = ;`, a real
JS syntax error breaking the whole combined footer bundle for every
non-webmaster admin, fixed by `exposeData()`'s real `json_encode()`.
`PiwigoExtension::escapeJavascript()` and its filter-map entry are
removed (P38-G), along with its 2 unit tests;
`tools/phpstan/Latte/Generated/LatteAnalysisShims.php` regenerated.
Two real, pre-existing test-harness gaps were found and documented
rather than silently worked around: `themes/default/template/
search.latte`'s advanced-search block is unreachable by any registered
route (`SearchController` always redirects, never renders), and
`themes/standard_pages/template/profile.latte`'s new `exposeData()`
calls, while correct, aren't exercised by `test:golden-html` either —
the golden test's `golden_html_test` fixture theme has no
`themeconf.inc.php` and never actually triggers the `use_standard_pages`
template swap, a P38-C-era gap in the test itself, not in this
conversion. Verified: `composer lint:latte`/`lint:js`/`analyse:phpstan`
clean on every batch; `composer test:visual` 66/66 green (including a
newly-found, deterministic, pre-existing `admin-themes-new` VR failure —
the same stale-cursor-triggers-a-real-hover class already fixed for
`admin-cat-list`, extended to cover it); `composer test:golden-html`
regenerated and reviewed for every route the conversion actually
touches; `composer test` (Unit/Arch) green throughout.

**P39 — Inline CSS extraction.** Every `<style>` block and `style="…"`
attribute moves to a real `.css` file: 20 templates with `<style>`, 243
`style="` attributes. Independent of P38 — different files, different
linter — so parallelizable with it. P39 also settles whether
`Template::htmlStyle()` (15 call sites, accumulating runtime inline CSS)
survives at all, or is superseded by real stylesheets plus the existing
`local/css/*-rules.css` mechanism. P41 should not carry it forward by
default.

**Shipped**: all 67 touched templates (46 admin / 16 default / 5
standard_pages) across 5 batches (P39-A mechanism through P39-E
retirement) — every `<style>` block, `{do htmlStyle(...)}` capture, and
`style="…"` attribute moved to a real `.css` file (new per-theme
`css/utilities.css` for repeated shapes, new `css/pages/<template>.css`
per template), registered via the existing `combineCss()` mechanism.
`no_photo_yet.latte` incidentally dropped its `n:syntax="off"` attribute,
no longer needed once its CSS left the template. `mail/text/html/
header.latte`'s dynamic `<style>` stayed inline (HTML-email
compatibility requirement), extended to `notification_admin.latte`'s and
`notification_by_mail.latte`'s static inline styles for the same reason.
`.stylelintrc.json` no longer double-lints every theme file through the
`public/themes`/`public/dist` symlinks; a `stylelint-suppressions.json`
baseline now separates pre-existing violations from new ones, though
`bun run lint:css`'s own exit code can't reach 0 (a real limitation in
stylelint 17's suppression feature, not a script bug) — verified per
touched file instead. `Template::htmlStyle()`, its `$htmlStyle`
accumulator, its `PiwigoExtension` filter-map entry, and its
`finalizeOutput()` splice are removed (P39-E); `htmlHeadElements`
handling is untouched. Several real, pre-existing bugs were found and
fixed along the way, none visible in golden-HTML's text diff: repeated
CSS-specificity regressions where a new class-based rule lost to an
existing higher-specificity selector in `theme.css` (hide/show toggles,
`margin`/`color`/`right`/`max-width` overrides, compound-class fixes);
a custom-property `url()` resolution bug (`url()` piped through a CSS
custom property resolves against the *consuming stylesheet's* location,
not the page's — kept inline instead, on `cat_modify.latte` and 2 more
shape-4 sites); a duplicate `class=` attribute from a bulk `style=`→
`class=` replace (`site_manager.latte`, a 3rd recurrence of the same
`install.latte`/other bug); and a genuine PHPStan `cast.string`
false-positive on `rating_user.latte`'s `{capture}`-produced value, root
caused to every compiled template's `extract($ʟ_args)` poisoning
untyped locals to `mixed` — fixed by teaching `LatteTemplateCompiler` to
inject a real `@var Html|string|false` docblock after every `{capture}`
target assignment, derived from Latte's own generated code shape.
Verified: `composer lint:latte` clean; `analyse:phpstan` 0 errors;
`lint:php` (ECS) 0 errors; `deptrac analyse` 0 violations;
`test:golden-html` 74/74; `test:visual` 66/66; `composer test`
(Unit/Arch) 5734 passed; `test:integration` 2119 passed.

**P40 — Typed view objects + `Template` split.** The largest single diff
in the epoch. Mitigate by converting one page-family at a time, after
proving the pattern end to end on a thin slice (`index.latte` +
`GalleryController`, see the "Scope correction" note below for why
`@layout.latte` is explicitly *not* part of this phase's thin slice)
gated on golden-HTML, VR and real Browser tests.

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
and tab, the `extents_for_templates` config and sanitiser, 14
`getExtent()` template calls (re-audited against the real tree: 14, not
15 — `grep -rn "getExtent(" themes/ --include="*.latte"`), and its unit,
Browser and VR tests. Also dead once this lands:
`CategoryRepository::findActivePermalinks()`/`CategoryService::
getActivePermalinks()`, which exist solely to feed this feature's own
"selective URLs keyword" list and have no other caller.

Delete the tooling this obsoletes: `tools/phpstan/Latte/VarTypeSyncer.php`,
`VarTypeSyncResult.php`, `Command/PhpStanLatteSyncVarTypeCommand.php`,
their two test files, and the `lint:vartype`/`lint:vartype:fix` scripts.
Collapse `VariableMapBuilder`, `TemplateCallSiteScanner`,
`TemplateCallSiteVisitor` and `ContextVariableExtractor` to "read the
declared `{templateType}`". Keep `LatteTemplateCompiler`. **Add a
round-trip check**: every template's `{templateType}` class must declare
that template back via `#[Template]`.

Depends on P36, P37, P38 and P39.

**Scope correction from the original plan text**: the thin slice above
("`index.latte` + a new `@layout.latte` + `GalleryController`") reads
as introducing real `{layout}`/`{block}` composition incrementally,
per page-family, during P40 itself — meaning migrated and unmigrated
pages would render through two different, coexisting shell mechanisms
for the whole ~130-context-class length of the campaign.
`header.latte`/`footer.latte` and the `PageHeaderRenderer`/
`PageTailRenderer`/`PageState` infrastructure that composes them are
shared by all 135 templates, not gallery-specific, so genuinely
replacing shell-last composition means refactoring that shared
infrastructure now — exactly P41's own stated scope, and consistent
with this phase's own "Depends on..." line above not listing P41.
**Decided instead**: P40 never touches `header.latte`/`footer.latte`/
`PageHeaderRenderer`/`PageTailRenderer`/`PageState`/`@layout.latte` at
all. A migrated page's `Renderer::render(View): Html` output gets
appended into `Template::$output` exactly the way `parse($file,
true)`'s return value does today — the middle piece of the same
three-call sequence, just produced a different way — so P40 proceeds
as a long, safe, page-family-at-a-time campaign with one rendering
model live for the shell at any time, only the body mechanism varying
per page. P41 becomes a single, one-time cutover for every page at
once, done only after every page-family already has a typed View —
see P41's own section below for what that unlocks. This also means a
View's data comes from merging `Template::$vars`' request-ambient
globals (`ROOT_URL`/`ROOT_PATH`/`themeconf`/`themes`/`lang_info`, plus
whatever `PageHeaderRenderer` assigned earlier in the same request)
with the View's own properties, not from the View alone — `Renderer`
calls a new `Template::renderView()` that does exactly this merge,
rather than routing through `Latte\Engine`'s own native object-param
support directly.

**Batch 1 (landed)**: template-extension feature deletion, exactly as
scoped above, plus the `CategoryRepository`/`CategoryService` dead-code
chain it exposed and 6 pre-existing Browser tests found asserting
against pre-P37/P38/P39 template shapes (fixed, not deferred).

**Batch 2 (landed)**: the mechanism (`Core\View`, `Template\Latte\
Attribute\Template`, `Template\Renderer`, `Template::renderView()`/
`appendOutput()`/`indexButtons()`) plus `index.latte` + `include/
selected_tags.inc.latte` converted to `{templateType}`, replacing
`GalleryPageContext`/`GalleryThumbnailsPageContext` with one merged
`Controller\Projection\IndexView` (+ `SelectedTagsView`). Two real
corrections found only once `index.latte`'s actual body was
grepped, not assumed from its old `{varType}` prelude:

- **`U_CANONICAL` is shell data, not body data.** `header.latte`'s own
  `<link rel="canonical">` (`isset($U_CANONICAL)`) renders while
  `PageHeaderRenderer::render()` parses `header.latte` — before
  `GalleryController` ever constructs its `IndexView`, whose render
  happens too late for `header.latte` to see it. `uCanonical`/
  `useStandardPages` (the latter has no real template reader anywhere
  in the app — corpus-wide-fallback noise) both stay off `IndexView`;
  `U_CANONICAL` gets its own single-field `Controller\Projection\
  CanonicalUrlPageContext`, assigned via the old ambient
  `assignContext()` mechanism at the same point in the method
  `GalleryPageContext` used to be built, before `PageHeaderRenderer`
  runs.
- **`VariableMapBuilder`'s `{templateType}` branch doesn't need a
  hardcoded ambient table.** The design section above lists
  `ROOT_URL`/`ROOT_PATH`/`themeconf`/`themes`/`lang_info` as globals a
  View's data merges with at runtime, but `index.latte`'s own body also
  references several more ambient names with no IndexView property at
  all (`MENUBAR`, `CATEGORIES`, `CONTENT`, `THUMBNAILS`, `chronology`,
  `chronology_views`, `favorite`, `QUERY_SEARCH`, `SEARCH_ID`, the
  `PLUGIN_INDEX_CONTENT_*` slots) — all assigned by sibling renderers
  (`MenubarRenderer`/`CategoryCatsRenderer`/`CategoryDefaultRenderer`/
  `SearchFilterRenderer`/etc.) that stay completely untouched by this
  batch. Actual fix: `VariableMapBuilder`'s `{templateType}` branch
  populates a `{templateType}` template's `byTemplate` entry from the
  View's own reflected public properties (via `ContextVariableExtractor
  ::propertyTypes()`, widened to `public`) and leaves `VariableMap::
  forTemplate()`'s existing fallback-union + `$globals` merge
  completely unchanged — those sibling renderers' own context classes
  are still live, unconverted `TemplatePageContext`s, so they keep
  contributing to the same corpus-wide fallback every other template
  already draws on. No new hardcoded list to keep in sync as more
  page-families convert.

`VarTypeSyncer`/`PhpStanLatteSyncVarTypeCommand` (`lint:vartype`/
`lint:vartype:fix`) are **not** deleted by this batch, despite the
"Delete the tooling this obsoletes" line above — that line describes
the *end* of the whole P40 campaign, once every template has converted
and there's no classic per-template `{varType}` corpus left to sync.
Mid-campaign, the 128 still-unconverted templates' `{varType}` blocks
keep drifting as their own corpus-wide fallback union changes (e.g.
losing `GalleryPageContext`/`GalleryThumbnailsPageContext`'s fields
once Batch 2 deleted them), so `VarTypeSyncer` stays live for the
length of the campaign — it just gained one new rule: skip (no-op) any
template whose raw source already contains `{templateType ...}`,
instead of prepending a second, redundant block onto it.

Batch 1+2 verified: `composer lint:latte` clean (130 files);
`analyse:phpstan` 0 errors; `lint:php` (ECS) 0 errors; `deptrac
analyse` 0 violations; `lint:vartype` 0 drift (128 templates'
`{varType}` blocks shrank once `GalleryPageContext`/
`GalleryThumbnailsPageContext` left the fallback union, `index.latte`/
`selected_tags.inc.latte` untouched); a new round-trip test
(`ViewTemplateTypeRoundTripTest`) confirming every `View`'s
`#[Template]` file declares `{templateType}` back at that same class;
`test:golden-html` 73/73 byte-identical; `test:visual` 65/65 (66 minus
the one Batch 1 deleted); `composer test` (Unit/Arch) 5695 passed;
`test:integration` 2119 passed; `tests/Browser/GalleryControllerTest.php`
20/20 (the real regression net for this conversion — exercises the
`U_MODE_FLAT` clear, `SELECTED_TAGS_TEMPLATE` conditional render,
canonical URL and `IndexRendered` event wrinkles through a real
browser request, not just static output diffing).

**Batch 3 (landed)**: the admin `ADMIN_CONTENT` renderer sweep — every
remaining conversion candidate whose controller/renderer called
`Template::assignVarFromTemplate('ADMIN_CONTENT', …)` (directly, or via
a page-family's own `*PageRenderer`), one page-family at a time:
`Rating`, `CatPerm`, `UserActivity`, `ElementSetRanks`, `LanguagesNew`,
`ThemesNew`, `ThemesStandardPages`, `UserList`, `PluginsInstalled`,
`CatList`, `RatingUser`, `PluginsNew`, `AlbumNotification`,
`ThemesInstalled`, `Stats`, `Albums`, `CatModify`, `PictureModify`,
`BatchManagerUnit`, `BatchManagerGlobal`, `UpdatesPwg`, and
`ConfigurationSubController` (22 renderers total). Same per-conversion
pattern throughout: one new `#[Template]` View class, dead fields
dropped (verified against the template body and any paired `.js`
file's `pwg_getPageData()` reads), the old context class plus its own
unit test deleted, callers updated to inject `Renderer`.

`ConfigurationSubController` (last in the batch) needed two extra
wrinkles no earlier conversion did: its 7 tabs each needed their own
View class, selected via a `match` on `$page['section']` since
`#[Template]` requires one fixed compile-time string per class; and
its two POST-handler methods (`processSizes()`/`processWatermark()`)
were changed to *return* a plain internal DTO instead of mutating
template state directly, since each tab's field set is populated from
two different call sites (the POST handler and the main render-time
switch) in the same request.

`grep -rn "assignVarFromTemplate('ADMIN_CONTENT'" src/Piwigo` now
returns zero real call sites (one docblock comment reference in
`SettingsPageInterface.php` only) — this exhausts the pool this batch
targeted. The front-end and remaining non-`ADMIN_CONTENT` page
-families (44 `TemplatePageContext` classes still live, confirmed via
`grep -rl "implements TemplatePageContext" src/Piwigo`) are open for a
future batch, not yet scoped.

Validation deferred to a later checkpoint per explicit direction —
each conversion this batch verified only by `php -l` plus the
narrative-docblock grep sweep at commit time, not the full
`lint:latte`/`analyse:phpstan`/`lint:php`/`deptrac`/`test:*` gate list.
That full pass is still owed before this batch can be marked verified.

**Batch 4 (landed), Batches 5–9 (scoped, not yet executed)**: the remaining 44
`TemplatePageContext`-implementing classes, traced one by one against
their real caller and real template body — not assumed from class
names — to find which are genuine page-family work versus P41 shell
territory versus something else entirely. Two corrections this pass
found in what Batch 3's own text claimed:

- **The `ADMIN_CONTENT` pool wasn't actually exhausted.**
  `Admin\Integrity\CheckIntegrity.php:272` produces admin page content
  via a third call shape neither swept: `Template::concat('ADMIN_CONTENT',
  $template->parse('check_integrity.latte', true))`, not
  `assignVarFromTemplate('ADMIN_CONTENT', …)`. Re-grepping for every
  remaining `->parse(`/`assignVarFromTemplate(`/`->pparse(` call site
  app-wide (not just the one `ADMIN_CONTENT` shape) is what surfaced
  this and everything below.
- **`themes/default/template/search.latte` and `search_rules.latte`
  were dead code**, not conversion candidates. `SearchController`'s own
  docblock says it only builds a `$search` descriptor and redirects,
  never renders; a repo-wide grep for `'search.latte'`/`search_rules`
  found zero real callers anywhere in `src/` or cross-template
  `{include}`. **Deletion review resolved (2026-08-20): deleted both
  files.** Re-confirmed before deleting: `SearchController::__invoke()`
  always calls `$this->redirectService->redirect($search_url)` and
  never renders; the golden-html/VR `'search'` fixture
  (`VisualRegressionRoutes.php`) hits `/search.php` with
  `CURLOPT_FOLLOWLOCATION` on, so it was always capturing the
  redirect's landing page, never `search.latte` itself; `search.latte`
  doesn't even `{include}` `search_rules.latte` — both were separate,
  independently-unreachable standalone pages. `search_filters.inc.latte`
  (the live sidebar search widget, already converted in Batch 6) is
  unrelated despite the similar name. Verified after deletion: `php
  bin/piwigo phpstan-latte:compile` (2 stale outputs pruned, 128
  templates, 0 errors), `composer lint:latte` (128 files, 0 errors),
  full `composer analyse:phpstan`, `vendor/bin/deptrac analyse` (0
  violations), `composer lint:php` — all clean.

**Batch 4 (landed) — Picture page's remaining ambient fragments.** All
6 context classes (`PictureCommentsOrderPageContext`,
`PictureCommentListPageContext` — found during implementation, not in
the original scoping above — `PictureCommentAddPageContext`,
`PictureMetadataPageContext`, `PictureRateSummaryPageContext`,
`PictureRatingFormPageContext`) folded onto `PictureView`/
`SlideshowView` as new properties, landed as 3 commits (one per
renderer: `PictureMetadataRenderer`, `PictureRateRenderer`,
`PictureCommentRenderer`). `PictureMetadataRenderer`/`PictureRateRenderer`
now return their own small result types (`?array`,
`PictureRateResult`) instead of calling `assignContext()`; both lost
their now-unused `CurrentTemplate` constructor dependency entirely.
`PictureCommentRenderer` returns a `PictureCommentsResult` bundling
all 6 of its own fields. `PictureCommentRenderer`'s own use of
`comment_list.latte` (`assignVarFromTemplate('COMMENT_LIST',
'comment_list.latte')`) switched to the `CommentListView` class that
**already existed** — built for `CommentsController`'s own, separate,
already-converted use of the same template —
`CommentListView::$commentDerivativeParams` widened to nullable to
cover `PictureCommentRenderer`'s own comment rows, which never carry a
`src_image` (already looking at the one photo above, no per-comment
illustration needed) — the template's own `isset($commentDerivativeParams)`
guard already anticipated this. `picture.latte`'s body renamed to
match throughout, including converting one bare
`{include 'navigation_bar.latte'}` (relying on inherited scope) to an
explicit `navbar: $commentsNavbar` param, matching every other real
call site of that template. Verified end-to-end each commit: `php -l`
+ `composer analyse:phpstan` + `lint:latte` + `picture-1`/`slideshow`
golden-html unchanged for the first (Metadata) commit; `php -l` +
`lint:latte` only for Rate/Comments per this session's "skip
validation" direction at the time. **The deferred full pass has since
run**: a full `composer test:golden-html` caught one real regression
from the Rate commit — `picture.latte`'s "Average" rating-score block
(`{if $displayInfo['rating_score'] and isset($rate_summary)}` and its
two `$rate_summary[...]` reads inside) was left on the pre-conversion
snake_case name instead of the renamed `$rateSummary` property, so
`isset()` was always false and the block silently stopped rendering.
Fixed in a standalone commit (`0dd8d9008c`); full suite green
afterward (73/73 golden-html).

**Batch 5 (landed) — small, bounded, 1–2-caller fragments.**
`check_integrity.latte` (2 callers: `IntroSubController`'s dashboard
page, `MaintenanceActionDispatcher`) converted to `CheckIntegrityView`
— `CheckIntegrity::display()` now returns a plain `CheckIntegrityResult`
DTO (same data-returning-method split as `PictureMetadataRenderer`/
`PictureRateRenderer`, needed because `CheckIntegrityTest` inspects raw
pre-render anomaly data, not `Html`); `IntroSubController` constructs
`CheckIntegrityView` and renders it, combining the `Html` with the
existing admin content before assigning `AdminContentPageContext`.
`MaintenanceActionDispatcher`'s own `CheckIntegrity` construction
(calls `maintenance()`, never `display()`) dropped its now-stale
`CurrentTemplate` arg. `no_photo_yet.latte`
(`NoPhotoYetRenderer`, already has its own `Request`/`Event`
scaffolding — the 2 context variants, `NoPhotoYetAdminPageContext`/
`NoPhotoYetGuestPageContext`, merged into one `NoPhotoYetView`) also
landed. The admin theme's own `popuphelp.latte` turned out to be
**already converted** — `AdminPopuphelpController` already renders a
shared `PopuphelpView` (theme-chain-resolved, same class the front-end
`PopuphelpController` uses), landed earlier this session in commit
`5cef717009`, before this batch's scoping text was written; no new
work needed there. `redirect.latte` stays deferred/optional per this
batch's original note (crash-path code, out of scope here). Verified
end-to-end: `php -l`, scoped `composer analyse:phpstan`, `composer
lint:latte`, `composer lint:php`, the 34 relevant `composer
test:integration` tests (`CheckIntegrity`/`C13yInternal`/
`MaintenanceActionDispatcher`), and a full `composer test:golden-html`
(73/73) — all green.

**Batch 6 (landed) — `index.latte`'s remaining ambient contributors.**
`thumbnails.latte` (`CategoryDefaultRenderer`) and `mainpage_categories.latte`
(`CategoryCatsRenderer`) both converted to real `View`/`Renderer`
fragments: `Piwigo\Category\*` is L2aCoreDomain and may not depend on
`Renderer`/`View` (L3Presentation) directly, so both renderers now
return a plain result DTO (`CategoryDefaultResult`/`CategoryCatsResult`)
instead of rendering internally, and `GalleryController` (always L3/L4)
constructs the real `ThumbnailsView`/`CategoryCatsView`, renders it,
and writes the `Html` into `Template::$vars['THUMBNAILS']`/`['CATEGORIES']`
via a new one-field `ThumbnailsHtmlPageContext`/`CategoryCatsHtmlPageContext`
— `assignContext()` stays the sole way anything writes into the
template, so a bare already-rendered `Html` value still needs this
one-field wrapper, matching `CanonicalUrlPageContext`'s own established
shape. `CategoryCatsNavbarPageContext` (the separate `cats_navbar`
ambient var) needed no change: it's a plain `assignContext()` call
with no rendering involved, which `TemplateInterface` already lets an
L2a/L2b class call directly.

The search widget fragments (`SearchFilterPageContext`/
`SearchAlbumsFoundPageContext`/`SearchDateFilterPageContext`/
`SearchTagsFoundPageContext`, feeding `include/search_filters.inc.latte`,
not the dead `search.latte`) converted the same way — `Piwigo\Search\*`
is L2bExtendedDomain, same constraint. `SearchFilterRenderer::render()`
now returns a `SearchFilterResult` (the resolved search id, unrelated
to the sidebar itself, plus a nullable `SearchFilterData` bundling all
19 sidebar fields across what used to be 4 separate `assignContext()`
calls from `render()` and its 3 private helpers). `index.latte`'s old
`{if !empty($SEARCH_ID)}{include 'include/search_filters.inc.latte'}{/if}`
pair became `{if !empty($SEARCH_FILTERS)}{$SEARCH_FILTERS}{/if}`,
matching `CATEGORIES`/`THUMBNAILS` — kept as a 3-line `{if}\n{$var}\n{/if}`
block rather than one line, since Latte's own tag-alone-on-its-line
whitespace trimming shifted compiled output by a blank line when tried
as one line (caught by golden-html, harmless but worth matching
byte-for-byte). `index.latte`'s own separate `{elseif !empty($SEARCH_ID)}`
"no results" branch (a distinct use of the same raw search-id string)
needed a new `IndexView::$searchId` property, since a rendered `Html`
blob can't expose that value back to `index.latte`'s own body the way
ambient `Template::$vars` used to.

`SectionFavoritePageContext` turned out **not to be a render
conversion candidate at all**, on inspection: unlike the other three,
nothing ever calls `assignVarFromTemplate()`/`parse()` for it — it's
pure ambient data (`SectionPopulator::populate()`'s own `Section::Favorites`
branch feeds `index.latte`'s direct `{$favorite['U_FAVORITE']}` body
reference, already converted in Batch 2), the same permanent-ambient
shape `CanonicalUrlPageContext` already establishes. `SectionPopulator`
is L2bExtendedDomain and computes this value deep inside a much larger
method with no channel back to `GalleryController` other than the
ambient assign; folding it into `IndexView` would mean duplicating
that computation or reshaping `SectionPopulator`'s own public contract
for one field. Left as-is — correctly excluded from this batch, not
a missed item.

Verified end-to-end per fragment: `composer analyse:phpstan`,
`lint:latte`, `lint:php`, the relevant `composer test:integration`/
`composer test:browser` suites, a full `composer test` (Unit+Arch), a
full `composer test:golden-html`, and a full `composer test:visual` —
all green (two confirmed-unrelated single-test flakes along the way,
both non-reproducing in isolation, matching this session's established
flaky-test handling).

**Batches 7–9 and the include-only-partials open question (landed).**
All four landed in the same "keep pushing, no validation" push: full
`composer test`/`test:golden-html`/`test:visual` validation is a
still-owed checkpoint for this whole span (Batches 7–9 + the 3
contract-only conversions), same as Batch 4's own precedent of
deferring the expensive suite while iterating fast on `php -l` +
scoped `composer analyse:phpstan` + `phpstan-latte:compile` per
change.

- **Batch 7 (Menubar)**: `Piwigo\Menu\*` is L3Presentation (may depend
  on `Renderer`/`View` directly, unlike the L2a/L2b split Batch 6
  needed) — `BlockManager::apply()` renders `menubar.latte` itself via
  a new `MenubarView`, dropping its now-meaningless `$var`/`$file`
  params (exactly 1 real caller, `MenubarRenderer::render()`, always
  `'MENUBAR'`/`'menubar.latte'`). The 7 real sub-block templates
  (`menubar_links.latte`, etc.) are contract-only conversions sharing
  one `MenubarBlockView` (`block: DisplayBlock, id: string`) — reached
  only via `menubar.latte`'s own native `{include $block->template,
  block: ..., id: ...}`, never `Renderer::render()`. `BlockManager`'s
  new `Renderer` dependency threaded through `MenubarRenderer::render()`
  (11 real Controller call sites) and `MenubarPageRenderer::render()`.
  Deleted the now-dead `MenubarBlocksPageContext` + its test.
- **Batch 8 (Tabsheet)**: same L4Integration-may-depend-on-L3 shape —
  `Tabsheet::assign()` renders `tabsheet.latte` via a new
  `TabsheetView`, writing the `Html` into `Template::$vars` under
  `Tabsheet::$name`'s own genuinely-dynamic ambient key (kept dynamic,
  not hardcoded, even though all 29 real `new Tabsheet(...)` call
  sites use the bare no-args constructor — same judgment
  `TabsheetPageContext` already made for `$titlename`, the one field
  it still carries). Threading the new `Renderer` param through all 29
  call sites caught a real bug: a first mechanical pass wired 4 sites
  (`CatListPageRenderer`/`CatOptionsPageRenderer`/
  `GroupListPageRenderer`/`TagsPageRenderer`) with a bare `$renderer`
  instead of their own `$this->renderer` property — an
  undefined-variable error PHPStan caught before it ever ran.
  `TemplateCallSiteScannerTest`'s admin-scoping test lost its last
  real fixture (P40's admin sweep across Batches 3/5/7/8 converted
  every real `Piwigo\Admin\*` `assignVarFromTemplate()`/`parse()` call
  site in the repository) — rebuilt synthetically, matching the same
  test file's own "widens to the full tree" precedent.
- **Batch 9 (Calendar)**: `month_calendar.latte` is never rendered via
  `Renderer::render()` at all — `CalendarRenderer` only passes its
  filename as a string (`CalendarChronologyPageContext::
  $fileChronologyView`), which `index.latte`'s own body turns into a
  bare `{include $FILE_CHRONOLOGY_VIEW}` (full parent-scope
  inheritance). Contract-only `MonthCalendarView`, with a real
  wrinkle: its property names stay **snake_case**
  (`chronology_calendar`, `chronology_navigation_bars`), matching the
  actual ambient `Template::$vars` keys verbatim — inherited-scope
  names can't be renamed without touching the classes that assign
  them, unlike a real View's `get_object_vars()` merge.
- **Include-only partials** (the prior turn's own open question,
  resolved and landed): `navigation_bar.latte` (both theme variants,
  one shared `NavigationBarView` — 11 real call sites, 10 pass
  `navbar: $x` explicitly, `comments.latte`'s own one bare `{include}`
  relies on inherited scope from `CommentsView::$navbar` instead, same
  single dependency either way), `picture_nav_buttons.latte`
  (`PictureNavButtonsView`, all 7 fields already on both
  `PictureView`/`SlideshowView`), `infos_errors.latte`
  (`InfosErrorsView`, fed by the cross-cutting ambient
  `PageMessagesContext`, not tied to any one page's View). All 3 are
  contract-only, same shape as `MenubarBlockView`.

Every one of these ~10 new/touched View-adjacent classes was verified
with `php -l`, a full `phpstan-latte:compile`, and a scoped `composer
analyse:phpstan` on every touched PHP file — 0 errors throughout.

**Post-Batch-9 sweep (landed): full-suite validation + 5 real bugs found.**
Running the deferred full `composer analyse:phpstan` (project-wide, not
scoped) surfaced findings scoped per-file checks structurally can't
catch, since `shipmonk/dead-code-detector`'s dead-property/dead-method
analysis needs the whole call graph:

- **5 contract-only View classes read only via reflection.**
  `MenubarBlockView`/`MonthCalendarView`/`NavigationBarView`/
  `PictureNavButtonsView`/`InfosErrorsView` are never
  `Renderer::render()`-ed or constructed by any in-tree PHP — their
  sole reader is `VariableMapBuilder`'s own `ReflectionClass(...)
  ->getProperties()` walk, invisible to shipmonk's built-in
  `ReflectionUsageProvider` (which only tracks a statically-known
  class name, not a runtime string read back from a `{templateType}`
  scan). Rather than a blanket path-based `ignoreErrors` suppression,
  built `tools/phpstan/TypedViewPropertyUsageProvider.php`, a
  `ReflectionBasedMemberUsageProvider` mirroring the existing
  `GessoHookMethodUsageProvider` pattern — recognizes any class that
  appears as a `{templateType}` target across the whole `.latte` tree
  as having every public property "read," while still catching a
  genuinely dead property on any other class.
- **`TemplateInterface` shrunk to `assignContext()` only** —
  `assignVarFromTemplate()`/`clearAssign()` had zero remaining
  L1/L2a/L2b callers once Batches 7–9 finished; both stay as concrete
  `Template` methods (still used internally and by L3/L4), only the
  interface contract and their now-inapplicable `#[Override]`
  attributes dropped.
- **`CheckIntegrityTest`'s stale extra constructor arg** — a leftover
  6th `Renderer` arg (constructor only takes 5) that PHP silently
  tolerated (extra positional args are not a runtime error, unlike too
  few) — invisible to every test run, only caught by PHPStan's
  `arguments.count`.
- **A real deptrac violation**: `CommentListView` lived in
  `Piwigo\Controller\Projection` (L4Integration), but
  `PictureCommentRenderer` (L3Presentation) constructs and renders it
  directly — L3 can't depend upward on L4. Fixed by relocating the
  View to `Piwigo\Picture\Projection` (L3), the same layer as its L3
  caller, rather than reclassifying the layer boundary — including
  updating `comment_list.latte`'s own `{templateType}` declaration to
  match (a real "moving a View class needs both the PHP namespace and
  every referencing `.latte` file's `{templateType}` line updated"
  gotcha, only caught by a subsequent full-suite run flagging
  `ViewTemplateTypeRoundTripTest`).
- **`MenubarRendererTest` (Unit)** missed Batch 7's own call-site grep
  because it resolves `MenubarRenderer::render()`'s params from the
  container rather than a literal `new MenubarRenderer(...)` call —
  only surfaced via a full `composer test` run ("too few arguments").

Also fixed two narrower type/assertion issues caught by the full
PHPStan run (`PictureCommentRendererTest::renderedComments()`'s
docblock was `array<string, mixed>|null`, should have been
`list<array<string, mixed>>|null`, making its `array_values()` call
masking-not-fixing the wrong type; a few now-redundant
`assertIsArray()`/`assertIsString()` calls PHPStan had already
narrowed away) and rebuilt `ContextVariableExtractorTest`'s "dynamic
array-dim assignment" fixture (`TabsheetPageContext` lost that exact
AST shape when Batch 8 shrank it, leaving no real production class
exercising the test).

Full validation now green end-to-end: `composer analyse:phpstan` (0
errors), `lint:latte`, `lint:php`, `vendor/bin/deptrac analyse` (0
violations), `composer test` (5532 Unit+Arch), `composer test:golden-html`
(73/73), `composer test:visual` (65/65), plus the relevant
`test:browser`/`test:integration` suites — this whole span (Batches
7–9 + include-only partials + this sweep) is fully closed out.

**Batch 7 (landed) — Menubar, smaller than it first looked.** Only 2 real call
sites construct a `BlockManager` at all (`Menu\MenubarRenderer`, the
front-end menubar; `Admin\MenubarPageRenderer`, the admin menu editor),
both routing through the single `BlockManager::apply()` method — that
one method is the whole choke point, not 29 call sites to audit.
`menubar.latte`'s own real body (past its `{varType}` prelude) is 9
lines: `{include $block->template, block: $block, id: $id}` per block
in a `foreach` — Latte's own native dynamic include, resolved by that
literal string field on `DisplayBlock`, with **no** `Renderer`/
`#[Template]`-attribute lookup involved (that reflection path only
fires for `Renderer::render(View)` calls; a bare `{include $variable}`
never touches it). Each of the 7 sub-block templates
(`menubar_links.latte`, `menubar_categories.latte`,
`menubar_related_categories.latte`, `menubar_tags.latte`,
`menubar_specials.latte`, `menubar_menu.latte`,
`menubar_identification.latte`) receives the same explicit 2-arg
`block: DisplayBlock, id: string` include call — but **not actually
isolated scope**, a correction to this section's own earlier claim
(see the follow-up resolution below). `DisplayBlock` is already a
real typed class, not a raw array, so each sub-block's own tiny View
is a 2-property wrapper around it. `menubar.latte` itself becomes
`{templateType MenubarView}` with one property, `blocks: list<DisplayBlock>`
(what `MenubarBlocksPageContext` already carries); `BlockManager::apply()`
renders it via `Renderer::render()` and assigns the resulting `Html`
into the same ambient `$var` (`'MENUBAR'`) it does today, so every
already-converted page that prints `$MENUBAR` needs no change at all.

**`MenubarIdentificationPageContext` follow-up (resolved, 2026-08-20):**
this section originally flagged two open questions — whether
`{include $block->template, block: ..., id: ...}` is truly isolated
scope, and what shape `DisplayBlock::$data` holds for the
identification block. Both traced directly against the real code:

- **Latte's `{include}` is never actually isolated.**
  `IncludeFileNode::print()` (`vendor/latte/latte/src/Latte/Essential/
  Nodes/IncludeFileNode.php:57`) compiles every `{include file, args...}`
  to `$this->createTemplate($file, $args + $this->params, $mode)` —
  the explicit `$args` are PHP-array-unioned (`+`) with `$this->params`,
  the *current* template's own full variable set, with `$args` only
  winning on key collision. So `{include $block->template, block: ...,
  id: ...}` hands the sub-template `block`/`id` **plus every other
  ambient var already in `Template::$vars` at that point** — not a
  fresh isolated scope. This corrects both this section's own earlier
  "isolated scope" claim and the near-identical claim in the
  include-only-partials section below; those conclusions happened to
  be harmless (the templates in question only ever read the explicitly
  -passed names), but the "isolated vs. full parent-scope" framing
  itself was wrong throughout.
- **`DisplayBlock::$data` is simply never set for the identification
  block.** `MenubarRenderer::render()` only sets
  `$block->template = 'menubar_identification.latte'`
  (`src/Piwigo/Menu/MenubarRenderer.php:392`) — no `$block->data =
  ...` line anywhere for this block. Every real field the template
  needs (`$USERNAME`, `$U_LOGIN`, `$U_LOST_PASSWORD`,
  `$AUTHORIZE_REMEMBERING`, `$U_REGISTER`, `$U_PROFILE`, `$U_LOGOUT`,
  `$U_ADMIN`, plus `menubar_categories.latte`'s own `$U_START_FILTER`/
  `$U_STOP_FILTER`) reaches the template purely through the ambient
  ↔`+ $this->params` merge above, sourced entirely from
  `MenubarIdentificationPageContext`'s own `assignContext()` call
  (`MenubarRenderer.php:395`).

Net finding: `MenubarIdentificationPageContext` is **not a render
-conversion candidate at all** — nothing ever calls
`assignVarFromTemplate()`/`parse()` for it, the same permanent
-ambient shape `CanonicalUrlPageContext`/`SectionFavoritePageContext`/
`CategoryCatsNavbarPageContext`/`CalendarChronologyPageContext`
already establish. No code change needed; this closes the open
follow-up.

**Batch 8 (landed) — Tabsheet, same shape as menubar.** `Tabsheet::assign()`
is the single choke point (constructing `new Tabsheet(...)` happens at
29 call sites, but they all just call `->assign($currentTemplate)` —
none of them touch template rendering directly). `tabsheet.latte`'s
real body needs exactly 2 variables, `$tabsheet` (list of
`{url, caption}`) and `$tabsheet_selected` — both already lowercase,
no further renaming needed. One wrinkle: `Tabsheet`'s own constructor
takes a `$name` that defaults to `'TABSHEET'` but is caller
-overridable, and `assign()` uses `$this->name` as the ambient
`$var` it assigns into — confirm at implementation time whether any of
the 29 call sites actually override it away from the default before
assuming every call site's output lands in the same well-known var.

**Batch 9 (landed) — Calendar.** `month_calendar.latte` is never rendered via
`Template::parse()`/`assignVarFromTemplate()` at all —
`CalendarRenderer` only ever passes its filename as a **string value**
(`CalendarChronologyPageContext::$fileChronologyView`), which whatever
consumes that ambient var (`index.latte`'s own body) turns into its
own `{include $FILE_CHRONOLOGY_VIEW}` — the same dynamic-include-by
-ambient-var shape as Batch 7's menubar blocks, just one level up.
Convert the same way: `{templateType MonthCalendarView}` on the
template, no `Renderer::render()` call needed from `CalendarRenderer`
itself.

**Resolved open question (landed): `{templateType}` on include-only partials.**
Last turn's scoping flagged this as unresolved for
`navigation_bar.latte`/`picture_nav_buttons.latte`/`infos_errors.latte`.
Checked directly: `navigation_bar.latte`'s real body only references
`$navbar` (one array), and its 7 real call sites
(`{include 'navigation_bar.latte', navbar: $cats_navbar}` in
`index.latte`, `picture.latte`, `comments.latte`, and 3 admin
templates) each pass exactly that one variable — so `{templateType
NavigationBarView}`'s single `navbar` property is a complete, accurate
contract for this template's real reads. (Correction, 2026-08-20: this
was originally described as Latte's `{include}` using "isolated
scope" here — it doesn't; `{include}` always merges the explicit args
with the current template's own full variable set, per
`IncludeFileNode::print()`, see the Batch 7 follow-up resolution
above. The conclusion still holds because `navigation_bar.latte`
simply never reads anything beyond `$navbar`, not because the scope
was ever actually isolated.) `{templateType}` is still meaningful and worth
doing here: the round-trip check only requires the declared class to
implement `View` and carry a matching `#[Template]` attribute pointing
back at the same file — nothing requires `Renderer::render()` to ever
actually be called for it. So these become **contract-only**
conversions: a tiny `View` class + `{templateType}` on the template,
with zero `Renderer`/`Html`-wrapping PHP change, purely to let
`VariableMapBuilder`'s reflection branch replace the corpus-wide
fallback-union noise these templates currently carry (`navigation_bar.latte`'s
own `{varType}` prelude, for instance, declares `$watermark`/
`$watermark_files`/`$warning_tags` and dozens more names it never
actually references). Same treatment applies to `picture_nav_buttons.latte`
and `infos_errors.latte` once their own real call-site variables are
confirmed the same way at implementation time.

**Mail domain (landed, 2026-08-20) — folded into P40 as its own
batch.** `MailService::getMailTemplate()` constructs a wholly separate
`Template` instance per send, rooted at `template/mail/{format}` — 13
real files under `themes/default/template/mail/text/{plain,html}/`
(`header.latte`/`footer.latte`/`cat_group_info.latte`/
`notification_by_mail.latte`/`notification_admin.latte`, plus
`mail-css-{clear,dark}.latte`/`global-mail-css.latte` for the HTML
format only — 14 was this section's own original count, off by one
against the real tree), no shared header/footer-as-web-chrome concept
and no `AdminShell` dispatch to piggyback on.

Scope decision: convert only the 3 real render-triggering CONTENT
templates (`notification_by_mail.latte`, `notification_admin.latte`,
`cat_group_info.latte`) to `View`/`Renderer` — `header.latte`/
`footer.latte`/the CSS fragments stay on ambient `assignContext()`,
the same P40/P41 shell boundary already established for the web
header/footer. New `Piwigo\Mail\Projection\NotificationByMailView`/
`NotificationAdminView`/`CatGroupInfoView` (merging
`NbmMailContentPageContext`+`NbmSubscribeActionMailContext`+
`NbmNewsMailContext` into the first, `MailRuntimeTemplatePageContext`
into the other two — all 4 deleted).

Mechanism wrinkle: mail's own `Template` instance is per-call, not the
ambient `CurrentTemplate` `Renderer::render()` binds to, so these 3
Views render via `Template::renderView()` directly with an explicit
bare filename — `Renderer::render()`'s own `#[Template]`-attribute
resolution never runs for them. Each `#[Template]` instead points at
the file's path relative to `themes/default/template/` (e.g.
`'mail/text/html/cat_group_info.latte'`), which satisfies
`ViewTemplateTypeRoundTripTest`'s own prefix-based resolution and
disambiguates from an unrelated same-basename file elsewhere in the
tree (`notification_by_mail.latte` also names the admin UI page, a
different, already-converted class). One class serves both a
template's html and plain variant (identical property shape) since
the attribute is read only by the round-trip test/`VariableMapBuilder`
here, never at runtime by `Renderer`.

`MailService::mail()`'s own generic `$tpl['filename']`/`'assign'`
runtime-template mechanism (used by `mailNotificationAdmins()`,
`Admin\Extensions\CoreUpdateService`, `Admin\AlbumNotificationPageRenderer`)
now resolves through a new `buildRuntimeTemplateView()`, a `match()`
on the exactly 2 real in-tree filenames (confirmed via exhaustive
`grep -rn "'filename' =>"`) instead of a file-existence lookup +
untyped `assignContext()` — the public `mail()`/`mailAdmins()`/
`mailGroup()` `$tpl` contract itself is otherwise unchanged, so
`AlbumNotificationPageRenderer`/`CoreUpdateService` needed zero
changes. Also dropped the fully-unused `'dirname'` key from that same
`$tpl` contract (zero real callers ever set it) across
`MailerInterface`/`MailService`/`SendNotificationEmailJob`/
`ExtensionContext`.

`NotificationByMailSender`'s own 2 direct `parse()` call sites
(subscribe/unsubscribe and news) now construct `NotificationByMailView`
directly; `assignVarsNbmMailContent()` (void, ambient-writing) became
`nbmMailContentFields()` (private, pure, Reflection-tested the same
way `MailService::resolveMailTheme()` already is).

Full-suite validation: `composer analyse:phpstan`/`deptrac analyse`/
`lint:latte`/`lint:php` all clean; full `composer test` (5524 passed);
`composer test:integration -- --filter=Mail` (128 passed, including
`MailGoldenHtmlSnapshotTest`'s byte-exact comparison against all 13
real mail template files' committed baselines — confirms
byte-identical output pre/post conversion); `composer test:browser --
--filter="NotificationByMailSubControllerTest|AlbumNotificationPageRendererTest"`
(20 passed). Two now-stale test fixtures found and fixed via the full
suite: `TemplateCallSiteScannerTest`'s Mail-scoping test (`Notification
ByMailSender`'s own `parse()` call sites went extinct converting to
`renderView()`; `MailService::mail()`'s own header/footer `parse()`
calls are the real remaining fixture) and `ContextVariableExtractorTest`'s
loose "real context class pool" floor (30 exactly now, was `>30`).

**Confirmed P41 (shell) scope, not new P40 work**: `header.latte`/
`footer.latte`/`admin.latte` and their context classes
(`PageHeaderPageContext`, `PageTailPageContext`,
`AdminShellFramePageContext`, `AdminShellPostDispatchPageContext`,
`AdminContentPageContext`, `AlbumSubControllerPageContext`,
`CanonicalUrlPageContext`, `HeaderMessagesPageContext` — assigned by
`Bootstrap\RequestBootstrap` itself, before any controller runs —
and `PageMessagesContext`, assigned by `HtmlService` for the same
header message banner). `install.latte` is already named in P41's own
text below ("`InstallWizard` stop[s] echoing"), so `InstallRenderPageContext`
is not new scope either. The 4 `BatchManager*` ambient contributors
(`FilterPanelPageContext`, `BatchManagerFilterOptionsPageContext`,
`BatchManagerNoSearchResultsPageContext`, `BatchManagerSearchDebugPageContext`)
are a deliberate design choice already established in Batch 3 (ambient
merge into the already-converted `batch_manager_{unit,global}.latte`),
not a gap — converting them is optional future polish, not blocking.

**P41 — Shell-last rendering, `PageState` split, and asset-pipeline
cutover.** Two corrections to the original scope text above, both
verified directly against the real code: admin's "48
`assignVarFromTemplate('ADMIN_CONTENT', …)` call sites" is stale —
already zero, since P40's Batch 3 converted every real admin
sub-controller to `Renderer::render()` wrapping the result in
`AdminContentPageContext`; what's left is just `admin.latte` itself
(P41-C), not 48 call sites. The asset-pipeline swap
(`CssLoader`/`ScriptLoader` → P36's dormant `PageAssets`) is folded into
this same plan as Part 2 (P41-G/H below), not a separately-numbered
future track — `AssetContribution::script()`/`::css()`'s factory params
map 1:1 onto `Template::combineScript()`/`combineCss()`'s existing
params, and the swap happens entirely inside those two methods, with
zero `.latte` file changes.

**The mechanism**: `{layout '…'}` (Latte's own `{extends}` alias) shares
the *same* variable scope between a child template and the layout it
extends (traced through `Latte\Runtime\Template::render()` directly,
not just the compile-time node) — so the existing ambient-merge design
(`Template::renderView()`'s `[...$this->vars, ...get_object_vars($view)]`)
already generalizes to it with no new classes: `PageHeaderPageContext`/
`PageTailPageContext`/`AdminShellFramePageContext`/etc. keep being built
and `assignContext()`-ed exactly as before, and `Renderer::render($pageView)`
renders the whole page in one shot because `$pageView`'s own template
now declares `{layout '…layout.latte'}` and wraps its body in
`{block content}…{/block}`. Transition is incremental via dual methods:
`PageHeaderRenderer`/`PageTailRenderer` each split into a
`prepareContext()` half (kept) and a `@deprecated`-tagged old
`parse()`-calling `render()`/`renderToString()` half, removed only once
every real caller has switched (P41-E).

Splits `PageState` (27 public mutable properties, confirmed by direct
count) by concern, not all of it — only the two self-contained clusters
whose own real readers/writers are exactly the classes this phase
already rewrites: `Piwigo\Core\LayoutState` (`bodyClasses`/`bodyId`/
`pageBanner`/`metaRobots`/`headerNotes`/`headerMessages`, read by
`PageHeaderRenderer`) and `Piwigo\Core\RequestMetrics`
(`countQueries`/`queriesTime`/`requestStart`/`debugOutput`/
`executionUuid`, read by `PageTailRenderer`/`TimingHelper`/`Logger`).
Both live in `Piwigo\Core`, not `Piwigo\Page` as first drafted — deptrac
caught the real violation: `Filter\FilterService`/`Section\SectionPopulator`
(L2bExtendedDomain) write to `LayoutState` directly, and L2b may not
depend on L3Presentation, so it has to sit at `PageState`'s own layer.
Everything else on `PageState` (`errors`/`warnings`/`messages`/`infos`,
`nbPendingComments`/`noMd5sumNumber`/`nbOrphans`/`nbPhotosTotal`/
`updatedVersion`/`notifyApiKeyExpiration`, `commentRejectionReasons`,
`exposedData`/`exposedStringKeys`/`bodyData`/`authKeyId`/`authKeyInvalid`)
is explicitly **not** touched — traced each field's real readers/writers
directly and confirmed none are reached by this phase's own rewrites;
splitting those out is a real, separate refactor wearing this one's
badge only because it happens to share a class.

**Batch P41-A (landed)** — the spike + mechanism + the `PageState` split
above. `themes/default/template/redirect.latte` is the spike (smallest
real body, single real caller in `RedirectService::redirectHtml()`):
proved the `{layout}` runtime trace holds on a real render (no
`{block}`-lookup-across-two-`Runtime\Template`-instances issue, no
`LatteTemplateCompiler::injectVarDocblocks()` anchor-notice regression).
`Template::finalizeHtml(string $html): string` extracted from the former
private `finalizeOutput()` so both the old accumulate-then-flush path
and new `{layout}`-based renders share the same combined-CSS/JS/
JSON-island/`<head>`-element substitution logic. `LayoutState`/
`RequestMetrics` (above) swept across every real reader/writer: the 11
front-end controllers, both popuphelp controllers, `AdminShell`,
`CheckIntegrity`, `MaintenanceActionDispatcher`, `SectionPopulator`,
`CalendarRenderer`, `Category{Default,Cats}Renderer`, `TimingHelper`,
`Logger`, `ConfigBootstrapMiddleware`, `RequestBootstrap`, `PageTail`,
`RedirectService`, `PageHeaderRenderer`, `PageTailRenderer`,
`FilterService`, `UserResolutionMiddleware` — plus a new `layout.latte`
per theme (`themes/default/`, `themes/admin/default/`,
`themes/standard_pages/`), each merging that theme's own real
`header.latte`+`footer.latte` chrome, not yet consumed by any real page
template (P41-B/C and a later standard_pages batch do that). A full
(not scoped) verification pass this batch's own end found and fixed two
real gaps a scoped check had missed: `public/admin.php`/`public/random.php`'s
own manual `RedirectService` construction, and a stale
`vendor/composer/autoload_classmap.php` entry for a class deleted in the
same batch.

**Batch P41-B (landed)** — the 12 remaining front-end
`PageHeaderRenderer`/`PageTail` call sites: `GalleryController`,
`PictureController`, `CommentsController`, `TagsController`,
`AboutController`, `IdentificationController`, `RegisterController`,
`PasswordController`, `ProfileController`, `NotificationController`,
`NbmController`, `PopuphelpController` (front-end). Each already
rendered its own body through a P40-converted `View`; only the final
render sequence changed — `PageHeaderRenderer::render()` →
`prepareContext()`, and the old `$template->appendOutput($this->renderer
->render($view)); $body = PageTail::renderToString();` tail →
`PageTail::prepareContext(); $html = $this->renderer->render($view);
$body = $template->finalizeHtml((string) $html);` — with every ambient
side-effect call (`eventDispatcher->dispatch()`, `flushPageMessages()`,
`flushKeyedErrors()`, `historyService->logVisit()`) kept in its original
relative order, now running before `PageTail::prepareContext()` instead
of before the old `PageTail::renderToString()`. Nested fragment renders
that feed into an outer page View as a property (`ProfileFormView` →
`ProfileView::$profileContent`, `CommentListView` →
`CommentsView::$commentList`) stayed plain, non-`{layout}` renders —
only the outermost page-level render per controller converts.
`identification`/`password`/`register`/`profile` each have a real
`themes/standard_pages/` template variant (that theme's own real 200
alternative, not a fallback) — both variants converted independently.
Every corresponding `.latte` file got `{layout 'layout.latte'}` added
right after its `{templateType}` line and its whole body wrapped in
`{block content}…{/block}`. `PictureController` additionally renders
`SlideshowView`/`slideshow.latte` (the `lightSlideshow` config branch),
also converted. Added `popuphelp` to
`tests/Browser/Helpers/VisualRegressionRoutes.php` (closes that gap for
this batch, per the plan's own Verification note). `picture` was
considered and deliberately **not** added there: `picture.php`'s route
already has real golden-html/VR coverage via each suite's own dedicated
`picture-1`/`slideshow` test, kept outside the shared route array
specifically because viewing a photo increments `images.hit` and the
shared loop has no per-route hit-freeze — adding a second `picture`
entry to that array would have double-counted the same state-mutating
route non-deterministically.
23 existing golden-html baselines changed shape (pure `{layout}`-driven
whitespace/indentation differences — verified line-by-line: no content,
URL, or attribute text differs anywhere) and were regenerated with
`GOLDEN_HTML_UPDATE=1`, then reverified stable on a clean rerun; a new
`popuphelp` baseline was captured. Full verification green: `lint:latte`
(131 templates), `phpstan-latte:compile` + full `analyse:phpstan`,
`deptrac analyse` (0 violations), `lint:php`, `composer test`
(Unit+Arch, 5533 passing), `test:golden-html` (74 passing, reverified
stable), `test:visual` (66 passing), and a scoped `test:browser` run
across all 12 controllers' own test files (151 passing).

**Batch P41-C (landed)** — `admin.latte` converted to a real
`Piwigo\Admin\Projection\AdminShellView` (`{templateType}`/`{layout}`,
replacing its 665-line auto-generated `{varType}` header entirely):
holds the `<div id="menubar">` sidebar-nav fields the shell's own body
actually reads (29 properties — `activeMenu` plus a subset of
`AdminShellFramePageContext`'s own fields: `enableSynchronization`,
`uHistoryStat`, `uMaintenance`, `uNotificationByMail`, 4×
`uConfig{General,Menubar,Languages,Themes}`, `uAlbums`, `uCatOptions`,
`uCatUpdate`, `uRating`, `uRecentSet`, `uBatch`, `uTags`, `uUsers`,
`uGroups`, `uAdmin`, `uPlugins`, `uAddPhotos`, `showRating`, `uUpdates`,
`uComments`, `nbPendingComments`, `nbPhotosInCaddie`, `uCaddie`,
`nbOrphans`, `uOrphans`) — confirmed via a real per-field grep across
every admin template's own body (past its `{varType}` header, which is
theme-wide boilerplate, not per-file usage) rather than assumed from
the two old context classes' own field lists. `AdminShellFramePageContext`
itself keeps being assigned ambiently, unchanged, at the same
pre-dispatch point: 4 other real admin templates (`intro.latte`,
`help.latte`, `photos_add_ftp.latte`,
`include/batch_manager_filter.inc.latte`) read a subset of its same
fields ambiently during `AdminDispatcher::dispatch()`, before this View
is ever constructed — confirmed the same way. `adminPageTitle`/
`adminPageObjectId` stay ambient too (via `AdminContentPageContext`,
unchanged): `admin.latte`'s own `<h1>` needs whichever a sub-controller
most recently overrode, so neither can pin to a fixed View property.
`AdminShellPostDispatchPageContext` (`activeMenu`+`pwgmenu`) is deleted
outright rather than kept alongside the new View: `activeMenu` moved
onto `AdminShellView`, and `pwgmenu` — confirmed dead via the same
exhaustive per-field grep, assigned but never read by any real
template — dropped rather than carried forward as an unused property.

`AdminPopuphelpController` converted the same way, sharing
`Piwigo\Controller\Projection\PopuphelpView` with the front-end
`PopuphelpController` (P41-B) — its `themes/admin/default/template/popuphelp.latte`
also lost its own 665-line `{varType}` header. This conversion also
fixed a real, pre-existing bug found by the batch's own golden-html
verification: the old admin `popuphelp.latte` read `{$HELP_CONTENT}`
(uppercase, ambient), but `AdminPopuphelpController` already rendered
through `PopuphelpView`/`Renderer::render()` (real `get_object_vars()`-based
camelCase properties, no `toArray()`) *before this batch started* —
nothing had ever written an uppercase `HELP_CONTENT` key into
`Template::$vars`, so every real admin help-popup page had been
silently rendering with empty content. Fixed by reading `{$helpContent}`
(the real property) instead — confirmed via the regenerated
`admin-popuphelp` golden-html baseline, which now shows the real help
article body instead of an empty `<div id="content" class="content"></div>`.

Also deleted `Piwigo\Bootstrap\PageTail::render()` and
`Piwigo\Page\PageTailRenderer::render()` (the void/echoing variants) in
this same batch, ahead of P41-E's own formal schedule: `AdminShell.php`
was their last real caller (confirmed via full-repo grep, including
test files — `renderToString()` stays, since dedicated Unit/Integration
tests still call it directly), and PHPStan's own dead-method detector
flags a zero-caller method as an unsuppressable error per this
project's own PHPStan instructions ("do not add baseline entries to
suppress"). `renderToString()`/`prepareContext()`/`Template::$output`/
`pparse()`/`flush()`/`finalizeOutput()`/`fetchOutput()` all stay for
P41-E, since `InstallWizard.php`'s own `pparse('install.latte')` (P41-D,
not yet converted) is still a real caller.

Full verification green: `lint:latte` (131 templates),
`phpstan-latte:compile` + full `analyse:phpstan` (0 errors, including
the dead-method check above), `deptrac analyse` (0 violations),
`lint:php`, `composer test` (Unit+Arch, 5532 passing — one fewer than
P41-B's count, `AdminShellPostDispatchPageContextTest.php` deleted
alongside its subject), `test:golden-html` (74 passing, reverified
stable — 48 admin baselines regenerated for the same `{layout}`-driven
whitespace/indentation reshape as P41-B, individually verified
whitespace-only via full-file comparison against git HEAD, not just the
diff hunks — a hunk-only comparison silently drops shared context lines
from both sides and can't be trusted for this; the one genuine content
change, `admin-popuphelp.html`, is the bug fix above), `test:visual` (65
passing + 1 known pre-existing flaky test — `admin-themes-new`, confirmed
by an isolated rerun, unrelated to this batch — plus a regenerated
`admin-popuphelp` screenshot baseline), scoped `test:browser`
(`AdminShellTest.php`, 12 passing), and scoped `test:integration`
(`PageTailRendererTest.php`/`PageTailTest.php`, 6 passing).

**Batch P41-D (landed)** — `InstallWizard`/`install.latte` converted to
a real `Piwigo\Admin\Install\Projection\InstallView`. No `{layout}`
needed, confirmed: `install.latte` is a genuinely self-contained
`<!DOCTYPE html>` document (its own `<head>`/inline
`{=getCombinedCss()}`/`{=getCombinedScripts('header')}` calls, both of
which already return the same `COMBINED_CSS_TAG`/`COMBINED_SCRIPTS_TAG`
placeholders every other page's `{do combineCss}`/`{do combineScript}`
resolves to, so `Template::finalizeHtml()` needed zero changes to
handle it), not something that parses against a shared admin
header/footer at all. All 18 of `InstallRenderPageContext`'s own fields
mapped 1:1 onto the new View's properties (confirmed via a real
per-field grep of `install.latte`'s own body, past its 665-line
`{varType}` header) — genuinely self-contained, unlike `admin.latte`'s
own P41-C conversion; `$lang_info`/`$ROOT_URL`/`$themeconf`/`$themes`
stay ambient, same `IndexView`-doesn't-declare-`$ROOT_URL` pattern as
every other page. `InstallRenderPageContext` deleted outright (0
remaining callers). `InstallWizard::render()` stays `void`/echoing
(matches `AdminShell::runDispatch()`'s own shape, not a PSR-7
controller) — `echo $template->finalizeHtml((string) $html);` in place
of the old `assignContext()`+`pparse()` pair.

Also deleted `Template::pparse()` itself in this same batch, ahead of
P41-E's own formal schedule: `InstallWizard.php` was its last real
caller (confirmed via full-repo grep — `parse()`/`flush()` both still
have other real callers and stay), and PHPStan's dead-method detector
flags it the same unsuppressable way it flagged `PageTail::render()`
in P41-C. Fixed two stale docblocks this deletion left behind
(`Http\ResponseFactory::html()`'s own "not pparse()'s echo" contrast,
now "not flush()'s echo"; a P41-C-authored `Template::finalizeHtml()`
comment that named install.latte's own pparse() call as the "one
remaining multi-flush example" — no longer true now that every real
page renders through a single `finalizeHtml()` call).

Found and fixed one real pre-existing test/mechanism mismatch during
verification: `tests/Integration/InstallWizardTest.php`'s own
`testRenderAssignsTheCollectedValidationErrorsToTheTemplate()`
asserted `Template::getTemplateVars('errors')` matched the wizard's
own `$errors` array — a real assertion against the *old* ambient
`assignContext()` mechanism, which a real typed View (never written
into `Template::$vars`) can no longer satisfy. Fixed by dropping that
assertion and keeping the test's own already-present, still-real
behavioral check (`install.latte`'s rendered output actually contains
the error text) — the same "verify against real call sites, not
internal mechanism" call this session's own established discipline
already applies elsewhere.

Full verification green: `lint:latte` (131 templates),
`phpstan-latte:compile` + full `analyse:phpstan` (0 errors, including
the `pparse()` dead-method check), `deptrac analyse` (0 violations),
`lint:php`, `composer test` (Unit+Arch, 5530 passing — 2 fewer than
P41-C's count, `InstallRenderPageContextTest.php` deleted alongside its
subject), scoped `test:integration` (`InstallWizardTest.php`, 16
passing), and the real end-to-end `test:install` browser flow (1
passing) — a genuine HTTP-level confirmation, not just Integration-test
internals. No `test:golden-html`/`test:visual` coverage exists for
install (per the plan's own Verification section), so `test:install`'s
real browser walk-through is this batch's actual regression net.

**Batch P41-E, cutover completion (landed)** — deleted
`PageHeaderRenderer::render()`, `PageTailRenderer::renderToString()`,
and `PageTail::renderToString()` (their `render()`/void siblings were
already gone, P41-C) — every real caller had already switched to
`prepareContext()` (confirmed via full-repo grep). Deleted
`Template::$output`, `appendOutput()`, `flush()`, `fetchOutput()`
alongside them, which forced `parse()` itself to simplify: its old
`bool $return = false` accumulate-into-`$output` mode had zero
remaining real callers once the two methods above were gone (every
survivor already passed `true`), so `parse(string $file): string`
now always returns the rendered string — `MailService`'s own shell
render and `assignVarFromTemplate()` (both already `true`-mode) are
unaffected.

Found and fixed one real pre-existing gap during investigation, before
touching any of the above: `Page\NoPhotoYetRenderer` was never covered
by any P41-A–D batch, but was still the last real production caller of
`appendOutput()`/`flush()` — converted to the same one-shot
`Renderer::render()`/`finalizeHtml()`/echo pattern as every other P41
page (`no_photo_yet.latte` is self-contained like `install.latte`, no
`{layout}` needed).

Also fixed a real, structural test consequence of deleting
`PageHeaderRenderer`'s only `parse('header.latte')` call site:
`TemplateCallSiteScannerTest.php`'s own "frontend polymorphic call
site" test relied on that real call site as its fixture (resolving
`header.latte` to both `themes/default/` and `themes/standard_pages/`)
— rebuilt as a synthetic fixture, the same pattern the file's own
Admin-scoping test already established for the identical reason
(P40's admin sweep retired ITS real fixture first). `MailService`'s
own `parse('header.latte')`/`parse('footer.latte')` calls remain real
but Mail-scoped (`themes/default/template/mail/` only), so they don't
cover the theme-polymorphic case.

Removed the now-permanently-unmatched `phpstan.neon` ignore rules for
the `@deprecated P41` methods just deleted (the ignore comment's own
text: "only P41-E deletes both the methods and this ignore together").

Full verification green: full `analyse:phpstan` (0 errors), `deptrac
analyse` (0 violations), `lint:php`, `lint:latte`, `composer test`
(Unit+Arch, 5529 passing), `test:golden-html` (74 passing, **zero**
baseline changes — confirms the refactor is purely internal),
`test:visual` (66 passing), scoped Integration tests for every
directly-touched rendering class (`MailService`, `NoPhotoYetRenderer`,
`PageHeaderRenderer`, `PageTailRenderer`, `PageTail`, `InstallWizard`
— 68 passing), and `MailGoldenHtmlSnapshotTest` (Mail's own real
`parse()` pipeline, 1 passing).

**P41-E's other half (landed)** — physically extracted
`TemplateLocator`/`ThemeChain` out of `Template.php` into their own
classes (`src/Piwigo/Template/TemplateLocator.php`,
`src/Piwigo/Template/ThemeChain.php`,
`src/Piwigo/Template/ThemeChainResolution.php`), matching the same
"constructed internally in `Template`'s own constructor" shape already
used for `$this->scriptLoader = new ScriptLoader()`/`$this->cssLoader
= new CssLoader()` — not a shared/injected collaborator, so none of the
7 real `new Template(...)` construction sites or `TemplateTestFactory::build()`
needed to change.

`TemplateLocator` owns the per-instance theme directory chain
(`addDir()`/`firstDir()`/`resolve()`/`exists()`) —
`resolveLatteTemplatePath()` now delegates to `resolve()`, returning
`null` on a genuine miss instead of calling `fatalError()` directly (the
one real caller converts that back into its own fatal-error path).
`ThemeChain` owns the recursive parent/child theme walk
(`resolve(): ThemeChainResolution`) and `theme.json` loading/caching
(`loadThemeconf()`, kept public on both `ThemeChain` and as a thin
`Template` delegate specifically to preserve `TemplateInstanceTest.php`'s
own ~8 existing direct unit tests unmodified).

The one real design tension: `setTheme()`'s original recursive
algorithm mutated `Template::$vars` directly via a private `append()`
helper (plain list-append for `themes`, key-merge-child-wins for
`themeconf`) at every recursion level. Replaced with a single
`ThemeChainResolution` value object (`dirs`/`themes`/`themeconf`)
`ThemeChain::resolve()` returns in one shot, which `setTheme()` applies
via 3 direct `assign()` calls — safe as a substitute for the old
accumulate-via-recursion approach only because `setTheme()`'s OUTER
call is confirmed (full-repo grep, real + test callers) to fire exactly
once per `Template` instance, always from the constructor, so there's
never pre-existing `$vars['themes']`/`$vars['themeconf']` content an
`assign()` overwrite could clobber. `Template::append()` deleted
outright (zero remaining callers after this rewrite).

The one genuine remaining side effect `ThemeChain` can't compute
purely — `loadThemeJson()` assigning `STD_PGS_SELECTED_SKIN`/
`STD_PGS_SELECTED_LOGO`/`GALLERY_TITLE` the moment a `theme.json`
literally named `standard_pages` loads (real, test-covered in
`TemplateInstanceTest.php`) — threaded through as a constructor-injected
`Closure $onStandardPagesThemeLoaded`, invoked from the exact same
`loadThemeJson()` call site, since `ThemeChain` has no access to
`Template`'s own private `assign()`.

Full verification green: full `analyse:phpstan` (0 errors), `deptrac
analyse` (0 violations), `ecs check` (clean), `composer test`
(Unit+Arch, 5528 passing — 1 unrelated `CategoryRepositoryTest`
DeadlockException, confirmed flaky via isolated rerun, all 105 passing
alone), `test:golden-html` (74/74, zero baseline changes),
`test:visual` (65/66 — the 1 failure, `admin-themes-new`, is the
already-documented pre-existing hover-zoom race on the theme-preview
grid described in `VisualRegressionTest.php`'s own comments, confirmed
via direct screenshot inspection and a clean pass on a 3rd isolated
rerun), and a scoped `TemplateInstanceTest.php`/`TemplateTest.php`/
`LatteEngineWiringTest.php` run (100 passing, 150 assertions) covering
every pre-existing `setTheme()`/`loadThemeconf()`/`parse()`/
`finalizeHtml()` edge case (parent-theme `load_parent_css`/
`load_parent_local_head` inheritance, non-string parent themeconf,
`standard_pages` side-effect timing, `loadThemeconf()`'s own cache-key
collision avoidance) against the new extracted classes unchanged. No
test file needed modification for this piece — every existing test
passed as-is against the new delegate-based `Template.php`.

This completes P41-E and all of P41 Part 1.

**Part 2 (P41-G/H) — asset-pipeline cutover (landed).** Redirected
`Template::combineCss()`/`combineScript()`/`footerScript()` to
`PageAssets::add(AssetContribution)` instead of `CssLoader`/
`ScriptLoader`, with `finalizeHtml()`'s CSS/JS half reading from
`PageAssets::resolveCss()`/`resolveScripts()` through a new
`Template::makeAssetSrc()`/`renderFooterScripts()` tag-rendering pair.
`combineScript()`'s dead `$template` param dropped outright (zero real
call sites, confirmed via grep); `footerScript()`'s 6 real
inline-script call sites route through a new `AssetContribution::inlineScript()`/
`AssetKind::InlineScript` variant, since `PageAssets` is now the *sole*
resolver; header and footer scripts unified onto the same
placeholder-deferred path (`COMBINED_FOOTER_SCRIPTS_TAG`, new).
`GetPageAssets` dispatches once per instance, lazily, on
`finalizeHtml()`'s first call.

One real gap the plan's own "Four real gaps" text hadn't accounted
for, found during implementation, not planning: `CssLoader`/
`ScriptLoader`'s real resolution routed through `FileCombiner`, which
does more than resolve paths — it actually bundles multiple CSS/JS
files into one cache-busted file on disk
(`CurrentConfig::$templateCombineFiles`, default `true`, genuinely
live in production). Per explicit user decision, this file-combining
behavior is **intentionally dropped**, not ported into `PageAssets` —
a real bundler (Vite) is coming once JS migrates to TS in a later
phase, so preserving `FileCombiner`'s ad-hoc mechanism now would be
throwaway work. Each registered CSS/JS file now renders its own
`<link>`/`<script>` tag instead of being merged — more requests per
page than before, accepted tradeoff. `CssLoader`/`ScriptLoader`/
`FileCombiner`/`Combinable`/`Css`/`Script`/`Projection\FooterScripts`/
`Event\CombinablePreparse`/`Event\CombinedCssPostfilter` all deleted
in the same pass (P41-H folded into P41-G, matching P41-E's own
precedent — PHPStan's dead-code detector forces it once nothing real
calls the old classes), along with `MaintenanceActionDispatcher`'s own
now-pointless `FileCombiner::clearCombinedFiles()` call and the 6
already-dead `themes/{default,admin/default,standard_pages}/template/{header,footer}.latte`
files (found via a separate adversarial check — their real call sites
were deleted back in P41-E, but the files themselves were never
removed; not `themes/default/template/mail/` ones, which
`MailService`'s own separately-rooted `Template` instance still
genuinely renders). `test:golden-html` shows real (not whitespace-only)
diffs from the combining removal, reviewed and accepted — reduced
`<link>`/`<script>` bundling, not a rendering bug.

**Part 2 follow-up (P41-I, proposed, then superseded before starting).**
The placeholder-tag + `substr_replace()` mechanism P41-G/H built works
and is fully tested, but isn't idiomatic Latte — Latte's own native
`{capture $var}...{/capture}` was the first proposed fix. Adversarial
review found `{capture}` is itself just a better-engineered workaround
for an ordering problem that shouldn't exist at all: the problem only
exists because `combineCss`/`combineScript`/`footerScript`/
`exposeData`/`exposeString`/`htmlHead` are imperative Latte calls
scattered through template bodies. **Superseded entirely, not just
deferred, by P42 below** — this page metadata belongs on the View,
declared before rendering starts, the same pattern P40 already
established for ordinary template variables.

**P42 — Declarative page assets & exposed data (View-level, in
progress).** Three new small interfaces a View implements when it
needs them: `Piwigo\Asset\HasPageAssets` (`pageAssets():
list<AssetContribution>`, replacing `combineCss`/`combineScript`/
`footerScript`), `Piwigo\Core\ExposesPageData` (`exposedPageData()`/
`exposedStrings()`, replacing `exposeData`/`exposeString`), and
`Piwigo\Core\HasHeadLinks` (`headLinks(): list<HeadLink>`, a new
readonly value object replacing `htmlHead`). `Renderer::render()`
gained a pre-population step applying a View's declared data to
`Template` *before* that View's own `.latte` file runs, and now also
owns `Template::dispatchPageAssetsOnce()`'s one-shot `GetPageAssets`
plugin-dispatch (relocated from `finalizeHtml()`'s former first line,
since `finalizeHtml()` itself is fully deletable once every real call
site migrates). Declarative and not-yet-migrated-imperative
registrations coexist safely on the same page throughout the whole
migration — `PageAssets::add()`/`Template::exposeData()` are dedup-safe
regardless of call order — so this converts incrementally, template by
template, no flag-day cutover.

Scale: 945 real call sites (`combineCss` 143, `combineScript` 226,
`footerScript` 6, `exposeData` 155, `exposeString` 415/329 distinct),
comparable to or larger than the entire P40 campaign. 11 of the 125
real templates were never given a `{templateType}` + View by P40 (no
dedicated class exists yet to attach the new interfaces to) — converting
those is this campaign's own opening batch, a real prerequisite, not
optional cleanup. Batches afterward are ordered bottom-up through the
`{include}` graph (a template that includes a not-yet-migrated partial
must wait), not reused from P40's own rendering-mechanism-based
grouping. Full design — the theme-base content split into 4 focused
collaborators, the CSS/script insertion-order risk `PageAssets::
resolveCss()`'s/`topologicalSort()`'s same-priority tie-breaking
creates, the deptrac layering check (every real View-hosting namespace
can reach `Asset`/`Core` downward), and per-batch verification — is
written up in
`/home/torres/.claude/plans/validated-hopping-hamster.md`.

**Shipped so far**: the 3 interfaces, `HeadLink`, and the
`Renderer::render()` pre-population/dispatch-relocation hook, with
`Template::registerPageAssets()`/`registerHeadLink()` as the small
public wrappers `Renderer` needs (`$pageAssets` itself stays private).

**P42-A (landed) — the 11 leftover pre-P40 partials.** 9 real
**contract-only** `{templateType}` conversions, same shape as the
pre-existing `Piwigo\Controller\Projection\NavigationBarView`
precedent (P40's own "include-only partials" resolution): a small
`View` class + `{templateType}` on the template, zero controller/
call-site changes, since none of these 9 are ever reached via
`Renderer::render()` — only plain `{include}`, which already merges
loose ambient vars with the current scope. `local_head.latte` is the
one real exception (full `View`, not contract-only) — the theme-base
"local-head resolver" piece (below) renders it directly.

Also found and deleted 2 files the plan's own list carried as real
conversions but that turned out to be genuinely dead:
`themes/default/template/include/{colorbox,autosize}.inc.latte` had
zero real callers anywhere in the app — both real usages are
admin-only, resolving against the admin theme's own same-path copies,
confirmed via exhaustive grep rather than assumed from the file list.
Converting them would have been pointless.

`batch_manager_filter.inc.latte` (the largest, 607 real lines) turned
up a real pattern the plan's own Design section hadn't anticipated:
its real property set is sourced entirely from several *ambient*
`TemplatePageContext` classes assigned upstream by other rendering
steps (`FilterPanelPageContext` and 3 others), not from this
template's own `{include}` args — `{templateType}` doesn't care about
provenance, only what the body actually reads, confirmed by reading
the full 607-line body rather than assuming from the 2 real call
sites. Those 2 call sites also both pass `title`/`searchPlaceholder`
args the body never reads at all — a real, pre-existing dead-parameter
waste, left alone as out of scope for this batch.

Verification: `test:golden-html` byte-identical across every real page
reaching one of these files (including all 7 of
`album_selector.inc.latte`'s own real parents), full `composer
analyse:phpstan`/`deptrac analyse`/`ecs check`/`lint:latte` clean, full
`composer test --testsuite=Unit,Arch` green (5386 tests).

**P42-A (fully landed) — the 4 theme-base pieces.** `ThemeBaseAssets`
(3 named factory methods, not `forTheme(ThemeId)` as originally
sketched — the 3 real layout families genuinely differ in their own
unconditional assets, confirmed by reading all 3 real `layout.latte`
files in full: `admin` loads 2 extra stylesheets with a hardcoded
`admin/default/` path regardless of active sub-theme, registers
`jquery` with an explicit path and no `load:`, and never calls
`localCssRules()`), the local-head resolver
(`Template::resolveLocalHeadOnce()`, fired from `Renderer::render()`'s
hook alongside `dispatchPageAssetsOnce()`, narrowly scoped to the one
real `local_head.latte` instance by comparing resolved paths, not
theme id alone — `themes/admin/default/` is also a real theme
literally named "default"), `localCssRules()`'s relocated call site,
and the confirm-dialog registration all wire into
`Template::setTheme()`, replacing the 3 real `layout.latte` files' own
imperative equivalents.

A new `Template::__construct()`/`setTheme()` `applyThemeBase` flag
(default `true`) was needed — `install.latte` is the one real
top-level page in the whole app that doesn't extend `layout.latte`
(confirmed via grep: no other `.latte` file besides the 3 real
`layout.latte`'s own has its own `<!DOCTYPE html>`), so
`InstallWizard` opts out explicitly rather than gaining admin chrome
it never wanted — found via a real golden-html regression during
verification, not assumed. Every CSS/script-tag and JSON-island-key
reordering in the resulting golden-html diffs (49 pages) was confirmed
pure reordering (same tag sets, same JSON content) via a scripted
line/JSON-aware diff check before accepting new baselines. Two
pre-existing stale visual-regression baselines (`admin-config-search`,
`admin-themes-new`) were also found and fixed in the same pass, both
confirmed unrelated to this work.

Also found and fixed, while investigating an Integration-suite run for
this commit: `PageHeaderRendererTest`/`PageTailRendererTest`/
`PageTailTest` each called `Template::parse('header.latte'/
'footer.latte')` directly — both files were deleted in this session's
earlier P41-G/H commit (`00fd301ac5`), a real pre-existing gap
(confirmed via `git stash` against the tree without this commit, not a
P42-A regression) that had gone uncaught because Integration tests
hadn't been run since. Fixed by rendering a tiny fixture file
extending `layout.latte` instead of the deleted standalone files.

This closes P42-A in full (11-partial conversion + theme-base pieces).
**P42-B (the 945-call-site page-by-page migration) — in progress.**
14 real pages/Views landed so far, ~41 of 945 call sites (all in the
admin `configuration_*`/`languages_*`/`updates_*`/`maintenance_*`/
`site_*`/`themes_*`/`check_integrity`/`help`/`permalinks` family —
every one a standalone page or sub-content fragment with zero
`{include}` of a not-yet-migrated partial): `HelpView` (1,
sub-content-fragment shape confirmed working through the P42 mechanism
unchanged), `MaintenanceSysView` (2, `$isWebmaster`-gated),
`PermalinksView` (3), `LanguagesInstalledView` (4, one duplicate
confirm-dialog pair dropped), `ConfigurationDisplayView` (2),
`CheckIntegrityView` (2, one genuinely `null`-vs-absent-key-sensitive),
`LanguagesNewView` (3), `UpdatesPwgView` (2, one duplicate
confirm-dialog string dropped), `MaintenanceEnvView` (5, no
golden-html route reaches this tab -- verified instead via its own
real Browser suite), `ConfigurationCommentsView` (2), `SiteUpdateView`
(2), `ThemesStandardPagesView` (2), `ConfigurationWatermarkView` (4,
one ambient `$ROOT_URL` resolved via the controller's already-injected
`UrlServiceInterface`), `ConfigurationSearchView` (4, one loosely-typed
array property needing `is_array()` narrowing to satisfy
`exposedPageData()`'s own return type).

Every migrated View's new interface methods carry `#[Override]`
(`StructuralTest`'s own project-wide requirement, applies to every
future migration in this campaign too). `test:golden-html`
byte-identical throughout (mostly pure whitespace from the deleted
imperative lines). Two pre-existing, unrelated issues found and
confirmed via `git stash` (not caused by this campaign): an
`admin-dashboard` activity-count drift (left alone, out of scope), and
an `admin-config-search` `filters_names`-ordering golden-html baseline
staleness (regenerated, since this migration's own page directly
exercises it).

Also migrated 8 more pages/Views since the last count, ~65 of 945:
`CatListView` (4), `ConfigurationSizesView` (7, 2 duplicate
confirm-dialog strings dropped), `PictureFormatsView` (8, one
`order: 10` "issue 1080" CSS), `SiteManagerView` (5, 2 duplicates
dropped).

**Real, deferred sub-task found**: `themes_installed.latte` (and 13
other real templates, including `admin.latte` the shell itself)
`{include 'include/colorbox.inc.latte'}` -- one of P42-A's own
contract-only partials, whose real `combineScript`/`combineCss`
content hasn't migrated yet. Per the plan's own bottom-up dependency
rule, none of these 14 pages can migrate their own remaining calls
until `ColorboxView` (and its sibling contract-only partials,
`AlbumSelectorView`/`AddAlbumView`/`AutosizeView`/`DatepickerView`/
`BatchManagerFilterView`/`QuickSearchView`) gets a real `pageAssets()`
**and** every one of ITS OWN real parents is updated to construct that
partial's View and merge its `pageAssets()` in -- there is no other
way for a contract-only, `{include}`-only partial's own declarative
data to actually apply, since `Renderer::render()` never runs for it.
`ColorboxView`'s own `pageAssets()` design was worked out (`$load_mode`
resolves the same 3-way `header`/`footer`/`async` mapping
`Template::combineScript()` itself uses) but reverted rather than
committed half-wired to zero real callers -- this is real, substantial
work (14 files for colorbox alone) deserving its own dedicated
batch(es), not something to fold into an unrelated single-page commit.
Every other page migrated so far was deliberately chosen to have zero
`{include}` of a not-yet-migrated partial, so this gap doesn't block
continued progress on the remaining independent pages.

Also migrated 4 more pages/Views, ~79 of 945: `ElementSetRanksView`
(3), `DoubleSelectView` (1, shared by 4 real callers), `CommentsView`
(8, csrf_token + 7 strings), `StatsView` (6, month_labels/lang_code +
2 strings) -- 22 pages/Views landed so far. All 4 had zero
`{include}` of any not-yet-migrated partial. `test:golden-html`
byte-identical (2 pure-whitespace baseline regenerations,
`admin-album-sort`/`admin-stats`, same deleted-blank-line shape as
every prior batch).

Also migrated 4 more pages/Views, ~190 of 945: `UpdatesExtView` (17,
shared by 4 real callers -- updates/ext + languages/themes/plugins
update tabs), `PluginsNewView` (23, new `$colorscheme` property
resolving the ambient `$themeconf['colorscheme']` reference via
`$template->themeConf('colorscheme')` in `PluginsNewPageRenderer` --
the first real instance of the plan's own documented ambient-value
case), `PluginsInstalledView` (30), `TagsView` (41, one `order: 10`
"issue 1080" CSS preserved) -- 26 pages/Views landed so far.
`test:golden-html` byte-identical throughout (6 pure-whitespace
baseline regenerations; `admin-plugins-installed` stayed byte-
identical with no leftover blank line at all).

Also migrated 6 more pages/Views, ~214 of 945:
`AlbumNotificationView` (5, new `$colorscheme`), `ConfigurationDefaultView`
(1), `MenubarView` (3, no golden-html fixture for either of the last 2 --
verified via `AdminConfigurationTest`'s "renders the default tab" test
and the `Menubar*Test.php` suite instead), `CatPermView` (10, new
`$colorscheme`/`$rootUrl`), `PictureCoiView` (5 -- its `{do htmlHead(...)}`
call is a plain static CSS `<link>`, migrated as an ordinary
`AssetContribution::css()` entry per the plan's own "htmlHead() fully
migrated" design, not a `HasHeadLinks` case; its conditional `{if
isset($coi)}{do exposeData(...)}{/if}` got a direct unit test per the
plan's own branching-logic testing discipline, since golden-html's one
fixture only exercises the non-null branch) -- 31 pages/Views landed so
far. `test:golden-html` byte-identical throughout (3 pure-whitespace
regenerations; `admin-picture-coi`'s regeneration also picked up a real,
expected content change -- the migrated stylesheet link now goes
through the same combining/versioning pipeline as every other asset
instead of being spliced in raw, gaining a `?v17.0.0` query string and
moving into sorted position).

Also migrated 3 more pages/Views, ~263 of 945: `MaintenanceActionsView`
(22, one `order: 10` "issue 1080" CSS preserved), `RatingUserView` (15,
new `$rootUrl`; preserves the bare `combineScript(id:
'jquery.ui.tooltip', load: 'footer')` call with no `path:` --
`PageAssets::fillKnownScript()` resolves it by naming convention),
`RatingView` (12, new `$colorscheme`/`$rootUrl`) -- 34 pages/Views
landed so far. `test:golden-html` byte-identical throughout (3
pure-whitespace regenerations).

**Ran an 8-agent parallel survey** (Workflow) of every remaining
template with imperative calls across all 3 theme trees, to map real
dependency order and gotchas before continuing. Findings:

- **A real infrastructure bug, not yet fixed**: `themes/default/
  local_head.latte`'s `LocalHeadView` is dispatched via `Template::
  resolveLocalHeadOnce()` calling `Template::renderView()` directly,
  bypassing `Renderer::render()` entirely -- so even a correct
  `pageAssets()` on `LocalHeadView` would never actually fire. Needs
  `resolveLocalHeadOnce()` itself updated (call
  `registerPageAssets()`/etc. before its `renderView()` call, mirroring
  what `Renderer::render()` does) before that one template migrates.
- **A real, confirmed regression caught and fixed**: `PopuphelpView` is
  shared by two callers resolving DIFFERENT physical `.latte` files
  (front-end vs. admin-context, same bare `#[Template('popuphelp.
  latte')]` name) -- the admin variant has zero calls of its own. Since
  `pageAssets()` lives on the shared class, a naive migration
  registered `popuphelp.js` on both renders; a new `$isAdminContext`
  flag (set by each real caller) restores the original per-physical-
  file behavior, confirmed via golden-html regeneration (a spurious
  `<script>` tag appeared, then disappeared once fixed). The exact same
  shared-class-two-physical-files pattern recurred for `Identification
  View`/`RegisterView`/`PasswordView` (default theme vs. `standard_
  pages` theme, same `Template::setTheme()` substitution) -- resolved
  identically each time via a new `$isStandardPagesTheme` flag
  (`$template->themeConf('id') === 'standard_pages'`).
- **`MenubarBlockView` (shared by all 7 `menubar_*.latte` sub-block
  templates via one generic `{include $block->template, ...}` call) and
  `MonthCalendarView`/`index.latte`'s own `{include
  $FILE_CHRONOLOGY_VIEW}` are genuinely unresolved design gaps**,
  distinct from the already-solved `PictureNavButtonsView` case below:
  neither class is ever a real `Renderer::render()` target NOR does any
  real caller hold enough of the right data in the right place to do a
  clean construct-and-merge the way `PictureView`/`SlideshowView` do --
  `month_calendar.latte`'s own data flows through a parallel `Template::
  assignContext()` path (`SectionPopulator`→`CalendarRenderer`) that
  never intersects `GalleryController`'s own `IndexView` construction
  at all. Deferred, same as the colorbox-family sub-task above --
  `index.latte`/`month_calendar.latte` and the 3 `menubar_*.latte`
  templates need their own dedicated design batch.
- The admin `layout.latte` shell has 2 `exposeData()` calls
  (`WHATS_NEW_MAJOR_VERSION`/`SHOW_WHATS_NEW`, genuinely per-request
  data with no page-level View to attach to) that need a narrow,
  explicitly-documented exception -- a direct `Template::exposeData()`
  call from `AdminShell::runDispatch()`, not a fake View. All 3 layout
  files' static `page-data` script registration folds cleanly into
  `ThemeBaseAssets` with no question. Not yet done.
- 4 templates (`comment_list.latte`/`mainpage_categories.latte`/
  `thumbnails.latte`/`picture_content.latte`) gate a couple of script
  registrations on `$derivative->isCached()`, a per-item service call
  no DTO View can replicate -- decided to register those scripts
  unconditionally (`PageAssets::add()` is dedup-safe; an unused
  `<script>` tag isn't a functional regression). Not yet done.

Migrated 10 more pages/Views since the survey: `RedirectView` (1),
`PopuphelpView` (1, `$isAdminContext` fix above), `SelectedTagsView`
(1), `IdentificationView` (2+3 across 2 physical files,
`$isStandardPagesTheme` fix above), `RegisterView` (2+3 across 2
physical files), `PasswordView` (4+4 across 2 physical files, plus a
3-way `$action`-gated `footerScript` `match()`), `NotificationView`
(the campaign's first real `HasHeadLinks` migration -- 2 RSS-discovery
`<link>` tags), `PictureNavButtonsView` (8 calls, contract-only, shared
by `picture.latte` AND `slideshow.latte` -- both real parents construct
an instance and merge in, landed together in one commit to avoid
silently regressing `slideshow.latte`), `PictureView` (13 calls, new
`$rootUrl`), `SlideshowView` (0 own calls, merge-only) -- **44 pages/
Views landed so far, ~366 of 945 call sites**. `test:golden-html`
byte-identical throughout (mix of pure-whitespace and fully-clean
regenerations); `picture-1`'s regeneration confirmed every merged
JSON-island key matched byte-for-byte. New unit tests added for every
real branch introduced (`IdentificationView`/`RegisterView`/
`PasswordView`'s theme branch, `PasswordView`'s 3-way action match,
`PictureNavButtonsView`'s 7 independently-nullable exposedPageData
branches, `PictureView`'s `uOriginal`/`rating`-gated branches,
`PopuphelpView`'s context branch).

Migrated 7 more pages/Views: `ToasterView` (2 calls, contract-only,
one real parent confirmed via repo-wide grep) + `ProfileView` (26
calls across the `standard_pages` physical file, 0 on the default
physical file -- same shared-class-two-physical-files pattern as
`PopuphelpView`, merges `ToasterView`'s `pageAssets()` in), then the
**derivative-`isCached()` batch**: `CommentListView` (5),
`CategoryCatsView` (4), `ThumbnailsView` (5), `PictureContentView` (3)
-- **51 pages/Views landed so far, ~412 of 945 call sites**. The first
3 register their `jquery.ajaxmanager`/`thumbnails.loader` pair
unconditionally (a per-item `$pwg->derivative(...)` service call no
DTO View can replicate; `PageAssets::add()` is dedup-safe, so this is
a deliberate, accepted widening); `PictureContentView` is the
exception -- its `$current['selected_derivative']` is already a real
`DerivativeImage` sitting on the View's own constructor data, so its
condition stayed exact, no widening. Confirmed via `git stash`: 2
`quick_search.latte`/`search_filters.inc.latte` migration attempts
were reverted after discovering `quick_search.latte` has a SECOND real
parent (`batch_manager_filter.inc.latte`) still in the deferred
colorbox-family batch -- both stay deferred together.

**First full-suite `test:golden-html` sweep run this campaign**
(previously verified only per-page) surfaced: 2 real, safe-to-close
verification gaps from earlier commits (`slideshow`/`infos-errors`,
closed in their own commit); 1 already-known pre-existing
`admin-config-search` `filters_names`-ordering drift (left alone); and
a full-suite-only "cumulative hit-count across ~78 sequential real
page views" artifact on `random`/`calendar-posted`/`favorites`
(individually clean, only drifts when the whole suite runs in one
process back-to-back -- left alone, not a real baseline defect).

**Final step of P42, once every batch above lands**: reimplement the
`17.x-rewrite-3` worktree's own independent array-to-object campaign
(124 commits, 614 files, `$array['field']` access converted to typed
`$object->field` access, plus a loose-union signature-narrowing sweep)
on top of `17.x-rewrite`. That branch diverged from this one at
`0c71fa6c55` and the two have drifted too far apart for a clean
merge/rebase/cherry-pick of the whole range -- reimplement the
*pattern* file-by-file instead, using
`/home/torres/piwigo17-rewrite-3/HANDOFF-array-to-object-campaign.md`
as the theme-by-theme index (every cited commit hash is directly
`git show`-able from this worktree too, since both share the same
`.git` object database -- no fetch needed). Not started.

**P43 — Typed contributions + plugin-owned routes.**

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

**P44 — Escaping campaign.** The residue after P38 removes the JS-context
cases and P40 turns rendered-sub-template vars into `Html`-typed
properties: the pre-escaped-URL population (`{$U_HOME|noescape}`,
`{$F_ACTION|noescape}`, `{$ROOT_URL|noescape}`), not the full 988. **Size
it after P40, not before.** Kept as its own phase so an escaping
regression stays bisectable from a structural one; gated by golden-HTML
and VR.

**P45 — Latte lint/format enforcement.** P32 built the tooling and gated
almost none of it: `composer lint:latte`, `composer precompile:templates`
and the `tools/latte-prettier/` formatter are invoked by neither
`.github/workflows/ci.yml` nor `lefthook.yml` — only
`composer analyse:phpstan` runs today, via the CI `phpstan` job and a
`lefthook` pre-push hook. Wire the survivors into CI and pre-commit.

Deliberately last in the refactor track: P43 changes `PiwigoExtension`'s
filter set and `lint:latte` registers that extension, so gating earlier
only churns the config. `lint:vartype` is **never** wired — P40 deletes
it along with the `{varType}` blocks it generates.

**P46 — JS → TS mechanical conversion.** `.js` → `.ts` renames, minimal
types to satisfy the existing strict `tsconfig.json`, real Vite entries
replacing the `noop` placeholder (the 68 entries `vite.config.ts` already
earmarks). Same code, same behavior. Vendored third-party files
(`jquery.js`/`.min.js`/`.cookie.js`, `themes/default/js/ui/**`,
`themes/default/js/plugins/**`, `jquery.geoip.js`) stay out of scope —
already ESLint-ignore-listed, decided in P49. Depends on P38.

**P47 — `getPageData<T>()` typing + `any` reduction (TS half).**
`getPageData<T>()` consumes P37's island; TypeScript `any` driven to zero
across P46's output. Real type-design work, not a mechanical rename.

**P48 — Refactor TS into modules.** Breaks up monolithic per-page scripts
into proper ES modules (shared utils, per-feature entry points), one Vite
entry per real page bundle.

**P49 — Remove jQuery.** An explicit per-surface decision, not a blanket
removal: first-party call sites (native DOM/fetch), the vendored bundle
itself (delete once nothing references it), `themes/default/js/ui/**` and
`themes/default/js/plugins/**` (selectize, jqtree — replace or keep
vendored per widget), `jquery.geoip.js`, and the installer's own separate
`jquery.packed.js` load, which is a third easy-to-miss surface with
thinner coverage (`composer test:install` only). `pngfix.js` is not in
scope — it is an IE shim, not a jQuery plugin, already removed in P35.

**P50 — Lit component catalog.** Conditional on P49's findings, and still
parity-only. Just for widgets P49 finds no reasonable vanilla replacement
for — tag autocomplete and tree picker are the likely candidates. Skipped
entirely if P49 turns up nothing that needs it.

**P51 — TS modernization.** An idiomatic pass over the now-modular,
jQuery-free, fully-typed codebase from P46–P50. Same behavior.

**P52 — CSS architecture modernization.** `@container` queries, `@layer`
cascade. Same visual output, proven via VR baselines. Depends on P39,
not on the JS track, so parallelizable with all of P46–P51. Includes
confirming that nothing in the vendored plugin RTL rules
(`selectize.dark.css`, `jqtree.css` — the only RTL handling anywhere in
this repo) regresses if P49 touched those files.

**The Tailwind decision, pulled forward and resolved: not adopted.**
Decided before P40 started, per this section's own reasoning (adopting
late would mean rewriting `class=` across all 135 templates a third
time, on top of P40/P41's own restructuring). P39 (Inline CSS
extraction) already built an extensive vanilla per-theme utility-CSS
architecture — `themes/{admin/default,default,standard_pages}/css/
utilities.css`, `css/pages/*.css`, `css/components/*.css` — kept
as-is rather than partially replaced. P52's own scope here is
therefore `@container`/`@layer` modernization of that existing
architecture, not a utility-framework migration.

#### New-feature track — lands last

**P53 — Picture pipeline.** `<picture>` AVIF/WebP variants plus ThumbHash
blur-up placeholders: new image formats and a new loading-placeholder UX.
Independent of the refactor track; kept last per the modernize-first
ordering rather than for a technical dependency. Soft-depends on P36 if
generated variants should be served through the Vite manifest.

**P54 — Dark mode.** A new user-facing capability (theme toggle,
`prefers-color-scheme`). Depends on P52 — it needs the modernized cascade
layers and custom properties to add a theme dimension onto cleanly.

#### Closing gate

**P55 — Real quality gates.** `lighthouserc.json` has no `assert` block
today and is collect-only; `.size-limit.json` has one 1 KB placeholder
budget, whose own name still cites a pre-renumbering phase. Wires real
Lighthouse perf, a11y and best-practices thresholds and real per-entry
`size-limit` budgets, and decides whether the risk register's claimed
"a11y gate" becomes a real automated check. Needs P35–P54's real bundles,
templates and features to measure against.

## Greenfield tracks (T3, cuttable — outside the P0–P55 backbone)

All entirely cuttable, never gating a backbone commit, dropped first on
overrun. None have started; each depends on backbone phases that have not
landed.

- **T3·WEB** — PWA, View Transitions, Speculation Rules, JSON-LD, SRI,
  resource hints. Depends on P36 (asset pipeline), P31/P33 (Latte
  templates) and P52 (CSS architecture).
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
- **P43's no-escape-hatch decision means core must be extended for novel
  needs.** Accepted explicitly; the consequence already absorbed is
  plugin-owned routes.
- **P43's built-in filter swaps have real semantic differences.** Check
  each; golden-HTML catches the rest.
- **P36's fork is decided (view-declared) — shell-last composition is
  retired by P41's own single, one-time cutover, run only after P40's
  page-family campaign fully completes, not interleaved with it.**
- **P52's Tailwind decision, resolved: not adopted.** Kept the vanilla
  per-theme utility-CSS architecture P39 already built; P52 modernizes
  it via `@container`/`@layer` rather than migrating to a utility
  framework.
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
and later still touch. Making it real is P55's call.

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
work but are gated nowhere until P45.

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
| SEC-10 | P9→P17–P23 | Remove `addslashes()` superglobal sanitization | Done (confirmed) — `addslashes()` on every superglobal in `RequestBootstrap::bootEntryPoint()` was corrupting data (`O'Brien` stored as `O\'Brien`), masked by 71 compensating `stripslashes()` calls; fixed `aba74c9129` |
| SEC-11 | P9 | CSRF token md5→sha256 HMAC | Done (confirmed) |
| SEC-12 | P9 | CSRF verification via `hash_equals()` | Done (confirmed) — holds for `CsrfService`; the WS layer's own copy, `Ws\WsHelper`/`WsCsrfGuard::checkSecurityToken()`, used `!==` not `hash_equals()` across all 41 real call sites; fixed `b38c5f0877` |
| SEC-13 | P9 | `CookieService` HttpOnly + Secure flags | Done |
| SEC-14 | P9 | Cookie deletion calls include all flags | Done |
| SEC-15 | P20 | Eliminate 2 of 3 `eval()` calls (3rd = SEC-49) | Done |
| SEC-16 | P19 | Wrap `exec()` calls with `escapeshellarg()` | Done (confirmed) — 4 of 16 real `exec()` sites escaped nothing (`Admin/Image/ImageBackend.php`, `Admin/MaintenanceActionsPageRenderer.php`, `Ws/Core/GetCacheSizeHandler.php` ×2), admin-to-shell via DB-settable config; fixed `c6a63c8143` |
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
