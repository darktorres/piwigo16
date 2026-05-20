<?php

declare(strict_types=1);

namespace Piwigo\Activity\Details;

use Piwigo\Activity\ActivityDetails;

/** Payload for User Edit events triggered by a profile form save. */
final readonly class ProfileEditDetails implements ActivityDetails
{
    public function __construct(
        public string $function,
        public string $tables,
    ) {
    }

    #[\Override]
    public function toJsonArray(): array
    {
        return ['function' => $this->function, 'tables' => $this->tables];
    }
}
