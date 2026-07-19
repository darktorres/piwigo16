<?php

declare(strict_types=1);

namespace Piwigo\Core;

/**
 * P23 batch 8d: "recent" badge/icon computation relocated from
 * include/functions.inc.php -- no natural existing class home (real
 * callers span Category and Users domains: CategoryCatsRenderer/
 * CategoryService/CategoryDefaultRenderer, and UserService), stateless
 * beyond the per-request `ProcessCache::get('get_icon')` memoization
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
 * inline" precedent (same as `Cache\UserCacheInvalidator`).
 */
final class RecentIconResolver
{
    /**
     * return an array which will be sent to template to display recent icon
     *
     * @return false|array<string, mixed>
     */
    public static function getIcon(string $date, int $recentPeriod, bool $isChildDate = false): false|array
    {
        if ($date === '' || $date === '0') {
            return false;
        }

        $recent_period = $recentPeriod;

        $get_icon_cache_raw = ProcessCache::get('get_icon');
        $get_icon_cache = is_array($get_icon_cache_raw) ? $get_icon_cache_raw : [];

        if (! isset($get_icon_cache['title'])) {
            $get_icon_cache['title'] = l10n(
                'photos posted during the last %d days',
                $recent_period
            );
        }

        $icon = [
            'TITLE' => $get_icon_cache['title'],
            'IS_CHILD_DATE' => $isChildDate,
        ];

        if (isset($get_icon_cache[$date])) {
            ProcessCache::set('get_icon', $get_icon_cache);
            return ((bool) $get_icon_cache[$date]) ? $icon : [];
        }

        if (! isset($get_icon_cache['sql_recent_date'])) {
            // Use MySql date in order to standardize all recent "actions/queries"
            $sql_recent_date = \Piwigo\Db\DbConnection::build()->fetchOne(
                'SELECT ' . \Piwigo\Db\SqlDialect::getRecentPeriodExpression($recent_period)
            );
            $get_icon_cache['sql_recent_date'] = is_string($sql_recent_date) ? $sql_recent_date : '';
        }

        $get_icon_cache[$date] = $date > $get_icon_cache['sql_recent_date'];
        ProcessCache::set('get_icon', $get_icon_cache);

        return $get_icon_cache[$date] ? $icon : [];
    }
}
