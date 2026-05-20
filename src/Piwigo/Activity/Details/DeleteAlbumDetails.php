<?php

declare(strict_types=1);

namespace Piwigo\Activity\Details;

use Piwigo\Activity\ActivityDetails;

/** Payload for Album Delete — records how photos were handled (no_delete / delete_orphans / force_delete). */
final readonly class DeleteAlbumDetails implements ActivityDetails
{
    public function __construct(public string $photoDeletionMode)
    {
    }

    #[\Override]
    public function toJsonArray(): array
    {
        return ['photo_deletion_mode' => $this->photoDeletionMode];
    }
}
