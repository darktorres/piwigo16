<?php

declare(strict_types=1);

use Piwigo\Controller\Admin\Projection\MaintenanceSubControllerPageContext;

test('toArray flattens the admin page title', function (): void {
    expect((new MaintenanceSubControllerPageContext(adminPageTitle: 'Maintenance'))->toArray())
        ->toBe(['ADMIN_PAGE_TITLE' => 'Maintenance']);
});
