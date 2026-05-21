<?php

declare(strict_types=1);

namespace Piwigo\Admin\Extensions;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Piwigo\Db\Tables;

/**
 * Persistence for the "ignore future update notifications for this
 * extension" list the admin manages in /admin/extensions/updates.
 *
 * Replaces the legacy `piwigo_conf.updates_ignored` row (which held a
 * PHP-serialized nested array). Each (extension_type, extension_id)
 * pair gets its own row in `piwigo_extension_ignored_updates`, so the
 * lifecycle becomes straight SQL: INSERT IGNORE to add, DELETE WHERE
 * to remove or reset, SELECT to read.
 */
final readonly class IgnoredUpdatesRepository
{
    public function __construct(private Connection $conn)
    {
    }

    /** @return list<string> extension IDs ignored for this type */
    public function listForType(ExtensionType $type): array
    {
        /** @var list<string> $rows */
        $rows = $this->conn->executeQuery(
            'SELECT extension_id FROM ' . Tables::extensionIgnoredUpdates() . ' WHERE extension_type = ?',
            [$type->value]
        )->fetchFirstColumn();
        return $rows;
    }

    public function listAll(): IgnoredExtensionLists
    {
        return new IgnoredExtensionLists(
            plugins:   $this->listForType(ExtensionType::Plugin),
            themes:    $this->listForType(ExtensionType::Theme),
            languages: $this->listForType(ExtensionType::Language),
        );
    }

    public function isIgnored(ExtensionType $type, string $extensionId): bool
    {
        $count = $this->conn->executeQuery(
            'SELECT COUNT(*) FROM ' . Tables::extensionIgnoredUpdates() . ' WHERE extension_type = ? AND extension_id = ?',
            [$type->value, $extensionId]
        )->fetchOne();
        return is_numeric($count) && (int) $count > 0;
    }

    public function ignore(ExtensionType $type, string $extensionId): void
    {
        $this->conn->executeStatement(
            'INSERT IGNORE INTO ' . Tables::extensionIgnoredUpdates() . ' (extension_type, extension_id, ignored_at) VALUES (?, ?, NOW())',
            [$type->value, $extensionId]
        );
    }

    public function unignore(ExtensionType $type, string $extensionId): void
    {
        $this->conn->executeStatement(
            'DELETE FROM ' . Tables::extensionIgnoredUpdates() . ' WHERE extension_type = ? AND extension_id = ?',
            [$type->value, $extensionId]
        );
    }

    public function clearType(ExtensionType $type): void
    {
        $this->conn->executeStatement(
            'DELETE FROM ' . Tables::extensionIgnoredUpdates() . ' WHERE extension_type = ?',
            [$type->value]
        );
    }

    public function clearAll(): void
    {
        $this->conn->executeStatement('DELETE FROM ' . Tables::extensionIgnoredUpdates());
    }

    /**
     * For a given type, keep only the extension IDs that still exist
     * on disk — DELETE any ignored row whose extension is no longer
     * installed. Used by Updates::checkExtensions to reconcile the
     * ignore list with the filesystem snapshot.
     *
     * @param list<string> $currentExtensionIds
     */
    public function pruneStale(ExtensionType $type, array $currentExtensionIds): void
    {
        if ($currentExtensionIds === []) {
            $this->clearType($type);
            return;
        }
        $this->conn->executeStatement(
            'DELETE FROM ' . Tables::extensionIgnoredUpdates()
            . ' WHERE extension_type = ? AND extension_id NOT IN (?)',
            [$type->value, $currentExtensionIds],
            [ParameterType::STRING, ArrayParameterType::STRING]
        );
    }
}
