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
        public string $uJumpto,
        public string $uChildren,
        public string $uEdit,
        public string $uAddPhotosAlbum,
        public string $uMove,
        public bool $isVirtual,
        public bool $catAdminAccess,
    ) {}
}
