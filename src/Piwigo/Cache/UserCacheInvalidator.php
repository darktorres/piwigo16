<?php

declare(strict_types=1);

namespace Piwigo\Cache;

use Piwigo\Core\Logger;
use Piwigo\Db\Tables;

/**
 * Ported from admin/include/functions.php's invalidate_user_cache()/
 * invalidate_user_cache_nb_tags() (P23 batch 8d). Lives here, not
 * Piwigo\Users, because real callers span every layer -- including
 * Piwigo\Group\GroupService (L2aCoreDomain, a sibling namespace to
 * Piwigo\Users, same layer) -- this class is already L1Infrastructure,
 * reachable from every layer, resolving the constraint for every caller
 * at once, same pattern as this batch's own Piwigo\Core\FilesystemHelper.
 */
final class UserCacheInvalidator
{
    public static function invalidate(bool $full = true): void
    {
        /**
         * @var PersistentCache $persistent_cache
         * @var Logger $logger
         */
        global $persistent_cache, $logger;

        $logger->info(__FUNCTION__ . ' called');

        if ($full) {
            $query = '
TRUNCATE TABLE ' . Tables::userCacheCategories() . ';';
            \Piwigo\Db\MysqliDb::query($query);
            $query = '
TRUNCATE TABLE ' . Tables::userCache() . ';';
            \Piwigo\Db\MysqliDb::query($query);
        } else {
            $query = '
UPDATE ' . Tables::userCache() . '
  SET need_update = \'true\';';
            \Piwigo\Db\MysqliDb::query($query);
        }
        $persistent_cache->purge(true);
        \Piwigo\Config\ConfigDb::confDeleteParam('count_orphans');
        trigger_notify('invalidate_user_cache', $full);
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
        $query = '
UPDATE ' . Tables::userCache() . '
  SET nb_available_tags = NULL';
        \Piwigo\Db\MysqliDb::query($query);
    }
}
