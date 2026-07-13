<?php

declare(strict_types=1);

namespace Piwigo\Bootstrap;

use Piwigo\Controller\Admin\AdminSubControllerInterface;
use Piwigo\Core\Kernel;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Replaces admin.php's `include PHPWG_ROOT_PATH . 'admin/' . $page_slug .
 * '.php';` dispatch. Lives in Bootstrap/ (not admin.php itself) because
 * Kernel::container() is arch-test-restricted to Bootstrap/ + root
 * index.php (tests/Arch/StructuralTest.php) -- admin.php is a different
 * root file, so it must reach the container through this class, the same
 * seam CommonBootstrap/RequestPipeline already use.
 *
 * $pageSlug is trusted pre-validated: admin.php's own
 * `/^[a-z_]*$/` + is_file() check (its "Variables init" section) already
 * ran before this is called, same guard it always used to gate the raw
 * `include`.
 *
 * Slugs not yet in config/admin_pages.php fall back to the legacy include
 * unchanged -- lets P21 migrate one batch at a time without ever breaking
 * an un-migrated page mid-phase.
 */
final class AdminDispatcher
{
    public static function dispatch(string $pageSlug, ServerRequestInterface $request): void
    {
        $map = self::map();

        if (! isset($map[$pageSlug])) {
            include PHPWG_ROOT_PATH . 'admin/' . $pageSlug . '.php';
            return;
        }

        $controller = Kernel::container()->get($map[$pageSlug]);
        if (! $controller instanceof AdminSubControllerInterface) {
            throw new \LogicException(
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
        $map = require PHPWG_ROOT_PATH . 'config/admin_pages.php';
        return $map;
    }
}
