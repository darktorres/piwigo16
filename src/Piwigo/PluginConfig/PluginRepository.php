<?php

declare(strict_types=1);

namespace Piwigo\PluginConfig;

use Doctrine\ORM\EntityRepository;
use Piwigo\PluginConfig\Projection\Plugin;

/**
 * @extends EntityRepository<PluginEntity>
 */
final class PluginRepository extends EntityRepository
{
    /**
     * Returns plugins defined in the database, optionally filtered by state
     * and/or id.
     *
     * @return list<Plugin>
     */
    public function getDbPlugins(string $state = '', string $id = ''): array
    {
        $qb = $this->createQueryBuilder('p');

        if ($state !== '') {
            $qb->andWhere('p.state = :state')
                ->setParameter('state', $state);
        }
        if ($id !== '') {
            $qb->andWhere('p.id = :id')
                ->setParameter('id', $id);
        }

        $entities = $qb->getQuery()
            ->getResult();

        return array_map(
            static fn (PluginEntity $entity): Plugin => new Plugin($entity->id, $entity->state->value, $entity->version),
            $entities,
        );
    }

    /**
     * Records a plugin's filesystem version after autoupdate_plugin()
     * detects a newer main.inc.php header.
     */
    public function updateVersion(string $id, string $version): void
    {
        $entity = $this->find($id);
        if ($entity === null) {
            return;
        }

        $entity->version = $version;
        $this->getEntityManager()
            ->flush();
    }
}
