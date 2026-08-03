<?php

declare(strict_types=1);

use Nyholm\Psr7\ServerRequest;
use Piwigo\Bootstrap\AdminDispatcher;
use Piwigo\Controller\Admin\AdminSubControllerInterface;
use Piwigo\Controller\Admin\PhotosAddSubController;
use Piwigo\Core\CurrentPaths;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Tests\Support\KernelContainerOverride;
use Psr\Http\Message\ServerRequestInterface;

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
    Kernel::reset();
});

test('dispatch throws for a page slug not registered in config/admin_pages.php', function (): void {
    // AdminDispatcher::map() reads config/admin_pages.php off
    // CurrentPaths::get()->root -- a shared, process-wide static -- set
    // explicitly rather than relying on some earlier-run test file to
    // have already done so.
    Kernel::boot(Paths::fromRoot(dirname(__DIR__, 3)));

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
    // Paths::class must be bound alongside the deliberately-wrong
    // PhotosAddSubController::class override -- KernelContainerOverride::
    // with() rebuilds the container from scratch with no Paths given by
    // default, and CurrentPaths (Phase 3) is a pure shim with no state of
    // its own to survive that rebuild.
    KernelContainerOverride::with(
        [
            PhotosAddSubController::class => new \stdClass(),
            Paths::class => Paths::fromRoot(dirname(__DIR__, 3)),
        ],
        static function (): void {
            AdminDispatcher::dispatch('photos_add', new ServerRequest('GET', '/admin.php'));
        }
    );
})->throws(
    LogicException::class,
    "Admin page 'photos_add' maps to '" . PhotosAddSubController::class . "', which does not implement AdminSubControllerInterface."
);

test('dispatch resolves the map relative to CurrentPaths root and calls handle() on a real, correctly-typed controller', function (): void {
    // Both remaining untested mutations on this class live here: (1)
    // `InstanceOfToFalse` on the `instanceof AdminSubControllerInterface`
    // check -- the two tests above only prove the *throw* side (a real
    // wrong-type container binding); nothing yet proves a real, correctly
    // -typed controller is let through. (2) `ConcatRemoveLeft` on
    // `CurrentPaths::get()->root . 'config/admin_pages.php'` -- under
    // `vendor/bin/pest`, the CLI's CWD already *is* the repo root, so a
    // mutated bare `'config/admin_pages.php'` would still resolve (via a
    // CWD-relative require) to the very same real file, silently masking
    // the mutation. A decoy root elsewhere, with its own
    // config/admin_pages.php mapping a slug the real one doesn't have,
    // forces the two to diverge: this only succeeds if `map()` genuinely
    // read the file *at CurrentPaths::get()->root*.
    $decoyRoot = sys_get_temp_dir() . '/piwigo-admin-dispatch-decoy-' . bin2hex(random_bytes(8)) . '/';
    mkdir($decoyRoot . 'config', 0755, true);
    file_put_contents(
        $decoyRoot . 'config/admin_pages.php',
        "<?php\n\ndeclare(strict_types=1);\n\nreturn ['decoy_slug' => stdClass::class];\n"
    );

    try {
        $stub = new class implements AdminSubControllerInterface {
            public bool $handled = false;

            public ?ServerRequestInterface $request = null;

            public function handle(ServerRequestInterface $request): void
            {
                $this->handled = true;
                $this->request = $request;
            }
        };

        $request = new ServerRequest('GET', '/admin.php');

        KernelContainerOverride::with(
            [
                stdClass::class => $stub,
                Paths::class => Paths::fromRoot($decoyRoot),
            ],
            function () use ($request): void {
                AdminDispatcher::dispatch('decoy_slug', $request);
            }
        );

        expect($stub->handled)->toBeTrue()
            ->and($stub->request)->toBe($request);
    } finally {
        unlink($decoyRoot . 'config/admin_pages.php');
        rmdir($decoyRoot . 'config');
        rmdir($decoyRoot);
    }
});
