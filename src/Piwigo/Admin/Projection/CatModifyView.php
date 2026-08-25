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
 * `cat_modify.ts`'s `pwg_getPageData()` reads.
 */
#[Template('cat_modify.latte')]
final readonly class CatModifyView implements View, HasPageAssets, ExposesPageData
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

    /**
     * @return list<AssetContribution>
     */
    #[Override]
    public function pageAssets(): array
    {
        return [
            AssetContribution::script('cat_modify', 'themes/admin/default/js/cat_modify.ts', loadMode: LoadMode::Footer, dependsOn: ['page-data']),
            AssetContribution::script('jquery.confirm', 'https://cdn.jsdelivr.net/npm/jquery-confirm@3.3.4/dist/jquery-confirm.min.js', loadMode: LoadMode::Footer, dependsOn: ['jquery']),
            AssetContribution::css('https://cdn.jsdelivr.net/npm/jquery-confirm@3.3.4/dist/jquery-confirm.min.css'),
            // order 10 is required, see issue 1080
            AssetContribution::css('themes/admin/default/fontello/css/animation.css', order: 10),
            AssetContribution::css('themes/admin/default/css/pages/cat_modify.css', id: 'cat_modify'),
            AssetContribution::script('jquery.tipTip', 'https://cdn.jsdelivr.net/gh/drewwilson/TipTip@277e33629e/jquery.tipTip.minified.js', loadMode: LoadMode::Footer),
            ...new AlbumSelectorView()
                ->pageAssets(),
            // include/colorbox.inc.latte's own contribution -- reached
            // transitively via album_selector.inc.latte's own nested
            // include (docs/PLAN.md's P42-B) -- resolves after it,
            // matching the accepted golden-html baseline.
            ...new ColorboxView()
                ->pageAssets(),
        ];
    }

    /**
     * @return array<string, string|int|float|bool|null|array<mixed>>
     */
    #[Override]
    public function exposedPageData(): array
    {
        return [
            'cat_id' => $this->catId,
            'parent_cat_id' => $this->parentCatId,
            'cat_name' => $this->catName,
            'nb_subcats' => $this->nbSubcats,
            'csrf_token' => $this->csrfToken,
            'u_delete' => $this->uDelete,
            'is_visible' => $this->isVisible,
        ];
    }

    /**
     * `'No, I have changed my mind'` already covered unconditionally by
     * `ThemeBaseAssets`'s own confirm-dialog triplet (docs/PLAN.md's
     * P42) -- dropped, not ported.
     *
     * @return list<string>
     */
    #[Override]
    public function exposedStrings(): array
    {
        return [
            'Delete album',
            'Delete album "%s" and its %d sub-albums.',
            'Just now',
            'delete only album, not photos',
            'delete album and the %d orphan photos',
            'delete album and all %d photos, even the %d associated to other albums',
            'Comments allowed for sub-albums',
            'Comments disallowed for sub-albums',
            'New parent album',
            ...new AlbumSelectorView()
                ->exposedStrings(),
        ];
    }
}
