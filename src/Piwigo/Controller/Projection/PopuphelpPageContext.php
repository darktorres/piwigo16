<?php

declare(strict_types=1);

namespace Piwigo\Controller\Projection;

use Override;
use Piwigo\Core\TemplatePageContext;

/**
 * The template variable set assigned by
 * {@see \Piwigo\Controller\PopuphelpController}.
 */
final readonly class PopuphelpPageContext implements TemplatePageContext
{
    public function __construct(
        public string $helpContent,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        return [
            'HELP_CONTENT' => $this->helpContent,
        ];
    }
}
