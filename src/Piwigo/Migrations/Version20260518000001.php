<?php

declare(strict_types=1);

namespace Piwigo\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Piwigo\Config\Config;
use Piwigo\Core\StringUtil;

/**
 * Replace the serialized `piwigo_conf.updates_ignored` row with a dedicated
 * `piwigo_extension_ignored_updates` table.
 *
 * Pre-B5 the admin "ignore future update notifications" list was stored as
 * a PHP-serialized nested `array{plugins,themes,languages: list<string>}`
 * inside a single conf row. Each caller did its own unserialize ritual.
 * The new table makes the storage match the access semantics: one row per
 * (type, id) pair.
 *
 * up()  — create the table, port existing serialized rows, delete the conf row.
 * down() — re-serialize back into the conf row, drop the table.
 */
final class Version20260518000001 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Create piwigo_extension_ignored_updates table and migrate the legacy conf row';
    }

    /**
     * MyISAM DDL implicit-commits the surrounding transaction, so the
     * addSql() pipeline is reserved for the CREATE TABLE step (which
     * happens first and is gated by tablesExist() for idempotency). The
     * data migration (read legacy conf row, insert rows, delete conf row)
     * runs as direct executeStatement() calls in postUp() — those happen
     * after the DDL has already implicit-committed and don't need
     * transactional grouping.
     */
    #[\Override]
    public function up(Schema $schema): void
    {
        $prefix    = $this->resolvePrefix();
        $tableName = $prefix . 'extension_ignored_updates';
        $sm        = $this->connection->createSchemaManager();

        if (!$sm->tablesExist([$tableName])) {
            $this->addSql(
                'CREATE TABLE `' . $tableName . '` ('
                . "`extension_type` ENUM('plugins','themes','languages') CHARACTER SET ascii NOT NULL, "
                . '`extension_id` VARCHAR(64) CHARACTER SET ascii NOT NULL, '
                . '`ignored_at` DATETIME NOT NULL, '
                . 'PRIMARY KEY (`extension_type`, `extension_id`)'
                . ') ENGINE=MyISAM'
            );
        }
    }

    #[\Override]
    public function postUp(Schema $schema): void
    {
        $prefix    = $this->resolvePrefix();
        $tableName = $prefix . 'extension_ignored_updates';
        $confTable = $prefix . 'config';

        $legacyValue = $this->connection->executeQuery(
            'SELECT value FROM `' . $confTable . '` WHERE param = ?',
            ['updates_ignored']
        )->fetchOne();

        if (is_string($legacyValue) && $legacyValue !== '') {
            $decoded = StringUtil::safeUnserialize($legacyValue);
            foreach (['plugins', 'themes', 'languages'] as $type) {
                $rawIds = $decoded[$type] ?? null;
                if (!is_array($rawIds)) {
                    continue;
                }
                foreach ($rawIds as $extId) {
                    if (is_string($extId) && $extId !== '') {
                        $this->connection->executeStatement(
                            'INSERT IGNORE INTO `' . $tableName
                            . '` (extension_type, extension_id, ignored_at) VALUES (?, ?, NOW())',
                            [$type, $extId]
                        );
                    }
                }
            }
        }

        $this->connection->executeStatement(
            'DELETE FROM `' . $confTable . '` WHERE param = ?',
            ['updates_ignored']
        );
    }

    #[\Override]
    public function preDown(Schema $schema): void
    {
        $prefix    = $this->resolvePrefix();
        $tableName = $prefix . 'extension_ignored_updates';
        $confTable = $prefix . 'config';
        $sm        = $this->connection->createSchemaManager();

        if (!$sm->tablesExist([$tableName])) {
            return;
        }

        $rows = $this->connection->executeQuery(
            'SELECT extension_type, extension_id FROM `' . $tableName . '` ORDER BY extension_type, extension_id'
        )->fetchAllAssociative();

        $bucketed = ['plugins' => [], 'themes' => [], 'languages' => []];
        foreach ($rows as $row) {
            $type = is_string($row['extension_type'] ?? null) ? $row['extension_type'] : null;
            $id   = is_string($row['extension_id'] ?? null) ? $row['extension_id'] : null;
            if ($type !== null && $id !== null && array_key_exists($type, $bucketed)) {
                $bucketed[$type][] = $id;
            }
        }

        if ($bucketed !== ['plugins' => [], 'themes' => [], 'languages' => []]) {
            $this->connection->executeStatement(
                'INSERT INTO `' . $confTable . '` (param, value) VALUES (?, ?) '
                . 'ON DUPLICATE KEY UPDATE value = VALUES(value)',
                ['updates_ignored', serialize($bucketed)]
            );
        }
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $prefix    = $this->resolvePrefix();
        $tableName = $prefix . 'extension_ignored_updates';
        $sm        = $this->connection->createSchemaManager();

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
