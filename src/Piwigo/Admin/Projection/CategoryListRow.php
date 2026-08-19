<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

/**
 * One row of `cat_list.latte`'s `$categories` list, built by
 * {@see \Piwigo\Admin\CatListPageRenderer::render()}. `$uDelete`/`$uSync`
 * are mutually exclusive and both genuinely optional (a virtual album
 * gets a delete link; a real, synchronization-enabled directory album
 * gets a sync link; a real album with synchronization disabled gets
 * neither) -- both omitted, not null, to match the original code's own
 * per-key conditional array assignment exactly.
 */
final readonly class CategoryListRow
{
    public function __construct(
        public string $name,
        public int $nbPhotos,
        public int $nbSubPhotos,
        public int $nbSubAlbums,
        public int $id,
        public int $rank,
        public string $uJumpto,
        public string $uChildren,
        public string $uEdit,
        public string $uAddPhotosAlbum,
        public string $uMove,
        public bool $isVirtual,
        public bool $catAdminAccess,
        public ?string $uDelete,
        public ?string $uSync,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $result = [
            'NAME' => $this->name,
            'NB_PHOTOS' => $this->nbPhotos,
            'NB_SUB_PHOTOS' => $this->nbSubPhotos,
            'NB_SUB_ALBUMS' => $this->nbSubAlbums,
            'ID' => $this->id,
            'RANK' => $this->rank,
            'U_JUMPTO' => $this->uJumpto,
            'U_CHILDREN' => $this->uChildren,
            'U_EDIT' => $this->uEdit,
            'U_ADD_PHOTOS_ALBUM' => $this->uAddPhotosAlbum,
            'U_MOVE' => $this->uMove,
            'IS_VIRTUAL' => $this->isVirtual,
            'CAT_ADMIN_ACCESS' => $this->catAdminAccess,
        ];

        if ($this->uDelete !== null) {
            $result['U_DELETE'] = $this->uDelete;
        }

        if ($this->uSync !== null) {
            $result['U_SYNC'] = $this->uSync;
        }

        return $result;
    }
}
