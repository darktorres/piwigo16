<?php

declare(strict_types=1);

namespace Piwigo\Permission;

use Doctrine\DBAL\ArrayParameterType;
use Piwigo\Db\AbstractRepository;
use Piwigo\Db\Tables;

/**
 * Persistence layer for the permission domain's forbidden-categories
 * computation. Reads only -- category visibility/status are Category-
 * domain columns (Category itself doesn't exist as a typed module yet,
 * P19), but these two narrow, single-purpose reads are thin enough to stay
 * here rather than wait on a full CategoryRepository, matching the
 * "thin cross-domain touch stays inline" precedent already established for
 * History/Activity in this phase's own plan.
 */
final class PermissionRepository extends AbstractRepository
{
    /**
     * @return list<int>
     */
    public function findPrivateCategoryIds(): array
    {
        $ids = $this->conn->createQueryBuilder()
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
        $ids = $this->conn->createQueryBuilder()
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
        $ids = $this->conn->createQueryBuilder()
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

        $this->conn->createQueryBuilder()
            ->delete(Tables::userAccess())
            ->where('user_id = :userId')
            ->andWhere('cat_id IN (:catIds)')
            ->setParameter('userId', $userId)
            ->setParameter('catIds', $catIds, ArrayParameterType::INTEGER)
            ->executeStatement();
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

        $ids = $this->conn->createQueryBuilder()
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
     * @param  list<array{user_id: int, cat_id: int}>  $inserts
     */
    public function massInsertUserAccess(array $inserts): void
    {
        if ($inserts === []) {
            return;
        }

        $this->batchWriter()
            ->massInsert(Tables::userAccess(), ['user_id', 'cat_id'], $inserts, [
                'ignore' => true,
            ]);
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

        $rows = $this->conn->createQueryBuilder()
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

        $rows = $this->conn->createQueryBuilder()
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
