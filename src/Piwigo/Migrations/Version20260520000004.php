<?php

declare(strict_types=1);

namespace Piwigo\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Piwigo\Config\Config;
use Piwigo\Core\StringUtil;

/**
 * A2 + A3: drop two conf rows that no longer have a typed home.
 *
 *   - `history_sections_cache` is deleted entirely — its value is now
 *     computed on the fly from SchemaHelper::getEnums().
 *   - `update_notify_last_notification` is split into two typed scalar
 *     conf rows: `..._version` and `..._at`. The legacy row is deleted
 *     after migration; the two new rows are populated from its decoded
 *     payload.
 */
final class Version20260520000004 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'A2+A3: drop history_sections_cache, split update_notify_last_notification into typed scalars';
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

        // A2 — drop history_sections_cache outright.
        $this->connection->executeStatement(
            'DELETE FROM `' . $confTable . '` WHERE param = ?',
            ['history_sections_cache']
        );

        // A3 — split blob into two scalars.
        $raw = $this->connection->executeQuery(
            'SELECT value FROM `' . $confTable . '` WHERE param = ?',
            ['update_notify_last_notification']
        )->fetchOne();

        if (is_string($raw) && $raw !== '') {
            $decoded = StringUtil::safeUnserialize($raw);
            $version = is_string($decoded['version'] ?? null) ? $decoded['version'] : null;
            $at      = is_string($decoded['notified_on'] ?? null) ? $decoded['notified_on'] : null;

            if ($version !== null) {
                $this->connection->executeStatement(
                    'INSERT INTO `' . $confTable . '` (param, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = ?',
                    ['update_notify_last_notification_version', $version, $version]
                );
            }
            if ($at !== null) {
                $this->connection->executeStatement(
                    'INSERT INTO `' . $confTable . '` (param, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = ?',
                    ['update_notify_last_notification_at', $at, $at]
                );
            }
        }

        $this->connection->executeStatement(
            'DELETE FROM `' . $confTable . '` WHERE param = ?',
            ['update_notify_last_notification']
        );
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        // no DDL
    }

    #[\Override]
    public function preDown(Schema $schema): void
    {
        $confTable = $this->resolvePrefix() . 'config';

        $version = $this->connection->executeQuery(
            'SELECT value FROM `' . $confTable . '` WHERE param = ?',
            ['update_notify_last_notification_version']
        )->fetchOne();
        $at = $this->connection->executeQuery(
            'SELECT value FROM `' . $confTable . '` WHERE param = ?',
            ['update_notify_last_notification_at']
        )->fetchOne();

        if (is_string($version) && is_string($at) && $version !== '' && $at !== '') {
            $blob = serialize(['version' => $version, 'notified_on' => $at]);
            $this->connection->executeStatement(
                'INSERT INTO `' . $confTable . '` (param, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = ?',
                ['update_notify_last_notification', $blob, $blob]
            );
        }

        $this->connection->executeStatement(
            'DELETE FROM `' . $confTable . '` WHERE param IN (?, ?)',
            ['update_notify_last_notification_version', 'update_notify_last_notification_at']
        );
        // history_sections_cache is not restored on rollback (it was
        // pure memoization; the code path no longer reads or writes it).
    }

    private function resolvePrefix(): string
    {
        return (class_exists(Config::class) && Config::has('db_prefix'))
            ? Config::dbPrefix()
            : ((($env = getenv('PIWIGO_DB_PREFIX')) !== false && $env !== '') ? $env : 'piwigo_');
    }
}
