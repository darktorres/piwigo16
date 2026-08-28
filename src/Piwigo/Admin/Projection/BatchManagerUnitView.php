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
use Piwigo\Core\View;
use Piwigo\Template\Latte\Attribute\Template;
use Piwigo\Template\Projection\QuickSearchView;

/**
 * `batch_manager_unit.latte`'s own typed view, constructed by {@see
 * \Piwigo\Admin\BatchManagerUnitPageRenderer::render()}. `$navbar` and
 * `$elementIds` are genuinely optional -- both are only ever computed
 * inside the renderer's own `count($cat_elements_id) > 0` branch. No
 * `$storageCategory` field -- the template's own body never references
 * it (it's overwritten once per matching category across the whole
 * per-image loop, last write wins, and was never actually read). No
 * `$fAction`/`$allElements` field either -- those stay genuinely
 * ambient here: `FilterPanelRenderer::render()` (called earlier in the
 * same request, still on the old assignContext() mechanism) assigns
 * them onto the same `Template` instance's `$vars` bag, which
 * `Renderer::render()`'s own ambient merge picks up same as
 * `ROOT_URL`. `$associatedCategories`/`$filterCategorySelected` are the
 * exceptions -- read back from that same ambient bag right after
 * `FilterPanelRenderer::render()` returns (docs/PLAN.md's P42-B), since
 * `exposedPageData()` below needs their real values.
 * `$filterDimensions`/`$filterFilesize` used to be read back the same
 * way and are now passed to `BatchManagerUnitPageRenderer::render()`
 * directly from the controller that computes them (P58-A): the bag read
 * returned `mixed`, so both had to be laundered through an `is_array()`
 * fallback that could not fail usefully, and they carry real value
 * objects now, not arrays. The last 3 feed
 * `include/batch_manager_filter.inc.latte`'s own registrations, declared
 * directly here rather than via a constructed `BatchManagerFilterView`
 * instance -- that partial's own real markup stays `{include}`d (same
 * reasoning as `AddAlbumView`/`AlbumSelectorView`), but its constructor
 * carries all 13 of its real template properties, and only 4 of them
 * (plus `$colorscheme`, already here) feed its asset/data/string
 * registrations -- not worth a full construction. `$elements` is always included
 * (even empty) since the template reads it with `{if !empty($elements)}`,
 * not `isset()`. Each `$elements` row stays a loose, dynamically
 * `array_merge()`-built shape -- same precedent as
 * `PluginsInstalledView::$plugins`/`ThemesInstalledView::$tplThemes`,
 * not minted into its own DTO here.
 */
#[Template('batch_manager_unit.latte')]
final readonly class BatchManagerUnitView implements View, HasPageAssets, ExposesPageData
{
    /**
     * @param array<array-key, string> $levelOptions
     * @param list<string> $activePlugins
     * @param array{CURRENT_PAGE?: float, URL_FIRST?: string, URL_PREV?: string, URL_NEXT?: string, URL_LAST?: string, pages?: array<int, string>, NB_PAGE?: int}|null $navbar
     * @param array<array-key, string> $cacheKeys
     * @param list<array<string, mixed>> $elements
     * @param array<array-key, mixed> $associatedCategories
     */
    public function __construct(
        public string $uElementsPage,
        public array $levelOptions,
        public string $csrfToken,
        public array $activePlugins,
        public int $perPage,
        public ?array $navbar,
        public ?string $elementIds,
        public array $cacheKeys,
        public array $elements,
        public string $jqueryCode,
        public string $colorscheme,
        public string $rootUrl,
        public array $associatedCategories,
        public DimensionFilterOptions $filterDimensions,
        public FilesizeFilterOptions $filterFilesize,
        public ?int $filterCategorySelected,
    ) {}

    /**
     * @return list<AssetContribution>
     */
    #[Override]
    public function pageAssets(): array
    {
        return [
            ...new AutosizeView()
                ->pageAssets(),
            ...new DatepickerView(jqueryCode: $this->jqueryCode)
                ->pageAssets(),
            // Real per-page bundle entry (docs/PLAN.md's P48) -- folds
            // autosize.ts's, datepicker.ts's, and scripts.ts's own code
            // in via direct imports instead of the separate script
            // tags AutosizeView/DatepickerView/this method's own former
            // `core.scripts` registration used to register directly
            // (all 3 have several real registrant pages, so a plain
            // import isn't safe here -- Design §4).
            AssetContribution::script('batch_manager_unit_page', 'themes/admin/default/js/pages/batch_manager_unit.ts', loadMode: LoadMode::Footer, dependsOn: ['jquery.autogrow', 'jquery.ui.timepicker-addon']),
            ...new ColorboxView()
                ->pageAssets(),
            AssetContribution::script('jquery.sort', 'themes/default/js/plugins/jquery.sort.js', loadMode: LoadMode::Footer),
            AssetContribution::script('jquery.confirm', 'https://cdn.jsdelivr.net/npm/jquery-confirm@3.3.4/dist/jquery-confirm.min.js', loadMode: LoadMode::Footer, dependsOn: ['jquery']),
            AssetContribution::css('https://cdn.jsdelivr.net/npm/jquery-confirm@3.3.4/dist/jquery-confirm.min.css'),
            // order 10 is required, see issue 1080
            AssetContribution::css('themes/admin/default/fontello/css/animation.css', order: 10),
            AssetContribution::script('jquery.selectize', 'https://cdn.jsdelivr.net/gh/selectize/selectize.js@v0.11.2/dist/js/standalone/selectize.min.js', loadMode: LoadMode::Footer),
            AssetContribution::css('themes/default/js/plugins/selectize.' . $this->colorscheme . '.css', id: 'jquery.selectize'),
            AssetContribution::script('batchManagerUnit', 'themes/admin/default/js/batchManagerUnit.ts', loadMode: LoadMode::Footer, dependsOn: ['jquery.ui', 'jquery.sort', 'jquery.selectize', 'jquery.colorbox']),
            AssetContribution::css('themes/admin/default/css/pages/batch_manager_unit.css', id: 'batch_manager_unit'),
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
            // contribution has to resolve first -- see
            // BatchManagerGlobalView's own identical comment, confirmed
            // there via a real golden-html diff.
            AssetContribution::script('jquery.selectize', 'https://cdn.jsdelivr.net/gh/selectize/selectize.js@v0.11.2/dist/js/standalone/selectize.min.js'),
            AssetContribution::css('themes/default/js/plugins/selectize.' . $this->colorscheme . '.css', id: 'jquery.selectize'),
            AssetContribution::script('jquery.ui', '', loadMode: LoadMode::Async),
            AssetContribution::css('https://cdnjs.cloudflare.com/ajax/libs/jqueryui/1.10.4/css/jquery-ui.css', id: 'jquery.ui'),
            AssetContribution::script('batchManagerFilter', 'themes/admin/default/js/batchManagerFilter.ts', loadMode: LoadMode::Footer),
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
     * `all_related_categories_ids` replicates the template's own
     * `{foreach $elements as $element}{do $all_selected_album[$element['ID']]
     * = json_decode($element['related_category_ids'])}{/foreach}` loop
     * exactly -- a real derived value, not a fixed literal, covered by
     * its own unit test.
     *
     * @return array<string, string|int|float|bool|null|array<mixed>>
     */
    #[Override]
    public function exposedPageData(): array
    {
        $allSelectedAlbum = [];
        foreach ($this->elements as $element) {
            $id = $element['ID'] ?? null;
            if (! is_string($id) && ! is_int($id)) {
                continue;
            }

            $relatedCategoryIds = $element['related_category_ids'] ?? null;
            $allSelectedAlbum[$id] = json_decode(is_string($relatedCategoryIds) ? $relatedCategoryIds : '');
        }

        return [
            'active_plugins' => $this->activePlugins,
            'cache_key_tags' => $this->cacheKeys['tags'],
            'cache_key_categories' => $this->cacheKeys['categories'],
            'cache_key_hash' => $this->cacheKeys['_hash'],
            'root_url' => $this->rootUrl,
            'associated_categories' => $this->associatedCategories,
            'dimensions' => $this->filterDimensions->toPageData(),
            'filesize' => $this->filterFilesize->toPageData(),
            'filter_category_selected' => $this->filterCategorySelected,
            'all_related_categories_ids' => $allSelectedAlbum,
            // Real pre-existing gap, found via album_selector.ts's own
            // P48 module conversion (docs/PLAN.md): this page embeds
            // AlbumSelectorView, whose real `#create_album()` reads the
            // CSRF token via `pwg_getPageData<string>("csrf_token")` --
            // never exposed here, so its own X-CSRF-Token header has
            // been `undefined` on this page at runtime.
            'csrf_token' => $this->csrfToken,
        ];
    }

    /**
     * `'Are you sure?'`/`'No, I have changed my mind'` already covered
     * unconditionally by `ThemeBaseAssets`'s own confirm-dialog triplet
     * (docs/PLAN.md's P42) -- dropped, not ported.
     *
     * @return list<string>
     */
    #[Override]
    public function exposedStrings(): array
    {
        return [
            'Create',
            'Cancel',
            'Yes, delete',
            'This photo is an orphan',
            'Warning ! Unsaved changes will be lost',
            'I want to continue',
            'Associate to album',
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
