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
 *
 * Route patterns match the request path with the app's own mount-point
 * prefix already stripped (e.g. "/about.php", not "/piwigo17/about.php")
 * -- UrlMatcher::match() expects a bare "pathinfo", the same contract a
 * full Symfony HttpRequest::getPathInfo() provides via
 * Request::getBaseUrl(). This app has no single front controller (each of
 * P22's 21 root files is its own independent entry point, unlike a
 * typical Symfony app's index.php), so that computation is done here from
 * SCRIPT_NAME instead: confirmed via a real live-curl 404 against a
 * genuinely-registered route (this dev instance is reached at
 * /piwigo17/about.php, not /about.php) that UrlMatcher::match() needs the
 * prefix stripped, not the raw request path.
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
            $match = $matcher->match(self::pathInfo($request, $uri->getPath()));
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

    /**
     * Strips the app's own mount-point prefix (SCRIPT_NAME's own directory
     * -- e.g. "/piwigo17" when this app lives at http://host/piwigo17/,
     * "" at a domain root) from the request path, so route patterns stay
     * environment-independent ("/about.php", not "/piwigo17/about.php").
     * Falls back to the raw path unchanged when SCRIPT_NAME isn't a real
     * prefix of it (SCRIPT_NAME absent, or a CLI/test request that never
     * set it) -- same "degrade to the domain-root shape" default a bare
     * `Request::getBaseUrl()` would produce with no SCRIPT_NAME either.
     */
    private static function pathInfo(ServerRequestInterface $request, string $path): string
    {
        $scriptName = $request->getServerParams()['SCRIPT_NAME'] ?? null;
        if (! is_string($scriptName) || $scriptName === '') {
            return $path;
        }

        $prefix = str_replace('\\', '/', dirname($scriptName));
        if ($prefix === '/' || $prefix === '.') {
            return $path;
        }

        if (! str_starts_with($path, $prefix)) {
            return $path;
        }

        $stripped = substr($path, strlen($prefix));

        return $stripped === '' ? '/' : $stripped;
    }
}
