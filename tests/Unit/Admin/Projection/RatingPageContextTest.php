<?php

declare(strict_types=1);

use Piwigo\Admin\Projection\RatingPageContext;

test('toArray flattens every property to its real Smarty template variable name', function (): void {
    $context = new RatingPageContext(
        navbar: [
            'NB_PAGE' => 3,
        ],
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
        images: [[
            'id' => 5,
            'FILE' => 'photo.jpg',
        ]],
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
                'FILE' => 'photo.jpg',
            ]],
        ]);
});

test('toArray includes empty order_by_options/images lists (not omitted)', function (): void {
    $context = new RatingPageContext(
        navbar: [],
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
