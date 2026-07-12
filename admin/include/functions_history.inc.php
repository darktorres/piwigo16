<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

use Piwigo\Admin\tabsheet;
use Piwigo\Db\DbConnection;
use Piwigo\History\HistoryRepository;
use Piwigo\History\HistoryService;

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
    return new HistoryService(new HistoryRepository(DbConnection::build()))
        ->historyCompare($a, $b);
}

/**
 * Perform history search.
 *
 * @param array<int, array<string, mixed>> $data  - used in trigger_change
 * @param array<string, mixed> $search
 * @param list<string> $types
 * @return array<int, array<string, mixed>>
 */
function get_history($data, array $search, $types): array
{
    return new HistoryService(new HistoryRepository(DbConnection::build()))
        ->getHistory($data, $search, $types);
}

/**
 * Compute statistics from history table to history_summary table
 *
 * @param int|null $max_lines - to only compute the next X lines, not the whole remaining lines
 */
function history_summarize(?int $max_lines = null): void
{
    new HistoryService(new HistoryRepository(DbConnection::build()))
        ->summarize($max_lines);
}

/**
 * Smart purge on history table. Keep some lines, purge only summarized lines
 *
 * @since 2.9
 */
function history_autopurge(): void
{
    new HistoryService(new HistoryRepository(DbConnection::build()))
        ->autopurge();
}

add_event_handler('get_history', 'get_history');
