<?php

declare(strict_types=1);

namespace Piwigo\Category;

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

    /**
     * @return array{0: string, 1: string} [table, column]
     */
    public function tableAndColumn(): array
    {
        return match ($this) {
            self::ImageCategory => [Tables::imageCategory(), 'category_id'],
            self::UserAccess => [Tables::userAccess(), 'cat_id'],
            self::GroupAccess => [Tables::groupAccess(), 'cat_id'],
            self::OldPermalinks => [Tables::oldPermalinks(), 'cat_id'],
        };
    }

    /**
     * @return array{0: class-string, 1: string}|null [entity class, DQL property path for the category-id column], or null for OldPermalinks (see this enum's own docblock)
     */
    public function entityClassAndProperty(): ?array
    {
        return match ($this) {
            self::ImageCategory => [ImageCategoryEntity::class, 'categoryId'],
            self::UserAccess => [UserAccessEntity::class, 'catId'],
            self::GroupAccess => [GroupAccessEntity::class, 'catId'],
            self::OldPermalinks => null,
        };
    }
}
