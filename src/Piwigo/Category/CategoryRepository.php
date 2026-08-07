<?php

declare(strict_types=1);

namespace Piwigo\Category;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\Expr\Join;
use Piwigo\Category\Projection\Category;
use Piwigo\Common\Dto\PaginatedResult;
use Piwigo\Common\ValueObject\CategoryId;
use Piwigo\Common\ValueObject\GroupId;
use Piwigo\Common\ValueObject\Permalink;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Config\CurrentConfig;
use Piwigo\Db\BatchWriter;
use Piwigo\Db\SqlDialect;
use Piwigo\Db\Tables;
use Piwigo\Group\GroupAccessEntity;
use Piwigo\Group\UserGroupEntity;
use Piwigo\Image\ImageCategoryEntity;
use Piwigo\Image\ImageEntity;
use Piwigo\Image\PhotoSortField;
use Piwigo\Permalink\OldPermalinkEntity;
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
 * the Permalink domain ({@see \Piwigo\Permalink\OldPermalinkEntity}, see
 * its own docblock -- this repository is one of its 3 real consumers,
 * queried directly rather than via `$em->getRepository()`, same shape as
 * the shared tables below), and shares `user_access` ({@see UserAccessEntity})/
 * `group_access` ({@see \Piwigo\Group\GroupAccessEntity}, created during
 * the Group batch)/`image_category` ({@see \Piwigo\Image\ImageCategoryEntity},
 * placed in the Image domain, its heaviest real consumer -- Item 14
 * Sub-phase B1) with Group/Image/Permission -- only the single-row/
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
 * `CurrentConfig`, needed for its `CurrentConfig::orderBy()`/
 * `orderByCustom()` reads). `CategoryEntity`'s own `#[ORM\Entity]` mapping
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
final class CategoryRepository
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CurrentConfig $currentConfig,
    ) {}

    private function find(int $id): ?CategoryEntity
    {
        return $this->em->find(CategoryEntity::class, $id);
    }

    /**
     * Accepts either query-builder flavor -- {@see SqlCondition}'s own
     * `sql`/`parameters`/`types` shape applies identically via
     * `andWhere()`/`setParameter()` on both DBAL's and DQL's query
     * builders, confirmed empirically (Item 15 audit): a DQL consumer
     * just passes a DQL property path (e.g. `c.id`) into the same
     * {@see PermissionCriteria} `*Condition()` methods a DBAL consumer
     * already uses with a raw column name.
     */
    private static function applyCondition(QueryBuilder|\Doctrine\ORM\QueryBuilder $qb, SqlCondition $condition): void
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

        $rows = $this->em->getRepository(CategoryEntity::class)->createQueryBuilder('c')
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
        $rows = $this->em->getRepository(CategoryEntity::class)->createQueryBuilder('c')
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

        return [
            'id' => is_numeric($row['id']) ? (int) $row['id'] : 0,
            'name' => is_string($row['name']) ? $row['name'] : '',
            'permalink' => is_string($row['permalink']) ? $row['permalink'] : null,
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
     * Item 14 DQL audit, re-corrected: `REGEXP`'s MySQL/MariaDB-specific
     * *operator name* is now genuinely portable via a custom DQL function
     * ({@see \Piwigo\Db\DqlFunction\RegexpFunction}, Item 14 Sub-phase B5
     * Tier 1) that reads the real operator from `AbstractPlatform::
     * getRegexpExpression()`. Converted to real DQL -- see that class's
     * own docblock for the real remaining Postgres-portability caveat
     * (the pattern *syntax* below, not just the operator, would need a
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
     * Matches $permalinks against both the current `categories.permalink`
     * column and the `old_permalinks` redirect table, keyed by the
     * permalink string. `is_old` distinguishes which table matched (a
     * match in `old_permalinks` needs its hit counter touched by the
     * caller via {@see touchOldPermalinkHit()}).
     *
     * @param  list<string>  $permalinks
     * @return array<string, array{id: int, permalink: string, is_old: int}>
     *
     * Item 14 DQL audit, re-corrected: `old_permalinks` is now mapped ({@see
     * \Piwigo\Permalink\OldPermalinkEntity}). Converted to real DQL -- still
     * two single-table selects unioned in PHP (DQL itself has no UNION),
     * one against OldPermalinkEntity and one against this repository's own
     * CategoryEntity. `op.catId` maps through the `category_id` custom
     * Doctrine Type, so getArrayResult() hydrates it as a CategoryId value
     * object (Gotcha #1 shape) -- read via instanceof, unlike the plain-int
     * `c.id` from the categories side.
     *
     * `op.permalink` maps through the `permalink` custom Doctrine Type
     * too, so it hydrates as a Permalink value object for old-rows --
     * `c.permalink` (from the still-untyped CategoryEntity side) stays a
     * plain string in the same merged result set, so both shapes are
     * unwrapped before the merge.
     */
    public function findPermalinkMatches(array $permalinks): array
    {
        if ($permalinks === []) {
            return [];
        }

        $em = $this->em;

        $oldRows = $em->createQueryBuilder()
            ->select('op.catId AS id', 'op.permalink AS permalink', '1 AS is_old')
            ->from(OldPermalinkEntity::class, 'op')
            ->where('op.permalink IN (:permalinks)')
            ->setParameter('permalinks', $permalinks, ArrayParameterType::STRING)
            ->getQuery()
            ->getArrayResult();

        $categoryRows = $this->em->getRepository(CategoryEntity::class)->createQueryBuilder('c')
            ->select('c.id AS id', 'c.permalink AS permalink', '0 AS is_old')
            ->where('c.permalink IN (:permalinks)')
            ->setParameter('permalinks', $permalinks, ArrayParameterType::STRING)
            ->getQuery()
            ->getArrayResult();

        $byPermalink = [];
        foreach ([...$oldRows, ...$categoryRows] as $row) {
            if (! is_array($row)) {
                continue;
            }

            $id = $row['id'] ?? null;
            $idValue = $id instanceof CategoryId ? $id->value : (is_numeric($id) ? (int) $id : null);
            $permalink = $row['permalink'] ?? null;
            $permalink = $permalink instanceof Permalink ? $permalink->value : $permalink;
            $isOld = $row['is_old'] ?? null;

            if ($idValue === null || ! is_string($permalink) || ! is_numeric($isOld)) {
                continue;
            }

            $byPermalink[$permalink] = [
                'id' => $idValue,
                'permalink' => $permalink,
                'is_old' => (int) $isOld,
            ];
        }

        return $byPermalink;
    }

    /**
     * Item 14 DQL audit, re-corrected: `old_permalinks` is now mapped
     * ({@see OldPermalinkEntity}). Converted to real DQL -- the original
     * "a mapped entity property write can't express `hit = hit + 1`"
     * reasoning only ruled out a fetch-mutate-flush() round trip; a DQL
     * bulk UPDATE's own SET clause allows a self-referential arithmetic
     * expression directly (`op.hit = op.hit + 1`, confirmed against the
     * installed doctrine/orm QueryBuilder -- same shape as `rank = rank +
     * 1` elsewhere in this codebase), and `NOW()` becomes DQL's own
     * `CURRENT_TIMESTAMP()` (same {@see \Piwigo\Users\UserRepository::
     * findPendingActivationKeyRows()} precedent, compiles to MySQL's
     * `NOW()` via `MySQLPlatform::getCurrentTimestampSQL()`). No `LIMIT`
     * clause -- ORM's QueryBuilder rejects `setMaxResults()` on an
     * UPDATE/DELETE DQL statement outright, but `permalink` is
     * OldPermalinkEntity's own single-column PK, so `WHERE op.permalink =
     * :permalink` alone is already at most one row; `cat_id` stays as a
     * defensive extra condition, matching the original. No full
     * OldPermalinkEntity object is ever hydrated anywhere in this
     * repository (only array/scalar reads), so there's no identity map
     * entry for this bulk UPDATE to leave stale -- $em->clear() would be a
     * no-op here.
     *
     * `$permalink` stays a raw `string` param -- unlike this method's own
     * `$catId`, its only real caller ({@see \Piwigo\Category\CategoryService::
     * findCategoryIdFromPermalinks()}) reaches it exclusively through a
     * value already round-tripped out of {@see findPermalinkMatches()}'s
     * own `old_permalinks` rows (its `is_old` branch), so it's guaranteed
     * to already satisfy `Permalink::from()`'s constraints -- wrapped
     * internally, right at the bind.
     */
    public function touchOldPermalinkHit(string $permalink, int $catId): void
    {
        $this->em
            ->createQueryBuilder()
            ->update(OldPermalinkEntity::class, 'op')
            ->set('op.lastHit', 'CURRENT_TIMESTAMP()')
            ->set('op.hit', 'op.hit + 1')
            ->where('op.permalink = :permalink')
            ->andWhere('op.catId = :catId')
            ->setParameter('permalink', Permalink::from($permalink))
            ->setParameter('catId', CategoryId::from($catId))
            ->getQuery()
            ->execute();
    }

    /**
     * SQL-modernization audit, Item 14 Sub-phase C1: converted to a typed
     * {@see PermissionCriteria} -- the one real caller applies
     * forbiddenCategoryIds/visibleCategoryIds against `c.id` and
     * visibleImageIds against `ic.image_id`. It also passed `visible_images
     * => 'image_id'` to the old `getSqlConditionFandFAsCondition()`, whose
     * own `visible_images` case falls through into `forbidden_images` with
     * no `break` -- with fieldName `'image_id'` (not `'id'`/`'i.id'`),
     * that's the `image_access_list` branch, so imageAccessIds applies
     * here too, against `ic.image_id`.
     *
     * Item 14 DQL audit, re-corrected: `image_category` is now mapped
     * ({@see \Piwigo\Image\ImageCategoryEntity}), and Sub-phase B5 Tier 3
     * gives `RAND()` a portable custom DQL function
     * ({@see \Piwigo\Db\DqlFunction\RandFunction}), but this method stays
     * on DBAL -- $criteria's own fragments are built for a plain DBAL
     * QueryBuilder, not DQL.
     *
     * Item 15 audit, re-verified: the claim above ("$criteria's own
     * fragments are built for a plain DBAL QueryBuilder, not DQL") is
     * wrong once actually tried -- {@see PermissionCriteria}'s
     * `*Condition()` methods work identically against a DQL query
     * builder, see {@see applyCondition()}'s own docblock. Converted to
     * real DQL, `RAND()` unchanged (same portable custom function already
     * used by {@see findRandomImageIdInCategory()}).
     */
    public function findRandomImageId(int $catId, string $uppercats, bool $recursive, PermissionCriteria $criteria): ?int
    {
        $scope = $recursive
            ? '(c.id = :catId OR c.uppercats LIKE :uppercatsLike)'
            : 'c.id = :catId';

        $qb = $this->em
            ->createQueryBuilder()
            ->select('ic.imageId')
            ->from(CategoryEntity::class, 'c')
            ->innerJoin(ImageCategoryEntity::class, 'ic', Join::WITH, 'ic.categoryId = c.id')
            ->where($scope)
            ->orderBy('RAND()')
            ->setMaxResults(1)
            ->setParameter('catId', $catId);
        self::applyCondition($qb, SqlCondition::combine(
            'AND',
            $criteria->forbiddenCategoriesCondition('c.id'),
            $criteria->visibleCategoriesCondition('c.id'),
            $criteria->visibleImagesCondition('ic.imageId'),
            $criteria->imageAccessCondition('ic.imageId'),
        ));

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
     * @return list<array{cat_id: int, id_uppercat: ?int, global_rank: ?string, rank: ?int, date_last: ?string, nb_images: int}>
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
     * SQL-modernization audit, Item 14 Sub-phase C1: converted to a typed
     * {@see PermissionCriteria} -- the one real caller applies
     * forbiddenCategoryIds/visibleCategoryIds against `ic.category_id` and
     * visibleImageIds against `i.id`. It also passed `visible_images =>
     * 'id'` to the old `getSqlConditionFandFAsCondition()`, whose own
     * `visible_images` case falls through into `forbidden_images` with no
     * `break` -- with fieldName `'id'`, that's the images-table's own
     * `level <= x` check, so maxLevel applies here too, against `i.level`.
     *
     * Item 14 DQL audit, re-corrected: `image_category` is now mapped
     * ({@see \Piwigo\Image\ImageCategoryEntity}), but stayed on DBAL for its
     * other, still-real blocker: a raw `CurrentConfig::orderBy()` ORDER BY
     * fragment.
     *
     * Item 16J: that blocker is now conditional, not absolute --
     * {@see \Piwigo\Image\PhotoSortField::parseOrderByFragment()} parses
     * the stored fragment against the bounded `$sort_fields` vocabulary
     * (see that method's own docblock for why this doesn't repeat the
     * reverted `CurrentConfig`-typing attempt), and this method runs real
     * DQL whenever that parse succeeds, falling back to the original raw
     * DBAL query -- unchanged below -- whenever `orderByCustom()` is active
     * or the text doesn't parse.
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
        $dqlOrderBy = $this->resolveDqlOrderBy('i', count($catIds) === 1 ? 'ic' : null);
        if ($dqlOrderBy !== null) {
            return $this->findImageIdsForCategoriesViaDql($catIds, $mode, $criteria, $dqlOrderBy);
        }

        $qb = $this->em
            ->getConnection()
            ->createQueryBuilder()
            ->select('id')
            ->from(Tables::images(), 'i')
            ->innerJoin('i', Tables::imageCategory(), 'ic', 'id = ic.image_id')
            ->where('category_id IN (:catIds)')
            ->setParameter('catIds', $catIds, ArrayParameterType::INTEGER)
            ->groupBy('id');
        self::applyCondition($qb, SqlCondition::combine(
            'AND',
            $criteria->forbiddenCategoriesCondition('ic.category_id'),
            $criteria->visibleCategoriesCondition('ic.category_id'),
            $criteria->visibleImagesCondition('i.id'),
            $criteria->maxLevelCondition('i.level'),
        ));

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
        // pgsql support pass: real bug found live -- CurrentConfig::orderBy()
        // is raw, sysadmin-settable SQL text (order_by/order_by_custom),
        // commonly containing the well-known "RAND()" random-order value
        // (Image\PhotoSortField::Random's own token). Unlike the DQL path
        // above (routes through the portable DqlFunction\RandFunction
        // automatically), this raw-DBAL fallback needs the literal
        // translated by hand -- confirmed live: "function rand() does not
        // exist" against a real Postgres server otherwise.
        $qb->orderBy(str_ireplace(
            'RAND()',
            SqlDialect::randomFunction() . '()',
            str_replace('ORDER BY ', '', $this->currentConfig->orderBy())
        ));

        $ids = $qb->executeQuery()
            ->fetchFirstColumn();

        return array_values(array_map(
            static fn (mixed $id): int => (int) $id,
            array_filter($ids, is_numeric(...))
        ));
    }

    /**
     * Resolves `CurrentConfig::orderBy()`'s stored fragment into a list of
     * DQL property paths, or null to fall back to raw DBAL -- either the
     * text doesn't parse ({@see \Piwigo\Image\PhotoSortField::
     * resolveDqlOrderBy()}), or `orderByCustom()`'s sysadmin-local-config
     * override is active (checked here, not in that shared helper, since
     * which custom flag applies -- or whether $orderBySql is even a plain
     * config value to begin with -- is each call site's own decision; see
     * that method's own docblock).
     *
     * @return list<array{property: string, dir: 'ASC'|'DESC'}>|null
     */
    private function resolveDqlOrderBy(string $imageAlias, ?string $imageCategoryAlias): ?array
    {
        if ($this->currentConfig->orderByCustom() !== null) {
            return null;
        }

        return PhotoSortField::resolveDqlOrderBy($this->currentConfig->orderBy(), $imageAlias, $imageCategoryAlias);
    }

    /**
     * @param  list<int>  $catIds
     * @param  list<array{property: string, dir: 'ASC'|'DESC'}>  $dqlOrderBy
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
            ->innerJoin(ImageCategoryEntity::class, 'ic', Join::WITH, 'i.id = ic.imageId')
            ->where('ic.categoryId IN (:catIds)')
            ->setParameter('catIds', $catIds, ArrayParameterType::INTEGER)
            ->groupBy('i.id');
        self::applyCondition($qb, SqlCondition::combine(
            'AND',
            $criteria->forbiddenCategoriesCondition('ic.categoryId'),
            $criteria->visibleCategoriesCondition('ic.categoryId'),
            $criteria->visibleImagesCondition('i.id'),
            $criteria->maxLevelCondition('i.level'),
        ));

        if ($mode === 'AND' && count($catIds) > 1) {
            $qb->having('COUNT(DISTINCT ic.categoryId) = :catCount')
                ->setParameter('catCount', count($catIds));
        }

        foreach ($dqlOrderBy as $entry) {
            if ($entry['property'] === 'ic.rank') {
                // Needed alongside `i.id` for MySQL's ONLY_FULL_GROUP_BY --
                // sound only because resolveDqlOrderBy() above never offers
                // this property when count($catIds) > 1, so `ic.rank` is
                // already 1:1 with `i.id` here (the join's composite PK is
                // (imageId, categoryId), and categoryId is pinned to one
                // value by the WHERE clause).
                $qb->addGroupBy('ic.rank');
            }

            $qb->addOrderBy($entry['property'], $entry['dir']);
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
     * @return array<int, array{id: int, uppercats: string, counter: int}> keyed by id
     *
     * SQL-modernization audit, Item 14 Sub-phase C1: converted to a typed
     * {@see PermissionCriteria} -- the one real caller applies
     * forbiddenCategoryIds/visibleCategoryIds against the base table's own
     * unqualified `category_id` (no alias on `image_category` here).
     *
     * Item 14 DQL audit, re-corrected: `image_category` is now mapped
     * ({@see \Piwigo\Image\ImageCategoryEntity}), but stays on DBAL --
     * $criteria's own fragments are built for a plain DBAL QueryBuilder,
     * not DQL.
     *
     * Item 15 audit, re-verified: the claim above is wrong once actually
     * tried -- {@see PermissionCriteria}'s `*Condition()` methods work
     * identically against a DQL query builder, see
     * {@see applyCondition()}'s own docblock. Converted to real DQL.
     */
    public function findCommonCategories(array $itemIds, ?int $max, array $excludedCatIds, PermissionCriteria $criteria): array
    {
        if ($itemIds === []) {
            return [];
        }

        $qb = $this->em
            ->createQueryBuilder()
            ->select('c.id', 'c.uppercats', 'COUNT(ic.imageId) AS counter')
            ->from(ImageCategoryEntity::class, 'ic')
            ->innerJoin(CategoryEntity::class, 'c', Join::WITH, 'ic.categoryId = c.id')
            ->where('ic.imageId IN (:itemIds)')
            ->setParameter('itemIds', $itemIds, ArrayParameterType::INTEGER)
            ->groupBy('c.id');
        self::applyCondition($qb, SqlCondition::combine(
            'AND',
            $criteria->forbiddenCategoriesCondition('ic.categoryId'),
            $criteria->visibleCategoriesCondition('ic.categoryId'),
        ));

        if ($excludedCatIds !== []) {
            $qb->andWhere('ic.categoryId NOT IN (:excludedCatIds)')
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
            if (! is_array($row)) {
                continue;
            }

            $id = is_numeric($row['id'] ?? null) ? (int) $row['id'] : 0;
            $byId[$id] = [
                'id' => $id,
                'uppercats' => is_string($row['uppercats'] ?? null) ? $row['uppercats'] : '',
                'counter' => is_numeric($row['counter'] ?? null) ? (int) $row['counter'] : 0,
            ];
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

        $rows = $this->em->getRepository(CategoryEntity::class)->createQueryBuilder('c')
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
     * Item 14 DQL audit: converted to real DQL -- single-table, static
     * WHERE; id is plain-typed, so getSingleColumnResult() returns ordinary
     * ints.
     */
    public function findCategoryIdsBySite(int $siteId): array
    {
        return array_values(array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $this->em->getRepository(CategoryEntity::class)->createQueryBuilder('c')
            ->select('c.id')
            ->where('c.siteId = :siteId')
            ->setParameter('siteId', $siteId)
            ->getQuery()
            ->getSingleColumnResult()));
    }

    /**
     * @param  list<int>  $ids
     * @return list<int>
     *
     * SQL-modernization audit, Item 14 Sub-phase B4: converted to real
     * DQL -- `images` is owned by the Image domain (`Piwigo\Image`,
     * L2aCoreDomain, same layer as `Piwigo\Category`, so querying
     * `ImageEntity` directly here is a legal same-layer dependency per
     * `deptrac.yaml`'s own ruleset), with no association declared on
     * `CategoryEntity` to it -- queried directly via
     * `$this->em->createQueryBuilder()->from(ImageEntity::class,
     * ...)`, same "no new association required" shape Item 14's own
     * `GroupAccessEntity`/`UserAccessEntity` joins already established.
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
                ->where('i.storageCategoryId IN (:ids)')
                ->setParameter('ids', $ids, ArrayParameterType::INTEGER)
                ->getQuery()
                ->getSingleColumnResult()
        ));
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
     * Item 14 DQL audit, re-corrected: `image_category` is now mapped
     * ({@see ImageCategoryEntity}). Converted to real DQL -- single-table.
     * `ic.imageId` uses the `image_id` custom Doctrine Type, but
     * `getSingleColumnResult()` uses `HYDRATE_SCALAR_COLUMN`, which never
     * applies a field's custom Type regardless (Gotcha #4) -- so this
     * still returns ordinary ints/numeric strings.
     */
    public function findDistinctLinkedImageIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $rows = $this->em
            ->createQueryBuilder()
            ->select('DISTINCT ic.imageId')
            ->from(ImageCategoryEntity::class, 'ic')
            ->where('ic.categoryId IN (:ids)')
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
     * Item 14 DQL audit, re-corrected: `image_category` is now mapped
     * ({@see ImageCategoryEntity}). Converted to real DQL -- single-table,
     * both filtered columns bound as `ArrayParameterType::INTEGER` IN-lists
     * (raw ints, not wrapped through CategoryId -- the IN-clause array
     * bind doesn't route through a field's custom Doctrine Type reliably,
     * same established convention as {@see deleteGroupAccessForCategories()}
     * elsewhere in this class). `$excludeIds` is still spliced in
     * unconditionally, even when empty, matching the original's own
     * behavior exactly (no new empty-array guard added).
     */
    public function findNonOrphanImageIds(array $imageIds, array $excludeIds): array
    {
        if ($imageIds === []) {
            return [];
        }

        $rows = $this->em
            ->createQueryBuilder()
            ->select('DISTINCT ic.imageId')
            ->from(ImageCategoryEntity::class, 'ic')
            ->where('ic.imageId IN (:imageIds)')
            ->andWhere('ic.categoryId NOT IN (:excludeIds)')
            ->setParameter('imageIds', $imageIds, ArrayParameterType::INTEGER)
            ->setParameter('excludeIds', $excludeIds, ArrayParameterType::INTEGER)
            ->getQuery()
            ->getSingleColumnResult();

        return array_values(array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $rows));
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
     * Item 14 DQL audit, re-corrected: `image_category` is now mapped
     * ({@see ImageCategoryEntity}). Converted to real DQL -- single-table,
     * no DISTINCT (matches the original -- this method deliberately
     * returns every matching row, see the class comment above).
     */
    public function findImageIdsOutsideCategories(array $excludeIds): array
    {
        $rows = $this->em
            ->createQueryBuilder()
            ->select('ic.imageId')
            ->from(ImageCategoryEntity::class, 'ic')
            ->where('ic.categoryId NOT IN (:excludeIds)')
            ->setParameter('excludeIds', $excludeIds, ArrayParameterType::INTEGER)
            ->getQuery()
            ->getSingleColumnResult();

        return array_values(array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $rows));
    }

    /**
     * @param  list<int>  $ids
     *
     * Item 14 DQL audit, re-corrected: `image_category` is now mapped
     * ({@see ImageCategoryEntity}). Converted to real DQL -- single-table
     * bulk DELETE, same "delete-by-ids clears the identity map afterward"
     * contract as {@see deleteUserAccessForCategories()}/
     * {@see deleteGroupAccessForCategories()} above. `$ids` is still
     * spliced in unconditionally, even when empty, matching the original's
     * own behavior exactly (no new empty-array guard added).
     */
    public function deleteImageCategoryLinksForCategories(array $ids): void
    {
        $em = $this->em;
        $em->createQueryBuilder()
            ->delete(ImageCategoryEntity::class, 'ic')
            ->where('ic.categoryId IN (:ids)')
            ->setParameter('ids', $ids, ArrayParameterType::INTEGER)
            ->getQuery()
            ->execute();
        $em->clear();
    }

    /**
     * @param  list<int>  $ids
     */
    public function deleteUserAccessForCategories(array $ids): void
    {
        if ($ids === []) {
            return;
        }

        $em = $this->em;
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

        $em = $this->em;
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

        $em = $this->em;
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
     * @param  list<int>  $ids
     *
     * Item 14 DQL audit, re-corrected: `old_permalinks` is now mapped
     * ({@see OldPermalinkEntity}). Converted to real DQL -- single-table
     * bulk DELETE, same "delete-by-ids clears the identity map afterward"
     * contract as {@see deleteUserAccessForCategories()}/
     * {@see deleteGroupAccessForCategories()} above (even though no full
     * OldPermalinkEntity object is ever hydrated anywhere in this
     * repository today, so this particular clear() is currently a no-op --
     * kept for consistency with every other entity-targeted bulk delete in
     * this class).
     */
    public function deleteOldPermalinksForCategories(array $ids): void
    {
        if ($ids === []) {
            return;
        }

        $em = $this->em;
        $em->createQueryBuilder()
            ->delete(OldPermalinkEntity::class, 'op')
            ->where('op.catId IN (:ids)')
            ->setParameter('ids', $ids, ArrayParameterType::INTEGER)
            ->getQuery()
            ->execute();
        $em->clear();
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

        return array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $this->em->getConnection()->executeQuery(<<<SQL
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

        $em = $this->em;
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
     * Item 14 DQL audit, re-corrected: `image_category` is now mapped
     * ({@see \Piwigo\Image\ImageCategoryEntity}), but stays on DBAL for its
     * other, still-real blocker: `$whereCatsSql` is a caller-supplied raw
     * SQL fragment.
     */
    public function findCategoriesNeedingRandomRepresentative(string $whereCatsSql, array $params = [], array $types = []): array
    {
        $categoriesTable = Tables::categories();
        $imageCategoryTable = Tables::imageCategory();

        return array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $this->em->getConnection()->executeQuery(<<<SQL
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
     * Item 15 audit: `$table`/`$column` converted from arbitrary runtime
     * strings to {@see CategoryOrphanTarget}'s bounded enum.
     *
     * Item 16I: 3 of the 4 targets now go through real DQL -- see that
     * enum's own docblock for why `OldPermalinks` alone keeps the
     * original raw DBAL path (a real deptrac boundary, not a VO-typing
     * question).
     */
    public function findOrphanedColumnValues(CategoryOrphanTarget $target): array
    {
        $entityClassAndProperty = $target->entityClassAndProperty();

        if ($entityClassAndProperty === null) {
            [$table, $column] = $target->tableAndColumn();
            $categoriesTable = Tables::categories();

            return array_values(array_unique(array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '', $this->em->getConnection()->executeQuery(<<<SQL
                SELECT
                    {$column}
                FROM {$table}
                    LEFT JOIN {$categoriesTable} ON id = {$column}
                WHERE id IS NULL
                SQL)->fetchFirstColumn())));
        }

        [$entityClass, $property] = $entityClassAndProperty;

        $values = $this->em
            ->createQueryBuilder()
            ->select("DISTINCT t.{$property}")
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
     * Item 15 audit: `$table`/`$column` converted from arbitrary runtime
     * strings to {@see CategoryOrphanTarget}'s bounded enum.
     *
     * Item 16I: same DQL/DBAL split as {@see findOrphanedColumnValues()}
     * above, same reasons.
     */
    public function deleteRowsWhereColumnIn(CategoryOrphanTarget $target, array $values): void
    {
        $entityClassAndProperty = $target->entityClassAndProperty();

        if ($entityClassAndProperty === null) {
            [$table, $column] = $target->tableAndColumn();

            $this->em
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

            return;
        }

        [$entityClass, $property] = $entityClassAndProperty;
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
     * @return list<array{id: int, id_uppercat: ?int, uppercats: string, rank: ?int, global_rank: ?string}>
     *
     * Item 14 DQL audit: converted to real DQL -- single-table, unconditional
     * select/order, all columns plain-typed.
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

        $em = $this->em;
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

        $em = $this->em;
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

        $em = $this->em;
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
    public function updateImageOrder(CategoryId $catId, ?string $imageOrder): void
    {
        $entity = $this->find($catId->value);
        if ($entity === null) {
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

        $rows = $this->em->getRepository(CategoryEntity::class)->createQueryBuilder('c')
            ->select('c.id', 'c.status')
            ->where('c.id IN (:ids)')
            ->setParameter('ids', $ids, ArrayParameterType::INTEGER)
            ->getQuery()
            ->getArrayResult();

        $byId = [];
        foreach ($rows as $row) {
            /** @var array{id: int, status: CategoryStatus} $row */
            $byId[$row['id']] = [
                'id' => $row['id'],
                'status' => $row['status']->value,
            ];
        }

        return $byId;
    }

    /**
     * @return list<int>
     *
     * $catId takes CategoryId directly, unwrapped to ->value here since
     * UserAccessEntity::$catId/$userId are still plain int (see
     * PermissionRepository's own equivalent docblock note).
     */
    public function findAccessUserIds(CategoryId $catId): array
    {
        $entities = $this->em
            ->createQueryBuilder()
            ->select('ua')
            ->from(UserAccessEntity::class, 'ua')
            ->where('ua.catId = :catId')
            ->setParameter('catId', $catId->value)
            ->getQuery()
            ->getResult();

        return array_map(static fn (UserAccessEntity $ua): int => $ua->userId, $entities);
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
     *   caller (-1 is substituted when no reference access exists, matching
     *   the original's own `$ref_access[] = -1;` sentinel)
     * @param  list<int>  $catIds
     *
     * Item 15 audit: `$table`/`$field` converted from arbitrary runtime
     * strings to {@see CategoryAccessTarget}'s bounded enum.
     *
     * Item 16I: converted to real DQL -- see that enum's own docblock for
     * why the earlier custom-Type mismatch reasoning didn't hold up.
     */
    public function deleteInconsistentAccess(CategoryAccessTarget $target, array $keepIds, array $catIds): void
    {
        [$entityClass, $fieldProperty] = $target->entityClassAndFieldProperty();

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
     * Item 14 DQL audit: converted to real DQL -- single-table, static
     * WHERE; uppercats is a plain string column, so getSingleColumnResult()
     * returns ordinary strings.
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

        $rows = $this->em->getRepository(CategoryEntity::class)->createQueryBuilder('c')
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
     * Item 15 audit: `$field`/`$minmax` converted from arbitrary runtime
     * strings to {@see CategoryRefDateField}/{@see CategoryRefDateAggregate}'s
     * bounded enums.
     *
     * Item 16I: converted to real DQL -- see {@see CategoryRefDateField}'s
     * own docblock for why the earlier "not worth the extra DQL-rewrite
     * risk" call was reconsidered.
     */
    public function findRefDatesByCategoryIds(array $categoryIds, CategoryRefDateField $field, CategoryRefDateAggregate $minmax): array
    {
        if ($categoryIds === []) {
            return [];
        }

        $aggregateExpr = $minmax->sqlFunction() . '(' . $field->dqlProperty() . ')';

        $rows = $this->em
            ->createQueryBuilder()
            ->select('ic.categoryId AS category_id', "{$aggregateExpr} AS ref_date")
            ->from(ImageCategoryEntity::class, 'ic')
            ->innerJoin(ImageEntity::class, 'i', Join::WITH, 'ic.imageId = i.id')
            ->where('ic.categoryId IN (:categoryIds)')
            ->setParameter('categoryIds', $categoryIds, ArrayParameterType::INTEGER)
            ->groupBy('ic.categoryId')
            ->getQuery()
            ->getArrayResult();

        $byCategoryId = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $categoryId = $row['category_id'] ?? null;
            $categoryId = $categoryId instanceof CategoryId ? $categoryId->value : $categoryId;
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
     * SQL-modernization audit, Item 14 Sub-phase B5 Tier 3: converted to
     * real DQL -- `image_category` is mapped
     * ({@see \Piwigo\Image\ImageCategoryEntity}), and its remaining
     * blocker, MySQL's `RAND()`, now has a portable custom DQL function
     * ({@see \Piwigo\Db\DqlFunction\RandFunction}, per-platform dispatch,
     * MySQL/MariaDB verified, PostgreSQL/SQLite unverified against a real
     * install -- see that class's own docblock). `imageId` uses the
     * `image_id` custom Doctrine Type, but `getSingleColumnResult()` +
     * `setMaxResults(1)` stays safe regardless -- `HYDRATE_SCALAR_COLUMN`
     * never applies a field's custom Type (this audit's own gotcha #4).
     */
    public function findRandomImageIdInCategory(int $categoryId): ?int
    {
        $values = $this->em
            ->createQueryBuilder()
            ->select('ic.imageId')
            ->from(ImageCategoryEntity::class, 'ic')
            ->where('ic.categoryId = :categoryId')
            ->setParameter('categoryId', CategoryId::from($categoryId))
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
     * Item 14 DQL audit: converted to real DQL -- single-table, static
     * WHERE; id/dir are both plain-typed. Builds the id=>dir map from
     * `getArrayResult()`'s own rows (no direct `fetchAllKeyValue()`
     * equivalent).
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

        $rows = $this->em->getRepository(CategoryEntity::class)->createQueryBuilder('c')
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
     * SQL-modernization audit, Item 14 Sub-phase B4: converted to real
     * DQL -- same "no association declared, queried directly" shape as
     * {@see findStorageLinkedImageIds()} above.
     */
    public function findDistinctStorageCategoryIds(): array
    {
        return array_values(array_map(
            static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0,
            $this->em
                ->createQueryBuilder()
                ->select('DISTINCT i.storageCategoryId')
                ->from(ImageEntity::class, 'i')
                ->where('i.storageCategoryId IS NOT NULL')
                ->getQuery()
                ->getSingleColumnResult()
        ));
    }

    /**
     * SQL-modernization audit, Item 14 Sub-phase B4: converted to real
     * DQL -- writes `images` (Image domain table, no association from
     * CategoryEntity, queried directly same as
     * {@see findStorageLinkedImageIds()} above). MySQL's own
     * `SqlDialect::concat()` was already a real, portable
     * `AbstractPlatform::getConcatExpression()` primitive (Item 16's own
     * finding), and DQL's built-in `CONCAT()` accepting 3+ arguments
     * (confirmed against `vendor/doctrine/orm/.../ConcatFunction.php`,
     * same as {@see \Piwigo\Category\CategoryRepository::
     * findAllForPermalinksDisplay()}'s own use) lets this collapse the
     * original's nested `CONCAT(CONCAT(:fulldir, '/'), file)` into one
     * flat call. DQL's bulk `UPDATE ... SET` accepts a function call as
     * the new value, same as {@see touchOldPermalinkHit()}'s own
     * self-referential-arithmetic SET precedent established this
     * primitive works for non-trivial SET expressions.
     */
    public function updateImagePathsForCategory(CategoryId $categoryId, string $fulldir): void
    {
        // i.storageCategoryId is CategoryId-typed -- binds the VO
        // directly, not ->value.
        $this->em
            ->createQueryBuilder()
            ->update(ImageEntity::class, 'i')
            ->set('i.path', "CONCAT(:fulldir, '/', i.file)")
            ->where('i.storageCategoryId = :categoryId')
            ->setParameter('fulldir', $fulldir)
            ->setParameter('categoryId', $categoryId)
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
     * Item 14 DQL audit: converted to real DQL -- single-table, static
     * WHERE, fixed SET column.
     */
    public function setRepresentativeImage(int $categoryId, int $imageId): void
    {
        $this->em
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
        $rows = $this->em->getRepository(CategoryEntity::class)->createQueryBuilder('c')
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
                'status' => ($row['status'] ?? null) instanceof CategoryStatus ? $row['status']->value : '',
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

        $em = $this->em;
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
        return $this->find($id)?->status
            ->value;
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
        if (! is_array($row)) {
            return null;
        }

        return [
            'id' => is_numeric($row['id'] ?? null) ? (int) $row['id'] : 0,
            'uppercats' => is_string($row['uppercats'] ?? null) ? $row['uppercats'] : '',
            'global_rank' => is_string($row['global_rank'] ?? null) ? $row['global_rank'] : '',
            'visible' => (bool) ($row['visible'] ?? false) ? 1 : 0,
            'status' => ($row['status'] ?? null) instanceof CategoryStatus ? $row['status']->value : '',
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
        return self::narrowIdNameUppercatsRankRows($this->em->getRepository(CategoryEntity::class)->createQueryBuilder('c')
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
        return self::narrowIdNameUppercatsRankRows($this->em->getRepository(CategoryEntity::class)->createQueryBuilder('c')
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
        return self::narrowIdNameUppercatsRankRows($this->em->getRepository(CategoryEntity::class)->createQueryBuilder('c')
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
     * `image_category` is mapped ({@see \Piwigo\Image\ImageCategoryEntity});
     * both branches use real DQL. The false branch's join has no declared
     * association from CategoryEntity, so it goes through an explicit
     * `Join::WITH` condition, same precedent as
     * {@see findPrivateCategoriesGrantedToUser()} elsewhere in this class.
     */
    public function findByRepresentativePresence(bool $hasRepresentative): array
    {
        $qb = $this->em->getRepository(CategoryEntity::class)->createQueryBuilder('c')
            ->select('c.id', 'c.name', 'c.uppercats', 'c.globalRank AS global_rank');

        if ($hasRepresentative) {
            $qb->where('c.representativePictureId IS NOT NULL');
        } else {
            $qb->distinct()
                ->innerJoin(ImageCategoryEntity::class, 'ic', Join::WITH, 'ic.categoryId = c.id')
                ->where('c.representativePictureId IS NULL');
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
        $qb = $this->em->getRepository(CategoryEntity::class)->createQueryBuilder('c')
            ->select('c.id', 'c.name', 'c.uppercats', 'c.globalRank AS global_rank')
            ->innerJoin(UserAccessEntity::class, 'ua', Join::WITH, 'ua.catId = c.id')
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
     * @return list<array<string, mixed>>
     *
     * Item 14 DQL audit: converted to real DQL -- single-table, static
     * WHERE plus an optional NOT IN.
     */
    public function findPrivateCategoriesExcluding(array $excludeCatIds): array
    {
        $qb = $this->em->getRepository(CategoryEntity::class)->createQueryBuilder('c')
            ->select('c.id', 'c.name', 'c.uppercats', 'c.globalRank AS global_rank')
            ->where('c.status = :status')
            ->setParameter('status', 'private');

        if ($excludeCatIds !== []) {
            $qb->andWhere($qb->expr()->notIn('c.id', ':excludeCatIds'))
                ->setParameter('excludeCatIds', $excludeCatIds, ArrayParameterType::STRING);
        }

        // pgsql support pass: see findPrivateCategoriesGrantedToGroup()'s
        // own docblock for why this needs an explicit order.
        return self::narrowIdNameUppercatsRankRows($qb->orderBy('c.id', 'ASC')->getQuery()->getArrayResult());
    }

    /**
     * Controller\CommentsController's own "search by album" category
     * listing -- permission-filtered, no other condition.
     *
     * @return list<array<string, mixed>>
     *
     * SQL-modernization audit, Item 14 Sub-phase B3 re-investigation: this
     * method's own sole real caller ({@see \Piwigo\Controller\
     * CommentsController}'s "search by album" listing, via
     * {@see \Piwigo\Category\CategoryService::displaySelectByCondition()})
     * only ever applies forbiddenCategoryIds/visibleCategoryIds against
     * the unqualified `id` (this table's own, no alias/join here).
     *
     * SQL-modernization audit, Item 14 Sub-phase C1: converted to a typed
     * {@see PermissionCriteria}. Stays on DBAL -- $criteria's own
     * fragments are built for a plain DBAL QueryBuilder, not DQL.
     *
     * Item 15 audit, re-verified: the claim above is wrong once actually
     * tried -- see {@see applyCondition()}'s own docblock. Converted to
     * real DQL, reusing {@see narrowIdNameUppercatsRankRows()} for the
     * same narrowing its own sibling method
     * ({@see findPrivateCategoriesExcluding()}) already applies to the
     * identical 4-column shape.
     */
    public function findIdNameUppercatsRank(PermissionCriteria $criteria): array
    {
        $qb = $this->em->getRepository(CategoryEntity::class)->createQueryBuilder('c')
            ->select('c.id', 'c.name', 'c.uppercats', 'c.globalRank AS global_rank');

        self::applyCondition($qb, SqlCondition::combine(
            'AND',
            $criteria->forbiddenCategoriesCondition('c.id'),
            $criteria->visibleCategoriesCondition('c.id'),
        ));

        // pgsql support pass: see findPrivateCategoriesGrantedToGroup()'s
        // own docblock for why this needs an explicit order.
        return self::narrowIdNameUppercatsRankRows($qb->orderBy('c.id', 'ASC')->getQuery()->getArrayResult());
    }

    /**
     * Controller\Admin\PermalinksSubController's own category listing --
     * every category, `name` replaced with a display label indicating
     * whether it already has a permalink set.
     *
     * SQL-modernization audit, Item 14 Sub-phase B5 Tier 2: converted to
     * real DQL -- MySQL's `IF(permalink IS NULL, "", " &radic;")` builds a
     * different value per branch (not just a NULL fallback COALESCE()
     * could express -- see {@see findNextId()}'s own docblock for that
     * distinction), but DQL's standard `CASE WHEN ... THEN ... ELSE ...
     * END` is a clean, portable drop-in for it, and `CONCAT()` accepting
     * more than 2 arguments (confirmed against
     * `vendor/doctrine/orm/.../ConcatFunction.php`) covers the rest.
     *
     * @return list<array<string, mixed>>
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
            if (! is_array($row)) {
                continue;
            }

            $result[] = [
                'id' => $row['id'] ?? null,
                'permalink' => $row['permalink'] ?? null,
                'name' => $row['name'] ?? null,
                'uppercats' => $row['uppercats'] ?? null,
                'global_rank' => $row['global_rank'] ?? null,
            ];
        }

        return $result;
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
        return self::narrowIdNameUppercatsRankRows($this->em->getRepository(CategoryEntity::class)->createQueryBuilder('c')
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
        $em = $this->em;
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
        $em = $this->em;
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
        $em = $this->em;
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
        $em = $this->em;
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
        $em = $this->em;
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

        $em = $this->em;
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
        $em = $this->em;
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
    public function updateFields(CategoryId $id, array $data): void
    {
        if ($data === []) {
            return;
        }

        $em = $this->em;
        new BatchWriter($em->getConnection())
            ->singleUpdate(Tables::categories(), $data, [
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
     * Item 14 DQL audit: not a DQL-vs-DBAL question -- bulk write with an
     * INSERT IGNORE option ORM persist()/flush() has no equivalent for.
     */
    public function massInsertGroupAccess(array $inserts, bool $ignore = false): void
    {
        $em = $this->em;
        new BatchWriter($em->getConnection())
            ->massInsert(Tables::groupAccess(), ['group_id', 'cat_id'], $inserts, [
                'ignore' => $ignore,
            ]);
        $em->clear();
    }

    /**
     * Picks a random representative image among a category's sub-categories
     * (`CategoryCatsRenderer`'s own fallback when a category has no direct
     * representative but does have sub-albums with images).
     *
     * SQL-modernization audit, Item 14 Sub-phase C1: converted to a typed
     * {@see PermissionCriteria} -- the one real caller only ever applies
     * visibleCategoryIds, against the unqualified `id` (no alias here).
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
     * Item 14 DQL audit: stays on DBAL -- $criteria's own fragment is a
     * raw SQL string spliced via heredoc (this method's own real
     * blocker: no `QueryBuilder` at all here, plain `executeQuery()`
     * string interpolation). MySQL's `RAND()` now has a portable custom
     * DQL function ({@see \Piwigo\Db\DqlFunction\RandFunction}, Sub-phase
     * B5 Tier 3), but that alone doesn't unblock this method; this call
     * site's own `SqlDialect::DB_RANDOM_FUNCTION` stays as-is
     * for now -- a broader `SqlDialect` portability rewrite is Item 16's
     * own scope, not this one (see this plan's Context section).
     *
     * Item 15 audit, re-verified: converted to a DQL `QueryBuilder` --
     * {@see PermissionCriteria}'s fragment needed no changes (see
     * {@see applyCondition()}'s own docblock), and `RAND()` uses the same
     * portable custom DQL function {@see findRandomImageIdInCategory()}
     * already established, dropping the `SqlDialect::DB_RANDOM_FUNCTION`
     * indirection for this one call site.
     */
    public function findRandomRepresentativeIdAmongSubcategories(string $uppercats, PermissionCriteria $criteria): ?string
    {
        $uppercatsLike = $uppercats . ',%';

        $qb = $this->em->getRepository(CategoryEntity::class)->createQueryBuilder('c')
            ->select('c.representativePictureId')
            ->where('c.uppercats LIKE :uppercatsLike')
            ->andWhere('c.representativePictureId IS NOT NULL')
            ->setParameter('uppercatsLike', $uppercatsLike)
            ->orderBy('RAND()')
            ->setMaxResults(1);

        self::applyCondition($qb, $criteria->visibleCategoriesCondition('c.id'));

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
     * @return array<int, array{from: ?string, to: ?string}> keyed by category id
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
            ->select('ic.categoryId', 'MIN(i.dateCreation) AS from_date', 'MAX(i.dateCreation) AS to_date')
            ->from(ImageCategoryEntity::class, 'ic')
            ->innerJoin(ImageEntity::class, 'i', Join::WITH, 'ic.imageId = i.id')
            ->where('ic.categoryId IN (:categoryIds)')
            ->setParameter('categoryIds', $categoryIds, ArrayParameterType::INTEGER)
            ->groupBy('ic.categoryId');

        self::applyCondition($qb, SqlCondition::combine(
            'AND',
            $criteria->visibleCategoriesCondition('ic.categoryId'),
            $criteria->visibleImagesCondition('i.id'),
            $criteria->maxLevelCondition('i.level'),
        ));

        $rows = $qb->getQuery()
            ->getArrayResult();

        $byId = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $categoryId = $row['categoryId'] ?? null;
            $categoryIdInt = $categoryId instanceof CategoryId ? $categoryId->value : (is_numeric($categoryId) ? (int) $categoryId : null);
            if ($categoryIdInt !== null) {
                $byId[$categoryIdInt] = [
                    'from' => is_scalar($row['from_date'] ?? null) ? (string) $row['from_date'] : null,
                    'to' => is_scalar($row['to_date'] ?? null) ? (string) $row['to_date'] : null,
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
     * Item 14 DQL audit: converted to real DQL -- single-table; $dirIsNull
     * toggles between two fixed DQL conditions (not a dynamic column name).
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
     * one (physical) -- Ws\PwgCore::getInfos()'s own "nb_virtual"/
     * "nb_physical" summary figures.
     *
     * Item 14 DQL audit: converted to real DQL -- same reasoning as
     * {@see findIdsByDirNull()} above.
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
     * Item 14 DQL audit: converted to real DQL -- single-table, static
     * WHERE against the real bool column.
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
     * Item 14 DQL audit: converted to real DQL -- single-table, static
     * WHERE.
     */
    public function findCategoryIdsRepresentedByImage(int $imageId): array
    {
        return array_values(array_map(
            static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0,
            $this->em->getRepository(CategoryEntity::class)->createQueryBuilder('c')
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

        $this->em
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
            $this->em->getRepository(CategoryEntity::class)->createQueryBuilder('c')
                ->select('c.id')
                ->innerJoin(GroupAccessEntity::class, 'ga', Join::WITH, 'ga.catId = c.id')
                ->where('c.status = :status')
                ->andWhere('ga.groupId = :groupId')
                ->setParameter('status', 'private')
                ->setParameter('groupId', GroupId::from($groupId))
                // pgsql support pass: see findPrivateCategoriesGrantedToGroup()'s
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
        $qb = $this->em->getRepository(CategoryEntity::class)->createQueryBuilder('c')
            ->select('c.id')
            ->innerJoin(UserAccessEntity::class, 'ua', Join::WITH, 'ua.catId = c.id')
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
            $this->em->getRepository(CategoryEntity::class)->createQueryBuilder('c')
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
            if (! is_array($row)) {
                continue;
            }

            $status = $row['status'] ?? null;
            $result[] = [
                'id' => $row['id'] ?? null,
                'name' => $row['name'] ?? null,
                'permalink' => $row['permalink'] ?? null,
                'dir' => $row['dir'] ?? null,
                'rank' => $row['rank'] ?? null,
                'status' => $status instanceof CategoryStatus ? $status->value : $status,
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
     * Item 14 DQL audit, re-corrected: `image_category` is now mapped
     * ({@see ImageCategoryEntity}). Converted to real DQL -- single-table
     * GROUP BY COUNT. `ic.categoryId` hydrates as a CategoryId VO under
     * getArrayResult() (Gotcha #1 shape), read via instanceof, same
     * precedent as {@see \Piwigo\Tag\TagRepository::
     * countImagesPerTagUnrestricted()}'s own `it.tagId`/TagId shape.
     */
    public function findPhotoCountsByCategory(): array
    {
        $rows = $this->em
            ->createQueryBuilder()
            ->select('ic.categoryId', 'COUNT(ic.imageId) AS counter')
            ->from(ImageCategoryEntity::class, 'ic')
            ->groupBy('ic.categoryId')
            ->getQuery()
            ->getArrayResult();

        $countByCategory = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $categoryId = $row['categoryId'] ?? null;
            if (! $categoryId instanceof CategoryId) {
                continue;
            }

            $countByCategory[$categoryId->value] = is_numeric($row['counter'] ?? null) ? (int) $row['counter'] : 0;
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
        $rows = $this->em->getRepository(CategoryEntity::class)->createQueryBuilder('c')
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
     * @return list<array<string, mixed>>
     *
     * Item 14 DQL audit: converted to real DQL -- single-table, static
     * WHERE, all 3 columns plain-typed.
     */
    public function findIdsNamesUppercatsForIds(array $categoryIds): array
    {
        $rows = $this->em->getRepository(CategoryEntity::class)->createQueryBuilder('c')
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
        $rows = $this->em->getRepository(CategoryEntity::class)->createQueryBuilder('c')
            ->select('c.id', 'c.name', 'c.rank', 'c.status', 'c.visible', 'c.uppercats', 'c.lastmodified')
            ->getQuery()
            ->getArrayResult();

        $result = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $status = $row['status'] ?? null;
            $result[] = [
                'id' => $row['id'] ?? null,
                'name' => $row['name'] ?? null,
                'rank' => $row['rank'] ?? null,
                'status' => $status instanceof CategoryStatus ? $status->value : $status,
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
     * Item 14 DQL audit, re-corrected: `image_category` is now mapped
     * ({@see ImageCategoryEntity}). Converted to real DQL -- a COUNT
     * aggregate always returns exactly one row, so there's no LIMIT to
     * preserve. `categoryId` is a custom-typed field, so the single-value
     * bind wraps it in the {@see CategoryId} VO -- `convertToDatabaseValue()`
     * is strict VO-only (see {@see \Piwigo\Db\Type\AbstractNumericIdType}'s
     * own docblock), unlike an IN-clause array bind.
     */
    public function hasImages(int $categoryId): bool
    {
        $count = $this->em
            ->createQueryBuilder()
            ->select('COUNT(ic.imageId)')
            ->from(ImageCategoryEntity::class, 'ic')
            ->where('ic.categoryId = :categoryId')
            ->setParameter('categoryId', CategoryId::from($categoryId))
            ->getQuery()
            ->getSingleScalarResult();

        return is_numeric($count) && (int) $count > 0;
    }

    /**
     * Photo count plus min/max date_available for $categoryId's own direct
     * images -- Admin\CatModifyPageRenderer's own "this album contains N
     * photos, added between X and Y" summary.
     *
     * @return list<mixed>
     *
     * SQL-modernization audit, Item 14 Sub-phase B5 Tier 2: converted to
     * real DQL -- `image_category` is mapped
     * ({@see \Piwigo\Image\ImageCategoryEntity}), and its remaining two
     * blockers are resolved by fetching raw rows and computing in PHP
     * instead: MySQL's `DATE()` has no portable DQL equivalent, and the
     * caller's positional `[count, min, max]` shape doesn't need DQL's
     * named field selects at all once the aggregation itself moves to PHP.
     * `dateAvailable` is a `Y-m-d H:i:s` string, so
     * `substr($dateAvailable, 0, 10)` reproduces `DATE(date_available)`'s
     * output exactly. Return type narrowed from `list|false` to `list` --
     * `false` was only ever reachable under the original driver-level
     * `fetchNumeric()` returning zero rows, which an aggregate query
     * without GROUP BY never does; this PHP-side rewrite has no equivalent
     * "zero rows" case at all, so the caller's own defensive
     * `$row === false` check ({@see \Piwigo\Admin\CatModifyPageRenderer})
     * is updated to match.
     */
    public function findPhotoCountAndDateRange(int $categoryId): array
    {
        $rows = $this->em
            ->createQueryBuilder()
            ->select('i.dateAvailable AS date_available')
            ->from(ImageCategoryEntity::class, 'ic')
            ->innerJoin(ImageEntity::class, 'i', Join::WITH, 'i.id = ic.imageId')
            ->where('ic.categoryId = :categoryId')
            ->setParameter('categoryId', CategoryId::from($categoryId))
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

            $dateAvailable = is_string($row['date_available'] ?? null) ? $row['date_available'] : null;
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

        return [$count, $minDate, $maxDate];
    }

    /**
     * Distinct image ids across every id in $categoryIds -- Admin\
     * CatModifyPageRenderer's own recursive (including sub-albums) photo
     * count.
     *
     * @param list<int> $categoryIds
     * @return list<int>
     *
     * Item 14 DQL audit, re-corrected: `image_category` is now mapped
     * ({@see ImageCategoryEntity}). Converted to real DQL -- single-table,
     * same shape as {@see findDistinctLinkedImageIds()} above (`$categoryIds`
     * is still spliced in unconditionally, even when empty, matching the
     * original's own behavior exactly -- no new empty-array guard added).
     */
    public function findDistinctImageIdsInCategories(array $categoryIds): array
    {
        $rows = $this->em
            ->createQueryBuilder()
            ->select('DISTINCT ic.imageId')
            ->from(ImageCategoryEntity::class, 'ic')
            ->where('ic.categoryId IN (:categoryIds)')
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

        $rows = $this->em->getRepository(CategoryEntity::class)->createQueryBuilder('c')
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
     * id/permalink/uppercats/global_rank for every category with an active
     * permalink -- Controller\Admin\PermalinksSubController's own listing.
     * $orderByColumn is 'id'/'permalink'/null (its only real caller's own
     * `$sort_by[0] === 'id' or $sort_by[0] === 'permalink'` check --
     * the caller sorts by global_rank itself afterward when not sorting
     * by id/permalink).
     *
     * SQL-modernization audit, Item 14 Sub-phase B3: converted to real
     * DQL -- the caller-supplied raw "ORDER BY ..." fragment turned out to
     * be one of exactly 3 finite shapes at its one real caller, so
     * $orderByColumn now carries just the column name (or null), and this
     * method decides the DQL `orderBy()` call itself.
     *
     * @return list<array<string, mixed>>
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
            if (! is_array($row)) {
                continue;
            }

            $result[] = [
                'id' => $row['id'] ?? null,
                'permalink' => $row['permalink'] ?? null,
                'uppercats' => $row['uppercats'] ?? null,
                'global_rank' => $row['global_rank'] ?? null,
            ];
        }

        return $result;
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
     * Whether a category with this id exists -- Ws\PwgCategories'
     * setRepresentative()'s own existence check.
     *
     * Item 14 DQL audit: converted to real DQL -- single-table, static
     * WHERE, COUNT aggregate.
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
            $this->em->getRepository(CategoryEntity::class)->createQueryBuilder('c')
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
     * $conditions is a list of caller-built SqlCondition fragments (its
     * one real caller combines a dynamically-sized per-`cat_id` OR-chain
     * with a {@see \Piwigo\Permission\PermissionCriteria} fragment);
     * unlike the `applyCondition()` family, this method splices
     * `{$combined->sql}` directly into a required `WHERE` clause with no
     * `isEmpty()` guard, so it falls back to `1 = 1` itself when every
     * condition is empty (e.g. no `cat_id` filter and no permission
     * restriction for this user) -- the old caller-side
     * `forceOneCondition: true` was load-bearing for exactly this case,
     * not a no-op; moved here so the guarantee lives with the method
     * that actually needs it.
     *
     * Further SQL-modernization audit, Item 15G: converted to real DQL --
     * single-table, no join, so the only real blocker was the caller's
     * own hardcoded `RLIKE`/`REGEXP` operator splice, itself already
     * solved by {@see \Piwigo\Db\DqlFunction\RegexpFunction} (registered,
     * already used elsewhere in this file); {@see \Piwigo\Ws\PwgCategories}
     * updated to build `c.`-prefixed DQL property paths and the portable
     * `REGEXP(...) = true` DQL function instead of a raw SQL fragment.
     */
    public function findIdsAndImageOrderWithConditions(array $conditions): array
    {
        $combined = SqlCondition::combine('AND', ...$conditions);
        $whereDql = $combined->isEmpty() ? '1 = 1' : $combined->sql;

        $qb = $this->em->getRepository(CategoryEntity::class)->createQueryBuilder('c')
            ->select('c.id', 'c.imageOrder')
            ->where($whereDql);

        foreach ($combined->parameters as $name => $value) {
            $qb->setParameter($name, $value, $combined->types[$name] ?? ParameterType::STRING);
        }

        $rows = $qb->getQuery()
            ->getArrayResult();

        $result = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $result[] = [
                'id' => is_numeric($row['id']) ? (int) $row['id'] : 0,
                'image_order' => is_string($row['imageOrder'] ?? null) ? $row['imageOrder'] : null,
            ];
        }

        return $result;
    }

    /**
     * The scope condition shared by findListForWs()/findAdminListForWs()
     * below -- exactly one of 3 mutually-exclusive branches (non-recursive
     * with a real $catId / non-recursive without / recursive with a real
     * $catId), or none at all (recursive with no $catId -- matches the
     * original, which added nothing to $where in that case).
     */
    private function categoryScopeCondition(?int $catId, bool $recursive): SqlCondition
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
            // Item 16 (AbstractPlatform adoption): the real per-platform
            // operator (MySQL/MariaDB: RLIKE) rather than a hardcoded
            // 'REGEXP' dialect constant. No longer static since this
            // needs a real Connection to ask for it.
            //
            // pgsql support pass: real bug found live -- AbstractPlatform::
            // getRegexpExpression() resolves to `SIMILAR TO` on Postgres, a
            // genuinely different pattern-matching dialect than POSIX
            // REGEXP (implicit whole-string anchoring, so the
            // substring-search pattern below never matches -- confirmed
            // live, same root cause as {@see \Piwigo\Db\DqlFunction\RegexpFunction}'s
            // own fix). Postgres's own POSIX-regex operator is `~`.
            $platform = $this->em
                ->getConnection()
                ->getDatabasePlatform();
            $regexOperator = $platform instanceof PostgreSQLPlatform ? '~' : $platform->getRegexpExpression();

            return new SqlCondition('uppercats ' . $regexOperator . ' :catUppercatsLike', [
                'catUppercatsLike' => '(^|,)' . $catId . '(,|$)',
            ]);
        }

        return new SqlCondition('');
    }

    /**
     * Ws\PwgCategories::getList()'s own paginated category rollup. Builds
     * its own scope/forbidden-categories/public-only conditions internally
     * via {@see categoryScopeCondition()} and SqlCondition::combine(), from
     * a typed CategoryListCriteria. $searchTerm/$searchLimit/$limit/
     * $limitPlusOne: a search term gets its own LIMIT only when no explicit
     * $limit is requested; $limit itself gets +1 when $limitPlusOne
     * (single-category scope), to detect "more remain" without a second
     * query. The total is only computed when $limit !== null.
     *
     * @return PaginatedResult<array<string, mixed>>
     *
     * Computes the total via `COUNT(*) OVER() AS total_count` in the same
     * query as the row data (no `DISTINCT`/`GROUP BY` here, so the window
     * function's count is exact) rather than a second round-trip.
     * `total_count` is stripped back out of each row before returning --
     * it's not part of this method's own row shape, only
     * `PaginatedResult::$total`.
     */
    public function findListForWs(
        CategoryListCriteria $criteria,
        ?string $searchTerm,
        int $searchLimit,
        ?int $limit,
        bool $limitPlusOne
    ): PaginatedResult {
        $conn = $this->em
            ->getConnection();

        $conditions = [$this->categoryScopeCondition($criteria->catId, $criteria->recursive)];

        if ($criteria->forbiddenCategoryIds !== []) {
            $conditions[] = new SqlCondition('id NOT IN (:forbiddenCategoryIds)', [
                'forbiddenCategoryIds' => $criteria->forbiddenCategoryIds,
            ], [
                'forbiddenCategoryIds' => ArrayParameterType::INTEGER,
            ]);
        }

        if ($criteria->publicOnly) {
            // pgsql support pass: real bugs found live -- double-quoted
            // "public" is a STRING LITERAL under MySQL's own lenient
            // default (non-ANSI_QUOTES) SQL mode, but Postgres always
            // treats double-quotes as an IDENTIFIER reference (never a
            // string literal), so this failed outright there; switched to
            // the single-quoted form both platforms treat identically.
            // `visible` is a genuine boolean column -- a bare `1` literal
            // is valid MySQL tinyint(1) input but Postgres rejects it
            // outright against a real boolean column.
            $visibleLiteral = $conn->getDatabasePlatform() instanceof PostgreSQLPlatform ? 'true' : '1';
            $conditions[] = new SqlCondition("status = 'public' AND visible = {$visibleLiteral}");
        }

        $combined = SqlCondition::combine('AND', new SqlCondition('1=1'), ...$conditions);
        $params = $combined->parameters;
        $types = $combined->types;

        $categoriesTable = Tables::categories();
        $totalColumn = $limit !== null ? 'COUNT(*) OVER() AS total_count,' : '';

        $sql = <<<SQL
            SELECT {$totalColumn}
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
            $rankColumn = $conn->getDatabasePlatform()
                ->quoteSingleIdentifier('rank');
            $sql .= <<<SQL

                ORDER BY {$rankColumn} ASC
                LIMIT :effectiveLimit
                SQL;
            $params['effectiveLimit'] = $limit + ($limitPlusOne ? 1 : 0);
            $types['effectiveLimit'] = ParameterType::INTEGER;
        }

        $rows = $conn->fetchAllAssociative($sql, $params, $types);

        $total = null;
        if ($limit !== null) {
            $total = $rows !== [] && is_numeric($rows[0]['total_count'] ?? null) ? (int) $rows[0]['total_count'] : 0;
            $rows = array_map(static function (array $row): array {
                unset($row['total_count']);

                return $row;
            }, $rows);
        }

        return new PaginatedResult($rows, $total);
    }

    /**
     * Ws\PwgCategories::getAdminList()'s own paginated category rollup, via
     * CategoryAdminListCriteria (no forbidden-categories/public-only fields
     * at all -- this WS method is admin-only). Always computes the total,
     * unlike {@see findListForWs()}'s own $limit-gated fetch.
     *
     * @return PaginatedResult<array<string, mixed>>
     *
     * Computes the total the same way as {@see findListForWs()} above
     * (`COUNT(*) OVER() AS total_count`) -- no `DISTINCT`/`GROUP BY` here
     * either.
     */
    public function findAdminListForWs(CategoryAdminListCriteria $criteria, ?string $searchTerm, int $searchLimit): PaginatedResult
    {
        $conn = $this->em
            ->getConnection();

        $combined = SqlCondition::combine('AND', new SqlCondition('1=1'), $this->categoryScopeCondition($criteria->catId, $criteria->recursive));
        $params = $combined->parameters;
        $types = $combined->types;

        $categoriesTable = Tables::categories();

        $sql = <<<SQL
            SELECT COUNT(*) OVER() AS total_count, id, name, comment, uppercats, global_rank, dir, status, image_order
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
        $total = $rows !== [] && is_numeric($rows[0]['total_count'] ?? null) ? (int) $rows[0]['total_count'] : 0;
        $rows = array_map(static function (array $row): array {
            unset($row['total_count']);

            return $row;
        }, $rows);

        return new PaginatedResult($rows, $total);
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

        $rows = $this->em->getRepository(CategoryEntity::class)->createQueryBuilder('c')
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

        $rows = $this->em->getRepository(CategoryEntity::class)->createQueryBuilder('c')
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
     * SQL-modernization audit, Item 14 Sub-phase B3: converted to real
     * DQL -- the caller-supplied raw SQL AND-continuation fragment turned
     * out to be one of exactly 3 finite shapes at its one real caller, so
     * $catId/$recursive now carry the intent directly and this method
     * builds the DQL condition itself, reusing the same portable REGEXP
     * DQL function ({@see \Piwigo\Db\DqlFunction\RegexpFunction}, Sub-phase
     * B5 Tier 1) {@see findSubcategoryIds()} already established for the
     * exact same `uppercats REGEXP '(^|,)ID(,|$)'` pattern.
     *
     * @return list<array<string, mixed>>
     */
    public function findSyncCandidatesForSite(int $siteId, ?int $catId, bool $recursive): array
    {
        $qb = $this->em->getRepository(CategoryEntity::class)->createQueryBuilder('c')
            ->select('c.id AS id', 'c.uppercats AS uppercats', 'c.globalRank AS global_rank', 'c.status AS status', 'c.visible AS visible')
            ->where('c.dir IS NOT NULL')
            ->andWhere('c.siteId = :siteId')
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
            if (! is_array($row)) {
                continue;
            }

            $status = $row['status'] ?? null;
            $result[] = [
                'id' => $row['id'] ?? null,
                'uppercats' => $row['uppercats'] ?? null,
                'global_rank' => $row['global_rank'] ?? null,
                'status' => $status instanceof CategoryStatus ? $status->value : $status,
                'visible' => $row['visible'] ?? null,
            ];
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
     * Item 14 DQL audit: converted to real DQL -- single-table,
     * unconditional select.
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
     * @return list<array<string, mixed>>
     *
     * Item 14 DQL audit: converted to real DQL -- single-table; MAX()+1 is
     * a standard DQL aggregate/arithmetic expression.
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

            $result[] = [
                'id_uppercat' => $row['id_uppercat'] ?? null,
                'next_rank' => $row['next_rank'] ?? null,
            ];
        }

        return $result;
    }
}
