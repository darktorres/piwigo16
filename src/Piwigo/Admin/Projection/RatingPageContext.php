<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

use Override;
use Piwigo\Core\Projection\Navbar;
use Piwigo\Core\TemplatePageContext;

/**
 * The template variable set assigned by
 * {@see \Piwigo\Admin\RatingPageRenderer::render()}. `$orderByOptions`
 * and `$images` are both always included -- `rating.latte` reads
 * `order_by_options` via an unguarded `{html_options}` and `images` via
 * an unguarded `{foreach}`, both matching the original code's own
 * unconditional loops.
 */
final readonly class RatingPageContext implements TemplatePageContext
{
    /**
     * @param list<mixed> $category
     * @param array<array-key, string> $cacheKeys
     * @param list<int> $orderByOptionsSelected
     * @param array<string, string> $userOptions
     * @param list<mixed> $userOptionsSelected
     * @param list<string> $orderByOptions
     * @param list<array<string, mixed>> $images
     */
    public function __construct(
        public Navbar $navbar,
        public string $fAction,
        public int $display,
        public int $nbElements,
        public array $category,
        public array $cacheKeys,
        public array $orderByOptionsSelected,
        public array $userOptions,
        public array $userOptionsSelected,
        public string $adminPageTitle,
        public array $orderByOptions,
        public array $images,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        return [
            'navbar' => $this->navbar->toArray(),
            'F_ACTION' => $this->fAction,
            'DISPLAY' => $this->display,
            'NB_ELEMENTS' => $this->nbElements,
            'category' => $this->category,
            'CACHE_KEYS' => $this->cacheKeys,
            'order_by_options_selected' => $this->orderByOptionsSelected,
            'user_options' => $this->userOptions,
            'user_options_selected' => $this->userOptionsSelected,
            'ADMIN_PAGE_TITLE' => $this->adminPageTitle,
            'order_by_options' => $this->orderByOptions,
            'images' => $this->images,
        ];
    }
}
