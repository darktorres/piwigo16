<?php

declare(strict_types=1);

namespace Piwigo\Menu\Projection;

use Override;
use Piwigo\Asset\AssetContribution;
use Piwigo\Asset\HasPageAssets;
use Piwigo\Asset\LoadMode;
use Piwigo\Core\View;
use Piwigo\Template\Latte\Attribute\Template;

/**
 * `menubar_links.latte`'s own typed view.
 *
 * Rendered through `Renderer::render()` and injected into the block's own
 * `raw_content`, not pulled in by `menubar.latte`'s former
 * `{include $block->template}` -- a dynamic-filename include cannot carry
 * a `{templateType}`, and its arguments are outside what the compile step
 * models. Same shape as {@see
 * \Piwigo\Controller\Projection\SelectedTagsView}, which P40 split out of
 * `index.latte` for the same reason.
 */
#[Template('menubar_links.latte')]
final readonly class MenubarLinksView implements View, HasPageAssets
{
    /**
     * @param list<MenubarLinkRow> $links never empty -- the block is only
     *    filled in when at least one link survives its visibility check
     */
    public function __construct(
        public array $links,
    ) {}

    /**
     * Declared here rather than in `MenubarView::pageAssets()`'s own
     * `match ($block->template)`: that dispatch keys off a field this
     * block no longer sets, and a View that owns its markup should own
     * its assets. `Renderer::render()` registers these when it renders
     * this view into the block's raw_content.
     *
     * @return list<AssetContribution>
     */
    #[Override]
    public function pageAssets(): array
    {
        return [
            AssetContribution::script('menubar-links', 'themes/default/js/menubar-links.ts', loadMode: LoadMode::Footer),
        ];
    }
}
