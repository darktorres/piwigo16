<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

use Override;
use Piwigo\Asset\AssetContribution;
use Piwigo\Asset\HasPageAssets;
use Piwigo\Core\ExposesPageData;
use Piwigo\Core\View;
use Piwigo\Image\DerivativeParams;
use Piwigo\Template\Latte\Attribute\Template;

/**
 * `batch_manager_global.latte`'s own typed view, constructed by {@see
 * \Piwigo\Admin\BatchManagerGlobalPageRenderer::render()}.
 * `$associatedTags`, `$navbar`, and `$thumbParams` are genuinely
 * optional -- all three are only ever computed inside the renderer's own
 * `count($cat_elements_id) > 0` branch. No `$usedMetadata` field -- the
 * template's own body (and `batch_manager_global.js`'s
 * `pwg_getPageData()` reads) never reference it. No `$fAction`/
 * `$start`/`$csrfToken`/`$uDisplay`/`$selection`/`$associatedCategories`/
 * `$allElements` field either -- those are genuinely ambient here:
 * `FilterPanelRenderer::render()` (called earlier in the same request,
 * still on the old assignContext() mechanism) assigns them directly
 * onto the same `Template` instance's `$vars` bag, which
 * `Renderer::render()`'s own ambient merge picks up the same way it
 * does `ROOT_URL`. `$thumbnails` is always included (even empty) since
 * the template reads it with `{if !empty($thumbnails)}`, not `isset()`.
 * Each `$thumbnails` row stays a loose, dynamically `array_merge()`-built
 * shape, same precedent as `PluginsInstalledView::$plugins`.
 */
#[Template('batch_manager_global.latte')]
final readonly class BatchManagerGlobalView implements View, HasPageAssets, ExposesPageData
{
    /**
     * @param list<array{id: int, name: string, url_name: string, lastmodified: string, counter: int}>|null $associatedTags
     * @param array<array-key, string> $levelOptions
     * @param array<string, string> $delDerivativesTypes
     * @param array<string, string> $generateDerivativesTypes
     * @param array{CURRENT_PAGE?: float, URL_FIRST?: string, URL_PREV?: string, URL_NEXT?: string, URL_LAST?: string, pages?: array<int, string>, NB_PAGE?: int}|null $navbar
     * @param array<array-key, string> $cacheKeys
     * @param list<array<string, mixed>> $thumbnails
     */
    public function __construct(
        public bool $inCaddie,
        public ?array $associatedTags,
        public string $dateCreation,
        public array $levelOptions,
        public int $levelOptionsSelected,
        public array $delDerivativesTypes,
        public array $generateDerivativesTypes,
        public ?array $navbar,
        public ?DerivativeParams $thumbParams,
        public int $nbThumbsPage,
        public int $nbThumbsSet,
        public array $cacheKeys,
        public array $thumbnails,
        public string $rootPath,
        public string $jqueryCode,
        public string $colorscheme,
    ) {}

    /**
     * Only `include/datepicker.inc.latte`'s, `include/colorbox.inc.latte`'s,
     * and `include/add_album.inc.latte`'s own contributions -- this
     * page's own many other `combineCss`/`combineScript`/`exposeData`
     * call sites (`docs/PLAN.md`'s P42-B colorbox-family batch) are not
     * migrated yet, deliberately, and stay imperative for now; both
     * sources coexist correctly (`PageAssets::add()`'s own dedup
     * contract).
     *
     * @return list<AssetContribution>
     */
    #[Override]
    public function pageAssets(): array
    {
        return [
            ...new DatepickerView(load_mode: 'async', rootPath: $this->rootPath, jqueryCode: $this->jqueryCode)
                ->pageAssets(),
            ...new ColorboxView(load_mode: 'footer')
                ->pageAssets(),
            ...new AddAlbumView(load_mode: 'async', colorscheme: $this->colorscheme)
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
