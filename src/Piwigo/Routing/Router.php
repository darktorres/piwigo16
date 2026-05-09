<?php

declare(strict_types=1);

namespace Piwigo\Routing;

use Symfony\Component\Routing\Exception\MethodNotAllowedException;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\Routing\Generator\UrlGenerator as SymfonyUrlGenerator;
use Symfony\Component\Routing\Matcher\UrlMatcher;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\RouteCollection;

/**
 * Thin wrapper around symfony/routing.
 *
 * Provides both dispatch (URL matching) and generation (URL building)
 * from the same RouteCollection, so route names are the single source of
 * truth for both incoming and outgoing URLs.
 */
final readonly class Router
{
    private RouteCollection $routes;

    public function __construct(string $routesFile)
    {
        /** @psalm-suppress UnresolvableInclude */
        $routes = require $routesFile;
        assert($routes instanceof RouteCollection);
        $this->routes = $routes;
    }

    /**
     * Match an HTTP method + path to a controller.
     *
     * @return RouteResult  status FOUND | NOT_FOUND | METHOD_NOT_ALLOWED
     */
    public function dispatch(string $method, string $path): RouteResult
    {
        $context = new RequestContext('', $method);
        try {
            $params  = new UrlMatcher($this->routes, $context)->match($path);
            $handler = is_string($params['_controller'] ?? null) ? $params['_controller'] : '';
            unset($params['_controller'], $params['_route']);
            // Coerce Symfony's scalar route params to string[]
            $args = [];
            foreach ($params as $k => $v) {
                $args[(string) $k] = is_scalar($v) ? (string) $v : '';
            }
            return new RouteResult(RouteResult::FOUND, $handler, $args);
        } catch (ResourceNotFoundException) {
            return new RouteResult(RouteResult::NOT_FOUND);
        } catch (MethodNotAllowedException) {
            return new RouteResult(RouteResult::METHOD_NOT_ALLOWED);
        }
    }

    /**
     * Generate a URL for a named route.
     *
     * @param array<string, mixed> $params
     */
    public function generate(string $routeName, array $params = [], string $baseUrl = ''): string
    {
        $context = new RequestContext($baseUrl);
        return new SymfonyUrlGenerator($this->routes, $context)->generate($routeName, $params);
    }
}
