<?php

declare(strict_types=1);

namespace Piwigo\Category;

use Doctrine\DBAL\ArrayParameterType;
use Piwigo\Category\Projection\Category;
use Piwigo\Db\AbstractRepository;
use Piwigo\Db\SqlDialect;
use Piwigo\Db\Tables;

/**
 * Persistence layer for the category domain: tree/menu queries, permalink
 * resolution, and the computed-categories rollup ({@see
 * findComputedCategoriesRollup()}). Permission filtering (forbidden/visible
 * categories and images) is passed in as an already-built SQL fragment by
 * the caller (CategoryService, via PermissionService::getSqlConditionFandF())
 * rather than constructed here -- same "repository takes a pre-built
 * permission condition string" shape as RateRepository/CommentRepository.
 */
final class CategoryRepository extends AbstractRepository
{
    public function findById(int $id): ?Category
    {
        $row = $this->conn->createQueryBuilder()
            ->select('*')
            ->from(Tables::categories())
            ->where('id = :id')
            ->setParameter('id', $id)
            ->executeQuery()
            ->fetchAssociative();

        return $row === false ? null : Category::fromRow($row);
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, array{id: int, name: string, permalink: ?string}> keyed by id
     */
    public function findNamesByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $rows = $this->conn->createQueryBuilder()
            ->select('id', 'name', 'permalink')
            ->from(Tables::categories())
            ->where('id IN (:ids)')
            ->setParameter('ids', $ids, ArrayParameterType::INTEGER)
            ->executeQuery()
            ->fetchAllAssociative();

        $byId = [];
        foreach ($rows as $row) {
            /** @var array{id: int, name: string, permalink: ?string} $row */
            $byId[$row['id']] = $row;
        }

        return $byId;
    }

    /**
     * Every category's id/name/permalink, unfiltered -- HtmlService::
     * getCatDisplayNameCache()'s own breadcrumb-rendering cache warm-up.
     *
     * @return array<int, array{id: int, name: string, permalink: ?string}> keyed by id
     */
    public function findAllIdNamePermalink(): array
    {
        $rows = $this->conn->createQueryBuilder()
            ->select('id', 'name', 'permalink')
            ->from(Tables::categories())
            ->executeQuery()
            ->fetchAllAssociative();

        $byId = [];
        foreach ($rows as $row) {
            /** @var array{id: int, name: string, permalink: ?string} $row */
            $byId[$row['id']] = $row;
        }

        return $byId;
    }

    /**
     * Every category whose `uppercats` path (a comma-separated ancestor id
     * list, e.g. "1,4,9") contains any of $ids -- the REGEXP matches an id
     * bounded by string-start/comma on either side, same operator the
     * original free function used (`DB_REGEX_OPERATOR`, platform-specific:
     * `REGEXP` on MySQL/MariaDB).
     *
     * @param  list<int>  $ids
     * @return list<int>
     */
    public function findSubcategoryIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $qb = $this->conn->createQueryBuilder()
            ->select('DISTINCT id')
            ->from(Tables::categories());

        $clauses = [];
        foreach ($ids as $num => $categoryId) {
            $param = 'id' . $num;
            $qb->setParameter($param, '(^|,)' . $categoryId . '(,|$)');
            $clauses[] = 'uppercats ' . $this->regexOperator() . ' :' . $param;
        }

        $rows = $qb->where(implode(' OR ', $clauses))
            ->executeQuery()
            ->fetchFirstColumn();

        return array_values(array_map(
            static fn (mixed $id): int => (int) $id,
            array_filter($rows, is_numeric(...))
        ));
    }

    /**
     * REGEXP is MySQL/MariaDB's operator name; matches
     * `include/dblayer/functions_mysqli.inc.php`'s own `DB_REGEX_OPERATOR`
     * constant, which this repository replaces the free-function caller of.
     */
    private function regexOperator(): string
    {
        return 'REGEXP';
    }

    /**
     * Matches $permalinks against both the current `categories.permalink`
     * column and the `old_permalinks` redirect table, keyed by the
     * permalink string. `is_old` distinguishes which table matched (a
     * match in `old_permalinks` needs its hit counter touched by the
     * caller via {@see touchOldPermalinkHit()}).
     *
     * @param  list<string>  $permalinks
     * @return array<string, array{id: int, permalink: string, is_old: int}>
     */
    public function findPermalinkMatches(array $permalinks): array
    {
        if ($permalinks === []) {
            return [];
        }

        $rows = $this->conn->createQueryBuilder()
            ->select('cat_id AS id', 'permalink', '1 AS is_old')
            ->from(Tables::oldPermalinks())
            ->where('permalink IN (:permalinks)')
            ->setParameter('permalinks', $permalinks, ArrayParameterType::STRING)
            ->executeQuery()
            ->fetchAllAssociative();

        $rows2 = $this->conn->createQueryBuilder()
            ->select('id', 'permalink', '0 AS is_old')
            ->from(Tables::categories())
            ->where('permalink IN (:permalinks)')
            ->setParameter('permalinks', $permalinks, ArrayParameterType::STRING)
            ->executeQuery()
            ->fetchAllAssociative();

        $byPermalink = [];
        foreach ([...$rows, ...$rows2] as $row) {
            /** @var array{id: int, permalink: string, is_old: int} $row */
            $byPermalink[$row['permalink']] = $row;
        }

        return $byPermalink;
    }

    public function touchOldPermalinkHit(string $permalink, int $catId): void
    {
        $this->conn->createQueryBuilder()
            ->update(Tables::oldPermalinks())
            ->set('last_hit', 'NOW()')
            ->set('hit', 'hit + 1')
            ->where('permalink = :permalink')
            ->andWhere('cat_id = :catId')
            ->setParameter('permalink', $permalink)
            ->setParameter('catId', $catId)
            ->setMaxResults(1)
            ->executeStatement();
    }

    public function findRandomImageId(int $catId, string $uppercats, bool $recursive, string $permissionCondition): ?int
    {
        $scope = $recursive
            ? '(c.id = :catId OR uppercats LIKE :uppercatsLike)'
            : 'c.id = :catId';

        $qb = $this->conn->createQueryBuilder()
            ->select('image_id')
            ->from(Tables::categories(), 'c')
            ->innerJoin('c', Tables::imageCategory(), 'ic', 'ic.category_id = c.id')
            ->where($scope . ' ' . $permissionCondition)
            ->orderBy('RAND()')
            ->setMaxResults(1)
            ->setParameter('catId', $catId);

        if ($recursive) {
            $qb->setParameter('uppercatsLike', $uppercats . ',%');
        }

        $value = $qb->executeQuery()
            ->fetchOne();

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * One row per category with its own direct image count/last-available
     * date -- {@see \Piwigo\Category\CategoryService::getComputedCategories()}
     * rolls these up into subtree totals.
     *
     * cat_id/id_uppercat/global_rank/rank/date_last/nb_images -- returned
     * loosely typed, same as every other row-map result in this project;
     * CategoryService narrows each field explicitly (is_numeric()/is_string()
     * checks) same as the original \Piwigo\Db\MysqliDb::fetchAssoc() loop did. `rank`
     * (sibling order within a parent, distinct from `global_rank`) is
     * carried through purely for P23 batch 4b's CategoryCatsRenderer
     * (CategoryService::compareByRank()) -- CategoryService/CategoryTreeCache
     * themselves never read it.
     *
     * @return list<array<string, mixed>>
     */
    public function findComputedCategoriesRollup(int $level, ?int $filterDays, string $forbiddenCategoriesCsv): array
    {
        // $filterDays, when given, MUST stay part of the second LEFT JOIN's
        // own ON condition, not a WHERE clause -- a WHERE would drop the
        // whole category row (via GROUP BY) for any category whose photos
        // are all older than the period, instead of keeping the row with
        // nb_images=0 the way a LEFT JOIN match failure does. The tree
        // rollup in CategoryService::getComputedCategories() depends on
        // every category still being present to compute parent counts
        // correctly before its own post-hoc pruning step runs.
        $imagesJoinCondition = 'ic.image_id = i.id AND i.level <= :level';
        if ($filterDays !== null) {
            $imagesJoinCondition .= ' AND i.date_available > ' . SqlDialect::getRecentPeriodExpression($filterDays);
        }

        $qb = $this->conn->createQueryBuilder()
            ->select(
                'c.id AS cat_id',
                'id_uppercat',
                'global_rank',
                'c.rank',
                'MAX(date_available) AS date_last',
                'COUNT(date_available) AS nb_images'
            )
            ->from(Tables::categories(), 'c')
            ->leftJoin('c', Tables::imageCategory(), 'ic', 'ic.category_id = c.id')
            ->leftJoin('ic', Tables::images(), 'i', $imagesJoinCondition)
            ->groupBy('c.id')
            ->setParameter('level', $level);

        if ($forbiddenCategoriesCsv !== '') {
            $qb->andWhere('c.id NOT IN (' . $forbiddenCategoriesCsv . ')');
        }

        return $qb->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * @param  list<int>  $catIds
     * @return list<int>
     */
    public function findImageIdsForCategories(
        array $catIds,
        string $mode,
        string $extraImagesWhereSql,
        string $orderBySql,
        string $permissionCondition
    ): array {
        if ($catIds === []) {
            return [];
        }

        $qb = $this->conn->createQueryBuilder()
            ->select('id')
            ->from(Tables::images(), 'i')
            ->innerJoin('i', Tables::imageCategory(), 'ic', 'id = ic.image_id')
            ->where('category_id IN (:catIds)')
            ->setParameter('catIds', $catIds, ArrayParameterType::INTEGER)
            ->groupBy('id');

        if ($permissionCondition !== '') {
            $qb->andWhere(trim($permissionCondition));
        }

        if ($extraImagesWhereSql !== '') {
            $qb->andWhere($extraImagesWhereSql);
        }

        if ($mode === 'AND' && count($catIds) > 1) {
            $qb->having('COUNT(DISTINCT category_id) = :catCount')
                ->setParameter('catCount', count($catIds));
        }

        if ($orderBySql !== '') {
            // $orderBySql is CurrentConfig::orderBy()'s own raw "ORDER BY
            // ..." SQL-fragment string (or a caller-supplied equivalent) --
            // every other real consumer concatenates it directly into a raw
            // SQL string, but QueryBuilder::orderBy() prepends its own
            // "ORDER BY " keyword, so the prefix must be stripped here or
            // the query becomes "ORDER BY ORDER BY ..." (a real syntax
            // error, caught live via CategoryServiceTest).
            $qb->orderBy(str_replace('ORDER BY ', '', $orderBySql));
        }

        $ids = $qb->executeQuery()
            ->fetchFirstColumn();

        return array_values(array_map(
            static fn (mixed $id): int => (int) $id,
            array_filter($ids, is_numeric(...))
        ));
    }

    /**
     * @param  list<int>  $itemIds
     * @param  list<int>  $excludedCatIds
     * @return array<int, array{id: int, uppercats: string, counter: int}> keyed by id
     */
    public function findCommonCategories(array $itemIds, ?int $max, array $excludedCatIds, string $permissionCondition): array
    {
        if ($itemIds === []) {
            return [];
        }

        $qb = $this->conn->createQueryBuilder()
            ->select('c.id', 'c.uppercats', 'COUNT(*) AS counter')
            ->from(Tables::imageCategory())
            ->innerJoin(Tables::imageCategory(), Tables::categories(), 'c', 'category_id = c.id')
            ->where('image_id IN (:itemIds)')
            ->setParameter('itemIds', $itemIds, ArrayParameterType::INTEGER)
            ->groupBy('c.id');

        if ($permissionCondition !== '') {
            $qb->andWhere(trim($permissionCondition));
        }

        if ($excludedCatIds !== []) {
            $qb->andWhere('category_id NOT IN (:excludedCatIds)')
                ->setParameter('excludedCatIds', $excludedCatIds, ArrayParameterType::INTEGER);
        }

        if ($max !== null) {
            $qb->orderBy('counter', 'DESC')
                ->setMaxResults($max);
        }

        $rows = $qb->executeQuery()
            ->fetchAllAssociative();

        $byId = [];
        foreach ($rows as $row) {
            /** @var array{id: int, uppercats: string, counter: int} $row */
            $byId[$row['id']] = $row;
        }

        return $byId;
    }

    /**
     * id/name/permalink/id_uppercat/uppercats/global_rank, loosely typed
     * same as {@see findComputedCategoriesRollup()}.
     *
     * @param  list<int>  $ids
     * @return list<array<string, mixed>>
     */
    public function findCategoriesByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return $this->conn->createQueryBuilder()
            ->select('id', 'name', 'permalink', 'id_uppercat', 'uppercats', 'global_rank')
            ->from(Tables::categories())
            ->where('id IN (:ids)')
            ->setParameter('ids', $ids, ArrayParameterType::INTEGER)
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * Every column on `categories` for the given ids -- deliberately a
     * separate method from {@see findCategoriesByIds()} (a narrower,
     * differently-shaped, already-tested 6-column contract with its own
     * real caller) rather than widening that one. P23 batch 4b's
     * CategoryCatsRenderer calls this only for the small, already-paginated
     * subset of cat_ids being displayed on one page -- never the whole
     * tree, unlike CategoryTreeCache's own cached rollup.
     *
     * @param  list<int>  $ids
     * @return list<Category>
     */
    public function findFullCategoriesByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $rows = $this->conn->createQueryBuilder()
            ->select('*')
            ->from(Tables::categories())
            ->where('id IN (:ids)')
            ->setParameter('ids', $ids, ArrayParameterType::INTEGER)
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(Category::fromRow(...), $rows);
    }

    /**
     * @return list<int>
     */
    public function findCategoryIdsBySite(int $siteId): array
    {
        return array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $this->conn->createQueryBuilder()
            ->select('id')
            ->from(Tables::categories())
            ->where('site_id = :siteId')
            ->setParameter('siteId', $siteId)
            ->executeQuery()
            ->fetchFirstColumn());
    }

    public function deleteSiteRow(int $id): void
    {
        $this->conn->createQueryBuilder()
            ->delete(Tables::sites())
            ->where('id = :id')
            ->setParameter('id', $id)
            ->executeStatement();
    }

    /**
     * @param  list<int>  $ids
     * @return list<int>
     */
    public function findStorageLinkedImageIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $this->conn->executeQuery('
SELECT id
  FROM ' . Tables::images() . '
  WHERE storage_category_id IN (
' . wordwrap(implode(', ', $ids), 80, "\n") . ')
;')->fetchFirstColumn());
    }

    /**
     * @param  list<int>  $ids
     * @return list<int>
     */
    public function findDistinctLinkedImageIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $this->conn->executeQuery('
SELECT
    DISTINCT(image_id)
  FROM ' . Tables::imageCategory() . '
  WHERE category_id IN (' . implode(',', $ids) . ')
;')->fetchFirstColumn());
    }

    /**
     * Image ids from $imageIds still linked to a category outside $excludeIds
     * -- used by delete_categories()'s "delete_orphans" mode to find images
     * that would become orphaned by dropping $excludeIds.
     *
     * @param  list<int>  $imageIds
     * @param  list<int>  $excludeIds
     * @return list<int>
     */
    public function findNonOrphanImageIds(array $imageIds, array $excludeIds): array
    {
        if ($imageIds === []) {
            return [];
        }

        return array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $this->conn->executeQuery('
SELECT
    DISTINCT(image_id)
  FROM ' . Tables::imageCategory() . '
  WHERE image_id IN (' . implode(',', $imageIds) . ')
    AND category_id NOT IN (' . implode(',', $excludeIds) . ')
;')->fetchFirstColumn());
    }

    /**
     * @param  list<int>  $ids
     */
    public function deleteImageCategoryLinksForCategories(array $ids): void
    {
        $this->conn->executeStatement('
DELETE FROM ' . Tables::imageCategory() . '
  WHERE category_id IN (
' . wordwrap(implode(', ', $ids), 80, "\n") . ')
;');
    }

    /**
     * @param  list<int>  $ids
     */
    public function deleteUserAccessForCategories(array $ids): void
    {
        $this->conn->executeStatement('
DELETE FROM ' . Tables::userAccess() . '
  WHERE cat_id IN (
' . wordwrap(implode(', ', $ids), 80, "\n") . ')
;');
    }

    /**
     * @param  list<int>  $ids
     */
    public function deleteGroupAccessForCategories(array $ids): void
    {
        $this->conn->executeStatement('
DELETE FROM ' . Tables::groupAccess() . '
  WHERE cat_id IN (
' . wordwrap(implode(', ', $ids), 80, "\n") . ')
;');
    }

    /**
     * Revokes a specific set of users' access to a specific set of
     * categories -- CategoryAdminService::setCategoryPermissions()'s own
     * "if you forbid access to an album, all sub-albums become
     * automatically forbidden too" deny path, narrower than {@see
     * deleteUserAccessForCategories()} (which drops every grant on a
     * category, not just $userIds').
     *
     * @param  array<int>  $userIds
     * @param  array<int>  $catIds
     */
    public function deleteUserAccessForUsersAndCategories(array $userIds, array $catIds): void
    {
        if ($userIds === [] || $catIds === []) {
            return;
        }

        $this->conn->executeStatement('
DELETE
  FROM ' . Tables::userAccess() . '
  WHERE user_id IN (' . implode(',', $userIds) . ')
    AND cat_id IN (' . implode(',', $catIds) . ')
;');
    }

    /**
     * Same as {@see deleteUserAccessForUsersAndCategories()}, for groups.
     *
     * @param  array<int>  $groupIds
     * @param  array<int>  $catIds
     */
    public function deleteGroupAccessForGroupsAndCategories(array $groupIds, array $catIds): void
    {
        if ($groupIds === [] || $catIds === []) {
            return;
        }

        $this->conn->executeStatement('
DELETE
  FROM ' . Tables::groupAccess() . '
  WHERE group_id IN (' . implode(',', $groupIds) . ')
    AND cat_id IN (' . implode(',', $catIds) . ')
;');
    }

    /**
     * @param  list<int>  $ids
     */
    public function deleteCategoriesByIds(array $ids): void
    {
        $this->conn->executeStatement('
DELETE FROM ' . Tables::categories() . '
  WHERE id IN (
' . wordwrap(implode(', ', $ids), 80, "\n") . ')
;');
    }

    /**
     * @param  list<int>  $ids
     */
    public function deleteOldPermalinksForCategories(array $ids): void
    {
        $this->conn->executeStatement('
DELETE FROM ' . Tables::oldPermalinks() . '
  WHERE cat_id IN (' . implode(',', $ids) . ')');
    }

    /**
     * @param  list<int>  $ids
     */
    public function deleteUserCacheCategoriesForCategories(array $ids): void
    {
        $this->conn->executeStatement('
DELETE FROM ' . Tables::userCacheCategories() . '
  WHERE cat_id IN (' . implode(',', $ids) . ')');
    }

    /**
     * $whereCatsSql is a pre-built SQL fragment from the caller (e.g. `1=1`,
     * `c.id=5`, `c.id IN (1,2,3)`) -- same "repository takes a pre-built SQL
     * fragment" shape this class already uses for permission conditions.
     *
     * @return list<int>
     */
    public function findWrongRepresentativeCategoryIds(string $whereCatsSql): array
    {
        return array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $this->conn->executeQuery('
SELECT DISTINCT c.id
  FROM ' . Tables::categories() . ' AS c LEFT JOIN ' . Tables::images() . ' AS i
    ON c.representative_picture_id = i.id
  WHERE representative_picture_id IS NOT NULL
    AND ' . $whereCatsSql . '
    AND i.id IS NULL
;')->fetchFirstColumn());
    }

    /**
     * @param  list<int>  $ids
     */
    public function clearRepresentativePictureIds(array $ids): void
    {
        $this->conn->executeStatement('
UPDATE ' . Tables::categories() . '
  SET representative_picture_id = NULL
  WHERE id IN (
' . wordwrap(implode(', ', $ids), 120, "\n") . ')
;');
    }

    /**
     * @return list<int>
     */
    public function findCategoriesNeedingRandomRepresentative(string $whereCatsSql): array
    {
        return array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $this->conn->executeQuery('
SELECT DISTINCT id
  FROM ' . Tables::categories() . ' INNER JOIN ' . Tables::imageCategory() . '
    ON id = category_id
  WHERE representative_picture_id IS NULL
    AND ' . $whereCatsSql . '
;')->fetchFirstColumn());
    }

    /**
     * @return list<string>
     */
    public function findOrphanedColumnValues(string $table, string $column): array
    {
        return array_values(array_unique(array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '', $this->conn->executeQuery('
SELECT
    ' . $column . '
  FROM ' . $table . '
    LEFT JOIN ' . Tables::categories() . ' ON id = ' . $column . '
  WHERE id IS NULL
;')->fetchFirstColumn())));
    }

    /**
     * @param  list<int|string>  $values
     */
    public function deleteRowsWhereColumnIn(string $table, string $column, array $values): void
    {
        $this->conn->executeStatement('
DELETE
  FROM ' . $table . '
  WHERE ' . $column . ' IN (' . implode(',', $values) . ')
;');
    }

    /**
     * id/id_uppercat/uppercats/rank/global_rank for every category, ordered
     * for {@see \Piwigo\Category\CategoryService::updateGlobalRank()}'s own
     * per-parent rank-numbering pass.
     *
     * @return list<array<string, mixed>>
     */
    public function findCategoriesForRankUpdate(): array
    {
        return $this->conn->executeQuery('
SELECT id, id_uppercat, uppercats, `rank`, global_rank
  FROM ' . Tables::categories() . '
  ORDER BY id_uppercat, `rank`, name
;')->fetchAllAssociative();
    }

    /**
     * @param  array<int>  $ids  real callers (getUppercatIds()/getSubcatIds()'s
     *   own array_unique()/array_merge() results) don't guarantee a list
     */
    public function updateCategoryVisibility(array $ids, bool $visible): void
    {
        // Bound as int, not bool: a plain executeStatement() param array
        // (no explicit Types::) can't always tell mysqli how to bind a raw
        // PHP bool -- found live, it silently became the empty string ''
        // instead of 0/1, which strict SQL mode then rejects outright
        // ("Incorrect integer value: '' for column"). An int has one
        // unambiguous wire representation on every platform.
        $this->conn->executeStatement('
UPDATE ' . Tables::categories() . '
  SET visible = ?
  WHERE id IN (' . implode(',', $ids) . ')', [(int) $visible]);
    }

    /**
     * @param  array<int>  $ids  same non-list caveat as {@see updateCategoryVisibility()}
     */
    public function updateCategoryCommentable(array $ids, bool $commentable): void
    {
        $this->conn->executeStatement('
UPDATE ' . Tables::categories() . '
  SET commentable = ?
  WHERE id IN (' . implode(',', $ids) . ')', [(int) $commentable]);
    }

    /**
     * @param  array<int>  $ids  same non-list caveat as {@see updateCategoryVisibility()}
     */
    public function updateCategoryStatus(array $ids, string $status): void
    {
        $this->conn->executeStatement('
UPDATE ' . Tables::categories() . '
  SET status = \'' . $status . '\'
  WHERE id IN (' . implode(',', $ids) . ')
;');
    }

    /**
     * CategoryAdminService::saveImageOrder()'s own category (admin/
     * element_set_ranks.php).
     */
    public function updateImageOrder(int $catId, ?string $imageOrder): void
    {
        $this->conn->createQueryBuilder()
            ->update(Tables::categories())
            ->set('image_order', ':imageOrder')
            ->where('id = :id')
            ->setParameter('imageOrder', $imageOrder)
            ->setParameter('id', $catId)
            ->executeStatement();
    }

    /**
     * Same as {@see updateImageOrder()}, applied to every descendant of
     * $uppercatsPrefix (a category's own `uppercats` value + ',') when
     * saveImageOrder()'s $applySubcats is true.
     */
    public function updateImageOrderForDescendants(string $uppercatsPrefix, ?string $imageOrder): void
    {
        $this->conn->createQueryBuilder()
            ->update(Tables::categories())
            ->set('image_order', ':imageOrder')
            ->where('uppercats LIKE :uppercatsPrefix')
            ->setParameter('imageOrder', $imageOrder)
            ->setParameter('uppercatsPrefix', $uppercatsPrefix . '%')
            ->executeStatement();
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, array{id: int, status: string}> keyed by id
     */
    public function findStatusByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $rows = $this->conn->executeQuery('
SELECT
    id,
    status
  FROM ' . Tables::categories() . '
  WHERE id IN (' . implode(',', $ids) . ')
;')->fetchAllAssociative();

        $byId = [];
        foreach ($rows as $row) {
            /** @var array{id: int, status: string} $row */
            $byId[$row['id']] = $row;
        }

        return $byId;
    }

    /**
     * @return list<int>
     */
    public function findAccessUserIds(int $catId): array
    {
        return array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $this->conn->executeQuery('
SELECT user_id
  FROM ' . Tables::userAccess() . '
  WHERE cat_id = ' . $catId . '
;')->fetchFirstColumn());
    }

    /**
     * @return list<int>
     */
    public function findAccessGroupIds(int $catId): array
    {
        return array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $this->conn->executeQuery('
SELECT group_id
  FROM ' . Tables::groupAccess() . '
  WHERE cat_id = ' . $catId . '
;')->fetchFirstColumn());
    }

    /**
     * @param  list<int>  $keepIds  a non-empty list is guaranteed by the
     *   caller (-1 is substituted when no reference access exists, matching
     *   the original's own `$ref_access[] = -1;` sentinel)
     * @param  list<int>  $catIds
     */
    public function deleteInconsistentAccess(string $table, string $field, array $keepIds, array $catIds): void
    {
        $this->conn->executeStatement('
DELETE
  FROM ' . $table . '
  WHERE ' . $field . ' NOT IN (' . implode(',', $keepIds) . ')
    AND cat_id IN (' . implode(',', $catIds) . ')
;');
    }

    /**
     * @param  array<int>  $ids  real callers don't guarantee a list
     * @return list<string>
     */
    public function findUppercatsColumns(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '', $this->conn->executeQuery('
SELECT uppercats
  FROM ' . Tables::categories() . '
  WHERE id IN (' . implode(',', $ids) . ')
;')->fetchFirstColumn());
    }

    /**
     * Same rows as {@see findUppercatsColumns()}, keyed by id instead of a
     * plain list -- CategoryAdminService::getCategoriesRefDate() needs to
     * look a specific category's uppercats back up by id while iterating.
     *
     * @param  list<int>  $ids
     * @return array<int, string> keyed by id
     */
    public function findUppercatsById(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $rows = $this->conn->executeQuery('
SELECT id, uppercats
  FROM ' . Tables::categories() . '
  WHERE id IN (' . implode(',', $ids) . ')
;')->fetchAllKeyValue();

        $byId = [];
        foreach ($rows as $id => $uppercats) {
            if (is_numeric($id)) {
                $byId[(int) $id] = is_scalar($uppercats) ? (string) $uppercats : '';
            }
        }

        return $byId;
    }

    /**
     * Per-category MIN/MAX of a numeric/date `images` column, for every
     * image directly in each of $categoryIds -- CategoryAdminService::
     * getCategoriesRefDate()'s own per-category aggregate, before it rolls
     * sub-categories' values up into their ancestors' own entry.
     *
     * $field/$minmax are never raw user input -- getCategoriesRefDate()'s
     * one real caller (AlbumsPageRenderer) only reaches them after
     * validating $_POST['order'] against a fixed whitelist.
     *
     * @param  list<int>  $categoryIds
     * @return array<int, mixed> keyed by category_id
     */
    public function findRefDatesByCategoryIds(array $categoryIds, string $field, string $minmax): array
    {
        if ($categoryIds === []) {
            return [];
        }

        $rows = $this->conn->executeQuery('
SELECT
    category_id,
    ' . $minmax . '(' . $field . ') as ref_date
  FROM ' . Tables::imageCategory() . '
    JOIN ' . Tables::images() . ' ON image_id = id
  WHERE category_id IN (' . implode(',', $categoryIds) . ')
  GROUP BY category_id
;')->fetchAllKeyValue();

        $byCategoryId = [];
        foreach ($rows as $categoryId => $refDate) {
            if (is_numeric($categoryId)) {
                $byCategoryId[(int) $categoryId] = $refDate;
            }
        }

        return $byCategoryId;
    }

    /**
     * Flattens every category's `uppercats` CSV column into a single
     * deduplicated list of uppercat ids. Shared by `CategoryService::
     * getUppercatIds()` and `PermissionService::addPermissionOnCategory()`
     * -- the latter can't depend on `CategoryService` (which already
     * constructor-injects `PermissionService`, a real circular-construction
     * risk), so this lives at the repository layer both can reach.
     *
     * @param array<int> $catIds
     * @return array<int>
     */
    public function findUppercatIds(array $catIds): array
    {
        if (count($catIds) < 1) {
            return [];
        }

        $uppercats = [];
        foreach ($this->findUppercatsColumns(array_map(intval(...), $catIds)) as $uppercatsCsv) {
            $uppercats = array_merge(
                $uppercats,
                array_map(intval(...), explode(',', $uppercatsCsv))
            );
        }

        return array_unique($uppercats);
    }

    public function findRandomImageIdInCategory(int $categoryId): ?int
    {
        $value = $this->conn->executeQuery('
SELECT image_id
  FROM ' . Tables::imageCategory() . '
  WHERE category_id = ' . $categoryId . '
  ORDER BY ' . $this->randomFunction() . '()
  LIMIT 1
;')->fetchOne();

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * `DB_RANDOM_FUNCTION`'s replacement -- MySQL/MariaDB's random-row
     * ordering function, matching {@see findRandomImageId()}'s own `RAND()`.
     */
    private function randomFunction(): string
    {
        return 'RAND';
    }

    /**
     * @return array<int|string, string> id => dir, filtered to non-null (dir
     *   is nullable in the schema; only categories with a real directory can
     *   contribute a fulldir segment) -- id keys are numeric strings (PHP
     *   coerces them to int automatically when used as real array keys, but
     *   fetchAllKeyValue()'s own generic type doesn't track that statically)
     */
    public function findCategoryDirsById(): array
    {
        return array_filter($this->conn->executeQuery('
SELECT id, dir
  FROM ' . Tables::categories() . '
  WHERE dir IS NOT NULL
;')->fetchAllKeyValue(), is_string(...));
    }

    /**
     * @return array<int|string, string> id => galleries_url, same
     *   numeric-string key caveat as {@see findCategoryDirsById()}
     */
    public function findSiteGalleriesUrls(): array
    {
        return array_filter($this->conn->executeQuery('
SELECT id, galleries_url
  FROM ' . Tables::sites() . '
;')->fetchAllKeyValue(), is_string(...));
    }

    /**
     * @param  array<int>  $ids  real callers don't guarantee a list
     * @return list<array<string, mixed>>
     */
    public function findCategoriesForFulldirs(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return $this->conn->executeQuery('
SELECT id, uppercats, site_id
  FROM ' . Tables::categories() . '
  WHERE dir IS NOT NULL
    AND id IN (
' . wordwrap(implode(', ', $ids), 80, "\n") . ')
;')->fetchAllAssociative();
    }

    /**
     * @return list<int>
     */
    public function findDistinctStorageCategoryIds(): array
    {
        return array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $this->conn->executeQuery('
SELECT DISTINCT(storage_category_id)
  FROM ' . Tables::images() . '
  WHERE storage_category_id IS NOT NULL
;')->fetchFirstColumn());
    }

    public function updateImagePathsForCategory(int $categoryId, string $fulldir): void
    {
        $this->conn->executeStatement('
UPDATE ' . Tables::images() . '
  SET path = ' . SqlDialect::concat(["'" . $fulldir . "/'", 'file']) . '
  WHERE storage_category_id = ' . $categoryId . '
;');
    }

    /**
     * @param  array<int>  $ids  real callers don't guarantee a list
     * @return list<array<string, mixed>>
     */
    public function findCategoriesForMove(array $ids): array
    {
        return $this->conn->executeQuery('
SELECT id, id_uppercat, status, uppercats
  FROM ' . Tables::categories() . '
  WHERE id IN (' . implode(',', $ids) . ')
;')->fetchAllAssociative();
    }

    public function findCategoryUppercatsById(int $id): ?string
    {
        $value = $this->conn->executeQuery('
SELECT uppercats
  FROM ' . Tables::categories() . '
  WHERE id = ' . $id . '
;')->fetchOne();

        return is_string($value) ? $value : null;
    }

    /**
     * $newParent is either a numeric category id or the literal string
     * `'NULL'` (root) -- matches the original's own `$new_parent < 1 ?
     * 'NULL' : $new_parent` substitution, embedded directly into the SQL
     * (not bindable as a parameter since it must appear unquoted as either
     * a number or the SQL keyword).
     *
     * @param  array<int>  $ids  real callers don't guarantee a list
     */
    public function updateCategoryParent(array $ids, string $newParent): void
    {
        $this->conn->executeStatement('
UPDATE ' . Tables::categories() . '
  SET id_uppercat = ' . $newParent . '
  WHERE id IN (' . implode(',', $ids) . ')
;');
    }

    public function findCategoryStatus(int $id): ?string
    {
        $value = $this->conn->executeQuery('
SELECT status
  FROM ' . Tables::categories() . '
  WHERE id = ' . $id . '
;')->fetchOne();

        return is_string($value) ? $value : null;
    }

    public function findMaxRankForParent(int|string|null $parentId): ?int
    {
        // Matches the original's own empty($parent_id) semantics (null, 0,
        // '0', and '' all mean "no parent" / root level).
        $parentIsEmpty = $parentId === null || $parentId === 0 || $parentId === '0' || $parentId === '';

        $value = $this->conn->executeQuery('
SELECT MAX(`rank`) AS max_rank
  FROM ' . Tables::categories() . '
  WHERE id_uppercat ' . ($parentIsEmpty ? 'IS NULL' : '= ' . (string) $parentId) . '
;')->fetchOne();

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * @return array{id: int, uppercats: string, global_rank: string, visible: int, status: string}|null
     */
    public function findParentCategoryForCreate(int|string $parentId): ?array
    {
        $row = $this->conn->executeQuery('
SELECT id, uppercats, global_rank, visible, status
  FROM ' . Tables::categories() . '
  WHERE id = ' . $parentId . '
;')->fetchAssociative();

        /** @var array{id: int, uppercats: string, global_rank: string, visible: int, status: string}|false $row */
        return $row === false ? null : $row;
    }

    /**
     * Executes an already-built SELECT query verbatim and returns every row.
     * Transitional -- `CategoryService::displaySelectCatWrapper()`'s own
     * callers (`Admin/UserPermPageRenderer.php`, `Admin/GroupPermPageRenderer.php`,
     * `Admin/CatOptionsPageRenderer.php`, `Controller/CommentsController.php`,
     * `Controller/Admin/PermalinksSubController.php`,
     * `Controller/Admin/SiteUpdateSubController.php`) build the raw SQL
     * string themselves instead of this repository, so there's no single
     * query shape to give a real `find*()` method -- retiring `MysqliDb::`
     * here only swaps the execution mechanism (Legacy Coupling Retirement:
     * DI+DBAL migration Phase 1a). Revisit once those 6 caller files get
     * their own pass (Phase 1g/1h) and can build a real `QueryBuilder`
     * instead of a raw string.
     *
     * @return list<array<string, mixed>>
     */
    public function fetchCallerBuiltQuery(string $query): array
    {
        return $this->conn->executeQuery($query)
            ->fetchAllAssociative();
    }

    /**
     * @param array<int, array{id: mixed, rank: mixed}> $datas
     */
    public function massUpdateRanks(array $datas): void
    {
        $this->batchWriter()
            ->massUpdate(
                Tables::categories(),
                [
                    'primary' => ['id'],
                    'update' => ['rank'],
                ],
                $datas
            );
    }

    /**
     * @param array<int, array{id: mixed, rank: mixed, global_rank: mixed}> $datas
     */
    public function massUpdateRanksAndGlobalRank(array $datas): void
    {
        $this->batchWriter()
            ->massUpdate(
                Tables::categories(),
                [
                    'primary' => ['id'],
                    'update' => ['rank', 'global_rank'],
                ],
                $datas
            );
    }

    /**
     * @param array<int, array{id: mixed, representative_picture_id: mixed}> $datas
     */
    public function massUpdateRepresentativePictures(array $datas): void
    {
        $this->batchWriter()
            ->massUpdate(
                Tables::categories(),
                [
                    'primary' => ['id'],
                    'update' => ['representative_picture_id'],
                ],
                $datas
            );
    }

    /**
     * @param array<int, array{id: mixed, uppercats: mixed}> $datas
     */
    public function massUpdateUppercats(array $datas): void
    {
        $this->batchWriter()
            ->massUpdate(
                Tables::categories(),
                [
                    'primary' => ['id'],
                    'update' => ['uppercats'],
                ],
                $datas
            );
    }

    /**
     * Inserts a new category row and returns its auto-generated id.
     *
     * @param array<string, mixed> $insert
     */
    public function insertCategory(array $insert): int|string
    {
        $this->batchWriter()
            ->singleInsert(Tables::categories(), $insert);

        return $this->conn->lastInsertId();
    }

    /**
     * @param array<string, mixed> $data
     */
    public function updateCategoryAfterInsert(int|string $id, array $data): void
    {
        $this->batchWriter()
            ->singleUpdate(Tables::categories(), $data, [
                'id' => $id,
            ]);
    }

    /**
     * $ignore matters for CategoryAdminService::setCategoryPermissions()'s
     * own caller: re-granting a group that already has access to one of
     * the target categories (editing permissions on an existing category,
     * not creating a brand new one) would otherwise hit group_access's own
     * unique constraint. createVirtualCategory()'s inheritance-on-creation
     * caller doesn't need it -- a freshly INSERTed category id can't
     * already have any group_access rows -- so the default is false.
     *
     * @param array<int, array{group_id: mixed, cat_id: mixed}> $inserts
     */
    public function massInsertGroupAccess(array $inserts, bool $ignore = false): void
    {
        $this->batchWriter()
            ->massInsert(Tables::groupAccess(), ['group_id', 'cat_id'], $inserts, [
                'ignore' => $ignore,
            ]);
    }

    /**
     * Picks a random representative image among a category's sub-categories
     * (`CategoryCatsRenderer`'s own fallback when a category has no direct
     * representative but does have sub-albums with images). $permissionCondition
     * is an already-built SQL fragment (leading "\n  AND"), same
     * pre-built-permission-string shape as every other repository method
     * here.
     */
    public function findRandomRepresentativeIdAmongSubcategories(string $uppercats, int $userId, string $permissionCondition): ?string
    {
        $value = $this->conn->executeQuery('
SELECT representative_picture_id
  FROM ' . Tables::categories() . ' INNER JOIN ' . Tables::userCacheCategories() . '
  ON id = cat_id and user_id = :userId
  WHERE uppercats LIKE :uppercatsLike
    AND representative_picture_id IS NOT NULL'
  . $permissionCondition . '
  ORDER BY ' . SqlDialect::DB_RANDOM_FUNCTION . '()
  LIMIT 1
;', [
      'userId' => $userId,
      'uppercatsLike' => $uppercats . ',%',
  ])->fetchOne();

        return is_scalar($value) ? (string) $value : null;
    }

    /**
     * First/last photo creation date per category (`CategoryCatsRenderer`'s
     * "from/to" date-range display, gated by `CurrentConfig::displayFromto()`).
     * $permissionCondition is an already-built SQL fragment, same shape as
     * {@see findRandomRepresentativeIdAmongSubcategories()}.
     *
     * @param  list<int>  $categoryIds
     * @return array<string, array{from: ?string, to: ?string}> keyed by category id
     */
    public function findDateRangeByCategory(array $categoryIds, string $permissionCondition): array
    {
        if ($categoryIds === []) {
            return [];
        }

        $rows = $this->conn->executeQuery('
SELECT
    category_id,
    MIN(date_creation) AS `from`,
    MAX(date_creation) AS `to`
  FROM ' . Tables::imageCategory() . '
    INNER JOIN ' . Tables::images() . ' ON image_id = id
  WHERE category_id IN (:categoryIds)
' . $permissionCondition . '
  GROUP BY category_id
;', [
            'categoryIds' => $categoryIds,
        ], [
            'categoryIds' => ArrayParameterType::INTEGER,
        ])->fetchAllAssociative();

        $byId = [];
        foreach ($rows as $row) {
            $categoryId = $row['category_id'] ?? null;
            if (is_scalar($categoryId)) {
                $byId[(string) $categoryId] = [
                    'from' => isset($row['from']) && is_scalar($row['from']) ? (string) $row['from'] : null,
                    'to' => isset($row['to']) && is_scalar($row['to']) ? (string) $row['to'] : null,
                ];
            }
        }

        return $byId;
    }
}
