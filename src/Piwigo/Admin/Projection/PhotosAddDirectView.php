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
 * `photos_add_direct.latte`'s own typed view, constructed by {@see
 * \Piwigo\Admin\PhotosAddDirectPageRenderer::render()}, which merges
 * in its own private `prepareUploadForm()`'s fields too -- both feed
 * this one file. No `$fAddAction`, `$levelOptions`/
 * `$levelOptionsSelected`, or `$cacheKeys` field here -- confirmed dead
 * in `photos_add_direct.latte`'s own real body: the "Manage
 * Permissions" fieldset that would have used the first three is
 * commented out (Smarty-era `{html_options ...}` syntax, not even this
 * project's own `n:foreach`-over-`<option>` convention), and
 * `CACHE_KEYS` has zero real references at all.
 */
#[Template('photos_add_direct.latte')]
final readonly class PhotosAddDirectView implements View, HasPageAssets, ExposesPageData
{
    /**
     * @param array<array-key, mixed>|null $formatsOriginalInfo
     * @param list<int> $selectedCategory
     * @param list<string> $setupErrors
     * @param list<string> $setupWarnings
     */
    public function __construct(
        public bool $promoteMobileApps,
        public string $phpwgUrl,
        public bool $enableFormats,
        public bool $displayFormats,
        public bool $haveFormatsOriginal,
        public ?array $formatsOriginalInfo,
        public string|false|null $formatsExtInfo,
        public string $switchFormatModeUrl,
        public string $formatExt,
        public string $strFormatExt,
        public int $chunkSize,
        public int $maxFileSize,
        public ?float $maxUploadWidth,
        public ?float $maxUploadHeight,
        public ?float $maxUploadResolution,
        public ?int $originalResizeMaxwidth,
        public ?int $originalResizeMaxheight,
        public string $formAction,
        public string $pwgToken,
        public string $uploadFileTypes,
        public string $fileExts,
        public ?string $addToAlbum,
        public ?string $selectedCategoryName,
        public array $selectedCategory,
        public int $nbAlbums,
        public array $setupErrors,
        public array $setupWarnings,
        public ?string $hideWarningsLink,
        public string $colorscheme,
        public string $rootPath,
        public string $pluploadCode,
    ) {}

    /**
     * `plupload_i18n-{code}`'s own `file_exists()` gate is a real
     * derived value, not a fixed literal -- covered by its own unit
     * test, same shape `DatepickerView`'s own `file_exists()` gate
     * already established. `include/add_album.inc.latte`'s own
     * contribution stays conditional on `!$displayFormats`, matching
     * the template's own original `{if}` guard exactly.
     *
     * @return list<AssetContribution>
     */
    #[Override]
    public function pageAssets(): array
    {
        $assets = [
            AssetContribution::script('common', 'themes/admin/default/js/common.js', loadMode: LoadMode::Footer),
            AssetContribution::script('jquery.plupload', 'themes/default/js/plugins/plupload/plupload.full.min.js', loadMode: LoadMode::Footer, dependsOn: ['jquery']),
            AssetContribution::script('jquery.plupload.queue', 'themes/default/js/plugins/plupload/jquery.plupload.queue/jquery.plupload.queue.min.js', loadMode: LoadMode::Footer, dependsOn: ['jquery']),
            AssetContribution::script('tus-js-client', 'themes/default/js/plugins/tus-js-client/tus.min.js', loadMode: LoadMode::Footer),
            AssetContribution::script('jquery.confirm', 'themes/default/js/plugins/jquery-confirm.min.js', loadMode: LoadMode::Footer, dependsOn: ['jquery']),
            AssetContribution::css('themes/default/js/plugins/jquery-confirm.min.css'),
            AssetContribution::css('themes/default/js/plugins/plupload/jquery.plupload.queue/css/jquery.plupload.queue.css'),
        ];

        $pluploadI18n = 'themes/default/js/plugins/plupload/i18n/' . $this->pluploadCode . '.js';
        if (file_exists($this->rootPath . $pluploadI18n)) {
            $assets[] = AssetContribution::script('plupload_i18n-' . $this->pluploadCode, $pluploadI18n, loadMode: LoadMode::Footer, dependsOn: ['jquery.plupload.queue']);
        }

        $assets = [
            ...$assets,
            ...new ColorboxView()
                ->pageAssets(),
        ];

        if (! $this->displayFormats) {
            $assets = [
                ...$assets,
                ...new AddAlbumView(colorscheme: $this->colorscheme)
                    ->pageAssets(),
            ];
        }

        return [
            ...$assets,
            AssetContribution::script('LocalStorageCache', 'themes/admin/default/js/LocalStorageCache.js', loadMode: LoadMode::Footer),
            AssetContribution::script('jquery.selectize', 'themes/default/js/plugins/selectize.min.js', loadMode: LoadMode::Footer),
            AssetContribution::css('themes/default/js/plugins/selectize.' . $this->colorscheme . '.css', id: 'jquery.selectize'),
            AssetContribution::script('piecon', 'themes/default/js/plugins/piecon.js', loadMode: LoadMode::Footer),
            AssetContribution::script('add_photo', 'themes/admin/default/js/photos_add_direct.js', loadMode: LoadMode::Footer, dependsOn: ['tus-js-client', 'page-data']),
            AssetContribution::css('themes/admin/default/css/pages/photos_add_direct.css', id: 'photos_add_direct'),
            ...new AlbumSelectorView()
                ->pageAssets(),
        ];
    }

    /**
     * `original_image_id_str` replicates the template's own `' ' .
     * ($formatsOriginalInfo['id'] ?? -1) . ' '` derivation exactly --
     * a real derived value, covered by its own unit test.
     *
     * @return array<string, string|int|float|bool|null|array<mixed>>
     */
    #[Override]
    public function exposedPageData(): array
    {
        $id = $this->formatsOriginalInfo['id'] ?? -1;
        $originalImageIdStr = ' ' . (is_scalar($id) ? (string) $id : -1) . ' ';

        return [
            'display_formats' => $this->displayFormats,
            'have_formats_original' => $this->haveFormatsOriginal,
            'original_image_id_str' => $originalImageIdStr,
            'formats_ext_info' => $this->formatsExtInfo,
            'nb_albums' => (string) $this->nbAlbums,
            'chunk_size' => $this->chunkSize,
            'max_file_size' => $this->maxFileSize,
            'csrf_token' => $this->pwgToken,
            'file_exts' => $this->fileExts,
            'format_ext' => $this->formatExt,
            'related_categories_ids' => $this->selectedCategory,
        ];
    }

    /**
     * @return list<string>
     */
    #[Override]
    public function exposedStrings(): array
    {
        return [
            'This format already exists, it will be overwritten !',
            'Remove',
            '%d photos uploaded',
            '%d photos updated',
            '%d formats added for %d photos',
            '%d formats updated for %d photos',
            'Manage this set of %d photos',
            'Album "%s" now contains %d photos',
            'Error when trying to detect formats',
            'There is multiple image in the database with the following names : %s.',
            'No picture found with the following name : %s.',
            'and %d more',
            'Upload in progress',
            'Drop into album',
            ...new AlbumSelectorView()
                ->exposedStrings(),
        ];
    }
}
