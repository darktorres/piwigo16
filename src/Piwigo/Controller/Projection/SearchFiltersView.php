<?php

declare(strict_types=1);

namespace Piwigo\Controller\Projection;

use Override;
use Piwigo\Admin\Projection\AlbumSelectorView;
use Piwigo\Admin\Projection\ColorboxView;
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
        public string $csrfToken,
    ) {}

    /**
     * @return list<AssetContribution>
     */
    #[Override]
    public function pageAssets(): array
    {
        return [
            AssetContribution::script('jquery.ui', '', loadMode: LoadMode::Async),
            AssetContribution::css('https://cdnjs.cloudflare.com/ajax/libs/jqueryui/1.10.4/css/jquery-ui.css', id: 'jquery.ui', order: -999),
            AssetContribution::script('jquery.selectize', 'https://cdn.jsdelivr.net/gh/selectize/selectize.js@v0.11.2/dist/js/standalone/selectize.min.js', loadMode: LoadMode::Footer),
            // order 10 is required, see issue 1080
            AssetContribution::css('themes/admin/default/fontello/css/animation.css', order: 10),
            // Genuine pre-existing bug, unrelated to any P46 JS->TS
            // conversion work (confirmed via git blame -- this line
            // predates it): missing `dependsOn: ['jquery']` meant this
            // Header-loaded CDN script could run before jQuery itself
            // had loaded, throwing `ReferenceError: jQuery is not
            // defined` on every real page load. Found via a real
            // browser console error, not a type/lint tool.
            AssetContribution::script('jquery.tipTip', 'https://cdn.jsdelivr.net/gh/drewwilson/TipTip@277e33629e/jquery.tipTip.minified.js', loadMode: LoadMode::Header, dependsOn: ['jquery']),
            AssetContribution::css('themes/default/css/search.css', order: -100),
            AssetContribution::css('themes/default/css/' . $this->colorscheme . '-search.css', order: -100),
            AssetContribution::css('themes/default/vendor/fontello/css/gallery-icon.css', order: -10),
            // search_filters.ts's own registration dropped (docs/PLAN.md
            // P48, search_filters.ts's own batch) -- mcs.ts is its one
            // real consumer file anywhere, so its code folds directly
            // into mcs.ts's own bundle via a plain `import` instead
            // (Design §4: exactly one real reaching entry). Real,
            // accepted timing change: search_filters.ts's own data-setup
            // code ran at this page's Footer before, now runs at mcs.ts's
            // own Async instead -- safe since search_filters.ts has zero
            // independent runtime behavior of its own (pure
            // `pwg_getPageData()`/`pwg_getPageString()` value setup, no
            // event handlers, no template reads it directly either).
            // 'page-data' added to mcs.ts's own registration's dependsOn
            // below (docs/PLAN.md's P48, album_selector.ts's own batch): this
            // file's real `?dup` import of album_selector.ts embeds that
            // file's own top-level `pwg_getPageString()` calls directly
            // into this bundle, a real new dependency on page-data.ts
            // having already run that didn't exist before this batch.
            //
            // Real, necessary fix found via golden-html review, not
            // assumed: doubleSlider.ts's own `.pwgDoubleSlider()` calls
            // jQuery UI's real `.slider()` widget method internally, and
            // that file's own code now folds into this bundle too
            // (docs/PLAN.md P48, doubleSlider.ts's own batch). Before
            // that fold, `doubleSlider`'s own separate registration
            // (`loadMode: Footer, dependsOn: ['jquery.ui']`) structurally
            // guaranteed jquery.ui loaded first via
            // `PageAssets::promoteLoadModes()` ("a dependency can't load
            // more loosely than its dependent"); folding its code into
            // this bundle without carrying that same guarantee forward
            // would have left a real Async-depends-on-Async race (the
            // exact documented anti-pattern `promoteLoadModes()`'s own
            // class docblock already warns about -- promotion only
            // fires on a strict LoadMode difference, so leaving `mcs`
            // itself Async here would silently no-op the dependency).
            // `loadMode: Footer` (was Async) + `dependsOn: ['jquery.ui']`
            // restores the identical real ordering guarantee doubleSlider
            // used to carry on its own.
            AssetContribution::script('mcs', 'themes/default/js/mcs.ts', loadMode: LoadMode::Footer, dependsOn: ['jquery', 'jquery.ui', 'page-data']),
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
