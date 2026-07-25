<?php

declare(strict_types=1);

namespace Piwigo\Cache;

use Piwigo\Db\DbConnection;

/**
 * Ported from admin/include/functions.php's invalidate_user_cache()
 * (P23 batch 8d; the sibling invalidate_user_cache_nb_tags() port,
 * invalidateNbTags(), was deleted in gap-closure Stage 4f once its own
 * `user_cache.nb_available_tags` write was confirmed dead -- the read
 * side has never touched that column, only CurrentUser::rawAttributes).
 * Lives here, not Piwigo\Users, because real callers span every layer --
 * including Piwigo\Group\GroupService (L2aCoreDomain, a sibling namespace
 * to Piwigo\Users, same layer) -- this class is already L1Infrastructure,
 * reachable from every layer, resolving the constraint for every caller
 * at once, same pattern as this batch's own Piwigo\Core\FilesystemHelper.
 *
 * Legacy Coupling Retirement Phase 8, 8d: invalidate()'s confDeleteParam()
 * call goes through CurrentConfigService::get() (Tier 2) -- reachable via
 * Admin\Extensions\CoreUpdateService::upgradeTo() (the admin Updates page's
 * core-version-upgrade action), covered by
 * InstallBootstrap::activateConfigService() on the normal request path.
 *
 * Gap-closure Stage 4g gap-closure (2026-07-25): also clears
 * `CachePools::permissions()`/`effectivePermissions()` -- a real Browser-
 * test-caught regression, not a design afterthought. Deleting Stage 4g's
 * lock/wait/503 mechanism removed the *only* thing that used to force an
 * immediate, synchronous permission-snapshot recompute on the very next
 * request after a real permission change (category made private/public,
 * group/user access granted/revoked, an image's own access level
 * changed); every one of those real mutations already calls this method
 * (~30 call sites: GroupService, Ws\PwgGroups/PwgCategories/PwgImages,
 * Admin\CatListPageRenderer/PictureModifyPageRenderer, etc.), so hooking
 * the clear here restores the "revocation takes effect on the next
 * request" guarantee for every one of them at once, without reintroducing
 * a lock -- a `CacheItemPoolInterface::clear()` is a cheap, uncoordinated
 * delete, not something concurrent requests need to wait on.
 */
final class UserCacheInvalidator
{
    public static function invalidate(bool $full = true): void
    {
        $persistent_cache = CurrentPersistentCache::get();
        $logger = \Piwigo\Core\CurrentLogger::get();

        $logger->info(__FUNCTION__ . ' called');

        $repo = new UserCacheRepository(DbConnection::build());
        if ($full) {
            $repo->truncateUserCacheCategories();
            $repo->truncateUserCache();
        } else {
            $repo->markNeedUpdate();
        }
        if (! $persistent_cache instanceof PersistentCache) {
            throw new \LogicException('Piwigo\Cache\CurrentPersistentCache not initialised.');
        }
        $persistent_cache->purge(true);
        CachePools::permissions()->clear();
        CachePools::effectivePermissions()->clear();
        \Piwigo\Config\CurrentConfigService::get()->confDeleteParam('count_orphans');
        \Piwigo\PluginConfig\EventDispatcher::get()->triggerNotify('invalidate_user_cache', $full);
    }
}
