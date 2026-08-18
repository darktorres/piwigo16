<?php

declare(strict_types=1);

namespace Piwigo\Http\Middleware;

use Nyholm\Psr7\Response;
use Override;
use Piwigo\Cache\IdempotencyCachePool;
use Piwigo\Http\ResponseFactory;
use Piwigo\Routing\RouteMatchStatus;
use Piwigo\Routing\Router;
use Piwigo\Routing\RouteResult;
use Piwigo\Users\CurrentUser;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * SEC-65 -- opt-in `Idempotency-Key` replay store. Runs between
 * `ApiErrorMiddleware` and `ControllerInvokerMiddleware` -- needs the
 * real `RouteResult::Found` to know the real controller class and scope
 * by `/api/v1`, and must wrap `ControllerInvokerMiddleware`'s own
 * `$handler->handle()` call to capture whatever response the real
 * controller produces.
 *
 * Does nothing unless the client sends a real `Idempotency-Key` header --
 * zero behavior change for every caller that doesn't use the feature.
 * Scoped to `POST`/`PUT`/`PATCH`/`DELETE` under `/api/v1/*`, excluding
 * routes marked `_bypass_idempotency` in `Bootstrap\RouteDefinitions`
 * (the 5 tus 1.0.0 upload routes -- tus already has its own offset/
 * resumability protocol, and replaying a cached response would corrupt
 * that instead of helping it). Route metadata, not a controller-class
 * check: this class (L3Presentation) may not depend on concrete
 * controllers (L4Integration) per `deptrac.yaml`'s ruleset.
 *
 * Cache key includes the resolved user id (guest requests share one id,
 * an accepted simplification for the one public mutating endpoint in
 * scope, `ImageFilteredSearchCreateController`) so one client's key never
 * collides with another's. A hit whose stored request-body hash matches
 * replays the stored response without invoking the real controller at
 * all -- the load-bearing behavior, since otherwise the underlying side
 * effect would run twice. A hit with a different body hash is rejected
 * with 400 -- this project's own "malformed request" convention
 * (`Http\JsonBody::decode()`'s own invalid-JSON case), not a 422
 * "right shape wrong value" case.
 *
 * Explicitly descoped, not silently dropped: true concurrent-duplicate-
 * request locking (two identical requests racing before the first
 * completes) would need real cross-process locking, a materially bigger
 * feature than a replay cache -- only the common "client retried after a
 * dropped connection" and "double-click" cases are handled here.
 */
final readonly class ApiIdempotencyMiddleware implements MiddlewareInterface
{
    private const string API_PREFIX = '/api/v1/';

    /**
     * @var list<string>
     */
    private const array GUARDED_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    public function __construct(
        private IdempotencyCachePool $idempotencyCachePool,
        private CurrentUser $currentUser,
    ) {}

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $idempotencyKey = $request->getHeaderLine('Idempotency-Key');
        $result = $request->getAttribute(RouteResult::class);
        $path = Router::pathInfo($request, $request->getUri()->getPath());

        if (
            $idempotencyKey === ''
            || ! $result instanceof RouteResult
            || $result->status !== RouteMatchStatus::Found
            || $result->handler === null
            || ! in_array($request->getMethod(), self::GUARDED_METHODS, true)
            || ! str_starts_with($path, self::API_PREFIX)
            || $result->bypassIdempotency
        ) {
            return $handler->handle($request);
        }

        $bodyHash = hash('sha256', (string) $request->getBody());
        $cacheKey = hash('sha256', $request->getMethod() . '|' . $path . '|' . $this->currentUser->get()->id->value . '|' . $idempotencyKey);

        $item = $this->idempotencyCachePool->getItem($cacheKey);
        if ($item->isHit()) {
            $stored = self::narrowStored($item->get());
            if ($stored !== null && $stored['bodyHash'] === $bodyHash) {
                return new Response($stored['status'], $stored['headers'], $stored['body']);
            }

            if ($stored !== null) {
                return ResponseFactory::problem(
                    'Bad Request',
                    400,
                    'Idempotency-Key already used with a different request body.'
                );
            }
        }

        $response = $handler->handle($request);

        $captured = [
            'bodyHash' => $bodyHash,
            'status' => $response->getStatusCode(),
            'headers' => $response->getHeaders(),
            'body' => (string) $response->getBody(),
        ];
        $item->set($captured);
        $this->idempotencyCachePool->save($item);

        return new Response($captured['status'], $captured['headers'], $captured['body']);
    }

    /**
     * `CacheItemInterface::get()` is `mixed` by interface -- this class is
     * the only writer of this cache entry, so the shallow per-field
     * narrowing here is the real shape (same depth as
     * `ExtensionUpdateChecker::checkUpdatedExtensions()`'s own read of a
     * self-produced cache value).
     *
     * @return array{bodyHash: string, status: int, headers: array<string, list<string>>, body: string}|null
     */
    private static function narrowStored(mixed $stored): ?array
    {
        if (
            ! is_array($stored)
            || ! is_string($stored['bodyHash'] ?? null)
            || ! is_int($stored['status'] ?? null)
            || ! is_array($stored['headers'] ?? null)
            || ! is_string($stored['body'] ?? null)
        ) {
            return null;
        }

        /** @var array<string, list<string>> $headers */
        $headers = $stored['headers'];

        return [
            'bodyHash' => $stored['bodyHash'],
            'status' => $stored['status'],
            'headers' => $headers,
            'body' => $stored['body'],
        ];
    }
}
