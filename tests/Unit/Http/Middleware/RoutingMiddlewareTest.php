<?php

declare(strict_types=1);

use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Piwigo\Http\Middleware\RoutingMiddleware;
use Piwigo\Routing\ApiRouteRegistrarInterface;
use Piwigo\Routing\PageRouteRegistrarInterface;
use Piwigo\Routing\Router;
use Piwigo\Routing\RouteResult;
use Piwigo\Tests\Unit\Http\Middleware\RoutingMiddlewareTestNoopPageRegistrar;
use Piwigo\Tests\Unit\Http\Middleware\RoutingMiddlewareTestNoopRegistrar;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

test('attaches the dispatched RouteResult as a request attribute', function (): void {
    $routes = new RouteCollection();
    $routes->add('home', new Route('/', defaults: [
        '_controller' => 'Some\\Handler',
    ]));
    $router = new Router($routes);

    // Echoes the attribute's handler name into the response body instead of
    // capturing it via a mutable side object -- the instanceof narrowing
    // then lives in the same scope PHPStan can actually track it in.
    $handler = new class() implements RequestHandlerInterface {
        #[Override]
        public function handle(ServerRequestInterface $request): ResponseInterface
        {
            $result = $request->getAttribute(RouteResult::class);
            $handlerName = $result instanceof RouteResult ? ($result->handler ?? '') : '';

            return new Response(200, [], $handlerName);
        }
    };

    $response = new RoutingMiddleware($router, new RoutingMiddlewareTestNoopRegistrar(), new RoutingMiddlewareTestNoopPageRegistrar())
        ->process(new ServerRequest('GET', '/'), $handler);

    expect((string) $response->getBody())
        ->toBe('Some\\Handler');
});

test('dispatches against a route the registrar adds during process()', function (): void {
    $routes = new RouteCollection();
    $router = new Router($routes);

    $registrar = new class() implements ApiRouteRegistrarInterface {
        #[Override]
        public function registerApiRoutes(RouteCollection $routes): void
        {
            $routes->add('plugin_route', new Route('/api/v1/plugin-routes/fixture/ping', defaults: [
                '_controller' => 'Fixture\\PingController',
            ]));
        }
    };

    $handler = new class() implements RequestHandlerInterface {
        #[Override]
        public function handle(ServerRequestInterface $request): ResponseInterface
        {
            $result = $request->getAttribute(RouteResult::class);
            $handlerName = $result instanceof RouteResult ? ($result->handler ?? '') : '';

            return new Response(200, [], $handlerName);
        }
    };

    $response = new RoutingMiddleware($router, $registrar, new RoutingMiddlewareTestNoopPageRegistrar())
        ->process(new ServerRequest('GET', '/api/v1/plugin-routes/fixture/ping'), $handler);

    expect((string) $response->getBody())
        ->toBe('Fixture\\PingController');
});

test('dispatches against a page route the page registrar adds during process()', function (): void {
    $routes = new RouteCollection();
    $router = new Router($routes);

    $pageRegistrar = new class() implements PageRouteRegistrarInterface {
        #[Override]
        public function registerPageRoutes(RouteCollection $routes): void
        {
            $routes->add('plugin_page', new Route('/tag_groups.php', defaults: [
                '_controller' => 'Fixture\\TagGroupsController',
            ]));
        }
    };

    $handler = new class() implements RequestHandlerInterface {
        #[Override]
        public function handle(ServerRequestInterface $request): ResponseInterface
        {
            $result = $request->getAttribute(RouteResult::class);
            $handlerName = $result instanceof RouteResult ? ($result->handler ?? '') : '';

            return new Response(200, [], $handlerName);
        }
    };

    $response = new RoutingMiddleware($router, new RoutingMiddlewareTestNoopRegistrar(), $pageRegistrar)
        ->process(new ServerRequest('GET', '/tag_groups.php'), $handler);

    expect((string) $response->getBody())
        ->toBe('Fixture\\TagGroupsController');
});
