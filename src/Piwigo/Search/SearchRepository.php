<?php

declare(strict_types=1);

namespace Piwigo\Search;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Query\Expression\ExpressionBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\ORM\QueryBuilder;
use Piwigo\Category\CategoryEntity;
use Piwigo\Common\ValueObject\CategoryId;
use Piwigo\Common\ValueObject\ImageId;
use Piwigo\Image\ImageCategoryEntity;
use Piwigo\Image\ImageEntity;
use Piwigo\Permission\SqlCondition;
use Piwigo\Search\Projection\Search;

/**
 * Persistence layer for the search domain: the `search` table (saved
 * search rules) plus generic parameterized id-list/row lookups shared by
 * the many distinct WHERE-clause shapes `SearchService` builds (the
 * quick-search token evaluator's own genuinely dynamic table/column/
 * operator combinations -- see {@see findIdsByClause()}'s own docblock
 * for why that one stays generic).
 *
 * Takes a directly-injected `EntityManagerInterface` rather than
 * extending `AbstractRepository` (`Search` is `L2bExtendedDomain`;
 * `Image`/`Category` are `L2aCoreDomain`, an allowed downward
 * dependency, same shape as `Calendar\CalendarRepository`/
 * `Notification\NotificationRepository`). The `search` table's own
 * basic CRUD runs through DQL/`persist()` below.
 * `findIdsByClause()`/`findRowsByClause()`/`quote()`/`expressionBuilder()`
 * stay generic -- the quick-search token evaluator's own real,
 * permanent need for dynamically-varying table/column/operator
 * combinations (MySQL FULLTEXT search, a plugin extensibility hook
 * accepting raw SQL) has no DQL representation.
 * `SearchService::getRegularSearchResults()`'s 12 advanced-search
 * criteria and `searchAllwords()` go through {@see findImageIdsMatching()}.
 * `SearchFilterRenderer`'s own filter-sidebar blocks (author/added_by/
 * filetypes/ratings/filesize/ratios/height/width/date_posted/
 * date_created) go through {@see countImagesGroupedBy()}/
 * {@see findDistinctImageRows()}/{@see findDistinctImageColumnValues()}/
 * {@see findCategoryIdsAndUppercats()}.
 *
 * Every `mixed` below stays that way by design: $params mirrors DBAL
 * Connection::executeQuery()'s own untyped bound-parameter contract
 * (values vary by which dynamically-built WHERE clause a caller
 * assembled); findRowsByClause()'s row shape genuinely varies with
 * $fromSql; $rules matches Search Projection's own already-documented
 * JSON rules-bag rationale.
 */
final class SearchRepository
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    private static function filterRulesKeys(mixed $rulesRaw): ?array
    {
        if (! is_array($rulesRaw)) {
            return null;
        }

        // Doctrine's native `json` Type decodes via json_decode($v, true)
        // -- PHP auto-coerces a numeric-string JSON object key (e.g. "0")
        // into a PHP int array key, so this string-keys-only filter is
        // needed.
        return array_filter($rulesRaw, is_string(...), ARRAY_FILTER_USE_KEY);
    }

    private static function toProjection(SavedSearchEntity $entity): Search
    {
        return new Search(
            id: $entity->id ?? 0,
            searchUuid: $entity->searchUuid,
            createdOn: $entity->createdOn,
            createdBy: $entity->createdBy,
            forkedFrom: $entity->forkedFrom,
            rules: self::filterRulesKeys($entity->rules),
        );
    }

    /**
     * The 2 typed methods below correspond to `getSearchIdPattern()`'s
     * own 2 real WHERE shapes (`'id = ?'`/`'search_uuid = ?'`) -- no
     * other WHERE shape is ever constructed.
     */
    public function findSavedSearchById(int $id): ?Search
    {
        $entity = $this->em->find(SavedSearchEntity::class, $id);

        return $entity === null ? null : self::toProjection($entity);
    }

    public function findSavedSearchByUuid(string $uuid): ?Search
    {
        $entity = $this->em->getRepository(SavedSearchEntity::class)
            ->findOneBy([
                'searchUuid' => $uuid,
            ]);

        return $entity === null ? null : self::toProjection($entity);
    }

    public function countSavedSearchByUuid(string $uuid): int
    {
        $value = $this->em->createQueryBuilder()
            ->select('COUNT(s.id)')
            ->from(SavedSearchEntity::class, 's')
            ->where('s.searchUuid = :uuid')
            ->setParameter('uuid', $uuid)
            ->getQuery()
            ->getSingleScalarResult();

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * $createdOn/$searchUuid default to null for Ws\PwgCore::historySearch()'s
     * ephemeral, metadata-less inserts (no user-facing permalink, never
     * forked) -- SearchService::saveSearch() always passes real values for
     * both.
     *
     * Uses `persist()`/`flush()` -- DQL has no INSERT statement.
     * `saveSearch()`'s own `$createdOn` is `Env::now()`-computed in PHP,
     * so it stays PIWIGO_TEST_NOW-aware.
     *
     * @param array<string, mixed> $rules
     * @return int the new row's auto-increment id
     */
    public function insertSavedSearch(
        array $rules,
        ?string $createdOn = null,
        ?int $createdBy = null,
        ?string $searchUuid = null,
        ?int $forkedFrom = null
    ): int {
        $entity = new SavedSearchEntity($searchUuid, $createdOn, $createdBy, $forkedFrom, $rules);
        $this->em->persist($entity);
        $this->em->flush();

        return $entity->id ?? 0;
    }

    /**
     * Batch lookup of decoded `rules` for a list of search ids, used by
     * Ws\PwgCore::historySearch()'s history-listing enrichment (one query
     * for every `search_id` referenced across a page of history rows,
     * instead of unserialize()-ing a raw per-row string itself).
     *
     * @param list<int> $ids
     * @return array<int, array<string, mixed>|null> keyed by search id
     */
    public function findSavedSearchRulesByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $rows = $this->em->createQueryBuilder()
            ->select('s.id', 's.rules')
            ->from(SavedSearchEntity::class, 's')
            ->where('s.id IN (:ids)')
            ->setParameter('ids', $ids, ArrayParameterType::INTEGER)
            ->getQuery()
            ->getArrayResult();

        $result = [];
        foreach ($rows as $row) {
            if (! is_array($row) || ! is_numeric($row['id'] ?? null)) {
                continue;
            }

            $result[(int) $row['id']] = self::filterRulesKeys($row['rules'] ?? null);
        }

        return $result;
    }

    /**
     * `Query::getArrayResult()`'s own return type is too loose for PHPStan
     * to confirm each row is `array<string, mixed>` (a real, always-true
     * invariant for a scalar-only SELECT list, just not one Doctrine's own
     * generics express) -- re-keys each row's columns to string here, once,
     * for every generic row-returning method below.
     *
     * `i.id` (ImageEntity's own ImageId-typed column, selected raw by
     * findDistinctImageRows()) array-hydrates as an ImageId instance, not
     * a scalar -- unwrapped generically rather than naming the 'id' key
     * specifically, since a future extra $selectExprs entry could
     * plausibly select another ImageId-typed expression too.
     *
     * @param  array<mixed>  $rows
     * @return list<array<string, mixed>>
     */
    private static function castRows(array $rows): array
    {
        $result = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $unwrapped = array_map(
                static fn (mixed $value): mixed => $value instanceof ImageId ? $value->value : $value,
                $row
            );

            $result[] = array_combine(array_map(strval(...), array_keys($unwrapped)), array_values($unwrapped));
        }

        return $result;
    }

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
     * Shared "images matching this WHERE fragment" executor for every
     * `SearchService::getRegularSearchResults()` advanced-search criterion
     * and `searchAllwords()` -- all of them share the exact same `FROM
     * ImageEntity i INNER JOIN ImageCategoryEntity ic WITH ic.imageId =
     * i.id WHERE <criterion>` shape (the caller already AND-combines its
     * own criterion condition with `PermissionCriteria`'s forbidden/
     * visible conditions into $whereDql before calling this).
     *
     * @return list<int>
     */
    public function findImageIdsMatching(SqlCondition $whereDql): array
    {
        $qb = $this->em->createQueryBuilder()
            ->select('DISTINCT i.id')
            ->from(ImageEntity::class, 'i')
            ->innerJoin(ImageCategoryEntity::class, 'ic', Join::WITH, 'ic.imageId = i.id');
        self::applyCondition($qb, $whereDql);

        return array_values(array_map(
            static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0,
            $qb->getQuery()
                ->getSingleColumnResult()
        ));
    }

    /**
     * Shared "GROUP BY <expr>, COUNT(DISTINCT id) AS counter" shape for
     * `SearchFilterRenderer`'s author/added_by/filetypes filter-sidebar
     * blocks. $groupByExpr can be a plain path expression (`i.author`) or
     * a function call (`SUBSTRING_INDEX(i.path, '.', -1)`); DQL's own
     * `GROUP BY` grammar only accepts a path expression or a SELECT-list
     * alias (never an arbitrary function-call expression -- see
     * {@see \Piwigo\Db\DqlFunction}'s own Calendar-redesign precedent), so
     * this always groups by $groupAlias, never $groupByExpr directly.
     *
     * @return list<array<string, mixed>>
     */
    public function countImagesGroupedBy(string $groupByExpr, string $groupAlias, SqlCondition $condition, bool $orderByCounterDesc = false): array
    {
        $qb = $this->em->createQueryBuilder()
            ->select($groupByExpr . ' AS ' . $groupAlias, 'COUNT(DISTINCT i.id) AS counter')
            ->from(ImageEntity::class, 'i')
            ->innerJoin(ImageCategoryEntity::class, 'ic', Join::WITH, 'ic.imageId = i.id')
            ->groupBy($groupAlias);
        self::applyCondition($qb, $condition);
        if ($orderByCounterDesc) {
            $qb->orderBy('counter', 'DESC');
        }

        return self::castRows($qb->getQuery()->getArrayResult());
    }

    /**
     * Shared "SELECT DISTINCT i.id, <extra cols> WHERE <condition>" shape
     * for `SearchFilterRenderer`'s ratings/filesize/ratios filter-sidebar
     * blocks -- `DISTINCT` matters here because of the `ImageCategoryEntity`
     * join's own row fan-out (one row per category an image belongs to),
     * not because of the extra columns. $selectExprs are full `"expr AS
     * alias"` strings the caller controls directly, so every row-consumer
     * loop (keyed by column name) works unchanged.
     *
     * @param  list<string>  $selectExprs
     * @return list<array<string, mixed>>
     */
    public function findDistinctImageRows(array $selectExprs, SqlCondition $condition): array
    {
        $qb = $this->em->createQueryBuilder()
            ->select('DISTINCT i.id', ...$selectExprs)
            ->from(ImageEntity::class, 'i')
            ->innerJoin(ImageCategoryEntity::class, 'ic', Join::WITH, 'ic.imageId = i.id');
        self::applyCondition($qb, $condition);

        return self::castRows($qb->getQuery()->getArrayResult());
    }

    /**
     * Shared "SELECT <col> ... GROUP BY <col> ORDER BY <col> ASC" shape
     * for `SearchFilterRenderer`'s height/width filter-sidebar blocks --
     * $dqlPath is always a plain path expression here (`i.height`/
     * `i.width`), so grouping/ordering by it directly (not an alias) is
     * safe (unlike {@see countImagesGroupedBy()}'s own function-call
     * case). Returns strings, matching the contract every real caller
     * here expects.
     *
     * @return list<string>
     */
    public function findDistinctImageColumnValues(string $dqlPath, SqlCondition $condition): array
    {
        $qb = $this->em->createQueryBuilder()
            ->select($dqlPath)
            ->from(ImageEntity::class, 'i')
            ->innerJoin(ImageCategoryEntity::class, 'ic', Join::WITH, 'ic.imageId = i.id')
            ->groupBy($dqlPath)
            ->orderBy($dqlPath, 'ASC');
        self::applyCondition($qb, $condition);

        return array_values(array_map(
            static fn (mixed $v): string => is_scalar($v) ? (string) $v : '',
            $qb->getQuery()
                ->getSingleColumnResult()
        ));
    }

    /**
     * `SearchFilterRenderer`'s `cat`/album-lookup block: a single-table
     * `CategoryEntity` query (`id`/`uppercats`), no image join.
     *
     * `c.id` is custom-Typed (`category_id`), so `getArrayResult()`
     * (Gotcha #1) returns a real `CategoryId` instance for it, unwrapped
     * below -- see `CategoryEntity`'s own docblock.
     *
     * @return list<array{id: int, uppercats: string}>
     */
    public function findCategoryIdsAndUppercats(SqlCondition $condition): array
    {
        $qb = $this->em->createQueryBuilder()
            ->select('c.id', 'c.uppercats')
            ->from(CategoryEntity::class, 'c');
        self::applyCondition($qb, $condition);

        $rows = $qb->getQuery()
            ->getArrayResult();

        $result = [];
        foreach ($rows as $row) {
            if (! is_array($row) || ! ($row['id'] ?? null) instanceof CategoryId || ! is_string($row['uppercats'] ?? null)) {
                continue;
            }

            $result[] = [
                'id' => $row['id']->value,
                'uppercats' => $row['uppercats'],
            ];
        }

        return $result;
    }

    /**
     * Generic "list of ids matching an arbitrary WHERE clause" executor,
     * used by the quick-search token evaluator's per-token image/tag/
     * category lookups -- a real, permanent need for dynamically-varying
     * table/column/operator combinations (MySQL FULLTEXT `MATCH()...
     * AGAINST()`, a plugin extensibility hook accepting raw SQL clause
     * strings), neither expressible in DQL. $whereSql uses `?`
     * placeholders bound from $params -- callers building a clause from
     * free-text search terms MUST bind through $params (or {@see quote()}
     * when the value has to be embedded inline in a larger OR-joined
     * fragment), never string-concatenate raw user input.
     *
     * @param  list<mixed>  $params
     * @return list<int>
     */
    public function findIdsByClause(string $selectSql, string $fromSql, string $whereSql, array $params = []): array
    {
        $ids = $this->em->getConnection()
            ->executeQuery(
                <<<SQL
                SELECT {$selectSql} FROM {$fromSql} WHERE {$whereSql}
                SQL
                ,
                $params
            )->fetchFirstColumn();

        return array_values(array_map(
            static fn (mixed $id): int => (int) $id,
            array_filter($ids, is_numeric(...))
        ));
    }

    /**
     * Same shape as {@see findIdsByClause()} but for full rows (the
     * quick-search tag/category text lookups need the whole row, not just
     * the id, to build `QResults::$all_tags`/`$all_cats`).
     *
     * `SELECT *` against `images`/`categories`/`tags` also picks up their
     * Postgres-only `tsv_search`/`tsv_author` generated columns, which no
     * real caller here ever wants. Stripped by key prefix rather than
     * narrowing the `SELECT *` itself: this method is deliberately
     * generic across whatever table/columns each caller's own `$fromSql`
     * names, and no legitimate caller's real column name would ever
     * start with `tsv_` (this schema's own naming convention -- every
     * real column is a plain noun, `tsv_*` is reserved for the
     * FULLTEXT-generated-column purpose specifically), so this can't
     * drop a real, wanted column for either platform.
     *
     * @param  list<mixed>  $params
     * @return list<array<string, mixed>>
     */
    public function findRowsByClause(string $fromSql, string $whereSql, array $params = []): array
    {
        $rows = $this->em->getConnection()
            ->executeQuery(
                <<<SQL
                SELECT * FROM {$fromSql} WHERE {$whereSql}
                SQL
                ,
                $params
            )->fetchAllAssociative();

        return array_map(
            static fn (array $row): array => array_filter(
                $row,
                static fn (string $key): bool => ! str_starts_with($key, 'tsv_'),
                ARRAY_FILTER_USE_KEY
            ),
            $rows
        );
    }

    /**
     * Safely quotes (and includes the surrounding quotes for) a value for
     * inline embedding into a hand-built SQL fragment -- for the specific
     * case where a free-text term has to be OR/AND-joined alongside other,
     * already-safe raw fragments (numeric/date range clauses from
     * QNumericRangeScope/QDateRangeScope) into a single WHERE string,
     * where a `?`-bound parameter can't cleanly compose. Uses the real
     * DBAL driver's own escaping -- addslashes() doesn't handle every
     * driver's/charset's escaping rules correctly.
     */
    public function quote(string $value): string
    {
        return $this->em->getConnection()
            ->quote($value);
    }

    /**
     * Exposes a real Doctrine\DBAL\Query\Expression\ExpressionBuilder for
     * composing dynamic OR/AND-joined clause lists via typed method calls
     * (e.g. $expr->and(...$whereClauses)) instead of hand-rolled
     * implode(' AND ', ...), same convention as
     * Permission\PermissionRepository::expressionBuilder().
     */
    public function expressionBuilder(): ExpressionBuilder
    {
        return $this->em->getConnection()
            ->createExpressionBuilder();
    }

    /**
     * Real MySQL server version -- a property read on the already-connected
     * driver handle under mysqli, unaffected by the DBAL native-int/float
     * casting difference documented on the methods below (server version is
     * a string on every driver).
     */
    public function getDbVersion(): string
    {
        return $this->em->getConnection()
            ->getServerVersion();
    }
}
