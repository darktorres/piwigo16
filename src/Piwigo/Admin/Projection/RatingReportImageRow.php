<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

/**
 * One `$images` entry of `rating.latte`, built by
 * {@see \Piwigo\Admin\RatingPageRenderer::render()} from a real
 * {@see \Piwigo\Rate\Projection\RatingReportRow} plus a resolved
 * thumbnail/photo url pair, a rating count, and its per-rate
 * {@see \Piwigo\Admin\Projection\RatingReportRateRow} children.
 */
final readonly class RatingReportImageRow
{
    /**
     * @param list<RatingReportRateRow> $rates
     */
    public function __construct(
        public int $id,
        public string $uThumb,
        public string $uUrl,
        public ?float $scoreRate,
        public ?float $avgRate,
        public float $sumRate,
        public int $nbRates,
        public int $nbRatesTotal,
        public string $file,
        public array $rates,
    ) {}
}
