<?php

declare(strict_types=1);

namespace Piwigo\Http\Middleware;

use Piwigo\Controller\ControllerInterface;
use Piwigo\Http\ResponseFactory;
use Piwigo\Routing\RouteResult;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Resolves the matched controller from the DI container and invokes it.
 * Returns a 404 response for unmatched routes.
 */
final readonly class ControllerInvokerMiddleware implements MiddlewareInterface
{
    public function __construct(private ContainerInterface $container)
    {
    }

    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $result = $request->getAttribute('_route_result');

        if (!$result instanceof RouteResult || !$result->isFound()) {
            return ResponseFactory::html('<h1>404 Not Found</h1>', 404);
        }

        $controller = $this->container->get($result->handler);

        if (!$controller instanceof ControllerInterface) {
            throw new \LogicException(sprintf('Controller "%s" does not implement ControllerInterface.', $result->handler));
        }

        return $controller($request, $result->args);
    }
}
