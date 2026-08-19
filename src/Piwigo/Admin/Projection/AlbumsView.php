<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

use Piwigo\Core\View;
use Piwigo\Template\Latte\Attribute\Template;

/**
 * `albums.latte`'s own typed view, constructed by {@see
 * \Piwigo\Admin\AlbumsPageRenderer::render()}. No `$nbCats` field -- the
 * template's own body never references it.
 */
#[Template('albums.latte')]
final readonly class AlbumsView implements View
{
    /**
     * @param list<array<string, mixed>> $albumData
     */
    public function __construct(
        public string $openCat,
        public string $fAction,
        public int $delayBeforeAutoOpen,
        public string $posPref,
        public array $albumData,
        public string $csrfToken,
        public int $nbAlbums,
        public int $lightAlbumManager,
    ) {}
}
