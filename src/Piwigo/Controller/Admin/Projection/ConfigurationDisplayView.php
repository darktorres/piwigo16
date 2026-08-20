<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin\Projection;

use Override;
use Piwigo\Asset\AssetContribution;
use Piwigo\Asset\HasPageAssets;
use Piwigo\Asset\LoadMode;
use Piwigo\Core\View;
use Piwigo\Template\Latte\Attribute\Template;

/**
 * `configuration_display.latte`'s own typed view, constructed by {@see
 * \Piwigo\Controller\Admin\ConfigurationSubController::handle()}'s own
 * `'display'` case, merged with the whole-page shared fields every
 * `configuration_*.latte` tab needs -- see {@see ConfigurationMainView}.
 */
#[Template('configuration_display.latte')]
final readonly class ConfigurationDisplayView implements View, HasPageAssets
{
    /**
     * @param array<string, mixed> $display
     */
    public function __construct(
        public array $display,
        public string $fAction,
        public ?string $saveSuccess,
        public int $isWebmaster,
        public string $csrfToken,
    ) {}

    /**
     * `configuration_display.latte`'s own unconditional
     * `{do combineScript(...)}`/`{do combineCss(...)}` (docs/PLAN.md's
     * P42-B).
     */
    #[Override]
    public function pageAssets(): array
    {
        return [
            AssetContribution::script('common', 'themes/admin/default/js/common.js', loadMode: LoadMode::Footer),
            AssetContribution::css('themes/admin/default/css/pages/configuration_display.css', id: 'configuration_display'),
        ];
    }
}
