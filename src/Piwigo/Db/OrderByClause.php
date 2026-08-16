<?php

declare(strict_types=1);

namespace Piwigo\Db;

/**
 * One rendered `ORDER BY` entry: a DQL expression and its direction.
 *
 * {@see SortRenderer::toDql()}'s per-entry output, previously duplicated as
 * a raw `array{property: string, dir: 'ASC'|'DESC'}` by three consumers
 * ({@see \Piwigo\Category\CategoryRepository::findImageIdsForCategoriesViaDql()},
 * {@see \Piwigo\Calendar\CalendarRepository::findImageIds()} and
 * {@see \Piwigo\Users\UserRepository::findVisibleFavoriteImageIds()}).
 *
 * `$property` is usually an entity property path (`i.dateAvailable`), but
 * may be a registered DQL function call (`RAND()`) -- Doctrine's grammar
 * accepts a FunctionDeclaration as an ORDER BY item.
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
