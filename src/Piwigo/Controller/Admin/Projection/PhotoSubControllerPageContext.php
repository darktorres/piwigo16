<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin\Projection;

use Override;
use Piwigo\Core\TemplatePageContext;

/**
 * The template variable set assigned by
 * {@see \Piwigo\Controller\Admin\PhotoSubController::handle()}.
 */
final readonly class PhotoSubControllerPageContext implements TemplatePageContext
{
    public function __construct(
        public string $adminPageTitle,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        return [
            'ADMIN_PAGE_TITLE' => $this->adminPageTitle,
        ];
    }
}
