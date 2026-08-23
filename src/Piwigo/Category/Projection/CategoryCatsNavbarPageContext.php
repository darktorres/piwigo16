<?php

declare(strict_types=1);

namespace Piwigo\Category\Projection;

use Override;
use Piwigo\Core\Projection\Navbar;
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
    public function __construct(
        public Navbar $catsNavbar,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        return [
            'cats_navbar' => $this->catsNavbar->toArray(),
        ];
    }
}
