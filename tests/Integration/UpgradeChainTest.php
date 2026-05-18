<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

final class UpgradeChainTest extends IntegrationTestCase
{
    private const string FIXTURE       = __DIR__ . '/../../dev/fixtures/piwigo-17.0.sql';
    private const string FIXTURE_PRE15 = __DIR__ . '/../../dev/fixtures/piwigo-15.x.sql';

    #[\Override]
    protected function setUp(): void
    {
        $this->setUpConnectionFromEnv();
        $this->requireBaseUrl();
        $this->resetDatabase();
        $this->loadFixture(self::FIXTURE);
        // This class manages its own DB lifecycle (loads pre-15 SQL on
        // top of the fixture) so the schema diverges from the template.
        // Force the next IntegrationTestCase setUp to do a full reload.
        $this->markSchemaDirty();
        $this->markTestInstalled();
    }

    public function test_upgrade_from_pre15x_dump_returns_409(): void
    {
        // setUp() loaded the 16.x fixture; apply the pre-15.x patch on top.
        $this->loadFixture(self::FIXTURE_PRE15);

        $chRaw = curl_init($this->baseUrl . '/index.php?/upgrade');
        self::assertNotFalse($chRaw, 'curl_init failed');
        $ch = $chRaw;
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER     => self::TEST_HEADER,
        ]);
        $execResult = curl_exec($ch);
        $body       = is_string($execResult) ? $execResult : '';
        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        unset($ch);

        self::assertSame(409, $statusCode, 'index.php?/upgrade must return 409 for pre-15.x databases');
        self::assertStringContainsString('Upgrade refused', $body);
    }

    public function test_upgrade_from_16x_dump_lands_on_current_version(): void
    {
        $ch2Raw = curl_init($this->baseUrl . '/index.php?/upgrade');
        self::assertNotFalse($ch2Raw, 'curl_init failed');
        $ch = $ch2Raw;
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'username' => 'fixture_admin',
                'password' => 'fixture_admin',
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER     => self::TEST_HEADER,
        ]);
        curl_exec($ch);
        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        unset($ch); // curl_close() deprecated in PHP 8.5; unset triggers cleanup equivalently

        self::assertSame(200, $statusCode, 'index.php?/upgrade must return 200');

        // piwigo_config.value is a JSON column (F7-a); JSON_UNQUOTE strips the
        // JSON-string wrapping so the assertion stays on the decoded value.
        $version = $this->queryScalar(
            "SELECT JSON_UNQUOTE(value) FROM piwigo_config WHERE param = 'piwigo_db_version'"
        );
        // AppInfo::branchFromVersion('17.0.0') returns '17' (first segment only, per Piwigo ≥ 11 convention)
        self::assertSame('17', $version, 'index.php?/upgrade must land on current branch version');
    }
}
