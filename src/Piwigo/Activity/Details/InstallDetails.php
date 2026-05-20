<?php

declare(strict_types=1);

namespace Piwigo\Activity\Details;

use Piwigo\Activity\ActivityDetails;

/** Payload for core Install events — the version that was installed. */
final readonly class InstallDetails implements ActivityDetails
{
    public function __construct(public string $version)
    {
    }

    #[\Override]
    public function toJsonArray(): array
    {
        return ['version' => $this->version];
    }
}
