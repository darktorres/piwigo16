<?php

declare(strict_types=1);

use Nyholm\Psr7\ServerRequest;
use Piwigo\Routing\RouteMatchStatus;
use Piwigo\Routing\Router;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

test('dispatch returns Found with the controller and path args', function (): void {
    $routes = new RouteCollection();
    $routes->add('picture', new Route(
        '/picture/{id}',
        defaults: ['_controller' => 'Piwigo\\Handler\\PictureHandler'],
        methods: ['GET'],
    ));

    $result = new Router($routes)->dispatch(new ServerRequest('GET', '/picture/42'));

    expect($result->status)->toBe(RouteMatchStatus::Found);
    expect($result->handler)->toBe('Piwigo\\Handler\\PictureHandler');
    expect($result->args)->toBe(['id' => '42']);
});

test('dispatch returns NotFound for an unmatched path', function (): void {
    $result = new Router(new RouteCollection())->dispatch(new ServerRequest('GET', '/nope'));

    expect($result->status)->toBe(RouteMatchStatus::NotFound);
});

test('dispatch returns MethodNotAllowed when the path matches but the method does not', function (): void {
    $routes = new RouteCollection();
    $routes->add('picture', new Route(
        '/picture/{id}',
        defaults: ['_controller' => 'X'],
        methods: ['POST'],
    ));

    $result = new Router($routes)->dispatch(new ServerRequest('GET', '/picture/42'));

    expect($result->status)->toBe(RouteMatchStatus::MethodNotAllowed);
});

test('fromFile loads a RouteCollection from a real file', function (): void {
    $router = Router::fromFile(__DIR__ . '/../../../config/routes.php');

    $result = $router->dispatch(new ServerRequest('GET', '/anything'));

    expect($result->status)->toBe(RouteMatchStatus::NotFound);
});

test('dispatch strips the app mount-point prefix derived from SCRIPT_NAME before matching', function (): void {
    // Reproduces this dev instance's own real shape: reached at
    // /piwigo17/about.php, not /about.php -- confirmed via a real
    // live-curl 404 before this fix (Router::pathInfo()'s own docblock).
    $routes = new RouteCollection();
    $routes->add('about', new Route('/about.php', defaults: ['_controller' => 'AboutController']));

    $request = new ServerRequest('GET', '/piwigo17/about.php', serverParams: ['SCRIPT_NAME' => '/piwigo17/about.php']);
    $result = new Router($routes)->dispatch($request);

    expect($result->status)->toBe(RouteMatchStatus::Found);
    expect($result->handler)->toBe('AboutController');
});

test('dispatch matches unprefixed paths unchanged when the app is mounted at the domain root', function (): void {
    $routes = new RouteCollection();
    $routes->add('about', new Route('/about.php', defaults: ['_controller' => 'AboutController']));

    $request = new ServerRequest('GET', '/about.php', serverParams: ['SCRIPT_NAME' => '/about.php']);
    $result = new Router($routes)->dispatch($request);

    expect($result->status)->toBe(RouteMatchStatus::Found);
});

test('dispatch falls back to the raw path when SCRIPT_NAME is absent (e.g. CLI/test requests)', function (): void {
    $result = new Router(new RouteCollection())->dispatch(new ServerRequest('GET', '/anything'));

    expect($result->status)->toBe(RouteMatchStatus::NotFound);
});

test('dispatch matches a wildcard-tail route pattern for both i.php URL styles', function (): void {
    // i.php (Workstream C3 Part III) supports 2 URL styles depending on
    // Config::questionMarkInUrls(): query-string ("/i.php?/upload/...",
    // where the query string never becomes part of the URI path Router
    // matches against, so the routable path is bare "/i.php") and
    // PATH_INFO ("/i.php/upload/...", where the routable path has a real
    // tail) -- confirmed live via a real diagnostic request (both
    // SCRIPT_NAME/PATH_INFO/QUERY_STRING captured directly), not assumed.
    // config/routes.php's own '/i.php{tail}' pattern (tail requirement
    // '.*', default '') must match both shapes with the same one route.
    $routes = new RouteCollection();
    $routes->add('derivative_image', new Route(
        '/i.php{tail}',
        defaults: ['_controller' => 'ImageDerivativeController', 'tail' => ''],
        requirements: ['tail' => '.*'],
    ));

    $bare = new Router($routes)->dispatch(new ServerRequest('GET', '/i.php', serverParams: ['SCRIPT_NAME' => '/i.php']));
    expect($bare->status)->toBe(RouteMatchStatus::Found);
    expect($bare->handler)->toBe('ImageDerivativeController');

    $withPathInfo = new Router($routes)->dispatch(new ServerRequest(
        'GET',
        '/i.php/upload/2026/08/01/20260801000000-abc-th.jpg',
        serverParams: ['SCRIPT_NAME' => '/i.php'],
    ));
    expect($withPathInfo->status)->toBe(RouteMatchStatus::Found);
    expect($withPathInfo->handler)->toBe('ImageDerivativeController');
});

test('dispatch strips one extra SCRIPT_NAME directory level per MOUNT_DEPTH_ATTRIBUTE, for an entry point one subdirectory below the app root', function (): void {
    // Reproduces admin/popuphelp.php's own real shape (P23 batch 6a): a
    // routed entry point one directory below the app root, unlike every
    // other routed file. Without the attribute, dirname(SCRIPT_NAME) alone
    // over-strips by the extra "admin" segment and either 404s or -- worse,
    // confirmed live -- silently matches a different, wrong route.
    $routes = new RouteCollection();
    $routes->add('admin_popuphelp', new Route('/admin/popuphelp.php', defaults: ['_controller' => 'AdminPopuphelpController']));

    $request = new ServerRequest(
        'GET',
        '/piwigo17/admin/popuphelp.php',
        serverParams: ['SCRIPT_NAME' => '/piwigo17/admin/popuphelp.php'],
    )->withAttribute(Router::MOUNT_DEPTH_ATTRIBUTE, 1);
    $result = new Router($routes)->dispatch($request);

    expect($result->status)->toBe(RouteMatchStatus::Found);
    expect($result->handler)->toBe('AdminPopuphelpController');
});

test('dispatch without MOUNT_DEPTH_ATTRIBUTE set does not match a route one directory below the app root', function (): void {
    // The other half of the fix above: confirms the attribute is genuinely
    // load-bearing, not a no-op -- omitting it reproduces the real bug
    // this fixes (a request for a nested entry point falls through to
    // NotFound rather than being silently misrouted, since this
    // RouteCollection has no "/popuphelp.php"-shaped route to accidentally
    // match, unlike the real config/routes.php).
    $routes = new RouteCollection();
    $routes->add('admin_popuphelp', new Route('/admin/popuphelp.php', defaults: ['_controller' => 'AdminPopuphelpController']));

    $request = new ServerRequest(
        'GET',
        '/piwigo17/admin/popuphelp.php',
        serverParams: ['SCRIPT_NAME' => '/piwigo17/admin/popuphelp.php'],
    );
    $result = new Router($routes)->dispatch($request);

    expect($result->status)->toBe(RouteMatchStatus::NotFound);
});
