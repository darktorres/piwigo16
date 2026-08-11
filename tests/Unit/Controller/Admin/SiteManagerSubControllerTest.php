<?php

declare(strict_types=1);

use Nyholm\Psr7\ServerRequest;
use Piwigo\Config\CurrentConfig;
use Piwigo\Controller\Admin\SiteManagerSubController;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Http\ResponseReadyException;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Tests\Support\CurrentTemplateTestFactory;
use Piwigo\Tests\Support\TemplateTestFactory;

/**
 * Piwigo\Controller\Admin\SiteManagerSubController -- 13 constructor deps.
 * Resolves the whole SubController via `Kernel::container()->get()`
 * rather than field-by-field construction (same rationale as
 * `UpdatesSubControllerTest.php`), since every dep beyond `CurrentTemplate`/
 * `CurrentConfig`/`HtmlRenderingInterface` is untouched on this guard
 * path. No dedicated Integration/Browser spec of its own.
 *
 * Covers the "synchronization is disabled" fatalError() guard. Unlike
 * `UpdatesSubController`'s equivalent guard, `$this->currentTemplate->get()`
 * runs BEFORE the config check, so a real Template must already be set on
 * `CurrentTemplateTestFactory::get()` even to reach it. Every other branch (new
 * site creation, site actions, the sites-list happy path) needs real DB
 * rows/filesystem paths and is not attempted here.
 */
function siteManagerSubControllerTestRoot(): string
{
    $root = sys_get_temp_dir() . '/piwigo-site-manager-subcontroller-test-' . bin2hex(random_bytes(8)) . '/';
    mkdir($root, 0o777, true);
    Kernel::boot(Paths::fromRoot($root));
    CurrentConfigTestFactory::get()->dataLocation = 'data/';
    CurrentConfigTestFactory::get()->dataDirChecked = '1';

    return $root;
}

function siteManagerSubControllerTestRrmdir(string $dir): void
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
        is_dir($path) ? siteManagerSubControllerTestRrmdir($path) : unlink($path);
    }
    rmdir($dir);
}

test('handle() fatal-errors when synchronization is disabled', function (): void {
    $root = siteManagerSubControllerTestRoot();

    try {
        $template = TemplateTestFactory::build();
        CurrentTemplateTestFactory::get()->set($template);

        $currentConfig = CurrentConfigTestFactory::get();
        $currentConfig->enableSynchronization = false;

        $subController = Kernel::container()->get(SiteManagerSubController::class);
        if (! $subController instanceof SiteManagerSubController) {
            throw new LogicException('Container returned an unexpected type for ' . SiteManagerSubController::class);
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
            ->toContain('synchronization is disabled');
    } finally {
        CurrentTemplateTestFactory::get()->reset();
        CurrentConfigTestFactory::get()->reset();
        Kernel::reset();
        siteManagerSubControllerTestRrmdir($root);
    }
});
