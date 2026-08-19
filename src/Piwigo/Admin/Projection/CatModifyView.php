<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

use Piwigo\Core\View;
use Piwigo\Template\Latte\Attribute\Template;

/**
 * `cat_modify.latte`'s own typed view, constructed by {@see
 * \Piwigo\Admin\CatModifyPageRenderer::render()}. Every remaining
 * optional field stays optional -- the template's own body reads all
 * of them through `isset()` guards, never a bare truthy check, so an
 * always-present `null` behaves identically to the original
 * conditionally-omitted key. No `$uChildren`, `$uManageRanks`,
 * `$cacheKeys`, `$parentCategory`, or `$infoId` field -- confirmed dead
 * against both the template's own body (and its included
 * `album_selector.inc.latte`, which is entirely self-contained and
 * reads no external context beyond a local `{var $load_mode}`) and
 * `cat_modify.js`'s `pwg_getPageData()` reads.
 */
#[Template('cat_modify.latte')]
final readonly class CatModifyView implements View
{
    /**
     * @param array<string, mixed>|null $representant
     */
    public function __construct(
        public string $categoriesNav,
        public string $categoriesParentNav,
        public int|string $parentCatId,
        public int $catId,
        public string $catName,
        public string $catComment,
        public bool $isVisible,
        public bool $catAdminAccess,
        public string $uDelete,
        public string $uJumpto,
        public string $uAddPhotosAlbum,
        public string $uMove,
        public string $uActivity,
        public ?bool $catCommentable,
        public ?string $uManageElements,
        public string $infoPhoto,
        public string $infoTitle,
        public ?string $infoCreationSince,
        public ?string $infoCreation,
        public string $infoDirectSub,
        public string $infoLastModifiedSince,
        public string $infoLastModified,
        public string $infoImagesRecursive,
        public string $infoSubcats,
        public int $nbSubcats,
        public ?string $catFullDir,
        public ?string $catDirName,
        public ?string $catMinDir,
        public ?string $uSync,
        public ?array $representant,
        public string $csrfToken,
    ) {}
}
