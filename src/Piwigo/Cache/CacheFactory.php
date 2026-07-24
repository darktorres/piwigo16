<?php

declare(strict_types=1);

namespace Piwigo\Cache;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Adapter\ApcuAdapter;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Adapter\RedisAdapter;

/**
 * Creates a PSR-6 cache pool for the requested (or env-configured) backend.
 *
 * Adapter choice via PIWIGO_CACHE_ADAPTER (apcu|redis|filesystem) --
 * CurrentConfig::cacheAdapter() doesn't exist yet (P13). When unset, auto-detects:
 * APCu if available, else filesystem -- matches the doc's own documented
 * fallback design, not a workaround; ext-apcu isn't installed in every
 * environment (confirmed absent in this one). An *explicit* request for an
 * unavailable adapter (PIWIGO_CACHE_ADAPTER=apcu with no ext-apcu) fails
 * loudly instead of silently falling back -- that's a real misconfiguration
 * worth surfacing, unlike the auto-detect default case.
 *
 * `$namespace`/`$defaultLifetime` (P23 batch 2) let CachePools build several
 * independent, non-colliding pools sharing one backend -- every Symfony
 * cache adapter used here accepts both as its own first two constructor
 * params, so this just threads them through instead of hardcoding 'piwigo'/0
 * the way every call site did until now (still the default, so the existing
 * single generic pool wired in config/container.php is unaffected).
 */
final class CacheFactory
{
    public static function create(?string $adapter = null, string $namespace = 'piwigo', int $defaultLifetime = 0): CacheItemPoolInterface
    {
        $explicit = $adapter ?? self::envAdapter();
        $resolved = $explicit ?? (ApcuAdapter::isSupported() ? 'apcu' : 'filesystem');

        return match ($resolved) {
            'apcu' => self::buildApcu($namespace, $defaultLifetime),
            'redis' => self::buildRedis($namespace, $defaultLifetime),
            'filesystem' => self::buildFilesystem($namespace, $defaultLifetime),
            default => throw new \InvalidArgumentException(
                "Unknown cache adapter '{$resolved}'. Expected apcu, redis, or filesystem."
            ),
        };
    }

    private static function envAdapter(): ?string
    {
        $env = getenv('PIWIGO_CACHE_ADAPTER');

        return $env !== false && $env !== '' ? $env : null;
    }

    private static function buildApcu(string $namespace, int $defaultLifetime): CacheItemPoolInterface
    {
        if (! ApcuAdapter::isSupported()) {
            throw new \RuntimeException(
                'APCu extension is not available. Install/enable ext-apcu or choose a different PIWIGO_CACHE_ADAPTER.'
            );
        }

        return new ApcuAdapter($namespace, $defaultLifetime);
    }

    private static function buildFilesystem(string $namespace, int $defaultLifetime): CacheItemPoolInterface
    {
        return new FilesystemAdapter($namespace, $defaultLifetime, dirname(__DIR__, 3) . '/_data/cache/');
    }

    private static function buildRedis(string $namespace, int $defaultLifetime): CacheItemPoolInterface
    {
        $dsn = getenv('PIWIGO_REDIS_DSN');
        $dsn = $dsn !== false && $dsn !== '' ? $dsn : 'redis://localhost:6379';

        $client = RedisAdapter::createConnection($dsn);

        return new RedisAdapter($client, $namespace, $defaultLifetime);
    }
}
