<?php

declare(strict_types=1);

namespace Piwigo\Picture;

use Piwigo\Image\SrcImage;

/**
 * Immutable value object holding the picture-page navigation context.
 * Built by PictureController::__invoke() and distributed via PictureContextRegistry.
 */
final class PictureContext
{
    /**
     * @param array<int|string, int>        $rankOf            Map of imageId → rank position
     * @param list<array<string, mixed>>    $relatedCategories Parent categories of the current image
     */
    public function __construct(
        public readonly int       $currentItem       = 0,
        public readonly ?int      $nextItem          = null,
        public readonly ?int      $previousItem      = null,
        public readonly ?int      $firstItem         = null,
        public readonly ?int      $lastItem          = null,
        public readonly int       $currentRank       = 0,
        public readonly int       $lastRank          = 0,
        public readonly array     $rankOf            = [],
        public readonly bool      $slideshow         = false,
        public readonly ?float    $ratingScore       = null,
        public readonly ?SrcImage $srcImage          = null,
        public readonly array     $relatedCategories = [],
    ) {
    }
}
