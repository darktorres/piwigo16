<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Http\Middleware;

use Piwigo\Routing\ApiRouteRegistrarInterface;
use Symfony\Component\Routing\RouteCollection;

/**
 * One-line no-op fake for the narrow ApiRouteRegistrarInterface -- exactly
 * the payoff of RoutingMiddleware depending on that interface instead of the
 * much heavier concrete PluginConfig\CurrentPluginRegistry/PluginRegistry
 * chain (see the interface's own docblock).
 */
final class RoutingMiddlewareTestNoopRegistrar implements ApiRouteRegistrarInterface
{
    #[\Override]
    public function registerApiRoutes(RouteCollection $routes): void {}
}
