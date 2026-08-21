<?php

declare(strict_types=1);

namespace Piwigo\Bootstrap;

use LogicException;
use Piwigo\Controller\Admin\AdminSubControllerInterface;
use Piwigo\Controller\Admin\Projection\AdminContentPageContext;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\PluginConfig\CurrentPluginRegistry;
use Piwigo\Template\CurrentTemplate;
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
 * Every admin page is a config/admin_pages.php sub-controller, or (P43-E)
 * an active plugin's own contributed slug (`PluginConfig\
 * AdminPageProviderInterface`, manifest `hasAdminPages: true`), merged
 * in by `pageMap()`; `Admin\AdminShell::runDispatch()` -- the one real
 * caller of `dispatch()` -- validates the requested `?page=` slug
 * against that same merged map (not the static file directly) before
 * ever reaching this class, so a plugin-contributed slug passes that
 * check too. admin.php already falls back to 'intro' for any slug not in
 * the map, so an unmapped slug reaching this point is a programming
 * error, not user input.
 */
final class AdminDispatcher
{
    public static function dispatch(string $pageSlug, ServerRequestInterface $request): void
    {
        $map = self::pageMap();

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

        $result = $controller->handle($request);

        self::currentTemplate()->get()->assignContext(new AdminContentPageContext(
            adminContent: $result->content,
            adminPageTitle: $result->pageTitle,
            helpUrl: $result->helpUrl,
        ));
    }

    /**
     * The full, merged admin-page-slug registry: the static
     * `config/admin_pages.php` map plus any active plugin's own
     * contributed pages. `Admin\AdminShell`'s own `?page=` slug
     * validation reads this same merged map (not the static file
     * directly) so a plugin-contributed slug passes that check too, not
     * just this class's own `dispatch()`.
     *
     * Gracefully skips the plugin half when `CurrentPluginRegistry`
     * isn't initialised yet (e.g. a Unit test dispatching directly, with
     * no real request pipeline -- `PluginBootstrapMiddleware::process()`
     * always runs before `Admin\AdminShell::runDispatch()` in the real
     * `admin.php` entry point, so this is never reached in production).
     *
     * @return array<string, class-string<AdminSubControllerInterface>>
     */
    public static function pageMap(): array
    {
        $pages = self::staticMap();

        $currentPluginRegistry = self::currentPluginRegistry();
        if (! $currentPluginRegistry->isInitialized()) {
            return $pages;
        }

        foreach ($currentPluginRegistry->get()->adminPages() as $slug => $class) {
            if (isset($pages[$slug])) {
                throw new LogicException(
                    "Plugin-contributed admin page slug '{$slug}' collides with an existing config/admin_pages.php entry."
                );
            }

            /** @var class-string<AdminSubControllerInterface> $class */
            $pages[$slug] = $class;
        }

        return $pages;
    }

    /**
     * @return array<string, class-string<AdminSubControllerInterface>>
     */
    private static function staticMap(): array
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

    private static function currentTemplate(): CurrentTemplate
    {
        $currentTemplate = Kernel::container()->get(CurrentTemplate::class);
        if (! $currentTemplate instanceof CurrentTemplate) {
            throw new LogicException('Container returned an unexpected type for ' . CurrentTemplate::class);
        }

        return $currentTemplate;
    }

    private static function currentPluginRegistry(): CurrentPluginRegistry
    {
        $currentPluginRegistry = Kernel::container()->get(CurrentPluginRegistry::class);
        if (! $currentPluginRegistry instanceof CurrentPluginRegistry) {
            throw new LogicException('Container returned an unexpected type for ' . CurrentPluginRegistry::class);
        }

        return $currentPluginRegistry;
    }
}
