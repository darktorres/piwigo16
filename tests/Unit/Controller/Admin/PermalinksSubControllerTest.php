<?php

declare(strict_types=1);

use Nyholm\Psr7\ServerRequest;
use Piwigo\Controller\Admin\PermalinksSubController;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Http\ResponseReadyException;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Tests\Support\TemplateTestFactory;

/**
 * Piwigo\Controller\Admin\PermalinksSubController -- 11 constructor deps.
 * Resolved via `Kernel::container()->get()` (same rationale as
 * `UpdatesSubControllerTest.php`). No dedicated Integration/Browser
 * spec of its own.
 *
 * Covers `CsrfService::checkOrFail()`'s own "submitted but wrong token"
 * branch (same reusable pattern as `MaintenanceSubControllerTest.php`):
 * a `set_permalink` submission with a valid `cat_id` gates the real CSRF
 * check; a mismatched `pwg_token` reaches
 * `HtmlRenderingInterface::accessDenied()`, which with the default
 * (uninitialized) `CurrentUser` takes its cheap `RedirectService::
 * redirectHttp()` branch. The real permalink list/edit happy path needs
 * real category and permalink rows and is not attempted here.
 */
function permalinksSubControllerTestRoot(): string
{
    $root = sys_get_temp_dir() . '/piwigo-permalinks-subcontroller-test-' . bin2hex(random_bytes(8)) . '/';
    mkdir($root, 0o777, true);
    Kernel::boot(Paths::fromRoot($root));
    CurrentConfigTestFactory::get()->dataLocation = 'data/';
    CurrentConfigTestFactory::get()->dataDirChecked = '1';

    return $root;
}

function permalinksSubControllerTestRrmdir(string $dir): void
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
        is_dir($path) ? permalinksSubControllerTestRrmdir($path) : unlink($path);
    }
    rmdir($dir);
}

test('handle() redirects when a permalink set is submitted with the wrong CSRF token', function (): void {
    $root = permalinksSubControllerTestRoot();
    $_POST['set_permalink'] = '1';
    $_POST['cat_id'] = '1';
    $_REQUEST['pwg_token'] = 'wrong-token';

    try {
        $template = TemplateTestFactory::build();
        CurrentTemplate::current()->set($template);

        $subController = Kernel::container()->get(PermalinksSubController::class);
        if (! $subController instanceof PermalinksSubController) {
            throw new LogicException('Container returned an unexpected type for ' . PermalinksSubController::class);
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
        unset($_POST['set_permalink'], $_POST['cat_id'], $_REQUEST['pwg_token']);
        CurrentTemplate::current()->reset();
        CurrentConfigTestFactory::get()->reset();
        Kernel::reset();
        permalinksSubControllerTestRrmdir($root);
    }
});
