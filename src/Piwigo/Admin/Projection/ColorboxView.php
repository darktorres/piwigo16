<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

use Override;
use Piwigo\Asset\AssetContribution;
use Piwigo\Asset\HasPageAssets;
use Piwigo\Core\View;
use Piwigo\Template\Latte\Attribute\Template as TemplateAttr;

/**
 * `{templateType}` target for
 * `themes/admin/default/template/include/colorbox.inc.latte`
 * (docs/PLAN.md's P42-A). Contract-only, same shape as
 * `Piwigo\Controller\Projection\NavigationBarView`'s own precedent --
 * never rendered via `Renderer::render()`, only `{include}`d. The
 * `themes/default/template/include/colorbox.inc.latte` counterpart this
 * file used to share a basename with had zero real callers anywhere in
 * the app and was deleted outright rather than converted.
 *
 * `pageAssets()` (docs/PLAN.md's P42-B) is never reached via
 * `Renderer::render()`'s own hook either, for the identical reason --
 * every real parent constructs a `new ColorboxView()` purely to merge
 * `->pageAssets()` into its own return value (the same construct-and-
 * merge pattern `DatepickerView` already established).
 *
 * No `$load_mode` constructor param any more (docs/PLAN.md's P49-B):
 * it only ever fed the CDN `jquery.colorbox` script's own `loadMode`,
 * removed outright once colorbox itself was ported to
 * `themes/default/js/vendor/colorbox.ts` -- the CSS contribution below
 * (still real, every id/class the native port creates matches the
 * original's own naming) never took a load mode to begin with.
 */
#[TemplateAttr('include/colorbox.inc.latte')]
final readonly class ColorboxView implements View, HasPageAssets
{
    /**
     * @return list<AssetContribution>
     */
    #[Override]
    public function pageAssets(): array
    {
        return [
            AssetContribution::css('https://cdn.jsdelivr.net/gh/jackmoore/colorbox@1.5.14/example2/colorbox.css', id: 'jquery.colorbox'),
        ];
    }
}
