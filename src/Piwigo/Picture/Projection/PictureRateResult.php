<?php

declare(strict_types=1);

namespace Piwigo\Picture\Projection;

/**
 * {@see \Piwigo\Picture\PictureRateRenderer::render()}'s own return
 * value -- threaded into `PictureController`'s own View construction.
 * `$rateSummary` is null only when `rateEnabled` is off; `$rating` is
 * null whenever the current visitor isn't allowed to rate (both are
 * independent gates, not mutually exclusive).
 */
final readonly class PictureRateResult
{
    /**
     * @param array<string, mixed>|null $rateSummary
     * @param array{F_ACTION: string, USER_RATE: ?int, marks: list<int>}|null $rating
     */
    public function __construct(
        public ?array $rateSummary,
        public ?array $rating,
    ) {}
}
