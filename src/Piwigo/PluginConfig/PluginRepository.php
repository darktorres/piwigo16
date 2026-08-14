<?php

declare(strict_types=1);

namespace Piwigo\PluginConfig;

use Doctrine\ORM\EntityRepository;
use Piwigo\Common\ValueObject\PluginId;
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
     *
     * `$id` stays a raw string at this public boundary -- its only real
     * caller (Admin\PluginLoader) never actually uses this
     * filter (only $state), and the id filter is otherwise test-only, so
     * `PluginId::tryFrom()` here, not `from()`: a filter value that can't
     * even be a well-formed PluginId can never match a real row (every
     * real row's own `p.id` is PluginId-typed by construction), so it
     * short-circuits to "no results" instead of throwing.
     */
    public function getDbPlugins(string $state = '', string $id = ''): array
    {
        $qb = $this->createQueryBuilder('p');

        if ($state !== '') {
            $qb->andWhere('p.state = :state')
                ->setParameter('state', $state);
        }
        if ($id !== '') {
            $pluginId = PluginId::tryFrom($id);
            if (! $pluginId instanceof PluginId) {
                return [];
            }

            $qb->andWhere('p.id = :id')
                ->setParameter('id', $pluginId);
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
     *
     * `$id` stays a raw string at this public boundary, wrapped
     * internally right before `find()` -- `EntityRepository::
     * find()` needs the id in the entity's own mapped-type form now that
     * PluginEntity::$id is PluginId-typed. `tryFrom()`, not `from()`: a
     * malformed id can never match a real row either way, matching this
     * method's own existing "no-op for an unknown id" contract (its one
     * real caller, Admin\PluginLoader, always passes an id read straight
     * back from a real filesystem-scanned plugin directory name, but this
     * keeps the graceful behavior for the id-not-found case consistent
     * regardless).
     */
    public function updateVersion(string $id, string $version): void
    {
        $pluginId = PluginId::tryFrom($id);
        if (! $pluginId instanceof PluginId) {
            return;
        }

        $entity = $this->find($pluginId);
        if ($entity === null) {
            return;
        }

        $entity->version = $version;
        $this->getEntityManager()
            ->flush();
    }

    /**
     * `PluginRegistry::install()`'s own write (P27.3) -- this class's
     * pre-P27 real caller (`Admin\PluginLoader`) only ever reads, so
     * insert/updateState/delete didn't exist here before; the admin-side
     * write path went through `Admin\Extensions\ExtensionRepository::
     * insertPlugin()`/`updatePluginState()` instead (raw DBAL against the
     * `plugins` table by string id, no `PluginEntity` involved at all).
     * `insertPlugin()` became genuinely dead once P27.5 retargeted
     * `ExtensionLifecycle` onto this registry -- deleted in P27.8.
     * `updatePluginState()` did **not**: `Admin\PluginsInstalledPageRenderer`
     * still calls it directly for its own "merged into core" auto-
     * deactivation, unrelated to `ExtensionLifecycle`'s own dispatch --
     * confirmed live before assuming P27.5's own original text (which
     * expected both to go dead together) still held.
     */
    public function insert(PluginId $id, string $version, PluginState $state = PluginState::Inactive): void
    {
        $this->getEntityManager()
            ->persist(new PluginEntity($id, $state, $version));
        $this->getEntityManager()
            ->flush();
    }

    public function updateState(PluginId $id, PluginState $state): void
    {
        $entity = $this->find($id);
        if ($entity === null) {
            return;
        }

        $entity->state = $state;
        $this->getEntityManager()
            ->flush();
    }

    public function delete(PluginId $id): void
    {
        $entity = $this->find($id);
        if ($entity === null) {
            return;
        }

        $this->getEntityManager()
            ->remove($entity);
        $this->getEntityManager()
            ->flush();
    }
}
