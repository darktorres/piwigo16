<?php

declare(strict_types=1);

use PHPUnit\Framework\Assert;
use Piwigo\Admin\Image\ImageBackend;
use Piwigo\Admin\Image\ImageImagick;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\CurrentLogger;

/**
 * Only runs the real ext-imagick extension when it's genuinely available in
 * this environment (same ImageBackend::isImagick() check
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
    /** @var string|null */
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
        throw new RuntimeException('imagecreatetruecolor() failed building the test fixture image.');
    }
    $color = imagecolorallocate($im, $r, $g, $b);
    if ($color === false) {
        throw new RuntimeException('imagecolorallocate() failed building the test fixture image.');
    }
    imagefill($im, 0, 0, $color);
    imagejpeg($im, $path);
}

function imageImagickTestSkipIfUnavailable(): void
{
    if (! ImageBackend::isImagick()) {
        Assert::markTestSkipped('ext-imagick is not available in this environment.');
    }
}

/**
 * Unlike imageImagickTestMakeJpeg() above, this writes a REAL,
 * fully-opaque alpha channel into the PNG -- a bare `imagepng()` on a
 * plain truecolor GD image (no imagesavealpha()/imagealphablending()/
 * imagecolorallocatealpha()) produces a PNG Imagick reads back with no
 * meaningful per-pixel alpha data to dim at all, per a `php -r` probe --
 * making compose()'s own evaluateImage(CHANNEL_ALPHA)
 * dimming step a silent no-op regardless of the requested opacity.
 */
function imageImagickTestMakeAlphaPng(string $path, int $width, int $height, int $r, int $g, int $b): void
{
    assert($width > 0 && $height > 0);
    assert($r >= 0 && $r <= 255);
    assert($g >= 0 && $g <= 255);
    assert($b >= 0 && $b <= 255);

    $im = imagecreatetruecolor($width, $height);
    if ($im === false) {
        throw new RuntimeException('imagecreatetruecolor() failed building the test fixture image.');
    }
    imagesavealpha($im, true);
    imagealphablending($im, false);
    $color = imagecolorallocatealpha($im, $r, $g, $b, 0);
    if ($color === false) {
        throw new RuntimeException('imagecolorallocatealpha() failed building the test fixture image.');
    }
    imagefill($im, 0, 0, $color);
    imagepng($im, $path);
}

function imageImagickTestMakeStripes(string $path, int $width, int $height): void
{
    // High-frequency checkerboard content, unlike the flat single-color
    // fixtures above: resize()'s pre-halving pass and write()'s chroma
    // subsampling factors are both no-ops (byte-identical output either
    // way) on a flat color -- there's no spatial/color detail for either
    // to actually discard or blend differently. Real edges are needed to
    // make any difference observable, same reasoning as the sharpen
    // fixture below.
    //
    // An earlier version of this
    // fixture varied color along X only (pure vertical stripes, constant
    // down each column) -- every row was then identical to every other
    // row, so any mutation affecting ONLY the pre-halving pass's height
    // argument (scaleImage()'s intdiv($this->getHeight(), 2) divisor)
    // was structurally invisible no matter what final assertion a caller
    // made (hand-mutated 2->1 and 2->3, reran the
    // real byte-for-byte comparison in imageImagickTestAssertPrehalving() --
    // output stayed identical both times). Varying color along BOTH axes
    // (a checkerboard, not stripes) gives every dimension-scaling mutation
    // real pixel content to lose or distort.
    assert($width > 0 && $height > 0);

    $im = imagecreatetruecolor($width, $height);
    if ($im === false) {
        throw new RuntimeException('imagecreatetruecolor() failed building the striped test fixture image.');
    }
    $red = imagecolorallocate($im, 220, 20, 20);
    $blue = imagecolorallocate($im, 20, 20, 220);
    if ($red === false || $blue === false) {
        throw new RuntimeException('imagecolorallocate() failed building the striped test fixture image.');
    }
    for ($y = 0; $y < $height; $y++) {
        for ($x = 0; $x < $width; $x++) {
            $color = (intdiv($x, 4) + intdiv($y, 4)) % 2 === 0 ? $red : $blue;
            imagesetpixel($im, $x, $y, $color);
        }
    }
    imagejpeg($im, $path);
}

/**
 * Shared assertion helper for resize()'s pre-halving optimization branch:
 * builds a striped source image, resizes it via the real
 * ImageImagick::resize(), and compares the output against two raw Imagick
 * reference pipelines -- one that mirrors the real two-step pre-halving
 * path exactly (scaleImage() to the halved intermediate size, then the
 * same Lanczos resizeImage()), and one that skips straight to a
 * single-pass resizeImage(). Both land on the exact same final
 * dimensions regardless of which path is taken, so asserting on
 * dimensions alone (as the two direct-path/pre-halving-path tests below
 * already do) can't tell whether the branch itself was actually taken or
 * skipped -- only a byte-level comparison against these two, differently
 * computed, reference pipelines can.
 */
function imageImagickTestAssertPrehalving(int $srcWidth, int $srcHeight, int $targetWidth, int $targetHeight, bool $expectTriggered): void
{
    $path = imageImagickTestMarker() . '/prehalving-src.jpg';
    imageImagickTestMakeStripes($path, $srcWidth, $srcHeight);

    $image = new ImageImagick($path);
    expect($image->resize($targetWidth, $targetHeight))
        ->toBeTrue();
    $actualPath = imageImagickTestMarker() . '/prehalving-actual.jpg';
    $image->write($actualPath);

    // Both raw reference pipelines must replicate resize()'s own
    // setInterlaceScheme() call and write()'s own setSamplingFactors()
    // call -- otherwise the encoded bytes can never match the real
    // pipeline's regardless of whether the pre-halving branch logic
    // itself is correct.
    $oneStep = new Imagick($path);
    $oneStep->setInterlaceScheme(Imagick::INTERLACE_LINE);
    $oneStep->resizeImage($targetWidth, $targetHeight, Imagick::FILTER_LANCZOS, 0.9);
    $oneStep->setSamplingFactors([2, 1]);
    $oneStepPath = imageImagickTestMarker() . '/prehalving-onestep.jpg';
    $oneStep->writeImage($oneStepPath);

    if ($expectTriggered) {
        $twoStep = new Imagick($path);
        $twoStep->setInterlaceScheme(Imagick::INTERLACE_LINE);
        $twoStep->scaleImage(intdiv($srcWidth, 2), intdiv($srcHeight, 2));
        $twoStep->resizeImage($targetWidth, $targetHeight, Imagick::FILTER_LANCZOS, 0.9);
        $twoStep->setSamplingFactors([2, 1]);
        $twoStepPath = imageImagickTestMarker() . '/prehalving-twostep.jpg';
        $twoStep->writeImage($twoStepPath);

        expect(md5_file($actualPath))
            ->toBe(md5_file($twoStepPath), 'resize() must take the pre-halving scaleImage() path here, landing on the exact halved intermediate size')
            ->and(md5_file($actualPath))
            ->not->toBe(md5_file($oneStepPath), 'the pre-halving path must actually alter the output vs a naive single-pass resize');
    } else {
        expect(md5_file($actualPath))
            ->toBe(md5_file($oneStepPath), 'resize() must NOT take the pre-halving scaleImage() path here');
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

    expect(fn (): ImageImagick => new ImageImagick($path))
        ->toThrow(ImagickException::class);
});

test('getWidth and getHeight report the real source dimensions', function (): void {
    imageImagickTestSkipIfUnavailable();

    $path = imageImagickTestMarker() . '/photo.jpg';
    imageImagickTestMakeJpeg($path, 200, 120, 200, 30, 30);

    $image = new ImageImagick($path);

    expect($image->getWidth())
        ->toBe(200)
        ->and($image->getHeight())
        ->toBe(120);
});

test('crop reduces the reported dimensions to the cropped region', function (): void {
    imageImagickTestSkipIfUnavailable();

    $path = imageImagickTestMarker() . '/photo.jpg';
    imageImagickTestMakeJpeg($path, 200, 120, 10, 200, 10);
    $image = new ImageImagick($path);

    $result = $image->crop(80, 60, 10, 10);

    expect($result)
        ->toBeTrue()
        ->and($image->getWidth())
        ->toBe(80)
        ->and($image->getHeight())
        ->toBe(60);
});

test('resize takes the direct path for a source not more than 3x the target width', function (): void {
    imageImagickTestSkipIfUnavailable();

    // 200 is not > 3 * 90 (270), so the pre-halving scaleImage() step is
    // skipped entirely -- resizeImage() alone must land on the exact target.
    $path = imageImagickTestMarker() . '/photo.jpg';
    imageImagickTestMakeJpeg($path, 200, 120, 30, 10, 200);
    $image = new ImageImagick($path);

    $result = $image->resize(90, 54);

    expect($result)
        ->toBeTrue()
        ->and($image->getWidth())
        ->toBe(90)
        ->and($image->getHeight())
        ->toBe(54);
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

    expect($result)
        ->toBeTrue()
        ->and($image->getWidth())
        ->toBe(40)
        ->and($image->getHeight())
        ->toBe(24);
});

test('rotate by 90 degrees swaps the reported width and height', function (): void {
    imageImagickTestSkipIfUnavailable();

    $path = imageImagickTestMarker() . '/photo.jpg';
    imageImagickTestMakeJpeg($path, 200, 120, 250, 250, 0);
    $image = new ImageImagick($path);

    $result = $image->rotate(90);

    expect($result)
        ->toBeTrue()
        ->and($image->getWidth())
        ->toBe(120)
        ->and($image->getHeight())
        ->toBe(200);
});

test('setCompressionQuality and strip both report success', function (): void {
    imageImagickTestSkipIfUnavailable();

    $path = imageImagickTestMarker() . '/photo.jpg';
    imageImagickTestMakeJpeg($path, 100, 80, 5, 5, 5);
    $image = new ImageImagick($path);

    expect($image->setCompressionQuality(60))
        ->toBeTrue()
        ->and($image->strip())
        ->toBeTrue();
});

test('sharpen applies a real convolution and reports success, actually changing the pixel data', function (): void {
    imageImagickTestSkipIfUnavailable();

    $path = imageImagickTestMarker() . '/sharpen.jpg';
    // A flat-color fill would sharpen to itself (no local contrast to
    // amplify) -- a filled rectangle over part of the canvas gives the
    // convolution a real edge to act on, so the output can genuinely
    // differ byte-for-byte from the untouched source.
    $im = imagecreatetruecolor(40, 40);
    if ($im === false) {
        throw new RuntimeException('imagecreatetruecolor() failed building the test fixture image.');
    }
    $color = imagecolorallocate($im, 200, 50, 50);
    if ($color === false) {
        throw new RuntimeException('imagecolorallocate() failed building the test fixture image.');
    }
    imagefilledrectangle($im, 10, 10, 29, 29, $color);
    imagejpeg($im, $path);

    $image = new ImageImagick($path);

    $result = $image->sharpen(50);

    expect($result)
        ->toBeTrue();

    $sharpenedPath = imageImagickTestMarker() . '/sharpened-out.jpg';
    $untouchedPath = imageImagickTestMarker() . '/sharpen-untouched.jpg';
    $image->write($sharpenedPath);
    new ImageImagick($path)
        ->write($untouchedPath);

    expect(md5_file($sharpenedPath))
        ->not->toBe(md5_file($untouchedPath), 'sharpen() must actually alter the pixel data via a real convolveImage() call');
});

test('write persists the image to the destination path with the current dimensions', function (): void {
    imageImagickTestSkipIfUnavailable();

    $srcPath = imageImagickTestMarker() . '/photo.jpg';
    $destPath = imageImagickTestMarker() . '/out.jpg';
    imageImagickTestMakeJpeg($srcPath, 200, 120, 0, 128, 255);
    $image = new ImageImagick($srcPath);
    $image->resize(50, 30);

    $result = $image->write($destPath);

    expect($result)
        ->toBeTrue();
    expect($destPath)
        ->toBeFile();
    expect(filesize($destPath))
        ->toBeGreaterThan(0);

    $reloaded = new ImageImagick($destPath);
    expect($reloaded->getWidth())
        ->toBe(50)
        ->and($reloaded->getHeight())
        ->toBe(30);
});

test('compose composites a same-backend overlay and preserves the base dimensions', function (): void {
    imageImagickTestSkipIfUnavailable();

    $basePath = imageImagickTestMarker() . '/base.jpg';
    $overlayPath = imageImagickTestMarker() . '/overlay.jpg';
    imageImagickTestMakeJpeg($basePath, 200, 120, 0, 0, 0);
    imageImagickTestMakeJpeg($overlayPath, 50, 50, 255, 255, 255);
    $base = new ImageImagick($basePath);
    $overlay = new ImageBackend($overlayPath, new CurrentLogger(), new CurrentConfig(), 'imagick');

    $result = $base->compose($overlay, 10, 10, 50);

    expect($result)
        ->toBeTrue()
        ->and($base->getWidth())
        ->toBe(200)
        ->and($base->getHeight())
        ->toBe(120);

    // Dimensions alone don't prove compositing actually happened -- write
    // the composed image out and compare it against a fresh, untouched
    // load of the exact same solid-black source: compositing a white
    // overlay onto it at any nonzero opacity must produce different pixel
    // data (and thus different bytes), regardless of the exact blend math.
    $composedPath = imageImagickTestMarker() . '/composed.jpg';
    $untouchedPath = imageImagickTestMarker() . '/untouched.jpg';
    $base->write($composedPath);
    new ImageImagick($basePath)
        ->write($untouchedPath);

    expect(md5_file($composedPath))
        ->not->toBe(md5_file($untouchedPath), 'compose() must actually alter the base image pixel data');
});

test('compose only dims a shared overlay once across repeated calls', function (): void {
    imageImagickTestSkipIfUnavailable();

    $basePath = imageImagickTestMarker() . '/base.jpg';
    $overlayPath = imageImagickTestMarker() . '/overlay.jpg';
    imageImagickTestMakeJpeg($basePath, 200, 120, 0, 0, 0);
    imageImagickTestMakeJpeg($overlayPath, 50, 50, 255, 255, 255);
    $base = new ImageImagick($basePath);
    $overlay = new ImageBackend($overlayPath, new CurrentLogger(), new CurrentConfig(), 'imagick');
    $overlayBackend = $overlay->image;
    expect($overlayBackend)
        ->toBeInstanceOf(ImageImagick::class);
    // dirtyTrickXrepeatApplied gates the alpha-dimming evaluateImage() call
    // to run exactly once per overlay instance, even across multiple
    // compose() calls onto different x-repeat/y-repeat tile positions --
    // read the real flag via reflection (private, no public accessor) so
    // the assertions below check the actual memoization state instead of
    // just "the 2nd call doesn't throw".
    $dirtyFlag = new ReflectionProperty(ImageImagick::class, 'dirtyTrickXrepeatApplied');

    expect($dirtyFlag->getValue($overlayBackend))
        ->toBeFalse('a freshly constructed overlay must start undimmed');

    $firstResult = $base->compose($overlay, 0, 0, 50);

    expect($firstResult)
        ->toBeTrue()
        ->and($dirtyFlag->getValue($overlayBackend))
        ->toBeTrue('opacity<100 on the first call must dim the overlay and record it');

    $secondResult = $base->compose($overlay, 20, 20, 50);

    expect($secondResult)
        ->toBeTrue()
        ->and($dirtyFlag->getValue($overlayBackend))
        ->toBeTrue('the flag must stay set (not get reset) across the second call onto the same overlay');
});

test('compose throws when the overlay uses a different image backend', function (): void {
    imageImagickTestSkipIfUnavailable();

    $basePath = imageImagickTestMarker() . '/base.jpg';
    $overlayPath = imageImagickTestMarker() . '/overlay.jpg';
    imageImagickTestMakeJpeg($basePath, 200, 120, 0, 0, 0);
    imageImagickTestMakeJpeg($overlayPath, 50, 50, 255, 255, 255);
    $base = new ImageImagick($basePath);
    $overlay = new ImageBackend($overlayPath, new CurrentLogger(), new CurrentConfig(), 'gd');

    expect(fn (): bool => $base->compose($overlay, 0, 0, 50))
        ->toThrow(LogicException::class, 'ImageBackend::compose(): overlay must use the same image backend');
});

test('crop accepts real float width/height/x/y without a TypeError', function (): void {
    imageImagickTestSkipIfUnavailable();

    // Every existing crop() test
    // above passes int literals, never exercising the (int) casts this
    // method's own docblock says are required because Imagick::
    // cropImage()'s own parameters are strictly typed int -- under
    // strict_types=1, passing an uncast float (whole-number or not)
    // throws a TypeError rather than silently coercing. Real callers (per
    // that same comment) pass floats.
    $path = imageImagickTestMarker() . '/crop-float.jpg';
    imageImagickTestMakeJpeg($path, 100, 80, 15, 15, 15);
    $image = new ImageImagick($path);

    $result = $image->crop(60.0, 40.0, 10.0, 5.0);

    expect($result)
        ->toBeTrue()
        ->and($image->getWidth())
        ->toBe(60)
        ->and($image->getHeight())
        ->toBe(40);
});

test('rotate resets the image orientation metadata to top-left', function (): void {
    imageImagickTestSkipIfUnavailable();

    $path = imageImagickTestMarker() . '/rotate-orientation.jpg';
    imageImagickTestMakeJpeg($path, 100, 60, 40, 40, 40);
    $image = new ImageImagick($path);
    // Start from a deliberately different orientation tag so the
    // assertion below can only pass because rotate() actively resets it,
    // not because it happened to already be top-left.
    $image->image->setImageOrientation(Imagick::ORIENTATION_BOTTOMRIGHT);
    expect($image->image->getImageOrientation())
        ->toBe(Imagick::ORIENTATION_BOTTOMRIGHT);

    $image->rotate(90);

    expect($image->image->getImageOrientation())
        ->toBe(Imagick::ORIENTATION_TOPLEFT);
});

test('resize switches the image to line-interlaced (progressive) encoding', function (): void {
    imageImagickTestSkipIfUnavailable();

    $path = imageImagickTestMarker() . '/resize-interlace.jpg';
    imageImagickTestMakeJpeg($path, 100, 60, 10, 10, 200);
    $image = new ImageImagick($path);
    expect($image->image->getInterlaceScheme())
        ->not->toBe(Imagick::INTERLACE_LINE);

    $image->resize(50, 30);

    expect($image->image->getInterlaceScheme())
        ->toBe(Imagick::INTERLACE_LINE);
});

test('resize accepts real float width/height without a TypeError', function (): void {
    imageImagickTestSkipIfUnavailable();

    // 100 is not > 3 * 60 (180), so the pre-halving path is skipped --
    // resizeImage()'s own (int) casts are what's under test here.
    $path = imageImagickTestMarker() . '/resize-float.jpg';
    imageImagickTestMakeJpeg($path, 100, 80, 15, 15, 15);
    $image = new ImageImagick($path);

    $result = $image->resize(60.0, 40.0);

    expect($result)
        ->toBeTrue()
        ->and($image->getWidth())
        ->toBe(60)
        ->and($image->getHeight())
        ->toBe(40);
});

// [Mutation] resize()'s resizeImage(..., 0.9) blur argument literal
// (Line 109's DecimalNumber-type mutators) is not independently testable
// via output-byte comparison in this environment -- varying blur from
// 0.1 to 20.0 (across both flat and high-frequency
// striped fixtures, both down- and up-scaling, both FILTER_LANCZOS and
// FILTER_GAUSSIAN, both lossy JPEG and lossless PNG output) shows this
// ImageMagick 7.1.2 build's resizeImage() blur parameter has zero
// observable effect on the encoded pixel data -- every value produces a
// byte-identical result. A mutation to the literal is therefore
// undetectable by any output-based assertion in this runtime; the
// dimension/pre-halving/interlace/subsampling behavior around it is
// already covered by the sibling tests above and below.

// [Mutation] Two RemoveDoubleCast mutants -- resize()'s Line 103
// `3.0 * (float) $width` and compose()'s Line 147
// `(float) $opacity / 100.0` -- are both genuinely inert regardless of
// fixture or assertion precision: $width/$opacity are declared
// `int|float`, and PHP's arithmetic already coerces an int operand to
// float the moment it's combined with a float literal (`3.0`/`100.0`),
// with or without an explicit `(float)` cast (hand-removed each cast in
// turn, reran the full suite including the
// byte-exact `md5_file()` comparisons below -- zero difference in either
// case).

test('resize\'s pre-halving pass lands on the exact halved intermediate size, not just the right final dimensions', function (): void {
    imageImagickTestSkipIfUnavailable();

    // 600 > 3 * 150 (450), both dimensions even -> triggers the branch.
    // Target picked deliberately at a moderate (4x) final ratio, not the
    // more extreme 15x a 40x24 target would give -- at very large
    // downscale ratios, ImageMagick's own Lanczos
    // implementation already performs an internal reduction equivalent to
    // the manual pre-halving step, making the two paths converge on
    // byte-identical output regardless of whether resize() actually took
    // the pre-halving branch -- the assertTriggered=true assertion below
    // needs a ratio where the two paths genuinely still diverge.
    imageImagickTestAssertPrehalving(600, 360, 150, 90, true);
});

test('resize does not take the pre-halving path when the source is exactly (not more than) 3x the target width', function (): void {
    imageImagickTestSkipIfUnavailable();

    // 120 === 3 * 40 exactly -- the condition requires strictly greater.
    imageImagickTestAssertPrehalving(120, 80, 40, 20, false);
});

test('resize takes the pre-halving path once the source exceeds 3x the target width, without needing to reach 4x', function (): void {
    imageImagickTestSkipIfUnavailable();

    // 140 > 3 * 40 (120) but < 4 * 40 (160).
    imageImagickTestAssertPrehalving(140, 80, 40, 20, true);
});

test('resize does not take the pre-halving path when only the width is even', function (): void {
    imageImagickTestSkipIfUnavailable();

    // The target here was
    // originally 40x24 (a ~15x downscale ratio from 600) -- at that
    // ratio, ImageMagick's own internal Lanczos reduction already
    // converges the "wrongly triggered" and "correctly skipped" outputs
    // to byte-identical, the exact same convergence zone the
    // "lands on the exact halved intermediate size" test's own comment
    // documents and deliberately avoids with a moderate ~4x ratio. A
    // real DecrementInteger on this height check's own `% 2` (to `% 1`,
    // always true) survived here for that reason -- 150x90 (also a ~4x
    // ratio, matching the already-proven-distinguishing zone) is chosen
    // for the same reason.
    // 600 is even but 361 is odd -- both dimensions must be even.
    imageImagickTestAssertPrehalving(600, 361, 150, 90, false);
});

test('resize does not take the pre-halving path when only the height is even', function (): void {
    imageImagickTestSkipIfUnavailable();

    // Same ~4x-ratio fix as the sibling "only the width is even" test
    // above, for the identical reason (the original 40x24 target fell
    // into ImageMagick's own large-ratio Lanczos-convergence zone).
    // 360 is even but 601 is odd -- both dimensions must be even.
    imageImagickTestAssertPrehalving(601, 360, 150, 90, false);
});

test('resize\'s pre-halving pass halves by exactly /2, verified with dimensions not evenly divisible by 3', function (): void {
    imageImagickTestSkipIfUnavailable();

    // 502 and 244 are both even but neither is divisible by 3 -- picked
    // deliberately so a modulus-divisor mutation (%2 -> %3) can't
    // coincidentally agree with the real check the way 600/360 (both
    // divisible by 3 too) would.
    imageImagickTestAssertPrehalving(502, 244, 100, 50, true);
});

test('compose does not dim the overlay when opacity is exactly 100 (not strictly less)', function (): void {
    imageImagickTestSkipIfUnavailable();

    $basePath = imageImagickTestMarker() . '/base-op100.jpg';
    $overlayPath = imageImagickTestMarker() . '/overlay-op100.jpg';
    imageImagickTestMakeJpeg($basePath, 100, 80, 0, 0, 0);
    imageImagickTestMakeJpeg($overlayPath, 40, 40, 255, 255, 255);
    $base = new ImageImagick($basePath);
    $overlay = new ImageBackend($overlayPath, new CurrentLogger(), new CurrentConfig(), 'imagick');
    $overlayBackend = $overlay->image;
    expect($overlayBackend)
        ->toBeInstanceOf(ImageImagick::class);
    $dirtyFlag = new ReflectionProperty(ImageImagick::class, 'dirtyTrickXrepeatApplied');

    $result = $base->compose($overlay, 0, 0, 100);

    expect($result)
        ->toBeTrue()
        ->and($dirtyFlag->getValue($overlayBackend))
        ->toBeFalse('opacity===100 is not "< 100" -- the dimming step must be skipped');
});

test('compose dims the overlay when opacity is 99, one below the threshold', function (): void {
    imageImagickTestSkipIfUnavailable();

    $basePath = imageImagickTestMarker() . '/base-op99.jpg';
    $overlayPath = imageImagickTestMarker() . '/overlay-op99.jpg';
    imageImagickTestMakeJpeg($basePath, 100, 80, 0, 0, 0);
    imageImagickTestMakeJpeg($overlayPath, 40, 40, 255, 255, 255);
    $base = new ImageImagick($basePath);
    $overlay = new ImageBackend($overlayPath, new CurrentLogger(), new CurrentConfig(), 'imagick');
    $overlayBackend = $overlay->image;
    expect($overlayBackend)
        ->toBeInstanceOf(ImageImagick::class);
    $dirtyFlag = new ReflectionProperty(ImageImagick::class, 'dirtyTrickXrepeatApplied');

    $result = $base->compose($overlay, 0, 0, 99);

    expect($result)
        ->toBeTrue()
        ->and($dirtyFlag->getValue($overlayBackend))
        ->toBeTrue('opacity 99 is "< 100" -- the dimming step must run');
});

test('compose dims the overlay alpha by exactly opacity/100 before compositing', function (): void {
    imageImagickTestSkipIfUnavailable();

    // An earlier version of this test
    // used a JPEG overlay (imageImagickTestMakeJpeg()) here -- JPEG has NO
    // alpha channel at all, so BOTH the real pipeline and this test's own
    // hand-reconstructed reference pipeline dim nothing, making the exact
    // md5 match below true regardless of the divisor's actual value or even
    // whether the dimming call runs at all -- hand-mutated the source's
    // `100.0` divisor to `99.0`/`101.0` and
    // removed the `(float)` cast, reran: all three mutations left this
    // test still passing. A PNG overlay with a REAL, explicitly-enabled
    // alpha channel (imageImagickTestMakeAlphaPng()) makes the exact
    // reference-pipeline comparison actually exercise the divisor's value.
    $basePath = imageImagickTestMarker() . '/base-dim.jpg';
    $overlayPath = imageImagickTestMarker() . '/overlay-dim.png';
    imageImagickTestMakeJpeg($basePath, 100, 100, 0, 0, 0);
    imageImagickTestMakeAlphaPng($overlayPath, 100, 100, 255, 255, 255);

    $base = new ImageImagick($basePath);
    $overlay = new ImageBackend($overlayPath, new CurrentLogger(), new CurrentConfig(), 'imagick');

    $result = $base->compose($overlay, 0, 0, 37);
    expect($result)
        ->toBeTrue();
    $actualPath = imageImagickTestMarker() . '/dim-actual.jpg';
    $base->write($actualPath);

    // Manually reproduce the exact real pipeline via raw Imagick calls:
    // dim the overlay's alpha channel by opacity/100 before compositing at
    // the same integer x/y -- must land on byte-identical output.
    $rawOverlay = new Imagick($overlayPath);
    $rawOverlay->evaluateImage(Imagick::EVALUATE_MULTIPLY, 37 / 100.0, Imagick::CHANNEL_ALPHA);
    $rawBase = new Imagick($basePath);
    $rawBase->compositeImage($rawOverlay, Imagick::COMPOSITE_DISSOLVE, 0, 0);
    // Must replicate write()'s own setSamplingFactors() call -- it alters
    // the encoded bytes independently of the compositing under test here.
    $rawBase->setSamplingFactors([2, 1]);
    $expectedPath = imageImagickTestMarker() . '/dim-expected.jpg';
    $rawBase->writeImage($expectedPath);

    expect(md5_file($actualPath))
        ->toBe(md5_file($expectedPath));
});

test('compose\'s alpha-dimming step genuinely changes the composited pixel color, not just the encoded bytes', function (): void {
    imageImagickTestSkipIfUnavailable();

    // Same underlying issue the
    // sibling "dims the overlay alpha by exactly opacity/100" test above
    // was just fixed for (see its own comment) -- a JPEG overlay has NO
    // alpha channel, so evaluateImage(..., CHANNEL_ALPHA) is a no-op on
    // one regardless of the divisor or even whether the call runs at all
    // (per a `php -r` probe). This test complements that exact
    // byte-comparison one with a plain-English pixel-value sanity check:
    // an opaque white overlay dissolved onto a black base at opacity=37
    // composites to a real partial blend (~rgb(94,94,94), matching
    // 255*0.37) when the dimming genuinely runs, vs full-opacity white
    // (255,255,255) when it's skipped entirely -- guards against the case
    // where a future change breaks both the real pipeline and this file's
    // own hand-reconstructed reference pipeline in the same way.
    $basePath = imageImagickTestMarker() . '/base-alpha-dim.jpg';
    $overlayPath = imageImagickTestMarker() . '/overlay-alpha-dim.png';
    imageImagickTestMakeJpeg($basePath, 50, 50, 0, 0, 0);
    imageImagickTestMakeAlphaPng($overlayPath, 50, 50, 255, 255, 255);

    $base = new ImageImagick($basePath);
    $overlay = new ImageBackend($overlayPath, new CurrentLogger(), new CurrentConfig(), 'imagick');

    $result = $base->compose($overlay, 0, 0, 37);

    expect($result)
        ->toBeTrue();
    $pixel = $base->image->getImagePixelColor(25, 25)
        ->getColor();
    expect($pixel['r'])->toBeGreaterThan(50)->toBeLessThan(140)
        ->and($pixel['g'])->toBe($pixel['r'])
        ->and($pixel['b'])->toBe($pixel['r']);
});

test('compose accepts real float x/y without a TypeError', function (): void {
    imageImagickTestSkipIfUnavailable();

    $basePath = imageImagickTestMarker() . '/base-float.jpg';
    $overlayPath = imageImagickTestMarker() . '/overlay-float.jpg';
    imageImagickTestMakeJpeg($basePath, 100, 80, 0, 0, 0);
    imageImagickTestMakeJpeg($overlayPath, 20, 20, 255, 255, 255);
    $base = new ImageImagick($basePath);
    $overlay = new ImageBackend($overlayPath, new CurrentLogger(), new CurrentConfig(), 'imagick');

    $result = $base->compose($overlay, 5.0, 5.0, 80);

    expect($result)
        ->toBeTrue();
});

test('write encodes JPEG output using the exact 4:2:2 sampling factors [2, 1]', function (): void {
    imageImagickTestSkipIfUnavailable();

    // A striped, color-varying fixture is required -- chroma subsampling
    // only discards color detail, so a flat-color image would encode
    // identically regardless of the sampling factors used.
    $path = imageImagickTestMarker() . '/sampling-source.jpg';
    imageImagickTestMakeStripes($path, 80, 40);
    $image = new ImageImagick($path);
    $actualPath = imageImagickTestMarker() . '/sampling-actual.jpg';

    expect($image->write($actualPath))
        ->toBeTrue();

    $rawMatching = new Imagick($path);
    $rawMatching->setSamplingFactors([2, 1]);
    $expectedPath = imageImagickTestMarker() . '/sampling-expected.jpg';
    $rawMatching->writeImage($expectedPath);

    expect(md5_file($actualPath))
        ->toBe(md5_file($expectedPath));

    // Sanity check that the sampling factors are actually observable on
    // this fixture -- a different (4:4:4, no subsampling) factor set must
    // produce different bytes, otherwise the assertion above would be
    // vacuously true.
    $rawDifferent = new Imagick($path);
    $rawDifferent->setSamplingFactors([1, 1]);
    $differentPath = imageImagickTestMarker() . '/sampling-different.jpg';
    $rawDifferent->writeImage($differentPath);

    expect(md5_file($expectedPath))
        ->not->toBe(md5_file($differentPath));
});
