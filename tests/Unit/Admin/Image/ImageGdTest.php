<?php

declare(strict_types=1);

use Piwigo\Admin\Image\ImageGd;
use Piwigo\Admin\Image\ImageInterface;
use Piwigo\Admin\Image\ImageProcessingException;
use Piwigo\Admin\Image\PwgImage;

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

test('construct decodes a real PNG', function (): void {
    $path = imageGdTestMarker() . '/photo.png';
    $gdImg = imagecreatetruecolor(33, 21);
    if ($gdImg === false) {
        throw new RuntimeException('imagecreatetruecolor failed');
    }
    imagepng($gdImg, $path);

    $img = new ImageGd($path);

    expect($img->get_width())->toBe(33);
    expect($img->get_height())->toBe(21);
});

test('construct decodes a real GIF', function (): void {
    $path = imageGdTestMarker() . '/photo.gif';
    $gdImg = imagecreatetruecolor(17, 9);
    if ($gdImg === false) {
        throw new RuntimeException('imagecreatetruecolor failed');
    }
    imagegif($gdImg, $path);

    $img = new ImageGd($path);

    expect($img->get_width())->toBe(17);
    expect($img->get_height())->toBe(9);
});

test('get_width and get_height report the real decoded dimensions, not transposed', function (): void {
    $path = imageGdTestMarker() . '/dims.jpg';
    $gdImg = imagecreatetruecolor(64, 12);
    if ($gdImg === false) {
        throw new RuntimeException('imagecreatetruecolor failed');
    }
    imagejpeg($gdImg, $path);

    $img = new ImageGd($path);

    expect($img->get_width())->toBe(64);
    expect($img->get_height())->toBe(12);
});

test('strip is a true no-op', function (): void {
    $path = imageGdTestMarker() . '/strip.jpg';
    $gdImg = imagecreatetruecolor(5, 5);
    if ($gdImg === false) {
        throw new RuntimeException('imagecreatetruecolor failed');
    }
    imagejpeg($gdImg, $path);
    $img = new ImageGd($path);

    expect($img->strip())->toBeTrue();
    // A genuine no-op: dimensions (the only cheaply-observable state)
    // stay exactly what they were before the call.
    expect($img->get_width())->toBe(5);
    expect($img->get_height())->toBe(5);
});

test('destroy is a true no-op returning true', function (): void {
    $path = imageGdTestMarker() . '/destroy.jpg';
    $gdImg = imagecreatetruecolor(5, 5);
    if ($gdImg === false) {
        throw new RuntimeException('imagecreatetruecolor failed');
    }
    imagejpeg($gdImg, $path);
    $img = new ImageGd($path);

    expect($img->destroy())->toBeTrue();
});

test('crop produces a real image of exactly the requested size, taken from the correct region', function (): void {
    $path = imageGdTestMarker() . '/crop.png';
    // A 20x20 canvas split into 2 distinct 10x10 colored halves (left
    // red, right blue) -- proves crop() reads from the requested x
    // offset, not just resizes the whole canvas down.
    $gdImg = imagecreatetruecolor(20, 20);
    if ($gdImg === false) {
        throw new RuntimeException('imagecreatetruecolor failed');
    }
    $red = imagecolorallocate($gdImg, 255, 0, 0);
    $blue = imagecolorallocate($gdImg, 0, 0, 255);
    if ($red === false || $blue === false) {
        throw new RuntimeException('imagecolorallocate failed');
    }
    imagefilledrectangle($gdImg, 0, 0, 9, 19, $red);
    imagefilledrectangle($gdImg, 10, 0, 19, 19, $blue);
    imagepng($gdImg, $path);

    $img = new ImageGd($path);
    $result = $img->crop(10, 20, 10, 0);

    expect($result)->toBeTrue();
    expect($img->get_width())->toBe(10);
    expect($img->get_height())->toBe(20);
    $color = imagecolorat($img->image, 0, 0);
    if ($color === false) {
        throw new RuntimeException('imagecolorat failed');
    }
    $rgb = imagecolorsforindex($img->image, $color);
    expect($rgb['red'])->toBe(0);
    expect($rgb['blue'])->toBe(255);
});

test('resize produces a real image scaled to exactly the requested dimensions', function (): void {
    $path = imageGdTestMarker() . '/resize.jpg';
    $gdImg = imagecreatetruecolor(80, 40);
    if ($gdImg === false) {
        throw new RuntimeException('imagecreatetruecolor failed');
    }
    imagejpeg($gdImg, $path);
    $img = new ImageGd($path);

    $result = $img->resize(40, 20);

    expect($result)->toBeTrue();
    expect($img->get_width())->toBe(40);
    expect($img->get_height())->toBe(20);
});

test('rotate produces a real image with width/height swapped for a 90-degree turn', function (): void {
    $path = imageGdTestMarker() . '/rotate.jpg';
    $gdImg = imagecreatetruecolor(30, 10);
    if ($gdImg === false) {
        throw new RuntimeException('imagecreatetruecolor failed');
    }
    imagejpeg($gdImg, $path);
    $img = new ImageGd($path);

    $result = $img->rotate(90);

    expect($result)->toBeTrue();
    expect($img->get_width())->toBe(10);
    expect($img->get_height())->toBe(30);
});

test('set_compression_quality stores the requested quality and reports success', function (): void {
    $path = imageGdTestMarker() . '/quality.jpg';
    $gdImg = imagecreatetruecolor(5, 5);
    if ($gdImg === false) {
        throw new RuntimeException('imagecreatetruecolor failed');
    }
    imagejpeg($gdImg, $path);
    $img = new ImageGd($path);

    $result = $img->set_compression_quality(42);

    expect($result)->toBeTrue();
    expect($img->quality)->toBe(42);
});

test('sharpen applies a real convolution and reports success', function (): void {
    $path = imageGdTestMarker() . '/sharpen.jpg';
    $gdImg = imagecreatetruecolor(10, 10);
    if ($gdImg === false) {
        throw new RuntimeException('imagecreatetruecolor failed');
    }
    $gray = imagecolorallocate($gdImg, 128, 128, 128);
    if ($gray === false) {
        throw new RuntimeException('imagecolorallocate failed');
    }
    imagefilledrectangle($gdImg, 0, 0, 9, 9, $gray);
    imagejpeg($gdImg, $path);
    $img = new ImageGd($path);

    expect($img->sharpen(50))->toBeTrue();
});

test('write saves as PNG when the destination extension is .png', function (): void {
    $path = imageGdTestMarker() . '/writesrc.jpg';
    $dest = imageGdTestMarker() . '/writedest.png';
    $gdImg = imagecreatetruecolor(6, 6);
    if ($gdImg === false) {
        throw new RuntimeException('imagecreatetruecolor failed');
    }
    imagejpeg($gdImg, $path);
    $img = new ImageGd($path);

    expect($img->write($dest))->toBeTrue();
    $info = getimagesize($dest);
    if ($info === false) {
        throw new RuntimeException('getimagesize failed');
    }
    expect($info[2])->toBe(IMAGETYPE_PNG);
});

test('write saves as GIF when the destination extension is .gif', function (): void {
    $path = imageGdTestMarker() . '/writesrc2.jpg';
    $dest = imageGdTestMarker() . '/writedest.gif';
    $gdImg = imagecreatetruecolor(6, 6);
    if ($gdImg === false) {
        throw new RuntimeException('imagecreatetruecolor failed');
    }
    imagejpeg($gdImg, $path);
    $img = new ImageGd($path);

    expect($img->write($dest))->toBeTrue();
    $info = getimagesize($dest);
    if ($info === false) {
        throw new RuntimeException('getimagesize failed');
    }
    expect($info[2])->toBe(IMAGETYPE_GIF);
});

test('write falls back to JPEG for any other destination extension', function (): void {
    $path = imageGdTestMarker() . '/writesrc3.jpg';
    $dest = imageGdTestMarker() . '/writedest.jpg';
    $gdImg = imagecreatetruecolor(6, 6);
    if ($gdImg === false) {
        throw new RuntimeException('imagecreatetruecolor failed');
    }
    imagejpeg($gdImg, $path);
    $img = new ImageGd($path);
    $img->set_compression_quality(77);

    expect($img->write($dest))->toBeTrue();
    $info = getimagesize($dest);
    if ($info === false) {
        throw new RuntimeException('getimagesize failed');
    }
    expect($info[2])->toBe(IMAGETYPE_JPEG);
});

test('compose throws when the overlay uses a different image backend', function (): void {
    $path = imageGdTestMarker() . '/compose-base.jpg';
    $gdImg = imagecreatetruecolor(10, 10);
    if ($gdImg === false) {
        throw new RuntimeException('imagecreatetruecolor failed');
    }
    imagejpeg($gdImg, $path);
    $img = new ImageGd($path);

    $overlay = new PwgImage($path, 'gd');
    // Swap in a fake, non-ImageGd backend to force the mismatch --
    // ImageImagick/ImageExtImagick both need a real ext_imagick/imagick
    // setup this suite doesn't otherwise depend on, and this class's own
    // guard only cares that it's genuinely not `self` (ImageGd).
    $overlay->image = new class implements ImageInterface {
        public function get_width(): int
        {
            return 1;
        }

        public function get_height(): int
        {
            return 1;
        }

        public function set_compression_quality(int $quality): bool
        {
            return true;
        }

        public function crop(int|float $width, int|float $height, int|float $x, int|float $y): bool
        {
            return true;
        }

        public function strip(): bool
        {
            return true;
        }

        public function rotate(int|float $rotation): bool
        {
            return true;
        }

        public function resize(int|float $width, int|float $height): bool
        {
            return true;
        }

        public function sharpen(int|float $amount): bool
        {
            return true;
        }

        public function compose(PwgImage $overlay, int|float $x, int|float $y, int|float $opacity): bool
        {
            return true;
        }

        public function write(string $destination_filepath): bool
        {
            return true;
        }
    };

    expect(fn () => $img->compose($overlay, 0, 0, 100))
        ->toThrow(LogicException::class, 'PwgImage::compose(): overlay must use the same image backend');
});

test('compose merges a same-backend overlay onto the base image', function (): void {
    $basePath = imageGdTestMarker() . '/compose-base2.jpg';
    $overlayPath = imageGdTestMarker() . '/compose-overlay.png';
    $baseImg = imagecreatetruecolor(20, 20);
    if ($baseImg === false) {
        throw new RuntimeException('imagecreatetruecolor failed');
    }
    imagejpeg($baseImg, $basePath);

    $overlayImg = imagecreatetruecolor(8, 8);
    if ($overlayImg === false) {
        throw new RuntimeException('imagecreatetruecolor failed');
    }
    $green = imagecolorallocate($overlayImg, 0, 255, 0);
    if ($green === false) {
        throw new RuntimeException('imagecolorallocate failed');
    }
    imagefilledrectangle($overlayImg, 0, 0, 7, 7, $green);
    imagepng($overlayImg, $overlayPath);

    $img = new ImageGd($basePath);
    $overlay = new PwgImage($overlayPath, 'gd');

    expect($img->compose($overlay, 2, 2, 100))->toBeTrue();
    // Base image dimensions are untouched by composing a smaller overlay.
    expect($img->get_width())->toBe(20);
    expect($img->get_height())->toBe(20);
    $color = imagecolorat($img->image, 2, 2);
    if ($color === false) {
        throw new RuntimeException('imagecolorat failed');
    }
    $rgb = imagecolorsforindex($img->image, $color);
    expect($rgb['green'])->toBe(255);
});
