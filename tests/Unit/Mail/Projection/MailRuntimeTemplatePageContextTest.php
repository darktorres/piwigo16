<?php

declare(strict_types=1);

use Piwigo\Mail\Projection\MailRuntimeTemplatePageContext;

test('toArray merges the dynamic extra bag with a trailing fixed CONTENT key', function (): void {
    $context = new MailRuntimeTemplatePageContext(
        extra: ['IMG' => ['link' => '/foo.jpg'], 'CAT_NAME' => 'Holidays'],
        content: '<p>hello</p>',
    );

    expect($context->toArray())->toBe([
        'IMG' => ['link' => '/foo.jpg'],
        'CAT_NAME' => 'Holidays',
        'CONTENT' => '<p>hello</p>',
    ]);
});

test('toArray lets CONTENT override a same-named key already present in extra', function (): void {
    $context = new MailRuntimeTemplatePageContext(
        extra: ['CONTENT' => 'stale'],
        content: 'fresh',
    );

    expect($context->toArray())->toBe([
        'CONTENT' => 'fresh',
    ]);
});

test('toArray works with an empty extra bag', function (): void {
    $context = new MailRuntimeTemplatePageContext(
        extra: [],
        content: 'plain text body',
    );

    expect($context->toArray())->toBe([
        'CONTENT' => 'plain text body',
    ]);
});
