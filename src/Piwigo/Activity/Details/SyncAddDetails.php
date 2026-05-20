<?php

declare(strict_types=1);

namespace Piwigo\Activity\Details;

use Piwigo\Activity\ActivityDetails;

/** Payload for Add events triggered by a filesystem sync (not a browser or API upload). */
final readonly class SyncAddDetails implements ActivityDetails
{
    #[\Override]
    public function toJsonArray(): array
    {
        return ['sync' => true];
    }
}
