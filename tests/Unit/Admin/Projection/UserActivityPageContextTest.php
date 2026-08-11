<?php

declare(strict_types=1);

use Piwigo\Admin\Projection\UserActivityPageContext;

test('toArray flattens every property to its real Smarty template variable name', function (): void {
    $context = new UserActivityPageContext(
        adminPageTitle: 'Users',
        pwgToken: 'abc123',
        inherit: true,
        cacheKeys: [
            'users' => 'def456',
        ],
        ulist: [[
            'id' => 1,
            'username' => 'jane',
            'nb_lines' => 5,
        ]],
        nbUsers: 3,
        activityDates: [
            'min' => '2026-01-01',
            'max' => '2026-08-08',
        ],
        additionalFiltType: 'photo',
        additionalFiltName: 'sunset.jpg',
        additionalFiltValue: '42',
        actions: [[
            'object' => 'image',
            'action' => 'add',
            'counter' => 2,
            'value' => 'image/add',
        ]],
    );

    expect($context->toArray())
        ->toBe([
            'ADMIN_PAGE_TITLE' => 'Users',
            'PWG_TOKEN' => 'abc123',
            'INHERIT' => true,
            'CACHE_KEYS' => [
                'users' => 'def456',
            ],
            'ulist' => [[
                'id' => 1,
                'username' => 'jane',
                'nb_lines' => 5,
            ]],
            'nb_users' => 3,
            'ACTIVITY_DATES' => [
                'min' => '2026-01-01',
                'max' => '2026-08-08',
            ],
            'ADDITIONAL_FILT' => [
                'type' => 'photo',
                'name' => 'sunset.jpg',
                'value' => '42',
            ],
            'ACTIONS' => [[
                'object' => 'image',
                'action' => 'add',
                'counter' => 2,
                'value' => 'image/add',
            ]],
        ]);
});

test('toArray reflects a no-filter state (type=false, name/value=null)', function (): void {
    $context = new UserActivityPageContext(
        adminPageTitle: 'Users',
        pwgToken: 'abc123',
        inherit: false,
        cacheKeys: [],
        ulist: [],
        nbUsers: 0,
        activityDates: [
            'min' => '',
            'max' => '',
        ],
        additionalFiltType: false,
        additionalFiltName: null,
        additionalFiltValue: null,
        actions: [],
    );

    expect($context->toArray()['ADDITIONAL_FILT'])->toBe([
        'type' => false,
        'name' => null,
        'value' => null,
    ]);
});
