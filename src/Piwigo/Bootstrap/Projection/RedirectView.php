<?php

declare(strict_types=1);

namespace Piwigo\Bootstrap\Projection;

use Override;
use Piwigo\Asset\AssetContribution;
use Piwigo\Asset\HasPageAssets;
use Piwigo\Core\View;
use Piwigo\Template\Latte\Attribute\Template;

/**
 * `redirect.latte`'s own typed view, constructed by {@see
 * \Piwigo\Bootstrap\RedirectService::redirectHtml()}.
 */
#[Template('redirect.latte')]
final readonly class RedirectView implements View, HasPageAssets
{
    public function __construct(
        public string $redirectMsg,
    ) {}

    /**
     * `redirect.latte`'s own unconditional `{do combineCss(...)}`
     * (docs/PLAN.md's P42-B).
     */
    #[Override]
    public function pageAssets(): array
    {
        return [
            AssetContribution::css('themes/default/css/pages/redirect.css', id: 'redirect'),
        ];
    }
}
