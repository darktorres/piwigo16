<?php

declare(strict_types=1);

namespace Piwigo\Image;

use Piwigo\Permission\SqlCondition;

/**
 * Further SQL-modernization audit, Item 13: replaces the `list<string>
 * $where_clauses` `Ws\PwgCategories::getImages()` used to build --
 * `WsHelper::stdImageSqlFilter()`'s own SqlCondition, a `category_id IN
 * (...)` restriction to the already-resolved, permission-filtered category
 * id set, and a `visible_images`-only permission condition
 * (`PermissionService::getSqlConditionFandFAsCondition()`'s own output).
 * `ImageRepository::findWithConditionsPaginated()` combines these
 * internally via `SqlCondition::combine()`.
 */
final readonly class CategoryImagesCriteria
{
    /**
     * @param  list<int>  $categoryIds
     */
    public function __construct(
        public SqlCondition $filterCondition,
        public array $categoryIds,
        public SqlCondition $visibleImagesCondition,
    ) {}
}
