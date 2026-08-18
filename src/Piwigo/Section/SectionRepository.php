<?php

declare(strict_types=1);

namespace Piwigo\Section;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Piwigo\Category\CategoryEntity;
use Piwigo\Db\OrderByClause;
use Piwigo\Db\SortRenderer;
use Piwigo\Image\ImageEntity;
use Piwigo\Permission\SqlCondition;

/**
 * Persistence layer for SectionPopulator/SectionInitializer's own
 * per-section-branch item-id queries (categories/recent_pics/most_visited/
 * best_rated/list/flat-subcat), each exposed as its own named method below,
 * parameterized by the truly dynamic fragments (permission conditions,
 * order-by, limit) SectionPopulator computes per branch. Favorites queries
 * stay in Users\UserRepository (a different domain).
 *
 * queryColumn() is a private implementation detail of the raw-SQL methods
 * below, not a generic executor meant to be called from outside this
 * class.
 *
 * Uses a directly-injected `EntityManagerInterface` rather than extending
 * `AbstractRepository` (`Section` is `L2bExtendedDomain`; `Image`/
 * `Category` are `L2aCoreDomain`, an allowed downward dependency, same
 * shape as `Calendar\CalendarRepository`/`Notification\NotificationRepository`).
 *
 * `findVisibleSubcategoryIds()`/`findTopByHitsImageIds()`/
 * `findTopRatedImageIds()` run as real DQL: each of their own
 * `$orderBySql` arguments is a hardcoded literal (or, for
 * `findVisibleSubcategoryIds()`, there is no `$orderBySql` at all), not
 * the genuinely open-ended `CurrentConfig::orderBy()`/
 * `orderByInsideCategory()` every other method here still depends on.
 * `findSectionImageIds()`/`findRecentImageIds()`/`findImageIdsAmongList()`
 * now try DQL first too (`resolveDqlOrderBy()`, same as
 * `CategoryRepository::findImageIdsForCategories()`), falling back to raw
 * DBAL only when `$orderBySql` is a session/category-specific raw
 * override outside the closed `PhotoSortOrder` vocabulary -- the earlier
 * "stay on raw DBAL for that [open-ended text] reason" note here
 * described the fallback's own trigger, not a real reason DQL couldn't be
 * attempted first.
 */
final readonly class SectionRepository
{
    public function __construct(
        private EntityManagerInterface $em,
    ) {}

    private function sortRenderer(): SortRenderer
    {
        return new SortRenderer($this->em->getConnection());
    }

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
    private function queryColumn(string $sql, array $params = [], array $types = []): array
    {
        return array_map(
            static fn (mixed $value): ?string => is_scalar($value) ? (string) $value : null,
            $this->em->getConnection()
                ->executeQuery($sql, $params, $types)
                ->fetchFirstColumn()
        );
    }

    /**
     * Visible subcategory ids directly under $uppercatsPattern (a category's
     * own `uppercats` value, matched as `uppercats LIKE '$uppercatsPattern,%'`)
     * -- SectionPopulator's own "flat categories" mode subcategory expansion.
     * Returns strings -- the real caller feeds these straight into
     * findSectionImageIds()'s own still-raw-SQL, string-typed `category_id
     * IN (:x)` bind.
     *
     * @return list<string>
     */
    public function findVisibleSubcategoryIds(string $uppercatsPattern, SqlCondition $permissionCondition): array
    {
        $qb = $this->em->createQueryBuilder()
            ->select('c.id')
            ->from(CategoryEntity::class, 'c')
            ->andWhere('c.uppercats LIKE :uppercatsPattern')
            ->setParameter('uppercatsPattern', $uppercatsPattern . ',%');
        $permissionCondition->applyTo($qb);

        return array_values(array_map(
            static fn (mixed $id): string => is_numeric($id) ? (string) $id : '',
            $qb->getQuery()
                ->getSingleColumnResult()
        ));
    }

    /**
     * Image ids for the current category/flat-mode section --
     * SectionPopulator's own main categories-section query. $scope is either
     * a plain `category_id = X` or the flat-mode `category_id IN (...)` it
     * already resolved; $forbidden carries the visibility restriction.
     * $orderBySql stays a raw fragment (admin-configurable order_by text,
     * or a session/category-specific raw override SectionPopulator may
     * have spliced in ahead of it -- resolveDqlOrderBy() rejects both, the
     * same "fall back on anything outside the closed vocabulary" behavior
     * every other real caller of it already relies on).
     *
     * $dqlScope/$dqlForbidden are $scope/$forbidden's DQL-aliased
     * counterparts (`ic.category`/`i.id`/`i.level`, not the raw columns
     * `category_id`/`id`/`level`) -- SectionPopulator's own single real
     * call site already builds both flavors from the same
     * PermissionCriteria calls, just with different field-name strings.
     * $dqlImageCategoryAlias is `'ic'` only for the single-category case
     * (where `image_category.rank` is unambiguous); the flat-mode/
     * whole-gallery cases pass null, matching
     * CategoryRepository::findImageIdsForCategories()'s own established
     * "rank has no single value across more than one category" fallback.
     *
     * @return list<string|null>
     */
    public function findSectionImageIds(
        SqlCondition $scope,
        SqlCondition $forbidden,
        string $orderBySql,
        SqlCondition $dqlScope,
        SqlCondition $dqlForbidden,
        ?string $dqlImageCategoryAlias,
    ): array {
        $dqlOrderBy = $this->sortRenderer()
            ->resolveDqlOrderBy($orderBySql, 'i', $dqlImageCategoryAlias);
        if ($dqlOrderBy !== null) {
            return $this->findSectionImageIdsViaDql($dqlScope, $dqlForbidden, $dqlOrderBy);
        }

        $where = SqlCondition::combine('AND', $scope, $forbidden);

        return $this->queryColumn(<<<SQL
            SELECT id
            FROM image_category
                INNER JOIN images ON id = image_id
            {$where->toWhereClause()}
            GROUP BY id
            {$orderBySql}
            SQL
            , $where->parameters, $where->types);
    }

    /**
     * @param list<OrderByClause> $dqlOrderBy
     * @return list<string|null>
     */
    private function findSectionImageIdsViaDql(SqlCondition $dqlScope, SqlCondition $dqlForbidden, array $dqlOrderBy): array
    {
        $qb = $this->em
            ->createQueryBuilder()
            ->select('i.id')
            ->from(ImageEntity::class, 'i')
            ->innerJoin('i.imageCategories', 'ic')
            ->groupBy('i.id');
        SqlCondition::combine('AND', $dqlScope, $dqlForbidden)->applyTo($qb);

        foreach ($dqlOrderBy as $entry) {
            if ($entry->property === 'ic.rank') {
                // Same ONLY_FULL_GROUP_BY need as
                // CategoryRepository::findImageIdsForCategoriesViaDql()'s
                // own comment -- sound here for the identical reason: this
                // branch only runs when $dqlImageCategoryAlias was
                // non-null, which the caller only ever passes for the
                // single-category case.
                $qb->addGroupBy('ic.rank');
            }

            $qb->addOrderBy($entry->property, $entry->dir);
        }

        return array_values(array_map(
            static fn (mixed $id): ?string => is_scalar($id) ? (string) $id : null,
            $qb->getQuery()
                ->getSingleColumnResult()
        ));
    }

    /**
     * Image ids for the "recent_pics" section -- $recent is
     * UserService::getRecentPhotosCondition()'s own condition. $dqlRecent/
     * $dqlForbidden are its DQL-aliased counterparts. Always spans every
     * category a user can see, never a single one, so `image_category.rank`
     * is never unambiguous here -- resolveDqlOrderBy() always gets a null
     * image-category alias, same reasoning as
     * CategoryRepository::findImageIdsForCategories()'s own multi-category
     * fallback.
     *
     * @return list<string|null>
     */
    public function findRecentImageIds(
        SqlCondition $recent,
        SqlCondition $forbidden,
        string $orderBySql,
        SqlCondition $dqlRecent,
        SqlCondition $dqlForbidden,
    ): array {
        $dqlOrderBy = $this->sortRenderer()
            ->resolveDqlOrderBy($orderBySql, 'i');
        if ($dqlOrderBy !== null) {
            return $this->findImagesViaDql($dqlRecent, $dqlForbidden, $dqlOrderBy);
        }

        $where = SqlCondition::combine('AND', $recent, $forbidden);

        return $this->queryColumn(<<<SQL
            SELECT id
            FROM images
                INNER JOIN image_category AS ic ON id = ic.image_id
            {$where->toWhereClause()}
            GROUP BY id
            {$orderBySql}
            SQL
            , $where->parameters, $where->types);
    }

    /**
     * Shared DQL body for findRecentImageIds()/findImageIdsAmongList() --
     * both join ImageCategoryEntity only to apply their own scope/
     * permission conditions and never pass an `ic` alias into
     * resolveDqlOrderBy(), so neither ever needs the extra
     * addGroupBy('ic.rank') findSectionImageIdsViaDql() above needs.
     *
     * @param list<OrderByClause> $dqlOrderBy
     * @return list<string|null>
     */
    private function findImagesViaDql(SqlCondition $dqlScope, SqlCondition $dqlForbidden, array $dqlOrderBy): array
    {
        $qb = $this->em
            ->createQueryBuilder()
            ->select('i.id')
            ->from(ImageEntity::class, 'i')
            ->innerJoin('i.imageCategories', 'ic')
            ->groupBy('i.id');
        SqlCondition::combine('AND', $dqlScope, $dqlForbidden)->applyTo($qb);

        foreach ($dqlOrderBy as $entry) {
            $qb->addOrderBy($entry->property, $entry->dir);
        }

        return array_values(array_map(
            static fn (mixed $id): ?string => is_scalar($id) ? (string) $id : null,
            $qb->getQuery()
                ->getSingleColumnResult()
        ));
    }

    /**
     * Image ids for the "most_visited" section, capped at $limit -- the
     * caller's own `ORDER BY hit DESC, id DESC` is a hardcoded literal,
     * per SectionPopulator.php's own real call site, not
     * CurrentConfig::orderBy()'s genuinely open-ended admin-typed
     * text, so this is real DQL.
     *
     * @return list<string>
     */
    public function findTopByHitsImageIds(SqlCondition $forbiddenCondition, int $limit): array
    {
        $qb = $this->em->createQueryBuilder()
            ->select('i.id')
            ->from(ImageEntity::class, 'i')
            ->innerJoin('i.imageCategories', 'ic')
            ->andWhere('i.hit > 0')
            ->groupBy('i.id')
            ->orderBy('i.hit', 'DESC')
            ->addOrderBy('i.id', 'DESC')
            ->setMaxResults($limit);
        $forbiddenCondition->applyTo($qb);

        return array_values(array_map(
            static fn (mixed $id): string => is_numeric($id) ? (string) $id : '',
            $qb->getQuery()
                ->getSingleColumnResult()
        ));
    }

    /**
     * Image ids for the "best_rated" section, capped at $limit -- same
     * "hardcoded ORDER BY literal, real DQL" reasoning as
     * {@see findTopByHitsImageIds()}.
     *
     * @return list<string>
     */
    public function findTopRatedImageIds(SqlCondition $forbiddenCondition, int $limit): array
    {
        $qb = $this->em->createQueryBuilder()
            ->select('i.id')
            ->from(ImageEntity::class, 'i')
            ->innerJoin('i.imageCategories', 'ic')
            ->andWhere('i.ratingScore IS NOT NULL')
            ->groupBy('i.id')
            ->orderBy('i.ratingScore', 'DESC')
            ->addOrderBy('i.id', 'DESC')
            ->setMaxResults($limit);
        $forbiddenCondition->applyTo($qb);

        return array_values(array_map(
            static fn (mixed $id): string => is_numeric($id) ? (string) $id : '',
            $qb->getQuery()
                ->getSingleColumnResult()
        ));
    }

    /**
     * Image ids for the "list" section (a caller-supplied id set, e.g. a
     * random-photos block), restricted to $imageIds and visibility.
     * $dqlForbidden is $forbidden's DQL-aliased counterpart; the id-list
     * scope itself is identical either way (`image_id`/`i.id` both bind
     * against the same column), so there is no separate $dqlScope here.
     *
     * @param list<string> $imageIds
     * @return list<string|null>
     */
    public function findImageIdsAmongList(
        array $imageIds,
        SqlCondition $forbidden,
        string $orderBySql,
        SqlCondition $dqlForbidden,
    ): array {
        $dqlOrderBy = $this->sortRenderer()
            ->resolveDqlOrderBy($orderBySql, 'i');
        if ($dqlOrderBy !== null) {
            $dqlScope = SqlCondition::fromRawSql('i.id IN (:imageIds)', [
                'imageIds' => $imageIds,
            ], [
                'imageIds' => ArrayParameterType::STRING,
            ]);

            return $this->findImagesViaDql($dqlScope, $dqlForbidden, $dqlOrderBy);
        }

        $where = SqlCondition::combine(
            'AND',
            SqlCondition::fromRawSql('image_id IN (:imageIds)', [
                'imageIds' => $imageIds,
            ], [
                'imageIds' => ArrayParameterType::STRING,
            ]),
            $forbidden,
        );

        return $this->queryColumn(<<<SQL
            SELECT id
            FROM images
                INNER JOIN image_category AS ic ON id = ic.image_id
            {$where->toWhereClause()}
            GROUP BY id
            {$orderBySql}
            SQL
            , $where->parameters, $where->types);
    }
}
