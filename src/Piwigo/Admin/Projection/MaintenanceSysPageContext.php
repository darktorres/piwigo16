<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

use Override;
use Piwigo\Core\TemplatePageContext;

/**
 * The full 'maintenance_sys.tpl' template variable set assigned by
 * {@see \Piwigo\Admin\MaintenanceSysPageRenderer::render()}.
 */
final readonly class MaintenanceSysPageContext implements TemplatePageContext
{
    public function __construct(
        public bool $isWebmaster,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        return [
            'isWebmaster' => $this->isWebmaster ? 1 : 0,
        ];
    }
}
