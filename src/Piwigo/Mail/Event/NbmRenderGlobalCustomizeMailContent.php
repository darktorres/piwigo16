<?php

declare(strict_types=1);

namespace Piwigo\Mail\Event;

/**
 * Typed event for the legacy `nbm_render_global_customize_mail_content`
 * filter. Registered (`NotificationByMailSubController::
 * renderGlobalCustomizeMailContent()`, wired from that same class's own
 * `handle()`) -- mutable on `$customizeMailContent`.
 */
final class NbmRenderGlobalCustomizeMailContent
{
    public function __construct(
        public string $customizeMailContent,
    ) {}
}
