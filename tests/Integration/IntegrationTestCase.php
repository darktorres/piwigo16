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
 * Each test class writes a fresh local/config/database.inc.php pointing at the
 * test database and deletes it in tearDown, leaving a clean slate.
 */
abstract class IntegrationTestCase extends TestCase
{
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

    protected function writeDatabaseConfig(): void
    {
        $dir = __DIR__ . '/../../local/config';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $host = $this->dbPort ? "{$this->dbHost}:{$this->dbPort}" : $this->dbHost;
        $d = '$';
        file_put_contents($dir . '/database.inc.php', sprintf(
            "<?php\n{$d}conf['dblayer'] = 'mysqli';\n{$d}conf['db_host'] = '%s';\n{$d}conf['db_user'] = '%s';\n{$d}conf['db_password'] = '%s';\n{$d}conf['db_base'] = '%s';\n{$d}prefixeTable = 'piwigo_';\ndefine('PHPWG_INSTALLED', true);\ndefine('PWG_CHARSET', 'utf-8');\ndefine('DB_CHARSET', 'utf8');\ndefine('DB_COLLATE', '');\n?>",
            addslashes($host),
            addslashes($this->dbUser),
            addslashes($this->dbPass),
            addslashes($this->dbName),
        ));
    }

    protected function removeDatabaseConfig(): void
    {
        $path = __DIR__ . '/../../local/config/database.inc.php';
        if (file_exists($path)) {
            unlink($path);
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
