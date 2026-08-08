<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

use Override;
use Piwigo\Core\TemplatePageContext;

/**
 * The template variable set assigned by
 * {@see \Piwigo\Admin\HelpPageRenderer::render()}.
 */
final readonly class HelpPageContext implements TemplatePageContext
{
    public function __construct(
        public string $helpContent,
        public string $helpSectionTitle,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        return [
            'HELP_CONTENT' => $this->helpContent,
            'HELP_SECTION_TITLE' => $this->helpSectionTitle,
        ];
    }
}
