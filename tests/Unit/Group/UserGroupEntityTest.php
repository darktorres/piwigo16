<?php

declare(strict_types=1);

use Piwigo\Common\ValueObject\GroupId;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Group\UserGroupEntity;

test('constructs with distinct values for every property', function (): void {
    $userGroup = new UserGroupEntity(groupId: GroupId::from(5), userId: UserId::from(17));

    expect($userGroup->groupId->value)
        ->toBe(5)
        ->and($userGroup->userId->value)
        ->toBe(17);
});
