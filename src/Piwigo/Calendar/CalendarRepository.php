<?php

declare(strict_types=1);

namespace Piwigo\Calendar;

use Piwigo\Db\AbstractRepository;

/**
 * Persistence layer for `CalendarRenderer::render()`'s own final "list of
 * matching image ids" query, and (Legacy Coupling Retirement: FrankenPHP/
 * workers exception retirement) for CalendarBase/CalendarMonthly/
 * CalendarWeekly's own remaining query-execution sites -- the
 * MysqliDb-only design predated the FrankenPHP/workers conversion plan;
 * under a persistent worker process there's no longer a per-request
 * container-cost reason to keep this rendering hierarchy off DBAL.
 */
final class CalendarRepository extends AbstractRepository
{
    /**
     * $fromWhereSql (CalendarService::buildInnerSql()), $dateWhereSql (the
     * pre-existing CalendarBase::get_date_where() -- despite the name, a
     * WHERE-clause *continuation* fragment, e.g. `AND (date_available
     * BETWEEN ...)`, not an ORDER BY) and $orderBySql are raw,
     * already-built SQL fragments, dynamically assembled from a variable
     * number of conditions -- same "hand-written parameterized SQL on
     * complex dynamic queries" allowance as SearchRepository, not a
     * bound-parameter mismatch: none of the three ever embed free-text
     * user input, only internally-trusted ids/dates.
     *
     * `GROUP BY id`, not `SELECT DISTINCT id` -- the original's own
     * `SELECT DISTINCT id ... ORDER BY <config-driven column not in the
     * SELECT list>` shape is invalid under ONLY_FULL_GROUP_BY (`DISTINCT`
     * has no functional-dependency exception for its ORDER BY columns,
     * unlike `GROUP BY`, which MySQL allows here since `id` is `images`'s
     * own primary key and $orderBySql's columns come from that same
     * table). DbConnection deliberately never strips ONLY_FULL_GROUP_BY
     * the way the legacy dblayer does (see its own docblock), so this
     * query has to be valid under it from the start, unlike the pre-DBAL
     * version.
     *
     * @return list<int>
     */
    public function findImageIds(string $fromWhereSql, string $dateWhereSql, string $orderBySql): array
    {
        $ids = $this->conn->executeQuery(
            'SELECT id ' . $fromWhereSql . ' ' . $dateWhereSql . ' GROUP BY id ' . $orderBySql
        )->fetchFirstColumn();

        return array_values(array_map(intval(...), array_filter($ids, is_numeric(...))));
    }

    /**
     * Executes an already-built, multi-row SELECT query -- built by
     * CalendarBase::build_nav_bar()/build_next_prev() or
     * CalendarMonthly's build_*_calendar() methods from
     * calendar_levels/inner_sql/get_date_where() fragments, same
     * "hand-written SQL on complex dynamic queries" allowance as
     * findImageIds() above -- and returns the raw result rows. Column
     * extraction/reduction (e.g. period => nb_images) stays in the
     * calendar classes themselves, matching the shape their own existing
     * code already expects.
     *
     * @return list<array<string, mixed>>
     */
    public function findRows(string $query): array
    {
        return $this->conn->executeQuery($query)
            ->fetchAllAssociative();
    }

    /**
     * Same as findRows(), for a query expected to match at most one row
     * (CalendarMonthly::build_month_calendar()'s per-day random-image
     * lookup).
     *
     * @return array<string, mixed>|null
     */
    public function findRow(string $query): ?array
    {
        $row = $this->conn->executeQuery($query)
            ->fetchAssociative();
        return $row === false ? null : $row;
    }
}
