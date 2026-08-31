<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

use Override;
use Piwigo\Asset\AssetContribution;
use Piwigo\Asset\HasPageAssets;
use Piwigo\Asset\LoadMode;
use Piwigo\Core\View;
use Piwigo\Template\Latte\Attribute\Template as TemplateAttr;

/**
 * `{templateType}` target for
 * `themes/admin/default/template/include/add_album.inc.latte`
 * (docs/PLAN.md's P42-A). Contract-only, same shape as
 * `Piwigo\Controller\Projection\NavigationBarView`'s own precedent --
 * never rendered via `Renderer::render()`, only `{include}`d (its own
 * real markup, the "add album" popin form, still needs a plain
 * `{include}` at each real call site -- only the `{do
 * combineCss}`/`{do combineScript}` calls move to `pageAssets()`,
 * docs/PLAN.md's P42-B).
 *
 * No `$load_mode` param anymore (docs/PLAN.md's P48) -- `addAlbum.ts`
 * no longer has its own independent script registration here at all
 * (see this class's own `pageAssets()` docblock), so the only thing
 * `$load_mode` ever affected is gone; each real caller now registers
 * its own real per-page bundle script (which folds `addAlbum.ts` in
 * via a real `import`) at whatever `LoadMode` that page's own script
 * group needs, directly. `$colorscheme` replaces the file's own
 * `$themeconf['colorscheme']` ambient read -- every real parent
 * resolves and passes it explicitly, the same `UserListView`-established
 * pattern.
 */
#[TemplateAttr('include/add_album.inc.latte')]
final readonly class AddAlbumView implements View, HasPageAssets
{
    public function __construct(
        public string $colorscheme = '',
    ) {}

    /**
     * `include/colorbox.inc.latte`'s own contribution is deliberately
     * NOT merged in here (docs/PLAN.md's P42-B): both of this file's own
     * real parents (`BatchManagerGlobalView`/`PhotosAddDirectView`)
     * already merge `ColorboxView` directly, so a merge here would just
     * be a redundant duplicate registration one level removed --
     * `include/colorbox.inc.latte`'s own `{include}` line was removed
     * from this file once that coverage was confirmed complete.
     *
     * No `addAlbum.ts` script registration here anymore (docs/PLAN.md's
     * P48) -- `addAlbum.ts` has exactly 2 real registrants
     * (`PhotosAddDirectView`/`BatchManagerGlobalView`, this class's own
     * 2 real callers), each at a genuinely different resolved
     * `LoadMode` (Footer/Async) -- its code now ships as a real
     * `import` inside each of those 2 pages' own bundle entries
     * instead.
     *
     * @return list<AssetContribution>
     */
    #[Override]
    public function pageAssets(): array
    {
        return [
            AssetContribution::css('themes/default/js/plugins/selectize.' . $this->colorscheme . '.css', id: 'jquery.selectize'),
            // order 10 is required, see issue 1080
            AssetContribution::css('themes/admin/default/fontello/css/animation.css', order: 10),
            AssetContribution::css('themes/admin/default/css/components/add_album.css', id: 'add_album'),
        ];
    }
}
