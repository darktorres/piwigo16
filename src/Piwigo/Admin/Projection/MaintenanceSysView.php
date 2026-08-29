<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

use Override;
use Piwigo\Admin\Maintenance\Projection\ActivityLogRow;
use Piwigo\Asset\AssetContribution;
use Piwigo\Asset\HasPageAssets;
use Piwigo\Core\View;
use Piwigo\Template\Latte\Attribute\Template;

/**
 * `maintenance_sys.latte`'s own typed view, constructed by {@see
 * \Piwigo\Admin\MaintenanceSysPageRenderer::render()}.
 */
#[Template('maintenance_sys.latte')]
final readonly class MaintenanceSysView implements View, HasPageAssets
{
    /**
     * @param list<ActivityLogRow> $activityLogEntries built by
     *   {@see \Piwigo\Admin\Maintenance\ActivityLogEntryFormatter::format()};
     *   empty when not webmaster.
     */
    public function __construct(
        public bool $isWebmaster,
        public array $activityLogEntries,
    ) {}

    /**
     * `maintenance_sys.latte`'s own `{if $isWebmaster}{do combineCss(...)}
     * {do combineCss(...)}{/if}` (docs/PLAN.md's P42-B) -- the whole
     * activity-log fieldset these style, `order: 10` included ("required,
     * see issue 1080" per the original template's own comment), doesn't
     * render at all for a non-webmaster.
     */
    #[Override]
    public function pageAssets(): array
    {
        if (! $this->isWebmaster) {
            return [];
        }

        return [
            AssetContribution::css('themes/admin/default/fontello/css/animation.css', order: 10),
            AssetContribution::css('themes/admin/default/css/pages/maintenance_sys.css', id: 'maintenance_sys'),
        ];
    }
}
