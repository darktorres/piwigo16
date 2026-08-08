<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

use Override;
use Piwigo\Core\TemplatePageContext;

/**
 * The template variable set assigned by
 * {@see \Piwigo\Admin\MenubarPageRenderer::render()}.
 */
final readonly class MenubarPageContext implements TemplatePageContext
{
    public function __construct(
        public string $formAction,
        public bool $isWebmaster,
        public string $adminPageTitle,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        return [
            'F_ACTION' => $this->formAction,
            'isWebmaster' => $this->isWebmaster ? 1 : 0,
            'ADMIN_PAGE_TITLE' => $this->adminPageTitle,
        ];
    }
}
