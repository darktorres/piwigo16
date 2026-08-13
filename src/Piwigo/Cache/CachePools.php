<?php

declare(strict_types=1);

namespace Piwigo\Cache;

use Psr\Cache\CacheItemPoolInterface;

/**
 * Named cache pools. Each method here is a distinct namespace on the same
 * configured backend (still one physical adapter -- APCu/Redis/filesystem
 * -- chosen the usual way via `CacheFactory`), each with a TTL suited to
 * what it caches.
 *
 * A plain static factory, matching `DbConnection`/`ConfigLoader`'s own
 * shape -- not a constructor-injected service, since building a pool has no
 * dependencies of its own to inject. Domain consumers receive a concrete
 * `CacheItemPoolInterface` via a `config/container.php` factory entry that
 * calls the relevant method here, the same way `Connection::class`'s entry
 * calls `DbConnection::build()`.
 *
 * `rate_limiter` is deliberately not built -- no consumer exists anywhere
 * in this codebase yet.
 */
final class CachePools
{
    /**
     * Catch-all pool for cacheable work that doesn't warrant its own named
     * pool -- callers should still prefer a dedicated method here once they
     * have specific TTL/invalidation needs, matching the reasoning that
     * split the other four out.
     */
    public static function general(): CacheItemPoolInterface
    {
        return CacheFactory::create(namespace: 'piwigo.general');
    }

    /**
     * No TTL, and the namespace itself carries the invalidation: callers
     * append a hash derived from the entity source files' own mtimes (see
     * Piwigo\Db\EntityManagerFactory::build()), so an edited entity class
     * lands in a fresh, disjoint namespace on its very next use instead of
     * needing a manual clear -- same "trust file mtimes" model OPcache
     * itself already uses for these same files. Real consumer:
     * EntityManagerFactory::build()'s Doctrine ORM metadata cache --
     * the same Xdebug profile put this at ~17-18% of bootstrap time
     * with isDevMode:true's throwaway in-memory-only cache.
     */
    public static function doctrineMetadata(string $mtimeHash): CacheItemPoolInterface
    {
        return CacheFactory::create(namespace: 'piwigo.doctrine_metadata.' . $mtimeHash);
    }
}
