<?php

declare(strict_types=1);

namespace Piwigo\Rate\Projection;

/**
 * {@see \Piwigo\Rate\RateRepository::findRatingReport()}'s own row shape --
 * {@see \Piwigo\Admin\RatingPageRenderer}'s real (and only) consumer, the
 * admin "Rating" report page.
 *
 * `toArray()` preserves the exact original snake_case shape:
 * {@see \Piwigo\Image\DerivativeImage::thumbUrl()} accepts
 * `array<string, mixed>|SrcImage` (not this DTO), so the report renderer
 * calls `toArray()` before handing a row to it, same boundary-unwrap
 * convention every other Projection in this codebase uses.
 */
final readonly class RatingReportRow
{
    public function __construct(
        public int $id,
        public string $path,
        public string $file,
        public ?string $representativeExt,
        public ?float $score,
        public ?string $recentlyRated,
        public ?float $avgRates,
        public int $nbRates,
        public float $sumRates,
    ) {}

    /**
     * @return array{id: int, path: string, file: string, representative_ext: ?string, score: ?float, recently_rated: ?string, avg_rates: ?float, nb_rates: int, sum_rates: float}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'path' => $this->path,
            'file' => $this->file,
            'representative_ext' => $this->representativeExt,
            'score' => $this->score,
            'recently_rated' => $this->recentlyRated,
            'avg_rates' => $this->avgRates,
            'nb_rates' => $this->nbRates,
            'sum_rates' => $this->sumRates,
        ];
    }
}
