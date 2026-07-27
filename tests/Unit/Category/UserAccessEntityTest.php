<?php

declare(strict_types=1);

use Piwigo\Category\UserAccessEntity;

test('constructs with distinct values for every property', function (): void {
    $userAccess = new UserAccessEntity(userId: 7, catId: 42);

    expect($userAccess->userId)->toBe(7)
        ->and($userAccess->catId)->toBe(42);
});
