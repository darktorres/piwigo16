<?php

declare(strict_types=1);

namespace Piwigo\Http\Middleware;

use Piwigo\Http\ResponseFactory;
use Piwigo\Routing\RouteResult;
use Piwigo\Routing\Router;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Dispatches the request to the matching route.
 *
 * Attaches the RouteResult as the '_route_result' request attribute so that
 * ControllerInvokerMiddleware can resolve and call the controller without
 * re-running the matcher.
 *
 * On METHOD_NOT_ALLOWED the 405 response is returned directly.
 * On NOT_FOUND the fallback handler is invoked (returns 404).
 */
final readonly class RoutingMiddleware implements MiddlewareInterface
{
    public function __construct(private Router $router)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path   = is_string($request->getAttribute('_route_path')) ? $request->getAttribute('_route_path') : '/';
        $method = $request->getMethod();

        $result = $this->router->dispatch($method, $path);

        if ($result->status === RouteResult::METHOD_NOT_ALLOWED) {
            // Short-circuit: emit 405 without calling the controller or fallback
            return ResponseFactory::create(405, 'Method Not Allowed');
        }

        return $handler->handle(
            $request->withAttribute('_route_result', $result)
        );
    }
}
