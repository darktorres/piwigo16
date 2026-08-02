<?php

declare(strict_types=1);

namespace Piwigo\Rate;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Query;
use Doctrine\ORM\Query\Expr\Join;
use Piwigo\Core\Env;
use Piwigo\Db\Tables;
use Piwigo\Image\ImageCategoryEntity;
use Piwigo\Image\ImageEntity;
use Piwigo\Rate\Projection\Rate;

/**
 * Persistence layer for the rate domain: `rate` itself, plus the
 * `images.rating_score` single-column bulk update -- a thin cross-domain
 * touch that stays inline here rather than becoming a new `ImageRepository`
 * dependency, same "thin cross-domain touch" precedent as History/
 * Activity's own single-column reads (see docs/PLAN.md's Epoch E section,
 * P17-P20 domain tiers).
 * Owns `rate` ({@see RateEntity}, composite PK). Item 14 DQL audit
 * (SQL-modernization plan): single/simple-condition reads and writes
 * against `rate`, plus the same-shaped single-column touches of
 * `images` (Image\ImageEntity, plain scalar columns, no custom Doctrine
 * Type), now go through real DQL -- including the class-level `images`
 * LEFT JOIN `rate` in findImageIdsWithStaleRatingScore(), which has no
 * declared association between the two entities. What stays plain DBAL
 * via $this->getEntityManager()->getConnection() is documented per
 * method with an "Item 14 DQL audit: stays on DBAL -- <reason>" note:
 * runtime-resolved multi-auth column names, joins against `users` /
 * `image_category` (neither ever entity-mapped in this migration -- Item
 * 14 Sub-phase B1 has since mapped `image_category`
 * {@see \Piwigo\Image\ImageCategoryEntity}, but crossing into it from here
 * is Sub-phase B4 scope, not yet done), or a caller-supplied raw ORDER BY
 * fragment. `ROUND()`, previously listed here too, no longer blocks
 * anything -- Sub-phase B5 Tier 2 replaced it everywhere in this file with
 * a raw `AVG()` plus a PHP-side round().
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
        $rows = $this->createQueryBuilder('r')
            ->select('r.elementId AS elementId', 'COUNT(r.rate) AS rcount', 'SUM(r.rate) AS rsum')
            ->groupBy('r.elementId')
            ->getQuery()
            ->getArrayResult();

        $byItem = [];
        foreach ($rows as $row) {
            if (! is_array($row) || ! is_numeric($row['elementId'] ?? null)) {
                continue;
            }

            $byItem[(int) $row['elementId']] = [
                'rcount' => is_numeric($row['rcount'] ?? null) ? (int) $row['rcount'] : 0,
                'rsum' => is_numeric($row['rsum'] ?? null) ? (float) $row['rsum'] : 0.0,
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
        foreach ($updates as $update) {
            $em->createQueryBuilder()
                ->update(ImageEntity::class, 'i')
                ->set('i.ratingScore', ':score')
                ->where('i.id = :id')
                ->setParameter('score', $update['ratingScore'])
                ->setParameter('id', $update['id'])
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
        // Item 14 DQL audit note: `images` (ImageEntity) and `rate`
        // (RateEntity) are both entity-mapped but have no declared
        // association between them, so this is a class-level (WITH-clause)
        // arbitrary DQL join rather than an association-path join.
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
     * Item 14 DQL audit: stays on DBAL -- $idColumn/$usernameColumn are
     * runtime-resolved column names (Piwigo's multi-auth
     * CurrentConfig::userFields() remapping), not fixed DQL property paths;
     * `users` is also deliberately not entity-mapped (see UserInfoEntity's
     * own docblock).
     *
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
     * SQL-modernization audit, Item 14 Sub-phase B4: converted to real
     * DQL -- `image_category` is now mapped
     * ({@see \Piwigo\Image\ImageCategoryEntity}), queried directly here
     * the same "no association required" way
     * {@see \Piwigo\Category\CategoryRepository::findStorageLinkedImageIds()}
     * queries `ImageEntity` (both `Piwigo\Image`, L2aCoreDomain -- a
     * legal same-layer dependency from `Piwigo\Rate`, L2bExtendedDomain,
     * per `deptrac.yaml`'s ruleset).
     *
     * @param list<int> $categoryIds
     */
    public function countRatedElements(?int $filterUserId, bool $excludeFilterUser, array $categoryIds): int
    {
        $qb = $this->getEntityManager()
            ->createQueryBuilder()
            ->select('COUNT(DISTINCT r.elementId)')
            ->from(RateEntity::class, 'r');

        if ($categoryIds !== []) {
            $qb->innerJoin(ImageEntity::class, 'i', Join::WITH, 'r.elementId = i.id')
                ->innerJoin(ImageCategoryEntity::class, 'ic', Join::WITH, 'ic.imageId = i.id')
                ->andWhere('ic.categoryId IN (:categoryIds)')
                ->setParameter('categoryIds', $categoryIds, ArrayParameterType::INTEGER);
        }

        if ($filterUserId !== null) {
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
     * SQL-modernization audit, Item 14 Sub-phase B4: converted to real
     * DQL -- `image_category` is now mapped
     * ({@see \Piwigo\Image\ImageCategoryEntity}), same "no association
     * required" shape as {@see countRatedElements()} above; the
     * caller-supplied raw "column DIRECTION" ORDER BY fragment turned out
     * to be a genuinely finite set of exactly 8 shapes at its one real
     * caller ({@see \Piwigo\Admin\RatingPageRenderer}'s own
     * `$available_order_by` array, always `DESC`), so $orderBy now carries
     * just the column key and this method decides the DQL `orderBy()`
     * call itself -- DQL supports ordering by a SELECT alias
     * (a "ResultVariable", confirmed against `vendor/doctrine/orm/.../
     * Parser.php`'s own `OrderByItem()` grammar) for the 5 aggregate/
     * aliased columns, and a real property path for the 3 plain
     * `ImageEntity` columns.
     *
     * Real bug found and fixed here, not just carried forward: Sub-phase
     * B5 Tier 2's own docblock previously claimed `ROUND(AVG(...), 2)` was
     * already replaced with a raw `AVG(...)` plus PHP-side round() for
     * this method too (true for {@see findRateSummaryForElement()}, which
     * that Tier 2 pass did convert) -- the code here was never actually
     * touched, a stale docblock claim not matching the code. Fixed now as
     * part of this conversion.
     *
     * @param list<int> $categoryIds
     * @param string $orderBy one of 'recently_rated'/'score'/'avg_rates'/
     *   'nb_rates'/'sum_rates'/'file'/'date_creation'/'date_available',
     *   always picked from the admin page's own fixed allowlist array,
     *   never from raw user input; any other value leaves the query
     *   unordered
     * @return list<array{id: int, path: string, file: string, representative_ext: ?string, score: ?float, recently_rated: ?string, avg_rates: ?float, nb_rates: int, sum_rates: float}>
     */
    public function findRatingReport(?int $filterUserId, bool $excludeFilterUser, array $categoryIds, string $orderBy, int $limit, int $offset): array
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

        if ($categoryIds !== []) {
            $qb->innerJoin(ImageCategoryEntity::class, 'ic', Join::WITH, 'ic.imageId = i.id')
                ->andWhere('ic.categoryId IN (:categoryIds)')
                ->setParameter('categoryIds', $categoryIds, ArrayParameterType::INTEGER);
        }

        if ($filterUserId !== null) {
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

            $result[] = [
                'id' => is_numeric($row['id'] ?? null) ? (int) $row['id'] : 0,
                'path' => is_string($row['path'] ?? null) ? $row['path'] : '',
                'file' => is_string($row['file'] ?? null) ? $row['file'] : '',
                'representative_ext' => is_string($row['representative_ext'] ?? null) ? $row['representative_ext'] : null,
                'score' => is_numeric($row['score'] ?? null) ? (float) $row['score'] : null,
                'recently_rated' => is_string($row['recently_rated'] ?? null) ? $row['recently_rated'] : null,
                'avg_rates' => $avgRates,
                'nb_rates' => is_numeric($row['nb_rates'] ?? null) ? (int) $row['nb_rates'] : 0,
                'sum_rates' => is_numeric($row['sum_rates'] ?? null) ? (float) $row['sum_rates'] : 0.0,
            ];
        }

        return $result;
    }

    /**
     * @return list<Rate>
     */
    public function findRateRowsForElement(int $elementId): array
    {
        // Item 14 DQL audit note: converted to real DQL. Deliberately maps
        // the hydrated RateEntity objects to Rate by hand instead of
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
                date: $r->date,
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
     * $anonymousId and/or $elementId -- Ws\PwgCore::ratesDelete()'s own
     * "delete this user's rates, optionally scoped" WS method, a
     * different contract from {@see deleteByUserAnonymousAndElements()}
     * above (that one requires both a non-null anonymousId and a
     * non-empty elementIds list; every condition here is independently
     * optional). Returns the number of rows actually deleted.
     */
    public function deleteByOptionalConditions(int $userId, ?string $anonymousId, ?int $elementId): int
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

        if ($elementId !== null) {
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
    public function countRatesForElement(int $elementId): int
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
     * Item 14 DQL audit: stays on DBAL -- $idColumn/$usernameColumn are
     * runtime-resolved multi-auth column names, not fixed DQL property
     * paths, and it joins `users`, which is deliberately not entity-mapped
     * (see UserInfoEntity's own docblock).
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
        // Item 14 DQL audit note: same reasoning as findRateRowsForElement()
        // above -- maps RateEntity to Rate by hand rather than reusing
        // Rate::fromRow(), which expects DBAL's column-name-keyed row shape.
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
                date: $r->date,
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
     * @param list<int> $imageIds
     * @return list<array{id: int, name: ?string, file: string, path: string, representative_ext: ?string, level: int}>
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
            static function (mixed $row): array {
                if (! is_array($row)) {
                    return [
                        'id' => 0,
                        'name' => null,
                        'file' => '',
                        'path' => '',
                        'representative_ext' => null,
                        'level' => 0,
                    ];
                }

                return [
                    'id' => is_numeric($row['id'] ?? null) ? (int) $row['id'] : 0,
                    'name' => is_string($row['name'] ?? null) ? $row['name'] : null,
                    'file' => is_string($row['file'] ?? null) ? $row['file'] : '',
                    'path' => is_string($row['path'] ?? null) ? $row['path'] : '',
                    'representative_ext' => is_string($row['representativeExt'] ?? null) ? $row['representativeExt'] : null,
                    'level' => is_numeric($row['level'] ?? null) ? (int) $row['level'] : 0,
                ];
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
            if (is_array($row) && is_numeric($row['elementId'] ?? null)) {
                $result[(int) $row['elementId']] = is_numeric($row['avgRate'] ?? null) ? (float) $row['avgRate'] : 0.0;
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
            ->createQueryBuilder()
            ->select('i.id')
            ->from(ImageEntity::class, 'i')
            ->orderBy('i.ratingScore', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getSingleColumnResult();

        return array_values(array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $ids));
    }

    /**
     * Picture page's rating-summary widget: count + average rate for a
     * single image.
     *
     * SQL-modernization audit, Item 14 Sub-phase B5 Tier 2: converted to
     * real DQL -- single-table, static WHERE; MySQL's `ROUND()` was the
     * only remaining blocker, and it has no portable DQL/DBAL-platform
     * equivalent. Replaced with a raw `AVG()` (a real, portable, standard
     * DQL aggregate on its own) plus a PHP-side round() -- a plain
     * aggregate query with no GROUP BY always returns exactly one row
     * (count 0 / average NULL for zero matching `rate` rows), so no
     * getOneOrNullResult()/NonUniqueResultException concern here.
     *
     * @return array{count: int, average: ?float}
     */
    public function findRateSummaryForElement(int $elementId): array
    {
        $row = $this->createQueryBuilder('r')
            ->select('COUNT(r.rate) AS count', 'AVG(r.rate) AS average')
            ->where('r.elementId = :elementId')
            ->setParameter('elementId', $elementId)
            ->getQuery()
            ->getOneOrNullResult(Query::HYDRATE_ARRAY);

        if (! is_array($row)) {
            return [
                'count' => 0,
                'average' => null,
            ];
        }

        return [
            'count' => is_numeric($row['count']) ? (int) $row['count'] : 0,
            'average' => is_numeric($row['average']) ? round((float) $row['average'], 2) : null,
        ];
    }

    /**
     * The current user's own rate for a single image. $anonymousId is only
     * applied for non-classic (guest) users -- matches the original's own
     * conditional `AND anonymous_id = ...` clause, always additionally
     * filtered by $userId regardless.
     *
     * Item 14 DQL audit note: converted to real DQL. `rate`'s PK is
     * (element_id, user_id, anonymous_id), so without the optional
     * anonymous_id filter more than one row can match (element_id, user_id)
     * alone -- ->getOneOrNullResult() would throw NonUniqueResultException
     * in that case, so this pairs the query with ->setMaxResults(1) instead,
     * matching the original DBAL fetchOne()'s "just give me any one
     * matching row" semantics.
     */
    public function findUserRate(int $elementId, int $userId, ?string $anonymousId): ?int
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
