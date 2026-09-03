<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

use Latte\Runtime\Html;
use Override;
use Piwigo\Asset\AssetContribution;
use Piwigo\Asset\HasPageAssets;
use Piwigo\Core\View;
use Piwigo\Template\Latte\Attribute\Template;

/**
 * `help.latte`'s own typed view, constructed by {@see
 * \Piwigo\Admin\HelpPageRenderer::render()}.
 *
 * `$helpContent` is Html, not string (P59): a local `help/help_*.html`
 * file shipped with the app, loaded under a filename built from
 * `$tabsheet->selected` -- always one of the tabsheet's own registered
 * (allowlisted) tab names per `Tabsheet::select()`'s own fallback
 * behavior, never raw user input (see `HelpSectionRequest`'s own
 * docblock). `$helpSectionTitle` is Html for the same reason {@see
 * \Piwigo\Admin\Projection\TabSheetEntry::$caption} is -- it's read
 * straight off that same field.
 */
#[Template('help.latte')]
final readonly class HelpView implements View, HasPageAssets
{
    public function __construct(
        public Html $helpContent,
        public Html $helpSectionTitle,
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
