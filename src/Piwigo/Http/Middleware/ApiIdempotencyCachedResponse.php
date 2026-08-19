<?php

declare(strict_types=1);

namespace Piwigo\Http\Middleware;

/**
 * {@see ApiIdempotencyMiddleware}'s own replay-cache entry -- the class
 * is the sole writer and reader of this cache value, so a plain
 * `instanceof` check on the deserialized value (never true for a
 * pre-existing array-shaped entry from before this VO existed, or any
 * other malformed value) is the real narrowing, same "cache is a real
 * persistence boundary, not something to over-trust" caution the former
 * per-field narrowing already had -- a stale/malformed hit is just
 * treated as a fresh miss, self-healing on the next write within the
 * cache's own TTL.
 */
final readonly class ApiIdempotencyCachedResponse
{
    /**
     * @param array<array<string>> $headers
     */
    public function __construct(
        public string $bodyHash,
        public int $status,
        public array $headers,
        public string $body,
    ) {}
}
