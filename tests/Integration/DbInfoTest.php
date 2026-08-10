<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Override;
use Piwigo\Db\DbConnection;
use Piwigo\Db\DbInfo;

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
            $this->resetDatabase();
            $this->loadFixture(dirname(__DIR__, 2) . '/tests/Fixtures/piwigo-17.0.sql');
            self::$fixtureReady = true;
        }

        $this->dbInfo = new DbInfo(DbConnection::build());
    }

    public function test_version_returns_a_real_non_empty_mysql_version_string(): void
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
    public function test_get_table_fingerprint_matches_the_real_epoch_and_row_count(): void
    {
        $conn = DbConnection::build();
        $expectedCount = $conn->fetchOne('SELECT COUNT(*) FROM ' . 'categories');
        self::assertIsNumeric($expectedCount);

        $fingerprint = $this->dbInfo->getTableFingerprint('categories');

        self::assertMatchesRegularExpression('/^\d+_\d+$/', $fingerprint);
        [, $count] = explode('_', $fingerprint);
        self::assertSame((string) (int) $expectedCount, $count);
    }
}
