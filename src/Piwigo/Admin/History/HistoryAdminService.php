<?php

declare(strict_types=1);

namespace Piwigo\Admin\History;

use Doctrine\DBAL\Connection;
use Piwigo\Admin\Tabsheet;
use Piwigo\Config\Config;
use Piwigo\Config\ConfigService;
use Piwigo\Core\LoggerRegistry;
use Piwigo\Core\ServiceLocator;
use Piwigo\Core\StringUtil;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Dml;
use Piwigo\Db\SqlExpr;
use Piwigo\Db\Tables;
use Piwigo\History\HistoryRepository;

final class HistoryAdminService
{
    public function historyTabsheet(): void
    {
        /** @var array<string, mixed> $page */
        $page = is_array($GLOBALS['page'] ?? null) ? $GLOBALS['page'] : [];
        $currentPage = is_scalar($page['page'] ?? null) ? (string) $page['page'] : '';
        $tabsheet = new Tabsheet();
        $tabsheet->setId('history');
        $tabsheet->select($currentPage);
        $tabsheet->assign();
    }

    public function historyCompare(mixed $a, mixed $b): int
    {
        $aArr = is_array($a) ? $a : [];
        $bArr = is_array($b) ? $b : [];
        $aStr = (is_scalar($aArr['date'] ?? null) ? (string) $aArr['date'] : '') . (is_scalar($aArr['time'] ?? null) ? (string) $aArr['time'] : '');
        $bStr = (is_scalar($bArr['date'] ?? null) ? (string) $bArr['date'] : '') . (is_scalar($bArr['time'] ?? null) ? (string) $bArr['time'] : '');
        return strcmp($aStr, $bStr);
    }

    /**
     * @param array<array<string, mixed>> $data
     * @param array<mixed> $search
     * @param string[]|string $types
     * @return array<array<string, mixed>>
     */
    public function getHistory(array $data, array $search, array|string $types): array
    {
        if (!is_array($types)) {
            $types = [$types];
        }
        /** @var array<string, mixed> $fields */
        $fields = is_array($search['fields'] ?? null) ? $search['fields'] : [];

        if (isset($fields['filename'])) {
            $query = '
SELECT
    id
  FROM ' . Tables::images() . '
  WHERE file LIKE ' . DbConnection::get()->quote(is_string($fields['filename']) ? $fields['filename'] : '') . '
;';
            $search['image_ids'] = array_column(DbConnection::get()->executeQuery($query)->fetchAllAssociative(), 'id');
        }

        $clauses = [];

        if (isset($fields['date-after'])) {
            $clauses[] = "date >= '" . (is_string($fields['date-after']) ? $fields['date-after'] : '') . "'";
        }

        if (isset($fields['date-before'])) {
            $clauses[] = "date <= '" . (is_string($fields['date-before']) ? $fields['date-before'] : '') . "'";
        }

        if (isset($fields['types'])) {
            $local_clauses = [];
            $types_field = is_array($fields['types']) ? $fields['types'] : [];

            foreach ($types as $type) {
                if (in_array($type, $types_field)) {
                    $clause = 'image_type ';
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
            $clauses[] = 'user_id = ' . (is_string($fields['user']) ? $fields['user'] : '0');
        }

        if (isset($fields['image_id'])) {
            $clauses[] = 'image_id = ' . (is_string($fields['image_id']) ? $fields['image_id'] : '0');
        }

        if (isset($fields['filename'])) {
            $image_ids = is_array($search['image_ids'] ?? null) ? $search['image_ids'] : [];
            if (count($image_ids) == 0) {
                $clauses[] = '1 = 2 ';
            } else {
                $clauses[] = 'image_id IN (' . implode(', ', array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '0', $image_ids)) . ')';
            }
        }

        if (isset($fields['ip'])) {
            $clauses[] = 'IP LIKE ' . DbConnection::get()->quote(is_string($fields['ip']) ? $fields['ip'] : '');
        }

        $clauses = ServiceLocator::get(StringUtil::class)->prependAppendArrayItems($clauses, '(', ')');
        $where_separator = implode("\n    AND ", $clauses);

        $query = '
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
  WHERE ' . $where_separator . '
;';

        foreach (ServiceLocator::get(Connection::class)
            ->executeQuery($query)->fetchAllAssociative() as $row) {
            $data[] = $row;
        }

        return $data;
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
        $summary_lines = DbConnection::get()->executeQuery($query)->fetchAllAssociative();

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
            $history_lines = DbConnection::get()->executeQuery($query)->fetchAllAssociative();
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
        $historyRows = ServiceLocator::get(Connection::class)
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
            foreach (ServiceLocator::get(Connection::class)
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
            $time_tokens = explode('-', $time_key);
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
            Dml::massInserts(Tables::historySummary(), array_keys($inserts[0]), $inserts);
        }
    }

    public function historyAutopurge(): void
    {
        $logger = LoggerRegistry::current();

        if (0 == Config::historyAutopurgeKeepLines()) {
            return;
        }

        $histRepo = ServiceLocator::get(HistoryRepository::class);
        $count = $histRepo->countAll();

        if ($count <= Config::historyAutopurgeKeepLines()) {
            $this->historyRemoveSummarizedColumn();
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
        $summary_lines = DbConnection::get()->executeQuery($query)->fetchAllAssociative();
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
        $history_lines = DbConnection::get()->executeQuery($query)->fetchAllAssociative();
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
        $history_lines = DbConnection::get()->executeQuery($query)->fetchAllAssociative();
        $history_id_oldest = is_numeric($history_lines[0]['id']) ? (int) $history_lines[0]['id'] : 0;

        $search_min = [
            $history_id_last_summarized,
            $history_id_latest - Config::historyAutopurgeKeepLines(),
            $history_id_oldest + Config::historyAutopurgeBlocksize(),
        ];

        $history_id_delete_before = min($search_min);
        $logger->debug(__FUNCTION__ . ', ' . join('/', $search_min));

        $histRepo->deleteBeforeId($history_id_delete_before);
        $this->historyRemoveSummarizedColumn();
    }

    public function historyRemoveSummarizedColumn(): void
    {
        if (Config::has('history_summarized_dropped') && Config::historySummarizedDropped()) {
            return;
        }

        $histRepo = ServiceLocator::get(HistoryRepository::class);
        $count = $histRepo->countAll();

        if ($count > Config::historyAutopurgeKeepLines() + Config::historyAutopurgeBlocksize()) {
            return;
        }

        if ($histRepo->summarizedColumnExists()) {
            $histRepo->dropSummarizedColumn();
        }

        ServiceLocator::get(ConfigService::class)->confUpdateParam('history_summarized_dropped', true);
    }
}
