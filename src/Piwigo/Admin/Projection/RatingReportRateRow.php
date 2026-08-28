<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

/**
 * One `rates` entry of `rating.latte`'s `$images[]['rates']`, built by
 * {@see \Piwigo\Admin\RatingPageRenderer::render()} from a real
 * {@see \Piwigo\Rate\Projection\Rate} row plus one spliced view-only
 * field (`user`, the resolved display name or a `? {id}` fallback).
 */
final readonly class RatingReportRateRow
{
    public function __construct(
        public int $userId,
        public string $anonymousId,
        public int $rate,
        public ?string $date,
        public string $user,
    ) {}
}
