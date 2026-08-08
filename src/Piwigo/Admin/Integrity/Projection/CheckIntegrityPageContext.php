<?php

declare(strict_types=1);

namespace Piwigo\Admin\Integrity\Projection;

use Override;
use Piwigo\Core\TemplatePageContext;

/**
 * The template variable set assigned by
 * {@see \Piwigo\Admin\Integrity\CheckIntegrity}'s own 'check_integrity'
 * render pass.
 */
final readonly class CheckIntegrityPageContext implements TemplatePageContext
{
    public function __construct(
        public bool $showSubmitAutomaticCorrection,
        public bool $showSubmitIgnore,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        return [
            'c13y_show_submit_automatic_correction' => $this->showSubmitAutomaticCorrection,
            'c13y_show_submit_ignore' => $this->showSubmitIgnore,
        ];
    }
}
