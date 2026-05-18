<?php

declare(strict_types=1);

namespace Piwigo\History;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Piwigo\Db\AbstractRepository;
use Piwigo\Db\SqlExpr;

/** Persistence layer for the history domain. */
final class HistoryRepository extends AbstractRepository
{
    /**
     * Sum of nb_pages for all history_summary rows with month IS NULL
     * (annual roll-ups), giving total site page views.
     */
    public function sumPageViews(): int
    {
        $value = $this->conn->createQueryBuilder()
            ->select('SUM(nb_pages)')
            ->from($this->table('history_summary'))
            ->where('month IS NULL')
            ->executeQuery()
            ->fetchOne();
        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * Insert a page-view log entry and return the new row's id.
     * Called by pwg_log() on every page view when history logging is enabled.
     *
     * $section, $categoryId, $searchId, $imageId, $imageType, $formatId,
     * $authKeyId, $tagsString may all be null (logged as SQL NULL).
     */
    public function insertLog(
        int $userId,
        string $ip,
        ?string $section,
        ?string $categoryId,
        ?string $searchId,
        ?int $imageId,
        ?string $imageType,
        ?string $formatId,
        ?string $authKeyId,
        ?string $tagsString
    ): int {
        $this->conn->insert($this->table('history'), [
            'date'        => new \DateTimeImmutable()->format('Y-m-d'),
            'time'        => new \DateTimeImmutable()->format('H:i:s'),
            'user_id'     => $userId,
            'IP'          => $ip,
            'section'     => $section,
            'category_id' => $categoryId,
            'search_id'   => $searchId,
            'image_id'    => $imageId,
            'image_type'  => $imageType,
            'format_id'   => $formatId,
            'auth_key_id' => $authKeyId,
            'tag_ids'     => $tagsString,
        ]);
        return (int) $this->conn->lastInsertId();
    }

    /**
     * Return the most recent (date, time) pair from history for a given user, or null.
     * Used to compute last_visit from the history table.
     *
     * @return array{date: string, time: string}|null
     */
    public function findLastVisitByUserId(int $userId): ?array
    {
        $row = $this->conn->createQueryBuilder()
            ->select('date', 'time')
            ->from($this->table('history'))
            ->where('user_id = :userId')
            ->setParameter('userId', $userId)
            ->orderBy('id', 'DESC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();
        if ($row === false) {
            return null;
        }
        return [
            'date' => is_string($row['date']) ? $row['date'] : '',
            'time' => is_string($row['time']) ? $row['time'] : '',
        ];
    }

    /** Total number of history rows (used to decide if autopurge is needed). */
    public function countAll(): int
    {
        $value = $this->conn->createQueryBuilder()
            ->select('COUNT(*)')
            ->from($this->table('history'))
            ->executeQuery()
            ->fetchOne();
        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * Delete history rows with id strictly less than $id.
     * Used by history_autopurge() to trim old log lines.
     */
    public function deleteBeforeId(int $id): void
    {
        $this->conn->createQueryBuilder()
            ->delete($this->table('history'))
            ->where('id < :id')
            ->setParameter('id', $id)
            ->executeStatement();
    }

    /**
     * Return history_summary rows filtered by granularity type.
     *
     * $type: 'hour' | 'day' | 'month' | 'year'
     *
     * @return list<array<string, mixed>>
     */
    public function findSummaryByType(string $type, int $limit): array
    {
        $qb = $this->conn->createQueryBuilder()
            ->select('year', 'month', 'day', 'hour', 'nb_pages')
            ->from($this->table('history_summary'))
            ->setMaxResults($limit);

        match ($type) {
            'hour'  => $qb->where('year IS NOT NULL')->andWhere('month IS NOT NULL')
                          ->andWhere('day IS NOT NULL')->andWhere('hour IS NOT NULL')
                          ->orderBy('year', 'DESC')->addOrderBy('month', 'DESC')
                          ->addOrderBy('day', 'DESC')->addOrderBy('hour', 'DESC'),
            'day'   => $qb->where('year IS NOT NULL')->andWhere('month IS NOT NULL')
                          ->andWhere('day IS NOT NULL')->andWhere('hour IS NULL')
                          ->orderBy('year', 'DESC')->addOrderBy('month', 'DESC')->addOrderBy('day', 'DESC'),
            'month' => $qb->where('year IS NOT NULL')->andWhere('month IS NOT NULL')
                          ->andWhere('day IS NULL')
                          ->orderBy('year', 'DESC')->addOrderBy('month', 'DESC'),
            default => $qb->where('year IS NOT NULL')->andWhere('month IS NULL')
                          ->orderBy('year', 'DESC'),
        };

        return $qb->executeQuery()->fetchAllAssociative();
    }

    /**
     * Return AVG(nb_pages) for days in the current year or the previous year
     * after the current month (rolling 12-month window).
     */
    public function findCurrentPeriodDailyAvg(int $currentYear, int $currentMonth): ?float
    {
        $value = $this->conn->executeQuery(
            'SELECT AVG(nb_pages) FROM ' . $this->table('history_summary') .
            ' WHERE (year = ? OR (year = ? AND month > ?)) AND day IS NOT NULL AND hour IS NULL',
            [$currentYear, $currentYear - 1, $currentMonth]
        )->fetchOne();
        return is_numeric($value) ? (float) $value : null;
    }

    /** Truncate the history detail table. */
    public function deleteAll(): void
    {
        $this->conn->executeStatement('DELETE FROM ' . $this->table('history'));
    }

    /** Truncate the history summary table. */
    public function deleteAllSummary(): void
    {
        $this->conn->executeStatement('DELETE FROM ' . $this->table('history_summary'));
    }

    /**
     * COUNT(*) of history rows matching the supplied WHERE fragment.
     *
     * @param list<mixed>                            $params
     * @param list<ArrayParameterType|ParameterType> $types
     */
    public function countByWhere(string $where, array $params, array $types): int
    {
        $sql   = 'SELECT COUNT(*) FROM ' . $this->table('history') . ' WHERE ' . $where;
        $value = $this->conn->executeQuery($sql, $params, $types)->fetchOne();
        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * Sum images.filesize over history rows with image_type='high' joined to
     * an existing image, restricted by the supplied WHERE fragment (which
     * already aliases columns as h.<col>).
     *
     * @param list<mixed>                            $params
     * @param list<ArrayParameterType|ParameterType> $types
     */
    public function sumHighFilesizeByWhere(string $where, array $params, array $types): int
    {
        $sql = 'SELECT COALESCE(SUM(i.filesize), 0)'
            . ' FROM ' . $this->table('history') . ' h'
            . ' INNER JOIN ' . $this->table('images') . ' i ON i.id = h.image_id'
            . " WHERE h.image_type = 'high' AND " . $where;
        $value = $this->conn->executeQuery($sql, $params, $types)->fetchOne();
        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * IP → hit-count map for one user under the supplied filter.
     *
     * @param list<mixed>                            $params
     * @param list<ArrayParameterType|ParameterType> $types
     * @return array<string, int>
     */
    public function findIpHitCountsForUser(int $userId, string $where, array $params, array $types): array
    {
        $sql = 'SELECT IP, COUNT(*) AS c FROM ' . $this->table('history')
            . ' WHERE user_id = ? AND ' . $where . ' GROUP BY IP';
        $rows = $this->conn->executeQuery(
            $sql,
            [$userId, ...$params],
            [ParameterType::INTEGER, ...$types],
        )->fetchAllAssociative();
        $out = [];
        foreach ($rows as $row) {
            $ip       = is_string($row['IP'] ?? null) ? $row['IP'] : '';
            $out[$ip] = is_numeric($row['c'] ?? null) ? (int) $row['c'] : 0;
        }
        return $out;
    }

    /**
     * user_id → hit-count map under the supplied filter.
     *
     * @param list<mixed>                            $params
     * @param list<ArrayParameterType|ParameterType> $types
     * @return array<int, int>
     */
    public function findUserHitCountsByWhere(string $where, array $params, array $types): array
    {
        $sql = 'SELECT user_id, COUNT(*) AS c FROM ' . $this->table('history')
            . ' WHERE ' . $where . ' GROUP BY user_id';
        $rows = $this->conn->executeQuery($sql, $params, $types)->fetchAllAssociative();
        $out = [];
        foreach ($rows as $row) {
            if (!is_numeric($row['user_id'] ?? null)) {
                continue;
            }
            $out[(int) $row['user_id']] = is_numeric($row['c'] ?? null) ? (int) $row['c'] : 0;
        }
        return $out;
    }

    /**
     * DISTINCT search_id values that pass the filter.
     *
     * @param list<mixed>                            $params
     * @param list<ArrayParameterType|ParameterType> $types
     * @return list<int>
     */
    public function findDistinctSearchIdsByWhere(string $where, array $params, array $types): array
    {
        $sql = 'SELECT DISTINCT search_id FROM ' . $this->table('history')
            . ' WHERE search_id IS NOT NULL AND ' . $where;
        $rows = $this->conn->executeQuery($sql, $params, $types)->fetchAllAssociative();
        $out  = [];
        foreach ($rows as $row) {
            if (is_numeric($row['search_id'] ?? null)) {
                $out[] = (int) $row['search_id'];
            }
        }
        return $out;
    }

    /**
     * Paginated detail rows (date DESC, time DESC) under the supplied filter.
     *
     * @param list<mixed>                            $params
     * @param list<ArrayParameterType|ParameterType> $types
     * @return list<array<string, mixed>>
     */
    public function findPageByWhere(string $where, array $params, array $types, int $offset, int $limit): array
    {
        $sql = 'SELECT date, time, user_id, IP, section, category_id, search_id, tag_ids, image_id, image_type'
            . ' FROM ' . $this->table('history')
            . ' WHERE ' . $where
            . ' ORDER BY date DESC, time DESC'
            . ' LIMIT ' . $limit . ' OFFSET ' . $offset;
        return $this->conn->executeQuery($sql, $params, $types)->fetchAllAssociative();
    }

    /**
     * Newest history_summary row with history_id_to set, or null when none.
     *
     * @return array<string, mixed>|null
     */
    public function findLastSummaryWithIdTo(): ?array
    {
        $row = $this->conn->createQueryBuilder()
            ->select('*')
            ->from($this->table('history_summary'))
            ->where('history_id_to IS NOT NULL')
            ->orderBy('history_id_to', 'DESC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();
        return $row !== false ? $row : null;
    }

    /** MIN(id) on history, or null when empty. */
    public function findMinId(): ?int
    {
        $value = $this->conn->executeQuery('SELECT MIN(id) FROM ' . $this->table('history'))->fetchOne();
        return is_numeric($value) ? (int) $value : null;
    }

    /** MAX(id) on history, or null when empty. */
    public function findMaxId(): ?int
    {
        $value = $this->conn->executeQuery('SELECT MAX(id) FROM ' . $this->table('history'))->fetchOne();
        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * Hourly grouping (date, hour, min_id, max_id, nb_pages) for history rows
     * with id > $idAfter, optionally capped at id <= $idAfter + $maxLines.
     *
     * @return list<array<string, mixed>>
     */
    public function findHourlyGroupingAfterId(int $idAfter, ?int $maxLines): array
    {
        $sql = '
SELECT
    date,
    ' . SqlExpr::hour('time') . ' AS hour,
    MIN(id) AS min_id,
    MAX(id) AS max_id,
    COUNT(*) AS nb_pages
  FROM ' . $this->table('history') . '
  WHERE id > ?';
        $params = [$idAfter];
        $types  = [ParameterType::INTEGER];
        if ($maxLines !== null) {
            $sql      .= ' AND id <= ?';
            $params[]  = $idAfter + $maxLines;
            $types[]   = ParameterType::INTEGER;
        }
        $sql .= ' GROUP BY date, hour ORDER BY date ASC, hour ASC';
        return $this->conn->executeQuery($sql, $params, $types)->fetchAllAssociative();
    }

    /**
     * Existing history_summary rows scoped to a single (year, month, day,
     * hour) tuple — month/day/hour are nullable and matched as "either NULL
     * or equal to the supplied value".
     *
     * @return list<array<string, mixed>>
     */
    public function findSummariesAtTime(int $year, int $month, int $day, int $hour): array
    {
        $sql = '
SELECT *
  FROM ' . $this->table('history_summary') . '
  WHERE year = ?
    AND ( month IS NULL
      OR ( month = ?
        AND ( day IS NULL
          OR ( day = ?
            AND (hour IS NULL OR hour = ?)
          )
        )
      )
    )';
        return $this->conn->executeQuery(
            $sql,
            [$year, $month, $day, $hour],
            [ParameterType::INTEGER, ParameterType::INTEGER, ParameterType::INTEGER, ParameterType::INTEGER],
        )->fetchAllAssociative();
    }

    /**
     * Apply nb_pages/history_id_to updates against history_summary keyed by
     * (year, month, day, hour). Each row is taken from findSummariesAtTime's
     * shape — month/day/hour may be null.
     *
     * @param list<array<string, mixed>> $rows
     */
    public function updateSummaryBatch(array $rows): void
    {
        if ($rows === []) {
            return;
        }
        $this->conn->transactional(function () use ($rows): void {
            foreach ($rows as $row) {
                $this->conn->update(
                    $this->table('history_summary'),
                    [
                        'nb_pages'      => $row['nb_pages'],
                        'history_id_to' => $row['history_id_to'],
                    ],
                    [
                        'year'  => $row['year'],
                        'month' => $row['month'],
                        'day'   => $row['day'],
                        'hour'  => $row['hour'],
                    ],
                );
            }
        });
    }

    /**
     * Insert new history_summary rows atomically.
     *
     * @param list<array<string, mixed>> $rows
     */
    public function insertSummaryBatch(array $rows): void
    {
        if ($rows === []) {
            return;
        }
        $this->conn->transactional(function () use ($rows): void {
            foreach ($rows as $row) {
                $this->conn->insert($this->table('history_summary'), $row);
            }
        });
    }

    /**
     * Extend the `section` ENUM on the history table to include the given
     * list of values. Used by ActivityLogger when a page-view comes in for
     * a plugin-defined section name not yet in the enum.
     *
     * @param list<string> $sections  Plugin-controlled but pre-filtered by
     *                                ActivityLogger to a strict charset; embedded
     *                                directly in DDL because DDL doesn't accept
     *                                bound parameters.
     */
    public function extendSectionEnum(array $sections): void
    {
        if ($sections === []) {
            return;
        }
        $this->conn->executeStatement(
            'ALTER TABLE ' . $this->table('history') . " CHANGE section section enum('"
            . implode("','", array_unique($sections)) . "') DEFAULT NULL"
        );
    }
}
