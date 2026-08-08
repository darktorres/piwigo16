<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

use Override;
use Piwigo\Core\TemplatePageContext;

/**
 * The template variable set assigned by
 * {@see \Piwigo\Admin\CatListPageRenderer::render()} at template init.
 */
final readonly class CatListHeaderPageContext implements TemplatePageContext
{
    /**
     * @param array<string, string> $sortOrders
     */
    public function __construct(
        public string $adminPageTitle,
        public string $categoriesNav,
        public string $formAction,
        public string $pwgToken,
        public array $sortOrders,
        public ?string $sortOrderChecked,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        return [
            'ADMIN_PAGE_TITLE' => $this->adminPageTitle,
            'CATEGORIES_NAV' => $this->categoriesNav,
            'F_ACTION' => $this->formAction,
            'PWG_TOKEN' => $this->pwgToken,
            'sort_orders' => $this->sortOrders,
            'sort_order_checked' => $this->sortOrderChecked,
        ];
    }
}
