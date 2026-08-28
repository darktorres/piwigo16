<?php

declare(strict_types=1);

namespace Piwigo\Search\Projection;

/**
 * The full search-filter-sidebar payload {@see
 * \Piwigo\Search\SearchFilterRenderer::render()} computes whenever the
 * current page is a real search results page -- bundled by {@see
 * SearchFilterResult}. Every optional field here is genuinely optional --
 * the original code only ever assigned each of these template keys under
 * its own runtime condition (a filter's own `access`/
 * `isset($searchFields[...])` gate, or a "found any" count check for
 * `albumsFound`/`tagsFound`/the date-filter pairs) -- `null` here plays
 * the same "not present" role `isset($x)` already treats identically to a
 * genuinely-undefined template variable.
 *
 * @see \Piwigo\Controller\Projection\SearchFiltersView the same 19 fields,
 *   as the actual `#[Template]`-carrying render target -- kept as two
 *   separate classes because `Piwigo\Search\*` is L2bExtendedDomain and
 *   may not depend on `Renderer`/`View` (L3Presentation) directly.
 */
final readonly class SearchFilterData
{
    /**
     * @param array<string, array<string, mixed>> $displayFilter
     * @param list<array<array-key, mixed>>|null $tags
     * @param list<array<array-key, mixed>>|null $authors
     * @param list<array<array-key, mixed>>|null $addedBy
     * @param array<array-key, mixed>|null $filetypes
     * @param array<array-key, mixed>|null $rating
     * @param array<array-key, mixed>|null $ratios
     * @param list<string>|null $albumsFound
     * @param list<string>|null $tagsFound
     * @param array<array-key, mixed>|null $listDatePosted
     * @param array<string, array{label: string, counter: mixed}>|null $datePosted
     * @param array<array-key, mixed>|null $listDateCreated
     * @param array<string, array{label: string, counter: mixed}>|null $dateCreated
     */
    public function __construct(
        public array $displayFilter,
        public bool $showFilterRatings,
        public string|false $gp,
        public ?string $searchId,
        public ?array $tags,
        public ?array $authors,
        public ?array $addedBy,
        public string|false|null $fullnameOf,
        public ?array $filetypes,
        public ?array $rating,
        public ?RangeFilterOptions $filesize,
        public ?array $ratios,
        public ?RangeFilterOptions $height,
        public ?RangeFilterOptions $width,
        public ?array $albumsFound,
        public ?array $tagsFound,
        public ?array $listDatePosted,
        public ?array $datePosted,
        public ?array $listDateCreated,
        public ?array $dateCreated,
    ) {}
}
