<?php

declare(strict_types=1);

namespace Piwigo\Migrations\UpgradePathProbe;

use Override;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Test-only fixture for tests/Integration/MigrationUpgradePathTest.php --
 * proves this app's own DependencyFactory/ledger-table/MigrateCommand
 * wiring (Piwigo\Db\MigrationDependencyFactory, the same class
 * InstallWizard and config/container.php's DependencyFactory::class
 * entry both use) correctly applies an incremental migration against an
 * already-migrated database, on whichever engine .env.test currently
 * points at. Deliberately its own migrations_paths namespace/directory
 * and its own dedicated ledger table, wholly separate from the real
 * src/Piwigo/Migrations/ baseline and its migration history -- this
 * never touches the real schema.
 *
 * Plain ANSI SQL only (no per-platform branching) -- both statements in
 * this fixture pair are already portable across MySQL/MariaDB and
 * PostgreSQL without translation.
 */
final class Version00000000000001 extends AbstractMigration
{
    #[Override]
    public function getDescription(): string
    {
        return 'Upgrade-path probe: creates the scratch probe table';
    }

    #[Override]
    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE ' . self::probeTable() . ' (id INTEGER NOT NULL)');
    }

    #[Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE ' . self::probeTable());
    }

    public static function probeTable(): string
    {
        return 'migration_upgrade_probe';
    }
}
