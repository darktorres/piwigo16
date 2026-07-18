<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Piwigo\Admin\Maintenance\DbMaintenanceRepository;
use Piwigo\Command\MaintenancePurgeSessionsCommand;
use Piwigo\Config\Config;
use Piwigo\Config\ConfigLoader;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Tables;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class MaintenancePurgeSessionsCommandTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

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
    }

    public function test_purges_a_session_belonging_to_a_deleted_user(): void
    {
        $conn = DbConnection::build();
        $conn->createQueryBuilder()
            ->insert(Tables::sessions())
            ->values(['id' => ':id', 'data' => ':data', 'expiration' => ':expiration'])
            ->setParameter('id', 'cli-orphan-session')
            ->setParameter('data', 'pwg_uid|i:999999;')
            ->setParameter('expiration', '2030-01-01 00:00:00')
            ->executeStatement();

        try {
            $command = new MaintenancePurgeSessionsCommand(new DbMaintenanceRepository($conn));
            $tester = new CommandTester($command);

            $exitCode = $tester->execute([]);

            self::assertSame(Command::SUCCESS, $exitCode);
            $remaining = $conn->createQueryBuilder()
                ->select('id')
                ->from(Tables::sessions())
                ->where('id = :id')
                ->setParameter('id', 'cli-orphan-session')
                ->executeQuery()
                ->fetchOne();
            self::assertFalse($remaining, 'the orphan session must have been purged');
        } finally {
            $conn->createQueryBuilder()
                ->delete(Tables::sessions())
                ->where('id = :id')
                ->setParameter('id', 'cli-orphan-session')
                ->executeStatement();
        }
    }
}
