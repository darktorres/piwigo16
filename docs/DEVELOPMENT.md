# Development

`17.x-rewrite` replays `16.x-rewrite`'s modernization in phases (`docs/PLAN-REPLAY.md`).
This doc covers what's set up so far; it grows with each phase.

## Requirements

- PHP 8.5 (`ext-calendar`, `ext-ctype`, `ext-curl`, `ext-dom`, `ext-fileinfo`,
  `ext-filter`, `ext-gd`, `ext-iconv`, `ext-intl`, `ext-libxml`, `ext-mbstring`,
  `ext-mysqli`, `ext-openssl`, `ext-session`, `ext-simplexml`, `ext-zlib`; `pcov` for
  coverage)
- Composer 2.x

## Setup

```
composer install
```

## Running the tools

| Command | What it does |
| --- | --- |
| `composer test` | Run Pest (`Unit` + `Arch` suites so far) |
| `composer analyse:phpstan` | PHPStan against `phpstan-baseline.neon` |
| `composer analyse:psalm` | Psalm against `psalm-baseline.xml` |
| `composer analyse` | Both of the above |
| `composer lint:php` | ECS in check mode (**not** `--fix` yet — see below) |
| `composer require-checker` | Composer-require-checker against `composer-require-checker.json` |
| `composer unused` | Composer-unused against `composer-unused.php` |
| `composer bench` | PHPBench (`tests/Bench/` is empty until P12) |
| `composer plan-lint` | Validates `docs/plan/manifest.yaml` (tier/depends_on presence, acyclic graph) |

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

## Tests

Only `tests/Unit` and `tests/Arch` are wired into `phpunit.xml.dist` so far
(`composer test`). `tests/Integration`, `tests/Contract`, and browser E2E land in P2
once their env/fixture infrastructure exists.
