<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Shared database infrastructure for integration tests.
 * Subclasses read connection details from environment variables set by CI/docker-compose.
 */
abstract class IntegrationTestCase extends TestCase
{
    protected string $dbHost;
    protected int $dbPort;
    protected string $dbUser;
    protected string $dbPass;
    protected string $dbName;
    protected string $webDbHost;
    protected string $baseUrl;

    protected function setUpConnectionFromEnv(): void
    {
        $this->dbHost    = (string) (getenv('PIWIGO_DB_HOST') ?: '127.0.0.1');
        $this->dbPort    = (int)    (getenv('PIWIGO_DB_PORT') ?: 3306);
        $this->dbUser    = (string) (getenv('PIWIGO_DB_USER') ?: 'piwigo');
        $this->dbPass    = (string) (getenv('PIWIGO_DB_PASSWORD') ?: 'piwigo');
        $this->dbName    = (string) (getenv('PIWIGO_DB_BASE') ?: 'piwigo_test');
        $this->webDbHost = (string) (getenv('PIWIGO_WEB_DB_HOST') ?: 'db');
        $this->baseUrl   = rtrim((string) (getenv('PIWIGO_BASE_URL') ?: 'http://localhost:8080'), '/');
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
        self::assertFileExists($path, 'Fixture file must exist — run dev/fixtures/README.md instructions to generate it');

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
        $d = '$';
        $cfg = sprintf(
            "<?php\n{$d}conf['dblayer'] = 'mysqli';\n{$d}conf['db_host'] = '%s';\n{$d}conf['db_user'] = '%s';\n{$d}conf['db_password'] = '%s';\n{$d}conf['db_base'] = '%s';\n{$d}prefixeTable = 'piwigo_';\ndefine('PHPWG_INSTALLED', true);\ndefine('PWG_CHARSET', 'utf-8');\ndefine('DB_CHARSET', 'utf8');\ndefine('DB_COLLATE', '');\n?>",
            addslashes($this->webDbHost),
            addslashes($this->dbUser),
            addslashes($this->dbPass),
            addslashes($this->dbName),
        );
        file_put_contents($dir . '/database.inc.php', $cfg);
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
