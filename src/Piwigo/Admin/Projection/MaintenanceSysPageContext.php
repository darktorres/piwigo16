<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

use Override;
use Piwigo\Core\TemplatePageContext;

/**
 * The full 'maintenance_sys.latte' template variable set assigned by
 * {@see \Piwigo\Admin\MaintenanceSysPageRenderer::render()}.
 */
final readonly class MaintenanceSysPageContext implements TemplatePageContext
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

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        return [
            'isWebmaster' => $this->isWebmaster ? 1 : 0,
            'ACTIVITY_LOG_ENTRIES' => $this->activityLogEntries,
        ];
    }
}
