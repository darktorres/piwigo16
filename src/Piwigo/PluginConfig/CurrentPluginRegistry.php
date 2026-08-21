<?php

declare(strict_types=1);

namespace Piwigo\PluginConfig;

use LogicException;
use Override;
use Piwigo\Routing\ApiRouteRegistrarInterface;
use Piwigo\Routing\PageRouteRegistrarInterface;
use Symfony\Component\Routing\RouteCollection;

/**
 * Container-shared instance holding the current request's `PluginRegistry`
 * reference -- same shape/purpose as `Config\CurrentConfigService`:
 * `Http\Middleware\PluginBootstrapMiddleware` builds `PluginRegistry` itself
 * manually, threading the request's own shared `Connection` through it
 * (see that class's own private `pluginRegistry()` method for why), so a
 * later container-autowired reader -- `Controller\Admin\
 * PluginSubController`, resolved fresh per request via the DI
 * container, not through that same manual `$conn`-scoped path -- would
 * otherwise get a *different*, never-`bootActive()`d `PluginRegistry`
 * instance whose `getBootedInstance()` cache is always empty. `set()`
 * here, called right after `bootActive()` runs, is what makes the one
 * real, already-booted instance reachable from that reader instead
 * (`Admin\LoadedPluginsMiddleware`, immediately after `PluginBootstrap
 * Middleware` in the real pipeline, is the other real reader).
 *
 * Implements `Routing\ApiRouteRegistrarInterface`/`PageRouteRegistrarInterface`
 * (P29.6/P43-E) as thin passthroughs to `PluginRegistry::registerApiRoutes()`/
 * `registerPageRoutes()` -- bound to those interfaces in
 * `config/container.php` so `Http\Middleware\RoutingMiddleware` can
 * depend on the narrow capability instead of this whole class.
 */
final class CurrentPluginRegistry implements ApiRouteRegistrarInterface, PageRouteRegistrarInterface
{
    private ?PluginRegistry $registry = null;

    public function get(): PluginRegistry
    {
        if (! $this->registry instanceof PluginRegistry) {
            throw new LogicException('CurrentPluginRegistry not initialised -- call Piwigo\Http\Middleware\PluginBootstrapMiddleware::process() first.');
        }

        return $this->registry;
    }

    public function set(PluginRegistry $registry): void
    {
        $this->registry = $registry;
    }

    /**
     * `Bootstrap\AdminDispatcher::pageMap()`'s own guard against reading
     * this before `PluginBootstrapMiddleware::process()` has run (e.g. a
     * Unit test constructing an admin sub-controller dispatch directly,
     * with no real request pipeline behind it) -- same shape as
     * `Template\CurrentTemplate::isInitialized()`.
     */
    public function isInitialized(): bool
    {
        return $this->registry instanceof PluginRegistry;
    }

    #[Override]
    public function registerApiRoutes(RouteCollection $routes): void
    {
        $this->get()
            ->registerApiRoutes($routes);
    }

    #[Override]
    public function registerPageRoutes(RouteCollection $routes): void
    {
        $this->get()
            ->registerPageRoutes($routes);
    }
}
