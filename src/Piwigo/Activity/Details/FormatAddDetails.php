<?php

declare(strict_types=1);

namespace Piwigo\Activity\Details;

use Piwigo\Activity\ActivityDetails;

/** Payload for Photo Edit events that add a format file to an existing photo. */
final readonly class FormatAddDetails implements ActivityDetails
{
    public function __construct(
        public string $formatExt,
        public int    $formatId,
    ) {
    }

    #[\Override]
    public function toJsonArray(): array
    {
        return ['action' => 'add format', 'format_ext' => $this->formatExt, 'format_id' => $this->formatId];
    }
}
