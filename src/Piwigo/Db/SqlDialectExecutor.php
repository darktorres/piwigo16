<?php

declare(strict_types=1);

namespace Piwigo\Db;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

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
     *
     * $period tightened to `int` alongside SqlDialect::
     * getRecentPeriodExpression()'s own signature (SQL-modernization
     * audit) -- this method's only real caller already passes a genuine
     * `int`.
     */
    public function fetchRecentCutoffDate(int $period, string $date = 'CURRENT_DATE'): string
    {
        // $recentPeriodExpr's own $date-quoting defect lives inside
        // SqlDialect::getRecentPeriodExpression() itself, not here -- see
        // that method's own docblock (SQL-modernization audit finding,
        // tracked for its 2 real non-default-$date callers' own staged
        // conversion). This method's only real caller never passes $date,
        // so the quoting branch never triggers here in practice; nothing
        // for this heredoc itself to fix.
        $recentPeriodExpr = SqlDialect::getRecentPeriodExpression($period, $date);
        $value = $this->conn->fetchOne(<<<SQL
            SELECT {$recentPeriodExpr}
            SQL);

        return is_string($value) ? $value : '';
    }

    /**
     * NOW() + 1 day, computed server-side -- Controller\
     * ProfileFormHandler::loadIntoTemplate()'s own default API-key
     * expiration date. SQL-modernization audit: verified, no interpolation
     * of any kind -- a fixed literal expression, nothing to bind.
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

        // SQL-modernization audit: the day count is real caller-supplied
        // data (Controller\ProfileFormHandler's own $conf['api_key_duration']
        // list) -- was spliced directly into INTERVAL {$day} DAY; now bound.
        // The `` `{$day}` `` column alias stays interpolated: SQL has no
        // bound-placeholder syntax for identifier/alias position, and $day
        // is a real `int` (not attacker-controlled string content) by the
        // time it reaches string interpolation there.
        $qb = $this->conn->createQueryBuilder();
        foreach ($days as $i => $day) {
            $placeholder = 'day' . $i;
            $qb->addSelect('ADDDATE(NOW(), INTERVAL :' . $placeholder . ' DAY) as `' . $day . '`');
            $qb->setParameter($placeholder, $day, ParameterType::INTEGER);
        }

        $row = $qb->executeQuery()
            ->fetchAssociative();

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
