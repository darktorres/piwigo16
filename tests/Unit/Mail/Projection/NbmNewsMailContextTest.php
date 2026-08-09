<?php

declare(strict_types=1);

use Piwigo\Mail\Projection\NbmNewsMailContext;

test('toArray includes content_new_elements_between and omits the single/optional keys when null', function (): void {
    $context = new NbmNewsMailContext(
        contentNewElementsBetween: ['DATE_BETWEEN_1' => '2026-01-01', 'DATE_BETWEEN_2' => '2026-08-08'],
        contentNewElementsSingle: null,
        globalNewLines: null,
        customMailContent: null,
        galleryTitle: 'My Gallery',
        galleryUrl: 'https://example.test/',
        sendAsName: 'My Gallery',
        recentPosts: [],
    );

    expect($context->toArray())->toBe([
        'recent_posts' => [],
        'content_new_elements_between' => ['DATE_BETWEEN_1' => '2026-01-01', 'DATE_BETWEEN_2' => '2026-08-08'],
        'GOTO_GALLERY_TITLE' => 'My Gallery',
        'GOTO_GALLERY_URL' => 'https://example.test/',
        'SEND_AS_NAME' => 'My Gallery',
    ]);
});

test('toArray includes content_new_elements_single/global_new_lines/custom_mail_content when set', function (): void {
    $context = new NbmNewsMailContext(
        contentNewElementsBetween: null,
        contentNewElementsSingle: ['DATE_SINGLE' => '2026-08-08'],
        globalNewLines: ['3 new photos added'],
        customMailContent: 'Enjoy the new photos!',
        galleryTitle: 'My Gallery',
        galleryUrl: 'https://example.test/',
        sendAsName: null,
        recentPosts: [['TITLE' => 'August 2026', 'HTML_DATA' => '<p>3 new photos</p>']],
    );

    expect($context->toArray())->toBe([
        'recent_posts' => [['TITLE' => 'August 2026', 'HTML_DATA' => '<p>3 new photos</p>']],
        'content_new_elements_single' => ['DATE_SINGLE' => '2026-08-08'],
        'global_new_lines' => ['3 new photos added'],
        'custom_mail_content' => 'Enjoy the new photos!',
        'GOTO_GALLERY_TITLE' => 'My Gallery',
        'GOTO_GALLERY_URL' => 'https://example.test/',
        'SEND_AS_NAME' => null,
    ]);
});
