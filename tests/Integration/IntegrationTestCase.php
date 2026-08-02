<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Events;
use Doctrine\ORM\ORMSetup;
use PHPUnit\Framework\TestCase;
use Piwigo\Config\ConfigEntry;
use Piwigo\Config\ConfigRepository;
use Piwigo\Core\CurrentPaths;
use Piwigo\Core\FilesystemHelper;
use Piwigo\Core\Paths;
use Piwigo\Db\DbConnection;
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
        \Piwigo\Core\ProcessCache::reset();
        // Piwigo\Users\CurrentUser (Legacy Coupling Retirement Track A batch
        // A3) is a request-lifetime singleton; PHPUnit/Pest run every test
        // file in one shared process (see this class's own docblock above
        // for the identical ProcessCache reasoning), so each test gets
        // a fresh guest baseline here -- idempotent, so a subclass's own
        // setUp() calling CurrentUser::set() with a specific fixture user
        // right after parent::setUp() simply overwrites it.
        \Piwigo\Users\CurrentUser::attachGlobals();
        // Piwigo\Core\CurrentLogger (Legacy Coupling Retirement Track A
        // gap-fill batch G5) is the same shape of per-request singleton --
        // tests that construct a domain service directly (not through a
        // real HTTP request, so RequestBootstrap::connect() never runs)
        // need a real instance too, or the first CurrentLogger::get() call
        // throws. severity => OFF makes every log call an immediate no-op
        // (Logger::log() checks severity() >= $level, and OFF is -1, below
        // every real level), so this never touches the filesystem.
        \Piwigo\Core\CurrentLogger::set(new \Piwigo\Core\Logger(['severity' => \Piwigo\Core\Logger::OFF]));
        // Piwigo\Core\FilterState (Phase 2 global-residual sweep) is the
        // same shape of per-request singleton -- a disabled-filter
        // baseline here means tests that construct a domain service
        // directly (not through a real HTTP request, so
        // RequestBootstrap::finalize() never runs) still get a valid,
        // non-throwing FilterState::isEnabled()/visibleCategories()/etc.
        // A subclass's own setUp() calling FilterState::set() with real
        // filter values right after parent::setUp() simply overwrites it.
        \Piwigo\Core\FilterState::set(false);
        // Piwigo\Core\CurrentPaths (Legacy Coupling Retirement gap-closure,
        // entry-shell define()/include round) is the same shape of
        // per-request singleton -- tests that construct a domain service
        // directly (not through a real HTTP request, so no root index.php
        // ever calls Kernel::boot($paths)) need a real Paths available too,
        // or the first CurrentPaths::get() call throws. dirname(__DIR__, 2)
        // from tests/Integration/ is this project's own repo root, matching
        // every fixture path (e.g. MetadataServiceTest's 'path' => '_data/...')
        // already written relative to it.
        CurrentPaths::set(Paths::fromRoot(dirname(__DIR__, 2)));
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
        \Piwigo\Users\CurrentUser::reset();
        \Piwigo\Core\CurrentLogger::reset();
        \Piwigo\Core\ProcessCache::reset();
        \Piwigo\Config\CurrentConfig::reset();
        // Harmless even for test classes that never call
        // buildConfigRepository()/wire CurrentConfigService::set() at
        // all -- reset() on an already-unset registry is a no-op.
        \Piwigo\Config\CurrentConfigService::reset();
        \Piwigo\Core\PageState::reset();
        \Piwigo\Core\FilterState::reset();
        CurrentPaths::reset();
        // Legacy Coupling Retirement gap-closure (entry-shell define()/
        // include round, Part 0b) -- same per-request-singleton shape as
        // the resets above; harmless even for test classes that never
        // call mark() at all, same reasoning as CurrentConfigService::
        // reset() above.
        \Piwigo\Core\AdminContext::reset();
        \Piwigo\Core\WsContext::reset();
        \Piwigo\Core\InstallationFlag::reset();
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
        $em->getEventManager()->addEventListener(Events::loadClassMetadata, new TablePrefixListener());
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
        $baseUrl  = getenv('PIWIGO_BASE_URL');

        $this->dbHost   = $dbHost !== false ? $dbHost : '127.0.0.1';
        $this->dbUser   = $dbUser !== false ? $dbUser : '';
        $this->dbPass   = $dbPass !== false ? $dbPass : '';
        $this->dbName   = $dbName !== false ? $dbName : '';
        $this->dbPrefix = $dbPrefix !== false ? $dbPrefix : 'piwigo_';
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
        $db = $this->newMysqli('');
        $db->query(sprintf('DROP DATABASE IF EXISTS `%s`', $this->dbName));
        $db->query(sprintf('CREATE DATABASE `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci', $this->dbName));
        $db->close();
    }

    protected function loadFixture(string $path): void
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

        // The raw `mysql` import above never goes through ConfigService,
        // so its own confUpdateParam()/confDeleteParam() cache-clearing
        // never fires -- without this, any real server process sharing
        // this filesystem-backed cache (see setUp()'s own comment) would
        // keep serving whatever config rows it cached before this fixture
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
            $db = $this->newMysqli($this->dbName);
            if ($db->connect_errno === 0) {
                $result = $db->query(sprintf('SELECT COUNT(*) FROM `%simages`', $this->dbPrefix));
                if ($result !== false) {
                    $db->close();
                    return;
                }
            }
            $db->close();
            usleep(100_000);
        }
        self::fail('Test database did not become queryable within 30s after fixture load.');
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
