# Plan and build history

Phase-by-phase record of `17.x-rewrite`'s development: what was planned,
what actually shipped, and where the two diverge. One current status per
phase, not several documents to cross-reference by hand.

`17.x-rewrite` replays `16.x-rewrite`'s modernization in 46 strictly-sequential
backbone phases (P0–P45, grouped into 10 epochs A–J — every backend phase
sequenced before every frontend phase), rebuilt from `origin/16.x` rather
than upgraded in place. Dual-purpose: a *replay* of work with a reference
implementation on `16.x-rewrite`, plus *greenfield* net-new capabilities
with no counterpart there. Full conventions (REPLAY vs. GREENFIELD
tagging, T1/T2/T3 tiers, the "every landed commit green, baselines
ratchet" working rule) carry forward unchanged from the original plan —
see "Conventions" below.

## Real status vs. commit-tag labels — read this before the table

Commit-message phase tags (`feat(p24): ...`) and the phase definitions
below diverged starting around P24. This matters for reading the table
correctly:

- **P24 is the real, formal designation for the post-P23 remediation
  era** — matching the `(p24)` commit-tag convention (405 commits as of
  2026-08-03). The *original* plan's P24 ("Vite + TypeScript conversion")
  is unaffected in scope but renumbered **P29**, moved after every backend
  phase so all frontend work sequences last — see the Phase detail
  section below for the current P24–P45 order. What actually landed under
  the `p24` tag (plus, not `(p24)`-tagged but the same post-P23
  remediation effort in substance — a SQL bound-parameter sweep and the
  singleton/DI elimination campaign): retiring the `$GLOBALS`/
  static-bridge coupling, migrating domain repositories from DBAL to real
  Doctrine ORM, retargeting the event-dispatch and `l10n()`/URL
  free-function bridges onto real classes, closing gaps a 2026-07-13
  audit found in P0–P23's own claims, a coverage/mutation-testing
  hardening sweep, and type correctness/mixed elimination + superglobal/
  array-offset access. Most of this was tracked in its own planning docs
  (`legacy-coupling-retirement.md`, `gap-closure-p0-p23.md`) at the time,
  under the `p24` tag as a matter of sequencing convenience ("whatever
  comes after P23"). See the P24 section below for the real work.
- **P25, renumbered P30** ("Inline JS extraction + `any` reduction") —
  not started. The 52 `(p25)`-tagged commits are PHP `mixed`-type
  elimination (Phase 1–2, by domain module), continued directly into the
  `(p27)`-tagged work covered next. Unrelated to the original P25 (now
  P30) scope.
- **The original plan's P27** ("Type correctness + mixed elimination") —
  the 89 `(p27)`-tagged commits continue the same mixed-elimination
  effort (Phase 4: replacing ambient `$_POST`/`$_GET` superglobal reads
  with typed Request DTOs) plus real security fixes found along the way
  (SQL injection, request-array scope). Merged into P24 — it's the same
  class of work as P24's own remediation sub-tracks (superglobal/
  raw-array-offset access, type correctness beyond what P0–P23 shipped),
  not a separable phase.

**This merge frees up a number, cascading a shift through every phase
after it in this document's own scheme**: this doc's P27 "Security" →
**P26**; P28 "Plugin/Theme contracts" → **P27**; P29 "Layer decoupling" →
**P28**; P30 "Vite + TypeScript" → **P29**; P31 "Inline JS extraction" →
**P30**; P32 "Template migration" → **P31**; P33 "CSS modernization" →
**P32**. Every "renumbered P##" note in this section and the table below
reflects the current numbers.

**This consolidation cascades one more time**: original P29–P32 (Vite+TS
conversion / inline JS extraction / template migration / CSS
modernization) expand into **P29–P45** — each of the 4 old phases mixed
multiple unrelated concerns (e.g. old P29 bundled real Vite entries,
JS→TS conversion, jQuery removal, and a Lit catalog into one phase; old
P32 bundled CSS architecture work with dark mode, a new feature); split
into 17 single-concern phases, ordered refactor/modernization first
(Latte lint/format + idiomatic cleanup sequenced right after P31, not at
the track's tail, since neither depends on the JS/CSS/TS work) and any
phase adding a genuinely new capability (Picture pipeline, Dark mode —
now P43–P44) last, gates closing the epoch at P45. P31 (Smarty→Latte,
`p31.x` commits, since completed) keeps its number unchanged. See Epoch J
below for the full breakdown.

**1 commit landed under the tag `chore(p32): delete doc/`**, not the
"layer decoupling + repository restructure" phase's full scope — a
narrow, unrelated cleanup that borrowed the tag. (That tag's "P32" refers
to a still-older, pre-consolidation numbering for the layer-decoupling
phase, which is today's P28 — unrelated to Epoch J's own numbering (that
old P32/CSS-modernization slot is today's P42). See that entry's own note
in Epoch I below.)

Where the table below says "diverged," this is what it means: check the
phase's own section rather than assuming the commit count maps cleanly
onto the original scope.

## Status (re-derived from git log + live code)

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
| P14 | DB layer + Doctrine ORM | Done — Doctrine Migrations, reversed for a time in favor of a static SQL schema, are reinstated for real (see "Migration path" below) | 4 |
| P15 | Schema migration + multi-provider | Done | 6 |
| P16 | Typed facades + constants + language | Done | 7 |
| P17 | Domain tier 1 | Done | 14 |
| P18 | Domain tier 2 | Done | 4 |
| P19 | Domain tier 3 | Done, 1 gap found (`Common` namespace never built, see below) | 12 |
| P20 | Domain tier 4 | Done | 10 |
| P21 | Admin controller migration | Done | 4 |
| P22 | Frontend controller migration | Done | 7 |
| P23 | Legacy deletion & cleanup | Done, gaps found in later audits (see below) | 123 |
| P24 | Post-P23 remediation & hardening (globals/DBAL/event/l10n coupling retirement, coverage + mutation-testing hardening, SQL bound-parameter sweep, singleton/DI elimination, type correctness + mixed elimination + superglobal/array-offset access — the original plan's P27, merged in, see above — plus the table-prefix + `Tables::` removal) | In progress — remediation sub-tracks done, 2 gaps found (see below); singleton/DI campaign **complete** (Phases 0–12F, zero shims remain); type-correctness/mixed-elimination sub-track real progress (Request DTO migration, Phase 4), not complete; table-prefix + `Tables::` removal **complete** | 405 `(p24)` + 16 `(sql)` + 74 `(di)`/`(lang)` + 89 `(p27)` + 62 (table-prefix removal) |
| P25 | REST resource layer + OpenAPI (WS API removed) | Not started | 0 |
| P26 | Security hardening | Not started | 0 |
| P27 | Plugin / Theme contracts + bundled extensions | In progress — P27.0-P27.5 and P27.7-P27.10 done; P27.6 (porting the 7 bundled extensions) in progress in a separate session, not yet landed | 17 |
| P28 | Layer decoupling + repository restructure | Not started (1 unrelated commit borrowed the tag — `doc/` cleanup) | 1 |
| P29 | Browserslist decision + legacy back-compat removal | Not started | 0 |
| P30 | Asset-pipeline foundation (ScriptLoader/CssLoader/FileCombiner retirement + ViteManifest resolution) | Not started | 0 |
| P31 | Smarty → Latte template migration | Done — all 139 real templates converted, Smarty engine fully removed (`smarty/smarty` dropped, `Template.php` Latte-only). Deferred asset-pipeline items (`ViteManifest`, `<picture>`, ThumbHash) out of scope, pick up under P29/P30/P43 | 80 |
| P32 | Latte lint/format | DONE. Format-half: `tools/latte-prettier/` (real Prettier plugin, 135/135 real-tree coverage, all 126 reformatted templates manually reviewed line-by-line). Lint-half: `composer lint:latte` + `precompile:templates` + a Piwigo-native phpstan-latte pipeline (`tools/phpstan/Latte/`, replacing an initially-vendored efabrica/phpstan-latte fork after a deep upstream review) — `bin/piwigo phpstan-latte:compile` compiles all 135 templates with typed `@var` injection + shim-rewritten filter/function calls into `_analysis/phpstan-latte/`, analysed by plain `phpstan analyse` (parallel, result-cached) with errors mapped back to real `.latte` lines via an `errorFormatter.table!` override. Two follow-up campaigns shrink the remaining scoped ignores: context-docblock enrichment (~1,400 mixed-flow findings across 130 TemplatePageContext classes) and template-source modernization (~450 loose-`==`/`empty()` findings) | 11 |
| P33 | Latte idiomatic modernization | Not started | 0 |
| P34 | Inline JS extraction | Not started | 0 |
| P35 | Inline CSS extraction | Not started | 0 |
| P36 | JS → TS mechanical conversion | Not started (mixed-elimination work landed under P24 instead, see above) | 0 |
| P37 | Typed page-data bridge + `any` reduction | Not started | 0 |
| P38 | Refactor TS into modules | Not started | 0 |
| P39 | Remove jQuery | Not started | 0 |
| P40 | Lit component catalog (conditional on P39) | Not started | 0 |
| P41 | TS modernization | Not started | 0 |
| P42 | CSS architecture modernization (`@container`/`@layer`/Tailwind) | Not started | 0 |
| P43 | Picture pipeline (new feature — AVIF/WebP, ThumbHash) | Not started | 0 |
| P44 | Dark mode (new feature) | Not started | 0 |
| P45 | Real quality gates (Lighthouse assert + size-limit budgets) | Not started | 0 |

Two adjacent, non-phase-numbered tracks, both not started:

- **FrankenPHP worker mode** (SEC-60, a P7 gap found in the 2026-07-13
  audit) — `docker/Caddyfile` still plain `php_server`, no `worker` block.
- **Legacy import tool** (`bin/piwigo import:legacy`, described by
  `docs/REFERENCE.md`'s "Clean fork, no in-place upgrade from upstream
  Piwigo" decision) — no `import:legacy`/`ImportLegacy` reference
  anywhere.

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

Detail for each phase/epoch follows below. Kept condensed: current
tool/system state lives in `docs/REFERENCE.md`, not duplicated here —
this section records what each phase delivered and any real corrections
found along the way, not a re-derivation of present-day config.

### Epoch A — Foundation (P0–P4)

**P0 — PHP tooling + baselines.** Installed Pest + plugins, pcov, ECS,
PHPStan, Psalm, Rector, Deptrac (config deferred to P6), ComposerRequireChecker/
Unused, PHPBench, roave/security-advisories — additive only, no first-party
code modified. Baselines recorded, not yet gated (ECS/Rector became
code-modifying passes later; Psalm gating was paused at P5, then Psalm was
dropped as a dependency entirely, then later reinstalled non-gating — see
the P5 entry below for the full history).

**P1 — Frontend tooling + baselines.** bun/Vite/TS/ESLint/Stylelint/Vitest,
knip, size-limit, commitlint, Lighthouse CI, `web-vitals` installed. A
2026-07-13 audit found `web-vitals` was installed but never wired to a
real endpoint — no beacon, no `/analytics/vitals` route. Fixed the same
audit cycle: `build/vitals.ts` + `VitalsController` + route, log-only (no
dashboard yet) — see `docs/REFERENCE.md`'s Development section for
current state.

**P2 — Test harness.** Env split (`.env.test`, `X-Piwigo-Env: test`
header), fixture DB (`tests/Fixtures/piwigo-17.0.sql`), Pest Browser E2E +
WS Contract suites established. Foundation for every later phase's own
test coverage.

**P3 — CI pipeline.** `ci.yml` job layout, matrix, caching; actionlint,
commitlint, SBOM/OSV jobs, OpenSSF Scorecard.

**P4 — Containerization + runtime image.** Multi-stage Dockerfile
(FrankenPHP + Apache-fallback targets), Compose, Helm chart, `/health`+
`/ready`, restore drills, SEC-01 web-root deny rules across all 3 server
targets. See `docs/REFERENCE.md`'s Deployment section for the live
details (image signing, hardening, web root isolation).

### Epoch B — Composer/Rector/PHPStan + PSR-4 (P5–P6)

**P5 — Composer + Rector + PHPStan (PHP modernization).** By far the
largest single phase by commit count (653) — whole-codebase ECS `--fix`,
PHPStan bleeding-edge rules applied file-by-file across the legacy tree,
vendored third-party library replacement (per `docs/REFERENCE.md`'s
native-platform-first library policy: PHPMailer → Symfony Mailer,
Emogrifier → `pelago/emogrifier`, phpqrcode → `endroid/qr-code`, vendored
Smarty → `smarty/smarty`, phpass → native `password_hash()`,
`mdetect.php` dropped with no replacement). Psalm's global-function-
resolution scanner didn't hold up against the still-non-namespaced legacy
codebase at this scale — investigated properly (ruled out cache
staleness, parallel-worker races), concluded it's a real tool limitation
at this codebase's shape, not a bug in the code. Psalm gating paused here
— PHPStan remains the sole blocking static-analysis gate. Psalm was later
dropped as a dependency entirely (2026-08-07), then reinstalled
(2026-08-11, `4118adbb85`, pinned to the `7.x-dev` branch — the latest
tagged release caps `sebastian/diff` below what Pest 5 needs) once
`psalm.xml`'s drifted path references were fixed and a real Psalm 7.x-dev
crash (undeclared `StatementsAnalyzer` properties, no upstream fix) was
patched via `composer-patches`. Both `composer.json`'s `vimeo/psalm`
entry and `vendor/bin/psalm` are real again today, along with a real
"psalm Batch 5/6/7" cleanup (`(p24)`-tagged) that fixed 25+ genuine
Psalm-flagged issues. Still not a CI gate — no dedicated `psalm` job
exists — so "gating is moot" still holds, but "dropped as a dependency"
no longer does; see `docs/REFERENCE.md`'s Psalm entry, which needs the
same correction.

**This finding's own "Fix" contradicts the code's own documented
rationale — flagging the contradiction, not resolving it.**
`Core\DeviceHelper::getDevice()`'s facts are still accurate: it has a
single writer, unconditionally sets `'desktop'` on every new session, no
User-Agent parsing exists anywhere in this codebase, and the only path to
the mobile theme is an explicit `?mobile=1` query param. But the method's
own inline comment states this is deliberate, not unimplemented: "No
UA-sniffing library (removed, no replacement...): the v17 responsive CSS
removes the need for a separate mobile theme via device detection.
`mobileTheme()` still honors an explicit `?mobile=1/0` override
independent of this default." That comment predates this finding (traced
to `915c6fec43`, a `docs(reference)` audit commit). The reference
implementation (`../piwigo16-rewrite`) kept `mobiledetect/mobiledetectlib`
and built a real `Http\DeviceDetectionService` on it, which this
finding's "Fix" recommends reintroducing — but nothing in this repo's own
history explains whether that reference approach was deliberately
rejected in favor of the responsive-CSS design the code now documents, or
whether the comment is itself just unvalidated rationale nobody reversed.
Same shape as the `LocalConfigOverrides` finding elsewhere in this doc:
flagging precisely rather than guessing which one is the real intent.

**Both halves of this finding are resolved, not a P0 placeholder
anymore.** `rector.php` no longer carries the "deliberately provisional"
header at all — live config has `withPhpSets(php85: true)` and
`withPreparedSets(typeDeclarations: true, instanceOf: true)` both active
(not commented out), plus `withImportNames()` and `withParallel()`,
neither part of the original finding. Landed in two real commits
(`c49a00014d` "Apply Rector's PHP 8.5 rule set across the codebase",
`0bfc324f59` "Apply Rector's typeDeclarations and instanceOf prepared
sets" — both ran the rules and applied their fixes tree-wide, not just
flipped a config flag) with one real regression along the way: an
intermediate commit (`6cf68f157b`) briefly commented both back out again
while doing an unrelated FQCN-import sweep, before the two commits above
re-enabled them for good. Still narrower than the reference
implementation's set (no `withComposerBased(doctrine: true, phpunit:
true, symfony: true)`, no explicit `SetList::TYPE_DECLARATION` or
strict-types/dead-tag-removal rules) and the `rector` CI job stays
`continue-on-error: true` (non-blocking) regardless of rule-set
richness — so there's still real headroom, just not "essentially
unconfigured" anymore. Separately, `phpstan/phpstan-deprecation-rules`
**is** in `composer.json` today (`^2.0`) — the doc's second claim ("no
`phpstan/phpstan-deprecation-rules` include") no longer holds either.

**Direct consequence of the Rector gap above: every PHP 7.1/8.3/8.4/8.5
language feature with a real candidate site is still unadopted.** A full
sweep of every 7.x/8.x language feature against real usage found every
7.0–8.3 feature either heavily used or correctly not applicable (no fit,
or superseded by a later feature this codebase already uses instead) —
genuine, ongoing modernization discipline that stops dead exactly at the
version Rector's own `php85` set is commented out. Real, scoped candidates
found:

- **Multi-catch (PHP 7.1)** — `Http\HttpClientService.php:245-247`:
  `catch (ClientExceptionInterface)`/`catch (InvalidArgumentException)`
  both do exactly `return null;`, the second with a long comment
  explaining why an `InvalidArgumentException` from URL parsing deserves
  the same graceful handling as a `ClientExceptionInterface` from network
  I/O — merge into one `catch (ClientExceptionInterface|InvalidArgumentException)`.
  The only real candidate found; every other multi-`catch` site in this
  codebase has genuinely different per-type handling (checked all 4 files
  with adjacent `catch` blocks) or a deliberate rethrow-vs-swallow split
  that must stay separate (`Controller\ImageDerivativeController.php`'s
  `ResponseReadyException` rethrow past a broader `Exception` catch is
  security-critical — a real anonymous request for a private album's
  derivative was served instead of denied when this ordering broke once).
- **`json_validate()` (PHP 8.3)** — not yet audited for real candidates:
  any `json_decode($x) !== null`-only-for-validity check (never using the
  decoded value) is a direct replacement.
- **Property hooks + asymmetric visibility (PHP 8.4) — done, not a
  candidate anymore.** Both prerequisites this entry named are resolved:
  `Config\CurrentConfig` now declares every property `public
  private(set) TYPE $name` directly (confirmed: 0 remaining `public
  function get`/`public function set` methods in the file, down from
  5225 lines to 2626 — real getter/setter boilerplate removed, not just
  reformatted); the class's own docblock states this outright ("Every
  real config key is a real typed property (PHP 8.4 property hooks +
  asymmetric visibility)"). Call sites converted project-wide
  (`e6bdedf369`, "call-site sweep for CurrentConfig property syntax");
  `ConfigService::confUpdateParam()`'s external write path now uses
  `ReflectionProperty`+`setValue()` directly against the asymmetric-visibility
  property, not a `ReflectionMethod` call to a setter — exactly the
  retarget this entry said was still needed.
- **`array_find`/`array_any`/`array_all`/`array_find_key` (PHP 8.4)** —
  not yet audited for real candidates: likely `foreach`+`break` and
  `array_filter()`+count-check patterns across the domain services would
  simplify.
- **Native `#[\Deprecated]` attribute (PHP 8.4)** — not a current fix (the
  singleton/DI campaign's docblock-`@deprecated` convention is fully
  retired, zero shims remain), but the better default if a transitional
  shim is ever needed again — real IDE/static-analysis visibility a
  docblock tag doesn't give.
- **`array_first()`/`array_last()` (PHP 8.5)** — not yet audited for real
  candidates: `reset($arr)`/`end($arr)`/`$arr[0]`/`$arr[count($arr) - 1]`
  patterns are the direct replacement target.
- **`#[\NoDiscard]` (PHP 8.5)** — not yet audited for real candidates: a
  method returning a validation result or a `bool` success flag that a
  caller could silently ignore is the fit.
- **Pipe operator `\|>` (PHP 8.5)** — 34 real call sites with 3+ levels of
  nested function calls found (candidate pool, not individually read yet).

**P6 — PSR-4 namespace migration.** Extracted every first-party `class`/
`interface` declaration living inside `include/`/`admin/include/`
procedural files into `src/Piwigo/`, `Piwigo\` namespace prefix — 66
classes/interfaces across 33 origin files. Extraction and namespacing
only, not a rewrite: no renaming to modern casing, no DI, no behavior
changes beyond what the move itself forced. Established the 6-layer
Deptrac model (L0Data → L4Integration, L2a/L2b domain split) — enumerated
per-namespace, not a catch-all regex, so a later phase adding a namespace
has to deliberately choose its layer. Deptrac 4.6.2 silently breaks
ruleset resolution when a layer name contains a hyphen — the original
`L0-Data`/`L1-Infrastructure`-style names made every legal cross-layer
dependency misreport as a violation; fixed by dropping hyphens from every
layer name. See `docs/REFERENCE.md`'s Architecture section for the full,
current namespace/layer table (P6 seeded roughly 20 of the namespaces;
every phase since P7 has added more).

### Epoch C — Kernel & HTTP foundation (P7–P12)

**P7 — Kernel + boot skeleton.** `Kernel`, `CommonBootstrap`,
`public/index.php`, fast-paths. A 2026-07-13 audit (SEC-60) found the
FrankenPHP worker loop was never implemented — classic per-request
execution on the FrankenPHP binary, not true worker mode; only 5 of 13
request-scoped static/singleton classes had a `reset()`-is-test-only arch
test. Worker mode is still open — see the status table above and
`docs/REFERENCE.md`'s "What's genuinely not built yet." Deliberately
deferred past P23 (the bootstrap-chain replacement work changes what
state needs resetting, so doing the audit twice would be wasted effort) —
P23 is long done and this hasn't been picked back up.

**P8 — DI container.** `Container`, `config/container.php`, PHP-DI
autowire-by-default.

**P9 — PSR-15 middleware + routing.** 7-stage middleware pipeline
(`RequestPipeline.php`'s own registration list: `ExceptionHandler`,
`SecurityHeaders`, `Session`, `ServerTiming`, `Sentry`, `Routing`,
`ControllerInvoker`). **The "no record explains which stage" gap is now
answered, not just narrowed**: checked `16.x-rewrite`'s own pipeline
directly (`Core\Kernel.php`, not `RequestPipeline.php` — different file
name there) — its real 8 stages are `SecurityHeaders`,
`ExceptionHandler`, `Session`, `Auth`, `Filter`, `Csrf`, `Routing`,
`ControllerInvoker`. Not "this fork omits 1 stage" — it's a different
composition entirely: the reference has `AuthMiddleware`/
`FilterMiddleware`/`CsrfMiddleware` as real pipeline stages that this
fork doesn't (this fork's own `Http/Middleware/` has no `Csrf`/`Auth`
middleware class at all — confirmed, consistent with SEC-42's own "CSRF
middleware: remove `/admin*` exemption... Not started (P26)" implying no
such middleware exists yet to have an exemption from), while this fork
adds `ServerTiming`/`Sentry` the reference doesn't have. *Why* auth/
CSRF/filter checks moved off the pipeline here (into services/
controllers directly, presumably) — deliberate design or a genuine gap —
isn't established by this comparison alone; flagging the real
composition difference precisely rather than guessing the intent behind
it. Routes, extensible
`SecurityHeadersMiddleware`, cross-server SEC-01 deny rules. A 2026-07-13
audit (SEC-11/SEC-12) found `CsrfService` still used
`hash_hmac('md5', ...)` + `===` despite the identical weak-hash pattern
being correctly fixed in the sibling `AuthService`/`EphemeralKeyService`
during P18 — a later P17/P18 fix (`a64fccbb6`) had touched the same file
for an unrelated bug (DB-persisted secret key) and never caught it. Fixed
in the pre-P23 remediation pass — `CsrfService` now uses
`hash_hmac('sha256', ...)` + `hash_equals()`.

**P10 — Observability.** Monolog channels, Server-Timing,
OpenTelemetry-first (OTLP → Sentry/Tempo/Jaeger). Greenfield, no reference
implementation.

**P11 — Cache + session + messenger + `opcache.preload`.** `symfony/cache`
pools, session handler, Messenger, preload list. A 2026-07-13 audit found
the "named cache pools" design (`config`/`permissions`/`category_tree`/
`tag_cloud`/`rate_limiter`/`general`, each its own TTL) was never built —
`CacheFactory` produced one generic pool, zero real consumers besides
`CacheClearCommand`. Load-bearing for P23's own cache-table-rationalization
gate. Fixed in the pre-P23 remediation pass — `CachePools` built on top
of `CacheFactory`; `rate_limiter` specifically stays unbuilt (genuinely
P26 scope, no consumer exists yet). Messenger itself is real and wired
(`config/messenger.php`, 5 `Piwigo\Job\*` classes + handlers) — see
`docs/REFERENCE.md`.

**A failed job today is invisible and unmanageable.** No code anywhere in
this codebase queries the `messenger_messages` transport table — if a
`SendNotificationEmailJob`/`GenerateDerivativeJob`/`BatchUploadJob`/
`ReindexImagesJob`/`RegenerateAllDerivativesJob` fails, there's no way to
see it, retry it, or purge it. The reference implementation has
`Job\MessengerRepository`/`Job\FailedJob` backing "an admin batch-manager
queue dashboard to inspect failed jobs and retry/purge them" (that
class's own docblock). Fix: build the equivalent repository + a small
admin-facing view, the same pattern this fork already uses elsewhere for
DB-backed admin tooling.

**P12 — CLI tool + backup/restore + graceful shutdown.** `bin/piwigo`,
`BackupService`, `ShutdownHandler`/SIGTERM cleanup, PHPBench. A
2026-07-13 audit found all 4 `maintenance:*` commands (`orphan-tags`/
`purge-history`/`purge-sessions`/`repair-db`) were planned but never
built. All 4 are now real: `MaintenanceOrphanTagsCommand`/
`MaintenancePurgeHistoryCommand`/`MaintenancePurgeSessionsCommand` landed
in the pre-P23 remediation pass; `MaintenanceRepairDbCommand`
(`maintenance:repair-db`) was deferred longer — its backing logic lived
in a legacy file P23's own absorption work still had to touch — but is
built too now, backed by `DbMaintenanceRepository::repairOptimizeAllTables()`
(the same method the admin web UI uses) — see `docs/REFERENCE.md`'s CLI
section.

### Epoch D — Config/DB/language (P13–P16)

**P13 — Config service.** 277-entry `SCHEMA`, `ConfigLoader`, typed
accessors. A 2026-07-13 audit found the `$conf` → `Config` migration had
stalled — 72 `src/Piwigo/` files still read `global $conf` directly, not
from incomplete migration but because `Config::` accessors were provably
unsynced with DB-persisted values (`ConfigService::loadConfFromDb()`
wrote into the legacy `$conf` global but never into `Config::$data`) —
the root cause of a real shipped bug (`CsrfService` reading an empty
`secret_key`). Fixed in the pre-P23 remediation pass (`ConfigDb`'s write
paths were made to call `Config::override()`/`Config::delete()` alongside
the legacy `$conf` write) and finished by Track A of the
legacy-coupling-retirement work below — zero `global $conf` reads
anywhere in `src/Piwigo/` today. `ConfigDb` was later merged into
`Piwigo\Config\ConfigService` directly (no standalone `ConfigDb` class
exists today), and `Config::override()`/`::delete()` no longer exist at
all — the typed-`CurrentConfig` refactor (see below) replaced them with
reflection-based named-setter calls (`ConfigService::confUpdateParam()`
invokes `CurrentConfig::set{Property}()` via `ReflectionMethod`). The
underlying fix (DB writes reaching the live config object, not just the
legacy global) holds under the new names.

**`#[Required]`/`#[Sensitive]` on `CurrentConfig` properties: real
consumers now exist for both, but neither is actually wired up yet —
"markers only" is no longer accurate, "not enforced" still is, for a
different reason.** `Required`/`Sensitive` are still empty attribute
classes themselves (confirmed), but each now has a genuine reflection-based
reader: `ConfigLoader::validateRequired()` — exactly the "boot-time...
check that every `#[Required]` property is non-empty" this section's own
"Fix" asked for — throws `MissingRequiredConfigException` for a missing
`#[Required]` property; `CurrentConfig::dumpForLog()` — exactly the
"log/dump redaction" this section's "Fix" asked for — returns every
property with `#[Sensitive]`-tagged ones replaced by `str_repeat('*', 8)`,
its own docblock stating "Intended for safe use in error-handler logs and
diagnostic output." **Neither is actually called from anywhere** —
confirmed via a repo-wide grep, both are real, correct, unreferenced
methods, not wired into boot or the error-handler pipeline. So today's
real state is "built but dead code," not "doesn't exist" — a materially
different, more specific problem than this section currently describes,
and a smaller remaining task (wire the two calls) than "give X a real
consumer" implied. `secretKey` still carries both, `smtpPassword` still
carries `#[Sensitive]` alone (unchanged) — auditing which other
properties should carry `#[Sensitive]` (mail/API credentials) is still
open.

**P14 — DB layer + Doctrine ORM.** Two real corrections, both since
resolved:
- The "repositories as real Doctrine ORM `EntityRepository` subclasses
  from day one" design was followed only for `ConfigRepository` itself —
  all domain repositories built in P17–21 used `AbstractRepository`+
  `Tables::` (DBAL) instead, which had become the real, working, tested
  pattern for query-heavy repositories, not a legacy-only shim. Migrating
  all of them for real was tracked as its own remediation initiative,
  sequenced after P23. **Update, since superseded again**: the "8
  repositories deliberately stay on `AbstractRepository`/DBAL" tier this
  section originally described no longer exists — `Db/AbstractRepository.php`
  itself has been deleted (confirmed: zero files in `src/Piwigo` extend
  it). `find src/Piwigo -iname '*Repository.php'` gives **38 real domain
  repositories** today (no shared abstract base file left to subtract),
  split two ways instead of three: **23** extend
  `Doctrine\ORM\EntityRepository` (not Symfony's `ServiceEntityRepository`
  — still unused, this codebase doesn't run on the Symfony framework/
  DoctrineBundle) — `Activity`, `Audit`, `Caddie`, `Comment`, `Config`,
  `Feed`, `Group`, `History`, `Image`, `Image\DerivativeSettings`,
  `Image\DerivativeSize`, `Lang`, `Notification\NotificationByMail`,
  `PluginConfig\Plugin`, `Rate`, `Session`, `Site`, `Tag`,
  `Admin\Extensions\PluginMigration`, `Admin\Extensions\ExtensionIgnoredUpdate`,
  `Admin\Integrity\IntegrityIgnoredAnomaly`, `Core\Theme`,
  `Auth\UserFailedLogin` — the last five, plus `Caddie` and
  `NotificationByMail`, weren't previously on this list at all (new
  repositories, or migrated off the now-gone DBAL tier since). **15**
  extend neither base class, holding `EntityManagerInterface` directly via
  constructor injection instead: `Permission` (`user_access` is a shared
  join table with no single owning repository), `Auth`, `Auth\ApiKey`,
  `Auth\Password`, `Metadata`, `Permalink`, `Admin\Maintenance\Db`,
  `Admin\Extensions\Extension`, `Calendar`, `Search`, `Section`,
  `Notification`, `Mail\Recipient`, `Category`, `Users\User` — each
  touches tables *other* repositories own
  (`Users\UserInfoEntity`/`Auth\UserAuthKeyEntity`/
  images/categories), reaching them via DQL for simple writes or plain
  DBAL for reads/dynamic fragments (`Search\SearchRepository`'s own
  current docblock: query/column/operator combinations that vary per
  caller "has no DQL representation" — the same rationale the old
  DBAL-tier docblocks gave, now applied directly instead of through
  `AbstractRepository`), never claiming ownership of a table itself.
  `Category` and `User` (`final readonly class CategoryRepository`/
  `final readonly class UserRepository implements
  WebmasterMailProviderInterface`, neither with an `extends` clause) both
  moved into this group since this section was last written — previously
  listed as `EntityRepository` subclasses.
- The Doctrine Migrations decision itself was reversed on 2026-07-24,
  before any real install existed — real installs created the schema
  from a static, hand-maintained `install/piwigo_structure-mysql.sql`
  instead; `doctrine/migrations` was temporarily not a dependency.
  `InstallWizard`'s own flow was already hardcoded to MySQL only, so the
  multi-provider migration path this enabled never backed a real
  installable option at the time. Migrations were later reinstated for
  real during the pgsql-support pass — see "Migration path" below for
  the current mechanism.

**P15 — Schema migration + multi-provider.** InnoDB+utf8mb4 uniformly, 7
new tables, FK constraints, `audit_log` (SEC-57). Cache tables
(`user_cache`, `user_cache_categories`, `history_summary`) originally got
engine/charset only, type-norm skipped — `user_cache`/`user_cache_categories`
were later dropped entirely (P23 gap-closure Stage 4, once every real
consumer moved onto TTL cache pools instead); `history_summary` was kept
and got its own type fix (`summary_id` AUTO_INCREMENT PK) separately.

**P16 — Typed facades + constants retirement + language.** `Paths`/
`CurrentUser`/`PageState` facades, 52 `define()` constants retired, `.po`
migration, ICU MessageFormat pluralization. A 2026-07-13 audit found
`src/Piwigo/Template/` (8 classes with real logic — `Template`,
`ScriptLoader`, `CssLoader`, `FileCombiner`, `Combinable`, `Css`,
`Script`, `PwgTemplateAdapter` — the last renamed `TemplateAdapter` on
2026-08-11, `d2d5b72398`, part of a broader same-day sweep dropping
leftover `Pwg`-prefixed legacy names repo-wide, alongside the separate
WS-layer rename noted in Epoch F below) had zero dedicated Unit test
coverage, only indirect exercise via the Browser suite. Fixed in the
pre-P23 remediation pass — all 8 classes have real `tests/Unit/Template/`
coverage now. (`Template/` has grown since — `CurrentTemplate.php`,
`LatteEngine.php` — neither part of this specific 8-class/coverage
finding.)

### Epoch E — Service layer (P17–P23)

**P17–P20 — Domain tiers 1–4.** The ~35 domain namespaces, migrated in
dependency order (each tier only depends on the ones before it): **Tier 1**
(no service deps) URL, Cookie (built as `Piwigo\Auth\CookieService`, not a
standalone `Cookie` namespace — a real placement choice, not a gap like
`Common` below, since the functionality itself exists), Session, HTML,
Storage, Csrf, Permalink, Site, Feed. **Tier 2** Mail, Filter, User (the
real namespace is `Users`, plural), Auth, Tag, Comment, Rate, Group,
Caddie, History, Activity. **Tier 3** Category, Search, Image, Calendar,
Notification, Metadata, Telemetry, Validation, Common. **Tier 4** Page
renderers, Menu, PluginConfig, Section, Job. Each domain's legacy
`include/` file was deleted immediately after its migration, not batched
to the end.

**Largely closed since 2026-07-27 — this section describing it as an
open, undocumented gap is itself now stale.** `Common` (the last item in
Tier 3's own list above) is real today: `src/Piwigo/Common/` exists with
`ValueObject/` (19 files — `CategoryId`, `CommentId`, `Email`, `GroupId`,
`ImageId`, `IpAddress`, `LangCode`, `Md5Sum`, `NumericId`, `Permalink`,
`PluginId`, `SqlDate`, `SqlDateTime`, `SqlTime`, `StringVo`, `TagId`,
`ThemeId`, `UserId`, `Username`), `Enum/` (`Section`, `SortOrder`), and
`Dto/` (`PaginatedResult`, `UserGroupPair`) — built starting with
`063fd2ae30` ("build the typed-primitives VO/enum/DTO layer",
2026-07-27) and extended by at least two follow-up commits
(`7442956d34` adding `IpAddress`, `9bee81a071` adding `SqlTime`). **Not
fully closed**, though: two of the originally-named items are still
genuinely missing — no `AbsPath`/`RelPath` path-value-object layer exists
anywhere in `src/Piwigo` (checked), and no centralized `Privacy` enum
exists either (only `Users\UserStatus` stays domain-local, as already
noted). So the real state is "mostly built, two specific gaps remain,"
not "never built at all" — this section needs a rewrite reflecting that,
not a full retraction. No gaps otherwise found in
P17–P19; P20 had one (below).

**P21 — Admin controller migration.** "62 admin pages" was never a
target count of `AdminSubControllerInterface` services to build —
`PLAN-REPLAY.md`'s own "Codebase baselines" table shows it's the
`origin/16.x` (upstream Piwigo) raw `admin/*.php` file count being
replaced (that same table shows `16.x-rewrite`, the reference
implementation, consolidating those 62 files down to a much smaller
number itself). `config/admin_pages.php` maps exactly **37** page slugs
to `AdminSubControllerInterface` services today, matching the 37 classes
that actually implement the interface — a reasonable, consolidated count
replacing 62 legacy files, not a gap against an unmet target. Dispatch
itself is `Bootstrap\AdminDispatcher::dispatch()` (not `AdminController`,
which doesn't exist under that name) — built decomposed from the start,
not migrated-then-decomposed: the reference implementation's god-classes
(`MaintenanceController`, `MiscController`, `BatchManagerController`) were
never reproduced as monoliths here. Same rule applied to admin PEM
services (`PluginScanner`/`PluginLifecycle`/`PemCatalog` and theme/
language equivalents authored directly, not as `Admin/Plugins.php`-style
700-line god-classes).

**P22 — Frontend controller migration.** Originally scoped as 21 frontend
controllers; only 19 of those 21 names were ever built on this branch —
confirmed via a branch-scoped `git log` (not `--all`, which mixes in
`16.x-rewrite`'s own unrelated history and would wrongly suggest these
once existed here). `Install` was never meant to become a
`Controller\InstallController` in the first place — `public/install.php`
stays a special, unrouted entry point (it must work before any DB/config
exists, so it can't go through the normal DI-resolved Router/Controller
path), backed by `Bootstrap\InstallBootstrap` + `Admin\Install\InstallWizard`
instead — a legitimate different architecture, not a miss. `Upgrade`/
`UpgradeFeed` genuinely were never built, on any branch history for this
fork: no route, no controller, confirmed absent from `config/routes.php`.
Consistent with `docs/REFERENCE.md`'s "clean fork, no in-place upgrade"
stance and the later deletion of the entire `DbPatch`/`VersionUpgrade`
chain (see P23's gap-closure list below) — there's no upgrade mechanism
left to drive an `Upgrade`/`UpgradeFeed` controller, so their absence is
a real, consistent consequence of that design decision, not an oversight.
Render via an engine-agnostic `$vars` array (P31's Latte swap was a
one-line render-call change per controller, not a rewrite — P31 is
done; every controller's `parse()` call now renders through Latte, no
Smarty left anywhere). A 2026-07-13 audit found
`GalleryController` only relocated `include/section_init.inc.php`'s
`include()` call site into the controller — the ~450 lines of raw SQL
logic P20's own docblock said belonged here (`$page['items']`,
favorites, next/prev navigation) was never actually absorbed. Fixed —
folded into P23's Gallery/Picture absorption batch.

**P23 — Legacy deletion & cleanup.** The largest single phase in the
original plan and, per this fork's own execution, larger still — see
"P23 in detail" below. Headline outcome: `include/`/`admin/` (as
directories) are fully deleted, all `$GLOBALS`/static-bridge globals are
retired, the legacy `Tables`/`AbstractRepository` DBAL layer is gone
(migrated to real Doctrine ORM), and the event-dispatch/`l10n()`/URL
free-function bridges are retargeted onto real classes — zero
`global $x` statements, zero live `$GLOBALS` reads, zero bare legacy
free-function calls anywhere in `src/Piwigo/` today, each guarded by a
zero-tolerance Arch test.

**This fork deliberately diverged from the original P23 plan in real,
documented ways**, not silently:
- `include/` was **not** deleted entirely at the time — it kept a 4-file
  bootstrap seam (`common.inc.php`, `config_default.inc.php`,
  `env.inc.php`, an anti-listing stub) through P23's own batches, since
  SEC-60 needs `define()`s to stay out of `src/Piwigo/`. This has since
  been fully closed anyway — `include/` doesn't exist at all today; the
  bootstrap seam collapsed into `Piwigo\Bootstrap\RequestBootstrap`
  during the later `$GLOBALS` retirement work (Track A7, see below).
- Root entry points (`admin.php`, `picture.php`, etc.) were kept as thin
  shells rather than collapsed into one front controller — this fork
  keeps Piwigo's original URL surface. Since relocated into `public/` as
  part of web-root isolation (originally P28 scope, pulled forward).
- The `$GLOBALS`/static-bridge retirement bullets in the original P23
  plan were audited and deliberately not executed in P23 itself — the
  plan's premise ("zero callers remain after `include/` deletion") didn't
  hold in this fork, since ~230 `src/` files had real, live `global $x`
  contracts preserved verbatim by the migration discipline. Tracked
  instead as its own post-P23 initiative (below), now done.
- `Tables.php`/`AbstractRepository` were kept, not deleted, pending the
  post-P23 ORM remediation (P14's own audit note) — now done, see Epoch D
  above.

#### Gaps found in a later audit, since closed

A 2026-07-13 full P0–P22 audit and further re-investigation found P23's
own manifest entry claimed more than had actually landed. All findings
below are now resolved:

- **43 column-type migrations** — mostly done; mechanism changed (the
  Doctrine Migrations plan was reversed, see P14 above). 4 real columns'
  serialize()/unserialize() leaks fixed end-to-end via typed
  `CurrentConfig` accessors; a 4th column (`activity.details`, not in the
  original 43) found and fixed the same way.
- **Typed DTO/Projection pattern** — every one of the 31 real domain
  repositories now has at least one typed accessor returning a real
  object instead of a raw array (e.g. `SearchRepository::findOneByClause():
  ?Search`). One caveat: this doesn't mean every method on every
  repository returns a typed object — the 8 repositories intentionally
  kept on `AbstractRepository` (see P14 above) still have generic,
  raw-`array`-returning query executors (`SearchRepository::findRowsByClause()`,
  `CalendarRepository::findRows()`, and similar) alongside their typed
  accessor, by the same deliberate design documented in each one's own
  docblock, not a residual gap.
- **Per-namespace Unit test coverage** — 11 of 11 caught up.
- **`CachePools` full wiring** — done (see P11 above).
- **`die()`/`exit()` elimination (image-processing failure paths)** — the
  original finding's own 3 named files (`ImageGd.php`/`PwgImage.php`/
  `Admin\Upload\UploadService.php`, 17 of the original 34 call sites) now
  have 0 real `die()`/`exit()` calls, replaced by
  `ImageProcessingException` throws per that class's own docblock. Not
  the same claim as "0 `die()`/`exit()` calls anywhere" — 9 real call
  sites remain project-wide, across 9 other files; see the C3 workstream
  note below for why (this is the still-open request-lifecycle
  architecture gap, not a missed cleanup pass) — at least one of the 9
  (`ShutdownHandler::install()`'s `exit(143)`) is a correct, deliberate
  SIGTERM exit-code convention, not a gap at all.
- **`reset()` arch-test coverage** — done; 31 classes now have a tested
  `reset()` (up from the 13 the 2026-07-13 audit found), all but 2
  legitimate exceptions verified individually.
- **FrankenPHP worker mode** (finding #15, first half) — still not
  started. See the status table above.
- **Legacy import tool** (`bin/piwigo import:legacy`) — still not
  started.
- **A repo-wide legacy sweep round 2** (`global` cleanup outside
  `src/Piwigo/`, `die()`/`exit()` request-lifecycle architecture,
  `LegacyRenderCapture` void-renderer → return-string conversion,
  DbPatch/VersionUpgrade raw-SQL-to-DBAL bound parameters) — all done,
  including the controller conversion this bullet previously described as
  blocked: `LegacyRenderCapture.php` no longer exists, and
  `PopuphelpController`/`AdminPopuphelpController`/`PictureController`
  (the 3 this bullet named) already return `ResponseInterface` and throw
  `ResponseReadyException` rather than using a void-renderer/`exit()`
  pattern. The one workstream still genuinely open is deeper than that
  controller-by-controller cleanup: the `header()`+`echo`+`exit()`/
  `: never`-return request-lifecycle architecture
  (`RedirectServiceInterface`'s own contract, bootstrap-phase
  short-circuits) is investigated and designed in outline only, not
  started — would mean changing `RedirectServiceInterface`'s contract
  from `: never` to `: ResponseInterface`, deliberately deferred to its
  own planning pass. `RedirectServiceInterface` already throws
  `ResponseReadyException` internally today rather than a raw `exit()`,
  so this redesign is about the wider bootstrap-short-circuit contract,
  not an unconverted caller.
- **`maintenance:repair-db`** — now built, see P12 above.
- **Install/upgrade legacy constants + a real `PWG_CHARSET` bug** — found
  and fixed.
- **`local/config/config.inc.php` bridge — fixed, then superseded.** A
  real bug was found and fixed here (2026-07-21, `338217f48`): nothing in
  `src/Piwigo/` ever actually read a site's local config override file on
  a real request, silently ignoring any non-DB-credential key
  (`order_by_custom`, `data_location`, `guest_id`, etc.) a site
  customized there. Fixed at the time via `LocalConfigOverrides::read()` +
  `ConfigLoader::applyLocalFileOverrides()`. `LocalConfigOverrides.php`
  was deleted outright 3 days later (2026-07-24, `feede75c9`, "typed
  CurrentConfig properties, DbCredentials/DeploymentPolicy split") as
  part of a deliberate, much larger redesign — `ConfigLoader::applyDefaults()`/
  `applyEnvOverrides()` are genuine no-op method bodies today, and the
  only surviving local-file mechanism is `Piwigo\Config\DeploymentPolicy`,
  sourced from a differently-formatted `local/config/config.php` (not
  `.inc.php`) and explicitly scoped to a narrow, deliberately separate
  list of security-boundary settings (PHP error display, auth-bypass
  flags, allowed hosts) — its own docblock states it "never overlaps with
  CurrentConfig (DB)." **Open question**: whether arbitrary site-local
  overrides of ordinary settings (the original bug's own examples) are
  meant to be reachable any other way now (the DB-backed admin UI,
  presumably) or whether this is a genuine unintentional regression — the
  architectural intent isn't documented anywhere. Flagging precisely
  rather than guessing which it is.

### Epoch F — Post-P23 Remediation & Hardening (P24)

**In progress, not done** — the singleton/DI campaign subsection below is
complete; the other P24 sub-tracks (DBAL → ORM migration Part B below)
are not. This formalizes the `(p24)` commit-tag convention (405 commits
as of 2026-08-03) as this doc's own real P24, rather than an unnumbered
status-table row diverging from the commit tags. Not the original plan's
P24 ("Vite + TypeScript conversion," still not started, renumbered
**P29** — see "Real status vs. commit-tag labels" above). What actually
happened under `feat(p24)`/`fix(p24)`/`test(p24)`/`docs(p24)`-tagged
commits is the post-P23 remediation work several phase sections above
already promised — the ORM migration (P14), the `$GLOBALS`/static-bridge
retirement P23 explicitly deferred, and the event-dispatch/`l10n()`/URL
free-function bridges P23 batch 8c/8d also deferred as "too many call
sites" — plus, since 2026-07-27, a coverage-gap-closing sweep, full-suite
stabilization, a mutation-testing hardening sweep, and (not
`(p24)`-tagged, but the same post-P23 remediation effort in substance) a
SQL bound-parameter conversion sweep and the singleton/DI elimination
campaign. The `(p24)`-tagged tracks below were tracked in their own
planning docs (`legacy-coupling-retirement.md`, `gap-closure-p0-p23.md`)
rather than this one at the time; folded in here so there's one current
record.

**Part B — DBAL → ORM migration.** 16 of 31 domain repositories converted
from `AbstractRepository`+`Tables::` (hand-written DBAL) to real Doctrine
`EntityRepository` + attribute-mapped entities at the time this batch of
work landed — the P14 remediation promised above. **The "8 stay on
`AbstractRepository`/DBAL by design" tier described here no longer
exists** — `SectionRepository`, the last remaining `AbstractRepository`
subclass, dropped it for a directly-injected `EntityManagerInterface` in
a separate SQL-modernization initiative's "Item 15H" (`c4125eeb43`,
2026-08-05 — before the table-prefix removal below, not part of it), at
which point `Db/AbstractRepository.php` itself was deleted outright, zero
subclasses left. See P14 above for the current, corrected two-group
breakdown (38 repositories total today, not 31). Real findings along the
way: the shared Doctrine identity map
serves stale data after bulk/raw writes outside the ORM (needs
`HINT_REFRESH` for reads or `clear()` after bulk ops) — first hit twice
while converting the repositories themselves (`TagRepository`'s
`image_tag` bulk insert, one other raw-write site), then found to be a
much larger, repo-wide problem: a dedicated follow-up audit (`cb956266b`)
found **33 real call sites across 13 files** where Controller/`Ws`/Admin
classes bypass their domain repository entirely and write via
`BatchWriter`/raw `executeStatement()` against a table an entity now
maps, each needing the same identity-map-clearing fix. This needed a real
new accessor, not just a fix at each call site —
`Piwigo\Db\EntityManagerFactory::build()` turned out to not be memoized
(always constructs a fresh `EntityManager`, so clearing a locally-built
instance protects nothing); the only genuinely shared identity map lives
on the DI container's `EntityManagerInterface` singleton, reachable only
through `Kernel::container()`, itself Arch-test-restricted to
`Bootstrap/`. `Bootstrap\InfrastructureAccessor::entityManager()` was
added specifically so L4Integration classes could legally reach and
clear that shared instance. Two call sites were investigated and
deliberately left unfixed, not missed: `InstallWizard.php`'s pre-seed
writes (the container's `EntityManagerInterface` would wrap a connection
built from stale pre-seed credentials — clearing it would be both risky
and pointless) and `UserService::checkAndSaveUserInfos()`'s writes (its
only real caller manually builds a fully isolated
`EntityManagerFactory`/repository chain with no container involvement at
all, so there's no shared identity map in that path to protect).

**Track A — `$GLOBALS`/static-bridge retirement.** Batches A1–A8, smallest/
lowest-risk first: `$template` → `Piwigo\Template\CurrentTemplate`, `$lang`
→ `Lang::t()`/new bulk accessors, `$user` → `CurrentUser::get()` (found
not actually functional for real users before this batch — only ever
seeded a guest placeholder, a real production bug fixed here, not just a
retarget), `$conf` → `Config::` (found a root architecture bug:
`Config::$data` was never synced with DB-persisted overrides during the
early bootstrap window — fixed by making `ConfigDb`'s write paths call
`Config::override()`/`Config::delete()` alongside the legacy write, both
since renamed/replaced — see Epoch D's P13 entry above for the current
names), `$page` → `PageState` (9 sub-batches, the one global without a
complete existing target — needed real design work, not just mechanical
retarget), the remaining ~25 smaller globals (`$my_base_url`, `$logger`,
`$mysqli`, `$prefixeTable`, `$filter`, `$pwg_loaded_plugins`, and more),
then collapsing `include/common.inc.php`'s raw seeding into real object
construction (A7) and deleting the `attachGlobals()` bridge shape (A8,
partially — the method *names* were kept as the per-request seeding entry
point on `CurrentUser`/`PageState`/`Lang`, since nothing needs a `$GLOBALS`
bridge anymore but a seeding call still runs once per request; a
documented judgment call, not a miss). Zero `global $x` anywhere in
`src/Piwigo/`, zero `$GLOBALS` reads, the door-lock Arch tests pass.

**Track B — event dispatch retarget.** Done and verified live
(2026-08-02). The free-function elimination landed first —
`add_event_handler()`/`trigger_change()`/`trigger_notify()`
(`PluginConfig/functions.php`) deleted, all 241 real call sites (later
re-counted at 240) retargeted onto `EventDispatcher::get()->addEventHandler()`/
`triggerChange()`/`triggerNotify()` directly. Track B's actual point —
typed event objects (`SomeEvent` classes, `dispatchNotify()`/
`dispatchChange()`) replacing the bare-string-keyed dispatch — then
shipped across 12 domain batches (156 real events today, including the
7-event WS-protocol-lifecycle group originally deferred behind P25).
`EventDispatcher.php` now exposes `addTypedHandler()`/`dispatchChange()`/
`dispatchNotify()` alongside the original string-keyed methods (kept only
for `'trigger'`, its own permanent internal meta-notification channel). A
token-aware door-lock Arch test enforces zero remaining string-keyed call
sites outside that one name. Delivered in 14 commits
(`25d8709bc0`..`6dd1034422`, Foundation + 12 domain batches + wrap-up);
full commit-level history is in `git log`, the plan doc itself was
deleted once the work landed.

**Follow-up recommendation, not yet actioned: replace the hand-rolled
`EventDispatcher` with Symfony's, independent of and before P27.** Found
while grounding P27's design against a reference implementation that
uses Symfony's `EventDispatcher`/PSR-14 (see Epoch I's P27 entry below)
— compared the two directly rather than assuming Symfony's is simply
better. Two of the three differences found (this fork's own priority
order runs ascending/lower-first where Symfony runs descending/
higher-first; no stoppable-event mechanism exists at all) are real gaps,
but checked before recommending anything: zero real
`addTypedHandler`/`addEventHandler` call sites in `src/Piwigo` pass a
non-default priority today, so neither gap currently affects any shipped
behavior — this is a safe window to fix that would close once real
plugins exist and start relying on priority ordering. The lazy-listener-
resolution difference the reference's own container wiring demonstrates
turned out not to be a dispatcher-class limitation at all — it's how
`RequestBootstrap.php` happens to call it (3 of 23 real registrations
there eagerly construct a one-off service regardless of whether the
event ever fires; fixable in place, no library swap needed). The
strongest actual case: this fork's `EventDispatcher` implements no
interface at all, not `Psr\EventDispatcher\EventDispatcherInterface` — a
real inconsistency against this codebase's own pattern of PSR-conforming
elsewhere (PSR-11/PSR-7/PSR-15/PSR-3, all in active use). Not even a new
dependency: `composer.lock` already resolves both
`symfony/event-dispatcher` and `psr/event-dispatcher` transitively,
unused for this purpose. Recommendation: switch onto Symfony's
`EventDispatcher` via the PSR-14 interface as its own standalone item,
not gated on P27 — `dispatchNotify()`/`dispatchChange()` survive as thin
typed wrappers over Symfony's own `dispatch()`.

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

**Repo-wide legacy sweep, round 2** (2026-07-18/19): 6 real `global`
sites outside `src/Piwigo/` fixed, `Ws/PwgImages.php`'s 5 raw `die()`
JSON calls retargeted onto its own typed `PwgError` path (fixed a real
latent bug — the old `die()` always emitted JSON even when the client
requested `format=rest`), `LegacyRenderCapture`'s void-renderer pattern
converted to return-string for 10 of 13 controllers at the time (the
remaining 3 finished separately since — see the P23 gap-list entry
above, now closed), 44 of ~144
DbPatch/VersionUpgrade files given real bound-parameter DML at the time
(2 real double-escaping bugs found and fixed along the way) — superseded
the next day: the entire `DbPatch`/`VersionUpgrade` subsystem (153
files) was deleted outright (`8224f23a3`, "delete the legacy in-place
upgrade chain (Stage 0)") — it contradicted the project's own "clean
fork, no in-place upgrade" design (`docs/REFERENCE.md`'s "Key design
decisions") and had been carried over mechanically during porting before
anyone caught the conflict. The bound-parameter fix above is moot, not a
currently-live improvement to point to. One workstream (C3, the
`die()`/`exit()`/`: never`-return request-lifecycle architecture)
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
resolved by later work above — but 3 items are still genuinely open:

- **Two real bugs found here, both now fixed, first one since superseded
  entirely** (`48f01836b8`, 2026-07-30, the coverage-gap-closing workflow
  below — not cross-referenced from that section's own summary, but
  confirmed by its diff): `Template::p()`
  (`src/Piwigo/Template/Template.php`) called
  `\Smarty_Internal_Debug::display_debug($this->smarty)` when
  `CurrentConfig::debugTemplate()` is enabled — that class doesn't exist
  in the installed Smarty 5.x package, so this would fatal the instant
  anyone enabled `debugTemplate`. Live code used `Smarty\Debug`
  correctly at the time, plus a documented fix for a second, deeper bug
  the original finding didn't catch (`Smarty\Debug::display_debug()`
  unconditionally calls `getSource()`, which the bare `Smarty` engine
  object doesn't implement — worked around with a throwaway `'string:'`
  resource template) — moot now: P31.7 deleted `p()`'s entire debug-console
  branch (and `p()` itself) outright once the Smarty engine was fully
  removed, so there's no `Smarty\Debug` call left to have this bug at
  all. Separately, `MailService::generateResetPasswordMail()` used
  `$message = Lang::t(...) . '</p>';` instead of `.=`, silently discarding
  the opening `<p>` tag set the line above; live code now uses `.=`
  consistently throughout, matching the sibling
  `generateSetPasswordMail()`.
- **Superseded twice over, not just historical**: this finding said
  `psalm.xml` (1143 lines at review time) and `vimeo/psalm` were both
  still a live dependency despite Psalm gating being paused since P5, and
  proposed deleting both outright. `vimeo/psalm` was fully dropped from
  `composer.json` on 2026-08-07 (a real dependency conflict with the
  Pest 5 bump), so the finding's proposal briefly happened on its own —
  but `vimeo/psalm` (pinned to `7.x-dev`) and a rebuilt `psalm.xml` were
  both reinstated on 2026-08-11 (see the P5 entry above for why), so the
  dependency is live again today, just still non-gating. This specific
  finding's original proposal (delete both) no longer applies either way.
- **TODO/FIXME/HACK/XXX markers in `src/Piwigo/`**: 0 today, not 50 — the
  literal-marker convention this finding counted is fully gone (a
  side-effect of some later cleanup pass, not tracked as its own item
  anywhere in this doc). The 2 concrete `@todo`-convention items the
  review separately triaged are still genuinely unresolved though:
  `DerivativeParams::isIdentity()`'s docblock (`@todo : description of
  DerivativeParams::isIdentity`) and `HtmlService`'s 4 `@todo nice display
  if $template loaded` markers (lines 493/514/536/563) — 5 of today's 6
  live `@todo` markers. The 6th, `DerivativeImage::build()`'s own
  undocumented `@todo` (line 242), wasn't named by the original review at
  all.

**Coverage-gap closing, Wave 1** (2026-07-27/28, `test(p24)`/`feat(p24)`).
Closed the remaining zero-coverage classes across the Admin/Search/Mail/
Category/Core/Controller/`Ws` domains (`9f5198bfe`'s own 70-class gap
closed most of this already — Wave 1 is the tail). Real bugs found and
fixed along the way: a metadata-sync bug (Controller-domain coverage), 4
real bugs in the `Ws` domain, `PasswordController` silently discarding
lockout/expiry errors instead of surfacing them.

**Full-suite stabilization** (2026-07-28 to 2026-07-31, `fix(p24)`). Got
the Browser, Contract, and combined Unit+Integration suites fully green —
root-caused every real failure from a full re-run rather than re-running
until it passed. Real bugs found and fixed: a picture-derivative cookie
test assumed an IPv4-only client, a watermark write-access test leaked
permanent debris instead of cleaning up, added_by/multi-filter search
tests were sensitive to ambient config drift, several Browser-suite
relative-path/fixture bugs.

**Mutation-testing gap-closing sweep** (2026-08-01, batches 20–31+,
`test(p24)`/`fix(p24)`/`docs(p24)`). A systematic `pest --mutate` sweep,
closing per-file mutation gaps batch by batch and documenting
confirmed-equivalent mutants where a surviving mutant is provably
behavior-preserving (not a test gap), rather than just suppressing the
finding. Real, previously-undiagnosed bugs found via mutation testing, not
caught by PHPStan/ECS: `SessionRepository::gc()`/
`LoungeMaintenance::needsEmptying()` read the real wall clock instead of
`Env::now()`; `UrlService::getAbsoluteRootUrl()` appended a stray trailing
colon; `Inflector_fr.php` had corrupted `é` regex literals; `Translator`'s
day/month reassembly had an untested gap; `SentryBootstrap::resolveOptions()`
needed extracting to fix a risky-test SDK leak; a deprecated
`trigger_error(E_USER_ERROR)` call was replaced with
`ErrorCollector::recordFatal()`; `UploadService` leaked its process umask.
14 VR baselines, found stale against the fixture's real backing files
during the sweep, were regenerated separately (`01d3ba401e`, 2026-08-02).

**SQL bound-parameter conversion sweep** (2026-08-01, `refactor(sql)`/
`fix(sql)`, 16 commits — *not* `(p24)`-tagged, but the same post-P23
remediation effort in substance, completing "the original SQL-modernization
initiative" that the separate, later Item-15/16 DBAL→DQL campaign's own
planning notes cite as an already-complete prerequisite). Converted the
remaining raw-string SQL splices to bound parameters across the Image,
Category, Tag, Search, Comment, History, Notification, Group, Activity,
Config, Calendar domains and the `Db/` infrastructure layer itself (a new
`SqlCondition` carrier introduced for this). Found and fixed several real,
live SQL injections along the way, not just style: 3 in `ImageRepository`,
1 in the Comment domain, a second in the History/Notification domains, and
a plugin-hook injection in `TagRepository::findIdByWhereFragment()`
(SEC-19-tagged). `CategoryRepository::countByVisible()`'s own splice was
found and fixed in a re-audit the same day, after the main sweep.

**Singleton/DI elimination campaign** (2026-08-02–2026-08-06, `feat(di)`/
`feat(lang)`, 74 commits — complete). A 10-phase campaign (grew a
close-out Phase 12 with 6 further lettered sub-phases, 12A–12F, once
Phase 11 finished and a handful of permanent-exception shims turned out
to be closeable after all) converting every static-singleton/
service-locator anti-pattern (~55 classes across three shapes, plus the
entire `Piwigo\Ws\*` static-dispatch layer as its own Phase 10) to
constructor-injected DI. Real motivation beyond style: SEC-60 ("Worker-mode
request isolation") needs no process-persistent static state, and
FrankenPHP worker mode (`docs/REFERENCE.md`'s "FrankenPHP worker-mode
runtime, Apache as fallback" decision) is a committed-to future direction
incompatible with it as-is. Mechanism: a transitional `@deprecated`-tagged
static shim for callers not yet converted per class, tracked via a
shrinking arch-test allow-list, with a hard "zero shims remain" gate —
met for real: `grep -rn "@deprecated" src/Piwigo` returns nothing (the
4 remaining hits of the bare substring are all past-tense prose describing
already-closed shims, not live tags; confirmed via a stricter
`^\s*\*\s*@deprecated\b` pattern, zero hits). Phases 0–11 converted every
class with real production callers; Phase 12's own sub-phases (12A
interface segregation, 12B accessor-exists sweep, 12C leftover-bug sweep,
12D genuinely-static NOCTOR sweep, 12E `CsrfService.php`'s 148-site churn,
12F's 12 lettered sub-phases 12F-1 through 12F-12) closed the last dozen
shims that had zero production callers left but still carried real test
debt — from 4 test sites (`CurrentLogger::getStatic()`) up to 1,382
(`CurrentConfig::current()`, the campaign's final shim, closed in 12F-12).
`Kernel` itself carries no such tag and was never a shim to begin with —
the one principled DI root every system needs. Full per-class detail
(shim design, real bugs found along the way, test-suite fallout per
class) lives in the campaign's own plan files, not reproduced here.

**Typed-context/VO/DTO campaign — typed template contexts sub-campaign
complete, 2026-08-08, 87 `feat(template)`-tagged commits.** This
sub-campaign has its own internal phase numbering in its own plan file
(unrelated to this doc's P0–P45 backbone numbers — its "Phase 13" is not
this doc's `P13`); referred to here only by what it did, not that
number. Every one of the 96 files calling `Template::assign()` with a
real (non-excluded) key converted to `final readonly class
FooPageContext implements TemplatePageContext` + a single
`$template->assignContext(new FooPageContext(...))` call. Zero real
`Template::assign()` calls with a string/array key remain in
`src/Piwigo` (126 shipped `TemplatePageContext` classes total); 4 sites
carry an explicit `// Not a Phase 13 TemplatePageContext candidate`
comment (that sub-campaign's own internal phase-number label, quoted
verbatim from the real source comment, not this doc's numbering) and are
correctly excluded — the assign *key* itself is caller-chosen or
per-instance-mutable, not a fixed page var (`Calendar/CalendarBase.php`,
`Category/CategoryService.php`, `Html/HtmlService.php`,
`Admin/Tabsheet.php`).

**Quality gap found by a full follow-up audit, not yet fixed**: the
campaign's own convention (one flat `readonly` class, every var an
independently-nullable property) is wrong whenever fields are actually
correlated (always null together, always set together, or represent a
real alternative) — a flat bag lets a caller construct combinations that
can never really happen. A systematic audit of all 32 shipped classes
with 2+ nullable constructor properties (the necessary precondition for
this pattern; the other 94 shipped classes are excluded by construction)
found **18 real violations**, none fixed yet — including one class
(`UpdatesPwgPageContext`) whose own docblock actively claims "every
optional field here is genuinely optional," which is false. Full
per-file fix specs (sub-VO/variant-interface shape for each) live in the
campaign's own plan file, not reproduced here.

**Type correctness + mixed elimination sub-track** (the original plan's
P27, merged into P24 — see "Real status vs. commit-tag labels" above for
why). Real, substantial progress on its own original definition. 89
`(p27)`-tagged commits: continuing the mixed-elimination sweep from the
`p25`-tagged work (Phase 4), replacing ambient `$_POST`/`$_GET`
superglobal reads across dozens of controllers/sub-controllers with typed
Request DTOs (one per action/param cluster — `PluginSectionRequest` and
many more), plus real bugs found and fixed along the way (a SQL injection
via a raw `cat_id` superglobal read in the cat-modify renderer; a stale
`$_POST` dead write in `AlbumSubController`; comment rejection-reason
tracking moved off `$_POST` onto `PageState`; a new SEC-40 arch-test gate
locking in "no raw superglobal reads outside a Request DTO" going
forward). The SEC-40 arch test itself is still live and passing
(`tests/Arch/StructuralTest.php`, no longer literally containing the
string "SEC-40" in its own text — that label now only persists
informally in ~78 Request DTO docblocks and this doc — a naming drift,
not a functional gap). Not complete — no claim of "0 remaining" has been
verified — but real, ongoing, and aligned with its own stated goal.

**Remaining `mixed` gap — one claim here was flatly wrong, the rest
holds up.** Total raw `mixed` token count keeps climbing with new code
(1593 today, up from 1491) — the wrong metric on its own, since a large,
legitimate by-design residual will always exist (DBAL scalar-narrowing
closures, `ValueObject::tryFrom()`, `Db/Type::convert*()`
vendor-dictated signatures, the WS RPC layer's arbitrary protocol
params, PSR-3 `LoggerInterface` context, and more — see the campaign's
own plan file for the full by-design inventory). "Projection" turns out
to be a real, repo-wide directory convention (`{Domain}/Projection/`,
confirmed present in 40+ namespaces, not a vague description) — worth
noting since this section never says so explicitly. The per-module
Projection-wiring gap (a repository/service method still declaring
`array<string, mixed>` where a sibling typed Projection already exists)
is real but uneven: `CategoryRepository`/`CategoryService` are
substantially already fixed (`findCategoriesByIds()`/
`findFullCategoriesByIds()` already return typed Projections — confirmed,
`@return list<CategoryListingRow>`; most of the remaining `mixed` there
is the already-accepted narrowing-closure pattern). **"`ImageRepository`/
`ImageService` and `UserRepository` still have the gap for real — no
Projection-wiring work has reached them yet" is simply false**:
`src/Piwigo/Image/Projection/` has 17 real classes (`ImageIdExt`,
`ImageFormat`, `ImageCategoryLink`, `PathRepresentativeExt`, and 13
more), 17 of them actually referenced from `ImageRepository.php`;
`src/Piwigo/Users/Projection/` has 10 (`ActivationKeyRow`,
`NotificationRecipient`, `UsernameById`, `UserInfo`, and 6 more), 7
referenced from `UserRepository.php`. Both domains have substantial,
already-wired Projection-typed accessors — this claim needs a real
re-audit, not a status flip, since "how much of the *remaining* `mixed`
there is by-design vs. a real gap" (the actual open question, same as
the `Category` entry above) was never answered, just asserted as "not
started." `CommentRepository`/`TagRepository`/`GroupRepository` are
near-resolved (3 occurrences each today, confirmed — matches exactly).
`SearchRepository`'s count is unchanged at 17 (confirmed) — still
genuinely unexplained, still worth a fresh single-file look.
`Admin`/`Core`/`Controller` all have real `Projection/` directories too
(51/2/15 files respectively) — same caveat as Image/Users applies: file
existence isn't the same claim as "audited for this specific gap," so
"still entirely unaudited for this pattern" isn't necessarily wrong for
these three, just worth knowing Projection classes already exist there
before assuming a from-scratch audit. `Ws` is the one domain confirmed
to have zero `Projection/` directory at all — that part of the original
claim holds cleanly.

**Direct continuation of this sub-track's own goal, not yet started as
its own tracked work: superglobal/array-offset access beyond `$_POST`/
`$_GET`/`$_REQUEST`/`$_FILES`.** Three further pockets, same "typed
accessor over raw offset access" discipline SEC-40 already established:

1. **Prerequisite — wire `admin.php` through the shared PSR-7
   pipeline.** `public/index.php` calls `RequestPipeline::handle(...)`;
   `public/admin.php` calls `RequestBootstrap::bootEntryPoint($paths,
   isAdmin: true)` then instantiates `AdminShell` directly — no
   `RequestPipeline`. `AdminShell::run()`'s own docblock states this
   explicitly ("AdminShell has no PSR-7 [pipeline]"). Independently
   corroborated by this doc's own SEC-42 line below (`/admin*` bypasses
   standard middleware, from the CSRF angle). `Http\ControllerInterface`,
   `RedirectServiceInterface`'s `ResponseReadyException` pattern, and the
   string-returning `Template::parse()`/`PageTail::renderToString()`
   siblings this fix needs already exist — this is a real, scoped,
   tractable prerequisite, not a rediscovery of Workstream C3's full
   scope.
2. **`$_SESSION`/`$_SERVER`/`$_COOKIE`** — 168/68/18 real direct-access
   sites (40/30/8 files) outside any designated typed home (the
   `$_SESSION` count has grown, not shrunk, since first scoped).
   `Session\Session` already exists as a designated growth point (still
   genuinely empty — "no in-tree code reads typed session state yet")
   and is already threaded through `Http\Middleware\SessionMiddleware`;
   `Core\CurrentServerRequest` would be a new sibling in the `Current*`
   singleton family; `Auth\CookieService` is already the right home for
   `$_COOKIE`. Real open design question: `SessionService` *itself*
   already has 15 named accessors for a different, non-overlapping slice
   of `$_SESSION` state (the `filter_*`/`device`/`mobile_theme` family)
   — whether new session keys become more named `SessionService`
   accessors (the pattern actually shipped for that half) or populate
   the still-empty `Session` VO (a different, parallel pattern) isn't
   resolved. One slice of this has a ready-made design already:
   `page_infos`/`page_errors`/`message_tags` (the cross-request
   flash-message pair `HtmlService` still reads/writes as raw
   `$_SESSION` keys) map directly onto the reference implementation's
   real `Session\FlashBag` class — write during request N via `add()`,
   consume-once on N+1 via `consume()`, peek without consuming via
   `peek()`. Worth porting directly rather than redesigning.
3. **WS method `$params` arrays** — stale filenames: the `Ws/Pwg*.php`
   naming this item cites doesn't exist anymore — the `Pwg` prefix was
   dropped repo-wide from the WS layer on 2026-08-11 (`89054d2b8d`
   and 3 sibling commits: `PwgTags.php`→`Tags.php`,
   `PwgUsers.php`→`Users.php`, `PwgCore`→`Core`, `PwgError`→`WsErrorResponse`,
   etc.) — real files today are `Ws/{Categories,Comments,Core,Extensions,
   Groups,Images,Permissions,Tags,Users}.php` plus supporting classes
   (`WsDefaultMethods`, `WsErrorResponse`, `WsHelper`, `WsInitializer`,
   `RequestHandler`, `Server`, `NamedArray`, `NamedStruct` — 17 files
   total in `Ws/`, matching this item's original file-count order of
   magnitude). The method count also drifted: `WsDefaultMethods.php` has
   95 `addMethod()` registrations today, not 97. Substance of the finding
   is unaffected — still a raw `array $params` indexed by string key,
   zero typed accessors, each with a full param-type schema at its
   registration site, a ready-made scaffold for one `{Method}Params` DTO
   per method. The
   reference implementation has already built this, for real, for 95 of
   its WS methods: a `{Method}Params implements WsParams` DTO (`fromArray()`
   factory) paired with a `{Method}Handler implements WsAction`
   (`__invoke(array $params, PwgServer $server): mixed`, constructor-
   injected typed dependencies), registered via a typed `MethodDefinition`/
   `ParamDefinition` pair instead of the legacy callback-array shape —
   `Ws/WsAction.php`'s own docblock: "replaces the legacy `*Endpoints`
   god-classes... being replaced one method at a time." This is directly
   adoptable prior art for this item, not just a target shape to
   reinvent from scratch.
4. **Raw DBAL row arrays** consumed by string-keyed offset — real, but
   its own prior sizing document (a now-deleted `docs/plan/` file) can no
   longer be checked against source; needs a fresh count before scoping.
   A second, previously-uncounted sub-population exists beyond the
   repository layer: roughly two-thirds of the files doing this are Page
   Renderers/Controllers/`Ws/*.php` classes (the `Pwg`-prefixed names —
   see item 3 above — were dropped repo-wide 2026-08-11) running raw SQL
   inline, not repositories at all.

Full per-item detail, exact reader-file lists, and the deptrac constraint
on where new `Session`-exposed VOs may legally live all live in the
campaign's own plan file, not reproduced here.

**Table-prefix + `Tables::` removal** (2026-08-09–2026-08-10, `refactor(db)`/
`fix(test)`/`docs`, 62 commits — complete). `PIWIGO_DB_PREFIX` (upstream's
`$prefixeTable`, defaulting to `piwigo_`) removed entirely, not just made
non-configurable: tables get their bare names (`sites`, `users`, `config`,
...) unconditionally. The prefix existed to let multiple installs share
one database — a real constraint on 2000s-era shared cPanel hosting, no
longer the mainstream deployment shape (current hosts allow multiple
databases; this project's own `docker-compose.yml`/`deploy/helm/` already
assume one dedicated database per install). **This supersedes P14's own
claim** that `AbstractRepository`+`Piwigo\Db\Tables::` (DBAL) "had become
the real, working, tested pattern for query-heavy repositories, not a
legacy-only shim" — `Tables::`'s 39 static methods (`Tables::images()`, …)
were deleted outright, not simplified: their opacity to static analysis
(`'SELECT * FROM ' . Tables::images()` is not a literal string) defeated
`staabm/phpstan-dba` (real-schema-aware SQL validation, wired into this
repo's PHPStan) and IDE SQL-injection/autocomplete, both of which only
recognize a literal SQL string. Every call site — 129 in `src/Piwigo`,
~1,540 more in `tests/` — was inlined to its literal table name from a
verified `Tables::method() -> 'bare_name'` mapping, then the class was
deleted along with `TablePrefixListener` (the ORM-metadata-level
prefixer) and every `PIWIGO_DB_PREFIX` reference across install flow,
backup manifests, deploy config, migrations, and CLI tooling.

Real, previously-undiagnosed bugs found along the way, not just
mechanical renames: `UniqueExecLock`/`PwgImages`/`UploadService`'s
`GET_LOCK()` lock-name hashes used `db_prefix` as hash *entropy* (server-
wide MySQL lock names need a per-install disambiguator) — switched to
`db_credentials->database` instead, still unique per install and more
reliably so than a prefix ever was; a leftover `bmDbPrefix()` call site in
`BatchManagerGlobalPageRendererTest.php` that survived an earlier cleanup
pass; a real column-name-case mismatch (`HistoryServiceTest.php`'s `'IP'`
vs. the Postgres migration's real lowercase `ip`); a real int/bool type
mismatch (`CommentServiceTest.php`'s `'validated' => 1` vs. the actual
boolean column). Once every table name became a literal, phpstan-dba
could resolve exact live-schema column types everywhere for the first
time, surfacing ~178 new real findings (all fixed — mostly now-provably-
dead `is_numeric()`/`(int)` defensive wrappers around schema-known-int
reads) plus 4 confirmed tool limitations, each root-caused against the
live schema or existing code rather than guessed, now documented and
narrowly suppressed in `phpstan.neon` (replacing the old blanket
`dba.keyValue` suppression that existed only because `Tables::` made
every table name non-literal): synthetic jsonb-placeholder sample values,
MySQL-dialect SQL validated against the one Postgres connection the tool
has, Postgres `::text` casts misparsed as named bind placeholders, and
dynamically-shaped `tearDown()` snapshot-restore arrays. A full,
non-parallel `composer test:integration` run (apparently the first in a
while) also surfaced 2 pre-existing Kernel-boot test-isolation bugs
(`InstallBootstrapTest`'s idempotency test still expected `Kernel::boot()`'s
old silent-ignore-on-mismatch behavior after that method was deliberately
changed to throw instead; `RequestBootstrapConfigureTest` was missing a
`Kernel::reset()` before booting with a non-default root) and 5 stale
hardcoded fixture-photo hash references left over from an earlier fixture
regen (`WsImagesMaintenanceTest`, `WsTopLevelTest`,
`CategoryRepositoryTest`, `CategoryServiceTest`, `NotificationRepositoryTest`)
— all fixed, unrelated to the prefix removal itself but found only
because this pass finally exercised the full suite end to end.

Verified green: full-repo `vendor/bin/phpstan analyse` (0 errors),
`composer lint:php` (0 errors), `composer test` (5196 passed),
`composer test:integration` (2041 passed, 4 skipped, 0 failed),
`composer test:contract` (595 passed, 0 failed) — all against Postgres
(`.env.test`'s current driver); not independently re-verified against
MySQL this pass. **Since resolved, not deferred anymore**: `ecs.php` had
excluded `tests/` from ECS's scope since this project's very first commit
(`chore(p0)`), originally with a "deferred to P5" comment that a later
cleanup sweep stripped while leaving the exclusion itself in place —
silently making it permanent. Fixed in `e44aeb8f2a` (2026-08-10,
"un-skip tests/ from ECS scope, run the full fix") — the exclusion is
gone from `ecs.php` and the full fixer set ran across all 882 test files
(import ordering, PSR-12/PhpCsFixer `cleanCode`/`common` style,
string-concat collapsing, PHPUnit test-method casing), with no new fixer
exclusions added despite the "several genuinely wrong for this codebase's
established style" concern this section originally raised.

### Epoch G — REST/OpenAPI (P25)

**P25 — REST resource layer + OpenAPI, legacy WS API removed** (`/api/v1`
as the sole API, ETag/304, `Link` pagination, a generated typed TS client;
removes the 94-method legacy RPC WS API this whole codebase's Contract
test suite currently locks in). Not started. The 39 `Ws*Test` Contract
tests, the whole `Ws/` namespace, and every `l10n()`/URL-retarget note
above that explicitly deferred a WS-specific event/function pending this
phase are all still waiting on it.

### Epoch H — Security (P26) — not started

**P26 — Security hardening** (WebAuthn/passkeys, OIDC SSO, nonce-based
CSP, COOP/COEP, CSP reporting). Depends on P24 (the original plan's P27,
"Type correctness + mixed elimination," merged there — see "Real status
vs. commit-tag labels" above). Not started — `rate_limiter` (the one P11
cache pool deliberately left unbuilt, "genuinely P26 scope") is the
clearest concrete marker that this phase hasn't begun.

### Epoch I — Plugins/Layering/Repo-restructure (P27–P28) — P27 in progress, P28 not started

**P27 — Plugin / Theme contracts + bundled extensions + decomposition**
(`PluginInterface`/`ThemeInterface`, JSON-schema manifests, 16
Listener/Subscriber classes, migrating 7 bundled extensions, OpenAPI spec
generation, outbound webhooks). The god-class-decomposition prerequisite
the original plan called for was already done ahead of this phase, and
Track B's own typed-event-object piece shipped early and independent of
the rest: **156** typed event classes plus the
`dispatchChange()`/`dispatchNotify()`/`addTypedHandler()` mechanism,
ahead of and outside P27.

**Implementation status (branch `17.x-rewrite-sql-modernization`):**
P27.0–P27.5 and P27.7–P27.10 are done; P27.6 (porting the 7 bundled
extensions onto the new contract) is in progress in a separate session,
not yet landed on this branch. Real commit tags: `P27.0`
(`8236c685de`, EventDispatcher PSR-14 conformance +
`Piwigo\Listener\*`), `P27.1`+`P27.2` (`83a7c8cbef`, `ExtensionInterface`
contract + manifests + JSON schemas + `ExtensionContext` SDK), `P27.3`
(`35dc37dacf`, `PluginRegistry`/`ThemeRegistry`), `P27.4`
(`e5650148e1`, request-time boot retarget), `P27.5` (`c80581d493` +
`cfbb03094f`, admin lifecycle retarget + page-renderer listing merge),
`P27.7` (`20a7a6ce08`, SEC-49 — `eval_visible` replaced by a typed
`CheckMenuLinkVisibility` event), `P27.8` (`626f6832ed`, dead
`PluginMaintain`/`ThemeMaintain`/`insertPlugin()` removal, plus
`ef65cbb25e`/`3fed9f66cc`, a pre-existing `MailService`
`RootPathOverride` leak + golden-snapshot fix found while doing that
cleanup), `P27.9` (`b1e58e7b4e` prerequisite `AppInfo::VERSION` bump to
`17.0.0`, `7674b185a2` local PEM mirror + manifest-aware compatibility),
`P27.10` (`f91b821a37` + `9497578aa1`, an unplanned but real
sub-campaign — full legacy plugin/theme file support retirement,
prompted mid-session by a live `elegant` theme rendering bug).
`docs/REFERENCE.md`'s architecture section now documents the shipped
contract surface. P27.6 stays open on this branch; do not treat P27 as
fully done until it lands.

*Survey grounding*: every real plugin in the sibling `../piwigo16-plugins`
catalog (~400 extensions) and every real theme in `../piwigo16-themes`
(113 files) were read to ground the design in actual usage rather than
guessing. 162 distinct legacy plugin events found in the wild; every one
of the top ~12 by real frequency already has a shipped 1:1 typed event
class — the events themselves don't need inventing, only a real
plugin/theme registration surface wired onto dispatch machinery that
already exists. `ws_add_methods` (the WS extensibility hook) turns out
to already be just another typed event (`Ws/Event/WsAddMethods.php`,
carrying a live `Piwigo\Ws\Server` — `PwgServer` before the 2026-08-11 WS
rename) — no separate WS plugin API needs designing. The 7 "bundled extensions" the original plan named
(AdminTools, LocalFilesEditor, TakeATour, language_switch, elegant,
modus, smartpocket) are all confirmed to exist in the sibling catalogs,
version-pinned in lockstep with a specific core release.

*Reference-implementation verdict*: a sibling repo
(`../piwigo16-rewrite`, a more advanced fork than the one holding this
plan's own original prose) actually built `PluginInterface`/
`ThemeInterface`/`PluginRegistry`/`ThemeRegistry` — real code, read in
full and traced call-site by call-site, not taken at face value.
Verdict: real, reusable prior art for the JSON manifest shape and
Doctrine-migrations-per-plugin design, but the interface design itself
doesn't hold up — both interfaces were written in one commit, never
touched again, and their own test helper's docblock admits "unused
inside this repository — there are no in-tree plugins yet." Traced every
call site of `getId()`/`getVersion()`/`getName()`/`getParentId()`/
`loadParentCss()`/`getAssetDir()`/`getLocalHeadTemplate()`: zero, across
the whole codebase — every real consumer reads the same data from the
manifest DTO instead, meaning the same facts are captured twice for no
operational reason. Once those dead methods are dropped,
`PluginInterface` and `ThemeInterface` reduce to an identical shape,
overturning that reference's own "keep them separate" design. Its
`boot(ContainerInterface $container)` hands a plugin the entire,
unrestricted app container (confirmed: no scoped binding exists) and
directly contradicts this fork's own established precedent for
plugin-facing classes (`Admin/PluginMaintain.php`'s constructor takes 2
narrow, named collaborators, never the container). PSR-4 autoloading is
parsed into the manifest but never actually wired — confirmed
unimplemented even there.

*Recommended design, adapted rather than copied*: one shared
`PluginConfig\ExtensionInterface` for plugins and themes (not two — see
the dead-method finding above), a narrow `ExtensionContext` SDK object
passed to `boot()` instead of the raw container (its full accessor list
is sized from a frequency survey of what real plugins actually call —
`template()`, `config()`, `currentUser()`, `lang()`, `url()`,
`redirect()`, a namespaced `session()` store, and
`dispatchNotify()`/`dispatchChange()` for a plugin publishing its own
events to sibling plugins), no raw DB access at all (every real
`pwg_query()` use case sampled — core-table reads, permission-filtered
joins, config storage, plugin-owned schema/joins — already maps onto
existing typed repositories, `ConfigService`, and ordinary
Doctrine-entities-per-plugin; one real plugin's own code comment admits
using raw SQL specifically "to bypass permission checks," a concrete
argument against ever exposing it), core-data reads routed through
new purpose-built read-only facades per domain rather than the existing
105-method `CategoryService`/`ImageService` classes directly (most of
those methods take other internal collaborators as parameters and many
are unrestricted mutations — not a safe surface to expose whole), and a
separate, shared "extensions" `EntityManager` (not the core one, and not
one per plugin) so a metadata error in one plugin's entity can't take
down every other active plugin's data access. JSON manifest format kept
from the reference design; `opis/json-schema` and `composer/semver` are
already resolved in `composer.lock` as transitive dependencies, nothing
new to introduce. `PluginMaintain`/`ThemeMaintain` (already shipped,
deliberately loosely-typed for 3rd-party back-compat) read as staging
classes meant to be absorbed into the new interface's lifecycle methods,
not permanent siblings. Full design detail, exact accessor signatures,
and the still-open per-method facade-surface question live in the
campaign's own plan file, not reproduced here. See Track B's own entry
above for a related but explicitly not `P27`-scoped finding
(`EventDispatcher` should move onto Symfony's, independent of and before
this phase).

**P28 — Layer decoupling + repository restructure** (drive the Deptrac
ratchet to zero cross-cutting residue, then the repository restructure —
web-root isolation, `public/` entry point, formerly its own phase). The
web-root-isolation half was pulled forward and is done (see
`docs/REFERENCE.md`'s Deployment section — `public/` is the real document
root today). The 1 commit actually tagged `p32` (`chore(p32): delete doc/`)
is an unrelated, narrow cleanup that borrowed the (old P32) tag, not phase
work — that "P32" is a still-older, pre-consolidation number for this same
phase, unrelated to Epoch J's own numbering (that old P32/CSS-modernization
slot is today's P42 — see "Real status vs. commit-tag labels" above for the
full renumbering history). Layer decoupling itself: not
started (though Deptrac already reports 0 violations today — whether
that's "this phase's ratchet reaching zero" or just "no violations have
accumulated yet" hasn't been separately verified).

### Epoch J — Frontend (P29–P45)

Sequenced after every backend phase above (Epochs G–I) so all frontend work
lands last — see "Real status vs. commit-tag labels" for why these carry
higher numbers than the backend phases that logically precede them, and
for why this epoch expanded from 4 phases (old P29–P32, each mixing
multiple unrelated concerns) to 17. Two tracks: refactor/modernization
(same behavior, different implementation) ordered first, then a
new-feature track for anything that adds a genuinely new capability, then
a closing gate phase.

**Refactor/modernization track (same behavior — land first):**

**P29 — Browserslist decision + legacy back-compat removal.** Not
started. Commit a `browserslist` config (none exists today — no
`.browserslistrc`, nothing in `package.json`), setting Vite's build
target and confirming `tsconfig.json`'s `ES2022` lib target against it.
Immediately followed by removing what that decision obsoletes:
`themes/default/js/pngfix.js` (an IE6 PNG-alpha-transparency shim) plus
its `<script>` reference and the IE conditional comments in
`header.latte`/`local_head.latte`; the IE7-specific fontello icon-font
stylesheets (`gallery-icon-ie7.css`, `gallery-icon-ie7-codes.css`,
`fontello-ie7.css`, `fontello-ie7-codes.css`); and scattered
`-ms-filter`/`zoom:1`/`\9`-hack CSS rules found across 11 files
(`iconset.css`, `admin/default/theme.css`, several vendored plugin
stylesheets). One phase, not two — the removal is the decision's direct,
mechanical consequence. Proven via `composer test:visual`.

**P30 — Asset-pipeline foundation.** Not started. Retires
`src/Piwigo/Template/`'s `ScriptLoader`/`CssLoader`/`FileCombiner` (the
legacy PHP asset combiner `vite.config.ts`'s own comment flags as still
driving everything except the `vitals` entry) for real `ViteManifest`
resolution reading `dist/manifest.json`. No template content moves — this
only builds the delivery mechanism P34/P35 need.

**P31 — Smarty → Latte template migration.** Done (80 `p31.x` commits).
Scope narrowed from the original plan: the old "+ asset pipeline"
clause is split out to P29/P30 above and P43 below — matches what
actually landed, since every `p31.x` commit is a `.tpl`→`.latte`
conversion or Smarty-engine cleanup, nothing manifest/combiner/
image-format related. All 139 real templates converted (P31.1–P31.6),
then the Smarty engine itself fully retired (P31.7): `Template.php` no
longer has a Smarty dependency at all (dropped `$smarty`/`$files`/
`$external_filters`, `setFilename()`/`setFilenames()`/
`assignVarFromHandle()`, every Smarty-only registration/helper), the
`smarty/smarty` Composer dependency and its 3 patches are gone, and
`tests/Arch/StructuralTest.php`'s Smarty-reach-around guard was
retired (PHP's own private-method visibility on `Template::assign()`/
`append()` enforces the same invariant now, no regex-based arch test
needed). The deferred asset-pipeline items (`ViteManifest`,
`<picture>`/AVIF/WebP responsive markup, ThumbHash blur placeholders)
were never part of this narrower scope — they pick up whenever
P29/P30 (Vite adoption) actually start, tracked under P43.

**P32 — Latte lint/format.** Format-half landed; lint-half not started. No Latte-native lint/format
tool is installed in this repo today, but `16.x-rewrite` (136 real
`.latte` templates, unlike `16.x-v2` which has none at all) already built
real prior art — REPLAY this, don't design from scratch:

Three of the four pieces below are confirmed *proven*, not just
present — checked that the reference's own CI actually runs them
(`.github/workflows/*.yml`), not merely that the code exists (a
reference repo can contain real but unexercised code — verify usage,
don't assume presence means proven):

- **Linting**: `composer lint:latte` → `tools/latte-lint.php`, a thin
  wrapper around Latte's own bundled `Latte\Tools\Linter` (from
  `latte/latte` itself, already this repo's real dependency — not a
  third-party tool to newly adopt), registering a custom
  `Piwigo\Template\Latte\PiwigoExtension` (so Piwigo-specific filters
  like `|translate` don't false-positive as "unknown filter") plus
  Latte's bundled `LinterExtension`, `strict: true`. Runs as a subprocess
  specifically because `Linter` is `final` and writes warnings only to
  stderr via a private error handler the wrapper can't hook into
  directly from the same process. **Confirmed run in CI** — a directly
  portable pattern, no excluded-scope entanglement.
- **Precompilation as a build gate**: `composer precompile:templates` →
  `tools/precompile_templates.php` — calls `LatteEngine::warmupCache()`
  on every bundled `.latte` file, catching a real syntax error as a
  build failure while also warming the production compile cache.
  **Confirmed run in CI** — same, directly portable.
- **Static analysis inside templates**: `efabrica/phpstan-latte` (real
  composer dependency there) plus a locally vendored/patched copy at
  `tools/phpstan-latte`, wired into `phpstan.neon` (`includes:
  vendor/efabrica/phpstan-latte/rules.neon`, a real
  `engineBootstrap`) letting PHPStan type-check variables used inside
  `.latte` templates against their real PHP types. **Confirmed run in
  CI** (`vendor/bin/phpstan analyse` runs there, phpstan-latte included)
  — **outcome: NOT ported.** A deep upstream review (dead Nette-only
  machinery, maintainer-confirmed performance overhead, three real
  crashes in its hand-rolled analysis loop) led to a Piwigo-native
  replacement instead: `tools/phpstan/Latte/` + `bin/piwigo
  phpstan-latte:compile`, chained inside `composer analyse:phpstan` —
  see the P32 status row.
- **CSP-aligned inline-script guard**: `composer lint:no-inline-scripts`
  → `tools/check-no-executable-inline-scripts.php` — scans `.latte`
  (and `.php`) for `<script>` tags missing `type=` or carrying one
  outside a CSP3-safe allow-list (`application/json`, `application/
  ld+json`, `importmap`, `speculationrules`); skips `.php` `<script
  src=...>` externals and mail templates. **Not confirmed run in CI**
  despite being a real, defined `composer` script with working code
  behind it — grepped the reference's own workflow files, no invocation
  found, only `lint:latte` and `precompile:templates` are. This tool
  also exists in the reference *because of* its own CSP `script-src
  'self'` hardening (a different, bundled scope this fork's plan keeps
  separate as P26) — per the standing "reference repo is a pattern
  source, not a scope target" lesson, borrow this pattern when P26's own
  CSP work is scoped, don't pull it into P32 just because it's adjacent
  and Latte-shaped.

**No dedicated formatter exists even in `16.x-rewrite`** — this phase's
"format" half has no reference prior art to replay, unlike its "lint"
half; scope it as genuinely new work (or confirm none is needed if
Prettier's plugin ecosystem or another tool covers Latte by the time
this phase starts). Depends on P31 only; sequenced immediately after it
(not at the track's tail) since it's independent of every JS/CSS/TS phase
below.

**Format-half landed**: `tools/latte-prettier/` — a real Prettier plugin
(hand-written recursive-descent parser producing a typed AST, printed
through Prettier's own `Doc` builders, the same architecture
`prettier-plugin-laravel-blade` uses for Blade, not a mask-and-delegate
tool). `bun run format:latte` / `format:latte:fix` cover both `themes/`
and `template-extension/`; `.prettierignore`'s blanket directory
excludes for both were replaced with precise per-extension excludes
(CSS/JSON/MD still excluded per P33's staging, `.latte` now reachable —
a bare `!themes/**/*.latte`-style negation doesn't work, gitignore
semantics can't re-include a path whose parent directory is already
fully excluded). Full real-tree coverage as of this writing: **135/135**
`.latte` files parse, format, are idempotent, and are
AST-semantically-equivalent to their source — reached incrementally
from the original 4-file corpus by root-causing each real gap against
actual source (elseif/else/spaceless structural mismatches from HTML
left deliberately unclosed or scopes that overlap rather than nest; a
real silent-corruption bug in unquoted attribute values, caught only by
the AST-equivalence check; `??`/array literals/casts/`\CONST`/ternary in
the expression grammar; `{for}`/`{define}`/`{breakIf}`/`{contentType}`/
bare-call-output tags; a real non-idempotency bug in trailing-newline
handling for documents that end via a genuinely-unclosed element),
never a guessed fix; see `tools/latte-prettier/README.md`.
`tests/Unit/Latte/latte-prettier-plugin.test.ts` asserts full-tree
coverage as a hard requirement (not a floor) plus strict correctness
against the 4 originally-verified files. Deliberately **still not**
wired into `lefthook` pre-commit or CI — full coverage of *today's* tree
doesn't cover templates P31 hasn't converted yet, and wiring in
enforcement is a separate decision for whoever wants it next, not
assumed here.

**Format-half then manually reviewed, every file, line by line** — ran
`format:latte:fix` across the whole tree and read all 126 changed files'
diffs directly (not sampled: the automated checks above compare
old-parse vs. new-parse with the *same* parser, so a systematic parser
bug is invisible to them by construction). Found 3 more real bugs this
way, all fixed and covered by the existing test/deep-review scripts
after; full detail in `tools/latte-prettier/README.md`:
- **The void-element fix above was too narrow.** `install.latte` had
  `<td>...</options>` (a typo — `<options>` is never opened anywhere in
  the tree), which isn't void, so it re-triggered the same
  cascading-unclosed-ancestor flattening (td→tr→table→fieldset→form)
  the void-element fix was supposed to have closed off. Real browsers
  discard *any* closing tag whose name isn't in the current stack of
  open elements, not just void ones — fixed by tracking a real
  open-element-name stack and checking it before ever propagating a
  mismatched closer upward (this subsumes the void-element case
  entirely). Same bug, previously undetected, also found in
  `batch_manager_unit.latte`, `photos_add_direct.latte`,
  `picture_modify.latte`, `search_filters.inc.latte` — all four share an
  `<a>`/`<div>` opened independently per `{if}`/`{else}` branch with one
  closing tag after `{/if}`. First draft of this fix got "what to do
  with a stray tag" backwards and started silently deleting
  `footer.latte`'s real cross-file closing tags (the ones
  `header.latte` opens) — caught by diffing the whole tree before/after
  the fix rather than trusting the one file it was written against.
- **Pure-CSS files** (`mail-css-clear.latte`, `mail-css-dark.latte`,
  `global-mail-css.latte` — raw CSS embedded into a sibling template's
  `<style>` block via `{$GLOBAL_MAIL_CSS|noescape}`, no HTML or Latte
  tags anywhere) went through the normal prose-reflow path and got
  their 100+ hand-formatted lines collapsed onto one unreadable line.
  Harmless to rendering (CSS is whitespace-insensitive between tokens)
  but destroyed readability/diffability — fixed by printing a `Document`
  with no HTML/Latte content at all byte-verbatim instead.
- **`{contentType text}` templates** (`mail/text/plain/*.latte` — output
  *is* the literal email body, not markup) lost meaningful leading
  whitespace on lines nested inside `{if}`/`{foreach}` bodies, because
  indentation there was being re-derived from nesting depth like
  everywhere else. Verified against the real Latte compiler (installed
  in this repo, used directly rather than assumed) that this is a
  genuine rendering difference: a line that's purely whitespace plus a
  control tag is auto-trimmed by Latte regardless of indentation, but a
  line carrying an output tag or literal text renders its leading
  whitespace verbatim. Fixed by printing gaps byte-verbatim throughout a
  `{contentType text}` document's body instead of recomputing them from
  indent depth.

Also verified, concretely rather than by inspection alone, that every
genuinely cross-file-split template still composes correctly: the mail
templates (`header.latte` + one of 3 possible content templates +
`footer.latte`, for both `text/html` and `text/plain`, joined by raw
PHP string concatenation in `MailService.php`) were rendered through the
real Latte engine for all 3 content-template combinations and the
concatenated output validated with a real HTML parser — content lands
correctly inside `<body>`, zero structural errors.
`check_integrity.latte`'s own unclosed `<dl>` (concatenated after
`intro.latte`'s dashboard via `Template::concat()`) was confirmed
pre-existing in the original source, not a formatter regression and not
actually a cross-file structural dependency — just a standalone
forgotten `</dl>` real browsers tolerate. A cross-check of the review
log against the full changed-file list also caught a real gap — 10
files (4 in `template-extension/`, 6 in `themes/admin/`) had been
carried over as "already reviewed" from an earlier context without
actually having been read; closed before calling the review done.

End state: of the 135-file tree, 122 files remain modified pending
commit (the other 4 — the three CSS files plus the one `{contentType
text}` fix — came out byte-identical to their already well-formatted
source once the bugs above were fixed, so they no longer show as
changed). The 126 reformatted `.latte` files are deliberately **left
uncommitted** — this pass was format-and-review only, not "commit the
reformat"; only the `tools/latte-prettier/` source fixes themselves are
committed, one per bug found.

**P33 — Latte idiomatic modernization.** Not started. A content pass over
templates once formatting is enforced — idiomatic Latte constructs,
cleaning up any Smarty-era patterns that survived P31's mechanical
conversion. Same rendered output. Depends on P32.

**P34 — Inline JS extraction.** Not started. Every `<script>` block
embedded in a template moves to a plain `.js` file loaded through P30's
manifest. Same behavior, proven via `composer test:visual`. No
TypeScript, no modularization, no jQuery changes — separate phases below.
Depends on P30 and P33 (extracting from the final, idiomatic `.latte`
files).

**P35 — Inline CSS extraction.** Not started. Every `<style>` block and
`style="..."` attribute moves to a real `.css` file. Independent of
P34 — different files, different lint tool (Stylelint vs. ESLint),
parallelizable with it. Same dependencies as P34 (P30 + P33).

**P36 — JS → TS mechanical conversion.** Not started as originally scoped
— the 52 commits tagged `p25` are the PHP-side mixed-elimination work
covered under Epoch F's P24 above, unrelated to this phase's actual
frontend scope. `.js` → `.ts` renames, minimal types to satisfy the
existing strict `tsconfig.json`, real Vite entries replacing the `noop`
placeholder (the "68 real entries" `vite.config.ts` already earmarks).
Same code, same behavior — no `any`-reduction, no modularization, no
jQuery removal yet. Vendored third-party files (`jquery.js`/`.min.js`/
`.cookie.js`, `themes/default/js/ui/**`, `themes/default/js/plugins/**`,
`jquery.geoip.js`) stay out of scope here — already ESLint-ignore-listed,
decided in P39. Depends on P34.

**P37 — Typed page-data bridge + `any` reduction.** Not started.
`getPageData<T>()` replaces inline PHP→JS data dumps; TypeScript `any`
driven to zero across P36's output. Split from P36 because this is real
type-design work, not a mechanical rename. Depends on P36.

**P38 — Refactor TS into modules.** Not started. Breaks up monolithic
per-page scripts into proper ES modules (shared utils, per-feature entry
points) — one Vite entry per real page bundle. Depends on P37.

**P39 — Remove jQuery.** Not started. Explicit per-surface decision, not
a blanket removal: first-party call sites (native DOM/fetch APIs), the
vendored bundle itself (delete once nothing references it),
`themes/default/js/ui/**` and `themes/default/js/plugins/**` (selectize,
jqtree, etc. — replace or keep vendored per-widget), `jquery.geoip.js`,
and the installer's own separate `jquery.packed.js` load in
`install.inc.tpl` — a third, easy-to-miss surface with thinner test
coverage (`composer test:install` only). `pngfix.js` is *not* in scope
here — it's an IE-back-compat shim, not a jQuery plugin, already removed
in P29. Depends on P38.

**P40 — Lit component catalog** (conditional on P39's findings, still
refactor-track — parity only). Only for widgets P39 finds no reasonable
vanilla replacement for (tag autocomplete, tree picker are the likely
candidates) — same behavior as the jQuery widget it replaces, no new
capability. Skipped entirely if P39 turns up nothing that needs it.

**P41 — TS modernization.** Not started. Idiomatic pass over the
now-modular, jQuery-free, fully-typed codebase from P36–P40. Same
behavior.

**P42 — CSS architecture modernization.** Not started. `@container`
queries, `@layer` cascade, Tailwind adoption evaluated (`@source`
scanning needs P31's Latte templates, already satisfied) — same visual
output as today, proven via VR baselines. Depends on P35, not on the JS
track (P36–P41), so parallelizable with all of it. Includes confirming
nothing in the vendored plugin RTL rules (`selectize.dark.css`/
`jqtree.css` — the only RTL handling found anywhere in this repo)
regresses if P39 touched those files. (Dark mode is split out to P44 —
a new capability, not a same-behavior refactor.)

**New-feature track (genuinely new capabilities — land last):**

**P43 — Picture pipeline.** Not started. `<picture>` AVIF/WebP variants +
ThumbHash blur-up placeholders — new image formats and a new
loading-placeholder UX not present today. Independent of the refactor
track above; kept last per the modernize-first ordering rather than for a
real technical dependency. Soft-depends on P30 if generated variants
should be served through the Vite manifest.

**P44 — Dark mode.** Not started. A new user-facing capability (theme
toggle, `prefers-color-scheme` support). Depends on P42 — needs the
modernized CSS architecture (cascade layers/custom properties) to add a
theme dimension onto cleanly.

**Closing phase:**

**P45 — Real quality gates.** Not started. `lighthouserc.json` has no
`assert` block today (collect-only, per `.github/workflows/ci.yml`'s own
comment); `.size-limit.json` has one 1 KB placeholder budget. Wires real
Lighthouse perf/a11y/best-practices thresholds and real per-entry
`size-limit` budgets, and decides whether the risk register's claimed
"a11y gate" (currently just the VR baseline + manual per-template
review — no automated tooling found) becomes a real automated check.
Needs P29–P44's real bundles/templates/features to measure against.

## Greenfield tracks (T3, cuttable — outside the P0–P45 backbone)

T3·WEB (PWA, View Transitions, Speculation Rules, JSON-LD, SRI, resource
hints — depends on P30 (asset pipeline), P31 (Latte templates), P42 (CSS
architecture)), T3·AI (depends on P19/P25), and T3·RIDERS
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

## Execution approach for remaining phases (P24–P45)

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
   own doc (`docs/FRONTEND.md` at P29, `docs/API.md` at P25,
   `docs/SECURITY.md` at P26, `docs/PLUGINS.md`/`docs/EVENTS.md`/JSON
   schemas at P27, `docs/STRUCTURE.md` at P28, `docs/AI.md` for T3·AI) —
   that plan predates this consolidation and is superseded by its whole
   premise (18 drifting files reduced to 2). A future P24+ contributor
   should add a Development/Deployment subsection or a new phase entry,
   not reintroduce the fragmentation this consolidation just closed.

**Risk register** (highest blast-radius remaining phases): P31 (Smarty →
Latte across 139 templates) is done — the visual-regression risk it
carried was mitigated by the committed VR baselines + per-template
review the whole way through, both a real, already-daily mechanism
(and both stay in place for whatever templates P32+ still touch).
**The "a11y gate" this line used to claim alongside them isn't one** —
no automated accessibility tooling (`axe-core`, `pa11y`, or a
Lighthouse `assert` block scoped to the a11y category) exists anywhere in
this repo; `lighthouserc.json` is collect-only (see P45 below). What
actually ran during P31 was the VR baseline plus manual per-template
review, nothing more. P27 (plugin/theme contracts, god-class decomposition)
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

## MySQL infrastructure notes

`utf8mb4_0900_ai_ci` was the *originally planned* MySQL collation (more
accurate multilingual sort than `utf8mb4_unicode_ci`) — but it doesn't
appear anywhere in the live repo: all 39 `CREATE TABLE` statements in
`install/piwigo_structure-mysql.sql` explicitly declare
`COLLATE=utf8mb4_unicode_ci` instead. No decision record explains the
reversal; relevant for any phase still touching schema, since a new
table following the *original* plan's `utf8mb4_0900_ai_ci` instruction
would be inconsistent with all 39 existing ones. Whether this was a
deliberate, undocumented simplification (fewer moving parts across the
MariaDB/PostgreSQL provider matrix, since MariaDB has no `_0900_`
collation either way) or an oversight isn't established — flagging
precisely rather than guessing, same as the `LocalConfigOverrides`
finding above. MariaDB's `utf8mb4_uca1400_ai_ci` equivalent was,
similarly, never actually adopted for the same reason. MySQL 8.0+ has no
`.frm`/query-cache — the `symfony/cache` layer is the intentional
replacement, not a gap. `SET PERSIST` is available for the future admin
maintenance page's MySQL tuning. Replication terminology is
`SOURCE`/`REPLICA`, not `MASTER`/`SLAVE`, in any future documentation or
admin page that touches it.

## Migration path

Clean fork, no in-place upgrade from an existing Piwigo install
(`docs/REFERENCE.md`'s "Key design decisions"). Doctrine Migrations
(`bin/piwigo migrations:migrate`) are the real, live mechanism today, for
both a fresh install and a version-to-version upgrade of an existing v17
install — this reinstates the original design after a detour (schema
briefly came from a single, hand-maintained `install/piwigo_structure-mysql.sql`
with no Doctrine Migrations at all, reversed 2026-07-24 before any real
install existed, then reinstated for real during the pgsql-support pass).
`install/piwigo_structure-{mysql,pgsql}.sql` are now generated,
human-reviewable snapshots regenerated *from* migrations by
`bin/piwigo schema:dump`, not the install-time source of truth (see
`docs/REFERENCE.md`'s Architecture and "Key design decisions" sections).
Adopting from an existing *pre-v17* Piwigo install is meant to go through
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
composer analyse:phpstan                    # level 10, 0 errors — blocking today; chains bin/piwigo phpstan-latte:compile ahead of phpstan (bare `vendor/bin/phpstan analyse` skips template checking)
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

**Not in this list**: `vendor/bin/psalm` — reinstalled 2026-08-11 (see the
P5 entry in Epoch B above), so it's a real, working dependency again
today, just deliberately non-gating (no dedicated CI job, no `composer`
script wired to it) — "gating is moot" still holds even though "doesn't
exist" no longer does, see `docs/REFERENCE.md`'s Psalm entry for the same
correction. `composer lint:latte`/`precompile:templates` (P31 is now
done — every template renders through Latte, no Smarty left anywhere
— but the lint/precompile tooling itself is still P32 scope, not yet
built), `tools/plan-lint` (deleted along with `docs/plan/manifest.yaml`
in this consolidation).

**Real consequence of that last deletion**: the original plan's SEC-NN
traceability design (every `SEC-NN` reachable from threat model → phase
checklist → manifest → `verified_by` test, enforced automatically by
`plan-lint`) has lost its automated cross-check. The threat model and
phase mapping are reproduced below (carried forward from the original
`PLAN-REPLAY.md`'s own security master checklist section), but nothing
currently re-verifies automatically that every `SEC-NN` still appears in
all the places it should. If SEC traceability enforcement matters going
forward, it needs a new mechanism that doesn't depend on the deleted
YAML file.

## Security master checklist

65 items, `SEC-01`–`SEC-65`, each globally unique. Status column is
derived from this file's own phase-status table above, not independently
re-verified item by item — treat "phase done ⇒ item done" as a
reasonable default, not a guarantee, except where marked `(confirmed)`,
which means directly verified in code.

| ID | Phase | Item | Status |
| --- | --- | --- | --- |
| SEC-01 | P4 | `.htaccess`/Caddy deny rules for sensitive directories | Done (confirmed) |
| SEC-02 | P0 | CLI guards on all `tools/*.php` scripts | Partial: most real entry-point scripts have a `PHP_SAPI !== 'cli'` guard, but `tools/i18n/verify-parity.php` and `tools/i18n/convert-all.php` — both real, directly-invokable CLI tools per their own "Usage:" docblocks — have none. Not currently reachable (`tools/` isn't among `public/`'s 3 real symlinks), but a literal, live gap against this item's stated scope regardless of reachability |
| SEC-03 | P2 | No fixture SQL with secrets in web root | Done |
| SEC-04 | P4 | Ship `robots.txt` | Done |
| SEC-05 | P4 | Brotli compression | Done |
| SEC-06 | P4 | `Cache-Control: immutable` for hashed assets | Done |
| SEC-07 | P5 | Replace `mt_rand()` with `random_int()` | Done for security-sensitive uses — 7 `mt_rand()` calls remain project-wide, but each is non-security-sensitive (temp-filename uniqueness, cache-busting query params, probabilistic log-sampling gates, or picking a *length* parameter for a value that itself comes from `random_bytes()`/`generateKey()`, e.g. `Ws\Users.php`'s auto-generated password — `PwgUsers.php` before the 2026-08-11 WS rename, confirmed still `mt_rand(15, 20)` at line 444 today). None are the actual entropy source for a security-relevant token |
| SEC-08 | P5/P17–P23 | Replace loose `==` with `===` (manual, per-domain) | Done |
| SEC-09 | P5 | `#[\SensitiveParameter]` on secret-carrying params | Partial: only `Users\UserService.php` and `Auth\PasswordService.php` use the attribute anywhere in `src/Piwigo/`. Real gaps found: `Auth\AuthService::tryLogUser()`/`::pwgLogin()` — the actual login entry points — take `?string $password` unguarded; `Db\DbCredentials`'s constructor holds the real DB password unguarded; 4 Request DTOs (`IdentificationSubmitRequest`/`RegisterSubmitRequest`/`PasswordRequest`/`UserBootstrapRequest`) hold raw passwords in unguarded constructor-promoted properties. Any exception thrown during login or DB-connection setup would currently leak the plaintext password into that exception's stack-trace args (visible to logs/Sentry) |
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
| SEC-25 | P18 | Session fixation: regenerate on privilege escalation | Done (P26 was meant to verify further; P26 not started, so only the P18 half is confirmed) |
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
| SEC-36 | P25 | REST error responses never leak internals | Not started (P25) |
| SEC-37 | P25 | No object dumps in the REST error path | Not started (P25) |
| SEC-38 | P25 | REST route authorization middleware | Not started (P25) |
| SEC-39 | P25 | Validate `Content-Type: application/json` on REST bodies | Not started (P25) |
| SEC-40 | P24 | Request DTOs as a hard input-validation gate | Real progress — see P24 above (not "0 remaining" verified) |
| SEC-41 | P26 | Password hashing → Argon2id | Not started (P26) |
| SEC-42 | P26 | CSRF middleware: remove `/admin*` exemption | Not started (P26) |
| SEC-43 | P26 | No `Access-Control-Allow-Origin: *` on the OpenAPI spec endpoint | Not started (P26, depends on P25) |
| SEC-44 | P26 | API rate limiting + rate-limit headers | Not started (P26; the `rate_limiter` cache pool is deliberately unbuilt pending this) |
| SEC-45 | P26 | CSP violation reporting | Not started (P26) |
| SEC-46 | P26 | Cross-Origin Isolation (COOP/COEP) | Not started (P26) |
| SEC-47 | P26 | `Vary: Cookie` on permission-dependent responses | Not started (P26) |
| SEC-48 | P31 | Default `allow_html_descriptions` to `false` | Not started (P31) |
| SEC-49 | P27 | Remove `eval_visible` (plugin-facing half of SEC-15) | Done |
| SEC-50 | P3 | CycloneDX SBOM generated as a CI artifact | Done (confirmed — `sbom` job in current CI list) |
| SEC-51 | P3 | Pin GitHub Actions to commit SHAs | Done |
| SEC-52 | P3 | OSV-Scanner over lockfiles in CI | Done |
| SEC-53 | P3 | SLSA build provenance + attestations | Done |
| SEC-54 | P4 | Sign container images + release artifacts (cosign/sigstore) | Done (confirmed — see `docs/REFERENCE.md`'s Deployment section) |
| SEC-55 | P26 | OIDC SSO: PKCE + state/nonce + ID-token validation | Not started (P26) |
| SEC-56 | P18 | GDPR data-subject endpoints behind re-auth + rate limit | Not started — `PrivacyService` doesn't exist (its REST exposure is P25/P31 scope, but the backend itself was P18 scope and isn't built either) |
| SEC-57 | P15 | Append-only / tamper-evident audit log | Done — `Piwigo\Audit\*` is real (see Epoch D) |
| SEC-58 | P11 | Feature-flag changes authz-gated + audited | Partial — `FeatureFlag` is read-only by design, no mutation path exists yet to protect (a deliberate, documented non-gap, not an oversight) |
| SEC-59 | T3·AI | MCP server: scoped read-only tokens | Not started (T3·AI, cuttable) |
| SEC-60 | P7 | Worker-mode request isolation | Not started — see `docs/REFERENCE.md`'s "not built yet"; this is the FrankenPHP worker mode gap |
| SEC-61 | P11 | Mercure topic authorization | Not started (T3 rider, hosted on P11) |
| SEC-62 | P26 | Trusted Types | Not started (P26) |
| SEC-63 | P26 | Fetch Metadata isolation | Not started (P26) |
| SEC-64 | P3 | OpenSSF Scorecard | Done |
| SEC-65 | P25 | API `Idempotency-Key` replay store | Not started (P25) |

**Threat model** (attacker goal → mitigating `SEC-NN` items) is a
different cross-section of the same 65 items — kept brief since the table
above already carries per-item status: every threat maps to at least one
`SEC-NN` above, so its own status is derivable from theirs. Two items
(SEC-05 Brotli, SEC-06 `Cache-Control: immutable`) are performance items,
not mitigations, and intentionally don't appear in any threat row.
Mitigations that aren't numbered items at all (nonce-based CSP, the PSR-18
SSRF guard, DB-level account locking, dual passwords) belong to
not-yet-started phases (P26) the same as their numbered siblings.

**Secrets & key management**: DB credentials and the application
`secret_key` live in `.env`, never web-served. A single `secret_key`
derives the HMACs for CSRF tokens (SEC-11/12), the auto-login cookie
(SEC-27), and ephemeral keys (SEC-28) — rotating it invalidates all three
at once, forcing re-login repo-wide; see `docs/REFERENCE.md`'s Secret
rotation section. DB password rotation via MySQL dual passwords (`ALTER
USER ... RETAIN CURRENT PASSWORD`) is P26 scope, not yet built — today's
rotation path is the simpler "update env, roll deployment" sequence
`docs/REFERENCE.md` documents.
