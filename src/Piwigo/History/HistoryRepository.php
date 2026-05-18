<?php

declare(strict_types=1);

namespace Piwigo\History;

use Piwigo\Db\AbstractRepository;

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
