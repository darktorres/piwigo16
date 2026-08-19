<?php

declare(strict_types=1);

namespace Piwigo\Category;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\ORM\QueryBuilder;
use Piwigo\Category\Projection\ActivePermalinkRow;
use Piwigo\Category\Projection\Category;
use Piwigo\Category\Projection\CategoryAdminListRow;
use Piwigo\Category\Projection\CategoryAlbumTreeRow;
use Piwigo\Category\Projection\CategoryAvailableListRow;
use Piwigo\Category\Projection\CategoryChildRow;
use Piwigo\Category\Projection\CategoryDateRange;
use Piwigo\Category\Projection\CategoryFulldirRow;
use Piwigo\Category\Projection\CategoryGroupAuthorizationRow;
use Piwigo\Category\Projection\CategoryIdImageOrder;
use Piwigo\Category\Projection\CategoryIdNamePermalink;
use Piwigo\Category\Projection\CategoryIdNameUppercat;
use Piwigo\Category\Projection\CategoryIdNameUppercatsRank;
use Piwigo\Category\Projection\CategoryIdStatus;
use Piwigo\Category\Projection\CategoryListingRow;
use Piwigo\Category\Projection\CategoryMoveDetailRow;
use Piwigo\Category\Projection\CategoryMoveRow;
use Piwigo\Category\Projection\CategoryNextRankByParentRow;
use Piwigo\Category\Projection\CategoryPermalinkDisplayRow;
use Piwigo\Category\Projection\CategoryRankInfoRow;
use Piwigo\Category\Projection\CategoryRankUpdateRow;
use Piwigo\Category\Projection\CategorySyncCandidateRow;
use Piwigo\Category\Projection\CategoryUppercatsCounter;
use Piwigo\Category\Projection\ComputedCategoryRollupRow;
use Piwigo\Category\Projection\ParentCategoryForCreate;
use Piwigo\Category\Projection\PhotoCountDateRange;
use Piwigo\Common\Dto\PaginatedResult;
use Piwigo\Common\ValueObject\CategoryId;
use Piwigo\Common\ValueObject\GroupId;
use Piwigo\Common\ValueObject\Permalink;
use Piwigo\Common\ValueObject\SqlDateTime;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\Env;
use Piwigo\Db\BatchWriter;
use Piwigo\Db\LikePattern;
use Piwigo\Db\OrderByClause;
use Piwigo\Db\SortRenderer;
use Piwigo\Db\SqlDialect;
use Piwigo\Group\GroupAccessEntity;
use Piwigo\Group\UserGroupEntity;
use Piwigo\Image\ImageCategoryEntity;
use Piwigo\Image\ImageEntity;
use Piwigo\Permission\PermissionCriteria;
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
 * Owns `categories` ({@see CategoryEntity}); `old_permalinks` is owned by
 * the Permalink domain ({@see \Piwigo\Permalink\OldPermalinkEntity}).
 * Queries against it go through {@see \Piwigo\Permalink\PermalinkRepository}
 * (behind {@see OldPermalinkLookupInterface}, an explicit method
 * parameter, not constructor-injected -- see that interface's own
 * docblock): `Category` is `L2aCoreDomain`, `Permalink` is
 * `L2bExtendedDomain`, and a direct dependency the other way is a real
 * deptrac violation. This repository shares `user_access` ({@see UserAccessEntity})/
 * `group_access` ({@see \Piwigo\Group\GroupAccessEntity})/`image_category`
 * ({@see \Piwigo\Image\ImageCategoryEntity}, placed in the Image domain,
 * its heaviest real consumer) with Group/Image/Permission -- only the single-row/
 * simple-id-list methods against those tables go through DQL; the large
 * majority of this repository's 65 methods are dynamic-fragment
 * (caller-built permission/ORDER BY SQL), dynamically table/column-named
 * (findOrphanedColumnValues/deleteRowsWhereColumnIn/deleteInconsistentAccess),
 * or cross-domain joins/reads (typically against `images`, owned by the
 * Image domain with no association declared here), and stay plain DBAL
 * via $this->em->getConnection() -- the same mixed-repository shape used
 * for Image/Tag.
 *
 * This class is a plain, container-shared service -- it does not extend
 * Doctrine's `EntityRepository` (whose `RepositoryFactory` always
 * constructs it via a fixed `(EntityManagerInterface $em, ClassMetadata
 * $class)` signature, which would block real constructor injection of
 * `CurrentConfig`, needed for its `CurrentConfig::orderBy()` reads).
 * `CategoryEntity`'s own `#[ORM\Entity]` mapping
 * doesn't name this class as its `repositoryClass`, so
 * `$em->getRepository(CategoryEntity::class)` returns a generic
 * `Doctrine\ORM\EntityRepository<CategoryEntity>`. Every
 * `$this->createQueryBuilder('c')` site reaches that generic repository
 * inline (`$this->em->getRepository(CategoryEntity::class)->createQueryBuilder('c')`),
 * not through a private wrapper: phpstan-doctrine's own DQL result-type
 * inference only traces a literal `getRepository(X::class)->createQueryBuilder()`
 * chain, not one hidden behind a same-file helper method. Its
 * `$this->find($id)` sites, by contrast, go through a small private
 * `find()` wrapper -- safe to wrap, unlike `createQueryBuilder()`, since
 * `EntityManagerInterface::find()`'s return type isn't
 * DQL-string-shape-dependent.
 */
final readonly class CategoryRepository
{
    public function __construct(
        private EntityManagerInterface $em,
        private CurrentConfig $currentConfig,
    ) {}

    /**
     * Built from the connection this repository already holds rather than
     * constructor-injected: {@see SortRenderer} is stateless apart from that
     * connection, and adding a parameter here would mean touching every
     * `new CategoryRepository(...)` site (113 of them) for no behavioural gain.
     */
    private function sortRenderer(): SortRenderer
    {
        return new SortRenderer($this->em->getConnection());
    }

    private function find(CategoryId $id): ?CategoryEntity
    {
        return $this->em->find(CategoryEntity::class, $id);
    }

    public function findById(int $id): ?Category
    {
        $catId = CategoryId::tryFrom($id);
        if (! $catId instanceof CategoryId) {
            return null;
        }

        $entity = $this->find($catId);

        return $entity instanceof CategoryEntity ? Category::fromEntity($entity) : null;
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, CategoryIdNamePermalink> keyed by id
     *
     * Real DQL -- single-table, static WHERE, no join DQL can't express.
     * `c.id`/`c.permalink` are custom-Typed (`category_id`/`permalink`),
     * so `getArrayResult()` (Gotcha #1) returns real VO instances for
     * them -- unwrapped below.
     */
    public function findNamesByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $rows = $this->em->getRepository(CategoryEntity::class)->createQueryBuilder('c')
            ->select('c.id', 'c.name', 'c.permalink')
            ->where('c.id IN (:ids)')
            ->setParameter('ids', $ids, ArrayParameterType::INTEGER)
            ->getQuery()
            ->getArrayResult();

        $byId = [];
        foreach ($rows as $row) {
            if (! is_array($row) || ! ($row['id'] ?? null) instanceof CategoryId) {
                continue;
            }

            $byId[$row['id']->value] = new CategoryIdNamePermalink(
                id: $row['id']->value,
                name: is_string($row['name'] ?? null) ? $row['name'] : '',
                permalink: ($row['permalink'] ?? null) instanceof Permalink ? $row['permalink']->value : null,
            );
        }

        return $byId;
    }

    /**
     * Every category's id/name/permalink, unfiltered -- HtmlService::
     * getCatDisplayNameCache()'s own breadcrumb-rendering cache warm-up.
     *
     * @return array<int, CategoryIdNamePermalink> keyed by id
     *
     * Real DQL -- single-table, unconditional select. `c.id`/`c.permalink`
     * are custom-Typed -- see this class's own Gotcha #1 note above.
     */
    public function findAllIdNamePermalink(): array
    {
        $rows = $this->em->getRepository(CategoryEntity::class)->createQueryBuilder('c')
            ->select('c.id', 'c.name', 'c.permalink')
            ->getQuery()
            ->getArrayResult();

        $byId = [];
        foreach ($rows as $row) {
            if (! is_array($row) || ! ($row['id'] ?? null) instanceof CategoryId) {
                continue;
            }

            $byId[$row['id']->value] = new CategoryIdNamePermalink(
                id: $row['id']->value,
                name: is_string($row['name'] ?? null) ? $row['name'] : '',
                permalink: ($row['permalink'] ?? null) instanceof Permalink ? $row['permalink']->value : null,
            );
        }

        return $byId;
    }

    /**
     * A single category's id/name/permalink, or null if it doesn't exist.
     * Unlike findAllIdNamePermalink() above (every row, cache warm-up),
     * this is a single-id lookup.
     *
     * @return ?CategoryIdNamePermalink
     *
     * Real DQL -- single-table, id is the PK so at most one row can match
     * (no NonUniqueResultException risk, no setMaxResults() needed).
     * `c.id`/`c.permalink` are custom-Typed -- see this class's own
     * Gotcha #1 note above.
     */
    public function findIdNamePermalinkById(int $id): ?CategoryIdNamePermalink
    {
        $rows = $this->em->getRepository(CategoryEntity::class)->createQueryBuilder('c')
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

        return new CategoryIdNamePermalink(
            id: $row['id'] instanceof CategoryId ? $row['id']->value : 0,
            name: is_string($row['name']) ? $row['name'] : '',
            permalink: $row['permalink'] instanceof Permalink ? $row['permalink']->value : null,
        );
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
     * `REGEXP`'s MySQL/MariaDB-specific *operator name* is portable via a
     * custom DQL function ({@see \Piwigo\Db\DqlFunction\RegexpFunction})
     * that reads the real operator from `AbstractPlatform::
     * getRegexpExpression()`. Real DQL -- see that class's own docblock
     * for the real remaining Postgres-portability caveat (the pattern
     * *syntax* below, not just the operator, would need a
     * platform-specific rewrite for genuine Postgres support).
     */
    public function findSubcategoryIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $qb = $this->em->getRepository(CategoryEntity::class)->createQueryBuilder('c')
            ->select('DISTINCT c.id');

        $clauses = [];
        foreach ($ids as $num => $categoryId) {
            $param = 'id' . $num;
            $qb->setParameter($param, '(^|,)' . $categoryId . '(,|$)');
            $clauses[] = 'REGEXP(c.uppercats, :' . $param . ') = true';
        }

        $matchedIds = $qb->where(implode(' OR ', $clauses))
            ->getQuery()
            ->getSingleColumnResult();

        return array_values(array_map(
            static fn (mixed $id): int => (int) $id,
            array_filter($matchedIds, is_numeric(...))
        ));
    }

    /**
     * Uses a typed {@see PermissionCriteria} -- the one real caller applies
     * forbiddenCategoryIds/visibleCategoryIds against `c.id` and
     * visibleImageIds against `ic.image` (via `image_access_list`, since
     * `visible_images` falls through to it in the old
     * `getSqlConditionFandFAsCondition()` mapping, so imageAccessIds
     * applies here too, against `ic.image`).
     *
     * Real DQL -- `image_category` is mapped
     * ({@see \Piwigo\Image\ImageCategoryEntity}), {@see PermissionCriteria}'s
     * `*Condition()` methods work identically against a DQL query builder
     * (see {@see applyCondition()}), and `RAND()` uses the same portable
     * custom DQL function ({@see \Piwigo\Db\DqlFunction\RandFunction}) as
     * {@see findRandomImageIdInCategory()}.
     */
    public function findRandomImageId(int $catId, string $uppercats, bool $recursive, PermissionCriteria $criteria): ?int
    {
        $scope = $recursive
            ? '(c.id = :catId OR c.uppercats LIKE :uppercatsLike)'
            : 'c.id = :catId';

        $qb = $this->em
            ->createQueryBuilder()
            ->select('IDENTITY(ic.image)')
            ->from(CategoryEntity::class, 'c')
            ->innerJoin('c.imageCategories', 'ic')
            ->where($scope)
            ->orderBy('RAND()')
            ->setMaxResults(1)
            ->setParameter('catId', $catId);
        SqlCondition::combine(
            'AND',
            $criteria->forbiddenCategoriesCondition('c.id'),
            $criteria->visibleCategoriesCondition('c.id'),
            $criteria->visibleImagesCondition('ic.image'),
            $criteria->imageAccessCondition('ic.image'),
        )->applyTo($qb);

        if ($recursive) {
            $qb->setParameter('uppercatsLike', $uppercats . ',%');
        }

        $values = $qb->getQuery()
            ->getSingleColumnResult();
        $value = $values[0] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * One row per category with its own direct image count/last-available
     * date -- {@see \Piwigo\Category\CategoryService::getComputedCategories()}
     * rolls these up into subtree totals.
     *
     * cat_id/id_uppercat/global_rank/rank/date_last/nb_images are narrowed
     * below the same way {@see \Piwigo\Category\Projection\Category::fromRow()}
     * narrows a full category row, so CategoryService::getComputedCategories()
     * can consume the result directly instead of re-deriving each field's
     * type itself. `rank` (sibling order within a parent, distinct from
     * `global_rank`) is carried through purely for CategoryCatsRenderer
     * (CategoryService::compareByRank()) -- CategoryService/CategoryTreeCache
     * themselves never read it.
     *
     * @return list<ComputedCategoryRollupRow>
     *
     * `image_category` is mapped ({@see \Piwigo\Image\ImageCategoryEntity}),
     * but this method stays on DBAL for two real blockers: a dynamic
     * `SqlDialect::getRecentPeriodExpression()` raw-SQL fragment spliced
     * into the second JOIN's own ON condition, and a raw
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

        $qb = $this->em
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
            ->from('categories', 'c')
            ->leftJoin('c', 'image_category', 'ic', 'ic.category_id = c.id')
            ->leftJoin('ic', 'images', 'i', $imagesJoinCondition)
            ->groupBy('c.id')
            ->setParameter('level', $level);

        if ($forbiddenCategoriesCsv !== '') {
            $qb->andWhere('c.id NOT IN (' . $forbiddenCategoriesCsv . ')');
        }

        return array_map(
            static fn (array $row): ComputedCategoryRollupRow => new ComputedCategoryRollupRow(
                catId: is_numeric($row['cat_id']) ? (int) $row['cat_id'] : 0,
                idUppercat: is_numeric($row['id_uppercat'] ?? null) ? (int) $row['id_uppercat'] : null,
                globalRank: is_string($row['global_rank'] ?? null) ? $row['global_rank'] : null,
                rank: is_numeric($row['rank'] ?? null) ? (int) $row['rank'] : null,
                dateLast: is_string($row['date_last'] ?? null) ? $row['date_last'] : null,
                nbImages: is_numeric($row['nb_images']) ? (int) $row['nb_images'] : 0,
            ),
            $qb->executeQuery()
                ->fetchAllAssociative()
        );
    }

    /**
     * @param  list<int>  $catIds
     * @return list<int>
     *
     * Uses a typed {@see PermissionCriteria} -- the one real caller applies
     * forbiddenCategoryIds/visibleCategoryIds against `ic.category_id` and
     * visibleImageIds against `i.id` (via `image_access_list`, since
     * `visible_images` falls through to the images-table's own
     * `level <= x` check in the old `getSqlConditionFandFAsCondition()`
     * mapping, so maxLevel applies here too, against `i.level`).
     *
     * Runs real DQL whenever {@see \Piwigo\Db\SortRenderer::toDql()} can
     * express the configured order. The raw-DBAL query below is reached in
     * exactly one case now: a `` `rank` `` entry with more than one category
     * requested, where there is no single `ic` alias to resolve it against
     * (see the comment on $dqlOrderBy below).
     */
    public function findImageIdsForCategories(
        array $catIds,
        string $mode,
        PermissionCriteria $criteria
    ): array {
        if ($catIds === []) {
            return [];
        }

        // `` `rank` `` only ever gets an `ic` alias to resolve against when
        // exactly one category is requested: a multi-category result set
        // has no single well-defined rank per image (each membership row
        // has its own), and even for one category, MySQL's
        // ONLY_FULL_GROUP_BY needs `ic.rank` explicitly in the GROUP BY
        // list too (added below) since it can't infer the functional
        // dependency on `i.id` from the WHERE clause's IN-list cardinality.
        $dqlOrderBy = $this->sortRenderer()
            ->toDql($this->currentConfig->orderBy, 'i', count($catIds) === 1 ? 'ic' : null);
        if ($dqlOrderBy !== null) {
            return $this->findImageIdsForCategoriesViaDql($catIds, $mode, $criteria, $dqlOrderBy);
        }

        $qb = $this->em
            ->getConnection()
            ->createQueryBuilder()
            ->select('id')
            ->from('images', 'i')
            ->innerJoin('i', 'image_category', 'ic', 'id = ic.image_id')
            ->where('category_id IN (:catIds)')
            ->setParameter('catIds', $catIds, ArrayParameterType::INTEGER)
            ->groupBy('id');
        SqlCondition::combine(
            'AND',
            $criteria->forbiddenCategoriesCondition('ic.category_id'),
            $criteria->visibleCategoriesCondition('ic.category_id'),
            $criteria->visibleImagesCondition('i.id'),
            $criteria->maxLevelCondition('i.level'),
        )->applyTo($qb);

        if ($mode === 'AND' && count($catIds) > 1) {
            $qb->having('COUNT(DISTINCT category_id) = :catCount')
                ->setParameter('catCount', count($catIds));
        }

        // toSqlBody(), not toSql(): QueryBuilder::orderBy() prepends its own
        // "ORDER BY " keyword, so passing a complete clause would build
        // "ORDER BY ORDER BY ..." -- a real syntax error. The body already
        // renders `RAND()` through SqlDialect::randomFunction() per platform
        // (SortRenderer::randomExpression()), so nothing needs translating here.
        //
        // `rank` does need translating. It is the only reason this fallback
        // is reached at all, it lives on the join row rather than on
        // `images`, and with more than one category an image has one rank
        // per membership -- so a bare reference is both ambiguous and, under
        // the sql_mode DbConnection pins, outright invalid against
        // `GROUP BY id`:
        //
        //   Expression #1 of ORDER BY clause is not in GROUP BY clause and
        //   contains nonaggregated column 'ic.rank' ... incompatible with
        //   sql_mode=only_full_group_by
        //
        // MIN() picks each image's best manual position among the requested
        // albums, which is the only reading that keeps one row per image.
        $renderer = $this->sortRenderer();
        $rankColumn = $renderer->rankColumn();
        $qb->orderBy(str_replace(
            $rankColumn,
            'MIN(' . $rankColumn . ')',
            $renderer->toSqlBody($this->currentConfig->orderBy)
        ));

        $ids = $qb->executeQuery()
            ->fetchFirstColumn();

        return array_values(array_map(
            static fn (mixed $id): int => (int) $id,
            array_filter($ids, is_numeric(...))
        ));
    }

    /**
     * @param  list<int>  $catIds
     * @param  list<OrderByClause>  $dqlOrderBy
     * @return list<int>
     */
    private function findImageIdsForCategoriesViaDql(
        array $catIds,
        string $mode,
        PermissionCriteria $criteria,
        array $dqlOrderBy
    ): array {
        $qb = $this->em
            ->createQueryBuilder()
            ->select('i.id')
            ->from(ImageEntity::class, 'i')
            ->innerJoin('i.imageCategories', 'ic')
            ->where('ic.category IN (:catIds)')
            ->setParameter('catIds', $catIds, ArrayParameterType::INTEGER)
            ->groupBy('i.id');
        SqlCondition::combine(
            'AND',
            $criteria->forbiddenCategoriesCondition('ic.category'),
            $criteria->visibleCategoriesCondition('ic.category'),
            $criteria->visibleImagesCondition('i.id'),
            $criteria->maxLevelCondition('i.level'),
        )->applyTo($qb);

        if ($mode === 'AND' && count($catIds) > 1) {
            $qb->having('COUNT(DISTINCT ic.category) = :catCount')
                ->setParameter('catCount', count($catIds));
        }

        foreach ($dqlOrderBy as $entry) {
            if ($entry->property === 'ic.rank') {
                // Needed alongside `i.id` for MySQL's ONLY_FULL_GROUP_BY --
                // sound only because the toDql() call above only passes an
                // `ic` alias when count($catIds) === 1, so `ic.rank` is
                // already 1:1 with `i.id` here (the join's composite PK is
                // (imageId, categoryId), and categoryId is pinned to one
                // value by the WHERE clause).
                $qb->addGroupBy('ic.rank');
            }

            $qb->addOrderBy($entry->property, $entry->dir);
        }

        $ids = $qb->getQuery()
            ->getSingleColumnResult();

        return array_values(array_map(
            static fn (mixed $id): int => (int) $id,
            array_filter($ids, is_numeric(...))
        ));
    }

    /**
     * @param  list<int>  $itemIds
     * @param  list<int>  $excludedCatIds
     * @return array<int, CategoryUppercatsCounter> keyed by id
     *
     * Uses a typed {@see PermissionCriteria} -- the one real caller applies
     * forbiddenCategoryIds/visibleCategoryIds against `ic.category`.
     * Real DQL -- `image_category` is mapped
     * ({@see \Piwigo\Image\ImageCategoryEntity}), and
     * {@see PermissionCriteria}'s `*Condition()` methods work identically
     * against a DQL query builder (see {@see applyCondition()}).
     */
    public function findCommonCategories(array $itemIds, ?int $max, array $excludedCatIds, PermissionCriteria $criteria): array
    {
        if ($itemIds === []) {
            return [];
        }

        $qb = $this->em
            ->createQueryBuilder()
            ->select('c.id', 'c.uppercats', 'COUNT(IDENTITY(ic.image)) AS counter')
            ->from(ImageCategoryEntity::class, 'ic')
            ->innerJoin('ic.category', 'c')
            ->where('ic.image IN (:itemIds)')
            ->setParameter('itemIds', $itemIds, ArrayParameterType::INTEGER)
            ->groupBy('c.id');
        SqlCondition::combine(
            'AND',
            $criteria->forbiddenCategoriesCondition('ic.category'),
            $criteria->visibleCategoriesCondition('ic.category'),
        )->applyTo($qb);

        if ($excludedCatIds !== []) {
            $qb->andWhere('ic.category NOT IN (:excludedCatIds)')
                ->setParameter('excludedCatIds', $excludedCatIds, ArrayParameterType::INTEGER);
        }

        if ($max !== null) {
            $qb->orderBy('counter', 'DESC')
                ->setMaxResults($max);
        }

        $rows = $qb->getQuery()
            ->getArrayResult();

        $byId = [];
        foreach ($rows as $row) {
            if (! is_array($row) || ! ($row['id'] ?? null) instanceof CategoryId) {
                continue;
            }

            $id = $row['id']->value;
            $byId[$id] = new CategoryUppercatsCounter(
                id: $id,
                uppercats: is_string($row['uppercats'] ?? null) ? $row['uppercats'] : '',
                counter: is_numeric($row['counter'] ?? null) ? (int) $row['counter'] : 0,
            );
        }

        return $byId;
    }

    /**
     * id/name/permalink/id_uppercat/uppercats/global_rank -- a deliberately
     * narrower 6-column contract than the full Category Projection, see
     * findFullCategoriesByIds()'s own docblock.
     *
     * @param  list<int>  $ids
     * @return list<CategoryListingRow>
     *
     * Real DQL -- single-table, static WHERE. `c.id`/`c.permalink` are
     * custom-Typed -- see this class's own Gotcha #1 note above.
     */
    public function findCategoriesByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $rows = $this->em->getRepository(CategoryEntity::class)->createQueryBuilder('c')
            ->select('c.id', 'c.name', 'c.permalink', 'c.idUppercat AS id_uppercat', 'c.uppercats', 'c.globalRank AS global_rank')
            ->where('c.id IN (:ids)')
            ->setParameter('ids', $ids, ArrayParameterType::INTEGER)
            ->getQuery()
            ->getArrayResult();

        $result = [];
        foreach ($rows as $row) {
            if (! is_array($row) || ! ($row['id'] ?? null) instanceof CategoryId) {
                continue;
            }

            $result[] = new CategoryListingRow(
                id: $row['id']->value,
                name: is_string($row['name'] ?? null) ? $row['name'] : '',
                permalink: ($row['permalink'] ?? null) instanceof Permalink ? $row['permalink']->value : null,
                idUppercat: is_numeric($row['id_uppercat'] ?? null) ? (int) $row['id_uppercat'] : null,
                uppercats: is_string($row['uppercats'] ?? null) ? $row['uppercats'] : '',
                globalRank: is_string($row['global_rank'] ?? null) ? $row['global_rank'] : null,
            );
        }

        return $result;
    }

    /**
     * Every column on `categories` for the given ids -- deliberately a
     * separate method from {@see findCategoriesByIds()} (a narrower,
     * differently-shaped, already-tested 6-column contract with its own
     * real caller) rather than widening that one. CategoryCatsRenderer
     * calls this only for the small, already-paginated subset of cat_ids
     * being displayed on one page -- never the whole tree, unlike
     * CategoryTreeCache's own cached rollup.
     *
     * @param  list<int>  $ids
     * @return list<Category>
     *
     * Fetches the full entity (object hydration, same as {@see findById()})
     * instead of a `SELECT *` DBAL row, and maps through {@see
     * Category::fromEntity()} instead of {@see Category::fromRow()}.
     */
    public function findFullCategoriesByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $entities = $this->em->getRepository(CategoryEntity::class)->createQueryBuilder('c')
            ->where('c.id IN (:ids)')
            ->setParameter('ids', $ids, ArrayParameterType::INTEGER)
            ->getQuery()
            ->getResult();

        return array_map(Category::fromEntity(...), $entities);
    }

    /**
     * @return list<int>
     *
     * Real DQL -- single-table, static WHERE; id is plain-typed, so
     * getSingleColumnResult() returns ordinary ints.
     */
    public function findCategoryIdsBySite(int $siteId): array
    {
        return array_values(array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $this->em->getRepository(CategoryEntity::class)->createQueryBuilder('c')
            ->select('c.id')
            ->where('c.site = :siteId')
            ->setParameter('siteId', $siteId)
            ->getQuery()
            ->getSingleColumnResult()));
    }

    /**
     * @param  list<int>  $ids
     * @return list<int>
     *
     * Real DQL -- `images` is owned by the Image domain (`Piwigo\Image`,
     * L2aCoreDomain, same layer as `Piwigo\Category`, so querying
     * `ImageEntity` directly here is a legal same-layer dependency per
     * `deptrac.yaml`'s own ruleset), queried directly via
     * `$this->em->createQueryBuilder()->from(ImageEntity::class, ...)` --
     * `ImageEntity::$storageCategory`'s owning side lives on `Image`, not
     * `CategoryEntity`, so this filters through the bare association path
     * the same way it filtered through the plain scalar column before.
     */
    public function findStorageLinkedImageIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return array_values(array_map(
            static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0,
            $this->em
                ->createQueryBuilder()
                ->select('i.id')
                ->from(ImageEntity::class, 'i')
                ->where('i.storageCategory IN (:ids)')
                ->setParameter('ids', $ids, ArrayParameterType::INTEGER)
                ->getQuery()
                ->getSingleColumnResult()
        ));
    }

    /**
     * Category ids whose name and/or comment matches $pattern (already a
     * complete SQL LIKE pattern, e.g. '%word%') -- backs
     * SearchService::searchAllwords()'s own "all words" search feature
     * (category-title/description match, distinct from quick-search's
     * separate token-based category lookup).
     *
     * @return list<int>
     *
     * Real DQL -- single-table. The dynamic name/comment OR is built as a
     * plain string (both branches share the same `:pattern` bind), not a
     * loop-built `Expr\Orx` composite -- sidesteps gotcha #2's
     * phpstan-doctrine false positive on dynamically-built composites.
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

        $ids = $this->em->getRepository(CategoryEntity::class)->createQueryBuilder('c')
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
     * Real DQL -- single-table; `image_category` is mapped
     * ({@see ImageCategoryEntity}). `ic.image` is a real association
     * (owning side) since the association-modeling item's final wave --
     * `IDENTITY()` is needed in the `SELECT` regardless of hydration mode
     * (a bare association path there changes the generated SQL itself,
     * not just how the result is read), so `getSingleColumnResult()`'s own
     * "never applies a custom Type" safety (Gotcha #4) is no longer the
     * relevant reasoning here.
     */
    public function findDistinctLinkedImageIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $rows = $this->em
            ->createQueryBuilder()
            ->select('DISTINCT IDENTITY(ic.image)')
            ->from(ImageCategoryEntity::class, 'ic')
            ->where('ic.category IN (:ids)')
            ->setParameter('ids', $ids, ArrayParameterType::INTEGER)
            ->getQuery()
            ->getSingleColumnResult();

        return array_values(array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $rows));
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
     * Real DQL -- single-table; `image_category` is mapped
     * ({@see ImageCategoryEntity}). Both filtered columns bind as
     * `ArrayParameterType::INTEGER` IN-lists (raw ints, not wrapped
     * through CategoryId -- the IN-clause array bind doesn't route
     * through a field's custom Doctrine Type reliably, same established
     * convention as {@see deleteGroupAccessForGroupsAndCategories()}
     * elsewhere in this class). `$excludeIds` is still spliced in unconditionally,
     * even when empty.
     */
    public function findNonOrphanImageIds(array $imageIds, array $excludeIds): array
    {
        if ($imageIds === []) {
            return [];
        }

        $rows = $this->em
            ->createQueryBuilder()
            ->select('DISTINCT IDENTITY(ic.image)')
            ->from(ImageCategoryEntity::class, 'ic')
            ->where('ic.image IN (:imageIds)')
            ->andWhere('ic.category NOT IN (:excludeIds)')
            ->setParameter('imageIds', $imageIds, ArrayParameterType::INTEGER)
            ->setParameter('excludeIds', $excludeIds, ArrayParameterType::INTEGER)
            ->getQuery()
            ->getSingleColumnResult();

        return array_values(array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $rows));
    }

    /**
     * image_id for every link outside $excludeIds, NOT deduplicated
     * (matches CategoryService::calculateOrphanImpact()'s own
     * large-category fallback path, which dedupes in PHP after
     * intersecting against the recursive image id set) -- a different
     * contract from
     * {@see findNonOrphanImageIds()} above (that one is DISTINCT and
     * pre-filtered to a specific image id set; this one returns every
     * matching row so the caller can avoid sending a huge `image_id IN
     * (...)` list when the recursive set is large).
     *
     * @param  list<int>  $excludeIds
     * @return list<int>
     *
     * Real DQL -- single-table; `image_category` is mapped
     * ({@see ImageCategoryEntity}). No DISTINCT -- this method
     * deliberately returns every matching row, see the class comment
     * above.
     */
    public function findImageIdsOutsideCategories(array $excludeIds): array
    {
        $rows = $this->em
            ->createQueryBuilder()
            ->select('IDENTITY(ic.image)')
            ->from(ImageCategoryEntity::class, 'ic')
            ->where('ic.category NOT IN (:excludeIds)')
            ->setParameter('excludeIds', $excludeIds, ArrayParameterType::INTEGER)
            ->getQuery()
            ->getSingleColumnResult();

        return array_values(array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $rows));
    }

    /**
     * Revokes a specific set of users' access to a specific set of
     * categories -- CategoryAdminService::setCategoryPermissions()'s own
     * "if you forbid access to an album, all sub-albums become
     * automatically forbidden too" deny path. Dropping every grant on a
     * category needs no method of its own: fk_user_access_cat_id is
     * ON DELETE CASCADE, so deleting the category removes them.
     *
     * @param  array<int>  $userIds
     * @param  array<int>  $catIds
     */
    public function deleteUserAccessForUsersAndCategories(array $userIds, array $catIds): void
    {
        if ($userIds === [] || $catIds === []) {
            return;
        }

        $em = $this->em;
        $em->createQueryBuilder()
            ->delete(UserAccessEntity::class, 'ua')
            ->where('ua.userId IN (:userIds)')
            ->andWhere('ua.catId IN (:catIds)')
            ->setParameter('userIds', $userIds, ArrayParameterType::INTEGER)
            ->setParameter('catIds', $catIds, ArrayParameterType::INTEGER)
            ->getQuery()
            ->execute();
        $em->clear();
    }

    /**
     * Same as {@see deleteUserAccessForUsersAndCategories()}, for groups.
     * Both arrays wrap through the VO before binding: GroupAccessEntity's
     * columns are custom-typed, and the IN-clause array bind does not route
     * through a field's Doctrine Type reliably, so the ints are unwrapped
     * again with an explicit ArrayParameterType::INTEGER.
     *
     * @param  array<int>  $groupIds
     * @param  array<int>  $catIds
     */
    public function deleteGroupAccessForGroupsAndCategories(array $groupIds, array $catIds): void
    {
        if ($groupIds === [] || $catIds === []) {
            return;
        }

        $em = $this->em;
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

        $em = $this->em;
        $em->createQueryBuilder()
            ->delete(CategoryEntity::class, 'c')
            ->where('c.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->execute();
        $em->clear();
    }

    /**
     * Categories whose representative picture points at an image that no
     * longer exists -- CategoryService::updateCategory()'s own first sweep.
     *
     * $ids scopes the sweep, in the three shapes its single caller can
     * produce: 'all', one category id, or a list of them.
     *
     * @param 'all'|int|string|array<int|string> $ids
     * @return list<int>
     *
     * Real DQL -- `c.representativePicture` is a real owning-side
     * `#[ORM\ManyToOne]`, so the join is a plain association join, not an
     * explicit `Join::WITH` condition. getSingleColumnResult() hydrates
     * HYDRATE_SCALAR_COLUMN, which never applies a field's custom Type, so
     * `c.id` comes back as a plain scalar despite being CategoryId-typed.
     */
    public function findWrongRepresentativeCategoryIds(array|int|string $ids = 'all'): array
    {
        $qb = $this->em->createQueryBuilder()
            ->select('c.id')
            ->distinct()
            ->from(CategoryEntity::class, 'c')
            ->leftJoin('c.representativePicture', 'i')
            ->where('c.representativePicture IS NOT NULL')
            ->andWhere('i.id IS NULL');

        if (! self::restrictToCategoryIds($qb, $ids)) {
            return [];
        }

        return array_values(array_map(
            static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0,
            $qb->getQuery()
                ->getSingleColumnResult()
        ));
    }

    /**
     * Narrows $qb to the categories $ids names, returning false when $ids
     * can never match anything (so the caller can skip the query outright).
     *
     * The scalar branch binds a real CategoryId: `c.id` uses the
     * `category_id` custom Type, whose convertToDatabaseValue() is
     * deliberately VO-only and rejects a bare int. The list branch passes
     * plain ints with an explicit ArrayParameterType, which bypasses
     * field-type inference entirely -- same split findDistinctLinkedImageIds()
     * and setParameter('categoryId', CategoryId::from(...)) already use.
     *
     * `QueryBuilder` is imported here as DBAL's, so the ORM one is named in
     * full -- same convention applyCondition() above already follows.
     *
     * @param 'all'|int|string|array<int|string> $ids
     */
    private static function restrictToCategoryIds(QueryBuilder $qb, array|int|string $ids): bool
    {
        if ($ids === 'all') {
            return true;
        }

        if (! is_array($ids)) {
            // tryFrom(), not from(): a non-positive or non-numeric id can't
            // match a row, and the raw-SQL version this replaced bound it
            // happily and returned nothing rather than raising.
            $catId = CategoryId::tryFrom($ids);
            if (! $catId instanceof CategoryId) {
                return false;
            }

            $qb->andWhere('c.id = :catId')
                ->setParameter('catId', $catId);

            return true;
        }

        $qb->andWhere('c.id IN (:catIds)')
            ->setParameter('catIds', array_map(intval(...), array_values($ids)), ArrayParameterType::INTEGER);

        return true;
    }

    /**
     * @param  list<int>  $ids
     */
    public function clearRepresentativePictureIds(array $ids): void
    {
        if ($ids === []) {
            return;
        }

        $em = $this->em;
        $em->createQueryBuilder()
            ->update(CategoryEntity::class, 'c')
            ->set('c.representativePicture', ':null')
            ->set('c.lastmodified', ':now')
            ->where('c.id IN (:ids)')
            ->setParameter('null', null)
            ->setParameter('now', Env::now()->format('Y-m-d H:i:s'))
            ->setParameter('ids', $ids)
            ->getQuery()
            ->execute();
        $em->clear();
    }

    /**
     * Categories that hold at least one photo but have no representative
     * picture set -- CategoryService::updateCategory()'s own second sweep.
     *
     * @param 'all'|int|string|array<int|string> $ids
     * @return list<int>
     *
     * Real DQL -- natural join through `c.imageCategories` (the inverse
     * side of `ImageCategoryEntity::$category`). Scoping on `c.id` rather
     * than the original's `image_category.category_id` is equivalent: the
     * join equates them for every matched row.
     */
    public function findCategoriesNeedingRandomRepresentative(array|int|string $ids = 'all'): array
    {
        $qb = $this->em->createQueryBuilder()
            ->select('c.id')
            ->distinct()
            ->from(CategoryEntity::class, 'c')
            ->innerJoin('c.imageCategories', 'ic')
            ->where('c.representativePicture IS NULL');

        if (! self::restrictToCategoryIds($qb, $ids)) {
            return [];
        }

        return array_values(array_map(
            static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0,
            $qb->getQuery()
                ->getSingleColumnResult()
        ));
    }

    /**
     * @return list<string>
     *
     * `$target` is {@see CategoryOrphanTarget}'s bounded enum, not an
     * arbitrary runtime entity/property pair.
     */
    public function findOrphanedColumnValues(CategoryOrphanTarget $target): array
    {
        $entityClassAndProperty = $target->entityClassAndProperty();
        $entityClass = $entityClassAndProperty->entityClass;
        $property = $entityClassAndProperty->property;
        // A bare association path in a SELECT clause changes the
        // generated SQL itself (it would hydrate the associated entity,
        // not just extract the FK id), so an association-shaped $property
        // (see DqlPropertyTarget::$isAssociation) needs IDENTITY() here --
        // the join condition and every other consumer of $property stay
        // on the bare path.
        $selectExpr = $entityClassAndProperty->isAssociation
            ? "DISTINCT IDENTITY(t.{$property})"
            : "DISTINCT t.{$property}";

        $values = $this->em
            ->createQueryBuilder()
            ->select($selectExpr)
            ->from($entityClass, 't')
            ->leftJoin(CategoryEntity::class, 'c', Join::WITH, "c.id = t.{$property}")
            ->where('c.id IS NULL')
            ->getQuery()
            ->getSingleColumnResult();

        return array_values(array_unique(array_map(
            static fn (mixed $v): string => is_scalar($v) ? (string) $v : '',
            $values
        )));
    }

    /**
     * @param  list<int|string>  $values
     *
     * `$target` is {@see CategoryOrphanTarget}'s bounded enum, not an
     * arbitrary runtime entity/property pair.
     */
    public function deleteRowsWhereColumnIn(CategoryOrphanTarget $target, array $values): void
    {
        $entityClassAndProperty = $target->entityClassAndProperty();
        $entityClass = $entityClassAndProperty->entityClass;
        $property = $entityClassAndProperty->property;
        $em = $this->em;

        $em->createQueryBuilder()
            ->delete($entityClass, 't')
            ->where("t.{$property} IN (:values)")
            ->setParameter('values', array_map(static fn (int|string $v): int => is_numeric($v) ? (int) $v : 0, $values), ArrayParameterType::INTEGER)
            ->getQuery()
            ->execute();
        $em->clear();
    }

    /**
     * id/id_uppercat/uppercats/rank/global_rank for every category, ordered
     * for {@see \Piwigo\Category\CategoryService::updateGlobalRank()}'s own
     * per-parent rank-numbering pass. `rank` (sibling order within a
     * parent, distinct from `global_rank`) is carried through purely for
     * that method's own rank-change detection.
     *
     * @return list<CategoryRankUpdateRow>
     *
     * Real DQL -- single-table, unconditional select/order, all columns
     * plain-typed.
     */
    public function findCategoriesForRankUpdate(): array
    {
        $rows = $this->em->getRepository(CategoryEntity::class)->createQueryBuilder('c')
            ->select('c.id', 'c.idUppercat AS id_uppercat', 'c.uppercats', 'c.rank', 'c.globalRank AS global_rank')
            ->orderBy('c.idUppercat')
            ->addOrderBy('c.rank')
            ->addOrderBy('c.name')
            ->getQuery()
            ->getArrayResult();

        $result = [];
        foreach ($rows as $row) {
            if (! is_array($row) || ! ($row['id'] ?? null) instanceof CategoryId) {
                continue;
            }

            $result[] = new CategoryRankUpdateRow(
                id: $row['id']->value,
                idUppercat: is_numeric($row['id_uppercat'] ?? null) ? (int) $row['id_uppercat'] : null,
                uppercats: is_string($row['uppercats'] ?? null) ? $row['uppercats'] : '',
                rank: is_numeric($row['rank'] ?? null) ? (int) $row['rank'] : null,
                globalRank: is_string($row['global_rank'] ?? null) ? $row['global_rank'] : null,
            );
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

        $em = $this->em;
        $em->createQueryBuilder()
            ->update(CategoryEntity::class, 'c')
            ->set('c.visible', ':visible')
            ->set('c.lastmodified', ':now')
            ->where('c.id IN (:ids)')
            ->setParameter('visible', $visible)
            ->setParameter('now', Env::now()->format('Y-m-d H:i:s'))
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

        $em = $this->em;
        $em->createQueryBuilder()
            ->update(CategoryEntity::class, 'c')
            ->set('c.commentable', ':commentable')
            ->set('c.lastmodified', ':now')
            ->where('c.id IN (:ids)')
            ->setParameter('commentable', $commentable)
            ->setParameter('now', Env::now()->format('Y-m-d H:i:s'))
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

        $em = $this->em;
        $em->createQueryBuilder()
            ->update(CategoryEntity::class, 'c')
            ->set('c.status', ':status')
            ->set('c.lastmodified', ':now')
            ->where('c.id IN (:ids)')
            ->setParameter('status', $status)
            ->setParameter('now', Env::now()->format('Y-m-d H:i:s'))
            ->setParameter('ids', array_values($ids))
            ->getQuery()
            ->execute();
        $em->clear();
    }

    /**
     * CategoryAdminService::saveImageOrder()'s own category (admin/
     * element_set_ranks.php).
     */
    public function updateImageOrder(CategoryId $catId, ?string $imageOrder): void
    {
        $entity = $this->find($catId);
        if (! $entity instanceof CategoryEntity) {
            return;
        }

        $entity->imageOrder = $imageOrder;
        $this->em
            ->flush();
    }

    /**
     * Same as {@see updateImageOrder()}, applied to every descendant of
     * $uppercatsPrefix (a category's own `uppercats` value + ',') when
     * saveImageOrder()'s $applySubcats is true.
     */
    public function updateImageOrderForDescendants(string $uppercatsPrefix, ?string $imageOrder): void
    {
        $em = $this->em;
        $em->createQueryBuilder()
            ->update(CategoryEntity::class, 'c')
            ->set('c.imageOrder', ':imageOrder')
            ->set('c.lastmodified', ':now')
            ->where('c.uppercats LIKE :uppercatsPrefix')
            ->setParameter('imageOrder', $imageOrder)
            ->setParameter('now', Env::now()->format('Y-m-d H:i:s'))
            ->setParameter('uppercatsPrefix', $uppercatsPrefix . '%')
            ->getQuery()
            ->execute();
        $em->clear();
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, CategoryIdStatus> keyed by id
     *
     * Real DQL -- single-table, static WHERE. `c.id` is custom-Typed
     * (`category_id`) -- `$row['id']` used directly as an array key here
     * would be a fatal TypeError against a real CategoryId object, unlike
     * this class's other Gotcha #1 sites which only silently return a
     * wrong scalar.
     */
    public function findStatusByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $rows = $this->em->getRepository(CategoryEntity::class)->createQueryBuilder('c')
            ->select('c.id', 'c.status')
            ->where('c.id IN (:ids)')
            ->setParameter('ids', $ids, ArrayParameterType::INTEGER)
            ->getQuery()
            ->getArrayResult();

        $byId = [];
        foreach ($rows as $row) {
            if (! is_array($row) || ! ($row['id'] ?? null) instanceof CategoryId || ! ($row['status'] ?? null) instanceof CategoryStatus) {
                continue;
            }

            $byId[$row['id']->value] = new CategoryIdStatus(
                id: $row['id']->value,
                status: $row['status']->value,
            );
        }

        return $byId;
    }

    /**
     * @return list<int>
     *
     * UserAccessEntity::$catId/$userId are VO-typed -- binds the
     * CategoryId VO directly and unwraps ->value on the returned UserId,
     * matching findAccessGroupIds()'s own pattern.
     */
    public function findAccessUserIds(CategoryId $catId): array
    {
        $entities = $this->em
            ->createQueryBuilder()
            ->select('ua')
            ->from(UserAccessEntity::class, 'ua')
            ->where('ua.catId = :catId')
            ->setParameter('catId', $catId)
            ->getQuery()
            ->getResult();

        return array_map(static fn (UserAccessEntity $ua): int => $ua->userId->value, $entities);
    }

    /**
     * @return list<int>
     */
    public function findAccessGroupIds(CategoryId $catId): array
    {
        // Single-value DQL parameter against a custom-typed field -- the
        // well-supported path (unlike the IN-clause array case above),
        // still wraps to keep AbstractNumericIdType::convertToDatabaseValue()
        // strict (VO-only).
        $entities = $this->em
            ->createQueryBuilder()
            ->select('ga')
            ->from(GroupAccessEntity::class, 'ga')
            ->where('ga.catId = :catId')
            ->setParameter('catId', $catId)
            ->getQuery()
            ->getResult();

        return array_map(static fn (GroupAccessEntity $ga): int => $ga->groupId->value, $entities);
    }

    /**
     * @param  list<int>  $keepIds  a non-empty list is guaranteed by the
     *   caller ({@see \Piwigo\Db\NoMatchSentinel::ID} is substituted when
     *   no reference access exists)
     * @param  list<int>  $catIds
     *
     * `$table`/`$field` are {@see CategoryAccessTarget}'s bounded enum,
     * not arbitrary runtime strings. Real DQL -- see that enum's own
     * docblock.
     */
    public function deleteInconsistentAccess(CategoryAccessTarget $target, array $keepIds, array $catIds): void
    {
        $entityClassAndFieldProperty = $target->entityClassAndFieldProperty();
        $entityClass = $entityClassAndFieldProperty->entityClass;
        $fieldProperty = $entityClassAndFieldProperty->property;

        $em = $this->em;
        $em->createQueryBuilder()
            ->delete($entityClass, 't')
            ->where("t.{$fieldProperty} NOT IN (:keepIds)")
            ->andWhere('t.catId IN (:catIds)')
            ->setParameter('keepIds', $keepIds, ArrayParameterType::INTEGER)
            ->setParameter('catIds', $catIds, ArrayParameterType::INTEGER)
            ->getQuery()
            ->execute();
        $em->clear();
    }

    /**
     * @param  array<int>  $ids  real callers don't guarantee a list
     * @return list<string>
     *
     * Real DQL -- single-table, static WHERE; uppercats is a plain string
     * column, so getSingleColumnResult() returns ordinary strings.
     */
    public function findUppercatsColumns(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return array_values(array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '', $this->em->getRepository(CategoryEntity::class)->createQueryBuilder('c')
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
     * Real DQL -- single-table, static WHERE; `c.id` is custom-Typed
     * (`category_id`) -- see this class's own Gotcha #1 note above.
     * `fetchAllKeyValue()` has no direct DQL equivalent, so the
     * id=>uppercats map is built from `getArrayResult()`'s own rows
     * instead.
     */
    public function findUppercatsById(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $rows = $this->em->getRepository(CategoryEntity::class)->createQueryBuilder('c')
            ->select('c.id', 'c.uppercats')
            ->where('c.id IN (:ids)')
            ->setParameter('ids', $ids, ArrayParameterType::INTEGER)
            ->getQuery()
            ->getArrayResult();

        $byId = [];
        foreach ($rows as $row) {
            if (! is_array($row) || ! ($row['id'] ?? null) instanceof CategoryId) {
                continue;
            }

            $uppercats = $row['uppercats'] ?? null;
            $byId[$row['id']->value] = is_scalar($uppercats) ? (string) $uppercats : '';
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
     * `$field`/`$minmax` are {@see CategoryRefDateField}/
     * {@see CategoryRefDateAggregate}'s bounded enums, not arbitrary
     * runtime strings. Real DQL -- see {@see CategoryRefDateField}'s own
     * docblock.
     */
    public function findRefDatesByCategoryIds(array $categoryIds, CategoryRefDateField $field, CategoryRefDateAggregate $minmax): array
    {
        if ($categoryIds === []) {
            return [];
        }

        $aggregateExpr = $minmax->sqlFunction() . '(' . $field->dqlProperty() . ')';

        $rows = $this->em
            ->createQueryBuilder()
            ->select('IDENTITY(ic.category) AS category_id', "{$aggregateExpr} AS ref_date")
            ->from(ImageCategoryEntity::class, 'ic')
            ->innerJoin('ic.image', 'i')
            ->where('ic.category IN (:categoryIds)')
            ->setParameter('categoryIds', $categoryIds, ArrayParameterType::INTEGER)
            ->groupBy('ic.category')
            ->getQuery()
            ->getArrayResult();

        $byCategoryId = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $categoryId = $row['category_id'] ?? null;
            if (is_numeric($categoryId)) {
                $byCategoryId[(int) $categoryId] = $row['ref_date'] ?? null;
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
     * Real DQL -- `image_category` is mapped
     * ({@see \Piwigo\Image\ImageCategoryEntity}), and MySQL's `RAND()` has
     * a portable custom DQL function
     * ({@see \Piwigo\Db\DqlFunction\RandFunction}, per-platform dispatch,
     * MySQL/MariaDB verified, PostgreSQL/SQLite unverified against a real
     * install -- see that class's own docblock). `ic.image` is a real
     * association -- `IDENTITY()` is needed in the `SELECT` regardless of
     * hydration mode, so `getSingleColumnResult()`'s own "never applies a
     * custom Type" safety (Gotcha #4) isn't the relevant reasoning here
     * anymore. `$categoryId` binds as the raw scalar the association
     * comparison expects, not wrapped in `CategoryId`.
     */
    public function findRandomImageIdInCategory(int $categoryId): ?int
    {
        $values = $this->em
            ->createQueryBuilder()
            ->select('IDENTITY(ic.image)')
            ->from(ImageCategoryEntity::class, 'ic')
            ->where('ic.category = :categoryId')
            ->setParameter('categoryId', $categoryId)
            ->orderBy('RAND()')
            ->setMaxResults(1)
            ->getQuery()
            ->getSingleColumnResult();

        $value = $values[0] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * @return array<int|string, string> id => dir, filtered to non-null (dir
     *   is nullable in the schema; only categories with a real directory can
     *   contribute a fulldir segment) -- id keys are numeric strings (PHP
     *   coerces them to int automatically when used as real array keys, but
     *   fetchAllKeyValue()'s own generic type doesn't track that statically)
     *
     * Real DQL -- single-table, static WHERE; `c.id` is custom-Typed
     * (`category_id`) -- see this class's own Gotcha #1 note above.
     * Builds the id=>dir map from `getArrayResult()`'s own rows (no
     * direct `fetchAllKeyValue()` equivalent).
     */
    public function findCategoryDirsById(): array
    {
        $rows = $this->em->getRepository(CategoryEntity::class)->createQueryBuilder('c')
            ->select('c.id', 'c.dir')
            ->where('c.dir IS NOT NULL')
            ->getQuery()
            ->getArrayResult();

        $byId = [];
        foreach ($rows as $row) {
            if (! is_array($row) || ! ($row['id'] ?? null) instanceof CategoryId) {
                continue;
            }

            $dir = $row['dir'] ?? null;
            if (is_string($dir)) {
                $byId[$row['id']->value] = $dir;
            }
        }

        return $byId;
    }

    /**
     * @param  array<int>  $ids  real callers don't guarantee a list
     * @return list<CategoryFulldirRow>
     *
     * Real DQL -- single-table, static WHERE. `c.id` is custom-Typed
     * (`category_id`) -- see this class's own Gotcha #1 note above.
     * `IDENTITY(c.site)` extracts the raw FK id without hydrating the
     * associated `SiteEntity` -- a bare path here would hydrate it
     * instead, since this is a `SELECT`, not a `WHERE`.
     */
    public function findCategoriesForFulldirs(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $rows = $this->em->getRepository(CategoryEntity::class)->createQueryBuilder('c')
            ->select('c.id', 'c.uppercats', 'IDENTITY(c.site) AS site_id')
            ->where('c.dir IS NOT NULL')
            ->andWhere('c.id IN (:ids)')
            ->setParameter('ids', array_values($ids), ArrayParameterType::INTEGER)
            ->getQuery()
            ->getArrayResult();

        $result = [];
        foreach ($rows as $row) {
            if (! is_array($row) || ! ($row['id'] ?? null) instanceof CategoryId) {
                continue;
            }

            $result[] = new CategoryFulldirRow(
                id: $row['id']->value,
                uppercats: is_string($row['uppercats'] ?? null) ? $row['uppercats'] : '',
                siteId: is_numeric($row['site_id'] ?? null) ? (int) $row['site_id'] : null,
            );
        }

        return $result;
    }

    /**
     * @return list<int>
     *
     * Real DQL -- same "queried directly, not from CategoryEntity's own
     * side" shape as {@see findStorageLinkedImageIds()} above.
     * `IDENTITY(i.storageCategory)` extracts the raw FK id without
     * hydrating the associated `CategoryEntity` -- a bare path here would
     * try to hydrate it instead, since this is a `SELECT`, not a `WHERE`.
     */
    public function findDistinctStorageCategoryIds(): array
    {
        return array_values(array_map(
            static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0,
            $this->em
                ->createQueryBuilder()
                ->select('DISTINCT IDENTITY(i.storageCategory)')
                ->from(ImageEntity::class, 'i')
                ->where('i.storageCategory IS NOT NULL')
                ->getQuery()
                ->getSingleColumnResult()
        ));
    }

    /**
     * Writes `images` (Image domain table, no association from
     * CategoryEntity, queried directly same as
     * {@see findStorageLinkedImageIds()} above). DQL's built-in
     * `CONCAT()` accepting 3+ arguments (same as
     * {@see \Piwigo\Category\CategoryRepository::
     * findAllForPermalinksDisplay()}'s own use) collapses the nested
     * `CONCAT(CONCAT(:fulldir, '/'), file)` shape into one flat call.
     * DQL's bulk `UPDATE ... SET` accepts a function call as the new
     * value, same as {@see touchOldPermalinkHit()}'s own
     * self-referential-arithmetic SET precedent establishes this
     * primitive works for non-trivial SET expressions.
     */
    public function updateImagePathsForCategory(CategoryId $categoryId, string $fulldir): void
    {
        // i.storageCategory is an association now -- the bare path in
        // WHERE resolves to the raw join column either way, but the bound
        // parameter must be a raw scalar, not the CategoryId VO: binding
        // the VO directly only worked against the old scalar-Typed column,
        // where the field's own custom Type handled the conversion.
        $this->em
            ->createQueryBuilder()
            ->update(ImageEntity::class, 'i')
            ->set('i.path', "CONCAT(:fulldir, '/', i.file)")
            ->where('i.storageCategory = :categoryId')
            ->setParameter('fulldir', $fulldir)
            ->setParameter('categoryId', $categoryId->value)
            ->getQuery()
            ->execute();
    }

    /**
     * Sets $categoryId's representative image -- Controller\
     * PictureController's own "set_as_representative" action. Caller is
     * responsible for clearing the EntityManager afterward (same contract as
     * before: a DQL bulk UPDATE also bypasses the identity map, so this
     * still doesn't call $em->clear() itself, matching every real caller's
     * own explicit clear() afterward).
     *
     * Real DQL -- single-table, static WHERE, fixed SET column.
     * `c.representativePicture` is an owning-side association now, but the
     * bare path resolves straight to `representative_picture_id`
     * (`SqlWalker::walkPathExpression()`), so a raw scalar bind still works
     * unwrapped -- same as binding a plain int against `c.id` above.
     */
    public function setRepresentativeImage(int $categoryId, int $imageId): void
    {
        $this->em
            ->createQueryBuilder()
            ->update(CategoryEntity::class, 'c')
            ->set('c.representativePicture', ':imageId')
            ->set('c.lastmodified', ':now')
            ->where('c.id = :categoryId')
            ->setParameter('imageId', $imageId)
            ->setParameter('now', Env::now()->format('Y-m-d H:i:s'))
            ->setParameter('categoryId', $categoryId)
            ->getQuery()
            ->execute();
    }

    /**
     * @param  array<int>  $ids  real callers don't guarantee a list
     * @return list<CategoryMoveRow>
     *
     * Real DQL -- single-table, static WHERE, all 4 columns plain-typed.
     */
    public function findCategoriesForMove(array $ids): array
    {
        $rows = $this->em->getRepository(CategoryEntity::class)->createQueryBuilder('c')
            ->select('c.id', 'c.idUppercat AS id_uppercat', 'c.status', 'c.uppercats')
            ->where('c.id IN (:ids)')
            ->setParameter('ids', array_values($ids), ArrayParameterType::INTEGER)
            ->getQuery()
            ->getArrayResult();

        $result = [];
        foreach ($rows as $row) {
            if (! is_array($row) || ! ($row['id'] ?? null) instanceof CategoryId) {
                continue;
            }

            $result[] = new CategoryMoveRow(
                id: $row['id']->value,
                idUppercat: is_numeric($row['id_uppercat'] ?? null) ? (int) $row['id_uppercat'] : null,
                status: ($row['status'] ?? null) instanceof CategoryStatus ? $row['status']->value : '',
                uppercats: is_string($row['uppercats'] ?? null) ? $row['uppercats'] : '',
            );
        }

        return $result;
    }

    /**
     * id is the PK, so this is just $this->find() plus a property read
     * (same idiom as {@see findById()}/{@see updateImageOrder()} elsewhere
     * in this class), rather than a partial-column select.
     */
    public function findCategoryUppercatsById(int $id): ?string
    {
        $catId = CategoryId::tryFrom($id);

        return $catId instanceof CategoryId ? $this->find($catId)?->uppercats : null;
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

        $em = $this->em;
        $em->createQueryBuilder()
            ->update(CategoryEntity::class, 'c')
            ->set('c.idUppercat', ':newParent')
            ->set('c.lastmodified', ':now')
            ->where('c.id IN (:ids)')
            ->setParameter('newParent', $newParent === 'NULL' ? null : (int) $newParent)
            ->setParameter('now', Env::now()->format('Y-m-d H:i:s'))
            ->setParameter('ids', array_values($ids))
            ->getQuery()
            ->execute();
        $em->clear();
    }

    /**
     * id is the PK, same $this->find()-based idiom as
     * {@see findCategoryUppercatsById()} above.
     */
    public function findCategoryStatus(int $id): ?string
    {
        $catId = CategoryId::tryFrom($id);

        return $catId instanceof CategoryId ? $this->find($catId)?->status
            ->value : null;
    }

    /**
     * Single-table, MAX() is a standard DQL aggregate function. An
     * aggregate with no GROUP BY always yields exactly one row (NULL when
     * nothing matches), so getSingleScalarResult() can't throw
     * NoResultException here.
     */
    public function findMaxRankForParent(int|string|null $parentId): ?int
    {
        // Matches the original's own empty($parent_id) semantics (null, 0,
        // '0', and '' all mean "no parent" / root level).
        $parentIsEmpty = $parentId === null || $parentId === 0 || $parentId === '0' || $parentId === '';

        $qb = $this->em->getRepository(CategoryEntity::class)->createQueryBuilder('c')
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
     * @return ParentCategoryForCreate|null
     *
     * Single-table, id is the PK. `visible` is a real bool column on
     * CategoryEntity -- DQL hydrates it as bool.
     */
    public function findParentCategoryForCreate(int|string $parentId): ?ParentCategoryForCreate
    {
        $rows = $this->em->getRepository(CategoryEntity::class)->createQueryBuilder('c')
            ->select('c.id', 'c.uppercats', 'c.globalRank AS global_rank', 'c.visible', 'c.status')
            ->where('c.id = :parentId')
            ->setParameter('parentId', $parentId)
            ->getQuery()
            ->getArrayResult();

        if ($rows === []) {
            return null;
        }

        $row = $rows[0];
        if (! is_array($row) || ! ($row['id'] ?? null) instanceof CategoryId) {
            return null;
        }

        return new ParentCategoryForCreate(
            id: $row['id']->value,
            uppercats: is_string($row['uppercats'] ?? null) ? $row['uppercats'] : '',
            globalRank: is_string($row['global_rank'] ?? null) ? $row['global_rank'] : '',
            visible: (bool) ($row['visible'] ?? false),
            status: ($row['status'] ?? null) instanceof CategoryStatus ? $row['status']->value : '',
        );
    }

    /**
     * Admin\CatOptionsPageRenderer's own "id,name,uppercats,global_rank
     * filtered by one boolean-ish column" shape, 3 of its 4 sections
     * (commentable/visible/status) -- the 4th (representative presence)
     * needs its own method below since its two branches aren't symmetric
     * (only the "no representative" branch joins image_category).
     *
     * Real DQL, inlined into each of the 3 methods below individually --
     * each column condition is a fixed, within-class literal, not a
     * caller-supplied fragment.
     *
     * @return list<CategoryIdNameUppercatsRank>
     */
    public function findByCommentable(bool $commentable): array
    {
        return self::narrowIdNameUppercatsRankRows($this->em->getRepository(CategoryEntity::class)->createQueryBuilder('c')
            ->select('c.id', 'c.name', 'c.uppercats', 'c.globalRank AS global_rank')
            ->where('c.commentable = :value')
            ->setParameter('value', $commentable)
            ->getQuery()
            ->getArrayResult());
    }

    /**
     * @return list<CategoryIdNameUppercatsRank>
     */
    public function findByVisible(bool $visible): array
    {
        return self::narrowIdNameUppercatsRankRows($this->em->getRepository(CategoryEntity::class)->createQueryBuilder('c')
            ->select('c.id', 'c.name', 'c.uppercats', 'c.globalRank AS global_rank')
            ->where('c.visible = :value')
            ->setParameter('value', $visible)
            ->getQuery()
            ->getArrayResult());
    }

    /**
     * @return list<CategoryIdNameUppercatsRank>
     */
    public function findByStatus(string $status): array
    {
        return self::narrowIdNameUppercatsRankRows($this->em->getRepository(CategoryEntity::class)->createQueryBuilder('c')
            ->select('c.id', 'c.name', 'c.uppercats', 'c.globalRank AS global_rank')
            ->where('c.status = :value')
            ->setParameter('value', $status)
            ->getQuery()
            ->getArrayResult());
    }

    /**
     * @param  array<mixed>  $rows
     * @return list<CategoryIdNameUppercatsRank>
     *
     * `c.id` is custom-Typed (`category_id`) -- see this class's own
     * Gotcha #1 note above.
     */
    private static function narrowIdNameUppercatsRankRows(array $rows): array
    {
        $result = [];
        foreach ($rows as $row) {
            if (! is_array($row) || ! ($row['id'] ?? null) instanceof CategoryId) {
                continue;
            }

            $result[] = new CategoryIdNameUppercatsRank(
                id: $row['id']->value,
                name: is_string($row['name'] ?? null) ? $row['name'] : '',
                uppercats: is_string($row['uppercats'] ?? null) ? $row['uppercats'] : '',
                globalRank: is_string($row['global_rank'] ?? null) ? $row['global_rank'] : null,
            );
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
     * @return list<CategoryIdNameUppercatsRank>
     *
     * `image_category` is mapped ({@see \Piwigo\Image\ImageCategoryEntity});
     * both branches use real DQL. The false branch's join goes through
     * `c.imageCategories` (the inverse side of `ImageCategoryEntity::
     * $category`).
     */
    public function findByRepresentativePresence(bool $hasRepresentative): array
    {
        $qb = $this->em->getRepository(CategoryEntity::class)->createQueryBuilder('c')
            ->select('c.id', 'c.name', 'c.uppercats', 'c.globalRank AS global_rank');

        if ($hasRepresentative) {
            $qb->where('c.representativePicture IS NOT NULL');
        } else {
            $qb->distinct()
                ->innerJoin('c.imageCategories', 'ic')
                ->where('c.representativePicture IS NULL');
        }

        return self::narrowIdNameUppercatsRankRows($qb->getQuery()->getArrayResult());
    }

    /**
     * Admin\UserPermPageRenderer's own "category options: authorized"
     * query_true -- private categories directly granted to $userId via
     * user_access, minus any category already covered by one of the
     * user's own group memberships ($groupAuthorizedCatIds).
     *
     * @param list<string> $groupAuthorizedCatIds
     * @return list<CategoryIdNameUppercatsRank>
     *
     * `user_access` is mapped ({@see UserAccessEntity}, no declared
     * association to CategoryEntity, so joined via an explicit
     * `Join::WITH` condition, same shape as
     * {@see \Piwigo\Group\GroupRepository::getAccessibleCategoryIdsForUser()}'s
     * own precedent).
     *
     * UserAccessEntity::$userId/$catId are VO-typed. $userId stays a raw
     * int at this public boundary -- its one real caller
     * (Admin\UserPermPageRenderer) reaches it from a URL param validated
     * only by is_numeric() (not a positive-int guarantee). tryFrom(), not
     * from(): a malformed value can never match a real user_access row
     * either way, so it short-circuits to "no private categories" instead
     * of throwing.
     */
    public function findPrivateCategoriesGrantedToUser(int $userId, array $groupAuthorizedCatIds = []): array
    {
        $userIdVo = UserId::tryFrom($userId);
        if (! $userIdVo instanceof UserId) {
            return [];
        }

        $qb = $this->em->getRepository(CategoryEntity::class)->createQueryBuilder('c')
            ->select('c.id', 'c.name', 'c.uppercats', 'c.globalRank AS global_rank')
            ->innerJoin(UserAccessEntity::class, 'ua', Join::WITH, 'ua.catId = c.id')
            ->where('c.status = :status')
            ->andWhere('ua.userId = :userId')
            ->setParameter('status', 'private')
            ->setParameter('userId', $userIdVo);

        if ($groupAuthorizedCatIds !== []) {
            $qb->andWhere($qb->expr()->notIn('ua.catId', ':groupAuthorized'))
                ->setParameter('groupAuthorized', array_map(intval(...), $groupAuthorizedCatIds), ArrayParameterType::INTEGER);
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
     * @return list<CategoryIdNameUppercatsRank>
     *
     * `group_access` is mapped ({@see GroupAccessEntity}), joined via an
     * explicit `Join::WITH` condition (same precedent as
     * {@see findPrivateCategoriesGrantedToUser()} above). The join condition
     * itself (`ga.catId = c.id`) compiles to a plain SQL `cat_id = id`
     * regardless of GroupAccessEntity's own custom Doctrine Types; only the
     * `ga.groupId = :groupId` parameter needs the {@see GroupId} VO wrapper
     * (the well-supported single-value bind case, not the IN-clause array
     * one).
     *
     * Explicit `ORDER BY c.id`: without it, row order is not guaranteed by
     * SQL and can differ across database engines' query planners even for
     * the same query (this query and its 3 siblings with the identical gap
     * -- {@see findPrivateCategoriesExcluding()}, {@see
     * findIdNameUppercatsRank()}, {@see findPrivateCategoryIdsGrantedToGroup()}
     * -- all need it for this reason, not just for Postgres compatibility).
     */
    public function findPrivateCategoriesGrantedToGroup(int $groupId): array
    {
        return self::narrowIdNameUppercatsRankRows($this->em->getRepository(CategoryEntity::class)->createQueryBuilder('c')
            ->select('c.id', 'c.name', 'c.uppercats', 'c.globalRank AS global_rank')
            ->innerJoin(GroupAccessEntity::class, 'ga', Join::WITH, 'ga.catId = c.id')
            ->where('c.status = :status')
            ->andWhere('ga.groupId = :groupId')
            ->setParameter('status', 'private')
            ->setParameter('groupId', GroupId::from($groupId))
            ->orderBy('c.id', 'ASC')
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
     * @return list<CategoryIdNameUppercatsRank>
     *
     * Single-table, static WHERE plus an optional NOT IN.
     */
    public function findPrivateCategoriesExcluding(array $excludeCatIds): array
    {
        $qb = $this->em->getRepository(CategoryEntity::class)->createQueryBuilder('c')
            ->select('c.id', 'c.name', 'c.uppercats', 'c.globalRank AS global_rank')
            ->where('c.status = :status')
            ->setParameter('status', 'private');

        if ($excludeCatIds !== []) {
            $qb->andWhere($qb->expr()->notIn('c.id', ':excludeCatIds'))
                ->setParameter('excludeCatIds', array_map(intval(...), $excludeCatIds), ArrayParameterType::INTEGER);
        }

        // See findPrivateCategoriesGrantedToGroup()'s
        // own docblock for why this needs an explicit order.
        return self::narrowIdNameUppercatsRankRows($qb->orderBy('c.id', 'ASC')->getQuery()->getArrayResult());
    }

    /**
     * Controller\CommentsController's own "search by album" category
     * listing -- permission-filtered, no other condition.
     *
     * @return list<CategoryIdNameUppercatsRank>
     *
     * This method's own sole real caller ({@see \Piwigo\Controller\
     * CommentsController}'s "search by album" listing, via
     * {@see \Piwigo\Category\CategoryService::displaySelectByCondition()})
     * only ever applies forbiddenCategoryIds/visibleCategoryIds against
     * the unqualified `id` (this table's own, no alias/join here).
     *
     * Uses a typed {@see PermissionCriteria} -- real DQL, reusing
     * {@see narrowIdNameUppercatsRankRows()} for the same narrowing its
     * own sibling method ({@see findPrivateCategoriesExcluding()}) already
     * applies to the identical 4-column shape.
     */
    public function findIdNameUppercatsRank(PermissionCriteria $criteria): array
    {
        $qb = $this->em->getRepository(CategoryEntity::class)->createQueryBuilder('c')
            ->select('c.id', 'c.name', 'c.uppercats', 'c.globalRank AS global_rank');

        SqlCondition::combine(
            'AND',
            $criteria->forbiddenCategoriesCondition('c.id'),
            $criteria->visibleCategoriesCondition('c.id'),
        )->applyTo($qb);

        // See findPrivateCategoriesGrantedToGroup()'s
        // own docblock for why this needs an explicit order.
        return self::narrowIdNameUppercatsRankRows($qb->orderBy('c.id', 'ASC')->getQuery()->getArrayResult());
    }

    /**
     * Controller\Admin\PermalinksSubController's own category listing --
     * every category, `name` replaced with a display label indicating
     * whether it already has a permalink set.
     *
     * MySQL's `IF(permalink IS NULL, "", " &radic;")` builds a different
     * value per branch (not just a NULL fallback COALESCE() could express
     * -- see {@see findNextId()}'s own docblock for that distinction), but
     * DQL's standard `CASE WHEN ... THEN ... ELSE ... END` is a clean,
     * portable drop-in for it, and `CONCAT()` accepting more than 2
     * arguments covers the rest.
     *
     * @return list<CategoryPermalinkDisplayRow>
     */
    public function findAllForPermalinksDisplay(): array
    {
        $rows = $this->em->getRepository(CategoryEntity::class)->createQueryBuilder('c')
            ->select(
                'c.id AS id',
                'c.permalink AS permalink',
                "CONCAT(c.id, ' - ', c.name, CASE WHEN c.permalink IS NULL THEN '' ELSE ' &radic;' END) AS name",
                'c.uppercats AS uppercats',
                'c.globalRank AS global_rank'
            )
            ->getQuery()
            ->getArrayResult();

        $result = [];
        foreach ($rows as $row) {
            if (! is_array($row) || ! ($row['id'] ?? null) instanceof CategoryId) {
                continue;
            }

            $permalink = $row['permalink'] ?? null;
            $result[] = new CategoryPermalinkDisplayRow(
                id: $row['id']->value,
                permalink: $permalink instanceof Permalink ? $permalink->value : null,
                name: is_string($row['name'] ?? null) ? $row['name'] : '',
                uppercats: is_string($row['uppercats'] ?? null) ? $row['uppercats'] : '',
                globalRank: is_string($row['global_rank'] ?? null) ? $row['global_rank'] : null,
            );
        }

        return $result;
    }

    /**
     * Controller\Admin\SiteUpdateSubController's own per-site category
     * listing.
     *
     * @return list<CategoryIdNameUppercatsRank>
     *
     * Single-table, static WHERE.
     */
    public function findIdNameUppercatsRankBySite(int $siteId): array
    {
        return self::narrowIdNameUppercatsRankRows($this->em->getRepository(CategoryEntity::class)->createQueryBuilder('c')
            ->select('c.id', 'c.name', 'c.uppercats', 'c.globalRank AS global_rank')
            ->where('c.site = :siteId')
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
     * Bulk multi-row write via BatchWriter, not something persist()/flush()
     * (one row per flush) expresses.
     */
    public function massUpdateRanks(array $datas): void
    {
        $em = $this->em;
        $now = Env::now()->format('Y-m-d H:i:s');
        new BatchWriter($em->getConnection())
            ->massUpdate(
                'categories',
                [
                    'primary' => ['id'],
                    'update' => ['rank', 'lastmodified'],
                ],
                array_map(static fn (array $data): array => [
                    ...$data,
                    'lastmodified' => $now,
                ], $datas)
            );
        $em->clear();
    }

    /**
     * @param array<int, array{id: int, rank: int, global_rank: ?string}> $datas
     *
     * Bulk write, same as {@see massUpdateRanks()} above.
     */
    public function massUpdateRanksAndGlobalRank(array $datas): void
    {
        $em = $this->em;
        $now = Env::now()->format('Y-m-d H:i:s');
        new BatchWriter($em->getConnection())
            ->massUpdate(
                'categories',
                [
                    'primary' => ['id'],
                    'update' => ['rank', 'global_rank', 'lastmodified'],
                ],
                array_map(static fn (array $data): array => [
                    ...$data,
                    'lastmodified' => $now,
                ], $datas)
            );
        $em->clear();
    }

    /**
     * @param array<int, array{id: int, representative_picture_id: ?int}> $datas
     *
     * Bulk write, same as {@see massUpdateRanks()} above.
     */
    public function massUpdateRepresentativePictures(array $datas): void
    {
        $em = $this->em;
        $now = Env::now()->format('Y-m-d H:i:s');
        new BatchWriter($em->getConnection())
            ->massUpdate(
                'categories',
                [
                    'primary' => ['id'],
                    'update' => ['representative_picture_id', 'lastmodified'],
                ],
                array_map(static fn (array $data): array => [
                    ...$data,
                    'lastmodified' => $now,
                ], $datas)
            );
        $em->clear();
    }

    /**
     * @param array<int, array{id: int, uppercats: string}> $datas
     *
     * Bulk write, same as {@see massUpdateRanks()} above.
     */
    public function massUpdateUppercats(array $datas): void
    {
        $em = $this->em;
        $now = Env::now()->format('Y-m-d H:i:s');
        new BatchWriter($em->getConnection())
            ->massUpdate(
                'categories',
                [
                    'primary' => ['id'],
                    'update' => ['uppercats', 'lastmodified'],
                ],
                array_map(static fn (array $data): array => [
                    ...$data,
                    'lastmodified' => $now,
                ], $datas)
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
     * Stays on DBAL -- dynamic caller-supplied column=>value map, no fixed
     * property path.
     */
    public function insertCategory(array $insert): int|string
    {
        $em = $this->em;
        new BatchWriter($em->getConnection())
            ->singleInsert('categories', $insert);
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
     * Bulk write with a dynamic column set.
     */
    public function massInsertCategories(array $dbfields, array $inserts): void
    {
        if ($inserts === []) {
            return;
        }

        $em = $this->em;
        new BatchWriter($em->getConnection())
            ->massInsert('categories', $dbfields, $inserts);
        $em->clear();
    }

    /**
     * @param array<string, mixed> $data
     *
     * Stays on DBAL -- dynamic caller-supplied column=>value map, same
     * reason as {@see insertCategory()} above.
     */
    public function updateCategoryAfterInsert(int|string $id, array $data): void
    {
        $em = $this->em;
        $data['lastmodified'] = Env::now()->format('Y-m-d H:i:s');
        new BatchWriter($em->getConnection())
            ->singleUpdate('categories', $data, [
                'id' => $id,
            ]);
        $em->clear();
    }

    /**
     * Same generic dynamic-field update as updateCategoryAfterInsert()
     * above, distinct name/call site -- CategoryService's own name/comment
     * edit, not a post-insert patch.
     *
     * @param array<string, mixed> $data
     *
     * Stays on DBAL -- dynamic caller-supplied column=>value map, same
     * reason as {@see insertCategory()} above.
     */
    public function updateFields(CategoryId $id, array $data): void
    {
        if ($data === []) {
            return;
        }

        $em = $this->em;
        $data['lastmodified'] = Env::now()->format('Y-m-d H:i:s');
        new BatchWriter($em->getConnection())
            ->singleUpdate('categories', $data, [
                'id' => $id->value,
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
     * Bulk write with an INSERT IGNORE option ORM persist()/flush() has no
     * equivalent for.
     */
    public function massInsertGroupAccess(array $inserts, bool $ignore = false): void
    {
        $em = $this->em;
        new BatchWriter($em->getConnection())
            ->massInsert('group_access', ['group_id', 'cat_id'], $inserts, [
                'ignore' => $ignore,
            ]);
        $em->clear();
    }

    /**
     * Picks a random representative image among a category's sub-categories
     * (`CategoryCatsRenderer`'s own fallback when a category has no direct
     * representative but does have sub-albums with images).
     *
     * Uses a typed {@see PermissionCriteria} -- the one real caller only
     * ever applies visibleCategoryIds, against the unqualified `id` (no
     * alias here). Deliberately has no `user_cache_categories` JOIN: that precomputed
     * table has no writer left, so a JOIN against it would silently
     * exclude every category for every user -- the caller's own
     * PermissionCriteria condition already duplicates the same "is this
     * category visible" check, so there's nothing the JOIN would add.
     *
     * Real DQL -- {@see PermissionCriteria}'s fragment works directly
     * against a DQL query builder (see {@see applyCondition()}), and
     * `RAND()` uses the same portable custom DQL function
     * ({@see \Piwigo\Db\DqlFunction\RandFunction}) as
     * {@see findRandomImageIdInCategory()}. `IDENTITY(c.representativePicture)`
     * extracts the raw FK id without hydrating the associated `ImageEntity`
     * -- the one context in this file where the bare association path
     * can't be used, since a bare path in `SELECT` would try to hydrate the
     * related entity instead of returning its scalar id.
     */
    public function findRandomRepresentativeIdAmongSubcategories(string $uppercats, PermissionCriteria $criteria): ?string
    {
        $uppercatsLike = $uppercats . ',%';

        $qb = $this->em->getRepository(CategoryEntity::class)->createQueryBuilder('c')
            ->select('IDENTITY(c.representativePicture)')
            ->where('c.uppercats LIKE :uppercatsLike')
            ->andWhere('c.representativePicture IS NOT NULL')
            ->setParameter('uppercatsLike', $uppercatsLike)
            ->orderBy('RAND()')
            ->setMaxResults(1);

        $criteria->visibleCategoriesCondition('c.id')
            ->applyTo($qb);

        $values = $qb->getQuery()
            ->getSingleColumnResult();
        $value = $values[0] ?? null;

        return is_scalar($value) ? (string) $value : null;
    }

    /**
     * First/last photo creation date per category (`CategoryCatsRenderer`'s
     * "from/to" date-range display, gated by `CurrentConfig::displayFromto()`).
     *
     * @param  list<int>  $categoryIds
     * @return array<int, CategoryDateRange> keyed by category id
     *   -- PHP auto-coerces a numeric string array key to int regardless of
     *   how it's written, so `int` (not `string`) is the real runtime key
     *   type.
     *
     * Uses a DQL `QueryBuilder`; {@see PermissionCriteria}'s fragments need
     * no changes for DQL (see {@see applyCondition()}'s own docblock).
     * `MIN(...)`/`MAX(...)` are aliased as `from_date`/`to_date`, not
     * `from`/`to`: `FROM` is a DQL keyword and DQL has no backtick-escaping
     * syntax. This row shape is internal to this method (rebuilt into the
     * `from`/`to`-keyed return array below), not a public contract.
     */
    public function findDateRangeByCategory(array $categoryIds, PermissionCriteria $criteria): array
    {
        if ($categoryIds === []) {
            return [];
        }

        $qb = $this->em
            ->createQueryBuilder()
            ->select('IDENTITY(ic.category) AS category_id', 'MIN(i.dateCreation) AS from_date', 'MAX(i.dateCreation) AS to_date')
            ->from(ImageCategoryEntity::class, 'ic')
            ->innerJoin('ic.image', 'i')
            ->where('ic.category IN (:categoryIds)')
            ->setParameter('categoryIds', $categoryIds, ArrayParameterType::INTEGER)
            ->groupBy('ic.category');

        SqlCondition::combine(
            'AND',
            $criteria->visibleCategoriesCondition('ic.category'),
            $criteria->visibleImagesCondition('i.id'),
            $criteria->maxLevelCondition('i.level'),
        )->applyTo($qb);

        $rows = $qb->getQuery()
            ->getArrayResult();

        $byId = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $categoryId = $row['category_id'] ?? null;
            $categoryIdInt = is_numeric($categoryId) ? (int) $categoryId : null;
            if ($categoryIdInt !== null) {
                $byId[$categoryIdInt] = new CategoryDateRange(
                    from: is_scalar($row['from_date'] ?? null) ? (string) $row['from_date'] : null,
                    to: is_scalar($row['to_date'] ?? null) ? (string) $row['to_date'] : null,
                );
            }
        }

        return $byId;
    }

    /**
     * Single-table, unconditional COUNT.
     */
    public function countAllCategories(): int
    {
        $value = $this->em->getRepository(CategoryEntity::class)->createQueryBuilder('c')
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
     * Single-table; $dirIsNull toggles between two fixed DQL conditions
     * (not a dynamic column name).
     */
    public function findIdsByDirNull(bool $dirIsNull): array
    {
        $qb = $this->em->getRepository(CategoryEntity::class)->createQueryBuilder('c')
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
     * one (physical) -- `Controller\Api\InfoController`'s own "nbVirtual"/
     * "nbPhysical" summary figures.
     *
     * Same reasoning as {@see findIdsByDirNull()} above.
     */
    public function countByDirNull(bool $dirIsNull): int
    {
        $qb = $this->em->getRepository(CategoryEntity::class)->createQueryBuilder('c')
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
     * Single-table, static WHERE against the real bool column.
     */
    public function countByVisible(bool $visible): int
    {
        $value = $this->em->getRepository(CategoryEntity::class)->createQueryBuilder('c')
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
     * Single-table, static WHERE.
     */
    public function findCategoryIdsRepresentedByImage(int $imageId): array
    {
        return array_values(array_map(
            static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0,
            $this->em->getRepository(CategoryEntity::class)->createQueryBuilder('c')
                ->select('c.id')
                ->where('c.representativePicture = :imageId')
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
     * Single-table, static SET/WHERE. Same "caller clears the
     * EntityManager afterward" contract as {@see setRepresentativeImage()}
     * above (both real callers already do).
     */
    public function setRepresentativeImageForCategories(array $categoryIds, int $imageId): void
    {
        if ($categoryIds === []) {
            return;
        }

        $this->em
            ->createQueryBuilder()
            ->update(CategoryEntity::class, 'c')
            ->set('c.representativePicture', ':imageId')
            ->set('c.lastmodified', ':now')
            ->where('c.id IN (:categoryIds)')
            ->setParameter('imageId', $imageId)
            ->setParameter('now', Env::now()->format('Y-m-d H:i:s'))
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
     * `group_access` is mapped ({@see GroupAccessEntity}), joined via
     * explicit `Join::WITH` (same precedent as
     * {@see findPrivateCategoriesGrantedToGroup()} above). Only `c.id` is
     * selected (plain int, not `ga.catId`), so this avoids the
     * custom-Doctrine-Type array-hydration question entirely.
     */
    public function findPrivateCategoryIdsGrantedToGroup(int $groupId): array
    {
        return array_values(array_map(
            static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0,
            $this->em->getRepository(CategoryEntity::class)->createQueryBuilder('c')
                ->select('c.id')
                ->innerJoin(GroupAccessEntity::class, 'ga', Join::WITH, 'ga.catId = c.id')
                ->where('c.status = :status')
                ->andWhere('ga.groupId = :groupId')
                ->setParameter('status', 'private')
                ->setParameter('groupId', GroupId::from($groupId))
                // See findPrivateCategoriesGrantedToGroup()'s
                // own docblock for why this needs an explicit order.
                ->orderBy('c.id', 'ASC')
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
     * @return list<CategoryGroupAuthorizationRow>
     *
     * `user_group`/`group_access` are both mapped ({@see UserGroupEntity}/
     * {@see GroupAccessEntity}), chained via two explicit `Join::WITH`
     * conditions. `c.id AS cat_id` is custom-Typed (`category_id`) -- see
     * this class's own Gotcha #1 note above.
     */
    public function findCategoriesAuthorizedViaGroupsForUser(int $userId): array
    {
        $rows = $this->em
            ->createQueryBuilder()
            ->select('c.id AS cat_id', 'c.uppercats', 'c.globalRank AS global_rank')
            ->distinct()
            ->from(UserGroupEntity::class, 'ug')
            ->innerJoin(GroupAccessEntity::class, 'ga', Join::WITH, 'ug.groupId = ga.groupId')
            ->innerJoin(CategoryEntity::class, 'c', Join::WITH, 'c.id = ga.catId')
            ->where('ug.userId = :userId')
            ->setParameter('userId', UserId::from($userId))
            ->getQuery()
            ->getArrayResult();

        $result = [];
        foreach ($rows as $row) {
            if (! is_array($row) || ! ($row['cat_id'] ?? null) instanceof CategoryId) {
                continue;
            }

            $result[] = new CategoryGroupAuthorizationRow(
                catId: $row['cat_id']->value,
                uppercats: is_string($row['uppercats'] ?? null) ? $row['uppercats'] : '',
                globalRank: is_string($row['global_rank'] ?? null) ? $row['global_rank'] : null,
            );
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
     * `user_access` is mapped ({@see UserAccessEntity}), joined via
     * explicit `Join::WITH`. Only `c.id` is selected (plain int), same
     * reasoning as {@see findPrivateCategoryIdsGrantedToGroup()} above.
     *
     * Same UserId::tryFrom() boundary-safety reasoning as
     * {@see findPrivateCategoriesGrantedToUser()} above -- this method's
     * own real caller reaches it via the identical Admin\UserPermPageRenderer
     * URL param.
     */
    public function findPrivateCategoryIdsGrantedToUser(int $userId, array $excludeCategoryIds): array
    {
        $userIdVo = UserId::tryFrom($userId);
        if (! $userIdVo instanceof UserId) {
            return [];
        }

        $qb = $this->em->getRepository(CategoryEntity::class)->createQueryBuilder('c')
            ->select('c.id')
            ->innerJoin(UserAccessEntity::class, 'ua', Join::WITH, 'ua.catId = c.id')
            ->where('c.status = :status')
            ->andWhere('ua.userId = :userId')
            ->setParameter('status', 'private')
            ->setParameter('userId', $userIdVo);

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
     * Direct children of $parentId (or every root category when null),
     * ordered by rank -- Admin\CatListPageRenderer's own album listing.
     *
     * @return list<CategoryChildRow>
     *
     * Single-table; $parentId toggles between two fixed DQL conditions
     * (not a dynamic column name).
     */
    public function findChildrenOfParent(?int $parentId): array
    {
        $qb = $this->em->getRepository(CategoryEntity::class)->createQueryBuilder('c')
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
            if (! is_array($row) || ! ($row['id'] ?? null) instanceof CategoryId) {
                continue;
            }

            $permalink = $row['permalink'] ?? null;
            $status = $row['status'] ?? null;
            $result[] = new CategoryChildRow(
                id: $row['id']->value,
                name: is_string($row['name'] ?? null) ? $row['name'] : '',
                permalink: $permalink instanceof Permalink ? $permalink->value : null,
                dir: is_string($row['dir'] ?? null) ? $row['dir'] : null,
                rank: is_numeric($row['rank'] ?? null) ? (int) $row['rank'] : null,
                status: $status instanceof CategoryStatus ? $status->value : '',
            );
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
     * Single-table GROUP BY COUNT; `image_category` is mapped
     * ({@see ImageCategoryEntity}). `ic.category` is a real association
     * (owning side) -- `IDENTITY()` is needed in the `SELECT` regardless of
     * hydration mode, so this reads a plain scalar via `is_numeric()`, not
     * the old `instanceof CategoryId` Gotcha #1 pattern
     * {@see \Piwigo\Tag\TagRepository::countImagesPerTagUnrestricted()}'s
     * own `it.tagId`/TagId shape still uses.
     */
    public function findPhotoCountsByCategory(): array
    {
        $rows = $this->em
            ->createQueryBuilder()
            ->select('IDENTITY(ic.category) AS category_id', 'COUNT(IDENTITY(ic.image)) AS counter')
            ->from(ImageCategoryEntity::class, 'ic')
            ->groupBy('ic.category')
            ->getQuery()
            ->getArrayResult();

        $countByCategory = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $categoryId = $row['category_id'] ?? null;
            if (! is_numeric($categoryId)) {
                continue;
            }

            $countByCategory[(int) $categoryId] = is_numeric($row['counter'] ?? null) ? (int) $row['counter'] : 0;
        }

        return $countByCategory;
    }

    /**
     * Every category's own uppercats string, unfiltered and keyed by id --
     * Admin\CatListPageRenderer's own subcategory/photo-rollup computation.
     *
     * @return array<int, string> keyed by id -- CategoryEntity::$uppercats
     *   is a non-nullable string column, always computed for every category
     *
     * Single-table, unconditional select, both columns plain-typed.
     */
    public function findAllCategoryUppercats(): array
    {
        $rows = $this->em->getRepository(CategoryEntity::class)->createQueryBuilder('c')
            ->select('c.id', 'c.uppercats')
            ->getQuery()
            ->getArrayResult();

        $byId = [];
        foreach ($rows as $row) {
            if (! is_array($row) || ! ($row['id'] ?? null) instanceof CategoryId || ! is_string($row['uppercats'] ?? null)) {
                continue;
            }

            $byId[$row['id']->value] = $row['uppercats'];
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
     * Single-table; same $parentId toggle as {@see findChildrenOfParent()}
     * above.
     */
    public function findIdsByParent(?int $parentId): array
    {
        $qb = $this->em->getRepository(CategoryEntity::class)->createQueryBuilder('c')
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
     * @return list<CategoryIdNameUppercat>
     *
     * Single-table, static WHERE, all 3 columns plain-typed.
     */
    public function findIdsNamesUppercatsForIds(array $categoryIds): array
    {
        $rows = $this->em->getRepository(CategoryEntity::class)->createQueryBuilder('c')
            ->select('c.id', 'c.name', 'c.idUppercat AS id_uppercat')
            ->where('c.id IN (:categoryIds)')
            ->setParameter('categoryIds', array_map(intval(...), $categoryIds), ArrayParameterType::INTEGER)
            ->getQuery()
            ->getArrayResult();

        $result = [];
        foreach ($rows as $row) {
            if (! is_array($row) || ! ($row['id'] ?? null) instanceof CategoryId) {
                continue;
            }

            $result[] = new CategoryIdNameUppercat(
                id: $row['id']->value,
                name: is_string($row['name'] ?? null) ? $row['name'] : '',
                idUppercat: is_numeric($row['id_uppercat'] ?? null) ? (int) $row['id_uppercat'] : null,
            );
        }

        return $result;
    }

    /**
     * Every category's id/name/rank/status/visible/uppercats/lastmodified
     * -- Admin\AlbumsPageRenderer's own full album-tree listing.
     *
     * @return list<CategoryAlbumTreeRow>
     *
     * Single-table, unconditional select. `id`/`status`/`lastmodified`
     * are all custom-Typed (`CategoryId`/`CategoryStatus`/`SqlDateTime`),
     * so the row mapper below unwraps each via `instanceof` (Gotcha #1).
     */
    public function findAllForAlbumTree(): array
    {
        $rows = $this->em->getRepository(CategoryEntity::class)->createQueryBuilder('c')
            ->select('c.id', 'c.name', 'c.rank', 'c.status', 'c.visible', 'c.uppercats', 'c.lastmodified')
            ->getQuery()
            ->getArrayResult();

        $result = [];
        foreach ($rows as $row) {
            if (! is_array($row) || ! ($row['id'] ?? null) instanceof CategoryId) {
                continue;
            }

            $status = $row['status'] ?? null;
            $lastmodified = $row['lastmodified'] ?? null;
            $result[] = new CategoryAlbumTreeRow(
                id: $row['id']->value,
                name: is_string($row['name'] ?? null) ? $row['name'] : '',
                rank: is_numeric($row['rank'] ?? null) ? (int) $row['rank'] : null,
                status: $status instanceof CategoryStatus ? $status->value : '',
                visible: (bool) ($row['visible'] ?? false),
                uppercats: is_string($row['uppercats'] ?? null) ? $row['uppercats'] : '',
                lastmodified: $lastmodified instanceof SqlDateTime ? $lastmodified->value : '',
            );
        }

        return $result;
    }

    /**
     * Whether $categoryId has at least one direct image link --
     * Admin\CatModifyPageRenderer's own "has_images" flag.
     *
     * `image_category` is mapped ({@see ImageCategoryEntity}). A COUNT
     * aggregate always returns exactly one row, so there's no LIMIT to
     * preserve. `ic.category` is a real association -- `IDENTITY()` is
     * needed inside the `COUNT()` regardless of hydration mode, and the
     * bound `$categoryId` is the raw scalar an association comparison
     * expects, not wrapped in {@see CategoryId} (that VO-wrapping
     * convention was for the old scalar-Typed column; an association has
     * no field-level custom Type to consult during binding).
     */
    public function hasImages(int $categoryId): bool
    {
        $count = $this->em
            ->createQueryBuilder()
            ->select('COUNT(IDENTITY(ic.image))')
            ->from(ImageCategoryEntity::class, 'ic')
            ->where('ic.category = :categoryId')
            ->setParameter('categoryId', $categoryId)
            ->getQuery()
            ->getSingleScalarResult();

        return is_numeric($count) && (int) $count > 0;
    }

    /**
     * Photo count plus min/max date_available for $categoryId's own direct
     * images -- Admin\CatModifyPageRenderer's own "this album contains N
     * photos, added between X and Y" summary.
     *
     * @return PhotoCountDateRange
     *
     * `image_category` is mapped ({@see \Piwigo\Image\ImageCategoryEntity}).
     * Fetches raw rows and computes count/min/max in PHP -- MySQL's
     * `DATE()` has no portable DQL equivalent, and the caller's
     * `count`/`min`/`max` shape doesn't need DQL's named field selects at
     * all once the aggregation itself moves to PHP.
     * `dateAvailable` is a `Y-m-d H:i:s` string, so
     * `substr($dateAvailable, 0, 10)` reproduces `DATE(date_available)`'s
     * output exactly.
     */
    public function findPhotoCountAndDateRange(int $categoryId): PhotoCountDateRange
    {
        $rows = $this->em
            ->createQueryBuilder()
            ->select('i.dateAvailable AS date_available')
            ->from(ImageCategoryEntity::class, 'ic')
            ->innerJoin('ic.image', 'i')
            ->where('ic.category = :categoryId')
            ->setParameter('categoryId', $categoryId)
            ->getQuery()
            ->getArrayResult();

        $count = 0;
        $minDate = null;
        $maxDate = null;

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $count++;

            $dateAvailableRaw = $row['date_available'] ?? null;
            $dateAvailable = $dateAvailableRaw instanceof SqlDateTime ? $dateAvailableRaw->value : null;
            if ($dateAvailable === null) {
                continue;
            }

            $date = substr($dateAvailable, 0, 10);
            if ($minDate === null || $date < $minDate) {
                $minDate = $date;
            }

            if ($maxDate === null || $date > $maxDate) {
                $maxDate = $date;
            }
        }

        return new PhotoCountDateRange($count, $minDate, $maxDate);
    }

    /**
     * Distinct image ids across every id in $categoryIds -- Admin\
     * CatModifyPageRenderer's own recursive (including sub-albums) photo
     * count.
     *
     * @param list<int> $categoryIds
     * @return list<int>
     *
     * Single-table; `image_category` is mapped
     * ({@see ImageCategoryEntity}). Same shape as
     * {@see findDistinctLinkedImageIds()} above (`$categoryIds` is still
     * spliced in unconditionally, even when empty).
     */
    public function findDistinctImageIdsInCategories(array $categoryIds): array
    {
        $rows = $this->em
            ->createQueryBuilder()
            ->select('DISTINCT IDENTITY(ic.image)')
            ->from(ImageCategoryEntity::class, 'ic')
            ->where('ic.category IN (:categoryIds)')
            ->setParameter('categoryIds', $categoryIds, ArrayParameterType::INTEGER)
            ->getQuery()
            ->getSingleColumnResult();

        return array_values(array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $rows));
    }

    /**
     * `dir` keyed by id for $ids -- Admin\CatModifyPageRenderer's own
     * getLocalDir() path-segment resolution.
     *
     * @param list<int|string> $ids
     * @return array<int, ?string> keyed by id -- CategoryEntity::$dir is a
     *   nullable string column (only set for on-disk "physical" categories)
     *
     * Single-table, static WHERE, both columns plain-typed.
     */
    public function findDirsByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $rows = $this->em->getRepository(CategoryEntity::class)->createQueryBuilder('c')
            ->select('c.id', 'c.dir')
            ->where('c.id IN (:ids)')
            ->setParameter('ids', array_map(intval(...), $ids), ArrayParameterType::INTEGER)
            ->getQuery()
            ->getArrayResult();

        $byId = [];
        foreach ($rows as $row) {
            if (! is_array($row) || ! ($row['id'] ?? null) instanceof CategoryId) {
                continue;
            }

            $dir = $row['dir'] ?? null;
            $byId[$row['id']->value] = is_string($dir) ? $dir : null;
        }

        return $byId;
    }

    /**
     * id/permalink/uppercats/global_rank for every category with an active
     * permalink -- Controller\Admin\PermalinksSubController's own listing.
     * $orderByColumn is 'id'/'permalink'/null (its only real caller's own
     * `$sort_by[0] === 'id' or $sort_by[0] === 'permalink'` check --
     * the caller sorts by global_rank itself afterward when not sorting
     * by id/permalink).
     *
     * The caller-supplied raw "ORDER BY ..." fragment is one of exactly 3
     * finite shapes at its one real caller, so $orderByColumn carries just
     * the column name (or null), and this method decides the DQL
     * `orderBy()` call itself.
     *
     * @return list<ActivePermalinkRow>
     */
    public function findActivePermalinksList(?string $orderByColumn): array
    {
        $qb = $this->em->getRepository(CategoryEntity::class)->createQueryBuilder('c')
            ->select('c.id AS id', 'c.permalink AS permalink', 'c.uppercats AS uppercats', 'c.globalRank AS global_rank')
            ->where('c.permalink IS NOT NULL');

        match ($orderByColumn) {
            'id' => $qb->orderBy('c.id'),
            'permalink' => $qb->orderBy('c.permalink'),
            default => null,
        };

        $rows = $qb->getQuery()
            ->getArrayResult();

        $result = [];
        foreach ($rows as $row) {
            if (! is_array($row) || ! ($row['id'] ?? null) instanceof CategoryId) {
                continue;
            }

            $permalink = $row['permalink'] ?? null;
            $result[] = new ActivePermalinkRow(
                id: $row['id']->value,
                permalink: $permalink instanceof Permalink ? $permalink->value : null,
                uppercats: is_string($row['uppercats'] ?? null) ? $row['uppercats'] : '',
                globalRank: is_string($row['global_rank'] ?? null) ? $row['global_rank'] : null,
            );
        }

        return $result;
    }

    /**
     * Whether $catId exists and isn't among $forbiddenCategoriesCsv --
     * Controller\SearchController's own "does this album exist and is it
     * accessible" check.
     *
     * Single-table, static WHERE; $forbiddenIds is a plain int list
     * computed in PHP before the query runs, not a raw fragment.
     */
    public function existsAndNotForbidden(int $catId, string $forbiddenCategoriesCsv): bool
    {
        $forbiddenIds = array_map(intval(...), array_filter(explode(',', $forbiddenCategoriesCsv), is_numeric(...)));
        if ($forbiddenIds === []) {
            $forbiddenIds = [0];
        }

        $rows = $this->em->getRepository(CategoryEntity::class)->createQueryBuilder('c')
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
     * Whether a category with this id exists -- used by several real
     * `Controller\Api\Categories\*` controllers' own existence checks
     * (e.g. `CategorySetRepresentativeController`).
     *
     * Single-table, static WHERE, COUNT aggregate.
     */
    public function existsById(int $id): bool
    {
        $value = $this->em->getRepository(CategoryEntity::class)->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->where('c.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getSingleScalarResult();

        return is_numeric($value) && (int) $value > 0;
    }

    /**
     * Ids from $ids that really exist -- CategoryService's own "do these
     * categories really exist" checks.
     *
     * @param  list<int>  $ids
     * @return list<int>
     *
     * Single-table, static WHERE.
     */
    public function findExistingIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return array_values(array_map(
            static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0,
            $this->em->getRepository(CategoryEntity::class)->createQueryBuilder('c')
                ->select('c.id')
                ->where('c.id IN (:ids)')
                ->setParameter('ids', $ids, ArrayParameterType::INTEGER)
                ->getQuery()
                ->getSingleColumnResult()
        ));
    }

    /**
     * Which of $ids are real category ids, minus $excludeIds --
     * SearchService's own quick-search subcategory-expansion step
     * (subcategory ids already resolved by CategoryService::getSubcatIds(),
     * narrowed to real, non-forbidden ones).
     *
     * @param  list<int>  $ids
     * @param  list<int>  $excludeIds
     * @return list<int>
     */
    public function findIdsAmongExcluding(array $ids, array $excludeIds): array
    {
        if ($ids === []) {
            return [];
        }

        $qb = $this->em->getRepository(CategoryEntity::class)->createQueryBuilder('c')
            ->select('c.id')
            ->where('c.id IN (:ids)')
            ->setParameter('ids', $ids, ArrayParameterType::INTEGER);

        if ($excludeIds !== []) {
            $qb->andWhere('c.id NOT IN (:excludeIds)')
                ->setParameter('excludeIds', $excludeIds, ArrayParameterType::INTEGER);
        }

        return array_values(array_map(
            static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0,
            $qb->getQuery()
                ->getSingleColumnResult()
        ));
    }

    /**
     * id/image_order for categories matching already-built $conditions --
     * `Controller\Api\Categories\CategoryImagesController`'s own "which
     * categories are we fetching images for" step.
     *
     * @param  list<SqlCondition>  $conditions
     * @return list<CategoryIdImageOrder>
     *
     * $conditions is a list of caller-built SqlCondition fragments (its
     * one real caller combines a dynamically-sized per-`cat_id` OR-chain
     * with a {@see \Piwigo\Permission\PermissionCriteria} fragment). All of
     * them may be empty at once -- no `cat_id` filter and no permission
     * restriction for this user -- in which case the query runs unfiltered
     * rather than against a `1 = 1` stand-in.
     *
     * Real DQL -- single-table, no join. The caller's own `RLIKE`/`REGEXP`
     * operator splice is solved by
     * {@see \Piwigo\Db\DqlFunction\RegexpFunction} (registered, already
     * used elsewhere in this file); the caller builds `c.`-prefixed DQL
     * property paths and the portable `REGEXP(...) = true` DQL function
     * instead of a raw SQL fragment.
     */
    public function findIdsAndImageOrderWithConditions(array $conditions): array
    {
        $qb = $this->em->getRepository(CategoryEntity::class)->createQueryBuilder('c')
            ->select('c.id', 'c.imageOrder');

        SqlCondition::combine('AND', ...$conditions)->applyTo($qb);

        $rows = $qb->getQuery()
            ->getArrayResult();

        $result = [];
        foreach ($rows as $row) {
            if (! is_array($row) || ! ($row['id'] ?? null) instanceof CategoryId) {
                continue;
            }

            $result[] = new CategoryIdImageOrder(
                id: $row['id']->value,
                imageOrder: is_string($row['imageOrder'] ?? null) ? $row['imageOrder'] : null,
            );
        }

        return $result;
    }

    /**
     * The scope condition shared by findAvailableList()/findAdminList()
     * below -- exactly one of 3 mutually-exclusive branches (non-recursive
     * with a real $catId / non-recursive without / recursive with a real
     * $catId), or none at all (recursive with no $catId -- matches the
     * original, which added nothing to $where in that case).
     *
     * `RegexpFunction` (registered as a DQL function, called via
     * `REGEXP(column, pattern)`) resolves the recursive-with-`$catId`
     * branch's per-platform operator (MySQL/MariaDB: `RLIKE`,
     * PostgreSQL: `~`, not `AbstractPlatform::getRegexpExpression()`'s
     * `SIMILAR TO` -- a genuinely different, whole-string-anchored
     * pattern-matching dialect) internally, so no platform lookup is
     * needed here the way a raw-SQL sibling of this method would need.
     */
    private function categoryScopeConditionDql(?CategoryId $catId, bool $recursive, string $propPrefix = 'c.'): SqlCondition
    {
        if (! $recursive) {
            if ($catId instanceof CategoryId) {
                return SqlCondition::fromRawSql('(' . $propPrefix . 'idUppercat = :catId OR ' . $propPrefix . 'id = :catId)', [
                    'catId' => $catId->value,
                ]);
            }

            return SqlCondition::fromRawSql($propPrefix . 'idUppercat IS NULL');
        }

        if ($catId instanceof CategoryId) {
            return SqlCondition::fromRawSql('REGEXP(' . $propPrefix . 'uppercats, :catUppercatsLike) = TRUE', [
                'catUppercatsLike' => '(^|,)' . $catId->value . '(,|$)',
            ]);
        }

        return SqlCondition::fromRawSql('');
    }

    /**
     * `Controller\Api\Categories\CategoryAvailableListController`'s own
     * paginated category rollup. Builds
     * its own scope/forbidden-categories/public-only conditions internally
     * via {@see categoryScopeConditionDql()} and SqlCondition::combine(),
     * from a typed CategoryListCriteria. $searchTerm/$searchLimit/$limit/
     * $limitPlusOne: a search term gets its own LIMIT only when no explicit
     * $limit is requested; $limit itself gets +1 when $limitPlusOne
     * (single-category scope), to detect "more remain" without a second
     * query. The total is only computed when $limit !== null.
     *
     * @return PaginatedResult<CategoryAvailableListRow>
     *
     * Computes the total via `COUNT_OVER() AS total_count` (the
     * {@see \Piwigo\Db\DqlFunction\CountOverFunction}-backed DQL name for
     * `COUNT(*) OVER()`) in the same query as the row data (no
     * `DISTINCT`/`GROUP BY` here, so the window function's count is
     * exact) rather than a second round-trip. `total_count` is stripped
     * back out of each row before returning -- it's not part of this
     * method's own row shape, only `PaginatedResult::$total`.
     *
     * `getArrayResult()` applies real Doctrine Type conversion to every
     * selected state-field path -- `id`/`status`/`permalink` come back as
     * real `CategoryId`/`CategoryStatus`/`Permalink` instances, unwrapped
     * via {@see unwrapCategoryListRowVoFields()} before being handed to
     * {@see CategoryAvailableListRow::fromRow()}, which is otherwise
     * unchanged.
     */
    public function findAvailableList(
        CategoryListCriteria $criteria,
        ?string $searchTerm,
        int $searchLimit,
        ?int $limit,
        bool $limitPlusOne
    ): PaginatedResult {
        $conditions = [$this->categoryScopeConditionDql($criteria->catId, $criteria->recursive)];

        if ($criteria->forbiddenCategoryIds !== []) {
            $conditions[] = SqlCondition::fromRawSql('c.id NOT IN (:forbiddenCategoryIds)', [
                'forbiddenCategoryIds' => $criteria->forbiddenCategoryIds,
            ], [
                'forbiddenCategoryIds' => ArrayParameterType::INTEGER,
            ]);
        }

        if ($criteria->publicOnly) {
            // `visible` is a genuine boolean column -- a bare `1` literal
            // is valid MySQL tinyint(1) input but Postgres rejects it
            // outright against a real boolean column, so it's bound as a
            // real BOOLEAN parameter instead of a per-platform literal.
            $conditions[] = SqlCondition::fromRawSql("c.status = 'public' AND c.visible = :visible", [
                'visible' => true,
            ], [
                'visible' => ParameterType::BOOLEAN,
            ]);
        }

        if ($searchTerm !== null) {
            $conditions[] = SqlCondition::fromRawSql('c.name LIKE :searchTerm', [
                'searchTerm' => LikePattern::containing($searchTerm),
            ]);
        }

        $qb = $this->em
            ->createQueryBuilder()
            ->select(
                'c.id',
                'c.name',
                'c.comment',
                'c.permalink',
                'c.status',
                'c.uppercats',
                'c.globalRank AS global_rank',
                'c.idUppercat AS id_uppercat',
                'IDENTITY(c.representativePicture) AS representative_picture_id',
                'c.imageOrder AS image_order',
            )
            ->from(CategoryEntity::class, 'c');
        SqlCondition::combine('AND', ...$conditions)->applyTo($qb);

        if ($limit !== null) {
            $qb->addSelect('COUNT_OVER() AS total_count');
        }

        if ($searchTerm !== null && $limit === null) {
            $qb->setMaxResults($searchLimit);
        }

        if ($limit !== null) {
            $qb->orderBy('c.rank', 'ASC')
                ->setMaxResults($limit + ($limitPlusOne ? 1 : 0));
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = $qb->getQuery()
            ->getArrayResult();

        $total = null;
        if ($limit !== null) {
            $total = $rows !== [] && is_numeric($rows[0]['total_count'] ?? null) ? (int) $rows[0]['total_count'] : 0;
        }

        return new PaginatedResult(
            array_map(static fn (array $row): CategoryAvailableListRow => CategoryAvailableListRow::fromRow(self::unwrapCategoryListRowVoFields($row)), $rows),
            $total
        );
    }

    /**
     * `Controller\Api\Categories\CategoryListController`'s own paginated
     * category rollup, via CategoryAdminListCriteria (no
     * forbidden-categories/public-only fields at all -- this list is
     * admin-only). Always computes the total,
     * unlike {@see findAvailableList()}'s own $limit-gated fetch.
     *
     * @return PaginatedResult<CategoryAdminListRow>
     *
     * Computes the total the same way as {@see findAvailableList()} above
     * (`COUNT_OVER() AS total_count`) -- no `DISTINCT`/`GROUP BY` here
     * either. Same `getArrayResult()`/VO-unwrap reasoning as
     * {@see findAvailableList()} above.
     */
    public function findAdminList(CategoryAdminListCriteria $criteria, ?string $searchTerm, int $searchLimit): PaginatedResult
    {
        $conditions = [$this->categoryScopeConditionDql($criteria->catId, $criteria->recursive)];
        if ($searchTerm !== null) {
            $conditions[] = SqlCondition::fromRawSql('c.name LIKE :searchTerm', [
                'searchTerm' => LikePattern::containing($searchTerm),
            ]);
        }

        $qb = $this->em
            ->createQueryBuilder()
            ->select(
                'COUNT_OVER() AS total_count',
                'c.id',
                'c.name',
                'c.comment',
                'c.uppercats',
                'c.globalRank AS global_rank',
                'c.dir',
                'c.status',
                'c.imageOrder AS image_order',
            )
            ->from(CategoryEntity::class, 'c');
        SqlCondition::combine('AND', ...$conditions)->applyTo($qb);

        if ($searchTerm !== null) {
            $qb->setMaxResults($searchLimit);
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = $qb->getQuery()
            ->getArrayResult();
        $total = $rows !== [] && is_numeric($rows[0]['total_count'] ?? null) ? (int) $rows[0]['total_count'] : 0;

        return new PaginatedResult(
            array_map(static fn (array $row): CategoryAdminListRow => CategoryAdminListRow::fromRow(self::unwrapCategoryListRowVoFields($row)), $rows),
            $total
        );
    }

    /**
     * {@see findAvailableList()}/{@see findAdminList()}'s shared
     * `getArrayResult()` VO-unwrap step -- `id`/`status`/`permalink`
     * come back as real `CategoryId`/`CategoryStatus`/`Permalink`
     * instances from a direct state-field-path DQL select, unlike
     * `getSingleColumnResult()`'s well-established no-conversion
     * behavior. Unwrapped here, once, so both `fromRow()` methods keep
     * their own existing flat-array/`is_string`/`is_numeric` contract
     * unchanged.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private static function unwrapCategoryListRowVoFields(array $row): array
    {
        if (($row['id'] ?? null) instanceof CategoryId) {
            $row['id'] = $row['id']->value;
        }

        if (($row['status'] ?? null) instanceof CategoryStatus) {
            $row['status'] = $row['status']->value;
        }

        if (($row['permalink'] ?? null) instanceof Permalink) {
            $row['permalink'] = $row['permalink']->value;
        }

        return $row;
    }

    /**
     * Subcategory counts grouped by parent id -- `CategoryListController`'s
     * own non-recursive "nb_categories" column.
     *
     * @param  list<int>  $parentIds
     * @return array<int, int> keyed by id_uppercat -- the `(string)` cast
     *   below never actually produces a string key for a real
     *   id_uppercat value: PHP normalizes any canonical decimal-integer
     *   string key back to int at the point of array assignment.
     *
     * Single-table, standard COUNT + GROUP BY aggregate.
     */
    public function findSubcategoryCountsByParent(array $parentIds): array
    {
        if ($parentIds === []) {
            return [];
        }

        $rows = $this->em->getRepository(CategoryEntity::class)->createQueryBuilder('c')
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
            if (is_numeric($idUppercat) && is_numeric($nbSubcats)) {
                $bySubcat[(int) $idUppercat] = (int) $nbSubcats;
            }
        }

        return $bySubcat;
    }

    /**
     * id/id_uppercat/rank for $ids -- `CategoryReorderController`'s own
     * "does the category really exist" check plus the sibling data it
     * needs afterward.
     *
     * @param  list<int>  $ids
     * @return list<CategoryRankInfoRow>
     *
     * Single-table, static WHERE, all 3 columns plain-typed.
     */
    public function findRankInfoByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $rows = $this->em->getRepository(CategoryEntity::class)->createQueryBuilder('c')
            ->select('c.id', 'c.idUppercat AS id_uppercat', 'c.rank')
            ->where('c.id IN (:ids)')
            ->setParameter('ids', $ids, ArrayParameterType::INTEGER)
            ->getQuery()
            ->getArrayResult();

        $result = [];
        foreach ($rows as $row) {
            if (! is_array($row) || ! ($row['id'] ?? null) instanceof CategoryId) {
                continue;
            }

            $result[] = new CategoryRankInfoRow(
                id: $row['id']->value,
                idUppercat: is_numeric($row['id_uppercat'] ?? null) ? (int) $row['id_uppercat'] : null,
                rank: is_numeric($row['rank'] ?? null) ? (int) $row['rank'] : null,
            );
        }

        return $result;
    }

    /**
     * Ids of every category directly under $parentId (or top-level, when
     * null), ordered by id -- `CategoryReorderController`'s own
     * "does the caller-provided order cover every sibling" check, which
     * relies on this exact id-ascending order to compare against the
     * caller's own numerically-sorted id list.
     *
     * @return list<int>
     *
     * Single-table; $parentId toggles between two fixed DQL conditions.
     */
    public function findIdsByParentOrderedById(?int $parentId): array
    {
        $qb = $this->em->getRepository(CategoryEntity::class)->createQueryBuilder('c')
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
     * when null), ordered by rank -- `CategoryReorderController`'s own
     * "insert the new category into its siblings' existing rank order"
     * step.
     *
     * @return list<int>
     *
     * Single-table; same $parentId toggle as
     * {@see findIdsByParentOrderedById()} above.
     */
    public function findSiblingIdsExcludingOrderedByRank(?int $parentId, int $excludeId): array
    {
        $qb = $this->em->getRepository(CategoryEntity::class)->createQueryBuilder('c')
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
     * id/name/dir/uppercats for $ids -- `CategoryMoveController`'s own
     * "reject physical categories, and remember every ancestor to
     * refresh" step. A different 4-column shape from
     * {@see findCategoriesForMove()} above (that one is
     * id/id_uppercat/status/uppercats, for a different real caller).
     *
     * @param  list<int>  $ids
     * @return list<CategoryMoveDetailRow>
     *
     * Single-table, static WHERE, all 4 columns plain-typed.
     */
    public function findMoveDetailsByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $rows = $this->em->getRepository(CategoryEntity::class)->createQueryBuilder('c')
            ->select('c.id', 'c.name', 'c.dir', 'c.uppercats')
            ->where('c.id IN (:ids)')
            ->setParameter('ids', $ids, ArrayParameterType::INTEGER)
            ->getQuery()
            ->getArrayResult();

        $result = [];
        foreach ($rows as $row) {
            if (! is_array($row) || ! ($row['id'] ?? null) instanceof CategoryId) {
                continue;
            }

            $result[] = new CategoryMoveDetailRow(
                id: $row['id']->value,
                name: is_string($row['name'] ?? null) ? $row['name'] : '',
                dir: is_string($row['dir'] ?? null) ? $row['dir'] : null,
                uppercats: is_string($row['uppercats'] ?? null) ? $row['uppercats'] : '',
            );
        }

        return $result;
    }

    /**
     * Next free id -- Controller\Admin\SiteUpdateSubController's own
     * manual-id assignment for directory-synced categories (mirrors the
     * retired MysqliDb::nextval()).
     *
     * `IF()` is MySQL-specific, but this particular "NULL becomes a
     * default" shape is exactly what DQL's standard `COALESCE()` expresses
     * (unlike {@see findAllForPermalinksDisplay()}'s own `IF()` use, which
     * builds a different value per branch, not just a NULL fallback).
     */
    public function findNextId(): int
    {
        $next = $this->em->getRepository(CategoryEntity::class)->createQueryBuilder('c')
            ->select('COALESCE(MAX(c.id) + 1, 1)')
            ->getQuery()
            ->getSingleScalarResult();

        return is_numeric($next) ? (int) $next : 1;
    }

    /**
     * Categories with a physical `dir`, scoped to $siteId (directory-based
     * synchronization's own candidate set) -- Controller\Admin\
     * SiteUpdateSubController's own "which categories to update" step.
     * $catId/$recursive narrow further, matching that one real caller's
     * own exactly-3-shapes logic: no `$catId` means no extra filter,
     * `$recursive` true means every descendant of $catId (uppercats
     * REGEXP match), false means $catId itself only.
     *
     * $catId/$recursive carry the intent directly, and this method builds
     * the DQL condition itself, reusing the same portable REGEXP DQL
     * function ({@see \Piwigo\Db\DqlFunction\RegexpFunction})
     * {@see findSubcategoryIds()} already established for the exact same
     * `uppercats REGEXP '(^|,)ID(,|$)'` pattern.
     *
     * @return list<CategorySyncCandidateRow>
     */
    public function findSyncCandidatesForSite(int $siteId, ?int $catId, bool $recursive): array
    {
        $qb = $this->em->getRepository(CategoryEntity::class)->createQueryBuilder('c')
            ->select('c.id AS id', 'c.uppercats AS uppercats', 'c.globalRank AS global_rank', 'c.status AS status', 'c.visible AS visible')
            ->where('c.dir IS NOT NULL')
            ->andWhere('c.site = :siteId')
            ->setParameter('siteId', $siteId);

        if ($catId !== null) {
            if ($recursive) {
                $qb->andWhere('REGEXP(c.uppercats, :uppercatsLike) = true')
                    ->setParameter('uppercatsLike', '(^|,)' . $catId . '(,|$)');
            } else {
                $qb->andWhere('c.id = :catId')
                    ->setParameter('catId', $catId);
            }
        }

        $rows = $qb->getQuery()
            ->getArrayResult();

        $result = [];
        foreach ($rows as $row) {
            if (! is_array($row) || ! ($row['id'] ?? null) instanceof CategoryId) {
                continue;
            }

            $status = $row['status'] ?? null;
            $result[] = new CategorySyncCandidateRow(
                id: $row['id']->value,
                uppercats: is_string($row['uppercats'] ?? null) ? $row['uppercats'] : '',
                globalRank: is_string($row['global_rank'] ?? null) ? $row['global_rank'] : null,
                status: $status instanceof CategoryStatus ? $status->value : '',
                visible: (bool) ($row['visible'] ?? false),
            );
        }

        return $result;
    }

    /**
     * Every category id, unfiltered -- Controller\Admin\
     * SiteUpdateSubController's own rank-bootstrap step ("every category
     * defaults to next-rank 1 on its own sub-categories, until proven
     * otherwise below").
     *
     * @return list<int>
     *
     * Single-table, unconditional select.
     */
    public function findAllIds(): array
    {
        return array_values(array_map(
            static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0,
            $this->em->getRepository(CategoryEntity::class)->createQueryBuilder('c')
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
     * @return list<CategoryNextRankByParentRow>
     *
     * Single-table; MAX()+1 is a standard DQL aggregate/arithmetic
     * expression.
     */
    public function findNextRanksByParent(): array
    {
        $rows = $this->em->getRepository(CategoryEntity::class)->createQueryBuilder('c')
            ->select('c.idUppercat AS id_uppercat', 'MAX(c.rank) + 1 AS next_rank')
            ->groupBy('c.idUppercat')
            ->getQuery()
            ->getArrayResult();

        $result = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $idUppercat = $row['id_uppercat'] ?? null;
            $nextRank = $row['next_rank'] ?? null;
            $result[] = new CategoryNextRankByParentRow(
                idUppercat: is_numeric($idUppercat) ? (int) $idUppercat : null,
                nextRank: is_numeric($nextRank) ? (int) $nextRank : null,
            );
        }

        return $result;
    }
}
