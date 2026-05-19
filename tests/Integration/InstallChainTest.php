<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

final class InstallChainTest extends IntegrationTestCase
{
    #[\Override]
    protected function setUp(): void
    {
        $this->setUpConnectionFromEnv();
        $this->requireBaseUrl();
        $this->resetDatabase();
        // install.php builds its own schema; ours diverges from the
        // template once it runs. Force a full reload on the next setUp.
        $this->markSchemaDirty();
        $stamp = $this->installedStampPath();
        if (file_exists($stamp)) {
            unlink($stamp);
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
            CURLOPT_HTTPHEADER     => $this->testHeader(),
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

        // piwigo_config.value is a JSON column (F7-a); JSON_UNQUOTE strips
        // the JSON-string wrapping so the assertion stays on the decoded value.
        $version = $this->queryScalar(
            "SELECT JSON_UNQUOTE(value) FROM piwigo_config WHERE param = 'piwigo_db_version'"
        );
        self::assertSame('17', $version, 'install must write piwigo_db_version = 17');

        self::assertFileExists(
            $this->installedStampPath(),
            'index.php?/install must create the .installed.test sentinel'
        );
    }

    /** Resolve the sentinel path through TestMode so paratest workers find their own file. */
    private function installedStampPath(): string
    {
        return __DIR__ . '/../../local/' . \Piwigo\Config\TestMode::installedStamp();
    }
}
