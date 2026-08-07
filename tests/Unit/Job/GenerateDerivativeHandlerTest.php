<?php

declare(strict_types=1);

use Piwigo\Config\CurrentConfig;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Tests\Support\CurrentPathsTestFactory;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Image\DerivativeCacheService;
use Piwigo\Job\GenerateDerivativeJob;
use Piwigo\Job\Handler\GenerateDerivativeHandler;

// See tests/Unit/Image/DerivativeCacheServiceTest.php's own comment on why
// a fresh, uniquely-named temp root per test (via Kernel::boot()) is
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

function generate_derivative_handler_test_current_config(): CurrentConfig
{
    $currentConfig = Kernel::container()->get(CurrentConfig::class);
    if (! $currentConfig instanceof CurrentConfig) {
        throw new LogicException('Container returned an unexpected type for ' . CurrentConfig::class);
    }

    return $currentConfig;
}

beforeEach(function (): void {
    CurrentConfigTestFactory::get()->reset();
    $root = sys_get_temp_dir() . '/piwigo-generate-derivative-handler-test-' . bin2hex(random_bytes(8));
    mkdir($root, 0o777, true);
    Kernel::boot(Paths::fromRoot($root));
    $currentConfig = generate_derivative_handler_test_current_config();
    $currentConfig->setDataLocation('data/');
    mkdir(CurrentPathsTestFactory::get()->root . $currentConfig->derivativeDir(), 0o777, true);
});

afterEach(function (): void {
    generate_derivative_handler_test_rrmdir(CurrentPathsTestFactory::get()->root);
    CurrentConfigTestFactory::get()->reset();
    Kernel::reset();
});

test('__invoke delegates to DerivativeCacheService::deleteElementDerivatives with just the path and type when representativeExt is null', function (): void {
    $derivDir = CurrentPathsTestFactory::get()->root . generate_derivative_handler_test_current_config()->derivativeDir() . '2026/07';
    mkdir($derivDir, 0o777, true);
    file_put_contents($derivDir . '/photo-th.jpg', 'x');
    file_put_contents($derivDir . '/other-th.jpg', 'x');

    // Must be the SAME container-shared CurrentConfig instance beforeEach()
    // configured (dataLocation='data/') -- a fresh new CurrentConfig()
    // would carry only its own hardcoded defaults, computing a different
    // derivative directory than $derivDir above.
    $handler = new GenerateDerivativeHandler(new DerivativeCacheService(generate_derivative_handler_test_current_config(), CurrentPathsTestFactory::get()));
    $handler(new GenerateDerivativeJob(path: '2026/07/photo.jpg', type: 'thumb'));

    expect(file_exists($derivDir . '/photo-th.jpg'))->toBeFalse()
        ->and(file_exists($derivDir . '/other-th.jpg'))->toBeTrue();
});

test('__invoke forwards representativeExt as representative_ext, rewriting the path to its pwg_representative form', function (): void {
    $derivDir = CurrentPathsTestFactory::get()->root . generate_derivative_handler_test_current_config()->derivativeDir() . '2026/07/pwg_representative';
    mkdir($derivDir, 0o777, true);
    file_put_contents($derivDir . '/photo-th.jpg', 'x');

    $handler = new GenerateDerivativeHandler(new DerivativeCacheService(generate_derivative_handler_test_current_config(), CurrentPathsTestFactory::get()));
    $handler(new GenerateDerivativeJob(path: '2026/07/photo.pdf', representativeExt: 'jpg', type: 'thumb'));

    expect(file_exists($derivDir . '/photo-th.jpg'))->toBeFalse();
});
