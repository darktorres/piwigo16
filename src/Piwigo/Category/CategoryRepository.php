<?php

declare(strict_types=1);

namespace Piwigo\Category;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityRepository;
use Piwigo\Category\Projection\Category;
use Piwigo\Common\Dto\PaginatedResult;
use Piwigo\Common\ValueObject\CategoryId;
use Piwigo\Common\ValueObject\GroupId;
use Piwigo\Db\BatchWriter;
use Piwigo\Db\SqlDialect;
use Piwigo\Db\Tables;
use Piwigo\Group\GroupAccessEntity;

/**
 * Persistence layer for the category domain: tree/menu queries, permalink
 * resolution, and the computed-categories rollup ({@see
 * findComputedCategoriesRollup()}). Permission filtering (forbidden/visible
 * categories and images) is passed in as an already-built SQL fragment by
 * the caller (CategoryService, via PermissionService::getSqlConditionFandF())
 * rather than constructed here -- same "repository takes a pre-built
 * permission condition string" shape as RateRepository/CommentRepository.
 *
 * Owns `categories` ({@see CategoryEntity}) and shares `user_access`
 * ({@see UserAccessEntity})/`group_access` ({@see \Piwigo\Group\GroupAccessEntity},
 * created during the Group batch) with Group/Permission -- only the
 * single-row/simple-id-list methods against those three tables go
 * through DQL; the large majority of this repository's 65 methods are
 * dynamic-fragment (caller-built permission/ORDER BY SQL), dynamically
 * table/column-named (findOrphanedColumnValues/deleteRowsWhereColumnIn/
 * deleteInconsistentAccess), or cross-domain joins/reads, and stay plain
 * DBAL via $this->getEntityManager()->getConnection() -- same "mixed
 * repository" shape Image/Tag's own conversions established.
 * `image_category` is never entity-mapped anywhere in this migration
 * (see CategoryEntity's own docblock).
 *
 * @extends EntityRepository<CategoryEntity>
 */
final class CategoryRepository extends EntityRepository
{
    public function findById(int $id): ?Category
    {
        $entity = $this->find($id);

        return $entity === null ? null : Category::fromEntity($entity);
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

        $rows = $this->getEntityManager()
            ->getConnection()
            ->createQueryBuilder()
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
        $rows = $this->getEntityManager()
            ->getConnection()
            ->createQueryBuilder()
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
     * A single category's id/name/permalink, or null if it doesn't exist --
     * Ws\PwgImages::add()'s own "resolve the just-associated category, for
     * the response URL" lookup. Unlike findAllIdNamePermalink() above
     * (every row, cache warm-up), this is a single-id lookup.
     *
     * @return ?array{id: int, name: string, permalink: ?string}
     */
    public function findIdNamePermalinkById(int $id): ?array
    {
        $row = $this->getEntityManager()
            ->getConnection()
            ->createQueryBuilder()
            ->select('id', 'name', 'permalink')
            ->from(Tables::categories())
            ->where('id = :id')
            ->setParameter('id', $id)
            ->executeQuery()
            ->fetchAssociative();

        if ($row === false) {
            return null;
        }

        return [
            'id' => is_numeric($row['id']) ? (int) $row['id'] : 0,
            'name' => is_string($row['name']) ? $row['name'] : '',
            'permalink' => is_string($row['permalink'] ?? null) ? $row['permalink'] : null,
        ];
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

        $qb = $this->getEntityManager()
            ->getConnection()
            ->createQueryBuilder()
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

        $conn = $this->getEntityManager()
            ->getConnection();

        $rows = $conn->createQueryBuilder()
            ->select('cat_id AS id', 'permalink', '1 AS is_old')
            ->from(Tables::oldPermalinks())
            ->where('permalink IN (:permalinks)')
            ->setParameter('permalinks', $permalinks, ArrayParameterType::STRING)
            ->executeQuery()
            ->fetchAllAssociative();

        $rows2 = $conn->createQueryBuilder()
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

    /**
     * `hit = hit + 1` is a self-referential SQL fragment a mapped entity
     * property write can't express -- stays raw DBAL, same reasoning as
     * Image\ImageRepository::incrementVisitCounter(). `old_permalinks`
     * is never entity-mapped in this migration (this is its only write
     * method; every other touch is a read), so there's no identity map
     * to clear here.
     */
    public function touchOldPermalinkHit(string $permalink, int $catId): void
    {
        $this->getEntityManager()
            ->getConnection()
            ->createQueryBuilder()
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

        $qb = $this->getEntityManager()
            ->getConnection()
            ->createQueryBuilder()
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
     * cat_id/id_uppercat/global_rank/rank/date_last/nb_images -- narrowed
     * below the same way {@see \Piwigo\Category\Projection\Category::fromRow()}
     * narrows a full category row, so CategoryService::getComputedCategories()
     * can consume the result directly instead of re-deriving each field's
     * type itself. `rank` (sibling order within a parent, distinct from
     * `global_rank`) is carried through purely for P23 batch 4b's
     * CategoryCatsRenderer (CategoryService::compareByRank()) --
     * CategoryService/CategoryTreeCache themselves never read it.
     *
     * @return list<array{cat_id: int, id_uppercat: ?int, global_rank: ?string, rank: ?int, date_last: ?string, nb_images: int}>
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

        $qb = $this->getEntityManager()
            ->getConnection()
            ->createQueryBuilder()
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

        return array_map(
            static fn (array $row): array => [
                'cat_id' => is_numeric($row['cat_id']) ? (int) $row['cat_id'] : 0,
                'id_uppercat' => is_numeric($row['id_uppercat'] ?? null) ? (int) $row['id_uppercat'] : null,
                'global_rank' => is_string($row['global_rank'] ?? null) ? $row['global_rank'] : null,
                'rank' => is_numeric($row['rank'] ?? null) ? (int) $row['rank'] : null,
                'date_last' => is_string($row['date_last'] ?? null) ? $row['date_last'] : null,
                'nb_images' => is_numeric($row['nb_images']) ? (int) $row['nb_images'] : 0,
            ],
            $qb->executeQuery()
                ->fetchAllAssociative()
        );
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

        $qb = $this->getEntityManager()
            ->getConnection()
            ->createQueryBuilder()
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

        $qb = $this->getEntityManager()
            ->getConnection()
            ->createQueryBuilder()
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
     * id/name/permalink/id_uppercat/uppercats/global_rank -- a deliberately
     * narrower 6-column contract than the full Category Projection, see
     * findFullCategoriesByIds()'s own docblock.
     *
     * @param  list<int>  $ids
     * @return list<array{id: int, name: string, permalink: ?string, id_uppercat: ?int, uppercats: string, global_rank: ?string}>
     */
    public function findCategoriesByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $rows = $this->getEntityManager()
            ->getConnection()
            ->createQueryBuilder()
            ->select('id', 'name', 'permalink', 'id_uppercat', 'uppercats', 'global_rank')
            ->from(Tables::categories())
            ->where('id IN (:ids)')
            ->setParameter('ids', $ids, ArrayParameterType::INTEGER)
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(
            static fn (array $row): array => [
                'id' => is_numeric($row['id']) ? (int) $row['id'] : 0,
                'name' => is_string($row['name'] ?? null) ? $row['name'] : '',
                'permalink' => is_string($row['permalink'] ?? null) ? $row['permalink'] : null,
                'id_uppercat' => is_numeric($row['id_uppercat'] ?? null) ? (int) $row['id_uppercat'] : null,
                'uppercats' => is_string($row['uppercats'] ?? null) ? $row['uppercats'] : '',
                'global_rank' => is_string($row['global_rank'] ?? null) ? $row['global_rank'] : null,
            ],
            $rows
        );
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

        $rows = $this->getEntityManager()
            ->getConnection()
            ->createQueryBuilder()
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
        return array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $this->getEntityManager()->getConnection()->createQueryBuilder()
            ->select('id')
            ->from(Tables::categories())
            ->where('site_id = :siteId')
            ->setParameter('siteId', $siteId)
            ->executeQuery()
            ->fetchFirstColumn());
    }

    public function deleteSiteRow(int $id): void
    {
        $this->getEntityManager()
            ->getConnection()
            ->createQueryBuilder()
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

        return array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $this->getEntityManager()->getConnection()->executeQuery('
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

        return array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $this->getEntityManager()->getConnection()->executeQuery('
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

        return array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $this->getEntityManager()->getConnection()->executeQuery('
SELECT
    DISTINCT(image_id)
  FROM ' . Tables::imageCategory() . '
  WHERE image_id IN (' . implode(',', $imageIds) . ')
    AND category_id NOT IN (' . implode(',', $excludeIds) . ')
;')->fetchFirstColumn());
    }

    /**
     * image_id for every link outside $excludeIds, NOT deduplicated
     * (matches Ws\PwgCategories::calculateOrphans()'s own large-category
     * fallback path, which dedupes in PHP after intersecting against the
     * recursive image id set) -- a different contract from
     * {@see findNonOrphanImageIds()} above (that one is DISTINCT and
     * pre-filtered to a specific image id set; this one returns every
     * matching row so the caller can avoid sending a huge `image_id IN
     * (...)` list when the recursive set is large).
     *
     * @param  list<int>  $excludeIds
     * @return list<int>
     */
    public function findImageIdsOutsideCategories(array $excludeIds): array
    {
        return array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $this->getEntityManager()->getConnection()->executeQuery('
SELECT
    image_id
  FROM
    ' . Tables::imageCategory() . '
  WHERE
    category_id
  NOT IN
    (' . implode(',', $excludeIds) . ')
;')->fetchFirstColumn());
    }

    /**
     * @param  list<int>  $ids
     */
    public function deleteImageCategoryLinksForCategories(array $ids): void
    {
        $this->getEntityManager()
            ->getConnection()
            ->executeStatement('
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
        if ($ids === []) {
            return;
        }

        $em = $this->getEntityManager();
        $em->createQueryBuilder()
            ->delete(UserAccessEntity::class, 'ua')
            ->where('ua.catId IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->execute();
        $em->clear();
    }

    /**
     * @param  list<int>  $ids
     */
    public function deleteGroupAccessForCategories(array $ids): void
    {
        if ($ids === []) {
            return;
        }

        // GroupAccessEntity's catId column is a custom-typed Piwigo\Db\Type\CategoryIdType
        // field -- wrapping through CategoryId::from() validates every id
        // reaching this shared entity, and the array bind unwraps back to
        // raw ints with an explicit ArrayParameterType::INTEGER (Doctrine's
        // IN-clause array binding doesn't route through a field's custom
        // Type reliably, verified against the installed doctrine/orm source).
        $catIds = array_map(CategoryId::from(...), $ids);

        $em = $this->getEntityManager();
        $em->createQueryBuilder()
            ->delete(GroupAccessEntity::class, 'ga')
            ->where('ga.catId IN (:ids)')
            ->setParameter('ids', array_map(static fn (CategoryId $c): int => $c->value, $catIds), ArrayParameterType::INTEGER)
            ->getQuery()
            ->execute();
        $em->clear();
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

        $em = $this->getEntityManager();
        $em->createQueryBuilder()
            ->delete(UserAccessEntity::class, 'ua')
            ->where('ua.userId IN (:userIds)')
            ->andWhere('ua.catId IN (:catIds)')
            ->setParameter('userIds', $userIds)
            ->setParameter('catIds', $catIds)
            ->getQuery()
            ->execute();
        $em->clear();
    }

    /**
     * Same as {@see deleteUserAccessForUsersAndCategories()}, for groups.
     * See {@see deleteGroupAccessForCategories()}'s own comment on why both
     * arrays wrap through the VO before binding.
     *
     * @param  array<int>  $groupIds
     * @param  array<int>  $catIds
     */
    public function deleteGroupAccessForGroupsAndCategories(array $groupIds, array $catIds): void
    {
        if ($groupIds === [] || $catIds === []) {
            return;
        }

        $em = $this->getEntityManager();
        $em->createQueryBuilder()
            ->delete(GroupAccessEntity::class, 'ga')
            ->where('ga.groupId IN (:groupIds)')
            ->andWhere('ga.catId IN (:catIds)')
            ->setParameter('groupIds', array_map(static fn (int $id): int => GroupId::from($id)->value, $groupIds), ArrayParameterType::INTEGER)
            ->setParameter('catIds', array_map(static fn (int $id): int => CategoryId::from($id)->value, $catIds), ArrayParameterType::INTEGER)
            ->getQuery()
            ->execute();
        $em->clear();
    }

    /**
     * @param  list<int>  $ids
     */
    public function deleteCategoriesByIds(array $ids): void
    {
        if ($ids === []) {
            return;
        }

        $em = $this->getEntityManager();
        $em->createQueryBuilder()
            ->delete(CategoryEntity::class, 'c')
            ->where('c.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->execute();
        $em->clear();
    }

    /**
     * @param  list<int>  $ids
     */
    public function deleteOldPermalinksForCategories(array $ids): void
    {
        if ($ids === []) {
            return;
        }

        $this->getEntityManager()
            ->getConnection()
            ->executeStatement('
DELETE FROM ' . Tables::oldPermalinks() . '
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
        return array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $this->getEntityManager()->getConnection()->executeQuery('
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
        if ($ids === []) {
            return;
        }

        $em = $this->getEntityManager();
        $em->createQueryBuilder()
            ->update(CategoryEntity::class, 'c')
            ->set('c.representativePictureId', ':null')
            ->where('c.id IN (:ids)')
            ->setParameter('null', null)
            ->setParameter('ids', $ids)
            ->getQuery()
            ->execute();
        $em->clear();
    }

    /**
     * @return list<int>
     */
    public function findCategoriesNeedingRandomRepresentative(string $whereCatsSql): array
    {
        return array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $this->getEntityManager()->getConnection()->executeQuery('
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
        return array_values(array_unique(array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '', $this->getEntityManager()->getConnection()->executeQuery('
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
        $this->getEntityManager()
            ->getConnection()
            ->executeStatement('
DELETE
  FROM ' . $table . '
  WHERE ' . $column . ' IN (' . implode(',', $values) . ')
;');
    }

    /**
     * id/id_uppercat/uppercats/rank/global_rank for every category, ordered
     * for {@see \Piwigo\Category\CategoryService::updateGlobalRank()}'s own
     * per-parent rank-numbering pass. `rank` (sibling order within a
     * parent, distinct from `global_rank`) is carried through purely for
     * that method's own rank-change detection.
     *
     * @return list<array{id: int, id_uppercat: ?int, uppercats: string, rank: ?int, global_rank: ?string}>
     */
    public function findCategoriesForRankUpdate(): array
    {
        $rows = $this->getEntityManager()
            ->getConnection()
            ->executeQuery('
SELECT id, id_uppercat, uppercats, `rank`, global_rank
  FROM ' . Tables::categories() . '
  ORDER BY id_uppercat, `rank`, name
;')->fetchAllAssociative();

        return array_map(
            static fn (array $row): array => [
                'id' => is_numeric($row['id']) ? (int) $row['id'] : 0,
                'id_uppercat' => is_numeric($row['id_uppercat'] ?? null) ? (int) $row['id_uppercat'] : null,
                'uppercats' => is_string($row['uppercats']) ? $row['uppercats'] : '',
                'rank' => is_numeric($row['rank'] ?? null) ? (int) $row['rank'] : null,
                'global_rank' => is_string($row['global_rank'] ?? null) ? $row['global_rank'] : null,
            ],
            $rows
        );
    }

    /**
     * @param  array<int>  $ids  real callers (getUppercatIds()/getSubcatIds()'s
     *   own array_unique()/array_merge() results) don't guarantee a list
     */
    public function updateCategoryVisibility(array $ids, bool $visible): void
    {
        if ($ids === []) {
            return;
        }

        $em = $this->getEntityManager();
        $em->createQueryBuilder()
            ->update(CategoryEntity::class, 'c')
            ->set('c.visible', ':visible')
            ->where('c.id IN (:ids)')
            ->setParameter('visible', $visible)
            ->setParameter('ids', array_values($ids))
            ->getQuery()
            ->execute();
        $em->clear();
    }

    /**
     * @param  array<int>  $ids  same non-list caveat as {@see updateCategoryVisibility()}
     */
    public function updateCategoryCommentable(array $ids, bool $commentable): void
    {
        if ($ids === []) {
            return;
        }

        $em = $this->getEntityManager();
        $em->createQueryBuilder()
            ->update(CategoryEntity::class, 'c')
            ->set('c.commentable', ':commentable')
            ->where('c.id IN (:ids)')
            ->setParameter('commentable', $commentable)
            ->setParameter('ids', array_values($ids))
            ->getQuery()
            ->execute();
        $em->clear();
    }

    /**
     * @param  array<int>  $ids  same non-list caveat as {@see updateCategoryVisibility()}
     */
    public function updateCategoryStatus(array $ids, string $status): void
    {
        if ($ids === []) {
            return;
        }

        $em = $this->getEntityManager();
        $em->createQueryBuilder()
            ->update(CategoryEntity::class, 'c')
            ->set('c.status', ':status')
            ->where('c.id IN (:ids)')
            ->setParameter('status', $status)
            ->setParameter('ids', array_values($ids))
            ->getQuery()
            ->execute();
        $em->clear();
    }

    /**
     * CategoryAdminService::saveImageOrder()'s own category (admin/
     * element_set_ranks.php).
     */
    public function updateImageOrder(int $catId, ?string $imageOrder): void
    {
        $entity = $this->find($catId);
        if ($entity === null) {
            return;
        }

        $entity->imageOrder = $imageOrder;
        $this->getEntityManager()
            ->flush();
    }

    /**
     * Same as {@see updateImageOrder()}, applied to every descendant of
     * $uppercatsPrefix (a category's own `uppercats` value + ',') when
     * saveImageOrder()'s $applySubcats is true.
     */
    public function updateImageOrderForDescendants(string $uppercatsPrefix, ?string $imageOrder): void
    {
        $em = $this->getEntityManager();
        $em->createQueryBuilder()
            ->update(CategoryEntity::class, 'c')
            ->set('c.imageOrder', ':imageOrder')
            ->where('c.uppercats LIKE :uppercatsPrefix')
            ->setParameter('imageOrder', $imageOrder)
            ->setParameter('uppercatsPrefix', $uppercatsPrefix . '%')
            ->getQuery()
            ->execute();
        $em->clear();
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

        $rows = $this->getEntityManager()
            ->getConnection()
            ->executeQuery('
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
        $entities = $this->getEntityManager()
            ->createQueryBuilder()
            ->select('ua')
            ->from(UserAccessEntity::class, 'ua')
            ->where('ua.catId = :catId')
            ->setParameter('catId', $catId)
            ->getQuery()
            ->getResult();

        return array_map(static fn (UserAccessEntity $ua): int => $ua->userId, $entities);
    }

    /**
     * @return list<int>
     */
    public function findAccessGroupIds(int $catId): array
    {
        // Single-value DQL parameter against a custom-typed field -- the
        // well-supported path (unlike the IN-clause array case above),
        // still wraps to keep AbstractNumericIdType::convertToDatabaseValue()
        // strict (VO-only).
        $entities = $this->getEntityManager()
            ->createQueryBuilder()
            ->select('ga')
            ->from(GroupAccessEntity::class, 'ga')
            ->where('ga.catId = :catId')
            ->setParameter('catId', CategoryId::from($catId))
            ->getQuery()
            ->getResult();

        return array_map(static fn (GroupAccessEntity $ga): int => $ga->groupId->value, $entities);
    }

    /**
     * @param  list<int>  $keepIds  a non-empty list is guaranteed by the
     *   caller (-1 is substituted when no reference access exists, matching
     *   the original's own `$ref_access[] = -1;` sentinel)
     * @param  list<int>  $catIds
     */
    public function deleteInconsistentAccess(string $table, string $field, array $keepIds, array $catIds): void
    {
        $em = $this->getEntityManager();
        $em->getConnection()
            ->executeStatement('
DELETE
  FROM ' . $table . '
  WHERE ' . $field . ' NOT IN (' . implode(',', $keepIds) . ')
    AND cat_id IN (' . implode(',', $catIds) . ')
;');
        $em->clear();
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

        return array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '', $this->getEntityManager()->getConnection()->executeQuery('
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

        $rows = $this->getEntityManager()
            ->getConnection()
            ->executeQuery('
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
     * The value's real type depends on which column $field names (a date
     * string for a date column, a number for a numeric one) -- genuinely
     * arbitrary by design, not just unnarrowed.
     *
     * @param  list<int>  $categoryIds
     * @return array<int, mixed> keyed by category_id
     */
    public function findRefDatesByCategoryIds(array $categoryIds, string $field, string $minmax): array
    {
        if ($categoryIds === []) {
            return [];
        }

        $rows = $this->getEntityManager()
            ->getConnection()
            ->executeQuery('
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
        $value = $this->getEntityManager()
            ->getConnection()
            ->executeQuery('
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
        return array_filter($this->getEntityManager()->getConnection()->executeQuery('
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
        return array_filter($this->getEntityManager()->getConnection()->executeQuery('
SELECT id, galleries_url
  FROM ' . Tables::sites() . '
;')->fetchAllKeyValue(), is_string(...));
    }

    /**
     * @param  array<int>  $ids  real callers don't guarantee a list
     * @return list<array{id: int, uppercats: string, site_id: ?int}>
     */
    public function findCategoriesForFulldirs(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $rows = $this->getEntityManager()
            ->getConnection()
            ->executeQuery('
SELECT id, uppercats, site_id
  FROM ' . Tables::categories() . '
  WHERE dir IS NOT NULL
    AND id IN (
' . wordwrap(implode(', ', $ids), 80, "\n") . ')
;')->fetchAllAssociative();

        return array_map(
            static fn (array $row): array => [
                'id' => is_numeric($row['id']) ? (int) $row['id'] : 0,
                'uppercats' => is_string($row['uppercats'] ?? null) ? $row['uppercats'] : '',
                'site_id' => is_numeric($row['site_id'] ?? null) ? (int) $row['site_id'] : null,
            ],
            $rows
        );
    }

    /**
     * @return list<int>
     */
    public function findDistinctStorageCategoryIds(): array
    {
        return array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $this->getEntityManager()->getConnection()->executeQuery('
SELECT DISTINCT(storage_category_id)
  FROM ' . Tables::images() . '
  WHERE storage_category_id IS NOT NULL
;')->fetchFirstColumn());
    }

    public function updateImagePathsForCategory(int $categoryId, string $fulldir): void
    {
        $this->getEntityManager()
            ->getConnection()
            ->executeStatement('
UPDATE ' . Tables::images() . '
  SET path = ' . SqlDialect::concat(["'" . $fulldir . "/'", 'file']) . '
  WHERE storage_category_id = ' . $categoryId . '
;');
    }

    /**
     * Sets $categoryId's representative image -- Controller\
     * PictureController's own "set_as_representative" action. Caller is
     * responsible for clearing the EntityManager afterward (bypasses the
     * ORM, same as every other raw-DBAL write in this class).
     */
    public function setRepresentativeImage(int $categoryId, int $imageId): void
    {
        $this->getEntityManager()
            ->getConnection()
            ->executeStatement('
UPDATE ' . Tables::categories() . '
  SET representative_picture_id = ' . $imageId . '
  WHERE id = ' . $categoryId . '
;');
    }

    /**
     * @param  array<int>  $ids  real callers don't guarantee a list
     * @return list<array{id: int, id_uppercat: ?int, status: string, uppercats: string}>
     */
    public function findCategoriesForMove(array $ids): array
    {
        $rows = $this->getEntityManager()
            ->getConnection()
            ->executeQuery('
SELECT id, id_uppercat, status, uppercats
  FROM ' . Tables::categories() . '
  WHERE id IN (' . implode(',', $ids) . ')
;')->fetchAllAssociative();

        return array_map(
            static fn (array $row): array => [
                'id' => is_numeric($row['id']) ? (int) $row['id'] : 0,
                'id_uppercat' => is_numeric($row['id_uppercat'] ?? null) ? (int) $row['id_uppercat'] : null,
                'status' => is_string($row['status'] ?? null) ? $row['status'] : '',
                'uppercats' => is_string($row['uppercats'] ?? null) ? $row['uppercats'] : '',
            ],
            $rows
        );
    }

    public function findCategoryUppercatsById(int $id): ?string
    {
        $value = $this->getEntityManager()
            ->getConnection()
            ->executeQuery('
SELECT uppercats
  FROM ' . Tables::categories() . '
  WHERE id = ' . $id . '
;')->fetchOne();

        return is_string($value) ? $value : null;
    }

    /**
     * $newParent is either a numeric category id or the literal string
     * `'NULL'` (root) -- matches the original's own `$new_parent < 1 ?
     * 'NULL' : $new_parent` substitution. Parsed here into a real bound
     * parameter (int or null) rather than embedded unquoted into the SQL,
     * now that this is a DQL bulk update against the owned CategoryEntity.
     *
     * @param  array<int>  $ids  real callers don't guarantee a list
     */
    public function updateCategoryParent(array $ids, string $newParent): void
    {
        if ($ids === []) {
            return;
        }

        $em = $this->getEntityManager();
        $em->createQueryBuilder()
            ->update(CategoryEntity::class, 'c')
            ->set('c.idUppercat', ':newParent')
            ->where('c.id IN (:ids)')
            ->setParameter('newParent', $newParent === 'NULL' ? null : (int) $newParent)
            ->setParameter('ids', array_values($ids))
            ->getQuery()
            ->execute();
        $em->clear();
    }

    public function findCategoryStatus(int $id): ?string
    {
        $value = $this->getEntityManager()
            ->getConnection()
            ->executeQuery('
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

        $value = $this->getEntityManager()
            ->getConnection()
            ->executeQuery('
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
        $row = $this->getEntityManager()
            ->getConnection()
            ->executeQuery('
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
        return $this->getEntityManager()
            ->getConnection()
            ->executeQuery($query)
            ->fetchAllAssociative();
    }

    /**
     * id stays mixed -- CategoryService::saveCategoriesOrder()'s own
     * $categories is raw request input (see that method's own docblock),
     * so id traces back to an unvalidated request element.
     *
     * @param array<int, array{id: mixed, rank: int}> $datas
     */
    public function massUpdateRanks(array $datas): void
    {
        $em = $this->getEntityManager();
        new BatchWriter($em->getConnection())
            ->massUpdate(
                Tables::categories(),
                [
                    'primary' => ['id'],
                    'update' => ['rank'],
                ],
                $datas
            );
        $em->clear();
    }

    /**
     * @param array<int, array{id: int, rank: int, global_rank: ?string}> $datas
     */
    public function massUpdateRanksAndGlobalRank(array $datas): void
    {
        $em = $this->getEntityManager();
        new BatchWriter($em->getConnection())
            ->massUpdate(
                Tables::categories(),
                [
                    'primary' => ['id'],
                    'update' => ['rank', 'global_rank'],
                ],
                $datas
            );
        $em->clear();
    }

    /**
     * @param array<int, array{id: int, representative_picture_id: ?int}> $datas
     */
    public function massUpdateRepresentativePictures(array $datas): void
    {
        $em = $this->getEntityManager();
        new BatchWriter($em->getConnection())
            ->massUpdate(
                Tables::categories(),
                [
                    'primary' => ['id'],
                    'update' => ['representative_picture_id'],
                ],
                $datas
            );
        $em->clear();
    }

    /**
     * @param array<int, array{id: int, uppercats: string}> $datas
     */
    public function massUpdateUppercats(array $datas): void
    {
        $em = $this->getEntityManager();
        new BatchWriter($em->getConnection())
            ->massUpdate(
                Tables::categories(),
                [
                    'primary' => ['id'],
                    'update' => ['uppercats'],
                ],
                $datas
            );
        $em->clear();
    }

    /**
     * Inserts a new category row and returns its auto-generated id. Stays
     * raw DBAL (BatchWriter) -- $insert is a generic, caller-built
     * column=>value map (dynamic keyset), which a fixed-property
     * CategoryEntity can't represent, same "dynamic column map" exception
     * as Users\UserRepository::insertUser().
     *
     * @param array<string, mixed> $insert
     */
    public function insertCategory(array $insert): int|string
    {
        $em = $this->getEntityManager();
        new BatchWriter($em->getConnection())
            ->singleInsert(Tables::categories(), $insert);
        $em->clear();

        return $em->getConnection()
            ->lastInsertId();
    }

    /**
     * @param array<string, mixed> $data
     */
    public function updateCategoryAfterInsert(int|string $id, array $data): void
    {
        $em = $this->getEntityManager();
        new BatchWriter($em->getConnection())
            ->singleUpdate(Tables::categories(), $data, [
                'id' => $id,
            ]);
        $em->clear();
    }

    /**
     * $ignore matters for CategoryAdminService::setCategoryPermissions()'s
     * own caller: re-granting a group that already has access to one of
     * the target categories (editing permissions on an existing category,
     * not creating a brand new one) would otherwise hit group_access's own
     * unique constraint. createVirtualCategory()'s inheritance-on-creation
     * caller doesn't need it -- a freshly INSERTed category id can't
     * already have any group_access rows -- so the default is false.
     * Stays raw DBAL (BatchWriter) -- persist()+flush() has no INSERT
     * IGNORE equivalent, same reasoning as Group\GroupRepository::
     * addMembers().
     *
     * @param array<int, array{group_id: int, cat_id: int}> $inserts
     */
    public function massInsertGroupAccess(array $inserts, bool $ignore = false): void
    {
        $em = $this->getEntityManager();
        new BatchWriter($em->getConnection())
            ->massInsert(Tables::groupAccess(), ['group_id', 'cat_id'], $inserts, [
                'ignore' => $ignore,
            ]);
        $em->clear();
    }

    /**
     * Picks a random representative image among a category's sub-categories
     * (`CategoryCatsRenderer`'s own fallback when a category has no direct
     * representative but does have sub-albums with images). $permissionCondition
     * is an already-built SQL fragment (leading "\n  AND"), same
     * pre-built-permission-string shape as every other repository method
     * here.
     *
     * Gap-closure Stage 4h (docs/plan/gap-closure-p0-p23.md): dropped the
     * `user_cache_categories` `INNER JOIN` -- a real, live regression this
     * fix closes, not just a modernization: gap-closure Stage 4g deleted
     * the only remaining writer of that table, so the JOIN's own
     * visibility filter had silently gone permanently empty for every user
     * (confirmed live: only 2 stale rows survived in the whole table).
     * The caller's own `$permissionCondition` (built via
     * `PermissionService::getSqlConditionFandF(['visible_categories' =>
     * 'id'], ...)`) was *already* a live, correctly-scoped duplicate of
     * the exact same "is this category visible" check the JOIN provided
     * via a now-dead precomputed table -- removing the JOIN is not a
     * behavior change, the real filtering was already happening twice.
     * `$userId` is dropped too -- its only use was the JOIN's own
     * `user_id = :userId` condition.
     */
    public function findRandomRepresentativeIdAmongSubcategories(string $uppercats, string $permissionCondition): ?string
    {
        $value = $this->getEntityManager()
            ->getConnection()
            ->executeQuery('
SELECT representative_picture_id
  FROM ' . Tables::categories() . '
  WHERE uppercats LIKE :uppercatsLike
    AND representative_picture_id IS NOT NULL'
              . $permissionCondition . '
  ORDER BY ' . SqlDialect::DB_RANDOM_FUNCTION . '()
  LIMIT 1
;', [
                  'uppercatsLike' => $uppercats . ',%',
              ])->fetchOne();

        // fetchOne() returns false (also a real is_scalar() value) to
        // signal "no rows matched" -- is_scalar() alone can't tell that
        // apart from a genuine representative_picture_id, so it must be
        // excluded explicitly first.
        return $value !== false && is_scalar($value) ? (string) $value : null;
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

        $rows = $this->getEntityManager()
            ->getConnection()
            ->executeQuery('
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

    public function countAllCategories(): int
    {
        $value = $this->getEntityManager()
            ->getConnection()
            ->fetchOne('SELECT COUNT(*) FROM ' . Tables::categories() . ';');

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * Count of categories with no physical directory (virtual) or with
     * one (physical) -- Ws\PwgCore::getInfos()'s own "nb_virtual"/
     * "nb_physical" summary figures.
     */
    public function countByDirNull(bool $dirIsNull): int
    {
        $value = $this->getEntityManager()
            ->getConnection()
            ->fetchOne('
SELECT COUNT(*)
  FROM ' . Tables::categories() . '
  WHERE dir IS ' . ($dirIsNull ? 'NULL' : 'NOT NULL') . '
;');

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * Ids of every category that uses $imageId as its own representative
     * picture -- Admin\PictureModifyPageRenderer's own "which albums does
     * this photo currently represent" lookup.
     *
     * @return list<int>
     */
    public function findCategoryIdsRepresentedByImage(int $imageId): array
    {
        return array_map(
            static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0,
            array_column($this->getEntityManager()
                ->getConnection()
                ->fetchAllAssociative('
SELECT id
  FROM ' . Tables::categories() . '
  WHERE representative_picture_id = ' . $imageId . '
;'), 'id')
        );
    }

    /**
     * Bulk variant of setRepresentativeImage() above -- Admin\
     * PictureModifyPageRenderer's own "make this photo the thumbnail for
     * every newly-checked album" step.
     *
     * @param list<int> $categoryIds
     */
    public function setRepresentativeImageForCategories(array $categoryIds, int $imageId): void
    {
        if ($categoryIds === []) {
            return;
        }

        $this->getEntityManager()
            ->getConnection()
            ->executeStatement('
UPDATE ' . Tables::categories() . '
  SET representative_picture_id = ' . $imageId . '
  WHERE id IN (' . implode(',', $categoryIds) . ')
;');
    }

    /**
     * Private category ids already granted to $groupId --
     * Admin\GroupPermPageRenderer's own "already-authorized" set, used to
     * compute which private categories still need granting.
     *
     * @return list<int>
     */
    public function findPrivateCategoryIdsGrantedToGroup(int $groupId): array
    {
        return array_map(
            static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0,
            array_column($this->getEntityManager()
                ->getConnection()
                ->fetchAllAssociative('
SELECT id
  FROM ' . Tables::categories() . ' INNER JOIN ' . Tables::groupAccess() . ' ON cat_id = id
  WHERE status = \'private\'
    AND group_id = ' . $groupId . '
;'), 'id')
        );
    }

    /**
     * Categories $userId is authorized for via group membership (not
     * direct grants) -- Admin\UserPermPageRenderer's own "authorized
     * because of a group" display, deduplicated across every group the
     * user belongs to.
     *
     * @return list<array<string, mixed>>
     */
    public function findCategoriesAuthorizedViaGroupsForUser(int $userId): array
    {
        return $this->getEntityManager()
            ->getConnection()
            ->fetchAllAssociative('
SELECT DISTINCT cat_id, c.uppercats, c.global_rank
  FROM ' . Tables::userGroup() . ' AS ug
    INNER JOIN ' . Tables::groupAccess() . ' AS ga
      ON ug.group_id = ga.group_id
    INNER JOIN ' . Tables::categories() . ' AS c
      ON c.id = ga.cat_id
  WHERE ug.user_id = ' . $userId . '
;');
    }

    /**
     * Private categories directly granted to $userId, optionally excluding
     * $excludeCategoryIds (categories already authorized via group
     * membership) -- Admin\UserPermPageRenderer's own "authorized
     * individually" listing.
     *
     * @param list<int> $excludeCategoryIds
     * @return list<int>
     */
    public function findPrivateCategoryIdsGrantedToUser(int $userId, array $excludeCategoryIds): array
    {
        $query = '
SELECT id
  FROM ' . Tables::categories() . ' INNER JOIN ' . Tables::userAccess() . ' ON cat_id = id
  WHERE status = \'private\'
    AND user_id = ' . $userId;
        if ($excludeCategoryIds !== []) {
            $query .= '
    AND cat_id NOT IN (' . implode(',', $excludeCategoryIds) . ')';
        }
        $query .= '
;';

        return array_map(
            static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0,
            array_column($this->getEntityManager()
                ->getConnection()
                ->fetchAllAssociative($query), 'id')
        );
    }

    /**
     * Every non-null category permalink -- Admin\ExtendForTemplatesPageRenderer's
     * own "selective URLs keyword" list.
     *
     * @return list<string>
     */
    public function findActivePermalinks(): array
    {
        $rows = $this->getEntityManager()
            ->getConnection()
            ->fetchAllAssociative('
SELECT permalink
  FROM ' . Tables::categories() . '
  WHERE permalink IS NOT NULL
;');

        return array_map(
            static fn (mixed $v): string => is_scalar($v) ? (string) $v : '',
            array_column($rows, 'permalink')
        );
    }

    /**
     * Direct children of $parentId (or every root category when null),
     * ordered by rank -- Admin\CatListPageRenderer's own album listing.
     *
     * @return list<array<string, mixed>>
     */
    public function findChildrenOfParent(?int $parentId): array
    {
        $query = '
SELECT id, name, permalink, dir, `rank`, status
  FROM ' . Tables::categories();
        $query .= $parentId === null
            ? '
  WHERE id_uppercat IS NULL'
            : '
  WHERE id_uppercat = ' . $parentId;
        $query .= '
  ORDER BY `rank` ASC
;';

        return $this->getEntityManager()
            ->getConnection()
            ->fetchAllAssociative($query);
    }

    /**
     * Photo count per category (every category that owns at least one
     * direct image_category link) -- Admin\CatListPageRenderer's own
     * per-album photo count display.
     *
     * @return array<int, int> keyed by category_id
     */
    public function findPhotoCountsByCategory(): array
    {
        $rows = $this->getEntityManager()
            ->getConnection()
            ->fetchAllAssociative('
SELECT
    category_id,
    COUNT(*) AS nb_photos
  FROM ' . Tables::imageCategory() . '
  GROUP BY category_id
;');

        $countByCategory = [];
        foreach ($rows as $row) {
            if (is_numeric($row['category_id']) && is_numeric($row['nb_photos'])) {
                $countByCategory[(int) $row['category_id']] = (int) $row['nb_photos'];
            }
        }

        return $countByCategory;
    }

    /**
     * Every category's own uppercats string, unfiltered and keyed by id --
     * Admin\CatListPageRenderer's own subcategory/photo-rollup computation.
     *
     * @return array<int|string, mixed> keyed by id
     */
    public function findAllCategoryUppercats(): array
    {
        return array_column($this->getEntityManager()
            ->getConnection()
            ->fetchAllAssociative('
SELECT
    id,
    uppercats
  FROM ' . Tables::categories() . '
;'), 'uppercats', 'id');
    }

    /**
     * Bare ids of $parentId's direct children (or every root category when
     * null) -- Admin\AlbumsPageRenderer's own auto-order id resolution, a
     * narrower-column sibling of findChildrenOfParent() above (that one
     * also selects name/permalink/dir/rank/status and orders by rank).
     *
     * @return list<int>
     */
    public function findIdsByParent(?int $parentId): array
    {
        $query = '
SELECT id
  FROM ' . Tables::categories() . '
  WHERE id_uppercat ' . ($parentId === null ? 'IS NULL' : '= ' . $parentId) . '
;';

        return array_map(
            static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0,
            array_column($this->getEntityManager()
                ->getConnection()
                ->fetchAllAssociative($query), 'id')
        );
    }

    /**
     * id/name/id_uppercat for $categoryIds -- Admin\AlbumsPageRenderer's
     * own auto-order sort-and-save step.
     *
     * @param list<string> $categoryIds
     * @return list<array<string, mixed>>
     */
    public function findIdsNamesUppercatsForIds(array $categoryIds): array
    {
        return $this->getEntityManager()
            ->getConnection()
            ->fetchAllAssociative('
SELECT id, name, id_uppercat
  FROM ' . Tables::categories() . '
  WHERE id IN (' . implode(',', $categoryIds) . ')
;');
    }

    /**
     * Every category's id/name/rank/status/visible/uppercats/lastmodified
     * -- Admin\AlbumsPageRenderer's own full album-tree listing.
     *
     * @return list<array<string, mixed>>
     */
    public function findAllForAlbumTree(): array
    {
        return $this->getEntityManager()
            ->getConnection()
            ->fetchAllAssociative('
SELECT id,name,`rank`,status, visible, uppercats, lastmodified
  FROM ' . Tables::categories() . '
;');
    }

    /**
     * Whether $categoryId has at least one direct image link --
     * Admin\CatModifyPageRenderer's own "has_images" flag.
     */
    public function hasImages(int $categoryId): bool
    {
        $rows = $this->getEntityManager()
            ->getConnection()
            ->fetchAllAssociative('SELECT DISTINCT category_id
  FROM ' . Tables::imageCategory() . '
  WHERE category_id = :category_id
  LIMIT 1', [
                'category_id' => $categoryId,
            ]);

        return count($rows) > 0;
    }

    /**
     * Photo count plus min/max date_available for $categoryId's own direct
     * images -- Admin\CatModifyPageRenderer's own "this album contains N
     * photos, added between X and Y" summary.
     *
     * @return list<mixed>|false
     */
    public function findPhotoCountAndDateRange(int $categoryId): array|false
    {
        return $this->getEntityManager()
            ->getConnection()
            ->fetchNumeric('
SELECT
    COUNT(image_id),
    MIN(DATE(date_available)),
    MAX(DATE(date_available))
  FROM ' . Tables::images() . '
    JOIN ' . Tables::imageCategory() . ' ON image_id = id
  WHERE category_id = ' . $categoryId . '
;');
    }

    /**
     * Distinct image ids across every id in $categoryIds -- Admin\
     * CatModifyPageRenderer's own recursive (including sub-albums) photo
     * count.
     *
     * @param list<int> $categoryIds
     * @return list<int>
     */
    public function findDistinctImageIdsInCategories(array $categoryIds): array
    {
        return array_map(
            static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0,
            array_column($this->getEntityManager()
                ->getConnection()
                ->fetchAllAssociative('
SELECT DISTINCT
    (image_id)
  FROM
    ' . Tables::imageCategory() . '
  WHERE
    category_id IN (' . implode(',', $categoryIds) . ')
  ;'), 'image_id')
        );
    }

    /**
     * `dir` keyed by id for $ids -- Admin\CatModifyPageRenderer's own
     * getLocalDir() path-segment resolution.
     *
     * @param list<int|string> $ids
     * @return array<int|string, mixed> keyed by id
     */
    public function findDirsByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return array_column($this->getEntityManager()
            ->getConnection()
            ->fetchAllAssociative('SELECT id,dir
  FROM ' . Tables::categories() . ' WHERE id IN (' . implode(',', $ids) . ')
;'), 'dir', 'id');
    }

    /**
     * $categoryId's own site's galleries_url, via the site_id FK join --
     * Admin\CatModifyPageRenderer's own getSiteUrl().
     */
    public function findGalleriesUrlForCategory(int|string $categoryId): ?string
    {
        $row = $this->getEntityManager()
            ->getConnection()
            ->fetchAssociative('
SELECT galleries_url
  FROM ' . Tables::sites() . ' AS s,' . Tables::categories() . ' AS c
  WHERE s.id = c.site_id
    AND c.id = ' . $categoryId . '
;');

        if ($row === false) {
            return null;
        }

        $galleriesUrl = $row['galleries_url'];

        return is_string($galleriesUrl) ? $galleriesUrl : '';
    }

    /**
     * id/permalink/uppercats/global_rank for every category with an active
     * permalink -- Controller\Admin\PermalinksSubController's own listing.
     * $orderBySql is a raw "ORDER BY ..." fragment or '' (the caller sorts
     * by global_rank itself afterward when not sorting by id/permalink).
     *
     * @return list<array<string, mixed>>
     */
    public function findActivePermalinksList(string $orderBySql): array
    {
        return $this->getEntityManager()
            ->getConnection()
            ->fetchAllAssociative('
SELECT id, permalink, uppercats, global_rank
  FROM ' . Tables::categories() . '
  WHERE permalink IS NOT NULL
' . $orderBySql);
    }

    /**
     * Whether $catId exists and isn't among $forbiddenCategoriesCsv --
     * Controller\SearchController's own "does this album exist and is it
     * accessible" check.
     */
    public function existsAndNotForbidden(int $catId, string $forbiddenCategoriesCsv): bool
    {
        $rows = $this->getEntityManager()
            ->getConnection()
            ->fetchAllAssociative('
SELECT
    id
  FROM ' . Tables::categories() . '
  WHERE id = ' . $catId . '
    AND id NOT IN (' . $forbiddenCategoriesCsv . ')
;');

        return $rows !== [];
    }

    /**
     * Whether a category with this id exists -- Ws\PwgCategories'
     * setRepresentative()'s own existence check.
     */
    public function existsById(int $id): bool
    {
        $value = $this->getEntityManager()
            ->getConnection()
            ->fetchOne('
SELECT COUNT(*)
  FROM ' . Tables::categories() . '
  WHERE id = ' . $id . '
;');

        return is_numeric($value) && (int) $value > 0;
    }

    /**
     * Ids from $ids that really exist -- Ws\PwgCategories' own "do these
     * categories really exist" checks (getImages()/delete()).
     *
     * @param  list<int>  $ids
     * @return list<int>
     */
    public function findExistingIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return array_map(
            static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0,
            $this->getEntityManager()
                ->getConnection()
                ->executeQuery('
SELECT id
  FROM ' . Tables::categories() . '
  WHERE id IN (' . implode(',', $ids) . ')
;')->fetchFirstColumn()
        );
    }

    /**
     * id/image_order for categories matching already-built $whereClauses --
     * Ws\PwgCategories::getImages()'s own "which categories are we
     * fetching images for" step.
     *
     * @param  list<string>  $whereClauses
     * @return list<array{id: int, image_order: ?string}>
     */
    public function findIdsAndImageOrderWithConditions(array $whereClauses): array
    {
        $rows = $this->getEntityManager()
            ->getConnection()
            ->fetchAllAssociative('
SELECT
    id,
    image_order
  FROM ' . Tables::categories() . '
  WHERE ' . implode("\n    AND ", $whereClauses) . '
;');

        return array_map(
            static fn (array $row): array => [
                'id' => is_numeric($row['id']) ? (int) $row['id'] : 0,
                'image_order' => is_string($row['image_order'] ?? null) ? $row['image_order'] : null,
            ],
            $rows
        );
    }

    /**
     * Ws\PwgCategories::getList()'s own paginated category rollup --
     * $whereClauses are already-built, trusted SQL fragments (permission/
     * recursive-scope conditions), same "caller composes trusted
     * fragments" contract as {@see \Piwigo\Comment\CommentRepository::findAllWithConditions()}.
     * $searchTerm/$searchLimit/$limit/$limitPlusOne replicate the
     * original's own conditional LIMIT logic verbatim: a search term gets
     * its own LIMIT only when no explicit $limit is requested; $limit
     * itself gets +1 when $limitPlusOne (single-category scope), to detect
     * "more remain" without a second query. FOUND_ROWS() is only fetched
     * when $limit !== null, matching the original's own guard.
     *
     * @param  list<string>  $whereClauses
     * @return PaginatedResult<array<string, mixed>>
     */
    public function findListForWs(
        array $whereClauses,
        ?string $searchTerm,
        int $searchLimit,
        ?int $limit,
        bool $limitPlusOne
    ): PaginatedResult {
        $conn = $this->getEntityManager()
            ->getConnection();

        $sql = '
SELECT SQL_CALC_FOUND_ROWS
    id, name, comment, permalink, status,
    uppercats, global_rank, id_uppercat,
    representative_picture_id,
    image_order
  FROM ' . Tables::categories() . '
  WHERE ' . implode("\n    AND ", $whereClauses);

        if ($searchTerm !== null) {
            $sql .= '
    AND name LIKE ' . $conn->quote('%' . $searchTerm . '%');
            if ($limit === null) {
                $sql .= ' LIMIT ' . $searchLimit;
            }
        }

        if ($limit !== null) {
            $sql .= '
  ORDER BY `rank` ASC
  LIMIT ' . ($limit + ($limitPlusOne ? 1 : 0));
        }

        $sql .= '
;';

        $rows = $conn->fetchAllAssociative($sql);

        $total = null;
        if ($limit !== null) {
            $totalRaw = $conn->fetchOne('SELECT FOUND_ROWS()');
            $total = is_numeric($totalRaw) ? (int) $totalRaw : 0;
        }

        return new PaginatedResult($rows, $total);
    }

    /**
     * Ws\PwgCategories::getAdminList()'s own paginated category rollup --
     * same "caller composes trusted fragments" contract as
     * {@see findListForWs()} above, always fetches FOUND_ROWS()
     * (unconditional in the original, unlike findListForWs()'s own
     * $limit-gated fetch).
     *
     * @param  list<string>  $whereClauses
     * @return PaginatedResult<array<string, mixed>>
     */
    public function findAdminListForWs(array $whereClauses, ?string $searchTerm, int $searchLimit): PaginatedResult
    {
        $conn = $this->getEntityManager()
            ->getConnection();

        $sql = '
SELECT SQL_CALC_FOUND_ROWS id, name, comment, uppercats, global_rank, dir, status, image_order
  FROM ' . Tables::categories() . '
  WHERE ' . implode("\n    AND ", $whereClauses);

        if ($searchTerm !== null) {
            $sql .= '
  AND name LIKE ' . $conn->quote('%' . $searchTerm . '%') . '
  LIMIT ' . $searchLimit;
        }

        $sql .= '
;';

        $rows = $conn->fetchAllAssociative($sql);
        $totalRaw = $conn->fetchOne('SELECT FOUND_ROWS()');

        return new PaginatedResult($rows, is_numeric($totalRaw) ? (int) $totalRaw : 0);
    }

    /**
     * Subcategory counts grouped by parent id -- Ws\PwgCategories::
     * getAdminList()'s own non-recursive "nb_categories" column.
     *
     * @param  list<int>  $parentIds
     * @return array<string, int> keyed by id_uppercat
     */
    public function findSubcategoryCountsByParent(array $parentIds): array
    {
        if ($parentIds === []) {
            return [];
        }

        $rows = $this->getEntityManager()
            ->getConnection()
            ->fetchAllAssociative('
SELECT
    id_uppercat,
    COUNT(*) AS nb_subcats
  FROM ' . Tables::categories() . '
  WHERE id_uppercat IN (' . implode(',', $parentIds) . ')
  GROUP BY id_uppercat
;');

        $bySubcat = [];
        foreach ($rows as $row) {
            $idUppercat = $row['id_uppercat'] ?? null;
            $nbSubcats = $row['nb_subcats'] ?? null;
            if (is_scalar($idUppercat) && is_numeric($nbSubcats)) {
                $bySubcat[(string) $idUppercat] = (int) $nbSubcats;
            }
        }

        return $bySubcat;
    }

    /**
     * id/id_uppercat/rank for $ids -- Ws\PwgCategories::setRank()'s own
     * "does the category really exist" check plus the sibling data it
     * needs afterward.
     *
     * @param  list<int>  $ids
     * @return list<array{id: int, id_uppercat: ?int, rank: ?int}>
     */
    public function findRankInfoByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $rows = $this->getEntityManager()
            ->getConnection()
            ->fetchAllAssociative('
SELECT id, id_uppercat, `rank`
  FROM ' . Tables::categories() . '
  WHERE id IN (' . implode(',', $ids) . ')
;');

        return array_map(
            static fn (array $row): array => [
                'id' => is_numeric($row['id']) ? (int) $row['id'] : 0,
                'id_uppercat' => is_numeric($row['id_uppercat'] ?? null) ? (int) $row['id_uppercat'] : null,
                'rank' => is_numeric($row['rank'] ?? null) ? (int) $row['rank'] : null,
            ],
            $rows
        );
    }

    /**
     * Ids of every category directly under $parentId (or top-level, when
     * null), ordered by id -- Ws\PwgCategories::setRank()'s own
     * "does the caller-provided order cover every sibling" check, which
     * relies on this exact id-ascending order to compare against the
     * caller's own numerically-sorted id list.
     *
     * @return list<int>
     */
    public function findIdsByParentOrderedById(?int $parentId): array
    {
        return array_map(
            static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0,
            $this->getEntityManager()
                ->getConnection()
                ->executeQuery('
SELECT id
  FROM ' . Tables::categories() . '
  WHERE id_uppercat ' . ($parentId === null ? 'IS NULL' : '= ' . $parentId) . '
  ORDER BY `id` ASC
;')->fetchFirstColumn()
        );
    }

    /**
     * Ids of every sibling of $excludeId under $parentId (or top-level,
     * when null), ordered by rank -- Ws\PwgCategories::setRank()'s own
     * "insert the new category into its siblings' existing rank order"
     * step.
     *
     * @return list<int>
     */
    public function findSiblingIdsExcludingOrderedByRank(?int $parentId, int $excludeId): array
    {
        return array_map(
            static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0,
            $this->getEntityManager()
                ->getConnection()
                ->executeQuery('
SELECT id
  FROM ' . Tables::categories() . '
  WHERE id_uppercat ' . ($parentId === null ? 'IS NULL' : '= ' . $parentId) . '
    AND id != ' . $excludeId . '
  ORDER BY `rank` ASC
;')->fetchFirstColumn()
        );
    }

    /**
     * id/name/dir/uppercats for $ids -- Ws\PwgCategories::move()'s own
     * "reject physical categories, and remember every ancestor to
     * refresh" step. A different 4-column shape from
     * {@see findCategoriesForMove()} above (that one is
     * id/id_uppercat/status/uppercats, for a different real caller).
     *
     * @param  list<int>  $ids
     * @return list<array{id: int, name: string, dir: ?string, uppercats: string}>
     */
    public function findMoveDetailsByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $rows = $this->getEntityManager()
            ->getConnection()
            ->fetchAllAssociative('
SELECT id, name, dir, uppercats
  FROM ' . Tables::categories() . '
  WHERE id IN (' . implode(',', $ids) . ')
;');

        return array_map(
            static fn (array $row): array => [
                'id' => is_numeric($row['id']) ? (int) $row['id'] : 0,
                'name' => is_string($row['name'] ?? null) ? $row['name'] : '',
                'dir' => is_string($row['dir'] ?? null) ? $row['dir'] : null,
                'uppercats' => is_string($row['uppercats'] ?? null) ? $row['uppercats'] : '',
            ],
            $rows
        );
    }

    /**
     * Next free id -- Controller\Admin\SiteUpdateSubController's own
     * manual-id assignment for directory-synced categories (mirrors the
     * retired MysqliDb::nextval()).
     */
    public function findNextId(): int
    {
        $next = $this->getEntityManager()
            ->getConnection()
            ->fetchOne('
SELECT IF(MAX(id)+1 IS NULL, 1, MAX(id)+1)
  FROM ' . Tables::categories());

        return is_numeric($next) ? (int) $next : 1;
    }

    /**
     * Categories with a physical `dir`, scoped to $siteId (directory-based
     * synchronization's own candidate set) -- Controller\Admin\
     * SiteUpdateSubController's own "which categories to update" step.
     * $extraCondition is an already-built, trusted SQL AND-continuation
     * fragment (empty string means no further restriction) -- same
     * "caller composes trusted fragments" contract used throughout this
     * repository.
     *
     * @return list<array<string, mixed>>
     */
    public function findSyncCandidatesForSite(int $siteId, string $extraCondition): array
    {
        return $this->getEntityManager()
            ->getConnection()
            ->fetchAllAssociative('
SELECT id, uppercats, global_rank, status, visible
  FROM ' . Tables::categories() . '
  WHERE dir IS NOT NULL
    AND site_id = ' . $siteId . '
' . $extraCondition);
    }

    /**
     * Every category id, unfiltered -- Controller\Admin\
     * SiteUpdateSubController's own rank-bootstrap step ("every category
     * defaults to next-rank 1 on its own sub-categories, until proven
     * otherwise below").
     *
     * @return list<int>
     */
    public function findAllIds(): array
    {
        return array_map(
            static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0,
            $this->getEntityManager()
                ->getConnection()
                ->executeQuery('
SELECT id
  FROM ' . Tables::categories())->fetchFirstColumn()
        );
    }

    /**
     * Next available rank per parent (id_uppercat) -- Controller\Admin\
     * SiteUpdateSubController's own "does this parent already have
     * sub-categories, and if so what's the next free rank" step.
     *
     * @return list<array<string, mixed>>
     */
    public function findNextRanksByParent(): array
    {
        return $this->getEntityManager()
            ->getConnection()
            ->fetchAllAssociative('
SELECT id_uppercat, MAX(`rank`)+1 AS next_rank
  FROM ' . Tables::categories() . '
  GROUP BY id_uppercat');
    }
}
