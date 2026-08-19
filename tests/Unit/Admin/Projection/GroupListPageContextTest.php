<?php

declare(strict_types=1);

use Piwigo\Admin\Projection\GroupListPageContext;
use Piwigo\Common\ValueObject\GroupId;
use Piwigo\Group\Projection\GroupListRow;

test('toArray flattens every property to its real Latte template variable name', function (): void {
    $context = new GroupListPageContext(
        addAction: '/admin.php?page=group_list',
        pwgToken: 'token123',
        cacheKeys: [
            'groups' => 'groups_5_20',
            'users' => 'users_3_10',
        ],
        adminPageTitle: 'Groups <span class="badge-number">5</span>',
        groups: [
            new GroupListRow(
                name: 'Family',
                id: GroupId::from(3),
                isDefaultLabel: '',
                nbMembers: 2,
                membersList: 'alice &middot; bob',
                membersLabel: '2 members',
                deleteUrl: '/admin.php?page=group_list&delete=3',
                permUrl: '/admin.php?page=group_perm&group_id=3',
                usersUrl: '/admin.php?page=user_list&group=3',
                toggleDefaultUrl: '/admin.php?page=group_list&toggle_is_default=3',
            ),
        ],
    );

    expect($context->toArray())
        ->toBe([
            'F_ADD_ACTION' => '/admin.php?page=group_list',
            'CSRF_TOKEN' => 'token123',
            'CACHE_KEYS' => [
                'groups' => 'groups_5_20',
                'users' => 'users_3_10',
            ],
            'ADMIN_PAGE_TITLE' => 'Groups <span class="badge-number">5</span>',
            'groups' => [[
                'NAME' => 'Family',
                'ID' => 3,
                'IS_DEFAULT' => '',
                'NB_MEMBERS' => 2,
                'L_MEMBERS' => 'alice &middot; bob',
                'MEMBERS' => '2 members',
                'U_DELETE' => '/admin.php?page=group_list&delete=3',
                'U_PERM' => '/admin.php?page=group_perm&group_id=3',
                'U_USERS' => '/admin.php?page=user_list&group=3',
                'U_ISDEFAULT' => '/admin.php?page=group_list&toggle_is_default=3',
            ]],
        ]);
});

test('toArray includes an empty groups list (not omitted)', function (): void {
    $context = new GroupListPageContext(
        addAction: '/admin.php?page=group_list',
        pwgToken: 'token123',
        cacheKeys: [],
        adminPageTitle: 'Groups',
        groups: [],
    );

    expect($context->toArray()['groups'])->toBe([]);
});
