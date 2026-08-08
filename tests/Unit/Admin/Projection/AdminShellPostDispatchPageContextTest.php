<?php

declare(strict_types=1);

use Piwigo\Admin\Projection\AdminShellPostDispatchPageContext;

test('toArray flattens every property to its real Smarty template variable name', function (): void {
    $context = new AdminShellPostDispatchPageContext(
        activeMenu: 3,
        pwgmenu: ['HOME' => 'https://piwigo.example'],
    );

    expect($context->toArray())->toBe([
        'ACTIVE_MENU' => 3,
        'pwgmenu' => ['HOME' => 'https://piwigo.example'],
    ]);
});
