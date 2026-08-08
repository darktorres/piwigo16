<?php

declare(strict_types=1);

use Piwigo\Mail\Projection\MailTitlePageContext;

test('toArray flattens every property to its real Smarty template variable name', function (): void {
    $context = new MailTitlePageContext(
        mailTitle: 'My Gallery',
        mailSubtitle: 'A new comment',
    );

    expect($context->toArray())->toBe([
        'MAIL_TITLE' => 'My Gallery',
        'MAIL_SUBTITLE' => 'A new comment',
    ]);
});
