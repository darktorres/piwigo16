<?php

declare(strict_types=1);

namespace Piwigo\Http\Middleware;

use LogicException;
use Override;
use Piwigo\Http\ControllerInterface;
use Piwigo\Http\ResponseFactory;
use Piwigo\Http\ResponseReadyException;
use Piwigo\Routing\RouteMatchStatus;
use Piwigo\Routing\RouteResult;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Terminal middleware -- resolves the matched route's handler from the
 * container and invokes it, instead of calling $handler->handle() onward.
 *
 * Resolves Piwigo\Http\ControllerInterface (->__invoke()) -- real
 * Controllers exist for RouteDefinitions's
 * `_controller` defaults to reference.
 */
final readonly class ControllerInvokerMiddleware implements MiddlewareInterface
{
    public function __construct(
        private ContainerInterface $container
    ) {}

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $result = $request->getAttribute(RouteResult::class);
        if (! $result instanceof RouteResult) {
            throw new LogicException(
                'No RouteResult attribute on the request -- RoutingMiddleware must run before ControllerInvokerMiddleware.'
            );
        }

        if ($result->status !== RouteMatchStatus::Found || $result->handler === null) {
            return ResponseFactory::text('Not Found', 404);
        }

        $controller = $this->container->get($result->handler);
        if (! $controller instanceof ControllerInterface) {
            throw new LogicException(
                "Route handler '{$result->handler}' must implement Piwigo\\Http\\ControllerInterface."
            );
        }

        // This is the innermost/terminal middleware, so catching here
        // (not in ExceptionHandlerMiddleware) means every outer
        // middleware (SentryMiddleware's own `finally`,
        // ServerTimingMiddleware's post-processing, SessionMiddleware's
        // persist, SecurityHeadersMiddleware) sees a normal Response
        // return value and unwinds exactly as if the controller had
        // returned it directly -- see ResponseReadyException's own
        // docblock for the real bug this fixes.
        try {
            return $controller($request->withAttribute('route_args', $result->args));
        } catch (ResponseReadyException $e) {
            return $e->response();
        }
    }
}
