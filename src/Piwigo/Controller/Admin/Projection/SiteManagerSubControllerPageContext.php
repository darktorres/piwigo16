<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin\Projection;

use Override;
use Piwigo\Core\TemplatePageContext;

/**
 * The template variable set assigned by
 * {@see \Piwigo\Controller\Admin\SiteManagerSubController::handle()}.
 * `$sites` is always included (even empty) since `site_manager.latte`
 * reads it with `{if !empty($sites)}`, not `isset()`.
 */
final readonly class SiteManagerSubControllerPageContext implements TemplatePageContext
{
    /**
     * @param list<SiteRow> $sites
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
            'CSRF_TOKEN' => $this->pwgToken,
            'ADMIN_PAGE_TITLE' => $this->adminPageTitle,
            'sites' => array_map(static fn (SiteRow $site): array => $site->toArray(), $this->sites),
        ];
    }
}
