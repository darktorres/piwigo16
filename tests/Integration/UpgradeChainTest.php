<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class UpgradeChainTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/../../dev/fixtures/piwigo-16.x.sql';

    private string $dbHost;
    private int $dbPort;
    private string $dbUser;
    private string $dbPass;
    private string $dbName;
    private string $webDbHost;
    private string $baseUrl;

    protected function setUp(): void
    {
        $this->dbHost   = (string) (getenv('PIWIGO_DB_HOST') ?: '127.0.0.1');
        $this->dbPort   = (int)    (getenv('PIWIGO_DB_PORT') ?: 3306);
        $this->dbUser   = (string) (getenv('PIWIGO_DB_USER') ?: 'piwigo');
        $this->dbPass   = (string) (getenv('PIWIGO_DB_PASSWORD') ?: 'piwigo');
        $this->dbName   = (string) (getenv('PIWIGO_DB_BASE') ?: 'piwigo_test');
        // Hostname as seen from *inside* the web container (Docker service name).
        $this->webDbHost = (string) (getenv('PIWIGO_WEB_DB_HOST') ?: 'db');
        $this->baseUrl  = rtrim((string) (getenv('PIWIGO_BASE_URL') ?: 'http://localhost:8080'), '/');

        $this->resetDatabase();
        $this->loadFixture(self::FIXTURE);
        $this->writeDatabaseConfig();
    }

    protected function tearDown(): void
    {
        $this->removeDatabaseConfig();
    }

    public function test_upgrade_from_16x_dump_lands_on_current_version(): void
    {
        $ch = curl_init($this->baseUrl . '/upgrade.php');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'username' => 'fixture_admin',
                'password' => 'fixture_admin',
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        $statusCode = (int) curl_getinfo(curl_exec($ch) !== false ? $ch : $ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        self::assertSame(200, $statusCode, 'upgrade.php must return 200');

        $version = $this->queryScalar(
            "SELECT value FROM piwigo_config WHERE param = 'piwigo_db_version'"
        );
        // get_branch_from_version('16.3.0') returns '16' (first segment only, per Piwigo ≥ 11 convention)
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
        self::assertFileExists($path, 'Fixture file must exist — run dev/fixtures/README.md instructions to generate it');

        $proc = new Process([
            'mysql',
            "-h{$this->dbHost}",
            "-P{$this->dbPort}",
            "-u{$this->dbUser}",
            "-p{$this->dbPass}",
            $this->dbName,
        ]);
        $proc->setInput(file_get_contents($path));
        $proc->mustRun();
    }

    private function writeDatabaseConfig(): void
    {
        $dir = __DIR__ . '/../../local/config';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        // Uses webDbHost (the in-container service name) so upgrade.php can reach the DB.
        $cfg = sprintf(
            "<?php\n\$conf['dblayer'] = 'mysqli';\n\$conf['db_host'] = '%s';\n\$conf['db_user'] = '%s';\n\$conf['db_password'] = '%s';\n\$conf['db_base'] = '%s';\n\$prefixeTable = 'piwigo_';\ndefine('PHPWG_INSTALLED', true);\ndefine('PWG_CHARSET', 'utf-8');\ndefine('DB_CHARSET', 'utf8');\ndefine('DB_COLLATE', '');\n?>",
            addslashes($this->webDbHost),
            addslashes($this->dbUser),
            addslashes($this->dbPass),
            addslashes($this->dbName),
        );
        file_put_contents($dir . '/database.inc.php', $cfg);
    }

    private function removeDatabaseConfig(): void
    {
        $path = __DIR__ . '/../../local/config/database.inc.php';
        if (file_exists($path)) {
            unlink($path);
        }
    }

    private function queryScalar(string $sql): string
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
