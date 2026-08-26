<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use LogicException;
use Override;
use Piwigo\Admin\Maintenance\DbMaintenanceRepository;
use Piwigo\Command\MaintenancePurgeHistoryCommand;
use Piwigo\Config\ConfigLoader;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\Kernel;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Tests\Support\DbTransactionTestOverride;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class MaintenancePurgeHistoryCommandTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

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

        // PILOT (transaction-wrapping rollout): begin before any container
        // resolution below -- see ApiKeyServiceGetAvailableTest.php's own
        // comment for the full reasoning.
        DbTransactionTestOverride::begin();

        $currentConfig = Kernel::container()->get(CurrentConfig::class);
        if (! $currentConfig instanceof CurrentConfig) {
            throw new LogicException('Container returned an unexpected type for ' . CurrentConfig::class);
        }
        $currentConfig->reset();
        ConfigLoader::applyDefaults();
        ConfigLoader::applyEnvOverrides();
    }

    #[Override]
    protected function tearDown(): void
    {
        DbTransactionTestOverride::rollback();
        parent::tearDown();
    }

    public function testPurgesHistoryDetailAndSummary(): void
    {
        $conn = DbConnection::build();
        $conn->createQueryBuilder()
            ->insert('history')
            ->values([
                'user_id' => ':userId',
            ])
            ->setParameter('userId', 1)
            ->executeStatement();

        $command = new MaintenancePurgeHistoryCommand(new DbMaintenanceRepository(EntityManagerFactory::build($conn)));
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        $remaining = $conn->createQueryBuilder()
            ->select('COUNT(*)')
            ->from('history')
            ->executeQuery()
            ->fetchOne();
        self::assertSame(0, is_numeric($remaining) ? (int) $remaining : -1);
    }
}
