<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

use Override;
use Piwigo\Core\TemplatePageContext;

/**
 * The template variable set assigned by
 * {@see \Piwigo\Admin\RatingPageRenderer::render()}. `images` is seeded
 * empty here -- the renderer's own rating-report loop populates it
 * afterward via `Template::append('images', ...)`, a separate call this
 * context intentionally doesn't own.
 */
final readonly class RatingPageContext implements TemplatePageContext
{
    /**
     * @param array{CURRENT_PAGE?: float, URL_FIRST?: string, URL_PREV?: string, URL_NEXT?: string, URL_LAST?: string, pages?: array<int, string>, NB_PAGE?: int} $navbar
     * @param list<mixed> $category
     * @param array<array-key, string> $cacheKeys
     * @param list<int> $orderByOptionsSelected
     * @param array<string, string> $userOptions
     * @param list<mixed> $userOptionsSelected
     */
    public function __construct(
        public array $navbar,
        public string $fAction,
        public int $display,
        public int $nbElements,
        public array $category,
        public array $cacheKeys,
        public array $orderByOptionsSelected,
        public array $userOptions,
        public array $userOptionsSelected,
        public string $adminPageTitle,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        return [
            'navbar' => $this->navbar,
            'F_ACTION' => $this->fAction,
            'DISPLAY' => $this->display,
            'NB_ELEMENTS' => $this->nbElements,
            'category' => $this->category,
            'CACHE_KEYS' => $this->cacheKeys,
            'order_by_options_selected' => $this->orderByOptionsSelected,
            'user_options' => $this->userOptions,
            'user_options_selected' => $this->userOptionsSelected,
            'ADMIN_PAGE_TITLE' => $this->adminPageTitle,
            'images' => [],
        ];
    }
}
