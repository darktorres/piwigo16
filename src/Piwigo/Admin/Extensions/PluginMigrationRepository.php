<?php

declare(strict_types=1);

namespace Piwigo\Admin\Extensions;

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
}
