<?php

declare(strict_types=1);

namespace Piwigo\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Piwigo\Config\Config;

/**
 * Create `piwigo_plugin_migrations` — the ledger that B9 uses to track
 * which Doctrine migrations from each plugin's `migrations/` directory
 * have run.
 *
 * Composite primary key (plugin_id, version) so the same migration
 * version string from different plugins never collides. The table is
 * empty by default; B9's per-plugin migration runner populates it as
 * activate() / update() execute `plugins/<id>/migrations/Version*.php`.
 */
final class Version20260516000001 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Create piwigo_plugin_migrations ledger for per-plugin Doctrine migrations';
    }

    /**
     * Pattern lifted from Version20260514000001: gate the DDL on a
     * tablesExist() probe so the second-and-subsequent migrate() runs
     * become no-ops. MyISAM implicit-commits DDL, which makes the
     * surrounding Doctrine transaction undefined; the guard keeps
     * everything addSql-driven and lets the migration register cleanly
     * on first install without breaking the all-or-nothing transactional
     * mode the runner enables for the rest of the migration set.
     */
    #[\Override]
    public function up(Schema $schema): void
    {
        $prefix = $this->resolvePrefix();
        $tableName = $prefix . 'plugin_migrations';
        $sm = $this->connection->createSchemaManager();
        if (!$sm->tablesExist([$tableName])) {
            // varchar(191) matches Doctrine's migration_versions ledger
            // (see migrations.php version_column_length). utf8mb3 keeps
            // the (plugin_id, version) composite PK under MyISAM's
            // 1000-byte key cap: (64 + 191) * 3 bytes = 765 bytes.
            $this->addSql(
                'CREATE TABLE `' . $tableName . '` ('
                . '`plugin_id` VARCHAR(64) NOT NULL, '
                . '`version` VARCHAR(191) NOT NULL, '
                . '`executed_at` DATETIME NOT NULL, '
                . 'PRIMARY KEY (`plugin_id`, `version`)'
                . ') ENGINE=MyISAM DEFAULT CHARSET=utf8mb3'
            );
        }
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $prefix = $this->resolvePrefix();
        $tableName = $prefix . 'plugin_migrations';
        $sm = $this->connection->createSchemaManager();
        if ($sm->tablesExist([$tableName])) {
            $this->addSql('DROP TABLE `' . $tableName . '`');
        }
    }

    private function resolvePrefix(): string
    {
        return (class_exists(Config::class) && Config::has('db_prefix'))
            ? Config::dbPrefix()
            : ((($env = getenv('PIWIGO_DB_PREFIX')) !== false && $env !== '') ? $env : 'piwigo_');
    }
}
