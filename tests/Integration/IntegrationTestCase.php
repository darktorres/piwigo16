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

    protected function setUpConnectionFromEnv(): void
    {
        $this->dbHost   = getenv('PIWIGO_DB_HOST') !== false ? getenv('PIWIGO_DB_HOST') : '127.0.0.1';
        $this->dbUser   = getenv('PIWIGO_DB_USER') !== false ? getenv('PIWIGO_DB_USER') : '';
        $this->dbPass   = getenv('PIWIGO_DB_PASSWORD') !== false ? getenv('PIWIGO_DB_PASSWORD') : '';
        $this->dbName   = getenv('PIWIGO_DB_BASE') !== false ? getenv('PIWIGO_DB_BASE') : '';
        $this->dbPrefix = getenv('PIWIGO_DB_PREFIX') !== false ? getenv('PIWIGO_DB_PREFIX') : 'piwigo_';
        $this->baseUrl  = rtrim(getenv('PIWIGO_BASE_URL') !== false ? getenv('PIWIGO_BASE_URL') : '', '/');
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
    }

    protected function markTestInstalled(): void
    {
        require_once dirname(__DIR__, 2) . '/include/env.inc.php';
        $stamp = dirname(__DIR__, 2) . '/local/' . pwg_test_mode_installed_stamp();
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
        require_once dirname(__DIR__, 2) . '/include/env.inc.php';
        $stamp = dirname(__DIR__, 2) . '/local/' . pwg_test_mode_installed_stamp();
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
