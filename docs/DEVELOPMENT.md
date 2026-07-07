# Development

`17.x-rewrite` replays `16.x-rewrite`'s modernization in phases (`docs/PLAN-REPLAY.md`).
This doc covers what's set up so far; it grows with each phase.

## Requirements

- PHP 8.5 (`ext-calendar`, `ext-ctype`, `ext-curl`, `ext-dom`, `ext-fileinfo`,
  `ext-filter`, `ext-gd`, `ext-iconv`, `ext-intl`, `ext-libxml`, `ext-mbstring`,
  `ext-mysqli`, `ext-openssl`, `ext-session`, `ext-simplexml`, `ext-sockets`,
  `ext-zlib`; `pcov` for coverage)
- Composer 2.x
- Node 24, bun, [`just`](https://github.com/casey/just)
- MySQL (or MariaDB/PostgreSQL — see the provider matrix) + a webserver serving this
  checkout, for anything beyond `composer test` (Integration/Contract/Browser)

## Setup

```
composer install
bun install
node_modules/.bin/playwright install chromium
cp .env.example .env.test   # fill in the .env.test block; see docs/DEVELOPMENT.md#tests
```

`just` runs recipes across both stacks (`just --list` to see them all) — thin wrappers
around the `composer`/`bun` commands below.

## Running the tools

| Command                    | What it does                                                                  |
| -------------------------- | ----------------------------------------------------------------------------- |
| `composer test`            | Pest `Unit`+`Arch` (fast, no DB/webserver — see Tests below for the rest)     |
| `composer analyse:phpstan` | PHPStan against `phpstan-baseline.neon`                                       |
| `composer analyse:psalm`   | Psalm against `psalm-baseline.xml`                                            |
| `composer analyse`         | Both of the above                                                             |
| `composer lint:php`        | ECS in check mode (**not** `--fix` yet — see below)                           |
| `composer require-checker` | Composer-require-checker against `composer-require-checker.json`              |
| `composer unused`          | Composer-unused against `composer-unused.php`                                 |
| `composer bench`           | PHPBench (`tests/Bench/` is empty until P12)                                  |
| `composer plan-lint`       | Validates `docs/plan/manifest.yaml` (tier/depends_on presence, acyclic graph) |
| `composer test:coverage`   | Pest `Unit`+`Arch` with pcov coverage (`--min=0.1`, see CI section below)     |
| `composer sbom`            | CycloneDX SBOM for Composer deps (`sbom-composer.cdx.json`, gitignored)       |
| `bun run build`            | Vite build (`build/noop.ts` placeholder entry until P24)                      |
| `bun run dev`              | Vite dev server                                                               |
| `bun run test`             | Vitest (TS unit tests — Pest can't execute these)                             |
| `bun run lint:js`          | ESLint against `eslint-suppressions.json`                                     |
| `bun run lint:css`         | Stylelint                                                                     |
| `bun run format`           | Prettier check (**not** `--write` yet — separate from ESLint, see below)      |
| `bun run knip`             | Unused files/exports/dependencies in the JS/TS tree                           |
| `bun run size-limit`       | Bundle size budget (placeholder until P25)                                    |

## Baselines and ratchets

Every static-analysis tool records a baseline of the legacy codebase's existing
issues (`phpstan-baseline.neon`, `psalm-baseline.xml`, `composer-require-checker.json`'s
`symbol-whitelist`). CI enforces that these can only shrink, never grow — a new commit
may not introduce a new, unbaselined issue.

`vendor/bin/ecs check` (no `--fix`) reports **572** legacy style violations as of P2 (571
at P0; +1 from the small env-loading edits to `common.inc.php`/`install.php`/`i.php`) —
recorded, not yet gated. The whole-codebase ECS reformat is deferred to P5 step 11, now
that the P2 regression harness this note used to be waiting on actually exists (see
`docs/PLAN-REPLAY.md`'s "additive-only foundation" rule).

`vendor/bin/rector process --dry-run` (`rector.php`, `php85: true` set) reports **321
files** would change as of P0 — almost entirely `LongArrayToShortArrayRector`
(`array(...)` → `[...]`) on legacy code. No rule is applied to the tree until P5, which
designs the real rule-set strategy (this P0 config is provisional, just enough to record
a baseline count).

`vendor/bin/deptrac analyse` has no `deptrac.yaml` yet — the layer model is designed in
P6, once PSR-4 namespaces exist to enforce layers over.

`bun run lint:js` scans 37 authored JS files (`admin/themes/default/js/*.js`,
`themes/default/js/{mcs,rating,scripts,switchbox,thumbnails.loader}.js`,
`themes/standard_pages/js/*.js`) — everything else under `themes/default/js/` (jQuery
core, jQuery UI, the `plugins/` bundle) and `admin/themes/default/js/jquery.geoip.js` /
`tools/ws/**` is bundled third-party or dev-only, excluded in `eslint.config.ts`.
`eslint-suppressions.json` (ESLint's native `--suppress-all` bulk-suppression file)
baselines **2585** legacy errors (mostly `eqeqeq`, `no-undef` on template-injected
globals); **213** warnings (`no-console`, `no-unused-vars`) are left visible and
non-blocking rather than suppressed — deliberately chosen `warn` severity, not baseline
noise. Prettier is intentionally **not** wired into ESLint (no
`eslint-plugin-prettier`) — same reason ECS and PHPStan stayed separate tools in P0;
`bun run format` checks formatting on its own.

`bun run lint:css` has nothing to check yet — `.stylelintrc.json` excludes all of
`themes/`, `admin/themes/`, `template-extension/`, `tools/ws/` (legacy or vendored),
and there's no new first-party CSS in P1. Exit 0, 0 files scanned; the config exists so
P30 has something to progressively loosen rather than author from scratch.

`bun run format` (Prettier) covers `.ts`/`.json`/`.md`/`.css`, `.prettierignore`
excluding the same legacy/vendored paths plus a handful of pre-existing top-level docs
(`README.md`, `SECURITY.md`, `docs/CONTRIBUTING.md`, `docs/PLAN-REPLAY.md`) not authored
in this phase. Clean as of P1.

`bun run knip` needed explicit `entry`/`project` globs (`knip.json`) — its default
bundler-entry-graph heuristics don't fit a script-tag legacy app with no import graph
yet (reported the entire vendored jQuery/jQuery-UI/plugins surface as "unused" without
this). Scoped to the same 37 authored files as ESLint. `commitlint`'s devDependencies
are auto-detected via knip's commitlint plugin once `commitlint.config.ts` exists;
`@lhci/cli` has no such plugin and needed an explicit `ignoreDependencies` entry
alongside `web-vitals` (both installed ahead of their wiring, deliberately). Clean
(exit 0) as of P1.

**Commit hooks (lefthook + commitlint).** `piwigo16`/`piwigo16-rewrite`/`piwigo17-rewrite`
are three worktrees of one repo, and `core.hooksPath` is set in the _shared_ git config
(not overridable per-worktree — `extensions.worktreeConfig` isn't enabled) to
`piwigo16`'s `.git/hooks`, which already has generic lefthook-shim scripts installed.
`bun add -d lefthook` puts the actual binary in _this_ worktree's own `node_modules/`,
which those shared shims already know how to find — no changes needed to `.git/hooks/`
or the other worktrees. `commitlint`'s `scope-enum` is wired as lefthook's own
`commit-msg` hook (`bunx commitlint --edit {1}`) rather than a standalone
`.githooks/commit-msg` script, since a project-local `.githooks/` dir would never fire
given the shared `hooksPath`. Verified against real `git commit` invocations (not just
`lefthook run`): a non-conventional message is rejected, a `chore(p1): ...`-shaped one
succeeds. `lefthook run` prints a cosmetic "Custom hooks paths are not supported by
default" warning every time — harmless (hooks still execute correctly through the
existing shims); _not_ fixed by `lefthook install --force`, which would rewrite the
shared shim scripts affecting all three worktrees.

**release-please + Renovate** (`.release-please-manifest.json`, `release-please-config.json`,
`renovate.json`) are config-only in P1 — both run as GitHub Actions / a GitHub App,
neither of which exists until P3 wires up CI.

**Lighthouse CI** (`lighthouserc.json`) was verified for real, not just installed: started
a local `php -S` server, `bunx lhci autorun` against `/index.php` (redirects to the
install page — no DB configured in dev). Baseline recorded:
performance 92, accessibility 70, best-practices 93, seo 82. Not wired into automated CI
until P3; reports write to `.lighthouseci/` (gitignored) with `upload.target:
"filesystem"` — deliberately not `temporary-public-storage`, which would publish the
report externally.

`web-vitals` is installed (`package.json` `dependencies`) but not wired — no
`/analytics/vitals` endpoint or real Vite entry exists yet to report from. Installing the
package is the entirety of this phase's scope for it.

## Tests

| Command                       | What it does                                                          |
| ----------------------------- | --------------------------------------------------------------------- |
| `composer test`               | Pest `Unit`+`Arch` — fast, no DB/webserver needed                     |
| `composer test:integration`   | Pest `Integration` — needs `.env.test` + `piwigo_test` DB             |
| `composer test:contract`      | Pest `Contract` — WS API contract tests against the committed fixture |
| `composer test:browser`       | Pest `Browser` — E2E flows via `pest-plugin-browser` (Chromium)       |
| `composer test:visual`        | Visual regression only — **run in isolation**, see below              |
| `composer test:fixture-regen` | Rebuilds `tests/Fixtures/piwigo-16.x.sql` from a fresh install + seed |

### Env split (P2)

Tests run against a throw-away `piwigo_test` database, never production. Copy
`.env.example`'s `.env.test` block to a real `.env.test` (gitignored) and fill in
credentials — dev default is `root`/`1234`@`127.0.0.1`/`piwigo_test`, matching this
repo's local MySQL. `PIWIGO_BASE_URL` must point at a running Apache vhost serving this
checkout (e.g. `http://localhost/piwigo17`) — Integration/Contract/Browser tests all make
real HTTP requests, not just anything in-process.

The mechanism (`include/env.inc.php`, wired into `common.inc.php`/`install.php`/`i.php`):
an `X-Piwigo-Env: test` header, honored only from loopback, switches the runtime to read
`.env.test` and gate on `local/.installed.test` instead of `.env`/`local/.installed`.
`tests/bootstrap.php` sets this header for the whole Pest CLI process; Browser tests set
it per-context via Playwright's `extraHTTPHeaders` (see
`tests/Browser/Helpers/BrowserTestHelpers.php`). `symfony/dotenv` loads the file;
existing process env vars always win (never overridden). install.php still writes a
legacy `local/config/database.inc.php` shim in **prod** mode only, so `upgrade.php` and a
few other not-yet-migrated scripts keep working (P13 unifies config loading properly).

### Fixture

`tests/Fixtures/piwigo-16.x.sql` is a committed dump — a fresh install (`fixture_admin`/
`fixture_admin`) plus seed content (2 albums, 5 photos, 3 tags, 5 comments, 3 groups, 2
extra users, ratings/favorites/a permalink/some config tweaks). Contract and most Browser
tests load this same file rather than reseeding per run.

To rebuild it: `composer test:fixture-regen` (tagged `fixture-regen`, excluded from
`test:browser` — it wipes `piwigo_test` and overwrites the committed fixture, so it's
opt-in, not a regression test). Uploaded photos land under `upload/` (gitignored) and
must exist on disk for image-dependent pages/tests to render — running this once per
environment (fresh clone, CI image) is expected, not automatic per test run.

### Contract tests (WS API)

`tests/Contract/ContractTestCase` drives `ws.php` over curl with its own cookie jar per
test, validating responses against JSON Schema files in `tests/Contract/schemas/`
(`justinrainbow/json-schema`). 21 `Ws*Test` classes cover the WS methods actually
registered in `ws.php` — ported method-by-method against the real registry, not assumed.
These lock the legacy WS response shapes while P2-P23 refactor the internals; P26 removes
the WS API and retires them for REST contract tests against `/api/v1`.

### Browser tests (E2E)

15 flows in `tests/Browser/` via `pestphp/pest-plugin-browser` (Chromium; no standalone
Playwright config). `tests/Browser/Helpers/BrowserTestHelpers.php` centralizes the
patterns every flow needs: `visitPwg()`/`loginAsAdmin()` (test-mode header via
`extraHTTPHeaders`, since the plugin has no dedicated per-request header API),
`navigateOk()` (continues in the same browser context so the session cookie survives —
calling `visit()` again starts a fresh one), `wsCall()` (drives the WS API through that
session via a same-origin `fetch()` POST run in the page — several WS methods reject
GET), and `uploadPhotoViaApi()` (a fresh curl-based login for the actual multipart
upload, since the admin upload UI is a JS/plupload widget with no plain
`<input type="file">` fallback to automate reliably).

`phpunit.xml.dist`'s `<source ignoreIndirectDeprecations="true">` exists because
`pest-plugin-browser`'s own retry/polling internals trip a PHP 8.4+
`ReflectionProperty::setValue()` deprecation on every `assertMissing()`/`assertVisible()`
retry — matches PHPUnit's own documented default config for vendor-internal
deprecations; first-party code triggering a deprecation directly still fails the suite.

### Visual regression

`tests/Browser/VisualRegressionTest.php` — 32 screenshot baselines via
`assertScreenshotMatches()` (Pest's native snapshot system, `tests/.pest/snapshots/`, not
loose PNGs). **Must run in isolation**: `composer test:visual`, never bundled with the
CRUD-mutating Browser tests (`AlbumCreateTest`, `TagCrudTest`, `UserManagementTest`, ...) —
those drift the sidebar's live "N Albums/Photos/Users" counts, producing false diffs.

Re-baseline after an intentional visual change (P29 templates, P30 CSS):

```
vendor/bin/pest tests/Browser/VisualRegressionTest.php --update-snapshots
```

Determinism fixes landed in the same commit as the baselines they protect (see that
commit's message for the full reasoning), not a later cleanup pass:

- `piwigo_images.hit` ("Visited N times", shown on `picture.php` and the admin photo
  editor) is pinned via `BrowserTestHelpers::freezeImageHits()` before each screenshot
  that would otherwise show it.
- `piwigo_history` is wiped via `BrowserTestHelpers::truncateHistory()` right before the
  `admin-history` screenshot — its Search tab always filters to today (no start/end GET
  override exists to pin a different range), so it would otherwise show whatever real
  guest page-views the rest of the run had already logged.
- **`pwg_now()`** (`include/env.inc.php`) freezes what `time_since()`
  (`include/functions.inc.php`) and the admin dashboard's activity-chart computation
  (`admin/intro.php`) treat as "now", via a `PIWIGO_TEST_NOW` env var read only in test
  mode (zero behavior change in production — falls through to real `new DateTime()`
  whenever the var is unset). This makes `admin-photo-editor`, `admin-dashboard`,
  `admin-album`, and `admin-users` deterministic — all four were previously **excluded**
  based on an inherited, never-verified claim that the dashboard's issue was a
  client-side Chart.js canvas needing a full mockable-clock kernel layer (P7-P12).
  Re-reading the actual code found that claim wrong: the dashboard's "Activity peak"
  widget is plain server-rendered Smarty from the same kind of `new DateTime()` call as
  everything else here — no canvas at all. (A genuinely separate page,
  `admin/themes/default/template/stats.tpl` + `stats.js`, does use Chart.js and was
  likely conflated with the dashboard; it isn't in this suite.)
- The dashboard also made two live calls to piwigo.org unrelated to the clock —
  `get_piwigo_news()` (a real news-feed fetch) and `pwg.extensions.checkUpdates` (a
  core/extension update check) — both enabled by default
  (`config_default.inc.php:806,810`). `pwg_now()` does nothing for these; fixed
  separately by disabling both config keys in the fixture itself
  (`tests/Browser/RegenerateFixtureTest.php`). This was a genuinely pre-existing gap:
  `AdminSmokeTest`/`ConsoleCleanTest` already visit `/admin.php` and had been silently
  making these live calls the whole time, unnoticed because neither screenshot-compares
  and a failed fetch is swallowed silently (`fetchRemote()`'s curl calls are
  `@`-suppressed).
- `install.php`'s env-file rewrite now preserves any pre-existing custom line (like
  `PIWIGO_TEST_NOW`) instead of silently dropping it — needed because
  `tests/Browser/RegenerateFixtureTest.php` drives a real re-install every time the
  fixture is regenerated, and `install.php` previously rewrote `.env.test` from scratch
  with only the `PIWIGO_DB_*`/`PIWIGO_BASE_URL` keys it manages.

`admin-history`'s "Search" tab also has a genuine timing race independent of the above:
its results panel loads via async request
(`admin/themes/default/js/history.js`) that can still be in flight when
`assertScreenshotMatches()` fires despite its built-in networkidle/readyState waits.
Fixed with `BrowserTestHelpers::waitUntilHidden()`, which polls in-browser (via
`script()`) for the `.loading` spinner to actually disappear — neither `assertSee()` nor
`assertMissing()` actually retry (both are one-shot checks under the hood, confirmed by
reading pest-plugin-browser's own implementations after both flaked on this exact page).
Investigating that race also surfaced a real, previously-unnoticed **production bug**:
`pwg.history.search` (`include/ws_functions/pwg.php`) indexed two arrays with a
possibly-`null` `$line['category_id']`, tripping a PHP 8.5 "Using null as an array
offset" deprecation that got printed straight into the WS JSON response body —
corrupting it for every real client, not just this test. Fixed at the source
(null-check before indexing), not routed around.

**Run `composer test:visual` only against a freshly-reloaded fixture**, never right
after `composer test:browser` — the CRUD-mutating flows it runs (`AlbumCreateTest`,
`TagCrudTest`, `UserManagementTest`, ...) drift the sidebar's live counts exactly as the
class docblock warns, and chaining the two without reimporting
`tests/Fixtures/piwigo-16.x.sql` in between produces a wave of false diffs across nearly
every page.

**Two more non-obvious reliability gotchas**, found while regenerating baselines:

- pest-plugin-browser's own `playwright run-server` subprocess is **not cleaned up**
  when the Pest CLI process exits. Across a long session with many browser-test
  invocations these accumulate — 83 orphaned processes were observed consuming 6.4 GB
  of RAM in one session, which in turn causes screenshot comparisons to fail with a
  generic "missing image" placeholder (no real pixelmatch diff — expected/actual are
  pixel-identical on inspection) rather than a true content difference. Check for and
  kill them (`pkill -f "playwright run-server"`) before trusting a run of failures as
  real; always confirm via an isolated re-run before concluding transience, never
  dismiss a failure on sight.
- Reimporting the fixture DB immediately before running browser tests, with no
  settling time, can itself cause the same class of transient timeout on the very next
  test (observed consistently across several attempts, resolved by simply running a
  cheap query like `SELECT COUNT(*) FROM piwigo_images` against the freshly-imported DB
  before starting Pest — apparently enough to let MySQL/PHP's connection pool settle).

## CI (P3)

`.github/workflows/ci.yml` runs on every push/PR (docs-only changes excluded via
`paths-ignore`). Every gate from the tables above gets its own job, translated to what's
actually runnable today rather than the plan doc's prose verbatim (its script names and
thresholds don't all match this repo 1:1 — see below):

| Job | Command | Status |
| --- | --- | --- |
| `pest` | `composer test` | blocking |
| `ecs` | `composer lint:php` | **non-blocking** until P5 (matches `lefthook.yml`) |
| `phpstan` / `psalm` | `analyse --no-progress` / `--no-cache` | blocking |
| `rector` | `--dry-run` | **non-blocking** until P5 |
| `eslint` / `stylelint` / `vitest` | `bun run lint:js` / `lint:css` / `test` | blocking |
| `coverage` | `composer test:coverage` (`--min=0.1`) | blocking at the measured 0.2% baseline floor |
| `audit` | `composer audit` + `bun audit --ignore=...` | blocking — see below for the 3 documented ignores |
| `deptrac` | guarded on `hashFiles('deptrac.yaml')` | no-op until P6 |
| `require-checker` / `composer-unused` / `knip` | as `composer`/`bun run` scripts | blocking |
| `actionlint` | `reviewdog/action-actionlint` | blocking, self-validates every workflow file |
| `phpbench` | `--report=aggregate`, uploaded as an artifact | blocking (passes trivially with 0 subjects until P12) |
| `size-limit` | `bun run build && bun run size-limit` | blocking (real placeholder budget) |
| `k6-load` | guarded on `hashFiles('tests/Load/**')` | no-op + non-blocking until P29 |
| `test-file-inventory` | `find tests/<Dir> -name '*Test.php'` per suite | blocking — catches a testsuite silently running 0 tests (see below) |
| `integration` / `contract` / `browser` / `visual-regression` | the matching `composer test:*` script | blocking, against an ephemeral `mysql:9.7` service container + `php -S`, fixture imported fresh each run |
| `lighthouse` | `bunx lhci autorun` | collect/upload only — no `assert` block until P10 |
| `commitlint` | event-appropriate commit range | blocking |
| `sbom` | `composer sbom` + `cyclonedx-npm`, `actions/attest-build-provenance` | blocking (SEC-50, SEC-53) |

Separate workflow files: `osv-scanner.yml` (SEC-52, weekly + push/PR, Google's reusable
workflows) and `scorecard.yml` (SEC-64, weekly + push) run independently of `ci.yml`.
`release-please.yml` wires up the P1-landed config, targeting `17.x-rewrite` explicitly
— this repo's actual GitHub default branch, `16.x-rewrite`, is an unrelated earlier
rewrite lineage, so "push to main" (the plan doc's phrasing) doesn't apply literally.

**Non-obvious gotchas, verified rather than assumed:**

- `tests/Integration/` had **zero** concrete `*Test.php` files until this phase — only
  the shared `IntegrationTestCase` base class. `composer test:integration` silently
  exited non-zero ("No tests found") since P2. `DatabaseConnectionTest.php` restores the
  smoke test P2's own plan called for but never actually landed. The `test-file-inventory`
  job exists specifically to catch this class of regression on disk, cheaply, without
  re-running every suite.
- **`--exclude-group=fixture-regen,visual-regression` (one flag, comma-joined) silently
  did not exclude `fixture-regen`** — reproduced directly (`--filter=RegenerateFixtureTest`
  still matched and ran it, wiping `piwigo_test` and overwriting the committed fixture
  mid-suite, corrupting state for every test that ran after it alphabetically). Fixed by
  passing the flag twice (`--exclude-group=fixture-regen --exclude-group=visual-regression`
  — verified this form actually excludes both) in `composer.json`'s `test:browser` script.
  This had been silently broken since P2; every prior `composer test:browser` run had been
  destructively regenerating the fixture without anyone noticing, since the resulting
  fixture content is shape-compatible (same row counts, different timestamps/IDs) so nothing
  failed loudly except by the accident of alphabetical test ordering.
- `bun audit` and OSV-Scanner (`osv-scanner.toml`) both need to ignore the same 3 GHSAs
  (`GHSA-52f5-9888-hmc6`, `GHSA-ph9p-34f9-6g65`, `GHSA-w5hq-g745-h8pq`) — transitive
  `tmp`/`uuid` pins inside `@lhci/cli@0.15.1` (latest, dev-only, never shipped), no fixed
  release available upstream. Re-check both places on every Renovate bump of `@lhci/cli`.
- `composer audit --abandoned=fail` fails today: `phpbench/phpbench` (dev-only) requires
  `doctrine/annotations`, which Composer flags abandoned with no replacement. CI runs
  plain `composer audit` (still reports it, just doesn't escalate a transitive dev-only
  dependency we don't control to a hard failure).
- `@cyclonedx/cyclonedx-npm` doesn't read `bun.lock` — it needs an npm-format lockfile.
  The `sbom` job runs real `npm install --package-lock-only --ignore-scripts` first (a
  throwaway snapshot, gitignored, never committed — `bun.lock` stays authoritative).
- **`psalm.xml`/`phpstan.neon` never excluded `node_modules`** before this phase — some
  npm packages ship stray `.php` files (e.g. `flatted`'s PHP port), and Psalm's stricter
  analysis flagged them plus drifted the baseline enough that `psalm-baseline.xml` no
  longer matched a genuinely clean checkout (verified via an isolated `git worktree`,
  independent of any change in this phase). Fixed by excluding `node_modules` in both
  configs and regenerating `psalm-baseline.xml` fresh, then re-verifying "No errors
  found!" is stable across repeated runs — not just fixed once and assumed to hold.
- `_data/` is fully gitignored and only appears at runtime (`mkgetdir()` calls scattered
  through `template.class.php`/`cache.class.php`/`Logger.class.php`) — but `psalm.xml`'s
  `ignoreFiles` needs the directory to literally **exist** to resolve that config path at
  all, or Psalm fails immediately with a config-parse error before analysis even starts.
  A committed `_data/.gitkeep` (gitignore switched to `/_data/*` + `!/_data/.gitkeep`,
  matching the existing `/local/*` pattern) guarantees this on every fresh checkout,
  including CI.
- CI uses PHP's built-in server (`php -S`), not Apache — this app has no `.htaccess` or
  `RewriteRule` dependency yet (every tested route is `?page=`-style query strings, not
  pretty URLs), confirmed by reading the full route list before deciding this.
