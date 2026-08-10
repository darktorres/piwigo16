<?php

declare(strict_types=1);

use Piwigo\Category\CategoryAccessTarget;
use Piwigo\Category\UserAccessEntity;
use Piwigo\Group\GroupAccessEntity;

/**
 * Piwigo\Category\CategoryAccessTarget -- CategoryRepository::
 * deleteInconsistentAccess()'s $table/$field pair, enumerated. No
 * dedicated Integration/Browser spec of its own.
 */
test('entityClassAndFieldProperty maps UserAccess to the user_id keep column on UserAccessEntity', function (): void {
    $target = CategoryAccessTarget::UserAccess->entityClassAndFieldProperty();

    expect($target->entityClass)->toBe(UserAccessEntity::class)
        ->and($target->property)->toBe('userId');
});

test('entityClassAndFieldProperty maps GroupAccess to the group_id keep column on GroupAccessEntity', function (): void {
    $target = CategoryAccessTarget::GroupAccess->entityClassAndFieldProperty();

    expect($target->entityClass)->toBe(GroupAccessEntity::class)
        ->and($target->property)->toBe('groupId');
});
