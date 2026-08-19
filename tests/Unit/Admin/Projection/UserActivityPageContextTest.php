<?php

declare(strict_types=1);

use Piwigo\Admin\Projection\ActivityDateRange;
use Piwigo\Admin\Projection\UserActivityActionRow;
use Piwigo\Admin\Projection\UserActivityPageContext;
use Piwigo\Admin\Projection\UserActivityUserRow;

test('toArray flattens every property to its real Latte template variable name', function (): void {
    $context = new UserActivityPageContext(
        adminPageTitle: 'Users',
        pwgToken: 'abc123',
        inherit: true,
        cacheKeys: [
            'users' => 'def456',
        ],
        ulist: [
            new UserActivityUserRow(id: 1, username: 'jane', nbLines: 5),
        ],
        nbUsers: 3,
        activityDates: new ActivityDateRange(min: '2026-01-01', max: '2026-08-08'),
        additionalFiltType: 'photo',
        additionalFiltName: 'sunset.jpg',
        additionalFiltValue: '42',
        actions: [
            new UserActivityActionRow(object: 'image', action: 'add', counter: 2, value: 'image/add'),
        ],
    );

    expect($context->toArray())
        ->toBe([
            'ADMIN_PAGE_TITLE' => 'Users',
            'CSRF_TOKEN' => 'abc123',
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
        activityDates: new ActivityDateRange(min: '', max: ''),
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
