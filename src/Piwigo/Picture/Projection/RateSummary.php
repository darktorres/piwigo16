<?php

declare(strict_types=1);

namespace Piwigo\Picture\Projection;

/**
 * The rating figures `picture.latte` shows beside a photo, built by {@see
 * \Piwigo\Picture\PictureRateRenderer::render()}.
 *
 * `$score` is the photo's own stored average from the images table, which
 * is null until it has been rated at all -- that null is what gates the
 * per-image summary lookup, so `$count` is 0 exactly when `$score` is
 * null. No `$average` field: the renderer used to carry
 * `RateSummaryRow::$average` here and nothing ever read it. The API's own
 * rating payload gets its average straight from
 * {@see \Piwigo\Rate\RateService::getRateSummaryForElement()} instead.
 */
final readonly class RateSummary
{
    public function __construct(
        public int $count,
        public ?float $score,
    ) {}
}
