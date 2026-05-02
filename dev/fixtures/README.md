# dev/fixtures

## piwigo-16.x.sql

A real Piwigo 16.x database dump used by `UpgradeChainTest` to verify the upgrade path.

### What's in it

A fully installed Piwigo 16.x database with representative data:
- At least 2 albums (one root, one sub-album)
- At least 5 uploaded photos with metadata
- 3 users (admin + 2 regular users with different permission levels)
- At least 1 comment and 3 tags
- A handful of changed configuration values (to exercise the `$conf` write path)
- The admin user has username `fixture_admin` and password `fixture_admin`

### How to regenerate

Automated via Playwright. Drives a full install + content seed against a
scratch database (`piwigo_fixture_build`), then dumps it. Credentials come
from `.env.local`; see `.env.example` for the variable list.

```bash
REGENERATE_FIXTURE=1 npx playwright test tests/e2e/regenerate-fixture.spec.ts
```

The spec is skipped by default — it writes a real local database and
overwrites the committed fixture file, so it only runs when explicitly
opted in. Cleanup (drop scratch DB, remove `local/config/database.inc.php`)
runs in `afterAll` regardless of pass/fail.

After the run completes:

```bash
git diff --stat dev/fixtures/piwigo-16.x.sql   # confirm the new dump
git add dev/fixtures/piwigo-16.x.sql && git commit -m "..."
```

A clean dump from this spec lands around 45-50 KB (~30 KB for 34 CREATE
TABLE statements + ~15 KB seed data). The historical 241 KB fixture was
inflated by ~376 phantom image rows (synthetic seed data with no on-disk
files) — that drift is what motivated the rewrite to a deterministic,
scripted regeneration.

### When to regenerate

- Phase 6 pre-floor cleanup bumps the fixture to 16.x-only schema
- Any time a migration adds required rows to existing tables
