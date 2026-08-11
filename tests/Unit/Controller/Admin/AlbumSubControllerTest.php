<?php

declare(strict_types=1);

use Nyholm\Psr7\ServerRequest;
use Piwigo\Controller\Admin\AlbumSubController;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Http\ResponseReadyException;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Tests\Support\CurrentTemplateTestFactory;
use Piwigo\Tests\Support\TemplateTestFactory;

/**
 * Piwigo\Controller\Admin\AlbumSubController -- 14 constructor deps.
 * Resolved via `Kernel::container()->get()` (same rationale as
 * `UpdatesSubControllerTest.php`) -- every dep beyond `CurrentTemplate`/
 * `CurrentConfig`/`HtmlRenderingInterface` is untouched on this guard
 * path. No dedicated Integration/Browser spec of its own.
 *
 * Covers the "unknown album" fatalError() guard: a `cat_id` query param
 * with no matching row (real `CategoryRepository::findById()` read
 * against the real DB, same as every other B2-pattern class in this
 * campaign) reaches it directly, before any tab dispatch/Tabsheet setup.
 * The 4 real tabs (properties/sort_order/permissions/notification) each
 * need their own heavy renderer and are not attempted here.
 */
function albumSubControllerTestRoot(): string
{
    $root = sys_get_temp_dir() . '/piwigo-album-subcontroller-test-' . bin2hex(random_bytes(8)) . '/';
    mkdir($root, 0o777, true);
    Kernel::boot(Paths::fromRoot($root));
    CurrentConfigTestFactory::get()->dataLocation = 'data/';
    CurrentConfigTestFactory::get()->dataDirChecked = '1';

    return $root;
}

function albumSubControllerTestRrmdir(string $dir): void
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
        is_dir($path) ? albumSubControllerTestRrmdir($path) : unlink($path);
    }
    rmdir($dir);
}

test('handle() fatal-errors when cat_id does not match a real album', function (): void {
    $root = albumSubControllerTestRoot();

    try {
        $template = TemplateTestFactory::build();
        CurrentTemplateTestFactory::get()->set($template);

        $subController = Kernel::container()->get(AlbumSubController::class);
        if (! $subController instanceof AlbumSubController) {
            throw new LogicException('Container returned an unexpected type for ' . AlbumSubController::class);
        }

        $request = new ServerRequest('GET', '/admin.php?cat_id=999999999');
        $request = $request->withQueryParams([
            'cat_id' => '999999999',
        ]);

        $exception = null;
        try {
            $subController->handle($request);
        } catch (ResponseReadyException $e) {
            $exception = $e;
        }

        expect($exception)
            ->toBeInstanceOf(ResponseReadyException::class);
        if (! $exception instanceof ResponseReadyException) {
            return; // unreachable -- the assertion above already failed the test otherwise.
        }
        expect((string) $exception->response()->getBody())
            ->toContain('unknown album');
    } finally {
        CurrentTemplateTestFactory::get()->reset();
        CurrentConfigTestFactory::get()->reset();
        Kernel::reset();
        albumSubControllerTestRrmdir($root);
    }
});
