<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use PHPUnit\Framework\TestCase;

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
    }

    #[\Override]
    protected function tearDown(): void
    {
        \Piwigo\Users\CurrentUser::reset();
        \Piwigo\Core\CurrentLogger::reset();
        \Piwigo\Core\ProcessCache::reset();
        \Piwigo\Config\Config::reset();
        \Piwigo\Core\PageState::reset();
        parent::tearDown();
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

        return ['X-Piwigo-Env: ' . (is_string($value) ? $value : 'test')];
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
        self::assertSame(0, $exit, 'mysql fixture load failed: ' . $stderr);

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
