<?php

declare(strict_types=1);

namespace Piwigo\Category;

use Piwigo\Category\Projection\DqlPropertyTarget;
use Piwigo\Group\GroupAccessEntity;
use Piwigo\Image\ImageCategoryEntity;

/**
 * Enumerates the entity class/property pairs used by {@see
 * CategoryRepository::findOrphanedColumnValues()}/{@see
 * CategoryRepository::deleteRowsWhereColumnIn()}, matching {@see
 * CategoryService::checkCategoriesIntegrity()}'s own related-table
 * cleanup. `old_permalinks` used to be a 4th case here, handled via its
 * own raw-DBAL path (`old_permalinks` is owned by
 * `Piwigo\Permalink\OldPermalinkEntity`, `Permalink`/`L2bExtendedDomain`,
 * which this enum's own `Category`/`L2aCoreDomain` cannot depend on
 * directly) -- carved out into
 * {@see \Piwigo\Category\OldPermalinkLookupInterface::findOrphanedCatIds()}/
 * `deleteOldPermalinksForCatIds()` instead, called directly by
 * `checkCategoriesIntegrity()` alongside this enum's 3 remaining cases,
 * not through this dispatcher.
 *
 * All 3 remaining cases use real DQL: `getSingleColumnResult()` never
 * applies custom Doctrine Type conversion regardless of the underlying
 * column's mapped type (a VO-typed `catId` column still comes back as a
 * plain int through it), and a DQL `IN (:values)` delete bound with
 * `ArrayParameterType::INTEGER` against a VO-typed field works
 * correctly. `ImageCategory`'s `category` property is a real owning-side
 * association, not a scalar column, since the association-modeling
 * item's final wave -- {@see DqlPropertyTarget::$isAssociation} marks it
 * so the `SELECT`-context consumer wraps it in `IDENTITY()`.
 */
enum CategoryOrphanTarget
{
    case ImageCategory;
    case UserAccess;
    case GroupAccess;

    /**
     * [entity class, DQL property path for the category-id column]
     */
    public function entityClassAndProperty(): DqlPropertyTarget
    {
        return match ($this) {
            self::ImageCategory => new DqlPropertyTarget(ImageCategoryEntity::class, 'category', isAssociation: true),
            self::UserAccess => new DqlPropertyTarget(UserAccessEntity::class, 'catId'),
            self::GroupAccess => new DqlPropertyTarget(GroupAccessEntity::class, 'catId'),
        };
    }
}
