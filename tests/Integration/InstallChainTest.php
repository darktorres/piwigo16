<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

final class InstallChainTest extends IntegrationTestCase
{
    /** Absolute path to the test sentinel file (matches TestMode logic). */
    private const string INSTALLED_STAMP = __DIR__ . '/../../local/.installed.test';

    #[\Override]
    protected function setUp(): void
    {
        $this->setUpConnectionFromEnv();
        $this->requireBaseUrl();
        $this->resetDatabase();
        if (file_exists(self::INSTALLED_STAMP)) {
            unlink(self::INSTALLED_STAMP);
        }
    }

    #[\Override]
    protected function tearDown(): void
    {
        // Restore sentinel so other integration tests remain unaffected.
        $this->markTestInstalled();
    }

    public function test_fresh_install_creates_database_and_marks_installed(): void
    {
        $dbHostField = $this->dbHost;
        if ($this->dbPort > 0 && $this->dbPort !== 3306) {
            $dbHostField .= ':' . $this->dbPort;
        }

        $chRaw = curl_init($this->baseUrl . '/index.php?/install');
        self::assertNotFalse($chRaw, 'curl_init failed');
        $ch = $chRaw;
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query([
                'install'     => '1',
                'dbhost'      => $dbHostField,
                'dbuser'      => $this->dbUser,
                'dbpasswd'    => $this->dbPass,
                'dbname'      => $this->dbName,
                'prefix'      => 'piwigo_',
                'admin_name'  => 'install_admin',
                'admin_pass1' => 'install_pass',
                'admin_pass2' => 'install_pass',
                'admin_mail'  => 'admin@example.com',
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER     => self::TEST_HEADER,
        ]);
        $execResult = curl_exec($ch);
        $body       = is_string($execResult) ? $execResult : '';
        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        unset($ch);

        self::assertSame(200, $statusCode, 'index.php?/install must return 200 on successful install');

        self::assertStringContainsString(
            'Congratulations',
            $body,
            'install.php response must contain success message — body: ' . substr(strip_tags($body), 0, 500)
        );

        $version = $this->queryScalar(
            "SELECT value FROM piwigo_config WHERE param = 'piwigo_db_version'"
        );
        self::assertSame('16', $version, 'install must write piwigo_db_version = 16');

        self::assertFileExists(self::INSTALLED_STAMP, 'index.php?/install must create the .installed.test sentinel');
    }
}
