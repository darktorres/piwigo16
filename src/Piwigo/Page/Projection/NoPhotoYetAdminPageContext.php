<?php

declare(strict_types=1);

namespace Piwigo\Page\Projection;

use Override;
use Piwigo\Core\TemplatePageContext;

/**
 * The template variable set assigned by
 * {@see \Piwigo\Page\NoPhotoYetRenderer::render()}'s own admin branch.
 */
final readonly class NoPhotoYetAdminPageContext implements TemplatePageContext
{
    public function __construct(
        public int $step,
        public string $intro,
        public string $nextStepUrl,
        public string $deactivateUrl,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        return [
            'step' => $this->step,
            'intro' => $this->intro,
            'next_step_url' => $this->nextStepUrl,
            'deactivate_url' => $this->deactivateUrl,
        ];
    }
}
