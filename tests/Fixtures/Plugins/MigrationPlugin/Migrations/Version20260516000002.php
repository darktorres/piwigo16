<?php

declare(strict_types=1);

namespace Piwigo\Tests\Fixtures\Plugins\MigrationPlugin\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Second fixture migration — proves the runner applies migrations in
 * version order (this one depends on Version20260516000001's table).
 */
final class Version20260516000002 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Add column to fixture_one';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE fixture_one ADD COLUMN label TEXT');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        // SQLite supports DROP COLUMN since 3.35; for older targets the
        // runner test only verifies up() then full down() of every applied
        // migration, so the column gets dropped together with the table.
        $this->addSql('ALTER TABLE fixture_one DROP COLUMN label');
    }

    #[\Override]
    public function isTransactional(): bool
    {
        return false;
    }
}
