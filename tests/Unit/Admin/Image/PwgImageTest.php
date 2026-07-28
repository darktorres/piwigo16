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
 * has no seam to inject.
 */
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
