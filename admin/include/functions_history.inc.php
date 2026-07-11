<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

use Piwigo\Admin\tabsheet;
use Piwigo\Core\Logger;
use Piwigo\Db\Tables;

/**
 * Init tabsheet for history pages
 * @ignore
 */
function history_tabsheet(): void
{
    /** @var array<string, mixed> $page */
    global $page, $link_start;

    // TabSheet
    $tabsheet = new tabsheet();
    $tabsheet->set_id('history');
    $page_tab = isset($page['page']) && is_string($page['page']) ? $page['page'] : '';
    $tabsheet->select($page_tab);
    $tabsheet->assign();
}

/**
 * Callback used to sort history entries
 * @param array<string, mixed> $a
 * @param array<string, mixed> $b
 */
function history_compare(array $a, array $b): int
{
    $a_date = isset($a['date']) && is_string($a['date']) ? $a['date'] : '';
    $a_time = isset($a['time']) && is_string($a['time']) ? $a['time'] : '';
    $b_date = isset($b['date']) && is_string($b['date']) ? $b['date'] : '';
    $b_time = isset($b['time']) && is_string($b['time']) ? $b['time'] : '';

    return strcmp($a_date . $a_time, $b_date . $b_time);
}

/**
 * Perform history search.
 *
 * @param array<int, array<string, mixed>> $data  - used in trigger_change
 * @param array<string, mixed> $search
 * @param string[] $types
 * @return array<int, array<string, mixed>>
 */
function get_history($data, array $search, $types): array
{
    // $search comes from unserialize()'d search rules (see
    // ws_history_search() in include/ws_functions/pwg.php), so its
    // 'fields' sub-array is only provably an array, not that its values
    // have the expected scalar/array shapes; narrow each field on use.
    $fields = [];
    if (isset($search['fields']) and is_array($search['fields'])) {
        $fields = $search['fields'];
    }

    $filename = null;
    if (isset($fields['filename']) and is_string($fields['filename'])) {
        $filename = $fields['filename'];
    }

    $image_ids = [];

    if ($filename !== null) {
        $query = '
SELECT
    id
  FROM ' . Tables::images() . '
  WHERE file LIKE \'' . $filename . '\'
;';
        $image_ids = array_filter(array_from_query($query, 'id'), is_string(...));
    }

    // echo '<pre>'; print_r($search); echo '</pre>';

    $clauses = [];

    if (isset($fields['date-after']) and is_string($fields['date-after'])) {
        $clauses[] = "date >= '" . $fields['date-after'] . "'";
    }

    if (isset($fields['date-before']) and is_string($fields['date-before'])) {
        $clauses[] = "date <= '" . $fields['date-before'] . "'";
    }

    if (isset($fields['types']) and is_array($fields['types'])) {
        $search_types = array_filter($fields['types'], is_string(...));
        $local_clauses = [];

        foreach ($types as $type) {
            if (in_array($type, $search_types)) {
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

    if (isset($fields['user']) and is_numeric($fields['user']) and (int) $fields['user'] !== -1) {
        $clauses[] = 'user_id = ' . (int) $fields['user'];
    }

    if (isset($fields['image_id']) and is_numeric($fields['image_id'])) {
        $clauses[] = 'image_id = ' . (int) $fields['image_id'];
    }

    if ($filename !== null) {
        if (count($image_ids) == 0) {
            // a clause that is always false
            $clauses[] = '1 = 2 ';
        } else {
            $clauses[] = 'image_id IN (' . implode(', ', $image_ids) . ')';
        }
    }

    if (isset($fields['ip']) and is_string($fields['ip'])) {
        $clauses[] = 'IP LIKE "' . $fields['ip'] . '"';
    }

    $clauses = prepend_append_array_items($clauses, '(', ')');

    $where_separator =
      implode(
          "\n    AND ",
          $clauses
      );

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

    // LIMIT '.$conf['nb_logs_page'].' OFFSET '.$page['start'].'

    $result = pwg_query($query);

    while ((bool) ($row = pwg_db_fetch_assoc($result))) {
        $data[] = $row;
    }

    return $data;
}

/**
 * Compute statistics from history table to history_summary table
 *
 * @param int|null $max_lines - to only compute the next X lines, not the whole remaining lines
 */
function history_summarize(?int $max_lines = null): void
{
    // we need to know which was the last line "summarized"
    $query = '
SELECT
    *
  FROM ' . Tables::historySummary() . '
  WHERE history_id_to IS NOT NULL
  ORDER BY history_id_to DESC
  LIMIT 1
;';
    $summary_lines = query2array($query);

    $history_min_id = 0;
    if (count($summary_lines) > 0) {
        $last_summary = $summary_lines[0];
        $last_history_id_to = $last_summary['history_id_to'];
        $history_min_id = is_numeric($last_history_id_to) ? (int) $last_history_id_to : 0;
    } else {
        // if we have no "reference", ie "starting point", we need to find
        // one. And "0" is not the right answer here, because history table may
        // have been purged already.
        $query = '
SELECT
    MIN(id) AS min_id
  FROM ' . Tables::history() . '
;';
        $history_lines = query2array($query);
        if (count($history_lines) > 0) {
            $min_id = $history_lines[0]['min_id'];
            // MIN(id) with no matching rows comes back as SQL NULL (the
            // aggregate query always returns exactly one row); mirror the
            // previous implicit null->0 coercion explicitly.
            $history_min_id = (is_numeric($min_id) ? (int) $min_id : 0) - 1;
        }
    }

    $query = '
SELECT
    date,
    ' . pwg_db_get_hour('time') . ' AS hour,
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
    $result = pwg_query($query);

    /** @var array<string, array{nb_pages: int, history_id_from: int, history_id_to: int}> $need_update */
    $need_update = [];

    $is_first = true;
    $first_time_key = null;

    while ((bool) ($row = pwg_db_fetch_assoc($result))) {
        $row_min_id = isset($row['min_id']) && is_numeric($row['min_id']) ? (int) $row['min_id'] : 0;
        $row_max_id = isset($row['max_id']) && is_numeric($row['max_id']) ? (int) $row['max_id'] : 0;
        $row_nb_pages = isset($row['nb_pages']) && is_numeric($row['nb_pages']) ? (int) $row['nb_pages'] : 0;

        $time_keys = [
            substr((string) $row['date'], 0, 4), // yyyy
            substr((string) $row['date'], 0, 7), // yyyy-mm
            substr((string) $row['date'], 0, 10), // yyyy-mm-dd
            sprintf(
                '%s-%02u',
                $row['date'],
                $row['hour']
            ),
        ];

        foreach ($time_keys as $time_key) {
            if (! isset($need_update[$time_key])) {
                $need_update[$time_key] = [
                    'nb_pages' => 0,
                    'history_id_from' => $row_min_id,
                    'history_id_to' => $row_max_id,
                ];
            }
            $need_update[$time_key]['nb_pages'] += $row_nb_pages;

            if ($row_min_id < $need_update[$time_key]['history_id_from']) {
                $need_update[$time_key]['history_id_from'] = $row_min_id;
            }

            if ($row_max_id > $need_update[$time_key]['history_id_to']) {
                $need_update[$time_key]['history_id_to'] = $row_max_id;
            }
        }

        if ($is_first) {
            $is_first = false;
            $first_time_key = $time_keys[3];
        }
    }

    // Only the oldest time_key might be already summarized, so we have to
    // update the 4 corresponding lines instead of simply inserting them.
    //
    // For example, if the oldest unsummarized is 2005.08.25.21, the 4 lines
    // that can be updated are:
    //
    // +---------------+----------+
    // | id            | nb_pages |
    // +---------------+----------+
    // | 2005          |   241109 |
    // | 2005-08       |    20133 |
    // | 2005-08-25    |      620 |
    // | 2005-08-25-21 |      151 |
    // +---------------+----------+

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
        $result = pwg_query($query);
        while ((bool) ($row = pwg_db_fetch_assoc($result))) {
            $key = sprintf('%4u', $row['year']);
            if (isset($row['month'])) {
                $key .= sprintf('-%02u', $row['month']);
                if (isset($row['day'])) {
                    $key .= sprintf('-%02u', $row['day']);
                    if (isset($row['hour'])) {
                        $key .= sprintf('-%02u', $row['hour']);
                    }
                }
            }

            if (isset($need_update[$key])) {
                $row_nb_pages = isset($row['nb_pages']) && is_numeric($row['nb_pages']) ? (int) $row['nb_pages'] : 0;
                $row['nb_pages'] = $row_nb_pages + $need_update[$key]['nb_pages'];
                $row['history_id_to'] = $need_update[$key]['history_id_to'];
                $updates[] = $row;
                unset($need_update[$key]);
            }
        }
    }

    foreach ($need_update as $time_key => $summary) {
        $time_tokens = explode('-', $time_key);

        $inserts[] = [
            'year' => $time_tokens[0],
            'month' => @$time_tokens[1],
            'day' => @$time_tokens[2],
            'hour' => @$time_tokens[3],
            'nb_pages' => $summary['nb_pages'],
            'history_id_from' => $summary['history_id_from'],
            'history_id_to' => $summary['history_id_to'],
        ];
    }

    if (count($updates) > 0) {
        mass_updates(
            Tables::historySummary(),
            [
                'primary' => ['year', 'month', 'day', 'hour'],
                'update' => ['nb_pages', 'history_id_to'],
            ],
            $updates
        );
    }

    if (count($inserts) > 0) {
        mass_inserts(
            Tables::historySummary(),
            array_keys($inserts[0]),
            $inserts
        );
    }
}

/**
 * Smart purge on history table. Keep some lines, purge only summarized lines
 *
 * @since 2.9
 */
function history_autopurge(): void
{
    /**
     * @var array<string, mixed> $conf
     * @var Logger $logger
     */
    global $conf, $logger;

    $keep_lines = is_numeric($conf['history_autopurge_keep_lines'] ?? null) ? (int) $conf['history_autopurge_keep_lines'] : 0;

    if ($keep_lines == 0) {
        return;
    }

    // we want to purge only if there are too many lines and if the lines are summarized

    $query = '
SELECT
    COUNT(*)
  FROM ' . Tables::history() . '
;';
    $row = pwg_db_fetch_row(pwg_query($query));
    assert($row !== null);
    [$count] = $row;

    if ($count <= $keep_lines) {
        history_remove_summarized_column();
        return; // no need to purge for now
    }

    // 1) find the last summarized history line
    $query = '
SELECT
    *
  FROM ' . Tables::historySummary() . '
  WHERE history_id_to IS NOT NULL
  ORDER BY history_id_to DESC
  LIMIT 1
;';
    $summary_lines = query2array($query);
    if (count($summary_lines) == 0) {
        return; // lines not summarized, no purge
    }

    $history_id_last_summarized_raw = $summary_lines[0]['history_id_to'];
    $history_id_last_summarized = is_numeric($history_id_last_summarized_raw) ? (int) $history_id_last_summarized_raw : 0;

    // 2) find the latest history line (and substract the number of lines to keep)
    $query = '
SELECT
    id
  FROM ' . Tables::history() . '
  ORDER BY id DESC
  LIMIT 1
;';
    $history_lines = query2array($query);
    if (count($history_lines) == 0) {
        return;
    }

    $history_id_latest_raw = $history_lines[0]['id'];
    $history_id_latest = is_numeric($history_id_latest_raw) ? (int) $history_id_latest_raw : 0;

    // 3) find the oldest history line (and add the number of lines to delete)
    $query = '
SELECT
    id
  FROM ' . Tables::history() . '
  ORDER BY id ASC
  LIMIT 1
;';
    $history_lines = query2array($query);
    $history_id_oldest_raw = $history_lines[0]['id'];
    $history_id_oldest = is_numeric($history_id_oldest_raw) ? (int) $history_id_oldest_raw : 0;

    $blocksize = is_numeric($conf['history_autopurge_blocksize'] ?? null) ? (int) $conf['history_autopurge_blocksize'] : 0;

    $search_min = [
        $history_id_last_summarized,
        $history_id_latest - $keep_lines,
        $history_id_oldest + $blocksize,
    ];

    $history_id_delete_before = min($search_min);

    $logger->debug(__FUNCTION__ . ', ' . join('/', array_map(strval(...), $search_min)));

    $query = '
DELETE
  FROM ' . Tables::history() . '
  WHERE id < ' . $history_id_delete_before . '
;';
    pwg_query($query);

    history_remove_summarized_column();
}

function history_remove_summarized_column(): void
{
    /** @var array<string, mixed> $conf */
    global $conf;

    if (isset($conf['history_summarized_dropped']) and (bool) $conf['history_summarized_dropped']) {
        return;
    }

    $query = '
SELECT
    COUNT(*)
  FROM ' . Tables::history() . '
;';
    $row = pwg_db_fetch_row(pwg_query($query));
    assert($row !== null);
    [$count] = $row;

    $keep_lines = is_numeric($conf['history_autopurge_keep_lines'] ?? null) ? (int) $conf['history_autopurge_keep_lines'] : 0;
    $blocksize = is_numeric($conf['history_autopurge_blocksize'] ?? null) ? (int) $conf['history_autopurge_blocksize'] : 0;

    if ($count > $keep_lines + $blocksize) {
        // it's not yet time to remove history.summarized
        return;
    }

    $result = pwg_query('SHOW COLUMNS FROM `' . Tables::history() . '` LIKE "summarized";');
    if ((bool) pwg_db_num_rows($result)) {
        pwg_query('ALTER TABLE `' . Tables::history() . '` DROP COLUMN `summarized`;');
    }

    conf_update_param('history_summarized_dropped', true);
}

add_event_handler('get_history', 'get_history');
trigger_notify('functions_history_included');
