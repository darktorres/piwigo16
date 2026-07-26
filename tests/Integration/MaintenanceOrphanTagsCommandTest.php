<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Piwigo\Admin\Maintenance\DbMaintenanceRepository;
use Piwigo\Command\MaintenanceOrphanTagsCommand;
use Piwigo\Config\CurrentConfig;
use Piwigo\Config\ConfigLoader;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Tables;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class MaintenanceOrphanTagsCommandTest extends IntegrationTestCase
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

        CurrentConfig::reset();
        ConfigLoader::applyDefaults();
        ConfigLoader::applyEnvOverrides();
    }

    public function test_deletes_an_orphan_tag_and_reports_the_count(): void
    {
        $conn = DbConnection::build();
        $conn->createQueryBuilder()
            ->insert(Tables::tags())
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
            $command = new MaintenanceOrphanTagsCommand(new DbMaintenanceRepository(\Piwigo\Db\EntityManagerFactory::build($conn)));
            $tester = new CommandTester($command);

            $exitCode = $tester->execute([]);

            self::assertSame(Command::SUCCESS, $exitCode);
            self::assertStringContainsString('Deleted 1 orphan tag(s).', $tester->getDisplay());
        } finally {
            $conn->createQueryBuilder()
                ->delete(Tables::tags())
                ->where('name = :name')
                ->setParameter('name', 'cli-orphan-tag')
                ->executeStatement();
        }
    }
}
