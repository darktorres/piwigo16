<?php

declare(strict_types=1);

use Nyholm\Psr7\ServerRequest;
use Piwigo\Bootstrap\AdminDispatcher;
use Piwigo\Controller\Admin\PhotosAddSubController;
use Piwigo\Core\CurrentPaths;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Tests\Support\KernelContainerOverride;

/**
 * Piwigo\Bootstrap\AdminDispatcher::dispatch() -- had zero dedicated
 * coverage (see /home/torres/.claude/plans/piped-enchanting-spark.md, Wave
 * 1). The happy path (a mapped page slug reaching a real controller) is
 * already exhaustively exercised by every one of the Browser suite's
 * admin.php page-load tests; this file covers only the class's own docblock-
 * documented "programming error, not user input" guard -- unreachable
 * through the real admin.php entry point (which always resolves to a
 * mapped slug or the 'intro' fallback before calling dispatch()), but a
 * real, reachable branch for any direct caller of this public static
 * method.
 */
afterEach(function (): void {
    CurrentPaths::reset();
    Kernel::reset();
});

test('dispatch throws for a page slug not registered in config/admin_pages.php', function (): void {
    // AdminDispatcher::map() reads config/admin_pages.php off
    // CurrentPaths::get()->root -- a shared, process-wide static -- set
    // explicitly rather than relying on some earlier-run test file to
    // have already done so.
    CurrentPaths::set(Paths::fromRoot(dirname(__DIR__, 3)));

    expect(fn () => AdminDispatcher::dispatch('not-a-real-registered-slug', new ServerRequest('GET', '/admin.php')))
        ->toThrow(LogicException::class, "Admin page 'not-a-real-registered-slug' is not registered in config/admin_pages.php.");
});

test('dispatch throws when a mapped page resolves to a class that does not implement AdminSubControllerInterface', function (): void {
    // 'photos_add' is a real, registered slug in config/admin_pages.php,
    // mapped to PhotosAddSubController -- KernelContainerOverride rebinds
    // just that one class to a plain stdClass so the container really
    // does hand back something that fails the
    // `instanceof AdminSubControllerInterface` check, the same shape as
    // AdminAccessorTest.php's own "unexpected type" cases.
    //
    // CurrentPaths::set() must run *inside* the callback:
    // KernelContainerOverride::with() itself calls Kernel::reset() first,
    // which cascades into CurrentPaths::reset() (see Kernel::reset()'s own
    // docblock) -- setting it beforehand would just get wiped again
    // before map() ever reads it.
    KernelContainerOverride::withWrongTypeFor(
        PhotosAddSubController::class,
        static function (): void {
            CurrentPaths::set(Paths::fromRoot(dirname(__DIR__, 3)));
            AdminDispatcher::dispatch('photos_add', new ServerRequest('GET', '/admin.php'));
        }
    );
})->throws(
    LogicException::class,
    "Admin page 'photos_add' maps to '" . PhotosAddSubController::class . "', which does not implement AdminSubControllerInterface."
);
