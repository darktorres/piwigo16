<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

use Override;
use Piwigo\Core\TemplatePageContext;

/**
 * The template variable set assigned by
 * {@see \Piwigo\Admin\LanguagesNewPageRenderer::render()}.
 */
final readonly class LanguagesNewPageContext implements TemplatePageContext
{
    public function __construct(
        public string $adminPageTitle,
        public bool $isWebmaster,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        return [
            'ADMIN_PAGE_TITLE' => $this->adminPageTitle,
            'isWebmaster' => $this->isWebmaster ? 1 : 0,
        ];
    }
}
