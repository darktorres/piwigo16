<?php

declare(strict_types=1);

namespace Piwigo\Activity\Details;

use Piwigo\Activity\ActivityDetails;

/** Payload for User Edit events that associate a user with a group (or vice-versa). */
final readonly class UserAssocDetails implements ActivityDetails
{
    public function __construct(public int $associated)
    {
    }

    #[\Override]
    public function toJsonArray(): array
    {
        return ['associated' => $this->associated];
    }
}
