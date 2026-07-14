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
 *
 * MOUNT_DEPTH_ATTRIBUTE (P23 batch 6a) corrects a real bug found live:
 * every routed entry point until admin/popuphelp.php lived directly at the
 * app's mount point, so a single `dirname(SCRIPT_NAME)` call always
 * happened to strip exactly the right prefix. For a file one directory
 * deeper (admin/), `dirname(SCRIPT_NAME)` over-strips by that extra
 * directory (e.g. "/piwigo17/admin" instead of "/piwigo17"), silently
 * matching a *different*, wrong route rather than 404ing (confirmed live:
 * requests to /admin/popuphelp.php were actually being served by the
 * front-end PopuphelpController instead of AdminPopuphelpController).
 *
 * Deriving the extra depth from real filesystem paths (SCRIPT_FILENAME vs.
 * the app root) was tried first and rejected: this dev instance serves
 * the app through a symlink (`/var/www/html/piwigo17` -> the real repo
 * checkout), and Apache's own SCRIPT_FILENAME reflects the *symlinked*
 * path while PHP's own `__DIR__`/realpath-resolved paths reflect the
 * *target* path -- the two are never string-comparable, confirmed live.
 * Instead, the entry point itself (the one file that genuinely knows its
 * own real depth below the app root, e.g. admin/popuphelp.php's own
 * bootstrap) attaches it as a request attribute before dispatch; every
 * existing root-level entry point never sets it, defaulting to 0 (no
 * behavior change for any of them).
 */
final readonly class Router
{
    public const string MOUNT_DEPTH_ATTRIBUTE = 'router_extra_mount_depth';

    public function __construct(
        private RouteCollection $routes,
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
     *
     * Strips one extra `dirname()` per self::MOUNT_DEPTH_ATTRIBUTE level
     * (see class docblock) -- 0 by default (every existing root-level
     * file), explicitly set higher by an entry point that genuinely lives
     * in a subdirectory.
     */
    private static function pathInfo(ServerRequestInterface $request, string $path): string
    {
        $scriptName = $request->getServerParams()['SCRIPT_NAME'] ?? null;
        if (! is_string($scriptName) || $scriptName === '') {
            return $path;
        }

        $prefix = str_replace('\\', '/', $scriptName);
        $extraLevels = self::mountDepth($request);
        for ($i = 0; $i <= $extraLevels; $i++) {
            $prefix = dirname($prefix);
        }
        $prefix = str_replace('\\', '/', $prefix);

        if ($prefix === '/' || $prefix === '.') {
            return $path;
        }

        if (! str_starts_with($path, $prefix)) {
            return $path;
        }

        $stripped = substr($path, strlen($prefix));

        return $stripped === '' ? '/' : $stripped;
    }

    private static function mountDepth(ServerRequestInterface $request): int
    {
        $depth = $request->getAttribute(self::MOUNT_DEPTH_ATTRIBUTE, 0);

        return is_int($depth) && $depth > 0 ? $depth : 0;
    }
}
