<?php

declare(strict_types=1);

use Piwigo\Admin\Image\ImageGd;
use Piwigo\Admin\Image\ImageProcessingException;

/**
 * P23 Stage 1e: __construct()'s 2 real failure branches (unsupported
 * extension, undecodable content) used to die() -- first test coverage
 * for this class, confirming both now throw ImageProcessingException
 * instead. The imagecreatetruecolor() failure branches (crop()/resize()/
 * compose()) stay untested -- that GD call essentially never fails for a
 * real, reasonable pixel size, no realistic way to trigger it without
 * mocking a global function this class has no seam to inject.
 */
function imageGdTestMarker(): string
{
    /** @var string|null $marker */
    static $marker = null;

    return $marker ??= sys_get_temp_dir() . '/piwigo-image-gd-test-' . bin2hex(random_bytes(8));
}

beforeEach(function (): void {
    mkdir(imageGdTestMarker(), 0o777, true);
});

afterEach(function (): void {
    $dir = imageGdTestMarker();
    $files = glob($dir . '/*');
    foreach ($files !== false ? $files : [] as $file) {
        unlink($file);
    }
    rmdir($dir);
});

test('construct throws for an unsupported file extension', function (): void {
    $path = imageGdTestMarker() . '/photo.bmp';
    file_put_contents($path, 'not a real bmp, extension alone is enough to hit the guard');

    expect(fn () => new ImageGd($path))
        ->toThrow(ImageProcessingException::class, '[Image GD] unsupported file extension');
});

test('construct throws when the jpeg content cannot be decoded', function (): void {
    $path = imageGdTestMarker() . '/photo.jpg';
    file_put_contents($path, 'this is plain text, not a real JPEG');

    // imagecreatefromjpeg()'s own libgd warning isn't suppressed by a
    // plain @ here (phpunit.xml's failOnWarning="true" would still flag
    // it) -- a real no-op error handler for the duration of this one
    // expected-to-warn call is the only reliable way to swallow it.
    set_error_handler(static fn (): bool => true);
    try {
        expect(fn () => new ImageGd($path))
            ->toThrow(ImageProcessingException::class, '[Image GD] unable to decode image');
    } finally {
        restore_error_handler();
    }
});
