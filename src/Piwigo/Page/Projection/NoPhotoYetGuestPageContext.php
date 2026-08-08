<?php

declare(strict_types=1);

namespace Piwigo\Page\Projection;

use Override;
use Piwigo\Core\TemplatePageContext;

/**
 * The template variable set assigned by
 * {@see \Piwigo\Page\NoPhotoYetRenderer::render()}'s own non-admin
 * (guest) branch.
 */
final readonly class NoPhotoYetGuestPageContext implements TemplatePageContext
{
    public function __construct(
        public int $step,
        public string $loginUrl,
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
            'U_LOGIN' => $this->loginUrl,
            'deactivate_url' => $this->deactivateUrl,
        ];
    }
}
