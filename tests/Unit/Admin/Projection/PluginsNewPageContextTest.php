<?php

declare(strict_types=1);

use Piwigo\Admin\Projection\PluginsNewPageContext;

test('toArray flattens every fixed property, and omits the 2 optional keys when null', function (): void {
    $context = new PluginsNewPageContext(
        orderOptions: [
            'date' => 'Post date',
            'name' => 'Name',
        ],
        orderSelected: null,
        betaUrl: null,
        adminPageTitle: 'Plugins',
        betaTest: false,
        plugins: [],
    );

    expect($context->toArray())
        ->toBe([
            'order_options' => [
                'date' => 'Post date',
                'name' => 'Name',
            ],
            'ADMIN_PAGE_TITLE' => 'Plugins',
            'BETA_TEST' => false,
            'plugins' => [],
        ]);
});

test('toArray includes order_selected/BETA_URL when set', function (): void {
    $context = new PluginsNewPageContext(
        orderOptions: [
            'date' => 'Post date',
        ],
        orderSelected: 'date',
        betaUrl: '/admin.php?page=plugins&tab=new&beta-test=true',
        adminPageTitle: 'Plugins',
        betaTest: true,
        plugins: [[
            'ID' => '42',
            'EXT_NAME' => 'Foo',
        ]],
    );

    expect($context->toArray())
        ->toBe([
            'order_options' => [
                'date' => 'Post date',
            ],
            'ADMIN_PAGE_TITLE' => 'Plugins',
            'BETA_TEST' => true,
            'plugins' => [[
                'ID' => '42',
                'EXT_NAME' => 'Foo',
            ]],
            'order_selected' => 'date',
            'BETA_URL' => '/admin.php?page=plugins&tab=new&beta-test=true',
        ]);
});
