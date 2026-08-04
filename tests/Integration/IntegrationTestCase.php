<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Events;
use Doctrine\ORM\ORMSetup;
use PHPUnit\Framework\TestCase;
use Piwigo\Config\ConfigEntry;
use Piwigo\Config\ConfigRepository;
use Piwigo\Core\FilesystemHelper;
use Piwigo\Core\Paths;
use Piwigo\Db\DbConnection;
use Piwigo\Db\DbCredentials;
use Piwigo\Db\Tables;
use Piwigo\Db\TablePrefixListener;

/**
 * Shared infrastructure for integration tests.
 *
 * Requires these environment variables (loaded from .env.test by
 * tests/bootstrap.php):
 *
 *   PIWIGO_DB_HOST      MySQL host
 *   PIWIGO_DB_USER      MySQL user
 *   PIWIGO_DB_PASSWORD  MySQL password
 *   PIWIGO_DB_BASE      Test database name — never the production DB
 *   PIWIGO_DB_PREFIX    Table prefix (default: piwigo_)
 *   PIWIGO_BASE_URL     Base URL of the running Apache instance
 *
 * Every HTTP call sends `X-Piwigo-Env: test` so the runtime reads
 * .env.test and uses local/.installed.test — prod config is never
 * touched even if a test crashes.
 */
abstract class IntegrationTestCase extends TestCase
{
    protected string $dbHost = '';

    protected string $dbUser = '';

    protected string $dbPass = '';

    protected string $dbName = '';

    protected string $dbPrefix = 'piwigo_';

    protected string $dbDriver = 'mysqli';

    protected string $baseUrl = '';

    /**
     * Set by buildConfigRepository() -- exposed so a test that bypasses
     * ConfigRepository with a raw SQL write (e.g. corrupting a config row
     * to prove a guard's behavior) can clear this EntityManager's identity
     * map afterward. Without that, a later ConfigRepository::upsert() call
     * would resolve find()'s stale, pre-raw-write entity via the identity
     * map, see no property change, and flush() would silently skip the
     * UPDATE -- this EntityManager is a private `new EntityManager(...)`
     * distinct from Kernel::container()'s own, so clearing the container's
     * doesn't reach it.
     */
    protected ?\Doctrine\ORM\EntityManagerInterface $configEntityManager = null;

    /**
     * Piwigo\Users\UserService::getDefaultUserInfo() memoizes its DB read
     * into Piwigo\Core\ProcessCache (Legacy Coupling Retirement Track A
     * gap-fill batch G5, formerly `global $cache['default_user'];`) for
     * the lifetime of the process (a real production optimization -- one
     * row read per request, not per call). Since PHPUnit/Pest run every
     * test file in one shared process, a test with a minimal
     * `$GLOBALS['conf']` (missing `default_user_id`) can cache `false`
     * and poison the value every later test file reads (P23 batch 8d
     * found this the moment a 2nd Integration test file started
     * exercising the real getDefaultUserInfo()/getDefaultTheme()/
     * getDefaultLanguage() call chain instead of a fixed-value stub).
     * Every subclass's own setUp() already calls parent::setUp() first,
     * so resetting here guarantees each test starts with a fresh
     * memoization slot.
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        // Piwigo\Core\CurrentPaths is a pure transitional shim now
        // (singleton/service-locator elimination campaign, Phase 3) reading
        // Paths::class straight out of the live container -- tests that
        // construct a domain service directly (not through a real HTTP
        // request, so no root index.php ever calls Kernel::boot($paths))
        // need a real Paths bound too, or the first CurrentPaths::get() call
        // throws. Only boot here when nothing has booted the Kernel yet: a
        // subclass whose own setUp() calls Kernel::boot() *after*
        // parent::setUp() (several do, for its mountDepth/isWs/isAdmin
        // params or to layer its own container wiring on top) would have
        // that later call silently no-op against Kernel::boot()'s own
        // idempotency guard if this ran unconditionally -- a subclass
        // needing a genuinely different root calls Kernel::reset() itself
        // right after parent::setUp() (see InstallBootstrapTest/
        // InstallWizardTest/LegacyFileConfTest) rather than fighting this
        // default. dirname(__DIR__, 2) from tests/Integration/ is this
        // project's own repo root, matching every fixture path (e.g.
        // MetadataServiceTest's 'path' => '_data/...') already written
        // relative to it. Deliberately runs BEFORE the ProcessCache/
        // CurrentLogger/FilterState seeding below (not after, as an
        // earlier revision of this method had it) -- seeding those against
        // "Kernel::isBooted() happens to already be true" left every
        // subclass whose own setUp() boots Kernel via a bare, no-op-against-
        // the-idempotency-guard Kernel::boot() call (most of them, needing
        // no custom Paths) with an *unseeded* CurrentLogger/FilterState for
        // the whole test: parent::setUp() ran first (Kernel not yet
        // booted, so the old ordering's own isBooted() checks were all
        // false), then the subclass's own later boot() call silently
        // no-op'd against a container that was never seeded. Real bug,
        // found via a full composer test:integration run surfacing
        // "CurrentLogger not initialised" across ~8 unrelated test files
        // that construct their SUT directly but reach a container-resolved
        // CurrentLogger/FilterState somewhere in that SUT's own dependency
        // chain (e.g. via a Bootstrap\*Accessor).
        if (! \Piwigo\Core\Kernel::isBooted()) {
            \Piwigo\Core\Kernel::boot(Paths::fromRoot(dirname(__DIR__, 2)));
        }
        // ProcessCache is a container-shared instance now (singleton/
        // service-locator elimination campaign, Phase 1), not a static
        // facade -- always resolve+reset now that the boot decision above
        // guarantees a container exists.
        $processCache = \Piwigo\Core\Kernel::container()->get(\Piwigo\Core\ProcessCache::class);
        if ($processCache instanceof \Piwigo\Core\ProcessCache) {
            $processCache->reset();
        }
        // Piwigo\Users\CurrentUser (Legacy Coupling Retirement Track A batch
        // A3) is a request-lifetime singleton; PHPUnit/Pest run every test
        // file in one shared process (see this class's own docblock above
        // for the identical ProcessCache reasoning), so each test gets
        // a fresh guest baseline here -- idempotent, so a subclass's own
        // setUp() calling CurrentUser::set() with a specific fixture user
        // right after parent::setUp() simply overwrites it.
        $currentUser = \Piwigo\Core\Kernel::container()->get(\Piwigo\Users\CurrentUser::class);
        if ($currentUser instanceof \Piwigo\Users\CurrentUser) {
            $currentUser->attachGlobals();
        }
        // Piwigo\Core\CurrentLogger (singleton/service-locator elimination
        // campaign, Phase 2: container-shared instance) -- a real,
        // no-op-severity instance here means a subclass resolving its
        // SUT's CurrentLogger from the container still gets a valid,
        // non-throwing get() rather than the "not initialised"
        // LogicException. severity => OFF makes every log call an
        // immediate no-op (Logger::log() checks severity() >= $level, and
        // OFF is -1, below every real level), so this never touches the
        // filesystem.
        $currentLogger = \Piwigo\Core\Kernel::container()->get(\Piwigo\Core\CurrentLogger::class);
        if ($currentLogger instanceof \Piwigo\Core\CurrentLogger) {
            $currentLogger->set(new \Piwigo\Core\Logger(['severity' => \Piwigo\Core\Logger::OFF]));
        }
        // Piwigo\Core\FilterState (singleton/service-locator elimination
        // campaign, Phase 2: container-shared instance) -- a disabled-
        // filter baseline here means a subclass resolving its SUT's
        // FilterState from the container still gets a valid, non-throwing
        // isEnabled()/visibleCategories()/etc. A subclass's own setUp()
        // calling ->set() with real filter values right after
        // parent::setUp() simply overwrites it.
        $filterState = \Piwigo\Core\Kernel::container()->get(\Piwigo\Core\FilterState::class);
        if ($filterState instanceof \Piwigo\Core\FilterState) {
            $filterState->set(false);
        }
        // Truncate rather than delete -- Piwigo\Core\ErrorCollector appends
        // to this file while test-mode is active regardless of which test
        // wrote it last; starting each test from an empty file is what lets
        // assertNoPhpErrors() attribute an entry to the request that just
        // ran, not a previous test. _data/logs/ isn't guaranteed to exist
        // yet on a fresh checkout (see ErrorCollector::writeTestErrorsLog()'s
        // own identical guard) -- ensure it before truncating.
        FilesystemHelper::mkgetdir(dirname(__DIR__, 2) . '/_data/logs', FilesystemHelper::MKGETDIR_RECURSIVE);
        file_put_contents(dirname(__DIR__, 2) . '/_data/logs/test_errors.log', '');
        // ConfigService::allRowsFromCacheOrDb()'s cache is real,
        // cross-process-persistent storage in this environment -- ext-apcu
        // isn't installed here (confirmed elsewhere this session), so
        // CachePools::config() falls back to FilesystemAdapter, real files
        // under _data/cache/ visible to both this PHPUnit/Pest process and
        // any real Apache/FrankenPHP worker serving Browser-test HTTP
        // requests, not a per-process in-memory optimization. A test
        // writing config via raw SQL (bypassing ConfigService, so
        // confUpdateParam()'s own clear() never fires) would otherwise
        // leak a stale cached row into whichever test runs next.
        \Piwigo\Cache\CachePools::config()->clear();
    }

    #[\Override]
    protected function tearDown(): void
    {
        if (\Piwigo\Core\Kernel::isBooted()) {
            $currentUser = \Piwigo\Core\Kernel::container()->get(\Piwigo\Users\CurrentUser::class);
            if ($currentUser instanceof \Piwigo\Users\CurrentUser) {
                $currentUser->reset();
            }
            $processCache = \Piwigo\Core\Kernel::container()->get(\Piwigo\Core\ProcessCache::class);
            if ($processCache instanceof \Piwigo\Core\ProcessCache) {
                $processCache->reset();
            }
            $currentLogger = \Piwigo\Core\Kernel::container()->get(\Piwigo\Core\CurrentLogger::class);
            if ($currentLogger instanceof \Piwigo\Core\CurrentLogger) {
                $currentLogger->reset();
            }
        }
        \Piwigo\Config\CurrentConfig::current()->reset();
        // Harmless even for test classes that never call
        // buildConfigRepository()/wire CurrentConfigService::current()->set() at
        // all -- reset() on an already-unset registry is a no-op.
        \Piwigo\Config\CurrentConfigService::current()->reset();
        if (\Piwigo\Core\Kernel::isBooted()) {
            $pageState = \Piwigo\Core\Kernel::container()->get(\Piwigo\Core\PageState::class);
            if ($pageState instanceof \Piwigo\Core\PageState) {
                $pageState->reset();
            }
            $filterState = \Piwigo\Core\Kernel::container()->get(\Piwigo\Core\FilterState::class);
            if ($filterState instanceof \Piwigo\Core\FilterState) {
                $filterState->reset();
            }
        }
        // InstallationFlag is a container-shared instance now (singleton/
        // service-locator elimination campaign, Phase 1), not a static
        // facade -- most subclasses never call Kernel::boot() at all, so
        // only resolve+reset when a container genuinely exists.
        if (\Piwigo\Core\Kernel::isBooted()) {
            $installationFlag = \Piwigo\Core\Kernel::container()->get(\Piwigo\Core\InstallationFlag::class);
            if ($installationFlag instanceof \Piwigo\Core\InstallationFlag) {
                $installationFlag->reset();
            }
        }
        // CurrentPaths has no state of its own to reset (Phase 3) -- it
        // reads Paths::class from whatever container is live. Reset the
        // Kernel itself instead: setUp() above only boots when nothing else
        // has, so this is what returns the next test to a clean, unbooted
        // baseline -- safe even when a subclass's own tearDown() already
        // called Kernel::reset() itself (idempotent).
        \Piwigo\Core\Kernel::reset();
        // A test that exercises a real login/install-completion flow (e.g.
        // AuthService's own session_start()) leaves PHP's native session
        // machinery genuinely active -- PHPUnit/Pest run every Integration
        // test file in one shared process (see this class's own docblock
        // above), so an unclosed session here bleeds into the next test
        // file entirely: a later, unrelated setUp() calling session_id()
        // to pin a fixed id (CsrfService::getToken() needs one) throws the
        // "cannot be changed when a session is active" PHP warning.
        // Real bug this exact way, found via
        // tests/Integration/InstallWizardTest.php's own
        // isSendCredentialsByMail/isNewsletterSubscribe scenarios reaching
        // a real post-install auto-login for the first time.
        if (session_status() === \PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        parent::tearDown();
    }

    /**
     * Opt-in helper for the Integration tests that need a real, DB-backed
     * ConfigRepository -- Doctrine's ORM stack isn't part of this base
     * class's own setUp() (most Integration tests never touch it), so
     * this mirrors setUpConnectionFromEnv()'s own not-auto-called shape:
     * call it explicitly from a subclass's setUp() when the test actually
     * exercises a Tier 2 class's write path (Legacy Coupling Retirement
     * Phase 5) or otherwise needs a real ConfigService/ConfigRepository.
     */
    protected function buildConfigRepository(): ConfigRepository
    {
        $conn = DbConnection::build();
        $ormConfig = ORMSetup::createAttributeMetadataConfig([dirname(__DIR__, 2) . '/src/Piwigo'], isDevMode: true);
        $ormConfig->enableNativeLazyObjects(true);
        $em = new EntityManager($conn, $ormConfig);
        $em->getEventManager()->addEventListener(Events::loadClassMetadata, new TablePrefixListener(DbCredentials::current()));
        $this->configEntityManager = $em;

        $repo = $em->getRepository(ConfigEntry::class);
        self::assertInstanceOf(ConfigRepository::class, $repo);

        return $repo;
    }

    protected function setUpConnectionFromEnv(): void
    {
        $dbHost   = getenv('PIWIGO_DB_HOST');
        $dbUser   = getenv('PIWIGO_DB_USER');
        $dbPass   = getenv('PIWIGO_DB_PASSWORD');
        $dbName   = getenv('PIWIGO_DB_BASE');
        $dbPrefix = getenv('PIWIGO_DB_PREFIX');
        $dbDriver = getenv('PIWIGO_DB_DRIVER');
        $baseUrl  = getenv('PIWIGO_BASE_URL');

        $this->dbHost   = $dbHost !== false ? $dbHost : '127.0.0.1';
        $this->dbUser   = $dbUser !== false ? $dbUser : '';
        $this->dbPass   = $dbPass !== false ? $dbPass : '';
        $this->dbName   = $dbName !== false ? $dbName : '';
        $this->dbPrefix = $dbPrefix !== false ? $dbPrefix : 'piwigo_';
        $this->dbDriver = $dbDriver === 'pgsql' ? 'pgsql' : 'mysqli';
        $this->baseUrl  = rtrim($baseUrl !== false ? $baseUrl : '', '/');
    }

    protected function requireBaseUrl(): void
    {
        if ($this->baseUrl === '') {
            self::fail('PIWIGO_BASE_URL is not set in .env.test — integration tests need a running web server.');
        }
    }

    /**
     * @return list<string>
     */
    protected function testHeader(): array
    {
        $value = $_SERVER['HTTP_X_PIWIGO_ENV'] ?? null;

        $headers = ['X-Piwigo-Env: ' . (is_string($value) ? $value : 'test')];
        if (getenv('PIWIGO_COVERAGE') === '1') {
            $headers[] = 'X-Piwigo-Coverage: 1';
        }

        return $headers;
    }

    protected function resetDatabase(): void
    {
        if ($this->dbDriver === 'pgsql') {
            $this->resetDatabasePostgres();

            return;
        }

        $db = $this->newMysqli('');
        $db->query(sprintf('DROP DATABASE IF EXISTS `%s`', $this->dbName));
        $db->query(sprintf('CREATE DATABASE `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci', $this->dbName));
        $db->close();
    }

    /**
     * pgsql support pass: a bare `DROP DATABASE` fails outright
     * (`ERROR: database "..." is being accessed by other users`, a real
     * nonzero-exit-code error, not a warning) if any other connection is
     * still attached -- verified live against a genuinely active,
     * `pg_stat_activity`-confirmed connection (a real risk here: a prior
     * Browser test's Apache/FrankenPHP worker connection, or this test
     * process's own previous connection, can easily still be attached
     * when the next test's resetDatabase() runs). `WITH (FORCE)`
     * (Postgres 13+ -- this environment runs 18) terminates other
     * backends automatically first -- verified live against that same
     * active-connection setup that a bare DROP DATABASE couldn't clear.
     * Connects to the `postgres` maintenance database (always exists,
     * can't be dropped) since Postgres can't DROP/CREATE the database
     * the current connection is itself attached to, mirroring
     * newMysqli('')'s own no-dbname admin-connection shape.
     */
    private function resetDatabasePostgres(): void
    {
        $conn = $this->newPgsqlConnection('postgres');
        pg_query($conn, sprintf('DROP DATABASE IF EXISTS "%s" WITH (FORCE)', $this->dbName));
        pg_query($conn, sprintf('CREATE DATABASE "%s" WITH ENCODING \'UTF8\'', $this->dbName));
        pg_close($conn);
    }

    protected function loadFixture(string $path): void
    {
        if ($this->dbDriver === 'pgsql') {
            $this->loadFixtureViaPsql($this->pgsqlFixturePath($path));
        } else {
            $this->loadFixtureViaMysql($path);
        }

        // The raw import above never goes through ConfigService, so its
        // own confUpdateParam()/confDeleteParam() cache-clearing never
        // fires -- without this, any real server process sharing this
        // filesystem-backed cache (see setUp()'s own comment) would keep
        // serving whatever config rows it cached before this fixture
        // replaced them.
        \Piwigo\Cache\CachePools::config()->clear();

        // `piwigo_sites` id=1's own `galleries_url` is committed in the
        // fixture as an absolute filesystem path (Piwigo\Core\Paths::$root
        // . 'galleries/', matching exactly what Admin\Install\InstallWizard
        // seeds it with on a real install) -- inherently tied to wherever
        // *that* install's checkout lived, not portable data. Every
        // checkout of this repo lives at a different path, so this is
        // corrected here, at fixture-load time (same "environment-injected,
        // never fixture-baked" treatment as PIWIGO_TEST_NOW), rather than
        // left for whichever test happens to read it first to discover it's
        // stale. tools/reimport-fixture.sh applies the identical correction
        // for its own separate (shell, not PHPUnit) import path.
        //
        // dirname(__DIR__, 2) rather than CurrentPaths::get()->root:
        // ContractTestCase (a real loadFixture() caller) never calls
        // parent::setUp(), so CurrentPaths is never initialised in its own
        // process -- confirmed live (a Contract suite run threw "CurrentPaths
        // not initialised" here before this fix). Computed directly instead,
        // same technique this class's own setUp() below and
        // tests/Browser/CatModifyPageRendererTest.php both already use for
        // the identical "this checkout's real root" value -- self-contained,
        // no initialization-order dependency.
        DbConnection::build()->executeStatement(
            'UPDATE ' . Tables::sites() . ' SET galleries_url = ? WHERE id = 1',
            [dirname(__DIR__, 2) . '/galleries/']
        );

        $this->settleDatabase();
    }

    private function loadFixtureViaMysql(string $path): void
    {
        self::assertFileExists($path, 'Fixture file must exist: ' . $path);

        $cmd = ['mysql', '-u' . $this->dbUser];
        if ($this->dbPass !== '') {
            $cmd[] = '-p' . $this->dbPass;
        }

        $cmd[] = str_starts_with($this->dbHost, '/') ? '--socket=' . $this->dbHost : '-h' . $this->dbHost;

        $cmd[] = $this->dbName;

        $descriptors = [
            0 => ['file', $path, 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open($cmd, $descriptors, $pipes);
        self::assertIsResource($proc, 'proc_open failed for mysql fixture load');
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        self::assertSame(0, $exit, 'mysql fixture load failed: ' . ($stderr === false ? '' : $stderr));
    }

    /**
     * pgsql support pass: `tests/Fixtures/piwigo-17.0-pgsql.sql` is a
     * plain-SQL `pg_dump` (schema + data, same shape
     * Piwigo\Db\SchemaDumpService's own schema-only dump uses) -- `psql
     * -f` loads it directly, no `pg_restore`/custom-format machinery
     * needed. `-v ON_ERROR_STOP=1` matters here specifically: psql's own
     * default is to keep running past a failed statement (and still
     * exit 0), which would silently leave a partially-loaded fixture
     * indistinguishable from a fully-loaded one to this method's own
     * exit-code check.
     *
     * Real bug found live once a genuine `pg_dump` output (rather than a
     * hand-crafted throwaway fixture) exercised this path: every real
     * `pg_dump` emits `SELECT pg_catalog.set_config('search_path', '',
     * false);` as its own standard preamble -- a real query that returns
     * a real one-row result set, which `psql` writes to stdout as a
     * formatted table regardless of `-q` (quiet mode only suppresses
     * psql's own informational messages, not query output). Blindly
     * `fclose()`-ing $pipes[1] without ever reading it left nothing on
     * the other end of that pipe, so psql's own write failed with EPIPE
     * ("could not print result table: Broken pipe") the moment it tried
     * -- confirmed live, and confirmed this never surfaced against
     * `piwigo-17.0.sql`'s own `mysqldump` output because MySQL's dump
     * format contains no bare `SELECT` that returns a real result set to
     * print. Drained the same way $pipes[2] (stderr) already was, rather
     * than left unread.
     */
    private function loadFixtureViaPsql(string $path): void
    {
        self::assertFileExists($path, 'Fixture file must exist: ' . $path);

        $cmd = ['psql', '-v', 'ON_ERROR_STOP=1', '-q'];
        $cmd[] = '-U' . $this->dbUser;
        $cmd[] = '-h' . $this->dbHost;
        $cmd[] = '-d' . $this->dbName;
        $cmd[] = '-f' . $path;

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        // getenv() (real process env vars, always string-valued), not
        // $_SERVER -- proc_open()'s own $env_vars param requires every
        // value to be a string, and $_SERVER carries CLI-SAPI-specific
        // array-valued entries too (e.g. 'argv'), confirmed live to throw
        // "Array to string conversion" partway through proc_open()'s own
        // internal env-var marshalling otherwise.
        $env = $this->dbPass !== '' ? array_merge(getenv(), ['PGPASSWORD' => $this->dbPass]) : null;
        $proc = proc_open($cmd, $descriptors, $pipes, null, $env);
        self::assertIsResource($proc, 'proc_open failed for psql fixture load');
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        self::assertSame(0, $exit, 'psql fixture load failed: ' . ($stderr === false ? '' : $stderr) . ($stdout === false ? '' : $stdout));
    }

    /**
     * `piwigo-17.0.sql` -> `piwigo-17.0-pgsql.sql` -- every real
     * loadFixture() caller passes the same hardcoded mysql-shaped
     * literal path (~120 call sites), so this derives the pgsql sibling
     * from it rather than needing every call site to pass both.
     */
    private function pgsqlFixturePath(string $mysqlPath): string
    {
        self::assertStringEndsWith('.sql', $mysqlPath, 'Fixture path must end in .sql: ' . $mysqlPath);

        return substr($mysqlPath, 0, -4) . '-pgsql.sql';
    }

    /**
     * A cold InnoDB buffer pool on a freshly (re)imported schema can make the
     * very first heavy query slow enough to blow a browser-test assertion's
     * timeout, even though the app itself has no bug (a bare curl to the same
     * URL was instant while a Playwright assertion timed out at 5s
     * immediately after a reimport). Poll a real table — not a no-op
     * `SELECT 1` — until it's readable.
     */
    private function settleDatabase(): void
    {
        $deadline = microtime(true) + 30.0;
        while (microtime(true) < $deadline) {
            if ($this->dbDriver === 'pgsql') {
                // PGSQL_CONNECT_FORCE_NEW is load-bearing here specifically
                // -- see newPgsqlConnection()'s own docblock: without it,
                // this loop would keep reusing the same (possibly dead,
                // e.g. right after resetDatabase()'s own WITH (FORCE))
                // cached connection handle every iteration instead of
                // genuinely re-probing.
                $conn = @pg_connect($this->pgsqlConnectionString($this->dbName), PGSQL_CONNECT_FORCE_NEW);
                if ($conn !== false) {
                    $result = @pg_query($conn, sprintf('SELECT COUNT(*) FROM "%simages"', $this->dbPrefix));
                    pg_close($conn);
                    if ($result !== false) {
                        return;
                    }
                }
            } else {
                $db = $this->newMysqli($this->dbName);
                if ($db->connect_errno === 0) {
                    $result = $db->query(sprintf('SELECT COUNT(*) FROM `%simages`', $this->dbPrefix));
                    if ($result !== false) {
                        $db->close();

                        return;
                    }
                }
                $db->close();
            }

            usleep(100_000);
        }
        self::fail('Test database did not become queryable within 30s after fixture load.');
    }

    /**
     * pgsql support pass: several real Integration/Contract tests
     * temporarily disable FK enforcement to reproduce an orphaned-row
     * state no normal write path can ever produce on its own (e.g. a
     * bulk import/migration that ran with checks off) -- MySQL's own
     * `SET FOREIGN_KEY_CHECKS=0/1` is session-scoped and needs no
     * per-table bookkeeping, which every real call site's own shape
     * already relies on (disable once, insert into 1-2 different tables,
     * re-enable). Postgres has no single blanket session setting an
     * ordinary role can reach: `SET session_replication_role = replica`
     * is the real equivalent (also session-scoped, also disables every
     * table's FK-enforcement trigger at once, matching the MySQL
     * semantic exactly) but is a superuser-only GUC (confirmed live --
     * `ALTER TABLE ... DISABLE TRIGGER ALL` was tried first and rejected
     * identically: "permission denied: ... is a system trigger", even
     * against the table's own owner). The dedicated Postgres test role
     * needs `SUPERUSER` granted for this to work at all -- the same
     * elevated-privilege convention this codebase's own MySQL test setup
     * already relies on (`PIWIGO_DB_USER=root` in `.env.test`), not a
     * new asymmetry Postgres introduces.
     */
    protected function disableForeignKeyChecks(Connection $conn): void
    {
        $conn->executeStatement($this->dbDriver === 'pgsql' ? 'SET session_replication_role = replica' : 'SET FOREIGN_KEY_CHECKS=0');
    }

    protected function enableForeignKeyChecks(Connection $conn): void
    {
        $conn->executeStatement($this->dbDriver === 'pgsql' ? 'SET session_replication_role = DEFAULT' : 'SET FOREIGN_KEY_CHECKS=1');
    }

    protected function markTestInstalled(): void
    {
        $stamp = dirname(__DIR__, 2) . '/local/' . \Piwigo\Core\Env::testModeInstalledStamp();
        // The stamp is often already present, created by install.php running
        // as the webserver user (e.g. www-data) — only the file's existence
        // matters (common.inc.php gates on file_exists(), not mtime), so
        // don't touch() an existing file the CLI user may not own.
        if (!file_exists($stamp)) {
            touch($stamp);
        }
    }

    protected function removeTestStamp(): void
    {
        $stamp = dirname(__DIR__, 2) . '/local/' . \Piwigo\Core\Env::testModeInstalledStamp();
        if (file_exists($stamp)) {
            unlink($stamp);
        }
    }

    protected function newMysqli(string $dbName): \mysqli
    {
        return new \mysqli($this->dbHost, $this->dbUser, $this->dbPass, $dbName);
    }

    /**
     * pgsql support pass: the same raw, driver-native low-level escape
     * hatch newMysqli() already gives MySQL-targeted tests, for the
     * (currently only internal, see resetDatabase()/settleDatabase())
     * pgsql-targeted equivalent.
     *
     * PGSQL_CONNECT_FORCE_NEW is load-bearing, not defensive -- unlike
     * `new \mysqli(...)` (always a genuinely new connection), PHP's
     * `pg_connect()` caches and reuses a connection object per identical
     * connection string within one process by default. Real bug found
     * live: resetDatabase()'s own `WITH (FORCE)` terminates the server
     * backend for any prior connection to that database, but a later
     * plain `pg_connect()` call with the same string still returned that
     * same, now-dead PHP-level handle -- confirmed by comparing object
     * identity (`===`) and `pg_get_pid()` before/after a real drop+
     * recreate cycle in one process, then observing every query against
     * it fail with "FATAL: terminating connection due to administrator
     * command". settleDatabase()'s own poll loop calling plain
     * `pg_connect()` in this state would loop for the full 30s timeout
     * and always fail, never actually re-probing a live connection.
     */
    protected function newPgsqlConnection(string $dbName): \PgSql\Connection
    {
        $conn = pg_connect($this->pgsqlConnectionString($dbName), PGSQL_CONNECT_FORCE_NEW);
        self::assertNotFalse($conn, 'pg_connect failed');

        return $conn;
    }

    private function pgsqlConnectionString(string $dbName): string
    {
        $parts = [
            'host=' . $this->dbHost,
            'user=' . $this->dbUser,
            'dbname=' . $dbName,
        ];
        if ($this->dbPass !== '') {
            $parts[] = 'password=' . $this->dbPass;
        }

        return implode(' ', $parts);
    }

    /**
     * Fetches `GET /__test/errors` (Piwigo\Controller\TestErrorsController)
     * and asserts Piwigo\Core\ErrorCollector's buffer is empty -- call after
     * exercising a real HTTP request to catch PHP errors/warnings/
     * deprecations a test might otherwise miss (the X-PHP-Error-N response
     * headers are easy to not notice, and don't survive a redirect).
     * Requires PIWIGO_BASE_URL, same as every other real-HTTP-request helper
     * on this class.
     */
    protected function assertNoPhpErrors(): void
    {
        $this->requireBaseUrl();

        $ch = curl_init($this->baseUrl . '/__test/errors');
        self::assertNotFalse($ch, 'curl_init failed');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $this->testHeader());
        $body = curl_exec($ch);
        curl_close($ch);

        self::assertIsString($body, 'GET /__test/errors did not return a body');
        $data = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertArrayHasKey('errors', $data);
        self::assertIsArray($data['errors']);
        $errors = array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '', $data['errors']);
        self::assertSame(
            [],
            $data['errors'],
            'Expected no PHP errors/warnings/deprecations, got: ' . implode('; ', $errors)
        );
    }

    protected function queryScalar(string $sql): string
    {
        $db     = $this->newMysqli($this->dbName);
        $result = $db->query($sql);
        self::assertInstanceOf(\mysqli_result::class, $result);
        $row = $result->fetch_row();
        $db->close();
        self::assertIsArray($row);
        self::assertIsString($row[0]);

        return $row[0];
    }
}
