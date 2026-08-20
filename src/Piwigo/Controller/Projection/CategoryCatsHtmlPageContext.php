<?php

declare(strict_types=1);

namespace Piwigo\Controller\Projection;

use Latte\Runtime\Html;
use Override;
use Piwigo\Core\TemplatePageContext;

/**
 * `CATEGORIES` alone -- {@see \Piwigo\Controller\GalleryController::__invoke()}
 * renders {@see CategoryCatsView} via `Renderer::render()` (a page-scoped
 * sub-fragment, not `IndexView`'s own property), then writes the result
 * into `Template::$vars` this way, same shape as {@see
 * ThumbnailsHtmlPageContext}.
 */
final readonly class CategoryCatsHtmlPageContext implements TemplatePageContext
{
    public function __construct(
        public Html $categories,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        return [
            'CATEGORIES' => $this->categories,
        ];
    }
}
