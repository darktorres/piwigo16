<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Doctrine\ORM\EntityManagerInterface;
use Override;
use Piwigo\Core\Kernel;
use Piwigo\Core\ProcessCache;
use Piwigo\Users\CurrentUser;
use Piwigo\Core\CurrentLogger;
use Piwigo\Core\Logger;
use Piwigo\Core\FilterState;
use Piwigo\Cache\CachePools;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Tests\Support\CurrentConfigServiceTestFactory;
use Piwigo\Core\PageState;
use Piwigo\Core\InstallationFlag;
use Piwigo\Core\Env;
use mysqli;
use mysqli_result;
use mysqli_sql_exception;
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
use Piwigo\Tests\Support\DbCredentialsTestFactory;
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
    protected ?EntityManagerInterface $configEntityManager = null;

    /**
     * Piwigo\Users\UserService::getDefaultUserInfo() memoizes its DB read
     * into Piwigo\Core\ProcessCache for the lifetime of the process (a
     * real production optimization -- one row read per request, not per
     * call). Since PHPUnit/Pest run every test file in one shared
     * process, a test with a minimal `$GLOBALS['conf']` (missing
     * `default_user_id`) can cache `false` and poison the value every
     * later test file reads. Every subclass's own setUp() already calls
     * parent::setUp() first, so resetting here guarantees each test
     * starts with a fresh memoization slot.
     */
    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        // Paths::class must be resolvable straight out of the live
        // container -- tests that construct a domain service directly (not
        // through a real HTTP request, so no root index.php ever calls
        // Kernel::boot($paths)) need a real Paths bound too, or the first
        // real container resolve throws. Only boot here when nothing has
        // booted the Kernel yet: a subclass whose own setUp() calls
        // Kernel::boot() *after* parent::setUp() (several do, for its
        // mountDepth/isWs/isAdmin params or to layer its own container
        // wiring on top) would have that later call silently no-op against
        // Kernel::boot()'s own idempotency guard if this ran
        // unconditionally -- a subclass needing a genuinely different root
        // calls Kernel::reset() itself right after parent::setUp() (see
        // InstallBootstrapTest/InstallWizardTest/LegacyFileConfTest) rather
        // than fighting this default. dirname(__DIR__, 2) from
        // tests/Integration/ is this project's own repo root, matching
        // every fixture path (e.g. MetadataServiceTest's 'path' =>
        // '_data/...') already written relative to it. This deliberately
        // runs BEFORE the ProcessCache/CurrentLogger/FilterState seeding
        // below: seeding those first would leave any subclass whose own
        // setUp() boots Kernel via a bare, no-op-against-the-idempotency-
        // guard Kernel::boot() call (most of them, needing no custom Paths)
        // with an *unseeded* CurrentLogger/FilterState for the whole test,
        // since that subclass's own later boot() call would silently
        // no-op against a container that was never seeded -- surfacing as
        // a "CurrentLogger not initialised" error in any test that
        // constructs its SUT directly but reaches a container-resolved
        // CurrentLogger/FilterState somewhere in that SUT's own dependency
        // chain (e.g. via a Bootstrap\*Accessor).
        if (! Kernel::isBooted()) {
            Kernel::boot(Paths::fromRoot(dirname(__DIR__, 2)));
        }
        // ProcessCache is a container-shared instance, not a static
        // facade -- always resolve+reset now that the boot decision above
        // guarantees a container exists.
        $processCache = Kernel::container()->get(ProcessCache::class);
        if ($processCache instanceof ProcessCache) {
            $processCache->reset();
        }
        // Piwigo\Users\CurrentUser is a request-lifetime singleton;
        // PHPUnit/Pest run every test file in one shared process (see this
        // class's own docblock above for the identical ProcessCache
        // reasoning), so each test gets a fresh guest baseline here --
        // idempotent, so a subclass's own setUp() calling CurrentUser::set()
        // with a specific fixture user right after parent::setUp() simply
        // overwrites it.
        $currentUser = Kernel::container()->get(CurrentUser::class);
        if ($currentUser instanceof CurrentUser) {
            $currentUser->attachGlobals();
        }
        // Piwigo\Core\CurrentLogger is a container-shared instance -- a
        // real, no-op-severity instance here means a subclass resolving
        // its SUT's CurrentLogger from the container still gets a valid,
        // non-throwing get() rather than the "not initialised"
        // LogicException. severity => OFF makes every log call an
        // immediate no-op (Logger::log() checks severity() >= $level, and
        // OFF is -1, below every real level), so this never touches the
        // filesystem.
        $currentLogger = Kernel::container()->get(CurrentLogger::class);
        if ($currentLogger instanceof CurrentLogger) {
            $currentLogger->set(new Logger(['severity' => Logger::OFF]));
        }
        // Piwigo\Core\FilterState is a container-shared instance -- a
        // disabled-filter baseline here means a subclass resolving its
        // SUT's FilterState from the container still gets a valid,
        // non-throwing isEnabled()/visibleCategories()/etc. A subclass's
        // own setUp() calling ->set() with real filter values right after
        // parent::setUp() simply overwrites it.
        $filterState = Kernel::container()->get(FilterState::class);
        if ($filterState instanceof FilterState) {
            $filterState->set(false);
        }
        // Truncate rather than delete -- Piwigo\Core\ErrorCollector appends
        // to this file while test-mode is active regardless of which test
        // wrote it last; starting each test from an empty file is what lets
        // assertNoPhpErrors() attribute an entry to the request that just
        // ran, not a previous test. _data/logs/ isn't guaranteed to exist
        // yet on a fresh checkout (see ErrorCollector::writeTestErrorsLog()'s
        // own identical guard) -- ensure it before truncating.
        FilesystemHelper::mkgetdir(dirname(__DIR__, 2) . '/_data/logs', CurrentConfigTestFactory::get(), FilesystemHelper::MKGETDIR_RECURSIVE);
        file_put_contents(dirname(__DIR__, 2) . '/_data/logs/test_errors.log', '');
        // ConfigService::allRowsFromCacheOrDb()'s cache is real,
        // cross-process-persistent storage in this environment -- ext-apcu
        // isn't installed here, so
        // CachePools::config() falls back to FilesystemAdapter, real files
        // under _data/cache/ visible to both this PHPUnit/Pest process and
        // any real Apache/FrankenPHP worker serving Browser-test HTTP
        // requests, not a per-process in-memory optimization. A test
        // writing config via raw SQL (bypassing ConfigService, so
        // confUpdateParam()'s own clear() never fires) would otherwise
        // leak a stale cached row into whichever test runs next.
        CachePools::config()->clear();
    }

    #[Override]
    protected function tearDown(): void
    {
        if (Kernel::isBooted()) {
            $currentUser = Kernel::container()->get(CurrentUser::class);
            if ($currentUser instanceof CurrentUser) {
                $currentUser->reset();
            }
            $processCache = Kernel::container()->get(ProcessCache::class);
            if ($processCache instanceof ProcessCache) {
                $processCache->reset();
            }
            $currentLogger = Kernel::container()->get(CurrentLogger::class);
            if ($currentLogger instanceof CurrentLogger) {
                $currentLogger->reset();
            }
        }
        CurrentConfigTestFactory::get()->reset();
        // Harmless even for test classes that never call
        // buildConfigRepository()/wire CurrentConfigServiceTestFactory::get()->set() at
        // all -- reset() on an already-unset registry is a no-op.
        CurrentConfigServiceTestFactory::get()->reset();
        if (Kernel::isBooted()) {
            $pageState = Kernel::container()->get(PageState::class);
            if ($pageState instanceof PageState) {
                $pageState->reset();
            }
            $filterState = Kernel::container()->get(FilterState::class);
            if ($filterState instanceof FilterState) {
                $filterState->reset();
            }
        }
        // InstallationFlag is a container-shared instance, not a static
        // facade -- most subclasses never call Kernel::boot() at all, so
        // only resolve+reset when a container genuinely exists.
        if (Kernel::isBooted()) {
            $installationFlag = Kernel::container()->get(InstallationFlag::class);
            if ($installationFlag instanceof InstallationFlag) {
                $installationFlag->reset();
            }
        }
        // Paths::class has no independent state of its own to reset -- it's
        // read directly from whatever container is live. Reset the
        // Kernel itself instead: setUp() above only boots when nothing else
        // has, so this is what returns the next test to a clean, unbooted
        // baseline -- safe even when a subclass's own tearDown() already
        // called Kernel::reset() itself (idempotent).
        Kernel::reset();
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
     * exercises a Tier 2 class's write path or otherwise needs a real
     * ConfigService/ConfigRepository.
     */
    protected function buildConfigRepository(): ConfigRepository
    {
        $conn = DbConnection::build();
        $ormConfig = ORMSetup::createAttributeMetadataConfig([dirname(__DIR__, 2) . '/src/Piwigo'], isDevMode: true);
        $ormConfig->enableNativeLazyObjects(true);
        $em = new EntityManager($conn, $ormConfig);
        $em->getEventManager()->addEventListener(Events::loadClassMetadata, new TablePrefixListener(DbCredentialsTestFactory::get()));
        $this->configEntityManager = $em;

        $repo = $em->getRepository(ConfigEntry::class);

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
        $this->dropAndCreateDatabase($this->dbName);
    }

    /**
     * pgsql support pass: generalized from resetDatabase()'s own former
     * $this->dbName-only body so scratch-database-using tests (backup/
     * restore round-trips, which deliberately restore into their own
     * disposable database rather than the shared fixture one) can reuse
     * the same real drop+recreate mechanics under any name, not just the
     * one this class's own fixture lives in.
     *
     * A bare `DROP DATABASE` fails outright (`ERROR: database "..." is
     * being accessed by other users`, a real nonzero-exit-code error, not
     * a warning) if any other connection is still attached -- verified
     * live against a genuinely active, `pg_stat_activity`-confirmed
     * connection (a real risk here: a prior Browser test's Apache/
     * FrankenPHP worker connection, or this test process's own previous
     * connection, can easily still be attached when the next reset runs).
     * `WITH (FORCE)` (Postgres 13+ -- this environment runs 18) terminates
     * other backends automatically first -- verified live against that
     * same active-connection setup that a bare DROP DATABASE couldn't
     * clear. Connects to the `postgres` maintenance database (always
     * exists, can't be dropped) since Postgres can't DROP/CREATE the
     * database the current connection is itself attached to, mirroring
     * newMysqli('')'s own no-dbname admin-connection shape.
     */
    protected function dropAndCreateDatabase(string $dbName): void
    {
        // Real bug found live: `WITH (FORCE)` terminates every other
        // backend attached to $dbName, including a still-open PHP native
        // session left active by an earlier test's real login flow (this
        // class's own tearDown() only closes it *after* the test method
        // returns -- too late once a later test's resetDatabase() has
        // already force-killed the connection PwgSession/SessionService
        // was going to write through). Closing it here first flushes that
        // write against the connection while it's still alive, instead of
        // deferring to a tearDown() that will find it already severed
        // ("terminating connection due to administrator command",
        // confirmed live via a real cross-test-file reproduction).
        //
        // This early close can still lose the race: a *different*,
        // interleaving test's own `WITH (FORCE)` drop can have already
        // severed the connection behind *this* stale session's
        // PwgSession/EntityManager before this method ever runs (confirmed
        // live via a real full-suite reproduction, traced down to a
        // Doctrine\DBAL\Exception\ConnectionLost -- "terminating
        // connection due to administrator command" -- thrown from
        // UnitOfWork::commit()'s own beginTransaction() call). This
        // write's own success or failure is moot either way: the very
        // next statement drops (and recreates) the whole database,
        // discarding whatever row it would have touched. PwgSession::
        // write() already catches that exception and correctly returns
        // false per SessionHandlerInterface's contract, but PHP's own
        // session module then raises a native E_WARNING for the
        // handler-reported failure -- `@` does not suppress it from
        // Pest/PHPUnit's own error handler (confirmed live: it still
        // converts to a test warning even under `@`), so swap in a
        // no-op handler for the duration of this one call instead.
        if ($this->dbDriver === 'pgsql' && session_status() === \PHP_SESSION_ACTIVE) {
            set_error_handler(static fn (): bool => true);

            try {
                session_write_close();
            } finally {
                restore_error_handler();
            }
        }

        if ($this->dbDriver === 'pgsql') {
            $conn = $this->newPgsqlConnection('postgres');
            pg_query($conn, sprintf('DROP DATABASE IF EXISTS "%s" WITH (FORCE)', $dbName));
            pg_query($conn, sprintf('CREATE DATABASE "%s" WITH ENCODING \'UTF8\'', $dbName));
            pg_close($conn);

            return;
        }

        $db = $this->newMysqli('');
        $db->query(sprintf('DROP DATABASE IF EXISTS `%s`', $dbName));
        $db->query(sprintf('CREATE DATABASE `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci', $dbName));
        $db->close();
    }

    /**
     * Drop-only counterpart to {@see dropAndCreateDatabase()} -- for
     * scratch-database cleanup in a test's own tearDown(), which only
     * ever needs the database gone, not recreated.
     */
    protected function dropDatabase(string $dbName): void
    {
        if ($this->dbDriver === 'pgsql') {
            $conn = $this->newPgsqlConnection('postgres');
            pg_query($conn, sprintf('DROP DATABASE IF EXISTS "%s" WITH (FORCE)', $dbName));
            pg_close($conn);

            return;
        }

        $db = $this->newMysqli('');
        $db->query(sprintf('DROP DATABASE IF EXISTS `%s`', $dbName));
        $db->close();
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
        CachePools::config()->clear();

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
        // dirname(__DIR__, 2) rather than a real, container-bound Paths->root:
        // ContractTestCase (a real loadFixture() caller) never calls
        // parent::setUp(), so no Paths is ever bound in its own
        // process -- confirmed live (a Contract suite run threw "CurrentPaths
        // not initialised" here before this fix, back when this read
        // through the CurrentPaths::get() shim). Computed directly instead,
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
                try {
                    $db = $this->newMysqli($this->dbName);
                    $db->query(sprintf('SELECT COUNT(*) FROM `%simages`', $this->dbPrefix));
                    $db->close();

                    return;
                } catch (mysqli_sql_exception) {
                    // PHP 8.1+'s default mysqli error mode throws
                    // mysqli_sql_exception on both a failed connection and a
                    // failed query, rather than leaving connect_errno
                    // nonzero or returning false -- under this PHP
                    // version's real default, reaching the return above at
                    // all already proves the query succeeded, and this
                    // catch (not a `!== false` check) is what makes the
                    // retry loop's own documented "poll until readable"
                    // intent actually work for a transient failure during
                    // InnoDB's cold-buffer-pool warm-up. Falls through to
                    // the usleep()+retry below, same as
                    // the pgsql branch's own @-suppressed false return.
                }
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
        $stamp = dirname(__DIR__, 2) . '/local/' . Env::testModeInstalledStamp();
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
        $stamp = dirname(__DIR__, 2) . '/local/' . Env::testModeInstalledStamp();
        if (file_exists($stamp)) {
            unlink($stamp);
        }
    }

    protected function newMysqli(string $dbName): mysqli
    {
        return new mysqli($this->dbHost, $this->dbUser, $this->dbPass, $dbName);
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
    // Not yet adopted by any subclass.
    // @phpstan-ignore shipmonk.deadMethod
    protected function assertNoPhpErrors(): void
    {
        $this->requireBaseUrl();

        $ch = curl_init($this->baseUrl . '/__test/errors');
        self::assertNotFalse($ch, 'curl_init failed');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $this->testHeader());
        $body = curl_exec($ch);

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

    /**
     * pgsql support pass: was hardcoded to a raw mysqli connection --
     * DbConnection::build()'s portable DBAL Connection handles a single
     * scalar fetch identically on both platforms, no raw driver dispatch
     * needed for a plain SELECT. Every real caller queries a numeric
     * column (id/COUNT(*)); mysqli's own raw fetch always returned these
     * as strings, but DBAL's own fetchOne() may return a native int/float
     * per platform/driver -- stringified here so this method's own return
     * contract stays exactly what every real caller already expects,
     * regardless of which native type the driver happens to hand back.
     */
    protected function queryScalar(string $sql): string
    {
        $value = DbConnection::build()->fetchOne($sql);
        self::assertTrue(is_scalar($value), 'queryScalar() expects a single scalar column value');

        return (string) $value;
    }

    /**
     * {@see queryScalar()}'s own counterpart for a database OTHER than
     * the one DbCredentials::fromEnv() currently points at (e.g. a
     * disposable backup/restore scratch database) -- DbConnection::build()
     * has no dbname override, so this needs the same raw, driver-native
     * connection {@see newMysqli()}/{@see newPgsqlConnection()} already
     * give MySQL/pgsql-targeted tests, dispatched per platform.
     */
    protected function queryScalarFromDatabase(string $dbName, string $sql): ?string
    {
        if ($this->dbDriver === 'pgsql') {
            $conn = $this->newPgsqlConnection($dbName);
            $result = pg_query($conn, $sql);
            self::assertNotFalse($result, 'pg_query failed');
            $row = pg_fetch_row($result);
            pg_close($conn);

            return $row === false ? null : $row[0];
        }

        $db = $this->newMysqli($dbName);
        $result = $db->query($sql);
        self::assertInstanceOf(mysqli_result::class, $result);
        $row = $result->fetch_row();
        $db->close();
        if (! is_array($row)) {
            return null;
        }
        self::assertIsString($row[0]);

        return $row[0];
    }
}
