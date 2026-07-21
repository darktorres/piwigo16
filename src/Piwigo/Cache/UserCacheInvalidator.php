<?php

declare(strict_types=1);

namespace Piwigo\Cache;

use Piwigo\Db\DbConnection;

/**
 * Ported from admin/include/functions.php's invalidate_user_cache()/
 * invalidate_user_cache_nb_tags() (P23 batch 8d). Lives here, not
 * Piwigo\Users, because real callers span every layer -- including
 * Piwigo\Group\GroupService (L2aCoreDomain, a sibling namespace to
 * Piwigo\Users, same layer) -- this class is already L1Infrastructure,
 * reachable from every layer, resolving the constraint for every caller
 * at once, same pattern as this batch's own Piwigo\Core\FilesystemHelper.
 *
 * Legacy Coupling Retirement Phase 8, 8d: invalidate()'s confDeleteParam()
 * call goes through CurrentConfigService::get() (Tier 2) -- reachable both
 * directly (Admin\Install\UpgradeRunner.php) and transitively (via
 * Admin\updates::upgrade_to()), both covered by
 * InstallBootstrap::activateConfigService() on the install/upgrade path.
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
        \Piwigo\Config\CurrentConfigService::get()->confDeleteParam('count_orphans');
        \Piwigo\PluginConfig\EventDispatcher::get()->triggerNotify('invalidate_user_cache', $full);
    }

    /**
     * Only real callers are Piwigo\Tag\TagService (L2aCoreDomain) -- this
     * class stays L1Infrastructure (see class docblock), so it may not
     * depend on Piwigo\Users\CurrentUser itself (deptrac); each caller
     * syncs CurrentUser's own cached 'nb_available_tags' rawAttribute
     * right after calling this, same-layer as Piwigo\Users.
     */
    public static function invalidateNbTags(): void
    {
        new UserCacheRepository(DbConnection::build())->clearNbAvailableTags();
    }
}
