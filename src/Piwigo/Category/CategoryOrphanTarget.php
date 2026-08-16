<?php

declare(strict_types=1);

namespace Piwigo\Category;

use Piwigo\Category\Projection\DqlPropertyTarget;
use Piwigo\Category\Projection\TableColumnTarget;
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
 * VO-typed field works correctly. `ImageCategory`'s `category` property is
 * a real owning-side association, not a scalar column, since the
 * association-modeling item's final wave -- {@see DqlPropertyTarget
 * ::$isAssociation} marks it so the `SELECT`-context consumer wraps it in
 * `IDENTITY()`.
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
            self::ImageCategory => new TableColumnTarget('image_category', 'category_id'),
            self::UserAccess => new TableColumnTarget('user_access', 'cat_id'),
            self::GroupAccess => new TableColumnTarget('group_access', 'cat_id'),
            self::OldPermalinks => new TableColumnTarget('old_permalinks', 'cat_id'),
        };
    }

    /**
     * [entity class, DQL property path for the category-id column], or
     * null for OldPermalinks (see this enum's own docblock)
     */
    public function entityClassAndProperty(): ?DqlPropertyTarget
    {
        return match ($this) {
            self::ImageCategory => new DqlPropertyTarget(ImageCategoryEntity::class, 'category', isAssociation: true),
            self::UserAccess => new DqlPropertyTarget(UserAccessEntity::class, 'catId'),
            self::GroupAccess => new DqlPropertyTarget(GroupAccessEntity::class, 'catId'),
            self::OldPermalinks => null,
        };
    }
}
