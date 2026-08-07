<?php

declare(strict_types=1);

namespace Piwigo\Core;

use Doctrine\ORM\EntityRepository;
use Piwigo\Common\ValueObject\ThemeId;

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

            $themes[] = [
                'id' => $id,
                'name' => $name,
            ];
        }

        return $themes;
    }
}
