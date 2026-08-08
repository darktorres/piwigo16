<?php

declare(strict_types=1);

use Piwigo\Admin\Projection\ThemesNewPageContext;

test('toArray flattens every property to its real Smarty template variable name', function (): void {
    $context = new ThemesNewPageContext(
        defaultScreenshot: '/themes/admin/roboticfarm/images/missing_screenshot.png',
        adminPageTitle: 'Themes',
    );

    expect($context->toArray())->toBe([
        'default_screenshot' => '/themes/admin/roboticfarm/images/missing_screenshot.png',
        'ADMIN_PAGE_TITLE' => 'Themes',
    ]);
});
