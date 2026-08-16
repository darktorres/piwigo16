<?php

declare(strict_types=1);

namespace Piwigo\History;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Query;
use Override;
use Piwigo\Auth\LastVisitLookupInterface;
use Piwigo\Common\ValueObject\CategoryId;
use Piwigo\Common\ValueObject\ImageId;
use Piwigo\Common\ValueObject\IpAddress;
use Piwigo\Common\ValueObject\SearchId;
use Piwigo\Common\ValueObject\SqlDate;
use Piwigo\Common\ValueObject\SqlTime;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Core\Env;
use Piwigo\History\Projection\GroupedCountSince;
use Piwigo\History\Projection\HistorySearchRow;
use Piwigo\History\Projection\HistorySummaryCount;
use Piwigo\History\Projection\HistorySummaryCursor;
use Piwigo\History\Projection\HistorySummaryRow;
use Piwigo\Image\ImageEntity;
use Piwigo\Users\UserInfoEntity;

/**
 * Persistence layer for the history domain: `history` (one row per public
 * page view) and `history_summary` (year/month/day/hour rollup, keyed by a
 * NULL-inclusive unique index -- MySQL never treats two NULLs as equal in a
 * unique index, which is why summary rows that might already exist are
 * looked up explicitly (findSummaryRowsForHierarchy()) rather than upserted
 * blindly).
 *
 * Owns `history` ({@see HistoryEntity}) and `history_summary` too
 * ({@see HistorySummaryEntity}) -- every method against it goes through
 * the ORM/DQL, including findSummaryRowsForHierarchy()'s own
 * nested-conditional WHERE, a direct 1:1 port of the same fixed-shape
 * branching the original DBAL version did. A handful of other classes
 * (Admin\Maintenance\DbMaintenanceRepository, Admin\HistoryPageRenderer)
 * still touch these two tables directly via raw DBAL -- no
 * cross-repository identity-map risk from that, since neither goes
 * through the ORM/entity manager for these tables (both are
 * `L3Presentation`-layer, an allowed downward dependency regardless).
 * `Auth\AuthRepository` depends on {@see \Piwigo\Auth\LastVisitLookupInterface}
 * (implemented by this class) rather than touching these tables directly,
 * since `Auth` is `L2aCoreDomain`, which cannot depend on `History`'s own
 * `L2bExtendedDomain` layer.
 * Admin\InstallationStats/Admin\StatsPageRenderer/Ws\Core read
 * `history_summary` via this repository's own findLastByType()/
 * findMonthlyRows()/findDailyRowsForMonths()/
 * findAverageDailyPageViewsSince()/sumPageViews(); Ws\Core's own
 * activity-table listing goes to
 * {@see \Piwigo\Activity\ActivityRepository::findPaginated()} instead, a
 * different table this class doesn't own.
 *
 * @extends EntityRepository<HistoryEntity>
 */
final class HistoryRepository extends EntityRepository implements LastVisitLookupInterface
{
    /**
     * `history.section`'s original MySQL ENUM member list, at schema
     * creation time -- see {@see getSectionEnumOptions()}'s own docblock
     * for why this needs restating here for the Postgres branch.
     */
    private const array BASE_SECTIONS = [
        'categories', 'tags', 'search', 'list', 'favorites',
        'most_visited', 'best_rated', 'recent_pics', 'recent_cats',
    ];

    /**
     * Real DQL replacement for the raw DBAL read
     * {@see \Piwigo\Auth\AuthRepository::findLastVisitFromHistory()} used
     * to do directly -- `Auth` (`L2aCoreDomain`) can't depend on
     * `History` (`L2bExtendedDomain`), so `AuthRepository` now
     * constructor-injects {@see \Piwigo\Auth\LastVisitLookupInterface}
     * instead, wired to this class at the composition root. The original
     * looped over every row its query returned, but the query itself was
     * already `ORDER BY id DESC LIMIT 1` -- so at most one iteration ever
     * ran; a single-row fetch is behaviorally identical.
     */
    #[Override]
    public function findLastVisit(int $userId): ?string
    {
        $row = $this->getEntityManager()
            ->createQueryBuilder()
            ->select('h.date', 'h.time')
            ->from(HistoryEntity::class, 'h')
            ->where('h.userId = :userId')
            ->orderBy('h.id', 'DESC')
            ->setMaxResults(1)
            ->setParameter('userId', UserId::from($userId))
            ->getQuery()
            ->getOneOrNullResult(Query::HYDRATE_ARRAY);

        if (! is_array($row)) {
            return null;
        }

        $date = ($row['date'] ?? null) instanceof SqlDate ? $row['date']->value : '';
        $time = ($row['time'] ?? null) instanceof SqlTime ? $row['time']->value : '';

        return $date . ' ' . $time;
    }

    /**
     * `history_summary` is mapped
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
     * Single-table MIN() aggregate, no WHERE; `h.id` is a plain integer
     * column, no custom Doctrine Type involved.
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
     * MySQL's `HOUR(time)` has no portable DQL equivalent (only
     * ABS/CONCAT/CURRENT_DATE/CURRENT_TIME/CURRENT_TIMESTAMP/DATE_ADD/
     * DATE_DIFF/DATE_SUB/LENGTH/LOCATE/LOWER/MOD/SIZE/SQRT/SUBSTRING/TRIM/
     * UPPER/BIT_AND/BIT_OR are standard DQL functions, and this project's
     * EntityManagerFactory registers no custom DQL functions on top of
     * those), and it is also part of the `GROUP BY` key (DQL can't group
     * by a SELECT alias). Fetches `date`/`time`/`id` per row instead and
     * groups in PHP -- the real caller
     * ({@see \Piwigo\History\HistoryService::summarize()}) is a
     * chunked/cron-style batch job, not a hot request path, and already
     * supports a `$maxLines` cap for controlling batch size; scanning
     * every history row in ]$minId, $maxId] is the same trade
     * `updateSummaryRows()`/`insertSummaryRows()` make for their own
     * per-row loops. `time` is an `HH:MM:SS` string (`HistoryEntity`'s own
     * `length: 8`), so `(int) substr($time, 0, 2)` reproduces
     * `HOUR(time)`'s output exactly.
     *
     * One row per (date, hour) bucket with at least one history line in
     * ]$minId, $maxId].
     *
     * @return list<GroupedCountSince>
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

        // Accumulated in parallel mutable maps -- see
        // ImageRepository::findAddMethodBreakdown()'s own docblock for why
        // (GroupedCountSince is readonly too).
        $dateByKey = [];
        $hourByKey = [];
        $minIdByKey = [];
        $maxIdByKey = [];
        $nbPagesByKey = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $id = is_numeric($row['id'] ?? null) ? (int) $row['id'] : 0;
            $date = ($row['date'] ?? null) instanceof SqlDate ? $row['date']->value : '';
            $time = ($row['time'] ?? null) instanceof SqlTime ? $row['time']->value : '';
            $hour = (int) substr($time, 0, 2);

            $key = $date . "\0" . $hour;

            if (! isset($nbPagesByKey[$key])) {
                $dateByKey[$key] = $date;
                $hourByKey[$key] = $hour;
                $minIdByKey[$key] = $id;
                $maxIdByKey[$key] = $id;
                $nbPagesByKey[$key] = 0;
            }

            $minIdByKey[$key] = min($minIdByKey[$key], $id);
            $maxIdByKey[$key] = max($maxIdByKey[$key], $id);
            $nbPagesByKey[$key]++;
        }

        $result = [];
        foreach ($nbPagesByKey as $key => $nbPages) {
            $result[] = new GroupedCountSince($dateByKey[$key], $hourByKey[$key], $minIdByKey[$key], $maxIdByKey[$key], $nbPages);
        }

        usort(
            $result,
            static fn (GroupedCountSince $a, GroupedCountSince $b): int => [$a->date, $a->hour] <=> [$b->date, $b->hour]
        );

        return $result;
    }

    /**
     * `history_summary` is mapped
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
     * `history_summary` is mapped
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
     * `history_summary` is mapped
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
     * Single-table COUNT() aggregate, no WHERE.
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
     * `history_summary` is mapped
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
     * `history_summary` is mapped
     * ({@see HistorySummaryEntity}). Converted to real DQL -- single-table;
     * `$type`'s match() only ever selects one of 4 fixed WHERE/ORDER BY
     * shapes, not a caller-supplied dynamic fragment.
     *
     * The last $limit summary rows at the given hierarchy level ($type:
     * 'hour'/'day'/'month', or year for anything else), most recent
     * first -- Admin\StatsPageRenderer's own chart-data query, one real
     * caller, page-specific view-shaping (not a general-purpose finder).
     *
     * @return list<HistorySummaryRow>
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

        $result = [];
        foreach ($qb->getQuery()->getArrayResult() as $row) {
            if (is_array($row)) {
                $result[] = HistorySummaryRow::fromRow(array_filter($row, is_string(...), ARRAY_FILTER_USE_KEY));
            }
        }

        return $result;
    }

    /**
     * `history_summary` is mapped
     * ({@see HistorySummaryEntity}). Converted to real DQL -- single-table,
     * static WHERE.
     *
     * Every month-level summary row, most recent first, optionally capped
     * at $limit -- Admin\StatsPageRenderer's own "compare years" chart
     * data, one real caller.
     *
     * @return list<HistorySummaryRow>
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

        $result = [];
        foreach ($qb->getQuery()->getArrayResult() as $row) {
            if (is_array($row)) {
                $result[] = HistorySummaryRow::fromRow(array_filter($row, is_string(...), ARRAY_FILTER_USE_KEY));
            }
        }

        return $result;
    }

    /**
     * `history_summary` is mapped
     * ({@see HistorySummaryEntity}). Converted to real DQL -- single-table,
     * static WHERE (3 fixed (year, month) pairs, both bound parameters).
     *
     * Day-level summary rows for 3 specific (year, month) pairs (this
     * month, last month, this month last year) -- Admin\
     * StatsPageRenderer::getMonthStats()'s own "recent months" chart data,
     * one real caller.
     *
     * @return list<HistorySummaryRow>
     */
    public function findDailyRowsForMonths(int $year1, int $month1, int $year2, int $month2, int $year3, int $month3): array
    {
        $rows = $this->getEntityManager()
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

        $result = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $result[] = HistorySummaryRow::fromRow(array_filter($row, is_string(...), ARRAY_FILTER_USE_KEY));
            }
        }

        return $result;
    }

    /**
     * `history_summary` is mapped
     * ({@see HistorySummaryEntity}). Converted to real DQL -- single-table,
     * static WHERE, AVG() is a standard DQL function. `ORDER BY` dropped
     * from the DQL form: a bare aggregate SELECT (no GROUP BY) always
     * collapses to a single row, so ordering the input rows before
     * aggregating can't change the one-row result -- this matches the
     * original's own real behavior, not just a DQL limitation papered
     * over.
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
     * Single-table ORDER BY + LIMIT 1; getSingleColumnResult() +
     * `$ids[0] ?? null` used instead of getOneOrNullResult()
     * (getOneOrNullResult() throws NonUniqueResultException on more than
     * one row, which setMaxResults(1) here rules out, but the array-index
     * form matches "no rows -> null" fetchOne() semantics with no
     * exception path at all).
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
     * Same shape as findLatestHistoryId() above, ASC instead of DESC.
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
     * Single-table (`images`, mapped by {@see ImageEntity}, a different
     * repository's own entity but freely queryable via the shared
     * EntityManager), static WHERE, no join/aggregate DQL can't express;
     * `id`/`file` are plain integer/string columns, no custom Doctrine
     * Type involved.
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
     * Single-table (`history`), every filter a WHERE/LIKE/IN DQL can
     * express; the dynamic $imageTypes OR-clause is built as a plain
     * bound-placeholder string (not a loop-built Orx composite) to
     * sidestep the phpstan-doctrine static-analysis false positive on
     * loop-built composites -- same "raw string + real bound params"
     * convention already used everywhere else in this codebase.
     * `userId`/`categoryId`/`imageId`/`ip`/`date`/`time` all route through
     * custom Doctrine Types, so the row mapper below unwraps each via
     * `instanceof` (Gotcha #1 -- `getArrayResult()` DOES apply a
     * column's Type during hydration).
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
     * @return list<HistorySearchRow>
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
                ->setParameter('userId', UserId::from($userId));
        }

        if ($imageId !== null) {
            $qb->andWhere('h.imageId = :imageId')
                ->setParameter('imageId', ImageId::from($imageId));
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

            $results[] = new HistorySearchRow(
                date: ($row['date'] ?? null) instanceof SqlDate ? $row['date']->value : null,
                time: ($row['time'] ?? null) instanceof SqlTime ? $row['time']->value : '',
                userId: ($row['userId'] ?? null) instanceof UserId ? $row['userId']->value : (is_numeric($row['userId'] ?? null) ? (int) $row['userId'] : 0),
                ip: ($row['ip'] ?? null) instanceof IpAddress ? $row['ip']->value : '',
                section: is_string($row['section'] ?? null) ? $row['section'] : null,
                categoryId: ($row['categoryId'] ?? null) instanceof CategoryId ? $row['categoryId']->value : (is_numeric($row['categoryId'] ?? null) ? (int) $row['categoryId'] : null),
                searchId: ($row['searchId'] ?? null) instanceof SearchId ? $row['searchId']->value : (is_numeric($row['searchId'] ?? null) ? (int) $row['searchId'] : null),
                tagIds: is_string($row['tagIds'] ?? null) ? $row['tagIds'] : null,
                imageId: ($row['imageId'] ?? null) instanceof ImageId ? $row['imageId']->value : (is_numeric($row['imageId'] ?? null) ? (int) $row['imageId'] : null),
                imageType: ($row['imageType'] ?? null) instanceof HistoryImageType ? $row['imageType']->value : null,
            );
        }

        return $results;
    }

    public function updateLastVisitNow(int $userId): void
    {
        // `History` is `L2bExtendedDomain`; `Users` is `L2aCoreDomain` --
        // a downward dependency, the same accepted precedent already used
        // by `Permission\PermissionRepository`/`UserRepository::
        // deleteUser()`'s own direct `Users\*Entity` DQL touches, not the
        // `deleteSiteRow`-class real violation.
        //
        // Env::now() rather than DQL's CURRENT_TIMESTAMP() -- matches
        // SessionRepository/CommentRepository's own established reasoning
        // (invisible to PIWIGO_TEST_NOW).
        $em = $this->getEntityManager();
        $em->createQuery('UPDATE ' . UserInfoEntity::class . ' ui SET ui.lastVisit = :now WHERE ui.userId = :userId')
            ->setParameter('now', Env::now()->format('Y-m-d H:i:s'))
            ->setParameter('userId', UserId::from($userId))
            ->execute();
        $em->clear();
    }

    /**
     * The section names currently in use, for resolving a page section to
     * its canonical casing.
     *
     * Stays on DBAL: `SELECT DISTINCT` over one column, no entity to
     * hydrate.
     *
     * `history.section` is a plain `VARCHAR` on both platforms, so there is
     * no schema-level "currently allowed values" construct to read. The set
     * is derived from the data instead, which is self-healing: once a row
     * using a given section exists, later reads recognise it. That used to
     * be the PostgreSQL-only branch, with MySQL parsing its live `ENUM`
     * definition out of `DESC history` -- the ENUM is gone, so both
     * platforms now share this one implementation.
     *
     * A genuinely cold table (a fresh install, or an isolated test fixture)
     * has nothing for `SELECT DISTINCT` to find, so even the built-in
     * sections would read back as unknown on first use. {@see BASE_SECTIONS}
     * -- the member list the `section` column was originally created with --
     * is unioned in so they are always recognised; plugin-defined sections
     * rely on the DISTINCT-from-data lookup.
     *
     * @return list<string>
     */
    public function getSectionEnumOptions(): array
    {
        $sections = $this->getEntityManager()
            ->getConnection()
            ->executeQuery(<<<SQL
                SELECT DISTINCT section FROM history WHERE section IS NOT NULL
                SQL)->fetchFirstColumn();

        return array_values(array_unique([
            ...self::BASE_SECTIONS,
            ...array_filter($sections, is_string(...)),
        ]));
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
            date: SqlDate::from($now->format('Y-m-d')),
            time: SqlTime::from($now->format('H:i:s')),
            userId: UserId::from($data['userId']),
            ip: IpAddress::tryFrom($data['ip']),
            section: $data['section'],
            categoryId: $data['categoryId'] === null ? null : CategoryId::from($data['categoryId']),
            searchId: $data['searchId'] === null ? null : SearchId::from($data['searchId']),
            tagIds: $data['tagsString'],
            imageId: $data['imageId'] === null ? null : ImageId::from($data['imageId']),
            imageType: $data['imageType'] !== null ? HistoryImageType::tryFrom($data['imageType']) : null,
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
