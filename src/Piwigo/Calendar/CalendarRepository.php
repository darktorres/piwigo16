<?php

declare(strict_types=1);

namespace Piwigo\Calendar;

use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\ORM\QueryBuilder;
use Piwigo\Common\ValueObject\ImageId;
use Piwigo\Db\SqlDialect;
use Piwigo\Image\ImageCategoryEntity;
use Piwigo\Image\ImageEntity;
use Piwigo\Image\PhotoSortField;
use Piwigo\Permission\SqlCondition;

/**
 * Persistence layer for `CalendarRenderer::render()`'s own final "list of
 * matching image ids" query, and for CalendarBase/CalendarMonthly/
 * CalendarWeekly's own remaining query-execution sites.
 *
 * Further SQL-modernization audit, Item 15G: every method here is real
 * DQL except {@see findImageIds()}, which only conditionally is --
 * see its own docblock (Item 16J). Dropped `extends AbstractRepository`
 * (this repository queries `Image\ImageEntity`/`ImageCategoryEntity`, not
 * an entity of its own -- same shape as {@see \Piwigo\Notification\
 * NotificationRepository}) in favor of a directly-injected
 * `EntityManagerInterface`.
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
     * Item 16J: the raw-DBAL blocker above is now conditional -- corrected
     * too, `orderByCustom()` was never actually admin-UI-reachable (see
     * {@see \Piwigo\Image\PhotoSortField}'s own docblock). $orderBySql
     * traces to `CurrentConfig::orderBy()`, but {@see \Piwigo\Calendar\
     * CalendarRenderer::render()}'s own call site dynamically prepends
     * `$calendar->date_field` (always `date_available`/`date_creation`)
     * ahead of it -- {@see \Piwigo\Image\PhotoSortField::
     * resolveDqlOrderBy()} doesn't care about that, it just parses
     * whatever string it's handed. $dqlScope/$dqlDateWhere are the
     * DQL-shaped counterparts of $fromWhere/$dateWhere (already computed
     * by the caller for every *other* real method in this file via
     * {@see CalendarQueryScope::$dqlWhere}/`CalendarBase::
     * get_date_where($max_levels, forDql: true)`) -- null from either
     * (this method's only other real caller,
     * `tests/Integration/CalendarRepositoryTest.php`, never passes them)
     * means "don't even attempt DQL," same as an unparseable $orderBySql.
     *
     * @return list<int>
     */
    public function findImageIds(
        SqlCondition $fromWhere,
        SqlCondition $dateWhere,
        string $orderBySql,
        ?CalendarQueryScope $dqlScope = null,
        ?SqlCondition $dqlDateWhere = null
    ): array {
        if ($dqlScope !== null && $dqlDateWhere !== null) {
            // Never offered an `ic` alias for Rank: whether image_category
            // is even joined is conditional ($scope->joinImageCategory),
            // and a calendar view has no single category context the way
            // CategoryRepository::findImageIdsForCategories() does, so
            // there's no query-independent way to express it here.
            $dqlOrderBy = PhotoSortField::resolveDqlOrderBy($orderBySql, 'i');
            if ($dqlOrderBy !== null) {
                $qb = $this->baseQueryBuilder($dqlScope)
                    ->select('i.id')
                    ->groupBy('i.id');
                self::applyCondition($qb, $dqlDateWhere);
                foreach ($dqlOrderBy as $entry) {
                    $qb->addOrderBy($entry['property'], $entry['dir']);
                }

                $ids = $qb->getQuery()
                    ->getSingleColumnResult();

                return array_values(array_map(
                    static fn (mixed $id): int => (int) $id,
                    array_filter($ids, is_numeric(...))
                ));
            }
        }

        // pgsql support pass: real bug found live -- $orderBySql traces
        // back to CurrentConfig::orderBy(), admin-settable raw SQL text
        // that can legitimately be `ORDER BY RAND()`. Same real gap
        // already fixed for CategoryRepository's own raw-DBAL fallback
        // ("function rand() does not exist" against a real Postgres
        // server otherwise).
        $orderBySql = str_ireplace('RAND()', SqlDialect::randomFunction() . '()', $orderBySql);

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
     * binding every one of its parameters. DQL-only: every consumer in
     * this file uses real DQL, including {@see findImageIds()} above
     * whenever its own conditional DQL attempt succeeds -- its raw-DBAL
     * fallback path is a plain string-concatenated query and doesn't go
     * through this helper.
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
        // pgsql support pass: real bug found live -- no ORDER BY, so row
        // order was never guaranteed; MySQL and PostgreSQL disagreed on
        // it (nav bar items appearing out of chronological order).
        // $levelDql is always one of YEAR()/WEEK()/WEEKDAY()'s own
        // numeric DQL functions, so a plain numeric ASC sort is always
        // correct here, matching countByMonthDay()/countByDayOfMonth()'s
        // own established "period alone is group+sort key" precedent.
        $qb = $this->baseQueryBuilder($scope)
            ->select($levelDql . ' AS period', 'COUNT(DISTINCT i.id) AS nb_images')
            ->distinct()
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
     * random order) runs as its own DQL query -- every table/condition it
     * touches is mapped.
     *
     * Item 16H: the final by-id row also converted, via explicit `AS`
     * aliases matching `SrcImage`'s own raw snake_case constructor
     * contract exactly (`representative_ext`, confirmed by reading it) --
     * a mapping shim at this call site, not a `SrcImage` redesign. All 7
     * selected columns (id/file/representative_ext/path/width/height/
     * rotation) are plain scalar-typed on `ImageEntity`, no custom
     * Doctrine Type to unwrap.
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

        // `i.id` (ImageEntity's own ImageId-typed column) array-hydrates
        // as an ImageId instance, not a scalar.
        $pickedId = $picked['id'] ?? null;
        if (! $pickedId instanceof ImageId) {
            return null;
        }

        $row = $this->em->createQueryBuilder()
            ->select('i.id', 'i.file', 'i.representativeExt AS representative_ext', 'i.path', 'i.width', 'i.height', 'i.rotation')
            ->from(ImageEntity::class, 'i')
            ->where('i.id = :id')
            ->setParameter('id', $pickedId)
            ->getQuery()
            ->getOneOrNullResult(Query::HYDRATE_ARRAY);

        if (! is_array($row)) {
            return null;
        }

        $rowId = $row['id'] ?? null;
        $rowPath = $row['path'] ?? null;

        return [
            'id' => $rowId instanceof ImageId ? $rowId->value : null,
            'file' => $row['file'] ?? null,
            'representative_ext' => $row['representative_ext'] ?? null,
            'path' => is_string($rowPath) ? $rowPath : null,
            'width' => $row['width'] ?? null,
            'height' => $row['height'] ?? null,
            'rotation' => $row['rotation'] ?? null,
            'dow' => $picked['dow'] ?? null,
        ];
    }
}
