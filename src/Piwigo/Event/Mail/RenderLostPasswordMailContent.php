<?php

declare(strict_types=1);

namespace Piwigo\Event\Mail;

/**
 * Typed event for the legacy `render_lost_password_mail_content` filter.
 * No handler is registered for it anywhere today.
 */
final readonly class RenderLostPasswordMailContent
{
    public function __construct(
        public string $message,
    ) {}
}
