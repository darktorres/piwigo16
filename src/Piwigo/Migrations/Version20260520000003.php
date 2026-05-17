<?php

declare(strict_types=1);

namespace Piwigo\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Piwigo\Config\Config;

/**
 * Drop the `cache_sizes` conf row — the value is now memoized through the
 * PSR-6 cache pool instead of the conf table. The data is computed-data
 * memoization (admin clicks "Calculate" to refresh), not configuration.
 *
 * No DDL; pure data migration. Down() restores nothing — once the SCHEMA
 * entry and accessor are deleted, the conf row would just sit unread.
 */
final class Version20260520000003 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Drop cache_sizes conf row (moved to PSR-6 cache pool)';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        // no DDL
    }

    #[\Override]
    public function postUp(Schema $schema): void
    {
        $confTable = $this->resolvePrefix() . 'config';
        $this->connection->executeStatement(
            'DELETE FROM `' . $confTable . '` WHERE param = ?',
            ['cache_sizes']
        );
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        // no DDL; the conf row is not restored on rollback because the
        // SCHEMA entry no longer exists in the new code path.
    }

    private function resolvePrefix(): string
    {
        return (class_exists(Config::class) && Config::has('db_prefix'))
            ? Config::dbPrefix()
            : ((($env = getenv('PIWIGO_DB_PREFIX')) !== false && $env !== '') ? $env : 'piwigo_');
    }
}
