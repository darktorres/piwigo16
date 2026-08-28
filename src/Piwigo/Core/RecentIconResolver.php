<?php

declare(strict_types=1);

namespace Piwigo\Core;

use Piwigo\Core\Projection\RecentIcon;
use Piwigo\Db\DbConnection;
use Piwigo\Db\SqlDialectExecutor;

/**
 * "Recent" badge/icon computation. Callers span the Category and Users
 * domains (CategoryCatsRenderer/CategoryService/CategoryDefaultRenderer,
 * and UserService). Stateless beyond the per-request `get_icon`
 * memoization bridge it reads/writes through `ProcessCache`.
 *
 * The `SELECT SUBDATE(...)` query is a genuine DB round-trip (unlike
 * `SqlDialect::getRecentPeriodExpression()`'s pure fragment-building
 * sibling); it is executed via a `DbConnection::build()` constructed
 * inline, since this static method has no instance state to inject a
 * connection into. `ProcessCache`/`Lang` are taken as explicit method
 * parameters instead, since every real caller already has both
 * available.
 */
final class RecentIconResolver
{
    /**
     * The recent-posted marker for a template, or null when there is none
     * to show -- which covers both "no date to judge" and "that date is not
     * recent", the two cases this used to return `false` and `[]` for.
     */
    public static function getIcon(string $date, int $recentPeriod, ProcessCache $processCache, Lang $lang, bool $isChildDate = false): ?RecentIcon
    {
        if ($date === '' || $date === '0') {
            return null;
        }

        $recent_period = $recentPeriod;

        $get_icon_cache_raw = $processCache->get('get_icon');
        $get_icon_cache = is_array($get_icon_cache_raw) ? $get_icon_cache_raw : [];

        if (! isset($get_icon_cache['title'])) {
            $get_icon_cache['title'] = $lang->t(
                'photos posted during the last %d days',
                $recent_period
            );
        }

        $icon = new RecentIcon(
            title: is_string($get_icon_cache['title']) ? $get_icon_cache['title'] : '',
            isChildDate: $isChildDate,
        );

        if (isset($get_icon_cache[$date])) {
            $processCache->set('get_icon', $get_icon_cache);
            return ((bool) $get_icon_cache[$date]) ? $icon : null;
        }

        if (! isset($get_icon_cache['sql_recent_date'])) {
            // Use MySql date in order to standardize all recent "actions/queries"
            $get_icon_cache['sql_recent_date'] = new SqlDialectExecutor(DbConnection::build())
                ->fetchRecentCutoffDate($recent_period);
        }

        $get_icon_cache[$date] = $date > $get_icon_cache['sql_recent_date'];
        $processCache->set('get_icon', $get_icon_cache);

        return $get_icon_cache[$date] ? $icon : null;
    }
}
