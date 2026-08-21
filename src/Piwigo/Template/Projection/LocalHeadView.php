<?php

declare(strict_types=1);

namespace Piwigo\Template\Projection;

use Override;
use Piwigo\Asset\AssetContribution;
use Piwigo\Asset\HasPageAssets;
use Piwigo\Core\View;
use Piwigo\Template\Latte\Attribute\Template as TemplateAttr;

/**
 * `{templateType}` target for `themes/default/local_head.latte` -- the
 * one real `$theme['local_head']` file in the whole app (docs/PLAN.md's
 * P42's theme-base "local-head resolver" piece). Unlike the other 10
 * P42-A partial conversions, this one is a full `View`, not
 * contract-only: the theme-base resolver renders it via
 * `Renderer::render()` directly, not a plain `{include}`.
 *
 * `#[Template]`'s `themes/default/local_head.latte` value (not a bare
 * filename) resolves via `TemplateLocator::resolve()`'s own
 * project-root-relative fallback branch -- the file sits directly under
 * the theme root, not under `themes/default/template/` like every other
 * real template, so the usual bare-filename + `$templateDirs` search
 * would never find it.
 *
 * `pageAssets()` (docs/PLAN.md's P42-B) replicates this file's own
 * former `{if $load_css}{do combineCss(...)}{/if}` -- but
 * `Template::resolveLocalHeadOnce()` calls `renderView()` on this View
 * directly, not through `Renderer::render()` (it can't: `Renderer`
 * itself depends on `Template` via `CurrentTemplate`, so `Template`
 * calling back into `Renderer` would be circular), so
 * `resolveLocalHeadOnce()` applies `HasPageAssets` inline instead of
 * relying on `Renderer::render()`'s own hook to do it.
 */
#[TemplateAttr('themes/default/local_head.latte')]
final readonly class LocalHeadView implements View, HasPageAssets
{
    public function __construct(
        public bool $load_css,
    ) {}

    /**
     * @return list<AssetContribution>
     */
    #[Override]
    public function pageAssets(): array
    {
        if (! $this->load_css) {
            return [];
        }

        return [
            AssetContribution::css('themes/default/print.css', order: -10),
        ];
    }
}
