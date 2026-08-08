<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

use Override;
use Piwigo\Core\TemplatePageContext;

/**
 * The template variable set assigned by
 * {@see \Piwigo\Admin\PhotosAddDirectPageRenderer::prepareUploadForm()}
 * -- see {@see PhotosAddDirectPageContext} for that renderer's own
 * `render()` assigns. Every optional field here is genuinely optional --
 * the original code only ever assigns each of these template keys under
 * its own runtime condition, omitted here (not present as a null value)
 * to match that exact original behavior. `$maxUploadWidth`/
 * `$maxUploadHeight`/`$maxUploadResolution` are only ever assigned
 * together, as are `$originalResizeMaxwidth`/`$originalResizeMaxheight`
 * and `$setupWarnings`/`$hideWarningsLink`. `$addToAlbum` and
 * `$selectedCategoryName` are mutually exclusive (if/else branches) but
 * map to different template keys, so stay as 2 separate fields.
 */
final readonly class PhotosAddDirectUploadFormPageContext implements TemplatePageContext
{
    /**
     * @param list<int> $selectedCategory
     * @param array<array-key, string> $levelOptions
     * @param list<mixed> $levelOptionsSelected
     * @param list<string> $setupErrors
     * @param array<array-key, string> $cacheKeys
     * @param list<string>|null $setupWarnings
     */
    public function __construct(
        public string $fAddAction,
        public int $chunkSize,
        public int $maxFileSize,
        public string $adminPageTitle,
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
        public array $levelOptions,
        public array $levelOptionsSelected,
        public array $setupErrors,
        public array $cacheKeys,
        public ?array $setupWarnings,
        public ?string $hideWarningsLink,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        $result = [
            'F_ADD_ACTION' => $this->fAddAction,
            'chunk_size' => $this->chunkSize,
            'max_file_size' => $this->maxFileSize,
            'ADMIN_PAGE_TITLE' => $this->adminPageTitle,
            'form_action' => $this->formAction,
            'pwg_token' => $this->pwgToken,
            'upload_file_types' => $this->uploadFileTypes,
            'file_exts' => $this->fileExts,
            'selected_category' => $this->selectedCategory,
            'NB_ALBUMS' => $this->nbAlbums,
            'level_options' => $this->levelOptions,
            'level_options_selected' => $this->levelOptionsSelected,
            'setup_errors' => $this->setupErrors,
            'CACHE_KEYS' => $this->cacheKeys,
        ];

        if ($this->maxUploadWidth !== null) {
            $result['max_upload_width'] = $this->maxUploadWidth;
            $result['max_upload_height'] = $this->maxUploadHeight;
            $result['max_upload_resolution'] = $this->maxUploadResolution;
        }

        if ($this->originalResizeMaxwidth !== null) {
            $result['original_resize_maxwidth'] = $this->originalResizeMaxwidth;
            $result['original_resize_maxheight'] = $this->originalResizeMaxheight;
        }

        if ($this->addToAlbum !== null) {
            $result['ADD_TO_ALBUM'] = $this->addToAlbum;
        }

        if ($this->selectedCategoryName !== null) {
            $result['selected_category_name'] = $this->selectedCategoryName;
        }

        if ($this->setupWarnings !== null) {
            $result['setup_warnings'] = $this->setupWarnings;
            $result['hide_warnings_link'] = $this->hideWarningsLink;
        }

        return $result;
    }
}
