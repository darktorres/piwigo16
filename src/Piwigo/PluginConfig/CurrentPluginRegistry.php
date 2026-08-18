<?php

declare(strict_types=1);

namespace Piwigo\PluginConfig;

use LogicException;
use Override;
use Piwigo\Routing\ApiRouteRegistrarInterface;
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
 * Implements `Routing\ApiRouteRegistrarInterface` (P29.6) as a thin
 * passthrough to `PluginRegistry::registerApiRoutes()` -- bound to that
 * interface in `config/container.php` so `Http\Middleware\
 * RoutingMiddleware` can depend on the narrow capability instead of this
 * whole class.
 */
final class CurrentPluginRegistry implements ApiRouteRegistrarInterface
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

    #[Override]
    public function registerApiRoutes(RouteCollection $routes): void
    {
        $this->get()
            ->registerApiRoutes($routes);
    }
}
