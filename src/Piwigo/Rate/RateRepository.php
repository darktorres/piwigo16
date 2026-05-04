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
     * Return (count, average) for a given element's rates.
     * Used by picture_rate.inc.php to display rating summary.
     *
     * @return array{0: int, 1: float|null}
     */
    public function findCountAndAvgByElementId(int $elementId): array
    {
        $row = $this->conn->executeQuery(
            'SELECT COUNT(rate), ROUND(AVG(rate),2) FROM ' . $this->table('rate') . ' WHERE element_id = ?',
            [$elementId]
        )->fetchNumeric();
        return [
            is_numeric($row[0] ?? null) ? (int) $row[0] : 0,
            is_numeric($row[1] ?? null) ? (float) $row[1] : null,
        ];
    }

    /**
     * Return the rate value a specific user gave to a specific element, or null if not rated.
     * $anonId is only passed for anonymous users.
     */
    public function findRateByUserAndElement(int $elementId, int $userId, ?string $anonId = null): ?float
    {
        $qb = $this->conn->createQueryBuilder()
            ->select('rate')
            ->from($this->table('rate'))
            ->where('element_id = :elementId')
            ->andWhere('user_id = :userId')
            ->setParameter('elementId', $elementId)
            ->setParameter('userId', $userId);

        if ($anonId !== null) {
            $qb->andWhere('anonymous_id = :anonId')
               ->setParameter('anonId', $anonId);
        }

        $value = $qb->executeQuery()->fetchOne();
        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * Return all rate rows for the given element, ordered by date descending.
     *
     * @return list<array<string, mixed>>
     */
    public function findByElementId(int $elementId): array
    {
        return $this->conn->createQueryBuilder()
            ->select('*')
            ->from($this->table('rate'))
            ->where('element_id = :elementId')
            ->setParameter('elementId', $elementId)
            ->orderBy('date', 'DESC')
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * Return all rate rows ordered by date descending.
     * Used by admin/rating_user.php.
     *
     * @return list<array<string, mixed>>
     */
    public function findAllOrderedByDate(): array
    {
        return $this->conn->createQueryBuilder()
            ->select('*')
            ->from($this->table('rate'))
            ->orderBy('date', 'DESC')
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * Return (element_id, avg_rate) for all elements.
     * Used by admin/rating_user.php to compute per-image average.
     *
     * @return list<array<string, mixed>>
     */
    public function findAverageByElement(): array
    {
        return $this->conn->createQueryBuilder()
            ->select('element_id', 'AVG(rate) AS avg_rate')
            ->from($this->table('rate'))
            ->groupBy('element_id')
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /** Count how many times the given image has been rated. */
    public function countByElementId(int $elementId): int
    {
        $value = $this->conn->createQueryBuilder()
            ->select('COUNT(*)')
            ->from($this->table('rate'))
            ->where('element_id = :elementId')
            ->setParameter('elementId', $elementId)
            ->executeQuery()
            ->fetchOne();
        return is_numeric($value) ? (int) $value : 0;
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
