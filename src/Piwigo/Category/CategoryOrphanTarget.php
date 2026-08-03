<?php

declare(strict_types=1);

namespace Piwigo\Category;

use Piwigo\Db\Tables;
use Piwigo\Group\GroupAccessEntity;
use Piwigo\Image\ImageCategoryEntity;

/**
 * Item 15 Sub-item D: {@see CategoryRepository::findOrphanedColumnValues()}/
 * {@see CategoryRepository::deleteRowsWhereColumnIn()}'s `$table`/`$column`
 * pair, enumerated -- {@see CategoryService::checkCategoriesIntegrity()}'s
 * own fixed 4-row `$relatedColumns` list, confirmed via a fresh grep before
 * converting.
 *
 * Item 16I: 3 of the 4 cases converted to real DQL via
 * {@see entityClassAndProperty()} -- the original "stays on DBAL,
 * `category_id`/`cat_id` hydrates as a `CategoryId` VO on 3 of 4 targets
 * but plain int on the 4th, mixed-type IN clause is real added risk"
 * reasoning didn't hold up under direct empirical verification:
 * `getSingleColumnResult()` never applies custom Doctrine Type
 * conversion regardless of the underlying column's mapped type
 * (confirmed live -- a VO-typed `catId` column still comes back as a
 * plain int through it), and a DQL `IN (:values)` delete bound with
 * `ArrayParameterType::INTEGER` against a VO-typed field works correctly
 * (same established pattern already used throughout this campaign, and
 * live-probed again here specifically against `GroupAccessEntity::$catId`
 * before converting).
 *
 * `OldPermalinks` stays on `tableAndColumn()`'s own raw DBAL path --
 * `old_permalinks` is owned by `Piwigo\Permalink\OldPermalinkEntity`
 * (`Permalink`, `L2bExtendedDomain`), and this enum lives in `Category`
 * (`L2aCoreDomain`), which can't depend on it directly (confirmed live
 * via `deptrac analyse`, not assumed) -- the exact same real boundary
 * Item 16E/16F's own Category/Site cases hit, not the VO-typing
 * question the other 3 cases turned out not to have.
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
