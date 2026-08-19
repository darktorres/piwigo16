<?php

declare(strict_types=1);

namespace Piwigo\Controller\Projection;

use Override;
use Piwigo\Core\TemplatePageContext;

/**
 * `U_CANONICAL` alone -- `header.latte`'s own `<link rel="canonical">`
 * tag (`isset($U_CANONICAL)`) renders while `PageHeaderRenderer::render()`
 * parses `header.latte`, which happens before a page's own `View`
 * (`GalleryController`'s `IndexView`, `PictureController`'s
 * `PictureView`/`SlideshowView`) is ever constructed -- so this one field
 * stays on the ambient `assignContext()` mechanism instead, since a
 * `View`'s own render always happens too late for `header.latte` to see
 * it.
 */
final readonly class CanonicalUrlPageContext implements TemplatePageContext
{
    public function __construct(
        public string $uCanonical
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        return [
            'U_CANONICAL' => $this->uCanonical,
        ];
    }
}
