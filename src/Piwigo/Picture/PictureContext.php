<?php

declare(strict_types=1);

namespace Piwigo\Picture;

use Piwigo\Image\SrcImage;

/**
 * Immutable value object holding the picture-page navigation context.
 * Built by PictureController::__invoke() and distributed via PictureContextRegistry.
 */
final readonly class PictureContext
{
    /**
     * @param array<int|string, int>        $rankOf            Map of imageId → rank position
     * @param list<array<string, mixed>>    $relatedCategories Parent categories of the current image
     */
    public function __construct(
        public int       $currentItem       = 0,
        public ?int      $nextItem          = null,
        public ?int      $previousItem      = null,
        public ?int      $firstItem         = null,
        public ?int      $lastItem          = null,
        public int       $currentRank       = 0,
        public int       $lastRank          = 0,
        public array     $rankOf            = [],
        public bool      $slideshow         = false,
        public ?float    $ratingScore       = null,
        public ?SrcImage $srcImage          = null,
        public array     $relatedCategories = [],
    ) {
    }
}
