# Fixtures

`piwigo-17.0.sql` is a committed `mysqldump` of a freshly-installed Piwigo with seed
content, loaded by `IntegrationTestCase::loadFixture()` at the start of every Contract
test process (and by most Browser tests, indirectly, since they run against whatever the
test DB currently holds).

## What's in it

- Install credentials: `fixture_admin` / `fixture_admin` (webmaster)
- 2 albums: a root "Sample Album" and a nested "Nested Sub Album"
- 5 photos (solid-color JPEGs generated via GD, not real photographs), 3 in the root
  album and 2 in the sub-album
- 3 tags (`nature`, `travel`, `family`), attached across the photos
- 2 extra users (`regular_user`, `power_user`) and 3 groups (`Editors`, `Reviewers`,
  `Guests`) with memberships
- 5 comments (one unvalidated, to exercise moderation), 5 ratings, 3 favorites, a mail
  notification entry, an old permalink, and a few config tweaks (gallery title, comments
  enabled, `show_piwigo_latest_news`/`dashboard_check_for_updates` disabled so the admin
  dashboard never makes a live call to piwigo.org from a test run, etc.)

Uploaded photo files themselves land under `upload/` (gitignored, not part of this dump)
— they're regenerated fresh every time the fixture is rebuilt, so don't assume a specific
upload path or filename persists across regenerations, only the DB-visible shape (ids,
names, tag/album associations) does.

## Regenerating

```
composer test:fixture-regen
```

This runs `tests/Browser/RegenerateFixtureTest.php` (tagged `fixture-regen`, excluded
from `composer test:browser`): drops and recreates the test database, drives a real
`install.php` submission (which creates the final schema via the real Doctrine
Migrations baseline `InstallWizard::performInstall()` runs, not a static SQL file),
seeds the content listed above via the WS API, then dumps the result over this file.
Rerun it whenever the fixture's shape needs to change (new tables/columns, more seed data
needed by a new test) — not part of the normal day-to-day test loop, which just loads the file
as committed.

Since it wipes `piwigo_test`, never point `PIWIGO_DB_BASE` in `.env.test` at a real
database — `RegenerateFixtureTest` refuses to run at all if `PIWIGO_DB_BASE` is empty or
literally `piwigo`, but any other name is fair game and will be dropped.

After regenerating, bump `PIWIGO_TEST_NOW` (`.env.test`) forward to a date safely after
whatever real timestamp this run baked in — `pwg_now()` (`include/env.inc.php`) freezes
`time_since()`-based "N units ago" text to that fixed instant, and a `PIWIGO_TEST_NOW`
left in the past relative to the fixture's own timestamps would render as "in the
future" instead. Same idea as bumping a baseline alongside the file it protects.

## `GoldenHtml/`

Raw HTML response bodies for every route in `Helpers/VisualRegressionRoutes.php`,
originally captured while the app was still 100% Smarty-rendered — the P31
(Smarty → Latte) migration's baseline, back before any template had converted.
P31 is now done (every template is `.latte`, the Smarty engine itself is fully
removed), so this fixture no longer represents "current Smarty output" — it's the
frozen pre-migration snapshot every template's own conversion diff was checked
against. Written by `tests/Browser/GoldenHtmlSnapshotTest.php`
(`composer test:golden-html`), one file per route name.

Not a byte-identical assertion target: Latte's auto-escaping was deliberately
enabled during the migration (see `docs/PLAN.md`'s P31 section), so a converted
template's output was *expected* to differ here wherever escaping applied where
Smarty ran raw. Each template's own conversion sub-item diffed its new output
against its file here and a human classified every changed line — an
escaping-related change or a real regression. Kept as a historical record now
that P31 is done; a future phase (P32+) touching these same routes' markup
again would need a fresh baseline capture, not a diff against this one.
