<?php

declare(strict_types=1);

namespace Piwigo\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Drop the legacy `summarized` column from the history table.
 *
 * The column was written by Piwigo 2.x to track whether a history row had
 * been rolled up into history_summary. It is unused in 16.x. Previously
 * removed lazily by historyRemoveSummarizedColumn(); this migration makes
 * the removal versioned and unconditional.
 *
 * The IF EXISTS guard handles fresh 16.x installs that never had the column.
 */
final class Version20260514000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop legacy summarized column from history table';
    }

    public function up(Schema $schema): void
    {
        $prefix = $this->resolvePrefix();
        $table  = '`' . $prefix . 'history`';
        $sm     = $this->connection->createSchemaManager();
        if ($sm->introspectTableByUnquotedName($prefix . 'history')->hasColumn('summarized')) {
            $this->addSql('ALTER TABLE ' . $table . ' DROP COLUMN `summarized`');
        }
    }

    public function down(Schema $schema): void
    {
        $prefix = $this->resolvePrefix();
        $table  = '`' . $prefix . 'history`';
        $this->addSql("ALTER TABLE $table ADD COLUMN `summarized` ENUM('yes','no') DEFAULT NULL");
    }

    private function resolvePrefix(): string
    {
        return (class_exists(\Piwigo\Config\Config::class) && \Piwigo\Config\Config::has('db_prefix'))
            ? \Piwigo\Config\Config::dbPrefix()
            : (getenv('PIWIGO_DB_PREFIX') ?: 'piwigo_');
    }
}
