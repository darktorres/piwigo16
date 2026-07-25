<?php

declare(strict_types=1);

namespace Piwigo\Permission;

use Psr\Cache\CacheItemPoolInterface;

/**
 * Per-user cached wrapper around PermissionService::getForbiddenCategories()
 * (P23 Stage 1d, wiring CachePools::permissions()). 30s TTL ({@see
 * \Piwigo\Cache\CachePools::permissions()}) means a permission change
 * (revoking a category, editing a group) becomes visible well within one
 * user session, long enough to avoid recomputing the multi-query
 * calculation on every request for the same user.
 *
 * A separate class rather than a PermissionService method: PermissionService
 * is constructed directly (`new PermissionService(...)`, no DI container) at
 * ~40 call sites throughout src/Piwigo -- adding a cache dependency to its
 * own constructor would break every one of them, same reasoning as
 * {@see \Piwigo\Category\CategoryTreeCache}.
 *
 * Deliberately NOT used by UserService::generateUserCache() -- that method
 * is the authoritative writer of `user_cache.forbidden_categories` (the
 * value this cache exists to avoid recomputing elsewhere), so it must always
 * read a fresh value, never a stale one from this pool.
 */
final readonly class ForbiddenCategoriesCache
{
    public function __construct(
        private PermissionService $service,
        private CacheItemPoolInterface $pool,
    ) {}

    public function getForUser(int $userId, string $userStatus): string
    {
        $item = $this->pool->getItem('forbidden_' . $userId);
        if ($item->isHit()) {
            $cached = $item->get();
            if (is_string($cached)) {
                return $cached;
            }
        }

        $forbidden = $this->service->getForbiddenCategories($userId, $userStatus);

        $item->set($forbidden);
        $this->pool->save($item);

        return $forbidden;
    }
}
