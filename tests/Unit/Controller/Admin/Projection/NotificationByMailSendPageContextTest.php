<?php

declare(strict_types=1);

use Piwigo\Controller\Admin\Projection\NotificationByMailSendPageContext;

test('toArray nests users/CUSTOMIZE_MAIL_CONTENT under the literal send key, and omits auth_key_duration when null', function (): void {
    $context = new NotificationByMailSendPageContext(
        users: [['ID' => 'abc', 'CHECKED' => 'checked="checked"', 'USERNAME' => 'jane', 'EMAIL' => 'jane@example.test', 'LAST_SEND' => null]],
        customizeMailContent: 'Enjoy!',
        authKeyDuration: null,
    );

    expect($context->toArray())->toBe([
        'send' => [
            'users' => [['ID' => 'abc', 'CHECKED' => 'checked="checked"', 'USERNAME' => 'jane', 'EMAIL' => 'jane@example.test', 'LAST_SEND' => null]],
            'CUSTOMIZE_MAIL_CONTENT' => 'Enjoy!',
        ],
    ]);
});

test('toArray includes auth_key_duration when set', function (): void {
    $context = new NotificationByMailSendPageContext(
        users: [],
        customizeMailContent: '',
        authKeyDuration: '2 hours',
    );

    expect($context->toArray())->toBe([
        'send' => [
            'users' => [],
            'CUSTOMIZE_MAIL_CONTENT' => '',
        ],
        'auth_key_duration' => '2 hours',
    ]);
});
