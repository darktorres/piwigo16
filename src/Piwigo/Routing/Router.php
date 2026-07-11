<?php

declare(strict_types=1);

namespace Piwigo\Routing;

use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\Routing\Exception\MethodNotAllowedException;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\Routing\Matcher\UrlMatcher;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\RouteCollection;

/**
 * Wraps symfony/routing's UrlMatcher. Dispatch-only for now -- nothing
 * needs reverse routing (UrlGenerator) yet.
 */
final readonly class Router
{
    public function __construct(
        private RouteCollection $routes
    ) {}

    public static function fromFile(string $path): self
    {
        $routes = require $path;
        if (! $routes instanceof RouteCollection) {
            throw new \RuntimeException("{$path} must return a RouteCollection");
        }

        return new self($routes);
    }

    public function dispatch(ServerRequestInterface $request): RouteResult
    {
        $uri = $request->getUri();
        $context = new RequestContext(
            method: $request->getMethod(),
            host: $uri->getHost() !== '' ? $uri->getHost() : 'localhost',
            scheme: $uri->getScheme() !== '' ? $uri->getScheme() : 'http',
        );
        $matcher = new UrlMatcher($this->routes, $context);

        try {
            $match = $matcher->match($uri->getPath());
        } catch (ResourceNotFoundException) {
            return RouteResult::notFound();
        } catch (MethodNotAllowedException) {
            return RouteResult::methodNotAllowed();
        }

        $controller = $match['_controller'] ?? null;
        if (! is_string($controller)) {
            $routeName = $match['_route'] ?? null;
            $routeNameForMessage = is_string($routeName) ? $routeName : 'unknown';
            throw new \RuntimeException("Route '{$routeNameForMessage}' has no _controller default.");
        }

        $args = [];
        foreach ($match as $key => $value) {
            if (is_string($key) && $key !== '_route' && $key !== '_controller') {
                $args[$key] = $value;
            }
        }

        return RouteResult::found($controller, $args);
    }
}
