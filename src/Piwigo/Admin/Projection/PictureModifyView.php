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
        public string $colorscheme,
        public string $rootUrl,
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
            AssetContribution::script('LocalStorageCache', 'themes/admin/default/js/LocalStorageCache.js', loadMode: LoadMode::Footer),
            AssetContribution::script('jquery.selectize', 'themes/default/js/plugins/selectize.min.js', loadMode: LoadMode::Footer),
            AssetContribution::css('themes/default/js/plugins/selectize.' . $this->colorscheme . '.css', id: 'jquery.selectize'),
            AssetContribution::css('themes/admin/default/css/pages/picture_modify.css', id: 'picture_modify'),
            AssetContribution::script('jquery.confirm', 'themes/default/js/plugins/jquery-confirm.min.js', loadMode: LoadMode::Footer, dependsOn: ['jquery']),
            AssetContribution::css('themes/default/js/plugins/jquery-confirm.min.css'),
            AssetContribution::script('picture_modify', 'themes/admin/default/js/picture_modify.js', loadMode: LoadMode::Footer, dependsOn: ['jquery.colorbox', 'page-data']),
            // order 10 is required, see issue 1080
            AssetContribution::css('themes/admin/default/fontello/css/animation.css', order: 10),
            ...new AlbumSelectorView()
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
            'cache_key_categories' => $this->cacheKeys['categories'],
            'cache_key_tags' => $this->cacheKeys['tags'],
            'cache_key_hash' => $this->cacheKeys['_hash'],
            'root_url' => $this->rootUrl,
            'u_delete' => $this->uDelete,
            'related_categories_ids' => $this->relatedCategoriesIds,
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
            'Associate to album',
            ...new AlbumSelectorView()
                ->exposedStrings(),
        ];
    }
}
