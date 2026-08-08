<?php

declare(strict_types=1);

use Piwigo\Controller\Admin\Projection\PhotoSubControllerPageContext;

test('toArray flattens the admin page title', function (): void {
    expect((new PhotoSubControllerPageContext(adminPageTitle: 'Edit photo #1'))->toArray())
        ->toBe(['ADMIN_PAGE_TITLE' => 'Edit photo #1']);
});
