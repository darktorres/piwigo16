<?php

declare(strict_types=1);

namespace Piwigo\Plugin\Migration;

use Piwigo\Db\AbstractRepository;

/**
 * DB access for `piwigo_plugin_migrations` — the per-plugin migration
 * ledger created by core migration Version20260516000001.
 *
 * Tracks one row per (plugin_id, version) pair, where `version` is the
 * fully-qualified class name of the plugin migration. The composite key
 * lets the same migration version string co-exist across plugins.
 *
 * The runner test uses this directly against an in-memory SQLite
 * connection — no subclassing needed.
 */
final class PluginMigrationLedger extends AbstractRepository
{
    /**
     * Migration FQCNs already applied for the given plugin.
     *
     * @return list<string>
     */
    public function getApplied(string $pluginId): array
    {
        $rows = $this->conn->createQueryBuilder()
            ->select('version')
            ->from($this->table('plugin_migrations'))
            ->where('plugin_id = :id')
            ->setParameter('id', $pluginId)
            ->executeQuery()
            ->fetchAllAssociative();

        $out = [];
        foreach ($rows as $row) {
            $v = $row['version'] ?? null;
            if (is_string($v)) {
                $out[] = $v;
            }
        }
        return $out;
    }

    /** Mark a migration version as applied for this plugin. */
    public function recordApplied(string $pluginId, string $version): void
    {
        $this->conn->insert($this->table('plugin_migrations'), [
            'plugin_id'   => $pluginId,
            'version'     => $version,
            'executed_at' => (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s'),
        ]);
    }

    /** Remove a single applied-migration row (used by down()). */
    public function removeApplied(string $pluginId, string $version): void
    {
        $this->conn->createQueryBuilder()
            ->delete($this->table('plugin_migrations'))
            ->where('plugin_id = :id AND version = :version')
            ->setParameter('id', $pluginId)
            ->setParameter('version', $version)
            ->executeStatement();
    }

    /** Drop every ledger entry for a plugin (used on full uninstall). */
    public function removeAll(string $pluginId): void
    {
        $this->conn->createQueryBuilder()
            ->delete($this->table('plugin_migrations'))
            ->where('plugin_id = :id')
            ->setParameter('id', $pluginId)
            ->executeStatement();
    }
}
