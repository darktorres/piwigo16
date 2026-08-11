<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

use Override;
use Piwigo\Core\TemplatePageContext;

/**
 * The template variable set assigned by
 * {@see \Piwigo\Admin\PictureModifyPageRenderer::render()}.
 * `$saveSuccess`, `$uCoi`, and `$uJumpto` are genuinely optional -- the
 * original code only ever assigns those 3 template keys under their own
 * runtime condition, omitted here (not present as a null value) to match
 * that exact original behavior. `$storageCategory` is also optional and
 * genuinely dead: it's overwritten once per matching category across
 * the whole related-categories loop (last write wins), and
 * `picture_modify.tpl` never actually reads `$STORAGE_CATEGORY`
 * (confirmed by direct read) -- kept here anyway as a faithful 1:1 port
 * of the original assign() call, not removed as an out-of-scope
 * dead-code cleanup.
 *
 * `dateCreation` stays `string|int|null`, unvalidated -- `picture_modify.tpl`
 * renders it straight into `<input type="hidden" name="date_creation"
 * value="{$DATE_CREATION}">`, read back by a JS datepicker and
 * round-tripped through `PictureModifyRequest`'s own
 * `date_creation` validation pattern (`\d\d\d\d-\d\d-\d\d(...)?`, time
 * component optional). Its raw driver-fetch source can be `int` or
 * `string` depending on DBAL's native-casting mode (same ambiguity
 * `CatModifyPageRenderer` documents for a different column), which is
 * harmless here since the value is only ever string-interpolated, never
 * computed on -- no existing VO matches "loose date-or-datetime string,
 * no validation," and one built solely for this call site would be a
 * premature abstraction.
 */
final readonly class PictureModifyPageContext implements TemplatePageContext
{
    /**
     * @param array<int, array{name: mixed, id: string}> $tagSelection
     * @param array<string, mixed> $introVars
     * @param array<array-key, string> $levelOptions
     * @param list<mixed> $levelOptionsSelected
     * @param array<array-key, array{name: string, unlinkable: bool}> $relatedCategories
     * @param list<string> $relatedCategoriesIds
     * @param list<int> $associatedAlbums
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
        public string $title,
        public string $dimensions,
        public int $format,
        public string $filesize,
        public string $registrationDate,
        public string $author,
        public string|int|null $dateCreation,
        public string $description,
        public string $fAction,
        public array $introVars,
        public ?string $uCoi,
        public array $levelOptions,
        public array $levelOptionsSelected,
        public ?string $storageCategory,
        public array $relatedCategories,
        public array $relatedCategoriesIds,
        public ?string $uJumpto,
        public array $associatedAlbums,
        public array $representedAlbums,
        public ?string $storageAlbum,
        public array $cacheKeys,
        public string $pwgToken,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        $result = [
            'tag_selection' => $this->tagSelection,
            'U_DOWNLOAD' => $this->uDownload,
            'U_SYNC' => $this->uSync,
            'U_DELETE' => $this->uDelete,
            'U_HISTORY' => $this->uHistory,
            'U_ACTIVITY' => $this->uActivity,
            'PATH' => $this->path,
            'TN_SRC' => $this->tnSrc,
            'FILE_SRC' => $this->fileSrc,
            'NAME' => $this->name,
            'TITLE' => $this->title,
            'DIMENSIONS' => $this->dimensions,
            'FORMAT' => $this->format,
            'FILESIZE' => $this->filesize,
            'REGISTRATION_DATE' => $this->registrationDate,
            'AUTHOR' => $this->author,
            'DATE_CREATION' => $this->dateCreation,
            'DESCRIPTION' => $this->description,
            'F_ACTION' => $this->fAction,
            'INTRO' => $this->introVars,
            'level_options' => $this->levelOptions,
            'level_options_selected' => $this->levelOptionsSelected,
            'related_categories' => $this->relatedCategories,
            'related_categories_ids' => $this->relatedCategoriesIds,
            'associated_albums' => $this->associatedAlbums,
            'represented_albums' => $this->representedAlbums,
            'STORAGE_ALBUM' => $this->storageAlbum,
            'CACHE_KEYS' => $this->cacheKeys,
            'CSRF_TOKEN' => $this->pwgToken,
        ];

        if ($this->saveSuccess !== null) {
            $result['save_success'] = $this->saveSuccess;
        }

        if ($this->uCoi !== null) {
            $result['U_COI'] = $this->uCoi;
        }

        if ($this->storageCategory !== null) {
            $result['STORAGE_CATEGORY'] = $this->storageCategory;
        }

        if ($this->uJumpto !== null) {
            $result['U_JUMPTO'] = $this->uJumpto;
        }

        return $result;
    }
}
