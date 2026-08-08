<?php

declare(strict_types=1);

use Piwigo\Controller\Admin\Projection\SiteManagerSubControllerPageContext;

test('toArray flattens every property to its real Smarty template variable name', function (): void {
    $context = new SiteManagerSubControllerPageContext(
        formAction: '/admin.php?page=site_manager',
        pwgToken: 'token123',
        adminPageTitle: 'Synchronize',
    );

    expect($context->toArray())->toBe([
        'F_ACTION' => '/admin.php?page=site_manager',
        'PWG_TOKEN' => 'token123',
        'ADMIN_PAGE_TITLE' => 'Synchronize',
    ]);
});
