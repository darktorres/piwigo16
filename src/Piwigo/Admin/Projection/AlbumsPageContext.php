<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

use Override;
use Piwigo\Core\TemplatePageContext;

/**
 * The template variable set assigned by
 * {@see \Piwigo\Admin\AlbumsPageRenderer::render()}.
 */
final readonly class AlbumsPageContext implements TemplatePageContext
{
    /**
     * @param list<array<string, mixed>> $albumData
     */
    public function __construct(
        public int $nbCats,
        public string $openCat,
        public string $fAction,
        public int $delayBeforeAutoOpen,
        public string $posPref,
        public array $albumData,
        public string $pwgToken,
        public int $nbAlbums,
        public string $adminPageTitle,
        public int $lightAlbumManager,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        return [
            'nb_cats' => $this->nbCats,
            'open_cat' => $this->openCat,
            'F_ACTION' => $this->fAction,
            'delay_before_autoOpen' => $this->delayBeforeAutoOpen,
            'POS_PREF' => $this->posPref,
            'album_data' => $this->albumData,
            'CSRF_TOKEN' => $this->pwgToken,
            'nb_albums' => $this->nbAlbums,
            'ADMIN_PAGE_TITLE' => $this->adminPageTitle,
            'light_album_manager' => $this->lightAlbumManager,
        ];
    }
}
