<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

use Override;
use Piwigo\Core\TemplatePageContext;

/**
 * The full template variable set assigned by
 * {@see \Piwigo\Admin\PhotosAddApplicationsPageRenderer::render()}.
 */
final readonly class PhotosAddApplicationsPageContext implements TemplatePageContext
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
