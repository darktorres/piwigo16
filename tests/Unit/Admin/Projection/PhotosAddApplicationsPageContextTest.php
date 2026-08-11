<?php

declare(strict_types=1);

use Piwigo\Admin\Projection\PhotosAddApplicationsPageContext;

test('toArray flattens the admin page title', function (): void {
    expect((new PhotosAddApplicationsPageContext(adminPageTitle: 'Upload Photos'))->toArray())
        ->toBe([
            'ADMIN_PAGE_TITLE' => 'Upload Photos',
        ]);
});
