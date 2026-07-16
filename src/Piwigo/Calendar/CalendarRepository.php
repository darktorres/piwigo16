<?php

declare(strict_types=1);

namespace Piwigo\Calendar;

use Piwigo\Db\AbstractRepository;

/**
 * Persistence layer for `CalendarRenderer::render()`'s own single
 * data-access point -- the pre-existing
 * CalendarBase/CalendarMonthly/CalendarWeekly rendering classes
 * (already-built, P6-era) keep using the legacy query2array()/pwg_query()
 * layer internally; only this call site's own final "list of matching
 * image ids" query is ported to DBAL here.
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
}
