<?php

declare(strict_types=1);

namespace Piwigo\Search\Projection;

/**
 * {@see \Piwigo\Search\SearchFilterRenderer::render()}'s own return value.
 * `$resolvedSearchId` alone still feeds `GalleryController`'s own
 * `IndexView::$searchId` (unrelated to the search-filter sidebar itself --
 * this is the resolved numeric search id every other part of the gallery
 * page needs) -- `$data` is null exactly when `render()`'s own early
 * return (not a real search-results page) fires, in which case
 * `$resolvedSearchId` is also always null.
 */
final readonly class SearchFilterResult
{
    public function __construct(
        public ?int $resolvedSearchId,
        public ?SearchFilterData $data,
    ) {}
}
