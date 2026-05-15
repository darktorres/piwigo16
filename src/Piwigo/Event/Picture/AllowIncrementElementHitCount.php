<?php

declare(strict_types=1);

namespace Piwigo\Event\Picture;

/**
 * Typed event for legacy `allow_increment_element_hit_count` (dispatch).
 *
 * Dispatched from: src/Piwigo/Controller/PictureController.php
 */
final readonly class AllowIncrementElementHitCount
{
    public function __construct(
        public bool $contentNotSet,
    ) {
    }

    public function withContentNotSet(bool $contentNotSet): self
    {
        return new self($contentNotSet);
    }
}
