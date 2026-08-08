<?php

declare(strict_types=1);

use Piwigo\Admin\Projection\UserListPageContext;

$makeContext = fn (bool $showAddUser): UserListPageContext => new UserListPageContext(
    groupsForFilter: [['id' => 1, 'name' => 'Family', 'counter' => 3]],
    registerDates: '2026-01,2026-02',
    adminPageTitle: 'Users',
    activateComments: true,
    doublePassword: false,
    uHistory: '/admin.php?page=history&filter_user_id=',
    pwgToken: 'abc123',
    nbImagePage: 20,
    recentPeriod: 7,
    themeOptions: ['default' => 'Default'],
    themeSelected: 'default',
    languageOptions: ['en_GB' => 'English'],
    languageSelected: 'en_GB',
    associationOptions: [1 => 'Family'],
    protectedUsers: '1,2',
    passwordProtectedUsers: '2',
    guestUser: 2,
    filterGroup: '1',
    searchInput: 'id:5',
    connectedUser: 3,
    connectedUserStatus: 'admin',
    owner: 1,
    ownerUsername: 'webmaster',
    showAddUser: $showAddUser,
    labelOfStatus: ['admin' => 'Admin'],
    prefStatusOptions: ['admin' => 'Admin'],
    prefStatusSelected: 'normal',
    nbUsersByStatus: ['admin' => ['name' => 'Admin', 'counter' => 1]],
    levelOptions: [0 => 'Everybody'],
    levelSelected: 0,
    nbUsersByLevel: [0 => 'Everybody'],
    groupsArrId: '1,2',
    groupsArrName: '"Family","Friends"',
    guestId: 2,
    viewSelector: 'line',
    pagination: 5,
);

test('toArray flattens every fixed property, and omits show_add_user when false', function () use ($makeContext): void {
    $result = $makeContext(false)->toArray();

    expect($result)->not->toHaveKey('show_add_user')
        ->and($result['groups_for_filter'])->toBe([['id' => 1, 'name' => 'Family', 'counter' => 3]])
        ->and($result['ADMIN_PAGE_TITLE'])->toBe('Users')
        ->and($result['Double_Password'])->toBeFalse()
        ->and($result['nb_users_by_status'])->toBe(['admin' => ['name' => 'Admin', 'counter' => 1]])
        ->and($result['pagination'])->toBe(5);
});

test('toArray includes show_add_user when true', function () use ($makeContext): void {
    $result = $makeContext(true)->toArray();

    expect($result['show_add_user'])->toBeTrue();
});
