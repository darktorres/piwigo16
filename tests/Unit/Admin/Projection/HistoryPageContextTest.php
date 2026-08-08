<?php

declare(strict_types=1);

use Piwigo\Admin\Projection\HistoryPageContext;

test('toArray flattens every property to its real Smarty template variable name', function (): void {
    $context = new HistoryPageContext(
        fAction: '/admin.php?page=history',
        apiMethod: 'ws.php?format=json&method=pwg.history.search',
        userId: -1,
        userName: null,
        imageId: '',
        ip: '',
        start: '2026-08-08',
        end: '2026-08-08',
        displayThumbnails: ['no_display_thumbnail' => 'No display'],
        displayThumbnailSelected: 'no_display_thumbnail',
        guestId: 2,
        adminPageTitle: 'History',
    );

    expect($context->toArray())->toBe([
        'F_ACTION' => '/admin.php?page=history',
        'API_METHOD' => 'ws.php?format=json&method=pwg.history.search',
        'USER_ID' => -1,
        'USER_NAME' => null,
        'IMAGE_ID' => '',
        'IP' => '',
        'START' => '2026-08-08',
        'END' => '2026-08-08',
        'display_thumbnails' => ['no_display_thumbnail' => 'No display'],
        'display_thumbnail_selected' => 'no_display_thumbnail',
        'guest_id' => 2,
        'ADMIN_PAGE_TITLE' => 'History',
    ]);
});
