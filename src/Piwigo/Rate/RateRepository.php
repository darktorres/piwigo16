<?php

declare(strict_types=1);

namespace Piwigo\Rate;

use Doctrine\DBAL\ArrayParameterType;
use Piwigo\Db\AbstractRepository;
use Piwigo\Db\Tables;

/**
 * Persistence layer for the rate domain: `rate` itself, plus the
 * `images.rating_score` single-column bulk update -- a thin cross-domain
 * touch that stays inline here rather than becoming a new `ImageRepository`
 * dependency, same "thin cross-domain touch" precedent as History/
 * Activity's own single-column reads (see docs/plan/manifest.yaml's P18
 * entry).
 */
final class RateRepository extends AbstractRepository
{
    /**
     * @return list<int>
     */
    public function findElementIdsForUserAndAnonymousId(int $userId, string $anonymousId): array
    {
        $ids = $this->conn->createQueryBuilder()
            ->select('element_id')
            ->from(Tables::rate())
            ->where('user_id = :userId')
            ->andWhere('anonymous_id = :anonymousId')
            ->setParameter('userId', $userId)
            ->setParameter('anonymousId', $anonymousId)
            ->executeQuery()
            ->fetchFirstColumn();

        return self::toIntList($ids);
    }

    /**
     * @param array<int, int> $elementIds
     */
    public function deleteByUserAnonymousAndElements(int $userId, string $anonymousId, array $elementIds): void
    {
        if ($elementIds === []) {
            return;
        }

        $this->conn->createQueryBuilder()
            ->delete(Tables::rate())
            ->where('user_id = :userId')
            ->andWhere('anonymous_id = :anonymousId')
            ->andWhere('element_id IN (:elementIds)')
            ->setParameter('userId', $userId)
            ->setParameter('anonymousId', $anonymousId)
            ->setParameter('elementIds', $elementIds, ArrayParameterType::INTEGER)
            ->executeStatement();
    }

    public function reassignAnonymousId(int $userId, string $oldAnonymousId, string $newAnonymousId): void
    {
        $this->conn->createQueryBuilder()
            ->update(Tables::rate())
            ->set('anonymous_id', ':newAnonymousId')
            ->where('user_id = :userId')
            ->andWhere('anonymous_id = :oldAnonymousId')
            ->setParameter('newAnonymousId', $newAnonymousId)
            ->setParameter('userId', $userId)
            ->setParameter('oldAnonymousId', $oldAnonymousId)
            ->executeStatement();
    }

    /**
     * Deletes any existing rate this user (and, for an anonymous rater,
     * this specific anonymous_id) already has on $elementId, so the
     * insert that follows never collides with the (element_id, user_id,
     * anonymous_id) primary key.
     */
    public function deleteExistingRate(int $elementId, int $userId, ?string $anonymousId): void
    {
        $qb = $this->conn->createQueryBuilder()
            ->delete(Tables::rate())
            ->where('element_id = :elementId')
            ->andWhere('user_id = :userId')
            ->setParameter('elementId', $elementId)
            ->setParameter('userId', $userId);

        if ($anonymousId !== null) {
            $qb->andWhere('anonymous_id = :anonymousId')
                ->setParameter('anonymousId', $anonymousId);
        }

        $qb->executeStatement();
    }

    public function insertRate(int $elementId, int $userId, string $anonymousId, int $rate): void
    {
        $this->conn->createQueryBuilder()
            ->insert(Tables::rate())
            ->values([
                'user_id' => ':userId',
                'anonymous_id' => ':anonymousId',
                'element_id' => ':elementId',
                'rate' => ':rate',
                'date' => 'NOW()',
            ])
            ->setParameter('userId', $userId)
            ->setParameter('anonymousId', $anonymousId)
            ->setParameter('elementId', $elementId)
            ->setParameter('rate', $rate)
            ->executeStatement();
    }

    /**
     * Every element with at least one rate, its rate count and sum.
     *
     * @return array<int, array{rcount: int, rsum: float}>
     */
    public function findRateSummaries(): array
    {
        $rows = $this->conn->createQueryBuilder()
            ->select('element_id', 'COUNT(rate) AS rcount', 'SUM(rate) AS rsum')
            ->from(Tables::rate())
            ->groupBy('element_id')
            ->executeQuery()
            ->fetchAllAssociative();

        $byItem = [];
        foreach ($rows as $row) {
            if (! is_numeric($row['element_id'])) {
                continue;
            }

            $byItem[(int) $row['element_id']] = [
                'rcount' => is_numeric($row['rcount']) ? (int) $row['rcount'] : 0,
                'rsum' => is_numeric($row['rsum']) ? (float) $row['rsum'] : 0.0,
            ];
        }

        return $byItem;
    }

    /**
     * @param list<array{id: int, ratingScore: float}> $updates
     */
    public function updateRatingScores(array $updates): void
    {
        foreach ($updates as $update) {
            $this->conn->createQueryBuilder()
                ->update(Tables::images())
                ->set('rating_score', ':score')
                ->where('id = :id')
                ->setParameter('score', $update['ratingScore'])
                ->setParameter('id', $update['id'])
                ->executeStatement();
        }
    }

    /**
     * Images with no rate row at all, but a leftover non-null
     * rating_score (e.g. every rate on that image was since deleted).
     *
     * @return list<int>
     */
    public function findImageIdsWithStaleRatingScore(): array
    {
        $ids = $this->conn->createQueryBuilder()
            ->select('i.id')
            ->from(Tables::images(), 'i')
            ->leftJoin('i', Tables::rate(), 'r', 'i.id = r.element_id')
            ->where('r.element_id IS NULL')
            ->andWhere('i.rating_score IS NOT NULL')
            ->executeQuery()
            ->fetchFirstColumn();

        return self::toIntList($ids);
    }

    /**
     * @param array<int, int> $ids
     */
    public function clearRatingScores(array $ids): void
    {
        if ($ids === []) {
            return;
        }

        $this->conn->createQueryBuilder()
            ->update(Tables::images())
            ->set('rating_score', 'NULL')
            ->where('id IN (:ids)')
            ->setParameter('ids', $ids, ArrayParameterType::INTEGER)
            ->executeStatement();
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
