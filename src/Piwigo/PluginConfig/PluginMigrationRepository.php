<?php

declare(strict_types=1);

namespace Piwigo\PluginConfig;

use Doctrine\ORM\EntityRepository;
use Piwigo\Common\ValueObject\PluginId;

/**
 * Persistence layer for `plugin_migrations`. Composite (plugin_id,
 * version) PK -- record() is an upsert (update executed_at if the row
 * already exists, insert otherwise), never a blind persist(): a plugin's
 * 'restore' action (ExtensionLifecycle::performPluginAction()) uninstalls
 * then re-activates, which re-runs 'install' for the same plugin at the
 * same on-disk version, so the exact same (plugin_id, version) pair is a
 * real, expected recurrence, not just a theoretical one.
 *
 * @extends EntityRepository<PluginMigrationEntity>
 */
final class PluginMigrationRepository extends EntityRepository
{
    public function record(PluginId $pluginId, string $version, string $executedAt): void
    {
        $em = $this->getEntityManager();

        $entity = $this->find([
            'pluginId' => $pluginId,
            'version' => $version,
        ]);

        if ($entity === null) {
            $em->persist(new PluginMigrationEntity($pluginId, $version, $executedAt));
        } else {
            $entity->executedAt = $executedAt;
        }

        $em->flush();
    }

    /**
     * Removes a plugin's whole ledger.
     *
     * Exists because `fk_plugin_migrations_plugin_id` is `ON DELETE
     * RESTRICT`: the constraint deliberately refuses to let a `plugins` row
     * disappear while its history is still attached, so uninstalling has to
     * say what should happen to that history rather than have a cascade
     * decide silently. {@see \Piwigo\PluginConfig\PluginRegistry::uninstall()}
     * calls this immediately before deleting the row, in the same
     * transaction.
     */
    public function deleteForPlugin(PluginId $pluginId): void
    {
        $em = $this->getEntityManager();
        $em->createQueryBuilder()
            ->delete(PluginMigrationEntity::class, 'pm')
            ->where('pm.pluginId = :pluginId')
            ->setParameter('pluginId', $pluginId)
            ->getQuery()
            ->execute();
        $em->clear();
    }
}
