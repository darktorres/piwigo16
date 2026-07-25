<?php

declare(strict_types=1);

use Piwigo\Admin\Image\ImageExtImagick;
use Piwigo\Admin\Image\ImageProcessingException;
use Piwigo\Admin\Image\PwgImage;
use Piwigo\Config\CurrentConfig;

/**
 * P23 Stage 1e: __construct()'s "identify couldn't determine dimensions"
 * guard used to die() -- first test coverage for this class, confirming
 * it now throws ImageProcessingException. Only runs the real external
 * `identify` binary when it's genuinely available in this environment
 * (same check PwgImage::is_ext_imagick() itself uses) -- no fake/mocked
 * binary, this is exercising the real shell-exec path.
 */
function imageExtImagickTestMarker(): string
{
    /** @var string|null $marker */
    static $marker = null;

    return $marker ??= sys_get_temp_dir() . '/piwigo-image-ext-imagick-test-' . bin2hex(random_bytes(8));
}

beforeEach(function (): void {
    mkdir(imageExtImagickTestMarker(), 0o777, true);
});

afterEach(function (): void {
    CurrentConfig::reset();
    $dir = imageExtImagickTestMarker();
    $files = glob($dir . '/*');
    foreach ($files !== false ? $files : [] as $file) {
        unlink($file);
    }
    rmdir($dir);
});

test('construct throws when identify cannot determine the image dimensions', function (): void {
    if (! PwgImage::is_ext_imagick()) {
        \PHPUnit\Framework\Assert::markTestSkipped('No external ImageMagick (magick/convert) binary available in this environment.');
    }

    $path = imageExtImagickTestMarker() . '/photo.jpg';
    file_put_contents($path, 'this is plain text, not a real image at all');

    expect(fn () => new ImageExtImagick($path))
        ->toThrow(ImageProcessingException::class, '[External ImageMagick] Corrupt image');
});
