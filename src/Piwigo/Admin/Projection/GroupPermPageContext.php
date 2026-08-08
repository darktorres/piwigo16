<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

use Override;
use Piwigo\Core\TemplatePageContext;

/**
 * The template variable set assigned by
 * {@see \Piwigo\Admin\GroupPermPageRenderer::render()}.
 */
final readonly class GroupPermPageContext implements TemplatePageContext
{
    public function __construct(
        public string $title,
        public string $catOptionsTrueLabel,
        public string $catOptionsFalseLabel,
        public string $formAction,
        public string $pwgToken,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        return [
            'TITLE' => $this->title,
            'L_CAT_OPTIONS_TRUE' => $this->catOptionsTrueLabel,
            'L_CAT_OPTIONS_FALSE' => $this->catOptionsFalseLabel,
            'F_ACTION' => $this->formAction,
            'PWG_TOKEN' => $this->pwgToken,
        ];
    }
}
