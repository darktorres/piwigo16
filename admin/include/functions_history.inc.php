<?php

declare(strict_types=1);

use Piwigo\Admin\History\HistoryAdminService;
use Piwigo\Core\ServiceLocator;

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// +-----------------------------------------------------------------------+

function history_tabsheet(): void
{
    ServiceLocator::get(HistoryAdminService::class)->historyTabsheet();
}

/**
 * @param array<mixed> $a
 * @param array<mixed> $b
 */
function history_compare(array $a, array $b): int
{
    return ServiceLocator::get(HistoryAdminService::class)->historyCompare($a, $b);
}

/**
 * @param list<array<string, mixed>> $data
 * @param array<mixed> $search
 * @param string[]|string $types
 * @return list<array<string, mixed>>
 */
function get_history(array $data, array $search, array|string $types): array
{
    return ServiceLocator::get(HistoryAdminService::class)->getHistory($data, $search, $types);
}

function history_summarize(?int $max_lines = null): void
{
    ServiceLocator::get(HistoryAdminService::class)->historySummarize($max_lines);
}

function history_autopurge(): void
{
    ServiceLocator::get(HistoryAdminService::class)->historyAutopurge();
}

function history_remove_summarized_column(): void
{
    ServiceLocator::get(HistoryAdminService::class)->historyRemoveSummarizedColumn();
}

add_event_handler('get_history', 'get_history');
trigger_notify('functions_history_included');
