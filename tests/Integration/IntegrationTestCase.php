<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Shared database infrastructure for integration tests.
 *
 * Requires these environment variables (set in shell or .env.local):
 *
 *   PIWIGO_DB_HOST      MySQL host reachable by the test runner (default: 127.0.0.1)
 *   PIWIGO_DB_PORT      MySQL port (default: 3306)
 *   PIWIGO_DB_USER      MySQL user
 *   PIWIGO_DB_PASSWORD  MySQL password
 *   PIWIGO_DB_BASE      Test database name — never the production DB (default: piwigo_test)
 *   PIWIGO_BASE_URL     Base URL of the running Apache Piwigo instance
 *
 * Each test class writes a fresh .env at the repo root pointing at the test
 * database and restores any pre-existing .env in tearDown, leaving a clean
 * slate. Apache reads .env on every request via Piwigo\Config\ConfigLoader,
 * so this is what flips the runtime DB pointer for the duration of a test.
 */
abstract class IntegrationTestCase extends TestCase
{
    protected string $dbHost;
    protected int $dbPort;
    protected string $dbUser;
    protected string $dbPass;
    protected string $dbName;
    protected string $baseUrl;

    private const ENV_PATH        = __DIR__ . '/../../.env';
    private const ENV_BACKUP_PATH = __DIR__ . '/../../.env.test-backup';
    private const STAMP_PATH      = __DIR__ . '/../../local/.installed';

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
     * Writes a fresh .env pointing at the test DB and creates the install
     * stamp. Backs up any pre-existing .env so the user's runtime config
     * can be restored in tearDown.
     */
    protected function writeRuntimeConfig(): void
    {
        if (file_exists(self::ENV_PATH) && !file_exists(self::ENV_BACKUP_PATH)) {
            rename(self::ENV_PATH, self::ENV_BACKUP_PATH);
        }
        $host = $this->dbPort ? "{$this->dbHost}:{$this->dbPort}" : $this->dbHost;
        file_put_contents(self::ENV_PATH, sprintf(
            "PIWIGO_DB_HOST=%s\nPIWIGO_DB_USER=%s\nPIWIGO_DB_PASSWORD=%s\nPIWIGO_DB_BASE=%s\nPIWIGO_DB_PREFIX=piwigo_\n",
            $host,
            $this->dbUser,
            $this->dbPass,
            $this->dbName,
        ));

        $stampDir = dirname(self::STAMP_PATH);
        if (!is_dir($stampDir)) {
            mkdir($stampDir, 0755, true);
        }
        touch(self::STAMP_PATH);
    }

    protected function restoreRuntimeConfig(): void
    {
        if (file_exists(self::ENV_PATH)) {
            unlink(self::ENV_PATH);
        }
        if (file_exists(self::ENV_BACKUP_PATH)) {
            rename(self::ENV_BACKUP_PATH, self::ENV_PATH);
        }
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
