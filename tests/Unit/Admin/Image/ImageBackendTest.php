<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Admin\Image;

use Exception;
use LogicException;
use Piwigo\Admin\Image\ImageBackend;
use Piwigo\Admin\Image\ImageInterface;
use Piwigo\Admin\Image\ImageProcessingException;
use Piwigo\Admin\Image\Projection\ResizeCrop;
use Piwigo\Admin\Image\Projection\ResizeDimensions;
use Piwigo\Admin\Image\Projection\WebpInfo;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\CurrentLogger;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Tests\Support\KernelContainerOverride;
use ReflectionClass;
use RuntimeException;

/**
 * __construct()'s unsupported-extension guard throws
 * ImageProcessingException. The "no image library available" branch
 * stays untested: GD is real and available in this environment, and
 * getLibrary()'s own fallback chain always resolves to 'gd' eventually
 * regardless of the requested library, so there's no realistic way to
 * make it return false here without mocking a global function this class
 * has no seam to inject. The same reasoning makes __construct()'s own
 * `default => throw ... "unknown image library"` match arm (only
 * reachable if getLibrary() itself somehow returned a 4th string, which
 * its own switch can never produce) and getLibrary()'s final `return
 * false;` (only reached if even 'gd' fails) untestable here too: all
 * three require GD itself to become unavailable mid-process, and GD (a
 * compiled-in extension, not a php.ini-toggleable one) can't be disabled
 * except by starting a whole new PHP engine without it -- which would
 * also rule out the 'gd' fallback these branches exist to guard against,
 * so no PHP process, subprocess or otherwise, can ever exercise them.
 * That's a genuinely different situation from the sibling
 * `function_exists('exif_read_data')` / `function_exists('exec')` guards
 * elsewhere in this class (see the subprocess-based tests further down):
 * those gate on php.ini's `disable_functions`, which a real, separate
 * `php -d disable_functions=...` subprocess genuinely enforces without
 * needing GD itself to go away.
 */

/**
 * Builds a real, minimal JPEG (via GD) with a hand-built EXIF APP1 segment
 * spliced in right after the SOI marker -- exif_read_data()/getimagesize()
 * both need genuinely valid marker-segment bytes, not arbitrary content, and
 * neither ImageMagick's `-set/-define exif:Orientation=` nor a synthetic
 * `xc:` canvas actually persists an EXIF profile (`identify
 * -verbose` reports "Orientation: Undefined" after either), so this hand-
 * rolled TIFF/IFD0 byte layout is the only reliable, dependency-free way to
 * get a real Orientation tag into a file getRotationAngle() will read.
 */
function pwgImageMakeJpegWithOrientation(int $orientation): string
{
    $tiff = 'II' . pack('v', 42) . pack('V', 8);
    $ifd = pack('v', 1)
        . pack('v', 0x0112) . pack('v', 3) . pack('V', 1) . pack('v', $orientation) . pack('v', 0)
        . pack('V', 0);
    $exifHeader = "Exif\x00\x00" . $tiff . $ifd;
    $app1 = "\xFF\xE1" . pack('n', strlen($exifHeader) + 2) . $exifHeader;

    $img = imagecreatetruecolor(20, 20);
    if ($img === false) {
        throw new RuntimeException('imagecreatetruecolor failed');
    }
    ob_start();
    imagejpeg($img);
    // ob_get_clean() can only return false when there's no active output
    // buffer -- ob_start() immediately above guarantees one here.
    $base = ob_get_clean();

    return substr($base, 0, 2) . $app1 . substr($base, 2);
}
function pwgImageTestMarker(): string
{
    /** @var string|null */
    static $marker = null;

    return $marker ??= sys_get_temp_dir() . '/piwigo-pwg-image-test-' . bin2hex(random_bytes(8));
}

/**
 * ImageBackend takes CurrentLogger via constructor injection, forwarded only
 * to the 'ext_imagick' branch (ImageExtImagick::write()'s own read) -- a
 * fresh, never-set() instance is safe here since every test in this file
 * uses 'gd' or lets getLibrary() fall back to it (this environment has no
 * ext_imagick/imagick binary available, see this file's own docblock).
 */
function pwgImageTestMake(string $sourceFilepath, ?string $library = null, ?ImageInterface $image = null): ImageBackend
{
    return new ImageBackend($sourceFilepath, new CurrentLogger(), new CurrentConfig(), $library, $image);
}

/**
 * Builds a ImageBackend whose underlying ImageInterface is a ImageBackendSpyImage
 * reporting the given (fake) width/height -- via __construct()'s own
 * $image override, so no real image decode ever happens. $sourceFilepath
 * only needs a real file on disk when getRotationAngle() will read its
 * EXIF data (automatic rotation tests) -- pwgResize() never reads pixels
 * through this double.
 *
 * @return array{0: ImageBackend, 1: ImageBackendSpyImage}
 */
function pwgImageTestMakeSpy(string $sourceFilepath, int|float $width, int|float $height): array
{
    $spy = new ImageBackendSpyImage($width, $height);
    $img = pwgImageTestMake($sourceFilepath, image: $spy);

    return [$img, $spy];
}

/**
 * Same shape as pwgImageTestMakeSpy(), but for ImageBackendSpyImageFileControl
 * -- exact control over the destination file getResizeResult() later
 * inspects via filesize().
 *
 * @return array{0: ImageBackend, 1: ImageBackendSpyImageFileControl}
 */
function pwgImageTestMakeFileControlSpy(string $sourceFilepath, int|float $width, int|float $height, ?int $writeBytes): array
{
    $spy = new ImageBackendSpyImageFileControl($width, $height, $writeBytes);
    $img = pwgImageTestMake($sourceFilepath, image: $spy);

    return [$img, $spy];
}

/**
 * Runs $script (appended after `require '<real vendor/autoload.php>';`)
 * in a genuinely separate `php` CLI process started with $flags -- see
 * tests/Unit/Core/ContainerDetectorTest.php's own docblock for why this
 * is the established pattern in this suite for closing branches gated on
 * a real PHP-engine-level fact (a disabled function, an unloaded
 * extension) that this class has no injection seam for: a real PHP
 * engine genuinely enforcing real `-d`/`-n` flags, not a mock of
 * ImageBackend or of any global function it calls, and the subprocess exits
 * on its own without leaking any state back into this shared PHPUnit
 * process the way an in-process override would.
 *
 * @param array<int, string> $flags
 * @return array{exit: int, stdout: string, stderr: string}
 */
function pwgImageRunSubprocess(array $flags, string $script): array
{
    $autoloadPath = dirname(__DIR__, 4) . '/vendor/autoload.php';
    if (! is_file($autoloadPath)) {
        throw new RuntimeException('autoload.php not found at ' . $autoloadPath);
    }

    $cmd = [PHP_BINARY, ...$flags, '-r', 'require ' . var_export($autoloadPath, true) . ';' . $script];

    $descriptors = [
        0 => ['file', '/dev/null', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $proc = proc_open($cmd, $descriptors, $pipes);
    if (! is_resource($proc)) {
        throw new RuntimeException('proc_open failed');
    }

    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $exit = proc_close($proc);

    return [
        'exit' => $exit,
        'stdout' => $stdout !== false ? $stdout : '',
        'stderr' => $stderr !== false ? $stderr : '',
    ];
}

beforeEach(function (): void {
    mkdir(pwgImageTestMarker(), 0o777, true);
});

afterEach(function (): void {
    $dir = pwgImageTestMarker();
    $files = glob($dir . '/*');
    foreach ($files !== false ? $files : [] as $file) {
        unlink($file);
    }
    rmdir($dir);
});

test('construct throws for an unsupported file extension', function (): void {
    $path = pwgImageTestMarker() . '/photo.bmp';
    file_put_contents($path, 'not a real bmp, extension alone is enough to hit the guard');

    expect(fn (): ImageBackend => pwgImageTestMake($path))
        ->toThrow(ImageProcessingException::class, '[Image] unsupported file extension');
});

test('construct accepts an uppercase file extension case-insensitively', function (): void {
    // __construct() lowercases the
    // extension before checking it against pictureExtensions() (a
    // lowercase list) -- every existing construct test uses an
    // already-lowercase extension, so a mutation that skips the
    // strtolower() call was never observed to matter.
    $path = pwgImageTestMarker() . '/photo.JPG';
    $gdImg = imagecreatetruecolor(10, 10);
    if ($gdImg === false) {
        throw new RuntimeException('imagecreatetruecolor failed');
    }
    imagejpeg($gdImg, $path);

    $img = pwgImageTestMake($path, 'gd');

    expect($img->library)
        ->toBe('gd');
});

test('getResizeDimensions leaves dimensions unchanged when both fit within the max bounds', function (): void {
    $result = ImageBackend::getResizeDimensions(100, 100, 200, 200);

    expect($result)
        ->toEqual(new ResizeDimensions(100, 100));
});

test('getResizeDimensions scales down on the width-bound side', function (): void {
    // ratio_width=2 > ratio_height=1 -> the "else" branch: width pinned to
    // max_width, height derived from ratio_width.
    $result = ImageBackend::getResizeDimensions(800, 400, 400, 400);

    // round() always returns float in PHP -- $max_width itself passes
    // through unrounded (still the plain int param), but the derived side
    // is always a real float, never coerced back to int.
    expect($result)
        ->toEqual(new ResizeDimensions(400, 200.0));
});

test('getResizeDimensions scales down on the height-bound side', function (): void {
    // ratio_height=2 > ratio_width=1 -> the "if" branch: height pinned to
    // max_height, width derived from ratio_height.
    $result = ImageBackend::getResizeDimensions(400, 800, 400, 400);

    expect($result)
        ->toEqual(new ResizeDimensions(200.0, 400));
});

test('getResizeDimensions rounds (not floors) the width-bound side when the fraction is >= 0.5', function (): void {
    // The sibling test above lands
    // on a whole-number ratio (round(400/2) = 200 exactly), so round()
    // and floor() coincide there. height=401 (vs. 400) shifts the raw
    // value to 200.5, where round() (201) and floor() (200) differ.
    $result = ImageBackend::getResizeDimensions(800, 401, 400, 400);

    expect($result)
        ->toEqual(new ResizeDimensions(400, 201.0));
});

test('getResizeDimensions rounds (not floors) the height-bound side when the fraction is >= 0.5', function (): void {
    $result = ImageBackend::getResizeDimensions(401, 800, 400, 400);

    expect($result)
        ->toEqual(new ResizeDimensions(201.0, 400));
});

test('getResizeDimensions crops a portrait image against a landscape-ish max, swapping max dimensions via follow_orientation', function (): void {
    // width(100) < height(300) and follow_orientation=true -> max_width/
    // max_height swap to (120, 160) first; dest_ratio(0.75) > img_ratio
    // (0.333) selects the destHeight/y-crop branch (round()-derived
    // height/y come back as float; width/x pass through as the untouched
    // int params).
    $result = ImageBackend::getResizeDimensions(100, 300, 160, 120, null, true, true);

    expect($result)
        ->toEqual(new ResizeDimensions(100, 133.0, new ResizeCrop(100, 133.0, 0, 84.0)));
});

test('getResizeDimensions crops a landscape image, selecting the destWidth/x-crop branch', function (): void {
    // width(300) > height(100) -- no follow_orientation swap. dest_ratio(1)
    // < img_ratio(3) selects the destWidth/x-crop branch instead (here
    // width/x are the round()-derived floats; height/y pass through as
    // untouched ints).
    $result = ImageBackend::getResizeDimensions(300, 100, 200, 200, null, true);

    expect($result)
        ->toEqual(new ResizeDimensions(100.0, 100, new ResizeCrop(100.0, 100, 100.0, 0)));
});

test('getResizeDimensions rounds (not floors) the destHeight/y crop math when the fraction is >= 0.5', function (): void {
    // The sibling portrait test
    // above has a raw destHeight of 133.333 -- round() and floor() both
    // land on 133 there, so a round()->floor() mutation is invisible.
    // width=101 (vs. 100) shifts the fraction to 134.667/82.5, where
    // round() (135/83) and floor() (134/82) genuinely differ.
    $result = ImageBackend::getResizeDimensions(101, 300, 160, 120, null, true, true);

    expect($result)
        ->toEqual(new ResizeDimensions(101, 135.0, new ResizeCrop(101, 135.0, 0, 83.0)));
});

test('getResizeDimensions rounds (not floors) the destWidth/x crop math when the fraction is >= 0.5', function (): void {
    // Same "round vs floor coincide" gap as the sibling test above, for
    // the destWidth/x-crop branch instead: height=101, max 200x150
    // yields a raw destWidth of 134.667/x of 82.667, where round()
    // (135/83) and floor() (134/82) genuinely differ.
    $result = ImageBackend::getResizeDimensions(300, 101, 200, 150, null, true);

    expect($result)
        ->toEqual(new ResizeDimensions(135.0, 101, new ResizeCrop(135.0, 101, 83.0, 0)));
});

test('getResizeDimensions does not swap max dimensions for a square (tied width/height) image', function (): void {
    // `$width < $height` becoming
    // `<=` only matters when width and height are exactly equal -- a
    // square source with a non-square max makes the (wrongly) swapped
    // vs. not-swapped outcome genuinely different.
    $result = ImageBackend::getResizeDimensions(200, 200, 160, 120, null, true);

    expect($result)
        ->toEqual(new ResizeDimensions(160, 120.0, new ResizeCrop(200, 150.0, 0, 25.0)));
});

test('getResizeDimensions swaps width/height for a 90-degree rotation before and after computing the max-size fit', function (): void {
    $result = ImageBackend::getResizeDimensions(100, 200, 50, 100, 90, false);

    // Pre-swap: destination_width=$max_width (int, unchanged), destination_
    // height=round(...) (float); the post-computation rotate_for_dimensions
    // swap then puts that float first.
    expect($result)
        ->toEqual(new ResizeDimensions(25.0, 50));
});

test('getResizeDimensions swaps width/height for a 270-degree rotation too, not just 90', function (): void {
    // The only existing rotation
    // test uses 90 -- a mutation shrinking/growing/dropping the [90, 270]
    // array (269, 271, or [90] alone) all still swap for 90 but silently
    // stop swapping for 270, which none of the existing tests would catch.
    $result = ImageBackend::getResizeDimensions(100, 200, 50, 100, 270, false);

    expect($result)
        ->toEqual(new ResizeDimensions(25.0, 50));
});

test('getResizeDimensions applies neither crop offset when dest_ratio exactly equals img_ratio', function (): void {
    // Documents real, intended behavior (no crop key when the aspect
    // ratios already match) -- NOT a mutation kill despite first looking
    // like one. `$dest_ratio > $img_ratio` becoming `>=` (line 237) was
    // checked against this exact scenario, producing the byte-identical
    // output: at an EXACT ratio tie, destHeight
    // = round(width * max_height / max_width) is a mathematical identity
    // for `height` itself (since dest_ratio == img_ratio == width/height
    // means width/dest_ratio == height exactly), so the "wrongly" taken
    // branch reassigns $height to its own current value (int 100 -> float
    // 100.0, silently absorbed by the later round()-based destination_
    // height computation either way) and leaves $x/$y at their untouched
    // 0/0 defaults -- there is no external observable difference between
    // `>` and `>=` (or `<`/`<=` on line 241, same reasoning) at this exact
    // boundary. Provably inert, not an untested gap.
    $result = ImageBackend::getResizeDimensions(200, 100, 100, 50, null, true);

    expect($result)
        ->toEqual(new ResizeDimensions(100, 50.0));
});

test('getResizeDimensions rounds (not ceils) the destWidth/destHeight crop-branch math when the fraction is below 0.5', function (): void {
    // Every existing "rounds (not
    // floors)" test above deliberately picks a fraction >= 0.5 to
    // distinguish round() from floor() -- but round() and ceil() also
    // AGREE whenever the fraction is >= 0.5 (both round up), so those same
    // tests can never catch a RoundToCeil mutation on this line. A
    // fraction < 0.5 is required to tell round() (rounds down here) apart
    // from ceil() (always rounds up for any non-zero fraction).
    // width(300):height(100), max(97,97) -> dest_ratio(1) < img_ratio(3)
    // selects the destWidth/x-crop branch: destWidth = round(100*97/97) =
    // round(100) = 100 exactly (no fraction there), but width(300) is
    // scaled differently -- computed concretely: destWidth = round(height
    // * max_width / max_height) = round(100 * 97 / 97) = 100. Re-derived
    // with max(101, 97) instead so the division itself lands on a
    // fraction < 0.5: destWidth = round(100 * 101 / 97) = round(104.123..)
    // = 104 (round and floor agree; ceil would give 105).
    $result = ImageBackend::getResizeDimensions(300, 100, 101, 97, null, true);

    expect($result)
        ->toEqual(new ResizeDimensions(101.0, 97, new ResizeCrop(104.0, 100, 98.0, 0)));
});

test('getResizeDimensions rounds (not ceils) the final destination_width when the max-size-exceeded fraction is below 0.5', function (): void {
    // Same round()-vs-ceil()
    // reasoning as the crop-branch test above, for the final
    // max-size-exceeded destination_width = round(width / ratio_height)
    // branch instead (selected when ratio_width < ratio_height).
    // width(203):max_width(100) -> ratio_width=2.03; height(100):
    // max_height(48) -> ratio_height=2.0833.., so ratio_width < ratio_height
    // selects this branch: round(203 / 2.0833..) = round(97.44) = 97
    // (ceil would give 98), per the real function (not hand-derived).
    $result = ImageBackend::getResizeDimensions(203, 100, 100, 48, null, false);

    expect($result->width)
        ->toBe(97.0);
});

test('getResizeDimensions rounds (not ceils) the final destination_height when the max-size-exceeded fraction is below 0.5', function (): void {
    // Mirror of the destination_width test above, for the sibling
    // destination_height = round(height / ratio_width) branch instead
    // (selected when ratio_width >= ratio_height). height(203):
    // max_height(100) -> ratio_height=2.03; width(100):max_width(48) ->
    // ratio_width=2.0833.., so ratio_width >= ratio_height selects this
    // branch: round(203 / 2.0833..) = round(97.44) = 97 (ceil would give
    // 98), per the real function.
    $result = ImageBackend::getResizeDimensions(100, 203, 48, 100, null, false);

    expect($result->height)
        ->toBe(97.0);
});

test('getRotationCodeFromAngle maps every known angle, treating null the same as 0', function (): void {
    expect(ImageBackend::getRotationCodeFromAngle(null))->toBe(0);
    expect(ImageBackend::getRotationCodeFromAngle(0))->toBe(0);
    expect(ImageBackend::getRotationCodeFromAngle(90))->toBe(1);
    expect(ImageBackend::getRotationCodeFromAngle(180))->toBe(2);
    expect(ImageBackend::getRotationCodeFromAngle(270))->toBe(3);
});

test('getRotationCodeFromAngle throws for an unexpected angle', function (): void {
    expect(fn (): int => ImageBackend::getRotationCodeFromAngle(45))
        ->toThrow(Exception::class, 'getRotationCodeFromAngle(): unexpected rotation angle 45');
});

test('getRotationAngleFromCode maps every known code, wrapping modulo 4', function (): void {
    expect(ImageBackend::getRotationAngleFromCode(0))->toBe(0);
    expect(ImageBackend::getRotationAngleFromCode(1))->toBe(90);
    expect(ImageBackend::getRotationAngleFromCode(2))->toBe(180);
    expect(ImageBackend::getRotationAngleFromCode(3))->toBe(270);
    // the mod-4 wrap covers a value one full cycle past 3.
    expect(ImageBackend::getRotationAngleFromCode(4))->toBe(0);
});

test('getRotationAngleFromCode throws for an unexpected code', function (): void {
    expect(fn (): int => ImageBackend::getRotationAngleFromCode(-1))
        ->toThrow(Exception::class);
});

test('getRotationAngle returns null for a non-JPEG source', function (): void {
    $path = pwgImageTestMarker() . '/photo.png';
    $img = imagecreatetruecolor(10, 10);
    if ($img === false) {
        throw new RuntimeException('imagecreatetruecolor failed');
    }
    imagepng($img, $path);

    expect(ImageBackend::getRotationAngle($path))->toBeNull();
});

test('getRotationAngle returns 0 for a JPEG with no EXIF orientation tag', function (): void {
    $path = pwgImageTestMarker() . '/photo.jpg';
    $img = imagecreatetruecolor(10, 10);
    if ($img === false) {
        throw new RuntimeException('imagecreatetruecolor failed');
    }
    imagejpeg($img, $path);

    expect(ImageBackend::getRotationAngle($path))->toBe(0);
});

test('getSharpenMatrix returns a normalized 3x3 kernel centered on the amount-derived weight', function (): void {
    $matrix = ImageBackend::getSharpenMatrix(50);

    expect($matrix)
        ->toHaveCount(3);
    foreach ($matrix as $row) {
        expect($row)->toHaveCount(3);
    }
    // Every corner/edge cell stays -1 pre-normalization; only the center
    // cell depends on $amount.
    expect($matrix[0][0])->toBe($matrix[0][2]);
    expect($matrix[0][0])->toBe($matrix[2][0]);
    expect($matrix[1][1])->not->toBe($matrix[0][0]);
});

test('getSharpenMatrix computes exact, real weight values for a known amount', function (): void {
    // The sibling test above only
    // checks structural shape, never a real computed value -- amount=50's
    // pre-normalization center weight is round(abs(-48.0 + 50*0.38), 2) =
    // 29.0, and every cell (corners included) is that raw matrix summed
    // then divided by the same norm, so the center/corner *ratio* -29.0
    // is exact and stable regardless of floating-point precision in the
    // individual cells.
    $matrix = ImageBackend::getSharpenMatrix(50);

    expect($matrix[1][1] / $matrix[0][0])->toBe(-29.0);
    // The center/corner ratio alone can't tell a real normalization pass
    // from a skipped one (dividing -- or not -- every cell by the same
    // norm doesn't change their ratio to each other) -- the raw center
    // weight itself (29.0 pre-normalization, ~1.38 after dividing by the
    // real norm of 21) is what actually proves the /=$norm loop ran.
    expect($matrix[1][1])->toBeGreaterThan(1.0)->toBeLessThan(2.0);
});

test('webpInfo detects the simple lossy VP8 format', function (): void {
    $path = pwgImageTestMarker() . '/lossy.webp';
    file_put_contents($path, "RIFF\x00\x00\x00\x00" . 'WEBPVP8 ' . str_repeat("\x00", 9));

    expect(ImageBackend::webpInfo($path))->toEqual(new WebpInfo('VP8', false, false));
});

test('webpInfo detects a transparent lossless VP8L format', function (): void {
    $path = pwgImageTestMarker() . '/lossless-transparent.webp';
    $buf = "RIFF\x00\x00\x00\x00" . 'WEBPVP8L' . str_repeat("\x00", 8) . chr(0x10);
    file_put_contents($path, $buf);

    expect(ImageBackend::webpInfo($path))->toEqual(new WebpInfo('VP8L', false, true));
});

test('webpInfo detects a non-transparent lossless VP8L format', function (): void {
    $path = pwgImageTestMarker() . '/lossless-opaque.webp';
    $buf = "RIFF\x00\x00\x00\x00" . 'WEBPVP8L' . str_repeat("\x00", 8) . chr(0x00);
    file_put_contents($path, $buf);

    expect(ImageBackend::webpInfo($path))->toEqual(new WebpInfo('VP8L', false, false));
});

test('webpInfo detects an animated, transparent extended VP8X format', function (): void {
    $path = pwgImageTestMarker() . '/extended.webp';
    $buf = "RIFF\x00\x00\x00\x00" . 'WEBPVP8X' . str_repeat("\x00", 4) . chr(0x12) . str_repeat("\x00", 4);
    file_put_contents($path, $buf);

    expect(ImageBackend::webpInfo($path))->toEqual(new WebpInfo('VP8X', true, true));
});

test('webpInfo detects an extended VP8X format that is animated but not transparent', function (): void {
    // The sibling test above sets
    // both flag bits together, so a mutation on either individual bit
    // check (0x02 for animation, 0x10 for transparency) can't be told
    // apart from a mutation on the other -- only a flags byte with just
    // one bit set can.
    $path = pwgImageTestMarker() . '/extended-animated-only.webp';
    $buf = "RIFF\x00\x00\x00\x00" . 'WEBPVP8X' . str_repeat("\x00", 4) . chr(0x02) . str_repeat("\x00", 4);
    file_put_contents($path, $buf);

    expect(ImageBackend::webpInfo($path))->toEqual(new WebpInfo('VP8X', true, false));
});

test('webpInfo correctly reports no transparency for a VP8L flags byte with an adjacent, unrelated bit set', function (): void {
    // Every existing VP8L test uses
    // a flags byte that either has 0x10 set (transparent) or is all-zero
    // (opaque) -- neither distinguishes the real `& 0x10` check from a
    // mutated `& 0x11`, since both bit patterns produce the same truthy/
    // falsy AND result for those two byte values. A byte with ONLY the
    // adjacent 0x01 bit set does: `0x01 & 0x10` is 0 (correctly opaque),
    // but the mutated `0x01 & 0x11` is 0x01 (falsely transparent).
    $path = pwgImageTestMarker() . '/lossless-adjacent-bit.webp';
    $buf = "RIFF\x00\x00\x00\x00" . 'WEBPVP8L' . str_repeat("\x00", 8) . chr(0x01);
    file_put_contents($path, $buf);

    expect(ImageBackend::webpInfo($path))->toEqual(new WebpInfo('VP8L', false, false));
});

test('webpInfo correctly reports no animation or transparency for a VP8X flags byte with an adjacent, unrelated bit set', function (): void {
    // Same reasoning as the VP8L test above, for both VP8X flag checks
    // (`& 0x2` for animation, `& 0x10` for transparency) at once: a flags
    // byte of only 0x01 makes both real checks correctly false, while
    // either mutated check (`& 0x3` or `& 0x11`) would wrongly turn true.
    $path = pwgImageTestMarker() . '/extended-adjacent-bit.webp';
    $buf = "RIFF\x00\x00\x00\x00" . 'WEBPVP8X' . str_repeat("\x00", 4) . chr(0x01) . str_repeat("\x00", 4);
    file_put_contents($path, $buf);

    expect(ImageBackend::webpInfo($path))->toEqual(new WebpInfo('VP8X', false, false));
});

test('webpInfo detects an extended VP8X format that is transparent but not animated', function (): void {
    $path = pwgImageTestMarker() . '/extended-transparent-only.webp';
    $buf = "RIFF\x00\x00\x00\x00" . 'WEBPVP8X' . str_repeat("\x00", 4) . chr(0x10) . str_repeat("\x00", 4);
    file_put_contents($path, $buf);

    expect(ImageBackend::webpInfo($path))->toEqual(new WebpInfo('VP8X', false, true));
});

test('webpInfo throws for a file that is not a real WEBP container', function (): void {
    $path = pwgImageTestMarker() . '/not-webp.webp';
    file_put_contents($path, str_repeat('x', 30));

    expect(fn (): WebpInfo => ImageBackend::webpInfo($path))
        ->toThrow(Exception::class, 'webpInfo(): not a valid webp image');
});

test('webpInfo throws for a buffer exactly one byte short of the 25-byte minimum header, even if otherwise well-formed', function (): void {
    // `strlen($buf) < 25` becoming
    // `< 24` is invisible to every existing test above, all of which use
    // either a full 25+ byte buffer or an unrelated too-short buffer
    // that's already caught for other reasons. A 24-byte buffer that is
    // otherwise a well-formed lossy VP8 header proves the length check
    // itself is what's rejecting it, not the format bytes.
    $path = pwgImageTestMarker() . '/24-bytes.webp';
    // 'RIFF'(4) + size(4) + 'WEBP'(4) + 'VP8'(3) + ' '(1) = 16 bytes,
    // padded to exactly 24.
    $buf = "RIFF\x00\x00\x00\x00" . 'WEBPVP8 ' . str_repeat("\x00", 8);
    expect(strlen($buf))
        ->toBe(24);
    file_put_contents($path, $buf);

    expect(fn (): WebpInfo => ImageBackend::webpInfo($path))
        ->toThrow(Exception::class, 'webpInfo(): not a valid webp image');
});

test('isGd reports GD as available in this environment', function (): void {
    expect(ImageBackend::isGd())->toBeTrue();
});

test('getLibrary resolves an explicit "gd" request without probing imagick at all', function (): void {
    expect(ImageBackend::getLibrary('gd', 'jpg'))->toBe('gd');
});

test('getLibrary falls back to auto for an unrecognized library name, resolving to the same real library "auto" itself would', function (): void {
    // Whichever backend this environment's own 'auto' probe resolves to
    // first (ext_imagick CLI, the imagick PHP extension, or GD -- all 3
    // are real possibilities depending on what's installed where these
    // tests run), an unrecognized $library name must resolve to that
    // exact same result, per getLibrary()'s own default-case fallback.
    expect(ImageBackend::getLibrary('not-a-real-library', 'jpg'))->toBe(ImageBackend::getLibrary('auto', 'jpg'));
});

test('getGraphicsLibraryLabel formats the resolved library and version', function (): void {
    // Only checking "non-empty
    // string" can't tell a real label/version pair apart from a mangled
    // concatenation -- ext_imagick (a real `magick` binary is installed
    // in this environment, per `command -v magick`) is
    // getLibrary()'s first real "auto" pick, so this asserts the exact,
    // real label format for it.
    $label = ImageBackend::getGraphicsLibraryLabel();

    expect($label)
        ->toStartWith('External ImageMagick ')
        ->and($label)
        ->toMatch('/^External ImageMagick \d+\.\d+\.\d+/');
});

test('constructor uses a caller-provided image instance and skips its own library resolution entirely', function (): void {
    $fake = new class() implements ImageInterface {
        #[\Override]
        public function getWidth(): int
        {
            return 123;
        }

        #[\Override]
        public function getHeight(): int
        {
            return 456;
        }

        #[\Override]
        public function setCompressionQuality(int $quality): bool
        {
            return true;
        }

        #[\Override]
        public function crop(int|float $width, int|float $height, int|float $x, int|float $y): bool
        {
            return true;
        }

        #[\Override]
        public function strip(): bool
        {
            return true;
        }

        #[\Override]
        public function rotate(int|float $rotation): bool
        {
            return true;
        }

        #[\Override]
        public function resize(int|float $width, int|float $height): bool
        {
            return true;
        }

        #[\Override]
        public function sharpen(int|float $amount): bool
        {
            return true;
        }

        #[\Override]
        public function compose(ImageBackend $overlay, int|float $x, int|float $y, int|float $opacity): bool
        {
            return true;
        }

        #[\Override]
        public function write(string $destination_filepath): bool
        {
            return true;
        }
    };

    // A path this class's own real library resolution would reject
    // outright (unsupported extension) -- proving the caller-provided
    // $image really did short-circuit __construct() before that check
    // ever ran, not merely that it happened to also pass.
    $img = pwgImageTestMake(pwgImageTestMarker() . '/whatever.totally-unsupported-ext', image: $fake);

    expect($img->getWidth())
        ->toBe(123);
    expect($img->library)
        ->toBe('');
});

test('an instance built without going through the constructor throws LogicException on first real method call', function (): void {
    $img = new ReflectionClass(ImageBackend::class)->newInstanceWithoutConstructor();

    expect(fn () => $img->getWidth())
        ->toThrow(LogicException::class, 'ImageBackend: no image library instantiated');
});

test('destroy delegates to a GD-backed image that implements it', function (): void {
    $path = pwgImageTestMarker() . '/destroy-me.jpg';
    $gdImg = imagecreatetruecolor(10, 10);
    if ($gdImg === false) {
        throw new RuntimeException('imagecreatetruecolor failed');
    }
    imagejpeg($gdImg, $path);

    $img = pwgImageTestMake($path, 'gd');

    expect($img->destroy())
        ->toBeTrue();
});

test('pwgResize copies the source unchanged when it already fits within the max bounds', function (): void {
    $source = pwgImageTestMarker() . '/small-source.jpg';
    $dest = pwgImageTestMarker() . '/small-dest.jpg';
    $gdImg = imagecreatetruecolor(40, 30);
    if ($gdImg === false) {
        throw new RuntimeException('imagecreatetruecolor failed');
    }
    imagejpeg($gdImg, $source);

    $img = pwgImageTestMake($source, 'gd');
    $result = $img->pwgResize($dest, 200, 200, 90, automatic_rotation: false);

    expect($result->width)
        ->toBe(40);
    expect($result->height)
        ->toBe(30);
    expect(file_exists($dest))
        ->toBeTrue();
    $destSize = getimagesize($dest);
    if ($destSize === false) {
        throw new RuntimeException('getimagesize failed');
    }
    expect($destSize[0])->toBe(40);
    expect($destSize[1])->toBe(30);
    // No existing pwgResize test
    // ever checked source/destination/size/library -- only width/height
    // and the destination file's own existence.
    expect($result->source)
        ->toBe($source)
        ->and($result->destination)
        ->toBe($dest)
        ->and($result->library)
        ->toBe('gd')
        ->and($result->size)
        ->toEndWith(' KB')
        ->and((float) $result->size)
        ->toBeGreaterThanOrEqual(0.0)
        ->and($result->time)
        ->toEndWith(' ms');
    // The "already fits" branch's own
    // condition (line 180, 4 float casts + the === itself) and its early
    // return (line 183) were all untested -- every mutation there still
    // lands on a *resized* image with the exact same final 40x30 dimensions
    // (GD resizing 40x30 to 40x30 is a no-op pixel-wise), so width/height/
    // file-exists assertions alone can't distinguish the fast copy() path
    // from the full setCompressionQuality()+resize()+write() path. A
    // byte-identical copy (untouched by any re-encode) only happens on the
    // real fast path.
    expect(file_get_contents($dest))
        ->toBe(file_get_contents($source));
});

test('pwgResize scales a real oversized image down and writes the resized destination', function (): void {
    $source = pwgImageTestMarker() . '/big-source.jpg';
    $dest = pwgImageTestMarker() . '/big-dest.jpg';
    $gdImg = imagecreatetruecolor(400, 200);
    if ($gdImg === false) {
        throw new RuntimeException('imagecreatetruecolor failed');
    }
    imagejpeg($gdImg, $source);

    $img = pwgImageTestMake($source, 'gd');
    $result = $img->pwgResize($dest, 100, 100, 85, automatic_rotation: false, strip_metadata: true);

    // ratio_width(4) > ratio_height(2) -> width pinned to max_width(100),
    // height derived: round(200/4) = 50.
    expect($result->width)
        ->toBe(100);
    expect($result->height)
        ->toBe(50.0);
    $destSize = getimagesize($dest);
    if ($destSize === false) {
        throw new RuntimeException('getimagesize failed');
    }
    expect($destSize[0])->toBe(100);
    expect($destSize[1])->toBe(50);
});

test('pwgResize crops a mismatched-aspect image before resizing', function (): void {
    $source = pwgImageTestMarker() . '/crop-source.jpg';
    $dest = pwgImageTestMarker() . '/crop-dest.jpg';
    $gdImg = imagecreatetruecolor(300, 100);
    if ($gdImg === false) {
        throw new RuntimeException('imagecreatetruecolor failed');
    }
    imagejpeg($gdImg, $source);

    $img = pwgImageTestMake($source, 'gd');
    $result = $img->pwgResize($dest, 100, 100, 85, automatic_rotation: false, crop: true);

    expect($result->width)
        ->toBe(100.0);
    expect($result->height)
        ->toBe(100);
    $destSize = getimagesize($dest);
    if ($destSize === false) {
        throw new RuntimeException('getimagesize failed');
    }
    expect($destSize[0])->toBe(100);
    expect($destSize[1])->toBe(100);
});

test('pwgResize rotates the destination when the source carries a real EXIF orientation tag', function (): void {
    $source = pwgImageTestMarker() . '/rotate-source.jpg';
    $dest = pwgImageTestMarker() . '/rotate-dest.jpg';
    // Orientation 6 -> getRotationAngle() maps it to a 270-degree rotation.
    file_put_contents($source, pwgImageMakeJpegWithOrientation(6));

    $img = pwgImageTestMake($source, 'gd');
    $result = $img->pwgResize($dest, 15, 15, 85, automatic_rotation: true);

    // The 20x20 source already fits within 15x15 on neither axis untouched,
    // so this genuinely exercises resize() + rotate() + write(), not the
    // early copy() shortcut.
    expect(file_exists($dest))
        ->toBeTrue();
    expect($result->time)
        ->toBeString();
    expect($result->time)
        ->toEndWith(' ms');
});

test('pwgResize calls only setCompressionQuality/resize/write when strip/crop/rotation are all off', function (): void {
    // No existing test ever asserted
    // the real call *sequence* -- a mutation removing the strip()/crop()/
    // rotate() calls entirely, or inverting the `if ($strip_metadata)`
    // check, produces a destination file with identical final dimensions
    // either way, so width/height-only assertions can't tell them apart.
    $dest = pwgImageTestMarker() . '/spy-plain-dest.jpg';
    [$img, $spy] = pwgImageTestMakeSpy(pwgImageTestMarker() . '/spy-plain-source.jpg', 200, 100);

    $img->pwgResize($dest, 100, 50, 77, automatic_rotation: false, strip_metadata: false, crop: false);

    expect($spy->calls)
        ->toBe(['setCompressionQuality(77)', 'resize(100,50)', 'write']);
});

test('pwgResize calls strip() between setCompressionQuality and resize when strip_metadata is true', function (): void {
    $dest = pwgImageTestMarker() . '/spy-strip-dest.jpg';
    [$img, $spy] = pwgImageTestMakeSpy(pwgImageTestMarker() . '/spy-strip-source.jpg', 200, 100);

    $img->pwgResize($dest, 100, 50, 77, automatic_rotation: false, strip_metadata: true, crop: false);

    expect($spy->calls)
        ->toBe(['setCompressionQuality(77)', 'strip', 'resize(100,50)', 'write']);
});

test('pwgResize calls crop() before resize() when the computed dimensions include a crop offset', function (): void {
    $dest = pwgImageTestMarker() . '/spy-crop-dest.jpg';
    // 300x100 source into a 100x100 max with crop:true mismatches aspect
    // ratio (3:1 vs 1:1), so getResizeDimensions() computes a real,
    // non-zero crop offset -- see the destWidth/x-crop-branch unit test
    // above for the exact same shape.
    [$img, $spy] = pwgImageTestMakeSpy(pwgImageTestMarker() . '/spy-crop-source.jpg', 300, 100);

    $img->pwgResize($dest, 100, 100, 77, automatic_rotation: false, crop: true);

    expect($spy->calls)
        ->toBe(['setCompressionQuality(77)', 'crop(100,100,100,0)', 'resize(100,100)', 'write']);
});

test('pwgResize does not call rotate() when automatic rotation resolves to exactly 0 degrees', function (): void {
    // `$rotation !== null &&
    // $rotation !== 0` becoming `=== 0`, `!== -1`, or `!== 1` is invisible
    // to the sibling "rotates the destination" test above, which only
    // ever exercises a genuinely non-zero rotation (270 degrees, from
    // orientation 6). EXIF orientation 1 ("normal") is a real value
    // getRotationAngle() maps to 0 (not null) -- the one case that
    // reaches the `$rotation !== null` check with a real, non-null zero.
    $dest = pwgImageTestMarker() . '/spy-no-rotate-dest.jpg';
    $source = pwgImageTestMarker() . '/spy-no-rotate-source.jpg';
    file_put_contents($source, pwgImageMakeJpegWithOrientation(1));
    [$img, $spy] = pwgImageTestMakeSpy($source, 200, 100);

    $img->pwgResize($dest, 100, 50, 77, automatic_rotation: true);

    expect($spy->calls)
        ->toBe(['setCompressionQuality(77)', 'resize(100,50)', 'write']);
});

test('pwgResize calls rotate() when automatic rotation resolves to a real non-zero angle', function (): void {
    $dest = pwgImageTestMarker() . '/spy-rotate-dest.jpg';
    $source = pwgImageTestMarker() . '/spy-rotate-source.jpg';
    // Orientation 6 -> 270 degrees, same mapping as the sibling real-GD
    // rotate test above -- here isolated to just prove rotate() itself is
    // called with the right value, independent of resize()'s own effect.
    // A 90/270 rotation also makes getResizeDimensions() swap width/
    // height both before and after computing the max-size fit (see its
    // own unit tests above) -- 200x100 into a 100x50 max, rotated, lands
    // on 50x25 (per the real function, not
    // hand-derived), not the naive unrotated 100x50.
    file_put_contents($source, pwgImageMakeJpegWithOrientation(6));
    [$img, $spy] = pwgImageTestMakeSpy($source, 200, 100);

    $img->pwgResize($dest, 100, 50, 77, automatic_rotation: true);

    expect($spy->calls)
        ->toBe(['setCompressionQuality(77)', 'resize(50,25)', 'rotate(270)', 'write']);
});

test('webpInfo throws when the file cannot be opened for reading', function (): void {
    $path = pwgImageTestMarker() . '/does-not-exist.webp';

    // fopen() on a missing file emits a real PHP warning -- same
    // set_error_handler swallow-for-one-call pattern as UploadServiceTest's
    // md5_file() case, needed because phpunit.xml's failOnWarning="true"
    // would otherwise convert it into an exception before this method's own
    // `if (! (bool) $fp)` check (and its own distinctly-worded exception)
    // is ever reached.
    set_error_handler(static fn (): bool => true);
    try {
        expect(fn (): WebpInfo => ImageBackend::webpInfo($path))
            ->toThrow(Exception::class, "webpInfo(): fopen({$path}): Failed");
    } finally {
        restore_error_handler();
    }
});

test('webpInfo throws for a well-formed VP8 header with an unrecognized sub-format byte', function (): void {
    $path = pwgImageTestMarker() . '/unknown-subformat.webp';
    // Valid up through byte 14 ('VP8'), but byte 15 is neither ' ', 'L' nor 'X'.
    file_put_contents($path, "RIFF\x00\x00\x00\x00" . 'WEBPVP8?' . str_repeat("\x00", 9));

    expect(fn (): WebpInfo => ImageBackend::webpInfo($path))
        ->toThrow(Exception::class, 'webpInfo(): could not detect webp type');
});

test('getRotationAngle returns null when getimagesize() fails to read the file, instead of throwing', function (): void {
    // Every real call site of getRotationAngle() (UploadService::
    // addUploadedFile() in particular, for any non-picture file allowed via
    // CurrentConfig::uploadFormAllTypes()) calls this with no surrounding
    // try/catch, so a genuinely non-image upload must not throw. "Not
    // decodable as an image at all" is a strict superset of "not a JPEG",
    // which this method already treats as a plain `null` (no rotation) two
    // lines below -- see tests/Integration/UploadServiceTest.php for the
    // real call-site coverage.
    $path = pwgImageTestMarker() . '/does-not-exist.jpg';

    // getimagesize() on a missing file also emits a real PHP warning --
    // same swallow-for-one-call reasoning as webpInfo()'s fopen() case
    // above.
    set_error_handler(static fn (): bool => true);
    try {
        expect(ImageBackend::getRotationAngle($path))->toBeNull();
    } finally {
        restore_error_handler();
    }
});

test('getRotationAngle maps EXIF orientation 3 to a 180-degree rotation', function (): void {
    $path = pwgImageTestMarker() . '/orientation-3.jpg';
    file_put_contents($path, pwgImageMakeJpegWithOrientation(3));

    expect(ImageBackend::getRotationAngle($path))->toBe(180);
});

test('getRotationAngle maps EXIF orientation 6 to a 270-degree rotation', function (): void {
    $path = pwgImageTestMarker() . '/orientation-6.jpg';
    file_put_contents($path, pwgImageMakeJpegWithOrientation(6));

    expect(ImageBackend::getRotationAngle($path))->toBe(270);
});

test('getRotationAngle maps EXIF orientation 8 to a 90-degree rotation', function (): void {
    $path = pwgImageTestMarker() . '/orientation-8.jpg';
    file_put_contents($path, pwgImageMakeJpegWithOrientation(8));

    expect(ImageBackend::getRotationAngle($path))->toBe(90);
});

test('getRotationAngle maps EXIF orientation 4 to a 180-degree rotation', function (): void {
    // Every orientation pair
    // (['3','4'], ['5','6'], ['7','8']) only ever had ONE of its two
    // members exercised (3, 6, 8 above) -- a mutation shrinking any pair
    // down to just its already-tested member survives undetected unless
    // the *other* member is tested too.
    $path = pwgImageTestMarker() . '/orientation-4.jpg';
    file_put_contents($path, pwgImageMakeJpegWithOrientation(4));

    expect(ImageBackend::getRotationAngle($path))->toBe(180);
});

test('getRotationAngle maps EXIF orientation 5 to a 270-degree rotation', function (): void {
    $path = pwgImageTestMarker() . '/orientation-5.jpg';
    file_put_contents($path, pwgImageMakeJpegWithOrientation(5));

    expect(ImageBackend::getRotationAngle($path))->toBe(270);
});

test('getRotationAngle maps EXIF orientation 7 to a 90-degree rotation', function (): void {
    $path = pwgImageTestMarker() . '/orientation-7.jpg';
    file_put_contents($path, pwgImageMakeJpegWithOrientation(7));

    expect(ImageBackend::getRotationAngle($path))->toBe(90);
});

test('isExtImagick returns false when the configured binary directory has no real ImageMagick binary', function (): void {
    // ImageBackend's own private currentConfig() resolver is a fresh,
    // unmemoized instance pre-boot -- unlike FilesystemHelper's
    // mkgetdir()/getFsDirectories() (which take CurrentConfig as a real,
    // explicit param specifically because of this exact coupling), is_
    // ext_imagick()/getLibrary()/getGraphicsLibrary()/
    // getExtImagickCommand() have too many real callers across too many
    // files for that to be proportional here -- so this test boots Kernel
    // instead, so this call's own CurrentConfigTestFactory::get() mutation
    // and ImageBackend's internal resolve both reach the same real,
    // container-shared instance.
    Kernel::boot(Paths::fromRoot(sys_get_temp_dir()));
    $original = CurrentConfigTestFactory::get()->extImagickDir;
    // Concatenated adjacent to the (memoized, real) command name via
    // escapeshellarg() -- see getExtImagickCommand()'s own [SEC-16]
    // comment -- so this genuinely points `exec()` at a nonexistent path
    // regardless of which real binary this environment already resolved.
    CurrentConfigTestFactory::get()->extImagickDir = '/totally/nonexistent/dir/';

    try {
        expect(ImageBackend::isExtImagick())->toBeFalse();
    } finally {
        CurrentConfigTestFactory::get()->extImagickDir = $original;
        Kernel::reset();
    }
});

test('isExtImagick detects the real, installed magick binary and parses its version', function (): void {
    // Every other isExtImagick()
    // test forces a nonexistent binary directory to hit the false path
    // (both the getExtImagickCommand() 'magick'-vs-'convert' probe and
    // the version-string preg_match were never exercised for real). A
    // real `magick` CLI is genuinely installed in this environment
    // (per `command -v magick`), so this exercises the actual
    // success path end to end, not a mock.
    ImageBackend::$ext_imagick_version = '';

    expect(ImageBackend::isExtImagick())->toBeTrue()
        ->and(ImageBackend::$ext_imagick_version)->toMatch('/^\d+\.\d+\.\d+/');
});

test('getGraphicsLibrary reports a real ImageMagick PHP-extension version when ext_imagick itself is unavailable', function (): void {
    // Same "boot Kernel so this test's own CurrentConfigTestFactory::get()
    // mutation and ImageBackend's internal currentConfig() resolve reach the
    // same real, container-shared instance" reasoning as the
    // isExtImagick() test above.
    Kernel::boot(Paths::fromRoot(sys_get_temp_dir()));
    $original = CurrentConfigTestFactory::get()->extImagickDir;
    CurrentConfigTestFactory::get()->extImagickDir = '/totally/nonexistent/dir/';

    try {
        // isExtImagick() forced false above; the 'imagick' PHP extension
        // (present in this environment) is getLibrary()'s next
        // fallback in its 'auto' chain.
        $library = ImageBackend::getGraphicsLibrary();

        expect($library)
            ->toBeString();
        expect($library)
            ->toStartWith('imagick/');
    } finally {
        CurrentConfigTestFactory::get()->extImagickDir = $original;
        Kernel::reset();
    }
});

test('webpInfo throws when fread() fails after a successful fopen()', function (): void {
    // fopen(<directory>, 'rb') genuinely succeeds on Linux -- it returns a
    // real, valid stream resource -- but any subsequent fread() on that
    // resource genuinely fails (EISDIR). This is real
    // filesystem behavior, not a mock of fopen()/fread() or of webpInfo()
    // itself, and it's the only way to reach webpInfo()'s `$buf === false`
    // branch: every other realistic fread() failure mode (already-closed
    // handle, truncated read) either can't happen with a fresh handle right
    // after a successful fopen(), or simply returns a short (not false)
    // string. fread() emits a real E_WARNING here, which phpunit.xml's
    // failOnWarning="true" would otherwise turn into a failure -- same
    // swallow-for-one-call pattern as this file's other fopen()/
    // getimagesize() warning tests.
    $dir = pwgImageTestMarker();

    set_error_handler(static fn (): bool => true);
    try {
        expect(fn (): WebpInfo => ImageBackend::webpInfo($dir))
            ->toThrow(Exception::class, "webpInfo(): fread({$dir}): Failed");
    } finally {
        restore_error_handler();
    }
});

test('getRotationAngle returns null when exif_read_data() is unavailable, without ever calling it', function (): void {
    $path = pwgImageTestMarker() . '/exif-disabled.jpg';
    // Orientation 6 would normally map to a 270-degree rotation (see the
    // "maps EXIF orientation 6" test above) -- proving that the null this
    // test asserts really comes from the `function_exists('exif_read_data')`
    // guard short-circuiting before exif_read_data() is ever reached, not
    // from the file happening to lack a real orientation tag.
    file_put_contents($path, pwgImageMakeJpegWithOrientation(6));

    $script = 'echo json_encode(['
        . '"exif_available" => function_exists("exif_read_data"), '
        . '"result" => \Piwigo\Admin\Image\ImageBackend::getRotationAngle(' . var_export($path, true) . '),'
        . ']);';
    // php.ini's disable_functions genuinely makes the disabled function
    // both uncallable and, crucially, function_exists()-false -- a real
    // engine-level fact, with no in-process seam to fake it
    // (function_exists('exif_read_data') here checks the real global
    // function table, not anything this class can inject into).
    $proc = pwgImageRunSubprocess(['-d', 'disable_functions=exif_read_data'], $script);

    expect($proc['exit'])->toBe(0, 'subprocess failed: ' . $proc['stderr']);
    $decoded = json_decode($proc['stdout'], true);
    expect($decoded)
        ->toBe([
            'exif_available' => false,
            'result' => null,
        ]);
});

test('isExtImagick returns false when exec() itself is unavailable, without ever calling it', function (): void {
    $script = 'echo json_encode(['
        . '"exec_available" => function_exists("exec"), '
        . '"result" => \Piwigo\Admin\Image\ImageBackend::isExtImagick(),'
        . ']);';
    // Same disable_functions technique as the exif_read_data test above,
    // targeting isExtImagick()'s own `function_exists('exec')` guard.
    $proc = pwgImageRunSubprocess(['-d', 'disable_functions=exec'], $script);

    expect($proc['exit'])->toBe(0, 'subprocess failed: ' . $proc['stderr']);
    $decoded = json_decode($proc['stdout'], true);
    expect($decoded)
        ->toBe([
            'exec_available' => false,
            'result' => false,
        ]);
});

test('getGraphicsLibrary resolves through the gd case and appends a real GD version string', function (): void {
    // Boots Kernel inside the subprocess itself (rather than calling
    // CurrentConfigTestFactory::get(), which shares no state with this
    // isolated process either way) so ImageBackend's own internal
    // currentConfig() resolve reaches this exact mutated instance --
    // same reasoning as the isExtImagick() test above's own doc.
    $script = '\Piwigo\Core\Kernel::boot(\Piwigo\Core\Paths::fromRoot(sys_get_temp_dir()));'
        . '\Piwigo\Core\Kernel::container()->get(\Piwigo\Config\CurrentConfig::class)->extImagickDir = "/totally/nonexistent/dir/";'
        . 'echo json_encode(['
        . '"imagick_extension_loaded" => extension_loaded("imagick"), '
        . '"result" => \Piwigo\Admin\Image\ImageBackend::getGraphicsLibrary(),'
        . ']);';
    // `-n` starts PHP with none of this host's configured extensions
    // loaded, then `-d extension=gd` loads only GD back -- a real,
    // independent PHP engine that genuinely has no imagick extension
    // (unlike the "ext_imagick itself is unavailable" test above, which
    // only rules out the ext_imagick *CLI* and still resolves to the real
    // 'imagick' PHP extension present in this main test process). Setting
    // ext_imagick_dir to a nonexistent path (same technique as the
    // isExtImagick() test above) rules out the ext_imagick CLI too, so
    // getLibrary()'s 'auto' chain falls all the way through to 'gd' --
    // exercising getGraphicsLibrary()'s 'gd' case (gd_info(), the
    // ?? null fallback, the is_string() narrowing, and the version-suffix
    // concatenation) for real.
    $proc = pwgImageRunSubprocess(['-n', '-d', 'extension=gd'], $script);

    expect($proc['exit'])->toBe(0, 'subprocess failed: ' . $proc['stderr']);
    $decoded = json_decode($proc['stdout'], true);
    expect($decoded)
        ->toBeArray();
    assert(is_array($decoded));
    expect($decoded['imagick_extension_loaded'])->toBeFalse();
    expect($decoded['result'])->toBeString();
    expect($decoded['result'])->toStartWith('gd/');
    expect($decoded['result'])->not->toBe('gd/');
});

// Mutation-testing gap closure (G1, when-i-run-the-mutable-cookie.md):
// every test below closes a real, previously-untested branch/output
// found by a fresh scoped `pest --mutate` rerun (94 untested, 2026-08-10
// -- the plan's own Aug 8 count of 93 was already stale given how much
// this file's own coverage had grown in the interim).
//
// Confirmed-equivalent/unreachable mutations from that same rerun, deliberately
// not chased with a new test (category 3/4/5 per the plan's own triage):
// - __construct()'s `(bool) ($this->library = self::getLibrary(...))` cast
//   (line 71) and getGraphicsLibraryLabel()'s own `(string)
//   self::getGraphicsLibrary())` cast (line 599): both live on the
//   already-documented "no image library available" branch at the top of
//   this file -- GD is compiled-in and always available in any PHP process
//   that can run this file, so getLibrary()/getGraphicsLibrary() never
//   actually return `false` here. Category 4.
// - getResizeDimensions()'s whole crop/ratio-math block (RemoveDoubleCast
//   on $img_ratio/$dest_ratio/$ratio_width/$ratio_height and every
//   round()-wrapped destHeight/destWidth/destination_width/
//   destination_height expression): every one of these divisions/casts
//   either (a) only ever feeds a numeric comparison (`>`/`<`), where PHP's
//   loose numeric comparison is int/float-type-agnostic, or (b) is wrapped
//   in round(), which always returns float regardless of its argument's
//   own type. `declare(strict_types=1)` guarantees every width/height/max_*
//   param genuinely is int|float by the time it reaches these casts (no
//   numeric-string coercion in play), so removing a (float) cast here can
//   only ever change PHP's internal int-vs-float bookkeeping mid-expression,
//   never the final observable value. Category 5.
// - getResizeDimensions()'s `$dest_ratio > $img_ratio` / `$dest_ratio <
//   $img_ratio` boundary (`>`/`<` vs `>=`/`<=`): already proven inert by
//   the "applies neither crop offset when dest_ratio exactly equals
//   img_ratio" test's own docblock above (a mathematical identity makes
//   the "wrongly taken" branch reassign height/width to their own current
//   value at the exact tie) -- that reasoning covers both the `>` and `<`
//   comparisons symmetrically, not just one. Category 5.
// - getResizeDimensions()'s `(bool) $x or (bool) $y` (line 278) and
//   webpInfo()'s `! (bool) $fp` (line 295): both already-boolean-context
//   positions (`or`/`!` both coerce their operand to bool regardless of an
//   explicit cast) -- the same established "already-coercing position"
//   pattern as this codebase's other confirmed-inert cast removals.
//   Category 5.
// - webpInfo()'s `fread($fp, 25)` byte count (line 298): nothing in this
//   method ever reads index 25 or later (every check is `strlen() < 25` or
//   a fixed index <= 24), so requesting one extra byte is unobservable
//   regardless of source length. Category 5.
// - webpInfo()'s `fclose($fp);` (line 299): a resource-cleanup call with
//   no return value read and no cross-test observable effect within a
//   single process -- same shape as AdminUiHelper.php's own
//   already-confirmed "resource-cleanup call" skip from an earlier phase
//   of this same campaign. Category 3.
// - getRotationAngle()'s `$size === false` check (line 331) and its
//   early `return null;` (line 339): the sibling `[$width, $height, $type]
//   = $size;` destructuring on a genuinely-false $size emits a PHP warning
//   and yields $type=null, which the very next `$type !== IMAGETYPE_JPEG`
//   check also maps to `return null;` -- the SAME final return value
//   either way. The one existing test exercising this path
//   (getimagesize() failing) wraps the whole call in a warning-swallowing
//   set_error_handler(), so even the intermediate warning is invisible.
//   Both mutations are provably unkillable by any test using this
//   swallow-and-observe-final-return shape. Category 5.
// - getRotationAngle()'s `$matches[1]` (line 359, the EXIF-orientation
//   regex capture): `/^\s*(\d)/`'s $matches[0] (full match) and $matches[1]
//   (captured digit) are identical strings unless the source has leading
//   whitespace before the digit -- exif_read_data() returns a real,
//   unpadded PHP int for a SHORT-typed
//   Orientation tag (e.g. int(6), never " 6"), so this file's own
//   hand-crafted EXIF fixtures can never produce a leading-whitespace
//   value to distinguish the two. Category 5.
// - isImagick()'s `extension_loaded('imagick') and class_exists('Imagick')`
//   (line 448, `and` vs `or`): both operands are real facts about the SAME
//   running PHP process for the SAME extension name -- they can't
//   disagree (this environment's own imagick tests elsewhere in
//   this file rely on both being true together), so `and`/`or` always
//   agree here. Category 5.
// - getSharpenMatrix()'s `(float) $amount` cast (line 410, the
//   RemoveDoubleCast survivor after the round()-precision test above):
//   $amount is `int|float`-typed under strict_types, and `*` between an
//   int and a float already produces a float with the identical numeric
//   value in PHP -- same already-coercing-arithmetic reasoning as the
//   getResizeDimensions() cluster above. Category 5.
// - getResizeResult()'s `$destination_filesize !== false ? ... : 0`
//   fallback's own `false`/`0` literals (line 434, the FalseToTrue/
//   IncrementInteger survivors after the "0 KB fallback" test above):
//   both mutations were checked directly against that same test and
//   converge to the identical "0 KB" output -- `false` coerces to int 0 in
//   arithmetic (line 440's `floor($destination_filesize / 1024)`), and
//   floor(0/1024) and floor(1/1024) are both 0. The "X KB" display can
//   never distinguish any fallback value under 1024 from any other.
//   Category 5.
// - getResizeResult()'s `(string) floor(...)` cast (line 440,
//   RemoveStringCast): the `.` concatenation operator immediately after
//   it already stringifies its left operand -- same already-coercing
//   pattern as the destroy()/isExtImagick() casts above. Category 5.
// - getResizeResult()'s `(bool) $time` cast (line 441, RemoveBooleanCast):
//   another already-coercing ternary-condition position, same as
//   getResizeDimensions()'s crop check. Category 5.
// - getResizeResult()'s `* 1000.0` scale factor (line 441,
//   DecrementFloat/IncrementFloat -> 999.0/1001.0, a 0.1% precision
//   change): ResizeResult::$time is a purely human-facing "X.XX ms"
//   performance indicator (per a full grep of every real
//   caller -- nothing downstream parses or branches on it), and
//   distinguishing a 0.1% scale error from ordinary wall-clock
//   measurement jitter is not realistically achievable without
//   controlling TimingHelper::getMoment() itself (a real microtime(true)
//   call, not injectable). Category 3.
// - getGraphicsLibrary()'s own `(bool) preg_match(...)` cast in the
//   'imagick' PHP-extension case (line 581): another already-coercing
//   if-condition position, same pattern as several others above --
//   already exercised (not just theoretically) by the existing "reports a
//   real ImageMagick PHP-extension version" test. Category 5.
//
// A NEW, project-wide finding, NOT a gap in this file's own tests: the
// exec()-command-string-construction mutations for
// getExtImagickCommand() (lines 489-490), isExtImagick()'s own exec()
// call (lines 508-510), getGraphicsLibrary()'s own ext_imagick exec()
// call (lines 573-574), and getGraphicsLibraryLabel()'s 'gd' array
// entry (line 604) all still show as UNTESTED after adding real,
// purpose-built subprocess tests above that DO exercise them -- per
// hand-mutating each line, running ONLY the new
// subprocess test against the mutant (it genuinely fails),
// then reverting. `pest --mutate --covered-only`'s own mutant/test
// selection is driven by a plain in-process coverage run, which has no
// visibility into code executed inside a `proc_open()`-spawned child
// process (the same subprocess technique this file's own pre-existing
// exif_read_data()/exec()-disabled tests already use) -- so it never even
// attempts to run a subprocess-based test against a mutant on a
// subprocess-only-covered line, regardless of whether that test would
// really catch it. This is a genuine, permanent blind spot in this
// project's mutation-testing tooling, not a real coverage gap: these
// lines will keep showing UNTESTED in every future `pest --mutate` run of
// this file too. Same root cause and consequence as
// tests/Unit/Core/ErrorCollectorTest.php's own documented subprocess-crash
// blind spot (there for genuine PHP fatals; here for a real, non-crashing
// custom-environment subprocess instead) -- not code-quality gaps, don't
// re-chase either.

test('getSharpenMatrix rounds to exactly 2 decimal places, not 1 or 3', function (): void {
    // round(..., 2)'s own literal
    // `2` -- the sibling "computes exact, real weight values" test above
    // uses amount=50, whose pre-round value (29.0) happens to already be
    // a whole number, so round() to 1/2/3 decimals all coincide there.
    // amount=33.333 is chosen so round(...,1)=35.3, round(...,2)=35.33,
    // round(...,3)=35.333 all genuinely differ, per
    // the real function (not hand-derived).
    $matrix = ImageBackend::getSharpenMatrix(33.333);

    expect($matrix[1][1])->toBe(1.2927186242224662);
    expect($matrix[0][0])->toBe(-0.036589828027808274);
});

test('getResizeResult falls back to "0 KB" when the destination file is genuinely unreadable', function (): void {
    // The `$destination_filesize
    // !== false ? $destination_filesize : 0` fallback (getResizeResult(),
    // itself private, only reachable through pwgResize()) was never
    // exercised by any existing test -- every prior pwgResize test writes
    // a real destination file first. This spy's own write() never creates
    // the file at all, so filesize() genuinely fails.
    $dest = pwgImageTestMarker() . '/missing-dest.jpg';
    [$img, $spy] = pwgImageTestMakeFileControlSpy(pwgImageTestMarker() . '/missing-source.jpg', 200, 100, null);

    // filesize() on a missing path emits a real PHP warning -- same
    // swallow-for-one-call pattern as this file's other fopen()/
    // getimagesize() warning tests.
    set_error_handler(static fn (): bool => true);
    try {
        $result = $img->pwgResize($dest, 100, 50, 77, automatic_rotation: false);
    } finally {
        restore_error_handler();
    }

    expect($spy->calls)
        ->toContain('write');
    expect($result->size)
        ->toBe('0 KB');
});

test('getResizeResult computes the exact "X KB" size, floor not round/ceil', function (): void {
    // FloorToRound/FloorToCeil on
    // floor($destination_filesize / 1024) -- 1536 bytes -> 1.5 exactly,
    // where floor()=1 genuinely differs from round()=2 and ceil()=2,
    // per the real function.
    $dest = pwgImageTestMarker() . '/sized-dest.jpg';
    [$img] = pwgImageTestMakeFileControlSpy(pwgImageTestMarker() . '/sized-source.jpg', 200, 100, 1536);

    $result = $img->pwgResize($dest, 100, 50, 77, automatic_rotation: false);

    expect($result->size)
        ->toBe('1 KB');
});

test('getResizeResult divides the destination size by exactly 1024, not 1023 or 1025', function (): void {
    // DecrementInteger/IncrementInteger
    // on the literal 1024 -- 524799 bytes is chosen so floor(524799/1024)=512
    // genuinely differs from floor(524799/1023)=513 and floor(524799/1025)=511,
    // per the real function (small byte counts near
    // this magnitude mostly coincide across all three divisors).
    $dest = pwgImageTestMarker() . '/precise-dest.jpg';
    [$img] = pwgImageTestMakeFileControlSpy(pwgImageTestMarker() . '/precise-source.jpg', 200, 100, 524799);

    $result = $img->pwgResize($dest, 100, 50, 77, automatic_rotation: false);

    expect($result->size)
        ->toBe('512 KB');
});

test('getResizeResult reports a real, accurately-scaled elapsed-time measurement', function (): void {
    // No existing pwgResize test
    // checks $result->time against a real, independently-verifiable
    // duration -- only that it's a non-empty ' ms'-suffixed string,
    // string-typed. A deliberately slow write() (usleep) forces a real,
    // known-minimum elapsed duration, so MultiplicationToDivision
    // (reporting ~1000x too small) and MinusToPlus (reporting a value on
    // the order of the current unix timestamp, billions of ms) both fail
    // a tight bound a merely-imprecise measurement never would. Also
    // proves the exact ' ms' suffix (ConcatRemoveLeft) and the 2-decimal
    // format (DecrementInteger/IncrementInteger on number_format()'s own
    // literal 2) via the regex itself.
    $slowImage = new readonly class(200, 100) implements ImageInterface {
        public function __construct(
            private int|float $width,
            private int|float $height
        ) {}

        #[\Override]
        public function getWidth(): int|float
        {
            return $this->width;
        }

        #[\Override]
        public function getHeight(): int|float
        {
            return $this->height;
        }

        #[\Override]
        public function setCompressionQuality(int $quality): bool
        {
            return true;
        }

        #[\Override]
        public function crop(int|float $width, int|float $height, int|float $x, int|float $y): bool
        {
            return true;
        }

        #[\Override]
        public function strip(): bool
        {
            return true;
        }

        #[\Override]
        public function rotate(int|float $rotation): bool
        {
            return true;
        }

        #[\Override]
        public function resize(int|float $width, int|float $height): bool
        {
            return true;
        }

        #[\Override]
        public function sharpen(int|float $amount): bool
        {
            return true;
        }

        #[\Override]
        public function compose(ImageBackend $overlay, int|float $x, int|float $y, int|float $opacity): bool
        {
            return true;
        }

        #[\Override]
        public function write(string $destination_filepath): bool
        {
            usleep(40000);
            touch($destination_filepath);
            return true;
        }
    };
    $img = pwgImageTestMake(pwgImageTestMarker() . '/slow-source.jpg', image: $slowImage);
    $dest = pwgImageTestMarker() . '/slow-dest.jpg';

    $result = $img->pwgResize($dest, 100, 50, 77, automatic_rotation: false);

    expect($result->time)
        ->toMatch('/^\d+\.\d{2} ms$/');
    $reportedMs = (float) $result->time;
    expect($reportedMs)
        ->toBeGreaterThanOrEqual(35.0)
        ->and($reportedMs)
        ->toBeLessThan(5000.0);
});

test('currentConfig throws LogicException when the container returns an unexpected type', function (): void {
    // Kills the private currentConfig() resolver's own InstanceOfToTrue
    // mutation (`if (!true)`, never taking the throw branch) -- same
    // established KernelContainerOverride::withWrongTypeFor() pattern as
    // CurrentTemplateTest.php's own identical guard-shape test.
    // getLibrary() is the entry point since currentConfig() is private
    // -- it calls self::currentConfig()->graphicsLibrary() unconditionally
    // as its very first real statement.
    Kernel::boot(Paths::fromRoot(sys_get_temp_dir()));

    try {
        KernelContainerOverride::withWrongTypeFor(
            CurrentConfig::class,
            static fn (): string|false => ImageBackend::getLibrary(),
        );
    } finally {
        Kernel::reset();
    }
})->throws(LogicException::class, 'Container returned an unexpected type for ' . CurrentConfig::class);

test('getLibrary lowercases the requested library name before matching', function (): void {
    // switch(strtolower($library))
    // -- every existing getLibrary() test already passes an
    // already-lowercase library name, so UnwrapStrtolower (comparing the
    // raw, unlowercased value instead) was never observed to matter.
    expect(ImageBackend::getLibrary('GD', 'jpg'))->toBe('gd');
});

test('getLibrary never selects imagick for a gif extension, even when imagick is available', function (): void {
    // `$extension !== 'gif' and
    // self::isImagick()` becoming `or` would wrongly still pick imagick
    // for a gif file whenever this real environment's own imagick probe
    // is true -- every existing getLibrary() test uses a 'jpg' extension,
    // so this exclusion was never actually exercised. Requesting 'imagick'
    // directly (not 'auto') isolates this to the imagick-specific guard:
    // falling through it lands on the 'gd' case, which this environment's
    // own isGd() always confirms available.
    expect(ImageBackend::getLibrary('imagick', 'gif'))->toBe('gd');
});

test('getGraphicsLibraryLabel formats the imagick-extension library and version when ext_imagick is unavailable', function (): void {
    // RemoveArrayItem on the
    // 'imagick' => 'ImageMagick' entry in $label_for_lib -- every existing
    // getGraphicsLibraryLabel() test only exercises this real
    // environment's own default 'auto' pick (ext_imagick, a real magick
    // CLI is installed here). Same "force ext_imagick CLI unavailable"
    // technique as getGraphicsLibrary()'s own sibling test.
    Kernel::boot(Paths::fromRoot(sys_get_temp_dir()));
    $original = CurrentConfigTestFactory::get()->extImagickDir;
    CurrentConfigTestFactory::get()->extImagickDir = '/totally/nonexistent/dir/';

    try {
        $label = ImageBackend::getGraphicsLibraryLabel();

        // getGraphicsLibrary()'s own 'imagick' case captures the WHOLE
        // "ImageMagick X.X.X" phrase from Imagick::getVersion() (not just
        // the version number) into $match[0] -- a real, pre-existing
        // quirk of this codebase, not something this test is asserting
        // as newly-introduced behavior.
        expect($label)
            ->toStartWith('ImageMagick ')
            ->and($label)
            ->toMatch('/^ImageMagick ImageMagick \d+\.\d+\.\d+/');
    } finally {
        CurrentConfigTestFactory::get()->extImagickDir = $original;
        Kernel::reset();
    }
});

test('getGraphicsLibraryLabel formats the gd library and version when no imagick backend is available', function (): void {
    // RemoveArrayItem on the
    // 'gd' => 'GD' entry -- mirrors getGraphicsLibrary()'s own "gd case"
    // subprocess test, calling getGraphicsLibraryLabel() instead to
    // prove the label array's own 'gd' entry is real, not just non-empty.
    $script = '\Piwigo\Core\Kernel::boot(\Piwigo\Core\Paths::fromRoot(sys_get_temp_dir()));'
        . '\Piwigo\Core\Kernel::container()->get(\Piwigo\Config\CurrentConfig::class)->extImagickDir = "/totally/nonexistent/dir/";'
        . 'echo \Piwigo\Admin\Image\ImageBackend::getGraphicsLibraryLabel();';
    $proc = pwgImageRunSubprocess(['-n', '-d', 'extension=gd'], $script);

    expect($proc['exit'])->toBe(0, 'subprocess failed: ' . $proc['stderr']);
    expect($proc['stdout'])->toStartWith('GD ');
});

test('getExtImagickCommand/isExtImagick/getGraphicsLibrary correctly locate and parse a real custom-path magick binary', function (): void {
    // The concatenation pieces
    // building each real exec() command string (getExtImagickCommand()'s
    // 'command -v '.escapeshellarg($dir).'magick', isExtImagick()'s
    // escapeshellarg($dir).command.' -version < /dev/null', and
    // getGraphicsLibrary()'s own sibling call) were never proven correct
    // by any existing test -- every prior test either points extImagickDir
    // at a nonexistent path (proving only the "not found" branch) or
    // relies on this environment's own real, default-empty-dir magick
    // install (which happens to still resolve correctly regardless of
    // concatenation order, since escapeshellarg('') is a no-op either side
    // of 'magick'). A real, custom, non-empty binary directory containing
    // a fake 'magick' script is the only way to prove the command string
    // is assembled from the right pieces in the right order.
    // getExtImagickCommand()'s own `static $command` memoization means
    // this must run in a fresh subprocess, not this shared test process.
    $dir = sys_get_temp_dir() . '/pwgimage-fake-magick-' . bin2hex(random_bytes(8));
    mkdir($dir, 0o777, true);
    $fakeMagick = $dir . '/magick';
    $scriptContent = <<<'SH'
        #!/bin/sh
        if [ "$1" = "-version" ]; then
            echo 'Version: ImageMagick 7.1.0-49 Q16-HDRI x86_64 00000'
        fi
        exit 0
        SH;
    file_put_contents($fakeMagick, $scriptContent);
    chmod($fakeMagick, 0o755);

    try {
        $script = '\Piwigo\Core\Kernel::boot(\Piwigo\Core\Paths::fromRoot(sys_get_temp_dir()));'
            . '\Piwigo\Core\Kernel::container()->get(\Piwigo\Config\CurrentConfig::class)->extImagickDir = ' . var_export($dir . '/', true) . ';'
            . 'echo json_encode(['
            . '"command" => \Piwigo\Admin\Image\ImageBackend::getExtImagickCommand(),'
            . '"is_ext" => \Piwigo\Admin\Image\ImageBackend::isExtImagick(),'
            . '"version" => \Piwigo\Admin\Image\ImageBackend::$ext_imagick_version,'
            . '"library" => \Piwigo\Admin\Image\ImageBackend::getGraphicsLibrary(),'
            . ']);';
        $proc = pwgImageRunSubprocess([], $script);

        expect($proc['exit'])->toBe(0, 'subprocess failed: ' . $proc['stderr']);
        $decoded = json_decode($proc['stdout'], true);
        expect($decoded)
            ->toBe([
                'command' => 'magick',
                'is_ext' => true,
                'version' => '7.1.0-49',
                'library' => 'ext_imagick/7.1.0-49',
            ]);
    } finally {
        unlink($fakeMagick);
        rmdir($dir);
    }
});

test('destroy falls back to true when the underlying image has no destroy method at all', function (): void {
    // IfNegated on
    // method_exists($image, 'destroy') -- the only existing destroy()
    // test uses a GD-backed image (which DOES implement destroy()), never
    // an image without one (e.g. a plugin-provided backend, per this
    // method's own docblock).
    $fake = new class() implements ImageInterface {
        #[\Override]
        public function getWidth(): int
        {
            return 1;
        }

        #[\Override]
        public function getHeight(): int
        {
            return 1;
        }

        #[\Override]
        public function setCompressionQuality(int $quality): bool
        {
            return true;
        }

        #[\Override]
        public function crop(int|float $width, int|float $height, int|float $x, int|float $y): bool
        {
            return true;
        }

        #[\Override]
        public function strip(): bool
        {
            return true;
        }

        #[\Override]
        public function rotate(int|float $rotation): bool
        {
            return true;
        }

        #[\Override]
        public function resize(int|float $width, int|float $height): bool
        {
            return true;
        }

        #[\Override]
        public function sharpen(int|float $amount): bool
        {
            return true;
        }

        #[\Override]
        public function compose(ImageBackend $overlay, int|float $x, int|float $y, int|float $opacity): bool
        {
            return true;
        }

        #[\Override]
        public function write(string $destination_filepath): bool
        {
            return true;
        }
    };
    $img = pwgImageTestMake(pwgImageTestMarker() . '/no-destroy.whatever-unsupported-ext', image: $fake);

    expect($img->destroy())
        ->toBeTrue();
});

test('destroy coerces and genuinely forwards the underlying image\'s own destroy() return value', function (): void {
    // RemoveBooleanCast and
    // RemoveEarlyReturn on `return (bool) $image->destroy();` -- the only
    // existing destroy() test uses ImageGd::destroy(), which always
    // returns bool(true) unconditionally, so neither the cast nor the
    // early return can be distinguished from ImageBackend::destroy()'s own
    // unconditional `return true;` fallback by that test alone. A fake
    // destroy() returning a non-bool falsy value (int 0, not declared by
    // ImageInterface at all, called dynamically) proves both: without the
    // cast, returning a raw int from a `: bool`-declared method under
    // strict_types is a real TypeError; without the early return, this
    // method falls through to its own unconditional `return true;`
    // regardless of what the real destroy() returned.
    $fake = new class() implements ImageInterface {
        #[\Override]
        public function getWidth(): int
        {
            return 1;
        }

        #[\Override]
        public function getHeight(): int
        {
            return 1;
        }

        #[\Override]
        public function setCompressionQuality(int $quality): bool
        {
            return true;
        }

        #[\Override]
        public function crop(int|float $width, int|float $height, int|float $x, int|float $y): bool
        {
            return true;
        }

        #[\Override]
        public function strip(): bool
        {
            return true;
        }

        #[\Override]
        public function rotate(int|float $rotation): bool
        {
            return true;
        }

        #[\Override]
        public function resize(int|float $width, int|float $height): bool
        {
            return true;
        }

        #[\Override]
        public function sharpen(int|float $amount): bool
        {
            return true;
        }

        #[\Override]
        public function compose(ImageBackend $overlay, int|float $x, int|float $y, int|float $opacity): bool
        {
            return true;
        }

        #[\Override]
        public function write(string $destination_filepath): bool
        {
            return true;
        }

        public function destroy(): int
        {
            return 0;
        }
    };
    $img = pwgImageTestMake(pwgImageTestMarker() . '/coerce-destroy.whatever-unsupported-ext', image: $fake);

    expect($img->destroy())
        ->toBeFalse();
});
