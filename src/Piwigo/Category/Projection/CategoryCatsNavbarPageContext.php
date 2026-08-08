<?php

declare(strict_types=1);

namespace Piwigo\Category\Projection;

use Override;
use Piwigo\Core\TemplatePageContext;

/**
 * The 'cats_navbar' template variable assigned by
 * {@see \Piwigo\Category\CategoryCatsRenderer::render()}, after
 * {@see CategoryCatsPageContext} has already been assigned and the
 * 'index_category_thumbnails' template already parsed.
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
