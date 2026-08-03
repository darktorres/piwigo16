<?php

declare(strict_types=1);

namespace Piwigo\Category;

use Piwigo\Db\Tables;

/**
 * Item 15 Sub-item D: {@see CategoryRepository::deleteInconsistentAccess()}'s
 * `$table`/`$field` pair, enumerated -- {@see CategoryService}'s own fixed
 * `[Tables::userAccess() => 'user_id', Tables::groupAccess() => 'group_id']`
 * map, confirmed via a fresh grep before converting. Stays on DBAL raw SQL
 * for the same custom-Doctrine-Type/plain-int mismatch reasoning as
 * {@see CategoryOrphanTarget}.
 */
enum CategoryAccessTarget
{
    case UserAccess;
    case GroupAccess;

    /**
     * @return array{0: string, 1: string} [table, field]
     */
    public function tableAndField(): array
    {
        return match ($this) {
            self::UserAccess => [Tables::userAccess(), 'user_id'],
            self::GroupAccess => [Tables::groupAccess(), 'group_id'],
        };
    }
}
