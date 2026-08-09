<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin\Projection;

use Override;
use Piwigo\Core\TemplatePageContext;

/**
 * The template variable set assigned by
 * {@see \Piwigo\Controller\Admin\SiteManagerSubController::handle()}.
 * `$sites` is always included (even empty) since `site_manager.tpl`
 * reads it with `{if not empty($sites)}`, not `isset()`.
 */
final readonly class SiteManagerSubControllerPageContext implements TemplatePageContext
{
    /**
     * @param list<array<string, mixed>> $sites
     */
    public function __construct(
        public string $formAction,
        public string $pwgToken,
        public string $adminPageTitle,
        public array $sites,
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
            'sites' => $this->sites,
        ];
    }
}
