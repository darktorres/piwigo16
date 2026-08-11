<?php

declare(strict_types=1);

use Piwigo\Controller\Projection\PictureContentPageContext;

test('toArray flattens every fixed property, and omits U_ORIGINAL/PDF_VIEWER_FILESIZE_THRESHOLD when null', function (): void {
    $context = new PictureContentPageContext(
        uOriginal: null,
        altImg: 'photo.jpg',
        cookiePath: '/piwigo/',
        pdfViewerFilesizeThreshold: null,
        current: [
            'id' => 5,
            'file' => 'photo.jpg',
            'TITLE_ESC' => 'Photo',
        ],
    );

    $result = $context->toArray();

    expect($result)
        ->not->toHaveKeys(['U_ORIGINAL', 'PDF_VIEWER_FILESIZE_THRESHOLD'])
        ->and($result['ALT_IMG'])->toBe('photo.jpg')
        ->and($result['COOKIE_PATH'])->toBe('/piwigo/')
        ->and($result['current'])->toBe([
            'id' => 5,
            'file' => 'photo.jpg',
            'TITLE_ESC' => 'Photo',
        ]);
});

test('toArray includes U_ORIGINAL when set', function (): void {
    $context = new PictureContentPageContext(
        uOriginal: '/upload/2026/08/photo.jpg',
        altImg: 'photo.jpg',
        cookiePath: '/piwigo/',
        pdfViewerFilesizeThreshold: null,
        current: [],
    );

    expect($context->toArray()['U_ORIGINAL'])->toBe('/upload/2026/08/photo.jpg');
});

test('toArray includes PDF_VIEWER_FILESIZE_THRESHOLD when set', function (): void {
    $context = new PictureContentPageContext(
        uOriginal: null,
        altImg: 'document.pdf',
        cookiePath: '/piwigo/',
        pdfViewerFilesizeThreshold: 2048,
        current: [],
    );

    expect($context->toArray()['PDF_VIEWER_FILESIZE_THRESHOLD'])->toBe(2048);
});
