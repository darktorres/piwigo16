<?php

declare(strict_types=1);

namespace Piwigo\Category;

use Piwigo\Category\Projection\DqlPropertyTarget;
use Piwigo\Category\Projection\TableColumnTarget;
use Piwigo\Db\Tables;
use Piwigo\Group\GroupAccessEntity;
use Piwigo\Image\ImageCategoryEntity;

/**
 * Enumerates the `$table`/`$column` pairs used by {@see
 * CategoryRepository::findOrphanedColumnValues()}/{@see
 * CategoryRepository::deleteRowsWhereColumnIn()}, matching {@see
 * CategoryService::checkCategoriesIntegrity()}'s own fixed 4-row
 * `$relatedColumns` list.
 *
 * 3 of the 4 cases use real DQL via {@see entityClassAndProperty()}:
 * `getSingleColumnResult()` never applies custom Doctrine Type conversion
 * regardless of the underlying column's mapped type (a VO-typed `catId`
 * column still comes back as a plain int through it), and a DQL
 * `IN (:values)` delete bound with `ArrayParameterType::INTEGER` against a
 * VO-typed field works correctly.
 *
 * `OldPermalinks` stays on `tableAndColumn()`'s own raw DBAL path:
 * `old_permalinks` is owned by `Piwigo\Permalink\OldPermalinkEntity`
 * (`Permalink`, `L2bExtendedDomain`), and this enum lives in `Category`
 * (`L2aCoreDomain`), which cannot depend on it directly.
 */
enum CategoryOrphanTarget
{
    case ImageCategory;
    case UserAccess;
    case GroupAccess;
    case OldPermalinks;

    public function tableAndColumn(): TableColumnTarget
    {
        return match ($this) {
            self::ImageCategory => new TableColumnTarget(Tables::imageCategory(), 'category_id'),
            self::UserAccess => new TableColumnTarget(Tables::userAccess(), 'cat_id'),
            self::GroupAccess => new TableColumnTarget(Tables::groupAccess(), 'cat_id'),
            self::OldPermalinks => new TableColumnTarget(Tables::oldPermalinks(), 'cat_id'),
        };
    }

    /**
     * [entity class, DQL property path for the category-id column], or
     * null for OldPermalinks (see this enum's own docblock)
     */
    public function entityClassAndProperty(): ?DqlPropertyTarget
    {
        return match ($this) {
            self::ImageCategory => new DqlPropertyTarget(ImageCategoryEntity::class, 'categoryId'),
            self::UserAccess => new DqlPropertyTarget(UserAccessEntity::class, 'catId'),
            self::GroupAccess => new DqlPropertyTarget(GroupAccessEntity::class, 'catId'),
            self::OldPermalinks => null,
        };
    }
}
