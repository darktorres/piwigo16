<?php

declare(strict_types=1);

namespace Piwigo\History;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityRepository;
use Piwigo\Core\Env;
use Piwigo\Db\Tables;
use Piwigo\History\Projection\HistorySummaryCount;
use Piwigo\History\Projection\HistorySummaryCursor;

/**
 * Persistence layer for the history domain: `history` (one row per public
 * page view) and `history_summary` (year/month/day/hour rollup, keyed by a
 * NULL-inclusive unique index -- MySQL never treats two NULLs as equal in a
 * unique index, which is why summary rows that might already exist are
 * looked up explicitly (findSummaryRowsForHierarchy()) rather than upserted
 * blindly).
 *
 * Owns `history` ({@see HistoryEntity}) -- only insert()/deleteBefore() go
 * through it; every other method (including every `history_summary` touch)
 * stays plain DBAL via $this->getEntityManager()->getConnection(), same
 * "mixed repository" shape Image/Category/Rate's own conversions
 * established. A handful of other classes (AuthRepository,
 * Admin\Maintenance\DbMaintenanceRepository, Admin\HistoryPageRenderer)
 * also touch these two tables directly via raw DBAL -- no cross-repository
 * identity-map risk from leaving the rest raw here either, since none of
 * those go through the ORM/entity manager for these tables.
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

    public function findMinHistoryId(): ?int
    {
        $value = $this->getEntityManager()
            ->getConnection()
            ->createQueryBuilder()
            ->select('MIN(id)')
            ->from(Tables::history())
            ->executeQuery()
            ->fetchOne();

        return is_numeric($value) ? (int) $value : null;
    }

    /**
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

    public function countAll(): int
    {
        $value = $this->getEntityManager()
            ->getConnection()
            ->createQueryBuilder()
            ->select('COUNT(*)')
            ->from(Tables::history())
            ->executeQuery()
            ->fetchOne();

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * Total page views across every yearly summary row (`month IS NULL`
     * is `summarize()`'s own "whole year" rollup row, distinct from its
     * per-month/day/hour rows) -- Admin\InstallationStats's own
     * "nb_views" summary figure.
     */
    public function sumPageViews(): int
    {
        $value = $this->getEntityManager()
            ->getConnection()
            ->fetchOne('
SELECT
    SUM(nb_pages)
  FROM ' . Tables::historySummary() . '
  WHERE month IS NULL
;');

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * The last $limit summary rows at the given hierarchy level ($type:
     * 'hour'/'day'/'month', or year for anything else), most recent
     * first -- Admin\StatsPageRenderer's own chart-data query, one real
     * caller, page-specific view-shaping (not a general-purpose finder).
     *
     * @return list<array{year: int|string, month: int|string|null, day: int|string|null, hour: int|string|null, nb_pages: int|string|null}>
     */
    public function findLastByType(string $type, int $limit): array
    {
        $sql = '
SELECT
    year,
    month,
    day,
    hour,
    nb_pages
  FROM ' . Tables::historySummary();

        $sql .= match ($type) {
            'hour' => '
  WHERE year IS NOT NULL
    AND month IS NOT NULL
    AND day IS NOT NULL
    AND hour IS NOT NULL
  ORDER BY
    year DESC,
    month DESC,
    day DESC,
    hour DESC
  LIMIT ' . $limit . '
;',
            'day' => '
  WHERE year IS NOT NULL
    AND month IS NOT NULL
    AND day IS NOT NULL
    AND hour IS NULL
  ORDER BY
    year DESC,
    month DESC,
    day DESC
  LIMIT ' . $limit . '
;',
            'month' => '
  WHERE year IS NOT NULL
    AND month IS NOT NULL
    AND day IS NULL
  ORDER BY
    year DESC,
    month DESC
  LIMIT ' . $limit . '
;',
            default => '
  WHERE year IS NOT NULL
    AND month IS NULL
  ORDER BY
    year DESC
  LIMIT ' . $limit . '
;',
        };

        /** @var list<array{year: int|string, month: int|string|null, day: int|string|null, hour: int|string|null, nb_pages: int|string|null}> */
        return $this->getEntityManager()
            ->getConnection()
            ->fetchAllAssociative($sql);
    }

    /**
     * Every month-level summary row, most recent first, optionally capped
     * at $limit -- Admin\StatsPageRenderer's own "compare years" chart
     * data, one real caller.
     *
     * @return list<array{year: int|string, month: int|string|null, day: int|string|null, hour: int|string|null, nb_pages: int|string|null}>
     */
    public function findMonthlyRows(?int $limit): array
    {
        $sql = '
SELECT
  year,
  month,
  day,
  hour,
  nb_pages
FROM ' . Tables::historySummary() . '
WHERE month IS NOT NULL
  AND day IS NULL
ORDER BY
  year DESC,
  month DESC';

        if ($limit !== null) {
            $sql .= ' LIMIT ' . $limit;
        }

        $sql .= ';';

        /** @var list<array{year: int|string, month: int|string|null, day: int|string|null, hour: int|string|null, nb_pages: int|string|null}> */
        return $this->getEntityManager()
            ->getConnection()
            ->fetchAllAssociative($sql);
    }

    /**
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
            ->fetchAllAssociative('
SELECT
  year,
  month,
  day,
  hour,
  nb_pages
FROM ' . Tables::historySummary() . '
WHERE
  (
    (year = ' . $year1 . ' AND month = ' . $month1 . ')
    OR (year = ' . $year2 . ' AND month = ' . $month2 . ')
    OR (year = ' . $year3 . ' AND month = ' . $month3 . ')
  )
  AND day IS NOT NULL
  AND hour IS NULL
ORDER BY
  year DESC,
  month DESC
;');
    }

    /**
     * Average daily page views across the trailing 12-ish months (this
     * year, plus last year from $afterMonth onward) -- Admin\
     * StatsPageRenderer::getMonthStats()'s own "avg" figure, one real
     * caller.
     */
    public function findAverageDailyPageViewsSince(int $year, int $previousYear, int $afterMonth): ?float
    {
        $value = $this->getEntityManager()
            ->getConnection()
            ->fetchOne('
SELECT
  AVG(nb_pages)
FROM ' . Tables::historySummary() . '
WHERE
  (
  year = ' . $year . ' OR
  (year = ' . $previousYear . ' and month > ' . $afterMonth . ')
  )
  AND day IS NOT NULL
  AND hour IS NULL
ORDER BY
  year DESC,
  month DESC
;');

        return is_numeric($value) ? (float) $value : null;
    }

    public function findLatestHistoryId(): ?int
    {
        $value = $this->getEntityManager()
            ->getConnection()
            ->createQueryBuilder()
            ->select('id')
            ->from(Tables::history())
            ->orderBy('id', 'DESC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();

        return is_numeric($value) ? (int) $value : null;
    }

    public function findOldestHistoryId(): ?int
    {
        $value = $this->getEntityManager()
            ->getConnection()
            ->createQueryBuilder()
            ->select('id')
            ->from(Tables::history())
            ->orderBy('id', 'ASC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();

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
     * @return list<int>
     */
    public function findImageIdsByFilename(string $filenamePattern): array
    {
        $ids = $this->getEntityManager()
            ->getConnection()
            ->createQueryBuilder()
            ->select('id')
            ->from(Tables::images())
            ->where('file LIKE :pattern')
            ->setParameter('pattern', $filenamePattern)
            ->executeQuery()
            ->fetchFirstColumn();

        return array_map(
            static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0,
            $ids
        );
    }

    /**
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
        $qb = $this->getEntityManager()
            ->getConnection()
            ->createQueryBuilder()
            ->select('date', 'time', 'user_id', 'IP', 'section', 'category_id', 'search_id', 'tag_ids', 'image_id', 'image_type')
            ->from(Tables::history());

        if ($dateAfter !== null) {
            $qb->andWhere('date >= :dateAfter')
                ->setParameter('dateAfter', $dateAfter);
        }

        if ($dateBefore !== null) {
            $qb->andWhere('date <= :dateBefore')
                ->setParameter('dateBefore', $dateBefore);
        }

        if ($imageTypes !== null) {
            $typeClauses = [];
            foreach ($types as $i => $type) {
                if (! in_array($type, $imageTypes, true)) {
                    continue;
                }

                if ($type === 'none') {
                    $typeClauses[] = 'image_type IS NULL';
                } else {
                    $paramName = 'type' . $i;
                    $typeClauses[] = 'image_type = :' . $paramName;
                    $qb->setParameter($paramName, $type);
                }
            }

            if ($typeClauses !== []) {
                $qb->andWhere('(' . implode(' OR ', $typeClauses) . ')');
            }
        }

        if ($userId !== null) {
            $qb->andWhere('user_id = :userId')
                ->setParameter('userId', $userId);
        }

        if ($imageId !== null) {
            $qb->andWhere('image_id = :imageId')
                ->setParameter('imageId', $imageId);
        }

        if ($imageIdsFromFilename !== null) {
            if ($imageIdsFromFilename === []) {
                // a filename filter was given but matched no image: always false
                $qb->andWhere('1 = 2');
            } else {
                $qb->andWhere('image_id IN (:imageIds)')
                    ->setParameter('imageIds', $imageIdsFromFilename, ArrayParameterType::INTEGER);
            }
        }

        if ($ip !== null) {
            $qb->andWhere('IP LIKE :ip')
                ->setParameter('ip', $ip);
        }

        $rows = $qb->executeQuery()
            ->fetchAllAssociative();

        return array_map(
            static fn (array $row): array => [
                'date' => is_string($row['date'] ?? null) ? $row['date'] : null,
                'time' => is_string($row['time']) ? $row['time'] : '',
                'user_id' => is_numeric($row['user_id']) ? (int) $row['user_id'] : 0,
                'IP' => is_string($row['IP']) ? $row['IP'] : '',
                'section' => is_string($row['section'] ?? null) ? $row['section'] : null,
                'category_id' => is_numeric($row['category_id'] ?? null) ? (int) $row['category_id'] : null,
                'search_id' => is_numeric($row['search_id'] ?? null) ? (int) $row['search_id'] : null,
                'tag_ids' => is_string($row['tag_ids'] ?? null) ? $row['tag_ids'] : null,
                'image_id' => is_numeric($row['image_id'] ?? null) ? (int) $row['image_id'] : null,
                'image_type' => is_string($row['image_type'] ?? null) ? $row['image_type'] : null,
            ],
            $rows
        );
    }

    public function updateLastVisitNow(int $userId): void
    {
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
        $rows = $this->getEntityManager()
            ->getConnection()
            ->executeQuery('DESC ' . Tables::history())->fetchAllAssociative();

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
        $enumList = implode(',', array_map(static fn (string $option): string => "'" . $option . "'", array_unique($options)));

        $this->getEntityManager()
            ->getConnection()
            ->executeStatement(
                'ALTER TABLE ' . Tables::history() . ' CHANGE section section enum(' . $enumList . ') DEFAULT NULL'
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
