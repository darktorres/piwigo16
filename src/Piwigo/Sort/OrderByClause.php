<?php

declare(strict_types=1);

namespace Piwigo\Sort;

/**
 * One `ORDER BY` entry -- {@see PhotoSortField::resolveDqlOrderBy()}'s own
 * per-entry shape, independently duplicated as a raw
 * `array{property: string, dir: 'ASC'|'DESC'}` by 3 real consumers
 * ({@see \Piwigo\Category\CategoryRepository::findImageIdsForCategoriesViaDql()},
 * {@see \Piwigo\Calendar\CalendarRepository::findRandomImageForDay()}, and
 * {@see \Piwigo\Users\UserRepository::findVisibleFavoriteImageIds()}) before
 * this DTO unified them.
 */
final readonly class OrderByClause
{
    /**
     * @param 'ASC'|'DESC' $dir
     */
    public function __construct(
        public string $property,
        public string $dir,
    ) {}
}
