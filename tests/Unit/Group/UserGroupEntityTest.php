<?php

declare(strict_types=1);

use Piwigo\Group\UserGroupEntity;

test('constructs with distinct values for every property', function (): void {
    $userGroup = new UserGroupEntity(groupId: 5, userId: 17);

    expect($userGroup->groupId)->toBe(5)
        ->and($userGroup->userId)->toBe(17);
});
