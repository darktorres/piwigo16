<?php

declare(strict_types=1);

namespace Piwigo\Core;

use Doctrine\ORM\EntityRepository;

/**
 * Persistence layer for the `themes` table's own id/name listing below.
 *
 * Item 15 audit: converted to real DQL against {@see ThemeEntity} --
 * `themes` itself is entity-mapped (added in Item 14 Sub-phase B1 for
 * `Users\UserRepository`'s own DQL conversions), and this class's own
 * former docblock claim that it had "no other real caller to share an
 * entity-based repository with" was about needing a *shared* repository,
 * not about DQL itself being unreachable -- `EntityRepository<ThemeEntity>`
 * needs no other caller to be worth using here.
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
     * @return list<array{id: string, name: string}>
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
            if (! is_array($row) || ! is_string($row['id'] ?? null) || ! is_string($row['name'] ?? null)) {
                continue;
            }

            $themes[] = [
                'id' => $row['id'],
                'name' => $row['name'],
            ];
        }

        return $themes;
    }
}
