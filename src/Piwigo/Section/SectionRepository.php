<?php

declare(strict_types=1);

namespace Piwigo\Section;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\Expr\Join;
use Piwigo\Category\CategoryEntity;
use Piwigo\Image\ImageCategoryEntity;
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
 * stay on raw DBAL (via `$this->em->getConnection()`) for that same
 * reason.
 */
final readonly class SectionRepository
{
    public function __construct(
        private EntityManagerInterface $em,
    ) {}

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
  FROM image_category' . '
    INNER JOIN images' . ' ON id = image_id
  WHERE
    ' . $whereSql . '
' . $forbiddenSql . '
  GROUP BY id
  ' . $orderBySql . '
;', $params, $types);
    }

    /**
     * Image ids for the "recent_pics" section -- $recentSql is
     * UserService::getRecentPhotosCondition()'s own SqlCondition->sql
     * fragment (its ->parameters/->types are merged into $params/$types by
     * the caller, alongside the forbidden-categories condition's own).
     *
     * @param array<string, mixed> $params
     * @param array<string, ArrayParameterType|ParameterType> $types
     * @return list<string|null>
     */
    public function findRecentImageIds(string $recentSql, string $forbiddenSql, string $orderBySql, array $params = [], array $types = []): array
    {
        return $this->queryColumn('
SELECT id
  FROM images' . '
    INNER JOIN image_category' . ' AS ic ON id = ic.image_id
  WHERE '
  . $recentSql . '
  ' . $forbiddenSql . '
  GROUP BY id
  ' . $orderBySql . '
;', $params, $types);
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
            ->innerJoin(ImageCategoryEntity::class, 'ic', Join::WITH, 'i.id = ic.imageId')
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
            ->innerJoin(ImageCategoryEntity::class, 'ic', Join::WITH, 'i.id = ic.imageId')
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
  FROM images' . '
    INNER JOIN image_category' . ' AS ic ON id = ic.image_id
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
        return substr($this->em->getConnection()->quote($value), 1, -1);
    }
}
