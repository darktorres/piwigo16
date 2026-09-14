<?php

declare(strict_types=1);

use PHPUnit\Framework\Assert;
use Piwigo\Metadata\ExifTool\ExifToolFfi;

/**
 * Real dependency, no fake: unlike the old subprocess design's tests
 * (which substituted a small disposable fake bash script for the real
 * `exiftool` binary via a constructor parameter), there is no lightweight
 * way to fake a shared library for FFI -- so these run against the actual
 * vendored `tools/exiftool-rs-fork/` build, matching this codebase's own
 * existing convention of preferring real dependencies over mocks
 * (established by `Admin\Image\ImageBackend::isExtImagick()`'s own unit
 * test, which checks real environment truthiness rather than faking it).
 * Exhaustive tag-value correctness (dates, IPTC translation, GPS sign,
 * HTML stripping, ...) is `Integration\MetadataServiceTest`'s job -- this
 * file covers only `ExifToolFfi`'s own contract: constructor validation,
 * `isAvailable()`, and the JSON-decode round trip.
 */
function exifToolFfiTestTaggedJpeg(): string
{
    $path = sys_get_temp_dir() . '/exiftool-ffi-test-' . bin2hex(random_bytes(8)) . '.jpg';
    $img = imagecreatetruecolor(8, 8);
    imagejpeg($img, $path, 60);

    $process = new Symfony\Component\Process\Process([
        'exiftool',
        '-overwrite_original',
        '-EXIF:Make=UnitTestCam',
        '-GPS:GPSLatitude=41.9027',
        '-GPS:GPSLatitudeRef=S',
        $path,
    ]);
    $process->run();
    if (! $process->isSuccessful()) {
        throw new RuntimeException('Failed to tag test JPEG with real exiftool: ' . $process->getErrorOutput());
    }

    return $path;
}

test('isAvailable() is true when the exiftool-rs shared library is genuinely present', function (): void {
    // A hard requirement of this project from this change onward (the
    // Dockerfile builds it from tools/exiftool-rs-fork/) -- a false here in
    // any real dev/CI environment is a genuine environment problem worth
    // failing loudly on, not something to soften into a weaker assertion.
    expect(ExifToolFfi::isAvailable())
        ->toBeTrue();
});

test('constructor throws a clear, actionable exception when the library file is missing', function (): void {
    expect(static fn () => new ExifToolFfi('/no/such/path/libexiftool_rs.so'))
        ->toThrow(RuntimeException::class, 'Piwigo requires it to read photo metadata');
});

test('read() extracts real tags, including the # numeric GPS suffix, from a tagged file', function (): void {
    $path = exifToolFfiTestTaggedJpeg();

    try {
        $exifTool = new ExifToolFfi();
        $result = $exifTool->read($path, ['Make', 'GPSLatitude#']);
        Assert::assertIsArray($result);

        expect($result['Make'])->toBe('UnitTestCam')
            ->and($result['GPSLatitude'])->toEqualWithDelta(-41.9027, 0.001);
    } finally {
        unlink($path);
    }
});

test('read() reuses the same loaded library binding across multiple calls', function (): void {
    $path = exifToolFfiTestTaggedJpeg();

    try {
        $exifTool = new ExifToolFfi();
        $first = $exifTool->read($path, ['Make']);
        $second = $exifTool->read($path, ['Make']);
        Assert::assertIsArray($first);
        Assert::assertIsArray($second);

        expect($first['Make'])->toBe('UnitTestCam')
            ->and($second['Make'])->toBe('UnitTestCam');
    } finally {
        unlink($path);
    }
});

test('read() returns null for a file that does not exist', function (): void {
    $exifTool = new ExifToolFfi();

    expect($exifTool->read('/no/such/file.jpg', ['Make']))
        ->toBeNull();
});

test('close() is a harmless no-op, safe to call repeatedly and before further read()s', function (): void {
    $path = exifToolFfiTestTaggedJpeg();

    try {
        $exifTool = new ExifToolFfi();
        $exifTool->close();
        $exifTool->close();
        $result = $exifTool->read($path, ['Make']);
        Assert::assertIsArray($result);

        expect($result['Make'])->toBe('UnitTestCam');
    } finally {
        unlink($path);
    }
});
