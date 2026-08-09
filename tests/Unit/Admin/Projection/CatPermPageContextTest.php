<?php

declare(strict_types=1);

use Piwigo\Admin\Projection\CatPermPageContext;

test('toArray flattens every fixed property, and omits save_success/nb_users_granted_indirect/user_granted_indirect_groups when null', function (): void {
    $context = new CatPermPageContext(
        saveSuccess: null,
        categoriesNav: 'Home / Holidays',
        helpUrl: '/admin/popuphelp.php?page=cat_perm',
        fAction: '/admin.php?page=album-5-permissions',
        private: true,
        groups: [1 => 'Family'],
        groupsSelected: [1],
        users: [2 => 'jane'],
        usersSelected: [2],
        nbUsersGrantedIndirect: null,
        pwgToken: 'abc123',
        inherit: true,
        cacheKeys: ['groups' => 'x', 'users' => 'y'],
        userGrantedIndirectGroups: null,
    );

    expect($context->toArray())->toBe([
        'CATEGORIES_NAV' => 'Home / Holidays',
        'U_HELP' => '/admin/popuphelp.php?page=cat_perm',
        'F_ACTION' => '/admin.php?page=album-5-permissions',
        'private' => true,
        'groups' => [1 => 'Family'],
        'groups_selected' => [1],
        'users' => [2 => 'jane'],
        'users_selected' => [2],
        'PWG_TOKEN' => 'abc123',
        'INHERIT' => true,
        'CACHE_KEYS' => ['groups' => 'x', 'users' => 'y'],
    ]);
});

test('toArray includes save_success/nb_users_granted_indirect/user_granted_indirect_groups when set', function (): void {
    $context = new CatPermPageContext(
        saveSuccess: 'Album updated successfully',
        categoriesNav: 'Home / Holidays',
        helpUrl: '/admin/popuphelp.php?page=cat_perm',
        fAction: '/admin.php?page=album-5-permissions',
        private: false,
        groups: [],
        groupsSelected: [],
        users: [],
        usersSelected: [],
        nbUsersGrantedIndirect: 3,
        pwgToken: 'abc123',
        inherit: false,
        cacheKeys: [],
        userGrantedIndirectGroups: [['group_name' => 'Family', 'group_users' => 'jane, joe']],
    );

    $result = $context->toArray();

    expect($result['save_success'])->toBe('Album updated successfully')
        ->and($result['nb_users_granted_indirect'])->toBe(3)
        ->and($result['user_granted_indirect_groups'])->toBe([['group_name' => 'Family', 'group_users' => 'jane, joe']]);
});
