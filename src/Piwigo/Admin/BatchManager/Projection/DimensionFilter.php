<?php

declare(strict_types=1);

namespace Piwigo\Admin\BatchManager\Projection;

/**
 * {@see \Piwigo\Admin\BatchManager\FilterResolver::dimensionPhotoIds()}'s
 * parameter object (P17-23 Phase 8, "cross-domain generic-row-reader"
 * elimination) -- `$_SESSION['bulk_manager_filter']['dimension']`'s 6
 * optional bounds. The one real caller (`BatchManagerSubController::
 * computeCurrentSet()`) builds this via {@see self::fromArray()} from the
 * session sub-array it already has raw access to. {@see self::isEmpty()}
 * replaces the old `$where === []` check that used to live inside
 * `dimensionPhotoIds()` itself.
 */
final readonly class DimensionFilter
{
    public function __construct(
        public ?int $minWidth = null,
        public ?int $maxWidth = null,
        public ?int $minHeight = null,
        public ?int $maxHeight = null,
        public ?float $minRatio = null,
        public ?float $maxRatio = null,
    ) {}

    /**
     * @param array<string, mixed> $dimension
     */
    public static function fromArray(array $dimension): self
    {
        return new self(
            minWidth: isset($dimension['min_width']) && is_numeric($dimension['min_width']) ? (int) $dimension['min_width'] : null,
            maxWidth: isset($dimension['max_width']) && is_numeric($dimension['max_width']) ? (int) $dimension['max_width'] : null,
            minHeight: isset($dimension['min_height']) && is_numeric($dimension['min_height']) ? (int) $dimension['min_height'] : null,
            maxHeight: isset($dimension['max_height']) && is_numeric($dimension['max_height']) ? (int) $dimension['max_height'] : null,
            minRatio: isset($dimension['min_ratio']) && is_numeric($dimension['min_ratio']) ? (float) $dimension['min_ratio'] : null,
            maxRatio: isset($dimension['max_ratio']) && is_numeric($dimension['max_ratio']) ? (float) $dimension['max_ratio'] : null,
        );
    }

    public function isEmpty(): bool
    {
        return $this->minWidth === null
            && $this->maxWidth === null
            && $this->minHeight === null
            && $this->maxHeight === null
            && $this->minRatio === null
            && $this->maxRatio === null;
    }
}
