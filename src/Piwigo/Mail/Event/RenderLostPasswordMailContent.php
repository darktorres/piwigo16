<?php

declare(strict_types=1);

namespace Piwigo\Mail\Event;

/**
 * Typed event for the legacy `render_lost_password_mail_content` filter.
 * No handler is registered for it anywhere today. No context -- every
 * real call site passes only the message.
 */
final class RenderLostPasswordMailContent
{
    public function __construct(
        public string $message,
    ) {}
}
