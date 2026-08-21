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
 * `photos_add_direct.latte`'s own typed view, constructed by {@see
 * \Piwigo\Admin\PhotosAddDirectPageRenderer::render()}, which merges
 * in its own private `prepareUploadForm()`'s fields too -- both feed
 * this one file. No `$fAddAction`, `$levelOptions`/
 * `$levelOptionsSelected`, or `$cacheKeys` field here -- confirmed dead
 * in `photos_add_direct.latte`'s own real body: the "Manage
 * Permissions" fieldset that would have used the first three is
 * commented out (Smarty-era `{html_options ...}` syntax, not even this
 * project's own `{=htmlOptions(...)}` convention), and `CACHE_KEYS`
 * has zero real references at all.
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
    ) {}

    /**
     * Only `include/colorbox.inc.latte`'s and (when `!$displayFormats`,
     * matching the template's own original `{if}` guard exactly)
     * `include/add_album.inc.latte`'s own contributions -- this page's
     * own many other `combineCss`/`combineScript`/`exposeData`/
     * `exposeString` call sites (`docs/PLAN.md`'s P42-B colorbox-family
     * batch) are not migrated yet, deliberately, and stay imperative
     * for now; both sources coexist correctly (`PageAssets::add()`'s
     * own dedup contract).
     *
     * @return list<AssetContribution>
     */
    #[Override]
    public function pageAssets(): array
    {
        $assets = new ColorboxView()
            ->pageAssets();

        if (! $this->displayFormats) {
            $assets = [
                ...$assets,
                ...new AddAlbumView(colorscheme: $this->colorscheme)
                    ->pageAssets(),
            ];
        }

        return [
            ...$assets,
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
