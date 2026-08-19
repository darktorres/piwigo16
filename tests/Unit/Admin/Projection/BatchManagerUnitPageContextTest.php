<?php

declare(strict_types=1);

use Piwigo\Admin\Projection\BatchManagerUnitPageContext;
use Piwigo\Core\Projection\Navbar;

test('toArray flattens every fixed property, and omits navbar/STORAGE_CATEGORY/ELEMENT_IDS when null', function (): void {
    $context = new BatchManagerUnitPageContext(
        uElementsPage: '/admin.php',
        levelOptions: [
            0 => 'Everybody',
        ],
        adminPageTitle: 'Batch Manager',
        pwgToken: 'abc123',
        activePlugins: ['foo'],
        perPage: 5,
        navbar: null,
        storageCategory: null,
        elementIds: null,
        cacheKeys: [
            'tags' => 'x',
            'categories' => 'y',
        ],
        elements: [],
    );

    expect($context->toArray())
        ->toBe([
            'U_ELEMENTS_PAGE' => '/admin.php',
            'level_options' => [
                0 => 'Everybody',
            ],
            'ADMIN_PAGE_TITLE' => 'Batch Manager',
            'CSRF_TOKEN' => 'abc123',
            'ACTIVE_PLUGINS' => ['foo'],
            'per_page' => 5,
            'elements' => [],
            'CACHE_KEYS' => [
                'tags' => 'x',
                'categories' => 'y',
            ],
        ]);
});

test('toArray includes navbar/STORAGE_CATEGORY/ELEMENT_IDS when set', function (): void {
    $context = new BatchManagerUnitPageContext(
        uElementsPage: '/admin.php',
        levelOptions: [
            0 => 'Everybody',
        ],
        adminPageTitle: 'Batch Manager',
        pwgToken: 'abc123',
        activePlugins: ['foo'],
        perPage: 5,
        navbar: new Navbar(nbPage: 3),
        storageCategory: 'Holidays',
        elementIds: '1,2,3',
        cacheKeys: [
            'tags' => 'x',
            'categories' => 'y',
        ],
        elements: [[
            'ID' => '5',
            'TITLE' => 'Sunset',
        ]],
    );

    expect($context->toArray())
        ->toBe([
            'U_ELEMENTS_PAGE' => '/admin.php',
            'level_options' => [
                0 => 'Everybody',
            ],
            'ADMIN_PAGE_TITLE' => 'Batch Manager',
            'CSRF_TOKEN' => 'abc123',
            'ACTIVE_PLUGINS' => ['foo'],
            'per_page' => 5,
            'elements' => [[
                'ID' => '5',
                'TITLE' => 'Sunset',
            ]],
            'navbar' => [
                'NB_PAGE' => 3,
            ],
            'STORAGE_CATEGORY' => 'Holidays',
            'ELEMENT_IDS' => '1,2,3',
            'CACHE_KEYS' => [
                'tags' => 'x',
                'categories' => 'y',
            ],
        ]);
});
