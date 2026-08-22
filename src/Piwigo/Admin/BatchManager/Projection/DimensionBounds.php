<?php

declare(strict_types=1);

namespace Piwigo\Admin\BatchManager\Projection;

/**
 * {@see \Piwigo\Controller\Admin\BatchManagerSubController::
 * computeDimensionOptions()}'s own `bounds`/`selected` sub-shape -- both
 * fields share this exact 6-field layout.
 */
final readonly class DimensionBounds
{
    public function __construct(
        public int $minWidth,
        public int $maxWidth,
        public int $minHeight,
        public int $maxHeight,
        public float $minRatio,
        public float $maxRatio,
    ) {}

    /**
     * @return array{min_width: int, max_width: int, min_height: int, max_height: int, min_ratio: float, max_ratio: float}
     */
    public function toArray(): array
    {
        return [
            'min_width' => $this->minWidth,
            'max_width' => $this->maxWidth,
            'min_height' => $this->minHeight,
            'max_height' => $this->maxHeight,
            'min_ratio' => $this->minRatio,
            'max_ratio' => $this->maxRatio,
        ];
    }
}
