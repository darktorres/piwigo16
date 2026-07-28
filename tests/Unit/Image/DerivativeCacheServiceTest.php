<?php

declare(strict_types=1);

use Piwigo\Config\CurrentConfig;
use Piwigo\Core\CurrentPaths;
use Piwigo\Core\Paths;
use Piwigo\Image\DerivativeCacheService;

// Unique per-run root: clearDerivativeCache()/deleteElementDerivatives()
// read CurrentPaths::get()->root . CurrentConfig::derivativeDir() internally, not
// an explicit test-supplied path -- pointing CurrentPaths at a fresh,
// uniquely-named temp directory per test (rather than a shared constant)
// means the recursive-delete helper below can never touch anything outside
// this run's own sandbox, regardless of test ordering or other suites'
// own CurrentPaths::set() calls.
function derivative_cache_test_rrmdir(string $dir): void
{
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
    CurrentConfig::reset();
    $root = sys_get_temp_dir() . '/piwigo-derivative-cache-test-' . bin2hex(random_bytes(8));
    mkdir($root, 0o777, true);
    CurrentPaths::set(Paths::fromRoot($root));
    CurrentConfig::setDataLocation('data/');
    mkdir(CurrentPaths::get()->root . CurrentConfig::derivativeDir(), 0o777, true);
});

afterEach(function (): void {
    derivative_cache_test_rrmdir(CurrentPaths::get()->root);
    CurrentConfig::reset();
    CurrentPaths::reset();
});

test('clearDerivativeCacheRecursive deletes only files matching the pattern', function (): void {
    $dir = CurrentPaths::get()->root . 'derivatives';
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
    $base = CurrentPaths::get()->root . 'derivatives';
    $dir = $base . '/2026';
    mkdir($dir, 0o777, true);
    file_put_contents($dir . '/photo-th.jpg', 'x');

    new DerivativeCacheService()->clearDerivativeCacheRecursive($base, '#.*-th\.jpg$#');

    expect(is_dir($dir))->toBeFalse();
});

test('clearDerivativeCacheRecursive recurses into nested directories', function (): void {
    $base = CurrentPaths::get()->root . 'derivatives';
    $nested = $base . '/a/b';
    mkdir($nested, 0o777, true);
    file_put_contents($nested . '/photo-th.jpg', 'x');
    file_put_contents($nested . '/photo-sq.jpg', 'x');

    new DerivativeCacheService()->clearDerivativeCacheRecursive($base, '#.*-th\.jpg$#');

    expect(file_exists($nested . '/photo-th.jpg'))->toBeFalse()
        ->and(file_exists($nested . '/photo-sq.jpg'))->toBeTrue();
});

test('deleteElementDerivatives removes every derivative for the given element', function (): void {
    $derivDir = CurrentPaths::get()->root . CurrentConfig::derivativeDir() . '2026/07';
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
    $derivDir = CurrentPaths::get()->root . CurrentConfig::derivativeDir() . '2026/07';
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
    $derivDir = CurrentPaths::get()->root . CurrentConfig::derivativeDir() . '2026';
    mkdir($derivDir, 0o777, true);
    file_put_contents($derivDir . '/photo-th.jpg', 'x');
    file_put_contents($derivDir . '/photo-sq.jpg', 'x');

    new DerivativeCacheService()->clearDerivativeCache(['thumb']);

    expect(file_exists($derivDir . '/photo-th.jpg'))->toBeFalse()
        ->and(file_exists($derivDir . '/photo-sq.jpg'))->toBeTrue();
});

test('clearDerivativeCache accepts a single type given as a plain string, not wrapped in an array', function (): void {
    $derivDir = CurrentPaths::get()->root . CurrentConfig::derivativeDir() . '2026';
    mkdir($derivDir, 0o777, true);
    file_put_contents($derivDir . '/photo-th.jpg', 'x');
    file_put_contents($derivDir . '/photo-sq.jpg', 'x');

    new DerivativeCacheService()->clearDerivativeCache('thumb');

    expect(file_exists($derivDir . '/photo-th.jpg'))->toBeFalse()
        ->and(file_exists($derivDir . '/photo-sq.jpg'))->toBeTrue();
});

test('clearDerivativeCache falls back to the custom-type pattern for a type name that is neither "all" nor a standard ImageStdParams type', function (): void {
    $derivDir = CurrentPaths::get()->root . CurrentConfig::derivativeDir() . '2026';
    mkdir($derivDir, 0o777, true);
    // derivativeToUrl(CUSTOM) . '_' . $type == 'cu_myCustomWidget', matching
    // the '-cu_myCustomWidget.ext' filename a real custom-type derivative
    // would carry.
    file_put_contents($derivDir . '/photo-cu_myCustomWidget.jpg', 'x');
    file_put_contents($derivDir . '/photo-th.jpg', 'x');

    new DerivativeCacheService()->clearDerivativeCache('myCustomWidget');

    expect(file_exists($derivDir . '/photo-cu_myCustomWidget.jpg'))->toBeFalse()
        ->and(file_exists($derivDir . '/photo-th.jpg'))->toBeTrue();
});

test('deleteElementDerivatives rewrites the path to its pwg_representative form when representative_ext is given', function (): void {
    $derivDir = CurrentPaths::get()->root . CurrentConfig::derivativeDir() . '2026/07/pwg_representative';
    mkdir($derivDir, 0o777, true);
    file_put_contents($derivDir . '/photo-th.jpg', 'x');

    new DerivativeCacheService()->deleteElementDerivatives([
        'path' => '2026/07/photo.pdf',
        'representative_ext' => 'jpg',
    ]);

    expect(file_exists($derivDir . '/photo-th.jpg'))->toBeFalse();
});

test('deleteElementDerivatives strips a leading "../" from the path', function (): void {
    $derivDir = CurrentPaths::get()->root . CurrentConfig::derivativeDir() . '2026/07';
    mkdir($derivDir, 0o777, true);
    file_put_contents($derivDir . '/photo-th.jpg', 'x');

    new DerivativeCacheService()->deleteElementDerivatives([
        'path' => '../2026/07/photo.jpg',
    ]);

    expect(file_exists($derivDir . '/photo-th.jpg'))->toBeFalse();
});

test('clearDerivativeCacheRecursive returns false without throwing when the directory cannot be opened', function (): void {
    $missing = CurrentPaths::get()->root . 'does-not-exist';

    // opendir() on a missing directory is a real, unsuppressed E_WARNING
    // at the call site (not `@`-guarded in the source) -- absorb it with
    // a scoped handler instead of letting failOnWarning="true" turn it
    // into a failure, same convention as PluginMaintainTest's own
    // set_error_handler()/restore_error_handler() pair.
    set_error_handler(static fn (): bool => true);
    try {
        $result = new DerivativeCacheService()->clearDerivativeCacheRecursive($missing, '#.*#');
    } finally {
        restore_error_handler();
    }

    expect($result)->toBeFalse();
});
