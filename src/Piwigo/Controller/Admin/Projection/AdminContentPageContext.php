<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin\Projection;

use Latte\Runtime\Html;
use Override;
use Piwigo\Core\TemplatePageContext;

/**
 * The three fields every admin sub-controller assigns ambiently, shared
 * across page-family conversions the same way {@see
 * \Piwigo\Controller\Projection\CanonicalUrlPageContext} is on the
 * front end. `admin.latte`'s own shell renders `ADMIN_PAGE_TITLE`/
 * `U_HELP` in its own body (`<h1>`/help link), separately from and
 * after a page's own `Renderer::render()` call, so neither field can
 * live on a page-specific `View` -- and `ADMIN_CONTENT` itself is the
 * rendered `Html` a page's own View produces, handed back to this same
 * ambient mechanism for the shell to place.
 */
final readonly class AdminContentPageContext implements TemplatePageContext
{
    public function __construct(
        public Html $adminContent,
        public string $adminPageTitle,
        public ?string $helpUrl = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        $result = [
            'ADMIN_CONTENT' => $this->adminContent,
            'ADMIN_PAGE_TITLE' => $this->adminPageTitle,
        ];

        if ($this->helpUrl !== null) {
            $result['U_HELP'] = $this->helpUrl;
        }

        return $result;
    }
}
