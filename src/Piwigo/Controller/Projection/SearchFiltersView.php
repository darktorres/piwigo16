<?php

declare(strict_types=1);

namespace Piwigo\Controller\Projection;

use Latte\Runtime\Html;
use Override;
use Piwigo\Admin\Projection\AlbumSelectorView;
use Piwigo\Admin\Projection\ColorboxView;
use Piwigo\Asset\AssetContribution;
use Piwigo\Asset\HasPageAssets;
use Piwigo\Asset\LoadMode;
use Piwigo\Config\FilterViewDefinition;
use Piwigo\Core\ExposesPageData;
use Piwigo\Core\View;
use Piwigo\Search\Projection\AddedByFilterCount;
use Piwigo\Search\Projection\AuthorFilterCount;
use Piwigo\Search\Projection\DateFilterOptions;
use Piwigo\Search\Projection\RangeFilterOptions;
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
     * @param array<string, FilterViewDefinition> $displayFilter
     * @param list<array<array-key, mixed>>|null $tags
     * @param list<AuthorFilterCount>|null $authors
     * @param list<AddedByFilterCount>|null $addedBy
     * @param array<array-key, int>|null $filetypes
     * @param array<int, int>|null $rating
     * @param array<array-key, int>|null $ratios
     * @param list<Html>|null $albumsFound
     * @param list<Html>|null $tagsFound
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
        public ?DateFilterOptions $datePostedFilter,
        public ?DateFilterOptions $dateCreatedFilter,
        public string $colorscheme,
        public string $userRank,
        public string $csrfToken,
    ) {}

    /**
     * @return list<AssetContribution>
     */
    #[Override]
    public function pageAssets(): array
    {
        return [
            // jQuery UI's own script is gone -- doubleSlider.ts's
            // `.pwgDoubleSlider()` (below) was this page's one real
            // reason to load it, and that's a native port now (P49-B
            // group 4). The CSS theme stays, unconditional and
            // real: the native slider port renders the identical
            // `ui-slider`/`ui-slider-handle`/... class structure it
            // styles.
            AssetContribution::css('https://cdnjs.cloudflare.com/ajax/libs/jqueryui/1.10.4/css/jquery-ui.css', id: 'jquery.ui', order: -999),
            // order 10 is required, see issue 1080
            AssetContribution::css('themes/admin/default/fontello/css/animation.css', order: 10),
            AssetContribution::css('themes/default/css/search.css', order: -100),
            AssetContribution::css('themes/default/css/' . $this->colorscheme . '-search.css', order: -100),
            AssetContribution::css('themes/default/vendor/fontello/css/gallery-icon.css', order: -10),
            // searchFilters.ts's own registration dropped (docs/PLAN.md
            // P48, searchFilters.ts's own batch) -- mcs.ts is its one
            // real consumer file anywhere, so its code folds directly
            // into mcs.ts's own bundle via a plain `import` instead
            // (Design §4: exactly one real reaching entry). Real,
            // accepted timing change: searchFilters.ts's own data-setup
            // code ran at this page's Footer before, now runs at mcs.ts's
            // own Async instead -- safe since searchFilters.ts has zero
            // independent runtime behavior of its own (pure
            // `pwg_getPageData()`/`pwg_getPageString()` value setup, no
            // event handlers, no template reads it directly either).
            // 'page-data' added to mcs.ts's own registration's dependsOn
            // below (docs/PLAN.md's P48, album_selector.ts's own batch): this
            // file's real direct import of album_selector.ts embeds that
            // file's own top-level `pwg_getPageString()` calls directly
            // into this bundle, a real new dependency on pageData.ts
            // having already run that didn't exist before this batch.
            //
            // `loadMode: Footer` (was Async): doubleSlider.ts's own
            // `.pwgDoubleSlider()` calls, and that file's own code now
            // folds into this bundle too (docs/PLAN.md P48, doubleSlider.
            // ts's own batch). `dependsOn: ['jquery.ui']` -- which used to
            // sit here for exactly that reason, ordering this bundle
            // after jQuery UI's real `.slider()` widget method -- is
            // gone: `.pwgDoubleSlider()` is a native port now (P49-B
            // group 4), so nothing on this page needs jQuery UI's script
            // to have loaded at all any more (its CSS theme, above,
            // still does). No plain `dependsOn: ['jquery']` either
            // (P49-C) -- confirmed zero real jQuery calls left anywhere
            // in `mcs.ts` itself.
            AssetContribution::script('mcs', 'themes/default/js/mcs.ts', loadMode: LoadMode::Footer),
            ...new AlbumSelectorView()
                ->pageAssets(),
            ...new QuickSearchView(is_dark_mode: $this->colorscheme === 'dark')
                ->pageAssets(),
            // include/colorbox.inc.latte's own contribution -- reached
            // transitively via album_selector.inc.latte's own nested
            // include (docs/PLAN.md's P42-B) -- resolves last among
            // these 3, matching the accepted golden-html baseline.
            ...new ColorboxView()
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
            // Real pre-existing gap, found via album_selector.ts's own
            // P48 module conversion (docs/PLAN.md): this view embeds
            // AlbumSelectorView, whose real `#create_album()` reads the
            // CSRF token via `pwg_getPageData<string>("csrf_token")` --
            // never exposed here before. Unreachable in practice today
            // (mcs.ts's own `new AlbumSelector(...)` call never passes
            // `adminMode: true`, so `#create_album()` itself is never
            // reachable), but exposed anyway for the same defensive
            // correctness as BatchManagerGlobalView's/PictureModifyView's
            // own copy of this fix.
            'csrf_token' => $this->csrfToken,
        ];

        if ($this->fullnameOf !== null) {
            $data['fullname_of_cat_json'] = $this->fullnameOf;
        }
        if ($this->searchId !== null) {
            $data['search_id'] = $this->searchId;
        }
        if ($this->filesize !== null) {
            $data['filesize'] = $this->filesize->toPageData();
        }
        if ($this->height !== null) {
            $data['height'] = $this->height->toPageData();
        }
        if ($this->width !== null) {
            $data['width'] = $this->width->toPageData();
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
