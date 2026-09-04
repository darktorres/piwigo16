<?php

declare(strict_types=1);

namespace Piwigo\Admin\BatchManager\Projection;

/**
 * {@see \Piwigo\Controller\Admin\BatchManagerSubController::
 * computeDimensionOptions()}'s return shape -- the batch manager's
 * dimension-filter slider options, fed to `batch_manager_filter.inc.latte`.
 * `widths`/`heights`/`ratios` are comma-joined lists of every distinct
 * value (slider tick marks); the 4 `ratio*` categories are each `null`
 * unless at least one distinct ratio falls in that bucket.
 */
final readonly class DimensionFilterOptions
{
    public function __construct(
        public string $widths,
        public string $heights,
        public string $ratios,
        public DimensionBounds $bounds,
        public ?RatioRange $ratioPortrait,
        public ?RatioRange $ratioSquare,
        public ?RatioRange $ratioLandscape,
        public ?RatioRange $ratioPanorama,
        public DimensionBounds $selected,
    ) {}

    /**
     * The `"dimensions"` page-data payload batch_manager/filter.ts reads
     * through `pwg_getPageData()`. Not a template flatten -- the template
     * reads this object's own properties -- so the key names here answer
     * to that one JS consumer and nothing else, and the four `ratio_*`
     * keys stay conditionally *absent* rather than becoming null, which is
     * what the payload has always emitted.
     *
     * @return array<string, mixed>
     */
    public function toPageData(): array
    {
        $result = [
            'widths' => $this->widths,
            'heights' => $this->heights,
            'ratios' => $this->ratios,
            'bounds' => $this->bounds->toArray(),
        ];

        if ($this->ratioPortrait instanceof RatioRange) {
            $result['ratio_portrait'] = $this->ratioPortrait->toArray();
        }

        if ($this->ratioSquare instanceof RatioRange) {
            $result['ratio_square'] = $this->ratioSquare->toArray();
        }

        if ($this->ratioLandscape instanceof RatioRange) {
            $result['ratio_landscape'] = $this->ratioLandscape->toArray();
        }

        if ($this->ratioPanorama instanceof RatioRange) {
            $result['ratio_panorama'] = $this->ratioPanorama->toArray();
        }

        $result['selected'] = $this->selected->toArray();

        return $result;
    }
}
