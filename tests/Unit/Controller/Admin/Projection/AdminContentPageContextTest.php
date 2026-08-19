<?php

declare(strict_types=1);

use Latte\Runtime\Html;
use Piwigo\Controller\Admin\Projection\AdminContentPageContext;

test('toArray always includes ADMIN_CONTENT, omits ADMIN_PAGE_TITLE/U_HELP when null', function (): void {
    $context = new AdminContentPageContext(
        adminContent: new Html('<p>content</p>'),
    );

    expect($context->toArray())
        ->toBe([
            'ADMIN_CONTENT' => $context->adminContent,
        ]);
});

test('toArray includes ADMIN_PAGE_TITLE/U_HELP when both are set', function (): void {
    $context = new AdminContentPageContext(
        adminContent: new Html('<p>content</p>'),
        adminPageTitle: 'Albums',
        helpUrl: 'admin/popuphelp.php?page=permalinks',
    );

    expect($context->toArray())
        ->toBe([
            'ADMIN_CONTENT' => $context->adminContent,
            'ADMIN_PAGE_TITLE' => 'Albums',
            'U_HELP' => 'admin/popuphelp.php?page=permalinks',
        ]);
});
