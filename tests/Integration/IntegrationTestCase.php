<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Piwigo\Core\InstallSentinel;
use Symfony\Component\Process\Process;

/**
 * Shared database infrastructure for integration tests.
 *
 * Requires these environment variables (loaded from .env.test by
 * tests/bootstrap.php):
 *
 *   PIWIGO_DB_HOST      MySQL host reachable by the test runner
 *   PIWIGO_DB_PORT      MySQL port (default: 3306)
 *   PIWIGO_DB_USER      MySQL user
 *   PIWIGO_DB_PASSWORD  MySQL password
 *   PIWIGO_DB_BASE      Test database name — never the production DB
 *   PIWIGO_BASE_URL     Base URL of the running Apache Piwigo instance
 *
 * Apache request routing: every HTTP call from these tests carries the
 * `X-Piwigo-Env: test` header (see TEST_HEADER), which makes the runtime
 * read .env.test and use local/.installed.test instead of the prod
 * counterparts. Tests therefore never swap files on disk — prod config
 * stays untouched even if a test crashes.
 */
abstract class IntegrationTestCase extends TestCase
{
    /** Header line array suitable for CURLOPT_HTTPHEADER. */
    protected const TEST_HEADER = ['X-Piwigo-Env: test'];

    protected string $dbHost;
    protected int $dbPort;
    protected string $dbUser;
    protected string $dbPass;
    protected string $dbName;
    protected string $baseUrl;

    protected function setUpConnectionFromEnv(): void
    {
        $this->dbHost  = (string) getenv('PIWIGO_DB_HOST');
        $this->dbPort  = (int)    getenv('PIWIGO_DB_PORT');
        $this->dbUser  = (string) getenv('PIWIGO_DB_USER');
        $this->dbPass  = (string) getenv('PIWIGO_DB_PASSWORD');
        $this->dbName  = (string) getenv('PIWIGO_DB_BASE');
        $this->baseUrl = rtrim((string) getenv('PIWIGO_BASE_URL'), '/');
    }

    protected function resetDatabase(): void
    {
        $db = new \mysqli($this->dbHost, $this->dbUser, $this->dbPass, '', $this->dbPort);
        $db->query("DROP DATABASE IF EXISTS `{$this->dbName}`");
        $db->query("CREATE DATABASE `{$this->dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $db->close();
    }

    protected function loadFixture(string $path): void
    {
        self::assertFileExists($path, 'Fixture file must exist');

        $proc = new Process([
            'mysql',
            "-h{$this->dbHost}",
            "-P{$this->dbPort}",
            "-u{$this->dbUser}",
            "-p{$this->dbPass}",
            $this->dbName,
        ]);
        $proc->setInput((string) file_get_contents($path));
        $proc->mustRun();
    }

    /**
     * Marks the test runtime as installed so subsequent HTTP requests
     * skip the install redirect. InstallSentinel writes
     * `local/.installed.test` (TestMode is active in CLI bootstrap).
     * Integration tests bypass the install.php form by loading SQL
     * fixtures directly, so they own the sentinel lifecycle.
     */
    protected function markTestInstalled(): void
    {
        if (!defined('PHPWG_ROOT_PATH')) {
            define('PHPWG_ROOT_PATH', __DIR__ . '/../../');
        }
        InstallSentinel::markInstalled();
    }

    protected function queryScalar(string $sql): string
    {
        $db = new \mysqli($this->dbHost, $this->dbUser, $this->dbPass, $this->dbName, $this->dbPort);
        $result = $db->query($sql);
        self::assertInstanceOf(\mysqli_result::class, $result);
        $row = $result->fetch_row();
        $db->close();
        self::assertIsArray($row);
        return (string) $row[0];
    }
}
