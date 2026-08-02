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
     * Further SQL-modernization audit, Item 6: findRows()/findRow() (a
     * fully generic "execute an already-built query" pair) replaced with
     * one typed method per real query shape, built internally from
     * typed SqlCondition/expression-string pieces instead of a
     * pre-assembled query the calendar classes used to concatenate
     * themselves. Column extraction/reduction (e.g. period => nb_images)
     * deliberately stays in the calendar classes, unchanged -- some of
     * it has subtle, real casting differences between call sites (e.g.
     * build_month_calendar()'s day-count loop never casts `count` to
     * int, unlike its build_global_calendar()/build_year_calendar()
     * siblings), not worth risking a behavior change over for this item.
     *
     * CalendarBase::build_nav_bar()'s own query: one row per distinct
     * $levelSql value within the current inner/date-range filter.
     *
     * @return list<array<string, mixed>>
     */
    public function countGroupedByLevel(string $levelSql, \Piwigo\Permission\SqlCondition $innerSql, \Piwigo\Permission\SqlCondition $dateWhere): array
    {
        return $this->conn->executeQuery(
            <<<SQL
            SELECT DISTINCT({$levelSql}) as period,
              COUNT(DISTINCT id) as nb_images{$innerSql->sql}{$dateWhere->sql}
              GROUP BY period
            SQL
            ,
            [...$innerSql->parameters, ...$dateWhere->parameters],
            [...$innerSql->types, ...$dateWhere->types]
        )->fetchAllAssociative();
    }

    /**
     * CalendarBase::build_next_prev()'s own query -- every distinct
     * concatenated period string within the current inner filter, dated
     * rows only. Unlike the other methods here, returns the fully
     * extracted/filtered period list directly (not raw rows): the
     * extraction is a lossless, unambiguous single-column unwrap (no
     * casting-behavior risk the way the count methods' `nb_images`/
     * `count` columns have), and $dateField is needed here regardless to
     * build the query's own "IS NOT NULL" clause.
     *
     * @return list<string>
     */
    public function findAdjacentPeriods(string $concatWsExpr, \Piwigo\Permission\SqlCondition $innerSql, string $dateField): array
    {
        $rows = $this->conn->executeQuery(
            <<<SQL
            SELECT {$concatWsExpr} AS period{$innerSql->sql}
              AND {$dateField} IS NOT NULL
              GROUP BY period
            SQL
            ,
            $innerSql->parameters,
            $innerSql->types
        )->fetchAllAssociative();

        $periods = array_map(static fn (array $row): mixed => $row['period'] ?? null, $rows);

        return array_values(array_filter($periods, is_string(...)));
    }

    /**
     * CalendarMonthly::build_global_calendar()'s own query: image count
     * per year+month within the current inner/date-range filter. GROUP BY
     * also lists the exact YEAR()/MONTH() expressions ORDER BY uses (not
     * just the DATE_FORMAT()-derived `period` alias) -- under standard SQL
     * mode (ONLY_FULL_GROUP_BY, never stripped by this project's
     * DbConnection), MySQL doesn't infer that YEAR(x)/MONTH(x) are
     * functionally dependent on DATE_FORMAT(x, '%Y%m') just because both
     * derive from the same column; it only recognizes an ORDER BY
     * expression as valid when it's a literal GROUP BY member. Grouping by
     * all three doesn't change the partitioning (period and year+month
     * already identify the same buckets), only satisfies the SQL-mode
     * requirement.
     *
     * @return list<array<string, mixed>>
     */
    public function countByYearMonth(string $dateField, \Piwigo\Permission\SqlCondition $innerSql, \Piwigo\Permission\SqlCondition $dateWhere): array
    {
        $dateYYYYMM = \Piwigo\Db\SqlDialect::getDateYYYYMM($dateField);
        $yearExpr = \Piwigo\Db\SqlDialect::getYear($dateField);
        $monthExpr = \Piwigo\Db\SqlDialect::getMonth($dateField);

        return $this->conn->executeQuery(
            <<<SQL
            SELECT {$dateYYYYMM} as period,
                COUNT(distinct id) as count{$innerSql->sql}{$dateWhere->sql}
                GROUP BY period, {$yearExpr}, {$monthExpr}
                ORDER BY {$yearExpr} DESC, {$monthExpr} ASC
            SQL
            ,
            [...$innerSql->parameters, ...$dateWhere->parameters],
            [...$innerSql->types, ...$dateWhere->types]
        )->fetchAllAssociative();
    }

    /**
     * CalendarMonthly::build_year_calendar()'s own query: image count per
     * month+day within the current inner/date-range filter.
     *
     * @return list<array<string, mixed>>
     */
    public function countByMonthDay(string $dateField, \Piwigo\Permission\SqlCondition $innerSql, \Piwigo\Permission\SqlCondition $dateWhere): array
    {
        $dateMMDD = \Piwigo\Db\SqlDialect::getDateMMDD($dateField);

        return $this->conn->executeQuery(
            <<<SQL
            SELECT {$dateMMDD} as period,
                  COUNT(DISTINCT id) as count{$innerSql->sql}{$dateWhere->sql}
                  GROUP BY period
                  ORDER BY period ASC
            SQL
            ,
            [...$innerSql->parameters, ...$dateWhere->parameters],
            [...$innerSql->types, ...$dateWhere->types]
        )->fetchAllAssociative();
    }

    /**
     * CalendarMonthly::build_month_calendar()'s own day-count query:
     * image count per day-of-month within the current inner/date-range
     * filter (already scoped to a single year+month by $innerSql).
     *
     * @return list<array<string, mixed>>
     */
    public function countByDayOfMonth(string $dateField, \Piwigo\Permission\SqlCondition $innerSql, \Piwigo\Permission\SqlCondition $dateWhere): array
    {
        $dayOfMonth = \Piwigo\Db\SqlDialect::getDayOfMonth($dateField);

        return $this->conn->executeQuery(
            <<<SQL
            SELECT {$dayOfMonth} as period,
                  COUNT(DISTINCT id) as count{$innerSql->sql}{$dateWhere->sql}
                  GROUP BY period
                  ORDER BY period ASC
            SQL
            ,
            [...$innerSql->parameters, ...$dateWhere->parameters],
            [...$innerSql->types, ...$dateWhere->types]
        )->fetchAllAssociative();
    }

    /**
     * CalendarMonthly::build_month_calendar()'s own per-day query: one
     * random image (for the thumbnail preview) among the images available
     * on the given day, dated rows only ($dateField IS NOT NULL is
     * implicit in $dateWhere/$innerSql already scoping to a single day).
     *
     * @return array<string, mixed>|null
     */
    public function findRandomImageForDay(string $dateField, \Piwigo\Permission\SqlCondition $innerSql, \Piwigo\Permission\SqlCondition $dateWhere): ?array
    {
        $dayOfWeek = \Piwigo\Db\SqlDialect::getDayOfWeek($dateField);
        $randomFunction = \Piwigo\Db\SqlDialect::DB_RANDOM_FUNCTION;

        $row = $this->conn->executeQuery(
            <<<SQL
            SELECT id, file,representative_ext,path,width,height,rotation, {$dayOfWeek}-1 as dow{$innerSql->sql}{$dateWhere->sql}
              ORDER BY {$randomFunction}()
              LIMIT 1
            SQL
            ,
            [...$innerSql->parameters, ...$dateWhere->parameters],
            [...$innerSql->types, ...$dateWhere->types]
        )->fetchAssociative();

        return $row === false ? null : $row;
    }
}
