<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

use Override;
use Piwigo\Core\TemplatePageContext;

/**
 * The template variable set assigned by
 * {@see \Piwigo\Admin\PhotosAddFtpPageRenderer::render()}.
 */
final readonly class PhotosAddFtpPageContext implements TemplatePageContext
{
    public function __construct(
        public string $ftpHelpContent,
        public string $adminPageTitle,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        return [
            'FTP_HELP_CONTENT' => $this->ftpHelpContent,
            'ADMIN_PAGE_TITLE' => $this->adminPageTitle,
        ];
    }
}
