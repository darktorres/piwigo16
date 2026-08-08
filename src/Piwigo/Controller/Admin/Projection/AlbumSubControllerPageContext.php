<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin\Projection;

use Override;
use Piwigo\Core\TemplatePageContext;

/**
 * The template variable set assigned by
 * {@see \Piwigo\Controller\Admin\AlbumSubController::handle()} (shared
 * across all 4 of its own tab bodies).
 */
final readonly class AlbumSubControllerPageContext implements TemplatePageContext
{
    public function __construct(
        public string $adminPageTitle,
        public string $adminPageObjectId,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        return [
            'ADMIN_PAGE_TITLE' => $this->adminPageTitle,
            'ADMIN_PAGE_OBJECT_ID' => $this->adminPageObjectId,
        ];
    }
}
