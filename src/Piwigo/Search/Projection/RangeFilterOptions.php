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
 * `searchFilters.ts` reads it straight off the page data and does
 * `.split(",").map(Number)`, so it stays a single string rather than
 * becoming a list here.
 *
 * There is deliberately no `$bounds`. The renderer used to compute one and
 * the template used to emit it as `data-min`/`data-max` on each slider's
 * clear button, but nothing ever read either: `pwgDoubleSlider` takes its
 * range from `options.values` (i.e. `$list`) and its position from
 * `options.selected`, and no CSS or script reads the attributes. It was also
 * redundant by construction -- the bounds were exactly the first and last
 * entries of `$list`. The width slider never emitted them at all, which is
 * what made the asymmetry visible (P58-A).
 */
final readonly class RangeFilterOptions
{
    public function __construct(
        public string $list,
        public RangeBounds $selected,
    ) {}

    /**
     * The page-data shape, for `exposedPageData()`. Not a template flatten:
     * this is the JSON boundary, and `searchFilters.ts` reads these exact
     * key names back out through `pwg_getPageData('filesize'|'height'|
     * 'width')`, so they are part of a contract with the client and are
     * written out here rather than derived.
     *
     * The values were `int|string|false|null` before this class existed and
     * are `?string` now. That is invisible to the client: every read there
     * is `Number(...)`, and `Number('0') === Number(0)`.
     *
     * @return array{list: string, selected: array{min: ?string, max: ?string}}
     */
    public function toPageData(): array
    {
        return [
            'list' => $this->list,
            'selected' => [
                'min' => $this->selected->min,
                'max' => $this->selected->max,
            ],
        ];
    }
}
