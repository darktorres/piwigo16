<?php

declare(strict_types=1);

namespace Piwigo\Picture;

/**
 * Immutable value object holding the picture-page navigation context.
 * Built by PictureController::__invoke() and distributed via PictureContextRegistry.
 */
final class PictureContext
{
    /**
     * @param array<int|string, int> $rankOf   Map of imageId → rank position
     */
    public function __construct(
        public readonly int    $currentItem  = 0,
        public readonly ?int   $nextItem     = null,
        public readonly ?int   $previousItem = null,
        public readonly ?int   $firstItem    = null,
        public readonly ?int   $lastItem     = null,
        public readonly int    $currentRank  = 0,
        public readonly int    $lastRank     = 0,
        public readonly array  $rankOf       = [],
        public readonly bool   $slideshow    = false,
    ) {
    }
}
