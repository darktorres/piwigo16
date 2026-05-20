<?php

declare(strict_types=1);

namespace Piwigo\Activity\Details;

use Piwigo\Activity\ActivityDetails;

/** Payload for Album Move — records the destination parent album id. */
final readonly class MoveAlbumDetails implements ActivityDetails
{
    public function __construct(public int $parent)
    {
    }

    #[\Override]
    public function toJsonArray(): array
    {
        return ['parent' => $this->parent];
    }
}
