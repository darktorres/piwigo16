<?php

declare(strict_types=1);

use Piwigo\Config\CurrentConfig;
use Piwigo\Core\CurrentPaths;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Template\Template;

/**
 * Piwigo\Template\CurrentTemplate -- had zero dedicated coverage.
 * get()'s own "not initialised" \LogicException guard (only reachable
 * before any real request has run RequestBootstrap::finalize(), or in a
 * test that never calls set()) is the only red line.
 *
 * Same "point CurrentPaths at a fresh temp root" Template construction
 * setup as PictureRateRendererTest.php's own docblock -- a real
 * `new Template()` still needs a writable data dir to construct at all.
 */
function current_template_test_rrmdir(string $dir): void
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
        is_dir($path) ? current_template_test_rrmdir($path) : unlink($path);
    }
    rmdir($dir);
}

beforeEach(function (): void {
    $root = sys_get_temp_dir() . '/piwigo-current-template-test-' . bin2hex(random_bytes(8));
    mkdir($root, 0o777, true);
    Kernel::boot(Paths::fromRoot($root));
    CurrentConfig::setDataLocation('data/');
    CurrentConfig::setDataDirChecked('1');
});

afterEach(function (): void {
    current_template_test_rrmdir(CurrentPaths::get()->root);
    CurrentTemplate::reset();
    CurrentConfig::reset();
    Kernel::reset();
});

test('get throws when no Template has ever been set', function (): void {
    expect(CurrentTemplate::isInitialized())->toBeFalse();

    CurrentTemplate::get();
})->throws(LogicException::class, 'CurrentTemplate not initialised -- call Piwigo\Bootstrap\RequestBootstrap::finalize() first.');

test('set publishes a Template instance that get returns and isInitialized reports true', function (): void {
    $template = new Template();

    CurrentTemplate::set($template);

    expect(CurrentTemplate::isInitialized())->toBeTrue()
        ->and(CurrentTemplate::get())->toBe($template);
});

test('reset clears the published instance so get throws again', function (): void {
    CurrentTemplate::set(new Template());
    expect(CurrentTemplate::isInitialized())->toBeTrue();

    CurrentTemplate::reset();

    expect(CurrentTemplate::isInitialized())->toBeFalse();
    CurrentTemplate::get();
})->throws(LogicException::class);
