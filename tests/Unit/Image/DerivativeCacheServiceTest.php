<?php

declare(strict_types=1);

use Piwigo\Config\Config;
use Piwigo\Image\DerivativeCacheService;

// PHPWG_ROOT_PATH is a process-wide constant -- some OTHER test file
// (e.g. StorageRegistryTest.php) may already have defined it to the REAL
// project root before this file loads, since Pest/PHPUnit parses every
// test file into the same PHP process even under --filter. A guarded
// `if (! defined(...))` here is NOT enough on its own to guarantee a safe
// value; never let it, alone, gate a recursive delete (that's exactly
// what wiped the real project root out from under a live session once
// already). Only ever destroy paths that also contain $marker below.
if (! defined('PHPWG_ROOT_PATH')) {
    define('PHPWG_ROOT_PATH', sys_get_temp_dir() . '/piwigo-derivative-cache-test-root/');
}

// Unique per-run marker: every path this suite creates or deletes must
// contain it, and the recursive-delete helper refuses to run on any path
// that doesn't -- regardless of what PHPWG_ROOT_PATH actually resolves to.
function derivative_cache_test_marker(): string
{
    /** @var string|null $marker */
    static $marker = null;

    return $marker ??= 'phpwg-test-marker-' . bin2hex(random_bytes(8));
}

function derivative_cache_test_rrmdir(string $dir): void
{
    $marker = derivative_cache_test_marker();
    if (! str_contains($dir, $marker)) {
        throw new \RuntimeException(
            "Refusing to recursively delete '{$dir}': it does not contain this test run's marker ('{$marker}'). ".
            'PHPWG_ROOT_PATH is a shared process-wide constant that another test file may have '.
            'pointed at a real, non-test directory.'
        );
    }
    if (! is_dir($dir)) {
        return;
    }
    $nodes = scandir($dir);
    foreach ($nodes !== false ? $nodes : [] as $node) {
        if ($node === '.' || $node === '..') {
            continue;
        }
        $path = $dir . '/' . $node;
        is_dir($path) ? derivative_cache_test_rrmdir($path) : unlink($path);
    }
    rmdir($dir);
}

beforeEach(function (): void {
    $marker = derivative_cache_test_marker();
    Config::reset();
    // Config::derivativeDir() = Config::dataLocation() . 'i/' -- point
    // data_location at a uniquely-named subdirectory so
    // clearDerivativeCache()/deleteElementDerivatives() (which read
    // PHPWG_ROOT_PATH . Config::derivativeDir() internally, not an
    // explicit test-supplied path) only ever touch a path carrying this
    // run's marker, never PHPWG_ROOT_PATH itself.
    Config::override('data_location', $marker . '/');
    mkdir(PHPWG_ROOT_PATH . Config::derivativeDir(), 0o777, true);
});

afterEach(function (): void {
    $marker = derivative_cache_test_marker();
    derivative_cache_test_rrmdir(PHPWG_ROOT_PATH . $marker);
    Config::reset();
});

test('clearDerivativeCacheRecursive deletes only files matching the pattern', function (): void {
    $dir = PHPWG_ROOT_PATH . derivative_cache_test_marker() . '/derivatives';
    mkdir($dir, 0o777, true);
    file_put_contents($dir . '/photo-th.jpg', 'x');
    file_put_contents($dir . '/photo-sq.jpg', 'x');
    file_put_contents($dir . '/original.jpg', 'x');

    new DerivativeCacheService()->clearDerivativeCacheRecursive($dir, '#.*-th\.jpg$#');

    expect(file_exists($dir . '/photo-th.jpg'))->toBeFalse()
        ->and(file_exists($dir . '/photo-sq.jpg'))->toBeTrue()
        ->and(file_exists($dir . '/original.jpg'))->toBeTrue();
});

test('clearDerivativeCacheRecursive removes an emptied subdirectory', function (): void {
    $base = PHPWG_ROOT_PATH . derivative_cache_test_marker() . '/derivatives';
    $dir = $base . '/2026';
    mkdir($dir, 0o777, true);
    file_put_contents($dir . '/photo-th.jpg', 'x');

    new DerivativeCacheService()->clearDerivativeCacheRecursive($base, '#.*-th\.jpg$#');

    expect(is_dir($dir))->toBeFalse();
});

test('clearDerivativeCacheRecursive recurses into nested directories', function (): void {
    $base = PHPWG_ROOT_PATH . derivative_cache_test_marker() . '/derivatives';
    $nested = $base . '/a/b';
    mkdir($nested, 0o777, true);
    file_put_contents($nested . '/photo-th.jpg', 'x');
    file_put_contents($nested . '/photo-sq.jpg', 'x');

    new DerivativeCacheService()->clearDerivativeCacheRecursive($base, '#.*-th\.jpg$#');

    expect(file_exists($nested . '/photo-th.jpg'))->toBeFalse()
        ->and(file_exists($nested . '/photo-sq.jpg'))->toBeTrue();
});

test('deleteElementDerivatives removes every derivative for the given element', function (): void {
    $derivDir = PHPWG_ROOT_PATH . Config::derivativeDir() . '2026/07';
    mkdir($derivDir, 0o777, true);
    file_put_contents($derivDir . '/photo-th.jpg', 'x');
    file_put_contents($derivDir . '/photo-sq.jpg', 'x');
    file_put_contents($derivDir . '/other-th.jpg', 'x');

    new DerivativeCacheService()->deleteElementDerivatives([
        'path' => '2026/07/photo.jpg',
    ]);

    expect(file_exists($derivDir . '/photo-th.jpg'))->toBeFalse()
        ->and(file_exists($derivDir . '/photo-sq.jpg'))->toBeFalse()
        ->and(file_exists($derivDir . '/other-th.jpg'))->toBeTrue();
});

test('deleteElementDerivatives filters by a specific derivative type', function (): void {
    $derivDir = PHPWG_ROOT_PATH . Config::derivativeDir() . '2026/07';
    mkdir($derivDir, 0o777, true);
    file_put_contents($derivDir . '/photo-th.jpg', 'x');
    file_put_contents($derivDir . '/photo-sq.jpg', 'x');

    new DerivativeCacheService()->deleteElementDerivatives([
        'path' => '2026/07/photo.jpg',
    ], 'thumb');

    expect(file_exists($derivDir . '/photo-th.jpg'))->toBeFalse()
        ->and(file_exists($derivDir . '/photo-sq.jpg'))->toBeTrue();
});

test('deleteElementDerivatives throws for a path with no extension', function (): void {
    new DerivativeCacheService()->deleteElementDerivatives(['path' => 'no_extension']);
})->throws(Exception::class);

test('clearDerivativeCache with an explicit type list only matches those types', function (): void {
    $derivDir = PHPWG_ROOT_PATH . Config::derivativeDir() . '2026';
    mkdir($derivDir, 0o777, true);
    file_put_contents($derivDir . '/photo-th.jpg', 'x');
    file_put_contents($derivDir . '/photo-sq.jpg', 'x');

    new DerivativeCacheService()->clearDerivativeCache(['thumb']);

    expect(file_exists($derivDir . '/photo-th.jpg'))->toBeFalse()
        ->and(file_exists($derivDir . '/photo-sq.jpg'))->toBeTrue();
});
