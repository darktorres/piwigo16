<?php

declare(strict_types=1);

use Piwigo\Page\Projection\PageHeaderPageContext;

test('toArray flattens every fixed property, and omits the 3 optional keys when null', function (): void {
    $context = new PageHeaderPageContext(
        galleryTitle: 'My Gallery',
        pageBanner: 'My Gallery',
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
        ->toBe([
            'GALLERY_TITLE' => 'My Gallery',
            'PAGE_BANNER' => 'My Gallery',
            'BODY_ID' => 'theBody',
            'CONTENT_ENCODING' => 'utf-8',
            'PAGE_TITLE' => 'Photos',
            'U_HOME' => 'https://example.test/',
            'LEVEL_SEPARATOR' => ' / ',
            'SHOW_MOBILE_APP_BANNER' => true,
            'BODY_CLASSES' => ['theme-dark'],
            'head_elements' => [],
        ]);
});

test('toArray includes header_notes/meta_ref/page_refresh when set', function (): void {
    $context = new PageHeaderPageContext(
        galleryTitle: 'My Gallery',
        pageBanner: 'My Gallery',
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
        headElements: ['<meta name="robots" content="noindex,nofollow">'],
    );

    $result = $context->toArray();

    expect($result['header_notes'])->toBe(['Maintenance scheduled'])
        ->and($result['meta_ref'])->toBe(1)
        ->and($result['page_refresh'])->toBe([
            'TIME' => '5',
            'U_REFRESH' => 'https://example.test/next',
        ])
        ->and($result['head_elements'])->toBe(['<meta name="robots" content="noindex,nofollow">']);
});
