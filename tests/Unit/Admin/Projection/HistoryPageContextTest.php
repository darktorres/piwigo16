<?php

declare(strict_types=1);

use Piwigo\Admin\Projection\HistoryPageContext;

test('toArray flattens every property to its real Latte template variable name', function (): void {
    $context = new HistoryPageContext(
        fAction: '/admin.php?page=history',
        userId: -1,
        userName: null,
        imageId: '',
        ip: '',
        start: '2026-08-08',
        end: '2026-08-08',
        guestId: 2,
        adminPageTitle: 'History',
    );

    expect($context->toArray())
        ->toBe([
            'F_ACTION' => '/admin.php?page=history',
            'USER_ID' => -1,
            'USER_NAME' => null,
            'IMAGE_ID' => '',
            'IP' => '',
            'START' => '2026-08-08',
            'END' => '2026-08-08',
            'guest_id' => 2,
            'ADMIN_PAGE_TITLE' => 'History',
        ]);
});
