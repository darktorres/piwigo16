<?php

declare(strict_types=1);

use Piwigo\Admin\Projection\MenubarPageContext;

test('toArray flattens every property to its real Smarty template variable name, casting isWebmaster to an int', function (): void {
    $context = new MenubarPageContext(formAction: '/admin.php?page=menubar', isWebmaster: true, adminPageTitle: 'Menu Management', blocks: [[
        'pos' => 10,
        'reg' => 'search',
    ]]);

    expect($context->toArray())
        ->toBe([
            'F_ACTION' => '/admin.php?page=menubar',
            'isWebmaster' => 1,
            'ADMIN_PAGE_TITLE' => 'Menu Management',
            'blocks' => [[
                'pos' => 10,
                'reg' => 'search',
            ]],
        ]);
});
