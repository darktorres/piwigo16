<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

use Override;
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
 * `ROOT_URL`. `$associatedCategories`/`$filterDimensions`/
 * `$filterFilesize`/`$filterCategorySelected` are the exceptions -- read
 * back from that same ambient bag right after `FilterPanelRenderer::
 * render()` returns (docs/PLAN.md's P42-B), since `exposedPageData()`
 * below needs their real values. The last 3 feed
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
     * @param array<array-key, mixed> $filterDimensions
     * @param array<array-key, mixed> $filterFilesize
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
        public string $rootPath,
        public string $jqueryCode,
        public string $colorscheme,
        public string $rootUrl,
        public array $associatedCategories,
        public array $filterDimensions,
        public array $filterFilesize,
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
            ...new DatepickerView(rootPath: $this->rootPath, jqueryCode: $this->jqueryCode)
                ->pageAssets(),
            ...new ColorboxView()
                ->pageAssets(),
            AssetContribution::script('jquery.sort', 'themes/default/js/plugins/jquery.sort.js', loadMode: LoadMode::Footer),
            AssetContribution::script('jquery.confirm', 'themes/default/js/plugins/jquery-confirm.min.js', loadMode: LoadMode::Footer, dependsOn: ['jquery']),
            AssetContribution::css('themes/default/js/plugins/jquery-confirm.min.css'),
            // order 10 is required, see issue 1080
            AssetContribution::css('themes/admin/default/fontello/css/animation.css', order: 10),
            AssetContribution::script('jquery.selectize', 'themes/default/js/plugins/selectize.min.js', loadMode: LoadMode::Footer),
            AssetContribution::css('themes/default/js/plugins/selectize.' . $this->colorscheme . '.css', id: 'jquery.selectize'),
            AssetContribution::script('LocalStorageCache', 'themes/admin/default/js/LocalStorageCache.js', loadMode: LoadMode::Footer),
            AssetContribution::script('batchManagerUnit', 'themes/admin/default/js/batchManagerUnit.js', loadMode: LoadMode::Footer, dependsOn: ['jquery.ui.effect-blind', 'jquery.sort', 'jquery.selectize', 'LocalStorageCache', 'jquery.colorbox', 'page-data']),
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
            AssetContribution::script('doubleSlider', 'themes/admin/default/js/doubleSlider.js', loadMode: LoadMode::Footer, dependsOn: ['jquery.ui.slider']),
            AssetContribution::script('jquery.selectize', 'themes/default/js/plugins/selectize.min.js'),
            AssetContribution::css('themes/default/js/plugins/selectize.' . $this->colorscheme . '.css', id: 'jquery.selectize'),
            AssetContribution::script('jquery.ui.slider', 'themes/default/js/ui/minified/jquery.ui.slider.min.js', loadMode: LoadMode::Async, dependsOn: ['jquery.ui']),
            AssetContribution::css('themes/default/js/ui/theme/jquery.ui.slider.css'),
            AssetContribution::script('LocalStorageCache', 'themes/admin/default/js/LocalStorageCache.js', loadMode: LoadMode::Footer),
            AssetContribution::script('batchManagerFilter', 'themes/admin/default/js/batchManagerFilter.js', loadMode: LoadMode::Footer, dependsOn: ['page-data']),
            AssetContribution::css('themes/admin/default/css/components/batch_manager_filter.css', id: 'batch_manager_filter'),
            // quick_search.latte's own contribution, reached via
            // batch_manager_filter.inc.latte's own {include} of it --
            // resolves after batch_manager_filter's own registrations
            // above, matching the accepted golden-html baseline.
            ...new QuickSearchView(is_dark_mode: $this->colorscheme === 'dark')
                ->pageAssets(),
            AssetContribution::script('core.scripts', 'themes/default/js/scripts.js', loadMode: LoadMode::Async),
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
            'dimensions' => $this->filterDimensions,
            'filesize' => $this->filterFilesize,
            'filter_category_selected' => $this->filterCategorySelected,
            'all_related_categories_ids' => $allSelectedAlbum,
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
