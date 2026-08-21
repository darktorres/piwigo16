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
 * `picture_modify.latte`'s own typed view, constructed by {@see
 * \Piwigo\Admin\PictureModifyPageRenderer::render()}. Every remaining
 * optional field stays optional -- the template's own body reads all
 * of them through `isset()` guards, never a bare truthy check, so an
 * always-present `null` behaves identically to the original
 * conditionally-omitted key. No `$title`, `$dimensions`, `$filesize`,
 * `$registrationDate`, `$uCoi`, `$storageCategory`, `$associatedAlbums`,
 * or `$storageAlbum` field -- confirmed dead against both the
 * template's own body and `picture_modify.js`'s `pwg_getPageData()`
 * reads.
 */
#[Template('picture_modify.latte')]
final readonly class PictureModifyView implements View, HasPageAssets, ExposesPageData
{
    /**
     * @param array<int, array{name: mixed, id: string}> $tagSelection
     * @param array<string, mixed> $introVars
     * @param array<array-key, string> $levelOptions
     * @param list<mixed> $levelOptionsSelected
     * @param array<array-key, array{name: string, unlinkable: bool}> $relatedCategories
     * @param list<string> $relatedCategoriesIds
     * @param list<int> $representedAlbums
     * @param array<array-key, string> $cacheKeys
     */
    public function __construct(
        public ?string $saveSuccess,
        public array $tagSelection,
        public string $uDownload,
        public string $uSync,
        public string $uDelete,
        public string $uHistory,
        public string $uActivity,
        public string $path,
        public string $tnSrc,
        public string $fileSrc,
        public string $name,
        public int $format,
        public string $author,
        public string|int|null $dateCreation,
        public string $description,
        public string $fAction,
        public array $introVars,
        public array $levelOptions,
        public array $levelOptionsSelected,
        public array $relatedCategories,
        public array $relatedCategoriesIds,
        public ?string $uJumpto,
        public array $representedAlbums,
        public array $cacheKeys,
        public string $csrfToken,
        public string $rootPath,
        public string $jqueryCode,
    ) {}

    /**
     * Only `include/autosize.inc.latte`'s, `include/datepicker.inc.latte`'s,
     * `include/colorbox.inc.latte`'s, and `include/album_selector.inc.latte`'s
     * own contributions -- this page's own many other
     * `combineCss`/`combineScript` call sites (`docs/PLAN.md`'s P42-B
     * colorbox-family batch) are not migrated yet, deliberately, and
     * stay imperative for now; both sources coexist correctly
     * (`PageAssets::add()`'s own dedup contract).
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
     * this page's own many other `exposeData`/`exposeString` call
     * sites (`docs/PLAN.md`'s P42-B colorbox-family batch) are not
     * migrated yet, deliberately, and stay imperative for now.
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
