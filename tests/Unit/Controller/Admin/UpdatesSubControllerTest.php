<?php

declare(strict_types=1);

use LogicException;
use Nyholm\Psr7\ServerRequest;
use Piwigo\Config\CurrentConfig;
use Piwigo\Controller\Admin\UpdatesSubController;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Http\ResponseReadyException;
use Piwigo\Tests\Support\CurrentConfigTestFactory;

/**
 * Piwigo\Controller\Admin\UpdatesSubController -- 13 constructor deps, 2
 * of which (`ExtensionUpdateChecker`, `UpdatesPwgPageRenderer`) each pull
 * in their own multi-level tree of domain services (`PemCatalog`,
 * `CoreUpdateService` -> `ActivityService`/`UserService`/`MailService`,
 * etc.) -- hand-constructing that whole graph just to reach an early
 * guard would be strictly worse than trusting the real, already-tested
 * container wiring (`ContainerSmokeTest.php`) to build it, so this
 * resolves the whole SubController via `Kernel::container()->get()`
 * instead of the field-by-field pattern used for lighter classes
 * elsewhere in this campaign -- the same real construction path
 * `AdminShell::runDispatch()` uses in production. No dedicated
 * Integration/Browser spec of its own.
 *
 * Covers the "update system is disabled" fatalError() guard -- both
 * `enableExtensionsInstall`/`enableCoreUpdate` false reaches it before
 * any of the heavy deps above are ever touched. The tab-dispatch happy
 * path (either `UpdatesExtPageRenderer` or `UpdatesPwgPageRenderer`) is
 * not attempted here -- both need real PEM/core-update network calls.
 */
function updatesSubControllerTestRoot(): string
{
    $root = sys_get_temp_dir() . '/piwigo-updates-subcontroller-test-' . bin2hex(random_bytes(8)) . '/';
    mkdir($root, 0o777, true);
    Kernel::boot(Paths::fromRoot($root));

    return $root;
}

function updatesSubControllerTestRrmdir(string $dir): void
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
        is_dir($path) ? updatesSubControllerTestRrmdir($path) : unlink($path);
    }
    rmdir($dir);
}

test('handle() fatal-errors when both extension install and core update are disabled', function (): void {
    $root = updatesSubControllerTestRoot();

    try {
        $currentConfig = CurrentConfigTestFactory::get();
        $currentConfig->setEnableExtensionsInstall(false);
        $currentConfig->setEnableCoreUpdate(false);

        $subController = Kernel::container()->get(UpdatesSubController::class);
        if (! $subController instanceof UpdatesSubController) {
            throw new LogicException('Container returned an unexpected type for ' . UpdatesSubController::class);
        }

        $exception = null;
        try {
            $subController->handle(new ServerRequest('GET', '/admin.php'));
        } catch (ResponseReadyException $e) {
            $exception = $e;
        }

        expect($exception)->toBeInstanceOf(ResponseReadyException::class);
        if (! $exception instanceof ResponseReadyException) {
            return; // unreachable -- the assertion above already failed the test otherwise.
        }
        expect((string) $exception->response()->getBody())->toContain('update system is disabled');
    } finally {
        CurrentConfigTestFactory::get()->reset();
        Kernel::reset();
        updatesSubControllerTestRrmdir($root);
    }
});
