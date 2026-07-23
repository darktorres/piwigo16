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
