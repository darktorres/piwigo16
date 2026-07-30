<?php

declare(strict_types=1);

namespace Piwigo\Db;

use Doctrine\DBAL\Connection;

/**
 * Real DB round-trips for a `SqlDialect`-built date expression --
 * {@see SqlDialect} itself is a deliberate pure string-builder with no
 * connection dependency (its own class docblock: "every one of these is a
 * pure string builder... no connection dependency"), so the handful of
 * real callers that need an actual computed value back (not just the SQL
 * fragment to splice into their own larger query) go through this class
 * instead, rather than each hand-rolling `DbConnection::build()->fetchOne('SELECT
 * ' . ...)` inline.
 */
final class SqlDialectExecutor
{
    public function __construct(
        private readonly Connection $conn,
    ) {}

    /**
     * The real cutoff date for "recent" (last $period days), computed
     * server-side for dialect consistency -- Core\RecentIconResolver's own
     * "is this photo/comment/category recent" comparison baseline.
     */
    public function fetchRecentCutoffDate(int|string $period, string $date = 'CURRENT_DATE'): string
    {
        $recentPeriodExpr = SqlDialect::getRecentPeriodExpression($period, $date);
        $value = $this->conn->fetchOne(<<<SQL
            SELECT {$recentPeriodExpr}
            SQL);

        return is_string($value) ? $value : '';
    }

    /**
     * NOW() + 1 day, computed server-side -- Controller\
     * ProfileFormHandler::loadIntoTemplate()'s own default API-key
     * expiration date.
     */
    public function fetchTomorrow(): string
    {
        $value = $this->conn->fetchOne(<<<SQL
            SELECT ADDDATE(NOW(), INTERVAL 1 DAY)
            SQL);

        return is_string($value) ? $value : '';
    }

    /**
     * NOW() + N days for every N in $days, in one round trip, keyed by N
     * -- Controller\ProfileFormHandler::loadIntoTemplate()'s own API-key
     * expiration-choice list ($conf['api_key_duration']).
     *
     * @param  list<int>  $days
     * @return array<int, mixed> keyed by day count
     */
    public function fetchFutureDatesFor(array $days): array
    {
        if ($days === []) {
            return [];
        }

        $columns = [];
        foreach ($days as $day) {
            $columns[] = "ADDDATE(NOW(), INTERVAL {$day} DAY) as `{$day}`";
        }

        $columnsSql = implode(', ', $columns);
        $row = $this->conn->fetchAssociative(<<<SQL
            SELECT
              {$columnsSql}
            SQL);

        if ($row === false) {
            return [];
        }

        $byDay = [];
        foreach ($days as $day) {
            $key = (string) $day;
            $byDay[$day] = array_key_exists($key, $row) ? $row[$key] : null;
        }

        return $byDay;
    }
}
