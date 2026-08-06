<?php

declare(strict_types=1);

use Piwigo\Config\CurrentConfig;
use Piwigo\Tests\Support\CurrentPathsTestFactory;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Image\DerivativeCacheService;
use Piwigo\Job\Handler\RegenerateAllDerivativesHandler;
use Piwigo\Job\RegenerateAllDerivativesJob;

// See tests/Unit/Image/DerivativeCacheServiceTest.php's own comment on why
// a fresh, uniquely-named temp root per test (via Kernel::boot()) is
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
    CurrentConfig::current()->reset();
    $root = sys_get_temp_dir() . '/piwigo-regen-handler-test-' . bin2hex(random_bytes(8));
    mkdir($root, 0o777, true);
    Kernel::boot(Paths::fromRoot($root));
    CurrentConfig::current()->setDataLocation('data/');
    mkdir(CurrentPathsTestFactory::get()->root . CurrentConfig::current()->derivativeDir(), 0o777, true);
});

afterEach(function (): void {
    regen_handler_test_rrmdir(CurrentPathsTestFactory::get()->root);
    CurrentConfig::current()->reset();
    Kernel::reset();
});

test('__invoke delegates to DerivativeCacheService::clearDerivativeCache with the job types', function (): void {
    $derivDir = CurrentPathsTestFactory::get()->root . CurrentConfig::current()->derivativeDir() . '2026';
    mkdir($derivDir, 0o777, true);
    file_put_contents($derivDir . '/photo-th.jpg', 'x');
    file_put_contents($derivDir . '/photo-sq.jpg', 'x');

    // Same container-shared instance as beforeEach's own setDataLocation('data/')
    // write -- a fresh `new CurrentConfig()` here would carry its own
    // default dataLocation ('_data/'), pointing DerivativeCacheService's
    // derivativeDir() at a directory that doesn't hold the fixture files
    // created above, silently no-oping clearDerivativeCache() and failing
    // the assertions below for the wrong reason.
    $handler = new RegenerateAllDerivativesHandler(new DerivativeCacheService(CurrentConfig::current(), CurrentPathsTestFactory::get()));
    $handler(new RegenerateAllDerivativesJob(['thumb']));

    expect(file_exists($derivDir . '/photo-th.jpg'))->toBeFalse()
        ->and(file_exists($derivDir . '/photo-sq.jpg'))->toBeTrue();
});
