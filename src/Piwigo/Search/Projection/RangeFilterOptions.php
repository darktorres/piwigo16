<?php

declare(strict_types=1);

namespace Piwigo\Search\Projection;

/**
 * A search-filter slider's full payload, as
 * {@see \Piwigo\Search\SearchFilterRenderer::render()} builds it for the
 * filesize, height and width filters. All three built the identical
 * `['list' => …, 'bounds' => ['min' => …, 'max' => …], 'selected' => …]`
 * literal, so one class serves all three (P58-A).
 *
 * `$list` is the comma-joined option set the slider is built from --
 * `search_filters.ts` reads it straight off the page data and does
 * `.split(",").map(Number)`, so it stays a single string rather than
 * becoming a list here.
 *
 * `$bounds` is the range the slider spans and `$selected` the sub-range
 * currently chosen; they are deliberately different objects even when they
 * hold equal values, because a search that names no min/max falls back to
 * the bounds and the two then coincide by accident, not by definition.
 */
final readonly class RangeFilterOptions
{
    public function __construct(
        public string $list,
        public RangeBounds $bounds,
        public RangeBounds $selected,
    ) {}

    /**
     * The page-data shape, for `exposedPageData()`. Not a template flatten:
     * this is the JSON boundary, and `search_filters.ts` reads these exact
     * key names back out through `pwg_getPageData('filesize'|'height'|
     * 'width')`, so they are part of a contract with the client and are
     * written out here rather than derived.
     *
     * The values were `int|string|false|null` before this class existed and
     * are `?string` now. That is invisible to the client: every read there
     * is `Number(...)`, and `Number('0') === Number(0)`.
     *
     * @return array{list: string, bounds: array{min: ?string, max: ?string}, selected: array{min: ?string, max: ?string}}
     */
    public function toPageData(): array
    {
        return [
            'list' => $this->list,
            'bounds' => [
                'min' => $this->bounds->min,
                'max' => $this->bounds->max,
            ],
            'selected' => [
                'min' => $this->selected->min,
                'max' => $this->selected->max,
            ],
        ];
    }
}
