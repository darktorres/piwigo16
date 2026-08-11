<?php

declare(strict_types=1);

use Nyholm\Psr7\ServerRequest;
use Piwigo\Admin\LoadedPlugins;
use Piwigo\Controller\Admin\PluginSubController;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Http\ResponseReadyException;
use Piwigo\Tests\Support\HtmlServiceTestFactory;
use Piwigo\Validation\InputValidator;

/**
 * Piwigo\Controller\Admin\PluginSubController -- 4 constructor deps, no
 * template rendering at all. No dedicated Integration/Browser spec of
 * its own.
 *
 * Covers the "plugin not active" fatalError() guard -- a well-formed
 * ?section= value (passes InputValidator's own charset/segment-count
 * checks cleanly) against an explicitly empty LoadedPlugins::set([])
 * reaches it directly. The "missing file" fatalError() branch and the
 * real include_once happy path both need a real plugin file on disk,
 * not attempted here.
 */
function pluginSubControllerTestRrmdir(string $dir): void
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
        is_dir($path) ? pluginSubControllerTestRrmdir($path) : unlink($path);
    }
    rmdir($dir);
}

test('handle() fatal-errors when the requested plugin is not active', function (): void {
    $_GET['section'] = 'my_plugin/settings.php';
    $root = sys_get_temp_dir() . '/piwigo-plugin-subcontroller-test-' . bin2hex(random_bytes(8)) . '/';
    mkdir($root, 0o777, true);
    Kernel::boot(Paths::fromRoot($root));

    try {
        $loadedPlugins = new LoadedPlugins();
        $loadedPlugins->set([]);

        $subController = new PluginSubController(
            $loadedPlugins,
            HtmlServiceTestFactory::build(),
            new InputValidator(),
            Paths::fromRoot(sys_get_temp_dir()),
        );

        $exception = null;
        try {
            $subController->handle(new ServerRequest('GET', '/admin.php'));
        } catch (ResponseReadyException $e) {
            $exception = $e;
        }

        expect($exception)
            ->toBeInstanceOf(ResponseReadyException::class);
        if (! $exception instanceof ResponseReadyException) {
            return; // unreachable -- the assertion above already failed the test otherwise.
        }
        expect((string) $exception->response()->getBody())
            ->toContain('Invalid URL - plugin my_plugin not active');
    } finally {
        unset($_GET['section']);
        Kernel::reset();
        pluginSubControllerTestRrmdir($root);
    }
});
