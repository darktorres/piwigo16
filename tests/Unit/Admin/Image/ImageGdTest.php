<?php

declare(strict_types=1);

use Piwigo\Admin\Image\ImageGd;
use Piwigo\Admin\Image\ImageInterface;
use Piwigo\Admin\Image\ImageProcessingException;
use Piwigo\Admin\Image\PwgImage;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\CurrentLogger;
use Piwigo\PluginConfig\EventDispatcher;

/**
 * __construct()'s 2 real failure branches (unsupported extension,
 * undecodable content) both throw ImageProcessingException.
 *
 * crop()/resize() have a real, directly reachable
 * imagecreatetruecolor() failure path -- unlike
 * compose() (see below), crop($width, $height, ...)/resize($width,
 * $height) pass their own method *arguments* straight into
 * imagecreatetruecolor(), completely independent of the already-decoded
 * source image's real size. Confirmed live: imagecreatetruecolor(100000,
 * 100000) returns false with a real "product of memory allocation
 * multiplication would exceed INT_MAX" warning (width*height*4 bytes >
 * PHP_INT_MAX's 32-bit-truncated internal check) -- reachable by simply
 * calling crop()/resize() with a pathologically large target size on any
 * normally-decoded small source image, no mocking needed.
 *
 * compose()'s own imagecreatetruecolor($ow, $oh) call (line 179) is
 * different: $ow/$oh come from imagesx()/imagesy() on an *already
 * successfully decoded* overlay GdImage, not a caller-supplied argument.
 * Reaching that failure would require a real, successfully-constructed
 * GdImage whose own dimensions are already large enough to make a *second*
 * identical imagecreatetruecolor() call fail -- a contradiction, since
 * decoding a GdImage of dims (W,H) in the first place (whether via
 * imagecreatetruecolor() directly or libgd's internal PNG/JPEG decode
 * path, which hits the exact same allocation check) requires that same
 * allocation to have just succeeded. Left uncovered with this comment
 * rather than mocked: not a static-analysis-only guard, a real defensive
 * check against a real `array|false`-shaped API, just one this class's own
 * source-then-destination-of-the-same-dims data flow makes provably
 * unreachable.
 */
function imageGdTestMarker(): string
{
    /** @var string|null */
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

    expect($img->get_width())
        ->toBe(33);
    expect($img->get_height())
        ->toBe(21);
});

test('construct decodes a real .jpeg (not just .jpg) extension', function (): void {
    // Real gap, found via mutation testing: every existing jpeg-flavored
    // test here uses a .jpg path -- removing 'jpeg' from the
    // in_array(['jpg', 'jpeg']) check was invisible without a real
    // .jpeg-suffixed file.
    $path = imageGdTestMarker() . '/photo.jpeg';
    $gdImg = imagecreatetruecolor(19, 11);
    if ($gdImg === false) {
        throw new RuntimeException('imagecreatetruecolor failed');
    }
    imagejpeg($gdImg, $path);

    $img = new ImageGd($path);

    expect($img->get_width())
        ->toBe(19);
    expect($img->get_height())
        ->toBe(11);
});

test('construct decodes a real GIF', function (): void {
    $path = imageGdTestMarker() . '/photo.gif';
    $gdImg = imagecreatetruecolor(17, 9);
    if ($gdImg === false) {
        throw new RuntimeException('imagecreatetruecolor failed');
    }
    imagegif($gdImg, $path);

    $img = new ImageGd($path);

    expect($img->get_width())
        ->toBe(17);
    expect($img->get_height())
        ->toBe(9);
});

test('construct lowercases the file extension before matching it', function (): void {
    // Real gap, found via mutation testing: every existing decode test
    // here uses a lowercase extension -- removing the strtolower() call
    // wrapping StringHelper::getExtension() was invisible without a real
    // uppercase-suffixed file, which would otherwise fall through to the
    // "unsupported file extension" branch instead of decoding.
    $path = imageGdTestMarker() . '/photo.PNG';
    $gdImg = imagecreatetruecolor(15, 8);
    if ($gdImg === false) {
        throw new RuntimeException('imagecreatetruecolor failed');
    }
    imagepng($gdImg, $path);

    $img = new ImageGd($path);

    expect($img->get_width())
        ->toBe(15);
    expect($img->get_height())
        ->toBe(8);
});

test('get_width and get_height report the real decoded dimensions, not transposed', function (): void {
    $path = imageGdTestMarker() . '/dims.jpg';
    $gdImg = imagecreatetruecolor(64, 12);
    if ($gdImg === false) {
        throw new RuntimeException('imagecreatetruecolor failed');
    }
    imagejpeg($gdImg, $path);

    $img = new ImageGd($path);

    expect($img->get_width())
        ->toBe(64);
    expect($img->get_height())
        ->toBe(12);
});

test('strip is a true no-op', function (): void {
    $path = imageGdTestMarker() . '/strip.jpg';
    $gdImg = imagecreatetruecolor(5, 5);
    if ($gdImg === false) {
        throw new RuntimeException('imagecreatetruecolor failed');
    }
    imagejpeg($gdImg, $path);
    $img = new ImageGd($path);

    expect($img->strip())
        ->toBeTrue();
    // A genuine no-op: dimensions (the only cheaply-observable state)
    // stay exactly what they were before the call.
    expect($img->get_width())
        ->toBe(5);
    expect($img->get_height())
        ->toBe(5);
});

test('destroy is a true no-op returning true', function (): void {
    $path = imageGdTestMarker() . '/destroy.jpg';
    $gdImg = imagecreatetruecolor(5, 5);
    if ($gdImg === false) {
        throw new RuntimeException('imagecreatetruecolor failed');
    }
    imagejpeg($gdImg, $path);
    $img = new ImageGd($path);

    expect($img->destroy())
        ->toBeTrue();
});

// ---------------------------------------------------------------------
// Mutation-testing gap closure (G1, when-i-run-the-mutable-cookie.md):
// a fresh scoped rerun (2026-08-10) found 20 untested. 1 new real test
// closed above (resize()'s own imagealphablending(false) call). The
// rest confirmed inert/unreachable/already-documented this pass, none
// needing a new test:
// - crop()'s own IDENTICAL imagealphablending($dest, false)/
//   imagesavealpha($dest, true)/imageantialias($dest, true) setup
//   (TrueToFalse/RemoveFunctionCall/IfNegated): verified directly (not
//   assumed) that this trio has NO observable effect on crop()'s own
//   output, even with the exact same alpha-transparent source the new
//   resize() test above uses -- crop() merges via imagecopymerge(),
//   which has a real, well-known PHP/GD alpha-handling limitation (the
//   same "php bug #23815" compose()'s own docblock already references
//   for the identical reason) that makes it ignore all three regardless.
// - resize()'s own `imagesavealpha($dest, true)` call: a SEPARATE,
//   narrower finding from the alphablending call above -- verified
//   directly that it's redundant specifically for imagecopyresampled()
//   onto a fresh truecolor canvas (probed with alphablending(false)
//   alone, no savealpha call at all: byte-identical alpha result).
// - Both `if (function_exists('imageantialias'))` guards (crop() and
//   resize(), IfNegated): imageantialias() only affects line-drawing
//   functions (imageline()/imagepolygon()/etc.), never a pixel-copy
//   function like imagecopymerge()/imagecopyresampled() -- neither
//   method draws a single line, so this call is a genuine no-op in
//   both places regardless of whether it's skipped.
// - compose()'s own `if ($cut === false)` check (FalseToTrue): already
//   documented in this file's own top docblock (see above) --
//   $ow/$oh come from a real, already-successfully-decoded overlay
//   image, making a second, identically-sized imagecreatetruecolor()
//   call fail a structural contradiction, not just untested.
// - compose()'s own FIRST imagecopy() call ("Copy the blank image into
//   the destination image where the source goes", RemoveFunctionCall +
//   DecrementInteger/IncrementInteger x2 on its dst_x/dst_y literals):
//   confirmed PROVABLY DEAD CODE by reading the next statement -- the
//   very next line unconditionally overwrites the ENTIRE $cut canvas
//   with the overlay image at the same (0,0) origin and the SAME
//   $ow/$oh dimensions $cut was just allocated at, so nothing this
//   first call writes can ever survive to be read. Not removed as part
//   of this mutation-gap-closure pass (out of scope -- a source-level
//   dead-code cleanup deserves its own dedicated, independently-
//   verified change), but worth flagging for one.
// ---------------------------------------------------------------------

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

    expect($result)
        ->toBeTrue();
    expect($img->get_width())
        ->toBe(10);
    expect($img->get_height())
        ->toBe(20);
    $color = imagecolorat($img->image, 0, 0);
    if ($color === false) {
        throw new RuntimeException('imagecolorat failed');
    }
    $rgb = imagecolorsforindex($img->image, $color);
    expect($rgb['red'])->toBe(0);
    expect($rgb['blue'])->toBe(255);
});

test('crop accepts real float width/height/x/y without a TypeError', function (): void {
    // Real gap, found via mutation testing: every existing crop() test
    // passes int literals, never exercising the (int) casts this
    // method's own docblock says were added specifically because GD's
    // native functions throw a TypeError on a float argument -- a real
    // caller (i.php's ImageRect::cropH()/cropV(), per that same
    // comment) always passes floats. Removing any of the 4 casts would
    // crash this call, not just silently misbehave.
    $path = imageGdTestMarker() . '/crop-float.png';
    $gdImg = imagecreatetruecolor(20, 20);
    if ($gdImg === false) {
        throw new RuntimeException('imagecreatetruecolor failed');
    }
    imagepng($gdImg, $path);

    $img = new ImageGd($path);
    $result = $img->crop(10.0, 20.0, 10.0, 0.0);

    expect($result)
        ->toBeTrue();
    expect($img->get_width())
        ->toBe(10);
    expect($img->get_height())
        ->toBe(20);
});

test('crop writes into every pixel of the destination canvas, including the far edge', function (): void {
    // Real gap, found via mutation testing: the existing crop-region test
    // only samples pixel (0,0) -- never the opposite corner, which is
    // exactly where imagecopymerge()'s dst_x/dst_y literals (both 0) land.
    // Shifting either to -1 leaves that far corner as the freshly-
    // allocated destination canvas's own default black, never overwritten
    // by the merge.
    $path = imageGdTestMarker() . '/crop-edge.png';
    $gdImg = imagecreatetruecolor(10, 10);
    if ($gdImg === false) {
        throw new RuntimeException('imagecreatetruecolor failed');
    }
    $green = imagecolorallocate($gdImg, 0, 200, 0);
    if ($green === false) {
        throw new RuntimeException('imagecolorallocate failed');
    }
    imagefilledrectangle($gdImg, 0, 0, 9, 9, $green);
    imagepng($gdImg, $path);

    $img = new ImageGd($path);
    $result = $img->crop(10, 10, 0, 0);

    expect($result)
        ->toBeTrue();
    $color = imagecolorat($img->image, 9, 9);
    if ($color === false) {
        throw new RuntimeException('imagecolorat failed');
    }
    $rgb = imagecolorsforindex($img->image, $color);
    expect($rgb['red'])->toBe(0);
    expect($rgb['green'])->toBe(200);
    expect($rgb['blue'])->toBe(0);
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

    expect($result)
        ->toBeTrue();
    expect($img->get_width())
        ->toBe(40);
    expect($img->get_height())
        ->toBe(20);
});

test('resize accepts real float width/height without a TypeError', function (): void {
    $path = imageGdTestMarker() . '/resize-float.jpg';
    $gdImg = imagecreatetruecolor(80, 40);
    if ($gdImg === false) {
        throw new RuntimeException('imagecreatetruecolor failed');
    }
    imagejpeg($gdImg, $path);
    $img = new ImageGd($path);

    $result = $img->resize(40.0, 20.0);

    expect($result)
        ->toBeTrue();
    expect($img->get_width())
        ->toBe(40);
    expect($img->get_height())
        ->toBe(20);
});

test('resize writes into every pixel of the destination canvas, including both edges', function (): void {
    // Real gap, found via mutation testing: neither existing resize test
    // samples pixel colors at all. imagecopyresampled()'s dst_x/dst_y
    // literals (both 0) place the resampled content within the
    // destination canvas -- shifting either by +-1 leaves one edge (the
    // near or the far one, depending on direction) as the freshly-
    // allocated canvas's own default black, never overwritten.
    $path = imageGdTestMarker() . '/resize-edge.png';
    $gdImg = imagecreatetruecolor(10, 10);
    if ($gdImg === false) {
        throw new RuntimeException('imagecreatetruecolor failed');
    }
    $green = imagecolorallocate($gdImg, 0, 200, 0);
    if ($green === false) {
        throw new RuntimeException('imagecolorallocate failed');
    }
    imagefilledrectangle($gdImg, 0, 0, 9, 9, $green);
    imagepng($gdImg, $path);

    $img = new ImageGd($path);
    $result = $img->resize(10, 10);

    expect($result)
        ->toBeTrue();
    foreach ([[0, 0], [9, 9]] as [$px, $py]) {
        $color = imagecolorat($img->image, $px, $py);
        if ($color === false) {
            throw new RuntimeException('imagecolorat failed');
        }
        $rgb = imagecolorsforindex($img->image, $color);
        expect($rgb['red'])->toBe(0);
        expect($rgb['green'])->toBe(200);
        expect($rgb['blue'])->toBe(0);
    }
});

test('resize samples from the correct source region, not shifted by one pixel', function (): void {
    // Real gap, found via mutation testing: imagecopyresampled()'s src_x/
    // src_y literals (both 0) determine which part of the source image
    // feeds the resampled output. A 1:1 resize (same target size as
    // source) turns this into a plain identity copy, so a 3-quadrant
    // source image pins down exactly which source pixel each destination
    // pixel is read from.
    $path = imageGdTestMarker() . '/resize-quadrants.png';
    $gdImg = imagecreatetruecolor(20, 20);
    if ($gdImg === false) {
        throw new RuntimeException('imagecreatetruecolor failed');
    }
    $red = imagecolorallocate($gdImg, 255, 0, 0);
    $blue = imagecolorallocate($gdImg, 0, 0, 255);
    $green = imagecolorallocate($gdImg, 0, 255, 0);
    if ($red === false || $blue === false || $green === false) {
        throw new RuntimeException('imagecolorallocate failed');
    }
    imagefilledrectangle($gdImg, 0, 0, 9, 9, $red);
    imagefilledrectangle($gdImg, 10, 0, 19, 9, $blue);
    imagefilledrectangle($gdImg, 0, 10, 9, 19, $green);
    imagepng($gdImg, $path);

    $img = new ImageGd($path);
    $result = $img->resize(20, 20);

    expect($result)
        ->toBeTrue();

    $atRed = imagecolorat($img->image, 9, 9);
    $atBlue = imagecolorat($img->image, 10, 9);
    $atGreen = imagecolorat($img->image, 9, 10);
    if ($atRed === false || $atBlue === false || $atGreen === false) {
        throw new RuntimeException('imagecolorat failed');
    }
    $redRgb = imagecolorsforindex($img->image, $atRed);
    $blueRgb = imagecolorsforindex($img->image, $atBlue);
    $greenRgb = imagecolorsforindex($img->image, $atGreen);

    expect([$redRgb['red'], $redRgb['green'], $redRgb['blue']])->toBe([255, 0, 0]);
    expect([$blueRgb['red'], $blueRgb['green'], $blueRgb['blue']])->toBe([0, 0, 255]);
    expect([$greenRgb['red'], $greenRgb['green'], $greenRgb['blue']])->toBe([0, 255, 0]);
});

test('resize preserves the real alpha channel via imagecopyresampled(), not silently flattening it to opaque', function (): void {
    // Real gap, found via mutation testing: TrueToFalse/RemoveFunctionCall
    // on resize()'s own imagealphablending($dest, false) call -- no
    // existing resize test uses a source with real alpha transparency,
    // only opaque colors. Without it, a semi-transparent source pixel
    // comes back fully opaque AND color-shifted (blended against GD's
    // default black canvas instead of copied raw) -- confirmed live via
    // a hand-mutated probe before writing this assertion.
    // imagecopyresampled() genuinely honors this flag, unlike crop()'s
    // own imagecopymerge()-based pipeline (see that method's own test
    // docblock below -- imagecopymerge() has a well-known, long-standing
    // PHP/GD alpha-handling bug, the exact one compose()'s own docblock
    // references as "php bug #23815", making the identical
    // alphablending call genuinely inert THERE).
    // The adjacent `imagesavealpha($dest, true)` call is a SEPARATE
    // finding: verified directly that it is redundant for this pipeline
    // specifically -- imagecopyresampled() onto a fresh truecolor canvas
    // preserves the source's alpha channel structurally regardless of
    // this flag (probed with alphablending(false) alone, no savealpha
    // call at all: identical result). Its own TrueToFalse/
    // RemoveFunctionCall mutations (and imageantialias()'s, which only
    // affects line-drawing functions never called here) stay untested
    // and inert for the same reason as crop()'s own trio above.
    $path = imageGdTestMarker() . '/resize-alpha.png';
    $gdImg = imagecreatetruecolor(10, 10);
    if ($gdImg === false) {
        throw new RuntimeException('imagecreatetruecolor failed');
    }
    imagesavealpha($gdImg, true);
    imagealphablending($gdImg, false);
    $transparentRed = imagecolorallocatealpha($gdImg, 255, 0, 0, 64);
    if ($transparentRed === false) {
        throw new RuntimeException('imagecolorallocatealpha failed');
    }
    imagefilledrectangle($gdImg, 0, 0, 9, 9, $transparentRed);
    imagepng($gdImg, $path);

    $img = new ImageGd($path);
    $result = $img->resize(10, 10);

    expect($result)
        ->toBeTrue();
    $color = imagecolorat($img->image, 5, 5);
    if ($color === false) {
        throw new RuntimeException('imagecolorat failed');
    }
    $rgb = imagecolorsforindex($img->image, $color);
    expect($rgb['red'])->toBe(255)
        ->and($rgb['green'])->toBe(0)
        ->and($rgb['blue'])->toBe(0)
        ->and($rgb['alpha'])->toBe(64);
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

    expect($result)
        ->toBeTrue();
    expect($img->get_width())
        ->toBe(10);
    expect($img->get_height())
        ->toBe(30);
});

test('rotate fills newly exposed corners with the documented background color', function (): void {
    // Real gap, found via mutation testing: the existing rotate test only
    // uses a 90-degree turn, which is an exact geometric swap with no
    // newly-exposed background pixels at all (every output pixel maps to
    // a real source pixel). imagerotate()'s 3rd argument (background
    // color, 0) is only observable for angles that are not a multiple of
    // 90, which genuinely expose new corner regions with no source data.
    $path = imageGdTestMarker() . '/rotate-bg.jpg';
    $gdImg = imagecreatetruecolor(20, 20);
    if ($gdImg === false) {
        throw new RuntimeException('imagecreatetruecolor failed');
    }
    $red = imagecolorallocate($gdImg, 255, 0, 0);
    if ($red === false) {
        throw new RuntimeException('imagecolorallocate failed');
    }
    imagefilledrectangle($gdImg, 0, 0, 19, 19, $red);
    imagejpeg($gdImg, $path, 100);
    $img = new ImageGd($path);

    $result = $img->rotate(45);

    expect($result)
        ->toBeTrue();
    // The extreme (0,0) corner of the enlarged bounding canvas sits well
    // outside the rotated (now diamond-shaped) red square -- a real
    // background pixel, far from any anti-aliased edge.
    $color = imagecolorat($img->image, 0, 0);
    if ($color === false) {
        throw new RuntimeException('imagecolorat failed');
    }
    $rgb = imagecolorsforindex($img->image, $color);
    expect($rgb['red'])->toBe(0);
    expect($rgb['green'])->toBe(0);
    expect($rgb['blue'])->toBe(0);
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

    expect($result)
        ->toBeTrue();
    expect($img->quality)
        ->toBe(42);
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

    expect($img->sharpen(50))
        ->toBeTrue();
});

test('sharpen leaves a uniform region at exactly its original value', function (): void {
    // Real gap, found via mutation testing: the existing sharpen test
    // only checks the boolean return value, never any actual pixel data.
    // PwgImage::get_sharpen_matrix() always normalizes its kernel to sum
    // to exactly 1 (every cell divided by the matrix's own total), so for
    // any uniform (flat-color) region, imageconvolution()'s div=1/offset=0
    // literals must leave every channel completely unchanged -- any
    // div/offset mutation shows up as an exact, deterministic shift.
    $path = imageGdTestMarker() . '/sharpen-exact.png';
    $gdImg = imagecreatetruecolor(10, 10);
    if ($gdImg === false) {
        throw new RuntimeException('imagecreatetruecolor failed');
    }
    $gray = imagecolorallocate($gdImg, 100, 100, 100);
    if ($gray === false) {
        throw new RuntimeException('imagecolorallocate failed');
    }
    imagefilledrectangle($gdImg, 0, 0, 9, 9, $gray);
    imagepng($gdImg, $path);
    $img = new ImageGd($path);

    expect($img->sharpen(50))
        ->toBeTrue();

    $color = imagecolorat($img->image, 5, 5);
    if ($color === false) {
        throw new RuntimeException('imagecolorat failed');
    }
    $rgb = imagecolorsforindex($img->image, $color);
    expect($rgb['red'])->toBe(100);
    expect($rgb['green'])->toBe(100);
    expect($rgb['blue'])->toBe(100);
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

    expect($img->write($dest))
        ->toBeTrue();
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

    expect($img->write($dest))
        ->toBeTrue();
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

    expect($img->write($dest))
        ->toBeTrue();
    $info = getimagesize($dest);
    if ($info === false) {
        throw new RuntimeException('getimagesize failed');
    }
    expect($info[2])->toBe(IMAGETYPE_JPEG);
});

test('write lowercases the destination extension before matching it', function (): void {
    // Real gap, found via mutation testing: every existing write() test
    // uses a lowercase destination extension -- removing the strtolower()
    // call wrapping StringHelper::getExtension() was invisible without a
    // real uppercase-suffixed destination, which would otherwise fall
    // through past the 'png'/'gif' checks straight to the JPEG default.
    $path = imageGdTestMarker() . '/writesrc-upper.jpg';
    $dest = imageGdTestMarker() . '/writedest-upper.PNG';
    $gdImg = imagecreatetruecolor(6, 6);
    if ($gdImg === false) {
        throw new RuntimeException('imagecreatetruecolor failed');
    }
    imagejpeg($gdImg, $path);
    $img = new ImageGd($path);

    expect($img->write($dest))
        ->toBeTrue();
    $info = getimagesize($dest);
    if ($info === false) {
        throw new RuntimeException('getimagesize failed');
    }
    expect($info[2])->toBe(IMAGETYPE_PNG);
});

test('compose throws when the overlay uses a different image backend', function (): void {
    $path = imageGdTestMarker() . '/compose-base.jpg';
    $gdImg = imagecreatetruecolor(10, 10);
    if ($gdImg === false) {
        throw new RuntimeException('imagecreatetruecolor failed');
    }
    imagejpeg($gdImg, $path);
    $img = new ImageGd($path);

    $overlay = new PwgImage($path, new CurrentLogger(), new EventDispatcher(), new CurrentConfig(), 'gd');
    // Swap in a fake, non-ImageGd backend to force the mismatch --
    // ImageImagick/ImageExtImagick both need a real ext_imagick/imagick
    // setup this suite doesn't otherwise depend on, and this class's own
    // guard only cares that it's genuinely not `self` (ImageGd).
    $overlay->image = new class() implements ImageInterface {
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

test('crop throws when the requested target size overflows GD\'s internal allocation check', function (): void {
    $path = imageGdTestMarker() . '/crop-overflow.jpg';
    $gdImg = imagecreatetruecolor(5, 5);
    if ($gdImg === false) {
        throw new RuntimeException('imagecreatetruecolor failed');
    }
    imagejpeg($gdImg, $path);
    $img = new ImageGd($path);

    // 100000 x 100000 x 4 bytes/pixel overflows imagecreatetruecolor()'s
    // internal INT_MAX allocation-size check (confirmed live: it returns
    // false with a real "product of memory allocation multiplication
    // would exceed INT_MAX" warning) -- crop()'s own $width/$height
    // arguments feed that call directly, regardless of the small 5x5
    // source image actually decoded above.
    set_error_handler(static fn (): bool => true, E_WARNING);
    try {
        expect(fn () => $img->crop(100000, 100000, 0, 0))
            ->toThrow(ImageProcessingException::class, '[Image GD] imagecreatetruecolor() failed');
    } finally {
        restore_error_handler();
    }
});

test('resize throws when the requested target size overflows GD\'s internal allocation check', function (): void {
    $path = imageGdTestMarker() . '/resize-overflow.jpg';
    $gdImg = imagecreatetruecolor(5, 5);
    if ($gdImg === false) {
        throw new RuntimeException('imagecreatetruecolor failed');
    }
    imagejpeg($gdImg, $path);
    $img = new ImageGd($path);

    set_error_handler(static fn (): bool => true, E_WARNING);
    try {
        expect(fn () => $img->resize(100000, 100000))
            ->toThrow(ImageProcessingException::class, '[Image GD] imagecreatetruecolor() failed');
    } finally {
        restore_error_handler();
    }
});

test('rotate returns false when imagerotate() itself fails', function (): void {
    $path = imageGdTestMarker() . '/rotate-fail.jpg';
    $gdImg = imagecreatetruecolor(5, 5);
    if ($gdImg === false) {
        throw new RuntimeException('imagecreatetruecolor failed');
    }
    imagejpeg($gdImg, $path);
    $img = new ImageGd($path);

    // NAN is a valid float (rotate()'s own signature accepts int|float),
    // and libgd's imagerotate() rejects it at its own internal memory-
    // allocation-multiplication check, confirmed live: returns false with
    // a real "one parameter to a memory allocation multiplication is
    // negative or zero" warning, rather than throwing.
    set_error_handler(static fn (): bool => true, E_WARNING);
    try {
        $result = $img->rotate(NAN);
    } finally {
        restore_error_handler();
    }

    expect($result)
        ->toBeFalse();
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
    $overlay = new PwgImage($overlayPath, new CurrentLogger(), new EventDispatcher(), new CurrentConfig(), 'gd');

    expect($img->compose($overlay, 2, 2, 100))
        ->toBeTrue();
    // Base image dimensions are untouched by composing a smaller overlay.
    expect($img->get_width())
        ->toBe(20);
    expect($img->get_height())
        ->toBe(20);
    $color = imagecolorat($img->image, 2, 2);
    if ($color === false) {
        throw new RuntimeException('imagecolorat failed');
    }
    $rgb = imagecolorsforindex($img->image, $color);
    expect($rgb['green'])->toBe(255);
});

test('compose accepts real float x/y/opacity without a TypeError', function (): void {
    // Real gap, found via mutation testing: every existing compose() test
    // passes int literals for x/y/opacity, never exercising the (int)
    // casts this method's own docblock says were added specifically
    // because GD's native functions throw a TypeError on a float argument
    // (x/y feed imagecopy()/imagecopymerge(); opacity feeds
    // imagecopymerge()'s $pct) -- a real caller (i.php's watermark
    // positioning, per that same comment) always passes floats.
    $basePath = imageGdTestMarker() . '/compose-float-base.jpg';
    $overlayPath = imageGdTestMarker() . '/compose-float-overlay.png';
    $baseImg = imagecreatetruecolor(20, 20);
    if ($baseImg === false) {
        throw new RuntimeException('imagecreatetruecolor failed');
    }
    imagejpeg($baseImg, $basePath);

    $overlayImg = imagecreatetruecolor(8, 8);
    if ($overlayImg === false) {
        throw new RuntimeException('imagecreatetruecolor failed');
    }
    imagepng($overlayImg, $overlayPath);

    $img = new ImageGd($basePath);
    $overlay = new PwgImage($overlayPath, new CurrentLogger(), new EventDispatcher(), new CurrentConfig(), 'gd');

    $result = $img->compose($overlay, 2.0, 2.0, 100.0);

    expect($result)
        ->toBeTrue();
});

test('compose samples the cut region and the overlay from the correct offsets, not shifted by one pixel', function (): void {
    // Real gap, found via mutation testing: the "merges a same-backend
    // overlay" test above uses a solid-colored overlay and only samples
    // pixel (2,2) -- the overlay's own top-left corner, unaffected by an
    // off-by-one in any of the several imagecopy()/imagecopymerge()
    // offset literals along compose()'s cut->merge pipeline. A 3-quadrant
    // overlay pins down exactly which overlay pixel lands at each
    // destination pixel.
    $basePath = imageGdTestMarker() . '/compose-quad-base.jpg';
    $overlayPath = imageGdTestMarker() . '/compose-quad-overlay.png';
    $baseImg = imagecreatetruecolor(20, 20);
    if ($baseImg === false) {
        throw new RuntimeException('imagecreatetruecolor failed');
    }
    $red = imagecolorallocate($baseImg, 255, 0, 0);
    if ($red === false) {
        throw new RuntimeException('imagecolorallocate failed');
    }
    imagefilledrectangle($baseImg, 0, 0, 19, 19, $red);
    imagejpeg($baseImg, $basePath);

    $overlayImg = imagecreatetruecolor(8, 8);
    if ($overlayImg === false) {
        throw new RuntimeException('imagecreatetruecolor failed');
    }
    $green = imagecolorallocate($overlayImg, 0, 255, 0);
    $yellow = imagecolorallocate($overlayImg, 255, 255, 0);
    $cyan = imagecolorallocate($overlayImg, 0, 255, 255);
    if ($green === false || $yellow === false || $cyan === false) {
        throw new RuntimeException('imagecolorallocate failed');
    }
    imagefilledrectangle($overlayImg, 0, 0, 3, 3, $green);
    imagefilledrectangle($overlayImg, 4, 0, 7, 3, $yellow);
    imagefilledrectangle($overlayImg, 0, 4, 3, 7, $cyan);
    imagepng($overlayImg, $overlayPath);

    $img = new ImageGd($basePath);
    $overlay = new PwgImage($overlayPath, new CurrentLogger(), new EventDispatcher(), new CurrentConfig(), 'gd');

    expect($img->compose($overlay, 2, 2, 100))
        ->toBeTrue();

    // (5,5) = local overlay coordinate (3,3), the last pixel still inside
    // the top-left (green) quadrant -- any 1px shift in either direction,
    // at any stage of the cut->merge pipeline, samples an adjacent
    // quadrant here instead.
    $color = imagecolorat($img->image, 5, 5);
    if ($color === false) {
        throw new RuntimeException('imagecolorat failed');
    }
    $rgb = imagecolorsforindex($img->image, $color);
    expect($rgb['red'])->toBe(0);
    expect($rgb['green'])->toBe(255);
    expect($rgb['blue'])->toBe(0);
});
