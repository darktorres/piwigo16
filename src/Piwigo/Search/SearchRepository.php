<?php

declare(strict_types=1);

namespace Piwigo\Search;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\ORM\QueryBuilder;
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
 * Further SQL-modernization audit, Item 15H: drops `extends
 * AbstractRepository` for a directly-injected `EntityManagerInterface`
 * (`Search` is `L2bExtendedDomain`; `Image`/`Category` are
 * `L2aCoreDomain`, an allowed downward dependency, same shape as
 * `Calendar\CalendarRepository`/`Notification\NotificationRepository`).
 * The `search` table's own basic CRUD (`findOneByClause()`/`now()`/
 * `insertSearch()`/`countByUuid()`/`findRulesByIds()`) converted to real
 * DQL/`persist()` below -- every real shape was bounded (`getSearchIdPattern()`'s
 * own 2 literal WHERE patterns, confirmed via reading every real caller).
 * `findIdsByClause()`/`findRowsByClause()`/`quote()`/`expressionBuilder()`
 * stay generic -- the quick-search token evaluator's own real, permanent
 * need for dynamically-varying table/column/operator combinations
 * (MySQL FULLTEXT search, a plugin extensibility hook accepting raw SQL)
 * has no DQL representation, confirmed by reading it, not assumed.
 * `SearchService::getRegularSearchResults()`'s 12 advanced-search criteria
 * and `searchAllwords()` -- both genuinely bounded shapes, unlike the
 * quicksearch path -- converted to {@see findImageIdsMatching()} instead,
 * which they no longer share `findIdsByClause()` with.
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
        // same as ArrayHelper::safeJsonDecode() did -- PHP auto-coerces a
        // numeric-string JSON object key (e.g. "0") into a PHP int array
        // key, so this same string-keys-only filter (originally
        // Search\Projection\Search::decodeRules()'s own) still applies
        // here.
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
     * Replaces the former generic `findOneByClause()`'s own 2 real WHERE
     * shapes (`getSearchIdPattern()`'s own `'id = ?'`/`'search_uuid = ?'`
     * literals) with 2 typed methods -- confirmed via reading every real
     * caller (`SearchService::getSearchInfo()`, `Ws\PwgCore::
     * historySearch()`) no other WHERE shape is ever constructed.
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
     * Further SQL-modernization audit, Item 15H: converted to
     * `persist()`/`flush()` -- DQL has no INSERT statement. The former
     * `now()`'s own `SELECT NOW()` round-trip retired entirely;
     * `saveSearch()`'s own `$createdOn` is now `Env::now()`-computed in
     * PHP, matching the same "PHP-computed timestamp beats a DB round
     * trip, and stays PIWIGO_TEST_NOW-aware" pattern used throughout this
     * campaign.
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
     * @param  list<mixed>  $params
     * @return list<array<string, mixed>>
     */
    public function findRowsByClause(string $fromSql, string $whereSql, array $params = []): array
    {
        return $this->em->getConnection()
            ->executeQuery(
                <<<SQL
                SELECT * FROM {$fromSql} WHERE {$whereSql}
                SQL
                ,
                $params
            )->fetchAllAssociative();
    }

    /**
     * Safely quotes (and includes the surrounding quotes for) a value for
     * inline embedding into a hand-built SQL fragment -- for the specific
     * case where a free-text term has to be OR/AND-joined alongside other,
     * already-safe raw fragments (numeric/date range clauses from
     * QNumericRangeScope/QDateRangeScope) into a single WHERE string,
     * where a `?`-bound parameter can't cleanly compose. Uses the real
     * DBAL driver's own escaping (unlike the original's addslashes(),
     * SEC-18's own fix target -- addslashes() doesn't handle every
     * driver's/charset's escaping rules correctly).
     */
    public function quote(string $value): string
    {
        return $this->em->getConnection()
            ->quote($value);
    }

    /**
     * Further SQL-modernization audit, Item 7: exposes a real
     * Doctrine\DBAL\Query\Expression\ExpressionBuilder for composing
     * dynamic OR/AND-joined clause lists via typed method calls (e.g.
     * $expr->and(...$whereClauses)) instead of hand-rolled
     * implode(' AND ', ...), same convention Permission\
     * PermissionRepository::expressionBuilder() established for Item 2.
     */
    public function expressionBuilder(): \Doctrine\DBAL\Query\Expression\ExpressionBuilder
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
     * Generic free-form-SQL row executor for SearchFilterRenderer's own
     * dynamically-assembled filter queries (custom SELECT lists, JOINs,
     * GROUP BY/ORDER BY -- not expressible through findRowsByClause()'s
     * fixed "SELECT * FROM X WHERE Y" shape). Every value is cast the same
     * way {@see \Piwigo\Db\MysqliDb::fetchAssoc()} always did, so this is a
     * behavior-preserving 1:1 API swap -- callers written against the old
     * MysqliDb::query()+fetchAssoc()/query2Array() string|null contract
     * need no changes, sidestepping the native-int-vs-string DBAL
     * regression class this migration has hit before (see
     * AbstractRepository-based methods elsewhere that intentionally cast to
     * `int` instead -- those are fresh typed contracts, not a mimicked
     * legacy shape).
     *
     * Further SQL-modernization audit, Item 7: $condition (a SqlCondition,
     * default empty) replaces the former separate $params/$types pair --
     * every real caller (SearchFilterRenderer) already builds its bound
     * values via a SqlCondition (Permission\PermissionService::
     * getSqlConditionFandFAsCondition()/getClauseForFilter()'s own return),
     * so this removes the "unpack into two parallel arrays, then repack
     * elsewhere" step for no real benefit. $condition->sql itself is
     * unused here -- unlike every other SqlCondition consumer in this
     * codebase, the WHERE clause text is already embedded directly in
     * $sql (this method's own long-standing "caller composes trusted
     * query text" contract, unchanged by this item -- see class docblock);
     * a caller with no bindable values at all (e.g. the one query with no
     * FROM/WHERE clause whatsoever) simply omits $condition, matching its
     * own former zero-argument call shape.
     *
     * Further SQL-modernization audit, Item 15H: still needed by
     * SearchFilterRenderer's own filter-block queries for now -- see that
     * class's own follow-up conversion, which retires this method once
     * every real call site converts to a typed DQL method instead.
     *
     * @return list<array<string, string|null>>
     */
    public function queryRows(string $sql, SqlCondition $condition = new SqlCondition('')): array
    {
        return array_map(
            static fn (array $row): array => array_map(
                static fn (mixed $value): ?string => is_scalar($value) ? (string) $value : null,
                $row
            ),
            $this->em->getConnection()
                ->executeQuery($sql, $condition->parameters, $condition->types)
                ->fetchAllAssociative()
        );
    }

    /**
     * Same shape as {@see \Piwigo\Db\MysqliDb::query2Array()} with both a
     * key and a value column name.
     *
     * @return array<string, string|null>
     */
    public function queryKeyedColumn(string $sql, string $keyColumn, string $valueColumn, SqlCondition $condition = new SqlCondition('')): array
    {
        $result = [];
        foreach ($this->queryRows($sql, $condition) as $row) {
            $key = $row[$keyColumn] ?? '';
            $result[$key] = $row[$valueColumn] ?? null;
        }

        return $result;
    }

    /**
     * Same shape as {@see \Piwigo\Db\MysqliDb::query2Array()} with only a
     * value column name.
     *
     * @return list<string|null>
     */
    public function queryColumn(string $sql, string $column, SqlCondition $condition = new SqlCondition('')): array
    {
        return array_map(
            static fn (array $row): ?string => $row[$column] ?? null,
            $this->queryRows($sql, $condition)
        );
    }
}
