<?php

declare(strict_types=1);

namespace Piwigo\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Piwigo\Config\Config;
use Piwigo\Core\StringUtil;

/**
 * Flip the per-row TEXT columns `search.rules` and `activity.details`
 * from PHP serialize() to JSON encoding. The columns stay TEXT; only the
 * value payload changes.
 *
 * Idempotent: rows that already decode as JSON are left alone. Rows that
 * decode as legacy serialize() are re-encoded as JSON. Malformed rows are
 * deleted (cleaner than leaving an unreadable row in place).
 */
final class Version20260520000002 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Flip search.rules and activity.details columns from serialize() to JSON';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        // no DDL
    }

    #[\Override]
    public function postUp(Schema $schema): void
    {
        $prefix = $this->resolvePrefix();

        $this->convertColumn($prefix . 'search', 'id', 'rules');
        $this->convertColumn($prefix . 'activity', 'activity_id', 'details');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        // no DDL
    }

    #[\Override]
    public function preDown(Schema $schema): void
    {
        $prefix = $this->resolvePrefix();

        $this->revertColumn($prefix . 'search', 'id', 'rules');
        $this->revertColumn($prefix . 'activity', 'activity_id', 'details');
    }

    private function convertColumn(string $table, string $idColumn, string $valueColumn): void
    {
        $rows = $this->connection->executeQuery(
            'SELECT `' . $idColumn . '` AS id, `' . $valueColumn . '` AS value FROM `' . $table . '`'
        )->fetchAllAssociative();

        foreach ($rows as $row) {
            $raw = $row['value'];
            $id  = $row['id'];

            if (!is_string($raw) || $raw === '') {
                continue;
            }

            // Already JSON? leave it.
            $decodedJson = json_decode($raw, associative: true);
            if (json_last_error() === JSON_ERROR_NONE) {
                continue;
            }

            $decodedPhp = StringUtil::safeUnserialize($raw);
            if ($decodedPhp === []) {
                $this->connection->executeStatement(
                    'DELETE FROM `' . $table . '` WHERE `' . $idColumn . '` = ?',
                    [$id]
                );
                continue;
            }

            $reencoded = json_encode(
                $decodedPhp,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            );

            $this->connection->executeStatement(
                'UPDATE `' . $table . '` SET `' . $valueColumn . '` = ? WHERE `' . $idColumn . '` = ?',
                [$reencoded, $id]
            );
        }
    }

    private function revertColumn(string $table, string $idColumn, string $valueColumn): void
    {
        $rows = $this->connection->executeQuery(
            'SELECT `' . $idColumn . '` AS id, `' . $valueColumn . '` AS value FROM `' . $table . '`'
        )->fetchAllAssociative();

        foreach ($rows as $row) {
            $raw = $row['value'];
            $id  = $row['id'];

            if (!is_string($raw) || $raw === '') {
                continue;
            }

            $decoded = json_decode($raw, associative: true);
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
                continue;
            }

            $this->connection->executeStatement(
                'UPDATE `' . $table . '` SET `' . $valueColumn . '` = ? WHERE `' . $idColumn . '` = ?',
                [serialize($decoded), $id]
            );
        }
    }

    private function resolvePrefix(): string
    {
        return (class_exists(Config::class) && Config::has('db_prefix'))
            ? Config::dbPrefix()
            : ((($env = getenv('PIWIGO_DB_PREFIX')) !== false && $env !== '') ? $env : 'piwigo_');
    }
}
