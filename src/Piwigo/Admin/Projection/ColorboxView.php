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
 * `themes/admin/default/template/include/colorbox.inc.latte`
 * (docs/PLAN.md's P42-A). Contract-only, same shape as
 * `Piwigo\Controller\Projection\NavigationBarView`'s own precedent --
 * never rendered via `Renderer::render()`, only `{include}`d. The
 * `themes/default/template/include/colorbox.inc.latte` counterpart this
 * file used to share a basename with had zero real callers anywhere in
 * the app and was deleted outright rather than converted.
 *
 * `$load_mode` is genuinely optional -- the template's own
 * `{if empty($load_mode)}{var $load_mode = 'footer'}{/if}` default
 * applies whenever a real call site omits it (most do). `pageAssets()`
 * (docs/PLAN.md's P42-B) is never reached via `Renderer::render()`'s
 * own hook either, for the identical reason -- every real parent
 * constructs a `new ColorboxView(...)` purely to merge
 * `->pageAssets()` into its own return value (the same construct-and-
 * merge pattern `AutosizeView`/`DatepickerView` already established).
 */
#[TemplateAttr('include/colorbox.inc.latte')]
final readonly class ColorboxView implements View, HasPageAssets
{
    public function __construct(
        public ?string $load_mode = null,
    ) {}

    /**
     * @return list<AssetContribution>
     */
    #[Override]
    public function pageAssets(): array
    {
        $loadMode = match ($this->load_mode) {
            'header' => LoadMode::Header,
            'async' => LoadMode::Async,
            default => LoadMode::Footer,
        };

        return [
            AssetContribution::script('jquery.colorbox', 'https://cdn.jsdelivr.net/gh/jackmoore/colorbox@1.5.14/jquery.colorbox-min.js', loadMode: $loadMode, dependsOn: ['jquery']),
            AssetContribution::css('https://cdn.jsdelivr.net/gh/jackmoore/colorbox@1.5.14/example2/colorbox.css', id: 'jquery.colorbox'),
        ];
    }
}
