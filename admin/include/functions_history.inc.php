<?php

declare(strict_types=1);

use Piwigo\Admin\Tabsheet;

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

/**
 * @package functions\admin\history
 */


/**
 * Init tabsheet for history pages
 * @ignore
 */
function history_tabsheet(): void
{
    global $link_start;
    global $page;
    // TabSheet
    $tabsheet = new Tabsheet();
    $tabsheet->set_id('history');
    $tabsheet->select($page['page']);
    $tabsheet->assign();
}

/**
 * Callback used to sort history entries
 */
/**
 * @param array<mixed> $a
 * @param array<mixed> $b
 */
function history_compare(array $a, array $b): int
{
    $aStr = (is_scalar($a['date'] ?? null) ? (string)$a['date'] : '') . (is_scalar($a['time'] ?? null) ? (string)$a['time'] : '');
    $bStr = (is_scalar($b['date'] ?? null) ? (string)$b['date'] : '') . (is_scalar($b['time'] ?? null) ? (string)$b['time'] : '');
    return strcmp($aStr, $bStr);
}

/**
 * Perform history search.
 *
 * @param array $data  - used in trigger_change
 * @param string[] $types
 */
/**
 * @param array<mixed> $data
 * @param array<mixed> $search
 * @param string[]|string $types
 * @return array<mixed>
 */
function get_history(array $data, array $search, array|string $types): array
{
    if (!is_array($types)) {
        $types = [$types];
    }
    /** @var array<string,mixed> $fields */
    $fields = is_array($search['fields'] ?? null) ? $search['fields'] : [];

    if (isset($fields['filename'])) {
        $query = '
SELECT
    id
  FROM '.IMAGES_TABLE.'
  WHERE file LIKE \''.(is_scalar($fields['filename']) ? (string)$fields['filename'] : '').'\'
;';
        $search['image_ids'] = query2array($query, null, 'id');
    }

    // echo '<pre>'; print_r($search); echo '</pre>';

    $clauses = [];

    if (isset($fields['date-after'])) {
        $clauses[] = "date >= '".(is_scalar($fields['date-after']) ? (string)$fields['date-after'] : '')."'";
    }

    if (isset($fields['date-before'])) {
        $clauses[] = "date <= '".(is_scalar($fields['date-before']) ? (string)$fields['date-before'] : '')."'";
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
                    $clause .= "= '".$type."'";
                }

                $local_clauses[] = $clause;
            }
        }

        if (count($local_clauses) > 0) {
            $clauses[] = implode(' OR ', $local_clauses);
        }
    }

    if (isset($fields['user'])
        and $fields['user'] != -1) {
        $clauses[] = 'user_id = '.(is_scalar($fields['user']) ? (string)$fields['user'] : '0');
    }

    if (isset($fields['image_id'])) {
        $clauses[] = 'image_id = '.(is_scalar($fields['image_id']) ? (string)$fields['image_id'] : '0');
    }

    if (isset($fields['filename'])) {
        $image_ids = is_array($search['image_ids'] ?? null) ? $search['image_ids'] : [];
        if (count($image_ids) == 0) {
            // a clause that is always false
            $clauses[] = '1 = 2 ';
        } else {
            $clauses[] = 'image_id IN ('.implode(', ', array_map(fn (mixed $v): string => is_scalar($v) ? (string)$v : '', $image_ids)).')';
        }
    }

    if (isset($fields['ip'])) {
        $clauses[] = 'IP LIKE "'.(is_scalar($fields['ip']) ? (string)$fields['ip'] : '').'"';
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
  FROM '.HISTORY_TABLE.'
  WHERE '.$where_separator.'
;';

    // LIMIT '.\Piwigo\Config\Config::nbLogsPage().' OFFSET '.$page['start'].'

    $result = pwg_query($query);

    while ($row = pwg_db_fetch_assoc($result)) {
        $data[] = $row;
    }

    return $data;
}

/**
 * Compute statistics from history table to history_summary table
 *
 * @param int $max_lines - to only compute the next X lines, not the whole remaining lines
 */
function history_summarize(?int $max_lines = null): void
{
    // we need to know which was the last line "summarized"
    $query = '
SELECT
    *
  FROM '.HISTORY_SUMMARY_TABLE.'
  WHERE history_id_to IS NOT NULL
  ORDER BY history_id_to DESC
  LIMIT 1
;';
    $summary_lines = query2array($query);

    $history_min_id = 0;
    if (count($summary_lines) > 0) {
        $last_summary = $summary_lines[0];
        $history_min_id = is_numeric($last_summary['history_id_to'] ?? null) ? (int)$last_summary['history_id_to'] : 0;
    } else {
        // if we have no "reference", ie "starting point", we need to find
        // one. And "0" is not the right answer here, because history table may
        // have been purged already.
        $query = '
SELECT
    MIN(id) AS min_id
  FROM '.HISTORY_TABLE.'
;';
        $history_lines = query2array($query);
        if (count($history_lines) > 0) {
            $history_min_id = (int) $history_lines[0]['min_id'] - 1;
        }
    }

    $query = '
SELECT
    date,
    '.pwg_db_get_hour('time').' AS hour,
    MIN(id) AS min_id,
    MAX(id) AS max_id,
    COUNT(*) AS nb_pages
  FROM '.HISTORY_TABLE.'
  WHERE id > '.$history_min_id;

    if (isset($max_lines)) {
        $query .= '
    AND id <= '.($history_min_id + $max_lines);
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

    $need_update = [];

    $is_first = true;
    $first_time_key = null;

    while ($row = pwg_db_fetch_assoc($result)) {
        $time_keys = [
          substr((string) $row['date'], 0, 4), //yyyy
          substr((string) $row['date'], 0, 7), //yyyy-mm
          substr((string) $row['date'], 0, 10),//yyyy-mm-dd
          sprintf(
              '%s-%02u',
              $row['date'],
              $row['hour']
          ),
          ];

        foreach ($time_keys as $time_key) {
            if (!isset($need_update[$time_key])) {
                $need_update[$time_key] = [
                  'nb_pages' => 0,
                  'history_id_from' => $row['min_id'],
                  'history_id_to' => $row['max_id'],
                  ];
            }
            $need_update[$time_key]['nb_pages'] += $row['nb_pages'];

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
  FROM '.HISTORY_SUMMARY_TABLE.'
  WHERE year='.$year.'
    AND ( month IS NULL
      OR ( month='.$month.'
        AND ( day is NULL
          OR (day='.$day.'
            AND (hour IS NULL OR hour='.$hour.')
          )
        )
      )
    )
;';
        $result = pwg_query($query);
        while ($row = pwg_db_fetch_assoc($result)) {
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
                $row['nb_pages'] += $need_update[$key]['nb_pages'];
                $row['history_id_to'] = $need_update[$key]['history_id_to'];
                $updates[] = $row;
                unset($need_update[$key]);
            }
        }
    }

    foreach ($need_update as $time_key => $summary) {
        $time_tokens = explode('-', (string) $time_key);

        $inserts[] = [
          'year'     => $time_tokens[0],
          'month'    => @$time_tokens[1],
          'day'      => @$time_tokens[2],
          'hour'     => @$time_tokens[3],
          'nb_pages' => $summary['nb_pages'],
          'history_id_from' => $summary['history_id_from'],
          'history_id_to' => $summary['history_id_to'],
          ];
    }

    if (count($updates) > 0) {
        mass_updates(
            HISTORY_SUMMARY_TABLE,
            [
            'primary' => ['year','month','day','hour'],
            'update'  => ['nb_pages','history_id_to'],
            ],
            $updates
        );
    }

    if (count($inserts) > 0) {
        mass_inserts(
            HISTORY_SUMMARY_TABLE,
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
    $logger = \Piwigo\Core\LoggerRegistry::current();

    if (0 == \Piwigo\Config\Config::historyAutopurgeKeepLines()) {
        return;
    }

    // we want to purge only if there are too many lines and if the lines are summarized

    $query = '
SELECT
    COUNT(*)
  FROM '.HISTORY_TABLE.'
;';
    [$count] = pwg_db_fetch_row(pwg_query($query)) ?? [null];

    if ($count <= \Piwigo\Config\Config::historyAutopurgeKeepLines()) {
        history_remove_summarized_column();
        return; // no need to purge for now
    }

    // 1) find the last summarized history line
    $query = '
SELECT
    *
  FROM '.HISTORY_SUMMARY_TABLE.'
  WHERE history_id_to IS NOT NULL
  ORDER BY history_id_to DESC
  LIMIT 1
;';
    $summary_lines = query2array($query);
    if (count($summary_lines) == 0) {
        return; // lines not summarized, no purge
    }

    $history_id_last_summarized = (int) $summary_lines[0]['history_id_to'];

    // 2) find the latest history line (and substract the number of lines to keep)
    $query = '
SELECT
    id
  FROM '.HISTORY_TABLE.'
  ORDER BY id DESC
  LIMIT 1
;';
    $history_lines = query2array($query);
    if (count($history_lines) == 0) {
        return;
    }

    $history_id_latest = (int) $history_lines[0]['id'];

    // 3) find the oldest history line (and add the number of lines to delete)
    $query = '
SELECT
    id
  FROM '.HISTORY_TABLE.'
  ORDER BY id ASC
  LIMIT 1
;';
    $history_lines = query2array($query);
    $history_id_oldest = (int) $history_lines[0]['id'];

    $search_min = [
      $history_id_last_summarized,
      $history_id_latest - \Piwigo\Config\Config::historyAutopurgeKeepLines(),
      $history_id_oldest + \Piwigo\Config\Config::historyAutopurgeBlocksize(),
      ];

    $history_id_delete_before = min($search_min);

    $logger->debug(__FUNCTION__.', '.join('/', $search_min));

    $query = '
DELETE
  FROM '.HISTORY_TABLE.'
  WHERE id < '.$history_id_delete_before.'
;';
    pwg_query($query);

    history_remove_summarized_column();
}

function history_remove_summarized_column(): void
{
    if (\Piwigo\Config\Config::has('history_summarized_dropped') and \Piwigo\Config\Config::historySummarizedDropped()) {
        return;
    }

    $query = '
SELECT
    COUNT(*)
  FROM '.HISTORY_TABLE.'
;';
    [$count] = pwg_db_fetch_row(pwg_query($query)) ?? [null];

    if ($count > \Piwigo\Config\Config::historyAutopurgeKeepLines() + \Piwigo\Config\Config::historyAutopurgeBlocksize()) {
        // it's not yet time to remove history.summarized
        return;
    }

    $result = pwg_query('SHOW COLUMNS FROM `'.HISTORY_TABLE.'` LIKE "summarized";');
    if (pwg_db_num_rows($result)) {
        pwg_query('ALTER TABLE `'.HISTORY_TABLE.'` DROP COLUMN `summarized`;');
    }

    conf_update_param('history_summarized_dropped', true);
}

add_event_handler('get_history', 'get_history');
trigger_notify('functions_history_included');
