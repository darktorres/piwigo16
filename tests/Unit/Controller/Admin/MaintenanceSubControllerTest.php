<?php

declare(strict_types=1);

use Nyholm\Psr7\ServerRequest;
use Piwigo\Controller\Admin\MaintenanceSubController;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Http\ResponseReadyException;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Tests\Support\TemplateTestFactory;

/**
 * Piwigo\Controller\Admin\MaintenanceSubController -- 13 constructor
 * deps. Resolved via `Kernel::container()->get()` (same rationale as
 * `UpdatesSubControllerTest.php`). No dedicated Integration/Browser
 * spec of its own.
 *
 * Covers `CsrfService::checkOrFail()`'s own "submitted but wrong token"
 * branch: `?action=` set (which gates the real, load-bearing CSRF check
 * -- see `MaintenanceDispatchRequest`'s own docblock) plus a mismatched
 * `pwg_token` makes `CsrfService::check()` return `false`, which calls
 * `HtmlRenderingInterface::accessDenied()`. With the default
 * (uninitialized) `CurrentUser`, `accessDenied()` takes its cheap
 * `RedirectService::redirectHttp()` branch directly -- unlike the
 * "missing token" branch (routes through the expensive
 * `redirectHtml()`, not attempted here), this needs no admin-page
 * bootstrap at all. The 3 real tabs (actions/env/sys) and every real
 * maintenance action are not attempted here.
 */
function maintenanceSubControllerTestRoot(): string
{
    $root = sys_get_temp_dir() . '/piwigo-maintenance-subcontroller-test-' . bin2hex(random_bytes(8)) . '/';
    mkdir($root, 0o777, true);
    Kernel::boot(Paths::fromRoot($root));
    CurrentConfigTestFactory::get()->setDataLocation('data/');
    CurrentConfigTestFactory::get()->setDataDirChecked('1');

    return $root;
}

function maintenanceSubControllerTestRrmdir(string $dir): void
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
        is_dir($path) ? maintenanceSubControllerTestRrmdir($path) : unlink($path);
    }
    rmdir($dir);
}

test('handle() redirects when a maintenance action is submitted with the wrong CSRF token', function (): void {
    $root = maintenanceSubControllerTestRoot();
    $_GET['action'] = 'purgehistory';
    $_REQUEST['pwg_token'] = 'wrong-token';

    try {
        $template = TemplateTestFactory::build();
        CurrentTemplate::current()->set($template);

        $subController = Kernel::container()->get(MaintenanceSubController::class);
        if (! $subController instanceof MaintenanceSubController) {
            throw new LogicException('Container returned an unexpected type for ' . MaintenanceSubController::class);
        }

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
        expect($exception->response()->getStatusCode())
            ->toBe(302);
    } finally {
        unset($_GET['action'], $_REQUEST['pwg_token']);
        CurrentTemplate::current()->reset();
        CurrentConfigTestFactory::get()->reset();
        Kernel::reset();
        maintenanceSubControllerTestRrmdir($root);
    }
});
