<?php

declare(strict_types=1);

namespace Piwigo\Template\Projection;

use Override;
use Piwigo\Asset\AssetContribution;
use Piwigo\Asset\HasPageAssets;
use Piwigo\Asset\LoadMode;
use Piwigo\Core\View;
use Piwigo\Template\Latte\Attribute\Template as TemplateAttr;

/**
 * `{templateType}` target for `themes/standard_pages/template/toaster.latte`
 * (docs/PLAN.md's P42-A). Contract-only, same shape as
 * `Piwigo\Controller\Projection\NavigationBarView`'s own precedent: its
 * one real call site (`profile.latte`'s bare `{include 'toaster.latte'}`)
 * passes nothing, and the body itself is 100% static markup plus static
 * `combineScript`/`combineCss` calls -- no real property, and never
 * rendered via `Renderer::render()`. `ProfileView` constructs an instance
 * and merges its `pageAssets()` in (docs/PLAN.md's P42-B).
 */
#[TemplateAttr('themes/standard_pages/template/toaster.latte')]
final readonly class ToasterView implements View, HasPageAssets
{
    /**
     * `toaster.latte`'s own unconditional `{do combineScript(...)}`/
     * `{do combineCss(...)}` (docs/PLAN.md's P42-B).
     */
    #[Override]
    public function pageAssets(): array
    {
        return [
            AssetContribution::script('toaster_js', 'themes/standard_pages/js/toaster.ts', loadMode: LoadMode::Async, dependsOn: ['jquery']),
            AssetContribution::css('themes/standard_pages/css/pages/toaster.css', id: 'toaster'),
        ];
    }
}
