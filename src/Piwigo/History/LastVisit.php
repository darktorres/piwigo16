<?php

declare(strict_types=1);

namespace Piwigo\History;

/** Date + time pair from the history table representing the last page view. */
final readonly class LastVisit
{
    public function __construct(
        public string $date,
        public string $time,
    ) {
    }

    public function toDateTimeString(): string
    {
        return $this->date . ' ' . $this->time;
    }
}
