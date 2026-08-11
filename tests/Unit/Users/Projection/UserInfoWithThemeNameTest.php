<?php

declare(strict_types=1);

use Piwigo\Users\Projection\UserInfo;
use Piwigo\Users\Projection\UserInfoWithThemeName;

test('constructs with distinct values for every property', function (): void {
    $userInfo = UserInfo::fromRow([
        'user_id' => 5,
    ]);
    $row = new UserInfoWithThemeName($userInfo, 'elegant');

    expect($row->userInfo)
        ->toBe($userInfo)
        ->and($row->themeName)
        ->toBe('elegant');
});

test('toArray merges the wrapped UserInfo::toArray() shape with theme_name', function (): void {
    $userInfo = UserInfo::fromRow([
        'user_id' => 5,
        'theme' => 'default',
    ]);
    $row = new UserInfoWithThemeName($userInfo, 'elegant');

    expect($row->toArray())
        ->toBe([
            ...$userInfo->toArray(),
            'theme_name' => 'elegant',
        ]);
});

test('accepts a null themeName', function (): void {
    $row = new UserInfoWithThemeName(UserInfo::fromRow([
        'user_id' => 5,
    ]), null);

    expect($row->themeName)
        ->toBeNull()
        ->and($row->toArray()['theme_name'])->toBeNull();
});
