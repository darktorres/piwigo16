<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

use Override;
use Piwigo\Asset\AssetContribution;
use Piwigo\Asset\HasPageAssets;
use Piwigo\Core\View;
use Piwigo\Template\Latte\Attribute\Template;

/**
 * `help.latte`'s own typed view, constructed by {@see
 * \Piwigo\Admin\HelpPageRenderer::render()}.
 */
#[Template('help.latte')]
final readonly class HelpView implements View, HasPageAssets
{
    public function __construct(
        public string $helpContent,
        public string $helpSectionTitle,
        public bool $enableSynchronization,
    ) {}

    /**
     * `help.latte`'s own `{if !$ENABLE_SYNCHRONIZATION}{do combineCss(...)}{/if}`
     * (docs/PLAN.md's P42-B) -- the synchronization tab this stylesheet
     * styles doesn't render at all when synchronization is disabled.
     */
    #[Override]
    public function pageAssets(): array
    {
        if ($this->enableSynchronization) {
            return [];
        }

        return [
            AssetContribution::css('themes/admin/default/css/pages/help.css', id: 'help'),
        ];
    }
}
