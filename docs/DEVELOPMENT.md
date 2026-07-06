# Development

`17.x-rewrite` replays `16.x-rewrite`'s modernization in phases (`docs/PLAN-REPLAY.md`).
This doc covers what's set up so far; it grows with each phase.

## Requirements

- PHP 8.5 (`ext-calendar`, `ext-ctype`, `ext-curl`, `ext-dom`, `ext-fileinfo`,
  `ext-filter`, `ext-gd`, `ext-iconv`, `ext-intl`, `ext-libxml`, `ext-mbstring`,
  `ext-mysqli`, `ext-openssl`, `ext-session`, `ext-simplexml`, `ext-zlib`; `pcov` for
  coverage)
- Composer 2.x
- Node 24, bun, [`just`](https://github.com/casey/just)

## Setup

```
composer install
bun install
```

`just` runs recipes across both stacks (`just --list` to see them all) — thin wrappers
around the `composer`/`bun` commands below.

## Running the tools

| Command                    | What it does                                                                  |
| -------------------------- | ----------------------------------------------------------------------------- |
| `composer test`            | Run Pest (`Unit` + `Arch` suites so far)                                      |
| `composer analyse:phpstan` | PHPStan against `phpstan-baseline.neon`                                       |
| `composer analyse:psalm`   | Psalm against `psalm-baseline.xml`                                            |
| `composer analyse`         | Both of the above                                                             |
| `composer lint:php`        | ECS in check mode (**not** `--fix` yet — see below)                           |
| `composer require-checker` | Composer-require-checker against `composer-require-checker.json`              |
| `composer unused`          | Composer-unused against `composer-unused.php`                                 |
| `composer bench`           | PHPBench (`tests/Bench/` is empty until P12)                                  |
| `composer plan-lint`       | Validates `docs/plan/manifest.yaml` (tier/depends_on presence, acyclic graph) |
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

`vendor/bin/ecs check` (no `--fix`) reports **571** legacy style violations as of P0 —
recorded, not yet gated. The whole-codebase ECS reformat is deferred to P5 step 11, once
the P2 regression harness exists to catch a misbehaving fixer (see
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

Only `tests/Unit` and `tests/Arch` are wired into `phpunit.xml.dist` so far
(`composer test`). `tests/Integration`, `tests/Contract`, and browser E2E land in P2
once their env/fixture infrastructure exists.
