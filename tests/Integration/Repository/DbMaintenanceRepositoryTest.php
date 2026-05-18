<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration\Repository;

use Doctrine\DBAL\Connection;
use Piwigo\Db\DbMaintenanceRepository;
use Piwigo\Tests\Integration\IntegrationTestCase;

/**
 * Real-DB integration tests for {@see DbMaintenanceRepository}. The repo
 * does DDL-adjacent work (SHOW TABLES, DESC, REPAIR/OPTIMIZE) so the
 * tests must run against a real database — there's nothing meaningful
 * to mock here.
 */
final class DbMaintenanceRepositoryTest extends IntegrationTestCase
{
    private const string FIXTURE = __DIR__ . '/../../../dev/fixtures/piwigo-17.0.sql';

    private Connection $conn;
    private DbMaintenanceRepository $repo;

    #[\Override]
    protected function setUp(): void
    {
        $this->setUpConnectionFromEnv();
        $this->resetDatabase();
        $this->loadFixture(self::FIXTURE);
        $this->conn = $this->newDbalConnection();
        $this->repo = new DbMaintenanceRepository($this->conn, 'piwigo_');
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->conn->close();
    }

    public function test_findAllPiwigoTableNames_lists_seeded_tables(): void
    {
        $tables = $this->repo->findAllPiwigoTableNames();

        self::assertContains('piwigo_users', $tables);
        self::assertContains('piwigo_categories', $tables);
        self::assertContains('piwigo_images', $tables);
        self::assertContains('piwigo_config', $tables);
    }

    public function test_findPrimaryKeyColumns_returns_single_column_pk(): void
    {
        $cols = $this->repo->findPrimaryKeyColumns('piwigo_users');
        self::assertSame(['id'], $cols);
    }

    public function test_findPrimaryKeyColumns_returns_composite_pk(): void
    {
        // piwigo_image_category PRIMARY KEY (image_id, category_id)
        $cols = $this->repo->findPrimaryKeyColumns('piwigo_image_category');
        sort($cols);
        self::assertSame(['category_id', 'image_id'], $cols);
    }

    public function test_findTableFingerprint_returns_max_ts_underscore_count_format(): void
    {
        $fingerprint = $this->repo->findTableFingerprint('piwigo_categories');

        // Format: "<unix_ts>_<count>" — 2 albums in fixture.
        self::assertMatchesRegularExpression('/^\d+_2$/', $fingerprint);
    }

    public function test_optimizeTables_round_trips_on_real_tables(): void
    {
        // OPTIMIZE TABLE returns a status row per table; the call must
        // succeed without error against the schema's InnoDB tables.
        $this->repo->optimizeTables(['piwigo_users', 'piwigo_categories']);

        // Sanity-check the tables are still queryable after the OPTIMIZE.
        $userCount = $this->conn->executeQuery('SELECT COUNT(*) FROM piwigo_users')->fetchOne();
        self::assertGreaterThan(0, is_numeric($userCount) ? (int) $userCount : 0);
    }
}
