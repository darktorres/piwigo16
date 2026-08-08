<?php

declare(strict_types=1);

use Piwigo\Admin\Projection\ExtendForTemplatesPageContext;

test('toArray flattens every property to its real Smarty template variable name', function (): void {
    $context = new ExtendForTemplatesPageContext(
        helpUrl: '/admin/popuphelp.php?page=extend_for_templates',
        adminPageTitle: 'Extend for templates',
    );

    expect($context->toArray())->toBe([
        'U_HELP' => '/admin/popuphelp.php?page=extend_for_templates',
        'ADMIN_PAGE_TITLE' => 'Extend for templates',
    ]);
});
