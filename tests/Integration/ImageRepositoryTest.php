<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Doctrine\DBAL\Connection;
use Piwigo\Config\Config;
use Piwigo\Config\ConfigLoader;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Tables;
use Piwigo\Image\ImageRepository;

final class ImageRepositoryTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    private ImageRepository $repo;

    private Connection $conn;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpConnectionFromEnv();

        if (! self::$fixtureReady) {
            $this->resetDatabase();
            $this->loadFixture(dirname(__DIR__, 2) . '/tests/Fixtures/piwigo-17.0.sql');
            self::$fixtureReady = true;
        }

        Config::reset();
        ConfigLoader::applyDefaults();
        ConfigLoader::applyEnvOverrides();

        $this->conn = DbConnection::build();
        $this->repo = new ImageRepository($this->conn);
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->conn->executeStatement('UPDATE ' . Tables::images() . " SET hit = 0 WHERE id IN (1, 2)");
        parent::tearDown();
    }

    public function test_increment_visit_counter_increments_hit(): void
    {
        $before = $this->conn->createQueryBuilder()
            ->select('hit')
            ->from(Tables::images())
            ->where('id = 1')
            ->executeQuery()
            ->fetchOne();
        $before = is_numeric($before) ? (int) $before : 0;

        $this->repo->incrementVisitCounter(1);

        $after = $this->conn->createQueryBuilder()
            ->select('hit')
            ->from(Tables::images())
            ->where('id = 1')
            ->executeQuery()
            ->fetchOne();
        $after = is_numeric($after) ? (int) $after : 0;

        self::assertSame($before + 1, $after);
    }

    public function test_increment_visit_counter_does_not_change_other_rows(): void
    {
        $before = $this->conn->createQueryBuilder()
            ->select('hit')
            ->from(Tables::images())
            ->where('id = 2')
            ->executeQuery()
            ->fetchOne();
        $before = is_numeric($before) ? (int) $before : 0;

        $this->repo->incrementVisitCounter(1);

        $after = $this->conn->createQueryBuilder()
            ->select('hit')
            ->from(Tables::images())
            ->where('id = 2')
            ->executeQuery()
            ->fetchOne();
        $after = is_numeric($after) ? (int) $after : 0;

        self::assertSame($before, $after);
    }
}
