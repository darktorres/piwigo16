<?php

declare(strict_types=1);

namespace Piwigo\Rate;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityRepository;
use Piwigo\Core\Env;
use Piwigo\Db\Tables;
use Piwigo\Rate\Projection\Rate;

/**
 * Persistence layer for the rate domain: `rate` itself, plus the
 * `images.rating_score` single-column bulk update -- a thin cross-domain
 * touch that stays inline here rather than becoming a new `ImageRepository`
 * dependency, same "thin cross-domain touch" precedent as History/
 * Activity's own single-column reads (see docs/plan/manifest.yaml's P18
 * entry). Owns `rate` ({@see RateEntity}, composite PK) -- only the
 * single/simple-condition write methods against it go through DQL; every
 * read here (including bulk list/aggregate reads of `rate` itself) stays
 * plain DBAL via $this->getEntityManager()->getConnection(), same "mixed
 * repository" shape Image/Category's own conversions established.
 *
 * @extends EntityRepository<RateEntity>
 */
final class RateRepository extends EntityRepository
{
    /**
     * @return list<int>
     */
    public function findElementIdsForUserAndAnonymousId(int $userId, string $anonymousId): array
    {
        $ids = $this->getEntityManager()
            ->getConnection()
            ->createQueryBuilder()
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

        $em = $this->getEntityManager();
        $em->createQueryBuilder()
            ->delete(RateEntity::class, 'r')
            ->where('r.userId = :userId')
            ->andWhere('r.anonymousId = :anonymousId')
            ->andWhere('r.elementId IN (:elementIds)')
            ->setParameter('userId', $userId)
            ->setParameter('anonymousId', $anonymousId)
            ->setParameter('elementIds', $elementIds)
            ->getQuery()
            ->execute();
        $em->clear();
    }

    public function reassignAnonymousId(int $userId, string $oldAnonymousId, string $newAnonymousId): void
    {
        $em = $this->getEntityManager();
        $em->createQueryBuilder()
            ->update(RateEntity::class, 'r')
            ->set('r.anonymousId', ':newAnonymousId')
            ->where('r.userId = :userId')
            ->andWhere('r.anonymousId = :oldAnonymousId')
            ->setParameter('newAnonymousId', $newAnonymousId)
            ->setParameter('userId', $userId)
            ->setParameter('oldAnonymousId', $oldAnonymousId)
            ->getQuery()
            ->execute();
        $em->clear();
    }

    /**
     * Deletes any existing rate this user (and, for an anonymous rater,
     * this specific anonymous_id) already has on $elementId, so the
     * insert that follows never collides with the (element_id, user_id,
     * anonymous_id) primary key.
     */
    public function deleteExistingRate(int $elementId, int $userId, ?string $anonymousId): void
    {
        $em = $this->getEntityManager();
        $qb = $em->createQueryBuilder()
            ->delete(RateEntity::class, 'r')
            ->where('r.elementId = :elementId')
            ->andWhere('r.userId = :userId')
            ->setParameter('elementId', $elementId)
            ->setParameter('userId', $userId);

        if ($anonymousId !== null) {
            $qb->andWhere('r.anonymousId = :anonymousId')
                ->setParameter('anonymousId', $anonymousId);
        }

        $qb->getQuery()
            ->execute();
        $em->clear();
    }

    public function insertRate(int $elementId, int $userId, string $anonymousId, int $rate): void
    {
        // Env::now() rather than SQL's NOW() -- same reasoning as
        // CommentRepository::insert()'s own docblock: NOW() runs on the
        // real DB-server clock, invisible to Env::now()'s PIWIGO_TEST_NOW
        // freeze, so a fresh rate inserted during a test would silently
        // sort before/after PIWIGO_TEST_NOW-anchored fixture dates
        // depending on which is chronologically later.
        $em = $this->getEntityManager();
        $em->persist(new RateEntity(
            elementId: $elementId,
            userId: $userId,
            anonymousId: $anonymousId,
            rate: $rate,
            date: Env::now()->format('Y-m-d'),
        ));
        $em->flush();
    }

    /**
     * Every element with at least one rate, its rate count and sum.
     *
     * @return array<int, array{rcount: int, rsum: float}>
     */
    public function findRateSummaries(): array
    {
        $rows = $this->getEntityManager()
            ->getConnection()
            ->createQueryBuilder()
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
        if ($updates === []) {
            return;
        }

        $em = $this->getEntityManager();
        $conn = $em->getConnection();
        foreach ($updates as $update) {
            $conn->createQueryBuilder()
                ->update(Tables::images())
                ->set('rating_score', ':score')
                ->where('id = :id')
                ->setParameter('score', $update['ratingScore'])
                ->setParameter('id', $update['id'])
                ->executeStatement();
        }

        // Bypasses the ORM -- images is Image\ImageEntity's own table
        // (this repository doesn't own it, just touches this one column).
        $em->clear();
    }

    /**
     * Images with no rate row at all, but a leftover non-null
     * rating_score (e.g. every rate on that image was since deleted).
     *
     * @return list<int>
     */
    public function findImageIdsWithStaleRatingScore(): array
    {
        $ids = $this->getEntityManager()
            ->getConnection()
            ->createQueryBuilder()
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

        $em = $this->getEntityManager();
        $em->getConnection()
            ->createQueryBuilder()
            ->update(Tables::images())
            ->set('rating_score', 'NULL')
            ->where('id IN (:ids)')
            ->setParameter('ids', $ids, ArrayParameterType::INTEGER)
            ->executeStatement();
        $em->clear();
    }

    /**
     * @return array<int, string>
     */
    public function findUsernamesById(string $idColumn, string $usernameColumn): array
    {
        $rows = $this->getEntityManager()
            ->getConnection()
            ->createQueryBuilder()
            ->select($idColumn . ' AS id', $usernameColumn . ' AS username')
            ->from(Tables::users())
            ->executeQuery()
            ->fetchAllAssociative();

        $result = [];
        foreach ($rows as $row) {
            if (is_numeric($row['id'])) {
                $result[(int) $row['id']] = is_string($row['username']) ? $row['username'] : '';
            }
        }

        return $result;
    }

    /**
     * Admin "Rating" report: distinct rated elements, optionally scoped to
     * one user (included or excluded) and/or a set of categories.
     *
     * @param list<int> $categoryIds
     */
    public function countRatedElements(?int $filterUserId, bool $excludeFilterUser, array $categoryIds): int
    {
        $qb = $this->getEntityManager()
            ->getConnection()
            ->createQueryBuilder()
            ->select('COUNT(DISTINCT r.element_id)')
            ->from(Tables::rate(), 'r');

        if ($categoryIds !== []) {
            $qb->join('r', Tables::images(), 'i', 'r.element_id = i.id')
                ->join('i', Tables::imageCategory(), 'ic', 'ic.image_id = i.id')
                ->andWhere('ic.category_id IN (:categoryIds)')
                ->setParameter('categoryIds', $categoryIds, ArrayParameterType::INTEGER);
        }

        if ($filterUserId !== null) {
            $qb->andWhere($excludeFilterUser ? 'r.user_id <> :filterUserId' : 'r.user_id = :filterUserId')
                ->setParameter('filterUserId', $filterUserId);
        }

        $value = $qb->executeQuery()
            ->fetchOne();

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * Same report as countRatedElements(), one row per rated image with its
     * rate aggregates.
     *
     * @param list<int> $categoryIds
     * @param string $orderBySql a raw "column DIRECTION" SQL fragment, always
     *   picked from the admin page's own fixed allowlist array, never from
     *   raw user input
     * @return list<array{id: int, path: string, file: string, representative_ext: ?string, score: ?float, recently_rated: ?string, avg_rates: ?float, nb_rates: int, sum_rates: float}>
     */
    public function findRatingReport(?int $filterUserId, bool $excludeFilterUser, array $categoryIds, string $orderBySql, int $limit, int $offset): array
    {
        $qb = $this->getEntityManager()
            ->getConnection()
            ->createQueryBuilder()
            ->select(
                'i.id',
                'i.path',
                'i.file',
                'i.representative_ext',
                'i.rating_score AS score',
                'MAX(r.date) AS recently_rated',
                'ROUND(AVG(r.rate), 2) AS avg_rates',
                'COUNT(r.rate) AS nb_rates',
                'SUM(r.rate) AS sum_rates',
            )
            ->from(Tables::rate(), 'r')
            ->leftJoin('r', Tables::images(), 'i', 'r.element_id = i.id')
            ->groupBy('i.id', 'i.path', 'i.file', 'i.representative_ext', 'i.rating_score', 'r.element_id')
            ->orderBy($orderBySql)
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        if ($categoryIds !== []) {
            $qb->join('i', Tables::imageCategory(), 'ic', 'ic.image_id = i.id')
                ->andWhere('ic.category_id IN (:categoryIds)')
                ->setParameter('categoryIds', $categoryIds, ArrayParameterType::INTEGER);
        }

        if ($filterUserId !== null) {
            $qb->andWhere($excludeFilterUser ? 'r.user_id <> :filterUserId' : 'r.user_id = :filterUserId')
                ->setParameter('filterUserId', $filterUserId);
        }

        $rows = $qb->executeQuery()
            ->fetchAllAssociative();

        return array_map(
            static fn (array $row): array => [
                'id' => is_numeric($row['id']) ? (int) $row['id'] : 0,
                'path' => is_string($row['path']) ? $row['path'] : '',
                'file' => is_string($row['file']) ? $row['file'] : '',
                'representative_ext' => is_string($row['representative_ext']) ? $row['representative_ext'] : null,
                'score' => is_numeric($row['score']) ? (float) $row['score'] : null,
                'recently_rated' => is_string($row['recently_rated']) ? $row['recently_rated'] : null,
                'avg_rates' => is_numeric($row['avg_rates']) ? (float) $row['avg_rates'] : null,
                'nb_rates' => is_numeric($row['nb_rates']) ? (int) $row['nb_rates'] : 0,
                'sum_rates' => is_numeric($row['sum_rates']) ? (float) $row['sum_rates'] : 0.0,
            ],
            $rows
        );
    }

    /**
     * @return list<Rate>
     */
    public function findRateRowsForElement(int $elementId): array
    {
        $rows = $this->getEntityManager()
            ->getConnection()
            ->createQueryBuilder()
            ->select('*')
            ->from(Tables::rate())
            ->where('element_id = :elementId')
            ->orderBy('date', 'DESC')
            ->setParameter('elementId', $elementId)
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(Rate::fromRow(...), $rows);
    }

    public function countAllRates(): int
    {
        $value = $this->getEntityManager()
            ->getConnection()
            ->createQueryBuilder()
            ->select('COUNT(*)')
            ->from(Tables::rate())
            ->executeQuery()
            ->fetchOne();

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * Admin "Rating by user" report: every rater, joined to their account
     * status (used by the page to decide whether to render them as an
     * anonymous rater).
     *
     * @return list<array{id: int, name: string, status: string}>
     */
    public function findUsersWithStatusByIdUsername(string $idColumn, string $usernameColumn): array
    {
        $rows = $this->getEntityManager()
            ->getConnection()
            ->createQueryBuilder()
            ->select('DISTINCT u.' . $idColumn . ' AS id', 'u.' . $usernameColumn . ' AS name', 'ui.status')
            ->from(Tables::users(), 'u')
            ->innerJoin('u', Tables::userInfos(), 'ui', 'u.' . $idColumn . ' = ui.user_id')
            ->executeQuery()
            ->fetchAllAssociative();

        $result = [];
        foreach ($rows as $row) {
            if (! is_numeric($row['id'])) {
                continue;
            }

            $result[] = [
                'id' => (int) $row['id'],
                'name' => is_string($row['name']) ? $row['name'] : '',
                'status' => is_string($row['status']) ? $row['status'] : '',
            ];
        }

        return $result;
    }

    /**
     * @return list<Rate>
     */
    public function findAllRatesOrderedByDateDesc(): array
    {
        $rows = $this->getEntityManager()
            ->getConnection()
            ->createQueryBuilder()
            ->select('*')
            ->from(Tables::rate())
            ->orderBy('date', 'DESC')
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(Rate::fromRow(...), $rows);
    }

    /**
     * Thin cross-domain touch (image thumbnail info for the "by user" rating
     * report), same precedent as this repository's own images.rating_score
     * update above -- not worth a new ImageRepository dependency for one
     * report query.
     *
     * @param list<int> $imageIds
     * @return list<array{id: int, name: ?string, file: string, path: string, representative_ext: ?string, level: int}>
     */
    public function findImageThumbInfoByIds(array $imageIds): array
    {
        if ($imageIds === []) {
            return [];
        }

        $rows = $this->getEntityManager()
            ->getConnection()
            ->createQueryBuilder()
            ->select('id', 'name', 'file', 'path', 'representative_ext', 'level')
            ->from(Tables::images())
            ->where('id IN (:ids)')
            ->setParameter('ids', $imageIds, ArrayParameterType::INTEGER)
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(
            static fn (array $row): array => [
                'id' => is_numeric($row['id']) ? (int) $row['id'] : 0,
                'name' => is_string($row['name']) ? $row['name'] : null,
                'file' => is_string($row['file']) ? $row['file'] : '',
                'path' => is_string($row['path']) ? $row['path'] : '',
                'representative_ext' => is_string($row['representative_ext']) ? $row['representative_ext'] : null,
                'level' => is_numeric($row['level']) ? (int) $row['level'] : 0,
            ],
            $rows
        );
    }

    /**
     * @return array<int, float>
     */
    public function findAverageRatePerElement(): array
    {
        $rows = $this->getEntityManager()
            ->getConnection()
            ->createQueryBuilder()
            ->select('element_id', 'AVG(rate) AS avg_rate')
            ->from(Tables::rate())
            ->groupBy('element_id')
            ->executeQuery()
            ->fetchAllAssociative();

        $result = [];
        foreach ($rows as $row) {
            if (is_numeric($row['element_id'])) {
                $result[(int) $row['element_id']] = is_numeric($row['avg_rate']) ? (float) $row['avg_rate'] : 0.0;
            }
        }

        return $result;
    }

    /**
     * @return list<int>
     */
    public function findTopRatedImageIds(int $limit): array
    {
        $ids = $this->getEntityManager()
            ->getConnection()
            ->createQueryBuilder()
            ->select('id')
            ->from(Tables::images())
            ->orderBy('rating_score', 'DESC')
            ->setMaxResults($limit)
            ->executeQuery()
            ->fetchFirstColumn();

        return self::toIntList($ids);
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

    /**
     * Picture page's rating-summary widget: count + average rate for a
     * single image.
     *
     * @return array{count: int, average: ?float}
     */
    public function findRateSummaryForElement(int $elementId): array
    {
        $row = $this->getEntityManager()
            ->getConnection()
            ->createQueryBuilder()
            ->select('COUNT(rate) AS count', 'ROUND(AVG(rate), 2) AS average')
            ->from(Tables::rate())
            ->where('element_id = :elementId')
            ->setParameter('elementId', $elementId)
            ->executeQuery()
            ->fetchAssociative();

        if ($row === false) {
            return [
                'count' => 0,
                'average' => null,
            ];
        }

        return [
            'count' => is_numeric($row['count']) ? (int) $row['count'] : 0,
            'average' => is_numeric($row['average']) ? (float) $row['average'] : null,
        ];
    }

    /**
     * The current user's own rate for a single image. $anonymousId is only
     * applied for non-classic (guest) users -- matches the original's own
     * conditional `AND anonymous_id = ...` clause, always additionally
     * filtered by $userId regardless.
     */
    public function findUserRate(int $elementId, int $userId, ?string $anonymousId): ?int
    {
        $qb = $this->getEntityManager()
            ->getConnection()
            ->createQueryBuilder()
            ->select('rate')
            ->from(Tables::rate())
            ->where('element_id = :elementId')
            ->andWhere('user_id = :userId')
            ->setParameter('elementId', $elementId)
            ->setParameter('userId', $userId);

        if ($anonymousId !== null) {
            $qb->andWhere('anonymous_id = :anonymousId')
                ->setParameter('anonymousId', $anonymousId);
        }

        $value = $qb->executeQuery()
            ->fetchOne();

        return is_numeric($value) ? (int) $value : null;
    }
}
