<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin\Projection;

use Override;
use Piwigo\Core\TemplatePageContext;

/**
 * The template variable set assigned by
 * {@see \Piwigo\Controller\Admin\SiteUpdateSubController::handle()}'s own
 * "template initialization" block.
 */
final readonly class SiteUpdatePageContext implements TemplatePageContext
{
    public function __construct(
        public string $siteUrl,
        public string $siteManagerUrl,
        public string $resultUpdateLabel,
        public string $resultMetadataLabel,
        public string $metadataList,
        public string $helpUrl,
        public string $adminPageTitle,
        public string $pwgToken,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        return [
            'SITE_URL' => $this->siteUrl,
            'U_SITE_MANAGER' => $this->siteManagerUrl,
            'L_RESULT_UPDATE' => $this->resultUpdateLabel,
            'L_RESULT_METADATA' => $this->resultMetadataLabel,
            'METADATA_LIST' => $this->metadataList,
            'U_HELP' => $this->helpUrl,
            'ADMIN_PAGE_TITLE' => $this->adminPageTitle,
            'PWG_TOKEN' => $this->pwgToken,
        ];
    }
}
