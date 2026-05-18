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

    // -------------------------------------------------------------------------
    // SearchFilterRenderer queries — one method per filter-data fetch
    // -------------------------------------------------------------------------

    /**
     * Author histogram for the "author" filter widget.
     *
     * @param list<mixed>                                              $params
     * @param list<ArrayParameterType|ParameterType>                   $types
     * @return list<array<string, mixed>>
     */
    public function findAuthorsForFilter(string $filterClause, array $params, array $types): array
    {
        $sql = 'SELECT author, COUNT(DISTINCT(id)) AS counter'
            . ' FROM ' . $this->table('images') . ' AS i'
            . ' JOIN ' . $this->table('image_category') . ' AS ic ON ic.image_id = i.id'
            . ' WHERE ' . $filterClause
            . ' AND author IS NOT NULL'
            . ' GROUP BY author';
        return $this->conn->executeQuery($sql, $params, $types)->fetchAllAssociative();
    }

    /**
     * Date thresholds row (24h/7d/30d/3m/6m) for the "date_posted" widget.
     *
     * @return array<string, mixed>
     */
    public function findDatePostedThresholds(): array
    {
        $rows = $this->conn->executeQuery(
            'SELECT SUBDATE(NOW(), INTERVAL 24 HOUR) AS `24h`,'
            . ' SUBDATE(NOW(), INTERVAL 7 DAY) AS `7d`,'
            . ' SUBDATE(NOW(), INTERVAL 30 DAY) AS `30d`,'
            . ' SUBDATE(NOW(), INTERVAL 3 MONTH) AS `3m`,'
            . ' SUBDATE(NOW(), INTERVAL 6 MONTH) AS `6m`',
        )->fetchAllAssociative();
        return $rows[0] ?? [];
    }

    /**
     * Date thresholds row (7d/30d/3m/6m/12m) for the "date_created" widget.
     *
     * @return array<string, mixed>
     */
    public function findDateCreatedThresholds(): array
    {
        $rows = $this->conn->executeQuery(
            'SELECT SUBDATE(NOW(), INTERVAL 7 DAY) AS `7d`,'
            . ' SUBDATE(NOW(), INTERVAL 30 DAY) AS `30d`,'
            . ' SUBDATE(NOW(), INTERVAL 3 MONTH) AS `3m`,'
            . ' SUBDATE(NOW(), INTERVAL 6 MONTH) AS `6m`,'
            . ' SUBDATE(NOW(), INTERVAL 12 MONTH) AS `12m`',
        )->fetchAllAssociative();
        return $rows[0] ?? [];
    }

    /**
     * (id, date_available AS date) rows for the date_posted filter widget.
     *
     * @param list<mixed>                                              $params
     * @param list<ArrayParameterType|ParameterType>                   $types
     * @return list<array<string, mixed>>
     */
    public function findImageDatePostedRows(string $filterClause, array $params, array $types): array
    {
        $sql = 'SELECT DISTINCT id, date_available AS date'
            . ' FROM ' . $this->table('images') . ' AS i'
            . ' JOIN ' . $this->table('image_category') . ' AS ic ON ic.image_id = i.id'
            . ' WHERE ' . $filterClause;
        return $this->conn->executeQuery($sql, $params, $types)->fetchAllAssociative();
    }

    /**
     * (id, date_creation AS date) rows for the date_created filter widget.
     *
     * @param list<mixed>                                              $params
     * @param list<ArrayParameterType|ParameterType>                   $types
     * @return list<array<string, mixed>>
     */
    public function findImageDateCreatedRows(string $filterClause, array $params, array $types): array
    {
        $sql = 'SELECT DISTINCT id, date_creation AS date'
            . ' FROM ' . $this->table('images') . ' AS i'
            . ' JOIN ' . $this->table('image_category') . ' AS ic ON ic.image_id = i.id'
            . ' WHERE ' . $filterClause;
        return $this->conn->executeQuery($sql, $params, $types)->fetchAllAssociative();
    }

    /**
     * (counter, added_by_id) histogram for the "added_by" filter widget.
     *
     * @param list<mixed>                                              $params
     * @param list<ArrayParameterType|ParameterType>                   $types
     * @return list<array<string, mixed>>
     */
    public function findAddedByForFilter(string $filterClause, array $params, array $types): array
    {
        $sql = 'SELECT COUNT(DISTINCT(id)) AS counter, added_by AS added_by_id'
            . ' FROM ' . $this->table('images') . ' AS i'
            . ' JOIN ' . $this->table('image_category') . ' AS ic ON ic.image_id = i.id'
            . ' WHERE ' . $filterClause
            . ' GROUP BY added_by_id'
            . ' ORDER BY counter DESC';
        return $this->conn->executeQuery($sql, $params, $types)->fetchAllAssociative();
    }

    /**
     * (ext → counter) for the file_type filter widget. $forbiddenWhere is the
     * caller's permission filter; the WHERE clause is always "1=1 $forbidden"
     * so this is the "all images visible to me" histogram (cached aggressively
     * upstream).
     *
     * @param list<mixed>                                              $params
     * @param list<ArrayParameterType|ParameterType>                   $types
     * @return array<int|string, mixed>
     */
    public function findAllFileExtensions(string $forbiddenWhere, array $params, array $types): array
    {
        $sql = 'SELECT SUBSTRING_INDEX(path, ".", -1) AS ext, COUNT(DISTINCT(id)) AS counter'
            . ' FROM ' . $this->table('images') . ' AS i'
            . ' JOIN ' . $this->table('image_category') . ' AS ic ON ic.image_id = i.id'
            . ' WHERE 1=1' . $forbiddenWhere
            . ' GROUP BY ext'
            . ' ORDER BY counter DESC';
        return array_column(
            $this->conn->executeQuery($sql, $params, $types)->fetchAllAssociative(),
            'counter',
            'ext',
        );
    }

    /**
     * Per-extension counter restricted to the current filter selection.
     *
     * @param list<mixed>                                              $params
     * @param list<ArrayParameterType|ParameterType>                   $types
     * @return array<int|string, mixed>
     */
    public function findFilteredFileExtensions(string $filterClause, array $params, array $types): array
    {
        $sql = 'SELECT SUBSTRING_INDEX(path, ".", -1) AS ext, COUNT(DISTINCT(id)) AS counter'
            . ' FROM ' . $this->table('images') . ' AS i'
            . ' JOIN ' . $this->table('image_category') . ' AS ic ON ic.image_id = i.id'
            . ' WHERE ' . $filterClause
            . ' GROUP BY ext'
            . ' ORDER BY counter DESC';
        return array_column(
            $this->conn->executeQuery($sql, $params, $types)->fetchAllAssociative(),
            'counter',
            'ext',
        );
    }

    /**
     * (id, rating_score) tuples for the "ratings" filter widget.
     *
     * @param list<mixed>                                              $params
     * @param list<ArrayParameterType|ParameterType>                   $types
     * @return list<array<string, mixed>>
     */
    public function findRatingsForFilter(string $filterClause, array $params, array $types): array
    {
        $sql = 'SELECT DISTINCT id, rating_score'
            . ' FROM ' . $this->table('images') . ' AS i'
            . ' JOIN ' . $this->table('image_category') . ' AS ic ON ic.image_id = i.id'
            . ' WHERE ' . $filterClause;
        return $this->conn->executeQuery($sql, $params, $types)->fetchAllAssociative();
    }

    /**
     * (id, filesize) tuples for the filesize slider widget.
     *
     * @param list<mixed>                                              $params
     * @param list<ArrayParameterType|ParameterType>                   $types
     * @return list<array<string, mixed>>
     */
    public function findFilesizesForFilter(string $filterClause, array $params, array $types): array
    {
        $sql = 'SELECT DISTINCT id, filesize'
            . ' FROM ' . $this->table('images') . ' AS i'
            . ' JOIN ' . $this->table('image_category') . ' AS ic ON ic.image_id = i.id'
            . ' WHERE ' . $filterClause;
        return $this->conn->executeQuery($sql, $params, $types)->fetchAllAssociative();
    }

    /**
     * (id, width, height) tuples for the aspect-ratio bucket widget.
     *
     * @param list<mixed>                                              $params
     * @param list<ArrayParameterType|ParameterType>                   $types
     * @return list<array<string, mixed>>
     */
    public function findRatiosForFilter(string $filterClause, array $params, array $types): array
    {
        $sql = 'SELECT DISTINCT id, width, height'
            . ' FROM ' . $this->table('images') . ' AS i'
            . ' JOIN ' . $this->table('image_category') . ' AS ic ON ic.image_id = i.id'
            . ' WHERE ' . $filterClause
            . ' AND width IS NOT NULL'
            . ' AND height IS NOT NULL';
        return $this->conn->executeQuery($sql, $params, $types)->fetchAllAssociative();
    }

    /**
     * Distinct height values present under the filter, ascending — used to
     * populate the height-slider tick marks.
     *
     * @param list<mixed>                                              $params
     * @param list<ArrayParameterType|ParameterType>                   $types
     * @return list<int>
     */
    public function findDistinctHeightsForFilter(string $filterClause, array $params, array $types): array
    {
        $sql = 'SELECT height'
            . ' FROM ' . $this->table('images') . ' AS i'
            . ' JOIN ' . $this->table('image_category') . ' AS ic ON ic.image_id = i.id'
            . ' WHERE ' . $filterClause
            . ' AND height IS NOT NULL'
            . ' GROUP BY height'
            . ' ORDER BY height ASC';
        $rows = $this->conn->executeQuery($sql, $params, $types)->fetchAllAssociative();
        return array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, array_column($rows, 'height'));
    }

    /**
     * Distinct width values present under the filter, ascending.
     *
     * @param list<mixed>                                              $params
     * @param list<ArrayParameterType|ParameterType>                   $types
     * @return list<int>
     */
    public function findDistinctWidthsForFilter(string $filterClause, array $params, array $types): array
    {
        $sql = 'SELECT width'
            . ' FROM ' . $this->table('images') . ' AS i'
            . ' JOIN ' . $this->table('image_category') . ' AS ic ON ic.image_id = i.id'
            . ' WHERE ' . $filterClause
            . ' AND width IS NOT NULL'
            . ' GROUP BY width'
            . ' ORDER BY width ASC';
        $rows = $this->conn->executeQuery($sql, $params, $types)->fetchAllAssociative();
        return array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, array_column($rows, 'width'));
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
