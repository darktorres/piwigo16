<?php

declare(strict_types=1);

use Piwigo\Admin\Projection\PluginsInstalledPageContext;

test('toArray flattens every property to its real Smarty template variable name, casting isWebmaster to an int', function (): void {
    $context = new PluginsInstalledPageContext(
        plugins: [[
            'ID' => 'foo',
            'NAME' => 'Foo',
        ]],
        countTypesPlugins: [
            'active' => 2,
            'inactive' => 1,
            'missing' => 0,
            'merged' => 0,
        ],
        pwgToken: 'token123',
        baseUrl: '/admin.php?page=plugins',
        showDetails: true,
        maxInactiveBeforeHide: 8,
        isWebmaster: true,
        adminPageTitle: 'Plugins',
        viewSelector: 'classic',
        enableExtensionsInstall: false,
        pluginStates: ['active', 'inactive', 'merged'],
    );

    expect($context->toArray())
        ->toBe([
            'plugins' => [[
                'ID' => 'foo',
                'NAME' => 'Foo',
            ]],
            'count_types_plugins' => [
                'active' => 2,
                'inactive' => 1,
                'missing' => 0,
                'merged' => 0,
            ],
            'PWG_TOKEN' => 'token123',
            'base_url' => '/admin.php?page=plugins',
            'show_details' => true,
            'max_inactive_before_hide' => 8,
            'isWebmaster' => 1,
            'ADMIN_PAGE_TITLE' => 'Plugins',
            'view_selector' => 'classic',
            'CONF_ENABLE_EXTENSIONS_INSTALL' => false,
            'plugin_states' => ['active', 'inactive', 'merged'],
        ]);
});

test('toArray casts isWebmaster false to 0', function (): void {
    $context = new PluginsInstalledPageContext(
        plugins: [],
        countTypesPlugins: [],
        pwgToken: '',
        baseUrl: '',
        showDetails: false,
        maxInactiveBeforeHide: 999,
        isWebmaster: false,
        adminPageTitle: '',
        viewSelector: 'classic',
        enableExtensionsInstall: true,
        pluginStates: ['active', 'inactive'],
    );

    expect($context->toArray()['isWebmaster'])->toBe(0);
});
