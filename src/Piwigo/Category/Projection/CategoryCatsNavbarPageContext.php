<?php

declare(strict_types=1);

namespace Piwigo\Category\Projection;

use Override;
use Piwigo\Core\TemplatePageContext;

/**
 * The 'cats_navbar' template variable assigned by
 * {@see \Piwigo\Category\CategoryCatsRenderer::render()}, after its own
 * {@see \Piwigo\Category\Projection\CategoryCatsResult} has already been
 * returned and rendered by the caller. Stays a plain `assignContext()`
 * call, unlike the `mainpage_categories.latte` render itself -- no
 * `Renderer`/`View` dependency to route around here.
 */
final readonly class CategoryCatsNavbarPageContext implements TemplatePageContext
{
    /**
     * @param array{CURRENT_PAGE?: float, URL_FIRST?: string, URL_PREV?: string, URL_NEXT?: string, URL_LAST?: string, pages?: array<int, string>, NB_PAGE?: int} $catsNavbar
     */
    public function __construct(
        public array $catsNavbar,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        return [
            'cats_navbar' => $this->catsNavbar,
        ];
    }
}
