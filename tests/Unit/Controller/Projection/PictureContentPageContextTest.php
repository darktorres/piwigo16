<?php

declare(strict_types=1);

use Piwigo\Controller\Projection\PictureContentPageContext;

test('toArray flattens every fixed property, and omits U_ORIGINAL when null', function (): void {
    $context = new PictureContentPageContext(
        uOriginal: null,
        altImg: 'photo.jpg',
        cookiePath: '/piwigo/',
    );

    $result = $context->toArray();

    expect($result)->not->toHaveKey('U_ORIGINAL')
        ->and($result['ALT_IMG'])->toBe('photo.jpg')
        ->and($result['COOKIE_PATH'])->toBe('/piwigo/');
});

test('toArray includes U_ORIGINAL when set', function (): void {
    $context = new PictureContentPageContext(
        uOriginal: '/upload/2026/08/photo.jpg',
        altImg: 'photo.jpg',
        cookiePath: '/piwigo/',
    );

    expect($context->toArray()['U_ORIGINAL'])->toBe('/upload/2026/08/photo.jpg');
});
