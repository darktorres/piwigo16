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
