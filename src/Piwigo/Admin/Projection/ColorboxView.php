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
 * never rendered via `Renderer::render()`, only `{include}`d.
 *
 * `pageAssets()` (docs/PLAN.md's P42-B) is never reached via
 * `Renderer::render()`'s own hook either, for the identical reason --
 * every real parent constructs a `new ColorboxView()` purely to merge
 * `->pageAssets()` into its own return value (the same construct-and-
 * merge pattern `DatepickerView` already established).
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
