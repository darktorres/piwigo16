# Runbook

## Bootstrap a clean checkout

CI is the sole definition of "green" (see `docs/PLAN-REPLAY.md`'s working rule) — this is
the one-command reproduction of that gate on any fresh worktree:

```
composer install && composer dump-autoload
```

(`--classmap-authoritative` is added back once P6 gives the autoloader a real PSR-4 map to
be authoritative about — nothing is namespaced yet.)

`bun install` is added to this bootstrap once P1 lands.

## Gates

`composer test`, `composer analyse`, `composer lint:php`, `composer require-checker`,
`composer unused`, `composer bench`, `composer plan-lint` — see `docs/DEVELOPMENT.md` for
what each does and its current baseline.
