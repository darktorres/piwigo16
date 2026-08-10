<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Override;
use Doctrine\Migrations\Version\Version;
use Piwigo\Migrations\UpgradePathProbe\Version00000000000002;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Column;
use Doctrine\Migrations\Configuration\EntityManager\ExistingEntityManager;
use Doctrine\Migrations\Configuration\Migration\ConfigurationArray;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Tools\Console\Command\MigrateCommand;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Migrations\UpgradePathProbe\Version00000000000001;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Doctrine's own migration engine is already well-tested upstream -- this
 * proves *this app's* wiring instead: Piwigo\Db\MigrationDependencyFactory's
 * construction recipe (the same one InstallWizard::performInstall() and
 * config/container.php's DependencyFactory::class entry both use), the
 * ledger-table naming, and MigrateCommand's non-interactive ArrayInput
 * invocation, all correctly apply an incremental migration
 * against an already-migrated database -- the real upgrade-path shape
 * (bin/piwigo migrations:migrate against an existing install), not just a
 * fresh-install run.
 *
 * Runs against whichever engine .env.test currently points at (via the
 * shared DbConnection::build()) -- deliberately independent of
 * IntegrationTestCase's still mysqli-hardcoded newMysqli()/resetDatabase()
 * helpers, so this test doesn't need IntegrationTestCase's still-pending
 * driver-awareness fixes to already work on both platforms.
 *
 * Uses its own migrations_paths namespace/directory
 * (tests/Fixtures/Migrations/UpgradePathProbe/, 2 throwaway migrations)
 * and its own dedicated table_storage ledger table -- wholly separate
 * from the real src/Piwigo/Migrations/ baseline and its migration
 * history, so this never touches the real schema or its ledger.
 */
final class MigrationUpgradePathTest extends IntegrationTestCase
{
    private const string LEDGER_TABLE_NAME = 'migration_versions_upgrade_probe';

    private Connection $conn;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->conn = DbConnection::build();
        $this->dropProbeTables();
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->dropProbeTables();
        parent::tearDown();
    }

    /**
     * Deliberately duplicates Version00000000000001::probeTable()'s own
     * one-line computation rather than calling it -- this runs from
     * setUp(), before Doctrine's own migration finder has required that
     * fixture file (only buildDependencyFactory()/runMigrate() below
     * trigger that), so the class doesn't exist yet at this point.
     */
    /**
     * @return non-empty-string
     */
    private function probeTable(): string
    {
        return 'migration_upgrade_probe';
    }

    private function dropProbeTables(): void
    {
        $this->conn->executeStatement('DROP TABLE IF EXISTS ' . $this->probeTable());
        $this->conn->executeStatement('DROP TABLE IF EXISTS ' . self::LEDGER_TABLE_NAME);
    }

    private function buildDependencyFactory(): DependencyFactory
    {
        $em = EntityManagerFactory::build($this->conn);
        $config = new ConfigurationArray([
            'migrations_paths' => [
                'Piwigo\\Migrations\\UpgradePathProbe' => dirname(__DIR__) . '/Fixtures/Migrations/UpgradePathProbe',
            ],
            'table_storage' => [
                'table_name' => self::LEDGER_TABLE_NAME,
            ],
        ]);

        return DependencyFactory::fromEntityManager($config, new ExistingEntityManager($em));
    }

    private function runMigrate(DependencyFactory $dependencyFactory, string $version): int
    {
        $input = new ArrayInput(['version' => $version, '--allow-no-migration' => true]);
        $input->setInteractive(false);

        return new MigrateCommand($dependencyFactory)->run($input, new BufferedOutput());
    }

    /**
     * @param non-empty-string $tableName
     * @return list<string>
     */
    private function columnNames(string $tableName): array
    {
        $columns = $this->conn->createSchemaManager()->introspectTableColumnsByUnquotedName($tableName);

        return array_map(static function (Column $column): string {
            $objectName = $column->getObjectName();

            return $objectName->getIdentifier()->getValue();
        }, $columns);
    }

    public function test_migrate_applies_only_the_delta_against_an_already_migrated_database(): void
    {
        $dependencyFactory = $this->buildDependencyFactory();

        $firstExitCode = $this->runMigrate($dependencyFactory, Version00000000000001::class);
        self::assertSame(0, $firstExitCode);

        $columnsAfterFirstRun = $this->columnNames($this->probeTable());
        self::assertContains('id', $columnsAfterFirstRun);
        self::assertNotContains('probe_value', $columnsAfterFirstRun);

        $executedAfterFirstRun = $dependencyFactory->getMetadataStorage()->getExecutedMigrations();
        self::assertCount(1, $executedAfterFirstRun);

        // The real "upgrade an already-migrated install" case: a second
        // migrate() call, still pointed at the exact same DependencyFactory/
        // connection/ledger table, now resolving 'latest' to the delta
        // migration registered above -- proving it applies ONLY that delta
        // (not a full re-run: Version00000000000001's own CREATE TABLE
        // would fail outright against a table that already exists).
        $secondExitCode = $this->runMigrate($dependencyFactory, 'latest');
        self::assertSame(0, $secondExitCode);

        $columnsAfterSecondRun = $this->columnNames($this->probeTable());
        self::assertContains('probe_value', $columnsAfterSecondRun);

        $executedAfterSecondRun = $dependencyFactory->getMetadataStorage()->getExecutedMigrations();
        self::assertCount(2, $executedAfterSecondRun);
        self::assertTrue($executedAfterSecondRun->hasMigration(new Version(Version00000000000001::class)));
        self::assertTrue($executedAfterSecondRun->hasMigration(new Version(Version00000000000002::class)));
    }
}
