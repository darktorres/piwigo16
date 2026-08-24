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
 * `element_set_ranks.latte`'s own typed view, constructed by {@see
 * \Piwigo\Admin\ElementSetRanksPageRenderer::render()}. No
 * `$categoriesNav` field -- the template's own body never references
 * it. `$thumbnails` is always included (even empty) since the template
 * reads it with `{if !empty($thumbnails)}`, not `isset()`. `$imageOrder`
 * is always exactly 3 elements -- render()'s own fixed 3-iteration loop
 * runs unconditionally, and the template's own `{foreach $imageOrder as
 * $order}` has no guard at all.
 */
#[Template('element_set_ranks.latte')]
final readonly class ElementSetRanksView implements View, HasPageAssets
{
    /**
     * @param array<string, string> $imageOrderOptions
     * @param list<array<string, mixed>> $thumbnails
     * @param list<string> $imageOrder
     */
    public function __construct(
        public string $formAction,
        public string $csrfToken,
        public array $imageOrderOptions,
        public string $imageOrderChoice,
        public array $thumbnails,
        public array $imageOrder,
        public ?string $saveSuccess,
    ) {}

    /**
     * `element_set_ranks.latte`'s own unconditional `{do combineScript(...)}`x2/
     * `{do combineCss(...)}`x1 (docs/PLAN.md's P42-B).
     */
    #[Override]
    public function pageAssets(): array
    {
        return [
            AssetContribution::script('common', 'themes/admin/default/js/common.ts', loadMode: LoadMode::Footer),
            AssetContribution::script('element_set_ranks', 'themes/admin/default/js/element_set_ranks.ts', loadMode: LoadMode::Footer, dependsOn: ['jquery.ui', 'jquery.tipTip']),
            AssetContribution::css('themes/admin/default/css/pages/element_set_ranks.css', id: 'element_set_ranks'),
        ];
    }
}
