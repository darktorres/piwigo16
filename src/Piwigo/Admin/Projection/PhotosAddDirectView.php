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
     * @param ?FormatsOriginalInfo $formatsOriginalInfo null when there
     *   is no such photo, which is also what the template and the
     *   `have_formats_original` page-data flag both test
     * @param list<int> $selectedCategory
     * @param list<string> $setupErrors
     * @param list<string> $setupWarnings
     */
    public function __construct(
        public bool $promoteMobileApps,
        public string $phpwgUrl,
        public bool $enableFormats,
        public bool $displayFormats,
        public ?FormatsOriginalInfo $formatsOriginalInfo,
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
    ) {}

    /**
     * `include/add_album.inc.latte`'s own contribution stays
     * conditional on `!$displayFormats`, matching the template's own
     * original `{if}` guard exactly.
     *
     * The `jquery.plupload`/`jquery.plupload.queue` scripts and the
     * per-locale `plupload_i18n-{code}` one are all dropped (P49-C):
     * `photos_add_direct.ts` no longer uses plupload as a jQuery-family
     * library at all (native `vendor/uploadQueue.ts` replaces it
     * entirely). The plugin's own CSS stays -- real, still-needed
     * visual styling for the same classnames the native port's own
     * markup produces, with zero jQuery/plupload-runtime dependency of
     * its own.
     *
     * @return list<AssetContribution>
     */
    #[Override]
    public function pageAssets(): array
    {
        $assets = [
            AssetContribution::css('https://cdn.jsdelivr.net/npm/jquery-confirm@3.3.4/dist/jquery-confirm.min.css'),
            AssetContribution::css('https://cdn.jsdelivr.net/gh/moxiecode/plupload@v2.1.2/js/jquery.plupload.queue/css/jquery.plupload.queue.css'),
            ...new ColorboxView()
                ->pageAssets(),
        ];

        if (! $this->displayFormats) {
            $assets = [
                ...$assets,
                ...new AddAlbumView(colorscheme: $this->colorscheme)
                    ->pageAssets(),
                // Real per-page bundle entry (docs/PLAN.md's P48) --
                // folds addAlbum.ts's code in via a real `import`
                // instead of the separate script tag AddAlbumView used
                // to register directly.
                AssetContribution::script('photos_add_direct_page', 'themes/admin/default/js/pages/photos_add_direct.ts', loadMode: LoadMode::Footer),
            ];
        }

        return [
            ...$assets,
            // LocalStorageCache.ts's own registration dropped outright
            // (docs/PLAN.md's P48, LocalStorageCache.ts's own batch) --
            // a real, pre-existing dead registration, confirmed
            // directly: neither this page's own photos_add_direct.ts
            // nor album_selector.ts's own AlbumSelector class (the only
            // other first-party file this page loads) reads any of
            // LocalStorageCache.ts's 4 real exported classes, and that
            // file has zero top-level side effects of its own to
            // preserve either.
            AssetContribution::css('themes/default/js/plugins/selectize.' . $this->colorscheme . '.css', id: 'jquery.selectize'),
            AssetContribution::script('add_photo', 'themes/admin/default/js/photos_add_direct.ts', loadMode: LoadMode::Footer),
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
        // `??` already covers the null view property -- no nullsafe
        // needed, since property access on null is exactly what it
        // suppresses.
        $originalImageIdStr = ' ' . ($this->formatsOriginalInfo->id ?? -1) . ' ';

        return [
            'display_formats' => $this->displayFormats,
            // Derived rather than carried: it was a second
            // constructor argument that had to agree with
            // $formatsOriginalInfo being null, and nothing
            // could make PHPStan (or a reader) believe they
            // did -- every property read in the template was
            // reported as a read on a possibly-null value.
            'have_formats_original' => $this->formatsOriginalInfo !== null,
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
