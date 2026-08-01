<?php

declare(strict_types=1);

namespace Piwigo\Permission;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Piwigo\Category\UserAccessEntity;
use Piwigo\Db\BatchWriter;
use Piwigo\Db\Tables;

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
 */
final readonly class PermissionRepository
{
    public function __construct(
        private EntityManagerInterface $em,
    ) {}

    /**
     * @return list<int>
     */
    public function findPrivateCategoryIds(): array
    {
        $ids = $this->em->getConnection()
            ->createQueryBuilder()
            ->select('id')
            ->from(Tables::categories())
            ->where('status = :status')
            ->setParameter('status', 'private')
            ->executeQuery()
            ->fetchFirstColumn();

        return self::toIntList($ids);
    }

    /**
     * @return list<int>
     */
    public function findLockedCategoryIds(): array
    {
        $ids = $this->em->getConnection()
            ->createQueryBuilder()
            ->select('id')
            ->from(Tables::categories())
            ->where('visible = :visible')
            ->setParameter('visible', false)
            ->executeQuery()
            ->fetchFirstColumn();

        return self::toIntList($ids);
    }

    /**
     * @return list<int>
     */
    public function findDirectlyAuthorizedCategoryIds(int $userId): array
    {
        $ids = $this->em->getConnection()
            ->createQueryBuilder()
            ->select('cat_id')
            ->from(Tables::userAccess())
            ->where('user_id = :userId')
            ->setParameter('userId', $userId)
            ->executeQuery()
            ->fetchFirstColumn();

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

        $ids = $this->em->getConnection()
            ->createQueryBuilder()
            ->select('id')
            ->from(Tables::categories())
            ->where('id IN (:catIds)')
            ->andWhere('status = :status')
            ->setParameter('catIds', $catIds, ArrayParameterType::INTEGER)
            ->setParameter('status', 'private')
            ->executeQuery()
            ->fetchFirstColumn();

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

        $rows = $this->em->getConnection()
            ->createQueryBuilder()
            ->select('cat_id', 'group_id')
            ->from(Tables::groupAccess())
            ->where('cat_id IN (:catIds)')
            ->setParameter('catIds', $catIds, ArrayParameterType::INTEGER)
            ->executeQuery()
            ->fetchAllAssociative();

        $grouped = [];
        foreach ($rows as $row) {
            if (! is_numeric($row['cat_id']) || ! is_numeric($row['group_id'])) {
                continue;
            }
            $grouped[(int) $row['cat_id']][] = (int) $row['group_id'];
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

        $rows = $this->em->getConnection()
            ->createQueryBuilder()
            ->select('cat_id', 'user_id')
            ->from(Tables::userAccess())
            ->where('cat_id IN (:catIds)')
            ->setParameter('catIds', $catIds, ArrayParameterType::INTEGER)
            ->executeQuery()
            ->fetchAllAssociative();

        $grouped = [];
        foreach ($rows as $row) {
            if (! is_numeric($row['cat_id']) || ! is_numeric($row['user_id'])) {
                continue;
            }
            $grouped[(int) $row['cat_id']][] = (int) $row['user_id'];
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
        $ids = $this->em->getConnection()
            ->createQueryBuilder()
            ->select('i.id')
            ->distinct()
            ->from(Tables::images(), 'i')
            ->innerJoin('i', Tables::imageCategory(), 'ic', 'i.id = ic.image_id')
            ->where('ic.category_id NOT IN (:forbidden)')
            ->andWhere('i.level > :level')
            ->setParameter('forbidden', self::csvToIntList($structuralForbidden), ArrayParameterType::INTEGER)
            ->setParameter('level', is_numeric($level) ? (int) $level : 0, ParameterType::INTEGER)
            ->executeQuery()
            ->fetchAllAssociative();

        return self::toIntList(array_column($ids, 'id'));
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

        $total = $this->em->getConnection()
            ->createQueryBuilder()
            ->select('COUNT(DISTINCT(image_id)) as total')
            ->from(Tables::imageCategory())
            ->where('category_id NOT IN (:forbidden)')
            ->andWhere('image_id ' . $imageAccessType . ' (:accessList)')
            ->setParameter('forbidden', self::csvToIntList($structuralForbidden), ArrayParameterType::INTEGER)
            ->setParameter('accessList', self::csvToIntList($imageAccessList), ArrayParameterType::INTEGER)
            ->executeQuery()
            ->fetchOne();

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
        $nb = $this->em->getConnection()
            ->createQueryBuilder()
            ->select('COUNT(*) AS nb')
            ->from(Tables::imageCategory())
            ->where('image_id = :imageId')
            ->andWhere('category_id NOT IN (:forbidden)')
            ->setParameter('imageId', $imageId)
            ->setParameter('forbidden', $forbiddenCategoryIds, ArrayParameterType::INTEGER)
            ->executeQuery()
            ->fetchOne();

        return is_numeric($nb) && (int) $nb !== 0;
    }

    /**
     * Every direct user_access row, optionally filtered to $catIds ([]
     * means unfiltered) -- Ws\PwgPermissions::getList()'s own "direct
     * users" block.
     *
     * @param  list<int>  $catIds
     * @return list<array<string, mixed>>
     */
    public function findDirectUserAccessRows(array $catIds): array
    {
        $qb = $this->em->getConnection()
            ->createQueryBuilder()
            ->select('user_id', 'cat_id')
            ->from(Tables::userAccess());

        if ($catIds !== []) {
            $qb->where('cat_id IN (:catIds)')
                ->setParameter('catIds', $catIds, ArrayParameterType::INTEGER);
        }

        return $qb->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * Every indirect (via group membership) user access row, optionally
     * filtered to $catIds ([] means unfiltered) -- Ws\PwgPermissions::
     * getList()'s own "indirect users" block.
     *
     * @param  list<int>  $catIds
     * @return list<array<string, mixed>>
     */
    public function findIndirectUserAccessRows(array $catIds): array
    {
        $qb = $this->em->getConnection()
            ->createQueryBuilder()
            ->select('ug.user_id', 'ga.cat_id')
            ->from(Tables::userGroup(), 'ug')
            ->innerJoin('ug', Tables::groupAccess(), 'ga', 'ug.group_id = ga.group_id');

        if ($catIds !== []) {
            $qb->where('ga.cat_id IN (:catIds)')
                ->setParameter('catIds', $catIds, ArrayParameterType::INTEGER);
        }

        return $qb->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * Every direct group_access row, optionally filtered to $catIds ([]
     * means unfiltered) -- Ws\PwgPermissions::getList()'s own "groups"
     * block.
     *
     * @param  list<int>  $catIds
     * @return list<array<string, mixed>>
     */
    public function findGroupAccessRows(array $catIds): array
    {
        $qb = $this->em->getConnection()
            ->createQueryBuilder()
            ->select('group_id', 'cat_id')
            ->from(Tables::groupAccess());

        if ($catIds !== []) {
            $qb->where('cat_id IN (:catIds)')
                ->setParameter('catIds', $catIds, ArrayParameterType::INTEGER);
        }

        return $qb->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * @param list<mixed> $values
     * @return list<int>
     */
    private static function toIntList(array $values): array
    {
        return array_map(
            static fn (mixed $value): int => is_numeric($value) ? (int) $value : 0,
            $values
        );
    }
}
