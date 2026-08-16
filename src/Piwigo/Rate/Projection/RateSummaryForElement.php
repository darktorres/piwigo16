<?php

declare(strict_types=1);

namespace Piwigo\Rate\Projection;

/**
 * {@see \Piwigo\Rate\RateRepository::findRateSummaryForElement()}'s own
 * row shape -- the picture page's rating-summary widget
 * ({@see \Piwigo\Picture\PictureRateRenderer}) and
 * `pwg.images.getInfo`'s own rating block
 * ({@see \Piwigo\Ws\Images\GetInfoHandler}, via
 * {@see \Piwigo\Rate\RateService::getRateSummaryForElement()}).
 */
final readonly class RateSummaryForElement
{
    public function __construct(
        public int $count,
        public ?float $average,
    ) {}
}
