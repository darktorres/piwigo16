<?php

declare(strict_types=1);

namespace Piwigo\Admin\History;

use Doctrine\DBAL\Connection;
use Piwigo\Admin\Tabsheet;
use Piwigo\Config\Config;
use Piwigo\Core\LoggerRegistry;
use Piwigo\Core\StringUtil;
use Piwigo\Db\Dml;
use Piwigo\Db\SqlExpr;
use Piwigo\Db\Tables;
use Piwigo\History\HistoryRepository;

final readonly class HistoryAdminService
{
    public function __construct(
        private Connection $conn,
        private HistoryRepository $historyRepository,
    ) {
    }

    public function historyTabsheet(string $currentPage): void
    {
        $tabsheet = new Tabsheet();
        $tabsheet->setId('history');
        $tabsheet->select($currentPage);
        $tabsheet->assign();
    }

    /**
     * Resolves filename-pattern matches into concrete image_ids and
     * applies the documented image_id-wins-over-filename precedence.
     * Idempotent: callers can chain this once per request and reuse the
     * prepared array across the aggregate methods below.
     *
     * @param array<mixed> $search
     * @return array<mixed>
     */
    public function prepareSearch(array $search): array
    {
        /** @var array<string, mixed> $fields */
        $fields = is_array($search['fields'] ?? null) ? $search['fields'] : [];

        if (isset($fields['image_id'])) {
            unset($fields['filename']);
        }

        if (isset($fields['filename'])) {
            $query = '
SELECT
    id
  FROM ' . Tables::images() . '
  WHERE file LIKE ' . $this->conn->quote(is_string($fields['filename']) ? $fields['filename'] : '') . '
;';
            $search['image_ids'] = array_column($this->conn->executeQuery($query)->fetchAllAssociative(), 'id');
        }

        $search['fields'] = $fields;
        return $search;
    }

    /**
     * Returns the WHERE fragment for a `history` query (joined with
     * `AND`, each clause already wrapped in parens). When `$alias` is
     * non-empty, columns are emitted as `<alias>.<col>` so the fragment
     * works inside a JOIN (see getHistoryTotalFilesizeForHigh).
     *
     * @param array<mixed> $search must already be passed through prepareSearch()
     * @param string[]|string $types image_type enum values to keep
     */
    private function buildHistoryWhereSql(array $search, array|string $types, string $alias = ''): string
    {
        if (!is_array($types)) {
            $types = [$types];
        }
        /** @var array<string, mixed> $fields */
        $fields = is_array($search['fields'] ?? null) ? $search['fields'] : [];
        $p = $alias === '' ? '' : $alias . '.';

        $clauses = [];

        if (isset($fields['date-after'])) {
            $clauses[] = "{$p}date >= '" . (is_string($fields['date-after']) ? $fields['date-after'] : '') . "'";
        }

        if (isset($fields['date-before'])) {
            $clauses[] = "{$p}date <= '" . (is_string($fields['date-before']) ? $fields['date-before'] : '') . "'";
        }

        if (isset($fields['types'])) {
            $local_clauses = [];
            $types_field = is_array($fields['types']) ? $fields['types'] : [];

            foreach ($types as $type) {
                if (in_array($type, $types_field)) {
                    $clause = "{$p}image_type ";
                    if ($type == 'none') {
                        $clause .= 'IS NULL';
                    } else {
                        $clause .= "= '" . $type . "'";
                    }
                    $local_clauses[] = $clause;
                }
            }

            if (count($local_clauses) > 0) {
                $clauses[] = implode(' OR ', $local_clauses);
            }
        }

        if (isset($fields['user']) && $fields['user'] != -1) {
            $clauses[] = "{$p}user_id = " . (is_scalar($fields['user']) ? (string) $fields['user'] : '0');
        }

        if (isset($fields['image_id'])) {
            $clauses[] = "{$p}image_id = " . (is_scalar($fields['image_id']) ? (string) $fields['image_id'] : '0');
        }

        if (isset($fields['filename'])) {
            $image_ids = is_array($search['image_ids'] ?? null) ? $search['image_ids'] : [];
            if (count($image_ids) == 0) {
                $clauses[] = '1 = 2 ';
            } else {
                $clauses[] = "{$p}image_id IN (" . implode(', ', array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '0', $image_ids)) . ')';
            }
        }

        if (isset($fields['ip'])) {
            $clauses[] = "{$p}IP LIKE " . $this->conn->quote(is_string($fields['ip']) ? $fields['ip'] : '');
        }

        $clauses = StringUtil::prependAppendArrayItems($clauses, '(', ')');
        return implode("\n    AND ", $clauses);
    }

    /**
     * @param array<mixed> $search prepared search array
     * @param string[]|string $types image_type enum values to keep
     */
    public function getHistoryCount(array $search, array|string $types): int
    {
        $where = $this->buildHistoryWhereSql($search, $types);
        $sql   = 'SELECT COUNT(*) AS c FROM ' . Tables::history() . ' WHERE ' . $where . ';';
        $row   = $this->conn->executeQuery($sql)->fetchAssociative();
        return is_array($row) && is_numeric($row['c'] ?? null) ? (int) $row['c'] : 0;
    }

    /**
     * Sum of `images.filesize` (KiB) over filtered history rows whose
     * image_type='high' and whose image_id still resolves to an existing
     * image — the inner join replicates the original PHP behavior of
     * skipping deleted images.
     *
     * @param array<mixed> $search prepared search array
     * @param string[]|string $types image_type enum values to keep
     */
    public function getHistoryTotalFilesizeForHigh(array $search, array|string $types): int
    {
        $where = $this->buildHistoryWhereSql($search, $types, 'h');
        $sql   = 'SELECT COALESCE(SUM(i.filesize), 0) AS s'
               . ' FROM ' . Tables::history() . ' h'
               . ' INNER JOIN ' . Tables::images() . ' i ON i.id = h.image_id'
               . " WHERE h.image_type = 'high' AND " . $where . ';';
        $row   = $this->conn->executeQuery($sql)->fetchAssociative();
        return is_array($row) && is_numeric($row['s'] ?? null) ? (int) $row['s'] : 0;
    }

    /**
     * IP → hit-count map for guest rows in the filtered set.
     *
     * @param array<mixed> $search prepared search array
     * @param string[]|string $types image_type enum values to keep
     * @return array<string, int>
     */
    public function getHistoryGuestIpHistogram(array $search, array|string $types, int $guestId): array
    {
        $where = $this->buildHistoryWhereSql($search, $types);
        $sql   = 'SELECT IP, COUNT(*) AS c FROM ' . Tables::history()
               . ' WHERE user_id = ' . $guestId . ' AND ' . $where
               . ' GROUP BY IP;';
        $out = [];
        foreach ($this->conn->executeQuery($sql)->fetchAllAssociative() as $row) {
            $ip  = is_string($row['IP'] ?? null) ? $row['IP'] : '';
            $out[$ip] = is_numeric($row['c'] ?? null) ? (int) $row['c'] : 0;
        }
        return $out;
    }

    /**
     * user_id → hit-count map across the filtered set (includes guest;
     * caller decides whether to filter it out).
     *
     * @param array<mixed> $search prepared search array
     * @param string[]|string $types image_type enum values to keep
     * @return array<int, int>
     */
    public function getHistoryUserHitCounts(array $search, array|string $types): array
    {
        $where = $this->buildHistoryWhereSql($search, $types);
        $sql   = 'SELECT user_id, COUNT(*) AS c FROM ' . Tables::history()
               . ' WHERE ' . $where
               . ' GROUP BY user_id;';
        $out = [];
        foreach ($this->conn->executeQuery($sql)->fetchAllAssociative() as $row) {
            if (!is_numeric($row['user_id'] ?? null)) {
                continue;
            }
            $out[(int) $row['user_id']] = is_numeric($row['c'] ?? null) ? (int) $row['c'] : 0;
        }
        return $out;
    }

    /**
     * @param array<mixed> $search prepared search array
     * @param string[]|string $types image_type enum values to keep
     * @return list<int>
     */
    public function getHistoryDistinctSearchIds(array $search, array|string $types): array
    {
        $where = $this->buildHistoryWhereSql($search, $types);
        $sql   = 'SELECT DISTINCT search_id FROM ' . Tables::history()
               . ' WHERE search_id IS NOT NULL AND ' . $where . ';';
        $out = [];
        foreach ($this->conn->executeQuery($sql)->fetchAllAssociative() as $row) {
            if (is_numeric($row['search_id'] ?? null)) {
                $out[] = (int) $row['search_id'];
            }
        }
        return $out;
    }

    /**
     * Paginated detail rows, newest first.
     *
     * @param array<mixed> $search prepared search array
     * @param string[]|string $types image_type enum values to keep
     * @return array<array<string, mixed>>
     */
    public function getHistoryPage(array $search, array|string $types, int $offset, int $limit): array
    {
        $where = $this->buildHistoryWhereSql($search, $types);
        $sql   = '
SELECT
    date,
    time,
    user_id,
    IP,
    section,
    category_id,
    search_id,
    tag_ids,
    image_id,
    image_type
  FROM ' . Tables::history() . '
  WHERE ' . $where . '
  ORDER BY date DESC, time DESC
  LIMIT ' . $limit . ' OFFSET ' . $offset . '
;';
        return $this->conn->executeQuery($sql)->fetchAllAssociative();
    }

    public function historySummarize(?int $max_lines = null): void
    {
        $query = '
SELECT
    *
  FROM ' . Tables::historySummary() . '
  WHERE history_id_to IS NOT NULL
  ORDER BY history_id_to DESC
  LIMIT 1
;';
        $summary_lines = $this->conn->executeQuery($query)->fetchAllAssociative();

        $history_min_id = 0;
        if (count($summary_lines) > 0) {
            $last_summary = $summary_lines[0];
            $history_min_id = is_numeric($last_summary['history_id_to'] ?? null) ? (int) $last_summary['history_id_to'] : 0;
        } else {
            $query = '
SELECT
    MIN(id) AS min_id
  FROM ' . Tables::history() . '
;';
            $history_lines = $this->conn->executeQuery($query)->fetchAllAssociative();
            if (count($history_lines) > 0) {
                $history_min_id = (is_numeric($history_lines[0]['min_id']) ? (int) $history_lines[0]['min_id'] : 0) - 1;
            }
        }

        $query = '
SELECT
    date,
    ' . SqlExpr::hour('time') . ' AS hour,
    MIN(id) AS min_id,
    MAX(id) AS max_id,
    COUNT(*) AS nb_pages
  FROM ' . Tables::history() . '
  WHERE id > ' . $history_min_id;

        if (isset($max_lines)) {
            $query .= '
    AND id <= ' . ($history_min_id + $max_lines);
        }

        $query .= '
  GROUP BY
    date,
    hour
  ORDER BY
    date ASC,
    hour ASC
;';
        $historyRows = $this->conn
            ->executeQuery($query)->fetchAllAssociative();

        $need_update = [];
        $is_first = true;
        $first_time_key = null;

        foreach ($historyRows as $row) {
            $row_date = is_string($row['date'] ?? null) ? $row['date'] : '';
            $row_hour = is_numeric($row['hour']) ? (int) $row['hour'] : 0;
            $time_keys = [
                substr($row_date, 0, 4),
                substr($row_date, 0, 7),
                substr($row_date, 0, 10),
                sprintf('%s-%02u', $row_date, $row_hour),
            ];

            foreach ($time_keys as $time_key) {
                if (!isset($need_update[$time_key])) {
                    $need_update[$time_key] = [
                        'nb_pages' => 0,
                        'history_id_from' => $row['min_id'],
                        'history_id_to' => $row['max_id'],
                    ];
                }
                $need_update[$time_key]['nb_pages'] += is_numeric($row['nb_pages']) ? (int) $row['nb_pages'] : 0;

                if ($row['min_id'] < $need_update[$time_key]['history_id_from']) {
                    $need_update[$time_key]['history_id_from'] = $row['min_id'];
                }
                if ($row['max_id'] > $need_update[$time_key]['history_id_to']) {
                    $need_update[$time_key]['history_id_to'] = $row['max_id'];
                }
            }

            if ($is_first) {
                $is_first = false;
                $first_time_key = $time_keys[3];
            }
        }

        $updates = [];
        $inserts = [];

        if (isset($first_time_key)) {
            [$year, $month, $day, $hour] = explode('-', $first_time_key);

            $query = '
SELECT *
  FROM ' . Tables::historySummary() . '
  WHERE year=' . $year . '
    AND ( month IS NULL
      OR ( month=' . $month . '
        AND ( day is NULL
          OR (day=' . $day . '
            AND (hour IS NULL OR hour=' . $hour . ')
          )
        )
      )
    )
;';
            foreach ($this->conn
                ->executeQuery($query)->fetchAllAssociative() as $row) {
                $key = sprintf('%4u', is_numeric($row['year']) ? (int) $row['year'] : 0);
                if (isset($row['month'])) {
                    $key .= sprintf('-%02u', is_numeric($row['month']) ? (int) $row['month'] : 0);
                    if (isset($row['day'])) {
                        $key .= sprintf('-%02u', is_numeric($row['day']) ? (int) $row['day'] : 0);
                        if (isset($row['hour'])) {
                            $key .= sprintf('-%02u', is_numeric($row['hour']) ? (int) $row['hour'] : 0);
                        }
                    }
                }

                if (isset($need_update[$key])) {
                    $row['nb_pages'] = (is_numeric($row['nb_pages']) ? (int) $row['nb_pages'] : 0) + $need_update[$key]['nb_pages'];
                    $row['history_id_to'] = $need_update[$key]['history_id_to'];
                    $updates[] = $row;
                    unset($need_update[$key]);
                }
            }
        }

        foreach ($need_update as $time_key => $summary) {
            // PHP coerces numeric-string array keys to int (e.g. '2026' → 2026),
            // so the key can be int at runtime even though the docblock types
            // it as string. explode() requires a string in PHP 8.
            /** @psalm-suppress RedundantCastGivenDocblockType */
            $time_tokens = explode('-', (string) $time_key);
            $inserts[] = [
                'year'     => $time_tokens[0],
                'month'    => $time_tokens[1] ?? null,
                'day'      => $time_tokens[2] ?? null,
                'hour'     => $time_tokens[3] ?? null,
                'nb_pages' => $summary['nb_pages'],
                'history_id_from' => $summary['history_id_from'],
                'history_id_to'   => $summary['history_id_to'],
            ];
        }

        if (count($updates) > 0) {
            Dml::massUpdates(
                Tables::historySummary(),
                ['primary' => ['year', 'month', 'day', 'hour'], 'update' => ['nb_pages', 'history_id_to']],
                $updates
            );
        }

        if (count($inserts) > 0) {
            $this->conn->transactional(function () use ($inserts): void {
                foreach ($inserts as $row) {
                    $this->conn->insert(Tables::historySummary(), $row);
                }
            });
        }
    }

    public function historyAutopurge(): void
    {
        $logger = LoggerRegistry::current();

        if (0 == Config::historyAutopurgeKeepLines()) {
            return;
        }

        $histRepo = $this->historyRepository;
        $count = $histRepo->countAll();

        if ($count <= Config::historyAutopurgeKeepLines()) {
            return;
        }

        $query = '
SELECT
    *
  FROM ' . Tables::historySummary() . '
  WHERE history_id_to IS NOT NULL
  ORDER BY history_id_to DESC
  LIMIT 1
;';
        $summary_lines = $this->conn->executeQuery($query)->fetchAllAssociative();
        if (count($summary_lines) == 0) {
            return;
        }

        $history_id_last_summarized = is_numeric($summary_lines[0]['history_id_to']) ? (int) $summary_lines[0]['history_id_to'] : 0;

        $query = '
SELECT
    id
  FROM ' . Tables::history() . '
  ORDER BY id DESC
  LIMIT 1
;';
        $history_lines = $this->conn->executeQuery($query)->fetchAllAssociative();
        if (count($history_lines) == 0) {
            return;
        }
        $history_id_latest = is_numeric($history_lines[0]['id']) ? (int) $history_lines[0]['id'] : 0;

        $query = '
SELECT
    id
  FROM ' . Tables::history() . '
  ORDER BY id ASC
  LIMIT 1
;';
        $history_lines = $this->conn->executeQuery($query)->fetchAllAssociative();
        $history_id_oldest = is_numeric($history_lines[0]['id']) ? (int) $history_lines[0]['id'] : 0;

        $search_min = [
            $history_id_last_summarized,
            $history_id_latest - Config::historyAutopurgeKeepLines(),
            $history_id_oldest + Config::historyAutopurgeBlocksize(),
        ];

        $history_id_delete_before = min($search_min);
        $logger->debug(__FUNCTION__ . ', ' . join('/', $search_min));

        $histRepo->deleteBeforeId($history_id_delete_before);
    }
}
