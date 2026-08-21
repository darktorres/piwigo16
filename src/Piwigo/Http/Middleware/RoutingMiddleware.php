<?php

declare(strict_types=1);

namespace Piwigo\Http\Middleware;

use Override;
use Piwigo\Routing\ApiRouteRegistrarInterface;
use Piwigo\Routing\PageRouteRegistrarInterface;
use Piwigo\Routing\Router;
use Piwigo\Routing\RouteResult;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class RoutingMiddleware implements MiddlewareInterface
{
    public function __construct(
        private Router $router,
        private ApiRouteRegistrarInterface $apiRouteRegistrar,
        private PageRouteRegistrarInterface $pageRouteRegistrar,
    ) {}

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Deliberately called here, not inside Router::class's own
        // container factory (P29.6): PluginBootstrapMiddleware::process()
        // -- which is what makes an active plugin's own registerApiRoutes()/
        // registerPageRoutes() reachable at all, via CurrentPluginRegistry --
        // has already run by the time THIS runs (BOOTSTRAP_MIDDLEWARE
        // precedes RoutingMiddleware in Bootstrap\RequestPipeline::
        // DEFAULT_MIDDLEWARE), but Router::class itself gets constructed
        // much earlier, when Bootstrap\RequestPipeline::handle()'s own
        // array_map() eagerly resolves every middleware instance up front
        // -- before ANY middleware's own process() (including
        // PluginBootstrapMiddleware's) has actually run.
        $this->apiRouteRegistrar->registerApiRoutes($this->router->routes());
        $this->pageRouteRegistrar->registerPageRoutes($this->router->routes());

        $result = $this->router->dispatch($request);

        return $handler->handle($request->withAttribute(RouteResult::class, $result));
    }
}
