<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Piwigo\Admin\Maintenance\DbMaintenanceRepository;
use Piwigo\Command\MaintenancePurgeHistoryCommand;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Tables;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class MaintenancePurgeHistoryCommandTest extends IntegrationTestCase
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
    }

    public function test_purges_history_detail_and_summary(): void
    {
        $conn = DbConnection::build();
        $conn->createQueryBuilder()
            ->insert(Tables::history())
            ->values(['user_id' => ':userId'])
            ->setParameter('userId', 1)
            ->executeStatement();

        $command = new MaintenancePurgeHistoryCommand(new DbMaintenanceRepository($conn));
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        $remaining = $conn->createQueryBuilder()
            ->select('COUNT(*)')
            ->from(Tables::history())
            ->executeQuery()
            ->fetchOne();
        self::assertSame(0, is_numeric($remaining) ? (int) $remaining : -1);
    }
}
