<?php

declare(strict_types=1);

use Piwigo\Config\CurrentConfig;
use Piwigo\Core\CurrentPaths;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Template\Template;

/**
 * Piwigo\Template\CurrentTemplate -- container-shared instance (singleton/
 * service-locator elimination campaign, Phase 5); each test constructs its
 * own fresh instance directly, no reset() needed. get()'s own
 * "not initialised" \LogicException guard (only reachable before any real
 * request has run RequestBootstrap::finalize(), or in a test that never
 * calls set()) is the only red line.
 *
 * Same "point CurrentPaths at a fresh temp root" Template construction
 * setup as PictureRateRendererTest.php's own docblock -- a real
 * `\Piwigo\Tests\Support\TemplateTestFactory::build()` still needs a writable data dir to construct at all.
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
    $currentConfig = Kernel::container()->get(CurrentConfig::class);
    if (! $currentConfig instanceof CurrentConfig) {
        throw new \LogicException('Container returned an unexpected type for ' . CurrentConfig::class);
    }
    $currentConfig->setDataLocation('data/');
    $currentConfig->setDataDirChecked('1');
});

afterEach(function (): void {
    current_template_test_rrmdir(CurrentPaths::get()->root);
    \Piwigo\Config\CurrentConfig::current()->reset();
    Kernel::reset();
});

test('get throws when no Template has ever been set', function (): void {
    $currentTemplate = new CurrentTemplate();

    expect($currentTemplate->isInitialized())->toBeFalse();

    $currentTemplate->get();
})->throws(LogicException::class, 'CurrentTemplate not initialised -- call Piwigo\Bootstrap\RequestBootstrap::finalize() first.');

test('set publishes a Template instance that get returns and isInitialized reports true', function (): void {
    $currentTemplate = new CurrentTemplate();
    $template = \Piwigo\Tests\Support\TemplateTestFactory::build();

    $currentTemplate->set($template);

    expect($currentTemplate->isInitialized())->toBeTrue()
        ->and($currentTemplate->get())->toBe($template);
});

test('reset clears the published instance so get throws again', function (): void {
    $currentTemplate = new CurrentTemplate();
    $currentTemplate->set(\Piwigo\Tests\Support\TemplateTestFactory::build());
    expect($currentTemplate->isInitialized())->toBeTrue();

    $currentTemplate->reset();

    expect($currentTemplate->isInitialized())->toBeFalse();
    $currentTemplate->get();
})->throws(LogicException::class);

test('current() falls back to a memoized instance when Kernel is not booted', function (): void {
    // Memoized (not fresh-per-call), same reasoning as Translator::get()/
    // EventDispatcher::get()/CurrentUser::current(): a caller that writes
    // via current() in one call and reads via current() in a later call
    // must see the same instance, or the write would be lost. Kernel is
    // already booted by this file's own beforeEach() (real Template
    // construction needs a writable data dir) -- build the Template
    // *before* resetting Kernel (its constructor reaches CurrentPaths::
    // get(), which throws once Kernel is reset), then capture the root
    // and reset to reach the genuinely not-booted branch.
    $template = \Piwigo\Tests\Support\TemplateTestFactory::build();
    $root = CurrentPaths::get()->root;
    Kernel::reset();

    $first = CurrentTemplate::current();
    $first->set($template);

    $second = CurrentTemplate::current();

    expect($second)->toBe($first)
        ->and($second->isInitialized())->toBeTrue();

    // Restore for this test's own afterEach() (CurrentPaths::get() would
    // otherwise throw against the now-reset container).
    Kernel::boot(Paths::fromRoot($root));
});

test('current() resolves the container-shared instance once Kernel is booted', function (): void {
    $instance = Kernel::container()->get(CurrentTemplate::class);

    expect(CurrentTemplate::current())->toBe($instance);
});
