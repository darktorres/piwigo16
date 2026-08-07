<?php

declare(strict_types=1);

namespace Piwigo\Core;

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
     * return an array which will be sent to template to display recent icon
     *
     * @return false|array{}|array{TITLE: string, IS_CHILD_DATE: bool}
     */
    public static function getIcon(string $date, int $recentPeriod, ProcessCache $processCache, Lang $lang, bool $isChildDate = false): false|array
    {
        if ($date === '' || $date === '0') {
            return false;
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

        $icon = [
            'TITLE' => is_string($get_icon_cache['title']) ? $get_icon_cache['title'] : '',
            'IS_CHILD_DATE' => $isChildDate,
        ];

        if (isset($get_icon_cache[$date])) {
            $processCache->set('get_icon', $get_icon_cache);
            return ((bool) $get_icon_cache[$date]) ? $icon : [];
        }

        if (! isset($get_icon_cache['sql_recent_date'])) {
            // Use MySql date in order to standardize all recent "actions/queries"
            $get_icon_cache['sql_recent_date'] = new SqlDialectExecutor(DbConnection::build())
                ->fetchRecentCutoffDate($recent_period);
        }

        $get_icon_cache[$date] = $date > $get_icon_cache['sql_recent_date'];
        $processCache->set('get_icon', $get_icon_cache);

        return $get_icon_cache[$date] ? $icon : [];
    }
}
