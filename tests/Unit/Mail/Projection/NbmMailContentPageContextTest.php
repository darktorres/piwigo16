<?php

declare(strict_types=1);

use Piwigo\Mail\Projection\NbmMailContentPageContext;

test('toArray flattens every property to its real Latte template variable name', function (): void {
    $context = new NbmMailContentPageContext(
        username: 'jane',
        sendAsName: 'My Gallery',
        unsubscribeLink: '/nbm.php?unsubscribe=abc',
        subscribeLink: '/nbm.php?subscribe=abc',
        contactEmail: 'webmaster@example.test',
    );

    expect($context->toArray())
        ->toBe([
            'USERNAME' => 'jane',
            'SEND_AS_NAME' => 'My Gallery',
            'UNSUBSCRIBE_LINK' => '/nbm.php?unsubscribe=abc',
            'SUBSCRIBE_LINK' => '/nbm.php?subscribe=abc',
            'CONTACT_EMAIL' => 'webmaster@example.test',
        ]);
});
