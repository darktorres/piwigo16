<?php

declare(strict_types=1);

namespace Piwigo\Controller\Projection;

use Override;
use Piwigo\Admin\Projection\AlbumSelectorView;
use Piwigo\Asset\AssetContribution;
use Piwigo\Asset\HasPageAssets;
use Piwigo\Asset\LoadMode;
use Piwigo\Core\ExposesPageData;
use Piwigo\Core\View;
use Piwigo\Template\Latte\Attribute\Template;
use Piwigo\Template\Projection\QuickSearchView;

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
 * pick up `AlbumSelectorView`'s own contribution below.
 *
 * `$colorscheme`/`$userRank` replace this file's own
 * `$themeconf['colorscheme']`/`is_admin('')`/`is_classic_user('')`
 * ambient reads -- `GalleryController::__invoke()` resolves both the
 * same way `Template`/`PiwigoExtension` themselves do.
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
        public string $colorscheme,
        public string $userRank,
    ) {}

    /**
     * @return list<AssetContribution>
     */
    #[Override]
    public function pageAssets(): array
    {
        return [
            AssetContribution::script('jquery.ui', 'themes/default/js/ui/minified/jquery.ui.core.min.js', loadMode: LoadMode::Async),
            AssetContribution::script('jquery.ui.slider', 'themes/default/js/ui/minified/jquery.ui.slider.min.js', loadMode: LoadMode::Async, dependsOn: ['jquery.ui']),
            AssetContribution::css('themes/default/js/ui/theme/jquery.ui.slider.css', order: -999),
            AssetContribution::script('doubleSlider', 'themes/admin/default/js/doubleSlider.js', loadMode: LoadMode::Footer, dependsOn: ['jquery.ui.slider']),
            AssetContribution::script('jquery.selectize', 'themes/default/js/plugins/selectize.min.js', loadMode: LoadMode::Footer),
            // order 10 is required, see issue 1080
            AssetContribution::css('themes/admin/default/fontello/css/animation.css', order: 10),
            AssetContribution::script('jquery.tipTip', 'themes/default/js/plugins/jquery.tipTip.minified.js', loadMode: LoadMode::Header),
            AssetContribution::css('themes/default/css/search.css', order: -100),
            AssetContribution::css('themes/default/css/' . $this->colorscheme . '-search.css', order: -100),
            AssetContribution::css('themes/default/vendor/fontello/css/gallery-icon.css', order: -10),
            AssetContribution::script('search_filters', 'themes/default/js/search_filters.js', loadMode: LoadMode::Footer, dependsOn: ['page-data']),
            AssetContribution::script('mcs', 'themes/default/js/mcs.js', loadMode: LoadMode::Async, dependsOn: ['jquery']),
            ...new AlbumSelectorView()
                ->pageAssets(),
            ...new QuickSearchView(is_dark_mode: $this->colorscheme === 'dark')
                ->pageAssets(),
        ];
    }

    /**
     * @return array<string, string|int|float|bool|null|array<mixed>>
     */
    #[Override]
    public function exposedPageData(): array
    {
        $data = [
            'global_params_json' => $this->gp,
            'user_rank' => $this->userRank,
            'show_filter_ratings' => $this->showFilterRatings,
        ];

        if ($this->fullnameOf !== null) {
            $data['fullname_of_cat_json'] = $this->fullnameOf;
        }
        if ($this->searchId !== null) {
            $data['search_id'] = $this->searchId;
        }
        if ($this->filesize !== null) {
            $data['filesize'] = $this->filesize;
        }
        if ($this->height !== null) {
            $data['height'] = $this->height;
        }
        if ($this->width !== null) {
            $data['width'] = $this->width;
        }

        return $data;
    }

    /**
     * @return list<string>
     */
    #[Override]
    public function exposedStrings(): array
    {
        return [
            'Search for words',
            'Tag',
            'Album',
            'Author',
            'Added by',
            'File type',
            'Rating',
            'no rate',
            'between %d and %d',
            'Filesize',
            'Width',
            'Height',
            'Ratio',
            'Portrait',
            'square',
            'Landscape',
            'Panorama',
            'Expert mode',
            'Fill in the filters to start a search',
            'Pre-established filters are proposed, but you can add or remove them using the "Choose filters" button.',
            'Search in albums',
            'between %s and %s MB',
            'between %d and %d pixels',
            ...new AlbumSelectorView()
                ->exposedStrings(),
        ];
    }
}
