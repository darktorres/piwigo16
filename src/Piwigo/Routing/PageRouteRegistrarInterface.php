<?php

declare(strict_types=1);

namespace Piwigo\Routing;

use Symfony\Component\Routing\RouteCollection;

/**
 * Narrow capability `Http\Middleware\RoutingMiddleware` depends on to let
 * already-active plugins add their own public-facing page routes
 * (`PluginConfig\PageRouteProviderInterface`) before dispatch --
 * deliberately not a direct dependency on `PluginConfig\
 * CurrentPluginRegistry`/`PluginRegistry` themselves, same reasoning as
 * this namespace's own sibling `ApiRouteRegistrarInterface`. Bound in
 * `config/container.php` to `PluginConfig\CurrentPluginRegistry` (which
 * implements this interface too, as a thin passthrough to its own
 * wrapped `PluginRegistry::registerPageRoutes()`); a Unit test can
 * instead pass a trivial one-line fake.
 */
interface PageRouteRegistrarInterface
{
    public function registerPageRoutes(RouteCollection $routes): void;
}
