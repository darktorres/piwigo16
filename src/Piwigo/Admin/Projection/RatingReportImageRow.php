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

    /**
     * @return array{id: int, U_THUMB: string, U_URL: string, SCORE_RATE: ?float,
     *     AVG_RATE: ?float, SUM_RATE: float, NB_RATES: int, NB_RATES_TOTAL: int,
     *     FILE: string, rates: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'U_THUMB' => $this->uThumb,
            'U_URL' => $this->uUrl,
            'SCORE_RATE' => $this->scoreRate,
            'AVG_RATE' => $this->avgRate,
            'SUM_RATE' => $this->sumRate,
            'NB_RATES' => $this->nbRates,
            'NB_RATES_TOTAL' => $this->nbRatesTotal,
            'FILE' => $this->file,
            'rates' => array_map(static fn (RatingReportRateRow $rate): array => $rate->toArray(), $this->rates),
        ];
    }
}
