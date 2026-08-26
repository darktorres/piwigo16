<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use LogicException;
use Override;
use Piwigo\Admin\Maintenance\DbMaintenanceRepository;
use Piwigo\Command\MaintenanceOrphanTagsCommand;
use Piwigo\Config\ConfigLoader;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\Kernel;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Tests\Support\DbTransactionTestOverride;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class MaintenanceOrphanTagsCommandTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

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

    public function testDeletesAnOrphanTagAndReportsTheCount(): void
    {
        $conn = DbConnection::build();
        $conn->createQueryBuilder()
            ->insert('tags')
            ->values([
                'name' => ':name',
                'url_name' => ':urlName',
                'lastmodified' => ':lastmodified',
            ])
            ->setParameter('name', 'cli-orphan-tag')
            ->setParameter('urlName', 'cli-orphan-tag')
            ->setParameter('lastmodified', '2020-01-01 00:00:00')
            ->executeStatement();

        try {
            $command = new MaintenanceOrphanTagsCommand(new DbMaintenanceRepository(EntityManagerFactory::build($conn)));
            $tester = new CommandTester($command);

            $exitCode = $tester->execute([]);

            self::assertSame(Command::SUCCESS, $exitCode);
            self::assertStringContainsString('Deleted 1 orphan tag(s).', $tester->getDisplay());
        } finally {
            $conn->createQueryBuilder()
                ->delete('tags')
                ->where('name = :name')
                ->setParameter('name', 'cli-orphan-tag')
                ->executeStatement();
        }
    }
}
