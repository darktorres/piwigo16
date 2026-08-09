<?php

declare(strict_types=1);

namespace Piwigo\Db;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;

/**
 * Real DB round-trips for a `SqlDialect`-built date expression --
 * {@see SqlDialect} itself is a deliberate pure string-builder with no
 * connection dependency (its own class docblock: "every one of these is a
 * pure string builder... no connection dependency"), so the handful of
 * real callers that need an actual computed value back (not just the SQL
 * fragment to splice into their own larger query) go through this class
 * instead, rather than each hand-rolling `DbConnection::build()->fetchOne('SELECT
 * ' . ...)` inline.
 *
 * `fetchTomorrow()`/`fetchFutureDatesFor()` branch on
 * `$this->conn->getDatabasePlatform()` directly (a real Connection is
 * already available here, unlike `SqlDialect`'s own static methods) --
 * `ADDDATE(NOW(), INTERVAL n DAY)` has no Postgres equivalent, `NOW() +
 * make_interval(days => n)` is the real one (`NOW()` itself is already
 * portable, needs no branch of its own).
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
     * $period is `int`, matching SqlDialect::
     * getRecentPeriodExpression()'s own signature -- this method's only
     * real caller already passes a genuine `int`.
     */
    public function fetchRecentCutoffDate(int $period, string $date = 'CURRENT_DATE'): string
    {
        // $recentPeriodExpr's own $date-quoting defect lives inside
        // SqlDialect::getRecentPeriodExpression() itself, not here -- see
        // that method's own docblock. This method's only real caller
        // never passes $date, so the quoting branch never triggers here
        // in practice; nothing for this heredoc itself to fix.
        $recentPeriodExpr = SqlDialect::getRecentPeriodExpression($period, $date);
        $value = $this->conn->fetchOne(<<<SQL
            SELECT {$recentPeriodExpr}
            SQL);

        return is_string($value) ? $value : '';
    }

    /**
     * NOW() + 1 day, computed server-side -- Controller\
     * ProfileFormHandler::loadIntoTemplate()'s own default API-key
     * expiration date. No interpolation of any kind -- a fixed literal
     * expression, nothing to bind.
     */
    public function fetchTomorrow(): string
    {
        $expr = $this->conn->getDatabasePlatform() instanceof PostgreSQLPlatform
            ? 'NOW() + make_interval(days => 1)'
            : 'ADDDATE(NOW(), INTERVAL 1 DAY)';

        // staabm/phpstan-dba only validates against one live connection,
        // so the branch for the other engine always fails here -- both are
        // correct for their own platform.
        // @phpstan-ignore dba.syntaxError
        $value = $this->conn->fetchOne('SELECT ' . $expr);

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

        // The day count is real caller-supplied data (Controller\
        // ProfileFormHandler's own $conf['api_key_duration'] list), bound
        // rather than spliced into INTERVAL {$day} DAY.
        // The column alias stays interpolated: SQL has no bound-placeholder
        // syntax for identifier/alias position, and $day is a real `int`
        // (not attacker-controlled string content) by the time it reaches
        // string interpolation there. quoteSingleIdentifier() picks the
        // real per-platform quoting (backticks/double-quotes) rather than
        // the previous hand-rolled, MySQL-only backtick literal -- needed
        // here specifically since a bare numeric alias is otherwise invalid
        // identifier syntax on either platform.
        $platform = $this->conn->getDatabasePlatform();
        $expr = $platform instanceof PostgreSQLPlatform
            ? 'NOW() + make_interval(days => :%s)'
            : 'ADDDATE(NOW(), INTERVAL :%s DAY)';

        $qb = $this->conn->createQueryBuilder();
        foreach ($days as $i => $day) {
            $placeholder = 'day' . $i;
            $qb->addSelect(sprintf($expr, $placeholder) . ' as ' . $platform->quoteSingleIdentifier((string) $day));
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
