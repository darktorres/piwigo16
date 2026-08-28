<?php

declare(strict_types=1);

namespace Piwigo\Calendar\Projection;

/**
 * {@see \Piwigo\Calendar\CalendarBase::buildNextPrev()}'s own
 * `previous`/`next` entry.
 */
final readonly class CalendarNavAdjacent
{
    public function __construct(
        public string $label,
        public string $url,
    ) {}
}
