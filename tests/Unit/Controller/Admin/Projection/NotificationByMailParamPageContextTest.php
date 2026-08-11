<?php

declare(strict_types=1);

use Piwigo\Controller\Admin\Projection\NotificationByMailParamPageContext;

test('toArray nests every property under the literal param key', function (): void {
    $context = new NotificationByMailParamPageContext(
        sendHtmlMail: true,
        sendMailAs: 'My Gallery',
        sendDetailedContent: false,
        complementaryMailContent: 'Enjoy!',
        sendRecentPostDates: true,
    );

    expect($context->toArray())
        ->toBe([
            'param' => [
                'SEND_HTML_MAIL' => true,
                'SEND_MAIL_AS' => 'My Gallery',
                'SEND_DETAILED_CONTENT' => false,
                'COMPLEMENTARY_MAIL_CONTENT' => 'Enjoy!',
                'SEND_RECENT_POST_DATES' => true,
            ],
        ]);
});
