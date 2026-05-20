<?php

declare(strict_types=1);

namespace Piwigo\Image;

/** Oldest lounge row returned by LoungeRepository::findOldestEntry(). */
final readonly class LoungeEntry
{
    public function __construct(
        public int    $imageId,
        public string $dateAvailable,
        public string $dbnow,
    ) {
    }
}
