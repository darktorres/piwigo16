<?php

declare(strict_types=1);

namespace Piwigo\Core;

/**
 * P23 batch 8d: "recent" badge/icon computation relocated from
 * include/functions.inc.php -- no natural existing class home (real
 * callers span Category and Users domains: CategoryCatsRenderer/
 * CategoryService/CategoryDefaultRenderer, and UserService), stateless
 * beyond the per-request `ProcessCache::getStatic('get_icon')` memoization
 * bridge it reads/writes through (Legacy Coupling Retirement Track A
 * gap-fill batch G5, formerly `$cache['get_icon']`), unchanged.
 *
 * Legacy Coupling Retirement: DI+DBAL migration Phase 1e --
 * `\Piwigo\Db\MysqliDb::getRecentPeriod()`'s own real `SELECT SUBDATE(...)`
 * query (a genuine DB round-trip, unlike `SqlDialect::
 * getRecentPeriodExpression()`'s pure fragment-building sibling) is now
 * executed via `DbConnection::build()`, constructed inline -- this static
 * method has no instance state to inject a Connection into, and every
 * real caller invokes it statically (`RecentIconResolver::getIcon(...)`),
 * matching the established "static method constructs its own dependency
 * inline" precedent (same as `Cache\PermissionCacheInvalidator`).
 */
final class RecentIconResolver
{
    /**
     * return an array which will be sent to template to display recent icon
     *
     * @return false|array{}|array{TITLE: string, IS_CHILD_DATE: bool}
     */
    public static function getIcon(string $date, int $recentPeriod, bool $isChildDate = false): false|array
    {
        if ($date === '' || $date === '0') {
            return false;
        }

        $recent_period = $recentPeriod;

        $get_icon_cache_raw = ProcessCache::getStatic('get_icon');
        $get_icon_cache = is_array($get_icon_cache_raw) ? $get_icon_cache_raw : [];

        if (! isset($get_icon_cache['title'])) {
            $get_icon_cache['title'] = Lang::current()->t(
                'photos posted during the last %d days',
                $recent_period
            );
        }

        $icon = [
            'TITLE' => is_string($get_icon_cache['title']) ? $get_icon_cache['title'] : '',
            'IS_CHILD_DATE' => $isChildDate,
        ];

        if (isset($get_icon_cache[$date])) {
            ProcessCache::setStatic('get_icon', $get_icon_cache);
            return ((bool) $get_icon_cache[$date]) ? $icon : [];
        }

        if (! isset($get_icon_cache['sql_recent_date'])) {
            // Use MySql date in order to standardize all recent "actions/queries"
            $get_icon_cache['sql_recent_date'] = new \Piwigo\Db\SqlDialectExecutor(\Piwigo\Db\DbConnection::build())
                ->fetchRecentCutoffDate($recent_period);
        }

        $get_icon_cache[$date] = $date > $get_icon_cache['sql_recent_date'];
        ProcessCache::setStatic('get_icon', $get_icon_cache);

        return $get_icon_cache[$date] ? $icon : [];
    }
}
