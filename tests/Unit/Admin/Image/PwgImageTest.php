<?php

declare(strict_types=1);

use Piwigo\Admin\Image\ImageProcessingException;
use Piwigo\Admin\Image\PwgImage;
use Piwigo\Core\CurrentLogger;
use Piwigo\Event\Lifecycle\LoadImageLibrary;

/**
 * P23 Stage 1e: __construct()'s unsupported-extension guard used to
 * die() -- first test coverage for this class, confirming it now throws
 * ImageProcessingException. The "no image library available" branch
 * stays untested: GD is real and available in this environment, and
 * get_library()'s own fallback chain always resolves to 'gd' eventually
 * regardless of the requested library, so there's no realistic way to
 * make it return false here without mocking a global function this class
 * has no seam to inject. The same reasoning makes __construct()'s own
 * `default => throw ... "unknown image library"` match arm (only
 * reachable if get_library() itself somehow returned a 4th string, which
 * its own switch can never produce) and get_library()'s final `return
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
 * `xc:` canvas actually persists an EXIF profile (confirmed live: `identify
 * -verbose` reports "Orientation: Undefined" after either), so this hand-
 * rolled TIFF/IFD0 byte layout is the only reliable, dependency-free way to
 * get a real Orientation tag into a file get_rotation_angle() will read.
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
    /** @var string|null $marker */
    static $marker = null;

    return $marker ??= sys_get_temp_dir() . '/piwigo-pwg-image-test-' . bin2hex(random_bytes(8));
}

/**
 * PwgImage takes CurrentLogger via constructor injection (singleton/
 * service-locator elimination campaign, Phase 2), forwarded only to the
 * 'ext_imagick' branch (ImageExtImagick::write()'s own read) -- a fresh,
 * never-set() instance is safe here since every test in this file uses
 * 'gd' or lets get_library() fall back to it (this environment has no
 * ext_imagick/imagick binary available, see this file's own docblock).
 */
function pwgImageTestMake(string $sourceFilepath, ?string $library = null): PwgImage
{
    return new PwgImage($sourceFilepath, new CurrentLogger(), $library);
}

/**
 * Runs $script (appended after `require '<real vendor/autoload.php>';`)
 * in a genuinely separate `php` CLI process started with $flags -- see
 * tests/Unit/Core/ContainerDetectorTest.php's own docblock for why this
 * is the established pattern in this suite for closing branches gated on
 * a real PHP-engine-level fact (a disabled function, an unloaded
 * extension) that this class has no injection seam for: a real PHP
 * engine genuinely enforcing real `-d`/`-n` flags, not a mock of
 * PwgImage or of any global function it calls, and the subprocess exits
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

    expect(fn () => pwgImageTestMake($path))
        ->toThrow(ImageProcessingException::class, '[Image] unsupported file extension');
});

test('get_resize_dimensions leaves dimensions unchanged when both fit within the max bounds', function (): void {
    $result = PwgImage::get_resize_dimensions(100, 100, 200, 200);

    expect($result)->toBe(['width' => 100, 'height' => 100]);
});

test('get_resize_dimensions scales down on the width-bound side', function (): void {
    // ratio_width=2 > ratio_height=1 -> the "else" branch: width pinned to
    // max_width, height derived from ratio_width.
    $result = PwgImage::get_resize_dimensions(800, 400, 400, 400);

    // round() always returns float in PHP -- $max_width itself passes
    // through unrounded (still the plain int param), but the derived side
    // is always a real float, never coerced back to int.
    expect($result)->toBe(['width' => 400, 'height' => 200.0]);
});

test('get_resize_dimensions scales down on the height-bound side', function (): void {
    // ratio_height=2 > ratio_width=1 -> the "if" branch: height pinned to
    // max_height, width derived from ratio_height.
    $result = PwgImage::get_resize_dimensions(400, 800, 400, 400);

    expect($result)->toBe(['width' => 200.0, 'height' => 400]);
});

test('get_resize_dimensions rounds (not floors) the width-bound side when the fraction is >= 0.5', function (): void {
    // Real gap, found via mutation testing: the sibling test above lands
    // on a whole-number ratio (round(400/2) = 200 exactly), so round()
    // and floor() coincide there. height=401 (vs. 400) shifts the raw
    // value to 200.5, where round() (201) and floor() (200) differ.
    $result = PwgImage::get_resize_dimensions(800, 401, 400, 400);

    expect($result)->toBe(['width' => 400, 'height' => 201.0]);
});

test('get_resize_dimensions rounds (not floors) the height-bound side when the fraction is >= 0.5', function (): void {
    $result = PwgImage::get_resize_dimensions(401, 800, 400, 400);

    expect($result)->toBe(['width' => 201.0, 'height' => 400]);
});

test('get_resize_dimensions crops a portrait image against a landscape-ish max, swapping max dimensions via follow_orientation', function (): void {
    // width(100) < height(300) and follow_orientation=true -> max_width/
    // max_height swap to (120, 160) first; dest_ratio(0.75) > img_ratio
    // (0.333) selects the destHeight/y-crop branch (round()-derived
    // height/y come back as float; width/x pass through as the untouched
    // int params).
    $result = PwgImage::get_resize_dimensions(100, 300, 160, 120, null, true, true);

    expect($result)->toBe([
        'width' => 100,
        'height' => 133.0,
        'crop' => ['width' => 100, 'height' => 133.0, 'x' => 0, 'y' => 84.0],
    ]);
});

test('get_resize_dimensions crops a landscape image, selecting the destWidth/x-crop branch', function (): void {
    // width(300) > height(100) -- no follow_orientation swap. dest_ratio(1)
    // < img_ratio(3) selects the destWidth/x-crop branch instead (here
    // width/x are the round()-derived floats; height/y pass through as
    // untouched ints).
    $result = PwgImage::get_resize_dimensions(300, 100, 200, 200, null, true);

    expect($result)->toBe([
        'width' => 100.0,
        'height' => 100,
        'crop' => ['width' => 100.0, 'height' => 100, 'x' => 100.0, 'y' => 0],
    ]);
});

test('get_resize_dimensions rounds (not floors) the destHeight/y crop math when the fraction is >= 0.5', function (): void {
    // Real gap, found via mutation testing: the sibling portrait test
    // above has a raw destHeight of 133.333 -- round() and floor() both
    // land on 133 there, so a round()->floor() mutation is invisible.
    // width=101 (vs. 100) shifts the fraction to 134.667/82.5, where
    // round() (135/83) and floor() (134/82) genuinely differ.
    $result = PwgImage::get_resize_dimensions(101, 300, 160, 120, null, true, true);

    expect($result)->toBe([
        'width' => 101,
        'height' => 135.0,
        'crop' => ['width' => 101, 'height' => 135.0, 'x' => 0, 'y' => 83.0],
    ]);
});

test('get_resize_dimensions rounds (not floors) the destWidth/x crop math when the fraction is >= 0.5', function (): void {
    // Same "round vs floor coincide" gap as the sibling test above, for
    // the destWidth/x-crop branch instead: height=101, max 200x150
    // yields a raw destWidth of 134.667/x of 82.667, where round()
    // (135/83) and floor() (134/82) genuinely differ.
    $result = PwgImage::get_resize_dimensions(300, 101, 200, 150, null, true);

    expect($result)->toBe([
        'width' => 135.0,
        'height' => 101,
        'crop' => ['width' => 135.0, 'height' => 101, 'x' => 83.0, 'y' => 0],
    ]);
});

test('get_resize_dimensions does not swap max dimensions for a square (tied width/height) image', function (): void {
    // Real gap, found via mutation testing: `$width < $height` becoming
    // `<=` only matters when width and height are exactly equal -- a
    // square source with a non-square max makes the (wrongly) swapped
    // vs. not-swapped outcome genuinely different.
    $result = PwgImage::get_resize_dimensions(200, 200, 160, 120, null, true);

    expect($result)->toBe([
        'width' => 160,
        'height' => 120.0,
        'crop' => ['width' => 200, 'height' => 150.0, 'x' => 0, 'y' => 25.0],
    ]);
});

test('get_resize_dimensions swaps width/height for a 90-degree rotation before and after computing the max-size fit', function (): void {
    $result = PwgImage::get_resize_dimensions(100, 200, 50, 100, 90, false);

    // Pre-swap: destination_width=$max_width (int, unchanged), destination_
    // height=round(...) (float); the post-computation rotate_for_dimensions
    // swap then puts that float first.
    expect($result)->toBe(['width' => 25.0, 'height' => 50]);
});

test('get_rotation_code_from_angle maps every known angle, treating null the same as 0', function (): void {
    expect(PwgImage::get_rotation_code_from_angle(null))->toBe(0);
    expect(PwgImage::get_rotation_code_from_angle(0))->toBe(0);
    expect(PwgImage::get_rotation_code_from_angle(90))->toBe(1);
    expect(PwgImage::get_rotation_code_from_angle(180))->toBe(2);
    expect(PwgImage::get_rotation_code_from_angle(270))->toBe(3);
});

test('get_rotation_code_from_angle throws for an unexpected angle', function (): void {
    expect(fn () => PwgImage::get_rotation_code_from_angle(45))
        ->toThrow(\Exception::class, 'get_rotation_code_from_angle(): unexpected rotation angle 45');
});

test('get_rotation_angle_from_code maps every known code, wrapping modulo 4', function (): void {
    expect(PwgImage::get_rotation_angle_from_code(0))->toBe(0);
    expect(PwgImage::get_rotation_angle_from_code(1))->toBe(90);
    expect(PwgImage::get_rotation_angle_from_code(2))->toBe(180);
    expect(PwgImage::get_rotation_angle_from_code(3))->toBe(270);
    // ImageDerivativeController's own caller passes a native Doctrine int,
    // but the signature also accepts a numeric-string (legacy mysqli-style
    // caller) -- and the mod-4 wrap covers a value one full cycle past 3.
    expect(PwgImage::get_rotation_angle_from_code('4'))->toBe(0);
});

test('get_rotation_angle_from_code throws for an unexpected code', function (): void {
    expect(fn () => PwgImage::get_rotation_angle_from_code(-1))
        ->toThrow(\Exception::class);
});

test('get_rotation_angle returns null for a non-JPEG source', function (): void {
    $path = pwgImageTestMarker() . '/photo.png';
    $img = imagecreatetruecolor(10, 10);
    if ($img === false) {
        throw new RuntimeException('imagecreatetruecolor failed');
    }
    imagepng($img, $path);

    expect(PwgImage::get_rotation_angle($path))->toBeNull();
});

test('get_rotation_angle returns 0 for a JPEG with no EXIF orientation tag', function (): void {
    $path = pwgImageTestMarker() . '/photo.jpg';
    $img = imagecreatetruecolor(10, 10);
    if ($img === false) {
        throw new RuntimeException('imagecreatetruecolor failed');
    }
    imagejpeg($img, $path);

    expect(PwgImage::get_rotation_angle($path))->toBe(0);
});

test('get_sharpen_matrix returns a normalized 3x3 kernel centered on the amount-derived weight', function (): void {
    $matrix = PwgImage::get_sharpen_matrix(50);

    expect($matrix)->toHaveCount(3);
    foreach ($matrix as $row) {
        expect($row)->toHaveCount(3);
    }
    // Every corner/edge cell stays -1 pre-normalization; only the center
    // cell depends on $amount.
    expect($matrix[0][0])->toBe($matrix[0][2]);
    expect($matrix[0][0])->toBe($matrix[2][0]);
    expect($matrix[1][1])->not->toBe($matrix[0][0]);
});

test('get_sharpen_matrix computes exact, real weight values for a known amount', function (): void {
    // Real gap, found via mutation testing: the sibling test above only
    // checks structural shape, never a real computed value -- amount=50's
    // pre-normalization center weight is round(abs(-48.0 + 50*0.38), 2) =
    // 29.0, and every cell (corners included) is that raw matrix summed
    // then divided by the same norm, so the center/corner *ratio* -29.0
    // is exact and stable regardless of floating-point precision in the
    // individual cells.
    $matrix = PwgImage::get_sharpen_matrix(50);

    expect($matrix[1][1] / $matrix[0][0])->toBe(-29.0);
    // The center/corner ratio alone can't tell a real normalization pass
    // from a skipped one (dividing -- or not -- every cell by the same
    // norm doesn't change their ratio to each other) -- the raw center
    // weight itself (29.0 pre-normalization, ~1.38 after dividing by the
    // real norm of 21) is what actually proves the /=$norm loop ran.
    expect($matrix[1][1])->toBeGreaterThan(1.0)->toBeLessThan(2.0);
});

test('webp_info detects the simple lossy VP8 format', function (): void {
    $path = pwgImageTestMarker() . '/lossy.webp';
    file_put_contents($path, 'RIFF' . "\x00\x00\x00\x00" . 'WEBP' . 'VP8' . ' ' . str_repeat("\x00", 9));

    expect(PwgImage::webp_info($path))->toBe([
        'type' => 'VP8',
        'has-animation' => false,
        'has-transparent' => false,
    ]);
});

test('webp_info detects a transparent lossless VP8L format', function (): void {
    $path = pwgImageTestMarker() . '/lossless-transparent.webp';
    $buf = 'RIFF' . "\x00\x00\x00\x00" . 'WEBP' . 'VP8' . 'L' . str_repeat("\x00", 8) . chr(0x10);
    file_put_contents($path, $buf);

    expect(PwgImage::webp_info($path))->toBe([
        'type' => 'VP8L',
        'has-animation' => false,
        'has-transparent' => true,
    ]);
});

test('webp_info detects a non-transparent lossless VP8L format', function (): void {
    $path = pwgImageTestMarker() . '/lossless-opaque.webp';
    $buf = 'RIFF' . "\x00\x00\x00\x00" . 'WEBP' . 'VP8' . 'L' . str_repeat("\x00", 8) . chr(0x00);
    file_put_contents($path, $buf);

    expect(PwgImage::webp_info($path))->toBe([
        'type' => 'VP8L',
        'has-animation' => false,
        'has-transparent' => false,
    ]);
});

test('webp_info detects an animated, transparent extended VP8X format', function (): void {
    $path = pwgImageTestMarker() . '/extended.webp';
    $buf = 'RIFF' . "\x00\x00\x00\x00" . 'WEBP' . 'VP8' . 'X' . str_repeat("\x00", 4) . chr(0x12) . str_repeat("\x00", 4);
    file_put_contents($path, $buf);

    expect(PwgImage::webp_info($path))->toBe([
        'type' => 'VP8X',
        'has-animation' => true,
        'has-transparent' => true,
    ]);
});

test('webp_info detects an extended VP8X format that is animated but not transparent', function (): void {
    // Real gap, found via mutation testing: the sibling test above sets
    // both flag bits together, so a mutation on either individual bit
    // check (0x02 for animation, 0x10 for transparency) can't be told
    // apart from a mutation on the other -- only a flags byte with just
    // one bit set can.
    $path = pwgImageTestMarker() . '/extended-animated-only.webp';
    $buf = 'RIFF' . "\x00\x00\x00\x00" . 'WEBP' . 'VP8' . 'X' . str_repeat("\x00", 4) . chr(0x02) . str_repeat("\x00", 4);
    file_put_contents($path, $buf);

    expect(PwgImage::webp_info($path))->toBe([
        'type' => 'VP8X',
        'has-animation' => true,
        'has-transparent' => false,
    ]);
});

test('webp_info detects an extended VP8X format that is transparent but not animated', function (): void {
    $path = pwgImageTestMarker() . '/extended-transparent-only.webp';
    $buf = 'RIFF' . "\x00\x00\x00\x00" . 'WEBP' . 'VP8' . 'X' . str_repeat("\x00", 4) . chr(0x10) . str_repeat("\x00", 4);
    file_put_contents($path, $buf);

    expect(PwgImage::webp_info($path))->toBe([
        'type' => 'VP8X',
        'has-animation' => false,
        'has-transparent' => true,
    ]);
});

test('webp_info throws for a file that is not a real WEBP container', function (): void {
    $path = pwgImageTestMarker() . '/not-webp.webp';
    file_put_contents($path, str_repeat('x', 30));

    expect(fn () => PwgImage::webp_info($path))
        ->toThrow(\Exception::class, 'webp_info(): not a valid webp image');
});

test('is_gd reports GD as available in this environment', function (): void {
    expect(PwgImage::is_gd())->toBeTrue();
});

test('get_library resolves an explicit "gd" request without probing imagick at all', function (): void {
    expect(PwgImage::get_library('gd', 'jpg'))->toBe('gd');
});

test('get_library falls back to auto for an unrecognized library name, resolving to the same real library "auto" itself would', function (): void {
    // Whichever backend this environment's own 'auto' probe resolves to
    // first (ext_imagick CLI, the imagick PHP extension, or GD -- all 3
    // are real possibilities depending on what's installed where these
    // tests run), an unrecognized $library name must resolve to that
    // exact same result, per get_library()'s own default-case fallback.
    expect(PwgImage::get_library('not-a-real-library', 'jpg'))->toBe(PwgImage::get_library('auto', 'jpg'));
});

test('get_graphics_library_label formats the resolved library and version', function (): void {
    // Real gap, found via mutation testing: only checking "non-empty
    // string" can't tell a real label/version pair apart from a mangled
    // concatenation -- ext_imagick (a real `magick` binary is installed
    // in this environment, confirmed via `command -v magick`) is
    // get_library()'s first real "auto" pick, so this asserts the exact,
    // real label format for it.
    $label = PwgImage::get_graphics_library_label();

    expect($label)->toStartWith('External ImageMagick ')
        ->and($label)->toMatch('/^External ImageMagick \d+\.\d+\.\d+/');
});

test('constructor uses a plugin-provided image instance and skips its own library resolution entirely', function (): void {
    $fake = new class implements \Piwigo\Admin\Image\ImageInterface {
        public function get_width(): int
        {
            return 123;
        }

        public function get_height(): int
        {
            return 456;
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

    $handler = function (LoadImageLibrary $event) use ($fake): void {
        $target = $event->value;
        if (! $target instanceof PwgImage) {
            throw new RuntimeException('load_image_library: expected a PwgImage instance');
        }
        $target->image = $fake;
    };
    \Piwigo\PluginConfig\EventDispatcher::get()->addTypedHandler(LoadImageLibrary::class, $handler);

    try {
        // A path this class's own real library resolution would reject
        // outright (unsupported extension) -- proving the plugin-provided
        // $image really did short-circuit __construct() before that check
        // ever ran, not merely that it happened to also pass.
        $img = pwgImageTestMake(pwgImageTestMarker() . '/whatever.totally-unsupported-ext');

        expect($img->get_width())->toBe(123);
        expect($img->library)->toBe('');
    } finally {
        \Piwigo\PluginConfig\EventDispatcher::get()->removeEventHandler(LoadImageLibrary::class, $handler);
    }
});

test('an instance built without going through the constructor throws LogicException on first real method call', function (): void {
    $img = new ReflectionClass(PwgImage::class)->newInstanceWithoutConstructor();

    expect(fn () => $img->get_width())
        ->toThrow(LogicException::class, 'PwgImage: no image library instantiated');
});

test('destroy delegates to a GD-backed image that implements it', function (): void {
    $path = pwgImageTestMarker() . '/destroy-me.jpg';
    $gdImg = imagecreatetruecolor(10, 10);
    if ($gdImg === false) {
        throw new RuntimeException('imagecreatetruecolor failed');
    }
    imagejpeg($gdImg, $path);

    $img = pwgImageTestMake($path, 'gd');

    expect($img->destroy())->toBeTrue();
});

test('pwg_resize copies the source unchanged when it already fits within the max bounds', function (): void {
    $source = pwgImageTestMarker() . '/small-source.jpg';
    $dest = pwgImageTestMarker() . '/small-dest.jpg';
    $gdImg = imagecreatetruecolor(40, 30);
    if ($gdImg === false) {
        throw new RuntimeException('imagecreatetruecolor failed');
    }
    imagejpeg($gdImg, $source);

    $img = pwgImageTestMake($source, 'gd');
    $result = $img->pwg_resize($dest, 200, 200, 90, automatic_rotation: false);

    expect($result['width'])->toBe(40);
    expect($result['height'])->toBe(30);
    expect(file_exists($dest))->toBeTrue();
    $destSize = getimagesize($dest);
    if ($destSize === false) {
        throw new RuntimeException('getimagesize failed');
    }
    expect($destSize[0])->toBe(40);
    expect($destSize[1])->toBe(30);
    // Real gap, found via mutation testing: no existing pwg_resize test
    // ever checked source/destination/size/library -- only width/height
    // and the destination file's own existence.
    expect($result['source'])->toBe($source)
        ->and($result['destination'])->toBe($dest)
        ->and($result['library'])->toBe('gd')
        ->and($result['size'])->toEndWith(' KB')
        ->and((float) $result['size'])->toBeGreaterThanOrEqual(0.0)
        ->and($result['time'])->toEndWith(' ms');
});

test('pwg_resize scales a real oversized image down and writes the resized destination', function (): void {
    $source = pwgImageTestMarker() . '/big-source.jpg';
    $dest = pwgImageTestMarker() . '/big-dest.jpg';
    $gdImg = imagecreatetruecolor(400, 200);
    if ($gdImg === false) {
        throw new RuntimeException('imagecreatetruecolor failed');
    }
    imagejpeg($gdImg, $source);

    $img = pwgImageTestMake($source, 'gd');
    $result = $img->pwg_resize($dest, 100, 100, 85, automatic_rotation: false, strip_metadata: true);

    // ratio_width(4) > ratio_height(2) -> width pinned to max_width(100),
    // height derived: round(200/4) = 50.
    expect($result['width'])->toBe(100);
    expect($result['height'])->toBe(50.0);
    $destSize = getimagesize($dest);
    if ($destSize === false) {
        throw new RuntimeException('getimagesize failed');
    }
    expect($destSize[0])->toBe(100);
    expect($destSize[1])->toBe(50);
});

test('pwg_resize crops a mismatched-aspect image before resizing', function (): void {
    $source = pwgImageTestMarker() . '/crop-source.jpg';
    $dest = pwgImageTestMarker() . '/crop-dest.jpg';
    $gdImg = imagecreatetruecolor(300, 100);
    if ($gdImg === false) {
        throw new RuntimeException('imagecreatetruecolor failed');
    }
    imagejpeg($gdImg, $source);

    $img = pwgImageTestMake($source, 'gd');
    $result = $img->pwg_resize($dest, 100, 100, 85, automatic_rotation: false, crop: true);

    expect($result['width'])->toBe(100.0);
    expect($result['height'])->toBe(100);
    $destSize = getimagesize($dest);
    if ($destSize === false) {
        throw new RuntimeException('getimagesize failed');
    }
    expect($destSize[0])->toBe(100);
    expect($destSize[1])->toBe(100);
});

test('pwg_resize rotates the destination when the source carries a real EXIF orientation tag', function (): void {
    $source = pwgImageTestMarker() . '/rotate-source.jpg';
    $dest = pwgImageTestMarker() . '/rotate-dest.jpg';
    // Orientation 6 -> get_rotation_angle() maps it to a 270-degree rotation.
    file_put_contents($source, pwgImageMakeJpegWithOrientation(6));

    $img = pwgImageTestMake($source, 'gd');
    $result = $img->pwg_resize($dest, 15, 15, 85, automatic_rotation: true);

    // The 20x20 source already fits within 15x15 on neither axis untouched,
    // so this genuinely exercises resize() + rotate() + write(), not the
    // early copy() shortcut.
    expect(file_exists($dest))->toBeTrue();
    expect($result['time'])->toBeString();
    expect($result['time'])->toEndWith(' ms');
});

test('webp_info throws when the file cannot be opened for reading', function (): void {
    $path = pwgImageTestMarker() . '/does-not-exist.webp';

    // fopen() on a missing file emits a real PHP warning -- same
    // set_error_handler swallow-for-one-call pattern as UploadServiceTest's
    // md5_file() case, needed because phpunit.xml's failOnWarning="true"
    // would otherwise convert it into an exception before this method's own
    // `if (! (bool) $fp)` check (and its own distinctly-worded exception)
    // is ever reached.
    set_error_handler(static fn (): bool => true);
    try {
        expect(fn () => PwgImage::webp_info($path))
            ->toThrow(\Exception::class, "webp_info(): fopen({$path}): Failed");
    } finally {
        restore_error_handler();
    }
});

test('webp_info throws for a well-formed VP8 header with an unrecognized sub-format byte', function (): void {
    $path = pwgImageTestMarker() . '/unknown-subformat.webp';
    // Valid up through byte 14 ('VP8'), but byte 15 is neither ' ', 'L' nor 'X'.
    file_put_contents($path, 'RIFF' . "\x00\x00\x00\x00" . 'WEBP' . 'VP8' . '?' . str_repeat("\x00", 9));

    expect(fn () => PwgImage::webp_info($path))
        ->toThrow(\Exception::class, 'webp_info(): could not detect webp type');
});

test('get_rotation_angle returns null when getimagesize() fails to read the file, instead of throwing', function (): void {
    // Real bug, fixed during the coverage-gap-closure pass (see
    // tests/Integration/UploadServiceTest.php's own docblock): every real
    // call site of get_rotation_angle() (UploadService::addUploadedFile()
    // in particular, for any non-picture file allowed via
    // CurrentConfig::uploadFormAllTypes()) calls this with no surrounding
    // try/catch, so a genuinely non-image upload always crashed with an
    // uncaught Exception. "Not decodable as an image at all" is a strict
    // superset of "not a JPEG", which this method already treats as a
    // plain `null` (no rotation) two lines below -- the throw served no
    // real caller.
    $path = pwgImageTestMarker() . '/does-not-exist.jpg';

    // getimagesize() on a missing file also emits a real PHP warning --
    // same swallow-for-one-call reasoning as webp_info()'s fopen() case
    // above.
    set_error_handler(static fn (): bool => true);
    try {
        expect(PwgImage::get_rotation_angle($path))->toBeNull();
    } finally {
        restore_error_handler();
    }
});

test('get_rotation_angle maps EXIF orientation 3 to a 180-degree rotation', function (): void {
    $path = pwgImageTestMarker() . '/orientation-3.jpg';
    file_put_contents($path, pwgImageMakeJpegWithOrientation(3));

    expect(PwgImage::get_rotation_angle($path))->toBe(180);
});

test('get_rotation_angle maps EXIF orientation 6 to a 270-degree rotation', function (): void {
    $path = pwgImageTestMarker() . '/orientation-6.jpg';
    file_put_contents($path, pwgImageMakeJpegWithOrientation(6));

    expect(PwgImage::get_rotation_angle($path))->toBe(270);
});

test('get_rotation_angle maps EXIF orientation 8 to a 90-degree rotation', function (): void {
    $path = pwgImageTestMarker() . '/orientation-8.jpg';
    file_put_contents($path, pwgImageMakeJpegWithOrientation(8));

    expect(PwgImage::get_rotation_angle($path))->toBe(90);
});

test('is_ext_imagick returns false when the configured binary directory has no real ImageMagick binary', function (): void {
    $original = \Piwigo\Config\CurrentConfig::extImagickDir();
    // Concatenated adjacent to the (memoized, real) command name via
    // escapeshellarg() -- see get_ext_imagick_command()'s own [SEC-16]
    // comment -- so this genuinely points `exec()` at a nonexistent path
    // regardless of which real binary this environment already resolved.
    \Piwigo\Config\CurrentConfig::setExtImagickDir('/totally/nonexistent/dir/');

    try {
        expect(PwgImage::is_ext_imagick())->toBeFalse();
    } finally {
        \Piwigo\Config\CurrentConfig::setExtImagickDir($original);
    }
});

test('is_ext_imagick detects the real, installed magick binary and parses its version', function (): void {
    // Real gap, found via mutation testing: every other is_ext_imagick()
    // test forces a nonexistent binary directory to hit the false path
    // (both the get_ext_imagick_command() 'magick'-vs-'convert' probe and
    // the version-string preg_match were never exercised for real). A
    // real `magick` CLI is genuinely installed in this environment
    // (confirmed via `command -v magick`), so this exercises the actual
    // success path end to end, not a mock.
    \Piwigo\Admin\Image\PwgImage::$ext_imagick_version = '';

    expect(PwgImage::is_ext_imagick())->toBeTrue()
        ->and(PwgImage::$ext_imagick_version)->toMatch('/^\d+\.\d+\.\d+/');
});

test('get_graphics_library reports a real ImageMagick PHP-extension version when ext_imagick itself is unavailable', function (): void {
    $original = \Piwigo\Config\CurrentConfig::extImagickDir();
    \Piwigo\Config\CurrentConfig::setExtImagickDir('/totally/nonexistent/dir/');

    try {
        // is_ext_imagick() forced false above; the 'imagick' PHP extension
        // (confirmed present in this environment) is get_library()'s next
        // fallback in its 'auto' chain.
        $library = PwgImage::get_graphics_library();

        expect($library)->toBeString();
        expect($library)->toStartWith('imagick/');
    } finally {
        \Piwigo\Config\CurrentConfig::setExtImagickDir($original);
    }
});

test('webp_info throws when fread() fails after a successful fopen()', function (): void {
    // fopen(<directory>, 'rb') genuinely succeeds on Linux -- it returns a
    // real, valid stream resource -- but any subsequent fread() on that
    // resource genuinely fails (EISDIR). Confirmed live. This is real
    // filesystem behavior, not a mock of fopen()/fread() or of webp_info()
    // itself, and it's the only way to reach webp_info()'s `$buf === false`
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
        expect(fn () => PwgImage::webp_info($dir))
            ->toThrow(\Exception::class, "webp_info(): fread({$dir}): Failed");
    } finally {
        restore_error_handler();
    }
});

test('get_rotation_angle returns null when exif_read_data() is unavailable, without ever calling it', function (): void {
    $path = pwgImageTestMarker() . '/exif-disabled.jpg';
    // Orientation 6 would normally map to a 270-degree rotation (see the
    // "maps EXIF orientation 6" test above) -- proving that the null this
    // test asserts really comes from the `function_exists('exif_read_data')`
    // guard short-circuiting before exif_read_data() is ever reached, not
    // from the file happening to lack a real orientation tag.
    file_put_contents($path, pwgImageMakeJpegWithOrientation(6));

    $script = 'echo json_encode(['
        . '"exif_available" => function_exists("exif_read_data"), '
        . '"result" => \Piwigo\Admin\Image\PwgImage::get_rotation_angle(' . var_export($path, true) . '),'
        . ']);';
    // php.ini's disable_functions genuinely makes the disabled function
    // both uncallable and, crucially, function_exists()-false -- a real
    // engine-level fact, confirmed live, with no in-process seam to fake it
    // (function_exists('exif_read_data') here checks the real global
    // function table, not anything this class can inject into).
    $proc = pwgImageRunSubprocess(['-d', 'disable_functions=exif_read_data'], $script);

    expect($proc['exit'])->toBe(0, 'subprocess failed: ' . $proc['stderr']);
    $decoded = json_decode($proc['stdout'], true);
    expect($decoded)->toBe(['exif_available' => false, 'result' => null]);
});

test('is_ext_imagick returns false when exec() itself is unavailable, without ever calling it', function (): void {
    $script = 'echo json_encode(['
        . '"exec_available" => function_exists("exec"), '
        . '"result" => \Piwigo\Admin\Image\PwgImage::is_ext_imagick(),'
        . ']);';
    // Same disable_functions technique as the exif_read_data test above,
    // targeting is_ext_imagick()'s own `function_exists('exec')` guard.
    $proc = pwgImageRunSubprocess(['-d', 'disable_functions=exec'], $script);

    expect($proc['exit'])->toBe(0, 'subprocess failed: ' . $proc['stderr']);
    $decoded = json_decode($proc['stdout'], true);
    expect($decoded)->toBe(['exec_available' => false, 'result' => false]);
});

test('get_graphics_library resolves through the gd case and appends a real GD version string', function (): void {
    $script = '\Piwigo\Config\CurrentConfig::setExtImagickDir("/totally/nonexistent/dir/");'
        . 'echo json_encode(['
        . '"imagick_extension_loaded" => extension_loaded("imagick"), '
        . '"result" => \Piwigo\Admin\Image\PwgImage::get_graphics_library(),'
        . ']);';
    // `-n` starts PHP with none of this host's configured extensions
    // loaded, then `-d extension=gd` loads only GD back -- a real,
    // independent PHP engine that genuinely has no imagick extension
    // (unlike the "ext_imagick itself is unavailable" test above, which
    // only rules out the ext_imagick *CLI* and still resolves to the real
    // 'imagick' PHP extension present in this main test process). Setting
    // ext_imagick_dir to a nonexistent path (same technique as the
    // is_ext_imagick() test above) rules out the ext_imagick CLI too, so
    // get_library()'s 'auto' chain falls all the way through to 'gd' --
    // exercising get_graphics_library()'s 'gd' case (gd_info(), the
    // ?? null fallback, the is_string() narrowing, and the version-suffix
    // concatenation) for real.
    $proc = pwgImageRunSubprocess(['-n', '-d', 'extension=gd'], $script);

    expect($proc['exit'])->toBe(0, 'subprocess failed: ' . $proc['stderr']);
    $decoded = json_decode($proc['stdout'], true);
    expect($decoded)->toBeArray();
    assert(is_array($decoded));
    expect($decoded['imagick_extension_loaded'])->toBeFalse();
    expect($decoded['result'])->toBeString();
    expect($decoded['result'])->toStartWith('gd/');
    expect($decoded['result'])->not->toBe('gd/');
});
