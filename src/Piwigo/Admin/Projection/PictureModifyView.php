<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

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
final readonly class PictureModifyView implements View
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
    ) {}
}
