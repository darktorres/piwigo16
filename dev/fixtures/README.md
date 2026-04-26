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

1. Start the dev stack: `docker compose up -d --wait db web`
2. Reset the database: `docker compose exec db mariadb -uroot -proot -e "DROP DATABASE piwigo; CREATE DATABASE piwigo CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"`
3. Install Piwigo at http://localhost:8080/install.php (db host: `db`, user: `piwigo`, pass: `piwigo`, db: `piwigo`, admin: `fixture_admin`, pass: `fixture_admin`)
4. Log in as `fixture_admin` and create the representative content listed above
5. Dump: `docker compose exec db mysqldump -upiwigo -ppiwigo piwigo > dev/fixtures/piwigo-16.x.sql`
6. Commit the new dump

The fixture should be ≥ 200 KB to exercise representative tables.

### When to regenerate

- Phase 6 pre-floor cleanup bumps the fixture to 16.x-only schema
- Any time a migration adds required rows to existing tables
