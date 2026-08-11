<?php

declare(strict_types=1);

use Piwigo\Mail\Projection\NbmSubscribeActionMailContext;

test('toArray flattens the dynamic section-action-by key alongside the 2 fixed gallery keys', function (): void {
    $context = new NbmSubscribeActionMailContext(
        sectionActionBy: 'subscribe_by_admin',
        galleryTitle: 'My Gallery',
        galleryUrl: 'https://example.test/',
    );

    expect($context->toArray())
        ->toBe([
            'subscribe_by_admin' => true,
            'GOTO_GALLERY_TITLE' => 'My Gallery',
            'GOTO_GALLERY_URL' => 'https://example.test/',
        ]);
});

test('toArray uses the unsubscribe-by-himself key for that combination', function (): void {
    $context = new NbmSubscribeActionMailContext(
        sectionActionBy: 'unsubscribe_by_himself',
        galleryTitle: 'My Gallery',
        galleryUrl: 'https://example.test/',
    );

    expect($context->toArray())
        ->toBe([
            'unsubscribe_by_himself' => true,
            'GOTO_GALLERY_TITLE' => 'My Gallery',
            'GOTO_GALLERY_URL' => 'https://example.test/',
        ]);
});
