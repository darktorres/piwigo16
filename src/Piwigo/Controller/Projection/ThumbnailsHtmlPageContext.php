<?php

declare(strict_types=1);

namespace Piwigo\Controller\Projection;

use Latte\Runtime\Html;
use Override;
use Piwigo\Core\TemplatePageContext;

/**
 * `THUMBNAILS` alone -- {@see \Piwigo\Controller\GalleryController::__invoke()}
 * renders {@see ThumbnailsView} via `Renderer::render()` (a page-scoped
 * sub-fragment, not `IndexView`'s own property, matching the ambient shape
 * `CanonicalUrlPageContext` already established), then writes the result
 * into `Template::$vars` this way -- `assignContext()` is the sole way any
 * L1/L2a/L2b/L3 caller writes into the current request's template, so a
 * bare `Html` value still needs a one-field wrapper even though it's
 * already fully rendered.
 */
final readonly class ThumbnailsHtmlPageContext implements TemplatePageContext
{
    public function __construct(
        public Html $thumbnails,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        return [
            'THUMBNAILS' => $this->thumbnails,
        ];
    }
}
