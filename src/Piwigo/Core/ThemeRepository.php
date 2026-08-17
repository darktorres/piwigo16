<?php

declare(strict_types=1);

namespace Piwigo\Core;

use Doctrine\ORM\EntityRepository;
use Piwigo\Common\ValueObject\ThemeId;
use Piwigo\Core\Projection\ThemeListing;

/**
 * Persistence layer for the `themes` table's own id/name listing below.
 *
 * Real DQL against {@see ThemeEntity} -- `themes` itself is
 * entity-mapped. `EntityRepository<ThemeEntity>` needs no other caller
 * to be worth using here.
 *
 * @extends EntityRepository<ThemeEntity>
 */
final class ThemeRepository extends EntityRepository
{
    /**
     * id/name for every installed theme row, ordered by name --
     * ThemeCatalog::getPwgThemes()'s own catalog listing. `name` is
     * nullable in the schema; rows with a null name are dropped, matching
     * the original raw query's own `is_string()` filter.
     *
     * @return list<ThemeListing>
     *
     * `t.id` maps through the `theme_id` custom Doctrine Type, so
     * getArrayResult() hydrates it as a ThemeId value object -- unwrapped
     * here since this listing's own return shape stays plain string (its
     * one real caller, ThemeCatalog::getPwgThemes(), compares it against
     * a raw config string and uses it as an array key, no VO involved on
     * that side).
     */
    public function findAllIdsAndNames(): array
    {
        $rows = $this->createQueryBuilder('t')
            ->select('t.id', 't.name')
            ->orderBy('t.name', 'ASC')
            ->getQuery()
            ->getArrayResult();

        $themes = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $id = $row['id'] ?? null;
            $id = $id instanceof ThemeId ? $id->value : null;
            $name = $row['name'] ?? null;
            if ($id === null || ! is_string($name)) {
                continue;
            }

            $themes[] = new ThemeListing($id, $name);
        }

        return $themes;
    }

    /**
     * `ThemeRegistry::activate()`'s own write. The admin-side write path
     * goes through `Admin\Extensions\ExtensionRepository::insertNamed()`/
     * `delete()` instead (raw DBAL against the `themes` table by string
     * id, no `ThemeEntity` involved at all). Unlike plugins, a `themes`
     * row's mere existence already means "active" -- there is no
     * persisted installed-but-inactive state, so `ThemeRegistry` only
     * ever needs insert/delete here, never an updateState() equivalent.
     */
    public function insert(ThemeId $id, string $version, ?string $name): void
    {
        $this->getEntityManager()
            ->persist(new ThemeEntity($id, $version, $name));
        $this->getEntityManager()
            ->flush();
    }

    public function delete(ThemeId $id): void
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

    /**
     * `ThemeRegistry::update()`'s own write -- `EntityRepository::
     * getEntityManager()` is protected, so a caller outside this class
     * can't flush a mutated entity's version directly; this wraps the
     * find+mutate+flush sequence the same way `PluginConfig\
     * PluginRepository::updateVersion()` already does for plugins.
     */
    public function updateVersion(ThemeId $id, string $version): void
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
