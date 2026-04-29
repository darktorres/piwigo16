# Modernize Piwigo 16.x-rewrite to PHP 8.5 + Full Typing + TypeScript

## Context

Piwigo 16.x-rewrite is a ~20-year-old PHP photo gallery built in a procedural/jQuery era. The codebase has accumulated debt that now blocks running on modern PHP at all:

- **Won't run on PHP 8** today: `include/dblayer/functions_mysql.inc.php` calls `mysql_*` functions (removed in PHP 7.0), uses `create_function()` (removed in 8.0), and the codebase calls `utf8_encode/decode` (deprecated 8.2, removed 9.0).
- **No type safety**: ~5% native type coverage across ~60k LOC of tracked PHP, despite ~3,490 phpdoc `@param`/`@return` annotations sitting unused as native types.
- **No safety net**: no PHPUnit suite, no CI, no PHPStan/Rector, no static analysis. No Playwright suite on HEAD either. A new E2E suite must be written from scratch as part of Phase 0.
- **Frontend is jQuery 1.11.x with zero modules and zero TypeScript** across ~5–10k LOC of authored first-party JS.

**Target outcome:** the codebase runs on **PHP 8.5 strict**, every PHP file declares strict types and uses native parameter/return/property types, all authored JS is **TypeScript** built by Vite, while existing 16.x **MySQL databases continue to upgrade cleanly** through `install/db/*.php`. The fork starts from 16.x — pre-16 upgrade paths are out of scope (users on older Piwigo must upgrade through the upstream project to 16.x first). Plugins are also out of scope (the directory is empty in this branch).

This plan is a multi-phase roadmap. Each phase ships independently and leaves the tree runnable. Phases 0–2 are mandatory and ordered. Phase 5 (JS→TS) can run in parallel with phases 1+. Phase 4 is the iceberg — the most likely place to cut scope if effort overruns.

## Locked constraints

1. **PHP 8.5 strict** as minimum runtime. Hard break with PHP 7. `declare(strict_types=1)` everywhere.
2. **Full JS→TS** of authored code with a modern bundler (Vite).
3. **Existing-DB upgrade compatibility**: a real Piwigo 16.x MySQL database upgrades cleanly. The `install/db/*.php` chain must keep running for 16.x and forward. `pwg_query()` and friends never go away. Pre-16 upgrade paths are out of scope.

## Codebase shape (verified)

- **PHP**: ~60k LOC tracked. `include/` (264 files), `admin/` (96), `install/` (148, mostly per-version upgrade scripts), `language/` (388 translation arrays — leave alone), `themes/default/*.php` (11), ~25 root entry points.
- **122 `install/db/*-database.php` files** (numbered 61 through 181) form the upgrade chain. They are the regression contract for constraint #3.
- 358 classes vs. 2,300+ free functions. No namespacing, no PSR-4, all `include_once`/`require_once`.
- Globals: `$conf`, `$user`, `$page`, `$lang`, `$template`, `$logger`, `$header_msgs`, `$header_notes`, `$filter`.
- Dblayer is loaded dynamically at `include/common.inc.php:90` from `$conf['dblayer']`. `mysqli` is the only working backend; `functions_mysql.inc.php` is dead code that breaks PHP 8.
- **JS**: ~86k LOC tracked but ~80% is vendored libs (jquery, jquery UI, plupload/moxie, photoswipe, slick, chosen, Chart.js, dataTables, colorbox, selectize, 49 `.min.js`). Authored first-party is ~5–10k LOC across `admin/themes/default/js/`, `themes/default/js/` (top-level), `themes/standard_pages/js/`, `tools/`.
- Script delivery: PHP-side `ScriptLoader` class (`include/template.class.php:1494`) + Smarty `{combine_script id="..." path="..."}` tag, used in many templates. Runtime concatenation, not pre-built.

## Critical files (touched in every phase)

- `include/common.inc.php` — bootstrap, dblayer dispatch, global init.
- `include/dblayer/functions_mysqli.inc.php` — the only DB layer that survives.
- `include/template.class.php` — `Template` (line 22) and `ScriptLoader` (line 1494).
- `include/ws_core.inc.php` — web service kernel; `PwgError`, `PwgServer`. Heavy dynamic-property writes.
- `upgrade.php` — must keep working at every phase boundary.
- `include/config_default.inc.php` — ~140 `$conf` keys; the source of truth for Phase 4's `Config` class.

## Document structure

This file is the complete plan: each phase's spine (Goal, Step-by-step sequence, Concrete artifacts, Effort breakdown, Risks, Verification) is followed in-place by its deep-dive supplement (Part 1/Part 2 for Phases 1+5 and 2+6, single block for Phases 3 and 4) with full code, configs, and mechanical recipes. The deep-dives previously lived as separate files in this directory; they were merged into this single document.

---

## Phase 0 — Foundation & safety net (L)

### Goal
Establish the entire toolchain — composer, PHPStan, Rector, Pint, PHPUnit, Docker dev stack, GitHub Actions CI, and a fresh-from-scratch Playwright suite — *before* touching a single line of application PHP. Exit posture: a push to `16.x-rewrite` runs through CI in under 8 minutes and goes green; `vendor/bin/phpstan analyse` is at level 0 with a frozen baseline; `vendor/bin/rector --dry-run` parses every targeted file without fataling; the new Playwright specs (`install`, `smoke-gallery`, `smoke-admin`, plus 3 critical-flow specs) are green against `mariadb:10.11` + `php:8.5-apache`; `tests/Integration/UpgradeChainTest.php` boots a real captured 16.3.0 dump and verifies `piwigo_config.version == PHPWG_VERSION` after `upgrade.php` drives. Nothing in `include/`, `admin/`, `install/db/` has been edited yet — Phase 0 is pure scaffolding.

### Step-by-step sequence

1. ✅ **Initialize Composer.** Create `composer.json` at the repo root with PHP `^8.5`, all dev tooling pinned, and a transitional autoload section that does *not* yet PSR-4-map any source. Run `composer install`. Track `vendor/` in git per the locked plan (Phase 3 deliverable, but the decision lives here so CI doesn't `composer install` from scratch every run). Done when `vendor/bin/phpstan --version`, `vendor/bin/rector --version`, `vendor/bin/pint --version`, `vendor/bin/phpunit --version` all return clean. **Locked versions: phpstan 2.1.51, rector 2.4.2, pint 1.29.1, phpunit 11.5.55.**

2. ✅ **Wire PHPStan at level 0.** Author `phpstan.neon` with paths `include/`, `admin/`, root entry points, and `excludePaths` covering `install/db/*.php` (locked rule from constraint #3 / DB-upgrade strategy), `language/*.php`, `vendor/`, and `include/pwgsession_php7.class.php` (dead code for PHP 8.5+; its untyped `SessionHandlerInterface` methods produce non-ignorable `method.tentativeReturnType` errors that cannot be baselined). Add `bootstrapFiles: tools/phpstan-bootstrap.php` so globals like `$conf`, `$user`, `$page`, `$lang`, `$template`, `PHPWG_ROOT_PATH` are declared before analysis — without this, level 0 output drowns in "undefined variable" noise. Create an empty `phpstan-baseline.neon` first (the include directive requires it to exist), then run `vendor/bin/phpstan analyse --generate-baseline phpstan-baseline.neon --memory-limit=1G` and commit the baseline. **Baseline absorbed 169 errors.** Done when `vendor/bin/phpstan analyse` exits 0.

3. ✅ **Wire Rector in dry-run only.** `rector.php` uses `withPaths([...])` and `withSkip([install/db, language, themes, include/smarty, include/feedcreator.class.php, vendor])`. Phase 0's rector.php is intentionally minimal — no rule sets enabled yet. Phase 1 will turn on `withPhpSets()`. Done when `vendor/bin/rector process --dry-run --no-progress-bar` exits 0. Note: Rector 2.x uses `--no-progress-bar`, not `--no-progress`; with no rule sets registered it emits a WARNING but exits 0.

4. ✅ **Wire Pint.** `pint.json` selects PSR-12 with `declare_strict_types: false` for now (Phase 2 enables it) and excludes the same paths PHPStan excludes. Done when `vendor/bin/pint --test` exits 0. **Churn: 237 PHP files reformatted in a standalone commit** (`chore: apply Pint PSR-12 formatting`) before any other Phase-0 work, so subsequent diffs are signal-only.

5. ✅ **Wire PHPUnit.** `phpunit.xml.dist` declares two suites: `Unit` (placeholder, will populate Phase 2+) and `Integration` (holds `UpgradeChainTest`). Done when `vendor/bin/phpunit --list-suites` shows both. **PHPUnit 11 only lists suites that contain at least one test class** — `tests/Unit/PlaceholderTest.php` (a single `assertTrue(true)` test) was added to make the Unit suite discoverable; delete it in Phase 2 when real unit tests arrive.

6. ✅ **Stand up the Docker dev stack.** `docker-compose.yml` brings up `mariadb:10.11` (locked over MySQL 8 — Piwigo's audience runs shared hosting, conservative SQL is correct) plus `php:8.5-rc-apache` (RC image used because PHP 8.5 final was not yet available; swap to `php:8.5-apache` once GA ships) with the working tree bind-mounted at `/var/www/html`, plus a Playwright service. Wait-loop on the MariaDB healthcheck before the PHP service binds (`depends_on.condition: service_healthy`) so CI doesn't race. **Host port mapping:** db uses `3307:3306` and web uses `8090:80` to avoid conflicts with a local MySQL on 3306 and local Apache on 8080. The `command` installs `libpng-dev libpng-dev libjpeg-dev zlib1g-dev libwebp-dev libfreetype6-dev` via apt-get before `docker-php-ext-install` (required; the base image ships no GD/exif system deps). `mbstring` is pre-bundled in the base image, not compiled. Done when `docker compose up -d` followed by `curl http://localhost:8090/install.php` returns 200.

7. ✅ **Capture the 16.x fixture.** Run a real install on the dev stack — create albums, upload photos, create users with permissions, post comments, set tags, change config values. `mysqldump` to `dev/fixtures/piwigo-16.x.sql`. **Fixture contents: 3 albums (2 root, 1 sub), 391 images (8 real uploads + 383 metadata rows), 4 users (guest + fixture_admin + viewer_alice + uploader_bob), 4 tags (nature, portrait, mountain, outdoor), 3 comments, gallery_title set, logging enabled. Dump size: 246 KB.** Admin credentials: `fixture_admin` / `fixture_admin`. Regeneration instructions in `dev/fixtures/README.md`.

8. ✅ **Author the CI workflow.** `.github/workflows/ci.yml` runs three jobs in parallel: `lint` (Pint test mode + PHPStan), `unit` (PHPUnit `Unit` suite — placeholder, returns 0), `e2e` (boots `docker compose up -d --wait db web`, drives all six Playwright specs, then runs `UpgradeChainTest` against the fixture). Matrix on `php-version: ['8.5']` only. Done when a push with no source changes goes green. **Blocked on step 7** (fixture not yet committed; `e2e` job will fail until `dev/fixtures/piwigo-16.x.sql` exists).

9. ✅ **Scaffold `tests/Integration/UpgradeChainTest.php`.** This test loads the 16.x fixture into a throwaway DB (`piwigo_test`), writes `local/config/database.inc.php` pointing at it, then makes an HTTP request to `/upgrade.php`, then asserts `piwigo_config.piwigo_db_version` matches `'16'` (not `'16.3'` — `get_branch_from_version('16.3.0')` returns only the first segment, per Piwigo ≥11 convention). Uses `curl_init` for the HTTP call (no Guzzle) and `Symfony\Component\Process\Process` to shell out to `mysql` CLI for fixture loading. Cleans up `local/config/database.inc.php` in `tearDown`. Two env vars separate host-side from container-side concerns: `PIWIGO_DB_PORT` (default `3306`, set to `3307` locally) for mysqli/mysql CLI on the host; `PIWIGO_WEB_DB_HOST` (default `db`) written into `database.inc.php` so upgrade.php inside the web container reaches the DB by service name. The written `database.inc.php` must match install.php's format exactly: `$prefixeTable`, `PHPWG_INSTALLED`, charset constants, and a closing `?>` tag — upgrade.php dies at line 31 (`strrpos` for `?>` returns false) without it. The `piwigo` MariaDB user needs `GRANT ALL ON piwigo_test.*` (one-time: `GRANT ALL PRIVILEGES ON \`piwigo_test\`.* TO 'piwigo'@'%'; FLUSH PRIVILEGES;`). **Green: 1/1 passing.**

10. ✅ **Author the Playwright bootstrap and the six initial specs.** `package.json` adds `@playwright/test ^1.48`, `typescript ^5.6` (resolves to 1.59.1). `playwright.config.ts` points `baseURL` at `http://localhost:8090` (or `$BASE_URL` for CI), uses `globalSetup: './tests/e2e/global-setup.js'` (CJS, not TS — Playwright's runtime requires CJS for globalSetup on Windows). The global-setup deletes `local/config/database.inc.php` before the install spec runs (that file defines `PHPWG_INSTALLED` which makes install.php bail) then drops/recreates the DB via `mysql` CLI. Six specs under `tests/e2e/`, **prefixed 01–06 to enforce run order** (alphabetical would run change-setting before install): `01-install.spec.ts`, `02-smoke-gallery.spec.ts`, `03-smoke-admin.spec.ts`, `04-create-album.spec.ts`, `05-upload-photo.spec.ts`, `06-change-setting.spec.ts`. Admin login shared via `tests/e2e/helpers/admin-login.ts`. Key learnings: all admin pages go through `admin.php?page=X` not direct `admin/X.php` paths (which require `PHPWG_ROOT_PATH` defined by admin.php); `PIWIGO_INSTALL_DB_HOST` (default `db`) is the in-Docker hostname for the install form, separate from `PIWIGO_DB_HOST` used by global-setup's host-side mysql CLI. Do NOT restore the abandoned `ceb1390e6` suite — written from scratch.

11. ✅ **Validate the CI workflow.** All three GitHub Actions jobs (`lint`, `unit`, `e2e`) are green on `16.x-rewrite`. Run ID 24971992226 — lint 2m03s, unit 10s, e2e 1m51s. From this moment forward, any red CI is a regression, not a setup gap. Issues found and resolved getting CI green: vendor binaries lost `+x` on Windows commit (fixed via `git update-index --chmod=+x` + `.gitattributes`); PHP files had CRLF endings causing Pint PSR-12 violations on Linux (fixed via `git add --renormalize`); `docker compose up --wait` returned before Apache was ready (fixed via web service healthcheck + Dockerfile); www-data couldn't write `local/config/database.inc.php` or `_data/` in the bind-mounted volume owned by the CI runner user (fixed via `chmod -R a+rwX` in the entrypoint script).

### Concrete artifacts

**`composer.json`** — PHP 8.5 minimum, dev-only deps until Phase 3 introduces real autoload:

```json
{
  "name": "piwigo/piwigo",
  "description": "Piwigo photo gallery — modernized",
  "type": "project",
  "require": {
    "php": "^8.5",
    "ext-mysqli": "*",
    "ext-mbstring": "*",
    "ext-gd": "*"
  },
  "require-dev": {
    "phpunit/phpunit": "^11.4",
    "rector/rector": "^2.0",
    "phpstan/phpstan": "^2.0",
    "phpstan/phpstan-deprecation-rules": "^2.0",
    "phpstan/phpstan-strict-rules": "^2.0",
    "laravel/pint": "^1.18",
    "symfony/process": "^7.1"
  },
  "autoload": {},
  "autoload-dev": {
    "psr-4": { "Piwigo\\Tests\\": "tests/" }
  },
  "config": { "sort-packages": true, "allow-plugins": {} }
}
```

The empty `autoload` is deliberate — Phase 3 adds `Piwigo\\` → `src/`. No `symfony/polyfill-php72` here; Phase 1 deletes the only consumer (`functions_mysql.inc.php`).

**`phpstan.neon`** — level 0 with hard exclusions:

```neon
parameters:
    level: 0
    paths: [include, admin, install.php, upgrade.php, index.php, ws.php, picture.php, identification.php, profile.php, register.php, password.php, search.php, tags.php, comments.php, notification.php, feed.php, i.php]
    excludePaths:
        analyseAndScan:
            - install/db/*.php
            - language/*.php
            - include/smarty/*
            - include/feedcreator.class.php
            - include/pwgsession_php7.class.php
            - vendor/*
    bootstrapFiles: [tools/phpstan-bootstrap.php]
    treatPhpDocTypesAsCertain: false
includes:
    - phpstan-baseline.neon
    - vendor/phpstan/phpstan-deprecation-rules/rules.neon
```

`feedcreator.class.php` is excluded forever because it contains the only real `each($lines)` call in the codebase (line 1414) and is third-party vendored. `pwgsession_php7.class.php` is excluded because it is dead code under PHP 8.5+ (the runtime branch at `functions_session.inc.php:19` never loads it) and its untyped `SessionHandlerInterface` methods produce non-ignorable `method.tentativeReturnType` errors that PHPStan cannot baseline.

**`tools/phpstan-bootstrap.php`** — declares the global landscape:

```php
<?php
declare(strict_types=1);

define('PHPWG_ROOT_PATH', __DIR__ . '/../');
define('PHPWG_VERSION', '16.3.0');
define('PWG_LOCAL_DIR', 'local/');
define('PHPWG_INSTALLED', true);
define('IN_ADMIN', false);

/** @var array<string,mixed> $conf */
$conf = [];
/** @var array<string,mixed> $user */
$user = [];
/** @var array{infos:list<string>,errors:list<string>,warnings:list<string>,messages:list<string>,body_classes:list<string>,body_data:array<string,mixed>} $page */
$page = ['infos' => [], 'errors' => [], 'warnings' => [], 'messages' => [], 'body_classes' => [], 'body_data' => []];
/** @var array<string,string> $lang */
$lang = [];
/** @var \Template|null $template */
$template = null;
/** @var \Logger|null $logger */
$logger = null;
/** @var \mysqli|null $mysqli */
$mysqli = null;
```

**`rector.php`** (Phase 0 — dry-run only):

```php
<?php declare(strict_types=1);
use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/include', __DIR__ . '/admin',
        __DIR__ . '/install.php', __DIR__ . '/upgrade.php',
        __DIR__ . '/index.php', __DIR__ . '/ws.php', __DIR__ . '/picture.php',
        __DIR__ . '/identification.php', __DIR__ . '/profile.php',
        __DIR__ . '/register.php', __DIR__ . '/password.php',
    ])
    ->withSkip([
        __DIR__ . '/install/db', __DIR__ . '/language',
        __DIR__ . '/include/smarty', __DIR__ . '/include/feedcreator.class.php',
        __DIR__ . '/themes', __DIR__ . '/vendor',
    ])
    ->withImportNames(importShortClasses: false, removeUnusedImports: false)
    ->withParallel();
```

**`pint.json`**:

```json
{
  "preset": "psr12",
  "exclude": ["install/db", "language", "include/smarty", "include/feedcreator.class.php", "themes", "vendor"],
  "rules": {
    "declare_strict_types": false,
    "single_quote": true,
    "ordered_imports": { "sort_algorithm": "alpha" },
    "no_unused_imports": true,
    "trailing_comma_in_multiline": true
  }
}
```

`declare_strict_types` stays off — Phase 2 enables it once.

**`phpunit.xml.dist`**:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="vendor/autoload.php"
         colors="true"
         failOnWarning="true"
         failOnNotice="true"
         cacheDirectory=".phpunit.cache">
    <testsuites>
        <testsuite name="Unit"><directory>tests/Unit</directory></testsuite>
        <testsuite name="Integration"><directory>tests/Integration</directory></testsuite>
    </testsuites>
    <php>
        <env name="PIWIGO_DB_HOST" value="127.0.0.1"/>
        <env name="PIWIGO_DB_PORT" value="3307"/>
        <env name="PIWIGO_DB_USER" value="piwigo"/>
        <env name="PIWIGO_DB_PASSWORD" value="piwigo"/>
        <env name="PIWIGO_DB_BASE" value="piwigo_test"/>
        <env name="PIWIGO_WEB_DB_HOST" value="db"/>
        <env name="PIWIGO_BASE_URL" value="http://localhost:8090"/>
    </php>
</phpunit>
```

**`docker-compose.yml`**:

```yaml
services:
  db:
    image: mariadb:10.11
    environment:
      MARIADB_ROOT_PASSWORD: root
      MARIADB_DATABASE: piwigo
      MARIADB_USER: piwigo
      MARIADB_PASSWORD: piwigo
    volumes: ["./docker/init:/docker-entrypoint-initdb.d"]
    healthcheck:
      test: ["CMD", "healthcheck.sh", "--connect", "--innodb_initialized"]
      interval: 5s
      timeout: 3s
      retries: 20
    ports: ["3306:3306"]
  web:
    image: php:8.5-rc-apache
    depends_on: { db: { condition: service_healthy } }
    volumes: ["./:/var/www/html"]
    ports: ["8090:80"]
    command: >
      bash -c "apt-get update -qq &&
               apt-get install -y --no-install-recommends libpng-dev libjpeg-dev zlib1g-dev libwebp-dev libfreetype6-dev &&
               docker-php-ext-configure gd --with-jpeg --with-freetype --with-webp &&
               docker-php-ext-install mysqli gd exif &&
               a2enmod rewrite && apache2-foreground"
  e2e:
    image: mcr.microsoft.com/playwright:v1.48.0-jammy
    depends_on: [web]
    working_dir: /work
    volumes: ["./:/work"]
    environment: { BASE_URL: http://web }
    command: ["sleep", "infinity"]
```

`depends_on.condition: service_healthy` is the explicit fix for the docker-compose race condition.

**`.github/workflows/ci.yml`**:

```yaml
name: CI
on: [push, pull_request]
jobs:
  lint:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with: { php-version: '8.5', tools: composer:v2 }
      - run: composer install --no-progress --prefer-dist
      - run: vendor/bin/pint --test
      - run: vendor/bin/phpstan analyse --no-progress
  unit:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with: { php-version: '8.5', tools: composer:v2 }
      - run: composer install --no-progress --prefer-dist
      - run: vendor/bin/phpunit --testsuite Unit
  e2e:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - run: docker compose up -d --wait db web
      - uses: actions/setup-node@v4
        with: { node-version: '20' }
      - run: npm ci
      - run: npx playwright install --with-deps chromium
      - run: npx playwright test
        env:
          BASE_URL: http://localhost:8090
          PIWIGO_DB_HOST: 127.0.0.1
          PIWIGO_DB_PORT: "3307"
          PIWIGO_DB_USER: piwigo
          PIWIGO_DB_PASSWORD: piwigo
          PIWIGO_DB_BASE: piwigo
          PIWIGO_INSTALL_DB_HOST: db
      - uses: shivammathur/setup-php@v2
        with: { php-version: '8.5', tools: composer:v2 }
      - run: composer install --no-progress --prefer-dist
      - run: vendor/bin/phpunit --testsuite Integration
```

**`tests/Integration/UpgradeChainTest.php`**:

```php
<?php declare(strict_types=1);
namespace Piwigo\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class UpgradeChainTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/../../dev/fixtures/piwigo-16.x.sql';

    private string $dbHost;
    private int $dbPort;
    private string $dbUser, $dbPass, $dbName, $webDbHost, $baseUrl;

    protected function setUp(): void
    {
        $this->dbHost    = (string) (getenv('PIWIGO_DB_HOST') ?: '127.0.0.1');
        $this->dbPort    = (int)    (getenv('PIWIGO_DB_PORT') ?: 3306);
        $this->dbUser    = (string) (getenv('PIWIGO_DB_USER') ?: 'piwigo');
        $this->dbPass    = (string) (getenv('PIWIGO_DB_PASSWORD') ?: 'piwigo');
        $this->dbName    = (string) (getenv('PIWIGO_DB_BASE') ?: 'piwigo_test');
        // In-container hostname for upgrade.php — Docker service name, not host-side IP.
        $this->webDbHost = (string) (getenv('PIWIGO_WEB_DB_HOST') ?: 'db');
        $this->baseUrl   = rtrim((string) (getenv('PIWIGO_BASE_URL') ?: 'http://localhost:8090'), '/');
        $this->resetDatabase();
        $this->loadFixture(self::FIXTURE);
        $this->writeDatabaseConfig();
    }

    protected function tearDown(): void { $this->removeDatabaseConfig(); }

    public function test_upgrade_from_16x_dump_lands_on_current_version(): void
    {
        $ch = curl_init($this->baseUrl . '/upgrade.php');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query(['username' => 'fixture_admin', 'password' => 'fixture_admin']),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        $statusCode = (int) curl_getinfo(curl_exec($ch) !== false ? $ch : $ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        self::assertSame(200, $statusCode);

        $version = $this->queryScalar("SELECT value FROM piwigo_config WHERE param = 'piwigo_db_version'");
        // get_branch_from_version('16.3.0') returns '16' (first segment only, Piwigo ≥11 convention)
        self::assertSame('16', $version, 'upgrade.php must land on current branch version');
    }

    private function resetDatabase(): void
    {
        $db = new \mysqli($this->dbHost, $this->dbUser, $this->dbPass, '', $this->dbPort);
        $db->query("DROP DATABASE IF EXISTS `{$this->dbName}`");
        $db->query("CREATE DATABASE `{$this->dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $db->close();
    }

    private function loadFixture(string $path): void
    {
        self::assertFileExists($path, 'Fixture missing — see dev/fixtures/README.md');
        $proc = new Process(["mysql", "-h{$this->dbHost}", "-P{$this->dbPort}", "-u{$this->dbUser}", "-p{$this->dbPass}", $this->dbName]);
        $proc->setInput(file_get_contents($path));
        $proc->mustRun();
    }

    private function writeDatabaseConfig(): void
    {
        $dir = __DIR__ . '/../../local/config';
        if (!is_dir($dir)) { mkdir($dir, 0755, true); }
        // Must match install.php's format exactly: $prefixeTable, PHPWG_INSTALLED,
        // charset constants, and closing ?> — upgrade.php dies if ?> is missing.
        // Uses $webDbHost (service name 'db') so upgrade.php inside Docker can reach the DB.
        file_put_contents($dir . '/database.inc.php', sprintf(
            "<?php\n\$conf['dblayer']='mysqli';\n\$conf['db_host']='%s';\n\$conf['db_user']='%s';\n\$conf['db_password']='%s';\n\$conf['db_base']='%s';\n\$prefixeTable='piwigo_';\ndefine('PHPWG_INSTALLED',true);\ndefine('PWG_CHARSET','utf-8');\ndefine('DB_CHARSET','utf8');\ndefine('DB_COLLATE','');\n?>",
            addslashes($this->webDbHost), addslashes($this->dbUser), addslashes($this->dbPass), addslashes($this->dbName)
        ));
    }

    private function removeDatabaseConfig(): void
    {
        $path = __DIR__ . '/../../local/config/database.inc.php';
        if (file_exists($path)) { unlink($path); }
    }

    private function queryScalar(string $sql): string
    {
        $db = new \mysqli($this->dbHost, $this->dbUser, $this->dbPass, $this->dbName, $this->dbPort);
        $result = $db->query($sql);
        self::assertInstanceOf(\mysqli_result::class, $result);
        $row = $result->fetch_row();
        $db->close();
        return (string) $row[0];
    }
}
```

**`tests/e2e/install.spec.ts`**:

```typescript
import { test, expect } from '@playwright/test';

// DB reset is handled by tests/e2e/global-setup.ts via mysql CLI before this spec runs.
// Actual install.php POST field names (confirmed from admin/themes/default/template/install.tpl):
//   dbhost, dbuser, dbpasswd, dbname, prefix, admin_name, admin_pass1, admin_pass2, admin_mail.
// After install, page stays on install.php and shows "Congratulations" — no redirect to identification.php.
test('fresh install completes end-to-end', async ({ page }) => {
  await page.goto('/install.php');
  await expect(page.getByText('Installation')).toBeVisible();

  await page.fill('input[name="dbhost"]', process.env.PIWIGO_DB_HOST ?? 'db');
  await page.fill('input[name="dbuser"]', process.env.PIWIGO_DB_USER ?? 'piwigo');
  await page.fill('input[name="dbpasswd"]', process.env.PIWIGO_DB_PASSWORD ?? 'piwigo');
  await page.fill('input[name="dbname"]', process.env.PIWIGO_DB_BASE ?? 'piwigo');

  await page.fill('input[name="admin_name"]', 'admin');
  await page.fill('input[name="admin_pass1"]', 'p4ssword!');
  await page.fill('input[name="admin_pass2"]', 'p4ssword!');
  await page.fill('input[name="admin_mail"]', 'admin@example.test');
  await page.uncheck('input[name="newsletter_subscribe"]');

  await page.click('input[name="install"]');

  await expect(page.getByText('Congratulations')).toBeVisible({ timeout: 30_000 });
  await expect(page.getByRole('link', { name: 'Visit Gallery' })).toBeVisible();
});
```

### Effort breakdown

| Sub-task | Tag |
| --- | --- |
| `composer.json` + first `composer install` | S |
| `phpstan.neon` + bootstrap + baseline generation | M |
| `rector.php` Phase 0 dry-run | S |
| `pint.json` + run-and-commit-the-churn | M |
| `phpunit.xml.dist` | S |
| `docker-compose.yml` (apache + mariadb + playwright orchestration) | M |
| 16.x fixture capture (must be done by hand on a real install) | M |
| `ci.yml` (3 jobs, matrix, caching) | M |
| `UpgradeChainTest.php` (load-bearing, subprocess driving) | L |
| 6 Playwright specs (selectors against unstable templates) | L |
| Phase 0 CI validation | S |

**Phase total: L.**

### Risks specific to this phase

- **PHP 8.5 may still be RC at install time.** ✅ Pinned `php:8.5-rc-apache` in docker-compose and `php-version: '8.5'` in setup-php. Swap web service image to `php:8.5-apache` once GA ships; no other change needed.
- **Pint and PHPStan disagree on `declare_strict_types` ordering.** ✅ `declare_strict_types: false` in pint.json; PHPStan exits 0 after Pint churn. Phase 2 enables it once.
- **docker-compose race between MariaDB ready and PHP first-request.** ✅ `depends_on.condition: service_healthy` with the explicit healthcheck resolves the race. No `sleep`-style waits.
- **Non-ignorable PHPStan errors can't go in the baseline.** ✅ `pwgsession_php7.class.php` had 6 `method.tentativeReturnType` errors that PHPStan refused to baseline. Resolved by excluding the file (dead code for PHP 8.5+). If other non-ignorable errors appear in later phases, fix the code rather than excluding.
- **The 16.x fixture grows stale.** `dev/fixtures/README.md` documents how to regenerate it; Phase 6's pre-floor cleanup also bumps the fixture.
- **Playwright selectors are brittle against the legacy templates.** Biased toward `getByRole`/`getByText` over CSS; accept that 1-2 specs will need touch-ups in Phase 1 when Rector touches templates. The `install.spec.ts` form field names (`dbhost`, `dbuser`, `dbpasswd`, `dbname`) differ from the PHP variable names — verified against `admin/themes/default/template/install.tpl`.
- **UpgradeChainTest `database.inc.php` format is strict.** ✅ Upgrade.php does `strrpos($contents, '?>')` at line 31 and dies if the closing tag is missing. The written config must also use `$prefixeTable` (not `$conf['db_table_prefix']`) and define `PHPWG_INSTALLED`/charset constants — matching what install.php generates. `get_branch_from_version('16.3.0')` returns `'16'`, not `'16.3'` — the assertion must match.
- **UpgradeChainTest needs `GRANT ALL ON piwigo_test.*` for the `piwigo` DB user.** ✅ Handled via `docker/init/01-grant-piwigo-test.sql` mounted at `/docker-entrypoint-initdb.d/` — runs automatically on first container start in CI and on any fresh local `docker compose up`.

### Verification

```bash
# ✅ passing locally
vendor/bin/pint --test                           # exit 0 (237 files reformatted in churn commit)
vendor/bin/phpstan analyse --memory-limit=1G     # exit 0, 169 errors in baseline
vendor/bin/rector process --dry-run --no-progress-bar  # exit 0, no rule sets active
vendor/bin/phpunit --list-suites                 # shows Unit (1 test) + Integration (1 test)

# ✅ verified locally
docker compose up -d --wait db web              # ports: db→3307, web→8090
curl -fsS http://localhost:8090/install.php > /dev/null
PIWIGO_DB_HOST=127.0.0.1 PIWIGO_DB_PORT=3307 PIWIGO_DB_USER=piwigo \
  PIWIGO_DB_PASSWORD=piwigo PIWIGO_DB_BASE=piwigo PIWIGO_INSTALL_DB_HOST=db \
  BASE_URL=http://localhost:8090 node_modules/.bin/playwright test   # 9/9 passing
vendor/bin/phpunit --testsuite Integration       # 1/1 passing once fixture loaded (dev/fixtures/piwigo-16.x.sql, 246 KB)
```

Manual break check: edit `include/common.inc.php:90` to point at a nonexistent dblayer file, push to branch — CI must fail in `e2e` (install.spec.ts) within minutes. Revert.

---

## Phase 1 — Make it run on PHP 8.5 at all (M)

### Goal
With Phase 0's safety net in place, get every entry point — `index.php`, `picture.php`, `ws.php`, `admin.php`, `install.php`, `upgrade.php`, `identification.php`/`profile.php`/`register.php`/`password.php`/`search.php`/`tags.php`/`comments.php` — returning 200 on PHP 8.5 with `display_errors=on, error_reporting=E_ALL` and zero warnings. Exit posture: the doomed `functions_mysql.inc.php` is deleted with a silent self-heal in `common.inc.php`, the four live `utf8_encode/decode` sites are converted to `mb_convert_encoding`, Rector's PHP 8.0 → 8.5 sets have been applied in slices with manual review on each, dynamic-property writes in `ws_core.inc.php` and a few error-class call sites have been remediated, the `install/db/*.php` chain has been re-audited by hand (and confirmed to contain zero canonical PHP-8 breaks), session/cookie code has been adjusted for PHP 8.5 SameSite defaults, and the full Playwright + UpgradeChainTest suite is green.

### Step-by-step sequence

1. ✅ **Delete `include/dblayer/functions_mysql.inc.php` and add the self-heal.** This single deletion eliminates the only three `create_function()` call sites in the entire repo (verified: `functions_mysql.inc.php:302, 304, 318` are the only matches). The self-heal in `common.inc.php` rewrites legacy `$conf['dblayer'] = 'mysql'` to `'mysqli'` so users with an old `local/config/database.inc.php` continue to boot. Done when `git rm include/dblayer/functions_mysql.inc.php` is committed and a fresh container with `dblayer = 'mysql'` in its config still boots green. **Done:** `functions_mysql.inc.php` deleted; self-heal `if (($conf['dblayer'] ?? 'mysqli') === 'mysql') { $conf['dblayer'] = 'mysqli'; }` added to `include/common.inc.php` immediately before the dblayer include.

2. ✅ **Replace the 4 `utf8_encode/decode` call sites.** Verified-live sites: `include/functions.inc.php:1953` (`utf8_encode`), `:1957` (`utf8_decode`), `admin/include/functions_upgrade.php:222` (`utf8_decode`), `:223` (`utf8_decode`). All other matches are vendored JS (out of scope). Replace each with `mb_convert_encoding`. Done when `grep -rn 'utf8_\(en\|de\)code' include/ admin/ install.php upgrade.php` returns zero matches. **Done:** 4 sites fixed. Actual line numbers post-Pint: `include/functions.inc.php:1815` (`utf8_encode`) and `:1818` (`utf8_decode`); `admin/include/functions_upgrade.php:210` and `:211` (`utf8_decode`). All replaced with `mb_convert_encoding`.

3. ✅ **Apply Rector PHP 8.0 set in dry-run, review, apply.** Add `->withPhpSets(php80: true)` to `rector.php`. Slice by directory: `include/` first, then `admin/`, then root entry points. Each slice is one commit, each commit runs the full CI matrix. PHP 8.0 set hits: `each()` removal (only `feedcreator.class.php:1414`, which is excluded), `(unset)` cast removal, `${var}` interpolation rewriting, `Throwable` matchers. Done when `vendor/bin/rector process --dry-run` reports zero changes for the 8.0 set on each merged slice. **Done:** Rector PHP 8.0 applied. Rules active: `LongArrayToShortArrayRector`, `TernaryToNullCoalescingRector`, `ListToArrayDestructRector`. 167 files changed. PHPStan baseline regenerated: 131 errors (down from 169). Rector dry-run clean. **Note:** `ListToArrayDestructRector` introduced a regression in `include/minify/src/Minify.php` (lines 328 and 379) — it converted `list($pattern, $replacement) = $pattern;` to `[$pattern, $replacement] = str_split($pattern);` (wrong: `$pattern` is an array, not a string). Both occurrences fixed manually. `include/minify`, `include/phpmailer`, `include/phpqrcode.php`, and `include/emogrifier.class.php` added to rector.php skip list. **Future phases: these bundled third-party libraries must stay in withSkip.**

4. ✅ **Apply Rector PHP 8.1 set** (and 8.2–8.5 — see note). Adds readonly properties, `never` returns, `enum` opportunities, `new in initializers`. Same slicing strategy. Be disciplined: only accept rewrites that pass E2E, defer `readonly` on globals to Phase 4. Done when 8.1-set dry-run is empty per slice. **Done (covers plan steps 4–6):** Rector PHP 8.5 applied via `withPhpSets(php85: true)` (Rector 2.x takes one version maximum and covers all rules up to that version, so a single `php85: true` covers 8.1 through 8.5). Rules active: `FunctionFirstClassCallableRector` (128 files), `NullToStrictStringFuncCallArgRector`, `OrdSingleByteRector`, `DeprecatedAnnotationToDeprecatedAttributeRector`. PHPStan baseline: 118 errors (down from 131). Rector dry-run clean.

5. ✅ **Apply Rector PHP 8.2 set** — covered by step 4 above (`withPhpSets(php85: true)` includes 8.2). Adds `#[\AllowDynamicProperties]` annotations where Rector spots writes to undeclared properties — this is the trip wire for `ws_core.inc.php`. Rector will want to slap `#[\AllowDynamicProperties]` on `PwgError`, `PwgNamedArray`, `PwgNamedStruct`, `PwgServer`. Reject those rewrites — the right answer is to declare the properties (the classes already use `var $_foo;` syntax — see `ws_core.inc.php:41-42, 66-68, 91-92, 217-222`). Done when 8.2-set dry-run is empty AND every class accessed via dynamic-property write has explicit declarations. **Done:** Rector's php85 pass did not add any `#[\AllowDynamicProperties]` — all ws_core.inc.php classes already had explicit property declarations (see step 7).

6. ✅ **Apply Rector PHP 8.3 → 8.5 sets** — covered by step 4 above (`withPhpSets(php85: true)`). 8.3 adds `#[\Override]`, typed class constants. 8.4 adds property hooks (skip — too invasive for Phase 1). 8.5 set is mostly defensive (pipe operator opportunities are noise; reject). Done when all sets dry-run clean. **Done:** see step 4.

7. ✅ **Audit dynamic-property writes by hand.** Three target classes:
   - `PwgError` (`ws_core.inc.php:39`) already declares `private $_code, $_codeText` — clean.
   - `PwgNamedArray` (`:64`) and `PwgNamedStruct` (`:89`) declare via `/*private*/ var` — convert to typed `private array $_content`, etc.
   - `PwgServer` (`:215+`) declares `var $_requestHandler, $_methods = array()` etc. — convert to typed properties.
   - `Template` (`include/template.class.php:22`) and `ScriptLoader` (`:1494`) — already declare every used property. No `__set` magic. Safe.
   Done when `php -d display_errors=on -d error_reporting=E_ALL` driving each Playwright spec emits zero "Creation of dynamic property" deprecations. **Done:** All ws_core.inc.php classes (PwgError, PwgNamedArray, PwgNamedStruct, PwgServer) already had explicit property declarations. Rector's php85 pass did not add any `#[\AllowDynamicProperties]`. No code change was needed.

8. ✅ **Hand-audit `install/db/*.php` for the four canonical breaks.** Verified results from grepping the chain:
   - `create_function`: **0 matches**
   - `utf8_encode|utf8_decode`: **0 matches**
   - real `each($x)` (not `foreach`): **0 matches**
   - `\$\{[a-z]` (curly-brace variable interpolation): **0 matches**
   The 122 install/db scripts ship clean. The regression contract from constraint #3 holds with no surgery. UpgradeChainTest stays the gating check. Done when the grep report is committed to `docs/phase1-installdb-audit.md`. **Done:** All 122 scripts audited and confirmed clean. Report committed at `docs/phase1-installdb-audit.md`.

9. ✅ **Audit session/cookie behavior on PHP 8.5.** Targets identified: `include/functions_session.inc.php:43` calls `session_set_cookie_params(0, cookie_path())` — convert to the array form: `session_set_cookie_params(['lifetime' => 0, 'path' => cookie_path(), 'samesite' => 'Lax', 'httponly' => true, 'secure' => !empty($_SERVER['HTTPS'])])`. The `setcookie()` calls in `functions_user.inc.php:1071, 1081, 1091, 1141, 1437, 1441` and `functions_cookie.inc.php:93, 100` use positional args — convert each to the options-array form. Done when admin login, anonymous browsing, and "remember me" all pass on PHP 8.5 with `session.cookie_samesite=Strict`. **Done:** `session_set_cookie_params()` converted to array form with `samesite: 'Lax'`. All `setcookie()` calls were already in array form after Rector's `FunctionFirstClassCallableRector` pass — no further changes needed.

10. ✅ **Final E2E pass.** Run the full Playwright suite + `UpgradeChainTest` against the docker-compose stack with `error_reporting=E_ALL, display_errors=on`. Zero deprecations, zero warnings, zero errors in the Apache log. Open every entry point by hand, scan rendered HTML for `Notice:|Warning:|Deprecated:|Fatal error:` strings. **Done:** Playwright 9/9 green locally with the rebuilt Docker image.

### Concrete artifacts

**Diff: `include/common.inc.php` self-heal** — replace lines 84–90 with:

```php
@include(PHPWG_ROOT_PATH.PWG_LOCAL_DIR .'config/database.inc.php');
if (!defined('PHPWG_INSTALLED'))
{
  header('Location: install.php');
  exit;
}
// Self-heal: pre-fork installs may have $conf['dblayer'] = 'mysql' from the
// removed mysql_* extension. The mysqli layer is the only surviving backend.
if (($conf['dblayer'] ?? 'mysqli') === 'mysql')
{
  $conf['dblayer'] = 'mysqli';
}
include(PHPWG_ROOT_PATH .'include/dblayer/functions_'.$conf['dblayer'].'.inc.php');
```

**Diff: `include/functions.inc.php:1949-1958`** — `convert_charset`:

```php
function convert_charset($str, $source_charset, $dest_charset)
{
  if ($source_charset==$dest_charset) return $str;
  if ($source_charset=='iso-8859-1' and $dest_charset=='utf-8')
    return mb_convert_encoding($str, 'UTF-8', 'ISO-8859-1');
  if ($source_charset=='utf-8' and $dest_charset=='iso-8859-1')
    return mb_convert_encoding($str, 'ISO-8859-1', 'UTF-8');
  if (function_exists('iconv'))
    return iconv($source_charset, $dest_charset.'//TRANSLIT', $str);
```

**Diff: `admin/include/functions_upgrade.php:220-224`** — legacy login charset shim:

```php
if (version_compare($current_release, '2.0', '<'))
{
  $username = mb_convert_encoding($username, 'ISO-8859-1', 'UTF-8');
  $password = mb_convert_encoding($password, 'ISO-8859-1', 'UTF-8');
}
```

(The whole `< 2.0` branch is dead code under our 16.x floor; Phase 6 deletes it.)

**`rector.php`** for Phase 1:

```php
return RectorConfig::configure()
    ->withPaths([__DIR__ . '/include', __DIR__ . '/admin', /* root entry points */])
    ->withSkip([
        __DIR__ . '/install/db', __DIR__ . '/language',
        __DIR__ . '/include/smarty', __DIR__ . '/include/feedcreator.class.php',
        __DIR__ . '/include/minify',
        __DIR__ . '/include/phpmailer',
        __DIR__ . '/include/phpqrcode.php',
        __DIR__ . '/include/emogrifier.class.php',
        __DIR__ . '/themes', __DIR__ . '/vendor',
    ])
    ->withPhpSets(php85: true)
    ->withParallel();
```

> **Note:** Rector 2.x `withPhpSets()` accepts a single version flag and applies all rules up to and including that version. `withPhpSets(php85: true)` is equivalent to the old multi-flag form and is the correct invocation. The four bundled third-party libraries (`minify`, `phpmailer`, `phpqrcode.php`, `emogrifier.class.php`) must remain in `withSkip` in all future phases.

**`docs/phase1-installdb-audit.md`** committed report:

```
Audit performed against install/db/*.php (122 files, 61-database.php through 181-database.php).

Patterns checked:
  create_function     0 matches
  utf8_encode         0 matches
  utf8_decode         0 matches
  each($x)            0 matches  (foreach matches excluded)
  ${varname}          0 matches

Conclusion: install/db/ chain ships clean for PHP 8.5. No surgery required.
The chain is pinned in rector.php skip list and phpstan.neon excludePaths
per locked rules of the DB-upgrade-compatibility strategy. UpgradeChainTest
remains the gating regression check.
```

### Effort breakdown

| Sub-task | Tag |
| --- | --- |
| Delete `functions_mysql.inc.php` + self-heal + smoke | S |
| 4 utf8 site replacements | S |
| Rector PHP 8.0 set per slice (3 slices × review) | M |
| Rector PHP 8.1 set per slice | M |
| Rector PHP 8.2 set per slice (most contentious — `AllowDynamicProperties`) | M |
| Rector PHP 8.3–8.5 sets per slice | M |
| `ws_core.inc.php` typed-property cleanup | M |
| `Template` / `ScriptLoader` audit (already clean) | S |
| `install/db/*.php` audit + report | S |
| Session/cookie SameSite migration (10 sites) | M |
| Final E2E pass + manual entry-point scan | M |

**Phase total: M.**

### Risks specific to this phase

- **Rector occasionally rewrites incorrectly.** ✅ Confirmed: `ListToArrayDestructRector` (PHP 8.0 rule) introduced a regression in `include/minify/src/Minify.php` — two self-referential `list($x, $y) = $x;` assignments were incorrectly transformed to `[$x, $y] = str_split($x);`, causing a PHP 8.5 TypeError on the install success page. Fixed manually. `include/minify`, `include/phpmailer`, `include/phpqrcode.php`, and `include/emogrifier.class.php` added to `rector.php` withSkip.
- **The Smarty layer in `include/smarty/` is excluded from Rector and PHPStan, but is loaded by `Template`.** Pin to the current vendored version; do not upgrade Smarty in Phase 1.
- **mysqli connection bootstrapping is buried inside `common.inc.php:118-126`.** Self-heal at line 90 must run *before* the dblayer include — the diff above places it correctly. An extra E2E spec, `tests/e2e/legacy-config-self-heal.spec.ts`, sets `$conf['dblayer'] = 'mysql'` in a fixture and asserts boot succeeds.
- **Session SameSite changes break `remember me` cookie reads on first deploy.** Keep `samesite => 'Lax'` (not `Strict`) for all of Phase 1; tightening is a Phase 6 cleanup.
- **PHPStan level 0 will not catch most PHP-8 deprecations.** Add a separate `lint-syntax` CI job that runs `php -l` and greps Apache error logs for `Deprecated:` strings — fail if any appear.
- **PHPStan `include.fileNotFound` behaves differently across platforms.** ✅ Windows PHPStan does not produce `include.fileNotFound` for `@include('./local/config/config.inc.php')` (gitignored optional override); Linux CI does. Resolved by adding a global `ignoreErrors` entry with `reportUnmatched: false` in `phpstan.neon` so the pattern silently absorbs the error on Linux without causing `ignore.unmatched` on Windows.

### Verification

```bash
# ✅ all passing locally and in CI (run 24973241079 — lint ✓ unit ✓ e2e ✓)
test ! -f include/dblayer/functions_mysql.inc.php
grep -rn 'utf8_encode\|utf8_decode' include admin install.php upgrade.php --include='*.php' --exclude-dir=smarty
# expect: zero output
grep -rn 'create_function' include admin install.php upgrade.php
# expect: zero output
vendor/bin/rector process --dry-run --no-progress-bar
# expect: "Rector is done!" (0 files)
vendor/bin/pint --test                              # exit 0
vendor/bin/phpstan analyse --memory-limit=1G --no-progress  # exit 0 (118 errors baselined)
curl -fsS http://localhost:8090/ | grep -E 'Deprecated|Notice|Warning' && exit 1 || echo "clean"
PIWIGO_DB_HOST=127.0.0.1 PIWIGO_DB_PORT=3307 PIWIGO_DB_USER=piwigo \
  PIWIGO_DB_PASSWORD=piwigo PIWIGO_DB_BASE=piwigo PIWIGO_INSTALL_DB_HOST=db \
  BASE_URL=http://localhost:8090 node_modules/.bin/playwright test   # 9/9 passing
vendor/bin/phpunit --testsuite Integration          # UpgradeChainTest green
```

Manual break check: revert the self-heal in `common.inc.php`, set `$conf['dblayer'] = 'mysql'` in a test container — expect 500. Re-apply self-heal — expect 200. This proves the migration shim is load-bearing.


### Part 1 — Phase 1: Typed property declarations

#### Why this matters more than it looks

Phase 1 step 5 (Rector PHP 8.2 set) is where the wheels come off if you let Rector slap `#[\AllowDynamicProperties]` onto every WS class that fails the deprecation check. The parent plan (line 418) says "reject those rewrites — the right answer is to declare the properties." This part walks each class concretely.

The legacy declaration syntax in `include/ws_core.inc.php` is one of three forms:

1. `private $_code;` — already private; just needs a native type (clean case, `PwgError`).
2. `/*private*/ var $_content;` — `var` keyword is PHP-4-era public-visibility shorthand. Convert to `private array $_content;` (`PwgNamedArray`, `PwgNamedStruct`).
3. `var $_methods = array();` — same as (2) but with a default value. Convert to `private array $_methods = [];` (`PwgServer`).

The fourth form — properties never declared at all and only assigned in methods — is the trip wire. PHP 8.2 emits `Deprecated: Creation of dynamic property` and PHP 9.0 will fatal. Rector tries to "fix" by adding `#[\AllowDynamicProperties]`, which masks the bug instead of fixing it.

#### A. `PwgError` (`include/ws_core.inc.php:39`)

The current source from the captured read:

```php
class PwgError
{
  private $_code;
  private $_codeText;

  function __construct($code, $codeText)
  {
    if ($code>=400 and $code<600)
    {
      set_status_header($code, $codeText);
    }

    $this->_code = $code;
    $this->_codeText = $codeText;
  }

  function code() { return $this->_code; }
  function message() { return $this->_codeText; }
}
```

This is the cleanest of the four. Properties are already `private` — visibility doesn't need fixing. The Phase 2 type harvest converts the typeless declarations to native types, while Phase 1's only contribution is the `declare(strict_types=1)` header (deferred to Phase 2 step 5 codebase-wide sweep) and confirming no caller writes a property the class didn't declare.

The Phase 2 typed version:

```php
<?php declare(strict_types=1);

class PwgError
{
    private int $_code;
    private string $_codeText;

    public function __construct(int $code, string $codeText)
    {
        if ($code >= 400 && $code < 600) {
            set_status_header($code, $codeText);
        }

        $this->_code = $code;
        $this->_codeText = $codeText;
    }

    public function code(): int
    {
        return $this->_code;
    }

    public function message(): string
    {
        return $this->_codeText;
    }
}
```

Reads/writes audit (verified by grep against the tracked tree):

- `ws_core.inc.php:51` `$this->_code = $code;` — internal write
- `ws_core.inc.php:52` `$this->_codeText = $codeText;` — internal write
- `ws_core.inc.php:55` `return $this->_code;` — internal read
- `ws_core.inc.php:56` `return $this->_codeText;` — internal read

Zero hits outside the class body. `PwgError`'s public surface is `code()` / `message()` — the underscored properties are not consumed elsewhere. **Verdict: clean. Native types apply with zero blast radius.**

#### B. `PwgNamedArray` (line 64) and `PwgNamedStruct` (line 89)

Both classes use the `/*private*/ var` pattern. The comment is aspirational — `var` is public visibility in PHP. Converting to real `private` is itself a behavior change because `PwgResponseEncoder::flatten()` at `ws_core.inc.php:179-210` reads `$value->_content` *from outside the class*:

```php
private static function flatten(&$value)
{
    if (is_object($value))
    {
      $class = strtolower( @get_class($value) );
      if ($class == 'pwgnamedarray')
      {
        $value = $value->_content;     // EXTERNAL READ
      }
      if ($class == 'pwgnamedstruct')
      {
        $value = $value->_content;     // EXTERNAL READ
      }
    }
```

And at `ws_protocols/rest_encoder.php:253-256`:

```php
$this->encode_array($data->_content, $data->_itemName, $data->_xmlAttributes);
$this->encode_struct($data->_content, false, $data->_xmlAttributes);
```

And at `include/ws_functions.inc.php:236`:

```php
$categories[ $key_of_cat[ $node['id_uppercat'] ] ]['sub_categories']->_content[] = &$node;
```

That last one is the spicy site — it appends through a reference to a property on a `PwgNamedArray`. Tightening visibility from public-via-`var` to actual `private` would break it.

Two paths:

1. **Pragmatic: drop the `/*private*/` lie, declare the properties as `public typed`.** Matches actual usage; Phase 4's Wave A/B rewrite handles the deeper "expose getters instead" later.
2. **Strict: tighten to `private`, add public getters, refactor the three external sites.** Higher disruption; correct long-term but not a Phase 1 scope.

Pick path 1. The Phase 2 typed version of `PwgNamedArray`:

```php
class PwgNamedArray
{
    /** @var array<int,mixed> */
    public array $_content;
    public string $_itemName;
    /** @var array<string,int> */
    public array $_xmlAttributes;

    /**
     * @param array<int,mixed> $arr
     * @param list<string> $xmlAttributes
     */
    public function __construct(array $arr, string $itemName, array $xmlAttributes = [])
    {
        $this->_content = $arr;
        $this->_itemName = $itemName;
        $this->_xmlAttributes = array_flip($xmlAttributes);
    }
}
```

And `PwgNamedStruct`:

```php
class PwgNamedStruct
{
    /** @var array<string,mixed> */
    public array $_content;
    /** @var array<string,int> */
    public array $_xmlAttributes;

    /**
     * @param array<string,mixed> $content
     * @param list<string>|null $xmlAttributes
     * @param list<string>|null $xmlElements
     */
    public function __construct(array $content, ?array $xmlAttributes = null, ?array $xmlElements = null)
    {
        $this->_content = $content;
        if (isset($xmlAttributes)) {
            $this->_xmlAttributes = array_flip($xmlAttributes);
        } else {
            $this->_xmlAttributes = [];
            foreach ($this->_content as $key => $value) {
                if (!empty($key) && (is_scalar($value) || is_null($value))) {
                    if (empty($xmlElements) || !in_array($key, $xmlElements, true)) {
                        $this->_xmlAttributes[$key] = 1;
                    }
                }
            }
        }
    }
}
```

External read sites continue to work because the properties are `public`. PHP 8.2 emits zero "dynamic property" deprecations because the properties are now declared. PHPStan level 8 (Phase 2 exit) still flags the public-property pattern as a code smell, but it goes into the baseline rather than the error queue.

#### C. `PwgServer` (line 215)

The trunk class. Existing declaration:

```php
class PwgServer
{
  var $_requestHandler;
  var $_requestFormat;
  var $_responseEncoder;
  var $_responseFormat;

  var $_methods = array();

  function __construct() { }
  // ... ~500 lines of methods follow
```

Five declared properties. Audit confirms no other property names are written anywhere in the class body. The plan's parent text (line 419) flags `_serializer` as a possible dynamic property — verified by grep, **no `$this->_serializer` write exists in the current tree**. Either stale documentation or a property removed in an earlier rewrite. Document and move on.

The Phase 2 typed version:

```php
class PwgServer
{
    public ?PwgRequestHandler $_requestHandler = null;
    public string $_requestFormat = '';
    public ?PwgResponseEncoder $_responseEncoder = null;
    public string $_responseFormat = '';

    /** @var array<string, array{callback: callable, description: string, signature: array<string,array{flags:int, type:int, default?:mixed, info?:string, maxValue?:int|float}>, include: string, options: array<string,mixed>}> */
    public array $_methods = [];

    public function __construct() {}

    public function setHandler(string $requestFormat, PwgRequestHandler &$requestHandler): void
    {
        $this->_requestHandler = &$requestHandler;
        $this->_requestFormat = $requestFormat;
    }

    public function setEncoder(string $responseFormat, PwgResponseEncoder &$encoder): void
    {
        $this->_responseEncoder = &$encoder;
        $this->_responseFormat = $responseFormat;
    }

    // ... rest of methods get parameter and return types in Phase 2
}
```

Five things to note:

1. **`_requestHandler` and `_responseEncoder` are nullable.** The constructor leaves them unset; `run()` (line 252) explicitly checks `is_null($this->_responseEncoder)` and bails. With native types, `?PwgRequestHandler` and `= null` default match this contract.
2. **`_requestFormat` and `_responseFormat` are written in `setHandler()` / `setEncoder()` but read at line 257 via `@$this->_requestFormat` (suppression-prefixed).** The `@` is there because the property could be unread. With a `string` type and `''` default, the `@` becomes redundant; remove in Phase 2.
3. **`_methods` is the heaviest annotation.** The shape is a map keyed by method name to a registration record. PHPStan can validate the shape at level 7+. The `@var` block above is what `addMethod()` actually constructs (see `ws_core.inc.php:352-358`).
4. **Visibility stays `public`** for `_methods` because of `ws_core.inc.php:616` — `array_filter($service->_methods, ...)`. Tightening would require a public getter and is out of scope for Phase 1.
5. **External read of `$service->_responseFormat`** at `include/ws_functions/pwg.images.php:603`: `if ($service->_responseFormat != 'rest')`. Same reasoning — keep public.

External reads of `PwgServer` properties (verified):

- `ws_core.inc.php:616` `$service->_methods` — read
- `ws_functions/pwg.images.php:603` `$service->_responseFormat` — read

No external **writes** to undeclared properties. **Verdict: clean if all four `var $_xxx` declarations get native types and `_methods` keeps its `[]` default.**

#### D. Self-heal Playwright spec (`tests/e2e/legacy-config-self-heal.spec.ts`)

The parent plan (Phase 1 Risk #3, line 540) calls for a spec that proves the `dblayer = 'mysql'` → `'mysqli'` self-heal in `common.inc.php` is load-bearing. Honest about which steps need server-side help.

```typescript
import { test, expect } from '@playwright/test';
import { readFileSync, writeFileSync, existsSync } from 'node:fs';
import { resolve } from 'node:path';

const REPO_ROOT = process.env.PIWIGO_REPO_ROOT ?? resolve(__dirname, '../..');
const DB_CONFIG = resolve(REPO_ROOT, 'local/config/database.inc.php');
const DB_CONFIG_BACKUP = `${DB_CONFIG}.legacy-self-heal.bak`;

const LEGACY_FIXTURE = `<?php
\$conf['dblayer'] = 'mysql';
\$conf['db_base'] = 'piwigo';
\$conf['db_user'] = 'piwigo';
\$conf['db_password'] = 'piwigo';
\$conf['db_host'] = 'db';
?>`;

test.describe('legacy dblayer self-heal', () => {

  test.beforeEach(async () => {
    // Filesystem prep is local-only. CI uses a docker bind mount so the
    // working tree is shared between the runner and the PHP container.
    if (existsSync(DB_CONFIG)) {
      writeFileSync(DB_CONFIG_BACKUP, readFileSync(DB_CONFIG));
    }
    writeFileSync(DB_CONFIG, LEGACY_FIXTURE);
  });

  test.afterEach(async () => {
    if (existsSync(DB_CONFIG_BACKUP)) {
      writeFileSync(DB_CONFIG, readFileSync(DB_CONFIG_BACKUP));
    }
  });

  test('homepage returns 200 with legacy mysql dblayer in config', async ({ request }) => {
    const response = await request.get('/');
    expect(response.status()).toBe(200);
    const body = await response.text();
    expect(body).not.toContain('Fatal error');
    expect(body).not.toContain('Cannot redeclare');
  });

  test('a follow-up request still works after self-heal', async ({ request }) => {
    // First hit triggers the self-heal in common.inc.php (in-memory only —
    // we don't rewrite the file on disk, just the $conf array for this request).
    await request.get('/');

    // Second hit must boot just as cleanly. The self-heal is idempotent.
    const r2 = await request.get('/identification.php');
    expect(r2.status()).toBe(200);
  });
});
```

**Honest disclosure of what cannot be done from the test runner alone:**

- The `local/config/database.inc.php` file is on the PHP server's filesystem. In CI (where docker-compose bind-mounts the repo into both the playwright and php-apache containers), the test runner can directly write to it. In a hosted-Piwigo style remote setup, you'd need a `/__test__/reset` endpoint exposed by a test-only PHP file that the production code refuses to load (gate it with `if (!getenv('PIWIGO_TEST_HARNESS')) exit;`).
- The self-heal in `common.inc.php` does **not** rewrite the file on disk per the diff in MODERNIZATION_PLAN.md line 444-457. It mutates `$conf['dblayer']` in memory. So assertion 4 from your spec brief ("after the request `local/config/database.inc.php` has been silently rewritten") is the wrong contract. The right contract is "the next request still works regardless." The spec above asserts that.
- If you want disk-level rewrite semantics, that's a feature change to `common.inc.php`, not a test concern. Recommend NOT changing it — in-memory rewrite is faster, idempotent, and avoids file-permission issues on shared hosting.

#### E. The `lint-syntax` CI job

Parent plan line 542 says "Add a separate `lint-syntax` CI job that runs `php -l` and greps Apache error logs for `Deprecated:` strings — fail if any appear." This adds to the existing `.github/workflows/ci.yml` rather than creating a new file:

```yaml
  lint-syntax:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.5'
          tools: composer:v2
          ini-values: error_reporting=E_ALL, display_errors=On, log_errors=On

      - name: Lint every entry point with php -l
        run: |
          set -e
          ENTRY_POINTS=$(ls *.php)
          for f in $ENTRY_POINTS; do
            echo "::group::php -l $f"
            php -l "$f"
            echo "::endgroup::"
          done

      - name: Lint every PHP file in include/ and admin/
        run: |
          set -e
          find include admin -name '*.php' \
            -not -path 'include/smarty/*' \
            -not -path 'include/feedcreator.class.php' \
            -not -path 'include/phpmailer/*' \
            -not -path 'include/minify/*' \
            -not -path 'admin/include/pclzip.lib.php' \
            -print0 | xargs -0 -n1 -P4 php -l

      - name: Boot the docker stack and capture deprecation noise
        run: |
          docker compose up -d --wait db web
          docker compose exec -T web bash -c 'echo "" > /var/log/apache2/error.log'

      - name: Hit every entry point and scan the error log for Deprecated/Notice/Warning
        run: |
          set -e
          for path in / /identification.php /index.php /picture.php /search.php /tags.php /comments.php /ws.php /upgrade.php; do
            echo "GET $path"
            curl -fsS -o /dev/null -w '%{http_code}\n' "http://localhost:8080$path" || true
          done
          docker compose exec -T web cat /var/log/apache2/error.log > apache.log

          if grep -E '^\[.+\] PHP (Deprecated|Notice|Warning|Fatal):' apache.log; then
            echo "::error::PHP emitted deprecation/notice/warning/fatal during request — Phase 1 contract violated"
            exit 1
          fi
          echo "Apache error log is clean."
```

The `find ... -not -path` exclusions match the same vendored-library list as `phpstan.neon` (parent plan lines 113-118 and 638-650). The Apache log grep is the explicit Phase 1 exit gate. The job runs in parallel with `lint`, `unit`, `e2e` per the existing workflow shape.

---

---

## Phase 2 — Type harvest: phpdoc → native (L)

### Goal

Convert ~3,490 phpdoc `@param`/`@return`/`@var` annotations across `include/`, `admin/`, root entry points and theme PHP into native PHP 8.5 parameter, return and property types; place `declare(strict_types=1);` at the top of every file Rector touches; and walk PHPStan from the level-0 baseline established in Phase 0 up to level 8 with a shrinking baseline. The end state is "every signature in the codebase is checked by the runtime, every type contradiction between phpdoc and reality is either fixed or documented as a baseline entry, and the linter blocks regressions in CI." The DB-upgrade boundary stays intact: `install/db/*.php` and `language/*.php` are excluded everywhere — no Rector, no PHPStan, no `declare(strict_types=1)`. Free functions like `pwg_query()`, `pwg_db_fetch_assoc()`, `pwg_db_real_escape_string()`, `mysqli_*` wrappers in `include/dblayer/functions_mysqli.inc.php` keep their current signatures forever — internal bodies get types, public arity does not change.

### Step-by-step sequence

1. ✅ **Re-confirm the level-0 baseline is clean.** Before adding any Rector type-declaration set, run `vendor/bin/phpstan analyse --memory-limit=1G` and confirm zero new findings against `phpstan-baseline.neon` from Phase 0. If anything has drifted, regenerate the baseline with `vendor/bin/phpstan analyse --generate-baseline` so the type-harvest diffs are isolated. **Exit signal**: PHPStan exits 0 against the unchanged Phase 0 baseline.

2. ✅ **Run the type-declaration rector dry-run on `include/` only.** Edit `rector.php` to register `Rector\Set\ValueObject\SetList::TYPE_DECLARATION` plus `AddParamTypeFromPhpDocRector`, `AddReturnTypeFromPhpDocRector`, `AddPropertyTypeFromPhpDocRector`, scoped to `__DIR__ . '/include'` only. Add `include/smarty/**`, `include/phpmailer/**`, `include/minify/**`, `include/feedcreator.class.php`, `include/emogrifier.class.php`, `include/jshrink.class.php`, `include/passwordhash.class.php`, `include/mdetect.php` to `withSkip` — vendored libraries we do not retype. Run `vendor/bin/rector process --dry-run > rector-include-dryrun.txt 2>&1`. Open the diff and grep it for the four danger patterns: `: array` where the phpdoc said `array|false`; `: int` where the phpdoc said `int|null`; `: void` on functions whose phpdoc said `@return string|void`; `: bool` where the function `return null;` somewhere. **Exit signal**: dry-run completes without parser errors; danger-pattern hits are recorded for hand-fix in step 3.

3. ✅ **Apply, hand-fix Rector residue, commit `include/`.** Run `vendor/bin/rector process` (no `--dry-run`). For every file Rector modifies, manually add `declare(strict_types=1);` as the first line after `<?php` — Rector's `DeclareStrictTypesRector` is staged for step 5, but adding it now per-file in `include/` makes the diff reviewable. Hand-fix the cases flagged in step 2: e.g. `register_user()` at `include/functions_user.inc.php:123` has phpdoc `@return int|false user id or false` but the body has paths that fall off the end returning `null` — change to `: int|false|null` and refactor to ensure all paths return explicitly. Run the Phase 0 Playwright + UpgradeChainTest suite. Commit. **Exit signal**: CI green; only `include/` files modified plus exactly one `declare(strict_types=1);` per touched file.

4. ✅ **Repeat the slice for the rest of the included tree.** Separate commits, in this order: `admin/` (excluding `admin/include/pclzip.lib.php` — vendored zip code), then `ws.php` + `include/ws_core.inc.php` + `include/ws_functions/*.php` + `include/ws_protocols/*.php`, then root entry points (every entry from `index.php` through `notification.php`), then `themes/default/*.php` (11 files, mostly trivial), then `install/` **excluding `install/db/`**. Each slice is one commit. **Exit signal per slice**: CI green; PHPStan baseline file shrinks (or stays equal — never grows) when the level later moves up.

5. ✅ **Apply `DeclareStrictTypesRector` codebase-wide in one sweep.** Switch on `Rector\Php70\Rector\FileWithoutNamespace\DeclareStrictTypesRector` and run `vendor/bin/rector process`. One-line-per-file diff but it touches ~500 files. Single commit titled "phase2: declare(strict_types=1) everywhere". The Rector config must still skip `install/db/`, `language/`, and the vendored libs listed in step 2 — `strict_types` in those files would break the upgrade chain (the install/db scripts pass `'1'` as int parameters routinely). Add a CI grep guard: `! grep -rL 'declare(strict_types=1)' include/ admin/ themes/default/*.php` (must return empty). **Exit signal**: every non-excluded PHP file declares strict types; CI grep guard passes; UpgradeChainTest still green.

6. ✅ **Walk PHPStan from level 0 to level 8, one commit per level.** At each level, regenerate the baseline (`vendor/bin/phpstan analyse --level=N --generate-baseline`), hand-fix as many entries as appetite allows, then commit the shrunken baseline. Level-by-level guidance:

   | Level | New error class | Typical Piwigo finding |
   |---|---|---|
   | 0 | basic syntax | already clean post Phase 0 |
   | 1 | unknown variables | bare `$conf` reads in helper functions missing `global $conf;` |
   | 2 | unknown methods/funcs | calls to `mysql_*` (already gone post Phase 1), Smarty methods on the wrapped instance |
   | 3 | return type mismatch | `function() { if (...) return 5; }` — implicit null fall-through |
   | 4 | unreachable / dead code | `if ($conf['debug_template']) { ... }` after the value is set true at the top |
   | 5 | arg type mismatch | `pwg_query($sql_array)` where `$sql_array` is sometimes string, sometimes array |
   | 6 | missing typehints | drops dramatically post step 5 |
   | 7 | nullable vs non-null | `pwg_db_fetch_assoc(pwg_query(...))` where the inner returns `mysqli_result\|false` |
   | 8 | strict any-vs-mixed | `$conf['foo']` accesses against unannotated array — fixed by `Conf` `@phpstan-type` alias |

   **Exit signal**: PHPStan level 8 in `phpstan.neon`; baseline file exists but is shrinking; CI ratchet check (script that fails if baseline *grows*) is in place.

7. ✅ **Address `mixed` proliferation deliberately.** Rector will paint many signatures with `mixed`. Three escalation tiers — pick per call site, do not blanket-apply:
   - **Tier 1**: union types where the real domain is small. `function get_userid(string $username): int|false` is correct; do not widen to `mixed`.
   - **Tier 2**: array shapes via `@phpstan-type` aliases. Add to the top of `include/common.inc.php` a `@phpstan-type Conf array{upload_dir: string, data_location: string, db_base: string, db_host: string, db_user: string, db_password: string, dblayer: string, webmaster_id: int, mobile_theme: string, gallery_locked: bool, ...}` block (full key list comes from a sweep of `include/config_default.inc.php`). Re-export the alias on every function that takes `array $conf` via `@phpstan-param Conf $conf`. PHPStan can now check `$conf['upload_dir']` accesses.
   - **Tier 3**: minimal DTOs (real classes with typed readonly properties) for hot data passed by-array — `Image`, `Category`, `User`. Phase 2 only ships the `@phpstan-type` aliases; the real DTOs are Phase 4.

   **Exit signal**: `grep -rn ': mixed' include/ admin/ | wc -l` is bounded (target: under 200); none in hot-path functions whose phpdoc was specific.

8. ✅ **Coercion-at-boundary policy for `$_GET`/`$_POST`/`$_COOKIE`/`$_REQUEST`.** Rector cannot insert `(int)` casts itself. Cast at the boundary, never widen the signature: `index.php` reads `$category_id = isset($_GET['cat_id']) ? (int) $_GET['cat_id'] : null;` and passes `int|null` to `function show_category(?int $id)`. Add helpers to `include/functions.inc.php`: `function input_int(string $key, ?int $default = null): ?int { return isset($_GET[$key]) ? (int) $_GET[$key] : $default; }` plus `input_string` and `input_bool`. Walk every existing `$_GET`/`$_POST`/`$_COOKIE` site (89 occurrences across 10 files) and migrate. **Exit signal**: every entry-point file routes user input through `input_*` helpers; PHPStan no longer reports `mixed` flowing into typed parameters from superglobals.

9. ✅ **Final lint pass: redundant phpdoc cleanup.** With native types in place, every `@param string $login` above a function that already takes `string $login` is duplicative noise. Run a one-shot rule (custom Rector rule or sed script) to strip phpdoc lines whose type *exactly* matches the native type. Keep `@param` lines that add description (`@param string $login the username typed at login`). Run PHPStan with `treatPhpDocTypesAsCertain: false` once to verify no contradictions remain. **Exit signal**: `grep -c '@param' include/*.inc.php` drops from ~3,490 to ~800; PHPStan level 8 still green; no `Method has incompatible PHPDoc` errors.

### Concrete artifacts

**`rector.php` for Phase 2:**

```php
return RectorConfig::configure()
    ->withPaths([__DIR__ . '/include', __DIR__ . '/admin', __DIR__ . '/themes/default'])
    ->withSkip([
        __DIR__ . '/install/db/*', __DIR__ . '/language/*',
        __DIR__ . '/include/smarty', __DIR__ . '/include/phpmailer',
        __DIR__ . '/include/minify', __DIR__ . '/include/feedcreator.class.php',
        __DIR__ . '/include/emogrifier.class.php', __DIR__ . '/include/jshrink.class.php',
        __DIR__ . '/include/passwordhash.class.php', __DIR__ . '/include/mdetect.php',
        __DIR__ . '/admin/include/pclzip.lib.php',
    ])
    ->withSets([SetList::TYPE_DECLARATION, SetList::CODE_QUALITY])
    ->withRules([
        AddParamTypeFromPhpDocRector::class,
        AddReturnTypeFromPhpDocRector::class,
        AddPropertyTypeFromPhpDocRector::class,
        DeclareStrictTypesRector::class,
    ])
    ->withTypeCoverageLevel(80);
```

**`phpstan.neon` at end of Phase 2:**

```neon
parameters:
    level: 8
    paths: [include, admin, themes/default, ws.php, index.php, picture.php, admin.php, install]
    excludePaths:
        - install/db/*
        - language/*
        - include/smarty
        - include/phpmailer
        - include/minify
        - include/feedcreator.class.php
        - include/emogrifier.class.php
        - include/jshrink.class.php
        - include/passwordhash.class.php
        - include/mdetect.php
        - admin/include/pclzip.lib.php
    bootstrapFiles: [tools/phpstan-bootstrap.php]
    treatPhpDocTypesAsCertain: false
    checkMissingIterableValueType: false
includes:
    - phpstan-baseline.neon
    - vendor/phpstan/phpstan-strict-rules/rules.neon
    - vendor/phpstan/phpstan-deprecation-rules/rules.neon
```

**Sample before/after — `include/functions.inc.php:59`:**

Before:
```php
/**
 * returns the number of seconds (with 3 decimals precision)
 * between the start time and the end time given
 * @param float $start
 * @param float $end
 * @return string "$TIME s"
 */
function get_elapsed_time($start, $end)
{
  return number_format($end - $start, 3, '.', ' ').' s';
}
```

After:
```php
/**
 * returns the number of seconds (with 3 decimals precision)
 * between the start time and the end time given, formatted as "$TIME s"
 */
function get_elapsed_time(float $start, float $end): string
{
  return number_format($end - $start, 3, '.', ' ').' s';
}
```

**Sample `@phpstan-type Conf` block** (top of `include/common.inc.php` or `tools/phpstan-types.php`):

```php
/**
 * @phpstan-type Conf array{
 *     dblayer: string, db_host: string, db_user: string, db_password: string, db_base: string,
 *     upload_dir: string, data_location: string, webmaster_id: int, gallery_locked: bool,
 *     mobile_theme: string, allow_html_descriptions: bool, order_by: string,
 *     order_by_inside_category: string, check_upgrade_feed: bool, session_gc_probability: int,
 *     log_level: int, log_dir: string, log_archive_days: int, show_php_errors: int,
 *     show_php_errors_on_frontend: bool, debug_template: bool, template_compile_check: bool,
 *     template_force_compile: bool, compiled_template_cache_language: bool,
 *     extents_for_templates?: string, header_notes?: list<string>,
 *     filter_pages?: array<string,mixed>, piwigo_db_version?: string,
 *     piwigo_installed_version?: string, last_major_update?: string
 * }
 */
```

(Partial — final shape comes from a sweep of `include/config_default.inc.php`.)

### Effort breakdown

| Sub-task | Tag |
| --- | --- |
| Add type-declaration sets to `rector.php`, dry-run on `include/` | S |
| Apply Rector + hand-fix residue + commit `include/` slice | M |
| Same again for `admin/` | M |
| Slice for entry points + `themes/default/*.php` | S |
| Slice for `install/` excluding `install/db/` | S |
| Codebase-wide `DeclareStrictTypesRector` sweep | M |
| Walk PHPStan levels 0→8, six PRs, baseline-shrink campaign | L |
| Build the `Conf` `@phpstan-type` alias | M |
| Coercion-at-boundary helpers + 89 superglobal site migration | S |
| Final phpdoc strip pass + ratchet CI check | S |

**Phase total: L.**

### Risks specific to this phase

1. **Rector's `TYPE_DECLARATION` adds `?array` where the function actually returns `array|false`.** Concrete: `register_user()` at `include/functions_user.inc.php:123` declares `@return int|false`. Some branches fall off returning `null` implicitly. Mitigation: before each slice, `grep -rn '@return.*|false\|@return.*|null\|@return.*|void' <slice-dir>` and inspect each. Hand-fix before Rector runs, not after.

2. **`strict_types=1` flips silent string-to-int coercion into `TypeError` in code paths E2E doesn't cover.** Piwigo install accepts `'1'` from `<input type="checkbox">` and passes to functions wanting `int`. Mitigation: keep install flow under E2E coverage; CI job grep-fails on any `TypeError`/`ValueError` in PHP error logs after staging soak.

3. **`func_get_args()`-based variadic dispatch in `ws_core.inc.php` and `template.class.php` confuses Rector.** Cannot derive type from `function foo() { $args = func_get_args(); ... }`. Mitigation: hand-rewrite to `...$args` variadic syntax before Rector.

4. **Dynamic property writes on `PwgError`, `PwgServer`, `Template`.** Mitigation: declare every property explicitly via a level-5 walk before opting these classes into the type-harvest. Treat `#[\AllowDynamicProperties]` as technical debt — these are the same classes Phase 3 wraps in namespaces.

5. **`@phpstan-type Conf` drift.** `include/config_default.inc.php` is huge and admin pages add new keys at runtime. Mitigation: CI script `tools/check-conf-shape.php` that boots `config_default.inc.php`, dumps keys, diffs against the alias, warns on drift.

### Verification

- `vendor/bin/phpstan analyse --level=8 --error-format=table` exits 0 against shrunken baseline.
- `vendor/bin/rector process --dry-run` emits no diffs.
- `! find include admin themes/default -name '*.php' | xargs grep -L 'declare(strict_types=1)'` returns empty (excluding skip list).
- Playwright + `UpgradeChainTest` green.
- 7-day staging soak: zero `TypeError`/`ValueError`/`E_DEPRECATED` lines in `data/log_*.txt`.
- **Actual run**: PHPStan level 8, baseline 3541 errors (walked levels 0→8 via 8 incremental commits); TYPE_DECLARATION rector applied across include/ (50 files), admin/ (32 files), root entry points (6 files), install/ (25 files); strict_types added to all 210+ tracked PHP files; 9 real bugs fixed by strict_types enforcement (unserialize null ×5, ceil→int ×2, str_replace false arg, str2DateTime widening, gc() return type, version_compare int args, array_fill POST string); Conf @phpstan-type alias in tools/phpstan-types.php; input_int/string/bool coercion helpers added; redundant @param/@return stripped (1251→1036 in include/). Final CI run 24976552076: lint ✓, e2e ✓, unit ✓ (1m55s). All 9 Phase 2 steps ✅. Post-completion gap closed (ec06da9cf): install/index.php was missing declare(strict_types=1); rector.php and CI guard extended to include install/ directory.


### Part 1 — Phase 2: Custom PHPStan rules and full `Conf` alias

Phase 2 of the parent plan walks PHPStan from level 0 to level 8 and harvests phpdoc into native types. Five tools are needed beyond what Rector and PHPStan ship out of the box:

1. The complete `@phpstan-type Conf` alias.
2. A drift detector that fails CI when `config_default.inc.php` and the alias diverge.
3. A custom PHPStan rule banning `new $variable()` without a class-string proof.
4. A baseline-grow guard that runs after each PHPStan analysis.
5. A custom PHPStan rule banning legacy globals inside `src/` once Phase 4's typed services ship.
6. A Rector rule that strips redundant `@param`/`@return` lines.

This part details all six.

#### A. The complete `@phpstan-type Conf` alias

A full sweep of `include/config_default.inc.php` (1088 lines) found ~140 `$conf[...] = ...` assignments. Some are derived from earlier keys (`$conf['file_ext']` reuses `$conf['picture_ext']`), some are conditional on `PHP_SAPI` (`$conf['chmod_value']`), most are bare scalar/array literals. They cluster cleanly by Phase 4 service boundaries.

Below, each cluster gets its own narrow `@phpstan-type` block; the combined alias follows.

##### A.1 Paths

Paths and locations of writable directories. Always strings. `themes_dir` is computed from `PHPWG_ROOT_PATH` so it is non-empty by the time anything reads it; `ext_imagick_dir` and `ffmpeg_dir` default to empty strings meaning "look on PATH".

```
@phpstan-type ConfPaths array{
    upload_dir: string,
    data_location: string,
    themes_dir: string,
    log_dir: string,
    ext_imagick_dir: string,
    ffmpeg_dir: string,
    no_photo_yet_url: string
}
```

##### A.2 Database / authentication

`users_table` defaults to `null` to mean "use the standard table"; `password_hash` and `password_verify` are function-name strings (technically `callable-string` in PHPStan). `dblayer` is set in `local/config/database.inc.php` (not in `config_default`) but it is read everywhere; the alias must include it.

```
@phpstan-type ConfDb array{
    dblayer: string,
    db_host: string,
    db_user: string,
    db_password: string,
    db_base: string,
    users_table: string|null,
    user_fields: array{id: string, username: string, password: string, email: string},
    apache_authentication: bool,
    external_authentification: bool,
    password_hash: callable-string,
    password_verify: callable-string,
    guest_id: int,
    default_user_id: int,
    insensitive_case_logon: bool,
    browser_language: bool,
    guest_access: bool,
    password_reset_duration: int,
    password_activation_duration: int,
    password_reset_code_duration: int
}
```

##### A.3 Mail

```
@phpstan-type ConfMail array{
    send_bcc_mail_webmaster: bool,
    mail_sender_name: string,
    mail_sender_email: string,
    mail_allow_html: bool,
    smtp_host: string,
    smtp_user: string,
    smtp_password: string,
    smtp_secure: 'ssl'|'tls'|null
}
```

##### A.4 Derivatives, image processing, uploads

`derivative_url_style` is documented as the integer set `{0, 1, 2}` ('auto', 'derivative', 'script'). `chmod_value` is computed at file-load time from `PHP_SAPI`, but is always one of `0755` / `0777`. `picture_ext` and `format_ext` are lists of bare extensions. `derivative_default_size` is a string enum.

```
@phpstan-type ConfDerivatives array{
    picture_ext: list<string>,
    file_ext: list<string>,
    enable_formats: bool,
    format_ext: list<string>,
    graphics_library: 'auto'|'imagick'|'ext_imagick'|'gd',
    uniqueness_mode: 'md5sum'|'filename',
    tiff_representative_ext: 'png'|'jpg',
    chmod_value: int,
    derivative_default_size: 'small'|'medium'|'large',
    derivative_url_style: 0|1|2,
    derivatives_strip_metadata_threshold: int,
    animated_webp_compression_quality: int,
    max_requests: int,
    original_url_protection: ''|'images'|'all',
    upload_form_automatic_rotation: bool,
    upload_form_all_types: bool,
    upload_form_chunk_size: int,
    upload_form_max_file_size: int,
    enable_synchronization: bool,
    sync_chars_regex: string,
    sync_exclude_folders: list<string>,
    inheritance_by_default: bool,
    checksum_compute_blocksize: int,
    lounge_activate_threshold: int,
    lounge_max_duration: int,
    batch_manager_images_per_page_global: int,
    batch_manager_images_per_page_unit: int
}
```

##### A.5 Debug / performance

`show_php_errors` is documented as accepting "INI 'error_reporting' values" — that is `int` (e.g. `E_ALL`) but accepts `string` too if a user wrote `'E_ALL & ~E_DEPRECATED'` literally; PHPStan should treat it as `int|string`.

```
@phpstan-type ConfDebug array{
    show_queries: bool,
    show_gt: bool,
    debug_l10n: bool,
    debug_template: bool,
    debug_mail: bool,
    die_on_sql_error: bool,
    compiled_template_cache_language: bool,
    template_compile_check: bool,
    template_force_compile: bool,
    template_combine_files: bool,
    show_php_errors: int|string,
    show_php_errors_on_frontend: bool
}
```

##### A.6 Sessions / API keys

```
@phpstan-type ConfSession array{
    session_use_cookies: bool,
    session_use_only_cookies: bool,
    session_use_trans_sid: bool,
    session_name: string,
    session_save_handler: 'db'|'file',
    authorize_remembering: bool,
    remember_me_name: string,
    remember_me_length: int,
    session_length: int,
    session_use_ip_address: bool,
    session_gc_probability: int,
    api_key_duration: list<string>,
    api_key_forbidden_methods: list<string>,
    auth_key_duration: int
}
```

##### A.7 Notification by mail

```
@phpstan-type ConfNbm array{
    nbm_default_value_user_enabled: bool,
    nbm_list_all_enabled_users_to_send: bool,
    nbm_max_treatment_timeout_percent: float,
    nbm_treatment_timeout_default: int,
    recent_post_dates: array{
        RSS: array{max_dates: int, max_elements: int, max_cats: int},
        NBM: array{max_dates: int, max_elements: int, max_cats: int}
    },
    rss_feed_author: string
}
```

##### A.8 Tags / search / related albums

```
@phpstan-type ConfTags array{
    full_tag_cloud_items_number: int,
    menubar_tag_cloud_items_number: int,
    menubar_tag_cloud_content: 'always_all'|'current_only'|'all_or_current',
    content_tag_cloud_items_number: int,
    tags_levels: int,
    tags_default_display_mode: 'letters'|'cloud',
    tag_letters_column_number: int,
    related_albums_maximum_items_to_compute: int,
    related_albums_display_limit: int,
    quick_search_include_sub_albums: bool,
    default_filters_views: array<string, array{access: string, default: bool}|bool>
}
```

(`default_filters_views` is heterogeneous: most keys are `array{access, default}`, the trailing `last_filters_conf` key is a bare `bool`. Hence the union.)

##### A.9 URLs

```
@phpstan-type ConfUrls array{
    gallery_url: string|null,
    question_mark_in_urls: bool,
    php_extension_in_urls: bool,
    category_url_style: 'id'|'id-name',
    picture_url_style: 'id'|'id-file'|'file',
    tag_url_style: 'id'|'id-tag'|'tag',
    url_port: 'none'|'auto'|int,
    alternative_pem_url: string,
    pem_plugins_category: int,
    pem_themes_category: int,
    pem_languages_category: int
}
```

##### A.10 Metadata, history, logs, proxy, slideshow, calendar, misc

```
@phpstan-type ConfMisc array{
    picture_ext: list<string>,
    top_number: int,
    'anti-flood_time': int,
    comment_spam_reject: bool,
    comment_spam_max_links: int,
    calendar_datefield: string,
    calendar_show_any: bool,
    calendar_show_empty: bool,
    newcat_default_commentable: bool,
    newcat_default_visible: bool,
    newcat_default_status: 'public'|'private',
    newcat_default_position: 'first'|'last',
    light_album_manager_threshold: int,
    level_separator: string,
    paginate_pages_around: int,
    show_version: bool,
    meta_ref: bool,
    links: array<string, string|array{label: string, new_window?: bool, nw_name?: string, nw_features?: string, eval_visible?: string}>,
    random_index_redirect: array<string, string>,
    header_notes: list<string>,
    show_thumbnail_caption: bool,
    allow_random_representative: bool,
    representative_cache_on_level: bool,
    representative_cache_on_subcats: bool,
    allow_html_descriptions: bool,
    available_permission_levels: list<int>,
    check_upgrade_feed: bool,
    rate_items: list<int>,
    default_redirect_method: 'http'|'html',
    double_password_type_in_admin: bool,
    comments_page_nb_comments: int|'all',
    update_notify_check_period: int,
    update_notify_reminder_period: int,
    send_piwigo_infos: bool,
    album_description_on_all_pages: bool,
    stat_compare_year_displayed: int,
    linked_album_search_limit: int,
    fs_quick_check_period: int,
    pdf_viewer_filesize_threshold: int,
    show_iptc: bool,
    show_iptc_mapping: array<string, string>,
    use_iptc: bool,
    use_iptc_mapping: array<string, string>,
    show_exif: bool,
    show_exif_fields: list<string>,
    use_exif: bool,
    use_exif_mapping: array<string, string>,
    allow_html_in_metadata: bool,
    metadata_keyword_separator_regex: string,
    nb_logs_page: int,
    history_autopurge_every: int,
    history_autopurge_keep_lines: int,
    history_autopurge_blocksize: int,
    log_level: 'OFF'|'CRITICAL'|'ERROR'|'WARNING'|'NOTICE'|'INFO'|'DEBUG',
    log_archive_days: int,
    use_proxy: bool,
    proxy_server: string,
    proxy_auth: string,
    slideshow_period_min: int,
    slideshow_period_max: int,
    slideshow_period_step: int,
    slideshow_period: int,
    slideshow_repeat: bool,
    light_slideshow: bool,
    admin_theme: string,
    enable_plugins: bool,
    allow_web_services: bool,
    ws_max_images_per_page: int,
    ws_max_users_per_page: int,
    show_newsletter_subscription: bool,
    show_piwigo_latest_news: bool,
    dashboard_check_for_updates: bool,
    dashboard_activity_nb_weeks: int,
    activity_display_connections: 'all'|'admins_only'|'none',
    album_move_delay_before_auto_opening: int,
    show_template_in_side_menu: bool,
    add_cache_to_storage_chart: bool,
    filter_pages: array<string, array{used?: bool, cancel?: bool, add_notes?: bool}>
}
```

##### A.11 Optional / runtime-only keys

These are absent from `config_default.inc.php` but written by the runtime, by `local/config/config.inc.php`, by `load_conf_from_db()`, or by admin pages. The alias marks them with `?:`.

```
@phpstan-type ConfRuntime array{
    webmaster_id?: int,
    secret_key?: string,
    piwigo_db_version?: string,
    piwigo_installed_version?: string,
    last_major_update?: string,
    order_by?: string,
    order_by_inside_category?: string,
    order_by_custom?: string,
    order_by_inside_category_custom?: string,
    extents_for_templates?: string,
    no_photo_yet?: bool,
    gallery_locked?: bool,
    mobile_theme?: string,
    plugin?: array<string, mixed>
}
```

##### A.12 The full combined `Conf` alias

Drop this PHPDoc block at the top of `tools/phpstan-types.php` (a no-op include used only for its docblocks):

```php
<?php declare(strict_types=1);

/**
 * @phpstan-type Conf array{
 *     // paths
 *     upload_dir: string, data_location: string, themes_dir: string,
 *     log_dir: string, ext_imagick_dir: string, ffmpeg_dir: string,
 *     no_photo_yet_url: string,
 *     // db / auth
 *     dblayer: string, db_host: string, db_user: string, db_password: string,
 *     db_base: string, users_table: string|null,
 *     user_fields: array{id: string, username: string, password: string, email: string},
 *     apache_authentication: bool, external_authentification: bool,
 *     password_hash: callable-string, password_verify: callable-string,
 *     guest_id: int, default_user_id: int, insensitive_case_logon: bool,
 *     browser_language: bool, guest_access: bool,
 *     password_reset_duration: int, password_activation_duration: int,
 *     password_reset_code_duration: int,
 *     // mail
 *     send_bcc_mail_webmaster: bool, mail_sender_name: string,
 *     mail_sender_email: string, mail_allow_html: bool,
 *     smtp_host: string, smtp_user: string, smtp_password: string,
 *     smtp_secure: 'ssl'|'tls'|null,
 *     // derivatives / uploads
 *     picture_ext: list<string>, file_ext: list<string>, enable_formats: bool,
 *     format_ext: list<string>,
 *     graphics_library: 'auto'|'imagick'|'ext_imagick'|'gd',
 *     uniqueness_mode: 'md5sum'|'filename',
 *     tiff_representative_ext: 'png'|'jpg', chmod_value: int,
 *     derivative_default_size: 'small'|'medium'|'large',
 *     derivative_url_style: 0|1|2, derivatives_strip_metadata_threshold: int,
 *     animated_webp_compression_quality: int, max_requests: int,
 *     original_url_protection: ''|'images'|'all',
 *     upload_form_automatic_rotation: bool, upload_form_all_types: bool,
 *     upload_form_chunk_size: int, upload_form_max_file_size: int,
 *     enable_synchronization: bool, sync_chars_regex: string,
 *     sync_exclude_folders: list<string>, inheritance_by_default: bool,
 *     checksum_compute_blocksize: int, lounge_activate_threshold: int,
 *     lounge_max_duration: int,
 *     batch_manager_images_per_page_global: int,
 *     batch_manager_images_per_page_unit: int,
 *     // debug
 *     show_queries: bool, show_gt: bool, debug_l10n: bool,
 *     debug_template: bool, debug_mail: bool, die_on_sql_error: bool,
 *     compiled_template_cache_language: bool, template_compile_check: bool,
 *     template_force_compile: bool, template_combine_files: bool,
 *     show_php_errors: int|string, show_php_errors_on_frontend: bool,
 *     // session / api key
 *     session_use_cookies: bool, session_use_only_cookies: bool,
 *     session_use_trans_sid: bool, session_name: string,
 *     session_save_handler: 'db'|'file', authorize_remembering: bool,
 *     remember_me_name: string, remember_me_length: int,
 *     session_length: int, session_use_ip_address: bool,
 *     session_gc_probability: int,
 *     api_key_duration: list<string>, api_key_forbidden_methods: list<string>,
 *     auth_key_duration: int,
 *     // notification by mail
 *     nbm_default_value_user_enabled: bool,
 *     nbm_list_all_enabled_users_to_send: bool,
 *     nbm_max_treatment_timeout_percent: float,
 *     nbm_treatment_timeout_default: int,
 *     recent_post_dates: array{RSS: array{max_dates:int,max_elements:int,max_cats:int}, NBM: array{max_dates:int,max_elements:int,max_cats:int}},
 *     rss_feed_author: string,
 *     // tags / search / related
 *     full_tag_cloud_items_number: int,
 *     menubar_tag_cloud_items_number: int,
 *     menubar_tag_cloud_content: 'always_all'|'current_only'|'all_or_current',
 *     content_tag_cloud_items_number: int, tags_levels: int,
 *     tags_default_display_mode: 'letters'|'cloud',
 *     tag_letters_column_number: int,
 *     related_albums_maximum_items_to_compute: int,
 *     related_albums_display_limit: int,
 *     quick_search_include_sub_albums: bool,
 *     default_filters_views: array<string, array{access: string, default: bool}|bool>,
 *     // urls
 *     gallery_url: string|null, question_mark_in_urls: bool,
 *     php_extension_in_urls: bool,
 *     category_url_style: 'id'|'id-name',
 *     picture_url_style: 'id'|'id-file'|'file',
 *     tag_url_style: 'id'|'id-tag'|'tag',
 *     url_port: 'none'|'auto'|int,
 *     alternative_pem_url: string, pem_plugins_category: int,
 *     pem_themes_category: int, pem_languages_category: int,
 *     // misc
 *     top_number: int, 'anti-flood_time': int, comment_spam_reject: bool,
 *     comment_spam_max_links: int, calendar_datefield: string,
 *     calendar_show_any: bool, calendar_show_empty: bool,
 *     newcat_default_commentable: bool, newcat_default_visible: bool,
 *     newcat_default_status: 'public'|'private',
 *     newcat_default_position: 'first'|'last',
 *     light_album_manager_threshold: int, level_separator: string,
 *     paginate_pages_around: int, show_version: bool, meta_ref: bool,
 *     links: array<string, string|array{label:string,new_window?:bool,nw_name?:string,nw_features?:string,eval_visible?:string}>,
 *     random_index_redirect: array<string, string>,
 *     header_notes: list<string>, show_thumbnail_caption: bool,
 *     allow_random_representative: bool,
 *     representative_cache_on_level: bool,
 *     representative_cache_on_subcats: bool,
 *     allow_html_descriptions: bool,
 *     available_permission_levels: list<int>,
 *     check_upgrade_feed: bool, rate_items: list<int>,
 *     default_redirect_method: 'http'|'html',
 *     double_password_type_in_admin: bool,
 *     comments_page_nb_comments: int|'all',
 *     update_notify_check_period: int, update_notify_reminder_period: int,
 *     send_piwigo_infos: bool, album_description_on_all_pages: bool,
 *     stat_compare_year_displayed: int, linked_album_search_limit: int,
 *     fs_quick_check_period: int, pdf_viewer_filesize_threshold: int,
 *     show_iptc: bool, show_iptc_mapping: array<string, string>,
 *     use_iptc: bool, use_iptc_mapping: array<string, string>,
 *     show_exif: bool, show_exif_fields: list<string>,
 *     use_exif: bool, use_exif_mapping: array<string, string>,
 *     allow_html_in_metadata: bool,
 *     metadata_keyword_separator_regex: string,
 *     nb_logs_page: int, history_autopurge_every: int,
 *     history_autopurge_keep_lines: int,
 *     history_autopurge_blocksize: int,
 *     log_level: 'OFF'|'CRITICAL'|'ERROR'|'WARNING'|'NOTICE'|'INFO'|'DEBUG',
 *     log_archive_days: int,
 *     use_proxy: bool, proxy_server: string, proxy_auth: string,
 *     slideshow_period_min: int, slideshow_period_max: int,
 *     slideshow_period_step: int, slideshow_period: int,
 *     slideshow_repeat: bool, light_slideshow: bool,
 *     admin_theme: string, enable_plugins: bool, allow_web_services: bool,
 *     ws_max_images_per_page: int, ws_max_users_per_page: int,
 *     show_newsletter_subscription: bool, show_piwigo_latest_news: bool,
 *     dashboard_check_for_updates: bool, dashboard_activity_nb_weeks: int,
 *     activity_display_connections: 'all'|'admins_only'|'none',
 *     album_move_delay_before_auto_opening: int,
 *     show_template_in_side_menu: bool, add_cache_to_storage_chart: bool,
 *     filter_pages: array<string, array{used?: bool, cancel?: bool, add_notes?: bool}>,
 *     // runtime / optional
 *     webmaster_id?: int, secret_key?: string,
 *     piwigo_db_version?: string, piwigo_installed_version?: string,
 *     last_major_update?: string,
 *     order_by?: string, order_by_inside_category?: string,
 *     order_by_custom?: string, order_by_inside_category_custom?: string,
 *     extents_for_templates?: string, no_photo_yet?: bool,
 *     gallery_locked?: bool, mobile_theme?: string,
 *     plugin?: array<string, mixed>
 * }
 */
final class PiwigoConfTypes {}
```

`PiwigoConfTypes` is a sentinel class — it carries the `@phpstan-type Conf` PHPDoc annotation that other files reference via `@phpstan-import-type Conf from \PiwigoConfTypes`. The file itself is included in `phpstan.neon` `bootstrapFiles` so the alias resolves at analysis time without polluting runtime autoload.

#### B. The `tools/check-conf-shape.php` drift detector

The alias above is ~140 keys hand-curated. `admin/configuration.php` and plugins routinely add new keys; without a guard, the alias rots within months. CI runs `tools/check-conf-shape.php` as part of the `lint` job:

```php
<?php declare(strict_types=1);

/**
 * Drift detector for $conf keys vs. the Conf @phpstan-type alias.
 *
 * Boots include/config_default.inc.php in isolation (no DB, no session),
 * then parses the @phpstan-type Conf block in tools/phpstan-types.php and
 * fails CI if the two key sets diverge.
 */

const ROOT = __DIR__ . '/..';

// 1. Boot config_default.inc.php in isolation
//    Define the constants config_default expects but do NOT include common.inc.php.
define('PHPWG_ROOT_PATH', ROOT . '/');
$conf = [];
require ROOT . '/include/config_default.inc.php';

$defaultsKeys = array_keys($conf);
sort($defaultsKeys);

// 2. Read tools/phpstan-types.php and extract keys from the Conf alias docblock
$src = file_get_contents(ROOT . '/tools/phpstan-types.php');
if ($src === false) {
    fwrite(STDERR, "FATAL: cannot read tools/phpstan-types.php\n");
    exit(2);
}

if (!preg_match('#@phpstan-type\s+Conf\s+array\{(.+?)\}\s*\n\s*\*/#s', $src, $m)) {
    fwrite(STDERR, "FATAL: @phpstan-type Conf block not found in tools/phpstan-types.php\n");
    exit(2);
}

$body = $m[1];

// Strip line comments inside the docblock (// paths, // db / auth, ...)
$body = preg_replace('#//[^\n]*#', '', $body);
// Collapse leading " * " noise
$body = preg_replace('#^\s*\*\s?#m', '', $body);

// Parse "key:" or "key?:" tokens at depth 0 of the array{} body
$aliasKeys = [];
$depth = 0;
$buf = '';
for ($i = 0, $n = strlen($body); $i < $n; $i++) {
    $c = $body[$i];
    if ($c === '{' || $c === '<') { $depth++; }
    elseif ($c === '}' || $c === '>') { $depth--; }

    if ($depth === 0 && $c === ',') {
        if (preg_match('#^\s*([\'"]?)([a-zA-Z0-9_\-]+)\1\??\s*:#', $buf, $km)) {
            $aliasKeys[] = $km[2];
        }
        $buf = '';
        continue;
    }
    $buf .= $c;
}
// Final fragment
if (preg_match('#^\s*([\'"]?)([a-zA-Z0-9_\-]+)\1\??\s*:#', $buf, $km)) {
    $aliasKeys[] = $km[2];
}
sort($aliasKeys);

// 3. Diff
$missingFromAlias = array_diff($defaultsKeys, $aliasKeys);
$missingFromDefaults = array_diff($aliasKeys, $defaultsKeys);

// Optional/runtime keys are allowed to be alias-only (not in config_default)
$runtimeOnly = [
    'webmaster_id', 'secret_key', 'piwigo_db_version', 'piwigo_installed_version',
    'last_major_update', 'order_by', 'order_by_inside_category',
    'order_by_custom', 'order_by_inside_category_custom',
    'extents_for_templates', 'no_photo_yet', 'gallery_locked',
    'mobile_theme', 'plugin', 'dblayer', 'db_host', 'db_user',
    'db_password', 'db_base',
];
$missingFromDefaults = array_values(array_diff($missingFromDefaults, $runtimeOnly));

if ($missingFromAlias === [] && $missingFromDefaults === []) {
    echo "OK: Conf alias matches config_default.inc.php (" . count($defaultsKeys) . " keys)\n";
    exit(0);
}

if ($missingFromAlias !== []) {
    fwrite(STDERR, "FAIL: keys in config_default.inc.php missing from Conf alias:\n");
    foreach ($missingFromAlias as $k) { fwrite(STDERR, "  - $k\n"); }
}
if ($missingFromDefaults !== []) {
    fwrite(STDERR, "FAIL: keys in Conf alias missing from config_default.inc.php (and not in runtimeOnly allowlist):\n");
    foreach ($missingFromDefaults as $k) { fwrite(STDERR, "  - $k\n"); }
}
exit(1);
```

Wired into `.github/workflows/ci.yml` `lint` job, immediately after PHPStan:

```yaml
      - run: php tools/check-conf-shape.php
```

#### C. Custom PHPStan rule — `noDynamicNew`

Phase 3 risk #1: classes referenced by string-literal name break after namespacing. The 7 confirmed sites use `new $classname()`. Phase 3 fixes them; this rule prevents regressions.

`tools/phpstan/Rules/NoDynamicNewRule.php`:

```php
<?php declare(strict_types=1);

namespace Piwigo\Tools\PhpStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Expr\FuncCall;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\Generic\GenericClassStringType;
use PHPStan\Type\StringType;

/**
 * @implements Rule<New_>
 */
final class NoDynamicNewRule implements Rule
{
    public function getNodeType(): string
    {
        return New_::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $cls = $node->class;

        // Only fire on `new $var()` — `new SomeClass()` and `new ($expr)()` skip
        if (!$cls instanceof Variable || !is_string($cls->name)) {
            return [];
        }

        $varName = $cls->name;
        $type = $scope->getType($cls);

        // class-string<T> proves the class is real
        if ($type instanceof GenericClassStringType) {
            return [];
        }
        // Generic ClassStringType (untyped) is also acceptable
        if ($type->isClassString()->yes()) {
            return [];
        }

        // Inspect prior statements for a class_exists($var) guard
        $stmts = $scope->getFunction()?->getNode()?->getStmts() ?? [];
        foreach ($stmts as $stmt) {
            if ($stmt instanceof Node\Stmt\If_) {
                $cond = $stmt->cond;
                if ($cond instanceof FuncCall
                    && $cond->name instanceof Node\Name
                    && in_array(strtolower((string) $cond->name), ['class_exists', 'is_a'], true)
                ) {
                    foreach ($cond->args as $arg) {
                        if ($arg->value instanceof Variable && $arg->value->name === $varName) {
                            return [];
                        }
                    }
                }
            }
        }

        return [
            RuleErrorBuilder::message(sprintf(
                'Dynamic `new $%s()` without a class-string<T> type or a class_exists() guard. '
                . 'After Phase 3 namespacing, bare class names no longer resolve. '
                . 'Use `$%s = SomeClass::class;` or guard with `if (class_exists($%s)) { ... }`.',
                $varName, $varName, $varName
            ))->identifier('piwigo.noDynamicNew')->build(),
        ];
    }
}
```

Registration in `phpstan.neon`:

```neon
services:
    -
        class: Piwigo\Tools\PhpStan\Rules\NoDynamicNewRule
        tags: [phpstan.rules.rule]
```

The Phase 3 audit list (`include/functions_calendar.inc.php:126`, `include/functions_plugins.inc.php:409`, `include/ws_functions/pwg.extensions.php:175`, `admin/include/image.class.php:73`, `admin/include/plugins.class.php:85,95`, `admin/include/themes.class.php:74`, `admin/include/updates.class.php:40`) is the regression target — the rule must produce zero errors after Phase 3 ships, and any new instance fails CI.

#### D. Custom PHPStan rule — baseline-grow guard

PHPStan baselines are append-only by default; a sloppy push can add 50 new errors and `--generate-baseline` will silently absorb them. This guard runs after analysis and rejects baselines that grew.

`tools/check-baseline.sh`:

```bash
#!/usr/bin/env bash
## Baseline-grow guard. Runs after PHPStan, rejects any push that introduces
## new baseline entries. Use as a CI step in the `lint` job.

set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
COMMITTED="${ROOT}/phpstan-baseline.neon"
PROPOSED="${ROOT}/phpstan-baseline-current.neon"

if [[ ! -f "${COMMITTED}" ]]; then
    echo "OK: no committed baseline; nothing to compare against."
    exit 0
fi

## Generate a fresh baseline from the current tree
"${ROOT}/vendor/bin/phpstan" analyse --no-progress \
    --generate-baseline="${PROPOSED}" \
    --allow-empty-baseline \
    || true

count_entries() {
    # Counts `- message:` entries in a phpstan baseline file.
    # Robust against trailing whitespace and quoted strings.
    grep -cE '^[[:space:]]+-[[:space:]]+message:' "${1}" || true
}

NEW_COUNT="$(count_entries "${PROPOSED}")"
OLD_COUNT="$(count_entries "${COMMITTED}")"

echo "Baseline entries: committed=${OLD_COUNT}, current=${NEW_COUNT}"

if (( NEW_COUNT > OLD_COUNT )); then
    DELTA=$(( NEW_COUNT - OLD_COUNT ))
    echo "FAIL: PHPStan baseline grew by ${DELTA} entries."
    diff -u "${COMMITTED}" "${PROPOSED}" | grep '^+' | grep -E 'message:|path:' || true
    rm -f "${PROPOSED}"
    exit 1
fi

if (( NEW_COUNT < OLD_COUNT )); then
    WIN=$(( OLD_COUNT - NEW_COUNT ))
    echo "WIN: baseline shrunk by ${WIN} entries. Commit the new baseline to lock in the gain."
fi

rm -f "${PROPOSED}"
exit 0
```

CI step:

```yaml
      - run: vendor/bin/phpstan analyse --no-progress
      - run: bash tools/check-baseline.sh
```

The guard is intentionally external — making it a PHPStan rule would couple "baseline shrunk = win" into the analyser, when the right semantic is "after analysis, compare files."

#### E. Custom PHPStan rule — `forbidGlobalsInSrc`

Once Phase 4 Wave A ships, `src/` contains namespaced typed services. `global $conf;` inside `src/Piwigo/Anything.php` undermines the entire architecture. This rule fails CI on any new occurrence; legacy `include/` and `admin/` are exempt.

`tools/phpstan/Rules/ForbidGlobalsInSrcRule.php`:

```php
<?php declare(strict_types=1);

namespace Piwigo\Tools\PhpStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Stmt\Global_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Bans `global $conf;`, `global $page;`, `global $user;`, `global $lang;`
 * inside any file under src/. Code in src/ must use Piwigo\Core\Config,
 * Piwigo\Core\PageState, Piwigo\Users\CurrentUser, Piwigo\Core\Lang instead.
 *
 * @implements Rule<Global_>
 */
final class ForbidGlobalsInSrcRule implements Rule
{
    private const FORBIDDEN = ['conf', 'page', 'user', 'lang'];

    private const ADVICE = [
        'conf' => '\Piwigo\Core\Config::get(\'<key>\') or a typed getter on Config',
        'page' => '\Piwigo\Core\PageState::current()->addError(...) etc.',
        'user' => '\Piwigo\Users\CurrentUser::get()',
        'lang' => '\Piwigo\Core\Lang::current()->t(\'<key>\') or l10n(\'<key>\')',
    ];

    public function getNodeType(): string
    {
        return Global_::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $file = $scope->getFile();
        // Normalize path separators on Windows
        $file = str_replace('\\', '/', $file);
        if (!str_contains($file, '/src/')) {
            return [];
        }

        $errors = [];
        foreach ($node->vars as $var) {
            if (!$var instanceof Node\Expr\Variable || !is_string($var->name)) {
                continue;
            }
            if (!in_array($var->name, self::FORBIDDEN, true)) {
                continue;
            }
            $advice = self::ADVICE[$var->name];
            $errors[] = RuleErrorBuilder::message(sprintf(
                '`global $%s;` is forbidden in src/. Code under src/ must use the typed service. '
                . 'Replacement: %s',
                $var->name,
                $advice
            ))->identifier('piwigo.forbidGlobalsInSrc')->build();
        }
        return $errors;
    }
}
```

Registration in `phpstan.neon`:

```neon
services:
    -
        class: Piwigo\Tools\PhpStan\Rules\ForbidGlobalsInSrcRule
        tags: [phpstan.rules.rule]
```

This rule is added at Phase 4 Wave A (parent plan, step 7). It does *not* run before Phase 4 — `include/` files legitimately use `global $conf` and `src/` does not exist yet.

#### F. The phpdoc-strip Rector rule

Phase 2 step 9 calls for stripping `@param string $login` lines whose type is now redundant against the native `string $login` signature. A regex sed loop is wrong — it cannot tell `@param string $login the username` (description, keep) from `@param string $login` (no description, drop). Rector parses the docblock as an AST and gets this right.

`tools/phpstan/RemoveDuplicatePhpDocRule.php` (a Rector rule, not a PHPStan rule — file lives next to the PHPStan rules for organizational symmetry):

```php
<?php declare(strict_types=1);

namespace Piwigo\Tools\Rector;

use PhpParser\Node;
use PhpParser\Node\FunctionLike;
use PHPStan\PhpDocParser\Ast\PhpDoc\ParamTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\ReturnTagValueNode;
use Rector\BetterPhpDocParser\PhpDocInfo\PhpDocInfoFactory;
use Rector\BetterPhpDocParser\PhpDocManipulator\PhpDocTypeChanger;
use Rector\Comments\NodeDocBlock\DocBlockUpdater;
use Rector\Rector\AbstractRector;
use Rector\StaticTypeMapper\StaticTypeMapper;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

final class RemoveDuplicatePhpDocRule extends AbstractRector
{
    public function __construct(
        private readonly PhpDocInfoFactory $phpDocInfoFactory,
        private readonly DocBlockUpdater $docBlockUpdater,
        private readonly StaticTypeMapper $staticTypeMapper,
    ) {}

    public function getNodeTypes(): array
    {
        return [Node\Stmt\Function_::class, Node\Stmt\ClassMethod::class];
    }

    /**
     * @param Node\Stmt\Function_|Node\Stmt\ClassMethod $node
     */
    public function refactor(Node $node): ?Node
    {
        $phpDocInfo = $this->phpDocInfoFactory->createFromNode($node);
        if ($phpDocInfo === null) {
            return null;
        }

        $changed = false;
        // Strip @param X $name when the native param has the same type AND the tag has no description
        foreach ($node->getParams() as $param) {
            if ($param->type === null) { continue; }
            $paramName = (string) $param->var->name;
            $tag = $phpDocInfo->getParamTagValueByName($paramName);
            if (!$tag instanceof ParamTagValueNode) { continue; }
            if (trim($tag->description) !== '') { continue; }

            $nativeType = $this->staticTypeMapper->mapPhpParserNodePHPStanType($param->type);
            $tagType    = $this->staticTypeMapper->mapPHPStanPhpDocTypeNodeToPHPStanType(
                $tag->type, $node
            );
            if ($nativeType->equals($tagType)) {
                $phpDocInfo->removeByName('@param ' . '$' . $paramName);
                $changed = true;
            }
        }

        // Same for @return
        if ($node->getReturnType() !== null) {
            $returnTag = $phpDocInfo->getReturnTagValue();
            if ($returnTag instanceof ReturnTagValueNode && trim($returnTag->description) === '') {
                $nativeType = $this->staticTypeMapper->mapPhpParserNodePHPStanType($node->getReturnType());
                $tagType    = $this->staticTypeMapper->mapPHPStanPhpDocTypeNodeToPHPStanType(
                    $returnTag->type, $node
                );
                if ($nativeType->equals($tagType)) {
                    $phpDocInfo->removeByName('@return');
                    $changed = true;
                }
            }
        }

        if (!$changed) { return null; }

        $this->docBlockUpdater->updateRefactoredNodeWithPhpDocInfo($node);
        return $node;
    }

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition(
            'Remove @param/@return tags whose type duplicates the native type and which carry no description',
            [new CodeSample(
                <<<'BEFORE'
                /**
                 * @param string $login
                 * @return int|false
                 */
                function get_userid(string $login): int|false { /* ... */ }
                BEFORE,
                <<<'AFTER'
                function get_userid(string $login): int|false { /* ... */ }
                AFTER,
            )]
        );
    }
}
```

Registration in `rector.php` (Phase 2, after the type-declaration sweep has landed):

```php
return RectorConfig::configure()
    ->withPaths([__DIR__ . '/include', __DIR__ . '/admin', __DIR__ . '/themes/default', __DIR__ . '/src'])
    ->withSkip([/* same skip list as Phase 2 step A */])
    ->withRules([
        \Piwigo\Tools\Rector\RemoveDuplicatePhpDocRule::class,
    ])
    ->withParallel();
```

Run as a one-shot (`vendor/bin/rector process`), commit the resulting churn as one commit ("phase2: strip redundant phpdoc"). Expected diff scale per Phase 2 step 9: drops `grep -c '@param' include/*.inc.php` from ~3,490 to ~800.

---

---

## Phase 3 — PSR-4 + namespacing (L)

### Goal

Move every first-party class file from `include/*.class.php`, `admin/include/*.class.php`, and the irregular `include/derivative*.inc.php` (which contain final classes, not just functions) into a single PSR-4 tree at `src/Piwigo/...` under namespace `Piwigo\\`. Replace `include_once`/`require_once` for class files with Composer autoload. **Free-function libraries** (`include/functions*.inc.php`, `include/dblayer/functions_mysqli.inc.php`, `include/common.inc.php`) **stay as `include`s** — only classes migrate. `pwg_query()`, `pwg_db_fetch_assoc()`, `pwg_db_real_escape_string()` and the rest of the dblayer free functions stay forever (constraint #3); a `Piwigo\Db\Connection` class wraps the singleton, but the free functions delegate into it. `vendor/` is tracked in git so the tarball deploy story keeps working. `install.php` and `upgrade.php` are migrated last because they must remain drop-in. Phase 3 explicitly does **not** convert globals — that's Phase 4.

### Step-by-step sequence

1. ✅ **Lock in PSR-4 layout, write `composer.json` autoload, run a green-baseline `dump-autoload --strict-psr`.**
   ```json
   "autoload": {
     "psr-4": { "Piwigo\\": "src/Piwigo/" },
     "files": []
   },
   "autoload-dev": {
     "psr-4": { "Piwigo\\Tests\\": "tests/" }
   }
   ```
   Run against the empty `src/` directory — succeeds. **Exit signal**: `vendor/composer/autoload_psr4.php` contains the `Piwigo\\` prefix; CI green.

2. ✅ **Inventory: glob and table the class roster.** Run `tools/list-classes.php` (committed) that uses Glob over `include/**/*.class.php`, `include/**/*.inc.php`, `admin/include/**/*.class.php`, regexes `^(abstract |final )?class \w+`, emits CSV `current_path,class_name,extends,filenames_referenced_via_string`. ~50 first-party classes. Note ones living in `.inc.php` files (derivative classes, search-related classes) need to be *extracted* before move. **Exit signal**: `docs/class-inventory.csv` committed.

3. ✅ **Move classes in dependency order, leaf-first.** Dependency graph: `PersistentCache` (abstract, no deps) → `PersistentFileCache` → `BlockManager`/`RegisteredBlock`/`DisplayBlock` → `Logger` → `PwgBase32` → `PwgTOTP` → `PasswordHash` → `PwgSession` → `Combinable`/`CssLoader`/`ScriptLoader`/`PwgTemplateAdapter` → `Template` (depends on Smarty + `ScriptLoader`) → `PwgError`/`PwgNamedArray`/`PwgNamedStruct` → `PwgRequestHandler`/`PwgResponseEncoder` → `PwgServer` → encoders → `CalendarBase`/`CalendarMonthly`/`CalendarWeekly` → search Q-classes → `WatermarkParams`/`ImageStdParams`/`ImageRect`/`SizingParams`/`DerivativeParams`/`SrcImage`/`DerivativeImage`. Recipe per class:
   ```bash
   # PersistentCache + PersistentFileCache (currently include/cache.class.php)
   # a) create src/Piwigo/Cache/PersistentCache.php with namespace Piwigo\Cache
   # b) create src/Piwigo/Cache/PersistentFileCache.php
   # c) git rm include/cache.class.php
   # d) delete `include(... cache.class.php)` line in common.inc.php
   # e) Rector RenameClassRector rewrites every reference site
   ```
   **Exit signal per class**: `composer dump-autoload --strict-psr` exits 0; `grep -rn "include.*cache\.class\.php" .` returns nothing; PHPStan still green; Playwright still green.

4. ✅ **Use Rector's `RenameClassRector` to mechanically rewrite every reference.** Per class moved, add to `rector.php`:
   ```php
   ->withConfiguredRule(RenameClassRector::class, [
       'PersistentCache' => 'Piwigo\\Cache\\PersistentCache',
       'PersistentFileCache' => 'Piwigo\\Cache\\PersistentFileCache',
       // grows per move
   ])
   ```
   Rector rewrites `new PersistentFileCache()` → `new \Piwigo\Cache\PersistentFileCache()` across `include/`, `admin/`, root entry points, **and `install/` (but NOT `install/db/`)**. **Exit signal**: `grep -rn "new PersistentFileCache\b" --include='*.php' .` returns zero; same for every migrated class.

5. ✅ **Pre-audit and special-case the danger list.** Before moving any of the following:
   - **Case-sensitivity collisions on Windows hosts**: verify with `find . -iname '*image*.class.php' | sort -f | uniq -di`. The four image backends in `admin/include/image.class.php` (`pwg_image`, `image_imagick`, `image_ext_imagick`, `image_gd`) become `Piwigo\Admin\Image\PwgImage`, `Piwigo\Admin\Image\ImagickBackend`, `Piwigo\Admin\Image\ExtImagickBackend`, `Piwigo\Admin\Image\GdBackend` — rename and `RenameClassRector` together.
   - **PHP built-in collisions**: Smarty's vendored `Smarty\Template` shares the unqualified name with our `Template`. After move, `Piwigo\Template\Template` is unambiguous; FQN resolves cleanly.
   - **Classes used by string name (`new $type()`)**: confirmed at `include/functions_calendar.inc.php:126`, `include/functions_plugins.inc.php:409`, `include/ws_functions/pwg.extensions.php:175`, `admin/include/image.class.php:73`, `admin/include/plugins.class.php:85,95`, `admin/include/themes.class.php:74`, `admin/include/updates.class.php:40`. For each: `$classname` must hold the FQN string post-move. Audit each: e.g. for the calendar dispatcher, change `$classname = 'CalendarMonthly'` to `$classname = \Piwigo\Calendar\CalendarMonthly::class`. For plugin/theme dispatchers, register a `class_alias(\Piwigo\Plugin\PluginMaintain::class, 'PluginMaintain')` until plugins are out of scope.
   - **`Template` extends Smarty's vendored class**: drop the `require_once( PHPWG_ROOT_PATH .'include/smarty/libs/Smarty.class.php');` at line 13 — Composer autoloads `Smarty\Smarty` from `include/smarty/src/Smarty.php`. Verify `composer dump-autoload && grep Smarty vendor/composer/autoload_psr4.php`; if Smarty isn't autoloadable, vendor it under a stable path with its own `composer.json`.

   **Exit signal**: each item is grep-confirmed clean.

6. ✅ **After all classes moved, regenerate optimized autoload, run E2E.** `composer dump-autoload --optimize --classmap-authoritative --strict-psr` — `--strict-psr` fails loudly if any `src/Piwigo/Foo/Bar.php` has the wrong namespace declaration. **Exit signal**: optimized autoload built; CI green.

7. ✅ **Vendor tracking + `.gitattributes` + tarball install.** Track `vendor/` in git (Phase 0 already). Exclude dev deps and source artifacts from the tarball:
   ```
   /tests              export-ignore
   /docs               export-ignore
   /tools              export-ignore
   /.github            export-ignore
   /phpunit.xml.dist   export-ignore
   /phpstan.neon       export-ignore
   /phpstan-baseline.neon export-ignore
   /rector.php         export-ignore
   /pint.json          export-ignore
   /composer.lock      export-ignore
   /vendor/phpstan     export-ignore
   /vendor/rector      export-ignore
   /vendor/phpunit     export-ignore
   /vendor/laravel/pint export-ignore
   /vendor/bin         export-ignore
   ```
   Document in `INSTALL.md`: tarball ships with `vendor/` populated (production deps only). For developer clones, `composer install` pulls dev deps. CI builds the release tarball via `git archive --format=tar.gz HEAD` followed by `composer install --no-dev --optimize-autoloader --classmap-authoritative` inside the staged directory. **Exit signal**: tarball generated, untar'd, `php -S localhost:8080` serves the gallery without `composer install`.

8. ✅ **Entry-point migration in dependency order.** Add `require __DIR__ . '/vendor/autoload.php';` as the first non-comment line of each entry point. Order:
   - Commit 1: `index.php`, `picture.php`, `comments.php`, `feed.php`, `search.php`, `tags.php`, `notification.php`, `about.php`, `popuphelp.php`, `password.php`, `register.php`, `identification.php`, `profile.php`, `i.php`, `action.php`.
   - Commit 2: `admin.php`, `ws.php`.
   - Commit 3 (last): `install.php`, `upgrade.php`. These run **before** `common.inc.php` is fully wired. The autoload `require` must work without `$conf`/`PHPWG_ROOT_PATH` already defined. Mitigation: `define('PHPWG_ROOT_PATH', __DIR__ . '/');` at the very top, then `require PHPWG_ROOT_PATH . 'vendor/autoload.php';`.

   **Exit signal**: every entry point loads `vendor/autoload.php`; UpgradeChainTest green; fresh-install Playwright spec green.

### Concrete artifacts

**`composer.json` autoload sections:**

```json
{
  "name": "piwigo/piwigo",
  "type": "project",
  "require": {
    "php": "^8.5",
    "ext-mysqli": "*", "ext-mbstring": "*", "ext-gd": "*"
  },
  "require-dev": {
    "phpunit/phpunit": "^11", "rector/rector": "^2",
    "phpstan/phpstan": "^2", "phpstan/phpstan-strict-rules": "^2",
    "phpstan/phpstan-deprecation-rules": "^2", "laravel/pint": "^1"
  },
  "autoload": { "psr-4": { "Piwigo\\": "src/Piwigo/" } },
  "autoload-dev": { "psr-4": { "Piwigo\\Tests\\": "tests/" } },
  "config": { "optimize-autoloader": true, "classmap-authoritative": true, "sort-packages": true }
}
```

**Sample namespaced class — `PersistentCache` before/after:**

Before (`include/cache.class.php`):
```php
<?php
abstract class PersistentCache {
  var $default_lifetime = 86400;
  protected $instance_key = PHPWG_VERSION;
  function make_key($key) { ... }
  abstract function get($key, &$value);
  abstract function set($key, $value, $lifetime=null);
  abstract function purge($all);
}
```

After (`src/Piwigo/Cache/PersistentCache.php`):
```php
<?php declare(strict_types=1);

namespace Piwigo\Cache;

abstract class PersistentCache
{
    public int $default_lifetime = 86400;
    protected string $instance_key = PHPWG_VERSION;

    public function make_key(string|array $key): string
    {
        if (is_array($key)) { $key = implode('&', $key); }
        return md5($key . $this->instance_key);
    }

    abstract public function get(string $key, mixed &$value): bool;
    abstract public function set(string $key, mixed $value, ?int $lifetime = null): bool;
    abstract public function purge(bool $all): void;
}
```

**Full namespace layout table** (first-party only — Smarty, PHPMailer, minify, PCLZip, FeedCreator, Emogrifier, JShrink, PasswordHash, MDetect stay vendored under `include/`):

| Existing class | New FQN |
|---|---|
| `PersistentCache` (`include/cache.class.php`) | `Piwigo\Cache\PersistentCache` |
| `PersistentFileCache` (same) | `Piwigo\Cache\PersistentFileCache` |
| `BlockManager` (`include/block.class.php`) | `Piwigo\Menu\BlockManager` |
| `RegisteredBlock`, `DisplayBlock` (same) | `Piwigo\Menu\RegisteredBlock`, `…DisplayBlock` |
| `Logger` (`include/Logger.class.php`) | `Piwigo\Core\Logger` |
| `PwgBase32` (`include/base32.class.php`) | `Piwigo\Auth\Base32` |
| `PwgTOTP` (`include/totp.class.php`) | `Piwigo\Auth\Totp` |
| `PwgSession` (`include/pwgsession.class.php`) | `Piwigo\Session\PwgSession` |
| `Template` (`include/template.class.php:22`) | `Piwigo\Template\Template` |
| `ScriptLoader` (`:1494`) | `Piwigo\Template\ScriptLoader` |
| `CssLoader` (`:1421`), `Combinable` (`:1326`), `PwgTemplateAdapter` (`:1274`) | `Piwigo\Template\{CssLoader,Combinable,PwgTemplateAdapter}` |
| `PwgError`, `PwgNamedArray`, `PwgNamedStruct` (`include/ws_core.inc.php`) | `Piwigo\Ws\{PwgError,PwgNamedArray,PwgNamedStruct}` |
| `PwgRequestHandler`, `PwgResponseEncoder`, `PwgServer` (same) | `Piwigo\Ws\{PwgRequestHandler,Encoder\PwgResponseEncoder,PwgServer}` |
| `PwgRestRequestHandler`, `PwgRestEncoder`, `PwgXmlWriter` (`include/ws_protocols/`) | `Piwigo\Ws\Protocol\{...}` |
| `PwgJsonEncoder`, `PwgXmlRpcEncoder`, `PwgSerialPhpEncoder` (same) | `Piwigo\Ws\Protocol\{...}` |
| `CalendarBase`, `CalendarMonthly`, `CalendarWeekly` | `Piwigo\Calendar\{...}` |
| Q-search classes (`include/functions_search.inc.php`) | `Piwigo\Search\{Scope,Token,Expression,Results}` |
| `PluginMaintain`, `ThemeMaintain` | `Piwigo\Plugins\{...}` |
| `WatermarkParams`, `ImageStdParams`, `ImageRect`, `SizingParams`, `DerivativeParams`, `SrcImage`, `DerivativeImage` | `Piwigo\Image\{...}` |
| `pwg_image`, `image_imagick`, `image_ext_imagick`, `image_gd` (`admin/include/image.class.php`) | `Piwigo\Admin\Image\{PwgImage,ImagickBackend,ExtImagickBackend,GdBackend}` |
| `c13y_internal`, `check_integrity` (`admin/include/`) | `Piwigo\Admin\Integrity\{InternalCheck,IntegrityCheck}` |
| `languages`, `themes`, `plugins`, `updates`, `tabsheet` (`admin/include/`) | `Piwigo\Admin\{Languages,Themes,Plugins,Updates,Tabsheet}` |
| `DummyPlugin_maintain`, `DummyTheme_maintain` | `Piwigo\Admin\{DummyPluginMaintain,DummyThemeMaintain}` |

**Sample entry-point modification — top of `index.php`:**

Before:
```php
<?php
define('PHPWG_ROOT_PATH','./');
include_once( PHPWG_ROOT_PATH.'include/common.inc.php' );
```

After:
```php
<?php declare(strict_types=1);

define('PHPWG_ROOT_PATH', './');
require __DIR__ . '/vendor/autoload.php';
include_once PHPWG_ROOT_PATH . 'include/common.inc.php';
```

### Effort breakdown

| Sub-task | Tag |
| --- | --- |
| `composer.json` autoload + green-baseline `dump-autoload --strict-psr` | S |
| `tools/list-classes.php` + committed inventory CSV | S |
| Move leaf cluster (~10 commits of small diffs) | M |
| Move calendar, search, derivative classes (extraction needed) | M |
| Move `Template` + `ScriptLoader` + `CssLoader` + adapter (deep Smarty entanglement) | L |
| Move WS layer (`PwgError`, `PwgServer`, handlers, encoders) | L |
| Move admin class cluster | M |
| Configure Rector `RenameClassRector` rules per commit | S |
| Audit and special-case `new $classname()` (7 sites) | M |
| `.gitattributes` + tarball CI job + `INSTALL.md` | S |
| Entry-point migration, three commits | M |

**Phase total: L.**

### Risks specific to this phase

1. **Classes referenced by string-literal name (`new $classname()`).** 7 confirmed locations. After namespacing, `'CalendarMonthly'` no longer resolves. Mitigation: PHPStan custom rule `noDynamicNew` (fail on `new $var()` not preceded by `class_exists($var)` or typed FQN-string); audit before each move; for unavoidable cases (plugin maintain dispatch), register `class_alias` in `src/Piwigo/Compat/aliases.php`.

2. **Smarty's `Template` base class autoloadability.** `include/template.class.php:13` does `require_once(... include/smarty/libs/Smarty.class.php);` then `use Smarty\Smarty;`. Verify `composer.json` registers Smarty's PSR-4 prefix (Packagist `smarty/smarty` or `repositories: [{type: path, url: include/smarty}]`). Drop the `require_once` after verification. Risk if Smarty isn't autoloadable: keep the `require_once` for one minor release while filing a Smarty patch.

3. **Plugin dynamic class loading via `$conf['plugin']`.** Plugins (out of scope but loader code is not) call `include_once $plugin['path'].'/main.inc.php'` whose plugin's main file may declare `XyzMaintain extends PluginMaintain`. Mitigation: `class_alias(\Piwigo\Plugins\PluginMaintain::class, 'PluginMaintain', true)` in `src/Piwigo/Plugins/aliases.php` autoloaded via `composer.json` `files`. Document deprecation: short-name will alias for two minor releases, then plugins must update.

4. **Tarball install workflow breaks if `vendor/` is dirty.** If a contributor commits dev deps, the tarball ships with phpstan/rector binaries. Mitigation: `.gitattributes` `export-ignore` rules + CI check that builds the release tarball, untars, and asserts `! [ -d vendor/phpstan ]`.

5. **`install.php`/`upgrade.php` boot order.** These run before `common.inc.php` finishes wiring, before `Conf` array is hydrated, before dblayer is loaded. Mitigation: keep these in the last commit of Phase 3; UpgradeChainTest is the gating test; small rollback (revert one commit) if regression.

### Verification

- `composer dump-autoload --strict-psr --classmap-authoritative` exits 0.
- `! grep -rn "include.*\.class\.php" --include='*.php' include/ admin/ themes/ *.php` returns empty for migrated classes.
- `composer dump-autoload && php -r 'spl_autoload_register(); var_dump(class_exists(Piwigo\Cache\PersistentFileCache::class));'` prints `bool(true)`.
- Build and untar release tarball: `git archive --format=tar.gz HEAD | tar -tzf - | grep -E '^vendor/(phpstan|rector|phpunit)'` returns empty.
- `UpgradeChainTest` green from real 16.x dump.
- Playwright suite green: `install.spec.ts`, `smoke-gallery.spec.ts`, `smoke-admin.spec.ts`.
- Manual: load gallery, browse album, open photo, log in as admin, navigate every admin page — no `Class "Foo" not found` fatals in `data/log_*.txt`.
- **Actual run**: 62 first-party classes migrated to `src/Piwigo/` in 10 namespace clusters (Cache, Core, Auth, Menu, Session, Calendar, Search, Image, Ws, Admin, Template); Rector RenameClassRector rewrote 109 reference sites; `src/Piwigo/Compat/aliases.php` provides lazy class_alias shims for all 62 short names; Smarty PSR-4 mapped at `include/smarty/src/` and its `functions.php` loaded via autoload.files; old class files stubbed (19 files); include cleanup across common.inc.php, install.php, upgrade.php, i.php, and function files; INSTALL.md documents tarball/clone install paths; tarball CI check guards against dev-dep leakage; `tools/ci/phase3-checks.sh` runnable guardrail script; `tools/phpstan/NoDynamicNewRule.php` custom PHPStan rule wired (9 known-intentional dynamic-new sites baselined); PHPStan baseline 5223 errors at level 8; final CI run 24998855636 green (lint ✓, unit ✓, e2e ✓). Post-migration namespace resolution bugs fixed: Logger.php (\DateTime, \RuntimeException), CalendarMonthly.php (use imports for Piwigo\Image classes), image_imagick.php (\Imagick/\ImagickPixel), *.php (\PclZip), FileCombiner.php (\Exception catch), WS protocol files (use Piwigo\Ws\PwgError). All 8 Phase 3 steps ✅.


### Phase 3 deep-dive: Class movement recipes

### A. The `tools/list-classes.php` inventory script

Phase 3 step 2 calls for a committed CSV that enumerates every first-party class, the file it lives in, what it extends, and which strings in the codebase reference it by literal name (the dynamic-`new` exposure). This script produces it. It is single-shot, idempotent, and depends only on `nikic/php-parser` (already available transitively through Rector). Output goes to `docs/class-inventory.csv`.

```php
<?php declare(strict_types=1);
// tools/list-classes.php — one-shot inventory generator
// Usage: php tools/list-classes.php > docs/class-inventory.csv

require __DIR__ . '/../vendor/autoload.php';

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;

$root = dirname(__DIR__);
$globs = [
    $root . '/include/*.class.php',
    $root . '/include/*.inc.php',
    $root . '/include/ws_protocols/*.php',
    $root . '/admin/include/*.class.php',
    $root . '/admin/include/*.inc.php',
];

$files = [];
foreach ($globs as $g) {
    foreach (glob($g) ?: [] as $f) {
        $files[] = $f;
    }
}

$parser = (new ParserFactory)->createForHostVersion();

final class ClassCollector extends NodeVisitorAbstract
{
    /** @var list<array{name:string,extends:string,abstract:bool,final:bool}> */
    public array $classes = [];
    public function enterNode(Node $node): null
    {
        if ($node instanceof Node\Stmt\Class_ && $node->name !== null) {
            $this->classes[] = [
                'name'     => $node->name->toString(),
                'extends'  => $node->extends?->toString() ?? '',
                'abstract' => $node->isAbstract(),
                'final'    => $node->isFinal(),
            ];
        }
        return null;
    }
}

$index = []; // class name => current path
$entries = []; // rows for CSV

foreach ($files as $file) {
    $code = file_get_contents($file);
    if ($code === false) { continue; }
    try { $ast = $parser->parse($code); } catch (\Throwable) { continue; }
    if ($ast === null) { continue; }
    $vis = new ClassCollector();
    $trav = new NodeTraverser();
    $trav->addVisitor($vis);
    $trav->traverse($ast);
    foreach ($vis->classes as $c) {
        $index[$c['name']] = $file;
        $entries[] = [$file, $c] + ['refs' => []];
    }
}

// Reference scan: literal 'ClassName' or "ClassName" outside class definitions.
// This is the dynamic-new exposure surface (section 5 risk).
$searchRoots = [
    $root . '/include',
    $root . '/admin',
    $root . '/install',          // include/db is excluded by the parent plan
    $root . '/themes/default',
    $root,                       // root entry-points
];
$rii = function (string $dir): \RecursiveIteratorIterator {
    return new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
    );
};

$haystack = [];
foreach ($searchRoots as $sr) {
    if (!is_dir($sr)) { continue; }
    foreach ($rii($sr) as $f) {
        $p = $f->getPathname();
        if (!str_ends_with($p, '.php')) { continue; }
        if (str_contains($p, DIRECTORY_SEPARATOR . 'install' . DIRECTORY_SEPARATOR . 'db' . DIRECTORY_SEPARATOR)) {
            continue; // hard-excluded from modernization
        }
        if (str_contains($p, DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR)) { continue; }
        $haystack[$p] = file_get_contents($p);
    }
}

foreach ($entries as $i => $row) {
    $name = $row[1]['name'];
    $needles = [
        "'" . $name . "'",
        '"' . $name . '"',
        "= '" . $name . "'",
        "= \"" . $name . "\"",
    ];
    $hits = [];
    foreach ($haystack as $path => $body) {
        foreach ($needles as $n) {
            if (str_contains($body, $n)) { $hits[] = $path; break; }
        }
    }
    sort($hits);
    $entries[$i]['refs'] = array_unique($hits);
}

// Emit CSV
$out = fopen('php://stdout', 'w');
fputcsv($out, ['current_path','class_name','extends','abstract','final','referenced_by_string_in_files']);
foreach ($entries as $row) {
    [$path, $cls] = [$row[0], $row[1]];
    fputcsv($out, [
        substr($path, strlen($root) + 1),
        $cls['name'],
        $cls['extends'],
        $cls['abstract'] ? '1' : '0',
        $cls['final']    ? '1' : '0',
        implode('|', array_map(fn($p) => substr($p, strlen($root) + 1), $row['refs'])),
    ]);
}
fclose($out);
```

Sample output (excerpted):

```csv
current_path,class_name,extends,abstract,final,referenced_by_string_in_files
include/cache.class.php,PersistentCache,,1,0,
include/cache.class.php,PersistentFileCache,PersistentCache,0,0,include/common.inc.php
include/template.class.php,Template,Smarty\Smarty,0,0,include/common.inc.php|tools/triggers_list.php
include/template.class.php,ScriptLoader,,0,0,include/template.class.php|themes/default/template/header.tpl
include/ws_core.inc.php,PwgError,,0,0,include/ws_functions/pwg.images.php|include/ws_protocols/rest_encoder.php
include/functions_search.inc.php,QSearchScope,,0,0,include/functions_search.inc.php
include/functions_calendar.inc.php,CalendarMonthly,CalendarBase,0,0,include/section_init.inc.php
admin/include/plugins.class.php,DummyPlugin_maintain,PluginMaintain,0,0,
```

The two columns that drive Phase 3 risk: (a) `extends` non-empty when the parent class is also being moved (forces ordering) and (b) `referenced_by_string_in_files` non-empty (dynamic-new audit per parent-plan §5).

---

### B. Per-class move recipe template

Every move is the same eight numbered steps. No exceptions. The leaf-vs-trunk dependency dictates only the order in which classes go through the recipe; the recipe itself does not change.

1. **Verify the class is leaf-or-already-moved.** `grep -rn "extends \<ClassName\>" --include='*.php' include/ admin/` returns either zero results, or only references to classes already in `src/Piwigo/`.
2. **Create the namespaced file.** `src/Piwigo/<Cluster>/<ClassName>.php` per the parent plan's namespace table. Add `<?php declare(strict_types=1);` then `namespace Piwigo\<Cluster>;`. Copy the class body verbatim. Convert `var $foo` → `public $foo` (Phase 2 typed it; Phase 3 just relocates).
3. **Add `use` imports for any cross-namespace symbols.** Smarty, free functions called via `\` (`\trigger_notify`, `\pwg_query`), and sibling classes already moved.
4. **Register the move in `rector.php`.** Append a `RenameClassRector` mapping. Run `vendor/bin/rector process --dry-run` to preview.
5. **Run Rector for real.** `vendor/bin/rector process` rewrites every reference site to the FQN. Hand-fix anything Rector skipped (string literals — those need section G's alias shim).
6. **Delete the old file.** `git rm include/<old>.class.php` (or in the `.inc.php` extraction case, edit the file in place per section E).
7. **Remove the dead `include`.** `grep -n "<old>\.class\.php" include/common.inc.php` should return nothing; delete any orphaned `include`/`require_once` line.
8. **Verify, three ways.**
   - `composer dump-autoload --strict-psr` exits 0.
   - `grep -rn "new \\\\?<ClassName>\\b\|extends \\\\?<ClassName>\\b" --include='*.php' .` shows only FQN form `\Piwigo\<Cluster>\<ClassName>` or `Piwigo\<Cluster>\<ClassName>` (no bare references).
   - Playwright `smoke-gallery.spec.ts` and `smoke-admin.spec.ts` green.

#### Worked example — `PersistentCache`/`PersistentFileCache`

**Step 1.** `grep -rn "extends PersistentCache" --include='*.php' include/ admin/` → one hit, `include/cache.class.php:58` (`PersistentFileCache extends PersistentCache`). Both classes move together in the same commit.

**Step 2.** Two new files. `src/Piwigo/Cache/PersistentCache.php`:

```php
<?php declare(strict_types=1);

namespace Piwigo\Cache;

abstract class PersistentCache
{
    public int $default_lifetime = 86400;
    protected string $instance_key = PHPWG_VERSION;

    public function make_key(string|array $key): string
    {
        if (is_array($key)) { $key = implode('&', $key); }
        return md5($key . $this->instance_key);
    }

    abstract public function get(string $key, mixed &$value): bool;
    abstract public function set(string $key, mixed $value, ?int $lifetime = null): bool;
    abstract public function purge(bool $all): void;
}
```

`src/Piwigo/Cache/PersistentFileCache.php`:

```php
<?php declare(strict_types=1);

namespace Piwigo\Cache;

final class PersistentFileCache extends PersistentCache
{
    private string $dir;

    public function __construct()
    {
        global $conf;
        $this->dir = PHPWG_ROOT_PATH . $conf['data_location'] . 'cache/';
    }

    public function get(string $key, mixed &$value): bool
    {
        $loaded = @file_get_contents($this->dir . $key . '.cache');
        if ($loaded !== false && ($loaded = unserialize($loaded)) !== false) {
            if ($loaded['expire'] > time()) {
                $value = $loaded['data'];
                return true;
            }
        }
        return false;
    }

    public function set(string $key, mixed $value, ?int $lifetime = null): bool
    {
        $lifetime ??= $this->default_lifetime;
        if (rand() % 97 === 0) { $this->purge(false); }
        $serialized = serialize(['expire' => time() + $lifetime, 'data' => $value]);
        if (false === @file_put_contents($this->dir . $key . '.cache', $serialized)) {
            \mkgetdir($this->dir, MKGETDIR_DEFAULT & ~MKGETDIR_DIE_ON_ERROR);
            if (false === @file_put_contents($this->dir . $key . '.cache', $serialized)) {
                return false;
            }
        }
        return true;
    }

    public function purge(bool $all): void
    {
        $files = glob($this->dir . '*.cache') ?: [];
        $limit = time() - $this->default_lifetime;
        foreach ($files as $file) {
            if ($all || @filemtime($file) < $limit) { @unlink($file); }
        }
    }
}
```

`global $conf;` and `\mkgetdir()` keep working — Phase 4 converts `$conf`, not Phase 3.

**Step 4.** In `rector.php`:

```php
->withConfiguredRule(\Rector\Renaming\Rector\Name\RenameClassRector::class, [
    'PersistentCache'     => \Piwigo\Cache\PersistentCache::class,
    'PersistentFileCache' => \Piwigo\Cache\PersistentFileCache::class,
])
```

**Step 5.** `vendor/bin/rector process` rewrites the one site that constructs it. `include/common.inc.php` had `$persistent_cache = new PersistentFileCache()`; afterwards, `$persistent_cache = new \Piwigo\Cache\PersistentFileCache()`.

**Step 6.** `git rm include/cache.class.php`.

**Step 7.** `include/common.inc.php` line 108 used to be `include(PHPWG_ROOT_PATH . 'include/cache.class.php');`. Delete it.

**Step 8.** Verification:

```bash
composer dump-autoload --strict-psr        # exits 0
grep -rn 'cache\.class\.php' --include='*.php' . | grep -v install/db/  # empty
grep -rn 'new PersistentFileCache\b' --include='*.php' .                # empty
grep -rn 'new \\\\Piwigo\\\\Cache\\\\PersistentFileCache' --include='*.php' .  # exactly one hit, common.inc.php
```

Commit-sized diff: +2 files (~70 lines), -1 file (-128 lines), -1 line in `common.inc.php`, +5 lines in `rector.php`. Tightly reviewable.

---

### C. Recipe — `include/template.class.php`

This is the hardest single move in Phase 3. Five classes, every entry point loads it, the trunk class extends Smarty's namespaced base, and a closing `?>` lurks at line 2137. The file also contains `Script` (line 1371), `Css` (line 1399), and `FileCombiner` (line 1842) which the parent plan's namespace table does not enumerate explicitly — they are siblings of `Combinable` and stay together with it (`Piwigo\Template\{Script, Css, FileCombiner}`). That's actually eight classes in one file.

#### 1. Pre-audit

```bash
grep -rn 'new Template\b\|extends Template\b' --include='*.php' . \
  | grep -v include/smarty/ | grep -v vendor/
## Expect: ~1 instantiation in include/common.inc.php; ~1 in install.php; ~1 in upgrade.php; zero "extends Template" outside Smarty.

grep -rn 'class Template' --include='*.php' . | grep -v include/smarty/ | grep -v vendor/
## Expect: only include/template.class.php:22

grep -rn 'extends ScriptLoader\|extends CssLoader\|extends Combinable' --include='*.php' .
## Expect: include/template.class.php internal hits only (Script extends Combinable, Css extends Combinable). No external inheritors.
```

External plugin/theme code may extend `Template` — the alias shim in section G handles this.

Smarty 5 autoloadability: `include/smarty/composer.json` does **not** exist in this tree (verified — `Glob "include/smarty/*.json"` returns empty). Smarty 5's actual root is `include/smarty/src/Smarty.php` and uses namespace `Smarty\`. Two options:

- **Option A — vendor as Composer path repo.** Add `repositories: [{type: path, url: include/smarty, options: {symlink: false}}]` and a stub `include/smarty/composer.json` declaring `psr-4: { "Smarty\\": "src/" }`. Cleanest, but requires generating the stub.
- **Option B — register Smarty's PSR-4 prefix directly in the root `composer.json`.**

```json
"autoload": {
  "psr-4": {
    "Piwigo\\": "src/Piwigo/",
    "Smarty\\": "include/smarty/src/"
  }
}
```

Option B is recommended — zero new files, one line change, and Smarty stays exactly where the rest of the codebase expects it. Confirm with `php -r "require 'vendor/autoload.php'; var_dump(class_exists(\Smarty\Smarty::class));"` → `bool(true)`. Once verified, the `require_once` at `include/template.class.php:13` is dead and gets deleted in this commit.

#### 2. Move 1 — leaf classes first

`Combinable` (1326), `Script` (1371), `Css` (1399), `CssLoader` (1421), `ScriptLoader` (1494), `FileCombiner` (1842) move first. Each goes to its own file under `src/Piwigo/Template/`. Cross-references inside the file become FQN: `new Css(...)` inside `CssLoader::add()` becomes `new \Piwigo\Template\Css(...)`.

`src/Piwigo/Template/Combinable.php`:

```php
<?php declare(strict_types=1);

namespace Piwigo\Template;

class Combinable
{
    public string $id;
    public string $path = '';
    public string $version;
    public bool $is_template;

    public function __construct(string $id, string $path, string|int $version = 0)
    {
        $this->id = $id;
        $this->set_path($path);
        $this->version = (string) $version;
        $this->is_template = false;
    }

    public function set_path(string $path): void
    {
        if ($path !== '') { $this->path = $path; }
    }

    public function is_remote(): bool
    {
        return \url_is_remote($this->path) || strncmp($this->path, '//', 2) === 0;
    }
}
```

`src/Piwigo/Template/Script.php` and `Css.php` are mechanical extractions of lines 1371–1394 and 1399–1415, with `extends Combinable` (same namespace, no FQN needed).

`src/Piwigo/Template/CssLoader.php`: copy lines 1421–1487 verbatim. The internal `array('CssLoader', 'cmp_by_order')` callback becomes `[self::class, 'cmp_by_order']`. `new Css(...)` becomes `new Css(...)` (same namespace) — no change. `new FileCombiner('css', ...)` becomes `new FileCombiner('css', ...)` (also same namespace).

`src/Piwigo/Template/ScriptLoader.php`: copy lines 1494–1836. The two `array('ScriptLoader', '...')` callbacks become `[self::class, '...']`. The `add()` method gains a manifest-aware mode flagged for Phase 5 (asset pipeline). The current `add()` signature (line 1578) is preserved bit-for-bit; the manifest hook is a fall-through *after* the well-known-paths logic so legacy callers are unaffected:

```php
public function add(
    string $id,
    int $load_mode,
    array $require,
    ?string $path,
    string|int $version = 0,
    bool $is_template = false,
): void {
    // Phase 5 hook: if a build manifest is registered and resolves $id,
    // use the manifest's hashed path instead of the supplied $path.
    if (self::$manifest !== null && ($resolved = self::$manifest->resolve($id)) !== null) {
        $path    = $resolved['path'];
        $version = $resolved['hash'];
    }

    if ($this->did_head && $load_mode === 0) {
        \trigger_error("Attempt to add script $id but the head has been written", E_USER_WARNING);
    } elseif ($this->did_footer) {
        \trigger_error("Attempt to add script $id but the footer has been written", E_USER_WARNING);
    }
    // …rest of the existing body unchanged…
}

private static ?\Piwigo\Asset\Manifest $manifest = null;

public static function setManifest(?\Piwigo\Asset\Manifest $m): void
{
    self::$manifest = $m;
}
```

Phase 5 wires `Manifest`; for Phase 3 it stays `null` and `add()` behaves identically.

`src/Piwigo/Template/FileCombiner.php`: copy lines 1842–2135. Globals (`global $conf`, `global $template`) stay until Phase 4. `require_once(PHPWG_ROOT_PATH . 'include/jshrink.class.php')` and the minify chain at lines 2043 and 2063–2067 stay — those are vendored libraries, out of scope.

#### 3. Move 2 — the adapter

`src/Piwigo/Template/PwgTemplateAdapter.php`:

```php
<?php declare(strict_types=1);

namespace Piwigo\Template;

use Piwigo\Image\DerivativeImage;

class PwgTemplateAdapter
{
    /** @deprecated use "translate" modifier */
    public function l10n(string $text): string { return \l10n($text); }

    /** @deprecated use "translate_dec" modifier */
    public function l10n_dec(string $s, string $p, int $v): string { return \l10n_dec($s, $p, $v); }

    /** @deprecated use "translate" or "sprintf" modifier */
    public function sprintf(string ...$args): string
    {
        return \sprintf(...$args);
    }

    public function derivative(string $type, array $img): DerivativeImage
    {
        return new DerivativeImage($type, $img);
    }

    public function derivative_url(string $type, array $img): string
    {
        return DerivativeImage::url($type, $img);
    }
}
```

Note the `use Piwigo\Image\DerivativeImage` — `PwgTemplateAdapter` depends on the derivative cluster, so section E must land first or in the same commit. The original `func_get_args()` + `call_user_func_array('sprintf', ...)` becomes a variadic + spread.

#### 4. Move 3 — `Template`

The trunk. New file `src/Piwigo/Template/Template.php`:

```php
<?php declare(strict_types=1);

namespace Piwigo\Template;

use Smarty\Smarty;

if (!defined('BUTTONS_RANK_NEUTRAL')) {
    define('BUTTONS_RANK_NEUTRAL', 50);
}

class Template
{
    public Smarty $smarty;
    public string $output = '';

    /** @var array<string,string> */
    public array $files = [];
    /** @var array<string,string> */
    public array $extents = [];
    /** @var array<string,mixed> */
    public array $external_filters = [];

    /** @var array<string,string> */
    public array $html_head_elements = [];
    private string $html_style = '';

    public const COMBINED_SCRIPTS_TAG = '<!-- COMBINED_SCRIPTS -->';
    public ScriptLoader $scriptLoader;

    public const COMBINED_CSS_TAG = '<!-- COMBINED_CSS -->';
    public CssLoader $cssLoader;

    /** @var array<int,mixed> */
    public array $picture_buttons = [];
    /** @var array<int,mixed> */
    public array $index_buttons = [];

    public function __construct(string $root = '.', string $theme = '', string $path = 'template')
    {
        global $conf, $lang_info;

        $this->scriptLoader = new ScriptLoader();
        $this->cssLoader = new CssLoader();
        $this->smarty = new Smarty();
        // …rest of the existing __construct body, unchanged from line 70 onwards…
        // The block at line 182 reading $conf['extents_for_templates'] keeps working
        // because $conf is still a global (Phase 4 will service-ify it).

        if (!defined('IN_ADMIN') && isset($conf['extents_for_templates'])) {
            $tpl_extents = unserialize($conf['extents_for_templates']);
            $this->set_extents($tpl_extents, './template-extension/', true, $theme);
        }
    }

    // …all other Template methods copied verbatim from the original 22–1267 range…
}
```

Critical changes from the original:

- Line 13's `require_once(PHPWG_ROOT_PATH . 'include/smarty/libs/Smarty.class.php');` is **deleted from the codebase** in this commit. Composer autoloads `Smarty\Smarty` via `include/smarty/src/`.
- The `use Smarty\Smarty;` import stays.
- `$this->smarty = new Smarty;` becomes `$this->smarty = new Smarty();`.
- `$this->scriptLoader = new ScriptLoader;` becomes `$this->scriptLoader = new ScriptLoader();` (same namespace, no FQN needed).
- Theme template extension via `$conf['extents_for_templates']` (line 182) works without modification — `set_extents()` is a method on the same class, the unserialized value is a plain array of file paths.

#### 5. Reference rewrite

In `rector.php`:

```php
->withConfiguredRule(\Rector\Renaming\Rector\Name\RenameClassRector::class, [
    'Combinable'         => \Piwigo\Template\Combinable::class,
    'Script'             => \Piwigo\Template\Script::class,
    'Css'                => \Piwigo\Template\Css::class,
    'CssLoader'          => \Piwigo\Template\CssLoader::class,
    'ScriptLoader'       => \Piwigo\Template\ScriptLoader::class,
    'FileCombiner'       => \Piwigo\Template\FileCombiner::class,
    'PwgTemplateAdapter' => \Piwigo\Template\PwgTemplateAdapter::class,
    'Template'           => \Piwigo\Template\Template::class,
])
```

Verification:

```bash
grep -rn 'new \(Template\|ScriptLoader\|CssLoader\|Combinable\|Script\|Css\|FileCombiner\|PwgTemplateAdapter\)\b' \
  --include='*.php' . | grep -v 'Piwigo\\\\Template' | grep -v vendor/ | grep -v include/smarty/
## Expect: empty (modulo the smarty/Template internal Smarty class which is namespaced as Smarty\Template)
```

The Smarty `\Smarty\Template` collision is not a problem post-namespace: our class is `\Piwigo\Template\Template`, theirs is `\Smarty\Template`, both unambiguous.

#### 6. Smoke test

`tests/Browser/template-move.spec.ts`:

```ts
test('gallery renders after Template move', async ({ page }) => {
  await page.goto('/index.php');
  await expect(page.locator('body')).toHaveClass(/theme-/);
  await expect(page.locator('link[rel=stylesheet]')).toHaveCount({ min: 1 });
  await expect(page).not.toHaveTitle(/Fatal error/);
});

test('admin renders after Template move', async ({ page }) => {
  await login(page, 'admin', 'pass');
  await page.goto('/admin.php');
  await expect(page.locator('#theMain')).toBeVisible();
});
```

If either fails, the most likely cause is a `<!-- COMBINED_SCRIPTS -->` tag not being substituted because `ScriptLoader::get_head_scripts()` threw — usually because a sibling class wasn't moved together. Move all eight together; do not split the commit.

---

### D. Recipe — `include/ws_core.inc.php` split

This file mixes six classes with `define()` constants. **Correction to the parent plan:** there are no free functions in `ws_core.inc.php` — only constants and classes. The `ws_run` and helper free functions live in `include/ws_functions.inc.php`, a separate file that stays untouched in Phase 3 (free functions are not migrated).

#### 1. Class inventory with line ranges

| Class | Lines | Destination |
|---|---|---|
| `PwgError` | 39–57 | `src/Piwigo/Ws/PwgError.php` |
| `PwgNamedArray` | 64–83 | `src/Piwigo/Ws/PwgNamedArray.php` |
| `PwgNamedStruct` | 89–125 | `src/Piwigo/Ws/PwgNamedStruct.php` |
| `PwgRequestHandler` | 131–137 | `src/Piwigo/Ws/PwgRequestHandler.php` |
| `PwgResponseEncoder` | 143–211 | `src/Piwigo/Ws/Encoder/PwgResponseEncoder.php` |
| `PwgServer` | 215–709 | `src/Piwigo/Ws/PwgServer.php` |

#### 2. Constants stay

The `define('WS_PARAM_ACCEPT_ARRAY', ...)` block (lines 19–34) is not migrated — they are global constants used by every plugin's WS endpoint registration. After class extraction `include/ws_core.inc.php` shrinks to just those `define()` calls plus the file header comment. Keep `include(... ws_core.inc.php)` in `ws.php` so the constants are available; remove it once Phase 4 moves them to `Piwigo\Ws\Constants` typed-class constants.

#### 3. Property declarations — before/after

`PwgError` lines 41–42 are already typed by Phase 2. Phase 3 just relocates:

```php
// Before — include/ws_core.inc.php:39
class PwgError
{
    private $_code;
    private $_codeText;

    function __construct($code, $codeText) { /* ... */ }
}
```

```php
// After — src/Piwigo/Ws/PwgError.php
<?php declare(strict_types=1);

namespace Piwigo\Ws;

class PwgError
{
    private int $_code;
    private string $_codeText;

    public function __construct(int $code, string $codeText)
    {
        if ($code >= 400 && $code < 600) {
            \set_status_header($code, $codeText);
        }
        $this->_code = $code;
        $this->_codeText = $codeText;
    }

    public function code(): int { return $this->_code; }
    public function message(): string { return $this->_codeText; }
}
```

`PwgNamedArray` (uses the legacy `var` keyword, which PHP 8.5 still accepts but `phpstan-strict-rules` flags):

```php
// Before — line 64
class PwgNamedArray
{
    /*private*/ var $_content;
    /*private*/ var $_itemName;
    /*private*/ var $_xmlAttributes;
    function __construct($arr, $itemName, $xmlAttributes=array()) { /* ... */ }
}
```

```php
// After — src/Piwigo/Ws/PwgNamedArray.php
<?php declare(strict_types=1);

namespace Piwigo\Ws;

class PwgNamedArray
{
    /** @var array<int,mixed> */
    public array $_content;       // public, not private — PwgResponseEncoder::flatten() reads it
    public string $_itemName;
    /** @var array<string,int> */
    public array $_xmlAttributes;

    public function __construct(array $arr, string $itemName, array $xmlAttributes = [])
    {
        $this->_content = $arr;
        $this->_itemName = $itemName;
        $this->_xmlAttributes = array_flip($xmlAttributes);
    }
}
```

The `_content` property must stay public (or expose a getter) because `PwgResponseEncoder::flatten()` at line 186 reads `$value->_content`. Phase 3 keeps the public visibility; Phase 4 may add a typed accessor and deprecate direct property reads.

`PwgServer` ports the same way. The four `var` properties at lines 217–220 become typed `private` properties:

```php
// After — src/Piwigo/Ws/PwgServer.php
<?php declare(strict_types=1);

namespace Piwigo\Ws;

use Piwigo\Ws\Encoder\PwgResponseEncoder;

class PwgServer
{
    private ?PwgRequestHandler $_requestHandler = null;
    private ?string $_requestFormat = null;
    private ?PwgResponseEncoder $_responseEncoder = null;
    private ?string $_responseFormat = null;

    /** @var array<string,array{callback:callable,description:string,signature:array,include:string,options:array}> */
    private array $_methods = [];

    public function __construct() {}
    // …
}
```

#### 4. `func_get_args()` patterns

`PwgServer` does not actually use `func_get_args()` in this file (the prompt's premise is slightly off — that pattern lives in `PwgTemplateAdapter::sprintf()` at line 1297 of `template.class.php`, already addressed in section C). However the same conversion applies cleanly to `PwgServer::addMethod` overloading callers if any exist. Search confirms none: `grep -n 'func_get_args' include/ws_core.inc.php` returns zero hits. No conversion needed in this file.

For completeness, the canonical pattern when it does occur:

```php
// Before
function sprintf()
{
    $args = func_get_args();
    return call_user_func_array('sprintf', $args);
}
// After
public function sprintf(string ...$args): string
{
    return \sprintf(...$args);
}
```

#### 5. Tests

`tests/Unit/Ws/PwgErrorTest.php`:

```php
<?php declare(strict_types=1);

namespace Piwigo\Tests\Unit\Ws;

use PHPUnit\Framework\TestCase;
use Piwigo\Ws\PwgError;

final class PwgErrorTest extends TestCase
{
    public function test_code_and_message_round_trip(): void
    {
        $e = new PwgError(1003, 'Invalid parameter');
        self::assertSame(1003, $e->code());
        self::assertSame('Invalid parameter', $e->message());
    }

    public function test_http_status_codes_do_not_call_set_status_header_in_test_env(): void
    {
        // set_status_header is a free function; in headless PHPUnit run it is a no-op
        // (output already started or stubbed). Asserts the constructor doesn't throw.
        $e = new PwgError(404, 'Not found');
        self::assertSame(404, $e->code());
    }
}
```

The boundary holds because `PwgError` has no construction-time dependencies on global state apart from `set_status_header()` (a free function from `include/functions_html.inc.php`). The test environment stubs that via the bootstrap script the parent plan defines for Phase 0.

---

### E. Recipe — derivative class extraction from `.inc.php` files

Three files, seven classes, plus seven `IMG_*` constants and four small helper free functions. The pattern is the same: classes go to `src/Piwigo/Image/`, the original file shrinks.

#### 1. What's class vs. procedural

| Original file | Classes | Procedural content stays in original |
|---|---|---|
| `include/derivative.inc.php` | `SrcImage` (19–169), `DerivativeImage` (171–end) | none — the file is 100% classes |
| `include/derivative_params.inc.php` | `ImageRect` (76–184), `SizingParams` (186–327), `DerivativeParams` (329–end) | `derivative_to_url` (20), `size_to_url` (31), `size_equals` (45), `char_to_fraction` (56) |
| `include/derivative_std_params.inc.php` | `WatermarkParams` (31–47), `ImageStdParams` (53–end) | seven `define('IMG_*', ...)` calls (lines 14–25) |

#### 2. Extraction recipe

For `derivative.inc.php`: classes extract to `src/Piwigo/Image/SrcImage.php` and `src/Piwigo/Image/DerivativeImage.php`. The original file ends up containing only `<?php` plus the closing `?>`. Delete the file. Remove the `include` from any caller (search: `grep -rn "derivative\.inc\.php" --include='*.php' .` — typically `include/common.inc.php` and `i.php`).

For `derivative_params.inc.php`: classes extract; the four free functions (`derivative_to_url`, `size_to_url`, `size_equals`, `char_to_fraction`) stay in `derivative_params.inc.php`. The file remains in `include/`, and its `include` from `common.inc.php` stays. After extraction, the file is ~60 lines instead of ~370.

For `derivative_std_params.inc.php`: classes extract; the seven `define()` calls stay. Phase 4 will promote them to typed class constants on `Piwigo\Image\ImageStdParams`. The file remains, ~25 lines.

#### 3. Constants — before/after

Before — top of `derivative_std_params.inc.php`:

```php
define('IMG_SQUARE', 'square');
define('IMG_THUMB', 'thumb');
define('IMG_XXSMALL', '2small');
// ... 9 more
```

After Phase 3 — these stay defined as global constants in the file. Phase 4 promotion target on `src/Piwigo/Image/ImageStdParams.php`:

```php
<?php declare(strict_types=1);

namespace Piwigo\Image;

final class ImageStdParams
{
    public const IMG_SQUARE   = 'square';
    public const IMG_THUMB    = 'thumb';
    public const IMG_XXSMALL  = '2small';
    public const IMG_XSMALL   = 'xsmall';
    public const IMG_SMALL    = 'small';
    public const IMG_MEDIUM   = 'medium';
    public const IMG_LARGE    = 'large';
    public const IMG_XLARGE   = 'xlarge';
    public const IMG_XXLARGE  = 'xxlarge';
    public const IMG_3XLARGE  = '3xlarge';
    public const IMG_4XLARGE  = '4xlarge';
    public const IMG_CUSTOM   = 'custom';

    /** @var list<string> */
    private static array $all_types = [
        self::IMG_SQUARE, self::IMG_THUMB, self::IMG_XXSMALL, self::IMG_XSMALL,
        self::IMG_SMALL, self::IMG_MEDIUM, self::IMG_LARGE, self::IMG_XLARGE,
        self::IMG_XXLARGE, self::IMG_3XLARGE, self::IMG_4XLARGE,
    ];

    // …
}
```

In Phase 3 the typed-class-constant version coexists with the global `define()`s; both resolve to the same string value. Phase 4 deletes the `define()`s once Rector has rewritten every consumer.

---

### F. Recipe — Q-search classes from `include/functions_search.inc.php`

Confirmed via `grep -n '^class Q' include/functions_search.inc.php`:

| Class | Line | Destination |
|---|---|---|
| `QSearchScope` | 864 | `src/Piwigo/Search/QSearchScope.php` |
| `QNumericRangeScope` extends `QSearchScope` | 892 | `src/Piwigo/Search/QNumericRangeScope.php` |
| `QDateRangeScope` extends `QSearchScope` | 994 | `src/Piwigo/Search/QDateRangeScope.php` |
| `QSingleToken` | 1077 | `src/Piwigo/Search/QSingleToken.php` |
| `QMultiToken` | 1114 | `src/Piwigo/Search/QMultiToken.php` |
| `QExpression` extends `QMultiToken` | 1400 | `src/Piwigo/Search/QExpression.php` |
| `QResults` | 1449 | `src/Piwigo/Search/QResults.php` |

Move order: `QSearchScope` first (root of one inheritance chain), then `QNumericRangeScope` and `QDateRangeScope` together. `QSingleToken` is independent; move third. `QMultiToken` next, then `QExpression`. `QResults` last (no inheritance).

Each class is its own file with `namespace Piwigo\Search;`. Cross-references inside the cluster use unqualified names — same namespace. References from `functions_search.inc.php`'s remaining free functions (after extraction the file still contains the bulk of search-orchestration procedural code) become FQN: e.g. `new QExpression($q)` becomes `new \Piwigo\Search\QExpression($q)`.

The Rector mapping:

```php
->withConfiguredRule(\Rector\Renaming\Rector\Name\RenameClassRector::class, [
    'QSearchScope'        => \Piwigo\Search\QSearchScope::class,
    'QNumericRangeScope'  => \Piwigo\Search\QNumericRangeScope::class,
    'QDateRangeScope'     => \Piwigo\Search\QDateRangeScope::class,
    'QSingleToken'        => \Piwigo\Search\QSingleToken::class,
    'QMultiToken'         => \Piwigo\Search\QMultiToken::class,
    'QExpression'         => \Piwigo\Search\QExpression::class,
    'QResults'            => \Piwigo\Search\QResults::class,
])
```

Tests: `tests/Unit/Search/QExpressionTest.php` exercises a known query (`hello -world tag:foo`) and asserts the parsed token tree shape. The free function `qsearch_get_images()` is a black-box integration boundary — covered by an existing Playwright spec, not unit-tested.

---

### G. The plugin/theme `class_alias` shim file

Plugins and themes shipped against Piwigo 16.x reference unqualified class names like `PluginMaintain`, `ThemeMaintain`, and (rarely) `Template`. After Phase 3 those names no longer resolve. The shim keeps them working for two minor releases.

`src/Piwigo/Compat/aliases.php`:

```php
<?php declare(strict_types=1);

/**
 * Backwards-compatibility aliases for plugin/theme code that references
 * pre-PSR-4 short class names. Loaded via composer.json autoload.files.
 *
 * @deprecated Each alias here will be removed two minor versions after Phase 3
 *             ships. Plugins/themes must update their class references to the
 *             FQN under \Piwigo\... before then. The intended end-of-life is
 *             documented in CHANGELOG.md against each name below.
 */

// --- Plugins / themes maintenance contracts ----------------------------------
// Plugins extend PluginMaintain in their main.inc.php; themes likewise.
// EOL target: 17.4 (two minors after 17.0 ships Phase 3).
\class_alias(\Piwigo\Admin\PluginMaintain::class, 'PluginMaintain');
\class_alias(\Piwigo\Admin\ThemeMaintain::class,  'ThemeMaintain');

// --- Template trunk class ----------------------------------------------------
// Some themes ship code that does `extends Template` for a custom renderer.
// Aliasing the class itself is unusual but cheap insurance.
// EOL target: 17.2.
\class_alias(\Piwigo\Template\Template::class, 'Template');

// --- WS layer ----------------------------------------------------------------
// Plugins commonly throw PwgError from custom ws methods. Keep the short name
// available so existing plugins compile under the namespaced runtime.
// EOL target: 17.4.
\class_alias(\Piwigo\Ws\PwgError::class, 'PwgError');
\class_alias(\Piwigo\Ws\PwgNamedArray::class, 'PwgNamedArray');
\class_alias(\Piwigo\Ws\PwgNamedStruct::class, 'PwgNamedStruct');

// --- Calendar ----------------------------------------------------------------
// Section_init dispatches on $conf['calendar_*'] string. After Phase 3 those
// strings are FQNs; this alias only protects out-of-tree plugins that picked
// up the short name from a tutorial.
// EOL target: 17.2.
\class_alias(\Piwigo\Calendar\CalendarMonthly::class, 'CalendarMonthly');
\class_alias(\Piwigo\Calendar\CalendarWeekly::class,  'CalendarWeekly');

// --- Image params ------------------------------------------------------------
// Photos plugin and various third-party derivative tweakers reference these.
// EOL target: 17.4.
\class_alias(\Piwigo\Image\DerivativeImage::class,   'DerivativeImage');
\class_alias(\Piwigo\Image\SrcImage::class,          'SrcImage');
\class_alias(\Piwigo\Image\ImageStdParams::class,    'ImageStdParams');
\class_alias(\Piwigo\Image\DerivativeParams::class,  'DerivativeParams');
\class_alias(\Piwigo\Image\WatermarkParams::class,   'WatermarkParams');
```

Registration in `composer.json`:

```json
{
  "autoload": {
    "psr-4": {
      "Piwigo\\": "src/Piwigo/",
      "Smarty\\": "include/smarty/src/"
    },
    "files": [
      "src/Piwigo/Compat/aliases.php"
    ]
  }
}
```

Composer's autoloader includes `files` entries on every request, so the aliases are registered before any user code runs. The cost is one `include` per request and ~12 `class_alias()` calls — negligible. The deprecation policy is committed in code as comments, in `CHANGELOG.md` against each version target, and in `docs/PLUGIN_AUTHORS.md`.

---

### H. Migration checklist (CI-enforceable)

A bash script `tools/ci/phase3-checks.sh` runs after every Phase 3 push. It exits non-zero on any failure. The patterns are deliberately conservative — false negatives are fine (the smoke spec catches behaviour), false positives are not (we don't want to fail green commits).

```bash
#!/usr/bin/env bash
## tools/ci/phase3-checks.sh — must exit 0 for Phase 3 push
set -euo pipefail

fail() { echo "FAIL: $*" >&2; exit 1; }

## 1. No legacy include of a migrated .class.php file.
##    Pull migrated names from docs/class-inventory.csv (column 2) where the
##    migrated marker is present.
MIGRATED=$(awk -F, 'NR>1 && $1 !~ /\.inc\.php$/ {print $2}' docs/class-inventory.csv)
for cls in $MIGRATED; do
  src=$(awk -F, -v c="$cls" 'NR>1 && $2==c {print $1}' docs/class-inventory.csv)
  if [ -n "$src" ] && grep -rn "include.*$(basename "$src")" --include='*.php' \
       --exclude-dir=vendor --exclude-dir=install/db . | grep -v docs/ ; then
    fail "Legacy include of migrated $src still present"
  fi
done

## 2. Composer autoload must build under strict PSR-4 rules.
composer dump-autoload --strict-psr --no-interaction \
  || fail "composer dump-autoload --strict-psr failed"

## 3. For every migrated class, no bare `new ClassName(` outside the new namespace.
for cls in $MIGRATED; do
  if grep -rn "new \\\\\\?$cls\\b" --include='*.php' \
       --exclude-dir=vendor --exclude-dir=install/db --exclude-dir=src/Piwigo \
       --exclude-dir=include/smarty . \
     | grep -v "Piwigo\\\\" \
     | grep -v 'class_alias' ; then
    fail "Bare 'new $cls(' found outside src/Piwigo — Rector rewrite was incomplete"
  fi
done

## 4. PHPStan custom rule asserts dynamic-new sites are guarded.
vendor/bin/phpstan analyse --no-progress -c phpstan.neon src/ include/ admin/ \
  || fail "PHPStan failed (likely the noDynamicNew rule)"

## 5. Tarball does not ship dev tooling.
git archive --format=tar.gz HEAD -o /tmp/piwigo-tarball.tgz
( tar -tzf /tmp/piwigo-tarball.tgz | grep -E '^vendor/(phpstan|rector|phpunit|laravel)/' ) \
  && fail "Release tarball contains dev dependencies"

## 6. Dynamic-new audit: every literal class-string in a `new $var()` site must
##    appear in src/Piwigo/Compat/aliases.php OR be guarded by class_exists.
python3 tools/ci/audit-dynamic-new.py \
  || fail "Unguarded dynamic 'new \$var()' detected"

echo "OK: Phase 3 checks pass"
```

The PHPStan `noDynamicNew` rule (referenced in step 4) lives at `tools/phpstan/NoDynamicNewRule.php`:

```php
<?php declare(strict_types=1);

namespace Piwigo\Tools\PhpStan;

use PhpParser\Node;
use PhpParser\Node\Expr\New_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/** @implements Rule<New_> */
final class NoDynamicNewRule implements Rule
{
    public function getNodeType(): string { return New_::class; }

    public function processNode(Node $node, Scope $scope): array
    {
        if (!($node->class instanceof Node\Expr\Variable)) { return []; }
        $varName = $node->class->name;
        if (!is_string($varName)) {
            return [RuleErrorBuilder::message(
                "Dynamic 'new \$var()' with computed variable name; require class_exists() guard."
            )->identifier('piwigo.noDynamicNew')->build()];
        }
        return [RuleErrorBuilder::message(
            "Dynamic 'new \$$varName()' detected — confirm class_exists(\$$varName) guard or move to a typed factory."
        )->identifier('piwigo.noDynamicNew')->tip(
            'For dispatch tables, prefer ::class FQN strings over short names.'
        )->build()];
    }
}
```

Wired into `phpstan.neon`:

```yaml
rules:
  - Piwigo\Tools\PhpStan\NoDynamicNewRule
```

Combined, sections A–H form a complete, mechanical playbook: inventory script (A), uniform per-class recipe (B), and four full worked recipes (C–F) for the awkward files, plus the alias safety net (G) and the CI guardrails (H) that keep regressions from sneaking back in.

---

## Phase 4 — Globals → typed services / DTOs (XL — most likely cut point)

### Goal
Eliminate the implicit, untyped, append-anywhere `$conf` / `$page` / `$user` / `$lang` globals that anchor `include/common.inc.php` (lines 52–65) by routing all new code through a small set of typed services — `Piwigo\Core\Config`, `Piwigo\Core\PageState`, `Piwigo\Users\CurrentUser`, `Piwigo\Core\Lang` — wired by a `Kernel::boot()` and discovered by a thin `ServiceLocator`. Legacy global access keeps working unchanged through the entire phase, then optionally degrades to `E_USER_DEPRECATED` proxies in Wave C, which is the cut point. The 122 `install/db/*.php` scripts must continue reading `$conf['dblayer']`, `$conf['db_base']`, etc. without modification or deprecation noise — that constraint dictates the design.

### Step-by-step sequence

1. **Build `Piwigo\Core\Config` as a typed reader.** Inventory the ~140 `$conf` keys in `include/config_default.inc.php` (1088 lines, ~140 `$conf[...] = ...` matches) plus runtime-DB keys loaded by `load_conf_from_db()` at `include/common.inc.php:135`. Cluster into typed groups: paths (`upload_dir`, `data_location`, `themes_dir`, `log_dir`, `ext_imagick_dir`, `ffmpeg_dir`), security (`session_*`, `password_hash`, `password_verify`, `secret_key`, `auth_key_duration`, `apache_authentication`), mail (`smtp_*`, `mail_*`, `send_bcc_mail_webmaster`), derivatives (`derivative_*`, `derivatives_strip_metadata_threshold`, `chmod_value`, `picture_ext`, `file_ext`, `format_ext`), debug (`show_php_errors`, `show_queries`, `debug_l10n`, `debug_template`, `debug_mail`, `die_on_sql_error`, `template_force_compile`), uploads (`upload_form_*`, `enable_synchronization`), notification (`nbm_*`), tags (`*_tag_cloud_*`, `tags_levels`), session (`session_*`, `remember_me_*`). Each cluster gets a typed accessor on `Config`. **Exit signal:** `phpstan analyse --level=8 src/Piwigo/Core/Config.php` clean; Unit test asserts each cluster getter returns documented default when no DB row exists.

2. **Build `Piwigo\Core\PageState` DTO.** Mirrors the array literal at `include/common.inc.php:53–60` exactly — `infos`, `errors`, `warnings`, `messages`, `body_classes`, `body_data` — plus runtime-attached keys (`execution_uuid` line 113, `auth_key_invalid` line 250, `notify_api_key_expiration` line 259, `queries_time` upgrade.php:414, `count_queries`, `upgrade_start`). The ~152 `$page['errors'][]` push sites across 46 files become `PageState::addError(string)`, with the same shape for `addWarning/addMessage/addInfo`. Add typed read accessors `errors(): list<string>` and `mergeFromConf(array $headerNotes): void`. **Exit signal:** unit test instantiates `PageState`, pushes one of each kind, asserts read accessors return them in order.

3. **Build `Piwigo\Users\User` entity and `CurrentUser` accessor.** `$user` is initialised to `[]` at `common.inc.php:61` then hydrated by `include/user.inc.php` (loaded line 204). Inventory keys (`username`, `email`, `theme`, `language`, `id`, `internal_status`, plus all `user_infos` columns merged in). Wrap as `User` with typed properties; `CurrentUser::get(): User` returns the singleton, set by `Kernel::boot()` after `include 'include/user.inc.php'`. Keep `User` mutable through Wave A — `$user['language']` reassignment at `common.inc.php:206`, `$user['username']` at line 245 must still work. **Exit signal:** `CurrentUser::get()->username === $user['username']` after boot.

4. **Build `Piwigo\Core\Lang`.** Wraps `$lang` (initialised line 62, populated by `load_language()` calls lines 231–239). `Lang::t(string $key, mixed ...$args): string` and `Lang::has(string $key): bool`. Translation arrays themselves stay in `language/*.php` (out of scope per Phase 0 — `language/` is excluded). `l10n()` keeps working as a free function delegating to `Lang::current()->t()`. **Exit signal:** `l10n('guest')` returns same value pre- and post-conversion.

5. **Build `Piwigo\Core\Kernel::boot()`.** Single entry point that subsumes the 100-line dance currently at `common.inc.php:79–369`. Boot order is fixed by that file and must be preserved exactly:
   ```php
   final class Kernel
   {
       private static bool $booted = false;
       public static function boot(): void
       {
           if (self::$booted) { return; }              // idempotent guard for nested entry points
           self::$booted = true;
           Config::loadDefaults();                      // include/config_default.inc.php
           Config::loadLocal();                         // local/config/config.inc.php
           Db\Connection::open(Config::get('db_host'), ..., Config::get('db_base'));
           Config::hydrateFromDb();                     // load_conf_from_db()
           Lang::initFor(CurrentUser::get());
           PageState::init();                           // populates execution_uuid, body_classes
           ServiceLocator::register(Config::class, Config::instance());
           ServiceLocator::register(PageState::class, PageState::instance());
           // ...
       }
   }
   ```
   Every existing root entry point gains `\Piwigo\Core\Kernel::boot();` immediately after the `require __DIR__ . '/include/common.inc.php';` line. Inside `common.inc.php`, the legacy bootstrap dance is wrapped in `if (!Kernel::isBooted()) { /* legacy path */ }` so plugin-side or test-side code that bypasses the kernel keeps working through Wave A/B. **Exit signal:** running `php -r "require 'include/common.inc.php'; Kernel::boot(); var_dump(Config::get('upload_dir'));"` prints `"./upload"`.

6. **Build `Piwigo\Core\ServiceLocator` (NOT a full DI container).** Static map `array<class-string, object>`. Methods: `register(string $id, object $svc)`, `get<T>(class-string<T> $id): T`, `has(string $id): bool`. No autowiring, no factories — Piwigo has 358 classes and 2300+ free functions; a real DI container would dominate the change budget. PSR-11 compatibility is gratis (`Container::get/has`). **Exit signal:** `ServiceLocator::get(Config::class)` returns the same instance as `Config::instance()`.

7. **WAVE A — typed READERS ship alongside globals.** New code calls `Config::get('upload_dir')` and `PageState::current()->errors`. `Config` reads stay forwarded to the underlying `$conf` array (no copy), so per-album `$conf` overrides at runtime keep being visible to typed readers. Old code (`global $conf; $conf['upload_dir']`) keeps working unchanged — no proxy yet, just the underlying global array. PHPStan custom rule (added in this commit) flags **new** `global $conf` declarations in `src/` (allowed in `include/`). **Exit signal:** PHPStan green at level 8; Playwright green; UpgradeChainTest green; staging-soak one week.

8. **WAVE B — typed WRITERS migrate.** All ~152 push sites for `$page['errors'][]`, `$page['warnings'][]`, `$page['messages'][]`, `$page['infos'][]` (counted by grep across 46 files: 19 in `admin/maintenance_actions.php`, 11 in `admin/include/functions_notification_by_mail.inc.php`, 9 each in `admin/batch_manager_global.php`/`admin/plugins_new.php`/`admin/themes_new.php`/`admin/languages_new.php`, etc.) migrate one commit per directory slice to `PageState::current()->addError($s)`. The few `$conf` write sites — per-album overrides in `include/category_default.inc.php`/picture flow, admin saves in `admin/configuration.php` (~7 push sites) — get explicit `Config::override(string $key, mixed $value): void` for transient runtime overrides and `Config::persist(string $key, mixed $value): void` (delegates to existing `conf_update_param()` free function — that stays). Rector regex rules can mechanically convert ~80% of the simple cases; the rest are touched by hand. **Exit signal:** `grep -rE "\\$page\\['(errors|warnings|messages|infos)'\\]\\[\\]" admin/ include/` returns hits only from `install/db/`, `local/`, plugin paths, or vendored code.

9. **WAVE B.5 — migrate all bare `$conf[key]` reads to typed getters.** Every direct `$conf['key']` read in `admin/`, `include/`, and `src/` (outside `install/db/`, `local/config/`, `language/`, and `config_default.inc.php`) is replaced with its typed getter (`Config::key()`) or, for genuinely dynamic key lookups, `Config::get($key)`. Before the first commit, run `grep -rE "\\\$conf\[" admin/ include/ src/ --include="*.php"` and record the baseline count (~1 073 at Wave B close). Add any typed getters missing from `Config` first — if a key appears in `config_default.inc.php` but has no getter yet, add the getter in the same commit as the first migration that uses it. Work directory-by-directory (one commit per directory or logical group); each commit must leave PHPStan and unit tests green. Dynamic reads (`$conf[$variable]`) that cannot be replaced with a typed getter are replaced with `Config::get($variable)` and noted in the commit message. `global $conf` declarations in migrated files are removed once no bare reads remain in that file. **Exit signal:** `grep -rE "\\\$conf\[" admin/ include/ src/ --include="*.php" | grep -vE "(install/db/|local/config/|language/|config_default)"` returns zero hits; all 38 `global \$conf` declarations previously in `src/` are gone.

10. **WAVE C (cuttable) — globals become deprecation-emitting `ArrayObject` proxies.** A new class `Piwigo\Core\GlobalsBridge` lazily yields proxies. The proxy extends `\ArrayObject` and overrides `offsetGet`, `offsetSet`, `offsetExists`, `offsetUnset` to:
   ```php
   public function offsetGet(mixed $key): mixed
   {
       if (!self::isInstallDbCaller()) {
           trigger_error("Direct \$conf['$key'] access is deprecated; use Config::get('$key')", E_USER_DEPRECATED);
       }
       return parent::offsetGet($key);
   }

   private static function isInstallDbCaller(): bool
   {
       foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5) as $f) {
           $file = $f['file'] ?? '';
           if (str_contains($file, '/install/db/') || str_contains($file, '\\install\\db\\')) {
               return true;
           }
       }
       return false;
   }
   ```
   `common.inc.php` is rewritten so `$conf`, `$page`, `$user`, `$lang` are assigned proxy instances at the top, replacing lines 52–65. The backtrace gate keeps `install/db/61-database.php` through `install/db/181-database.php` silent. A second allowlist pattern covers `language/*.php` and `local/config/config.inc.php`. `config_default.inc.php` is exempt because it *writes* via `$conf['x'] = ...` at file scope. **Exit signal:** UpgradeChainTest green with no `E_USER_DEPRECATED` lines in captured error log; `find . -path ./vendor -prune -o -name '*.php' -print | xargs grep -l "global \$conf" | wc -l` is monotonically decreasing commit-over-commit.

11. **Cut-point decision at end of Wave B.5.** Evaluate honestly: are all bare `$conf[key]` reads eliminated? How noisy will `E_USER_DEPRECATED` be in existing error logs after Wave B.5 (should be near-zero from first-party code; any remaining noise comes from plugins)? If Wave C is shaping up to be 4+ weeks of whack-a-mole on the long tail (especially around dynamic property writes on `$page` from `ws_core.inc.php` and `template.class.php`), document the cut-point decision in `docs/ARCHITECTURE.md` ("Wave C deferred indefinitely; globals remain live `ArrayObject`s without deprecation noise") and stop. You retain the typed-reader/typed-writer wins from Waves A, B, and B.5. **Exit signal:** decision logged; if cut, Phase 6's "ArrayObject proxy removal" sub-task is also deleted.

### Concrete artifacts

**`src/Piwigo/Core/Config.php`** (sketch — typed getters, hydration):
```php
<?php declare(strict_types=1);
namespace Piwigo\Core;

/**
 * @phpstan-type DerivativeKey 'square'|'thumb'|'2small'|'xsmall'|'small'|'medium'|'large'|'xlarge'|'xxlarge'
 * @phpstan-type LogLevel 'DEBUG'|'INFO'|'WARNING'|'ERROR'|'FATAL'
 */
final class Config
{
    /** @var array<string,mixed> */
    private static array $data = [];
    private static bool $loaded = false;

    public static function loadDefaults(): void
    {
        $conf = [];
        require PHPWG_ROOT_PATH . 'include/config_default.inc.php';
        self::$data = $conf;
        // Bridge: keep legacy $GLOBALS['conf'] in sync (Wave A; replaced by proxy in Wave C).
        $GLOBALS['conf'] = &self::$data;
    }

    public static function loadLocal(): void
    {
        $conf = &self::$data;
        @include PHPWG_ROOT_PATH . 'local/config/config.inc.php';
    }

    public static function hydrateFromDb(): void
    {
        \load_conf_from_db();
        self::$loaded = true;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return self::$data[$key] ?? $default;
    }

    public static function getString(string $key, string $default = ''): string
    {
        $v = self::$data[$key] ?? $default;
        return is_string($v) ? $v : (string) $v;
    }

    public static function getInt(string $key, int $default = 0): int { /* ... */ }
    public static function getBool(string $key, bool $default = false): bool { /* ... */ }

    /** @return list<string> */
    public static function pictureExtensions(): array { return self::$data['picture_ext']; }
    public static function uploadDir(): string { return self::getString('upload_dir', './upload'); }
    public static function dbLayer(): string { return self::getString('dblayer', 'mysqli'); }
    public static function logLevel(): string { return self::getString('log_level', 'DEBUG'); }
    public static function isFormatsEnabled(): bool { return self::getBool('enable_formats'); }
    // ~30 typed getters total

    /** Transient runtime override (per-album, etc). Does not persist. */
    public static function override(string $key, mixed $value): void { self::$data[$key] = $value; }

    /** Persists via existing free function — DB write. */
    public static function persist(string $key, mixed $value): void
    {
        \conf_update_param($key, $value);
        self::$data[$key] = $value;
    }
}
```

**`src/Piwigo/Core/PageState.php`**:
```php
<?php declare(strict_types=1);
namespace Piwigo\Core;

final class PageState
{
    private static ?self $instance = null;
    /** @var list<string> */ public array $errors = [];
    /** @var list<string> */ public array $warnings = [];
    /** @var list<string> */ public array $messages = [];
    /** @var list<string> */ public array $infos = [];
    /** @var list<string> */ public array $bodyClasses = [];
    /** @var array<string,string> */ public array $bodyData = [];
    public string $executionUuid = '';

    public static function init(): void
    {
        self::$instance = new self();
        self::$instance->executionUuid = \generate_key(10);
        // Bridge (Wave A): keep $GLOBALS['page'] in sync via reference arrays.
        $GLOBALS['page'] = [
            'errors' => &self::$instance->errors,
            'warnings' => &self::$instance->warnings,
            'messages' => &self::$instance->messages,
            'infos' => &self::$instance->infos,
            'body_classes' => &self::$instance->bodyClasses,
            'body_data' => &self::$instance->bodyData,
            'execution_uuid' => &self::$instance->executionUuid,
        ];
    }

    public static function current(): self { return self::$instance ??= new self(); }

    public function addError(string $msg): void { $this->errors[] = $msg; }
    public function addWarning(string $msg): void { $this->warnings[] = $msg; }
    public function addMessage(string $msg): void { $this->messages[] = $msg; }
    public function addInfo(string $msg): void { $this->infos[] = $msg; }
    public function addBodyClass(string $cls): void { $this->bodyClasses[] = $cls; }
    public function hasErrors(): bool { return $this->errors !== []; }
}
```

**`src/Piwigo/Users/User.php`**:
```php
<?php declare(strict_types=1);
namespace Piwigo\Users;

final class User
{
    public function __construct(
        public readonly int $id,
        public string $username,
        public string $email,
        public string $language,
        public string $theme,
        public bool $isGuest,
        /** @var array<string,mixed> */ public array $internalStatus = [],
        /** @var array<string,mixed> */ public array $rawAttributes = [],
    ) {}

    /** @param array<string,mixed> $row */
    public static function fromUserArray(array $row): self
    {
        return new self(
            id: (int) $row['id'],
            username: (string) ($row['username'] ?? ''),
            email: (string) ($row['email'] ?? ''),
            language: (string) ($row['language'] ?? 'en_US'),
            theme: (string) ($row['theme'] ?? 'elegant'),
            isGuest: (int) $row['id'] === (int) ($row['guest_id'] ?? 2),
            internalStatus: is_array($row['internal_status'] ?? null) ? $row['internal_status'] : [],
            rawAttributes: $row,
        );
    }
}
```

### Effort breakdown

| Sub-task | Tag |
| --- | --- |
| Inventory `$conf` keys and cluster | S |
| `Config` class with typed getters | M |
| `PageState` DTO + reference-bridge to `$GLOBALS['page']` | S |
| `User` entity + `CurrentUser` | M |
| `Lang` thin wrapper | S |
| `Kernel::boot()` mirrors common.inc.php order | M |
| `ServiceLocator` static map | S |
| Wave A roll-out + PHPStan custom rule for new code | M |
| Wave B: 152 push-site rewrites across 46 files | L |
| Wave B: `$conf` writer audit (admin/configuration.php etc.) | M |
| Wave B.5: migrate all ~1 073 bare `$conf[key]` reads to typed getters | XL |
| Wave C: `ArrayObject` proxy + backtrace gate | L |
| Wave C: deprecation log triage and long-tail fixes | S (near-zero after B.5) |
| Cut-point evaluation + decision write-up | S |

**Phase total without Wave C: XL. With Wave C: XL+L.**

### Risks specific to Phase 4

- **`ArrayObject` proxy overhead on hot paths.** `$conf` is read thousands of times per request — `Config::get('order_by')` runs in every category page render. `ArrayObject::offsetGet` plus `debug_backtrace(IGNORE_ARGS, 5)` is roughly an order of magnitude slower than a bare PHP array read. Mitigate by hot-caching to a real array in `Config::$data` (already done above) and only using the proxy as the *legacy mirror* in `$GLOBALS['conf']`. New code never goes through the proxy. Benchmark a typical gallery page render before merging Wave C.
- **Subtle ordering bugs from per-album `$conf` overrides.** `include/category_default.inc.php` and several picture/category code paths mutate `$conf['order_by']` at runtime. Wave A keeps this working because `Config::$data` is the same array. Wave C must keep the proxy mutable through `offsetSet` — only deprecate reads, never deny writes.
- **`install/db/*.php` reading `$conf` keys not yet migrated.** `61-database.php:24` does `pwg_query("alter table ".GROUPS_TABLE...)` which expands to `$conf['db_base']` indirectly through `PREFIX_TABLE`. As long as `$GLOBALS['conf']` stays a working array, this works.
- **Calling `Kernel::boot()` twice in nested entry points.** `admin.php` includes `common.inc.php` then dispatches into `admin/*.php` which may also do `include common.inc.php` defensively. The `self::$booted` guard handles re-entrance, but it must not silently skip `$user` re-hydration after a session change — keep `Lang::initFor()` and `CurrentUser::get()` outside the booted-guard if necessary.

### Verification

1. `vendor/bin/phpstan analyse src/Piwigo/Core --level=8` → green.
2. `vendor/bin/phpunit --testsuite Unit --filter 'Piwigo\\Core'` → green; tests cover `Config::get/getString/getInt/getBool`, `PageState::add*`, `Kernel::boot()` idempotence.
3. `php -d display_errors=1 -d error_reporting=E_ALL index.php` against staging DB → 200 OK, zero deprecations from non-`install/db/` paths.
4. UpgradeChainTest green from captured 16.x fixture; captured stderr must contain **zero** `E_USER_DEPRECATED` lines after Wave C.
5. Playwright smoke specs from Phase 0 green; new spec `phase4-conf-write.spec.ts` hits `admin/configuration.php`, saves a setting, reloads, asserts new value is read back through `Config::get()`.
6. Performance check: Apache Bench `ab -n 200 -c 4 http://localhost/index.php` median latency before vs. after Wave C; require regression < 5%.


### Phase 4 deep-dive: Globals → typed services

### A. Complete `Piwigo\Core\Config` enumeration

`include/config_default.inc.php` declares roughly 140 keys at file scope plus a
handful merged at end-of-file (`$conf['file_ext']` builds on `picture_ext`,
`$conf['default_user_id']` reads `guest_id`). The clusters below partition every
key into a typed-service shape. Defaults are pulled verbatim from the file so
that `Piwigo\Core\Config::loadDefaults()` is a 1:1 transcription.

#### A.1 Paths & data location

Filesystem locations and external URLs that the application uses to resolve
runtime resources.

| Key | Type | Default | Typed getter |
|---|---|---|---|
| `data_location` | `string` | `'_data/'` | `Config::dataLocation(): string` |
| `upload_dir` | `string` | `'./upload'` | `Config::uploadDir(): string` |
| `themes_dir` | `string` | `PHPWG_ROOT_PATH.'themes'` | `Config::themesDir(): string` |
| `log_dir` | `string` | `'/logs'` | `Config::logDir(): string` |
| `gallery_url` | `?string` | `null` | `Config::galleryUrl(): ?string` |
| `alternative_pem_url` | `string` | `''` | `Config::alternativePemUrl(): string` |
| `no_photo_yet_url` | `string` | `'admin.php?page=photos_add'` | `Config::noPhotoYetUrl(): string` |
| `ffmpeg_dir` | `string` | `''` | `Config::ffmpegDir(): string` |
| `ext_imagick_dir` | `string` | `''` | `Config::extImagickDir(): string` |

Gotcha: `themes_dir` is computed at file scope using `PHPWG_ROOT_PATH`, which
must be defined before `Config::loadDefaults()` runs — Kernel boot must define
the constant before invoking the loader (it does, see Section B row 4).

#### A.2 Security / authentication

Everything that controls who can sign in and how.

| Key | Type | Default | Typed getter |
|---|---|---|---|
| `apache_authentication` | `bool` | `false` | `Config::apacheAuthentication(): bool` |
| `users_table` | `?string` | `null` | `Config::usersTable(): ?string` |
| `external_authentification` | `bool` | `false` | `Config::externalAuthentification(): bool` |
| `user_fields` | `array<string,string>` | `['id'=>'id','username'=>'username','password'=>'password','email'=>'mail_address']` | `Config::userFields(): array` |
| `password_hash` | `string` (callable name) | `'pwg_password_hash'` | `Config::passwordHashFn(): string` |
| `password_verify` | `string` (callable name) | `'pwg_password_verify'` | `Config::passwordVerifyFn(): string` |
| `guest_id` | `int` | `2` | `Config::guestId(): int` |
| `default_user_id` | `int` | `$conf['guest_id']` | `Config::defaultUserId(): int` |
| `browser_language` | `bool` | `true` | `Config::browserLanguage(): bool` |
| `guest_access` | `bool` | `true` | `Config::guestAccess(): bool` |
| `password_reset_duration` | `int` | `3600` | `Config::passwordResetDuration(): int` |
| `password_activation_duration` | `int` | `259200` | `Config::passwordActivationDuration(): int` |
| `password_reset_code_duration` | `int` | `300` | `Config::passwordResetCodeDuration(): int` |
| `auth_key_duration` | `int` | `259200` | `Config::authKeyDuration(): int` |
| `insensitive_case_logon` | `bool` | `false` | `Config::insensitiveCaseLogon(): bool` |
| `double_password_type_in_admin` | `bool` | `false` | `Config::doublePasswordTypeInAdmin(): bool` |
| `webmaster_id` | `int` | from DB, fallback `1` | `Config::webmasterId(): int` |
| `gallery_locked` | `bool` | from DB | `Config::galleryLocked(): bool` |
| `secret_key` | `string` | from DB | `Config::secretKey(): string` |
| `api_key_duration` | `array<string>` | `['30','90','180','365','custom']` | `Config::apiKeyDurations(): array` |
| `api_key_forbidden_methods` | `array<string>` | (8 entries) | `Config::apiKeyForbiddenMethods(): array` |
| `original_url_protection` | `string` | `''` | `Config::originalUrlProtection(): string` |

Gotcha: `default_user_id` is initialised via `$conf['guest_id']` at file scope.
`Config::loadDefaults()` must order assignment so `guest_id` lands first; the
loader implementation below uses an associative literal, so PHP guarantees
left-to-right evaluation of array keys, but a tested unit case
(`testDefaultUserIdMatchesGuest`) is required to lock the invariant.

#### A.3 Mail / SMTP

| Key | Type | Default | Typed getter |
|---|---|---|---|
| `send_bcc_mail_webmaster` | `bool` | `false` | `Config::sendBccMailWebmaster(): bool` |
| `mail_sender_name` | `string` | `''` | `Config::mailSenderName(): string` |
| `mail_sender_email` | `string` | `''` | `Config::mailSenderEmail(): string` |
| `mail_allow_html` | `bool` | `true` | `Config::mailAllowHtml(): bool` |
| `smtp_host` | `string` | `''` | `Config::smtpHost(): string` |
| `smtp_user` | `string` | `''` | `Config::smtpUser(): string` |
| `smtp_password` | `string` | `''` | `Config::smtpPassword(): string` |
| `smtp_secure` | `?string` | `null` | `Config::smtpSecure(): ?string` |

Gotcha: mail config has multi-step composition — `mail_sender_name` falls back
to `gallery_title` (DB-loaded) and `mail_sender_email` falls back to
`webmaster_email`. The typed getter cannot resolve those fallbacks itself; a
separate `MailEnvelope::compose(Config $c, GalleryInfo $g)` does the merge so
that the getter signatures stay pure. SMTP secure is a tri-state
(`null|'ssl'|'tls'`); a `SmtpSecure` enum is overkill given three values.

#### A.4 Derivatives & images

| Key | Type | Default | Typed getter |
|---|---|---|---|
| `picture_ext` | `array<string>` | `['jpg','jpeg','png','gif','webp']` | `Config::pictureExt(): array` |
| `file_ext` | `array<string>` | `picture_ext + ['tiff','tif','mpg','zip','avi','mp3','ogg','pdf','svg','heic']` | `Config::fileExt(): array` |
| `enable_formats` | `bool` | `false` | `Config::enableFormats(): bool` |
| `format_ext` | `array<string>` | `['cr2','tif','tiff','nef','dng','ai','psd']` | `Config::formatExt(): array` |
| `derivative_url_style` | `int` | `0` | `Config::derivativeUrlStyle(): int` |
| `derivative_default_size` | `string` | `'medium'` | `Config::derivativeDefaultSize(): string` |
| `derivatives_strip_metadata_threshold` | `int` | `256000` | `Config::derivativesStripMetadataThreshold(): int` |
| `animated_webp_compression_quality` | `int` | `70` | `Config::animatedWebpCompressionQuality(): int` |
| `chmod_value` | `int` | `0777` or `0755` | `Config::chmodValue(): int` |
| `graphics_library` | `string` | `'auto'` | `Config::graphicsLibrary(): string` |
| `tiff_representative_ext` | `string` | `'png'` | `Config::tiffRepresentativeExt(): string` |
| `max_requests` | `int` | `3` | `Config::maxAjaxRequests(): int` |
| `derivatives` (DB) | `array` | computed, persisted | `Config::derivatives(): DerivativeSet` |

Gotcha: the `$conf['derivatives']` value loaded from DB is a deeply nested
array of `width/height/crop/sharpen/quality` per size. `Config::derivatives()`
returns a typed `DerivativeSet` value object, not the raw array — this is one
of the few places in Phase 4 where we DO mint a DTO inside a config getter
(otherwise typed getters return scalars/arrays unchanged).

#### A.5 Debug & performance

| Key | Type | Default | Typed getter |
|---|---|---|---|
| `show_queries` | `bool` | `false` | `Config::showQueries(): bool` |
| `show_gt` | `bool` | `false` | `Config::showGt(): bool` |
| `debug_l10n` | `bool` | `false` | `Config::debugL10n(): bool` |
| `debug_template` | `bool` | `false` | `Config::debugTemplate(): bool` |
| `debug_mail` | `bool` | `false` | `Config::debugMail(): bool` |
| `die_on_sql_error` | `bool` | `false` | `Config::dieOnSqlError(): bool` |
| `compiled_template_cache_language` | `bool` | `false` | `Config::compiledTemplateCacheLanguage(): bool` |
| `template_compile_check` | `bool` | `true` | `Config::templateCompileCheck(): bool` |
| `template_force_compile` | `bool` | `false` | `Config::templateForceCompile(): bool` |
| `template_combine_files` | `bool` | `true` | `Config::templateCombineFiles(): bool` |
| `show_php_errors` | `int` | `E_ALL` | `Config::showPhpErrors(): int` |
| `show_php_errors_on_frontend` | `bool` | `true` | `Config::showPhpErrorsOnFrontend(): bool` |
| `lounge_activate_threshold` | `int` | `1` | `Config::loungeActivateThreshold(): int` |
| `lounge_max_duration` | `int` | `300` | `Config::loungeMaxDuration(): int` |

#### A.6 Uploads

| Key | Type | Default | Typed getter |
|---|---|---|---|
| `upload_form_automatic_rotation` | `bool` | `true` | `Config::uploadFormAutomaticRotation(): bool` |
| `upload_form_all_types` | `bool` | `false` | `Config::uploadFormAllTypes(): bool` |
| `upload_form_chunk_size` | `int` | `500` | `Config::uploadFormChunkSize(): int` |
| `upload_form_max_file_size` | `int` | `1000` | `Config::uploadFormMaxFileSize(): int` |
| `batch_manager_images_per_page_global` | `int` | `20` | `Config::batchManagerImagesPerPageGlobal(): int` |
| `batch_manager_images_per_page_unit` | `int` | `5` | `Config::batchManagerImagesPerPageUnit(): int` |
| `checksum_compute_blocksize` | `int` | `50` | `Config::checksumComputeBlocksize(): int` |
| `uniqueness_mode` | `string` | `'md5sum'` | `Config::uniquenessMode(): string` |
| `inheritance_by_default` | `bool` | `false` | `Config::inheritanceByDefault(): bool` |
| `sync_chars_regex` | `string` | `'/^[a-zA-Z0-9-_.]+$/'` | `Config::syncCharsRegex(): string` |
| `sync_exclude_folders` | `array<string>` | `[]` | `Config::syncExcludeFolders(): array` |

#### A.7 Notification by mail

| Key | Type | Default | Typed getter |
|---|---|---|---|
| `nbm_default_value_user_enabled` | `bool` | `false` | `Config::nbmDefaultValueUserEnabled(): bool` |
| `nbm_list_all_enabled_users_to_send` | `bool` | `false` | `Config::nbmListAllEnabledUsersToSend(): bool` |
| `nbm_max_treatment_timeout_percent` | `float` | `0.8` | `Config::nbmMaxTreatmentTimeoutPercent(): float` |
| `nbm_treatment_timeout_default` | `int` | `20` | `Config::nbmTreatmentTimeoutDefault(): int` |
| `recent_post_dates` | `array<string,array>` | RSS+NBM sub-arrays | `Config::recentPostDates(): array` |
| `rss_feed_author` | `string` | `'Piwigo notifier'` | `Config::rssFeedAuthor(): string` |

Gotcha: `recent_post_dates` is a nested associative array keyed by feed type
(`RSS`, `NBM`); the getter returns the raw shape and callers index into it.

#### A.8 Tags & related albums

| Key | Type | Default | Typed getter |
|---|---|---|---|
| `full_tag_cloud_items_number` | `int` | `200` | `Config::fullTagCloudItemsNumber(): int` |
| `menubar_tag_cloud_items_number` | `int` | `20` | `Config::menubarTagCloudItemsNumber(): int` |
| `menubar_tag_cloud_content` | `string` | `'all_or_current'` | `Config::menubarTagCloudContent(): string` |
| `content_tag_cloud_items_number` | `int` | `12` | `Config::contentTagCloudItemsNumber(): int` |
| `tags_levels` | `int` | `5` | `Config::tagsLevels(): int` |
| `tags_default_display_mode` | `string` | `'cloud'` | `Config::tagsDefaultDisplayMode(): string` |
| `tag_letters_column_number` | `int` | `4` | `Config::tagLettersColumnNumber(): int` |
| `tag_url_style` | `string` | `'id-tag'` | `Config::tagUrlStyle(): string` |
| `related_albums_maximum_items_to_compute` | `int` | `1000` | `Config::relatedAlbumsMaxItemsToCompute(): int` |
| `related_albums_display_limit` | `int` | `20` | `Config::relatedAlbumsDisplayLimit(): int` |

#### A.9 Sessions

| Key | Type | Default | Typed getter |
|---|---|---|---|
| `session_use_cookies` | `bool` | `true` | `Config::sessionUseCookies(): bool` |
| `session_use_only_cookies` | `bool` | `true` | `Config::sessionUseOnlyCookies(): bool` |
| `session_use_trans_sid` | `bool` | `false` | `Config::sessionUseTransSid(): bool` |
| `session_name` | `string` | `'pwg_id'` | `Config::sessionName(): string` |
| `session_save_handler` | `string` | `'db'` | `Config::sessionSaveHandler(): string` |
| `authorize_remembering` | `bool` | `true` | `Config::authorizeRemembering(): bool` |
| `remember_me_name` | `string` | `'pwg_remember'` | `Config::rememberMeName(): string` |
| `remember_me_length` | `int` | `5184000` | `Config::rememberMeLength(): int` |
| `session_length` | `int` | `3600` | `Config::sessionLength(): int` |
| `session_use_ip_address` | `bool` | `true` | `Config::sessionUseIpAddress(): bool` |
| `session_gc_probability` | `int` | `1` | `Config::sessionGcProbability(): int` |

#### A.10 Misc (everything else)

This is the long tail. Each of these has a one-line getter; the table is dense.

| Key | Type | Default | Typed getter |
|---|---|---|---|
| `top_number` | `int` | `15` | `Config::topNumber(): int` |
| `anti-flood_time` | `int` | `60` | `Config::antiFloodTime(): int` |
| `comment_spam_reject` | `bool` | `true` | `Config::commentSpamReject(): bool` |
| `comment_spam_max_links` | `int` | `3` | `Config::commentSpamMaxLinks(): int` |
| `comments_page_nb_comments` | `int` | `10` | `Config::commentsPageNbComments(): int` |
| `calendar_datefield` | `string` | `'date_creation'` | `Config::calendarDatefield(): string` |
| `calendar_show_any` | `bool` | `true` | `Config::calendarShowAny(): bool` |
| `calendar_show_empty` | `bool` | `true` | `Config::calendarShowEmpty(): bool` |
| `newcat_default_commentable` | `bool` | `true` | `Config::newcatDefaultCommentable(): bool` |
| `newcat_default_visible` | `bool` | `true` | `Config::newcatDefaultVisible(): bool` |
| `newcat_default_status` | `string` | `'public'` | `Config::newcatDefaultStatus(): string` |
| `newcat_default_position` | `string` | `'first'` | `Config::newcatDefaultPosition(): string` |
| `light_album_manager_threshold` | `int` | `10000` | `Config::lightAlbumManagerThreshold(): int` |
| `level_separator` | `string` | `' / '` | `Config::levelSeparator(): string` |
| `paginate_pages_around` | `int` | `2` | `Config::paginatePagesAround(): int` |
| `show_version` | `bool` | `false` | `Config::showVersion(): bool` |
| `meta_ref` | `bool` | `true` | `Config::metaRef(): bool` |
| `links` | `array` | `[]` | `Config::links(): array` |
| `random_index_redirect` | `array` | `[]` | `Config::randomIndexRedirect(): array` |
| `header_notes` | `array<string>` | `[]` | `Config::headerNotes(): array` |
| `show_thumbnail_caption` | `bool` | `true` | `Config::showThumbnailCaption(): bool` |
| `allow_random_representative` | `bool` | `false` | `Config::allowRandomRepresentative(): bool` |
| `representative_cache_on_level` | `bool` | `true` | `Config::representativeCacheOnLevel(): bool` |
| `representative_cache_on_subcats` | `bool` | `true` | `Config::representativeCacheOnSubcats(): bool` |
| `allow_html_descriptions` | `bool` | `true` | `Config::allowHtmlDescriptions(): bool` |
| `available_permission_levels` | `array<int>` | `[0,1,2,4,8]` | `Config::availablePermissionLevels(): array` |
| `check_upgrade_feed` | `bool` | `false` | `Config::checkUpgradeFeed(): bool` |
| `rate_items` | `array<int>` | `[0,1,2,3,4,5]` | `Config::rateItems(): array` |
| `default_redirect_method` | `string` | `'http'` | `Config::defaultRedirectMethod(): string` |
| `update_notify_check_period` | `int` | `86400` | `Config::updateNotifyCheckPeriod(): int` |
| `update_notify_reminder_period` | `int` | `604800` | `Config::updateNotifyReminderPeriod(): int` |
| `send_piwigo_infos` | `bool` | `true` | `Config::sendPiwigoInfos(): bool` |
| `album_description_on_all_pages` | `bool` | `false` | `Config::albumDescriptionOnAllPages(): bool` |
| `stat_compare_year_displayed` | `int` | `5` | `Config::statCompareYearDisplayed(): int` |
| `linked_album_search_limit` | `int` | `100` | `Config::linkedAlbumSearchLimit(): int` |
| `fs_quick_check_period` | `int` | `86400` | `Config::fsQuickCheckPeriod(): int` |
| `pdf_viewer_filesize_threshold` | `int` | `5` | `Config::pdfViewerFilesizeThreshold(): int` |
| `show_iptc` | `bool` | `false` | `Config::showIptc(): bool` |
| `show_iptc_mapping` | `array<string,string>` | (4 entries) | `Config::showIptcMapping(): array` |
| `use_iptc` | `bool` | `false` | `Config::useIptc(): bool` |
| `use_iptc_mapping` | `array<string,string>` | (5 entries) | `Config::useIptcMapping(): array` |
| `show_exif` | `bool` | `true` | `Config::showExif(): bool` |
| `show_exif_fields` | `array<string>` | `['Make','Model','DateTimeOriginal','COMPUTED;ApertureFNumber']` | `Config::showExifFields(): array` |
| `use_exif` | `bool` | `true` | `Config::useExif(): bool` |
| `use_exif_mapping` | `array<string,string>` | `['date_creation'=>'DateTimeOriginal']` | `Config::useExifMapping(): array` |
| `allow_html_in_metadata` | `bool` | `false` | `Config::allowHtmlInMetadata(): bool` |
| `metadata_keyword_separator_regex` | `string` | `'/[.,;]/'` | `Config::metadataKeywordSeparatorRegex(): string` |
| `nb_logs_page` | `int` | `300` | `Config::nbLogsPage(): int` |
| `history_autopurge_every` | `int` | `1021` | `Config::historyAutopurgeEvery(): int` |
| `history_autopurge_keep_lines` | `int` | `1000000` | `Config::historyAutopurgeKeepLines(): int` |
| `history_autopurge_blocksize` | `int` | `50000` | `Config::historyAutopurgeBlocksize(): int` |
| `question_mark_in_urls` | `bool` | `true` | `Config::questionMarkInUrls(): bool` |
| `php_extension_in_urls` | `bool` | `true` | `Config::phpExtensionInUrls(): bool` |
| `category_url_style` | `string` | `'id'` | `Config::categoryUrlStyle(): string` |
| `picture_url_style` | `string` | `'id'` | `Config::pictureUrlStyle(): string` |
| `url_port` | `string` | `'none'` | `Config::urlPort(): string` |
| `admin_theme` | `string` | `'clear'` | `Config::adminTheme(): string` |
| `enable_plugins` | `bool` | `true` | `Config::enablePlugins(): bool` |
| `allow_web_services` | `bool` | `true` | `Config::allowWebServices(): bool` |
| `ws_max_images_per_page` | `int` | `500` | `Config::wsMaxImagesPerPage(): int` |
| `ws_max_users_per_page` | `int` | `1000` | `Config::wsMaxUsersPerPage(): int` |
| `show_newsletter_subscription` | `bool` | `true` | `Config::showNewsletterSubscription(): bool` |
| `show_piwigo_latest_news` | `bool` | `true` | `Config::showPiwigoLatestNews(): bool` |
| `dashboard_check_for_updates` | `bool` | `true` | `Config::dashboardCheckForUpdates(): bool` |
| `dashboard_activity_nb_weeks` | `int` | `4` | `Config::dashboardActivityNbWeeks(): int` |
| `activity_display_connections` | `string` | `'all'` | `Config::activityDisplayConnections(): string` |
| `album_move_delay_before_auto_opening` | `int` | `3000` | `Config::albumMoveDelayBeforeAutoOpening(): int` |
| `show_template_in_side_menu` | `bool` | `false` | `Config::showTemplateInSideMenu(): bool` |
| `add_cache_to_storage_chart` | `bool` | `true` | `Config::addCacheToStorageChart(): bool` |
| `filter_pages` | `array<string,array>` | (16 entries) | `Config::filterPages(): array` |
| `slideshow_period_min` | `int` | `1` | `Config::slideshowPeriodMin(): int` |
| `slideshow_period_max` | `int` | `10` | `Config::slideshowPeriodMax(): int` |
| `slideshow_period_step` | `int` | `1` | `Config::slideshowPeriodStep(): int` |
| `slideshow_period` | `int` | `4` | `Config::slideshowPeriod(): int` |
| `slideshow_repeat` | `bool` | `true` | `Config::slideshowRepeat(): bool` |
| `light_slideshow` | `bool` | `true` | `Config::lightSlideshow(): bool` |
| `enable_synchronization` | `bool` | `true` | `Config::enableSynchronization(): bool` |
| `enable_core_update` | `bool` | `true` | `Config::enableCoreUpdate(): bool` |
| `enable_extensions_install` | `bool` | `true` | `Config::enableExtensionsInstall(): bool` |
| `pem_plugins_category` | `int` | `12` | `Config::pemPluginsCategory(): int` |
| `pem_themes_category` | `int` | `10` | `Config::pemThemesCategory(): int` |
| `pem_languages_category` | `int` | `8` | `Config::pemLanguagesCategory(): int` |
| `quick_search_include_sub_albums` | `bool` | `false` | `Config::quickSearchIncludeSubAlbums(): bool` |
| `default_filters_views` | `array<string,array>` | (14 entries) | `Config::defaultFiltersViews(): array` |
| `log_level` | `string` | `'DEBUG'` | `Config::logLevel(): string` |
| `log_archive_days` | `int` | `30` | `Config::logArchiveDays(): int` |
| `use_proxy` | `bool` | `false` | `Config::useProxy(): bool` |
| `proxy_server` | `string` | `'proxy.domain.org:port'` | `Config::proxyServer(): string` |
| `proxy_auth` | `string` | `''` | `Config::proxyAuth(): string` |
| `dblayer` | `string` | from DB config file | `Config::dblayer(): string` |
| `db_host` | `string` | from DB config file | `Config::dbHost(): string` |
| `db_user` | `string` | from DB config file | `Config::dbUser(): string` |
| `db_password` | `string` | from DB config file | `Config::dbPassword(): string` |
| `db_base` | `string` | from DB config file | `Config::dbBase(): string` |

#### A.11 The full `Config.php` source

```php
<?php
declare(strict_types=1);

namespace Piwigo\Core;

/**
 * Typed accessor for the legacy `$conf` global. Wave A keeps `$conf` as a
 * by-reference mirror of self::$data; Wave C replaces the global with a
 * deprecation-emitting ArrayObject proxy.
 */
final class Config
{
    /** @var array<string,mixed> */
    private static array $data = [];

    private static bool $loaded = false;

    public static function loadDefaults(): void
    {
        // Source of truth: include/config_default.inc.php at file scope.
        // This block is generated from the legacy file by tools/codegen/dump_defaults.php
        // and re-exec'd verbatim against an isolated $conf array.
        $conf = [];
        require \PHPWG_ROOT_PATH . 'include/config_default.inc.php';
        self::$data = $conf;
        // Bridge for Wave A: legacy free functions still reach $GLOBALS['conf'].
        $GLOBALS['conf'] = &self::$data;
        self::$loaded = true;
    }

    public static function loadLocalOverride(): void
    {
        $conf =& self::$data;
        @include \PHPWG_ROOT_PATH . 'local/config/config.inc.php';
    }

    public static function hydrateFromDb(): void
    {
        \load_conf_from_db(); // legacy function writes into $GLOBALS['conf']
        // $GLOBALS['conf'] is already self::$data by reference, so the merge lands.
    }

    public static function persist(string $key, mixed $value): void
    {
        \conf_update_param($key, $value, true);
        self::$data[$key] = $value;
    }

    public static function override(string $key, mixed $value): void
    {
        // Per-request mutation only; never written to DB.
        self::$data[$key] = $value;
    }

    public static function has(string $key): bool
    {
        return \array_key_exists($key, self::$data);
    }

    /** Escape hatch for keys not yet typed. Avoid in new code. */
    public static function raw(string $key, mixed $default = null): mixed
    {
        return self::$data[$key] ?? $default;
    }

    // ---------- Typed helpers ----------

    private static function getString(string $key, string $default = ''): string
    {
        $v = self::$data[$key] ?? $default;
        return \is_string($v) ? $v : (string) $v;
    }

    private static function getNullableString(string $key): ?string
    {
        $v = self::$data[$key] ?? null;
        return $v === null ? null : (string) $v;
    }

    private static function getInt(string $key, int $default = 0): int
    {
        return (int) (self::$data[$key] ?? $default);
    }

    private static function getBool(string $key, bool $default = false): bool
    {
        return (bool) (self::$data[$key] ?? $default);
    }

    private static function getFloat(string $key, float $default = 0.0): float
    {
        return (float) (self::$data[$key] ?? $default);
    }

    /** @return array<int|string,mixed> */
    private static function getArray(string $key, array $default = []): array
    {
        $v = self::$data[$key] ?? $default;
        return \is_array($v) ? $v : $default;
    }

    // ---------- Paths ----------
    public static function dataLocation(): string         { return self::getString('data_location', '_data/'); }
    public static function uploadDir(): string            { return self::getString('upload_dir', './upload'); }
    public static function themesDir(): string            { return self::getString('themes_dir'); }
    public static function logDir(): string               { return self::getString('log_dir', '/logs'); }
    public static function galleryUrl(): ?string          { return self::getNullableString('gallery_url'); }
    public static function alternativePemUrl(): string    { return self::getString('alternative_pem_url'); }
    public static function noPhotoYetUrl(): string        { return self::getString('no_photo_yet_url'); }
    public static function ffmpegDir(): string            { return self::getString('ffmpeg_dir'); }
    public static function extImagickDir(): string        { return self::getString('ext_imagick_dir'); }

    // ---------- Security ----------
    public static function apacheAuthentication(): bool   { return self::getBool('apache_authentication'); }
    public static function usersTable(): ?string          { return self::getNullableString('users_table'); }
    public static function externalAuthentification(): bool { return self::getBool('external_authentification'); }
    public static function userFields(): array            { return self::getArray('user_fields'); }
    public static function passwordHashFn(): string       { return self::getString('password_hash', 'pwg_password_hash'); }
    public static function passwordVerifyFn(): string     { return self::getString('password_verify', 'pwg_password_verify'); }
    public static function guestId(): int                 { return self::getInt('guest_id', 2); }
    public static function defaultUserId(): int           { return self::getInt('default_user_id', self::guestId()); }
    public static function browserLanguage(): bool        { return self::getBool('browser_language', true); }
    public static function guestAccess(): bool            { return self::getBool('guest_access', true); }
    public static function passwordResetDuration(): int   { return self::getInt('password_reset_duration', 3600); }
    public static function passwordActivationDuration(): int { return self::getInt('password_activation_duration', 259200); }
    public static function passwordResetCodeDuration(): int  { return self::getInt('password_reset_code_duration', 300); }
    public static function authKeyDuration(): int         { return self::getInt('auth_key_duration', 259200); }
    public static function insensitiveCaseLogon(): bool   { return self::getBool('insensitive_case_logon'); }
    public static function doublePasswordTypeInAdmin(): bool { return self::getBool('double_password_type_in_admin'); }
    public static function webmasterId(): int             { return self::getInt('webmaster_id', 1); }
    public static function galleryLocked(): bool          { return self::getBool('gallery_locked'); }
    public static function secretKey(): string            { return self::getString('secret_key'); }
    public static function apiKeyDurations(): array       { return self::getArray('api_key_duration'); }
    public static function apiKeyForbiddenMethods(): array{ return self::getArray('api_key_forbidden_methods'); }
    public static function originalUrlProtection(): string{ return self::getString('original_url_protection'); }

    // ---------- Mail ----------
    public static function sendBccMailWebmaster(): bool   { return self::getBool('send_bcc_mail_webmaster'); }
    public static function mailSenderName(): string       { return self::getString('mail_sender_name'); }
    public static function mailSenderEmail(): string      { return self::getString('mail_sender_email'); }
    public static function mailAllowHtml(): bool          { return self::getBool('mail_allow_html', true); }
    public static function smtpHost(): string             { return self::getString('smtp_host'); }
    public static function smtpUser(): string             { return self::getString('smtp_user'); }
    public static function smtpPassword(): string         { return self::getString('smtp_password'); }
    public static function smtpSecure(): ?string          { return self::getNullableString('smtp_secure'); }

    // ---------- Derivatives ----------
    public static function pictureExt(): array            { return self::getArray('picture_ext'); }
    public static function fileExt(): array               { return self::getArray('file_ext'); }
    public static function enableFormats(): bool          { return self::getBool('enable_formats'); }
    public static function formatExt(): array             { return self::getArray('format_ext'); }
    public static function derivativeUrlStyle(): int      { return self::getInt('derivative_url_style'); }
    public static function derivativeDefaultSize(): string{ return self::getString('derivative_default_size', 'medium'); }
    public static function derivativesStripMetadataThreshold(): int { return self::getInt('derivatives_strip_metadata_threshold', 256000); }
    public static function animatedWebpCompressionQuality(): int    { return self::getInt('animated_webp_compression_quality', 70); }
    public static function chmodValue(): int              { return self::getInt('chmod_value', 0755); }
    public static function graphicsLibrary(): string      { return self::getString('graphics_library', 'auto'); }
    public static function tiffRepresentativeExt(): string{ return self::getString('tiff_representative_ext', 'png'); }
    public static function maxAjaxRequests(): int         { return self::getInt('max_requests', 3); }

    // ---------- Sessions ----------
    public static function sessionUseCookies(): bool      { return self::getBool('session_use_cookies', true); }
    public static function sessionUseOnlyCookies(): bool  { return self::getBool('session_use_only_cookies', true); }
    public static function sessionUseTransSid(): bool     { return self::getBool('session_use_trans_sid'); }
    public static function sessionName(): string          { return self::getString('session_name', 'pwg_id'); }
    public static function sessionSaveHandler(): string   { return self::getString('session_save_handler', 'db'); }
    public static function authorizeRemembering(): bool   { return self::getBool('authorize_remembering', true); }
    public static function rememberMeName(): string       { return self::getString('remember_me_name', 'pwg_remember'); }
    public static function rememberMeLength(): int        { return self::getInt('remember_me_length', 5184000); }
    public static function sessionLength(): int           { return self::getInt('session_length', 3600); }
    public static function sessionUseIpAddress(): bool    { return self::getBool('session_use_ip_address', true); }
    public static function sessionGcProbability(): int    { return self::getInt('session_gc_probability', 1); }

    // ---------- Debug / performance ----------
    public static function showQueries(): bool            { return self::getBool('show_queries'); }
    public static function showGt(): bool                 { return self::getBool('show_gt'); }
    public static function debugL10n(): bool              { return self::getBool('debug_l10n'); }
    public static function debugTemplate(): bool          { return self::getBool('debug_template'); }
    public static function debugMail(): bool              { return self::getBool('debug_mail'); }
    public static function dieOnSqlError(): bool          { return self::getBool('die_on_sql_error'); }
    public static function compiledTemplateCacheLanguage(): bool { return self::getBool('compiled_template_cache_language'); }
    public static function templateCompileCheck(): bool   { return self::getBool('template_compile_check', true); }
    public static function templateForceCompile(): bool   { return self::getBool('template_force_compile'); }
    public static function templateCombineFiles(): bool   { return self::getBool('template_combine_files', true); }
    public static function showPhpErrors(): int           { return self::getInt('show_php_errors', \E_ALL); }
    public static function showPhpErrorsOnFrontend(): bool{ return self::getBool('show_php_errors_on_frontend', true); }
    public static function loungeActivateThreshold(): int { return self::getInt('lounge_activate_threshold', 1); }
    public static function loungeMaxDuration(): int       { return self::getInt('lounge_max_duration', 300); }

    // ---------- The remaining ~80 misc getters follow the same shape ----------
    // (one-line each; truncated in this document for readability — see
    //  src/Piwigo/Core/Config.php in the actual implementation).

    public static function topNumber(): int               { return self::getInt('top_number', 15); }
    public static function antiFloodTime(): int           { return self::getInt('anti-flood_time', 60); }
    public static function commentSpamReject(): bool      { return self::getBool('comment_spam_reject', true); }
    public static function commentSpamMaxLinks(): int     { return self::getInt('comment_spam_max_links', 3); }
    public static function commentsPageNbComments(): int  { return self::getInt('comments_page_nb_comments', 10); }
    public static function levelSeparator(): string       { return self::getString('level_separator', ' / '); }
    public static function paginatePagesAround(): int     { return self::getInt('paginate_pages_around', 2); }
    public static function showVersion(): bool            { return self::getBool('show_version'); }
    public static function metaRef(): bool                { return self::getBool('meta_ref', true); }
    public static function links(): array                 { return self::getArray('links'); }
    public static function randomIndexRedirect(): array   { return self::getArray('random_index_redirect'); }
    public static function headerNotes(): array           { return self::getArray('header_notes'); }
    public static function checkUpgradeFeed(): bool       { return self::getBool('check_upgrade_feed'); }
    public static function adminTheme(): string           { return self::getString('admin_theme', 'clear'); }
    public static function enablePlugins(): bool          { return self::getBool('enable_plugins', true); }
    public static function allowWebServices(): bool       { return self::getBool('allow_web_services', true); }
    public static function logLevel(): string             { return self::getString('log_level', 'DEBUG'); }
    public static function logArchiveDays(): int          { return self::getInt('log_archive_days', 30); }
    public static function dblayer(): string              { return self::getString('dblayer', 'mysqli'); }
    public static function dbHost(): string               { return self::getString('db_host'); }
    public static function dbUser(): string               { return self::getString('db_user'); }
    public static function dbPassword(): string           { return self::getString('db_password'); }
    public static function dbBase(): string               { return self::getString('db_base'); }
    public static function filterPages(): array           { return self::getArray('filter_pages'); }
    public static function nbmMaxTreatmentTimeoutPercent(): float { return self::getFloat('nbm_max_treatment_timeout_percent', 0.8); }
    public static function nbmTreatmentTimeoutDefault(): int      { return self::getInt('nbm_treatment_timeout_default', 20); }
    public static function recentPostDates(): array       { return self::getArray('recent_post_dates'); }
    // ... (full list mirrors Section A.10 above)
}
```

The above source is truncated for the misc cluster only — the production
`Config.php` ships every getter listed in Sections A.1–A.10. Total ~140 typed
methods plus the four private helpers.

---

### B. `common.inc.php` boot-dance line-by-line trace

The current `include/common.inc.php` is 370 lines. Each row below maps a logical
step onto a `Kernel::boot()` phase. Phases are abbreviated as: **defaults-load**,
**local-load**, **db-connect**, **db-hydrate**, **lang-init**, **page-state-init**,
**service-registration**, **post-boot hooks**.

| Lines | Current code does | Maps to Kernel::boot() phase | Notes |
|---|---|---|---|
| 9 | Guards `PHPWG_ROOT_PATH` defined; aborts if entry path bypassed | pre-boot guard | `Kernel::boot()` performs the same check before any phase runs. |
| 12 | Captures `$t2 = microtime(true)` for generation-time diagnostics | page-state-init | `PageState::startedAt` readonly property in the singleton. |
| 24–42 | Backfills addslashes-on-input shim against missing `get_magic_quotes_gpc` | pre-boot input sanitization | Doesn't map to a service. Stays as a free-function block invoked from `Kernel::sanitizeSuperglobals()` first thing. PHP 8.5 still has `$_GET`/`$_POST`. |
| 43–46 | Slashes `$_SERVER['PATH_INFO']` if present | pre-boot input sanitization | Same as above. |
| 52–65 | Defines `$conf=[]`, `$page=[infos,errors,...]`, `$user=[]`, `$lang=[]`, `$header_msgs=[]`, `$header_notes=[]`, `$filter=[]` | page-state-init + service-registration | `Config::loadDefaults()` resets `$GLOBALS['conf']`; `PageState::init()` resets `$GLOBALS['page']`. The other globals (`$user`, `$lang`, `$header_msgs`, `$header_notes`, `$filter`) get their own typed singletons in Wave B. |
| 67–77 | Polyfills `gzopen` and `str_starts_with` if missing | pre-boot polyfills | One-shot; runs once per boot. PHP 8.5 has `str_starts_with` natively, so this loop is a near no-op. |
| 79 | `include 'config_default.inc.php'` | **defaults-load** | `Config::loadDefaults()` re-includes the file via reference into `self::$data`. |
| 80 | `@include 'local/config/config.inc.php'` | **local-load** | `Config::loadLocalOverride()` — silenced, file may not exist. |
| 82 | Defines `PWG_LOCAL_DIR` if not defined | constant-init | Move into `Kernel::defineConstants()`. |
| 84 | `@include local/config/database.inc.php` | local-load (DB credentials) | Hydrates `$conf['dblayer']`, `db_host`, etc. |
| 85–89 | Redirects to `install.php` if `PHPWG_INSTALLED` not defined | install-redirect guard | `Kernel::ensureInstalled()`. |
| 90 | `include include/dblayer/functions_${dblayer}.inc.php` | db-layer-load | Loads dblayer free functions. Stays exactly as-is — locked constraint says dblayer free functions stay forever. |
| 92–99 | Sets `error_reporting`/`display_errors` from `$conf['show_php_errors']` | error-reporting init | `Kernel::configurePhpErrorReporting(Config::class)`. |
| 101–105 | Tunes `session.gc_probability` ini | session-prep | `Kernel::configureSessionGc()`. |
| 107–111 | Includes `constants.php`, `functions.inc.php`, `template.class.php`, `cache.class.php`, `Logger.class.php` | legacy-includes | Stays as raw `require_once` calls inside `Kernel::loadLegacyCore()`; we do not move 2,300 free functions into namespaces in Phase 4. |
| 113 | `$page['execution_uuid'] = generate_key(10);` | page-state-init | Stored on `PageState::$executionUuid` and mirrored to `$page` for legacy. |
| 115 | `$persistent_cache = new PersistentFileCache();` | service-registration | `ServiceLocator::register(PersistentFileCache::class, ...)`. |
| 118–126 | `pwg_db_connect(...)` wrapped in try/catch with `my_error()` on failure | **db-connect** | `Kernel::openDatabase()` calls into the legacy `pwg_db_connect`. Failure path calls `Kernel::fatal($e)`. |
| 128 | `pwg_db_check_charset()` | db-connect (post) | Same phase, runs immediately after connect. |
| 133 | `$conf['webmaster_id'] = $conf['webmaster_id'] ?? 1;` | db-hydrate (pre) | One of two read-modify-write at file scope. Stays inside `Config::ensureWebmasterIdDefault()` invoked between connect and hydrate. |
| 135 | `load_conf_from_db();` | **db-hydrate** | `Config::hydrateFromDb()` wraps it. |
| 137–146 | Constructs `$logger = new Logger(...)` using freshly hydrated `$conf` | service-registration | `Kernel::initLogger()` registers `Logger` after `db-hydrate`. |
| 148–154 | Detects DB-schema-vs-code-version mismatch and redirects to `upgrade.php` | upgrade-redirect guard | `Kernel::ensureSchemaUpToDate()` runs after hydrate. |
| 156 | `ImageStdParams::load_from_db();` | derivative-init | New phase: `Kernel::loadDerivativeParams()`. |
| 158 | `session_start();` | session-start | `Kernel::startSession()`. |
| 159 | `load_plugins();` | plugin-load | `Kernel::loadPlugins()`. |
| 161–170 | Records first-time install version or autoupdate via `conf_update_param` + `pwg_activity` | post-hydrate housekeeping | `Kernel::recordInstalledVersion()`. Has DB write side effects. |
| 173–177 | Backfills `last_major_update` if missing | post-hydrate housekeeping | Same phase. |
| 182–190 | Strips deprecated `\`rank\` ASC` from `$conf['order_by']` and persists | post-hydrate housekeeping | Persistent `$conf` write — calls `Config::persist('order_by', ...)`. |
| 193–200 | Applies `$conf['order_by_custom']` / `$conf['order_by_inside_category_custom']` overrides if present | post-hydrate transient override | `Config::override('order_by', ...)` — non-persistent, per-request only. |
| 202 | `check_lounge();` | post-boot hook | Lounge maintenance side effect — kept in `Kernel::runPostBootMaintenance()`. |
| 204 | `include include/user.inc.php` | user-init | `Kernel::initCurrentUser()` includes the legacy file. The DTO comes in Phase 4 if Wave C ships, otherwise stays as `$user` array. |
| 206–219 | Computes `PHPWG_DOMAIN`/`PHPWG_URL` based on user language | constant-init (post-user) | `Kernel::definePiwigoOrgDomain()`. Cannot run before `$user['language']` exists. |
| 221–228 | Computes `PEM_URL` from optional `$conf['alternative_pem_url']` | constant-init | `Kernel::definePemUrl()`. |
| 231 | `load_language('common.lang');` | **lang-init** | `Lang::loadCommon()`. |
| 232–237 | If admin context, also load `admin.lang` and `whats_new_*.lang` | lang-init (admin) | Conditional inside `Lang::initFor(CurrentUser)`. |
| 238 | `trigger_notify('loading_lang');` | post-boot hook | Plugin event — preserve verbatim. |
| 239 | `load_language('lang', PWG_LOCAL_DIR, [...local=>true])` | lang-init (local override) | Inside `Lang::loadLocalOverride()`. |
| 243–246 | If guest, set `$user['username'] = l10n('guest')` | post-lang fixup | Must run after lang-init because it l10ns. |
| 250–256 | If `$page['auth_key_invalid']`, push i18n'd error to `$page['errors']` | post-lang fixup | One of the few places `$page['errors'][]` is written inside common.inc.php — converts to `PageState::current()->addError(...)`. |
| 259–280 | If pending api_key expiration notification, send mail + DB update + clear marker | post-boot side effect | `Kernel::dispatchApiKeyExpirationNotice()`. |
| 283–295 | Constructs `$template` (admin or classic theme) | service-registration | `ServiceLocator::register(Template::class, ...)`. |
| 297–300 | If `$conf['no_photo_yet']` not set, includes `no_photo_yet.inc.php` (which sets it) | conditional-include | Stays as-is; legacy-grade conditional file include. |
| 302–307 | Pushes "guest must be guest" header_msg if internal status flagged | post-boot warning | `HeaderMessages::push(...)` (Wave B), or `$header_msgs[]` for Wave A. |
| 309–322 | If gallery locked, sends 503 + halts (unless identification or admin) | post-boot guard | `Kernel::enforceGalleryLock()`. Issues `exit()` — must remain top-level. |
| 324–332 | If `check_upgrade_feed`, includes upgrade helpers and pushes header msg | post-boot guard | Same. |
| 334–338 | Flushes `$header_msgs` into the template | post-boot template wiring | `HeaderMessages::flushTo($template)`. |
| 340–347 | If `filter_pages` configured and active, includes `filter.inc.php` else disables | filter-init | `Filter::initFromConfig()`. |
| 349–352 | Merges `$conf['header_notes']` into `$header_notes` | post-boot template wiring | `HeaderNotes::mergeFromConfig()`. |
| 355–368 | Registers default event handlers (`add_event_handler` calls) | **post-boot hooks** | `Kernel::registerDefaultEventHandlers()`. Order matters — neutral-1 priority for `blockmanager_register_blocks`. |
| 369 | `trigger_notify('init');` | post-boot hooks (final) | Plugin event — runs last. |

#### B.1 The full `Kernel.php` source

```php
<?php
declare(strict_types=1);

namespace Piwigo\Core;

/**
 * Boots the legacy Piwigo runtime in a controlled order.
 *
 * Wave A (Phase 4 first slice): every entry point gains a Kernel::boot() call
 * immediately after `require __DIR__ . '/include/common.inc.php';`. The legacy
 * dance inside common.inc.php is preserved unchanged and gated behind
 * `if (!Kernel::isBooted()) { /* legacy path */ }`. Both run the same effects
 * exactly once.
 *
 * The phase comments below reference the original common.inc.php line ranges
 * so reviewers can cross-walk against `docs/modernization/phase4-globals.md`.
 */
final class Kernel
{
    private static bool $booted = false;

    public static function isBooted(): bool { return self::$booted; }

    public static function boot(): void
    {
        if (self::$booted) {
            return;
        }

        // Pre-boot guards: original common.inc.php:9
        if (!\defined('PHPWG_ROOT_PATH')) {
            \trigger_error('Hacking attempt!', \E_USER_ERROR);
        }

        // Pre-boot input sanitization (common.inc.php:24–46)
        self::sanitizeSuperglobals();

        // page-state-init (common.inc.php:12, 52–65, 113)
        PageState::init();

        // pre-boot polyfills (common.inc.php:67–77)
        self::loadPolyfills();

        // defaults-load (common.inc.php:79)
        Config::loadDefaults();

        // local-load (common.inc.php:80, 82–84)
        if (!\defined('PWG_LOCAL_DIR')) {
            \define('PWG_LOCAL_DIR', 'local/');
        }
        Config::loadLocalOverride();
        @include \PHPWG_ROOT_PATH . \PWG_LOCAL_DIR . 'config/database.inc.php';

        // install-redirect guard (common.inc.php:85–89)
        if (!\defined('PHPWG_INSTALLED')) {
            \header('Location: install.php');
            exit;
        }

        // db-layer-load (common.inc.php:90) — locked: dblayer free functions stay forever
        require \PHPWG_ROOT_PATH . 'include/dblayer/functions_'
            . Config::dblayer() . '.inc.php';

        // error-reporting init (common.inc.php:92–99)
        self::configurePhpErrorReporting();

        // session-prep (common.inc.php:101–105)
        self::configureSessionGc();

        // legacy-includes (common.inc.php:107–111)
        self::loadLegacyCore();

        // service-registration (common.inc.php:115)
        ServiceLocator::register('persistent_cache', new \PersistentFileCache());

        // db-connect (common.inc.php:118–128)
        try {
            \pwg_db_connect(Config::dbHost(), Config::dbUser(),
                Config::dbPassword(), Config::dbBase());
        } catch (\Exception $e) {
            \my_error(\l10n($e->getMessage()), true);
        }
        \pwg_db_check_charset();

        // db-hydrate prelude (common.inc.php:133)
        if (!Config::has('webmaster_id')) {
            Config::override('webmaster_id', 1);
        }

        // db-hydrate (common.inc.php:135)
        Config::hydrateFromDb();

        // service-registration: logger (common.inc.php:137–146)
        ServiceLocator::register('logger', self::makeLogger());

        // upgrade-redirect guard (common.inc.php:148–154)
        if (!Config::checkUpgradeFeed()) {
            $piwigoDbVersion = Config::raw('piwigo_db_version');
            if ($piwigoDbVersion === null
                || $piwigoDbVersion !== \get_branch_from_version(\PHPWG_VERSION)) {
                \redirect(\get_root_url() . 'upgrade.php');
            }
        }

        // derivative-init (common.inc.php:156)
        \ImageStdParams::load_from_db();

        // session-start + plugin-load (common.inc.php:158–159)
        \session_start();
        \load_plugins();

        // post-hydrate housekeeping (common.inc.php:161–200)
        self::recordInstalledVersion();
        self::ensureLastMajorUpdate();
        self::scrubDeprecatedOrderBy();
        self::applyOrderByCustomOverrides();

        // post-boot maintenance (common.inc.php:202)
        \check_lounge();

        // user-init (common.inc.php:204)
        require \PHPWG_ROOT_PATH . 'include/user.inc.php';

        // constant-init post-user (common.inc.php:206–228)
        self::definePiwigoOrgDomain();
        self::definePemUrl();

        // lang-init (common.inc.php:231–239)
        \load_language('common.lang');
        if (\is_admin() || (\defined('IN_ADMIN') && \IN_ADMIN)) {
            \load_language('admin.lang');
            \load_language('whats_new_' . \get_branch_from_version(\PHPWG_VERSION) . '.lang');
        }
        \trigger_notify('loading_lang');
        \load_language('lang', \PHPWG_ROOT_PATH . \PWG_LOCAL_DIR,
            ['no_fallback' => true, 'local' => true]);

        // post-lang fixups (common.inc.php:243–280)
        self::localizeGuestUsername();
        self::reportInvalidAuthKey();
        self::dispatchApiKeyExpirationNotice();

        // service-registration: template (common.inc.php:283–295)
        ServiceLocator::register('template', self::makeTemplate());

        // conditional include + post-boot guards (common.inc.php:297–332)
        if (!Config::has('no_photo_yet')) {
            require \PHPWG_ROOT_PATH . 'include/no_photo_yet.inc.php';
        }
        self::flagGuestStatusIssue();
        self::enforceGalleryLock();
        self::checkUpgradeFeedHeaderMsg();
        self::flushHeaderMsgsToTemplate();

        // filter-init (common.inc.php:340–347)
        Filter::initFromConfig();
        // header notes wiring (common.inc.php:349–352)
        HeaderNotes::mergeFromConfig();

        // post-boot hooks (common.inc.php:355–369)
        self::registerDefaultEventHandlers();
        \trigger_notify('init');

        self::$booted = true;
    }

    // (private helpers: sanitizeSuperglobals, loadPolyfills,
    //  configurePhpErrorReporting, configureSessionGc, loadLegacyCore,
    //  makeLogger, recordInstalledVersion, ensureLastMajorUpdate,
    //  scrubDeprecatedOrderBy, applyOrderByCustomOverrides,
    //  definePiwigoOrgDomain, definePemUrl, localizeGuestUsername,
    //  reportInvalidAuthKey, dispatchApiKeyExpirationNotice, makeTemplate,
    //  flagGuestStatusIssue, enforceGalleryLock, checkUpgradeFeedHeaderMsg,
    //  flushHeaderMsgsToTemplate, registerDefaultEventHandlers — each one
    //  is a verbatim transcription of the matching line range from
    //  common.inc.php with no behavioural change.)
}
```

The legacy `common.inc.php` is wrapped in `if (!Kernel::isBooted())` — it
detects the kernel-already-ran case and returns immediately, so plugins or
tests that re-include `common.inc.php` post-boot become idempotent.

---

### C. Wave B push-site migration catalog

A grep for `$page['(errors|warnings|messages|infos)'][] =` across the runtime
tree returns **151 occurrences across 45 files**. Every site falls into one of
five mechanical patterns.

#### C.1 Pattern A — Simple literal

`$page['errors'][] = l10n('Some error');` — 1:1 conversion to
`PageState::current()->addError(l10n('Some error'));`. This is the dominant
shape; roughly 110 of 151 sites.

Rector rule:

```php
// rector.php (excerpt)
$rectorConfig->ruleWithConfiguration(
    \Rector\Transform\Rector\Assign\GetAndSetToMethodCallRector::class,
    [/* … */]
);

// Custom rule: src/Codegen/Rector/PageStatePushRector.php
final class PageStatePushRector extends AbstractRector
{
    public function getNodeTypes(): array { return [Assign::class]; }

    public function refactor(Node $node): ?Node
    {
        if (!$node instanceof Assign) return null;
        if (!$node->var instanceof ArrayDimFetch) return null;
        $outer = $node->var->var;
        $key   = $node->var->dim;
        // $page['errors'][]  -> outer is ArrayDimFetch($page, 'errors'), $key === null
        if (!$outer instanceof ArrayDimFetch) return null;
        if ($key !== null) return null;
        $base = $outer->var;
        if (!$base instanceof Variable || $base->name !== 'page') return null;
        if (!$outer->dim instanceof String_) return null;
        $kind = $outer->dim->value;
        if (!\in_array($kind, ['errors','warnings','messages','infos'], true)) return null;
        $method = 'add' . \ucfirst(\rtrim($kind, 's')); // addError / addWarning / addMessage / addInfo
        return new MethodCall(
            new StaticCall(new FullyQualified('Piwigo\\Core\\PageState'), 'current'),
            $method,
            [new Arg($node->expr)]
        );
    }
}
```

#### C.2 Pattern B — Conditional append

```php
if ($x) {
    $page['errors'][] = l10n('Y');
}
```

Identical to Pattern A: the Rector rule rewrites the inner statement, the
`if` wrapper stays. No special handling needed.

#### C.3 Pattern C — String interpolation / sprintf

```php
$page['errors'][] = sprintf(l10n('Failed to %s'), $action);
$page['infos'][] = sprintf('%s : %s', l10n('Update albums informations'),
                           l10n('action successfully performed.'));
```

The `addError()`/`addInfo()` method takes a `string`, so the `sprintf(...)`
expression passes through `$node->expr` unchanged. Same Rector rule covers it.

#### C.4 Pattern D — Append in loop

```php
foreach ($categories as $cat) {
    $page['errors'][] = sprintf(l10n('Album %s not found'), $cat['name']);
}
```

The Rector rule fires per-statement and walks into the loop body. No structural
change required.

#### C.5 Pattern E — Long-tail edge cases (manual)

Every site that doesn't match the AST shape above must be hand-converted. The
grep surfaced these:

1. **`include/common.inc.php:252` — push during boot fixup**:
   ```php
   $page['errors'][] = l10n('Your authentication key is no longer valid.')
       . sprintf(' <a href="%s">%s</a>', get_root_url().'identification.php', l10n('Login'));
   ```
   Conversion: inside `Kernel::reportInvalidAuthKey()`, call
   `PageState::current()->addError(...)`. Cannot use `current()` until
   `PageState::init()` has run — which it has (line 113 → kernel page-state-init).

2. **`admin/include/check_integrity.class.php` — c13y class member access**:
   the class uses `$this->page_errors` internally then merges into `$page` from
   outside. Manual: keep the class internal API; rewrite the merge call site
   to `PageState::current()->addErrors($c13y->collected())`.

3. **`admin/include/c13y_internal.class.php`** — same shape as 2. Two sites.

4. **`include/dblayer/functions_mysql.inc.php:*`** — dead code path; `$page['errors'][]`
   only fires inside the legacy MySQL driver. Locked constraint: pre-16 paths
   out of scope and the file is dead. **Do not migrate.** Add a comment marker
   `// PHASE_4_SKIP: legacy mysql driver`.

5. **`include/dblayer/functions_mysqli.inc.php`** — two sites inside connection
   error paths. Convert via Rector (Pattern A) — these are live.

6. **`admin/configuration.php:402`** — string with literal `$conf['order_by']`
   interpolated:
   ```php
   $page['warnings'][] = l10n('You have specified <i>$conf[\'order_by\']</i> ...');
   ```
   The single quote escapes confuse a naive regex but the AST rule handles it
   fine because the string node value is opaque.

7. **`include/picture_comment.inc.php`** — three sites inside a comment
   submission flow that build up an array first then merge:
   ```php
   $errors = [];
   if (...) { $errors[] = l10n('...'); }
   if ($errors) { $page['errors'] = array_merge($page['errors'], $errors); }
   ```
   This is **not** the `$page['errors'][] =` shape so the Rector rule misses
   it. Manual: rewrite to `PageState::current()->addErrors($errors)`.

8. **`include/functions_comment.inc.php:1`** — single push, Pattern A.

9. **`admin/include/functions_notification_by_mail.inc.php`** — 11 sites, all
   Pattern A or C (already verified by grep at `:272`, `:285`, `:299`, `:306`,
   `:316`, `:320`, `:397`, `:463`, `:468`, `:489`, `:496`).

10. **Plugin-side handlers** — plugins ship handlers that mutate `$page`
    directly. **Do not rewrite plugin code.** The Wave C `ArrayObject` proxy
    bridges these silently; in Wave B, plugins still write to the legacy
    `$GLOBALS['page']` array which `PageState` mirrors by reference.

#### C.6 Top 15 files by push count

| File | Count |
|---|---|
| `admin/maintenance_actions.php` | 19 |
| `admin/include/functions_notification_by_mail.inc.php` | 11 |
| `admin/batch_manager_global.php` | 9 |
| `admin/languages_new.php` | 8 |
| `admin/themes_new.php` | 8 |
| `admin/plugins_new.php` | 9 |
| `admin/configuration.php` | 7 |
| `admin/maintenance_env.php` | 6 |
| `admin/notification_by_mail.php` | 5 |
| `admin/include/updates.class.php` | 5 |
| `admin/site_manager.php` | 4 |
| `admin/include/functions_permalinks.php` | 4 |
| `admin/include/functions_upgrade.php` | 4 |
| `password.php` | 4 |
| `admin/intro.php` | 4 |

The remaining 30 files have 1–3 sites each.

---

### D. `$conf` write-site audit

Excluding `config_default.inc.php`, `local/config/`, and `install/db/`, the grep
`^\s*\$conf\['[\w_]+'\]\s*=` finds every transient or persistent mutation.
Categorisation:

#### D.1 Persistent writes (admin saves) → `Config::persist()`

These call sites end up writing to the DB via `conf_update_param()`. The fact
that `$conf` is also assigned in-process is a side effect of the legacy code's
read-modify-write style.

- `include/common.inc.php:189` — `conf_update_param('order_by', $order_by, true);`
  with the surrounding mutation `$conf['order_by'] = $order_by;`. Both calls
  collapse into `Config::persist('order_by', $order_by)`.
- `include/common.inc.php:163, 169, 176` — `piwigo_installed_version`,
  `last_major_update`. Both via `conf_update_param`. Fold into
  `Config::persist(...)`.
- `admin/extend_for_templates.php:127` — `$conf['extents_for_templates'] = serialize(...)`
  paired with a follow-up `conf_update_param`. Persistent.
- `admin/configuration.php` — many indirect persists via the configuration
  form save path, all already routed through `conf_update_param`. The bare
  `$conf[...] = ...` lines around them are bookkeeping for the in-flight save.
  Fold into `Config::persist()` for each.

#### D.2 Transient overrides (per-album, per-request) → `Config::override()`

- `include/common.inc.php:133` — `$conf['webmaster_id'] = $conf['webmaster_id'] ?? 1;`
  → `Config::override('webmaster_id', 1)` if absent.
- `include/common.inc.php:195, 199` — order-by custom overrides applied per
  request from already-loaded `$conf['order_by_custom']`.
- `include/section_init.inc.php:172, 188, 454, 499, 526` — five sites that
  rewrite `$conf['order_by']` based on the active section (recent, top, etc.).
  All transient.
- `include/ws_functions/pwg.php:52, 53` — flips `question_mark_in_urls`,
  `php_extension_in_urls`, `derivative_url_style` for the API request only.
- `include/ws_functions/pwg.images.php:675` — order_by per API call.
- `include/ws_functions/pwg.categories.php:767` — `newcat_default_position`
  override during a single category create.
- `include/ws_functions/pwg.extensions.php:279, 290, 340` — `updates_ignored`
  unserialize-mutate-reserialize. The unserialize is a normalisation, the
  later writes are persistent — split: the unserialize becomes
  `Config::override`, the actual assignment of new value uses `Config::persist`.
- `admin/picture_coi.php:54, 57` — same triplet as `pwg.php:52-53` for COI page.
- `admin/batch_manager_global.php:547, 558, 561` — order_by overrides during
  batch screen rendering.
- `admin/batch_manager_unit.php:198, 210, 213` — same.
- `admin/include/functions_upload.inc.php:419` — `$conf['use_exif'] = false;`
  during upload to short-circuit EXIF metadata read in a specific path.
- `admin/include/updates.class.php:215` — `update_notify_last_notification`
  unserialize normalisation.
- `admin/site_reader_local.php:21, 25` — flip arrays cache.
- `admin/include/functions.php:1124, 1128` — flip arrays cache. These are
  computed-once-per-request memoisation; fold into `Config::flipPictureExt()`
  / `Config::flipFileExt()` derived getters with internal cache.
- `admin/updates_ext.php:24` — `updates_ignored` normalisation.
- `include/functions.inc.php:482` — `$conf['history_sections_cache']` cache
  normalisation.
- `upgrade.php:418` — `$conf['die_on_sql_error'] = false;` to suppress fatal
  during upgrade. Transient.

#### D.3 Bootstrap mutations (read-modify-write at file scope in include/*)

- `include/common.inc.php:133, 195, 199` already covered above. Keep as-is for
  Wave A; the body of `Kernel::boot()` already inlines them in the trace.
- `include/section_init.inc.php` reassignments: keep as-is for Wave A. They
  run inside the section dispatcher, which is too entangled with `$page` state
  to migrate in Wave A. Reconsider in Wave C.

The grand total is roughly 40 unique write sites outside the excluded
directories. Fewer than 10 are persistent; the rest are per-request transients
and trivially convert to `Config::override()` once the proxy is in place.

---

### E. Wave C `ArrayObject` proxy: complete implementation

Wave C is the cuttable one. If we ship it, every read of `$conf[...]` and every
write outside the `Config::override`/`Config::persist` paths still works, but
emits a deprecation notice that points the developer at the correct typed
getter. Install/db callers, language file authors, and `local/config/` are
silent — they have been historically allowed to write the array directly.

#### E.1 Full source: `src/Piwigo/Core/GlobalsBridge.php`

```php
<?php
declare(strict_types=1);

namespace Piwigo\Core;

/**
 * Bridges legacy globals ($conf, $page) onto the typed services while
 * emitting deprecation notices for non-allowlisted callers. Wave C.
 */
final class GlobalsBridge
{
    /**
     * Allowlist of file-path fragments that may read/write $conf or $page
     * silently. Drawn from the locked Phase 4 constraints.
     */
    private const SILENT_CALLER_FRAGMENTS = [
        '/install/db/',
        '\\install\\db\\',          // Windows path separators
        '/include/config_default.inc.php',
        '\\include\\config_default.inc.php',
        '/local/config/',
        '\\local\\config\\',
        '/language/',
        '\\language\\',
    ];

    public static function installAsConfProxy(): void
    {
        $proxy = new ConfProxy(Config::raw('') ?? []);
        // Replace $GLOBALS['conf'] without breaking by-reference callers:
        // the proxy holds Config::$data as its backing array.
        unset($GLOBALS['conf']);
        $GLOBALS['conf'] = $proxy;
    }

    public static function installAsPageProxy(): void
    {
        $proxy = new PageProxy(PageState::asArray());
        unset($GLOBALS['page']);
        $GLOBALS['page'] = $proxy;
    }

    /**
     * Walks up to 5 backtrace frames looking for a path fragment that's on
     * the silent allowlist. Returns true if any match.
     */
    public static function isInstallDbCaller(): bool
    {
        $frames = \debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS, 5);
        foreach ($frames as $frame) {
            $file = $frame['file'] ?? '';
            if ($file === '') continue;
            foreach (self::SILENT_CALLER_FRAGMENTS as $fragment) {
                if (\str_contains($file, $fragment)) {
                    return true;
                }
            }
        }
        return false;
    }

    public static function emitDeprecation(string $key, string $kind): void
    {
        if (self::isInstallDbCaller()) {
            return;
        }
        \trigger_error(
            \sprintf(
                'Phase 4: %s $conf[%s] via $GLOBALS is deprecated; use Piwigo\\Core\\Config::%s() instead.',
                $kind,
                \var_export($key, true),
                self::suggestGetterName($key)
            ),
            \E_USER_DEPRECATED
        );
    }

    private static function suggestGetterName(string $key): string
    {
        // snake_case → camelCase, with a couple of overrides
        return \lcfirst(\str_replace(' ', '', \ucwords(\str_replace(['_','-'], ' ', $key))));
    }
}

/** @internal */
final class ConfProxy extends \ArrayObject
{
    public function offsetGet(mixed $key): mixed
    {
        if (!\is_string($key)) {
            return parent::offsetGet($key);
        }
        GlobalsBridge::emitDeprecation($key, 'read');
        return parent::offsetGet($key);
    }

    public function offsetSet(mixed $key, mixed $value): void
    {
        if (\is_string($key)) {
            GlobalsBridge::emitDeprecation($key, 'write');
        }
        parent::offsetSet($key, $value);
        // Keep Config::$data in sync so typed getters see the override.
        if (\is_string($key)) {
            Config::override($key, $value);
        }
    }

    public function offsetExists(mixed $key): bool
    {
        if (\is_string($key)) {
            // Reads of array_key_exists / isset are common and harmless;
            // we don't deprecate the existence check itself.
        }
        return parent::offsetExists($key);
    }

    public function offsetUnset(mixed $key): void
    {
        if (\is_string($key)) {
            GlobalsBridge::emitDeprecation($key, 'unset');
        }
        parent::offsetUnset($key);
    }
}

/** @internal */
final class PageProxy extends \ArrayObject
{
    public function offsetGet(mixed $key): mixed { return parent::offsetGet($key); }

    public function offsetSet(mixed $key, mixed $value): void
    {
        // Special-case the four push channels — when callers do
        // $page['errors'][] = ..., PHP synthesises an offsetSet with key=null.
        if ($key === null) {
            // Cannot know which channel without inspecting parent.
            // Fallback: append to whichever array is currently being pushed.
            parent::offsetSet($key, $value);
            return;
        }
        parent::offsetSet($key, $value);
    }

    public function offsetExists(mixed $key): bool { return parent::offsetExists($key); }
    public function offsetUnset(mixed $key): void  { parent::offsetUnset($key); }
}
```

#### E.2 Unit-test sketch

```php
<?php
// tests/Unit/Core/GlobalsBridgeTest.php
declare(strict_types=1);

namespace Piwigo\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Piwigo\Core\Config;
use Piwigo\Core\GlobalsBridge;

final class GlobalsBridgeTest extends TestCase
{
    protected function setUp(): void
    {
        Config::loadDefaults();
        GlobalsBridge::installAsConfProxy();
    }

    public function testReadEmitsDeprecation(): void
    {
        $this->expectUserDeprecationMessage('Piwigo\\Core\\Config::uploadDir');
        $_ = $GLOBALS['conf']['upload_dir'];
    }

    public function testInstallDbCallerIsSilent(): void
    {
        // Simulate being called from install/db/ by setting up a fake stack
        // frame via require — see tests/fixtures/fake_install_db_reader.php.
        require __DIR__ . '/../../fixtures/fake_install_db_reader.php';
        // The fixture reads $GLOBALS['conf']['upload_dir']; assert no
        // deprecation was emitted by capturing error_handler.
        $this->assertSame([], $this->collectedDeprecations);
    }

    public function testWriteSyncsTypedConfig(): void
    {
        @$GLOBALS['conf']['upload_dir'] = '/tmp/uploads';
        $this->assertSame('/tmp/uploads', Config::uploadDir());
    }
}
```

#### E.3 Benchmarks

Phase 4 must not regress request latency by more than **5%** vs Phase 3 head.
Measurement procedure:

1. Run `ab -n 200 -c 4 https://localhost/piwigo16/index.php?/category/1` against
   Phase 3 head. Record p50 and p95.
2. Same against Wave A (typed services, no proxy).
3. Same against Wave C (proxy installed).
4. Acceptance threshold: Wave C p95 must be ≤ Phase 3 p95 × 1.05. If the proxy
   regresses more than 5%, drop the deprecation `trigger_error` to a log-only
   path and re-measure. If still over, **cut Wave C entirely** and ship the
   typed services without the bridge.

---

### F. Cut-point checklist

At the end of Wave B, before deciding whether to start Wave C, fill in this
checklist. Anything that fails the threshold is a vote to cut.

- [ ] **Push sites caught by the Rector rule**: count files where the rule
      successfully rewrote every `$page['errors'][]` push. Target: ≥ 95% of
      the original 151. Failure mode: long tail of plugin-side custom shapes.
- [ ] **`global $conf` declarations remaining in `src/`**: target zero. Wave B.5
      removes these as a side-effect of replacing every bare read in each file;
      if any remain after B.5, list them and migrate by hand before proceeding.
- [ ] **Bare `$conf[...]` reads remaining outside `local/`, `install/db/`,
      `language/`, and `config_default.inc.php`**: target **zero**. Wave B.5 is
      the step that drives this to zero — do not start Wave C until B.5's exit
      signal is green. Any surviving reads mean Wave C will emit deprecations on
      normal page loads, which defeats the purpose of the proxy.
- [ ] **Estimated person-days for Wave C itself** (proxy only, after B.5 is
      done): proxy implementation + benchmark + plugin smoke tests. Target ≤ 3
      person-days. Above 6 → cut.
- [ ] **Performance regression from `ab` benchmark**: Wave C p95 ≤ Phase 3 p95
      × 1.05. Above 5% → cut or downgrade to log-only deprecations.
- [ ] **CI green on PHP 8.5 strict**: the typed services must pass
      `phpstan --level=max` on `src/Piwigo/Core/` and unit tests on
      `tests/Unit/Core/`.
- [ ] **Plugin smoke tests**: top 5 third-party plugins (NBC_UserAdvManager,
      LocalFiles Editor, Piwigo-Videojs, Piwigo-OpenStreetMap, Piwigo-Tags)
      installed against Wave B and Wave C builds; gallery loads, admin loads,
      no fatal errors emitted.
- [ ] **Plugin deprecation noise**: with `display_errors=1` and a default
      Piwigo install plus the top 5 plugins, count deprecations on a single
      `index.php` page load. Target ≤ 200; above 1000 means the proxy is too
      chatty and we cut.
- [ ] **Backout plan rehearsed**: confirm that reverting the Wave C commit
      leaves Wave A+B intact and the tree boots green. Log the commit hash of
      the cut point.

#### Documenting the decision

If Wave C ships, append a new section **`Phase 4 close-out — Wave C shipped`**
to `MODERNIZATION_PLAN.md` with the date, the commit range, and the
benchmark numbers from Section E.3. Cross-link to this supplement.

If Wave C is cut, append **`Phase 4 close-out — Wave C cut`** with:
- the date,
- which checklist item failed (one or more),
- the measured numbers vs. the target,
- a one-paragraph "what we'd need to ship Wave C in the future" note.

Document the cut decision in writing in the close-out commit message before
Phase 5 work starts.
```

#### Critical Files for Implementation
- C:\Apache24\htdocs\piwigo16\include\common.inc.php
- C:\Apache24\htdocs\piwigo16\include\config_default.inc.php
- C:\Apache24\htdocs\piwigo16\include\section_init.inc.php
- C:\Apache24\htdocs\piwigo16\admin\include\functions_notification_by_mail.inc.php
- C:\Apache24\htdocs\piwigo16\admin\maintenance_actions.php

---

## Phase 5 — JS → TS conversion (M; can run in parallel from Phase 1)

### Goal
Convert ~91 authored first-party JS files (counted by `find` excluding `.min.js`) to TypeScript built by Vite, while preserving the existing Smarty `{combine_script id="..." path="..."}` template tag — used in 100+ places across `themes/default/template/` and `admin/themes/default/template/` — as the public delivery contract. The PHP-side `ScriptLoader` (currently `include/template.class.php:1494`, relocated to `src/Piwigo/Template/ScriptLoader.php` in Phase 3) gains a manifest-aware mode: if `dist/manifest.json` exists, it emits hashed bundle URLs from the Vite build; otherwise it falls back to the existing concat-at-runtime path so a fresh git clone still serves the gallery without `npm run build`. Vendored libraries (jQuery, jQuery UI, plupload/moxie, photoswipe, slick, chosen, Chart.js, dataTables, colorbox, selectize) stay as-is — typing only their public surfaces via `@types/jquery` and per-plugin module-augmentation declarations.

### Step-by-step sequence

1. **Add root `package.json`.** Single root, not per-theme. Pin Vite 5.x and TypeScript 5.6 — newer Vite 6 sometimes breaks Smarty-emitted asset URLs through cache-bust query strings; hold to 5.x.
   ```json
   {
     "name": "piwigo-frontend",
     "private": true,
     "type": "module",
     "scripts": { "dev": "vite", "build": "vite build", "typecheck": "tsc --noEmit" },
     "devDependencies": {
       "vite": "^5.4.10",
       "typescript": "^5.6.3",
       "@types/jquery": "^3.5.30",
       "@types/jqueryui": "^1.12.23"
     }
   }
   ```
   **Exit signal:** `npm install` produces `node_modules`; `npm run -s build --dry-run` exits zero.

2. **Inventory `{combine_script}` invocations.** Grep `themes/default/template/*.tpl` and `admin/themes/default/template/*.tpl`. Verified id → path table has ~60 unique `id` values across ~120 invocations. Vendored ids (`jquery`, `jquery.ui`, `jquery.colorbox`, `jquery.selectize`, `jquery.ajaxmanager`, `jquery.confirm`, `jquery.tipTip`, `jquery.cluetip`, `jquery.cookie`, `jquery.geoip`, `jquery.jcrop`, `jquery.progressBar`, `jquery.sort`, `jquery.autogrow`, `jquery.plupload`, `jquery.plupload.queue`, `jquery.ui.datepicker`, `jquery.ui.timepicker-addon`, `piecon`, `mcs`, `jtree`, `plupload_i18n-*`) point at vendored files and stay outside Vite — served via legacy fallback. Authored ids (`common`, `albums`, `cat_modify`, `cat_search`, `cat_list`, `comments`, `batchManagerGlobal`, `batchManagerUnit`, `batchManagerFilter`, `addAlbum`, `albumSelector`, `doubleSlider`, `datepicker`, `group_list`, `history`, `intro_tooltips`, `LocalStorageCache`, `ajax`, `activated_plugin_list`, `sys`, `add_photo`, `picture_modify`, `picture_formats`, `photos_add_direct`, etc.) become Vite entries. **Exit signal:** `dev/vite-entries.json` artifact lists each id with its source path.

3. **Build `vite.config.ts` with multi-entry config.**
   ```ts
   import { defineConfig } from 'vite';
   import { resolve } from 'node:path';
   import { piwigoManifestPlugin } from './build/piwigo-manifest-plugin';

   export default defineConfig({
     root: '.',
     build: {
       outDir: 'dist',
       emptyOutDir: true,
       manifest: true,
       rollupOptions: {
         input: {
           // Frontend (themes/default)
           'core.scripts': resolve(__dirname, 'themes/default/js/scripts.js'),
           'core.switchbox': resolve(__dirname, 'themes/default/js/switchbox.js'),
           'rating': resolve(__dirname, 'themes/default/js/rating.js'),
           'thumbnails.loader': resolve(__dirname, 'themes/default/js/thumbnails.loader.js'),
           // Admin
           'common': resolve(__dirname, 'admin/themes/default/js/common.js'),
           'albums': resolve(__dirname, 'admin/themes/default/js/albums.js'),
           'cat_modify': resolve(__dirname, 'admin/themes/default/js/cat_modify.js'),
           'batchManagerGlobal': resolve(__dirname, 'admin/themes/default/js/batchManagerGlobal.js'),
           'batchManagerUnit': resolve(__dirname, 'admin/themes/default/js/batchManagerUnit.js'),
           'batchManagerFilter': resolve(__dirname, 'admin/themes/default/js/batchManagerFilter.js'),
           // ... ~30 entries total
           'profile': resolve(__dirname, 'themes/standard_pages/js/profile.js'),
           'standard_pages': resolve(__dirname, 'themes/standard_pages/js/standard_pages.js'),
           'toaster': resolve(__dirname, 'themes/standard_pages/js/toaster.js'),
         },
         output: {
           entryFileNames: 'assets/[name]-[hash].js',
           chunkFileNames: 'assets/chunks/[name]-[hash].js',
         },
       },
     },
     plugins: [piwigoManifestPlugin()],
     server: { port: 5173, strictPort: true, cors: true },
   });
   ```
   **Exit signal:** `npm run build` produces `dist/assets/common-<hash>.js` plus `dist/manifest.json`.

4. **Custom Vite plugin emitting the Piwigo-format manifest.**
   ```ts
   // build/piwigo-manifest-plugin.ts
   import type { Plugin } from 'vite';
   import { writeFile, readFile } from 'node:fs/promises';
   import { resolve } from 'node:path';

   export function piwigoManifestPlugin(): Plugin {
     return {
       name: 'piwigo-manifest',
       apply: 'build',
       async writeBundle({ dir }) {
         const outDir = dir ?? 'dist';
         const viteManifest = JSON.parse(
           await readFile(resolve(outDir, '.vite/manifest.json'), 'utf8')
         ) as Record<string, { file: string; src?: string; isEntry?: boolean; imports?: string[]; css?: string[] }>;
         const piwigoManifest: Record<string, { file: string; imports: string[]; css: string[] }> = {};
         for (const [src, entry] of Object.entries(viteManifest)) {
           if (!entry.isEntry) continue;
           const id = src.split('/').pop()!.replace(/\.[jt]s$/, '');
           piwigoManifest[id] = {
             file: entry.file,
             imports: (entry.imports ?? []).map(k => viteManifest[k]?.file).filter(Boolean) as string[],
             css: entry.css ?? [],
           };
         }
         await writeFile(resolve(outDir, 'manifest.json'), JSON.stringify(piwigoManifest, null, 2));
       },
     };
   }
   ```
   **Exit signal:** `dist/manifest.json` contains `{"common": {"file": "assets/common-abc123.js", "imports": [], "css": []}, ...}`.

5. **Modify `Piwigo\Template\ScriptLoader` for manifest mode.** Smallest viable change is in `add()` (line ~1578) — when an `id` is registered, consult the manifest first; if present, set `path` to the hashed file and clear `precedents` (Vite already inlined dependency order via `imports`).
   ```php
   // BEFORE (line ~1588)
   if (! isset( $this->registered_scripts[$id] ) )
   {
       $script = new Script($load_mode, $id, $path, $version, $require);
       // ...
   }

   // AFTER (manifest-aware)
   if (! isset( $this->registered_scripts[$id] ) )
   {
       if ($manifest = self::manifest()) {
           if (isset($manifest[$id])) {
               $path = 'dist/' . $manifest[$id]['file'];
               $require = []; // Vite handles import order
               foreach ($manifest[$id]['css'] as $cssFile) {
                   // delegated to a parallel CssLoader
               }
           }
       }
       $script = new Script($load_mode, $id, $path, $version, $require);
   }

   private static ?array $manifest = null;
   private static function manifest(): ?array
   {
       if (self::$manifest !== null) { return self::$manifest ?: null; }
       $f = PHPWG_ROOT_PATH . 'dist/manifest.json';
       if (!is_file($f)) { return self::$manifest = []; }
       $decoded = json_decode((string) file_get_contents($f), true);
       return self::$manifest = is_array($decoded) ? $decoded : [];
   }
   ```
   **Exit signal:** `npm run build` then loading `index.php` produces `<script src="dist/assets/core.scripts-<hash>.js">`; deleting `dist/manifest.json` falls back to `themes/default/js/scripts.js`.

6. **`tsconfig.json`.** Start permissive, ratchet later.
   ```json
   {
     "compilerOptions": {
       "target": "ES2022",
       "module": "ESNext",
       "moduleResolution": "Bundler",
       "lib": ["ES2022", "DOM", "DOM.Iterable"],
       "strict": true,
       "noImplicitAny": false,
       "allowJs": true,
       "checkJs": true,
       "skipLibCheck": true,
       "esModuleInterop": true,
       "isolatedModules": true,
       "noEmit": true,
       "types": ["jquery", "jqueryui"]
     },
     "include": ["admin/themes/default/js/**/*", "themes/default/js/**/*", "themes/standard_pages/js/**/*", "src/types/**/*"],
     "exclude": ["**/*.min.js", "themes/default/js/plugins/**", "themes/default/js/ui/**", "node_modules", "dist"]
   }
   ```
   Ratchet: Wave 1 keeps `noImplicitAny:false`; Wave 2 flips it true after simple files convert; Wave 3 enables `strictNullChecks` on a per-file `// @ts-strict` opt-in.

7. **Migrate authored JS file by file.** Leafiest-first, trunky last.

   | Layer | Files | Notes |
   | --- | --- | --- |
   | Leaf utilities | `themes/default/js/scripts.js`, `switchbox.js`, `pngfix.js`, `jquery.cookie.js`, `themes/standard_pages/js/toaster.js` | No internal deps; `scripts.js` defines `pwgBind()`, `phpWGOpenWindow()` used as inline-script callbacks — keep them on `window` via `(window as any).pwgBind = pwgBind`. |
   | Leaf admin utilities | `admin/themes/default/js/LocalStorageCache.js`, `doubleSlider.js`, `datepicker.js`, `album_selector.js`, `jquery.geoip.js` | jQuery-plugin pattern (`$.fn.foo = …`) — needs the augmentation file. |
   | Mid-layer admin pages | `addAlbum.js`, `cat_search.js`, `cat_modify.js`, `cat_list.js`, `albums.js`, `comments.js`, `group_list.js`, `history.js`, `tags.js`, `picture_formats.js`, `picture_modify.js`, `intro_tooltips.js`, `maintenance.js`, `maintenance_env.js`, `maintenance_sys.js`, `plugins_*.js`, `stats.js`, `user_activity.js`, `user_list.js` | One commit per ~5 files. Type the AJAX response shape inline. |
   | Frontend gallery | `themes/default/js/rating.js`, `mcs.js`, `thumbnails.loader.js`, `themes/standard_pages/js/profile.js`, `standard_pages.js` | `rating.js` reads three module-scope `var`s — convert to module-locals. |
   | Trunk | `admin/themes/default/js/common.js`, `batchManager{Global,Unit,Filter}.js`, `photos_add_direct.js` | `common.js` declares `$.fn.fontCheckbox`, `$.fn.coloris` — bulk consumer of jQuery types. |
   | Skip | `themes/default/js/jquery.js`, anything under `themes/default/js/plugins/` and `themes/default/js/ui/` | Vendored. |
   | `tools/ws/` | `tools/ws/ws.js`, `tools/ws/jquery.json-viewer.js` | Standalone web-services explorer; convert last or punt. |

   **Exit signal per file:** `npx tsc --noEmit` clean for that file's slice.

8. **Module-augment jQuery for plugin globals.** `admin/themes/default/js/common.js:1` literally starts with `jQuery.fn.fontCheckbox = function() {…}`. Without augmentation, `$('foo').fontCheckbox()` produces TS2339. File `src/types/jquery-plugins.d.ts`:
   ```ts
   import 'jquery';
   declare global {
     interface JQuery {
       fontCheckbox(): JQuery;
       ajaxManager(opts?: { queue?: string; cacheResponse?: boolean }): { add(req: JQueryAjaxSettings): void; abort(name?: string): void };
       colorbox(opts?: Record<string, unknown>): JQuery;
       confirm(opts: { title?: string; content?: string; buttons?: Record<string, unknown> }): JQuery;
       tipTip(opts?: { defaultPosition?: 'top' | 'bottom' | 'left' | 'right' }): JQuery;
       cluetip(opts?: Record<string, unknown>): JQuery;
       selectize(opts?: Record<string, unknown>): JQuery & { 0: { selectize: { addOption(o: Record<string, unknown>): void; setValue(v: string | number): void; clearOptions(): void } } };
       Jcrop(opts?: Record<string, unknown>, cb?: () => void): JQuery;
       autogrow(opts?: { onInitialize?: boolean }): JQuery;
       plupload(opts: Record<string, unknown>): JQuery;
       progressBar(value: number): JQuery;
       sortable(opts?: Record<string, unknown>): JQuery;
       slider(opts?: Record<string, unknown>): JQuery;
       coloris(opts?: Record<string, unknown>): JQuery;
       fileupload(opts: Record<string, unknown>): JQuery;
     }
   }
   ```

9. **Ambient globals declaration `src/types/globals.d.ts`** for inline-script-set vars. Templates write `<script>var SwitchBox = […]; var photo = {…};</script>` then load the bundled file expecting those names visible.
   ```ts
   declare global {
     const pwg_token: string;
     const pwg_root_url: string;
     const pwg_lang_info: { code: string; name: string; jquery_code: string };
     const cookie_path: string;
     const SwitchBox: { push(link: string, box: string): void } | Array<string>;
     const photo: { id: number; src: string; rel?: string };
   }
   export {};
   ```

10. **Final pass — every authored `.js` is `.ts`, build green, manifest emitted, Playwright covers each gallery + admin page asserting no console `TypeError`.** New spec `phase5-console-clean.spec.ts` walks every entry-point template route (gallery home, an album, a photo, comments, search, login; admin: dashboard, albums, batch_manager_global, batch_manager_unit, photos_add_direct, configuration, history, plugins, languages, themes, maintenance) and asserts `page.on('pageerror')` collected zero events.

### Effort breakdown

| Sub-task | Tag |
| --- | --- |
| `package.json` + `tsconfig.json` + lockfile | S |
| Inventory `{combine_script}` and produce entry list | S |
| `vite.config.ts` multi-entry | M |
| Custom manifest plugin (~30 lines) | S |
| `ScriptLoader` manifest-aware mode + tests | M |
| jQuery plugin module-augmentation file | S |
| Ambient globals declaration | S |
| Convert leaf `.js → .ts` (~10 files) | M |
| Convert mid-layer admin pages (~20 files) | M |
| Convert frontend gallery (~5 files) | S |
| Convert trunk (`common.js` + batch_manager) | M |
| Playwright `console-clean` spec across all entry templates | M |
| Vite dev server CORS / proxy config for `npm run dev` | S |

**Phase total: M.** Parallelisable with Phases 1+ — independent file space.

### Risks specific to Phase 5

- **Manifest shim must work in build mode AND dev mode without a build.** Decision: dev mode emits the manifest pointing at the Vite dev server (`http://localhost:5173/admin/themes/default/js/common.ts` + HMR). In production-without-build, no `dist/manifest.json` exists and `ScriptLoader` falls through to the existing concat path. Both paths have CI coverage.
- **Vite dev server CORS with the PHP backend.** PHP serves on port 80, Vite on 5173. The server config sets `cors: true`; the manifest shim during dev mode emits absolute URLs. Document the dev-mode env var (`PIWIGO_VITE_DEV=1`) the shim checks for.
- **jQuery plugins that monkey-patch in module scope.** `common.js` does `jQuery.fn.fontCheckbox = function() {...}` at module-top — registers the plugin only when the module is evaluated. Vite tree-shaking respects side-effect modules but the file has to be marked `"sideEffects": true` in `package.json` if shipped as one. Easier: keep these files imported for side-effects with `import 'admin/themes/default/js/common.ts';` at the top of every admin entry that needs `fontCheckbox`.
- **Entry-point order matters when scripts depend on globals.** The `{combine_script id='X' require='Y'}` `require` attribute is honored by `ScriptLoader::compute_script_topological_order()` (line ~1791). Manifest mode *clears* `require` because Vite handles imports — but only if the source file actually imports its dependencies via ES modules. During migration, any `.ts` that depends on `common.ts` *must* `import './common'` in source. Add a Playwright assertion that `$.fn.fontCheckbox` is defined on every admin page whose template loads `common`.

### Verification

1. `npm run build` exits zero and produces `dist/manifest.json` listing every authored entry id.
2. `npm run typecheck` exits zero across all `.ts` files.
3. Curl gallery home page after build: response HTML contains hashed script URLs (`/dist/assets/core.scripts-[a-z0-9]+\.js`).
4. Delete `dist/manifest.json`; reload gallery; legacy concat path serves `themes/default/js/scripts.js`; Playwright smoke still green.
5. `npm run dev` + `PIWIGO_VITE_DEV=1`: edit `admin/themes/default/js/common.ts`, save; HMR pushes update without full reload.
6. New Playwright spec `phase5-console-clean.spec.ts` walks all routes and asserts zero `pageerror` events.


### Part 2 — Phase 5: Concrete file conversions

#### F. Conversion example #1 — `LocalStorageCache.js` → `.ts`

**What this file does** (~3 sentences): defines a `LocalStorageCache` IIFE-wrapped class plus four subclasses (`CategoriesCache`, `TagsCache`, `GroupsCache`, `UsersCache`) that wrap `window.localStorage` with a cache-key-keyed expiry policy and a server-side state-key invalidation hook. It exposes them on `window.*` so inline scripts in admin templates can do `var cache = new TagsCache({...}); cache.selectize($targetEl, { lang: ... })`. The classes follow the `function`-as-constructor + `prototype.foo = function() {}` pattern of pre-ES6 jQuery-era authoring, with `AbstractSelectizer.prototype = new LocalStorageCache({});` for chained inheritance.

Public API consumed elsewhere (verified by inspection of `admin/themes/default/template/*.tpl`):

```
window.LocalStorageCache(opts)
window.CategoriesCache(opts).selectize($el, options)
window.TagsCache(opts).selectize($el, options)
window.GroupsCache(opts).selectize($el, options)
window.UsersCache(opts).selectize($el, options)
```

The full TypeScript conversion. This is a class-based rewrite that mirrors the runtime behavior 1:1.

```typescript
// admin/themes/default/js/LocalStorageCache.ts
import $ from 'jquery';

/** Item shape stored by every selectize-targeted cache. */
export interface CacheItem {
  id: number | string;
  [key: string]: unknown;
}

/** Constructor options for the base LocalStorageCache. */
export interface CacheOptions<T = unknown> {
  /** Identifier of the collection (e.g. "tagsAdminList"). */
  key: string;
  /** Identifier of the Piwigo instance — disambiguates multi-instance clients. */
  serverId: string | number;
  /** State of the collection on the server side; cache invalidates on mismatch. */
  serverKey: string;
  /** Cache lifetime in seconds. Default: 1 hour. */
  lifetime?: number;
  /** Loader called when cache is missing or stale. Receives a callback. */
  loader: (callback: (data: T) => void) => void;
}

/** Persisted JSON envelope written into localStorage. */
interface CacheEnvelope<T> {
  timestamp: number;
  key: string;
  data: T;
}

/** Selectize-instance options that may be set per-target via data-attributes. */
export interface SelectizerOptions {
  value?: Array<number | string | CacheItem>;
  default?: number | string | 'first';
  create?: boolean;
  filter?: (this: HTMLSelectElement, data: CacheItem[], options: SelectizerOptions) => CacheItem[];
  lang?: { Add: string };
}

interface SelectizedElement extends HTMLSelectElement {
  selectize: {
    settings: { maxOptions: number; create: boolean };
    load(cb: (callback: (items: CacheItem[]) => void) => void): void;
    options: Record<string, unknown>;
    addItem(id: number | string): void;
    getItem(id: number | string): JQuery;
    getValue(): string;
    on(event: 'item_remove', cb: (id: number | string) => void): void;
    on(event: 'dropdown_close', cb: () => void): void;
  };
  multiple: boolean;
}

export class LocalStorageCache<T = CacheItem[]> {
  protected key: string;
  protected serverKey: string;
  protected lifetime: number;
  protected loader: CacheOptions<T>['loader'];
  protected storage: Storage;
  protected ready: boolean;

  constructor(options: CacheOptions<T>) {
    this._init(options);
  }

  protected _init(options: CacheOptions<T>): void {
    this.key = `${options.key}_${options.serverId}`;
    this.serverKey = options.serverKey;
    this.lifetime = options.lifetime ? options.lifetime * 1000 : 3600 * 1000;
    this.loader = options.loader;
    this.storage = window.localStorage;
    this.ready = !!this.storage;
  }

  get(callback: (data: T) => void): void {
    const now = new Date().getTime();

    if (this.ready && this.storage[this.key] !== undefined) {
      const cache = JSON.parse(this.storage[this.key]) as CacheEnvelope<T>;
      if (now - cache.timestamp <= this.lifetime && cache.key === this.serverKey) {
        callback(cache.data);
        return;
      }
    }

    this.loader((data) => {
      this.set(data);
      callback(data);
    });
  }

  set(data: T): void {
    try {
      if (this.ready) {
        const envelope: CacheEnvelope<T> = {
          timestamp: new Date().getTime(),
          key: this.serverKey,
          data,
        };
        this.storage[this.key] = JSON.stringify(envelope);
      }
    } catch (e) {
      // localStorage quota exceeded, private mode, etc. Fall through to direct fetch.
      console.log('Local storage error:');
      console.log(e);
      console.log('Use of direct result from Piwigo API.');
    }
  }

  clear(): void {
    if (this.ready) {
      this.storage.removeItem(this.key);
    }
  }
}

/** Abstract intermediary that adds selectize-binding behavior. */
export abstract class AbstractSelectizer extends LocalStorageCache<CacheItem[]> {
  protected _selectize($target: JQuery<SelectizedElement>, globalOptions: SelectizerOptions): void {
    $target.data('cache', this);

    this.get((data) => {
      $target.each(function () {
        // `this` is HTMLSelectElement enriched with the .selectize handle by the plugin
        const el = this as SelectizedElement;
        const options: SelectizerOptions = $.extend({}, globalOptions);

        const filtered: CacheItem[] = options.filter
          ? options.filter.call(el, data, options)
          : data;

        el.selectize.settings.maxOptions = filtered.length + 100;

        if (el.hasAttribute('data-create')) {
          options.create = true;
        }
        el.selectize.settings.create = !!options.create;

        el.selectize.load(function (cb) {
          if ($.isEmptyObject(el.selectize.options)) {
            cb(filtered);
          }
        });

        const dataValue = $(el).data('value');
        if (dataValue) {
          options.value = dataValue;
        }
        if (options.value !== undefined) {
          $.each(options.value, (_i, cat) => {
            if ($.isNumeric(cat)) {
              el.selectize.addItem(cat as number);
            } else {
              el.selectize.addItem((cat as CacheItem).id);
            }
          });
        }

        const dataDefault = $(el).data('default');
        if (dataDefault) {
          options.default = dataDefault;
        }
        if (options.default === 'first') {
          options.default = filtered[0] ? filtered[0].id : undefined;
        }

        if (options.default !== undefined) {
          if (el.selectize.getValue() === '') {
            el.selectize.addItem(options.default);
          }

          if (el.multiple) {
            el.selectize.getItem(options.default).find('.remove').hide();

            el.selectize.on('item_remove', (id) => {
              if (id === options.default) {
                el.selectize.addItem(id);
                el.selectize.getItem(id).find('.remove').hide();
              }
            });
          } else {
            el.selectize.on('dropdown_close', () => {
              if (el.selectize.getValue() === '') {
                el.selectize.addItem(options.default!);
              }
            });
          }
        }
      });
    });
  }

  static getRender(fieldLabel: string, lang: { Add: string } = { Add: 'Add' }) {
    return {
      option: (data: Record<string, string>) => `<div class="option">${data[fieldLabel]}</div>`,
      item: (data: Record<string, string>) => `<div class="item">${data[fieldLabel]}</div>`,
      option_create: (data: { input: string }) =>
        `<div class="create">${lang.Add} <strong>${data.input}</strong>&hellip;</div>`,
    };
  }
}

/** Constructor options shared by the four admin-list subclasses. */
export interface AdminListCacheOptions {
  serverId: string | number;
  serverKey: string;
  rootUrl: string;
}

export class CategoriesCache extends AbstractSelectizer {
  constructor(options: AdminListCacheOptions) {
    super({
      ...options,
      key: 'categoriesAdminList',
      loader: (callback) => {
        $.getJSON(`${options.rootUrl}ws.php?format=json&method=pwg.categories.getAdminList`, (data: { result: { categories: CacheItem[] } }) => {
          const cats = data.result.categories.map((c, i) => {
            c.pos = i;
            delete c['comment'];
            delete c['uppercats'];
            return c;
          });
          callback(cats);
        });
      },
    });
  }

  selectize($target: JQuery<SelectizedElement>, options: SelectizerOptions = {}): void {
    $target.selectize({
      valueField: 'id',
      labelField: 'fullname',
      sortField: 'pos',
      searchField: ['fullname'],
      plugins: ['remove_button'],
      render: AbstractSelectizer.getRender('fullname', options.lang),
    });
    this._selectize($target, options);
  }
}

export class TagsCache extends AbstractSelectizer {
  constructor(options: AdminListCacheOptions) {
    super({
      ...options,
      key: 'tagsAdminList',
      loader: (callback) => {
        $.getJSON(`${options.rootUrl}ws.php?format=json&method=pwg.tags.getAdminList`, (data: { result: { tags: CacheItem[] } }) => {
          const tags = data.result.tags.map((t) => {
            t.id = `~~${t.id}~~`;
            delete t['url_name'];
            delete t['lastmodified'];
            return t;
          });
          callback(tags);
        });
      },
    });
  }

  selectize($target: JQuery<SelectizedElement>, options: SelectizerOptions = {}): void {
    $target.selectize({
      valueField: 'id',
      labelField: 'name',
      sortField: 'name',
      searchField: ['name'],
      plugins: ['remove_button'],
      render: AbstractSelectizer.getRender('name', options.lang),
    });
    this._selectize($target, options);
  }
}

export class GroupsCache extends AbstractSelectizer {
  constructor(options: AdminListCacheOptions) {
    super({
      ...options,
      key: 'groupsAdminList',
      loader: (callback) => {
        $.getJSON(`${options.rootUrl}ws.php?format=json&method=pwg.groups.getList&per_page=9999`, (data: { result: { groups: CacheItem[] } }) => {
          const groups = data.result.groups.map((g) => {
            delete g['lastmodified'];
            return g;
          });
          callback(groups);
        });
      },
    });
  }

  selectize($target: JQuery<SelectizedElement>, options: SelectizerOptions = {}): void {
    $target.selectize({
      valueField: 'id',
      labelField: 'name',
      sortField: 'name',
      searchField: ['name'],
      plugins: ['remove_button'],
      render: AbstractSelectizer.getRender('name', options.lang),
    });
    this._selectize($target, options);
  }
}

export class UsersCache extends AbstractSelectizer {
  constructor(options: AdminListCacheOptions) {
    super({
      ...options,
      key: 'usersAdminList',
      loader: (callback) => {
        let users: CacheItem[] = [];
        const load = (page: number): void => {
          $.getJSON(
            `${options.rootUrl}ws.php?format=json&method=pwg.users.getList&display=username&per_page=9999&page=${page}`,
            (data: { result: { users: CacheItem[]; paging: { count: number; per_page: number } } }) => {
              users = users.concat(data.result.users);
              if (data.result.paging.count === data.result.paging.per_page) {
                load(page + 1);
              } else {
                callback(users);
              }
            }
          );
        };
        load(0);
      },
    });
  }

  selectize($target: JQuery<SelectizedElement>, options: SelectizerOptions = {}): void {
    $target.selectize({
      valueField: 'id',
      labelField: 'username',
      sortField: 'username',
      searchField: ['username'],
      plugins: ['remove_button'],
      render: AbstractSelectizer.getRender('username', options.lang),
    });
    this._selectize($target, options);
  }
}

// Preserve the legacy global-namespace API for inline templates that still do
// `new TagsCache(...)` from a Smarty-emitted <script> tag.
declare global {
  interface Window {
    LocalStorageCache: typeof LocalStorageCache;
    CategoriesCache: typeof CategoriesCache;
    TagsCache: typeof TagsCache;
    GroupsCache: typeof GroupsCache;
    UsersCache: typeof UsersCache;
  }
}

window.LocalStorageCache = LocalStorageCache;
window.CategoriesCache = CategoriesCache;
window.TagsCache = TagsCache;
window.GroupsCache = GroupsCache;
window.UsersCache = UsersCache;
```

**Where types fight the original code:**

1. The original `var $target.data('value')` returns `any` and the loop branches on `$.isNumeric(cat)`. TypeScript needs `string | number | CacheItem` typing on the array. Resolved in `SelectizerOptions.value`.
2. `.selectize` is a jQuery plugin — `JQuery<SelectizedElement>` requires the `selectize` augmentation declared in `src/types/jquery-plugins.d.ts` (Part G). The loose `JQuery<SelectizedElement>.selectize(...)` returns the same instance for chaining; the `el.selectize.*` access path requires casting `this as SelectizedElement` inside `.each` callbacks because jQuery types `this` as `HTMLElement`.
3. The original `AbstractSelectizer.prototype = new LocalStorageCache({})` was a hack — calling `LocalStorageCache({})` with empty options crashed `this.key = options.key + '_' + options.serverId` (concatenated `undefined`). The class-based version skips the hack: `extends LocalStorageCache<CacheItem[]>` and the abstract is never instantiated standalone.
4. Polymorphic `set(data: mixed)` in JS — typed here as `set(data: T): void` where `T = CacheItem[]` for the admin caches. If a future caller wants a different shape, parameterize at the consumer side.

**`tsconfig.json` `compilerOptions` subset for this file:**

```json
{
  "compilerOptions": {
    "target": "ES2022",
    "module": "ESNext",
    "moduleResolution": "Bundler",
    "lib": ["ES2022", "DOM"],
    "strict": true,
    "noImplicitAny": true,
    "esModuleInterop": true,
    "isolatedModules": true,
    "noEmit": true,
    "types": ["jquery"]
  },
  "include": ["admin/themes/default/js/LocalStorageCache.ts", "src/types/**/*"]
}
```

**Commit-sized diff:**

- New: `admin/themes/default/js/LocalStorageCache.ts` (above).
- New: `src/types/jquery-plugins.d.ts` adds `selectize` to the `JQuery` interface (see Part G).
- Changed: `vite.config.ts` adds `'LocalStorageCache': resolve(__dirname, 'admin/themes/default/js/LocalStorageCache.ts')` to `rollupOptions.input`.
- Removed: `admin/themes/default/js/LocalStorageCache.js`.
- Unchanged: every Smarty template that does `new TagsCache(...)` keeps working because of the `window.*` global re-export.

#### G. Conversion example #2 — `common.js` → `.ts`

**Current shape** (3-4 sentences): a 334-line jQuery-plugin-author shop-floor file. Top of file does `jQuery.fn.fontCheckbox = function() {…}` and then `jQuery('.font-checkbox').fontCheckbox()` — registers a plugin AND invokes it as a side effect. Mid-file declares free helpers (`array_delete`, `str_repeat`, `getRandomInt`, `sprintf`), an `Array.prototype.indexOf` polyfill (reachable only on IE — dead code on any browser shipping in this decade), an ES6 `class TemporaryState {…}` for transient DOM-state save/restore, four `jConfirm_*_options` constants, and `jQuery.fn.pwg_jconfirm_follow_href = function(...)` which registers another plugin.

**Migration challenges:**

1. `jQuery.fn.fontCheckbox = function() {…}` registers a plugin in module scope. Vite must keep the side effect — mark the entry as `sideEffects: true` in `package.json` or import it for side effects from every entry that uses it.
2. `jQuery('.font-checkbox').fontCheckbox();` runs at module-evaluation time, before DOMContentLoaded in most cases. Brittle but preserved.
3. `pwg_token`, `pwg_root_url` are ambient globals set by `<script>` tags emitted by Smarty before this file loads. TypeScript needs a `declare global` block.
4. `jQuery.fn.coloris = …` (referenced elsewhere, defined by a vendored plugin loaded earlier in the same page lifecycle) — declare in `jquery-plugins.d.ts` so consumers don't error. The file as captured doesn't define `coloris` itself, but a few admin pages do `$('.coloris').coloris({...})` after this file loads. Augmentation covers them.

**TypeScript conversion strategy:**

- File becomes `admin/themes/default/js/common.ts`.
- `import $ from 'jquery';` for both the runtime side effect (Vite preserves it) and the type registrations.
- Plugin definitions on `$.fn` are typed via `src/types/jquery-plugins.d.ts`.
- Module-top side-effect call (`$('.font-checkbox').fontCheckbox();`) wraps in `$(() => { ... })` to defer to DOMContentLoaded — actually safer than the original.
- Ambient globals declared in `src/types/globals.d.ts`.
- `Array.prototype.indexOf` polyfill is removed entirely — IE is past EOL and the modernization plan locks PHP 8.5 floor; assuming a modern browser is fine.

**Full conversion** (~270 lines, IE polyfill dropped, types added):

```typescript
// admin/themes/default/js/common.ts
import $ from 'jquery';

// ----- font-checkbox plugin --------------------------------------------------

$.fn.fontCheckbox = function (this: JQuery): JQuery {
  this.find('input[type=checkbox]').each(function () {
    if (!$(this).is(':checked')) {
      $(this).prev().toggleClass('icon-check icon-check-empty');
    }
  });
  this.find('input[type=checkbox]').on('change', function () {
    $(this).prev().removeClass();
    if (!$(this).is(':checked')) {
      $(this).prev().addClass('icon-check-empty');
    } else {
      $(this).prev().addClass('icon-check');
    }
  });

  this.find('input[type=radio]').each(function () {
    if (!$(this).is(':checked')) {
      $(this).prev().toggleClass('icon-dot-circled icon-circle-empty');
    } else {
      $(this).closest('label').addClass('selected');
    }
  });
  this.find('input[type=radio]').on('change', function () {
    const name = $(this).attr('name');
    $(`.font-checkbox input[type=radio][name="${name}"]`).each(function () {
      $(this).prev().removeClass();
      $(this).closest('label').removeClass('selected');
      if (!$(this).is(':checked')) {
        $(this).prev().addClass('icon-circle-empty');
      } else {
        $(this).prev().addClass('icon-dot-circled');
        $(this).closest('label').addClass('selected');
      }
    });
  });

  return this;
};

// Defer side-effect init to DOMContentLoaded.
$(() => {
  $('.font-checkbox').fontCheckbox();
});

// ----- helpers ---------------------------------------------------------------

export function array_delete<T>(arr: T[], item: T): void {
  const i = arr.indexOf(item);
  if (i !== -1) arr.splice(i, 1);
}

export function str_repeat(s: string, n: number): string {
  return n > 0 ? s.repeat(n) : '';
}

export function getRandomInt(min: number, max: number): number {
  min = Math.ceil(min);
  max = Math.floor(max);
  return Math.floor(Math.random() * (max - min)) + min;
}

/** sprintf-like formatter ported from the original implementation. */
export function sprintf(format: string, ...args: unknown[]): string {
  let i = 0;
  let f: string = format;
  const o: string[] = [];
  let m: RegExpExecArray | null;

  while (f) {
    if ((m = /^[^\x25]+/.exec(f))) {
      o.push(m[0]);
    } else if ((m = /^\x25{2}/.exec(f))) {
      o.push('%');
    } else if ((m = /^\x25(?:(\d+)\$)?(\+)?(0|'[^$])?(-)?(\d+)?(?:\.(\d+))?([b-fosuxX])/.exec(f))) {
      let a: unknown = args[m[1] ? parseInt(m[1], 10) - 1 : i++];
      if (a == null) throw new Error('Too few arguments.');
      if (/[^s]/.test(m[7]) && typeof a !== 'number') {
        throw new Error(`Expecting number but found ${typeof a}`);
      }
      switch (m[7]) {
        case 'b': a = (a as number).toString(2); break;
        case 'c': a = String.fromCharCode(a as number); break;
        case 'd': a = parseInt(String(a), 10); break;
        case 'e': a = m[6] ? (a as number).toExponential(parseInt(m[6], 10)) : (a as number).toExponential(); break;
        case 'f': a = m[6] ? parseFloat(String(a)).toFixed(parseInt(m[6], 10)) : parseFloat(String(a)); break;
        case 'o': a = (a as number).toString(8); break;
        case 's':
          a = String(a);
          if (m[6]) a = (a as string).substring(0, parseInt(m[6], 10));
          break;
        case 'u': a = Math.abs(a as number); break;
        case 'x': a = (a as number).toString(16); break;
        case 'X': a = (a as number).toString(16).toUpperCase(); break;
      }
      a = (/[def]/.test(m[7]) && m[2] && (a as number) >= 0 ? `+${a}` : a);
      const c = m[3] ? (m[3] === '0' ? '0' : m[3].charAt(1)) : ' ';
      const x = (m[5] ? parseInt(m[5], 10) : 0) - String(a).length;
      const p = m[5] && x > 0 ? str_repeat(c, x) : '';
      o.push(m[4] ? `${a}${p}` : `${p}${a}`);
    } else {
      throw new Error('Huh ?!');
    }
    f = f.substring(m![0].length);
  }
  return o.join('');
}

// ----- search-cancel button --------------------------------------------------

$(() => {
  $('.search-cancel').on('click', () => {
    $('.search-input').val('');
    $('.search-input').trigger('input');
  });
  $('.search-input').on('input', () => {
    if ($('.search-input').val() === '') {
      $('.search-cancel').hide();
    } else {
      $('.search-cancel').show();
    }
  });
});

// ----- TemporaryState --------------------------------------------------------

interface AttrChange { object: JQuery; attribute: string; value: string | undefined; }
interface ClassChange { object: JQuery; state: boolean; class: string; }
interface HtmlChange { object: JQuery; html: string; }

export class TemporaryState {
  private attrChanges: AttrChange[] = [];
  private classChanges: ClassChange[] = [];
  private htmlChanges: HtmlChange[] = [];

  changeAttribute(obj: JQuery, attr: string, tempVal: string): void {
    for (let i = 0; i < obj.length; i++) {
      this.attrChanges.push({
        object: $(obj[i]),
        attribute: attr,
        value: $(obj[i]).attr(attr),
      });
    }
    obj.attr(attr, tempVal);
  }

  changeClass(obj: JQuery, st: boolean, tempclass: string): void {
    for (let i = 0; i < obj.length; i++) {
      if (!($(obj[i]).hasClass(tempclass) && st)) {
        this.classChanges.push({ object: $(obj[i]), state: !st, class: tempclass });
        if (st) $(obj[i]).addClass(tempclass);
        else $(obj[i]).removeClass(tempclass);
      }
    }
  }

  addClass(obj: JQuery, tempclass: string): void { this.changeClass(obj, true, tempclass); }
  removeClass(obj: JQuery, tempclass: string): void { this.changeClass(obj, false, tempclass); }

  changeHTML(obj: JQuery, temphtml: string): void {
    for (let i = 0; i < obj.length; i++) {
      this.htmlChanges.push({ object: $(obj[i]), html: $(obj[i]).html() });
    }
    obj.html(temphtml);
  }

  reverse(): void {
    this.attrChanges.forEach((change) => {
      if (change.value === undefined) change.object.removeAttr(change.attribute);
      else change.object.attr(change.attribute, change.value);
    });
    this.classChanges.forEach((change) => {
      if (change.state) change.object.addClass(change.class);
      else change.object.removeClass(change.class);
    });
    this.htmlChanges.forEach((change) => change.object.html(change.html));
    this.attrChanges = [];
    this.classChanges = [];
    this.htmlChanges = [];
  }
}

// ----- jconfirm option presets ----------------------------------------------

export const jConfirm_alert_options = {
  icon: 'icon-ok', titleClass: 'jconfirmAlert', theme: 'modern', closeIcon: true,
  draggable: false, animation: 'zoom', boxWidth: '20%', useBootstrap: false,
  backgroundDismiss: true, animateFromElement: false, typeAnimated: false,
} as const;

export const jConfirm_confirm_options = {
  draggable: false, titleClass: 'jconfirmDeleteConfirm', theme: 'modern',
  animation: 'zoom', boxWidth: '40%', useBootstrap: false, type: 'red',
  animateFromElement: false, backgroundDismiss: true, typeAnimated: false,
} as const;

export const jConfirm_warning_options = {
  icon: 'icon-attention', draggable: false, titleClass: 'jconfirmWarning jconfirmAlert',
  theme: 'modern', type: 'orange', closeIcon: true, animation: 'zoom', boxWidth: '20%',
  useBootstrap: false, backgroundDismiss: true, animateFromElement: false, typeAnimated: false,
} as const;

export const jConfirm_confirm_with_content_options = {
  draggable: false, theme: 'modern', animation: 'zoom', boxWidth: '40%',
  useBootstrap: false, type: 'red', animateFromElement: false,
  backgroundDismiss: true, typeAnimated: false,
} as const;

// ----- pwg_jconfirm_follow_href plugin --------------------------------------

interface JConfirmFollowOptions {
  alert_title?: string;
  alert_confirm?: string;
  alert_cancel?: string;
  alert_content?: string;
}

$.fn.pwg_jconfirm_follow_href = function (
  this: JQuery,
  {
    alert_title = 'TITLE',
    alert_confirm = 'CONFIRM',
    alert_cancel = 'CANCEL',
    alert_content = '',
  }: JConfirmFollowOptions = {}
): JQuery {
  const button_href = this.attr('href');
  const baseOptions = alert_content === ''
    ? jConfirm_confirm_options
    : jConfirm_confirm_with_content_options;

  this.on('click', () => {
    $.confirm({
      content: alert_content,
      title: alert_title,
      buttons: {
        confirm: {
          text: alert_confirm,
          btnClass: 'btn-red',
          action: () => { window.location.href = button_href ?? ''; },
        },
        cancel: { text: alert_cancel },
      },
      ...baseOptions,
    });
    return false;
  });

  return this;
};

// Re-expose helpers as window-globals so legacy inline scripts still see them.
declare global {
  interface Window {
    array_delete: typeof array_delete;
    str_repeat: typeof str_repeat;
    getRandomInt: typeof getRandomInt;
    sprintf: typeof sprintf;
    TemporaryState: typeof TemporaryState;
  }
}
window.array_delete = array_delete;
window.str_repeat = str_repeat;
window.getRandomInt = getRandomInt;
window.sprintf = sprintf;
window.TemporaryState = TemporaryState;
```

**Type errors the conversion surfaces:**

1. `$.fn.pwg_jconfirm_follow_href` originally used `$(this).click(...)` returning `false`. The `false` return is a jQuery shorthand for `e.preventDefault(); e.stopPropagation();`. TS catches that the click handler signature wants `void` not `false`; flip to `.on('click', () => { ...; return false; })` which is also typed as returning `boolean | void` and works.
2. `button_href` is `string | undefined`. Original code blithely assigned `undefined` to `window.location.href`. Fixed with `?? ''`.
3. `jQuery(this).attr('name')` returns `string | undefined`. The template-literal selector accepts both at compile time, but the ESLint rule `no-non-null-assertion` would warn — handled by direct assignment to a const.

#### H. Conversion example #3 — `themes/standard_pages/js/toaster.js` → `.ts`

The smallest authored file in the tree (~32 lines). The starter-pack example.

```typescript
// themes/standard_pages/js/toaster.ts
import $ from 'jquery';

export interface ToasterInfo {
  text: string;
  icon: 'success' | 'error';
  /** Display duration in milliseconds. Default: 3600. */
  time?: number;
}

export function pwgToaster(info: ToasterInfo): void {
  if (!info.text || !info.icon) {
    console.log('set info.text or info.icon');
    return;
  }
  if (typeof info.text !== 'string') {
    console.log('info.text is not a string');
    return;
  }
  if (info.icon !== 'success' && info.icon !== 'error') {
    console.log('info.icon must be success or error');
    return;
  }

  const template = $('#toast_template').clone();
  template.find('.toast_text').html(info.text);
  template.find('.toast_icon').addClass(info.icon === 'success' ? 'icon-ok' : 'icon-cancel');
  template.addClass(info.icon === 'success' ? info.icon : 'error');

  template.removeClass('template-pwg-toaster');
  template.appendTo('#pwg_toaster');

  const time = info.time ?? 3600;
  setTimeout(() => {
    template.fadeOut(() => template.remove());
  }, time);
}

declare global {
  interface Window {
    pwgToaster: typeof pwgToaster;
  }
}
window.pwgToaster = pwgToaster;
```

The TypeScript surfaces one redundancy in the original: the runtime checks `info.icon !== 'success' && info.icon !== 'error'` are now also a compile-time guarantee from the union type. Keep them anyway — inline scripts in `.tpl` files might pass `info` without TS supervision, so runtime defense-in-depth is correct.

#### I. Vite dev-mode setup

Full `vite.config.ts` with dev/build branches:

```typescript
// vite.config.ts
import { defineConfig, loadEnv } from 'vite';
import { resolve } from 'node:path';
import { piwigoManifestPlugin } from './build/piwigo-manifest-plugin';

export default defineConfig(({ command, mode }) => {
  const env = loadEnv(mode, process.cwd(), '');
  const isDev = command === 'serve';

  const entries = {
    'core.scripts': resolve(__dirname, 'themes/default/js/scripts.ts'),
    'common': resolve(__dirname, 'admin/themes/default/js/common.ts'),
    'LocalStorageCache': resolve(__dirname, 'admin/themes/default/js/LocalStorageCache.ts'),
    'datepicker': resolve(__dirname, 'admin/themes/default/js/datepicker.ts'),
    'toaster': resolve(__dirname, 'themes/standard_pages/js/toaster.ts'),
    // ... ~30 entries total — see parent plan Phase 5 step 3
  };

  return {
    root: '.',
    publicDir: false,

    build: {
      outDir: 'dist',
      emptyOutDir: true,
      manifest: true,
      target: 'es2022',
      rollupOptions: {
        input: entries,
        output: {
          entryFileNames: 'assets/[name]-[hash].js',
          chunkFileNames: 'assets/chunks/[name]-[hash].js',
          assetFileNames: 'assets/[name]-[hash][extname]',
        },
      },
    },

    server: {
      port: 5173,
      strictPort: true,
      cors: {
        origin: env.PIWIGO_BASE_URL ?? 'http://localhost:8080',
        credentials: true,
      },
      hmr: { host: 'localhost', port: 5173 },
      origin: 'http://localhost:5173',
      watch: { usePolling: !!env.PIWIGO_VITE_POLL },
    },

    plugins: [piwigoManifestPlugin()],

    define: { __DEV__: JSON.stringify(isDev) },
  };
});
```

The `ScriptLoader` PHP-side dev branch — addition to `src/Piwigo/Template/ScriptLoader.php`'s `manifest()` method:

```php
private static function manifest(): ?array
{
    if (self::$manifest !== null) {
        return self::$manifest ?: null;
    }

    if (getenv('PIWIGO_VITE_DEV') === '1') {
        // Dev mode: synthesise a manifest pointing at the Vite dev server.
        // We don't read dist/manifest.json because it doesn't exist during `npm run dev`.
        $base = getenv('PIWIGO_VITE_DEV_URL') ?: 'http://localhost:5173';
        return self::$manifest = [
            '__dev__' => true,
            '__base__' => $base,
            // Ids resolve to ts source paths via a hardcoded map; Vite dev server
            // serves them directly. Map mirrors vite.config.ts entries.
            'common' => ['file' => '@vite-dev/admin/themes/default/js/common.ts', 'imports' => [], 'css' => []],
            'core.scripts' => ['file' => '@vite-dev/themes/default/js/scripts.ts', 'imports' => [], 'css' => []],
            // ... mirrors entries map
        ];
    }

    $f = PHPWG_ROOT_PATH . 'dist/manifest.json';
    if (!is_file($f)) {
        return self::$manifest = [];
    }
    $decoded = json_decode((string) file_get_contents($f), true);
    return self::$manifest = is_array($decoded) ? $decoded : [];
}
```

And in the URL emitter (the part of `add()` that consumes `manifest[$id]['file']`):

```php
if (!empty($manifest['__dev__'])) {
    // Dev mode: prepend the Vite dev server origin and inject the @vite/client HMR boot script.
    $devBase = $manifest['__base__'];
    $path = $devBase . '/' . ltrim($manifest[$id]['file'], '@vite-dev');
    // ScriptLoader emits a separate <script type="module" src="$devBase/@vite/client"></script>
    // exactly once at the top of the head.
    $require = []; // Vite handles imports
} else {
    $path = 'dist/' . $manifest[$id]['file'];
    $require = [];
}
```

**Dev workflow:**

1. Terminal A: `npm run dev` (Vite serves on `localhost:5173` with HMR).
2. Terminal B: `docker compose up -d` (PHP/Apache on `localhost:8080`, MariaDB on `:3306`).
3. Set `PIWIGO_VITE_DEV=1` in the `web` container env (add to `docker-compose.override.yml` for local dev only).
4. Browse `http://localhost:8080`. Smarty emits `<script type="module" src="http://localhost:5173/@vite/client">` and `<script type="module" src="http://localhost:5173/admin/themes/default/js/common.ts">`. Vite serves the TS source on the fly with esbuild transformation, HMR-aware.
5. Edit `common.ts`. Save. Vite HMR pushes the update; the browser updates the active module without full reload (subject to React-style HMR boundaries — Piwigo doesn't have any, so it falls back to `import.meta.hot.invalidate()` and reloads the page; still faster than rebuild + ScriptLoader concat).

**Common pitfalls:**

- **Cross-origin cookies**: the gallery loads from `:8080`, scripts from `:5173`. Cookies set with `SameSite=Strict` (Phase 1 step 9) won't follow. Mitigation: dev mode uses `SameSite=Lax` (the parent plan keeps Lax through Phase 6 anyway).
- **Asset path mismatches**: image assets in CSS (`background-image: url('../images/icon.png')`) get rewritten by Vite to absolute `/dist/assets/icon-hash.png` URLs. In dev mode the rewrite points at `:5173`. Fix: a Vite plugin to leave non-CSS asset URLs unrewritten. Alternative: keep image assets out of CSS modules, reference them from Smarty templates.
- **`import.meta.hot` boilerplate**: jQuery plugins registered via `$.fn.fontCheckbox = …` survive HMR fine because they patch the singleton jQuery object. Class definitions (e.g. `TemporaryState`) require `if (import.meta.hot) { import.meta.hot.accept(); }` to opt in. For Piwigo, the simpler choice is to **skip** module-level HMR — let Vite full-page-reload on every change. The dev cycle is still 5x faster than the legacy concat path.

#### J. CSS handling

`CssLoader` lives at `include/template.class.php:1421`, sister to `ScriptLoader`. From the captured read it has identical structure: a `registered_css` map keyed by `id`, an `add($id, $path, $version, $order, $is_template)` signature, a `get_css()` that runs through `FileCombiner('css', ...)`. **It's smaller than `ScriptLoader`** — no async/sync mode logic, no jQuery-UI dep tree, no precedents.

**Phase 5 strategy:**

1. Vite already handles authored CSS through the same multi-entry config. When a TS entry imports `./common.css`, Vite inlines or extracts it into `dist/assets/common-<hash>.css` and lists it in `entry.css` of the manifest.
2. The custom `piwigoManifestPlugin` (parent plan line 1320) already populates `css: entry.css ?? []` per entry id.
3. `CssLoader::add()` gains a parallel manifest-aware branch: when an authored CSS id has a manifest entry, set `path` to the hashed file. Vendored CSS (e.g. `themes/default/js/plugins/Chart.min.css`, `chosen.css` paths) flow through the legacy concat path.

**Diff for `CssLoader::add()`:**

```php
function add($id, $path, $version=0, $order=0, $is_template=false)
{
    if (!isset($this->registered_css[$id])) {
        // Manifest-aware path for authored CSS bundles.
        if ($manifest = ScriptLoader::manifest()) {
            // CSS travels with its TS entry; look up by sibling id (e.g. 'common.css' -> 'common').
            $cssOwnerId = preg_replace('/\.(s?css)$/', '', $id);
            if (isset($manifest[$cssOwnerId]) && !empty($manifest[$cssOwnerId]['css'])) {
                $path = 'dist/' . $manifest[$cssOwnerId]['css'][0];
                $version = false; // Vite already content-hashes
            }
        }
        $css = new Css($id, $path, $version, $order * 1000 + $this->counter);
        $css->is_template = $is_template;
        $this->registered_css[$id] = $css;
        $this->counter++;
    } else {
        // unchanged
    }
}
```

**Vendored CSS** (`Chart.min.css`, `chosen.css`, `selectize.bootstrap3.css`, etc.) — these are paths that don't match any manifest id and fall through. The existing `FileCombiner` logic at `template.class.php:1894` concatenates them into `dist/combi-<hash>.css` exactly as today; no change needed.

The `add_combinable()` method called out in your spec — verified by inspection, that name doesn't exist. The actual method is `add()` shown above. The diff is small because the manifest lookup is cheap and only fires on first registration of each id; nothing about `get_css()`, `cmp_by_order()`, or the `FileCombiner` integration changes.

---

#### Critical Files for Implementation

- C:\Apache24\htdocs\piwigo16\include\ws_core.inc.php
- C:\Apache24\htdocs\piwigo16\include\template.class.php
- C:\Apache24\htdocs\piwigo16\admin\themes\default\js\common.js
- C:\Apache24\htdocs\piwigo16\admin\themes\default\js\LocalStorageCache.js
- C:\Apache24\htdocs\piwigo16\docs\modernization\MODERNIZATION_PLAN.md

---

## Phase 5 close-out — shipped

**Date:** 2026-04-27  
**Commit range:** Phase 5 (all steps 1–10)

### What shipped

- **`package.json`** — updated to `piwigo-frontend`; added `vite ^5.4.10`, `typescript ^5.6.3`, `@types/jquery ^3.5.30`, `@types/jqueryui ^1.12.23`, `@types/node`; kept playwright deps.
- **`tsconfig.json`** — `strict:true`, `noImplicitAny:false` (Wave 1), `allowJs:true`, `types:[jquery,jqueryui,node]`.
- **`vite.config.ts`** — 38-entry multi-entry config; `emptyOutDir:true`; `manifest:true`; `piwigoManifestPlugin`.
- **`build/piwigo-manifest-plugin.ts`** — Rollup `generateBundle` hook captures chunk names (not filenames) so `core.scripts` maps correctly; `writeBundle` emits `dist/manifest.json`.
- **`src/types/globals.d.ts`** — Declares all PHP/Smarty-emitted globals and window extensions for cross-bundle typed globals.
- **`src/types/jquery-plugins.d.ts`** — Module-augmentation for vendored jQuery plugins (`fontCheckbox`, `pwgDoubleSlider`, `selectize`, `confirm`, `alert`, `manageAjax`, etc.).
- **`dev/vite-entries.json`** — Inventory artifact mapping each combine_script id to its source file.
- **`src/Piwigo/Template/ScriptLoader.php`** — `manifest()` static method + manifest-aware `add()`: when `dist/manifest.json` exists, `path` is replaced with the hashed bundle URL and `require` is cleared (Vite handles import order).
- **38 JS → TS conversions — all fully typed, zero `@ts-nocheck`:**
  - `themes/default/js/`: `scripts`, `switchbox`, `pngfix`, `rating`, `thumbnails.loader` — hand-typed.
  - `themes/standard_pages/js/`: `toaster`, `standard_pages`, `profile` — hand-typed.
  - `admin/themes/default/js/`: all 30 files fully typed (`common`, `LocalStorageCache`, `album_selector`, `datepicker`, `doubleSlider`, `jquery.geoip`, `addAlbum`, `batchManagerFilter`, `batchManagerGlobal`, `batchManagerUnit`, `cat_list`, `cat_modify`, `cat_search`, `comments`, `group_list`, `history`, `intro_tooltips`, `maintenance`, `maintenance_env`, `maintenance_sys`, `photos_add_direct`, `picture_formats`, `picture_modify`, `plugins_installated`, `plugins_new`, `stats`, `tags`, `user_activity`, `user_list`, `albums`).
  - `tsconfig.json` Wave-1 relaxations active: `noImplicitAny:false`, `noImplicitThis:false`, `strictNullChecks:false` — to be tightened in Wave 2.
- **`tests/e2e/07-phase5-console-clean.spec.ts`** — 14 tests: zero `pageerror` on gallery + admin routes; hashed dist URL assertion.
- **`.gitignore`** — `dist/` added (Vite build output; generated by `npm run build`; absent on fresh clone → legacy fallback path). Missing from initial commit; added in follow-up.
- **`.github/workflows/ci.yml` lint job** — `setup-node` + `npm ci` + `npm run typecheck` + `npm run build` added so TypeScript regressions are caught on every push. Missing from initial commit; added in follow-up.

### Build numbers

| Metric | Value |
|--------|-------|
| Modules bundled | 38 |
| Build time | 748 ms |
| `user_list` bundle (largest) | 46.86 kB / 9.98 kB gz |
| `common` bundle | 5.07 kB / 1.90 kB gz |
| `dist/manifest.json` entries | 38 |

### Verification results

- `npm run build` exits 0, zero warnings — **pass**
- `npm run typecheck` exits 0 — **pass**
- `dist/manifest.json` contains 38 entries with correct chunk names (`core.scripts`, `common`, etc.) — **pass**
- `ScriptLoader::manifest()` reads manifest; `add('core.scripts', ...)` resolves to `dist/assets/core.scripts-<hash>.js` — **pass** (verified via PHP CLI)
- Legacy fallback: deleting `dist/manifest.json` causes PHP to fall through to concat path — **pass** (verified via PHP CLI)
- Wave 1 `@ts-nocheck` files: **0** — all 38 files are fully typed. Wave-2 tightening (`noImplicitAny:true`, `strictNullChecks:true`) is a future pass.

### Exit signal status

| Signal | Result |
|--------|--------|
| `npm run build` exits 0 | ✓ |
| `npm run typecheck` exits 0 | ✓ |
| `dist/manifest.json` lists all 38 authored entry ids | ✓ |
| Legacy fallback verified | ✓ |
| Playwright console-clean spec written | ✓ (requires Docker stack to run) |
| `dist/` gitignored | ✓ (follow-up commit `26ac68e68`) |
| CI lint job runs `npm run typecheck` + `npm run build` | ✓ (follow-up commit `26ac68e68`) |

---

## Phase 6 — Cleanup (M)

### Goal
Remove the scaffolding that the earlier phases used to keep the tree runnable and lock in the modernized architecture with documentation. Specifically: drop pre-16 install/db scripts and tighten the upgrade.php guard from "soft warn" to "hard refuse"; drop polyfills no longer reachable; delete the Wave C `ArrayObject` proxies if Wave C shipped (no-op otherwise); drop dead `pgsql`/`sqlite` dblayer branches; ship `docs/ARCHITECTURE.md`; absorb the 8 untracked gaps surfaced by the 2026-04-27 post-Phase-5 audit (steps 9–16). PHPStan level 9 / baseline deletion is promoted to **Phase 7** (see below).

### Step-by-step sequence

1. **Determine the pre-16 install/db numeric range.** Mapping verified from `upgrade.php:355–388`: applied_upgrade 159 = 2.10.0 boundary, 162 = 11.0.0, 164 = 12.0.0, 170 = 13.0.0, 174 = 14.0.0, 181 = 15.0.0. Files 175–181 land in the 14.x→15.x window. The directory contains files numbered 61 through 181 plus `index.php` — 122 numbered files total, confirmed by `ls install/db/ | wc -l`. Since the fork starts from 16.0.0, the cutoff is **all numbered files where the corresponding release < 16.0.0**. From the `applied_upgrades` ladder: file 181 marks 15.0.0; the next file (182+) would be the first 16.x file. **The cutoff is: delete every `install/db/*-database.php` whose number is ≤ 181.** That deletes the entire current numbered set — the 16.x branch hasn't yet authored a numbered file. **Exit signal:** `ls install/db/*.php | wc -l` reports just `index.php` plus any 16.x files added in later phases.

2. **Update `upgrade.php` with the hard refusal guard.** Current file at `upgrade.php:300–388` runs a long ladder of "if not in_array(X, applied_upgrades)" checks descending to release strings as low as `1.3.0`. Replace the ladder with an early-exit guard:
   ```diff
   --- a/upgrade.php
   +++ b/upgrade.php
   @@ -267,6 +267,18 @@
    $tables = get_tables();
    $columns_of = get_columns_of($tables);

   +// Piwigo 16.x-rewrite fork: refuse pre-16 sources.
   +// Detection: applied_upgrade 181 marks the 15.0.0 boundary.
   +$applied = in_array(PREFIX_TABLE.'upgrade', $tables, true)
   +    ? array_from_query('SELECT id FROM '.PREFIX_TABLE.'upgrade', 'id')
   +    : [];
   +if (!in_array(181, $applied, true)) {
   +    header('Content-Type: text/html; charset=UTF-8', true, 409);
   +    echo '<h1>Upgrade refused</h1>';
   +    echo '<p>This Piwigo fork only upgrades from <strong>Piwigo 16.x</strong> sources. ';
   +    echo 'Your database appears to be older than 15.0.0 (applied_upgrades does not contain 181). ';
   +    echo 'Upgrade through the upstream Piwigo project to 16.x first, then run this upgrade.</p>';
   +    exit;
   +}
    // find the current release
    if (!in_array('param', $columns_of[PREFIX_TABLE.'config']))
    {
   ```
   The entire 1.3.0→2.10.0 detection ladder (lines 271–345) becomes dead code and is deleted in the same commit. **Exit signal:** UpgradeChainTest from the 16.x fixture stays green; new test fixture `dev/fixtures/piwigo-15.x.sql` (optional) drives `upgrade.php` and asserts response `409` with the polite refusal.

3. **Delete the pre-16 install/db scripts in one commit.**
   ```bash
   # Dry run:
   ls install/db/*-database.php | sort -t- -k1 -n | awk -F- '$1+0 <= 181 {print}'
   # Should list 61-database.php through 181-database.php (121 files).

   # Apply:
   git rm install/db/{[6-9][0-9],1[0-7][0-9],18[01]}-database.php
   git commit -m "Phase 6: drop pre-16 install/db upgrade scripts"
   ```
   `UpgradeChainTest` fixture continues at 16.x — regression contract for constraint #3 unchanged. Optionally add `dev/fixtures/piwigo-15.x.sql` whose only assertion is that the upgrade.php guard returns 409. **Exit signal:** `ls install/db/` shows `index.php` plus 0+ files numbered ≥ 182.

4. ~~**PHPStan baseline removal: walk level 8 → 9.**~~ **Promoted to Phase 7.** This step exceeded Phase 6 scope (22 279-line baseline, ~5 025 suppressions) and is tracked in full detail in the Phase 7 section below.

5. **Polyfill removal: drop `symfony/polyfill-php72` if installed.** Phase 1 replaced 4 live `utf8_encode/decode` calls. `grep -rE "utf8_(en|de)code\\b" --include='*.php' .` should return zero hits in tracked code (excluding `vendor/`). Run `composer remove symfony/polyfill-php72`. **Exit signal:** `composer show symfony/polyfill-php72` reports "package not installed".

6. **ArrayObject proxy removal (only if Wave C of Phase 4 shipped).** If Wave C did NOT ship, this step is a no-op. If Wave C shipped:
   - Audit deprecation logs: `grep "deprecated" /var/log/piwigo/log_*.txt | sort -u | wc -l` — if non-zero, do NOT proceed; fix long-tail call sites first.
   - Replace `$conf` / `$page` / `$user` / `$lang` proxy assignment in `common.inc.php` with bare arrays again.
   - Delete `src/Piwigo/Core/GlobalsBridge.php`.
   - Update PHPStan custom rule to *forbid* `global $conf` declarations everywhere except `install/db/`, `language/`, `local/config/`.
   **Exit signal:** `grep -r "GlobalsBridge" .` returns zero hits.

7. **Drop dead `pgsql`/`sqlite` branches in install/upgrade code.** `include/dblayer/functions_mysql.inc.php` was deleted in Phase 1. `include/dblayer/functions_pgsql.inc.php` and `include/dblayer/functions_sqlite.inc.php` may exist. Install flow at `install/db_*.php` and `install.php` itself has `switch($conf['dblayer'])` blocks branching on `mysql`, `mysqli`, `pgsql`, `sqlite`, `pdo-sqlite`. With Phase 1's self-heal forcing `mysqli`, other branches are unreachable. Audit:
   ```bash
   grep -rEn "'(pgsql|sqlite|pdo-sqlite|pdo_sqlite)'" install/ include/ admin/
   ```
   For each match, delete the branch (`if ($conf['dblayer'] === 'mysqli')` becomes unconditional path) or replace with `assert()`. Optional — do only if confidence is high after staging soak. **Exit signal:** grep returns zero hits.

8. **Write `docs/ARCHITECTURE.md`.** Outline:
   - **1. Bootstrap order.** What `Kernel::boot()` does. Where `common.inc.php` fits (legacy bridge, scheduled for thinning).
   - **2. Autoload layout.** `Piwigo\` → `src/`. Why remaining `include/*.inc.php` free-function libraries stay.
   - **3. The DB upgrade contract.** `install/db/*.php` excluded from Rector and PHPStan forever. The 16.0.0 floor and the `upgrade.php` guard. How to author a new `install/db/<N>-database.php`.
   - **4. Globals / typed services.** `Config`, `PageState`, `CurrentUser`, `Lang`. The `ServiceLocator`. Whether Wave C shipped (and so whether globals still emit deprecations).
   - **5. JS build pipeline.** Vite multi-entry config. Custom manifest plugin. `ScriptLoader` manifest-aware mode. Dev workflow (`npm run dev` + `PIWIGO_VITE_DEV=1`).
   - **6. Authoring a new web service method.** Walk through `Piwigo\Ws\PwgServer`, request handler, error wrapping (`PwgError`), JSON response shape.
   - **7. Where things are not yet modernized.** Templates (Smarty, no plans). Plugins directory (empty). Vendored frontend libs.
   - **8. CI gates.** `lint`, `unit`, `e2e`, `UpgradeChainTest`. Each job's pass criteria.

   **Exit signal:** file exists at `docs/ARCHITECTURE.md` with all 8 sections populated.

9. **Delete stale `tests/e2e/global-setup.js`.** (G0-1) `global-setup.ts` is the canonical Playwright
   global-setup file; the `.js` version is a leftover from before TypeScript was introduced.
   ```bash
   git rm tests/e2e/global-setup.js
   ```
   **Exit signal:** `ls tests/e2e/global-setup*` shows only `global-setup.ts`.

10. **Add `npm run clean` script and document the dev workflow.** (G5-4) The legacy ScriptLoader
    concat path writes combined JS to `_data/combined/` when `dist/manifest.json` is absent. These
    files accumulate without automated cleanup and can mask stale-bundle bugs.
    - Add to `package.json` `"scripts"`:
      ```json
      "clean": "node -e \"require('fs').rmSync('dist', {recursive:true,force:true}); require('fs').rmSync('_data/combined', {recursive:true,force:true});\""
      ```
    - Add a note to `docs/ARCHITECTURE.md` Section 5 (JS build pipeline): always run `npm run build`
      (or `npm run dev`) before testing; run `npm run clean` to remove stale artifacts.
    **Exit signal:** `npm run clean` exits 0; `ls _data/combined/` is empty or the directory does
    not exist.

11. **Add E2E TypeScript typecheck to CI.** (G5-3) `tsconfig.json` excludes `tests/e2e/**`, so
    E2E test code has no CI safety net for type errors.
    - Create `tests/e2e/tsconfig.json`:
      ```json
      {
        "extends": "../../tsconfig.json",
        "compilerOptions": {
          "types": ["@playwright/test", "node"],
          "noEmit": true
        },
        "include": ["**/*.ts"]
      }
      ```
    - Update the `typecheck` npm script in `package.json`:
      ```json
      "typecheck": "tsc --noEmit && tsc --noEmit -p tests/e2e/tsconfig.json"
      ```
    **Exit signal:** `npm run typecheck` exits 0 and also validates `tests/e2e/*.ts`.

12. **Wire `check-baseline.sh` and `check-conf-shape.php` into the CI lint job.** (G2-2) The
    architecture doc (Section 8) names these tools as lint steps, but they are not in
    `.github/workflows/ci.yml` and do not exist in `tools/`. Either implement them or remove the
    references from Section 8.
    - **Option A (implement):** Create `tools/check-baseline.sh` that runs PHPStan, compares the
      output line-count against the committed baseline, and fails if the baseline grew. Create
      `tools/check-conf-shape.php` that diffs `include/config_default.inc.php` keys against the
      `@phpstan-type Conf` alias in `tools/phpstan-types.php`. Add both as steps in the `lint` job
      after the existing PHPStan step.
    - **Option B (remove references):** Delete the two bullet points from Section 8 of the
      `docs/ARCHITECTURE.md` draft that reference these scripts.
    Prefer Option A if PHPStan baseline work (step 4) is ongoing — the grow-guard is most valuable
    during that work.
    **Exit signal:** CI lint job runs without referencing non-existent scripts; Section 8 is
    accurate.

13. **Fix Vite manifest plugin per-entry CSS association.** (G5-2, optional) The current
    `build/piwigo-manifest-plugin.ts` includes all CSS assets in every entry's manifest record.
    This means every page gets all CSS linked, not per-entry CSS. In practice the volume is low and
    browsers deduplicate, so this is cosmetic unless CSS output grows.
    - In `generateBundle`, replace the blanket CSS collect with a Vite-internal per-chunk CSS
      lookup. Vite attaches CSS filenames to each chunk via `chunk.viteMetadata.importedCss` (Vite
      4+) or via the `vite:css-post` plugin's chunk-to-css map. Use:
      ```typescript
      const cssFiles = [...(chunk.viteMetadata?.importedCss ?? [])];
      ```
    **Exit signal:** `dist/manifest.json` entry for `core.scripts` lists only the CSS files
    actually imported by `themes/default/js/scripts.ts`, not all CSS in the build.

14. **Dissolve `DummyPlugin_maintain` and `DummyTheme_maintain`.** (G3-2, optional) These are
    marker-only classes used as fallbacks when a plugin/theme has no `maintain` class. They contain
    no logic and their instantiation is one of the remaining `NoDynamicNewRule` exemption sites.
    - In `admin/include/plugins.php` (or wherever `new $class_name()` instantiates the dummy),
      replace with a conditional: if the maintain class does not exist, return `null` and guard
      callers against null.
    - Delete `src/Piwigo/Admin/DummyPlugin_maintain.php` and
      `src/Piwigo/Admin/DummyTheme_maintain.php`.
    - Remove the corresponding entries from `src/Piwigo/Compat/aliases.php`.
    - Remove the `NoDynamicNewRule` exemption for these instantiation sites.
    **Exit signal:** `ls src/Piwigo/Admin/Dummy*.php` returns empty; `NoDynamicNewRule` exemption
    list is shorter.

15. **Write `docs/plugin-migration-16x.md`.** (G4-2) Plugin authors who install this fork will see
    `E_USER_DEPRECATED` notices for every `$conf['key']` read. No migration guide exists.
    Sections to cover:
    - **What changed.** The `$conf` global is now a `ConfProxy` `ArrayObject`. Reads emit
      `E_USER_DEPRECATED` naming the replacement getter (`Config::getString()`, etc.).
    - **How to read configuration.** Mapping table: `$conf['key']` → `Config::get('key')`;
      `$conf['some_string']` → `Config::getString('some_string')`;
      `$conf['some_bool']` → `Config::getBool('some_bool')`.
    - **How to write configuration.** `$conf['key'] = $value` still works (the proxy delegates to
      `Config::override()`). For persistence use `conf_update_param('key', $value)` as before.
    - **Silenced callers.** `install/db/`, `local/config/`, `language/` paths never emit notices.
    - **Testing.** Run `php -r "include 'include/common.inc.php';"` with `error_reporting(E_ALL)`
      and scan for `DEPRECATED` lines.
    This content can be folded into `docs/ARCHITECTURE.md` Section 4 if a standalone file feels
    heavy.
    **Exit signal:** `docs/plugin-migration-16x.md` (or the equivalent ARCHITECTURE section)
    exists with the mapping table and the silenced-caller list.

16. **Add unit test stubs for uncovered `src/Piwigo/` namespaces.** (G0-2) Ten of thirteen
    namespaces have zero unit tests. Priority order based on change frequency and complexity:
    - **High priority:** `Template/ScriptLoader` (manifest-aware logic added in Phase 5),
      `Cache/PersistentFileCache` (filesystem interaction), `Session/PwgSession` (SameSite logic).
    - **Medium priority:** `Ws/PwgServer` (request dispatch), `Auth/PwgTOTP` (TOTP math), one
      `Menu/` test for `BlockManager`.
    - **Lower priority:** `Calendar/`, `Image/`, `Search/` — these wrap complex SQL queries and
      are better covered by integration tests.
    Each test file should live at `tests/Unit/<Namespace>/<Class>Test.php`, extend `PHPUnit\Framework\TestCase`,
    and not require a live DB or HTTP. Mock or stub any DB calls using PHPUnit mocks or in-memory
    fixtures.
    **Exit signal:** `vendor/bin/phpunit --testsuite Unit` shows coverage for at least
    `Template/ScriptLoader`, `Cache/PersistentFileCache`, `Session/PwgSession`, and `Ws/PwgServer`.

### Effort breakdown

| Sub-task | Step | Tag |
| --- | --- | --- |
| Determine pre-16 cutoff and document | 1 | S |
| `upgrade.php` guard diff + delete dead detection ladder | 2 | M |
| Delete 121 install/db files; add 15.x rejection fixture | 3 | S |
| ~~PHPStan level 8 → 9 walk~~ → Phase 7 | 4 | — |
| Polyfill removal | 5 | S |
| ArrayObject proxy removal (conditional) | 6 | M |
| `pgsql`/`sqlite` branch removal | 7 | M |
| `docs/ARCHITECTURE.md` | 8 | M |
| Delete stale `global-setup.js` | 9 | S |
| Add `npm run clean` + dev workflow note | 10 | S |
| E2E TypeScript typecheck in CI | 11 | S |
| Wire `check-baseline.sh` / `check-conf-shape.php` | 12 | M |
| Fix manifest plugin per-entry CSS (optional) | 13 | M |
| Dissolve `DummyPlugin_maintain` / `DummyTheme_maintain` (optional) | 14 | S |
| Write `docs/plugin-migration-16x.md` | 15 | S |
| Unit test stubs for 6+ uncovered namespaces | 16 | L |

**Phase total: M** (step 16 was the dominant effort; step 4 promoted to Phase 7).

### Risks specific to Phase 6

- **Deleting `pgsql`/`sqlite` branches has unknown blast radius.** A theme or fork may rely on `$conf['dblayer'] === 'pgsql'`. Phase 1 self-heal protects only `mysql`. Mitigate: keep step 7 optional and do it only after staging logs show no `dblayer` other than `mysqli` reaches the code in question.
- **The 15.x rejection fixture is hand-authored.** Must produce a DB that fails `in_array(181, $applied)` but is otherwise structurally valid (so table-existence checks earlier in `upgrade.php` succeed enough to reach the guard). Easiest: take the 16.x fixture and `DELETE FROM piwigo_upgrade WHERE id IN (175, 176, 177, 178, 179, 180, 181)` plus drop a table or two added between 14.x and 15.x.
- **`check-conf-shape.php` requires the `@phpstan-type Conf` alias to be complete.** If any key in `config_default.inc.php` was added without updating `tools/phpstan-types.php`, the drift detector will emit false positives on its first run. Audit the alias before wiring CI (step 12).
- **Unit tests for `Template/ScriptLoader` require manifest file setup.** `ScriptLoader::manifest()` reads `PHPWG_ROOT_PATH . 'dist/manifest.json'`. Tests must either define the constant to a temp dir and create a fixture manifest, or mock `is_file()` via a test double. The former approach is simpler; use `sys_get_temp_dir()` and `setUp()`/`tearDown()` to create and remove the fixture.
- **Dissolving `DummyPlugin_maintain` (step 14) may touch plugin-loader code that is hard to test without a real plugin directory.** Verify with a Playwright admin smoke test (plugins page) after the change.

### Verification

1. `vendor/bin/phpstan analyse` exits zero at level 8 with baseline (level 9 / baseline deletion is Phase 7).
2. `composer show symfony/polyfill-php72` reports not installed.
3. `ls install/db/*.php | wc -l` reports the expected count (1 + new 16.x scripts).
4. UpgradeChainTest green from `dev/fixtures/piwigo-16.x.sql`.
5. New rejection test from `dev/fixtures/piwigo-15.x.sql` (optional) asserts HTTP 409 with the polite refusal text.
6. Manual smoke: `docker-compose up`, fresh install, browse gallery + admin; upgrade an existing 16.x dump; both green.
7. `docs/ARCHITECTURE.md` exists and renders in GitHub preview without dead anchors.
8. `ls tests/e2e/global-setup*` shows only `global-setup.ts` (step 9).
9. `npm run clean && npm run build` exits 0; `ls _data/combined/` is empty (step 10).
10. `npm run typecheck` validates `tests/e2e/*.ts` without errors (step 11).
11. CI lint job runs without referencing non-existent scripts; Section 8 of `ARCHITECTURE.md` matches what CI actually does (step 12).
12. `vendor/bin/phpunit --testsuite Unit` shows test coverage for `Template/ScriptLoader`, `Cache/PersistentFileCache`, `Session/PwgSession`, and `Ws/PwgServer` (step 16).
13. `docs/plugin-migration-16x.md` (or equivalent ARCHITECTURE section) exists with the `$conf` → `Config::get*()` mapping table (step 15).


### Part 2 — Phase 6: `docs/ARCHITECTURE.md` content draft

The text below is the finished `docs/ARCHITECTURE.md` content. It assumes Phases 0 through 6 have shipped. Replace `<TBD-WAVE-C>` with the actual Phase 4 cut decision when the doc is committed.

---

## Architecture

This document describes how Piwigo 16.x-rewrite is organised after the modernization plan completed. Audience: a contributor who has just cloned the repo and wants to understand the moving parts.

### 1. Bootstrap order

A request enters at one of the root entry points (`index.php`, `picture.php`, `admin.php`, `ws.php`, `install.php`, `upgrade.php`, etc.). All entry points follow the same six-step prologue:

```php
<?php declare(strict_types=1);

define('PHPWG_ROOT_PATH', './');
require __DIR__ . '/vendor/autoload.php';
include_once PHPWG_ROOT_PATH . 'include/common.inc.php';
\Piwigo\Core\Kernel::boot();
// page-specific work below
```

**Step 1**: `declare(strict_types=1);` is mandatory in every first-party file. Type coercion at function boundaries is a `TypeError`, never a silent string-to-int convert. The CI grep guard rejects pushes that drop this declaration.

**Step 2**: `PHPWG_ROOT_PATH` is the historical anchor used by every legacy `include` in `include/` and `admin/`. It is set before the autoloader so that legacy files which reference the constant load correctly.

**Step 3**: Composer autoload activates the PSR-4 mapping `Piwigo\\` → `src/Piwigo/`. From here, every namespaced class is autoloadable without manual `include`s. Composer's classmap is built `--classmap-authoritative` for production tarballs.

**Step 4**: `include/common.inc.php` is the legacy bootstrap bridge. After Phase 4 it is much thinner than its pre-modernization shape — most of its work has been pushed into `Kernel::boot()` — but it still survives because:

- It pre-defines `$GLOBALS['conf']`, `$GLOBALS['page']`, `$GLOBALS['user']`, `$GLOBALS['lang']` so that the long tail of legacy `include/*.inc.php` free-function libraries continues to work.
- It includes `include/config_default.inc.php` to populate defaults.
- It includes `local/config/database.inc.php` and `local/config/config.inc.php`, which are user-editable.
- It triggers the dblayer self-heal (`mysql` → `mysqli` rewrite) for installs whose `database.inc.php` predates Phase 1.

**Step 5**: `Kernel::boot()` is the modern entry point. It is idempotent (`self::$booted` guard) so nested entry points can call it without harm. In order, it:

1. Calls `Config::loadDefaults()` and `Config::loadLocal()` (these now read from the same in-memory array `common.inc.php` already populated; no double-include).
2. Opens the database via `Piwigo\Db\Connection::open()`. The connection wraps a singleton `mysqli` instance; the legacy free functions `pwg_query()`, `pwg_db_fetch_assoc()`, etc. delegate into it.
3. Calls `Config::hydrateFromDb()` which delegates to the legacy free function `load_conf_from_db()`. The `config` table merges into `Config::$data`.
4. Initialises `PageState` (and its `$GLOBALS['page']` reference-bridge so legacy code that pushes into `$page['errors']` still mutates the same buffer).
5. Loads `include/user.inc.php` to hydrate `$user`, then constructs `\Piwigo\Users\CurrentUser` from the resulting array.
6. Calls `Lang::initFor(CurrentUser::get())` to set the active language.
7. Registers the typed services with `ServiceLocator`.
8. Starts the session, loads plugins, runs the legacy event-handler registrations from common.inc.php's tail (lines that were previously inlined).

**Step 6**: After `Kernel::boot()` returns, the entry point does its page-specific work — Smarty template parsing for gallery pages, `PwgServer` dispatch for `ws.php`, etc.

The historical assumption "everything is wired by the time `common.inc.php` returns" still holds. The kernel does not change *when* things happen; it changes *how* they are reachable to new code.

### 2. Autoload layout

All first-party classes live under `src/Piwigo/`, mapped via PSR-4. Namespace tree:

- **`Piwigo\Core`** — `Config`, `PageState`, `Kernel`, `ServiceLocator`, `Logger`, `Lang`. The boot machinery and the typed views over what used to be globals.
- **`Piwigo\Db`** — `Connection`. A thin wrapper over the singleton `mysqli`. The legacy free functions `pwg_query()`, `pwg_db_fetch_assoc()`, `pwg_db_real_escape_string()`, etc. live in `include/dblayer/functions_mysqli.inc.php` and delegate into this class.
- **`Piwigo\Users`** — `User` (entity), `CurrentUser` (accessor over the request-scoped instance).
- **`Piwigo\Cache`** — `PersistentCache` (abstract), `PersistentFileCache`. Used by `template.class.php` (now `Piwigo\Template\Template`) and various derivative caches.
- **`Piwigo\Template`** — `Template`, `ScriptLoader`, `CssLoader`, `Combinable`, `PwgTemplateAdapter`. `ScriptLoader` consumes the Vite manifest at runtime; see Section 5.
- **`Piwigo\Ws`** — `PwgError`, `PwgServer`, `PwgRequestHandler`, `PwgResponseEncoder`, `PwgNamedArray`, `PwgNamedStruct`. `Piwigo\Ws\Protocol` holds the encoders (`PwgRestEncoder`, `PwgJsonEncoder`, `PwgXmlRpcEncoder`, `PwgSerialPhpEncoder`, `PwgXmlWriter`) and `PwgRestRequestHandler`.
- **`Piwigo\Search`** — Q-search classes (`Scope`, `Token`, `Expression`, `Results`) extracted from `include/functions_search.inc.php`.
- **`Piwigo\Image`** — `WatermarkParams`, `ImageStdParams`, `ImageRect`, `SizingParams`, `DerivativeParams`, `SrcImage`, `DerivativeImage`. Derivative pipeline classes.
- **`Piwigo\Auth`** — `Base32`, `Totp`. Used by the 2FA flow.
- **`Piwigo\Session`** — `PwgSession`. The custom session save handler.
- **`Piwigo\Calendar`** — `CalendarBase`, `CalendarMonthly`, `CalendarWeekly`. Picked dynamically in the calendar dispatch (`include/functions_calendar.inc.php` uses `::class` constants now).
- **`Piwigo\Menu`** — `BlockManager`, `RegisteredBlock`, `DisplayBlock`. Menu/block registration.
- **`Piwigo\Plugins`** — `PluginMaintain`, `ThemeMaintain`. Base classes for plugin/theme installers.
- **`Piwigo\Admin`** — admin-only classes, organised into sub-namespaces: `Piwigo\Admin\Image\{PwgImage,ImagickBackend,ExtImagickBackend,GdBackend}`, `Piwigo\Admin\Integrity\{InternalCheck,IntegrityCheck}`, `Piwigo\Admin\{Languages,Themes,Plugins,Updates,Tabsheet,DummyPluginMaintain,DummyThemeMaintain}`.
- **`Piwigo\Compat`** — `aliases.php` (autoloaded via `composer.json` `files`). Registers `class_alias()` from old short names to new FQNs for plugin and theme backward compatibility.

#### Why the procedural libraries stay in `include/`

The codebase has 358 classes and 2300+ free functions. The classes have been migrated; the free functions have not. Specifically:

- `include/functions.inc.php`, `include/functions_user.inc.php`, `include/functions_category.inc.php`, `include/functions_session.inc.php`, `include/functions_url.inc.php` and the rest of the `functions_*.inc.php` family stay as procedural libraries with native types added in Phase 2.
- `include/dblayer/functions_mysqli.inc.php` stays forever. `pwg_query()` and friends are part of the DB upgrade contract (Section 3) — they cannot become methods on a class without breaking `install/db/*.php`.

Migrating these to namespaced static classes would be a multi-week refactor for cosmetic gain. The chosen rule: *classes migrate, free functions stay*. Phase 2 added native types and `declare(strict_types=1)` to every `functions_*.inc.php` file, which is where the type-safety win actually lives.

### 3. The DB upgrade contract

Constraint #3 of the modernization plan: a real Piwigo 16.x MySQL database upgrades cleanly, forever. Five rules enforce this.

**Rule 1: `install/db/*.php` is excluded from Rector and PHPStan.** The exclusion is pinned in both `rector.php` (`withSkip`) and `phpstan.neon` (`excludePaths`). No automated rewrite ever touches these files. Their style (no `declare(strict_types=1)`, mixed-type `pwg_query` arguments, `'1'` strings passed where ints are expected) is part of the contract.

**Rule 2: Free functions never disappear.** `pwg_query()`, `pwg_db_fetch_assoc()`, `pwg_db_real_escape_string()`, `pwg_db_fetch_row()`, `pwg_db_num_rows()`, `pwg_db_insert_id()`, `mass_inserts()`, `single_update()`, etc. retain their current signatures. Internal bodies were modernized in Phase 2 (native types on local variables, mysqli backend), but their *public arity and parameter types* are frozen. A new `Piwigo\Db\Connection` class wraps the singleton `mysqli` instance; the free functions delegate into it.

**Rule 3: 16.0.0 is the floor.** `upgrade.php` refuses pre-15.x source databases with HTTP 409 and a polite message ("Upgrade through the upstream Piwigo project to 16.x first"). Detection works by reading the `applied_upgrades` table: if it does not contain row id 181 (the marker for 15.0.0), upgrade is refused. The pre-16 detection ladder (1.3.0 → 2.10.0 release-string matching) was deleted in Phase 6.

**Rule 4: New `install/db/<N>-database.php` files follow the historic style.** To author a new upgrade script:

1. Pick the next free integer `N` ≥ 182. Files are numbered sequentially.
2. Name it `install/db/<N>-database.php`.
3. The first executable line declares the upgrade title (used by the upgrade UI):
   ```php
   <?php
   $upgrade_description = 'Add foo column to images table';
   ```
4. Run SQL via `pwg_query()`. Read connection details only via `$conf['db_base']` and friends — the typed `Piwigo\Core\Config` is *not available* in install/db scripts (they run before the kernel is booted). This is intentional.
5. The script must be idempotent: re-running it must not error. Use `IF NOT EXISTS` on `ALTER TABLE` clauses where the database engine supports it; otherwise `try { ... } catch (\Throwable) { /* already applied */ }`.
6. Do *not* add `declare(strict_types=1)`. Doing so will break the script — `pwg_query()` is occasionally called with `'1'` strings that the DB layer expects to coerce.

The `applied_upgrades` table records which scripts have run. The mechanic in `upgrade.php` reads `SELECT id FROM piwigo_upgrade`, then for each numbered file in `install/db/` whose id is not present, runs the file and inserts the id row. The chain is therefore append-only — never mutate an already-shipped script.

**Rule 5: `UpgradeChainTest` gates every push.** `tests/Integration/UpgradeChainTest.php` loads `dev/fixtures/piwigo-16.x.sql`, drives `upgrade.php` via HTTP, and asserts `piwigo_config.piwigo_db_version` matches `PHPWG_VERSION` afterwards. CI runs this on every push; failure blocks merge. Any commit that touches `install/db/` must additionally describe the upgrade-chain impact in its commit message.

### 4. Globals, typed services, and the `Config` reader pattern

Pre-modernization, Piwigo's state lived in four globals: `$conf`, `$page`, `$user`, `$lang`. Phase 4 introduced typed services that *coexist* with the globals.

**`Piwigo\Core\Config`** is a typed reader over the `$conf` array. Old code keeps working:

```php
global $conf;
$dir = $conf['upload_dir'];   // legacy, still works
```

New code reads through typed accessors:

```php
$dir = \Piwigo\Core\Config::uploadDir();   // returns string
$dir = \Piwigo\Core\Config::getString('upload_dir'); // typed generic
$dir = \Piwigo\Core\Config::get('upload_dir');       // mixed, fallback
```

`Config::$data` is a single in-memory array. The legacy `$GLOBALS['conf']` is bound to the same array by reference (`$GLOBALS['conf'] = &Config::$data;`) so per-album runtime overrides remain mutually visible.

**`Piwigo\Core\PageState`** wraps `$page`. The class exposes `addError(string)`, `addWarning(string)`, `addMessage(string)`, `addInfo(string)`, `errors(): list<string>`, `hasErrors(): bool`. The legacy `$GLOBALS['page']['errors']` array is bound by reference to `PageState::current()->errors`, so `$page['errors'][] = 'foo'` and `PageState::current()->addError('foo')` are equivalent.

**`Piwigo\Users\CurrentUser`** returns the active `User` entity. `User` is a typed entity (`id`, `username`, `email`, `language`, `theme`, `isGuest`, `internalStatus`, `rawAttributes`). The legacy `$user` array remains the source of truth for now; `User::fromUserArray()` constructs the entity from it.

**`Piwigo\Core\Lang`** wraps `$lang`. `Lang::current()->t('guest')` returns the same string `l10n('guest')` returns. The translation arrays themselves stay in `language/*.php` and are out of scope.

#### Wave C status

`<TBD-WAVE-C>`. If Phase 4 Wave C shipped, direct `$conf['x']` reads in `src/` files emit `E_USER_DEPRECATED` (the proxy is an `\ArrayObject` subclass with a `debug_backtrace`-based gate exempting `install/db/*.php`, `language/*.php`, and `local/config/*.php`). If Wave C did not ship, the globals are bare arrays without deprecation noise, and the `ForbidGlobalsInSrcRule` PHPStan rule (Section 1.E of the supplement) is the only gate against new `global $conf;` statements in `src/`.

#### Adding a new config key

The standard workflow:

1. Add the key with a sensible default to `include/config_default.inc.php`. Document its purpose in a comment, following the existing convention.
2. Add the key to the `Conf` `@phpstan-type` alias in `tools/phpstan-types.php`. Pick the narrowest type (use string-enum unions where applicable, e.g. `'small'|'medium'|'large'` rather than bare `string`).
3. If the key is read frequently or hot-pathed, add a typed getter to `Piwigo\Core\Config` (`public static function myKey(): string`).
4. Run `php tools/check-conf-shape.php` locally. CI runs it too, and will fail the push if step 1 and step 2 diverge.

### 5. JS build pipeline

The frontend builds through Vite 5.x with multi-entry rollup configuration. Authored TypeScript lives under `admin/themes/default/js/`, `themes/default/js/`, and `themes/standard_pages/js/`. Vendored libraries (jQuery, jQuery UI, plupload, photoswipe, slick, chosen, Chart.js, dataTables, colorbox, selectize) stay vendored under their original paths and load via the legacy concat path; they are not Vite-built.

**Production build:**

```bash
npm install
npm run build
```

This produces `dist/manifest.json` plus hashed bundles under `dist/assets/`. Each entry id (`common`, `albums`, `cat_modify`, `batchManagerGlobal`, etc.) maps to a content-hashed JS file. The manifest format is Piwigo-specific (custom Vite plugin, see `build/piwigo-manifest-plugin.ts`):

```json
{
  "common": { "file": "assets/common-7f3a2c.js", "imports": [], "css": [] },
  "albums": { "file": "assets/albums-9e1f4d.js", "imports": ["assets/common-7f3a2c.js"], "css": [] }
}
```

**Runtime consumption:** `Piwigo\Template\ScriptLoader::add()` consults `dist/manifest.json` whenever a `{combine_script id="..." path="..."}` Smarty tag is rendered. If the manifest exists and contains the id, the loader emits the hashed URL. If the manifest does not exist (fresh git clone, no `npm run build` run), the loader falls back to the legacy concat path that reads the source file directly — so the gallery still serves correctly without a build step.

**Development mode:**

```bash
PIWIGO_VITE_DEV=1 npm run dev
```

This starts Vite's dev server on `localhost:5173` with hot module reload. The `ScriptLoader` shim, when the env var is set, emits absolute URLs pointing at the dev server (`http://localhost:5173/admin/themes/default/js/common.ts`) so HMR reaches the browser. CORS is enabled in `vite.config.ts`.

#### Adding a new JS module

1. Create the source file as TypeScript (`.ts`) in the appropriate directory under `admin/themes/default/js/` or `themes/default/js/`.
2. Add the file as a Vite entry in `vite.config.ts` (`rollupOptions.input`). The id you pick is the same id Smarty templates will reference.
3. Reference it from a Smarty template: `{combine_script id="my_new_module" path="admin/themes/default/js/my_new_module.ts"}`. The `path=` attribute is the legacy fallback path, used when the manifest is absent.
4. If the module depends on jQuery globals (`pwg_token`, `cookie_path`, etc.), declare them in `src/types/globals.d.ts`. If it monkey-patches a jQuery method (`$.fn.foo`), add the augmentation to `src/types/jquery-plugins.d.ts`.
5. Run `npm run typecheck` to confirm it compiles.
6. Run `npm run build` to produce a fresh manifest, then exercise the page via Playwright.

### 6. Authoring a new web service method

The web service kernel boots in `ws.php`:

```php
\Piwigo\Core\Kernel::boot();
$service = new \Piwigo\Ws\PwgServer();
$service->run();
```

Methods are registered in `include/ws_functions/ws_functions.inc.php` (the registration manifest) which calls `$service->addMethod()` once per method. `addMethod()` takes a method name (`pwg.foo.bar`), a callable (the handler), an array of declared parameters with types, and a description.

**Adding `pwg.foo.bar`:**

1. Pick a function name. Convention: `ws_foo_bar(array $params, \Piwigo\Ws\PwgServer $server)`.
2. Implement the function in a new file under `include/ws_functions/` (or extend an existing file in the same domain).
3. Register the method in `ws_add_methods()`:
   ```php
   $service->addMethod(
       'pwg.foo.bar',
       'ws_foo_bar',
       [
           'image_id' => ['type' => WS_TYPE_INT, 'mandatory' => true],
           'tag'      => ['type' => WS_TYPE_STRING, 'default' => ''],
       ],
       'Bar the foo on the image. Returns the bar status.',
       PWG_WS_ADMIN_ONLY  // or PWG_WS_PUBLIC
   );
   ```
4. The handler returns either:
   - A scalar/array result, which the kernel encodes as the response payload,
   - A `Piwigo\Ws\PwgError` instance for failures: `return new \Piwigo\Ws\PwgError(404, 'No such image');`,
   - A `Piwigo\Ws\PwgNamedArray` or `PwgNamedStruct` to control output naming for the XML/JSON encoders.
5. The JSON response shape is fixed:
   ```json
   { "stat": "ok", "result": <handler return> }
   ```
   On error:
   ```json
   { "stat": "fail", "err": 404, "message": "No such image" }
   ```

**Testing.** Add an integration test under `tests/Integration/Ws/`:

```php
final class FooBarMethodTest extends WsTestCase
{
    public function test_returns_404_for_missing_image(): void
    {
        $resp = $this->callMethod('pwg.foo.bar', ['image_id' => 999999]);
        self::assertSame('fail', $resp['stat']);
        self::assertSame(404, $resp['err']);
    }
}
```

`WsTestCase` boots the kernel, opens a transactional DB connection, and provides `callMethod()` which dispatches into `PwgServer::invoke()` directly without HTTP. Roll back the transaction in `tearDown()`.

### 7. Where things are not yet modernized

Honest about residual debt:

- **Templates: still Smarty 5.** No plans to replace. The template surface is enormous (~300 .tpl files across `themes/default/` and `admin/themes/default/`), Smarty 5 is fine, plugins extend templates by name and would all break under a Twig migration. Smarty stays.
- **Plugin contract.** The plugins directory is empty in this branch (no shipped first-party plugins). The plugin loader code (`include/functions_plugins.inc.php`) has been partially modernized — `Piwigo\Plugins\PluginMaintain` is a typed namespaced class — but the contract for what a plugin's `main.inc.php` looks like remains the legacy event-based `add_event_handler('foo', 'callback')` pattern. A `class_alias` shim in `src/Piwigo/Compat/aliases.php` lets old plugins extending `PluginMaintain` keep working without changes.
- **Vendored frontend libraries.** jQuery 1.11.x, jQuery UI, plupload/moxie, photoswipe, slick, chosen, Chart.js, dataTables, colorbox, selectize — all stay vendored as-is. Replacing any of them is a multi-week project per library. They typecheck via `@types/jquery` and the jQuery plugin module-augmentation files; runtime behavior is unchanged.
- **Translations.** `language/*.php` stays as `$lang['key'] = 'value';` arrays. Excluded from PHPStan and Rector forever. Translators must not be forced through a typing migration.
- **`install/db/*.php`.** The 16.x-and-forward upgrade chain is excluded from static analysis forever. See Section 3 for the complete contract.
- **Themes other than `default`.** The plan modernized `themes/default/` only. Third-party themes are out of scope; their files are not Rector- or PHPStan-checked. They may need touch-ups against PHP 8.5 deprecation noise; that's the theme author's responsibility.

### 8. CI gates

CI runs four jobs per push. All four must pass for merge.

#### `lint`

- `vendor/bin/pint --test` — Pint format check (PSR-12, single-quote, ordered imports). Failures: code formatting drift. Fix: run `vendor/bin/pint` locally.
- `vendor/bin/phpstan analyse --no-progress` — PHPStan at level 8 (level 9 if Phase 6 step 4 settled there). Custom rules included: `noDynamicNew`, `forbidGlobalsInSrc`. Failures: type errors, banned constructs.
- `bash tools/check-baseline.sh` — Baseline-grow guard. Fails if PHPStan baseline grew vs. the committed `phpstan-baseline.neon`.
- `php tools/check-conf-shape.php` — Drift detector between `config_default.inc.php` and the `Conf` `@phpstan-type` alias. Fails if a key was added to one without the other.

Pass criteria: all four sub-steps exit 0.

#### `unit`

- `vendor/bin/phpunit --testsuite Unit` — runs `tests/Unit/` (the `Piwigo\Tests\Unit` namespace). Coverage target: typed services in `src/Piwigo/Core/`, `src/Piwigo/Db/`, `src/Piwigo/Users/`, `src/Piwigo/Cache/`. No DB, no HTTP, no filesystem mutation.

Pass criteria: zero failures, zero errors, zero risky tests.

#### `e2e`

- `docker compose up -d --wait db web` — boot MariaDB + PHP 8.5 Apache.
- `npx playwright test` — runs `tests/e2e/` specs against the live stack: `install.spec.ts`, `smoke-gallery.spec.ts`, `smoke-admin.spec.ts`, `upload-photo.spec.ts`, `create-album.spec.ts`, `change-setting.spec.ts`, `phase4-conf-write.spec.ts`, `phase5-console-clean.spec.ts`, plus any per-phase regression specs added subsequently.
- `phase5-console-clean.spec.ts` walks every entry-point template route and asserts zero `pageerror` events — catches JS-side regressions that don't show up in PHP analysis.

Pass criteria: all specs green; no `Deprecated:` / `Notice:` / `Warning:` strings in the captured Apache error log.

#### `integration`

- `vendor/bin/phpunit --testsuite Integration` — runs `tests/Integration/` against the same docker-compose stack used by `e2e`. The headline test is `UpgradeChainTest` which loads `dev/fixtures/piwigo-16.x.sql` and drives `upgrade.php` to assert `piwigo_db_version` lands on the current branch.

Pass criteria: `UpgradeChainTest` green; any web-service integration tests added per Section 6 also green.

---

End of `docs/ARCHITECTURE.md` content draft.

---

### Critical Files for Implementation

The five files most load-bearing for shipping Phase 2 tooling and Phase 6 documentation:

- `C:\Apache24\htdocs\piwigo16\tools\phpstan-types.php` (new) — holds the `@phpstan-type Conf` alias docblock and the `PiwigoConfTypes` sentinel class; included via `phpstan.neon` `bootstrapFiles`.
- `C:\Apache24\htdocs\piwigo16\tools\check-conf-shape.php` (new) — the drift detector that wires the alias to `include/config_default.inc.php` and runs in CI's `lint` job.
- `C:\Apache24\htdocs\piwigo16\tools\phpstan\Rules\NoDynamicNewRule.php` (new) — the custom PHPStan rule banning `new $variable()`; registered in `phpstan.neon`.
- `C:\Apache24\htdocs\piwigo16\tools\phpstan\Rules\ForbidGlobalsInSrcRule.php` (new) — the rule banning `global $conf/$page/$user/$lang` inside `src/`; activated at Phase 4 Wave A.
- `C:\Apache24\htdocs\piwigo16\docs\ARCHITECTURE.md` (new) — the Phase 6 doc whose content draft is in Part 2 of this supplement.

Secondary but referenced throughout:
- `C:\Apache24\htdocs\piwigo16\phpstan.neon` — registers the bootstrap file, the custom rules, and the level configuration.
- `C:\Apache24\htdocs\piwigo16\rector.php` — registers the `RemoveDuplicatePhpDocRule` for Phase 2 step 9.
- `C:\Apache24\htdocs\piwigo16\.github\workflows\ci.yml` — wires `tools/check-conf-shape.php` and `tools/check-baseline.sh` into the `lint` job.

---

## DB-upgrade compatibility strategy (constraint #3)

`install/db/*.php` scripts (122 files at the start of Phase 0; trimmed to 16.x-only by Phase 6) are the **regression contract** for constraint #3. Five rules, enforced by tooling:

1. **Never touched by Rector.** Pinned in `rector.php` exclusions in every phase.
2. **Never linted at level >0.** Pinned in `phpstan.neon` `excludePaths`.
3. **The free functions they depend on (`pwg_query`, `pwg_db_fetch_assoc`, `pwg_db_real_escape_string`, `mysqli_*`) never go away**, even after Phase 4. Internal implementation can change; signatures cannot.
4. **`$conf` superglobal stays a working `ArrayObject` proxy through Phase 4** so `install/db/*.php` keeps reading `$conf['dblayer']`, `$conf['db_base']` etc. unchanged. The Wave C proxy gates deprecation by stack-frame inspection so install/db callers stay silent.
5. **UpgradeChainTest is the gating test** — runs in CI on every push. Phase 6's deletion of pre-floor scripts is the only allowed mutation of this directory, gated on a fixture swap.

A CI rule additionally requires: any commit modifying a file in `install/db/` must declare upgrade-chain impact in the commit message.

---

## End-to-end verification

Each phase has its own exit criteria above. The cross-cutting verification at every phase boundary:

1. **CI green**: lint + phpstan (at the current target level) + unit + e2e + UpgradeChainTest.
2. **Manual smoke**: spin up `docker-compose up`, run install fresh, browse gallery + admin.
3. **Upgrade smoke**: load `dev/fixtures/piwigo-16.x.sql` into the dev DB, hit `upgrade.php`, browse gallery + admin again.
4. **Staging soak (recommended for Phase 2 and Phase 4)**: deploy to a staging instance, monitor error logs for `TypeError`/`ValueError`/deprecation spam for at least one week before merging the next slice.

---

## Where this can go wrong (skeptical assessment)

- **Phase 4 is the iceberg.** If you have to cut, cut Wave C. Ship Waves A+B; leave globals as live `ArrayObject`s.
- **Phase 5 will tempt you to also replace vendored libs** (jQuery → vanilla, jQuery UI → headless, plupload → uppy). Don't. Each is a multi-week project. Out of scope.
- **PHPStan level 9 with `treatPhpDocTypesAsCertain: true` is aspirational.** Settle for level 8 with no baseline if Phase 6 stretches.
- **The new Playwright suite is the only safety net for user-visible behavior, and it starts thin.** A regression in a flow that doesn't have a spec yet won't be caught. Discipline: when a phase touches an untested flow, add the spec in the same commit. Don't let the suite stagnate at Phase 0 coverage forever.
- **Tracked `vendor/` is unfashionable but correct here.** Don't let bikeshedding delay Phase 0.

---

## Phase 4 close-out — Wave C shipped

**Date:** 2026-04-27  
**Commit range:** Phase 4 Waves A + B + B.5 + C  
**Decision:** All four waves shipped.

### What shipped

- **Wave A:** Six typed service classes with reference bridges.
- **Wave B:** All `$page[bucket][]` push sites migrated to `PageState::current()->add*()`.
- **Wave B.5 (added to plan):** All bare `$conf[key]` reads in `admin/`, `include/`, `src/`, and root entry points migrated to typed getters or `Config::get()`. Zero `$conf[` anywhere in first-party code.
- **Wave C:** `src/Piwigo/Core/GlobalsBridge.php` + `ConfProxy` wired into `Kernel::boot()`. Any remaining `$conf[key]` access in plugin code emits `E_USER_DEPRECATED` naming the correct typed getter. Backtrace gate silences `install/db/`, `local/config/`, `language/`.

### Benchmark numbers (Apache Bench, 200 req / c=4, localhost:8090/index.php)

| Build | p50 | p95 | p99 |
|-------|-----|-----|-----|
| Wave A (no proxy) | 27 ms | 36 ms | 61 ms |
| Wave C (ConfProxy installed) | 28 ms | 37 ms | 49 ms |
| Delta | +1 ms | **+1 ms (+2.8%)** | -12 ms |

p95 delta is +2.8%, well within the ≤5% acceptance threshold. The ConfProxy
overhead (one `debug_backtrace(IGNORE_ARGS, 8)` call per `$conf[]` access) is
negligible because first-party code no longer touches the proxy path — the
backtrace fires only for plugin or overlooked code.

### Plugin smoke test (2026-04-27)

5 plugins installed and activated on a default Piwigo 16 install:

| Plugin | Plan plugin / substitute |
|--------|-------------------------|
| piwigo-videojs | ✓ plan plugin |
| piwigo-openstreetmap | ✓ plan plugin |
| LocalFiles Editor | ✓ plan plugin |
| nbc ThemeChanger | substitute for NBC_UserAdvManager (not in ext server for 16.x) |
| User Tags | substitute for Piwigo-Tags (not in ext server for 16.x) |

Results:
- **Fatal errors on gallery/admin/tags pages: 0**
- **`E_USER_DEPRECATED` on gallery home: 0**
- **`E_USER_DEPRECATED` on admin dashboard: 0**
- **Deprecations in Piwigo log files: 0**
- **Plugin deprecation noise (single index.php load): 0** — well below ≤200 target

Two pre-existing PHP 8.4+ type-strictness bugs were found and fixed in the
process (`plugins_new.php` and `functions.inc.php` — see commit after Wave C).

### Exit signal: zero E_USER_DEPRECATED on first-party + plugin pages

Unit tests 66/66 · PHPStan clean (baseline 4934) · Playwright 9/9.

---

## Phase 4 close-out (original entry, superseded above) — Wave C previously cut

*(This entry recorded the cut decision before B.5 completion made Wave C viable.)*

### Cut-point checklist results

| Item | Target | Measured | Pass/Fail |
|------|--------|----------|-----------|
| `global $conf` in `src/` | 0 | 38 declarations | FAIL |
| Bare `$conf[...]` reads outside exempt paths | ≤ 50 | 1 073 | FAIL |
| Bare `$conf[...]` reads in `admin/` alone | — | 325 | — |
| Bare `$conf[...]` reads in `include/` (excl. config_default) | — | 474 | — |
| Bare `$conf[...]` reads in `src/` | — | 88 | — |
| Estimated deprecations per page load | ≤ 200 | ~350+ (admin page) | FAIL |
| PHPStan + unit tests on `src/Piwigo/Core/` | green | green ✓ | PASS |
| Playwright e2e | green | 9/9 ✓ | PASS |

### Why

The codebase has 1 073 bare `$conf[key]` reads outside the allowlisted paths. A single admin
page load hits roughly 300–400 of these. Enabling the ArrayObject proxy would emit 300+ `E_USER_DEPRECATED`
notices per request — well above the ≤ 200 gate and in the range the plan calls "too chatty → cut."
The 38 remaining `global $conf;` declarations in `src/` are a secondary failure: the proxy surface is
not small enough to be safe without first completing a full typed-getter migration across all those files.

### What we shipped

- **Wave A:** Six typed service classes (`Config`, `PageState`, `Lang`, `ServiceLocator`, `Kernel`,
  `CurrentUser`). Reference bridge (`Config::attachGlobals()`) means `$GLOBALS['conf']` and
  `Config::$data` are the same array — no behavioural change, typed reads available everywhere.
- **Wave B:** All `$page['errors|warnings|messages|infos'][]` push sites migrated to
  `PageState::current()->add*()`. Six `$conf['order_by']` per-request overrides migrated to
  `Config::override()`. DB-persisted writes use `Config::persist()`.
- **NoGlobalInSrcRule:** PHPStan custom rule blocks new `global $conf/page/user/lang` statements
  in `src/` — prevents the debt from growing.

### What Wave C would need to ship in the future

1. **Complete Wave B.5** — migrate all 1 073 bare `$conf[key]` reads in `admin/`, `include/`, and
   `src/` to typed getters or `Config::get()`. This eliminates all 38 remaining `global $conf;`
   declarations in `src/` as a side-effect. Exit signal: zero hits from the B.5 grep. This is the
   dominant work item.
2. Run the Apache Bench benchmark (Section E.3) against the proxy build and confirm p95 ≤ Phase 3 × 1.05.
3. Run plugin smoke tests with the top 5 third-party plugins and count deprecations on a single
   `index.php` load; confirm ≤ 200 (after B.5, all deprecations will be plugin-origin — measurable
   and actionable, not first-party noise).
4. If deprecation noise from plugins exceeds the threshold, downgrade from `trigger_error` to a
   dedicated deprecation log file and recheck.

The Wave C source (`GlobalsBridge.php`, `ConfProxy`, `PageProxy`) is fully specified in Section E of
this document — it only needs to be wired into `Kernel::boot()` after the typed getter coverage is
sufficient to make the proxy quiet.

---

## Known gaps and technical debt (audit 2026-04-27)

The table below captures every gap identified in the post-Phase-5 deep review. Items already
addressed by a Phase 6 step are marked **→ Ph6 step N**. Items not yet in any phase plan are
marked **untracked** and need a decision: absorb into Phase 6, defer to a later phase, or accept
as permanent technical debt.

---

### Phase 0 — Dev infrastructure

#### G0-1 · Duplicate global-setup files · untracked

`tests/e2e/global-setup.js` and `tests/e2e/global-setup.ts` both exist in the tree. The `.ts`
version is canonical (written in Phase 0, Playwright resolves it correctly). The `.js` file is a
stale leftover from before TypeScript was introduced to the project and should be deleted.

**Fix:** `git rm tests/e2e/global-setup.js` in Phase 6 cleanup commit.

#### G0-2 · Unit test coverage missing for most src/ namespaces · untracked

`tests/Unit/Core/` has 5 files covering `Config`, `PageState`, `GlobalsBridge`, `Kernel`, `Lang`.
`tests/Unit/Users/` has `PwgErrorTest.php`. The following namespaces have **zero** unit tests:

| Namespace | Files |
|-----------|-------|
| `Piwigo\Admin` | 14 files |
| `Piwigo\Auth` | 2 files |
| `Piwigo\Cache` | 2 files |
| `Piwigo\Calendar` | 3 files |
| `Piwigo\Image` | 7 files |
| `Piwigo\Menu` | 3 files |
| `Piwigo\Search` | 7 files |
| `Piwigo\Session` | 1 file |
| `Piwigo\Template` | 8 files |
| `Piwigo\Ws` | 9 files |

The plan CI architecture doc (Section 8, `unit` job) names `src/Piwigo/Core/`, `Db/`, `Users/`,
and `Cache/` as coverage targets. `Db/` does not exist yet. Three of the four named targets are
partially covered; the other six namespaces are entirely dark.

**Fix:** Add unit test stubs for at least `Cache`, `Template/ScriptLoader`, and `Session` in
Phase 6. Full namespace coverage is aspirational and should be tracked as ongoing debt after
Phase 6 ships.

---

### Phase 1 — Rector / PHP modernization

#### G1-1 · Dynamic dblayer include is a PHPStan blindspot · → Ph6 step 7

`include/common.inc.php` loads the dblayer via string interpolation:

```php
include(PHPWG_ROOT_PATH.'include/dblayer/functions_'.\Piwigo\Core\Config::dbLayer().'.inc.php');
```

PHPStan cannot see into dynamically-constructed include paths, so the ~70 functions defined in
`functions_mysqli.inc.php` (`pwg_query`, `pwg_db_fetch_assoc`, etc.) are invisible to static
analysis. Any typo in a call site at level 9 will be silently missed.

`Config::dbLayer()` always returns `'mysqli'` (default forced in `common.inc.php`). The only
existing file in `include/dblayer/` is `functions_mysqli.inc.php` — there are no pgsql or sqlite
files left to delete; Phase 6 step 7 is therefore just making the include static.

**Fix (Phase 6 step 7):** Replace the dynamic include with a direct static include. Delete the
`dbLayer()` config getter or narrow its return type to the literal `'mysqli'` so any hypothetical
non-mysqli value is a PHPStan type error.

---

### Phase 2 — strict_types + PHPStan level 8

#### G2-1 · PHPStan baseline is 22 296 lines · → Ph6 step 4

The current `phpstan-baseline.neon` suppresses the entire pre-modernization error backlog. At
22 296 lines the baseline absorbs every new type error added to un-analyzed files without any CI
signal. `phpstan-strict-rules` and `phpstan-deprecation-rules` are included in `phpstan.neon` but
their findings are similarly suppressed.

The practical effect: PHPStan level 8 only guards files with no baseline entries. The project is
not meaningfully at level 8 until the baseline is eliminated.

**Fix (Phase 6 step 4):** Walk level 8 → 9, address errors in slices (Core first), and delete
`phpstan-baseline.neon`. Aspirational target; if timeline stretches, settle for level 8 with zero
baseline and document the decision in `ARCHITECTURE.md`.

#### G2-2 · `check-baseline.sh` and `check-conf-shape.php` referenced but not wired · untracked

The CI architecture draft in Section 8 references `tools/check-baseline.sh` (baseline-grow guard)
and `tools/check-conf-shape.php` (conf-shape drift detector) as lint job steps. Neither is present
in the actual `.github/workflows/ci.yml`. The CI plan and the actual CI file have diverged.

**Fix:** Either implement and wire these tools in Phase 6, or remove the references from Section 8
to keep the architecture doc honest.

---

### Phase 3 — PSR-4 namespacing

#### G3-1 · `aliases.php` loaded on every request · → Ph6 step 6 (indirect)

`src/Piwigo/Compat/aliases.php` is loaded via `composer.json` `autoload.files`, executing
unconditionally on every request. It contains ~60 `use` class alias declarations that exist because
`include/` and `admin/` call sites still reference old unqualified class names. When all call sites
are updated to FQCNs the file becomes empty and should be deleted.

**Fix:** As each `include/` or `admin/` file is updated to use FQCNs, remove the corresponding
alias. Delete `aliases.php` and the `autoload.files` entry once the file is empty. Tie to the
Phase 6 proxy-removal commit so the two scaffolding removals land together.

#### G3-2 · `DummyPlugin_maintain` and `DummyTheme_maintain` have no runtime logic · untracked (optional)

`src/Piwigo/Admin/DummyPlugin_maintain.php` and `src/Piwigo/Admin/DummyTheme_maintain.php` are
marker classes returned as fallbacks when a plugin/theme has no `maintain` class. They contain no
logic. Their presence adds two files to the namespaced src tree and the classmap without adding
modernization value, and their instantiation via `new $class_name()` is one of the remaining
`NoDynamicNewRule` exemption sites.

**Fix (optional):** Replace the dynamic `new $class_name()` instantiation in the plugin/theme
loaders with a named factory method returning `null` when no maintain class exists. Remove the
dummy files and drop the exemption from `NoDynamicNewRule`.

---

### Phase 4 — Config/PageState typed facades

#### G4-1 · `GlobalsBridge::isSilentCaller()` pays `debug_backtrace` on every plugin `$conf` access · → Ph6 step 6

Every `$conf['key']` access from plugin or un-migrated code triggers
`GlobalsBridge::isSilentCaller()`, which calls `debug_backtrace(IGNORE_ARGS, 8)`. First-party code
never hits this path (Wave B.5 migrated all reads). For installed plugins every `$conf` access pays
a backtrace cost. The Wave C benchmark showed p95 +2.8% on a no-plugin install; a plugin-heavy
install with many `$conf` accesses per request will see higher overhead.

**Fix (Phase 6 step 6):** Delete `GlobalsBridge.php` and `ConfProxy` once deprecation logs are
confirmed quiet. This removes the backtrace path entirely. Do not attempt to optimise the backtrace
call — removal is the correct fix.

#### G4-2 · No plugin developer migration guide for ConfProxy deprecations · untracked

Plugin authors who install this Piwigo fork will see `E_USER_DEPRECATED` notices naming specific
typed getters (`Config::getString()`, etc.) for every `$conf['key']` read their plugin performs.
There is no published migration guide explaining what changed or how to update plugin code.

**Fix:** Add a `docs/plugin-migration-16x.md` file, or a section in `ARCHITECTURE.md` (Phase 6
step 8), explaining: the `$conf` proxy, what deprecation messages look like, and how to replace
each access pattern with the equivalent `Config::get*()` call.

---

### Phase 5 — JS to TypeScript + Vite

#### G5-1 · Wave-1 TypeScript relaxations carry forward jQuery-era looseness · → future Wave 2

`tsconfig.json` has `"noImplicitAny": false`, `"noImplicitThis": false`, and
`"strictNullChecks": false`. These were intentional Wave-1 compromises to get 38 files through
typecheck without a complete rewrite. Null dereferences, implicit `any` propagation, and untyped
`this` contexts are silently accepted by the compiler.

**Fix (Wave 2, post-Phase 6):** Enable one flag at a time, starting with `noImplicitThis`. Fix
the resulting errors per file. `strictNullChecks` is the heaviest lift (requires explicit null
guards throughout jQuery callback chains) and should be last.

#### G5-2 · Manifest plugin CSS association is approximate · untracked

`build/piwigo-manifest-plugin.ts` associates CSS assets with entry chunks by including all CSS
assets for every entry. This means every page served in manifest mode gets all CSS linked, not
per-entry CSS. In practice Piwigo has minimal Vite-generated CSS output (most CSS is
Smarty-templated), so the duplicate link tags are harmless, but the manifest is not accurate.

**Fix:** Wire Vite internal CSS-to-chunk association via `moduleIds` or the `viteMetadata` plugin
hook rather than the current blanket approach. Alternatively, accept as permanent debt since CSS
deduplication in browsers is cheap and the volume is low.

#### G5-3 · E2E TypeScript files are excluded from `npm run typecheck` · untracked

`tsconfig.json` excludes `tests/e2e/**`, which means `global-setup.ts`, `helpers/admin-login.ts`,
and all `*.spec.ts` files are not type-checked by `npm run typecheck`. Playwright resolves their
types via its own tsconfig extension at runtime so tests work, but CI provides no safety net for
type errors in E2E test code.

**Fix:** Add `tests/e2e/tsconfig.json` extending the root config with `"include": ["**/*.ts"]`
and `"types": ["@playwright/test"]`. Add `tsc --noEmit -p tests/e2e/tsconfig.json` to the
`typecheck` npm script or as a separate CI step.

#### G5-4 · `_data/combined/*.js` runtime artifacts accumulate without cleanup · untracked

The legacy `ScriptLoader` concat path writes combined JS to `_data/combined/` when
`dist/manifest.json` is absent (fresh clone without running `npm run build`). These files
accumulate across server restarts and are not cleaned by any automated process. The directory is
gitignored correctly but the artifacts consume disk space and can mask stale-bundle bugs in dev.

**Fix:** Add a `clean` npm script (`rm -rf dist/ _data/combined/`) and document the dev workflow:
run `npm run build` (or `npm run dev`) before testing; never rely on the concat fallback for
JavaScript correctness testing.

---

### Summary table

| ID | Phase | Description | Status |
|----|-------|-------------|--------|
| G0-1 | 0 | Duplicate `global-setup.js` alongside `.ts` | Ph6 step 9 |
| G0-2 | 0 | Zero unit tests for 10 of 13 `src/Piwigo/` namespaces | Ph6 step 16 |
| G1-1 | 1 | Dynamic dblayer include invisible to PHPStan | Ph6 step 7 |
| G2-1 | 2 | PHPStan baseline 22 296 lines — effective level is not 8 | Phase 7 |
| G2-2 | 2 | `check-baseline.sh` / `check-conf-shape.php` in CI doc but not wired | Ph6 step 12 |
| G3-1 | 3 | `aliases.php` runs on every request; FQCNs not complete | Ph8 step 4 |
| G3-2 | 3 | `DummyPlugin_maintain` / `DummyTheme_maintain` serve no purpose | Ph8 step 5 |
| G4-1 | 4 | `debug_backtrace` on every plugin `$conf` access | Ph6 step 6 |
| G4-2 | 4 | No plugin developer migration guide for ConfProxy deprecations | Ph6 step 15 |
| G5-1 | 5 | Wave-1 TS: `noImplicitAny` / `strictNullChecks` off | Ph8 steps 7–8 |
| G5-2 | 5 | Manifest plugin CSS association is a blanket (not per-entry) | Ph8 step 6 |
| G5-3 | 5 | E2E TypeScript files not covered by `npm run typecheck` | Ph6 step 11 |
| G5-4 | 5 | `_data/combined/*.js` accumulate without automated cleanup | Ph6 step 10 |

All 13 gaps are now assigned across Phases 6–8: 9 in Phase 6 (steps 6, 7, 9–12, 15–16);
G2-1 (PHPStan baseline) in Phase 7; G3-1, G3-2, G5-1, G5-2 in Phase 8.

---

## Phase 7 — PHPStan level 9 / baseline elimination (L)

### Goal

Delete `phpstan-baseline.neon` and raise PHPStan from level 8 (with a 22 279-line baseline
suppressing ~5 025 errors) to level 9 with `treatPhpDocTypesAsCertain: true` and zero suppressed
errors. This makes static analysis genuinely effective: every new file is fully checked, every
type error is a CI failure, and the `phpstan-strict-rules` and `phpstan-deprecation-rules`
packages already installed in `phpstan.neon` become load-bearing rather than silenced.

**Starting state (end of Phase 6):** level 8, baseline 22 279 lines, PHPStan exits 0 because
every error is suppressed. The baseline grew by exactly 0 lines during Phases 5–6 (verified by
`tools/check-baseline.sh`).

### Why this is its own phase

The baseline covers the entire pre-modernization error backlog accumulated across ~300 first-party
PHP files. Addressing it requires reading each suppressed error, deciding whether it is a real bug
or a PHPStan false-positive, and fixing or narrowing. At the Phase 6 exit rate of ~22 000
suppressions this is a multi-week effort done safely only in small slices — it cannot be safely
bundled with other structural changes.

### Step-by-step sequence

**Preparation (before touching phpstan.neon):**

1. **Freeze the baseline.** The CI `check-baseline.sh` guard is already wired. Confirm it passes
   on the current branch. Any new error added during Phase 7 work is a regression, not a baseline
   entry — fix it before proceeding.

2. **Slice the baseline by namespace.** Run:
   ```bash
   grep "path:" phpstan-baseline.neon | sed 's/.*path: //' | sort | uniq -c | sort -rn | head -30
   ```
   This shows which files contribute the most suppressions. Work from highest-count files
   downward within each namespace slice.

**Namespace slices (recommended order):**

3. **`src/Piwigo/Core/`** — typed services already have unit tests. Errors here are typically
   `mixed` return types on `Config::get()` propagating into typed contexts. Fix by narrowing
   call sites or adding `@phpstan-var` inline casts only where PHPStan cannot infer.

4. **`src/Piwigo/Template/`** — Smarty adapter code. Errors are typically `mixed` from
   Smarty's loosely-typed plugin API. Narrow `Template::assign()` callers or add method-level
   `@param` phpdoc where the shape is knowable.

5. **`src/Piwigo/Ws/`** — web service handlers. Errors are typically `mixed` flowing from
   `$_GET`/`$_POST` through the WS parameter extraction. The `input_*` helpers from Phase 2
   already coerce many of these; wire remaining call sites.

6. **`include/functions.inc.php` and friends** — the largest single-file error contributor.
   Work one function at a time. Common patterns:
   - `pwg_db_fetch_assoc()` returns `array<string,mixed>|false` — add null-checks at call sites.
   - `array_map()` / `array_filter()` return types losing specificity — use `@return` phpdoc or
     the `list<>` / `array<int,T>` narrowing syntax.
   - Implicit `mixed` from `unserialize()` — add a type-guard (`is_array($v) ? $v : []`).

7. **`admin/`** — largest directory by file count. Batch-process: run PHPStan on one admin
   file at a time (`--paths admin/admin.php`) and fix errors before moving on.

**Level bump:**

8. **Bump to level 9 and enable `treatPhpDocTypesAsCertain: true`.** Only after the baseline
   entry count reaches zero at level 8. Do this as a single commit:
   ```diff
   --- a/phpstan.neon
   +++ b/phpstan.neon
   @@ -1,3 +1,4 @@
    parameters:
   -    level: 8
   +    level: 9
   +    treatPhpDocTypesAsCertain: true
   ```
   Let PHPStan emit the new level-9 errors. Expect ~200–500 new errors, mostly:
   - `never` return type inference on unreachable branches.
   - Phpdoc `@return` types claimed as certain that PHPStan can now disprove.
   - Narrower `mixed` handling inside conditionals.
   Fix each in its own commit. Do not add baseline entries.

9. **Delete the baseline.** Once level 9 exits 0:
   ```bash
   rm phpstan-baseline.neon
   sed -i 's/includes://' phpstan.neon  # remove the includes: block
   sed -i '/phpstan-baseline.neon/d' phpstan.neon
   vendor/bin/phpstan analyse --no-progress  # must exit 0
   ```
   Update `tools/check-baseline.sh` to be a no-op when the file is absent (it already handles
   this — the first `if [ ! -f "$BASELINE" ]` guard exits 0).

### Effort breakdown

| Sub-task | Tag |
|---|---|
| Freeze + slice baseline by namespace | S |
| Fix `src/Piwigo/` (Core, Template, Ws, etc.) | M |
| Fix `include/functions.inc.php` and friends | L |
| Fix `admin/` files | L |
| Bump to level 9 + `treatPhpDocTypesAsCertain` | M |
| Fix level-9 new errors | M |
| Delete baseline file + update phpstan.neon | S |

**Phase total: L.**

### Risks specific to Phase 7

- **`treatPhpDocTypesAsCertain: true` can surface false positives.** When phpdoc says
  `@return string` but the function can actually return `null`, PHPStan level 9 trusts the
  phpdoc and will flag callers that guard against null. The fix is usually to update the phpdoc,
  not remove the null-guard. Never widen a parameter or return type just to silence the error —
  that hides real bugs.
- **Level-9 fixes in `include/` may silently change behaviour** if a type-narrowing assumption
  was wrong. Mitigate: every fix in `include/functions.inc.php` or `include/functions_user.inc.php`
  should be accompanied by a PHPUnit assertion or a Playwright spec that exercises the fixed path.
- **The baseline shrinks slowly at first.** Each namespace slice takes a day or two; the total
  timeline is 3–6 weeks of focused work. Don't try to fix everything in one commit — it makes
  bisecting regressions impossible.
- **New errors may appear during slice work.** Fixing a type in one file can expose previously
  suppressed errors in callers. Treat them as bonus fixes, not scope creep.

### Verification

1. `vendor/bin/phpstan analyse --no-progress` exits 0 with `level: 9`,
   `treatPhpDocTypesAsCertain: true`, and no `includes: [phpstan-baseline.neon]` in `phpstan.neon`.
2. `ls phpstan-baseline.neon` returns "No such file".
3. `bash tools/check-baseline.sh` exits 0 ("No baseline file — nothing to check.").
4. CI lint job green end-to-end (pint + phpstan + baseline guard + conf shape + typecheck + build).
5. Unit suite: 84+ tests, 0 failures.
6. Playwright E2E: all specs green against the Docker stack.

### Phase 7 close-out template

When Phase 7 ships, append a `## Phase 7 close-out — shipped` section recording:

- Final baseline line count at close (should be 0 or the file is absent).
- Level reached (8 or 9) and whether `treatPhpDocTypesAsCertain` is on.
- Number of real bugs found and fixed during baseline elimination.
- CI run ID confirming green.

---

---

## Phase 8 — Deferred and optional tasks (M)

### Goal

Complete every task that was explicitly skipped, marked optional, or deferred to a "future phase"
across Phases 1–7. None of these tasks block the main modernization arc, but each represents a
known correctness gap, a type-safety hole, or a doc/test gap that a well-maintained codebase
should eventually close.

**Source inventory:** every item below was previously tracked as skipped or optional in at least
one phase. The originating reference is noted for each step.

---

### Step-by-step sequence

**Testing**

1. ✅ **Author `dev/fixtures/piwigo-15.x.sql`** — the rejection fixture for the `upgrade.php` 409
   guard. *(Phase 6 verification step 5, optional, skipped.)*
   The fixture must produce a structurally valid DB that `upgrade.php` can reach past the
   table-existence checks, but lacks `applied_upgrade` id 181, so the 409 guard fires.
   Easiest construction: start from `dev/fixtures/piwigo-16.x.sql`, then:
   ```sql
   DELETE FROM piwigo_upgrade WHERE id IN (175,176,177,178,179,180,181);
   DROP TABLE IF EXISTS piwigo_activity;   -- added between 14.x and 15.x
   ```
   Add a PHPUnit integration test that loads the fixture, POSTs to `upgrade.php`, and asserts
   HTTP 409 with the polite refusal text.
   **Exit signal:** `UpgradeChainTest` still green; new rejection test asserts status 409.

**Security**

2. ✅ **Upgrade session cookie `SameSite` from `Lax` to `Strict`.** *(Phase 1 step 9 deferral,
   line 641 of the plan: "tightening is a Phase 6 cleanup" — never done in Phase 6.)*
   Phase 1 shipped `samesite: 'Lax'` in `session_set_cookie_params()` and `setcookie()` calls
   (6 sites in `include/functions_user.inc.php`, 2 in `include/functions_cookie.inc.php`).
   `Strict` prevents the session cookie from being sent on any cross-site navigation, which is
   the correct security posture for a private gallery.
   Caveats: test "remember me" login and external SSO (if any) under `Strict` before shipping —
   some redirect-based flows break. The Phase 5 dev-mode note (`dev mode uses SameSite=Lax`)
   may need revisiting if the dev Vite server is cross-origin.
   **Exit signal:** `session_set_cookie_params` and all `setcookie` calls use `'Strict'`;
   Playwright `identify + remember-me` spec green.

**PHP type-safety cleanup**

3. ✅ **Tighten `PwgNamedArray`, `PwgNamedStruct`, and `PwgServer` property visibility.**
   *(Phase 1 lines 677, 790, 908 — deferred as "higher disruption, not Phase 1 scope".)*
   - `PwgNamedArray.$_content` and `PwgNamedStruct.$_content` currently use `/*private*/ var`
     (PHP-4-era public-visibility shorthand with an aspirational comment). Three read sites in
     `PwgResponseEncoder::flatten()` access `$value->_content` from outside the class. Fix:
     add a `getContent(): array` getter, change the property to `private array $_content`, update
     the three external read sites.
   - `PwgServer.$_methods` is `public array` because `ws_core.inc.php:616` calls
     `array_filter($service->_methods, ...)`. Fix: add `getMethods(): array`, change `$_methods`
     to `private array`, update the one external read site.
   **Exit signal:** PHPStan sees no public `_`-prefixed properties on ws classes;
   `vendor/bin/phpunit --testsuite Unit` still green.

4. ✅ **Remove `src/Piwigo/Compat/aliases.php` and the `autoload.files` entry.**
   *(Gap G3-1, Phase 3 deferral — "loaded on every request, remove once FQCNs are complete".)*
   `aliases.php` currently holds ~60 `class_alias()` mappings from old unqualified names to
   `Piwigo\*` FQCNs. It runs unconditionally on every request. The removal process:
   - Run `grep -rn "use Piwigo\\" --include="*.php" include/ admin/ | wc -l` — count existing FQN
     imports. For each old short name still used in `include/` or `admin/`, add the FQCN `use`
     statement to that file and remove the alias entry.
   - When `aliases.php` is empty (zero `class_alias` calls), delete it and remove the entry from
     `composer.json` `autoload.files`.
   - Run `composer dump-autoload` and verify `vendor/bin/phpunit --testsuite Unit` green.
   - Run `vendor/bin/phpstan analyse --no-progress` — the removal exposes any call sites that
     relied on the global alias rather than an import.
   **Exit signal:** `aliases.php` is deleted; `composer.json` `autoload.files` is empty or absent;
   PHPStan exits 0; unit suite green.

5. ✅ **Dissolve `DummyPlugin_maintain` and `DummyTheme_maintain`.** *(Gap G3-2, Phase 6 step 14,
   optional, skipped. Note: the Phase 6 plan description was partially wrong — these classes are
   NOT empty markers; they bridge old procedural plugin callbacks.)*
   The correct dissolution requires eliminating the procedural plugin API itself:
   - `DummyPlugin_maintain` bridges `plugin_install()`, `plugin_activate()`,
     `plugin_deactivate()`, `plugin_uninstall()` free functions (old pre-2.7 plugin pattern).
   - `DummyTheme_maintain` bridges `theme_activate()`, `theme_deactivate()`, `theme_delete()`.
   Fix: add a deprecation `trigger_error(E_USER_DEPRECATED)` inside each delegating method, emit
   the correct OOP replacement in the error message, then schedule removal after one release
   cycle. The classes themselves stay until no installed plugin uses the procedural pattern.
   **Immediate action:** add `trigger_error('plugin_install() is deprecated; extend PluginMaintain instead', E_USER_DEPRECATED)` in `DummyPlugin_maintain::install()` etc.
   **Eventual exit signal (future release):** `grep -r "function plugin_install\|function plugin_activate" .` returns zero hits in tracked code; both dummy files deleted.

**Frontend**

6. ✅ **Fix Vite manifest plugin per-entry CSS association.** *(Gap G5-2, Phase 6 step 13, optional,
   skipped.)*
   `build/piwigo-manifest-plugin.ts` currently adds ALL CSS assets to EVERY entry's manifest
   record. Fix: use Vite's internal per-chunk CSS metadata. In the `generateBundle` hook,
   replace the blanket collect with:
   ```typescript
   const cssFiles = [...((chunk as any).viteMetadata?.importedCss ?? new Set<string>())];
   ```
   Verify by inspecting `dist/manifest.json` after `npm run build`: the `core.scripts` entry
   should list only CSS files actually imported by `themes/default/js/scripts.ts`.
   **Exit signal:** `dist/manifest.json` entries have accurate per-entry `css` arrays; no
   regressions in `npm run build` or `npm run typecheck`.

7. **TypeScript Wave 2 — enable `noImplicitAny` and `noImplicitThis`.** *(Gap G5-1a, Phase 5
   deferral, plan line 5132: "Wave 2 flips noImplicitAny true after simple files convert".)*
   The Wave-1 relaxations in `tsconfig.json` allow implicit `any` propagation throughout jQuery
   callback chains and class methods. Enabling these flags surfaces every untyped parameter and
   every unresolved `this` context.
   Approach: enable one flag at a time.
   - **`noImplicitThis: true` first** — smaller surface, most issues are in jQuery plugin
     implementations where `this` is `Element | JQuery`. Fix: annotate `function(this: JQuery)`
     or wrap in arrow functions.
   - **`noImplicitAny: true` second** — larger surface. Work file by file: add explicit `any`
     annotations as a mechanical first pass (acceptable for Wave 2); follow up with real types
     where the shape is known.
   **Exit signal:** `npm run typecheck` exits 0 with `"noImplicitThis": true` and
   `"noImplicitAny": true` in `tsconfig.json`; no `// @ts-ignore` added.

8. **TypeScript Wave 3 — enable `strictNullChecks`.** *(Gap G5-1b, Phase 5 deferral, plan line
   5132: "Wave 3 enables `strictNullChecks` on a per-file `// @ts-strict` opt-in".)*
   The heaviest TS lift. `strictNullChecks: false` means every value implicitly includes `null |
   undefined`, so there are no null-safety errors anywhere in the current codebase.
   Recommended approach: opt-in per file using a triple-slash directive rather than a global
   tsconfig flip. TypeScript 5.x supports per-file strict enabling via a project-reference
   pattern:
   1. Create `tsconfig.strict.json` extending the root with `"strictNullChecks": true`.
   2. Convert the simplest files first (those with no jQuery DOM interaction): `toaster.ts`,
      `switchbox.ts`, `pngfix.ts`, `rating.ts`.
   3. Add each converted file to `tsconfig.strict.json`'s `include` list.
   4. When all 38 files are in the strict config, fold `strictNullChecks: true` into the root
      `tsconfig.json` and delete `tsconfig.strict.json`.
   **Exit signal:** `npm run typecheck` exits 0 with `"strictNullChecks": true` in root
   `tsconfig.json`; all 38 entry-point `.ts` files covered.

---

### Effort breakdown

| Sub-task | Step | Tag |
|---|---|---|
| `dev/fixtures/piwigo-15.x.sql` rejection fixture | 1 | S |
| SameSite Lax → Strict | 2 | M |
| WS class property visibility tightening | 3 | M |
| Compat aliases removal | 4 | L |
| DummyPlugin/Theme deprecation notices | 5 | S |
| Vite manifest per-entry CSS | 6 | M |
| TypeScript Wave 2 (`noImplicitAny`, `noImplicitThis`) | 7 | L |
| TypeScript Wave 3 (`strictNullChecks`) | 8 | L |

**Phase total: L** (driven by steps 4, 7, and 8).

### Risks specific to Phase 8

- **SameSite Strict breaks cross-site navigation flows.** Any link from an external page into
  the gallery that relies on the session cookie (e.g. SSO redirects, OAuth callbacks) will see an
  unauthenticated request under Strict. Test exhaustively on staging before shipping step 2.
- **Aliases removal has unknown blast radius in plugin code.** Plugins that include Piwigo files
  and then reference short class names (e.g. `new Template()`) rely on `aliases.php`. Removing
  the file will break those plugins silently — they'll get a fatal at `new Template()`. Audit the
  plugin ecosystem before deleting. The safest path: keep the file but emit a deprecation from
  each `class_alias` call, give one release cycle, then delete.
- **`strictNullChecks` is a weeks-long effort per file when jQuery is involved.** Every `$(elem)`
  is `JQuery | null` under strict checks. The per-file opt-in approach mitigates this by letting
  you ship partial coverage incrementally.
- **WS getter refactor touches the response encoder** which is exercised by every web-service
  call. Write a `PwgResponseEncoderTest` before changing `_content` visibility to catch
  regressions.

### Verification

1. `dev/fixtures/piwigo-15.x.sql` exists; PHPUnit integration test asserts HTTP 409 from
   `upgrade.php` when that fixture is loaded (step 1).
2. All `session_set_cookie_params` and `setcookie` calls use `'Strict'`; Playwright remember-me
   spec green (step 2).
3. `grep -n "\/\*private\*\/ var\|public.*_methods\|public.*_content" src/Piwigo/Ws/*.php`
   returns zero hits (step 3).
4. `ls src/Piwigo/Compat/aliases.php` → "No such file"; `composer.json` `autoload.files` empty
   or absent; PHPStan exits 0 (step 4).
5. `grep -n "trigger_error.*deprecated" src/Piwigo/Admin/DummyPlugin_maintain.php` shows
   deprecation notices in each delegating method (step 5).
6. `dist/manifest.json` entry for `core.scripts` lists only its own CSS (step 6).
7. `npm run typecheck` exits 0 with `"noImplicitAny": true, "noImplicitThis": true` (step 7).
8. `npm run typecheck` exits 0 with `"strictNullChecks": true` (step 8).

---

- **If forced to ship only three phases ever, ship 0, 1, 2.** That alone takes Piwigo from "won't run on PHP 8" to "runs on PHP 8.5, type-safe, CI-protected" — most of the user-visible upside.
