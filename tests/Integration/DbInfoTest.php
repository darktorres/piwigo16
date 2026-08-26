<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Override;
use Piwigo\Db\DbConnection;
use Piwigo\Db\DbInfo;
use Piwigo\Tests\Support\DbTransactionTestOverride;

final class DbInfoTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    private DbInfo $dbInfo;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpConnectionFromEnv();

        if (! self::$fixtureReady) {
            $this->reimportFixtureIfSharedStateUnknown(dirname(__DIR__, 2) . '/tests/Fixtures/piwigo-17.0.sql');
            self::$fixtureReady = true;
        }

        // PILOT (transaction-wrapping rollout): begin before any container
        // resolution below -- see ApiKeyServiceGetAvailableTest.php's own
        // comment for the full reasoning.
        DbTransactionTestOverride::begin();

        $this->dbInfo = new DbInfo(DbConnection::build());
    }

    #[Override]
    protected function tearDown(): void
    {
        DbTransactionTestOverride::rollback();
        parent::tearDown();
    }

    public function testVersionReturnsARealNonEmptyMysqlVersionString(): void
    {
        $version = $this->dbInfo->version();

        self::assertNotSame('', $version);
        // A real MySQL/MariaDB version string always starts with a digit;
        // PostgreSQL's own SELECT version() output always starts with the
        // literal "PostgreSQL" instead.
        self::assertMatchesRegularExpression(
            $this->dbDriver === 'pgsql' ? '/^PostgreSQL/' : '/^\d/',
            $version
        );
    }

    /**
     * getTableFingerprint() computes its epoch expression differently per
     * platform; this asserts the real value matches on both MySQL and
     * Postgres, not just that a fingerprint string of the right shape
     * comes back.
     */
    public function testGetTableFingerprintMatchesTheRealEpochAndRowCount(): void
    {
        $conn = DbConnection::build();
        $expectedCount = $conn->fetchOne('SELECT COUNT(*) FROM categories');

        $fingerprint = $this->dbInfo->getTableFingerprint('categories');

        self::assertMatchesRegularExpression('/^\d+_\d+$/', $fingerprint);
        [, $count] = explode('_', $fingerprint);
        self::assertSame((string) $expectedCount, $count);
    }
}
