<?php

declare(strict_types=1);

namespace Piwigo\Controller\Projection;

use Override;
use Piwigo\Core\TemplatePageContext;

/**
 * `U_CANONICAL` alone -- `header.latte`'s own `<link rel="canonical">`
 * tag (`isset($U_CANONICAL)`) renders while `PageHeaderRenderer::render()`
 * parses `header.latte`, which happens before {@see
 * \Piwigo\Controller\GalleryController::__invoke()} constructs its own
 * `IndexView` -- so this one field stays on the old ambient
 * `assignContext()` mechanism instead of moving into `IndexView`, whose
 * render happens too late for `header.latte` to see it.
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
