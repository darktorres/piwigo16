<?php

declare(strict_types=1);

namespace Piwigo\Activity\Details;

use Piwigo\Activity\ActivityDetails;

/** Payload for core Update / AutoUpdate events — the version pair that was applied. */
final readonly class VersionDetails implements ActivityDetails
{
    public function __construct(
        public string $fromVersion,
        public string $toVersion,
    ) {
    }

    #[\Override]
    public function toJsonArray(): array
    {
        return ['from_version' => $this->fromVersion, 'to_version' => $this->toVersion];
    }
}
