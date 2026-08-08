<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

use Override;
use Piwigo\Core\TemplatePageContext;

/**
 * The template variable set assigned by
 * {@see \Piwigo\Admin\CatOptionsPageRenderer::render()}.
 */
final readonly class CatOptionsPageContext implements TemplatePageContext
{
    public function __construct(
        public string $helpUrl,
        public string $formAction,
        public string $section,
        public string $catOptionsTrueLabel,
        public string $catOptionsFalseLabel,
        public string $pwgToken,
        public string $adminPageTitle,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        return [
            'U_HELP' => $this->helpUrl,
            'F_ACTION' => $this->formAction,
            'L_SECTION' => $this->section,
            'L_CAT_OPTIONS_TRUE' => $this->catOptionsTrueLabel,
            'L_CAT_OPTIONS_FALSE' => $this->catOptionsFalseLabel,
            'PWG_TOKEN' => $this->pwgToken,
            'ADMIN_PAGE_TITLE' => $this->adminPageTitle,
        ];
    }
}
