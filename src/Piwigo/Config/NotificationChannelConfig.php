<?php

declare(strict_types=1);

namespace Piwigo\Config;

/** Per-channel limits for recent-post-date notification queries (RSS or NBM). */
final readonly class NotificationChannelConfig
{
    public function __construct(
        public int $maxDates,
        public int $maxElements,
        public int $maxCats,
    ) {
    }
}
