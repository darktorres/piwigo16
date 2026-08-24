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
 * `themes/admin/default/template/include/autosize.inc.latte`
 * (docs/PLAN.md's P42-A). Contract-only, same shape as
 * `Piwigo\Controller\Projection\NavigationBarView`'s own precedent --
 * never rendered via `Renderer::render()`, only `{include}`d, and 100%
 * static `combineScript` calls, no real property. The
 * `themes/default/template/include/autosize.inc.latte` counterpart this
 * file used to share a basename with had zero real callers anywhere in
 * the app and was deleted outright rather than converted.
 *
 * `pageAssets()` (docs/PLAN.md's P42-B) is never reached via
 * `Renderer::render()`'s own hook either, for the identical reason --
 * every real parent constructs a `new AutosizeView()` purely to merge
 * `->pageAssets()` into its own return value (the same construct-and-
 * merge pattern `PictureNavButtonsView`/`ToasterView` already
 * established), while the Latte `{include}` for markup stays
 * unchanged.
 */
#[TemplateAttr('include/autosize.inc.latte')]
final readonly class AutosizeView implements View, HasPageAssets
{
    /**
     * @return list<AssetContribution>
     */
    #[Override]
    public function pageAssets(): array
    {
        return [
            AssetContribution::script('jquery.autogrow', 'themes/default/js/plugins/jquery.autogrow-textarea.js', loadMode: LoadMode::Async, dependsOn: ['jquery']),
            AssetContribution::script('autosize', 'themes/admin/default/js/autosize.ts', loadMode: LoadMode::Footer, dependsOn: ['jquery.autogrow']),
        ];
    }
}
