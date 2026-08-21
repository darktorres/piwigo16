<?php

declare(strict_types=1);

namespace Piwigo\Controller\Projection;

use Override;
use Piwigo\Admin\Projection\AlbumSelectorView;
use Piwigo\Asset\AssetContribution;
use Piwigo\Asset\HasPageAssets;
use Piwigo\Core\ExposesPageData;
use Piwigo\Core\View;
use Piwigo\Template\Latte\Attribute\Template;

/**
 * `include/search_filters.inc.latte`'s own typed view -- rendered by
 * {@see \Piwigo\Controller\GalleryController::__invoke()} from {@see
 * \Piwigo\Search\SearchFilterRenderer::render()}'s own {@see
 * \Piwigo\Search\Projection\SearchFilterData}, same L2bExtendedDomain
 * split as {@see ThumbnailsView}/`CategoryDefaultRenderer`.
 *
 * Unlike every other colorbox-family real parent (docs/PLAN.md's
 * P42-B), this View genuinely IS rendered via `Renderer::render()`
 * (see `GalleryController::__invoke()`), so `pageAssets()` fires
 * through the normal hook -- no construct-and-merge trick needed to
 * pick up `AlbumSelectorView`'s own contribution below. This page's
 * own many other `combineCss`/`combineScript`/`exposeData`/
 * `exposeString` call sites are not migrated yet, deliberately, and
 * stay imperative for now; both sources coexist correctly
 * (`PageAssets::add()`'s own dedup contract).
 */
#[Template('include/search_filters.inc.latte')]
final readonly class SearchFiltersView implements View, HasPageAssets, ExposesPageData
{
    /**
     * @param array<string, array<string, mixed>> $displayFilter
     * @param list<array<array-key, mixed>>|null $tags
     * @param list<array<array-key, mixed>>|null $authors
     * @param list<array<array-key, mixed>>|null $addedBy
     * @param array<array-key, mixed>|null $filetypes
     * @param array<array-key, mixed>|null $rating
     * @param array<string, mixed>|null $filesize
     * @param array<array-key, mixed>|null $ratios
     * @param array<string, mixed>|null $height
     * @param array<string, mixed>|null $width
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
        public ?array $filesize,
        public ?array $ratios,
        public ?array $height,
        public ?array $width,
        public ?array $albumsFound,
        public ?array $tagsFound,
        public ?array $listDatePosted,
        public ?array $datePosted,
        public ?array $listDateCreated,
        public ?array $dateCreated,
    ) {}

    /**
     * Only `include/album_selector.inc.latte`'s own contribution.
     *
     * @return list<AssetContribution>
     */
    #[Override]
    public function pageAssets(): array
    {
        return new AlbumSelectorView()
            ->pageAssets();
    }

    /**
     * Only `include/album_selector.inc.latte`'s own contribution --
     * this page's own many other `exposeData`/`exposeString` call
     * sites are not migrated yet, deliberately, and stay imperative
     * for now.
     *
     * @return array<string, string|int|float|bool|null|array<mixed>>
     */
    #[Override]
    public function exposedPageData(): array
    {
        return [];
    }

    /**
     * @return list<string>
     */
    #[Override]
    public function exposedStrings(): array
    {
        return new AlbumSelectorView()
            ->exposedStrings();
    }
}
