<?php

declare(strict_types=1);

use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Piwigo\Http\Middleware\ApiErrorMiddleware;
use Piwigo\Routing\RouteResult;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

function apiErrorNoopHandler(): RequestHandlerInterface
{
    return new class() implements RequestHandlerInterface {
        #[Override]
        public function handle(ServerRequestInterface $request): ResponseInterface
        {
            return new Response(200, [], 'downstream');
        }
    };
}

test('passes through unchanged when the route was found, regardless of path', function (): void {
    $middleware = new ApiErrorMiddleware();
    $request = new ServerRequest('GET', '/api/v1/version')
        ->withAttribute(RouteResult::class, RouteResult::found('X', []));

    $response = $middleware->process($request, apiErrorNoopHandler());

    expect((string) $response->getBody())
        ->toBe('downstream');
});

test('passes through unchanged for a NotFound route outside /api/v1', function (): void {
    $middleware = new ApiErrorMiddleware();
    $request = new ServerRequest('GET', '/anything')
        ->withAttribute(RouteResult::class, RouteResult::notFound());

    $response = $middleware->process($request, apiErrorNoopHandler());

    expect((string) $response->getBody())
        ->toBe('downstream');
});

test('passes through unchanged when no RouteResult attribute is present', function (): void {
    $middleware = new ApiErrorMiddleware();
    $request = new ServerRequest('GET', '/api/v1/version');

    $response = $middleware->process($request, apiErrorNoopHandler());

    expect((string) $response->getBody())
        ->toBe('downstream');
});

test('returns an RFC 9457 problem+json 404 for an unmatched /api/v1 path, without calling the next handler', function (): void {
    $middleware = new ApiErrorMiddleware();
    $request = new ServerRequest('GET', '/api/v1/does-not-exist')
        ->withAttribute(RouteResult::class, RouteResult::notFound());

    $response = $middleware->process($request, apiErrorNoopHandler());

    expect($response->getStatusCode())
        ->toBe(404);
    expect($response->getHeaderLine('Content-Type'))
        ->toBe('application/problem+json');
    expect(json_decode((string) $response->getBody(), true))
        ->toBe([
            'type' => 'about:blank',
            'title' => 'Not Found',
            'status' => 404,
            'detail' => 'No resource exists at /api/v1/does-not-exist.',
        ]);
});

test('returns an RFC 9457 problem+json 405 for a method mismatch on /api/v1', function (): void {
    $middleware = new ApiErrorMiddleware();
    $request = new ServerRequest('POST', '/api/v1/version')
        ->withAttribute(RouteResult::class, RouteResult::methodNotAllowed());

    $response = $middleware->process($request, apiErrorNoopHandler());

    expect($response->getStatusCode())
        ->toBe(405);
    expect($response->getHeaderLine('Content-Type'))
        ->toBe('application/problem+json');
    expect(json_decode((string) $response->getBody(), true))
        ->toBe([
            'type' => 'about:blank',
            'title' => 'Method Not Allowed',
            'status' => 405,
            'detail' => 'POST is not supported for /api/v1/version.',
        ]);
});
