<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

use Override;
use Piwigo\Asset\AssetContribution;
use Piwigo\Asset\HasPageAssets;
use Piwigo\Asset\LoadMode;
use Piwigo\Core\View;
use Piwigo\Template\Latte\Attribute\Template;

/**
 * `menubar.latte`'s own typed view, constructed by {@see
 * \Piwigo\Admin\MenubarPageRenderer::render()}. `$blocks` is always
 * included -- the loop that builds it runs unconditionally, and
 * `menubar.latte`'s own `{foreach}` has no guard around it either.
 * `$saveSuccess` is genuinely optional, only set once the menubar
 * order was just saved.
 */
#[Template('menubar.latte')]
final readonly class MenubarView implements View, HasPageAssets
{
    /**
     * @param list<MenubarBlockConfigRow> $blocks
     */
    public function __construct(
        public string $formAction,
        public int $isWebmaster,
        public array $blocks,
        public ?string $saveSuccess,
    ) {}

    /**
     * `menubar.latte`'s own unconditional `{do combineScript(...)}`x2/
     * `{do combineCss(...)}` (docs/PLAN.md's P42-B).
     */
    #[Override]
    public function pageAssets(): array
    {
        return [
            AssetContribution::script('menubar', 'themes/admin/default/js/menubar.ts', loadMode: LoadMode::Footer, dependsOn: ['jquery.ui']),
            AssetContribution::css('themes/admin/default/css/pages/menubar.css', id: 'menubar'),
        ];
    }
}
