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
     * CurrentConfig's own properties are already an in-process static --
     * this pool is for cross-*process* reuse (APCu/Redis/filesystem persist
     * between requests; a plain PHP static doesn't). Real consumer: ConfigService::
     * allRowsFromCacheOrDb(), caching the ~280-row param => value map that
     * loadConfFromDb() would otherwise re-hydrate via Doctrine ORM on every
     * request. No TTL -- ConfigService's own confUpdateParam()/
     * confDeleteParam() clear this pool after every real write instead of
     * relying on expiry.
     */
    public static function config(): CacheItemPoolInterface
    {
        return CacheFactory::create(namespace: 'piwigo.config');
    }

    /**
     * 30s TTL -- short enough that a permission change (revoking a
     * category, editing a group) becomes visible well within one user
     * session, long enough to avoid recomputing
     * PermissionService::getForbiddenCategories()'s multi-query
     * calculation on every single request for the same user.
     */
    public static function permissions(): CacheItemPoolInterface
    {
        return CacheFactory::create(namespace: 'piwigo.permissions', defaultLifetime: 30);
    }

    /**
     * 30s TTL -- same reasoning as permissions() above. Holds the
     * *effective* permission snapshot (forbidden categories, image access
     * type/list, total image count, and last photo date), distinct from
     * permissions() above, which only ever holds the narrower structural
     * value.
     */
    public static function effectivePermissions(): CacheItemPoolInterface
    {
        return CacheFactory::create(namespace: 'piwigo.effective_permissions', defaultLifetime: 30);
    }

    /**
     * 300s TTL -- per-user per-album counts, cheaper to hold slightly
     * stale than to recompute the `COUNT(*) ... GROUP BY category_id`
     * rollup on every category listing.
     */
    public static function categoryTree(): CacheItemPoolInterface
    {
        return CacheFactory::create(namespace: 'piwigo.category_tree', defaultLifetime: 300);
    }

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

    /**
     * 30s TTL -- same reasoning as permissions() above. Used by
     * Search\SearchFilterRenderer's several filter-row/count caches and
     * Search\SearchService::getQuickSearchResults(): a uniform 30s TTL,
     * not a longer "search content changes slowly" value, since
     * invalidation here is really about permission changes, not content
     * staleness.
     */
    public static function searchResults(): CacheItemPoolInterface
    {
        return CacheFactory::create(namespace: 'piwigo.search_results', defaultLifetime: 30);
    }

    /**
     * 30s TTL -- same reasoning as permissions() above. Used by
     * Calendar\CalendarRenderer's calendar navigation-bar cache.
     */
    public static function calendarNav(): CacheItemPoolInterface
    {
        return CacheFactory::create(namespace: 'piwigo.calendar_nav', defaultLifetime: 30);
    }

    /**
     * 30s TTL -- same reasoning as permissions() above. Used by
     * Notification\NotificationService::getRecentPostDates().
     */
    public static function notifications(): CacheItemPoolInterface
    {
        return CacheFactory::create(namespace: 'piwigo.notifications', defaultLifetime: 30);
    }
}
