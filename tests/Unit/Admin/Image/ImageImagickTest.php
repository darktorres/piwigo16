<?php

declare(strict_types=1);

use Piwigo\Admin\Image\ImageImagick;
use Piwigo\Admin\Image\PwgImage;

/**
 * Only runs the real ext-imagick extension when it's genuinely available in
 * this environment (same PwgImage::is_imagick() check
 * tests/Unit/Admin/Image/ImageExtImagickTest.php uses for its own external
 * `identify` binary) -- no fake/mocked Imagick, this exercises the real
 * extension. Real JPEG fixture images are generated on the fly via GD
 * (always available here), matching ImageGdTest's/ImageExtImagickTest's own
 * real-temp-file approach.
 *
 * Unlike ImageGd/ImageExtImagick, this class has no manual validation logic
 * of its own in __construct() (it's a bare `new \Imagick($source_filepath)`)
 * -- so, beyond the construct-failure case those sibling tests cover, this
 * also targets the two methods where ImageImagick *does* carry its own
 * conditional logic: resize()'s pre-halving optimization branch (taken only
 * for a source more than 3x the target width, with even dimensions) and
 * compose()'s overlay-backend-mismatch guard.
 */
function imageImagickTestMarker(): string
{
    /** @var string|null $marker */
    static $marker = null;

    return $marker ??= sys_get_temp_dir() . '/piwigo-image-imagick-test-' . bin2hex(random_bytes(8));
}

function imageImagickTestMakeJpeg(string $path, int $width, int $height, int $r, int $g, int $b): void
{
    // Every real call site below passes positive dimensions and real 0-255
    // RGB components -- narrows imagecreatetruecolor()/imagecolorallocate()'s
    // own int<1,max>/int<0,255> parameter types for PHPStan, same assert()
    // idiom ImageGd::crop() already uses for the same GD functions (this
    // environment runs with zend.assertions=-1, so this is a pure static
    // type-narrowing hint, not a runtime check).
    assert($width > 0 && $height > 0);
    assert($r >= 0 && $r <= 255);
    assert($g >= 0 && $g <= 255);
    assert($b >= 0 && $b <= 255);

    $im = imagecreatetruecolor($width, $height);
    if ($im === false) {
        throw new \RuntimeException('imagecreatetruecolor() failed building the test fixture image.');
    }
    $color = imagecolorallocate($im, $r, $g, $b);
    if ($color === false) {
        throw new \RuntimeException('imagecolorallocate() failed building the test fixture image.');
    }
    imagefill($im, 0, 0, $color);
    imagejpeg($im, $path);
}

function imageImagickTestSkipIfUnavailable(): void
{
    if (! PwgImage::is_imagick()) {
        \PHPUnit\Framework\Assert::markTestSkipped('ext-imagick is not available in this environment.');
    }
}

beforeEach(function (): void {
    mkdir(imageImagickTestMarker(), 0o777, true);
});

afterEach(function (): void {
    $dir = imageImagickTestMarker();
    $files = glob($dir . '/*');
    foreach ($files !== false ? $files : [] as $file) {
        unlink($file);
    }
    rmdir($dir);
});

test('construct throws for content that is not a real image', function (): void {
    imageImagickTestSkipIfUnavailable();

    $path = imageImagickTestMarker() . '/photo.jpg';
    file_put_contents($path, 'this is plain text, not a real JPEG');

    expect(fn () => new ImageImagick($path))->toThrow(\ImagickException::class);
});

test('get_width and get_height report the real source dimensions', function (): void {
    imageImagickTestSkipIfUnavailable();

    $path = imageImagickTestMarker() . '/photo.jpg';
    imageImagickTestMakeJpeg($path, 200, 120, 200, 30, 30);

    $image = new ImageImagick($path);

    expect($image->get_width())->toBe(200)
        ->and($image->get_height())->toBe(120);
});

test('crop reduces the reported dimensions to the cropped region', function (): void {
    imageImagickTestSkipIfUnavailable();

    $path = imageImagickTestMarker() . '/photo.jpg';
    imageImagickTestMakeJpeg($path, 200, 120, 10, 200, 10);
    $image = new ImageImagick($path);

    $result = $image->crop(80, 60, 10, 10);

    expect($result)->toBeTrue()
        ->and($image->get_width())->toBe(80)
        ->and($image->get_height())->toBe(60);
});

test('resize takes the direct path for a source not more than 3x the target width', function (): void {
    imageImagickTestSkipIfUnavailable();

    // 200 is not > 3 * 90 (270), so the pre-halving scaleImage() step is
    // skipped entirely -- resizeImage() alone must land on the exact target.
    $path = imageImagickTestMarker() . '/photo.jpg';
    imageImagickTestMakeJpeg($path, 200, 120, 30, 10, 200);
    $image = new ImageImagick($path);

    $result = $image->resize(90, 54);

    expect($result)->toBeTrue()
        ->and($image->get_width())->toBe(90)
        ->and($image->get_height())->toBe(54);
});

test('resize takes the pre-halving path for an even source more than 3x the target width', function (): void {
    imageImagickTestSkipIfUnavailable();

    // 600 > 3 * 40 (120), and both dimensions are even -> triggers the
    // scaleImage() box-filter halving step before the final Lanczos
    // resizeImage() -- the end result must still land on the exact target.
    $path = imageImagickTestMarker() . '/photo.jpg';
    imageImagickTestMakeJpeg($path, 600, 360, 90, 200, 10);
    $image = new ImageImagick($path);

    $result = $image->resize(40, 24);

    expect($result)->toBeTrue()
        ->and($image->get_width())->toBe(40)
        ->and($image->get_height())->toBe(24);
});

test('rotate by 90 degrees swaps the reported width and height', function (): void {
    imageImagickTestSkipIfUnavailable();

    $path = imageImagickTestMarker() . '/photo.jpg';
    imageImagickTestMakeJpeg($path, 200, 120, 250, 250, 0);
    $image = new ImageImagick($path);

    $result = $image->rotate(90);

    expect($result)->toBeTrue()
        ->and($image->get_width())->toBe(120)
        ->and($image->get_height())->toBe(200);
});

test('set_compression_quality and strip both report success', function (): void {
    imageImagickTestSkipIfUnavailable();

    $path = imageImagickTestMarker() . '/photo.jpg';
    imageImagickTestMakeJpeg($path, 100, 80, 5, 5, 5);
    $image = new ImageImagick($path);

    expect($image->set_compression_quality(60))->toBeTrue()
        ->and($image->strip())->toBeTrue();
});

test('write persists the image to the destination path with the current dimensions', function (): void {
    imageImagickTestSkipIfUnavailable();

    $srcPath = imageImagickTestMarker() . '/photo.jpg';
    $destPath = imageImagickTestMarker() . '/out.jpg';
    imageImagickTestMakeJpeg($srcPath, 200, 120, 0, 128, 255);
    $image = new ImageImagick($srcPath);
    $image->resize(50, 30);

    $result = $image->write($destPath);

    expect($result)->toBeTrue();
    expect($destPath)->toBeFile();
    expect(filesize($destPath))->toBeGreaterThan(0);

    $reloaded = new ImageImagick($destPath);
    expect($reloaded->get_width())->toBe(50)
        ->and($reloaded->get_height())->toBe(30);
});

test('compose composites a same-backend overlay and preserves the base dimensions', function (): void {
    imageImagickTestSkipIfUnavailable();

    $basePath = imageImagickTestMarker() . '/base.jpg';
    $overlayPath = imageImagickTestMarker() . '/overlay.jpg';
    imageImagickTestMakeJpeg($basePath, 200, 120, 0, 0, 0);
    imageImagickTestMakeJpeg($overlayPath, 50, 50, 255, 255, 255);
    $base = new ImageImagick($basePath);
    $overlay = new PwgImage($overlayPath, 'imagick');

    $result = $base->compose($overlay, 10, 10, 50);

    expect($result)->toBeTrue()
        ->and($base->get_width())->toBe(200)
        ->and($base->get_height())->toBe(120);

    // Dimensions alone don't prove compositing actually happened -- write
    // the composed image out and compare it against a fresh, untouched
    // load of the exact same solid-black source: compositing a white
    // overlay onto it at any nonzero opacity must produce different pixel
    // data (and thus different bytes), regardless of the exact blend math.
    $composedPath = imageImagickTestMarker() . '/composed.jpg';
    $untouchedPath = imageImagickTestMarker() . '/untouched.jpg';
    $base->write($composedPath);
    (new ImageImagick($basePath))->write($untouchedPath);

    expect(md5_file($composedPath))->not->toBe(md5_file($untouchedPath), 'compose() must actually alter the base image pixel data');
});

test('compose only dims a shared overlay once across repeated calls', function (): void {
    imageImagickTestSkipIfUnavailable();

    $basePath = imageImagickTestMarker() . '/base.jpg';
    $overlayPath = imageImagickTestMarker() . '/overlay.jpg';
    imageImagickTestMakeJpeg($basePath, 200, 120, 0, 0, 0);
    imageImagickTestMakeJpeg($overlayPath, 50, 50, 255, 255, 255);
    $base = new ImageImagick($basePath);
    $overlay = new PwgImage($overlayPath, 'imagick');
    $overlayBackend = $overlay->image;
    expect($overlayBackend)->toBeInstanceOf(ImageImagick::class);
    // dirtyTrickXrepeatApplied gates the alpha-dimming evaluateImage() call
    // to run exactly once per overlay instance, even across multiple
    // compose() calls onto different x-repeat/y-repeat tile positions --
    // read the real flag via reflection (private, no public accessor) so
    // the assertions below check the actual memoization state instead of
    // just "the 2nd call doesn't throw".
    $dirtyFlag = new \ReflectionProperty(ImageImagick::class, 'dirtyTrickXrepeatApplied');

    expect($dirtyFlag->getValue($overlayBackend))->toBeFalse('a freshly constructed overlay must start undimmed');

    $firstResult = $base->compose($overlay, 0, 0, 50);

    expect($firstResult)->toBeTrue()
        ->and($dirtyFlag->getValue($overlayBackend))->toBeTrue('opacity<100 on the first call must dim the overlay and record it');

    $secondResult = $base->compose($overlay, 20, 20, 50);

    expect($secondResult)->toBeTrue()
        ->and($dirtyFlag->getValue($overlayBackend))->toBeTrue('the flag must stay set (not get reset) across the second call onto the same overlay');
});

test('compose throws when the overlay uses a different image backend', function (): void {
    imageImagickTestSkipIfUnavailable();

    $basePath = imageImagickTestMarker() . '/base.jpg';
    $overlayPath = imageImagickTestMarker() . '/overlay.jpg';
    imageImagickTestMakeJpeg($basePath, 200, 120, 0, 0, 0);
    imageImagickTestMakeJpeg($overlayPath, 50, 50, 255, 255, 255);
    $base = new ImageImagick($basePath);
    $overlay = new PwgImage($overlayPath, 'gd');

    expect(fn () => $base->compose($overlay, 0, 0, 50))
        ->toThrow(\LogicException::class, 'PwgImage::compose(): overlay must use the same image backend');
});
