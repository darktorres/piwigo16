<?php

declare(strict_types=1);

namespace Piwigo\Activity\Details;

use Piwigo\Activity\ActivityDetails;

/** Payload for System Maintenance events — names the specific maintenance action performed. */
final readonly class MaintenanceDetails implements ActivityDetails
{
    public function __construct(public string $maintenanceAction)
    {
    }

    #[\Override]
    public function toJsonArray(): array
    {
        return ['maintenance_action' => $this->maintenanceAction];
    }
}
