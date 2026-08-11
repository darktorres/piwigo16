<?php

declare(strict_types=1);

use Piwigo\Admin\Projection\PhotosAddDirectPageContext;

test('toArray flattens every property', function (): void {
    $context = new PhotosAddDirectPageContext(
        promoteMobileApps: true,
        phpwgUrl: 'https://piwigo.org',
        enableFormats: true,
        displayFormats: false,
        haveFormatsOriginal: true,
        formatsOriginalInfo: [
            'id' => 5,
            'file' => 'foo.jpg',
        ],
        formatsExtInfo: '["jpg","raw"]',
        switchFormatModeUrl: '/admin.php?page=photos_add&formats',
        formatExt: 'jpg,raw',
        strFormatExt: 'jpg, raw',
    );

    expect($context->toArray())
        ->toBe([
            'PROMOTE_MOBILE_APPS' => true,
            'PHPWG_URL' => 'https://piwigo.org',
            'ENABLE_FORMATS' => true,
            'DISPLAY_FORMATS' => false,
            'HAVE_FORMATS_ORIGINAL' => true,
            'FORMATS_ORIGINAL_INFO' => [
                'id' => 5,
                'file' => 'foo.jpg',
            ],
            'FORMATS_EXT_INFO' => '["jpg","raw"]',
            'SWITCH_FORMAT_MODE_URL' => '/admin.php?page=photos_add&formats',
            'format_ext' => 'jpg,raw',
            'str_format_ext' => 'jpg, raw',
        ]);
});

test('toArray preserves null formatsOriginalInfo and formatsExtInfo', function (): void {
    $context = new PhotosAddDirectPageContext(
        promoteMobileApps: false,
        phpwgUrl: 'https://piwigo.org',
        enableFormats: false,
        displayFormats: false,
        haveFormatsOriginal: false,
        formatsOriginalInfo: null,
        formatsExtInfo: null,
        switchFormatModeUrl: '/admin.php?page=photos_add',
        formatExt: '',
        strFormatExt: '',
    );

    $result = $context->toArray();

    expect($result['FORMATS_ORIGINAL_INFO'])->toBeNull()
        ->and($result['FORMATS_EXT_INFO'])->toBeNull();
});
