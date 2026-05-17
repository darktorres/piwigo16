<?php

declare(strict_types=1);

namespace Piwigo\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Piwigo\Config\Config;
use Piwigo\Core\StringUtil;

/**
 * Flip three conf-table rows from PHP serialize() to JSON encoding:
 *
 *   - blk_menubar              (array<string, int> block layout)
 *   - picture_informations     (array<string, bool> info-field toggles)
 *   - extents_for_templates    (plugin-supplied template extension map)
 *
 * Idempotent: rows that already decode as JSON are left alone. Rows that
 * decode as legacy serialize() are re-encoded as JSON. Malformed rows are
 * deleted (cleaner than leaving a row the new accessor can't read).
 */
final class Version20260520000001 extends AbstractMigration
{
    private const array KEYS = ['blk_menubar', 'picture_informations', 'extents_for_templates'];

    #[\Override]
    public function getDescription(): string
    {
        return 'Flip blk_menubar, picture_informations, extents_for_templates conf rows from serialize() to JSON';
    }

    /**
     * Pure data migration — no schema change. Lives in postUp() so the
     * direct executeStatement() calls happen after Doctrine's transaction
     * scope is settled (matching the Version20260518000001 pattern).
     */
    #[\Override]
    public function up(Schema $schema): void
    {
        // no DDL
    }

    #[\Override]
    public function postUp(Schema $schema): void
    {
        $prefix    = $this->resolvePrefix();
        $confTable = $prefix . 'config';

        foreach (self::KEYS as $key) {
            $raw = $this->connection->executeQuery(
                'SELECT value FROM `' . $confTable . '` WHERE param = ?',
                [$key]
            )->fetchOne();

            if (!is_string($raw) || $raw === '') {
                continue;
            }

            // Already JSON? leave it.
            $decodedJson = json_decode($raw, associative: true);
            if (json_last_error() === JSON_ERROR_NONE) {
                continue;
            }

            // Try the legacy serialize() shape.
            $decodedPhp = StringUtil::safeUnserialize($raw);
            if ($decodedPhp === []) {
                // Both decoders failed — drop the row rather than leave
                // unreadable data in place.
                $this->connection->executeStatement(
                    'DELETE FROM `' . $confTable . '` WHERE param = ?',
                    [$key]
                );
                continue;
            }

            $reencoded = json_encode(
                $decodedPhp,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            );

            $this->connection->executeStatement(
                'UPDATE `' . $confTable . '` SET value = ? WHERE param = ?',
                [$reencoded, $key]
            );
        }
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        // no DDL
    }

    #[\Override]
    public function preDown(Schema $schema): void
    {
        $prefix    = $this->resolvePrefix();
        $confTable = $prefix . 'config';

        foreach (self::KEYS as $key) {
            $raw = $this->connection->executeQuery(
                'SELECT value FROM `' . $confTable . '` WHERE param = ?',
                [$key]
            )->fetchOne();

            if (!is_string($raw) || $raw === '') {
                continue;
            }

            $decoded = json_decode($raw, associative: true);
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
                continue;
            }

            $this->connection->executeStatement(
                'UPDATE `' . $confTable . '` SET value = ? WHERE param = ?',
                [serialize($decoded), $key]
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
