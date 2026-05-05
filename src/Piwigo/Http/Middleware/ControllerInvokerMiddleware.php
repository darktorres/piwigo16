<?php

declare(strict_types=1);

namespace Piwigo\Http\Middleware;

use Piwigo\Controller\ControllerInterface;
use Piwigo\Routing\RouteResult;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Resolves the matched controller from the DI container and invokes it.
 *
 * If the RouteResult was NOT_FOUND, or if the controller class does not yet
 * exist (Wave-A/B migration in progress), the next handler (FallbackHandler)
 * is called instead.
 */
final class ControllerInvokerMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly ContainerInterface $container)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $result = $request->getAttribute('_route_result');

        if (!$result instanceof RouteResult || !$result->isFound()) {
            return $handler->handle($request);
        }

        $fqcn = $result->handler;

        if ($fqcn === '' || !class_exists($fqcn)) {
            // Controller not yet implemented — fall through to FallbackHandler.
            return $handler->handle($request);
        }

        $controller = $this->container->get($fqcn);

        if (!$controller instanceof ControllerInterface) {
            return $handler->handle($request);
        }

        return $controller($request, $result->args);
    }
}
