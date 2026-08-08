<?php

declare(strict_types=1);

namespace Piwigo\Category;

use Piwigo\Category\Projection\DqlPropertyTarget;
use Piwigo\Group\GroupAccessEntity;

/**
 * Item 15 Sub-item D: {@see CategoryRepository::deleteInconsistentAccess()}'s
 * `$table`/`$field` pair, enumerated -- {@see CategoryService}'s own fixed
 * `[Tables::userAccess() => 'user_id', Tables::groupAccess() => 'group_id']`
 * map, confirmed via a fresh grep before converting.
 *
 * Item 16I: converted to real DQL via {@see entityClassAndFieldProperty()}
 * -- same "getSingleColumnResult()/IN (:values) with ArrayParameterType::
 * INTEGER both sidestep the custom-Type mismatch" empirical finding as
 * {@see CategoryOrphanTarget}'s own re-audit. Both target entities'
 * category-id column is named `catId` (no per-case dispatch needed for
 * that half); only the `user_id`/`group_id` "keep" field genuinely
 * varies per case.
 */
enum CategoryAccessTarget
{
    case UserAccess;
    case GroupAccess;

    /**
     * [entity class, DQL property path for the user_id/group_id "keep" column]
     */
    public function entityClassAndFieldProperty(): DqlPropertyTarget
    {
        return match ($this) {
            self::UserAccess => new DqlPropertyTarget(UserAccessEntity::class, 'userId'),
            self::GroupAccess => new DqlPropertyTarget(GroupAccessEntity::class, 'groupId'),
        };
    }
}
