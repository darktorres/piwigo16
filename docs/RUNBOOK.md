# Runbook

## Bootstrap a clean checkout

CI is the sole definition of "green" (see `docs/PLAN-REPLAY.md`'s working rule) — this is
the one-command reproduction of that gate on any fresh worktree:

```
composer install && composer dump-autoload && bun install
```

(`--classmap-authoritative` is added back once P6 gives the autoloader a real PSR-4 map to
be authoritative about — nothing is namespaced yet.)

## Gates

`composer test`, `composer analyse`, `composer lint:php`, `composer require-checker`,
`composer unused`, `composer bench`, `composer plan-lint` — see `docs/DEVELOPMENT.md` for
what each does and its current baseline.

## Test database (P2)

`composer test` (Unit+Arch) needs nothing beyond the bootstrap above. Everything else
needs a real MySQL instance and a webserver serving this checkout:

```
node_modules/.bin/playwright install chromium   # once per environment
cp .env.example .env.test                       # fill in DB creds + PIWIGO_BASE_URL
composer test:fixture-regen                      # builds piwigo_test + tests/Fixtures/*.sql
composer test:integration && composer test:contract && composer test:browser
composer test:visual                             # run separately — see docs/DEVELOPMENT.md
```

`test:fixture-regen` is destructive to `piwigo_test` (never production) and only needs
rerunning when the fixture itself must change — day-to-day `test:contract`/`test:browser`
runs reuse the committed `tests/Fixtures/piwigo-16.x.sql` dump as-is.
