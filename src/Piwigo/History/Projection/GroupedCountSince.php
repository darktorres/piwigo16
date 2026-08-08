<?php

declare(strict_types=1);

namespace Piwigo\History\Projection;

/**
 * {@see \Piwigo\History\HistoryRepository::findGroupedCountsSince()}'s own
 * per-(date, hour)-bucket row shape --
 * {@see \Piwigo\History\HistoryService::summarize()}'s real (and only)
 * consumer.
 */
final readonly class GroupedCountSince
{
    public function __construct(
        public string $date,
        public int $hour,
        public int $minId,
        public int $maxId,
        public int $nbPages,
    ) {}
}
