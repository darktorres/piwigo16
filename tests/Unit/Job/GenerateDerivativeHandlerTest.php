<?php

declare(strict_types=1);

use Piwigo\Config\CurrentConfig;
use Piwigo\Core\CurrentPaths;
use Piwigo\Core\Paths;
use Piwigo\Image\DerivativeCacheService;
use Piwigo\Job\GenerateDerivativeJob;
use Piwigo\Job\Handler\GenerateDerivativeHandler;

// See tests/Unit/Image/DerivativeCacheServiceTest.php's own comment on why
// a fresh, uniquely-named temp root per test (via CurrentPaths::set()) is
// used instead of a single shared constant.
function generate_derivative_handler_test_rrmdir(string $dir): void
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
        is_dir($path) ? generate_derivative_handler_test_rrmdir($path) : unlink($path);
    }
    rmdir($dir);
}

beforeEach(function (): void {
    CurrentConfig::reset();
    $root = sys_get_temp_dir() . '/piwigo-generate-derivative-handler-test-' . bin2hex(random_bytes(8));
    mkdir($root, 0o777, true);
    CurrentPaths::set(Paths::fromRoot($root));
    CurrentConfig::setDataLocation('data/');
    mkdir(CurrentPaths::get()->root . CurrentConfig::derivativeDir(), 0o777, true);
});

afterEach(function (): void {
    generate_derivative_handler_test_rrmdir(CurrentPaths::get()->root);
    CurrentConfig::reset();
    CurrentPaths::reset();
});

test('__invoke delegates to DerivativeCacheService::deleteElementDerivatives with just the path and type when representativeExt is null', function (): void {
    $derivDir = CurrentPaths::get()->root . CurrentConfig::derivativeDir() . '2026/07';
    mkdir($derivDir, 0o777, true);
    file_put_contents($derivDir . '/photo-th.jpg', 'x');
    file_put_contents($derivDir . '/other-th.jpg', 'x');

    $handler = new GenerateDerivativeHandler(new DerivativeCacheService());
    $handler(new GenerateDerivativeJob(path: '2026/07/photo.jpg', type: 'thumb'));

    expect(file_exists($derivDir . '/photo-th.jpg'))->toBeFalse()
        ->and(file_exists($derivDir . '/other-th.jpg'))->toBeTrue();
});

test('__invoke forwards representativeExt as representative_ext, rewriting the path to its pwg_representative form', function (): void {
    $derivDir = CurrentPaths::get()->root . CurrentConfig::derivativeDir() . '2026/07/pwg_representative';
    mkdir($derivDir, 0o777, true);
    file_put_contents($derivDir . '/photo-th.jpg', 'x');

    $handler = new GenerateDerivativeHandler(new DerivativeCacheService());
    $handler(new GenerateDerivativeJob(path: '2026/07/photo.pdf', representativeExt: 'jpg', type: 'thumb'));

    expect(file_exists($derivDir . '/photo-th.jpg'))->toBeFalse();
});
