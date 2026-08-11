<?php

declare(strict_types=1);

use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Piwigo\Http\Middleware\RoutingMiddleware;
use Piwigo\Routing\Router;
use Piwigo\Routing\RouteResult;
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

    $response = new RoutingMiddleware($router)
        ->process(new ServerRequest('GET', '/'), $handler);

    expect((string) $response->getBody())
        ->toBe('Some\\Handler');
});
