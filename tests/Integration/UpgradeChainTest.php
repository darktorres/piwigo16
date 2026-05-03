<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

final class UpgradeChainTest extends IntegrationTestCase
{
    private const FIXTURE       = __DIR__ . '/../../dev/fixtures/piwigo-16.x.sql';
    private const FIXTURE_PRE15 = __DIR__ . '/../../dev/fixtures/piwigo-15.x.sql';

    protected function setUp(): void
    {
        $this->setUpConnectionFromEnv();
        $this->resetDatabase();
        $this->loadFixture(self::FIXTURE);
        $this->writeRuntimeConfig();
    }

    protected function tearDown(): void
    {
        $this->restoreRuntimeConfig();
    }

    public function test_upgrade_from_pre15x_dump_returns_409(): void
    {
        // setUp() loaded the 16.x fixture; apply the pre-15.x patch on top.
        $this->loadFixture(self::FIXTURE_PRE15);

        $ch = curl_init($this->baseUrl . '/upgrade.php');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        $body       = (string) curl_exec($ch);
        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        unset($ch);

        self::assertSame(409, $statusCode, 'upgrade.php must return 409 for pre-15.x databases');
        self::assertStringContainsString('Upgrade refused', $body);
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
        unset($ch); // curl_close() deprecated in PHP 8.5; unset triggers cleanup equivalently

        self::assertSame(200, $statusCode, 'upgrade.php must return 200');

        $version = $this->queryScalar(
            "SELECT value FROM piwigo_config WHERE param = 'piwigo_db_version'"
        );
        // get_branch_from_version('16.3.0') returns '16' (first segment only, per Piwigo ≥ 11 convention)
        self::assertSame('16', $version, 'upgrade.php must land on current branch version');
    }
}
