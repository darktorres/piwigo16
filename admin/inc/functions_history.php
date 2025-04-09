<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\admin\inc;

use Piwigo\inc\dblayer\functions_mysqli;
use Piwigo\inc\functions;
use Piwigo\inc\functions_plugins;

final class functions_history
{
    /**
     * Init tabsheet for history pages
     */
    public static function history_tabsheet(): void
    {
        global $page, $link_start;

        // TabSheet
        $tabsheet = new tabsheet();
        $tabsheet->set_id('history');
        $tabsheet->select($page['page']);
        $tabsheet->assign();
    }

    /**
     * Callback used to sort history entries
     */
    public static function history_compare(
        array $a,
        array $b
    ): int {
        return strcmp($a['date'] . $a['time'], $b['date'] . $b['time']);
    }

    /**
     * Perform history search.
     *
     * @param array $data  - used in trigger_change
     * @param array<string> $types
     */
    public static function get_history(
        array $data,
        array $search,
        array $types
    ): array {
        if (isset($search['fields']['filename'])) {
            $query = <<<SQL
                SELECT id
                FROM images
                WHERE file LIKE '{$search['fields']['filename']}';
                SQL;
            $search['image_ids'] = functions_mysqli::query2array($query, null, 'id');
        }

        // echo '<pre>'; print_r($search); echo '</pre>';

        $clauses = [];

        if (isset($search['fields']['date-after'])) {
            $clauses[] = "date >= '" . $search['fields']['date-after'] . "'";
        }

        if (isset($search['fields']['date-before'])) {
            $clauses[] = "date <= '" . $search['fields']['date-before'] . "'";
        }

        if (isset($search['fields']['types'])) {
            $local_clauses = [];

            foreach ($types as $type) {
                if (in_array($type, $search['fields']['types'])) {
                    $clause = 'image_type ';

                    if ($type == 'none') {
                        $clause .= 'IS NULL';
                    } else {
                        $clause .= "= '" . $type . "'";
                    }

                    $local_clauses[] = $clause;
                }
            }

            if ($local_clauses !== []) {
                $clauses[] = implode(' OR ', $local_clauses);
            }
        }

        if (isset($search['fields']['user']) &&
            $search['fields']['user'] != -1
        ) {
            $clauses[] = 'user_id = ' . $search['fields']['user'];
        }

        if (isset($search['fields']['image_id'])) {
            $clauses[] = 'image_id = ' . $search['fields']['image_id'];
        }

        if (isset($search['fields']['filename'])) {
            if (count($search['image_ids']) == 0) {
                // a clause that is always false
                $clauses[] = '1 = 2 ';
            } else {
                $clauses[] = 'image_id IN (' . implode(', ', $search['image_ids']) . ')';
            }
        }

        if (isset($search['fields']['ip'])) {
            $clauses[] = 'IP LIKE "' . $search['fields']['ip'] . '"';
        }

        $clauses = functions::prepend_append_array_items($clauses, '(', ')');

        $where_separator =
          implode(
              "\n    AND ",
              $clauses
          );

        $query = <<<SQL
            SELECT date, time, user_id, IP, section, category_id, search_id, tag_ids, image_id, image_type
            FROM history
            WHERE {$where_separator}
            SQL;

        // LIMIT '.$conf->nb_logs_page.' OFFSET '.$page['start'].'

        $result = functions_mysqli::pwg_query($query);

        while ($row = functions_mysqli::pwg_db_fetch_assoc($result)) {
            $data[] = $row;
        }

        return $data;
    }

    /**
     * Compute statistics from history table to history_summary table
     *
     * @param int|null $max_lines - to only compute the next X lines, not the whole remaining lines
     */
    public static function history_summarize(
        ?int $max_lines = null
    ): void {
        // we need to know which was the last line "summarized"
        $query = <<<SQL
            SELECT *
            FROM history_summary
            WHERE history_id_to IS NOT NULL
            ORDER BY history_id_to DESC
            LIMIT 1;
            SQL;
        $summary_lines = functions_mysqli::query2array($query);

        $history_min_id = 0;

        if ($summary_lines !== []) {
            $last_summary = $summary_lines[0];
            $history_min_id = $last_summary['history_id_to'];
        } else {
            // if we have no "reference", ie "starting point", we need to find
            // one. And "0" is not the right answer here, because history table may
            // have been purged already.
            $query = <<<SQL
                SELECT MIN(id) AS min_id
                FROM history;
                SQL;
            $history_lines = functions_mysqli::query2array($query);

            if ($history_lines !== []) {
                $history_min_id = $history_lines[0]['min_id'] - 1;
            }
        }

        $hourFunction = functions_mysqli::pwg_db_get_hour('time');
        $query = <<<SQL
            SELECT date, {$hourFunction} AS hour, MIN(id) AS min_id, MAX(id) AS max_id, COUNT(*) AS nb_pages
            FROM history
            WHERE id > {$history_min_id}

            SQL;

        if (isset($max_lines)) {
            $idLimit = $history_min_id + $max_lines;
            $query .= <<<SQL
                AND id <= {$idLimit}
                SQL;
        }

        $query .= <<<SQL
            GROUP BY date, hour
            ORDER BY date ASC, hour ASC;
            SQL;
        $result = functions_mysqli::pwg_query($query);

        $need_update = [];

        $is_first = true;
        $first_time_key = null;

        while ($row = functions_mysqli::pwg_db_fetch_assoc($result)) {
            $time_keys = [
                substr($row['date'], 0, 4), //yyyy
                substr($row['date'], 0, 7), //yyyy-mm
                substr($row['date'], 0, 10), //yyyy-mm-dd
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

            $query = <<<SQL
                SELECT *
                FROM history_summary
                WHERE year = {$year}
                    AND (month IS NULL OR (month = {$month} AND (day IS NULL OR (day = {$day} AND (hour IS NULL OR hour = {$hour})))));
                SQL;
            $result = functions_mysqli::pwg_query($query);

            while ($row = functions_mysqli::pwg_db_fetch_assoc($result)) {
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
                'year' => $time_tokens[0],
                'month' => ($time_tokens[1] ?? null),
                'day' => ($time_tokens[2] ?? null),
                'hour' => ($time_tokens[3] ?? null),
                'nb_pages' => $summary['nb_pages'],
                'history_id_from' => $summary['history_id_from'],
                'history_id_to' => $summary['history_id_to'],
            ];
        }

        if ($updates !== []) {
            functions_mysqli::mass_updates(
                'history_summary',
                [
                    'primary' => ['year', 'month', 'day', 'hour'],
                    'update' => ['nb_pages', 'history_id_to'],
                ],
                $updates
            );
        }

        if ($inserts !== []) {
            functions_mysqli::mass_inserts(
                'history_summary',
                array_keys($inserts[0]),
                $inserts
            );
        }
    }

    /**
     * Smart purge on history table. Keep some lines, purge only summarized lines
     */
    public static function history_autopurge(): void
    {
        global $conf, $logger;

        if ($conf->history_autopurge_keep_lines == 0) {
            return;
        }

        // we want to purge only if there are too many lines and if the lines are summarized

        $query = <<<SQL
            SELECT COUNT(*)
            FROM history;
            SQL;
        [$count] = functions_mysqli::pwg_db_fetch_row(functions_mysqli::pwg_query($query));

        if ($count <= $conf->history_autopurge_keep_lines) {
            self::history_remove_summarized_column();
            return; // no need to purge for now
        }

        // 1) find the last summarized history line
        $query = <<<SQL
            SELECT *
            FROM history_summary
            WHERE history_id_to IS NOT NULL
            ORDER BY history_id_to DESC
            LIMIT 1;
            SQL;
        $summary_lines = functions_mysqli::query2array($query);

        if (count($summary_lines) == 0) {
            return; // lines not summarized, no purge
        }

        $history_id_last_summarized = $summary_lines[0]['history_id_to'];

        // 2) find the latest history line (and subtract the number of lines to keep)
        $query = <<<SQL
            SELECT id
            FROM history
            ORDER BY id DESC
            LIMIT 1;
            SQL;
        $history_lines = functions_mysqli::query2array($query);

        if (count($history_lines) == 0) {
            return;
        }

        $history_id_latest = $history_lines[0]['id'];

        // 3) find the oldest history line (and add the number of lines to delete)
        $query = <<<SQL
            SELECT id
            FROM history
            ORDER BY id ASC
            LIMIT 1;
            SQL;
        $history_lines = functions_mysqli::query2array($query);
        $history_id_oldest = $history_lines[0]['id'];

        $search_min = [
            $history_id_last_summarized,
            $history_id_latest - $conf->history_autopurge_keep_lines,
            $history_id_oldest + $conf->history_autopurge_blocksize,
        ];

        $history_id_delete_before = min($search_min);

        $logger->debug(__FUNCTION__ . ', ' . implode('/', $search_min));

        $query = <<<SQL
            DELETE FROM history
            WHERE id < {$history_id_delete_before};
            SQL;
        functions_mysqli::pwg_query($query);

        self::history_remove_summarized_column();
    }

    public static function history_remove_summarized_column(): void
    {
        global $conf;

        if ($conf->history_summarized_dropped !== null &&
            $conf->history_summarized_dropped
        ) {
            return;
        }

        $query = <<<SQL
            SELECT COUNT(*)
            FROM history;
            SQL;
        [$count] = functions_mysqli::pwg_db_fetch_row(functions_mysqli::pwg_query($query));

        if ($count > $conf->history_autopurge_keep_lines + $conf->history_autopurge_blocksize) {
            // it's not yet time to remove history.summarized
            return;
        }

        $result = functions_mysqli::pwg_query('SHOW COLUMNS FROM history LIKE "summarized";');

        if (functions_mysqli::pwg_db_num_rows($result)) {
            functions_mysqli::pwg_query('ALTER TABLE history DROP COLUMN summarized;');
        }

        functions::conf_update_param('history_summarized_dropped', true);
    }
}

functions_plugins::add_event_handler('get_history', functions_history::get_history(...));
functions_plugins::trigger_notify('functions_history_included');
