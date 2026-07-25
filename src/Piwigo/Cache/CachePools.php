<?php

declare(strict_types=1);

namespace Piwigo\Cache;

use Psr\Cache\CacheItemPoolInterface;

/**
 * Named cache pools (P11 gap, closed P23 batch 2). `CacheFactory::create()`
 * had exactly one real caller before this (config/container.php's single
 * generic `CacheItemPoolInterface` entry, itself only consumed by
 * `CacheClearCommand`) -- every concern that needs caching shared one flat
 * namespace with no way to size/expire/clear them independently. Each
 * method here is a distinct namespace on the same configured backend
 * (still one physical adapter -- APCu/Redis/filesystem -- chosen the usual
 * way via `CacheFactory`), with a TTL matching what P23's cache-table
 * rationalization (batch 3) needs for each of the three MyISAM-era tables
 * it replaces.
 *
 * A plain static factory, matching `DbConnection`/`ConfigLoader`'s own
 * shape -- not a constructor-injected service, since building a pool has no
 * dependencies of its own to inject. Domain consumers (wired in batch 3, not
 * here -- this batch only builds the infrastructure) receive a concrete
 * `CacheItemPoolInterface` via a `config/container.php` factory entry that
 * calls the relevant method here, the same way `Connection::class`'s entry
 * calls `DbConnection::build()`.
 *
 * `rate_limiter` is deliberately not built -- genuinely P28 scope, no
 * consumer exists anywhere in this codebase yet.
 */
final class CachePools
{
    /**
     * CurrentConfig's own properties are already an in-process static
     * (ConfigLoader/ConfigService, P13/P23 batch 1) -- this pool is for
     * cross-*process* reuse (APCu/Redis/filesystem persist between
     * requests; a plain PHP static doesn't). Real consumer: ConfigService::
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
     * 30s TTL -- matches docs/PLAN-REPLAY.md P23's stated design for the
     * `user_cache.forbidden_categories` replacement (batch 3): short enough
     * that a permission change (revoking a category, editing a group)
     * becomes visible well within one user session, long enough to avoid
     * recomputing PermissionService::getForbiddenCategories()'s multi-query
     * calculation on every single request for the same user.
     */
    public static function permissions(): CacheItemPoolInterface
    {
        return CacheFactory::create(namespace: 'piwigo.permissions', defaultLifetime: 30);
    }

    /**
     * 300s TTL -- matches docs/PLAN-REPLAY.md P23's stated design for the
     * `user_cache_categories` replacement (batch 3): per-user per-album
     * counts, cheaper to hold slightly stale than to recompute the
     * `COUNT(*) ... GROUP BY category_id` rollup on every category listing.
     */
    public static function categoryTree(): CacheItemPoolInterface
    {
        return CacheFactory::create(namespace: 'piwigo.category_tree', defaultLifetime: 300);
    }

    /**
     * No TTL from this doc's own design notes yet -- tag cloud computation
     * (Piwigo\Tag\TagService::getAvailableTags(), P23 batch 8c) still
     * caches through the older PersistentFileCache mechanism
     * (Piwigo\Cache\CurrentPersistentCache, formerly `global
     * $persistent_cache;`), unchanged from the pre-port free function, so
     * there's no real consumer of this pool to derive a TTL from yet. Uses
     * the same 300s default as categoryTree() (a comparable "expensive
     * aggregate, tolerable staleness" shape) until a real caller's actual
     * requirements are known.
     */
    public static function tagCloud(): CacheItemPoolInterface
    {
        return CacheFactory::create(namespace: 'piwigo.tag_cloud', defaultLifetime: 300);
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
     * 30s TTL -- same reasoning as permissions() above. Gap-closure Stage
     * 4a (docs/plan/gap-closure-p0-p23.md): replaces the
     * `user_cache.cache_update_time`-keyed immediate-invalidation pattern
     * previously used by Section\SectionPopulator's per-user visible
     * image-id list for a section.
     */
    public static function sectionImageIds(): CacheItemPoolInterface
    {
        return CacheFactory::create(namespace: 'piwigo.section_image_ids', defaultLifetime: 30);
    }

    /**
     * 30s TTL -- same reasoning as permissions() above. Gap-closure Stage
     * 4a: replaces the `cache_update_time`-keyed pattern previously used by
     * Search\SearchFilterRenderer's several filter-row/count caches and
     * Search\SearchService::getQuickSearchResults() (whose own explicit
     * 300s TTL is deliberately dropped to this pool's uniform 30s -- the
     * 300s was never a considered "search content changes slowly" choice
     * on its own, it was bundled with the permission-invalidation concern
     * cacheUpdateTime provided).
     */
    public static function searchResults(): CacheItemPoolInterface
    {
        return CacheFactory::create(namespace: 'piwigo.search_results', defaultLifetime: 30);
    }

    /**
     * 30s TTL -- same reasoning as permissions() above. Gap-closure Stage
     * 4a: replaces the `cache_update_time`-keyed pattern previously used by
     * Calendar\CalendarRenderer's calendar navigation-bar cache.
     */
    public static function calendarNav(): CacheItemPoolInterface
    {
        return CacheFactory::create(namespace: 'piwigo.calendar_nav', defaultLifetime: 30);
    }

    /**
     * 30s TTL -- same reasoning as permissions() above. Gap-closure Stage
     * 4a: replaces the `cache_update_time`-keyed pattern previously used by
     * Notification\NotificationService::getRecentPostDates().
     */
    public static function notifications(): CacheItemPoolInterface
    {
        return CacheFactory::create(namespace: 'piwigo.notifications', defaultLifetime: 30);
    }
}
