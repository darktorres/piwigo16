<?php

declare(strict_types=1);

namespace Piwigo\Search;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Piwigo\Db\AbstractRepository;

/**
 * Persistence layer for the search domain.
 */
final class SearchRepository extends AbstractRepository
{
    /**
     * Return the saved-search row whose id or search_uuid matches $candidate.
     * $idColumn is one of 'id' / 'search_uuid' (caller-controlled, validated
     * by the caller).
     *
     * @return array<string, mixed>|null
     */
    public function findSearchRow(string $idColumn, string $candidate): ?array
    {
        $row = $this->conn->createQueryBuilder()
            ->select('*')
            ->from($this->table('search'))
            ->where($idColumn . ' = :candidate')
            ->setParameter('candidate', $candidate)
            ->executeQuery()
            ->fetchAssociative();
        return $row !== false ? $row : null;
    }

    /**
     * Insert a full search row (rules, created_on, created_by, search_uuid,
     * forked_from) and return its new id.
     *
     * @param array<string, mixed> $fields
     */
    public function insertSearchRow(array $fields): int
    {
        $this->conn->insert($this->table('search'), $fields);
        return (int) $this->conn->lastInsertId();
    }

    /**
     * SELECT DISTINCT(id) FROM images i INNER JOIN image_category ic ON
     * id = ic.image_id WHERE <whereClause> $permWhere — the per-filter shape
     * used throughout SearchService::getRegularSearchResults.
     *
     * The caller embeds free-form $whereClause SQL (already escaped or
     * derived from controlled inputs); $permWhere/$permParams/$permTypes
     * come from PermissionService::getSqlConditionFandF.
     *
     * @param list<mixed>                                              $permParams
     * @param list<ArrayParameterType|ParameterType>                   $permTypes
     * @return list<int>
     */
    public function findDistinctImageIdsByWhereWithPermissions(
        string $whereClause,
        string $permWhere,
        array $permParams,
        array $permTypes,
    ): array {
        $sql = 'SELECT DISTINCT(id)'
            . ' FROM ' . $this->table('images') . ' AS i'
            . ' INNER JOIN ' . $this->table('image_category') . ' AS ic ON id = ic.image_id'
            . ' WHERE ' . $whereClause . ' ' . $permWhere;
        return array_map(
            static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0,
            array_column($this->conn->executeQuery($sql, $permParams, $permTypes)->fetchAllAssociative(), 'id'),
        );
    }

    /**
     * Apply Config::orderBy() to the given image ids, returning them in that
     * canonical order. Caller guarantees $ids contains at least 2 entries.
     *
     * @param list<int> $ids
     * @return list<int>
     */
    public function orderImageIds(array $ids, string $orderBy): array
    {
        if ($ids === []) {
            return [];
        }
        $sql = 'SELECT id FROM ' . $this->table('images') . ' i'
            . ' WHERE id IN (?) ' . $orderBy;
        return array_map(
            static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0,
            array_column(
                $this->conn->executeQuery($sql, [$ids], [ArrayParameterType::INTEGER])->fetchAllAssociative(),
                'id',
            ),
        );
    }

    /**
     * Run a caller-built qsearch images query and return image ids. The
     * token clauses are caller-constructed SQL (text-match clauses pre-
     * escaped by SearchService::qsearchGetTextTokenSearchSql) and arrive in
     * the $whereFragment string (starts with "WHERE …").
     *
     * @return list<int>
     */
    public function findQsearchImageIdsByWhere(string $whereFragment): array
    {
        return array_map(
            static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0,
            array_column(
                $this->conn->executeQuery(
                    'SELECT id FROM ' . $this->table('images') . ' i ' . $whereFragment,
                )->fetchAllAssociative(),
                'id',
            ),
        );
    }

    /**
     * Final result-pruning query — DISTINCT(id) FROM images i [JOIN
     * image_category] WHERE <clauses> ORDER BY <orderBy>. The clauses, perm
     * params/types and orderBy fragment are caller-built.
     *
     * @param list<string>                                              $whereClauses
     * @param list<mixed>                                               $permParams
     * @param list<ArrayParameterType|ParameterType>                    $permTypes
     * @return list<int>
     */
    public function findOrderedImageIdsForQsearch(
        array $whereClauses,
        bool $joinImageCategory,
        array $permParams,
        array $permTypes,
        string $orderBy,
    ): array {
        $sql = 'SELECT DISTINCT(id) FROM ' . $this->table('images') . ' i';
        if ($joinImageCategory) {
            $sql .= ' INNER JOIN ' . $this->table('image_category') . ' AS ic ON id = ic.image_id';
        }
        $sql .= ' WHERE ' . implode("\n AND ", $whereClauses) . "\n" . $orderBy;
        return array_map(
            static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0,
            array_column(
                $this->conn->executeQuery($sql, $permParams, $permTypes)->fetchAllAssociative(),
                'id',
            ),
        );
    }

    /**
     * Count saved searches with the given UUID.
     * Used to generate a unique UUID (returns 0 when the candidate is free).
     */
    public function countByUuid(string $uuid): int
    {
        $value = $this->conn->createQueryBuilder()
            ->select('COUNT(*)')
            ->from($this->table('search'))
            ->where('search_uuid = :uuid')
            ->setParameter('uuid', $uuid)
            ->executeQuery()
            ->fetchOne();
        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * Insert a new search row with the given JSON-encoded rules and return its id.
     */
    public function insertSearch(string $encodedRules): int
    {
        $this->conn->insert($this->table('search'), ['rules' => $encodedRules]);
        return (int) $this->conn->lastInsertId();
    }

    /**
     * Return the JSON-encoded rules string for a search by id, or null if not found.
     */
    public function findRulesById(int $id): ?string
    {
        $value = $this->conn->createQueryBuilder()
            ->select('rules')
            ->from($this->table('search'))
            ->where('id = :id')
            ->setParameter('id', $id)
            ->executeQuery()
            ->fetchOne();
        return is_string($value) ? $value : null;
    }

    /** Truncate the search history table. */
    public function deleteAll(): void
    {
        $this->conn->executeStatement('DELETE FROM ' . $this->table('search'));
    }
}
