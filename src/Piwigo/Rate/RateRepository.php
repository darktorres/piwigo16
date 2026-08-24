<?php

declare(strict_types=1);

namespace Piwigo\Rate;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Query;
use Doctrine\ORM\Query\Expr\Join;
use Piwigo\Common\ValueObject\ImageId;
use Piwigo\Common\ValueObject\SqlDate;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Common\ValueObject\Username;
use Piwigo\Core\Env;
use Piwigo\Image\ImageEntity;
use Piwigo\Rate\Projection\ImageThumbInfo;
use Piwigo\Rate\Projection\Rate;
use Piwigo\Rate\Projection\RaterInfo;
use Piwigo\Rate\Projection\RateSummary;
use Piwigo\Rate\Projection\RateSummaryForElement;
use Piwigo\Rate\Projection\RatingReportRow;
use Piwigo\Rate\Projection\RatingScoreUpdate;
use Piwigo\Users\UserEntity;
use Piwigo\Users\UserInfoEntity;
use Piwigo\Users\UserStatus;

/**
 * Persistence layer for the rate domain: `rate` itself, plus the
 * `images.rating_score` single-column bulk update -- a thin cross-domain
 * touch that stays inline here rather than becoming a new
 * `ImageRepository` dependency.
 * Owns `rate` ({@see RateEntity}, composite PK).
 *
 * @extends EntityRepository<RateEntity>
 */
final class RateRepository extends EntityRepository
{
    /**
     * @return list<int>
     */
    public function findElementIdsForUserAndAnonymousId(UserId $userId, string $anonymousId): array
    {
        $ids = $this->createQueryBuilder('r')
            ->select('r.elementId')
            ->where('r.userId = :userId')
            ->andWhere('r.anonymousId = :anonymousId')
            ->setParameter('userId', $userId)
            ->setParameter('anonymousId', $anonymousId)
            ->getQuery()
            ->getSingleColumnResult();

        return array_values(array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $ids));
    }

    /**
     * @param array<int, int> $elementIds
     */
    public function deleteByUserAnonymousAndElements(UserId $userId, string $anonymousId, array $elementIds): void
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
            ->setParameter('elementIds', $elementIds, ArrayParameterType::INTEGER)
            ->getQuery()
            ->execute();
        $em->clear();
    }

    public function reassignAnonymousId(UserId $userId, string $oldAnonymousId, string $newAnonymousId): void
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
    public function deleteExistingRate(ImageId $elementId, UserId $userId, ?string $anonymousId): void
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

    public function insertRate(ImageId $elementId, UserId $userId, string $anonymousId, int $rate): void
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
            date: SqlDate::from(Env::now()->format('Y-m-d')),
        ));
        $em->flush();
    }

    /**
     * Every element with at least one rate, its rate count and sum.
     *
     * @return array<int, RateSummary>
     */
    public function findRateSummaries(): array
    {
        $rows = $this->createQueryBuilder('r')
            ->select('r.elementId AS elementId', 'COUNT(r.rate) AS rcount', 'SUM(r.rate) AS rsum')
            ->groupBy('r.elementId')
            ->getQuery()
            ->getArrayResult();

        $byItem = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $elementId = $row['elementId'] ?? null;
            if (! $elementId instanceof ImageId) {
                continue;
            }

            $byItem[$elementId->value] = new RateSummary(
                rcount: is_numeric($row['rcount'] ?? null) ? (int) $row['rcount'] : 0,
                rsum: is_numeric($row['rsum'] ?? null) ? (float) $row['rsum'] : 0.0,
            );
        }

        return $byItem;
    }

    /**
     * @param list<RatingScoreUpdate> $updates
     */
    public function updateRatingScores(array $updates): void
    {
        if ($updates === []) {
            return;
        }

        $em = $this->getEntityManager();
        foreach ($updates as $update) {
            $em->createQueryBuilder()
                ->update(ImageEntity::class, 'i')
                ->set('i.ratingScore', ':score')
                ->where('i.id = :id')
                ->setParameter('score', $update->ratingScore)
                ->setParameter('id', $update->id)
                ->getQuery()
                ->execute();
        }

        // images is Image\ImageEntity's own table (this repository
        // doesn't own it, just touches this one column) -- clear() keeps
        // the ORM identity map from holding a stale ImageEntity if one
        // happens to already be managed elsewhere in this request.
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
        // `images` (ImageEntity) and `rate` (RateEntity) are both
        // entity-mapped but have no declared association between them, so
        // this is a class-level (WITH-clause) arbitrary DQL join rather
        // than an association-path join.
        $ids = $this->getEntityManager()
            ->createQueryBuilder()
            ->select('i.id')
            ->from(ImageEntity::class, 'i')
            ->leftJoin(RateEntity::class, 'r', Join::WITH, 'i.id = r.elementId')
            ->where('r.elementId IS NULL')
            ->andWhere('i.ratingScore IS NOT NULL')
            ->getQuery()
            ->getSingleColumnResult();

        return array_values(array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $ids));
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
        $em->createQueryBuilder()
            ->update(ImageEntity::class, 'i')
            ->set('i.ratingScore', 'NULL')
            ->where('i.id IN (:ids)')
            ->setParameter('ids', $ids, ArrayParameterType::INTEGER)
            ->getQuery()
            ->execute();
        $em->clear();
    }

    /**
     * @return array<int, string>
     */
    public function findUsernamesById(): array
    {
        $rows = $this->getEntityManager()
            ->createQueryBuilder()
            ->select('u.id AS id', 'u.username AS username')
            ->from(UserEntity::class, 'u')
            ->getQuery()
            ->getArrayResult();

        $result = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $id = $row['id'] ?? null;
            $username = $row['username'] ?? null;
            if ($id instanceof UserId) {
                $result[$id->value] = $username instanceof Username ? $username->value : '';
            }
        }

        return $result;
    }

    /**
     * Admin "Rating" report: distinct rated elements, optionally scoped to
     * one user (included or excluded) and/or a set of categories.
     *
     * Queries `image_category` ({@see \Piwigo\Image\ImageCategoryEntity})
     * directly -- `Piwigo\Image` (L2aCoreDomain) is a legal dependency
     * from `Piwigo\Rate` (L2bExtendedDomain) per `deptrac.yaml`'s
     * ruleset.
     *
     * @param list<int> $categoryIds
     */
    public function countRatedElements(?UserId $filterUserId, bool $excludeFilterUser, array $categoryIds): int
    {
        $qb = $this->getEntityManager()
            ->createQueryBuilder()
            ->select('COUNT(DISTINCT r.elementId)')
            ->from(RateEntity::class, 'r');

        if ($categoryIds !== []) {
            $qb->innerJoin(ImageEntity::class, 'i', Join::WITH, 'r.elementId = i.id')
                ->innerJoin('i.imageCategories', 'ic')
                ->andWhere('ic.category IN (:categoryIds)')
                ->setParameter('categoryIds', $categoryIds, ArrayParameterType::INTEGER);
        }

        if ($filterUserId instanceof UserId) {
            $qb->andWhere($excludeFilterUser ? 'r.userId <> :filterUserId' : 'r.userId = :filterUserId')
                ->setParameter('filterUserId', $filterUserId);
        }

        $value = $qb->getQuery()
            ->getSingleScalarResult();

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * Same report as countRatedElements(), one row per rated image with its
     * rate aggregates.
     *
     * $orderBy carries only the column key, never a raw ORDER BY fragment
     * -- its one real caller ({@see \Piwigo\Admin\RatingPageRenderer})
     * always picks from a fixed set of columns, always sorted DESC, so
     * this method builds the DQL `orderBy()` call itself: a SELECT alias
     * for the 5 aggregate/aliased columns, a real property path for the 3
     * plain `ImageEntity` columns.
     *
     * `i.id` hydrates as a real {@see \Piwigo\Common\ValueObject\ImageId}
     * under `getArrayResult()` -- the `id` narrow below checks for that
     * VO instance rather than `is_numeric()`, which would silently
     * default every row's id to 0 (never caught by PHPStan, since
     * `getArrayResult()`'s own return type is bare `mixed[]`).
     *
     * @param list<int> $categoryIds
     * @param string $orderBy one of 'recently_rated'/'score'/'avg_rates'/
     *   'nb_rates'/'sum_rates'/'file'/'date_creation'/'date_available',
     *   always picked from the admin page's own fixed allowlist array,
     *   never from raw user input; any other value leaves the query
     *   unordered
     * @return list<RatingReportRow>
     */
    public function findRatingReport(?UserId $filterUserId, bool $excludeFilterUser, array $categoryIds, string $orderBy, int $limit, int $offset): array
    {
        $qb = $this->getEntityManager()
            ->createQueryBuilder()
            ->select(
                'i.id AS id',
                'i.path AS path',
                'i.file AS file',
                'i.representativeExt AS representative_ext',
                'i.ratingScore AS score',
                'MAX(r.date) AS recently_rated',
                'AVG(r.rate) AS avg_rates',
                'COUNT(r.rate) AS nb_rates',
                'SUM(r.rate) AS sum_rates',
            )
            ->from(RateEntity::class, 'r')
            ->leftJoin(ImageEntity::class, 'i', Join::WITH, 'r.elementId = i.id')
            ->groupBy('i.id', 'i.path', 'i.file', 'i.representativeExt', 'i.ratingScore', 'r.elementId')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        match ($orderBy) {
            'recently_rated' => $qb->orderBy('recently_rated', 'DESC'),
            'score' => $qb->orderBy('score', 'DESC'),
            'avg_rates' => $qb->orderBy('avg_rates', 'DESC'),
            'nb_rates' => $qb->orderBy('nb_rates', 'DESC'),
            'sum_rates' => $qb->orderBy('sum_rates', 'DESC'),
            'file' => $qb->orderBy('i.file', 'DESC'),
            'date_creation' => $qb->orderBy('i.dateCreation', 'DESC'),
            'date_available' => $qb->orderBy('i.dateAvailable', 'DESC'),
            default => null,
        };

        // Tiebreaker for every branch above, including `default` (which
        // orders by nothing at all). Each real branch sorts on a
        // non-unique column or an aggregate -- two images readily share a
        // score, an average or a rating count -- and this query is paged
        // with setFirstResult(), so without a total order a row can appear
        // on two pages or on none as $offset advances. `i.id` is in the
        // GROUP BY, so it is unique per returned row.
        $qb->addOrderBy('i.id', 'DESC');

        if ($categoryIds !== []) {
            $qb->innerJoin('i.imageCategories', 'ic')
                ->andWhere('ic.category IN (:categoryIds)')
                ->setParameter('categoryIds', $categoryIds, ArrayParameterType::INTEGER);
        }

        if ($filterUserId instanceof UserId) {
            $qb->andWhere($excludeFilterUser ? 'r.userId <> :filterUserId' : 'r.userId = :filterUserId')
                ->setParameter('filterUserId', $filterUserId);
        }

        $rows = $qb->getQuery()
            ->getArrayResult();

        $result = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $avgRates = is_numeric($row['avg_rates'] ?? null) ? round((float) $row['avg_rates'], 2) : null;

            $result[] = new RatingReportRow(
                id: ($row['id'] ?? null) instanceof ImageId ? $row['id']->value : (is_numeric($row['id'] ?? null) ? (int) $row['id'] : 0),
                path: is_string($row['path'] ?? null) ? $row['path'] : '',
                file: is_string($row['file'] ?? null) ? $row['file'] : '',
                representativeExt: is_string($row['representative_ext'] ?? null) ? $row['representative_ext'] : null,
                score: is_numeric($row['score'] ?? null) ? (float) $row['score'] : null,
                recentlyRated: is_string($row['recently_rated'] ?? null) ? $row['recently_rated'] : null,
                avgRates: $avgRates,
                nbRates: is_numeric($row['nb_rates'] ?? null) ? (int) $row['nb_rates'] : 0,
                sumRates: is_numeric($row['sum_rates'] ?? null) ? (float) $row['sum_rates'] : 0.0,
            );
        }

        return $result;
    }

    /**
     * @return list<Rate>
     */
    public function findRateRowsForElement(ImageId $elementId): array
    {
        // Maps the hydrated RateEntity objects to Rate by hand instead of
        // reusing Rate::fromRow() -- fromRow()'s own contract expects a
        // raw-DBAL-shaped row keyed by column name (element_id, user_id,
        // ...), whereas DQL entity/array hydration keys by PHP property
        // name (elementId, userId, ...); feeding one into the other would
        // silently default every field instead of surfacing a mismatch.
        $entities = $this->createQueryBuilder('r')
            ->where('r.elementId = :elementId')
            ->orderBy('r.date', 'DESC')
            ->setParameter('elementId', $elementId)
            ->getQuery()
            ->getResult();

        return array_map(
            static fn (RateEntity $r): Rate => new Rate(
                userId: $r->userId,
                elementId: $r->elementId,
                anonymousId: $r->anonymousId,
                rate: $r->rate,
                date: $r->date?->value,
            ),
            $entities
        );
    }

    public function countAllRates(): int
    {
        return (int) $this->createQueryBuilder('r')
            ->select('COUNT(r.elementId)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Deletes rates matching $userId, and optionally further narrowed to
     * $anonymousId and/or $elementId --
     * `Controller\Api\Users\UserDeleteRatingsController`'s own "delete
     * this user's rates, optionally scoped" method, a
     * different contract from {@see deleteByUserAnonymousAndElements()}
     * above (that one requires both a non-null anonymousId and a
     * non-empty elementIds list; every condition here is independently
     * optional). Returns the number of rows actually deleted.
     */
    public function deleteByOptionalConditions(UserId $userId, ?string $anonymousId, ?ImageId $elementId): int
    {
        $em = $this->getEntityManager();
        $qb = $em->createQueryBuilder()
            ->delete(RateEntity::class, 'r')
            ->where('r.userId = :userId')
            ->setParameter('userId', $userId);

        if ($anonymousId !== null) {
            $qb->andWhere('r.anonymousId = :anonymousId')
                ->setParameter('anonymousId', $anonymousId);
        }

        if ($elementId instanceof ImageId) {
            $qb->andWhere('r.elementId = :elementId')
                ->setParameter('elementId', $elementId);
        }

        $deleted = $qb->getQuery()
            ->execute();
        $em->clear();

        return $deleted;
    }

    /**
     * Number of rates for a single element -- Admin\PictureModifyPageRenderer's
     * own "how many times has this photo been rated" display.
     */
    public function countRatesForElement(ImageId $elementId): int
    {
        return (int) $this->createQueryBuilder('r')
            ->select('COUNT(r.elementId)')
            ->where('r.elementId = :elementId')
            ->setParameter('elementId', $elementId)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Admin "Rating by user" report: every rater, joined to their account
     * status (used by the page to decide whether to render them as an
     * anonymous rater).
     *
     * @return list<RaterInfo>
     */
    public function findUsersWithStatusByIdUsername(): array
    {
        $rows = $this->getEntityManager()
            ->createQueryBuilder()
            ->select('DISTINCT u.id AS id', 'u.username AS name', 'ui.status AS status')
            ->from(UserEntity::class, 'u')
            ->innerJoin(UserInfoEntity::class, 'ui', Join::WITH, 'u.id = ui.user')
            ->getQuery()
            ->getArrayResult();

        $result = [];
        foreach ($rows as $row) {
            if (! is_array($row) || ! ($row['id'] instanceof UserId)) {
                continue;
            }

            $name = $row['name'] ?? null;
            $status = $row['status'] ?? null;

            $result[] = new RaterInfo(
                id: $row['id']->value,
                name: $name instanceof Username ? $name->value : '',
                // `ui.status` (UserInfoEntity::$status) is enumType-mapped
                // -- array hydration returns a real UserStatus instance for
                // it, not a raw string.
                status: $status instanceof UserStatus ? $status->value : '',
            );
        }

        return $result;
    }

    /**
     * @return list<Rate>
     */
    public function findAllRatesOrderedByDateDesc(): array
    {
        // Maps RateEntity to Rate by hand rather than reusing
        // Rate::fromRow(), which expects DBAL's column-name-keyed row shape
        // (see findRateRowsForElement() above).
        $entities = $this->createQueryBuilder('r')
            ->orderBy('r.date', 'DESC')
            ->getQuery()
            ->getResult();

        return array_map(
            static fn (RateEntity $r): Rate => new Rate(
                userId: $r->userId,
                elementId: $r->elementId,
                anonymousId: $r->anonymousId,
                rate: $r->rate,
                date: $r->date?->value,
            ),
            $entities
        );
    }

    /**
     * Thin cross-domain touch (image thumbnail info for the "by user" rating
     * report), same precedent as this repository's own images.rating_score
     * update above -- not worth a new ImageRepository dependency for one
     * report query.
     *
     * Same `getArrayResult()`-hydration handling as {@see findRatingReport()}
     * above, for `id` (ImageId) -- `path` is a plain scalar column.
     *
     * @param list<int> $imageIds
     * @return list<ImageThumbInfo>
     */
    public function findImageThumbInfoByIds(array $imageIds): array
    {
        if ($imageIds === []) {
            return [];
        }

        $rows = $this->getEntityManager()
            ->createQueryBuilder()
            ->select('i.id AS id', 'i.name AS name', 'i.file AS file', 'i.path AS path', 'i.representativeExt AS representativeExt', 'i.level AS level')
            ->from(ImageEntity::class, 'i')
            ->where('i.id IN (:ids)')
            ->setParameter('ids', $imageIds, ArrayParameterType::INTEGER)
            ->getQuery()
            ->getArrayResult();

        return array_values(array_map(
            static function (mixed $row): ImageThumbInfo {
                if (! is_array($row)) {
                    return new ImageThumbInfo(0, null, '', '', null, 0);
                }

                return new ImageThumbInfo(
                    id: ($row['id'] ?? null) instanceof ImageId ? $row['id']->value : (is_numeric($row['id'] ?? null) ? (int) $row['id'] : 0),
                    name: is_string($row['name'] ?? null) ? $row['name'] : null,
                    file: is_string($row['file'] ?? null) ? $row['file'] : '',
                    path: is_string($row['path'] ?? null) ? $row['path'] : '',
                    representativeExt: is_string($row['representativeExt'] ?? null) ? $row['representativeExt'] : null,
                    level: is_numeric($row['level'] ?? null) ? (int) $row['level'] : 0,
                );
            },
            $rows
        ));
    }

    /**
     * @return array<int, float>
     */
    public function findAverageRatePerElement(): array
    {
        $rows = $this->createQueryBuilder('r')
            ->select('r.elementId AS elementId', 'AVG(r.rate) AS avgRate')
            ->groupBy('r.elementId')
            ->getQuery()
            ->getArrayResult();

        $result = [];
        foreach ($rows as $row) {
            $elementId = is_array($row) ? ($row['elementId'] ?? null) : null;
            if ($elementId instanceof ImageId) {
                $result[$elementId->value] = is_numeric($row['avgRate'] ?? null) ? (float) $row['avgRate'] : 0.0;
            }
        }

        return $result;
    }

    /**
     * @return list<int>
     */
    public function findTopRatedImageIds(int $limit): array
    {
        // MySQL's ORDER BY always treats NULL as the smallest value
        // regardless of ASC/DESC (so a bare DESC sort naturally puts NULL
        // ratingScore rows last), but PostgreSQL's default for DESC is
        // NULLS FIRST, putting an unrated image ahead of every real ranked
        // one. Neither engine has a portable NULLS LAST syntax (MySQL has
        // none at all), so this sorts on an explicit null-last
        // discriminant first, matching MySQL's behavior on both platforms.
        $ids = $this->getEntityManager()
            ->createQueryBuilder()
            ->select('i.id')
            ->from(ImageEntity::class, 'i')
            ->orderBy('CASE WHEN i.ratingScore IS NULL THEN 1 ELSE 0 END', 'ASC')
            ->addOrderBy('i.ratingScore', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getSingleColumnResult();

        return array_values(array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $ids));
    }

    /**
     * Picture page's rating-summary widget: count + average rate for a
     * single image.
     *
     * MySQL's `ROUND()` has no portable DQL/DBAL-platform equivalent, so
     * this uses a raw `AVG()` plus a PHP-side round(). A plain aggregate
     * query with no GROUP BY always returns exactly one row (count 0 /
     * average NULL for zero matching `rate` rows), so there's no
     * getOneOrNullResult()/NonUniqueResultException concern here.
     */
    public function findRateSummaryForElement(ImageId $elementId): RateSummaryForElement
    {
        $row = $this->createQueryBuilder('r')
            ->select('COUNT(r.rate) AS count', 'AVG(r.rate) AS average')
            ->where('r.elementId = :elementId')
            ->setParameter('elementId', $elementId)
            ->getQuery()
            ->getOneOrNullResult(Query::HYDRATE_ARRAY);

        if (! is_array($row)) {
            return new RateSummaryForElement(0, null);
        }

        return new RateSummaryForElement(
            count: is_numeric($row['count']) ? (int) $row['count'] : 0,
            average: is_numeric($row['average']) ? round((float) $row['average'], 2) : null,
        );
    }

    /**
     * The current user's own rate for a single image. $anonymousId is only
     * applied for non-classic (guest) users, always additionally filtered
     * by $userId regardless.
     *
     * `rate`'s PK is (element_id, user_id, anonymous_id), so without the
     * optional anonymous_id filter more than one row can match
     * (element_id, user_id) alone -- ->getOneOrNullResult() would throw
     * NonUniqueResultException in that case, so this pairs the query with
     * ->setMaxResults(1) instead, returning any one matching row.
     */
    public function findUserRate(ImageId $elementId, UserId $userId, ?string $anonymousId): ?int
    {
        $qb = $this->createQueryBuilder('r')
            ->select('r.rate')
            ->where('r.elementId = :elementId')
            ->andWhere('r.userId = :userId')
            ->setParameter('elementId', $elementId)
            ->setParameter('userId', $userId);

        if ($anonymousId !== null) {
            $qb->andWhere('r.anonymousId = :anonymousId')
                ->setParameter('anonymousId', $anonymousId);
        }

        $values = $qb->setMaxResults(1)
            ->getQuery()
            ->getSingleColumnResult();

        $value = $values[0] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }
}
