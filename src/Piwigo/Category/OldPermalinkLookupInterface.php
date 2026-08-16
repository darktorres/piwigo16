<?php

declare(strict_types=1);

namespace Piwigo\Category;

/**
 * Seam {@see CategoryService}'s own `findCategoryIdFromPermalinks()`/
 * `deleteCategories()` methods take as an explicit parameter (not
 * constructor-injected -- both have many real construction/call sites
 * across every layer, some in `L2bExtendedDomain` itself, so constructor
 * injection would just relocate the same deptrac violation one level up
 * the call stack). `Category` is `L2aCoreDomain`, `Permalink` (whose
 * {@see \Piwigo\Permalink\OldPermalinkEntity} this seam's real
 * implementation queries) is `L2bExtendedDomain` -- L2a may not depend
 * upward on L2b. Implemented by {@see \Piwigo\Permalink\PermalinkRepository},
 * constructed directly by each real caller (all either `L2bExtendedDomain`
 * itself, same layer as `Permalink`, or `L4Integration`, unrestricted).
 */
interface OldPermalinkLookupInterface
{
    /**
     * Matches $permalinks against both the current `categories.permalink`
     * column and the `old_permalinks` redirect table, keyed by the
     * permalink string. `is_old` distinguishes which table matched (a
     * match in `old_permalinks` needs its hit counter touched via
     * touchOldPermalinkHit()).
     *
     * @param  list<string>  $permalinks
     * @return array<string, array{id: int, permalink: string, is_old: int}>
     */
    public function findPermalinkMatches(array $permalinks): array;

    public function touchOldPermalinkHit(string $permalink, int $catId): void;
}
