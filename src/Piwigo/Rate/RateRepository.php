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
        return array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $rows);
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

    /**
     * Delete rate rows matching ws/rate.delete's optional filter combination:
     * a user_id is always required; anonymous_id and element_id are optional
     * additional restrictions. Returns the number of rows removed.
     */
    public function deleteByUserOptionalAnonAndElement(int $userId, ?string $anonId, ?int $elementId): int
    {
        $qb = $this->conn->createQueryBuilder()
            ->delete($this->table('rate'))
            ->where('user_id = :userId')
            ->setParameter('userId', $userId);
        if ($anonId !== null && $anonId !== '') {
            $qb->andWhere('anonymous_id = :anonId')
               ->setParameter('anonId', $anonId);
        }
        if ($elementId !== null) {
            $qb->andWhere('element_id = :elementId')
               ->setParameter('elementId', $elementId);
        }
        return (int) $qb->executeStatement();
    }

    /**
     * Count distinct rated images, optionally restricted by user-class filter
     * ('all'|'user'|'guest') and by an explicit list of category ids.
     *
     * @param list<int> $catFilterIds
     */
    public function countDistinctRatedImagesAdmin(string $userClass, int $guestId, array $catFilterIds): int
    {
        $qb = $this->conn->createQueryBuilder()
            ->select('COUNT(DISTINCT(r.element_id))')
            ->from($this->table('rate'), 'r');
        if ($catFilterIds !== []) {
            $qb->innerJoin('r', $this->table('images'), 'i', 'r.element_id = i.id')
               ->innerJoin('i', $this->table('image_category'), 'ic', 'ic.image_id = i.id')
               ->andWhere($qb->expr()->in('ic.category_id', ':catIds'))
               ->setParameter('catIds', $catFilterIds, ArrayParameterType::INTEGER);
        }
        if ($userClass === 'user') {
            $qb->andWhere('r.user_id <> :guestId')->setParameter('guestId', $guestId);
        } elseif ($userClass === 'guest') {
            $qb->andWhere('r.user_id = :guestId')->setParameter('guestId', $guestId);
        }
        $value = $qb->executeQuery()->fetchOne();
        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * Paginated admin "rated images" listing. $orderBy is caller-built but
     * comes from a static whitelist; user-class/cat filters are bound.
     *
     * @param list<int> $catFilterIds
     * @return list<array<string, mixed>>
     */
    public function findRatedImagesAdminPage(
        string $userClass,
        int $guestId,
        array $catFilterIds,
        string $orderBy,
        int $limit,
        int $offset,
    ): array {
        $sql = 'SELECT i.id, i.path, i.file, i.representative_ext, i.rating_score AS score,'
            . ' MAX(r.date) AS recently_rated, ROUND(AVG(r.rate),2) AS avg_rates,'
            . ' COUNT(r.rate) AS nb_rates, SUM(r.rate) AS sum_rates'
            . ' FROM ' . $this->table('rate') . ' AS r'
            . ' LEFT JOIN ' . $this->table('images') . ' AS i ON r.element_id = i.id';
        $params = [];
        $types  = [];
        if ($catFilterIds !== []) {
            $sql       .= ' JOIN ' . $this->table('image_category') . ' AS ic ON ic.image_id = i.id';
        }
        $sql .= ' WHERE 1 = 1';
        if ($userClass === 'user') {
            $sql      .= ' AND r.user_id <> ?';
            $params[]  = $guestId;
            $types[]   = \Doctrine\DBAL\ParameterType::INTEGER;
        } elseif ($userClass === 'guest') {
            $sql      .= ' AND r.user_id = ?';
            $params[]  = $guestId;
            $types[]   = \Doctrine\DBAL\ParameterType::INTEGER;
        }
        if ($catFilterIds !== []) {
            $sql      .= ' AND ic.category_id IN (?)';
            $params[]  = $catFilterIds;
            $types[]   = ArrayParameterType::INTEGER;
        }
        $sql .= ' GROUP BY i.id, i.path, i.file, i.representative_ext, i.rating_score, r.element_id'
              . ' ORDER BY ' . $orderBy
              . ' LIMIT ' . $limit . ' OFFSET ' . $offset;
        return $this->conn->executeQuery($sql, $params, $types)->fetchAllAssociative();
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
            'date'         => new \DateTimeImmutable()->format('Y-m-d'),
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
        $row0 = ($row !== false) ? ($row[0] ?? null) : null;
        $row1 = ($row !== false) ? ($row[1] ?? null) : null;
        return [
            is_numeric($row0) ? (int) $row0 : 0,
            is_numeric($row1) ? (float) $row1 : null,
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
     * Used by the Bayesian average recalculation in RateService::updateRatingScore().
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
     * Used by RateService::updateRatingScore() to clear stale scores after all rates are deleted.
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
        return array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $rows);
    }
}
