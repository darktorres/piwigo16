<?php

declare(strict_types=1);

use Piwigo\Mail\Projection\MailHeaderPageContext;

test('toArray flattens every property to its real Smarty template variable name', function (): void {
    $context = new MailHeaderPageContext(
        galleryUrl: 'https://example.test/',
        galleryTitle: 'My Gallery',
        version: '16.3.0',
        phpwgUrl: 'https://piwigo.example',
        contentEncoding: 'utf-8',
        contactMail: 'webmaster@example.test',
    );

    expect($context->toArray())->toBe([
        'GALLERY_URL' => 'https://example.test/',
        'GALLERY_TITLE' => 'My Gallery',
        'VERSION' => '16.3.0',
        'PHPWG_URL' => 'https://piwigo.example',
        'CONTENT_ENCODING' => 'utf-8',
        'CONTACT_MAIL' => 'webmaster@example.test',
    ]);
});
