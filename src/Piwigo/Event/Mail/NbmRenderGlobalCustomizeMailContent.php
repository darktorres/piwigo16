<?php

declare(strict_types=1);

namespace Piwigo\Event\Mail;

/**
 * Typed event for legacy `nbm_render_global_customize_mail_content` (dispatch).
 *
 * Dispatched from: src/Piwigo/Controller/Admin/MiscController.php
 */
final readonly class NbmRenderGlobalCustomizeMailContent
{
    public function __construct(
        public string $customizeMailContent,
    ) {
    }

    public function withCustomizeMailContent(string $customizeMailContent): self
    {
        return new self($customizeMailContent);
    }
}
