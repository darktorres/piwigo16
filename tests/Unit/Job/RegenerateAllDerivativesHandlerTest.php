<?php

declare(strict_types=1);

use Piwigo\Config\CurrentConfig;
use Piwigo\Core\CurrentPaths;
use Piwigo\Core\Paths;
use Piwigo\Image\DerivativeCacheService;
use Piwigo\Job\Handler\RegenerateAllDerivativesHandler;
use Piwigo\Job\RegenerateAllDerivativesJob;

// See tests/Unit/Image/DerivativeCacheServiceTest.php's own comment on why
// a fresh, uniquely-named temp root per test (via CurrentPaths::set()) is
// used instead of a single shared constant.
function regen_handler_test_rrmdir(string $dir): void
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
        is_dir($path) ? regen_handler_test_rrmdir($path) : unlink($path);
    }
    rmdir($dir);
}

beforeEach(function (): void {
    CurrentConfig::reset();
    $root = sys_get_temp_dir() . '/piwigo-regen-handler-test-' . bin2hex(random_bytes(8));
    mkdir($root, 0o777, true);
    CurrentPaths::set(Paths::fromRoot($root));
    CurrentConfig::setDataLocation('data/');
    mkdir(CurrentPaths::get()->root . CurrentConfig::derivativeDir(), 0o777, true);
});

afterEach(function (): void {
    regen_handler_test_rrmdir(CurrentPaths::get()->root);
    CurrentConfig::reset();
    CurrentPaths::reset();
});

test('__invoke delegates to DerivativeCacheService::clearDerivativeCache with the job types', function (): void {
    $derivDir = CurrentPaths::get()->root . CurrentConfig::derivativeDir() . '2026';
    mkdir($derivDir, 0o777, true);
    file_put_contents($derivDir . '/photo-th.jpg', 'x');
    file_put_contents($derivDir . '/photo-sq.jpg', 'x');

    $handler = new RegenerateAllDerivativesHandler(new DerivativeCacheService());
    $handler(new RegenerateAllDerivativesJob(['thumb']));

    expect(file_exists($derivDir . '/photo-th.jpg'))->toBeFalse()
        ->and(file_exists($derivDir . '/photo-sq.jpg'))->toBeTrue();
});
