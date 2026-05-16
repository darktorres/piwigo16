<?php

declare(strict_types=1);

namespace Piwigo\Event\Picture;

/**
 * Typed event for legacy `update_rating_score` (dispatch).
 *
 * New in 2.6.
 *
 * Dispatched from: src/Piwigo/Rate/RateService.php
 */
final readonly class UpdateRatingScore
{
    public function __construct(
        public bool $done,
        public int $elementId,
    ) {
    }
}
