<?php

declare(strict_types=1);

namespace Piwigo\Tests\Fixtures\Plugins\MigrationPlugin\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Fixture migration — creates a tiny SQLite-compatible table so
 * PluginMigrationRunnerTest can verify the runner executes addSql()
 * queries against the connection and records the version in
 * piwigo_plugin_migrations afterwards.
 */
final class Version20260516000001 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Create fixture_one for PluginMigrationRunnerTest';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE fixture_one (id INTEGER PRIMARY KEY)');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE fixture_one');
    }

    /**
     * SQLite cannot wrap CREATE/DROP TABLE inside a transaction reliably
     * for the version of DBAL we ship; flip transactional off so the
     * fixture test can use an in-memory SQLite driver without warnings.
     */
    #[\Override]
    public function isTransactional(): bool
    {
        return false;
    }
}
