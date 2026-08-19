<?php

declare(strict_types=1);

use Piwigo\Admin\Projection\RatingPageContext;
use Piwigo\Admin\Projection\RatingReportImageRow;
use Piwigo\Core\Projection\Navbar;

test('toArray flattens every property to its real Latte template variable name', function (): void {
    $context = new RatingPageContext(
        navbar: new Navbar(nbPage: 3),
        fAction: '/admin.php',
        display: 20,
        nbElements: 42,
        category: [5],
        cacheKeys: [
            'categories' => 'abc123',
        ],
        orderByOptionsSelected: [0],
        userOptions: [
            'all' => 'all',
            'user' => 'Users',
            'guest' => 'Guests',
        ],
        userOptionsSelected: ['all'],
        adminPageTitle: 'Rating',
        orderByOptions: ['Rate date', 'Rating score'],
        images: [
            new RatingReportImageRow(
                id: 5,
                uThumb: '/i.php?/5-sq.jpg',
                uUrl: '/admin.php?page=photo-5',
                scoreRate: 4.5,
                avgRate: 4.5,
                sumRate: 9.0,
                nbRates: 2,
                nbRatesTotal: 2,
                file: 'photo.jpg',
                rates: [],
            ),
        ],
    );

    expect($context->toArray())
        ->toBe([
            'navbar' => [
                'NB_PAGE' => 3,
            ],
            'F_ACTION' => '/admin.php',
            'DISPLAY' => 20,
            'NB_ELEMENTS' => 42,
            'category' => [5],
            'CACHE_KEYS' => [
                'categories' => 'abc123',
            ],
            'order_by_options_selected' => [0],
            'user_options' => [
                'all' => 'all',
                'user' => 'Users',
                'guest' => 'Guests',
            ],
            'user_options_selected' => ['all'],
            'ADMIN_PAGE_TITLE' => 'Rating',
            'order_by_options' => ['Rate date', 'Rating score'],
            'images' => [[
                'id' => 5,
                'U_THUMB' => '/i.php?/5-sq.jpg',
                'U_URL' => '/admin.php?page=photo-5',
                'SCORE_RATE' => 4.5,
                'AVG_RATE' => 4.5,
                'SUM_RATE' => 9.0,
                'NB_RATES' => 2,
                'NB_RATES_TOTAL' => 2,
                'FILE' => 'photo.jpg',
                'rates' => [],
            ]],
        ]);
});

test('toArray includes empty order_by_options/images lists (not omitted)', function (): void {
    $context = new RatingPageContext(
        navbar: Navbar::none(),
        fAction: '/admin.php',
        display: 20,
        nbElements: 0,
        category: [],
        cacheKeys: [],
        orderByOptionsSelected: [],
        userOptions: [],
        userOptionsSelected: [],
        adminPageTitle: 'Rating',
        orderByOptions: [],
        images: [],
    );

    expect($context->toArray()['order_by_options'])->toBe([])
        ->and($context->toArray()['images'])->toBe([]);
});
