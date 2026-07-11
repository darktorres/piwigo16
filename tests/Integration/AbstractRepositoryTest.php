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
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpConnectionFromEnv();

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
