<?php

declare(strict_types=1);

namespace Piwigo\Event\Mail;

/**
 * Typed event for legacy `nbm_render_user_customize_mail_content` (dispatch).
 *
 * Dispatched from: src/Piwigo/Controller/Admin/MiscController.php
 */
final readonly class NbmRenderUserCustomizeMailContent
{
    /**
     * @param array<string, mixed> $nbmUser
     */
    public function __construct(
        public string $customizeMailContent,
        public array $nbmUser,
    ) {
    }
}
