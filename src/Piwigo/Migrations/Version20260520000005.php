<?php

declare(strict_types=1);

namespace Piwigo\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Piwigo\Config\Config;
use Piwigo\Core\StringUtil;

/**
 * Replace the serialized `piwigo_conf.c13y_ignore` row with a dedicated
 * `piwigo_integrity_ignored_anomalies` table.
 *
 * The legacy conf row stored a single serialized
 * `{version: string, list: list<string>}` blob — admins' acknowledged
 * anomaly IDs for the running piwigo version. The new table makes each
 * (anomaly_id, piwigo_version) pair its own row, so the access pattern
 * is straight SQL.
 *
 * Mirrors the Version20260518000001 pattern: DDL via addSql() in up(),
 * data migration via direct executeStatement() in postUp().
 */
final class Version20260520000005 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Create piwigo_integrity_ignored_anomalies table and migrate the legacy c13y_ignore conf row';
    }

    /**
     * MyISAM CREATE TABLE implicit-commits the surrounding transaction,
     * so Doctrine's BEGIN/COMMIT wrapper raises "no active transaction"
     * once postUp() finishes. Opt out of transactional wrapping.
     */
    #[\Override]
    public function isTransactional(): bool
    {
        return false;
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $prefix    = $this->resolvePrefix();
        $tableName = $prefix . 'integrity_ignored_anomalies';
        $sm        = $this->connection->createSchemaManager();

        if (!$sm->tablesExist([$tableName])) {
            $this->addSql(
                'CREATE TABLE `' . $tableName . '` ('
                . '`anomaly_id` VARCHAR(64) CHARACTER SET ascii NOT NULL, '
                . '`piwigo_version` VARCHAR(16) CHARACTER SET ascii NOT NULL, '
                . '`ignored_at` DATETIME NOT NULL, '
                . 'PRIMARY KEY (`anomaly_id`, `piwigo_version`)'
                . ') ENGINE=MyISAM'
            );
        }
    }

    #[\Override]
    public function postUp(Schema $schema): void
    {
        $prefix    = $this->resolvePrefix();
        $tableName = $prefix . 'integrity_ignored_anomalies';
        $confTable = $prefix . 'config';

        $legacyValue = $this->connection->executeQuery(
            'SELECT value FROM `' . $confTable . '` WHERE param = ?',
            ['c13y_ignore']
        )->fetchOne();

        if (is_string($legacyValue) && $legacyValue !== '') {
            $decoded = StringUtil::safeUnserialize($legacyValue);
            $version = is_string($decoded['version'] ?? null) ? $decoded['version'] : null;
            $list    = is_array($decoded['list'] ?? null) ? $decoded['list'] : [];

            if ($version !== null) {
                foreach ($list as $anomalyId) {
                    if (is_string($anomalyId) && $anomalyId !== '') {
                        $this->connection->executeStatement(
                            'INSERT IGNORE INTO `' . $tableName
                            . '` (anomaly_id, piwigo_version, ignored_at) VALUES (?, ?, NOW())',
                            [$anomalyId, $version]
                        );
                    }
                }
            }
        }

        $this->connection->executeStatement(
            'DELETE FROM `' . $confTable . '` WHERE param = ?',
            ['c13y_ignore']
        );
    }

    #[\Override]
    public function preDown(Schema $schema): void
    {
        $prefix    = $this->resolvePrefix();
        $tableName = $prefix . 'integrity_ignored_anomalies';
        $confTable = $prefix . 'config';
        $sm        = $this->connection->createSchemaManager();

        if (!$sm->tablesExist([$tableName])) {
            return;
        }

        $rows = $this->connection->executeQuery(
            'SELECT anomaly_id, piwigo_version FROM `' . $tableName . '` ORDER BY piwigo_version, anomaly_id'
        )->fetchAllAssociative();

        $byVersion = [];
        foreach ($rows as $row) {
            $version = is_string($row['piwigo_version'] ?? null) ? $row['piwigo_version'] : null;
            $id      = is_string($row['anomaly_id'] ?? null) ? $row['anomaly_id'] : null;
            if ($version !== null && $id !== null) {
                $byVersion[$version][] = $id;
            }
        }

        // Only one (version, list) tuple fits in the legacy schema — pick
        // the version with the most acknowledged anomalies (best effort).
        if ($byVersion !== []) {
            uasort($byVersion, static fn (array $a, array $b): int => count($b) <=> count($a));
            $winnerVersion = array_key_first($byVersion);
            $winnerList    = $byVersion[$winnerVersion];

            $blob = serialize(['version' => $winnerVersion, 'list' => $winnerList]);
            $this->connection->executeStatement(
                'INSERT INTO `' . $confTable . '` (param, value) VALUES (?, ?) '
                . 'ON DUPLICATE KEY UPDATE value = VALUES(value)',
                ['c13y_ignore', $blob]
            );
        }
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $prefix    = $this->resolvePrefix();
        $tableName = $prefix . 'integrity_ignored_anomalies';
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
