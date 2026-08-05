<?php

declare(strict_types=1);

namespace Piwigo\Migrations\UpgradePathProbe;

use Override;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The real "delta" half of tests/Integration/MigrationUpgradePathTest.php's
 * pair -- see Version00000000000001's own docblock for the full picture.
 * Alphabetically sorts after Version00000000000001 within this fixture's
 * own dedicated namespace, so a migrate() run targeting 'latest' after
 * Version00000000000001 has already been applied executes only this one.
 */
final class Version00000000000002 extends AbstractMigration
{
    #[Override]
    public function getDescription(): string
    {
        return 'Upgrade-path probe: adds the delta column';
    }

    #[Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ' . Version00000000000001::probeTable() . ' ADD COLUMN probe_value INTEGER');
    }

    #[Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ' . Version00000000000001::probeTable() . ' DROP COLUMN probe_value');
    }
}
