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
