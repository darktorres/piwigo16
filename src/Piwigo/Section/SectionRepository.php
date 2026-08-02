<?php

declare(strict_types=1);

namespace Piwigo\Section;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Piwigo\Db\AbstractRepository;

/**
 * Persistence layer for SectionPopulator/SectionInitializer's own
 * per-section-branch item-id queries (categories/recent_pics/most_visited/
 * best_rated/list/flat-subcat), each exposed as its own named method below
 * parameterized by the truly dynamic fragments (permission conditions,
 * order-by, limit) SectionPopulator computes per branch -- deptrac DBAL-leak
 * cleanup (2026-07-29) moved the actual query text here from
 * SectionPopulator itself, which used to build full SQL strings and hand
 * them to a generic queryColumn()/executeStatement() escape hatch. Favorites
 * queries stay in Users\UserRepository (a different domain); this class
 * still exposes queryColumn()/executeStatement() as raw escape hatches for
 * SectionInitializer's own remaining direct use.
 */
final class SectionRepository extends AbstractRepository
{
    /**
     * Same shape as the since-deleted \Piwigo\Db\MysqliDb::query2Array()
     * called with only a value column name (`query2Array($sql, null,
     * $column)`) -- every value cast to string|null the same way
     * MysqliDb::fetchAssoc() always did, so this is a behavior-preserving
     * 1:1 API swap for SectionPopulator's own single-column item-id
     * queries.
     *
     * @param array<string, mixed> $params
     * @param array<string, ArrayParameterType|ParameterType> $types
     * @return list<string|null>
     */
    public function queryColumn(string $sql, array $params = [], array $types = []): array
    {
        return array_map(
            static fn (mixed $value): ?string => is_scalar($value) ? (string) $value : null,
            $this->conn->executeQuery($sql, $params, $types)
                ->fetchFirstColumn()
        );
    }

    public function executeStatement(string $sql): void
    {
        $this->conn->executeStatement($sql);
    }

    /**
     * Visible subcategory ids directly under $uppercatsPattern (a category's
     * own `uppercats` value, matched as `uppercats LIKE '$uppercatsPattern,%'`)
     * -- SectionPopulator's own "flat categories" mode subcategory expansion.
     * $permissionCondition is an already-built, trusted SQL fragment
     * (leading "\n  AND"); any real value it references is bound via
     * $params/$types rather than spliced.
     *
     * @param array<string, mixed> $params
     * @param array<string, ArrayParameterType|ParameterType> $types
     * @return list<string|null>
     */
    public function findVisibleSubcategoryIds(string $uppercatsPattern, string $permissionCondition, array $params = [], array $types = []): array
    {
        $params['uppercatsPattern'] = $uppercatsPattern . ',%';

        return $this->queryColumn('
SELECT id
  FROM ' . \Piwigo\Db\Tables::categories() . '
  WHERE
    uppercats LIKE :uppercatsPattern '
    . $permissionCondition, $params, $types);
    }

    /**
     * Image ids for the current category/flat-mode section, given
     * $whereSql (either a plain `category_id = X` or the flat-mode
     * `category_id IN (...)` SectionPopulator already resolved) --
     * SectionPopulator's own main categories-section query. $whereSql/
     * $forbiddenSql/$orderBySql are already-built, trusted SQL fragments;
     * any real value they reference is bound via $params/$types.
     *
     * @param array<string, mixed> $params
     * @param array<string, ArrayParameterType|ParameterType> $types
     * @return list<string|null>
     */
    public function findSectionImageIds(string $whereSql, string $forbiddenSql, string $orderBySql, array $params = [], array $types = []): array
    {
        return $this->queryColumn('
SELECT id
  FROM ' . \Piwigo\Db\Tables::imageCategory() . '
    INNER JOIN ' . \Piwigo\Db\Tables::images() . ' ON id = image_id
  WHERE
    ' . $whereSql . '
' . $forbiddenSql . '
  GROUP BY id
  ' . $orderBySql . '
;', $params, $types);
    }

    /**
     * Image ids for the "recent_pics" section -- $recentSql is
     * UserService::getRecentPhotosSql()'s own raw WHERE fragment.
     *
     * @param array<string, mixed> $params
     * @param array<string, ArrayParameterType|ParameterType> $types
     * @return list<string|null>
     */
    public function findRecentImageIds(string $recentSql, string $forbiddenSql, string $orderBySql, array $params = [], array $types = []): array
    {
        return $this->queryColumn('
SELECT id
  FROM ' . \Piwigo\Db\Tables::images() . '
    INNER JOIN ' . \Piwigo\Db\Tables::imageCategory() . ' AS ic ON id = ic.image_id
  WHERE '
  . $recentSql . '
  ' . $forbiddenSql . '
  GROUP BY id
  ' . $orderBySql . '
;', $params, $types);
    }

    /**
     * Image ids for the "most_visited" section, capped at $limit.
     *
     * @param array<string, mixed> $params
     * @param array<string, ArrayParameterType|ParameterType> $types
     * @return list<string|null>
     */
    public function findTopByHitsImageIds(string $forbiddenSql, string $orderBySql, int $limit, array $params = [], array $types = []): array
    {
        $params['limit'] = $limit;
        $types['limit'] = ParameterType::INTEGER;

        return $this->queryColumn('
SELECT id
  FROM ' . \Piwigo\Db\Tables::images() . '
    INNER JOIN ' . \Piwigo\Db\Tables::imageCategory() . ' AS ic ON id = ic.image_id
  WHERE hit > 0
    ' . $forbiddenSql . '
    GROUP BY id
    ' . $orderBySql . '
  LIMIT :limit
;', $params, $types);
    }

    /**
     * Image ids for the "best_rated" section, capped at $limit.
     *
     * @param array<string, mixed> $params
     * @param array<string, ArrayParameterType|ParameterType> $types
     * @return list<string|null>
     */
    public function findTopRatedImageIds(string $forbiddenSql, string $orderBySql, int $limit, array $params = [], array $types = []): array
    {
        $params['limit'] = $limit;
        $types['limit'] = ParameterType::INTEGER;

        return $this->queryColumn('
SELECT id
  FROM ' . \Piwigo\Db\Tables::images() . '
    INNER JOIN ' . \Piwigo\Db\Tables::imageCategory() . ' AS ic ON id = ic.image_id
  WHERE rating_score IS NOT NULL
    ' . $forbiddenSql . '
    GROUP BY id
    ' . $orderBySql . '
  LIMIT :limit
;', $params, $types);
    }

    /**
     * Image ids for the "list" section (a caller-supplied id set, e.g. a
     * random-photos block), restricted to $imageIds and visibility.
     *
     * @param list<string> $imageIds
     * @param array<string, mixed> $params
     * @param array<string, ArrayParameterType|ParameterType> $types
     * @return list<string|null>
     */
    public function findImageIdsAmongList(array $imageIds, string $forbiddenSql, string $orderBySql, array $params = [], array $types = []): array
    {
        $params['imageIds'] = $imageIds;
        $types['imageIds'] = ArrayParameterType::STRING;

        return $this->queryColumn('
SELECT id
  FROM ' . \Piwigo\Db\Tables::images() . '
    INNER JOIN ' . \Piwigo\Db\Tables::imageCategory() . ' AS ic ON id = ic.image_id
  WHERE image_id IN (:imageIds)
    ' . $forbiddenSql . '
  GROUP BY id
  ' . $orderBySql . '
;', $params, $types);
    }

    /**
     * Real driver-escaping, without the surrounding quotes {@see
     * \Doctrine\DBAL\Connection::quote()} adds -- SectionInitializer's own
     * use of this (a $_GET-key character-escape) was never actually SQL, it
     * (mis)used mysqli_real_escape_string() as a general string sanitizer
     * for a URL path token; ported as-is rather than changed to something
     * SQL-appropriate, since altering that escaping behavior is out of this
     * migration's "same result" scope.
     */
    public function escapeToken(string $value): string
    {
        return substr($this->conn->quote($value), 1, -1);
    }
}
