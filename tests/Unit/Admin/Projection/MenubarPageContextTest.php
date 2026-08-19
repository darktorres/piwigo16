<?php

declare(strict_types=1);

use Piwigo\Admin\Projection\MenubarBlockConfigRow;
use Piwigo\Admin\Projection\MenubarPageContext;
use Piwigo\Menu\RegisteredBlock;

test('toArray flattens every property to its real Latte template variable name, casting isWebmaster to an int', function (): void {
    $reg = new RegisteredBlock('search', 'Search', 'piwigo');
    $context = new MenubarPageContext(formAction: '/admin.php?page=menubar', isWebmaster: true, adminPageTitle: 'Menu Management', blocks: [
        new MenubarBlockConfigRow(pos: 10, reg: $reg),
    ]);

    expect($context->toArray())
        ->toBe([
            'F_ACTION' => '/admin.php?page=menubar',
            'isWebmaster' => 1,
            'ADMIN_PAGE_TITLE' => 'Menu Management',
            'block_configs' => [[
                'pos' => 10,
                'reg' => $reg,
            ]],
        ]);
});
