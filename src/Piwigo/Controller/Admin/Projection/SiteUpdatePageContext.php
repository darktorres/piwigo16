<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin\Projection;

use Override;
use Piwigo\Core\TemplatePageContext;

/**
 * The template variable set assigned by
 * {@see \Piwigo\Controller\Admin\SiteUpdateSubController::handle()}'s own
 * "template initialization" block. `$footerElements` is genuinely
 * optional -- accumulated across this method's own several independently
 * -gated sync stages (dirs/files/metadata), each of which may or may not
 * run in a given request; omitted here (not present as an empty-array
 * value) to match `footer.latte`'s own `{if isset($footer_elements)}`
 * guard exactly, same as the original code's own "never appended, stays
 * unset" behavior when zero stages ran.
 */
final readonly class SiteUpdatePageContext implements TemplatePageContext
{
    /**
     * @param list<string>|null $footerElements
     */
    public function __construct(
        public string $siteUrl,
        public string $siteManagerUrl,
        public string $resultUpdateLabel,
        public string $resultMetadataLabel,
        public string $metadataList,
        public string $helpUrl,
        public string $adminPageTitle,
        public string $pwgToken,
        public ?array $footerElements,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        $result = [
            'SITE_URL' => $this->siteUrl,
            'U_SITE_MANAGER' => $this->siteManagerUrl,
            'L_RESULT_UPDATE' => $this->resultUpdateLabel,
            'L_RESULT_METADATA' => $this->resultMetadataLabel,
            'METADATA_LIST' => $this->metadataList,
            'U_HELP' => $this->helpUrl,
            'ADMIN_PAGE_TITLE' => $this->adminPageTitle,
            'CSRF_TOKEN' => $this->pwgToken,
        ];

        if ($this->footerElements !== null) {
            $result['footer_elements'] = $this->footerElements;
        }

        return $result;
    }
}
