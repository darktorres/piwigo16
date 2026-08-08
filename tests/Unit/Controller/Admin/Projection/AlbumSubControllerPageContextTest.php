<?php

declare(strict_types=1);

use Piwigo\Controller\Admin\Projection\AlbumSubControllerPageContext;

test('toArray flattens every property to its real Smarty template variable name', function (): void {
    $context = new AlbumSubControllerPageContext(
        adminPageTitle: 'Edit album <strong>Vacation</strong>',
        adminPageObjectId: '#3',
    );

    expect($context->toArray())->toBe([
        'ADMIN_PAGE_TITLE' => 'Edit album <strong>Vacation</strong>',
        'ADMIN_PAGE_OBJECT_ID' => '#3',
    ]);
});
