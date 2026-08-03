<?php

declare(strict_types=1);

namespace Piwigo\Calendar;

use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\ORM\QueryBuilder;
use Piwigo\Db\Tables;
use Piwigo\Image\ImageCategoryEntity;
use Piwigo\Image\ImageEntity;
use Piwigo\Permission\SqlCondition;

/**
 * Persistence layer for `CalendarRenderer::render()`'s own final "list of
 * matching image ids" query, and for CalendarBase/CalendarMonthly/
 * CalendarWeekly's own remaining query-execution sites.
 *
 * Further SQL-modernization audit, Item 15G: every method here is now
 * real DQL except {@see findImageIds()}, which stays on raw DBAL --
 * its own `$orderBySql` traces to `CurrentConfig::orderBy()`/
 * `orderByCustom()`, genuinely open-ended admin-typed raw SQL (a real
 * Item-16-scoped blocker, see that method's own docblock). Dropped
 * `extends AbstractRepository` (this repository queries `Image\
 * ImageEntity`/`ImageCategoryEntity`, not an entity of its own -- same
 * shape as {@see \Piwigo\Notification\NotificationRepository}) in favor
 * of a directly-injected `EntityManagerInterface`.
 */
final class CalendarRepository
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    /**
     * $fromWhereSql ({@see CalendarService::buildInnerSql()}'s own
     * `CalendarQueryScope::$rawSqlFromWhere}) and $dateWhereSql (the
     * pre-existing `CalendarBase::get_date_where()` -- despite the name,
     * a WHERE-clause *continuation* fragment, e.g. `AND
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
     * Further SQL-modernization audit, Item 15G: stays on raw DBAL --
     * $orderBySql traces back to `CurrentConfig::orderBy()`/
     * `orderByCustom()` ({@see \Piwigo\Calendar\CalendarRenderer::render()}'s
     * own call site), a plain admin-typed free-text `"ORDER BY ..."` SQL
     * string (`orderByCustom()` lets an admin override it with arbitrary
     * raw SQL via the admin UI) -- not a bounded token set like every
     * other "genuinely open-ended ORDER BY" exclusion accepted throughout
     * this DQL-modernization campaign. DQL requires every identifier to
     * be an alias-qualified property path; there is no safe, general way
     * to transform arbitrary admin-typed raw SQL column names into DQL
     * property paths by string manipulation. Redesigning
     * `CurrentConfig::orderBy()`'s own contract into something
     * DQL-compatible is a real, separate, cross-cutting redesign --
     * Item 16's territory, not this one's.
     *
     * @return list<int>
     */
    public function findImageIds(SqlCondition $fromWhere, SqlCondition $dateWhere, string $orderBySql): array
    {
        $ids = $this->em->getConnection()
            ->executeQuery(
                'SELECT id ' . $fromWhere->sql . ' ' . $dateWhere->sql . ' GROUP BY id ' . $orderBySql,
                [...$fromWhere->parameters, ...$dateWhere->parameters],
                [...$fromWhere->types, ...$dateWhere->types]
            )->fetchFirstColumn();

        return array_values(array_map(intval(...), array_filter($ids, is_numeric(...))));
    }

    /**
     * Applies a permission/scope/date `SqlCondition` via `andWhere()`,
     * binding every one of its parameters -- same shared-helper shape as
     * every other `applyCondition()` in this DQL-modernization campaign.
     * DQL-only (unlike most of its siblings elsewhere in this campaign,
     * which stay a `QueryBuilder|Doctrine\DBAL\Query\QueryBuilder` union)
     * -- every consumer in this file is real DQL; {@see findImageIds()}
     * above is the only method still on raw DBAL, and it doesn't go
     * through this helper at all (a plain string-concatenated query).
     */
    private static function applyCondition(QueryBuilder $qb, SqlCondition $condition): void
    {
        if ($condition->isEmpty()) {
            return;
        }

        $qb->andWhere($condition->sql);
        foreach ($condition->parameters as $name => $value) {
            $qb->setParameter($name, $value, $condition->types[$name] ?? ParameterType::STRING);
        }
    }

    /**
     * Builds the shared `FROM ImageEntity i [INNER JOIN ImageCategoryEntity
     * ic ...] WHERE $scope->dqlWhere` base every DQL method below starts
     * from.
     */
    private function baseQueryBuilder(CalendarQueryScope $scope): QueryBuilder
    {
        $qb = $this->em->createQueryBuilder()
            ->from(ImageEntity::class, 'i');

        if ($scope->joinImageCategory) {
            $qb->innerJoin(ImageCategoryEntity::class, 'ic', Join::WITH, 'ic.imageId = i.id');
        }

        self::applyCondition($qb, $scope->dqlWhere);

        return $qb;
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
     * $levelDql value within the current inner/date-range filter.
     *
     * Further SQL-modernization audit, Item 15G: converted to real DQL.
     * DQL's own `GroupByItem` grammar only accepts a path expression or a
     * `ResultVariable` (a SELECT-list alias), never an arbitrary function
     * call -- confirmed against `vendor/doctrine/orm`'s own
     * `Parser::GroupByItem()` -- so `GROUP BY period` (the alias) is both
     * the only legal DQL shape and, per `SqlWalker::walkResultVariable()`,
     * compiles to the exact same `GROUP BY period` SQL text the original
     * raw query already used; no `DISTINCT(...)`-wrapping ambiguity
     * either (DQL's `->distinct()` is a SELECT-clause-level flag, same as
     * raw SQL's own `SELECT DISTINCT` -- functionally a no-op layered
     * onto an already-fully-deduplicating `GROUP BY`, carried forward
     * unchanged rather than "fixed" to avoid a behavior-preservation
     * risk).
     *
     * @return list<array<string, mixed>>
     */
    public function countGroupedByLevel(string $levelDql, CalendarQueryScope $scope, SqlCondition $dateWhere): array
    {
        $qb = $this->baseQueryBuilder($scope)
            ->select($levelDql . ' AS period', 'COUNT(DISTINCT i.id) AS nb_images')
            ->distinct()
            ->groupBy('period');
        self::applyCondition($qb, $dateWhere);

        $result = [];
        foreach ($qb->getQuery()->getArrayResult() as $row) {
            if (! is_array($row)) {
                continue;
            }

            $result[] = [
                'period' => $row['period'] ?? null,
                'nb_images' => $row['nb_images'] ?? null,
            ];
        }

        return $result;
    }

    /**
     * CalendarBase::build_next_prev()'s own query -- every distinct
     * concatenated period string within the current inner filter, dated
     * rows only. Unlike the other methods here, returns the fully
     * extracted/filtered period list directly (not raw rows): the
     * extraction is a lossless, unambiguous single-column unwrap (no
     * casting-behavior risk the way the count methods' `nb_images`/
     * `count` columns have), and $dateFieldDql is needed here regardless
     * to build the query's own "IS NOT NULL" clause.
     *
     * Further SQL-modernization audit, Item 15G: converted to real DQL --
     * $levelDqlExpressions (one DQL expression per chronology_date level,
     * e.g. `['YEAR(i.dateAvailable)', 'WEEK(i.dateAvailable)+1']`) used to
     * be pre-joined by the caller into one `CONCAT(...)` DQL expression
     * string, but DQL's `CONCAT()` grammar only accepts `StringPrimary`
     * arguments -- a bare function call or literal, never a trailing
     * arithmetic suffix like `WEEK(...)+1` (confirmed empirically: the
     * DQL parser rejects it, `StringPrimary()`'s own grammar has no
     * arithmetic-expression branch, unlike a plain `SelectExpression`
     * context which does). Solved by selecting each level expression as
     * its own aliased column (`part0`, `part1`, ...) instead -- a bare
     * `SelectExpression` position, which does allow `FUNC(...)+1` (per
     * `Parser::SelectExpression()`'s own `isMathOperator()` lookahead
     * branch) -- grouping by each alias, and concatenating the fetched
     * values together in PHP, semantically identical to what
     * `CONCAT_WS('-', ...)` did at the SQL level.
     *
     * @param list<string> $levelDqlExpressions
     * @return list<string>
     */
    public function findAdjacentPeriods(array $levelDqlExpressions, CalendarQueryScope $scope, string $dateFieldDql): array
    {
        if ($levelDqlExpressions === []) {
            return [];
        }

        $partAliases = [];
        $selects = [];
        foreach ($levelDqlExpressions as $i => $expr) {
            $alias = 'part' . $i;
            $partAliases[] = $alias;
            $selects[] = $expr . ' AS ' . $alias;
        }

        $qb = $this->baseQueryBuilder($scope)
            ->select(...$selects)
            ->andWhere($dateFieldDql . ' IS NOT NULL');
        foreach ($partAliases as $alias) {
            $qb->addGroupBy($alias);
        }

        $periods = [];
        foreach ($qb->getQuery()->getArrayResult() as $row) {
            if (! is_array($row)) {
                continue;
            }

            $parts = array_map(
                static fn (string $alias): string => is_scalar($row[$alias] ?? null) ? (string) $row[$alias] : '',
                $partAliases
            );
            $periods[] = implode('-', $parts);
        }

        return $periods;
    }

    /**
     * CalendarMonthly::build_global_calendar()'s own query: image count
     * per year+month within the current inner/date-range filter.
     *
     * Further SQL-modernization audit, Item 15G: converted to real DQL.
     * The original raw-SQL query's own `GROUP BY period, {$yearExpr},
     * {$monthExpr}` (restating the literal YEAR()/MONTH() expressions
     * for MySQL's ONLY_FULL_GROUP_BY, since it can't infer they're
     * functionally dependent on the DATE_FORMAT()-derived `period` alias)
     * has no direct DQL translation -- DQL's `GroupByItem` grammar
     * rejects an arbitrary function-call expression outright, only a
     * path expression or a `ResultVariable` (SELECT-list alias) is legal
     * (confirmed against `vendor/doctrine/orm`'s own grammar). Solved by
     * giving `YEAR(...)`/`MONTH(...)` their own SELECT-list aliases
     * (`yr`/`mo`) so `GROUP BY period, yr, mo` and `ORDER BY yr DESC, mo
     * ASC` are both legal alias references -- the 2 extra columns this
     * adds to each hydrated row are dropped below, never returned.
     *
     * @return list<array<string, mixed>>
     */
    public function countByYearMonth(string $dateFieldDql, CalendarQueryScope $scope, SqlCondition $dateWhere): array
    {
        $qb = $this->baseQueryBuilder($scope)
            ->select(
                "DATE_FORMAT_YEAR_MONTH({$dateFieldDql}) AS period",
                "YEAR({$dateFieldDql}) AS yr",
                "MONTH({$dateFieldDql}) AS mo",
                'COUNT(DISTINCT i.id) AS count'
            )
            ->groupBy('period')
            ->addGroupBy('yr')
            ->addGroupBy('mo')
            ->orderBy('yr', 'DESC')
            ->addOrderBy('mo', 'ASC');
        self::applyCondition($qb, $dateWhere);

        $result = [];
        foreach ($qb->getQuery()->getArrayResult() as $row) {
            if (! is_array($row)) {
                continue;
            }

            $result[] = [
                'period' => $row['period'] ?? null,
                'count' => $row['count'] ?? null,
            ];
        }

        return $result;
    }

    /**
     * CalendarMonthly::build_year_calendar()'s own query: image count per
     * month+day within the current inner/date-range filter.
     *
     * Further SQL-modernization audit, Item 15G: converted to real DQL --
     * `period` alone is both the group key and sort key here (unlike
     * countByYearMonth() above), so no extra GROUP-BY-satisfying aliases
     * are needed.
     *
     * @return list<array<string, mixed>>
     */
    public function countByMonthDay(string $dateFieldDql, CalendarQueryScope $scope, SqlCondition $dateWhere): array
    {
        $qb = $this->baseQueryBuilder($scope)
            ->select("DATE_FORMAT_MONTH_DAY({$dateFieldDql}) AS period", 'COUNT(DISTINCT i.id) AS count')
            ->groupBy('period')
            ->orderBy('period', 'ASC');
        self::applyCondition($qb, $dateWhere);

        $result = [];
        foreach ($qb->getQuery()->getArrayResult() as $row) {
            if (! is_array($row)) {
                continue;
            }

            $result[] = [
                'period' => $row['period'] ?? null,
                'count' => $row['count'] ?? null,
            ];
        }

        return $result;
    }

    /**
     * CalendarMonthly::build_month_calendar()'s own day-count query:
     * image count per day-of-month within the current inner/date-range
     * filter (already scoped to a single year+month by $scope/$dateWhere).
     *
     * Further SQL-modernization audit, Item 15G: converted to real DQL,
     * same "period alone is group+sort key" shape as countByMonthDay()
     * above.
     *
     * @return list<array<string, mixed>>
     */
    public function countByDayOfMonth(string $dateFieldDql, CalendarQueryScope $scope, SqlCondition $dateWhere): array
    {
        $qb = $this->baseQueryBuilder($scope)
            ->select("DAYOFMONTH({$dateFieldDql}) AS period", 'COUNT(DISTINCT i.id) AS count')
            ->groupBy('period')
            ->orderBy('period', 'ASC');
        self::applyCondition($qb, $dateWhere);

        $result = [];
        foreach ($qb->getQuery()->getArrayResult() as $row) {
            if (! is_array($row)) {
                continue;
            }

            $result[] = [
                'period' => $row['period'] ?? null,
                'count' => $row['count'] ?? null,
            ];
        }

        return $result;
    }

    /**
     * CalendarMonthly::build_month_calendar()'s own per-day query: one
     * random image (for the thumbnail preview) among the images available
     * on the given day, dated rows only ($dateFieldDql IS NOT NULL is
     * implicit in $dateWhere/$scope already scoping to a single day).
     *
     * Further SQL-modernization audit, Item 15G: the *selection* (which
     * image id, and its `dow` value, respecting $scope/$dateWhere, in
     * random order) now runs as its own DQL query -- every table/
     * condition it touches is mapped. The final row -- `id, file,
     * representative_ext, path, width, height, rotation` -- stays raw
     * DBAL by id: it feeds `new SrcImage($row)` directly
     * ({@see \Piwigo\Calendar\CalendarMonthly::build_month_calendar()}),
     * and `SrcImage`'s constructor reads those exact raw snake_case keys
     * (confirmed by reading it), the same public/internal-contract reason
     * {@see \Piwigo\Image\ImageRepository::findRowWithCondition()} stays
     * excluded -- reproducing that shape via DQL's camelCase entity
     * hydration would mean hand-mapping every one of those columns for no
     * safety/correctness gain (the id itself is already fully bound
     * either way).
     *
     * @return array<string, mixed>|null
     */
    public function findRandomImageForDay(string $dateFieldDql, CalendarQueryScope $scope, SqlCondition $dateWhere): ?array
    {
        $qb = $this->baseQueryBuilder($scope)
            ->select('i.id AS id', "(DAYOFWEEK({$dateFieldDql}) - 1) AS dow")
            ->orderBy('RAND()')
            ->setMaxResults(1);
        self::applyCondition($qb, $dateWhere);

        $picked = $qb->getQuery()
            ->getOneOrNullResult(Query::HYDRATE_ARRAY);

        if (! is_array($picked)) {
            return null;
        }

        $imageId = is_numeric($picked['id'] ?? null) ? (int) $picked['id'] : 0;

        $imagesTable = Tables::images();
        $row = $this->em->getConnection()
            ->executeQuery(
                <<<SQL
                SELECT id, file, representative_ext, path, width, height, rotation
                FROM {$imagesTable}
                WHERE id = :id
                SQL
                ,
                [
                    'id' => $imageId,
                ],
                [
                    'id' => ParameterType::INTEGER,
                ]
            )->fetchAssociative();

        if ($row === false) {
            return null;
        }

        $row['dow'] = $picked['dow'] ?? null;

        return $row;
    }
}
