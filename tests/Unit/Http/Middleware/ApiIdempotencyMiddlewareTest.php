<?php

declare(strict_types=1);

use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Piwigo\Cache\CacheFactory;
use Piwigo\Cache\IdempotencyCachePool;
use Piwigo\Config\CurrentConfig;
use Piwigo\Controller\Api\Tags\TagCreateController;
use Piwigo\Controller\Api\Uploads\TusUploadPatchController;
use Piwigo\Http\Middleware\ApiIdempotencyMiddleware;
use Piwigo\Routing\RouteResult;
use Piwigo\Users\CurrentUser;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

function apiIdempotencyTestMiddleware(): ApiIdempotencyMiddleware
{
    $currentUser = new CurrentUser(new CurrentConfig());
    $currentUser->attachGlobals();
    $pool = new IdempotencyCachePool(CacheFactory::create(namespace: 'piwigo.idempotency', defaultLifetime: 86400));

    return new ApiIdempotencyMiddleware($pool, $currentUser);
}

/**
 * @return array{RequestHandlerInterface, Closure(): int}
 */
function apiIdempotencyCountingHandler(): array
{
    $calls = 0;
    $handler = new class(static function () use (&$calls): void {
        $calls++;
    }) implements RequestHandlerInterface {
        public function __construct(
            private readonly Closure $onCall
        ) {}

        #[Override]
        public function handle(ServerRequestInterface $request): ResponseInterface
        {
            ($this->onCall)();

            return new Response(201, [], json_encode([
                'searchId' => uniqid('search-', true),
            ], JSON_THROW_ON_ERROR));
        }
    };

    return [$handler, static function () use (&$calls): int {
        return $calls;
    }];
}

test('calls the real handler every time when no Idempotency-Key header is present', function (): void {
    $middleware = apiIdempotencyTestMiddleware();
    [$handler, $callCount] = apiIdempotencyCountingHandler();
    $request = new ServerRequest('POST', '/api/v1/images/searches')
        ->withAttribute(RouteResult::class, RouteResult::found(TagCreateController::class, []));

    $middleware->process($request, $handler);
    $middleware->process($request, $handler);

    expect($callCount())
        ->toBe(2);
});

test('replays the stored response without calling the real handler again for a repeated key with the same body', function (): void {
    $middleware = apiIdempotencyTestMiddleware();
    [$handler, $callCount] = apiIdempotencyCountingHandler();
    $key = 'unit-test-' . uniqid();
    $request = new ServerRequest('POST', '/api/v1/images/searches', [
        'Idempotency-Key' => $key,
    ], '{"tags":[1]}')
        ->withAttribute(RouteResult::class, RouteResult::found(TagCreateController::class, []));

    $first = $middleware->process($request, $handler);
    $second = $middleware->process($request, $handler);

    expect($callCount())
        ->toBe(1);
    expect((string) $second->getBody())
        ->toBe((string) $first->getBody());
    expect($second->getStatusCode())
        ->toBe($first->getStatusCode());
});

test('rejects a repeated key with a different body with 400, without calling the real handler again', function (): void {
    $middleware = apiIdempotencyTestMiddleware();
    [$handler, $callCount] = apiIdempotencyCountingHandler();
    $key = 'unit-test-conflict-' . uniqid();
    $first = new ServerRequest('POST', '/api/v1/images/searches', [
        'Idempotency-Key' => $key,
    ], '{"tags":[1]}')
        ->withAttribute(RouteResult::class, RouteResult::found(TagCreateController::class, []));
    $second = new ServerRequest('POST', '/api/v1/images/searches', [
        'Idempotency-Key' => $key,
    ], '{"tags":[2]}')
        ->withAttribute(RouteResult::class, RouteResult::found(TagCreateController::class, []));

    $middleware->process($first, $handler);
    $response = $middleware->process($second, $handler);

    expect($callCount())
        ->toBe(1);
    expect($response->getStatusCode())
        ->toBe(400);
});

test('calls the real handler every time for an excluded tus controller even when Idempotency-Key is present', function (): void {
    $middleware = apiIdempotencyTestMiddleware();
    [$handler, $callCount] = apiIdempotencyCountingHandler();
    $key = 'unit-test-tus-' . uniqid();
    $request = new ServerRequest('PATCH', '/api/v1/uploads/some-id', [
        'Idempotency-Key' => $key,
    ])
        ->withAttribute(RouteResult::class, RouteResult::found(TusUploadPatchController::class, []));

    $middleware->process($request, $handler);
    $middleware->process($request, $handler);

    expect($callCount())
        ->toBe(2);
});

test('calls the real handler every time for a GET request even when Idempotency-Key is present', function (): void {
    $middleware = apiIdempotencyTestMiddleware();
    [$handler, $callCount] = apiIdempotencyCountingHandler();
    $key = 'unit-test-get-' . uniqid();
    $request = new ServerRequest('GET', '/api/v1/images/searches', [
        'Idempotency-Key' => $key,
    ])
        ->withAttribute(RouteResult::class, RouteResult::found(TagCreateController::class, []));

    $middleware->process($request, $handler);
    $middleware->process($request, $handler);

    expect($callCount())
        ->toBe(2);
});
