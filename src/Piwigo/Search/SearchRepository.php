<?php

declare(strict_types=1);

namespace Piwigo\Search;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Query\Expression\ExpressionBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Piwigo\Category\CategoryEntity;
use Piwigo\Common\ValueObject\CategoryId;
use Piwigo\Common\ValueObject\ImageId;
use Piwigo\Common\ValueObject\SqlDateTime;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Image\ImageEntity;
use Piwigo\Permission\SqlCondition;
use Piwigo\Search\Projection\CategoryIdUppercats;
use Piwigo\Search\Projection\Search;

/**
 * Persistence layer for the search domain: the `search` table (saved
 * search rules) plus a handful of raw-DBAL image/tag/category lookups the
 * quick-search token evaluator needs for operator combinations DQL can't
 * express (MySQL FULLTEXT search, a plugin extensibility hook accepting
 * raw SQL) -- see {@see findImageIdsByRawWhere()}'s own docblock for the
 * full reasoning.
 *
 * Takes a directly-injected `EntityManagerInterface` rather than
 * extending `AbstractRepository` (`Search` is `L2bExtendedDomain`;
 * `Image`/`Category` are `L2aCoreDomain`, an allowed downward
 * dependency, same shape as `Calendar\CalendarRepository`/
 * `Notification\NotificationRepository`). The `search` table's own
 * basic CRUD runs through DQL/`persist()` below.
 * `findImageIdsByRawWhere()`/`findImageIdsForRegularSearch()`/
 * `findTagRowsByRawWhere()`/`findCategoryRowsByRawWhere()`/
 * `expressionBuilder()` each name their own real table and column list --
 * only the WHERE clause and its bound parameters vary per call, not the
 * table/column shape itself (§14 retired the earlier fully-generic
 * `findIdsByClause()`/`findRowsByClause()`, plus the always-unused
 * `quote()`, in favor of these).
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
 * assembled); findTagRowsByRawWhere()/findCategoryRowsByRawWhere()'s row
 * shape carries a plugin-rewritable `name` key (see
 * `Search\QResults::$all_tags`'s own docblock), so it can't be a typed
 * Projection either; $rules matches Search Projection's own
 * already-documented JSON rules-bag rationale.
 */
final readonly class SearchRepository
{
    public function __construct(
        private EntityManagerInterface $em,
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
            createdOn: $entity->createdOn?->value,
            createdBy: $entity->createdBy?->value,
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
     * $createdOn/$searchUuid default to null for an ephemeral,
     * metadata-less insert (no user-facing permalink, never forked) --
     * no current real caller passes null for either: SearchService::
     * saveSearch() always passes real values for both.
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
        $entity = new SavedSearchEntity($searchUuid, SqlDateTime::tryFrom($createdOn), UserId::tryFrom($createdBy), $forkedFrom, $rules);
        $this->em->persist($entity);
        $this->em->flush();

        return $entity->id ?? 0;
    }

    /**
     * Batch lookup of decoded `rules` for a list of search ids, used by
     * `Controller\Api\History\HistorySearchController`'s history-listing
     * enrichment (one query for every `search_id` referenced across a
     * page of history rows, instead of unserialize()-ing a raw per-row
     * string itself).
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
                static fn (mixed $value): mixed => match (true) {
                    $value instanceof ImageId, $value instanceof SqlDateTime => $value->value,
                    default => $value,
                },
                $row
            );

            $result[] = array_combine(array_map(strval(...), array_keys($unwrapped)), array_values($unwrapped));
        }

        return $result;
    }

    /**
     * Shared "images matching this WHERE fragment" executor for every
     * `SearchService::getRegularSearchResults()` advanced-search criterion
     * and `searchAllwords()` -- all of them share the exact same `FROM
     * ImageEntity i INNER JOIN i.imageCategories ic WHERE <criterion>`
     * shape (the caller already AND-combines its
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
            ->innerJoin('i.imageCategories', 'ic');
        $whereDql->applyTo($qb);

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
            ->innerJoin('i.imageCategories', 'ic')
            ->groupBy($groupAlias);
        $condition->applyTo($qb);
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
            ->innerJoin('i.imageCategories', 'ic');
        $condition->applyTo($qb);

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
            ->innerJoin('i.imageCategories', 'ic')
            ->groupBy($dqlPath)
            ->orderBy($dqlPath, 'ASC');
        $condition->applyTo($qb);

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
     * @return list<CategoryIdUppercats>
     */
    public function findCategoryIdsAndUppercats(SqlCondition $condition): array
    {
        $qb = $this->em->createQueryBuilder()
            ->select('c.id', 'c.uppercats')
            ->from(CategoryEntity::class, 'c');
        $condition->applyTo($qb);

        $rows = $qb->getQuery()
            ->getArrayResult();

        $result = [];
        foreach ($rows as $row) {
            if (! is_array($row) || ! ($row['id'] ?? null) instanceof CategoryId || ! is_string($row['uppercats'] ?? null)) {
                continue;
            }

            $result[] = new CategoryIdUppercats($row['id'], $row['uppercats']);
        }

        return $result;
    }

    /**
     * "Image ids matching an arbitrary WHERE clause" executor -- the
     * quick-search token evaluator's own per-token image lookup
     * (`SearchService::qsearchGetImages()`) and its "re-sort a known id
     * set" step, a real, permanent need for dynamically-varying operator
     * combinations (MySQL FULLTEXT `MATCH()...AGAINST()`, a plugin
     * extensibility hook accepting raw SQL clause strings) that DQL can't
     * express. $whereSql uses `?` placeholders bound from $params --
     * callers building a clause from free-text search terms MUST bind
     * through $params (or {@see quote()} when the value has to be embedded
     * inline in a larger OR-joined fragment), never string-concatenate raw
     * user input. Has no `SqlCondition` (named-parameter) equivalent on
     * purpose -- see {@see findTagRowsByRawWhere()}'s own docblock for why
     * (the same plugin-hook contract feeds this method too).
     *
     * An empty $whereSql means "no restriction" and omits the WHERE
     * entirely, so callers matching everything pass nothing rather than a
     * `1=1` stand-in. $orderBySqlBody is appended as a literal `ORDER BY`
     * clause (already rendered by the caller, e.g. via
     * {@see \Piwigo\Db\SortRenderer::toSqlBody()}), empty meaning no order.
     *
     * @param  list<mixed>  $params
     * @return list<int>
     */
    public function findImageIdsByRawWhere(string $whereSql, array $params = [], string $orderBySqlBody = ''): array
    {
        $where = $whereSql === '' ? '' : 'WHERE ' . $whereSql;
        $orderBy = $orderBySqlBody === '' ? '' : 'ORDER BY ' . $orderBySqlBody;
        $ids = $this->em->getConnection()
            ->executeQuery(
                <<<SQL
                SELECT id FROM images i {$where} {$orderBy}
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
     * `SearchService::getRegularSearchResults()`'s own final permission-
     * filtered image id assembly -- same raw-WHERE/plugin-hook shape as
     * {@see findImageIdsByRawWhere()}, but with an optional `image_category`
     * join (needed only when the permission check reads `category_id`) and
     * an unconditional `GROUP BY id` (an image can have several category
     * memberships once joined, so `DISTINCT`/`GROUP BY` is required to keep
     * one row per image -- `GROUP BY`, not `DISTINCT`, because
     * `Db\DbConnection` deliberately never strips `ONLY_FULL_GROUP_BY`,
     * under which `SELECT DISTINCT id ... ORDER BY <col not in select>` is
     * invalid but `GROUP BY id` (functionally dependent via the primary
     * key) is not).
     *
     * @param  list<mixed>  $params
     * @return list<int>
     */
    public function findImageIdsForRegularSearch(string $whereSql, array $params, bool $joinImageCategory, string $orderBySqlBody): array
    {
        $join = $joinImageCategory ? 'INNER JOIN image_category AS ic ON id = ic.image_id' : '';
        $where = $whereSql === '' ? '' : 'WHERE ' . $whereSql;
        $orderBy = $orderBySqlBody === '' ? '' : 'ORDER BY ' . $orderBySqlBody;
        $ids = $this->em->getConnection()
            ->executeQuery(
                <<<SQL
                SELECT id FROM images i {$join} {$where} GROUP BY id {$orderBy}
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
     * Quick-search tag text-match lookup (`SearchService::qsearchGetTags()`)
     * -- every real `tags` column, explicitly listed rather than `SELECT *`,
     * so the Postgres-only `tsv_search` generated column is never fetched in
     * the first place (no key-stripping needed afterward). $whereSql is a
     * caller-built `?`-bound REGEXP/FULLTEXT/LIKE OR-clause list
     * ({@see \Piwigo\Search\SearchService::qsearchGetTextTokenSearchSql()}),
     * which has no `SqlCondition` (named-parameter) equivalent here on
     * purpose -- {@see \Piwigo\Search\Event\QsearchGetImagesSqlScopes} is a
     * public plugin hook built on the same positional-`?` clause/params
     * shape, so changing it would be a breaking contract change, not an
     * internal refactor.
     *
     * @param  list<mixed>  $params
     * @return list<array<string, mixed>>
     */
    public function findTagRowsByRawWhere(string $whereSql, array $params = []): array
    {
        $where = $whereSql === '' ? '' : 'WHERE ' . $whereSql;

        return $this->em->getConnection()
            ->executeQuery(
                <<<SQL
                SELECT id, name, url_name, lastmodified FROM tags {$where}
                SQL
                ,
                $params
            )->fetchAllAssociative();
    }

    /**
     * Quick-search category text-match lookup
     * (`SearchService::qsearchGetCategories()`) -- same shape and same
     * plugin-hook-compatibility reasoning as
     * {@see findTagRowsByRawWhere()}, every real `categories` column
     * explicitly listed instead of `SELECT *`.
     *
     * @param  list<mixed>  $params
     * @return list<array<string, mixed>>
     */
    public function findCategoryRowsByRawWhere(string $whereSql, array $params = []): array
    {
        $where = $whereSql === '' ? '' : 'WHERE ' . $whereSql;
        // `rank` is a reserved word on both platforms (MySQL 8.0.2+), same
        // reasoning as Db\SortRenderer::rankColumn() -- always quoted.
        $conn = $this->em->getConnection();
        $rankColumn = $conn->getDatabasePlatform()
            ->quoteSingleIdentifier('rank');

        return $conn
            ->executeQuery(
                <<<SQL
                SELECT id, name, id_uppercat, comment, dir, {$rankColumn}, status, site_id, visible, representative_picture_id, uppercats, commentable, global_rank, image_order, permalink, lastmodified
                FROM categories {$where}
                SQL
                ,
                $params
            )->fetchAllAssociative();
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

    /**
     * Whether the real, live connection is PostgreSQL -- read from the
     * connected platform, not `DbCredentials::fromEnv()`, so `SearchService`
     * (which holds no `Connection` of its own) doesn't need an env read to
     * branch its quick-search SQL construction.
     */
    public function isPostgres(): bool
    {
        return $this->em->getConnection()
            ->getDatabasePlatform() instanceof PostgreSQLPlatform;
    }
}
