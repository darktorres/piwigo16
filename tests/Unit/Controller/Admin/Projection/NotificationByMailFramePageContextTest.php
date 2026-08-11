<?php

declare(strict_types=1);

use Piwigo\Controller\Admin\Projection\NotificationByMailFramePageContext;

test('toArray flattens every fixed property, and omits save_success/REPOST_SUBMIT_NAME when null', function (): void {
    $context = new NotificationByMailFramePageContext(
        saveSuccess: null,
        pwgToken: 'abc123',
        helpUrl: '/admin/popuphelp.php?page=notification_by_mail',
        fAction: '/admin.php?page=notification_by_mail',
        repostSubmitName: null,
        adminPageTitle: 'Send mail to users',
    );

    expect($context->toArray())
        ->toBe([
            'CSRF_TOKEN' => 'abc123',
            'U_HELP' => '/admin/popuphelp.php?page=notification_by_mail',
            'F_ACTION' => '/admin.php?page=notification_by_mail',
            'ADMIN_PAGE_TITLE' => 'Send mail to users',
        ]);
});

test('toArray includes save_success/REPOST_SUBMIT_NAME when set', function (): void {
    $context = new NotificationByMailFramePageContext(
        saveSuccess: '1 parameter was updated.',
        pwgToken: 'abc123',
        helpUrl: '/admin/popuphelp.php?page=notification_by_mail',
        fAction: '/admin.php?page=notification_by_mail',
        repostSubmitName: 'falsify',
        adminPageTitle: 'Send mail to users',
    );

    expect($context->toArray())
        ->toBe([
            'CSRF_TOKEN' => 'abc123',
            'U_HELP' => '/admin/popuphelp.php?page=notification_by_mail',
            'F_ACTION' => '/admin.php?page=notification_by_mail',
            'ADMIN_PAGE_TITLE' => 'Send mail to users',
            'save_success' => '1 parameter was updated.',
            'REPOST_SUBMIT_NAME' => 'falsify',
        ]);
});
