<?php

declare(strict_types=1);

namespace Piwigo\Http\Middleware;

use Override;
use Piwigo\Routing\Router;
use Piwigo\Routing\RouteResult;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class RoutingMiddleware implements MiddlewareInterface
{
    public function __construct(
        private Router $router
    ) {}

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $result = $this->router->dispatch($request);

        return $handler->handle($request->withAttribute(RouteResult::class, $result));
    }
}
