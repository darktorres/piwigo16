<?php

declare(strict_types=1);

use Piwigo\Config\Config;
use Piwigo\Image\DerivativeCacheService;
use Piwigo\Job\Handler\RegenerateAllDerivativesHandler;
use Piwigo\Job\RegenerateAllDerivativesJob;

// See tests/Unit/Image/DerivativeCacheServiceTest.php's own extensive
// comment on why PHPWG_ROOT_PATH can't be trusted alone.
if (! defined('PHPWG_ROOT_PATH')) {
    define('PHPWG_ROOT_PATH', sys_get_temp_dir() . '/piwigo-regen-handler-test-root/');
}

foreach ([
    'IMG_SQUARE' => 'square',
    'IMG_THUMB' => 'thumb',
    'IMG_XXSMALL' => '2small',
    'IMG_XSMALL' => 'xsmall',
    'IMG_SMALL' => 'small',
    'IMG_MEDIUM' => 'medium',
    'IMG_LARGE' => 'large',
    'IMG_XLARGE' => 'xlarge',
    'IMG_XXLARGE' => 'xxlarge',
    'IMG_3XLARGE' => '3xlarge',
    'IMG_4XLARGE' => '4xlarge',
    'IMG_CUSTOM' => 'custom',
] as $name => $value) {
    if (! defined($name)) {
        define($name, $value);
    }
}

function regen_handler_test_marker(): string
{
    /** @var string|null $marker */
    static $marker = null;

    return $marker ??= 'phpwg-regen-handler-test-marker-' . bin2hex(random_bytes(8));
}

function regen_handler_test_rrmdir(string $dir): void
{
    $marker = regen_handler_test_marker();
    if (! str_contains($dir, $marker)) {
        throw new RuntimeException("Refusing to recursively delete '{$dir}': missing this test run's marker.");
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
        is_dir($path) ? regen_handler_test_rrmdir($path) : unlink($path);
    }
    rmdir($dir);
}

beforeEach(function (): void {
    Config::reset();
    Config::override('data_location', regen_handler_test_marker() . '/');
    mkdir(PHPWG_ROOT_PATH . Config::derivativeDir(), 0o777, true);
});

afterEach(function (): void {
    regen_handler_test_rrmdir(PHPWG_ROOT_PATH . regen_handler_test_marker());
    Config::reset();
});

test('__invoke delegates to DerivativeCacheService::clearDerivativeCache with the job types', function (): void {
    $derivDir = PHPWG_ROOT_PATH . Config::derivativeDir() . '2026';
    mkdir($derivDir, 0o777, true);
    file_put_contents($derivDir . '/photo-th.jpg', 'x');
    file_put_contents($derivDir . '/photo-sq.jpg', 'x');

    $handler = new RegenerateAllDerivativesHandler(new DerivativeCacheService());
    $handler(new RegenerateAllDerivativesJob(['thumb']));

    expect(file_exists($derivDir . '/photo-th.jpg'))->toBeFalse()
        ->and(file_exists($derivDir . '/photo-sq.jpg'))->toBeTrue();
});
