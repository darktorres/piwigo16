<?php

declare(strict_types=1);

namespace Piwigo\Routing;

use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
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
 * Request::getBaseUrl(). This app has no single front controller (every
 * root PHP file is its own independent entry point, unlike a typical
 * Symfony app's index.php), so that computation is done here from
 * SCRIPT_NAME instead.
 *
 * self::MOUNT_DEPTH_ATTRIBUTE lets an entry point that lives one (or
 * more) directories deeper than the app's mount point (e.g.
 * admin/popuphelp.php) request extra `dirname()` stripping beyond the
 * single strip every root-level entry point needs -- without it,
 * `dirname(SCRIPT_NAME)` would strip the wrong number of segments and
 * silently match a different, wrong route instead of 404ing.
 *
 * The extra depth can't be derived automatically from filesystem paths:
 * when the app is served through a symlink, Apache's own SCRIPT_FILENAME
 * reflects the symlinked path while PHP's own `__DIR__`/realpath-resolved
 * paths reflect the target path, so the two are never reliably
 * comparable. Instead, the entry point itself (the one file that
 * genuinely knows its own depth below the app root) attaches
 * self::MOUNT_DEPTH_ATTRIBUTE as a request attribute before dispatch;
 * every root-level entry point never sets it, defaulting to 0.
 */
final readonly class Router
{
    public const string MOUNT_DEPTH_ATTRIBUTE = 'router_extra_mount_depth';

    public function __construct(
        private RouteCollection $routes,
    ) {}

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
            throw new RuntimeException("Route '{$routeNameForMessage}' has no _controller default.");
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
     *
     * Public (not just this class's own dispatch() caller): Http\
     * Middleware\ApiErrorMiddleware needs the exact same mount-stripped
     * path dispatch() itself matched routes against, not the raw
     * `$request->getUri()->getPath()` -- a `/piwigo17`-mounted deployment
     * would otherwise never match its own `/api/v1` prefix check.
     */
    public static function pathInfo(ServerRequestInterface $request, string $path): string
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
