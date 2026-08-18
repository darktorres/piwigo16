<?php

declare(strict_types=1);

namespace Piwigo\Routing;

use Symfony\Component\Routing\RouteCollection;

/**
 * Narrow capability `Http\Middleware\RoutingMiddleware` depends on to let
 * already-active plugins add their own routes (`PluginConfig\
 * ApiRouteProviderInterface`) before dispatch -- deliberately not a direct
 * dependency on `PluginConfig\CurrentPluginRegistry`/`PluginRegistry`
 * themselves, matching this codebase's own established pattern elsewhere
 * (`Core\MailerInterface`/`Core\HtmlRenderingInterface`/etc.): a caller
 * that only needs one narrow capability shouldn't have to depend on (or,
 * for a test double, construct) the full concrete machinery behind it.
 * Bound in `config/container.php` to `PluginConfig\CurrentPluginRegistry`
 * (which implements this interface as a thin passthrough to its own
 * wrapped `PluginRegistry::registerApiRoutes()`); a Unit test can instead
 * pass a trivial one-line fake.
 */
interface ApiRouteRegistrarInterface
{
    public function registerApiRoutes(RouteCollection $routes): void;
}
