<?php

declare(strict_types=1);

namespace Piwigo\Cache;

/**
 * Gap-closure Stage 4i (docs/plan/gap-closure-p0-p23.md): replaces
 * `UserCacheInvalidator` now that `piwigo_user_cache`/
 * `piwigo_user_cache_categories` are dropped -- there's nothing left to
 * truncate or flag `need_update`. The real remaining purpose (already
 * established by Stage 4g's own fix to the old class, not new here): clear
 * the permission-related PSR-6 pools so a real access-affecting mutation
 * (category/group/image access change) takes effect on the very next
 * request, plus invalidate the cached orphan-image count
 * (`Image\ImageService::emptyLounge()`'s own `confUpdateParam`) since the
 * same mutations can affect it too. Lives here, not `Piwigo\Users`, for the
 * same reason the old class did: real callers span every layer, including
 * `Piwigo\Group\GroupService` (L2aCoreDomain) -- this class is
 * L1Infrastructure, reachable from every layer at once.
 *
 * Dropped from the old class, not carried forward:
 * - `$persistent_cache->purge(true)` -- grep confirms `CurrentPersistentCache`/
 *   `PersistentFileCache` have zero remaining permission/category-related
 *   content (Stage 4a already moved every real consumer onto
 *   `CachePools`); its only other real consumer is the unrelated
 *   `'compiled-templates'` maintenance action, which already purges it
 *   itself.
 * - the `invalidate_user_cache` `EventDispatcher::triggerNotify()` hook --
 *   grep confirms zero real in-tree handlers.
 * - the `bool $full` parameter (old `markNeedUpdate()` vs. truncate
 *   distinction) -- grep of all ~31 real call sites found zero callers
 *   ever passed `false`.
 */
final class PermissionCacheInvalidator
{
    public static function invalidate(): void
    {
        CachePools::permissions()->clear();
        CachePools::effectivePermissions()->clear();
        \Piwigo\Config\CurrentConfigService::current()->get()->confDeleteParam('count_orphans');
    }
}
