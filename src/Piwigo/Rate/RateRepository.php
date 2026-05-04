<?php

declare(strict_types=1);

namespace Piwigo\Rate;

use Doctrine\DBAL\ArrayParameterType;
use Piwigo\Db\AbstractRepository;

/** Persistence layer for the photo-rating domain. */
final class RateRepository extends AbstractRepository
{
    /**
     * Return element_ids rated by $userId with the given anonymous IP.
     * Used to detect IP changes for anonymous raters.
     *
     * @return int[]
     */
    public function findElementIdsByUserAndAnonId(int $userId, string $anonId): array
    {
        $rows = $this->conn->createQueryBuilder()
            ->select('element_id')
            ->from($this->table('rate'))
            ->where('user_id = :userId')
            ->andWhere('anonymous_id = :anonId')
            ->setParameter('userId', $userId)
            ->setParameter('anonId', $anonId)
            ->executeQuery()
            ->fetchFirstColumn();
        return array_map('intval', $rows);
    }

    /**
     * Delete rate rows for a user+anonId pair restricted to the given element ids.
     * Called when an anonymous rater changes IP to remove the old-IP duplicate entries.
     *
     * @param int[] $elementIds
     */
    public function deleteByUserAnonElements(int $userId, string $anonId, array $elementIds): void
    {
        if ($elementIds === []) {
            return;
        }
        $qb = $this->conn->createQueryBuilder()
            ->delete($this->table('rate'))
            ->where('user_id = :userId')
            ->andWhere('anonymous_id = :anonId')
            ->setParameter('userId', $userId)
            ->setParameter('anonId', $anonId);
        $qb->andWhere($qb->expr()->in('element_id', ':elementIds'))
           ->setParameter('elementIds', $elementIds, ArrayParameterType::INTEGER);
        $qb->executeStatement();
    }

    /** Reassign all rate rows from $oldAnonId to $newAnonId for the given user. */
    public function updateAnonId(int $userId, string $oldAnonId, string $newAnonId): void
    {
        $this->conn->createQueryBuilder()
            ->update($this->table('rate'))
            ->set('anonymous_id', ':newAnonId')
            ->where('user_id = :userId')
            ->andWhere('anonymous_id = :oldAnonId')
            ->setParameter('newAnonId', $newAnonId)
            ->setParameter('userId', $userId)
            ->setParameter('oldAnonId', $oldAnonId)
            ->executeStatement();
    }

    /**
     * Delete the existing rate by this user for this element before re-inserting.
     * When $anonId is given the WHERE is further restricted (anonymous rater).
     */
    public function deleteByElementAndUser(int $elementId, int $userId, ?string $anonId = null): void
    {
        $qb = $this->conn->createQueryBuilder()
            ->delete($this->table('rate'))
            ->where('element_id = :elementId')
            ->andWhere('user_id = :userId')
            ->setParameter('elementId', $elementId)
            ->setParameter('userId', $userId);

        if ($anonId !== null) {
            $qb->andWhere('anonymous_id = :anonId')
               ->setParameter('anonId', $anonId);
        }

        $qb->executeStatement();
    }

    /** Insert a new rate row with today's date. */
    public function insert(int $userId, string $anonId, int $elementId, float $rate): void
    {
        $this->conn->insert($this->table('rate'), [
            'user_id'      => $userId,
            'anonymous_id' => $anonId,
            'element_id'   => $elementId,
            'rate'         => $rate,
            'date'         => (new \DateTimeImmutable())->format('Y-m-d'),
        ]);
    }

    /**
     * Return (element_id, rcount, rsum) for every element that has at least one rate.
     * Used by the Bayesian average recalculation in update_rating_score().
     *
     * @return list<array<string, mixed>>
     */
    public function getSumsByElement(): array
    {
        return $this->conn->createQueryBuilder()
            ->select('element_id', 'COUNT(rate) AS rcount', 'SUM(rate) AS rsum')
            ->from($this->table('rate'))
            ->groupBy('element_id')
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * Return ids of images that have rating_score set but no rate rows.
     * Used by update_rating_score() to clear stale scores after all rates are deleted.
     *
     * @return int[]
     */
    public function findImageIdsWithNoRates(): array
    {
        $rows = $this->conn->executeQuery(
            'SELECT i.id FROM ' . $this->table('images') . ' i
             LEFT JOIN ' . $this->table('rate') . ' r ON i.id = r.element_id
             WHERE r.element_id IS NULL AND i.rating_score IS NOT NULL'
        )->fetchFirstColumn();
        return array_map('intval', $rows);
    }
}
