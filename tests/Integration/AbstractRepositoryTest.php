<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Doctrine\DBAL\Connection;
use Piwigo\Config\Config;
use Piwigo\Config\ConfigLoader;
use Piwigo\Db\AbstractRepository;
use Piwigo\Db\DbConnection;

final class AbstractRepositoryTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpConnectionFromEnv();

        // Real pre-existing gap this surfaced: unlike every other
        // Integration test class, this one never called resetDatabase()/
        // loadFixture() itself, silently relying on some earlier test
        // class (alphabetically) having already created the database --
        // real, reproducible failure ("Unknown database 'piwigo_test'")
        // when this class happens to run first against a fresh DB
        // (confirmed via a full, unfiltered `composer test:integration`
        // run).
        if (! self::$fixtureReady) {
            $this->resetDatabase();
            $this->loadFixture(dirname(__DIR__, 2) . '/tests/Fixtures/piwigo-17.0.sql');
            self::$fixtureReady = true;
        }

        Config::reset();
        ConfigLoader::applyDefaults();
        ConfigLoader::applyEnvOverrides();
    }

    public function testConnectionIsStoredAndUsable(): void
    {
        $conn = DbConnection::build();
        $repo = new class ($conn) extends AbstractRepository {
            public function conn(): Connection
            {
                return $this->conn;
            }
        };

        self::assertSame($conn, $repo->conn());

        $value = $repo->conn()->fetchOne('SELECT 1');
        self::assertTrue(is_int($value) || is_string($value));
        self::assertSame(1, (int) $value);
    }
}
