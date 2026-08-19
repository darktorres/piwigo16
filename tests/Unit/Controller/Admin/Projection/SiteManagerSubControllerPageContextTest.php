<?php

declare(strict_types=1);

use Piwigo\Controller\Admin\Projection\SiteManagerSubControllerPageContext;
use Piwigo\Controller\Admin\Projection\SiteRow;

test('toArray flattens every property to its real Latte template variable name', function (): void {
    $context = new SiteManagerSubControllerPageContext(
        formAction: '/admin.php?page=site_manager',
        pwgToken: 'token123',
        adminPageTitle: 'Synchronize',
        sites: [
            new SiteRow(
                name: '/var/www/gallery',
                type: 'Local',
                categories: 3,
                images: 42,
                uSynchronize: '/admin.php?page=site_update&site=1',
                uDelete: null,
                pluginLinks: [],
            ),
        ],
    );

    expect($context->toArray())
        ->toBe([
            'F_ACTION' => '/admin.php?page=site_manager',
            'CSRF_TOKEN' => 'token123',
            'ADMIN_PAGE_TITLE' => 'Synchronize',
            'sites' => [[
                'NAME' => '/var/www/gallery',
                'TYPE' => 'Local',
                'CATEGORIES' => 3,
                'IMAGES' => 42,
                'U_SYNCHRONIZE' => '/admin.php?page=site_update&site=1',
                'plugin_links' => [],
            ]],
        ]);
});

test('toArray includes an empty sites list (not omitted)', function (): void {
    $context = new SiteManagerSubControllerPageContext(
        formAction: '/admin.php?page=site_manager',
        pwgToken: 'token123',
        adminPageTitle: 'Synchronize',
        sites: [],
    );

    expect($context->toArray()['sites'])->toBe([]);
});
