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
 * template's own body and `picture_modify.ts`'s `pwg_getPageData()`
 * reads.
 */
#[Template('picture_modify.latte')]
final readonly class PictureModifyView implements View, HasPageAssets, ExposesPageData
{
    /**
     * @param array<int, array{name: mixed, id: string}> $tagSelection
     * @param array<array-key, string> $levelOptions
     * @param list<string> $levelOptionsSelected
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
        public bool $isWide,
        public string $author,
        public string|int|null $dateCreation,
        public string $description,
        public string $fAction,
        public PictureIntroVars $introVars,
        public array $levelOptions,
        public array $levelOptionsSelected,
        public array $relatedCategories,
        public array $relatedCategoriesIds,
        public ?string $uJumpto,
        public array $representedAlbums,
        public array $cacheKeys,
        public string $csrfToken,
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
            ...new DatepickerView(jqueryCode: $this->jqueryCode)
                ->pageAssets(),
            // Real per-page bundle entry (docs/PLAN.md's P48) -- folds
            // autosize.ts's and datepicker.ts's own code in via real
            // direct imports instead of the separate script tags
            // DatepickerView used to register directly (both have
            // several real registrant pages, so a plain import isn't
            // safe here -- Design §4). autosize.ts's own `jquery.autogrow`
            // dependency is gone too -- autogrow is a native port now
            // (P49-B group 1).
            AssetContribution::script('picture_modify_page', 'themes/admin/default/js/pages/picture_modify.ts', loadMode: LoadMode::Footer, dependsOn: ['jquery.ui.timepicker-addon']),
            ...new ColorboxView()
                ->pageAssets(),
            AssetContribution::script('jquery.selectize', 'https://cdn.jsdelivr.net/gh/selectize/selectize.js@v0.11.2/dist/js/standalone/selectize.min.js', loadMode: LoadMode::Footer),
            AssetContribution::css('themes/default/js/plugins/selectize.' . $this->colorscheme . '.css', id: 'jquery.selectize'),
            AssetContribution::css('themes/admin/default/css/pages/picture_modify.css', id: 'picture_modify'),
            AssetContribution::script('jquery.confirm', 'https://cdn.jsdelivr.net/npm/jquery-confirm@3.3.4/dist/jquery-confirm.min.js', loadMode: LoadMode::Footer, dependsOn: ['jquery']),
            AssetContribution::css('https://cdn.jsdelivr.net/npm/jquery-confirm@3.3.4/dist/jquery-confirm.min.css'),
            AssetContribution::script('picture_modify', 'themes/admin/default/js/picture_modify.ts', loadMode: LoadMode::Footer, dependsOn: ['jquery.colorbox']),
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
            'Associate to album',
            ...new AlbumSelectorView()
                ->exposedStrings(),
        ];
    }
}
