<?php

declare(strict_types=1);

use Nyholm\Psr7\ServerRequest;
use Piwigo\Bootstrap\RouteDefinitions;
use Piwigo\Controller\Api\VersionController;
use Piwigo\Routing\RouteMatchStatus;
use Piwigo\Routing\Router;
use Piwigo\Routing\RouteResult;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

test('dispatch returns Found with the controller and path args', function (): void {
    $routes = new RouteCollection();
    $routes->add('picture', new Route(
        '/picture/{id}',
        defaults: [
            '_controller' => 'Piwigo\\Handler\\PictureHandler',
        ],
        methods: ['GET'],
    ));

    $result = new Router($routes)
        ->dispatch(new ServerRequest('GET', '/picture/42'));

    expect($result->status)
        ->toBe(RouteMatchStatus::Found);
    expect($result->handler)
        ->toBe('Piwigo\\Handler\\PictureHandler');
    expect($result->args)
        ->toBe([
            'id' => '42',
        ]);
});

test('dispatch returns NotFound for an unmatched path', function (): void {
    $result = new Router(new RouteCollection())
        ->dispatch(new ServerRequest('GET', '/nope'));

    expect($result->status)
        ->toBe(RouteMatchStatus::NotFound);
});

test('dispatch returns MethodNotAllowed when the path matches but the method does not', function (): void {
    $routes = new RouteCollection();
    $routes->add('picture', new Route(
        '/picture/{id}',
        defaults: [
            '_controller' => 'X',
        ],
        methods: ['POST'],
    ));

    $result = new Router($routes)
        ->dispatch(new ServerRequest('GET', '/picture/42'));

    expect($result->status)
        ->toBe(RouteMatchStatus::MethodNotAllowed);
});

test('dispatch works against the real RouteDefinitions::all() route collection', function (): void {
    $router = new Router(RouteDefinitions::all());

    $result = $router->dispatch(new ServerRequest('GET', '/anything'));

    expect($result->status)
        ->toBe(RouteMatchStatus::NotFound);
});

test('dispatch resolves /api/v1/version to VersionController', function (): void {
    $router = new Router(RouteDefinitions::all());

    $result = $router->dispatch(new ServerRequest('GET', '/api/v1/version'));

    expect($result->status)
        ->toBe(RouteMatchStatus::Found);
    expect($result->handler)
        ->toBe(VersionController::class);
});

test('dispatch returns NotFound for an unmatched /api/v1 resource path (no catch-all route)', function (): void {
    // Deliberately no dedicated route for this -- see RouteDefinitions's
    // own comment on why a catch-all would shadow a real route's 405.
    // Http\Middleware\ApiErrorMiddleware turns this NotFound into RFC
    // 9457 problem+json further down the pipeline.
    $router = new Router(RouteDefinitions::all());

    $result = $router->dispatch(new ServerRequest('GET', '/api/v1/does/not/exist'));

    expect($result->status)
        ->toBe(RouteMatchStatus::NotFound);
});

test('dispatch returns MethodNotAllowed for a non-GET request to /api/v1/version', function (): void {
    $router = new Router(RouteDefinitions::all());

    $result = $router->dispatch(new ServerRequest('POST', '/api/v1/version'));

    expect($result->status)
        ->toBe(RouteMatchStatus::MethodNotAllowed);
});

test('dispatch resolves the request context host from a non-empty URI host, not overriding it with the localhost fallback', function (): void {
    $routes = new RouteCollection();
    $routes->add('host_pinned', new Route(
        '/host-check',
        defaults: [
            '_controller' => 'HostPinnedController',
        ],
    )->setHost('example.com'));

    $result = new Router($routes)
        ->dispatch(new ServerRequest('GET', 'http://example.com/host-check'));

    expect($result->status)
        ->toBe(RouteMatchStatus::Found);
    expect($result->handler)
        ->toBe('HostPinnedController');
});

test('dispatch falls back to "localhost" as the request context host when the URI has no host', function (): void {
    $routes = new RouteCollection();
    $routes->add('host_fallback', new Route(
        '/host-fallback',
        defaults: [
            '_controller' => 'HostFallbackController',
        ],
    )->setHost('localhost'));

    // A bare-path URI (no scheme://host authority) leaves Uri::getHost() ''.
    $result = new Router($routes)
        ->dispatch(new ServerRequest('GET', '/host-fallback'));

    expect($result->status)
        ->toBe(RouteMatchStatus::Found);
    expect($result->handler)
        ->toBe('HostFallbackController');
});

test('dispatch resolves the request context scheme from a non-empty URI scheme, not overriding it with the http fallback', function (): void {
    $routes = new RouteCollection();
    $routes->add('scheme_pinned', new Route(
        '/scheme-check',
        defaults: [
            '_controller' => 'SchemePinnedController',
        ],
    )->setSchemes(['https']));

    $result = new Router($routes)
        ->dispatch(new ServerRequest('GET', 'https://example.com/scheme-check'));

    expect($result->status)
        ->toBe(RouteMatchStatus::Found);
    expect($result->handler)
        ->toBe('SchemePinnedController');
});

test('dispatch falls back to "http" as the request context scheme when the URI has no scheme', function (): void {
    $routes = new RouteCollection();
    $routes->add('scheme_fallback', new Route(
        '/scheme-fallback',
        defaults: [
            '_controller' => 'SchemeFallbackController',
        ],
    )->setSchemes(['http']));

    // A bare-path URI leaves Uri::getScheme() ''.
    $result = new Router($routes)
        ->dispatch(new ServerRequest('GET', '/scheme-fallback'));

    expect($result->status)
        ->toBe(RouteMatchStatus::Found);
    expect($result->handler)
        ->toBe('SchemeFallbackController');
});

test('dispatch strips the app mount-point prefix derived from SCRIPT_NAME before matching', function (): void {
    // Reproduces this dev instance's own real shape: reached at
    // /piwigo17/about.php, not /about.php -- confirmed via a real
    // live-curl 404 before this fix (Router::pathInfo()'s own docblock).
    $routes = new RouteCollection();
    $routes->add('about', new Route('/about.php', defaults: [
        '_controller' => 'AboutController',
    ]));

    $request = new ServerRequest('GET', '/piwigo17/about.php', serverParams: [
        'SCRIPT_NAME' => '/piwigo17/about.php',
    ]);
    $result = new Router($routes)
        ->dispatch($request);

    expect($result->status)
        ->toBe(RouteMatchStatus::Found);
    expect($result->handler)
        ->toBe('AboutController');
});

test('dispatch matches unprefixed paths unchanged when the app is mounted at the domain root', function (): void {
    $routes = new RouteCollection();
    $routes->add('about', new Route('/about.php', defaults: [
        '_controller' => 'AboutController',
    ]));

    $request = new ServerRequest('GET', '/about.php', serverParams: [
        'SCRIPT_NAME' => '/about.php',
    ]);
    $result = new Router($routes)
        ->dispatch($request);

    expect($result->status)
        ->toBe(RouteMatchStatus::Found);
});

test('dispatch falls back to the raw path when SCRIPT_NAME is absent (e.g. CLI/test requests)', function (): void {
    $result = new Router(new RouteCollection())
        ->dispatch(new ServerRequest('GET', '/anything'));

    expect($result->status)
        ->toBe(RouteMatchStatus::NotFound);
});

test('dispatch matches a wildcard-tail route pattern for both i.php URL styles', function (): void {
    // i.php supports 2 URL styles depending on
    // CurrentConfig::questionMarkInUrls(): query-string ("/i.php?/upload/...",
    // where the query string never becomes part of the URI path Router
    // matches against, so the routable path is bare "/i.php") and
    // PATH_INFO ("/i.php/upload/...", where the routable path has a real
    // tail). RouteDefinitions's own '/i.php{tail}' pattern (tail
    // requirement '.*', default '') must match both shapes with the same
    // one route.
    $routes = new RouteCollection();
    $routes->add('derivative_image', new Route(
        '/i.php{tail}',
        defaults: [
            '_controller' => 'ImageDerivativeController',
            'tail' => '',
        ],
        requirements: [
            'tail' => '.*',
        ],
    ));

    $bare = new Router($routes)
        ->dispatch(new ServerRequest('GET', '/i.php', serverParams: [
            'SCRIPT_NAME' => '/i.php',
        ]));
    expect($bare->status)
        ->toBe(RouteMatchStatus::Found);
    expect($bare->handler)
        ->toBe('ImageDerivativeController');

    $withPathInfo = new Router($routes)
        ->dispatch(new ServerRequest(
            'GET',
            '/i.php/upload/2026/08/01/20260801000000-abc-th.jpg',
            serverParams: [
                'SCRIPT_NAME' => '/i.php',
            ],
        ));
    expect($withPathInfo->status)
        ->toBe(RouteMatchStatus::Found);
    expect($withPathInfo->handler)
        ->toBe('ImageDerivativeController');
});

test('dispatch strips one extra SCRIPT_NAME directory level per MOUNT_DEPTH_ATTRIBUTE, for an entry point one subdirectory below the app root', function (): void {
    // Reproduces admin/popuphelp.php's own real shape: a routed entry
    // point one directory below the app root, unlike every other routed
    // file. Without the attribute, dirname(SCRIPT_NAME) alone over-strips
    // by the extra "admin" segment and either 404s or -- worse -- silently
    // matches a different, wrong route.
    $routes = new RouteCollection();
    $routes->add('admin_popuphelp', new Route('/admin/popuphelp.php', defaults: [
        '_controller' => 'AdminPopuphelpController',
    ]));

    $request = new ServerRequest(
        'GET',
        '/piwigo17/admin/popuphelp.php',
        serverParams: [
            'SCRIPT_NAME' => '/piwigo17/admin/popuphelp.php',
        ],
    )->withAttribute(Router::MOUNT_DEPTH_ATTRIBUTE, 1);
    $result = new Router($routes)
        ->dispatch($request);

    expect($result->status)
        ->toBe(RouteMatchStatus::Found);
    expect($result->handler)
        ->toBe('AdminPopuphelpController');
});

/**
 * A mutation-testing sweep found line 132's UnwrapStrReplace mutant (the
 * `str_replace('\\', '/', $prefix)` call *after* the dirname() loop,
 * unwrapped to a no-op `$prefix = $prefix`) is confirmed-equivalent --
 * verified live via `pest --mutate --id` replaying the exact mutation
 * against this file's full suite, including the backslash-SCRIPT_NAME
 * test directly below, which was the strongest candidate to kill it.
 *
 * By the time execution reaches line 132, $prefix has already passed
 * through line 127's own `str_replace('\\', '/', ...)`, so it is
 * guaranteed backslash-free; dirname() (this app never runs on Windows,
 * confirmed via PHP_OS_FAMILY) only ever *removes* trailing characters
 * from its input on every subsequent loop iteration, so it can never
 * reintroduce a backslash it wasn't given. Line 132's call is therefore
 * always applied to an already-backslash-free string -- str_replace()
 * mathematically returns its input unchanged whenever the search string
 * ('\\') isn't present, which is provably true here for every possible
 * SCRIPT_NAME and every MOUNT_DEPTH_ATTRIBUTE value, not just this test's
 * particular input.
 */
test('dispatch converts backslash-style SCRIPT_NAME separators (as IIS reports them) to forward slashes before deriving the mount prefix', function (): void {
    $routes = new RouteCollection();
    $routes->add('about', new Route('/about.php', defaults: [
        '_controller' => 'AboutController',
    ]));

    // The request URI path always uses forward slashes (URL syntax); only
    // SCRIPT_NAME -- an IIS server variable in this scenario -- reports
    // backslashes. pathInfo() must normalize them before deriving the
    // mount prefix via dirname(), which never treats '\' as a separator
    // on this (non-Windows) platform.
    $request = new ServerRequest(
        'GET',
        '/piwigo17/about.php',
        serverParams: [
            'SCRIPT_NAME' => '\\piwigo17\\about.php',
        ],
    );
    $result = new Router($routes)
        ->dispatch($request);

    expect($result->status)
        ->toBe(RouteMatchStatus::Found);
    expect($result->handler)
        ->toBe('AboutController');
});

test('dispatch clamps a negative MOUNT_DEPTH_ATTRIBUTE to 0 instead of skipping the mount-prefix strip entirely', function (): void {
    // A negative depth must not survive into pathInfo()'s stripping loop
    // (`for ($i = 0; $i <= $extraLevels; $i++)`): if it did, the loop
    // would never execute at all (0 <= a negative number is false), so
    // dirname() would never run and the full SCRIPT_NAME (not just its
    // directory) would be treated as the prefix to strip -- consuming the
    // whole path and collapsing it to '/'.
    $routes = new RouteCollection();
    $routes->add('about', new Route('/about.php', defaults: [
        '_controller' => 'AboutController',
    ]));

    $request = new ServerRequest(
        'GET',
        '/piwigo17/about.php',
        serverParams: [
            'SCRIPT_NAME' => '/piwigo17/about.php',
        ],
    )->withAttribute(Router::MOUNT_DEPTH_ATTRIBUTE, -5);
    $result = new Router($routes)
        ->dispatch($request);

    expect($result->status)
        ->toBe(RouteMatchStatus::Found);
    expect($result->handler)
        ->toBe('AboutController');
});

/**
 * A mutation-testing sweep found pathInfo()'s two EmptyStringToNotEmpty
 * mutants (line 123's `$scriptName === ''` early-return guard, and line
 * 144's `$stripped === '' ? '/' : $stripped` conversion) are both
 * confirmed-equivalent -- verified live via `pest --mutate --id` replaying
 * each exact mutation against this file's full suite.
 *
 * This test exercises precisely the scenario that should distinguish
 * them: an empty SCRIPT_NAME reaching the early return with an empty
 * $path, vs. (if the guard were skipped) falling through the stripping
 * logic to reach $stripped === '' at line 144. Even here the two mutants
 * are unobservable, because both of pathInfo()'s possible return values
 * in that scenario ('' from the early return, or '/' from line 144's own
 * conversion) are collapsed back to the *same* '/' by the very next line
 * of code outside this class: Symfony's own
 * `UrlMatcher::match()` unconditionally normalizes an empty pathinfo
 * string to '/' before any route matching happens
 * (`$pathinfo = '' === $pathinfo ? '/' : $pathinfo;`). So whichever of
 * '' or '/' pathInfo() hands back, the matcher treats them identically --
 * there is no way to construct a request where the two mutants' route
 * -matching outcome differs.
 */
test('dispatch treats a totally empty SCRIPT_NAME the same as an absent one, matching a root route from a root-path request', function (): void {
    $routes = new RouteCollection();
    $routes->add('root', new Route('/', defaults: [
        '_controller' => 'RootController',
    ]));

    $request = new ServerRequest('GET', '', serverParams: [
        'SCRIPT_NAME' => '',
    ]);
    $result = new Router($routes)
        ->dispatch($request);

    expect($result->status)
        ->toBe(RouteMatchStatus::Found);
    expect($result->handler)
        ->toBe('RootController');
});

/**
 * A mutation-testing sweep found three more confirmed-equivalent mutants,
 * all inside mountDepth()'s single clamp expression
 * `is_int($depth) && $depth > 0 ? $depth : 0` -- verified live via
 * `pest --mutate --id` replaying each exact mutation against this file's
 * full suite:
 *
 * - Line 149's DecrementInteger (the `0` default passed to
 *   getAttribute() becomes `-1`): only takes effect when the attribute is
 *   entirely absent, in which case $depth is exactly 0 (original) or -1
 *   (mutant) -- both fail `$depth > 0` and clamp to the same final 0.
 * - Line 151's GreaterToGreaterOrEqual (`>` becomes `>=`): only changes
 *   the outcome at the $depth === 0 boundary, where the "then" branch
 *   would return $depth itself -- which is 0, identical to the "else"
 *   branch's literal 0.
 * - Line 151's DecrementInteger (`> 0` becomes `> -1`): only changes the
 *   outcome at $depth === 0 (same reasoning as above -- returns $depth,
 *   which is 0) and $depth === -1, where both `-1 > 0` and `-1 > -1` are
 *   false and clamp to 0 either way.
 *
 * All three differ from the original only where the "genuine value" and
 * "clamped-to-0" branches happen to produce the identical integer, so no
 * MOUNT_DEPTH_ATTRIBUTE value -- explicit or absent -- can distinguish
 * them from the original.
 */
test('dispatch treats an explicit MOUNT_DEPTH_ATTRIBUTE of exactly 0 the same as omitting it entirely', function (): void {
    $routes = new RouteCollection();
    $routes->add('about', new Route('/about.php', defaults: [
        '_controller' => 'AboutController',
    ]));

    $request = new ServerRequest(
        'GET',
        '/piwigo17/about.php',
        serverParams: [
            'SCRIPT_NAME' => '/piwigo17/about.php',
        ],
    )->withAttribute(Router::MOUNT_DEPTH_ATTRIBUTE, 0);
    $result = new Router($routes)
        ->dispatch($request);

    expect($result->status)
        ->toBe(RouteMatchStatus::Found);
    expect($result->handler)
        ->toBe('AboutController');
});

test('dispatch throws when a matched route has no _controller default', function (): void {
    $routes = new RouteCollection();
    $routes->add('broken_route', new Route('/broken'));

    expect(static fn (): RouteResult => new Router($routes)->dispatch(new ServerRequest('GET', '/broken')))
        ->toThrow(RuntimeException::class, "Route 'broken_route' has no _controller default.");
});

test('dispatch falls back to the raw path when SCRIPT_NAME is not actually a prefix of it', function (): void {
    // Distinct from the "SCRIPT_NAME absent entirely" fallback above --
    // here SCRIPT_NAME is present and non-empty, but the prefix it
    // reduces to (via dirname()) is not actually a prefix of the real
    // request path at all (e.g. a stale/mismatched SCRIPT_NAME from a
    // different vhost), so pathInfo() must still fall back to the raw
    // path unchanged rather than mangling it with substr().
    $routes = new RouteCollection();
    $routes->add('about', new Route('/about.php', defaults: [
        '_controller' => 'AboutController',
    ]));

    $request = new ServerRequest('GET', '/about.php', serverParams: [
        'SCRIPT_NAME' => '/some-other-app/index.php',
    ]);
    $result = new Router($routes)
        ->dispatch($request);

    expect($result->status)
        ->toBe(RouteMatchStatus::Found);
    expect($result->handler)
        ->toBe('AboutController');
});

test('dispatch without MOUNT_DEPTH_ATTRIBUTE set does not match a route one directory below the app root', function (): void {
    // The other half of the fix above: confirms the attribute is genuinely
    // load-bearing, not a no-op -- omitting it reproduces the real bug
    // this fixes (a request for a nested entry point falls through to
    // NotFound rather than being silently misrouted, since this
    // RouteCollection has no "/popuphelp.php"-shaped route to accidentally
    // match, unlike the real RouteDefinitions).
    $routes = new RouteCollection();
    $routes->add('admin_popuphelp', new Route('/admin/popuphelp.php', defaults: [
        '_controller' => 'AdminPopuphelpController',
    ]));

    $request = new ServerRequest(
        'GET',
        '/piwigo17/admin/popuphelp.php',
        serverParams: [
            'SCRIPT_NAME' => '/piwigo17/admin/popuphelp.php',
        ],
    );
    $result = new Router($routes)
        ->dispatch($request);

    expect($result->status)
        ->toBe(RouteMatchStatus::NotFound);
});
