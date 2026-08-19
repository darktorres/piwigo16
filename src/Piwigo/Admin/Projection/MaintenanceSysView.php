<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

use Piwigo\Core\View;
use Piwigo\Template\Latte\Attribute\Template;

/**
 * `maintenance_sys.latte`'s own typed view, constructed by {@see
 * \Piwigo\Admin\MaintenanceSysPageRenderer::render()}.
 */
#[Template('maintenance_sys.latte')]
final readonly class MaintenanceSysView implements View
{
    /**
     * @param list<array<string, mixed>> $activityLogEntries each shaped by
     *   {@see \Piwigo\Admin\Maintenance\ActivityLogEntryFormatter::format()};
     *   empty when not webmaster.
     */
    public function __construct(
        public bool $isWebmaster,
        public array $activityLogEntries,
    ) {}
}
