<?php

declare(strict_types=1);

use Piwigo\Admin\Image\ImageProcessingException;
use Piwigo\Admin\Image\PwgImage;

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
 * false;` (only reached if even 'gd' fails) untestable here too.
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

    expect(fn () => new PwgImage($path))
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
    $label = PwgImage::get_graphics_library_label();

    expect($label)->toBeString();
    expect($label)->not->toBe('');
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

    $handler = function (array $args) use ($fake): void {
        $target = $args[0];
        if (! $target instanceof PwgImage) {
            throw new RuntimeException('load_image_library: expected a PwgImage instance');
        }
        $target->image = $fake;
    };
    \Piwigo\PluginConfig\EventDispatcher::get()->addEventHandler('load_image_library', $handler);

    try {
        // A path this class's own real library resolution would reject
        // outright (unsupported extension) -- proving the plugin-provided
        // $image really did short-circuit __construct() before that check
        // ever ran, not merely that it happened to also pass.
        $img = new PwgImage(pwgImageTestMarker() . '/whatever.totally-unsupported-ext');

        expect($img->get_width())->toBe(123);
        expect($img->library)->toBe('');
    } finally {
        \Piwigo\PluginConfig\EventDispatcher::get()->removeEventHandler('load_image_library', $handler);
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

    $img = new PwgImage($path, 'gd');

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

    $img = new PwgImage($source, 'gd');
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
});

test('pwg_resize scales a real oversized image down and writes the resized destination', function (): void {
    $source = pwgImageTestMarker() . '/big-source.jpg';
    $dest = pwgImageTestMarker() . '/big-dest.jpg';
    $gdImg = imagecreatetruecolor(400, 200);
    if ($gdImg === false) {
        throw new RuntimeException('imagecreatetruecolor failed');
    }
    imagejpeg($gdImg, $source);

    $img = new PwgImage($source, 'gd');
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

    $img = new PwgImage($source, 'gd');
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

    $img = new PwgImage($source, 'gd');
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

test('get_rotation_angle throws when getimagesize() fails to read the file', function (): void {
    $path = pwgImageTestMarker() . '/does-not-exist.jpg';

    // getimagesize() on a missing file also emits a real PHP warning --
    // same swallow-for-one-call reasoning as webp_info()'s fopen() case
    // above.
    set_error_handler(static fn (): bool => true);
    try {
        expect(fn () => PwgImage::get_rotation_angle($path))
            ->toThrow(\Exception::class, "get_rotation_angle(): getimagesize({$path}): Failed");
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
