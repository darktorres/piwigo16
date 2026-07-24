<?php

declare(strict_types=1);

namespace Piwigo\Search;

use Piwigo\Db\AbstractRepository;
use Piwigo\Db\Tables;
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
 */
final class SearchRepository extends AbstractRepository
{
    /**
     * @param  list<mixed>  $params
     */
    public function findOneByClause(string $whereSql, array $params = []): ?Search
    {
        $row = $this->conn->executeQuery(
            'SELECT * FROM ' . Tables::search() . ' WHERE ' . $whereSql,
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
            'SELECT ' . $selectSql . ' FROM ' . $fromSql . ' WHERE ' . $whereSql,
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
            'SELECT * FROM ' . $fromSql . ' WHERE ' . $whereSql,
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

    public function countByUuid(string $uuid): int
    {
        $count = $this->conn->executeQuery(
            'SELECT COUNT(*) FROM ' . Tables::search() . ' WHERE search_uuid = ?',
            [$uuid]
        )->fetchOne();

        return is_numeric($count) ? (int) $count : 0;
    }

    /**
     * @return int the new row's auto-increment id
     */
    public function insertSearch(
        string $rulesSerialized,
        string $createdOn,
        ?int $createdBy,
        string $searchUuid,
        ?int $forkedFrom
    ): int {
        $this->conn->executeStatement(
            'INSERT INTO ' . Tables::search() . ' (rules, created_on, created_by, search_uuid, forked_from) VALUES (?, ?, ?, ?, ?)',
            [$rulesSerialized, $createdOn, $createdBy, $searchUuid, $forkedFrom]
        );

        return (int) $this->conn->lastInsertId();
    }

    public function now(): string
    {
        $row = $this->conn->executeQuery('SELECT NOW()')
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
     * @return list<array<string, string|null>>
     */
    public function queryRows(string $sql): array
    {
        return array_map(
            static fn (array $row): array => array_map(
                static fn (mixed $value): ?string => is_scalar($value) ? (string) $value : null,
                $row
            ),
            $this->conn->executeQuery($sql)
                ->fetchAllAssociative()
        );
    }

    /**
     * Same shape as {@see \Piwigo\Db\MysqliDb::query2Array()} with both a
     * key and a value column name.
     *
     * @return array<string, string|null>
     */
    public function queryKeyedColumn(string $sql, string $keyColumn, string $valueColumn): array
    {
        $result = [];
        foreach ($this->queryRows($sql) as $row) {
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
    public function queryColumn(string $sql, string $column): array
    {
        return array_map(
            static fn (array $row): ?string => $row[$column] ?? null,
            $this->queryRows($sql)
        );
    }
}
