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

Uses the local Apache + MySQL serving this checkout (the same stack the
integration tests target). Credentials live in `.env.local` (see
`.env.example` for the variable list).

1. Reset a scratch database — never use the production DB:
   ```bash
   mysql -u root -p"$PIWIGO_DB_PASSWORD" -e \
     "DROP DATABASE IF EXISTS piwigo_fixture_build;
      CREATE DATABASE piwigo_fixture_build CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
   ```
2. Point Apache at the scratch DB by writing `local/config/database.inc.php`:
   ```php
   <?php
   $conf['dblayer'] = 'mysqli';
   $conf['db_host'] = '127.0.0.1:3306';
   $conf['db_user'] = 'root';
   $conf['db_password'] = '...';
   $conf['db_base'] = 'piwigo_fixture_build';
   $prefixeTable = 'piwigo_';
   ```
   Leave `PHPWG_INSTALLED` undefined so the next request hits `install.php`.
3. Visit `http://localhost/piwigo16/install.php`. Admin: `fixture_admin` / `fixture_admin`.
4. Log in as `fixture_admin` and create the representative content listed above.
5. Dump: `mysqldump -u root -p"$PIWIGO_DB_PASSWORD" piwigo_fixture_build > dev/fixtures/piwigo-16.x.sql`
6. Delete the scratch DB and `local/config/database.inc.php`.
7. Commit the new dump.

The fixture should be ≥ 200 KB to exercise representative tables.

### When to regenerate

- Phase 6 pre-floor cleanup bumps the fixture to 16.x-only schema
- Any time a migration adds required rows to existing tables
