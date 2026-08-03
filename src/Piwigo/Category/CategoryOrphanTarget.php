<?php

declare(strict_types=1);

namespace Piwigo\Category;

use Piwigo\Db\Tables;

/**
 * Item 15 Sub-item D: {@see CategoryRepository::findOrphanedColumnValues()}/
 * {@see CategoryRepository::deleteRowsWhereColumnIn()}'s `$table`/`$column`
 * pair, enumerated -- {@see CategoryService::checkCategoriesIntegrity()}'s
 * own fixed 4-row `$relatedColumns` list, confirmed via a fresh grep before
 * converting. Stays on DBAL raw SQL rather than DQL: `category_id`/`cat_id`
 * hydrate as a `CategoryId` VO on 3 of the 4 target entities
 * ({@see \Piwigo\Group\GroupAccessEntity}/{@see OldPermalinkEntity}/
 * {@see \Piwigo\Image\ImageCategoryEntity}) but a plain int on the 4th
 * ({@see UserAccessEntity}) -- binding a caller-supplied string/int list
 * against that mixed custom-type/plain-type IN clause is real added risk
 * for a permission-sensitive bulk delete, not worth taking on in the same
 * change as closing the actual gap here (an arbitrary runtime string ->
 * a bounded, compile-time-safe enum).
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
}
