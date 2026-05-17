<?php

declare(strict_types=1);

namespace Piwigo\Calendar;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Piwigo\Db\AbstractRepository;

/** Persistence layer for the chronology / calendar feature. */
final class CalendarRepository extends AbstractRepository
{
    /**
     * For each distinct period at the given calendar level, return how many
     * images fall into it. Used by the navigation bar generator.
     *
     * @param list<mixed>                            $params
     * @param list<ArrayParameterType|ParameterType> $types
     * @return array<int|string, int|string>
     */
    public function findNavBarPeriodImageCounts(
        string $levelExpr,
        string $innerSql,
        string $dateWhere,
        array $params,
        array $types,
    ): array {
        $query = '
SELECT DISTINCT(' . $levelExpr . ') AS period,
  COUNT(DISTINCT id) AS nb_images' . $innerSql . $dateWhere . '
  GROUP BY period';
        $rows = $this->conn->executeQuery($query, $params, $types)->fetchAllAssociative();
        $out = [];
        foreach ($rows as $row) {
            $key = is_scalar($row['period']) ? (string) $row['period'] : '';
            $out[$key] = is_numeric($row['nb_images']) ? (int) $row['nb_images'] : 0;
        }
        return $out;
    }

    /**
     * Return the distinct period strings used by next/prev navigation. The
     * caller composes the period SELECT expression (typically a CONCAT_WS
     * over the chronology levels).
     *
     * @param list<mixed>                            $params
     * @param list<ArrayParameterType|ParameterType> $types
     * @return list<string>
     */
    public function findChronologyPeriods(
        string $concatExpr,
        string $innerSql,
        string $dateField,
        array $params,
        array $types,
    ): array {
        $query = 'SELECT ' . $concatExpr . ' AS period'
            . $innerSql . '
AND ' . $dateField . ' IS NOT NULL
GROUP BY period';
        $rows = $this->conn->executeQuery($query, $params, $types)->fetchAllAssociative();
        return array_map(static fn (array $r): string => is_scalar($r['period']) ? (string) $r['period'] : '', $rows);
    }

    /**
     * Return (period, count) rows used by global/year/month calendar builders.
     * The caller composes the period SELECT expression, the ORDER BY suffix,
     * and the COUNT-distinct flag.
     *
     * @param list<mixed>                            $params
     * @param list<ArrayParameterType|ParameterType> $types
     * @return list<array{period: string, count: int}>
     */
    public function findCalendarPeriodCounts(
        string $periodExpr,
        string $innerSql,
        string $dateWhere,
        string $orderBy,
        array $params,
        array $types,
    ): array {
        $query = 'SELECT ' . $periodExpr . ' AS period,
  COUNT(DISTINCT id) AS count' . $innerSql . $dateWhere . '
  GROUP BY period
  ' . $orderBy;
        $rows = $this->conn->executeQuery($query, $params, $types)->fetchAllAssociative();
        return array_map(static fn (array $r): array => [
            'period' => is_scalar($r['period']) ? (string) $r['period'] : '',
            'count'  => is_numeric($r['count']) ? (int) $r['count'] : 0,
        ], $rows);
    }

    /**
     * Return one random image row inside the caller's calendar window.
     * Returns null when the window is empty.
     *
     * @param list<mixed>                            $params
     * @param list<ArrayParameterType|ParameterType> $types
     * @return array<string, mixed>|null
     */
    public function findRandomImageInCalendarPeriod(
        string $dowExpr,
        string $innerSql,
        string $dateWhere,
        array $params,
        array $types,
    ): ?array {
        $query = '
  SELECT id, file, representative_ext, path, width, height, rotation, ' . $dowExpr . ' - 1 AS dow'
            . $innerSql . $dateWhere . '
    ORDER BY RAND()
    LIMIT 1';
        $row = $this->conn->executeQuery($query, $params, $types)->fetchAssociative();
        return $row !== false ? $row : null;
    }

    /**
     * Return distinct image ids inside the caller's calendar window.
     * Used by CalendarService::initializeCalendar to populate the items list.
     *
     * @param list<mixed>                            $params
     * @param list<ArrayParameterType|ParameterType> $types
     * @return list<int>
     */
    public function findImageIdsForCalendar(
        string $innerSql,
        string $dateWhere,
        string $orderBy,
        array $params,
        array $types,
    ): array {
        $query = 'SELECT DISTINCT id ' . $innerSql . '
  ' . $dateWhere . '
  ' . $orderBy;
        $rows = $this->conn->executeQuery($query, $params, $types)->fetchAllAssociative();
        return array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, array_column($rows, 'id'));
    }
}
