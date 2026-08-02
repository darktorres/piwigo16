<?php

declare(strict_types=1);

namespace Piwigo\Category;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\ORM\EntityRepository;
use Piwigo\Category\Projection\Category;
use Piwigo\Common\Dto\PaginatedResult;
use Piwigo\Common\ValueObject\CategoryId;
use Piwigo\Common\ValueObject\GroupId;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Db\BatchWriter;
use Piwigo\Db\SqlDialect;
use Piwigo\Db\Tables;
use Piwigo\Group\GroupAccessEntity;
use Piwigo\Group\UserGroupEntity;
use Piwigo\Permission\SqlCondition;

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

    public function findById(int $id): ?Category
    {
        $entity = $this->find($id);

        return $entity === null ? null : Category::fromEntity($entity);
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, array{id: int, name: string, permalink: ?string}> keyed by id
     *
     * Item 14 DQL audit: converted to real DQL -- single-table, static WHERE,
     * no join DQL can't express; id/name/permalink are plain types (no
     * custom Doctrine Type on CategoryEntity), so array hydration returns
     * ordinary scalars.
     */
    public function findNamesByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $rows = $this->createQueryBuilder('c')
            ->select('c.id', 'c.name', 'c.permalink')
            ->where('c.id IN (:ids)')
            ->setParameter('ids', $ids, ArrayParameterType::INTEGER)
            ->getQuery()
            ->getArrayResult();

        $byId = [];
        foreach ($rows as $row) {
            if (! is_array($row) || ! is_numeric($row['id'] ?? null)) {
                continue;
            }

            $byId[(int) $row['id']] = [
                'id' => (int) $row['id'],
                'name' => is_string($row['name'] ?? null) ? $row['name'] : '',
                'permalink' => is_string($row['permalink'] ?? null) ? $row['permalink'] : null,
            ];
        }

        return $byId;
    }

    /**
     * Every category's id/name/permalink, unfiltered -- HtmlService::
     * getCatDisplayNameCache()'s own breadcrumb-rendering cache warm-up.
     *
     * @return array<int, array{id: int, name: string, permalink: ?string}> keyed by id
     *
     * Item 14 DQL audit: converted to real DQL -- single-table, unconditional
     * select of plain-typed columns.
     */
    public function findAllIdNamePermalink(): array
    {
        $rows = $this->createQueryBuilder('c')
            ->select('c.id', 'c.name', 'c.permalink')
            ->getQuery()
            ->getArrayResult();

        $byId = [];
        foreach ($rows as $row) {
            if (! is_array($row) || ! is_numeric($row['id'] ?? null)) {
                continue;
            }

            $byId[(int) $row['id']] = [
                'id' => (int) $row['id'],
                'name' => is_string($row['name'] ?? null) ? $row['name'] : '',
                'permalink' => is_string($row['permalink'] ?? null) ? $row['permalink'] : null,
            ];
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
     *
     * Item 14 DQL audit: converted to real DQL -- single-table, id is the PK
     * so at most one row can match (no NonUniqueResultException risk, no
     * setMaxResults() needed); id/name/permalink are plain types.
     */
    public function findIdNamePermalinkById(int $id): ?array
    {
        $rows = $this->createQueryBuilder('c')
            ->select('c.id', 'c.name', 'c.permalink')
            ->where('c.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getArrayResult();

        if ($rows === []) {
            return null;
        }

        /** @var array{id: mixed, name: mixed, permalink: mixed} $row */
        $row = $rows[0];

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
     *
     * Item 14 DQL audit: stays on DBAL -- `REGEXP` is MySQL/MariaDB-specific
     * with no DQL equivalent.
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
     *
     * Item 14 DQL audit: stays on DBAL -- `old_permalinks` has no mapped
     * Entity anywhere in this migration, and the two queries are unioned in
     * PHP.
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
     *
     * Item 14 DQL audit: stays on DBAL -- see above (`old_permalinks`
     * unmapped, plus a self-referential `hit = hit + 1` SET expression).
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

    /**
     * Item 14 DQL audit: stays on DBAL -- joins `image_category`, never
     * entity-mapped anywhere in this migration, plus a caller-built
     * SqlCondition fragment and MySQL-specific `RAND()`.
     */
    public function findRandomImageId(int $catId, string $uppercats, bool $recursive, SqlCondition $condition): ?int
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
            ->where($scope)
            ->orderBy('RAND()')
            ->setMaxResults(1)
            ->setParameter('catId', $catId);
        self::applyCondition($qb, $condition);

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
     *
     * Item 14 DQL audit: stays on DBAL -- joins `image_category`/`images`
     * (never entity-mapped on the Category side, and `images` is owned by
     * the Image domain), a dynamic `SqlDialect::getRecentPeriodExpression()`
     * fragment inside the second JOIN's own ON condition, and a raw
     * `$forbiddenCategoriesCsv` NOT IN splice.
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
     *
     * Item 14 DQL audit: stays on DBAL -- joins `images`/`image_category`
     * (Image domain/unmapped), a caller-built SqlCondition fragment, and a
     * raw `CurrentConfig::orderBy()` ORDER BY fragment.
     */
    public function findImageIdsForCategories(
        array $catIds,
        string $mode,
        SqlCondition $condition
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
        self::applyCondition($qb, $condition);

        if ($mode === 'AND' && count($catIds) > 1) {
            $qb->having('COUNT(DISTINCT category_id) = :catCount')
                ->setParameter('catCount', count($catIds));
        }

        // CurrentConfig::orderBy() is always applied -- Item 3's own dead-
        // parameter cleanup found no real caller ever supplies a genuinely
        // different order, so there's nothing left to distinguish "no
        // override" from "current config order" for. Its own raw "ORDER BY
        // ..." SQL-fragment shape means QueryBuilder::orderBy() prepends its
        // own "ORDER BY " keyword, so the prefix must be stripped here or
        // the query becomes "ORDER BY ORDER BY ..." (a real syntax error,
        // caught live via CategoryServiceTest).
        $qb->orderBy(str_replace('ORDER BY ', '', \Piwigo\Config\CurrentConfig::orderBy()));

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
     *
     * Item 14 DQL audit: stays on DBAL -- joins `image_category`, never
     * entity-mapped anywhere in this migration, plus a caller-built
     * SqlCondition fragment.
     */
    public function findCommonCategories(array $itemIds, ?int $max, array $excludedCatIds, SqlCondition $condition): array
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
        self::applyCondition($qb, $condition);

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
     *
     * Item 14 DQL audit: converted to real DQL -- single-table, static WHERE,
     * all 6 columns plain-typed.
     */
    public function findCategoriesByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $rows = $this->createQueryBuilder('c')
            ->select('c.id', 'c.name', 'c.permalink', 'c.idUppercat AS id_uppercat', 'c.uppercats', 'c.globalRank AS global_rank')
            ->where('c.id IN (:ids)')
            ->setParameter('ids', $ids, ArrayParameterType::INTEGER)
            ->getQuery()
            ->getArrayResult();

        $result = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $result[] = [
                'id' => is_numeric($row['id'] ?? null) ? (int) $row['id'] : 0,
                'name' => is_string($row['name'] ?? null) ? $row['name'] : '',
                'permalink' => is_string($row['permalink'] ?? null) ? $row['permalink'] : null,
                'id_uppercat' => is_numeric($row['id_uppercat'] ?? null) ? (int) $row['id_uppercat'] : null,
                'uppercats' => is_string($row['uppercats'] ?? null) ? $row['uppercats'] : '',
                'global_rank' => is_string($row['global_rank'] ?? null) ? $row['global_rank'] : null,
            ];
        }

        return $result;
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
     *
     * Item 14 DQL audit: converted to real DQL -- fetches the full entity
     * (object hydration, same as {@see findById()}) instead of a `SELECT *`
     * DBAL row, and maps through {@see Category::fromEntity()} instead of
     * {@see Category::fromRow()}.
     */
    public function findFullCategoriesByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $entities = $this->createQueryBuilder('c')
            ->where('c.id IN (:ids)')
            ->setParameter('ids', $ids, ArrayParameterType::INTEGER)
            ->getQuery()
            ->getResult();

        return array_map(Category::fromEntity(...), $entities);
    }

    /**
     * @return list<int>
     *
     * Item 14 DQL audit: converted to real DQL -- single-table, static
     * WHERE; id is plain-typed, so getSingleColumnResult() returns ordinary
     * ints.
     */
    public function findCategoryIdsBySite(int $siteId): array
    {
        return array_values(array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $this->createQueryBuilder('c')
            ->select('c.id')
            ->where('c.siteId = :siteId')
            ->setParameter('siteId', $siteId)
            ->getQuery()
            ->getSingleColumnResult()));
    }

    /**
     * Item 14 DQL audit, re-corrected: `sites` *is* mapped ({@see
     * \Piwigo\Site\SiteEntity}), but converting this to DQL against it
     * would make `Category` (L2aCoreDomain) depend on `Site`
     * (L2bExtendedDomain) -- confirmed a real `deptrac` violation
     * (`DependsOnDisallowedLayer`), not a false positive: `deptrac.yaml`'s
     * ruleset only lets `L2aCoreDomain` depend downward on
     * `L1Infrastructure`/`L0Data`, never upward into `L2bExtendedDomain`.
     * Stays on DBAL for this real architectural-boundary reason, not a
     * missing-entity one.
     */
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
     *
     * Item 14 DQL audit: stays on DBAL -- queries `images`, a table owned
     * by the Image domain with no association declared on CategoryEntity
     * to it.
     */
    public function findStorageLinkedImageIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $imagesTable = Tables::images();

        return array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $this->getEntityManager()->getConnection()->executeQuery(<<<SQL
            SELECT id
            FROM {$imagesTable}
            WHERE storage_category_id IN (:ids)
            SQL
            , [
                'ids' => $ids,
            ], [
                'ids' => ArrayParameterType::INTEGER,
            ])->fetchFirstColumn());
    }

    /**
     * Category ids whose name and/or comment matches $pattern (already a
     * complete SQL LIKE pattern, e.g. '%word%') -- Further SQL-
     * modernization audit, Item 7: retargeted here from SearchRepository's
     * own generic findIdsByClause(), SearchService::searchAllwords()'s own
     * "all words" search feature (category-title/description match,
     * distinct from quick-search's separate token-based category lookup).
     *
     * @return list<int>
     *
     * Item 14 DQL audit: converted to real DQL -- single-table. The
     * dynamic name/comment OR is built as a plain string (both branches
     * share the same `:pattern` bind), not a loop-built `Expr\Orx`
     * composite -- sidesteps gotcha #2's phpstan-doctrine false positive
     * on dynamically-built composites.
     */
    public function findIdsByNameOrCommentLike(string $pattern, bool $matchName, bool $matchComment): array
    {
        if (! $matchName && ! $matchComment) {
            return [];
        }

        $clauses = [];
        if ($matchName) {
            $clauses[] = 'c.name LIKE :pattern';
        }

        if ($matchComment) {
            $clauses[] = 'c.comment LIKE :pattern';
        }

        $ids = $this->createQueryBuilder('c')
            ->select('c.id')
            ->where(implode(' OR ', $clauses))
            ->setParameter('pattern', $pattern)
            ->getQuery()
            ->getSingleColumnResult();

        return array_values(array_map(intval(...), array_filter($ids, is_numeric(...))));
    }

    /**
     * @param  list<int>  $ids
     * @return list<int>
     *
     * Item 14 DQL audit: stays on DBAL -- `image_category` has no mapped
     * Entity anywhere in this migration.
     */
    public function findDistinctLinkedImageIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $imageCategoryTable = Tables::imageCategory();

        return array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $this->getEntityManager()->getConnection()->executeQuery(<<<SQL
            SELECT
                DISTINCT(image_id)
            FROM {$imageCategoryTable}
            WHERE category_id IN (:ids)
            SQL
            , [
                'ids' => $ids,
            ], [
                'ids' => ArrayParameterType::INTEGER,
            ])->fetchFirstColumn());
    }

    /**
     * Image ids from $imageIds still linked to a category outside $excludeIds
     * -- used by delete_categories()'s "delete_orphans" mode to find images
     * that would become orphaned by dropping $excludeIds.
     *
     * @param  list<int>  $imageIds
     * @param  list<int>  $excludeIds
     * @return list<int>
     *
     * Item 14 DQL audit: stays on DBAL -- `image_category` has no mapped
     * Entity anywhere in this migration.
     */
    public function findNonOrphanImageIds(array $imageIds, array $excludeIds): array
    {
        if ($imageIds === []) {
            return [];
        }

        $imageCategoryTable = Tables::imageCategory();

        return array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $this->getEntityManager()->getConnection()->executeQuery(<<<SQL
            SELECT
                DISTINCT(image_id)
            FROM {$imageCategoryTable}
            WHERE image_id IN (:imageIds)
                AND category_id NOT IN (:excludeIds)
            SQL
            , [
                'imageIds' => $imageIds,
                'excludeIds' => $excludeIds,
            ], [
                'imageIds' => ArrayParameterType::INTEGER,
                'excludeIds' => ArrayParameterType::INTEGER,
            ])->fetchFirstColumn());
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
     *
     * Item 14 DQL audit: stays on DBAL -- `image_category` has no mapped
     * Entity anywhere in this migration.
     */
    public function findImageIdsOutsideCategories(array $excludeIds): array
    {
        $imageCategoryTable = Tables::imageCategory();

        return array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $this->getEntityManager()->getConnection()->executeQuery(<<<SQL
            SELECT
                image_id
            FROM
                {$imageCategoryTable}
            WHERE
                category_id
            NOT IN
                (:excludeIds)
            SQL
            , [
                'excludeIds' => $excludeIds,
            ], [
                'excludeIds' => ArrayParameterType::INTEGER,
            ])->fetchFirstColumn());
    }

    /**
     * @param  list<int>  $ids
     *
     * Item 14 DQL audit: stays on DBAL -- `image_category` has no mapped
     * Entity anywhere in this migration.
     */
    public function deleteImageCategoryLinksForCategories(array $ids): void
    {
        $imageCategoryTable = Tables::imageCategory();

        $this->getEntityManager()
            ->getConnection()
            ->executeStatement(<<<SQL
                DELETE FROM {$imageCategoryTable}
                WHERE category_id IN (:ids)
                SQL
                , [
                    'ids' => $ids,
                ], [
                    'ids' => ArrayParameterType::INTEGER,
                ]);
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
     *
     * Item 14 DQL audit: stays on DBAL -- `old_permalinks` has no mapped
     * Entity anywhere in this migration.
     */
    public function deleteOldPermalinksForCategories(array $ids): void
    {
        if ($ids === []) {
            return;
        }

        $oldPermalinksTable = Tables::oldPermalinks();

        $this->getEntityManager()
            ->getConnection()
            ->executeStatement(<<<SQL
                DELETE FROM {$oldPermalinksTable}
                WHERE cat_id IN (:ids)
                SQL
                , [
                    'ids' => $ids,
                ], [
                    'ids' => ArrayParameterType::INTEGER,
                ]);
    }

    /**
     * $whereCatsSql is a pre-built SQL fragment from the caller (e.g. `1=1`,
     * `c.id = :catId`, `c.id IN (:catIds)`) -- same "repository takes a
     * pre-built SQL fragment" shape this class already uses for permission
     * conditions; any real value it references is bound via $params/$types
     * rather than spliced.
     *
     * @param array<string, mixed> $params
     * @param array<string, ArrayParameterType|ParameterType> $types
     * @return list<int>
     *
     * Item 14 DQL audit: stays on DBAL -- $whereCatsSql is a caller-supplied
     * raw SQL fragment, and this joins `images` (Image domain, no
     * association from CategoryEntity).
     */
    public function findWrongRepresentativeCategoryIds(string $whereCatsSql, array $params = [], array $types = []): array
    {
        $categoriesTable = Tables::categories();
        $imagesTable = Tables::images();

        return array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $this->getEntityManager()->getConnection()->executeQuery(<<<SQL
            SELECT DISTINCT c.id
            FROM {$categoriesTable} AS c LEFT JOIN {$imagesTable} AS i
                ON c.representative_picture_id = i.id
            WHERE representative_picture_id IS NOT NULL
                AND {$whereCatsSql}
                AND i.id IS NULL
            SQL
            , $params, $types)->fetchFirstColumn());
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
    /**
     * @param array<string, mixed> $params
     * @param array<string, ArrayParameterType|ParameterType> $types
     * @return list<int>
     *
     * Item 14 DQL audit: stays on DBAL -- $whereCatsSql is a caller-supplied
     * raw SQL fragment, and this joins `image_category` (never
     * entity-mapped anywhere in this migration).
     */
    public function findCategoriesNeedingRandomRepresentative(string $whereCatsSql, array $params = [], array $types = []): array
    {
        $categoriesTable = Tables::categories();
        $imageCategoryTable = Tables::imageCategory();

        return array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $this->getEntityManager()->getConnection()->executeQuery(<<<SQL
            SELECT DISTINCT id
            FROM {$categoriesTable} INNER JOIN {$imageCategoryTable}
                ON id = category_id
            WHERE representative_picture_id IS NULL
                AND {$whereCatsSql}
            SQL
            , $params, $types)->fetchFirstColumn());
    }

    /**
     * @return list<string>
     *
     * Item 14 DQL audit: stays on DBAL -- $table/$column are dynamic
     * runtime table/column names, not a fixed DQL property path.
     */
    public function findOrphanedColumnValues(string $table, string $column): array
    {
        $categoriesTable = Tables::categories();

        return array_values(array_unique(array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '', $this->getEntityManager()->getConnection()->executeQuery(<<<SQL
            SELECT
                {$column}
            FROM {$table}
                LEFT JOIN {$categoriesTable} ON id = {$column}
            WHERE id IS NULL
            SQL)->fetchFirstColumn())));
    }

    /**
     * @param  list<int|string>  $values
     *
     * Item 14 DQL audit: stays on DBAL -- $table/$column are dynamic
     * runtime table/column names, same reason as
     * {@see findOrphanedColumnValues()} above.
     */
    public function deleteRowsWhereColumnIn(string $table, string $column, array $values): void
    {
        $this->getEntityManager()
            ->getConnection()
            ->executeStatement(<<<SQL
                DELETE
                FROM {$table}
                WHERE {$column} IN (:values)
                SQL
                , [
                    'values' => array_map(static fn (int|string $v): string => (string) $v, $values),
                ], [
                    'values' => ArrayParameterType::STRING,
                ]);
    }

    /**
     * id/id_uppercat/uppercats/rank/global_rank for every category, ordered
     * for {@see \Piwigo\Category\CategoryService::updateGlobalRank()}'s own
     * per-parent rank-numbering pass. `rank` (sibling order within a
     * parent, distinct from `global_rank`) is carried through purely for
     * that method's own rank-change detection.
     *
     * @return list<array{id: int, id_uppercat: ?int, uppercats: string, rank: ?int, global_rank: ?string}>
     *
     * Item 14 DQL audit: converted to real DQL -- single-table, unconditional
     * select/order, all columns plain-typed.
     */
    public function findCategoriesForRankUpdate(): array
    {
        $rows = $this->createQueryBuilder('c')
            ->select('c.id', 'c.idUppercat AS id_uppercat', 'c.uppercats', 'c.rank', 'c.globalRank AS global_rank')
            ->orderBy('c.idUppercat')
            ->addOrderBy('c.rank')
            ->addOrderBy('c.name')
            ->getQuery()
            ->getArrayResult();

        $result = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $result[] = [
                'id' => is_numeric($row['id'] ?? null) ? (int) $row['id'] : 0,
                'id_uppercat' => is_numeric($row['id_uppercat'] ?? null) ? (int) $row['id_uppercat'] : null,
                'uppercats' => is_string($row['uppercats'] ?? null) ? $row['uppercats'] : '',
                'rank' => is_numeric($row['rank'] ?? null) ? (int) $row['rank'] : null,
                'global_rank' => is_string($row['global_rank'] ?? null) ? $row['global_rank'] : null,
            ];
        }

        return $result;
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
     *
     * Item 14 DQL audit: converted to real DQL -- single-table, static
     * WHERE, both columns plain-typed.
     */
    public function findStatusByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $rows = $this->createQueryBuilder('c')
            ->select('c.id', 'c.status')
            ->where('c.id IN (:ids)')
            ->setParameter('ids', $ids, ArrayParameterType::INTEGER)
            ->getQuery()
            ->getArrayResult();

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
     *
     * Item 14 DQL audit: stays on DBAL -- $table/$field are dynamic runtime
     * table/column names, same reason as {@see findOrphanedColumnValues()}
     * above.
     */
    public function deleteInconsistentAccess(string $table, string $field, array $keepIds, array $catIds): void
    {
        $em = $this->getEntityManager();
        $em->getConnection()
            ->executeStatement(<<<SQL
                DELETE
                FROM {$table}
                WHERE {$field} NOT IN (:keepIds)
                    AND cat_id IN (:catIds)
                SQL
                , [
                    'keepIds' => $keepIds,
                    'catIds' => $catIds,
                ], [
                    'keepIds' => ArrayParameterType::INTEGER,
                    'catIds' => ArrayParameterType::INTEGER,
                ]);
        $em->clear();
    }

    /**
     * @param  array<int>  $ids  real callers don't guarantee a list
     * @return list<string>
     *
     * Item 14 DQL audit: converted to real DQL -- single-table, static
     * WHERE; uppercats is a plain string column, so getSingleColumnResult()
     * returns ordinary strings.
     */
    public function findUppercatsColumns(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return array_values(array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '', $this->createQueryBuilder('c')
            ->select('c.uppercats')
            ->where('c.id IN (:ids)')
            ->setParameter('ids', array_values($ids), ArrayParameterType::INTEGER)
            ->getQuery()
            ->getSingleColumnResult()));
    }

    /**
     * Same rows as {@see findUppercatsColumns()}, keyed by id instead of a
     * plain list -- CategoryAdminService::getCategoriesRefDate() needs to
     * look a specific category's uppercats back up by id while iterating.
     *
     * @param  list<int>  $ids
     * @return array<int, string> keyed by id
     *
     * Item 14 DQL audit: converted to real DQL -- single-table, static
     * WHERE; id/uppercats are both plain-typed. `fetchAllKeyValue()` has no
     * direct DQL equivalent, so the id=>uppercats map is built from
     * `getArrayResult()`'s own rows instead.
     */
    public function findUppercatsById(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $rows = $this->createQueryBuilder('c')
            ->select('c.id', 'c.uppercats')
            ->where('c.id IN (:ids)')
            ->setParameter('ids', $ids, ArrayParameterType::INTEGER)
            ->getQuery()
            ->getArrayResult();

        $byId = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $id = $row['id'] ?? null;
            $uppercats = $row['uppercats'] ?? null;
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
     *
     * Item 14 DQL audit: stays on DBAL -- $field/$minmax are dynamic
     * runtime column/function names, and this joins `image_category`/
     * `images` (unmapped/Image domain).
     */
    public function findRefDatesByCategoryIds(array $categoryIds, string $field, string $minmax): array
    {
        if ($categoryIds === []) {
            return [];
        }

        $imageCategoryTable = Tables::imageCategory();
        $imagesTable = Tables::images();

        $rows = $this->getEntityManager()
            ->getConnection()
            ->executeQuery(<<<SQL
                SELECT
                    category_id,
                    {$minmax}({$field}) as ref_date
                FROM {$imageCategoryTable}
                    JOIN {$imagesTable} ON image_id = id
                WHERE category_id IN (:categoryIds)
                GROUP BY category_id
                SQL
                , [
                    'categoryIds' => $categoryIds,
                ], [
                    'categoryIds' => ArrayParameterType::INTEGER,
                ])->fetchAllKeyValue();

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

    /**
     * Item 14 DQL audit: stays on DBAL -- `image_category` has no mapped
     * Entity anywhere in this migration, plus MySQL-specific `RAND()`.
     */
    public function findRandomImageIdInCategory(int $categoryId): ?int
    {
        $imageCategoryTable = Tables::imageCategory();
        $randomFunction = $this->randomFunction();

        $value = $this->getEntityManager()
            ->getConnection()
            ->executeQuery(<<<SQL
                SELECT image_id
                FROM {$imageCategoryTable}
                WHERE category_id = :categoryId
                ORDER BY {$randomFunction}()
                LIMIT 1
                SQL
                , [
                    'categoryId' => $categoryId,
                ])->fetchOne();

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
     *
     * Item 14 DQL audit: converted to real DQL -- single-table, static
     * WHERE; id/dir are both plain-typed. Builds the id=>dir map from
     * `getArrayResult()`'s own rows (no direct `fetchAllKeyValue()`
     * equivalent).
     */
    public function findCategoryDirsById(): array
    {
        $rows = $this->createQueryBuilder('c')
            ->select('c.id', 'c.dir')
            ->where('c.dir IS NOT NULL')
            ->getQuery()
            ->getArrayResult();

        $byId = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $id = $row['id'] ?? null;
            $dir = $row['dir'] ?? null;
            if (is_numeric($id) && is_string($dir)) {
                $byId[(int) $id] = $dir;
            }
        }

        return $byId;
    }

    /**
     * @return array<int|string, string> id => galleries_url, same
     *   numeric-string key caveat as {@see findCategoryDirsById()}
     *
     * Item 14 DQL audit, re-corrected: `sites` *is* mapped ({@see
     * \Piwigo\Site\SiteEntity}), but querying it from here would make
     * `Category` (L2aCoreDomain) depend on `Site` (L2bExtendedDomain) --
     * a real `deptrac` `DependsOnDisallowedLayer` violation, same
     * reasoning as {@see deleteSiteRow()} above. Stays on DBAL.
     */
    public function findSiteGalleriesUrls(): array
    {
        $sitesTable = Tables::sites();

        return array_filter($this->getEntityManager()->getConnection()->executeQuery(<<<SQL
            SELECT id, galleries_url
            FROM {$sitesTable}
            SQL)->fetchAllKeyValue(), is_string(...));
    }

    /**
     * @param  array<int>  $ids  real callers don't guarantee a list
     * @return list<array{id: int, uppercats: string, site_id: ?int}>
     *
     * Item 14 DQL audit: converted to real DQL -- single-table, static
     * WHERE, all 3 columns plain-typed.
     */
    public function findCategoriesForFulldirs(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $rows = $this->createQueryBuilder('c')
            ->select('c.id', 'c.uppercats', 'c.siteId AS site_id')
            ->where('c.dir IS NOT NULL')
            ->andWhere('c.id IN (:ids)')
            ->setParameter('ids', array_values($ids), ArrayParameterType::INTEGER)
            ->getQuery()
            ->getArrayResult();

        $result = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $result[] = [
                'id' => is_numeric($row['id'] ?? null) ? (int) $row['id'] : 0,
                'uppercats' => is_string($row['uppercats'] ?? null) ? $row['uppercats'] : '',
                'site_id' => is_numeric($row['site_id'] ?? null) ? (int) $row['site_id'] : null,
            ];
        }

        return $result;
    }

    /**
     * @return list<int>
     *
     * Item 14 DQL audit: stays on DBAL -- queries `images`, a table owned by
     * the Image domain with no association declared on CategoryEntity to it.
     */
    public function findDistinctStorageCategoryIds(): array
    {
        $imagesTable = Tables::images();

        return array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $this->getEntityManager()->getConnection()->executeQuery(<<<SQL
            SELECT DISTINCT(storage_category_id)
            FROM {$imagesTable}
            WHERE storage_category_id IS NOT NULL
            SQL)->fetchFirstColumn());
    }

    /**
     * Item 14 DQL audit: stays on DBAL -- writes `images` (Image domain
     * table, no association from CategoryEntity), and the SET expression is
     * a dynamic `SqlDialect::concat()` fragment, not a fixed property path.
     */
    public function updateImagePathsForCategory(int $categoryId, string $fulldir): void
    {
        $imagesTable = Tables::images();
        $pathExpr = SqlDialect::concat(['CONCAT(:fulldir, \'/\')', 'file']);

        $this->getEntityManager()
            ->getConnection()
            ->executeStatement(<<<SQL
                UPDATE {$imagesTable}
                SET path = {$pathExpr}
                WHERE storage_category_id = :categoryId
                SQL
                , [
                    'fulldir' => $fulldir,
                    'categoryId' => $categoryId,
                ]);
    }

    /**
     * Sets $categoryId's representative image -- Controller\
     * PictureController's own "set_as_representative" action. Caller is
     * responsible for clearing the EntityManager afterward (same contract as
     * before: a DQL bulk UPDATE also bypasses the identity map, so this
     * still doesn't call $em->clear() itself, matching every real caller's
     * own explicit clear() afterward).
     *
     * Item 14 DQL audit: converted to real DQL -- single-table, static
     * WHERE, fixed SET column.
     */
    public function setRepresentativeImage(int $categoryId, int $imageId): void
    {
        $this->getEntityManager()
            ->createQueryBuilder()
            ->update(CategoryEntity::class, 'c')
            ->set('c.representativePictureId', ':imageId')
            ->where('c.id = :categoryId')
            ->setParameter('imageId', $imageId)
            ->setParameter('categoryId', $categoryId)
            ->getQuery()
            ->execute();
    }

    /**
     * @param  array<int>  $ids  real callers don't guarantee a list
     * @return list<array{id: int, id_uppercat: ?int, status: string, uppercats: string}>
     *
     * Item 14 DQL audit: converted to real DQL -- single-table, static
     * WHERE, all 4 columns plain-typed.
     */
    public function findCategoriesForMove(array $ids): array
    {
        $rows = $this->createQueryBuilder('c')
            ->select('c.id', 'c.idUppercat AS id_uppercat', 'c.status', 'c.uppercats')
            ->where('c.id IN (:ids)')
            ->setParameter('ids', array_values($ids), ArrayParameterType::INTEGER)
            ->getQuery()
            ->getArrayResult();

        $result = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $result[] = [
                'id' => is_numeric($row['id'] ?? null) ? (int) $row['id'] : 0,
                'id_uppercat' => is_numeric($row['id_uppercat'] ?? null) ? (int) $row['id_uppercat'] : null,
                'status' => is_string($row['status'] ?? null) ? $row['status'] : '',
                'uppercats' => is_string($row['uppercats'] ?? null) ? $row['uppercats'] : '',
            ];
        }

        return $result;
    }

    /**
     * Item 14 DQL audit: converted to real DQL -- id is the PK, so this is
     * just $this->find() plus a property read (same idiom as
     * {@see findById()}/{@see updateImageOrder()} elsewhere in this class),
     * rather than a partial-column select.
     */
    public function findCategoryUppercatsById(int $id): ?string
    {
        return $this->find($id)?->uppercats;
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

    /**
     * Item 14 DQL audit: converted to real DQL -- id is the PK, same
     * $this->find()-based idiom as {@see findCategoryUppercatsById()} above.
     */
    public function findCategoryStatus(int $id): ?string
    {
        return $this->find($id)?->status;
    }

    /**
     * Item 14 DQL audit: converted to real DQL -- single-table, MAX() is a
     * standard DQL aggregate function. An aggregate with no GROUP BY always
     * yields exactly one row (NULL when nothing matches), so
     * getSingleScalarResult() can't throw NoResultException here.
     */
    public function findMaxRankForParent(int|string|null $parentId): ?int
    {
        // Matches the original's own empty($parent_id) semantics (null, 0,
        // '0', and '' all mean "no parent" / root level).
        $parentIsEmpty = $parentId === null || $parentId === 0 || $parentId === '0' || $parentId === '';

        $qb = $this->createQueryBuilder('c')
            ->select('MAX(c.rank)');

        if ($parentIsEmpty) {
            $qb->where('c.idUppercat IS NULL');
        } else {
            $qb->where('c.idUppercat = :parentId')
                ->setParameter('parentId', $parentId);
        }

        $value = $qb->getQuery()
            ->getSingleScalarResult();

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * @return array{id: int, uppercats: string, global_rank: string, visible: int, status: string}|null
     *
     * Item 14 DQL audit: converted to real DQL -- single-table, id is the
     * PK. `visible` is a real bool column on CategoryEntity now (DQL
     * hydrates it as bool, not the raw driver value the original DBAL
     * fetchAssociative() row shape assumed) -- cast back to int explicitly
     * to preserve this method's own documented `visible: int` contract.
     */
    public function findParentCategoryForCreate(int|string $parentId): ?array
    {
        $rows = $this->createQueryBuilder('c')
            ->select('c.id', 'c.uppercats', 'c.globalRank AS global_rank', 'c.visible', 'c.status')
            ->where('c.id = :parentId')
            ->setParameter('parentId', $parentId)
            ->getQuery()
            ->getArrayResult();

        if ($rows === []) {
            return null;
        }

        $row = $rows[0];
        if (! is_array($row)) {
            return null;
        }

        return [
            'id' => is_numeric($row['id'] ?? null) ? (int) $row['id'] : 0,
            'uppercats' => is_string($row['uppercats'] ?? null) ? $row['uppercats'] : '',
            'global_rank' => is_string($row['global_rank'] ?? null) ? $row['global_rank'] : '',
            'visible' => (bool) ($row['visible'] ?? false) ? 1 : 0,
            'status' => is_string($row['status'] ?? null) ? $row['status'] : '',
        ];
    }

    /**
     * Further SQL-modernization audit, Item 9: fetchCallerBuiltQuery() (a
     * fully generic "execute an already-built SELECT" escape hatch) deleted
     * outright -- every one of its 9 real call sites, read individually
     * across 6 files, is one of the typed methods below.
     *
     * Admin\CatOptionsPageRenderer's own "id,name,uppercats,global_rank
     * filtered by one boolean-ish column" shape, 3 of its 4 sections
     * (commentable/visible/status) -- the 4th (representative presence)
     * needs its own method below since its two branches aren't symmetric
     * (only the "no representative" branch joins image_category).
     *
     * Item 14 DQL audit: converted to real DQL, and inlined into each of
     * the 3 methods below individually (each column condition is a fixed,
     * within-class literal, not a caller-supplied fragment) -- the shared
     * `findIdNameUppercatsRankByCondition()` raw-SQL-fragment helper this
     * replaces is gone; nothing else called it.
     *
     * @return list<array<string, mixed>>
     */
    public function findByCommentable(bool $commentable): array
    {
        return self::narrowIdNameUppercatsRankRows($this->createQueryBuilder('c')
            ->select('c.id', 'c.name', 'c.uppercats', 'c.globalRank AS global_rank')
            ->where('c.commentable = :value')
            ->setParameter('value', $commentable)
            ->getQuery()
            ->getArrayResult());
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findByVisible(bool $visible): array
    {
        return self::narrowIdNameUppercatsRankRows($this->createQueryBuilder('c')
            ->select('c.id', 'c.name', 'c.uppercats', 'c.globalRank AS global_rank')
            ->where('c.visible = :value')
            ->setParameter('value', $visible)
            ->getQuery()
            ->getArrayResult());
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findByStatus(string $status): array
    {
        return self::narrowIdNameUppercatsRankRows($this->createQueryBuilder('c')
            ->select('c.id', 'c.name', 'c.uppercats', 'c.globalRank AS global_rank')
            ->where('c.status = :value')
            ->setParameter('value', $status)
            ->getQuery()
            ->getArrayResult());
    }

    /**
     * @param  array<mixed>  $rows
     * @return list<array<string, mixed>>
     */
    private static function narrowIdNameUppercatsRankRows(array $rows): array
    {
        $result = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $result[] = [
                'id' => $row['id'] ?? null,
                'name' => $row['name'] ?? null,
                'uppercats' => $row['uppercats'] ?? null,
                'global_rank' => $row['global_rank'] ?? null,
            ];
        }

        return $result;
    }

    /**
     * Admin\CatOptionsPageRenderer's own "representative" section --
     * genuinely asymmetric branches (only the "no representative" side
     * needs the image_category join + DISTINCT, to list every category
     * that owns at least one image but has none picked as representative
     * yet), not just true/false of the same predicate.
     *
     * @return list<array<string, mixed>>
     *
     * Item 14 DQL audit: stays on DBAL -- the $hasRepresentative=false
     * branch joins `image_category`, never entity-mapped anywhere in this
     * migration; the true branch alone would convert cleanly, but splitting
     * one query-building method's two branches across DQL and DBAL just to
     * convert half of it isn't worth the inconsistency.
     */
    public function findByRepresentativePresence(bool $hasRepresentative): array
    {
        $qb = $this->getEntityManager()
            ->getConnection()
            ->createQueryBuilder()
            ->select('id', 'name', 'uppercats', 'global_rank')
            ->from(Tables::categories());

        if ($hasRepresentative) {
            $qb->where('representative_picture_id IS NOT NULL');
        } else {
            $qb->distinct()
                ->innerJoin(Tables::categories(), Tables::imageCategory(), 'ic', 'id = ic.category_id')
                ->where('representative_picture_id IS NULL');
        }

        return $qb->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * Admin\UserPermPageRenderer's own "category options: authorized"
     * query_true -- private categories directly granted to $userId via
     * user_access, minus any category already covered by one of the
     * user's own group memberships ($groupAuthorizedCatIds).
     *
     * @param list<string> $groupAuthorizedCatIds
     * @return list<array<string, mixed>>
     *
     * Item 14 DQL audit: converted to real DQL -- `user_access` is mapped
     * ({@see UserAccessEntity}, no declared association to CategoryEntity,
     * so joined via an explicit `Join::WITH` condition, same shape as
     * {@see \Piwigo\Group\GroupRepository::getAccessibleCategoryIdsForUser()}'s
     * own precedent). UserAccessEntity's own userId/catId are plain ints
     * (no custom Doctrine Type), unlike GroupAccessEntity below.
     */
    public function findPrivateCategoriesGrantedToUser(int $userId, array $groupAuthorizedCatIds = []): array
    {
        $qb = $this->createQueryBuilder('c')
            ->select('c.id', 'c.name', 'c.uppercats', 'c.globalRank AS global_rank')
            ->innerJoin(UserAccessEntity::class, 'ua', \Doctrine\ORM\Query\Expr\Join::WITH, 'ua.catId = c.id')
            ->where('c.status = :status')
            ->andWhere('ua.userId = :userId')
            ->setParameter('status', 'private')
            ->setParameter('userId', $userId);

        if ($groupAuthorizedCatIds !== []) {
            $qb->andWhere($qb->expr()->notIn('ua.catId', ':groupAuthorized'))
                ->setParameter('groupAuthorized', $groupAuthorizedCatIds, ArrayParameterType::STRING);
        }

        return self::narrowIdNameUppercatsRankRows($qb->getQuery()->getArrayResult());
    }

    /**
     * Admin\GroupPermPageRenderer's own "category options: authorized"
     * query_true -- private categories directly granted to $groupId via
     * group_access. Same shape as findPrivateCategoriesGrantedToUser(),
     * joined via group_access instead -- groups have no "authorized via
     * another group" concept, so there's no exclusion-list parameter here.
     *
     * @return list<array<string, mixed>>
     *
     * Item 14 DQL audit: converted to real DQL -- `group_access` is mapped
     * ({@see GroupAccessEntity}), joined via an explicit `Join::WITH`
     * condition (same precedent as
     * {@see findPrivateCategoriesGrantedToUser()} above). The join condition
     * itself (`ga.catId = c.id`) compiles to a plain SQL `cat_id = id`
     * regardless of GroupAccessEntity's own custom Doctrine Types; only the
     * `ga.groupId = :groupId` parameter needs the {@see GroupId} VO wrapper
     * (the well-supported single-value bind case, not the IN-clause array
     * one).
     */
    public function findPrivateCategoriesGrantedToGroup(int $groupId): array
    {
        return self::narrowIdNameUppercatsRankRows($this->createQueryBuilder('c')
            ->select('c.id', 'c.name', 'c.uppercats', 'c.globalRank AS global_rank')
            ->innerJoin(GroupAccessEntity::class, 'ga', \Doctrine\ORM\Query\Expr\Join::WITH, 'ga.catId = c.id')
            ->where('c.status = :status')
            ->andWhere('ga.groupId = :groupId')
            ->setParameter('status', 'private')
            ->setParameter('groupId', GroupId::from($groupId))
            ->getQuery()
            ->getArrayResult());
    }

    /**
     * Admin\UserPermPageRenderer/GroupPermPageRenderer's own "category
     * options: not yet authorized" query_false -- every private category
     * except the given ids. Shared by both real callers: user_perm passes
     * its own directly-authorized ids merged with group-authorized ids,
     * group_perm passes just its own directly-authorized ids -- two
     * separate `NOT IN` clauses on the original pre-migration queries,
     * logically identical to one `NOT IN` over their union.
     *
     * @param list<string> $excludeCatIds
     * @return list<array<string, mixed>>
     *
     * Item 14 DQL audit: converted to real DQL -- single-table, static
     * WHERE plus an optional NOT IN.
     */
    public function findPrivateCategoriesExcluding(array $excludeCatIds): array
    {
        $qb = $this->createQueryBuilder('c')
            ->select('c.id', 'c.name', 'c.uppercats', 'c.globalRank AS global_rank')
            ->where('c.status = :status')
            ->setParameter('status', 'private');

        if ($excludeCatIds !== []) {
            $qb->andWhere($qb->expr()->notIn('c.id', ':excludeCatIds'))
                ->setParameter('excludeCatIds', $excludeCatIds, ArrayParameterType::STRING);
        }

        return self::narrowIdNameUppercatsRankRows($qb->getQuery()->getArrayResult());
    }

    /**
     * Controller\CommentsController's own "search by album" category
     * listing -- permission-filtered, no other condition.
     *
     * @return list<array<string, mixed>>
     *
     * Item 14 DQL audit: stays on DBAL -- takes a caller-built SqlCondition
     * fragment (same family as {@see applyCondition()}'s other callers).
     */
    public function findIdNameUppercatsRank(SqlCondition $condition): array
    {
        $qb = $this->getEntityManager()
            ->getConnection()
            ->createQueryBuilder()
            ->select('id', 'name', 'uppercats', 'global_rank')
            ->from(Tables::categories());

        self::applyCondition($qb, $condition);

        return $qb->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * Controller\Admin\PermalinksSubController's own category listing --
     * every category, `name` replaced with a display label indicating
     * whether it already has a permalink set.
     *
     * @return list<array<string, mixed>>
     *
     * Item 14 DQL audit: stays on DBAL -- `IF()` is MySQL-specific with no
     * DQL equivalent (DQL's `CASE WHEN ... END` isn't a drop-in text/
     * escaping match for it here).
     */
    public function findAllForPermalinksDisplay(): array
    {
        $categoriesTable = Tables::categories();

        return $this->getEntityManager()
            ->getConnection()
            ->executeQuery(<<<SQL
                SELECT
                  id, permalink,
                  CONCAT(id, " - ", name, IF(permalink IS NULL, "", " &radic;") ) AS name,
                  uppercats, global_rank
                FROM {$categoriesTable}
                SQL)
            ->fetchAllAssociative();
    }

    /**
     * Controller\Admin\SiteUpdateSubController's own per-site category
     * listing.
     *
     * @return list<array<string, mixed>>
     *
     * Item 14 DQL audit: converted to real DQL -- single-table, static
     * WHERE.
     */
    public function findIdNameUppercatsRankBySite(int $siteId): array
    {
        return self::narrowIdNameUppercatsRankRows($this->createQueryBuilder('c')
            ->select('c.id', 'c.name', 'c.uppercats', 'c.globalRank AS global_rank')
            ->where('c.siteId = :siteId')
            ->setParameter('siteId', $siteId)
            ->getQuery()
            ->getArrayResult());
    }

    /**
     * id stays mixed -- CategoryService::saveCategoriesOrder()'s own
     * $categories is raw request input (see that method's own docblock),
     * so id traces back to an unvalidated request element.
     *
     * @param array<int, array{id: mixed, rank: int}> $datas
     *
     * Item 14 DQL audit: not a DQL-vs-DBAL question -- bulk multi-row write
     * via BatchWriter, not something persist()/flush() (one row per flush)
     * expresses.
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
     *
     * Item 14 DQL audit: not a DQL-vs-DBAL question -- bulk write, same as
     * {@see massUpdateRanks()} above.
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
     *
     * Item 14 DQL audit: not a DQL-vs-DBAL question -- bulk write, same as
     * {@see massUpdateRanks()} above.
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
     *
     * Item 14 DQL audit: not a DQL-vs-DBAL question -- bulk write, same as
     * {@see massUpdateRanks()} above.
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
     *
     * Item 14 DQL audit: stays on DBAL -- dynamic caller-supplied
     * column=>value map, no fixed property path.
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
     * Bulk category insert -- Controller\Admin\SiteUpdateSubController's
     * own filesystem-sync "add every newly-discovered directory at once"
     * step. Same "dynamic column map" reasoning as insertCategory() above,
     * just batched.
     *
     * @param string[] $dbfields
     * @param array<int, array<string, mixed>> $inserts
     *
     * Item 14 DQL audit: not a DQL-vs-DBAL question -- bulk write with a
     * dynamic column set.
     */
    public function massInsertCategories(array $dbfields, array $inserts): void
    {
        if ($inserts === []) {
            return;
        }

        $em = $this->getEntityManager();
        new BatchWriter($em->getConnection())
            ->massInsert(Tables::categories(), $dbfields, $inserts);
        $em->clear();
    }

    /**
     * @param array<string, mixed> $data
     *
     * Item 14 DQL audit: stays on DBAL -- dynamic caller-supplied
     * column=>value map, same reason as {@see insertCategory()} above.
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
     * Same generic dynamic-field update as updateCategoryAfterInsert()
     * above, distinct name/call site -- Ws\PwgCategories::setInfo()'s own
     * name/comment edit, not a post-insert patch.
     *
     * @param array<string, mixed> $data
     *
     * Item 14 DQL audit: stays on DBAL -- dynamic caller-supplied
     * column=>value map, same reason as {@see insertCategory()} above.
     */
    public function updateFields(int $id, array $data): void
    {
        if ($data === []) {
            return;
        }

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
     *
     * Item 14 DQL audit: not a DQL-vs-DBAL question -- bulk write with an
     * INSERT IGNORE option ORM persist()/flush() has no equivalent for.
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
     * representative but does have sub-albums with images). $condition is
     * an already-built permission `SqlCondition`, same shape as every other
     * repository method here.
     *
     * Gap-closure Stage 4h (docs/plan/gap-closure-p0-p23.md): dropped the
     * `user_cache_categories` `INNER JOIN` -- a real, live regression this
     * fix closes, not just a modernization: gap-closure Stage 4g deleted
     * the only remaining writer of that table, so the JOIN's own
     * visibility filter had silently gone permanently empty for every user
     * (confirmed live: only 2 stale rows survived in the whole table).
     * The caller's own condition (built via
     * `PermissionService::getSqlConditionFandFAsCondition(['visible_categories' =>
     * 'id'])`) was *already* a live, correctly-scoped duplicate of
     * the exact same "is this category visible" check the JOIN provided
     * via a now-dead precomputed table -- removing the JOIN is not a
     * behavior change, the real filtering was already happening twice.
     * `$userId` is dropped too -- its only use was the JOIN's own
     * `user_id = :userId` condition.
     *
     * Item 14 DQL audit: stays on DBAL -- caller-built SqlCondition
     * fragment plus MySQL-specific `RAND()` (no ORDER BY random() DQL
     * equivalent).
     */
    public function findRandomRepresentativeIdAmongSubcategories(string $uppercats, SqlCondition $condition): ?string
    {
        $categoriesTable = Tables::categories();
        $dbRandomFunction = SqlDialect::DB_RANDOM_FUNCTION;
        $uppercatsLike = $uppercats . ',%';

        $conditionSql = $condition->isEmpty() ? '' : ' AND ' . $condition->sql;
        $params = array_merge([
            'uppercatsLike' => $uppercatsLike,
        ], $condition->parameters);
        $types = $condition->types;

        $value = $this->getEntityManager()
            ->getConnection()
            ->executeQuery(<<<SQL
                SELECT representative_picture_id
                FROM {$categoriesTable}
                WHERE uppercats LIKE :uppercatsLike
                    AND representative_picture_id IS NOT NULL
                {$conditionSql}
                ORDER BY {$dbRandomFunction}()
                LIMIT 1
                SQL
                , $params, $types)->fetchOne();

        // fetchOne() returns false (also a real is_scalar() value) to
        // signal "no rows matched" -- is_scalar() alone can't tell that
        // apart from a genuine representative_picture_id, so it must be
        // excluded explicitly first.
        return $value !== false && is_scalar($value) ? (string) $value : null;
    }

    /**
     * First/last photo creation date per category (`CategoryCatsRenderer`'s
     * "from/to" date-range display, gated by `CurrentConfig::displayFromto()`).
     * $condition is an already-built permission fragment, same shape as
     * {@see findRandomRepresentativeIdAmongSubcategories()}.
     *
     * @param  list<int>  $categoryIds
     * @return array<string, array{from: ?string, to: ?string}> keyed by category id
     *
     * Item 14 DQL audit: stays on DBAL -- joins `image_category`, never
     * entity-mapped anywhere in this migration, plus a caller-built
     * SqlCondition fragment.
     */
    public function findDateRangeByCategory(array $categoryIds, SqlCondition $condition): array
    {
        if ($categoryIds === []) {
            return [];
        }

        $imageCategoryTable = Tables::imageCategory();
        $imagesTable = Tables::images();

        $conditionSql = $condition->isEmpty() ? '' : ' AND ' . $condition->sql;
        $params = array_merge([
            'categoryIds' => $categoryIds,
        ], $condition->parameters);
        $types = array_merge([
            'categoryIds' => ArrayParameterType::INTEGER,
        ], $condition->types);

        $rows = $this->getEntityManager()
            ->getConnection()
            ->executeQuery(<<<SQL
                SELECT
                    category_id,
                    MIN(date_creation) AS `from`,
                    MAX(date_creation) AS `to`
                FROM {$imageCategoryTable}
                    INNER JOIN {$imagesTable} ON image_id = id
                WHERE category_id IN (:categoryIds)
                {$conditionSql}
                GROUP BY category_id
                SQL
                , $params, $types)->fetchAllAssociative();

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

    /**
     * Item 14 DQL audit: converted to real DQL -- single-table,
     * unconditional COUNT.
     */
    public function countAllCategories(): int
    {
        $value = $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->getQuery()
            ->getSingleScalarResult();

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * Ids of categories with no physical directory (virtual) or with one
     * (physical) -- Admin\BatchManager\FilterResolver's own
     * "no_virtual_album" prefilter. Id-only sibling of countByDirNull()
     * below.
     *
     * @return list<int>
     *
     * Item 14 DQL audit: converted to real DQL -- single-table; $dirIsNull
     * toggles between two fixed DQL conditions (not a dynamic column name).
     */
    public function findIdsByDirNull(bool $dirIsNull): array
    {
        $qb = $this->createQueryBuilder('c')
            ->select('c.id');
        $qb->where($dirIsNull ? 'c.dir IS NULL' : 'c.dir IS NOT NULL');

        return array_values(array_map(
            static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0,
            $qb->getQuery()
                ->getSingleColumnResult()
        ));
    }

    /**
     * Count of categories with no physical directory (virtual) or with
     * one (physical) -- Ws\PwgCore::getInfos()'s own "nb_virtual"/
     * "nb_physical" summary figures.
     *
     * Item 14 DQL audit: converted to real DQL -- same reasoning as
     * {@see findIdsByDirNull()} above.
     */
    public function countByDirNull(bool $dirIsNull): int
    {
        $qb = $this->createQueryBuilder('c')
            ->select('COUNT(c.id)');
        $qb->where($dirIsNull ? 'c.dir IS NULL' : 'c.dir IS NOT NULL');

        $value = $qb->getQuery()
            ->getSingleScalarResult();

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * Count of categories whose `visible` matches $visible --
     * Controller\Admin\IntroSubController's own "locked album" dashboard
     * warning. `visible` is a real bool column now, not the legacy
     * `enum('true','false')` string the original inline query's own
     * `WHERE visible = 'false'` predated (see Comment's own commentable/
     * validated retype for the same bug class).
     *
     * Item 14 DQL audit: converted to real DQL -- single-table, static
     * WHERE against the real bool column.
     */
    public function countByVisible(bool $visible): int
    {
        $value = $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->where('c.visible = :visible')
            ->setParameter('visible', $visible)
            ->getQuery()
            ->getSingleScalarResult();

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * Ids of every category that uses $imageId as its own representative
     * picture -- Admin\PictureModifyPageRenderer's own "which albums does
     * this photo currently represent" lookup.
     *
     * @return list<int>
     *
     * Item 14 DQL audit: converted to real DQL -- single-table, static
     * WHERE.
     */
    public function findCategoryIdsRepresentedByImage(int $imageId): array
    {
        return array_values(array_map(
            static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0,
            $this->createQueryBuilder('c')
                ->select('c.id')
                ->where('c.representativePictureId = :imageId')
                ->setParameter('imageId', $imageId)
                ->getQuery()
                ->getSingleColumnResult()
        ));
    }

    /**
     * Bulk variant of setRepresentativeImage() above -- Admin\
     * PictureModifyPageRenderer's own "make this photo the thumbnail for
     * every newly-checked album" step.
     *
     * @param list<int> $categoryIds
     *
     * Item 14 DQL audit: converted to real DQL -- single-table, static SET/
     * WHERE. Same "caller clears the EntityManager afterward" contract as
     * {@see setRepresentativeImage()} above (both real callers already do).
     */
    public function setRepresentativeImageForCategories(array $categoryIds, int $imageId): void
    {
        if ($categoryIds === []) {
            return;
        }

        $this->getEntityManager()
            ->createQueryBuilder()
            ->update(CategoryEntity::class, 'c')
            ->set('c.representativePictureId', ':imageId')
            ->where('c.id IN (:categoryIds)')
            ->setParameter('imageId', $imageId)
            ->setParameter('categoryIds', $categoryIds, ArrayParameterType::INTEGER)
            ->getQuery()
            ->execute();
    }

    /**
     * Private category ids already granted to $groupId --
     * Admin\GroupPermPageRenderer's own "already-authorized" set, used to
     * compute which private categories still need granting.
     *
     * @return list<int>
     *
     * Item 14 DQL audit: converted to real DQL -- `group_access` is mapped
     * ({@see GroupAccessEntity}), joined via explicit `Join::WITH` (same
     * precedent as {@see findPrivateCategoriesGrantedToGroup()} above).
     * Only `c.id` is selected (plain int, not `ga.catId`), so this avoids
     * the custom-Doctrine-Type array-hydration question entirely.
     */
    public function findPrivateCategoryIdsGrantedToGroup(int $groupId): array
    {
        return array_values(array_map(
            static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0,
            $this->createQueryBuilder('c')
                ->select('c.id')
                ->innerJoin(GroupAccessEntity::class, 'ga', \Doctrine\ORM\Query\Expr\Join::WITH, 'ga.catId = c.id')
                ->where('c.status = :status')
                ->andWhere('ga.groupId = :groupId')
                ->setParameter('status', 'private')
                ->setParameter('groupId', GroupId::from($groupId))
                ->getQuery()
                ->getSingleColumnResult()
        ));
    }

    /**
     * Categories $userId is authorized for via group membership (not
     * direct grants) -- Admin\UserPermPageRenderer's own "authorized
     * because of a group" display, deduplicated across every group the
     * user belongs to.
     *
     * @return list<array<string, mixed>>
     *
     * Item 14 DQL audit: converted to real DQL -- `user_group`/
     * `group_access` are both mapped ({@see UserGroupEntity}/
     * {@see GroupAccessEntity}), chained via two explicit `Join::WITH`
     * conditions. Selects `c.id AS cat_id` (plain int off CategoryEntity)
     * rather than `ga.catId` (a custom-typed CategoryId VO) -- the real
     * caller (Admin\UserPermPageRenderer) narrows `cat_id` with
     * `is_int()/is_string()`, which a VO instance would silently fail,
     * gotcha #1 from the Item 14 audit's own pilot.
     */
    public function findCategoriesAuthorizedViaGroupsForUser(int $userId): array
    {
        $rows = $this->getEntityManager()
            ->createQueryBuilder()
            ->select('c.id AS cat_id', 'c.uppercats', 'c.globalRank AS global_rank')
            ->distinct()
            ->from(UserGroupEntity::class, 'ug')
            ->innerJoin(GroupAccessEntity::class, 'ga', \Doctrine\ORM\Query\Expr\Join::WITH, 'ug.groupId = ga.groupId')
            ->innerJoin(CategoryEntity::class, 'c', \Doctrine\ORM\Query\Expr\Join::WITH, 'c.id = ga.catId')
            ->where('ug.userId = :userId')
            ->setParameter('userId', UserId::from($userId))
            ->getQuery()
            ->getArrayResult();

        $result = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $result[] = [
                'cat_id' => $row['cat_id'] ?? null,
                'uppercats' => $row['uppercats'] ?? null,
                'global_rank' => $row['global_rank'] ?? null,
            ];
        }

        return $result;
    }

    /**
     * Private categories directly granted to $userId, optionally excluding
     * $excludeCategoryIds (categories already authorized via group
     * membership) -- Admin\UserPermPageRenderer's own "authorized
     * individually" listing.
     *
     * @param list<int> $excludeCategoryIds
     * @return list<int>
     *
     * Item 14 DQL audit: converted to real DQL -- `user_access` is mapped
     * ({@see UserAccessEntity}), joined via explicit `Join::WITH`. Only
     * `c.id` is selected (plain int), same reasoning as
     * {@see findPrivateCategoryIdsGrantedToGroup()} above.
     */
    public function findPrivateCategoryIdsGrantedToUser(int $userId, array $excludeCategoryIds): array
    {
        $qb = $this->createQueryBuilder('c')
            ->select('c.id')
            ->innerJoin(UserAccessEntity::class, 'ua', \Doctrine\ORM\Query\Expr\Join::WITH, 'ua.catId = c.id')
            ->where('c.status = :status')
            ->andWhere('ua.userId = :userId')
            ->setParameter('status', 'private')
            ->setParameter('userId', $userId);

        if ($excludeCategoryIds !== []) {
            $qb->andWhere($qb->expr()->notIn('ua.catId', ':excludeCategoryIds'))
                ->setParameter('excludeCategoryIds', $excludeCategoryIds, ArrayParameterType::INTEGER);
        }

        return array_values(array_map(
            static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0,
            $qb->getQuery()
                ->getSingleColumnResult()
        ));
    }

    /**
     * Every non-null category permalink -- Admin\ExtendForTemplatesPageRenderer's
     * own "selective URLs keyword" list.
     *
     * @return list<string>
     *
     * Item 14 DQL audit: converted to real DQL -- single-table, static
     * WHERE; permalink is plain-typed.
     */
    public function findActivePermalinks(): array
    {
        return array_values(array_map(
            static fn (mixed $v): string => is_scalar($v) ? (string) $v : '',
            $this->createQueryBuilder('c')
                ->select('c.permalink')
                ->where('c.permalink IS NOT NULL')
                ->getQuery()
                ->getSingleColumnResult()
        ));
    }

    /**
     * Direct children of $parentId (or every root category when null),
     * ordered by rank -- Admin\CatListPageRenderer's own album listing.
     *
     * @return list<array<string, mixed>>
     *
     * Item 14 DQL audit: converted to real DQL -- single-table; $parentId
     * toggles between two fixed DQL conditions (not a dynamic column name).
     */
    public function findChildrenOfParent(?int $parentId): array
    {
        $qb = $this->createQueryBuilder('c')
            ->select('c.id', 'c.name', 'c.permalink', 'c.dir', 'c.rank', 'c.status')
            ->orderBy('c.rank', 'ASC');

        if ($parentId === null) {
            $qb->where('c.idUppercat IS NULL');
        } else {
            $qb->where('c.idUppercat = :parentId')
                ->setParameter('parentId', $parentId);
        }

        $result = [];
        foreach ($qb->getQuery()->getArrayResult() as $row) {
            if (! is_array($row)) {
                continue;
            }

            $result[] = [
                'id' => $row['id'] ?? null,
                'name' => $row['name'] ?? null,
                'permalink' => $row['permalink'] ?? null,
                'dir' => $row['dir'] ?? null,
                'rank' => $row['rank'] ?? null,
                'status' => $row['status'] ?? null,
            ];
        }

        return $result;
    }

    /**
     * Photo count per category (every category that owns at least one
     * direct image_category link) -- Admin\CatListPageRenderer's own
     * per-album photo count display.
     *
     * @return array<int, int> keyed by category_id
     *
     * Item 14 DQL audit: stays on DBAL -- `image_category` has no mapped
     * Entity anywhere in this migration.
     */
    public function findPhotoCountsByCategory(): array
    {
        $imageCategoryTable = Tables::imageCategory();

        $rows = $this->getEntityManager()
            ->getConnection()
            ->fetchAllAssociative(<<<SQL
                SELECT
                    category_id,
                    COUNT(*) AS nb_photos
                FROM {$imageCategoryTable}
                GROUP BY category_id
                SQL);

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
     *
     * Item 14 DQL audit: converted to real DQL -- single-table,
     * unconditional select, both columns plain-typed.
     */
    public function findAllCategoryUppercats(): array
    {
        $rows = $this->createQueryBuilder('c')
            ->select('c.id', 'c.uppercats')
            ->getQuery()
            ->getArrayResult();

        $byId = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $id = $row['id'] ?? null;
            if (is_int($id) || is_string($id)) {
                $byId[$id] = $row['uppercats'] ?? null;
            }
        }

        return $byId;
    }

    /**
     * Bare ids of $parentId's direct children (or every root category when
     * null) -- Admin\AlbumsPageRenderer's own auto-order id resolution, a
     * narrower-column sibling of findChildrenOfParent() above (that one
     * also selects name/permalink/dir/rank/status and orders by rank).
     *
     * @return list<int>
     *
     * Item 14 DQL audit: converted to real DQL -- single-table; same
     * $parentId toggle as {@see findChildrenOfParent()} above.
     */
    public function findIdsByParent(?int $parentId): array
    {
        $qb = $this->createQueryBuilder('c')
            ->select('c.id');

        if ($parentId === null) {
            $qb->where('c.idUppercat IS NULL');
        } else {
            $qb->where('c.idUppercat = :parentId')
                ->setParameter('parentId', $parentId);
        }

        return array_values(array_map(
            static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0,
            $qb->getQuery()
                ->getSingleColumnResult()
        ));
    }

    /**
     * id/name/id_uppercat for $categoryIds -- Admin\AlbumsPageRenderer's
     * own auto-order sort-and-save step.
     *
     * @param list<string> $categoryIds
     * @return list<array<string, mixed>>
     *
     * Item 14 DQL audit: converted to real DQL -- single-table, static
     * WHERE, all 3 columns plain-typed.
     */
    public function findIdsNamesUppercatsForIds(array $categoryIds): array
    {
        $rows = $this->createQueryBuilder('c')
            ->select('c.id', 'c.name', 'c.idUppercat AS id_uppercat')
            ->where('c.id IN (:categoryIds)')
            ->setParameter('categoryIds', $categoryIds, ArrayParameterType::STRING)
            ->getQuery()
            ->getArrayResult();

        $result = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $result[] = [
                'id' => $row['id'] ?? null,
                'name' => $row['name'] ?? null,
                'id_uppercat' => $row['id_uppercat'] ?? null,
            ];
        }

        return $result;
    }

    /**
     * Every category's id/name/rank/status/visible/uppercats/lastmodified
     * -- Admin\AlbumsPageRenderer's own full album-tree listing.
     *
     * @return list<array<string, mixed>>
     *
     * Item 14 DQL audit: converted to real DQL -- single-table,
     * unconditional select, all columns plain-typed.
     */
    public function findAllForAlbumTree(): array
    {
        $rows = $this->createQueryBuilder('c')
            ->select('c.id', 'c.name', 'c.rank', 'c.status', 'c.visible', 'c.uppercats', 'c.lastmodified')
            ->getQuery()
            ->getArrayResult();

        $result = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $result[] = [
                'id' => $row['id'] ?? null,
                'name' => $row['name'] ?? null,
                'rank' => $row['rank'] ?? null,
                'status' => $row['status'] ?? null,
                'visible' => $row['visible'] ?? null,
                'uppercats' => $row['uppercats'] ?? null,
                'lastmodified' => $row['lastmodified'] ?? null,
            ];
        }

        return $result;
    }

    /**
     * Whether $categoryId has at least one direct image link --
     * Admin\CatModifyPageRenderer's own "has_images" flag.
     *
     * Item 14 DQL audit: stays on DBAL -- `image_category` has no mapped
     * Entity anywhere in this migration.
     */
    public function hasImages(int $categoryId): bool
    {
        $imageCategoryTable = Tables::imageCategory();

        $rows = $this->getEntityManager()
            ->getConnection()
            ->fetchAllAssociative(<<<SQL
                SELECT DISTINCT category_id
                FROM {$imageCategoryTable}
                WHERE category_id = :category_id
                LIMIT 1
                SQL
                , [
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
     *
     * Item 14 DQL audit: stays on DBAL -- joins `images`/`image_category`
     * (Image domain/unmapped), uses MySQL's `DATE()` function, and returns
     * a positional (`fetchNumeric()`) row shape DQL's named field selects
     * don't produce.
     */
    public function findPhotoCountAndDateRange(int $categoryId): array|false
    {
        $imagesTable = Tables::images();
        $imageCategoryTable = Tables::imageCategory();

        return $this->getEntityManager()
            ->getConnection()
            ->fetchNumeric(<<<SQL
                SELECT
                    COUNT(image_id),
                    MIN(DATE(date_available)),
                    MAX(DATE(date_available))
                FROM {$imagesTable}
                    JOIN {$imageCategoryTable} ON image_id = id
                WHERE category_id = :categoryId
                SQL
                , [
                    'categoryId' => $categoryId,
                ]);
    }

    /**
     * Distinct image ids across every id in $categoryIds -- Admin\
     * CatModifyPageRenderer's own recursive (including sub-albums) photo
     * count.
     *
     * @param list<int> $categoryIds
     * @return list<int>
     *
     * Item 14 DQL audit: stays on DBAL -- `image_category` has no mapped
     * Entity anywhere in this migration.
     */
    public function findDistinctImageIdsInCategories(array $categoryIds): array
    {
        $imageCategoryTable = Tables::imageCategory();

        return array_map(
            static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0,
            array_column($this->getEntityManager()
                ->getConnection()
                ->fetchAllAssociative(<<<SQL
                    SELECT DISTINCT
                        (image_id)
                    FROM
                        {$imageCategoryTable}
                    WHERE
                        category_id IN (:categoryIds)
                    SQL
                    , [
                        'categoryIds' => $categoryIds,
                    ], [
                        'categoryIds' => ArrayParameterType::INTEGER,
                    ]), 'image_id')
        );
    }

    /**
     * `dir` keyed by id for $ids -- Admin\CatModifyPageRenderer's own
     * getLocalDir() path-segment resolution.
     *
     * @param list<int|string> $ids
     * @return array<int|string, mixed> keyed by id
     *
     * Item 14 DQL audit: converted to real DQL -- single-table, static
     * WHERE, both columns plain-typed.
     */
    public function findDirsByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $rows = $this->createQueryBuilder('c')
            ->select('c.id', 'c.dir')
            ->where('c.id IN (:ids)')
            ->setParameter('ids', array_map(static fn (int|string $v): string => (string) $v, $ids), ArrayParameterType::STRING)
            ->getQuery()
            ->getArrayResult();

        $byId = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $id = $row['id'] ?? null;
            if (is_int($id) || is_string($id)) {
                $byId[$id] = $row['dir'] ?? null;
            }
        }

        return $byId;
    }

    /**
     * $categoryId's own site's galleries_url, via the site_id FK join --
     * Admin\CatModifyPageRenderer's own getSiteUrl().
     *
     * Item 14 DQL audit, re-corrected: `sites` *is* mapped ({@see
     * \Piwigo\Site\SiteEntity}), but joining it from here would make
     * `Category` (L2aCoreDomain) depend on `Site` (L2bExtendedDomain) --
     * a real `deptrac` `DependsOnDisallowedLayer` violation, same
     * reasoning as {@see deleteSiteRow()} above. Stays on DBAL.
     */
    public function findGalleriesUrlForCategory(int|string $categoryId): ?string
    {
        $sitesTable = Tables::sites();
        $categoriesTable = Tables::categories();

        $row = $this->getEntityManager()
            ->getConnection()
            ->fetchAssociative(<<<SQL
                SELECT galleries_url
                FROM {$sitesTable} AS s,{$categoriesTable} AS c
                WHERE s.id = c.site_id
                    AND c.id = :categoryId
                SQL
                , [
                    'categoryId' => $categoryId,
                ]);

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
     *
     * Item 14 DQL audit: stays on DBAL -- $orderBySql is a caller-supplied
     * raw "ORDER BY ..." fragment, not a fixed DQL property path.
     */
    public function findActivePermalinksList(string $orderBySql): array
    {
        $categoriesTable = Tables::categories();

        return $this->getEntityManager()
            ->getConnection()
            ->fetchAllAssociative(<<<SQL
                SELECT id, permalink, uppercats, global_rank
                FROM {$categoriesTable}
                WHERE permalink IS NOT NULL
                {$orderBySql}
                SQL);
    }

    /**
     * Whether $catId exists and isn't among $forbiddenCategoriesCsv --
     * Controller\SearchController's own "does this album exist and is it
     * accessible" check.
     *
     * Item 14 DQL audit: converted to real DQL -- single-table, static
     * WHERE; $forbiddenIds is a plain int list computed in PHP before the
     * query runs, not a raw fragment.
     */
    public function existsAndNotForbidden(int $catId, string $forbiddenCategoriesCsv): bool
    {
        $forbiddenIds = array_map(intval(...), array_filter(explode(',', $forbiddenCategoriesCsv), is_numeric(...)));
        if ($forbiddenIds === []) {
            $forbiddenIds = [0];
        }

        $rows = $this->createQueryBuilder('c')
            ->select('c.id')
            ->where('c.id = :catId')
            ->andWhere('c.id NOT IN (:forbiddenIds)')
            ->setParameter('catId', $catId)
            ->setParameter('forbiddenIds', $forbiddenIds, ArrayParameterType::INTEGER)
            ->getQuery()
            ->getArrayResult();

        return $rows !== [];
    }

    /**
     * Whether a category with this id exists -- Ws\PwgCategories'
     * setRepresentative()'s own existence check.
     *
     * Item 14 DQL audit: converted to real DQL -- single-table, static
     * WHERE, COUNT aggregate.
     */
    public function existsById(int $id): bool
    {
        $value = $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->where('c.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getSingleScalarResult();

        return is_numeric($value) && (int) $value > 0;
    }

    /**
     * Ids from $ids that really exist -- Ws\PwgCategories' own "do these
     * categories really exist" checks (getImages()/delete()).
     *
     * @param  list<int>  $ids
     * @return list<int>
     *
     * Item 14 DQL audit: converted to real DQL -- single-table, static
     * WHERE.
     */
    public function findExistingIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return array_values(array_map(
            static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0,
            $this->createQueryBuilder('c')
                ->select('c.id')
                ->where('c.id IN (:ids)')
                ->setParameter('ids', $ids, ArrayParameterType::INTEGER)
                ->getQuery()
                ->getSingleColumnResult()
        ));
    }

    /**
     * id/image_order for categories matching already-built $conditions --
     * Ws\PwgCategories::getImages()'s own "which categories are we
     * fetching images for" step.
     *
     * @param  list<SqlCondition>  $conditions
     * @return list<array{id: int, image_order: ?string}>
     *
     * Item 14 DQL audit: stays on DBAL -- $conditions is a list of
     * caller-built SqlCondition fragments (combined via
     * `SqlCondition::combine()`), same family as `applyCondition()`'s other
     * callers.
     */
    public function findIdsAndImageOrderWithConditions(array $conditions): array
    {
        $categoriesTable = Tables::categories();
        $combined = SqlCondition::combine('AND', ...$conditions);

        $rows = $this->getEntityManager()
            ->getConnection()
            ->fetchAllAssociative(<<<SQL
                SELECT
                    id,
                    image_order
                FROM {$categoriesTable}
                WHERE {$combined->sql}
                SQL
                , $combined->parameters, $combined->types);

        return array_map(
            static fn (array $row): array => [
                'id' => is_numeric($row['id']) ? (int) $row['id'] : 0,
                'image_order' => is_string($row['image_order'] ?? null) ? $row['image_order'] : null,
            ],
            $rows
        );
    }

    /**
     * The scope condition shared by findListForWs()/findAdminListForWs()
     * below -- exactly one of 3 mutually-exclusive branches (non-recursive
     * with a real $catId / non-recursive without / recursive with a real
     * $catId), or none at all (recursive with no $catId -- matches the
     * original, which added nothing to $where in that case).
     */
    private static function categoryScopeCondition(?int $catId, bool $recursive): SqlCondition
    {
        if (! $recursive) {
            if ($catId !== null && $catId > 0) {
                return new SqlCondition('(id_uppercat = :catId OR id = :catId)', [
                    'catId' => $catId,
                ]);
            }

            return new SqlCondition('id_uppercat IS NULL');
        }

        if ($catId !== null && $catId > 0) {
            return new SqlCondition('uppercats ' . SqlDialect::DB_REGEX_OPERATOR . ' :catUppercatsLike', [
                'catUppercatsLike' => '(^|,)' . $catId . '(,|$)',
            ]);
        }

        return new SqlCondition('');
    }

    /**
     * Further SQL-modernization audit, Item 13: Ws\PwgCategories::
     * getList()'s own paginated category rollup -- $whereClauses (a
     * caller-built `list<string>` of trusted SQL fragments) replaced with
     * a typed CategoryListCriteria; this method now builds its own scope/
     * forbidden-categories/public-only conditions internally via
     * {@see categoryScopeCondition()} and SqlCondition::combine().
     * $searchTerm/$searchLimit/$limit/$limitPlusOne replicate the
     * original's own conditional LIMIT logic verbatim: a search term gets
     * its own LIMIT only when no explicit $limit is requested; $limit
     * itself gets +1 when $limitPlusOne (single-category scope), to detect
     * "more remain" without a second query. FOUND_ROWS() is only fetched
     * when $limit !== null, matching the original's own guard.
     *
     * @return PaginatedResult<array<string, mixed>>
     *
     * Item 14 DQL audit: stays on DBAL -- `SQL_CALC_FOUND_ROWS`/
     * `FOUND_ROWS()` are MySQL-specific with no DQL equivalent, plus a
     * caller-conditioned SqlCondition combination.
     */
    public function findListForWs(
        CategoryListCriteria $criteria,
        ?string $searchTerm,
        int $searchLimit,
        ?int $limit,
        bool $limitPlusOne
    ): PaginatedResult {
        $conn = $this->getEntityManager()
            ->getConnection();

        $conditions = [self::categoryScopeCondition($criteria->catId, $criteria->recursive)];

        if ($criteria->forbiddenCategoryIds !== []) {
            $conditions[] = new SqlCondition('id NOT IN (:forbiddenCategoryIds)', [
                'forbiddenCategoryIds' => $criteria->forbiddenCategoryIds,
            ], [
                'forbiddenCategoryIds' => ArrayParameterType::INTEGER,
            ]);
        }

        if ($criteria->publicOnly) {
            $conditions[] = new SqlCondition('status = "public" AND visible = 1');
        }

        $combined = SqlCondition::combine('AND', new SqlCondition('1=1'), ...$conditions);
        $params = $combined->parameters;
        $types = $combined->types;

        $categoriesTable = Tables::categories();

        $sql = <<<SQL
            SELECT SQL_CALC_FOUND_ROWS
                id, name, comment, permalink, status,
                uppercats, global_rank, id_uppercat,
                representative_picture_id,
                image_order
            FROM {$categoriesTable}
            WHERE {$combined->sql}
            SQL;

        if ($searchTerm !== null) {
            $sql .= <<<SQL

                AND name LIKE :searchTerm
                SQL;
            $params['searchTerm'] = '%' . $searchTerm . '%';
            if ($limit === null) {
                $sql .= ' LIMIT :searchLimit';
                $params['searchLimit'] = $searchLimit;
                $types['searchLimit'] = ParameterType::INTEGER;
            }
        }

        if ($limit !== null) {
            $sql .= <<<SQL

                ORDER BY `rank` ASC
                LIMIT :effectiveLimit
                SQL;
            $params['effectiveLimit'] = $limit + ($limitPlusOne ? 1 : 0);
            $types['effectiveLimit'] = ParameterType::INTEGER;
        }

        $rows = $conn->fetchAllAssociative($sql, $params, $types);

        $total = null;
        if ($limit !== null) {
            $totalRaw = $conn->fetchOne(<<<SQL
                SELECT FOUND_ROWS()
                SQL);
            $total = is_numeric($totalRaw) ? (int) $totalRaw : 0;
        }

        return new PaginatedResult($rows, $total);
    }

    /**
     * Further SQL-modernization audit, Item 13: Ws\PwgCategories::
     * getAdminList()'s own paginated category rollup -- same conversion as
     * {@see findListForWs()} above, but via CategoryAdminListCriteria (no
     * forbidden-categories/public-only fields at all -- this WS method is
     * admin-only). Always fetches FOUND_ROWS() (unconditional in the
     * original, unlike findListForWs()'s own $limit-gated fetch).
     *
     * @return PaginatedResult<array<string, mixed>>
     *
     * Item 14 DQL audit: stays on DBAL -- same `SQL_CALC_FOUND_ROWS`/
     * `FOUND_ROWS()` reasoning as {@see findListForWs()} above.
     */
    public function findAdminListForWs(CategoryAdminListCriteria $criteria, ?string $searchTerm, int $searchLimit): PaginatedResult
    {
        $conn = $this->getEntityManager()
            ->getConnection();

        $combined = SqlCondition::combine('AND', new SqlCondition('1=1'), self::categoryScopeCondition($criteria->catId, $criteria->recursive));
        $params = $combined->parameters;
        $types = $combined->types;

        $categoriesTable = Tables::categories();

        $sql = <<<SQL
            SELECT SQL_CALC_FOUND_ROWS id, name, comment, uppercats, global_rank, dir, status, image_order
            FROM {$categoriesTable}
            WHERE {$combined->sql}
            SQL;

        if ($searchTerm !== null) {
            $sql .= <<<SQL

                AND name LIKE :searchTerm
                LIMIT :searchLimit
                SQL;
            $params['searchTerm'] = '%' . $searchTerm . '%';
            $params['searchLimit'] = $searchLimit;
            $types['searchLimit'] = ParameterType::INTEGER;
        }

        $rows = $conn->fetchAllAssociative($sql, $params, $types);
        $totalRaw = $conn->fetchOne(<<<SQL
            SELECT FOUND_ROWS()
            SQL);

        return new PaginatedResult($rows, is_numeric($totalRaw) ? (int) $totalRaw : 0);
    }

    /**
     * Subcategory counts grouped by parent id -- Ws\PwgCategories::
     * getAdminList()'s own non-recursive "nb_categories" column.
     *
     * @param  list<int>  $parentIds
     * @return array<string, int> keyed by id_uppercat
     *
     * Item 14 DQL audit: converted to real DQL -- single-table, standard
     * COUNT + GROUP BY aggregate.
     */
    public function findSubcategoryCountsByParent(array $parentIds): array
    {
        if ($parentIds === []) {
            return [];
        }

        $rows = $this->createQueryBuilder('c')
            ->select('c.idUppercat AS id_uppercat', 'COUNT(c.id) AS nb_subcats')
            ->where('c.idUppercat IN (:parentIds)')
            ->groupBy('c.idUppercat')
            ->setParameter('parentIds', $parentIds, ArrayParameterType::INTEGER)
            ->getQuery()
            ->getArrayResult();

        $bySubcat = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

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
     *
     * Item 14 DQL audit: converted to real DQL -- single-table, static
     * WHERE, all 3 columns plain-typed.
     */
    public function findRankInfoByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $rows = $this->createQueryBuilder('c')
            ->select('c.id', 'c.idUppercat AS id_uppercat', 'c.rank')
            ->where('c.id IN (:ids)')
            ->setParameter('ids', $ids, ArrayParameterType::INTEGER)
            ->getQuery()
            ->getArrayResult();

        $result = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $result[] = [
                'id' => is_numeric($row['id'] ?? null) ? (int) $row['id'] : 0,
                'id_uppercat' => is_numeric($row['id_uppercat'] ?? null) ? (int) $row['id_uppercat'] : null,
                'rank' => is_numeric($row['rank'] ?? null) ? (int) $row['rank'] : null,
            ];
        }

        return $result;
    }

    /**
     * Ids of every category directly under $parentId (or top-level, when
     * null), ordered by id -- Ws\PwgCategories::setRank()'s own
     * "does the caller-provided order cover every sibling" check, which
     * relies on this exact id-ascending order to compare against the
     * caller's own numerically-sorted id list.
     *
     * @return list<int>
     *
     * Item 14 DQL audit: converted to real DQL -- single-table; $parentId
     * toggles between two fixed DQL conditions.
     */
    public function findIdsByParentOrderedById(?int $parentId): array
    {
        $qb = $this->createQueryBuilder('c')
            ->select('c.id')
            ->orderBy('c.id', 'ASC');

        if ($parentId === null) {
            $qb->where('c.idUppercat IS NULL');
        } else {
            $qb->where('c.idUppercat = :parentId')
                ->setParameter('parentId', $parentId);
        }

        return array_values(array_map(
            static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0,
            $qb->getQuery()
                ->getSingleColumnResult()
        ));
    }

    /**
     * Ids of every sibling of $excludeId under $parentId (or top-level,
     * when null), ordered by rank -- Ws\PwgCategories::setRank()'s own
     * "insert the new category into its siblings' existing rank order"
     * step.
     *
     * @return list<int>
     *
     * Item 14 DQL audit: converted to real DQL -- single-table; same
     * $parentId toggle as {@see findIdsByParentOrderedById()} above.
     */
    public function findSiblingIdsExcludingOrderedByRank(?int $parentId, int $excludeId): array
    {
        $qb = $this->createQueryBuilder('c')
            ->select('c.id')
            ->andWhere('c.id != :excludeId')
            ->orderBy('c.rank', 'ASC')
            ->setParameter('excludeId', $excludeId);

        if ($parentId === null) {
            $qb->andWhere('c.idUppercat IS NULL');
        } else {
            $qb->andWhere('c.idUppercat = :parentId')
                ->setParameter('parentId', $parentId);
        }

        return array_values(array_map(
            static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0,
            $qb->getQuery()
                ->getSingleColumnResult()
        ));
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
     *
     * Item 14 DQL audit: converted to real DQL -- single-table, static
     * WHERE, all 4 columns plain-typed.
     */
    public function findMoveDetailsByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $rows = $this->createQueryBuilder('c')
            ->select('c.id', 'c.name', 'c.dir', 'c.uppercats')
            ->where('c.id IN (:ids)')
            ->setParameter('ids', $ids, ArrayParameterType::INTEGER)
            ->getQuery()
            ->getArrayResult();

        $result = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $result[] = [
                'id' => is_numeric($row['id'] ?? null) ? (int) $row['id'] : 0,
                'name' => is_string($row['name'] ?? null) ? $row['name'] : '',
                'dir' => is_string($row['dir'] ?? null) ? $row['dir'] : null,
                'uppercats' => is_string($row['uppercats'] ?? null) ? $row['uppercats'] : '',
            ];
        }

        return $result;
    }

    /**
     * Next free id -- Controller\Admin\SiteUpdateSubController's own
     * manual-id assignment for directory-synced categories (mirrors the
     * retired MysqliDb::nextval()).
     *
     * Item 14 DQL audit: converted to real DQL -- single-table; `IF()` is
     * MySQL-specific, but this particular "NULL becomes a default" shape is
     * exactly what DQL's standard `COALESCE()` expresses (unlike
     * {@see findAllForPermalinksDisplay()}'s own `IF()` use, which builds a
     * different value per branch, not just a NULL fallback).
     */
    public function findNextId(): int
    {
        $next = $this->createQueryBuilder('c')
            ->select('COALESCE(MAX(c.id) + 1, 1)')
            ->getQuery()
            ->getSingleScalarResult();

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
     * @param array<string, mixed> $params
     * @param array<string, ArrayParameterType|ParameterType> $types
     * @return list<array<string, mixed>>
     *
     * Item 14 DQL audit: stays on DBAL -- $extraCondition is a caller-
     * supplied raw SQL AND-continuation fragment, not a fixed DQL property
     * path.
     */
    public function findSyncCandidatesForSite(int $siteId, string $extraCondition, array $params = [], array $types = []): array
    {
        $categoriesTable = Tables::categories();
        $params['siteId'] = $siteId;

        return $this->getEntityManager()
            ->getConnection()
            ->fetchAllAssociative(<<<SQL
                SELECT id, uppercats, global_rank, status, visible
                FROM {$categoriesTable}
                WHERE dir IS NOT NULL
                    AND site_id = :siteId
                {$extraCondition}
                SQL
                , $params, $types);
    }

    /**
     * Every category id, unfiltered -- Controller\Admin\
     * SiteUpdateSubController's own rank-bootstrap step ("every category
     * defaults to next-rank 1 on its own sub-categories, until proven
     * otherwise below").
     *
     * @return list<int>
     *
     * Item 14 DQL audit: converted to real DQL -- single-table,
     * unconditional select.
     */
    public function findAllIds(): array
    {
        return array_values(array_map(
            static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0,
            $this->createQueryBuilder('c')
                ->select('c.id')
                ->getQuery()
                ->getSingleColumnResult()
        ));
    }

    /**
     * Next available rank per parent (id_uppercat) -- Controller\Admin\
     * SiteUpdateSubController's own "does this parent already have
     * sub-categories, and if so what's the next free rank" step.
     *
     * @return list<array<string, mixed>>
     *
     * Item 14 DQL audit: converted to real DQL -- single-table; MAX()+1 is
     * a standard DQL aggregate/arithmetic expression.
     */
    public function findNextRanksByParent(): array
    {
        $rows = $this->createQueryBuilder('c')
            ->select('c.idUppercat AS id_uppercat', 'MAX(c.rank) + 1 AS next_rank')
            ->groupBy('c.idUppercat')
            ->getQuery()
            ->getArrayResult();

        $result = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $result[] = [
                'id_uppercat' => $row['id_uppercat'] ?? null,
                'next_rank' => $row['next_rank'] ?? null,
            ];
        }

        return $result;
    }
}
