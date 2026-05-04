<?php

declare(strict_types=1);

namespace Piwigo\Db;

/**
 * MySQL SQL-fragment builders — all methods return a SQL expression string,
 * none of them execute any query.  Use these instead of the legacy
 * pwg_db_get_*() global functions.
 */
final class SqlExpr
{
    /** Returns a YEAR(…) expression. */
    public static function year(string $date): string
    {
        return 'YEAR(' . $date . ')';
    }

    /** Returns a MONTH(…) expression. */
    public static function month(string $date): string
    {
        return 'MONTH(' . $date . ')';
    }

    /** Returns a WEEK(…) expression, with optional mode. */
    public static function week(string $date, ?int $mode = null): string
    {
        return $mode !== null ? "WEEK($date, $mode)" : "WEEK($date)";
    }

    /** Returns a DAYOFMONTH(…) expression. */
    public static function dayOfMonth(string $date): string
    {
        return 'DAYOFMONTH(' . $date . ')';
    }

    /** Returns a DAYOFWEEK(…) expression (1=Sunday … 7=Saturday). */
    public static function dayOfWeek(string $date): string
    {
        return 'DAYOFWEEK(' . $date . ')';
    }

    /** Returns a WEEKDAY(…) expression (0=Monday … 6=Sunday). */
    public static function weekday(string $date): string
    {
        return 'WEEKDAY(' . $date . ')';
    }

    /** Returns DATE_FORMAT(…, '%Y%m') — the YYYYMM integer string. */
    public static function dateYYYYMM(string $date): string
    {
        return "DATE_FORMAT($date, '%Y%m')";
    }

    /** Returns DATE_FORMAT(…, '%m%d') — the MMDD integer string. */
    public static function dateMMDD(string $date): string
    {
        return "DATE_FORMAT($date, '%m%d')";
    }

    /** Returns HOUR(…). */
    public static function hour(string $date): string
    {
        return 'HOUR(' . $date . ')';
    }

    /**
     * Returns SUBDATE(date, INTERVAL $period DAY).
     * $date may be a column name or 'CURRENT_DATE'.
     */
    public static function recentPeriodExpr(int|string $period, string $date = 'CURRENT_DATE'): string
    {
        $sqlDate = $date === 'CURRENT_DATE' ? 'CURRENT_DATE' : "'" . $date . "'";
        return 'SUBDATE(' . $sqlDate . ', INTERVAL ' . $period . ' DAY)';
    }

    /**
     * Returns SUBDATE(NOW(), INTERVAL $seconds SECOND).
     * Used for flood-protection WHERE clauses.
     */
    public static function floodPeriodExpr(int|string $seconds): string
    {
        return 'SUBDATE(NOW(), INTERVAL ' . $seconds . ' SECOND)';
    }

    /** Returns CONCAT_WS('sep', col1, col2, …). */
    /** @param string[] $columns */
    public static function concatWs(array $columns, string $separator): string
    {
        return "CONCAT_WS('$separator'," . implode(',', $columns) . ')';
    }
}
