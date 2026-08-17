<?php

declare(strict_types=1);

namespace Piwigo\Http\Middleware;

use Override;
use Piwigo\Http\ResponseFactory;
use Piwigo\Routing\RouteMatchStatus;
use Piwigo\Routing\Router;
use Piwigo\Routing\RouteResult;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Runs between RoutingMiddleware and ControllerInvokerMiddleware. Every
 * `/api/v1/...` response is RFC 9457 `application/problem+json` (P27
 * Locked Decision D3) -- ControllerInvokerMiddleware's own fallback for a
 * non-Found RouteResult is plain text, correct for every other route in
 * the app, so this intercepts only the `/api/v1` prefix rather than
 * changing that shared behavior.
 *
 * No dedicated route drives this (see RouteDefinitions's own comment on
 * why a catch-all route would shadow a real route's 405): a genuinely
 * unmatched `/api/v1/...` path is a real RouteMatchStatus::NotFound, and
 * a path that matched with the wrong HTTP method is a real
 * RouteMatchStatus::MethodNotAllowed -- both already computed by
 * RoutingMiddleware, just given the app's generic 404 shape until now.
 */
final readonly class ApiErrorMiddleware implements MiddlewareInterface
{
    private const string API_PREFIX = '/api/v1/';

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $result = $request->getAttribute(RouteResult::class);
        // The same mount-stripped path RoutingMiddleware's own Router
        // matched routes against, not the raw request-URI path -- a
        // /piwigo17-mounted deployment's real path is
        // /piwigo17/api/v1/..., which would never satisfy this prefix
        // check otherwise.
        $path = Router::pathInfo($request, $request->getUri()->getPath());

        if (! $result instanceof RouteResult || $result->status === RouteMatchStatus::Found || ! str_starts_with($path, self::API_PREFIX)) {
            return $handler->handle($request);
        }

        if ($result->status === RouteMatchStatus::MethodNotAllowed) {
            return ResponseFactory::problem(
                'Method Not Allowed',
                405,
                $request->getMethod() . ' is not supported for ' . $path . '.',
            );
        }

        return ResponseFactory::problem(
            'Not Found',
            404,
            'No resource exists at ' . $path . '.',
        );
    }
}
