<?php

declare(strict_types=1);

namespace Piwigo\Plugin;

use Piwigo\Db\AbstractRepository;

/**
 * Persistence layer for the plugin domain.
 *
 * Non-final so PluginRegistryTest can subclass it for an in-memory shim
 * (no DB needed at unit-test scope). Production code never extends it.
 */
class PluginRepository extends AbstractRepository
{
    /**
     * Return all rows from the plugins table, optionally filtered by state and/or id.
     * Schema is fixed: id (string), state (enum 'inactive' / 'active'),
     * version (string).
     *
     * @return list<PluginRecord>
     */
    public function findAll(?string $state = '', ?string $id = ''): array
    {
        $qb = $this->conn->createQueryBuilder()
            ->select('*')
            ->from($this->table('plugins'));

        if ($state !== null && $state !== '') {
            $qb->andWhere('state = :state')
               ->setParameter('state', $state);
        }
        if ($id !== null && $id !== '') {
            $qb->andWhere('id = :id')
               ->setParameter('id', $id);
        }

        $out = [];
        foreach ($qb->executeQuery()->fetchAllAssociative() as $row) {
            $idRaw      = $row['id'] ?? null;
            $stateRaw   = $row['state'] ?? null;
            $versionRaw = $row['version'] ?? null;
            if (!is_string($idRaw)) {
                continue;
            }
            $out[] = new PluginRecord(
                id:      $idRaw,
                state:   is_string($stateRaw) ? $stateRaw : 'inactive',
                version: is_string($versionRaw) ? $versionRaw : '0',
            );
        }
        return $out;
    }

    /** Insert a new plugin record with the given id and version. */
    public function insert(string $pluginId, string $version): void
    {
        $this->conn->insert($this->table('plugins'), [
            'id'      => $pluginId,
            'version' => $version,
        ]);
    }

    /** Update only the version column for a plugin. */
    public function updateVersion(string $pluginId, string $version): void
    {
        $this->conn->createQueryBuilder()
            ->update($this->table('plugins'))
            ->set('version', ':version')
            ->where('id = :id')
            ->setParameter('version', $version)
            ->setParameter('id', $pluginId)
            ->executeStatement();
    }

    /** Update only the state column for a plugin (e.g. 'active', 'inactive'). */
    public function updateState(string $pluginId, string $state): void
    {
        $this->conn->createQueryBuilder()
            ->update($this->table('plugins'))
            ->set('state', ':state')
            ->where('id = :id')
            ->setParameter('state', $state)
            ->setParameter('id', $pluginId)
            ->executeStatement();
    }

    /** Delete a plugin record by id. */
    public function delete(string $pluginId): void
    {
        $this->conn->createQueryBuilder()
            ->delete($this->table('plugins'))
            ->where('id = :id')
            ->setParameter('id', $pluginId)
            ->executeStatement();
    }
}
