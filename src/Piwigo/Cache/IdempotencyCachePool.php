<?php

declare(strict_types=1);

namespace Piwigo\Cache;

/**
 * `Http\Middleware\ApiIdempotencyMiddleware`'s own replay store (SEC-65) --
 * one entry per `Idempotency-Key` the client actually sends, keyed by
 * method + path + user + the key itself. 86400s TTL is a common
 * idempotency-key retention window. Namespace/TTL are supplied by
 * config/container.php's own factory()
 * entry, not this class -- see AbstractNamedCachePool.
 */
final class IdempotencyCachePool extends AbstractNamedCachePool {}
