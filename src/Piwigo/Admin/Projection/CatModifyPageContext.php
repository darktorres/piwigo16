<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

use Override;
use Piwigo\Core\TemplatePageContext;

/**
 * The template variable set assigned by
 * {@see \Piwigo\Admin\CatModifyPageRenderer::render()}. Every optional
 * field here is genuinely optional -- the original code only ever
 * assigns each of these template keys under its own runtime condition,
 * omitted here (not present as a null value) to match that exact
 * original behavior. `$catFullDir`/`$catDirName`/`$catMinDir`/`$uSync`
 * are only ever assigned together (a real, physical directory album);
 * `$parentCategory` is mutually exclusive with that group (a virtual
 * album).
 */
final readonly class CatModifyPageContext implements TemplatePageContext
{
    /**
     * @param array<array-key, string> $cacheKeys
     * @param array<string, mixed>|null $representant
     * @param list<int|string>|null $parentCategory
     */
    public function __construct(
        public string $categoriesNav,
        public string $categoriesParentNav,
        public int|string $parentCatId,
        public int $catId,
        public string $catName,
        public string $catComment,
        public string $isVisible,
        public bool $catAdminAccess,
        public string $uDelete,
        public string $uJumpto,
        public string $uAddPhotosAlbum,
        public string $uChildren,
        public string $uMove,
        public string $uActivity,
        public ?string $catCommentable,
        public ?string $uManageElements,
        public string $infoPhoto,
        public string $infoTitle,
        public ?string $infoCreationSince,
        public ?string $infoCreation,
        public string $infoDirectSub,
        public string $infoId,
        public string $infoLastModifiedSince,
        public string $infoLastModified,
        public mixed $infoImagesRecursive,
        public mixed $infoSubcats,
        public mixed $nbSubcats,
        public string $uManageRanks,
        public array $cacheKeys,
        public ?string $catFullDir,
        public ?string $catDirName,
        public ?string $catMinDir,
        public ?string $uSync,
        public ?array $representant,
        public ?array $parentCategory,
        public string $pwgToken,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        $result = [
            'CATEGORIES_NAV' => $this->categoriesNav,
            'CATEGORIES_PARENT_NAV' => $this->categoriesParentNav,
            'PARENT_CAT_ID' => $this->parentCatId,
            'CAT_ID' => $this->catId,
            'CAT_NAME' => $this->catName,
            'CAT_COMMENT' => $this->catComment,
            'IS_VISIBLE' => $this->isVisible,
            'CAT_ADMIN_ACCESS' => $this->catAdminAccess,
            'U_DELETE' => $this->uDelete,
            'U_JUMPTO' => $this->uJumpto,
            'U_ADD_PHOTOS_ALBUM' => $this->uAddPhotosAlbum,
            'U_CHILDREN' => $this->uChildren,
            'U_MOVE' => $this->uMove,
            'U_ACTIVITY' => $this->uActivity,
            'INFO_PHOTO' => $this->infoPhoto,
            'INFO_TITLE' => $this->infoTitle,
            'INFO_DIRECT_SUB' => $this->infoDirectSub,
            'INFO_ID' => $this->infoId,
            'INFO_LAST_MODIFIED_SINCE' => $this->infoLastModifiedSince,
            'INFO_LAST_MODIFIED' => $this->infoLastModified,
            'INFO_IMAGES_RECURSIVE' => $this->infoImagesRecursive,
            'INFO_SUBCATS' => $this->infoSubcats,
            'NB_SUBCATS' => $this->nbSubcats,
            'U_MANAGE_RANKS' => $this->uManageRanks,
            'CACHE_KEYS' => $this->cacheKeys,
            'PWG_TOKEN' => $this->pwgToken,
        ];

        if ($this->catCommentable !== null) {
            $result['CAT_COMMENTABLE'] = $this->catCommentable;
        }

        if ($this->uManageElements !== null) {
            $result['U_MANAGE_ELEMENTS'] = $this->uManageElements;
        }

        if ($this->infoCreationSince !== null) {
            $result['INFO_CREATION_SINCE'] = $this->infoCreationSince;
        }

        if ($this->infoCreation !== null) {
            $result['INFO_CREATION'] = $this->infoCreation;
        }

        if ($this->catFullDir !== null) {
            $result['CAT_FULL_DIR'] = $this->catFullDir;
        }

        if ($this->catDirName !== null) {
            $result['CAT_DIR_NAME'] = $this->catDirName;
        }

        if ($this->catMinDir !== null) {
            $result['CAT_MIN_DIR'] = $this->catMinDir;
        }

        if ($this->uSync !== null) {
            $result['U_SYNC'] = $this->uSync;
        }

        if ($this->representant !== null) {
            $result['representant'] = $this->representant;
        }

        if ($this->parentCategory !== null) {
            $result['parent_category'] = $this->parentCategory;
        }

        return $result;
    }
}
