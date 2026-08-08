<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin\Projection;

use Override;
use Piwigo\Core\TemplatePageContext;

/**
 * The template variable set assigned by
 * {@see \Piwigo\Controller\Admin\SiteManagerSubController::handle()}.
 */
final readonly class SiteManagerSubControllerPageContext implements TemplatePageContext
{
    public function __construct(
        public string $formAction,
        public string $pwgToken,
        public string $adminPageTitle,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        return [
            'F_ACTION' => $this->formAction,
            'PWG_TOKEN' => $this->pwgToken,
            'ADMIN_PAGE_TITLE' => $this->adminPageTitle,
        ];
    }
}
