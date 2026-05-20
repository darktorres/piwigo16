<?php

declare(strict_types=1);

namespace Piwigo\Activity\Details;

use Piwigo\Activity\ActivityDetails;

/** No per-action payload — login, logout, add/delete without additional context. */
final readonly class EmptyDetails implements ActivityDetails
{
    #[\Override]
    public function toJsonArray(): array
    {
        return [];
    }
}
