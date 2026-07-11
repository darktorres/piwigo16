<?php

declare(strict_types=1);

namespace Piwigo\Migrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;
use Piwigo\Config\Config;

/**
 * The 7 new tables (P15's own explicit inventory -- not `audit_log`/
 * `user_passkeys`, which the Epoch D banner mentions but which belong to
 * later phases: never in the "7 new tables (P15)" list, never built in
 * the reference, `AuditService` placed at P18 by the doc's own text).
 * Column shapes pulled from the reference's real, working schema.
 *
 * Uses the portable Schema API throughout -- brand-new tables with no
 * legacy baggage, exactly where it shines. JSON columns are native from
 * day one (these are new columns, not part of the 43 text->JSON
 * conversions deferred to P17-23 -- no scope conflict). The reference's
 * per-column `CHARACTER SET ascii` storage optimization on the ID-like
 * columns is dropped -- DBAL's Column API has no portable per-column
 * charset, and it's a storage nicety with zero functional impact (values
 * are still ASCII-only by application-level regex validation); not worth
 * fighting the abstraction for.
 */
final class Version20260711150903 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Create the 7 new tables: derivative_settings, derivative_size, extension_ignored_updates, integrity_ignored_anomalies, plugin_migrations, search_filter_view, user_failed_logins';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $prefix = Config::dbPrefix();

        if (! $schema->hasTable($prefix . 'derivative_settings')) {
            $t = $schema->createTable($prefix . 'derivative_settings');
            $t->addOption('charset', 'utf8mb4');
            $t->addOption('collation', 'utf8mb4_unicode_ci');
            $t->addColumn('id', Types::SMALLINT, [
                'notnull' => true,
            ]);
            $t->addColumn('default_quality', Types::INTEGER, [
                'notnull' => true,
                'default' => 95,
            ]);
            $t->addColumn('watermark_json', Types::JSON, [
                'notnull' => true,
            ]);
            $t->addColumn('custom_json', Types::JSON, [
                'notnull' => true,
            ]);
            $t->setPrimaryKey(['id']);
        }

        if (! $schema->hasTable($prefix . 'derivative_size')) {
            $t = $schema->createTable($prefix . 'derivative_size');
            $t->addOption('charset', 'utf8mb4');
            $t->addOption('collation', 'utf8mb4_unicode_ci');
            $t->addColumn('name', Types::STRING, [
                'notnull' => true,
                'length' => 32,
            ]);
            $t->addColumn('enabled', Types::SMALLINT, [
                'notnull' => true,
                'default' => 1,
            ]);
            $t->addColumn('max_width', Types::INTEGER, [
                'notnull' => true,
                'default' => 0,
            ]);
            $t->addColumn('max_height', Types::INTEGER, [
                'notnull' => true,
                'default' => 0,
            ]);
            $t->addColumn('max_crop', Types::DECIMAL, [
                'notnull' => true,
                'precision' => 5,
                'scale' => 4,
                'default' => 0,
            ]);
            $t->addColumn('min_width', Types::INTEGER, [
                'notnull' => false,
            ]);
            $t->addColumn('min_height', Types::INTEGER, [
                'notnull' => false,
            ]);
            $t->addColumn('sharpen', Types::DECIMAL, [
                'notnull' => true,
                'precision' => 5,
                'scale' => 4,
                'default' => 0,
            ]);
            $t->addColumn('last_mod_time', Types::INTEGER, [
                'notnull' => true,
                'default' => 0,
            ]);
            $t->setPrimaryKey(['name']);
        }

        if (! $schema->hasTable($prefix . 'extension_ignored_updates')) {
            $t = $schema->createTable($prefix . 'extension_ignored_updates');
            $t->addOption('charset', 'utf8mb4');
            $t->addOption('collation', 'utf8mb4_unicode_ci');
            $t->addColumn('extension_type', Types::STRING, [
                'notnull' => true,
                'length' => 16,
            ]);
            $t->addColumn('extension_id', Types::STRING, [
                'notnull' => true,
                'length' => 64,
            ]);
            $t->addColumn('ignored_at', Types::DATETIME_MUTABLE, [
                'notnull' => true,
            ]);
            $t->setPrimaryKey(['extension_type', 'extension_id']);
        }

        if (! $schema->hasTable($prefix . 'integrity_ignored_anomalies')) {
            $t = $schema->createTable($prefix . 'integrity_ignored_anomalies');
            $t->addOption('charset', 'utf8mb4');
            $t->addOption('collation', 'utf8mb4_unicode_ci');
            $t->addColumn('anomaly_id', Types::STRING, [
                'notnull' => true,
                'length' => 64,
            ]);
            $t->addColumn('piwigo_version', Types::STRING, [
                'notnull' => true,
                'length' => 16,
            ]);
            $t->addColumn('ignored_at', Types::DATETIME_MUTABLE, [
                'notnull' => true,
            ]);
            $t->setPrimaryKey(['anomaly_id', 'piwigo_version']);
        }

        if (! $schema->hasTable($prefix . 'plugin_migrations')) {
            $t = $schema->createTable($prefix . 'plugin_migrations');
            $t->addOption('charset', 'utf8mb4');
            $t->addOption('collation', 'utf8mb4_unicode_ci');
            $t->addColumn('plugin_id', Types::STRING, [
                'notnull' => true,
                'length' => 64,
            ]);
            $t->addColumn('version', Types::STRING, [
                'notnull' => true,
                'length' => 191,
            ]);
            $t->addColumn('executed_at', Types::DATETIME_MUTABLE, [
                'notnull' => true,
            ]);
            $t->setPrimaryKey(['plugin_id', 'version']);
        }

        if (! $schema->hasTable($prefix . 'search_filter_view')) {
            $t = $schema->createTable($prefix . 'search_filter_view');
            $t->addOption('charset', 'utf8mb4');
            $t->addOption('collation', 'utf8mb4_unicode_ci');
            $t->addColumn('name', Types::STRING, [
                'notnull' => true,
                'length' => 64,
            ]);
            $t->addColumn('config_json', Types::JSON, [
                'notnull' => true,
            ]);
            $t->addColumn('created_at', Types::DATETIME_MUTABLE, [
                'notnull' => true,
            ]);
            $t->setPrimaryKey(['name']);
        }

        if (! $schema->hasTable($prefix . 'user_failed_logins')) {
            $t = $schema->createTable($prefix . 'user_failed_logins');
            $t->addOption('charset', 'utf8mb4');
            $t->addOption('collation', 'utf8mb4_unicode_ci');
            $t->addColumn('id', Types::INTEGER, [
                'notnull' => true,
                'unsigned' => true,
                'autoincrement' => true,
            ]);
            $t->addColumn('user_id', Types::INTEGER, [
                'notnull' => false,
                'unsigned' => true,
            ]);
            if (! $this->platform instanceof PostgreSQLPlatform) {
                // users.id is mediumint(8) unsigned on MySQL/MariaDB (real
                // origin width, not DBAL's portable INTEGER) -- an FK
                // between mismatched widths is rejected as "incompatible"
                // even though both are logically the same unsigned range
                // (same empirically-found class of bug as
                // Version20260711150857's bootstrap fix). PostgreSQL has
                // no mediumint distinction, so the portable INTEGER above
                // is already correct there.
                $t->getColumn('user_id')
                    ->setColumnDefinition('mediumint(8) unsigned DEFAULT NULL');
            }
            $t->addColumn('ip', Types::STRING, [
                'notnull' => true,
                'length' => 45,
            ]);
            $t->addColumn('attempted_at', Types::DATETIME_MUTABLE, [
                'notnull' => true,
            ]);
            $t->setPrimaryKey(['id']);
            $t->addIndex(['user_id', 'attempted_at'], 'idx_user_failed_logins_user_time');
            $t->addIndex(['ip', 'attempted_at'], 'idx_user_failed_logins_ip_time');
            // The 42nd FK constraint (see Version20260711150901's
            // docblock) -- added here, not there, since this table
            // doesn't exist until this migration creates it.
            $t->addForeignKeyConstraint(
                $prefix . 'users',
                ['user_id'],
                ['id'],
                [
                    'onDelete' => 'CASCADE',
                ],
                'fk_user_failed_logins_user_id',
            );
        }
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $prefix = Config::dbPrefix();

        foreach ([
            'derivative_settings', 'derivative_size', 'extension_ignored_updates',
            'integrity_ignored_anomalies', 'plugin_migrations', 'search_filter_view',
            'user_failed_logins',
        ] as $table) {
            if ($schema->hasTable($prefix . $table)) {
                $schema->dropTable($prefix . $table);
            }
        }
    }
}
