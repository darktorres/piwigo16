<?php

declare(strict_types=1);

namespace Piwigo\Rate\Projection;

/** Paginated rated-image row from RateRepository::findRatedImagesAdminPage(). */
final readonly class RatedImageRow
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
        public int $sumRates,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            id:                is_numeric($row['id'] ?? null) ? (int) $row['id'] : 0,
            path:              is_string($row['path'] ?? null) ? $row['path'] : '',
            file:              is_string($row['file'] ?? null) ? $row['file'] : '',
            representativeExt: is_string($row['representative_ext'] ?? null) ? $row['representative_ext'] : null,
            score:             is_numeric($row['score'] ?? null) ? (float) $row['score'] : null,
            recentlyRated:     is_string($row['recently_rated'] ?? null) ? $row['recently_rated'] : null,
            avgRates:          is_numeric($row['avg_rates'] ?? null) ? (float) $row['avg_rates'] : null,
            nbRates:           is_numeric($row['nb_rates'] ?? null) ? (int) $row['nb_rates'] : 0,
            sumRates:          is_numeric($row['sum_rates'] ?? null) ? (int) $row['sum_rates'] : 0,
        );
    }
}
