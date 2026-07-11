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
rerunning when the fixture itself must change. `test:integration`/`test:contract`/
`test:browser`/`test:visual` don't need it re-run first — each reimports the committed
`tests/Fixtures/piwigo-16.x.sql` dump itself before its tests start (`test:integration`
via `DatabaseConnectionTest::setUp()`; `test:contract` via `ContractTestCase::setUp()`;
`test:browser`/`test:visual` via `tools/reimport-fixture.sh`), so they're self-contained
regardless of what a previous run left the DB looking like — even a fully dropped
`piwigo_test` database.

## CI (P3)

`.github/workflows/ci.yml` runs the full gate set above on every push/PR — see
`docs/DEVELOPMENT.md`'s CI section for the job list and what's guarded vs. live today.
Unlike local dev, CI provisions its own ephemeral `piwigo_test` per run (a `mysql:9.7`
service container, fixture imported fresh via `mysql < tests/Fixtures/piwigo-16.x.sql`)
and serves the checkout via PHP's built-in server (`php -S`) rather than Apache — this
app has no `.htaccess`/pretty-URL dependency yet, so this is a faithful, lower-setup
substitute for the Integration/Contract/Browser/Visual suites. P4 adds two additional,
narrowly-scoped jobs on top rather than migrating those suites: one serves the checkout
via real Apache with the shipped `.htaccess`, one builds and runs the production
container image — both just curl the SEC-01 deny-rule paths and assert 403, proving the
deny rules on every server this project actually ships, without touching the existing
`php -S`-based suites.

`.github/workflows/osv-scanner.yml` and `scorecard.yml` are supply-chain jobs (SEC-52,
SEC-64) independent of the main pipeline — weekly-scheduled in addition to push/PR.
`release-please.yml` targets `17.x-rewrite` explicitly (this repo's actual GitHub
default branch, `16.x-rewrite`, is an unrelated earlier rewrite lineage).

## Incident response (P4)

1. **Identify**: `/health` (liveness — is PHP itself serving) and `/ready` (readiness —
   can it reach the DB) are the first two checks; both are plain-text `200`/`503`, no
   auth, safe to poll from any monitoring system. Container/Helm-level: `docker logs
   <container>` or `kubectl logs deploy/<release>-piwigo`.
2. **Contain**: scale the affected deployment to 0 (Helm: `kubectl scale
   deploy/<release>-piwigo --replicas=0`; Compose: `docker compose stop app`) if the
   incident is active data corruption or an in-progress compromise — don't just restart,
   a restart can destroy forensic state (container filesystem, in-memory state).
3. **Diagnose**: pull the SBOM (`sbom-composer.cdx.json`, `.github/workflows/ci.yml`'s
   SBOM job) and the OSV-Scanner / Scorecard history if the trigger might be a dependency
   CVE. Check `/app/local/config/config.inc.php` and the `PIWIGO_DB_*`/`PIWIGO_*` env
   vars for unauthorized changes.
4. **Eradicate + recover**: redeploy from a known-good image tag/digest (never patch a
   running container in place); restore data per the Restore section below if the
   `_data`/`local`/`galleries`/`upload` volumes themselves were affected.
5. **Post-incident**: rotate every secret the incident could have exposed (see Secret
   rotation below) even if exposure is only suspected, not confirmed.

## Restore (real CLI landed in P12)

`bin/piwigo backup:create` / `bin/piwigo backup:restore <file> --force` are real —
`mysqldump`/`mysql`/`tar` shelled out via `PIWIGO_DB_*` env vars (`Piwigo\Db\
DbCredentials`), same CLI-safe mechanism `tools/restore-drill.sh`/`tools/
reimport-fixture.sh` already used. `backup:create` writes a timestamped `.tar.gz`
(db dump + `galleries/` + `local/config/config.inc.php` if present) to
`_data/backups/`; `backup:restore` validates the archive's `manifest.json`, restores
the DB into `--database` (defaults to `PIWIGO_DB_BASE`), and restores `galleries/`.
`--force` is required — it drops/recreates the target database. Neither "stops the
app" nor "runs pending migrations": no live traffic is routed through the new request
pipeline yet (nothing to stop), and Doctrine Migrations don't exist until P14 (nothing
to run).

`tools/restore-drill.sh` was written back in P4 anticipating a rewire onto these
commands once P12 landed — deliberately **not done**. That CI job
(`.github/workflows/ci.yml`'s `restore-drill` job) is intentionally PHP-dependency-free
(checkout + write `.env.test` + bash script, no `composer install` step, ~10s);
rewiring it onto `bin/piwigo` would mean adding a full PHP/composer setup to a job
built to stay minimal. Real command-level proof instead comes from
`tests/Integration/BackupServiceTest.php`, which round-trips
`BackupService::create()`/`restore()` against `piwigo_test` into a scratch DB, reusing
the exact row-count + join-query smoke assertions below. `restore-drill.sh` itself
keeps restoring the tracked `tests/Fixtures/piwigo-16.x.sql` mysqldump directly (never
`piwigo_test`/production) as its own independent, lean proof:

```
bash tools/restore-drill.sh
```

For an actual production restore: prefer `bin/piwigo backup:restore <file> --force
--database=<scratch>` into a scratch database first, run the same two smoke assertions
`restore-drill.sh` does (row counts on a couple of core tables, one join query)
against it, and only then point the real deployment at it (or re-run
`backup:restore` against the real target once the scratch dry run passes). Never
restore directly onto a live production database without a scratch-DB dry run first.

## Secret rotation

- **DB password** (`PIWIGO_DB_PASSWORD` / Helm `db.existingSecret`): rotate at the
  MySQL user level first (`ALTER USER ... IDENTIFIED BY ...`), then update the
  Secret/env var and roll the deployment (`kubectl rollout restart` / `docker compose up
  -d app`) — rotating the Secret before the DB user would lock the app out.
- **`secret_key`** (`piwigo_config`, used for session/CSRF token signing): rotating it
  invalidates all existing sessions and CSRF tokens — plan for a forced re-login, don't
  do it silently during business hours.
- **Container registry / signing credentials** (cosign/sigstore keyless OIDC, SEC-54):
  no long-lived key to rotate by design (Fulcio short-lived certs) — rotate the CI
  identity's OIDC trust (GitHub Actions OIDC issuer config) if that trust itself is
  suspected compromised.

## Disaster recovery

RPO/RTO target: the `_data`/`local`/`galleries`/`upload` volumes and the production
database are the only stateful assets (the container image itself is rebuildable from
this repo at any commit) — run `bin/piwigo backup:create` (P12) on the same schedule
and replicate `galleries/` (original photos — the least replaceable asset; `upload/`
is transient incoming-upload staging, not durable storage, per the Dockerfile's own
volume comment) to offsite storage at least daily. Full recovery is: rebuild/pull the
image at the last known-good tag, restore the database (Restore section above),
restore the volumes from their offsite copy, redeploy.
