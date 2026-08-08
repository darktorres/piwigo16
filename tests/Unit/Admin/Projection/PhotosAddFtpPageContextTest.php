<?php

declare(strict_types=1);

use Piwigo\Admin\Projection\PhotosAddFtpPageContext;

test('toArray flattens every property to its real Smarty template variable name', function (): void {
    $context = new PhotosAddFtpPageContext(ftpHelpContent: '<p>FTP help</p>', adminPageTitle: 'Upload Photos');

    expect($context->toArray())->toBe([
        'FTP_HELP_CONTENT' => '<p>FTP help</p>',
        'ADMIN_PAGE_TITLE' => 'Upload Photos',
    ]);
});
