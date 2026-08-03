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
 * Owns `history` ({@see HistoryEntity}) and, since Item 14 Sub-phase B1/B2,
 * `history_summary` too ({@see HistorySummaryEntity} -- previously claimed
 * to have "no clean single-row shape an entity would help with"; re-audited
 * and every method against it goes through the ORM/DQL now, including
 * findSummaryRowsForHierarchy()'s own nested-conditional WHERE, a direct
 * 1:1 port of the same fixed-shape branching the original DBAL version
 * already did). A handful of other classes (AuthRepository, Admin\
 * Maintenance\DbMaintenanceRepository, Admin\HistoryPageRenderer) still
 * touch these two tables directly via raw DBAL -- no cross-repository
 * identity-map risk from that, since none of those go through the ORM/
 * entity manager for these tables.
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
    /**
     * Item 14 DQL audit, re-corrected: `history_summary` is now mapped
     * ({@see HistorySummaryEntity}). Converted to real DQL -- single-table,
     * static WHERE.
     */
    public function findLastSummaryWithHistoryIdTo(): ?HistorySummaryCursor
    {
        $rows = $this->getEntityManager()
            ->createQueryBuilder()
            ->select('hs.year', 'hs.month', 'hs.day', 'hs.hour', 'hs.historyIdTo AS history_id_to')
            ->from(HistorySummaryEntity::class, 'hs')
            ->where('hs.historyIdTo IS NOT NULL')
            ->orderBy('hs.historyIdTo', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getArrayResult();

        $row = $rows[0] ?? null;
        if (! is_array($row)) {
            return null;
        }

        return HistorySummaryCursor::fromRow([
            'year' => $row['year'] ?? null,
            'month' => $row['month'] ?? null,
            'day' => $row['day'] ?? null,
            'hour' => $row['hour'] ?? null,
            'history_id_to' => $row['history_id_to'] ?? null,
        ]);
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
     * SQL-modernization audit, Item 14 Sub-phase B5 Tier 2: converted to
     * real DQL -- MySQL's `HOUR(time)` has no portable DQL equivalent
     * (only ABS/CONCAT/CURRENT_DATE/CURRENT_TIME/CURRENT_TIMESTAMP/
     * DATE_ADD/DATE_DIFF/DATE_SUB/LENGTH/LOCATE/LOWER/MOD/SIZE/SQRT/
     * SUBSTRING/TRIM/UPPER/BIT_AND/BIT_OR are standard DQL functions, and
     * this project's EntityManagerFactory registers no custom DQL
     * functions on top of those), and it was also part of the `GROUP BY`
     * key (DQL can't group by a SELECT alias). Fetches `date`/`time`/`id`
     * per row instead and groups in PHP -- the real caller
     * ({@see \Piwigo\History\HistoryService::summarize()}) is a
     * chunked/cron-style batch job, not a hot request path, and already
     * supports a `$maxLines` cap for controlling batch size; scanning
     * every history row in ]$minId, $maxId] is the same trade
     * `updateSummaryRows()`/`insertSummaryRows()` already made for their
     * own per-row loops. `time` is an `HH:MM:SS` string (`HistoryEntity`'s
     * own `length: 8`), so `(int) substr($time, 0, 2)` reproduces
     * `HOUR(time)`'s output exactly.
     *
     * One row per (date, hour) bucket with at least one history line in
     * ]$minId, $maxId].
     *
     * @return list<array{date: string, hour: int, minId: int, maxId: int, nbPages: int}>
     */
    public function findGroupedCountsSince(int $minId, ?int $maxId): array
    {
        $qb = $this->createQueryBuilder('h')
            ->select('h.id AS id', 'h.date AS date', 'h.time AS time')
            ->where('h.id > :minId')
            ->setParameter('minId', $minId);

        if ($maxId !== null) {
            $qb->andWhere('h.id <= :maxId')
                ->setParameter('maxId', $maxId);
        }

        $rows = $qb->getQuery()
            ->getArrayResult();

        $byKey = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $id = is_numeric($row['id'] ?? null) ? (int) $row['id'] : 0;
            $date = is_string($row['date'] ?? null) ? $row['date'] : '';
            $time = is_string($row['time'] ?? null) ? $row['time'] : '';
            $hour = (int) substr($time, 0, 2);

            $key = $date . "\0" . $hour;

            if (! isset($byKey[$key])) {
                $byKey[$key] = [
                    'date' => $date,
                    'hour' => $hour,
                    'minId' => $id,
                    'maxId' => $id,
                    'nbPages' => 0,
                ];
            }

            $byKey[$key]['minId'] = min($byKey[$key]['minId'], $id);
            $byKey[$key]['maxId'] = max($byKey[$key]['maxId'], $id);
            $byKey[$key]['nbPages']++;
        }

        $result = array_values($byKey);
        usort(
            $result,
            static fn (array $a, array $b): int => [$a['date'], $a['hour']] <=> [$b['date'], $b['hour']]
        );

        return $result;
    }

    /**
     * Item 14 DQL audit, re-corrected: `history_summary` is now mapped
     * ({@see HistorySummaryEntity}). Converted to real DQL -- the "dynamic
     * nullable-hierarchy WHERE" the original note flagged isn't actually
     * caller-supplied text: it's a fixed nested-conditional *shape*
     * (branching purely on which of $month/$day/$hour are null), the same
     * shape the original DBAL version itself already built one clause
     * string at a time -- a direct 1:1 port to DQL property paths, no
     * redesign needed.
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
            ->createQueryBuilder()
            ->select('hs.year', 'hs.month', 'hs.day', 'hs.hour', 'hs.nbPages AS nb_pages')
            ->from(HistorySummaryEntity::class, 'hs')
            ->where('hs.year = :year')
            ->setParameter('year', $year);

        $monthClause = 'hs.month IS NULL';
        if ($month !== null) {
            $dayClause = 'hs.day IS NULL';
            if ($day !== null) {
                $hourClause = 'hs.hour IS NULL';
                if ($hour !== null) {
                    $hourClause = '(hs.hour IS NULL OR hs.hour = :hour)';
                    $qb->setParameter('hour', $hour);
                }

                $dayClause = '(hs.day IS NULL OR (hs.day = :day AND ' . $hourClause . '))';
                $qb->setParameter('day', $day);
            }

            $monthClause = '(hs.month IS NULL OR (hs.month = :month AND ' . $dayClause . '))';
            $qb->setParameter('month', $month);
        }

        /** @var list<array{year: int|string, month: int|string|null, day: int|string|null, hour: int|string|null, nb_pages: int|string|null}> */
        $rows = $qb->andWhere($monthClause)
            ->getQuery()
            ->getArrayResult();

        return array_map(HistorySummaryCount::fromRow(...), $rows);
    }

    /**
     * Item 14 DQL audit, re-corrected: `history_summary` is now mapped
     * ({@see HistorySummaryEntity}). Converted to real DQL -- still a
     * per-row loop (each row has its own distinct WHERE, so this can't
     * collapse into a single bulk statement), but each iteration is now a
     * real DQL `UPDATE ... WHERE`, same "bulk UPDATE bypasses the ORM
     * identity map" shape as {@see \Piwigo\Image\ImageRepository::
     * updateLevelForImages()}.
     *
     * @param list<array{year: int, month: ?int, day: ?int, hour: ?int, nbPages: int, historyIdTo: int}> $rows
     */
    public function updateSummaryRows(array $rows): void
    {
        $em = $this->getEntityManager();
        foreach ($rows as $row) {
            $qb = $em->createQueryBuilder()
                ->update(HistorySummaryEntity::class, 'hs')
                ->set('hs.nbPages', ':nbPages')
                ->set('hs.historyIdTo', ':historyIdTo')
                ->where('hs.year = :year')
                ->setParameter('nbPages', $row['nbPages'])
                ->setParameter('historyIdTo', $row['historyIdTo'])
                ->setParameter('year', $row['year']);

            $qb->andWhere($row['month'] === null ? 'hs.month IS NULL' : 'hs.month = :month');
            if ($row['month'] !== null) {
                $qb->setParameter('month', $row['month']);
            }

            $qb->andWhere($row['day'] === null ? 'hs.day IS NULL' : 'hs.day = :day');
            if ($row['day'] !== null) {
                $qb->setParameter('day', $row['day']);
            }

            $qb->andWhere($row['hour'] === null ? 'hs.hour IS NULL' : 'hs.hour = :hour');
            if ($row['hour'] !== null) {
                $qb->setParameter('hour', $row['hour']);
            }

            $qb->getQuery()
                ->execute();
        }
    }

    /**
     * Item 14 DQL audit, re-corrected: `history_summary` is now mapped
     * ({@see HistorySummaryEntity}) -- DQL still has no INSERT statement
     * at all, but a real ORM `persist()`-per-row loop with a single
     * `flush()` after it is the same N-individual-writes shape this raw
     * per-row `INSERT` loop already had, same precedent as {@see
     * \Piwigo\Activity\ActivityRepository::insertMany()}. `BatchWriter`
     * stays the right tool for a genuine single bulk multi-row `VALUES
     * (...), (...), ...` statement, which this was never doing anyway.
     *
     * @param list<array{year: int, month: ?int, day: ?int, hour: ?int, nbPages: int, historyIdFrom: int, historyIdTo: int}> $rows
     */
    public function insertSummaryRows(array $rows): void
    {
        $em = $this->getEntityManager();
        foreach ($rows as $row) {
            $em->persist(new HistorySummaryEntity(
                year: $row['year'],
                month: $row['month'],
                day: $row['day'],
                hour: $row['hour'],
                nbPages: $row['nbPages'],
                historyIdFrom: $row['historyIdFrom'],
                historyIdTo: $row['historyIdTo'],
            ));
        }

        $em->flush();
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
     * Item 14 DQL audit, re-corrected: `history_summary` is now mapped
     * ({@see HistorySummaryEntity}). Converted to real DQL -- single-table,
     * static WHERE, SUM() is a standard DQL function.
     *
     * Total page views across every yearly summary row (`month IS NULL`
     * is `summarize()`'s own "whole year" rollup row, distinct from its
     * per-month/day/hour rows) -- Admin\InstallationStats's own
     * "nb_views" summary figure.
     */
    public function sumPageViews(): int
    {
        $value = $this->getEntityManager()
            ->createQueryBuilder()
            ->select('SUM(hs.nbPages)')
            ->from(HistorySummaryEntity::class, 'hs')
            ->where('hs.month IS NULL')
            ->getQuery()
            ->getSingleScalarResult();

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * Item 14 DQL audit, re-corrected: `history_summary` is now mapped
     * ({@see HistorySummaryEntity}). Converted to real DQL -- single-table;
     * `$type`'s match() only ever selects one of 4 fixed WHERE/ORDER BY
     * shapes, not a caller-supplied dynamic fragment.
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
        $qb = $this->getEntityManager()
            ->createQueryBuilder()
            ->select('hs.year', 'hs.month', 'hs.day', 'hs.hour', 'hs.nbPages AS nb_pages')
            ->from(HistorySummaryEntity::class, 'hs')
            ->setMaxResults($limit);

        match ($type) {
            'hour' => $qb->where('hs.year IS NOT NULL')
                ->andWhere('hs.month IS NOT NULL')
                ->andWhere('hs.day IS NOT NULL')
                ->andWhere('hs.hour IS NOT NULL')
                ->orderBy('hs.year', 'DESC')
                ->addOrderBy('hs.month', 'DESC')
                ->addOrderBy('hs.day', 'DESC')
                ->addOrderBy('hs.hour', 'DESC'),
            'day' => $qb->where('hs.year IS NOT NULL')
                ->andWhere('hs.month IS NOT NULL')
                ->andWhere('hs.day IS NOT NULL')
                ->andWhere('hs.hour IS NULL')
                ->orderBy('hs.year', 'DESC')
                ->addOrderBy('hs.month', 'DESC')
                ->addOrderBy('hs.day', 'DESC'),
            'month' => $qb->where('hs.year IS NOT NULL')
                ->andWhere('hs.month IS NOT NULL')
                ->andWhere('hs.day IS NULL')
                ->orderBy('hs.year', 'DESC')
                ->addOrderBy('hs.month', 'DESC'),
            default => $qb->where('hs.year IS NOT NULL')
                ->andWhere('hs.month IS NULL')
                ->orderBy('hs.year', 'DESC'),
        };

        /** @var list<array{year: int|string, month: int|string|null, day: int|string|null, hour: int|string|null, nb_pages: int|string|null}> */
        return $qb->getQuery()
            ->getArrayResult();
    }

    /**
     * Item 14 DQL audit, re-corrected: `history_summary` is now mapped
     * ({@see HistorySummaryEntity}). Converted to real DQL -- single-table,
     * static WHERE.
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
            ->createQueryBuilder()
            ->select('hs.year', 'hs.month', 'hs.day', 'hs.hour', 'hs.nbPages AS nb_pages')
            ->from(HistorySummaryEntity::class, 'hs')
            ->where('hs.month IS NOT NULL')
            ->andWhere('hs.day IS NULL')
            ->orderBy('hs.year', 'DESC')
            ->addOrderBy('hs.month', 'DESC');

        if ($limit !== null) {
            $qb->setMaxResults($limit);
        }

        /** @var list<array{year: int|string, month: int|string|null, day: int|string|null, hour: int|string|null, nb_pages: int|string|null}> */
        return $qb->getQuery()
            ->getArrayResult();
    }

    /**
     * Item 14 DQL audit, re-corrected: `history_summary` is now mapped
     * ({@see HistorySummaryEntity}). Converted to real DQL -- single-table,
     * static WHERE (3 fixed (year, month) pairs, both bound parameters).
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
            ->createQueryBuilder()
            ->select('hs.year', 'hs.month', 'hs.day', 'hs.hour', 'hs.nbPages AS nb_pages')
            ->from(HistorySummaryEntity::class, 'hs')
            ->where('(hs.year = :year1 AND hs.month = :month1) OR (hs.year = :year2 AND hs.month = :month2) OR (hs.year = :year3 AND hs.month = :month3)')
            ->andWhere('hs.day IS NOT NULL')
            ->andWhere('hs.hour IS NULL')
            ->orderBy('hs.year', 'DESC')
            ->addOrderBy('hs.month', 'DESC')
            ->setParameter('year1', $year1)
            ->setParameter('month1', $month1)
            ->setParameter('year2', $year2)
            ->setParameter('month2', $month2)
            ->setParameter('year3', $year3)
            ->setParameter('month3', $month3)
            ->getQuery()
            ->getArrayResult();
    }

    /**
     * Item 14 DQL audit, re-corrected: `history_summary` is now mapped
     * ({@see HistorySummaryEntity}). Converted to real DQL -- single-table,
     * static WHERE, AVG() is a standard DQL function. `ORDER BY` dropped
     * from the DQL form: a bare aggregate SELECT (no GROUP BY) always
     * collapses to a single row, so ordering the input rows before
     * aggregating can't change the one-row result -- verified this
     * matches the original's own real behavior, not just a DQL
     * limitation papered over.
     *
     * Average daily page views across the trailing 12-ish months (this
     * year, plus last year from $afterMonth onward) -- Admin\
     * StatsPageRenderer::getMonthStats()'s own "avg" figure, one real
     * caller.
     */
    public function findAverageDailyPageViewsSince(int $year, int $previousYear, int $afterMonth): ?float
    {
        $value = $this->getEntityManager()
            ->createQueryBuilder()
            ->select('AVG(hs.nbPages)')
            ->from(HistorySummaryEntity::class, 'hs')
            ->where('hs.year = :year OR (hs.year = :previousYear AND hs.month > :afterMonth)')
            ->andWhere('hs.day IS NOT NULL')
            ->andWhere('hs.hour IS NULL')
            ->setParameter('year', $year)
            ->setParameter('previousYear', $previousYear)
            ->setParameter('afterMonth', $afterMonth)
            ->getQuery()
            ->getSingleScalarResult();

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
        // Item 15 audit, re-verified: this plan's own text speculated the
        // deptrac boundary rules this out -- wrong once actually checked.
        // `History` is `L2bExtendedDomain`; `Users` is `L2aCoreDomain` --
        // a downward dependency, the same accepted precedent already used
        // by `Permission\PermissionRepository`/`UserRepository::
        // deleteUser()`'s own direct `Users\*Entity` DQL touches, not the
        // `deleteSiteRow`-class real violation. Converted to real DQL.
        //
        // Env::now() rather than DQL's CURRENT_TIMESTAMP() -- matches
        // SessionRepository/CommentRepository's own established reasoning
        // (invisible to PIWIGO_TEST_NOW). `ui.lastmodified = ui.lastmodified`
        // is a deliberate self-assignment (see Auth\AuthRepository::
        // saveLastVisitFromHistory()'s own docblock for why) -- DQL
        // supports this identically to the original DBAL form.
        $em = $this->getEntityManager();
        $em->createQuery('UPDATE ' . \Piwigo\Users\UserInfoEntity::class . ' ui SET ui.lastVisit = :now, ui.lastmodified = ui.lastmodified WHERE ui.userId = :userId')
            ->setParameter('now', Env::now()->format('Y-m-d H:i:s'))
            ->setParameter('userId', \Piwigo\Common\ValueObject\UserId::from($userId))
            ->execute();
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
