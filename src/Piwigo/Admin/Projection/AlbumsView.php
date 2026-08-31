<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

use Override;
use Piwigo\Asset\AssetContribution;
use Piwigo\Asset\HasPageAssets;
use Piwigo\Asset\LoadMode;
use Piwigo\Core\ExposesPageData;
use Piwigo\Core\View;
use Piwigo\Template\Latte\Attribute\Template;

/**
 * `albums.latte`'s own typed view, constructed by {@see
 * \Piwigo\Admin\AlbumsPageRenderer::render()}. No `$nbCats` field -- the
 * template's own body never references it.
 */
#[Template('albums.latte')]
final readonly class AlbumsView implements View, HasPageAssets, ExposesPageData
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

    /**
     * `albums.latte`'s own unconditional `{do combineScript(...)}`x6/
     * `{do combineCss(...)}`x4 (docs/PLAN.md's P42-B).
     */
    #[Override]
    public function pageAssets(): array
    {
        return [
            AssetContribution::script('jquery.confirm', 'https://cdn.jsdelivr.net/npm/jquery-confirm@3.3.4/dist/jquery-confirm.min.js', loadMode: LoadMode::Footer, dependsOn: ['jquery']),
            AssetContribution::css('https://cdn.jsdelivr.net/npm/jquery-confirm@3.3.4/dist/jquery-confirm.min.css'),
            AssetContribution::css('https://cdn.jsdelivr.net/npm/jqtree@1.4.12/jqtree.css'),
            AssetContribution::script('jtree', 'https://cdn.jsdelivr.net/npm/jqtree@1.4.12/tree.jquery.js', loadMode: LoadMode::Footer),
            // order: 10 is required, see issue 1080.
            AssetContribution::css('themes/admin/default/fontello/css/animation.css', order: 10),
            AssetContribution::script('albums', 'themes/admin/default/js/albums.ts', loadMode: LoadMode::Footer),
            AssetContribution::script('cat_search', 'themes/admin/default/js/cat_search.ts', loadMode: LoadMode::Footer, dependsOn: ['albums']),
            AssetContribution::css('themes/admin/default/css/pages/albums.css', id: 'albums'),
        ];
    }

    #[Override]
    public function exposedPageData(): array
    {
        return [
            'album_data' => $this->albumData,
            'csrf_token' => $this->csrfToken,
            'open_cat' => $this->openCat,
            'nb_albums' => $this->nbAlbums,
            'light_album_manager' => $this->lightAlbumManager,
            'delay_auto_open' => $this->delayBeforeAutoOpen,
        ];
    }

    /**
     * `albums.latte`'s own unconditional `{do exposeString(...)}`x28
     * (docs/PLAN.md's P42-B).
     */
    #[Override]
    public function exposedStrings(): array
    {
        return [
            'The status of the album \'%s\' and its sub-albums will change to private. Are you sure?',
            'Yes change parent anyway',
            'No, don\'t move this album here',
            'Root',
            '%d sub-albums',
            '%d photos',
            '%d pictures in sub-albums',
            '<b>%d</b> albums found',
            '<b>1</b> album found',
            '<b>%d+</b> albums found, try to refine the search',
            'Drag and drop to reorder albums',
            'Delete album "%s".',
            'Delete album "%s" and its %d sub-albums.',
            'delete album and all %d photos, even the %d associated to other albums',
            'delete album and the %d orphan photos',
            'Rename "%s"',
            'Add Album',
            'Edit album',
            'Add Photos',
            'Visit Gallery',
            'Automatic sort order',
            'Delete album',
            'Apply to root albums',
            'Apply to direct sub-albums',
            'Album name must not be empty',
            'Create a new album at root',
            'Create a sub-album of "%s"',
            'Locked album',
        ];
    }
}
