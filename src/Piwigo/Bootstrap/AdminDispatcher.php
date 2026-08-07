<?php

declare(strict_types=1);

namespace Piwigo\Bootstrap;

use LogicException;
use Piwigo\Controller\Admin\AdminSubControllerInterface;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Dispatches an admin page by resolving its `config/admin_pages.php`
 * sub-controller from the container. Lives in Bootstrap/ (not admin.php
 * itself) because Kernel::container() is arch-test-restricted to
 * Bootstrap/ + root index.php (tests/Arch/StructuralTest.php) --
 * admin.php is a different root file, so it must reach the container
 * through this class, the same seam RequestBootstrap/RequestPipeline
 * already use.
 *
 * Every admin page is a config/admin_pages.php sub-controller; admin.php
 * already falls back to 'intro' for any slug not in the map, so an
 * unmapped slug reaching this point is a programming error, not user
 * input.
 */
final class AdminDispatcher
{
    public static function dispatch(string $pageSlug, ServerRequestInterface $request): void
    {
        $map = self::map();

        if (! isset($map[$pageSlug])) {
            throw new LogicException(
                "Admin page '{$pageSlug}' is not registered in config/admin_pages.php."
            );
        }

        $controller = Kernel::container()->get($map[$pageSlug]);
        if (! $controller instanceof AdminSubControllerInterface) {
            throw new LogicException(
                "Admin page '{$pageSlug}' maps to '{$map[$pageSlug]}', which does not implement AdminSubControllerInterface."
            );
        }

        $controller->handle($request);
    }

    /**
     * @return array<string, class-string<AdminSubControllerInterface>>
     */
    private static function map(): array
    {
        /** @var array<string, class-string<AdminSubControllerInterface>> $map */
        $map = require self::paths()->root . 'config/admin_pages.php';
        return $map;
    }

    /**
     * Resolves the container-shared instance directly -- this class
     * already has direct Kernel::container() access (arch-tested to
     * Bootstrap/ only).
     */
    private static function paths(): Paths
    {
        $paths = Kernel::container()->get(Paths::class);
        if (! $paths instanceof Paths) {
            throw new LogicException('Container returned an unexpected type for ' . Paths::class);
        }

        return $paths;
    }
}
