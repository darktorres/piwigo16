<?php

declare(strict_types=1);

namespace Piwigo\Calendar;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
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
     * $fromWhereSql (CalendarService::buildInnerSql()) and $dateWhereSql
     * (the pre-existing CalendarBase::get_date_where() -- despite the
     * name, a WHERE-clause *continuation* fragment, e.g. `AND
     * (date_available BETWEEN ...)`, not an ORDER BY) are already-built
     * SqlCondition fragments; $orderBySql is a raw, already-built trusted
     * fragment (config-driven column/direction, never a real value), same
     * "caller composes trusted fragments" contract used throughout this
     * initiative -- unlike the other two, it carries no bindable value.
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
    public function findImageIds(\Piwigo\Permission\SqlCondition $fromWhere, \Piwigo\Permission\SqlCondition $dateWhere, string $orderBySql): array
    {
        $ids = $this->conn->executeQuery(
            'SELECT id ' . $fromWhere->sql . ' ' . $dateWhere->sql . ' GROUP BY id ' . $orderBySql,
            [...$fromWhere->parameters, ...$dateWhere->parameters],
            [...$fromWhere->types, ...$dateWhere->types]
        )->fetchFirstColumn();

        return array_values(array_map(intval(...), array_filter($ids, is_numeric(...))));
    }

    /**
     * Executes an already-built, multi-row SELECT query -- built by
     * CalendarBase::build_nav_bar()/build_next_prev() or
     * CalendarMonthly's build_*_calendar() methods from
     * calendar_levels/inner_sql/get_date_where() fragments, same
     * "caller composes trusted query text, binds its own real values"
     * shape as findImageIds() above -- and returns the raw result rows.
     * Column extraction/reduction (e.g. period => nb_images) stays in the
     * calendar classes themselves, matching the shape their own existing
     * code already expects.
     *
     * @param array<string, mixed> $params
     * @param array<string, ArrayParameterType|ParameterType> $types
     * @return list<array<string, mixed>>
     */
    public function findRows(string $query, array $params = [], array $types = []): array
    {
        return $this->conn->executeQuery($query, $params, $types)
            ->fetchAllAssociative();
    }

    /**
     * Same as findRows(), for a query expected to match at most one row
     * (CalendarMonthly::build_month_calendar()'s per-day random-image
     * lookup).
     *
     * @param array<string, mixed> $params
     * @param array<string, ArrayParameterType|ParameterType> $types
     * @return array<string, mixed>|null
     */
    public function findRow(string $query, array $params = [], array $types = []): ?array
    {
        $row = $this->conn->executeQuery($query, $params, $types)
            ->fetchAssociative();
        return $row === false ? null : $row;
    }
}
