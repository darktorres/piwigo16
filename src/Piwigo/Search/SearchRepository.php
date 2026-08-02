<?php

declare(strict_types=1);

namespace Piwigo\Search;

use Doctrine\DBAL\ArrayParameterType;
use Piwigo\Core\ArrayHelper;
use Piwigo\Db\AbstractRepository;
use Piwigo\Db\Tables;
use Piwigo\Permission\SqlCondition;
use Piwigo\Search\Projection\Search;

/**
 * Persistence layer for the search domain: the `search` table (saved
 * search rules) plus generic parameterized id-list/row lookups shared by
 * the many distinct WHERE-clause shapes `SearchService` builds (advanced
 * search's 12 criteria, the quick-search token evaluator).
 *
 * Deliberately NOT QueryBuilder-per-query here -- most of these WHERE
 * clauses are assembled dynamically from a variable number of OR/AND-joined
 * fragments (SearchService's own concern), so this repository exposes a
 * small number of generic, fully parameterized executors instead of one
 * method per query shape, matching this project's documented allowance for
 * hand-written parameterized SQL on complex dynamic queries.
 *
 * Every `mixed` below stays that way by design: $params mirrors DBAL
 * Connection::executeQuery()'s own untyped bound-parameter contract
 * (values vary by which dynamically-built WHERE clause a caller
 * assembled); findRowsByClause()'s row shape genuinely varies with
 * $fromSql, same category as CategoryRepository::fetchCallerBuiltQuery();
 * $rules matches Search Projection's own already-documented JSON
 * rules-bag rationale.
 */
final class SearchRepository extends AbstractRepository
{
    /**
     * @param  list<mixed>  $params
     */
    public function findOneByClause(string $whereSql, array $params = []): ?Search
    {
        $searchTable = Tables::search();
        $row = $this->conn->executeQuery(
            <<<SQL
            SELECT * FROM {$searchTable} WHERE {$whereSql}
            SQL
            ,
            $params
        )->fetchAssociative();

        return $row === false ? null : Search::fromRow($row);
    }

    /**
     * Generic "list of ids matching an arbitrary WHERE clause" executor,
     * used for both the advanced-search criteria (each one a distinct
     * `SELECT DISTINCT(id) FROM images ...` shape) and the quick-search
     * token evaluator's per-token image/tag/category lookups. $whereSql
     * uses `?` placeholders bound from $params -- callers building a
     * clause from free-text search terms MUST bind through $params (or
     * {@see quote()} when the value has to be embedded inline in a larger
     * OR-joined fragment), never string-concatenate raw user input.
     *
     * @param  list<mixed>  $params
     * @return list<int>
     */
    public function findIdsByClause(string $selectSql, string $fromSql, string $whereSql, array $params = []): array
    {
        $ids = $this->conn->executeQuery(
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
        return $this->conn->executeQuery(
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
        return $this->conn->quote($value);
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
        return $this->conn->createExpressionBuilder();
    }

    public function countByUuid(string $uuid): int
    {
        $searchTable = Tables::search();
        $count = $this->conn->executeQuery(
            <<<SQL
            SELECT COUNT(*) FROM {$searchTable} WHERE search_uuid = ?
            SQL
            ,
            [$uuid]
        )->fetchOne();

        return is_numeric($count) ? (int) $count : 0;
    }

    /**
     * $createdOn/$searchUuid default to null for Ws\PwgCore::historySearch()'s
     * ephemeral, metadata-less inserts (no user-facing permalink, never
     * forked) -- SearchService::saveSearch() always passes real values for
     * both.
     *
     * @param array<string, mixed> $rules
     * @return int the new row's auto-increment id
     */
    public function insertSearch(
        array $rules,
        ?string $createdOn = null,
        ?int $createdBy = null,
        ?string $searchUuid = null,
        ?int $forkedFrom = null
    ): int {
        $searchTable = Tables::search();
        $this->conn->executeStatement(
            <<<SQL
            INSERT INTO {$searchTable} (rules, created_on, created_by, search_uuid, forked_from) VALUES (?, ?, ?, ?, ?)
            SQL
            ,
            [json_encode($rules), $createdOn, $createdBy, $searchUuid, $forkedFrom]
        );

        return (int) $this->conn->lastInsertId();
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
    public function findRulesByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $searchTable = Tables::search();
        $rows = $this->conn->executeQuery(
            <<<SQL
            SELECT id, rules FROM {$searchTable} WHERE id IN (?)
            SQL
            ,
            [$ids],
            [ArrayParameterType::INTEGER]
        )->fetchAllAssociative();

        $result = [];
        foreach ($rows as $row) {
            if (! is_numeric($row['id'] ?? null)) {
                continue;
            }

            $rulesRaw = $row['rules'] ?? null;
            $result[(int) $row['id']] = is_string($rulesRaw)
                ? array_filter(ArrayHelper::safeJsonDecode($rulesRaw), is_string(...), ARRAY_FILTER_USE_KEY)
                : null;
        }

        return $result;
    }

    public function now(): string
    {
        $row = $this->conn->executeQuery(<<<SQL
            SELECT NOW()
            SQL)
            ->fetchOne();

        return is_string($row) ? $row : '';
    }

    /**
     * Real MySQL server version -- a property read on the already-connected
     * driver handle under mysqli, unaffected by the DBAL native-int/float
     * casting difference documented on the methods below (server version is
     * a string on every driver).
     */
    public function getDbVersion(): string
    {
        return $this->conn->getServerVersion();
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
     * Kept as a fully generic executor deliberately, not collapsed
     * further into e.g. one countGroupedBy()-shaped method: the 17 real
     * call sites this repository serves span images+image_category-joined
     * grouped counts, images+image_category-joined ungrouped row scans,
     * a users-table lookup, and one query with no FROM/WHERE at all --
     * genuinely too varied a set of shapes to fit one narrower signature
     * without either dropping real cases or smuggling raw SQL back in
     * through a different parameter.
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
            $this->conn->executeQuery($sql, $condition->parameters, $condition->types)
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
