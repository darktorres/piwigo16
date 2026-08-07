<?php

declare(strict_types=1);

use Piwigo\Category\UserAccessEntity;
use Piwigo\Common\ValueObject\CategoryId;
use Piwigo\Common\ValueObject\UserId;

test('constructs with distinct values for every property', function (): void {
    $userAccess = new UserAccessEntity(userId: UserId::from(7), catId: CategoryId::from(42));

    expect($userAccess->userId)->toEqual(UserId::from(7))
        ->and($userAccess->catId)->toEqual(CategoryId::from(42));
});
