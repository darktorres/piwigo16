<?php

declare(strict_types=1);

namespace Piwigo\Users;

use Doctrine\DBAL\ArrayParameterType;
use Piwigo\Db\AbstractRepository;

/**
 * Persistence for the `caddie` table — the per-user temporary selection
 * basket used by the admin Batch Manager and the "add to caddie" buttons.
 *
 * Extracted in F5-d/13 from `ImageRepository` (caddie was a foreign
 * aggregate squatting in the image domain). Caddie entries are keyed by
 * (user_id, element_id) and have no first-class identity of their own.
 */
final class UserCaddieRepository extends AbstractRepository
{
    /**
     * Append element ids to a user's caddie, skipping any already present.
     *
     * @param list<int|string> $elementIds
     */
    public function addElements(int $userId, array $elementIds): void
    {
        if ($elementIds === []) {
            return;
        }
        $existing = $this->conn->createQueryBuilder()
            ->select('element_id')
            ->from($this->table('caddie'))
            ->where('user_id = :uid')
            ->setParameter('uid', $userId)
            ->executeQuery()
            ->fetchFirstColumn();
        $alreadyIn = array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '0', $existing);
        $toInsert  = array_values(array_diff($elementIds, $alreadyIn));
        if ($toInsert === []) {
            return;
        }
        $rows = array_map(
            static fn (int|string $id): array => ['element_id' => $id, 'user_id' => $userId],
            $toInsert
        );
        $this->conn->transactional(function () use ($rows): void {
            foreach ($rows as $row) {
                $this->conn->insert($this->table('caddie'), $row);
            }
        });
    }

    /** Delete all caddie entries for the given user (empty the caddie). */
    public function deleteAllByUserId(int $userId): void
    {
        $this->conn->createQueryBuilder()
            ->delete($this->table('caddie'))
            ->where('user_id = :userId')
            ->setParameter('userId', $userId)
            ->executeStatement();
    }

    /**
     * Delete only the given element ids from a user's caddie.
     *
     * @param int[] $imageIds
     */
    public function deleteByImageIds(int $userId, array $imageIds): void
    {
        if ($imageIds === []) {
            return;
        }
        $qb = $this->conn->createQueryBuilder()
            ->delete($this->table('caddie'))
            ->where('user_id = :userId')
            ->setParameter('userId', $userId);
        $qb->andWhere($qb->expr()->in('element_id', ':imageIds'))
           ->setParameter('imageIds', $imageIds, ArrayParameterType::INTEGER);
        $qb->executeStatement();
    }

    /**
     * Return all element ids currently in the user's caddie.
     *
     * @return list<int>
     */
    public function findElementIdsByUserId(int $userId): array
    {
        $rows = $this->conn->createQueryBuilder()
            ->select('element_id')
            ->from($this->table('caddie'))
            ->where('user_id = :userId')
            ->setParameter('userId', $userId)
            ->executeQuery()
            ->fetchFirstColumn();
        return array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $rows);
    }

    /** Count caddie entries for the given user. */
    public function countByUserId(int $userId): int
    {
        $value = $this->conn->createQueryBuilder()
            ->select('COUNT(*)')
            ->from($this->table('caddie'))
            ->where('user_id = :userId')
            ->setParameter('userId', $userId)
            ->executeQuery()
            ->fetchOne();
        return is_numeric($value) ? (int) $value : 0;
    }
}
