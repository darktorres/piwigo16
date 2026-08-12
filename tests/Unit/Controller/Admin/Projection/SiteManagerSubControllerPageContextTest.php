<?php

declare(strict_types=1);

use Piwigo\Controller\Admin\Projection\SiteManagerSubControllerPageContext;

test('toArray flattens every property to its real Latte template variable name', function (): void {
    $context = new SiteManagerSubControllerPageContext(
        formAction: '/admin.php?page=site_manager',
        pwgToken: 'token123',
        adminPageTitle: 'Synchronize',
        sites: [[
            'NAME' => '/var/www/gallery',
            'TYPE' => 'Local',
        ]],
    );

    expect($context->toArray())
        ->toBe([
            'F_ACTION' => '/admin.php?page=site_manager',
            'CSRF_TOKEN' => 'token123',
            'ADMIN_PAGE_TITLE' => 'Synchronize',
            'sites' => [[
                'NAME' => '/var/www/gallery',
                'TYPE' => 'Local',
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
