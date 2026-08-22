<?php

declare(strict_types=1);

namespace Piwigo\Admin\BatchManager\Projection;

/**
 * {@see \Piwigo\Controller\Admin\BatchManagerSubController::
 * computeDimensionOptions()}'s own `ratio_portrait`/`ratio_square`/
 * `ratio_landscape`/`ratio_panorama` sub-shape -- each conditionally
 * present, only when at least one distinct ratio falls in that bucket.
 */
final readonly class RatioRange
{
    public function __construct(
        public float $min,
        public float $max,
    ) {}

    /**
     * @return array{min: float, max: float}
     */
    public function toArray(): array
    {
        return [
            'min' => $this->min,
            'max' => $this->max,
        ];
    }
}
