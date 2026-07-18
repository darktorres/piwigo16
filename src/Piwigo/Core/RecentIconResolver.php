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
 * `\Piwigo\Db\MysqliDb::getRecentPeriod()` stays a bare call --
 * functions_mysqli.inc.php, relocate-only (batch 8f, finding 2).
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
            $get_icon_cache['sql_recent_date'] = \Piwigo\Db\MysqliDb::getRecentPeriod($recent_period);
        }

        $get_icon_cache[$date] = $date > $get_icon_cache['sql_recent_date'];
        ProcessCache::set('get_icon', $get_icon_cache);

        return $get_icon_cache[$date] ? $icon : [];
    }
}
