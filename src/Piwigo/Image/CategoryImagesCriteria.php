<?php

declare(strict_types=1);

namespace Piwigo\Image;

use Piwigo\Permission\SqlCondition;

/**
 * Replaces the `list<string> $where_clauses`
 * `Ws\Categories::getImages()` used to build -- an
 * {@see ImageFilterCriteria}, a `category_id IN (...)` restriction to the
 * already-resolved, permission-filtered category id set, and a
 * `visible_images`-only permission condition (`PermissionService::
 * getSqlConditionFandFAsCondition()`'s own output).
 * `ImageRepository::findWithConditionsPaginated()` combines these
 * internally.
 */
final readonly class CategoryImagesCriteria
{
    /**
     * @param  list<int>  $categoryIds
     */
    public function __construct(
        public ImageFilterCriteria $filterCriteria,
        public array $categoryIds,
        public SqlCondition $visibleImagesCondition,
    ) {}
}
