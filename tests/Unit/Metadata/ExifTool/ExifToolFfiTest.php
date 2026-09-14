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

test('resolveLibraryPath() defaults to the Docker-deployed path on this (non-Windows) box', function (): void {
    // Real, not simulated: DEFAULT_LIBRARY_PATH is a hard requirement here
    // too (see isAvailable()'s own test above), so this is the actual
    // real-world default path this dev/CI box resolves to.
    expect(ExifToolFfi::resolveLibraryPath())
        ->toBe('/usr/local/lib/piwigo/libexiftool_rs.so');
});

test('resolveLibraryPath() falls back to the fork\'s own local cargo build output on Windows', function (): void {
    // The bug this whole resolution scheme exists to fix: a Windows dev
    // building tools/exiftool-rs-fork/ locally gets a real exiftool_rs.dll,
    // not a libexiftool_rs.so -- there is no deployed convention for
    // Windows (Piwigo ships no Windows production image), so this branch
    // is unconditional there, independent of what exists on disk.
    expect(ExifToolFfi::resolveLibraryPath('Windows'))
        ->toEndWith('/tools/exiftool-rs-fork/target/release/exiftool_rs.dll');
});

test('resolveLibraryPath() honors the PIWIGO_EXIFTOOL_RS_LIBRARY_PATH env var override, on any OS', function (): void {
    // Save/restore rather than a blind unset in the finally: this test's
    // own --parallel worker process keeps this env var for every later
    // test in the same worker otherwise (only matters if something ever
    // legitimately sets it outside this test, but restoring the real
    // prior value costs nothing and is never wrong).
    $original = getenv('PIWIGO_EXIFTOOL_RS_LIBRARY_PATH');
    putenv('PIWIGO_EXIFTOOL_RS_LIBRARY_PATH=/custom/path/libexiftool_rs.so');

    try {
        expect(ExifToolFfi::resolveLibraryPath())
            ->toBe('/custom/path/libexiftool_rs.so')
            ->and(ExifToolFfi::resolveLibraryPath('Windows'))
            ->toBe('/custom/path/libexiftool_rs.so');
    } finally {
        if ($original === false) {
            putenv('PIWIGO_EXIFTOOL_RS_LIBRARY_PATH');
        } else {
            putenv('PIWIGO_EXIFTOOL_RS_LIBRARY_PATH=' . $original);
        }
    }
});

test('constructor honors an explicit $libraryPath over resolveLibraryPath(), just like before this env-var/OS resolution existed', function (): void {
    expect(static fn () => new ExifToolFfi('/no/such/path/libexiftool_rs.so'))
        ->toThrow(RuntimeException::class, 'Could not find the exiftool-rs shared library at "/no/such/path/libexiftool_rs.so"');
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
