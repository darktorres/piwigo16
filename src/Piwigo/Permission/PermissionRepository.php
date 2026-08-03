<?php

declare(strict_types=1);

namespace Piwigo\Permission;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Query\Expression\ExpressionBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\Expr\Join;
use Piwigo\Category\CategoryEntity;
use Piwigo\Category\UserAccessEntity;
use Piwigo\Common\ValueObject\CategoryId;
use Piwigo\Common\ValueObject\GroupId;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Db\BatchWriter;
use Piwigo\Db\Tables;
use Piwigo\Group\GroupAccessEntity;
use Piwigo\Group\UserGroupEntity;
use Piwigo\Image\ImageCategoryEntity;
use Piwigo\Image\ImageEntity;

/**
 * Persistence layer for the permission domain's forbidden-categories
 * computation, plus the `user_access` write methods below. Owns no table
 * itself -- `categories`/`group_access` are read-only cross-domain
 * touches (each owned elsewhere), and `user_access` is a shared join
 * table with no single owner: CategoryRepository writes permission grants
 * tied to a category's own lifecycle, this class writes/deletes them from
 * the permission-management side -- holds EntityManagerInterface directly
 * rather than being resolved via getRepository(), same shape as
 * Auth\AuthRepository.
 *
 * `deleteUserAccess()`/`massInsertUserAccess()` were originally classified
 * "reads only" when Part B's DBAL->ORM migration first surveyed this
 * class -- wrong; both are real writes into user_access, found via a
 * later audit of raw/BatchWriter call sites outside the repository layer
 * that turned up this same gap inside the repository layer too. (Category\
 * UserAccessEntity's own docblock still describes this class as read-only
 * and needs the same correction.)
 *
 * Further SQL-modernization audit, Item 16A: every method except
 * `expressionBuilder()` (still real -- the quicksearch token evaluator's
 * own permanent DBAL boundary, see `SearchService::
 * getQuickSearchResultsNoCache()`) and `massInsertUserAccess()` (`INSERT
 * IGNORE` via `BatchWriter`, Item 16C's own bucket) converted to real
 * DQL -- every real query here was already bounded, never audited by
 * Item 14/15. `findGrantedGroupIdsByCategory()`/`findGroupAccessRows()`/
 * `findIndirectUserAccessRows()` unwrap `GroupAccessEntity`/
 * `UserGroupEntity`'s own `CategoryId`/`GroupId`/`UserId` VO-typed columns
 * -- `getArrayResult()` (unlike `getSingleColumnResult()`'s own
 * `HYDRATE_SCALAR_COLUMN`, which skips custom-Type conversion entirely)
 * applies each column's real custom Doctrine Type, so these hydrate as
 * real VO instances, not raw ints.
 */
final readonly class PermissionRepository
{
    public function __construct(
        private EntityManagerInterface $em,
    ) {}

    /**
     * Further SQL-modernization audit, Item 2: exposes a real
     * `Doctrine\DBAL\Query\Expression\ExpressionBuilder` for
     * `PermissionService::getSqlConditionFandFAsCondition()` (a pure
     * string/params builder with no DB access of its own, per its own
     * docblock) to compose SQL-condition fragments via typed method
     * calls instead of hand-typed string concatenation -- kept here
     * rather than injecting Connection directly into the service, since
     * this repository already owns the EntityManager/DB-access concern.
     */
    public function expressionBuilder(): ExpressionBuilder
    {
        return $this->em->getConnection()
            ->createExpressionBuilder();
    }

    /**
     * @return list<int>
     */
    public function findPrivateCategoryIds(): array
    {
        $ids = $this->em->createQueryBuilder()
            ->select('c.id')
            ->from(CategoryEntity::class, 'c')
            ->where('c.status = :status')
            ->setParameter('status', 'private')
            ->getQuery()
            ->getSingleColumnResult();

        return self::toIntList($ids);
    }

    /**
     * @return list<int>
     */
    public function findLockedCategoryIds(): array
    {
        $ids = $this->em->createQueryBuilder()
            ->select('c.id')
            ->from(CategoryEntity::class, 'c')
            ->where('c.visible = :visible')
            ->setParameter('visible', false)
            ->getQuery()
            ->getSingleColumnResult();

        return self::toIntList($ids);
    }

    /**
     * @return list<int>
     */
    public function findDirectlyAuthorizedCategoryIds(int $userId): array
    {
        $ids = $this->em->createQueryBuilder()
            ->select('ua.catId')
            ->from(UserAccessEntity::class, 'ua')
            ->where('ua.userId = :userId')
            ->setParameter('userId', $userId)
            ->getQuery()
            ->getSingleColumnResult();

        return self::toIntList($ids);
    }

    /**
     * Deletes direct user-category access rows. Ported from
     * admin/user_perm.php's own inline `DELETE FROM user_access WHERE
     * user_id = ... AND cat_id IN (...)` (P21 Users batch) -- bound
     * parameters replacing the original's raw implode()'d id list.
     *
     * @param list<int> $catIds
     */
    public function deleteUserAccess(int $userId, array $catIds): void
    {
        if ($catIds === []) {
            return;
        }

        $this->em->createQueryBuilder()
            ->delete(UserAccessEntity::class, 'ua')
            ->where('ua.userId = :userId')
            ->andWhere('ua.catId IN (:catIds)')
            ->setParameter('userId', $userId)
            ->setParameter('catIds', $catIds)
            ->getQuery()
            ->execute();
        $this->em->clear();
    }

    /**
     * Which of $catIds are private categories -- addPermissionOnCategory()'s
     * own "only private categories need explicit user_access rows" filter.
     *
     * @param  list<int>  $catIds
     * @return list<int>
     */
    public function findPrivateCategoryIdsAmong(array $catIds): array
    {
        if ($catIds === []) {
            return [];
        }

        $ids = $this->em->createQueryBuilder()
            ->select('c.id')
            ->from(CategoryEntity::class, 'c')
            ->where('c.id IN (:catIds)')
            ->andWhere('c.status = :status')
            ->setParameter('catIds', $catIds, ArrayParameterType::INTEGER)
            ->setParameter('status', 'private')
            ->getQuery()
            ->getSingleColumnResult();

        return self::toIntList($ids);
    }

    /**
     * $ignore matters for the same reason as Category\CategoryRepository::
     * massInsertGroupAccess()'s own docblock -- stays raw DBAL
     * (BatchWriter), persist()+flush() has no INSERT IGNORE equivalent.
     * Defaults to true, matching this method's only caller before
     * Controller\Admin\SiteUpdateSubController's own "brand-new
     * categories can't already have an access row" insert reused it with
     * false (its own $insert_granted_users is already deduplicated via
     * array_unique() beforehand, so INSERT IGNORE's collision-swallowing
     * would only ever mask a genuine bug, not a real duplicate).
     *
     * @param  list<array{user_id: int, cat_id: int}>  $inserts
     */
    public function massInsertUserAccess(array $inserts, bool $ignore = true): void
    {
        if ($inserts === []) {
            return;
        }

        new BatchWriter($this->em->getConnection())
            ->massInsert(Tables::userAccess(), ['user_id', 'cat_id'], $inserts, [
                'ignore' => $ignore,
            ]);
        $this->em->clear();
    }

    /**
     * Which group ids are directly granted access to each of $catIds --
     * SiteUpdateSubController's own "copy the parent's permissions onto
     * newly-synchronized child categories" step.
     *
     * @param  list<int>  $catIds
     * @return array<int, list<int>> keyed by cat_id
     */
    public function findGrantedGroupIdsByCategory(array $catIds): array
    {
        if ($catIds === []) {
            return [];
        }

        $rows = $this->em->createQueryBuilder()
            ->select('ga.catId', 'ga.groupId')
            ->from(GroupAccessEntity::class, 'ga')
            ->where('ga.catId IN (:catIds)')
            ->setParameter('catIds', $catIds, ArrayParameterType::INTEGER)
            ->getQuery()
            ->getArrayResult();

        $grouped = [];
        foreach ($rows as $row) {
            if (! is_array($row) || ! $row['catId'] instanceof CategoryId || ! $row['groupId'] instanceof GroupId) {
                continue;
            }
            $grouped[$row['catId']->value][] = $row['groupId']->value;
        }

        return $grouped;
    }

    /**
     * Which user ids are directly granted access to each of $catIds -- same
     * purpose as findGrantedGroupIdsByCategory() above, for individual users
     * rather than groups.
     *
     * @param  list<int>  $catIds
     * @return array<int, list<int>> keyed by cat_id
     */
    public function findGrantedUserIdsByCategory(array $catIds): array
    {
        if ($catIds === []) {
            return [];
        }

        $rows = $this->em->createQueryBuilder()
            ->select('ua.catId', 'ua.userId')
            ->from(UserAccessEntity::class, 'ua')
            ->where('ua.catId IN (:catIds)')
            ->setParameter('catIds', $catIds, ArrayParameterType::INTEGER)
            ->getQuery()
            ->getArrayResult();

        $grouped = [];
        foreach ($rows as $row) {
            if (! is_array($row) || ! is_numeric($row['catId'] ?? null) || ! is_numeric($row['userId'] ?? null)) {
                continue;
            }
            $grouped[(int) $row['catId']][] = (int) $row['userId'];
        }

        return $grouped;
    }

    /**
     * Image ids outside $structuralForbidden with an access level above
     * $level -- EffectiveForbiddenCategoriesCache's own "which specific
     * images does the level restriction additionally forbid" step.
     *
     * SQL-modernization audit: $structuralForbidden (a comma-separated id
     * list, PermissionService::getForbiddenCategories()'s own return
     * shape) and $level were previously spliced raw; both now bound.
     * PermissionService::getForbiddenCategories() itself is unchanged --
     * its CSV-string return shape is a separate, wider cross-cutting
     * concern (tracked in the plan) than this one extraction converting
     * its own consumption of that string to bound parameters.
     *
     * @return list<int>
     */
    public function findImageIdsOutsideForbiddenCategories(string $structuralForbidden, int|string $level): array
    {
        $ids = $this->em->createQueryBuilder()
            ->select('DISTINCT i.id')
            ->from(ImageEntity::class, 'i')
            ->innerJoin(ImageCategoryEntity::class, 'ic', Join::WITH, 'i.id = ic.imageId')
            ->where('ic.categoryId NOT IN (:forbidden)')
            ->andWhere('i.level > :level')
            ->setParameter('forbidden', self::csvToIntList($structuralForbidden), ArrayParameterType::INTEGER)
            ->setParameter('level', is_numeric($level) ? (int) $level : 0, ParameterType::INTEGER)
            ->getQuery()
            ->getSingleColumnResult();

        return self::toIntList($ids);
    }

    /**
     * Count of distinct accessible images given $structuralForbidden plus
     * the already-computed $imageAccessType/$imageAccessList restriction --
     * EffectiveForbiddenCategoriesCache's own `nbTotalImages`.
     *
     * SQL-modernization audit: $structuralForbidden/$imageAccessList were
     * previously spliced raw; both now bound. $imageAccessType (the SQL
     * inclusion operator itself, 'IN'/'NOT IN') can't be a bound
     * parameter -- SQL has no placeholder syntax for an operator position
     * -- so it's validated against the fixed 2-value domain instead
     * (matches getSqlConditionFandFAsCondition()'s own treatment of the
     * same field).
     */
    public function countAccessibleImages(string $structuralForbidden, string $imageAccessType, string $imageAccessList): string
    {
        if (! in_array($imageAccessType, ['IN', 'NOT IN'], true)) {
            throw new \UnexpectedValueException('Unexpected image_access_type: ' . $imageAccessType);
        }

        $total = $this->em->createQueryBuilder()
            ->select('COUNT(DISTINCT ic.imageId) AS total')
            ->from(ImageCategoryEntity::class, 'ic')
            ->where('ic.categoryId NOT IN (:forbidden)')
            ->andWhere('ic.imageId ' . $imageAccessType . ' (:accessList)')
            ->setParameter('forbidden', self::csvToIntList($structuralForbidden), ArrayParameterType::INTEGER)
            ->setParameter('accessList', self::csvToIntList($imageAccessList), ArrayParameterType::INTEGER)
            ->getQuery()
            ->getSingleScalarResult();

        return is_scalar($total) ? (string) $total : '0';
    }

    /**
     * @return list<int>
     */
    private static function csvToIntList(string $csv): array
    {
        if ($csv === '') {
            return [];
        }

        return array_map(intval(...), explode(',', $csv));
    }

    /**
     * Whether $imageId belongs to any category NOT in $forbiddenCategoryIds
     * -- ImageVisibilityChecker's [SEC-33] fast-path check. Deliberately
     * just this one cheap query against already-computed forbidden-category
     * ids, never a PermissionService call: ADR-0007/0008 forbids live
     * permission recomputation on this path (see ImageVisibilityChecker's
     * own docblock).
     *
     * @param list<int> $forbiddenCategoryIds
     */
    public function isImageOutsideForbiddenCategories(int $imageId, array $forbiddenCategoryIds): bool
    {
        $nb = $this->em->createQueryBuilder()
            ->select('COUNT(ic.imageId) AS nb')
            ->from(ImageCategoryEntity::class, 'ic')
            ->where('ic.imageId = :imageId')
            ->andWhere('ic.categoryId NOT IN (:forbidden)')
            ->setParameter('imageId', $imageId)
            ->setParameter('forbidden', $forbiddenCategoryIds, ArrayParameterType::INTEGER)
            ->getQuery()
            ->getSingleScalarResult();

        return is_numeric($nb) && (int) $nb !== 0;
    }

    /**
     * Every direct user_access row, optionally filtered to $catIds ([]
     * means unfiltered) -- Ws\PwgPermissions::getList()'s own "direct
     * users" block.
     *
     * @param  list<int>  $catIds
     * @return list<array{user_id: int, cat_id: int}>
     */
    public function findDirectUserAccessRows(array $catIds): array
    {
        $qb = $this->em->createQueryBuilder()
            ->select('ua.userId', 'ua.catId')
            ->from(UserAccessEntity::class, 'ua');

        if ($catIds !== []) {
            $qb->where('ua.catId IN (:catIds)')
                ->setParameter('catIds', $catIds, ArrayParameterType::INTEGER);
        }

        $rows = $qb->getQuery()
            ->getArrayResult();

        $result = [];
        foreach ($rows as $row) {
            if (! is_array($row) || ! is_numeric($row['userId'] ?? null) || ! is_numeric($row['catId'] ?? null)) {
                continue;
            }
            $result[] = [
                'user_id' => (int) $row['userId'],
                'cat_id' => (int) $row['catId'],
            ];
        }

        return $result;
    }

    /**
     * Every indirect (via group membership) user access row, optionally
     * filtered to $catIds ([] means unfiltered) -- Ws\PwgPermissions::
     * getList()'s own "indirect users" block.
     *
     * @param  list<int>  $catIds
     * @return list<array{user_id: int, cat_id: int}>
     */
    public function findIndirectUserAccessRows(array $catIds): array
    {
        $qb = $this->em->createQueryBuilder()
            ->select('ug.userId', 'ga.catId')
            ->from(UserGroupEntity::class, 'ug')
            ->innerJoin(GroupAccessEntity::class, 'ga', Join::WITH, 'ug.groupId = ga.groupId');

        if ($catIds !== []) {
            $qb->andWhere('ga.catId IN (:catIds)')
                ->setParameter('catIds', $catIds, ArrayParameterType::INTEGER);
        }

        $rows = $qb->getQuery()
            ->getArrayResult();

        $result = [];
        foreach ($rows as $row) {
            if (! is_array($row) || ! $row['userId'] instanceof UserId || ! $row['catId'] instanceof CategoryId) {
                continue;
            }
            $result[] = [
                'user_id' => $row['userId']->value,
                'cat_id' => $row['catId']->value,
            ];
        }

        return $result;
    }

    /**
     * Every direct group_access row, optionally filtered to $catIds ([]
     * means unfiltered) -- Ws\PwgPermissions::getList()'s own "groups"
     * block.
     *
     * @param  list<int>  $catIds
     * @return list<array{group_id: int, cat_id: int}>
     */
    public function findGroupAccessRows(array $catIds): array
    {
        $qb = $this->em->createQueryBuilder()
            ->select('ga.groupId', 'ga.catId')
            ->from(GroupAccessEntity::class, 'ga');

        if ($catIds !== []) {
            $qb->where('ga.catId IN (:catIds)')
                ->setParameter('catIds', $catIds, ArrayParameterType::INTEGER);
        }

        $rows = $qb->getQuery()
            ->getArrayResult();

        $result = [];
        foreach ($rows as $row) {
            if (! is_array($row) || ! $row['groupId'] instanceof GroupId || ! $row['catId'] instanceof CategoryId) {
                continue;
            }
            $result[] = [
                'group_id' => $row['groupId']->value,
                'cat_id' => $row['catId']->value,
            ];
        }

        return $result;
    }

    /**
     * @param array<mixed> $values
     * @return list<int>
     */
    private static function toIntList(array $values): array
    {
        return array_values(array_map(
            static fn (mixed $value): int => is_numeric($value) ? (int) $value : 0,
            $values
        ));
    }
}
