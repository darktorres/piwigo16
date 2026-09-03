<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin\Projection;

use Latte\Runtime\Html;
use Override;
use Piwigo\Core\TemplatePageContext;

/**
 * The template variable set assigned by
 * {@see \Piwigo\Controller\Admin\AlbumSubController::handle()} (shared
 * across all 4 of its own tab bodies).
 *
 * `$adminPageTitle` accepts `string|Html` (P59 correction) -- see
 * {@see AdminPageResult::$pageTitle}'s own docblock, the field this one
 * mirrors. The one real caller here always passes `Html` (a hand-built
 * `<strong>` fragment), never a plain string, but the union stays for
 * consistency with every other `ADMIN_PAGE_TITLE` contributor.
 */
final readonly class AlbumSubControllerPageContext implements TemplatePageContext
{
    public function __construct(
        public string|Html $adminPageTitle,
        public string $adminPageObjectId,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        return [
            'ADMIN_PAGE_TITLE' => $this->adminPageTitle,
            'ADMIN_PAGE_OBJECT_ID' => $this->adminPageObjectId,
        ];
    }
}
