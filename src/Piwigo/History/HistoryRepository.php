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
     * Used by admin/stats.php to build the stats charts.
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
     * Used by admin/stats.php for the daily-average badge.
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
}
