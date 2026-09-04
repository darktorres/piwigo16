<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

use Override;
use Piwigo\Admin\BatchManager\Projection\DimensionFilterOptions;
use Piwigo\Admin\BatchManager\Projection\FilesizeFilterOptions;
use Piwigo\Asset\AssetContribution;
use Piwigo\Asset\HasPageAssets;
use Piwigo\Asset\LoadMode;
use Piwigo\Core\ExposesPageData;
use Piwigo\Core\Projection\Navbar;
use Piwigo\Core\View;
use Piwigo\Image\DerivativeParams;
use Piwigo\Template\Latte\Attribute\Template;
use Piwigo\Template\Projection\QuickSearchView;

/**
 * `batch_manager_global.latte`'s own typed view, constructed by {@see
 * \Piwigo\Admin\BatchManagerGlobalPageRenderer::render()}.
 * `$navbar` is genuinely optional -- only ever computed inside the
 * renderer's own `count($cat_elements_id) > 0` branch. `$associatedTags`
 * was too, and is a plain list now: its two readers both ask only
 * whether there is a tag to offer removing, so `null` (no elements) and
 * `[]` (elements with no tag in common) were the same answer wearing two
 * spellings, and the only thing the nullable bought was an `!empty()`
 * that had to cover both (P58-B2). `$thumbParams` used to be too,
 * and the template called `->maxWidth()` on it unguarded; it is
 * non-nullable now, its `ImageStdParams::getByType()` lookup hoisted out
 * of that branch, since the lookup never depended on the element set
 * (P58-A's §11). No `$usedMetadata` field -- the
 * template's own body (and `batch_manager_global.js`'s
 * `pwg_getPageData()` reads) never reference it. No `$fAction`/
 * `$start`/`$uDisplay`/`$selection` field either -- those
 * stay genuinely ambient here: `FilterPanelRenderer::render()` (called
 * earlier in the same request, still on the old assignContext()
 * mechanism) assigns them directly onto the same `Template` instance's
 * `$vars` bag, which `Renderer::render()`'s own ambient merge picks up
 * the same way it does `ROOT_URL`. `$associatedCategories`/
 * `$allElements`/`$filterCategorySelected` are the exceptions -- read
 * back from that same ambient bag right after `FilterPanelRenderer::
 * render()` returns (docs/PLAN.md's P42-B), since `exposedPageData()`
 * below needs their real values. `$filterDimensions`/`$filterFilesize`
 * used to be read back the same way and are now passed to
 * `BatchManagerGlobalPageRenderer::render()` directly from the controller
 * that computes them (P58-A) -- see `BatchManagerUnitView`'s own
 * docblock. The last 3 feed `include/batch_manager_filter.inc.latte`'s
 * own registrations, declared directly here rather than via a
 * constructed `BatchManagerFilterView` instance -- see
 * `BatchManagerUnitView`'s own docblock for why. `$thumbnails` is
 * always included (even empty) since
 * the template reads it with `{if !empty($thumbnails)}`, not `isset()`.
 * Each `$thumbnails` row is a {@see BatchManagerThumbnail}: the
 * producer used to merge the whole `images` row into it, of which the
 * template read three columns. `$csrfToken`
 * is a real constructor property (not read back from the ambient bag
 * like the 5 above) -- that bag only ever fed the Latte template's own
 * direct reads, never this class's own `exposedPageData()` JSON island,
 * a real pre-existing gap found via album_selector.ts's own P48 module
 * conversion (docs/PLAN.md): this page embeds `AlbumSelectorView`,
 * whose real `#create_album()` reads the CSRF token via
 * `pwg_getPageData<string>("csrf_token")` -- never exposed here before.
 */
#[Template('batch_manager_global.latte')]
final readonly class BatchManagerGlobalView implements View, HasPageAssets, ExposesPageData
{
    /**
     * @param list<array{id: int, name: string, url_name: string, lastmodified: string, counter: int}> $associatedTags
     * @param array<array-key, string> $levelOptions
     * @param array<string, string> $delDerivativesTypes
     * @param array<string, string> $generateDerivativesTypes
     * @param array<array-key, string> $cacheKeys
     * @param list<BatchManagerThumbnail> $thumbnails
     * @param array<array-key, mixed> $associatedCategories
     * @param array<array-key, mixed> $allElements
     */
    public function __construct(
        public bool $inCaddie,
        public array $associatedTags,
        public string $dateCreation,
        public array $levelOptions,
        public int $levelOptionsSelected,
        public array $delDerivativesTypes,
        public array $generateDerivativesTypes,
        public ?Navbar $navbar,
        public DerivativeParams $thumbParams,
        public int $nbThumbsPage,
        public int $nbThumbsSet,
        public array $cacheKeys,
        public array $thumbnails,
        public string $jqueryCode,
        public string $colorscheme,
        public string $rootUrl,
        public array $associatedCategories,
        public array $allElements,
        public DimensionFilterOptions $filterDimensions,
        public FilesizeFilterOptions $filterFilesize,
        public ?int $filterCategorySelected,
        public string $csrfToken,
    ) {}

    /**
     * @return list<AssetContribution>
     */
    #[Override]
    public function pageAssets(): array
    {
        return [
            ...new ColorboxView()
                ->pageAssets(),
            ...new AddAlbumView(colorscheme: $this->colorscheme)
                ->pageAssets(),
            // Real per-page bundle entry (docs/PLAN.md's P48) -- folds
            // addAlbum.ts's, datepicker.ts's, and scripts.ts's own code
            // in via direct imports instead of the separate script
            // tags AddAlbumView/DatepickerView/this method's own former
            // `core.scripts` registration used to register directly
            // (all 3 have several real registrant pages, so a plain
            // import isn't safe here -- Design §4), plus both of this
            // page's own real shared-library files, batchManagerGlobal.ts
            // and batch_manager/global.ts, which this pair's own batch
            // found must merge into ONE bundle at ONE LoadMode (see both
            // files' own leading comments) rather than staying 2
            // separate (page × LoadMode) entries the way most pages'
            // shared-library files do -- batchManagerGlobal.ts has real,
            // unconditional page-load side effects (event-handler
            // registration), so it can never be safely duplicated the
            // way addAlbum.ts's direct import is. Footer (not
            // batchManagerGlobal.ts's former Async) is the merged mode,
            // matching batch_manager/global.ts's own former mode. No more
            // `jquery.ui.timepicker-addon` dependency -- datepicker.ts is
            // fully native now too (P49-B); `jquery-ui.css` below (kept
            // for its own sake, not via that former dependency's
            // cascade-promotion) still themes the native port's reused
            // class names. No plain `dependsOn: ['jquery']` either
            // (P49-C) -- confirmed zero real jQuery calls left anywhere
            // in `batchManagerGlobal.ts`/`batch_manager/global.ts`
            // (`pwgAddAlbum` is a real named ES import now, not a
            // `jQuery.fn.pwgAddAlbum` plugin registration). The
            // `jquery.progressBar` script is dropped outright too --
            // `progress_bar()`'s own real implementation is plain
            // `css()` (`vendor/utils/dom.ts`), never that plugin's own real
            // `.progressBar()` API, confirmed via a repo-wide grep.
            AssetContribution::script('batch_manager_global_page', 'themes/admin/default/js/pages/batch_manager_global.ts', loadMode: LoadMode::Footer),
            AssetContribution::css('themes/admin/default/css/pages/batch_manager_global.css', id: 'batch_manager_global'),
            AssetContribution::css('https://cdn.jsdelivr.net/npm/jquery-confirm@3.3.4/dist/jquery-confirm.min.css'),
            // order 10 is required, see issue 1080
            AssetContribution::css('themes/admin/default/fontello/css/animation.css', order: 10),
            ...new AlbumSelectorView()
                ->pageAssets(),
            // include/batch_manager_filter.inc.latte's own registrations.
            // Both this and AlbumSelectorView are now fully declarative,
            // so their relative order is this array's own order, not
            // either partial's old textual {include} position --
            // batch_manager_filter.inc.latte itself {include}s
            // album_selector.inc.latte internally (a bare relative path,
            // the same nested-include shape documented on
            // ColorboxView/AddAlbumView), so album_selector's own
            // contribution has to resolve first, matching the accepted
            // golden-html baseline confirmed by a real diff, not assumed.
            AssetContribution::css('themes/default/js/plugins/selectize.' . $this->colorscheme . '.css', id: 'jquery.selectize'),
            AssetContribution::css('https://cdnjs.cloudflare.com/ajax/libs/jqueryui/1.10.4/css/jquery-ui.css', id: 'jquery.ui'),
            AssetContribution::css('https://cdn.jsdelivr.net/gh/trentrichardson/jQuery-Timepicker-Addon@v1.4.4/dist/jquery-ui-timepicker-addon.min.css'),
            AssetContribution::script('batchManagerFilter', 'themes/admin/default/js/batch_manager/filter.ts', loadMode: LoadMode::Footer),
            AssetContribution::css('themes/admin/default/css/components/batch_manager_filter.css', id: 'batch_manager_filter'),
            // quick_search.latte's own contribution, reached via
            // batch_manager_filter.inc.latte's own {include} of it --
            // resolves after batch_manager_filter's own registrations
            // above, matching the accepted golden-html baseline.
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
        return [
            'cache_key_tags' => $this->cacheKeys['tags'],
            'cache_key_categories' => $this->cacheKeys['categories'],
            'cache_key_hash' => $this->cacheKeys['_hash'],
            'root_url' => $this->rootUrl,
            'jquery_code' => $this->jqueryCode,
            'associated_categories' => $this->associatedCategories,
            'nb_thumbs_page' => $this->nbThumbsPage,
            'nb_thumbs_set' => $this->nbThumbsSet,
            'all_elements' => $this->allElements,
            'dimensions' => $this->filterDimensions->toPageData(),
            'filesize' => $this->filterFilesize->toPageData(),
            'filter_category_selected' => $this->filterCategorySelected,
            'csrf_token' => $this->csrfToken,
        ];
    }

    /**
     * `'Are you sure?'` already covered unconditionally by
     * `ThemeBaseAssets`'s own confirm-dialog triplet (docs/PLAN.md's
     * P42) -- dropped, not ported.
     *
     * @return list<string>
     */
    #[Override]
    public function exposedStrings(): array
    {
        return [
            'Cancel',
            'Deletion in progress',
            'Synchronization in progress',
            'Generate multiple size images',
            'Create',
            'on the %d selected photos',
            '%d of %d photos selected',
            'No photo selected, %d photos in current set',
            'All %d photos are selected',
            'Add Album',
            'Select an album',
            ...new AlbumSelectorView()
                ->exposedStrings(),
            // include/batch_manager_filter.inc.latte's own strings --
            // see pageAssets()'s own comment for why these resolve after
            // AlbumSelectorView's, not before.
            'between %d and %d pixels',
            'between %.2f and %.2f',
            'between %s and %s MB',
            'Select at least one album',
            'Select at least one tag',
        ];
    }
}
