<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

use Override;
use Piwigo\Asset\AssetContribution;
use Piwigo\Asset\HasPageAssets;
use Piwigo\Core\ExposesPageData;
use Piwigo\Core\View;
use Piwigo\Template\Latte\Attribute\Template;

/**
 * `batch_manager_unit.latte`'s own typed view, constructed by {@see
 * \Piwigo\Admin\BatchManagerUnitPageRenderer::render()}. `$navbar` and
 * `$elementIds` are genuinely optional -- both are only ever computed
 * inside the renderer's own `count($cat_elements_id) > 0` branch. No
 * `$storageCategory` field -- the template's own body never references
 * it (it's overwritten once per matching category across the whole
 * per-image loop, last write wins, and was never actually read). No
 * `$fAction`/`$allElements`/`$associatedCategories` field either --
 * those are genuinely ambient here: `FilterPanelRenderer::render()`
 * (called earlier in the same request, still on the old
 * assignContext() mechanism) assigns them onto the same `Template`
 * instance's `$vars` bag, which `Renderer::render()`'s own ambient
 * merge picks up same as `ROOT_URL`. `$elements` is always included
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
    ) {}

    /**
     * Only `include/autosize.inc.latte`'s, `include/datepicker.inc.latte`'s,
     * `include/colorbox.inc.latte`'s, and `include/album_selector.inc.latte`'s
     * own contributions -- this page's own many other
     * `combineCss`/`combineScript` call sites
     * (`docs/PLAN.md`'s P42-B colorbox-family batch) are not migrated
     * yet, deliberately, and stay imperative for now; both sources
     * coexist correctly (`PageAssets::add()`'s own dedup contract).
     *
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
            ...new AlbumSelectorView()
                ->pageAssets(),
        ];
    }

    /**
     * Only `include/album_selector.inc.latte`'s own contribution --
     * this page's own many other `exposeData` call sites
     * (`docs/PLAN.md`'s P42-B colorbox-family batch) are not migrated
     * yet, deliberately, and stay imperative for now.
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
