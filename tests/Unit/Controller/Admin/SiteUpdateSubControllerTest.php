<?php

declare(strict_types=1);

use Nyholm\Psr7\ServerRequest;
use Piwigo\Controller\Admin\SiteUpdateSubController;
use Piwigo\Core\CurrentLogger;
use Piwigo\Core\Kernel;
use Piwigo\Core\Logger;
use Piwigo\Core\Paths;
use Piwigo\Http\ResponseReadyException;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Tests\Support\CurrentTemplateTestFactory;
use Piwigo\Tests\Support\TemplateTestFactory;

/**
 * Piwigo\Controller\Admin\SiteUpdateSubController -- 22 constructor deps.
 * Resolved via `Kernel::container()->get()` (same rationale as
 * `UpdatesSubControllerTest.php`) -- every dep beyond `CurrentTemplate`/
 * `CurrentConfig`/`HtmlRenderingInterface`/`CurrentLogger` is untouched
 * on this guard path. No dedicated Integration/Browser spec of its own.
 *
 * Covers the "site param missing or invalid" fatalError() guard --
 * `?site=` unset (`is_numeric(null)` is false), reached with
 * synchronization left enabled (the default) so the earlier
 * "synchronization is disabled" guard (already covered on the sibling
 * `SiteManagerSubController`, same `enableSynchronization()` check) is
 * not what fires here. Every branch past this point needs a real site
 * row and touches real sync/scan logic, not attempted here.
 */
function siteUpdateSubControllerTestRoot(): string
{
    $root = sys_get_temp_dir() . '/piwigo-site-update-subcontroller-test-' . bin2hex(random_bytes(8)) . '/';
    mkdir($root, 0o777, true);
    Kernel::boot(Paths::fromRoot($root));
    CurrentConfigTestFactory::get()->dataLocation = 'data/';
    CurrentConfigTestFactory::get()->dataDirChecked = '1';

    return $root;
}

function siteUpdateSubControllerTestRrmdir(string $dir): void
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
        is_dir($path) ? siteUpdateSubControllerTestRrmdir($path) : unlink($path);
    }
    rmdir($dir);
}

test('handle() fatal-errors when the site query param is missing', function (): void {
    $root = siteUpdateSubControllerTestRoot();
    unset($_GET['site']);

    try {
        $template = TemplateTestFactory::build();
        CurrentTemplateTestFactory::get()->set($template);

        $currentLogger = Kernel::container()->get(CurrentLogger::class);
        if (! $currentLogger instanceof CurrentLogger) {
            throw new LogicException('Container returned an unexpected type for ' . CurrentLogger::class);
        }
        $currentLogger->set(new Logger([
            'severity' => Logger::OFF,
        ]));

        $subController = Kernel::container()->get(SiteUpdateSubController::class);
        if (! $subController instanceof SiteUpdateSubController) {
            throw new LogicException('Container returned an unexpected type for ' . SiteUpdateSubController::class);
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
        expect((string) $exception->response()->getBody())
            ->toContain('site param missing or invalid');
    } finally {
        unset($_GET['site']);
        CurrentTemplateTestFactory::get()->reset();
        CurrentConfigTestFactory::get()->reset();
        Kernel::reset();
        siteUpdateSubControllerTestRrmdir($root);
    }
});
