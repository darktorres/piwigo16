# Fixtures

`piwigo-16.x.sql` is a committed `mysqldump` of a freshly-installed Piwigo with seed
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
from `composer test:browser`): drops and recreates `piwigo_test`, drives a real
`install.php` submission, seeds the content listed above via the WS API, then dumps the
result over this file. Rerun it whenever the fixture's shape needs to change (new
tables/columns from a later phase's migration, more seed data needed by a new test) —
not part of the normal day-to-day test loop, which just loads the file as committed.

Since it wipes `piwigo_test`, never point `PIWIGO_DB_BASE` in `.env.test` at a real
database — `RegenerateFixtureTest` refuses to run at all if `PIWIGO_DB_BASE` is empty or
literally `piwigo`, but any other name is fair game and will be dropped.

After regenerating, bump `PIWIGO_TEST_NOW` (`.env.test`) forward to a date safely after
whatever real timestamp this run baked in — `pwg_now()` (`include/env.inc.php`) freezes
`time_since()`-based "N units ago" text to that fixed instant, and a `PIWIGO_TEST_NOW`
left in the past relative to the fixture's own timestamps would render as "in the
future" instead. Same idea as bumping a baseline alongside the file it protects.
