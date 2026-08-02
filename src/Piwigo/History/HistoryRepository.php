<?php

declare(strict_types=1);

namespace Piwigo\History;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityRepository;
use Piwigo\Core\Env;
use Piwigo\Db\Tables;
use Piwigo\History\Projection\HistorySummaryCount;
use Piwigo\History\Projection\HistorySummaryCursor;
use Piwigo\Image\ImageEntity;

/**
 * Persistence layer for the history domain: `history` (one row per public
 * page view) and `history_summary` (year/month/day/hour rollup, keyed by a
 * NULL-inclusive unique index -- MySQL never treats two NULLs as equal in a
 * unique index, which is why summary rows that might already exist are
 * looked up explicitly (findSummaryRowsForHierarchy()) rather than upserted
 * blindly).
 *
 * Owns `history` ({@see HistoryEntity}) -- insert()/deleteBefore() and,
 * since the Item 14 DQL audit, findMinHistoryId()/countAll()/
 * findLatestHistoryId()/findOldestHistoryId()/search()/
 * findImageIdsByFilename() (the last against `images`/{@see
 * \Piwigo\Image\ImageEntity}, a different repository's own entity) go
 * through the ORM/DQL; every `history_summary` touch stays plain DBAL via
 * $this->getEntityManager()->getConnection() -- `history_summary` is never
 * entity-mapped at all (its NULL-inclusive composite-key WHERE has no
 * clean single-row shape an entity would help with), same "mixed
 * repository" shape Image/Category/Rate's own conversions established. A
 * handful of other classes (AuthRepository, Admin\Maintenance\
 * DbMaintenanceRepository, Admin\HistoryPageRenderer) also touch these two
 * tables directly via raw DBAL -- no cross-repository identity-map risk
 * from leaving the rest raw here either, since none of those go through
 * the ORM/entity manager for these tables.
 * Admin\InstallationStats/Admin\StatsPageRenderer/Ws\PwgCore were all
 * retargeted (during the raw-DBAL-out-of-non-Repository-classes pass)
 * onto this repository's own findLastByType()/findMonthlyRows()/
 * findDailyRowsForMonths()/findAverageDailyPageViewsSince()/
 * sumPageViews() for their history_summary reads; Ws\PwgCore's own
 * activity-table listing went to {@see \Piwigo\Activity\ActivityRepository::findPaginated()}
 * instead, a different table this class doesn't own.
 *
 * @extends EntityRepository<HistoryEntity>
 */
final class HistoryRepository extends EntityRepository
{
    public function findLastSummaryWithHistoryIdTo(): ?HistorySummaryCursor
    {
        $row = $this->getEntityManager()
            ->getConnection()
            ->createQueryBuilder()
            ->select('year', 'month', 'day', 'hour', 'history_id_to')
            ->from(Tables::historySummary())
            ->where('history_id_to IS NOT NULL')
            ->orderBy('history_id_to', 'DESC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return $row === false ? null : HistorySummaryCursor::fromRow($row);
    }

    /**
     * Item 14 DQL audit: converted to real DQL -- single-table MIN()
     * aggregate, no WHERE; `h.id` is a plain integer column, no custom
     * Doctrine Type involved.
     */
    public function findMinHistoryId(): ?int
    {
        $value = $this->createQueryBuilder('h')
            ->select('MIN(h.id)')
            ->getQuery()
            ->getSingleScalarResult();

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * Item 14 DQL audit: stays on DBAL -- `HOUR(time)` has no DQL
     * equivalent (only ABS/CONCAT/CURRENT_DATE/CURRENT_TIME/
     * CURRENT_TIMESTAMP/DATE_ADD/DATE_DIFF/DATE_SUB/LENGTH/LOCATE/LOWER/
     * MOD/SIZE/SQRT/SUBSTRING/TRIM/UPPER/BIT_AND/BIT_OR are standard DQL
     * functions, and this project's EntityManagerFactory registers no
     * custom DQL functions on top of those).
     *
     * One row per (date, hour) bucket with at least one history line in
     * ]$minId, $maxId].
     *
     * @return list<array{date: string, hour: int, minId: int, maxId: int, nbPages: int}>
     */
    public function findGroupedCountsSince(int $minId, ?int $maxId): array
    {
        $qb = $this->getEntityManager()
            ->getConnection()
            ->createQueryBuilder()
            ->select('date', 'HOUR(time) AS hour', 'MIN(id) AS min_id', 'MAX(id) AS max_id', 'COUNT(*) AS nb_pages')
            ->from(Tables::history())
            ->where('id > :minId')
            ->groupBy('date', 'hour')
            ->orderBy('date', 'ASC')
            ->addOrderBy('hour', 'ASC')
            ->setParameter('minId', $minId);

        if ($maxId !== null) {
            $qb->andWhere('id <= :maxId')
                ->setParameter('maxId', $maxId);
        }

        $rows = $qb->executeQuery()
            ->fetchAllAssociative();

        return array_map(
            static fn (array $row): array => [
                'date' => is_string($row['date']) ? $row['date'] : '',
                'hour' => is_numeric($row['hour']) ? (int) $row['hour'] : 0,
                'minId' => is_numeric($row['min_id']) ? (int) $row['min_id'] : 0,
                'maxId' => is_numeric($row['max_id']) ? (int) $row['max_id'] : 0,
                'nbPages' => is_numeric($row['nb_pages']) ? (int) $row['nb_pages'] : 0,
            ],
            $rows
        );
    }

    /**
     * Item 14 DQL audit: stays on DBAL -- `history_summary` is never
     * entity-mapped (see this class's own docblock); the dynamic
     * nullable-hierarchy WHERE it builds has no fixed property path DQL
     * could target here either way.
     *
     * Existing summary rows anywhere in the (year[, month[, day[, hour]]])
     * hierarchy -- e.g. for (2026, 7, 12, 3): the year-only row, the
     * year+month row, the year+month+day row, and the year+month+day+hour
     * row, whichever of those 4 already exist.
     *
     * @return list<HistorySummaryCount>
     */
    public function findSummaryRowsForHierarchy(int $year, ?int $month, ?int $day, ?int $hour): array
    {
        $qb = $this->getEntityManager()
            ->getConnection()
            ->createQueryBuilder()
            ->select('year', 'month', 'day', 'hour', 'nb_pages')
            ->from(Tables::historySummary())
            ->where('year = :year')
            ->setParameter('year', $year);

        $monthClause = 'month IS NULL';
        if ($month !== null) {
            $dayClause = 'day IS NULL';
            if ($day !== null) {
                $hourClause = 'hour IS NULL';
                if ($hour !== null) {
                    $hourClause = '(hour IS NULL OR hour = :hour)';
                    $qb->setParameter('hour', $hour);
                }

                $dayClause = '(day IS NULL OR (day = :day AND ' . $hourClause . '))';
                $qb->setParameter('day', $day);
            }

            $monthClause = '(month IS NULL OR (month = :month AND ' . $dayClause . '))';
            $qb->setParameter('month', $month);
        }

        $rows = $qb->andWhere($monthClause)
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(HistorySummaryCount::fromRow(...), $rows);
    }

    /**
     * Item 14 DQL audit: stays on DBAL -- `history_summary` is never
     * entity-mapped; also a bulk per-row UPDATE loop, not a single query
     * DQL would reshape anyway.
     *
     * @param list<array{year: int, month: ?int, day: ?int, hour: ?int, nbPages: int, historyIdTo: int}> $rows
     */
    public function updateSummaryRows(array $rows): void
    {
        $conn = $this->getEntityManager()
            ->getConnection();
        foreach ($rows as $row) {
            $qb = $conn->createQueryBuilder()
                ->update(Tables::historySummary())
                ->set('nb_pages', ':nbPages')
                ->set('history_id_to', ':historyIdTo')
                ->where('year = :year')
                ->setParameter('nbPages', $row['nbPages'])
                ->setParameter('historyIdTo', $row['historyIdTo'])
                ->setParameter('year', $row['year']);

            $qb->andWhere($row['month'] === null ? 'month IS NULL' : 'month = :month');
            if ($row['month'] !== null) {
                $qb->setParameter('month', $row['month']);
            }

            $qb->andWhere($row['day'] === null ? 'day IS NULL' : 'day = :day');
            if ($row['day'] !== null) {
                $qb->setParameter('day', $row['day']);
            }

            $qb->andWhere($row['hour'] === null ? 'hour IS NULL' : 'hour = :hour');
            if ($row['hour'] !== null) {
                $qb->setParameter('hour', $row['hour']);
            }

            $qb->executeStatement();
        }
    }

    /**
     * Item 14 DQL audit: stays on DBAL -- `history_summary` is never
     * entity-mapped; also a bulk per-row INSERT loop, not a DQL-expressible
     * write (ORM `persist()`/`flush()` writes one row per flush, not a
     * bulk statement, same carve-out as `BatchWriter`-based bulk inserts
     * elsewhere in this codebase).
     *
     * @param list<array{year: int, month: ?int, day: ?int, hour: ?int, nbPages: int, historyIdFrom: int, historyIdTo: int}> $rows
     */
    public function insertSummaryRows(array $rows): void
    {
        $conn = $this->getEntityManager()
            ->getConnection();
        foreach ($rows as $row) {
            $conn->createQueryBuilder()
                ->insert(Tables::historySummary())
                ->values([
                    'year' => ':year',
                    'month' => ':month',
                    'day' => ':day',
                    'hour' => ':hour',
                    'nb_pages' => ':nbPages',
                    'history_id_from' => ':historyIdFrom',
                    'history_id_to' => ':historyIdTo',
                ])
                ->setParameter('year', $row['year'])
                ->setParameter('month', $row['month'])
                ->setParameter('day', $row['day'])
                ->setParameter('hour', $row['hour'])
                ->setParameter('nbPages', $row['nbPages'])
                ->setParameter('historyIdFrom', $row['historyIdFrom'])
                ->setParameter('historyIdTo', $row['historyIdTo'])
                ->executeStatement();
        }
    }

    /**
     * Item 14 DQL audit: converted to real DQL -- single-table COUNT()
     * aggregate, no WHERE.
     */
    public function countAll(): int
    {
        $value = $this->createQueryBuilder('h')
            ->select('COUNT(h.id)')
            ->getQuery()
            ->getSingleScalarResult();

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * Item 14 DQL audit: stays on DBAL -- `history_summary` is never
     * entity-mapped.
     *
     * Total page views across every yearly summary row (`month IS NULL`
     * is `summarize()`'s own "whole year" rollup row, distinct from its
     * per-month/day/hour rows) -- Admin\InstallationStats's own
     * "nb_views" summary figure.
     */
    public function sumPageViews(): int
    {
        // SQL-modernization audit: verified, zero interpolation of any
        // kind (the table name is a structural Tables::xxx() constant),
        // nothing to bind.
        $historySummaryTable = Tables::historySummary();

        $value = $this->getEntityManager()
            ->getConnection()
            ->fetchOne(<<<SQL
                SELECT
                    SUM(nb_pages)
                FROM {$historySummaryTable}
                WHERE month IS NULL
                SQL);

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * Item 14 DQL audit: stays on DBAL -- `history_summary` is never
     * entity-mapped.
     *
     * The last $limit summary rows at the given hierarchy level ($type:
     * 'hour'/'day'/'month', or year for anything else), most recent
     * first -- Admin\StatsPageRenderer's own chart-data query, one real
     * caller, page-specific view-shaping (not a general-purpose finder).
     *
     * @return list<array{year: int|string, month: int|string|null, day: int|string|null, hour: int|string|null, nb_pages: int|string|null}>
     */
    public function findLastByType(string $type, int $limit): array
    {
        // SQL-modernization audit: $limit used to be spliced into
        // `LIMIT {$limit}` (a real `int` param, but still a value in
        // query text rather than bound) -- now setMaxResults().
        $qb = $this->getEntityManager()
            ->getConnection()
            ->createQueryBuilder()
            ->select('year', 'month', 'day', 'hour', 'nb_pages')
            ->from(Tables::historySummary())
            ->setMaxResults($limit);

        match ($type) {
            'hour' => $qb->where('year IS NOT NULL')
                ->andWhere('month IS NOT NULL')
                ->andWhere('day IS NOT NULL')
                ->andWhere('hour IS NOT NULL')
                ->orderBy('year', 'DESC')
                ->addOrderBy('month', 'DESC')
                ->addOrderBy('day', 'DESC')
                ->addOrderBy('hour', 'DESC'),
            'day' => $qb->where('year IS NOT NULL')
                ->andWhere('month IS NOT NULL')
                ->andWhere('day IS NOT NULL')
                ->andWhere('hour IS NULL')
                ->orderBy('year', 'DESC')
                ->addOrderBy('month', 'DESC')
                ->addOrderBy('day', 'DESC'),
            'month' => $qb->where('year IS NOT NULL')
                ->andWhere('month IS NOT NULL')
                ->andWhere('day IS NULL')
                ->orderBy('year', 'DESC')
                ->addOrderBy('month', 'DESC'),
            default => $qb->where('year IS NOT NULL')
                ->andWhere('month IS NULL')
                ->orderBy('year', 'DESC'),
        };

        /** @var list<array{year: int|string, month: int|string|null, day: int|string|null, hour: int|string|null, nb_pages: int|string|null}> */
        return $qb->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * Item 14 DQL audit: stays on DBAL -- `history_summary` is never
     * entity-mapped.
     *
     * Every month-level summary row, most recent first, optionally capped
     * at $limit -- Admin\StatsPageRenderer's own "compare years" chart
     * data, one real caller.
     *
     * @return list<array{year: int|string, month: int|string|null, day: int|string|null, hour: int|string|null, nb_pages: int|string|null}>
     */
    public function findMonthlyRows(?int $limit): array
    {
        $qb = $this->getEntityManager()
            ->getConnection()
            ->createQueryBuilder()
            ->select('year', 'month', 'day', 'hour', 'nb_pages')
            ->from(Tables::historySummary())
            ->where('month IS NOT NULL')
            ->andWhere('day IS NULL')
            ->orderBy('year', 'DESC')
            ->addOrderBy('month', 'DESC');

        if ($limit !== null) {
            $qb->setMaxResults($limit);
        }

        /** @var list<array{year: int|string, month: int|string|null, day: int|string|null, hour: int|string|null, nb_pages: int|string|null}> */
        return $qb->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * Item 14 DQL audit: stays on DBAL -- `history_summary` is never
     * entity-mapped.
     *
     * Day-level summary rows for 3 specific (year, month) pairs (this
     * month, last month, this month last year) -- Admin\
     * StatsPageRenderer::getMonthStats()'s own "recent months" chart data,
     * one real caller.
     *
     * @return list<array{year: int|string, month: int|string|null, day: int|string|null, hour: int|string|null, nb_pages: int|string|null}>
     */
    public function findDailyRowsForMonths(int $year1, int $month1, int $year2, int $month2, int $year3, int $month3): array
    {
        /** @var list<array{year: int|string, month: int|string|null, day: int|string|null, hour: int|string|null, nb_pages: int|string|null}> */
        return $this->getEntityManager()
            ->getConnection()
            ->createQueryBuilder()
            ->select('year', 'month', 'day', 'hour', 'nb_pages')
            ->from(Tables::historySummary())
            ->where('(year = :year1 AND month = :month1) OR (year = :year2 AND month = :month2) OR (year = :year3 AND month = :month3)')
            ->andWhere('day IS NOT NULL')
            ->andWhere('hour IS NULL')
            ->orderBy('year', 'DESC')
            ->addOrderBy('month', 'DESC')
            ->setParameter('year1', $year1)
            ->setParameter('month1', $month1)
            ->setParameter('year2', $year2)
            ->setParameter('month2', $month2)
            ->setParameter('year3', $year3)
            ->setParameter('month3', $month3)
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * Item 14 DQL audit: stays on DBAL -- `history_summary` is never
     * entity-mapped.
     *
     * Average daily page views across the trailing 12-ish months (this
     * year, plus last year from $afterMonth onward) -- Admin\
     * StatsPageRenderer::getMonthStats()'s own "avg" figure, one real
     * caller.
     */
    public function findAverageDailyPageViewsSince(int $year, int $previousYear, int $afterMonth): ?float
    {
        $value = $this->getEntityManager()
            ->getConnection()
            ->createQueryBuilder()
            ->select('AVG(nb_pages)')
            ->from(Tables::historySummary())
            ->where('year = :year OR (year = :previousYear AND month > :afterMonth)')
            ->andWhere('day IS NOT NULL')
            ->andWhere('hour IS NULL')
            ->orderBy('year', 'DESC')
            ->addOrderBy('month', 'DESC')
            ->setParameter('year', $year)
            ->setParameter('previousYear', $previousYear)
            ->setParameter('afterMonth', $afterMonth)
            ->executeQuery()
            ->fetchOne();

        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * Item 14 DQL audit: converted to real DQL -- single-table ORDER BY +
     * LIMIT 1; getSingleColumnResult() + `$ids[0] ?? null` used instead of
     * getOneOrNullResult() (Item 14 DQL audit gotcha #3: the latter throws
     * NonUniqueResultException on more than one row, which setMaxResults(1)
     * here rules out, but the array-index form matches the original's own
     * "no rows -> null" fetchOne() semantics with no exception path at
     * all).
     */
    public function findLatestHistoryId(): ?int
    {
        $ids = $this->createQueryBuilder('h')
            ->select('h.id')
            ->orderBy('h.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getSingleColumnResult();

        $value = $ids[0] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * Item 14 DQL audit: converted to real DQL -- same shape as
     * findLatestHistoryId() above, ASC instead of DESC.
     */
    public function findOldestHistoryId(): ?int
    {
        $ids = $this->createQueryBuilder('h')
            ->select('h.id')
            ->orderBy('h.id', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getSingleColumnResult();

        $value = $ids[0] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }

    public function deleteBefore(int $id): void
    {
        $em = $this->getEntityManager();
        $em->createQueryBuilder()
            ->delete(HistoryEntity::class, 'h')
            ->where('h.id < :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->execute();
        $em->clear();
    }

    /**
     * Item 14 DQL audit: converted to real DQL -- single-table (`images`,
     * mapped by {@see ImageEntity}, a different repository's own entity
     * but freely queryable via the shared EntityManager), static WHERE,
     * no join/aggregate DQL can't express; `id`/`file` are plain
     * integer/string columns, no custom Doctrine Type involved.
     *
     * @return list<int>
     */
    public function findImageIdsByFilename(string $filenamePattern): array
    {
        $ids = $this->getEntityManager()
            ->createQueryBuilder()
            ->select('i.id')
            ->from(ImageEntity::class, 'i')
            ->where('i.file LIKE :pattern')
            ->setParameter('pattern', $filenamePattern)
            ->getQuery()
            ->getSingleColumnResult();

        return array_values(array_map(
            static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0,
            $ids
        ));
    }

    /**
     * Item 14 DQL audit: converted to real DQL -- single-table (`history`),
     * every filter a WHERE/LIKE/IN DQL can express; the dynamic
     * $imageTypes OR-clause is built as a plain bound-placeholder string
     * (not a loop-built Orx composite) to sidestep the phpstan-doctrine
     * static-analysis false positive on loop-built composites (Item 14
     * DQL audit gotcha #2) -- same "raw string + real bound params"
     * convention already used everywhere else in this codebase. None of
     * `history`'s own columns route through a custom Doctrine Type, so no
     * value-object hydration concern here either.
     *
     * History lines matching every given filter, each filter applied only
     * when its criterion is present (matches get_history()'s own
     * conditional clause-building).
     *
     * @param ?list<string> $imageTypes the search's own requested subset
     *   of $types (null when no type filter was requested at all)
     * @param list<string> $types every possible image_type value + 'none'
     *   (matched against a NULL image_type)
     * @param ?list<int> $imageIdsFromFilename
     * @return list<array{date: ?string, time: string, user_id: int, IP: string, section: ?string, category_id: ?int, search_id: ?int, tag_ids: ?string, image_id: ?int, image_type: ?string}>
     */
    public function search(
        ?string $dateAfter,
        ?string $dateBefore,
        ?array $imageTypes,
        array $types,
        ?int $userId,
        ?int $imageId,
        ?array $imageIdsFromFilename,
        ?string $ip
    ): array {
        $qb = $this->createQueryBuilder('h')
            ->select('h.date', 'h.time', 'h.userId', 'h.ip', 'h.section', 'h.categoryId', 'h.searchId', 'h.tagIds', 'h.imageId', 'h.imageType');

        if ($dateAfter !== null) {
            $qb->andWhere('h.date >= :dateAfter')
                ->setParameter('dateAfter', $dateAfter);
        }

        if ($dateBefore !== null) {
            $qb->andWhere('h.date <= :dateBefore')
                ->setParameter('dateBefore', $dateBefore);
        }

        if ($imageTypes !== null) {
            $typeClauses = [];
            foreach ($types as $i => $type) {
                if (! in_array($type, $imageTypes, true)) {
                    continue;
                }

                if ($type === 'none') {
                    $typeClauses[] = 'h.imageType IS NULL';
                } else {
                    $paramName = 'type' . $i;
                    $typeClauses[] = 'h.imageType = :' . $paramName;
                    $qb->setParameter($paramName, $type);
                }
            }

            if ($typeClauses !== []) {
                $qb->andWhere('(' . implode(' OR ', $typeClauses) . ')');
            }
        }

        if ($userId !== null) {
            $qb->andWhere('h.userId = :userId')
                ->setParameter('userId', $userId);
        }

        if ($imageId !== null) {
            $qb->andWhere('h.imageId = :imageId')
                ->setParameter('imageId', $imageId);
        }

        if ($imageIdsFromFilename !== null) {
            if ($imageIdsFromFilename === []) {
                // a filename filter was given but matched no image: always false
                $qb->andWhere('1 = 2');
            } else {
                $qb->andWhere('h.imageId IN (:imageIds)')
                    ->setParameter('imageIds', $imageIdsFromFilename, ArrayParameterType::INTEGER);
            }
        }

        if ($ip !== null) {
            $qb->andWhere('h.ip LIKE :ip')
                ->setParameter('ip', $ip);
        }

        $results = [];
        foreach ($qb->getQuery()->getArrayResult() as $row) {
            if (! is_array($row)) {
                continue;
            }

            $results[] = [
                'date' => is_string($row['date'] ?? null) ? $row['date'] : null,
                'time' => is_string($row['time'] ?? null) ? $row['time'] : '',
                'user_id' => is_numeric($row['userId'] ?? null) ? (int) $row['userId'] : 0,
                'IP' => is_string($row['ip'] ?? null) ? $row['ip'] : '',
                'section' => is_string($row['section'] ?? null) ? $row['section'] : null,
                'category_id' => is_numeric($row['categoryId'] ?? null) ? (int) $row['categoryId'] : null,
                'search_id' => is_numeric($row['searchId'] ?? null) ? (int) $row['searchId'] : null,
                'tag_ids' => is_string($row['tagIds'] ?? null) ? $row['tagIds'] : null,
                'image_id' => is_numeric($row['imageId'] ?? null) ? (int) $row['imageId'] : null,
                'image_type' => is_string($row['imageType'] ?? null) ? $row['imageType'] : null,
            ];
        }

        return $results;
    }

    public function updateLastVisitNow(int $userId): void
    {
        // Item 14 DQL audit: stays on DBAL -- writes `user_infos`, a
        // different repository's own table/entity
        // ({@see \Piwigo\Users\UserInfoEntity}), not this repository's
        // HistoryEntity; this class has no business declaring a DQL UPDATE
        // against another domain's entity, and the deliberate
        // `lastmodified = lastmodified` self-assignment below (an
        // ORM-identity-map side-channel write, see its own note) is exactly
        // the kind of caller-specific quirk that belongs on DBAL rather
        // than baked into a general-purpose DQL statement.
        //
        // Env::now() rather than SQL's NOW() -- matches
        // SessionRepository/CommentRepository's own established reasoning
        // (invisible to PIWIGO_TEST_NOW). `lastmodified = lastmodified` is
        // a deliberate self-assignment (see Auth\AuthRepository::
        // saveLastVisitFromHistory()'s own docblock for why). Bypasses the
        // ORM for a row Users\UserInfoEntity may already have cached.
        $em = $this->getEntityManager();
        $em->getConnection()
            ->createQueryBuilder()
            ->update(Tables::userInfos())
            ->set('last_visit', ':now')
            ->set('lastmodified', 'lastmodified')
            ->where('user_id = :userId')
            ->setParameter('now', Env::now()->format('Y-m-d H:i:s'))
            ->setParameter('userId', $userId)
            ->executeStatement();
        $em->clear();
    }

    /**
     * Item 14 DQL audit: stays on DBAL -- a `DESC <table>` schema-
     * introspection statement, not a data query at all; DQL has no
     * equivalent for reading a live column definition.
     *
     * Parses the `history`.`section` column's current ENUM options
     * (`enum('blue','green','black')` -> `['blue', 'green', 'black']`),
     * matching the original MysqliDb::getEnums()'s own `DESC` + string-parse
     * approach -- no cross-driver-portable DBAL equivalent exists for
     * reading a live ENUM definition.
     *
     * @return list<string>
     */
    public function getSectionEnumOptions(): array
    {
        // SQL-modernization audit: verified, {$historyTable} is a
        // structural Tables::history() constant, no real value spliced.
        $historyTable = Tables::history();
        $rows = $this->getEntityManager()
            ->getConnection()
            ->executeQuery(<<<SQL
                DESC {$historyTable}
                SQL)->fetchAllAssociative();

        foreach ($rows as $row) {
            if (($row['Field'] ?? null) === 'section') {
                $type = is_string($row['Type'] ?? null) ? $row['Type'] : '';
                $options = explode(',', substr($type, 5, -1));

                return array_map(static fn (string $option): string => str_replace('\'', '', $option), $options);
            }
        }

        return [];
    }

    /**
     * Item 14 DQL audit: stays on DBAL -- a DDL `ALTER TABLE` statement,
     * not a DQL-expressible operation at all (DQL only targets
     * SELECT/UPDATE/DELETE data queries, never schema DDL).
     *
     * Widens the `section` column's ENUM definition to include every
     * option in $options -- $options is always getSectionEnumOptions()'s
     * own DB-introspected values with one new value appended, that new
     * value already regex-validated by the caller (HistoryService::
     * logVisit(), `/^[a-zA-Z0-9_-]+$/`), never raw user input, matching
     * the original's own trust boundary for this DDL statement.
     *
     * @param  list<string>  $options
     */
    public function alterSectionEnum(array $options): void
    {
        // SQL-modernization audit: verified, not a target -- an ENUM
        // column definition has no bind-able parameter position in any
        // SQL dialect (DDL, same carve-out as Admin\Maintenance\
        // DbMaintenanceRepository::repairOptimizeAllTables()), and
        // $options is already regex-gated by the one real caller (see
        // this method's own docblock) rather than raw user input.
        $enumList = implode(',', array_map(static fn (string $option): string => "'" . $option . "'", array_unique($options)));

        $historyTable = Tables::history();
        $this->getEntityManager()
            ->getConnection()
            ->executeStatement(
                <<<SQL
                ALTER TABLE {$historyTable} CHANGE section section enum({$enumList}) DEFAULT NULL
                SQL
            );
    }

    /**
     * @param array{
     *   userId: int, ip: string, section: ?string, categoryId: ?int,
     *   searchId: ?int, imageId: ?int, imageType: ?string,
     *   formatId: int|string|null, authKeyId: ?int, tagsString: ?string,
     * } $data
     */
    public function insert(array $data): int
    {
        $now = Env::now();

        $entity = new HistoryEntity(
            date: $now->format('Y-m-d'),
            time: $now->format('H:i:s'),
            userId: $data['userId'],
            ip: $data['ip'],
            section: $data['section'],
            categoryId: $data['categoryId'],
            searchId: $data['searchId'],
            tagIds: $data['tagsString'],
            imageId: $data['imageId'],
            imageType: $data['imageType'],
            formatId: is_numeric($data['formatId']) ? (int) $data['formatId'] : null,
            authKeyId: $data['authKeyId'],
        );

        $em = $this->getEntityManager();
        $em->persist($entity);
        $em->flush();

        assert($entity->id !== null);

        return $entity->id;
    }
}
