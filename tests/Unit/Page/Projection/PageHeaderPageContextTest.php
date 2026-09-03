<?php

declare(strict_types=1);

use Latte\Runtime\Html;
use Piwigo\Page\Projection\PageHeaderPageContext;

// The 3 nullable keys are emitted with their null rather than omitted
// (P58-B2): an omitted key leaves the template variable undefined, which
// forced layout.latte to guard them with empty()/isset() instead of
// asking the nullable question directly. Pinned here because a key
// silently going missing again would put those guards back.
test('toArray emits all 13 keys, the 3 nullable ones as null', function (): void {
    $context = new PageHeaderPageContext(
        galleryTitle: 'My Gallery',
        pageBanner: new Html('My Gallery'),
        bodyId: 'theBody',
        contentEncoding: 'utf-8',
        pageTitle: 'Photos',
        homeUrl: 'https://example.test/',
        levelSeparator: ' / ',
        showMobileAppBanner: true,
        bodyClasses: ['theme-dark'],
        headerNotes: null,
        metaRef: null,
        pageRefresh: null,
        headElements: [],
    );

    expect($context->toArray())
        ->toEqual([
            'GALLERY_TITLE' => 'My Gallery',
            'PAGE_BANNER' => new Html('My Gallery'),
            'BODY_ID' => 'theBody',
            'CONTENT_ENCODING' => 'utf-8',
            'PAGE_TITLE' => 'Photos',
            'U_HOME' => 'https://example.test/',
            'LEVEL_SEPARATOR' => ' / ',
            'SHOW_MOBILE_APP_BANNER' => true,
            'BODY_CLASSES' => ['theme-dark'],
            'head_elements' => [],
            'header_notes' => null,
            'meta_ref' => null,
            'page_refresh' => null,
        ]);
});

test('toArray includes header_notes/meta_ref/page_refresh when set', function (): void {
    $context = new PageHeaderPageContext(
        galleryTitle: 'My Gallery',
        pageBanner: new Html('My Gallery'),
        bodyId: '',
        contentEncoding: 'utf-8',
        pageTitle: 'Photos',
        homeUrl: 'https://example.test/',
        levelSeparator: ' / ',
        showMobileAppBanner: false,
        bodyClasses: [],
        headerNotes: ['Maintenance scheduled'],
        metaRef: 1,
        pageRefresh: [
            'TIME' => '5',
            'U_REFRESH' => 'https://example.test/next',
        ],
        headElements: [new Html('<meta name="robots" content="noindex,nofollow">')],
    );

    $result = $context->toArray();

    expect($result['header_notes'])->toBe(['Maintenance scheduled'])
        ->and($result['meta_ref'])->toBe(1)
        ->and($result['page_refresh'])->toBe([
            'TIME' => '5',
            'U_REFRESH' => 'https://example.test/next',
        ])
        ->and($result['head_elements'])->toEqual([new Html('<meta name="robots" content="noindex,nofollow">')]);
});
