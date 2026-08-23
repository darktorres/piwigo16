# Plan and build history

Blueprint for `rewrite`: a from-scratch redo of the Piwigo modernization,
starting over from `origin/16.x` again rather than building on any prior
attempt's code. This file and `docs/REFERENCE.md` are the only two
planning documents, deliberately — an earlier structure of 18 per-phase
files drifted against each other and was consolidated into these two.

This is not the first attempt. `16.x-rewrite` and four `17.x-rewrite*`
lines were built before this one, each renamed `deprecated_*` and kept as
read-only reference material — not a merge source, not history this file
narrates. Every phase below is written as an instruction informed by what
those attempts found, not a record of what they did: where a prior
attempt hit a real bug, made an architectural call worth keeping, or
learned a gotcha the hard way, that becomes the reasoning behind this
phase's instructions, so it isn't rediscovered blind a second time.

`rewrite` replays `16.x-rewrite`'s modernization as 57 sequential backbone
phases (P0–P56, in 11 epochs A–K), rebuilt from `origin/16.x` rather than
upgraded in place. Every backend phase is sequenced before every frontend
phase. The work is dual-purpose: a *replay* of work that has a reference
implementation on `16.x-rewrite`, plus *greenfield* capabilities with no
counterpart there.

## How to read this file

- **Present tense is a claim about live code**, checked when the line was
  last edited. Once work starts, keep claims machine-checkable where it's
  cheap to do so — a `doc-drift-check` marker (invisible when rendered)
  that `composer check:doc-drift` re-runs on every CI build.
- **Commit counts start at 0** and are filled in as each phase actually
  lands on this branch — they are not carried forward from any prior
  attempt.
- **"Open question"** marks something genuinely unresolved — the intent
  was never recorded, or two sources of truth disagree. It is not a
  to-do; it is a flag that guessing here would be wrong.
- **Detail lives closest to the code.** Where a campaign needs its own
  plan file, keep per-file specs there and record only the outcome here.

## Numbering and commit tags

Historical, from the prior `17.x-rewrite` attempt, kept for reference
only: that attempt's commit-message phase tags drifted from its own
plan-file numbering across three successive renumberings (its `docs/`
git history has the detail, and `deprecated_*`'s own commit log is the
authoritative record if it's ever needed). None of that drift applies
here — `rewrite` starts a clean commit history under this file's phase
numbers from the first commit, so tag-to-phase mapping should just stay
correct as long as commit tags are kept in sync with this file's numbers
as phases are added, split, or renumbered going forward.

## Status

| Phase | Scope | Status | Commits |
| --- | --- | --- | --- |
| P0 | PHP tooling + baselines | Not started | 0 |
| P1 | Frontend tooling + baselines | Not started | 0 |
| P2 | Test harness | Not started — build combined coverage measurement from day one, see below | 0 |
| P3 | CI pipeline | Not started | 0 |
| P4 | Containerization + runtime image | Not started | 0 |
| P5 | Composer + Rector + PHPStan | Not started — skip Psalm entirely this time, see below | 0 |
| P6 | PSR-4 namespace migration | Not started | 0 |
| P7 | Kernel + boot skeleton | Not started — builds worker-mode readiness only; true worker-mode execution lands in P23, see below | 0 |
| P8 | DI container | Not started — commit to DI everywhere from this phase, don't let shims accumulate; also builds the core PSR-14 event dispatcher, see below | 0 |
| P9 | PSR-15 middleware + routing | Not started — route admin.php through the same pipeline as index.php from the start, see below | 0 |
| P10 | Observability | Not started | 0 |
| P11 | Cache + session + messenger + `opcache.preload` | Not started — build named pools and failed-job visibility from the start, see below | 0 |
| P12 | CLI tool + backup/restore | Not started | 0 |
| P13 | Config service | Not started — keep DB-persisted and typed config as one source of truth, see below | 0 |
| P14 | DB layer + Doctrine ORM | Not started — build real EntityRepository/EntityManager use from day one, no interim DBAL layer, see below | 0 |
| P15 | Schema migration + multi-provider | Not started — skip the table-prefix mechanism entirely, see below | 0 |
| P16 | Typed facades + constants + language | Not started | 0 |
| P17 | Domain tier 1 | Not started | 0 |
| P18 | Domain tier 2 | Not started | 0 |
| P19 | Domain tier 3 | Not started — decide Common namespace scope up front, see below | 0 |
| P20 | Domain tier 4 | Not started | 0 |
| P21 | Admin controller migration | Not started — one service per page, no god-classes, see below | 0 |
| P22 | Frontend controller migration | Not started | 0 |
| P23 | Legacy deletion & cleanup | Not started — see the explicit pre-phase decisions and verification checklist below | 0 |
| P24 | (retired as a phase) | Not a phase — a prior attempt needed a large dedicated remediation pass here because several cross-cutting concerns (DI discipline, DBAL-vs-ORM, table prefixes, superglobal DTOs, typed session/cookie access) weren't designed in from the start. Epoch F below folds those directly into the phases that should own them from day one, so this remediation pass shouldn't be needed again | 0 |
| P25 | (folded into Epoch G) | Not a separate phase this time — the audit step now runs at the start of Epoch G, see below | 0 |
| P26 | (folded into Epoch G) | Not a separate phase — holds by construction once there is no legacy WS envelope to move methods off of, see below | 0 |
| P27 | Public API v1 (REST + OpenAPI 3.2 + tus) | Not started — build directly to this shape from day one, skip the legacy-WS-modernization intermediate, see Epoch G below | 0 |
| P28 | Security hardening | Not started | 0 |
| P29 | Plugin / Theme contracts | Not started — ground design in a real plugin/theme usage survey first, see below | 0 |
| P30 | Layer decoupling + repository restructure | Not started — expect this to already be satisfied by P6/earlier phases if done right; verify, don't re-engineer, see below | 0 |
| P31 | Smarty → Latte template migration | Not started | 0 |
| P32 | Latte lint/format tooling | Not started — build lint/format tooling and wire CI enforcement in this same phase, see below | 0 |
| P33 | Latte idiomatic modernization | Not started | 0 |
| P34 | Event system: legacy-hook-catalog migration | Not started — dispatcher itself already built at P8, see below | 0 |
| P35 | Browserslist decision + IE back-compat removal | Not started | 0 |
| P36 | Asset-pipeline foundation (ViteManifest) | Not started — asset-declaration model already decided (view-declared), see below | 0 |
| P37 | Typed page-data exposure (PHP half) | Not started | 0 |
| P38 | Inline JS extraction | Not started | 0 |
| P39 | Inline CSS extraction | Not started | 0 |
| P40 | Typed view objects + `Template` split | Not started — expect this to be the largest single diff in the epoch, see below for the gotchas to design around from day one | 0 |
| P41 | Shell-last rendering, `PageState` split, and asset-pipeline cutover | Not started — fold the asset-pipeline swap into this phase from the start, see below | 0 |
| P42 | Declarative page assets & exposed data (View-level) | Not started — expect this to rival P40 in scale, see below | 0 |
| P43 | Typed contributions + plugin-owned routes | Not started | 0 |
| P44 | Escaping campaign | Not started | 0 |
| P45 | Latte lint/format enforcement | Not started | 0 |
| P46 | JS → TS mechanical conversion | Not started | 0 |
| P47 | `getPageData<T>()` typing + `any` reduction | Not started | 0 |
| P48 | Refactor TS into modules | Not started | 0 |
| P49 | Lit component catalog (tag autocomplete + tree picker) | Not started — moved ahead of P50, see below | 0 |
| P50 | Remove jQuery | Not started — the unconditional completion phase now that P49 runs first, see below | 0 |
| P51 | TS modernization | Not started | 0 |
| P52 | CSS architecture modernization | Not started — Tailwind call resolved (not adopted); design below | 0 |
| P53 | Picture pipeline (new feature) | Not started | 0 |
| P54 | Dark mode (new feature) | Not started | 0 |
| P55 | Real quality gates | Not started | 0 |
| P56 | Typo & non-English content sweep | Not started | 0 |

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
- **Reference branches**: `16.x-rewrite` (`../piwigo16-rewrite`) is a
  read-only design target for REPLAY work — reproduce its behavior; never
  `git checkout` or cherry-pick from it. The `deprecated_*` branches
  (the renamed prior `17.x-rewrite[-2/-3/-4]` attempts) are read-only
  reference in the same sense, for the lessons and validated design calls
  folded into this file's phase instructions — not a source to merge or
  cherry-pick from either.

## Phase detail

Current tool and system state lives in `docs/REFERENCE.md` and is not
duplicated here. This section gives each phase's build instructions, with
validated design decisions and known gotchas from prior attempts folded
in as the reasoning behind them.

### Epoch A — Foundation (P0–P4)

**P0 — PHP tooling + baselines.** Install Pest and its full plugin set
now, not just the PHPStan-focused extensions named below in isolation —
`pestphp/pest-plugin-arch` (P2's Arch suite depends on it) and
`pestphp/pest-plugin-mutate` (P2's mutation-testing tier depends on it)
are structural
prerequisites for work this same epoch already commits to, not optional
add-ons to remember later — plus pcov, ECS, PHPStan, Rector, Deptrac
(defer its ruleset config to P6, once real namespaces exist to
enumerate), ComposerRequireChecker and ComposerUnused, PHPBench,
roave/security-advisories. Additive only — record baselines, don't gate
on them yet; ECS and Rector become code-modifying passes later (P5). **Set
an explicit PHPStan level policy now rather than leaving it implicit:
start the whole repo at level 8, ratchet to 9 and then `max` once P6's
real namespaces replace the placeholder skeleton** — track the committed
baseline file's own size as a real metric from the first commit that adds
one, and treat baseline *growth* on a PR (not merely the baseline's
existence) as a signal worth a reviewer's attention, not debt that's
allowed to silently accumulate because "it's already baselined." **Skip
Psalm entirely this time** — a
prior attempt adopted it, dropped it over a Pest-version conflict,
readopted it pinned to a `-dev` release with a hand-patched crash, and
never got it gating anything. PHPStan/ECS/Deptrac/Pest are the real
gates; don't repeat the adopt-drop-readopt cycle for a tool that ends up
non-gating regardless. Install a handful of PHPStan extension packages
from early on rather than leaving them for a late audit pass — each
found a real, previously-invisible class of gap in a prior attempt:
`pestphp/pest-plugin-phpstan` (teaches PHPStan Pest's own `$this`-binding
and fluent `expect()`-chain types — installing it retroactively dropped a
prior attempt's full-repo error count by two orders of magnitude and let
16 blanket path-scoped suppressions be deleted in favor of real fixes);
`phpstan-deprecation-rules` (plus `phpstan-symfony` if any standalone
Symfony components are direct dependencies — together they found 61 real
deprecated-API call sites in a prior attempt, including one genuine,
non-mechanical behavior migration in a vendor library, not just renames);
and `staabm/phpstan-dba` (schema-aware SQL validation against a real live
database connection, already referenced under P15's tooling notes). Add
`phpstan/phpstan-strict-rules` alongside these from day one too — it's
cheap to adopt before any real code exists to have accumulated violations
against it, and it catches disallowed loose comparisons and implicit
truthy/falsy checks the base PHPStan levels don't. **P0 is done once
every installed tool runs successfully against the bare skeleton (clean,
or against a freshly committed baseline) and its output/baseline
artifacts are committed** — not once each tool is merely installed and
never actually invoked.

**P1 — Frontend tooling + baselines.** bun, Vite, TypeScript, ESLint,
Stylelint, Vitest, knip, size-limit, commitlint, Lighthouse CI and
`web-vitals`. **One line on why bun, not npm/pnpm/yarn:** its native
TypeScript/JSX transpilation and built-in test runner overlap directly
with territory Vite/Vitest already cover, and its install speed matters
most exactly here, at the front of every CI job and every local
checkout. **Install and pin the `playwright` npm package here, via bun**
— P2's Pest Browser E2E/VR suite is what drives it, but it's a Node
dependency like everything else this phase owns; P1 installs and pins the
package (and, per P35's dependency-bump gotcha, its bundled browser build
specifically, not just the package's own semver range), P2 wires Pest's
own Browser-plugin config against it. Cover bun/npm-resolved dependencies
with the same OSV-scanner job P3 wires for Composer's — one job, two
lockfiles (`composer.lock` and `bun.lock`) — rather than leaving JS/TS
dependency scanning unnamed until P3 introduces it for the PHP side
alone. **Wire `web-vitals` to a real endpoint in this same phase** —
don't just install the package and leave it dangling log-only-later;
build the `VitalsController` + route alongside the install, not as a
follow-up close-out, as the permanent ingestion sink P10 (Observability)
later builds dashboards on top of, not a throwaway stub P10 has to
replace.

**Run one mechanical, tree-wide rename pass over every legacy `.js`/`.css`
file in this same phase, immediately after installing ESLint/Stylelint —
don't wait for P46-51/P52 to touch these files for real.** `origin/16.x`'s
own frontend code has no one naming convention to preserve, confirmed
directly rather than assumed: `themes/default/js/scripts.js` alone mixes
`pwgAddEventListener`/`pwgBind` (camelCase with a lowercase prefix),
`popuphelp` (no internal word separation at all), and `PwgWS` (PascalCase,
despite being a function, not a class); `themes/default/theme.css` mixes
`.action-buttons` (kebab), `.actionButtons` (camelCase), and
`.additional_info`/`.header_msgs` (snake_case) in one file. Targets:
TypeScript/JavaScript — `camelCase` variables/functions/methods,
`PascalCase` classes/types/interfaces/Lit components, `UPPER_SNAKE_CASE`
true module-level constants; CSS — `kebab-case` for every class selector
and custom property. **Strip every `pwg`/`Pwg`/`PWG` prefix in the same
pass** — it's redundant the moment first-party JS/CSS lives under this
project's own build (no ambient global-scope collision risk to guard
against, the same reasoning P6 applies PHP-side below), so there's no
reason to carry it forward and drop it later. This is a pure,
mechanical, name-preserving rewrite (a codemod plus a project-wide
find/replace, not a redesign) — verify it with the existing VR baseline,
which must show zero pixel diff, proving nothing but names actually
changed. Enforce with ESLint's `@typescript-eslint/naming-convention`
rule (added here from day one — it has no equivalent already configured,
confirmed absent from every prior attempt's own ESLint setup) and
Stylelint's `selector-class-pattern`/`selector-id-pattern`/
`keyframes-name-pattern` (a prior attempt installed Stylelint with all
three explicitly disabled, apparently because turning them on against
the still-legacy CSS would immediately flag hundreds of violations, and
never revisited enabling them once the rename above would have made
that free). **Flip both to blocking immediately once this pass lands,
not deferred to P46-51/P52 or any later phase** — every JS/CSS
identifier already has its real name from this point on, so P46-51's
TS conversion and P52's CSS architecture work both start from an
already-clean, already-enforced baseline rather than inheriting legacy
naming debt to clean up themselves.

**P2 — Test harness.** Env split (`.env.test`, `X-Piwigo-Env: test`),
fixture DB, Pest Browser E2E and WS Contract suites. **The
`X-Piwigo-Env: test` header switch must only ever be honored when the
server's own config already declares a non-production environment** —
check `APP_ENV`/equivalent server-side config first and ignore the header
entirely otherwise, so the switch can never let a client flip
test-fixture behavior on a real production deployment just by sending a
header. Add an explicit acceptance test for this in P2 itself: assert the
header is silently ignored (production behavior unchanged) when
server-side config says production, and honored only when it already
says test/dev — not a test that only ever exercises the honored path.

**Write real tests in the same commit as the code they cover, from P0
onward — never as a deferred "add coverage later" pass for a whole
phase or domain.** The entire value of having test infrastructure this
early is that every later change gets validated immediately against a
real, already-growing regression suite, not against a coverage
percentage nobody has actually looked at recently. A phase or sub-item
isn't done when the code compiles and passes a manual check; it's done
once its own real tests exist and pass, in the same commit. This is
what makes "did this change break anything" answerable in minutes
throughout the whole rewrite instead of only at occasional, expensive
full-suite checkpoints — treat a growing gap between "code written" and
"code tested" as accumulating real risk, not as a schedule optimization.

**Design the suite's tiering around feedback *speed* explicitly in this
phase, not just around what infrastructure each test happens to need.**
Decide, and document, which tier is the fast, no-real-I/O tier a
developer runs on every save or before every commit (no real DB
connection, no real HTTP round trip, no real filesystem/subprocess
work) versus which tiers exist for correctness guarantees a fast tier
structurally can't provide (real DB-backed Integration/Contract
behavior, real-browser Browser/VR rendering). Keep the fast tier's own
definition genuinely tight — the moment a "quick DB check" or "just this
one real file read" creeps into it for convenience, its speed erodes
silently and it stops being the tight, always-run feedback loop it
exists to be. `pest --mutate`'s own line-vs-assertion-coverage findings
(below) matter far more once this tight, always-run tier actually gets
run on every real change, not just in occasional full-suite passes.

**Profile the test suite's own runtime as a first-class, continuously-
tracked metric from this phase on, not a problem fixed reactively only
once the suite becomes unbearable to run.** Wire per-test/per-file
timing into the suite's own output from the start (Pest/PHPUnit both
report the slowest tests in a run; keep that report visible, not
scrolled past) and set an explicit, tracked threshold for the fast tier
specifically — **target under 60 seconds wall-clock with `--parallel`
warm on CI hardware, under 3 minutes without it**, stated as a default
worth revisiting once the real suite's eventual size is known rather than
left unstated indefinitely; a suite that silently grows slower one test at a time
never presents a single moment that "feels" like regression, only a
slow accumulation nobody notices until running the suite has quietly
become a chore developers start avoiding, which is the exact opposite
of what an early, fast suite is for. When a genuinely slow test is
found, prefer fixing its real cause over demoting it to a slower tier
as the default response: a real `sleep()`-based wait where a
deterministic readiness signal exists, an N+1 query pattern a real
production code path would suffer from too (worth fixing at the
source, not just tolerating in tests), an unindexed lookup, or a real
subprocess spawn where a fake/stub would do, are all real inefficiencies
worth fixing outright — moving a slow-because-of-a-real-bug test to a
slower tier just hides the bug's own performance cost instead of fixing
it. Reach for real parallelism (`--parallel`) from day one as part of
the suite's own baseline design, not a later bolt-on — see the
`--parallel`-specific cross-file-race and shared-connection gotchas
below, which are the real, load-bearing cost of adopting it early
rather than reasons to defer it. Structure CI so the fast tier reports
back first and fastest (its own job, gating quickly) while slower tiers
run alongside it rather than strictly after it, so waiting on the full
gate doesn't become the bottleneck for every single change.

**Build combined Unit+Arch+Integration+Contract+Browser coverage
measurement into this phase, not later.** A prior attempt found a real,
substantial zero-coverage gap only once Contract and Browser coverage
became measurable together (pcov work) — a large number of classes had
been invisible to *every* siloed measurement individually, not caught
by any one tier's own report. Get combined coverage measurable from day
one instead of accumulating a real, undiscovered gap for months. **Name
the actual server-side collection mechanism, since "pcov work" alone
doesn't say how coverage gets out of a live web-server process** —
Unit/Integration/Contract coverage comes from `pcov`'s normal CLI-process
collection, but Browser tests drive real HTTP requests against a running
FrankenPHP/PHP process, a different OS process than the Pest CLI
runner entirely. Enable `pcov` on that server process too
(`pcov.enabled=1` in the test environment's own `php.ini`, gated behind
the same `X-Piwigo-Env: test` check P2 already builds so it's never live
in production), have each request write its own per-request coverage
data to a shared directory (`PCOV\collect()` at request end, one file
per request, avoiding concurrent-write corruption across parallel
Browser workers), and merge all of it — CLI-collected plus every
per-request file — via `SebastianBergmann\CodeCoverage`'s own merge API
at the end of a full suite run, before the combined report is generated.
Same
for mutation testing (`pest --mutate`) — run it early and often, not as
a late one-off sweep. It's the only
thing that reliably surfaces certain bug classes PHPStan/ECS structurally
can't catch: wall-clock reads where a test-injectable clock abstraction
should be used instead (`SessionRepository::gc()`/
`LoungeMaintenance::needsEmptying()`-style bugs), off-by-one string
artifacts in URL/path builders, corrupted regex literals in
locale-specific code (a French pluralization inflector had this),
untested day/month-boundary gaps in date formatting, error-monitoring
SDK config leaking into test runs, deprecated error-trigger patterns
that should route through a real error collector instead, and process
umask leaks in upload handling. The single most valuable, recurring class
of gap it surfaces: **line coverage that isn't assertion coverage** — a
test can execute every branch of a method while only asserting on one of
many computed values, letting a wrong caption/field/flag ship invisibly
(a prior attempt found this dozens of times: a caption-generating helper
whose 27 test cases all checked URLs but never the caption text itself; a
diagnostics renderer with zero structural assertions beyond one substring
check). Treat a mutation-testing pass as the real completion gate for any
"this class is tested" claim, not the coverage percentage alone. Two
narrower, reusable techniques the same sweep validated: distinguish
"blocked by an extension/type check" from "genuinely unsupported content"
in a file-type handler's tests by feeding real, valid content saved under
the *wrong* extension (proves the check fires on the extension, not the
content); and reach for `ReflectionMethod::invokeArgs()` with an explicit
by-reference variable, never `invoke()`, when testing a method that
mutates a by-ref parameter — `invoke()` silently drops the mutation,
which looks like a passing test that asserts nothing. **CRITICAL:** any
command backed by a real recursive filesystem delete (`removeDir()`-style)
must never hardcode its target to a real project-relative path — inject
it via a constructor/parameter so tests point it at an isolated temp
directory, and add an independent structural guard on the resolved path
(e.g. verify the basename/parent match an expected shape) before
recursing. Mutation testing executes real mutated production code, not a
simulation: a single mutated line that breaks the path computation and
widens it to the project root is exactly the kind of one-line change a
`pest --mutate` run makes routinely — a prior attempt had this actually
recursively delete its own repository's working tree multiple times
before the target path was made injectable and independently guarded.
The same warning applies equally to any code path that shells out or
writes to shared infrastructure, not just filesystem deletes — a mutated
`escapeshellarg()`/exec()-string-building method actually ran a real,
malformed shell command against the real shared test database and
filesystem in a prior attempt's own mutation run, corrupting a live DB
row and leaving debris directories behind. Isolate or sandbox any code
path that shells out or touches shared state the same way the
recursive-delete command needed an injectable target before it's safe to
subject to mutation testing at all.

**CRITICAL — a second, independent way the real repository's working
tree can get destroyed during test runs: a DI-container boot
routine's own idempotency guard silently keeping a stale root binding
across a mismatched re-boot.** A prior attempt's `Kernel::boot()`
silently no-op'd whenever a second call passed a *different*,
non-null root (its idempotency guard only checked "already booted,"
never "booted with the value I'm now passing") — so a test that boots a
disposable fixture/temp root, without first calling the framework's own
`reset()`, could silently inherit an *earlier* test's leaked real-repo-root
binding instead. Any filesystem-mutating test that then reads that root
(`chmod`, `mkdir`, a recursive delete) operates on the real checkout, not
its own throwaway directory — this was live-confirmed as the actual
mechanism behind repeated, previously-unexplained catastrophic wipes of
a prior attempt's own working tree during full-suite runs. Design the
boot routine to **throw**, not silently no-op, whenever it's called a
second time with a genuinely different non-null root — forcing every
test that needs a different root to call `reset()` explicitly first,
converting a silent data-corruption risk into a loud, immediate test
failure at exactly the site of the mistake. Two further layers of
defense worth building in from the start, since the throw-on-mismatch
fix alone isn't sufficient by itself: teardown/cleanup logic (an
`afterEach()`-style hook) must capture its own intended target into a
local test property *before* calling boot(), and read that captured
local value for any later destructive operation — never re-resolve the
target from the container at cleanup time, since a test framework's own
teardown hooks still run even when the corresponding setup threw (a real,
non-obvious lifecycle semantic to design around, not assume away). And
add a cheap, independent structural guard at the very start of *every*
recursive-delete implementation in the codebase (there may be more than
one) that refuses to operate on anything resembling the real project
root (e.g. a directory with both a `composer.json` and a `.git` entry
directly inside it) — a last-resort backstop that fails loudly at the
point of the dangerous call itself, regardless of how a wrong path got
there.

**CRITICAL — Doctrine's `EntityManager`/`UnitOfWork` object graph is
reference-cyclic, so PHP's refcounting alone never frees it; expect this
to matter a great deal under any long-lived-process execution model
(parallel test workers today, true FrankenPHP worker mode later).** A
prior attempt found thousands of uncollected "possible roots" accumulate
after only dozens of `EntityManagerFactory::build()` calls in a tight
loop, with zero automatic garbage-collector runs — a `Connection` trapped
in that cycle keeps its DB socket open long after the code that created
it returns, so connections silently accumulate across a long-lived
process's entire lifetime instead of being freed between logical
"requests," eventually exhausting the DB server's own connection limit
under concurrent load. Under parallel test workers this surfaced as
random "too many clients" failures; under a genuine long-lived worker
process (the committed FrankenPHP worker-mode direction) the identical
mechanism would leak real production DB connections request over
request, not just in tests. Force an explicit `gc_collect_cycles()`
sweep at a natural request/test boundary rather than trusting PHP's
own threshold-triggered auto-collection to run often enough — and if
building a suite-wide test hook for this in Pest specifically, register
it via `uses()->afterEach()->in(...)`, not a bare global `afterEach()`
in a shared bootstrap file: Pest's own hook registry keys hooks by the
file they're declared in, so a bare `afterEach()` sitting in a file that
defines no tests of its own silently never fires at all. A per-boundary
`gc_collect_cycles()` sweep is not free — it's a full cycle-collector
pass over the live object graph, and calling it on every single request
inside a genuinely long-lived FrankenPHP worker (once P7's worker-mode
decision lands, not the test-runner boundary this paragraph otherwise
describes) is real per-request latency paid whether or not a cycle
actually needs collecting that request. Decide the worker-mode policy
explicitly rather than defaulting to "call it every request": sweep on a
fixed request-count or wall-clock interval (e.g. every N requests, tuned
against real leaked-connection-count telemetry once it exists) rather
than on every single one, and pair the interval sweep with periodic
`EntityManager`/`Connection` recreation — closing and rebuilding the
`EntityManager` (and, if pooling reveals the app-level `Connection` itself
still accumulates cyclic garbage, the DBAL `Connection` beneath it) on
that same interval bounds worst-case connection accumulation even on a
run where the GC sweep alone doesn't fully collect the cycle in time.
Cross-reference this against P7's own worker-mode timing decision — it's
the phase where "does true worker-mode execution land now or later"
actually gets decided, and this lifecycle policy only has a live worker
process to apply to once that decision is made.

`pest --mutate` also has two structural blind spots worth knowing about
rather than mistaking for a real gap: it cannot detect a mutation killed
only by a subprocess-based test (`proc_open()`) — the child process
re-reads the source file directly off disk, always seeing the real
unmutated code regardless of which mutant the controlling process is
"applying," so a genuinely-passing crash/fatal-error test still reports
as "untested"; and the mutate plugin's own stream wrapper treats any
0000-permission-but-existing path as nonexistent for the *entire*
mutation run while a mutation subprocess is active, for any path, not
just the mutated file. Document both explicitly per-file when hit,
rather than chasing an unfixable false negative — and expect the scanner
to have further, unexplained blind spots beyond these two documented
ones: when a mutation is live-verified (a direct sed-applied source
mutation plus a full suite rerun, reproduced more than once) to be
genuinely killed by a real test, but the tool's own scan still reports it
UNTESTED, trust the live verification and document it as
killed-but-tool-invisible rather than writing more tests chasing a gap
that isn't real. Two more test-hygiene notes from the same sweep: a
blanket suppress-everything error handler is just as blind to a mutant's
signature as `@` — use a *selective* handler that only swallows the one
expected warning and asserts nothing else fired; and returning `false`
from a custom `set_error_handler()` callback falls through to PHP's own
built-in reporting, not back to whatever handler (e.g. PHPUnit's) was
active before it was installed. Two `--parallel`-specific gotchas, since
ParaTest splits test *files* across separate worker processes rather than
sharing one process: a plain helper function declared as a top-level
symbol in only one test file is invisible to any worker that happens to
run a different file first — put anything genuinely shared across test
files in `tests/Pest.php`, the one file Pest always loads in every
worker, not in an arbitrary file that happens to declare it; and a single
shared *row* in a real global DB table that two different test files both
manipulate directly can race across workers the same way two concurrent
production requests would — wrap the affected tests in both files with a
server-wide `GET_LOCK()`/`RELEASE_LOCK()` mutex, not a connection-local
one. More generally: any test file that cleans up via a *broad* pattern
match (`DELETE ... WHERE uuid LIKE 'prefix-%'`) or draws from a *shared*
small pool of "real" fixture rows/ids is a cross-file race under
`--parallel`, full stop — this recurred independently across several
unrelated domains in a prior attempt (search, caddie, permalinks), each
time two test files silently sharing a uuid namespace or a small fixed
id pool, with one file's cleanup or write deleting/colliding with the
other file's still-in-flight row. Partition explicitly per file (a
distinct uuid prefix per file, a disjoint id range/pool per file) as a
standing convention from the start, rather than discovering each
collision as its own flaky-test investigation.

**When porting a sequential-suite (Integration-style) test down to a
`--parallel` Unit-style suite that shares one live DB across worker
processes, never carry over the original's own blanket
`tearDown()`-style "reset these shared fixture columns back to their
default" cleanup verbatim.** That reset is only safe under sequential
execution; under `--parallel` it races with every other file's own
in-flight assertions against the same shared rows (e.g. two files both
reading a shared category's `status` column while a third resets it
mid-run), and — worse — the code path under test can itself have
side effects on rows *beyond* the ones the test directly touched (a
status-cascade that also silently deletes an unrelated permission row a
completely different test file depends on), which a narrow
per-test `finally`-block restore of only the directly-touched columns
will miss. Two concrete fixes, applied together as needed: (1) restore
every column/row a real production code path *could* touch as a
side effect of the operation under test, not just the ones the test
explicitly set up, confirmed by tracing the real method's own full write
set rather than assuming it matches the test's own writes; (2) for
tests whose whole scenario is inherently a shared-row mutation, wrap the
mutation and every assertion inside one shared-connection transaction
(`beginTransaction()`/`rollBack()`) instead of mutate-then-restore, so
the writes stay invisible to every other file's own connection for the
whole test and disappear automatically regardless of what the code
under test actually touched. Verify the fix under real contention, not
just a clean run: a single passing `composer test --parallel` run proves
nothing about a race — rerun the full affected suite 15–30 times in a
loop and expect near-100%-frequency failure to surface if a shared-row
hazard is still present, since a low but nonzero collision rate reads as
"probably fine" right up until it doesn't. Always reimport the fixture
between verification-loop runs (the normal `composer test`-family
scripts already do this; running the underlying test-runner binary
directly does not) — letting a loop iterate many times against the same
unreset DB lets auto-increment counters climb unboundedly, and a
small-width id column (e.g. a `smallint unsigned` primary key) can
genuinely hit its ceiling after enough iterations, producing a wave of
unrelated-looking failures with a root cause far from where they surface.

**Design the DB-isolation mechanism for a `--parallel` Unit suite up
front, as real infrastructure, rather than hand-fixing shared-row races
file by file as they're discovered** — a prior attempt spent a large,
open-ended sequence of individual fixes on exactly this class of bug
before finally building the real mechanism, at which point most of the
individual fixes became unnecessary. The mechanism: intercept at the
connection-*factory* level (not by wrapping each test's own top-level
connection) so that every call anywhere in a test's call graph —
including a secondary/independent connection opened internally by the
code under test itself, not just the test's own — transparently
resolves to the same single, already-open, never-committed transaction,
opened in a global `beforeEach` and rolled back in a global `afterEach`.
Wrapping only the test's own connection is not equivalent: a secondary
internal connection opened by the code under test can deadlock trying
to acquire a lock the outer, still-open transaction already holds on
the same row, and only intercepting at the factory level (so there is
never a genuinely second connection at all) eliminates that risk
structurally rather than papering over it per call site. **Build this on
top of `dama/doctrine-test-bundle`'s `StaticDriver` rather than
reimplementing factory-level connection interception from scratch** — it
already is exactly this mechanism (a static, process-wide DBAL driver
decorator that transparently keeps every newly-constructed `Connection`,
including ones opened internally by code under test, resolving to the
same open, never-committed transaction); the two exemption classes below
(DDL/implicit-commit statements, full-text-index deadlock avoidance) and
the transient-visibility/global-side-effect cautions that follow are this
project's own real additions layered on top of `StaticDriver`, not a
reason to avoid adopting it as the base.

This structurally eliminates ordinary cross-file races, but expect (and
explicitly document, per call site) two classes of test that
structurally defeat it and must opt out to their own real
commit-per-statement behavior plus explicit try/finally cleanup instead:

- **DDL/table-maintenance statements** (`CREATE`/`DROP`/`OPTIMIZE TABLE`
  and similar) **implicitly commit** in a database like MySQL,
  silently ending the wrapped transaction out from under the rest of the
  test. `OPTIMIZE TABLE` specifically is more disruptive than its name
  suggests on InnoDB: it's internally a full `ALTER TABLE ... FORCE`
  table rebuild, which bumps the table's own metadata/definition
  version — any other session (a different `--parallel` worker) holding
  an already-prepared statement against that same table gets a real
  "table definition has changed" error on its next execution, not just a
  brief lock wait. If a test genuinely needs to force a full-text
  index's word cache to sync (rather than actually needing the table
  optimized), `FLUSH TABLES` achieves the identical visibility guarantee
  by closing and reopening the table's cached handle — InnoDB's own
  documented, much lighter mechanism for this — without the schema-level
  rebuild or its cross-session disruption.
- **A table carrying a full-text/auxiliary secondary index only
  synchronizes that index on commit, and holding a real INSERT/UPDATE/
  DELETE against such a table open inside a long-lived wrapped
  transaction for a whole test's duration can deadlock against another
  `--parallel` worker's own concurrent write to the same table** — this
  is not a hypothetical, it recurred across every service/repository
  test file in a prior attempt that ever created a disposable row in a
  full-text-indexed table, discovered only via repeated
  fresh-fixture-reimport `--parallel` loops, never via a single clean
  run. Audit every DML site against every full-text-indexed table
  specifically (not just "does this test write to the DB") before
  considering the wrapper's rollout complete — a grep pass scoped to an
  obvious helper-function call can still miss sites that reach the same
  table only indirectly through a different shared fixture helper.

Once a test is exempted this way, its writes are **genuinely,
transiently visible to every other wrapped test's own
snapshot-isolated reads** for the whole window between its real commit
and its own cleanup — any exact-list/exact-count assertion elsewhere in
the suite touching the same table needs to filter down to known-real
ids or tolerate extra members, not assert a closed universe, since it
can no longer assume it's the only writer. Watch too for a **global
side-effect a normal-looking insert can trigger on rows the test never
directly touched** — a "recompute a canonical, contiguous rank/ordering
across every row in a table" routine that fires on any insert into that
table is a real example: a disposable row inserted at an unspecified/
default position can transiently renumber a real fixture row's own
position for as long as it exists, observable by a completely unrelated
test reading that column. Give a disposable row an explicit, deliberately
last/uninvolved value for any such globally-recomputed column rather
than leaving it to the schema's own default — a `NULL` default that
sorts first in ascending order is a common, easy-to-miss instance of
exactly this hazard.

Finally, expect at least one **genuine InnoDB-style two-table
lock-order-inversion deadlock that isn't fixable by tuning either side's
own query** — e.g. an `ON DELETE CASCADE` foreign key forcing a broad
scan-lock on the referencing table to verify referential integrity,
regardless of how precisely the deleted rows on the parent side were
selected. Root-cause with the database's own lock/transaction
introspection tooling before concluding this, not by guessing — but once
confirmed as a legitimate collision between two independently correct,
correctly-isolated transactions rather than a missing exemption or a
missing filter, it's reasonable to document it as an accepted residual
(with its observed failure rate) rather than chasing further mitigation
that wouldn't fix a real engine-level lock-ordering conflict anyway.
Two Pest-specific coverage-flag
gotchas to verify rather than assume: combining Pest's bare `--coverage`
with `--coverage-php` crashes Pest's own internal temp-path resolution
(use `--coverage-php` alone); and a `--min=N` coverage threshold is
silently a no-op without the bare `--coverage` flag present — confirm any
coverage gate actually fails a build when coverage is genuinely below
threshold, don't trust that a flag combination is enforcing anything.
One more `pest --mutate` limitation worth expecting: the plugin composes
its `--filter` argument from *every* test covering a mutated line with no
length guard — mutating a line inside a very widely-shared helper (a
teardown method called from hundreds of files' own `afterEach()`, for
instance) can compose a filter string past Linux's ~128KB single-argv
limit, crashing the *entire* mutation run outright rather than just
failing to score that one mutation. No upstream fix existed as of a prior
attempt's own investigation; patch in a fallback that runs the mutant
against the full test suite instead whenever the composed filter would
be unsafely long — always correctness-preserving, just slower for that
one mutant.

**Decide VR (visual-regression) determinism explicitly in this phase,
not as a later remediation project once flaky baselines make the suite
untrustworthy** — this needed a dedicated later fix in a prior project's
own history, and every root cause traced back to a decision left implicit
at adoption time rather than a hard problem. Pin the rendering
*environment*, not just the code under test: capture VR screenshots
inside the same container image (and therefore the same font set and
fontconfig) in CI as in local dev, never against a CI runner's or a
developer's own ambient system fonts, since font substitution and
subpixel/anti-aliasing metrics differ across machines and silently
invalidate baselines with zero real rendering change. Diff with an
explicit numeric pixel-tolerance from the start, never a bare
pixel-for-pixel match — start at a small, named threshold (0.1%
differing pixels, a `pixelmatch`-style perceptual diff rather than exact
byte comparison) and treat any baseline that only passes after *loosening*
the tolerance as a signal to investigate the render, not license to widen
the number further. And make baseline review a real reviewable step:
a changed baseline PNG is committed alongside its PR and diffed visually
in review like any other change, never regenerated and merged
automatically on a CI mismatch.

**A Browser-suite "no server errors" helper must recognize the
application's own generic error-response shape, not just raw PHP error
text.** A helper pattern-matching for "Fatal error"/"Warning:"/"Uncaught"
never matches an app's *intentional* generic "Internal Server Error" 500
body (the correct, deliberate behavior of a production exception
handler) — silently letting a real request failure pass the check, after
which the test blindly continues trying to fill in/click form fields
that don't exist on the error page, retrying for the full outer timeout
instead of failing fast with an attributable cause. Include the app's own
real error-page shape in that pattern from the start.

**P3 — CI pipeline.** Design `ci.yml`'s job layout, matrix, and caching;
wire actionlint, commitlint, SBOM and OSV-scanner jobs, a secret-scanning
job (gitleaks, scanning full history on first adoption and just the diff
on every PR after), and OpenSSF Scorecard from the start. **Treat
Scorecard as informational, not a merge gate**: publish its score and let
it drive periodic hardening work, but don't block PRs on it — a
meaningful share of its checks (Dangerous-Workflow, Token-Permissions,
Branch-Protection) score controls this project already builds by
construction elsewhere in this epoch, so gating on the composite score
would duplicate an existing control rather than add a new one, and would
also block merges on criteria this project has no realistic path to
scoring perfectly on (e.g. Fuzzing) regardless of real code quality.
**Build and smoke-test every P4 container target in `ci.yml` itself**
(FrankenPHP and the Apache-fallback target — see P4 for the corrected
target count), not just the PHP/JS application test suites — a container
image that builds cleanly but is never actually started and hit with a
real request in CI is exactly the kind of gap that let P4's own
docroot-exposure incident ship undetected for as long as it did.

**P4 — Containerization + runtime image.** Multi-stage Dockerfile
(FrankenPHP plus Apache-fallback targets — **two server targets, not
three**; correcting an earlier miscount in this phase's own scope), Compose,
Helm chart, `/health` and `/ready`, restore drills, SEC-01 web-root deny
rules across both server targets. Set `DocumentRoot`/docroot explicitly to
`public/` on every server target that gets one configured from scratch
(the base Apache image's own default is the repo root) — a prior attempt
missed this on one Dockerfile stage, serving the entire source tree
(`vendor/`, `src/`, `.env.example`, `composer.json`) over HTTP until
caught. When a CI job asserts a deny rule returns 403, verify the
*mechanism* actually producing that status is the one being tested, not
just that the status code matches — a prior attempt's own "expected 403"
assertion passed for months for the wrong reason (an OS-level directory-
traversal permission wall blocking the CI runner's own web server user
from reaching the checkout at all, unrelated to any `<Directory>`/
`.htaccess` rule), staying hidden until the one assertion expecting a
real 200 finally exposed it.

**Scope "restore drills" here explicitly to what P4 actually has to
restore with**: this phase predates P12's own `BackupService`/`bin/piwigo`
CLI tooling, so the drill at this stage is a raw restore against the
Compose/Helm stack directly — a `mysqldump`/volume-snapshot taken from a
running stack, the stack torn down and rebuilt from the image, and the
dump/volume restored back into it — proving the *infrastructure* can be
rebuilt and repopulated from a backup, not exercising any application-level
tooling that doesn't exist yet. Re-run the same drill against the real
`BackupService`/CLI once P12 lands, as part of P12's own verification, not
as a reason to skip or fake this phase's drill now.

**Harden the container itself in this phase, not only its web-root
exposure**: run as a non-root `USER` in every stage, drop all Linux
capabilities by default and add back only the ones FrankenPHP/Apache
genuinely need to bind a low port (`CAP_NET_BIND_SERVICE`, rather than
running the whole process as root just to bind 80/443), and mount the
container's root filesystem read-only in Compose/Helm with explicit
writable volumes only where something genuinely needs to write (upload
storage, cache, logs). Apply the same "verify the mechanism, not just the
status code" discipline already used for the SEC-01 403 check here too:
confirm a process actually fails to write outside its declared writable
volumes under the hardened config, don't just assert the flags are
present in the manifest. **Scan built images for known vulnerabilities
(Trivy or Grype) in this phase**, distinct from and in addition to P3's
source-level OSV-scanner — a clean `composer.lock`/`bun.lock` says
nothing about the base OS image's own installed packages (glibc,
OpenSSL, the distro package set), which is exactly what an image scanner
catches and a dependency-lockfile scanner structurally can't. **Give
`/health` and `/ready` genuinely different semantics, not two names for
the same check**: `/health` (liveness) verifies only that the PHP/Caddy
process itself is alive and answering, with no dependency reachability
check at all — a DB outage must never fail liveness, or an orchestrator
kills and restarts an already-degraded pod in a loop that makes a real
outage worse instead of better; `/ready` (readiness) is the one that
checks real dependency reachability (DB, cache, session backend) and
gates traffic routing, not process survival. Set explicit CPU/memory
requests and limits in the Helm chart from the start rather than leaving
them unset, sized from a real measurement once one exists (P12's
PHPBench, or an early load test) and revisited then — an explicit
placeholder value recorded now beats no value at all, since unset
requests/limits leave the scheduler with nothing to pack or protect
against a noisy neighbor.

### Epoch B — Composer/Rector/PHPStan + PSR-4 (P5–P6)

**P5 — Composer + Rector + PHPStan.** Expect this to be the largest
phase by commit count. Whole-codebase ECS `--fix`; PHPStan applied
file-by-file across the legacy tree; replace vendored third-party
libraries per a native-platform-first policy: PHPMailer → Symfony
Mailer, Emogrifier → `pelago/emogrifier`, phpqrcode → `endroid/qr-code`,
phpass → native `password_hash()`.

**State the PHP naming-convention target explicitly here, since `origin/16.x`
itself is internally inconsistent, not just "legacy style" — there is no
single existing convention to preserve.** Confirmed directly against
upstream, not assumed: classes are already reasonably PSR-1-conformant
(`BlockManager`, `PersistentFileCache`), but methods and free functions
mix conventions within the *same file* — `PersistentFileCache::get()`/
`set()`/`purge()` sit beside free functions like `str2url()` (all-lower,
no internal casing) and `str2DateTime()`/`dateDiff()` (camelCase already,
inconsistently, right next to the snake_case majority in the same
`functions.inc.php`). Target: PSR-1/PSR-12 fully — `PascalCase` classes/
interfaces/enums, `camelCase` methods/functions/properties/parameters/
local variables, `UPPER_SNAKE_CASE` constants. ECS's `CamelCapsMethodNameSniff`
(already configured, report-only per its own comment in `ecs.php`) covers
methods; add a variable/property-naming sniff too (PHP_CodeSniffer's
`Squiz.NamingConventions.ValidVariableName` is the standard choice) so
`$category_id`-style legacy properties/locals are flagged with the same
rigor, not just method names. **Keep both report-only here, not blocking
yet** — P5 runs against the still-procedural legacy tree, before P6's
real namespaced classes exist for a rename to land in. **P6 (immediately
next) is where every identifier actually gets renamed, in one pass, not
deferred to each domain's own later touch point** — see P6's own text
below for why. Flip both sniffs to **blocking** the moment P6 closes,
not held report-only for the phases after it — every PHP identifier in
the tree already has its real name by then, so there's nothing left for
a later phase to still be catching. **`mobiledetect/mobiledetectlib`
(`mdetect.php`) is not a settled drop** — its disposition is the open
device-detection decision below; don't drop it here on the assumption
responsive CSS already makes that call, since the two prior attempts
this plan draws on actually disagree on it. For vendored
Smarty: consider skipping the `smarty/smarty` package swap entirely and
going straight to whatever templating engine P31 lands on (Latte last
time) — the package swap was pure intermediate-step churn before the
real replacement anyway.

*Rector*: configure the full rule set from the start —
`withPhpSets`, `withPreparedSets(typeDeclarations: true, instanceOf:
true)`, `withImportNames()`, `withParallel()`, **plus**
`withComposerBased()`, an explicit `SetList::TYPE_DECLARATION`, and
strict-types/dead-tag rules. A prior attempt configured a narrower set
first and only later realized the fuller set (already used by the
reference implementation) was missing — start with the complete set
instead of discovering the gap after the fact. Decide up front whether
the `rector` CI job blocks or stays `continue-on-error: true`, and
record the reasoning either way. **Never trust a Rector auto-rewrite of
a nested ternary/null-coalescing expression as behavior-preserving by
default — review the diff for operator-precedence changes specifically.**
A real, confirmed instance: Rector's own null-coalescing-operator rule
rewrote `$x !== null ? $x : ($cond ? $a : $b)` into `$x ?? $cond ? $a :
$b`, dropping the parentheses PHP's own `??`-binds-tighter-than-`?:`
precedence requires to keep the same grouping — the rewritten
expression's ternary condition silently became `$x ?? $cond` (a
different, always-truthy-once-`$x`-is-set value) instead of just
`$cond`. This produced a real, live form-resubmission data-loss bug
(a page silently re-rendering a stale stored value instead of what the
user just submitted), caught only by a full static-analysis pass
noticing the resulting type didn't match, not by a formatter/linter —
the code still executed without error, it just picked the wrong branch
for specific real inputs. Any automated rewrite touching a nested
ternary or a `??`/`?:` combination is worth a specific manual precedence
check, not just a "still passes the type checker" or "tests still pass"
sanity check, since a precedence bug like this one only misbehaves for
inputs the existing test suite may not happen to cover. Don't rely on
manual review alone to catch the next one: add a CI grep gate flagging
any line matching `??` and `?:` in the same expression (a simple regex
scan of the diff is enough — the point is a mandatory human sign-off
before merge, not a full parser) so every future instance gets the same
specific precedence check this one only got by luck. One PHPStan
narrowing gotcha worth
recognizing on sight rather than fighting blind: it only re-widens a
previously-narrowed `$this` property after a method call made *directly*
on `$this` — if the same property instead "escapes" by being wrapped
into a newly-constructed object passed as an argument to another call
(even a call that itself indirectly mutates it through a reference), the
analyzer won't re-examine the property afterward and keeps treating it
as its pre-call narrowed type, producing a false "always false"/"dead
code" error. The fix is moving the call into its own method invoked via
`$this->`, not suppressing the finding. When a PHPStan version bump
starts flagging previously-invisible "dead" fallback branches or sentinel
values across many files at once, verify what the tool's inferred type
actually traces back to before deleting what it names as dead — a prior
attempt found several of these were correct, provable dead code, but a
few traced back to a **docblock-only** type annotation (`@var string`,
no native type) that was itself simply inaccurate relative to the real,
broader contract; PHPStan trusted the wrong docblock and correctly (by
its own logic) declared real, load-bearing code unreachable. Blindly
deleting what the tool names shipped two real regressions this way,
caught only by the full test suite, not by a second clean PHPStan pass.
The correct fix in that case is adding the missing *native* type at the
real source of the value, not removing the code the wrong annotation
made look dead. Prefer a named class over an anonymous class
(`new class extends ... {}`) for anything carrying its own array-shape
`@var`/`@param` docblocks that later static analysis depends on — a
real, confirmed gap: an anonymous class's own property docblocks type
correctly when that one file is analyzed in isolation, but silently lose
their type entirely (falling back to bare `mixed`, with every downstream
read then flagged too) once analyzed as part of a full-project/
parallel-worker run, reproducible even after clearing the analyzer's
result cache. A named class has a stable identity across worker
processes that an anonymous one doesn't; extracting to a small named
class sidesteps the gap entirely rather than fighting it. Always verify
a suspected tool limitation like this against a full-project run
specifically, not just the one file being edited — the isolated-file
result can look completely clean and still hide a real gap that only
the full/CI-equivalent run surfaces. **Scope PHPStan's `tmpDir` (and any
similar analyzer scratch/result-cache directory) to something unique per
checkout, never the tool's own machine-wide default** — its default
lives under the OS-wide temp root, shared by every local checkout of the
same repo on one machine; a dead-code-detector-style extension whose own
cache keys purely by content hash and garbage-collects entries its own
process didn't just read will race between two checkouts analyzing
overlapping content concurrently (a "cache file not found for hash ..."
symptom). Point it at a path that embeds the current working directory,
but keep it genuinely outside the repo tree — a first, reasonable-looking
attempt at a repo-relative scratch directory can make a whole-tree file
count explode by tens of thousands of tiny cache files, corrupting any
other tool (a linter, a formatter) that walks the same tree without an
explicit exclude for it.

**PHPStan definition-of-done for this phase, stated explicitly rather
than left to be inferred from the final verification gate list**: target
level 10 from day one — the same level the project commits to
permanently (see the Verification section's `composer analyse:phpstan`
gate) — not a lower level ratcheted up later, since re-raising the level
later is exactly the kind of "regenerate the baseline and hope" move
this phase's own PHPStan gotchas above warn against. Because P5 applies
PHPStan "file-by-file across the legacy tree," generate one
`phpstan-baseline.neon` at the start of the phase covering every
pre-existing violation, and enforce **baseline-shrink-only**: CI
regenerates the baseline on every run and fails if its entry count grows
versus the committed one, so a new violation in an already-migrated file
can never quietly join the noise of the legacy baseline. As each file
gets its own PHPStan pass, remove its entries from the baseline by hand
rather than waiting for a bulk regeneration to prune them — a bulk
regenerate-and-commit at the end would silently accept whatever's left
as permanent, defeating the shrink-only guarantee. The `phpstan` CI job
blocks from the start (baseline growth is the failure condition, not
"any error at all," so blocking doesn't require the legacy tree to be
clean yet). P5 is not done until the baseline file is empty and deleted
outright — a lingering non-empty baseline at phase end is scope that
leaked into P6, not a P5 loose end to carry forward silently.

**The 8.4/8.5 feature-adoption catalog below is explicitly backlog, not
P5-blocking scope**: P5's own job is the Composer/Rector/PHPStan
pipeline and vendored-library replacement, not an exhaustive per-call-site
audit of every new-in-8.4/8.5 function against the whole codebase. Track
each unaudited catalog entry as its own follow-up item, picked up
opportunistically whenever a later phase's own work already touches a
file with a real candidate (a domain-tier migration phase editing a file
with a `foreach`+`break` pattern is a natural point to swap in
`array_find()`, for instance) rather than as a dedicated sweep phase of
its own.

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

**Property hooks + asymmetric visibility (8.4) — adopt for config-like
classes from the start**, not as a later refactor: declare every key as
`public private(set) TYPE $name` on any class with a large flat set of
typed properties (a config class this shape collapsed from 5225 to 2626
lines last time, real boilerplate removed, not just reformatting). Route
any external write path through `ReflectionProperty::setValue()` against
the asymmetric-visibility property rather than inventing a setter per
field. Four concrete, verified property-hook gotchas to expect, all
found the hard way rather than in documentation:

1. PHPStan checks an *external* assignment (`$obj->prop = $x`) against
   the property's own **declared type** (including any `@var` docblock
   refinement) — not the `set` hook's own parameter type. If the
   property's `@var` is more refined than the hook's parameter type, a
   real, correct external write gets falsely flagged as a type mismatch.
   Fix by putting an explicit `@param` directly on the `set` hook
   matching the property's own refined type, not by loosening either
   type.
2. Inside a `set` hook's own body, PHPStan back-narrows a nested
   callback's (e.g. an `array_map()` sanitizer's) defensive
   `is_scalar()`/cast checks to "always true," based on the *property's*
   declared type rather than the genuinely untrusted raw input the hook
   exists to sanitize — a real false-positive, not a style issue. Extract
   the sanitizing logic into a separate method outside the hook body to
   keep PHPStan from over-narrowing it.
3. `ReflectionProperty::setValue()` on a hooked property **invokes the
   property's own `set` hook** — the hook *is* the property's real write
   path now, not a bypassable side channel the way a plain property once
   was. A test relying on raw reflection to construct a specific
   edge-case state (e.g. an empty array a sanitizing hook would normally
   reject/reset) gets silently "helpfully" normalized instead, defeating
   the scenario. `ReflectionProperty::setRawValue()` (PHP 8.4+) writes
   the real backing storage directly, bypassing the hook — the actual
   test-construction escape hatch. **The same root cause recurs in P14**:
   Doctrine's own entity hydration also writes properties via
   `ReflectionProperty::setValue()`, so any entity property that's both
   Doctrine-mapped *and* declared with a `set` hook gets that hook invoked
   on every single row hydrated from a query — expect and design for this
   explicitly when P14 lands hydration, rather than rediscovering the
   same mechanism as a second, unrelated-feeling surprise.
4. Any Reflection-based enumeration that filters properties by a specific
   **visibility modifier** (`getProperties(ReflectionProperty::IS_PRIVATE)`,
   written for a "private property + public getter/setter method" design)
   silently stops matching almost everything the moment properties convert
   to `public`/`public private(set)` with hooks — no error, just a
   shrinking, wrong result set. Audit every such Reflection-based
   enumeration (a config dump/reset/serialization helper is the classic
   case) whenever a class's property-declaration *style* changes, not
   just when a specific property's type changes.

**Open question resolved: drop `mobiledetect/mobiledetectlib`, no
User-Agent parsing anywhere.** Investigated directly rather than left to
guesswork: neither prior attempt actually ships a distinct mobile theme
(no `themes/mobile` in this line, none in the reference `16.x-rewrite`
either) — `mobile_theme` is a schema-typed config key that defaults to
`''` (off) and only ever matters if an admin explicitly installs and
selects a *third-party* mobile theme, at which point it's purely an
auto-switch convenience on top of the always-available `?mobile=1`
manual toggle (the footer's "View in Mobile" link is the actual primary
affordance either way). The reference's `Http\DeviceDetectionService` +
`mobiledetectlib` is real, working code — but it exists to serve an
opt-in feature with no core-shipped consumer, that this document's own
"native-platform-first library policy" (`docs/REFERENCE.md`) already
leans against keeping. Since P52 commits the rewrite to one fully
responsive default theme via `@container` queries, there is no design
gap for UA-based detection to fill. Decision: no vendored UA-sniffing
library; `getDevice()` always reports `'desktop'` from a single writer,
`mobileTheme()` still honors an explicit `?mobile=1`/`?mobile=0`
override (and the footer toggle link) independent of that default, and
an admin who installs a third-party mobile theme gets manual-only
switching, not automatic UA detection. Record this in `docs/REFERENCE.md`
once implemented so it reads as a decision, not silently-dropped
behavior.

**P6 — PSR-4 namespace migration.** Extract every first-party class and
interface declaration out of `include/` and `admin/include/` procedural
files into `src/Piwigo/` under a `Piwigo\` prefix. **Keep this pass
extraction-and-namespacing plus naming/folder cleanup only** — no DI, no
other behavior changes beyond what the move and the renames below force;
scope creep beyond that just makes the diff harder to review for zero
behavior change. **Free functions and constants left behind in
`include/`/`admin/include/` after class/interface extraction are not
this phase's concern — they're retired later, in P23 (Legacy deletion &
cleanup), once every real consumer has been retargeted onto a
class-based replacement.** P6 itself only moves declarations; leaving
procedural glue in place around them (still `require`d from the same
bootstrap seam) is correct, not a half-finished state, since P23 is
explicitly the phase that retires `$GLOBALS`/free-function bridges and
enforces zero remaining bare legacy calls.

**Rename every extracted identifier to its final target-convention name
in this same phase, in one consolidated pass — don't defer it to each
class's own later domain phase.** This reverses an earlier version of
this plan's own "extraction-and-namespacing only, no renaming" scoping —
worth stating explicitly, since that framing was deliberate the first
time and is being deliberately overridden here, not silently dropped.
The reason: a pure naming-convention rename (`get_moment()` →
`getMoment()`, `$category_id` → `$categoryId`) is a mechanical,
behavior-preserving rewrite that never gets invalidated by a later
phase's real redesign — unlike a shape/behavior change, the *name*
chosen here stays correct no matter how much P17-23 later reworks a
class's actual implementation. Doing it now, tree-wide, in one pass
gives every phase from P7 onward a clean, fully consistent naming
baseline to build against, instead of 50+ phases of code where some
identifiers are already camelCase and some still carry legacy
`snake_case` — real, confirmed confusion risk, not a hypothetical one
(see the naming targets and upstream evidence in P1 and below). Covers:
method/function/property/parameter/local-variable names (target
PSR-1/PSR-12 `camelCase`), and — in the same pass — every legacy
`Pwg`/`PWG` class-name prefix (`PwgImage`→`ImageBackend`,
`PwgSession`→`SessionHandler`, `PwgError`→`WsErrorResponse`, the
`PwgCategories`/`PwgImages`/`PwgUsers`/… WS-method-family classes, the
WS encoding/writer classes, `Base32`/`Totp`, `TemplateAdapter`, event
classes) and every `Pwg`/`PWG`-prefixed constant, since the prefix is
redundant the instant its class/constant lands under the `Piwigo\`
namespace — no reason to carry it forward. **This is grounded in a real,
confirmed cost of getting this wrong, not a hypothetical:** a prior
attempt deferred exactly this prefix removal to each class's own later
domain phase and needed a dedicated separate 9-commit cleanup campaign
across 20+ classes afterward to actually drop it — pure waste that a
single upfront pass avoids entirely. **Finalize the per-domain folder
structure under `src/Piwigo/` in this same pass too**, matching the
Deptrac layer model below (one subfolder per real domain namespace,
final shape from the first commit) rather than placing files
provisionally and reshuffling them again once P17-23 splits domains out
for real — the folder move is already happening this phase regardless
(`include/` → `src/Piwigo/`), so getting the final per-domain shape
right here is the same "don't do the move twice" reasoning as the
rename above. Verify the whole pass with the full existing test suite
plus a full VR run showing zero behavior/pixel diff — this phase is
pure rename/reshuffle, and the test suite proving that is the actual
acceptance criterion, not "the diff looks like a rename."

Establish the Deptrac layer model in this same phase, enumerated
per-namespace rather than by catch-all regex, so any later phase adding
a namespace must deliberately choose its layer. **Start with this
concrete five-layer skeleton** (a reasonable default worth revisiting
once real namespaces exist to test it against, not a final answer):
`L0Data` (Doctrine entities/repositories, raw DBAL access), `L1Integration`
(external-system gateways — filesystem, mail, HTTP clients, cache/session
backends, third-party APIs), `L2CoreDomain` (core business services:
categories, images, users, permissions), `L3ExtendedDomain` (optional/
extended domain services: plugins, comments, ratings, notifications),
`L4Presentation` (controllers, PSR-15 middleware, CLI commands,
templating). Allow dependency only rightward along that list — each
layer may depend on itself and any layer named after it, never on one
named before it — matching the "core/extended domain split" and the
presentation/integration-layer vocabulary P30 later re-verifies this
same ruleset against. **Known tool gotcha**: at least Deptrac 4.6.2
silently breaks ruleset resolution when a layer name contains a hyphen —
name layers without hyphens from the start (e.g. `L0Data`, not
`L0-Data`, matching the names above) to avoid a confusing false-violation
debugging detour.

**Make a mandatory "dynamic-reference sweep" part of every single
extraction batch, not an occasional afterthought — a prior attempt found
a real hit on nearly every batch.** Any bare/string-based reference to a
class resolves from the *global* namespace regardless of `use` imports,
and silently breaks the moment that class moves into `Piwigo\`: (1)
self-referential callable arrays like `['ClassName', 'method']` — convert
to `[self::class, 'method']`; (2) a leading-backslash native type
declaration or docblock (`\PwgError`, `\Template`) — strip the backslash
once a `use` import exists, or repoint it to the real FQN; (3) dynamic
string-based instantiation (`new $classname()`, `class_exists($name)`)
built from a bare class-name string — needs `Foo::class` or an explicit
`match()`, since a bare string never resolves through `use` imports;
(4) `is_a()`/`is_subclass_of()`/`ReflectionClass()` given a bare string
class name instead of an object or a `::class` constant — same global-
namespace resolution rule, same silent break; (5) test-suite mock
builders and data providers that reference a class by string (a
`@dataProvider`-style array literal, a mocking library's own
`->createMock('ClassName')`-by-string call) — these live in `tests/`, not
`include/`, so a sweep scoped only to production code misses them, and a
broken one fails as a confusing "class not found" from inside the test
runner rather than the application itself. Separately, watch for
**filesystem case-sensitivity mismatches**: a class moved into `Piwigo\`
with its namespace segments not exactly matching the directory casing on
disk resolves fine on a case-insensitive dev filesystem (macOS default,
some WSL setups) and fails only on a case-sensitive one (Linux CI,
production) — verify the extraction batch's own file paths against a
case-sensitive check (or just run the batch's own tests inside the same
case-sensitive CI environment) rather than trusting a local pass. Also
check every class for **`serialize()` exposure**: PHP embeds a
class's fully-qualified name as a literal string in every value it has
ever serialized, so moving a class that's ever been persisted (a DB
config blob, cached derivative-params, session data) silently breaks
`unserialize()` for old data — it doesn't error, it produces a
`__PHP_Incomplete_Class` stub that fails every `instanceof` check
downstream with no obvious error at the point of failure. Fix the
project's own committed test fixture with a one-time `class_alias()`-
bridged reserialize script (never a permanent compat shim), and flag any
class with real *production* persisted data as a genuine one-time
data-migration item, tracked separately from the extraction itself.
Finally, watch for a blind find-and-replace fixing case (2) also
corrupting the file's own freshly-added `use` statement if the replaced
substring appears inside it — re-check `use` lines specifically after any
such scripted fix.

**Run `composer dump-autoload --optimize --classmap-authoritative` as an
explicit step of every extraction batch, not just at the very end.**
PSR-4's own fallback resolution means a missing/misplaced class doesn't
necessarily error immediately without it, but `--classmap-authoritative`
makes the autoloader refuse to fall back to filesystem probing at all —
turning a real PSR-4/namespace-path mismatch into an immediate, loud
autoload failure at the point it's introduced, rather than a silent
success that only breaks once the classmap goes stale in some other
context (a production deploy that runs the optimized dump, a case-
sensitivity mismatch case (5) above wouldn't otherwise surface locally).

**Phase-completion check**: a repo-wide grep for zero remaining
`class`/`interface`/`trait` declarations inside `include/` and
`admin/include/` (excluding test fixtures deliberately kept in the
legacy shape), combined with a full green test-suite run — both required
together, since the grep alone doesn't prove the extracted classes still
behave identically and the test suite alone doesn't prove the sweep was
exhaustive.

### Epoch C — Kernel & HTTP foundation (P7–P12)

**Watch for the "reference is end-state, not per-phase" trap on every
phase from here through the rest of the replay.** `16.x-rewrite`'s
current file content shows the fully-evolved *final* shape of a file, not
what it looked like when the phase now being replayed actually landed it.
Building straight to match the reference's current shape means writing
code against classes/config keys/domain types that belong to *later*
phases and don't exist yet on this branch. Read the reference file
directly, identify exactly which of its pieces this phase's own
already-landed dependencies can actually support, and build a genuinely
narrower/emptier version — a container with zero definitions, a route
table with zero routes, a state object with zero typed slots — with an
explicit "grows one entry at a time, only when a later phase actually
needs it" note, rather than padding ahead of need to look finished.
**The concrete mechanism for reconstructing which of a reference file's
pieces actually belong to the phase being replayed**: `16.x-rewrite`'s
own commit messages carry a `(pNN)` conventional-commit scope tag on the
commit that first landed that phase's real feature work (e.g. `feat(p7):
kernel + boot skeleton`), distinct from later `docs(plan)`/`refactor`/
`fix` commits that merely mention the phase number in passing. Run `git
log --all -i --grep='^feat(pN)'` (substituting the real phase number)
against the reference checkout to find that landing commit directly, and
diff the file at that commit against its current tip to see exactly what
later phases added on top — a far more reliable signal than guessing
from the file's current, fully-evolved shape alone. Related: don't trust
a phase's own doc-prose scope claim either — cross-
check what the reference implementation *actually* built (its real
composer.json, its real file tree) before assuming a tool/technique the
prose describes at length is real; this project's own prose promised more
than the reference delivered at least three separate times (an
observability library, a benchmark suite, a code generator). When the
doc's stated scope and the reference's simpler, already-built path
genuinely diverge on something the doc explicitly commits to, raise it
instead of silently taking the reference's smaller retreat — silently
following the reference would override a real, stated commitment, which
is a different kind of call than filling an ordinary gap.

**P7 — Kernel + boot skeleton.** `Kernel`, `CommonBootstrap`,
`public/index.php`, fast paths. **Define "fast paths" concretely**: a
static-asset bypass (a request matching a known static file extension
under `public/` is served directly by the webserver/FrankenPHP's own
static-file handling, never reaching `Kernel::boot()` at all) and a
maintenance-mode short-circuit (checked before the rest of bootstrap
runs, returning a 503 immediately rather than paying for DB/config/
session setup on every request while the site is down). Anything that
needs DB, config, session, or the DI container is by definition not a
fast path — those all go through the full boot sequence.

**Open question resolved: defer true worker-mode execution to P23, name
it explicitly rather than repeating the prior silent-defer failure.**
Every prior attempt reached the same practical state — FrankenPHP as the
runtime, classic per-request execution only, worker mode never actually
flipped on (`docker/Caddyfile` still uses plain `php_server`,
per `docs/REFERENCE.md`) — because turning it on before legacy deletion
means resetting state across a bootstrap chain that's still half
`include/`-based, an unstable target to build `reset()` coverage against.
P23 (Legacy deletion & cleanup) is exactly where the bootstrap chain
reaches its final DI-only shape, and it's the same phase a prior attempt
already intended to pick worker mode back up in — the actual failure was
never revisiting it once P23 arrived, not that P23 was the wrong target.
Decision: P7 builds every piece of worker-mode *readiness* (this
section's `reset()` coverage and `define()` guard, below) without
flipping worker mode on; **P23 is where true worker-mode execution
actually lands**, as one of that phase's own explicit pre-phase
decisions (cross-referenced there) — not an open question deferred a
second time. Keep every
service's `reset()` method arch-test-covered from the start (worker mode
needs every request-scoped service reset between requests; retrofitting
that coverage later is real, avoidable work). **Also sweep for raw,
unguarded `define()` calls before worker-mode goes live**: PHP's
`define()` doesn't throw on redefinition, it emits a silent `E_WARNING`
and returns `false` — a constant defined a second time within the same OS
process (a second request hitting one persistent worker) silently no-ops
instead of erroring, a real landmine that "worked" under classic
per-request execution. The safe idiom is `defined('X') or define('X',
...)` everywhere, not just at the couple of sites that happen to get
flagged. Turn this into a CI gate rather than a one-time manual sweep: a
grep/regex check (or a small custom ECS/PHPStan rule, since both already
walk the AST) flagging any `define(` call not immediately wrapped in
`defined(...) or` — cheap, mechanical, and catches every new call site a
later phase adds, not just the ones caught during this phase's own
audit. **Also audit every `register_*_handler()`-style registration
(`session_set_save_handler()`, a shutdown function, any callback that
persists for the rest of the OS process) for a dependency captured once
at construction time instead of resolved lazily on each call** — a
session handler that captured a singleton service in its own constructor
froze onto whichever backing resolved at *registration* time, invisible
in normal one-request-per-process execution (only one resolution ever
happens) but a real correctness bug the moment anything shares a process
across requests (a shared test-runner process, or true FrankenPHP worker
mode once it lands). Resolve inside the handler method itself, not the
constructor. A related but distinct shape: a "run this only once per
object lifetime" memoization guard around a block of setup code is
correct only for the parts of that block that are genuinely
identity-scoped one-time registration (event-handler wiring, a
non-idempotent internal state push) — if the same guarded block also
computes something that's really *per-request* state (a response format
selected from the current request's own headers/params), wrapping it in
the same one-time guard means a long-lived object reused across requests
under worker mode permanently serves the *first* request's computed
value to every later request on that same instance. Split the block:
keep the genuinely-one-time parts inside the guard, move anything that
must reflect the current request outside it so it recomputes on every
call regardless of whether the underlying object is freshly built or
reused. Separately: `session_set_save_handler()` itself registers
process-global PHP state that outlives `session_write_close()` — closing
a session does not un-register the custom handler that was serving it,
which stays "the current handler" for every later `session_start()` in
the same OS process until something explicitly re-registers a different
one. A long-lived test-runner process (or worker mode) that ever
installs a custom handler bound to a disposable resource (a throwaway
per-test database connection) must explicitly re-register PHP's own
built-in handler afterward, not just close the session — otherwise every
later session in that same process silently reuses the leftover handler,
bound to a resource that may already be gone.

**Worker-mode readiness doesn't stop at `define()`/handler-capture/
statics — three more sources of cross-request leakage worth designing
around explicitly, even if true worker mode itself is deferred:** (1)
**output-buffering leakage** — any `ob_start()` without a matching
`ob_end_clean()`/`ob_end_flush()` on every exit path (including a thrown
exception) leaves a stray buffer open into the next request on the same
persistent worker, corrupting or swallowing that request's own output;
audit every `ob_start()` call site for a `finally` block that always
closes it. (2) **request-scoped in-memory caches** — any static/instance
property memoizing a per-request computation (a resolved permission set,
a parsed config value) that isn't cleared by the same `reset()` sweep
already planned for services must be identified now, before worker mode
lands, since it's the same class of bug as the singleton statics P8
addresses, just living inside an otherwise-correctly-DI'd service rather
than as a bare static. (3) **interaction with P12's SIGTERM/
`ShutdownHandler`** — that's P12's own concern, not this phase's: P7
decides *whether and when* worker-mode execution lands at all; P12 is
where the *shutdown* half of the worker lifecycle (what happens to an
in-flight request when the process receives SIGTERM) actually gets
built. Don't let either phase assume the other one covers it.

**P7 acceptance criteria**: a concrete double-dispatch test — boot one
`Kernel` instance, dispatch two distinct requests through it in sequence
without rebuilding the kernel between them, and assert no request-scoped
state (session data, a memoized per-request value, an output buffer)
from the first leaks into the second. This is the cheapest real
worker-mode-readiness signal available this early, well before true
worker mode itself lands, and it's exactly the kind of gap items (1) and
(2) above only surface under.

**P8 — DI container.** `Container`, `config/container.php`, PHP-DI
autowire-by-default.

**Resolve two PHP-DI configuration questions explicitly in this phase,
not by discovering a runtime failure once real classes exist to trip
over them.** (1) *Circular constructor dependencies*: autowiring cannot
construct two classes that each require the other through their
constructors — it throws at resolution time, not at analysis time, and
constructor DI everywhere (this phase's own commitment, below) makes
this a real risk the moment two services genuinely need each other.
Prefer breaking the cycle by extracting the shared behavior both sides
need into a third class they each depend on instead; where that's
genuinely not possible, setter/method injection on one side of the
cycle is the container-native escape hatch — treat it as exactly the
kind of tracked exception this phase's own shim-allowlist discipline
already covers below, not a one-off workaround exempt from it. (2)
*Ambiguous interface bindings*: PHP-DI autowires a **concrete class**
automatically by inspecting its constructor, but has no way to guess
which implementation to use for an **interface** with more than one real
implementation — every such interface needs an explicit binding entry in
`config/container.php` from the start, not a hope that autowiring will
pick the right one (it won't autowire an ambiguous interface at all; it
throws). **Open question resolved: enable `enableCompilation()` in
production, disabled in dev/test.** No prior attempt (this line or the
reference) ever actually enabled it — genuinely unexplored, not just
undecided — but PHP-DI's own documented guidance treats compiling the
container in production as close to a default recommendation (faster
resolution, some binding mistakes surfaced at compile time instead of
first-request runtime), with the standard env-gated pattern (`if
($env === 'prod') { $builder->enableCompilation(...); }`) avoiding the
downside (a stale compiled container silently outliving a dev-time
config edit) entirely outside production. Decide as part of this phase —
a compiled container resolves faster and surfaces some binding mistakes
at compile time rather than at first-request runtime — since P11 builds
`opcache.preload` on top of the same container shortly after this phase,
and getting the compiled-container class's own regeneration trigger
right here (it must be excluded from `opcache.preload`'s own file list
and rebuilt whenever `config/container.php` itself changes, never
preloaded once and silently left stale) avoids reworking P11's preload
list to accommodate it later.

**Commit to constructor-injected DI everywhere from this phase on —
don't let static-singleton/service-locator shims accumulate.** A prior
attempt needed a dedicated 74-commit, 10-phase campaign later to convert
~55 classes across three anti-pattern shapes (plus an entire
WS-layer-style static-dispatch namespace) because DI wasn't enforced
from day one. The real motivation isn't style: worker-mode request
isolation needs no process-persistent static state, and if FrankenPHP
worker mode is a committed direction (see P7), lingering statics are
directly incompatible with it, not just untidy. If a transitional shim
for an unconverted caller is genuinely unavoidable, tag it `@deprecated`
and track it via a shrinking arch-test allow-list with a hard
zero-shims-remain gate from the start — but the goal is not needing that
mechanism at all. **A shrinking allow-list test has a real blind spot:
it only fails on an unexpected *new* caller, never on a stale entry for a
caller that already stopped using the shim** — nothing forces its removal
once true, so a long campaign accumulates dead allow-list rows silently
alongside the real ones. Periodically audit entries against the actual
file content (grep the real caller), not just trust the test's green
status, or the allow-list quietly drifts wider than reality for the rest
of the campaign. If the campaign maintains more than one such allow-list
for related-but-distinct concerns (a per-shim-method list alongside a
broader architectural-boundary list, e.g. "the DI container may only be
resolved from the Bootstrap layer"), audit each one on its own explicit
cadence — a routine sweep of one doesn't cover the other, and a prior
attempt's own boundary-level list accumulated over a dozen stale entries
across multiple phases specifically because no sub-phase's own mechanical
procedure ever targeted it, only each narrower per-shim list got checked.
**When converting a class's methods from static to
instance as part of such a campaign, every remaining caller still using
the old `ClassName::method()` static-call syntax becomes an immediate
fatal PHP error the moment the conversion lands — not a deprecation
warning, not a lint finding.** Such a conversion must land in the same
commit as every one of its own callers' updates; there is no safe
partial/staged landing for a single class's own static-to-instance
switch, even though the wider multi-class campaign itself can and should
stay staged across many separate commits. If any such conversion
campaign does happen, search for construction sites both by plain
short name and fully-qualified
(`\Namespace\Class(...)`) form, and both static-only and instance-method-
based construction helpers — a purely static-pattern sweep misses both
variants. Also audit every isolated/unit-style test that constructs the
retargeted class directly (bypassing full application bootstrap): the
moment that class's construction starts resolving something through the
container, such a test needs its own `Kernel::boot()`/`reset()` added to
its own setup/teardown, or it fails the instant the retarget lands.
Before converting any static "current instance" singleton to a
container-shared instance, check whether its value genuinely varies
per-request at all — several of a prior attempt's own singletons turned
out to be fixed once and never mutated mid-request, needing only a plain
container binding with no "current instance" concept, `reset()`, or
shim at all. Conversely, don't default every such class to `readonly`
either: one real singleton was deliberately kept mutable in place,
because a documented past production bug showed some of its consumers
must see a mid-request update (a user-submitted config value seeded
partway through install) rather than a stale copy captured at
construction time — check for a legitimate mid-request-mutation
requirement before assuming immutable-by-default is safe. Any transitional
shim built with a "gracefully no-op when the container isn't booted yet"
fallback needs an audit of its own: a caller that writes a value and
immediately reads it back in the same method (`set()` then `get()`) breaks
silently once the no-op path is hit, since the write became a no-op but
the read still returns null — such callers need to read their own
just-computed local value directly, not round-trip through the shim.
And PHP-DI's `has()` returns `true` for *any* autowireable concrete class,
not just ones explicitly bound in `container.php` — an `if
($container->has(X::class))` guard doesn't prove X is really configured,
it can pass and then have the following `get()` throw a confusing
autowiring exception for a class with required constructor args; catch
`ContainerExceptionInterface` around a direct `get()` instead of
guarding with `has()` first. **A `var_export($this)`/`print_r($this)`-style
whole-object debug dump becomes a real memory-exhaustion risk once the
dumped class carries a real DI-injected dependency graph** — harmless
when a class held no collaborators, but once it holds even one real
service reference, dumping `$this` recursively walks that service's own
collaborators, and theirs, often reaching most of the application's hub
services; a prior attempt had exactly this fire from a malformed
request parameter reaching an error-diagnostic dump, exhausting the
request's memory limit on a path any external, unauthenticated client
could trigger — a real DoS vector, not just noisy logging. Never dump
`$this` wholesale for debug/error output on a class with real
collaborators; dump only the specific, shallow fields relevant to the
context.

**A concrete decision rubric for "should this stay a static/
`Kernel::container()`-resolved call, or become a real constructor
property," since the question recurs constantly and deserves a
consistent answer, not a per-class judgment call each time:** stays
static/lazily-resolved when (a) something outside this codebase's own
construction sites calls it — a raw PHP `include` needing a
PHPStan-visible access path with no object to call a method on, or an
external caller with no constructor of its own to inject into; or (b)
resolving it eagerly through the constructor would create a real
container dependency cycle. Converts to a real constructor property
when it resolves a genuine container-wide singleton with **no
re-resolve-to-observe-current-state requirement** — if every real
caller is fine with the value captured once, at construction, injecting
it costs nothing and removes a hidden container dependency from the
class's own public contract. **Even with this rubric applied
consistently from day one, budget one final, explicit, codebase-wide
sweep before calling this done, rather than trusting day-one discipline
alone to have caught every site — a real one, run late in a prior
attempt specifically to check whether the day-one policy actually held,
found 225 real `Kernel::container()`-outside-`config/container.php`
call sites across 38 files: the overwhelming majority already correct,
deliberate design by this same rubric, but a real, non-zero handful
were genuine conversions the day-one discipline alone had still let
through.** Re-verify every such count at the time this sweep actually
runs rather than trusting an earlier snapshot to still be accurate —
the call-site count only ever moves as new code lands.

**Never give a container-managed collaborator a nullable, defaulted
constructor parameter with an inline "build a fallback instance if not
provided" branch, as a substitute for real dependency injection.** This
looks harmless (production always resolves the class through the
container, which "should" supply the real value) but is a real, silent
trap: an autowiring DI container only ever populates a *required*
constructor parameter — an optional/nullable/defaulted one is never
autowired, ever, by design (the container has no way to know whether a
caller's own explicit default was intentional). So every real,
container-resolved construction in production permanently takes the
fallback branch, not the injected one, and that fallback typically
hand-rolls a *throwaway* instance of the dependency (its own fresh
`EntityManager`, its own fresh sub-service graph) instead of reusing the
container-shared one — defeating connection/instance sharing completely,
on every single call, invisibly, since the class still "looks" DI-based
and every test that constructs it directly with an explicit argument
never exercises the fallback at all. A prior attempt found this exact
shape independently in dozens of unrelated classes only via a dedicated,
long sweep specifically grepping for nullable constructor params with a
non-trivial default branch — it does not surface through code review,
type checking, or normal test coverage, since nothing about it is
type-incorrect or untested; it's a purely runtime waste-of-sharing bug
that a fresh non-shared instance still behaves identically to a shared
one for correctness purposes, just far more expensively (a fresh
`EntityManager` per call re-triggers platform auto-detection and a new
eager DB connection — see the reference-cyclic-GC note above). Audit for
this shape explicitly and early (a real grep pass over every
`?Type $x = null` / `Type $x = new ...` constructor parameter, checked
against whether that parameter's class is meant to be container-shared),
not opportunistically as each instance happens to get noticed. Make the
dependency a required constructor parameter (or, for a method needed by
only a handful of infrequently-used call sites relative to a class's
total real construction-site count, an explicit method parameter instead
of a constructor property) and thread it through every real caller —
never leave the nullable-with-fallback shape as a "works either way"
convenience.

**Build the core PSR-14 event dispatcher here too, as a container-registered
service, not at P34's later position — P34 (Epoch J) was where a prior
draft of this document built it, and that position is wrong.** Real
evidence, not a hypothetical: this document's own P23 (Epoch E) already
says "retarget event-dispatch... bridges onto real classes," and P29
(Epoch I) already designs its whole extension layer against a
`subscribedEvents()`-registered dispatcher — both need a real, working,
typed dispatcher years (in phase terms) before P34's own Epoch J
position, or they inherit/invent an interim mechanism that gets thrown
away once P34 finally lands. Build the actual core mechanics here, now:
one dispatch verb — `dispatch(object $event): object`, never a
change/notify split; a real standard dispatcher wrapped directly (e.g.
Symfony's), registered with closure-based subscriber registration,
settled outright here, not conditionally, since every later phase
(P17-23, P29) builds against this exact registration shape; typed event
objects always, zero string-keyed dispatch call sites, enforced by an
arch test from this phase on; mutable payload fields for anything a
handler needs to change (drop `readonly` on those specifically, keep
context-only fields `readonly`); no eager service construction for a
registered listener whose event never actually fires; slow listener work
always goes through P11's Messenger, never inline in the dispatch path;
and ship the spy/traceable dispatcher decorator (records every dispatched
event and which listeners ran) as a named test-support deliverable here,
since P17-23's own domain tests need it immediately, not once P34 gets
around to it. **P34 still exists, later, as the full legacy-hook-catalog
migration** — mapping all 162 real legacy hook points (the P29 survey's
own count) onto typed events one capability at a time, aligning listener
priority/exception-propagation with the conventions P29 settles for its
extension layer, and adding `StoppableEventInterface` where the survey
confirms real former filter-chain behavior — extending the dispatcher
built here, not building it from scratch a second time. See P34's own
text in Epoch J for that remaining scope; nothing there should ever again
read as inventing the dispatcher itself.

**P9 — PSR-15 middleware + routing.** **Design the full pipeline
composition deliberately in this phase, including `admin.php`, not just
`index.php`.** A prior attempt built a 7-stage pipeline here and only
discovered much later — via a dedicated remediation workstream — that
`admin.php` never actually routed through it at all, silently losing
DB/config/session/plugin/user/language bootstrap and every security
header once an earlier refactor stopped duplicating that setup directly
in `admin.php`. Route every real entry point through one shared
PSR-15 pipeline from this phase, verified with an integration test that
actually exercises `admin.php`, not just `index.php`. **Open question
resolved: match the reference — real PSR-15 middleware stages.** The
reference implementation's `config/container.php` and `Kernel::pipeline()`
already wire a working, validated order:
`SecurityHeaders → ExceptionHandler → Session → Auth → Filter → Csrf →
Routing → ControllerInvoker`. Adopt that shape and that order rather than
moving Auth/Filter/Csrf into services or controllers — a real
already-built precedent beats an untested alternative here, and keeping
them as pipeline stages is what makes the "route every entry point
through one shared pipeline" guarantee above actually enforceable by an
integration test, instead of relying on every controller remembering to
call three services itself. Build `CsrfService` with `sha256` HMAC +
`hash_equals()` from the start, not `md5`/`===` — and specifically
audit for **duplicated**
copies of the same security check living in more than one layer (a
prior attempt fixed this pattern in the main auth services, but an
independent copy in the WS/API layer kept the old `md5`/`===` version
for weeks after, undetected because it was a second, unrelated
implementation of the "same" check).

**Name the routing mechanism concretely, since the phase title promises
it and nothing else in this plan does**: `symfony/routing`'s
`RouteCollection`/`UrlMatcher`/`UrlGenerator`, wrapped in a thin project
`Router` that loads a `RouteCollection` from a plain `config/routes.php`
file (route names stay the single source of truth for both incoming
dispatch and outgoing URL generation) and returns a small `RouteResult`
value object with `FOUND`/`NOT_FOUND`/`METHOD_NOT_ALLOWED` states rather
than leaking Symfony's own matcher exceptions past the wrapper. Skip
route-matcher compilation/caching in this phase — a freshly-constructed
`UrlMatcher` per request is cheap at Piwigo's real route-table size, and
`CompiledUrlMatcherDumper` output is a pure performance optimization with
no behavior change, safe to defer to whenever profiling actually calls
for it rather than a P9 blocker. State the trailing-slash policy
per-route in `config/routes.php` itself (Symfony's own per-route
trailing-slash/exact-match options are the mechanism) rather than adding
a separate normalizing middleware that duplicates it. For 404/405: don't
add a catch-all route for custom error pages — it silently shadows a
real route's own 405 response, since a catch-all matches the path under
every method including the wrong one, so it wins the match before the
router ever gets a chance to notice a real route existed under a
*different* method (see P23's own routing/deployment-gotchas note for
the concrete regression test this needs). Let a `NOT_FOUND`/
`METHOD_NOT_ALLOWED` `RouteResult` reach a dedicated error-response stage
in the pipeline instead.

**Require an explicit, documented canonical middleware order as a P9
deliverable, written into the pipeline construction site itself — not
left implicit in whatever order factories happen to get registered in.**
Outermost to innermost: SecurityHeaders → ExceptionHandler → Session →
Auth → Filter → Csrf → Routing → ControllerInvoker (matching the
reference implementation). The ordering is load-bearing, not arbitrary:
security headers go first so every response — including one an inner
stage errors out of — carries them; ExceptionHandler wraps everything
below it so no lower-stage exception ever reaches the client unhandled;
Session precedes Auth since Auth reads the session; Filter (visibility/
permission scoping) precedes Csrf and Routing since both need to know
the resolved viewer; Routing runs last, immediately before the
controller invoker, so every earlier stage's decision (auth state, CSRF
verdict, filters) is already settled by the time a specific route's
controller actually runs.

**Design the `ExceptionHandler` stage to catch a short-circuit response
at every middleware nesting level, not just the innermost handler —
this is a P9-phase design decision, not a P23-phase bug to find
later.** A short-circuit thrown by an outer-ish middleware (not just
the one immediately wrapping the controller) must still be caught and
answered as the real intended response, never silently logged as an
unhandled error and answered with a generic 500 — which would also
lose the security/timing headers earlier stages already set. See P23's
own note on the concrete regression test this needs, and Epoch F's
`die()`/`exit()` guidance for the same short-circuit-response hazard
via a different mechanism.

**Specify the CSRF token's lifecycle explicitly rather than leaving scope,
rotation, and cookie attributes open questions**: tokens are derived
deterministically as an HMAC of the current session id (keyed by the
app secret), not stored server-side and not issued via their own cookie
— this is the stateless/deterministic variant of the synchronizer-token
pattern, not double-submit-cookie, and the token only ever travels as a
hidden form field / request parameter. That construction resolves the
open questions by itself: the token is implicitly session-scoped (a new
session id after login/logout produces a different token, so rotation
piggybacks entirely on session-id regeneration — see SEC-25/P18 for that
policy — with no separate rotation trigger to design here), and there's
no separate CSRF cookie to set `SameSite`/`Secure`/`HttpOnly` on; those
attributes belong to the session cookie itself (P11's session-handler
design). Don't add a second, cookie-based CSRF mechanism alongside this
one — it would be redundant defense with its own attribute surface to
get wrong, for no real benefit over the HMAC approach already chosen.

**A known, accepted UX tradeoff of session-scoped CSRF tokens generally,
not a defect of this specific HMAC construction: a second tab open to a
stale form invalidates the moment SEC-25 regenerates the session id in a
different tab (login, logout, privilege change).** Any session-id-tied
CSRF scheme has this shape — a server-side-stored token list would only
avoid it by keeping multiple valid tokens alive at once, adding real
storage/complexity for a rare edge case. Don't build that; instead make
the failure legible: a CSRF mismatch caused by a genuine session
regeneration (not a real attack) should render a specific "your session
changed in another tab, please retry" message tied to CSRF-failure
detection, not a bare 403 indistinguishable from an actual forgery
attempt.

**Pipeline-level rate limiting and request-body-size limits are
explicitly out of scope for this phase too** — the same `rate_limiter`
deferral P11 states for its own cache pool applies here: P9 builds the
pipeline's shape, P28 builds its later cross-cutting policies (SEC-44).
Don't build a placeholder rate-limiting middleware stage now just
because the canonical order above has room for one.

**PSR-7 request objects and legacy superglobal-reading code coexist
safely by construction, with no bridging layer needed**: the PSR-7
`ServerRequestInterface` this pipeline builds (via `nyholm/psr7-server`'s
`ServerRequestCreator::fromGlobals()` or equivalent) is built by *reading*
`$_GET`/`$_POST`/`$_SERVER`/etc. once at the edge, not by consuming or
clearing them — every legacy `include/`/`admin/include/` file still
reading superglobals directly (until P23 retires them) sees the exact
same underlying request data the PSR-7 object was built from. The two
representations can drift only if something mutates the superglobals
*after* the PSR-7 object is built and *before* legacy code runs later in
the same request — audit for that specific ordering hazard if it ever
comes up, rather than assuming coexistence is unconditionally safe.

**A DI container factory can resolve eagerly, before the per-request
middleware chain has actually started executing in order — never wire
logic that depends on state a specific middleware sets into a container
factory reached through a *different* service's constructor injection.**
A pipeline runner that eagerly builds every registered middleware
instance up front (e.g. via an `array_map()` over the whole middleware
list before any middleware's own `process()` runs) means any container
factory in that eager construction chain runs at pipeline-build time,
not at the correct point in per-request dispatch — a factory that reads
request-scoped state a specific middleware is supposed to have already
set (an authenticated user, a resolved plugin registry) will read it too
early, before that middleware ever ran, and throw or silently use stale
state. This is easy to get wrong by routing the dependent logic through
a container factory for convenience instead of putting it directly in
the one middleware's own `process()` method that actually runs at the
right time — and it's invisible to static analysis and can pass a
narrowly-scoped test while still breaking on every real request; only an
Integration-level test that boots the full real pipeline catches it.

**P10 — Observability.** Monolog channels, Server-Timing,
OpenTelemetry-first (OTLP → Sentry/Tempo/Jaeger). Greenfield. **This
phase is thinner than its neighbors in the reference implementation
itself, not just in this document's prose** — it's one of the areas the
Epoch C preamble above already flags as prose promising more than the
reference actually delivered. Per that preamble's own instruction, raise
the gap and design it for real rather than silently retreating to match:

- **Log redaction**: build a minimal deny-list Monolog processor in this
  phase as an interim measure — a fixed list of field names (`password`,
  `secret_key`, `api_key`, `token`, `authorization`, `pwg_token`, session
  cookie values) redacted from every log record's context array before it
  reaches any handler, regardless of channel. This closes the real
  P10–P12 window that would otherwise exist with full logging live but no
  structural redaction at all, three phases before P13's `#[Sensitive]`
  mechanism — and P13's mechanism doesn't actually supersede this one
  when it lands, since `#[Sensitive]` governs config-property redaction
  specifically (enforced at config boot and in the error handler), not
  arbitrary log-context redaction; keep both.
- **Correlation/trace-ID propagation**: generate (or extract, if a
  reverse proxy already sets one) a request-scoped trace id as early as
  possible in the P9 pipeline — the `SecurityHeaders` stage, the
  outermost one, is the natural place, since every later stage and every
  log line for the request should carry it. Inject it into every Monolog
  record via a processor reading it off the current request attribute,
  and attach it as the root OTLP span's own trace id (not a separate
  custom attribute) so a log line and a trace for the same request are
  correlated by construction, not by a second lookup.
- **Sampling, retention, and OTLP export cost**: don't default to
  100%-sample-everything in production. A reasonable default worth
  revisiting once real traffic volume exists: sample all traces at a low
  fixed rate (e.g. 10%) plus 100% of any trace containing an error or
  exceeding a latency threshold (a `ParentBased` + `TraceIdRatioBased`
  sampler composed with an always-on override for error spans is the
  standard OpenTelemetry SDK shape for this). Retention and OTLP-volume
  cost policy belong to whichever backend receives the export (Tempo/
  Jaeger/Sentry's own retention settings), not to application code — but
  the sampling *rate* is an application-level decision this phase must
  make explicitly, since it directly trades observability completeness
  against export volume/cost.
- **Acceptance criteria**: a real integration test that boots the full
  pipeline, dispatches one request through a route deliberately
  instrumented with a few nested spans, and asserts (a) one OTLP trace
  exists with the expected span count and structure, and (b) at least one
  Monolog log line emitted during that request carries the same trace id
  as the root span. This is the only sound way to verify propagation
  actually works end-to-end rather than trusting that Monolog channels,
  Server-Timing headers, and OTLP export were each wired correctly in
  isolation.

**P11 — Cache + session + messenger + `opcache.preload`.** Build
`symfony/cache` pools, session handler, Messenger, preload list. **Design
the named-pool structure in this phase, not as a later remediation** —
distinct pools for config, permissions, category tree, tag cloud, rate
limiting, and general use, each with its own TTL. A prior attempt shipped
one generic pool with no real consumers first, needing a dedicated
follow-up to actually wire named pools in. `rate_limiter` is real P28
scope, not a gap — leave it unbuilt here on purpose. **The permissions
pool specifically needs event-driven invalidation-on-write, not TTL-only**
— a TTL-only permissions cache means every group-membership change,
category-permission edit, or user ban leaves a real
still-has-access-after-revocation window open for up to the pool's own
TTL, which is a genuine security gap for a cache pool named
"permissions," not just a staleness inconvenience. Every write path that
mutates permission-affecting state must synchronously delete the
specific affected cache key(s) in the same request that made the change,
not rely on the TTL to catch up eventually; require an integration test
per such write path asserting the cache entry is actually gone
immediately after the write, not just that the write itself succeeded.

**Specify the session handler's storage backend and cross-reference P7's
own worker-mode gotcha directly, since this is the phase that actually
calls `session_set_save_handler()`.** Storage backend: a DB-backed
`SessionHandlerInterface` implementation (matching the reference
implementation's own `SessionService`/`SessionRepository` shape) reusing
the same database rather than a separate session store — consistent with
this phase's broader "don't stand up new infrastructure where the
existing DB already does the job" posture for Messenger's transports,
below. Re-apply P7's own construction-time-vs-lazy-resolution gotcha here
specifically: build the handler to resolve its own dependencies inside
each interface method call, never capture them in the constructor, since
this phase is exactly where that gotcha stops being theoretical. Session
fixation/regeneration-on-privilege-escalation policy is intentionally
**not** this phase's own scope — that's SEC-25, tracked against P18, once
real login/auth domain logic exists to trigger a regeneration from; P11
only needs to build storage that a later `session_regenerate_id()` call
works correctly against (i.e., a handler with no assumptions baked in
that the session id never changes mid-request).

**State an explicit idempotency requirement for every Messenger handler
class from this phase, especially `SendNotificationEmailJob`.** Route
Messenger through Doctrine-DB-backed transports (a plain `async` queue
plus a dedicated `failed` transport, reusing the existing database rather
than standing up a separate broker — a deliberate simplicity choice,
revisit only if real throughput ever demands it) with the framework's
default retry policy left in place. That default retry policy is
at-least-once delivery by construction: a handler that isn't idempotent
(unconditionally sends, unconditionally writes) can double-send/
double-write on a retry triggered by a failure *after* the real side
effect already completed, not just a failure before it. `GenerateDerivativeJob`/
`RegenerateAllDerivativesJob`/`ReindexImagesJob` are idempotent by nature
(pure recomputation — a retry just redoes work, it doesn't corrupt
anything), but `SendNotificationEmailJob` is not, and needs an explicit
decision recorded, not left implicit: either a dedup key checked before
sending, or an accepted product decision that an occasional duplicate
notification email is tolerable. Don't leave it undecided by default.

**Build failed-job visibility into Messenger as part of this phase, not
a later gap to close**: nothing querying the transport table means a failed
notification-email, derivative-generation, batch-upload, or reindex job
is invisible and unretryable/unpurgeable. Build the repository plus a
small admin dashboard view for it now — and report every transition into
the `failed` transport as a real Sentry/OTLP event through P10's own
pipeline as part of this same phase, not only as a dashboard row nobody
is watching; a silently-full failed-job queue with no alerting is the
same blind spot as building no visibility at all.

**Define the `opcache.preload` list-maintenance mechanism explicitly, and
its interaction with FrankenPHP worker-process lifetime.** A reasonable
default worth revisiting once real measurements exist: auto-generate the
list from the Composer classmap (`vendor/composer/autoload_classmap.php`,
filtered to `Piwigo\`-namespaced classes) plus a fixed allowlist of
hot-path vendor classes (Doctrine ORM/DBAL core, the PSR-7 message
implementation, the compiled DI container class from P8), regenerated by
a `bin/piwigo` command tied into the same deploy step that already
restarts FrankenPHP workers — never hand-maintained, since a
hand-maintained list silently drifts as classes get added and removed.
The worker-lifetime interaction: `opcache.preload` only runs once per PHP
process start, so a long-lived FrankenPHP worker amortizes the preload
cost across every request that process ever serves (a real win worker
mode gets that classic per-request execution doesn't), but it also means
a preload-list change only takes effect on the *next* worker restart, not
on the next deploy of changed files alone — regenerating the list without
also triggering a worker restart silently serves the old list until
whatever normally restarts workers happens to run.

**P12 — CLI tool + backup/restore + graceful shutdown.** `bin/piwigo`,
`BackupService`, `ShutdownHandler`/SIGTERM cleanup, PHPBench. **Build all
four `maintenance:*` commands (`orphan-tags`, `purge-history`,
`purge-sessions`, `repair-db`) in this same phase** — a prior attempt
deferred `repair-db` because its backing logic still lived in a legacy
file not yet absorbed by that point in the sequence; plan this phase's
dependencies so that gap can't recur. Give each of the four a
`--dry-run` flag that reports what it would do without doing it —
`repair-db`, `purge-history`, and `purge-sessions` are all destructive by
nature, and a dry-run mode is the only safe way to verify one against
real production-shaped data before actually running it. Schedule them via
a Kubernetes `CronJob` resource in the Helm chart for K8s deployments
(P4), with a plain crontab entry as the documented fallback for
Compose-only deployments that don't have a K8s scheduler available.

**`BackupService` is the exact mechanism P4's own restore drills are
written to eventually exercise — this phase isn't separate, overlapping
scope, it's the thing P4 explicitly deferred.** P4's own text already
says as much ("re-run the same drill against the real `BackupService`/CLI
once P12 lands, as part of P12's own verification"); this phase's job is
to make that re-run possible, not to redesign the drill concept from
scratch. **Specify backup scope explicitly**: DB dump (the same
mechanism P4's own infrastructure-level drill already uses) plus the
media tree (`galleries`/`upload` — a DB-only backup is useless without
the actual photo library it references), packaged together as one backup
artifact. Full backups only for v1, not incremental — media files are
overwhelmingly write-once (a new upload is a new file, rarely an
in-place edit), so the incremental-vs-full tradeoff that matters for
constantly-mutating data doesn't buy much here; revisit only if backup
size/duration actually becomes a real operational problem. No
application-level encryption at rest by default — rely on the storage
target's own encryption (an encrypted volume, S3 server-side encryption)
rather than building and key-managing a second encryption layer; storage
location is pluggable through the same `league/flysystem` abstraction
already a real dependency elsewhere in this project, defaulting to a
local filesystem path but not hardcoded to one, so a remote adapter is a
config change, not new code.

**Define the SIGTERM cleanup budget concretely, and decide in-flight
Messenger handling explicitly** — both real, previously-undefined gaps.
Budget: 10 seconds from receiving SIGTERM to forced termination, chosen
to fit comfortably inside a typical Kubernetes
`terminationGracePeriodSeconds: 30` alongside whatever other cleanup the
orchestrator layer also needs before the hard kill; treat this as a
default worth revisiting once a real measurement of actual shutdown-path
duration exists, not a final number. In-flight Messenger handling: since
P11 routes Messenger through Doctrine-DB-backed transports, a message
being processed isn't invisibly "checked out" the way it would be behind
a broker's own visibility-timeout semantics — the transport's own
`redeliver_timeout` (Symfony's default is one hour) is *already* the
safety net for a message whose worker dies mid-processing without
acking. So `ShutdownHandler`'s real job on SIGTERM is simpler than
building explicit requeue logic: stop pulling *new* messages immediately,
let whatever message is already mid-flight finish within the shutdown
budget above, and let the transport's own redeliver mechanism handle the
rare case where it doesn't finish in time — don't build a bespoke
requeue-on-shutdown path that duplicates what the transport already
guarantees.

**Give PHPBench the same explicit CI-blocking treatment as P5's Rector
decision, and name starter benchmark targets rather than leaving the
tool a bare name-drop**: non-blocking, regression-tracking only — run in
CI but never fail the build on a numeric regression, since without
dedicated, isolated benchmarking hardware a hard perf threshold in a
shared CI runner is exactly the kind of flaky gate this project avoids
elsewhere (mirrors the `--mutate`/mutation-score gate's own
not-run-in-CI-yet posture). Starter targets, picked for being real,
known-expensive Piwigo operations rather than arbitrary micro-benchmarks:
category-tree computation for a user (the permission-filtered category
listing every page needs), permission resolution for a given user/album
pair, and derivative-image generation for one representative size. Grow
the target list from real production-shaped profiling once it exists,
not from guessing further ones up front.

**P12 acceptance criteria**: an integration test per `maintenance:*`
command asserting both its real effect (the specific rows/files it's
supposed to touch actually change) and its `--dry-run` safety (nothing
changes when the flag is set); and a real restore-drill test that
provisions a fresh environment from an actual `BackupService`-produced
artifact end to end, satisfying P4's own deferred verification rather
than leaving it as a permanently-open loose end.

### Epoch D — Config/DB/language (P13–P16)

**P13 — Config service.** Build a typed schema, `ConfigLoader`, typed
accessors. **Keep DB-persisted config and the live typed config object
as a single source of truth from the start — never let a write path
update one without the other.** A prior attempt's `$conf` → typed
migration stalled with dozens of files still reading a legacy global,
not from an incomplete migration but because the DB write path updated
the legacy global and never the typed object — a real shipped bug
followed (`CsrfService` reading an empty `secret_key` because it read
the typed side, which was stale). Design the write path so there's
structurally only one place config state lives.

**Name the mechanism explicitly rather than leaving "structurally only
one place" to be inferred later.** The DB row is the sole canonical
store; the typed `Config` object is a lazily-hydrated, request-scoped
read-through wrapper around it, never an independent copy. Every admin
write goes through one `ConfigWriter`-shaped method that updates the DB
row and, in the same call, invalidates — not merely refreshes — both the
request-scoped typed instance and the P11 config cache pool key it
backs, so no later code path in the same request (or a later request
against a warm cache) can observe a value the DB write hasn't already
committed. A read-live model (typed accessors querying the DB directly
on every access, no cache at all) is explicitly rejected here — it would
defeat the entire purpose of the dedicated config cache pool P11 stands
up two phases later. One consequence worth flagging now rather than
rediscovering once real infrastructure exists: since P4's Helm chart
targets a multi-replica Kubernetes deployment, a same-process
invalidation alone only clears the writing replica's own copy — the
config pool specifically needs a cross-process-visible cache backend
(not a per-process-local adapter such as APCu-only) so the
invalidate-on-write above is actually observed by every replica on its
next read, not only the one that served the write. Record this as an
explicit requirement on P11's own pool-backend choice, not an assumption
this phase gets to make silently.

**Generalize the lesson above to every typed facade replacing a legacy
global, not just Config — this is the single most important recurring
failure mode of the whole replay.** A typed facade that "exists" and
compiles is not evidence it's actually wired to real, non-default data —
each one needs independent, empirical verification against real
production-shaped state as its own explicit step, not just its own unit
tests (which typically only exercise default/empty state). This bit a
prior attempt at increasing scale: a user-identity facade existed for
many phases but only ever seeded a guest placeholder, never syncing a
real logged-in identity anywhere in production code; the *same*
Config-sync bug above recurred a second, narrower time in a different
bootstrap window even after the first fix landed; and several already-
shipped typed accessors had a genuine shape mismatch against what the
underlying storage actually held (one assumed a structured value where
storage held a raw string; others re-decoded a value the accessor already
decoded, leaving a defensive `is_string()` check permanently false) —
silently disabling whole features with no error anywhere. Every one of
these was only caught by a dedicated retargeting-and-verification pass,
often via a real visual-regression pixel-diff investigation. Budget an
explicit "verify this facade against real, non-default data" step for
every legacy-global retirement in **P16–P23 and beyond — deliberately
starting at P16, not P17, since P16 is where `CurrentUser` itself, the
exact facade the guest-placeholder bug above happened to, actually gets
built; excluding P16 would exempt the one phase this rule exists
because of.**

**Split config by trust/sourcing tier into separate classes from the
start, rather than one flat typed bag.** A prior attempt eventually had
to retrofit this once its single Config class (~290 properties) became a
real liability: environment-only credentials (DB connection params) need
their own class, sysadmin-lockable deployment settings (error display,
host allowlists, auth-bypass flags) sourced from a typed file-only config
belong in another, and ordinary admin-editable site settings stay in the
DB-backed one — real typed properties in each, not a generic string-keyed
map, so a typo'd key in any tier is an immediate fatal `Error` at boot
instead of a silently-ignored no-op. Doing this from P13 rather than as a
later refactor avoids re-touching every one of the ~290 accessors' real
call sites a second time. **Name the actual storage for each tier
explicitly, since the accessors need to know how they're populated, not
just which class they live on**: the environment tier is read straight
from real process environment variables (`symfony/dotenv`-loaded `.env`
in every non-production environment, real orchestrator-injected env vars
in production) and is never persisted to the DB at all; the sysadmin
deployment tier is a plain PHP file (`config/deployment.php`, returning a
typed array), parsed once at boot and never written by any in-app code
path — sysadmin-lockable means filesystem-permission-lockable, not just
UI-hidden; the ordinary site-settings tier is the one DB-persisted row
this whole phase's write-path section above is about.

**Wire `#[Required]`/`#[Sensitive]` attribute enforcement (SEC-76) into
boot and the error handler as part of this same phase**, not as inert
markers to activate later. Build the reflection-based readers
(`MissingRequiredConfigException` for a missing required property,
redacted logging for sensitive ones) *and* call them from day one — a
prior attempt built both readers correctly but never wired either call
site in. Audit every credential-bearing property (secret key, mail/API
credentials) for `#[Sensitive]` up front, not just the first two found.
**State the backfill contract for a `#[Required]` property added after
go-live, too**: attaching `#[Required]` to a new property is only safe in
the same commit as a migration that backfills a real value into every
existing install's DB row — never a bare schema addition with no data
step. A missing-required-value boot fatal on an existing install after
that point is the intended failure mode of a genuinely broken migration,
not something to soften with a silent implicit default; a silent default
would defeat the entire reason the property is marked required in the
first place. Add this as its own item on the migration-review checklist
P14 builds below, not a separate, easy-to-forget rule. **This phase's own
acceptance test**: assert boot throws `MissingRequiredConfigException`
for a required property with a deliberately-deleted DB row, and assert a
`#[Sensitive]` property's value never appears verbatim in rendered
log/error output on a forced exception path — both as real integration
tests exercising the reflection-based readers themselves, not only each
individual property's own accessor.

**Add a semantic-validation layer on top of PHP's own type system, with
an explicit fail/default policy per tier.** A typed property proves shape
(an `int`, a `non-empty-string`) but not domain validity — an
out-of-0-100-range percentage, a URL-shaped string that isn't a real URL,
a string outside its own real allowed set all pass bare PHP typing
silently. Attach a validation callable/attribute to any accessor that
needs more than shape-checking, and give required and optional properties
different failure behavior: a required property that fails its own
semantic validation throws the same `MissingRequiredConfigException`
family a missing value throws — "present but invalid" is equivalent to
"absent" for a property the app declared it cannot run without, never a
silent fallback. An optional property that fails validation falls back to
its documented static default and logs a warning, rather than either
crashing the request or letting an invalid value silently reach
application logic that assumes it's already been checked.

**Concurrent-write policy: explicitly accept last-write-wins, don't leave
it silently undecided.** Two admins (or an admin and a `bin/piwigo
config:set` CLI invocation) editing the same property within the same
short window is real but rare for a single-tenant admin panel, not a
high-contention shared resource — a full optimistic-concurrency scheme (a
per-row `updated_at`/version column, a conflict-detection UI) is
disproportionate machinery for how infrequently config actually changes
outside initial setup. Default: the later write overwrites the earlier
one, with no merge and no conflict surfaced to either admin. Treat this
as a default worth revisiting only once real usage shows concurrent
config edits genuinely colliding often enough to matter — don't build the
version-column machinery speculatively ahead of that evidence.

**P14 — DB layer + Doctrine ORM.** **Build every domain repository as a
real Doctrine `EntityRepository` (or a directly-injected
`EntityManagerInterface` for repositories that legitimately span
tables) from day one — do not build a DBAL-based abstraction layer as an
interim step.** A prior attempt did exactly that for every repository
but one, then needed a dedicated later migration across ~38 repositories
to get off it. Split by actual need from the start: repositories owning
one table as real `EntityRepository` subclasses; repositories reading
across tables via DQL or raw DBAL for genuinely dynamic query
composition (a search-style repository with per-caller-varying
column/operator combinations has no clean DQL representation) as
directly-injected `EntityManagerInterface` consumers — that's a
legitimate permanent shape, not technical debt to convert away from
later.

**Decide per-FK-column, up front, whether it's a real single-target
foreign key (a `#[ORM\ManyToOne]` association from the start) or a
polymorphic/exclusive-arc column (a scalar value-object column,
permanently) — don't default every FK to a scalar VO column first and
sort this out in a later campaign.** A prior attempt did exactly that:
an initial "every id-like column gets its own VO" pass (`SiteId`,
`CategoryId`, `FormatId`, etc., ~14 columns) typed every FK uniformly,
then a dedicated later multi-wave campaign went back through eight of
those columns one at a time — `representative_picture_id`,
`storage_category_id`, `site_id`, `added_by`, `author_id`, and others —
replacing each scalar-VO column with a real association
(`CategoryEntity::$representativePicture` as `?ImageEntity`, not an
`ImageId`), because a real association is what those columns actually
were: unambiguous, single-target, lazy-loadable relationships that the
ORM should model as such from day one. The other five columns in that
same original batch (`userId`/`categoryId`/`imageId`/`tagId`/`groupId`
used in an **exclusive-arc** pattern — one physical column whose target
table depends on a sibling discriminator column) correctly stayed scalar
VOs, because no real Doctrine association can point at "one of several
possible target tables" — that shape is the one legitimate reason to
keep a column as a VO rather than an association. Make this
classification during this phase's own initial modeling pass, column by
column, rather than shipping the uniform-VO default everywhere and
discovering the split later: every genuine single-target FK becomes a
`#[ORM\ManyToOne]` from its first commit; only real exclusive-arc
columns get scalar VOs. Two gotchas the later campaign hit and this
phase should design around from the start: (1) a raw `fromRow()`/
hydration narrow hard-coded to `instanceof <ColumnId>VO` silently goes
permanently false once a column switches to a real association and
starts hydrating via `IDENTITY(...)` instead — audit every such narrow
for `is_numeric()`-style tolerance, not a VO-only `instanceof` check;
(2) binding a VO instance directly as a DQL/raw-SQL parameter is fine
against a scalar-Type column but unsafe against a real association
comparison — bind the association's own identifier, not the VO, once a
column converts (see the P14 EntityManager section above for this same
binding-convention gotcha stated generally).

**Known identity-map gotcha — design around it from the start, don't
rediscover it per repository.** Any bulk or raw write outside the ORM
(a batch writer, a raw `executeStatement()`) against a table an entity
also maps leaves the shared Doctrine identity map serving stale data on
the next read. Build one arch-test-restricted accessor to the DI
container's *shared* `EntityManager` (a locally-constructed one doesn't
share the identity map, so clearing it protects nothing) so
integration-tier code can legally reach and clear/refresh it — bake this
into the initial repository pattern rather than finding the bug
independently at a dozen call sites first. This gotcha is CRITICAL for any
audit/tamper-detection feature: a query whose entire purpose is to detect
an out-of-band raw write (e.g. an append-only audit log's own
integrity-check read) must force `Query::HINT_REFRESH` or an explicit
`clear()` — otherwise the identity map's default caching is exactly what
would mask the very tampering the query exists to catch. Only the DI
container's one *shared* `EntityManager` needs this treatment; a
freshly-built, per-call throwaway `EntityManager` starts with an empty
identity map and gains nothing from clearing. Also plan on Doctrine ORM
3.x's `EntityManager::flush()`/`clear()` losing their per-entity overloads
(`flush($entity)`, `clear($entityClass)` no longer exist) — always call
the bare, whole-unit-of-work form.

**CRITICAL — decide explicitly how the shared `EntityManager` behaves
under FrankenPHP worker mode, and cross-reference P7's own worker-mode-
readiness posture rather than assuming this phase is exempt from it.** A
persistent worker process reuses the same PHP process — and therefore the
same container-shared `EntityManager` instance, unless something
explicitly resets it — across many requests. Doctrine's own identity map
and unit-of-work state are exactly the request-scoped in-memory state
P7's own worker-mode-readiness criteria already flags (its item (2),
request-scoped in-memory caches) as needing an explicit `reset()` sweep.
Give the shared `EntityManager` its own participant in that same sweep:
call `EntityManager::clear()` — never a full rebuild, since a fresh
`EntityManager` re-triggers the eager platform-detection DB connection
documented above on every single request, defeating worker mode's whole
performance point — at the request boundary, wired into whatever P7
middleware/kernel hook already resets other request-scoped services.
Until worker mode itself lands (P7 may defer the decision), this stays
inert but arch-test-covered, matching P7's own "keep every service's
`reset()` method arch-test-covered from the start" posture — don't defer
the `EntityManager`'s own participation in that sweep until worker mode
actually ships; retrofitting it onto ~38+ repositories' worth of already-
built call sites is exactly the kind of avoidable later work this
project's own conventions exist to prevent.

**Add an explicit N+1-prevention checklist item for every collection-
rendering repository method, verified by a real query-count assertion,
not code review alone.** Category tree, tag cloud, and comment-thread
rendering are the three classic N+1 shapes in this codebase specifically
— each walks a parent-to-many-children relationship where a naive
per-child lazy-load turns one page render into dozens or hundreds of
queries. For each such method, state and test the real eager-loading
strategy (`JOIN` + `addSelect()` for a bounded fan-out, an `IN`-clause
batch-fetch for an unbounded one) with a Doctrine SQL-logger-backed test
asserting a fixed, exact query count against a fixture with a known
number of children — not just that the page renders correctly, since a
correct-but-N+1 render passes every functional test while still being the
performance bug.

**State a default transaction-boundary convention, and use batch upload
as the worked example that actually stresses it.** Default: implicit
per-request auto-flush for ordinary single-entity writes (a repository's
`save()` calls `persist()`+`flush()` itself, no caller-visible
transaction) — explicit `$em->wrapInTransaction()` only where one logical
operation spans multiple entity writes that must commit or roll back
together. Batch upload needs the explicit form: creating an `Image`
entity, dispatching its derivative-generation job, and writing its
category associations are three separate writes that must not partially
commit if any one fails mid-batch — wrap the whole per-image unit in
`wrapInTransaction()`, but keep the *file* write explicitly outside that
transaction boundary, since it already landed on disk via `league/
flysystem` before any DB write starts and a DB rollback can't undo it; a
failed transaction for one image in a batch needs an explicit
compensating file-delete, not an assumption that the DB rollback cleaned
up after it.

**Decide the connection-failure UX explicitly: a clean 503 over an
uncaught fatal, and name where that catch lives.** Don't add connection-
level retry/backoff inside the ORM/DBAL layer itself — a transient DB
outage retried silently inside a repository call just delays the same
failure by a fixed backoff, at the cost of holding a request-serving
worker open for the whole retry window, a real availability cost under
worker mode specifically, where one hung worker is one fewer worker
serving anyone else. Instead, let a DB connection failure propagate up to
a single PSR-15-middleware-level catch (P9's own pipeline) that renders a
generic 503 with a `Retry-After` header — the same shape as the
maintenance-mode short-circuit P7 already defines. One catch site, no
per-repository try/catch, no retry loop.

**State explicitly whether Doctrine's own query/result cache rides on
P11's named `symfony/cache` pools, and which query groups map to which
pool** — don't leave Doctrine's own cache configuration as a separate,
unstated decision from P11's pool design a few phases later. Doctrine's
result cache is a natural fit for exactly the query shapes P11 already
names a dedicated pool for: the `category tree` pool backs the
category-tree DQL query's result cache, the `tag cloud` pool backs the
tag-cloud aggregate query's — reusing the same invalidation discipline
P11 establishes for its permissions pool (event-driven
invalidation-on-write, not TTL-only, for any cached query result whose
underlying rows a write path can mutate; a category rename must
invalidate the category-tree result-cache entry in the same request, the
same way a permission edit invalidates the permissions pool). Leave
Doctrine's separate *metadata* cache (mapping information, not query
results) on a simple long-lived pool with no invalidation concern at all
— it only changes when a migration changes the schema, which already
requires a deploy.

**CRITICAL: never use `persist()`+`flush()` for a write whose failure is
expected and meant to be tolerated (an "insert or ignore a duplicate"
pattern) against the shared, request-scoped `EntityManager`.** Doctrine's
`UnitOfWork::commit()` calls `$em->close()` internally in its own
`finally` branch on *any* `flush()` failure — confirmed empirically, not
assumed — and `clear()` cannot undo that; every later operation on that
same `EntityManager` throws `EntityManagerClosed` for the rest of the
request. Since the container-shared `EntityManager` is reused across
every repository for the whole request, one caught-and-tolerated
duplicate-key exception from an ORM write would permanently break every
*other* repository call for the rest of that request — a severe, easy-to-miss
blast radius for what looks like a locally-contained try/catch. Use plain
DBAL `Connection::insert()` wrapped in a catch for the specific expected
exception instead for any intentionally-tolerated-duplicate write — it
never enters the ORM's unit of work at all, so a caught failure there has
no such blast radius.

**Not every table needs a mapped entity, and not every repository owns a
table — classify each one explicitly rather than assuming uniform
`EntityRepository` conversion.** Three legitimate shapes, expected to
coexist: (a) a repository owning a real mapped entity; (b) a plain entity
with *no* custom repository class for a join/pivot table with no single
natural owner, queried directly via DQL by whichever domain repository
needs it; (c) permanently raw DBAL for tables touched via caller-supplied
dynamic column names, which compile-time ORM column attributes can't
represent (see the DQL-can't-express-it carve-out above). Expect most
non-trivial repositories to mix shapes across their own methods, not fall
cleanly into one bucket. **Shape (c) actually has two independent
triggers, not one — name the second explicitly rather than letting it
hide under the same label:** the dynamic-column-names case above, and,
separately, any write whose failure is expected and meant to be tolerated
(the insert-or-ignore-a-duplicate pattern) belongs on raw DBAL
specifically to avoid the `EntityManagerClosed` blast radius documented
below, regardless of whether that write's own columns are static or
dynamic. A repository method can land on raw DBAL for either reason
independently, and sometimes both at once. Separately: a custom
repository method named
`findAll()` collides with `EntityRepository::findAll(): list<Entity>`'s
covariant return type if it's meant to return something else (a row-shape
array, not entities) — name it `findAllRows()` or similar instead of
fighting the base class's signature.

**CRITICAL — Doctrine's custom Type conversion is NOT applied
consistently across every hydration mode; verify empirically per query,
never assume.** `getArrayResult()`/entity hydration apply a mapped
column's custom Doctrine Type (a wrapping value object) to the result
row's field — but `getSingleColumnResult()` uses a completely different
hydration path (`HYDRATE_SCALAR_COLUMN`, a raw `fetchFirstColumn()`) with
*no* per-field Type conversion at all, silently returning the bare
scalar. A prior attempt's own `instanceof SomeIdType` narrowing check,
written assuming the same VO-hydration behavior as every other DQL query
in the same file, always failed against that raw scalar and silently
returned an empty result for every real input — caught only by an
existing test, not by PHPStan (a runtime hydration-mode difference, not a
type error). When converting any raw-DBAL query to DQL against an entity
with a custom-Typed column, write a real test asserting the exact
resulting PHP shape of that specific query method (object? VO? bare
scalar?) before trusting a row-shape helper built for a different
hydration mode to handle it correctly. A *partial* DQL `select()` of
specific fields (`select('i.id', 'i.path')` rather than the whole
entity) is not the same case as `getSingleColumnResult()` above and
easy to mistake for it — a partial select still goes through
object/array hydration and *does* apply each selected field's custom
Type, silently returning VOs, not plain scalars. The dangerous moment is
retrofitting a custom Doctrine Type onto a column an existing codebase
already queries widely: every pre-existing narrowing check written for
the old raw-scalar shape (`is_numeric($row['id'])`, `is_string($row['path'])`)
silently misbehaves against the new VO value (defaulting to `0`/`''`, or
dropping the row entirely) with no error to surface it — this was the
single most repeated bug class across a whole VO-adoption campaign, hit
independently in file after file. Treat "add a custom Doctrine Type to an
already-widely-queried column" as requiring a full grep sweep of every
DQL read site touching that column before merging, not something to
discover reactively per file. The same underlying question — "does this
specific Doctrine operation apply the column's custom Type, or bypass
it?" — also applies to parameter binding, not just reads: an IN-clause
`setParameter($arrayOfInts)` binding *without* an explicit
`ArrayParameterType` triggers the same per-element Type-conversion
attempt a scalar bind with an explicit `ParameterType` does not, and
`Doctrine\Common\Collections\Criteria::expr()->eq()` against a
custom-typed field requires the real VO, not a raw scalar, to match at
all. Verify each of these independently per call site; none can be
assumed safe by analogy with another. Two more binding-safety data
points, each verified live rather than assumed: a bare scalar bound via
`setParameter()` against a *scalar column* mapped with a custom Type
does **not** throw (confirmed — the custom Type is enforced on
hydration/persistence, not on simple equality parameter binding), but
binding a VO instance directly against a *real ORM association*
comparison (a `#[ORM\ManyToOne]` relation, as opposed to a plain
Type-mapped scalar column) is unsafe the same way — bind the VO's own
raw underlying value there instead, matching the established
raw-scalar-binding convention rather than assuming a VO always works or
never does. Separately: calling `find()`, mutating, then `flush()`
against an entity whose own identifier is itself an association (not a
scalar column) hits a real internal Doctrine landmine — its identifier
gets flattened to a plain string internally
(`IdentifierFlattener`) before the update runs, which then fails a
strict custom Type expecting its real VO shape. A bulk DQL `UPDATE`
against the same rows sidesteps the internal identifier-flattening path
entirely and is the safe alternative for this specific shape.

**CRITICAL — Doctrine's DQL *query* cache is separate from its metadata
cache, keyed by the DQL string itself, and by default can be backed by a
PERSISTENT filesystem cache that survives across separate PHP process
boundaries.** Two different PHP processes running near-identical DQL
strings can silently reuse a previous process's already-compiled SQL —
this specifically defeats mutation testing (or any other test technique)
that relies on re-invoking a custom DQL function's `getSql()`/`parse()`
logic between runs to prove a real change is observed, since a mutated
function's own new output never actually runs if a stale compiled result
is served from the persistent cache instead. Any `EntityManager` built
specifically to exercise DQL-generation logic under test needs an
explicit, non-persistent cache override (a fresh in-memory `ArrayAdapter`
per test), not whatever cache pool the container wires up for production.

**A handful of narrower DQL/Doctrine-version gotchas worth expecting
rather than rediscovering, all confirmed only by reading vendor source or
testing live, never by assumption:** a column name colliding with a
newly-reserved SQL keyword (MySQL 8.0.2+ added `RANK` as a window-function
word) can compile fine in a DQL `SELECT`/`WHERE` and still throw a real
syntax error specifically in a DQL `UPDATE ... SET` on that same column —
backtick-quote any column name that might collide with a SQL reserved
word, don't assume "it worked in one statement type" proves it's safe in
every statement type. Not every SQL function assumed portable has a DQL
built-in in a given ORM version (`RAND()`/`REGEXP()`/`GROUP_CONCAT()` did,
`YEAR()`/`MONTH()` didn't, in the version used) — check the installed
ORM's own function-registry source, or register a custom DQL function via
its documented extension mechanism (`Configuration::addCustomStringFunction()`)
rather than assuming a common function is either always-present or
always-absent. DQL's `CONCAT()` only accepts bare argument expressions,
rejecting trailing arithmetic (`WEEK(...) + 1`) a real caller may need —
select each part as its own aliased column and concatenate in PHP
instead. DQL's `BETWEEN` can mis-parse immediately after an arithmetic
left-hand-side expression. And when a computation must compare against a
live external clock (a DB server's own date functions, or anything
genuinely relative to real wall-clock time rather than to other
`Env::now()`-stamped application rows), don't reflexively apply this
project's own frozen-test-clock (`Env::now()`/`PIWIGO_TEST_NOW`) fix —
decide per computation which "now" is the semantically correct anchor:
comparisons against rows the app itself stamped with `Env::now()` need
`Env::now()` for consistency, but a comparison meant to track the DB
server's or the browser's own real clock needs the real, unfrozen one, or
it silently drifts out of sync as real time passes during a long test
run.

**A frozen test clock (`Env::now()`/`PIWIGO_TEST_NOW`) has to be
independently wired into every structurally distinct write mechanism a
timestamp column can go through — fixing one doesn't fix the others, and
each is invisible from auditing the rest.** A real, recurring source of
golden-HTML/VR baseline drift on every real-time-elapsed re-run, closed
only after finding it independently in four separate places on the same
handful of tables: (1) a schema-level `ON UPDATE CURRENT_TIMESTAMP`-style
auto-bump fires on real wall-clock time with no application code
involved at all — drop it once every real write path sets the column
explicitly, don't rely on it as a determinism-friendly default; (2) an
ORM entity-flush write only respects the frozen clock if something
explicitly sets the property before flush — a shared lifecycle listener
(`prePersist`/`preUpdate`) is the one place to centralize this so a new
entity automatically inherits it, rather than requiring every call site
to remember; (3) a DQL bulk `UPDATE` never loads entities into the unit
of work, so an entity-flush listener can't reach it at all — needs its
own explicit `Env::now()`-bound `SET` clause at every DQL bulk-update
site; (4) a raw-DBAL/bulk-writer path is a third, separate mechanism
neither the listener nor DQL conversion reaches — needs the timestamp
injected into the row/bag explicitly before the write, at every such
site. Audit all four independently for every timestamp-bearing table,
not just the one path a given bug report happened to surface. The same
class of hidden-live-wall-clock-read applies to anything computing a
"now" for a UI-facing derived value (a chart's date-bucket boundaries, a
short-lived-key's embedded date component) via a bare `date()`/`new
DateTime()`/`strtotime('now ...')` call instead of the injected clock —
grep for the raw form specifically once real coverage of the surrounding
page exists to catch it, since these don't announce themselves as a bug
until something actually renders and diffs the affected value. A
seedable SQL-level random function (`RAND()`) needs the same treatment:
have the query-building layer emit the complete seeded expression in
test mode rather than a bare function name, so every call site
automatically gets it with no per-site opt-in to remember.

**Any process-wide "temporarily override global/ambient state, restore
it after" utility built as a ref-counted push/pop stack must be called
through `try`/`finally` at every real call site, with no exceptions** —
a bare push-then-unpop sequence with no `finally` leaves the override
permanently stuck the moment any exception fires between the two calls,
silently corrupting every subsequent request/test in the same long-lived
process (observed as a garbage absolute-URL prefix bleeding into
unrelated, later, unrelated tests' own output). Grep every real call
site of such a utility for the `finally` wrapper specifically as part of
building it, not just at the one site where the leak was first noticed.

**A framework/engine's own compiled-artifact cache (a template engine's
compiled-template directory, a bytecode/opcode cache, anything derived
from source but written to a separate on-disk location) needs its own
explicit clear step wired into whatever tooling resets the test
environment, alongside the DB reimport** — a stale compiled artifact
left behind by a *different* checkout/commit can silently survive a
plain DB reimport untouched, since the two are unrelated storage
locations, and then produce output a fresh compile of the exact same
source, on the exact same installed engine version, could never actually
produce. If the fixture-reimport tooling already clears some derived-
cache directories but not others, audit for the specific one just added
by whatever feature most recently started writing compiled artifacts —
this is exactly the kind of gap that stays invisible until a golden-
snapshot test happens to diff against a stale compiled output.

**Docblocks explaining "why this stays on raw DBAL instead of DQL" go
stale as fast as any other comment — re-verify each one's stated reason
against the current deptrac ruleset and entity-mapping state before
trusting it, rather than treating an existing "not convertible" note as
still true.** A prior attempt's own later re-audit of its DQL-conversion
campaign found several confidently-stated blockers were simply wrong by
the time they were checked again: a table claimed to have "no mapped
entity" already had one; a MySQL-specific date function claimed to have
"no DQL equivalent" had one under a different argument order; a
cross-domain join claimed blocked by a layer-boundary rule was actually
fine once checked against the real ruleset, the doc having only recorded
a stale repository-ownership convention as if it were an architectural
constraint. Treat every "stays on DBAL, here's why" note as a claim to
re-verify on next contact, not a settled fact.

**CRITICAL: constructing a Doctrine repository for the first time in a
process triggers ORM platform auto-detection and an eager DB connection
attempt — even if no query ever actually runs.** This makes any
dependency chain that can *reach* a repository's constructor a real
DB-connectivity dependency, invisible from reading the calling code (it
looks like inert object-graph wiring). A prior attempt hit this twice
independently, both on pages that must work before a DB connection is
guaranteed: a CSS/JS-combining cache decision made on *every* page render
eagerly resolved an access-control chain that happened to end at a
repository, so a transient DB outage turned a harmless caching heuristic
into an uncaught fatal for the whole page; and the install wizard's own
error-redisplay after a bad DB-credentials submission needed its
translation-lookup dependency to stay lazy for the identical reason.
Give any such collaborator a nullable/lazy property with its own setter
rather than a required constructor param wherever the dependency chain
can reach a repository, and treat "does this call path need to render
before the DB is known-good" as a real design question for every shared,
always-on-every-request code path (caching heuristics, error rendering,
pre-install pages) — not just the obviously DB-driven ones. The same
constraint applies to a CLI application's own bootstrap, and the blast
radius there is wider: if the CLI framework eagerly resolves *every*
registered command's full constructor chain up front (a common pattern
for building a complete command list before dispatching to just one),
a single command whose constructor chain reaches a container factory
that does an unconditional DB read breaks *every* CLI invocation of
*any* command — including the migration-runner command itself against a
genuinely pre-migration database, the exact moment such a read is
guaranteed to fail. Audit every container-factory-bound class the CLI
bootstrap's eager resolution can reach for an unconditional DB
read at construction time, not just the commands that look
DB-related by name. **Back this with a concrete arch test, not only
written guidance**: enumerate every class reachable from the CLI
bootstrap's eager command-resolution chain (a static walk of each
registered command's constructor dependencies, or a runtime-instrumented
boot that records every class actually constructed before dispatch) and
assert none of them is a repository or otherwise triggers a DB
connection at construction time. Run it in this phase's own CI from the
start, not as a one-time audit a later command can silently violate
again once no test is watching for it.

**When building a transitional `current()`-style shim during any
singleton-to-DI conversion, match its pre-boot fallback's caching
behavior to the real object's actual lifecycle**, not one fixed shim
shape for every class. A "load once, read/write many times per request"
class (a translation-string cache, an event-handler registry, a
type-map) needs a *memoized* fallback (`self::$fallback ??= new self()`)
— a fresh instance on every call silently loses state between calls,
since each caller gets its own empty copy. A class with no safe fake
collaborator to default to at all (an access-control gate with no
guest-safe default, a translator with real files to load) needs to
*live-resolve from the container on every call with no fallback*,
crashing loudly if used before boot rather than silently degrading.
Picking the wrong shape for a given class either loses real state
silently or serves a stale/wrong object indefinitely.

**Commit to Doctrine Migrations as the schema mechanism here and don't
reverse course mid-project.** A prior attempt briefly dropped migrations
for a static hand-maintained SQL file before any real install existed,
then had to reinstate them for real multi-provider support. Decide the
schema-authority mechanism once. **Add a migration-review checklist item
from the first migration on**: every migration ships with either a
working, tested `down()` method, or an explicit `// irreversible:
<reason>` comment plus a note in the migration's own PR description —
never a silently-empty `down()` that looks reversible but isn't. Verify
`down()` for real periodically, not just that it exists: run it forward
one migration, then back, using the same fresh-fixture-regeneration
technique described below, and diff the resulting schema against the
pre-migration snapshot.

**Read P15's collation/FK-action-policy/table-prefix decisions before
writing this phase's own first migration, not after.** Those decisions
are written up under P15's heading (that's where they topically belong —
P15 is titled for schema/multi-provider concerns), but they apply
starting at *this* phase's first migration, not from whenever P15
happens to run: `utf8mb4_unicode_ci` on every table and no table-prefix
mechanism at all are both stated as true "from the first migration,"
and a default `ON DELETE RESTRICT` FK-action policy is far cheaper to
apply consistently from row one than to retrofit onto tables a later
migration already created without it. Getting any of these wrong in
P14's first migration means a real fix-up migration later, not just a
docs correction — go read P15's text now, before writing that first
migration, rather than treating P15 as a phase that hasn't started yet
and is therefore safe to ignore.

**A real DBAL connection is measurably stricter about SQL correctness
than the legacy `mysqli` connection it replaces — audit for this
explicitly rather than discovering it query-by-query in production.**
Two concrete classes hit repeatedly by a prior attempt: `GROUP BY`
queries selecting a column absent from both the group list and an
aggregate function (`ONLY_FULL_GROUP_BY`), and `SELECT DISTINCT ...
ORDER BY <column not in the SELECT list>` — both silently tolerated under
`mysqli`, both a real runtime 500 under DBAL. Audit every ported raw
`GROUP BY`/`DISTINCT` query for strict-mode compliance as a checklist
item during each domain's migration; the fix for the `GROUP BY` case is
consistently wrapping the non-aggregated column in `ANY_VALUE(...)`.
Separately: DBAL returns **native PHP types** (`int`, `bool`) for typed
columns where `mysqli` always returned strings — this breaks more than
backend type-narrowing. Any JSON/API payload built from a DBAL row and
consumed by frontend JS doing strict-equality against it (e.g.
`array.includes(id)` where `id` came off a DOM attribute as a string)
needs an audit too, once the backend switches to native types.

**Never move a *set* of ids/values through a single `GROUP_CONCAT()`-
style concatenated string column.** It silently truncates at the
server's configured concatenation-length limit, and the failure mode is
worse than "some members are missing": truncation cuts at a byte
boundary mid-value, so a large group's tail id (`...,12345`) can become
a shorter but still syntactically valid, real id belonging to an
unrelated row (`...,123`) — a false positive that passes ordinary
validation and silently corrupts whatever consumes the parsed list, not
just an incomplete result a caller might notice. Do the grouping/joining
in application code instead of SQL for any id-set query. If a delimited
string still has to be built for a real string-typed downstream contract
(a legacy `IN`-clause-shaped caller), build it in application code too,
not via the database's own concat aggregate. Where NULL-vs-empty-string
distinctness matters for a grouping key derived from a nullable tuple, a
JSON-encoded key preserves that distinction (SQL's own `GROUP BY`
already treats all NULLs as one group; a naive delimiter-joined string
key doesn't reproduce that without care) — prefer it over inventing a
delimiter convention.

**Parameter binding prevents SQL injection but does *not* neutralize
`LIKE` pattern metacharacters (`%`/`_`) in the bound value** — a bound
search term containing a literal `%` or `_` still matches as a wildcard,
so `LIKE ?` bound to `"100%"` matches every row, and `"foo_bar"` matches
`"fooXbar"`. This is a distinct concern from injection and needs its own
explicit escaping (backslash-escape both characters, escaping the escape
character itself *first* — doing the wildcard characters first turns an
already-escaped backslash into a live wildcard again) applied to every
user-supplied `LIKE` pattern from one shared helper, not hand-rolled
per call site — a prior attempt found the identical concern solved three
different, mutually inconsistent ways across four call sites in the same
codebase, two of which didn't actually escape anything at all. Record
explicitly whether the target database's own "disable backslash escapes"
SQL mode is in play, since it defeats the default escape character
entirely if enabled.

**Give every `OFFSET`-paginated query a real, unique tiebreaker column
in its `ORDER BY` — the primary key, always, appended after whatever
user-facing sort the query already has.** Sorting purely on a
non-unique column (a timestamp two rows can share, a computed
aggregate collision is even easier) leaves each page's membership
technically unspecified by the engine between separate `OFFSET`
queries — a row can appear on two consecutive pages or be skipped
entirely as the offset advances, non-deterministically, including for a
query whose "no explicit order requested" branch applies no ordering at
all. Test this specifically by forcing a real tie (seed two rows with an
identical sort-key value) and asserting the pages partition the full set
exactly — a fixture whose sort column happens to already be unique for
every existing row will never exercise the bug at all.

**Never issue schema-altering DDL from an ordinary request-serving code
path, even to accommodate a genuinely open-ended, plugin-extensible
vocabulary column.** A live `ALTER TABLE` triggered by an unrecognized
value arriving during normal traffic needs real DDL privilege in
production for what looks like an ordinary write, takes a metadata lock
on what may be a hot, high-write table, and — on a database that treats
DDL as implicitly committing — can't be rolled back if anything else in
the same logical operation fails. It's also a common source of
cross-provider divergence, since one provider's fixed-size enum
requiring periodic widening may correspond to another provider's already
open-ended text column, making the whole mechanism a no-op there. Model
a column that must accept a genuinely open, plugin-extensible vocabulary
as a plain wide-enough text column with application-level length/format
validation from the start, not a closed SQL `ENUM` an application widens
on demand — this also sidesteps needing the DDL-from-a-request-path
mechanism at all.

**Model an optional, dynamically-built WHERE fragment as a real value
object with its own `applyTo(QueryBuilder)`/`toWhereClause()`-style
methods from the start, not as a raw SQL-text-plus-params pair each
caller reduces to a query independently.** A prior attempt found the
same nine-line "if this filter fragment is empty, skip it; otherwise
`andWhere()` plus bind every param/type" idiom copy-pasted verbatim
across nine separate repositories, and a parallel, different workaround
(seeding a hardcoded `1=1` tautology) everywhere the fragment instead
had to splice into a hand-built raw SQL string rather than a
`QueryBuilder`. Both exist purely because "is there a filter at all"
had no single place to answer once the fragment was already reduced to
loose text-plus-bindings. Giving the condition object itself the
"render me, including correctly rendering as truly empty" responsibility
(`toWhereClause()` returning `''` when empty, `applyTo()` no-op'ing on
an empty condition) removes both the copy-pasted helper and every
tautology placeholder at once, and lets two independently-built partial
conditions (e.g. a user-supplied search filter and a permission
restriction) combine and render as one clause in exactly one place
rather than being concatenated ad hoc at each call site.

**Don't flatten a config value built from a closed vocabulary into plain
text before every consumer needs it — model it as a real value object
with explicit render methods for each real consumer shape, keeping only
the genuinely-freeform "raw escape hatch" input as an opaque string.** A
concrete instance: a sortable-field-plus-direction setting (built from an
admin-configured allow-list, or a WS enum param) was immediately
flattened to literal `"ORDER BY x ASC"` text, so every consumer that
needed anything structural about it (a DQL-compatible property path, a
platform-specific portability rewrite for a function with no cross-database
equivalent, a bare "field direction" token pair for re-populating an
admin form) had to regex-parse that same rendered text back apart again
— the tell that the flattening happened too early. A regex built to
match the common "`<field> <direction>`" shape can also silently
misclassify a legitimate value that doesn't fit it (a bare function call
with no direction, e.g. a random-order clause) as unparseable raw text,
carrying a platform-specific expression through unmodified into a
context where it doesn't work — model every real distinct case as its
own explicit variant rather than "the common shape, or else raw." Keep
exactly one designated raw/opaque escape hatch (an operator-configured
override with no closed vocabulary behind it) explicitly flagged as such
(an `isRaw()` check) rather than letting the boundary between "structured"
and "opaque text" blur across the rest of the type.

**When changing a response's `Content-Type` header to the technically
correct value, audit every client-side consumer for behavior that
implicitly depended on the old, wrong one — a client HTTP/AJAX layer
frequently branches on `Content-Type` specifically to decide whether to
auto-parse the body for the caller.** Correcting a JSON endpoint's
`Content-Type` from a generic/wrong value to the real `application/json`
value is a real, previously-latent behavior change on the client: every
call site that used to *manually* parse the (until-then-unparsed)
response body now receives an already-parsed value and double-parses it,
which fails outright once it tries to parse a non-string object.
Grep every real consumer of the affected endpoint(s) for a manual parse
call as part of the same change, not as a follow-up once something
breaks in production.

**P15 — Schema migration + multi-provider.** InnoDB and utf8mb4
uniformly across every table from the start (not most tables, with a
few cache tables getting normalized later), FK constraints, an
append-only `audit_log` (SEC-57).

**Collation: `utf8mb4_unicode_ci` for every table from the first
migration, and record that choice here, in this phase's own body — the
"MySQL infrastructure notes" appendix later in this document explicitly
asks P15 to decide the collation "explicitly," and a decision recorded
only in an appendix P15's own text never points back to doesn't satisfy
that.** `utf8mb4_unicode_ci` is chosen specifically because it has a real
equivalent across the whole MySQL/MariaDB/Postgres provider matrix this
phase is titled for (MySQL's more accurate `utf8mb4_0900_ai_ci` has no
MariaDB equivalent); see the appendix for the full reasoning.

**This phase's own body below is deliberately MySQL/MariaDB-first,
because that's the primary target — but the real multi-provider decisions
the phase's own title promises live in two appendices later in this
document ("MySQL infrastructure notes," "PostgreSQL/multi-provider
portability checklist"), not in this section. Read both as part of
finishing this phase, not as an optional later pass**: MariaDB's schema
*representation* diverges from MySQL's by well over a hundred lines even
for an identical migrated schema (no native `JSON` column type, different
display-width and collation rendering) and needs its own committed
schema-drift snapshot; the `ngram` FULLTEXT parser this section's own CJK
guidance depends on has no MariaDB equivalent at all and must be
conditionally stripped per-platform; and real Postgres support (if in
scope) has its own recurring semantic-gap checklist (boolean wire
representation, integer division, and more). None of that is optional
scope discovered later — it's this phase's own work, just written up
where the provider-specific detail actually lives.

**State a default FK-action policy once, rather than deciding it ad hoc
per table.** Default: `ON DELETE RESTRICT` for every foreign key — a
delete blocked by a real dependent row is a bug to surface immediately at
the point of the attempted delete, not data silently cascaded away that
the deleting caller never asked to also remove. Two named, deliberate
exceptions, and no others without an equally explicit reason recorded at
the point of use: `ON DELETE CASCADE` for a genuine ownership relationship
where the child row has no meaning without its parent (a comment without
its image, a derivative without its source image), and `ON DELETE SET
NULL` for the append-only `audit_log`'s own live-reference half (below)
plus any other reference that must outlive its referent's deletion by
design. Every FK that isn't one of these two named exceptions defaults to
RESTRICT; don't leave a table's FK action unstated and silently inherit
whatever the migration tool's own default happens to be.

**Cross-reference the legacy import tool explicitly: its migration step
owns validating and repairing any FK-violating row before this phase's
FK-constrained schema can accept it.** Pre-FK production data — a real,
existing Piwigo install with no foreign-key enforcement at all — can
legitimately contain orphaned references the new schema's constraints
reject outright on import: a comment pointing at an already-deleted
image, a category-image link surviving its category's own deletion. The
legacy import tool's own migration step (`bin/piwigo import:legacy`,
tracked as its own non-phase-numbered track above) is where this gets
resolved — null out or drop the orphaned reference, logging what was
discarded — not a caveat this phase's schema design silently assumes
away. State this as an explicit dependency running from the legacy import
tool back to this phase, not an unstated assumption on either side.

**Design any log/audit-style table's "what this row is about" column as
two separate roles from the start, not one column trying to serve
both.** A single `object_id`-style column that's both "a live foreign
key to reach the referenced row" and "a permanent historical record of
what the row was about" is a real conflict: the referenced row is
routinely gone by the time (or shortly after) the log entry exists — a
deletion is logged *after* the row is removed, and legitimate callers
record activity against ids they don't own the lifecycle of — so a
strict foreign key on that single column either can't be added at all,
or corrupts the log the moment it's enforced (measure real data before
assuming the "obvious" constraint shape is safe: in a prior attempt,
the overwhelming majority of existing delete-action log rows already
pointed at something gone, which a naive `ON DELETE SET NULL`/`CASCADE`
constraint applied retroactively would have wiped from the very rows a
log's purpose is to keep). Split the concerns instead: a typed,
nullable FK column that's genuinely a live reference (`ON DELETE SET
NULL`, populated only when the referent still exists *at write time*,
verified per-row rather than trusted) alongside a permanent, never-
constrained `object_id`/`entity_id` column holding the historical fact
regardless of whether the row survives. **A logging/audit write must
never be able to fail the very operation it's recording** — if
populating the live-reference half of a log write can itself throw (a
`NOT NULL`/FK violation because the referenced row already vanished),
that failure propagates up and can take down the real delete/mutation
the log call sits inside, which is worse than an incomplete log entry.

- **Decided here, not left conditional: the audit log is genuinely
  tamper-evident, via a hash chain (each row's hash covering some of
  its own column values plus the previous row's hash) — matching
  SEC-57's own "tamper-evident," not just "append-only," wording,
  rather than leaving that half of SEC-57 an open question this
  phase's own text never actually settles.** Any later retype
  of a column folded into that hash payload is only safe if the new
  type's string/byte representation is identical to the old one for
  every value already written** — a value object whose `__toString()`
  happens to reproduce the exact same digit string a raw int
  representation used, as opposed to something that looks equivalent
  but isn't (an object's default string form, a debug-style
  representation with extra characters), is what keeps already-persisted
  rows' hashes valid across the retype; anything else silently
  invalidates the tamper-evidence of every row already written. This is
  invisible on a fresh install with no history — pin it with a real test
  that recomputes the actual hash payload shape and asserts it's
  unchanged, not just a test that the retype compiles and a fresh insert
  still passes.
- **When auditing a schema for a repeated structural gap across many
  tables/columns (which ones lack an index, need a retype, are missing a
  constraint), generate the target list from the live database's own
  catalog/`information_schema`, not by hand-enumerating what looks
  right from reading the migration/entity source** — a prior attempt's
  own hand-derived list was incomplete or outright wrong on multiple
  independent audits within the same campaign (a regex parse of the
  committed schema file finding under half of the real gap; a foreign-
  key audit missing columns hidden inside a composite primary key). The
  catalog is authoritative in a way source-reading isn't; treat any
  hand-built enumeration as a draft to verify against it, not the final
  list.

**Name the activity/history log table's timestamp column `occurred_on`,
not `occured_on`, in this migration — the very first one to create it.**
Upstream Piwigo carries `occured_on` on the equivalent column; P56's
typo sweep (near the end of this document) tracks the rename as a seed
finding specifically because this is the one point where fixing it is
nearly free — there's no production data yet to migrate around, and
every later fix means a real column rename plus every referencing query.
Don't let P56's own "run the broad sweep late" guidance apply to this
one column; it's this phase's job, now.

**If any FULLTEXT index needs to match non-whitespace-tokenizable text
(CJK content), design the `ngram` parser + stopword interaction in from
the start — this is a severe, non-obvious trap, not a tuning detail.**
MySQL's default parser only tokenizes on whitespace, which can't match
CJK text at all; `WITH PARSER ngram` fixes that, but its default 2-byte
token size collides badly with MySQL's own default stopword list, which
includes short fragments like `at`/`in`/`on` — if *any* 2-character
fragment of a word matches a stopword, MySQL drops every fragment of that
*entire* word from the index, not just the matching piece. A prior
attempt found this meant a word as ordinary as "cat" (contains "at")
became completely unsearchable, and this isn't rare — it hits any word
containing a common short fragment anywhere in it. Worse: a FULLTEXT
index's stopword-filtering behavior is fixed at `CREATE TABLE` time and
cannot be changed for an existing index by any later per-connection or
per-write toggle — `SET SESSION innodb_ft_enable_stopword = 0` has zero
effect once the index already exists, confirmed live. The fix must be
`SET SESSION innodb_ft_enable_stopword = 0` executed *before* the
`CREATE TABLE` statement itself, baked into the schema/migration file
permanently, not a runtime toggle applied around individual writes.
Beyond the stopword interaction, expect the `ngram` parser's own
2-character-fragment tokenization to produce genuine false-positive
matches between semantically unrelated content that merely happens to
share a common short substring — confirmed live: a search for "family"
(widened by the app's own word-variant inflector to "families") scored a
real, non-zero relevance match against a category named "Nested Sub
Album," purely because both strings share the fragment "es," with
nothing else in common. `MATCH() AGAINST()` alone is not precise enough
to trust as the sole filter once `ngram` is in play — AND a literal
substring `LIKE` confirmation onto the FULLTEXT clause, keeping the
FULLTEXT index as the fast candidate-narrowing filter while `LIKE`
eliminates fragment-coincidence false positives (a byte/character
substring test, so it stays correct for the same CJK content the `ngram`
parser exists to serve in the first place).

**Skip the table-prefix mechanism entirely — don't build it, don't plan
to remove it later.** Upstream Piwigo's configurable table prefix exists
to let multiple installs share one database, a real constraint on 2000s
shared hosting that doesn't apply to a Compose/Helm deployment model
assuming one dedicated database per install. A prior attempt inherited
it, then had to remove it later across 62 commits — go straight to bare,
unconditional table names. This also matters for tooling: a configurable
prefix makes every SQL string built with it non-literal (`'SELECT * FROM
' . $prefixed()`), which defeats static SQL analysis
(`staabm/phpstan-dba`) and IDE SQL tooling — both only recognize a
literal SQL string. Bare table names from the start let that tooling
resolve real schema types immediately, rather than only after a later
prefix-removal pass makes strings literal for the first time.

**Known gotcha**: if anything derives a per-install disambiguator (e.g.
for `GET_LOCK()` lock names, which need one server-wide), use the
database name — a prior attempt used a table-prefix-style value for this
and had to switch once the prefix concept was removed. Separately: build
every `GET_LOCK()` name from a hash (e.g. `sha1(...)`) of its real
components, never literal concatenation — MySQL's lock-name limit is 64
characters, and a literal-concatenation scheme silently overflows it for
long enough component values, surfacing only as a real, reproducible
`mysqli_sql_exception` once a long-enough real value is exercised (a
prior attempt hit this during a fixture regeneration, not in design
review). Also: a UNIQUE constraint isn't always the right fix for a
check-then-insert TOCTOU race — check whether the domain has a legitimate
"allow this duplicate anyway" escape hatch first (a prior attempt's own
`check_uniqueness=false` upload option), in which case an advisory
`GET_LOCK()`-guarded check-then-insert preserves that escape hatch where
a hard constraint would silently break it. `GET_LOCK()`/`RELEASE_LOCK()`
are scoped to the *connection*, not the request or the process — a real
behavioral win if the acquire and release genuinely share one connection
for the request's lifetime (MySQL releases the lock automatically the
instant that connection closes, including an abnormal process death,
eliminating stale-lock cleanup logic entirely), but a correctness trap
otherwise: if the code that opens DB connections doesn't pool them (a
fresh connection on every `build()`-style call), the acquiring and
releasing call must be threaded through the *same* connection object
explicitly (a lazily-opened, request-cached one), or `RELEASE_LOCK()`
silently no-ops against a connection that never held the lock. This also
matters for testing real contention: re-acquiring an already-held lock
name on the *same* connection is reentrant and just succeeds again, so a
test simulating "another process holds this lock" must open a genuinely
separate connection, not just call the acquire method a second time on
the same one.

**Two specific, security-relevant MySQL `ALTER TABLE` traps to design
around, both caught the hard way by a prior attempt:**

1. Combining a table-level `CONVERT TO CHARACTER SET x COLLATE y` with a
   column-specific `MODIFY ... COLLATE z` in the *same* `ALTER TABLE`
   statement silently makes the table-level collation win — the
   column-specific override is silently ignored, no error. This broke a
   real guarantee (a username column meant to stay case-sensitive
   silently became case-insensitive, colliding two different real
   accounts) and went undetected for a while because Doctrine's abstract
   column-type system has no concept of collation at all — a broken and
   a correct column report identically. Always split a charset-wide
   `CONVERT TO` from any column-specific collation override into two
   separate `ALTER TABLE` statements, and verify the result directly
   against `information_schema.COLUMNS`, not just that the migration
   runs without error.
2. `MODIFY col tinyint(1)` on an `enum('true','false')` column converts
   by the enum's internal ordinal index (1-based), not by the string
   value — `'false'` (the enum's 2nd defined value) becomes the tinyint
   value `2`, not `0`, silently making every "false" row look truthy.
   Verify any such conversion empirically against a real MySQL instance
   before trusting it; the safe pattern is adding a real new-typed
   column, populating it via a string comparison against the still-enum
   original (`old_col = 'true'`, which compares by value, not ordinal),
   then dropping the original and renaming the new column into place —
   never a direct `MODIFY`. After converting any boolean-like column off
   string/enum storage, also grep for and fix every raw-SQL literal
   comparison against its old string values elsewhere in the codebase:
   MySQL's non-numeric-string-to-int coercion silently maps *both*
   `'true'` and `'false'` to `0` against a numeric column, so the query
   never errors, it just silently always evaluates as comparing against
   zero.

**Pair the read-side hazard above with its write-side twin: binding a
raw PHP `bool` (specifically `false`) to a DBAL parameter with no
explicit type hint gets bound through `mysqli` as a plain string, and
mysqli's own string-cast of `false` is `''` — which `STRICT_TRANS_TABLES`
mode rejects outright as an invalid integer for the column** ("Incorrect
integer value: '' for column"). Every boolean/`tinyint(1)` column write
needs an explicit `(int)` cast (or a shared `booleanToInt()`-style
helper) at the bind site, never a raw bool. Treat both hazards as a
mandatory two-item checklist for every `enum('true','false')`-to-real-
boolean column conversion — a prior attempt rediscovered this same pair
independently, per domain, across most of the schema before naming it as
a checklist. Two more small, low-risk cleanups worth doing at the same
time as any such column conversion: dropping a `1970-01-01`-style
NOT-NULL sentinel default for a real nullable column is safe exactly when
every real write path already sets the column explicitly — verify this
by reading every real writer, don't assume; and after converting a raw
associative-array DB row into a typed projection object, grep for
`array_column()` calls still reading the OLD snake_case key names against
it — `array_column()` doesn't error on a missing key, it silently drops
the row from its result.

**Periodically regenerate the committed test fixture entirely from
scratch (a fresh install + fresh migrations, never just reusing a
long-unchanged committed dump) as a deliberate, independent bug-finding
technique, not just a one-time chore.** A prior attempt found several
"only ever worked by accident against stale fixture data, never against
a genuinely fresh write" bugs this way in one pass — a trailing-slash
config default combined with un-normalized concatenation silently
double-slashing every new upload's stored path, and a `'./'`-prefix
path-matching scheme that could never actually match a freshly-uploaded
image's real stored path, only a stale value an earlier fixture
generation happened to bake in. When a piece of logic "passes" only
against an old fixture and never against freshly-generated data, suspect
the logic, not the fixture. Validate new migrations specifically against
a fresh, throwaway database (migrate from zero) rather than the
long-lived fixture DB — a fixture DB whose schema was loaded once from a
raw dump doesn't have Doctrine's own migration-tracking table populated,
so replaying real migrations against it collides with already-applied
DDL. Never bake an absolute filesystem path into a git-committed fixture
(a SQL dump's own seeded row, a JSON fixture) — a prior attempt's fixture
SQL hardcoded one checkout's real path for a gallery's storage directory,
which silently broke under any other worktree or checkout with a
different directory name; patch it at import time from the real checkout
root instead, the same way a frozen test clock is injected via
environment rather than baked into fixture data. Watch for the same
hardcoded value getting independently copy-pasted into a test's own
assertion, too — one such case only "passed" because *both* sides of its
comparison were wrong in the exact same way, masking a real failure in a
sibling test that compared the same fixture value against something
correct.

**P16 — Typed facades + constants retirement + language.** `Paths`,
`CurrentUser` and `PageState` facades, `define()` constants retired
outright rather than kept as legacy shims, `.po` migration, ICU
MessageFormat pluralization. **Build real Unit test coverage for the
templating layer's classes as they're written, not as a later
gap-closing pass** — a prior attempt shipped this layer with zero
dedicated Unit coverage (only indirect Browser exercise) for a
significant stretch.

**State `PageState`'s scope explicitly here rather than leaving it a bare
name, and cross-reference P41's own later split.** `PageState` replaces
the legacy `global $page` array as one shared per-request singleton
(`PageState::current()`), holding the page-level flash-style
accumulators (`errors`/`warnings`/`messages`/`infos`, plus the
header-strip-specific `headerMessages`/`headerNotes` bucket) and a
handful of page display fields (`bodyId`, `bodyClasses`, `pageBanner`,
`metaRobots`). Build it here as one class with this full field set — do
**not** pre-split it by concern in P16; P41's own "shell-last rendering,
`PageState` split" work is explicitly the phase that determines the real
split boundaries, once shell-last rendering exists to reveal which reader
lives at which layer, and splitting early here would just be guessing at
boundaries P41 is positioned to get right. **One field to deliberately
leave off `PageState` from the start, though, rather than reproducing it
and cleaning it up later: a `loginFailureReason`-style back-channel
flag.** Using a page-state field to smuggle "account locked" vs. "bad
credentials" out of the login flow works, but it's a back-channel a
controller has to know to check rather than a value the call that
produced it actually returns — exactly the shape this project's own
"explicit result over implicit state" posture argues against elsewhere.
Build `AuthService::login()` to return a typed result object instead
(a nullable user id paired with a nullable, enum-shaped failure reason)
from the moment it's built in P18, and let the calling controller branch
on that return value directly; this sidesteps needing the back-channel
field on `PageState` at all, rather than adding it here and removing it
in a later cleanup pass.

**`PageState` is exactly the kind of request-scoped, container-shared
singleton P7's worker-mode `reset()`-sweep policy exists for — give it a
real `reset()` method, arch-test-covered, from this same commit, not
later.** A scattered-write accumulator like this (many unrelated call
sites append to `errors`/`warnings`/`messages`/`infos` throughout a
request) is a poor fit for PSR-7 request attributes specifically — those
are immutable and set-once, which would mean threading a growing
`ServerRequestInterface` through every call site that wants to add a
message, reintroducing the prop-drilling problem DI-container-shared
services exist to avoid. A reset-swept singleton is the right shape here
(and is what `EntityManager` and every other request-scoped service in
this document already use); the risk worth naming explicitly is skipping
the `reset()` coverage, not the singleton shape itself.

**Add a zero-tolerance arch test for `define()` retirement from this
phase's first commit, matching P23's own posture for `$GLOBALS`/static
bridges rather than leaving constants retirement with no enforcement
mechanism at all.** Enforce zero raw `define()` calls (an
`ecs`/`PHPStan` AST rule flagging the bare function call, mirroring the
`defined(...) or define(...)` sweep rule P7 builds for the worker-mode
transition) across `src/Piwigo/` from day one. Scope the check past PHP
source alone: grep Latte templates for a bare constant reference
(`{PHPWG_ROOT_PATH}`-style usage compiles fine as long as the legacy
constant still exists, silently surviving a PHP-only sweep), and audit
any script that generates a JS-exposed constant snapshot (a build step
serializing selected PHP constants into a `<script>` block for frontend
consumption) for the same legacy names — a constant retired from PHP but
still referenced by name in a template or a JS-generation script fails
silently (an undefined-constant warning under PHP 8's `E_WARNING`
posture, not a hard error) rather than announcing itself at retirement
time.

**Add a concrete i18n verification step for the ICU MessageFormat
migration, not just the mechanical `.po` conversion itself.** Render a
real pluralized string across at least three distinct ICU plural-rule
categories from one target locale, not just English's own two (`one`,
`other`) — English alone can't catch a category-count bug, since it has
the fewest categories of any commonly-supported locale. Use Polish
(`one`/`few`/`many`/`other`) or Arabic (`zero`/`one`/`two`/`few`/`many`/
`other`) as the verification locale specifically, since both expose a
real category the English-only path can never exercise. Pair this with an
explicit `.po`-conversion policy, decided once rather than per-file:
convert existing gettext plural forms to ICU MessageFormat mechanically
wherever the old locale's gettext plural-rule count already matches its
new ICU category count (the conversion is lossless there — every existing
translated string carries over with no re-authoring needed), but flag any
locale whose category count differs between the two schemes (a locale
gettext modeled as N forms that ICU's own CLDR data models with a
different N) for native-speaker re-authoring rather than trusting a
mechanical form-index remap to invent or drop categories correctly on its
own.

### Epoch E — Service layer (P17–P23)

**P17–P20 — Domain tiers 1–4.** Migrate domain namespaces in dependency
order, each tier depending only on the ones before it. **Tier 1** URL,
Cookie, Session, HTML, Storage, Csrf, Permalink, Site, Feed. **Tier 2**
Mail, Filter, Users, Auth, Tag, Comment, Rate, Group, Caddie, History,
Activity. **Tier 3** Category, Search, Image, Calendar, Notification,
Metadata, Telemetry, Validation, Common. **Tier 4** Page renderers, Menu,
PluginConfig, Section, Job. **Delete each domain's legacy `include/`
file immediately after its migration, not batched to the end** — keeps
every diff reviewable and avoids one large simultaneous deletion at the
very end. Every migrated method/property/parameter/local variable
already carries its real PSR-1 `camelCase` name coming into this phase —
P6's own naming-convention pass (below) already renamed the whole tree
before any domain work started, so there is nothing left to rename here.

**Resolve the apparent tier-dependency conflicts explicitly before
migrating any of these namespaces, rather than leaving "each tier depends
only on the ones before it" as an assertion the tier list itself seems to
contradict.** On paper several Tier 1/2 domains reference Tier 3 concepts
directly — Permalink and Feed (Tier 1) both resolve categories/images;
Comment, Rate, Caddie, and History (Tier 2) are all fundamentally
per-image records. **Default to this resolution:** every such reference
is a scalar, typed id value object (`ImageId`, `CategoryId` — built in
`Common\ValueObject` per the namespace decision below) toward the
not-yet-migrated Tier 3 domain, never an entity-typed association or
eager join; the actual relational join only happens once the
*consuming* Tier 3 repository is built and reaches back across the id.
This keeps the tier order real rather than aspirational: a domain only
genuinely depends on a later tier if it needs that tier's own
repository/service code to run, not merely a scalar id shaped by it.
Confirm this scalar-id shape at each of the six domains' own migration
commit rather than assuming it from this list, and re-tier or split the
one domain if a real entity-typed dependency turns up instead.

**Session and Csrf land in this very first tier and are both
security-sensitive from their first commit — don't defer their
hardening to P23's later sweep the way the rest of this epoch's security
rigor is filed there.** Build dedicated Unit coverage in P17 itself for
session-fixation (the session id regenerates on privilege change; the
pre-auth id is rejected afterward), session-regeneration, and
CSRF token-mismatch/replay paths. Pair it with a P17 acceptance
criterion for the tier's URL-facing domains: byte-identical legacy
URL/permalink resolution against a real fixture set, verified before
calling the tier done.

Two naming choices worth repeating deliberately: build Cookie as
`Piwigo\Auth\CookieService` rather than a standalone namespace, and name
the user namespace `Users`, plural — the `Auth` namespace directory
therefore opens in Tier 1, before Auth's own domain classes land in
Tier 2; that's expected and correct, not a tier-order violation, since a
namespace is a code-location boundary, not a claim that everything under
it already exists.

**Sequence Tier 2's eleven domains explicitly, don't land them "at
once":** Users first, since Auth, Group, and the tier's four per-image
domains all reference it; Auth and Group next, both direct Users
dependents; Tag after that; Comment, Rate, Caddie, and History next —
each needs only the scalar `ImageId` value object from the
tier-dependency default above, not Tier 3's actual Image domain code, so
nothing here is really blocked; Activity, Mail, and Filter last, since
nothing else in the tier depends on them. Revisit this ordering once
real per-domain effort estimates exist — it's a sensible default, not a
fixed law.

**Auth is the highest-consequence domain in this tier and gets zero
domain-specific security scrutiny anywhere else in this section — build
these checks as part of Auth's own P18 commit, not deferred to P23's
later sweep:** the password-hash algorithm plus a migration path for
existing legacy hashes (old hashes still authenticate and get
transparently upgraded on next successful login, never silently locked
out); a session-fixation regression test specific to the login flow; and
a CSRF-token round-trip test for the login form itself, ahead of and
narrower than the general admin-mutating-handler sweep P23 runs later.

**Decide the `Common` namespace's real scope up front, not as an
open-ended "maybe later."** `ValueObject`/`Enum`/`Dto` subdirectories for
genuinely cross-domain types. Decide explicitly whether a path-value-object
layer (`AbsPath`/`RelPath`) and a centralized `Privacy` enum are in scope
— a prior attempt left both as an unresolved "originally named, never
built" gap for the whole project rather than deciding once, early.
**Default: yes to both, decided here rather than left open again** —
Tier 3 (Image, Category, Search) is exactly where filesystem-path
handling and privacy-level checks concentrate, so build `AbsPath`/
`RelPath` and a centralized `Privacy` enum as real `Common` types from
the moment Tier 3 starts, not deferred to whichever individual domain
happens to need one first.

**Define "Projection" here concretely, since Search is the domain most
likely to originate the pattern and the term otherwise appears bare,
undefined, at its next two uses (P23's cleanup checklist, and Epoch F's
typed-row-consumption rule below):** a Projection is a readonly,
per-query DTO with real typed properties — never a raw associative array
or a single generic row wrapper reused everywhere — that a repository
method returns in place of its query's raw result set. One Projection
type per distinct query shape, so each consuming call site gets real
property-level typing instead of string-keyed array access.

**Add a Search-specific acceptance criterion for this tier:** relevance
ordering and filter combinations verified against a fixture corpus with
known-correct legacy results, not spot-checked ad hoc.

**When converting a previously-primitive id/string field to a real value
object, audit four specific breakage classes at every real call site, not
just the type signature — a prior attempt found real, silent bugs in each
category across its Group/Tag/User VO integration:** (1) the value used
as a PHP array key (`$counts[$tag->id]` throws `TypeError` once `id` is
an object — needs `->value`); (2) `===`/`!==`/`in_array()` comparison,
which checks object identity, not value, for two independently-constructed
instances of the same logical id — always false when compared correctly
would be true, or vice versa, never a loud error, just silently wrong
(use an explicit `->equals()` or compare unwrapped `->value`s); (3) a
serialization boundary — `$_SESSION`, a cache key, `json_encode()` — where
the object crosses into a context a later, unconverted read site still
expects the old raw scalar to come back out (unwrap explicitly at the
boundary, don't let the object leak across it); (4) an existing mechanical
find-and-replace pass that only matches one literal access chain (e.g.
`CurrentUser::get()->id`) misses the same value reached through a local
variable indirection (`$u = CurrentUser::get(); ...; $u->id`) — after any
such mechanical sweep, grep every real call site of the *underlying
accessor* itself, not just the literal chain pattern, to catch it.

**Use bound-parameter SQL composition everywhere in these domains from
the start — no raw-string splicing, ever, not even as a quick first
pass.** A prior attempt needed a dedicated later sweep to fix several
live SQL injections that had already shipped this way: three in the
Image domain, one in Comment, one spanning History/Notification, a
plugin-hook injection point, one in Category. Design a typed condition/
bound-parameter carrier for dynamic query composition (the kind
`SearchRepository`-style dynamic filtering needs) as part of this
phase's own infrastructure, not retrofitted after finding the injection
bugs. If such a sweep is ever needed anyway, convert *every* raw-string
splice regardless of whether it looks attacker-reachable, not just the
ones a quick audit flags — a prior attempt's own "not user input"
docblock claim on one fragment turned out to be wrong about the value,
only right about the column choice, and several other fixes turned out to
be guest-reachable through non-obvious paths (an unrestricted URL-segment
regex, a WS parameter registered with zero type constraints). Scope any
such sweep by the underlying DB-execution sink (every `fetch*`/
`execute*`/`query*` call), not by one syntactic form of string-building —
a prior attempt's sweep was keyed to `<<<SQL` heredocs specifically and
initially missed several files building the identical raw SQL via plain
string concatenation instead, invisible to a heredoc-only grep. Prefer a
real query-builder's expression API (Doctrine DBAL's `ExpressionBuilder`
— `notIn()`/`in()`/`and()`, etc.) over hand-typing SQL fragment strings
even once every value is bound: hand-typed fragments (`$field . ' NOT IN
(:' . $placeholder . ')'`) can still drop a space, paren, or keyword,
exactly the bug class that broke a real admin filter query earlier in
this same lineage, where the value-binding was already correct and only
the surrounding syntax was hand-assembled wrong. Separately, when several
sibling queries reuse *overlapping subsets* of the same filter/condition
set (not identical between queries, not independent either — one query
needs the full set, another needs it minus one specific field, a third
needs a different field ignored) — model it as one typed, readonly
criteria object built once and passed to every consuming method, with
each method explicitly choosing which fields it honors in its own code.
A prior attempt's original shape for this (one shared, mutable
loosely-typed condition list, with `unset()` on specific array keys
between call sites to drop a field for one specific sub-query) is fragile
by construction — the criteria object makes each method's real filtering
contract an explicit, reviewable piece of code instead of an
easy-to-miss array-key convention.

**Design the upload/representative-generation pipeline's security
posture as explicit P19 (Image domain) infrastructure, not a checklist
audited in afterward.** A dedicated security review of a prior attempt's
own upload pipeline (`UploadService`'s SVG sanitization, `exec()`-based
PDF/HEIC/TIFF/PSD/EPS/video representative generation) confirmed several
real controls already in place there (DOMDocument-based SVG script/
`on*=` stripping, `escapeshellarg()` on every shell-out per SEC-16,
forced `Content-Disposition: attachment` on served SVG/HTML uploads, a
finfo-vs-extension MIME-sniff check) but found a real, concrete gap list
on top of them, none of it exotic — each worth building as day-one P19
infrastructure rather than retrofitted once found in production:

- **The system's own ImageMagick `policy.xml` is inherited, unpinned,
  from whatever the deploy image happens to provide** — the single
  highest-value gap to close first. A deploy image that already denies
  URL/HTTPS delegates and default-denies non-allowlisted coders is doing
  real work the application takes zero credit or responsibility for;
  ship and pin a real `policy.xml` in the application's own container
  image (see P4) so this control travels with the app rather than
  depending on which base image happens to be deployed against.
- **No pixel-area cap before any image decode** — `getimagesize()`/GD/
  Imagick decode calls never check declared width×height before
  decoding, a decompression-bomb/pixel-flood vector distinct from (but
  the same family as) SEC-32's archive-decompression concern: a small
  file can declare enormous dimensions and force a huge in-memory
  allocation on decode. Check declared dimensions against a real cap
  *before* the full decode, not after.
- **No hardcoded, non-overridable deny-list on accepted upload
  extensions** — the accepted-extensions config only type-coerces,
  never blocks executable extensions (`php`/`phtml`/etc.) outright.
  Since the upload directory is web-served, a plain admin
  misconfiguration here is a direct RCE path, not a defense-in-depth
  nicety — hardcode a deny-list that config genuinely cannot override,
  independent of whatever the admin-configurable allow-list says.
- **No re-encoding of accepted raster images** — accepted JPEG/PNG are
  resized but never force-re-encoded, so most embedded-polyglot payloads
  and EXIF-based exploits ride through untouched. Re-encoding through a
  real decode→re-encode pass strips this class of payload at the cost of
  some metadata/quality loss — worth the trade for anything served
  directly from `/upload/`.
- **SVG sanitization needs more than DOMDocument-based `<script>`/`on*=`
  stripping to actually be safe — four concrete, real bugs a
  regex/DOM-based sanitizer commonly misses, each worth its own explicit
  test fixture rather than trusting "the sanitizer runs" as the
  acceptance bar:** (1) a DOCTYPE-stripping regex that doesn't correctly
  consume a bracketed internal subset (`<!DOCTYPE svg [ ... ]>`) can
  leave part of an XXE-capable internal DTD intact even though the
  sanitizer believes it stripped the whole doctype; (2) `javascript:`/
  `data:`-scheme URIs in `href`/`xlink:href` are a script-execution
  vector script-tag/event-attribute stripping alone doesn't close —
  strip or reject both schemes on both attributes explicitly; (3) SMIL
  animation elements (`<animate>`, `<set>`, and siblings) can trigger
  script-equivalent behavior on their own, independent of `<script>`
  and `on*=` — a sanitizer scoped only to those two misses this whole
  class; (4) the sanitizer must **fail closed** — reject and refuse to
  store a file it can't fully parse/sanitize, never fall back to
  storing the original, unsanitized bytes "since sanitization didn't
  crash" reads as success when it silently skipped the file. Extend the
  same MIME-vs-extension cross-check already applied to images to
  `text/html`/`text/plain` uploads too, and force
  `Content-Disposition: attachment` on any served `image/svg+xml` or
  `text/html` upload regardless of a client-supplied `download`
  request param — a param the serving endpoint honors for display
  convenience must never be able to downgrade the one header
  preventing inline execution.
- **EXIF/IPTC/XMP metadata is itself an injection vector, not just the
  pixel data** — audit every metadata field's own escaping at DB-write
  time and at every point the admin UI renders it back (stored-XSS/SQLi
  risk if any field reaches output or SQL unescaped) — this is exactly
  the kind of externally-controlled string this phase's own
  bound-parameter-everywhere rule already covers, but metadata fields
  are easy to miss in an audit scoped only to obvious form input.
- **XMP is XML too, parsed via a separate code path from the SVG
  sanitizer** — extend SEC-20's XXE protection explicitly to the XMP/
  metadata parse path, not just the upload-time SVG sanitizer; the two
  are easy to configure inconsistently since they're reached from
  different call sites.
- **ffmpeg's own demuxer surface carries its own independent CVE
  history** — distinct from the image-decoder CVE classes already
  covered, including historical local-file-read primitives via certain
  playlist/concat-style inputs. Pin a specific, tracked ffmpeg version
  rather than "whatever the base image ships," matching the `policy.xml`
  pinning reasoning above.
- **No file-size ceiling beyond PHP's own `upload_max_filesize`, and no
  per-user/session upload quota** — worth a real, enforced cap
  independent of the blanket PHP ini setting.
- Consider **malware/AV scanning of uploaded bytes** as an explicit,
  deployment-dependent decision (expensive to run universally, genuinely
  valuable for a multi-tenant/public-upload deployment) rather than
  silently never deciding either way.

**Spell it `representative`, not `representant`, on every PHP
variable/method name in this pipeline.** Upstream Piwigo's own misspelling
(`representant` — French-derived) infects the naming throughout the
representative-generation code this phase migrates; fix it here, at the
point this code is rewritten anyway, rather than deferring it to P56's
later sweep. The 130+ Latte templates referencing the same name and the
one live translatable string (`'Find a new representant by random'`) are
P31's job instead, at the point each template is converted — see P31's
own text below.

**Scope Tier 4's four names explicitly before migrating any of them —
none is defined elsewhere in this document and two risk real duplication
with later phases:**

- **"Page renderers"** — treat this as an interim per-page rendering
  layer this tier moves out of `include/` largely as-is, not a permanent
  design; P40/P41's later typed-view-object/`Template` split is what
  actually determines this layer's final shape, and supersedes it. If a
  genuinely admin-vs-frontend-shared renderer layer doesn't materialize
  once real code exists, fold page-renderer classes into each page's own
  P21/P22 controller commit instead of forcing a separate Tier 4 domain
  to hold them.
- **"PluginConfig"** — scope this to plugin/theme configuration storage
  and CRUD only (activation state, config key/value persistence,
  migration tracking). The actual extension contract and lifecycle
  system (`ExtensionInterface`, `ExtensionContext`, plugin/theme
  registries, event dispatch) is P29's job, built later, even though it
  ends up living in this same namespace — P20 migrates the storage half
  first, P29 adds the contract half on top.
- **"Section"** — the resolved gallery-navigation context for the
  current request (which top-level view is being rendered: categories,
  tags, search, favorites, a most-visited/best-rated/recent listing, or
  an explicit image-id list), replacing the legacy `$page['section']`
  global. One frontend controller (P22) dispatches internally on this
  value; it isn't a per-view-type controller split.
- **"Job"** — background/queued work (derivative regeneration, batch
  upload processing, notification email, reindexing). Cross-reference
  P11 (messenger) and P12 (CLI) explicitly here, the way P23's own
  checklist cross-references P11 — Job domain classes are message
  handlers dispatched through P11's messenger infrastructure, and
  several are triggered from P12 CLI commands.

**P21 — Admin controller migration.** Map every admin page to its own
dedicated service implementing a shared `AdminSubControllerInterface` —
**do not build god-classes that bundle many admin pages together**
(a `MaintenanceController`, `MiscController`, or `BatchManagerController`
handling a dozen unrelated pages each). Keep dispatch itself decomposed
too (a thin dispatcher mapping page slugs to services, not a routing
god-class). Apply the same one-page-one-service discipline to any
admin-surface plugin/extension services, not just core pages.

**Enforce the no-god-classes rule with a real arch test from this
phase's first commit, not stated intent alone** — a zero-tolerance check
(mirroring P23's own `$GLOBALS`/free-function arch test) that fails if
any single class is registered against more than one
`config/admin_pages.php` slug.

**Specify `AdminSubControllerInterface`'s contract explicitly here, not
left implicit:** one method, `handle(ServerRequestInterface $request):
void`, reading input from the injected PSR-7 request rather than
`$_GET`/`$_POST` directly (SEC-19) — deliberately minimal, with no
permission-level or CSRF declaration built into the interface itself.
That leaves both checks each implementation's own responsibility unless
something centralizes them; **decide here whether to centralize both as
required constructor collaborators (a permission-level enum plus a CSRF
verifier, injected and checked by a shared abstract base class before
`handle()`'s own body runs) rather than leaving every sub-controller to
remember them independently** — the latter shape is exactly what P23's
later CSRF/authorization sweep found missing, page by page, across a
large fraction of the legacy admin surface.

**State the tab/mode-splitting rule explicitly: a legacy page that
multiplexes several internally distinct modes off one shared
`?tab=`/`?mode=` GET parameter (sharing one script) still counts as
multiple real admin pages for this phase's purposes — one dedicated
service per mode, not one service with an internal switch.** Route the
tab/mode dispatch itself at the same thin-dispatcher layer already used
for page slugs.

**Make CSRF-token verification and permission-level checking a per-page
P21 acceptance criterion, not deferred to P23's later sweep** — verify
both directions live for every migrated page (a request with no token
rejected; a request with a real token actually performs the mutation),
the same two-directions discipline P23 applies later. Doing this here,
page by page, as each page is actually read and migrated, is what halves
the surface P23's dedicated sweep needs to catch independently.

**P22 — Frontend controller migration.** One controller per real
frontend page. Two things to decide deliberately rather than rediscover:
`Install` isn't a controller — it must work before any DB or config
exists, so it can't go through the DI-resolved router; keep it a special
unrouted entry point from the start. No `Upgrade`/`UpgradeFeed`
controllers are needed if committing to the clean-fork stance (no
in-place-upgrade mechanism at all) — decide this explicitly rather than
building dead controllers for a mechanism that won't exist. **Absorb a
gallery/listing page's real query logic (item listing, favorites,
next/prev navigation) out of `include/` during this phase, into the
Image/Category domain services P19 already built — called by the
controller, not embedded inline inside the controller class itself.** A
prior attempt initially just relocated the `include()` call and left
hundreds of lines of real SQL logic sitting unabsorbed inside the
controller until a later cleanup pass moved it down into domain services
where it belonged; do it in one pass this time. **Build this as a
single `GalleryController`, internally dispatching on the P20 `Section`
domain's resolved value (categories/tags/search/favorites/most-visited/
best-rated/recent/an explicit id list), not one controller per listing
type** — the legacy `$page['section']` split is a rendering-mode switch
on one page, not several distinct pages.

**Extend the CSRF/authorization sweep to this phase's own frontend
mutating handlers, not just P23's admin-scoped one** — comment
submission, rating submission, and private-gallery password checks are
all guest/user-reachable mutations with no cross-reference to any
CSRF/authorization coverage anywhere in this document. **Decided here:
P22 owns verifying them directly, mirroring the acceptance criterion
added to P21** — the same page-by-page, migrate-and-verify-together
discipline, rather than deferring frontend coverage to P23's own
sweep, which stays scoped to the admin surface only (see P23's own
text above). Verify both directions live for every migrated frontend
mutating handler (a request with no token rejected; a request with a
real token actually performs the mutation), the same two-directions
discipline P21/P23 apply elsewhere.

**Add an acceptance criterion for sort ordering:** legacy per-category
custom sort order, random order, and date-created-vs-date-posted
ordering all preserved and verified against fixtures, plus a URL-parity
regression check across the listing/navigation surface this phase
migrates.

**P23 — Legacy deletion & cleanup.** Delete `include/` and `admin/` as
directories entirely once every domain is migrated. Retire every
`$GLOBALS`/static-bridge global (should be minimal if P8's DI-first
discipline held). Retarget event-dispatch, `l10n()`, and URL
free-function bridges onto real classes. Enforce zero `global $x`
statements, zero live `$GLOBALS` reads, zero bare legacy free-function
calls via a zero-tolerance arch test **from this phase's first commit**,
not bolted on once things already feel done. **Also run P8's own
final codebase-wide static/`Kernel::container()`-resolution sweep here**
(P8's own text above states the rubric and why the sweep matters even
with day-one discipline) — this phase is already the natural home for
"verify the DI discipline actually held," not a separate, additional
audit phase.

**Decide these explicitly before starting, not as issues to discover
mid-phase** (a prior attempt hit all four as unplanned divergences):

- Whether `include/` needs to keep a thin bootstrap seam for as long as
  certain constants must stay out of `src/Piwigo/` (a worker-mode
  requirement, see P7) — moot by construction now that P7's own open
  question resolves worker mode's actual flip-on to this phase: by the
  time this phase's own scope is done, `include/` is gone and the seam
  question falls out on its own.
- **This is the named phase where true FrankenPHP worker-mode execution
  actually lands** (P7's own resolved open question) — flip
  `docker/Caddyfile` off plain `php_server` and onto worker mode as part
  of this phase's own scope, verified against every `reset()` method P7
  onward kept arch-test-covered, not treated as a follow-on someday.
- Root entry points (`admin.php`, `picture.php`, etc.) staying thin
  shells that preserve Piwigo's original URL surface, rather than
  collapsing into one front controller — and living under `public/` from
  the start (web-root isolation), not migrated there later.
- Don't assume `$GLOBALS` retirement is free just because `include/` is
  deleted — real `global $x` contracts can still be scattered across
  `src/` files if DI wasn't strictly enforced earlier (P8). Budget real
  time for this rather than assuming a zero-cost cleanup.
- Complete the ORM migration (P14) *before* this phase, so there's no
  legacy DBAL abstraction layer still pending removal here — verify with
  a repo-wide grep for raw `Connection::fetch*`/`execute*`/`query*` calls
  outside `src/Piwigo/*/Repository` classes; a nonzero count means P14
  isn't actually complete yet, whatever its own status marker says.

**Record every such "decide explicitly" choice in this document in the
same place, not wherever felt convenient per bullet:** append a dated
entry to `docs/DECISIONS.md` (decision, rationale, phase) each time one
of this epoch's bullets asks for one — including the four just above,
the tab/mode-splitting rule in P21, and the admin-dispatcher-routing and
site-local-override questions further below. A short, consistently
located log is what makes "record which, and why" actually checkable
later, rather than trusting it happened somewhere in commit messages.

**Specifically verify these before calling this phase done** — easy to
miss, all real gaps found only by a dedicated audit last time, not by
the normal course of migration: every column that could leak a PHP
object via `serialize()` across a namespace move (check every
serialized-column type, not just ones already suspected — this found a
real bug in 5 different columns last time); the typed DTO/Projection
pattern (see the definition above, P19) applied consistently across
every namespace; per-namespace Unit
coverage for every domain, not just the obvious ones; cache-pool wiring
actually connected to real consumers (see P11); `die()`/`exit()`
elimination as a universal rule for every controller and middleware, not
scoped to image-processing paths alone — the private-album 200-OK bypass
example a few paragraphs below is a permission-check short-circuit, not
an image-serving one, and shows the risk is general — build every
response-producing path (image-serving included) to return a real
response object from the start rather than
`die()`/`exit()`-ing, so this isn't a retrofit; `reset()` arch-test
coverage for every request-scoped service (needed regardless of the
worker-mode decision, since it's good hygiene either way); a working
`repair-db` CLI command; and correct charset handling in install-time
constants (a real `PWG_CHARSET` bug shipped here last time) — reconcile
this explicitly with P16's "`define()` retired outright" policy:
`Install` runs before the DI container, config service, or even the DB
connection exist, so a small, fixed, named set of pre-bootstrap
constants (`PWG_CHARSET` and the install-time root-path constant, and
nothing else) is a legitimate exception to P16's rule, not a loophole —
keep that set minimal and enumerated in one place rather than letting it
grow by precedent.

**A few sharp, narrow hazards worth designing around explicitly rather
than rediscovering during this phase's own gap-closure work:** when
centralizing any family of `serialize()`-blob config/data columns onto
one JSON encode/decode point, check every raw-SQL site that bypasses the
normal repository layer (one such site was found splicing ids into a raw
`WHERE IN (...)` with zero parameter binding — a real SQL-injection
surface uncovered as a side effect, not a deliberate audit target), and
re-encode existing persisted data still in the old format via a one-time
script rather than letting it silently decode wrong; a raw `UPDATE`
(never an upsert) that "worked" only because a seed row always existed to
match is a landmine the moment that seed row is removed elsewhere — an
`UPDATE` against zero matching rows errors at nothing and just silently
does nothing — fix it with a portable pattern, not `ON DUPLICATE KEY
UPDATE` (MySQL-specific, in tension with this project's own
multi-provider commitment, see P15): select-then-insert-or-update inside
a transaction, or Doctrine's own `EntityManager::persist()` against an
entity fetched-or-new, which already resolves to the correct one of the
two branches at flush time without hand-writing either; before removing
a `JOIN` as part of a caching refactor,
enumerate every column the query actually selects from it, not just the
`WHERE`-clause condition that motivated the change — a JOIN can be
supplying both a filter and separate rollup columns at once; when
auditing "who still reads this legacy column," grep for the raw table/
column name too, not just the facade that's supposed to have replaced
direct access — a consumer that never adopted the facade won't show up
in a facade-name-scoped search; and before deleting a whole directory as
"now fully dead," check every file in it individually rather than
assuming directory-level homogeneity — a genuinely still-needed shared
helper living inside an otherwise-dead directory is an easy near-miss.
Separately, a real MySQL/MariaDB `JSON` column gotcha for any test
comparing a written object against what comes back: the engine reorders
object members by key length then lexicographically on write, so a
literal round-trip equality check against the original key order fails
even when the data is identical — `ksort()` both sides before comparing,
rather than special-casing key order per test. Relatedly: a column
retyped from `text` to native `JSON` re-serializes into MySQL's own
canonical form on write (e.g. a space after every comma), not the exact
bytes originally sent — a test asserting byte-for-byte equality against
`json_encode()`'s own PHP-side output is wrong the moment such a column
exists; decode both sides and compare structure instead.

**If `die()`/`exit()` elimination is ever done as a retrofit anyway
(rather than avoided from the start, per the bullet above), treat it as
security-critical, not mechanical.** Converting an `exit()`/`die()` call
to a thrown "response ready" exception can be silently swallowed by a
pre-existing, seemingly-unrelated broad `catch (\Exception $e)` block
somewhere between the call site and the intended top-level catch point —
a block that was only ever safe because `exit()` bypasses any enclosing
`catch` entirely. Before converting any such call, audit every enclosing
try/catch on the path for a broad catch clause; a prior attempt shipped a
real, live permission bypass this exact way (a denied private-album
request was silently logged and served 200 OK instead of being denied)
until a dedicated regression test written specifically to fail without
the fix caught it.

**Once any resource class (images, originals, exports, backups —
anything with per-file permissions) is served behind a permission-
checking script, find and re-route *every* code path that ever builds a
raw filesystem/URL link to that resource, not just the obvious main
one.** A prior attempt found the identical raw-permission-bypassing-link
bug in at least three separate places after fixing the first: a
derivative cache-hit fast path, a same-derivative redirect shortcut, and
the original-image inline `<img src>` builder — which also bypassed a
completely separate access-level check, permanently, the moment the raw
link was known or guessed. Grep for and re-route every real constructor
of a gated resource's raw path when introducing the gate, and verify each
one with a live, DB-backed private-fixture test, not code review alone.

**If web-root isolation (moving entry-point files under a `public/`
subdirectory) is done as a dedicated later move rather than from the
project's first commit, treat it as a real security-audit opportunity,
not just a mechanical relocation.** Audit every filesystem-path default
for CWD-relativity — a config default like `'./upload'` only "works"
because entry files happen to live at the repo root, and silently starts
writing data to the wrong (possibly still-web-reachable) place once entry
files move without every such default being made root-anchored first.
Deliberately decide, one directory at a time, which previously-reachable
paths (upload staging, gallery storage, site-local overrides, language
files, plugin directories, and **the config/credentials file itself** —
easy to omit from a list built around asset directories, but arguably
the single highest-value target for this exact bug class) should stop
being directly bridged/symlinked now that there's a real document-root
boundary, and verify each one's new unreachability against a real
on-disk fixture file, not just an absent symlink.

**Run a dedicated, exhaustive CSRF/authorization audit sweep across every
legacy admin mutating handler as its own explicit task early in this
phase, rather than relying on catching gaps opportunistically as pages
happen to get migrated one at a time.** This sweep is deliberately
scoped to admin handlers; P22 is where the equivalent decision for
frontend mutating handlers (comment/rating submission, private-gallery
password checks) gets made — don't assume it's covered here too. A
prior attempt found a CSRF or authorization gap independently, over and
over, as nearly every admin
page got individually migrated and fully read for the first time — a
core-self-update handler, a bulk-sync handler, a photo-reorder handler, a
caddie-empty action, an activate/deactivate cluster missing even the
webmaster gate, a reset-to-defaults action, and a raw `?tab=` local-file-
inclusion gap, among others. This is not a scattered handful of one-offs;
it's systemic across the legacy admin surface, and it was only caught
because literally every admin page eventually got fully read during its
own migration. Don't assume that will happen passively again. When fixing
a gap, verify **both directions live** (a request with no token rejected;
a request with a real token actually performs the mutation) — adding the
server-side check alone can silently break the feature if the
corresponding template link/form never embedded a token to begin with.

**Understand the exact mechanism behind "retiring `$GLOBALS` isn't free"
above, because it recurs constantly and is easy to reintroduce.**
Wrapping formerly-top-level-script legacy PHP inside any function or
method call frame (a controller's `__invoke()`, a dispatcher's
`handle()`, even an ordinary helper function) silently breaks every
`global $var;` statement in any file that gets further-`include`d from
inside that new frame — PHP's `include` only shares the *enclosing
function's* local scope, never `$GLOBALS`, unless the wrapping code
itself explicitly re-declares each such variable `global` before the
include runs. This bit a prior attempt independently at more than half a
dozen separate call sites across this exact phase and P22 before it, and
was still only partially swept by the time the phase was declared done.
Whenever legacy top-level script code is moved inside a new function/
method call boundary during this rewrite, explicitly check (with a real
throwaway repro if uncertain) whether it or anything it includes relies
on a bare `global $var;` for a variable the new wrapper itself needs to
set.

**Default to "no permanent facades" as policy from the start of this
phase, not as a later correction.** It's tempting to leave a handful of
free-function bridges (`l10n()`, `redirect()`, event-dispatch functions)
in place indefinitely once call-site count makes immediate retargeting
look impractical — a prior attempt did exactly this, then had to walk it
back under explicit direction once it became clear the "permanent"
label was being over-applied. Treat every free-function bridge introduced
during migration as inherently temporary scaffolding with an owner and a
retirement plan from day one; reserve an actual permanent exception only
for the few structurally-forced cases (a genuine cross-layer dependency-
direction conflict the architecture can't otherwise resolve, or a
dependency only constructible via DI-container resolution that the
migration's usual inline-construction pattern can't reach).

**When retargeting call sites mechanically (a reviewed codemod or scripted
find-and-replace), budget for these recurring hazards rather than
trusting one clean run:** a codemod's own cache can silently under-report
real matches after related code changes (clear it and re-run when in
doubt); the file whose function bodies are being deleted is usually
excluded from the "retarget calls" scope for good reason, but can still
contain its own internal self-references to the functions being moved,
which need a separate hand-fix pass; and a unit test that fakes out a
real dependency by relying on same-namespace function-shadowing breaks
silently once the call is retargeted to a class method — grep both
`tests/Unit/` and `tests/Integration/` for a shadow/stub of the same name
before applying a retargeting pass. After any such pass, diff the static-
analysis baseline before vs. after and investigate every delta that isn't
exactly the expected shape, not just "the count went down by roughly the
right amount" — this is how a namespace-resolution break and a silently-
corrupted `use` import were both caught last time.

**Design exception handling in the middleware pipeline (P9) to catch a
short-circuit response at every nesting level, not just the innermost
middleware.** A prior attempt's pipeline initially only caught this at
the innermost handler — a short-circuit thrown by an outer-ish
middleware was silently logged as an unhandled error, Sentry-reported,
and answered with a generic 500 instead of the real response, quietly
losing security headers and timing headers along the way. This is a
P9-phase design decision, not a P23-phase bug to find later.

**Open question resolved: `admin.php` becomes a real routed controller,
`AdminShell` does not survive.** The reference implementation already
did this conversion (its "Wave B" commits) — `admin.php` is a thin shim
into the same unified pipeline, backed by a real `AdminController
implements ControllerInterface` invoked through the same
`ControllerInvokerMiddleware` as every other route, which then resolves
to per-tab sub-controllers (`UsersController`, `ConfigurationController`,
`GroupsController`, etc.) rather than a monolithic tabsheet-dispatch
class. Adopt that shape: no `AdminShell`-equivalent living outside the
pipeline. The second half — whether legacy template-construction during
request finalization gets its own middleware or waits for the
typed-view-object work (P40/41) — stays a real per-implementation call
to make once P40/41's shape is concrete at that point in the rebuild;
record whichever way it goes there rather than presupposing it here.

**Open question resolved: arbitrary site-local config overrides are
*not* a supported feature — only a small, fixed, named set of
trust-boundary settings gets filesystem override, and it's already
designed.** The main worktree's `Config\DeploymentPolicy` (readonly
class, loaded from `local/config/config.php` returning `new
DeploymentPolicy(...)`) is exactly the narrow mechanism the prior
attempt's ambiguous end-state left half-built: it explicitly reasons, in
its own docblock, that lacking a settings-page checkbox alone doesn't
earn a key a spot on this class — only settings that gate a real
security/trust boundary do (`showPhpErrors`/`showPhpErrorsOnFrontend`,
`apacheAuthentication`/`externalAuthentification`, `allowedHosts` for
SEC-29's Host-header guard). Every other setting stays exclusively
DB-backed through `CurrentConfig`, with no DB row and no `ConfigService`
setter for anything on `DeploymentPolicy` — never both. Adopt
`DeploymentPolicy`'s shape as-is: a typed PHP file (not `$conf['key'] =
value;`, so a typo'd named argument throws immediately instead of
silently no-oping), one setting lives in exactly one place, and a
deployment that doesn't need to lock anything simply omits the file.

### Epoch F — Cross-cutting hardening to design in from the start

**Not a phase to schedule — a set of cross-cutting risks to design
against throughout Epochs C-E**, not clean up afterward. A prior attempt
let every one of these accumulate into dedicated later remediation work;
this epoch exists to name them so the earlier phases design around them
instead.

**Route error/JSON responses through the typed response system from the
start, everywhere — never a raw `die()`/`exit()` that assumes the
request format.** A prior attempt had several raw `die()` JSON calls
that always emitted JSON even when a client asked for a different
format, undiscovered until a late sweep. This is the same
short-circuit-response hazard P9's exception-handling design addresses
above (catch a short-circuit at every middleware nesting level, not
just the innermost) — a raw `die()`/`exit()` bypasses that whole catch
chain the same way an exception caught at the wrong level does, just via
a different mechanism. Enforce it the same way: a CI grep/PHPStan custom
rule banning `die()`, `exit()`, bare `echo`, and direct `header()` calls
anywhere outside the response-emission layer itself, not just a
documented convention.

**Before porting any legacy upgrade/patch-application code, check it
against the clean-fork stance first** — a prior attempt mechanically
ported an entire legacy
upgrade-mechanism subsystem (150+ files) before anyone caught that it
contradicted the project's own no-in-place-upgrade design. **The real
danger isn't just building it once — it's that it can sit unnoticed
through multiple further phases of real, wasted follow-on investment**
(a full OOP port, then a whole security-hardening sweep, all landed
*after* the contradiction should have been obvious) before a dedicated
architecture audit finally catches it and it gets deleted wholesale.
Once such a contradiction is caught anywhere, don't assume "we'll circle
back" — re-verify immediately, since the same accidentally-built
subsystem keeps absorbing real effort the longer it survives.

**If any `$GLOBALS`-style ambient state accumulates despite P8's
DI-first discipline, retire it smallest/lowest-risk first, ordered by
call-site count and by whether a clean typed target already exists**:
template state, language, user, and config each already map onto an
existing typed service by this point in the build, so retire those
first; page-level request state doesn't — it's the one kind of global
without a clean existing typed target, so expect real design work here,
not a mechanical retarget. The concrete pattern to design toward: a
PSR-15 middleware early in P9's pipeline sets it as a request attribute,
and every consumer resolves it via a request-scoped DI binding (P8) —
never a second, parallel `$GLOBALS`-style accessor rebuilt under a new
name. Retire any remaining smaller stragglers after that, then the
bootstrap seeding mechanism itself last. **Watch for a "trivial" retarget
that's actually silently broken, and treat this as a standing rule, not
a one-off lesson**: a prior attempt's current-user accessor had never
actually worked for real (logged-in) users — it only ever returned a
guest placeholder — undiscovered until this exact retirement pass
finally exercised it for real. Don't assume an existing accessor works
just because nothing crashes; every retarget in this list needs a real
test against actual non-default data (a logged-in, non-guest user; a
non-empty language/config value), not just a happy-path smoke test.
Completion criterion: zero `$GLOBALS` grep hits in `src/` outside a
documented, reviewed allowlist.

**Use `===`/`!==` from the start, everywhere — but when a later sweep is
ever needed anyway (a legacy file ported verbatim, a vendored/frozen
script), never treat it as a blanket find-and-replace.** A prior
attempt's dedicated loose-to-strict sweep needed real per-site type
verification against a specific checklist of PHP type-juggling hazards,
not a mechanical `==`→`===` substitution: float vs int (`0 == 0.0` but
`0 !== 0.0` — cast both sides to the same type first); null vs
empty-string (`'' == null` is true, `'' === null` is false — normalize
via a consistent `?? ''`/`?? null`, matching the *original* truth table);
bool-coercion side effects (a loose comparison against a string sentinel
can accidentally also accept any truthy bool/int, via PHP casting the
other side to match — decide deliberately whether that was an intentional
feature before removing it); array vs null (`[] == null` is true,
`[] === null` is false — only matters if the array side can genuinely be
empty); and the "0 vs absent" trap specifically for count/score fields —
`0 != null` is *false* under loose comparison, silently treating a real
zero the same as "never happened," so the fix there is `!== null`, not a
blind swap. HTML form values are strings the same way DB row values are:
a `$_POST['flag'] == 1` checkbox-style check needs `$_POST['flag'] ===
'1'` (matching that field's own real string default), not `=== 1`. This
checklist isn't limited to hazards containing a literal `==`/`!=` token,
either: `switch`/`case` matching uses the same loose-comparison
semantics (a `case '0':` arm silently also matches integer `0` and
boolean `false`) and needs the same per-arm type audit; `in_array()`/
`array_search()` default to loose comparison unless called with an
explicit third `true` (strict) argument, so any call searching a
mixed-type haystack for a string/int needle needs that argument added,
not just the surrounding `==`/`!=` calls; and `array_unique()` defaults
to `SORT_STRING`, casting every element to a string before comparing, so
a `[0, '0', false]`-shaped array silently collapses to one element
unless `SORT_REGULAR` is passed explicitly where that's not the intent.
Separately: `is_callable()` on a bare *string* only ever resolves a free
function or a static `Class::method` reference — never an instance
method. Any design storing a "callback to invoke later" as a plain
string (a persisted correction-handler id, a dynamic dispatch table) and
later checking `is_callable()`/invoking it against an instance method
silently, permanently fails with no error — just a real feature that
quietly never runs. Store a real `Closure` (or a `[$object, 'method']`
callable array) instead of a bare string the moment the target is an
instance method. Adopt `phpstan-strict-rules`' `disallowedLooseComparison`
rule as a permanent CI gate once this sweep completes, so a new loose
comparison can't reappear silently, and track the sweep itself against a
real artifact (a checklist file or tracked issue enumerating every
flagged site and its resolution) rather than tribal memory that "the
sweep happened."

**When retargeting any free function onto a real class, specifically
audit Deptrac visibility as part of that change, not after.** A
free-function call creates no Deptrac dependency edge, but the direct
class reference a retarget introduces does — a prior attempt found over
a dozen real cross-layer callers that had been completely invisible to
Deptrac until a retarget made the dependency literal. Same class of
blind spot as a tool silently mis-modeling hyphenated layer names (P6):
whenever a refactor doesn't move an automated check the way it should,
suspect the tool's model of the code, not just the code. When such a
newly-visible cross-layer caller turns up, the default is to fix the
caller — move it, or route it through the correct layer's public API;
carve out a Deptrac exception only via a documented, reviewed
`deptrac.yaml` entry explaining why the dependency is structurally
forced, the same bar Epoch E's own DI-retirement guidance sets for a
permanent exception. Verify the retarget's actual effect by running
`deptrac analyse` immediately before and after each retarget batch and
diffing the violation counts — a rising count that isn't triaged
immediately is exactly how a dozen invisible callers accumulate
unnoticed in the first place.

**Route every `$_POST`/`$_GET`/`$_REQUEST`/`$_FILES` superglobal read
through a typed Request DTO from the start — one per action or param
cluster — and enforce zero raw superglobal reads outside that layer
with an arch test from day one**, not as later remediation. **Hydrate
every Request DTO from the injected PSR-7 `ServerRequestInterface`
(`fromRequest(ServerRequestInterface $request)`, reading
`getParsedBody()`/`getQueryParams()`/`getUploadedFiles()`), not from a
`fromGlobals()`-style factory that reads superglobals a second,
independent time.** A controller already receives `$request` per SEC-19
(P21's own `AdminSubControllerInterface` contract, "reading input from
the injected PSR-7 request rather than `$_GET`/`$_POST` directly") — a
DTO that reads superglobals itself, even inside its own sanctioned
hydration layer, reopens exactly the bypass SEC-19 exists to close one
level down, and makes the DTO impossible to unit-test without mutating
real superglobals. `fromRequest()` fixes both: the controller's own
`$request` is the DTO's only input, and a test constructs a fake
`ServerRequestInterface` directly, no superglobal manipulation needed.
`$_FILES`
needs its own typed shape (upload field name, tmp path, size, and PHP's
own upload-error code, not a raw associative array indexed by field
name) — it's core to every upload flow and carries the same
unvalidated-input risk as `$_POST`, so it belongs in this rule
explicitly, not treated as a special case exempt from it. List
`$_REQUEST` among the superglobals this rule forbids outside the DTO
layer too — it's easy to treat as a harmless GET/POST union, but it
silently also merges `$_COOKIE` values in, widening the same injection
surface this rule exists to close. A prior attempt found real bugs from
raw superglobal reads that a DTO layer would have prevented structurally:
a SQL injection via a raw category-id read, a stale dead write, and
moderation state that belonged in typed page state instead of `$_POST`.
That same category-id bug's root cause is its own general audit
checklist item: a `trigger_error(..., E_USER_ERROR)` call is **not** a
halting guard by itself in a codebase whose own error handler intercepts
`E_USER_ERROR` and lets execution continue (the same mechanism a
`fatalError()`-style helper works around by throwing afterward) — the
original code's `isset(...) && is_numeric(...)` check called
`trigger_error()` on failure with no following `return`/`throw`, so the
very next line ran anyway and built raw SQL from the unvalidated value.
This isn't a DTO-layer-specific rule — the same halting-guard gap can
hide behind any code path anywhere that signals failure by throwing or
returning after a guard clause, not just Request DTO validation, so
audit every `trigger_error(E_USER_ERROR, ...)` call site in the whole
codebase against it, not just the ones this phase's own DTO layer
introduces; never assume the error handler alone stops execution.
Enforce both this and the zero-raw-superglobal-reads rule above with a
concrete day-one tool — a custom PHPStan rule (or a `phpat` architecture
test) failing the build on either violation — not a manual grep run
occasionally. Checkbox-array POST fields need their own explicit DTO
pattern for the same reason: an HTML checkbox that's unchecked sends no
key at all, not a `false`/empty value, so a naive "read what's present"
DTO hydration silently preserves whatever the field's *previous* stored
value was instead of clearing it — a known recurring bug in this
codebase's admin forms. Default every DTO-hydrated checkbox-array field
to an explicit empty/false baseline before reading `$_POST`, so "field
present with values" and "field absent because everything was unchecked"
both produce a real, deliberate value instead of the absent case
silently falling through to a stale one. Relatedly, never let a
superglobal read leak into
a domain/service-layer method as an ambient side-channel — a permission
service read `$_POST` directly to decide whether to cascade a grant, and
its WS caller (which had no `$_POST` of its own for that field) worked
around it by *writing* to `$_POST` purely to feed that read; the fix is a
real parameter on the service method, not a caller-side global mutation.
And in any recursive dispatch (a WS method invoking another WS method
internally), an authorization/method check must use the actual
already-known method name being invoked as a real parameter — re-deriving
it from `$_REQUEST`/ambient request state inside the check silently
authorizes against the *outer* HTTP request's method name instead of the
inner recursive call's.

**When converting raw superglobal reads to typed Request DTOs, trace the
full read/write *timeline* of each superglobal within its method, not
just each read site in isolation.** Legacy code repeatedly uses an
in-place `$_POST[...] = ...`/`unset($_POST[...])` mutation as an
intra-request communication channel — a value written partway through a
method specifically so a *later* read in that same method (or a call it
makes) sees the override (simulating a full form submission from a
shortcut GET param, marking a field "consumed" for a later `isset()`
check, synthesizing a token a downstream layer depends on reading raw).
Replicate this as a local mutable "working copy" the caller builds and
threads through — never by mutating the real superglobal from inside a
DTO. Separately, preserve "key absent" and "key present but the wrong
type" as two distinct signals rather than collapsing both into one
nullable field: whenever the original branched on mere presence (`isset()`
alone, independent of the value's type), a malformed present value must
still read as "present," not silently look identical to "absent." Give
this a concrete shape rather than leaving it ad hoc per DTO: either a
small discriminated result per field (`Absent` | `Invalid` | `Valid`
cases, via a first-class enum or a tagged-union value object) or a
dedicated `Undefined` sentinel distinct from `null` (mirroring the
distinction TypeScript's `undefined` vs `null` makes, applied to PHP
property hydration) — pick one convention and apply it uniformly across
every Request DTO, not a different ad hoc shape per field. And expect
the "zero raw superglobal reads outside a Request DTO" arch test to need
a real, individually-documented allowlist rather than literal 100%
enforcement — a handful of sites are genuinely load-bearing raw reads
(an in-place rewrite meant for a later `fromRequest()` call downstream,
a minimal existence-only check not worth a full DTO). Make the allowlist
itself the arch test's actual machine-checked input — a committed list
of `file:line` or fully-qualified-method entries the test reads and
diffs against, not a comment or a separate markdown doc the test can
silently drift from — so a new raw read only passes CI by being added
to that list explicitly, in the same diff, keeping every exception
visible in review.

**Watch for "errors collected into an array but never actually checked
before the mutating action runs" as a recurring form-handler bug shape.**
A prior attempt's registration flow appended a password-mismatch error to
`$errors` but the very next, unconditional line created the account
anyway — only the final redirect checked whether `$errors` was empty, by
which point the account already existed. Any "validate into a collection,
then act" handler needs an explicit gate immediately before the mutating
call, not just before the response. Prefer a structural fix over a
discipline-only gate wherever the validation shape allows it: a
validator that throws a single aggregating exception (a
`ValidationException` collecting every failure, raised via a
`throwIfAny()` call at the end of the validation pass) makes the
mutating code after it unreachable on any failure by construction,
instead of relying on every handler remembering its own explicit `if
(!empty($errors)) { return; }` gate. Where a plain errors array is still
needed for the response's own field-level display, build it from that
same exception's attached errors rather than a second, independently
collected array — so it's built exactly once and can't be checked in one
place and forgotten in another. Back this with a blanket integration-test
rule for every mutating handler in the suite: submit deliberately invalid
input and assert zero mutation occurred (no row created/updated/deleted),
not just that an error was displayed.

**Distinguish a genuinely-defended security boundary from ordinary
malformed/stale input when wording a validation-failure message, and
design the DTO layer's default rejection message accordingly from the
start.** A blanket accusatory "Hacking attempt!"-style message on every
validation failure presumes intent a simple type/pattern/shape mismatch
can't actually verify — a stale language/theme cookie pointing at a
since-removed pack, a syntactically-valid id for a since-deleted
resource, or a value that just doesn't match an expected regex are all
routine, non-malicious states, and greeting them with hostile wording is
a real (if minor) UX defect at genuine scale, since a single shared
validation-failure path fires from dozens of call sites. Reserve firm,
explicit rejection wording for the sites that actually defend a real
security boundary (path-traversal guards on filename/path-segment
interpolation); default every other DTO-layer validation failure (wrong
type, non-matching pattern, missing mandatory value, an unrecognized
enum-like value) to neutral wording ("Invalid request parameter"/
"Unrecognized value for parameter"). Also: a syntactically well-formed
id that simply doesn't reference an existing resource (a deleted
album/category) is a not-found case, not a validation failure at all —
route it to a real 404 path, not the same rejection a malformed id gets.
Both the neutral and the firm wording are real, translatable strings —
route both through the same translation catalog as every other
user-facing message, with no hardcoded English literal for either, since
a validation failure is exactly as reachable by a non-English user as any
other page. The wording change is UX-only; back it with a real severity
differentiation in P10's logging design so demoting the wording doesn't
also demote how seriously the event is treated operationally — a routine
neutral-wording rejection logs at a low level with no alert, while a
genuine security-boundary rejection (the path-traversal class above)
logs at a level that actually pages/alerts. Acceptance criterion: an
enumerated list of every DTO validation-failure site in the codebase,
each asserted by an integration test to return the correct one of
neutral wording / firm wording / a real 404, not spot-checked.

**Establish a `{Domain}/Projection/` typed-DTO convention early (P17-20)
and apply it consistently as each domain is built, not audited for gaps
afterward** (see P19's own definition above: a Projection is a
readonly, per-query DTO with real typed properties, one type per
distinct query shape — this convention is that definition applied
domain-by-domain, not a separate concept). Track the metric that
actually matters — a repository or service method still declaring a raw
`mixed`/untyped-array return where a sibling typed Projection already
exists for that data — not a raw `mixed`-token count, which has a large
legitimate by-design residual (DBAL scalar-narrowing closures,
value-object `tryFrom()` methods, vendor-dictated type-conversion
signatures, RPC protocol params, logger context) and is the wrong thing
to chase toward zero. Make that metric something a tool actually tracks
rather than a mental checklist: a custom PHPStan rule flagging any
repository/service method whose return type is `mixed`/`array` while a
Projection class already exists in that domain's own `Projection/`
namespace is the precise, automatable version of this; where that's not
practical for a given case, fall back to a periodic manual audit (once
per domain, at that domain's own completion checkpoint) instead of never
checking at all.

**Design typed homes for `$_SESSION`/`$_SERVER`/`$_COOKIE` access up
front (P9/P16) and route every access through them from the start** — a
session value object, a cookie service, and a server-request facade
alongside it. A prior attempt built the typed homes but didn't enforce
using them, so direct `$_SESSION` access grew over time instead of
shrinking. One concrete, reusable design: a flash-message pair (page
info/error messages that must survive exactly one redirect) maps
cleanly onto a `FlashBag`-style `add()`/`consume()`/`peek()` API — build
that shape from the start rather than raw `$_SESSION` keys. Don't repeat
the same mistake the superglobal-DTO rule above exists to prevent:
enforce this with the identical day-one arch-test mechanism (a custom
PHPStan rule/`phpat` check) banning direct `$_SESSION`/`$_SERVER`/
`$_COOKIE` access anywhere outside these three typed homes, not a
documented convention alone — a convention alone is exactly what let
direct `$_SESSION` access grow back in the prior attempt this paragraph
already describes.

**Consume DB rows via typed Projection objects everywhere, including
page renderers and controllers, not just repositories** — the same
`{Domain}/Projection/` convention above, extended past the repository
boundary it's most naturally associated with. Raw string-keyed
row-array access anywhere in the request path is exactly the kind of
thing that's cheap to prevent structurally and expensive to retrofit
later (two-thirds of a prior attempt's remaining instances were in
renderers/controllers running inline SQL, not repositories at all). This
scope extends into template-facing data too: a Projection flowing into a
controller shouldn't get flattened back into an untyped array at the
`XxxView` boundary P40 builds — the View object's own typed properties
should be built from the Projection directly, keeping the typing intact
end to end from query to template (cross-reference P37/P40/P42, whose
typed page-data/view-object work is where this either holds or quietly
breaks). Track it with the same PHPStan-rule metric named above,
extended to renderer/controller/View call sites, not repository methods
alone.

**A handful of narrower, concrete gotchas worth designing around rather
than rediscovering — flagged here because they surfaced during
cross-cutting hardening work, even though each one's natural home is a
specific earlier phase (the mailer timeout belongs with P11's messenger/
transport build; the PHPUnit/Pest quirks below belong with P2's harness
design) and should be re-verified there when those phases actually
land:** any synchronous external-process-backed transport (a mail
sender's default local-`sendmail` DSN, any subprocess-based integration)
needs an explicit bounded timeout — Symfony Mailer's `native://` DSN
resolves to an unbounded `proc_open()` around the system `sendmail`
binary with no timeout at all, and a slow/unreachable local MTA blocked
an entire synchronous HTTP request for minutes; wrap any such transport
in an explicitly `setTimeout()`-bounded subprocess mechanism instead of
trusting the vendor default. **Concrete default, decided here rather
than left open: a 10-second `setTimeout()` on the sendmail subprocess**
(generous enough for a healthy local MTA to accept a message, short
enough that a hung MTA doesn't visibly stall the request), with the send
routed through P11's Messenger transport asynchronously wherever the
call site can tolerate it, so even a timeout-bounded synchronous send
doesn't block the HTTP response — the timeout is the hard backstop,
async dispatch is the actual fix. When spawning a background
subprocess meant to be tracked and killed later (a Playwright/dev-server
process under test tooling, any long-lived helper), construct it from an
argv array rather than a shell command string — a shell-string spawn gets
tracked by its wrapping shell's PID, not the real child's, so a clean
`SIGTERM` kills the shell while the actual process survives as an
orphan. **Elevate this next one into a named, standing practice, not a
one-off tip — call it the independent re-audit pass, and cross-reference
it from every mechanical sweep in this document (P6's tool-model audit,
P14's raw-SQL migration, F3's `===`/`!==` sweep, F5/F6's superglobal-DTO
conversion):** after any large mechanical sweep/migration claims
completion (moving raw SQL out of non-Repository classes, converting
every superglobal read, etc.), run one fresh, independent grep audit for
the exact same pattern before trusting it's actually done — a prior
attempt's own "complete" raw-SQL migration had a follow-up audit turn up
9 more files the first pass missed. On the test-tooling side:
`@`-suppression does not stop PHPUnit's `ErrorHandler` from surfacing
warnings/deprecations under
`failOnWarning`/`failOnDeprecation` — use `set_error_handler()` for any
genuinely-expected warning instead; and `self::assert*()` static-method
narrowing doesn't work inside Pest's global `it()`/`test()` closures
(only inside a real PHPUnit `TestCase` subclass) — use plain `if`-guards
or a shared assertion helper there instead. Watch for this specific
failure shape whenever the PHP runtime version itself gets bumped under a
suite running `failOnWarning="true"`: a new PHP version can introduce a
brand-new warning class for code that was silently fine before (e.g. PHP
8.5's new warning for a no-op `use SomeGlobalClass;` import in a
namespace-less file) — under strict warning-as-failure gating, *one*
previously-invisible warning anywhere in the whole suite can flip the
entire test run's exit code nonzero with **no attributable failing test
shown anywhere in the output**, since it's the runner's own warning
collector failing the run, not any individual assertion. If a suite run
reports a nonzero exit with zero visible failures after a PHP version
bump, suspect exactly this before assuming test-runner corruption or a
flaky infrastructure issue.

### Epoch G — REST API (P25–P27, collapsed to one design+build phase)

**Skip the three-phase modernize-then-replace path a prior attempt took
— go straight to `/api/v1`.** That attempt first modernized the legacy
`ws.php` WS layer's internals while preserving wire compatibility (its
own P25), then moved UI-facing methods off the envelope (P26 — a step
that simply holds by construction here: with no legacy WS envelope
ever built in this rewrite, there's nothing to move methods off of),
then finally built REST and deleted the WS layer (P27) — a sensible
sequence
*only* because production wire compatibility mattered mid-transition.
Starting fresh, nothing depends on the old wire format yet, so design
and build the REST API directly. Keep the one genuinely valuable
front-loaded step: **do the audience/security audit first, as its own
research pass, before writing REST code** — it's cheap and it reshapes
the design.

**Audit first.** A prior review of the legacy `ws.php` surface found it
served two audiences through one interface: most registered methods were
really admin plumbing exposed as a public contract, and of the rest,
about a third were reachable from the first-party UI while the real
external/machine surface was much narrower — auth, browse, upload,
image metadata, favorites. Do the equivalent audit against `origin/16.x`
before designing routes, so the REST API's domain boundaries reflect
real usage, not the legacy method list 1:1.

**Produce a concrete artifact from this audit, not just a research
pass**: enumerate every legacy `ws.php` method in `docs/api-audit.md`,
tagged with its audience classification (admin-plumbing / UI-reachable
/ real external-machine surface) and its P27 disposition (kept as a
REST route, folded into a broader one, or dropped as internal-only).
"100% of methods classified" is this step's own acceptance criterion,
not "audit performed."

**Resolve the apparent tension before it reads as scope creep** back
into the anti-pattern this section opens by rejecting: the narrow
external-surface finding above (auth, browse, upload, image metadata,
favorites) describes what a *machine-only* client actually exercised,
while P27's own domain list further below is wider because it also
carries every method the audit finds genuinely UI-reachable — REST's
real scope is "everything real usage across both audiences needs," not
"the machine-only slice alone." State that distinction in
`docs/api-audit.md` itself, not only here.

**Decide the third-party-consumer question explicitly** rather than
leaving it silent, and log it in `docs/DECISIONS.md` alongside P23's
other "decide explicitly" entries (see above): this rewrite ships no
wire-compatible `ws.php`, so any existing third-party client speaking
that legacy protocol — including Piwigo's own official mobile/desktop
apps — is broken by this API by design, with no compatibility shim
planned. Migrating those clients onto `/api/v1` is explicitly out of
this phase's scope.

**Structurally prevent these seven vulnerability classes in the new
REST layer from the start** — there is no live `ws.php` file in this
rewrite to patch; these are findings from the security audit of the
legacy/reference implementation (`origin/16.x`) above, and the new code
must never reproduce them, not a retrofit onto code that doesn't exist
here. All seven were real, found only by a dedicated security-focused
review of the legacy WS layer, not by routine development:

1. A global blanket `addslashes()` on every superglobal, every request —
   real data corruption (`O'Brien` stored as `O\'Brien`), not just a
   style issue (SEC-10). Don't reintroduce global request-sanitization
   at all — use bound parameters (see P14/P17-20's guidance).
2. An API-key-authenticated session getting laundered into a fully
   unrestricted session via an upload-related connection-type
   overwrite. Design connection/auth-context tracking so an
   API-key-scoped session can never be silently upgraded.
3. A chunked-upload endpoint writing a file from unvalidated
   client-supplied params — an authenticated arbitrary-directory write.
   Validate every upload-path-deriving parameter server-side, never
   trust a client-supplied checksum/type for path construction.
4. An admin action bypassing a webmaster-only gate a sibling endpoint
   already enforced — audit every mutating admin endpoint for consistent
   authorization, not just spot-checking a few.
5. A CSRF token doubling as an unrelated "allow HTML" flag on some
   mutating methods, one of them GET-reachable. Keep CSRF verification
   and any other request flag structurally separate from the start.
6. CSRF token comparison via `!==` instead of `hash_equals()` (SEC-12) —
   build every token comparison on `hash_equals()` from day one, and
   specifically check for a second, independent copy of the same check
   living in a different layer (this exact bug was fixed in the main
   auth services well before it was found, separately, in the API
   layer's own copy).
7. Shell-out calls (`exec()`) with unescaped arguments (SEC-16) —
   prefer spawning via an explicit argv array (Symfony `Process`, the
   same argv-array construction Epoch F specifies for subprocess
   spawning just above) over building a shell command string at all.
   Where a raw `exec()`/shell-string call is genuinely unavoidable,
   apply `escapeshellarg()` at every such site as a hard rule, not
   case-by-case — but treat the argv-array form as the preferred fix
   and `escapeshellarg()` as the fallback, not the default.

**Build the REST API to this validated shape directly** (this is what a
prior attempt arrived at after the full three-phase migration — build it
as the day-one target, not an eventual destination):

- One typed `*Controller` class per real REST operation, each with a
  typed `*Input` DTO for its request body — never a shared dispatch
  god-method or `array $params` indexed by string key.
- Routes organized by real domain (categories, comments, extensions,
  groups, history, images including filtered search,
  session/preferences/API-keys/favorites/caddie, tags, uploads, users),
  sized from the audit above, not the legacy method list.
- Uploads via the tus 1.0.0 chunked-upload protocol (a handful of
  dedicated controllers), not a bespoke multi-method chunk-upload
  protocol. On tus completion, dispatch post-processing (derivative
  generation, metadata extraction) as a Messenger job (P11) rather than
  running it synchronously in the completion request — a batch upload
  shouldn't hold the HTTP connection open for the slowest file's
  processing. Validate the completed upload's real content type by
  sniffing file content server-side, never trusting the client-declared
  MIME type or file extension alone (the same discipline vuln class 3
  above requires for upload-path parameters), and enforce a per-user
  upload quota (storage bytes and/or file count), checked before
  accepting new tus chunks, not only after the upload completes.
- Standard REST list-endpoint conventions, decided here rather than
  invented per route: offset-based `page`/`per_page` query parameters
  (consistent with the `OFFSET`-pagination tiebreaker convention already
  required above for internal queries), `per_page` capped at a fixed
  maximum — 100, rejecting anything higher with 400 rather than silently
  clamping it — and `sort`/`filter` parameters validated against an
  explicit per-route allow-list of sortable/filterable fields, never
  passed through to the query builder unchecked.
- An explicit CORS policy scoped to the `/api/v1` mount: an allow-list
  of trusted origins for browser-based clients, never
  `Access-Control-Allow-Origin: *` on an authenticated route — kept
  independent of SEC-43's separate question about the OpenAPI spec's own
  serving path.
- Conditional-GET support (`ETag` + `If-None-Match`) on cacheable read
  routes (category listings, image metadata), returning a bodyless 304
  on a match — real bandwidth savings for gallery-browsing clients doing
  repeat/polling reads.
- Every error response as RFC 9457 `application/problem+json`, both for
  routing-level 404/405 and for uncaught exceptions app-wide (SEC-36/
  SEC-37) — one exception-handling middleware, not per-controller error
  formatting.
- A real authorization guard (401 vs 403 distinguished) injected into
  every route that needs one (SEC-38), decided per-route at design time,
  not audited in afterward. Back this with a CI-gated authorization
  matrix test, generated from the route list itself, asserting the real
  expected status (401 unauthenticated / 403 wrong-permission / 2xx
  authorized) for every registered route — so a newly added route with
  no corresponding assertion fails the test, rather than shipping
  unaudited the way spot-checking a handful of routes by hand would
  allow.
- `Content-Type: application/json` validated on every non-empty request
  body at one choke point, rejecting anything else with 415 (SEC-39).
- An OpenAPI 3.2 spec hand-authored from the real controller/DTO/service
  source (not generated from route reflection, which drifts silently),
  gated in CI by a spec linter (Spectral, with a project-specific
  ruleset) and a structural test confirming the spec matches real
  routes; a generated TypeScript client regenerated and diffed in CI to
  catch drift; runtime contract enforcement against real request/
  response pairs in Browser-tier tests, with per-operation coverage
  tracked so "spec exists" isn't confused with "spec is exercised."
- An opt-in `Idempotency-Key` replay store on mutating routes (excluding
  tus, whose own resumability protocol already covers retries) — a
  replay cache, not cross-process locking; decide explicitly that
  concurrent-duplicate-request locking is out of scope rather than an
  unnoticed gap. Store replay records in a dedicated `idempotency` named
  cache pool, following P11's own named-pool convention, with a bounded
  24-hour TTL rather than the general-use pool — long enough to cover a
  realistic client retry window, short enough not to accumulate
  unboundedly. Key each record on the `Idempotency-Key` value *and* a
  hash of the request body: a replay with the same key but a different
  body hash is a distinct request, not a retry, and must be rejected
  with 409 rather than silently served the first request's stale
  response.
- Rate limiting is deliberately absent from this list — it's genuine
  P28 scope, building the `rate_limiter` pool there (see Epoch H), not
  an earlier gap.

**Skip the legacy-WS-layer intermediate entirely**: no god-class
registration method, no recursive dispatch pattern, no `Pwg`-prefixed
class names to later rename, no `public/ws.php` entry point to delete —
build directly against the REST shape above from the first commit in
this phase. (P23's own deletion checklist never lists `public/ws.php`
for exactly this reason: this rewrite never creates one, so there is
nothing there for P23 to delete.)

**General lesson worth applying to this and any later large-scale
subsystem deletion in this codebase (a legacy compat surface, a
superseded internal layer): a "verified safe to delete" claim can go
stale between when it was made and when the deletion actually happens,
in two specific, easy-to-miss ways.** First, code built to *replace* the
subsystem can still develop real, load-bearing dependencies back onto
the old subsystem's own shared utility/helper classes (an error-response
value object, a URL/query-criteria builder) even though the
subsystem's own public-facing surface was fully superseded — a
full-surface rewrite doesn't guarantee every small shared helper
underneath it was independently duplicated or migrated first. Grep the
*new* replacement code for imports reaching into the old subsystem's own
namespace specifically, not just for remaining callers of the old
subsystem's own public entry points, and move (not delete) anything
still genuinely depended-on to a real domain namespace before the bulk
deletion. Second, test helper infrastructure can quietly borrow the old
subsystem as generic, unrelated fixture-creation plumbing (a
convenient way to create realistic test data) far more pervasively than
"tests that actually exercise the subsystem" would suggest — audit test
helper functions for this borrowed-as-plumbing dependency specifically,
and give them a like-for-like replacement (calling the same real domain
operation through its new interface) rather than leaving them broken or
skipping the audit because "those aren't really testing the old thing."

**Any full-page-takeover rendering mechanism (an onboarding/empty-state
screen that replaces the normal page for every request under some global
condition) must exempt the machine-facing API surface as a structural
rule, not a per-route decision.** A JSON API context and an HTML page
context are different response *kinds*, not just different routes — a
takeover mechanism built before the REST API existed, whose exemption
list only knew about page-rendering variants (an admin context, a
handful of pre-install page basenames), silently intercepted API
requests too once the API existed, including the login endpoint itself
— an admin couldn't authenticate through the new API-backed frontend at
all under the exact global condition (an empty gallery) the takeover
exists to handle gracefully for page requests. Check for "is this
request machine-facing at all" once, ahead of any narrower per-route
exemption list, on any such mechanism.

**Two routing/deployment gotchas worth designing around from the start
rather than discovering live:**

- **Don't add a literal catch-all route for custom 404 handling — it can
  silently shadow a real route's own 405 (Method Not Allowed) response.**
  A routing library's own `MethodNotAllowedException` typically only
  fires when *no* route matches the request path at all under any
  method; a catch-all route matches the path under every method,
  including the wrong one, so it wins the match before the router ever
  gets the chance to notice a real route existed for that path under a
  *different* method. Verify a deliberately wrong-method request against
  a real registered route still returns 405, not whatever the catch-all
  produces, as an explicit test case — this is exactly the kind of gap a
  "does 404 work" test alone won't catch.
- **Reuse the exact mount-stripped request path the routing middleware
  itself already computed for a route match — never let a second piece
  of middleware independently re-derive "the path" from the raw request
  URI.** If the application can be deployed under a non-root path (a
  subdirectory mount, a reverse-proxy path prefix — already a stated
  requirement elsewhere in this app for asset URLs), any code re-deriving
  its own idea of "the current path" from the untrimmed URI silently
  no-ops or misbehaves the moment a real deployment isn't mounted at `/`,
  invisible in every local dev/test environment that happens to run at
  the root. Expose the router's own already-computed, already-correct
  path as something a later middleware can read directly, rather than
  parsing `$_SERVER`/the request URI a second time.

**When a legacy method-registration flag's own name implies a narrower
guarantee than what its real enforcement code actually checks, trust the
enforcement code, not the name — every time, not just once.** A
concrete, recurring instance: the legacy WS layer's per-method
`requiresAuth` flag reads as "must be logged in," but its one real
enforcement site actually gated the method behind full admin status —
a materially different, stricter check than the name suggests. Porting
a `requiresAuth: true` WS method onto its new REST route by assuming the
flag's own name is correct produces a real authorization gap (a
too-permissive guard, silently under-protecting a mutating endpoint).
This is exactly the kind of mistake that's easy to make *again* on the
next handful of methods even right after fixing it once on an earlier
one — verify the real enforcement path per method being ported, not by
pattern-matching the flag name against methods already confirmed.

**Specifically verify these before calling this epoch done**, the same
"verify explicitly, don't trust a feeling of completeness" discipline
P23 applies to its own deletion checklist: `docs/api-audit.md` shows
100% of legacy methods classified with a stated P27 disposition; the
OpenAPI 3.2 spec lints clean and its structural test confirms it
matches every real registered route; the generated TypeScript client
has been regenerated and its diff actually reviewed, not just re-run;
Browser-tier contract tests exercise every operation the spec declares,
with per-operation coverage tracked rather than assumed; the
authorization matrix test passes for every registered route; and a
repo-wide grep confirms zero `Pwg`-prefixed classes, zero
`public/ws.php`, and zero other legacy WS-envelope artifacts anywhere
in the tree.

### Epoch H — Security (P28)

**P28 — Security hardening.** WebAuthn/passkeys, OIDC SSO, nonce-based
CSP, COOP/COEP, CSP reporting, rate limiting (build the `rate_limiter`
cache pool here — see P11 — it's genuine P28 scope, not an earlier gap).
Twelve `SEC-NN` items land in this phase — SEC-41 (Argon2id), SEC-42
(no blanket CSRF exemption), SEC-43 (no wildcard CORS on the OpenAPI
spec), SEC-44 (rate limiting), SEC-45 (CSP reporting), SEC-46
(COOP/COEP), SEC-47 (`Vary: Cookie`), SEC-48 (`allow_html_descriptions`
default), SEC-55 (OIDC PKCE/state/nonce), SEC-62 (Trusted Types), SEC-63
(Fetch Metadata), SEC-74 (DB-level account locking) — plus one mitigation
tracked only in the threat model below, not as a numbered item: DB
password rotation via MySQL dual passwords (`docs/REFERENCE.md`'s Secret
rotation section has the runbook). The sub-items below cite the relevant
`SEC-NN` inline, the same convention every other epoch narrative already
follows.

- **Argon2id (SEC-41).** Use PHP's native `password_hash()`/
  `PASSWORD_ARGON2ID`, but override its cost parameters rather than
  keeping PHP's own defaults (`memory_cost=65536` KiB) — pin to OWASP's
  baseline instead (`memory_cost=19456` KiB, `time_cost=2`,
  `threads=1`), chosen explicitly to bound per-request memory under
  concurrent login load. A default worth revisiting once a real
  login-throughput number exists, not a researched optimum.
- **CSRF exemption rule (SEC-42).** The CSRF check applies to every
  state-changing request regardless of URL prefix, admin routes
  included — no route ever opts out by path. The one legitimate
  exemption is narrower and orthogonal to path: a request authenticated
  purely by API key (Epoch G's REST auth, carrying no session cookie at
  all) is exempt, because CSRF specifically exploits ambient
  cookie-based auth a browser attaches automatically, and an API-key
  credential the client must supply explicitly can't be forged the same
  way. The moment a request also carries a session cookie, the CSRF
  check applies regardless of what else authenticated it — the same
  discipline that structurally prevents Epoch G's "API-key session
  laundered into a full session" bug class applies here too.
- **OIDC SSO flow shape, decided explicitly against COOP (SEC-46/
  SEC-55).** This rewrite uses the OIDC Authorization Code flow with
  PKCE via a full-page redirect, never a popup-window flow. A
  server-rendered PHP app has no `window.opener` dependency to protect
  the way an SPA's popup-based login does, so COOP can be set to the
  strict `same-origin` value across the whole app, login/callback
  routes included — no `same-origin-allow-popups` carve-out needed.
  State and nonce are generated with the same hardened RNG as every
  other token in this phase (`random_bytes()`, SEC-07), bound to the
  session, and checked on callback; the ID token's signature and
  `iss`/`aud`/`exp` claims are verified server-side before the response
  is ever trusted, not treated as self-evidently valid because it
  arrived over TLS. **Name the JWKS fetch/cache/rotation mechanism
  explicitly — the #1 real-world OIDC failure point, and unnamed
  anywhere else in this document.** `web-token/jwt-framework` (or
  `firebase/php-jwt` paired with its own JWK-fetching helper) resolves
  the IdP's signing key by `kid` from a cached JWKS document, not a
  hardcoded key — cache it in the same P11 cache-pool infrastructure
  already standing up other named pools, keyed by issuer, with a real
  TTL (a JWKS document is meant to be long-lived but not eternal). On a
  signature check against a `kid` not found in the cached set, refetch
  once before failing — an IdP mid-key-rotation is a real, expected
  event, not an attack, and a stale cache alone shouldn't turn a
  legitimate rotation into a hard login outage. Cap the refetch to once
  per verification attempt (never an unbounded retry loop against the
  IdP triggered by a client-supplied `kid`), and treat a `kid` still
  unresolved after that single refetch as a genuine validation failure.
- **Rate limiting and reverse-proxy client IP (SEC-44).** The naive
  `REMOTE_ADDR` read this document elsewhere already knows is spoofable
  behind a reverse proxy gets resolved the same way here: a declared
  `TRUSTED_PROXIES` list (CIDR ranges, `.env`-configured) that the
  rate-limiting middleware checks against the immediate peer address
  before trusting `X-Forwarded-For`'s left-most entry — an untrusted
  peer is rate-limited on its own directly observed `REMOTE_ADDR`, full
  stop, never on a header it could set itself. Key scope differs by
  surface: login/password-reset attempts key on account identifier
  *and* IP together (defeats both one IP spraying many accounts and one
  account's guesses spread across a botnet); general API rate limiting
  keys on API key/session where authenticated, falling back to IP only
  for anonymous requests. Rate-limit the CSP-reporting endpoint (SEC-45)
  through the same limiter — an unauthenticated `report-uri`/`report-to`
  endpoint with no limit is a known log-flooding target.
- **Brute-force / account lockout and password-reset flow (SEC-74) —
  new scope for this phase.** Give DB-level account locking real
  parameters: lock the account after 10 failed attempts within a
  15-minute rolling window, for 15 minutes, tracked per account (not per
  IP — the account+IP
  rate-limit keying above already covers distributed guessing), and
  surface a generic "too many attempts" message that never distinguishes
  a locked account from a merely slow one. Starting defaults, not a
  researched optimum — revisit once real login telemetry exists.
  Password-reset tokens reuse `EphemeralKeyService` (SEC-28) rather than
  a parallel mechanism, single-use and time-boxed the same way, and the
  reset-request endpoint returns an identical response whether or not
  the submitted address has an account — SEC-31's account-enumeration
  discipline applies to password reset too, not just registration.
  **Rate-limit the *request* action separately from the *guess*
  action — two distinct surfaces, not one.** The account+IP keying above
  covers someone guessing an already-issued code's value; it does
  nothing to stop someone repeatedly triggering *new* codes to be
  issued (a real reset-email-flooding nuisance against a target, and a
  side channel for probing which addresses have accounts despite the
  generic response, via delivery/bounce timing rather than the response
  body itself). Give reset-code *requests* their own dual-scope
  (account-identifier and IP) rate limit, independent of the
  guess-attempt limit above, backed by its own persisted request log —
  not folded into the same counter as failed-guess attempts, which
  measures a different action entirely.
- **Trusted Types (SEC-62).** A single named policy (`default`),
  created once at app boot before any other script runs, whose
  `createHTML` routes through the same trusted HTML sanitizer already
  required elsewhere in this document for user-supplied HTML rather
  than a bespoke one. This closes DOM-XSS sinks (`innerHTML`,
  `document.write`, script injection via `.src`) that a strict
  `script-src` nonce doesn't cover on its own — nonce CSP stops
  untrusted `<script>` tags, not untrusted strings reaching an HTML sink
  from already-trusted script. **Land the `require-trusted-types-for
  'script'` directive in `Content-Security-Policy-Report-Only` mode in
  this phase, not full enforcement** — jQuery (removed only much later,
  P50) writes to `innerHTML` and similar sinks internally in ways the
  `default` policy's `createHTML` can't intercept per-call-site, so
  enforcing outright here would break every still-jQuery-dependent page
  between P28 and P50. Report-only still surfaces real violations via
  SEC-45's CSP reporting endpoint from day one; flip to full enforcement
  once P50 completes — P49 (Lit components) runs first specifically so
  P50 is an unconditional "zero jQuery remains" completion, with no
  per-widget carve-out to reason about here. **A per-route early flip
  (enforcing on any page confirmed to never load the shared jQuery
  bundle, well before P50) was considered and rejected as not worth
  the added complexity**: CSP headers can genuinely differ per response,
  but jQuery is a shared, declaratively-required asset (P36) loaded
  broadly across most templates, not confined to the four widgets P49/
  P50 actually replace — auditing which specific pages are jQuery-free
  early enough to matter would likely cover a small page count for real
  added tracking cost. One global flip at P50, with report-only
  visibility into every real violation from P28 onward via SEC-45's
  reporting endpoint the whole time, is the better trade here.
- **Fetch Metadata (SEC-63).** A PSR-15 middleware, ordered ahead of
  the CSRF check, rejecting any state-changing (non-`GET`/`HEAD`)
  request whose `Sec-Fetch-Site` header is `cross-site` outright — a
  legitimate same-app form submission or fetch is never cross-site by
  definition, so this costs real traffic nothing and closes off
  cross-site request forgery independent of whether a CSRF token was
  also stolen. A request missing the header entirely (older browsers)
  falls through to the existing CSRF-token check instead of being
  blocked — Fetch Metadata is defense-in-depth here, not the sole gate.
- **`Vary: Cookie` (SEC-47)** on every response whose body differs by
  session/permission state, so a shared cache or CDN in front of the
  app never serves one visitor's permission-gated response to another
  visitor carrying a different cookie.
- **`allow_html_descriptions` (SEC-48)** defaults to `false` for new
  installs; an admin enabling raw HTML in gallery/category descriptions
  opts in explicitly, and the setting change is itself an audited
  action once SEC-57's append-only audit log is wired.
- **Extend the CSP lint tool beyond bare `<script>` tags.** The
  `composer lint:no-inline-scripts` check (scanning templates and PHP
  for `<script>` tags missing `type=` or carrying one outside a
  CSP3-safe allow-list, gated in CI for `script-src 'self'` hardening —
  keep this in P28, not the templating tooling phase, since it belongs
  with the CSP work it supports) must also flag inline event-handler
  attributes (`onclick=`, `onerror=`, `onload=`, any `on*=` attribute in
  rendered output — the same legacy pattern this document elsewhere
  strips at the sanitization layer) and `javascript:` URLs in
  `href`/`src`; either one defeats a strict `script-src 'self'` CSP as
  completely as an inline `<script>` block does. Nonce `style-src` too,
  symmetrically with `script-src` — inline `<style>` and `style=`
  attributes get the same per-request nonce, rather than leaving
  `style-src 'unsafe-inline'` open as a hole nonce-scripting only closes
  on the script side.

**Verification for this phase:** a reintroduced inline `<script>` (or
`on*=` attribute, or `javascript:` URL) fails CI via the extended lint
check; a per-request nonce differs across two requests, verified by a
live browser check that a script carrying a stale nonce is actually
blocked, not just asserted against the CSP header string; the rate
limiter returns `429` with correct `Retry-After`/`RateLimit-*` headers
past its configured threshold, tested against both a trusted-proxy
request and an untrusted-peer `X-Forwarded-For`-spoofing attempt; a
WebAuthn registration/assertion round-trips against a Chromium virtual
authenticator in the browser test suite; an OIDC login is tested
against a mock IdP asserting PKCE, state, and nonce are actually
verified server-side — not merely present on the request — and that a
tampered ID-token signature is rejected; and once COOP+COEP are both
live, a browser check asserts `self.crossOriginIsolated === true`.
Cross-origin subresources loaded by any active plugin/theme (P29) must
carry correct CORP/CORS headers under COEP `require-corp` or they
simply stop loading under it — flag this constraint in P29's own
extension-authoring documentation rather than discovering it only once
a real plugin breaks.

### Epoch I — Plugins/Layering/Repo-restructure (P29–P30)

**P29 — Plugin / Theme contracts.** **Ground the design in real plugin/
theme usage before designing the interface, not after.** A prior attempt
read every real plugin in the wider Piwigo ecosystem (~400 extensions)
and every real theme (100+ files) to base the contract on actual usage
rather than guessing, and found 162 distinct legacy plugin hook points in
the wild, with a handful covering most real usage by frequency — do the
same survey first.

**Design directly to this shape** (arrived at only after building and
rejecting a more literal port of upstream's plugin/theme interface
design — see the reference-critique below, worth reading before
designing rather than after):

- One shared `ExtensionInterface` for both plugins and themes, not two
  separate interfaces — a literal two-interface port collapses to an
  identical shape in practice once dead methods are stripped, since real
  consumers read plugin/theme facts from the manifest DTO either way.
- A narrow `ExtensionContext` SDK object passed to `boot()`, sized from
  the frequency survey above — never the raw DI container. Handing a
  plugin the entire unrestricted container (no scoped binding) is a real
  anti-pattern to avoid from the start.
- **No raw DB access for extensions, ever** — route every real
  `pwg_query()`-style use case found in the survey onto typed
  repositories, the config service, or Doctrine entities instead. (One
  real plugin's own comment admitted using raw SQL specifically "to
  bypass permission checks" — a concrete argument against ever exposing
  it.) Route core-data reads through new, narrow, purpose-built
  read-only facades rather than exposing broad internal services with
  many unrestricted-mutation methods.
- A separate "extensions" `EntityManager`, distinct from core's — one
  shared instance across all active extensions, not one per extension
  (one-per-extension multiplies DB connection-pool usage and per-request
  startup cost at plugin scale for no real benefit here). Sharing one
  instance only isolates extension data access from *core's* data
  access; it does nothing by itself to stop a malformed entity in one
  plugin's mapping from breaking Doctrine's metadata factory for every
  *sibling* plugin, since the factory loads all registered metadata
  together. Close that gap structurally instead, in the same
  two-pass boot design below: validate each extension's own mapping
  paths against the metadata factory individually during the
  construct/register pass, and catch, log, and disable just that one
  extension (leaving its siblings unaffected) rather than letting one
  malformed entity abort metadata loading for the whole shared
  `EntityManager`.
- Reuse the JSON-manifest-with-schema-validation format for
  plugin/theme metadata — a genuinely reusable, portable idea worth
  keeping regardless of the interface-design changes above. Add one
  field the reference manifest never had: `requires.core`, a semver
  range against this application's own version — the loader parses it
  at activation time and refuses to activate (with a clear "requires
  core ^17.2, found 17.0" message) rather than silently activating an
  extension built against a contract this version doesn't have.

**Registry/lifecycle mechanics worth designing in from the start, each a
real gap found only during implementation:**

- **`subscribedEvents()` (or any extension-manifest event-registration
  method) must hand back real bound `Closure`s, never a method-name
  string for later dynamic dispatch** — a design that stores a plain
  string for a subsequent variable/dynamic method call is unbuildable if
  the project's own static-analysis strictness level forbids variable
  method calls outright (a real, project-wide rule this rewrite already
  commits to elsewhere). Verify this constraint against the actual
  static-analysis configuration before finalizing the interface shape,
  not after implementing it.
- **Boot every active extension in two passes, not one**: construct and
  cache each extension instance while registering its
  `subscribedEvents()`, and only *then* call `boot()` on those same
  cached instances. This matters for two independent reasons: (1) an
  extension that dispatches its own custom event from inside its own
  `boot()` needs every sibling extension's event registrations already
  in place regardless of load order, which a single-pass
  construct-register-boot-immediately loop can't guarantee; (2) if
  registration and boot ever constructed *separate* instances of the
  same extension, anything `boot()` sets on `$this` would be invisible
  to that extension's own registered handlers — caching the one instance
  used for both steps closes that gap structurally.
- **A parent/child extension chain (a child theme inheriting from a
  parent) must boot furthest-ancestor-first, self-last** — if event
  dispatch is a pipeline where each handler's return value feeds the
  next (rather than fire-and-forget), booting the child before the
  parent hands the parent the final say over the child's own event
  handling, backwards from the intended override relationship.
- **A declared inter-extension dependency (`require:`) needs guards in
  both directions, not just a load-order topological sort**: a forward
  check must confirm the required extension is genuinely *active* right
  now, not merely present on disk (present-but-inactive is not
  satisfied); a reverse guard must refuse to deactivate/uninstall an
  extension while any other currently-active extension still declares a
  dependency on it. Neither direction is optional — a topological sort
  alone only orders installation, it doesn't prevent an already-installed
  dependency from being pulled out from under something still relying on
  it.
- **Sibling (non-parent/child) extension event-handler ordering is a
  manifest-declared integer `priority`, default `0`, ties broken by
  stable activation order** — the ancestor-first rule above only
  resolves order *within* a parent/child chain; two unrelated active
  extensions listening on the same event need their own explicit,
  documented ordering rule rather than depending on incidental
  registration order, which is exactly the kind of thing a later
  extension activation could silently reorder.
- **A `boot()` that throws must not take down every other page's
  extensions with it.** Catch per-extension around each `boot()` call
  in the second pass, the same catch-log-disable pattern already
  applied to a malformed entity mapping in the first (construct/
  register) pass above, just moved to the later point where a failure
  can actually occur: a throwing `boot()` disables just that one
  extension, logs the exception, and surfaces it on the admin
  extensions screen, while every other active extension still boots and
  the page still renders. The alternative — one misbehaving plugin
  producing a site-wide fatal — is the actual worst-case failure mode a
  plugin system exists to prevent.
- **Install/upgrade/uninstall are distinct lifecycle methods on
  `ExtensionInterface`, not folded into `boot()`/`deactivate()`**:
  `install()` runs once on first activation (schema creation, default
  config rows); `upgrade(string $fromVersion, string $toVersion)` runs
  when an already-installed extension's on-disk version number advances
  past its last-recorded installed version, letting an extension
  migrate its own schema/config incrementally rather than assuming a
  clean install every time; `uninstall()` runs on removal and is the
  one place an extension is expected to clean up its own schema/data.
  `boot()`/`deactivate()` stay what they already are — per-request
  wiring, not one-time lifecycle events — conflating the two is what
  makes an extension's upgrade path unbuildable later.

**Reference-implementation critique, worth internalizing before
designing rather than discovering after building the literal port**: a
prior reference design's separate `PluginInterface`/`ThemeInterface`
were written once and never revisited, several of their methods had zero
real call sites (every real consumer read the same facts from the
manifest DTO instead), `boot()` handed plugins the raw unrestricted
container, and declared PSR-4 autoloading in the manifest was parsed but
never wired. Trace prior-art call-site by call-site before adopting its
shape, not just its existence. **Confirm explicitly this design actually
wires the autoloading it declares**, rather than repeating the same
parsed-but-inert defect under a new interface shape: at the same
construct/register boot pass above, register each active extension's
manifest-declared PSR-4 namespace/path pair onto the real Composer
class loader (`Composer\Autoload\ClassLoader::addPsr4()`, reachable via
the `vendor/autoload.php` loader instance) before that extension is
constructed, not a bespoke parallel autoloader. Acceptance criterion:
an activated extension's autoload-declared class is instantiable with
no manual `require`, exercised by a fixture extension that only
declares the mapping and never explicitly requires its own class file.

**Enumerate the `ExtensionContext` SDK's actual capability surface, not
just what it must never be** (never the raw DI container, stated
above): event subscription (register on other extensions'/core's
events, the `subscribedEvents()` surface); template-hook registration
(inject markup at a named template hook point, the mechanism most of
the 162 surveyed hook points above actually are) — a real CSS/JS
asset contribution is a distinct, narrower capability than generic
markup injection and routes through P36's own typed asset-contribution
event instead (no inline-source option there, by design, so it's
CSP-nonce-free by construction rather than needing P28's per-request
nonce threaded through a plugin-authored hook); the narrow
read-only data facades named above (never raw repositories or the
container); an admin-menu/settings-page registration call, mirroring
whatever mechanism `hasApiRoutes` below piggybacks on; and a
config-namespace accessor scoped to that extension's own settings only,
never the global config service. This is a default set based on the
survey's own stated hook shape, not a re-derivation of the frequency
data itself — revisit against the actual per-category counts once the
survey's raw results are available to consult directly.

**Defer bundled-extension porting to a real dedicated effort *after* the
core rewrite is otherwise stable — decide this explicitly, don't leave
it ambiguous.** Porting existing plugins/themes onto a still-changing
core contract means porting the same code twice. Ship the contract and
infrastructure first; leave `plugins/`/`themes/` empty of bundled
third-party code by design until the core stabilizes.

**When the REST API (Epoch G) replaces the legacy WS layer, design the
plugin-extensibility replacement for it at the same time, not as an
afterthought.** A prior attempt initially assumed a WS-method-registration
hook would map onto a typed event like every other legacy hook, then
found the entire namespace it lived in was deleted outright by the REST
migration with nothing replacing the plugin-extensibility half — a real
gap, not caught until specifically checked for. The fix pattern worth
reusing directly: a manifest-declared capability (`hasApiRoutes: true`)
mirroring however settings-page registration works, letting an active
plugin register real routes under a reserved
`/api/v1/plugin-routes/{id}/...` prefix. Design this in Epoch G itself,
not left as a P29 surprise once REST already exists without it. A
plugin-owned route is mounted through the identical core PSR-15 pipeline
as every core route — CSRF, content-type negotiation, rate limiting,
authorization — with no per-route opt-out available to the extension
that registers it; the same discipline P9 already applies to
`admin.php`/`index.php` alike extends to plugin routes for the same
reason, not a separate, weaker pipeline just because the handler code
lives outside `src/`.

**Verify this phase's design against real fixtures, not just unit
tests of the loader in isolation.** Build 2-3 synthetic sample
extensions plus a parent/child theme pair as committed test fixtures
(`tests/Fixtures/extensions/`), and drive the registry/lifecycle
behavior above through integration tests against them: two-pass boot
ordering (an extension dispatching a custom event from its own
`boot()` reaches a sibling's already-registered handler), ancestor-first
parent/child boot order, both directions of the `require:` dependency
guard, and a malformed-manifest extension rejected at load time without
affecting its siblings.

**P30 — Layer decoupling + repository restructure. Verify, don't
re-engineer.** If P6 and Epoch C-D's phases were done right, both halves
of this phase should already be satisfied by the time it's reached — a
prior attempt found this phase needed zero new engineering, only
confirmation, once earlier phases were built correctly. Treat that as
the expected outcome, and treat any actual new work needed here as a
signal an earlier phase's design was incomplete, not as this phase's own
scope.

**Layer decoupling**: build the Deptrac ruleset as a live ratchet from
P6 onward — 0 violations, no `skip_violations` escape hatch, gated in
CI from early on rather than added once a baseline already exists. **Make
re-verifying it a habit after any refactor that reorganizes
dependencies, not just at initial setup** — a prior attempt had it
silently regress twice (once when a new class landed in the wrong layer
without an explicit ruleset entry, once when a presentation-layer class
depended on several concrete integration-layer controllers instead of a
route-level abstraction) and both were only caught by later, unrelated
reviews. Don't leave the fix as two undecided alternatives — the
anecdote just given already names the one actually used: pin every
presentation-tier reference to an integration-tier destination to
route-name-based URL generation via P9's router (`Router::generate()`
against a named route, never a direct reference to the controller
class that handles it), the same mechanism P9 itself establishes for
every other outgoing URL. A metadata-driven registry is a real
alternative in the abstract but isn't the one this project's own
history validated, so it isn't the default here.

**Keep the Deptrac ratchet's own lesson from applying to only half of
this phase.** The layer-decoupling half above gets continuous
re-verification after every refactor; give the repository-restructure
half an equivalent standing mechanism against organic drift back into
`public/`, rather than relying on the one-time verification this
phase's own framing already expects to be a formality: a CI check that
enumerates `public/`'s actual top-level contents against an explicit,
committed allowlist (the real entry-point scripts plus the specific
symlink targets named below) and fails the build on any unexpected
file or directory — the repository-restructure equivalent of Deptrac's
0-violations gate, catching the same class of silent regression
(something new landing in the wrong place without anyone updating a
ruleset) before it reaches a later, unrelated review instead of after.

**Repository restructure**: keep it simple — `public/` as a plain
sibling directory holding only real entry-point scripts plus the
specific symlinks that must stay web-reachable (static theme assets,
build output). Everything else (`galleries/`, `upload/`, `install/`,
`vendor/`, `src/`) stays outside `public/` entirely, none of it directly
HTTP-reachable; `src/` holds only PHP, no TypeScript mixed in; no stray
root-level working-note files. A prior attempt considered a heavier
scheme (a fully separate source folder anywhere the user wants, plus a
generated shim directory) and never needed to build it — the simple
sibling-directory layout satisfied every real requirement. Don't build
the heavier version unless a concrete, identified requirement demands
it.

**The required symlinks carry real operational risk this phase must
verify against, not just declare** — the same "verify the mechanism,
not just that it looks configured" discipline P4 already applies to its
own docroot/deny-rule checks (see P4) extends to these symlinks:
`FollowSymLinks`/`+SymLinksIfOwnerMatch` can be disabled at the web
server or `open_basedir` config level in a way that makes a
perfectly-present symlink resolve to nothing over HTTP even though `ls`
shows it locally; and a container `COPY`/multi-stage build (P4) can
silently dereference a symlink into a real copied file, or drop it
entirely, depending on the builder. Verify each symlinked static-asset
path with a real HTTP request against the built container image (`curl`
against the running image in CI, asserting `200` and the expected
content, not a `test -L`/filesystem-existence check against the
pre-container source tree) — a filesystem check confirms the symlink
exists in the repo, not that it survives the actual container build and
the actual web server's own symlink policy.

**Verification checklist for this phase**: `deptrac analyse` at 0
violations; the `public/`-contents-vs-allowlist CI check passing;
a real-HTTP smoke test passing for every symlinked static-asset path
against the built container image; and a grep sweep for stray
root-level working-note files, confirming the "no stray root-level
working-note files" rule above holds in practice, not just as stated
intent.

### Epoch J — Presentation, templating & extension surface (P31–P55)

Sequenced after every backend phase. Order within the epoch: the Latte
foundation, then refactor and modernization (same behavior, different
implementation), then new features, then a closing gate.

A prior attempt's own fully-converted tree measured 135 templates and
119,752 lines, of which **93,420 lines (78%) were auto-generated
`{varType}` boilerplate** — every template carried the same 692-line
block while referencing 11.5 distinct variables on average. That was
forced by `Template::$vars` being one request-global bag; P40 is what
removes that constraint here. This is cited as evidence for the scale of
the problem, not a target to reproduce — the fresh tree built in P31-33
will have its own real template/line counts, not necessarily these ones.

#### Latte foundation — lands first

**P31 — Smarty → Latte template migration.** Convert every template to
Latte, then retire the Smarty engine entirely — no lingering dependency,
no compat patches. **Keep this phase scoped to template syntax
conversion + engine cleanup only.** Don't fold in asset-pipeline/
manifest/combiner work or image-format changes here — that scope creep
makes "same behavior" verification harder to trust. Retire any
Smarty-specific escape-hatch/reach-around test coverage once the target
templating engine's own visibility rules structurally enforce the same
invariant (e.g. a private-method boundary), rather than keeping a
parallel guard around forever.

**Fix the `representant` → `representative` template-variable typo as
each template gets converted, not in a later sweep.** Every one of the
130+ templates referencing this name gets rewritten by this phase anyway,
so the rename is free here and costly to isolate later; the same applies
to the one live translatable string, `'Find a new representant by
random'`. The corresponding PHP-side variable/method rename is P19's job
(Image domain) — see P19's own text above; keep both renames landing at
the point each side is naturally touched rather than deferred to P56.

**If a "one typed context object per page, no scattered incremental
template-variable writes" convention is ever retrofitted onto existing
render methods — P40 is where that convention actually lands, as the
`XxxView` class per template — audit the *order* of every write relative
to every read for the same template key, not just which methods write
which keys.** A
prior attempt's own real, in-production bugs (twice, in opposite
directions) came purely from this: an incremental "merge onto an
existing key" write followed later by a full-replace typed-context
assignment silently discards whatever the merge added, and a full-replace
assignment that runs *after* some other logic already reads that key
produces the read side seeing nothing, both with zero error — just
content quietly missing from the rendered page. Neither was caught by any
static analysis; both were found only by actually rendering the real page
under a full Browser-suite run. When converting a render method's
scattered `assign()`/`append()` calls into one context object, trace the
full read/write timeline for every touched key first, the same discipline
already established for converting superglobal mutations.

**Confirmed, concrete Latte-conversion gotchas from a prior attempt's
real migration — expect all of these, verified empirically against
Latte's actual compiled output, not assumed from either engine's docs:**

- **`{include}` scope propagation is the opposite of Smarty's.** Smarty's
  `{include}` passes the *entire* including template's current local
  scope in addition to any explicitly-named argument; Latte's `{include}`
  exposes *only* what's explicitly passed as a named argument — nothing
  implicit carries over. This caused a real, confirmed bug (a variable
  silently invisible inside an included partial, changing rendered
  output with no error). Trace every Smarty `{include}` site's full local
  variable usage and pass each one explicitly by name when converting.
  The one exception: values assigned at the *page's own top level*
  through the app's typed-context mechanism (not a template's own local
  `{capture}`/loop variable) do auto-propagate through a bare `{include}`
  in this migration's own design — only genuinely local values need
  explicit passing.
- **Latte's default loader resolves a *nested* `{include}` (one issued
  from inside an already-rendering template) relative to the *including
  template's own directory* — not relative to any registered
  theme-root/template-dir the way Smarty resolves a `file=` path.** Every
  same-directory include "works" either way, masking this until a
  genuine cross-directory or cross-theme include is hit. Audit every
  `{include}` target for whether it lives outside the including
  template's own directory and give it an explicit, root-prefixed
  absolute path if so.
- **Latte has no built-in equivalent of a "render this shared partial at
  most once per request, even if `{include}`d from several different
  call sites" guard** (Smarty's `{if !$smarty.capture.NAME}...{capture
  NAME}...{/capture}{/if}` idiom) — confirmed empirically that a
  `{capture}` result does not persist across separate, independent
  `{include}` invocations of the same partial. Any real cross-page-region
  hub partial relying on this (a popup/dialog markup block included from
  several sibling partials, guarding against duplicate DOM/duplicate
  inline-script `const` redeclaration) needs a real, custom per-request
  dedup primitive built into the template engine wrapper itself before
  conversion, not a template-level workaround.
- **A bare `{` immediately followed by a letter/identifier character
  inside literal output (a JS or CSS brace carried over from a Smarty
  `{literal}`/`{ldelim}` block) is misread by Latte's own parser as an
  attempted tag start**, producing a hard compile error — recurred
  across multiple, unrelated templates in the same migration. The fix is
  inserting a literal space immediately after the brace; grep every
  converted template for this shape rather than only fixing it reactively
  per compile error.
- **Auto-escaping context is precise per attribute type, not a blanket
  policy — verify by reading the engine's actual compiled PHP output for
  a given attribute, never assume from a classification helper read in
  isolation.** `onclick`/other `on*` event-handler attributes get real,
  automatic, context-aware JS-string escaping; a plain attribute
  containing a `javascript:` URI scheme does *not* get that same
  treatment, only ordinary HTML-attribute escaping. A manual
  double-escape "to be safe" inside an `on*` attribute silently corrupts
  the output (visible garbled/escaped text, not a crash) because the
  automatic escaping already ran underneath it. Verify escaping-sensitive
  markup that only renders for a privileged/authenticated user (a
  delete-confirmation dialog, an admin-only control) with a real
  authenticated request specifically — an anonymous-traffic-only
  golden-HTML baseline can miss this class of bug entirely, since the
  affected markup never renders in the baseline at all.
- **Audit every helper/utility whose output convention is tied to the
  old engine's specific escaping mode** (a URL-building helper defaulting
  its separator to the pre-encoded `&amp;` string, written for Smarty's
  raw/`escape_html=false` output) — this becomes a systemic,
  *repeating* double-encoding bug (`&amp;` → `&amp;amp;`) the moment the
  new engine's auto-escaping is live, and is easy to miss because each
  individual converted template looks correct read in isolation. Patch
  each real call site with an explicit "don't re-escape this" annotation
  during the migration, and defer the real systemic fix (changing the
  helper's own default to match the new engine's expectation) to the
  final cutover, once no old-engine caller depends on the pre-encoded
  form anymore.
- **A translation catalog entry (a `.po` file's own `msgstr`) can embed
  literal HTML markup directly in a translated string** (an `&rarr;`
  HTML entity baked into a label across many real locale files, not an
  English-only quirk) — invisible to any code-only audit of the
  template or its renderer, discoverable only by checking the actual
  translation catalog content. Trace every "looks like plain text"
  template field back to its real PHP/data source (including checking
  whether it's `Lang::t()`-sourced from a `.po` file that might embed
  markup) before assuming auto-escaping is safe to leave on for it.
- **A filter chain is not accepted anywhere inside an `{if}` condition** —
  confirmed repeatedly across unrelated templates. Register the
  underlying operation as a callable *function* (usable inside a bare PHP
  expression) in addition to a filter wherever it might ever need to sit
  inside a conditional, rather than filter-only.
- **Multi-argument filter syntax is comma-separated, not colon-chained** —
  only the first argument after a filter name takes a colon; every
  argument after that needs a comma. A straight copy of the old engine's
  own colon-chained multi-arg modifier syntax fails to parse.
- **A filter piped onto a *named `{include}` argument's value* does not
  filter that argument at all** — the engine reinterprets the trailing
  pipe as an output filter on the whole `{include}` result instead,
  silently mis-parsing the argument list. This compiles clean and only
  throws (a content-type-mismatch runtime exception) when the include
  actually renders, so a compile-only check won't catch it. Precompute
  the filtered value into a local variable first, then pass the bare
  variable as the named argument.
- **`{elseif}` must be one word — a straight copy of a two-word `{else
  if}` idiom from the old engine fails to parse.** Grep converted
  templates for the two-word form specifically; it's an easy, silent
  carry-over from muscle memory, not something a generic syntax-check
  reliably flags with a clear message.
- **A JSON-encoded value that can produce a `{` in its output (any
  associative array/object — not a flat scalar array or list, whose JSON
  never contains `{`) gets corrupted by the engine's own default
  auto-escaping wherever it's printed** — not only inside a
  `{capture}`/JS block; the same corruption happens in a plain HTML-body
  position and inside an HTML attribute value too, via the same
  underlying escape-a-literal-brace defense the engine applies in every
  context. The general rule: any `json_encode()` of a possibly-associative
  value needs an explicit raw-output marker wherever it's printed, full
  stop — don't scope the fix to "inside JS blocks only."
- **A raw-output marker on a print does not suppress *every* kind of
  auto-escaping in an HTML-attribute-value position** — confirmed
  empirically that quote-character escaping specifically (converting a
  literal `"`/`'` inside the printed value into its HTML entity) still
  runs on an attribute-context print even with the raw-output marker
  applied, because the engine treats "don't HTML-entity-escape this
  content" and "don't let this value's own quote characters break out of
  the surrounding attribute" as two separate, independently-enforced
  concerns. Usually harmless (browsers HTML-entity-decode attribute
  values before any JS reads them, so a `&quot;`-encoded JSON payload is
  functionally identical to the literal-quote version once parsed) but
  confirm that equivalence against the real downstream JS consumer before
  assuming it's a no-op, rather than leaving a misleading raw-output
  marker that provably does nothing in that specific position.
- **Expect at least three or four textually-distinct ways a `{capture}`-
  style "treat this block as generic markup, not literal script" region
  can still misparse**, each a different underlying parser layer — not
  one bug class with one fix:
  1. A backslash-escaped quote (`\"`) inside a double-quoted JS string
     literal specifically — not "any HTML-tag-looking substring," a
     narrower and more precise trigger than it first appears; naturally
     written HTML inside single-quoted strings or template literals can
     compile fine.
  2. A completely bare, unquoted `<letter` sequence with no string
     involved at all (a loop condition like `i<x.length`) — read as a
     phantom tag-open by the block's own HTML5 tag tokenizer, and the
     resulting compile error's line/column can point past the actual
     collision, not at it.
  3. An HTML-tag-*looking* fragment opened in one JS string-concatenation
     piece and only closed in a separate, later piece of the same
     expression — the tokenizer sees an unclosed tag across both. Prefer
     a standard hex-escape (e.g. `\x3C` for `<`) on just the offending
     character over wrapping the block in a literal `<script>` tag to get
     the engine's raw-text lexer state instead — the wrapping approach can
     compile clean while leaving a stray, empty tag pair in the real
     rendered output, since the block's own captured content sits inside
     the wrapper rather than replacing it.
  4. A bare JS object literal whose `{` is immediately followed by a
     word and a colon (`{value: x}`) — misparsed as an attempted tag
     invocation by the block's own tag-delimiter lexer, a different layer
     again from the HTML5 tokenizer. Fixed the same way as the
     brace-then-letter case above: a literal space after the `{`.
  Don't treat the first fix found for one of these as the general
  solution — verify each new compile/render error against which of the
  distinct trigger shapes it actually is.
- **When porting a string-escaping helper by name (e.g. a
  `|escape:'javascript'`-style modifier), verify its *exact* character-
  level output against the old engine's own real, compiled behavior —
  don't hand-derive the mapping from documentation or memory.** Compile a
  real template containing the original modifier through the old engine
  and diff the port's output character-for-character against it. A
  prior attempt caught two real transcription bugs this way (a literal
  newline where an escape sequence was needed, a missing backslash before
  a brace) that looked plausible on inspection and would have shipped
  silently otherwise.
- **A same-named PHP escaping function is not guaranteed to match the old
  engine's own modifier of the same apparent purpose** — a real, latent,
  cross-cutting bug: a shared HTML-options-rendering helper called PHP's
  `htmlspecialchars()` with its implicit `double_encode` default (`true`),
  while the old engine's own equivalent modifier explicitly passed
  `double_encode: false`. Invisible in every test and every manually-
  reviewed conversion until a real dataset happened to contain a label
  with a pre-existing HTML entity in it (translated text with a literal
  `&nbsp;`), which then rendered visibly double-encoded. This was latent
  in *every* earlier conversion reusing the same shared helper, not just
  the one where it was finally caught — once found, re-verify every
  earlier site that reused the same helper, even ones already marked
  verified, since the bug was silently invisible there too.
- **Any "append new content onto an existing template-variable slot"
  helper (a `concat`/`append`-style utility) that type-checks the
  existing value (e.g. `is_string($current) ? $current : ''`) will
  silently *discard* the entire existing value the moment that slot's
  producer starts wrapping its output in the new engine's own
  string-like-but-not-`string` value type** — this was the single most
  severe bug this class of migration produced: converting one template
  whose output fed such a slot retroactively broke a previously-working,
  already-converted call site elsewhere, discarding a full page's content
  down to just the newly-appended fragment, with no error. Audit every
  such append/concat helper for a bare `is_string()`-style type gate
  *before* converting any template whose output feeds into it, and widen
  the gate to accept the new value type (cast to string) rather than
  discarding it.
- **When a large template converts almost entirely by one mechanical
  substitution rule, do the repetitive part as a verified bulk
  find-and-replace, not by hand-editing every occurrence** — first grep
  to confirm the substitution's precondition holds at every matched site
  (not just a sample), then apply it in bulk, reserving individual manual
  edits only for the handful of sites that don't fit the mechanical
  pattern. Far less error-prone at volume than retyping each occurrence,
  and the grep-first step is what makes the bulk pass safe rather than
  reckless.
- **`??`'s isset-safe short-circuiting only protects its own left
  operand — a fallback value on the right side that can itself be unset
  still throws.** `$a['k'] ?? $b['k']` still emits a real "undefined
  array key" warning if `$b['k']` doesn't exist either; needs its own
  nested `?? null` on the right side too (`$a['k'] ?? ($b['k'] ?? null)`).
  Easy to miss because the left side's own guard makes the whole
  expression *look* fully defended.
- **When retiring an old engine's handle-based template-lookup API in
  favor of direct-by-filename resolution, any feature that persisted its
  own override/extension map keyed by the old opaque handle string
  (not a real filename) breaks silently, not loudly** — confirmed via a
  real bug: an admin-configurable per-template override feature kept
  writing new entries keyed by real basename once direct-filename
  resolution went live, while whatever the *reading* side still expected
  the old handle-string key format continued reading nothing for those
  entries (not an error, just an override that silently never applies).
  A resolution path that has to check "is this a template file, or is it
  going through a different, still-absolute-path-shaped input" (a
  combined-asset build path that resolves both real filenames and
  already-absolute real paths through the same lookup) needs to
  short-circuit on an already-absolute, already-existing path rather
  than run it through the same relative-directory-walk logic real
  filenames use — otherwise it double-prefixes into a nonexistent
  candidate. Add explicit test coverage for every distinct keying/input
  scheme the lookup has to support, not just the common case, since
  neither had any before either bug was found.
- **After converting any template with its own dedicated test fixture,
  grep the *entire* test tree for every other stub fixture referencing
  that same template filename before declaring the conversion done** —
  a recurring, systemic gap across this whole migration: multiple
  unrelated test classes each independently wrote their own throwaway
  fixture file under the same old filename and asserted against the old
  plain-string return shape. Converting only the first-found test's own
  fixture leaves every sibling test either silently exercising a dead
  fallback path or failing outright once the shared rendering code no
  longer resolves the old filename at all. A full, not scoped, test-suite
  run before considering a template conversion finished is what actually
  catches every sibling gap — a scoped run against just the "obvious"
  test class isn't enough.

One more finding from the same migration, unrelated to Latte itself but
worth recording here since it recurred during this phase's own bulk
conversion work: **a `git add` invocation naming several paths, one of
which is already gone (renamed/deleted moments earlier in the same
change), can fail outright and silently stage *none* of the listed
paths** — including unrelated new files listed in the same command that
have nothing to do with the bad pathspec. Run `git status`/`git diff
--staged` after any multi-path `git add` that mixes a delete/rename with
new files, rather than trusting that only the problem pathspec was
skipped. Treat this as a general bulk-migration-workflow habit, not a
template-syntax gotcha.

**Inheritance and inline-PHP constructs**: before converting, grep the
real template tree for Smarty's `{extends}`/`{block}` inheritance tags
and for `{php}`/alternate-resource-type (`file:`, `string:`, `eval:`)
usage — both are easy to silently drop from an otherwise-exhaustive
conversion sweep because neither shows up in the common
assign-and-print/`{if}`/`{foreach}` conversion path. If the grep comes
back empty, record that finding in the phase's own completion notes
instead of building conversion tooling for a construct that was never
actually used. If either is found: convert a genuine `{extends}`/
`{block}` inheritance chain to the target engine's own layout/block
pair (its structural equivalent), and for `{php}` blocks, move the
embedded logic into the page's typed context object rather than
reaching for the target engine's own raw-PHP-execution escape hatch —
keeping arbitrary PHP execution inside a template is exactly the kind of
reach-around this phase's own scope note already rules out for
Smarty-compat shims.

**Mixed-engine transition window**: converting 135 real templates
page-family by page-family (mirroring P40's later per-page-family
cadence) necessarily means both engines are registered and dispatching
simultaneously for some span of the phase — this is supported
deliberately, not an accident to avoid. Dispatch rule: the template
locator resolves by file extension (`.tpl` still routes through the
Smarty engine, `.latte` through the new one), so a converted template
and its not-yet-converted siblings coexist safely in the same tree with
no shared ambiguity. A CI check counts remaining `.tpl` files and fails
if that count goes *up* between commits (a ratchet, not a hard zero
gate, until the final cleanup commit removes the Smarty engine and its
last dependency outright — at which point the ratchet's floor becomes
literally zero and the check itself can be deleted).

**Render-time regression check**: because Smarty is interpreted per-request
(with its own opcode-cache-backed compiled-template layer) and Latte
compiles once to plain PHP ahead of time, a real regression here is a
signal of a bug (e.g., the production cache never actually getting
warmed, so every request pays first-hit compile cost) rather than an
expected cost of the migration. Add a render-time comparison to the
phase's own acceptance signals: for each converted page family, capture
p95 server-side render time before and after conversion (the existing
Browser-suite timing instrumentation is sufficient; no new benchmarking
harness needed), and treat more than a 20% regression as a build-blocking
signal requiring investigation before the page family is marked
converted, not a known-acceptable cost silently absorbed into the diff.

**Compiled-template cache invalidation across deploys**: locally, rely on
the engine's own default mtime-based auto-recompile — a saved edit
invalidates its own compiled output on the next request. In production,
version the compiled-template cache directory per deploy (keyed by
release/build ID, the same mechanism already used for any other
per-deploy build artifact in this codebase) rather than reusing one
long-lived cache directory across releases; a fresh deploy therefore
gets a fresh, empty cache directory by construction, with no runtime
staleness check needed and no risk of a stale compiled template from a
previous release surviving a deploy that changed the source `.latte`
file it came from.

**Phase-done checklist** — all of the following, not just "conversion
looks complete on inspection":

- Zero remaining `.tpl` files and zero Smarty references anywhere in
  `composer.json`/`composer.lock`/the dependency tree.
- Full VR and golden-HTML suites green across anonymous, authenticated,
  and admin routes.
- Full VR and golden-HTML suites green for at least one non-English
  locale (catches the translation-catalog-embeds-markup class of bug
  documented above, which an English-only baseline structurally cannot
  surface).
- The render-time regression check above passing for every converted
  page family.
- CI's Latte lint/format gate (P32) green with zero grandfathered
  suppressions.

**P32 — Latte lint/format tooling.** Build lint and format tooling for
templates, **and wire enforcement into CI in this same phase** — don't
build solid tooling and defer gating to a much later phase (a prior
attempt did exactly that and effectively shipped unenforced tooling for
a long stretch). **A lint or format violation is a hard CI failure from
the moment this phase merges, with zero grandfathered suppressions
carried past go-live** — a suppression file seeded from "every file that
currently fails" and never driven back to zero is exactly the deferred-
gating gap this phase exists to avoid; if the initial sweep can't clear
every existing template, fix the templates, not the gate. (P45 later
only re-verifies this same enforcement after P43's filter-set changes —
it doesn't defer the initial gate itself; if that reads as two phases
both owning enforcement, it isn't: P32 owns turning the gate on, P45
owns keeping it accurate once the filter set it lints against changes.)

*Lint*: wrap the templating engine's own bundled linter if one exists,
registering any project-specific filter/tag extension so custom syntax
doesn't false-positive as unknown. Also warm the production template
cache as a build step, so a template syntax error becomes a build
failure rather than a runtime surprise. Alongside the full-tree CI run,
add a changed-files-only mode (lint only the templates touched in the
current diff, driven off `git diff --name-only` against the merge base)
for fast local/pre-commit feedback — the full-tree run stays the
authoritative CI gate, the changed-files mode is a speed optimization
for the inner dev loop, not a replacement for it. Ship editor/IDE
integration as an explicit deliverable of this phase, not an
afterthought left to whoever needs it later: syntax highlighting and
inline lint diagnostics for the chosen editor(s) (VS Code at minimum,
given it's already this project's assumed baseline elsewhere in the
plan), wired to the same lint tooling built here so a diagnostic seen in
the editor matches what CI enforces.

*Static analysis inside templates*: **evaluate any third-party
static-analysis bridge package critically before adopting it, not
after.** A prior attempt found dead framework-specific machinery, real
performance overhead, and real crashes in one such package's
hand-rolled analysis loop, and built a native compile-then-analyze
approach instead: compile every template (with typed variable
injection) into a real analyzable PHP file, run the normal static
analyzer against that, map errors back to real template lines. **Decision
rule, concretely: time-box the bridge-package evaluation to one day.**
Run it, unmodified, against the real converted template tree, and count
how many of its reported errors are real (a genuine type/undefined-
variable issue an engineer would otherwise have to find by hand) versus
noise (framework-specific false positives, crashes, or errors traceable
to the bridge's own machinery rather than the template). **Adopt the
bridge only if real-error precision clears 80%** (four in five reported
errors are real); below that bar, stop the spike immediately and build
the native compile-then-analyze approach described above instead of
continuing to tune the bridge package. Given the prior attempt's own
findings above (dead machinery, real crashes) already describe a bridge
package failing this bar, default to expecting the native approach to
win the spike, and treat a bridge package that does clear 80% as the
surprising outcome worth double-checking before committing to it.

*Format*: if no prior art exists for formatting the chosen template
language, expect to need a real parser (not a regex-based formatter) for
round-trip safety. **Review any large automated reformat manually, file
by file, not just via automated old/new-parse comparison** — a check
using the same parser on both sides can't catch a systematic parser bug.
A prior attempt's manual review caught three real formatting bugs
(a mismatched-closing-tag cascade, hand-formatted CSS collapsed onto one
line, meaningful whitespace lost in a plain-text content type) that
automated comparison missed entirely. **Verify cross-file template
composition concretely, not by inspection** — if templates are ever
joined by string concatenation outside the template engine itself (e.g.
multi-part mail templates), render the real combination through the
real engine and validate the output with a real parser.

If a real formatter genuinely has to be built from scratch (confirmed a
prior attempt's own situation — no existing plugin covered its chosen
template language at all): write a real recursive-descent parser
producing a typed AST, printed through the target formatting toolchain's
own document/print builders, rather than a mask-and-delegate approach
that hides template syntax from a host-language formatter and restores
it after — matching the architecture other real template-language
formatter plugins for the same toolchain already use for a different
template language. Build out grammar coverage driven by an
unconditional "every real `.latte`-equivalent file in the tree must
parse, format, converge on a second pass, and be idempotent" test with
no hardcoded file list and no skip/try-catch escape hatch — a coverage
floor that's allowed to stay below 100% just accumulates permanently
unformatted files instead of forcing each new gap to actually get
closed, and a silent skip defeats the exact regression-catching purpose
of the suite. Expect the specific gaps to be mundane, real syntax the
existing test corpus just didn't happen to contain yet (a language's own
newer operators, cast syntax, array literals, C-style loop
constructs) — implement each only after reading the real source line
that needs it, never speculatively.

Model the target markup language's own real quirky parsing rules
explicitly, don't assume tag-name matching is straightforward: a real
browser silently discards a closing tag for a void element (an explicit
`</img>`/`</input>`/etc. is always a no-op, never a real pair in any
file) and separately discards *any* closing tag whose name isn't
currently in the open-tag stack at all (a typo'd closing tag naming a
tag that was never opened) — both need the same "no matching opener,
discard" handling, but a void element should be dropped unconditionally
everywhere, while a non-void mismatched closer should be preserved as
literal passthrough, since a single-file parser has no way to tell a
genuine typo apart from one half of a legitimate cross-file split point
(e.g. a shared header/footer pair whose closing tags belong to tags the
*other* file opened). Getting this distinction backwards silently
deletes real, correct closing tags belonging to a legitimate split —
caught only by diffing the *whole* tree's output before/after the fix,
not by the one file the fix was written against. Mark any such
intentional cross-file split with an explicit no-op comment in the
source template (verified to compile away to nothing) describing which
file finishes the markup and by what runtime mechanism (a template
include vs. raw string concatenation outside the engine entirely) — this
kind of seam is invisible from any single file in isolation and easy to
mistake for a real bug on a later read.

Give a formatter's generic content-reflow logic an explicit escape hatch
for template files whose entire body is a single content type where
whitespace is semantically meaningful rather than decorative — a file
that's 100% embedded raw CSS (spliced verbatim into a `<style>` block
elsewhere) or 100% plain-text content (a plain-text email body) should
print byte-verbatim, not get flattened through the same prose-reflow
path as real markup. Detect this by checking the parsed content
directly (e.g. "every child node is plain text"), not by file extension
or directory convention alone.

**P33 — Latte idiomatic modernization.** A content pass over templates
for idiomatic constructs, cleaning up mechanical-conversion-era
patterns, verified as same rendered output throughout **production-mode
rendering** — the one deliberate exception is the dev-only debug bar
below, which by definition changes rendered output (that's its whole
job) and is scoped to non-production rendering only; "same output"
never applies to it. Design choices worth adopting directly:

- Enable any dedent/scoped-loop-variable-style engine features on the
  whole tree from the start, not as a later opt-in.
- Generate type-annotation blocks from a live variable-usage map via
  tooling, with a drift check — not a hand-maintained pass. Make the
  drift check concrete: a CI step re-runs the generator against the
  current `XxxView` classes and diffs its output against the
  checked-in annotation block, failing the build on any difference —
  the same "regenerate and diff, don't hand-verify" shape already used
  elsewhere in this plan for other generated artifacts. The same pass
  is also a low-cost way to flag a now-unused variable or a dead
  annotation entry (one the view class no longer declares but the
  template's annotation block still lists) — surface both as part of
  this same generator run rather than as separate tooling.
- When sweeping a syntax pattern (verbose conditionals → concise tag
  forms), do it via an AST-based transform, not regex, and expect a
  handful of genuine structural edge cases to stay unconverted —
  catalogue them rather than forcing 100%. When such a sweep can produce
  candidate matches that textually nest inside one another (an outer
  wrapper block and an inner one both matching the same "collapse to a
  shorthand attribute" rule), apply it outside-in across multiple passes
  — convert only the non-nested candidates in each pass, re-parse, and
  repeat until a pass finds nothing new — rather than computing every
  candidate's position once and splicing them all in one pass; splicing
  an inner match first invalidates an outer match's own already-computed
  offsets. Separately, refuse to place a second such shorthand attribute
  onto an element that a prior pass already converted, even if it also
  matches the same "wraps exactly one element" shape — two chained
  wrapper conditions collapsed onto one element can silently invert their
  original evaluation order (a template engine's own combined-attribute
  form doesn't necessarily evaluate multiple directives in source order —
  confirmed a real engine evaluates a loop attribute before a conditional
  attribute on the same tag, backwards from the original nested
  if-then-loop, which short-circuited before ever looping; collapsing the
  two produced a real crash iterating a value that was `null` rather than
  an empty collection). Leaving the outer wrapper as a real block is
  exactly as valid an instance of "wraps one element" and preserves the
  original evaluation order exactly.
- When auditing an "unescape"/raw-output marker for removal, classify
  every site by an AST walk cross-checked against a raw-text count
  (catches sites a substring grep would miss), and only remove a site
  once its safety is confirmed against the real hard type contract, not
  just static inference. Some categories (pre-built HTML strings from
  PHP helpers, sites inside a literal `<script>`/`<style>` body) are
  genuinely unsafe to collapse — catalogue them as explicitly deferred,
  not silently inconsistent.
- Wire locale-aware number/date formatting from the real current-user
  language from the start. When auditing for legacy
  locale-unaware formatting call sites, don't trust a first
  substring-grep pass alone — verify with a real locale-divergence unit
  test proving a specific locale's real output actually differs from
  the naive function, not just "renders without crashing." Require at
  least two concrete locales in that test, chosen to each break a
  different naive assumption: `de_DE` (comma decimal separator, period
  thousands separator — breaks any code assuming `.`/`,` are fixed) and
  `ja_JP` (year-month-day date ordering with no separator convention in
  common — breaks any code assuming day/month/year ordering or a
  Latin-script month name).
- If adopting a dev-only debug-bar tool, default to **Tracy** — the
  debug bar from the same Nette ecosystem Latte itself comes from, so
  its panel/extension model already understands Latte's own compiled
  output and template-error mapping natively rather than needing a
  separate integration built for it. Gate it behind an explicit opt-in
  env flag mirroring your error-monitoring SDK's own
  no-op-unless-configured shape, and watch for a layer-direction
  violation if the tool's constructor unconditionally touches something
  that pulls in a higher-layer dependency — register it conditionally
  instead of unconditionally in the constructor.

#### Refactor/modernization track — lands first

**P34 — Event system: legacy-hook-catalog migration.** The dispatcher
itself — one dispatch verb, typed events, mutable-payload convention,
closure-based registration — was already built at P8, immediately after
the DI container, specifically so P17-23 and P29 never had to invent or
route around an interim mechanism (see P8's own text for why that
mattered and the full reasoning). **What's left for this phase is
mapping the full legacy hook surface onto that already-working
dispatcher, one real capability at a time**, using the survey P29
already ran:

1. **One event per real capability, not one per legacy hook name.**
   Ground the event catalogue in a real survey of what a wider plugin
   ecosystem's hooks are actually used for (same survey as P29's
   plugin-contract design), and when two legacy hook names clearly want
   the same underlying capability through different historical
   mechanisms, collapse them into one typed event rather than porting
   both 1:1. Keep a name-lookup reference doc mapping legacy hook names
   to new event classes for anyone porting old plugin code later.
   **Completeness acceptance criterion**: every legacy hook name
   surfaced by that same P29 survey maps to exactly one typed event
   class, and at least one dispatch-site test exists proving the event
   actually fires from the real code path the legacy hook used to fire
   from — a name mapped in the reference doc with no corresponding test
   is not a completed migration for that hook, just a documented
   intention.
2. **Listener ordering**: reuse P29's own already-settled convention for
   the extension layer — a manifest-declared/registration-declared
   integer `priority`, default `0`, higher runs first, ties broken by
   stable registration order — for *every* listener the core dispatcher
   runs, not only extension-registered ones. This keeps one ordering
   rule for the whole system instead of a core-only implicit-order
   convention sitting beside a separately-documented extension-only
   priority convention.
3. **Exception propagation**: a core-authored listener that throws
   propagates and aborts the remainder of that dispatch — an uncaught
   exception from first-party code is a real bug that should surface
   immediately (in error monitoring, in the request's own error
   response), not be silently swallowed. An **extension-registered**
   listener that throws does not: catch it at the dispatcher's own
   per-listener invocation boundary, log it, and continue dispatching to
   the remaining listeners — the same catch-log-disable philosophy P29
   already applies to a throwing `boot()`, extended here to a throwing
   event listener specifically so one misbehaving extension can't also
   take down every other listener (core or sibling-extension) reacting
   to the same event. Distinguish the two cases by which registration
   path added the listener (core registration vs. the `ExtensionContext`
   SDK's `subscribedEvents()` surface), not by inspecting the listener
   itself.
4. **`StoppableEventInterface` support for former filter-chain-style
   events.** A legacy filter-chain hook (one listener transforms a value
   and can prevent any later listener from running at all) has a real
   PSR-14 equivalent: the typed event class for that capability
   implements `Psr\EventDispatcher\StoppableEventInterface`
   (`isPropagationStopped(): bool`), backed by an internal flag a
   `stopPropagation()` method on a shared base event class sets: the
   wrapped standard dispatcher already checks this after every listener
   per the PSR-14 spec it implements, so this is wiring, not new
   dispatch logic. Only give this interface to an event whose real
   legacy behavior actually short-circuited later listeners (confirmed
   against the P29 hook survey) — an event that was always fire-and-
   forget under the legacy system shouldn't gain stoppability it never
   had, since a listener silently relying on "I can stop this" where
   nothing upstream ever could is a new, unaudited capability, not a
   faithful port.

The spy/traceable dispatcher decorator built at P8 is what makes the
completeness acceptance criterion in point 1 practically testable at the
volume 162 real legacy hook points implies — nothing new to build here,
just use it.

**P35 — Browserslist decision + IE back-compat removal.** Decide the
supported-browser floor explicitly, cross-checked against the
TypeScript/bundler build target already configured rather than picked
independently — declare the floor in both the browser-targeting config and the
bundler's own target setting (they don't derive from each other, so this is two
matching declarations, not one). **Concrete decision: evergreen browsers with
full ES2022 support, matching `tsconfig.json`'s `compilerOptions.target`/`lib`
— `Chrome >= 94, Edge >= 94, Firefox >= 93, Safari >= 15`** — no IE, no legacy
(EdgeHTML) Edge, since both predate ES2022 entirely. Land this as a real,
committed `.browserslistrc` at the repo root (the file every browserslist-aware
tool resolves against by default, not a `browserslist` key buried in
`package.json`), and mirror it explicitly in `vite.config.ts`'s `build.target`
as esbuild target strings (`chrome94`, `edge94`, `firefox93`, `safari15`) —
esbuild takes target strings, not browserslist queries, so it can't just read
`.browserslistrc` directly, and needs its own comment pointing back at this
decision so the two never drift apart silently. **Wire whichever CSS-processing
tool P36/P39 introduce (autoprefixer or postcss-preset-env) to resolve against
this same `.browserslistrc`** via its default project-root lookup rather than a
second, independently hardcoded target list — that kind of duplication is
exactly the drift this phase exists to prevent. **No polyfill strategy is
needed**: an evergreen-only floor with no legacy fallback target means there's
nothing to polyfill *to* — state this as the explicit decision (a default worth
revisiting only if real access-log UA data later shows meaningful sub-floor
traffic), not leave the question open. Remove everything the floor obsoletes in
one pass — old-browser shims, vendor-prefixed hacks, conditional-comment-gated
CSS and their references — **and run the real grep for these patterns
end-to-end rather than trusting an initial shorthand description of what to
look for**; the real scope is usually broader than first estimated. **Concrete
grep-pattern checklist for the sweep**: conditional comments (`<!--[if`),
IE-only vendor-prefixed rules (`-ms-`, and any `-moz-`-only declaration with no
unprefixed counterpart), known IE-shim/polyfill library names (`html5shiv`,
`respond.js`, `es5-shim`, `selectivizr`), and IE-specific APIs (`attachEvent`,
`XDomainRequest`, `document.all`) — treat any hit as a removal candidate, not a
rewrite-to-modern-equivalent candidate, since the whole point of the floor is
that the modern browsers already do this natively.

**Verify via the full, unfiltered visual-regression/golden-HTML suite,
not a filtered subset, and not just a typecheck.** A prior attempt found
a genuine pre-existing non-deterministic-baseline bug this way — an
admin activity-log page's row count depended on how many other
authenticated test routes happened to run before it, only ever exposed
by a full unfiltered run. Any admin page rendering a full activity/audit
table needs a deterministic-baseline reset technique (truncate
test-generated log noise before the page renders under test) built in
from the start, not discovered as a flaky-test mystery later. This is
also the phase that surfaced *why* P1 pins the Playwright-bundled
browser build explicitly rather than just the `playwright` npm
package's own semver range: a routine, seemingly-safe dependency bump
within an existing range silently pulled a new bundled Chromium build
with a real UA-stylesheet rendering change (native form-control
borders), invalidating over a dozen VR baselines with zero source-code
change — and none of the standard JS-side CI gates (typecheck, lint,
vitest, format, knip, build) caught it, since none of them touch the
PHP/Pest-driven VR suite that actually renders through that browser. A
dependency bump's own green CI run only proves what its own gates cover;
verify the *other* suite that actually depends on the changed tool before
trusting a routine bump is safe. The pinning mechanism itself lives in
P1 (where the package is installed) since that's a general VR-harness
concern P35 merely happened to discover first, not something specific
to the browserslist decision.

**P36 — Asset-pipeline foundation.** Build this on Vite (already
installed and pinned in P1; `vite.config.ts` already exists in the
repo) — name it explicitly, since the PHP-side integration pattern
differs by environment: a manifest-reading resolver
(`Piwigo\Asset\ViteManifest`, matching the Status table's own naming)
resolves hashed production filenames from Vite's `manifest.json` in
production, while local development instead passes through to Vite's
own dev server for HMR — module URLs served directly by the dev server
rather than resolved from a manifest that doesn't exist yet in that
mode. Decide the environment signal that selects between the two
resolvers in this phase (mirror whatever `APP_ENV`/equivalent switch
P2 already establishes), not as an implementer's later judgment call.
**Decide the asset-declaration model (template-declared vs.
view-declared) explicitly and early, informed by a real measurement,
not intuition.** Count how many registered scripts actually need to
load in `<head>` before the rest of the page renders — if it's a small
minority, the "must collect before `<head>` renders" constraint is
mostly a CSS-ordering problem, not a whole-page JS problem; CSS is
head-blocking by default in every real browser (render is withheld
until the CSSOM is built), so CSS placement needs no separate
measurement to justify it — JS placement is the actual open variable
this measurement answers. If CSS layering already relies on fragile
magic-number ordering comments, view-declared (one explicit ordered
asset list per page) is likely the right call — verify it against how
the template engine's own layout/extends mechanism actually renders,
not assumed, and decide once rather than leaving two later phases to
re-litigate it.

**When introducing new asset infrastructure alongside an old one
mid-migration, build the new system completely alongside the untouched
old one first, with zero template edits, and migrate one page-family at
a time only once the pattern is proven end to end on a thin slice.**
Bridging every template through an interim collector immediately is
exactly the kind of throwaway scaffolding a later phase would just
replace. Combine a page-family's JS/CSS extraction, typed view, asset
declaration, and shell-first rendering into one pass per family instead
of four separate passes across four phases.

**Audit real script/style registration behavior before designing the
replacement — don't assume simple cases cover everything.** This
dependency-ordering problem belongs to the *existing* combiner
(Piwigo's legacy `ScriptLoader`/`CssLoader`-equivalent), which keeps
serving every not-yet-migrated page throughout the P36–P41 transition
— it is not a Vite-native concern. Vite's own ES-module graph resolves
real `import`-declared dependencies natively once a page's scripts
become real modules at its P41 cutover, so hand-rolled ordering only
matters for as long as, and exactly where, the legacy combiner is still
in the loop; design the topological resolution below for that
combiner, not by trying to bend Vite's bundling toward hand-ordered
non-module scripts. Real
multi-level dependency chains (a script depending on a script depending
on a script) need genuine topological resolution, not a single-level
check. Any registration relying on convention-based auto-resolution
(no explicit path/dependency params, resolved purely by naming
convention) needs that resolution logic preserved or it silently breaks
whatever depends on it. Real per-request inline JS with server-interpolated
data can't move to a static-asset system until a typed JSON-island
mechanism exists (build that, P37 below, before or alongside this).
**A script bundler/combiner that resolves load order by an implicit
tiebreak (alphabetical filename, registration order) rather than
requiring an explicit dependency declaration is a systemic latent-bug
generator, not a one-off risk.** A script that calls into another
script's namespace immediately at its own top level (a jQuery plugin
invocation, not deferred inside a `ready()`/click handler) only "works"
today because its filename happens to sort after its dependency's — this
recurred independently across multiple unrelated pages in a prior
migration, each one only surfacing as a real runtime error ("X is not a
function") once something reordered the bundle. Require every such
script to declare its real dependency explicitly and enforce that the
bundler actually orders by declared dependencies, not name — and treat
any bare top-level (non-deferred) call into another script's exports as
a signal to audit for a missing explicit dependency declaration
specifically, across the whole tree, not just the file being edited.
Separately: a callback registered for a "run after the page is ready"
hook is ordered by its own *registration* order relative to every other
such registration, which is a distinct concern from asset *load* order —
moving a deferred callback's registration earlier in a file (a common,
seemingly-harmless refactor) can silently change its firing order
relative to other ready-hooks and break an implicit sequencing
dependency between them (a UI-initialization handler firing before the
event-guard handlers meant to own that interaction are wired up). Treat
ready-hook registration order as a real ordering contract worth a
comment when a sequencing dependency exists, not an incidental
implementation detail.

**Cache-busting is already decided, not open**: Vite's own default
content-hashed output naming (`assets/[name]-[hash].js`, already
configured in `vite.config.ts`'s `rollupOptions.output.entryFileNames`)
is the mechanism — every emitted file's name changes when its content
does, so no separate invalidation scheme is needed. **Chunking/vendor-
splitting policy is explicitly deferred, not decided here**: with real
entry points still a single placeholder (68 real ones land in P45),
there's nothing yet to split sensibly — decide the real policy once
real per-page entries exist and their sizes are measured, defaulting to
Rollup's automatic shared-chunk extraction across multi-entry builds
rather than hand-authored manual chunks unless a specific real entry's
measured size later justifies one (a default worth revisiting at P45,
not before). **Wire `size-limit` (P1) to read real emitted bundle sizes
per entry point from this phase on**: point its config at Vite's own
manifest-resolved output files rather than hardcoded paths, so the
mechanism already exists when P45 lands real entries — the individual
budgets themselves only become meaningful once those entries replace
the current placeholder, but the wiring shouldn't wait until then.

**Design the plugin-extensibility point for page assets as one new
typed event** (matching the same filter-event convention as P34), not
arbitrary inline template calls — preserves the *capability* a plugin
needs (get an asset onto the page) without preserving the old, harder-
to-typecheck *mechanism*. **Fully specify that event's payload now, not
later** — an underspecified public plugin contract is expensive to
change once real plugins depend on it. Minimum required fields: an
asset kind (`Script`|`Style`), a real file path or URL (no inline-source
option — matching P28's CSP direction, a plugin asset is always a real,
addressable file, never inline-injected string content), an ordered
list of declared dependency identifiers (empty when there are none),
and a placement hint (`head`|`footer`, the same page-position
vocabulary P37/P38's ordering hint uses) rather than a free-form
priority number. Reject an unresolvable shape (a dependency identifier
that doesn't match another registered asset) at registration time with
a real exception, not a silently-dropped asset.

**P37 — Typed page-data exposure (PHP half).** Build one typed JSON-island
payload builder per page, replacing ad-hoc PHP→JS smuggling (raw
`json_encode()` calls scattered through templates, a string-into-JS-
literal escaping pattern) with a single mechanism. **Build this before
starting inline-JS extraction (P38/P39)** — otherwise that phase has to
invent an interim mechanism this one just replaces. It's also the PHP
counterpart of the typed view-object work (P40) — the same typed source
should feed both the template and the JSON island, so design the two
together even though the view-object work lands later.

**Security-relevant design point, not optional polish**: neutralize
`</script>`, HTML-comment starts, and `&` in the emitted JSON, not just handle
UTF-8 safely — standard requirements for embedding JSON safely inside a
`<script>` tag. **Name the concrete mechanism, not just the requirement**:
`JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT` (plus
`JSON_UNESCAPED_UNICODE` for real UTF-8, not `\uXXXX` escapes) on every
`json_encode()` call this class makes is the floor. The primary emission
pattern, though, is `<script type="application/json">…</script>` read back via
`JSON.parse()` on the JS side, not a `<script>` tag assigning straight into a
JS literal — `application/json` content is inert (the browser never executes
it), so it both sidesteps JS-literal-escaping edge cases entirely and already
satisfies P28's CSP lint tool (`composer lint:no-inline-scripts`) and its
`type=`-allow-list check without needing a per-request nonce threaded through
at all — a raw executable `<script>` assigning `window.__X__ = {…}` would need
P28's nonce and should not be this mechanism's default shape. Provide
accumulate-and-dedup declaration methods, and **test that any full-reset
mechanism clears every property exhaustively** — a prior attempt found no
existing test asserted this, a real, easy-to-miss coverage gap for a class
every request touches. Emit one JSON `<script>` tag per real page footer, not
scattered ad hoc.
**Design the declaration API with an explicit ordering/`require:`-style
hint from the start** — P38 depends on this existing before it starts
converting real call sites, since a script reading a producer variable
before that variable's own data has actually been declared is exactly
the render-order bug P38 designs against; don't leave this as an
implicit "declare in the right order" convention with no enforced
mechanism. Match the shape `footerScript()`/`ScriptLoader::addInline()`
already use for this same problem rather than inventing new vocabulary:
a `$require` list of ids that must already be loaded (non-deferred)
before this data is read, enforced the same way
`ScriptLoader::addInline()` already does (fail loudly — its own
`fatalError()` call — on an unresolvable id, not a silent no-op).

**Watch for correlated fields masquerading as independently-nullable
ones, in this and every other typed context/payload class built
throughout this epoch.** A flat class where every field is independently
nullable is wrong whenever some fields are actually always-null-together,
always-set-together, or represent a real alternative — it lets a caller
construct combinations that can never happen. Audit for this as classes
are built (any class with 2+ nullable constructor properties is a
candidate), not in a dedicated later audit — a prior attempt found 18
real violations only after the fact, including one class whose own
docblock actively (and incorrectly) claimed every field was
independently optional. **Standard replacement idiom, applied
consistently across the epoch wherever this pattern is found**: a
`final readonly` variant class per real alternative, sharing one common
marker interface, discriminated by class identity (a `match ($variant)`
over `::class` or, when serialization needs a stable string tag, a
backing `enum` property each variant sets to its own case) — never a
flat class with two-or-more nullable properties standing in for what is
actually a closed set of alternatives.

**Payload-size guideline**: an inline JSON island is for small,
page-scoped data — as a concrete floor, anything whose serialized
payload would regularly exceed roughly 5 KB (a default worth revisiting
once real page payloads are measured, not a hard technical limit)
belongs behind a real paginated endpoint instead, fetched by the page's
own JS rather than embedded. Add an ongoing-enforcement lint/arch-test
rule banning raw `json_encode()` calls inside template-rendering code
paths outside this builder class specifically — the same enforcement
posture as P28's `composer lint:no-inline-scripts` check, and a natural
extension to wire into that same tool rather than inventing a second
one.

**Scope this phase to the payload-builder infrastructure only** —
converting every existing template call site that smuggles data into JS
onto the new mechanism is the next phase's job (P38), one template at a
time, not this one's.

**P38 — Inline JS extraction.** Move every `<script>` block in a
template to a real `.js` file loaded through the asset manifest (P36).
Same behavior throughout, proven via visual regression. No TypeScript,
no modularization, no framework changes — keep this phase's scope to
extraction only.

**Inline JS is not just literal `<script>` blocks — audit for
capture-block-style JS generation too, which can carry far more real sites than
the literal blocks do.** Confirmed by the real inventory: zero templates carry
a literal inline-*content* `<script>` block — the only three raw `<script>`
tags in the whole corpus (one per front-end/admin footer template) are already
real `src=` references (the vitals RUM loader), nothing to extract. The entire
real inline-JS surface is capture-block generation: `footerScript()`
(`{capture}…{/capture}{do footerScript(...)}`), 7 call sites across 5
templates. Add inline event-handler attributes as a third extraction category —
13 real `onclick=`/`onchange=` attribute sites across 8 templates — moving
their logic into the same extracted `.js` files and wiring a real listener
instead of a `on*=` attribute string; this is also a direct prerequisite for
P28's CSP work, whose lint tool already plans to flag any remaining `on*=`
attribute once a strict `script-src` is enforced, so this phase is what gives
that later check something to actually gate against instead of a corpus still
full of legitimate hits. Critically, **verify precisely what JS-context
escaping lives inside that captured scope before doing any escaping/filter
cleanup elsewhere** — a prior attempt found literally every JS-context escaping
call site in the whole template corpus lived inside this scope, meaning any
escaping cleanup attempted before this extraction phase would have been
entirely discarded work. Sequence P38/P39 ahead of any further template-content
pass for this reason.

**Two bug classes to specifically design against during this kind of
extraction, both found only because forcing every call site to be
individually inspected surfaced them:**

1. A script reading a producer variable before that variable's own
   script tag has actually rendered — a render-order-dependent bug
   that's easy to introduce and easy to miss without an explicit
   dependency-ordering mechanism. P37's declaration API already carries
   the `$require`-list hint for exactly this reason (see P37) — use it
   at every real call site converted here rather than re-deriving an ad
   hoc per-template ordering convention.
2. A raw boolean/scalar PHP value interpolated directly into a JS
   context through the template engine's own *text* escaper (not a
   JS-aware one) — this can silently produce broken JS syntax for a
   subset of values (e.g. a `false` rendering as empty string breaks a
   `const x = ;` statement), shipping unnoticed for whichever condition
   triggers it. Route every dynamic value into the JSON island via a
   real JSON encoder, never raw text-escaper interpolation into a script
   context.

**Wrap each extracted file in its own IIFE by default**, rather than
leaving every extracted script sharing the same global scope it happened
to occupy inline — "no modularization" means no ES-module `import`/
`export` graph yet (that's P46/P48's job), not "no scoping at all"; an
IIFE per file is zero-risk isolation that costs nothing at this phase's
extraction-only scope and heads off a fresh cross-file global-variable
collision the moment two extracted files that happened to coexist
safely as separate inline blocks land side by side as separate `.js`
files. Where one extracted script genuinely needs to call into
another's exports (the same cross-script top-level-call risk P36's
combiner section already designs against), export that one specific
name onto a single shared namespace object explicitly, rather than
leaving both files un-wrapped in the ambient global scope by default.
**Lint-clean output is a hard requirement for this phase's own
completion**, not a follow-up: every extracted `.js` file passes
ESLint/Prettier before the page-family's extraction is considered done,
the same standard newly-written code meets everywhere else in this
document. **Add an ongoing-enforcement check** extending P28's existing
`composer lint:no-inline-scripts` tool (it already flags a bare
`<script>` tag missing `type=`/outside the CSP3 allow-list) to also
fail on any remaining `on*=` attribute in rendered template output —
the natural single home for both checks, since a reintroduced inline
`<script>` block and a reintroduced `onclick=` are the same regression
class this phase exists to close off, and P37's own JSON-island
`<script type="application/json">` tag is the one form the check must
keep allowing.

**Verify with the full visual-regression and golden-HTML suites, not a
sample, and expect a broad mechanical conversion like this to surface
genuine pre-existing, unrelated test-harness gaps** — a prior attempt
found an entire template block only reachable via a route that always
redirects and never actually renders it, and a golden-HTML fixture theme
that never exercised one real template-swap code path at all. Document
gaps like these explicitly as test-harness bugs to fix, not silently
worked around.

**P39 — Inline CSS extraction.** Every `<style>` block and `style="…"`
attribute moves to a real `.css` file: 20 templates with `<style>`, 243
`style="` attributes. **Destination structure: one `.css` file per
page-family**, matching the same page-family conversion unit P36
combines JS/view/asset work into and P40 converts one at a time — not
one file per template (too fragmented for a linter/bundler to manage
at 20+ files) and not one consolidated corpus-wide file (defeats
per-page-family incremental rollout and VR isolation). Independent of
P38 — different files, different linter — so parallelizable with it.
P39 also settles whether `Template::htmlStyle()` (15 call sites,
accumulating runtime inline CSS) survives at all, or is superseded by
real stylesheets plus the existing `local/css/*-rules.css` mechanism.
P41 should not carry it forward by default. **Classify every one of the
20 templates' rules before extraction begins**: static (identical on
every render, the common case, and the only kind a flat `.css` file can
represent) versus genuinely per-request dynamic (a value computed from
request/user/config state, which can't become a static rule at all and
either stays behind whatever dynamic mechanism this phase decides
survives, or moves to a CSS custom property set inline and read by an
otherwise-static extracted rule). Extracting a rule before this
classification risks silently baking one request's dynamic value in as
everyone's static default.

**Two known regression classes for this exact kind of extraction,
confirmed on this codebase — design and verify against both, not just
per-page VR:**

- **Specificity risk.** Any CSS property — not just `display` — can lose
  to an existing rule once an inline style (which always wins on
  specificity) becomes a real stylesheet rule (which doesn't). Only a
  full VR run catches this, not static review of the extracted CSS —
  verify per-property, not just per-page, since a specificity loss can be
  visually subtle enough to not register as an obvious diff. **Proactive
  mitigation, not detection alone**: emit each extracted rule against a
  generated per-instance selector (a stable `data-`-attribute hook or a
  generated single-purpose class on that exact element) rather than a
  bare, broad selector that now has to out-compete every other rule
  targeting the same element on real cascade order alone — this
  reproduces roughly the original inline rule's effective precedence
  instead of relying on stylesheet ordering to get it right by luck.
  **Some of that original precedence is deliberate, not accidental** —
  an inline style that's there specifically to override a broader rule
  (a legitimate, load-bearing use of inline styling's automatic
  specificity win) is not a bug to flatten away; where the audit
  confirms a rule is a real intentional override, preserve that intent
  explicitly in the extracted file (the same generated per-instance
  selector above, or a documented `!important` with a comment naming
  what it overrides and why) rather than treating every specificity
  delta VR surfaces as a regression to eliminate.
- **`url()` resolution.** A `var()`'d `url()` resolves relative to the
  stylesheet it's declared in, not the page referencing it. Extracting an
  inline `style="background:url(...)"` that reads a CSS custom property
  into a real `.css` file changes that resolution base — audit every
  `url()` inside a `var()` for its new resolution path, not just whether
  the property itself moved correctly.

**Add an ongoing-enforcement check**: extend the same lint-tool family
P28/P38 already build (`composer lint:no-inline-scripts` and its
`on*=`-attribute extension) with a sibling check failing CI on any new
`style="…"` attribute or `<style>` block in rendered template output,
excepting only whichever dynamic mechanism this phase decides survives
(`Template::htmlStyle()`, if kept, or its replacement) — otherwise this
phase's own extraction work erodes the same way P38's would without an
equivalent standing check.

**P40 — Typed view objects + `Template` split.** Expect this to be the
largest single diff in the epoch. Convert one page-family at a time,
proving the pattern end to end on a thin slice first, gated on
golden-HTML, VR, and real Browser tests at real checkpoints (see the
cadence note near the end of this entry — don't defer full-suite
validation across too many batches in a row). This is the phase P31's
write-order-audit warning was written against: when collapsing a page's
scattered `assign()`/`append()` calls into one `XxxView` object, trace
that page's full read/write timeline for every touched key first (P31
documents the two opposite-direction production bugs this catches).

**Target shape, per template**: one `final readonly class XxxView` carrying a
`#[Template(...)]` attribute pointing back at its template, so the template's
own header collapses to one declared type instead of a large per-variable
boilerplate block. **This attribute is required on every View class,
contract-only ones included, and the round-trip check below covers all of them
uniformly** — `#[Template(...)]` is purely declarative metadata, not something
that only means anything once a `Renderer::render()` call actually happens, so
it costs nothing to require even on a class no render call ever touches;
exempting contract-only Views would carve out exactly the templates most
exposed to silent drift, since they're reached only via a dynamic include a
static analyzer can't otherwise connect back to a type. This is what deletes
the bulk of any auto-generated per-template type-annotation boilerplate, a
`toArray()`-per-context-class pattern, and any legacy `ALL_CAPS`/prefix-style
naming convention inherited from the old templating engine — most mapped keys
are typically a direct camelCase rename with no other change. **Classes with
*derived* values (values computed at assign time, not just renamed) need an
individual decision each — use this rubric**: keep the derivation as a real
typed property/method only if removing it would just push the same computation
into the template body instead (no net simplification); drop it and replace
with the raw underlying value plus, if needed, a Latte filter/helper, when the
derivation exists purely to coerce a value into a shape the *old* engine's
weaker typing required (a stringified number, a pre-formatted date the new
engine's own `|date` filter already covers) — those add no real value once the
type system itself enforces the shape. **Also apply P37's
correlated-nullable-fields audit to every View built here**: a View is exactly
the kind of typed payload class that rule targets, so check every View with 2+
nullable constructor properties for hidden correlation as it's built, not as a
later pass. Push-only classes must **return** typed fragments for their caller
to compose, not mutate ambient state; read-back accessors become real typed
accessors — concretely, this is what retires
`Template::addIndexButton()`/`parseIndexButtons()`-style ranked collectors (a
controller-timed flush into an ambient slot before render): P43 gives this
problem its full, dedicated typed-contribution design (typed value objects
whose type determines their destination, not a string-keyed slot), so treat any
push-only class converted here as a thin interim shape that P43 later absorbs
wholesale, not a second, independently-designed pattern for the same problem.

**Split the monolithic template class into**: a `Renderer` with one real
method (`render(View): Html`), a template locator, a typed theme-chain
resolver (replacing any "merge with parent theme" compatibility
emulation the old engine needed), a thin asset seam (owned by P36), a
contribution registry, and a trimmed template-engine extension class.
Delete any adapter/interface layer that exists solely for
Smarty-compatibility once nothing real still needs it, and any template
registration with zero real template uses.

**Audit and delete any low-value, low-usage feature outright rather
than migrating it** — verify real usage with an actual repo-wide grep
across every consumer (never assume from a feature's name or its own
docs), and if usage is genuinely near-zero (a handful of real call
sites, no real ecosystem adoption), delete it and its whole supporting
chain (config, sanitizers, dependent service methods that exist only to
feed it) rather than porting it forward.

**Add a round-trip check as part of this phase's own tooling**: every
template's declared View type must declare that same template back via
its own attribute — catches drift between a class and the template it
claims to serve, cheaply, as a standing CI check. **Add a fast
unit-test layer alongside it**: construct each View class directly (no
render call, no template engine involved) and assert its public
properties — this is the tight, no-real-I/O tier P2 designed the fast
suite around, catches a constructor-shape regression in milliseconds
without needing a real render pass, and is cheap enough to write in the
same commit as each View per the phase's own per-page-family cadence.

**Design the View/ambient-data merge explicitly — a View's data is
rarely fully self-contained.** A migrated page's rendered output needs
to merge with genuinely global, request-ambient data (root URL, theme
config, language info, whatever request-scoped chrome data other
renderers assigned earlier in the same request) — design one explicit
merge point for this (a renderer method that does exactly "View's own
properties plus the ambient set"), don't route through the template
engine's native object-binding alone, which won't know about the
ambient half.

**Decide the shell/body split up front, and keep the shell entirely
out of this phase's scope.** Shared page chrome (header/footer/admin
frame) is used by every template, not specific to any one page-family,
so genuinely replacing shell composition means refactoring shared
infrastructure that touches everything at once — that's a
different-shaped phase (P41, a one-time cutover once every page-family
already has a typed View), not something to blend into this phase's
incremental page-family-at-a-time cadence. A migrated page's rendered
body can be appended into the existing output-accumulation mechanism
exactly the way the old body-render step's return value was, so this
phase can proceed as a long, safe, incremental campaign with one shell
mechanism live throughout — only the body mechanism varies per page as
it migrates.

**Real, generalizable gotchas to design around from the start, each
found only by tracing real template bodies rather than assumed from
class names or old type-annotation preludes:**

- **A "canonical URL" or similar per-page shell-adjacent value can be
  needed by the shared header before a page's own View is even
  constructed.** Anything the shell renders ahead of the page body
  needs its own small, permanently-ambient context object assigned
  early, not folded into the page's own View — trying to source
  shell-timing data from a View that hasn't been built yet doesn't
  work.
- **Don't hardcode a table of "ambient globals" a View merges with.**
  A real per-page template often references several more ambient names
  than any explicit global list anticipates, all owned by sibling
  renderers untouched by a given page-family's own conversion. Design
  the merge to fall back to the existing corpus-wide ambient mechanism
  automatically (reflect the View's own declared properties, leave
  everything else on the existing fallback), so converting one
  page-family never requires updating a shared hardcoded list.
- **A class in a lower architectural layer (core/extended domain) may
  not be allowed to depend on the rendering layer directly** — if your
  layer-boundary tool (Deptrac or equivalent) enforces this, design the
  pattern now: a lower-layer renderer returns a plain result DTO
  instead of rendering internally, and the higher-layer controller that
  already has rendering permission constructs and renders the real View,
  writing the resulting markup into ambient state via a small one-field
  wrapper context, matching whatever "already-rendered markup written
  ambiently" shape the shell-timing values above use. **The write
  happens from that same controller layer, never from the lower-layer
  DTO-returning code** — the whole point of the DTO indirection is that
  the layer forbidden from touching rendering also never touches the
  ambient-state write that only rendering-permitted code is allowed to
  perform; if a lower-layer class ever writes ambient state directly
  "just this once" to avoid threading the DTO back up, that reintroduces
  the exact violation this pattern exists to avoid, so treat it as a
  Deptrac violation to fix, not a pragmatic exception.
- **`{include}`-style template composition's scope-passing behavior is
  engine-specific — verify against the real target engine's own compiled
  output, don't assume either direction.** Smarty's `{include}` merges
  explicitly-passed arguments with the *including* template's entire
  current local scope (explicit args only win on key collision); Latte's
  `{include}` is the opposite — only what's explicitly passed as a named
  argument is visible, nothing implicit carries over (see P31's own
  confirmed finding, including its one real exception for
  page-top-level typed-context values). Converting *from* a
  full-parent-scope engine *to* an explicit-args-only one means every
  real `{include}` site needs its full local-variable usage traced and
  passed explicitly — this is not automatically harmless the way it
  would be converting in the other direction.
- **Not every template needs a full typed-View-plus-Renderer
  conversion — some are legitimately "contract-only."** A template only
  ever reached via a dynamic native include (never a full render call)
  can still get a typed contract purely to eliminate corpus-wide
  fallback-type noise, with zero rendering-mechanism PHP change — a
  tiny type declaration matching the template's own real variable
  reads. Use this for menu-block-style dynamic sub-includes, calendar
  fragments included by string-filename reference, and similar
  patterns where a Renderer call never actually happens.
- **When moving a View class to a different namespace/layer (to fix a
  layer-boundary violation, for instance), update *every* referencing
  template's own type declaration in the same change** — a class move
  alone leaves the template pointing at a stale type, only caught by a
  round-trip check if one exists; build that check early (see above)
  specifically so this class of mistake is caught immediately, not on
  a later full-suite run.
- **A dead-code static analyzer's built-in usage tracking won't know
  about reflection-based readers.** If tooling reads View classes via
  runtime reflection (to build a type map, for instance) rather than a
  static, named call, a standard dead-code analyzer will flag every
  contract-only View's properties as unused. Don't blanket-suppress
  this — build a small custom usage-provider extension recognizing
  "any class serving as a declared template type is considered used,"
  narrow enough to still catch genuinely dead code elsewhere.
- **After any bulk property rename, re-grep the corresponding template
  body specifically for the *old* names inside conditional blocks, not
  just top-level references.** A real regression from this exact
  pattern: a renamed property left referenced under its old name inside
  an `{if}` condition — the condition silently evaluated false forever
  (an `isset()`-style check on a now-nonexistent name), and the guarded
  content stopped rendering with no error, only caught by a later full
  golden-HTML diff. A static type-check alone won't catch this if the
  old name still resolves to *something* (e.g. a lingering ambient
  fallback) rather than a hard error.
- **A full, whole-codebase static-analysis run (not scoped per-file)
  catches a different class of bug than any per-file check** — dead-code/
  unused-property analysis, argument-count mismatches on classes
  resolved via a DI container rather than a literal constructor call,
  and layer violations from a class that moved namespaces all need the
  full call graph. Budget a real full-suite checkpoint every few
  batches of this kind of large mechanical campaign, not only at the
  very end — a prior attempt deferred it across many batches and a real
  rendering regression (see above) sat undetected for a stretch as a
  result.

**Turn each of the seven gotchas above into its own permanent, named
regression test checked into the repo as this phase lands**, not left
as prose for the next engineer to rediscover — each one was found by
tracing a real, specific failure mode, and a named test
(`test_view_with_derived_shell_timing_value_renders_before_view_exists`-
style naming, one per gotcha) is what keeps a future page-family
conversion from reintroducing the exact same bug the gotcha describes,
which prose alone can't enforce.

Depends on P36, P37, P38, and P39.

**P41 — Shell-last rendering, `PageState` split, and asset-pipeline
cutover.** **"Shell-last" names a call-order change, not an
output-order change** — say this explicitly since the name alone
invites the opposite reading. Today, shared chrome (header/footer/admin
frame) renders and flushes ahead of a page's own body being computed;
after this phase, the shell's own render call moves to the end, firing
once the full page (View plus body) is already composed, so the whole
page assembles in one render pass instead of two sequential ones — the
bytes on the wire are still header-then-body either way, only the
*call order* producing them changes. This leaves P40's shell-timing
ambient-context workaround (the canonical-URL-style pattern for values
the shared header needs before a page's own View exists) untouched and
still permanent: moving *when the shell's render call fires* doesn't
change *when those ambient values become available* relative to a
page's own View construction, so keep building them exactly as P40
established — shell-last rendering doesn't supersede that mechanism,
it just changes what happens after it.

Two scoping decisions worth making explicitly rather than discovering
mid-phase: fold the asset-pipeline swap (old combiner-based CSS/JS
loading → P36's per-page asset infrastructure) into this same phase
rather than a separately-numbered future track — the new
factory-style registration maps closely enough onto the old
combine-call parameters, on paper, that the swap looks like it can
happen entirely inside those call sites with zero template file
changes, but verify that claim directly against the real call sites
before committing to it, the same way the second decision below
demands real-code verification rather than an assumption; and confirm
the real remaining admin-shell conversion surface directly against the
code at the time this phase starts — P40's own View-object conversion
may already have reduced how much admin sub-controller work is left by
the time P41 begins — rather than trusting an old scope estimate
written before P40 landed.

**Mechanism**: verify directly (trace through the real template
engine's runtime render path, not just its compile-time behavior)
whether your chosen layout/extends mechanism shares full variable scope
between a child template and the layout it extends. If it does, the
existing ambient-merge design from P40 (View properties merged with
request-ambient globals) generalizes to full-page shell rendering with
no new classes needed — ambient shell-chrome context objects keep being
built and assigned exactly as before, and rendering a page's View
renders the whole page in one shot once that page's template declares
the layout and wraps its body in a named content block. **If it
doesn't share scope**, fall back to a generalized version of the same
ambient-context-object pattern P40 already established for
shell-timing values: instead of relying on implicit scope-sharing, wrap
the *whole* ambient set (not just one canonical-URL-style value) in an
explicit context object and thread it into the layout template as one
explicit named argument alongside the page's own View, so the layout
still has everything it needs without depending on an engine behavior
that turned out not to hold. **Transition incrementally via a
deprecated-but-still-functional dual path**: split any renderer that
needs both an old and new call shape into a "build the context" half
(kept, reused by both paths) and an `@deprecated`-tagged old rendering
half, removed only once every real caller has switched — and **let the
static analyzer's own dead-code detector force each deletion the
moment it's actually unused**, rather than manually tracking "is
anything still calling this." This project's own zero-suppression
PHPStan policy makes an unused deprecated method a hard, unmissable
signal to delete it — a good forcing function to design the migration
around from the start, not just a side effect. **Phase-exit
criterion**: zero `@deprecated` shell-rendering methods remain
referenced anywhere in the codebase, confirmed by the static analyzer's
dead-code detector — the same mechanism the migration already leans on
to force each individual deletion is what gates the whole phase as
done.

**`PageState`-equivalent split**: split a large shared per-request state
class by concern, but only the genuinely self-contained clusters whose
real readers/writers are exactly the classes this phase touches — not
everything on the class. **Respect layer-boundary constraints when
deciding where a split piece lives**: if a lower-layer domain class
needs to write into a piece of that state directly, that piece has to
live at a layer both the writer and the shell-renderer reader can
legally reach, even if the "natural" namespace would be one layer
higher. Leave everything else on the original class alone — splitting
unrelated fields out just because they happen to share a class with
something this phase touches is a separate refactor wearing this
phase's badge.

**Real, generalizable findings from doing this kind of shell/state
migration, worth designing around from the start:**

- **Verify the real fields a shell template reads via a direct grep of
  its actual body, past any large auto-generated type-annotation
  header** — never assume from an old context class's field list, which
  can carry long-dead fields nothing reads. This recurs from P40's own
  lesson and matters just as much for shell templates.
- **A silent, months-long content bug is a real risk of exactly this
  kind of conversion**: a template reading an old ambient key by its
  legacy naming convention, when the real renderer had already switched
  to writing typed properties under a different convention, produces no
  error — just an empty content block that looks intentional. **Verify
  with a full-body content diff (not just "no PHP errors"), and
  specifically check that a rendered fragment actually contains real
  content, not an empty shell** — a coverage gap a pure type-check can't
  catch, since both the old key and the new property are individually
  "valid," just disconnected from each other.
- **When verifying a reflow/whitespace-only claim about a diff, compare
  the full file, not just the diff hunks.** A hunk-only comparison
  silently drops shared context lines from both sides and can hide a
  real content change sitting among whitespace changes.
- **When replacing a mutate-shared-state-via-recursion algorithm with a
  pure resolve-and-return-a-value-object one, confirm first (via a real
  grep of every caller) that the outer entry point fires exactly once
  per relevant scope.** A pure replacement is only a safe substitute if
  nothing depends on incremental accumulation across multiple calls —
  verify this explicitly rather than assuming the refactor is
  behavior-preserving.
- **When cutting over an asset-bundling mechanism, decide explicitly
  whether to preserve file-combining/bundling behavior or drop it
  pending a real bundler landing in a later phase.** Dropping it (each
  asset gets its own tag instead of being merged into one cache-busted
  file) is a legitimate, deliberate tradeoff if a real bundler is
  coming — but expect **real, non-whitespace** rendering diffs as a
  result, and review/accept them consciously rather than treating them
  as regressions.
- **Delete an obsolete parallel mechanism's classes *and* its now-dead
  template files in the same pass, not just its call sites** — a
  dedicated adversarial check (not the normal test suite) is what
  catches template files whose only callers were already deleted in an
  earlier batch but were themselves never removed.
- **Before building a more idiomatic version of a scattered, imperative
  ordering workaround, ask whether the ordering problem should exist at
  all.** A cleaner mechanism for capturing/deferring imperative template
  calls is often just a better-engineered version of a problem that a
  declarative, View-level design (see P42) eliminates structurally
  instead of managing more gracefully — prefer the structural fix over
  a nicer workaround for the symptom.
- **Composing the whole page in one shot, only at the end, delays the
  first byte relative to the old flush-shell-then-stream-body shape** —
  accept this as a deliberate, reviewed tradeoff rather than an
  unexamined side effect. Nothing in this phase's real page sizes makes
  the delay user-visible (no page here streams large enough bodies for
  early flushing to matter), so shell-last stays the default; if a
  future page's TTFB regresses enough to matter, that page can still
  flush its shell early as a one-off exception without unwinding this
  phase's design for everything else.

Depends on P36-P40.

**P42 — Declarative page assets & exposed data (View-level).** Design
three small interfaces a View implements when it needs them: one for
declaring page assets (replacing imperative combine calls *and*
imperative script-registration calls — two distinct old mechanisms),
one for declaring exposed JSON-island data (replacing imperative
expose calls), and one for declaring `<head>` links (replacing an
imperative head-injection call) — each backed by a typed value object,
not raw arrays, and each reserving a nonce field for CSP compatibility
(thread it through once P28's CSP design lands). That's four of the
five imperative mechanisms this campaign replaces; the fifth is the
scattered, ad-hoc plugin-dispatch call sites sprinkled through the old
render path, which this phase consolidates into one hook (see below) —
together they're what the sizing note further down counts.

`Renderer::render()` applies a View's three declarations to the
template in a fixed order — **assets, then head links, then exposed
JSON-island data** — *before* that View's own template file runs. Assets
resolve first because an asset declaration can itself imply a
`<head>` tag (a registered stylesheet needs a `<link>`), so an explicit
head-link declaration targeting that same resource needs the
asset-derived tag already resolved to dedupe against it; exposed data
resolves last because it never affects markup shape, only JSON-island
content, so it has no ordering dependency on the other two.
`Renderer::render()` also fires the one-shot page-lifecycle
plugin-dispatch hook (e.g. a plugin asset-contribution event)
immediately after applying those three declarations, at the same
point. **Ownership split with P43**: this phase owns *when and where*
that hook fires (one call, one point, inside `Renderer::render()`);
P43's typed-contribution design owns *what* fires through it and how
contributions get collected and rendered — the two phases divide the
same mechanism by firing-point vs. payload-shape, not by duplicating
each other's scope.

**Design declarative and not-yet-migrated-imperative registrations to
coexist safely on the same page throughout the whole migration** — the
underlying collector must be dedup-safe regardless of call order, so
this can convert incrementally, template by template, with no flag-day
cutover. This is the same principle P36 already established for the
asset infrastructure generally, applied one level up to the exposed-data
and head-link mechanisms too.

**Scope the campaign's true size before starting, and expect it to
rival the largest campaign in the epoch** — a prior attempt found call
sites numbering in the high hundreds across five different imperative
mechanisms being replaced, comparable to or larger than the entire
typed-view-object campaign (P40). **Verify every template already has a
typed View to attach the new interfaces to before starting — converting
any stragglers P40 didn't reach is this campaign's own real
prerequisite, not optional cleanup.** Order migration batches
bottom-up through the template include graph (a template that includes
a not-yet-migrated partial must wait) — a genuinely different ordering
logic than P40's own rendering-mechanism-based grouping, worth deciding
explicitly rather than reusing P40's batch order by default.

**Real, generalizable findings, worth designing around from the
start:**

- **Don't assume theme/layout variants are interchangeable — read every
  real layout file in full before generalizing a shared theme-base
  abstraction.** A prior attempt found the different layout families
  genuinely differed in their own always-loaded assets (one loaded
  extra hardcoded stylesheets regardless of active sub-theme, skipped a
  shared CSS-rules mechanism the others used) — a single generic
  "assets for theme X" factory would have been wrong; named,
  per-family factory methods were the honest shape.
- **Scope any "resolve once per page" mechanism by comparing resolved
  paths, not a theme name/id alone** — a theme name like "default" can
  legitimately exist under more than one theme root (e.g. both a
  front-end and an admin theme), so identity based on name alone is a
  real collision risk.
- **Verify whether every top-level page actually extends the shared
  layout before applying shared theme-base behavior unconditionally.**
  A prior attempt found one real top-level page (the installer) that
  deliberately does *not* extend the shared layout at all, needing an
  explicit opt-out flag — found via a real regression during
  verification, not anticipated in the original design. Grep for every
  real page's own top-level document structure before assuming
  uniformity.
- **When verifying a "pure reordering, no content change" claim across
  many changed baselines, use a scripted structural diff (line/JSON
  -aware), not a manual eyeball pass** — the volume alone (dozens of
  changed pages) makes manual review unreliable, and a real content
  regression can hide inside what looks like pure reordering.
- **A full-suite run surfacing a stale test fixture calling a method
  deleted by an *earlier*, unrelated phase is a sign the full suite
  hasn't run recently enough** — this is exactly the "don't defer
  full-suite validation across too many batches" lesson from P40,
  recurring here for the same underlying reason.
- **A template's real property set can be sourced entirely from
  ambient ancestor-assigned context, not its own `{include}` args** —
  type-declare based on what the template body actually reads,
  regardless of where that data provably comes from. Read the full
  template body before typing it, not just its call sites' argument
  lists.
- **Verify a file is genuinely still called before converting it — a
  "leftover conversion candidate" list can include files that turned
  out dead once real usage is checked exhaustively.** Converting a dead
  file is pure waste; deleting it is the right move, confirmed via a
  real cross-repo grep, not assumed from a stale list.
- **A shared View class can back two physically different template
  files, substituted at runtime by the same theme/context-switching
  mechanism the rest of the app already relies on** (a front-end vs.
  admin variant resolving the same bare template name differently; a
  default-theme vs. alternate-theme substitution) — this recurred
  independently at least three times in a prior attempt's own campaign.
  A naive migration that gives the shared class one `pageAssets()`/
  `exposedPageData()` silently applies the *same* registrations to
  *both* physical renders, even when only one variant's original
  template ever had them — caught only via a golden-html diff showing a
  spurious extra `<script>`/asset on the variant that shouldn't have it.
  Give the shared class an explicit per-instance discriminator flag, set
  by each real caller from whatever context/theme signal already
  distinguishes the two variants, and gate the divergent registrations
  on it — don't assume a shared class implies shared output.
- **Not every partial resolves to a clean "construct a View, merge its
  declarations in" story — a template reached only through a dynamic,
  generic include mechanism** (an `{include $block->template, ...}`-style
  call iterating a runtime collection of sub-templates, never itself a
  direct `Renderer::render()` target, with no single real caller holding
  the right data in the right shape to construct one) **needs its own
  resolution strategy, not a forced fit of the standard pattern.** The
  working shape: let the real *parent* (which already resolves and holds
  every sub-item's data before it renders) iterate its own
  already-resolved collection and pattern-match each item's real,
  in-tree template identity, replicating each one's own known
  registrations directly — with anything unrecognized (a plugin-supplied
  entry, for instance) falling through untouched rather than erroring.
  Treat this as a materially different, genuinely harder case than the
  ordinary "construct the partial's own View and merge" pattern, worth
  identifying and scoping separately rather than assuming it'll fall out
  of the standard batch order.
- **A per-item condition driven by a real runtime service call (not a
  static, precomputable property) generally can't be replicated by a
  typed View's own constructor data — and it usually doesn't need to
  be, if the underlying registration mechanism is genuinely dedup-safe.**
  Registering an asset/script unconditionally instead of behind the
  original per-item runtime check is a deliberate, accepted widening in
  that case (an unused `<script>` tag is not a functional regression),
  cheaper and more honest than inventing a fake DTO stand-in for
  request-time-only state. Reach for this only once the underlying
  collector's dedup-safety is actually verified, not assumed.
- **During a partial migration, a value produced by a *different*,
  not-yet-migrated rendering step earlier in the same request is a real,
  legitimate source for a View's own constructor data — read it back
  through whatever ambient/old-style accessor that other step already
  populates, explicitly, rather than blocking the whole migration on
  that other step converting first.** This coexistence technique (a new
  View reading old-mechanism ambient output) is what lets independent
  parts of a large campaign like this proceed in parallel instead of
  becoming one giant, undividable batch — but it does mean a template's
  full real dependency set can include values with no local `{include}`
  argument and no obvious owner at first glance; trace the real upstream
  assignment, don't guess.
- **The insertion-order/same-priority-tie-break risk this campaign's own
  asset-resolution algorithm carries is a real, confirmed failure mode,
  not a theoretical one worth a passing mention.** Moving a registration
  from its original position (the literal tail of a render sequence,
  executing after every other page-specific and nested-partial
  registration) to an *earlier* eager-dispatch point — even though its
  own declared priority is unchanged — silently reorders every other
  same-priority item registered around it, because the tie-break is
  resolved by registration order, not by priority value alone. This
  showed up as a real, full-suite-only regression (dozens of golden-HTML
  failures at once) precisely because it had only ever been verified
  per-page up to that point. Any change to *when* something registers,
  not just *what priority* it declares, needs the same full-suite
  golden-HTML/VR re-verification as a content change would — including
  the app's own shell-level bootstrap code that assigns exposed data
  directly (outside any View), which needs the identical
  before/after-render positioning discipline applied to it.
- **A "find every real parent/caller" sweep for a shared partial must
  account for a nested include reached by a bare relative filename, not
  only the fuller path form the direct/expected callers use** — a
  partial-of-a-partial reference (one shared template including another
  by its bare name rather than a fully-qualified path) is invisible to
  a grep scoped to the expected form, and removing/emptying the included
  partial before every real transitive parent is accounted for produces
  a real, live regression, not just a missed-but-harmless case. Grep for
  both the fully-qualified and bare-relative forms of every shared
  partial's own filename before concluding its real caller set is
  complete.

Full detailed design (a theme-base collaborator split, an
insertion-order/tie-breaking risk in the asset-resolution algorithm, the
layer-boundary check every View-hosting namespace needs, per-batch
verification specifics) is worth writing up as its own scoped design doc
before starting, given the campaign's size — don't try to hold the full
design in working memory across a campaign this large. **Owner and
trigger**: whoever picks up P42 writes this design doc as the phase's
own first commit, before any migration batch starts — it's not a
separate, unowned deliverable to be scheduled independently, it's this
phase's own required first step.

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
overlay ~9, picture action ~8, menu item ~6. **State the residue
plainly rather than folding it into "a short tail": those seven kinds
account for 104 of 211 callbacks, leaving ~107 — over half the survey,
larger than the sum of every named kind — that don't cluster above a
handful of occurrences each.** That residue is real, not a rounding
error, and the no-escape-hatch decision below is made with it explicit:
those callbacks are presumed one-off DOM/JS tweaks and genuinely
plugin-specific customizations the typed-contribution model isn't meant
to generalize, left unaddressed by design on the same "needing to
extend core later is acceptable" logic that decision already states for
real novel needs — not retrofitted into a kind that doesn't fit them.

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

**i18n for contributed strings, decided here**: every contributed value
object carries plain, already-translated strings, resolved through the
*contributing plugin's own* gettext catalog before the value object is
constructed — never core's catalog, and never a raw translation-key
string resolved later by core, which doesn't know a given plugin's
catalog exists. This is the same convention `ExtensionContext` already
needs for `myplugin:` template resolution (see the rendering-API
paragraph below); a plugin translates a contributed field label exactly
the way it translates its own template output, through one mechanism,
not two.

**Conflict resolution for single-target kinds, decided here**: two
plugins targeting the same enum target/field slot (two `FormProvider`s
both registering against `AdminForm::PictureModify`'s `themeId` field,
say) is a **hard error at boot**, not last-registered-wins — silently
picking a winner hides a real plugin-compatibility conflict from the
site operator until its symptom surfaces at render time, where failing
loudly at boot, naming both plugins and the contested target, surfaces
it at activation time instead. Consistent with this phase's own "a
wrong target is an invalid enum case" framing above.

**Enum-evolution tradeoff, acknowledged explicitly**: a multi-destination
kind's enum target is exactly the kind of contract that's cheap to add a
case to and breaking to plugins that `match()` it exhaustively — adding
`AdminForm::NewPage` breaks every plugin whose own `match` on
`AdminForm` has no `default` arm. Mitigation: the plugin-facing contract
documents that every plugin `match()` against a core-owned enum target
must carry a `default` arm, and adding an enum case is treated as a
documented breaking change in the plugin API changelog regardless.

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
instead of `void` plus `ADMIN_CONTENT`. **`SettingsPageInterface` is
introduced here, not inherited from P21 or P29** — it's the plugin-facing
analog of P21's core-only `AdminSubControllerInterface`, scoped
specifically to plugin-owned settings pages reached through P29's
`ExtensionContext` rather than core's own `config/admin_pages.php`
registry; P21/P29 don't define it because neither phase's own scope is
plugin-owned admin pages. **The `myplugin:` prefix has to stay a runtime
string, not a `#[Template(...)]` attribute argument, for a real PHP
constraint, not a style choice**: attribute arguments must be
compile-time constant expressions, and a plugin's install path is only
known at runtime — the internal loader resolves the prefix to a real
path itself rather than the attribute doing it.

**A per-photo/per-user *state* concern (is this image already a
favorite, is this row currently selected) never belongs in a typed
contribution's own contract — it's the plugin's own client-side
responsibility, reading the stable DOM hooks this phase already emits**
(`data-image-id` etc.). A thumbnail-overlay-style contribution (an icon
badge on every gallery thumbnail, say) only needs to describe *that* an
overlay renders and *what* it looks like structurally; whether a given
photo's badge is currently "on" is state the plugin's own JS looks up
and toggles against the rendered `data-image-id`, the same mount-point
pattern every interactive contribution kind uses. Modeling per-item
state server-side in the typed contract would reopen exactly the kind
of per-caller bespoke logic this phase's whole design exists to close
off.

*Deliberately no escape hatch*: no loader-chain template override, no
block override, no rendered-output filter. Consistency and predictability
are worth more than flexibility here, and needing to extend core later is
acceptable. Plugin-owned routes are consequently **required, not
optional** — making `Bootstrap\RouteDefinitions` extensible is the only
remaining answer for page ownership (`tag_groups`,
`piwigo_masonry_grid`, `PWG_Stuffs`).

**Split plugin-owned routes into two distinct interfaces, one per real
route class, not one generic "register a route" mechanism** — public
pages and admin pages have genuinely different collision-safety needs.
A `PageRouteProviderInterface` (public pages) needs no reserved
URL-prefix/namespace the way plugin-owned API routes do: a real
clean-URL page route (`tag_groups`/`piwigo_masonry_grid`/`PWG_Stuffs`-
style, e.g. `/tag_groups.php`) has to look like an ordinary path, not a
namespaced sub-path, to serve its actual purpose. This stays safe
without a reserved prefix only because the router always appends
plugin-registered routes *after* every core route and tries them in
registration order — a plugin can add a route but can never shadow an
existing core path. An `AdminPageProviderInterface` (admin pages,
contributing a `?page=` slug) has the opposite need: a **hard error at
boot** on any slug collision, core-vs-plugin or plugin-vs-plugin, since
silently letting one registration win would make the losing page
permanently unreachable with no visible error — the same
fail-loud-at-boot posture this phase's own conflict-resolution
paragraph above already commits to for contribution targets, applied
here to route slugs too. **Audit every real reachable entry point for a
given registry, not just the one the design doc names** — a page-slug
validation path can exist in more than one place (a dispatcher's own
documented lookup, *and* a separate legacy gate that reads the same
static config file independently before ever reaching the dispatcher);
route both through the one real merged source of truth, or a
plugin-contributed slug passes the documented gate and still 404s at
the other one. **Type a plugin-facing registry's own return value as a
bare `class-string`, not a narrower `class-string<CoreInterface>`, when
the core interface lives in a stricter architectural layer than the
plugin-facing registry itself** — a lower/plugin-facing layer generally
can't reference a higher layer's interface directly under a real
layered-dependency ruleset, so let whatever already runs an `instanceof`
check against every resolved page (core or plugin) enforce that
contract at the one place it's actually invoked, rather than fighting
the layering with a workaround.

**Land a large, many-call-site contract change (a `void`-returning
interface method becoming a typed return value, say) across two commits
with a temporarily-nullable return type as the bridge, not one atomic
change touching every implementer at once.** First commit: the new
return type lands as nullable, with every not-yet-converted implementer
still doing its old side-effecting assignment and returning `null`,
which the one real caller treats as "nothing further to do" — letting
each of many call sites convert independently, verified on its own.
Second commit: once every real implementer returns a genuine
non-`null` value, drop the `?`. This keeps a wide migration reviewable
in small pieces without ever leaving the codebase in a state where the
new contract is only partially trustworthy — one specific, real, and
easy-to-miss failure mode this shape guards against: two call sites
converted in *different* commits (an interface's real implementer, and
a separate class that consumes its output) can silently start
discarding real output if the consuming side isn't updated in the same
pass as the producing side — audit every real consumer alongside every
real producer when a batch like this spans more than one commit, not
just the interface's own direct implementers.

*Verification.* Golden-HTML and VR cover every core-rendered
contribution kind, the same as any other template-affecting phase in
this epoch — but neither can prove a contributed `AdminForm` field is
actually wired to a real form submission, only that it renders. Add a
real Browser interaction test per interactive kind (at minimum
`AdminForm`/`FormProvider`): register a fixture plugin's contribution,
submit the resulting form through a real browser, and assert the
submitted value reaches the handler — proving the contribution
round-trips, not just paints.

**P44 — Escaping, input validation & security hardening campaign.**
Broader than output escaping alone — decided explicitly, not scope
creep discovered mid-phase. Output escaping, input validation, upload
serving hardening, SSRF, deserialization, rate-limiting, and cookie/
transport security genuinely complement each other rather than being
five unrelated checklists bolted together: a value escaped correctly
at output with no input-side character restriction is still a stored
payload waiting for the next unescaped sink; an upload sanitizer with
no serving-side `Content-Disposition` backstop is one missed content-
type away from reflected execution; an SSRF guard that validates a
hostname and then lets a *second*, independent DNS resolution happen
at connect time (see SEC-23/24 above) is not actually closed. Treat
this phase as one coordinated security pass across all of them, not
output-escaping-plus-leftovers.

The `|noescape` residue after P38 removes the JS-context cases and P40
turns rendered-sub-template vars into `Html`-typed properties (the
pre-escaped-URL population — `{$U_HOME|noescape}`, `{$F_ACTION|noescape}`,
`{$ROOT_URL|noescape}`) is one input to this phase, not its whole
scope. **Don't trust a pre-authoring `|noescape` occurrence count as a
sizing estimate — measure the real corpus at the start of this phase
instead.** A prior attempt's own plan-authoring-time guess (~988) was
off by roughly half against the real, measured count once P32-P43's
conversions had actually landed (494 occurrences across 95 templates,
333 distinct expressions) — treat whatever number this document or any
earlier phase cites as a starting anchor to re-measure, never a target
to hit blind. **Size it after P40, not before.** Kept as its own phase
so a regression in any of these dimensions stays bisectable from a
structural one; gated by golden-HTML and VR, plus a standing acceptance
check: a repo-wide grep asserting zero `|noescape` usages outside an
explicit allowlist (the three named cases above, until something
removes the need even for those), wired into the same lint job P38/P39
already extend so a reintroduced `|noescape` fails CI the same way a
reintroduced inline
`<script>` does.

**Audit pre-authentication surfaces with the same rigor as authenticated
ones — a real gap class this campaign exists to catch, not a
hypothetical.** The install flow itself is a real, concrete instance:
`install.php` renders admin-supplied form fields (an email address for
a newsletter-subscribe label, say) back into the page before any
account or session exists — an unescaped field there is a reflected-XSS
gap reachable on the very first request the application ever serves,
lower-severity-feeling than an authenticated one only because it's
easy to assume "nobody attacks the installer," not because the
mechanism is any different. Include `install.php` and every other
pre-auth-reachable form explicitly in this phase's own audit scope, not
implicitly covered by "every template" while actually meaning "every
authenticated template."

**A config value an admin sets *for themselves* still needs output
escaping — "admin-self-XSS" is a real, distinct class worth naming
explicitly, not folded into "authenticated user" and assumed lower
priority.** An admin-configurable URL-shaped setting (a custom
"no photo available" placeholder image URL, say) that gets echoed
unescaped on the strength of "an admin configured it, so it's trusted"
misses two real cases: a compromised admin account, and a
supply-chain-tainted config value (an import, a migration, a
copy-pasted value from an untrusted source) — neither of which the
admin's own authentication status protects against. Escape it the same
as any other dynamic value; "an admin configured this" is not itself a
trust boundary.

**Audit for redundant escaping — a PHP-side `htmlspecialchars()` (or
equivalent) call on a value Latte's own auto-escape already handles at
output — as its own pass, not assumed absent because the migration to
Latte's auto-escaping model was already done once.** A double-escaped
value renders literal `&amp;amp;`-style corruption, a real, visible bug
class distinct from the missing-escape XSS findings the rest of this
phase looks for — grep for `htmlspecialchars`/`htmlentities` calls
still live in PHP code whose output later passes through an
auto-escaping Latte print, and remove the redundant PHP-side call
rather than leaving both in place "to be safe." Treat a flagged site
that turns out to be genuinely unreachable dead code as a signal to
investigate what real gap it was standing in for, not just delete it
and move on — a plain-text (non-HTML) output path derived from the same
underlying value the dead double-escape site guarded is a real,
separate thing worth checking for its own correct escaping.

**Harden deserialization of any remote, attacker-influenceable response
this application's own outbound HTTP client receives** — a plugin/theme
metadata fetch, an update-check response, anything from P28's OIDC/SSO
flow or a webhook delivery. `unserialize($response, ['allowed_classes'
=> false])` (never a bare `unserialize()` call) on every such call
site: PHP's `unserialize()` with unrestricted class-instantiation is a
real, well-known RCE/object-injection primitive when handed data an
external party influences, distinct from and in addition to
SEC-10-style local-data-serialization concerns already covered
elsewhere in this document.

**P45 — Latte lint/format enforcement, finalized.** P32 already wires
initial CI/pre-commit enforcement for the lint and format tooling as
part of building it (see P32's own guidance above — don't repeat the
"tooling built but left ungated" gap here). This phase's real job is
narrower: **re-verify and finalize that enforcement once P43's filter-set
changes land**, since `lint:latte` registers the template-engine
extension P43 modifies, and gating against a filter set that's about to
change again would just churn the CI config twice. Deliberately
sequenced after P43 for that reason, not because enforcement was left
undone until now.

**Acceptance criterion:** `lint:latte`'s CI job passes clean against the
full post-P43 template tree, plus a standing regression test that feeds
the linter a fixture using the retired `math()`, `htmlOptions`, or
`htmlRadios` syntax and asserts it fails — proving the retirement
itself, not just the current filter set, stays enforced.

**P46 — JS → TS mechanical conversion.** `.js` → `.ts` renames, minimal
types to satisfy the existing strict `tsconfig.json`, real Vite entries
replacing the `noop` placeholder (the 68 entries `vite.config.ts` already
earmarks). Same code, same behavior. Vendored third-party files
(`jquery.js`/`.min.js`/`.cookie.js`, `themes/default/js/ui/**`,
`themes/default/js/plugins/**`, `jquery.geoip.js`) stay out of scope —
the ESLint ignore-list entry for them predates this plan; P49 and P50
together decide their permanent disposition (removed, replaced, or kept
vendored), not their scoping here. Depends on P38.

**Why P38 (extract) → P46 (typed rename) → P48 (modularize) → P51
(idiomatic pass) stay four separate phases instead of one "extract
straight to modular TypeScript" pass, stated explicitly since nothing
else in this document says so for the JS/TS track specifically:** the
same reason P31 stays scoped to Latte syntax conversion alone and P33's
idiomatic pass runs separately, later — combining "convert" with
"redesign" in one commit makes zero-behavior-change verification
genuinely harder to trust, not just slower to review. A four-way merge
means a real syntax bug and a real design regression land in the same
diff, with no clean way to tell which caused a given test failure. Each
phase here has its own narrow, independently-verifiable acceptance bar
(P46: `tsc --noEmit` clean plus one smoke test per entry; P48: a
byte-count budget assertion; P51: golden-HTML/VR parity) specifically
because it does one kind of change. Merging them trades that away for
fewer commits touching the same files, which this document consistently
treats as the worse trade throughout Epoch J.

**`any` is an explicitly sanctioned bridge type in this phase** — P46's
job is the mechanical rename plus whatever minimal typing satisfies the
strict `tsconfig.json` with the least churn, not real type design;
falling back to `any` at a genuinely ambiguous site is expected and
correct here, with P47 doing the real work of driving it to zero. Test
files rename alongside their subjects (`*.test.js` → `*.test.ts`) —
Vitest's default resolution already covers both extensions, so this is
a rename only, no config change. **Acceptance bar:** `tsc --noEmit`
clean (`bun run typecheck`) across the converted set, plus one real
Browser smoke test per converted entry confirming its now-non-noop Vite
bundle actually loads without a console error.

**P47 — `getPageData<T>()` typing + `any` reduction (TS half).**
`getPageData<T>()` consumes P37's island; TypeScript `any` driven to zero
across P46's output. Real type-design work, not a mechanical rename.

**PHP↔TS type-sync mechanism, decided here:** no codegen pipeline —
introducing one is a heavier build-time dependency than this payload
size class justifies (P37's own ~5 KB guideline). Instead,
`getPageData<T>()` takes a hand-written runtime type-guard alongside its
type parameter (`getPageData<T>(isT: (v: unknown) => v is T)`), authored
next to the TS interface as a matched pair with the PHP payload-builder
class it mirrors — the same "these two things move together" discipline
P37 already applies internally to its own `$require` ordering. A shape
mismatch throws at parse time instead of silently trusting an `as T`
cast, so "`any` driven to zero" is a real runtime guarantee, not just a
compile-time one. **Add `@typescript-eslint/no-explicit-any` as a
CI-enforced ESLint rule in this same phase** — the natural point to also
close off future regression, since this is the phase that drives the
count to zero in the first place.

**P48 — Refactor TS into modules.** Breaks up monolithic per-page scripts
into proper ES modules (shared utils, per-feature entry points), one Vite
entry per real page bundle. Same code, same behavior — the same
invariant P46/P51 state explicitly, restated here because splitting a
monolith is exactly where a silent load-order or initialization-order
regression is easiest to introduce.

**Chunking strategy, decided here:** configure Vite's
`build.rollupOptions.output.manualChunks` to split shared utilities into
one common vendor/shared chunk, rather than letting Rollup's default
per-entry graph duplicate a shared util into every bundle that imports
it. Verify with a real byte-count assertion, not just a green build:
total shipped JS bytes across all entries should not exceed P46's
pre-modularization baseline plus a small fixed margin — a silent
duplication regression shows up as a raw size increase before it shows
up as anything else, and this baseline feeds directly into P55's later
real per-entry `size-limit` budgets. Verification: golden-HTML, VR, and
a real Browser interaction test per converted entry point exercising its
actual behavior, not load-only smoke coverage.

**P49 — Lit component catalog.** Moved ahead of jQuery removal,
deliberately — building this first is what lets P50 become an
unconditional "zero jQuery remains" phase instead of a partial one with
a temporary exception. Checked the actual current call sites rather than
assuming: `selectize` is used for exactly one thing (`search.latte`'s
tag filter — literal tag autocomplete) and `jqtree` for the album tree
picker — the two widgets with no reasonable vanilla replacement, both
real, both in scope. Still parity-only; still the narrowest possible
catalog (just these two), not a general component library. Build and
verify each Lit component against the existing jQuery-based widget it
replaces (both still present and functional at this point in the
sequence), then swap the template markup over and delete `selectize.*`/
`jqtree.*` from the vendored bundle once the swap lands — the first two
of the four jQuery-dependent widgets actually leave the tree in this
phase, not just get a planned replacement.

**Why Lit, stated with the same rigor P52's Tailwind call gets:** plain
Web Components (no library) push every ARIA state-management and
reactive-render concern these two specific widgets need — combobox
filtering, tree expand/collapse — back onto hand-written imperative DOM
code, exactly the kind of code this whole track exists to get out of.
Lit is a thin (~5 KB gzip) reactive-template layer over native Custom
Elements, not a competing app framework, so it doesn't reopen the
Tailwind-style "second build philosophy" question — it's sized to fit
inside P55's later per-entry `size-limit` budgets, not exempted from
them.

**Accessibility is a required exception to the document's "no automated
a11y gate by default" stance, not an omission** — tag autocomplete and
tree picker are exactly the two hardest common ARIA patterns (combobox,
treeview), making this the highest a11y-risk phase in the JS/TS track.
Both widgets must conform to the WAI-ARIA APG combobox and treeview
patterns respectively, verified by axe-core run against each in
isolation (not full-page VR, which can't see keyboard focus or ARIA
state) plus a manual keyboard-only walkthrough recorded once per widget.

**Verification beyond parity:** real Browser interaction tests driving
both widgets by keyboard alone (arrow-key navigation, `Enter`/`Escape`
handling, async filter results in the autocomplete) — VR screenshots
confirm visual parity but cannot confirm either widget still works
without a mouse.

**P50 — Remove jQuery.** An explicit per-surface decision, not a blanket
removal: first-party call sites (native DOM/fetch), the vendored bundle
itself (delete once nothing references it), `themes/default/js/ui/**` and
`themes/default/js/plugins/**` (colorbox, jQuery UI — selectize and
jqtree already left the tree in P49, so this phase closes out the
remaining two; P52 already keeps all four's CSS out of scope, so a
JS-side decision to keep either vendored must be made explicitly here,
not defaulted to by omission). **Open question resolved: neither stays
vendored — both get a real native replacement, no keep-vendored
exception.** `colorbox` (the photo lightbox/overlay) has a direct native
replacement in the `<dialog>` element — no framework needed. `jQuery UI`'s
actual footprint here is narrower than "the library": grepping the real
call sites turns up only `.sortable()` (menu/thumbnail/rank reordering,
3 call sites), `.slider()` (single- and dual-handle range filters,
heavily used in `user_list.js`), and `.datepicker()` — no `.dialog()`,
`.autocomplete()`, `.accordion()`, `.tabs()`, or `.menu()` anywhere. Each
has a solid native replacement (HTML5 Drag and Drop API for `.sortable()`,
paired `<input type="range">` for `.slider()`, `<input type="date">` for
`.datepicker()`) — none need Lit. **This phase is the true unconditional
completion — no per-widget carve-out to track and no temporary
exception, since P49 already removed the two widgets that would
otherwise have needed one.** `plupload` (confirm whether the vendored
build actually depends on jQuery, or is already one of upstream
plupload's jQuery-free builds, before assuming it needs a decision here
at all), `jquery.geoip.js`, and the installer's own separate
`jquery.packed.js` load, which is a third easy-to-miss surface with
thinner coverage (`composer test:install` only). `pngfix.js` is not in
scope — it is an IE shim, not a jQuery plugin, already removed in P35.
**The trigger for flipping SEC-62's Trusted Types directive from
report-only to full enforcement (see P28) is this phase's own
completion** — with P49 already done, this is the first and only point
at which zero jQuery-dependent widgets remain anywhere. Confirm every
remaining page is Trusted-Types-clean as part of that flip.

**Idioms needing manual reimplementation, named explicitly rather than
discovered ad hoc mid-conversion:** delegated event binding
(`$(document).on('click', selector, handler)` → native
`addEventListener` plus manual `event.target.closest(selector)`
matching), `.serialize()`-style form submission (→ `new
FormData(form)`/`URLSearchParams`), `.fadeIn()`/`.fadeOut()`/
`.slideUp()`/`.slideDown()`-style animation (→ the Web Animations API or
a CSS transition toggled by a class), and `.ajax()`-style
request/response handling (→ `fetch()`, already the target per the
native-DOM/fetch direction above).

**P51 — TS modernization.** An idiomatic pass over the now-modular,
jQuery-free, fully-typed codebase from P46–P50. Same behavior throughout,
mirroring P33's own "same rendered output" discipline for the same
reason: a modernization pass that silently changes behavior isn't a
modernization pass.

**Target idioms, named explicitly rather than left to reviewer
discretion:** `const`/`let` discipline (no remaining `var`), `async`/
`await` over `.then()` chains, optional chaining (`?.`) and nullish
coalescing (`??`) replacing manual truthy guards, `readonly` on
constructor properties and array/object literals never mutated after
construction, and template literals over string concatenation.

**Transform methodology, matching P33's own AST-not-regex discipline:**
an AST-based transform via `ts-morph` (the natural TS-side counterpart
to whatever tool P33 uses on the Latte side), with the same outside-in
multi-pass caution P33 documents for nested candidate matches — a
`.then()` chain nested inside another `.then()` callback, or a truthy
guard nested inside a larger conditional, can invalidate a naively
computed outer match's offsets the same way a nested Latte wrapper
conditional can.

**Verification:** golden-HTML and VR per converted entry point, plus a
real Browser interaction test exercising each entry's actual behavior —
parity screenshots alone can't catch an `async`/`await` conversion that
silently drops error handling a `.catch()` used to provide.

**P52 — CSS architecture modernization.** Four `theme.css` files are in
scope: `themes/default/theme.css` (1,004 lines, public gallery),
`themes/admin/default/theme.css` (8,599 lines, the parent admin skin),
and `themes/admin/roma/theme.css`/`themes/admin/clear/theme.css`
(2,505/976 lines) — both live, user-selectable, confirmed partial
skins (`theme.json`'s `"parent": "default", "loadParentCss": true`;
`Template::setTheme()` recurses into the parent before the child's own
`append('themes', …)` call, so `admin/default`'s `<link>` always
precedes the skin's own). Vendored plugin/widget CSS
(`selectize.*`, `jqtree.css`, `Chart.min.css`, jQuery UI, `plupload`,
`colorbox`, `fontello`) stays untouched, same precedent P38 set for
vendored JS. Zero custom properties, `@layer`, or `@container` exist
anywhere in scope today; the only custom properties anywhere in
`themes/` are in that same out-of-scope vendored plugin CSS.

Every in-scope class selector already carries its `kebab-case` name
coming into this phase — P1's own naming-convention pass (below) already
renamed the whole tree, including today's `.action-buttons`/
`.actionButtons`/`.additional_info` mix, before any template/CSS
architecture work started. Vendored plugin/widget CSS keeps its own
selector names unchanged, same as the rest of its out-of-scope treatment
above — P1's rename pass excludes it for the same reason.

**The Tailwind decision, resolved: not adopted.** P40 and P41 already
touch every one of the 135 templates once each — header-area type
declarations and shell composition respectively, not a body-markup
rewrite — so adopting Tailwind would add a `class=` rewrite pass on top
of, not instead of, that churn. More load-bearing: the admin theme's
partial-skin model composes naturally with cascade-layer +
custom-property overrides (a child skin redefines a token or adds a
later layer) but not with utility classes baked into markup, which
would need per-skin markup forks to differ visually. Native CSS
(`@layer`, `@container`, custom properties, nesting) closes the same
ergonomic gap without a new build dependency. This removes the former
"Tailwind call due before P40" gate — P52 no longer blocks P40's start.

**Cascade-layer order**, declared once per theme entry point:

```css
@layer reset, tokens, base, theme-chain, components, pages, utilities;
```

`reset` (new, minimal — box-sizing, margin removal), `tokens` (`:root`
custom properties only, no selectors), `base` (bare-element defaults
lifted from today's un-scoped top-level rules), `theme-chain`
(`admin/default`'s own rules; `roma`/`clear` add overrides to this same
layer name in their own files, so cascade-layer position — not
specificity or load order — decides the winner; this is the layer that
replaces today's pure-load-order fallback with something explicit and
order-independent), `components` (extends the existing
`css/components/` convention — already present per admin skin, not
just `admin/default`, though `roma`'s copies today are whole-selector
duplicates a shared token would collapse to one redefinition),
`pages` (per-page CSS, the landing spot for P39's extracted inline
styles), `utilities` (a short, hand-authored `.u-*` set — deliberately
not Tailwind-generated). `Template::localCssRules()`'s
admin-configurable, site-operator override files
(`local/css/{theme}-rules.css`, outside `themes/` entirely, registered
at a deliberately higher `order` so they always win) must stay
**unlayered** — wrapping them in a named layer would silently break
their "always wins" guarantee, since unlayered CSS already beats every
layer unconditionally.

**Design tokens.** **Token names are semantic/role-based, not literal
values** — `--color-surface`, `--color-border`, `--color-text-primary`,
`--color-accent`, never `--color-gray-3c3c3c` — stated here explicitly
because P54 (Dark mode) depends on this being true: a dark-mode override
that only has to redefine what `--color-surface` *means*, not rename
every consuming rule, is the whole reason P54 can land as pure value
redefinition rather than a second markup pass. Categories seeded from
values already repeated in the legacy CSS rather than invented: color
(`admin/default/theme.css` repeats `#3c3c3c`/`#3C3C3C` 23+18 times, same
color mis-cased; a near-duplicate orange family
`#ffa646`/`#ffa744`/`#ff7700` needs a call on whether that's 3
intentional shades or accumulated drift), spacing, radius, and one
z-index scale (56 raw declarations, 17 distinct literal values from
`-1` to `99999`, no scale today) — **the target shape is named semantic
layers, not a raw numeric step scale** (`--z-base`, `--z-dropdown`,
`--z-sticky`, `--z-overlay`, `--z-modal`, `--z-toast`, `--z-tooltip`,
each a fixed value, not a formula), the same semantic-over-literal
principle as color above, and the natural fit for a UI whose real
stacking needs are "which named layer is this," not "how many layers
above its neighbor." **`url()` values never go
through a custom property** — a `url()` consumed via `var()` resolves
against the *consuming* stylesheet's directory, not the page's, and
breaks page-relative paths once the rule moves into a nested
`css/pages/*.css` file; keep the whole `background-image: url(...)`
declaration inline on the consuming rule instead — the same
specificity/`url()`-resolution risk P39 documents; see that phase's own
note rather than restating it.

**`@container` candidates**, each checked for genuine box-size-dependent
layout and for which element actually needs `container-type` (the
ancestor being measured, never the element whose own rule changes — an
element cannot query its own box to redefine its own layout):
`#availablePlugins` (`display: flex; flex-wrap: wrap`) — its child
`.pluginBigBox` already has a `@media (max-width: 1700px)` viewport
hack collapsing a 2-column layout to 1, a textbook case that becomes a
real bug fix as `@container` instead of modernization-for-its-own-sake;
`#picture-content` (parent of `#picture-infos`, which sets `display:
grid; grid-template-columns: 50% 50%`) — both IDs are worth converting
to classes in the same pass, since neither needs ID-level specificity;
`.thumbnails` — the one candidate that's genuinely both the wrapper and
the container itself.

**RTL correction.** `selectize.dark.css`/`jqtree.css` hold the only
genuine locale/bidi RTL support in this repo and stay out of scope. But
three in-scope files also use `direction: rtl` — `themes/default/css/
search.css` (×2), `themes/admin/default/css/components/
album_selector.css`, `themes/admin/default/theme.css:7440` — for an
unrelated ellipsis-truncation trick (`direction: rtl; text-align:
left;` on a `text-overflow: ellipsis` element so a long path truncates
from the start). These are real conversion targets, not part of the RTL
exception.

**Stylelint gating.** The `stylelint` CI job already runs
unconditionally; `ignoreFiles: ["themes/**"]` in `.stylelintrc.json` is
what makes it a no-op for this whole scope today. Narrow it path-by-path
as each file converts, not as one blanket removal on day one — avoids
turning an already-green job into an immediate wall of pre-existing
legacy violations across files nothing has touched yet.

**Migration order**, each step VR-gated and re-checked for the
specificity-regression pattern (any property, not just display/
visibility — an existing same-selector rule at equal-or-higher
specificity can silently overwrite a converted property with no
golden-HTML signal) before commit: (1) `tokens` + `reset` + `base`,
theme-independent; (2) `admin/default`, the largest file; (3)
`roma`/`clear`, expressed as `theme-chain`-layer overrides of
`admin/default`'s token names, replacing today's load-order-dependent
fallback; (4) `themes/default`, independent of the admin work.

Depends on P39 (real per-page CSS files should exist before layering
them), not on the JS track, so parallelizable with all of P46–P51.
Feeds P54 (token names must exist first) and T3·WEB.

#### New-feature track — lands last

**P53 — Picture pipeline.** `<picture>` AVIF/WebP variants plus ThumbHash
blur-up placeholders: new image formats and a new loading-placeholder UX.
Independent of the refactor track; kept last per the modernize-first
ordering rather than for a technical dependency.

**Generation strategy, decided here:** async via P11's Messenger, not
inline on upload — a `GeneratePictureVariantsJob` enqueued from the
existing upload write path, reusing P11's `failed` transport for
visibility into a variant that failed to encode rather than one that
silently never appears. A one-time backfill job walks the existing
library and enqueues the same job per already-uploaded picture, so
existing libraries gain variants without a separate migration mechanism.
**Storage multiplication is real and unaddressed elsewhere**: two new
derivative formats per existing size class roughly doubles image storage
for the library — state this as an accepted cost of the feature, not a
side effect discovered at rollout.

**The P36 relationship, stated precisely rather than as a soft
dependency**: P36's Vite manifest is a build-time, first-party-asset
concern (JS/CSS bundles) with no runtime role in serving per-photo
derivatives — there is no literal coupling. What P53 actually reuses
from P36 is its *cache-busting convention* (a content-hashed filename),
applied to generated derivatives the same way, so the two phases don't
invent two different cache-busting schemes for two different kinds of
generated asset. Drop the "soft-depends" framing; P53 has no
build-order dependency on P36, only a naming-convention one.

**Schema note, flagged against P15 like every other schema-touching
phase in this document**: ThumbHash storage needs a new column (a short
binary/base83-string value per picture, small enough to sit directly on
the existing images table rather than a side table) — route it through
P15's migration tooling, not an ad hoc `ALTER TABLE`.

**Codec dependency, flagged against P4**: AVIF encoding needs GD built
with `libavif` support (`imageavif()`, PHP 8.1+) — today's container
image's GD build covers JPEG/PNG/WebP only (per the libvips-migration
rider's own inventory later in this document), so P4's Dockerfile needs
the AVIF build flag added before this phase can ship; WebP already works
against the existing GD build with no new dependency.

**Measurable target for P55's Lighthouse gate**: ThumbHash blur-up is
specifically an LCP/CLS lever — state the target as a real number once
P55 wires real thresholds (a reasonable placeholder default worth
revisiting once measured: no CLS regression, LCP improved or unchanged
on the picture-detail page relative to the pre-P53 baseline), so P55 has
something concrete from this feature to assert against rather than
inventing its own picture-pipeline-specific criterion independently.

**P54 — Dark mode.** A new user-facing capability: a **three-state**
toggle (system/light/dark), not a binary one — `system` follows
`prefers-color-scheme` live, `light`/`dark` are explicit user overrides.
Depends on P52 — it needs the modernized cascade layers and custom
properties to add a theme dimension onto cleanly, and specifically needs
P52's tokens layer to already be semantic/role-based (see P52's own note
above) for this phase to land as pure value redefinition in a `dark`
variant of the `tokens` layer rather than a second markup/selector pass.

**Persistence, decided here**: a per-account setting, stored server-side
(reusing the existing user-preferences storage, not a new table),
falling back to `localStorage` for anonymous/logged-out visitors so the
choice still survives a reload without an account. Server-side storage
for authenticated users also keeps the choice consistent across devices,
which a client-only `localStorage` scheme can never provide.

**FOUC prevention is a required deliverable, not an afterthought** — the
classic failure mode for this exact feature is a visible flash of the
wrong theme between first paint and a client-side script applying the
stored preference. Prevent it structurally: render the resolved theme
(from the server-side setting, or a same-site cookie mirroring the
`localStorage` value for anonymous visitors) as a `data-theme` attribute
on the server-rendered `<html>` tag itself, so the correct theme is
already present in the first byte of HTML — no render-blocking inline
script needed for the common case, only a small inline script as the
`system`-state fallback when no stored preference/cookie exists yet.
Verify with a real Browser test asserting no visible theme flash across
a hard reload for each of the three states.

**Scope, stated explicitly**: all four of P52's `theme.css` files get a
dark variant in this phase — the public gallery
(`themes/default/theme.css`) and all three admin skins (`admin/default`,
`roma`, `clear`), since P52's `theme-chain`-layer inheritance means a
skin's dark override is a small diff once `admin/default`'s own dark
values exist, not three independent efforts.

**VR baseline extension**: add a full second baseline set captured under
`dark`, not a representative subset — the whole point of this phase is
that every themed surface gets new color values, and a subset would
leave real regressions in the untested remainder invisible until a user
reports them.

#### Closing gate

**P55 — Real quality gates.** `lighthouserc.json` has no `assert` block
today and is collect-only; `.size-limit.json` has one 1 KB placeholder
budget, whose own name still cites a pre-renumbering phase. Wires real
Lighthouse perf, a11y and best-practices thresholds and real per-entry
`size-limit` budgets, and decides whether the risk register's claimed
"a11y gate" becomes a real automated check. Needs P35–P54's real bundles,
templates and features to measure against.

**Threshold methodology, decided here rather than left as a number to
invent later**: derive `size-limit` budgets from the real bundle sizes
P48 actually ships (measured after its `manualChunks` split, per that
phase's own byte-count baseline) plus a fixed headroom margin (a
reasonable default worth revisiting once real numbers exist: 20%),
ratcheted down on a later release once the codebase has lived with the
gate for a while rather than set once and never revisited. Lighthouse
thresholds: keep and name explicitly Lighthouse's own default throttling
profile (simulated slow-4G network, 4x mid-tier-mobile CPU slowdown,
`formFactor: mobile`) — today's sparse `lighthouserc.json` inherits it
implicitly by not overriding anything; state it in the config itself so
it's a stated decision, not a silent default that could drift the
moment someone "cleans up" the collect settings — with numeric floors
for performance, accessibility, and best-practices scores set from the
same measure-first-then-gate discipline as the size budgets, not guessed
in advance of having real pages to score.

**Per-entry budgets resolve directly from P48's chunking answer**: once
P48's `manualChunks` split lands, each real Vite entry plus its share of
the shared/vendor chunk gets its own named `size-limit` budget — nothing
to invent here, it's mechanical once P48's shape exists, which is
exactly why P55 is sequenced to need P35–P54 first.

**Hard-blocking, with a documented override**: these are real CI gates,
not reporting-only — a title of "gates" that only warns isn't a gate.
Override process: a PR that must exceed a budget for a stated reason (a
real new feature's genuine weight, e.g. P49's Lit widgets or P53's codec
work) updates the checked-in budget file explicitly, in the same commit,
with a one-line justification in the commit message — never a silent
CI-config bypass or a skipped check.

**Scope: lab-only by design, wired to real-user field data separately,
not merged into the same gate.** Lighthouse CI is synthetic/lab
measurement; this phase's own gates stay lab-only and CI-blocking for
that reason — a field-data threshold can't block a PR merge, since RUM
data doesn't exist yet for code still in review. Real-user Core Web
Vitals already flow through P1's `web-vitals` → `VitalsController`
pipeline into P10's dashboards; treat that as this feature's ongoing
production monitoring, complementary to but never a substitute for
P55's own pre-merge lab gate.

### Epoch K — Repository hygiene sweep (P56)

**P56 — Typo & non-English content sweep.** A repo-wide pass fixing
typos and confirming every first-party comment/string is in English.
Language/translation catalogs (the gettext-based `language/` tree) are
the one authorized exception — they exist specifically to hold every
supported locale; this phase is about source that's supposed to be
English-only and isn't.

Scope: source comments and docblocks, PHP and TypeScript/JavaScript
identifiers, template text, CSS class/selector names, CLI subcommand and
option names (P12), config keys and env-var names, OpenAPI/REST field
names (P27), and `docs/*.md`. Two concrete seed findings, found while
auditing dead branches for salvageable work, motivate the phase rather
than it being speculative:

- A `rigth` → `right` CSS-class typo, present consistently across 6
  named Latte templates (`history.latte`, `navigation_bar.latte`,
  `tags.latte`, `comments.latte`, `user_list.latte`,
  `user_activity.latte`) plus `themes/admin/default/theme.css:969` — 7
  confirmed sites. The seed audit also flagged `pagination-arrow` usage
  "generally" as a likely-broader pattern it didn't fully enumerate, so
  treat 7 as a floor, not a settled count — re-run the grep against the
  actual repo at implementation time rather than trusting this seed list
  verbatim, especially since P56 runs after P31 has already rewritten
  every one of these templates as Latte. Must be fixed as one coordinated
  rename across every site actually found — a template-only fix desyncs
  the markup from the CSS selector and regresses that page's arrow icon.
- A genuine French comment in
  `themes/admin/default/js/photos_add_direct.js:168`
  (`// Création de la liste avec plupload_id : image_name`), plus a
  French-flavored `n°` inside a dead `console.log` in
  `themes/admin/default/js/history.js:420`.
- Two spelling issues inherited from upstream Piwigo itself (real in
  `origin/16.x`, so present again from this rewrite's very first
  commit): `representant` → `representative` (a French-derived
  misspelling — a live PHP variable/method name, a Latte template
  variable referenced across 130+ templates, and one live translatable
  UI string an admin actually sees, `'Find a new representant by
  random'`), and `occured_on` → `occurred_on`, a **live database
  column name** in the activity-log table. Fix `occured_on` in the very
  first migration that creates it, not as a later rename — there's no
  production data yet to migrate around, which is the one time this
  exact fix is nearly free. `representant` is lower-risk (no schema
  impact) but touches enough surface area (naming + every referencing
  template + one translation string) that it's worth fixing early too,
  before real usage/documentation accumulates around the wrong name.

Detection approach: a plain non-ASCII grep is too noisy to triage
directly — em/en-dashes, curly quotes, and this file's own `§`-numbered
cross-reference convention (comments citing earlier plan sections, e.g.
`SqlDialect.php`/`SearchRepository.php`/`SearchFilterClause.php`) are
all false positives, as is legitimate non-English content like
`InflectorFr.php`'s French pluralization rules or
`ExtensionScanner.php`'s "Français" language-name string. Needs an
allowlist of legitimate typographic Unicode plus known-legitimate
non-English tokens (language names, `Fr`/`De`/etc.-suffixed inflector
classes) before what's left can be triaged as a real typo or stray
non-English text worth fixing.

**Use `typos` (the Rust-based spell-checker), not a hand-rolled grep, as
the actual detection tool — considered and decided here rather than left
implicit.** Its default English-word dictionary already flags real
misspellings (`rigth`, `occured`, `representant`'s "spelled wrong for
English" half) without matching ordinary code identifiers, and its
`_typos.toml` config's `[default.extend-words]`/
`[default.extend-identifiers]` sections are exactly the allowlist
mechanism above already needs — building both independently would
duplicate the same allowlist twice. A hand-rolled grep stays only as a
narrow supplement for what `typos` can't see: the project-specific
non-English-text check (stray French comments, `n°`-style artifacts),
which is a "is this English at all" question rather than a spelling one.

**Wire `typos` into CI as a real, build-failing gate once the one-time
sweep lands — matching every sibling cleanup phase (P32, P44, P55) that
pairs a one-time pass with a lasting enforcement mechanism, not a check
that only ever ran once and then silently drifted.** Acceptance
criterion for this phase: zero non-allowlisted `typos` findings against a
fresh clone, and the CI job introduced here reproduces that same
zero-findings result using only the checked-in `_typos.toml` — no
locally-applied exclusions that aren't captured in the committed config.

**The broad repo-wide sweep itself is best run late**, after P39–P52
land — those phases touch most of the same template/CSS/JS surface this
sweep also needs to read, so sweeping first would mean re-sweeping the
same files again once they're rewritten. The two seed findings above are
the exception, and both already carry their own forward-pointer at the
point they're naturally touched rather than waiting for this late phase:
`occured_on` gets fixed in its own originating migration, per P15's own
text above ("Name the activity/history log table's timestamp column
`occurred_on`"); `representant` gets fixed on the PHP side per P19's own
text above ("Spell it `representative`, not `representant`") and on the
template side per P31's own text above ("Fix the `representant` →
`representative` template-variable typo"). Neither stays open until this
late phase just because the broad sweep runs late.

## Greenfield tracks (T3, cuttable — outside the P0–P56 backbone)

All entirely cuttable, never gating a backbone commit, dropped first on
overrun. None have started; each depends on backbone phases that have not
landed.

- **T3·WEB** — six independently-cuttable sub-items, not one bundle
  (cut whichever haven't landed on overrun, not all-or-nothing). Depends
  on P36 (asset pipeline), P31/P33 (Latte templates) and P52 (CSS
  architecture).
  - **PWA** — installable manifest + service worker for offline gallery
    browsing (cached derivative images and shell markup, not full
    offline upload/edit).
  - **View Transitions** — the `document.startViewTransition()` API for
    same-document navigation between gallery pages (category → image,
    pagination), not a cross-document transition.
  - **Speculation Rules** — prerender/prefetch hints for likely-next
    navigations (next page of a category, next image in a lightbox
    sequence). **Must not prerender any page with side effects on load**
    — a plain image/category page is safe, but any URL that mutates
    state as a GET side effect (a "mark as read"/view-count-incrementing
    endpoint, if one exists on a page URL rather than behind an explicit
    action) is not; audit every candidate URL for this before adding it
    to a speculation ruleset, not after a prerender silently double-fires
    a mutation.
  - **JSON-LD** — structured data (`ImageObject`/`ImageGallery` schema.org
    types) for image/category pages. Soft-depends on P53 (picture
    pipeline) for the width/height/thumbnail-URL fields a correct
    `ImageObject` needs — land after P53 if both are in scope, rather
    than emitting incomplete structured data first and back-filling it.
  - **SRI** — `integrity=` hashes on script/link tags. Its value is
    real only for assets served cross-origin (a CDN front, a different
    origin than the page); P36's own content-hashed filenames already
    give same-origin assets equivalent tamper-evidence at the URL level
    (a swapped file's hash changes, so its old path 404s rather than
    silently serving stale/tampered content). Decide explicitly: skip
    SRI entirely unless/until a CDN-fronted deployment is actually in
    scope, rather than adding `integrity=` attributes that guard against
    a threat the same-origin deployment doesn't have.
  - **Resource hints** (`preconnect`/`dns-prefetch`/`preload`) — for the
    image-serving origin and any third-party origin actually in use
    (fonts, if not self-hosted).

  Forward-pointer: run T3·WEB's Lighthouse-visible sub-items (PWA
  installability, resource-hint-driven load performance) against P55's
  Lighthouse categories once both exist, rather than measuring them ad
  hoc outside that gate.
- **T3·AI** — the least-specified item in this list; scope not yet
  designed beyond its two dependencies (P19, P27). Give it the same
  one-line feature enumeration as its siblings once scoped, rather than
  leaving "depends on P19 and P27" as the entire spec indefinitely.
  Explicitly resolve its relationship to T3·RIDERS' "vector/CLIP search"
  item before either is designed — these read as the same feature
  (embedding-based image similarity search) described twice under two
  different track names; treat T3·RIDERS' entry as the canonical one
  (already hosted at P27/domain tier above) and fold T3·AI into it,
  unless a real scoping pass finds T3·AI covers something genuinely
  distinct (auto-tagging, caption generation) that vector search alone
  doesn't.
- **T3·RIDERS** — CQRS, libvips/HEIC, vector/CLIP search, webhooks,
  Fibers, Mercure, soft delete. Each is hosted on its own backbone
  phase — named explicitly here since none of the six besides Mercure
  (P11, already tagged "T3 rider" at SEC-61) appears anywhere else in
  this document, making "hosted on its own backbone phase" otherwise
  unverifiable: CQRS → P14/P17–P20 (the ORM and domain-tier phases a
  read/write-model split would sit on top of); libvips/HEIC → P19 (the
  same Image-domain upload pipeline the libvips migration is detailed
  against below); vector/CLIP search → P27/domain tier, and reconciled
  with T3·AI below rather than designed twice; webhooks → P34 (event
  system rewrite — a webhook is an outbound delivery mechanism for the
  same event dispatch that phase already owns); Fibers → P7 (kernel/boot
  skeleton, where worker-mode timing is already an explicit decision).
  **Soft delete is categorically riskier to retrofit than the other five
  — a cross-cutting persistence semantic touching every query and FK
  policy already decided in P15, not a purely additive capability like
  the others — so it doesn't sit at the same undifferentiated tier even
  though it's still cuttable.** Host it at P14/P15 and require the
  decision (adopt now vs. explicitly defer) no later than P15, even if
  the implementation itself is deferred as a rider — retrofitting a
  cascade-delete/RESTRICT policy already committed across every table is
  far more expensive than adding it while FK actions are still being
  decided table-by-table. **Not riders, despite an earlier draft of this
  list including them: tus uploads and passkeys/OIDC.** Both are already
  mandatory, non-cuttable scope elsewhere in this document with no stated
  fallback if cut — tus is baked into P27's own phase title ("REST +
  OpenAPI 3.2 + tus") and SEC-65's replay-store design already assumes
  it's built; WebAuthn/passkeys and OIDC SSO are flat P28 scope, and
  SEC-55 (unlike SEC-61's Mercure row, which is explicitly tagged
  "T3 rider") carries no cuttable tag. Listing them here contradicted
  both — removed rather than downgrading P27/P28, since neither shows
  any other sign of being designed as optional.
- **T3·UPLOAD-ASYNC** — move the upload/representative-generation
  pipeline's `exec()`-based conversion work (PDF/HEIC/TIFF/PSD/EPS via
  ImageMagick/Ghostscript, video posters via ffmpeg) off the request
  path entirely. Justified on availability/throughput grounds alone,
  independent of the security work below — design and land this rider
  first, not gated behind T3·UPLOAD-SANDBOX.
- **T3·UPLOAD-SANDBOX** — network/capability isolation for the same
  conversion pipeline, for a SaaS/multi-tenant threat model
  specifically. Layers onto T3·UPLOAD-ASYNC's own worker container once
  it exists, rather than justifying going async in the first place.

Both upload riders depend on P19 (Image domain, where `UploadService`
lives) and are detailed further below. Each is hosted
on its own backbone phase.

The **legacy import tool** (`bin/piwigo import:legacy`) is the one
non-cuttable exception in this group — T2 adoption tooling, not a rider.
Depends on P12 (CLI framework the command itself is built on) and P15
(the FK-constrained target schema it imports into — see P15's own
cross-reference to this tool above, "Cross-reference the legacy import
tool explicitly"). Verification: a fixture-based import against a real
legacy `piwigo` database dump, asserting record-count parity per table
(images, categories, comments, tags, users) and metadata parity on a
sampled subset (EXIF fields, album hierarchy, tag associations) between
the source dump and the imported rows — not just "the command exits
zero."

**The physical asset tree — every real photo/derivative file, not just
the DB rows describing them — is this tool's other half, missing from
every prior draft of this section.** Metadata parity means nothing if
`ImageEntity::$path` points at a file that was never actually moved.
Decide explicitly, as part of this phase, rather than discovering it
mid-import: copy (safe, doubles disk usage for the migration window, no
risk to the source install), move (no doubled disk usage, real risk if
the import aborts partway and the source install still expects the
files at their old location), or symlink (fast, zero extra disk, but
means the new install has a hard runtime dependency on the old
install's filesystem staying mounted and unchanged forever, which
contradicts "adopted and independent" the way a symlinked config file
would). Default: copy for v1, matching this document's own "full
backups only, not incremental" risk posture elsewhere — an aborted or
partial import must never leave the source install's own files
mutated. Verify file-level parity the same rigorous way as the DB rows:
a checksum comparison (not just "the file exists at the new path") for
every imported image and its derivatives, sampled at the same rate as
the EXIF/metadata check above, and a real disk-space preflight check
before starting (the command's own responsibility, not a
runbook-only warning) — a copy-based import that runs out of disk
mid-way through a multi-gigabyte gallery is a real, likely failure
mode worth catching before it starts, not after.

**T3·UPLOAD-ASYNC, detailed — a real availability problem, not a
security one.** Today, `UploadService::addUploadedFile()` runs every
representative-generation `exec()` call synchronously inside the
request, blocking a request-serving worker (FrankenPHP or otherwise)
for the subprocess's full duration. Under any real concurrent-upload
load — a SaaS trait, but true for any multi-user deployment — a handful
of slow PDF/video conversions starve the worker pool and degrade the
whole app's responsiveness. This is a plain throughput problem, worth
fixing on its own merits with zero additional sandboxing.

The redesign's real cost is not the queue/infra plumbing — it's that
`$this->eventDispatcher->dispatch(new UploadFile(...))->representativeExt`
today returns synchronously and feeds straight into the same
`ImageInsertRow` in the same request/transaction. Moving to async needs:

- A **"pending representative" state** on the image row
  (`representativeExt` starts null, gets set on job completion) —
  touches every place a representative is currently assumed
  ready-by-construction: admin thumbnails, `DerivativeImage::url()`, and
  the cache-priming `HttpClientService::fetch()` call at the end of
  `addUploadedFile()`.
- A queue between `UploadService` and a worker pool performing the
  actual `uploadFilePdf()`/`uploadFileHeic()`/`uploadFileTiff()`/
  `uploadFileVideo()`/`uploadFilePsd()`/`uploadFileEps()` logic (a
  near-straight port of the existing methods) — build on P11's own
  Messenger infrastructure and its failed-job visibility rather than a
  second, parallel queue mechanism.
- Job idempotency (safe redelivery), dead-lettering for permanently-
  failing jobs, and orphaned/timed-out job handling.
- An **inline/synchronous execution mode for the same worker code**, so
  the existing deterministic Pest/integration suite (settle-then-assert,
  no polling, `Env::now()` frozen) isn't forced to wait on a real queue
  round-trip in tests — the same test-mode-vs-real-mode split already
  established elsewhere in this plan for other async mechanisms.

Rough effort: medium-large (multi-week) — most of the cost is the
pending-representative state machine and the call sites that assume a
representative is synchronously available, not the queue plumbing
itself. Depends on P11 (Messenger/failed-job visibility) and P19.

**T3·UPLOAD-SANDBOX, detailed — SaaS/multi-tenant threat model: assume
untrusted uploaders by default, not as the edge case.** Blast radius
under this threat model includes *other tenants'* data — SSRF to cloud
metadata endpoints and cross-tenant access are the top concerns, not
just "a bad file got uploaded." Three isolation options, compared:

- **A — lightweight in-process sandboxing** (`bwrap`/`firejail`/seccomp +
  non-root UID + dropped capabilities, wrapping the existing `exec()`
  calls in place). Shares the host kernel — mitigates but doesn't fully
  contain a kernel-exploit-class escape. Near-zero overhead, no
  architecture change, small effort (days): one helper wrapping the
  existing SEC-16 command-string pattern across the 6 `uploadFile*`
  handlers, the real work being tuning read-only binds (fonts/config
  dirs) without breaking real conversions. Worth doing regardless — it's
  effectively free — but not sufficient alone for this threat model;
  best as defense-in-depth *inside* Option B below, not standalone.
- **B — dedicated worker container** (queue-based — built on
  T3·UPLOAD-ASYNC's own worker, not a second one; network-isolated with
  no egress; read-only rootfs except a scratch dir; dropped
  capabilities; its own pinned `policy.xml`/ffmpeg build per SEC-69/
  SEC-72 instead of inheriting the host's). Real container isolation
  closes the SSRF-to-metadata path (no route out) and the cross-tenant
  path (the worker never sees the DB or other tenants' credentials —
  least privilege by construction). **Recommended baseline** once a
  SaaS/multi-tenant deployment is in scope — layers directly onto
  T3·UPLOAD-ASYNC's own worker container rather than requiring a second,
  separate one.
- **C — per-job microVM** (Firecracker or gVisor), reserved for later and
  only for the specific job types that hit Ghostscript/ffmpeg (PDF/EPS/
  PSD/TIFF/HEIC/video) if/when serving genuinely public/anonymous
  uploaders — GD-decoded JPEG/PNG/WEBP/GIF doesn't need VM-grade
  isolation. gVisor is a small marginal cost over B (same worker image,
  swap the OCI runtime); Firecracker is a much larger, months-scale
  undertaking (VM pool orchestration, snapshot/restore, guest
  rootfs/kernel maintenance) even with OSS tooling to lower the build
  cost. If C is ever adopted, prefer gVisor as a near-free upgrade on
  top of an already-working B, not Firecracker.

Non-root/dropped-capabilities hardening (container `USER` non-root,
`--cap-drop=ALL`, `--security-opt=no-new-privileges`, read-only rootfs,
user-namespace remapping) stacks on top of any of A/B/C — it closes
escalation-inside-the-sandbox and most capability-dependent escape
techniques, but does **not** by itself close the SSRF-to-metadata or
cross-tenant-access paths, which need Option B's real network/filesystem
isolation regardless.

**libvips migration (T3·RIDERS' own "libvips/HEIC" item) — security
impact, evaluated here since it interacts directly with this rider.**
Real reduction in attack surface, not "problem solved": kills the entire
ImageMagick delegate/shell-execution architecture (the ImageTragick/
"GhostButt" class — MVG/MSL content-sniffing, `@`-prefixed local-file-
read, URL/HTTPS delegate SSRF; libvips has no delegate mechanism, it
links against loader libraries directly) and retires the unpinned-
`policy.xml` dependency (SEC-69) in favor of libvips's own
`VIPS_BLOCK_UNTRUSTED`/`vips_operation_block_set()` loader allowlist. But
it changes shape rather than disappearing, and needs explicit decisions
— **make these decisions as part of the libvips migration's own
acceptance criteria, not implicit:**

1. **Open question resolved: CLI-invoked (`vipsthumbnail`/`vips`), not
   in-process `php-vips` bindings.** No prior attempt has implemented
   either — genuinely unexplored code-wise — but the choice follows
   directly from why this rider exists at all: T3·UPLOAD-SANDBOX's entire
   purpose is strengthening the process/trust boundary around untrusted
   upload input, and in-process bindings would give up exactly the
   property the rest of this rider is built to establish. Today a
   Ghostscript exploit runs in a separate OS process, sandboxable at
   Option A's own granularity; in-process libvips means a decoder
   memory-corruption bug runs inside the PHP worker's own address space
   (DB credentials/session state, and under worker mode, potentially
   other requests' data) — a strictly worse starting point than what
   this rider is trying to fix. CLI-invoked keeps the free process
   boundary and reintroduces only a small single-tool
   command-construction surface, already governed by SEC-16's existing
   discipline. Accept the small performance cost of a subprocess call
   per operation; it's the same trade this document already makes
   everywhere else security and raw throughput compete.
2. A different, not zero, set of trusted decoders (poppler/pdfium for
   PDF, libheif for HEIC, native TIFF, the same libjpeg-turbo/libpng/
   libwebp GD already wraps for JPEG/PNG/WEBP) — smaller and more
   actively fuzzed (OSS-Fuzz) than ImageMagick's 100+-coder legacy
   surface, but still C, still capable of memory-corruption CVEs.
3. Decompression-bomb protection (SEC-67) is still opt-in — libvips's
   streaming pipeline helps for operations that support it, but an
   explicit width×height check before full processing is still needed;
   cheaper to add since vips can read headers without a full decode.
4. **Format coverage gap: PSD and EPS have no libvips loader.** Either
   drop `uploadFilePsd()`/`uploadFileEps()` representative generation
   entirely, or keep a narrow, still-`exec()`-based ImageMagick/
   Ghostscript fallback for just those two formats — SEC-16's hardening
   work shrinks from 6 handlers to 2 rather than fully retiring. Decide
   explicitly, don't discover this mid-migration.
5. SVG is not currently rasterized via GD/Imagick anywhere in this
   pipeline (only referenced as an extension pass-through) — librsvg's
   memory-safety win (a Rust-rewritten core in modern versions) only
   becomes relevant if this migration adds new SVG-thumbnail-
   rasterization functionality; it's not risk already present today.

Sequence this rider **before or alongside** the libvips migration so the
new decode path is designed against the isolation boundary from day
one, not retrofitted afterward.

## Execution approach

1. **Write tests first**, or in the same commit group.
2. Read the target state of the equivalent code on `16.x-rewrite`
   (`../piwigo16-rewrite`) — for reference only. **This step doesn't apply
   to a GREENFIELD-kind phase** (P29's contract redesign, P53/P54's new
   features, P56's sweep) that has no reference-branch equivalent to
   read in the first place; those phases start from this document's own
   text and real upstream/spec research instead. Every REPLAY-kind
   phase — the large majority, porting existing behavior forward — does
   follow this step as written.
3. **Re-implement manually.** Nothing is git-pulled or cherry-picked from
   either branch. Self-contained files are re-created by hand; greenfield
   items are authored new.
4. `config/container.php` and `config/routes.php` grow incrementally with
   each phase, never reproduced from the reference in bulk.
5. **Fast gate suite (lint, typecheck, unit/integration Pest, PHPStan) after
   every commit group; fix before proceeding, no exceptions.** The
   heavier checks — golden-HTML/VR/Browser suites and a full,
   whole-codebase static-analysis run — follow the periodic-checkpoint
   cadence P40's own text establishes ("budget a real full-suite
   checkpoint every few batches... not only at the very end") for any
   phase doing the same kind of large mechanical conversion, rather than
   running in full after every single commit group; a phase not doing
   that kind of bulk conversion runs the full heavy suite every commit
   group same as the fast gate. Don't read "full gate suite after each
   commit group" as contradicting P40's own explicit cadence guidance —
   this line means the fast gate.
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
phase only if T1/T2 alone is still oversized. **No per-phase time/size
estimate is recorded anywhere in this document to measure "materially
exceeding" against — a gap worth naming rather than leaving implicit.**
Default proxy, until real estimates get recorded per phase: a phase
whose commit count exceeds roughly double what its own T1/T2 scope
description in this document implies (count the named sub-items/gotchas
called out for that phase as a rough unit), or whose elapsed wall-clock
time exceeds two weeks of focused single-person work. Both numbers are a
starting default, not a measured baseline — revisit once a few phases
have actually landed and real duration data exists to calibrate against.

## Risk register

- **P40 is the largest single diff remaining.** Mitigated by the thin
  slice and by converting one page-family at a time; two rendering models
  coexist during the transition.
- **P43's no-escape-hatch decision means core must be extended for novel
  needs.** Accepted explicitly; the consequence already absorbed is
  plugin-owned routes. **Revisit trigger, since an accepted-forever risk
  with no monitoring signal tends to just get forgotten**: if real plugin
  authors (once P29's contract ships) repeatedly request the same
  specific extension point core doesn't provide, that's the signal to add
  it to core rather than reconsidering the no-escape-hatch decision in
  the abstract — track via plugin-support-request volume, not
  preemptively.
- **P43's built-in filter swaps have real semantic differences.** Named
  list, from P43's own text above (*"Migrate Smarty duplicates onto Latte
  built-ins"*): `count` → `|length`, `date_format` → `|date`, `nl2br` →
  `|breakLines`, `strip_tags` → `|striptags`, `join` → `|implode`, `cat` →
  `~`. One difference already confirmed there — Smarty's `strip_tags`
  replaces a tag with a *space*, Latte's `|striptags` does not — verify
  the remaining five the same way (read each pair's actual behavior, not
  assume equivalence from the name) before treating the swap as
  behavior-preserving; golden-HTML catches whatever a manual read misses.
  Owner: P43.
- **P36's fork is decided (view-declared) — shell-last composition is
  unnecessary once P41's migration completes.**
- **P52's Tailwind decision, resolved: not adopted.** No longer gates
  P40's start. The admin theme's `roma`/`clear` partial-skin model
  (`theme.json`'s `loadParentCss`) composes with cascade-layer +
  custom-property overrides, not with utility classes baked into
  markup; native CSS closes the same ergonomic gap without a third
  `class=` pass across all 135 templates or a new build dependency.
- **P29 breaks external extensions by design** — an accepted product
  decision, not an oversight. In-tree callers migrate in the same phase.
- **A short-circuit response caught only at the innermost middleware
  layer breaks silently, not loudly.** See P9/P23's own guidance above —
  a bootstrap-phase middleware that short-circuits without the
  catch-at-every-nesting-level fix still "works" (the request completes)
  while quietly losing security headers and timing headers and spamming
  the error monitor with false errors — exactly the kind of regression a
  happy-path test suite won't catch. Build the full-depth catch and the
  bootstrap-phase middleware together, not the second without the first.
- **MySQL 9.x is a non-LTS line.** **Decision, recorded here rather than
  left for a future pass: pin MySQL 8.4 (Oracle's LTS line since April
  2024), not a 9.x Innovation release, in P4's runtime image — no exact
  version is pinned anywhere else in this document, and P4 (the
  container/runtime-image phase) is the actual owner of the image tag
  this decision sets.** 9.x's short-lived Innovation-release cadence
  (superseded every few months, no multi-year support commitment) is a
  poor fit for a container image meant to stay stable across this
  rewrite's own multi-phase timeline; 8.4 gets multi-year vendor support
  and is schema/feature-compatible with everything this document's own
  MySQL-specific guidance already assumes. Revisit this specific pin at
  P4 implementation time — Oracle's stated cadence calls for a new LTS
  roughly every two years from 8.4, so a newer LTS may already exist by
  then. Hedge via the MariaDB/PostgreSQL provider matrix regardless of
  which exact MySQL minor is pinned.

**On the "a11y gate."** Plan for there not to be one by default — no
automated accessibility tooling (`axe-core`, `pa11y`, a Lighthouse
`assert` block scoped to the a11y category) unless P55 explicitly wires
one in. **"Gate" specifically means an enforced, build-failing
threshold, not raw score collection** — the Verification list's own
`bunx lhci autorun` line runs from early on and does compute an a11y
score by Lighthouse's own default category set, but with no
`lighthouserc.json` `assert` block configured (see P55), that score is
collected/uploaded only, never asserted against, so it fails nothing.
Don't let that distinction blur in practice: a bare `lhci autorun` in CI
reads, at a glance, like a real gate. Default to VR baseline plus manual
per-template review during P31 and any later template work as the real
(if partial) safety net, and treat "wire a real automated a11y check"
(the `assert` block, not the collection run) as P55's own explicit
decision to make, not an assumed given.

**The manual per-template review needs a concrete minimum rubric, not
just a named intention — a checklist with no items isn't a real safety
net.** Minimum, per converted template, until P55 decides otherwise:
(1) a keyboard-only pass — tab through every interactive element on the
rendered page in source order, confirm focus visibility and that nothing
is reachable only by mouse; (2) a documented WCAG 2.1 AA color-contrast
check (4.5:1 normal text, 3:1 large text/UI components) against that
template's actual rendered colors, not the design tokens in isolation;
(3) every `<img>` has real `alt` text (empty `alt=""` only for genuinely
decorative images) and every form control has a programmatically
associated `<label>`. Record the result (pass/fail per item, not just an
overall pass) alongside that template's own PR — the same discipline the
VR baseline itself already gets, so a later reviewer can tell whether a
template was actually checked or just assumed fine.

## MySQL infrastructure notes

**P15's own text (above) already finalizes this decision: `utf8mb4_unicode_ci`
for every table from the first migration on — record any further
reasoning there, not as a still-open decision to revisit in this
appendix.** `utf8mb4_0900_ai_ci` (MySQL) offers more accurate
multilingual sort than `utf8mb4_unicode_ci`, but MariaDB has no
equivalent (its closest match, `utf8mb4_uca1400_ai_ci`, needs MariaDB
10.10+ and still isn't wire-identical to MySQL's collation) — a prior
attempt's own schema ended up uniformly on `utf8mb4_unicode_ci` for
every table with no recorded reason, plausibly because it's the one
collation with a real equivalent across the whole MySQL/MariaDB/Postgres
provider matrix this project's own multi-provider scope needs. This
project makes the same choice deliberately, for that same reason.

**Carve out one explicit exception to that uniform choice: every
password hash, token, session-id, and HMAC-digest column uses
`utf8mb4_bin` regardless of the project-wide text collation — a P15
rule, not a table-by-table judgment call.** A case-insensitive collation
on a value meant to be compared byte-for-byte silently accepts
case-varied or accent-folded lookups as a match against a stored bearer
token, session identifier, or HMAC signature — a real
authentication/authorization weakening, not a sort-order nuance. Give it
a concrete acceptance test: an `information_schema.columns`
introspection asserting every text-bearing column in the migrated schema
carries the decided collation, except a small, explicit
`utf8mb4_bin` allowlist naming exactly the columns above — run per
provider leg alongside the schema-drift guard already covering MySQL vs.
MariaDB, so a table added later with the wrong collation fails CI
instead of drifting silently.

**Confirm `DYNAMIC` row format (InnoDB's default since MySQL 5.7/MariaDB
10.2) from the very first migration, not assumed implicitly.** The
classic 767-byte InnoDB index-prefix ceiling under `utf8mb4` (4
bytes/char, making `varchar(191)` the practical index-safe ceiling)
applies only to the older `COMPACT`/`REDUNDANT` row formats;
`DYNAMIC` plus `innodb_large_prefix` raises the real limit to 3072
bytes. Getting this wrong doesn't fail loudly at schema-design time — it
surfaces later as a baffling "index prefix too long" error on whichever
column happens to need a longer unique index first, long after the
uniform-collation decision above was made.

Other notes: MySQL 8.0+ has no `.frm` or query cache, and the
`symfony/cache` layer is the intentional replacement, not a gap.
`SET PERSIST` requires the `SYSTEM_VARIABLES_ADMIN` privilege — this is
scoped to a future dedicated ops/maintenance credential, not the
application's own least-privilege runtime DB user, which should never
hold it. Watch `caching_sha2_password`: it has been MySQL 8's default
authentication plugin since 8.0.4, and it needs OpenSSL (or an
already-established secure channel) to authenticate cleanly against
older PDO/mysqli client builds — a common real container-networking
failure mode where an app container and a DB container that otherwise
look correctly configured fail to authenticate, directly relevant to
P4's own runtime-image work. Either confirm the chosen container images'
PHP/MySQL client builds support it cleanly, or pin
`mysql_native_password` explicitly at user-creation time in P4/P15's own
provisioning and record that as the deliberate choice — don't let the
default silently decide it. Replication terminology is
`SOURCE`/`REPLICA` in any future documentation or admin page that
touches it.

**MariaDB is a genuinely distinct schema/query dialect from MySQL, not a
drop-in wire-compatible sibling — verify it independently rather than
assuming "MySQL-compatible" covers it.** A prior attempt's own provider
matrix ran MariaDB down the same code path and migration set as MySQL on
the theory that they share enough SQL surface to share verification too,
and that held for query dialect (the same `RAND()`/`GROUP_CONCAT()`
function-dispatch branches, the same `instanceof
AbstractMySQLPlatform`-style checks genuinely serve both) but broke for
schema *representation*: dumping the identical, fully-migrated schema
from a real MariaDB server differed from the MySQL dump in well over a
hundred lines, not cosmetically — MariaDB has no native `JSON` column
type at all (a `json`-typed column becomes `longtext` plus a
`CHECK (json_valid(...))` constraint there), display widths MySQL 8.4
itself dropped are still emitted, and default-value/collation rendering
differ. A schema-drift CI guard that diffs a live-migrated database
against one committed "MySQL-compatible" dump file is a category error
for this leg specifically — it can never pass, for reasons that have
nothing to do with real drift. Give MariaDB its own committed schema
snapshot (dispatched by platform-class detection ordered so the more
specific `MariaDBPlatform` check runs *before* the general MySQL one it
subclasses — the reverse order silently misclassifies every MariaDB
schema as MySQL's) rather than skipping verification on that leg
entirely. Separately, a MySQL-only FULLTEXT parser extension (`WITH
PARSER ngram`, needed for CJK substring search) has no MariaDB
equivalent at all and must be conditionally stripped per-platform in the
migration itself, not assumed portable — the failure mode is a hard
`CREATE TABLE` error at the very first migration, not a soft degradation,
so an untested MariaDB leg can silently never have created its schema at
all for a long stretch.

**State the floor explicitly: MariaDB 10.11 (the current LTS line,
supported into 2028) is the minimum supported MariaDB version** — pick
it for the same multi-year-support reasoning as MySQL 8.4's own pin
above, and record it here rather than letting whichever version happens
to be on a given developer's machine or CI image become the de facto
floor. `CHECK` constraints are enforced (not merely parsed and silently
ignored) only from MariaDB 10.2 on — comfortably below the 10.11 floor,
so it's not a live constraint at the chosen version, but worth recording
so the reasoning doesn't need re-deriving if the floor is ever
reconsidered. Treat "held for query dialect" as verified specifically
for the functions and `instanceof` checks already named above — the
`RAND()`/`GROUP_CONCAT()` dispatch branches and the `AbstractMySQLPlatform`
checks — not as a blanket guarantee covering every future MySQL-specific
construct: any *new* MySQL function this project reaches for later (a
window function, a JSON path function, a native `SEQUENCE` object —
MariaDB has its own distinct `SEQUENCE` support with no MySQL
equivalent, which is exactly the kind of schema-representation gap the
dump-diff guard above already exists to catch) needs its own explicit
MariaDB verification pass before being trusted, the same discipline the
Postgres checklist below applies per item.

**A CI job whose steps run sequentially with default fail-fast semantics
lets one early failing step silently skip every later step in the same
job — including steps checking something entirely unrelated to the
failure.** A prior attempt had a real, unrelated regression (an
aggregate-based cache-key computation returning an empty string instead
of a fingerprint on any genuinely empty table — `CONCAT()` returns `NULL`
if *any* argument is `NULL` on both MySQL and Postgres, and `MAX()` over
zero rows is `NULL`) that only manifests against a freshly-migrated,
still-empty database, never against a fixture pre-loaded with rows — so
it went undetected in every environment that only ever tests against
populated fixture data. It happened to sit at an early position in a
longer job whose later steps included the schema-dump drift guard, so
once the early step started failing, the drift guard silently stopped
running at all for a real stretch, masking an independent, real schema
staleness the whole time. Two lessons: budget at least one real
verification pass against a genuinely empty/freshly-migrated database,
not only a populated fixture, for anything computing an aggregate over a
table that can legitimately be empty; and treat a CI job's own step
ordering as a real dependency graph worth reviewing occasionally — a
job that silently stops verifying most of what it claims to cover the
moment one early, unrelated step breaks is a structural risk independent
of whatever that first failure actually was.

**Turn the second lesson into an explicit structural rule, not just a
periodic-review habit: checks that don't genuinely depend on an earlier
step's outcome run as independent CI jobs, or as steps carrying their
own `continue-on-error: true`/`if: always()`, never chained
sequentially into one fail-fast job on the assumption that "they're all
schema checks so they belong together."** A shared job is for a real
dependency (step B needs step A's output), not merely a shared topic.
Name P2 (Test harness) as the owning phase for building the
empty/freshly-migrated-database test lane the first lesson calls for —
it already owns the fixture/test-harness design this lane is a sibling
of, rather than leaving it implicit for whichever phase happens to add
the first aggregate query.

**PostgreSQL/multi-provider portability checklist.** If real Postgres
support is in scope (a prior attempt validated it end to end, including a
real install/upload/search flow against a live server), expect these
specific, recurring MySQL-vs-Postgres semantic gaps — every one of them
was found live, not by reading documentation, and most recurred across
many independent files because the same underlying habit repeats
throughout a codebase once established:

- **Boolean columns are a completely different wire representation.** A
  raw (unmapped) fetch returns a native PHP `bool` on Postgres but a
  numeric `1`/`0` on MySQL for the identical logical column — any
  `is_numeric()`-gated cast needs `(int) (bool) $value` to normalize
  either representation, and a bare integer literal (`visible = 1`)
  spliced into raw SQL text against a genuine boolean column is *rejected
  outright* by Postgres ("column ... is of type boolean but expression is
  of type integer"), not silently tolerated the way MySQL treats it. This
  is the single most common portability bug in the whole class — turn the
  one-time remediation grep into a standing CI lint (a regex scan of
  tracked `.php`/`.sql` files for a known boolean column name compared
  against a bare `0`/`1` literal) rather than a single point-in-time
  sweep, since the underlying habit — writing a boolean comparison the
  way MySQL happens to tolerate — recurs every time a new call site is
  added, not only in whatever was live when the sweep ran. Apply it to
  every real boolean column compared against a bare `0`/`1` literal in
  raw SQL text (including test fixture setup/teardown SQL, which breaks
  just as hard and often masks itself as an unrelated cascading failure
  in whatever test runs next). There's a second, more insidious layer of
  this same bug class specific to any *unbuffered/raw driver-level*
  fetch (`pg_fetch_assoc()` and similar): Postgres represents a boolean
  column as the literal **string** `'t'`/`'f'`, not a native bool the way
  a properly-typed DBAL/ORM fetch does — and both a bare `(bool)` cast
  (`'f'` is a non-empty string, so `(bool) 'f'` is `true`) and a bare
  `(int)` cast (`(int) 'f'` is `0` either way, coincidentally "right" but
  for the wrong reason) mishandle it silently, with no error to surface
  the mistake. Write one shared, explicit normalization helper for this
  specific representation (not a bare cast) anywhere code reads a
  Postgres boolean column through a raw/unmapped fetch path.
- **Integer division truncates on Postgres, not on MySQL.** MySQL's `/`
  always computes in decimal context regardless of operand types;
  Postgres's `/` on two integer columns truncates to an integer. Any
  ratio/percentage computed from two integer columns needs an explicit
  decimal-context promotion (`col * 1.0 / other_col` — DQL has no
  `CAST()`, so this is the portable idiom in both raw SQL and DQL). A
  genuinely zero divisor is a second, distinct trap on top of the
  truncation issue: MySQL's `/` silently returns `NULL` for
  division-by-zero, but Postgres *raises a real exception*
  ("division by zero"), turning a soft null result into a hard 500 —
  wrap the divisor in `NULLIF(divisor, 0)` to force the same
  MySQL-compatible `NULL` result on both platforms rather than letting a
  degenerate zero-valued row crash the request.
- **Postgres's extended query protocol enforces a bound parameter's
  inferred column type strictly; MySQL doesn't.** An out-of-range
  literal compared against a narrow integer column (e.g. a `smallint` id
  column) is just a harmless `false` comparison on MySQL, but Postgres
  rejects the value outright before the query can even run, throwing an
  exception instead of returning "not found." Any lookup-by-id path that
  relies on "a nonexistent/out-of-range id just returns no rows" needs an
  explicit range check ahead of the query on Postgres, not just a
  not-found handler after it. Two more MySQL constructs with no direct
  translation, need a redesign: a multi-table `UPDATE ... INNER JOIN ...
  SET` (rewrite as `UPDATE ... SET ... FROM ... WHERE`), and
  `JSON_OBJECT()`/`JSON_SET()` (Postgres's equivalents are
  `jsonb_build_object()`/`jsonb_set()` — different names, not just a
  different dialect of the same call).
- **PostgreSQL's bare `timestamp` type defaults to microsecond
  precision; MySQL's `TIMESTAMP` defaults to whole seconds.** This bites
  specifically when a strict application-side date parser (a value object
  with an exact `Y-m-d H:i:s` format) reads back a value the *database
  itself* computed (a raw `NOW()`/`now() + INTERVAL ...` write, or a
  server-managed `DEFAULT now()`/update-trigger column) rather than one
  the application computed and formatted itself — the fractional seconds
  Postgres silently keeps make the strict parser throw on every read,
  while the identical column round-trips fine on MySQL. Declare
  `timestamp(0)` explicitly on every such DB-populated timestamp column
  on Postgres to match MySQL's real precision, and audit for this
  specifically wherever a strict date/time value object is introduced
  onto an *existing* schema — it's easy to fix every column an app writes
  to and still miss the ones only the database itself ever writes.
- **Identifier and string-literal quoting are both non-portable, in
  different ways.** Backtick-quoting is MySQL-only. A *double-quoted*
  value means an identifier reference on Postgres but, under MySQL's
  lenient default SQL mode, a plain string literal — the same source line
  can be valid-but-wrong on one platform and a hard syntax error on the
  other. Always resolve identifier quoting through the real
  `Connection::getDatabasePlatform()->quoteSingleIdentifier()` (or the
  enum/dialect-branch shape already established for `RAND()`/`REGEXP()`
  when no `Connection` is available in scope), and always use
  single-quotes for actual string literals. Backslash-escaping a quote
  inside a string literal (`'o\'brien'`) is also MySQL-only — Postgres's
  default `standard_conforming_strings=on` doesn't support it and parses
  garbage; use SQL-standard doubled-quote escaping (`'o''brien'`),
  portable on both.
- **Unquoted identifier case-folding goes in opposite directions.**
  MySQL preserves an unquoted identifier's case as written (on the
  common case-sensitive-filesystem/`lower_case_table_names=0` config);
  Postgres silently *lowercases* every unquoted identifier at parse
  time, so an unquoted mixed-case column or table name written into a
  migration or raw query is a completely different, case-preserved
  identifier on one platform and a silently-lowercased one on the
  other. This is a genuinely silent bug, not a syntax error either way —
  a migration authored and only ever run against MySQL can create a
  `CamelCase`-named column that a later, unquoted Postgres query against
  the "same" name simply never matches, with no error to surface the
  mismatch. Standardize on lower_snake_case for every identifier from the
  first migration on, so quoting-dependent case never matters in
  practice, rather than relying on quoting discipline alone to prevent
  it.
- **`LIKE`/`NOT LIKE` are case-sensitive on Postgres by default; MySQL's
  default collation makes them case-insensitive.** A query written and
  verified against MySQL that relies on `LIKE` for a case-insensitive
  substring match (a search-box "contains" filter, a username lookup)
  silently stops matching differently-cased input the moment it runs
  against Postgres — no error, just fewer results. Use Postgres's
  `ILIKE` explicitly wherever the intent is genuinely
  case-insensitive, branched the same way `RAND()`/`REGEXP()` already
  are per platform, rather than assuming `LIKE`'s behavior travels.
- **`GROUP BY` functional-dependency inference is not equally smart.**
  MySQL's `ONLY_FULL_GROUP_BY` reasons *transitively* through a join (a
  column determined by a joined table's own primary key is allowed even
  if not itself in the `GROUP BY` list); Postgres's equivalent check does
  not reason transitively — only a table's own primary key appearing
  directly in `GROUP BY` is recognized. A query that passed MySQL's
  strict-mode audit (P14's own `ANY_VALUE()` checklist) can still fail on
  Postgres for exactly the columns reached only through a join.
- **Never trust a query's row order without a real `ORDER BY`.** MySQL
  and Postgres's query planners can and do choose different real
  orderings for an otherwise-identical unordered query — a query that
  "happened" to return rows in a convenient order on one platform is a
  latent, previously-invisible ordering bug, not a portability nuance. If
  the caller genuinely depends on row order, add an explicit `ORDER BY`;
  if it doesn't, fix the caller (or the test) to not depend on unordered
  output rather than pinning a specific accidental order.
- **NULL sorts to a different end of an ordered result on MySQL vs.
  Postgres, with no portable syntax to force either behavior on both.**
  MySQL always treats NULL as the smallest possible value (`ASC` puts
  NULLs first, `DESC` puts them last); Postgres's default is the exact
  opposite (`NULLS LAST` for `ASC`, `NULLS FIRST` for `DESC`). Any
  `ORDER BY` on a nullable column — even a bare admin-configurable "sort
  gallery by date" default — silently changes which end of the list
  undated/unset rows land on purely by switching database provider.
  Neither engine has one portable "NULLS FIRST/LAST" syntax MySQL
  understands at all, so the portable fix is a `CASE WHEN col IS NULL
  THEN 0 ELSE 1 END` discriminant ordered ahead of the real column, in
  the same direction as the real column's own sort — build this into one
  shared sort-rendering helper once, since it applies to every nullable
  sortable column a gallery-style listing exposes, not just one query.
- **`SELECT DISTINCT ... ORDER BY <expr not in the SELECT list>` is valid
  on MySQL and a hard error on Postgres** — every `ORDER BY` expression
  must appear in the `SELECT` list under `DISTINCT`. A `RAND()`-ordered
  distinct query has no portable direct translation; redesign around
  `GROUP BY` on the real dedup key instead where the join can otherwise
  produce duplicates.
- **Several common MySQL constructs have zero direct Postgres
  equivalent and need a redesign, not a syntax swap**:
  `SQL_CALC_FOUND_ROWS`/`FOUND_ROWS()` (Postgres's parser reads the bare
  token as a bogus column reference — use a real `COUNT(*) OVER()` window
  function when the query shape allows it, a separate `COUNT(DISTINCT
  ...)` query when it's a `DISTINCT` query `COUNT(*) OVER()` would
  mis-total); `UPDATE ... ORDER BY ... LIMIT` (no Postgres equivalent at
  all — rewrite as a portable subquery selecting the target row, wrapped
  in an extra derived-table `SELECT` specifically because MySQL itself
  separately rejects a subquery that references the very table being
  updated unless it's first materialized as a derived table); inline
  `UNIQUE KEY name (...)` and `ENGINE=...` DDL shorthand (use the
  ANSI-standard `CONSTRAINT ... UNIQUE (...)` form and a driver-aware
  engine-clause branch).
- **MySQL's `ENUM` has no direct Postgres equivalent — it's a redesign,
  not a type-name swap.** MySQL's `ENUM` is an inline, per-column list of
  string values; Postgres's closest match is a named, schema-level type
  created once with `CREATE TYPE ... AS ENUM (...)` and referenced by
  name from any column that uses it, with new values added later via
  `ALTER TYPE ... ADD VALUE` (which itself cannot run inside the same
  transaction as a statement that uses the new value, on Postgres
  versions before 12). The two simplest portable options are a plain
  `VARCHAR` with an application-level/`CHECK`-constraint validation (this
  project's own established default given `ON DELETE RESTRICT`-by-default
  precedent for explicit, visible constraints elsewhere in this phase),
  or committing to Postgres's native named-type mechanism and accepting
  its own migration ceremony for adding values later — decide once, per
  column that needs it, rather than defaulting silently to whichever
  Doctrine's own platform abstraction happens to emit.
- **Row-locking clause syntax and semantics diverge.** MySQL's `SELECT
  ... LOCK IN SHARE MODE` has no direct Postgres spelling — Postgres's
  equivalent is the SQL-standard `SELECT ... FOR SHARE` (and MySQL 8
  itself now also accepts `FOR SHARE`, making that the portable choice
  for any code written from here on rather than the legacy MySQL-only
  clause). `SELECT ... FOR UPDATE` is shared syntax on both platforms,
  but Postgres additionally supports `FOR UPDATE SKIP LOCKED`/`NOWAIT`
  modifiers MySQL 8 also has — verify any such modifier is actually
  exercised on both platform legs before relying on it, rather than
  assuming shared syntax implies shared behavior under real lock
  contention.
- **A framework's generic cross-platform SQL abstraction can resolve to a
  semantically *different* operator, not just a different spelling of the
  same one — verify empirically per platform, never trust the
  abstraction blindly.** Delegating "get me a regexp operator for this
  platform" resolved to Postgres's `SIMILAR TO`, which implicitly anchors
  the *whole* string — silently breaking every substring-search pattern a
  real caller needs. The fix was branching explicitly onto Postgres's own
  POSIX `~` operator instead of trusting the framework's generic
  resolution.
- **A custom DQL date-arithmetic function against a bare bound parameter
  (not a column reference) can fail on Postgres because it can't infer
  the parameter's real type from context** — Postgres needs an explicit
  cast (`::timestamp`) on the parameter side; an ORM-level parameter Type
  annotation does *not* help, since the low-level driver binding only
  sends a generic `ParameterType` enum value over the wire, never a real
  timestamp OID.
- **`AUTO_INCREMENT`/identity-sequence semantics diverge for
  explicit-value seed inserts.** MySQL's `AUTO_INCREMENT` tracks the
  highest value ever inserted regardless of whether it arrived via an
  explicit value or the column default, so a later default-value insert
  never collides with an earlier explicit one. Postgres's identity
  sequence does *not* auto-advance for an explicit-value insert — an
  install/seed script that inserts explicit ids (e.g. a fixed webmaster/
  guest user id) needs an explicit sequence resync
  (`pg_get_serial_sequence()` + `setval`) afterward, or the very next
  real default-value insert collides head-on with the seeded row.
- **The default transaction isolation level itself differs.** MySQL's
  InnoDB default is `REPEATABLE READ`; Postgres's default is `READ
  COMMITTED` — a genuinely weaker isolation level under which a second
  read within the same transaction can see rows a concurrent transaction
  committed in between (a non-repeatable read MySQL's own default
  isolation level would have prevented). Any code that relies on reading
  a consistent snapshot across multiple statements inside one transaction
  (a read-modify-write sequence, a report computed from more than one
  query against data that could change mid-transaction) needs either an
  explicit `SET TRANSACTION ISOLATION LEVEL REPEATABLE READ` on the
  Postgres leg to match MySQL's real default, or a redesign that doesn't
  depend on cross-statement snapshot consistency — don't assume "it's
  wrapped in a transaction" already guarantees the same read behavior on
  both platforms.
- **Disabling FK enforcement for a test/migration needs a genuinely
  different mechanism per platform.** MySQL's `SET FOREIGN_KEY_CHECKS=0/1`
  is an ordinary session toggle; Postgres has no equivalent an ordinary
  role can reach (`ALTER TABLE ... DISABLE TRIGGER ALL` is rejected even
  for the table's own owner as a "system trigger"; `SET
  session_replication_role` is superuser-only) — a dedicated Postgres
  test role needs real superuser privilege for this specific purpose.
  Relatedly, `TRUNCATE` on Postgres refuses to run against a table with
  live incoming FK references regardless of trigger-disabling (a
  non-trigger-based check) — use `DELETE FROM` (trigger-based, correctly
  bypassed) wherever the code needs to clear a table under a
  disabled-FK-checks test scenario.
- **`pg_connect()` caches and reuses a connection object for an identical
  connection string within one PHP process — unlike `new \mysqli(...)`,
  which always opens fresh.** After forcibly terminating a backend (e.g.
  a test harness's `DROP DATABASE ... WITH (FORCE)`), a later
  `pg_connect()` call with the same connection string can silently return
  that same, now-dead handle; every query against it then fails.
  `PGSQL_CONNECT_FORCE_NEW` is required for any code path that might
  reconnect after intentionally terminating other backends. Also: a
  Postgres connection can never `DROP`/`CREATE` the database it's
  currently attached to (connect to the `postgres` maintenance database
  first), and a plain `DROP DATABASE` fails outright if any other backend
  is still attached — `WITH (FORCE)` (Postgres 13+) terminates other
  backends first.
- **A full-text search query language (`to_tsquery()`, etc.) is a real
  grammar, not plain text.** A raw multi-word phrase fed straight in
  without tokenizing hits a genuine syntax error on an unescaped space
  (not a valid inter-term operator), and — more importantly — a
  user-supplied word containing its own query-operator characters is an
  injection risk into that grammar if passed through unsplit. Always
  tokenize/rejoin user search input into the target platform's query
  language explicitly, the same discipline as SQL injection.
  **State the relevance-ranking expectation explicitly rather than
  leaving it implicit: exact result *ranking* is accepted as divergent
  across providers, not a portability bug to chase to parity.** MySQL's
  `MATCH ... AGAINST` natural-language relevance score and Postgres's
  `ts_rank`/`ts_rank_cd` are different scoring algorithms over different
  index structures (a `FULLTEXT` index vs. a `tsvector`/GIN index) with
  no shared formula to converge on — the *set* of matched rows for an
  equivalent query must agree between providers (that's a real
  correctness bug if it doesn't), but their relative order within that
  set is not held to the same standard. Cover set-equality with a
  cross-provider test; don't write a test asserting a specific relevance
  order that only one provider's algorithm happens to produce.
- **Treat every DB driver/credential-selection environment variable
  (not just data values) as part of a test's env save/restore
  discipline.** A test that seeds/mutates the real process env for
  `PIWIGO_DB_DRIVER`/`PIWIGO_DB_PORT`-style variables without restoring
  them leaks the wrong driver into every later test in the same shared
  process — and depending on how the two protocols fail against each
  other's port, this can present as anything from silently wrong results
  to a full 60-180-second hang per affected test (one driver's client
  speaking its wire protocol against the other platform's open port often
  doesn't fail fast), making it far harder to root-cause than an ordinary
  leaked data value.

## Migration path

Clean fork, no in-place upgrade from an existing upstream Piwigo
install. Commit to Doctrine Migrations (`bin/piwigo migrations:migrate`)
as the one real mechanism for both a fresh install and any future
version-to-version upgrade of this project's own installs — decided
once in P14, not revisited mid-project (a prior attempt briefly
replaced migrations with a hand-maintained static schema file before
any real install existed, then had to reinstate them; avoid that
detour entirely this time). If the install flow invokes
`Doctrine\Migrations\Tools\Console\Command\MigrateCommand::run()`
directly rather than through a full Symfony Console `Application`,
know that this does *not* guarantee catching every failure into a clean
exit code — a driver-level exception (a real "table already exists"
collision on a re-run against an already-migrated database, for
instance) can escape `run()` uncaught, past whatever narrower exception
type the caller's own surrounding catch block expects, reaching the
client as a raw, unhandled fatal error instead of the installer's own
error UI. Wrap that specific call in its own try/catch and fold any
thrown exception into the same failure path a nonzero exit code already
triggers, rather than assuming `run()` alone is exception-safe.

**Actively watch for a legacy in-place-upgrade chain getting mechanically
reintroduced during P17–P20's domain porting, despite directly
contradicting this section's own "no in-place upgrade" decision — this
is the single largest wasted-work case found across this branch's whole
history.** A prior attempt's domain-porting phases carried over
`Admin/Install/DbPatch/` (127 files) and `VersionUpgrade/` (26 files),
plus the `UpgradeRunner`/`UpgradeService`/`UpgradeFeedRunner` orchestrators
and `public/upgrade.php`/`upgrade_feed.php` entry points, apparently
without anyone noticing they implemented exactly the schema-patching
upgrade mechanism this plan already rejects in favor of Doctrine
Migrations plus a one-way `import:legacy` data tool — 153 files' worth of
legacy porting effort, deleted outright once caught, not narrowed or
adapted. The failure mode was porting-by-default: nothing in P17–P20
told the implementer to *skip* this subtree, so a domain-by-domain
mechanical port pulled it across like everything else `admin/include/`
still contained. Treat `DbPatch/`, `VersionUpgrade/`, and their
orchestrators as explicitly out of scope from the start of domain
porting, the same way P6 already excludes free functions from its own
extraction pass — don't let "port everything under `admin/include/`"
default to including a subsystem this plan has already decided not to
need. If any single-caller/dead code turns up only once this subtree is
finally excised (a version-check redirect in bootstrap, a
per-patch-ID ledger row, a config property with no other reader), that's
expected fallout from the same mechanical-porting root cause, not a
separate bug — retire it in the same pass rather than treating each as
independent.

If shipping any static per-provider schema snapshot file
(`install/piwigo_structure-{mysql,mariadb,pgsql}.sql`-style), generate
it *from* migrations as a human-reviewable artifact, never hand-maintain
it as the install-time source of truth — the two can drift, and only
one should ever be authoritative.

**State the deployment concurrency model explicitly: `migrations:migrate`
runs exactly once per deploy, from a single deploy-time job or
init-container, never from ordinary application-container boot in a
multi-replica deployment.** Running it from every replica's own boot
sequence is a real race — two replicas starting concurrently against a
freshly-provisioned database can both observe "migration N not yet
applied" and both attempt to apply it, and Doctrine Migrations' own
advisory-lock protection (`--no-lock` aside) reduces this to a lock-wait
or a clean failure on the losing replica rather than actual corruption,
but a losing replica that doesn't specifically tolerate that failure
(retry-and-recheck, or simply not treating a migration-already-applied
error as fatal to its own boot) can crash-loop on every deploy instead.
Default: a dedicated migration step in the deploy pipeline (or a Job/
init-container in a Kubernetes-style deployment) that runs to completion
*before* any application replica starts serving traffic, with ordinary
replica boot never invoking `migrations:migrate` itself at all.

**Author cross-provider migrations as a single migration class branching
on `Connection::getDatabasePlatform()`, not separate per-provider
migration trees.** This mirrors the branching pattern the MySQL
infrastructure notes above already establish for `RAND()`/`REGEXP()` and
identifier quoting — one class, one migration version number, with the
DDL itself platform-branched inside — rather than three parallel
directories of migrations that can silently drift out of version-number
sync with each other. Add a rollback policy alongside it: every
migration implements a real `down()`, verified by CI actually invoking
it (migrate up, migrate down, migrate up again) rather than trusting an
unexercised `down()` method to work the first time it's ever needed in
production; and `bin/piwigo migrations:status`/`--dry-run` (Doctrine
Migrations' own built-in equivalents) are part of the deploy pipeline's
pre-flight check, not just a manual developer command. Seed/bootstrap
data (the webmaster/guest user rows, default config values) is inserted
by a dedicated post-migration install step, not folded into a schema
migration itself — a schema migration changes structure and is safe to
replay against every environment including ones that already have real
users; a seed insert is meaningful only once, on a genuinely fresh
install, and conflating the two risks either reseeding a live database
or skipping seed data on a fresh one depending on which path a given
migration happens to take.

**Concrete acceptance test**: CI runs `migrations:migrate` from a blank
database to head on all three provider legs (MySQL, MariaDB, Postgres),
not just the primary MySQL leg — this is the same empty-database
verification-gap lesson the CI fail-fast incident above already
surfaces for aggregate queries, applied here to the migrations
themselves.

**In scope, already decided** (see the Status table's own note and the
Greenfield-tracks section: T2 adoption tooling, the one non-cuttable
exception in that group, not an open question to revisit here). Design
`bin/piwigo import:legacy` (adopting from an existing pre-fork Piwigo
install) as a real, planned tool from the start, hosted on P12 (CLI
framework) once P15's target schema exists to migrate into — not left
referenced-but-never-built the way a prior attempt's own equivalent
tooling sat unimplemented for a long stretch. Concrete acceptance
criterion: a fixture-based import run against a real pre-fork database
dump, with record-count and metadata parity asserted against the
source, not just "the command exits 0."

## Verification

The full gate list once every phase is done — build toward this from
P0 onward, wiring each gate in as the phase that makes it meaningful
lands, rather than deferring gate-wiring to the end. See
`docs/REFERENCE.md`'s CI section for the real, current status of each
once work starts.

```bash
vendor/bin/pest                             # unit, integration, browser, arch — blocking
vendor/bin/pest --mutate --min=60           # mutation score — not run in CI yet, see note below
vendor/bin/pest --type-coverage --min=95    # type coverage — blocking
vendor/bin/ecs --no-progress-bar            # style — 0 violations, blocking
composer analyse:phpstan                    # level 10, 0 errors — blocking
vendor/bin/rector --dry-run                 # non-blocking
vendor/bin/deptrac --no-progress            # 0 violations — blocking
vendor/bin/composer-require-checker check   # blocking — catches a used-but-undeclared dependency
vendor/bin/composer-unused                  # non-blocking — real false-positive rate on dynamically-loaded classes
vendor/bin/phpbench run --report=aggregate  # non-blocking — publishes benchmark deltas, no asserted threshold
just typecheck && just lint-js && just lint-css   # blocking
just build                                  # blocking
bun run test:unit -- --coverage             # blocking — coverage collected, no --min threshold; see note below
bunx size-limit                             # blocking — bundle-size budget
bunx knip                                   # non-blocking — same false-positive-rate reasoning as composer-unused
bunx lhci autorun                           # collects + uploads scores incl. a11y; no `assert` block until P55 — collection alone is not the "a11y gate" the note below means; non-blocking until P55
actionlint .github/workflows/*.yml          # blocking
bunx commitlint --from origin/16.x --to HEAD      # blocking
k6 run tests/Load/*.js                      # non-blocking, tests/Load/ doesn't exist yet
```

`composer analyse:phpstan` chains `bin/piwigo phpstan-latte:compile`
ahead of PHPStan; a bare `vendor/bin/phpstan analyse` skips template
checking entirely.

**No bare line-coverage percentage gate on the PHP side is a deliberate
omission, not an oversight to fill in with an invented threshold — state
it here so it doesn't read as a gap.** P2's own guidance above already
settles this: mutation score, not raw line-coverage percentage, is
"the real completion gate for any 'this class is tested' claim." A
`vendor/bin/pest --coverage --min=N` line would measure something this
project has already decided isn't the meaningful signal (line coverage
that isn't assertion coverage is exactly the gap mutation testing exists
to catch). The real asymmetry worth fixing isn't the missing raw-percentage
line — it's that the mutation-score line immediately above it is still
marked "not run in CI yet": wiring `--mutate --min=60` into CI as a real
blocking gate, not just a documented-but-dormant command, is the actual
follow-up this note calls for. The JS-side `bun run test:unit --
--coverage` line has the same shape (collected, unenforced) and the
same reasoning applies there for consistency.

**State how that eventual full-suite `--mutate --min=60` gate stays fast
enough to actually run per-PR, not just that it should eventually be
blocking — a full mutation run against a database-heavy codebase this
size is a real hours-scale cost, not a hypothetical one.** Two-tier,
matching how every other slow-but-valuable check in this list is already
handled: per-PR, scope `--mutate` to only the files the PR actually
changed (`pest --mutate` accepts a path/filter argument — diff the PR
against its base branch, feed the changed-file list in, so review-time
feedback stays proportional to the change's own size); the full,
unscoped `--min=60` run happens on a schedule (nightly against `main`)
where an hours-long run is a non-issue, with a failure there opening a
tracked issue rather than blocking any single PR after the fact. Land
this two-tier split in the same commit that flips `--mutate` from
"not run in CI yet" to blocking — a full-suite-only gate would either
get disabled the first time it makes CI too slow to be usable, or get
left un-enforced indefinitely the same way it is today.

**State the job-grouping/parallelism model for this list explicitly, and
apply the CI fail-fast-ordering lesson from the MySQL infrastructure
notes above to this exact list, not just to the database-specific
job.** Group by real dependency, not by shared topic: the fast PHP/JS
static-analysis tier (ECS, PHPStan, deptrac, typecheck/lint-js/lint-css)
runs as its own job so a style violation doesn't block the test tiers
from reporting; `just build` gates anything that consumes its output
(`bunx size-limit`, `bunx lhci autorun`) as a real dependency, not a
topic-grouping; and every other line — the dependency-hygiene checks,
`actionlint`, `bunx commitlint`, `k6`, `phpbench` — runs as its own
independent job or with `continue-on-error: true` so one failing,
unrelated check never silently hides whether a later one even ran, the
exact failure mode the MySQL-notes incident documents above.

**Deliberately not in this list**: Psalm — see P0's own note on skipping
it entirely this time. `composer lint:latte`/`precompile:templates` earn
their place here once P32 wires their CI/pre-commit enforcement (P45
only re-finalizes it after P43's filter-set changes, per P45's own
note) — don't let either sit built-but-ungated for a whole epoch the
way a prior attempt did.

**Open question resolved: build it, but not as a separate hand-maintained
manifest — that's specifically what killed the prior version.**
Traced the actual prior mechanism (`docs/plan/manifest.yaml` +
`tools/plan-lint.php`, introduced at P0) to its real deletion commit
rather than guessing at why it died: it wasn't abandoned because
traceability didn't matter — it was deleted because the manifest's own
hand-set `status:` field had drifted from reality (P24 still read
`"planned"` after 271 real commits had landed against it), a classic
dual-source-of-truth decay the lint script couldn't catch because it
only checked the manifest's own structure, never cross-verified it
against real code or test artifacts. Building the same shape again would
just drift the same way. Decision: build SEC-NN traceability using this
document's own already-working `doc-drift-check` marker mechanism
instead of a parallel hand-maintained file — a lint check that greps
real test files for a `SEC-NN` reference (existence/coverage, a
mechanical fact) rather than trusting a hand-set status flag that has no
forcing function to stay current. **Own this on P3** (CI pipeline): it's
the phase that already wires every other lint/scan job this list runs
(actionlint, secret-scanning, SBOM/OSV), so the SEC-NN-lint script
belongs alongside them, enforced from the start rather than left unowned
the way the prior attempt's version eventually was.

## Security master checklist

76 items, `SEC-01`–`SEC-76`, each globally unique. Each row names the phase
that owns it; treat "phase done ⇒ item done" as a reasonable default once
work resumes, not a guarantee — verify each item directly against code
rather than assuming a phase's completion covers everything listed under
it. A few items carry a "see below" pointer to fuller implementation
detail from a prior attempt's investigation, kept because it's still the
right shape for the fix, not because it's already built.

The table below has no separate "verified_by" column — deliberately, not
an oversight: at this stage no item has a real test yet, so a column
filled entirely with placeholders would only manufacture the appearance
of traceability the checklist's own opening warning cautions against.
The real acceptance criterion for each item is its owning phase's own
"Verification for this phase" section (see Epoch H/P28's, as one
example already built out) — once a phase lands, replace that phase's
items' Status cells with the actual test name/file that exercises them,
rather than retrofitting a bare column of TBDs now.

| ID | Phase | Item | Status |
| --- | --- | --- | --- |
| SEC-01 | P4 | `.htaccess`/Caddy deny rules for sensitive directories | Not started — enumerate the deny-list explicitly rather than leaving "sensitive directories" open-ended: `.git/`, `.env`/`.env.*`, `docker/`, `tests/`, any `*.sql`/`*.sql.gz` dump, and dotfiles generally — defense-in-depth for exactly the docroot-misconfiguration scenario this phase's own incident already describes, since a correct `public/` docroot alone should already make these unreachable |
| SEC-02 | P0 | CLI guards on all `tools/*.php` scripts | Not started — apply to every real entry-point script, not just the obvious ones, see below |
| SEC-03 | P2 | No fixture SQL with secrets in web root | Not started |
| SEC-04 | P4 | Ship `robots.txt` | Not started |
| SEC-05 | P4 | Brotli compression | Not started |
| SEC-06 | P4 | `Cache-Control: immutable` for hashed assets | Not started |
| SEC-07 | P5 | Replace `mt_rand()` with `random_int()`/`random_bytes()` | Not started — security-sensitive uses only; `mt_rand()` stays fine for cache-busting/sampling, see below |
| SEC-08 | P5/P17–P23 | Use `===`/`!==` everywhere, never loose `==`/`!=` | Not started |
| SEC-09 | P5 | `#[\SensitiveParameter]` on secret-carrying params | Not started — audit every credential-bearing parameter up front, not just the first ones found, see below |
| SEC-10 | P9→P17–P23 | Never blanket-sanitize superglobals with `addslashes()` | Not started — a prior attempt found this genuinely corrupting data (`O'Brien` stored as `O\'Brien`), masked for a long time by dozens of compensating `stripslashes()` calls elsewhere. Use bound parameters at the SQL boundary instead of sanitizing at the input boundary |
| SEC-11 | P9 | Build CSRF tokens as sha256 HMAC from the start, never md5 | Not started |
| SEC-12 | P9 | Verify every CSRF/token comparison with `hash_equals()`, never `!==` | Not started — specifically check for a second, independent copy of the same check in a different layer (e.g. an API/WS-style layer's own copy) — a prior attempt fixed this in the main auth services but missed an independent duplicate elsewhere for a while |
| SEC-13 | P9 | `CookieService` HttpOnly + Secure flags | Not started — SameSite and Path/Domain scoping too, see below |
| SEC-14 | P9 | Cookie deletion calls include all flags | Not started |
| SEC-15 | P20 | Eliminate `eval()` wherever a typed/native alternative exists | Not started — track the one plugin-facing exception under SEC-49 |
| SEC-16 | P19 | Apply `escapeshellarg()` to every `exec()` argument, as a hard rule not a case-by-case judgment | Not started — a prior attempt found roughly a quarter of real shell-out sites escaping nothing, some reachable via DB-settable config (admin-to-shell); also explicitly redirect stdin (e.g. to `/dev/null`) on every such `exec()` call — several real CLI tools (ImageMagick, ffmpeg) silently fall back to reading from stdin when a required argument like a filename is empty/missing, turning an intended fail-fast error path into an indefinite hang if the calling process's own stdin is left open, which can happen under some execution harnesses even when it wouldn't under a normal CLI run |
| SEC-17 | P17 | URL validation in redirect responder | Not started |
| SEC-18 | P19 | Bound-parameter search query composition, no `addslashes()` | Not started |
| SEC-19 | P21–P22 | Controllers use PSR-7 request, not superglobals | Not started |
| SEC-20 | P19 | XXE protection on SVG/XML parsing | Not started — covers both the upload-time SVG sanitizer and the separate XMP-metadata parse path (`MetadataService`) — the two are distinct call sites easy to configure inconsistently, verify `LIBXML_NONET`/no-external-entity settings independently on each |
| SEC-21 | P19 | SVG stored XSS sanitization on upload | Not started |
| SEC-22 | P21 | Replace `phpinfo()` with curated server info | Not started |
| SEC-23 | P17 | SSRF hardening for the HTTP client | Not started |
| SEC-24 | P17 | No local-file read fallback in the HTTP client | Not started |
| SEC-25 | P18 | Session fixation: regenerate on privilege escalation | Not started — verified in P17/P18 themselves (P17's dedicated Tier-1 Unit coverage, P18's login-flow regression test), not deferred to P28: P28's own scope (WebAuthn, OIDC, CSP, rate limiting) has no natural session-fixation surface of its own to add |
| SEC-26 | P16 | Validate locale before `include` in `LangService` | Not started |
| SEC-27 | P18 | Auto-login key HMAC sha256 + `hash_equals()` from the start, never sha1 | Not started |
| SEC-28 | P18 | `EphemeralKeyService` HMAC sha256 + `hash_equals()` from the start, never md5 | Not started |
| SEC-29 | P17 | Host header poisoning defense | Not started |
| SEC-30 | P17–P22 | Exception messages don't expose internals | Not started |
| SEC-31 | P18 | Account enumeration via registration, login, and password reset | Not started — identical response regardless of whether the submitted address/username exists, across all three flows, not just registration; see P28's password-reset bullet |
| SEC-32 | P20 | ZIP bomb protection | Not started — same decompression-bomb family as SEC-67's image pixel-flood guard, distinct decode path |
| SEC-33 | P19 | Derivative serving must not leak file existence | Not started — design the permission check as a real fast path, not something that only runs correctly through the full rendering pipeline |
| SEC-34 | P22 | Install sentinel DB-flag secondary check | Not started |
| SEC-35 | P19 | No non-standard headers from the derivative pipeline | Not started |
| SEC-36 | P27 | REST error responses never leak internals | Not started — one app-wide exception-handling middleware catching every uncaught `Throwable`, logging + reporting it, returning a bare 500 with no message/trace, is the validated shape (see Epoch G) |
| SEC-37 | P27 | No object dumps in the REST error path | Not started — log only the exception class name and message, never a full object dump, and never return either to the client |
| SEC-38 | P27 | REST route authorization middleware | Not started — a dedicated guard distinguishing 401 vs 403, explicitly injected per route that needs it, decided at design time not audited in afterward (see Epoch G) |
| SEC-39 | P27 | Validate `Content-Type: application/json` on REST bodies | Not started — one choke point every JSON-body-consuming controller goes through, rejecting a non-empty body of the wrong media type with 415 (see Epoch G) |
| SEC-40 | P17-P20/P22 | Request DTOs as a hard input-validation gate, enforced by an arch test from day one | Not started — see Epoch F's guidance on routing every superglobal read through a typed DTO from the start |
| SEC-41 | P28 | Password hashing via Argon2id | Not started |
| SEC-42 | P28 | No blanket CSRF exemption for any URL prefix, including admin routes | Not started |
| SEC-43 | P28 | No `Access-Control-Allow-Origin: *` if the OpenAPI spec is ever served over HTTP | Not started — moot as long as the spec stays a lint-gated repo artifact with no serving route; revisit only if that changes |
| SEC-44 | P28 | API rate limiting + rate-limit headers | Not started — build the `rate_limiter` cache pool here, deliberately not earlier (see P11) |
| SEC-45 | P28 | CSP violation reporting | Not started |
| SEC-46 | P28 | Cross-Origin Isolation (COOP/COEP) | Not started |
| SEC-47 | P28 | `Vary: Cookie` on permission-dependent responses | Not started |
| SEC-48 | P28 | Default `allow_html_descriptions` (or equivalent) to `false` | Not started |
| SEC-49 | P29 | No `eval()`-based plugin-visibility mechanism — use a typed event instead (plugin-facing half of SEC-15) | Not started |
| SEC-50 | P3 | CycloneDX SBOM generated as a CI artifact | Not started |
| SEC-51 | P3 | Pin GitHub Actions to commit SHAs | Not started |
| SEC-52 | P3 | OSV-Scanner over lockfiles in CI | Not started |
| SEC-53 | P3 | SLSA build provenance + attestations | Not started |
| SEC-54 | P4 | Sign container images + release artifacts | Not started |
| SEC-55 | P28 | OIDC SSO: PKCE + state/nonce + ID-token validation | Not started |
| SEC-56 | P18 | GDPR data-subject endpoints behind re-auth + rate limit | Not started — backend in P18/domain-tier scope, REST exposure in Epoch G |
| SEC-57 | P15 | Append-only / tamper-evident audit log | Not started |
| SEC-58 | P11 | Feature-flag changes authz-gated + audited | Not started — moot as long as feature flags stay read-only by design; revisit only if a mutation path is ever added |
| SEC-59 | T3·AI | MCP server: scoped read-only tokens | Not started (cuttable) |
| SEC-60 | P23 | Worker-mode request isolation | Not started — a unified PSR-15 bootstrap pipeline covering every real entry point (see P9's guidance) is a real prerequisite; P7 builds readiness, P23 is where worker mode actually flips on (resolved, see P7's own note). A synchronous `exec()` call (upload representative generation) blocking a worker for a subprocess's full duration is a related but distinct availability concern — see T3·UPLOAD-ASYNC |
| SEC-61 | P11 | Mercure topic authorization | Not started (T3 rider) |
| SEC-62 | P28 | Trusted Types | Not started |
| SEC-63 | P28 | Fetch Metadata isolation | Not started |
| SEC-64 | P3 | OpenSSF Scorecard | Not started |
| SEC-65 | P27 | API `Idempotency-Key` replay store | Not started — opt-in via the header, scoped to mutating methods excluding tus (its own resumability protocol already covers retries); a replay cache, not cross-process locking, is the validated scope (see Epoch G) |
| SEC-66 | P3 | Dedicated static-analysis-to-code-scanning workflow | Not started — build one whose actual job is static analysis (uploading SARIF via a code-scanning action), not just relying on Scorecard's own SARIF upload as an incidental side effect. Deliberately no tool named here — browse what's current/maintained at implementation time rather than locking in a choice now |
| SEC-67 | P19 | Pixel-area/decompression-bomb cap on image decode | Not started — check declared width×height before, not after, a full `getimagesize()`/GD/Imagick decode; same decompression-bomb family as SEC-32 |
| SEC-68 | P19 | Hardcoded, non-config-overridable deny-list on upload file extensions | Not started — a direct RCE path if left purely to admin-configurable allow-list config, since `/upload/` is web-served |
| SEC-69 | P19 | Pin the application's own ImageMagick `policy.xml` in its container image | Not started — never inherit whatever URL/coder-delegate policy the base deploy image happens to ship; see P4 |
| SEC-70 | P19 | Re-encode accepted raster images before storage | Not started — a real decode→re-encode pass on JPEG/PNG (not just resize) strips most embedded-polyglot/EXIF-exploit payloads, at a real, accepted metadata/quality cost |
| SEC-71 | P4 | Baseline security headers on every response, `/upload/*` especially | Not started — `X-Content-Type-Options: nosniff` (closes GIFAR-style polyglot MIME-sniffing on served uploads), `X-Frame-Options`/`frame-ancestors`, `Referrer-Policy`, `Permissions-Policy`, HSTS; see SEC-01's deny rules for the same `/upload/` surface |
| SEC-72 | P19 | Pin a specific, tracked ffmpeg version for video representative generation | Not started — ffmpeg's demuxer surface carries its own independent CVE history from the image-decoder classes already covered, including historical local-file-read primitives via certain playlist/concat-style inputs |
| SEC-73 | P19 | Malware/AV scanning of uploaded bytes | Not started — optional, deployment-dependent (expensive to run universally, genuinely valuable for a multi-tenant/public-upload deployment); decide explicitly rather than silently never deciding either way, see T3·UPLOAD-SANDBOX |
| SEC-74 | P28 | DB-level account lockout, given its own numbered item (was named only in the threat model, unnumbered) | Not started — real parameters (10 attempts/15-minute window, 15-minute lockout, per-account not per-IP) already specified in Epoch H's own brute-force bullet; cross-referenced from SEC-31 |
| SEC-75 | P3 | Secret-scanning in CI (gitleaks) | Not started — `gitleaks detect` over the full git history plus working tree, gated in CI the same way OSV-Scanner (SEC-52) and Scorecard (SEC-64) already are; a leaked credential in a squashed/rebased-away commit still lives in reflog/history until scanned, so this must run against history, not only the diff |
| SEC-76 | P13 | `#[Sensitive]` config-property redaction enforced at boot + error handler | Not started — the property-level mirror of SEC-09's parameter-level redaction; SEC-09 covers passwords/secrets passed as raw method parameters, this covers the same values once wrapped in a config object, where `#[\SensitiveParameter]` does not apply |

### Notes on selected items

**SEC-02.** Give every real entry-point script a `PHP_SAPI !== 'cli'` (or
equivalent) guard as it's written, not as a later audit pass — it's easy
to add the guard to the obvious scripts and miss ones tucked under a
subtool directory (a prior attempt missed exactly this for two
directly-invokable `tools/i18n/*.php` scripts, each with its own "Usage:"
docblock proving it was meant to run standalone). Whether `tools/` ends up
reachable from `public/` or not, treat every script under it as if it
were, for guarding purposes.

**SEC-07.** `mt_rand()` is fine for genuinely non-security uses — temp
filename uniqueness, cache-busting query params, probabilistic
log-sampling gates, or picking a *length* parameter for a value whose
entropy comes from elsewhere (e.g. `generateKey(mt_rand(15, 20))`, where
`generateKey`'s own `random_bytes()` call is the entropy source, not the
length pick). Anything that *is* the entropy source for a token,
password, or key must use `random_int()`/`random_bytes()` instead — judge
each call site by whether `mt_rand`'s output value itself ends up in a
security-relevant secret, not by which function/file it happens to sit
in.

**SEC-09.** Mark every password/secret-carrying *parameter* with
`#[\SensitiveParameter]` as it's written: raw password parameters on
auth/login methods, DB credential parameters, and promoted properties on
any Request DTO that carries a password. Know the attribute's real limit
going in: it redacts scalar/array *parameters*, never object properties —
if a password ever gets wrapped in an event or DTO object instead of
passed as a raw parameter, that carrier object is not redacted by the
attribute and must never be dumped/logged directly (document the
limitation on the carrier's own docblock so it isn't rediscovered the
hard way).

**SEC-13.** `Lax` by default for ordinary session cookies (blocks the
classic cross-site GET-triggered CSRF case while still surviving a normal
top-level cross-site navigation, e.g. following a link into the app from
elsewhere); `Strict` for the auto-login cookie specifically (SEC-27) —
it's long-lived and re-authenticates silently with no user interaction to
notice, so there's no legitimate cross-site-navigation case where
`Lax`'s carve-out is worth the exposure. Scope `Path` to the narrowest
real prefix each cookie needs (the auto-login cookie to its own
verification route, not site-wide) and never set `Domain` at all unless
subdomain sharing is a genuine, deliberate requirement — an explicit
`Domain` attribute widens a cookie's reach to every subdomain, which is
never the default a security-sensitive cookie should opt into silently.

**SEC-17.** Never validate a redirect target as "does it look like a
relative path" — a leading `//host` is scheme-relative and browsers
resolve it as a full absolute URL exactly like `https://host`, so it must
be checked against the same allow-list as any other absolute target, not
waved through the relative-path branch unchecked. Default design: an
explicit allow-list of same-origin relative paths (or, where a genuine
external redirect target is a real feature — e.g. an OIDC `post_logout_redirect_uri`
— a configured host allow-list), never a generic "starts with `/`"
regex, which the `//host` case already defeats.

**SEC-23/SEC-24.** The PSR-18 HTTP client used for any outbound
platform-initiated fetch (remote plugin/theme metadata, URL-based image
import, webhook delivery) resolves the target hostname and rejects any
result landing in a private/loopback/link-local/cloud-metadata range
(RFC 1918, `127.0.0.0/8`, `169.254.0.0/16` — including
`169.254.169.254` — `::1`, `fc00::/7`), checked against the resolved IP,
never the literal hostname string. Close the DNS-rebinding gap this
class of guard most commonly misses by resolving immediately before the
actual connection and pinning that same IP for the TCP handshake itself,
rather than trusting a resolution done earlier at validation time — a
resolve-then-reconnect gap is exactly what lets an attacker's DNS answer
differently between the check and the fetch. Re-run the same range check
on every redirect hop, not just the initial request — a same-origin-
looking URL that 302s to an internal address is the standard SSRF-via-
redirect bypass. The client's allowed scheme set is `http`/`https` only,
enforced by construction, never by relying on the caller to have already
filtered a `file://` (or other) scheme out.

**SEC-29.** Every absolute-URL generation the app does itself (password-
reset links, OIDC redirect URIs, any CORS origin echo) uses a
`.env`-configured canonical base URL/host, never `$_SERVER['HTTP_HOST']`
or `X-Forwarded-Host` read directly — the same "trusted config, never a
client-supplied header" discipline SEC-44's `TRUSTED_PROXIES` list
applies to client IP. A genuine multi-hostname deployment validates the
incoming Host header against an explicit configured allow-list rather
than trusting whatever value arrives.

**SEC-34.** The primary check is the install-sentinel file P4/P22 already
use to gate re-running the installer, removed after a successful install;
this item is the independent second check — read the real DB state (a
settings row, or a non-zero `users` row count) rather than trusting the
filesystem sentinel alone. It defends against an attacker who can already
write into the app's own directory through some unrelated vulnerability
(a separate upload/RCE bug) recreating the deleted sentinel file to
re-trigger the install flow and seize the instance; it does not by itself
defend against a compromised DB, which is outside this checklist's threat
model the same way it is everywhere else in it.

**SEC-41.** Resolve the migration-sequencing question directly: P5's
`password_hash()` replacement for phpass already targets
`PASSWORD_ARGON2ID` from that phase's own commit, using the OWASP cost
parameters this item specifies — not `PASSWORD_DEFAULT` (bcrypt) as an
interim step. This keeps the migration a single hop (phpass → Argon2id,
transparently on next successful login, per P18's Auth-domain
acceptance test) rather than two (phpass → bcrypt in P5, then a second
bcrypt → Argon2id pass in P28 for users who logged in during the gap).

**SEC-51/SEC-53/SEC-54.** Pin every third-party GitHub Action reference
to a full 40-character commit SHA, never a mutable tag — Dependabot's
`github-actions` ecosystem keeps pins current via PRs bumping the SHA
comment, without giving up the pinning itself. Target SLSA Build Track
Level 3 (hosted, non-user-controlled build platform with provenance
generation isolated from the build steps), via
`slsa-framework/slsa-github-generator`, scoped to the container image and
any downloadable release archive, verified at deploy time before an
image is pulled into a running environment. Sign both with `cosign`
keyless signing through GitHub's OIDC-issued Fulcio certificate — no
long-lived signing key to manage or rotate — verified by the same
deploy-time policy check that verifies the SLSA attestation, one
verification step covering both, not two separate ones.

### Threat model

A different cross-section of the same 76 items, organized by attacker
persona/goal rather than by owning phase. Every attacker goal maps to at
least one `SEC-NN` above; the table below is the actual persona-to-
mitigation mapping this section previously only asserted was
"derivable" without showing it. It's a compact index into the checklist,
not a replacement for it — each cell names the load-bearing `SEC-NN`
items for that goal, not every item that touches it at all.

| Attacker persona | Primary goal/asset | Key mitigations |
| --- | --- | --- |
| Anonymous internet attacker | Account takeover (credential stuffing, session/cookie theft, CSRF) | SEC-07, SEC-11–14, SEC-25, SEC-27–28, SEC-31, SEC-41, SEC-74 |
| Anonymous internet attacker | Server-side compromise via untrusted upload | SEC-20–21, SEC-32, SEC-67–70, SEC-72–73 |
| Anonymous internet attacker | Pivot into internal network / cloud metadata via the app as a proxy | SEC-17, SEC-23–24, SEC-29 |
| Authenticated non-admin user | Privilege escalation, cross-tenant data access, IDOR | SEC-30, SEC-33, SEC-38, SEC-56 |
| Authenticated non-admin user | Stored/reflected XSS against other users or admins | SEC-15, SEC-21, SEC-26, SEC-45, SEC-62–63 |
| Malicious or compromised plugin/theme author | Abuse of the extension SDK to reach core data or the shell | SEC-49, P29's `ExtensionContext`/no-raw-DB-access design (Epoch I) |
| Insider with CI/CD or registry access | Supply-chain compromise of the published artifact | SEC-50–54, SEC-64, SEC-66, SEC-75 |
| Automated scanner/bot | Credential brute force, ZIP/pixel decompression bombs, enumeration | SEC-31, SEC-32, SEC-44, SEC-67, SEC-74 |

Two items (SEC-05 Brotli, SEC-06 `Cache-Control: immutable`) are
performance items, not mitigations, and intentionally appear in no
threat row or table cell above. Mitigations that are not numbered items
at all — nonce-based CSP, dual passwords — belong to P28 the same as
their numbered siblings.

### Secrets & key management

DB credentials and the application `secret_key` live in `.env`, never
web-served. Every other real credential this application handles — SMTP
credentials, the OIDC client secret (SEC-55), and any object-storage
credentials if/when S3-compatible storage backs uploads — follows the
same `.env`-only discipline as DB credentials and `secret_key`; no
separate secrets mechanism exists or is planned for any of them.

**`.env` at rest, decided explicitly rather than left as an
unstated assumption**: file permissions `0600`, owned by the
container's own runtime user, never web-served (SEC-01's deny rules
cover the web-serving half already) — and never baked into a built
container image layer. P4 builds the image and SEC-54 signs it for
distribution, which makes a leaked image *more* likely to eventually be
inspected by a downstream verifier pulling and checking its signature,
not less — so real secrets belong in the orchestrator's own runtime
injection (a Kubernetes `Secret` mounted as a file, or environment
injection at deploy time), never baked into an `ENV`/`COPY .env` layer
that ships with the image itself.

**Single-root-key blast radius, resolved as a concrete decision rather
than stated as an unquestioned fact**: a single `secret_key` currently
derives the HMACs for CSRF tokens (SEC-11/12), the auto-login cookie
(SEC-27), and ephemeral keys (SEC-28), so rotating it invalidates all
three at once, forcing a repo-wide re-login (including SEC-11's
open-form 403 case). **Default: split into purpose-specific HKDF-derived
subkeys instead** — `hash_hkdf('sha256', $secretKey, 32, 'csrf')`,
`'auto-login'`, `'ephemeral-key'` — one call per purpose at each of
P9/P18's three construction sites, deriving three independent 32-byte
subkeys from the one root `secret_key` still stored in `.env`. This
doesn't eliminate the root key's own blast radius on a *full*
`secret_key` rotation, but it does let a targeted rotation invalidate
just one purpose (e.g. a suspected auto-login-cookie leak) without
forcing every logged-in user to re-authenticate. Small implementation
cost for a real reduction in rotation blast radius — worth adopting as
the default, but revisit if a real operational reason to keep the
simpler single-key scheme ever turns up. Rotation still breaks any
already-open form regardless of the HKDF split, since its embedded CSRF
token was minted under the pre-rotation subkey — the CSRF-check
middleware should distinguish that case from an ordinary forged/expired
token (a decodable-but-wrong-HMAC token, vs. a garbled one) and respond
with a friendly "please retry" page that resubmits the form after a
fresh page load, rather than a bare 403, since this is an expected
consequence of an operator-initiated rotation, not an attack. See
`docs/REFERENCE.md`'s Secret rotation section.

DB password rotation via MySQL dual passwords
(`ALTER USER … RETAIN CURRENT PASSWORD`) is P28 scope, not built. Today's
path is the simpler "update env, roll deployment" sequence
`docs/REFERENCE.md` documents.
