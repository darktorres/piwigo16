<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Http\Middleware;

use Piwigo\Routing\PageRouteRegistrarInterface;
use Symfony\Component\Routing\RouteCollection;

/**
 * PageRouteRegistrarInterface counterpart to RoutingMiddlewareTestNoopRegistrar;
 * see that class's docblock for why these fakes are one-liners.
 */
final class RoutingMiddlewareTestNoopPageRegistrar implements PageRouteRegistrarInterface
{
    #[\Override]
    public function registerPageRoutes(RouteCollection $routes): void {}
}
