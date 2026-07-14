<?php

declare(strict_types=1);

namespace Piwigo\Category;

use Doctrine\DBAL\ArrayParameterType;
use Piwigo\Db\AbstractRepository;
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
    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $row = $this->conn->createQueryBuilder()
            ->select('*')
            ->from(Tables::categories())
            ->where('id = :id')
            ->setParameter('id', $id)
            ->executeQuery()
            ->fetchAssociative();

        return $row === false ? null : $row;
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
     * checks) same as the original pwg_db_fetch_assoc() loop did. `rank`
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
            $imagesJoinCondition .= ' AND i.date_available > ' . pwg_db_get_recent_period_expression($filterDays);
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
            $qb->orderBy($orderBySql);
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
     * @return list<array<string, mixed>>
     */
    public function findFullCategoriesByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return $this->conn->createQueryBuilder()
            ->select('*')
            ->from(Tables::categories())
            ->where('id IN (:ids)')
            ->setParameter('ids', $ids, ArrayParameterType::INTEGER)
            ->executeQuery()
            ->fetchAllAssociative();
    }
}
