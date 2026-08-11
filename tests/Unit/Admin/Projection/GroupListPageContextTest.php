<?php

declare(strict_types=1);

use Piwigo\Admin\Projection\GroupListPageContext;

test('toArray flattens every property to its real Smarty template variable name', function (): void {
    $context = new GroupListPageContext(
        addAction: '/admin.php?page=group_list',
        pwgToken: 'token123',
        cacheKeys: [
            'groups' => 'groups_5_20',
            'users' => 'users_3_10',
        ],
        adminPageTitle: 'Groups <span class="badge-number">5</span>',
        groups: [[
            'NAME' => 'Family',
            'ID' => 3,
        ]],
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
